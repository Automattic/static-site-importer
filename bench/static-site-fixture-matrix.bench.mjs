#!/usr/bin/env node

import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runWpCodeboxRecipe, wpCodeboxCommand, wpCodeboxBin } from '../tools/wp-codebox/recipe.mjs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import {
  buildFixtureMatrixRecipe,
  collectFixtureMatrixRunResults,
  createFixtureMatrix,
  normalizeFixtureMatrixResult,
  writeFixtureMatrixArtifacts,
  writeFixtureMatrixResultArtifacts,
} from '../lib/fixture-matrix.mjs';

const DEFAULT_BATCH_SIZE = 10;
// Each batch provisions its own WP Codebox sandbox, so batches are independent
// and safe to fan out in parallel. A single live sandbox costs ~3.3GB host RSS,
// but RSS grows superlinearly when several overlap (a measured `--concurrency 4`
// run peaked near 65GB and OOM-pressured the host). Default to 2 so a plain run
// still gets parallel speedup while staying within a few GB of headroom; the
// hard cap bounds even an explicit override so a fat-fingered `--concurrency 500`
// can not exhaust the host. Operators with RAM to spare can raise `--concurrency`
// up to the cap.
const DEFAULT_BATCH_CONCURRENCY = 2;
const MAX_BATCH_CONCURRENCY = 16;
const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

async function main() {
  const options = { ...optionsFromEnv(), ...parseArgs(process.argv.slice(2)) };
  if (options.help) {
    printHelp();
    return;
  }

  const { summary, runtimeError, runtime } = await runFixtureMatrix(options);
  process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
  if (runtimeError) {
    process.exitCode = runtime.exitCode || 1;
  }
}

export default async function runFixtureMatrixBench() {
  const options = { ...optionsFromEnv(), ...parseArgs(process.argv.slice(2)) };
  // Per-fixture / per-batch failures (PHP OOM in collect_artifacts, capture
  // failures, child timeouts) are already isolated inside `runFixtureMatrix`:
  // each failing batch is recorded as failed fixtures and folded into the
  // aggregate while sibling batches still run (see
  // `runFixtureMatrixBatch`/`mapWithConcurrency`). Re-throwing `runtimeError`
  // here would make the bench harness treat the entire lane as a hard
  // assertion_failure and DISCARD the run -- losing the aggregate and every
  // survivor from the batches that succeeded. Instead, always return the
  // aggregated metrics so the lane records the partial result; the rig's
  // `failed_fixture_count <= 0` result-gate then fails the run (because failed
  // fixtures are counted) WITHOUT discarding it, and `summarizeBenchRun` emits
  // the operator summary on that gate-FAIL. child_command_failures stay in
  // metadata so the failing batch remains attributable. Genuine pre-aggregate
  // setup failures (missing fixtures, composer install) still throw out of
  // `runFixtureMatrix` and legitimately abort the lane.
  const { summary } = await runFixtureMatrix(options);

  const resultSummary = summary.result_summary || {};
  return {
    metrics: {
      fixture_count: Number(summary.fixture_count || 0),
      passed_fixture_count: Number(resultSummary.succeeded || 0),
      failed_fixture_count: Number(resultSummary.failed || 0),
      not_run_fixture_count: Number(resultSummary.not_run || 0),
      finding_count: Number(resultSummary.finding_count || 0),
    },
    artifacts: {
      cli_run: { path: path.join(summary.output_directory, 'cli-run.json') },
      matrix: { path: path.join(summary.output_directory, 'matrix.json') },
      result: { path: summary.result_file },
      summary: { path: path.join(summary.output_directory, 'summary.json') },
      finding_packets: { path: path.join(summary.output_directory, 'finding-packets.json') },
    },
    metadata: {
      matrix_id: summary.matrix_id,
      fixture_root: summary.fixture_root,
      output_directory: summary.output_directory,
      result_summary: summary.result_summary,
      runtime: summary.runtime,
      // Surface failing batches at the top level (also nested in runtime) so a
      // gate-FAIL run stays attributable without re-reading the runtime block.
      ...(summary.child_command_failures?.length ? { child_command_failures: summary.child_command_failures } : {}),
    },
  };
}

