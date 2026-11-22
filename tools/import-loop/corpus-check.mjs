// Cross-site no-regression for confirm.mjs, on the frozen corpus (corpus.mjs).
//
// A head that passes on the site under repair must also leave every frozen
// corpus site no worse than the pass's trunk transformer and importer release.
// The baseline for that trunk and release is recorded once, under a lock, since
// per-Cook confirms run at once; each head then replays against it. Regressions
// refute the head, and confirm records them on its pull request (CORPUS_NOTE),
// where the next triage reads them to rework it. A head the corpus could not
// measure stays unconfirmed.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

export const CORPUS_NOTE = '<!-- import-loop:corpus-refuted ';

// One replay's verdict: regressed and improved cells per site, and anything
// the replay could not measure.
export function corpusOutcome(replay) {
  const sites = replay.sites || [];
  const per = (field) => sites.filter((s) => s[field]?.length).map((s) => ({ site: s.site, cells: s[field] }));
  return {
    regressed: per('regressions'),
    improved: per('improvements'),
    unmeasured: [...(replay.unmeasured || []), ...sites.filter((s) => s.unmeasured?.length).map((s) => `${s.site}: ${s.unmeasured.join('; ')}`)],
  };
}

// The regressions confirm recorded on a pull request for this exact head, or null.
export function corpusRefutation(comments, head) {
  for (const { body = '' } of [...comments].reverse()) {
    if (!body.startsWith(CORPUS_NOTE)) continue;
    const note = JSON.parse(body.slice(CORPUS_NOTE.length, body.indexOf(' -->')));
    return note.head === head ? note.regressed : null;
  }
  return null;
}

