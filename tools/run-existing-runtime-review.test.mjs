import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeExistingRuntimeReviewOptions, studioAutoLoginUrl } from './run-existing-runtime-review.mjs';

test('existing runtime review requires explicit runtime identity and makes credential-free Studio editor URLs', () => {
  const options = normalizeExistingRuntimeReviewOptions({ sourceOrigin: 'https://source.example', candidateOrigin: 'http://localhost:8886', route: '/', postId: '42', postType: 'pages', authProvider: 'studio-auto-login', outputDirectory: '/tmp/ssi-review' });
  assert.equal(options.source_url, 'https://source.example/');
  assert.equal(options.candidate_url, 'http://localhost:8886/');
  assert.equal(studioAutoLoginUrl(options), 'http://localhost:8886/studio-auto-login?redirect_to=%2Fwp-admin%2Fpost.php%3Fpost%3D42%26action%3Dedit');
  const input = { sourceOrigin: 'https://source.example', candidateOrigin: 'http://localhost:8886', route: '/', postId: '42', postType: 'pages', authProvider: 'studio-auto-login', outputDirectory: '/tmp/ssi-review' };
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, authProvider: 'cookies' }), /auth-provider/);
  assert.throws(() => normalizeExistingRuntimeReviewOptions({ ...input, route: 'about' }), /route/);
});
