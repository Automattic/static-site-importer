// Shared by the loop steps: arguments, GitHub, and predicate runs.

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';

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
export function linkedPullRequests(issueUrl) {
  const [, owner, name, , number] = new URL(issueUrl).pathname.split('/');
  const github = `${owner}/${name}`;
  return JSON.parse(gh('pr', 'list', '-R', github, '--state', 'open', '--json', 'number,url,body,headRefName,headRefOid,isDraft,mergeable,statusCheckRollup', '-L', '100'))
    .filter((pr) => (pr.body || '').includes(issueUrl) || new RegExp(`#${number}\\b`).test(pr.body || '') || pr.headRefName.includes(`issue-${number}-`))
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
export const predicateEnv = (subject, cell, site) => ({
  ...process.env,
  PREDICATE_TARGET: JSON.stringify({ subject, route: cell.route || '/', width: cell.width || 1440, evidence: cell.evidence }),
  WORDPRESS_PATH: site,
  // Gates run under a private HOME, where `studio wp` cannot find its sites:
  // the loop passes a WP-CLI that reads the site from its path alone.
  WP_CLI: args['wp-cli'] || process.env.WP_CLI || 'studio wp',
});

// Materialize `capture` through the release importer with the transformer at
// `transformer` (gates/reimport.sh) and serve it; predicates given its URL as
// PREDICATE_SITE_URL check the route the whole import produced. The server
// stops when this process exits.
export function reimportSite(transformer, { capture, ssi_zip, bare_wp }, dir) {
  const gate = new URL('./gates/reimport.sh', import.meta.url).pathname;
  let url;
  try {
    url = execFileSync('bash', [gate, transformer, capture, dir], { encoding: 'utf8', maxBuffer: 256 << 20, env: { ...process.env, SSI_ZIP: ssi_zip, BARE_WP: bare_wp }, stdio: ['ignore', 'pipe', 'pipe'] }).trim().split('\n').pop();
  } catch (error) {
    // The importer refused the site: name its reasons, keep its output in the log.
    const stderr = String(error.stderr || '');
    process.stderr.write(stderr);
    const reasons = [...new Set([...stderr.matchAll(/"(?:reason_code|code)":"([a-z0-9_]+)"/g)].map((m) => m[1]))];
    throw Object.assign(new Error(`import_failed: ${reasons.join(', ') || stderr.trim().split('\n').pop()?.slice(0, 200) || `exit ${error.status}`}`), { import_failed: true });
  }
  const pid = Number(fs.readFileSync(`${dir}/.server.pid`, 'utf8'));
  process.on('exit', () => { try { process.kill(pid); } catch {} });
  return url;
}

// A pull request is judged as it would ship: its head merged onto the pass's
// Blocks Engine trunk (`trunk`, the commit the import step re-imported and the
// evaluation measured), against that evaluation (`base_findings`). A stale
// branch's own merge base can predate the release importer and fail to import
// at all, which says nothing about the fix. `mergedHead` writes the merge once
// per head under `work`/heads (gates/merge-onto.sh); a conflict is an
// import_failed reason.
const heads = new Map();
function mergedHead(item, pr, { clone, root, work, trunk }) {
  const dir = `${work}/heads/${item.repo}-${pr.number}-${pr.headRefOid.slice(0, 8)}-on-${trunk.slice(0, 8)}`;
  if (!heads.has(dir) && !fs.existsSync(`${dir}/${root}`)) {
    execFileSync('git', ['-C', clone, 'fetch', '-q', 'origin', 'trunk', `pull/${pr.number}/head`]);
    const merge = spawnSync('bash', [new URL('./gates/merge-onto.sh', import.meta.url).pathname, clone, trunk, pr.headRefOid, root, dir], { encoding: 'utf8' });
    if (merge.status) heads.set(dir, Object.assign(new Error(merge.stderr.trim().split('\n').pop() || `merge_onto_exit_${merge.status}`), { import_failed: true }));
  }
  return dir;
}
function served(transformer, reimport, dir) {
  if (!heads.has(dir)) {
    try {
      heads.set(dir, reimportSite(transformer, reimport, `${dir}-site`));
    } catch (error) {
      if (!error.import_failed) throw error;
      heads.set(dir, error);
    }
  }
  return heads.get(dir);
}

// The acceptance cells of a tracked finding on a pull request merged onto
// trunk, re-imported once per merge. The re-import loads only the transformer's
// sources (gates/reimport.sh); its runtime libraries come with the release, so
// no install runs here. Returns [{route, width, exit}]: exit 'imported' on every
// cell, or 'import_failed' (with a reason) when the importer refuses the site.
export function cellsAtHead(item, pr, at) {
  const dir = mergedHead(item, pr, at);
  const site = served(`${dir}/${at.root}`, at.reimport, dir);
  if (site instanceof Error) return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: 'import_failed', reason: site.message }));
  // Predicate reduction is diagnostic only. Acceptance is decided by the
  // shared whole-site comparison below, including partial count reductions.
  return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: 'imported' }));
}

// Restyles outside `item`'s scope on a pull request merged onto trunk: its
// whole import against the pass's evaluation at that trunk, on every route.
// Returns {exit, report}; exit 0 means none, 2 means it could not be compared.
export function collateralAtHead(item, pr, at) {
  const head = mergedHead(item, pr, at);
  if (!heads.has(head)) throw new Error('collateral_requires_cellsAtHead_first');
  if (heads.get(head) instanceof Error) return { exit: 2, report: [heads.get(head).message] };
  const { reimport } = at;
  const gate = new URL('./gates/accept.mjs', import.meta.url).pathname;
  const receipt = JSON.parse(fs.readFileSync(`${reimport.capture}/capture-receipt.json`, 'utf8'));
  const url = receipt.source?.url || receipt.url;
  const run = spawnSync('node', [gate, '--base-findings', at.base_findings, '--candidate-url', heads.get(head), '--candidate-site', `${head}-site`, '--capture', reimport.capture, '--url', url, '--signature', item.signature, '--cells', JSON.stringify(item.cells), '--wp-cli', `php ${reimport.bare_wp}/wp-cli.phar`, '--out', `${head}-checks-${item.signature}`], { encoding: 'utf8', maxBuffer: 64 << 20 });
  return { exit: run.status ?? 2, report: [run.stdout || '', run.stderr || ''] };
}

// 0: the defect reproduces. 1: it does not. Anything else: the predicate failed.
export const predicateExit = (predicate, transformer, page, env) => spawnSync('node', [predicate, transformer, page], { stdio: 'ignore', env }).status;
