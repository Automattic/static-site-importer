import assert from 'node:assert/strict';
import test from 'node:test';
import { assertReviewDraftLifecycle, normalizeExistingRuntimeReviewOptions, studioAutoLoginUrl } from './run-existing-runtime-review.mjs';

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
