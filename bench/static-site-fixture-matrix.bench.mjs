#!/usr/bin/env node

/**
 * External dependencies
 */
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Internal dependencies
 */
import { runWpCodeboxRecipe, wpCodeboxCommand, wpCodeboxBin } from '../tools/wp-codebox/recipe.mjs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import {
  buildFixtureMatrixRecipe,
  classifyVisualDiffRegions,
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

export default async function runFixtureMatrixBench(context = {}) {
  const args = Array.isArray(context.args) ? context.args : process.argv.slice(2);
  const options = { ...optionsFromEnv(), ...parseArgs(args) };
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
      ...numericMetricMap(resultSummary.fixture_failure_categories || {}, 'failed_fixture_category'),
    },
    artifacts: {
      cli_run: { path: path.join(summary.output_directory, 'cli-run.json') },
      matrix: { path: path.join(summary.output_directory, 'matrix.json') },
      result: { path: summary.result_file },
      summary: { path: path.join(summary.output_directory, 'summary.json') },
      finding_packets: { path: path.join(summary.output_directory, 'finding-packets.json') },
      visual_parity_evidence_report: { path: path.join(summary.output_directory, 'visual-parity-evidence-report.json') },
      visual_parity_evidence_report_markdown: { path: path.join(summary.output_directory, 'visual-parity-evidence-report.md') },
      visual_diff_classification: { path: path.join(summary.output_directory, 'visual-diff-classification.json') },
      gutenberg_incompatibility_registry: { path: path.join(summary.output_directory, 'gutenberg-incompatibility-registry.json') },
      gutenberg_incompatibility_registry_report: { path: path.join(summary.output_directory, 'gutenberg-incompatibility-registry.md') },
      ...(summary.visual_parity_artifacts || {}),
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

function numericMetricMap(values, prefix) {
  return Object.fromEntries(Object.entries(values || {}).map(([key, value]) => [`${prefix}_${key}`, Number(value || 0)]));
}

function writeJsonArtifact(filePath, payload) {
  fs.writeFileSync(filePath, jsonArtifactText(payload));
}

function jsonArtifactText(payload) {
  return `${JSON.stringify(payload, null, 2)}\n`;
}

function fileBytes(filePath) {
  try {
    return fs.statSync(filePath).size;
  } catch {
    return 0;
  }
}

function nowMs() {
  return process.hrtime.bigint();
}

function elapsedMs(startedAt) {
  return Number((process.hrtime.bigint() - startedAt) / 1000000n);
}

export async function runFixtureMatrix(options) {
  const performance = {};
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
    // Lane selection comes from authored fixture manifests only. Absent options
    // leave the full matrix intact; missing metadata stays unknown rather than guessed.
    class: options.fixtureClass || options.class,
    tag: options.tag,
    capabilities: options.capability || options.capabilities,
    risk_profile: options.riskProfile || options.risk_profile,
    complexity: options.complexity,
    max_complexity: options.maxComplexity || options.max_complexity,
  });
  const artifactWriteStartedAt = nowMs();
  const written = writeFixtureMatrixArtifacts({
    outputDirectory,
    matrix,
    ...visualParityRecipeInput(options),
    ...liveWpParityRecipeInput(options),
  });
  performance.artifact_writing_ms = elapsedMs(artifactWriteStartedAt);
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
  writeJsonArtifact(recipeFile, recipe);
  const replay = wpCodeboxReplayCommand({
    recipeFile,
    artifactsDir: replayArtifactsDirectory(outputDirectory),
    wpCodeboxBin: options.wpCodeboxBin,
  });

  let runtime = null;
  let runtimeError = null;
  let collectedResult = written.result;
  let visualParityArtifacts = {};
  if (options.run) {
    const batchSize = positiveInteger(options.batchSize, DEFAULT_BATCH_SIZE);
    const concurrency = boundedConcurrency(options.concurrency, DEFAULT_BATCH_CONCURRENCY, MAX_BATCH_CONCURRENCY);
    const batches = chunk(matrix.fixtures, batchSize);
    // Each batch spins up its own isolated WP Codebox sandbox, so batches can run
    // concurrently. `mapWithConcurrency` bounds how many sandboxes are live at
    // once and returns outcomes in batch order, so the assembled batchRuns /
    // batchResults / childCommandFailures stay deterministic regardless of which
    // sandbox finishes first.
    const batchExecutionStartedAt = nowMs();
    const batchOutcomes = await mapWithConcurrency(batches, concurrency, (fixtures, batchIndex) => runFixtureMatrixBatch({
      fixtures,
      batchIndex,
      matrix,
      outputDirectory,
      staticSiteImporterPath,
      options,
    }));
    performance.batch_execution_ms = elapsedMs(batchExecutionStartedAt);

    const batchRuns = [];
    const batchResults = [];
    const childCommandFailures = [];
    for (const outcome of batchOutcomes) {
      batchRuns.push(outcome.batchRun);
      batchResults.push(outcome.batchResult);
      visualParityArtifacts = { ...visualParityArtifacts, ...(outcome.visualParityArtifacts || {}) };
      if (outcome.childCommandFailure) {
        childCommandFailures.push(outcome.childCommandFailure);
      }
      // Preserve the original first-failure-by-batch-order semantics: the earliest
      // batch that failed wins, independent of completion order.
      if (outcome.error) {
        runtimeError ||= outcome.error;
      }
    }
    const resultAssemblyStartedAt = nowMs();
    collectedResult = normalizeFixtureMatrixResult({
      matrix,
      results: attributeChildCommandFailures(batchResults.flatMap((result) => result.fixtures), childCommandFailures),
      // Editor-quality scoring is always on; the native-rate gate is opt-in.
      editorQuality: editorQualityGateInput(options),
    });
    performance.result_assembly_ms = elapsedMs(resultAssemblyStartedAt);
    runtime = {
      exitCode: runtimeError ? (batchRuns.find((batch) => batch.exit_code)?.exit_code || 1) : 0,
      batchSize,
      concurrency,
      batches: batchRuns,
      childCommandFailures,
    };
    const resultArtifactRewriteStartedAt = nowMs();
    writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result: collectedResult });
    performance.result_artifact_writing_ms = elapsedMs(resultArtifactRewriteStartedAt);
  }

  const writtenArtifactBytes = written.metadata?.artifact_bytes || {};
  const artifactBytes = {
    fixture_artifacts: Number(writtenArtifactBytes.fixture_artifacts || 0),
    staged_source: Number(writtenArtifactBytes.staged_source || 0),
    matrix: Number(writtenArtifactBytes.matrix || 0),
    recipe: fileBytes(recipeFile),
    result: fileBytes(path.join(outputDirectory, 'static-site-fixture-matrix-result.json')),
    summary: fileBytes(path.join(outputDirectory, 'summary.json')),
    finding_packets: fileBytes(path.join(outputDirectory, 'finding-packets.json')),
    visual_parity_evidence_report: fileBytes(path.join(outputDirectory, 'visual-parity-evidence-report.json')),
    visual_parity_evidence_report_markdown: fileBytes(path.join(outputDirectory, 'visual-parity-evidence-report.md')),
    visual_diff_classification: fileBytes(path.join(outputDirectory, 'visual-diff-classification.json')),
    gutenberg_incompatibility_registry: fileBytes(path.join(outputDirectory, 'gutenberg-incompatibility-registry.json')),
    gutenberg_incompatibility_registry_report: fileBytes(path.join(outputDirectory, 'gutenberg-incompatibility-registry.md')),
  };
  artifactBytes.total = Object.entries(artifactBytes)
    .filter(([key, value]) => key !== 'total' && Number.isFinite(Number(value)))
    .reduce((total, [, value]) => total + Number(value), 0);

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
    metadata: {
      performance,
      artifact_bytes: artifactBytes,
      source_staging: written.metadata?.source_staging,
    },
    ...(runtime?.childCommandFailures?.length ? { child_command_failures: runtime.childCommandFailures } : {}),
    result_file: path.join(outputDirectory, 'static-site-fixture-matrix-result.json'),
    visual_parity_artifacts: visualParityArtifacts,
    result_summary: collectedResult.summary,
    runtime: runtime ? runtimeSummary(runtime, runtimeError) : null,
  };
  const cliRunBaseTotal = summary.metadata.artifact_bytes.total;
  for (let index = 0; index < 3; index += 1) {
    summary.metadata.artifact_bytes.cli_run = Buffer.byteLength(jsonArtifactText(summary));
    summary.metadata.artifact_bytes.total = cliRunBaseTotal + summary.metadata.artifact_bytes.cli_run;
  }
  writeJsonArtifact(path.join(outputDirectory, 'cli-run.json'), summary);
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
  writeJsonArtifact(batchRecipeFile, batchRecipe);

  let batchRuntime = null;
  let batchError = null;
  let childCommandFailure = null;
  let childRecipeRunMs = 0;
  const childRecipeRunStartedAt = nowMs();
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
      fixtures,
      batchNumber,
      batchSuffix,
      batchRecipeFile,
      outputFile,
      artifactsDir: codeboxArtifactsDirectory,
      wpCodeboxBin: options.wpCodeboxBin,
      artifactRefs,
    });
  } finally {
    childRecipeRunMs = elapsedMs(childRecipeRunStartedAt);
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
    performance: {
      child_recipe_run_ms: childRecipeRunMs,
    },
    artifactBytes: {
      batch_recipe: fileBytes(batchRecipeFile),
      batch_output: fileBytes(outputFile),
    },
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
  const visualCompare = materializeVisualCompareArtifacts({
    result: batchResult,
    codeboxArtifactsDirectory,
    outputDirectory,
  });

  return { batchRun, batchResult: visualCompare.result, visualParityArtifacts: visualCompare.artifacts, error: batchError, childCommandFailure };
}

