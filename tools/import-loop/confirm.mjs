#!/usr/bin/env node
// Loop step, before merge: re-check every open fix on its head.
//
// A tracked finding lists acceptance cells, one per route/width it was seen
// in, each on the full captured page. Each head re-imports the capture as
// triage did. A fix is confirmed only when the shared check comparison shows
// fewer of its own occurrences, no other growing blocker, and no visual
// regression. Fixes in a repository whose code the predicates do not
// run (`predicate_root` in repos.json) are unconfirmed: the next pass's
// import is their check.
//
// Usage: node confirm.mjs --triage <triage.json> --blocks-engine <clone> --work <dir>
// Output: {confirmed:[{pr, head}], refuted:[{pr, head, cells}], unconfirmed:[{pr, head, reason}],
//          merge:[{github, number, sha}], merge_count, confirm_path}
// `merge` is what the merge step merges once its checks are green, each at the
// head checked here: every PR not refuted. A confirmed draft (an incomplete Cook that a later pass finished) is
// marked ready, since confirm just proved it; an unconfirmed draft waits.

import fs from 'node:fs';
import path from 'node:path';
import { args, cellsAtHead, collateralAtHead, gh, linkedPullRequests } from './lib.mjs';
import { compareImports } from './progress.mjs';

const here = path.dirname(new URL(import.meta.url).pathname);
for (const required of ['triage', 'blocks-engine', 'work']) if (!args[required]) throw new Error(`confirm_argument_missing: --${required}`);
const repos = JSON.parse(fs.readFileSync(path.join(here, 'repos.json'), 'utf8'));
const triage = JSON.parse(fs.readFileSync(args.triage, 'utf8'));
const clones = { 'blocks-engine': args['blocks-engine'] };
const log = (...parts) => console.error('[confirm]', ...parts);

// One pull request can fix several tracked findings: it is confirmed only
// when the shared acceptance comparison passes for each finding.
process.env.PREDICATE_CAPTURE = triage.reimport.capture;
const verdicts = new Map();
for (const item of triage.tracked) {
  for (const pr of linkedPullRequests(item.issue_url)) {
    const verdict = verdicts.get(pr.url) || { head: pr.headRefOid, github: pr.github, number: pr.number, draft: pr.isDraft, failing: [], reasons: [] };
    verdicts.set(pr.url, verdict);
    const root = repos[item.repo]?.predicate_root;
    if (!root || !clones[item.repo]) { verdict.reasons.push(`predicates do not run ${item.repo} code`); continue; }
    if (!item.cells?.length) { verdict.reasons.push(`no acceptance cells for ${item.signature}`); continue; }
    const at = { clone: clones[item.repo], root, work: args.work, reimport: triage.reimport, site: triage.site, routes: triage.routes };
    // A head no Cook moved since triage was checked there, on this capture and release.
    const prior = item.head_check?.pr === pr.url && item.head_check.head === pr.headRefOid ? item.head_check : null;
    const cells = (prior?.cells || cellsAtHead(item, pr, at)).map((cell) => ({ signature: item.signature, ...cell }));
    log(pr.url, item.signature, prior ? '(checked by triage)' : '', cells.map((c) => `${c.route}@${c.width}=${c.exit}`).join(' '));
    verdict.failing.push(...cells.filter((c) => c.exit === 'import_failed'));
    if (cells.some((c) => c.exit === 'import_failed')) continue;
    const collateral = prior?.collateral || collateralAtHead(item, pr, at);
    log(pr.url, item.signature, `collateral=${collateral.exit}`);
    if (collateral.exit === 1) verdict.failing.push({ signature: item.signature, collateral: collateral.report });
    else if (collateral.exit) verdict.reasons.push(`collateral comparison failed for ${item.signature}: ${collateral.report.at(-1)}`);
  }
}
const entries = [...verdicts];
const refuted = entries.filter(([, v]) => v.failing.length).map(([pr, v]) => ({ pr, head: v.head, cells: v.failing }));
const unconfirmed = entries.filter(([, v]) => !v.failing.length && v.reasons.length).map(([pr, v]) => ({ pr, head: v.head, reason: [...new Set(v.reasons)].join("; ") }));
const confirmed = entries.filter(([, v]) => !v.failing.length && !v.reasons.length).map(([pr, v]) => ({ pr, head: v.head }));
for (const [pr, v] of entries.filter(([, v]) => v.draft && !v.failing.length && !v.reasons.length)) { gh('pr', 'ready', pr); v.draft = false; }
const merge = entries.filter(([, v]) => !v.failing.length && !v.reasons.length && !v.draft).map(([, v]) => ({ github: v.github, number: v.number, sha: v.head }));

fs.mkdirSync(args.work, { recursive: true });
const confirm_path = path.join(args.work, 'confirm.json');
// --after is the next live import's evaluate result. Compare actual instance
// counts; a merged PR or a cleared reduced predicate is not live confirmation.
const live = args.after ? JSON.parse(fs.readFileSync(args.after, 'utf8')) : null;
const before = triage.findings_path ? JSON.parse(fs.readFileSync(triage.findings_path, 'utf8')) : null;
const live_checks = live && before ? triage.tracked.map((item) => ({ signature: item.signature, ...compareImports(before, live, item.signature, item.cells) })) : [];
const output = { confirmed, refuted, unconfirmed, live_checks, live_status: live ? 'checked' : 'awaiting_next_import', progress: live && before ? compareImports(before, live).blockers : triage.progress, confirmed_count: confirmed.length, refuted_count: refuted.length, merge, merge_count: merge.length, confirm_path };
fs.writeFileSync(confirm_path, JSON.stringify(output, null, 2));
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
