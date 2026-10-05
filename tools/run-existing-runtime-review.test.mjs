import assert from 'node:assert/strict';
import test from 'node:test';
import { assertEditorCanvasUsable, assertReviewDraftLifecycle, existingRuntimeReviewPassed, normalizeExistingRuntimeReviewOptions, studioAutoLoginUrl } from './run-existing-runtime-review.mjs';
import { normalizePresentationMap, evaluateEditorPresentation } from '../lib/editor-presentation.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { acceptanceScope, aggregateAcceptance, fidelityGate, editorGate, draftEditEvidencePassed, verifyReference, writeEvidence, consumeExistingRuntimeAcceptance, hash, bindFidelityReport } from '../lib/existing-runtime-acceptance.mjs';
import { runExistingRuntimeAcceptance } from '../lib/run-existing-runtime-acceptance.mjs';
import { pathToFileURL } from 'node:url';

test('block validity and successful editing cannot substitute for presentation evidence', () => {
  const result = { visual_parity: { status: 'passed' }, editor_validation: { total_blocks: 33, invalid_blocks: 0 }, review_draft: { status: 'passed' } };
  assert.equal(existingRuntimeReviewPassed(result), false);
  result.editor_presentation = { status: 'failed', comparisons: [] };
  assert.equal(existingRuntimeReviewPassed(result), false);
  result.editor_presentation = { status: 'passed', comparisons: [{ status: 'passed' }, { status: 'failed' }] };
  assert.equal(existingRuntimeReviewPassed(result), false);
});

test('presentation mapping requires bounded, unique, explicit identities on every surface', () => {
  const target = { id: 'form', role: 'region', selectors: { source: 'form', frontend: '.form', editor: '[data-type="jetpack/contact-form"]' }, containers: { source: 'main', frontend: 'main', editor: '.editor-styles-wrapper' } };
  const map = { schema: 'static-site-importer/editor-presentation-map/v1', targets: [target] };
  assert.equal(normalizePresentationMap(map).targets.length, 1);
  assert.throws(() => normalizePresentationMap({ ...map, targets: [] }), /1-128/);
  assert.throws(() => normalizePresentationMap({ ...map, targets: [target, target] }), /unique/);
  assert.throws(() => normalizePresentationMap({ ...map, targets: [{ ...target, selectors: { source: 'form' } }] }), /frontend/);
  assert.throws(() => normalizePresentationMap({ ...map, targets: [{ ...target, optional: true }] }), /required/);
  const empty = evaluateEditorPresentation(map, {});
  assert.equal(empty.status, 'failed');
  assert.equal(empty.coverage.matched, 0);
});

test('existing runtime review requires explicit runtime identity and makes credential-free Studio editor URLs', () => {
  const options = normalizeExistingRuntimeReviewOptions({ sourceOrigin: 'https://source.example', candidateOrigin: 'http://localhost:8886', route: '/', postId: '42', postType: 'pages', editorId: '7', authProvider: 'studio-auto-login', outputDirectory: '/tmp/ssi-review' });
  assert.equal(options.source_url, 'https://source.example/');
  assert.equal(options.candidate_url, 'http://localhost:8886/');
  assert.equal(studioAutoLoginUrl(options), 'http://localhost:8886/studio-auto-login?redirect_to=%2Fwp-admin%2Fpost.php%3Fpost%3D42%26action%3Dedit');
  assert.equal(options.editor_id, 7);
  const input = { sourceOrigin: 'https://source.example', candidateOrigin: 'http://localhost:8886', route: '/', postId: '42', postType: 'pages', editorId: '7', authProvider: 'studio-auto-login', outputDirectory: '/tmp/ssi-review' };
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, authProvider: 'cookies' }), /auth-provider/);
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, route: 'about' }), /route/);
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, sourceOrigin: 'https://user:secret@source.example' }), /origin/);
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, route: '/\\wp-admin' }), /route/);
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, editorId: 'editor' }), /editor-id/);
});

test('existing runtime review fails lifecycle evidence when persistence, cleanup, or target isolation is unproven', () => {
  assert.throws(() => assertReviewDraftLifecycle({ marker_present: false }), /marker/);
  assert.throws(() => assertReviewDraftLifecycle({ deleted: false, draft_id: 99 }), /still exists/);
  assert.throws(() => assertReviewDraftLifecycle({ target_baseline_sha256: 'before', target_after_sha256: 'after', target: 'pages\/42' }), /content changed/);
  assert.doesNotThrow(() => assertReviewDraftLifecycle({ marker_present: true, deleted: true, target_baseline_sha256: 'same', target_after_sha256: 'same', draft_id: 99, target: 'pages\/42' }));
});