export const corpusNote = (head, regressed, baseline) => [
  `${CORPUS_NOTE}${JSON.stringify({ head, regressed })} -->`,
  `Import loop refuted head \`${head.slice(0, 8)}\`: replaying the frozen capture corpus with it (static-site-importer \`tools/import-loop/corpus.mjs\`) makes other sites worse than the pass's trunk and release (baseline \`${path.basename(baseline)}\`):`,
  '',
  ...regressed.map((r) => `- ${r.site}: ${r.cells.map((c) => `\`${c}\``).join(', ')}`),
  '',
  'A fix must leave every corpus site no worse. The next pass reworks this head on its branch.',
].join('\n');

const parsed = (run) => {
  try { return JSON.parse(String(run.stdout || '').trim().split('\n').at(-1)); } catch { return { sites: [], unmeasured: [`corpus.mjs exit ${run.status}: ${String(run.stderr || run.stdout || '').trim().slice(-300)}`] }; }
};
const sleep = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);

function holderAlive(lock) {
  let pid;
  try { pid = Number(fs.readFileSync(path.join(lock, 'pid'), 'utf8')); } catch { return true; } // still being written
  try { process.kill(pid, 0); return true; } catch (error) { return error.code === 'EPERM'; }
}

function withLock(lock, fn) {
  for (const start = Date.now(); ;) {
    try { fs.mkdirSync(lock); fs.writeFileSync(path.join(lock, 'pid'), String(process.pid)); break; } catch (error) {
      if (error.code !== 'EEXIST') throw error;
      // A lock whose holder has exited, or older than the longest recording, is stale.
      if (!holderAlive(lock) || Date.now() - fs.statSync(lock).mtimeMs > 2 * 3600_000) { fs.rmSync(lock, { recursive: true, force: true }); continue; }
      if (Date.now() - start > 2 * 3600_000) throw new Error(`corpus_baseline_lock_timeout: ${lock}`);
      sleep(10_000);
    }
  }
  try { return fn(); } finally { fs.rmSync(lock, { recursive: true, force: true }); }
}

// heads: [{verdict, key, transformer, importer?} | {verdict, key, skip}]
export function corpusCheck({ corpus, sites, triage, here, log, heads }) {
  if (!corpus) return { summary: { status: 'not_configured' } };
  const root = path.resolve(corpus);
  const env = { ...process.env, SSI_ZIP: triage.reimport.ssi_zip, BARE_WP: triage.reimport.bare_wp };
  const corpusRun = (mode, extra) => parsed(spawnSync('node', [path.join(here, 'corpus.mjs'), mode, '--corpus', root, ...extra], { env, encoding: 'utf8', maxBuffer: 256 << 20 }));
  const measured = heads.filter((h) => !h.skip);
  const summary = { status: 'checked', heads: heads.filter((h) => h.skip).map((h) => ({ pr: h.key, skipped: h.skip })) };
  if (!measured.length) return { summary };
  const baseline = path.join(root, '.baselines', `${triage.trunk.slice(0, 12)}-${triage.bases['static-site-importer'].slice(0, 12)}`);
  const recorded = path.join(baseline, '.recorded.json');
  fs.mkdirSync(path.dirname(baseline), { recursive: true });
  withLock(`${baseline}.lock`, () => {
    if (fs.existsSync(recorded)) return;
    const start = Date.now();
    const result = corpusRun('record', ['--blocks-engine', triage.transformer, '--baseline', baseline, ...(sites ? ['--sites', sites] : [])]);
    const unmeasured = corpusOutcome(result).unmeasured;
    // A recording that measured no site is retried by the next confirm.
    if (!fs.existsSync(baseline) || !fs.readdirSync(baseline).some((f) => f.endsWith('.json'))) { log(`corpus baseline not recorded: ${unmeasured.join('; ').slice(0, 500)}`); return; }
    fs.writeFileSync(recorded, JSON.stringify({ seconds: (Date.now() - start) / 1000, unmeasured }, null, 2));
  });
  // Sites trunk itself cannot measure have no baseline: reported, never dropped silently.
  const baselined = !fs.existsSync(recorded) ? [] : fs.readdirSync(baseline).filter((f) => f.endsWith('.json') && !f.startsWith('.')).map((f) => f.slice(0, -5)).filter((s) => !sites || sites.split(',').includes(s));
  Object.assign(summary, { baseline, sites: baselined, unbaselined: fs.existsSync(recorded) ? JSON.parse(fs.readFileSync(recorded, 'utf8')).unmeasured : ['no site measured at trunk'] });
  for (const head of measured) {
    if (!baselined.length) { head.verdict.reasons.push(`the corpus has no measured baseline at ${path.basename(baseline)}`); continue; }
    const start = Date.now();
    const replay = (on) => corpusOutcome(corpusRun('replay', ['--blocks-engine', head.transformer, '--baseline', baseline, '--sites', on.join(','), ...(head.importer ? ['--importer-source', head.importer] : [])]));
    const outcome = replay(baselined);
    // A cell can fail one replay on a loaded machine; a regression stands only
    // when a second replay of its sites repeats it.
    if (outcome.regressed.length) {
      const sites = outcome.regressed.map((r) => r.site);
      const again = replay(sites);
      outcome.regressed = outcome.regressed.map((r) => ({ site: r.site, cells: r.cells.filter((c) => again.regressed.find((a) => a.site === r.site)?.cells.includes(c)) })).filter((r) => r.cells.length);
      outcome.unmeasured = [...outcome.unmeasured.filter((u) => !sites.some((site) => u.startsWith(`${site}: `))), ...again.unmeasured];
    }
    const seconds = (Date.now() - start) / 1000;
    log(head.key, `corpus: ${outcome.regressed.length} regressed, ${outcome.improved.length} improved, ${outcome.unmeasured.length} unmeasured in ${seconds}s`);
    summary.heads.push({ pr: head.key, seconds, ...outcome });
    if (outcome.regressed.length) head.verdict.failing.push({ corpus: outcome.regressed, baseline });
    else if (outcome.unmeasured.length) head.verdict.reasons.push(`corpus replay could not measure: ${outcome.unmeasured.join('; ').slice(0, 500)}`);
  }
  return { summary };
}
