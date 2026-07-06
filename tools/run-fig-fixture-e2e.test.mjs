import assert from 'node:assert/strict';
import fs from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { buildFigFixtureE2EPlan, summarizeRun } from './run-fig-fixture-e2e.mjs';

test('builds deterministic fig transform and SSI import matrix commands for three fixtures', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-plan-'));
  const blocksEngine = path.join(root, 'blocks-engine');
  const staticSiteImporter = path.join(root, 'static-site-importer');
  fs.mkdirSync(path.join(blocksEngine, 'figma-transformer', 'scripts'), { recursive: true });
  fs.mkdirSync(path.join(staticSiteImporter, 'bench'), { recursive: true });
  fs.writeFileSync(path.join(blocksEngine, 'figma-transformer', 'scripts', 'figma-fixture-matrix.php'), '<?php');
  fs.writeFileSync(path.join(staticSiteImporter, 'bench', 'static-site-fixture-matrix.bench.mjs'), '');

  const fixtures = ['Fisiostetic.fig', 'FSE Pilot Build Theme.fig', 'Twenty Twenty-Five.fig'].map((name) => path.join(root, name));
  for (const fixture of fixtures) {
    fs.writeFileSync(fixture, 'fig');
  }

  const plan = buildFigFixtureE2EPlan({
    blocksEngine,
    staticSiteImporter,
    fixtures,
    outputDirectory: path.join(root, 'artifacts'),
    run: true,
    wpCodeboxBin: '/usr/local/bin/wp-codebox',
    maxTransformVectorPlaceholders: 2,
    maxImportFindings: 5,
  });

  assert.equal(plan.fixture_count, 3);
  assert.equal(plan.expected_fixture_count, 3);
  assert.equal(plan.warnings.length, 0);
  assert.deepEqual(plan.steps.transform.argv.filter((arg) => arg.startsWith('--fixture=')), fixtures.map((fixture) => `--fixture=${fixture}`));
  assert.ok(plan.steps.import_matrix.argv.includes('--artifact-root'));
  assert.ok(plan.steps.import_matrix.argv.includes('--run'));
  assert.ok(plan.steps.import_matrix.argv.includes('--wp-codebox-bin'));
  assert.ok(plan.steps.import_matrix.command.includes('static-site-fixture-matrix.bench.mjs'));
  assert.equal(plan.thresholds.max_transform_vector_placeholders, 2);
  assert.equal(plan.thresholds.max_import_findings, 5);
});

test('summary fails when transform/import thresholds are not met', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-summary-'));
  const outputDirectory = path.join(root, 'artifacts');
  const transformDirectory = path.join(outputDirectory, 'figma-transform');
  const matrixDirectory = path.join(outputDirectory, 'ssi-matrix');
  fs.mkdirSync(transformDirectory, { recursive: true });
  fs.mkdirSync(matrixDirectory, { recursive: true });

  const plan = {
    fixture_count: 3,
    expected_fixture_count: 3,
    run_import_matrix: true,
    thresholds: {
      max_transform_failures: 0,
      max_import_failures: 0,
      min_native_rate: 1,
      max_transform_vector_placeholders: 0,
      max_transform_missing_assets: 0,
      max_import_findings: 3,
      max_baseline_regression_ratio: null,
    },
    artifacts: {
      transform_summary: path.join(transformDirectory, 'summary.json'),
      matrix_summary: path.join(matrixDirectory, 'summary.json'),
      matrix_result: path.join(matrixDirectory, 'static-site-fixture-matrix-result.json'),
      matrix_output_directory: matrixDirectory,
    },
    steps: {
      transform: { command: 'php figma-fixture-matrix.php' },
      import_matrix: { command: 'node static-site-fixture-matrix.bench.mjs' },
    },
    warnings: [],
  };
  fs.writeFileSync(plan.artifacts.transform_summary, JSON.stringify({
    fixtures: [
      { id: 'a', status: 'completed', duration_ms: 100, vector_placeholders: 1 },
      { id: 'b', status: 'failed', duration_ms: 200, missing_asset_count: 1 },
    ],
  }));
  fs.writeFileSync(plan.artifacts.matrix_summary, JSON.stringify({
    fixture_count: 2,
    result_summary: { succeeded: 1, failed: 1, finding_count: 4, editor_quality: { native_conversion_rate: 0.75 } },
  }));

  const summary = summarizeRun({ plan, transformStatus: { status: 0, duration_ms: 350 }, matrixStatus: { status: 1, duration_ms: 450 } });

  assert.equal(summary.status, 'failed');
  assert.equal(summary.metrics.transform_duration_ms, 350);
  assert.equal(summary.metrics.import_matrix_duration_ms, 450);
  assert.equal(summary.metrics.transform_vector_placeholder_count, 1);
  assert.equal(summary.metrics.transform_missing_asset_count, 1);
  assert.equal(summary.transform.completed_fixture_count, 1);
  assert.equal(summary.import_matrix.failed_fixture_count, 1);
  assert.equal(summary.import_matrix.min_native_conversion_rate, 0.75);
  assert.ok(summary.failures.some((failure) => failure.includes('Figma transform')));
  assert.ok(summary.failures.some((failure) => failure.includes('SSI matrix')));
  assert.ok(summary.failures.some((failure) => failure.includes('native conversion rate')));
  assert.ok(summary.failures.some((failure) => failure.includes('vector placeholder')));
  assert.ok(summary.failures.some((failure) => failure.includes('missing asset')));
  assert.ok(summary.failures.some((failure) => failure.includes('SSI finding')));
});

