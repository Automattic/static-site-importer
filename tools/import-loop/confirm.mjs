#!/usr/bin/env node
// Loop step, before merge: re-check every open fix on its head.
//
// A tracked finding lists acceptance cells, one per route/width it was seen
// in, each on the full captured page. Each head re-imports the capture as
// triage did. A fix is confirmed only when the shared check comparison shows
// fewer of its own occurrences, no other growing blocker, and no visual
// regression. Each head is merged onto the commit the pass measured for its
// repository (triage's `bases`) and enters the re-import as repos.json's
// `candidate` says: the transformer, the importer, or the capture client
// (which captures the source again). Fixes in a repository with no
// `candidate` are unconfirmed.
//
// Usage: node confirm.mjs --triage <triage.json> --blocks-engine <clone> --work <dir>
//          [--issue <url> | --uncooked 1] [--merge 1]
// Output: {confirmed:[{pr, head}], refuted:[{pr, head, cells}], unconfirmed:[{pr, head, reason}],
//          merge:[{github, number, sha}], merge_count, merged:[github#number], confirm_path}
// --issue confirms one tracked issue's pull requests: the loop runs it per Cook,
// as soon as that Cook settles, so a fix merges without waiting for the pass's
// slowest Cook. --uncooked 1 confirms the tracked issues no Cook works this
// pass, alongside the Cooks. `merge` is every confirmed, ready PR at the head
// checked here; with --merge 1 each merges once its checks are green, at that
// head (Homeboy `git pr merge-when-green`). A confirmed draft (an incomplete
// Cook that a later pass finished) is marked ready, since confirm just proved
// it; an unconfirmed draft waits.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { args, cellsAtHead, checkHeads, collateralAtHead, gh, linkedPullRequests, repoClone } from './lib.mjs';
import { compareImports } from './progress.mjs';

// accept.mjs's verdict line lists warnings: blockers the fix ran past their limit without failing anywhere new.
const collateralWarnings = (report) => { for (const line of String(report[0] || '').trim().split('\n').reverse()) { try { const v = JSON.parse(line); if (v && 'pass' in v) return v.warnings || []; } catch {} } return []; };

const here = path.dirname(new URL(import.meta.url).pathname);
for (const required of ['triage', 'blocks-engine', 'work']) if (!args[required]) throw new Error(`confirm_argument_missing: --${required}`);
const repos = JSON.parse(fs.readFileSync(path.join(here, 'repos.json'), 'utf8'));
const triage = JSON.parse(fs.readFileSync(args.triage, 'utf8'));
const compareClient = JSON.parse(fs.readFileSync(path.join(here, 'compare-client.json'), 'utf8'));
const runsRoot = path.dirname(path.dirname(path.resolve(args['blocks-engine'])));
const clones = { 'blocks-engine': args['blocks-engine'], 'static-site-importer': repoClone(repos['static-site-importer'].github, runsRoot), [compareClient.owner_repo]: repoClone(repos[compareClient.owner_repo].github, runsRoot) };
const log = (...parts) => console.error('[confirm]', ...parts);

// One pull request can fix several tracked findings: it is confirmed only
// when the shared acceptance comparison passes for each finding.
process.env.PREDICATE_CAPTURE = triage.reimport.capture;
const verdicts = new Map();
const cooked = new Set((triage.cook_items || []).map((cook) => cook.issue_url));
const linked = new Map(triage.tracked.map((item) => [item, linkedPullRequests(item.issue_url)]));
const chosen = triage.tracked.filter((item) => args.issue ? item.issue_url === args.issue : args.uncooked ? !cooked.has(item.issue_url) : true);
// A pull request linked to several findings is judged on all of them.
const prs = new Set(chosen.flatMap((item) => linked.get(item).map((pr) => pr.url)));
const scope = triage.tracked.filter((item) => chosen.includes(item) || linked.get(item).some((pr) => prs.has(pr.url)));
const headAt = (item) => ({ clone: clones[item.repo], candidate: repos[item.repo]?.candidate, base: triage.bases?.[item.repo], transformer: triage.transformer, work: args.work, reimport: triage.reimport, site: triage.site, url: triage.url, routes: triage.routes, trunk: triage.trunk, base_findings: triage.findings_path });
// Every head a Cook moved since triage, checked at once.
await checkHeads(scope.flatMap((item) => repos[item.repo]?.candidate && clones[item.repo] && triage.bases?.[item.repo] && item.cells?.length
  ? linked.get(item).filter((pr) => !(item.head_check?.pr === pr.url && item.head_check.head === pr.headRefOid)).map((pr) => ({ item, pr, at: headAt(item) }))
  : []));
