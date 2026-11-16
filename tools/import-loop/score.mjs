#!/usr/bin/env node
// One scoreboard line per pass: what the pass found, what its Cooks did, what
// it merged, and how long it took. Appended to <runs>/<slug>-scoreboard.jsonl,
// summarized in <runs>/<slug>-status.md, and printed as one summary line. Never fails the pass: a missing input is
// recorded as null.
//
// Usage: score.mjs --instance <id> --runs <dir> --slug <slug> --iteration <n>
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { args } from './lib.mjs';
import { excess } from './progress.mjs';

process.on('uncaughtException', (error) => { console.error(`[score] skipped: ${error.message}`); process.exit(0); });

const pass = path.join(args.runs, `${args.slug}-p${args.iteration}`);
const read = (file) => { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return null; } };
const occurrences = (f) => f.occurrences.reduce((n, o) => n + (o.evidence?.count ?? 1), 0);
const blockers = (findings) => findings && Object.fromEntries(findings.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, occurrences(f)]));

const board = path.join(args.runs, `${args.slug}-scoreboard.jsonl`);
const previous = fs.existsSync(board) ? fs.readFileSync(board, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)).at(-1) : null;
const now = blockers(read(`${pass}-eval/findings.json`));
const triage = read(`${pass}-triage/triage.json`);
const confirm = read(`${pass}-confirm/confirm.json`);

let run = null;
try { run = JSON.parse(execFileSync('homeboy', ['runs', 'show', args.instance], { encoding: 'utf8', maxBuffer: 256 << 20 })).data; } catch { /* recorded as null */ }
// Why a Cook did not succeed, from Homeboy's own diagnosis: the agent gave up
// (`agent.*`), the acceptance gate refused its candidate, or Homeboy's
// mechanics failed (anything else). Mechanics failures are the loop's bug count.
// A refused candidate is classified and summarized from the acceptance gate's
// verdict line.
const diagnose = (runId) => {
  let data;
  try { data = JSON.parse(execFileSync('homeboy', ['agent-task', 'diagnose', runId], { encoding: 'utf8', maxBuffer: 64 << 20 })).data; } catch { return null; }
  const verdict = (data?.gate_failure_output || '').split('\n').filter((l) => l.startsWith('{"pass":')).map((l) => { try { return JSON.parse(l); } catch { return null; } }).at(-1);
  if (!verdict || !data.root_cause) return data?.root_cause ?? null;
  const measured = `own blocker ${verdict.own?.before}->${verdict.own?.after}${verdict.own?.excess ? ` (excess ${verdict.own.excess.before}->${verdict.own.excess.after})` : ''}; added ${verdict.blockers_added?.join(' ') || 'none'}; ${verdict.visual_regressions?.length ?? 0} visual regressions`;
  // Acceptance passed, so the repository's own checks refused the candidate.
  const message = verdict.pass ? `acceptance passed (${measured}); repository checks failed` : measured;
  // A verdict means the gate ran and refused the candidate, whichever process
  // stream Homeboy's diagnosis ranks first.
  return { ...data.root_cause, class: 'agent_task.promotion_gate_failed', message };
};
const failure = (cause) => !cause?.class ? 'unknown' : cause.class.startsWith('agent.') ? 'gave_up' : cause.class === 'agent_task.promotion_gate_failed' ? 'gate_failed' : 'mechanics';
const cooks = (run?.steps?.cook?.items || []).map((item, n) => {
  const cook = { issue: triage?.cook_items?.[n]?.issue_url ?? null, signature: triage?.cook_items?.[n]?.signature ?? null, outcome: item.outcome ?? item.phase };
  if (cook.outcome === 'succeeded' || !item.run_id) return cook;
  const cause = diagnose(item.run_id);
  return { ...cook, run: item.run_id, failure: failure(cause), cause: cause?.message?.split('\n')[0].slice(0, 240) ?? item.error ?? null };
});
const merged = (run?.steps?.merge?.items || []).filter((item) => item.outcome === 'succeeded').map((item) => `${item.item?.github}#${item.item?.number}`);
const started = run?.job?.created_at_ms ?? null;

