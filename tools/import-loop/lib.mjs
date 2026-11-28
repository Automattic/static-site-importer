// Shared by the loop steps: arguments, GitHub, and predicate runs.

import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[index + 1]]] : pairs), []));

// A loop runs for days: a transient network failure retries with backoff
// instead of failing the pass. Any other gh error surfaces at once.
const TRANSIENT = /TLS handshake timeout|i\/o timeout|connection (reset|refused)|unexpected EOF|EOF$|HTTP 50[234]|Gateway Time-?out|timeout awaiting response|no such host/im;
export const gh = (...argv) => {
  for (let attempt = 1; ; attempt++) {
    try {
      return execFileSync('gh', argv, { encoding: 'utf8', maxBuffer: 64 << 20, stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (error) {
      if (attempt >= 5 || !TRANSIENT.test(String(error.stderr || error.message))) throw error;
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 2000 * 2 ** attempt);
    }
  }
};

// Open pull requests that name the issue, by URL, by #number, or by Cook's branch name.
// A pull request fixes an issue when GitHub links it to close the issue, or
// its branch was cut for it (`issue-<n>-`). A pull request that only mentions
// the issue (a loop fix citing it as evidence) is not its fix.
export const fixesIssue = (pr, number) => (pr.closingIssuesReferences || []).some((ref) => String(ref.number) === String(number)) || pr.headRefName.includes(`issue-${number}-`);
export function linkedPullRequests(issueUrl) {
  const [, owner, name, , number] = new URL(issueUrl).pathname.split('/');
  const github = `${owner}/${name}`;
  return JSON.parse(gh('pr', 'list', '-R', github, '--state', 'open', '--json', 'number,url,body,headRefName,headRefOid,isDraft,mergeable,statusCheckRollup,closingIssuesReferences', '-L', '100'))
    .filter((pr) => fixesIssue(pr, number))
    .map((pr) => ({ ...pr, github, mergeable: knownMergeable(github, pr) }));
}

// GitHub computes mergeability lazily: a list right after trunk moves reports
// UNKNOWN for a pull request that conflicts. Asking for it starts the
// computation; poll until it settles so a conflict reaches triage.
function knownMergeable(github, { number, mergeable }) {
  for (let attempt = 0; mergeable === 'UNKNOWN' && attempt < 10; attempt++) {
    execFileSync('sleep', ['3']);
    mergeable = JSON.parse(gh('pr', 'view', String(number), '-R', github, '--json', 'mergeable')).mergeable;
  }
  return mergeable;
}

// A pull request's CI and mergeability at its head, as triage tells a Cook.
// Merging waits on its own: `homeboy git pr merge-when-green`.
const FAILED = ['FAILURE', 'TIMED_OUT', 'CANCELLED', 'ERROR', 'ACTION_REQUIRED', 'STARTUP_FAILURE'];
const GREEN = ['SUCCESS', 'NEUTRAL', 'SKIPPED'];
export function prHealth({ statusCheckRollup: checks = [], mergeable }) {
  const name = (c) => c.name || c.context;
  return {
    failed: checks.filter((c) => FAILED.includes(c.conclusion || c.state)).map((c) => ({ name: name(c), url: c.detailsUrl || c.targetUrl })),
    pending: checks.filter((c) => ![...FAILED, ...GREEN].includes(c.conclusion || c.state)).map(name),
    no_checks: !checks.length,
    mergeable,
  };
}

// A predicate reads the finding cell it reproduces, and the imported site
// that renders transformer output, from the environment.
// A capture route's document: a served directory (`/about/`) is its
// `index.html`, a served file (`/about.html`) is itself.
// The capture keeps the source URL's case (`/shop/Suspension/`) and WordPress
// lowercases slugs, so a path segment missing as written matches its
// case-insensitive sibling. A directory route the capture saved as a file
// (`/shop/index-query-1/` from `shop/index-query-1.html`) reads that file.
const capturePath = (website, relative) => relative.split('/').filter(Boolean).reduce((at, segment) => {
  const exact = path.join(at, segment);
  if (fs.existsSync(exact) || !fs.existsSync(at)) return exact;
  return path.join(at, fs.readdirSync(at).find((name) => name.toLowerCase() === segment.toLowerCase()) ?? segment);
}, website);
export const routeDocument = (website, route = '/') => {
  if (route.endsWith('.html')) return capturePath(website, route);
  const index = capturePath(website, `${route}/index.html`);
  const file = route.replace(/\/+$/, '') && capturePath(website, `${route.replace(/\/+$/, '')}.html`);
  return fs.existsSync(index) || !file || !fs.existsSync(file) ? index : file;
};

// Homeboy posts one note per Cook that stopped without a pull request; Cooks
// lost to runner mechanics leave none. Those since the last guidance count
// against the finding. Guidance is the loop's own design notice, which opens a
// design round, or any comment other than the loop's notes, which re-arms the
// loop entirely. A design Cook whose note reports `Decision: accept` accepts
// the finding as it stands. A refuted pull request is reworked on its own
// branch, where Cook reports on the pull request instead: triage records each
// rework on the issue (REWORK_NOTE), and it counts as a failed Cook, since the
// head that Cook started from did not fix the finding.
//
// An issue is shared by every site whose import has the finding, but a design
// Cook decides from one site's capture. So an accept holds for that site only:
// the design notice records its site and workflow instance (designScope), and
// a Cook's note names its instance in its key. Every other site keeps cooking
// the finding against its own evidence. Design rounds count per site the same
// way: several sites reaching the cap at once each open their own round, and
// one site's rounds neither use up nor reset another's. Until a site opens its
// first design round, failed Cooks count from every site, since each is
// evidence against the finding. From then on failures another site's evidence
// produced (reworks naming another site, notes from another site's design
// instance) no longer count: a round is spent on this site's own outcomes, not
// on other sites' refutations landing meanwhile.
//
// A design round can also decide the defect is not in the owning repository:
// `Decision: reroute <repo>`. That holds for its site only too. Triage files
// the finding in that repository with a reroute scope naming the site, and
// that site's passes track the finding there from then on (ownIssue); every
// other site keeps the original issue.
// Gate tools: tools/ and lib/ in the repository layout, plus the packages the
// import-loop modules reach (through relative imports) and those packages'
// own dependencies, so a check that adds an import runs on the runner too.
export function stageGateTools(repo, out) {
  fs.rmSync(out, { recursive: true, force: true });
  for (const dir of ['tools', 'lib']) fs.cpSync(path.join(repo, dir), path.join(out, dir), { recursive: true, dereference: true, filter: (source) => path.basename(source) !== 'node_modules' });
  const loop = path.join(repo, 'tools', 'import-loop');
  const seen = new Set(), pending = new Set();
  const visit = (file) => {
    if (seen.has(file) || !fs.existsSync(file)) return;
    seen.add(file);
    for (const [, spec] of fs.readFileSync(file, 'utf8').matchAll(/(?:^\s*import\s+|\bfrom\s+|\bimport\(\s*)['"]([^'"]+)['"]/gm)) {
      if (spec.startsWith('.')) visit(path.resolve(path.dirname(file), spec));
      else if (!spec.startsWith('node:')) pending.add(spec.split('/').slice(0, spec.startsWith('@') ? 2 : 1).join('/'));
    }
  };
  for (const file of fs.readdirSync(loop, { recursive: true })) if (file.endsWith('.mjs')) visit(path.join(loop, file));
  const staged = new Set();
  for (const name of pending) {
    const source = path.join(repo, 'node_modules', name);
    if (staged.has(name) || !fs.existsSync(path.join(source, 'package.json'))) continue;
    staged.add(name);
    fs.cpSync(fs.realpathSync(source), path.join(out, 'node_modules', name), { recursive: true, dereference: true });
    for (const dependency of Object.keys(JSON.parse(fs.readFileSync(path.join(source, 'package.json'), 'utf8')).dependencies || {})) pending.add(dependency);
  }
  return [...staged].sort();
}

export const COOK_NOTE = '<!-- homeboy:key=agent-task-note:';
export const REROUTE_NOTICE = '<!-- import-loop:rerouted -->';
export const rerouteScope = (scope) => `<!-- import-loop:reroute-scope ${JSON.stringify(scope)} -->`;
const scopeOf = (body = '') => {
  const scope = body.match(/<!-- import-loop:reroute-scope (\{.*?\}) -->/)?.[1];
  return scope ? JSON.parse(scope) : null;
};
// The issue a site tracks a finding on: its own rerouted issue first, else the
// shared one; open before closed, so a finding that returns reopens its record.
export const ownIssue = (hits, signature, site, repoNames) => {
  const known = hits.filter((h) => repoNames.includes(h.repository.name) && (h.body || '').includes(`Loop signature: \`${signature}\``));
  for (const set of [known.filter((h) => scopeOf(h.body)?.site === site), known.filter((h) => !scopeOf(h.body))]) {
    const pick = set.find((h) => h.state === 'open') || set[0];
    if (pick) return pick;
  }
  return null;
};
export const REWORK_NOTE = '<!-- import-loop:rework -->';
export const CAP_NOTICE = '<!-- import-loop:cook-cap -->';
export const DESIGN_NOTICE = '<!-- import-loop:design -->';
export const NEAR_MISS = '<!-- import-loop:near-miss -->';
// A refuted head is a near miss when only collateral refuted it (no failing
// cell, CI job, conflict or corpus regression) and its own finding improved on
// the whole re-import: returns that own count, else null.
export const nearMiss = (refuted) => {
  if (!refuted?.collateral || refuted.cells?.length || refuted.ci || refuted.conflicting || refuted.corpus) return null;
  for (const line of String(refuted.collateral[0] || '').trim().split('\n').reverse()) {
    try { const v = JSON.parse(line); if (v && 'own' in v) return v.own && v.own.after < v.own.before ? v.own : null; } catch {}
  }
  return null;
};
export const designScope = (scope) => `<!-- import-loop:design-scope ${JSON.stringify(scope)} -->`;
const cookKey = (body) => body.slice(COOK_NOTE.length).split(' ')[0];
const designOf = (body) => { const scope = body.match(/<!-- import-loop:design-scope (\{.*?\}) -->/)?.[1]; return scope ? JSON.parse(scope) : null; };
// A pass cancelled after triage posted its design notice never runs that
// round's Cook: no Cook note carries its instance and no rework of a fix it
// opened reports this site. Such a round never happened: it opens nothing and
// resets nothing.
const unrunDesign = (comments, index, site) => {
  const { instance } = designOf(comments[index].body);
  return Boolean(instance) && !comments.slice(index + 1).some(({ body = '' }) => (body.startsWith(COOK_NOTE) && cookKey(body).startsWith(`${instance}-`)) || (body.startsWith(REWORK_NOTE) && body.match(/ on (\S+) refuted /)?.[1] === site));
};
export const cookHistory = (comments, site) => {
  let failed = 0, designs = 0, accepted = false, rerouted = null;
  const scopes = [];
  for (const [index, { body = '' }] of comments.entries()) {
    if (body.startsWith(COOK_NOTE)) {
      const key = cookKey(body);
      const scope = scopes.find((s) => s.instance && key.startsWith(`${s.instance}-`));
      if (!designs || !scope || scope.site === site) failed++;
      if (designs && scope?.site === site) {
        if (/Decision:\W*accept/i.test(body)) accepted = true;
        const repo = body.match(/Decision:\W*reroute\W+(?:to\W+)?([\w./-]+)/i)?.[1].split('/').pop();
        if (repo) rerouted = { repo, note: body };
      }
    }
    else if (body.startsWith(REWORK_NOTE)) { const from = body.match(/ on (\S+) refuted /)?.[1]; if (!designs || !from || from === site) failed++; }
    else if (body.startsWith(DESIGN_NOTICE)) {
      const scope = designOf(body);
      if (!scope || (scope.site === site && unrunDesign(comments, index, site))) continue;
      scopes.push(scope);
      if (scopes.at(-1).site === site) { failed = 0; designs++; }
    }
    else if (!body.startsWith(CAP_NOTICE) && !body.startsWith(REROUTE_NOTICE)) { failed = 0; designs = 0; accepted = false; rerouted = null; }
  }
  return { failed, designs, accepted, ...(rerouted ? { rerouted } : {}) };
};

export const predicateEnv = (subject, cell, site) => ({
  ...process.env,
  PREDICATE_TARGET: JSON.stringify({ subject, route: cell.route || '/', width: cell.width || 1440, evidence: cell.evidence }),
  WORDPRESS_PATH: site,
  // Gates run under a private HOME, where `studio wp` cannot find its sites:
  // the loop passes a WP-CLI that reads the site from its path alone.
  WP_CLI: args['wp-cli'] || process.env.WP_CLI || 'studio wp',
});

// A clone of `github` kept beside the passes (`runs`/clones), fetched by the
// commits each check needs: a repository the loop measures without a caller's
// checkout.
export function repoClone(github, runs) {
  const dir = path.join(runs, 'clones', github.split('/')[1]);
  if (!fs.existsSync(path.join(dir, '.git'))) execFileSync('git', ['clone', '-q', '--filter=blob:none', '--no-checkout', `https://github.com/${github}.git`, dir]);
  return dir;
}

// Materialize `capture` through the release importer with the transformer at
// `transformer` (gates/reimport.sh) and serve it; predicates given its URL as
// PREDICATE_SITE_URL check the route the whole import produced. `importer`
// names a candidate importer's sources to lay over the release's. The server
// stops when this process exits.
const reimportRun = (transformer, { capture, ssi_zip, bare_wp }, dir, importer) => [new URL('./gates/reimport.sh', import.meta.url).pathname, transformer, capture, dir, { env: { ...process.env, SSI_ZIP: ssi_zip, BARE_WP: bare_wp, SSI_SOURCE: importer } }];
function reimportUrl({ status, stdout, stderr }, dir) {
  if (status !== 0) {
    // The importer refused the site: name its reasons, keep its output in the log.
    process.stderr.write(stderr);
    const reasons = [...new Set([...stderr.matchAll(/"(?:reason_code|code)":"([a-z0-9_]+)"/g)].map((m) => m[1]))];
    throw Object.assign(new Error(`import_failed: ${reasons.join(', ') || stderr.trim().split('\n').pop()?.slice(0, 200) || `exit ${status}`}`), { import_failed: true });
  }
  const pid = Number(fs.readFileSync(`${dir}/.server.pid`, 'utf8'));
  // Stop the server's whole process group when this step ends, including when
  // Homeboy terminates it.
  process.on('exit', () => { try { process.kill(-pid); } catch {} });
  for (const signal of ['SIGTERM', 'SIGINT']) process.once(signal, () => process.exit(128 + (signal === 'SIGTERM' ? 15 : 2)));
  return stdout.trim().split('\n').pop();
}
export function reimportSite(transformer, reimport, dir, importer = '') {
  const [gate, ...rest] = reimportRun(transformer, reimport, dir, importer);
  const options = rest.pop();
  return reimportUrl(spawnSync('bash', [gate, ...rest], { ...options, encoding: 'utf8', maxBuffer: 256 << 20, stdio: ['ignore', 'pipe', 'pipe'] }), dir);
}

// A pull request is judged as it would ship: its head merged onto the base the
// pass measured (`at.base`: Blocks Engine's trunk for the transformer, the
// importer release's commit for the importer), against that measurement
// (`base_findings`). A stale branch's own merge base can predate the release
// and fail to import at all, which says nothing about the fix. `mergedHead`
// writes the repository's `candidate.paths` of the merge once per head under
// `work`/heads (gates/merge-onto.sh); a conflict is an import_failed reason.
const heads = new Map();
export function mergedHead(item, pr, { clone, candidate, base, work }) {
  const dir = `${work}/heads/${item.repo}-${pr.number}-${pr.headRefOid.slice(0, 8)}-on-${base.slice(0, 8)}`;
  if (!heads.has(dir) && !fs.existsSync(dir)) {
    execFileSync('git', ['-C', clone, 'fetch', '-q', 'origin', base, `pull/${pr.number}/head`]);
    const merge = spawnSync('bash', [new URL('./gates/merge-onto.sh', import.meta.url).pathname, clone, base, pr.headRefOid, dir, ...candidate.paths], { encoding: 'utf8' });
    if (merge.status) heads.set(dir, Object.assign(new Error(merge.stderr.trim().split('\n').pop() || `merge_onto_exit_${merge.status}`), { import_failed: true }));
  }
  return dir;
}
// A transformer candidate replaces the release's transformer; an importer
// candidate runs on the pass's trunk transformer (`at.transformer`). A client
// candidate (the capture and compare client) re-captures the source with
// itself and imports that capture on the trunk transformer
// (gates/client-candidate.sh); it compares with itself too (`compareRun`).
const servedRun = (dir, { candidate, transformer, reimport, url }) => candidate.slot === 'client'
  ? [new URL('./gates/client-candidate.sh', import.meta.url).pathname, dir, url, `${dir}-capture`, transformer, `${dir}-site`, { env: { ...process.env, SSI_ZIP: reimport.ssi_zip, BARE_WP: reimport.bare_wp } }]
  : candidate.slot === 'transformer'
    ? reimportRun(`${dir}/${candidate.paths[0]}`, reimport, `${dir}-site`, '')
    : reimportRun(transformer, reimport, `${dir}-site`, dir);
function settle(dir, serve) {
  try {
    heads.set(dir, serve());
  } catch (error) {
    if (!error.import_failed) throw error;
    heads.set(dir, error);
  }
}
function served(dir, at) {
  if (!heads.has(dir)) {
    const [gate, ...rest] = servedRun(dir, at);
    const options = rest.pop();
    settle(dir, () => reimportUrl(spawnSync('bash', [gate, ...rest], { ...options, encoding: 'utf8', maxBuffer: 256 << 20, stdio: ['ignore', 'pipe', 'pipe'] }), `${dir}-site`));
  }
  return heads.get(dir);
}

// The acceptance cells of a tracked finding on a pull request merged onto its
// base, re-imported once per merge. The re-import loads only the candidate's
// sources (gates/reimport.sh); its runtime libraries come with the release, so
// no install runs here. Returns [{route, width, exit}]: exit 'imported' on every
// cell, or 'import_failed' (with a reason) when the importer refuses the site.
export function cellsAtHead(item, pr, at) {
  const dir = mergedHead(item, pr, at);
  const site = served(dir, at);
  if (site instanceof Error) return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: 'import_failed', reason: site.message }));
  // Predicate reduction is diagnostic only. Acceptance is decided by the
  // shared whole-site comparison below, including partial count reductions.
  return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: 'imported' }));
}

