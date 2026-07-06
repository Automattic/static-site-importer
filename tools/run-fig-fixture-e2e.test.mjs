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
    timeoutMs: 60000,
    perFixtureThresholds: { 'Fisiostetic': { min_native_rate: 0.95 } },
  });

  assert.equal(plan.fixture_count, 3);
  assert.equal(plan.expected_fixture_count, 3);
  assert.equal(plan.warnings.length, 0);
  assert.deepEqual(plan.steps.transform.argv.filter((arg) => arg.startsWith('--fixture=')), fixtures.map((fixture) => `--fixture=${fixture}`));
  assert.ok(plan.steps.import_matrix.argv.includes('--artifact-root'));
  assert.ok(plan.steps.import_matrix.argv.includes('--run'));
  assert.ok(plan.steps.import_matrix.argv.includes('--wp-codebox-bin'));
  assert.ok(plan.steps.import_matrix.command.includes('static-site-fixture-matrix.bench.mjs'));
  assert.equal(plan.steps.transform.timeout_ms, 60000);
  assert.equal(plan.thresholds.per_fixture.Fisiostetic.min_native_rate, 0.95);
  assert.ok(plan.artifacts.matrix_cli_run.endsWith('cli-run.json'));
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
    thresholds: { max_transform_failures: 0, max_import_failures: 0, min_native_rate: 1 },
    artifacts: {
      transform_summary: path.join(transformDirectory, 'summary.json'),
      matrix_summary: path.join(matrixDirectory, 'summary.json'),
      matrix_result: path.join(matrixDirectory, 'static-site-fixture-matrix-result.json'),
      matrix_cli_run: path.join(matrixDirectory, 'cli-run.json'),
      matrix_output_directory: matrixDirectory,
    },
    steps: {
      transform: { command: 'php figma-fixture-matrix.php' },
      import_matrix: { command: 'node static-site-fixture-matrix.bench.mjs' },
    },
    warnings: [],
  };
  fs.writeFileSync(plan.artifacts.transform_summary, JSON.stringify({ fixtures: [{ id: 'a', status: 'completed' }, { id: 'b', status: 'failed' }] }));
  fs.writeFileSync(plan.artifacts.matrix_summary, JSON.stringify({
    fixture_count: 2,
    result_summary: { succeeded: 1, failed: 1, finding_count: 4, editor_quality: { native_conversion_rate: 0.75 } },
  }));
  fs.writeFileSync(plan.artifacts.matrix_result, JSON.stringify({
    fixtures: [
      { fixture_id: 'a', status: 'passed', editor_quality: { native_conversion_rate: 1 }, artifact_refs: { source: { path: '/tmp/a/index.html' } } },
      { fixture_id: 'b', status: 'failed', editor_quality: { native_conversion_rate: 0.5 }, diagnostics: [{ kind: 'core_html' }] },
    ],
  }));

  const summary = summarizeRun({ plan, transformStatus: { status: 0 }, matrixStatus: { status: 1 } });

  assert.equal(summary.status, 'failed');
  assert.equal(summary.transform.completed_fixture_count, 1);
  assert.equal(summary.import_matrix.failed_fixture_count, 1);
  assert.equal(summary.import_matrix.min_native_conversion_rate, 0.75);
  assert.equal(summary.fixture_gates.length, 2);
  assert.equal(summary.fixture_gates.find((fixture) => fixture.fixture_id === 'a').status, 'passed');
  assert.equal(summary.fixture_gates.find((fixture) => fixture.fixture_id === 'b').status, 'failed');
  assert.equal(summary.fixture_gates.find((fixture) => fixture.fixture_id === 'a').import_matrix.artifact_refs.source.path, '/tmp/a/index.html');
  assert.ok(summary.failures.some((failure) => failure.includes('Figma transform')));
  assert.ok(summary.failures.some((failure) => failure.includes('SSI matrix')));
  assert.ok(summary.failures.some((failure) => failure.includes('native conversion rate')));
  assert.ok(summary.failures.some((failure) => failure.includes('fixture b')));
});

test('summary applies per-fixture native-rate thresholds', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-fixture-gate-'));
  const outputDirectory = path.join(root, 'artifacts');
  const transformDirectory = path.join(outputDirectory, 'figma-transform');
  const matrixDirectory = path.join(outputDirectory, 'ssi-matrix');
  fs.mkdirSync(transformDirectory, { recursive: true });
  fs.mkdirSync(matrixDirectory, { recursive: true });

  const plan = {
    fixture_count: 1,
    expected_fixture_count: 1,
    run_import_matrix: true,
    thresholds: { max_transform_failures: 0, max_import_failures: 0, min_native_rate: 1, per_fixture: { relaxed: { min_native_rate: 0.8 } } },
    artifacts: {
      transform_summary: path.join(transformDirectory, 'summary.json'),
      matrix_summary: path.join(matrixDirectory, 'summary.json'),
      matrix_result: path.join(matrixDirectory, 'static-site-fixture-matrix-result.json'),
      matrix_cli_run: path.join(matrixDirectory, 'cli-run.json'),
      matrix_output_directory: matrixDirectory,
    },
    steps: {
      transform: { command: 'php figma-fixture-matrix.php' },
      import_matrix: { command: 'node static-site-fixture-matrix.bench.mjs' },
    },
    warnings: [],
  };
  fs.writeFileSync(plan.artifacts.transform_summary, JSON.stringify({ fixtures: [{ id: 'relaxed', status: 'completed' }] }));
  fs.writeFileSync(plan.artifacts.matrix_summary, JSON.stringify({
    fixture_count: 1,
    result_summary: { succeeded: 1, failed: 0, finding_count: 0, editor_quality: { native_conversion_rate: 0.9 } },
  }));
  fs.writeFileSync(plan.artifacts.matrix_result, JSON.stringify({
    fixtures: [{ fixture_id: 'relaxed', status: 'passed', editor_quality: { native_conversion_rate: 0.9 } }],
  }));

  const summary = summarizeRun({ plan, transformStatus: { status: 0 }, matrixStatus: { status: 0 } });

  assert.equal(summary.status, 'failed');
  assert.equal(summary.fixture_gates[0].status, 'passed');
  assert.equal(summary.fixture_gates[0].thresholds.min_native_rate, 0.8);
  assert.ok(summary.failures.some((failure) => failure.includes('minimum native conversion rate 0.9 is below 1')));
});
