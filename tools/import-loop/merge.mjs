#!/usr/bin/env node
// Loop step, after the merge approval: merge every open pull request that
// fixes a tracked issue and whose checks are green.
//
// Usage: node merge.mjs --triage <triage.json>
// Output: {merged:[{repo, pr, sha}], merged_count, held:[{pr, reason}]}

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[index + 1]]] : pairs), []));
const triage = JSON.parse(fs.readFileSync(args.triage, 'utf8'));
const gh = (...argv) => execFileSync('gh', argv, { encoding: 'utf8', maxBuffer: 64 << 20 });

const merged = [];
const held = [];
for (const item of triage.tracked) {
  const [, owner, name, , number] = new URL(item.issue_url).pathname.split('/');
  const github = `${owner}/${name}`;
  const prs = JSON.parse(gh('pr', 'list', '-R', github, '--state', 'open', '--json', 'number,url,body,headRefName,isDraft,mergeable,statusCheckRollup', '-L', '100'))
    .filter((pr) => (pr.body || '').includes(item.issue_url) || new RegExp(`#${number}\\b`).test(pr.body || '') || pr.headRefName.includes(`issue-${number}-`));
  for (const pr of prs) {
    const checks = pr.statusCheckRollup || [];
    const red = checks.filter((c) => !['SUCCESS', 'NEUTRAL', 'SKIPPED'].includes(c.conclusion || c.state));
    if (pr.isDraft) { held.push({ pr: pr.url, reason: 'draft' }); continue; }
    if (pr.mergeable !== 'MERGEABLE') { held.push({ pr: pr.url, reason: `mergeable=${pr.mergeable}` }); continue; }
    if (!checks.length || red.length) { held.push({ pr: pr.url, reason: checks.length ? `checks not green: ${red.map((c) => c.name || c.context).join(', ')}` : 'no checks reported' }); continue; }
    gh('pr', 'merge', pr.url, '--squash', '--delete-branch');
    const sha = JSON.parse(gh('pr', 'view', pr.url, '--json', 'mergeCommit')).mergeCommit?.oid;
    merged.push({ repo: item.repo, github, pr: pr.url, sha });
    console.error(`[merge] merged ${pr.url} ${sha}`);
  }
}
const output = { merged, merged_count: merged.length, held };
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
