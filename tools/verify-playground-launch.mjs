import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const root = path.resolve(import.meta.dirname, '..');
const readme = await readFile(path.join(root, 'README.md'), 'utf8');
const launchUrl = [ ...readme.matchAll(/\]\((https:\/\/playground\.wordpress\.net\/\?[^)]+)\)/g) ][0]?.[1];

assert.ok(launchUrl, 'README must include a WordPress Playground launch URL');

const launch = new URL(launchUrl);
if (process.env.PLAYGROUND_EXTENSION_MANIFEST_URL) {
  launch.searchParams.set('php-extension', process.env.PLAYGROUND_EXTENSION_MANIFEST_URL);
}
if (process.env.PLAYGROUND_BLUEPRINT_URL) {
  launch.searchParams.set('blueprint-url', process.env.PLAYGROUND_BLUEPRINT_URL);
}
const manifestUrl = launch.searchParams.get('php-extension');
assert.ok(manifestUrl, 'README launch URL must provide a PHP extension manifest');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  // This is deliberately a browser fetch from Playground's origin: Node fetch
  // cannot detect the CORS regression that broke the launch link.
  await page.goto('https://playground.wordpress.net/', { waitUntil: 'domcontentloaded' });
  const manifest = await page.evaluate(async (url) => {
    const response = await fetch(url);
    return { ok: response.ok, manifest: await response.json() };
  }, manifestUrl);
  assert.equal(manifest.ok, true, 'Playground must be able to CORS-fetch the extension manifest');
  assert.equal(manifest.manifest.name, 'zstd');

  await page.goto(launch.toString(), { waitUntil: 'domcontentloaded', timeout: 120_000 });
  let wordpress;
  for (let attempt = 0; attempt < 120; attempt += 1) {
    wordpress = page.frames().find((candidate) => candidate.url().includes('/wp-admin/') || candidate.url().includes('/import/'));
    if (wordpress) break;
    await page.waitForTimeout(1_000);
  }
  assert.ok(wordpress, 'Playground must boot its WordPress frame');
  if (!wordpress.url().includes('/import/')) await wordpress.waitForURL(/\/import\//, { timeout: 120_000 });
  await wordpress.locator('.ssi-importer').waitFor({ state: 'visible', timeout: 120_000 });
} finally {
  await browser.close();
}
