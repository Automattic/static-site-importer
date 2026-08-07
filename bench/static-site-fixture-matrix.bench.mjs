#!/usr/bin/env node

/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

/**
 * Internal dependencies
 */
import { DEFAULT_RECIPE_INACTIVITY_TIMEOUT_MS, runWpCodeboxRecipe, wpCodeboxCommand, wpCodeboxBin } from '../tools/wp-codebox/recipe.mjs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import {
  buildFixtureMatrixRecipe,
  classifyVisualDiffRegions,
  collectFixtureMatrixRunResults,
  createFixtureMatrix,
  inspectFixtureDirectories,
  normalizeFixtureMatrixResult,
  writeFixtureMatrixArtifacts,
  writeFixtureMatrixResultArtifacts,
  normalizeAnimatedMediaPolicy,
  normalizeVisualAttributionOptions,
  FIXTURE_MATRIX_RUN_FIELDS,
  fixtureMatrixBenchOptions,
  fixtureMatrixGateConfig,
  fixtureMatrixRecipeInput,
  fixtureMatrixRunConfigFromEnv,
  normalizeFixtureMatrixDependencyOverlays,
  normalizeFixtureMatrixRunConfig,
} from '../lib/fixture-matrix.mjs';

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
const VISUAL_ATTRIBUTION_TOP_FINDINGS_LIMIT = 5;
export const FIXTURE_MATRIX_PROGRESS_SCHEMA = 'homeboy/runner-progress/v1';
export const FIXTURE_MATRIX_PROGRESS_PREFIX = 'HOMEBOY_RUNNER_PROGRESS ';
const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const require = createRequire(import.meta.url);

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
      fixture_coverage: { path: path.join(summary.output_directory, 'fixture-coverage.json') },
      visual_parity_evidence_report: { path: path.join(summary.output_directory, 'visual-parity-evidence-report.json') },
      visual_parity_evidence_report_markdown: { path: path.join(summary.output_directory, 'visual-parity-evidence-report.md') },
      visual_diff_classification: { path: path.join(summary.output_directory, 'visual-diff-classification.json') },
      surface_lineage_bundles: { path: path.join(summary.output_directory, 'surface-lineage-bundles.json') },
      ...(summary.surface_lineage_artifacts || {}),
      gutenberg_incompatibility_registry: { path: path.join(summary.output_directory, 'gutenberg-incompatibility-registry.json') },
      gutenberg_incompatibility_registry_report: { path: path.join(summary.output_directory, 'gutenberg-incompatibility-registry.md') },
      ...(summary.visual_parity_artifacts || {}),
      ...(summary.editor_canvas_artifacts || {}),
    },
    metadata: {
      matrix_id: summary.matrix_id,
      fixture_root: summary.fixture_root,
      output_directory: summary.output_directory,
      result_summary: summary.result_summary,
      execution_requested: summary.metadata.execution_requested,
      execution_status: summary.metadata.execution_status,
      execution_evidence: summary.metadata.execution_evidence,
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
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
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
  const runConfig = normalizeFixtureMatrixRunConfig(Object.fromEntries(Object.keys(FIXTURE_MATRIX_RUN_FIELDS).map((key) => [key, options[key]])));
  options = { ...options, ...fixtureMatrixBenchOptions(runConfig) };
  // Fail before materializing artifacts or starting a WP Codebox recipe.
  const animatedMedia = normalizeAnimatedMediaPolicy(options.animatedMedia);
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
  const fixtureInspection = inspectFixtureDirectories(fixtureRoot, { maxDepth: options.maxDepth });
  const matrixInput = {
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
    fixture_ids: options.fixtureIds,
    fixture_corpus: options.fixtureCorpus,
  };
  const matrix = fixtureInspection.exclusions[0]?.reason === 'root_symlink'
    ? createFixtureMatrix({ ...matrixInput, fixtures: [] })
    : createFixtureMatrix(matrixInput);
  if (options.run && (matrix.count === 0 || matrix.fixture_coverage?.gate?.status === 'failed')) {
    throw new Error(`Fixture matrix execution requires a complete eligible fixture inventory under ${fixtureRoot}. Inspect the fixture root, entrypoint, metadata, duplicate IDs, and lane filters before retrying.`);
  }
  validateHydratedComposerDependencies(packageRoot);
  const progress = createFixtureMatrixProgress(matrix, options);
  progress.emit('matrix', 'started');
  const artifactWriteStartedAt = nowMs();
  const written = writeFixtureMatrixArtifacts({
    outputDirectory,
    matrix,
    ...visualParityRecipeInput(options),
    ...liveWpParityRecipeInput(options),
    ...runtimePresentationEvidenceRecipeInput(options),
  });
  performance.artifact_writing_ms = elapsedMs(artifactWriteStartedAt);
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    runId: matrix.id,
    attemptId: `plan-${matrix.id}`,
    artifactsDirectory: outputDirectory,
    playgroundArtifactsDirectory: options.playgroundArtifactsDirectory || '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    wordpressVersion: options.wordpressVersion,
    staticSiteImporterPath,
    staticSiteImporterPlugin: options.staticSiteImporterPlugin,
    staticSiteImporterSlug: options.staticSiteImporterSlug,
    dependencyOverrides,
    svgFontEvidence: true,
    ...fixtureMatrixRecipeInput(runConfig),
    ...visualParityRecipeInput(options),
    ...liveWpParityRecipeInput(options),
    ...runtimePresentationEvidenceRecipeInput(options),
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
  let editorCanvasArtifacts = {};
  let surfaceLineageArtifacts = collectSurfaceLineageArtifacts(collectedResult);
  if (options.run) {
    const batchSize = options.batchSize;
    const concurrency = options.concurrency;
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
      dependencyOverrides,
      progress,
    }));
    performance.batch_execution_ms = elapsedMs(batchExecutionStartedAt);

    const batchRuns = [];
    const batchResults = [];
    const childCommandFailures = [];
    for (const outcome of batchOutcomes) {
      batchRuns.push(outcome.batchRun);
      batchResults.push(outcome.batchResult);
      visualParityArtifacts = { ...visualParityArtifacts, ...(outcome.visualParityArtifacts || {}) };
      editorCanvasArtifacts = { ...editorCanvasArtifacts, ...(outcome.editorCanvasArtifacts || {}) };
      for (const failure of outcome.childCommandFailures || []) {
        childCommandFailures.push(failure);
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
      editorQuality: fixtureMatrixGateConfig(runConfig).editorQuality,
      requireSolvedCandidate: options.requireSolvedCandidate,
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
    surfaceLineageArtifacts = collectSurfaceLineageArtifacts(collectedResult);
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
    fixture_coverage: fileBytes(path.join(outputDirectory, 'fixture-coverage.json')),
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
    artifact_refs: [...written.artifact_refs, ...Object.entries(surfaceLineageArtifacts).map(([artifact_id, artifact]) => ({ artifact_id, kind: 'surface-lineage', path: artifact.path }))],
    metadata: {
      execution_requested: Boolean(options.run),
      execution_status: collectedResult.summary.execution_status,
      execution_evidence: executionEvidenceMetadata(options.run),
      performance,
      artifact_bytes: artifactBytes,
      source_staging: written.metadata?.source_staging,
      surface_coverage: recipe.metadata?.surface_coverage,
      runtime_cost_warnings: recipe.metadata?.runtime_cost_warnings || [],
      animated_media: animatedMedia || 'allow',
      visual_attribution: normalizeVisualAttributionOptions(options),
    },
    ...(runtime?.childCommandFailures?.length ? { child_command_failures: runtime.childCommandFailures } : {}),
    result_file: path.join(outputDirectory, 'static-site-fixture-matrix-result.json'),
    visual_parity_artifacts: visualParityArtifacts,
    editor_canvas_artifacts: editorCanvasArtifacts,
    surface_lineage_artifacts: surfaceLineageArtifacts,
    result_summary: collectedResult.summary,
    fixture_coverage: collectedResult.summary.fixture_coverage || matrix.fixture_coverage || null,
    runtime: runtime ? runtimeSummary(runtime, runtimeError) : null,
  };
  const cliRunBaseTotal = summary.metadata.artifact_bytes.total;
  for (let index = 0; index < 3; index += 1) {
    summary.metadata.artifact_bytes.cli_run = Buffer.byteLength(jsonArtifactText(summary));
    summary.metadata.artifact_bytes.total = cliRunBaseTotal + summary.metadata.artifact_bytes.cli_run;
  }
  writeJsonArtifact(path.join(outputDirectory, 'cli-run.json'), summary);
  progress.emit('matrix', runtimeError ? 'failed' : 'completed');
  return { summary, runtimeError, runtime };
}