// Restyles outside `item`'s scope on a pull request merged onto its base: its
// whole import against the pass's evaluation at that trunk, on every route.
// Returns {exit, report}; exit 0 means none, 2 means it could not be compared.
export function collateralAtHead(item, pr, at) {
  const head = mergedHead(item, pr, at);
  if (!heads.has(head)) throw new Error('collateral_requires_cellsAtHead_first');
  if (heads.get(head) instanceof Error) return { exit: 2, report: [heads.get(head).message] };
  const key = `${head}-checks-${item.signature}`;
  if (!collaterals.has(key)) { const [argv, env] = compareRun(item, head, at); collaterals.set(key, collateralResult(spawnSync('node', argv, { env, encoding: 'utf8', maxBuffer: 64 << 20 }))); }
  return collaterals.get(key);
}
const collaterals = new Map();
// accept.mjs exits 1 only after printing its verdict ({"pass":false,…}). A
// crash also exits 1 (node's uncaught error, a missing module), and measured
// nothing: without the verdict line it is a failed comparison (2), which leaves
// the head unconfirmed instead of refuting it and charging the issue a rework.
const verdictLine = (stdout = '') => stdout.trim().split('\n').reverse().some((line) => { try { return JSON.parse(line)?.pass === false; } catch { return false; } });
export const collateralResult = (run) => ({ exit: run.status === 1 && !verdictLine(run.stdout) ? 2 : run.status ?? 2, report: [run.stdout || '', run.stderr || ''] });
// The candidate's whole import against the pass's evaluation (gates/accept.mjs).
// A client candidate is compared on its own capture with its own package.
function compareRun(item, head, { reimport, base_findings, candidate, url }) {
  const client = candidate.slot === 'client';
  const argv = [new URL('./gates/accept.mjs', import.meta.url).pathname, '--base-findings', base_findings, '--candidate-url', heads.get(head), '--candidate-site', `${head}-site`, '--capture', client ? `${head}-capture` : reimport.capture, '--url', url, '--signature', item.signature, '--cells', JSON.stringify(item.cells), '--wp-cli', `php ${reimport.bare_wp}/wp-cli.phar`, '--out', `${head}-checks-${item.signature}`];
  return [argv, { ...process.env, ...(client ? { IMPORT_LOOP_CLIENT_PACKAGE: `${head}-capture/.client.tgz` } : {}) }];
}

