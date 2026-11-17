import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { evidenceArchive } from '../tools/import-loop/lib.mjs';

// A capture's media appears under both `media/` and `resources/external/`:
// the Cook's evidence archive carries that content once, and unpacks whole.
test('a provider evidence archive stores duplicate files once and unpacks every path', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'evidence-'));
  const capture = path.join(root, 'capture');
  const image = randomBytes(1 << 20);
  for (const file of ['media/a.jpg', 'resources/external/host/a.jpg', 'website/index.html']) {
    fs.mkdirSync(path.dirname(path.join(capture, file)), { recursive: true });
    fs.writeFileSync(path.join(capture, file), file.endsWith('.jpg') ? image : '<h1>Home</h1>');
  }
  const archive = evidenceArchive(capture, path.join(root, 'evidence', 'capture.tgz'));
  assert.ok(fs.statSync(archive).size < 1.5 * (1 << 20), `archive is ${fs.statSync(archive).size} bytes`);
  const out = path.join(root, 'out');
  fs.mkdirSync(out);
  execFileSync('tar', ['-xzf', archive, '-C', out]);
  assert.ok(fs.readFileSync(path.join(out, 'media/a.jpg')).equals(image));
  assert.ok(fs.readFileSync(path.join(out, 'resources/external/host/a.jpg')).equals(image));
  assert.equal(fs.readFileSync(path.join(out, 'website/index.html'), 'utf8'), '<h1>Home</h1>');
  assert.ok(fs.readFileSync(path.join(capture, 'media/a.jpg')).equals(image), 'source untouched');
  assert.deepEqual(fs.readdirSync(path.dirname(archive)), ['capture.tgz']);
  fs.rmSync(root, { recursive: true, force: true });
});
