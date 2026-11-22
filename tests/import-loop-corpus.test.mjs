import test from 'node:test';
import assert from 'node:assert/strict';
import { compareCells } from '../tools/import-loop/corpus.mjs';

const cell = (failures = [], verified = true) => ({ route: '/', width: 390, verified, pass: verified && !failures.length, scores: [{ state: 'default', profile: 'desktop', pass: !failures.length, failures }] });
test('corpus compares classes per state: regression, improvement, unmeasured', () => {
  const failed = cell(['text 12 chars !== source 20']);
  assert.deepEqual(compareCells([cell()], [failed]).regressions, ['/@390']);
  assert.deepEqual(compareCells([failed], [cell()]).improvements, ['/@390']);
  assert.deepEqual(compareCells([failed], [cell(['text 15 chars !== source 20'])]).regressions, []);
  assert.deepEqual(compareCells([failed], [cell(['text 15 chars !== source 20', 'text 18 chars !== source 30'])]).regressions, ['/@390']);
  assert.deepEqual(compareCells([cell()], [cell([], false)]), { regressions: ['/@390'], improvements: [], unmeasured: ['/@390'] });
  assert.deepEqual(compareCells([cell([], false)], [cell([], false)]), { regressions: [], improvements: [], unmeasured: ['/@390'] });
  assert.deepEqual(compareCells([cell()], []).regressions, ['/@390']);
  const unknown = cell(); unknown.pass = false; unknown.scores[0].pass = false;
  assert.deepEqual(compareCells([cell()], [unknown]).regressions, ['/@390']);
});

test('a corpus refutation confirm records reworks that head only', async () => {
  const { corpusNote, corpusRefutation } = await import('../tools/import-loop/corpus-check.mjs');
  const regressed = [{ site: 'bizcasual', cells: ['/@390'] }];
  const comments = [{ body: 'unrelated' }, { body: corpusNote('a'.repeat(40), regressed, '/c/.baselines/t-r') }];
  assert.deepEqual(corpusRefutation(comments, 'a'.repeat(40)), regressed);
  assert.equal(corpusRefutation(comments, 'b'.repeat(40)), null);
  assert.equal(corpusRefutation([{ body: 'unrelated' }], 'a'.repeat(40)), null);
});