// Each head check is its own merge, re-import, and server, so independent
// checks run at once: a pass with many open pull requests waits for the
// slowest one, not for their sum. `checks` is [{item, pr, at}]; afterwards
// cellsAtHead and collateralAtHead answer from what ran here. Merges run first
// and in order, since they fetch into the shared clone.
const running = (command, argv, options = {}) => new Promise((resolve) => {
  const child = spawn(command, argv, { ...options, stdio: ['ignore', 'pipe', 'pipe'] });
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (chunk) => { stdout += chunk; });
  child.stderr.on('data', (chunk) => { stderr += chunk; });
  child.on('close', (status) => resolve({ status, stdout, stderr }));
});
async function pooled(tasks, width) {
  const queue = [...tasks];
  await Promise.all(Array.from({ length: Math.min(width, queue.length) }, async () => { while (queue.length) await queue.shift()(); }));
}
export async function checkHeads(checks, width = Math.max(1, Math.floor(os.availableParallelism() / 4))) {
  const merged = checks.map((check) => ({ ...check, head: mergedHead(check.item, check.pr, check.at) }));
  const serves = new Map(merged.filter(({ head }) => !heads.has(head)).map((check) => [check.head, check]));
  await pooled([...serves.values()].map(({ head, at }) => async () => {
    const [gate, ...rest] = servedRun(head, at);
    const options = rest.pop();
    const run = await running('bash', [gate, ...rest], options);
    settle(head, () => reimportUrl(run, `${head}-site`));
  }), width);
  const compared = merged.filter(({ item, head }) => !(heads.get(head) instanceof Error) && !collaterals.has(`${head}-checks-${item.signature}`));
  await pooled(compared.map(({ item, head, at }) => async () => {
    const [argv, env] = compareRun(item, head, at);
    collaterals.set(`${head}-checks-${item.signature}`, collateralResult(await running('node', argv, { env })));
  }), width);
}

