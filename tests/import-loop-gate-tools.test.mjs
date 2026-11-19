import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

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
