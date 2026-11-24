#!/usr/bin/env node
// Frozen-corpus check for every open pull request, outside the import loop.
//
// The loop's confirm step refuses its own fixes that regress another corpus
// site (corpus-check.mjs). A pull request opened by anyone else merges without
// that check, so this script gives each open transformer or importer pull
// request's head the same replay and posts the verdict as the commit status
// `import-loop/corpus`. Run it on a cadence (corpus-pr.json) on the machine
// that holds the corpus; each head is measured once.
//
// Usage: node corpus-pr.mjs --corpus <dir> --runs <dir> [--sites a,b] [--max N] [--dry-run]
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { corpusCheck } from './corpus-check.mjs';
import { metricSummary } from './corpus-metrics.mjs';
import { mergedHead, repoClone } from './lib.mjs';

export const CONTEXT = 'import-loop/corpus';
const here = path.dirname(fileURLToPath(import.meta.url));

// The status a replay verdict posts: GitHub caps descriptions at 140 characters.
// Structure and weight that moved against the trunk baseline follow the cell verdict.
export function corpusStatus({ failing, reasons, structure = [] }, sites) {
  const regressed = failing.flatMap((f) => f.corpus || []);
  const moved = structure.length ? `; ${metricSummary(structure)}` : '';
  if (regressed.length) return { state: 'failure', description: `worse on ${regressed.map((r) => `${r.site} (${r.cells.length})`).join(', ')}${moved}`.slice(0, 140) };
  if (reasons.length) return { state: 'error', description: reasons[0].slice(0, 140) };
  return { state: 'success', description: `no regression on ${sites.length} corpus sites${moved}`.slice(0, 140) };
}

// Whether a pull request's changed files reach the candidate the corpus imports with.
export const touchesCandidate = (files, paths) => (files || []).some(({ path: file }) => paths.some((p) => file === p || file.startsWith(`${p}/`)));

const gh = (...a) => execFileSync('gh', a, { encoding: 'utf8', maxBuffer: 64 << 20 });
const log = (...a) => console.error(`[corpus-pr] ${a.join(' ')}`);

// Blocks Engine trunk tip, the latest importer release and a bare WordPress,
// as both corpus cadences (this one and corpus-trunk.mjs) measure them.
export function trunkSetup(runsDir, workName = 'corpus-pr') {
  const repos = JSON.parse(fs.readFileSync(path.join(here, 'repos.json'), 'utf8'));
  const runs = path.resolve(runsDir), work = path.join(runs, workName);
  fs.mkdirSync(work, { recursive: true });
  // Clones are fetched once per run; trunk is the transformer every importer head runs on.
  const clone = (name) => repoClone(repos[name].github, runs);
  const tip = (name) => { execFileSync('git', ['-C', clone(name), 'fetch', '-q', 'origin', repos[name].branch]); return execFileSync('git', ['-C', clone(name), 'rev-parse', 'FETCH_HEAD'], { encoding: 'utf8' }).trim(); };
  const be = repos['blocks-engine'], trunk = tip('blocks-engine');
  const trunkDir = path.join(work, `trunk-${trunk.slice(0, 12)}`);
  if (!fs.existsSync(trunkDir)) execFileSync('bash', [path.join(here, 'gates', 'merge-onto.sh'), clone('blocks-engine'), trunk, trunk, trunkDir, ...be.candidate.paths]);
  const zip = path.join(work, 'static-site-importer.zip');
  const release = execFileSync('bash', [path.join(here, 'ssi-release.sh'), zip], { encoding: 'utf8', env: { ...process.env, SSI_ZIP: '' } }).trim().split('\n').pop();
  const bare = process.env.BARE_WP ? path.resolve(process.env.BARE_WP) : path.join(work, 'bare-wp');
  execFileSync('bash', [path.join(here, 'gates', 'bare-wp.sh'), bare], { stdio: ['ignore', 'ignore', 'inherit'] });
  const triage = { trunk, bases: { 'static-site-importer': release }, transformer: path.join(trunkDir, be.candidate.paths[0]), reimport: { ssi_zip: zip, bare_wp: bare } };
  return { repos, work, clone, tip, trunk, release, triage };
}

