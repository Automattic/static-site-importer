import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { chromium } from 'playwright';
import { runOutcomeChecks, outcomeGate } from '../lib/outcome-checks.mjs';
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
    const run = () => runOutcomeChecks({ browser, candidateOrigin, routes: ['/'], importRunId: 'fixture', config: { sourceHosts: ['source.example', 'cdn.source.example'], aliases: ['/alias'], telemetryMarkers: ['source-analytics'], collectInventory: async () => ({ schema: 'static-site-importer/outcome-inventory/v1', types: ['post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation'], documents: [{ id: 1, type: 'wp_navigation', content: regression === 'source' ? '<a href="https://SOURCE.example/path">Bad</a>' : regression === 'cdn' ? '<img src="//cdn.source.example/image.png">' : regression === 'link' ? '<a href="/?page_id=1">Bad</a>' : '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', parse: { parser: 'parse_blocks', valid: regression !== 'block', errors: regression === 'block' ? [{ reason: 'stray_delimiter' }] : [] } }] }) } });
    const passing = await run();
    assert.equal(outcomeGate(passing).status, 'passed');
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
  } finally { await browser?.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
