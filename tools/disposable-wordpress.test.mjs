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

test('pinned downloads retry transport failures without bypassing integrity checks', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-provision-retry-'));
  const originalFetch = globalThis.fetch;
  let attempts = 0;
  try {
    globalThis.fetch = async () => {
      if (++attempts < 3) throw new TypeError('fetch failed');
      return new Response('not the pinned archive');
    };
    await assert.rejects(provisionWordPress({ workDir: directory }), /sha256 mismatch/);
    assert.equal(attempts, 3);
    assert.equal(fs.existsSync(path.join(directory, 'runtime')), false);
    attempts = 0;
    globalThis.fetch = async () => { attempts++; throw new TypeError('fetch failed'); };
    await assert.rejects(provisionWordPress({ workDir: directory }), /fetch failed/);
    assert.equal(attempts, 3, 'persistent failures stop after three attempts');
    assert.equal(fs.existsSync(path.join(directory, 'runtime')), false);
  } finally {
    globalThis.fetch = originalFetch;
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

test('WP-CLI output is not truncated by a fixed buffer', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-provision-'));
  let runtime;
  try {
    runtime = await provisionWordPress({ workDir: directory });
    // A whole-site inventory or theme snapshot exceeds Node's 1 MiB default.
    assert.equal(runtime.wpRun(['eval', 'echo str_repeat("x", 2 * 1024 * 1024);']).stdout.length, 2 * 1024 * 1024);
  } finally {
    await runtime?.close();
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
