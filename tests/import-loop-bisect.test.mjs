import test from 'node:test';
import assert from 'node:assert/strict';
import { bisectSearch, bisectSkip } from '../tools/import-loop/bisect-search.mjs';
import { reportRegression } from '../tools/import-loop/bisect-report.mjs';

test('first-parent search: boundary, probe bound and inconclusive outcomes', async () => {
  for (const size of [1, 2, 3, 5, 8, 28, 32]) {
    for (let first = 0; first < size; first++) {
      const commits = Array.from({ length: size }, (_, i) => `c${i}`);
      const calls = [];
      const result = await bisectSearch(commits, 'prev', async (commit) => {
        calls.push(commit);
        return commit !== 'prev' && Number(commit.slice(1)) >= first;
      }, Boolean);
      assert.equal(result.status, 'complete');
      assert.equal(result.commit, commits[first]);
      assert.equal(result.before, false);
      assert.equal(result.after, true);
      assert.ok(calls.length <= Math.ceil(Math.log2(size)) + 1, `${size}/${first}: ${calls.length}`);
      assert.equal(new Set(calls).size, calls.length);
    }
  }
  for (const outcomes of [[false, false, false], [false, new Error('build failed'), true]]) {
    const commits = ['a', 'b', 'c'];
    const result = await bisectSearch(commits, 'prev', async (c) => {
      if (c === 'prev') return true;
      const outcome = outcomes[commits.indexOf(c)];
      if (outcome instanceof Error) throw outcome;
      return outcome;
    }, Boolean);
    assert.equal(result.status, 'inconclusive');
    assert.ok(result.range);
    assert.equal(result.commit, undefined);
  }
});

test('skip contracts', () => {
  for (const [added, prev, cur, reason] of [
    [[], 'a', 'b', 'no added blockers'],
    [[{}], 'a', 'a', 'unchanged trunk'],
    [[{}], undefined, 'b', 'no previous trunk'],
    [[{}], 'a', 'b', null],
  ]) assert.equal(bisectSkip(added, prev, cur), reason);
});

test('attribution comments are retry-safe and guide only regressed findings', () => {
  const comments = new Map();
  const gh = (command, action, url, ...rest) => {
    if (action === 'view') return JSON.stringify({ comments: comments.get(url) || [] });
    const body = rest[rest.indexOf('--body') + 1];
    comments.set(url, [...(comments.get(url) || []), { body }]);
  };
  const bisect = { status: 'complete', prev: 'a', cur: 'b', culprit: { pr: 'pr', commit: 'sha', diff_stat: '1 file changed' }, signatures: [{ signature: 'sig', before: 1, cells: [{ route: '/', width: 390 }] }], after: { sig: 2 } };
  const tracked = [{ signature: 'sig', issue_url: 'issue' }, { signature: 'other', issue_url: 'other' }];
  const notes = reportRegression(bisect, tracked, 'site', gh);
  reportRegression(bisect, tracked, 'site', gh);
  assert.match(notes.get('sig'), /1 file changed/);
  assert.equal(notes.has('other'), false);
  assert.equal(comments.get('issue').length, 1);
  assert.equal(comments.get('pr').length, 1);
  assert.match(comments.get('issue')[0].body, /count 1 → 2 on \/@390/);
});