export function materializeVisualCompareArtifacts(input = {}) {
  const result = input.result || {};
  const outputDirectory = path.resolve(input.outputDirectory || input.output_directory || '');
  const codeboxArtifactsDirectory = path.resolve(input.codeboxArtifactsDirectory || input.codebox_artifacts_directory || '');
  const artifacts = {};
  const fixtures = Array.isArray(result.fixtures) ? result.fixtures : [];
  const updatedFixtures = fixtures.map((fixture) => materializeFixtureVisualCompareArtifacts({
    fixture,
    outputDirectory,
    codeboxArtifactsDirectory,
    artifacts,
  }));
  return {
    result: { ...result, fixtures: updatedFixtures },
    artifacts,
  };
}

function materializeFixtureVisualCompareArtifacts({ fixture, outputDirectory, codeboxArtifactsDirectory, artifacts }) {
  const visualParityArtifacts = fixture.visual_parity_artifacts || fixture.visualParityArtifacts;
  const slots = visualParityArtifacts?.artifacts || {};
  const fixtureId = fixture.fixture_id || fixture.fixtureId || '';
  if (!fixtureId || !slots || typeof slots !== 'object') {
    return fixture;
  }

  const rewrites = new Map();
  const updatedSlots = { ...slots };
  for (const slot of [
    ['source_screenshot', 'source', ['source_screenshot']],
    ['imported_screenshot', 'candidate', ['imported_screenshot', 'candidate_screenshot']],
    ['diff_screenshot', 'diff', ['diff_screenshot']],
  ]) {
    const [slotName, fileStem, artifactIds] = slot;
    const refPath = slots[slotName]?.ref?.path || visualDiagnosticRefPath(fixture.diagnostics, artifactIds);
    const sourcePath = resolveCodeboxArtifactPath(refPath, codeboxArtifactsDirectory);
    if (!sourcePath || !fs.existsSync(sourcePath)) {
      continue;
    }
    const persistedPath = path.join(outputDirectory, 'visual-compare', fixtureId, `${fileStem}.png`);
    fs.mkdirSync(path.dirname(persistedPath), { recursive: true });
    fs.copyFileSync(sourcePath, persistedPath);
    rewrites.set(refPath, persistedPath);
    updatedSlots[slotName] = {
      ...slots[slotName],
      status: 'captured',
      kind: slotName,
      ref: artifactRef(slotName, persistedPath, 'visual-parity'),
    };
    artifacts[`visual_compare_${artifactKey(fixtureId)}_${fileStem}`] = { path: persistedPath };
  }

  if (rewrites.size === 0) {
    return fixture;
  }

  const updatedVisualParityArtifacts = {
    ...visualParityArtifacts,
    owner: 'bench_artifact_root',
    missing: undefined,
    artifacts: updatedSlots,
  };
  const classification = classifyVisualDiffRegions({
    visual_parity_artifacts: updatedVisualParityArtifacts,
    comparison: {
      ...(visualParityArtifacts.metrics || {}),
      mismatchPixels: visualParityArtifacts.metrics?.mismatch_pixels,
      totalPixels: visualParityArtifacts.metrics?.total_pixels,
      overlapMismatchPixels: visualParityArtifacts.metrics?.overlap_mismatch_pixels,
      overlapPixels: visualParityArtifacts.metrics?.overlap_pixels,
      dimensionMismatch: visualParityArtifacts.metrics?.dimension_mismatch,
    },
    files: {
      sourceScreenshot: updatedSlots.source_screenshot?.ref?.path,
      candidateScreenshot: updatedSlots.imported_screenshot?.ref?.path,
      diffScreenshot: updatedSlots.diff_screenshot?.ref?.path,
    },
  }, { fixtureArtifactsDirectory: outputDirectory });

  return {
    ...fixture,
    diagnostics: rewriteDiagnosticArtifactRefs(fixture.diagnostics, rewrites),
    artifact_refs: rewriteArtifactRefs(fixture.artifact_refs, rewrites),
    visual_parity_artifacts: classification ? {
      ...updatedVisualParityArtifacts,
      visual_diff_regions: classification.visual_diff_regions,
      visual_diff_cause_summary: classification.visual_diff_cause_summary,
      visual_diff_classification: classification,
    } : updatedVisualParityArtifacts,
    ...(classification ? {
      visual_diff_regions: classification.visual_diff_regions,
      visual_diff_cause_summary: classification.visual_diff_cause_summary,
      visual_diff_classification: classification,
    } : {}),
  };
}