test('summary compares staged metrics against a baseline when requested', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-baseline-'));
  const outputDirectory = path.join(root, 'artifacts');
  const transformDirectory = path.join(outputDirectory, 'figma-transform');
  const matrixDirectory = path.join(outputDirectory, 'ssi-matrix');
  fs.mkdirSync(transformDirectory, { recursive: true });
  fs.mkdirSync(matrixDirectory, { recursive: true });
  const baselineSummary = path.join(root, 'baseline-summary.json');
  fs.writeFileSync(baselineSummary, JSON.stringify({
    metrics: {
      transform_duration_ms: 100,
      import_matrix_duration_ms: 200,
      total_duration_ms: 300,
      import_matrix_finding_count: 1,
    },
  }));

  const plan = {
    fixture_count: 3,
    expected_fixture_count: 3,
    run_import_matrix: true,
    baseline_summary: baselineSummary,
    thresholds: {
      max_transform_failures: 0,
      max_import_failures: 0,
      min_native_rate: 1,
      max_transform_vector_placeholders: 0,
      max_transform_missing_assets: 0,
      max_import_findings: null,
      max_baseline_regression_ratio: 0.25,
    },
    artifacts: {
      transform_summary: path.join(transformDirectory, 'summary.json'),
      matrix_summary: path.join(matrixDirectory, 'summary.json'),
      matrix_result: path.join(matrixDirectory, 'static-site-fixture-matrix-result.json'),
      matrix_output_directory: matrixDirectory,
    },
    steps: {
      transform: { command: 'php figma-fixture-matrix.php' },
      import_matrix: { command: 'node static-site-fixture-matrix.bench.mjs' },
    },
    warnings: [],
  };
  fs.writeFileSync(plan.artifacts.transform_summary, JSON.stringify({ fixtures: [{ id: 'a', status: 'completed' }] }));
  fs.writeFileSync(plan.artifacts.matrix_summary, JSON.stringify({
    fixture_count: 1,
    result_summary: { succeeded: 1, failed: 0, finding_count: 1, editor_quality: { native_conversion_rate: 1 } },
  }));

  const summary = summarizeRun({ plan, transformStatus: { status: 0, duration_ms: 140 }, matrixStatus: { status: 0, duration_ms: 200 } });

  assert.equal(summary.status, 'failed');
  assert.equal(summary.baseline_comparison.compared_metric_count, 4);
  assert.equal(summary.baseline_comparison.regressions[0].metric, 'transform_duration_ms');
  assert.ok(summary.failures.some((failure) => failure.includes('transform_duration_ms regressed')));
});