test('existing runtime review requires visible editable content in Gutenberg canvas iframe', () => {
  const canvas = { canvas_document_type: 'iframe', total_blocks: 12, visible_blocks: 4, visible_text_blocks: 3 };
  assert.doesNotThrow(() => assertEditorCanvasUsable(canvas));
  assert.throws(() => assertEditorCanvasUsable({ ...canvas, canvas_document_type: 'parent' }), /iframe/);
  assert.throws(() => assertEditorCanvasUsable({ ...canvas, total_blocks: 0 }), /zero blocks/);
  assert.throws(() => assertEditorCanvasUsable({ ...canvas, visible_blocks: 0 }), /no visible blocks/);
  assert.throws(() => assertEditorCanvasUsable({ ...canvas, visible_text_blocks: 0 }), /no visible editable content/);
});

const frozenScope = acceptanceScope({ routes: ['/', '/about/'] });
const fidelityReport = (stage, scope = frozenScope) => ({ stage, status: 'proven', pass: true, coverage: { required: scope.cells, measured: scope.cells.map(cell => ({ ...cell, status: 'proven', pass: true, scores: { visual: 1 } })), unknowns: [] }, pending: [] });
test('frozen scope requires tablet and every route; desired states cannot disappear', () => {
  assert.deepEqual(frozenScope.widths, [390, 768, 1440]);
  const report = fidelityReport('capture');
  report.coverage.measured = report.coverage.measured.filter(cell => cell.width !== 768);
  const gate = fidelityGate('capture', frozenScope, report, true);
  assert.equal(gate.status, 'pending');
  assert.equal(gate.pending.filter(cell => cell.width === 768).length, 2);
  const named = acceptanceScope({ routes: ['/'], states: ['baseline', 'menu-open'] });
  assert.equal(fidelityGate('materialization', named, fidelityReport('materialization', acceptanceScope({ routes: ['/'] })), true).status, 'pending');
  assert.throws(() => acceptanceScope({ routes: ['/', '/'] }), /Duplicate/);
});
test('capture and candidate failures remain owned by their measured stage', () => {
  for (const stage of ['capture', 'materialization']) {
    const report = fidelityReport(stage);
    report.coverage.measured[0] = { ...report.coverage.measured[0], status: 'failed', pass: false };
    const gate = fidelityGate(stage, frozenScope, report, true);
    assert.equal(gate.status, 'failed');
    assert.equal(gate.failed[0].stage, stage);
    assert.equal(fidelityGate(stage === 'capture' ? 'materialization' : 'capture', frozenScope, report, true).status, 'pending');
  }
  assert.equal(fidelityGate('capture', frozenScope, fidelityReport('capture'), false).status, 'pending');
  assert.equal(fidelityGate('capture', frozenScope, { ...fidelityReport('capture'), status: 'failed', errors: ['source_hash_mismatch'] }, false).status, 'pending');
  assert.equal(fidelityGate('capture', frozenScope, { ...fidelityReport('capture'), status: 'failed', errors: [{ code: 'source_hash_mismatch' }] }, true).status, 'pending');
  const unsupported = fidelityReport('capture');
  unsupported.pending = [{ stage: 'capture', route: '/', viewport: 390, state: 'menu-open', reason: 'interaction_state_unsupported' }];
  assert.equal(fidelityGate('capture', frozenScope, unsupported, true).status, 'pending');
});
test('binds actual public DLA numeric coverage and viewport scores without inventing scorer fields', () => {
  const actual = { stage: 'capture', status: 'proven', pass: true,
    scores: frozenScope.cells.map(cell => ({ route: cell.route, viewport: cell.width, state: cell.state, pass: true, failures: [], notes: [] })),
    coverage: { required: frozenScope.cells.length, measured: frozenScope.cells.length, unknowns: ['Baseline excludes motion outside requested scope.'] }, pending: [] };
  const bound = bindFidelityReport('capture', frozenScope, actual, 'a'.repeat(64), null, [{ path: 'compare/capture/report.json', sha256: 'b'.repeat(64) }]);
  assert.equal(fidelityGate('capture', frozenScope, bound, true).status, 'passed');
  assert.equal(bound.public_coverage, actual.coverage);
  const missing = bindFidelityReport('capture', frozenScope, { ...actual, scores: actual.scores.filter(score => score.viewport !== 768), coverage: { ...actual.coverage, measured: 4 } }, 'a'.repeat(64), null, []);
  assert.equal(fidelityGate('capture', frozenScope, missing, true).status, 'pending');
  assert.throws(() => bindFidelityReport('capture', frozenScope, fidelityReport('capture'), 'a'.repeat(64), null, []), /Incompatible/);
});
test('acceptance requires measured numeric zero from a bound import report, plus all requirements', () => {
  const input = { identity: {}, scope: frozenScope, gates: { capture: fidelityGate('capture', frozenScope, fidelityReport('capture'), true), materialization: fidelityGate('materialization', frozenScope, fidelityReport('materialization'), true), editor: { status: 'passed' }, health: { status: 'passed' } }, importReport: { schema: 'static-site-importer/import-report/v1', quality: { fallback_count: 0 } }, importReportVerified: true };
  assert.equal(aggregateAcceptance(input).status, 'accepted');
  assert.equal(aggregateAcceptance({ ...input, importReportVerified: false }).status, 'pending');
  for (const count of [true, '0', undefined, -1]) assert.equal(aggregateAcceptance({ ...input, importReport: { ...input.importReport, quality: { fallback_count: count } } }).status, 'pending');
  assert.equal(aggregateAcceptance({ ...input, importReport: { ...input.importReport, quality: { fallback_count: 1 } } }).status, 'failed');
  assert.equal(aggregateAcceptance({ ...input, prerequisites: [{ status: 'pending' }] }).status, 'pending');
  assert.equal(aggregateAcceptance({ ...input, gates: { ...input.gates, health: { status: 'failed' } } }).status, 'failed');
});
test('editor status alone, no-op saves, missing image persistence and failed cleanup cannot prove edits', () => {
  const scope = acceptanceScope({ routes: ['/'] });
  assert.equal(editorGate(scope, [{ route: '/', status: 'passed' }]).status, 'pending');
  assert.equal(editorGate(scope, [{ route: '/', status: 'passed', cleanup: { status: 'failed' } }]).status, 'failed');
  assert.equal(editorGate(scope, []).status, 'pending');
  const valid = { cleanup: { status: 'passed' }, edit: { status: 'passed' }, persisted: { text: true, image: true }, frontend: { text: true, image: true }, original_validation: { total_blocks: 3, invalid_blocks: 0, content_sha256: 'a'.repeat(64) }, reloaded_validation: { total_blocks: 3, invalid_blocks: 0, content_sha256: 'b'.repeat(64), marker_present: true }, isolation: { original_before_sha256: 'a'.repeat(64), original_after_sha256: 'a'.repeat(64), media: [{ attachment_id: 7, before_sha256: 'c'.repeat(64), after_sha256: 'c'.repeat(64) }] } };
  assert.equal(draftEditEvidencePassed(valid), true);
  assert.equal(draftEditEvidencePassed({ ...valid, reloaded_validation: valid.original_validation }), false);
  assert.equal(draftEditEvidencePassed({ ...valid, persisted: { text: true, image: false } }), false);
  assert.equal(draftEditEvidencePassed({ ...valid, edit: { status: 'failed' } }), false);
  assert.equal(draftEditEvidencePassed({ ...valid, frontend: { text: true, image: false } }), false);
  assert.equal(draftEditEvidencePassed({ ...valid, isolation: { ...valid.isolation, original_after_sha256: 'd'.repeat(64) } }), false);
});
test('hash-bound reusable evidence rejects modified or escaped artifacts', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-acceptance-'));
  try {
    const ref = writeEvidence(root, 'report.json', { status: 'passed' });
    assert.equal(verifyReference(root, ref), true);
    assert.equal(verifyReference(root, { ...ref, path: '../report.json' }), false);
    fs.writeFileSync(path.join(root, ref.path), '{}');
    assert.equal(verifyReference(root, ref), false);
    assert.equal(consumeExistingRuntimeAcceptance(root, { schema: 'static-site-importer/existing-runtime-acceptance/v1', artifacts: { report: ref } }).status, 'pending');
  } finally { fs.rmSync(root, { recursive: true, force: true }); }
});