function visualDiagnosticRefPath(diagnostics, artifactIds) {
  for (const diagnostic of Array.isArray(diagnostics) ? diagnostics : []) {
    for (const ref of Array.isArray(diagnostic?.artifact_refs) ? diagnostic.artifact_refs : []) {
      if (artifactIds.includes(ref?.artifact_id) && ref.path) {
        return ref.path;
      }
    }
  }
  return '';
}

function resolveCodeboxArtifactPath(refPath, codeboxArtifactsDirectory) {
  if (!refPath || !codeboxArtifactsDirectory) {
    return '';
  }
  if (path.isAbsolute(refPath)) {
    return refPath;
  }
  const directPath = path.join(codeboxArtifactsDirectory, refPath);
  if (fs.existsSync(directPath)) {
    return directPath;
  }
  for (const entry of safeReadDirectory(codeboxArtifactsDirectory)) {
    if (!entry.name.startsWith('runtime-') || !entry.isDirectory()) {
      continue;
    }
    const runtimePath = path.join(codeboxArtifactsDirectory, entry.name, refPath);
    if (fs.existsSync(runtimePath)) {
      return runtimePath;
    }
  }
  return directPath;
}

function safeReadDirectory(directory) {
  try {
    return fs.readdirSync(directory, { withFileTypes: true });
  } catch {
    return [];
  }
}

