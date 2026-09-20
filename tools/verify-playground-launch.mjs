import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const root = path.resolve(import.meta.dirname, '..');
const readme = await readFile(path.join(root, 'README.md'), 'utf8');
const launchUrl = [ ...readme.matchAll(/\]\((https:\/\/playground\.wordpress\.net\/\?[^)]+)\)/g) ][0]?.[1];
const figFixture = process.env.PLAYGROUND_FIG_FIXTURE;
const restaurantFixtureUrl = process.env.PLAYGROUND_RESTAURANT_FIXTURE_URL || 'https://raw.githubusercontent.com/Automattic/blocks-engine/03d53d903d620a578428c2f61094aca18392406f/fixtures/websites/14-restaurant/index.html';

assert.ok(launchUrl, 'README must include a WordPress Playground launch URL');

const restaurantFixtureResponse = await fetch(restaurantFixtureUrl);
assert.equal(restaurantFixtureResponse.ok, true, `Restaurant fixture must resolve: ${restaurantFixtureUrl}`);
const restaurantFixture = await restaurantFixtureResponse.text();
assert.equal(Buffer.byteLength(restaurantFixture, 'utf8'), 65_512, 'Restaurant fixture must retain its pinned byte size');

const launch = new URL(launchUrl);
if (process.env.PLAYGROUND_EXTENSION_MANIFEST_URL) {
  launch.searchParams.set('php-extension', process.env.PLAYGROUND_EXTENSION_MANIFEST_URL);
}
if (process.env.PLAYGROUND_BLUEPRINT_URL) {
  launch.searchParams.set('blueprint-url', process.env.PLAYGROUND_BLUEPRINT_URL);
}
const manifestUrl = launch.searchParams.get('php-extension');
const blueprintUrl = launch.searchParams.get('blueprint-url');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const diagnostics = [];
page.on('console', (message) => diagnostics.push(`console.${message.type()}: ${message.text()}`));
page.on('pageerror', (error) => diagnostics.push(`pageerror: ${error.message}`));
page.on('requestfailed', (request) => diagnostics.push(`requestfailed: ${request.url()} (${request.failure()?.errorText})`));