test('real supplied DLA and WordPress runtime preserve saved edits and reject unproven acceptance', {
  skip: !process.env.SSI_ACCEPTANCE_INTEGRATION_CONFIG,
  timeout: 240_000,
}, async () => {
  const { default: config } = await import(pathToFileURL(path.resolve(process.env.SSI_ACCEPTANCE_INTEGRATION_CONFIG)).href);
  const result = await runExistingRuntimeAcceptance(config);
  assert.equal(result.gates.capture.status, 'passed');
  assert.equal(result.gates.health.status, 'passed');
  assert.equal(result.conversion_quality.fallback_count, 0);
  assert.ok(result.gates.editor.routes.length > 0);
  for (const row of result.gates.editor.routes) {
    assert.equal(row.edit.status, 'passed');
    assert.deepEqual(row.persisted, { text: true, image: true });
    assert.deepEqual(row.frontend, { text: true, image: true });
    assert.equal(row.cleanup.status, 'passed');
    assert.equal(row.isolation.original_after_sha256, row.isolation.original_before_sha256);
    assert.ok(row.isolation.media.every(media => media.after_sha256 === media.before_sha256));
  }
  // Conversion and successful editor saves cannot stand in for missing render proof.
  if (result.gates.materialization.status !== 'passed' || result.gates.editor.status !== 'passed') {
    assert.notEqual(result.status, 'accepted');
  }
});