for (const item of scope) {
  for (const pr of linked.get(item)) {
    const verdict = verdicts.get(pr.url) || { head: pr.headRefOid, github: pr.github, number: pr.number, draft: pr.isDraft, failing: [], reasons: [], warnings: [] };
    verdicts.set(pr.url, verdict);
    const candidate = repos[item.repo]?.candidate;
    if (!candidate || !clones[item.repo] || !triage.bases?.[item.repo]) { verdict.reasons.push(`the re-import does not run ${item.repo} code`); continue; }
    if (!item.cells?.length) { verdict.reasons.push(`no acceptance cells for ${item.signature}`); continue; }
    const at = headAt(item);
    // A head no Cook moved since triage was checked there, on this capture and release.
    const prior = item.head_check?.pr === pr.url && item.head_check.head === pr.headRefOid ? item.head_check : null;
    const cells = (prior?.cells || cellsAtHead(item, pr, at)).map((cell) => ({ signature: item.signature, ...cell }));
    log(pr.url, item.signature, prior ? '(checked by triage)' : '', cells.map((c) => `${c.route}@${c.width}=${c.exit}`).join(' '));
    verdict.failing.push(...cells.filter((c) => c.exit === 'import_failed'));
    if (cells.some((c) => c.exit === 'import_failed')) continue;
    const collateral = prior?.collateral || collateralAtHead(item, pr, at);
    log(pr.url, item.signature, `collateral=${collateral.exit}`);
    if (collateral.exit === 1) verdict.failing.push({ signature: item.signature, collateral: collateral.report });
    else if (collateral.exit === 0) verdict.warnings.push(...collateralWarnings(collateral.report));
    else if (collateral.exit) verdict.reasons.push(`collateral comparison failed for ${item.signature}: ${collateral.report.at(-1)}`);
  }
}
const entries = [...verdicts];
const refuted = entries.filter(([, v]) => v.failing.length).map(([pr, v]) => ({ pr, head: v.head, cells: v.failing }));
const unconfirmed = entries.filter(([, v]) => !v.failing.length && v.reasons.length).map(([pr, v]) => ({ pr, head: v.head, reason: [...new Set(v.reasons)].join("; ") }));
const confirmed = entries.filter(([, v]) => !v.failing.length && !v.reasons.length).map(([pr, v]) => ({ pr, head: v.head, warnings: [...new Set(v.warnings)] }));
// A confirmed fix that ran another over-budget blocker further past its limit
// says so on its pull request before it lands: the warning is recorded, and the
// blocker stays tracked for the next pass.
for (const c of confirmed.filter((c) => c.warnings.length)) gh('pr', 'comment', c.pr, '--body', `Import loop confirmed this head \`${c.head.slice(0, 8)}\` as incremental progress on ${triage.url}: its own finding improves with no regression. It lands with these warnings, blockers that fail in the same places and run further past their limit; each stays tracked and the next pass measures it:\n\n${c.warnings.map((w) => `- \`${w}\``).join('\n')}\n\nAI disclosure: posted by the import loop (Franklin, Claude Opus via Pi).`);
for (const [pr, v] of entries.filter(([, v]) => v.draft && !v.failing.length && !v.reasons.length)) { gh('pr', 'ready', pr); v.draft = false; }
const merge = entries.filter(([, v]) => !v.failing.length && !v.reasons.length && !v.draft).map(([, v]) => ({ github: v.github, number: v.number, sha: v.head }));
const merged = [];
for (const pr of args.merge ? merge : []) {
  try {
    execFileSync('homeboy', ['git', 'pr', 'merge-when-green', '--repo', pr.github, '--number', String(pr.number), '--sha', pr.sha, '--wait-seconds', '3600'], { stdio: ['ignore', 'ignore', 'inherit'], timeout: 65 * 60 * 1000 });
    merged.push(`${pr.github}#${pr.number}`);
  } catch (error) {
    log(`not merged: ${pr.github}#${pr.number} (exit ${error.status})`);
  }
}

fs.mkdirSync(args.work, { recursive: true });
const confirm_path = path.join(args.work, 'confirm.json');
// --after is the next live import's evaluate result. Compare actual instance
// counts; a merged PR or a cleared reduced predicate is not live confirmation.
const live = args.after ? JSON.parse(fs.readFileSync(args.after, 'utf8')) : null;
const before = triage.findings_path ? JSON.parse(fs.readFileSync(triage.findings_path, 'utf8')) : null;
const live_checks = live && before ? triage.tracked.map((item) => ({ signature: item.signature, ...compareImports(before, live, item.signature, item.cells) })) : [];
const output = { confirmed, refuted, unconfirmed, live_checks, live_status: live ? 'checked' : 'awaiting_next_import', progress: live && before ? compareImports(before, live).blockers : triage.progress, confirmed_count: confirmed.length, refuted_count: refuted.length, merge, merge_count: merge.length, merged, confirm_path };
fs.writeFileSync(confirm_path, JSON.stringify(output, null, 2));
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