// 0: the defect reproduces. 1: it does not. Anything else: the predicate failed.
export const predicateExit = (predicate, transformer, page, env) => spawnSync('node', [predicate, transformer, page], { stdio: 'ignore', env }).status;

// A directory as one `.tgz` for a Cook's provider evidence, which Homeboy caps
// at 64 MB. A capture holds the same media under `media/` and
// `resources/external/`, so files with equal content go in as hard links to
// one copy: base44's capture packs to 37.6 MB instead of 72.3 MB. The staging
// tree hard-links the source (copying only across filesystems) and never
// writes to it.
export function evidenceArchive(source, archive) {
  const staged = `${archive}.staging`;
  fs.rmSync(staged, { recursive: true, force: true });
  fs.mkdirSync(path.dirname(archive), { recursive: true });
  try { execFileSync('cp', ['-RLl', `${fs.realpathSync(source)}/.`, staged], { stdio: 'ignore' }); } catch { fs.rmSync(staged, { recursive: true, force: true }); fs.cpSync(source, staged, { recursive: true, dereference: true }); }
  const first = new Map();
  for (const file of fs.readdirSync(staged, { recursive: true })) {
    const full = path.join(staged, file);
    if (!fs.lstatSync(full).isFile()) continue;
    const key = createHash('sha1').update(fs.readFileSync(full)).digest('hex');
    if (!first.has(key)) { first.set(key, full); continue; }
    fs.rmSync(full);
    fs.linkSync(first.get(key), full);
  }
  execFileSync('tar', ['-czf', archive, '-C', staged, '.']);
  fs.rmSync(staged, { recursive: true, force: true });
  return archive;
}
