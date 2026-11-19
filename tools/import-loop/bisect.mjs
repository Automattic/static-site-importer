#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { args } from './lib.mjs';
import { occurrences } from './progress.mjs';
import { bisectSearch, bisectSkip } from './bisect-search.mjs';

const read = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));
const git = (...cmd) => execFileSync('git', ['-C', args['blocks-engine'], ...cmd], { encoding: 'utf8' }).trim();
const work = path.resolve(args.work);
fs.mkdirSync(work, { recursive: true });
const output = path.join(work, 'bisect.json');
const write = (value) => { fs.writeFileSync(`${output}.tmp`, JSON.stringify(value, null, 2)); fs.renameSync(`${output}.tmp`, output); return value; };
const current = read(args.findings);
const runs = path.dirname(work);
const previousPath = args.previous || path.join(runs, `${args.slug}-p${Number(args.iteration) - 1}-triage`, 'triage.json');
const previousFindings = args['previous-findings'] || path.join(runs, `${args.slug}-p${Number(args.iteration) - 1}-eval`, 'findings.json');
const previous = fs.existsSync(previousPath) ? read(previousPath) : null;
const prev = args.prev || previous?.bases?.['blocks-engine'];
const cur = args.cur;
const skip = bisectSkip(current.progress?.added, prev, cur);
if (skip) {
  write({ status: 'skipped', reason: skip, prev, cur });
} else {
  const cacheKey = createHash('sha256').update(JSON.stringify([current.url, prev, cur])).digest('hex');
  const cache = path.join(path.dirname(work), `bisect-cache-${cacheKey}.json`);
  if (fs.existsSync(cache)) write(read(cache));
  else {
    const probes = [];
    let signatures = [];
    let narrowed = { prev, cur };
    const interrupted = () => {
      const value = write({ status: 'inconclusive', reason: 'bisect step timeout', prev, cur, probes, signatures, range: narrowed });
      fs.writeFileSync(cache, JSON.stringify(value, null, 2));
      process.exit(0);
    };
    const deadline = setTimeout(interrupted, 170 * 60_000);
    process.once('SIGTERM', interrupted);
    try {
      const before = read(previousFindings);
      signatures = current.progress.added.map(({ signature }) => {
        const finding = current.findings.find((f) => f.signature === signature);
        const cells = finding.occurrences.map(({ route, width }) => ({ route, width }));
        return { signature, cells, before: occurrences(before.findings.find((f) => f.signature === signature), cells), after: occurrences(finding, cells) };
      });
      if (git('merge-base', prev, cur) !== git('rev-parse', prev)) throw new Error('previous trunk is not an ancestor');
      const repos = read(new URL('./repos.json', import.meta.url));
      const repo = repos['blocks-engine'].github;
      const commits = git('log', '--first-parent', '--reverse', '--format=%H%x09%s', `${prev}..${cur}`).split('\n').filter(Boolean).filter((line) => !/\t(?:chore(?:\(release\))?:? release|release(?:\(|:| )|chore\(release\))/i.test(line)).map((line) => line.split('\t')[0]);
      // Workers use separate trees, imports and servers; never exceed the same
      // CPU-derived width used by checkHeads, nor two simultaneous probes.
      const width = Math.min(2, Math.max(1, Math.floor(os.availableParallelism() / 4)));
      let active = 0;
      const waiting = [];
      const probe = async (commit) => {
        if (active >= width) await new Promise((resolve) => waiting.push(resolve));
        active++;
        try {
          const dir = path.join(work, commit);
          fs.mkdirSync(dir, { recursive: true });
          const config = path.join(dir, 'probe.json');
          fs.writeFileSync(config, JSON.stringify({ commit, dir, clone: path.resolve(args['blocks-engine']), paths: repos['blocks-engine'].candidate.paths, reimport: { capture: fs.realpathSync(path.join(args.source, new URL(current.url).hostname)), ssi_zip: path.resolve(args['ssi-zip']), bare_wp: path.join(path.dirname(work), 'bare-wp') }, source: path.resolve(args.source), url: current.url, signatures }));
          await new Promise((resolve, reject) => {
            const log = fs.openSync(path.join(dir, 'probe.log'), 'w');
            const child = spawn(process.execPath, [new URL('./bisect-probe.mjs', import.meta.url).pathname, '--config', config], { stdio: ['ignore', log, log], timeout: 45 * 60_000 });
            fs.closeSync(log);
            const stop = () => child.kill('SIGTERM');
            process.once('exit', stop);
            child.on('error', (error) => { process.removeListener('exit', stop); reject(error); });
            child.on('exit', (code, signal) => {
              process.removeListener('exit', stop);
              code === 0 ? resolve() : reject(new Error(`probe ${commit}: ${signal || code}`));
            });
          });
          const counts = read(path.join(dir, 'counts.json'));
          probes.push({ commit, counts });
          return counts;
        } finally { active--; waiting.shift()?.(); }
      };
      // Reproduce before searching: probe both ends of the range on this
      // capture, and keep only signatures that grow from one to the other. A
      // count that moves between passes but not between the two ends is
      // measurement noise, and ends the step after one round of probes.
      const memo = new Map();
      const once = (commit) => { if (!memo.has(commit)) memo.set(commit, probe(commit)); return memo.get(commit); };
      if (!commits.length) throw new Error('no non-release commits');
      const [base, head] = await Promise.all([once(prev), once(commits.at(-1))]);
      signatures = signatures.filter((s) => head[s.signature] > base[s.signature]).map((s) => ({ ...s, before: base[s.signature] }));
      if (!signatures.length) {
        const value = write({ status: 'not_reproduced', reason: 'no added signature grows between the range ends on this capture', prev, cur, probes, signatures });
        fs.writeFileSync(cache, JSON.stringify(value, null, 2));
        console.log(JSON.stringify({ bisect_path: output, status: value.status }));
        process.exit(0);
      }
      const result = await bisectSearch(commits, prev, once, (counts) => signatures.some((s) => counts[s.signature] > s.before), base, (range) => { narrowed = range; });
      let culprit;
      if (result.status === 'complete') {
        const subject = git('show', '-s', '--format=%s', result.commit);
        const number = subject.match(/\(#(\d+)\)$/)?.[1] || JSON.parse(execFileSync('gh', ['api', `repos/${repo}/commits/${result.commit}/pulls`], { encoding: 'utf8' }))[0]?.number;
        if (!number) throw new Error('culprit PR not found');
        culprit = { commit: result.commit, pr: `https://github.com/${repo}/pull/${number}`, diff_stat: git('show', '--format=', '--stat', result.commit) };
      }
      const value = write({ prev, cur, probes, signatures, ...result, culprit });
      fs.writeFileSync(cache, JSON.stringify(value, null, 2));
    } catch (error) {
      const value = write({ status: 'inconclusive', reason: error.message, prev, cur, probes, signatures, range: { prev, cur } });
      fs.writeFileSync(cache, JSON.stringify(value, null, 2));
    } finally {
      clearTimeout(deadline);
      process.removeListener('SIGTERM', interrupted);
    }
  }
}
console.log(JSON.stringify({ bisect_path: output, status: read(output).status }));
