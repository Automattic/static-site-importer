import assert from 'node:assert/strict';
import test from 'node:test';
import { createHandoff, validateArtifactSelection, validateCapture } from './url-loop-intake.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

test('capture validation rejects partial capture and missing provenance', () => {
  const result = validateCapture({ schema: 'data-liberation-agent/capture-receipt/v1', status: 'partial', complete: false, source_url: 'https://example.com', file_count: 2 });
  assert.equal(result.valid, false);
  assert.deepEqual(result.failures, ['capture_incomplete', 'source_digest_missing', 'dla_provenance_missing']);
});

test('artifact selection rejects ambiguous generated artifact directories', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-loop-'));
  for (const name of ['one', 'two']) {
    fs.mkdirSync(path.join(root, name), { recursive: true });
    fs.writeFileSync(path.join(root, name, 'index.html'), '<!doctype html>');
  }
  assert.throws(() => validateArtifactSelection(root), /ambiguous/);
});

test('handoff never infers solved status from fallback-free output', () => {
  const handoff = createHandoff({ url: 'https://example.com/', sourceDigest: 'sha256:' + 'a'.repeat(64), matrix: { status: 'passed', fallback_count: 0 } });
  assert.equal(handoff.acceptance.solved, false);
  assert.equal(handoff.status, 'passed');
});
