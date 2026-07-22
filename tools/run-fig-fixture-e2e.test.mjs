/**
 * External dependencies
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

/**
 * Internal dependencies
 */
import { buildFigFixtureE2EPlan, summarizeRun, writeFigAcceptanceManifest } from './run-fig-fixture-e2e.mjs';
import { buildFigAcceptanceProvider, STAGES } from './fig-acceptance-provider.mjs';

test('builds deterministic fig transform and SSI import matrix commands for three fixtures', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-plan-'));
  const blocksEngine = path.join(root, 'blocks-engine');
  const staticSiteImporter = path.join(root, 'static-site-importer');
  fs.mkdirSync(path.join(blocksEngine, 'figma-transformer', 'scripts'), { recursive: true });
  fs.mkdirSync(path.join(blocksEngine, 'scripts'), { recursive: true });
  fs.mkdirSync(path.join(staticSiteImporter, 'bench'), { recursive: true });
  fs.writeFileSync(path.join(blocksEngine, 'figma-transformer', 'scripts', 'figma-fixture-matrix.php'), '<?php');
  fs.writeFileSync(path.join(blocksEngine, 'scripts', 'production-acceptance-matrix.php'), '<?php');
  fs.writeFileSync(path.join(staticSiteImporter, 'bench', 'static-site-fixture-matrix.bench.mjs'), '');

  const fixtures = ['Fisiostetic.fig', 'FSE Pilot Build Theme.fig', 'Twenty Twenty-Five.fig'].map((name) => path.join(root, name));
  for (const fixture of fixtures) {
    fs.writeFileSync(fixture, 'fig');
  }
  const acceptanceConfig = path.join(root, 'acceptance-config.json');
  fs.writeFileSync(acceptanceConfig, '{}');

  const plan = buildFigFixtureE2EPlan({
    blocksEngine,
    staticSiteImporter,
    fixtures,
    outputDirectory: path.join(root, 'artifacts'),
    run: true,
    wpCodeboxBin: '/usr/local/bin/wp-codebox',
    maxTransformVectorPlaceholders: 2,
    maxImportFindings: 5,
    acceptanceConfig,
  });

  assert.equal(plan.fixture_count, 3);
  assert.equal(plan.expected_fixture_count, 3);
  assert.equal(plan.warnings.length, 0);
  assert.deepEqual(plan.steps.transform.argv.filter((arg) => arg.startsWith('--fixture=')), fixtures.map((fixture) => `--fixture=${fixture}`));
  assert.ok(plan.steps.import_matrix.argv.includes('--artifact-root'));
  assert.ok(plan.steps.import_matrix.argv.includes('--run'));
  assert.ok(plan.steps.import_matrix.argv.includes('--wp-codebox-bin'));
  assert.ok(plan.steps.import_matrix.command.includes('static-site-fixture-matrix.bench.mjs'));
  assert.ok(plan.steps.acceptance_matrix.command.includes('production-acceptance-matrix.php'));
  assert.equal(plan.steps.acceptance_matrix.argv.includes('--no-run-providers'), false);
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

test('summary consumes root-level fixture accounting and fails the real matrix result', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-root-summary-'));
  const matrixSummary = path.join(root, 'summary.json');
  fs.writeFileSync(matrixSummary, JSON.stringify({
    fixture_count: 3,
    succeeded: 0,
    failed: 3,
    not_run: 0,
    finding_count: 19,
  }));
  const plan = summaryPlan(root, matrixSummary);

  const summary = summarizeRun({ plan, transformStatus: null, matrixStatus: { status: 0, duration_ms: 1 } });

  assert.equal(summary.status, 'failed');
  assert.equal(summary.import_matrix.failed_fixture_count, 3);
  assert.equal(summary.import_matrix.finding_count, 19);
  assert.ok(summary.failures.includes('3 SSI matrix fixture(s) failed'));
});

