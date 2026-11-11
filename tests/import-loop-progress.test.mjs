import test from 'node:test';
import assert from 'node:assert/strict';
import { compareImports, occurrences } from '../tools/import-loop/progress.mjs';
const finding = (signature, count, route = '/') => ({ signature, severity: 'blocker', occurrences: [{ route, width: 1440, evidence: { count } }] });
const visual = [{ route: '/', width: 1440, verified: true, scores: [{ state: 'baseline', failures: [] }] }];
const result = (...findings) => ({ findings, visual });
test('unchanged captured-dialog cannot pass (5104 failure class)', () => {
  const v = compareImports(result(finding('dialog', 7)), result(finding('dialog', 7)), 'dialog');
  assert.equal(v.pass, false);
  assert.deepEqual(v.blockers.unchanged, [{ signature: 'dialog', before: 7, after: 7, delta: 0 }]);
});
test('partial 7 to 5 counts as progress, not disappearance', () => {
  const v = compareImports(result(finding('image', 7)), result(finding('image', 5)), 'image');
  assert.equal(v.pass, true);
  assert.deepEqual(v.own, { before: 7, after: 5 });
});
test('improvement outside own cells does not pass', () => {
  assert.equal(compareImports(result(finding('a', 7)), result(finding('a', 5)), 'a', [{ route: '/other/' }]).pass, false);
});
test('another blocker appearing or growing fails', () => {
  for (const count of [0, 2]) assert.equal(compareImports(result(finding('a', 7), finding('b', count)), result(finding('a', 5), finding('b', count + 1)), 'a').pass, false);
});
test('unmeasured or regressed visual cell fails closed', () => {
  for (const next of [[], [{ ...visual[0], verified: false }], [{ ...visual[0], scores: [{ state: 'baseline', failures: ['lost image'] }] }]]) {
    assert.equal(compareImports(result(finding('a', 7)), { findings: [finding('a', 5)], visual: next }, 'a').pass, false);
  }
});
test('counts block instances rather than just cells', () => assert.equal(occurrences(finding('a', 7)), 7));
