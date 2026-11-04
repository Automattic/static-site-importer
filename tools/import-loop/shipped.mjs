#!/usr/bin/env node
// Loop step: wait until every merged fix is in what `studio create --from`
// installs, which is the latest release of each runtime repository. A
// Blocks Engine fix ships when the latest Static Site Importer release locks a
// transformer commit that contains it.
//
// Usage: node shipped.mjs --merged '<json array from merge.mjs>' [--poll-seconds 300]
// Output: {shipped:[{pr, release}]}. Exits non-zero only on timeout (the step's).

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const here = path.dirname(new URL(import.meta.url).pathname);
const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[index + 1]]] : pairs), []));
const repos = JSON.parse(fs.readFileSync(path.join(here, 'repos.json'), 'utf8'));
const merged = JSON.parse(args.merged || '[]');
const poll = Number(args['poll-seconds'] || 300) * 1000;
const gh = (...argv) => execFileSync('gh', argv, { encoding: 'utf8', maxBuffer: 64 << 20 });

const contains = (github, ref, sha) => {
  try { return ['ahead', 'identical'].includes(JSON.parse(gh('api', `repos/${github}/compare/${sha}...${ref}`)).status); } catch { return false; }
};
const latestTag = (github) => JSON.parse(gh('api', `repos/${github}/releases/latest`)).tag_name;

function shippedIn(fix) {
  const repo = repos[fix.repo];
  if (!repo?.shipped_via) {
    const tag = latestTag(fix.github);
    return contains(fix.github, tag, fix.sha) ? `${fix.github}@${tag}` : null;
  }
  const carrier = repos[repo.shipped_via].github;
  const tag = latestTag(carrier);
  const lock = JSON.parse(Buffer.from(JSON.parse(gh('api', `repos/${carrier}/contents/composer.lock?ref=${tag}`)).content, 'base64').toString('utf8'));
  const pkg = lock.packages.find((p) => p.name === repo.lock_package);
  // The package is published from a split repository, so its locked commit is
  // not in the monorepo; the monorepo's release tag for that version is.
  const ref = pkg && `${repo.release_tag_prefix}${pkg.version}`;
  return ref && contains(fix.github, ref, fix.sha) ? `${carrier}@${tag} (${repo.lock_package} ${pkg.version})` : null;
}

const pending = new Map(merged.map((m) => [m.pr, m]));
const shipped = [];
while (pending.size) {
  for (const [pr, fix] of pending) {
    // A failed lookup (network, rate limit) is "not shipped yet": the step's
    // timeout, not one transient error, bounds the wait.
    let release = null;
    try { release = shippedIn(fix); } catch (error) { console.error(`[shipped] ${pr}: lookup failed, retrying: ${String(error.stderr || error.message).trim()}`); }
    if (release) { shipped.push({ pr, release }); pending.delete(pr); console.error(`[shipped] ${pr} in ${release}`); }
  }
  if (pending.size) { console.error(`[shipped] waiting on ${[...pending.keys()].join(', ')}`); await new Promise((r) => setTimeout(r, poll)); }
}
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify({ shipped }));
