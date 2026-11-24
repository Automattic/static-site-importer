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

test('a corpus replay posts failure for regressions, error when unmeasured, success otherwise', async () => {
  const { corpusStatus } = await import('../tools/import-loop/corpus-pr.mjs');
  const sites = ['agency', 'bizcasual'];
  assert.deepEqual(corpusStatus({ failing: [{ corpus: [{ site: 'bizcasual', cells: ['/@390', '/@768'] }] }], reasons: [] }, sites), { state: 'failure', description: 'worse on bizcasual (2)' });
  assert.equal(corpusStatus({ failing: [], reasons: ['corpus replay could not measure: x'] }, sites).state, 'error');
  assert.deepEqual(corpusStatus({ failing: [], reasons: [] }, sites), { state: 'success', description: 'no regression on 2 corpus sites' });
});

test('A pull request is replayed only when it changes a candidate path', async () => {
  const { touchesCandidate } = await import('../tools/import-loop/corpus-pr.mjs');
  const importer = ['static-site-importer.php', 'includes'];
  for (const [files, replayed] of [
    [[{ path: 'tools/import-loop/editor.mjs' }], false], // SSI#3282: a check change, measured as noise
    [[{ path: 'includes/class-importer.php' }, { path: 'tests/x.php' }], true],
    [[{ path: 'static-site-importer.php' }], true],
    [[{ path: 'includes-extra/x.php' }], false],
  ]) assert.equal(touchesCandidate(files, importer), replayed);
});

// SSI#3297: a fixture replay of corpusCheck with a recorded trunk baseline, the
// runner answering each corpus.mjs call from a queue of replay reports.
async function fixtureCheck(replays) {
  const fs = await import('node:fs');
  const os = await import('node:os');
  const path = await import('node:path');
  const { corpusCheck } = await import('../tools/import-loop/corpus-check.mjs');
  const { corpusStatus } = await import('../tools/import-loop/corpus-pr.mjs');
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-corpus-check-'));
  const triage = { trunk: 't'.repeat(40), bases: { 'static-site-importer': 'v1.0.0' }, transformer: '/trunk/php-transformer', reimport: { ssi_zip: '', bare_wp: '' } };
  const baseline = path.join(root, '.baselines', `${'t'.repeat(12)}-v1.0.0`);
  fs.mkdirSync(baseline, { recursive: true });
  for (const site of ['agency', 'sdnunca']) fs.writeFileSync(path.join(baseline, `${site}.json`), '{}');
  fs.writeFileSync(path.join(baseline, '.recorded.json'), JSON.stringify({ unmeasured: [] }));
  const calls = [];
  const runCorpus = (mode, args) => { calls.push({ mode, blocksEngine: args[args.indexOf('--blocks-engine') + 1], sites: args[args.indexOf('--sites') + 1] }); return replays.shift(); };
  const verdict = { failing: [], reasons: [] };
  try {
    const { summary } = corpusCheck({ corpus: root, triage, here: '/x', log: () => {}, runCorpus, heads: [{ verdict, key: 'pr', transformer: '/head/php-transformer' }] });
    return { status: corpusStatus(verdict, summary.sites), calls };
  } finally { fs.rmSync(root, { recursive: true, force: true }); }
}
const report = (regressions) => ({ sites: [{ site: 'agency', regressions: [] }, { site: 'sdnunca', regressions }] });

test('a cell that regresses once and not on recheck is flaky, and the head passes', async () => {
  const { status, calls } = await fixtureCheck([report(['/@390']), { sites: [{ site: 'sdnunca', regressions: [] }] }, { sites: [{ site: 'sdnunca', regressions: [] }] }]);
  assert.deepEqual(status, { state: 'success', description: 'no regression on 2 corpus sites (1 flaky: sdnunca)' });
  // The recheck re-measures only the regressed site, on the head and on trunk.
  assert.deepEqual(calls.slice(1), [{ mode: 'replay', blocksEngine: '/head/php-transformer', sites: 'sdnunca' }, { mode: 'replay', blocksEngine: '/trunk/php-transformer', sites: 'sdnunca' }]);
});

test('a regression the head repeats while trunk stays put fails the head', async () => {
  const { status } = await fixtureCheck([report(['/@390']), { sites: [{ site: 'sdnunca', regressions: ['/@390'] }] }, { sites: [{ site: 'sdnunca', regressions: [] }] }]);
  assert.deepEqual(status, { state: 'failure', description: 'worse on sdnunca (1)' });
});

test('a regression trunk also shows on recheck is flicker in the baseline', async () => {
  const { status } = await fixtureCheck([report(['/@390', '/@768']), { sites: [{ site: 'sdnunca', regressions: ['/@390', '/@768'] }] }, { sites: [{ site: 'sdnunca', regressions: ['/@768'] }] }]);
  assert.deepEqual(status, { state: 'failure', description: 'worse on sdnunca (1) (1 flaky: sdnunca)' });
});
