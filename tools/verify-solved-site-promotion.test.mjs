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
  const artifactFiles = Object.fromEntries([
    ['editor', 'a1111111-editor.png'], ['source', 'b2222222-source.png'], ['candidate', 'c3333333-candidate.png'], ['diff', 'd4444444-diff.png'], ['visualDiff', 'e5555555-visual-diff.json'],
  ].map(([key, file]) => [key, path.join(root, 'harvested', file)]));
  fs.mkdirSync(path.join(root, 'harvested'));
  for (const file of Object.values(artifactFiles)) fs.writeFileSync(file, path.basename(file));
  const matrix = {
    schema: 'static-site-importer/fixture-matrix-result/v1',
    summary: { generation_status: 'succeeded', execution_status: 'requested', fixture_count: 1, failed: 0, not_run: 0, solved_candidate_gate: { enabled: true, failed_fixture_count: 0 } },
    fixtures: [{
      fixture_id: 'solved', status: 'passed', success: true,
      quality_metrics: { pass: true, fallback_count: 0, core_html_block_count: 0, freeform_block_count: 0, invalid_block_count: 0 },
      block_composition: { block_total: 4, native_block_count: 4, core_html_block_count: 0 },
      editor_validation: { validation_method: 'wp.blocks.validateBlock', total_blocks: 4, valid_blocks: 4, invalid_blocks: 0 },
      editor_canvas: { status: 'captured', screenshot: '/producer/editor.png' },
      visual_parity_artifacts: { metrics: { mismatch_ratio: 0, mismatch_pixels: 0 }, artifacts: Object.fromEntries([
        ['source_screenshot', 'source.png'], ['imported_screenshot', 'candidate.png'], ['diff_screenshot', 'diff.png'], ['visual_diff', 'visual-diff.json'],
      ].map(([slot, file]) => [slot, { status: 'captured', ref: { path: `/producer/${file}` } }])) },
      matrix_evidence: { readiness: 'verified', missing: [], transformer: { package_reference: BE_SHA }, materialization_receipt: { status: 'completed', plan_hash: 'abc' } },
      editor_quality: { native_conversion_rate: 1 },
    }],
  };
  const registry = { schema: 'static-site-importer/gutenberg-incompatibility-registry/v1', fixture_decisions: [{ fixture_id: 'solved', acceptance_status: 'solved_candidate' }] };
  const runtime = { nodeVersion: '20.19.4', phpVersion: '8.1.29', wordpressVersion: '7.0.2', homeboyVersion: 'v0.298.1', homeboySha256: '3'.repeat(64), homeboyExtensionsRef: '4'.repeat(40), wpCodeboxVersion: 'v0.12.29', wpCodeboxSha256: '5'.repeat(64), staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA };
  const artifactIndex = { schema: 'homeboy/command-result/v3', data: { payload: { artifacts: [
    ['editor_canvas_solved_editor.png', artifactFiles.editor], ['visual_compare_solved_source', artifactFiles.source], ['visual_compare_solved_candidate', artifactFiles.candidate], ['visual_compare_solved_diff', artifactFiles.diff], ['visual_compare_solved_visual-diff.json', artifactFiles.visualDiff],
  ].map(([name, file]) => ({ name, path: `/relocated/${path.basename(file)}` })) } } };
  const paths = { matrix: path.join(root, 'matrix.json'), registry: path.join(root, 'registry.json'), runtime: path.join(root, 'runtime.json'), artifactIndex: path.join(root, 'homeboy-bench-result.json') };
  write(paths.matrix, matrix); write(paths.registry, registry); write(paths.runtime, runtime); write(paths.artifactIndex, artifactIndex);
  return { root, artifactFiles, matrix, registry, runtime, artifactIndex, paths, options: { matrixResult: paths.matrix, registry: paths.registry, runtimeInputs: paths.runtime, artifactIndex: paths.artifactIndex, artifactRoot: root, staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA, fixtureTreeSha: '6'.repeat(40), solvedFixtureCount: 1, runUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123', artifactUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123#artifacts', output: path.join(root, 'receipt.json'), manifestOutput: path.join(root, 'manifest.json') } };
}

test('issues an accepted immutable promotion receipt', () => {
  const input = fixture();
  const receipt = verifySolvedSitePromotion(input.options);
  assert.equal(receipt.status, 'accepted');
  assert.equal(receipt.candidate.blocks_engine_sha, BE_SHA);
  assert.equal(receipt.corpus.selected_fixture_count, 1);
  assert.ok(receipt.evidence.artifacts.every((row) => /^[a-f0-9]{64}$/.test(row.sha256)));
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
  ['unregistered evidence', (input) => { input.artifactIndex.data.payload.artifacts = input.artifactIndex.data.payload.artifacts.filter((artifact) => artifact.name !== 'visual_compare_solved_diff'); }, /exactly one registered artifact/],
  ['duplicate registration', (input) => { input.artifactIndex.data.payload.artifacts.push({ ...input.artifactIndex.data.payload.artifacts[0] }); }, /exactly one registered artifact/],
  ['missing evidence file', (input) => { fs.unlinkSync(input.artifactFiles.diff); }, /could not be resolved uniquely/],
]) {
  test(`fails closed for ${name}`, () => {
    const input = fixture(); mutate(input); write(input.paths.matrix, input.matrix); write(input.paths.registry, input.registry); write(input.paths.runtime, input.runtime); write(input.paths.artifactIndex, input.artifactIndex);
    assert.throws(() => verifySolvedSitePromotion(input.options), pattern);
  });
}

function write(file, value) { fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }
