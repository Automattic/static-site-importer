#!/usr/bin/env node
// One scoreboard line per pass: what the pass found, what its Cooks did, what
// it merged, and how long it took. Appended to <runs>/<slug>-scoreboard.jsonl
// and printed as one summary line. Never fails the pass: a missing input is
// recorded as null.
//
// Usage: score.mjs --instance <id> --runs <dir> --slug <slug> --iteration <n>
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { args } from './lib.mjs';

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
const cooks = (run?.steps?.cook?.items || []).map((item, n) => ({ issue: triage?.cook_items?.[n]?.issue_url ?? null, outcome: item.outcome ?? item.phase }));
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
  confirmed: confirm?.confirmed?.map((c) => c.pr) ?? null,
  refuted: confirm?.refuted?.map((c) => c.pr) ?? null,
  merged,
};
fs.appendFileSync(board, JSON.stringify(line) + '\n');
const delta = previous?.occurrences != null && line.occurrences != null ? ` (${line.occurrences - previous.occurrences >= 0 ? '+' : ''}${line.occurrences - previous.occurrences})` : '';
console.error(`[score] ${args.slug} pass ${line.iteration}: ${line.blocker_count} blockers, ${line.occurrences} occurrences${delta}; cooks ${JSON.stringify(line.cook_outcomes)}; merged ${merged.length ? merged.join(' ') : 'none'}; ${line.minutes} min`);
console.log(JSON.stringify({ blocker_count: line.blocker_count, occurrences: line.occurrences, merged: merged.length, minutes: line.minutes, board }));