function rewriteDiagnosticArtifactRefs(diagnostics, rewrites) {
  return Array.isArray(diagnostics)
    ? diagnostics.map((diagnostic) => ({ ...diagnostic, artifact_refs: rewriteArtifactRefs(diagnostic.artifact_refs, rewrites) }))
    : diagnostics;
}

function rewriteArtifactRefs(refs, rewrites) {
  return Array.isArray(refs)
    ? refs.map((ref) => rewrites.has(ref?.path) ? { ...ref, path: rewrites.get(ref.path) } : ref)
    : refs;
}

function artifactRef(artifactId, filePath, kind) {
  return { schema: 'homeboy/artifact-ref/v1', artifact_id: artifactId, kind, path: filePath };
}

function artifactKey(value) {
  return String(value || 'fixture')
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '') || 'fixture';
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
    performance: input.performance || {},
    artifact_bytes: input.artifactBytes || input.artifact_bytes || {},
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

function buildWpCodeboxChildCommandFailure({ error, fixtures, batchNumber, batchSuffix, batchRecipeFile, outputFile, artifactsDir, wpCodeboxBin: bin, artifactRefs }) {
  const command = wpCodeboxRecipeRunCommand({ recipeFile: batchRecipeFile, artifactsDir, outputFile, wpCodeboxBin: bin });
  return {
    schema: 'homeboy/child-command-failure/v1',
    kind: 'child_command_failed',
    label: `WP Codebox recipe-run batch ${batchSuffix}`,
    batch: batchNumber,
    batch_id: `batch-${batchSuffix}`,
    fixture_ids: normalizeFixtureIds(fixtures),
    command: command.command,
    command_argv: command.argv,
    exit_status: exitStatus(error),
    error_code: error?.code,
    error_signal: error?.signal,
    stdout_tail: tailText(error?.stdout),
    stderr_tail: tailText(error?.stderr),
    recipe_file: batchRecipeFile,
    output_file: outputFile,
    artifacts_directory: artifactsDir,
    replay_command: wpCodeboxReplayCommand({ recipeFile: batchRecipeFile, artifactsDir, wpCodeboxBin: bin }),
    artifact_refs: artifactRefs,
    message: error?.message || 'WP Codebox recipe-run failed',
  };
}

