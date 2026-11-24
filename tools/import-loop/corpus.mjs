// Frozen-capture replay. Each worker owns its capture, WordPress and server.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { parseArgs } from 'node:util';
import { captureRoutes } from './checks.mjs';
import { compareInvocation, visualCells } from './visual.mjs';
import { classCounts, visualScoreRegressed } from './progress.mjs';
import { mergeRuns, routeCss, siteStructure } from './corpus-metrics.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const json = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const save = (file, value) => fs.writeFileSync(file, JSON.stringify(value, null, 2) + '\n');
export function compareCells(before, after) {
  const regressions = [], improvements = [], unmeasured = [];
  const key = c => `${c.route}@${c.width}`;
  const current = new Map(after.map(c => [key(c), c]));
  for (const was of before) {
    const now = current.get(key(was));
    if (!was.verified || !now?.verified) {
      unmeasured.push(key(was));
      if (Boolean(was.verified) !== Boolean(now?.verified)) regressions.push(key(was));
      continue;
    }
    let worse = was.pass && !now.pass, better = !was.pass && now.pass;
    for (const score of was.scores) {
      const next = now.scores.find(s => s.state === score.state && s.profile === score.profile);
      if (!next || visualScoreRegressed(score, next)) worse = true;
      if (next && visualScoreRegressed(next, score)) better = true;
    }
    if (worse) regressions.push(key(was));
    if (better) improvements.push(key(was));
  }
  for (const cell of after) if (!cell.verified && !unmeasured.includes(key(cell))) unmeasured.push(key(cell));
  return { regressions, improvements, unmeasured };
}
function run(command, args, env = process.env) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { env, stdio: ['ignore', 'pipe', 'pipe'], timeout: 30 * 60_000 });
    let stdout = '', stderr = '';
    child.stdout.on('data', b => { stdout += b; });
    child.stderr.on('data', b => { stderr += b; });
    child.on('error', reject);
    child.on('close', status => resolve({ status, stdout, stderr }));
  });
}
async function checked(command, args, env) {
  const result = await run(command, args, env);
  if (result.status !== 0) throw new Error(`${command}: ${result.stderr || result.stdout}`);
  return result.stdout.trim();
}
function removeTrees(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (!e.isDirectory()) continue;
    const full = path.join(dir, e.name);
    if (e.name === 'box-tree') fs.rmSync(full, { recursive: true });
    else removeTrees(full);
  }
}
async function main() {
  const { values: args, positionals } = parseArgs({ allowPositionals: true, options: Object.fromEntries(['corpus', 'blocks-engine', 'importer-source', 'sites', 'jobs', 'out', 'variant', 'ssi-zip', 'baseline', 'repeat'].map(k => [k, { type: 'string' }])) });
  const mode = positionals[0];
  // measure: cells and structure only, with no expected result to record or compare.
  if (!['record', 'replay', 'measure'].includes(mode) || !args.corpus || !args['blocks-engine']) throw new Error('usage: corpus.mjs record|replay|measure --corpus <dir> --blocks-engine <dir> [--importer-source <dir>] [--sites a,b] [--jobs N] [--out <dir>] [--ssi-zip <zip>] [--baseline <dir>] [--repeat N] [--variant no-box-tree]');
  // --repeat N replays each site N times; a cell passes only when every replay passes it (mergeRuns).
  const repeat = Number(args.repeat || 1);
  if (!Number.isInteger(repeat) || repeat < 1) throw new Error('repeat must be a positive integer');
  if (args.variant && (args.variant !== 'no-box-tree' || mode === 'record')) throw new Error('variant is replay-only: no-box-tree');
  // A replay compares each run with the baseline: corpus-check.mjs repeats regressed sites itself.
  if (repeat > 1 && mode === 'replay') throw new Error('repeat is for record and measure');
  const corpus = path.resolve(args.corpus), be = path.resolve(args['blocks-engine']);
  // Expected results live with each capture, or under --baseline as <site>.json:
  // one directory per measured transformer and release, so captures stay shared.
  const expectedPath = site => args.baseline ? path.join(path.resolve(args.baseline), `${site}.json`) : path.join(corpus, site, 'expected.json');
  if (args.baseline) fs.mkdirSync(path.resolve(args.baseline), { recursive: true });
  const transformer = fs.existsSync(path.join(be, 'php-transformer.php')) ? be : path.join(be, 'php-transformer');
  const known = json(path.join(here, 'sites.json'));
  const sites = args.sites ? args.sites.split(',') : fs.readdirSync(corpus).filter(s => known[s]);
  if (!sites.length || sites.some(s => !known[s] || s === '_comment')) throw new Error('unknown or empty site selection');
  const jobs = Number(args.jobs || Math.max(1, Math.floor(os.availableParallelism() / 2)));
  if (!Number.isInteger(jobs) || jobs < 1) throw new Error('jobs must be a positive integer');
  const out = args.out ? path.resolve(args.out) : fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-corpus-'));
  fs.mkdirSync(out, { recursive: true });
  const work = fs.mkdtempSync(path.join(out, 'run-'));
  // The release zip: --ssi-zip or SSI_ZIP when given (no gh auth needed),
  // otherwise downloaded as import.sh does. Its tag is read from the zip.
  const zip = path.join(work, 'static-site-importer.zip');
  const given = args['ssi-zip'] || process.env.SSI_ZIP || '';
  const release = await checked('bash', [path.join(here, 'ssi-release.sh'), zip], { ...process.env, SSI_ZIP: given ? path.resolve(given) : '' });
  // BARE_WP reuses an existing bare-wp.sh install; otherwise one is made per run.
  const bare = process.env.BARE_WP ? path.resolve(process.env.BARE_WP) : path.join(work, 'bare-wp');
  await checked('bash', [path.join(here, 'gates/bare-wp.sh'), bare]);
  // A plain php-transformer directory (not a git checkout) is named by its VERSION.
  // Only the transformer's own checkout names it, not an enclosing repository.
  const head = await run('git', ['-C', transformer, 'rev-parse', '--show-prefix', 'HEAD']);
  const [prefix, commit] = head.status === 0 ? head.stdout.split('\n') : [];
  const sha = ['', 'php-transformer/'].includes(prefix) && commit ? commit : `VERSION ${fs.readFileSync(path.join(transformer, 'VERSION'), 'utf8').trim()}`;
  let index = 0;
  const results = new Array(sites.length);
  async function replaySite(site, replay) {
    const start = Date.now(), dir = path.join(work, replay ? `${site}.${replay}` : site), wp = path.join(dir, 'site');
    const result = { site, cells: [], regressions: [], improvements: [], unmeasured: [], metrics: null };
    fs.mkdirSync(dir);
    try {
      const capture = path.join(dir, 'capture');
      fs.cpSync(path.join(corpus, site, 'capture'), capture, { recursive: true, dereference: true });
      // no-box-tree withholds box-tree evidence from the import only: the
      // compare still proves the frozen reference from the full capture.
      let input = capture;
      if (args.variant) {
        input = path.join(dir, 'import-capture');
        fs.cpSync(capture, input, { recursive: true });
        removeTrees(input);
      }
      const client = json(path.join(capture, '.capture-client.json')).package;
      const env = { ...process.env, BARE_WP: bare, SSI_ZIP: zip, SSI_SOURCE: args['importer-source'] ? path.resolve(args['importer-source']) : '' };
      const imported = await run('bash', [path.join(here, 'gates/reimport.sh'), transformer, input, wp], env);
      fs.writeFileSync(path.join(dir, 'reimport.log'), imported.stdout + imported.stderr);
      if (imported.status !== 0) throw new Error(`import failed (${path.join(dir, 'reimport.log')}): ${imported.stderr.trim().slice(-500)}`);
      const url = imported.stdout.trim().split('\n').at(-1);
      // Structure and weight of the import; a failure to measure them never hides the cells.
      try {
        const { stylesheet, ...structure } = await siteStructure(path.join(bare, 'wp-cli.phar'), wp);
        result.metrics = { ...structure, stylesheet, css: await routeCss(url, captureRoutes(capture)) };
      } catch (error) {
        result.metrics_error = `metrics: ${error.message}`;
      }
      const { command, reportPath } = compareInvocation(capture, url, client);
      fs.rmSync(reportPath, { force: true });
      const compared = await run('npx', command);
      fs.writeFileSync(path.join(dir, 'compare.log'), compared.stdout + compared.stderr);
      if (![0, 1].includes(compared.status)) throw new Error(`compare failed (${path.join(dir, 'compare.log')}): ${(compared.stderr || compared.stdout).trim().slice(-500)}`);
      result.cells = visualCells(capture, captureRoutes(capture), json(reportPath), known[site].url).cells.map(c => ({ ...c, pass: c.verified && c.scores.every(s => s.pass), classes: Object.fromEntries(classCounts(c.scores.flatMap(s => s.failures || []))) }));
      if (!result.cells.some(c => c.verified)) throw new Error('no measured cells');
      // Frozen source readiness failures cannot be measured by any candidate.
      // Keep their original reasons in expected.json, rather than masking a
      // candidate/compare failure or manufacturing a passing result.
      const reference = json(path.join(capture, 'fidelity-reference.json'));
      const frozenUnready = result.cells.filter(c => !c.verified && !c.scores.length && c.pending.length > 0 && c.pending.every(p => String(p.reason).includes('Source evidence unready:')) && reference.entries?.some(e => e.route === c.route && e.viewport === c.width && e.readiness?.ready === false));
      result.frozen_unmeasured = frozenUnready.map(c => ({ route: c.route, width: c.width, evidence: reference.entries.filter(e => e.route === c.route && e.viewport === c.width) }));
      if (mode !== 'replay') {
        result.unmeasured = result.cells.filter(c => !c.verified).map(c => `${c.route}@${c.width}`);
        // A recording is saved once every replay measured all it can (measure below).
        if (mode === 'record') result.recordable = { client, complete: result.unmeasured.length === frozenUnready.length };
      } else {
        const expected = json(expectedPath(site));
        Object.assign(result, compareCells(expected.cells, result.cells));
        result.baseline_metrics = expected.metrics ?? null;
        result.frozen_unmeasured = result.frozen_unmeasured.filter(c => expected.frozen_unmeasured?.some(e => e.route === c.route && e.width === c.width));
      }
      result.unmeasured = result.unmeasured.filter(key => !result.frozen_unmeasured.some(c => key === `${c.route}@${c.width}`));
    } catch (error) {
      result.unmeasured.push(error.message);
    } finally {
      const pidFile = path.join(wp, '.server.pid');
      if (fs.existsSync(pidFile)) {
        const pid = Number(fs.readFileSync(pidFile, 'utf8').trim());
        if (Number.isInteger(pid) && pid > 1) try { process.kill(-pid, 'SIGTERM'); } catch (error) { if (error.code !== 'ESRCH') throw error; }
      }
      result.seconds = (Date.now() - start) / 1000;
      // A site's import and screenshots are gigabytes; only --out keeps them.
      if (!args.out) fs.rmSync(dir, { recursive: true, force: true });
    }
    return result;
  }
  async function measure(site) {
    const runs = [];
    for (let i = 0; i < repeat; i++) runs.push(await replaySite(site, repeat > 1 ? i + 1 : 0));
    const { recordable, ...merged } = mergeRuns(runs);
    if (mode === 'record' && runs.every(r => r.recordable?.complete)) save(expectedPath(site), { blocks_engine_sha: sha, ssi_release: release, client_package: recordable.client, frozen_unmeasured: merged.frozen_unmeasured, cells: merged.cells, metrics: merged.metrics });
    return merged;
  }
  await Promise.all(Array.from({ length: Math.min(jobs, sites.length) }, async () => {
    while (index < sites.length) { const i = index++; results[i] = await measure(sites[i]); }
  }));
  if (!args.out) fs.rmSync(out, { recursive: true, force: true });
  const regressed = results.some(s => s.regressions.length > 0);
  console.log(JSON.stringify({ blocks_engine_sha: sha, ssi_release: release, sites: results, regressed }));
  process.exitCode = regressed || results.some(s => s.unmeasured.length) ? 1 : 0;
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main().catch(error => {
  console.log(JSON.stringify({ sites: [], regressed: false, unmeasured: [error.message] }));
  process.exitCode = 1;
});