function collectSurfaceLineageArtifacts(result) {
  return Object.fromEntries(arrayValue(result?.fixtures).flatMap((fixture) => arrayValue(fixture.artifact_refs)
    .filter((ref) => ref?.kind === 'surface-lineage' && ref.artifact_id && ref.path)
    .map((ref) => [ref.artifact_id, { path: ref.path }])));
}

function executionEvidenceMetadata(executionRequested) {
  if (executionRequested) {
    return {
      status: 'requested',
      blind_spots: [],
    };
  }

  return {
    status: 'plan_only',
    gate_reason: 'execution_not_requested',
    blind_spots: [
      'transformer_execution',
      'wordpress_materialization',
      'editor_validation',
      'visual_parity',
    ],
  };
}

// Provision and reconcile a single batch in its own WP Codebox sandbox. Pure with
// respect to other batches (it only writes batch-scoped recipe/output files and
// per-fixture artifact subdirectories, all keyed by the unique batch suffix), so
// many of these can run concurrently without colliding. Returns a stable outcome
// the caller folds back together in batch order.
export async function runFixtureMatrixBatch({ fixtures, batchIndex, matrix, outputDirectory, staticSiteImporterPath, options, dependencyOverrides = prepareDependencyOverrides(options), recovery = false, progress }) {
  const batchNumber = batchIndex + 1;
  const batchSuffix = recovery
    ? `${String(batchNumber).padStart(3, '0')}-recovery-${fixtures[0].id}`
    : String(batchNumber).padStart(3, '0');
  const batchMatrix = createFixtureMatrix({
    id: `${matrix.id}-batch-${batchSuffix}`,
    fixture_root: matrix.fixture_root,
    entrypoint: matrix.entrypoint,
    fixtures,
  });
  // Discovery is deliberately a separate, short-lived Codebox runtime. It only
  // asks SSI for its registry-derived plan; package resolution happens on the
  // host while assembling the following fresh import runtime.
  const dependencyOverlays = normalizeFixtureMatrixDependencyOverlays({
    staticSiteImporterPath,
    staticSiteImporterPlugin: options.staticSiteImporterPlugin,
    staticSiteImporterSlug: options.staticSiteImporterSlug,
    dependencyOverrides,
  });
  const dependencyPlan = await discoverFixtureDependencyPlan({ fixtures, outputDirectory, staticSiteImporterPath, options, dependencyOverlays, batchSuffix });
  const resolvedDependencyPlan = await resolveHostDependencyPlan(dependencyPlan, path.join(outputDirectory, 'dependency-cache'));
  const batchRecipe = buildFixtureMatrixRecipe({
    matrix: batchMatrix,
    runId: batchMatrix.id,
    attemptId: batchSuffix,
    artifactsDirectory: outputDirectory,
    playgroundArtifactsDirectory: options.playgroundArtifactsDirectory || '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    wordpressVersion: options.wordpressVersion,
    staticSiteImporterPath,
    staticSiteImporterPlugin: options.staticSiteImporterPlugin,
    staticSiteImporterSlug: options.staticSiteImporterSlug,
    dependencyPlan: resolvedDependencyPlan,
    dependencyOverrides,
    dependencyOverlays,
    svgFontEvidence: true,
    ...fixtureMatrixRecipeInput(normalizeFixtureMatrixRunConfig(Object.fromEntries(Object.keys(FIXTURE_MATRIX_RUN_FIELDS).map((key) => [key, options[key]])))),
    ...visualParityRecipeInput(options),
    ...liveWpParityRecipeInput(options),
    ...runtimePresentationEvidenceRecipeInput(options),
  });
  const batchRecipeFile = path.join(outputDirectory, `wp-codebox-static-site-fixture-matrix-batch-${batchSuffix}.json`);
  const outputFile = path.join(outputDirectory, `wp-codebox-output-batch-${batchSuffix}.json`);
  const codeboxArtifactsDirectory = batchCodeboxArtifactsDirectory(outputDirectory, batchSuffix);
  const artifactRefs = batchArtifactRefs({ outputDirectory, batchSuffix, batchRecipeFile, outputFile, codeboxArtifactsDirectory });
  writeJsonArtifact(batchRecipeFile, batchRecipe);
  progress?.emit(recovery ? 'recovery' : 'batch', 'started', { fixture_id: fixtures[0]?.id || '', batch: batchNumber, recovery });
  progress?.emit('fixture', 'started', { fixture_id: fixtures[0]?.id || '', batch: batchNumber, recovery });

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
      cwd: outputDirectory,
      wpCodeboxBin: options.wpCodeboxBin,
      inactivityTimeoutMs: batchInactivityTimeoutMs(options),
      onInactivity: ({ timeout_ms }) => {
        progress?.emit('batch', 'timeout', { fixture_id: fixtures[0]?.id || '', batch: batchNumber, recovery, timeout_ms });
        if (recovery) progress?.emit('fixture', 'timeout', { fixture_id: fixtures[0]?.id || '', batch: batchNumber, recovery, timeout_ms });
      },
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
      batchId: `batch-${String(batchNumber).padStart(3, '0')}`,
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
  materializeMaterializationSidecars({ fixtures, outputDirectory, codeboxArtifactsDirectory, attemptId: batchSuffix });
  const batchResult = collectFixtureMatrixRunResults({
    matrix: batchMatrix,
    outputDirectory,
    outputFile,
    codeboxOutput: batchRuntime?.json,
    codeboxError: batchError,
    sidecarAttemptId: batchSuffix,
    visualParity: fixtureMatrixGateConfig(normalizeFixtureMatrixRunConfig(Object.fromEntries(Object.keys(FIXTURE_MATRIX_RUN_FIELDS).map((key) => [key, options[key]])))).visualParity,
    liveWpParity: liveWpParityCollectorInput(options),
    dependencyOverrides,
    dependencyOverlays: batchRecipe.inputs.dependency_overlays || [],
  });
  const visualCompare = materializeVisualCompareArtifacts({
    result: batchResult,
    codeboxArtifactsDirectory,
    outputDirectory,
    visualAttributionNormalizer: options.visualAttributionNormalizer,
    visualAttributionLoader: options.visualAttributionLoader,
    homeboyExtensionPath: options.homeboyExtensionPath,
  });
  const editorCanvas = materializeEditorCanvasArtifacts({
    result: visualCompare.result,
    codeboxArtifactsDirectory,
    outputDirectory,
  });
  if (!batchError || recovery) {
    for (const fixture of editorCanvas.result.fixtures || []) {
      const fixtureId = fixture.fixture_id || fixture.fixtureId || '';
      progress?.emit('fixture', fixture.status === 'failed' ? 'failed' : 'completed', { fixture_id: fixtureId, batch: batchNumber, recovery });
    }
  }
  progress?.emit(recovery ? 'recovery' : 'batch', batchError ? (batchError.inactivityTimedOut ? 'timeout' : 'failed') : 'completed', { fixture_id: fixtures[0]?.id || '', batch: batchNumber, recovery });

  if (!batchError || recovery) {
    return {
      batchRun,
      batchResult: editorCanvas.result,
      visualParityArtifacts: visualCompare.artifacts,
      editorCanvasArtifacts: editorCanvas.artifacts,
      error: batchError,
      childCommandFailures: childCommandFailure ? [childCommandFailure] : [],
    };
  }

  // A recipe-level failure leaves the sandbox's state untrustworthy. Re-run each
  // fixture in a fresh sandbox so one stalled step cannot classify its batch peers.
  progress?.emit('recovery', 'started', { fixture_id: fixtures[0]?.id || '', batch: batchNumber, recovery: true });
  const recoveryOutcomes = await mapWithConcurrency(fixtures, boundedConcurrency(options.recoveryConcurrency, DEFAULT_BATCH_CONCURRENCY, MAX_BATCH_CONCURRENCY), (fixture) => runFixtureMatrixBatch({
      fixtures: [fixture],
      batchIndex,
      matrix,
      outputDirectory,
      staticSiteImporterPath,
      options,
      dependencyOverrides,
      recovery: true,
      progress,
    }));

  const recoveryErrors = recoveryOutcomes.map((outcome) => outcome.error).filter(Boolean);
  return {
    batchRun: {
      ...batchRun,
      recovery_attempts: recoveryOutcomes.map((outcome) => outcome.batchRun),
    },
    batchResult: {
      fixtures: recoveryOutcomes.flatMap((outcome) => outcome.batchResult.fixtures || []),
    },
    visualParityArtifacts: Object.assign({}, ...recoveryOutcomes.map((outcome) => outcome.visualParityArtifacts || {})),
    editorCanvasArtifacts: Object.assign({}, ...recoveryOutcomes.map((outcome) => outcome.editorCanvasArtifacts || {})),
    error: recoveryErrors[0] || null,
    childCommandFailures: recoveryOutcomes.flatMap((outcome) => outcome.childCommandFailures || []),
  };
}

