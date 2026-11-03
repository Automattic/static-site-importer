#!/usr/bin/env node
// Loop step, after the merge approval: merge every open pull request that
// fixes a tracked issue, is green, and that confirm.mjs did not refute.
//
// Usage: node merge.mjs --triage <triage.json> --confirm <confirm.json>
// Output: {merged:[{repo, pr, sha}], merged_count, held:[{pr, reason}]}

import fs from 'node:fs';
import { args, gh, linkedPullRequests } from './lib.mjs';

const triage = JSON.parse(fs.readFileSync(args.triage, 'utf8'));
const confirm = JSON.parse(fs.readFileSync(args.confirm, 'utf8'));

const merged = [];
const held = [];
for (const item of triage.tracked) {
  for (const pr of linkedPullRequests(item.issue_url)) {
    if (merged.some((m) => m.pr === pr.url) || held.some((h) => h.pr === pr.url)) continue;
    // Merge exactly the head the approver saw confirmed.
    const checked = [...confirm.confirmed, ...confirm.refuted, ...confirm.unconfirmed].find((c) => c.pr === pr.url);
    if (!checked || checked.head !== pr.headRefOid) { held.push({ pr: pr.url, reason: checked ? `head moved since confirm: ${checked.head.slice(0, 8)} -> ${pr.headRefOid.slice(0, 8)}` : 'not checked by confirm' }); continue; }
    const refutation = confirm.refuted.find((r) => r.pr === pr.url);
    if (refutation) { held.push({ pr: pr.url, reason: `defect still reproduces: ${refutation.cells.map((c) => `${c.route}@${c.width}`).join(', ')}` }); continue; }
    const checks = pr.statusCheckRollup || [];
    const red = checks.filter((c) => !['SUCCESS', 'NEUTRAL', 'SKIPPED'].includes(c.conclusion || c.state));
    if (pr.isDraft) { held.push({ pr: pr.url, reason: 'draft' }); continue; }
    if (pr.mergeable !== 'MERGEABLE') { held.push({ pr: pr.url, reason: `mergeable=${pr.mergeable}` }); continue; }
    if (!checks.length || red.length) { held.push({ pr: pr.url, reason: checks.length ? `checks not green: ${red.map((c) => c.name || c.context).join(', ')}` : 'no checks reported' }); continue; }
    gh('pr', 'merge', pr.url, '--squash', '--delete-branch');
    const sha = JSON.parse(gh('pr', 'view', pr.url, '--json', 'mergeCommit')).mergeCommit?.oid;
    merged.push({ repo: item.repo, github: pr.github, pr: pr.url, sha });
    console.error(`[merge] merged ${pr.url} ${sha}`);
  }
}
const output = { merged, merged_count: merged.length, held };
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
