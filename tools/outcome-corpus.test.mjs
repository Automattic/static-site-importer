import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { outcomeMatrix, outcomeMatrixMarkdown } from '../lib/outcome-matrix.mjs';
import { validateCorpus, runOutcomeCorpus } from './run-outcome-corpus.mjs';
import { unavailableOutcomes } from '../lib/outcome-checks.mjs';
import { build } from 'esbuild';
import { chromium } from 'playwright';
import { validateOutcomeDocuments } from '../lib/outcome-editor-validation.mjs';
import http from 'node:http';

test('saved documents use real client parser and save-contract validation for every owner type', async () => {
  const bundle = await build({ stdin: { contents: `import * as blocks from '@wordpress/blocks'; import {createElement} from '@wordpress/element'; window.wp={blocks}; blocks.registerBlockType('synthetic/text', {title:'Text', category:'text', attributes:{text:{type:'string',source:'text',selector:'p'}}, save:({attributes})=>createElement('p',null,attributes.text)});`, resolveDir: process.cwd() }, bundle: true, write: false, format: 'iife', define: { 'process.env.NODE_ENV': '"production"' } });
  const server = http.createServer((request, response) => {
    if (request.url === '/editor.js') { response.setHeader('Content-Type', 'text/javascript'); response.end(bundle.outputFiles[0].text); }
    else { response.setHeader('Content-Type', 'text/html'); response.end('<!doctype html><script src="/editor.js"></script>'); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    const candidateOrigin = `http://127.0.0.1:${server.address().port}`;
    const documents = ['post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation'].map((type, id) => ({ type, id, content: '<!-- wp:synthetic/text --><p>Hello</p><!-- /wp:synthetic/text -->' }));
    const baseline = await validateOutcomeDocuments({ page, candidateOrigin, documents });
    assert.equal(baseline.length, documents.length);
    assert.ok(baseline.every(row => row.validation.total_blocks === 1 && row.validation.invalid_blocks === 0));
    documents[4].content = '<!-- wp:synthetic/text --><h2>Hello</h2><!-- /wp:synthetic/text -->';
    const changed = await validateOutcomeDocuments({ page, candidateOrigin, documents });
    assert.deepEqual(changed.filter(row => row.validation.invalid_blocks > 0).map(row => row.type), ['wp_navigation']);
    assert.ok(changed[4].diagnostics.length > 0);
  } finally { await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

test('private corpus IDs and source selection are unambiguous', () => {
  const manifest = JSON.parse(fs.readFileSync(new URL('../docs/contracts/outcome-corpus-v1.schema.json', import.meta.url), 'utf8'));
  assert.equal(manifest.properties.schema.const, 'static-site-importer/outcome-corpus/v1');
  const corpus = { schema: manifest.properties.schema.const, sites: [{ id: 'one', artifact_directory: 'capture' }, { id: 'two', source_url: 'https://source.example/' }] };
  assert.equal(validateCorpus(corpus), corpus);
  assert.throws(() => validateCorpus({ ...corpus, sites: [corpus.sites[0], corpus.sites[0]] }), /duplicate/);
  assert.throws(() => validateCorpus({ ...corpus, sites: [{ ...corpus.sites[0], source_url: 'https://source.example/' }] }), /choose/);
});

test('matrix never promotes unavailable and compares single-cell regressions', () => {
  const report = unavailableOutcomes('', ['/'], 'synthetic');
  for (const check of Object.values(report.checks)) check.status = 'pass';
  const imports = ['one', 'two'].map(id => ({ id, report: structuredClone(report), evidence: `${id}/report.json` }));
  const baseline = outcomeMatrix(imports);
  assert.equal(baseline.status, 'passed');
  imports[0].report.checks.link_graph.status = 'fail';
  const changed = outcomeMatrix(imports, baseline);
  assert.equal(changed.status, 'failed');
  assert.equal(changed.changes.length, 1);
  assert.equal(changed.changes[0].check, 'link_graph');
  assert.equal(changed.changes[0].site, 'one');
  assert.match(outcomeMatrixMarkdown(changed), /fail \[evidence\]\(one\/report.json#\/checks\/link_graph\)/);
  delete imports[1].report.checks.forms;
  const missing = outcomeMatrix(imports);
  assert.equal(missing.cells.forms.two.status, 'unavailable');
  assert.equal(missing.cells.forms.two.reason, 'outcome_evidence_missing');
});

test('corpus runner retains unavailable evidence when capture intake cannot run', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-corpus-'));
  try {
    const manifest = path.join(directory, 'manifest.json');
    fs.writeFileSync(manifest, JSON.stringify({ schema: 'static-site-importer/outcome-corpus/v1', sites: [{ id: 'one', artifact_directory: 'missing-artifact' }, { id: 'two', artifact_directory: 'also-missing' }] }));
    const identity = { version: '1.0.0', commit: 'a'.repeat(40) };
    const matrix = await runOutcomeCorpus({ manifest, output: path.join(directory, 'run'), toolchain: { identities: { capture: identity, ssi: identity, blocks_engine: identity } } });
    assert.equal(matrix.status, 'failed');
    assert.deepEqual(matrix.sites, ['one', 'two']);
    assert.equal(matrix.cells.link_graph.one.status, 'unavailable');
    const report = JSON.parse(fs.readFileSync(path.join(directory, 'run/one/report.json'), 'utf8'));
    assert.deepEqual(report.versions.capture, identity);
    assert.ok(report.failure);
    assert.ok(fs.existsSync(path.join(directory, 'run/matrix.md')));
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