test('neutral browser smoke: unavailable DLA stays pending and candidate fatal fails independently', { skip: process.env.SSI_ACCEPTANCE_BROWSER_SMOKE !== '1' }, async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-runtime-smoke-'));
  let fatal = false;
  const server = http.createServer((request, response) => { response.writeHead(200, { 'Content-Type': 'text/html' }); response.end(fatal ? '<main>Fatal error: neutral fixture</main>' : '<main>Healthy neutral fixture</main>'); });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const candidateOrigin = `http://127.0.0.1:${server.address().port}`;
  try {
    writeEvidence(root, 'manifest.json', { routes: ['/'] });
    const config = { directory: root, manifest: path.join(root, 'manifest.json'), outputDirectory: path.join(root, 'review'), candidateOrigin, candidateIdentity: 'neutral-browser-fixture', evidenceRoot: root, authenticate: async () => {}, candidateInventory: async () => [{ route: '/', postId: 1, postType: 'pages' }], routeToPost: [{ route: '/', postId: 1, postType: 'pages' }], editorId: 1 };
    const healthy = await runExistingRuntimeAcceptance(config);
    assert.equal(healthy.status, 'pending');
    assert.equal(healthy.gates.health.status, 'passed');
    assert.equal(healthy.gates.capture.status, 'pending');
    assert.equal(healthy.gates.editor.status, 'pending');
    // Public API seam fixture is deliberately unproven, never a substitute DLA scorer.
    const reference = writeEvidence(root, 'fidelity-reference.json', { capture: 'frozen-neutral-reference' });
    const runtimeModule = path.join(root, 'public-runtime.mjs');
    fs.writeFileSync(runtimeModule, `import fs from 'node:fs'; import path from 'node:path'; import { createHash } from 'node:crypto';
      export async function checkFidelity(options) {
        const { directory, stage, routes, widths, states } = options;
        const callsFile = path.join(directory, 'calls.json');
        const calls = fs.existsSync(callsFile) ? JSON.parse(fs.readFileSync(callsFile)) : [];
        calls.push(options); fs.writeFileSync(callsFile, JSON.stringify(calls));
        const sha256 = createHash('sha256').update(fs.readFileSync(path.join(directory, 'fidelity-reference.json'))).digest('hex');
        const cells = routes.flatMap(route => widths.flatMap(width => states.map(state => ({route, width, state}))));
        const report = {stage, status:'unproven', pass:false, scores:[], pending:[{stage,reason:'fixture_has_no_scorer'}], coverage:{required:cells.length, measured:0, unknowns:['fixture_has_no_scorer']}};
        fs.mkdirSync(path.join(directory, 'compare', stage), {recursive:true});
        fs.writeFileSync(path.join(directory, 'compare', stage, 'report.json'), JSON.stringify(report));
        return report;
      }`);
    const runtimeValidation = writeEvidence(root, 'runtime-validation.json', { schema: 'static-site-importer/dla-runtime-validation/v1', runtime_identity: 'public-seam-fixture', public_entry_sha256: hash(fs.readFileSync(runtimeModule)), status: 'passed', frozen_capture: true, materialization_no_origin_visits: true });
    const composed = await runExistingRuntimeAcceptance({ ...config, reference, runtimeModule, runtimeIdentity: 'public-seam-fixture', runtimeValidation });
    assert.equal(composed.status, 'pending');
    const calls = JSON.parse(fs.readFileSync(path.join(root, 'calls.json')));
    assert.deepEqual(calls.map(call => call.stage), ['capture', 'materialization']);
    assert.equal(calls[0].candidateUrl, undefined);
    assert.equal(calls[1].candidateUrl, candidateOrigin);
    assert.deepEqual(calls[1].widths, [390, 768, 1440]);
    assert.deepEqual(calls[1].states, ['baseline']);
    assert.equal(composed.gates.materialization.evidence_valid, true);
    fatal = true;
    const broken = await runExistingRuntimeAcceptance(config);
    assert.equal(broken.status, 'failed');
    assert.equal(broken.gates.health.status, 'failed');
    assert.equal(broken.gates.materialization.status, 'pending');
    assert.equal(consumeExistingRuntimeAcceptance(config.outputDirectory, broken).status, 'failed');
  } finally { await new Promise(resolve => server.close(resolve)); fs.rmSync(root, { recursive: true, force: true }); }
});
