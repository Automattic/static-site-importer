import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { chromium } from 'playwright';
import { runOutcomeChecks, outcomeGate } from '../lib/outcome-checks.mjs';
import { interactiveOutcomes } from '../lib/interactive-outcomes.mjs';
import { interactiveConfig } from '../tests/acceptance/outcome-interactive-config.mjs';
import { aggregateAcceptance, acceptanceScope } from '../lib/existing-runtime-acceptance.mjs';

// Public runner primitive, with an explicit server-parse inventory seam. Real
// WordPress collection is also exercised by the disposable acceptance workload.
test('outcome report detects independent regressions, chains and cycles', async () => {
  let regression = '';
  const server = http.createServer((req, res) => {
    if (req.url === '/alias') { res.writeHead(301, { Location: regression === 'cycle' ? '/alias' : regression === 'chain' ? '/next' : '/' }); res.end(); return; }
    if (req.url === '/next') { res.writeHead(302, { Location: '/' }); res.end(); return; }
    const origin = `http://127.0.0.1:${server.address().port}`;
    res.end(`<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="canonical" href="${origin}/"><meta name="description" content="Imported fixture"><meta property="og:title" content="Fixture"><meta property="og:url" content="${origin}/"><meta property="og:image" content="${origin}/image.png">${regression === 'head' ? '<link rel="canonical" href="https://source.example/">' : ''}</head><body>Imported fixture</body></html>`);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
    const candidateOrigin = `http://127.0.0.1:${server.address().port}`;
    const run = () => runOutcomeChecks({ browser, candidateOrigin, routes: ['/'], importRunId: 'fixture', config: { capture: { schema: 'static-site-importer/interactive-capture/v1', pages: [{ route: '/', sourceUrl: 'https://source.example/', interactionStates: { schema: 'blocks-engine-capture/interaction-states/v2', states: [] }, navigation: [], forms: [] }] }, sourceHosts: ['source.example', 'cdn.source.example'], aliases: ['/alias'], telemetryMarkers: ['source-analytics'], collectInventory: async () => ({ schema: 'static-site-importer/outcome-inventory/v1', types: ['post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation'], documents: [{ id: 1, type: 'wp_navigation', content: regression === 'source' ? '<a href="https://SOURCE.example/path">Bad</a>' : regression === 'cdn' ? '<img src="//cdn.source.example/image.png">' : regression === 'link' ? '<a href="/?page_id=1">Bad</a>' : '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', parse: { parser: 'parse_blocks', valid: regression !== 'block', errors: regression === 'block' ? [{ reason: 'stray_delimiter' }] : [] } }] }) } });
    const passing = await run();
    assert.equal(outcomeGate(passing).status, 'passed');
    const retired = structuredClone(passing);
    delete retired.checks.control_inventory;
    delete retired.checks.forms;
    assert.equal(outcomeGate(retired).status, 'pending', 'three-check reports no longer certify acceptance');
    for (const [mutation, check] of [['link', 'link_graph'], ['source', 'link_graph'], ['cdn', 'link_graph'], ['block', 'block_validity'], ['head', 'head_singletons'], ['cycle', 'link_graph'], ['chain', 'link_graph']]) {
      regression = mutation;
      const report = await run();
      assert.equal(report.checks[check].status, 'fail', mutation);
      assert.equal(outcomeGate(report).status, 'failed');
      const gates = Object.fromEntries(['capture', 'materialization', 'editor', 'health'].map(name => [name, { status: 'passed' }]));
      assert.equal(aggregateAcceptance({ scope: acceptanceScope({ routes: ['/'] }), gates: { ...gates, outcomes: outcomeGate(report) }, importReportVerified: true, importReport: { schema: 'static-site-importer/import-report/v1', quality: { fallback_count: 0 } } }).status, 'failed');
    }
    const missing = await runOutcomeChecks({ browser, candidateOrigin, routes: ['/'] });
    assert.equal(missing.checks.block_validity.status, 'unsupported');
    assert.equal(outcomeGate(missing).status, 'pending');
    assert.equal(missing.checks.control_inventory.status, 'unsupported');
    assert.equal(missing.checks.forms.status, 'unsupported');
  } finally { await browser?.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
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
