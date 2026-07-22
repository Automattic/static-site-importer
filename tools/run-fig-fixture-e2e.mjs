#!/usr/bin/env node

/**
 * Compose the cross-stack Figma fixture proof:
 * Blocks Engine .fig transform -> SSI generated-artifact import/parity matrix.
 */
/**
 * External dependencies
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { buildFigAcceptanceProvider } from './fig-acceptance-provider.mjs';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const DEFAULT_EXPECTED_FIXTURE_COUNT = 3;
const SUMMARY_SCHEMA = 'static-site-importer/fig-fixture-e2e-summary/v1';
const BASELINE_METRICS = [
  'transform_duration_ms',
  'import_matrix_duration_ms',
  'total_duration_ms',
  'transform_vector_placeholder_count',
  'transform_missing_asset_count',
  'import_matrix_finding_count',
  'import_matrix_failed_fixture_count',
];

if (import.meta.url === `file://${process.argv[1]}`) {
  try {
    const options = parseArgs(process.argv.slice(2), process.env);
    if (options.help) {
      printHelp();
      process.exit(0);
    }
    const result = runFigFixtureE2E(options);
    process.stdout.write(`${JSON.stringify(result.summary, null, 2)}\n`);
    if (result.summary.status !== 'passed') {
      process.exitCode = 1;
    }
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}

export function runFigFixtureE2E(input = {}) {
  const plan = buildFigFixtureE2EPlan(input);
  fs.mkdirSync(plan.output_directory, { recursive: true });
  writeJson(path.join(plan.output_directory, 'plan.json'), plan);

  if (plan.dry_run) {
    const summary = summarizeRun({ plan, transformStatus: null, matrixStatus: null });
    writeJson(plan.artifacts.summary, summary);
    return { plan, summary };
  }

  const transformStatus = runCommand(plan.steps.transform);
  const matrixStatus = transformStatus.status === 0 ? runCommand(plan.steps.import_matrix) : null;
  const summary = summarizeRun({ plan, transformStatus, matrixStatus });
  if (summary.status === 'passed' && plan.acceptance.enabled) {
    try {
      const manifest = writeFigAcceptanceManifest(plan);
      const acceptanceStatus = runCommand(plan.steps.acceptance_matrix);
      summary.acceptance = {
        enabled: true,
        manifest_path: manifest.path,
        summary_path: path.join(plan.acceptance.output_directory, 'summary.json'),
        exit_code: acceptanceStatus.status,
      };
      if (acceptanceStatus.status !== 0) {
        summary.status = 'failed';
        summary.failures.push(`production acceptance matrix exited ${acceptanceStatus.status}`);
      }
    } catch (error) {
      summary.status = 'failed';
      summary.failures.push(`acceptance provider failed: ${error.message}`);
    }
  }
  writeJson(plan.artifacts.summary, summary);
  return { plan, summary };
}

export function buildFigFixtureE2EPlan(input = {}) {
  const options = normalizeOptions(input);
  const transformSummary = path.join(options.outputDirectory, 'figma-transform', 'summary.json');
  const matrixOutputDirectory = path.join(options.outputDirectory, 'ssi-matrix');
  const matrixSummary = path.join(matrixOutputDirectory, 'summary.json');
  const matrixResult = path.join(matrixOutputDirectory, 'static-site-fixture-matrix-result.json');
  const summaryPath = path.join(options.outputDirectory, 'summary.json');
  const acceptanceOutput = path.join(options.outputDirectory, 'production-acceptance');
  const acceptanceManifest = path.join(acceptanceOutput, 'manifest.json');
  const transformScript = path.join(options.blocksEngine, 'figma-transformer', 'scripts', 'figma-fixture-matrix.php');
  const acceptanceScript = path.join(options.blocksEngine, 'scripts', 'production-acceptance-matrix.php');
  const benchScript = path.join(options.staticSiteImporter, 'bench', 'static-site-fixture-matrix.bench.mjs');

  const transformArgv = [
    options.phpBin,
    transformScript,
    `--output-dir=${path.dirname(transformSummary)}`,
    `--max-pages=${options.maxPages}`,
    `--max-nodes=${options.maxNodes}`,
    ...options.fixtures.flatMap((fixture) => [`--fixture=${fixture}`]),
  ];
  const matrixArgv = [
    options.nodeBin,
    benchScript,
    '--artifact-root', path.dirname(transformSummary),
    '--output-directory', matrixOutputDirectory,
    '--static-site-importer-path', options.staticSiteImporter,
    '--blocks-engine-php-transformer-path', options.blocksEnginePhpTransformerPath,
    '--batch-size', String(options.batchSize),
    '--concurrency', String(options.concurrency),
    '--min-native-rate', String(options.minNativeRate),
    ...(options.run ? ['--run'] : []),
    ...(options.editorValidation ? [] : ['--no-editor-validation']),
    ...(options.visualParity ? [] : ['--no-visual-parity']),
    ...(options.liveWpParity ? ['--live-wp-parity'] : []),
    ...(options.wpCodeboxBin ? ['--wp-codebox-bin', options.wpCodeboxBin] : []),
  ];

  return {
    schema: 'static-site-importer/fig-fixture-e2e-plan/v1',
    generated_at: new Date().toISOString(),
    dry_run: options.dryRun,
    run_import_matrix: options.run,
    expected_fixture_count: options.expectedFixtureCount,
    fixture_count: options.fixtures.length,
    fixtures: options.fixtures,
    blocks_engine: options.blocksEngine,
    static_site_importer: options.staticSiteImporter,
    blocks_engine_php_transformer_path: options.blocksEnginePhpTransformerPath,
    output_directory: options.outputDirectory,
    baseline_summary: options.baselineSummary,
    artifacts: {
      plan: path.join(options.outputDirectory, 'plan.json'),
      summary: summaryPath,
      transform_summary: transformSummary,
      matrix_summary: matrixSummary,
      matrix_result: matrixResult,
      matrix_output_directory: matrixOutputDirectory,
    },
    acceptance: {
      enabled: Boolean(options.acceptanceConfig),
      config_path: options.acceptanceConfig || null,
      output_directory: acceptanceOutput,
      manifest_path: acceptanceManifest,
    },
    thresholds: {
      max_transform_failures: 0,
      max_import_failures: 0,
      min_native_rate: options.minNativeRate,
      max_transform_vector_placeholders: options.maxTransformVectorPlaceholders,
      max_transform_missing_assets: options.maxTransformMissingAssets,
      max_import_findings: options.maxImportFindings,
      max_baseline_regression_ratio: options.maxBaselineRegressionRatio,
    },
    steps: {
      transform: commandStep('figma-transform', transformArgv, { cwd: path.join(options.blocksEngine, 'figma-transformer') }),
      import_matrix: commandStep('ssi-import-matrix', matrixArgv, { cwd: options.staticSiteImporter }),
      ...(options.acceptanceConfig ? {
        acceptance_matrix: commandStep('production-acceptance-matrix', [options.phpBin, acceptanceScript, `--profile=${options.expectedFixtureCount === 3 ? 'production' : 'manifest'}`, `--manifest=${acceptanceManifest}`, `--output=${acceptanceOutput}`], { cwd: options.blocksEngine }),
      } : {}),
    },
    warnings: buildPlanWarnings(options, transformScript, benchScript, acceptanceScript),
  };
}

export function writeFigAcceptanceManifest(plan) {
  const config = readJsonIfExists(plan.acceptance.config_path);
  if (!config) throw new Error(`acceptance config is missing or invalid: ${plan.acceptance.config_path}`);
  const transformSummary = readJsonIfExists(plan.artifacts.transform_summary);
  const fixtures = Array.isArray(transformSummary?.fixtures) ? transformSummary.fixtures : [];
  const configuredFixtures = config.fixtures && typeof config.fixtures === 'object' && !Array.isArray(config.fixtures) ? config.fixtures : {};
  const fragments = fixtures.map((fixture) => {
    const id = requiredString(fixture?.id, 'transform fixture id');
    const configured = configuredFixtures[id];
    if (!configured || typeof configured !== 'object' || Array.isArray(configured)) throw new Error(`acceptance config has no fixture ${id}`);
    return buildFigAcceptanceProvider({
      fig: fixture.path,
      fixtureId: id,
      fixtureOutput: path.join(plan.acceptance.output_directory, 'fixtures', id),
      transformSummary: plan.artifacts.transform_summary,
      matrixResult: plan.artifacts.matrix_result,
      matrixOutput: plan.artifacts.matrix_output_directory,
      providerIdentity: config.provider_identity,
      runtimeIdentity: config.runtime_identity,
      sitePlan: configured.site_plan,
      htmlWordpressMobileParity: configured.html_wordpress_mobile_parity,
      figmaWordpressDesktopParity: configured.figma_wordpress_desktop_parity,
      figmaWordpressMobileParity: configured.figma_wordpress_mobile_parity,
    });
  });
  if (fragments.length !== plan.expected_fixture_count) throw new Error(`acceptance provider completed ${fragments.length}/${plan.expected_fixture_count} fixtures`);
  writeJson(plan.acceptance.manifest_path, { fixtures: fragments });
  return { path: plan.acceptance.manifest_path, fixtures: fragments };
}

export function summarizeRun({ plan, transformStatus, matrixStatus }) {
  const transformSummary = readJsonIfExists(plan.artifacts.transform_summary);
  const matrixSummary = readJsonIfExists(plan.artifacts.matrix_summary);
  const transformFixtures = Array.isArray(transformSummary?.fixtures) ? transformSummary.fixtures : [];
  const completedTransforms = transformFixtures.filter((fixture) => fixture.status === 'completed');
  const failedTransforms = transformFixtures.filter((fixture) => fixture.status && fixture.status !== 'completed');
  const matrixResultSummary = matrixResultSummaryFrom(matrixSummary);
  const failedImportFixtures = Number(matrixResultSummary?.failed || 0);
  const matrixNativeRate = minFixtureNativeRate(matrixSummary);
  const metrics = summaryMetrics({ plan, transformStatus, matrixStatus, transformSummary, matrixSummary });
  const baselineComparison = compareBaseline(plan, metrics);
  const failures = [];

  if (plan.fixture_count !== plan.expected_fixture_count) {
    failures.push(`expected ${plan.expected_fixture_count} fixture paths, received ${plan.fixture_count}`);
  }
  if (transformStatus && transformStatus.status !== 0) {
    failures.push(`figma transform command exited ${transformStatus.status}`);
  }
  if (transformSummary && completedTransforms.length !== plan.expected_fixture_count) {
    failures.push(`completed ${completedTransforms.length}/${plan.expected_fixture_count} Figma transforms`);
  }
  if (failedTransforms.length > plan.thresholds.max_transform_failures) {
    failures.push(`${failedTransforms.length} Figma transform fixture(s) failed`);
  }
  if (matrixStatus && matrixStatus.status !== 0) {
    failures.push(`SSI matrix command exited ${matrixStatus.status}`);
  }
  if (plan.run_import_matrix && matrixSummary && !matrixResultSummary) {
    failures.push('SSI matrix summary is missing fixture result accounting');
  }
  if (matrixResultSummary && matrixFixtureAccounting(matrixResultSummary) !== Number(matrixSummary?.fixture_count || 0)) {
    failures.push('SSI matrix fixture result accounting does not match fixture_count');
  }
  if (matrixSummary && failedImportFixtures > plan.thresholds.max_import_failures) {
    failures.push(`${failedImportFixtures} SSI matrix fixture(s) failed`);
  }
  if (matrixNativeRate !== null && matrixNativeRate < plan.thresholds.min_native_rate) {
    failures.push(`minimum native conversion rate ${matrixNativeRate} is below ${plan.thresholds.min_native_rate}`);
  }
  if (metrics.transform_vector_placeholder_count > plan.thresholds.max_transform_vector_placeholders) {
    failures.push(`${metrics.transform_vector_placeholder_count} Blocks Engine vector placeholder(s) exceeded ${plan.thresholds.max_transform_vector_placeholders}`);
  }
  if (metrics.transform_missing_asset_count > plan.thresholds.max_transform_missing_assets) {
    failures.push(`${metrics.transform_missing_asset_count} Blocks Engine missing asset(s) exceeded ${plan.thresholds.max_transform_missing_assets}`);
  }
  if (plan.thresholds.max_import_findings !== null && metrics.import_matrix_finding_count > plan.thresholds.max_import_findings) {
    failures.push(`${metrics.import_matrix_finding_count} SSI finding(s) exceeded ${plan.thresholds.max_import_findings}`);
  }
  for (const regression of baselineComparison.regressions) {
    failures.push(`${regression.metric} regressed by ${regression.ratio} vs baseline ${baselineComparison.baseline_summary_path}`);
  }

  return {
    schema: SUMMARY_SCHEMA,
    status: failures.length ? 'failed' : 'passed',
    generated_at: new Date().toISOString(),
    failures,
    fixture_count: plan.fixture_count,
    expected_fixture_count: plan.expected_fixture_count,
    metrics,
    baseline_comparison: baselineComparison,
    transform: {
      exit_code: transformStatus?.status ?? null,
      duration_ms: metrics.transform_duration_ms,
      summary_path: plan.artifacts.transform_summary,
      completed_fixture_count: completedTransforms.length,
      failed_fixture_count: failedTransforms.length,
      vector_placeholder_count: metrics.transform_vector_placeholder_count,
      missing_asset_count: metrics.transform_missing_asset_count,
      fixtures: transformFixtures.map((fixture) => ({
        id: fixture.id,
        status: fixture.status,
        duration_ms: numberOr(fixture.duration_ms, null),
        vector_placeholder_count: transformVectorPlaceholderCount(fixture),
        missing_asset_count: transformMissingAssetCount(fixture),
        artifact_dir: fixture.artifact_dir || null,
        result_path: fixture.result_path || null,
      })),
    },
    import_matrix: {
      enabled: plan.run_import_matrix,
      exit_code: matrixStatus?.status ?? null,
      duration_ms: metrics.import_matrix_duration_ms,
      summary_path: plan.artifacts.matrix_summary,
      result_path: plan.artifacts.matrix_result,
      fixture_count: Number(matrixSummary?.fixture_count || 0),
      passed_fixture_count: Number(matrixResultSummary?.succeeded || 0),
      failed_fixture_count: failedImportFixtures,
      finding_count: Number(matrixResultSummary?.finding_count || 0),
      min_native_conversion_rate: matrixNativeRate,
    },
    artifacts: plan.artifacts,
    commands: {
      transform: plan.steps.transform.command,
      import_matrix: plan.steps.import_matrix.command,
    },
    warnings: plan.warnings,
  };
}

function normalizeOptions(input) {
  const staticSiteImporter = path.resolve(input.staticSiteImporter || input.static_site_importer || process.env.SSI_FIG_E2E_STATIC_SITE_IMPORTER || packageRoot);
  const blocksEngine = path.resolve(requiredString(input.blocksEngine || input.blocks_engine || process.env.SSI_FIG_E2E_BLOCKS_ENGINE, '--blocks-engine'));
  const fixtures = normalizeFixtures(input.fixtures || input.fixture || process.env.SSI_FIG_E2E_FIXTURES);
  const outputDirectory = path.resolve(input.outputDirectory || input.output_directory || process.env.SSI_FIG_E2E_OUTPUT_DIRECTORY || path.join(process.cwd(), 'artifacts', 'fig-fixture-e2e'));
  return {
    staticSiteImporter,
    blocksEngine,
    blocksEnginePhpTransformerPath: path.resolve(input.blocksEnginePhpTransformerPath || input.blocks_engine_php_transformer_path || process.env.SSI_FIG_E2E_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH || blocksEngine),
    fixtures,
    outputDirectory,
    acceptanceConfig: input.acceptanceConfig || input.acceptance_config || process.env.SSI_FIG_E2E_ACCEPTANCE_CONFIG || '',
    baselineSummary: input.baselineSummary || input.baseline_summary || process.env.SSI_FIG_E2E_BASELINE_SUMMARY || '',
    expectedFixtureCount: positiveInteger(input.expectedFixtureCount || input.expected_fixture_count || process.env.SSI_FIG_E2E_EXPECTED_FIXTURE_COUNT, DEFAULT_EXPECTED_FIXTURE_COUNT),
    maxPages: positiveInteger(input.maxPages || input.max_pages || process.env.SSI_FIG_E2E_MAX_PAGES, 3),
    maxNodes: positiveInteger(input.maxNodes || input.max_nodes || process.env.SSI_FIG_E2E_MAX_NODES, 5000),
    batchSize: positiveInteger(input.batchSize || input.batch_size || process.env.SSI_FIG_E2E_BATCH_SIZE, 3),
    concurrency: positiveInteger(input.concurrency || process.env.SSI_FIG_E2E_CONCURRENCY, 1),
    minNativeRate: finiteNumber(input.minNativeRate || input.min_native_rate || process.env.SSI_FIG_E2E_MIN_NATIVE_RATE, 1),
    maxTransformVectorPlaceholders: finiteNumber(input.maxTransformVectorPlaceholders || input.max_transform_vector_placeholders || process.env.SSI_FIG_E2E_MAX_TRANSFORM_VECTOR_PLACEHOLDERS, 0),
    maxTransformMissingAssets: finiteNumber(input.maxTransformMissingAssets || input.max_transform_missing_assets || process.env.SSI_FIG_E2E_MAX_TRANSFORM_MISSING_ASSETS, 0),
    maxImportFindings: finiteNumber(input.maxImportFindings || input.max_import_findings || process.env.SSI_FIG_E2E_MAX_IMPORT_FINDINGS, null),
    maxBaselineRegressionRatio: finiteNumber(input.maxBaselineRegressionRatio || input.max_baseline_regression_ratio || process.env.SSI_FIG_E2E_MAX_BASELINE_REGRESSION_RATIO, null),
    phpBin: input.phpBin || input.php_bin || process.env.SSI_FIG_E2E_PHP_BIN || 'php',
    nodeBin: input.nodeBin || input.node_bin || process.env.SSI_FIG_E2E_NODE_BIN || process.execPath,
    wpCodeboxBin: input.wpCodeboxBin || input.wp_codebox_bin || process.env.SSI_FIG_E2E_WP_CODEBOX_BIN || '',
    run: Boolean(input.run || isTruthy(process.env.SSI_FIG_E2E_RUN)),
    dryRun: Boolean(input.dryRun || input.dry_run),
    editorValidation: input.editorValidation ?? input.editor_validation ?? !isFalsy(process.env.SSI_FIG_E2E_EDITOR_VALIDATION ?? true),
    visualParity: input.visualParity ?? input.visual_parity ?? !isFalsy(process.env.SSI_FIG_E2E_VISUAL_PARITY ?? true),
    liveWpParity: Boolean(input.liveWpParity || input.live_wp_parity || isTruthy(process.env.SSI_FIG_E2E_LIVE_WP_PARITY)),
  };
}

function parseArgs(args, env = process.env) {
  const options = { fixtures: [] };
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--help' || arg === '-h') {
      options.help = true;
      continue;
    }
    if (arg === '--run') {
      options.run = true;
      continue;
    }
    if (arg === '--dry-run') {
      options.dryRun = true;
      continue;
    }
    if (arg === '--no-editor-validation') {
      options.editorValidation = false;
      continue;
    }
    if (arg === '--no-visual-parity') {
      options.visualParity = false;
      continue;
    }
    if (arg === '--live-wp-parity') {
      options.liveWpParity = true;
      continue;
    }

    const [rawKey, inlineValue] = arg.startsWith('--') ? arg.slice(2).split(/=(.*)/s, 2) : ['', ''];
    if (!rawKey) {
      throw new Error(`Unknown positional argument: ${arg}`);
    }
    const value = inlineValue !== undefined && inlineValue !== '' ? inlineValue : args[++index];
    if (value === undefined) {
      throw new Error(`Missing value for --${rawKey}`);
    }
    const key = rawKey.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    if (key === 'fixture') {
      options.fixtures.push(value);
    } else {
      options[key] = value;
    }
  }

  const envFixtures = env.SSI_FIG_E2E_FIXTURES ? normalizeFixtures(env.SSI_FIG_E2E_FIXTURES) : [];
  options.fixtures = [...envFixtures, ...options.fixtures];
  return options;
}

function buildPlanWarnings(options, transformScript, benchScript, acceptanceScript) {
  const warnings = [];
  if (options.fixtures.length !== options.expectedFixtureCount) {
    warnings.push(`Expected ${options.expectedFixtureCount} fixture paths; received ${options.fixtures.length}.`);
  }
  for (const fixture of options.fixtures) {
    if (!fs.existsSync(fixture)) {
      warnings.push(`Fixture does not exist: ${fixture}`);
    }
  }
  if (!fs.existsSync(transformScript)) {
    warnings.push(`Blocks Engine Figma matrix script not found: ${transformScript}`);
  }
  if (!fs.existsSync(benchScript)) {
    warnings.push(`SSI fixture matrix bench script not found: ${benchScript}`);
  }
  if (options.acceptanceConfig && !fs.existsSync(options.acceptanceConfig)) {
    warnings.push(`Acceptance config does not exist: ${options.acceptanceConfig}`);
  }
  if (options.acceptanceConfig && !fs.existsSync(acceptanceScript)) {
    warnings.push(`Blocks Engine production acceptance script not found: ${acceptanceScript}`);
  }
  if (!options.run) {
    warnings.push('Import/parity matrix is planned only; pass --run or SSI_FIG_E2E_RUN=1 to launch WP Codebox.');
  }
  return warnings;
}

function runCommand(step) {
  const started = Date.now();
  const result = spawnSync(step.argv[0], step.argv.slice(1), {
    cwd: step.cwd,
    env: process.env,
    encoding: 'utf8',
    stdio: 'inherit',
  });
  return {
    status: typeof result.status === 'number' ? result.status : 1,
    signal: result.signal || null,
    duration_ms: Date.now() - started,
  };
}

function commandStep(label, argv, options = {}) {
  return {
    label,
    argv,
    command: argv.map(shellQuote).join(' '),
    cwd: options.cwd || process.cwd(),
  };
}

function normalizeFixtures(value) {
  const raw = Array.isArray(value) ? value : String(value || '').split(path.delimiter);
  return raw.map((fixture) => String(fixture || '').trim()).filter(Boolean).map((fixture) => path.resolve(fixture));
}

function summaryMetrics({ plan, transformStatus, matrixStatus, transformSummary, matrixSummary }) {
  const transformFixtures = Array.isArray(transformSummary?.fixtures) ? transformSummary.fixtures : [];
  const matrixResultSummary = matrixResultSummaryFrom(matrixSummary);
  const transformDuration = numberOr(transformStatus?.duration_ms, sumFixtureMetric(transformFixtures, 'duration_ms'));
  const importDuration = numberOr(matrixStatus?.duration_ms, numberOr(matrixSummary?.runtime?.duration_ms, null));
  return {
    transform_duration_ms: transformDuration,
    import_matrix_duration_ms: importDuration,
    total_duration_ms: sumFinite([transformDuration, importDuration]),
    transform_fixture_count: transformFixtures.length,
    transform_completed_fixture_count: transformFixtures.filter((fixture) => fixture.status === 'completed').length,
    transform_failed_fixture_count: transformFixtures.filter((fixture) => fixture.status && fixture.status !== 'completed').length,
    transform_vector_placeholder_count: sumQuality(transformFixtures, transformVectorPlaceholderCount),
    transform_missing_asset_count: sumQuality(transformFixtures, transformMissingAssetCount),
    import_matrix_enabled: plan.run_import_matrix ? 1 : 0,
    import_matrix_fixture_count: Number(matrixSummary?.fixture_count || 0),
    import_matrix_passed_fixture_count: Number(matrixResultSummary?.succeeded || 0),
    import_matrix_failed_fixture_count: Number(matrixResultSummary?.failed || 0),
    import_matrix_finding_count: Number(matrixResultSummary?.finding_count || 0),
    import_matrix_min_native_conversion_rate: minFixtureNativeRate(matrixSummary),
  };
}

function compareBaseline(plan, metrics) {
  const baselineSummaryPath = plan.baseline_summary || '';
  const baseline = baselineSummaryPath ? readJsonIfExists(baselineSummaryPath) : null;
  const maxRegressionRatio = numberOr(plan.thresholds?.max_baseline_regression_ratio, null);
  const deltas = [];
  const regressions = [];

  for (const metric of BASELINE_METRICS) {
    const baselineValue = Number(baseline?.metrics?.[metric]);
    const currentValue = Number(metrics?.[metric]);
    if (!Number.isFinite(baselineValue) || !Number.isFinite(currentValue)) {
      continue;
    }
    const delta = currentValue - baselineValue;
    let ratio = delta / baselineValue;
    if (baselineValue === 0) {
      ratio = delta > 0 ? Number.POSITIVE_INFINITY : 0;
    }
    const entry = { metric, baseline: baselineValue, current: currentValue, delta, ratio: roundRatio(ratio) };
    deltas.push(entry);
    if (maxRegressionRatio !== null && delta > 0 && ratio > maxRegressionRatio) {
      regressions.push(entry);
    }
  }

  return {
    baseline_summary_path: baselineSummaryPath || null,
    compared_metric_count: deltas.length,
    max_regression_ratio: maxRegressionRatio,
    deltas,
    regressions,
  };
}

function transformVectorPlaceholderCount(fixture) {
  return numberOr(fixture?.vector_placeholders || fixture?.artifact_quality?.vectors?.placeholders || fixture?.quality_summary?.vector_placeholders, 0);
}

function transformMissingAssetCount(fixture) {
  const direct = firstNumber([
    fixture?.missing_asset_count,
    fixture?.missing_assets_count,
    fixture?.quality_summary?.missing_asset_count,
    fixture?.quality_summary?.missing_assets,
    fixture?.artifact_quality?.missing_asset_count,
    fixture?.artifact_quality?.missing_assets_count,
    fixture?.artifact_quality?.summary?.missing_asset_count,
    fixture?.artifact_quality?.summary?.missing_assets,
  ]);
  if (direct !== null) {
    return direct;
  }
  const codes = Array.isArray(fixture?.diagnostic_codes) ? fixture.diagnostic_codes : [];
  return codes.filter((code) => String(code).includes('missing_asset')).length;
}

function sumFixtureMetric(fixtures, key) {
  const total = fixtures.reduce((sum, fixture) => sum + numberOr(fixture?.[key], 0), 0);
  return total || null;
}

function sumQuality(fixtures, getter) {
  return fixtures.reduce((sum, fixture) => sum + getter(fixture), 0);
}

function sumFinite(values) {
  const finiteValues = values.filter((value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)));
  return finiteValues.length ? finiteValues.reduce((sum, value) => sum + Number(value), 0) : null;
}

function firstNumber(values) {
  for (const value of values) {
    const number = Number(value);
    if (Number.isFinite(number)) {
      return number;
    }
  }
  return null;
}

function numberOr(value, fallback = 0) {
  if (value === null || value === undefined || value === '') {
    return fallback;
  }
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function roundRatio(value) {
  return Number.isFinite(value) ? Math.round(value * 10000) / 10000 : value;
}

function minFixtureNativeRate(matrixSummary) {
  const aggregateRate = Number(matrixResultSummaryFrom(matrixSummary)?.editor_quality?.native_conversion_rate);
  if (Number.isFinite(aggregateRate)) {
    return aggregateRate;
  }
  const fixtures = Array.isArray(matrixSummary?.fixtures) ? matrixSummary.fixtures : [];
  const rates = fixtures
    .map((fixture) => Number(fixture?.editor_quality?.native_conversion_rate))
    .filter((rate) => Number.isFinite(rate));
  return rates.length ? Math.min(...rates) : null;
}

function matrixResultSummaryFrom(matrixSummary) {
  if (!matrixSummary || typeof matrixSummary !== 'object') {
    return null;
  }
  const candidate = matrixSummary.result_summary && typeof matrixSummary.result_summary === 'object'
    ? matrixSummary.result_summary
    : matrixSummary;
  return ['succeeded', 'failed'].every((key) => Number.isInteger(Number(candidate[key])))
    && (candidate.not_run === undefined || Number.isInteger(Number(candidate.not_run)))
    ? { ...candidate, not_run: Number(candidate.not_run || 0) }
    : null;
}

function matrixFixtureAccounting(summary) {
  return Number(summary.succeeded) + Number(summary.failed) + Number(summary.not_run);
}

function readJsonIfExists(filePath) {
  if (!filePath || !fs.existsSync(filePath)) {
    return null;
  }
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`);
}

function requiredString(value, label) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`${label} is required`);
  }
  return value;
}

function positiveInteger(value, fallback) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : fallback;
}

function finiteNumber(value, fallback) {
  if (value === null || value === undefined || value === '') {
    return fallback;
  }
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function isTruthy(value) {
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function isFalsy(value) {
  return ['0', 'false', 'no', 'off'].includes(String(value).toLowerCase());
}

function shellQuote(value) {
  const text = String(value);
  return /^[A-Za-z0-9_/:.,=@%+-]+$/.test(text) ? text : `'${text.replace(/'/g, `'"'"'`)}'`;
}

function printHelp() {
  process.stdout.write('Acceptance mode: --acceptance-config <path> supplies provider identities, site plans, and downstream parity inputs, then runs the production evaluator.\n\n');
  process.stdout.write(`Usage: node tools/run-fig-fixture-e2e.mjs --blocks-engine <path> --fixture <file.fig> --fixture <file.fig> --fixture <file.fig> [options]\n\nRuns Blocks Engine Figma transforms and feeds the generated artifacts into the SSI WP Codebox fixture matrix.\n\nOptions:\n  --fixture <path>                         .fig fixture path. Repeat exactly three times for the release gate.\n  --blocks-engine <path>                   Blocks Engine checkout containing figma-transformer/. Required.\n  --static-site-importer <path>            SSI checkout. Defaults to this repo.\n  --blocks-engine-php-transformer-path <path>\n                                           Composer path override for SSI import. Defaults to --blocks-engine.\n  --output-directory <path>                Artifact root. Defaults to ./artifacts/fig-fixture-e2e.\n  --baseline-summary <path>                Previous summary.json to compare staged metrics against.\n  --max-baseline-regression-ratio <n>      Fail if compared numeric metrics regress by more than this ratio.\n  --run                                    Launch SSI import/parity through WP Codebox. Without this, writes transform artifacts and a replayable matrix recipe only.\n  --dry-run                                Write plan/summary without running transform or import.\n  --expected-fixture-count <n>             Defaults to 3.\n  --min-native-rate <n>                    Defaults to 1.\n  --max-transform-vector-placeholders <n>  Defaults to 0.\n  --max-transform-missing-assets <n>       Defaults to 0.\n  --max-import-findings <n>                Optional SSI finding count budget.\n  --batch-size <n>                         Defaults to 3.\n  --concurrency <n>                        Defaults to 1.\n  --wp-codebox-bin <path>                  WP Codebox binary override for the SSI matrix.\n  --no-editor-validation                   Skip editor block validation.\n  --no-visual-parity                       Skip visual compare.\n  --live-wp-parity                         Enable live WP HTML parity capture.\n\nEnvironment equivalents:\n  SSI_FIG_E2E_BLOCKS_ENGINE=/path/to/blocks-engine\n  SSI_FIG_E2E_FIXTURES=/path/Fisiostetic.fig:${path.join('<path>', 'FSE Pilot Build Theme.fig')}:/path/Twenty Twenty-Five.fig\n  SSI_FIG_E2E_RUN=1\n`);
}
