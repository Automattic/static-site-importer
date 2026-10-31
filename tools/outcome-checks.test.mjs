import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { chromium } from 'playwright';
import { runOutcomeChecks, outcomeGate, THEME_OWNER_SNAPSHOT_SCHEMA } from '../lib/outcome-checks.mjs';
import { documentOutcomes } from '../lib/document-outcomes.mjs';
import { interactiveOutcomes } from '../lib/interactive-outcomes.mjs';
import { interactiveConfig } from '../tests/acceptance/outcome-interactive-config.mjs';
import { aggregateAcceptance, acceptanceScope } from '../lib/existing-runtime-acceptance.mjs';

// Public runner primitive, with an explicit server-parse inventory seam. Real
// WordPress collection is also exercised by the disposable acceptance workload.
test('outcome report detects independent regressions, chains and cycles', async () => {
  let regression = '';
  // The theme switch flips the served theme without touching any native owner;
  // regressions mutate owners, routes, aliases or CSS under that switch.
  const theme = { active: 'outcome-fixture' };
  const page = body => `<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="canonical" href="HOME/"><link rel="stylesheet" href="/assets/site.css"><meta name="description" content="Imported fixture"><meta property="og:title" content="Fixture"><meta property="og:url" content="HOME/"><meta property="og:image" content="HOME/image.png"><meta name="generator" content="WordPress 7.1"><meta name="robots" content="${regression === 'head-robots' ? 'noindex' : 'max-image-preview:large'}">${regression === 'head' ? '<link rel="canonical" href="https://source.example/">' : ''}</head><body><nav><a href="/#fixture-panel">Information</a></nav><main>${body}</main></body></html>`.replaceAll('HOME', 'PLACEHOLDER');
  const server = http.createServer((req, res) => {
    const origin = `http://127.0.0.1:${server.address().port}`;
    const url = new URL(req.url, origin);
    if (theme.active !== 'outcome-fixture') theme.rendered = true;
    const body = page(url.pathname === '/about/' ? '<p id="about-section">About content</p>' : '<details id="fixture-panel"><summary>More</summary></details>');
    if (req.url === '/alias' || req.url === '/legacy') {
      if (req.url === '/legacy' && theme.active !== 'outcome-fixture' && regression === 'theme-alias') { res.setHeader('Content-Type', 'text/html'); res.end(body); return; }
      res.writeHead(301, { Location: regression === 'cycle' ? req.url : regression === 'chain' ? '/next' : '/' }); res.end(); return;
    }
    if (req.url === '/next') { res.writeHead(302, { Location: '/' }); res.end(); return; }
    if (url.pathname === '/assets/site.css') {
      res.setHeader('Content-Type', 'text/css');
      res.end(`main { color: #123456; }${regression === 'css' ? ' #fixture-retired { background-image: url(https://cdn.source.example/texture.png); }' : ''}${regression === 'css-import' ? ' @import url(https://source.example/legacy.css);' : ''}`);
      return;
    }
    if (url.pathname === '/about/' && regression === 'theme-route-about') { res.writeHead(500); res.end(); return; }
    if (url.pathname === '/' && regression === 'theme-route') { res.writeHead(500); res.end(); return; }
    res.setHeader('Content-Type', 'text/html');
    res.end(body.replaceAll('PLACEHOLDER', origin));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
    const candidateOrigin = `http://127.0.0.1:${server.address().port}`;
    const inventory = () => ({ schema: 'static-site-importer/outcome-inventory/v1', types: ['post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation'], documents: [
      { id: 1, type: 'wp_navigation', content: regression === 'anchor' ? '<!-- wp:html --><a href="#fixture-panel">Information</a><!-- /wp:html -->' : '<!-- wp:html --><a href="/#fixture-panel">Information</a><a href="#">Top</a><!-- /wp:html -->', parse: { parser: 'parse_blocks', valid: regression !== 'block', errors: regression === 'block' ? [{ reason: 'stray_delimiter' }] : [] } },
      { id: 2, type: 'wp_template_part', content: '<!-- wp:html --><a href="/about/#about-section">About</a><!-- /wp:html -->', parse: { parser: 'parse_blocks', valid: true, errors: [] } },
      { id: 3, type: 'page', content: regression === 'source' ? '<a href="https://SOURCE.example/path">Bad</a>' : regression === 'cdn' ? '<img src="//cdn.source.example/image.png">' : regression === 'link' ? '<a href="/?page_id=1">Bad</a>' : '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', parse: { parser: 'parse_blocks', valid: true, errors: [] } },
    ] });
    const ownerSnapshot = () => ({
      schema: THEME_OWNER_SNAPSHOT_SCHEMA,
      pages: [{ id: 1, type: 'page', status: 'publish', title: 'Home', excerpt: '', content: 'Home content', url: `${candidateOrigin}/`, meta: {} }],
      // Core creates a fallback menu the first time the probe theme renders a
      // header without one; an owner first appearing at restore is a loss.
      menus: { 5: { title: 'Primary', status: 'publish', content: 'navigation' }, ...(theme.rendered ? { 13: { title: 'Navigation', status: 'publish', content: 'page-list' } } : {}), ...(regression === 'theme-restore-addition' && theme.restored ? { 14: { title: 'Restored', status: 'publish', content: '' } } : {}) },
      media: { 9: { url: `${candidateOrigin}/uploads/img.png`, hash: 'a'.repeat(64) } },
      redirects: { ownership: regression === 'theme-redirect' && theme.active !== 'outcome-fixture' ? null : { rules: { '/legacy/': { id: 1 } } } },
      settings: {
        blogname: regression === 'theme-title' && theme.active !== 'outcome-fixture' ? 'Default Theme Title' : 'Fixture Title',
        blogdescription: 'Tagline', site_icon: 9, WPLANG: '', locale: 'en_US',
        show_on_front: 'page', page_on_front: 1, permalink_structure: '/%postname%/',
        active_theme: { stylesheet: theme.active, template: theme.active },
      },
    });
    const config = () => ({
      colourSchemes: ['light', 'dark'],
      // The source head declares what was authored: destination-owned robots
      // and generator tags pass, an echoed source directive or a replaced
      // authored description fails.
      documents: { '/': { sourceOrigin: 'https://source.example', html: `<head><meta name="robots" content="noindex"><meta name="generator" content="Source Builder">${regression === 'head-description' ? '<meta name="description" content="Authored description">' : ''}</head><nav><a href="/#fixture-panel">Information</a></nav><main></main>`, sourceContent: 'main', candidateContent: 'main' }, '/about/': { sourceOrigin: 'https://source.example', html: '<nav><a href="/#fixture-panel">Information</a></nav><main></main>', sourceContent: 'main', candidateContent: 'main' } },
      capture: { schema: 'static-site-importer/interactive-capture/v1', pages: ['/', '/about/'].map(route => ({ route, sourceUrl: `https://source.example${route}`, interactionStates: { schema: 'blocks-engine-capture/interaction-states/v2', states: [] }, navigation: [], forms: [] })) },
      sourceHosts: ['source.example', 'cdn.source.example'], aliases: ['/alias', '/legacy'], telemetryMarkers: ['source-analytics'],
      collectInventory: async () => inventory(),
      themeIndependence: {
        theme: 'twentytwentyfive',
        snapshot: async () => ownerSnapshot(),
        activate: async () => { theme.active = 'twentytwentyfive'; },
        restore: async () => { theme.active = 'outcome-fixture'; theme.restored = true; },
      },
    });
    const run = () => Object.assign(theme, { rendered: false, restored: false }) && runOutcomeChecks({ browser, candidateOrigin, routes: ['/', '/about/'], importRunId: 'fixture', config: config() });
    const passing = await run();
    // An HTTP-only fixture cannot certify the installed WordPress editor.
    assert.equal(passing.checks.block_validity.status, 'unsupported');
    assert.equal(passing.checks.block_validity.evidence[0].reason, 'installed_editor_validation_required');
    assert.equal(outcomeGate(passing).status, 'pending', JSON.stringify(passing.checks.link_graph));
    assert.equal(passing.checks.link_graph.status, 'pass');
    assert.equal(passing.checks.head_singletons.status, 'pass', JSON.stringify(passing.checks.head_singletons));
    assert.equal(passing.checks.theme_independence.status, 'pass', JSON.stringify(passing.checks.theme_independence));
    assert.ok(passing.checks.theme_independence.evidence.some(row => row.kind === 'theme_render_additions' && row.owners.join() === 'menus.13'), 'the probe theme owns the menu its render created');
    assert.ok(passing.checks.link_graph.evidence.some(row => row.surface?.startsWith('css:/') && row.violations.length === 0), 'rendered stylesheets are scanned');
    assert.ok(passing.checks.link_graph.evidence.some(row => row.surface === 'chrome:wp_navigation:1' && row.status === 'pass'), 'navigation anchors resolve');
    assert.ok(passing.checks.link_graph.evidence.filter(row => row.surface === 'chrome:wp_template_part:2' && row.status === 'pass').length === 2, 'template part anchors resolve on every route');
    assert.ok(passing.checks.theme_independence.evidence.some(row => row.phase === 'switched' && row.kind === 'route' && row.route === '/about/' && row.status === 'pass'));
    const unscoped = structuredClone(passing);
    unscoped.colour_schemes.push('dark');
    unscoped.checks.readability.evidence = unscoped.checks.readability.evidence.filter(row => row.colour_scheme !== 'dark');
    assert.equal(outcomeGate(unscoped).status, 'pending', 'scheme coverage cannot be omitted');
    const unswitched = structuredClone(passing);
    unswitched.checks.theme_independence.evidence = unswitched.checks.theme_independence.evidence.filter(row => !(row.phase === 'switched' && row.kind === 'route' && row.route === '/about/'));
    assert.equal(outcomeGate(unswitched).status, 'pending', 'switched-theme route coverage cannot be omitted');
    const retired = structuredClone(passing);
    delete retired.checks.readability;
    delete retired.checks.full_document;
    assert.equal(outcomeGate(retired).status, 'pending', 'reports without document outcomes no longer certify acceptance');
    const noTheme = structuredClone(passing);
    delete noTheme.checks.theme_independence;
    assert.equal(outcomeGate(noTheme).status, 'pending', 'reports without theme independence no longer certify acceptance');
    for (const [mutation, check] of [['link', 'link_graph'], ['source', 'link_graph'], ['cdn', 'link_graph'], ['block', 'block_validity'], ['head', 'head_singletons'], ['head-robots', 'head_singletons'], ['head-description', 'head_singletons'], ['cycle', 'link_graph'], ['chain', 'link_graph'], ['css', 'link_graph'], ['css-import', 'link_graph'], ['anchor', 'link_graph'], ['theme-title', 'theme_independence'], ['theme-redirect', 'theme_independence'], ['theme-route', 'theme_independence'], ['theme-alias', 'theme_independence'], ['theme-restore-addition', 'theme_independence']]) {
      regression = mutation;
      const report = await run();
      assert.equal(report.checks[check].status, 'fail', `${mutation}: ${JSON.stringify(report.checks[check])}`);
      assert.equal(outcomeGate(report).status, 'failed');
      const gates = Object.fromEntries(['capture', 'materialization', 'editor', 'health'].map(name => [name, { status: 'passed' }]));
      assert.equal(aggregateAcceptance({ scope: acceptanceScope({ routes: ['/', '/about/'] }), gates: { ...gates, outcomes: outcomeGate(report) }, importReportVerified: true, importReport: { schema: 'static-site-importer/import-report/v1', quality: { fallback_count: 0 } } }).status, 'failed');
    }
    regression = 'theme-route-about';
    const aboutFailure = await run();
    assert.equal(aboutFailure.checks.theme_independence.status, 'fail');
    assert.ok(aboutFailure.checks.theme_independence.evidence.some(row => row.phase === 'switched' && row.kind === 'route' && row.route === '/about/' && row.status === 'fail'));
    regression = '';
    const missing = await runOutcomeChecks({ browser, candidateOrigin, routes: ['/'] });
    assert.equal(missing.checks.block_validity.status, 'unsupported');
    assert.equal(outcomeGate(missing).status, 'pending');
    assert.equal(missing.checks.control_inventory.status, 'unsupported');
    assert.equal(missing.checks.forms.status, 'unsupported');
    assert.equal(missing.checks.theme_independence.status, 'unsupported');
    const noThemeExecutor = await runOutcomeChecks({ browser, candidateOrigin, routes: ['/'], importRunId: 'fixture', config: { ...config(), themeIndependence: undefined } });
    assert.equal(noThemeExecutor.checks.theme_independence.status, 'unsupported');
    assert.equal(outcomeGate(noThemeExecutor).status, 'pending');
    const badSnapshot = config();
    badSnapshot.themeIndependence = { ...config().themeIndependence, snapshot: async () => ({ schema: THEME_OWNER_SNAPSHOT_SCHEMA, pages: [] }) };
    const invalidSnapshot = await runOutcomeChecks({ browser, candidateOrigin, routes: ['/'], importRunId: 'fixture', config: badSnapshot });
    assert.equal(invalidSnapshot.checks.theme_independence.status, 'unsupported');
    assert.equal(outcomeGate(invalidSnapshot).status, 'pending');
  } finally { await browser?.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

test('document outcomes measure schemes, alpha, images and exact chrome decisions', async () => {
  let mutation = '';
  const server = http.createServer((req, res) => {
    res.setHeader('Content-Type', 'text/html');
    res.end(`<style>body{color:#111;background:white} @media(prefers-color-scheme:dark){body{color:white;background:#111}} .image{color:black;background-image:url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221%22 height=%221%22%3E%3Cpath fill=%22white%22 d=%22M0 0h1v1H0z%22/%3E%3C/svg%3E')}</style><header>Source header</header><main><p class="image">Image text</p><p style="${mutation === 'text' ? 'color:#eee;background:white' : mutation === 'alpha' ? 'color:rgba(0,0,0,.1);background:white' : mutation === 'unsupported' ? 'filter:blur(1px)' : mutation === 'gradient' ? 'background-image:linear-gradient(white,black)' : ''}">Readable text</p><button style="${mutation === 'button' ? 'color:white;background:white' : ''}">Control</button><p style="display:none;color:white;background:white">Hidden text</p></main><footer>Source footer</footer>${mutation === 'footer' ? '<aside>Injected widget</aside>' : ''}`);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true });
  try {
    const config = { colourSchemes: ['light', 'dark'], documents: { '/': { sourceOrigin: 'https://source.example', sourceContent: 'main', candidateContent: 'main', html: '<header>Source header</header><main></main><footer>Source footer</footer>' } } };
    const run = () => documentOutcomes({ browser, candidateOrigin: `http://127.0.0.1:${server.address().port}`, routes: ['/'], widths: [390, 1440], config });
    const baseline = await run();
    assert.equal(baseline.readability.status, 'pass', JSON.stringify(baseline));
    assert.equal(baseline.full_document.status, 'pass');
    assert.equal(baseline.full_document.evidence.length, 4);
    assert.ok(baseline.readability.evidence.some(row => row.text === 'Image text' && row.status === 'pass'));
    assert.ok(!baseline.readability.evidence.some(row => row.text === 'Hidden text'));
    for (const value of ['text', 'alpha', 'button', 'footer']) {
      mutation = value;
      const outcomes = await run();
      const check = value === 'footer' ? 'full_document' : 'readability';
      assert.equal(outcomes[check].status, 'fail', value);
      assert.equal(outcomes[check === 'readability' ? 'full_document' : 'readability'].status, 'pass');
      assert.equal(outcomeGate({ checks: outcomes }).status, 'failed');
      if (value === 'footer') {
        const row = outcomes.full_document.evidence[0];
        config.documents['/'].decisions = [{ reason: 'Owner-approved widget', expected: row.expected, actual: row.actual }];
        assert.equal((await run()).full_document.status, 'pass');
        config.documents['/'].decisions[0].reason = '';
        assert.equal((await run()).full_document.status, 'fail');
      }
    }
    mutation = 'gradient';
    const gradient = await run();
    assert.equal(gradient.readability.status, 'unsupported');
    assert.ok(gradient.readability.evidence.some(row => row.reason === 'background_image_geometry_not_measurable'));
    mutation = 'unsupported';
    const unsupported = await run();
    assert.equal(unsupported.readability.status, 'unsupported');
    assert.ok(unsupported.readability.evidence.some(row => row.reason === 'compositing_not_measurable'));
    assert.equal(outcomeGate({ checks: unsupported }).status, 'pending');
  } finally { await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

test('source inventories retain unsupported controls and require real form submissions', async () => {
  let regression = '';
  const server = http.createServer((req, res) => {
    res.setHeader('Content-Type', 'text/html');
    if (req.method === 'POST') { res.end('<p id="fixture-success">Received</p>'); return; }
    res.end(`<details id="fixture-disclosure"><summary ${regression === 'dead' ? 'onclick="event.preventDefault()"' : ''}>More</summary><p id="fixture-panel">Panel</p></details>${['dialog', 'listbox', 'tabs'].map(kind => `<button id="${kind}-trigger" onclick="document.getElementById('${kind}-target').setAttribute('data-state','active')">${kind}</button><div id="${kind}-target" data-state="inactive">${kind} content</div>`).join('')}<nav id="fixture-nav"><a href="${regression === 'nav' ? '/wrong' : '/#fixture-panel'}">Information</a></nav><form id="fixture-form" method="post" action="${regression === 'handler' ? '' : '/submit'}"><label>Email<input name="email"></label><button type="submit">Send</button></form>`);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true });
  try {
    const candidateOrigin = `http://127.0.0.1:${server.address().port}`;
    const configured = structuredClone(interactiveConfig);
    for (const kind of ['dialog', 'listbox', 'tabs']) {
      configured.capture.pages[0].interactionStates.states.push({ id: kind, kind, status: 'captured' });
      configured.controls[kind] = { trigger: `#${kind}-trigger`, target: `#${kind}-target`, attribute: 'data-state', expected: 'active' };
    }
    const run = (config = configured) => interactiveOutcomes({ browser, candidateOrigin, routes: ['/'], widths: [390, 1440], config });
    const baseline = await run();
    assert.equal(baseline.control_inventory.status, 'pass', JSON.stringify(baseline));
    assert.equal(baseline.forms.status, 'pass');
    assert.equal(baseline.control_inventory.evidence.filter(row => row.status === 'pass').length, 20);
    for (const [mutation, check] of [['dead', 'control_inventory'], ['nav', 'control_inventory'], ['handler', 'forms']]) {
      regression = mutation;
      const checks = await run();
      assert.equal(checks[check].status, 'fail');
      assert.equal(checks[check === 'forms' ? 'control_inventory' : 'forms'].status, 'pass');
      assert.equal(outcomeGate({ schema: baseline.schema, checks }).status, 'failed');
    }
    regression = '';
    const unmapped = structuredClone(interactiveConfig);
    delete unmapped.controls.disclosure;
    const unsupported = await run(unmapped);
    assert.equal(unsupported.control_inventory.status, 'unsupported');
    assert.equal(unsupported.control_inventory.unsupported.length, 4);
    assert.equal(outcomeGate({ checks: unsupported }).status, 'pending');
    const noOwner = structuredClone(interactiveConfig);
    delete noOwner.forms.contact.provider;
    assert.equal((await run(noOwner)).forms.status, 'fail');
    noOwner.forms.contact.ownerHandoff = 'Owner must configure delivery before launch: contact';
    assert.equal((await run(noOwner)).forms.status, 'pass');
    const missingRoute = structuredClone(interactiveConfig);
    missingRoute.capture.pages = [];
    assert.equal((await run(missingRoute)).forms.status, 'unsupported');
  } finally { await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
