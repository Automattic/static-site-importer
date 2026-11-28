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
test('a visual cell regresses when a failure class appears or grows, not when its numbers change', () => {
  const cell = (...failures) => [{ ...visual[0], scores: [{ state: 'baseline', failures }] }];
  const was = cell('text 3173 chars !== source 3175', 'text position differs for 86 matched text run(s)');
  for (const [now, regressed] of [
    [cell('text 3176 chars !== source 3165', 'text position differs for 3 matched text run(s)'), false],
    [cell('text 3176 chars !== source 3165', 'text position differs for 3 matched text run(s)', 'page height 9365px !== source 10264px (Δ-899)'), true],
    [cell('text 3176 chars !== source 3165', 'text 12 chars !== source 40', 'text position differs for 3 matched text run(s)'), true],
  ]) assert.equal(compareImports({ findings: [finding('a', 7)], visual: was }, { findings: [finding('a', 5)], visual: now }, 'a').pass, !regressed);
});
test('a cell unmeasured on both sides is reported, not a regression; nothing measured fails', () => {
  const blind = { route: '/about/', width: 1440, verified: false, scores: [] };
  const v = compareImports({ findings: [finding('a', 7)], visual: [...visual, blind] }, { findings: [finding('a', 5)], visual: [...visual, blind] }, 'a');
  assert.deepEqual([v.pass, v.visual_regressions, v.visual_unmeasured], [true, [], ['/about/@1440']]);
  assert.equal(compareImports({ findings: [finding('a', 7)], visual: [blind] }, { findings: [finding('a', 5)], visual: [blind] }, 'a').pass, false);
});
test('a cell whose capture reference drifted from the export is unmeasured, not a regression', () => {
  const regressed = [{ ...visual[0], scores: [{ state: 'baseline', failures: ['pixel region 0,0 48x48 (9 tiles) in core/group'] }] }];
  const drift = { signature: 'd', severity: 'blocker', check: 'capture_reference_drift', occurrences: [{ route: '/', width: 1440 }] };
  const v = compareImports({ findings: [finding('a', 7), drift], visual: [...visual, { ...visual[0], route: '/x/' }] }, { findings: [finding('a', 5), drift], visual: [...regressed, { ...visual[0], route: '/x/' }] }, 'a');
  assert.deepEqual([v.pass, v.visual_regressions, v.visual_unmeasured], [true, [], ['/@1440']]);
});
test('counts block instances rather than just cells', () => assert.equal(occurrences(finding('a', 7)), 7));
test('a threshold blocker passes on less excess at the same count, and fails on more', () => {
  const measured = (signature, value) => ({ signature, severity: 'blocker', occurrences: [{ route: '/', evidence: { measure: { value, limit: 90 } } }] });
  for (const [before, after, pass] of [[125, 100, true], [125, 125, false], [125, 130, false]]) {
    assert.equal(compareImports(result(measured('css', before)), result(measured('css', after)), 'css').pass, pass);
  }
  // Another over-budget blocker running further past its limit in the same
  // places is a warning: the fix lands as progress. Failing anywhere new refutes.
  const grown = compareImports(result(finding('a', 7), measured('css', 100)), result(finding('a', 5), measured('css', 110)), 'a');
  assert.deepEqual([grown.pass, grown.warnings.map((w) => w.signature)], [true, ['css']]);
  assert.equal(compareImports(result(finding('a', 7)), result(finding('a', 5), measured('css', 110)), 'a').pass, false);
});
test('blockers on routes only the candidate measured are new scope; a shared route still regresses', () => {
  const was = { routes: ['/'], findings: [finding('folded', 22)], visual };
  const wider = (b) => ({ routes: ['/', '/about/'], findings: [finding('folded', 0), finding('b', 9, '/about/'), ...(b ? [finding('b', b)] : [])], visual });
  const v = compareImports(was, wider(0), 'folded');
  assert.deepEqual([v.pass, v.new_scope], [true, { routes: ['/about/'], blockers: [{ signature: 'b', count: 9 }] }]);
  assert.equal(compareImports(was, wider(1), 'folded').pass, false);
});