export async function resolveHostDependencyPlan(plan, cacheDirectory, fetcher = fetch) {
  const entries = [];
  for (const entry of plan.entries) {
    if (entry.source_kind !== 'wordpress.org-plugin' || !/^[a-z0-9][a-z0-9-_]*$/i.test(entry.slug || '')) throw new Error('Host dependency resolver received an invalid plugin declaration.');
    const infoUrl = new URL('https://api.wordpress.org/plugins/info/1.2/');
    infoUrl.searchParams.set('action', 'plugin_information');
    infoUrl.searchParams.set('request[slug]', entry.slug);
    const infoResponse = await fetcher(infoUrl, { redirect: 'error' });
    if (!infoResponse.ok || !/^application\/json\b/i.test(infoResponse.headers.get('content-type') || '')) throw new Error(`WordPress.org plugin info failed for ${entry.slug}.`);
    const info = await infoResponse.json();
    const version = String(info?.version || '');
    const source = String(info?.download_link || '');
    const url = new URL(source);
    if (!/^https:$/.test(url.protocol) || url.hostname !== 'downloads.wordpress.org' || !version || !/^\d[0-9A-Za-z._+-]*$/.test(version)) throw new Error(`WordPress.org plugin info returned an invalid immutable package for ${entry.slug}.`);
    const cacheKey = `${entry.slug}-${version}`;
    const cachePath = path.join(cacheDirectory, cacheKey, 'package.zip');
    fs.mkdirSync(path.dirname(cachePath), { recursive: true });
    let bytes;
    if (fs.existsSync(cachePath)) bytes = fs.readFileSync(cachePath);
    else {
      const download = await fetcher(url, { redirect: 'error' });
      const contentLength = Number(download.headers.get('content-length') || 0);
      if (!download.ok || !/^application\/(zip|octet-stream)\b/i.test(download.headers.get('content-type') || '') || (contentLength && contentLength > 100 * 1024 * 1024)) throw new Error(`WordPress.org package download failed policy validation for ${entry.slug}.`);
      bytes = Buffer.from(await download.arrayBuffer());
      if (!bytes.length || bytes.length > 100 * 1024 * 1024) throw new Error(`WordPress.org package exceeds host size policy for ${entry.slug}.`);
      fs.writeFileSync(cachePath, bytes);
    }
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    entries.push({ ...entry, host_resolution: { schema: 'static-site-importer/host-package-resolution/v1', slug: entry.slug, version, source_url: url.toString(), archive_sha256: sha256, archive_path: cachePath } });
  }
  return { ...plan, entries };
}

