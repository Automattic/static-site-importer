#!/usr/bin/env node
// Loop step, after the merge approval: merge every open pull request that
// fixes a tracked issue, is green, and that confirm.mjs did not refute.
//
// Usage: node merge.mjs --triage <triage.json> --confirm <confirm.json> [--dry-run 1]
// Output: {merged:[{repo, pr, sha}], merged_count, held:[{pr, reason}]}
// With --dry-run, `merged` lists what approval would merge (sha is the head).

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
    if (refutation) { held.push({ pr: pr.url, reason: refutation.cells.map((c) => (c.collateral ? `restyles outside ${c.signature}` : `defect reproduces at ${c.route}@${c.width}`)).join(', ') }); continue; }
    // Wait for checks still running at this head (up to an hour), then judge them.
    let checks = pr.statusCheckRollup || [];
    for (let waited = 0; checks.some((c) => !(c.conclusion || ['SUCCESS', 'FAILURE', 'ERROR'].includes(c.state))) && waited < 60; waited++) {
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 60000);
      const now = JSON.parse(gh('pr', 'view', pr.url, '--json', 'headRefOid,statusCheckRollup'));
      if (now.headRefOid !== pr.headRefOid) break;
      checks = now.statusCheckRollup || [];
    }
    const red = checks.filter((c) => !['SUCCESS', 'NEUTRAL', 'SKIPPED'].includes(c.conclusion || c.state));
    // GitHub computes mergeability lazily; UNKNOWN settles within seconds.
    for (let attempt = 0; pr.mergeable === 'UNKNOWN' && attempt < 6; attempt++) {
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 5000);
      pr.mergeable = JSON.parse(gh('pr', 'view', pr.url, '--json', 'mergeable')).mergeable;
    }
    if (pr.mergeable !== 'MERGEABLE') { held.push({ pr: pr.url, reason: `mergeable=${pr.mergeable}` }); continue; }
    if (!checks.length || red.length) { held.push({ pr: pr.url, reason: checks.length ? `checks not green: ${red.map((c) => c.name || c.context).join(', ')}` : 'no checks reported' }); continue; }
    if (args['dry-run']) { merged.push({ repo: item.repo, github: pr.github, pr: pr.url, sha: pr.headRefOid }); continue; }
    // A draft is an incomplete Cook that later passes kept working on. Once it is
    // confirmed at this head, green, and approved, it is ready.
    if (pr.isDraft) gh('pr', 'ready', pr.url);
    gh('pr', 'merge', pr.url, '--squash', '--match-head-commit', pr.headRefOid);
    // Delete only the remote head: gh's --delete-branch also edits the local
    // checkout, which fails after the merge when the loop runs detached.
    gh('api', '-X', 'DELETE', `repos/${pr.github}/git/refs/heads/${pr.headRefName}`);
    const sha = JSON.parse(gh('pr', 'view', pr.url, '--json', 'mergeCommit')).mergeCommit?.oid;
    merged.push({ repo: item.repo, github: pr.github, pr: pr.url, sha });
    console.error(`[merge] merged ${pr.url} ${sha}`);
  }
}
// A confirmed PR someone merged before this step ran is shipped work all the
// same, provided it merged at the head confirm checked.
for (const checked of confirm.confirmed) {
  if (merged.some((m) => m.pr === checked.pr)) continue;
  const pr = JSON.parse(gh('pr', 'view', checked.pr, '--json', 'state,headRefOid,mergeCommit'));
  if (pr.state !== 'MERGED') continue;
  if (pr.headRefOid !== checked.head) { held.push({ pr: checked.pr, reason: `merged at ${pr.headRefOid.slice(0, 8)}, not the confirmed head ${checked.head.slice(0, 8)}` }); continue; }
  const item = triage.tracked.find((t) => t.open_pr === checked.pr);
  const github = checked.pr.replace('https://github.com/', '').split('/pull/')[0];
  merged.push({ repo: item?.repo || github.split('/')[1], github, pr: checked.pr, sha: pr.mergeCommit?.oid });
  console.error(`[merge] already merged ${checked.pr} ${pr.mergeCommit?.oid}`);
}
const output = { merged, merged_count: merged.length, held };
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
