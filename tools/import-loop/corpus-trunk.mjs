#!/usr/bin/env node
// Daily trunk scoreboard for the frozen corpus (corpus-trunk.json).
//
// Replays every corpus site (sites.json) at Blocks Engine trunk tip with the
// latest importer release, twice for the noise floor (corpus.mjs --repeat 2),
// and appends one row per site to the append-only `<corpus>/.trend.jsonl`:
// date, trunk, release, passing and verified cells, flaky cells, and the
// structure and weight metrics. It prints today against each site's previous
// row, the step output the cadence's notification carries.
//
// Usage: node corpus-trunk.mjs --corpus <dir> --runs <dir> [--sites a,b] [--jobs N] [--repeat N] [--trend <file>]
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { appendTrend, deltaTable, latestRows, readTrend, trendRow } from './corpus-metrics.mjs';
import { trunkSetup } from './corpus-pr.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));

// The rows one run appends, and the table against the ledger before them.
export function scoreboard(trend, replay, { date, trunk, release, sites }) {
  const bySite = new Map((replay.sites || []).map((s) => [s.site, s]));
  // A site the replay never reached still gets its row, with its reason.
  const rows = sites.map((site) => trendRow({ date, trunk, release }, bySite.get(site) || { site, unmeasured: replay.unmeasured?.length ? replay.unmeasured : ['not replayed'] }));
  return { rows, table: deltaTable(latestRows(trend), rows) };
}

function main() {
  const { values: args } = parseArgs({ options: Object.fromEntries(['corpus', 'runs', 'sites', 'jobs', 'repeat', 'trend'].map((k) => [k, { type: 'string' }])) });
  if (!args.corpus || !args.runs) throw new Error('usage: corpus-trunk.mjs --corpus <dir> --runs <dir> [--sites a,b] [--jobs N] [--repeat N] [--trend <file>]');
  const corpus = path.resolve(args.corpus);
  const known = JSON.parse(fs.readFileSync(path.join(here, 'sites.json'), 'utf8'));
  const sites = args.sites ? args.sites.split(',') : Object.keys(known).filter((s) => s !== '_comment');
  const { trunk, release, triage } = trunkSetup(args.runs, 'corpus-trunk');
  const run = spawnSync('node', [path.join(here, 'corpus.mjs'), 'measure', '--corpus', corpus, '--blocks-engine', triage.transformer, '--sites', sites.join(','), '--repeat', args.repeat || '2', ...(args.jobs ? ['--jobs', args.jobs] : [])], {
    env: { ...process.env, SSI_ZIP: triage.reimport.ssi_zip, BARE_WP: triage.reimport.bare_wp }, encoding: 'utf8', maxBuffer: 256 << 20,
  });
  let replay;
  try { replay = JSON.parse(String(run.stdout).trim().split('\n').at(-1)); } catch { replay = { sites: [], unmeasured: [`corpus.mjs exit ${run.status}: ${String(run.stderr || run.stdout).trim().slice(-300)}`] }; }
  const trendFile = args.trend ? path.resolve(args.trend) : path.join(corpus, '.trend.jsonl');
  const { rows, table } = scoreboard(readTrend(trendFile), replay, { date: new Date().toISOString().slice(0, 10), trunk, release, sites });
  appendTrend(trendFile, rows);
  console.log(table);
  const unmeasured = rows.filter((r) => r.unmeasured.length);
  for (const r of unmeasured) console.log(`${r.site}: ${r.unmeasured.join('; ').slice(0, 300)}`);
  // A site with no measurement at all fails the run; partial cells are recorded as they are.
  process.exitCode = rows.some((r) => !r.verified) ? 1 : 0;
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
