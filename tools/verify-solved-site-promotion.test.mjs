import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { verifySolvedSitePromotion } from './verify-solved-site-promotion.mjs';

const SSI_SHA = '1'.repeat(40);
const BE_SHA = '2'.repeat(40);

function fixture() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-promotion-'));
  for (const file of ['editor.png', 'source.png', 'candidate.png', 'diff.png', 'visual-diff.json']) fs.writeFileSync(path.join(root, file), file);
  const matrix = {
    schema: 'static-site-importer/fixture-matrix-result/v1',
    summary: { generation_status: 'succeeded', execution_status: 'requested', fixture_count: 1, failed: 0, not_run: 0, solved_candidate_gate: { enabled: true, failed_fixture_count: 0 } },
    fixtures: [{
      fixture_id: 'solved', status: 'passed', success: true,
      quality_metrics: { pass: true, fallback_count: 0, core_html_block_count: 0, freeform_block_count: 0, invalid_block_count: 0 },
      block_composition: { block_total: 4, native_block_count: 4, core_html_block_count: 0 },
      editor_validation: { validation_method: 'wp.blocks.validateBlock', total_blocks: 4, valid_blocks: 4, invalid_blocks: 0 },
      editor_canvas: { status: 'captured', screenshot: path.join(root, 'editor.png') },
      visual_parity_artifacts: { metrics: { mismatch_ratio: 0, mismatch_pixels: 0 }, artifacts: Object.fromEntries([
        ['source_screenshot', 'source.png'], ['imported_screenshot', 'candidate.png'], ['diff_screenshot', 'diff.png'], ['visual_diff', 'visual-diff.json'],
      ].map(([slot, file]) => [slot, { status: 'captured', ref: { path: path.join(root, file) } }])) },
      matrix_evidence: { readiness: 'verified', missing: [], transformer: { package_reference: BE_SHA }, materialization_receipt: { status: 'completed', plan_hash: 'abc' } },
      editor_quality: { native_conversion_rate: 1 },
    }],
  };
  const registry = { schema: 'static-site-importer/gutenberg-incompatibility-registry/v1', fixture_decisions: [{ fixture_id: 'solved', acceptance_status: 'solved_candidate' }] };
  const runtime = { nodeVersion: '20.19.4', phpVersion: '8.1.29', wordpressVersion: '7.0.2', homeboyVersion: 'v0.298.1', homeboySha256: '3'.repeat(64), homeboyExtensionsRef: '4'.repeat(40), wpCodeboxVersion: 'v0.12.29', wpCodeboxSha256: '5'.repeat(64), staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA };
  const paths = { matrix: path.join(root, 'matrix.json'), registry: path.join(root, 'registry.json'), runtime: path.join(root, 'runtime.json') };
  write(paths.matrix, matrix); write(paths.registry, registry); write(paths.runtime, runtime);
  return { root, matrix, registry, runtime, paths, options: { matrixResult: paths.matrix, registry: paths.registry, runtimeInputs: paths.runtime, artifactRoot: root, staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA, fixtureTreeSha: '6'.repeat(40), solvedFixtureCount: 1, runUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123', artifactUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123#artifacts', output: path.join(root, 'receipt.json'), manifestOutput: path.join(root, 'manifest.json') } };
}

test('issues an accepted immutable promotion receipt', () => {
  const input = fixture();
  const receipt = verifySolvedSitePromotion(input.options);
  assert.equal(receipt.status, 'accepted');
  assert.equal(receipt.candidate.blocks_engine_sha, BE_SHA);
  assert.equal(receipt.corpus.selected_fixture_count, 1);
  assert.ok(receipt.evidence.artifacts.every((row) => /^[a-f0-9]{64}$/.test(row.sha256)));
});

test('resolves uniquely named durable copies of transient runtime evidence', () => {
  const input = fixture();
  const durableEditor = path.join(input.root, 'uuid-editor.png');
  fs.renameSync(path.join(input.root, 'editor.png'), durableEditor);
  input.matrix.fixtures[0].editor_canvas.screenshot = '/transient/homeboy/editor.png';
  write(input.paths.matrix, input.matrix);
  const receipt = verifySolvedSitePromotion(input.options);
  assert.ok(receipt.evidence.artifacts.some((row) => row.path === 'uuid-editor.png'));
});

test('materializes host runtime evidence into the durable artifact root', () => {
  const input = fixture();
  const externalRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-promotion-runtime-'));
  const externalEditor = path.join(externalRoot, 'editor.png');
  fs.writeFileSync(externalEditor, 'runtime editor screenshot');
  input.matrix.fixtures[0].editor_canvas.screenshot = externalEditor;
  write(input.paths.matrix, input.matrix);
  const receipt = verifySolvedSitePromotion(input.options);
  const artifact = receipt.evidence.artifacts.find((row) => row.path.endsWith('-editor.png'));
  assert.match(artifact?.path || '', /^runtime-evidence\/[a-f0-9]{64}-editor\.png$/);
  assert.equal(fs.readFileSync(path.join(input.root, artifact.path), 'utf8'), 'runtime editor screenshot');
});

for (const [name, mutate, pattern] of [
  ['empty corpus', (input) => { input.matrix.fixtures = []; input.matrix.summary.fixture_count = 0; }, /non-empty/],
  ['failed decision', (input) => { input.registry.fixture_decisions[0].acceptance_status = 'visual_only_blocker'; }, /solved_candidate/],
  ['partial receipt', (input) => { input.matrix.fixtures[0].matrix_evidence.materialization_receipt.status = 'partial'; }, /materialization receipt/],
  ['zero editor blocks', (input) => { input.matrix.fixtures[0].editor_validation.total_blocks = 0; input.matrix.fixtures[0].editor_validation.valid_blocks = 0; }, /editor validation/],
  ['visual mismatch', (input) => { input.matrix.fixtures[0].visual_parity_artifacts.metrics.mismatch_pixels = 1; }, /visual mismatch/],
  ['fallback block', (input) => { input.matrix.fixtures[0].quality_metrics.core_html_block_count = 1; }, /core_html_block_count/],
  ['non-native conversion', (input) => { input.matrix.fixtures[0].editor_quality.native_conversion_rate = 0.99; }, /native conversion rate/],
  ['transformer mismatch', (input) => { input.matrix.fixtures[0].matrix_evidence.transformer.package_reference = '7'.repeat(40); }, /provenance/],
  ['unpinned runtime', (input) => { input.runtime.wordpressVersion = 'latest'; }, /pinned/],
  ['missing evidence file', (input) => { fs.unlinkSync(path.join(input.root, 'diff.png')); }, /missing/],
]) {
  test(`fails closed for ${name}`, () => {
    const input = fixture(); mutate(input); write(input.paths.matrix, input.matrix); write(input.paths.registry, input.registry); write(input.paths.runtime, input.runtime);
    assert.throws(() => verifySolvedSitePromotion(input.options), pattern);
  });
}

function write(file, value) { fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }
