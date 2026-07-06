#!/usr/bin/env node

/**
 * Compose the cross-stack Figma fixture proof:
 * Blocks Engine .fig transform -> SSI generated-artifact import/parity matrix.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const DEFAULT_EXPECTED_FIXTURE_COUNT = 3;
const SUMMARY_SCHEMA = 'static-site-importer/fig-fixture-e2e-summary/v1';

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
  const transformScript = path.join(options.blocksEngine, 'figma-transformer', 'scripts', 'figma-fixture-matrix.php');
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
    artifacts: {
      plan: path.join(options.outputDirectory, 'plan.json'),
      summary: summaryPath,
      transform_summary: transformSummary,
      matrix_summary: matrixSummary,
      matrix_result: matrixResult,
      matrix_output_directory: matrixOutputDirectory,
    },
    thresholds: {
      max_transform_failures: 0,
      max_import_failures: 0,
      min_native_rate: options.minNativeRate,
    },
    steps: {
      transform: commandStep('figma-transform', transformArgv, { cwd: path.join(options.blocksEngine, 'figma-transformer') }),
      import_matrix: commandStep('ssi-import-matrix', matrixArgv, { cwd: options.staticSiteImporter }),
    },
    warnings: buildPlanWarnings(options, transformScript, benchScript),
  };
}

export function summarizeRun({ plan, transformStatus, matrixStatus }) {
  const transformSummary = readJsonIfExists(plan.artifacts.transform_summary);
  const matrixSummary = readJsonIfExists(plan.artifacts.matrix_summary);
  const transformFixtures = Array.isArray(transformSummary?.fixtures) ? transformSummary.fixtures : [];
  const completedTransforms = transformFixtures.filter((fixture) => fixture.status === 'completed');
  const failedTransforms = transformFixtures.filter((fixture) => fixture.status && fixture.status !== 'completed');
  const matrixResultSummary = matrixSummary?.result_summary || {};
  const failedImportFixtures = Number(matrixResultSummary.failed || 0);
  const matrixNativeRate = minFixtureNativeRate(matrixSummary);
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
  if (matrixSummary && failedImportFixtures > plan.thresholds.max_import_failures) {
    failures.push(`${failedImportFixtures} SSI matrix fixture(s) failed`);
  }
  if (matrixNativeRate !== null && matrixNativeRate < plan.thresholds.min_native_rate) {
    failures.push(`minimum native conversion rate ${matrixNativeRate} is below ${plan.thresholds.min_native_rate}`);
  }

  return {
    schema: SUMMARY_SCHEMA,
    status: failures.length ? 'failed' : 'passed',
    generated_at: new Date().toISOString(),
    failures,
    fixture_count: plan.fixture_count,
    expected_fixture_count: plan.expected_fixture_count,
    transform: {
      exit_code: transformStatus?.status ?? null,
      summary_path: plan.artifacts.transform_summary,
      completed_fixture_count: completedTransforms.length,
      failed_fixture_count: failedTransforms.length,
      fixtures: transformFixtures.map((fixture) => ({
        id: fixture.id,
        status: fixture.status,
        artifact_dir: fixture.artifact_dir || null,
        result_path: fixture.result_path || null,
      })),
    },
    import_matrix: {
      enabled: plan.run_import_matrix,
      exit_code: matrixStatus?.status ?? null,
      summary_path: plan.artifacts.matrix_summary,
      result_path: plan.artifacts.matrix_result,
      fixture_count: Number(matrixSummary?.fixture_count || 0),
      passed_fixture_count: Number(matrixResultSummary.succeeded || 0),
      failed_fixture_count: failedImportFixtures,
      finding_count: Number(matrixResultSummary.finding_count || 0),
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
    expectedFixtureCount: positiveInteger(input.expectedFixtureCount || input.expected_fixture_count || process.env.SSI_FIG_E2E_EXPECTED_FIXTURE_COUNT, DEFAULT_EXPECTED_FIXTURE_COUNT),
    maxPages: positiveInteger(input.maxPages || input.max_pages || process.env.SSI_FIG_E2E_MAX_PAGES, 3),
    maxNodes: positiveInteger(input.maxNodes || input.max_nodes || process.env.SSI_FIG_E2E_MAX_NODES, 5000),
    batchSize: positiveInteger(input.batchSize || input.batch_size || process.env.SSI_FIG_E2E_BATCH_SIZE, 3),
    concurrency: positiveInteger(input.concurrency || process.env.SSI_FIG_E2E_CONCURRENCY, 1),
    minNativeRate: finiteNumber(input.minNativeRate || input.min_native_rate || process.env.SSI_FIG_E2E_MIN_NATIVE_RATE, 1),
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

function buildPlanWarnings(options, transformScript, benchScript) {
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

function minFixtureNativeRate(matrixSummary) {
  const aggregateRate = Number(matrixSummary?.result_summary?.editor_quality?.native_conversion_rate);
  if (Number.isFinite(aggregateRate)) {
    return aggregateRate;
  }
  const fixtures = Array.isArray(matrixSummary?.fixtures) ? matrixSummary.fixtures : [];
  const rates = fixtures
    .map((fixture) => Number(fixture?.editor_quality?.native_conversion_rate))
    .filter((rate) => Number.isFinite(rate));
  return rates.length ? Math.min(...rates) : null;
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
  process.stdout.write(`Usage: node tools/run-fig-fixture-e2e.mjs --blocks-engine <path> --fixture <file.fig> --fixture <file.fig> --fixture <file.fig> [options]\n\nRuns Blocks Engine Figma transforms and feeds the generated artifacts into the SSI WP Codebox fixture matrix.\n\nOptions:\n  --fixture <path>                         .fig fixture path. Repeat exactly three times for the release gate.\n  --blocks-engine <path>                   Blocks Engine checkout containing figma-transformer/. Required.\n  --static-site-importer <path>            SSI checkout. Defaults to this repo.\n  --blocks-engine-php-transformer-path <path>\n                                           Composer path override for SSI import. Defaults to --blocks-engine.\n  --output-directory <path>                Artifact root. Defaults to ./artifacts/fig-fixture-e2e.\n  --run                                    Launch SSI import/parity through WP Codebox. Without this, writes transform artifacts and a replayable matrix recipe only.\n  --dry-run                                Write plan/summary without running transform or import.\n  --expected-fixture-count <n>             Defaults to 3.\n  --min-native-rate <n>                    Defaults to 1.\n  --batch-size <n>                         Defaults to 3.\n  --concurrency <n>                        Defaults to 1.\n  --wp-codebox-bin <path>                  WP Codebox binary override for the SSI matrix.\n  --no-editor-validation                   Skip editor block validation.\n  --no-visual-parity                       Skip visual compare.\n  --live-wp-parity                         Enable live WP HTML parity capture.\n\nEnvironment equivalents:\n  SSI_FIG_E2E_BLOCKS_ENGINE=/path/to/blocks-engine\n  SSI_FIG_E2E_FIXTURES=/path/Fisiostetic.fig:${path.join('<path>', 'FSE Pilot Build Theme.fig')}:/path/Twenty Twenty-Five.fig\n  SSI_FIG_E2E_RUN=1\n`);
}