const line = {
  at: new Date().toISOString(),
  instance: args.instance,
  iteration: Number(args.iteration),
  minutes: started ? Math.round((Date.now() - started) / 60000) : null,
  blocker_count: now ? Object.keys(now).length : null,
  occurrences: now ? Object.values(now).reduce((a, b) => a + b, 0) : null,
  blockers: now,
  // Against the previous pass on this board: what this pass's import fixed.
  removed: now && previous?.blockers ? Object.keys(previous.blockers).filter((s) => !(s in now)) : null,
  cooks,
  cook_outcomes: cooks.reduce((m, c) => ({ ...m, [c.outcome]: (m[c.outcome] || 0) + 1 }), {}),
  cook_failures: cooks.filter((c) => c.failure).reduce((m, c) => ({ ...m, [c.failure]: (m[c.failure] || 0) + 1 }), {}),
  tools: execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
  confirmed: confirm?.confirmed?.map((c) => c.pr) ?? null,
  refuted: confirm?.refuted?.map((c) => c.pr) ?? null,
  merged,
  capped: triage?.capped?.map((c) => c.issue_url) ?? [],
};
fs.appendFileSync(board, JSON.stringify(line) + '\n');

// The site's current state in one page, rewritten every pass: what still
// blocks, where each blocker is tracked, and what its last Cook did.
const tracked = Object.fromEntries((triage?.tracked || []).map((t) => [t.signature, t]));
const lastCook = Object.fromEntries(cooks.filter((c) => c.signature).map((c) => [c.signature, c]));
const rows = Object.entries(now || {}).map(([signature, count]) => {
  const t = tracked[signature] || {};
  const c = lastCook[signature];
  const cook = t.capped ? `needs design: ${t.capped.failed_cooks} failed Cooks since guidance` : !c ? 'not cooked this pass' : c.failure ? `${c.failure}: ${c.cause ?? c.outcome}` : c.outcome;
  return `| ${t.check ?? signature} (${t.subject ?? ''}, ${count}) | ${t.issue_url ?? 'untracked'} | ${cook.replace(/\|/g, '/')} |`;
});
fs.writeFileSync(path.join(args.runs, `${args.slug}-status.md`), [
  `# ${args.slug}: pass ${line.iteration}, ${line.blocker_count ?? '?'} blockers`,
  '',
  `${triage?.url ?? ''} · ${line.at} · tools ${line.tools.slice(0, 12)} · ${line.minutes ?? '?'} min this pass · merged ${merged.length ? merged.join(' ') : 'none'}`,
  '',
  line.blocker_count === 0 ? 'Solved: no blockers remain.' : '| Blocker | Tracked at | Last Cook |\n|---|---|---|\n' + rows.join('\n'),
  '',
].join('\n'));
const delta = previous?.occurrences != null && line.occurrences != null ? ` (${line.occurrences - previous.occurrences >= 0 ? '+' : ''}${line.occurrences - previous.occurrences})` : '';
console.error(`[score] ${args.slug} pass ${line.iteration}: ${line.blocker_count} blockers, ${line.occurrences} occurrences${delta}; cooks ${JSON.stringify(line.cook_outcomes)}, failures ${JSON.stringify(line.cook_failures)}; merged ${merged.length ? merged.join(' ') : 'none'}; ${line.minutes} min`);
// What the chain's stop_unchanged compares: this pass's blockers (count and
// excess past their limits) and what it merged. A pass that merges a fix is
// progress the next pass's evaluation has yet to see.
const findings = read(`${pass}-eval/findings.json`);
const progress = findings ? [...findings.findings.filter((f) => f.severity === 'blocker').map((f) => `${f.signature}:${occurrences(f)}:${excess(f)}`).sort(), ...merged.map((m) => `merged:${m}`)] : null;
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify({ blocker_count: line.blocker_count, occurrences: line.occurrences, merged: merged.length, minutes: line.minutes, board, progress }));
