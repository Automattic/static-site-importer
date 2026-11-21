#!/usr/bin/env node
// One scoreboard line per pass: what the pass found, what its Cooks did, what
// it merged, and how long it took. Appended to <runs>/<slug>-scoreboard.jsonl,
// summarized in <runs>/<slug>-status.md, and printed as one summary line. Never fails the pass: a missing input is
// recorded as null.
//
// Scoring ends the pass, so it also applies retention (retain.mjs): this slug's
// servers stop and its sites go, except this pass's own site.
//
// Usage: score.mjs --instance <id> --runs <dir> --slug <slug> --iteration <n>
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { args, gh, COOK_NOTE } from './lib.mjs';
import { excess, UNCHECKED } from './progress.mjs';
import { retain, sweep } from './retain.mjs';

process.on('uncaughtException', (error) => { console.error(`[score] skipped: ${error.message}`); process.exit(0); });

const pass = path.join(args.runs, `${args.slug}-p${args.iteration}`);
const read = (file) => { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return null; } };
const occurrences = (f) => f.occurrences.reduce((n, o) => n + (o.evidence?.count ?? 1), 0);
const blockers = (findings) => findings && Object.fromEntries(findings.findings.filter((f) => f.severity === 'blocker').map((f) => [f.signature, occurrences(f)]));

const board = path.join(args.runs, `${args.slug}-scoreboard.jsonl`);
const previous = fs.existsSync(board) ? fs.readFileSync(board, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)).findLast((l) => l.blockers) ?? null : null;
// A pass whose evaluate could not measure everything (evaluate.mjs fails the
// step) has no counts: its partial findings would read as blockers fixed.
const evaluated = read(`${pass}-eval/findings.json`);
const incomplete = evaluated ? [...new Set(evaluated.findings.filter((f) => UNCHECKED.has(f.check)).map((f) => f.check))] : [];
const now = incomplete.length ? null : blockers(evaluated);
const triage = read(`${pass}-triage/triage.json`);
// One confirm per Cook and one for the issues no Cook worked: each wrote its
// verdicts and merges under the pass's confirm directory.
const confirms = fs.existsSync(`${pass}-confirm`) ? fs.readdirSync(`${pass}-confirm`).map((dir) => read(`${pass}-confirm/${dir}/confirm.json`)).filter(Boolean) : [];
const confirm = confirms.length ? { confirmed: confirms.flatMap((c) => c.confirmed), refuted: confirms.flatMap((c) => c.refuted) } : null;

