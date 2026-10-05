import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, mkdir, symlink, writeFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { buildHostAcceptance, HOST_ACCEPTANCE_API } from './build-host-acceptance.mjs';

// Playwright is a devDependency whose Chromium download lives in the caller's
// browser cache (or `PLAYWRIGHT_BROWSERS_PATH`); the deterministic gate
// environment runs with an isolated HOME that has no downloaded browser
// binary. Probe the executable the caller-owned Playwright package would
// launch so this suite skips instead of failing a browser launch, and still
// exercises the relocated bundle wherever the full browser toolchain exists.
const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};
const chromiumExecutableAvailable = Boolean( chromium ) && existsSync( chromium.executablePath() );
const skipWithoutChromium = chromiumExecutableAvailable
	? false
	: 'playwright chromium is not installed; run `npm install` and `npx playwright install chromium` to run this relocated host bundle suite';

test('relocated public host bundle has only caller-owned Playwright and records missing/fatal evidence honestly', {
  skip: skipWithoutChromium,
}, async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'ssi-host-acceptance-'));
  let fatal = false;
  const server = createServer((_request, response) => {
    response.setHeader('content-type', 'text/html');
    response.end(fatal ? '<main>There has been a critical error on this website.</main>' : '<main>Healthy fixture</main>');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const manifest = await buildHostAcceptance({ outputDirectory: path.join(directory, 'public') });
    const entry = path.join(directory, 'public/index.mjs');
    assert.equal(manifest.schema, HOST_ACCEPTANCE_API);
    assert.equal(manifest.sha256, createHash('sha256').update(await readFile(entry)).digest('hex'));
    const require = createRequire(import.meta.url);
    await mkdir(path.join(directory, 'node_modules'), { recursive: true });
    await symlink(path.dirname(require.resolve('playwright/package.json')), path.join(directory, 'node_modules/playwright'), process.platform === 'win32' ? 'junction' : 'dir');
    const runtime = await import(pathToFileURL(entry).href);
    for (const name of manifest.exports) assert.ok(runtime[name], `public export ${name}`);
    assert.equal(runtime.HOST_ACCEPTANCE_API, HOST_ACCEPTANCE_API);
    await writeFile(path.join(directory, 'scope.json'), JSON.stringify({ routes: ['/'] }));
    const config = { directory, evidenceRoot: directory, manifest: path.join(directory, 'scope.json'), outputDirectory: path.join(directory, 'output'), candidateOrigin: `http://127.0.0.1:${server.address().port}` };
    const missing = await runtime.runExistingRuntimeAcceptance(config);
    assert.equal(missing.status, 'pending');
    assert.equal(missing.gates.capture.status, 'pending');
    assert.equal(missing.gates.health.status, 'passed');
    fatal = true;
    const failed = await runtime.runExistingRuntimeAcceptance(config);
    assert.equal(failed.status, 'failed');
    assert.equal(failed.gates.health.status, 'failed');
    assert.equal(runtime.consumeExistingRuntimeAcceptance(config.outputDirectory, failed).status, 'failed');
  } finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
    await rm(directory, { recursive: true, force: true });
  }
});

test('published ZIP contains the exact versioned host entry and verified digest', {
  skip: !process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP,
}, async () => {
  const archive = process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP;
  const prefix = 'static-site-importer/assets/host-acceptance/';
  const manifest = JSON.parse(execFileSync('unzip', ['-p', archive, `${prefix}manifest.json`], { encoding: 'utf8' }));
  assert.equal(manifest.schema, HOST_ACCEPTANCE_API);
  assert.equal(manifest.entry, 'index.mjs');
  const bytes = execFileSync('unzip', ['-p', archive, `${prefix}index.mjs`], { maxBuffer: 5 * 1024 * 1024 });
  assert.equal(manifest.sha256, createHash('sha256').update(bytes).digest('hex'));
  const plugin = execFileSync('unzip', ['-p', archive, 'static-site-importer/static-site-importer.php'], { encoding: 'utf8' });
  assert.equal(manifest.version, plugin.match(/^\s*\* Version:\s*([0-9.]+)/m)?.[1]);
});

test('actual ZIP public entry verifies real DLA and Gutenberg saved edits after relocation', {
  skip: !process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP || !process.env.SSI_ACCEPTANCE_INTEGRATION_CONFIG,
  timeout: 240_000,
}, async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'ssi-host-zip-proof-'));
  try {
    execFileSync('unzip', ['-q', process.env.STATIC_SITE_IMPORTER_PACKAGE_ZIP, '-d', directory]);
    const root = path.join(directory, 'static-site-importer');
    await mkdir(path.join(root, 'node_modules'), { recursive: true });
    const require = createRequire(import.meta.url);
    await symlink(path.dirname(require.resolve('playwright/package.json')), path.join(root, 'node_modules/playwright'), process.platform === 'win32' ? 'junction' : 'dir');
    const runtime = await import(pathToFileURL(path.join(root, 'assets/host-acceptance/index.mjs')).href);
    const { default: config } = await import(pathToFileURL(path.resolve(process.env.SSI_ACCEPTANCE_INTEGRATION_CONFIG)).href);
    const result = await runtime.runExistingRuntimeAcceptance(config);
    assert.equal(result.gates.capture.status, 'passed');
    assert.equal(result.gates.health.status, 'passed');
    assert.equal(result.conversion_quality.fallback_count, 0);
    assert.ok(result.gates.editor.routes.length > 0);
    for (const row of result.gates.editor.routes) {
      assert.deepEqual(row.persisted, { text: true, image: true });
      assert.deepEqual(row.frontend, { text: true, image: true });
      assert.equal(row.cleanup.status, 'passed');
      assert.equal(row.isolation.original_before_sha256, row.isolation.original_after_sha256);
      assert.ok(row.isolation.media.every(media => media.before_sha256 === media.after_sha256));
    }
    if (result.gates.materialization.status !== 'passed' || result.gates.editor.status !== 'passed') assert.notEqual(result.status, 'accepted');
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