test('summary fails closed when fixture accounting is absent or incomplete', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-fig-e2e-invalid-summary-'));
  const matrixSummary = path.join(root, 'summary.json');
  fs.writeFileSync(matrixSummary, JSON.stringify({ fixture_count: 3 }));
  const plan = summaryPlan(root, matrixSummary);

  const summary = summarizeRun({ plan, transformStatus: null, matrixStatus: { status: 0, duration_ms: 1 } });

  assert.equal(summary.status, 'failed');
  assert.ok(summary.failures.includes('SSI matrix summary is missing fixture result accounting'));
});

test('adapts a complete concrete three-Fig fixture bundle to all Blocks Engine acceptance stages', () => {
  const bundle = acceptanceBundle();
  const manifest = buildFigAcceptanceProvider(bundle.input);

  assert.equal(manifest.id, 'fisiostetic');
  assert.equal(Object.keys(manifest.evidence).length, 13);
  assert.deepEqual(Object.keys(manifest.evidence), STAGES);
  for (const stage of STAGES) {
    const evidence = JSON.parse(fs.readFileSync(manifest.evidence[stage], 'utf8'));
    assert.equal(evidence.schema, 'blocks-engine/figma-wordpress-stage-evidence/v1');
    assert.equal(evidence.fixture_id, 'fisiostetic');
    assert.equal(evidence.stage, stage);
    assert.equal(evidence.status, 'passed');
    assert.ok(evidence.references.every((ref) => !path.isAbsolute(ref) && fs.existsSync(path.join(bundle.acceptanceRoot, ref))));
  }
  assert.equal(JSON.parse(fs.readFileSync(manifest.evidence.fallback, 'utf8')).fallback_count, 0);
  assert.equal(JSON.parse(fs.readFileSync(manifest.evidence.figma_html_desktop_parity, 'utf8')).comparison, 'figma_html');
  assert.equal(JSON.parse(fs.readFileSync(manifest.evidence.html_wordpress_desktop_parity, 'utf8')).comparison, 'html_wordpress');
  assert.equal(JSON.parse(fs.readFileSync(manifest.evidence.figma_wordpress_desktop_parity, 'utf8')).comparison, 'figma_wordpress');
});

test('acceptance provider fails closed for missing parity proof and mismatched fixture evidence', () => {
  const missing = acceptanceBundle();
  fs.rmSync(missing.paths.parity.htmlWordpressMobileParity);
  assert.throws(() => buildFigAcceptanceProvider(missing.input), /provider input is missing or unreadable/);

  const mismatch = acceptanceBundle();
  const matrix = JSON.parse(fs.readFileSync(mismatch.input.matrixResult, 'utf8'));
  matrix.fixtures[0].fixture_id = 'another-fixture';
  fs.writeFileSync(mismatch.input.matrixResult, JSON.stringify(matrix));
  assert.throws(() => buildFigAcceptanceProvider(mismatch.input), /matrix evidence has no fixture fisiostetic/);
});

test('acceptance provider rejects the invented acceptance_evidence-only shape', () => {
  const bundle = acceptanceBundle();
  fs.writeFileSync(bundle.input.transformSummary, JSON.stringify({ fixtures: [{ id: 'fisiostetic', status: 'completed', acceptance_evidence: {} }] }));
  assert.throws(() => buildFigAcceptanceProvider(bundle.input), /transform output_dir is required/);
});

test('acceptance provider rejects Figma stage evidence for another source file', () => {
  const bundle = acceptanceBundle();
  const summary = JSON.parse(fs.readFileSync(bundle.input.transformSummary, 'utf8'));
  const decodePath = summary.fixtures[0].acceptance_readiness.stage_paths.decode;
  const decode = JSON.parse(fs.readFileSync(decodePath, 'utf8'));
  decode.source_sha256 = '0'.repeat(64);
  fs.writeFileSync(decodePath, JSON.stringify(decode));
  assert.throws(() => buildFigAcceptanceProvider(bundle.input), /decode evidence does not match --fig/);
});

