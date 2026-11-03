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
  const url = execFileSync('bash', [gate, transformer, capture, dir], { encoding: 'utf8', env: { ...process.env, SSI_ZIP: ssi_zip, BARE_WP: bare_wp }, stdio: ['ignore', 'pipe', 'inherit'] }).trim().split('\n').pop();
  const pid = Number(fs.readFileSync(`${dir}/.server.pid`, 'utf8'));
  process.on('exit', () => { try { process.kill(pid); } catch {} });
  return url;
}

// The acceptance cells of a tracked finding, checked on a pull request's head:
// a worktree of `clone` at the head (dependencies installed under
// `work`/heads), re-imported once per head. Returns [{route, width, exit}];
// exit 1 on every cell means the defect is gone.
const heads = new Map();
export function cellsAtHead(item, pr, { clone, root, work, reimport, site }) {
  const dir = `${work}/heads/${item.repo}-${pr.number}-${pr.headRefOid.slice(0, 8)}`;
  const transformer = `${dir}/${root}`;
  if (!heads.has(dir)) {
    if (!fs.existsSync(dir)) {
      execFileSync('git', ['-C', clone, 'fetch', '-q', 'origin', `pull/${pr.number}/head`]);
      execFileSync('git', ['-C', clone, 'worktree', 'add', '-q', '--detach', dir, pr.headRefOid]);
      execFileSync('composer', ['install', '--no-interaction', '--no-progress', '--quiet'], { cwd: transformer, stdio: ['ignore', 'ignore', 'inherit'] });
    }
    heads.set(dir, reimportSite(transformer, reimport, `${dir}-site`));
  }
  const predicate = new URL(`./predicates/${item.check}.mjs`, import.meta.url).pathname;
  return item.cells.map((cell) => ({ route: cell.route, width: cell.width, exit: predicateExit(predicate, transformer, cell.page, { ...predicateEnv(item.subject, cell, site), PREDICATE_SITE_URL: heads.get(dir) }) }));
}

// 0: the defect reproduces. 1: it does not. Anything else: the predicate failed.
export const predicateExit = (predicate, transformer, page, env) => spawnSync('node', [predicate, transformer, page], { stdio: 'ignore', env }).status;
