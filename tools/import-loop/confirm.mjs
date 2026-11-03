#!/usr/bin/env node
// Loop step, before the merge approval: re-check every open fix on its head.
//
// A tracked finding lists acceptance cells, one per route/width it was seen
// in, each on the full captured page. A fix is confirmed when its finding's
// predicate exits 1 (the defect is gone) on every cell, and refuted when any
// cell still exits 0. Fixes in a repository whose code the predicates do not
// run (`predicate_root` in repos.json) are unconfirmed: the next pass's
// import is their check.
//
// Usage: node confirm.mjs --triage <triage.json> --blocks-engine <clone> --work <dir>
// Output: {confirmed:[{pr, head}], refuted:[{pr, head, cells}], unconfirmed:[{pr, head, reason}], confirm_path}

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { args, linkedPullRequests, predicateEnv, predicateExit } from './lib.mjs';

const here = path.dirname(new URL(import.meta.url).pathname);
for (const required of ['triage', 'blocks-engine', 'work']) if (!args[required]) throw new Error(`confirm_argument_missing: --${required}`);
const repos = JSON.parse(fs.readFileSync(path.join(here, 'repos.json'), 'utf8'));
const triage = JSON.parse(fs.readFileSync(args.triage, 'utf8'));
const clones = { 'blocks-engine': args['blocks-engine'] };
const run = (command, cwd) => execFileSync(command[0], command.slice(1), { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim();
const log = (...parts) => console.error('[confirm]', ...parts);

// A worktree of the clone at the pull request's head, with its dependencies.
function checkout(repo, pr) {
  const dir = path.join(args.work, 'heads', `${repo}-${pr.number}-${pr.headRefOid.slice(0, 8)}`);
  const root = path.join(dir, repos[repo].predicate_root);
  if (!fs.existsSync(dir)) {
    run(['git', '-C', clones[repo], 'fetch', '-q', 'origin', `pull/${pr.number}/head`]);
    run(['git', '-C', clones[repo], 'worktree', 'add', '-q', '--detach', dir, pr.headRefOid]);
    run(['composer', 'install', '--no-interaction', '--no-progress', '--quiet'], root);
  }
  return root;
}

// One pull request can fix several tracked findings: it is confirmed only
// when every cell of every finding it fixes clears.
const verdicts = new Map();
for (const item of triage.tracked) {
  for (const pr of linkedPullRequests(item.issue_url)) {
    const verdict = verdicts.get(pr.url) || { head: pr.headRefOid, failing: [], reasons: [] };
    verdicts.set(pr.url, verdict);
    const root = repos[item.repo]?.predicate_root;
    if (!root || !clones[item.repo]) { verdict.reasons.push(`predicates do not run ${item.repo} code`); continue; }
    if (!item.cells?.length) { verdict.reasons.push(`no acceptance cells for ${item.signature}`); continue; }
    const predicate = path.join(here, 'predicates', `${item.check}.mjs`);
    const head = checkout(item.repo, pr);
    const cells = item.cells.map((cell) => ({ signature: item.signature, route: cell.route, width: cell.width, exit: predicateExit(predicate, head, cell.page, predicateEnv(item.subject, cell, triage.site)) }));
    log(pr.url, item.signature, cells.map((c) => `${c.route}@${c.width}=${c.exit}`).join(' '));
    verdict.failing.push(...cells.filter((c) => c.exit !== 1));
  }
}
const entries = [...verdicts];
const refuted = entries.filter(([, v]) => v.failing.length).map(([pr, v]) => ({ pr, head: v.head, cells: v.failing }));
const unconfirmed = entries.filter(([, v]) => !v.failing.length && v.reasons.length).map(([pr, v]) => ({ pr, head: v.head, reason: v.reasons.join('; ') }));
const confirmed = entries.filter(([, v]) => !v.failing.length && !v.reasons.length).map(([pr, v]) => ({ pr, head: v.head }));

fs.mkdirSync(args.work, { recursive: true });
const confirm_path = path.join(args.work, 'confirm.json');
const output = { confirmed, refuted, unconfirmed, confirmed_count: confirmed.length, refuted_count: refuted.length, confirm_path };
fs.writeFileSync(confirm_path, JSON.stringify(output, null, 2));
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
