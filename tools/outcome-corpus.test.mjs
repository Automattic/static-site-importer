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
import { installedIdentities } from '../lib/default-outcome-toolchain.mjs';
import { execFileSync } from 'node:child_process';

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

test('installed artifact identities reject missing provenance, version drift and invalid commits', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-identities-'));
  const root = path.join(directory, 'checkout');
  const producer = path.join(directory, 'package');
  const put = (file, value) => { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, typeof value === 'string' ? value : JSON.stringify(value)); };
  try {
    put(path.join(producer, 'package.json'), { name: 'synthetic-capture', version: '1.0.0' });
    assert.throws(() => installedIdentities(root, producer), /predates installed-artifact provenance/);
    const provenance = { name: 'synthetic-capture', version: '1.0.0', commit: 'a'.repeat(40) };
    put(path.join(producer, 'provenance.json'), { ...provenance, version: '2.0.0' });
    assert.throws(() => installedIdentities(root, producer), /identity does not match/);
    put(path.join(producer, 'provenance.json'), { ...provenance, commit: 'a'.repeat(41) });
    assert.throws(() => installedIdentities(root, producer), /invalid commit/);
    put(path.join(producer, 'provenance.json'), provenance);
    put(path.join(root, 'composer.json'), { require: { 'automattic/blocks-engine-php-transformer': 'v1.0.0' } });
    put(path.join(root, 'static-site-importer.php'), '<?php // Version: 1.0.0\n');
    put(path.join(root, 'vendor/automattic/blocks-engine-php-transformer/VERSION'), '1.0.0\n');
    put(path.join(root, 'vendor/composer/installed.json'), { packages: [{ name: 'automattic/blocks-engine-php-transformer', version: 'v1.0.0' }] });
    execFileSync('git', ['init', '-q', root]);
    execFileSync('git', ['-C', root, 'add', '.']);
    execFileSync('git', ['-C', root, '-c', 'user.name=Synthetic', '-c', 'user.email=synthetic@example.test', 'commit', '-qm', 'Synthetic identity']);
    const identities = installedIdentities(root, producer);
    assert.equal(identities.ssi.dirty, false);
    assert.deepEqual(identities.capture, provenance);
    assert.equal(identities.blocks_engine.commit, 'unverified');
    assert.deepEqual(identities.blocks_engine.artifact_identity, { VERSION: '1.0.0' });
    put(path.join(root, 'dirty.txt'), 'change');
    assert.equal(installedIdentities(root, producer).ssi.dirty, true);
    put(path.join(root, 'vendor/automattic/blocks-engine-php-transformer/VERSION'), '2.0.0');
    assert.throws(() => installedIdentities(root, producer), /differs from SSI dependency pin/);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('default public CLI rejects an offline pre-provenance pack without a toolchain module', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-default-cli-'));
  try {
    fs.mkdirSync(path.join(directory, 'package'));
    fs.writeFileSync(path.join(directory, 'package/package.json'), JSON.stringify({ name: 'synthetic-capture', version: '1.0.0' }));
    const archive = path.join(directory, 'capture.tgz');
    execFileSync('tar', ['-czf', archive, '-C', directory, 'package']);
    const manifest = path.join(directory, 'manifest.json');
    fs.writeFileSync(manifest, JSON.stringify({ schema: 'static-site-importer/outcome-corpus/v1', sites: [{ id: 'one', artifact_directory: 'missing' }] }));
    const env = { ...process.env };
    delete env.SSI_OUTCOME_TOOLCHAIN;
    assert.throws(() => execFileSync(process.execPath, ['tools/run-outcome-corpus.mjs', '--manifest', manifest, '--output', path.join(directory, 'run'), '--producer-tarball', archive], { env, encoding: 'utf8', stdio: 'pipe' }), error => {
      assert.equal(error.status, 1);
      assert.match(error.stderr, /predates installed-artifact provenance/);
      assert.doesNotMatch(error.stderr, /Declare.*SSI_OUTCOME_TOOLCHAIN/);
      return true;
    });
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
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