export async function runFixtureMatrix(options) {
  const outputDirectory = path.resolve(options.outputDirectory || path.join(process.cwd(), 'artifacts', 'static-site-importer-fixture-matrix'));
  const intake = options.artifactRoot
    ? materializeGeneratedArtifactFixtures({
      artifactRoot: path.resolve(options.artifactRoot),
      fixtureRoot: path.resolve(options.fixtureRoot || path.join(outputDirectory, 'intake-fixtures')),
      entrypoint: options.entrypoint || 'index.html',
      maxDepth: options.maxDepth,
    })
    : null;
  const fixtureRoot = path.resolve(intake?.fixture_root || options.fixtureRoot || path.join(packageRoot, 'tests', 'fixtures', 'fixture-matrix'));
  const staticSiteImporterPath = options.staticSiteImporterPath || process.env.HOMEBOY_STATIC_SITE_IMPORTER_PATH || process.cwd();
  const dependencyOverrides = prepareDependencyOverrides(options);
  ensureComposerDependencies(staticSiteImporterPath, { dependencyOverrides });
  const matrix = createFixtureMatrix({
    id: options.id || `static-site-importer-fixture-matrix-${Date.now()}`,
    fixture_root: fixtureRoot,
    entrypoint: options.entrypoint || 'index.html',
    maxDepth: options.maxDepth,
    // Lane/tag selection: run "just the marketing/static lane" or only fixtures
    // carrying a given manifest tag. Absent options leave the full matrix intact.
    class: options.fixtureClass || options.class,
    tag: options.tag,
  });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: outputDirectory,
    playgroundArtifactsDirectory: options.playgroundArtifactsDirectory || '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    wordpressVersion: options.wordpressVersion,
    staticSiteImporterPath,
    staticSiteImporterPlugin: options.staticSiteImporterPlugin,
    staticSiteImporterSlug: options.staticSiteImporterSlug,
    dependencyOverrides,
    ...editorValidationRecipeInput(options),
    ...visualParityRecipeInput(options),
    ...liveWpParityRecipeInput(options),
  });
  const recipeFile = path.join(outputDirectory, 'wp-codebox-static-site-fixture-matrix-recipe.json');
  fs.writeFileSync(recipeFile, `${JSON.stringify(recipe, null, 2)}\n`);
  const replay = wpCodeboxReplayCommand({
    recipeFile,
    artifactsDir: replayArtifactsDirectory(outputDirectory),
    wpCodeboxBin: options.wpCodeboxBin,
  });

  let runtime = null;
  let runtimeError = null;
  let collectedResult = written.result;
  if (options.run) {
    const batchSize = positiveInteger(options.batchSize, DEFAULT_BATCH_SIZE);
    const concurrency = boundedConcurrency(options.concurrency, DEFAULT_BATCH_CONCURRENCY, MAX_BATCH_CONCURRENCY);
    const batches = chunk(matrix.fixtures, batchSize);
    // Each batch spins up its own isolated WP Codebox sandbox, so batches can run
    // concurrently. `mapWithConcurrency` bounds how many sandboxes are live at
    // once and returns outcomes in batch order, so the assembled batchRuns /
    // batchResults / childCommandFailures stay deterministic regardless of which
    // sandbox finishes first.
    const batchOutcomes = await mapWithConcurrency(batches, concurrency, (fixtures, batchIndex) => runFixtureMatrixBatch({
      fixtures,
      batchIndex,
      matrix,
      outputDirectory,
      staticSiteImporterPath,
      options,
    }));

    const batchRuns = [];
    const batchResults = [];
    const childCommandFailures = [];
    for (const outcome of batchOutcomes) {
      batchRuns.push(outcome.batchRun);
      batchResults.push(outcome.batchResult);
      if (outcome.childCommandFailure) {
        childCommandFailures.push(outcome.childCommandFailure);
      }
      // Preserve the original first-failure-by-batch-order semantics: the earliest
      // batch that failed wins, independent of completion order.
      if (outcome.error) {
        runtimeError ||= outcome.error;
      }
    }
    collectedResult = normalizeFixtureMatrixResult({
      matrix,
      results: batchResults.flatMap((result) => result.fixtures),
      // Editor-quality scoring is always on; the native-rate gate is opt-in.
      editorQuality: editorQualityGateInput(options),
    });
    runtime = {
      exitCode: runtimeError ? (batchRuns.find((batch) => batch.exit_code)?.exit_code || 1) : 0,
      batchSize,
      concurrency,
      batches: batchRuns,
      childCommandFailures,
    };
    writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result: collectedResult });
  }

  const summary = {
    schema: 'static-site-importer/fixture-matrix-cli-run/v1',
    matrix_id: matrix.id,
    fixture_root: matrix.fixture_root,
    fixture_count: matrix.count,
    intake,
    dependency_overrides: dependencyOverrides,
    recipe_dependency_overrides: recipe.metadata?.dependency_overrides || {},
    output_directory: outputDirectory,
    recipe_file: recipeFile,
    replay,
    artifact_refs: written.artifact_refs,
    ...(runtime?.childCommandFailures?.length ? { child_command_failures: runtime.childCommandFailures } : {}),
    result_file: path.join(outputDirectory, 'static-site-fixture-matrix-result.json'),
    result_summary: collectedResult.summary,
    runtime: runtime ? runtimeSummary(runtime, runtimeError) : null,
  };
  fs.writeFileSync(path.join(outputDirectory, 'cli-run.json'), `${JSON.stringify(summary, null, 2)}\n`);
  return { summary, runtimeError, runtime };
}

