import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { parityFailureClass, parityCellReports } from '../tools/import-loop/checks.mjs';
import { occurrences, compareImports } from '../tools/import-loop/progress.mjs';

// Failure strings exactly as the pinned scorer emitted them on sdnunca `/`
// (Automattic/static-site-importer#2955).
const failures = {
  title: 'title "My Site 1 | Home" !== source "Home | My Site 1"',
  link: 'nav 1 internal link(s) unsuccessful: /my-site-1 (HTTP 404; http-error)',
  anchor: 'nav 1 same-page anchor(s) missing: /about/index.html#about',
  motion: 'viewport animation lifecycle unproven: comp-mmc8rqph: authored paused CSS animation binding changed',
  textPosition: 'text position differs for 1 matched text run(s): "Home" source (1176,14), copy (1190,14)',
  textContent: 'text 887 chars !== source 863',
  imageGeometry: 'image geometry differs for 1 matched image(s)',
  backgroundGeometry: 'background geometry differs for 1 source background(s)',
  pageHeight: 'page height 3472px !== source 3359px (Δ113)',
  imageDecoding: 'images 1 visible image(s) pending or failed decoding',
};

test('each scorer failure class names its own subject and owner', () => {
  assert.deepEqual(parityFailureClass(failures.title), { subject: 'title', owner_repo: 'static-site-importer' });
  for (const [text, subject] of [
    [failures.link, 'link'], [failures.anchor, 'link'], [failures.motion, 'motion'],
    [failures.textPosition, 'text_position'], [failures.textContent, 'text_content'],
    [failures.imageGeometry, 'image_geometry'], [failures.backgroundGeometry, 'background_geometry'],
    [failures.pageHeight, 'page_height'], [failures.imageDecoding, 'image_decoding'],
  ]) assert.deepEqual(parityFailureClass(text), { subject, owner_repo: 'blocks-engine' });
});

test('a class the table has not seen still gets its own stable subject', () => {
  const first = parityFailureClass('widget 3 colors differ from source');
  const same = parityFailureClass('widget 9 colors differ from source');
  const other = parityFailureClass('shadow radius differs from source');
  assert.equal(first.owner_repo, 'blocks-engine');
  assert.deepEqual([first.subject, first.subject === same.subject, first.subject !== other.subject], ['widget_colors_differ', true, true]);
});

// `verified` mirrors checks.mjs: measured evidence (scores, no pending), not
// passing — a failing score still measured the cell.
const cell = (scores, extra = {}) => ({ route: '/', width: 1440, scores, pending: [], verified: scores.length > 0, ...extra });
const score = (failures, pass = !failures.length) => ({ state: 'baseline', profile: 'desktop', failures, pass });

test('a failing cell splits into one report per class with deduplicated counts', () => {
  const reports = parityCellReports(cell([
    score([failures.title, failures.link, failures.motion]),
    // The mobile profile repeats the same defects at the same route/width.
    { state: 'baseline', profile: 'mobile', failures: [failures.title, failures.motion], pass: false },
  ]));
  assert.deepEqual(reports.map((r) => [r.subject, r.owner_repo, r.evidence.count]), [
    ['title', 'static-site-importer', 1],
    ['link', 'blocks-engine', 1],
    ['motion', 'blocks-engine', 1],
  ]);
  assert.deepEqual(reports[0].evidence.failures, [failures.title]);
  assert.deepEqual(reports.find((r) => r.subject === 'motion').evidence.failures, [failures.motion]);
});

test('a passing verified cell reports nothing; a failed state without strings stays unverified', () => {
  assert.deepEqual(parityCellReports(cell([score([])])), []);
  const blind = parityCellReports(cell([], { verified: false }));
  assert.deepEqual(blind.map((r) => [r.subject, r.owner_repo, r.evidence]), [['unverified', 'blocks-engine', cell([], { verified: false })]]);
  // Pending evidence fails closed, and a failing state with no failure strings
  // still fails.
  assert.deepEqual(parityCellReports(cell([], { pending: [{ state: 'baseline' }] })).map((r) => r.subject), ['unverified']);
  assert.deepEqual(parityCellReports(cell([{ state: 'baseline', profile: 'desktop', failures: [], pass: false }])).map((r) => r.subject), ['unverified']);
});

test('per-class signatures are distinct, so each class is measured on its own', () => {
  const signature = (subject, owner) => createHash('sha1').update(JSON.stringify(['materialization', 'visual_parity', owner, subject])).digest('hex').slice(0, 12);
  const subjects = parityCellReports(cell([score([failures.title, failures.link, failures.motion, failures.textPosition])]));
  assert.equal(new Set(subjects.map((r) => signature(r.subject, r.owner_repo))).size, subjects.length);
  assert.equal(signature('title', 'static-site-importer') !== signature('title', 'blocks-engine'), true);
});

const asFinding = (reports, signatureOf) => reports.map((r) => ({
  signature: signatureOf(r.subject),
  severity: 'blocker',
  check: 'visual_parity',
  owner_repo: r.owner_repo,
  subject: r.subject,
  occurrences: [{ route: '/', width: 1440, evidence: r.evidence }],
}));

test('fixing the title class moves only its own count (sdnunca / shape)', () => {
  const signatureOf = (subject) => createHash('sha1').update(JSON.stringify(['materialization', 'visual_parity', subject === 'title' ? 'static-site-importer' : 'blocks-engine', subject])).digest('hex').slice(0, 12);
  const motionFailures = Array.from({ length: 3 }, (_, n) => `viewport animation lifecycle unproven: comp-${n}: authored paused CSS animation binding changed`);
  const before = asFinding(parityCellReports(cell([score([failures.title, failures.link, ...motionFailures])])), signatureOf);
  const afterReports = parityCellReports(cell([score([failures.link, ...motionFailures])]));
  const after = asFinding(afterReports, signatureOf);
  const wrap = (findings) => ({ findings, visual: [cell([score([failures.link, ...motionFailures])])] });
  const title = signatureOf('title');
  const progress = compareImports({ findings: before, visual: [cell([score([failures.title, failures.link, ...motionFailures])])] }, wrap(after), title);
  // The title finding is gone entirely, nothing else grew, and no parity
  // failure appeared or was lost: the fix registers as progress.
  assert.deepEqual([progress.pass, progress.own], [true, { before: 1, after: 0 }]);
  assert.deepEqual(progress.blockers.added, []);
  const motion = signatureOf('motion');
  const stuck = compareImports({ findings: before, visual: [] }, { findings: after, visual: [] }, motion);
  assert.deepEqual([stuck.pass, stuck.own], [false, { before: 3, after: 3 }]);
  assert.equal(occurrences(after.find((f) => f.signature === motion)), 3);
});