try {
  if (manifestUrl) {
    // This is deliberately a browser fetch from Playground's origin: Node fetch
    // cannot detect the CORS regression that broke the extension launch.
    await page.goto('https://playground.wordpress.net/', { waitUntil: 'domcontentloaded' });
    const manifest = await page.evaluate(async (url) => {
      const response = await fetch(url);
      return { ok: response.ok, manifest: await response.json() };
    }, manifestUrl);
    assert.equal(manifest.ok, true, 'Playground must be able to CORS-fetch the extension manifest');
    assert.equal(manifest.manifest.name, 'zstd');
  }

  assert.ok(blueprintUrl, 'Playground launch must include a blueprint URL');
  // Exercise Blueprint downloads through Playground, including its supported
  // CORS proxy fallback. A direct fetch is not the Blueprint resource contract.

  await page.goto(launch.toString(), { waitUntil: 'domcontentloaded', timeout: 120_000 });
  let wordpress;
  for (let attempt = 0; attempt < 120; attempt += 1) {
    wordpress = page.frames().find((candidate) => candidate.url().includes('/wp-admin/') || candidate.url().includes('/import/'));
    if (wordpress) break;
    await page.waitForTimeout(1_000);
  }
  assert.ok(
    wordpress,
    `Playground must boot its WordPress frame; frames=${JSON.stringify(page.frames().map((frame) => frame.url()))}; diagnostics=${JSON.stringify(diagnostics.slice(-20))}`,
  );
  if (!wordpress.url().includes('/import/')) await wordpress.waitForURL(/\/import\//, { timeout: 120_000 });
  await wordpress.locator('.ssi-importer').waitFor({ state: 'visible', timeout: 120_000 });

  const figmaAvailable = await wordpress.locator('.ssi-importer').getAttribute('data-static-site-importer-figma-available');
  const figmaButton = wordpress.locator('[data-static-site-importer-upload-figma]');
  assert.equal(figmaAvailable, manifestUrl ? '1' : '0', 'Figma availability must match the optional zstd runtime capability');
  assert.equal(await figmaButton.isDisabled(), !manifestUrl, 'Figma control must fail open without zstd');

  const importer = wordpress.locator('.ssi-importer');
  const importUrl = wordpress.url();
  const homeUrl = await importer.getAttribute('data-static-site-importer-home-url');
  const importRestUrl = await importer.getAttribute('data-static-site-importer-rest-url');
  assert.ok(homeUrl, 'Importer must expose its home URL');
  assert.ok(importRestUrl, 'Importer must expose its REST endpoint');
  const htmlDetails = wordpress.locator('details:has([data-static-site-importer-source-html])');
  await htmlDetails.locator('summary').click();
  assert.equal(await htmlDetails.evaluate((element) => element.open), true, 'Paste HTML must be expanded before filling its textarea');
  // Importing replaces the WordPress iframe. Listen on the browser context so
  // the response remains observable while that frame navigates.
  const importResponseReads = [];
  const collectImportResponse = (response) => {
    if (response.url() !== importRestUrl || response.request().method() !== 'POST') return;
    importResponseReads.push(response.json().then(
      (report) => ({ ok: response.ok(), report }),
      (error) => ({ ok: false, report: { error: error.message } }),
    ));
  };
  page.context().on('response', collectImportResponse);
  await wordpress.locator('[data-static-site-importer-source-html]').fill(restaurantFixture);
  await wordpress.locator('[data-static-site-importer-submit]').click();
  await wordpress.waitForURL((url) => url.href === new URL(homeUrl, importUrl).href, { timeout: 120_000 });
  page.context().off('response', collectImportResponse);
  const importResponses = await Promise.all(importResponseReads);
  const importResponse = importResponses.at(-1);
  assert.ok(importResponse, 'Restaurant fixture import must return a REST response');
  assert.equal(importResponse.ok, true, JSON.stringify(importResponse.report));
  assert.equal(importResponse.report.success, true, JSON.stringify(importResponse.report));
  // The public contract permits both a completed response and continuation.
  // Completion, not the number of requests, is the browser acceptance gate.
  assert.notEqual(importResponse.report.continuation, true, 'Restaurant import must finish its continuation');
  diagnostics.push(`import-responses: ${JSON.stringify(importResponses)}`);
  assert.equal(importResponse.report.result?.theme_slug, 'ember-rye', JSON.stringify(importResponse.report));
  assert.equal(await wordpress.locator('body').evaluate((element) => element.classList.contains('wp-theme-ember-rye')), true, 'The imported theme must be active on the existing home frame');
  assert.ok((await wordpress.locator('title').textContent())?.trim(), 'The imported site home must retain a document title');

  const migrationToolbarLink = wordpress.locator('#wp-admin-bar-pgwpc a');
  await migrationToolbarLink.waitFor({ state: 'visible', timeout: 120_000 });
  await Promise.all([
    wordpress.waitForURL(/\/wp-admin\/admin\.php\?page=playground-to-wordpress-com/, { timeout: 120_000 }),
    migrationToolbarLink.click(),
  ]);
  await wordpress.locator('#pgwpc-connect').waitFor({ state: 'visible', timeout: 120_000 });

  // The importer must survive materialization so a user can import another site.
  await wordpress.goto(importUrl, { waitUntil: 'domcontentloaded', timeout: 120_000 });
  await wordpress.locator('.ssi-importer').waitFor({ state: 'visible', timeout: 120_000 });

  console.log(JSON.stringify({ blueprintUrl, fixtureBytes: Buffer.byteLength(restaurantFixture, 'utf8'), importRequests: importResponses.length, theme: importResponse.report.result.theme_slug, migration: 'passed', repeatImport: 'passed' }));

  if (figFixture) {
    assert.ok(manifestUrl, 'Figma fixture verification requires a PHP extension manifest');
    const restUrl = await importer.getAttribute('data-static-site-importer-figma-rest-url');
    assert.ok(restUrl, 'Figma importer must expose its REST endpoint');
    const [ response ] = await Promise.all([
      page.context().waitForEvent('response', {
        predicate: (response) => response.url() === restUrl && response.request().method() === 'POST',
        timeout: 300_000,
      }),
      wordpress.locator('[data-static-site-importer-source-figma-file]').setInputFiles(figFixture),
    ]);
    const report = await response.json();
    assert.equal(response.ok(), true, JSON.stringify(report));
    assert.equal(report.success, true, JSON.stringify(report));
    await wordpress.waitForURL((url) => url.href === new URL('/', importUrl).href, { timeout: 120_000 });
  }
} catch (error) {
  const screenshotPath = '/tmp/static-site-importer-playground-launch-failure.png';
  try {
    await page.screenshot({ path: screenshotPath, fullPage: true });
    diagnostics.push(`screenshot: ${screenshotPath}`);
  } catch (screenshotError) {
    diagnostics.push(`screenshot-error: ${screenshotError.message}`);
  }
  throw new Error(`${error.message}\ndiagnostics=${JSON.stringify(diagnostics.slice(-50))}`, { cause: error });
} finally {
  await browser.close();
}