function main() {
  const { values: args } = parseArgs({ options: { corpus: { type: 'string' }, runs: { type: 'string' }, sites: { type: 'string' }, max: { type: 'string' }, 'dry-run': { type: 'boolean' } } });
  if (!args.corpus || !args.runs) throw new Error('usage: corpus-pr.mjs --corpus <dir> --runs <dir> [--sites a,b] [--max N] [--dry-run]');
  const { repos, work, clone, tip, trunk, release, triage } = trunkSetup(args.runs);

  // Heads not yet measured, most recently updated first, at most --max per run.
  const pending = [];
  for (const [name, repo] of Object.entries(repos)) {
    if (!['transformer', 'importer'].includes(repo.candidate.slot)) continue;
    for (const pr of JSON.parse(gh('pr', 'list', '-R', repo.github, '--state', 'open', '--base', repo.branch, '--limit', '200', '--json', 'number,headRefOid,url,updatedAt,files'))) {
      const statuses = JSON.parse(gh('api', `repos/${repo.github}/commits/${pr.headRefOid}/statuses?per_page=100`));
      if (statuses.some((s) => s.context === CONTEXT && s.state !== 'pending')) continue;
      // A head that changes none of the candidate's paths imports exactly as
      // its base does: a replay measures only noise, so it passes unreplayed.
      if (!touchesCandidate(pr.files, repo.candidate.paths)) {
        log(pr.url, 'changes no candidate path; not replayed');
        if (!args['dry-run']) gh('api', `repos/${repo.github}/statuses/${pr.headRefOid}`, '-f', 'state=success', '-f', `context=${CONTEXT}`, '-f', `description=changes no ${repo.candidate.slot} path; nothing to replay`);
        continue;
      }
      pending.push({ name, repo, pr });
    }
  }
  pending.sort((a, b) => b.pr.updatedAt.localeCompare(a.pr.updatedAt));
  const max = Number(args.max || 4);
  log(`${pending.length} unmeasured heads; measuring up to ${max} at trunk ${trunk.slice(0, 8)} and ${release}`);
  const bases = { 'static-site-importer': tip('static-site-importer') };
  const heads = [];
  for (const { name, repo, pr } of pending) {
    if (heads.length >= max) break;
    const base = name === 'blocks-engine' ? trunk : bases[name];
    const dir = mergedHead({ repo: name }, pr, { clone: clone(name), candidate: repo.candidate, base, work });
    // A head that conflicts with its base cannot ship as is; GitHub reports that conflict itself.
    if (!fs.existsSync(dir)) { log(pr.url, 'conflicts with its base; skipped'); continue; }
    const verdict = { failing: [], reasons: [] };
    heads.push({ verdict, key: pr.url, pr, repo, dir, ...(repo.candidate.slot === 'transformer' ? { transformer: path.join(dir, repo.candidate.paths[0]) } : { transformer: triage.transformer, importer: dir }) });
    if (!args['dry-run']) gh('api', `repos/${repo.github}/statuses/${pr.headRefOid}`, '-f', 'state=pending', '-f', `context=${CONTEXT}`, '-f', 'description=replaying the frozen capture corpus');
  }
  const { summary } = corpusCheck({ corpus: args.corpus, sites: args.sites, triage, here, log: (...a) => log(...a), heads });
  for (const head of heads) {
    const status = corpusStatus(head.verdict, summary.sites || []);
    log(head.key, status.state, status.description);
    if (!args['dry-run']) gh('api', `repos/${head.repo.github}/statuses/${head.pr.headRefOid}`, '-f', `state=${status.state}`, '-f', `context=${CONTEXT}`, '-f', `description=${status.description}`);
    fs.rmSync(head.dir, { recursive: true, force: true });
  }
  console.log(JSON.stringify({ trunk, release, pending: pending.length, measured: heads.map((h) => ({ pr: h.key, ...corpusStatus(h.verdict, summary.sites || []), structure: h.verdict.structure || [] })), corpus: summary }));
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
