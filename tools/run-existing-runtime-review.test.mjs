import assert from 'node:assert/strict';
import test from 'node:test';
import { assertEditorCanvasUsable, assertReviewDraftLifecycle, existingRuntimeReviewPassed, normalizeExistingRuntimeReviewOptions, studioAutoLoginUrl } from './run-existing-runtime-review.mjs';
import { normalizePresentationMap, evaluateEditorPresentation } from '../lib/editor-presentation.mjs';

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
