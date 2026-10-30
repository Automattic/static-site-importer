import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { provisionWordPress, PINNED } from '../lib/disposable-wordpress.mjs';
import { PINNED as releaseInputs } from './run-release-import-gate.mjs';

test('release gate uses shared pinned inputs and partial provisioning is destroyed', async () => {
  assert.equal(releaseInputs, PINNED);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-provision-'));
  const fetch = globalThis.fetch;
  try {
    fs.mkdirSync(path.join(directory, 'downloads'));
    // A retained, corrupted archive must not be accepted as a cached input.
    fs.writeFileSync(path.join(directory, 'downloads', `wordpress-${PINNED.wordpressVersion}.tar.gz`), 'corrupted');
    globalThis.fetch = async () => new Response('not the pinned archive');
    await assert.rejects(provisionWordPress({ workDir: directory }), /sha256 mismatch/);
    assert.equal(fs.existsSync(path.join(directory, 'runtime')), false);
    assert.equal(fs.existsSync(path.join(directory, 'downloads')), true);
  } finally {
    globalThis.fetch = fetch;
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('provisioning refuses to reuse an existing runtime without deleting it', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-provision-'));
  try {
    fs.mkdirSync(path.join(directory, 'runtime'));
    const marker = path.join(directory, 'runtime', 'owner');
    fs.writeFileSync(marker, 'existing');
    await assert.rejects(provisionWordPress({ workDir: directory }), /must be fresh/);
    assert.equal(fs.readFileSync(marker, 'utf8'), 'existing');
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