async function discoverFixtureDependencyPlan({ fixtures, outputDirectory, staticSiteImporterPath, options, dependencyOverlays = [], batchSuffix }) {
  const plans = [];
  for (const fixture of fixtures) {
    const fixtureDirectory = path.join(outputDirectory, fixture.id);
    const runtimeDirectory = `/wordpress/wp-content/uploads/static-site-importer-fixture-matrix/${fixture.id}`;
    const planName = `dependency-plan-${batchSuffix}.json`;
    const artifactsDir = path.join(outputDirectory, 'dependency-discovery', `${batchSuffix}-${fixture.id}`);
    const recipeFile = path.join(artifactsDir, 'recipe.json');
    const outputFile = path.join(artifactsDir, 'output.json');
    fs.mkdirSync(artifactsDir, { recursive: true });
    const recipe = {
      schema: 'wp-codebox/workspace-recipe/v1',
      runtime: { wp: options.wordpressVersion || 'latest', blueprint: {} },
      inputs: {
        stagedFiles: [{ source: path.join(fixtureDirectory, 'artifact.json'), target: path.join(runtimeDirectory, 'artifact.json') }],
        extra_plugins: [{ source: staticSiteImporterPath, slug: options.staticSiteImporterSlug || 'static-site-importer', activate: true }],
        ...(dependencyOverlays.length ? { dependency_overlays: dependencyOverlays } : {}),
      },
      workflow: { steps: [
        { command: 'wordpress.wp-cli', args: [`command=plugin activate ${(options.staticSiteImporterPlugin || 'static-site-importer/static-site-importer.php')}`] },
        { command: 'wordpress.wp-cli', args: [`command=static-site-importer plan-artifact-dependencies --artifact=${path.join(runtimeDirectory, 'artifact.json')} --slug=${fixture.id} --name=${JSON.stringify(fixture.label)} --output=${path.join(runtimeDirectory, planName)}`] },
      ] },
      artifacts: { directory: artifactsDir, typed: [{ name: 'dependency-plan', type: 'static-site-importer/runtime-dependency-plan', path: path.join(runtimeDirectory, planName), required: true, parseJson: true, contentType: 'application/json', payloadSchema: 'static-site-importer/runtime-dependency-plan/v1' }] },
    };
    writeJsonArtifact(recipeFile, recipe);
    const discovery = await runWpCodeboxRecipe({ recipeFile, artifactsDir, outputFile, wpCodeboxBin: options.wpCodeboxBin, inactivityTimeoutMs: batchInactivityTimeoutMs(options) });
    const plan = findDependencyPlan(artifactsDir);
    if (!plan) throw new Error(`Dependency discovery did not persist a valid plan for fixture ${fixture.id}.`);
    // Discovery only mounts SSI and its declared transformer overlays. Provider
    // packages are resolved and activated by the final recipe's extra_plugins
    // setup, before its workflow begins; asking this runtime for a provider
    // receipt would incorrectly require Jetpack/Woo during planning.
    plans.push(plan);
  }
  const entries = new Map();
  for (let index = 0; index < plans.length; index += 1) {
    const fixtureId = fixtures[index].id;
    for (const entry of plans[index].entries) {
      const key = `${entry.source_kind}:${entry.slug}:${entry.plugin_entrypoint}`;
      const existing = entries.get(key);
      entries.set(key, {
        ...(existing || entry),
        fixture_ids: [...new Set([...(existing?.fixture_ids || []), fixtureId])].sort(),
      });
    }
  }
  return { schema: 'static-site-importer/runtime-dependency-plan/v1', artifact_sha256: plans.map((plan) => plan.artifact_sha256).sort().join(','), entries: [...entries.values()] };
}

function findDependencyPlan(directory) {
  const visit = (current) => {
    for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
      const child = path.join(current, entry.name);
      if (entry.isDirectory()) {
        const found = visit(child);
        if (found) return found;
      } else if (entry.isFile() && entry.name.endsWith('.json')) {
        const parsed = parseJsonText(fs.readFileSync(child, 'utf8'));
        if (parsed?.schema === 'static-site-importer/runtime-dependency-plan/v1' && Array.isArray(parsed.entries)) return parsed;
      }
    }
    return null;
  };
  return visit(directory);
}

function createFixtureMatrixProgress(matrix, options) {
  const complete = new Set();
  const write = typeof options.progress === 'function'
    ? options.progress
    : (event) => process.stdout.write(`${FIXTURE_MATRIX_PROGRESS_PREFIX}${JSON.stringify(event)}\n`);
  return {
    emit(phase, status, details = {}) {
      const fixtureId = details.fixture_id || '';
      if (phase === 'fixture' && fixtureId && ['completed', 'failed', 'timeout'].includes(status)) complete.add(fixtureId);
      write({
        schema: FIXTURE_MATRIX_PROGRESS_SCHEMA,
        phase,
        current_item: fixtureId || matrix.id,
        completed: complete.size,
        total: matrix.count,
        metadata: {
          lifecycle_status: status,
          ...(details.batch ? { batch: details.batch } : {}),
          ...(details.recovery ? { recovery: true } : {}),
          ...(details.timeout_ms ? { timeout_ms: details.timeout_ms } : {}),
        },
      });
    },
  };
}

function batchInactivityTimeoutMs(options) {
  return positiveInteger(options.batchInactivityTimeoutMs || options.batch_inactivity_timeout_ms, DEFAULT_RECIPE_INACTIVITY_TIMEOUT_MS);
}

// Declared typed artifacts leave the guest filesystem through WP Codebox's
// --artifacts tree. Materialize only an identity-matching export into the
// fixture directory consumed by run intake.
export function materializeMaterializationSidecars({ fixtures = [], outputDirectory, codeboxArtifactsDirectory, attemptId }) {
  const root = path.resolve(codeboxArtifactsDirectory || '');
  const output = path.resolve(outputDirectory || '');
  if (!root || !output || !fs.existsSync(root)) return [];
  const exported = new Map();
  for (const filePath of runtimeArtifactFiles(root)) {
    if (filePath.endsWith('.json') && fs.statSync(filePath).size <= 32 * 1024) {
      try {
        const sidecar = JSON.parse(fs.readFileSync(filePath, 'utf8'));
        if (sidecar?.schema === 'static-site-importer/materialization-runtime-sidecar/v1' && sidecar.attempt_id === attemptId && typeof sidecar.fixture_id === 'string') {
          exported.set(sidecar.fixture_id, filePath);
        }
      } catch {
        // Declared artifact output can include unrelated or partial JSON files.
      }
    }
  }
  for (const fixture of fixtures) {
    const source = exported.get(fixture.id);
    if (!source) continue;
    const target = path.join(output, fixture.id, `materialization-receipt--${attemptId}.json`);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(source, target);
  }
  return [...exported.entries()].map(([fixture_id, source]) => ({ fixture_id, source }));
}

function runtimeArtifactFiles(directory, files = []) {
  for (const entry of safeReadDirectory(directory)) {
    const filePath = path.join(directory, entry.name);
    if (entry.isDirectory()) runtimeArtifactFiles(filePath, files);
    if (entry.isFile()) files.push(filePath);
  }
  return files;
}

export function materializeEditorCanvasArtifacts(input = {}) {
  const result = input.result || {};
  const outputDirectory = path.resolve(input.outputDirectory || input.output_directory || '');
  const codeboxArtifactsDirectory = path.resolve(input.codeboxArtifactsDirectory || input.codebox_artifacts_directory || '');
  const artifacts = {};
  return {
    result: {
      ...result,
      fixtures: arrayValue(result.fixtures).map((fixture) => materializeFixtureEditorCanvasArtifacts({ fixture, outputDirectory, codeboxArtifactsDirectory, artifacts })),
    },
    artifacts,
  };
}