// Each Homeboy read is bounded so a slow diagnosis costs the scoreboard one
// field, never the step: a score that outlives its step timeout ends the chain.
const HOMEBOY_READ_MS = 90_000;
let run = null;
try { run = JSON.parse(execFileSync('homeboy', ['runs', 'show', args.instance], { encoding: 'utf8', maxBuffer: 256 << 20, timeout: HOMEBOY_READ_MS })).data; } catch { /* recorded as null */ }
// How each Cook ended, from Homeboy's settled outcome (`agent-task status`
// `terminal_outcome`): a published PR the gate did not refuse is the only
// success; a refused gate (a draft PR included) is gate_failed; no change or a
// declined form is the agent giving up; infrastructure is Homeboy's mechanics,
// the loop's bug count. A Cook superseded by an active Cook on the same issue
// is not a failure. Diagnose only explains a failure: the acceptance gate's
// verdict line, or Homeboy's root cause.
const homeboy = (...argv) => { try { return JSON.parse(execFileSync('homeboy', argv, { encoding: 'utf8', maxBuffer: 64 << 20, timeout: HOMEBOY_READ_MS })).data; } catch { return null; } };
const FAILURE = { gate_refused: 'gate_failed', no_change: 'gave_up', agent_declined: 'gave_up', infrastructure: 'mechanics' };
const classify = (outcome) => {
  if (!outcome?.kind) return 'unknown';
  if (outcome.kind === 'published') return outcome.refused_gate ? 'gate_failed' : null;
  if (outcome.kind === 'superseded_by_active_cook') return null;
  return FAILURE[outcome.kind] ?? 'unknown';
};
// A Cook that stopped without a pull request explains itself in the note
// Homeboy posts on the issue: its summary is the agent's own reason.
const noteSummary = (runId, issue) => {
  try {
    const note = JSON.parse(gh('issue', 'view', issue, '--json', 'comments')).comments.map((c) => c.body).findLast((body) => body.startsWith(`${COOK_NOTE}${runId} -->`));
    return note?.match(/\*\*Summary:\*\*\s*(.+)/)?.[1] ?? null;
  } catch { return null; }
};
const explain = (runId, outcome, issue) => {
  if (FAILURE[outcome?.kind] === 'gave_up') { const summary = issue && noteSummary(runId, issue); if (summary) return summary; }
  const data = homeboy('agent-task', 'diagnose', runId);
  const verdict = (data?.gate_failure_output || '').split('\n').filter((l) => l.startsWith('{"pass":')).map((l) => { try { return JSON.parse(l); } catch { return null; } }).at(-1);
  if (verdict) {
    const measured = `own blocker ${verdict.own?.before}->${verdict.own?.after}${verdict.own?.excess ? ` (excess ${verdict.own.excess.before}->${verdict.own.excess.after})` : ''}; added ${verdict.blockers_added?.join(' ') || 'none'}; ${verdict.visual_regressions?.length ?? 0} visual regressions`;
    return verdict.pass ? `acceptance passed (${measured})` : measured;
  }
  return data?.root_cause?.message ?? outcome?.error_code ?? outcome?.kind ?? null;
};
// Where a Cook's time went: its whole wall time, how many attempts it took
// (each failed gate costs one), and the final attempt's phases. A pass is
// mostly Cook, so this is the line that says whether a slow pass waited for
// the runner, for the agent, or for a retry.
// Top-level attempt phases; the rest are their sub-phases.
const COOK_PHASES = ['schedule', 'stage.dependency_hydration', 'provider', 'gate', 'gate.replay', 'terminal.decision', 'promote', 'publish.aggregate'];
const ms = (a, b) => (a && b ? Math.max(0, Date.parse(b) - Date.parse(a)) : null);
const timing = (outer) => {
  const final = outer?.cook?.run_id ? homeboy('runs', 'show', outer.cook.run_id)?.payload?.run : null;
  const phases = Object.fromEntries((final?.resources?.phases || []).filter((p) => COOK_PHASES.includes(p.phase)).map((p) => [p.phase, Math.round(p.wall_ms / 6000) / 10]));
  return {
    minutes: Math.round((ms(outer?.started_at, outer?.finished_at) ?? 0) / 6000) / 10 || null,
    attempts: Number(outer?.cook?.run_id?.match(/-attempt-(\d+)-/)?.[1]) || null,
    final_attempt_minutes: Math.round((ms(final?.started_at, final?.finished_at) ?? 0) / 6000) / 10 || null,
    phases,
  };
};
const cooks = (run?.steps?.cook?.items || []).map((item, n) => {
  const cook = { issue: triage?.cook_items?.[n]?.issue_url ?? null, signature: triage?.cook_items?.[n]?.signature ?? null, outcome: item.outcome ?? item.phase };
  if (!item.run_id) return cook;
  const terminal = homeboy('agent-task', 'status', item.run_id)?.terminal_outcome ?? null;
  const outer = homeboy('runs', 'show', item.run_id)?.payload?.run ?? null;
  const failure = classify(terminal);
  const settled = { ...cook, run: item.run_id, terminal: terminal?.kind ?? null, ...(terminal?.pr ? { pr: terminal.pr } : {}), timing: timing(outer) };
  if (!failure) return settled;
  // Homeboy's cook verdict names a mechanics failure directly (a GitHub
  // error, a lost runner); diagnose can quote the prompt instead.
  const reason = failure === 'mechanics' ? outer?.cook?.reason : null;
  return { ...settled, failure, cause: String(reason ?? explain(item.run_id, terminal, cook.issue) ?? item.error ?? '').split(/\n|\\n/)[0].slice(0, 240) || null };
});
const merged = [...new Set(confirms.flatMap((c) => c.merged || []))];
const started = run?.job?.created_at_ms ?? null;