// Provision and reconcile a single batch in its own WP Codebox sandbox. Pure with
// respect to other batches (it only writes batch-scoped recipe/output files and
// per-fixture artifact subdirectories, all keyed by the unique batch suffix), so
// many of these can run concurrently without colliding. Returns a stable outcome
// the caller folds back together in batch order.
export async function runFixtureMatrixBatch({ fixtures, batchIndex, matrix, outputDirectory, staticSiteImporterPath, options }) {
  const batchNumber = batchIndex + 1;
  const batchSuffix = String(batchNumber).padStart(3, '0');
  const batchMatrix = createFixtureMatrix({
    id: `${matrix.id}-batch-${batchSuffix}`,
    fixture_root: matrix.fixture_root,
    entrypoint: matrix.entrypoint,
    fixtures,
  });
  const batchRecipe = buildFixtureMatrixRecipe({
    matrix: batchMatrix,
    artifactsDirectory: outputDirectory,
    playgroundArtifactsDirectory: options.playgroundArtifactsDirectory || '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    wordpressVersion: options.wordpressVersion,
    staticSiteImporterPath,
    staticSiteImporterPlugin: options.staticSiteImporterPlugin,
    staticSiteImporterSlug: options.staticSiteImporterSlug,
    dependencyOverrides: prepareDependencyOverrides(options),
    ...editorValidationRecipeInput(options),
    ...visualParityRecipeInput(options),
    ...liveWpParityRecipeInput(options),
  });
  const batchRecipeFile = path.join(outputDirectory, `wp-codebox-static-site-fixture-matrix-batch-${batchSuffix}.json`);
  const outputFile = path.join(outputDirectory, `wp-codebox-output-batch-${batchSuffix}.json`);
  const codeboxArtifactsDirectory = batchCodeboxArtifactsDirectory(outputDirectory, batchSuffix);
  const artifactRefs = batchArtifactRefs({ outputDirectory, batchSuffix, batchRecipeFile, outputFile, codeboxArtifactsDirectory });
  fs.writeFileSync(batchRecipeFile, `${JSON.stringify(batchRecipe, null, 2)}\n`);

  let batchRuntime = null;
  let batchError = null;
  let childCommandFailure = null;
  try {
    batchRuntime = await runWpCodeboxRecipe({
      recipeFile: batchRecipeFile,
      artifactsDir: codeboxArtifactsDirectory,
      outputFile,
      wpCodeboxBin: options.wpCodeboxBin,
    });
  } catch (error) {
    batchError = error;
    batchRuntime = {
      exitCode: error?.code ?? 1,
      outputFile,
      json: parseJsonText(error?.stdout),
    };
    childCommandFailure = buildWpCodeboxChildCommandFailure({
      error,
      batchNumber,
      batchSuffix,
      batchRecipeFile,
      outputFile,
      artifactsDir: codeboxArtifactsDirectory,
      wpCodeboxBin: options.wpCodeboxBin,
      artifactRefs,
    });
  }

  const batchRun = fixtureMatrixBatchRunSummary({
    batchNumber,
    batchMatrix,
    fixtures,
    batchRecipeFile,
    outputFile,
    codeboxArtifactsDirectory,
    batchRuntime,
    batchError,
  });
  const batchResult = collectFixtureMatrixRunResults({
    matrix: batchMatrix,
    outputDirectory,
    outputFile,
    codeboxOutput: batchRuntime?.json,
    codeboxError: batchError,
    visualParity: visualParityGateInput(options),
    liveWpParity: liveWpParityCollectorInput(options),
  });

  return { batchRun, batchResult, error: batchError, childCommandFailure };
}