function materializeFixtureEditorCanvasArtifacts({ fixture, outputDirectory, codeboxArtifactsDirectory, artifacts }) {
  const fixtureId = fixture.fixture_id || fixture.fixtureId || '';
  if (!fixtureId) {
    return fixture;
  }
  const rewrites = new Map();
  for (const ref of arrayValue(fixture.artifact_refs)) {
    if (ref?.kind !== 'editor-canvas' || !ref.path) {
      continue;
    }
    const sourcePath = resolveCodeboxArtifactPath(ref.path, codeboxArtifactsDirectory);
    if (!sourcePath || !fs.existsSync(sourcePath)) {
      continue;
    }
    const persistedPath = path.join(outputDirectory, 'editor-canvas', fixtureId, path.basename(ref.path));
    fs.mkdirSync(path.dirname(persistedPath), { recursive: true });
    fs.copyFileSync(sourcePath, persistedPath);
    const artifactId = String(ref.artifact_id || ref.id || path.basename(ref.path, path.extname(ref.path))).replace(/[^a-z0-9_.-]+/gi, '-');
    const exportedArtifactId = `editor_canvas_${fixtureId}_${artifactId}`;
    rewrites.set(ref.path, { path: persistedPath, artifact_id: exportedArtifactId });
    artifacts[exportedArtifactId] = { path: persistedPath };
  }
  if (rewrites.size === 0) {
    return fixture;
  }
  return {
    ...fixture,
    artifact_refs: rewriteArtifactRefs(fixture.artifact_refs, rewrites),
    editor_canvas: rewriteEditorEvidencePaths(fixture.editor_canvas, rewrites),
    editor_open: rewriteEditorEvidencePaths(fixture.editor_open, rewrites),
    surfaces: arrayValue(fixture.surfaces).map((surface) => ({
      ...surface,
      artifact_refs: rewriteArtifactRefs(surface.artifact_refs, rewrites),
      editor_canvas: rewriteEditorEvidencePaths(surface.editor_canvas, rewrites),
      editor_open: rewriteEditorEvidencePaths(surface.editor_open, rewrites),
    })),
  };
}

function rewriteEditorEvidencePaths(value, rewrites) {
  if (!value || typeof value !== 'object') {
    return value;
  }
  const files = value.files && typeof value.files === 'object'
    ? Object.fromEntries(Object.entries(value.files).map(([key, filePath]) => [key, rewritePath(filePath, rewrites)]))
    : undefined;
  return { ...value, ...(files ? { files } : {}), ...(value.screenshot ? { screenshot: rewritePath(value.screenshot, rewrites) } : {}) };
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
    visualAttributionNormalizer: input.visualAttributionNormalizer,
    visualAttributionLoader: input.visualAttributionLoader,
    homeboyExtensionPath: input.homeboyExtensionPath,
  }));
  return {
    result: { ...result, fixtures: updatedFixtures },
    artifacts,
  };
}

