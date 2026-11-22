import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sourceCss, themeCssWeight } from '../tools/import-loop/source-css.mjs';

test('route CSS allowance uses captured inline and linked CSS with a 90 KB floor', () => {
  const fixture = fileURLToPath(new URL('./fixtures/source-css', import.meta.url));
  const source = sourceCss(fixture, '/');
  const linked = fs.readFileSync(path.join(fixture, 'website/assets/captured.css'), 'utf8');
  assert.deepEqual(source, { source_kb: +((linked.length + 'body { color: #123456; }'.length) / 1024).toFixed(1), source_status: 'measured' });
  assert.ok(source.source_kb > 90);
  const lighter = themeCssWeight({ kb: 95, largest: [] }, source);
  assert.ok(lighter.kb <= lighter.limit_kb);
  const heavier = themeCssWeight({ kb: 120, largest: [] }, source);
  assert.ok(heavier.kb > heavier.limit_kb);
  assert.deepEqual(heavier.measure, { value: 120, limit: source.source_kb });

  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'source-css-'));
  try {
    fs.mkdirSync(path.join(dir, 'website/about'), { recursive: true });
    fs.writeFileSync(path.join(dir, 'website/about/index.html'), '<style>p { color: red; }</style>');
    const light = themeCssWeight({ kb: 91 }, sourceCss(dir, '/about/'));
    assert.equal(light.limit_kb, 90);
    assert.ok(light.kb > light.limit_kb);
    assert.equal(themeCssWeight({ kb: 90 }, sourceCss(dir, '/missing/')).limit_kb, 90);
    fs.writeFileSync(path.join(dir, 'website/index.html'), '<style>p{color:red}</style><link rel="stylesheet" href="/missing.css">');
    assert.deepEqual(sourceCss(dir, '/'), { source_kb: null, source_status: 'unmeasured' });
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