// Bounded-concurrency map that preserves input ordering. Spawns at most `limit`
// workers, each pulling the next index off a shared cursor, so up to `limit`
// async tasks are in flight at once while `results[i]` always corresponds to
// `items[i]` regardless of completion order.
export async function mapWithConcurrency(items, limit, worker) {
  const results = new Array(items.length);
  if (items.length === 0) {
    return results;
  }
  const poolSize = Math.max(1, Math.min(limit, items.length));
  let cursor = 0;
  const runWorker = async () => {
    while (true) {
      const index = cursor;
      cursor += 1;
      if (index >= items.length) {
        return;
      }
      results[index] = await worker(items[index], index);
    }
  };
  await Promise.all(Array.from({ length: poolSize }, () => runWorker()));
  return results;
}

export function boundedConcurrency(value, fallback, max) {
  const parsed = positiveInteger(value, fallback);
  return Math.max(1, Math.min(parsed, max));
}

function ensureComposerDependencies(pluginPath, options = {}) {
  const dependencyOverrides = options.dependencyOverrides || {};
  const blocksEnginePhpTransformerPath = dependencyOverrides.blocks_engine_php_transformer?.path || '';
  if (blocksEnginePhpTransformerPath) {
    updateComposerPathRepository(pluginPath, blocksEnginePhpTransformerPath);
    return;
  }

  if (fs.existsSync(path.join(pluginPath, 'vendor', 'autoload.php')) || !fs.existsSync(path.join(pluginPath, 'composer.json'))) {
    return;
  }

  const result = spawnSync('composer', ['install', '--no-interaction', '--prefer-dist', '--no-progress'], {
    cwd: pluginPath,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  if (result.status !== 0) {
    throw new Error(`Composer dependency install failed for ${pluginPath}: ${result.stderr || result.stdout || `exit ${result.status}`}`);
  }
}

function prepareDependencyOverrides(options) {
  const blocksEnginePhpTransformerPath = resolveBlocksEnginePhpTransformerPath(options.blocksEnginePhpTransformerPath);
  return {
    ...(blocksEnginePhpTransformerPath
      ? {
        blocks_engine_php_transformer: {
          package: 'automattic/blocks-engine-php-transformer',
          path: blocksEnginePhpTransformerPath,
        },
      }
      : {}),
  };
}

export function resolveBlocksEnginePhpTransformerPath(input) {
  if (!input) {
    return '';
  }

  const candidate = path.resolve(input);
  const packageComposer = path.join(candidate, 'composer.json');
  if (composerPackageName(packageComposer) === 'automattic/blocks-engine-php-transformer') {
    return candidate;
  }

  const nested = path.join(candidate, 'php-transformer');
  if (composerPackageName(path.join(nested, 'composer.json')) === 'automattic/blocks-engine-php-transformer') {
    return nested;
  }

  throw new Error(`Blocks Engine PHP transformer path must point to the package or Blocks Engine repo root: ${input}`);
}

function composerPackageName(composerFile) {
  try {
    const composer = JSON.parse(fs.readFileSync(composerFile, 'utf8'));
    return typeof composer.name === 'string' ? composer.name : '';
  } catch {
    return '';
  }
}

function updateComposerPathRepository(pluginPath, packagePath) {
  const composerFile = path.join(pluginPath, 'composer.json');
  const lockFile = path.join(pluginPath, 'composer.lock');
  const composerJson = fs.readFileSync(composerFile, 'utf8');
  const composerLock = fs.existsSync(lockFile) ? fs.readFileSync(lockFile, 'utf8') : null;
  let result = null;
  try {
    configureComposerPathRepository(pluginPath, packagePath);
    result = spawnSync('composer', ['update', 'automattic/blocks-engine-php-transformer', '--with-dependencies', '--no-interaction', '--prefer-source', '--no-progress'], {
      cwd: pluginPath,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } finally {
    fs.writeFileSync(composerFile, composerJson);
    if (composerLock !== null) {
      fs.writeFileSync(lockFile, composerLock);
    }
  }
  if (result.status !== 0) {
    throw new Error(`Composer dependency override failed for ${pluginPath}: ${result.stderr || result.stdout || `exit ${result.status}`}`);
  }
}

function configureComposerPathRepository(pluginPath, packagePath) {
  const composerFile = path.join(pluginPath, 'composer.json');
  const composer = JSON.parse(fs.readFileSync(composerFile, 'utf8'));
  composer.repositories = composer.repositories && typeof composer.repositories === 'object' && !Array.isArray(composer.repositories)
    ? composer.repositories
    : {};
  composer.repositories['blocks-engine-php-transformer-dev'] = composerPathRepositoryConfig(composer, packagePath);
  fs.writeFileSync(composerFile, `${JSON.stringify(composer, null, 2)}\n`);
}

export function composerPathRepositoryConfig(rootComposer, packagePath) {
  return {
    type: 'path',
    url: packagePath,
    canonical: true,
    options: {
      symlink: false,
      versions: {
        'automattic/blocks-engine-php-transformer': composerPathRepositoryVersion(rootComposer),
      },
    },
  };
}

export function fixtureMatrixBatchRunSummary(input = {}) {
  const batchError = input.batchError || null;
  const batchRuntime = input.batchRuntime || null;
  const fixtureIds = normalizeFixtureIds(input.fixtures);
  return {
    batch: input.batchNumber,
    batch_id: input.batchMatrix?.id || '',
    fixture_ids: fixtureIds,
    fixture_count: fixtureIds.length,
    recipe_file: input.batchRecipeFile || '',
    output_file: input.outputFile || '',
    codebox_artifacts_directory: input.codeboxArtifactsDirectory || '',
    exit_code: batchRuntime?.exitCode ?? 0,
    error: batchError ? batchError.message : '',
    stderr_tail: batchError ? textTail(batchError.stderr) : '',
    stdout_tail: batchError ? textTail(batchError.stdout) : '',
    parsed_output: Boolean(batchRuntime?.json),
  };
}

function normalizeFixtureIds(fixtures) {
  return Array.isArray(fixtures) ? fixtures.map((fixture) => fixture.id).filter(Boolean) : [];
}

function composerPathRepositoryVersion(rootComposer) {
  const constraint = rootComposer?.require?.['automattic/blocks-engine-php-transformer'];
  if (typeof constraint !== 'string') {
    return '0.1.15';
  }

  const trimmed = constraint.trim();
  const match = trimmed.match(/^\^?(\d+\.\d+\.\d+)$/);
  return match ? match[1] : '0.1.15';
}

function runtimeSummary(runtime, runtimeError) {
  return {
    exit_code: runtime.exitCode,
    ...(runtime.batchSize ? { batch_size: runtime.batchSize } : {}),
    ...(runtime.concurrency ? { concurrency: runtime.concurrency } : {}),
    ...(runtime.batches ? { batches: runtime.batches } : {}),
    ...(runtime.childCommandFailures?.length ? { child_command_failures: runtime.childCommandFailures } : {}),
    error: runtimeError ? runtimeError.message : '',
  };
}

function buildWpCodeboxChildCommandFailure({ error, batchNumber, batchSuffix, batchRecipeFile, outputFile, artifactsDir, wpCodeboxBin: bin, artifactRefs }) {
  const command = wpCodeboxRecipeRunCommand({ recipeFile: batchRecipeFile, artifactsDir, outputFile, wpCodeboxBin: bin });
  return {
    schema: 'homeboy/child-command-failure/v1',
    kind: 'child_command_failed',
    label: `WP Codebox recipe-run batch ${batchSuffix}`,
    batch: batchNumber,
    batch_id: `batch-${batchSuffix}`,
    command,
    exit_status: exitStatus(error),
    stdout_tail: tailText(error?.stdout),
    stderr_tail: tailText(error?.stderr),
    artifact_refs: artifactRefs,
    message: error?.message || 'WP Codebox recipe-run failed',
  };
}

function wpCodeboxRecipeRunCommand({ recipeFile, artifactsDir, outputFile, wpCodeboxBin: bin }) {
  const base = wpCodeboxCommand(bin || wpCodeboxBin());
  const argv = [
    base.command,
    ...(base.args || []),
    'recipe-run',
    recipeFile,
    '--artifacts-dir', artifactsDir,
    '--output', outputFile,
  ];
  return { argv };
}

function wpCodeboxReplayCommand({ recipeFile, artifactsDir, wpCodeboxBin: bin }) {
  const base = safeWpCodeboxCommand(bin);
  const argv = [
    base.command,
    ...(base.args || []),
    'recipe-run',
    '--recipe', recipeFile,
    '--artifacts', artifactsDir,
    '--json',
  ];
  return {
    artifacts_directory: artifactsDir,
    argv,
    command: argv.map(shellArg).join(' '),
  };
}

function safeWpCodeboxCommand(bin) {
  return { command: bin || process.env.HOMEBOY_WP_CODEBOX_BIN || 'wp-codebox', args: [] };
}

function replayArtifactsDirectory(outputDirectory) {
  const resolved = path.resolve(outputDirectory);
  return path.join(path.dirname(resolved), `${path.basename(resolved)}-wp-codebox-replay-artifacts`);
}

function batchCodeboxArtifactsDirectory(outputDirectory, batchSuffix) {
  const resolved = path.resolve(outputDirectory);
  return path.join(path.dirname(resolved), `${path.basename(resolved)}-wp-codebox-batch-${batchSuffix}-artifacts`);
}

function batchArtifactRefs({ outputDirectory, batchSuffix, batchRecipeFile, outputFile, codeboxArtifactsDirectory }) {
  return {
    artifacts_directory: codeboxArtifactsDirectory,
    recipe_file: batchRecipeFile,
    output_file: outputFile,
    fixture_artifacts_directory: outputDirectory,
    codebox_artifacts_directory: codeboxArtifactsDirectory,
    cli_run: path.join(outputDirectory, 'cli-run.json'),
    matrix: path.join(outputDirectory, 'matrix.json'),
    result: path.join(outputDirectory, 'static-site-fixture-matrix-result.json'),
    summary: path.join(outputDirectory, 'summary.json'),
    finding_packets: path.join(outputDirectory, 'finding-packets.json'),
    batch_recipe: path.join(outputDirectory, `wp-codebox-static-site-fixture-matrix-batch-${batchSuffix}.json`),
    batch_output: path.join(outputDirectory, `wp-codebox-output-batch-${batchSuffix}.json`),
  };
}

function exitStatus(error) {
  const status = error?.status ?? error?.exitCode ?? error?.code;
  return Number.isInteger(status) ? status : 1;
}

function tailText(value, maxLines = 40) {
  if (!value) {
    return '';
  }
  return String(value).split(/\r?\n/).slice(-maxLines).join('\n');
}

function parseArgs(args) {
  const options = {};
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
    if (arg.startsWith('--no-')) {
      options[camelCase(arg.slice(5))] = false;
      continue;
    }
    if (arg.startsWith('--')) {
      const [rawKey, rawValue] = arg.slice(2).split('=');
      const key = camelCase(rawKey);
      const value = rawValue === undefined ? args[index + 1] : rawValue;
      if (rawValue === undefined) {
        index += 1;
      }
      options[key] = value;
      continue;
    }
    if (!options.fixtureRoot) {
      options.fixtureRoot = arg;
    }
  }
  return options;
}

function optionsFromEnv(env = process.env) {
  const benchEnv = settingsBenchEnv(env);
  return {
    fixtureRoot: benchEnv.SSI_FIXTURE_MATRIX_FIXTURE_ROOT || env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT,
    outputDirectory: benchEnv.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY || env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY || env.HOMEBOY_BENCH_ARTIFACTS_DIR,
    staticSiteImporterPath: benchEnv.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH || env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH,
    staticSiteImporterSlug: benchEnv.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_SLUG || env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_SLUG,
    staticSiteImporterPlugin: benchEnv.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PLUGIN || env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PLUGIN,
    entrypoint: benchEnv.SSI_FIXTURE_MATRIX_ENTRYPOINT || env.SSI_FIXTURE_MATRIX_ENTRYPOINT,
    maxDepth: benchEnv.SSI_FIXTURE_MATRIX_MAX_DEPTH || env.SSI_FIXTURE_MATRIX_MAX_DEPTH,
    // Lane/tag selection from the manifest classification: run a single class lane
    // and/or only fixtures carrying a given manifest tag.
    fixtureClass: benchEnv.SSI_FIXTURE_MATRIX_CLASS || env.SSI_FIXTURE_MATRIX_CLASS,
    tag: benchEnv.SSI_FIXTURE_MATRIX_TAG || env.SSI_FIXTURE_MATRIX_TAG,
    artifactRoot: benchEnv.SSI_FIXTURE_MATRIX_ARTIFACT_ROOT || env.SSI_FIXTURE_MATRIX_ARTIFACT_ROOT,
    blocksEnginePhpTransformerPath: benchEnv.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH || env.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH,
    wordpressVersion: benchEnv.SSI_FIXTURE_MATRIX_WORDPRESS_VERSION || env.SSI_FIXTURE_MATRIX_WORDPRESS_VERSION,
    batchSize: benchEnv.SSI_FIXTURE_MATRIX_BATCH_SIZE || env.SSI_FIXTURE_MATRIX_BATCH_SIZE,
    concurrency: benchEnv.SSI_FIXTURE_MATRIX_CONCURRENCY || env.SSI_FIXTURE_MATRIX_CONCURRENCY,
    run: isTruthy(benchEnv.SSI_FIXTURE_MATRIX_RUN) || isTruthy(env.SSI_FIXTURE_MATRIX_RUN),
    wpCodeboxBin: benchEnv.SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN || env.SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN,
    editorValidation: !isFalsy(benchEnv.SSI_FIXTURE_MATRIX_EDITOR_VALIDATION ?? env.SSI_FIXTURE_MATRIX_EDITOR_VALIDATION),
    visualParity: !isFalsy(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY),
    visualParityGate: isTruthy(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE) || isTruthy(env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE),
    visualParityFullPage: isTruthy(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE) || isTruthy(env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE),
    // Opt-in live-WP parity capture + comparison. Off by default; mirrors the
    // visual-parity-gate truthy env mapping. When on, the recipe appends the
    // capture-html step and the result collector runs the live-wp-parity comparator.
    liveWpParity: isTruthy(benchEnv.SSI_FIXTURE_MATRIX_LIVE_WP_PARITY) || isTruthy(env.SSI_FIXTURE_MATRIX_LIVE_WP_PARITY),
    pixelThreshold: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD,
    visualParityCandidateUrl: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_CANDIDATE_URL || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_CANDIDATE_URL,
    visualParitySourceBaseUrl: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_SOURCE_BASE_URL || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_SOURCE_BASE_URL,
    minNativeRate: benchEnv.SSI_FIXTURE_MATRIX_MIN_NATIVE_RATE || env.SSI_FIXTURE_MATRIX_MIN_NATIVE_RATE,
  };
}

// Editor-validation recipe option. The wordpress.editor-validate-blocks step
// launches a browser per imported site and is the slowest per-fixture step, so
// --no-editor-validation (SSI_FIXTURE_MATRIX_EDITOR_VALIDATION=0) skips it while
// leaving native-rate/loss-classes/findings intact. Enabled by default.
function editorValidationRecipeInput(options) {
  return {
    editorValidation: options.editorValidation !== false,
  };
}

// Visual-parity options shared by the recipe (capture step) and the result
// collector (gating). Enable defaults on; gating defaults off (opt-in).
function visualParityRecipeInput(options) {
  return {
    visualParity: options.visualParity !== false,
    pixelThreshold: options.pixelThreshold,
    visualParityCandidateUrl: options.visualParityCandidateUrl,
    visualParitySourceBaseUrl: options.visualParitySourceBaseUrl,
    visualParityFullPage: options.visualParityFullPage,
  };
}

function visualParityGateInput(options) {
  return {
    threshold: options.pixelThreshold,
    gate: options.visualParityGate === true,
  };
}

// Live-WP parity recipe option. Off by default; when on, `liveWpParityEnabled`
// in the recipe builder appends the deterministic capture-html step per fixture.
// `liveWpParity: false` is inert in the recipe builder (the capture step is only
// added when truthy), so the OFF recipe is byte-identical to today.
function liveWpParityRecipeInput(options) {
  return {
    liveWpParity: options.liveWpParity === true,
  };
}

// Live-WP parity result-collector option. Off by default. When on, supplies the
// comparator package path so the result collector can score each fixture's
// captured rendered DOM against its staged source (with the render-free proxy
// delta). Resolving the transformer path only when enabled avoids touching the
// OFF path. A live-WP failure is isolated inside the collector (never sinks the lane).
function liveWpParityCollectorInput(options) {
  if (options.liveWpParity !== true) {
    return { enabled: false };
  }
  return {
    enabled: true,
    blocksEnginePhpTransformerPath: resolveBlocksEnginePhpTransformerPath(options.blocksEnginePhpTransformerPath),
    withProxy: true,
  };
}

// Editor-quality gate options for the result collector. Scoring always runs;
// `minNativeRate` defaults to absent (off) so gating is opt-in.
function editorQualityGateInput(options) {
  return {
    minNativeRate: options.minNativeRate,
  };
}

function settingsBenchEnv(env = process.env) {
  try {
    const settings = JSON.parse(env.HOMEBOY_SETTINGS_JSON || '{}');
    return settings && typeof settings.bench_env === 'object' && !Array.isArray(settings.bench_env)
      ? settings.bench_env
      : {};
  } catch {
    return {};
  }
}

function isTruthy(value) {
  return value === true || value === '1' || value === 'true';
}

function isFalsy(value) {
  return value === false || value === '0' || value === 'false' || value === 'no' || value === 'off';
}

function chunk(items, size) {
  const chunks = [];
  for (let index = 0; index < items.length; index += size) {
    chunks.push(items.slice(index, index + size));
  }
  return chunks;
}

function positiveInteger(value, fallback) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function parseJsonText(text) {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
}

function textTail(value, maxLength = 4000) {
  if (typeof value !== 'string' || value.length === 0) {
    return '';
  }
  return value.length > maxLength ? value.slice(value.length - maxLength) : value;
}

function camelCase(value) {
  return value.replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase());
}

function shellArg(value) {
  const text = String(value);
  return /^[A-Za-z0-9_/:=.,+@%-]+$/.test(text) ? text : `'${text.replace(/'/g, `'\\''`)}'`;
}

function printHelp() {
  process.stdout.write(`Usage: static-site-fixture-matrix [fixture-root] [options]\n\nOptions:\n  --fixture-root <path>              Static-site fixture root. Defaults to this package's fixtures directory.\n  --output-directory <path>          Artifact output directory.\n  --static-site-importer-path <path> Static Site Importer checkout/plugin directory.\n  --static-site-importer-slug <slug> Plugin slug. Defaults to static-site-importer.\n  --static-site-importer-plugin <p>  Plugin activation file. Defaults to static-site-importer/static-site-importer.php.\n  --artifact-root <path>             Generated artifact root to normalize into fixtures.\n  --blocks-engine-php-transformer-path <path>\n                                     Blocks Engine repo root or php-transformer package path for Composer.\n  --entrypoint <file>                Fixture entrypoint. Defaults to index.html.\n  --max-depth <n>                    Fixture discovery depth. Defaults to 2.\n  --wordpress-version <version>      WP Codebox WordPress version. Defaults to latest.\n  --batch-size <n>                   Fixtures per WP Codebox run when --run is used. Defaults to 10.\n  --concurrency <n>                  Batches (WP Codebox sandboxes) to run in parallel. Defaults to ${DEFAULT_BATCH_CONCURRENCY}, hard-capped at ${MAX_BATCH_CONCURRENCY}.\n  --no-editor-validation            Skip browser editor block validation.\n  --no-visual-parity                Skip wordpress.visual-compare recipe steps. Same as SSI_FIXTURE_MATRIX_VISUAL_PARITY=0.\n  --run                             Execute WP Codebox recipes. Omit locally to only materialize artifacts.\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  await main();
}
