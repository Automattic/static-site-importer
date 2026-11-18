import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { retain } from '../tools/import-loop/retain.mjs';

test('retention stops a slug\'s servers and removes its sites, keeping the scored pass site', async () => {
  const runs = fs.mkdtempSync(path.join(os.tmpdir(), 'retain-'));
  const site = (dir) => { fs.mkdirSync(dir, { recursive: true }); fs.writeFileSync(path.join(dir, 'wp-config.php'), ''); return dir; };
  const old = site(path.join(runs, 'wix-p1', 'site'));
  const kept = site(path.join(runs, 'wix-p2', 'site'));
  const trunk = site(path.join(runs, 'wix-p2-triage', 'trunk-site'));
  const head = site(path.join(runs, 'wix-p2-confirm', 'abc', 'heads', 'be-1-aaaa-on-bbbb-site'));
  const other = site(path.join(runs, 'other-p1', 'site'));
  const findings = path.join(runs, 'wix-p2-eval', 'findings.json');
  fs.mkdirSync(path.dirname(findings)); fs.writeFileSync(findings, '{}');
  // A served site: a process group like reimport.sh's setsid server.
  const server = spawn('sleep', ['60'], { detached: true, stdio: 'ignore' });
  fs.writeFileSync(path.join(kept, '.server.pid'), String(server.pid));
  const exited = new Promise((resolve) => server.on('exit', (code, signal) => resolve(signal)));

  assert.equal(retain(runs, 'wix', 2), 3);
  assert.equal(await exited, 'SIGTERM');
  assert.deepEqual([old, kept, trunk, head, other, findings].map((p) => fs.existsSync(p)), [false, true, false, false, true, true]);
});