function materializeFixtureVisualCompareArtifacts({ fixture, outputDirectory, codeboxArtifactsDirectory, artifacts, visualAttributionNormalizer, visualAttributionLoader, homeboyExtensionPath }) {
  const visualParityArtifacts = fixture.visual_parity_artifacts || fixture.visualParityArtifacts;
  if (!visualParityArtifacts) {
    return fixture;
  }
  const slots = visualParityArtifacts?.artifacts || {};
  const fixtureId = fixture.fixture_id || fixture.fixtureId || '';
  if (!fixtureId) {
    return fixture;
  }
  if (!slots || typeof slots !== 'object') {
    const secondary = arrayValue(fixture.visual_parity_comparisons).map((comparison) => materializeSecondaryVisualCompareArtifacts({ comparison, fixtureId, outputDirectory, codeboxArtifactsDirectory, artifacts }));
    const rewrites = new Map(secondary.flatMap((entry) => [...entry.rewrites]));
    return rewriteVisualEvidencePaths({ ...fixture, visual_parity_comparisons: secondary.map((entry) => entry.comparison) }, rewrites);
  }

  const rewrites = new Map();
  const unavailableArtifactPaths = new Set();
  const updatedSlots = { ...slots };
  for (const slot of [
    ['source_screenshot', 'source', ['source_screenshot']],
    ['imported_screenshot', 'candidate', ['imported_screenshot', 'candidate_screenshot']],
    ['diff_screenshot', 'diff', ['diff_screenshot']],
    ['visual_diff', 'visual-diff.json', ['visual_diff']],
    ['visual_explanation', 'visual-explanation.json', ['visual_explanation']],
    ['source_dom_snapshot', 'source-dom-snapshot.json', ['source_dom_snapshot']],
    ['candidate_dom_snapshot', 'candidate-dom-snapshot.json', ['candidate_dom_snapshot']],
  ]) {
    const [slotName, fileStem, artifactIds] = slot;
    const refPath = slots[slotName]?.ref?.path || visualDiagnosticRefPath(fixture.diagnostics, artifactIds);
    const sourcePath = resolveCodeboxArtifactPath(refPath, codeboxArtifactsDirectory);
    if (!sourcePath || !fs.existsSync(sourcePath)) {
      if (refPath) {
        unavailableArtifactPaths.add(refPath);
        updatedSlots[slotName] = {
          ...slots[slotName],
          status: 'pending',
          kind: slotName,
          capture_state: 'artifact_not_persisted',
          reason: 'artifact_not_persisted',
          ref: undefined,
        };
      }
      continue;
    }
    const persistedPath = path.join(outputDirectory, 'visual-compare', fixtureId, fileStem.includes('.') ? fileStem : `${fileStem}.png`);
    fs.mkdirSync(path.dirname(persistedPath), { recursive: true });
    fs.copyFileSync(sourcePath, persistedPath);
    const exportedArtifactId = `visual_compare_${artifactKey(fixtureId)}_${fileStem}`;
    rewrites.set(refPath, { path: persistedPath, artifact_id: exportedArtifactId });
    updatedSlots[slotName] = {
      ...slots[slotName],
      status: 'captured',
      kind: slotName,
      ref: artifactRef(exportedArtifactId, persistedPath, 'visual-parity'),
    };
    artifacts[exportedArtifactId] = { path: persistedPath };
  }

  const comparisonRefPaths = new Set(arrayValue(fixture.visual_parity_comparisons).flatMap((comparison) => Object.values(
    comparison?.visual_parity_artifacts?.artifacts || comparison?.visualParityArtifacts?.artifacts || {},
  ).map((slot) => slot?.ref?.path).filter(Boolean)));
  for (const diagnostic of arrayValue(fixture.diagnostics)) {
    for (const ref of arrayValue(diagnostic?.artifact_refs)) {
      if (ref?.kind !== 'visual-parity' || !ref.path || rewrites.has(ref.path) || comparisonRefPaths.has(ref.path)) {
        continue;
      }
      const sourcePath = resolveCodeboxArtifactPath(ref.path, codeboxArtifactsDirectory);
      if (!sourcePath || !fs.existsSync(sourcePath)) {
        continue;
      }
      const comparisonName = path.basename(path.dirname(ref.path));
      const fileName = path.basename(ref.path);
      const persistedPath = path.join(outputDirectory, 'visual-compare', comparisonName, fileName);
      fs.mkdirSync(path.dirname(persistedPath), { recursive: true });
      fs.copyFileSync(sourcePath, persistedPath);
      const exportedArtifactId = `visual_compare_${artifactKey(comparisonName)}_${artifactKey(fileName)}`;
      rewrites.set(ref.path, { path: persistedPath, artifact_id: exportedArtifactId });
      artifacts[exportedArtifactId] = { path: persistedPath };
    }
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
  const visualAttribution = materializeVisualAttribution({
    fixture,
    fixtureId,
    outputDirectory,
    slots: updatedSlots,
    normalizer: visualAttributionNormalizer,
    loader: visualAttributionLoader,
    homeboyExtensionPath,
  });
  if (visualAttribution) {
    updatedSlots.visual_attribution = {
      status: 'captured',
      kind: 'visual_attribution',
      ref: artifactRef('visual_attribution', visualAttribution.path, 'visual-parity'),
    };
    artifacts[`visual_compare_${artifactKey(fixtureId)}_visual-attribution`] = { path: visualAttribution.path };
    updatedVisualParityArtifacts.visual_attribution_summary = summarizeVisualAttribution(visualAttribution.attribution, visualAttribution.path);
  } else if (updatedSlots.visual_diff?.reason === 'artifact_not_persisted') {
    updatedVisualParityArtifacts.visual_attribution_summary = unavailableVisualAttributionSummary();
  }

  const materialized = {
    ...fixture,
    diagnostics: rewriteDiagnosticArtifactRefs(fixture.diagnostics, rewrites, unavailableArtifactPaths),
    artifact_refs: rewriteArtifactRefs(fixture.artifact_refs, rewrites, unavailableArtifactPaths),
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
  const comparisons = arrayValue(fixture.visual_parity_comparisons);
  if (comparisons.length === 0) {
    return materialized;
  }
  const secondary = comparisons.map((comparison) => materializeSecondaryVisualCompareArtifacts({
    comparison,
    fixtureId,
    outputDirectory,
    codeboxArtifactsDirectory,
    artifacts,
  }));
  // Nested front-page comparisons share the primary route's stable artifact IDs.
  // Apply those rewrites too so every result-level visual reference is retained.
  const secondaryRewrites = new Map([...rewrites, ...secondary.flatMap((entry) => [...entry.rewrites])]);
  return rewriteVisualEvidencePaths({
    ...materialized,
    visual_parity_comparisons: secondary.map((entry) => entry.comparison),
  }, secondaryRewrites);
}

function materializeSecondaryVisualCompareArtifacts({ comparison, fixtureId, outputDirectory, codeboxArtifactsDirectory, artifacts }) {
  const visualParityArtifacts = comparison?.visual_parity_artifacts || comparison?.visualParityArtifacts;
  const slots = visualParityArtifacts?.artifacts || {};
  const surfaceId = artifactKey(comparison?.surface_id || comparison?.surfaceId || 'front-page');
  // The primary route keeps its long-standing IDs and location. Every additional
  // route is fixture/surface-scoped so identical runtime filenames cannot collide.
  if (surfaceId === 'front-page' || !slots || typeof slots !== 'object') {
    return { comparison, rewrites: new Map() };
  }
  const rewrites = new Map();
  const updatedSlots = { ...slots };
  for (const [slotName, fileStem] of [
    ['source_screenshot', 'source'],
    ['imported_screenshot', 'candidate'],
    ['diff_screenshot', 'diff'],
    ['visual_diff', 'visual-diff.json'],
    ['visual_explanation', 'visual-explanation.json'],
    ['source_dom_snapshot', 'source-dom-snapshot.json'],
    ['candidate_dom_snapshot', 'candidate-dom-snapshot.json'],
  ]) {
    const refPath = slots[slotName]?.ref?.path;
    const sourcePath = resolveCodeboxArtifactPath(refPath, codeboxArtifactsDirectory);
    if (!sourcePath || !fs.existsSync(sourcePath)) {
      continue;
    }
    const persistedPath = path.join(outputDirectory, 'visual-compare', fixtureId, surfaceId, fileStem.includes('.') ? fileStem : `${fileStem}.png`);
    fs.mkdirSync(path.dirname(persistedPath), { recursive: true });
    fs.copyFileSync(sourcePath, persistedPath);
    const artifactId = `visual_compare_${artifactKey(fixtureId)}_${surfaceId}_${fileStem}`;
    rewrites.set(refPath, { path: persistedPath, artifact_id: artifactId });
    updatedSlots[slotName] = {
      ...slots[slotName],
      status: 'captured',
      kind: slotName,
      ref: artifactRef(artifactId, persistedPath, 'visual-parity'),
    };
    artifacts[artifactId] = { path: persistedPath };
  }
  return {
    rewrites,
    comparison: rewriteVisualEvidencePaths({
      ...comparison,
      visual_parity_artifacts: { ...visualParityArtifacts, owner: 'bench_artifact_root', artifacts: updatedSlots },
    }, rewrites),
  };
}

function rewriteVisualEvidencePaths(value, rewrites) {
  if (!value || typeof value !== 'object' || rewrites.size === 0) {
    return value;
  }
  if (Array.isArray(value)) {
    return value.map((entry) => rewriteVisualEvidencePaths(entry, rewrites));
  }
  return Object.fromEntries(Object.entries(value).map(([key, entry]) => [
    key,
    typeof entry === 'string' && rewrites.has(entry) ? rewritePath(entry, rewrites) : rewriteVisualEvidencePaths(entry, rewrites),
  ]));
}

function rewritePath(value, rewrites) {
  const rewrite = rewrites.get(value);
  return typeof rewrite === 'string' ? rewrite : rewrite?.path || value;
}

function materializeVisualAttribution({ fixture, fixtureId, outputDirectory, slots, normalizer, loader, homeboyExtensionPath }) {
  const visualDiffPath = slots.visual_diff?.ref?.path;
  if (!visualDiffPath || !fs.existsSync(visualDiffPath)) {
    return null;
  }
  const refs = {
    visualExplanation: slots.visual_explanation?.ref?.path,
    sourceDomSnapshot: slots.source_dom_snapshot?.ref?.path,
    candidateDomSnapshot: slots.candidate_dom_snapshot?.ref?.path,
  };
  const visualDiff = readJsonArtifact(visualDiffPath);
  const visualExplanation = readJsonArtifact(refs.visualExplanation);
  const sourceDomSnapshot = readJsonArtifact(refs.sourceDomSnapshot);
  const candidateDomSnapshot = readJsonArtifact(refs.candidateDomSnapshot);
  const activeNormalizer = resolveWordPressVisualAttributionNormalizer({ normalizer, loader, homeboyExtensionPath });
  let attribution;
  try {
    attribution = activeNormalizer
      ? activeNormalizer({
        visualDiff,
        visualExplanation,
        sourceDomSnapshot,
        candidateDomSnapshot,
        refs,
        candidateProvenance: fixture.candidate_provenance || fixture.candidateProvenance,
      })
      : unavailableVisualAttribution(refs, visualExplanation, sourceDomSnapshot, candidateDomSnapshot);
  } catch {
    attribution = unavailableVisualAttribution(refs, visualExplanation, sourceDomSnapshot, candidateDomSnapshot, 'The Homeboy WordPress extension normalizer failed; attribution is limited to retained pixel evidence.');
  }
  const persistedPath = path.join(outputDirectory, 'visual-compare', fixtureId, 'visual-attribution.json');
  writeJsonArtifact(persistedPath, attribution);
  return { path: persistedPath, attribution };
}

function unavailableVisualAttributionSummary() {
  return {
    schema: 'static-site-importer/visual-attribution-unavailable/v1',
    status: 'unavailable',
    reason: 'primary_visual_diff_not_persisted',
    limitations: ['Primary visual diff was not retained, so visual attribution was not materialized.'],
  };
}

function summarizeVisualAttribution(attribution, attributionPath) {
  const value = attribution && typeof attribution === 'object' ? attribution : {};
  const selectorDeltas = Array.isArray(value.selector_deltas) ? value.selector_deltas : [];
  const styleDeltas = value.computed_style_deltas && typeof value.computed_style_deltas === 'object'
    ? value.computed_style_deltas
    : {};
  const elements = value.elements && typeof value.elements === 'object' ? value.elements : {};
  const summary = value.summary && typeof value.summary === 'object' ? value.summary : {};
  return {
    schema: typeof value.schema === 'string' ? value.schema : 'static-site-importer/visual-attribution-unavailable/v1',
    status: value.schema === 'homeboy/WordPressVisualAttribution/v1' ? 'available' : 'limited',
    mismatch_region_count: Array.isArray(value.mismatch_regions) ? value.mismatch_regions.length : 0,
    selector_delta_count: selectorDeltas.length,
    geometry_delta_count: selectorDeltas.filter((delta) => hasVisualAttributionGeometryDelta(delta?.bounding_box?.delta)).length,
    computed_style_delta_counts: Object.fromEntries(Object.entries(styleDeltas)
      .filter(([, deltas]) => Array.isArray(deltas))
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([category, deltas]) => [category, deltas.length])),
    changed_count: finiteVisualAttributionCount(summary.changed, elements.changed),
    added_count: finiteVisualAttributionCount(summary.added, elements.added),
    removed_count: finiteVisualAttributionCount(summary.removed, elements.removed),
    top_findings: (Array.isArray(value.top_findings) ? value.top_findings : [])
      .slice(0, VISUAL_ATTRIBUTION_TOP_FINDINGS_LIMIT)
      .map((finding) => compactVisualAttributionFinding(finding)),
    limitations_count: Array.isArray(value.limitations) ? value.limitations.length : 0,
    attribution_ref: attributionPath,
  };
}

function hasVisualAttributionGeometryDelta(delta) {
  return Object.values(delta && typeof delta === 'object' ? delta : {}).some((value) => Number(value) !== 0);
}

function finiteVisualAttributionCount(summaryValue, elements) {
  const value = Number(summaryValue);
  return Number.isFinite(value) && value >= 0 ? value : (Array.isArray(elements) ? elements.length : 0);
}

function compactVisualAttributionFinding(value) {
  const finding = value && typeof value === 'object' ? value : {};
  return Object.fromEntries(Object.entries({
    kind: finding.kind,
    summary: finding.summary,
    selector: finding.selector,
    category: finding.category,
    property: finding.property,
  }).filter(([, entry]) => typeof entry === 'string' && entry));
}

function unavailableVisualAttribution(refs, visualExplanation, sourceDomSnapshot, candidateDomSnapshot, normalizerLimitation = 'The Homeboy WordPress extension normalizer was unavailable; attribution is limited to retained pixel evidence.') {
  return {
    schema: 'static-site-importer/visual-attribution-unavailable/v1',
    evidence: refs,
    limitations: [
      normalizerLimitation,
      ...(visualExplanation ? [] : ['WP Codebox visual explanation sidecar was not captured.']),
      ...(sourceDomSnapshot && candidateDomSnapshot ? [] : ['WP Codebox DOM snapshot sidecars were not both captured.']),
    ],
  };
}

function readJsonArtifact(filePath) {
  if (!filePath) {
    return null;
  }
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

export function resolveWordPressVisualAttributionNormalizer({ normalizer, loader, homeboyExtensionPath } = {}) {
  if (typeof normalizer === 'function') {
    return normalizer;
  }
  try {
    let extension;
    if (typeof loader === 'function') {
      extension = loader();
    } else {
      let extensionPath = homeboyExtensionPath;
      if (!extensionPath) {
        const helperManifestPath = process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST;
        const resolvedHelperManifestPath = typeof helperManifestPath === 'string' && helperManifestPath
          ? path.resolve(helperManifestPath)
          : '';
        if (
          resolvedHelperManifestPath
          && path.basename(resolvedHelperManifestPath) === 'helper-manifest.js'
          && path.basename(path.dirname(resolvedHelperManifestPath)) === 'lib'
          && fs.existsSync(resolvedHelperManifestPath)
        ) {
          extensionPath = path.dirname(path.dirname(resolvedHelperManifestPath));
        }
      }
      if (!extensionPath) {
        extensionPath = process.env.HOMEBOY_EXTENSION_PATH;
      }
      if (!extensionPath) {
        return null;
      }
      const resolvedExtensionPath = path.resolve(extensionPath);
      const normalizerModulePath = path.join(resolvedExtensionPath, 'lib', 'wordpress-visual-attribution.js');
      // Dev overlays include this self-contained module without the extension's
      // unrelated package-root dependencies.
      extension = fs.existsSync(normalizerModulePath)
        ? require(normalizerModulePath)
        : require(resolvedExtensionPath);
    }
    return typeof extension?.normalizeWordPressVisualAttribution === 'function'
      ? extension.normalizeWordPressVisualAttribution
      : null;
  } catch {
    return null;
  }
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
    if (fs.existsSync(refPath)) {
      return refPath;
    }
    const artifactMarker = `${path.sep}artifacts${path.sep}`;
    const markerIndex = refPath.lastIndexOf(artifactMarker);
    if (markerIndex !== -1) {
      refPath = refPath.slice(markerIndex + artifactMarker.length);
    } else {
      return refPath;
    }
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

function rewriteDiagnosticArtifactRefs(diagnostics, rewrites, unavailablePaths = new Set()) {
  return Array.isArray(diagnostics)
    ? diagnostics.map((diagnostic) => ({ ...diagnostic, artifact_refs: rewriteArtifactRefs(diagnostic.artifact_refs, rewrites, unavailablePaths) }))
    : diagnostics;
}

function rewriteArtifactRefs(refs, rewrites, unavailablePaths = new Set()) {
  return Array.isArray(refs)
    ? refs
      .filter((ref) => !unavailablePaths.has(ref?.path))
      .map((ref) => {
        const rewrite = rewrites.get(ref?.path);
        if (!rewrite) return ref;
        if (typeof rewrite === 'string') return { ...ref, path: rewrite };
        return { ...ref, path: rewrite.path, ...(rewrite.artifact_id ? { artifact_id: rewrite.artifact_id } : {}) };
      })
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

export function validateHydratedComposerDependencies(pluginPath) {
  const autoloadPath = path.join(pluginPath, 'vendor', 'autoload.php');
  if (fs.existsSync(autoloadPath)) {
    return autoloadPath;
  }

  throw new Error(
    `Homeboy hydration is incomplete: missing ${autoloadPath}. Run \`homeboy rig up static-site-importer-fixture-matrix\`, then rerun the fixture matrix.`,
  );
}

function prepareDependencyOverrides(options) {
  const blocksEnginePhpTransformerPath = resolveBlocksEnginePhpTransformerPath(options.blocksEnginePhpTransformerPath);
  return {
    ...(blocksEnginePhpTransformerPath
      ? {
        blocks_engine_php_transformer: {
          package: 'automattic/blocks-engine-php-transformer',
          path: blocksEnginePhpTransformerPath,
          ...(options.blocksEnginePhpTransformerReference
            ? { reference: options.blocksEnginePhpTransformerReference }
            : {}),
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

function buildWpCodeboxChildCommandFailure({ error, fixtures, batchNumber, batchSuffix, batchId, batchRecipeFile, outputFile, artifactsDir, wpCodeboxBin: bin, artifactRefs }) {
  const command = wpCodeboxRecipeRunCommand({ recipeFile: batchRecipeFile, artifactsDir, outputFile, wpCodeboxBin: bin });
  return {
    schema: 'homeboy/child-command-failure/v1',
    kind: 'child_command_failed',
    label: `WP Codebox recipe-run batch ${batchSuffix}`,
    batch: batchNumber,
    batch_id: batchId || `batch-${batchSuffix}`,
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
    fixture_coverage: path.join(outputDirectory, 'fixture-coverage.json'),
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

export function optionsFromEnv(env = process.env) {
  const benchEnv = settingsBenchEnv(env);
  const config = fixtureMatrixRunConfigFromEnv(env);
  return {
    ...fixtureMatrixBenchOptions(config),
    outputDirectory: benchEnv.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY || env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY || env.HOMEBOY_BENCH_ARTIFACTS_DIR,
    staticSiteImporterSlug: benchEnv.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_SLUG || env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_SLUG,
    staticSiteImporterPlugin: benchEnv.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PLUGIN || env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PLUGIN,
    entrypoint: benchEnv.SSI_FIXTURE_MATRIX_ENTRYPOINT || env.SSI_FIXTURE_MATRIX_ENTRYPOINT,
    maxDepth: benchEnv.SSI_FIXTURE_MATRIX_MAX_DEPTH || env.SSI_FIXTURE_MATRIX_MAX_DEPTH,
    artifactRoot: benchEnv.SSI_FIXTURE_MATRIX_ARTIFACT_ROOT || env.SSI_FIXTURE_MATRIX_ARTIFACT_ROOT,
    visualParityFullPage: optionalBoolean(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE),
    visualParityBlockExternalRequests: optionalBoolean(benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_BLOCK_EXTERNAL_REQUESTS ?? env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_BLOCK_EXTERNAL_REQUESTS),
    animatedMedia: benchEnv.SSI_FIXTURE_MATRIX_ANIMATED_MEDIA ?? env.SSI_FIXTURE_MATRIX_ANIMATED_MEDIA,
    runtimePresentationEvidence: optionalBoolean(benchEnv.SSI_FIXTURE_MATRIX_RUNTIME_PRESENTATION_EVIDENCE ?? env.SSI_FIXTURE_MATRIX_RUNTIME_PRESENTATION_EVIDENCE) === true,
    visualParityCandidateUrl: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_CANDIDATE_URL || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_CANDIDATE_URL,
    visualParitySourceBaseUrl: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_SOURCE_BASE_URL || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_SOURCE_BASE_URL,
    visualParityWaitFor: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_WAIT_FOR || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_WAIT_FOR,
    visualParityDurationMs: benchEnv.SSI_FIXTURE_MATRIX_VISUAL_PARITY_DURATION_MS || env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_DURATION_MS,
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
    visualParityBlockExternalRequests: options.visualParityBlockExternalRequests,
    visualParityWaitFor: options.visualParityWaitFor,
    visualParityDurationMs: options.visualParityDurationMs,
    animatedMedia: options.animatedMedia,
    ...normalizeVisualAttributionOptions(options),
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

function runtimePresentationEvidenceRecipeInput(options) {
  return { runtimePresentationEvidence: options.runtimePresentationEvidence === true };
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
  --blocks-engine-php-transformer-reference <ref>
                                     Immutable 40-64 character hexadecimal package source reference.
  --entrypoint <file>                Fixture entrypoint. Defaults to index.html.
  --max-depth <n>                    Fixture discovery depth. Defaults to 2.
  --surface-coverage <n>             Capture front page plus up to n secondary HTML surfaces.
  --max-extra-surfaces <n>           Secondary surface cap when surface coverage is boolean/object-driven.
  --class <fixture_class>            Filter to one authored fixture_class lane.
  --tag <tag>                        Filter to fixtures carrying an authored tag.
  --capability <capability>          Filter to fixtures carrying an authored capability.
  --risk-profile <profile>           Filter to one authored risk_profile.
  --complexity <n>                   Filter to fixtures with authored complexity exactly n.
  --max-complexity <n>               Filter to fixtures with authored complexity <= n.
  --wordpress-version <version>      WP Codebox WordPress version. Defaults to latest.
  --batch-size <n>                   Fixtures per WP Codebox run when --run is used. Defaults to 10.
  --concurrency <n>                  Batches (WP Codebox sandboxes) to run in parallel. Defaults to ${DEFAULT_BATCH_CONCURRENCY}, hard-capped at ${MAX_BATCH_CONCURRENCY}.
  --surface-coverage <n>             Opt into bounded secondary page browser evidence per fixture. Hard-capped at 10 extra surfaces.
  --no-editor-validation            Skip browser editor block validation.
  --no-visual-parity                Skip wordpress.visual-compare recipe steps. Same as SSI_FIXTURE_MATRIX_VISUAL_PARITY=0.
  --visual-parity-block-external-requests <bool>
                                       Keep visual comparison offline when true (default). Same as SSI_FIXTURE_MATRIX_VISUAL_PARITY_BLOCK_EXTERNAL_REQUESTS.
  --animated-media <allow|first-frame>
                                       Animated image capture policy. Same as SSI_FIXTURE_MATRIX_ANIMATED_MEDIA.
  --run                             Execute WP Codebox recipes. Omit locally to only materialize artifacts.
`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  await main();
}