const line = {
  at: new Date().toISOString(),
  instance: args.instance,
  iteration: Number(args.iteration),
  minutes: started ? Math.round((Date.now() - started) / 60000) : null,
  blocker_count: now ? Object.keys(now).length : null,
  solved: now ? Object.keys(now).length === 0 : null,
  occurrences: now ? Object.values(now).reduce((a, b) => a + b, 0) : null,
  blockers: now,
  // Measured blockers' value against their limit, so partial progress on
  // one (a share, a weight) shows between passes while its count stays 1.
  measures: now ? Object.fromEntries(evaluated.findings.filter((f) => f.severity === 'blocker' && f.occurrences.some((o) => o.evidence?.measure)).map((f) => [f.signature, f.occurrences.map((o) => o.evidence?.measure).filter(Boolean).map((m) => ({ value: m.value, limit: m.limit }))[0]])) : null,
  ...(incomplete.length ? { incomplete } : {}),
  // Against the previous pass on this board: what this pass's import fixed.
  removed: now && previous?.blockers ? Object.keys(previous.blockers).filter((s) => !(s in now)) : null,
  cooks,
  cook_outcomes: cooks.reduce((m, c) => { const k = c.terminal ?? c.outcome; return { ...m, [k]: (m[k] || 0) + 1 }; }, {}),
  cook_failures: cooks.filter((c) => c.failure).reduce((m, c) => ({ ...m, [c.failure]: (m[c.failure] || 0) + 1 }), {}),
  tools: execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
  confirmed: confirm?.confirmed?.map((c) => c.pr) ?? null,
  refuted: confirm?.refuted?.map((c) => c.pr) ?? null,
  merged,
  excepted: triage?.excepted?.map((c) => c.issue_url) ?? [],
  design: triage?.design?.map((c) => c.issue_url) ?? [],
};
fs.appendFileSync(board, JSON.stringify(line) + '\n');

// The site's current state in one page, rewritten every pass: what still
// blocks, where each blocker is tracked, and what its last Cook did.
const tracked = Object.fromEntries((triage?.tracked || []).map((t) => [t.signature, t]));
const lastCook = Object.fromEntries(cooks.filter((c) => c.signature).map((c) => [c.signature, c]));
const rows = Object.entries(now || {}).map(([signature, count]) => {
  const t = tracked[signature] || {};
  const c = lastCook[signature];
  const cook = t.excepted ? `out of scope: ${t.excepted.reason}` : !c ? 'not cooked this pass' : c.failure ? `${c.failure}: ${c.cause ?? c.outcome}` : c.pr ? `published ${c.pr}` : c.terminal ?? c.outcome;
  return `| ${t.check ?? signature} (${t.subject ?? ''}, ${count}) | ${t.issue_url ?? 'untracked'} | ${cook.replace(/\|/g, '/')} |`;
});
fs.writeFileSync(path.join(args.runs, `${args.slug}-status.md`), [
  `# ${args.slug}: pass ${line.iteration}, ${line.blocker_count ?? '?'} blockers`,
  '',
  `${triage?.url ?? ''} · ${line.at} · tools ${line.tools.slice(0, 12)} · ${line.minutes ?? '?'} min this pass · merged ${merged.length ? merged.join(' ') : 'none'}`,
  '',
  line.blocker_count === 0 ? 'Solved: no blockers remain.' : triage?.continue === false && triage.excepted?.length ? `Unsolved, stopped: every remaining blocker is excepted (${triage.excepted.map((e) => e.issue_url).join(' ')}). Not a solved site; it needs guidance on those issues.\n\n| Blocker | Tracked at | Last Cook |\n|---|---|---|\n${rows.join('\n')}` : '| Blocker | Tracked at | Last Cook |\n|---|---|---|\n' + rows.join('\n'),
  '',
].join('\n'));
const delta = previous?.occurrences != null && line.occurrences != null ? ` (${line.occurrences - previous.occurrences >= 0 ? '+' : ''}${line.occurrences - previous.occurrences})` : '';
console.error(`[score] ${args.slug} pass ${line.iteration}: ${line.blocker_count} blockers, ${line.occurrences} occurrences${delta}; cooks ${JSON.stringify(line.cook_outcomes)}, failures ${JSON.stringify(line.cook_failures)}; merged ${merged.length ? merged.join(' ') : 'none'}; ${line.minutes} min${cooks.some((c) => c.timing) ? `; cook ${cooks.filter((c) => c.timing).map((c) => `${c.timing.minutes ?? '?'}m/${c.timing.attempts ?? '?'} attempts`).join(', ')}` : ''}`);
// What the chain's stop_unchanged compares: this pass's blockers (count and
// excess past their limits) and what it merged. A pass that merges a fix is
// progress the next pass's evaluation has yet to see.
const findings = read(`${pass}-eval/findings.json`);
const progress = findings ? [...findings.findings.filter((f) => f.severity === 'blocker').map((f) => `${f.signature}:${occurrences(f)}:${excess(f)}`).sort(), ...merged.map((m) => `merged:${m}`)] : null;
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify({ blocker_count: line.blocker_count, occurrences: line.occurrences, merged: merged.length, minutes: line.minutes, board, progress }));
const removed = retain(args.runs, args.slug, args.iteration);
const swept = sweep(args.runs).filter((s) => s.removed);
console.error(`[score] retention: ${removed} sites removed for ${args.slug}${swept.length ? `; swept ${swept.map((s) => `${s.slug}:${s.removed}`).join(' ')}` : ''}`);
