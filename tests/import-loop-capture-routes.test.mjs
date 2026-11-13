import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { captureRoutes } from '../tools/import-loop/checks.mjs';
import { routeDocument } from '../tools/import-loop/lib.mjs';

test('routes come from the capture receipt for directory and file sites', () => {
  for (const [paths, routes] of [
    [['website/index.html', 'website/privacy-policy/index.html', 'website/about/index.html'], ['/', '/about/', '/privacy-policy/']],
    [['website/index.html', 'website/store.html', 'website/about.html', 'website/products.html'], ['/', '/about.html', '/products.html', '/store.html']],
  ]) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-routes-'));
    fs.writeFileSync(path.join(root, 'capture-receipt.json'), JSON.stringify({ routes: paths.map((p) => ({ path: p })) }));
    assert.deepEqual(captureRoutes(root), routes);
    // Each route reads back the document it was captured from.
    assert.deepEqual(captureRoutes(root).map((route) => path.relative(root, routeDocument(path.join(root, 'website'), route))).sort(), [...paths].sort());
  }
});
