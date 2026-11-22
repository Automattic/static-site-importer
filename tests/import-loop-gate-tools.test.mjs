import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { stageGateTools } from '../tools/import-loop/lib.mjs';

// Triage ships tools/ and lib/ to the Cook gate and confirm (gate-tools). A
// relative import that leaves them breaks every gate on the runner.
const repo = path.resolve(new URL('..', import.meta.url).pathname);
const shipped = ['tools', 'lib'].map((dir) => path.join(repo, dir) + path.sep);
const files = fs.readdirSync(path.join(repo, 'tools', 'import-loop'), { recursive: true }).filter((f) => f.endsWith('.mjs')).map((f) => path.join(repo, 'tools', 'import-loop', f));

test('import-loop tools import only what gate-tools ships', () => {
  const outside = files.flatMap((file) => [...fs.readFileSync(file, 'utf8').matchAll(/(?:from|import\()\s*['"](\.{1,2}\/[^'"]+)['"]/g)]
    .map((m) => path.resolve(path.dirname(file), m[1]))
    .filter((target) => !shipped.some((dir) => target.startsWith(dir)))
    .map((target) => `${path.relative(repo, file)} -> ${path.relative(repo, target)}`));
  assert.deepEqual(outside, []);
});

test('gate-tools carries every package the import-loop modules import', () => {
  const out = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-tools-'));
  try {
    stageGateTools(repo, path.join(out, 'gate-tools'));
    // Resolve each bare import from the staged snapshot, as the runner does.
    const specs = [...new Set(files.flatMap((file) => [...fs.readFileSync(file, 'utf8').matchAll(/(?:^\s*import\s+|\bfrom\s+|\bimport\(\s*)['"]([^'".][^'"]*)['"]/gm)].map((m) => m[1]).filter((spec) => !spec.startsWith('node:'))))];
    const run = spawnSync('node', ['--input-type=module', '-e', `for (const spec of ${JSON.stringify(specs)}) await import(spec);`], { cwd: path.join(out, 'gate-tools', 'tools', 'import-loop'), encoding: 'utf8' });
    assert.equal(run.status, 0, run.stderr);
  } finally { fs.rmSync(out, { recursive: true, force: true }); }
});
