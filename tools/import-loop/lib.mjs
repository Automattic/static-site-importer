// Shared by the loop steps: arguments, GitHub, and predicate runs.

import { execFileSync, spawnSync } from 'node:child_process';

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
  PREDICATE_TARGET: JSON.stringify({ subject, width: cell.width || 1440, evidence: cell.evidence }),
  WORDPRESS_PATH: site,
  WP_CLI: process.env.WP_CLI || 'studio wp',
});

// 0: the defect reproduces. 1: it does not. Anything else: the predicate failed.
export const predicateExit = (predicate, transformer, page, env) => spawnSync('node', [predicate, transformer, page], { stdio: 'ignore', env }).status;
