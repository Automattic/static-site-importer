import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { chromium } from 'playwright';

const root = path.resolve(import.meta.dirname, '..');
const readme = await readFile(path.join(root, 'README.md'), 'utf8');
const launchUrl = [ ...readme.matchAll(/\]\((https:\/\/playground\.wordpress\.net\/\?[^)]+)\)/g) ][0]?.[1];
const figFixture = process.env.PLAYGROUND_FIG_FIXTURE;
const sourceUrl = process.env.PLAYGROUND_SOURCE_URL;
const sourceZip = process.env.PLAYGROUND_SOURCE_ZIP;
assert.ok(!sourceUrl || !sourceZip, 'Select one input mode per fresh Playground instance');
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
const packages = [];
const uiReports = [];
let rejectImport;
page.context().on('console', (message) => diagnostics.push(`console.${message.type()}: ${message.text()}`));
page.on('pageerror', (error) => diagnostics.push(`pageerror: ${error.message}`));
page.on('requestfailed', (request) => diagnostics.push(`requestfailed: ${request.url()} (${request.failure()?.errorText})`));

try {
  await page.context().exposeBinding('__ssiVerifyReport', (_source, report) => {
    uiReports.push(report);
    diagnostics.push(`ui-report: ${JSON.stringify({ success: report.success, error: report.error || report.code, theme: report.result?.theme_slug, pages: report.result?.page_count })}`);
    if (rejectImport && report.success !== true && !report.continuation) rejectImport(new Error(`Import failed: ${JSON.stringify(report.error || report)}`));
  });
  // Observe the real user-facing report for both native fetch and Playground's
  // client transport, which does not emit browser HTTP response events.
  await page.context().addInitScript(() => {
    const descriptor = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
    Object.defineProperty(HTMLTextAreaElement.prototype, 'value', {
      ...descriptor,
      set(value) {
        descriptor.set.call(this, value);
        if (this.matches('[data-static-site-importer-report]')) {
          try { window.__ssiVerifyReport(JSON.parse(value)); } catch {}
        }
      },
    });
  });
  if (process.env.PLAYGROUND_BLUEPRINT_FILE || process.env.PLAYGROUND_PLUGIN_ZIP || process.env.PLAYGROUND_DEMO_ZIP) {
    let blueprint = process.env.PLAYGROUND_BLUEPRINT_FILE ? JSON.parse(await readFile(process.env.PLAYGROUND_BLUEPRINT_FILE, 'utf8')) : await (await fetch(blueprintUrl)).json();
    for (const [ file, name ] of [
      [ process.env.PLAYGROUND_PLUGIN_ZIP, 'static-site-importer.zip' ],
      [ process.env.PLAYGROUND_DEMO_ZIP, 'static-site-importer-playground-demo.zip' ],
    ]) {
      if (!file) continue;
      const bytes = await readFile(file);
      const sha256 = createHash('sha256').update(bytes).digest('hex');
      const step = blueprint.steps.find((candidate) => candidate.step === 'writeFile' && candidate.data?.url?.endsWith(`/${name}`));
      assert.ok(step, `Blueprint must declare ${name}`);
      const previous = step.path.match(/[a-f0-9]{64}/)?.[0];
      assert.ok(previous, 'Candidate packages must retain blueprint integrity checks');
      const url = `https://ssi-review.invalid/${name}`;
      step.data.url = url;
      blueprint = JSON.parse(JSON.stringify(blueprint).replaceAll(previous, sha256));
      await page.context().route(url, (route) => route.fulfill({ body: bytes, contentType: 'application/zip', headers: { 'access-control-allow-origin': '*' } }));
      packages.push({ name, sha256, bytes: bytes.length });
    }
    await page.context().route(blueprintUrl, (route) => route.fulfill({ json: blueprint, headers: { 'access-control-allow-origin': '*' } }));
  }
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
  if (sourceUrl) {
    await wordpress.locator('[data-static-site-importer-source-url]').fill('https://127.0.0.1/');
    await wordpress.locator('[data-static-site-importer-submit]').click();
    await wordpress.locator('[data-static-site-importer-submit]:enabled').waitFor();
    const rejected = JSON.parse(await wordpress.locator('[data-static-site-importer-report]').inputValue());
    assert.notEqual(rejected.success, true, 'Private URL must not claim success');
    assert.equal(rejected.error?.code || rejected.code, 'static_site_importer_url_private_ip');
    await wordpress.locator('[data-static-site-importer-submit]:enabled').waitFor();
    assert.equal(wordpress.url(), importUrl, 'Rejected URL must remain on the importer');
  }
  if (process.env.PLAYGROUND_INVALID_SOURCE_ZIP) {
    await wordpress.locator('[data-static-site-importer-source-files]').setInputFiles(process.env.PLAYGROUND_INVALID_SOURCE_ZIP);
    await wordpress.locator('[data-static-site-importer-submit]').click();
    await wordpress.locator('[data-static-site-importer-submit]:enabled').waitFor();
    const rejected = JSON.parse(await wordpress.locator('[data-static-site-importer-report]').inputValue());
    assert.notEqual(rejected.success, true, 'Invalid ZIP must not claim success');
    assert.ok(rejected.error?.code || rejected.code, 'Rejected ZIP must provide an error code');
    assert.ok(rejected.error?.message || rejected.message, 'Rejected ZIP must provide an actionable message');
    assert.equal(wordpress.url(), importUrl, 'Rejected ZIP must remain on the importer');
    await wordpress.locator('[data-static-site-importer-source-files]').setInputFiles([]);
  }
  if (!sourceUrl && !sourceZip) {
    const htmlDetails = wordpress.locator('details:has([data-static-site-importer-source-html])');
    await htmlDetails.locator('summary').click();
    assert.equal(await htmlDetails.evaluate((element) => element.open), true, 'Paste HTML must be expanded before filling its textarea');
  }
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
  if (sourceZip) {
    await wordpress.locator('[data-static-site-importer-source-files]').setInputFiles(sourceZip);
  } else {
    await wordpress.locator(sourceUrl ? '[data-static-site-importer-source-url]' : '[data-static-site-importer-source-html]').fill(sourceUrl || restaurantFixture);
  }
  const submitAndWaitForHome = async () => {
    const importFailed = new Promise((_resolve, reject) => { rejectImport = reject; });
    await Promise.all([
      Promise.race([
        wordpress.waitForURL((url) => url.href === new URL(homeUrl, importUrl).href, { timeout: sourceUrl || sourceZip ? 300_000 : 120_000 }),
        importFailed,
      ]),
      wordpress.locator('[data-static-site-importer-submit]').click(),
    ]);
    rejectImport = undefined;
  };
  await submitAndWaitForHome();
  page.context().off('response', collectImportResponse);
  const importResponses = await Promise.all(importResponseReads);
  const lastReport = uiReports.at(-1);
  const importResponse = lastReport ? { ok: lastReport.success === true, report: lastReport } : importResponses.at(-1);
  assert.ok(importResponse, 'Restaurant fixture import must return a REST response');
  assert.equal(importResponse.ok, true, JSON.stringify(importResponse.report));
  assert.equal(importResponse.report.success, true, JSON.stringify(importResponse.report));
  if (process.env.PLAYGROUND_EXPECT_RESPONSE_ARTIFACTS) assert.equal(importResponse.report.result?.response_artifacts?.status, process.env.PLAYGROUND_EXPECT_RESPONSE_ARTIFACTS);
  // The public contract permits both a completed response and continuation.
  // Completion, not the number of requests, is the browser acceptance gate.
  assert.notEqual(importResponse.report.continuation, true, 'Restaurant import must finish its continuation');
  diagnostics.push(`import-responses: ${JSON.stringify(importResponses)}`);
  const themeSlug = importResponse.report.result?.theme_slug || importResponse.report.result?.theme?.slug;
  assert.ok(typeof themeSlug === 'string' && themeSlug.length > 0, 'A completed import must identify the materialized theme');
  const expectedTheme = process.env.PLAYGROUND_EXPECT_THEME || (!sourceUrl && !sourceZip ? 'ember-rye' : undefined);
  if (expectedTheme) assert.equal(themeSlug, expectedTheme, JSON.stringify(importResponse.report));
  if (process.env.PLAYGROUND_MIN_PAGES) assert.ok(importResponse.report.result?.page_count >= Number(process.env.PLAYGROUND_MIN_PAGES), 'Fixture must materialize its expected page count');
  if (sourceUrl) {
    const discoveredRoutes = importResponse.report.url_batch_run?.total_routes || Math.max(0, ...importResponses.map(({ report }) => report.url_batch_run?.total_routes || 0));
    assert.ok(discoveredRoutes > 0 && importResponse.report.result?.page_count >= discoveredRoutes, 'Every discovered URL route must have a materialized page');
  }
  assert.equal(await wordpress.locator('body').evaluate((element, slug) => element.classList.contains(`wp-theme-${slug}`), themeSlug), true, 'The imported theme must be active on the existing home frame');
  assert.ok((await wordpress.locator('title').textContent())?.trim(), 'The imported site home must retain a document title');
  if (process.env.PLAYGROUND_EXPECT_LOCAL_IMAGE) {
    const image = wordpress.locator(`img[src*="${process.env.PLAYGROUND_EXPECT_LOCAL_IMAGE}"]`).first();
    await image.scrollIntoViewIfNeeded();
    const decoded = await image.evaluate(async (element) => {
      await element.decode();
      return { width: element.naturalWidth, origin: new URL(element.currentSrc).origin };
    });
    assert.ok(decoded.width > 0 && decoded.origin === new URL(homeUrl).origin, 'ZIP image must decode from the imported site');
  }
  if (process.env.PLAYGROUND_SCREENSHOT) await page.screenshot({ path: process.env.PLAYGROUND_SCREENSHOT, fullPage: true });

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

  if (process.env.PLAYGROUND_REPEAT_PASTE === '1') {
    await wordpress.locator('details:has([data-static-site-importer-source-html]) summary').click();
    await wordpress.locator('[data-static-site-importer-source-html]').fill(restaurantFixture);
    await submitAndWaitForHome();
    assert.equal(uiReports.at(-1)?.success, true, 'A second import must complete in the same instance');
    assert.equal(uiReports.at(-1)?.result?.theme_slug, 'ember-rye');
    assert.equal(await wordpress.locator('body').evaluate((element) => element.classList.contains('wp-theme-ember-rye')), true);
    await wordpress.locator('#wp-admin-bar-pgwpc a').click();
    await wordpress.locator('#pgwpc-connect').waitFor({ state: 'visible', timeout: 120_000 });
    await wordpress.goto(importUrl, { waitUntil: 'domcontentloaded' });
    await wordpress.locator('.ssi-importer').waitFor({ state: 'visible' });
  }
  console.log(JSON.stringify({ blueprintUrl, sourceUrl, sourceZip: sourceZip ? path.basename(sourceZip) : undefined, packages, fixtureBytes: sourceUrl || sourceZip ? undefined : Buffer.byteLength(restaurantFixture, 'utf8'), observedHttpResponses: importResponses.length, pages: importResponse.report.result?.page_count, theme: themeSlug, responseArtifacts: importResponse.report.result?.response_artifacts?.status, migration: 'passed', importerRetained: 'passed', secondImport: process.env.PLAYGROUND_REPEAT_PASTE === '1' ? 'passed' : undefined }));

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
  try {
    const debug = await Promise.race([
      page.evaluate(async () => window.playground ? await window.playground.readFileAsText('/wordpress/wp-content/debug.log') : ''),
      new Promise((resolve) => setTimeout(() => resolve('PHP diagnostics unavailable within 5 seconds'), 5_000)),
    ]);
    if (debug) console.error(String(debug).slice(-12_000));
  } catch {}
  const screenshotPath = process.env.PLAYGROUND_FAILURE_SCREENSHOT || '/tmp/static-site-importer-playground-launch-failure.png';
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