test('provider bundle conforms to the documented Blocks Engine stage schema', () => {
  const bundle = acceptanceBundle();
  const manifest = buildFigAcceptanceProvider(bundle.input);
  for (const stage of STAGES) {
    const evidence = JSON.parse(fs.readFileSync(manifest.evidence[stage], 'utf8'));
    assert.ok(['schema', 'fixture_id', 'stage', 'source_sha256', 'status', 'references'].every((key) => Object.hasOwn(evidence, key)));
    assert.equal(Object.hasOwn(evidence, 'acceptance_evidence'), false);
    assert.equal(evidence.schema, 'blocks-engine/figma-wordpress-stage-evidence/v1');
    assert.equal(evidence.status, 'passed');
  }
});

test('three-Fig workflow writes a combined evaluator manifest from provider config', () => {
  const bundle = acceptanceBundle();
  const config = {
    provider_identity: bundle.input.providerIdentity,
    runtime_identity: bundle.input.runtimeIdentity,
    fixtures: {
      fisiostetic: {
        site_plan: bundle.input.sitePlan,
        html_wordpress_mobile_parity: bundle.input.htmlWordpressMobileParity,
        figma_wordpress_desktop_parity: bundle.input.figmaWordpressDesktopParity,
        figma_wordpress_mobile_parity: bundle.input.figmaWordpressMobileParity,
      },
    },
  };
  const configPath = path.join(bundle.acceptanceRoot, 'config.json');
  fs.mkdirSync(bundle.acceptanceRoot, { recursive: true });
  fs.writeFileSync(configPath, JSON.stringify(config));
  const manifestPath = path.join(bundle.acceptanceRoot, 'manifest.json');
  const manifest = writeFigAcceptanceManifest({
    expected_fixture_count: 1,
    artifacts: { transform_summary: bundle.input.transformSummary, matrix_result: bundle.input.matrixResult, matrix_output_directory: bundle.input.matrixOutput },
    acceptance: { config_path: configPath, output_directory: bundle.acceptanceRoot, manifest_path: manifestPath },
  });
  assert.equal(manifest.fixtures.length, 1);
  assert.equal(Object.keys(manifest.fixtures[0].evidence).length, 13);
  assert.deepEqual(JSON.parse(fs.readFileSync(manifestPath, 'utf8')).fixtures.map((fixture) => fixture.id), ['fisiostetic']);
});