function attributeChildCommandFailures(results, failures) {
  const failuresByFixture = new Map();
  for (const failure of failures || []) {
    for (const fixtureId of normalizeFixtureIdsFromFailure(failure)) {
      failuresByFixture.set(fixtureId, [...(failuresByFixture.get(fixtureId) || []), childCommandFailureDiagnostic(failure)]);
    }
  }
  if (failuresByFixture.size === 0) {
    return results;
  }
  return results.map((result) => ({
    ...result,
    diagnostics: [
      ...arrayValue(result.diagnostics),
      ...(failuresByFixture.get(result.fixture_id) || []),
    ],
  }));
}

function normalizeFixtureIdsFromFailure(failure) {
  return [...new Set(arrayValue(failure?.fixture_ids || failure?.fixtureIds).filter(Boolean).map((fixtureId) => String(fixtureId)))].sort();
}

function childCommandFailureDiagnostic(failure) {
  return {
    kind: 'recipe_step_failure',
    group_key: 'wp_codebox_child_command_failure',
    loss_class: 'runtime_execution_failed',
    loss_acceptance: 'unacceptable',
    batch_id: failure.batch_id || failure.batchId,
    batch: failure.batch,
    command: printableFailureCommand(failure),
    command_argv: failure.command_argv || failure.commandArgv || failure.command?.argv,
    exit_status: failure.exit_status ?? failure.exitStatus ?? failure.exit_code ?? failure.exitCode,
    error_code: failure.error_code || failure.errorCode,
    error_signal: failure.error_signal || failure.errorSignal,
    stdout_tail: failure.stdout_tail || failure.stdoutTail,
    stderr_tail: failure.stderr_tail || failure.stderrTail,
    recipe_file: failure.recipe_file || failure.recipeFile,
    output_file: failure.output_file || failure.outputFile,
    artifacts_directory: failure.artifacts_directory || failure.artifactsDirectory,
    replay_command: failure.replay_command || failure.replayCommand,
    artifact_refs: failure.artifact_refs || failure.artifactRefs || {},
    message: failure.message || 'WP Codebox child command failed.',
  };
}

