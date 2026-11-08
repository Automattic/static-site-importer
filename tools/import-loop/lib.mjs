// Shared by the loop steps: arguments, GitHub, and predicate runs.

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';

export const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[index + 1]]] : pairs), []));

export const gh = (...argv) => execFileSync('gh', argv, { encoding: 'utf8', maxBuffer: 64 << 20 });

// Open pull requests that name the issue, by URL, by #number, or by Cook's branch name.
export function linkedPullRequests(issueUrl) {
  const [, owner, name, , number] = new URL(issueUrl).pathname.split('/');
  const github = `${owner}/${name}`;
  return JSON.parse(gh('pr', 'list', '-R', github, '--state', 'open', '--json', 'number,url,body,headRefName,headRefOid,isDraft,mergeable,statusCheckRollup', '-L', '100'))
    .filter((pr) => (pr.body || '').includes(issueUrl) || new RegExp(`#${number}\\b`).test(pr.body || '') || pr.headRefName.includes(`issue-${number}-`))
    .map((pr) => ({ ...pr, github }));
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

// The acceptance cells of a tracked finding, checked on a pull request's head:
// a worktree of `clone` at the head under `work`/heads, re-imported once per
// head. The re-import loads only the transformer's sources (gates/reimport.sh);
// its runtime libraries come with the release, so no install runs here. Returns [{route, width, exit}];
// exit 1 on every cell means the defect is gone; exit 'import_failed' (with a
// reason) means the importer refused the whole site at that head.
const heads = new Map();
export function cellsAtHead(item, pr, { clone, root, work, reimport, site }) {
  const dir = `${work}/heads/${item.repo}-${pr.number}-${pr.headRefOid.slice(0, 8)}`;
  const transformer = `${dir}/${root}`;
  if (!heads.has(dir)) {
    if (!fs.existsSync(dir)) {
      execFileSync('git', ['-C', clone, 'fetch', '-q', 'origin', `pull/${pr.number}/head`]);
      execFileSync('git', ['-C', clone, 'worktree', 'add', '-q', '--detach', dir, pr.headRefOid]);
    }
    try {
      heads.set(dir, reimportSite(transformer, reimport, `${dir}-site`));
    } catch (error) {
      if (!error.import_failed) throw error;
      heads.set(dir, error);
    }
  }
  // A head the importer refuses fixes nothing: every cell fails with its reason.
  const served = heads.get(dir);
  if (served instanceof Error) return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: 'import_failed', reason: served.message }));
  const predicate = new URL(`./predicates/${item.check}.mjs`, import.meta.url).pathname;
  return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: predicateExit(predicate, transformer, cell.page, { ...predicateEnv(item.subject, cell, site), PREDICATE_SITE_URL: served }) }));
}

// The elements a fix of `item` may restyle (gates/collateral.mjs): its subject
// and the media keys its evidence names, as one comma list.
export const scopeKeys = (item) => [...new Set([item.subject, ...(item.cells || []).flatMap((cell) => [cell.evidence?.key, ...(cell.evidence?.missing || []).map((m) => m.key)])].filter(Boolean))].join(',');

// Editability findings change how pages are built, not how they look: their
// fixes are held to whole-page pixel parity (gates/collateral.mjs `pixels`).
export const collateralMode = (item) => (item.stage === 'editability' ? 'pixels' : '');

// Restyles outside `item`'s scope on a pull request's head: its whole import
// against the import of its merge base with trunk, on every route.
// Returns {exit, report}; exit 0 means none.
export function collateralAtHead(item, pr, { clone, root, work, reimport, routes }) {
  const head = `${work}/heads/${item.repo}-${pr.number}-${pr.headRefOid.slice(0, 8)}`;
  if (!heads.has(head)) throw new Error('collateral_requires_cellsAtHead_first');
  if (heads.get(head) instanceof Error) return { exit: 2, report: [heads.get(head).message] };
  execFileSync('git', ['-C', clone, 'fetch', '-q', 'origin', 'trunk']);
  const sha = execFileSync('git', ['-C', clone, 'merge-base', pr.headRefOid, 'FETCH_HEAD'], { encoding: 'utf8' }).trim();
  const base = `${work}/bases/${item.repo}-${sha.slice(0, 8)}`;
  if (!heads.has(base)) {
    if (!fs.existsSync(base)) {
      fs.mkdirSync(base, { recursive: true });
      execFileSync('bash', ['-c', 'git -C "$1" archive "$2" "$3" | tar -x -C "$4"', '_', clone, sha, root, base]);
    }
    heads.set(base, reimportSite(`${base}/${root}`, reimport, `${base}-site`));
  }
  const gate = new URL('./gates/collateral.mjs', import.meta.url).pathname;
  // Exit 1 is a verdict (changes outside the subject); 2 means the comparison
  // itself failed, so it is retried and, if it persists, reported as unchecked.
  let run;
  for (let attempt = 0; attempt < 3 && (!run || run.status === 2); attempt++) run = spawnSync('node', [gate, heads.get(base), heads.get(head), scopeKeys(item), routes.join(','), collateralMode(item)], { encoding: 'utf8' });
  const report = (run.stderr || '').trim().split('\n').slice(-6);
  if (run.status === 2) return { exit: 2, report };
  // Editability debt counts as collateral: a fix must not trade one raw-HTML
  // companion or invalid block for another anywhere on the import.
  const editability = spawnSync('node', [new URL('./gates/editability.mjs', import.meta.url).pathname, `${base}-site`, `${head}-site`], { encoding: 'utf8' });
  const debt = (editability.stderr || '').trim().split('\n').slice(-6);
  if (editability.status === 2) return { exit: 2, report: debt };
  return { exit: Math.max(run.status, editability.status), report: [...(run.status ? report : []), ...(editability.status ? debt : [])] };
}

// 0: the defect reproduces. 1: it does not. Anything else: the predicate failed.
export const predicateExit = (predicate, transformer, page, env) => spawnSync('node', [predicate, transformer, page], { stdio: 'ignore', env }).status;