function acceptanceBundle() {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'ssi-acceptance-provider-'));
  const inputRoot = path.join(root, 'input');
  const acceptanceRoot = path.join(root, 'acceptance');
  const fixtureOutput = path.join(acceptanceRoot, 'fixtures', 'fisiostetic');
  fs.mkdirSync(inputRoot, { recursive: true });
  const write = (name, payload) => {
    const file = path.join(inputRoot, name);
    fs.writeFileSync(file, typeof payload === 'string' ? payload : JSON.stringify(payload));
    return file;
  };
  const fig = write('fixture.fig', 'fig bytes');
  const figHash = crypto.createHash('sha256').update(fs.readFileSync(fig)).digest('hex');
  const sitePlan = { schema: 'blocks-engine/wordpress-site-plan/v2', pages: [{ slug: 'home', title: 'Home', content: '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->' }], routes: [{ path: '/', page_slug: 'home' }] };
  const sitePlanPath = write('site-plan.json', sitePlan);
  const transformResult = write('transform-result.json', { schema: 'blocks-engine/figma-transform-result/v1', document: { name: 'Fisiostetic' } });
  const visualSource = write('html-source.png', 'png');
  const visualRendered = write('html-rendered.png', 'png');
  const visualDiff = write('html-diff.json', { mismatch_pixels: 0 });
  const parity = {};
  for (const stage of ['html_wordpress_mobile_parity', 'figma_wordpress_desktop_parity', 'figma_wordpress_mobile_parity']) {
    parity[stage.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase())] = write(`${stage}.json`, { schema: 'static-site-importer/fig-acceptance-parity-input/v1', stage, source_screenshot: write(`${stage}-source.png`, 'png'), rendered_screenshot: write(`${stage}-rendered.png`, 'png'), diff_report: { metrics: { pixel_difference_count: 0, geometry_difference_count: 0 } } });
  }
  const figmaReference = write('figma-result-reference.json', { status: 'completed' });
  const stagePaths = {};
  for (const stage of ['decode', 'normalize', 'emit', 'figma_html_desktop_parity', 'figma_html_mobile_parity', 'responsive_selection']) {
    const evidence = { schema: 'blocks-engine/figma-wordpress-stage-evidence/v1', fixture_id: 'fisiostetic', stage, source_sha256: figHash, status: 'passed', references: [path.basename(figmaReference)] };
    if (stage === 'decode') evidence.metrics = { missing_text_count: 0, missing_asset_count: 0, vector_placeholder_count: 0 };
    if (stage === 'normalize') evidence.metrics = { normalized_node_count: 1 };
    if (stage === 'emit') evidence.metrics = { emitted_route_count: 1, missing_emitted_asset_count: 0, missing_emitted_text_count: 0 };
    if (stage === 'responsive_selection') Object.assign(evidence, { selection_source: 'dev_status', responsive_routes: [{ output_route: '/', desktop_source_frame: 'Desktop', mobile_source_frame: 'Mobile', breakpoint_min_width: 375, breakpoint_max_width: 1440 }] });
    if (stage.includes('parity')) {
      const source = write(`${stage}-source.png`, 'png');
      const rendered = write(`${stage}-rendered.png`, 'png');
      const diff = write(`${stage}-diff.json`, { metrics: { pixel_difference_count: 0, geometry_difference_count: 0 } });
      Object.assign(evidence, { comparison: 'figma_html', metrics: { pixel_difference_count: 0, geometry_difference_count: 0 }, source_screenshot: path.basename(source), rendered_screenshot: path.basename(rendered), diff_report: path.basename(diff), references: [path.basename(figmaReference), path.basename(source), path.basename(rendered), path.basename(diff)] });
    }
    stagePaths[stage] = write(`${stage}-evidence.json`, evidence);
  }
  const transformSummary = write('transform-summary.json', { output_dir: inputRoot, fixtures: [{ id: 'fisiostetic', path: fig, status: 'completed', result_path: transformResult, artifact_dir: inputRoot, acceptance_readiness: { schema: 'blocks-engine/figma-transformer/acceptance-readiness/v1', status: 'passed', stage_paths: stagePaths } }] });
  const matrixResult = write('matrix-result.json', { fixtures: [{ fixture_id: 'fisiostetic', status: 'passed', block_composition: { block_total: 1, native_block_count: 1, core_html_block_count: 0 }, editor_validation: { validation_method: 'wp.blocks.validateBlock', total_blocks: 1, valid_blocks: 1, invalid_blocks: 0 }, quality_metrics: { fallback_count: 0 }, import_report: { blocks_engine: { wordpress_site_plan: sitePlan } }, matrix_evidence: { readiness: 'verified', materialization_receipt: { status: 'completed', page_count: 1 } }, visual_parity_artifacts: { metrics: { mismatch_pixels: 0, dimension_mismatch: false }, artifacts: { source_screenshot: { status: 'captured', ref: { path: visualSource } }, imported_screenshot: { status: 'captured', ref: { path: visualRendered } }, visual_diff: { status: 'captured', ref: { path: visualDiff } } } } }] });
  return { acceptanceRoot, paths: { parity }, input: { fig, fixtureId: 'fisiostetic', fixtureOutput, transformSummary, matrixResult, matrixOutput: inputRoot, sitePlan: sitePlanPath, providerIdentity: 'static-site-importer@test', runtimeIdentity: 'wp-codebox:test', ...parity } };
}

function summaryPlan(root, matrixSummary) {
  return {
    fixture_count: 3,
    expected_fixture_count: 3,
    run_import_matrix: true,
    thresholds: {
      max_transform_failures: 0,
      max_import_failures: 0,
      min_native_rate: 1,
      max_transform_vector_placeholders: 0,
      max_transform_missing_assets: 0,
      max_import_findings: null,
      max_baseline_regression_ratio: null,
    },
    artifacts: {
      transform_summary: path.join(root, 'missing-transform-summary.json'),
      matrix_summary: matrixSummary,
      matrix_result: path.join(root, 'matrix-result.json'),
      matrix_output_directory: root,
    },
    steps: {
      transform: { command: 'transform' },
      import_matrix: { command: 'matrix' },
    },
    warnings: [],
  };
}