function printableFailureCommand(failure) {
  if (typeof failure?.command === 'string') {
    return failure.command;
  }
  if (typeof failure?.command?.command === 'string') {
    return failure.command.command;
  }
  const argv = failure?.command_argv || failure?.commandArgv || failure?.command?.argv;
  return Array.isArray(argv) ? argv.map(shellArg).join(' ') : undefined;
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
}

function wpCodeboxRecipeRunCommand({ recipeFile, artifactsDir, outputFile, wpCodeboxBin: bin }) {
  const base = wpCodeboxCommand(bin || wpCodeboxBin());
  const argv = [
    base.command,
    ...(base.args || []),
    'recipe-run',
    '--recipe', recipeFile,
    '--artifacts', artifactsDir,
    '--output', outputFile,
    '--json',
  ];
  return {
    argv,
    command: argv.map(shellArg).join(' '),
  };
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
    visual_diff_classification: path.join(outputDirectory, 'visual-diff-classification.json'),
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
    // Lane selection from authored manifest taxonomy.
    fixtureClass: benchEnv.SSI_FIXTURE_MATRIX_CLASS || env.SSI_FIXTURE_MATRIX_CLASS,
    tag: benchEnv.SSI_FIXTURE_MATRIX_TAG || env.SSI_FIXTURE_MATRIX_TAG,
    capabilities: benchEnv.SSI_FIXTURE_MATRIX_CAPABILITY || env.SSI_FIXTURE_MATRIX_CAPABILITY || benchEnv.SSI_FIXTURE_MATRIX_CAPABILITIES || env.SSI_FIXTURE_MATRIX_CAPABILITIES,
    riskProfile: benchEnv.SSI_FIXTURE_MATRIX_RISK_PROFILE || env.SSI_FIXTURE_MATRIX_RISK_PROFILE,
    complexity: benchEnv.SSI_FIXTURE_MATRIX_COMPLEXITY || env.SSI_FIXTURE_MATRIX_COMPLEXITY,
    maxComplexity: benchEnv.SSI_FIXTURE_MATRIX_MAX_COMPLEXITY || env.SSI_FIXTURE_MATRIX_MAX_COMPLEXITY,
    artifactRoot: benchEnv.SSI_FIXTURE_MATRIX_ARTIFACT_ROOT || env.SSI_FIXTURE_MATRIX_ARTIFACT_ROOT,
    blocksEnginePhpTransformerPath: benchEnv.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH || env.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH,
    wordpressVersion: benchEnv.SSI_FIXTURE_MATRIX_WORDPRESS_VERSION || env.SSI_FIXTURE_MATRIX_WORDPRESS_VERSION,
    batchSize: benchEnv.SSI_FIXTURE_MATRIX_BATCH_SIZE || env.SSI_FIXTURE_MATRIX_BATCH_SIZE,
    concurrency: benchEnv.SSI_FIXTURE_MATRIX_CONCURRENCY || env.SSI_FIXTURE_MATRIX_CONCURRENCY,
    run: isTruthy(benchEnv.SSI_FIXTURE_MATRIX_RUN) || isTruthy(env.SSI_FIXTURE_MATRIX_RUN),
    wpCodeboxBin: benchEnv.SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN || env.SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN,
    editorValidation: !isFalsy(benchEnv.SSI_FIXTURE_MATRIX_EDITOR_VALIDATION ?? env.SSI_FIXTURE_MATRIX_EDITOR_VALIDATION),
    visualParity: !isFalsy(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY),
    visualParityGate: !isFalsy(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE ?? true),
    visualParityFullPage: optionalBoolean(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE),
    // Opt-in live-WP parity capture + comparison. Off by default; mirrors the
    // visual-parity-gate truthy env mapping. When on, the recipe appends the
    // capture-html step and the result collector runs the live-wp-parity comparator.
    liveWpParity: isTruthy(benchEnv.SSI_FIXTURE_MATRIX_LIVE_WP_PARITY) || isTruthy(env.SSI_FIXTURE_MATRIX_LIVE_WP_PARITY),
    pixelThreshold: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD,
    visualParityAlignment: optionalBoolean(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_ALIGNMENT ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_ALIGNMENT),
    visualParityMaxVerticalShift: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_VERTICAL_SHIFT || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_VERTICAL_SHIFT,
    visualParityMaxHorizontalShift: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_HORIZONTAL_SHIFT || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_HORIZONTAL_SHIFT,
    visualParityOffsetTolerance: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_OFFSET_TOLERANCE || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_OFFSET_TOLERANCE,
    visualParityPixelmatchThreshold: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXELMATCH_THRESHOLD || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXELMATCH_THRESHOLD,
    visualParityCandidateUrl: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_CANDIDATE_URL || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_CANDIDATE_URL,
    visualParitySourceBaseUrl: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_SOURCE_BASE_URL || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_SOURCE_BASE_URL,
    visualParityWaitFor: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_WAIT_FOR || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_WAIT_FOR,
    visualParityDurationMs: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_DURATION_MS || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_DURATION_MS,
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
// collector (gating). Capture and gating default on for honest dev-loop fidelity.
function visualParityRecipeInput(options) {
  return {
    visualParity: options.visualParity !== false,
    pixelThreshold: options.pixelThreshold,
    visualParityCandidateUrl: options.visualParityCandidateUrl,
    visualParitySourceBaseUrl: options.visualParitySourceBaseUrl,
    visualParityFullPage: options.visualParityFullPage,
    visualParityWaitFor: options.visualParityWaitFor,
    visualParityDurationMs: options.visualParityDurationMs,
  };
}

function visualParityGateInput(options) {
  return {
    threshold: options.pixelThreshold,
    gate: options.visualParityGate !== false,
    alignment: options.visualParityAlignment,
    maxVerticalShift: options.visualParityMaxVerticalShift,
    maxHorizontalShift: options.visualParityMaxHorizontalShift,
    offsetTolerance: options.visualParityOffsetTolerance,
    pixelmatchThreshold: options.visualParityPixelmatchThreshold,
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

function optionalBoolean(value) {
  if (value === undefined || value === null || value === '') {
    return undefined;
  }
  return !isFalsy(value);
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
  process.stdout.write(`Usage: static-site-fixture-matrix [fixture-root] [options]

Options:
  --fixture-root <path>              Static-site fixture root. Defaults to this package's fixtures directory.
  --output-directory <path>          Artifact output directory.
  --static-site-importer-path <path> Static Site Importer checkout/plugin directory.
  --static-site-importer-slug <slug> Plugin slug. Defaults to static-site-importer.
  --static-site-importer-plugin <p>  Plugin activation file. Defaults to static-site-importer/static-site-importer.php.
  --artifact-root <path>             Generated artifact root to normalize into fixtures.
  --blocks-engine-php-transformer-path <path>
                                     Blocks Engine repo root or php-transformer package path for Composer.
  --entrypoint <file>                Fixture entrypoint. Defaults to index.html.
  --max-depth <n>                    Fixture discovery depth. Defaults to 2.
  --class <fixture_class>            Filter to one authored fixture_class lane.
  --tag <tag>                        Filter to fixtures carrying an authored tag.
  --capability <capability>          Filter to fixtures carrying an authored capability.
  --risk-profile <profile>           Filter to one authored risk_profile.
  --complexity <n>                   Filter to fixtures with authored complexity exactly n.
  --max-complexity <n>               Filter to fixtures with authored complexity <= n.
  --wordpress-version <version>      WP Codebox WordPress version. Defaults to latest.
  --batch-size <n>                   Fixtures per WP Codebox run when --run is used. Defaults to 10.
  --concurrency <n>                  Batches (WP Codebox sandboxes) to run in parallel. Defaults to ${DEFAULT_BATCH_CONCURRENCY}, hard-capped at ${MAX_BATCH_CONCURRENCY}.
  --no-editor-validation            Skip browser editor block validation.
  --no-visual-parity                Skip wordpress.visual-compare recipe steps. Same as SSI_FIXTURE_MATRIX_VISUAL_PARITY=0.
  --run                             Execute WP Codebox recipes. Omit locally to only materialize artifacts.
`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  await main();
}
