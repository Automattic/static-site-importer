import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

// A request bundle delivers its files as payload references, so the site title
// must be read through the bundle's payload reader, not inline file content.
test('a request-bundle import names the site from the source document title', async () => {
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-site-title-'));
  let runtime;
  try {
    runtime = await provisionWordPress({ workDir });
    await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
    await mkdir(join(workDir, 'bundle/website'), { recursive: true });
    await writeFile(join(workDir, 'bundle/website/index.html'), '<!doctype html><html><head><title>Northwind Studio</title></head><body><main><h1>Welcome</h1><p>Hand-built furniture.</p></main></body></html>');
    const request = join(workDir, 'apply.json');
    await writeFile(request, JSON.stringify({ operation: 'apply', source: { type: 'files', ref: 'request-bundle:bundle' }, slug: 'northwind', name: 'northwind', activate: true }));
    const receipt = JSON.parse(runtime.wpRun(['static-site-importer', 'import', `--request=${request}`]).stdout.trim().split('\n').at(-1));
    assert.equal(receipt.status, 'completed', JSON.stringify(receipt).slice(0, 500));
    assert.equal(runtime.wpRun(['option', 'get', 'blogname']).stdout.trim(), 'Northwind Studio');
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
