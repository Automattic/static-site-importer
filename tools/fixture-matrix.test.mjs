/**
 * External dependencies
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, symlinkSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { PNG } from 'pngjs';

/**
 * Internal dependencies
 */
import runFixtureMatrixBench, {
  boundedConcurrency,
  composerPathRepositoryConfig,
  FIXTURE_MATRIX_PROGRESS_PREFIX,
  FIXTURE_MATRIX_PROGRESS_SCHEMA,
  fixtureMatrixBatchRunSummary,
  mapWithConcurrency,
  materializeMaterializationSidecars,
  materializeVisualCompareArtifacts,
  materializeEditorCanvasArtifacts,
  optionsFromEnv,
  resolveWordPressVisualAttributionNormalizer,
  resolveBlocksEnginePhpTransformerPath,
  runFixtureMatrix,
  validateHydratedComposerDependencies,
} from '../bench/static-site-fixture-matrix.bench.mjs';
import {
  buildCodeFreshness,
  buildFixtureMatrixRunPlan,
  FIXTURE_MATRIX_PHASE_PLAN_SCHEMA,
  phaseFailureDiagnostic,
  SOLVED_ONLY_LANE_ID,
  resolvePathFreshness,
  summarizeBenchRun,
  summarizeRun,
} from './run-fixture-matrix.mjs';
import {
  compareFindingPackets,
  selectorFamily,
} from './compare-finding-packets.mjs';
import {
  buildFixtureMatrixRecipe,
  classifyFixture,
  classifyStaticSiteFinding,
  collectBlockComposition,
  collectEditorValidationDiagnostics,
  collectEditorValidation,
  collectFixtureMatrixRunResults,
  collectMatrixEvidence,
  computeFixtureEditorQuality,
  parseSerializedBlockNames,
  collectVisualParityDiagnostics,
  classifyVisualDiffRegions,
  findBestVisualParityOffset,
  liveWpParityCaptureStep,
  liveWpParityEnabled,
  MAX_EXTRA_SURFACE_COUNT,
  normalizeSurfaceCoverageOptions,
  runLiveWpParity,
  normalizeLiveWpParityReport,
  selectFixtureSurfaces,
  buildFixtureArtifact,
  createFixtureMatrix,
  editorBlockValidationStep,
  EDITOR_VALIDATE_BLOCKS_COMMAND,
  EDITOR_VALIDATION_METHOD,
  buildGutenbergIncompatibilityRegistry,
  renderGutenbergIncompatibilityRegistryMarkdown,
  normalizeFixtureMatrixResult,
  normalizeLossClass,
  stageFixtureSource,
  buildVisualParityEvidenceReport,
  VISUAL_PARITY_DETERMINISTIC_CSS,
  VISUAL_PARITY_MISMATCH_KIND,
  visualParityCompareStep,
  normalizeVisualAttributionOptions,
  fixtureMatrixBenchOptions,
  fixtureMatrixGateConfig,
  fixtureMatrixHomeboySettings,
  fixtureMatrixRecipeInput,
  fixtureMatrixRunConfigFromEnv,
  FIXTURE_MATRIX_RUN_FIELDS,
  normalizeFixtureMatrixRunConfig,
  resolveFixtureSearchRoots,
  wordpressServedPath,
  writeFixtureMatrixArtifacts,
  RUNTIME_PRESENTATION_EVIDENCE_SCHEMA,
  RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE,
  collectRuntimePresentationEvidence,
  runtimePresentationEvidenceMergeStep,
  runtimePresentationEvidenceProbeStep,
  writeFixtureMatrixResultArtifacts,
} from '../lib/fixture-matrix.mjs';

import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import { collectQualityMetrics } from '../lib/fixture-matrix/collectors/quality-metrics.mjs';
import { collectEditorInteraction, collectEditorPresentation, collectSurfaceRecords } from '../lib/fixture-matrix/collectors/run-intake.mjs';
import { runWpCodeboxRecipe, wpCodeboxBin } from './wp-codebox/recipe.mjs';

const completeEditorPresentation = {
  schema: 'static-site-importer/editor-presentation-evidence/v3',
  provider_schema: 'wp-codebox/editor-presentation/v1',
  canvas_document_type: 'iframe',
  iframe_count: 1,
  expected_identity_count: 1,
  observed_identity_count: 1,
  expected_identities: ['a'.repeat(64)],
  observed_identities: ['a'.repeat(64)],
  missing_identities: [],
  expected_identities_complete: true,
  coverage_complete: true,
  idle_canvas: { schema: 'wp-codebox/editor-idle-canvas/v1', status: 'captured', onboarding_modal_count: 0 },
  matched_rendering: { schema: 'wp-codebox/editor-presentation-match/v1', status: 'passed', equivalent_canvas_widths: true, major_geometry_drift: false, unreadable_content: false, hidden_content: false, unresolved_asset_count: 0, frontend_screenshot: 'matched/frontend.png', editor_screenshot: 'matched/editor.png', diff_screenshot: 'matched/diff.png' },
};

const completeEditorInteraction = {
  schema: 'static-site-importer/editor-interaction-evidence/v1',
  provider_schema: 'wp-codebox/editor-actions/v1',
  selection: { status: 'ok' },
  text_mutation: { status: 'ok', mutation_status: 'applied' },
  block_movement: { status: 'ok', mutation_status: 'applied' },
  save: { schema: 'wp-codebox/editor-save/v1', status: 'saved', marker_present: true },
  reload: { status: 'ok' },
  post_save_validation: { schema: 'wp-codebox/editor-validity/v1', status: 'clean' },
};

const completeEditorValidation = {
  schema: 'wp-codebox/editor-validate-blocks/v1',
  validation_method: 'wp.blocks.validateBlock',
  validation_provider: 'wordpress-block-editor',
  content_source: 'edited-post-content',
  block_types_registered: 42,
  result_count: 8,
  results_complete: true,
  total_blocks: 8,
  valid_blocks: 8,
  invalid_blocks: 0,
};

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const fixtureRoot = path.join(packageRoot, 'tests', 'fixtures', 'fixture-matrix');
const syntheticFixtureCount = 3;

test('editor presentation intake compares provider-resolved expected and observed identities', () => {
  const globalIdentity = 'a'.repeat(64);
  const frontPageIdentity = 'b'.repeat(64);
  const otherRouteIdentity = 'c'.repeat(64);
  assert.deepEqual(collectEditorPresentation({
    summary: {
      editorPresentation: {
        schema: 'wp-codebox/editor-presentation/v1',
        canvasDocumentType: 'iframe',
        iframeCount: 1,
        generatedPresentationIdentities: [frontPageIdentity, globalIdentity, globalIdentity.toUpperCase()],
        expectedGeneratedPresentationIdentities: [globalIdentity, frontPageIdentity],
        expectedGeneratedPresentationIdentitiesComplete: true,
        idleCanvas: { schema: 'wp-codebox/editor-idle-canvas/v1', status: 'captured', onboardingModalCount: 0 },
        matchedRendering: { schema: 'wp-codebox/editor-presentation-match/v1', status: 'passed', equivalentCanvasWidths: true, majorGeometryDrift: false, unreadableContent: false, hiddenContent: false, unresolvedAssetCount: 0, frontendScreenshot: 'front.png', editorScreenshot: 'editor.png', diffScreenshot: 'diff.png' },
      },
    },
    import_report: {
      blocks_engine: {
        wordpress_site_plan: {
          assets: [
            { kind: 'css', content_hash: globalIdentity, scopes: [{ kind: 'global' }] },
            { kind: 'css', content_hash: frontPageIdentity, scopes: [{ kind: 'page', front_page: true }] },
            { kind: 'css', content_hash: otherRouteIdentity, scopes: [{ kind: 'page', route_path: 'about' }] },
          ],
        },
      },
    },
  }), {
    schema: 'static-site-importer/editor-presentation-evidence/v3',
    provider_schema: 'wp-codebox/editor-presentation/v1',
    canvas_document_type: 'iframe',
    iframe_count: 1,
    expected_identity_count: 2,
    observed_identity_count: 2,
    expected_identities: [globalIdentity, frontPageIdentity],
    observed_identities: [globalIdentity, frontPageIdentity],
    missing_identities: [],
    expected_identities_complete: true,
    coverage_complete: true,
    idle_canvas: { schema: 'wp-codebox/editor-idle-canvas/v1', status: 'captured', onboarding_modal_count: 0 },
    matched_rendering: { schema: 'wp-codebox/editor-presentation-match/v1', status: 'passed', equivalent_canvas_widths: true, major_geometry_drift: false, unreadable_content: false, hidden_content: false, unresolved_asset_count: 0, frontend_screenshot: 'front.png', editor_screenshot: 'editor.png', diff_screenshot: 'diff.png' },
  });
});

test('editor interaction intake requires typed state transitions without retaining step bulk', () => {
  assert.deepEqual(collectEditorInteraction([{ command: 'wordpress.editor-actions', metadata: { recipe_phase: 'editor-persistence' } }, {
    command: 'wordpress.editor-actions',
    steps: [
      { index: 0, kind: 'navigate', status: 'ok' },
      { index: 2, kind: 'insertBlock', status: 'ok', editorMutation: { status: 'applied', before: { contentSha256: 'before' }, after: { contentSha256: 'after' } } },
      { index: 3, kind: 'selectBlock', status: 'ok' },
      { index: 4, kind: 'moveBlock', status: 'ok', editorMutation: { status: 'applied' } },
      { index: 5, kind: 'savePost', status: 'ok' },
      { index: 6, kind: 'reload', status: 'ok' },
    ],
    summary: {
      editorSave: { schema: 'wp-codebox/editor-save/v1', status: 'saved', markerPresent: true, contentSha256: 'saved' },
      editorValidity: { schema: 'wp-codebox/editor-validity/v1', status: 'clean', warningCount: 0 },
    },
  }]), completeEditorInteraction);
});

test('editor presentation intake does not reconstruct expected identities from bounded site-plan data', () => {
  const identity = 'd'.repeat(64);
  assert.deepEqual(collectEditorPresentation({
    editor_open: { summary: { editorPresentation: { schema: 'wp-codebox/editor-presentation/v1', canvasDocumentType: 'iframe', iframeCount: 1, generatedPresentationIdentities: [], expectedGeneratedPresentationIdentities: [identity], expectedGeneratedPresentationIdentitiesComplete: true } } },
    import_report: { artifact_ref: 'import-report.json' },
  }, {
    wordpress_site_plan: { assets: [{ kind: 'css', payload_sha256: identity }] },
  }), {
    schema: 'static-site-importer/editor-presentation-evidence/v3',
    provider_schema: 'wp-codebox/editor-presentation/v1',
    canvas_document_type: 'iframe',
    iframe_count: 1,
    expected_identity_count: 1,
    observed_identity_count: 0,
    expected_identities: [identity],
    observed_identities: [],
    missing_identities: [identity],
    expected_identities_complete: true,
    coverage_complete: false,
    idle_canvas: { schema: '', status: '', onboarding_modal_count: -1 },
    matched_rendering: { schema: '', status: '', equivalent_canvas_widths: false, major_geometry_drift: null, unreadable_content: null, hidden_content: null, unresolved_asset_count: -1, frontend_screenshot: '', editor_screenshot: '', diff_screenshot: '' },
  });
});

test('bounded site-plan fallback retains scopes and cannot certify truncated presentation assets', () => {
  const identity = (index) => index.toString(16).padStart(64, '0');
  const assets = Array.from({ length: 51 }, (_, index) => ({
    kind: 'css',
    path: `assets/${String(index).padStart(2, '0')}.css`,
    payload_sha256: identity(index),
    scopes: [{ kind: 'page', ...(index === 0 ? { route_path: 'about' } : index === 1 ? { front_page: true } : { kind: 'global' }) }],
  }));
  const evidence = collectMatrixEvidence({
    import_report: { blocks_engine: { wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2', assets } } },
  });
  const observed = evidence.wordpress_site_plan.assets.map((asset) => asset.payload_sha256);
  const presentation = collectEditorPresentation({
    summary: { editorPresentation: { schema: 'wp-codebox/editor-presentation/v1', iframeCount: 1, generatedPresentationIdentities: observed } },
    import_report: { artifact_ref: 'import-report.json' },
  }, evidence);

  assert.equal(evidence.wordpress_site_plan.assets_truncated, true);
  assert.deepEqual(evidence.wordpress_site_plan.assets[0].scopes, [{ kind: 'page', route_path: 'about' }]);
  assert.equal(presentation.expected_identities.includes(identity(0)), false);
  assert.equal(presentation.expected_identities.includes(identity(1)), false);
  assert.equal(presentation.expected_identities_complete, false);
  assert.equal(presentation.coverage_complete, false);
});

test('declared site-plan asset counts make partial fallback presentation evidence fail closed', () => {
  const identity = (index) => index.toString(16).padStart(64, '0');
  const assets = Array.from({ length: 50 }, (_, index) => ({
    kind: 'css',
    path: `assets/${String(index).padStart(2, '0')}.css`,
    payload_sha256: identity(index),
    scopes: [{ kind: 'global' }],
  }));
  const evidence = collectMatrixEvidence({
    import_report: { blocks_engine: { wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2', asset_count: 51, assets } } },
  });
  const presentation = collectEditorPresentation({
    summary: { editorPresentation: { schema: 'wp-codebox/editor-presentation/v1', iframeCount: 1, generatedPresentationIdentities: assets.map((asset) => asset.payload_sha256) } },
    import_report: { artifact_ref: 'import-report.json' },
  }, evidence);

  assert.equal(evidence.wordpress_site_plan.assets_truncated, true);
  assert.equal(presentation.expected_identities_complete, false);
  assert.equal(presentation.coverage_complete, false);
});

test('matrix evidence requires and summarizes the canonical materialization receipt', () => {
  const evidence = collectMatrixEvidence({
    import_report: {
      blocks_engine: {
        transformer: { package: 'automattic/blocks-engine-php-transformer', version: 'dev-trunk', reference: 'a'.repeat(40) },
        wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2', assets: [] },
      },
    },
    materialization_receipt: {
      schema: 'static-site-importer/materialization-receipt/v1',
      status: 'completed',
      plan_hash: 'plan-hash',
      completed: { pages: { 'index.html': 1 }, files: [{ target_path: 'style.css' }], operations: [], declaration_ids: ['asset'] },
    },
  });
  assert.equal(evidence.readiness, 'verified');
  assert.deepEqual(evidence.materialization_receipt, { schema: 'static-site-importer/materialization-receipt/v1', status: 'completed', plan_hash: 'plan-hash', page_count: 1, file_count: 1, operation_count: 0, declaration_count: 1 });
});

test('matrix evidence fails closed when the materialization receipt is absent', () => {
  const evidence = collectMatrixEvidence({ import_report: { blocks_engine: { transformer: { package: 'package', version: '1.0.0', reference: 'a'.repeat(40) }, wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2', assets: [] } } } });
  assert.equal(evidence.readiness, 'runtime_evidence_incomplete');
  assert.ok(evidence.missing.includes('materialization_receipt'));
});

test('legacy validation payloads without a receipt contract remain consumable', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-legacy-no-sidecar-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'legacy-no-sidecar' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: { fixture_id: 'simple-site', status: 'passed', success: true, import_report: { blocks_engine: { available: true } } },
  });
  assert.equal(result.fixtures[0].status, 'passed');
  assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'missing');
});

test('matrix evidence consumes the bounded fixture diagnostic contract', () => {
  const reference = 'b'.repeat(40);
  const evidence = collectMatrixEvidence({
    fixture_diagnostics: {
      blocks_engine: {
        transformer: { package: 'automattic/blocks-engine-php-transformer', version: 'dev-trunk', reference },
        wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2', asset_count: 1, assets: [{ target_path: 'assets/app.js', payload_sha256: 'sha256' }] },
      },
      materialization_receipt: {
        schema: 'static-site-importer/materialization-receipt/v1',
        status: 'completed',
        plan_hash: 'plan-hash',
        page_count: 1,
        file_count: 2,
        operation_count: 3,
        declaration_count: 4,
        block_provenance_count: 1,
        block_provenance: [{ source: { schema: 'static-site-importer/page-provenance/v1', source_path: 'contact.html', reconciliation_identity: 'page-id' }, stages: [{ stage: 'blocks-engine/wordpress-site-plan-resolver', output: { sha256: 'resolved-hash', bytes: 18 } }, { stage: 'static-site-importer/runtime-entity-bindings', input_sha256: 'resolved-hash', output: { sha256: 'bound-hash', bytes: 21 } }] }],
      },
    },
  });
  assert.equal(evidence.readiness, 'verified');
  assert.equal(evidence.transformer.package_reference, reference);
  assert.equal(evidence.wordpress_site_plan.asset_count, 1);
  assert.equal(evidence.materialization_receipt.schema, 'static-site-importer/materialization-receipt/v1');
  assert.equal(evidence.materialization_receipt.status, 'completed');
  assert.equal(evidence.materialization_receipt.plan_hash, 'plan-hash');
  assert.deepEqual(evidence.materialization_receipt.block_provenance, [{
    source: { schema: 'static-site-importer/page-provenance/v1', source_path: 'contact.html', reconciliation_identity: 'page-id' },
    stages: [{ stage: 'blocks-engine/wordpress-site-plan-resolver', output: { sha256: 'resolved-hash', bytes: 18 } }, { stage: 'static-site-importer/runtime-entity-bindings', input_sha256: 'resolved-hash', output: { sha256: 'bound-hash', bytes: 21 } }],
  }]);
  assert.equal(evidence.materialization_receipt.block_provenance_count, 1);
  assert.equal(JSON.stringify(evidence.materialization_receipt.block_provenance).includes('preview'), false);
});

test('block composition uses nested runtime quality metrics and diagnostic fallback counts', () => {
  const composition = collectBlockComposition({
    quality_metrics: { metrics: { block_count: 318 } },
    fixture_diagnostics: { quality_counts: { core_html_block_count: 0, freeform_block_count: 0 } },
  });
  assert.deepEqual(composition, { block_total: 318, native_block_count: 318, core_html_block_count: 0, block_type_counts: null, source: 'quality_counts' });
});

test('bounded runtime quality retains the promotion gate pass signal', () => {
  const quality = collectQualityMetrics({
    quality: { pass: true },
    fixture_diagnostics: { quality_counts: { block_count: 318, fallback_count: 0 } },
  });
  assert.equal(quality.pass, true);
  assert.equal(quality.block_count, 318);
});

test('Homeboy component assigns runtime and dependency capabilities to WordPress', () => {
  const config = JSON.parse(readFileSync(path.join(packageRoot, 'homeboy.json'), 'utf8'));

  assert.ok(config.extensions?.wordpress, 'WordPress extension is required for fixture runtime workloads');
  assert.equal(config.extensions?.nodejs, undefined, 'Node.js must not register npm release actions for this WordPress plugin');
  assert.equal(config.capability_extensions?.deps, 'wordpress', 'WordPress owns npm and Composer Lab hydration');
});

test('discovers SSI fixtures and writes Blocks Engine site artifacts', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-matrix-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'test-matrix' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix });
  const artifact = JSON.parse(readFileSync(path.join(outputDirectory, 'simple-site', 'artifact.json'), 'utf8'));

  assert.equal(matrix.schema, 'static-site-importer/fixture-matrix/v1');
  assert.equal(matrix.count, 1);
  assert.equal(matrix.fixtures[0].id, 'simple-site');
  assert.equal(artifact.schema, 'blocks-engine/php-transformer/site-artifact/v1');
  // Files are base64-encoded exactly like the product's canonical import request, so
  // hydrate via `content_base64` to read the payload.
  const indexFile = artifact.files.find((file) => file.path === 'website/index.html');
  assert.ok(indexFile);
  assert.ok(Buffer.from(indexFile.content_base64, 'base64').toString('utf8').includes('Simple SSI Fixture'));
  assert.ok(artifact.files.some((file) => file.path === 'website/style.css'));
  assert.equal(written.result.summary.generation_status, 'succeeded');
  assert.equal(written.result.summary.execution_status, 'not_requested');
  assert.equal(written.result.summary.succeeded, 0);
  assert.equal(written.result.summary.failed, 0);
  assert.equal(written.result.summary.not_run, 1);
  assert.equal(written.result.summary.finding_count, 0);
  assert.equal(written.result.summary.unacceptable_finding_count, 0);
  assert.equal(written.result.summary.unacceptable_loss_classes.fixture_not_run, undefined);
  assert.equal(written.result.findings.some((finding) => finding.loss_class === 'fixture_not_run'), false);
});

test('execution-requested fixture matrices still fail missing validation results', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'missing-run-result-test' });
  const result = normalizeFixtureMatrixResult({ matrix, execution_status: 'requested' });

  assert.equal(result.summary.generation_status, 'succeeded');
  assert.equal(result.summary.execution_status, 'requested');
  assert.equal(result.summary.succeeded, 0);
  assert.equal(result.summary.failed, 1);
  assert.equal(result.summary.not_run, 1);
  assert.equal(result.summary.unacceptable_finding_count, 1);
  assert.equal(result.summary.unacceptable_loss_classes.fixture_not_run, 1);
  assert.equal(result.findings.some((finding) => finding.loss_class === 'fixture_not_run'), true);
});

test('gutenberg incompatibility registry aggregates recurring custom block candidates across fixtures', () => {
  const result = normalizeFixtureMatrixResult({
    matrix: {
      id: 'gutenberg-registry-synthetic',
      fixture_root: '/tmp/fixtures',
      fixtures: [
        { id: 'artist', fixture_path: '/tmp/fixtures/artist' },
        { id: 'coffee', fixture_path: '/tmp/fixtures/coffee' },
        { id: 'saas', fixture_path: '/tmp/fixtures/saas' },
      ],
    },
    results: [
      {
        fixture_id: 'artist',
        status: 'failed',
        diagnostics: [
          { kind: 'unsupported_html_fallback', observed: { block_name: 'core/html' }, selector: '.newsletter form', source: { snippet: '<form class="newsletter"><input type="email"><button>Subscribe</button></form>' }, reason: 'No core form block can represent newsletter form submission.' },
          { kind: 'unsupported_html_fallback', observed: { block_name: 'core/html' }, selector: '.logo svg', source: { snippet: '<svg><defs><linearGradient id="g"></linearGradient></defs></svg>' }, reason: 'Inline SVG gradient fallback.' },
        ],
      },
      {
        fixture_id: 'coffee',
        status: 'failed',
        diagnostics: [
          { kind: 'unsupported_html_fallback', observed: { block_name: 'core/html' }, selector: '.map svg', source: { snippet: '<svg><filter id="blur"></filter></svg>' }, reason: 'Inline SVG filter fallback.' },
        ],
      },
      {
        fixture_id: 'saas',
        status: 'failed',
        diagnostics: [
          { kind: 'preserved_runtime_island', loss_class: 'preserved_runtime_island', runtime_carried: true, selector: '.cart-control', source: { snippet: '<button class="add-to-cart">Add to cart</button><input class="qty" type="number">' }, reason: 'Quantity stepper and add-to-cart require runtime.' },
          { kind: 'unsupported_html_fallback', observed: { block_name: 'core/html' }, selector: '.signup form', source: { snippet: '<form><input name="email"><button>Start</button></form>' }, reason: 'Static form fallback.' },
        ],
      },
    ],
  });

  const registry = result.gutenberg_incompatibility_registry;
  const byKey = Object.fromEntries(registry.patterns.map((row) => [row.pattern_key, row]));

  assert.equal(registry.schema, 'static-site-importer/gutenberg-incompatibility-registry/v1');
  assert.equal(byKey['static-form'].classification, 'custom-block-candidate');
  assert.equal(byKey['static-form'].fixture_count, 2);
  assert.equal(byKey['inline-svg-filter-gradient'].classification, 'custom-block-candidate');
  assert.equal(byKey['inline-svg-filter-gradient'].fixture_count, 2);
  assert.equal(byKey['js-commerce-controls'].classification, 'convertible');
  assert.equal(byKey['js-commerce-controls'].fixture_count, 1);
  assert.deepEqual(registry.summary.top_patterns[0].classification, 'custom-block-candidate');
});

test('gutenberg incompatibility registry keeps runtime islands separate and consumes editor divergence signals', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'runtime-and-editor-signals',
    fixtures: [
      {
        fixture_id: 'canvas-fixture',
        editor_render_divergence: [{ selector: '.hero-card', reason: 'Frontend renders but editor canvas drops the transformed child.' }],
      },
      {
        fixture_id: 'runtime-fixture',
      },
    ],
    findings: [
      { fixture_id: 'runtime-fixture', kind: 'preserved_runtime_island', loss_class: 'preserved_runtime_island', runtime_carried: true, selector: 'canvas', reason: 'Canvas runtime island preserved.' },
    ],
  });
  const byKey = Object.fromEntries(registry.patterns.map((row) => [row.pattern_key, row]));

  assert.equal(byKey['legitimate-runtime-island'].classification, 'runtime-island');
  assert.equal(byKey['editor-render-divergence'].classification, 'convertible');
  assert.equal(byKey['editor-render-divergence'].signals.editor_render_divergence, 1);
});

test('gutenberg incompatibility registry promotes recurring semantic description lists without materializing a plugin', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'semantic-description-list',
    fixtures: [{ fixture_id: 'catalog' }, { fixture_id: 'documentation' }],
    findings: [
      { fixture_id: 'catalog', observed_block_name: 'core/html', source_snippet: '<dl><dt>Size</dt><dd>Large</dd></dl>' },
      { fixture_id: 'documentation', observed_block_name: 'core/html', source_snippet: '<dl><dt>API</dt><dd>Stable</dd></dl>' },
    ],
  });
  const pattern = registry.patterns.find((row) => row.pattern_key === 'semantic-description-list');

  assert.equal(pattern.classification, 'custom-block-candidate');
  assert.equal(pattern.limitation_type, 'real_gutenberg_gap');
  assert.equal(pattern.no_core_block_path, true);
  assert.equal(pattern.fixture_count, 2);
  assert.equal(JSON.stringify(registry).includes('companion_plugin_payload'), false);
});

test('gutenberg incompatibility registry separates fixture decision axes', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'decision-axis-map',
    fixtures: [
      {
        fixture_id: 'cv',
        status: 'passed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/cv/screenshot.png' }],
        editor_presentation: completeEditorPresentation,
        editor_interaction: completeEditorInteraction,
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
        block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
        editor_validation: completeEditorValidation,
        editor_quality: { editor_validated_block_total: 8, editor_invalid_count: 0, core_html_block_count: 0 },
      },
      {
        fixture_id: 'artist',
        status: 'failed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/artist/screenshot.png' }],
        editor_presentation: completeEditorPresentation,
        editor_interaction: completeEditorInteraction,
        block_composition: { block_total: 10, native_block_count: 9, core_html_block_count: 1 },
        editor_validation: completeEditorValidation,
        editor_quality: { editor_validated_block_total: 10, editor_invalid_count: 0, core_html_block_count: 1 },
        visual_diff_regions: [{ dominant_cause: 'position_offset', pixel_count: 2500 }],
      },
      {
        fixture_id: 'coffee',
        status: 'failed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/coffee/screenshot.png' }],
        editor_presentation: completeEditorPresentation,
        editor_interaction: completeEditorInteraction,
        editor_validation: completeEditorValidation,
        editor_quality: { editor_validated_block_total: 12, editor_invalid_count: 0, core_html_block_count: 0 },
        visual_diff_regions: [{ dominant_cause: 'font_metric_drift', pixel_count: 900 }],
      },
      {
        fixture_id: 'saas',
        status: 'failed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/saas/screenshot.png' }],
        editor_presentation: completeEditorPresentation,
        editor_interaction: completeEditorInteraction,
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
        editor_validation: completeEditorValidation,
        editor_quality: { editor_validated_block_total: 6, editor_invalid_count: 1, core_html_block_count: 0 },
      },
      {
        fixture_id: 'runtime-provider',
        status: 'failed',
      },
      {
        fixture_id: 'cv-missing-editor-evidence',
        status: 'passed',
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
        block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
        editor_quality: { editor_validated_block_total: 8, editor_invalid_count: 0, core_html_block_count: 0 },
      },
    ],
    findings: [
      {
        fixture_id: 'artist',
        kind: 'unsupported_html_fallback',
        observed_block_name: 'core/html',
        reason_code: 'html_form_fallback',
        selector: 'form.newsletter',
        source_snippet: '<form><input type="email"><button>Subscribe</button></form>',
      },
      {
        fixture_id: 'saas',
        kind: 'editor_block_invalid',
        loss_class: 'editor_block_invalid',
        selector: '.hero',
        reason: 'Editor block validation failed.',
      },
      {
        fixture_id: 'runtime-provider',
        kind: 'recipe_step_failure',
        loss_class: 'runtime_execution_failed',
        reason: 'wp-codebox command failed before evidence capture.',
      },
    ],
  });
  const decisions = Object.fromEntries(registry.fixture_decisions.map((row) => [row.fixture_id, row]));
  const patterns = Object.fromEntries(registry.patterns.map((row) => [row.pattern_key, row]));
  const markdown = renderGutenbergIncompatibilityRegistryMarkdown(registry);

  assert.equal(decisions.cv.frontend_visual_status, 'passed');
  assert.equal(decisions.cv.editor_canvas_status, 'visible');
  assert.equal(decisions.cv.editor_presentation_status, 'passed');
  assert.equal(decisions.cv.block_validity_status, 'valid');
  assert.equal(decisions.cv.editor_validity_status, 'valid');
  assert.equal(decisions.cv.native_editability_status, 'native_editable');
  assert.equal(decisions.cv.solved_candidate, true);
  assert.equal(decisions.cv.acceptance_status, 'solved_candidate');
  assert.equal(decisions.cv.solved_candidate_reason, 'passed frontend visual parity, matched editor presentation and interaction, block validity, and native editability without limitation patterns');
  assert.equal(decisions.artist.native_editability_status, 'custom_block_candidate');
  assert.equal(decisions.artist.frontend_visual_status, 'visual_mismatch');
  assert.equal(decisions.artist.editor_canvas_status, 'visible');
  assert.equal(decisions.artist.acceptance_status, 'native_editability_blocker');
  assert.equal(decisions.artist.visible_html_island_count, 1);
  assert.equal(decisions.artist.visible_runtime_or_html_islands, 1);
  assert.deepEqual(decisions.artist.gutenberg_gap_patterns, ['static-form']);
  assert.deepEqual(decisions.artist.visual_only_patterns, ['visual-position_offset']);
  assert.equal(decisions.coffee.native_editability_status, 'native_editable');
  assert.equal(decisions.coffee.acceptance_status, 'visual_only_blocker');
  assert.deepEqual(decisions.coffee.visual_only_patterns, ['visual-font_metric_drift']);
  assert.equal(decisions.saas.editor_canvas_status, 'visible');
  assert.equal(decisions.saas.editor_validity_status, 'invalid_blocks');
  assert.equal(decisions.saas.native_editability_status, 'editor_invalid');
  assert.equal(decisions.saas.acceptance_status, 'editor_blocker');
  assert.equal(decisions['runtime-provider'].frontend_visual_status, 'provider_runtime_blocked');
  assert.equal(decisions['runtime-provider'].acceptance_status, 'provider_runtime_blocker');
  assert.equal(decisions['cv-missing-editor-evidence'].frontend_visual_status, 'passed');
  assert.equal(decisions['cv-missing-editor-evidence'].editor_canvas_status, 'not_captured');
  assert.equal(decisions['cv-missing-editor-evidence'].native_editability_status, 'unknown');
  assert.equal(decisions['cv-missing-editor-evidence'].acceptance_status, 'evidence_gap');
  assert.equal(patterns['static-form'].limitation_type, 'real_gutenberg_gap');
  assert.equal(patterns['visual-position_offset'].limitation_type, 'visual_only_style_drift');
  assert.equal(registry.summary.fixture_decision_counts.solved_candidate, 1);
  assert.equal(registry.summary.fixture_decision_counts.visual_only_blocker, 1);
  assert.equal(registry.summary.fixture_decision_counts.editor_blocker, 1);
  assert.equal(registry.summary.fixture_decision_counts.native_editability_blocker, 1);
  assert.equal(registry.summary.fixture_decision_counts.provider_runtime_blocker, 1);
  assert.equal(registry.summary.fixture_decision_counts.evidence_gap, 1);
  assert.deepEqual(registry.summary.fixture_decision_groups, {
    evidence_gap: ['cv-missing-editor-evidence'],
    editor_blocker: ['saas'],
    native_editability_blocker: ['artist'],
    provider_runtime_blocker: ['runtime-provider'],
    solved_candidate: ['cv'],
    visual_only_blocker: ['coffee'],
  });
  assert.equal(registry.summary.editor_validity_counts.invalid_blocks, 1);
  assert.match(markdown, /## Fixture Decision Groups/);
  assert.match(markdown, /\| solved_candidate \| `cv` \|/);
  assert.match(markdown, /## Fixture Decisions/);
  assert.match(markdown, /solved_candidate/);
  assert.match(markdown, /native_editability_blocker/);
  assert.match(markdown, /`static-form`/);
});

test('gutenberg incompatibility registry attributes nested svg to the outer fallback island', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'nested-svg-attribution',
    fixtures: [
      { fixture_id: 'artist' },
      { fixture_id: 'coffee' },
    ],
    findings: [
      {
        fixture_id: 'artist',
        kind: 'unsupported_html_fallback',
        observed_block_name: 'core/html',
        reason_code: 'html_unsupported_element',
        pattern_family: 'html_div',
        selector: 'div.contact-content',
        source_snippet: '<div class="contact-content"><a href="mailto:test@example.com"><svg><defs><linearGradient id="g"></linearGradient></defs></svg>Email</a></div>',
      },
      {
        fixture_id: 'coffee',
        kind: 'unsupported_html_fallback',
        observed_block_name: 'core/html',
        reason_code: 'html_inline_svg_fallback',
        pattern_family: 'inline_svg',
        selector: 'a.nav-logo > svg',
        source_snippet: '<svg><defs><linearGradient id="g"></linearGradient></defs></svg>',
      },
    ],
  });
  const byKey = Object.fromEntries(registry.patterns.map((row) => [row.pattern_key, row]));

  assert.equal(byKey['contact-layout'].finding_count, 1);
  assert.equal(byKey['contact-layout'].classification, 'convertible');
  assert.equal(byKey['contact-layout'].fixtures[0], 'artist');
  assert.equal(byKey['inline-svg-filter-gradient'].finding_count, 1);
  assert.equal(byKey['inline-svg-filter-gradient'].fixtures[0], 'coffee');
});

test('gutenberg incompatibility registry ranks tracked custom-block candidates before visual-only evidence', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'tracked-candidate-ranking',
    fixtures: [
      {
        fixture_id: 'artist',
        visual_diff_regions: [{ dominant_cause: 'restyle_geometry', pixel_count: 100000 }],
      },
    ],
    findings: [
      {
        fixture_id: 'artist',
        kind: 'unsupported_html_fallback',
        observed_block_name: 'core/html',
        reason_code: 'html_form_fallback',
        pattern_family: 'interactive_form',
        selector: 'form.newsletter-form',
        source_snippet: '<form class="newsletter-form"><input type="email"><button>Subscribe</button></form>',
      },
    ],
  });

  assert.equal(registry.patterns[0].pattern_key, 'static-form');
  assert.equal(registry.patterns[0].classification, 'convertible');
  assert.equal(registry.patterns[1].pattern_key, 'visual-restyle_geometry');
});

test('gutenberg incompatibility registry artifacts are written with fixture matrix outputs', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-gutenberg-registry-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'gutenberg-registry-artifact-test' });
  const written = writeFixtureMatrixArtifacts({
    outputDirectory,
    matrix,
    result: normalizeFixtureMatrixResult({
      matrix,
      results: [
        {
          fixture_id: 'simple-site',
          status: 'failed',
          diagnostics: [{ kind: 'unsupported_html_fallback', observed: { block_name: 'core/html' }, source: { snippet: '<form><input><button>Send</button></form>' }, reason: 'No core form block.' }],
        },
      ],
    }),
  });

  assert.ok(existsSync(path.join(outputDirectory, 'gutenberg-incompatibility-registry.json')));
  assert.ok(existsSync(path.join(outputDirectory, 'gutenberg-incompatibility-registry.md')));
  assert.ok(written.artifact_refs.some((ref) => ref.artifact_id === 'gutenberg-incompatibility-registry'));
});

test('matrix artifacts use the product base64 encoding for EVERY payload, including text', () => {
  // Guards the smoke-test-theater regression: the matrix must build artifacts
  // with the SAME `content_base64` encoding the real SSI import command
  // emits (static-site-importer.php base64-encodes every file unconditionally).
  // A plain-`content` text payload here means the gate is exercising a path the
  // product never produces — exactly how an empty-style.css bug stayed green.
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'base64-contract' });
  const artifact = buildFixtureArtifact(matrix.fixtures[0]);

  assert.ok(artifact.files.length >= 2);
  for (const file of artifact.files) {
    // Every file carries base64 content and NO plain `content` field, matching
    // the product contract byte-for-byte.
    assert.equal(typeof file.content_base64, 'string', `${file.path} must be base64-encoded`);
    assert.equal(file.content, undefined, `${file.path} must not use a plain content field`);
  }

  // The text CSS payload (the exact class that hid the dropped-inline-CSS bug)
  // round-trips through base64 to its real bytes.
  const cssFile = artifact.files.find((file) => file.path === 'website/style.css');
  assert.ok(cssFile);
  assert.equal(cssFile.type, 'text/css');
  assert.ok(Buffer.from(cssFile.content_base64, 'base64').toString('utf8').includes('.site-shell'));
});

test('builds a generic WP Codebox recipe with SSI-owned plugin defaults', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'recipe-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });

  assert.equal(recipe.schema, 'wp-codebox/workspace-recipe/v1');
  assert.deepEqual(recipe.inputs.extra_plugins[0], {
    source: '/tmp/static-site-importer',
    slug: 'static-site-importer',
    activate: true,
  });
  assert.equal(recipe.workflow.steps[0].command, 'wordpress.wp-cli');
  assert.equal(recipe.workflow.steps[0].args[0], 'command=plugin activate static-site-importer/static-site-importer.php');
  assert.match(recipe.workflow.steps[1].args[0], /static-site-importer prepare-artifact-dependencies/);
  assert.match(recipe.workflow.steps[2].args[0], /static-site-importer validate-artifact/);
  for (const step of [recipe.workflow.steps[1], recipe.workflow.steps[2]]) {
    assert.match(step.args[0], /--client-script-policy=isolated_preview/);
    assert.match(step.args[0], /--client-script-provenance=fixture-matrix:[^ ]+:simple-site/);
    assert.match(step.args[0], /--client-script-isolated/);
  }
  assert.match(recipe.workflow.steps[2].args[0], /--lifecycle-receipt=/);
  assert.match(recipe.workflow.steps[2].args[0], /--format=fixture-matrix/);
  assert.match(recipe.workflow.steps[2].args[0], /--receipt-sidecar=\/wordpress\/wp-content\/uploads\/static-site-importer-fixture-matrix\/simple-site\/materialization-receipt--[A-Za-z0-9-]+\.json/);
  assert.equal(recipe.artifacts.typed[0].path, recipe.workflow.steps[2].args[0].match(/--receipt-sidecar=([^ ]+)/)?.[1]);
  assert.equal(recipe.artifacts.typed[0].payloadSchema, 'static-site-importer/materialization-runtime-sidecar/v2');
  assert.match(recipe.workflow.steps[2].args[0], /--allow-failure/);
  assert.equal(recipe.workflow.steps[3].metadata.phase, 'materialization-sidecar-readback');
  assert.equal(recipe.workflow.steps[3].metadata.attempt_id, recipe.artifacts.typed[0].metadata.attempt_id);
  assert.match(recipe.workflow.steps[3].args[0], /command=eval/);
  assert.deepEqual(recipe.inputs.stagedFiles[0], {
    source: '/tmp/artifacts/simple-site/artifact.json',
    target: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix/simple-site/artifact.json',
  });
  assert.deepEqual(recipe.inputs.mounts, []);
});

test('fixture matrix run config projects an explicit visual viewport into browser recipes', () => {
  const config = normalizeFixtureMatrixRunConfig({
    fixtureRoot: '/tmp/fixtures',
    staticSiteImporterPath: '/tmp/static-site-importer',
    visualParityViewportWidth: 390,
    visualParityViewportHeight: 844,
  });
  const input = fixtureMatrixRecipeInput(config);
  assert.deepEqual(input.visualParityViewport, { width: 390, height: 844 });
  assert.equal(fixtureMatrixHomeboySettings(config).SSI_FIXTURE_MATRIX_VISUAL_PARITY_VIEWPORT_WIDTH, '390');
  assert.equal(fixtureMatrixHomeboySettings(config).SSI_FIXTURE_MATRIX_VISUAL_PARITY_VIEWPORT_HEIGHT, '844');
});

test('fixture matrix selects classic materialization without duplicating the visual parity lane', () => {
  const config = normalizeFixtureMatrixRunConfig({
    fixtureRoot: '/tmp/fixtures',
    staticSiteImporterPath: '/tmp/static-site-importer',
    themeMaterialization: 'classic',
  });
  assert.equal(fixtureMatrixRecipeInput(config).themeMaterialization, 'classic');
  assert.equal(fixtureMatrixHomeboySettings(config).SSI_FIXTURE_MATRIX_THEME_MATERIALIZATION, 'classic');

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  const classicRecipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: '/tmp/artifacts', staticSiteImporterPath: '/tmp/static-site-importer', themeMaterialization: 'classic' });
  const classicImport = classicRecipe.workflow.steps.find((step) => /static-site-importer validate-artifact/.test(step.args?.[0] || ''));
  assert.match(classicImport.args[0], /--theme-materialization=classic(?: |$)/);
  assert.ok(classicRecipe.workflow.steps.some((step) => step.command === 'wordpress.visual-compare'));

  const defaultRecipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: '/tmp/artifacts', staticSiteImporterPath: '/tmp/static-site-importer' });
  const defaultImport = defaultRecipe.workflow.steps.find((step) => /static-site-importer validate-artifact/.test(step.args?.[0] || ''));
  assert.doesNotMatch(defaultImport.args[0], /--theme-materialization=/);
  assert.throws(
    () => normalizeFixtureMatrixRunConfig({ fixtureRoot: '/tmp/fixtures', staticSiteImporterPath: '/tmp/static-site-importer', themeMaterialization: 'hybrid' }),
    /themeMaterialization must be one of: block, classic/,
  );
});

test('configured pixelmatch colour threshold reaches the comparator and the pixel-count gate stays host-side', () => {
  const config = normalizeFixtureMatrixRunConfig({
    fixtureRoot: '/tmp/fixtures',
    staticSiteImporterPath: '/tmp/static-site-importer',
    pixelThreshold: 0,
    visualParityPixelmatchThreshold: 0.01,
  });

  // The colour distance must reach the recipe, because `wordpress.visual-compare`
  // passes its `threshold` option straight into `pixelmatch`. A gate-only
  // projection leaves the comparator on a value nobody configured (#1404).
  const input = fixtureMatrixRecipeInput(config);
  assert.equal(input.visualParityPixelmatchThreshold, 0.01);
  assert.equal(fixtureMatrixGateConfig(config).visualParity.pixelmatchThreshold, 0.01);
  assert.equal(fixtureMatrixGateConfig(config).visualParity.threshold, 0);

  const comparison = visualCompareMatrixComparison(visualParityCompareStep({ fixture: { id: 'shop' }, ...input }));
  assert.equal(comparison.threshold, 0.01, 'the comparator receives the colour distance, not the allowed pixel fraction');

  // The allowed FRACTION of mismatched pixels gates host-side and must never be
  // sent as the comparator's colour distance: at 0 it demands a bit-exact render.
  const zeroFraction = visualCompareMatrixComparison(visualParityCompareStep({ fixture: { id: 'shop' }, pixelThreshold: 0 }));
  assert.equal(zeroFraction.threshold, 0.01);
  assert.notEqual(zeroFraction.threshold, 0);
});

test('runtime presentation evidence persists, merges, and reaches the Blocks Engine compilation input in order', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-presentation-evidence' });
  const defaultRecipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: '/tmp/artifacts', staticSiteImporterPath: '/tmp/static-site-importer' });
  assert.equal(defaultRecipe.workflow.steps.some((step) => step.metadata?.phase === 'runtime-presentation-evidence'), false);

  const recipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: '/tmp/artifacts', staticSiteImporterPath: '/tmp/static-site-importer', runtimePresentationEvidence: true });
  const probeIndex = recipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'runtime-presentation-evidence');
  const mergeIndex = recipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'runtime-presentation-evidence-merge');
  const importIndex = recipe.workflow.steps.findIndex((step) => /static-site-importer validate-artifact/.test(step.args?.[0] || ''));
  const probe = recipe.workflow.steps[probeIndex];
  const merge = recipe.workflow.steps[mergeIndex];
  assert.ok(probeIndex >= 0 && probeIndex < mergeIndex && mergeIndex < importIndex);
  assert.equal(probe.command, 'wordpress.browser-probe');
  assert.ok(probe.args.includes('wait-for=networkidle'));
  assert.ok(probe.args.includes('output-artifact=simple-site/runtime-presentation-evidence.json'));
  const scriptArg = probe.args.find((arg) => arg.startsWith('script='));
  assert.match(scriptArg, /^script=const assets=/);
  assert.match(scriptArg, /; return \{schema:/);
  assert.match(scriptArg, new RegExp(RUNTIME_PRESENTATION_EVIDENCE_SCHEMA));
  assert.match(probe.args.find((arg) => arg.startsWith('script=')), /asset_hash/);
  assert.equal(merge.command, 'wordpress.wp-cli');
  assert.equal(merge.allowFailure, undefined, 'missing or invalid probe output must stop compilation');
  assert.deepEqual(merge.metadata, {
    fixture_id: 'simple-site',
    fixture_path: matrix.fixtures[0].fixture_path,
    phase: 'runtime-presentation-evidence-merge',
    artifact_root: '/tmp/artifacts',
    input_artifacts: ['simple-site/runtime-presentation-evidence.json'],
    artifact: 'simple-site/artifact-with-runtime-presentation-evidence.json',
    evidence_schema: RUNTIME_PRESENTATION_EVIDENCE_SCHEMA,
  });
  assert.match(recipe.workflow.steps[importIndex].args[0], /--artifact=\/tmp\/artifacts\/simple-site\/artifact-with-runtime-presentation-evidence\.json/);

  const playgroundRecipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: '/tmp/artifacts', playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/artifacts', staticSiteImporterPath: '/tmp/static-site-importer', runtimePresentationEvidence: true });
  const playgroundProbe = playgroundRecipe.workflow.steps.find((step) => step.metadata?.phase === 'runtime-presentation-evidence');
  const playgroundMerge = playgroundRecipe.workflow.steps.find((step) => step.metadata?.phase === 'runtime-presentation-evidence-merge');
  const playgroundImport = playgroundRecipe.workflow.steps.find((step) => /static-site-importer validate-artifact/.test(step.args?.[0] || ''));
  assert.ok(playgroundProbe.args.includes('output-artifact=simple-site/runtime-presentation-evidence.json'));
  assert.ok(playgroundProbe.args.includes('output-runtime-path=/wordpress/wp-content/uploads/artifacts/simple-site/runtime-presentation-evidence.json'));
  assert.equal(playgroundProbe.metadata.output_runtime_path, '/wordpress/wp-content/uploads/artifacts/simple-site/runtime-presentation-evidence.json');
  assert.equal(playgroundMerge.metadata.artifact_root, '/wordpress/wp-content/uploads/artifacts');
  assert.match(playgroundImport.args[0], /--artifact=\/wordpress\/wp-content\/uploads\/artifacts\/simple-site\/artifact-with-runtime-presentation-evidence\.json/);
});

test('runtime presentation evidence probes every selected surface before one aggregate merge and import', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-runtime-evidence-surfaces-'));
  try {
    const fixture = path.join(root, 'team-site');
    mkdirSync(path.join(fixture, 'images'), { recursive: true });
    writeFileSync(path.join(fixture, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
    writeFileSync(path.join(fixture, 'index.html'), '<img src="images/home.png">');
    writeFileSync(path.join(fixture, 'team.html'), '<img src="images/team.png">');
    writeFileSync(path.join(fixture, 'images', 'home.png'), 'home');
    writeFileSync(path.join(fixture, 'images', 'team.png'), 'team');
    const matrix = createFixtureMatrix({ fixture_root: root });
    const recipe = buildFixtureMatrixRecipe({
      matrix,
      artifactsDirectory: '/tmp/artifacts',
      playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/artifacts',
      staticSiteImporterPath: '/tmp/static-site-importer',
      runtimePresentationEvidence: true,
      surfaceCoverage: 1,
    });
    const probes = recipe.workflow.steps.filter((step) => step.metadata?.phase === 'runtime-presentation-evidence');
    const mergeIndex = recipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'runtime-presentation-evidence-merge');
    const importIndex = recipe.workflow.steps.findIndex((step) => /static-site-importer validate-artifact/.test(step.args?.[0] || ''));
    assert.deepEqual(probes.map((step) => step.metadata.surface_id), ['front-page', 'team']);
    assert.deepEqual(probes.map((step) => step.metadata.source_path), ['index.html', 'team.html']);
    assert.ok(probes.every((step) => recipe.workflow.steps.indexOf(step) < mergeIndex));
    assert.ok(mergeIndex < importIndex);
    assert.deepEqual(probes.map((step) => step.metadata.output_artifact), [
      'team-site/runtime-presentation-evidence.json',
      'team-site/runtime-presentation-evidence--team.json',
    ]);
    assert.deepEqual(probes.map((step) => step.metadata.output_runtime_path), [
      '/wordpress/wp-content/uploads/artifacts/team-site/runtime-presentation-evidence.json',
      '/wordpress/wp-content/uploads/artifacts/team-site/runtime-presentation-evidence--team.json',
    ]);
    const merge = recipe.workflow.steps[mergeIndex];
    assert.deepEqual(merge.metadata.input_artifacts, probes.map((step) => step.metadata.output_artifact));
    assert.match(probes[1].args.find((arg) => arg.startsWith('script=')), /source_path:'website\/team\.html'/);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('runtime presentation evidence merge retains a Team-like secondary image observation', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-runtime-evidence-merge-'));
  try {
    const fixture = path.join(root, 'team-site');
    mkdirSync(fixture, { recursive: true });
    const provenance = { browser: { name: 'Chromium', version: '126' }, viewport: { width: 1280, height: 1600, device_scale_factor: 1 }, lifecycle: { phase: 'network-idle' } };
    const envelope = (sourcePath, selector) => ({ schema: RUNTIME_PRESENTATION_EVIDENCE_SCHEMA, provenance, observations: [{ element: { source_path: sourcePath, selector }, asset_hash: 'a'.repeat(64), intrinsic: { width: 100, height: 100 }, rendered: { width: 50, height: 50 }, transform: { matrix: [1, 0, 0, 1, 0, 0], origin: { x: 0, y: 0 } }, clip: { x: 0, y: 0, width: 50, height: 50 } }] });
    writeFileSync(path.join(fixture, 'artifact.json'), JSON.stringify({ schema: 'fixture-artifact' }));
    writeFileSync(path.join(fixture, 'runtime-presentation-evidence.json'), JSON.stringify(envelope('website/index.html', 'html > body:nth-of-type(1) > img:nth-of-type(1)')));
    writeFileSync(path.join(fixture, 'runtime-presentation-evidence--team.json'), JSON.stringify(envelope('website/team.html', 'html > body:nth-of-type(1) > img:nth-of-type(1)')));
    const step = runtimePresentationEvidenceMergeStep({
      fixture: { id: 'team-site' },
      artifactRoot: root,
      outputArtifacts: ['team-site/runtime-presentation-evidence.json', 'team-site/runtime-presentation-evidence--team.json'],
    });
    const code = [...step.args[0].matchAll(/([A-Za-z0-9+/=]{100,})/g)]
      .map((match) => Buffer.from(match[1], 'base64').toString('utf8'))
      .find((candidate) => candidate.startsWith('$config ='));
    assert.ok(code, 'merge step must contain executable PHP');
    const result = spawnSync('php', ['-r', `class WP_CLI { public static function line($value) { echo $value; } public static function error($message) { exit(1); } } function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); } ${code}`], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
    const merged = JSON.parse(readFileSync(path.join(fixture, 'artifact-with-runtime-presentation-evidence.json'), 'utf8'));
    assert.deepEqual(merged.runtime_presentation_evidence.observations.map((observation) => observation.element.source_path), ['website/index.html', 'website/team.html']);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('runtime presentation evidence binds staged URLs to artifact paths and payload hashes', () => {
  const bytes = Buffer.from('animated image bytes');
  const contentBase64 = bytes.toString('base64');
  const expectedHash = createHash('sha256').update(contentBase64).digest('hex');
  const probe = runtimePresentationEvidenceProbeStep({
    fixture: { id: 'media-site', entrypoint: 'pages/team.html' },
    sourceUrl: '/wp-content/uploads/fixtures/media-site/source/pages/team.html',
    artifact: { files: [{ path: 'website/images/team.gif', type: 'image/gif', content_base64: contentBase64 }] },
  });
  const script = probe.args.find((arg) => arg.startsWith('script='));
  assert.match(script, new RegExp(expectedHash));
  assert.match(script, /sourceRoot=new URL\("\/wp-content\/uploads\/fixtures\/media-site\/source\/"/);
  assert.match(script, /pathname\.slice\(sourceRoot\.length\)/);
  assert.match(script, /source_path:'website\/pages\/team\.html'/);
  assert.match(script, /n===document\.body\)return ''/);
  assert.match(script, /return prefix\?prefix\+' > '\+part:part/);
  assert.match(script, /rendered:\{x:r\.x,y:r\.y,width:r\.width,height:r\.height\}/);
  assert.match(script, /nth-of-type\('\+\(s\.indexOf\(n\)\+1\)\+'/);
  assert.doesNotMatch(script, /nth-of-type\('\+s\.indexOf\(n\)\+1\+'/);
  assert.match(script, /HeadlessChrome\|Chrome/);
  assert.doesNotMatch(script, /version:navigator\.userAgent/);
});

test('runtime presentation evidence binds images with generic MIME but excludes unrelated binary files', () => {
  const gifBytes = Buffer.from('animated image bytes');
  const avifBytes = Buffer.from('avif payload');
  const zipBytes = Buffer.from('binary payload');
  const gifHash = createHash('sha256').update(gifBytes.toString('base64')).digest('hex');
  const avifHash = createHash('sha256').update(avifBytes.toString('base64')).digest('hex');
  const probe = runtimePresentationEvidenceProbeStep({
    fixture: { id: 'media-site', entrypoint: 'pages/team.html' },
    sourceUrl: '/wp-content/uploads/fixtures/media-site/source/pages/team.html',
    artifact: {
      files: [
        { path: 'website/images/team.gif', type: 'application/octet-stream', content_base64: gifBytes.toString('base64') },
        { path: 'website/images/team.avif', type: 'image/avif', content_base64: avifBytes.toString('base64') },
        { path: 'website/downloads/archive.zip', type: 'application/octet-stream', content_base64: zipBytes.toString('base64') },
      ],
    },
  });
  const script = probe.args.find((arg) => arg.startsWith('script='));
  assert.match(script, new RegExp(`images/team\\.gif":\\"${gifHash}\\"`));
  assert.match(script, new RegExp(`images/team\\.avif":\\"${avifHash}\\"`));
  assert.doesNotMatch(script, /downloads\/archive\.zip/);
});

test('runtime presentation evidence intake preserves a typed envelope and diagnoses an unmerged probe', () => {
  const unavailable = collectRuntimePresentationEvidence([{ command: 'wordpress.browser-probe', metadata: { phase: 'runtime-presentation-evidence' } }]);
  assert.equal(unavailable.evidence, null);
  assert.equal(unavailable.diagnostics[0].kind, RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE);
  assert.match(unavailable.diagnostics[0].message, /not merged into artifact\.json/);

  const envelope = { schema: RUNTIME_PRESENTATION_EVIDENCE_SCHEMA, provenance: { browser: { name: 'Chromium', version: '126' }, viewport: { width: 1280, height: 1600, device_scale_factor: 1 }, lifecycle: { phase: 'network-idle' } }, observations: [] };
  assert.deepEqual(collectRuntimePresentationEvidence([{ runtime_presentation_evidence: envelope }]), { evidence: envelope, diagnostics: [] });
  assert.deepEqual(collectRuntimePresentationEvidence([{ runtime_presentation_evidence: { status: 'invalid', diagnostic: { kind: RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE, code: 'invalid_runtime_presentation_evidence', message: 'Invalid envelope.' } } }]), {
    evidence: null,
    diagnostics: [{ severity: 'warning', loss_class: 'runtime_evidence_unavailable', kind: RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE, code: 'invalid_runtime_presentation_evidence', message: 'Invalid envelope.' }],
  });
});

test('emits provider readiness only for fixture plans that declare requirements', () => {
  const matrix = {
    id: 'provider-readiness-shell-contract',
    fixtures: ['15-saas', '87-travel-tours', '89-static-site-importer-architecture'].map((id) => ({
      id,
      label: id,
      directory: `/fixtures/${id}`,
      entrypoint: 'index.html',
    })),
  };
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
    visualParity: false,
    dependencyPlan: {
      schema: 'static-site-importer/runtime-dependency-plan/v1',
      artifact_sha256: 'a'.repeat(64),
      entries: [{
        source_kind: 'wordpress.org-plugin',
        slug: 'jetpack',
        plugin_entrypoint: 'jetpack/jetpack.php',
        activation: 'required',
        version_policy: 'exact',
        provenance: { provider: 'jetpack' },
        fixture_ids: ['87-travel-tours'],
        provider_readiness: {
          required_block_types: ['jetpack/contact-form', 'jetpack/field-email'],
          required_classes: ['Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form'],
          preparation_callback: ['Static_Site_Importer_Form_Seeder', 'prepare_jetpack_forms_runtime'],
        },
        host_resolution: {
          archive_path: '/tmp/jetpack.zip',
          archive_sha256: 'b'.repeat(64),
          version: '14.0',
        },
      }],
    },
  });
  const step = recipe.workflow.steps.find((candidate) => candidate.metadata?.phase === 'provider-readiness');
  const readinessFixtures = recipe.workflow.steps
    .filter((candidate) => candidate.metadata?.phase === 'provider-readiness')
    .map((candidate) => candidate.metadata.fixture_id);
  const command = step?.args?.[0] || '';
  const decoded = spawnSync('sh', ['-c', `set -- ${command}; printf "%s" "$2"`], { encoding: 'utf8' });

  assert.deepEqual(readinessFixtures, ['87-travel-tours']);
  assert.equal(recipe.workflow.steps.some((candidate) => candidate.metadata?.fixture_id === '15-saas' && candidate.metadata?.phase === 'provider-readiness'), false);
  assert.equal(recipe.workflow.steps.some((candidate) => candidate.metadata?.fixture_id === '89-static-site-importer-architecture' && candidate.metadata?.phase === 'provider-readiness'), false);
  assert.equal(decoded.status, 0, decoded.stderr);
  assert.doesNotMatch(command, /\$missing_blocks/);
  const transportedCode = Buffer.from(decoded.stdout.match(/base64_decode\('([^']+)'\)/)?.[1] || '', 'base64').toString('utf8');
  assert.match(transportedCode, /array_push\(\$missing_blocks, \$name\)/);
  assert.doesNotMatch(transportedCode, /\$missing_blocks\[\]/);
  assert.match(transportedCode, /get_error_code\(\)/);
  assert.match(transportedCode, /'result'=>'wp_error'/);
  assert.match(transportedCode, /array_slice\(array_values\(\$error_data\['missing'\]\), 0, 20\)/);
  const encodedRequirements = [...transportedCode.matchAll(/base64_decode\('([^']+)'\)/g)].map((match) => match[1]);
  assert.deepEqual(JSON.parse(Buffer.from(encodedRequirements[0], 'base64').toString('utf8')), ['jetpack/contact-form', 'jetpack/field-email']);
  assert.deepEqual(JSON.parse(Buffer.from(encodedRequirements[2], 'base64').toString('utf8')), [['Static_Site_Importer_Form_Seeder', 'prepare_jetpack_forms_runtime']]);
  const lint = spawnSync('php', ['-l'], { input: `<?php\n${transportedCode}`, encoding: 'utf8' });
  assert.equal(lint.status, 0, lint.stderr || lint.stdout);
});

test('fixture manifests explicitly opt into unproven dynamic client assets', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-dynamic-client-assets-'));
  try {
    const fixture = path.join(root, 'websites', 'runtime-site');
    mkdirSync(fixture, { recursive: true });
    writeFileSync(path.join(fixture, 'index.html'), '<main>Runtime site</main>');
    writeFileSync(path.join(fixture, 'fixture.json'), JSON.stringify({
      fixture_class: 'marketing/static',
      allow_unproven_dynamic_client_assets: true,
    }));
    const matrix = createFixtureMatrix({ fixture_root: root });
    const recipe = buildFixtureMatrixRecipe({
      matrix,
      artifactsDirectory: '/tmp/artifacts',
      playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
      staticSiteImporterPath: '/tmp/static-site-importer',
    });
    assert.match(recipe.workflow.steps[2].args[0], /--allow-unproven-dynamic-client-assets/);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('matrix import recipes declare the complete required sidecar contract', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'complete-sidecar-contract' });
  const recipe = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', runId: 'run-1', attemptId: 'attempt-1' });
  const command = recipe.workflow.steps[2].args[0];
  for (const flag of ['--receipt-sidecar=', '--receipt-run-id=run-1', '--receipt-step-id=import', '--receipt-attempt-id=attempt-1']) {
    assert.match(command, new RegExp(flag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
});

test('validate-artifact sidecar contract preserves legacy calls and rejects partial arguments', () => {
  const transport = readFileSync(path.join(packageRoot, 'includes/cli.php'), 'utf8');
  const start = transport.indexOf('function static_site_importer_cli_materialization_sidecar_contract');
  const end = transport.indexOf('\n}\n\n/**', start) + 2;
  const contract = transport.slice(start, end);
  const code = `class WP_Error { public $code; function __construct($code) { $this->code = $code; } } function is_wp_error($value) { return $value instanceof WP_Error; } ${contract} $cases = array(static_site_importer_cli_materialization_sidecar_contract(array()), static_site_importer_cli_materialization_sidecar_contract(array('receipt-sidecar' => '/tmp/sidecar.json', 'receipt-run-id' => 'run', 'receipt-step-id' => 'import', 'receipt-attempt-id' => 'attempt')), static_site_importer_cli_materialization_sidecar_contract(array('receipt-sidecar' => '/tmp/sidecar.json'))); echo json_encode(array($cases[0], $cases[1], is_wp_error($cases[2]) ? $cases[2]->code : 'not-error'));`;
  const result = spawnSync('php', ['-r', code], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), [false, true, 'static_site_importer_sidecar_contract_partial']);
});

test('gates visual capture on complete generated SVG font evidence after import', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'svg-font-evidence-recipe-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
    visualParity: false,
    svgFontEvidence: true,
  });
  const step = recipe.workflow.steps.find((candidate) => candidate.metadata?.phase === 'svg-font-evidence');
  const encoded = (step?.args?.[0] || '').match(/([A-Za-z0-9+/=]{100,})/)?.[1] || '';
  const code = Buffer.from(encoded, 'base64').toString('utf8');

  assert.equal(step?.command, 'wordpress.wp-cli');
  assert.match(code, /svg-font-embedding-evidence\/v1/);
  assert.match(code, /expected_font_svg_count/);
  assert.match(code, /has_data_font/);
  assert.match(code, /Required self-contained SVG fonts are missing/);
  assert.doesNotMatch(code, /base64_encode/);
  const lint = spawnSync('php', ['-l'], { input: `<?php\n${code}`, encoding: 'utf8' });
  assert.equal(lint.status, 0, lint.stderr || lint.stdout);
});

test('retains generated SVG font evidence from runtime output', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-svg-font-runtime-evidence-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'svg-font-runtime-evidence-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: {
      fixture_id: 'simple-site',
      status: 'passed',
      svg_font_embedding_evidence: {
        schema: 'static-site-importer/svg-font-embedding-evidence/v1',
        svg_count: 2,
        embedded_font_svg_count: 1,
        files: [{ path: 'assets/map.svg', bytes: 1234, sha256: 'abc', has_font_face: true, has_data_font: true }],
      },
    },
  });

  assert.equal(result.fixtures[0].svg_font_embedding_evidence.svg_count, 2);
  assert.equal(result.fixtures[0].svg_font_embedding_evidence.files[0].has_data_font, true);
});

test('fixture capability metadata does not alter provider setup or execution', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-recipe-capabilities-'));
  const plain = path.join(root, 'plain-site');
  const shop = path.join(root, 'shop-site');
  const shopForms = path.join(root, 'shop-forms-site');
  for (const directory of [plain, shop, shopForms]) {
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), '<h1>Fixture</h1>');
  }
  writeFileSync(path.join(plain, 'fixture.json'), JSON.stringify({ class: 'marketing/static' }));
  writeFileSync(path.join(shop, 'fixture.json'), JSON.stringify({ class: 'ecommerce/catalog', capabilities: ['commerce-products'] }));
  writeFileSync(path.join(shopForms, 'fixture.json'), JSON.stringify({ class: 'ecommerce/catalog', capabilities: ['forms', 'commerce-products'] }));

  const matrix = createFixtureMatrix({ fixture_root: root, id: 'recipe-capability-provisioning-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
    visualParity: false,
  });
  const fixtureSteps = (id) => recipe.workflow.steps.filter((step) => step.metadata?.fixture_id === id);

  const plainSteps = fixtureSteps('plain-site');
  assert.equal(plainSteps.length, 5);
  assert.ok(plainSteps.every((step) => step.command === 'wordpress.wp-cli'));
  assert.deepEqual(fixtureSteps('shop-site').map((step) => step.command), plainSteps.map((step) => step.command));
  assert.deepEqual(fixtureSteps('shop-forms-site').map((step) => step.command), plainSteps.map((step) => step.command));
  assert.equal(recipe.workflow.steps.some((step) => ['wordpress.plugin-setup', 'wordpress.run-php'].includes(step.command)), false);
  assert.deepEqual(recipe.metadata.provider_dependency_setup, []);
});

test('recipe delegates dependency preparation to SSI without host plugin setup', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-provider-recipe-'));
  try {
    const fixture = path.join(root, 'form-site');
    const artifacts = path.join(root, 'artifacts');
    mkdirSync(fixture, { recursive: true });
    mkdirSync(path.join(artifacts, 'form-site'), { recursive: true });
    writeFileSync(path.join(fixture, 'index.html'), '<main>Form</main>');
    writeFileSync(path.join(artifacts, 'form-site', 'artifact.json'), JSON.stringify({ runtime_declarations: [{ kind: 'entity_collection', type: 'forms', adapter_key: 'jetpack_contact_form' }] }));
    const matrix = createFixtureMatrix({ fixture_root: root });
    const recipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: artifacts, staticSiteImporterPath: '/tmp/static-site-importer', editorValidation: false, visualParity: false });
    assert.equal(recipe.workflow.steps.some((step) => step.command === 'wordpress.plugin-setup'), false);
    assert.match(recipe.workflow.steps.find((step) => step.metadata?.fixture_id === 'form-site').args[0], /prepare-artifact-dependencies/);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('fixture recipes leave adapter validation to SSI', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-unknown-provider-recipe-'));
  try {
    const fixture = path.join(root, 'unknown-site');
    const artifacts = path.join(root, 'artifacts');
    mkdirSync(fixture, { recursive: true });
    mkdirSync(path.join(artifacts, 'unknown-site'), { recursive: true });
    writeFileSync(path.join(fixture, 'index.html'), '<main>Unknown</main>');
    writeFileSync(path.join(artifacts, 'unknown-site', 'artifact.json'), JSON.stringify({ runtime_declarations: [{ kind: 'entity_collection', type: 'forms', adapter_key: 'unknown-provider' }] }));
    const matrix = createFixtureMatrix({ fixture_root: root });
    const recipe = buildFixtureMatrixRecipe({ matrix, artifactsDirectory: artifacts, staticSiteImporterPath: '/tmp/static-site-importer' });
    assert.equal(recipe.workflow.steps.some((step) => step.command === 'wordpress.plugin-setup'), false);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('fixture-matrix rig requires env-backed WP Codebox editor and visual capabilities', () => {
  const rig = JSON.parse(readFileSync(path.join(packageRoot, 'rigs', 'static-site-importer-fixture-matrix', 'rig.json'), 'utf8'));
  const tool = rig.requirements.runner_tools.find((item) => item.tool === 'wp-codebox');

  assert.ok(tool, 'expected a wp-codebox runner tool requirement');
  assert.equal(tool.command, 'wp-codebox');
  assert.deepEqual(tool.env, ['HOMEBOY_WP_CODEBOX_BIN']);
  assert.ok(tool.capabilities.includes('wordpress.editor-open'));
  assert.ok(tool.capabilities.includes('wordpress.editor-actions'));
  assert.ok(tool.capabilities.includes('wordpress.editor-validate-blocks'));
  assert.ok(tool.capabilities.includes('wordpress.visual-compare'));
});

test('fixture-matrix rig preflight is declarative and checks hydrated prerequisites deterministically', () => {
  const rig = JSON.parse(readFileSync(path.join(packageRoot, 'rigs', 'static-site-importer-fixture-matrix', 'rig.json'), 'utf8'));
  const checks = rig.pipeline.check;
  const hydrationRemediation = 'Dependencies are missing. Run `homeboy deps install --path /path/to/static-site-importer` so Homeboy hydrates the checkout, then rerun static-site-importer-fixture-matrix.';
  const requiredFiles = [
    'static-site-importer.php',
    'bench/static-site-fixture-matrix.bench.mjs',
    'tools/wp-codebox/recipe.mjs',
    'node_modules/pixelmatch/index.js',
    'node_modules/pngjs/lib/png.js',
    'vendor/autoload.php',
  ];
  const declaredFiles = checks.map((check) => check.file).filter(Boolean).map((file) => file.replace('${components.static-site-importer.path}/', ''));
  const declaredDirectories = checks.map((check) => check.dir).filter(Boolean).map((dir) => dir.replace('${components.static-site-importer.path}/', ''));

  assert.ok(checks.every((check) => check.kind === 'requirement'));
  assert.deepEqual(declaredFiles, requiredFiles);
  assert.deepEqual(declaredDirectories, []);
  assert.ok(checks.some((check) => check.executable === 'node'));
  assert.equal(JSON.stringify(checks).includes('npm ci'), false);
  assert.equal(JSON.stringify(checks).includes('fixture-matrix.test.mjs'), false);
  assert.equal(checks.filter((check) => check.remediation === hydrationRemediation).length, 2);

  const preflightFailures = (root) => checks.flatMap((check) => {
    const declared = check.file || check.dir;
    if (!declared) {
      return [];
    }
    const relativePath = declared.replace('${components.static-site-importer.path}/', '');
    return existsSync(path.join(root, relativePath)) ? [] : [relativePath];
  });

  assert.deepEqual(preflightFailures(packageRoot), []);
  assert.deepEqual(preflightFailures(path.join(packageRoot, 'missing-hydration')), requiredFiles);
});

test('fixture-matrix rig requires executed fixtures before transformer evidence can pass', () => {
  const rig = JSON.parse(readFileSync(path.join(packageRoot, 'rigs', 'static-site-importer-fixture-matrix', 'rig.json'), 'utf8'));

  assert.deepEqual(rig.bench.result_gates.not_run_fixture_count, { lte: 0 });
});

test('fixture-matrix WP Codebox batch runner uses Homeboy declared binary', () => {
  assert.equal(wpCodeboxBin({
    HOMEBOY_WP_CODEBOX_BIN: '/runner/wp-codebox-current',
    WP_CODEBOX_BIN: '/stale/wp-codebox',
  }), '/runner/wp-codebox-current');
  assert.equal(wpCodeboxBin({
    SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN: '/explicit/wp-codebox',
    HOMEBOY_WP_CODEBOX_BIN: '/runner/wp-codebox-current',
  }), '/explicit/wp-codebox');
});

test('builds WP Codebox recipe setup for SSI Composer dependency overrides', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-recipe-dependency-override-'));
  const transformerPath = path.join(root, 'blocks-engine', 'php-transformer');
  mkdirSync(transformerPath, { recursive: true });
  writeFileSync(path.join(transformerPath, 'composer.json'), JSON.stringify({
    name: 'automattic/blocks-engine-php-transformer',
  }));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'recipe-dependency-override-test' });
  const reference = 'a'.repeat(40);

  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    dependencyOverrides: {
      blocks_engine_php_transformer: {
        package: 'automattic/blocks-engine-php-transformer',
        path: transformerPath,
        reference,
      },
    },
  });

  assert.deepEqual(recipe.inputs.dependency_overlays[0], {
    kind: 'composer-package',
    package: 'automattic/blocks-engine-php-transformer',
    consumer: 'static-site-importer',
    source: transformerPath,
    reference,
  });
  assert.equal(recipe.inputs.mounts.length, 0);
  assert.equal(recipe.workflow.steps[0].args[0], 'command=plugin activate static-site-importer/static-site-importer.php');
  assert.equal(recipe.metadata.surface_coverage.enabled, false);
  assert.deepEqual(recipe.metadata.runtime_cost_warnings, []);
});

test('fails recipe generation for invalid SSI dependency override paths', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-invalid-dependency-override-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'recipe-invalid-dependency-override-test' });

  assert.throws(() => buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    dependencyOverrides: {
      blocks_engine_php_transformer: {
        package: 'automattic/blocks-engine-php-transformer',
        path: root,
      },
    },
  }), /composer\.json not found/);

  writeFileSync(path.join(root, 'composer.json'), JSON.stringify({
    name: 'automattic/blocks-engine-php-transformer',
  }));
  assert.throws(() => buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    dependencyOverrides: {
      blocks_engine_php_transformer: {
        path: root,
        reference: 'not-an-immutable-reference',
      },
    },
  }), /reference must be a 40-64 character hexadecimal immutable reference/);
});

test('normalizes SSI diagnostics into product repair groups', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'diagnostic-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          { message: 'Dropped image asset during import' },
          { message: 'Unexpected or invalid content in imported block' },
        ],
      },
    ],
  });

  assert.equal(result.summary.failed, 1);
  assert.equal(result.summary.groups.dropped_images, 1);
  assert.equal(result.summary.groups.invalid_block_content, 1);
  assert.equal(classifyStaticSiteFinding({ message: 'canvas target missing' }).repair_mode, 'runtime-dom-target-parity');
});

test('gates fixture matrix failures by unacceptable loss classes', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'loss-class-gate-test' });
  const acceptableResult = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'runtime_dependency_missing_dom_target',
            loss_class: 'preserved_runtime_island',
            runtime_carried: true,
            source_path: 'website/index.html',
            selector: '#hero canvas',
            message: 'Runtime island preserved for editor-safe import.',
          },
          {
            kind: 'html_canvas_runtime_fallback',
            loss_class: 'preserved_runtime_island',
            runtime_carried: true,
            source_path: 'website/index.html',
            selector: '#hero canvas',
            message: 'Blocks Engine reported the same preserved runtime island.',
          },
        ],
      },
    ],
  });

  assert.equal(acceptableResult.summary.succeeded, 1);
  assert.equal(acceptableResult.summary.failed, 0);
  assert.equal(acceptableResult.summary.acceptable_finding_count, 1);
  assert.equal(acceptableResult.summary.unacceptable_finding_count, 0);
  assert.equal(acceptableResult.summary.preserved_runtime_island_count, 1);
  assert.equal(acceptableResult.findings.length, 1);
  assert.equal(acceptableResult.fixtures[0].raw_status, 'failed');
  assert.equal(acceptableResult.fixtures[0].status, 'passed');
  assert.equal(acceptableResult.fixtures[0].quality_gate.loss_classes.preserved_runtime_island, 1);

  const unacceptableResult = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
      },
    ],
  });

  assert.equal(unacceptableResult.summary.failed, 1);
  assert.equal(unacceptableResult.summary.unacceptable_finding_count, 1);
  assert.equal(unacceptableResult.summary.unacceptable_loss_classes.fixture_failed, 1);
});

test('failed fixtures with passing import/editor quality report missing visual evidence instead of a generic fallback', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'missing-visual-evidence-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        quality_metrics: {
          pass: true,
          editor_invalid_count: 0,
          invalid_block_count: 0,
        },
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(result.summary.failed, 1);
  assert.equal(finding.kind, 'visual_evidence_missing');
  assert.equal(finding.loss_class, 'visual_evidence_missing');
  assert.match(finding.reason, /import quality and editor validity passed/);
  assert.equal(result.summary.unacceptable_loss_classes.visual_evidence_missing, 1);
  assert.equal(result.summary.top_pattern_families[0].key, 'visual_evidence_missing:visual_evidence_missing:(none)');
});

test('failed fixtures with passing import/editor quality and visual evidence report fixture status mismatch', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'fixture-status-mismatch-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        quality_metrics: {
          pass: true,
          editor_invalid_count: 0,
          invalid_block_count: 0,
        },
        visual_parity_artifacts: {
          schema: 'static-site-importer/visual-parity-artifacts/v1',
          metrics: { mismatch_pixels: 0, total_pixels: 2048000 },
          artifacts: { diff_screenshot: { status: 'captured' } },
        },
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(result.summary.failed, 1);
  assert.equal(finding.kind, 'fixture_status_mismatch');
  assert.equal(finding.loss_class, 'fixture_status_mismatch');
  assert.match(finding.reason, /no structured visual-parity mismatch/);
  assert.equal(result.summary.unacceptable_loss_classes.fixture_status_mismatch, 1);
  assert.equal(result.summary.top_pattern_families[0].key, 'fixture_status_mismatch:fixture_status_mismatch:(none)');
});

test('runtime command telemetry does not become a fixture diagnostic', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-matrix-telemetry-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'telemetry-diagnostic-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: {
      fixture_id: 'simple-site',
      status: 'passed',
      diagnostics: [
        {
          command: 'wordpress.visual-compare',
          timing: {
            startedAt: '2026-07-03T12:37:44.617Z',
            finishedAt: '2026-07-03T12:37:46.251Z',
            durationMs: 1634,
          },
        },
      ],
      quality_metrics: {
        pass: true,
        invalid_block_count: 0,
      },
      editor_validation: {
        validation_method: EDITOR_VALIDATION_METHOD,
        total_blocks: 1,
        valid_blocks: 1,
        invalid_blocks: 0,
      },
    },
  });

  assert.equal(result.summary.failed, 0);
  assert.equal(result.findings.length, 0);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('fixture matrix captures installed transformer provenance and bounded materialization-plan asset evidence', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-matrix-runtime-evidence-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-evidence-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: {
      fixture_id: 'simple-site',
      status: 'passed',
      import_report: {
        materialization_receipt: {
          schema: 'static-site-importer/materialization-receipt/v1',
          status: 'completed',
          plan_hash: 'plan-hash',
          completed: { pages: { 'index.html': 1 }, files: [], operations: [], declaration_ids: [] },
        },
        generated_theme: {
          template_parts: [{
            path: 'parts/header.html',
            origin: 'source_files.landmark',
            source_paths: ['website/index.html#header'],
            block_markup_hash: 'header123',
            block_markup_bytes: 512,
            block_names: ['core/group', 'core/navigation'],
            contains_core_html: false,
            control_marker_count: 2,
          }],
        },
        blocks_engine: {
          transformer: {
            package: 'automattic/blocks-engine-php-transformer',
            version: '0.2.6',
            reference: '908c76a8d9b7679c87f59aa183f09b12ea9f89e6',
            source_fingerprint: 'sha256:source123',
          },
          wordpress_site_plan: {
            schema: 'blocks-engine/wordpress-site-plan/v2',
            assets: [{
              path: 'assets/site.css',
              source: 'website/index.html',
              role: 'stylesheet',
              kind: 'css',
              media_type: 'text/css',
              placement: 'head',
              payload_present: true,
              payload_sha256: 'abc123',
              payload_bytes: 42,
            }, {
              path: 'assets/app.js',
              source: 'website/index.html',
              role: 'runtime',
              kind: 'script',
              mime_type: 'application/javascript',
              placement: 'footer',
              defer: true,
              async: false,
              payload_present: true,
              payload_sha256: 'def456',
              payload_bytes: 84,
            }],
          },
        },
      },
    },
  });

  const evidence = result.fixtures[0].matrix_evidence;
  assert.equal(evidence.readiness, 'verified');
  assert.deepEqual(evidence.transformer, {
    package: 'automattic/blocks-engine-php-transformer',
    version: '0.2.6',
    reference: 'sha256:source123',
    package_reference: '908c76a8d9b7679c87f59aa183f09b12ea9f89e6',
    source_fingerprint: 'sha256:source123',
  });
  assert.deepEqual(evidence.wordpress_site_plan.assets[0], {
    path: 'assets/app.js', source: 'website/index.html', role: 'runtime', kind: 'script', type: 'application/javascript', placement: 'footer', defer: true, async: false, payload_present: true, payload_sha256: 'def456', payload_bytes: 84,
  });
  assert.equal(evidence.wordpress_site_plan.assets[1].payload_sha256, 'abc123');
  assert.deepEqual(evidence.template_parts[0], {
    path: 'parts/header.html', origin: 'source_files.landmark', source_paths: ['website/index.html#header'], block_markup_hash: 'header123', block_markup_bytes: 512, block_names: ['core/group', 'core/navigation'], contains_core_html: false, control_marker_count: 2,
  });
  assert.equal(result.summary.matrix_evidence_readiness.status, 'verified');
});

test('fixture matrix labels reports without runtime provenance and materialization evidence as legacy', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-matrix-legacy-evidence-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'legacy-runtime-evidence-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: { fixture_id: 'simple-site', status: 'passed', import_report: { blocks_engine: { available: true } } },
  });

  assert.equal(result.fixtures[0].matrix_evidence.readiness, 'runtime_evidence_incomplete');
  assert.deepEqual(result.fixtures[0].matrix_evidence.missing, ['transformer_package', 'transformer_version', 'transformer_reference', 'wordpress_site_plan', 'materialization_receipt']);
  assert.equal(result.summary.matrix_evidence_readiness.status, 'incomplete');
  assert.equal(result.summary.matrix_evidence_readiness.counts.runtime_evidence_incomplete, 1);
});

test('materialization sidecars retain bounded evidence after oversized import stdout', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-oversized-output-'));
  const base = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-run' });
  const matrix = { ...base, fixtures: [{ ...base.fixtures[0] }, { ...base.fixtures[0], id: 'second-site' }] };
  for (const fixture of matrix.fixtures) {
    const directory = path.join(outputDirectory, fixture.id);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: fixture.id }));
    writeMaterializationSidecar({ directory, fixtureId: fixture.id, runId: matrix.id, receipt: boundedSidecarReceipt() });
  }
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: { executions: matrix.fixtures.map((fixture) => ({ metadata: { fixture_id: fixture.id }, stdout: 'x'.repeat(1024 * 1024 + 1) })) },
  });

  for (const fixture of result.fixtures) {
    assert.equal(fixture.matrix_evidence.materialization_sidecar.status, 'verified');
    assert.equal(fixture.matrix_evidence.materialization_receipt.operation_count, 99);
    assert.equal(fixture.matrix_evidence.materialization_receipt.page_count, 2);
    assert.deepEqual(fixture.matrix_evidence.materialization_sidecar.computed_layout_totals, { applied: 7, losses: 2, operations: 9 });
    assert.deepEqual(fixture.matrix_evidence.materialization_sidecar.provider_totals, { completed: 1 });
    assert.ok(fixture.artifact_refs.some((ref) => ref.artifact_id === 'materialization-receipt--primary'));
  }
});

test('materialization sidecars survive WP Codebox typed artifact transport', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-typed-artifact-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'typed-sidecar-run' });
  const directory = path.join(outputDirectory, 'simple-site');
  mkdirSync(directory, { recursive: true });
  writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
  const sidecar = writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, receipt: boundedSidecarReceipt() });
  rmSync(path.join(directory, 'materialization-receipt--primary.json'));

  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: {
      declaredArtifacts: [{
        schema: 'wp-codebox/recipe-declared-artifact-result/v1',
        status: 'collected',
        path: '/wordpress/wp-content/uploads/materialization-receipt--primary.json',
        parsedJson: sidecar,
      }],
    },
  });

  assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'verified');
  assert.equal(result.fixtures[0].matrix_evidence.materialization_receipt.status, 'completed');
  assert.equal(result.fixtures[0].matrix_evidence.materialization_receipt.operation_count, 99);
});

test('materialization sidecars preserve v2 plan identity through typed artifact transport', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-v2-identity-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'v2-identity-sidecar-run' });
  const directory = path.join(outputDirectory, 'simple-site');
  mkdirSync(directory, { recursive: true });
  writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
  const receipt = {
    ...boundedSidecarReceipt(),
    schema: 'static-site-importer/materialization-receipt/v2',
    plan_identity: { schema: 'blocks-engine/wordpress-site-plan-identity/v1', hash: 'c'.repeat(64) },
  };
  delete receipt.plan_hash;
  const sidecar = writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, receipt, schema: 'static-site-importer/materialization-runtime-sidecar/v2' });
  rmSync(path.join(directory, 'materialization-receipt--primary.json'));

  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: { declaredArtifacts: [{ schema: 'wp-codebox/recipe-declared-artifact-result/v1', status: 'collected', path: '/wordpress/wp-content/uploads/materialization-receipt--primary.json', parsedJson: sidecar }] },
  });

  const evidence = result.fixtures[0].matrix_evidence;
  assert.equal(evidence.missing.includes('materialization_receipt'), false);
  assert.deepEqual(evidence.materialization_receipt.plan_identity, { schema: 'blocks-engine/wordpress-site-plan-identity/v1', hash: 'c'.repeat(64) });
  assert.equal(evidence.materialization_receipt.plan_hash, 'c'.repeat(64));
});

test('failure sidecars retain the bounded import result and front-page option observation', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-failed-import-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'failed-import-evidence' });
  const directory = path.join(outputDirectory, 'simple-site');
  mkdirSync(directory, { recursive: true });
  const artifact = JSON.stringify({ fixture: 'simple-site' });
  writeFileSync(path.join(directory, 'artifact.json'), artifact);
  const sidecar = {
    schema: 'static-site-importer/materialization-runtime-sidecar/v1', fixture_id: 'simple-site', run_id: matrix.id, step_id: 'import', attempt_id: 'primary', artifact_sha256: createHash('sha256').update(artifact).digest('hex'), provenance: { provider: 'static-site-importer/current-runtime', provider_status: 'failed' }, durability: { file_fsync: 'available', directory_fsync: 'attempted' },
    receipt: { schema: 'static-site-importer/materialization-receipt/v1', status: 'failed', page_count: 0, file_count: 0, operation_count: 0, loss_count: 1, failure_code: 'artifact_invalid' },
    command_result: { status: 'failed', success: false, error_code: 'artifact_invalid', error_hash: 'a'.repeat(64) },
    front_page_options: { show_on_front: 'posts', page_on_front: 0 },
  };
  sidecar.content_sha256 = createHash('sha256').update(JSON.stringify(sidecar)).digest('hex');
  writeFileSync(path.join(directory, 'materialization-receipt--primary.json'), JSON.stringify(sidecar));
  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'verified');
  assert.deepEqual(result.fixtures[0].matrix_evidence.import_command, { status: 'failed', success: false, error_code: 'artifact_invalid', error_hash: 'a'.repeat(64) });
  assert.deepEqual(result.fixtures[0].matrix_evidence.front_page_options, { show_on_front: 'posts', page_on_front: 0 });
  assert.equal(result.fixtures[0].matrix_evidence.materialization_receipt.status, 'failed');
});

test('materialization sidecars reject malformed, stale, cross-fixture, and hash-mismatched evidence', () => {
  const cases = ['missing', 'malformed', 'stale', 'cross_fixture', 'hash_mismatch'];
  for (const status of cases) {
    const outputDirectory = mkdtempSync(path.join(tmpdir(), `ssi-sidecar-${status}-`));
    const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-validation-run' });
    const directory = path.join(outputDirectory, 'simple-site');
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
    if (status === 'malformed') writeFileSync(path.join(directory, 'materialization-receipt--primary.json'), '{');
    if (status === 'stale') writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: 'old-run', receipt: boundedSidecarReceipt() });
    if (status === 'cross_fixture') writeMaterializationSidecar({ directory, fixtureId: 'other-site', runId: matrix.id, receipt: boundedSidecarReceipt() });
    if (status === 'hash_mismatch') writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, receipt: boundedSidecarReceipt(), artifactHash: '0'.repeat(64) });
    const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
    assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, status === 'missing' ? 'missing' : status, status);
  }
});

test('materialization sidecars isolate concurrent attempts for the same fixture', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-concurrent-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-concurrent-run' });
  const directory = path.join(outputDirectory, 'simple-site');
  mkdirSync(directory, { recursive: true });
  writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
  writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, attemptId: 'attempt-a', receipt: { ...boundedSidecarReceipt(), operation_count: 1 } });
  writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, attemptId: 'attempt-b', receipt: { ...boundedSidecarReceipt(), operation_count: 2 } });

  const first = collectFixtureMatrixRunResults({ matrix, outputDirectory, sidecarAttemptId: 'attempt-a' });
  const second = collectFixtureMatrixRunResults({ matrix, outputDirectory, sidecarAttemptId: 'attempt-b' });
  assert.equal(first.fixtures[0].matrix_evidence.materialization_receipt.operation_count, 1);
  assert.equal(second.fixtures[0].matrix_evidence.materialization_receipt.operation_count, 2);
});

test('typed WP Codebox sidecar export materializes into the host intake path', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-host-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-codebox-artifacts-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-export-run' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  const guestDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', 'files', 'runtime-evidence', 'typed-artifacts', 'guest-simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  mkdirSync(guestDirectory, { recursive: true });
  const artifact = JSON.stringify({ fixture: 'simple-site' });
  writeFileSync(path.join(fixtureDirectory, 'artifact.json'), artifact);
  writeFileSync(path.join(guestDirectory, 'artifact.json'), artifact);
  // This is the complete row emitted by static_site_importer_cli_materialized_documents().
  const documents = [{ source_path: 'index.html', route: '/', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) }];
  writeMaterializationSidecar({ directory: guestDirectory, fixtureId: 'simple-site', runId: matrix.id, attemptId: 'batch-001', receipt: boundedSidecarReceipt(), fileName: 'exported-sidecar.json', schema: 'static-site-importer/materialization-runtime-sidecar/v2', documents, documentsTruncated: false, documentsTotal: 1 });

  const exports = materializeMaterializationSidecars({ fixtures: matrix.fixtures, outputDirectory, codeboxArtifactsDirectory, attemptId: 'batch-001' });
  const expected = path.join(fixtureDirectory, 'materialization-receipt--batch-001.json');
  assert.deepEqual(exports.map((entry) => entry.fixture_id), ['simple-site']);
  assert.equal(existsSync(expected), true);
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    sidecarAttemptId: 'batch-001',
    codeboxOutput: { fixture_id: 'simple-site', surface_id: 'front-page', command: 'wordpress.editor-open', status: 'completed', post_id: '42', post_type: 'page', post_slug: 'home' },
  });
  assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'verified');
  assert.deepEqual(result.fixtures[0].matrix_evidence.materialization_sidecar.documents, documents);
  assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.documents_total, 1);
  assert.deepEqual(result.fixtures[0].surface_lineage.find((surface) => surface.surface.id === 'front-page').materialized_document, { status: 'available', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) });
});

test('bounded WP Codebox stdout sidecar readback reaches host intake without artifact projection', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-stdout-readback-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-stdout-run' });
  const directory = path.join(outputDirectory, 'simple-site');
  mkdirSync(directory, { recursive: true });
  writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
  const sidecar = writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, attemptId: 'batch-001', receipt: boundedSidecarReceipt() });
  unlinkSync(path.join(directory, 'materialization-receipt--batch-001.json'));

  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    sidecarAttemptId: 'batch-001',
    codeboxOutput: { executions: [{ metadata: { fixture_id: 'simple-site', phase: 'materialization-sidecar-readback' }, stdout: JSON.stringify(sidecar) }] },
  });
  assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'verified');
  assert.equal(result.fixtures[0].matrix_evidence.materialization_receipt.status, 'completed');
  assert.equal(result.fixtures[0].matrix_evidence.missing.includes('materialization_receipt'), false);
});

test('persisted v1 and four-field v2 sidecars remain compatible while truncated documents make absent identities indeterminate', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-v1-v2-lineage-'));
  const base = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-v1-v2-lineage' });
  const matrix = { ...base, fixtures: [{ ...base.fixtures[0] }, { ...base.fixtures[0], id: 'truncated-site' }, { ...base.fixtures[0], id: 'missing-site' }] };
  for (const fixture of matrix.fixtures) {
    const directory = path.join(outputDirectory, fixture.id);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: fixture.id }));
  }
  writeMaterializationSidecar({ directory: path.join(outputDirectory, 'simple-site'), fixtureId: 'simple-site', runId: matrix.id, receipt: boundedSidecarReceipt() });
  writeMaterializationSidecar({ directory: path.join(outputDirectory, 'truncated-site'), fixtureId: 'truncated-site', runId: matrix.id, receipt: boundedSidecarReceipt(), schema: 'static-site-importer/materialization-runtime-sidecar/v2', documents: [{ post_id: '1', post_type: 'page', post_slug: 'first', serialized_content_sha256: 'd'.repeat(64) }], documentsTruncated: true, documentsTotal: 26 });
  writeMaterializationSidecar({ directory: path.join(outputDirectory, 'missing-site'), fixtureId: 'missing-site', runId: matrix.id, receipt: boundedSidecarReceipt(), schema: 'static-site-importer/materialization-runtime-sidecar/v2', documents: [], documentsTruncated: false, documentsTotal: 0 });

  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: { executions: matrix.fixtures.map((fixture) => ({ fixture_id: fixture.id, surface_id: 'front-page', command: 'wordpress.editor-open', status: fixture.id === 'missing-site' ? 'disabled' : 'completed', post_id: fixture.id === 'simple-site' ? '1' : '42' })) },
  });
  const byFixture = new Map(result.fixtures.map((fixture) => [fixture.fixture_id, fixture]));
  assert.equal(byFixture.get('simple-site').matrix_evidence.materialization_sidecar.schema, 'static-site-importer/materialization-runtime-sidecar/v1');
  assert.equal(byFixture.get('truncated-site').matrix_evidence.materialization_sidecar.documents_truncated, true);
  assert.deepEqual(byFixture.get('truncated-site').surface_lineage.find((surface) => surface.surface.id === 'front-page').materialized_document, { status: 'indeterminate', post_id: '42', truncated: true, documents_total: 26 });
  assert.deepEqual(byFixture.get('missing-site').surface_lineage.find((surface) => surface.surface.id === 'front-page').materialized_document, { status: 'missing', post_id: '42' });
  assert.equal(byFixture.get('missing-site').surface_lineage.find((surface) => surface.surface.id === 'front-page').lanes.editor.status, 'disabled');
});

test('materialization sidecars reject malformed canonical and mixed document rows', () => {
  const invalidDocuments = [
    ['empty-source-path', { source_path: '', route: '/', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) }],
    ['oversized-source-path', { source_path: `website/${'x'.repeat(493)}`, route: '/', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) }],
    ['control-character-route', { source_path: 'index.html', route: '/\n', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) }],
    ['relative-route', { source_path: 'index.html', route: 'home', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) }],
    ['mixed-row', { source_path: 'index.html', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'c'.repeat(64) }],
  ];
  for (const [name, document] of invalidDocuments) {
    const outputDirectory = mkdtempSync(path.join(tmpdir(), `ssi-sidecar-invalid-document-${name}-`));
    const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-invalid-document-run' });
    const directory = path.join(outputDirectory, 'simple-site');
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
    writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, receipt: boundedSidecarReceipt(), schema: 'static-site-importer/materialization-runtime-sidecar/v2', documents: [document], documentsTruncated: false, documentsTotal: 1 });

    const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
    assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'malformed', name);
  }
});

test('invalid writer-skipped lineage rows do not make a complete sidecar indeterminate', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-sidecar-skipped-lineage-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-skipped-lineage-run' });
  const directory = path.join(outputDirectory, 'simple-site');
  mkdirSync(directory, { recursive: true });
  writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
  // The PHP writer excludes an invalid source/route candidate before calculating
  // documents_total and documents_truncated.
  writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, receipt: boundedSidecarReceipt(), schema: 'static-site-importer/materialization-runtime-sidecar/v2', documents: [], documentsTruncated: false, documentsTotal: 0 });

  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: { fixture_id: 'simple-site', surface_id: 'front-page', command: 'wordpress.editor-open', status: 'completed', post_id: '42' },
  });
  const fixture = result.fixtures[0];
  assert.equal(fixture.matrix_evidence.materialization_sidecar.status, 'verified');
  assert.deepEqual(fixture.surface_lineage.find((surface) => surface.surface.id === 'front-page').materialized_document, { status: 'missing', post_id: '42' });
});

test('direct recipe builders generate distinct run and attempt identities', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'direct-recipe' });
  const first = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi' });
  const second = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi' });
  assert.notEqual(first.workflow.steps[1].args[0], second.workflow.steps[1].args[0]);
  assert.notEqual(first.artifacts.typed[0].path, second.artifacts.typed[0].path);
});

test('materialization sidecars reject partial, oversized, and semantically invalid content', () => {
  const invalid = [
    ['partial', (sidecar) => '{'],
    ['oversized', (sidecar) => JSON.stringify({ ...sidecar, padding: 'x'.repeat(32 * 1024) })],
    ['wrong-sidecar-schema', (sidecar) => JSON.stringify({ ...sidecar, schema: 'wrong/v1' })],
    ['wrong-receipt-schema', (sidecar) => JSON.stringify({ ...sidecar, receipt: { ...sidecar.receipt, schema: 'wrong/v1' } })],
    ['wrong-receipt-status', (sidecar) => JSON.stringify({ ...sidecar, receipt: { ...sidecar.receipt, status: 'partial' } })],
    ['raw-string', (sidecar) => JSON.stringify({ ...sidecar, receipt: { ...sidecar.receipt, operation_rows: [{ kind: '<script>\nraw</script>', hash: 'a'.repeat(64) }] } })],
  ];
  for (const [name, encode] of invalid) {
    const outputDirectory = mkdtempSync(path.join(tmpdir(), `ssi-sidecar-invalid-${name}-`));
    const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'sidecar-invalid-run' });
    const directory = path.join(outputDirectory, 'simple-site');
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'artifact.json'), JSON.stringify({ fixture: 'simple-site' }));
    writeMaterializationSidecar({ directory, fixtureId: 'simple-site', runId: matrix.id, receipt: boundedSidecarReceipt() });
    const sidecarPath = path.join(directory, 'materialization-receipt--primary.json');
    const sidecar = JSON.parse(readFileSync(sidecarPath, 'utf8'));
    writeFileSync(sidecarPath, encode(sidecar));
    const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
    assert.equal(result.fixtures[0].matrix_evidence.materialization_sidecar.status, 'malformed', name);
  }
});

function boundedSidecarReceipt() {
  return {
    schema: 'static-site-importer/materialization-receipt/v1', status: 'completed', plan_hash: 'a'.repeat(64), page_count: 2, file_count: 4, operation_count: 99, loss_count: 3,
    provider_totals: { completed: 1 }, computed_layout_totals: { applied: 7, losses: 2, operations: 9 }, operation_rows: [{ kind: 'computed_layout', status: 'completed', hash: 'a'.repeat(64) }], loss_rows: [{ kind: 'computed_layout_loss', reason_code: 'missing_measurement', hash: 'b'.repeat(64) }], truncated: { operation_rows: true, loss_rows: false },
  };
}

function writeMaterializationSidecar({ directory, fixtureId, runId, receipt, artifactHash, attemptId = 'primary', fileName, schema = 'static-site-importer/materialization-runtime-sidecar/v1', documents, documentsTruncated, documentsTotal }) {
  const artifact = readFileSync(path.join(directory, 'artifact.json'));
  const sidecar = {
    schema, fixture_id: fixtureId, run_id: runId, step_id: 'import', attempt_id: attemptId, artifact_sha256: artifactHash || createHash('sha256').update(artifact).digest('hex'), provenance: { provider: 'static-site-importer/current-runtime', provider_status: 'completed' }, durability: { file_fsync: 'available', directory_fsync: 'attempted' }, receipt,
  };
  if (schema === 'static-site-importer/materialization-runtime-sidecar/v2') {
    sidecar.documents = documents || [];
    sidecar.documents_truncated = Boolean(documentsTruncated);
    sidecar.documents_total = documentsTotal ?? sidecar.documents.length;
  }
  sidecar.content_sha256 = createHash('sha256').update(JSON.stringify(sidecar)).digest('hex');
  writeFileSync(path.join(directory, fileName || `materialization-receipt--${attemptId}.json`), JSON.stringify(sidecar));
  return sidecar;
}

test('fixture attribution assigns a transform loss only with complete transformer lineage', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'transform-attribution-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: verifiedMatrixEvidence(),
      diagnostics: [{ kind: 'missing_output', attribution_boundary: 'transform', message: 'Transformer omitted a source block.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, 'blocks-engine');
  assert.equal(result.findings[0].attribution_candidates.find((candidate) => candidate.boundary === 'transform').supported, true);
  assert.equal(result.findings[0].diagnostic_blind_spots, undefined);
});

test('fixture attribution assigns adapter loss only with correlated failed provider evidence', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'provider-attribution-test' });
  const evidence = verifiedMatrixEvidence();
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: evidence,
      diagnostics: [{ kind: 'recipe_step_failure', run_id: 'provider-run', attribution_boundary: 'provider', attribution_evidence: { correlation: { run_id: 'provider-run' }, provider_adapter: { schema: 'static-site-importer/provider-adapter/v1', status: 'failed', provider: 'test-adapter' } }, message: 'Provider adapter dropped the import result.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, 'static-site-importer');
  assert.equal(result.findings[0].attribution_candidates.find((candidate) => candidate.boundary === 'provider').supported, true);
});

test('fixture attribution keeps capture-only mismatches distinct from transform ownership', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'capture-attribution-test' });
  const evidence = verifiedMatrixEvidence();
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: evidence,
      diagnostics: [{ kind: 'visual_parity_mismatch', run_id: 'capture-run', attribution_boundary: 'capture', attribution_evidence: { correlation: { run_id: 'capture-run' }, capture: { schema: 'wp-codebox/visual-capture/v1', status: 'failed', source: 'source.png', candidate: 'candidate.png' } }, message: 'Capture contract mismatched source and candidate screenshots.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, 'static-site-importer');
  assert.equal(result.findings[0].attribution_candidates.find((candidate) => candidate.boundary === 'transform').supported, false);
});

test('versioned fixture evidence rejects uncorrelated direct provider evidence', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'strict-direct-provider-correlation-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: { schema: 'static-site-importer/fixture-matrix-runtime-evidence/v1', readiness: 'verified', missing: [] },
      diagnostics: [{ kind: 'recipe_step_failure', attribution_boundary: 'provider', attribution_evidence: { provider_adapter: { status: 'failed' } }, message: 'Uncorrelated direct provider evidence.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, '');
  assert.deepEqual(result.findings[0].diagnostic_blind_spots, ['provider_adapter_correlation']);
});

test('unversioned callers retain explicit provider ownership without correlation evidence', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'legacy-direct-provider-correlation-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      diagnostics: [{ kind: 'recipe_step_failure', attribution_boundary: 'provider', candidate_repo: 'static-site-importer', attribution_evidence: { provider_adapter: { status: 'failed' } }, message: 'Legacy provider ownership.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, 'static-site-importer');
});

test('fixture attribution records missing lineage as a blind spot instead of defaulting to Blocks Engine', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'missing-lineage-attribution-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: { schema: 'static-site-importer/fixture-matrix-runtime-evidence/v1', readiness: 'runtime_evidence_incomplete', missing: ['transformer_reference', 'wordpress_site_plan', 'materialization_receipt'] },
      diagnostics: [{ kind: 'visual_parity_mismatch', message: 'Visual mismatch has no lineage.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, '');
  assert.deepEqual(result.findings[0].diagnostic_blind_spots, ['attribution_boundary']);
  assert.ok(result.summary.diagnostic_blind_spots.some((spot) => spot.kind === 'missing_required_lineage'));
});

test('materialization attribution reports missing transformer provenance as a boundary blind spot', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'materialization-lineage-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: { schema: 'static-site-importer/fixture-matrix-runtime-evidence/v1', readiness: 'runtime_evidence_incomplete', missing: ['transformer_package', 'transformer_version', 'transformer_reference'] },
      diagnostics: [{ kind: 'missing_asset', attribution_boundary: 'materialization', candidate_repo: 'static-site-importer', message: 'Materialization omitted a stylesheet.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, '');
  assert.deepEqual(result.findings[0].diagnostic_blind_spots, ['transformer_package', 'transformer_reference', 'transformer_version']);
});

test('unversioned fixture results retain caller-supplied ownership when attribution evidence is absent', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'legacy-owner-compatibility-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      diagnostics: [{ kind: 'layout_shift', candidate_repo: 'blocks-engine', message: 'Legacy caller ownership.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, 'blocks-engine');
});

test('versioned fixture evidence replaces an unproven caller-supplied owner', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'strict-owner-compatibility-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: { schema: 'static-site-importer/fixture-matrix-runtime-evidence/v1', readiness: 'runtime_evidence_incomplete', missing: ['transformer_reference'] },
      diagnostics: [{ kind: 'layout_shift', candidate_repo: 'blocks-engine', message: 'Strict contract lacks ownership evidence.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, '');
});

test('fixture lineage retains the development transformer override identity', () => {
  const evidence = collectMatrixEvidence({
    import_report: {
      blocks_engine: {
        transformer: { package: 'automattic/blocks-engine-php-transformer', version: 'dev-main', reference: 'a'.repeat(40) },
        wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2' },
      },
      materialization_receipt: { schema: 'static-site-importer/materialization-receipt/v1', status: 'completed' },
    },
  }, {
    dependencyOverrides: { blocks_engine_php_transformer: { package: 'automattic/blocks-engine-php-transformer', reference: 'b'.repeat(40) } },
  });

  assert.deepEqual(evidence.lineage.development_override, { package: 'automattic/blocks-engine-php-transformer', reference: 'b'.repeat(40) });
});

test('fixture lineage only claims a transformer candidate declared by the effective recipe', () => {
  const reference = 'b'.repeat(40);
  const payload = {
    import_report: {
      blocks_engine: {
        transformer: { package: 'automattic/blocks-engine-php-transformer', version: 'dev-main', reference },
        wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2' },
      },
      materialization_receipt: { schema: 'static-site-importer/materialization-receipt/v1', status: 'completed' },
    },
  };
  const dependencyOverrides = { blocks_engine_php_transformer: { package: 'automattic/blocks-engine-php-transformer', reference } };

  assert.equal(collectMatrixEvidence(payload, { dependencyOverrides, dependencyOverlays: [] }).lineage.development_override, undefined);
  assert.deepEqual(collectMatrixEvidence(payload, {
    dependencyOverrides,
    dependencyOverlays: [{ kind: 'composer-package', package: 'automattic/blocks-engine-php-transformer', consumer: 'static-site-importer', source: '/candidate', reference }],
  }).lineage.development_override, { package: 'automattic/blocks-engine-php-transformer', reference });
});

test('fixture lineage does not correlate a retried provider failure to a transform diagnostic', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-attribution-correlation-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'attribution-correlation-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: [
      {
        fixture_id: 'simple-site',
        run_id: 'current-run',
        status: 'failed',
        import_report: verifiedImportReport(),
        diagnostics: [{ id: 'current-transform', run_id: 'current-run', step_id: 'shared-step', kind: 'missing_output', attribution_boundary: 'transform', message: 'Current transform omitted a source block.' }],
      },
      {
        fixture_id: 'simple-site',
        run_id: 'prior-run',
        step_id: 'shared-step',
        provider_adapter: { schema: 'static-site-importer/provider-adapter/v1', status: 'failed', provider: 'retried-provider' },
      },
    ],
  });

  const finding = result.findings.find((item) => item.kind === 'missing_output');
  assert.equal(finding.candidate_repo, 'blocks-engine');
  assert.equal(finding.diagnostic_blind_spots, undefined);
  assert.equal(finding.attribution_candidates.find((candidate) => candidate.boundary === 'provider').missing.length, 0);
});

test('fixture lineage rejects same-run evidence from a different step', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-attribution-step-correlation-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'attribution-step-correlation-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: [
      {
        fixture_id: 'simple-site',
        run_id: 'shared-run',
        step_id: 'transform-step',
        status: 'failed',
        import_report: verifiedImportReport(),
        diagnostics: [{ id: 'transform-diagnostic', run_id: 'shared-run', step_id: 'transform-step', kind: 'missing_output', attribution_boundary: 'transform', message: 'Transform failed.' }],
      },
      {
        fixture_id: 'simple-site',
        run_id: 'shared-run',
        step_id: 'provider-step',
        provider_adapter: { schema: 'static-site-importer/provider-adapter/v1', status: 'failed', provider: 'other-step-provider' },
      },
    ],
  });

  const finding = result.findings.find((item) => item.kind === 'missing_output');
  assert.equal(finding.candidate_repo, 'blocks-engine');
  assert.equal(finding.diagnostic_blind_spots, undefined);
});

test('fixture lineage requires complete matching correlation identities', () => {
  const cases = [
    ['matching run', { run_id: 'run-a' }, { run_id: 'run-a' }, true],
    ['matching run and step', { run_id: 'run-a', step_id: 'step-a' }, { run_id: 'run-a', step_id: 'step-a' }, true],
    ['matching full identity', { run_id: 'run-a', step_id: 'step-a', diagnostic_id: 'diagnostic-a' }, { run_id: 'run-a', step_id: 'step-a', diagnostic_id: 'diagnostic-a' }, true],
    ['same run with evidence-only step', { run_id: 'run-a' }, { run_id: 'run-a', step_id: 'step-a' }, false],
    ['same step with diagnostic-only run', { run_id: 'run-a', step_id: 'step-a' }, { step_id: 'step-a' }, false],
    ['same run with conflicting step', { run_id: 'run-a', step_id: 'step-a' }, { run_id: 'run-a', step_id: 'step-b' }, false],
    ['same run and step with conflicting diagnostic', { run_id: 'run-a', step_id: 'step-a', diagnostic_id: 'diagnostic-a' }, { run_id: 'run-a', step_id: 'step-a', diagnostic_id: 'diagnostic-b' }, false],
    ['absent identities', {}, {}, false],
  ];

  for (const [name, diagnosticIdentity, evidenceIdentity, expected] of cases) {
    const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-attribution-identity-'));
    const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: `attribution-identity-${name}` });
    const result = collectFixtureMatrixRunResults({
      matrix,
      outputDirectory,
      codeboxOutput: [
        {
          fixture_id: 'simple-site',
          status: 'failed',
          import_report: verifiedImportReport(),
          diagnostics: [{ ...diagnosticIdentity, kind: 'recipe_step_failure', attribution_boundary: 'provider', message: 'Provider operation failed.' }],
        },
        {
          fixture_id: 'simple-site',
          ...evidenceIdentity,
          provider_adapter: { schema: 'static-site-importer/provider-adapter/v1', status: 'failed', provider: 'correlation-test' },
        },
      ],
    });

    const finding = result.findings.find((item) => item.kind === 'recipe_step_failure');
    assert.equal(finding.candidate_repo === 'static-site-importer', expected, name);
  }
});

test('fixture attribution infers transform ownership for verified editor-invalid diagnostics', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'inferred-transform-attribution-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      matrix_evidence: verifiedMatrixEvidence(),
      diagnostics: [{ kind: 'editor_block_invalid', message: 'Editor rejected transformed block markup.' }],
    }],
  });

  assert.equal(result.findings[0].candidate_repo, 'blocks-engine');
  assert.equal(result.findings[0].attribution_candidates.find((candidate) => candidate.boundary === 'transform').supported, true);
});

function verifiedMatrixEvidence() {
  return {
    readiness: 'verified',
    missing: [],
    transformer: { package: 'automattic/blocks-engine-php-transformer', version: '1.0.0', reference: 'a'.repeat(40) },
    wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2' },
    materialization_receipt: { schema: 'static-site-importer/materialization-receipt/v1', status: 'completed' },
    lineage: { artifact: { schema: 'blocks-engine/wordpress-site-plan/v2' } },
  };
}

function verifiedImportReport() {
  return {
    materialization_receipt: { schema: 'static-site-importer/materialization-receipt/v1', status: 'completed' },
    blocks_engine: {
      transformer: { package: 'automattic/blocks-engine-php-transformer', version: '1.0.0', reference: 'a'.repeat(40) },
      wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2' },
    },
  };
}

test('fixture matrix rejects placeholder transformer provenance', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-matrix-placeholder-provenance-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'placeholder-provenance-test' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: {
      fixture_id: 'simple-site',
      status: 'passed',
      import_report: {
        blocks_engine: {
          transformer: { package: 'unknown', version: 'dev-unknown', reference: '0000000000000000000000000000000000000000' },
          wordpress_site_plan: { schema: 'blocks-engine/wordpress-site-plan/v2' },
        },
      },
    },
  });

  assert.deepEqual(result.fixtures[0].matrix_evidence.missing, ['transformer_package', 'transformer_version', 'transformer_reference', 'materialization_receipt']);
});

test('fails the gate when a preserved_runtime_island carries no runtime-carried signal', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-island-no-signal-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'html_form_fallback',
            loss_class: 'preserved_runtime_island',
            source_path: 'posts/page-contact.post_content',
            selector: 'form#contact',
            message: 'Contact form markup preserved but no handler was carried.',
          },
        ],
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.loss_class, 'preserved_runtime_island');
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(finding.acceptable_loss, false);
  assert.equal(result.summary.preserved_runtime_island_count, 1);
  assert.equal(result.summary.acceptable_finding_count, 0);
  assert.equal(result.summary.unacceptable_finding_count, 1);
  assert.equal(result.summary.failed, 1);
  assert.equal(result.summary.succeeded, 0);
  assert.equal(result.fixtures[0].status, 'failed');
});

test('passes the gate when a preserved_runtime_island carries a runtime-carried signal', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-island-signal-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'html_form_fallback',
            loss_class: 'preserved_runtime_island',
            runtime_mapped: 'wp-block-contact-form',
            source_path: 'posts/page-contact.post_content',
            selector: 'form#contact',
            message: 'Contact form markup preserved and behavior mapped to a native block.',
          },
        ],
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.loss_class, 'preserved_runtime_island');
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(finding.acceptable_loss, true);
  assert.equal(result.summary.acceptable_finding_count, 1);
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.summary.failed, 0);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('passes the gate for the transformer accepted runtime preservation contract', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-island-preservation-status-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'dom',
            loss_class: 'runtime_island_preserved',
            preservation_status: 'accepted_runtime_preservation',
            runtime_requirement: 'client_script_execution',
            disposition: 'preserve',
            js_handling: 'preserve_verbatim',
            source_path: 'website/index.html',
            selector: '.site-nav',
            message: 'Runtime-dependent source markup was preserved as a bounded runtime island.',
          },
        ],
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(finding.preservation_status, 'accepted_runtime_preservation');
  assert.equal(finding.runtime_requirement, 'client_script_execution');
  assert.equal(finding.js_handling, 'preserve_verbatim');
  assert.equal(result.summary.acceptable_loss_classes.preserved_runtime_island, 1);
  assert.equal(result.summary.unacceptable_loss_classes.preserved_runtime_island, undefined);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('loss class summaries reflect conditional finding verdicts', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'conditional-loss-summary-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          { kind: 'dom', loss_class: 'preserved_runtime_island', preservation_status: 'accepted_runtime_preservation', selector: '.working-runtime' },
          { kind: 'dom', loss_class: 'preserved_runtime_island', selector: '.dead-runtime' },
        ],
      },
    ],
  });

  assert.equal(result.summary.acceptable_loss_classes.preserved_runtime_island, 1);
  assert.equal(result.summary.unacceptable_loss_classes.preserved_runtime_island, 1);
  assert.equal(result.summary.acceptable_finding_count, 1);
  assert.equal(result.summary.unacceptable_finding_count, 1);
});

test('passes the gate when a preserved_runtime_island is explicitly accepted runtime preservation', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-island-repair-mode-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'core_html_block',
            loss_class: 'preserved_runtime_island',
            repair_mode: 'accepted-runtime-preservation',
            source_path: 'posts/page-home.post_content',
            selector: 'canvas#canvas',
            message: 'Canvas markup preserved for runtime script access.',
          },
        ],
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.loss_class, 'preserved_runtime_island');
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(finding.acceptable_loss, true);
  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.summary.failed, 0);
});

test('normalizes the transformer-emitted runtime_island_preserved loss class to the canonical preserved_runtime_island', () => {
  // The php-transformer emits `runtime_island_preserved` (FallbackDiagnostic /
  // HtmlTransformer). The alias must deterministically canonicalize it without
  // relying on the wording regex fallback.
  assert.equal(normalizeLossClass('runtime_island_preserved'), 'preserved_runtime_island');
  assert.equal(normalizeLossClass('preserved_runtime_island'), 'preserved_runtime_island');
  assert.equal(normalizeLossClass('runtime_island'), 'preserved_runtime_island');
});

test('classifies a transformer runtime_island_preserved finding as acceptable without relying on message wording', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-island-preserved-alias-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'html_script_fallback',
            // Exact string emitted by the php-transformer; carries no
            // "runtime island" wording in kind/message so acceptance must come
            // from the explicit alias, not the wording regex fallback.
            loss_class: 'runtime_island_preserved',
            runtime_carried: true,
            source_path: 'website/index.html',
            selector: 'script#app',
            message: 'Script kept verbatim.',
          },
        ],
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.loss_class, 'preserved_runtime_island');
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(finding.acceptable_loss, true);
  assert.equal(result.summary.acceptable_finding_count, 1);
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.summary.preserved_runtime_island_count, 1);
  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.summary.failed, 0);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('keeps native_conversion findings acceptable without a runtime-carried signal', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'native-conversion-acceptance-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'native_block_conversion',
            loss_class: 'native_conversion',
            source_path: 'website/index.html',
            message: 'Converted natively to editor blocks.',
          },
        ],
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.loss_class, 'native_conversion');
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(result.summary.acceptable_finding_count, 1);
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('classifies script fallbacks and semantic parity without generic unsupported loss', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'script-semantic-classification-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'html_script_fallback',
            source_path: 'website/index.html',
            selector: 'script:nth-of-type(1)',
            message: 'Script HTML requires runtime behavior and was preserved as scoped safe fallback metadata.',
          },
          {
            kind: 'html_semantic_parity_navigation_item_count_mismatch',
            source_path: 'website/index.html',
            selector: 'nav:nth-of-type(1)',
            message: 'Source navigation item count differs from generated core navigation items.',
          },
        ],
      },
    ],
  });

  assert.equal(result.findings[0].loss_class, 'preserved_runtime_island');
  assert.equal(result.findings[0].loss_acceptance, 'unacceptable');
  assert.equal(result.findings[1].loss_class, 'editable_approximation');
  assert.equal(result.findings[1].loss_acceptance, 'acceptable');
  assert.equal(result.summary.unacceptable_loss_classes.unsupported_loss, undefined);
});

test('preserves recipe step runtime execution failure loss class', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'recipe-step-failure-classification-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'recipe_step_failure',
            group_key: 'wp_codebox_recipe_step_failure',
            loss_class: 'runtime_execution_failed',
            command: 'wordpress.visual-compare',
            message: 'WP Codebox recipe step failed.',
          },
        ],
      },
    ],
  });

  assert.equal(result.findings[0].loss_class, 'runtime_execution_failed');
  assert.equal(result.findings[0].loss_acceptance, 'unacceptable');
  assert.equal(result.summary.unacceptable_loss_classes.runtime_execution_failed, 1);
  assert.equal(result.summary.unacceptable_loss_classes.unsupported_loss, undefined);
});

test('classifies fixtures from the per-fixture manifest as the sole source of truth', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-manifest-'));
  const shop = path.join(root, 'spring-shop');
  const shader = path.join(root, 'interactive-demo');
  mkdirSync(path.join(shop, 'products'), { recursive: true });
  mkdirSync(path.join(shader, 'assets'), { recursive: true });
  // The HTML/file content deliberately does NOT match the declared class — the
  // manifest wins regardless of what a heuristic would have guessed.
  writeFileSync(path.join(shop, 'index.html'), '<h1>Just a hero</h1>');
  writeFileSync(path.join(shop, 'products', 'shoe.html'), '<h2>Shoe</h2>');
  writeFileSync(path.join(shop, 'fixture.json'), JSON.stringify({ fixture_class: 'ecommerce/catalog', tags: ['Shop', 'has-cart'], capabilities: ['commerce-products', 'checkout'], risk_profile: 'High Risk', complexity: 3, quality_budgets: { max_unacceptable_findings: 0 } }));
  writeFileSync(path.join(shader, 'index.html'), '<h1>Plain marketing copy</h1>');
  writeFileSync(path.join(shader, 'assets', 'shader.js'), 'document.querySelector("canvas");');
  writeFileSync(path.join(shader, 'fixture.json'), JSON.stringify({ class: 'canvas/webgl/audio/runtime-heavy', complexity: 9 }));

  const matrix = createFixtureMatrix({ fixture_root: root });
  const shopFixture = matrix.fixtures.find((fixture) => fixture.id === 'spring-shop');
  const shaderFixture = matrix.fixtures.find((fixture) => fixture.id === 'interactive-demo');

  // Manifest class wins over anything the heuristic would have inferred.
  assert.equal(shopFixture.fixture_class, 'ecommerce/catalog');
  assert.equal(shaderFixture.fixture_class, 'canvas/webgl/audio/runtime-heavy');
  assert.deepEqual(shopFixture.taxonomy.signals, ['manifest']);

  // Tags and complexity are carried through onto the normalized fixture.
  assert.deepEqual(shopFixture.tags, ['Shop', 'has-cart']);
  assert.deepEqual(shopFixture.capabilities, ['checkout', 'commerce-products']);
  assert.equal(shopFixture.risk_profile, 'high-risk');
  assert.equal(shopFixture.complexity, 3);
  assert.deepEqual(shopFixture.quality_budgets, { max_unacceptable_findings: 0 });
  // Complexity is clamped into the documented 1-5 range.
  assert.equal(shaderFixture.complexity, 5);
  assert.deepEqual(shaderFixture.tags, []);

  // An explicit class injected by tests/runner/result-merge still takes precedence.
  assert.equal(classifyFixture({ fixture_class: 'docs/blog', directory: shop }).fixture_class, 'docs/blog');
});

test('preserves legacy class manifest alias while preferring fixture_class', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-class-alias-'));
  const legacy = path.join(root, 'legacy-class');
  const preferred = path.join(root, 'preferred-class');
  mkdirSync(legacy, { recursive: true });
  mkdirSync(preferred, { recursive: true });
  writeFileSync(path.join(legacy, 'index.html'), '<h1>Legacy</h1>');
  writeFileSync(path.join(legacy, 'fixture.json'), JSON.stringify({ class: 'docs/blog' }));
  writeFileSync(path.join(preferred, 'index.html'), '<h1>Preferred</h1>');
  writeFileSync(path.join(preferred, 'fixture.json'), JSON.stringify({ class: 'docs/blog', fixture_class: 'app/dashboard' }));

  const matrix = createFixtureMatrix({ fixture_root: root });
  const byId = new Map(matrix.fixtures.map((fixture) => [fixture.id, fixture]));

  assert.equal(byId.get('legacy-class').fixture_class, 'docs/blog');
  assert.equal(byId.get('preferred-class').fixture_class, 'app/dashboard');
  assert.equal(matrix.manifest_coverage.gate.status, 'passed');
});

test('keeps manifest-less fixtures unknown and fails coverage for malformed metadata', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-manifest-fallback-'));
  const missing = path.join(root, 'no-manifest');
  const invalid = path.join(root, 'bad-class');
  const broken = path.join(root, 'broken-json');
  mkdirSync(missing, { recursive: true });
  mkdirSync(invalid, { recursive: true });
  mkdirSync(broken, { recursive: true });
  writeFileSync(path.join(missing, 'index.html'), '<h1>Product Catalog Checkout Cart Shop</h1>');
  writeFileSync(path.join(invalid, 'index.html'), '<h1>Docs</h1>');
  writeFileSync(path.join(invalid, 'fixture.json'), JSON.stringify({ class: 'totally-made-up' }));
  writeFileSync(path.join(broken, 'index.html'), '<h1>Docs</h1>');
  writeFileSync(path.join(broken, 'fixture.json'), '{ not valid json');

  const warnings = [];
  const originalWrite = process.stderr.write;
  process.stderr.write = (chunk) => { warnings.push(String(chunk)); return true; };
  let matrix;
  try {
    matrix = createFixtureMatrix({ fixture_root: root });
  } finally {
    process.stderr.write = originalWrite;
  }
  const byId = new Map(matrix.fixtures.map((fixture) => [fixture.id, fixture]));

  // No heuristic guessing: manifest-less fixtures remain unknown, while malformed
  // metadata is excluded from executable discovery and fails coverage explicitly.
  assert.equal(byId.get('no-manifest').fixture_class, 'unknown');
  assert.deepEqual(byId.get('no-manifest').taxonomy.signals, ['manifest_missing']);
  assert.equal(matrix.manifest_coverage.gate.status, 'warning');
  assert.equal(matrix.manifest_coverage.unknown_fixture_class_count, 1);
  assert.equal(matrix.fixture_coverage.gate.status, 'failed');
  assert.deepEqual(matrix.fixture_coverage.active.malformed.map(({ id, reason }) => ({ id, reason })), [
    { id: 'bad-class', reason: 'metadata_invalid_class' },
    { id: 'broken-json', reason: 'malformed_metadata' },
  ]);

  // A clear, loud warning naming each offending fixture was emitted.
  const warningText = warnings.join('');
  assert.match(warningText, /WARNING:.*no-manifest.*no fixture\.json/s);
});

test('filters the matrix by manifest class and tag lane', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-filter-'));
  const cases = [
    ['landing', { class: 'marketing/static', tags: ['restaurant', 'has-form'], capabilities: ['forms'], risk_profile: 'low' }],
    ['brochure', { class: 'marketing/static', tags: ['agency'], capabilities: ['static-html'], risk_profile: 'low' }],
    ['storefront', { class: 'ecommerce/catalog', tags: ['restaurant'], capabilities: ['commerce-products', 'checkout'], risk_profile: 'high' }],
  ];
  for (const [name, manifest] of cases) {
    const dir = path.join(root, name);
    mkdirSync(dir, { recursive: true });
    writeFileSync(path.join(dir, 'index.html'), `<h1>${name}</h1>`);
    writeFileSync(path.join(dir, 'fixture.json'), JSON.stringify(manifest));
  }

  const classLane = createFixtureMatrix({ fixture_root: root, class: 'marketing/static' });
  assert.deepEqual(classLane.fixtures.map((fixture) => fixture.id).sort(), ['brochure', 'landing']);
  assert.deepEqual(classLane.filter, { fixture_class: 'marketing/static' });

  const tagLane = createFixtureMatrix({ fixture_root: root, tag: 'restaurant' });
  assert.deepEqual(tagLane.fixtures.map((fixture) => fixture.id).sort(), ['landing', 'storefront']);

  const combined = createFixtureMatrix({ fixture_root: root, class: 'marketing/static', tag: 'restaurant' });
  assert.deepEqual(combined.fixtures.map((fixture) => fixture.id), ['landing']);

  const capabilityLane = createFixtureMatrix({ fixture_root: root, capability: 'checkout' });
  assert.deepEqual(capabilityLane.fixtures.map((fixture) => fixture.id), ['storefront']);

  const riskLane = createFixtureMatrix({ fixture_root: root, risk_profile: 'low' });
  assert.deepEqual(riskLane.fixtures.map((fixture) => fixture.id).sort(), ['brochure', 'landing']);
});

test('filters the matrix by authored complexity lanes', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-complexity-filter-'));
  const cases = [
    ['simple-landing', { class: 'marketing/static', tags: ['restaurant'], complexity: 1 }],
    ['medium-brochure', { class: 'marketing/static', tags: ['agency'], complexity: 3 }],
    ['advanced-storefront', { class: 'ecommerce/catalog', tags: ['restaurant'], complexity: 5 }],
    ['unknown-complexity', { class: 'marketing/static', tags: ['restaurant'] }],
  ];
  for (const [name, manifest] of cases) {
    const dir = path.join(root, name);
    mkdirSync(dir, { recursive: true });
    writeFileSync(path.join(dir, 'index.html'), `<h1>${name}</h1>`);
    writeFileSync(path.join(dir, 'fixture.json'), JSON.stringify(manifest));
  }

  const exactLane = createFixtureMatrix({ fixture_root: root, complexity: 3 });
  assert.deepEqual(exactLane.fixtures.map((fixture) => fixture.id), ['medium-brochure']);
  assert.deepEqual(exactLane.filter, { complexity: 3 });

  const maxLane = createFixtureMatrix({ fixture_root: root, max_complexity: 3 });
  assert.deepEqual(maxLane.fixtures.map((fixture) => fixture.id).sort(), ['medium-brochure', 'simple-landing']);
  assert.deepEqual(maxLane.filter, { max_complexity: 3 });

  const combined = createFixtureMatrix({ fixture_root: root, tag: 'restaurant', max_complexity: 2 });
  assert.deepEqual(combined.fixtures.map((fixture) => fixture.id), ['simple-landing']);
  assert.deepEqual(combined.filter, { tags: ['restaurant'], max_complexity: 2 });

  const missingExcluded = createFixtureMatrix({ fixture_root: root, tag: 'restaurant', max_complexity: 5 });
  assert.deepEqual(missingExcluded.fixtures.map((fixture) => fixture.id).sort(), ['advanced-storefront', 'simple-landing']);
});

test('rolls fixture matrix summaries up by fixture class and repair bucket', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-class-rollups-'));
  const shop = path.join(root, 'shop-catalog');
  const docs = path.join(root, 'docs-blog');
  mkdirSync(shop, { recursive: true });
  mkdirSync(docs, { recursive: true });
  writeFileSync(path.join(shop, 'index.html'), '<h1>Shop</h1>');
  writeFileSync(path.join(shop, 'fixture.json'), JSON.stringify({ class: 'ecommerce/catalog', capabilities: ['commerce-products', 'checkout'], risk_profile: 'high' }));
  writeFileSync(path.join(docs, 'index.html'), '<article>Docs</article>');
  writeFileSync(path.join(docs, 'fixture.json'), JSON.stringify({ class: 'docs/blog' }));
  const matrix = createFixtureMatrix({ fixture_root: root, id: 'taxonomy-rollup-test' });

  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'shop-catalog',
        status: 'failed',
        diagnostics: [
          { kind: 'missing_asset', message: 'Missing image asset for product gallery' },
          { kind: 'invalid_block_content', message: 'Unexpected or invalid content in product card' },
        ],
      },
      {
        fixture_id: 'docs-blog',
        status: 'passed',
      },
    ],
  });

  assert.equal(result.fixtures.find((fixture) => fixture.fixture_id === 'shop-catalog').fixture_class, 'ecommerce/catalog');
  assert.equal(result.findings[0].fixture_class, 'ecommerce/catalog');
  assert.equal(result.summary.fixture_classes['ecommerce/catalog'], 1);
  assert.equal(result.summary.classes['ecommerce/catalog'].failed, 1);
  assert.equal(result.summary.classes['ecommerce/catalog'].repair_buckets.dropped_images, 1);
  assert.equal(result.summary.classes['ecommerce/catalog'].repair_buckets.invalid_block_content, 1);
  assert.equal(result.summary.manifest_coverage.gate.status, 'passed');
  assert.equal(result.summary.capabilities.checkout.fixture_count, 1);
  assert.equal(result.summary.capabilities.checkout.finding_count, 2);
  assert.equal(result.summary.risk_profiles.high.failed, 1);
  assert.equal(result.summary.quality_budgets['ecommerce/catalog'].findings_per_fixture, 2);
  assert.deepEqual(result.summary.quality_budgets['docs/blog'].dominant_repair_buckets, []);
});

test('aggregates pattern families, fixture exemplars, and diagnostic blind spots', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'diagnostic-rollup-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          {
            kind: 'runtime_dependency_missing_dom_target',
            repair_bucket: 'runtime_target_gap',
            candidate_repo: 'blocks-engine',
            source_path: 'website/index.html',
            selector: '#hero canvas',
            source_html_preview: '<canvas id="hero"></canvas>',
            emitted_block_preview: '<!-- wp:group -->',
            message: 'Runtime target #hero canvas is missing after import.',
          },
          { message: 'Unclassified import quality issue.' },
        ],
      },
    ],
  });

  assert.equal(result.summary.top_pattern_families[0].key, 'runtime_target_gap:runtime_dependency_missing_dom_target:id:hero');
  assert.equal(result.findings[0].loss_class, 'runtime_target_gap');
  assert.equal(result.summary.unacceptable_loss_classes.runtime_target_gap, 1);
  assert.equal(result.summary.fixture_exemplars[0].fixture_id, 'simple-site');
  assert.equal(result.summary.fixture_exemplars[0].source_snippet, '<canvas id="hero"></canvas>');
  assert.equal(result.fanout_groups[0].count, 1);
  assert.ok(result.summary.diagnostic_blind_spots.some((spot) => spot.kind === 'generic_finding_family'));
});

test('accepted native-conversion diagnostics with reason and source path are not missing evidence', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'native-conversion-evidence-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'passed',
        diagnostics: [
          {
            kind: 'woocommerce_waived',
            loss_class: 'native_conversion',
            source_path: 'commerce.dependencies.woocommerce',
            message: 'Commerce-bearing import proceeded without WooCommerce because allow_missing_woocommerce was set; products were not seeded.',
          },
        ],
      },
    ],
  });

  assert.equal(result.findings[0].loss_class, 'native_conversion');
  assert.equal(result.summary.fixture_categories.missing_evidence, undefined);
});

test('collects import-report dependency and seeding diagnostics into fixture findings', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-import-report-diagnostics-'));
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'import-report.json'), JSON.stringify({
    status: 'failed',
    diagnostics: [
      {
        code: 'woocommerce_missing',
        severity: 'error',
        source: 'commerce.dependencies.woocommerce',
        message: 'WooCommerce is required for this import.',
      },
    ],
    product_seeding: {
      status: 'skipped',
      reason: 'woocommerce_required_but_missing',
      counts: { created: 0, updated: 0, skipped: 2, error: 0 },
    },
  }));

  const result = collectFixtureMatrixRunResults({
    matrix: createFixtureMatrix({ fixture_root: fixtureRoot, id: 'import-report-diagnostics-test' }),
    outputDirectory,
  });
  const diagnostics = result.fixtures[0].diagnostics;

  assert.ok(diagnostics.some((diagnostic) => diagnostic.kind === 'woocommerce_missing'));
  assert.ok(diagnostics.some((diagnostic) => diagnostic.kind === 'product_seeding_failed'));
  assert.equal(result.fixtures[0].status, 'failed');
  assert.equal(result.summary.unacceptable_loss_classes.importer_materialization_bug >= 1, true);
});

test('suppresses count-only fixture diagnostics from actionable fanout rollups', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'count-only-diagnostic-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: [
          2,
          {
            kind: 'core_html_block',
            repair_bucket: 'fallback_block',
            selector: 'input#email',
            source_path: 'posts/page-contact.post_content',
            message: 'generated_document_contains_core_html',
          },
        ],
      },
    ],
  });

  assert.equal(result.summary.finding_count, 2);
  assert.equal(result.summary.actionable_finding_count, 1);
  assert.equal(result.summary.non_actionable_finding_count, 1);
  assert.equal(result.findings.find((finding) => finding.kind === 'static_site_fixture_diagnostic').actionability, 'count_only');
  assert.equal(result.summary.top_pattern_families[0].key, 'fallback_block:core_html_block:input');
  assert.equal(result.summary.top_pattern_families.some((family) => family.key === 'static_site_import_quality:static_site_fixture_diagnostic:(none)'), false);
  assert.equal(result.fanout_groups.length, 1);
  assert.equal(result.fanout_groups[0].findings.length, 1);
  assert.equal(result.fanout_groups[0].findings[0].kind, 'core_html_block');
});

test('splits acceptable and unacceptable pattern rollups for minion fanout', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fanout-rollups-'));
  for (const fixture of ['fixture-alpha', 'fixture-beta', 'fixture-gamma']) {
    mkdirSync(path.join(root, fixture), { recursive: true });
    writeFileSync(path.join(root, fixture, 'index.html'), '<main>Fixture</main>');
  }

  const matrix = createFixtureMatrix({ fixture_root: root, id: 'fanout-rollup-test' });

  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'fixture-alpha',
        status: 'failed',
        diagnostics: [
          {
            kind: 'layout_shift',
            candidate_repo: 'blocks-engine',
            source_path: 'website/index.html',
            message: 'Unexpected layout shift in imported hero.',
          },
          {
            kind: 'native_block_conversion',
            loss_class: 'native_conversion',
            candidate_repo: 'blocks-engine',
            source_path: 'website/index.html',
            message: 'Converted natively to editor blocks.',
          },
        ],
      },
      {
        fixture_id: 'fixture-beta',
        status: 'failed',
        diagnostics: [
          {
            kind: 'layout_shift',
            candidate_repo: 'blocks-engine',
            source_path: 'website/index.html',
            message: 'Unexpected layout shift in imported hero.',
          },
        ],
      },
      {
        fixture_id: 'fixture-gamma',
        status: 'failed',
        diagnostics: [
          {
            kind: 'font_color_loss',
            candidate_repo: 'static-site-importer',
            source_path: 'website/index.html',
            message: 'Font color changed after import.',
          },
        ],
      },
    ],
  });

  assert.equal(result.summary.finding_count, 4);
  assert.equal(result.summary.actionable_finding_count, 4);
  assert.equal(result.summary.acceptable_finding_count, 1);
  assert.equal(result.summary.unacceptable_finding_count, 3);
  assert.equal(result.summary.groups.static_site_import_quality, 4);
  assert.equal(result.summary.top_acceptable_pattern_families[0].key, 'static_site_import_quality:native_block_conversion:(none)');
  assert.equal(result.summary.top_unacceptable_pattern_families[0].key, 'static_site_import_quality:layout_shift:(none)');
  assert.equal(result.summary.top_unacceptable_pattern_families[0].count, 2);
  assert.equal(result.summary.unacceptable_candidate_repos[0].candidate_repo, 'blocks-engine');
  assert.equal(result.summary.unacceptable_candidate_repos[0].count, 2);
  assert.equal(result.summary.unacceptable_candidate_repos[0].top_pattern_families[0].key, 'static_site_import_quality:layout_shift:(none)');
  assert.equal(result.fanout_groups[0].acceptance, 'unacceptable');
  assert.equal(result.fanout_groups[0].candidate_repo, 'blocks-engine');
  assert.equal(result.fanout_groups[0].pattern_family, 'static_site_import_quality:layout_shift:(none)');
  assert.equal(result.fanout_groups[0].count, 2);
  assert.notEqual(result.fanout_groups[0].group_key, 'static_site_import_quality');
});

test('suppresses pre-normalized count-only fixture diagnostics with fixture source paths', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'pre-normalized-count-only-diagnostic-test' });
  const fixturePath = matrix.fixtures[0].fixture_path;
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        fixture_path: fixturePath,
        status: 'failed',
        diagnostics: [
          {
            kind: 'static_site_fixture_diagnostic',
            group_key: 'static_site_import_quality',
            repair_bucket: 'static_site_import_quality',
            source_path: fixturePath,
            reason: '2',
          },
          {
            kind: 'core_html_block',
            repair_bucket: 'fallback_block',
            selector: 'input#email',
            source_path: 'posts/page-contact.post_content',
            message: 'generated_document_contains_core_html',
          },
        ],
      },
    ],
  });

  assert.equal(result.summary.finding_count, 2);
  assert.equal(result.summary.actionable_finding_count, 1);
  assert.equal(result.summary.non_actionable_finding_count, 1);
  assert.equal(result.findings.find((finding) => finding.kind === 'static_site_fixture_diagnostic').actionability, 'count_only');
  assert.equal(result.summary.top_pattern_families.some((family) => family.key === 'static_site_import_quality:static_site_fixture_diagnostic:(none)'), false);
  assert.equal(result.summary.fixture_exemplars.some((exemplar) => exemplar.kind === 'static_site_fixture_diagnostic'), false);
  assert.equal(result.summary.diagnostic_blind_spots.some((spot) => spot.exemplars.some((exemplar) => exemplar.kind === 'static_site_fixture_diagnostic')), false);
  assert.equal(result.fanout_groups.length, 1);
  assert.equal(result.fanout_groups[0].findings.some((finding) => finding.kind === 'static_site_fixture_diagnostic'), false);
});

test('does not classify visual diff diagnostics as missing evidence', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-diff-evidence-test' });
  const fixturePath = matrix.fixtures[0].fixture_path;
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        fixture_path: fixturePath,
        status: 'passed',
        diagnostics: [
          {
            id: 'visual-diff-default',
            kind: 'static_site_fixture_diagnostic',
            category: 'visual',
            source_path: fixturePath,
            visual_diff: {
              viewport_id: 'default',
              mismatch_percent: 12.5,
              mismatch_pixels: 125,
              diff_screenshot_path: 'files/browser/visual-compare/diff.png',
            },
          },
        ],
      },
    ],
  });

  const fixture = result.fixtures.find((item) => item.fixture_id === 'simple-site');
  const diagnostic = result.findings.find((finding) => finding.id === 'visual-diff-default');

  assert.ok(diagnostic?.visual_diff, 'expected the visual diff evidence to be retained');
  assert.equal(fixture.quality_gate.fixture_categories.includes('missing_evidence'), false);
  assert.equal(result.summary.fixture_categories.missing_evidence, undefined);
});

test('classifies raw visual diff diagnostics as non-gating visual mismatches', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'raw-visual-diff-classification-test' });
  const fixturePath = matrix.fixtures[0].fixture_path;
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        fixture_path: fixturePath,
        status: 'failed',
        diagnostics: [
          {
            id: 'visual-diff-default',
            kind: 'static_site_fixture_diagnostic',
            category: 'visual',
            source_path: fixturePath,
            visual_diff: {
              viewport_id: 'default',
              mismatch_percent: 15.9,
              mismatch_pixels: 675101,
              diff_screenshot_path: 'files/browser/visual-compare/diff.png',
            },
          },
        ],
      },
    ],
  });

  const fixture = result.fixtures.find((item) => item.fixture_id === 'simple-site');
  const finding = result.findings.find((item) => item.id === 'visual-diff-default');

  assert.equal(finding.loss_class, 'visual_parity_mismatch');
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(fixture.status, 'passed');
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.summary.fixture_categories.visual_mismatch, 1);
  assert.equal(result.summary.fixture_failure_categories.visual_mismatch, undefined);
});

test('does not classify visual parity mismatch findings as missing evidence', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-mismatch-evidence-test' });
  const fixturePath = matrix.fixtures[0].fixture_path;
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        fixture_path: fixturePath,
        status: 'passed',
        diagnostics: [
          {
            kind: 'visual_parity_mismatch',
            source_path: fixturePath,
            message: 'Pixel visual parity mismatch: 125/1000 overlap pixels (12.50%) exceed the 0.00% threshold.',
          },
        ],
      },
    ],
  });

  const fixture = result.fixtures.find((item) => item.fixture_id === 'simple-site');

  assert.equal(fixture.quality_gate.fixture_categories.includes('visual_mismatch'), true);
  assert.equal(fixture.quality_gate.fixture_categories.includes('missing_evidence'), false);
  assert.equal(result.summary.fixture_categories.visual_mismatch, 1);
  assert.equal(result.summary.fixture_categories.missing_evidence, undefined);
});

test('emits one visual parity mismatch when raw and artifact evidence describe the same comparison', () => {
  const diagnostics = collectVisualParityDiagnostics({
    visual_compare: {
      comparison: {
        mismatch_pixels: 125,
        total_pixels: 1000,
        overlap_mismatch_pixels: 125,
        overlap_pixels: 1000,
        dimension_mismatch: false,
      },
    },
    visual_parity_artifacts: {
      mismatch_pixels: 125,
      total_pixels: 1000,
      overlap_mismatch_pixels: 125,
      overlap_pixels: 1000,
      dimension_mismatch: false,
      source_path: 'file:///tmp/source/index.html',
    },
  }, { threshold: 0 });

  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].kind, VISUAL_PARITY_MISMATCH_KIND);
  assert.equal(diagnostics[0].source_path, 'file:///tmp/source/index.html');
});

test('collects visual parity artifacts from wp-codebox matrix summaries with per-fixture refs', () => {
  const diagnostics = collectVisualParityDiagnostics({
    schema: 'wp-codebox/visual-compare-matrix/v1',
    comparisons: [
      {
        name: 'simple-site',
        source: { url: 'file:///tmp/artifacts/simple-site/source/index.html' },
        files: {
          sourceScreenshot: 'files/browser/visual-compare/simple-site/source.png',
          candidateScreenshot: 'files/browser/visual-compare/simple-site/candidate.png',
          diffScreenshot: 'files/browser/visual-compare/simple-site/diff.png',
          visualDiff: 'files/browser/visual-compare/simple-site/visual-diff.json',
        },
        comparison: {
          mismatchPixels: 994,
          totalPixels: 1000,
          overlapMismatchPixels: 994,
          overlapPixels: 1000,
          dimensionMismatch: false,
        },
      },
    ],
  }, { threshold: 0, gate: true });

  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].visual_parity_gate, true);
  assert.equal(diagnostics[0].artifact_refs.find((ref) => ref.artifact_id === 'diff_screenshot').path, 'files/browser/visual-compare/simple-site/diff.png');
});

test('visual parity alignment scores pure vertical shift as parity and reports offset', () => {
  const fixtureArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-shift-'));
  const visualDirectory = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'visual-compare', 'shifted');
  mkdirSync(visualDirectory, { recursive: true });
  const source = syntheticVisualParityPng(48, 96);
  const candidate = shiftedPng(source, 0, 8);
  writePng(path.join(visualDirectory, 'source.png'), source);
  writePng(path.join(visualDirectory, 'candidate.png'), candidate);

  const diagnostics = collectVisualParityDiagnostics(visualComparePayload({
    sourceScreenshot: 'files/browser/visual-compare/shifted/source.png',
    candidateScreenshot: 'files/browser/visual-compare/shifted/candidate.png',
    mismatchPixels: 1843,
    totalPixels: 4608,
    overlapMismatchPixels: 1843,
    overlapPixels: 4608,
  }), {
    fixtureArtifactsDirectory,
    threshold: 0,
    gate: true,
    maxVerticalShift: 16,
  });

  assert.equal(diagnostics.some((diagnostic) => diagnostic.kind === VISUAL_PARITY_MISMATCH_KIND), false);
  const offset = diagnostics.find((diagnostic) => diagnostic.kind === 'visual_parity_offset');
  assert.ok(offset, 'expected shifted-but-matching content to report a non-gating offset diagnostic');
  assert.equal(offset.detected_offset.y, 8);
  assert.equal(offset.aligned_mismatch_ratio, 0);
  assert.equal(offset.raw_mismatch_ratio, 1843 / 4608);
});

test('visual parity alignment still fails genuinely missing content', () => {
  const fixtureArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-missing-'));
  const visualDirectory = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'visual-compare', 'missing');
  mkdirSync(visualDirectory, { recursive: true });
  const source = syntheticVisualParityPng(48, 96);
  const candidate = blankPng(48, 96);
  writePng(path.join(visualDirectory, 'source.png'), source);
  writePng(path.join(visualDirectory, 'candidate.png'), candidate);

  const diagnostics = collectVisualParityDiagnostics(visualComparePayload({
    sourceScreenshot: 'files/browser/visual-compare/missing/source.png',
    candidateScreenshot: 'files/browser/visual-compare/missing/candidate.png',
    mismatchPixels: 1400,
    totalPixels: 4608,
    overlapMismatchPixels: 1400,
    overlapPixels: 4608,
  }), {
    fixtureArtifactsDirectory,
    threshold: 0.1,
    gate: true,
    maxVerticalShift: 16,
  });

  const mismatch = diagnostics.find((diagnostic) => diagnostic.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(mismatch, 'expected missing content to remain a visual-parity gate failure');
  assert.equal(mismatch.visual_parity_gate, true);
  assert.equal(mismatch.raw_mismatch_ratio, 1400 / 4608);
  assert.ok(mismatch.aligned_mismatch_ratio > 0.1);
});

test('findBestVisualParityOffset returns deterministic offset metrics for identical shifted PNGs', () => {
  const source = syntheticVisualParityPng(32, 64);
  const candidate = shiftedPng(source, 0, 6);
  const score = findBestVisualParityOffset(source, candidate, { maxVerticalShift: 10 });

  assert.equal(score.detected_offset.y, 6);
  assert.equal(score.detected_offset.x, 0);
  assert.equal(score.aligned_mismatch_pixels, 0);
  assert.equal(score.aligned_mismatch_ratio, 0);
});

test('visual diff region classification identifies pure color-fill changes as color_shift', () => {
  const { fixtureArtifactsDirectory, payload } = visualDiffClassificationFixture('color-shift', (source, candidate) => {
    fillRect(source, 8, 8, 24, 18, [20, 90, 160, 255]);
    fillRect(candidate, 8, 8, 24, 18, [220, 180, 40, 255]);
  });

  const classification = classifyVisualDiffRegions(payload, { fixtureArtifactsDirectory });
  assert.equal(classification.visual_diff_regions[0].dominant_cause, 'color_shift');
  assert.equal(classification.visual_diff_cause_summary.color_shift, classification.visual_diff_regions[0].pixel_count);
});

test('visual diff region classification identifies blanked content as missing_or_extra_element', () => {
  const { fixtureArtifactsDirectory, payload } = visualDiffClassificationFixture('missing-element', (source) => {
    fillRect(source, 8, 8, 24, 18, [20, 90, 160, 255]);
  });

  const classification = classifyVisualDiffRegions(payload, { fixtureArtifactsDirectory });
  assert.equal(classification.visual_diff_regions[0].dominant_cause, 'missing_or_extra_element');
});

test('visual diff region classification identifies shifted blocks as position_offset', () => {
  const fixtureArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-classify-shift-'));
  const visualDirectory = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'visual-compare', 'shifted-block');
  mkdirSync(visualDirectory, { recursive: true });
  const source = blankPng(48, 40);
  fillRect(source, 8, 8, 18, 16, [20, 90, 160, 255]);
  const candidate = shiftedPng(source, 6, 0);
  const diff = exactDiffPng(source, candidate);
  writePng(path.join(visualDirectory, 'source.png'), source);
  writePng(path.join(visualDirectory, 'candidate.png'), candidate);
  writePng(path.join(visualDirectory, 'diff.png'), diff);

  const classification = classifyVisualDiffRegions(visualComparePayload({
    sourceScreenshot: 'files/browser/visual-compare/shifted-block/source.png',
    candidateScreenshot: 'files/browser/visual-compare/shifted-block/candidate.png',
    diffScreenshot: 'files/browser/visual-compare/shifted-block/diff.png',
    mismatchPixels: 192,
    totalPixels: 1920,
    overlapMismatchPixels: 192,
    overlapPixels: 1920,
  }), { fixtureArtifactsDirectory, maxHorizontalShift: 8 });

  assert.equal(classification.visual_diff_regions[0].dominant_cause, 'position_offset');
});

test('visual diff region classification identifies resized boxes as restyle_geometry', () => {
  const { fixtureArtifactsDirectory, payload } = visualDiffClassificationFixture('resized-box', (source, candidate) => {
    fillRect(source, 8, 8, 18, 18, [20, 90, 160, 255]);
    fillRect(candidate, 8, 8, 28, 18, [20, 90, 160, 255]);
  });

  const classification = classifyVisualDiffRegions(payload, { fixtureArtifactsDirectory });
  assert.equal(classification.visual_diff_regions[0].dominant_cause, 'restyle_geometry');
});

test('visual diff region classification prefers computed screenshot regions over stale upstream regions', () => {
  const fixtureArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-classify-overlap-'));
  const visualDirectory = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'visual-compare', 'overlap');
  mkdirSync(visualDirectory, { recursive: true });
  const source = blankPng(48, 40);
  const candidate = blankPng(48, 40);
  fillRect(source, 8, 8, 24, 18, [20, 90, 160, 255]);
  fillRect(candidate, 8, 8, 24, 18, [220, 180, 40, 255]);
  const diff = exactDiffPng(source, candidate);
  writePng(path.join(visualDirectory, 'source.png'), source);
  writePng(path.join(visualDirectory, 'candidate.png'), candidate);
  writePng(path.join(visualDirectory, 'diff.png'), diff);

  const classification = classifyVisualDiffRegions(visualComparePayload({
    sourceScreenshot: 'files/browser/visual-compare/overlap/source.png',
    candidateScreenshot: 'files/browser/visual-compare/overlap/candidate.png',
    diffScreenshot: 'files/browser/visual-compare/overlap/diff.png',
    mismatchPixels: countDiffPixels(diff),
    totalPixels: 48 * 40,
    overlapMismatchPixels: 24 * 18,
    overlapPixels: 48 * 40,
    mismatchRegions: [{ x: 0, y: 36, width: 48, height: 4, pixels: 192 }],
  }), { fixtureArtifactsDirectory });

  assert.deepEqual(classification.visual_diff_regions[0].bbox, { x: 8, y: 8, width: 24, height: 18 });
});

test('visual diff classification discards stale regions for an exact pixel match', () => {
  const fixtureArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-classify-identical-'));
  const visualDirectory = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'visual-compare', 'identical');
  mkdirSync(visualDirectory, { recursive: true });
  const source = syntheticVisualParityPng(48, 40);
  const candidate = PNG.sync.read(PNG.sync.write(source));
  const diff = exactDiffPng(source, candidate);
  writePng(path.join(visualDirectory, 'source.png'), source);
  writePng(path.join(visualDirectory, 'candidate.png'), candidate);
  writePng(path.join(visualDirectory, 'diff.png'), diff);

  const classification = classifyVisualDiffRegions(visualComparePayload({
    sourceScreenshot: 'files/browser/visual-compare/identical/source.png',
    candidateScreenshot: 'files/browser/visual-compare/identical/candidate.png',
    diffScreenshot: 'files/browser/visual-compare/identical/diff.png',
    mismatchPixels: 0,
    totalPixels: 48 * 40,
    overlapMismatchPixels: 0,
    overlapPixels: 48 * 40,
    mismatchRegions: [{ x: 0, y: 0, width: 48, height: 40, pixels: 48 * 40 }],
  }), { fixtureArtifactsDirectory });

  assert.equal(classification, null);
});

test('fixture diagnostics drop empty rows and normalize kindless carriers with explicit kind', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-diagnostic-hygiene-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'diagnostic-hygiene-test' });
  const codeboxOutput = {
    fixture_id: 'simple-site',
    status: 'passed',
    diagnostics: [
      {},
      {
        loss_class: 'preserved_runtime_island',
        message: 'Script runtime was preserved intentionally.',
        runtime_carried: true,
      },
      {
        loss_class: 'editable_approximation',
        message: 'Converted to an editable approximation.',
      },
    ],
    import_report: {
      finding_packets: {
        packets: [
          {},
          {
            type: 'core_html_block',
            loss_class: 'editable_approximation',
            source_diagnostic: {
              source_path: 'posts/page-home.post_content',
              selector: 'section.hero',
            },
            message: 'Core HTML fallback remained editable.',
          },
        ],
      },
    },
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const diagnostics = result.fixtures[0].diagnostics;

  assert.equal(diagnostics.length, 3);
  assert.equal(diagnostics.every((diagnostic) => diagnostic && Object.keys(diagnostic).length > 0), true);
  assert.equal(diagnostics.every((diagnostic) => typeof diagnostic.kind === 'string' && diagnostic.kind.length > 0), true);
  assert.equal(result.summary.finding_count, 3);
  assert.equal(result.summary.loss_classes.preserved_runtime_island, 1);
  assert.equal(result.summary.loss_classes.editable_approximation, 2);
  assert.equal(result.summary.loss_classes.unsupported_loss, undefined);
});

test('fixture matrix intake consumes native editor-open output as canvas evidence and attaches all emitted artifact refs', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-canvas-intake-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-canvas-intake-test' });
  const codeboxOutput = {
    executions: [
      {
        command: 'wordpress.wp-cli',
        args: ['command=static-site-importer validate-artifact --slug=simple-site --artifact=/tmp/simple-site/artifact.json'],
        status: 'success',
      },
      {
        command: 'wordpress.editor-open',
        status: 'success',
        files: {
          screenshot: 'files/browser/editor-screenshot.png',
          editorState: 'files/browser/editor-state.json',
          editorValidity: 'files/browser/editor-validity.json',
        },
        summary: { editor: { blockCount: 4 } },
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const registry = buildGutenbergIncompatibilityRegistry(result);
  const decision = registry.fixture_decisions.find((row) => row.fixture_id === 'simple-site');

  assert.equal(result.fixtures[0].editor_canvas.screenshot, 'files/browser/editor-screenshot.png');
  assert.equal(result.fixtures[0].editor_open.files.screenshot, 'files/browser/editor-screenshot.png');
  assert.deepEqual(result.fixtures[0].artifact_refs.filter((ref) => ref.kind === 'editor-canvas').map((ref) => ref.path), [
    'files/browser/editor-screenshot.png',
    'files/browser/editor-state.json',
    'files/browser/editor-validity.json',
  ]);
  assert.equal(decision.editor_canvas_status, 'visible');
});

test('editor-open capture failure is distinct from editor evidence not requested', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-capture-failure-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'failed',
      diagnostics: [{ kind: 'recipe_step_failure', command: 'wordpress.editor-open', recipe_phase: 'editor-open', message: 'Editor navigation timed out.' }],
    }],
  });
  const decision = buildGutenbergIncompatibilityRegistry(result).fixture_decisions[0];

  assert.equal(decision.editor_canvas_status, 'capture_failed');
  assert.equal(decision.acceptance_status, 'editor_capture_failed');
});

test('editor canvas artifacts are persisted in the matrix artifact root and refs are rewritten', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-canvas-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-canvas-codebox-'));
  const sourceDirectory = path.join(codeboxArtifactsDirectory, 'runtime-001', 'files', 'browser');
  mkdirSync(sourceDirectory, { recursive: true });
  writeFileSync(path.join(sourceDirectory, 'editor-screenshot.png'), 'screenshot');
  writeFileSync(path.join(sourceDirectory, 'editor-state.json'), '{"blocks":3}');
  const result = materializeEditorCanvasArtifacts({
    outputDirectory,
    codeboxArtifactsDirectory,
    result: {
      fixtures: [{
        fixture_id: 'simple-site',
        artifact_refs: [
          { artifact_id: 'editor-open-screenshot', kind: 'editor-canvas', path: 'files/browser/editor-screenshot.png' },
          { artifact_id: 'editor-open-editorState', kind: 'editor-canvas', path: 'files/browser/editor-state.json' },
        ],
        editor_canvas: { screenshot: 'files/browser/editor-screenshot.png' },
        editor_open: { files: { screenshot: 'files/browser/editor-screenshot.png', editorState: 'files/browser/editor-state.json' } },
        surfaces: [{
          surface_id: 'front-page',
          artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'editor-canvas', path: 'files/browser/editor-screenshot.png' }],
          editor_open: { files: { screenshot: 'files/browser/editor-screenshot.png' } },
        }],
      }],
    },
  });
  const fixture = result.result.fixtures[0];
  const screenshotPath = path.join(outputDirectory, 'editor-canvas', 'simple-site', 'editor-screenshot.png');
  const statePath = path.join(outputDirectory, 'editor-canvas', 'simple-site', 'editor-state.json');

  assert.equal(existsSync(screenshotPath), true);
  assert.equal(existsSync(statePath), true);
  assert.equal(fixture.editor_canvas.screenshot, screenshotPath);
  assert.equal(fixture.editor_open.files.editorState, statePath);
  assert.equal(fixture.surfaces[0].editor_open.files.screenshot, screenshotPath);
  assert.deepEqual(fixture.artifact_refs.map((ref) => ref.path), [screenshotPath, statePath]);
  assert.deepEqual(fixture.artifact_refs.map((ref) => ref.artifact_id), [
    'editor_canvas_simple-site_editor-open-screenshot',
    'editor_canvas_simple-site_editor-open-editorState',
  ]);
  assert.deepEqual(result.artifacts, {
    'editor_canvas_simple-site_editor-open-screenshot': { path: screenshotPath },
    'editor_canvas_simple-site_editor-open-editorState': { path: statePath },
  });
});

test('editor canvas materialization recovers stale absolute runtime artifact paths', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-canvas-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-canvas-codebox-'));
  const runtimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-001', 'editor-canvas', 'simple-site');
  const stalePath = path.join(path.sep, 'stale', 'runtime', 'artifacts', 'editor-canvas', 'simple-site', 'editor-screenshot.png');
  mkdirSync(runtimeDirectory, { recursive: true });
  writeFileSync(path.join(runtimeDirectory, 'editor-screenshot.png'), 'screenshot');

  const result = materializeEditorCanvasArtifacts({
    outputDirectory,
    codeboxArtifactsDirectory,
    result: { fixtures: [{ fixture_id: 'simple-site', artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'editor-canvas', path: stalePath }], editor_canvas: { status: 'captured', screenshot: stalePath } }] },
  });
  const screenshotPath = path.join(outputDirectory, 'editor-canvas', 'simple-site', 'editor-screenshot.png');
  assert.equal(existsSync(screenshotPath), true);
  assert.equal(result.result.fixtures[0].editor_canvas.screenshot, screenshotPath);
  assert.deepEqual(result.artifacts, { 'editor_canvas_simple-site_editor-open-screenshot': { path: screenshotPath } });
});

test('collects SSI finding packet source and observed context from fixture artifacts', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-finding-packet-context-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'packet-context-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'import-report.json'), JSON.stringify({
    success: false,
    fixture_id: 'simple-site',
    finding_packets: {
      packets: [
        {
          type: 'runtime_dependency_missing_dom_target',
          severity: 'error',
          source: {
            path: 'website/index.html',
            selector: '.shader canvas',
            snippet: '<canvas class="shader"></canvas>',
          },
          observed: {
            reason_code: 'runtime_dependency_missing_dom_target',
            output: '<!-- wp:html /-->',
          },
          expected: {
            outcome: 'Runtime target should exist after import.',
          },
        },
      ],
    },
  }));

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const finding = result.findings[0];

  assert.equal(result.summary.finding_count, 1);
  assert.equal(finding.source_path, 'website/index.html');
  assert.equal(finding.selector, '.shader canvas');
  assert.equal(finding.selector_family, 'class:shader');
  assert.equal(finding.source_snippet, '<canvas class="shader"></canvas>');
  assert.equal(finding.observed_output, '<!-- wp:html /-->');
});

test('propagates accepted runtime preservation across duplicate script diagnostics during intake', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-runtime-preservation-intake-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-preservation-intake-test' });
  const codeboxOutput = {
    fixture_id: 'simple-site',
    status: 'failed',
    diagnostics: [
      {
        type: 'unsupported_html_fallback',
        kind: 'unsupported_html_fallback',
        reason_code: 'script_requires_runtime',
        source_path: 'website/index.html',
        selector: 'script:nth-of-type(1)',
        loss_class: 'preserved_runtime_island',
        repair_mode: 'accepted-runtime-preservation',
        acceptability: 'acceptable_preservation',
      },
      {
        code: 'html_script_fallback',
        reason: 'script_requires_runtime',
        tag: 'script',
        selector: 'script:nth-of-type(1)',
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.summary.acceptable_finding_count, 2);
  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.findings.every((finding) => finding.loss_acceptance === 'acceptable'), true);
});

test('resolved companion scripts suppress raw conversion-report fallback echoes', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-runtime-materialized-intake-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-materialized-intake-test' });
  const codeboxOutput = {
    fixture_id: 'simple-site',
    status: 'passed',
    import_report: {
      diagnostics: [{
        code: 'runtime_script_materialized',
        kind: 'runtime_script_materialized',
        loss_class: 'native_conversion',
        source_path: 'website/index.html',
        selector: 'script:nth-of-type(1)',
      }],
    },
    blocks_engine: {
      conversion_report: {
        diagnostics: [{
          code: 'html_script_fallback',
          kind: 'html',
          reason: 'script_requires_runtime',
          source_path: 'website/index.html',
          selector: 'script:nth-of-type(1)',
        }],
      },
    },
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  assert.equal(result.findings.some((finding) => finding.kind === 'html'), false);
  assert.equal(result.findings.filter((finding) => finding.kind === 'runtime_script_materialized').length, 1);
  assert.equal(result.summary.unacceptable_finding_count, 0);
});

test('materializes generated artifact roots into matrix-compatible fixtures', () => {
  const sourceRoot = mkdtempSync(path.join(tmpdir(), 'ssi-generated-artifacts-'));
  const fixtureOutput = mkdtempSync(path.join(tmpdir(), 'ssi-generated-fixtures-'));
  mkdirSync(path.join(sourceRoot, 'static-sites', 'alpha', 'assets'), { recursive: true });
  writeFileSync(path.join(sourceRoot, 'static-sites', 'alpha', 'index.html'), '<h1>Alpha</h1>');
  writeFileSync(path.join(sourceRoot, 'static-sites', 'alpha', 'assets', 'style.css'), 'body { color: black; }');
  mkdirSync(path.join(sourceRoot, 'artifact-candidate'), { recursive: true });
  writeFileSync(path.join(sourceRoot, 'artifact-candidate', 'site-artifact.json'), JSON.stringify({
    schema: 'blocks-engine/php-transformer/site-artifact/v1',
    metadata: { site: 'Beta Site' },
    compiler_limits: { max_files: 25, max_file_bytes: 10485760, max_total_bytes: 335544320 },
    files: [
      { path: 'website/index.html', content: '<h1>Beta</h1>' },
      { path: 'website/assets/style.css', content: 'body { color: blue; }' },
    ],
  }));

  const intake = materializeGeneratedArtifactFixtures({ artifactRoot: sourceRoot, fixtureRoot: fixtureOutput });
  const matrix = createFixtureMatrix({ fixture_root: intake.fixture_root });

  assert.equal(intake.count, 2);
  assert.deepEqual(matrix.fixtures.map((fixture) => fixture.id), ['alpha', 'beta-site']);
  assert.equal(readFileSync(path.join(fixtureOutput, 'alpha', 'index.html'), 'utf8'), '<h1>Alpha</h1>');
  assert.equal(readFileSync(path.join(fixtureOutput, 'beta-site', 'index.html'), 'utf8'), '<h1>Beta</h1>');
  const betaArtifact = buildFixtureArtifact(matrix.fixtures.find((fixture) => fixture.id === 'beta-site'));
  assert.deepEqual(betaArtifact.compiler_limits, { max_files: 25, max_file_bytes: 10485760, max_total_bytes: 335544320 });
  assert.equal(betaArtifact.files.some((file) => file.path.includes('generated-artifact-metadata')), false);
});

test('keeps a generated multi-page website as one matrix fixture', async () => {
  const sourceRoot = mkdtempSync(path.join(tmpdir(), 'ssi-generated-multi-page-artifact-'));
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-generated-multi-page-output-'));
  mkdirSync(path.join(sourceRoot, 'site'), { recursive: true });
  writeFileSync(path.join(sourceRoot, 'site', 'artifact.json'), JSON.stringify({
    schema: 'blocks-engine/php-transformer/site-artifact/v1',
    metadata: { site: 'Multi Page' },
    files: [
      { path: 'website/index.html', content: '<link rel="icon" href="/external/favicon.ico"><h1>Home</h1>' },
      { path: 'website/anchor/index.html', content: '<link rel="icon" href="/external/favicon.ico"><h1>Anchor</h1>' },
      { path: 'website/external/favicon.ico', content_base64: Buffer.from('icon').toString('base64') },
    ],
  }));

  const { summary } = await runFixtureMatrix({
    artifactRoot: sourceRoot,
    outputDirectory,
    staticSiteImporterPath: packageRoot,
    run: false,
  });
  const matrix = JSON.parse(readFileSync(path.join(outputDirectory, 'matrix.json'), 'utf8'));
  const artifact = JSON.parse(readFileSync(path.join(outputDirectory, 'multi-page', 'artifact.json'), 'utf8'));

  assert.equal(summary.fixture_count, 1);
  assert.deepEqual(matrix.fixtures.map((fixture) => fixture.id), ['multi-page']);
  assert.equal(artifact.summary.file_count, 3);
  assert.equal(artifact.files.some((file) => file.path === 'website/anchor/index.html'), true);
  assert.equal(artifact.files.some((file) => file.path === 'website/external/favicon.ico'), true);
});

test('resolves Blocks Engine PHP transformer override paths', () => {
  const repoRoot = mkdtempSync(path.join(tmpdir(), 'blocks-engine-'));
  const transformerPackageRoot = path.join(repoRoot, 'php-transformer');
  mkdirSync(transformerPackageRoot, { recursive: true });
  writeFileSync(path.join(transformerPackageRoot, 'composer.json'), JSON.stringify({
    name: 'automattic/blocks-engine-php-transformer',
  }));

  assert.equal(resolveBlocksEnginePhpTransformerPath(repoRoot), transformerPackageRoot);
  assert.equal(resolveBlocksEnginePhpTransformerPath(transformerPackageRoot), transformerPackageRoot);
});

test('requires Homeboy-hydrated Composer dependencies without installing them', () => {
  const pluginRoot = mkdtempSync(path.join(tmpdir(), 'ssi-hydration-'));
  const expectedAutoload = path.join(pluginRoot, 'vendor', 'autoload.php');

  assert.throws(
    () => validateHydratedComposerDependencies(pluginRoot),
    (error) => error.message.includes('Homeboy hydration is incomplete')
      && error.message.includes('homeboy rig up static-site-importer-fixture-matrix'),
  );

  mkdirSync(path.dirname(expectedAutoload), { recursive: true });
  writeFileSync(expectedAutoload, '<?php');
  assert.equal(validateHydratedComposerDependencies(pluginRoot), expectedAutoload);
});

test('builds Composer path repository override matching SSI constraints', () => {
  const config = composerPathRepositoryConfig({
    require: {
      'automattic/blocks-engine-php-transformer': '^0.1.15',
    },
  }, '/tmp/blocks-engine/php-transformer');

  assert.deepEqual(config, {
    type: 'path',
    url: '/tmp/blocks-engine/php-transformer',
    canonical: true,
    options: {
      symlink: false,
      versions: {
        'automattic/blocks-engine-php-transformer': '0.1.15',
      },
    },
  });
});

test('summarizes failed WP Codebox batches with fixture ids and child output tails', () => {
  const stderr = `${'x'.repeat(4100)}stderr failure for fixture-beta`;
  const stdout = 'stdout includes child JSON/error context';
  const summary = fixtureMatrixBatchRunSummary({
    batchNumber: 2,
    batchMatrix: { id: 'matrix-batch-002' },
    fixtures: [{ id: 'fixture-alpha' }, { id: 'fixture-beta' }],
    batchRecipeFile: '/tmp/wp-codebox-static-site-fixture-matrix-batch-002.json',
    outputFile: '/tmp/wp-codebox-output-batch-002.json',
    batchRuntime: { exitCode: 1, json: { ok: false } },
    batchError: { message: 'recipe-run failed', stderr, stdout },
  });

  assert.equal(summary.batch, 2);
  assert.equal(summary.batch_id, 'matrix-batch-002');
  assert.deepEqual(summary.fixture_ids, ['fixture-alpha', 'fixture-beta']);
  assert.equal(summary.fixture_count, 2);
  assert.equal(summary.exit_code, 1);
  assert.equal(summary.error, 'recipe-run failed');
  assert.equal(summary.parsed_output, true);
  assert.equal(summary.stderr_tail.length, 4000);
  assert.match(summary.stderr_tail, /stderr failure for fixture-beta$/);
  assert.equal(summary.stdout_tail, stdout);
});

test('builds one-command Blocks Engine fixture matrix plan from its derived inventory', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-canonical-matrix-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const blocksEngine = path.join(root, 'blocks-engine');
  const corpusRoot = path.join(blocksEngine, 'fixtures');
  const canonicalFixtureRoot = path.join(corpusRoot, 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (let index = 1; index <= syntheticFixtureCount; index += 1) {
    mkdirSync(path.join(canonicalFixtureRoot, `fixture-${String(index).padStart(2, '0')}`), { recursive: true });
    writeFileSync(path.join(canonicalFixtureRoot, `fixture-${String(index).padStart(2, '0')}`, 'index.html'), '<h1>Fixture</h1>');
  }
  writeFileSync(path.join(canonicalFixtureRoot, 'fixture-01', 'index.html'), '<h1>Target</h1>');
  const solvedFixtureRoot = path.join(corpusRoot, 'solved', 'solved-site');
  mkdirSync(solvedFixtureRoot, { recursive: true });
  writeFileSync(path.join(solvedFixtureRoot, 'index.html'), '<h1>Solved</h1>');

  const plan = buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    staticSiteImporter,
    blocksEngine,
    homeboyBin: '/tmp/homeboy-latest',
    runId: 'ssi-matrix-dev-proof',
    passthrough: ['--batch-size', '5'],
    skipInstall: true,
  });

  assert.equal(plan.mode, 'development-override');
  assert.equal(plan.homeboy_bin, '/tmp/homeboy-latest');
  assert.equal(plan.fixture_root, corpusRoot);
  assert.equal(plan.active_fixture_count, syntheticFixtureCount);
  assert.equal(plan.solved_fixture_count, 1);
  assert.equal(plan.fixture_count, syntheticFixtureCount + 1);
  assert.equal(plan.fixture_coverage.gate.status, 'passed');
  assert.equal(plan.namespace, 'ssi-matrix-dev-proof');
  assert.equal(plan.temp_root, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof');
  assert.equal(plan.output_file, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof/ssi-matrix-dev-proof.homeboy-bench.json');
  assert.equal(plan.shared_state, '');
  assert.equal(plan.artifact_root, '');
  assert.deepEqual(plan.warnings, []);
  assert.equal(plan.dependency_overrides.blocks_engine_php_transformer.path, blocksEngine);
  assert.equal(plan.steps.some((step) => step.args.includes('install')), false);
  assert.ok(plan.steps.some((step) => step.args.includes('sync')));

  const benchStep = plan.steps.at(-1);
  assert.deepEqual(benchStep.args.slice(0, 7), ['bench', '--rig', 'static-site-importer-fixture-matrix', '--profile', 'fixture-matrix', '--iterations', '1']);
  assert.equal(benchStep.command, '/tmp/homeboy-latest');
  assert.ok(benchStep.args.includes('--runner'));
  assert.ok(benchStep.args.includes('homeboy-lab'));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT=${corpusRoot}`));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH=${staticSiteImporter}`));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH=${blocksEngine}`));
  assert.ok(benchStep.args.includes('bench_env.SSI_FIXTURE_MATRIX_RUN=1'));
  assert.ok(benchStep.args.includes('bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE=1'));
  assert.ok(benchStep.args.includes('static_site_importer_fixture_matrix_namespace=ssi-matrix-dev-proof'));
  assert.equal(benchStep.args.includes('--shared-state'), false);
  assert.equal(benchStep.args.includes('--artifact-root'), false);
  assert.deepEqual(benchStep.args.slice(-3), ['--', '--batch-size', '5']);

  const releasePlan = buildFixtureMatrixRunPlan({
    mode: 'release-proof',
    staticSiteImporter,
    blocksEngine,
    passthrough: [],
  });
  assert.deepEqual(releasePlan.dependency_overrides, {});
  assert.equal(releasePlan.steps.at(-1).args.some((arg) => arg.includes('SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH')), false);

  const targetPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    targetFixture: 'fixture-01',
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(targetPlan.fixture_count, 1);
  assert.deepEqual(targetPlan.lane_filter.fixture_ids, ['fixture-01']);
  assert.equal(targetPlan.lane_filter.promotion_gate, undefined);
  assert.ok(targetPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_FIXTURE_IDS=fixture-01'));

  const promotionPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    targetFixture: 'fixture-01',
    promotionGate: true,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(promotionPlan.fixture_count, 2);
  assert.equal(promotionPlan.selected_active_fixture_count, 1);
  assert.equal(promotionPlan.selected_solved_fixture_count, 1);
  assert.deepEqual(promotionPlan.lane_filter.fixture_ids, ['fixture-01', 'solved-site']);
  assert.equal(promotionPlan.lane_filter.promotion_gate, true);
  assert.ok(promotionPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_FIXTURE_IDS=fixture-01,solved-site'));
  assert.ok(promotionPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_REQUIRE_SOLVED_CANDIDATE=1'));
  assert.ok(promotionPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_BATCH_SIZE=1'));

  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, promotionGate: true }),
    /--promotion-gate requires --target-fixture/
  );
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, targetFixture: 'fixture-01', promotionGate: true, visualParity: false }),
    /requires editor validation and gated visual parity/
  );

  const solvedOnlyPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    solvedOnly: true,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(solvedOnlyPlan.lane_identity, SOLVED_ONLY_LANE_ID);
  assert.equal(solvedOnlyPlan.fixture_count, 1);
  assert.equal(solvedOnlyPlan.selected_active_fixture_count, 0);
  assert.equal(solvedOnlyPlan.selected_solved_fixture_count, 1);
  assert.deepEqual(solvedOnlyPlan.lane_filter, {
    fixture_ids: ['solved-site'],
    solved_only: true,
    identity: SOLVED_ONLY_LANE_ID,
    fixture_corpus: 'solved',
  });
  assert.ok(solvedOnlyPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_FIXTURE_IDS=solved-site'));
  assert.ok(solvedOnlyPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_REQUIRE_SOLVED_CANDIDATE=1'));
  assert.ok(solvedOnlyPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_BATCH_SIZE=1'));

  const emptySolvedRoot = path.join(root, 'empty-solved-fixtures');
  mkdirSync(path.join(emptySolvedRoot, 'websites', 'fixture-01'), { recursive: true });
  writeFileSync(path.join(emptySolvedRoot, 'websites', 'fixture-01', 'index.html'), '<h1>Target</h1>');
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot: emptySolvedRoot, solvedOnly: true }),
    /--solved-only requires at least one valid fixture under .*\/solved/
  );
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, solvedOnly: true, targetFixture: 'fixture-01' }),
    /--solved-only selects the complete fixtures\/solved corpus and cannot be combined with --target-fixture/
  );
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, solvedOnly: true, class: 'marketing/static' }),
    /--solved-only selects the complete fixtures\/solved corpus and cannot be combined with --class/
  );
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, solvedOnly: true, editorValidation: false }),
    /--solved-only requires editor validation and gated visual parity/
  );

  const surfacePlan = buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    staticSiteImporter,
    blocksEngine,
    surfaceCoverage: '99',
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(surfacePlan.surface_coverage.extra_surfaces_per_fixture, MAX_EXTRA_SURFACE_COUNT);
  assert.equal(surfacePlan.surface_coverage.capped, true);
  assert.equal(surfacePlan.surface_coverage.max_browser_surface_count, (syntheticFixtureCount + 1) * (MAX_EXTRA_SURFACE_COUNT + 1));
  assert.ok(surfacePlan.warnings.some((warning) => warning.code === 'surface_coverage_runtime_cost'));

  const explicitOutput = path.join(root, 'custom-output', 'homeboy-bench.json');
  const explicitOutputPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    output: explicitOutput,
  });
  assert.equal(explicitOutputPlan.output_file, explicitOutput);

  const visualGateOptOutPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    visualParityGate: false,
  });
  assert.equal(visualGateOptOutPlan.visual_parity.gate, false);
  assert.ok(visualGateOptOutPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE=0'));
});

test('fixture matrix run configuration covers every declared environment, bench, recipe, and gate projection', () => {
  const input = Object.fromEntries(Object.entries(FIXTURE_MATRIX_RUN_FIELDS).map(([key, field]) => [key, fixtureMatrixRunConfigTestValue(key, field)]));
  const config = normalizeFixtureMatrixRunConfig(input);
  const settings = fixtureMatrixHomeboySettings(config);
  const directEnv = Object.fromEntries(Object.entries(FIXTURE_MATRIX_RUN_FIELDS).map(([key, field]) => [field.env, fixtureMatrixRunConfigFallbackValue(key, field)]));
  const fromSettings = fixtureMatrixRunConfigFromEnv({
    ...directEnv,
    HOMEBOY_SETTINGS_JSON: JSON.stringify({ bench_env: settings }),
  });
  const bench = fixtureMatrixBenchOptions(fromSettings);

  assert.deepEqual(fromSettings, config, 'Homeboy bench settings override direct environment values for every declared field');
  assert.deepEqual(bench, { ...config, fixtureIds: config.fixtureIds.join(',') }, 'every normalized field reaches the bench');
  assert.deepEqual(fixtureMatrixRecipeInput(config), fixtureMatrixExpectedProjection(config, 'recipe'));
  assert.deepEqual(fixtureMatrixGateConfig(config), fixtureMatrixExpectedProjection(config, 'gate'));
  for (const [key, field] of Object.entries(FIXTURE_MATRIX_RUN_FIELDS)) {
    assert.ok(Object.hasOwn(field, 'projections'), `${key} must explicitly declare its projection contract`);
  }
  assert.throws(() => normalizeFixtureMatrixRunConfig({ fixtureRoot: '/fixtures', unknown: true }), /Unknown fixture matrix run configuration/);
  assert.throws(() => normalizeFixtureMatrixRunConfig({ hostDependencyOrchestration: false }), /Unknown fixture matrix run configuration/);
  assert.equal(Object.values(FIXTURE_MATRIX_RUN_FIELDS).some((field) => field.env === 'SSI_FIXTURE_MATRIX_HOST_DEPENDENCY_ORCHESTRATION'), false);
});

test('fixture coverage inventory derives additions, omissions, metadata failures, and duplicate IDs', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-derived-fixture-coverage-'));
  const fixtureRoot = path.join(root, 'fixtures');
  const writeFixture = (relative, manifest = { fixture_class: 'marketing/static' }) => {
    const directory = path.join(fixtureRoot, 'websites', relative);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), '<h1>Fixture</h1>');
    if (manifest !== undefined) writeFileSync(path.join(directory, 'fixture.json'), typeof manifest === 'string' ? manifest : JSON.stringify(manifest));
    return directory;
  };

  const first = writeFixture('first');
  let matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.deepEqual(matrix.fixture_coverage.active.selected, [{ id: 'first', reason: 'selected' }]);
  assert.equal(matrix.fixture_coverage.gate.status, 'passed');

  writeFixture('added');
  matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.deepEqual(matrix.fixture_coverage.active.selected.map((row) => row.id), ['added', 'first']);

  matrix = createFixtureMatrix({ fixture_root: fixtureRoot, fixtures: [matrix.fixtures.find((fixture) => fixture.directory === first)] });
  assert.equal(matrix.fixture_coverage.gate.status, 'failed');
  assert.deepEqual(matrix.fixture_coverage.active.skipped.filter((row) => row.reason === 'omitted').map((row) => row.id), ['added']);

  writeFixture('broken', '{');
  matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.equal(matrix.fixture_coverage.gate.status, 'failed');
  assert.deepEqual(matrix.fixture_coverage.active.malformed.map(({ id, reason }) => ({ id, reason })), [{ id: 'broken', reason: 'malformed_metadata' }]);

  writeFixture('nested/duplicate');
  writeFixture('nested-duplicate');
  matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.equal(matrix.fixture_coverage.gate.status, 'failed');
  assert.deepEqual(matrix.fixture_coverage.active.duplicates.map((row) => row.id), ['nested-duplicate', 'nested-duplicate']);
});

test('fixture coverage evidence keeps solved-only and promotion selection separate', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-derived-fixture-selection-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const blocksEngine = path.join(root, 'blocks-engine');
  for (const [corpus, id] of [['websites', 'active-site'], ['solved', 'solved-site']]) {
    const directory = path.join(blocksEngine, 'fixtures', corpus, id);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), '<h1>Fixture</h1>');
    writeFileSync(path.join(directory, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
  }
  mkdirSync(staticSiteImporter, { recursive: true });

  const solvedOnly = buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, solvedOnly: true, skipInstall: true, skipSync: true });
  assert.deepEqual(solvedOnly.fixture_coverage.active.selected, []);
  assert.deepEqual(solvedOnly.fixture_coverage.solved.selected, [{ id: 'solved-site', reason: 'selected' }]);

  const promotion = buildFixtureMatrixRunPlan({ staticSiteImporter, blocksEngine, targetFixture: 'active-site', promotionGate: true, skipInstall: true, skipSync: true });
  assert.deepEqual(promotion.fixture_coverage.active.selected, [{ id: 'active-site', reason: 'selected' }]);
  assert.deepEqual(promotion.fixture_coverage.solved.selected, [{ id: 'solved-site', reason: 'selected' }]);
});

test('active and solved corpora cannot share a stable fixture ID', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-cross-corpus-duplicate-'));
  const fixtureRoot = path.join(root, 'fixtures');
  const staticSiteImporter = path.join(root, 'static-site-importer');
  for (const corpus of ['websites', 'solved']) {
    const directory = path.join(fixtureRoot, corpus, 'shared-site');
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), '<h1>Fixture</h1>');
    writeFileSync(path.join(directory, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
  }
  mkdirSync(staticSiteImporter, { recursive: true });

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.equal(matrix.count, 0, 'cross-corpus duplicates never reach artifact/result construction');
  for (const inventory of [matrix.fixture_coverage.active, matrix.fixture_coverage.solved]) {
    assert.deepEqual(inventory.duplicates.map((row) => ({ id: row.id, reason: row.reason, corpus: row.corpus, root: row.root, path: row.path })), [{
      id: 'shared-site',
      reason: 'duplicate_id',
      corpus: inventory.corpus,
      root: path.join(fixtureRoot, inventory.corpus === 'active' ? 'websites' : 'solved'),
      path: path.join(fixtureRoot, inventory.corpus === 'active' ? 'websites' : 'solved', 'shared-site'),
    }]);
  }
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot, targetFixture: 'shared-site', promotionGate: true }),
    /duplicate stable IDs.*shared-site.*\(active; root=.*\(solved; root=/s,
  );

  const outputDirectory = path.join(root, 'artifacts');
  writeFixtureMatrixArtifacts({ outputDirectory, matrix });
  assert.equal(existsSync(path.join(outputDirectory, 'shared-site', 'artifact.json')), false);
  assert.equal(existsSync(path.join(outputDirectory, 'shared-site', 'surface-lineage--front-page.json')), false);
});

test('the committed SSI fixture corpus has a deterministic coverage inventory', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.deepEqual(matrix.fixture_coverage.active.eligible_fixture_ids, ['simple-site']);
  assert.deepEqual(matrix.fixture_coverage.solved.eligible_fixture_ids, []);
  assert.equal(matrix.fixture_coverage.gate.status, 'passed');
});

test('result evidence writes the derived coverage into summary and finding packets', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-derived-fixture-evidence-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  writeFixtureMatrixResultArtifacts({ outputDirectory, matrix });
  const summary = JSON.parse(readFileSync(path.join(outputDirectory, 'summary.json'), 'utf8'));
  const packets = JSON.parse(readFileSync(path.join(outputDirectory, 'finding-packets.json'), 'utf8'));
  const coverage = JSON.parse(readFileSync(path.join(outputDirectory, 'fixture-coverage.json'), 'utf8'));
  assert.deepEqual(summary.fixture_coverage, matrix.fixture_coverage);
  assert.ok(Array.isArray(packets), 'legacy finding-packet consumers receive the original top-level array');
  assert.equal(packets.length, summary.finding_count);
  assert.equal(compareFindingPackets({ base: packets, candidate: packets }).totals.base, packets.length);
  assert.deepEqual(coverage, matrix.fixture_coverage);
});

function fixtureMatrixRunConfigTestValue(key, field) {
  if (field.boolean) return key === 'editorValidation' || key === 'visualParityGate' ? 'false' : 'true';
  if (field.list) return [`${key}-one`, ` ${key}-two `, `${key}-one`];
  if (field.enum) return field.enum.at(-1);
  if (field.string) return `/${key}`;
  if (field.integer) return String(field.integer.min === 0 ? 2 : 3);
  return '1.5';
}

function fixtureMatrixRunConfigFallbackValue(key, field) {
  if (field.boolean) return 'false';
  if (field.list) return `${key}-fallback`;
  if (field.enum) return field.enum[0];
  if (field.string) return `/fallback-${key}`;
  if (field.integer) return String(field.integer.min === 0 ? 1 : 2);
  return '2.5';
}

function fixtureMatrixExpectedProjection(config, projection) {
  const output = {};
  for (const [key, field] of Object.entries(FIXTURE_MATRIX_RUN_FIELDS)) {
    const target = field.projections?.[projection];
    if (!target) continue;
    const segments = target.split('.');
    const leaf = segments.pop();
    const parent = segments.reduce((value, segment) => (value[segment] ||= {}), output);
    parent[leaf] = config[key];
  }
  return output;
}

test('fixture selection fails closed for execution and keeps empty dry-run planning explicit', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-empty-selection-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'missing-entrypoint'), { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'metadata-warning'), { recursive: true });
  writeFileSync(path.join(fixtureRoot, 'metadata-warning', 'index.html'), '<h1>Fixture</h1>');
  writeFileSync(path.join(fixtureRoot, 'metadata-warning', 'fixture.json'), '{');
  symlinkSync(path.join(fixtureRoot, 'metadata-warning'), path.join(fixtureRoot, 'linked-fixture'));

  const plan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot, tag: 'absent' });
  assert.equal(plan.execution_eligible, false);
  assert.equal(plan.fixture_selection.status, 'planning_empty');
  assert.ok(plan.fixture_selection.exclusions.some((row) => row.reason === 'missing_entrypoint'));
  assert.ok(plan.fixture_selection.exclusions.some((row) => row.reason === 'symlink'));
  assert.ok(plan.fixture_selection.exclusions.some((row) => row.reason === 'filter_mismatch'));
  assert.ok(plan.fixture_selection.diagnostics.some((row) => row.reason === 'malformed_metadata'));

  const planned = await runFixtureMatrix({ fixtureRoot, outputDirectory: path.join(root, 'planned'), staticSiteImporterPath: staticSiteImporter, tag: 'absent', run: false });
  assert.equal(planned.summary.fixture_count, 0);
  await assert.rejects(
    runFixtureMatrix({ fixtureRoot, outputDirectory: path.join(root, 'executed'), staticSiteImporterPath: staticSiteImporter, tag: 'absent', run: true }),
    /requires a complete eligible fixture inventory/,
  );

  const nonDirectoryRoot = path.join(root, 'not-a-directory');
  writeFileSync(nonDirectoryRoot, 'fixture root file');
  const nonDirectoryPlan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot: nonDirectoryRoot });
  assert.equal(nonDirectoryPlan.execution_eligible, false);
  assert.equal(nonDirectoryPlan.fixture_selection.status, 'planning_empty');
  assert.equal(nonDirectoryPlan.fixture_selection.exclusions[0].reason, 'root_not_directory');
  const dryRun = spawnSync(process.execPath, [
    path.join(packageRoot, 'tools', 'run-fixture-matrix.mjs'),
    '--static-site-importer', staticSiteImporter,
    '--fixture-root', nonDirectoryRoot,
    '--dry-run',
  ], { encoding: 'utf8' });
  assert.equal(dryRun.status, 0, dryRun.stderr);
  assert.equal(JSON.parse(dryRun.stdout).fixture_selection.status, 'planning_empty');
});

test('missing and top-level symlink roots retain planning-empty selection semantics', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-invalid-root-planning-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const externalRoot = path.join(root, 'external-fixtures');
  const symlinkRoot = path.join(root, 'symlinked-fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(externalRoot, 'site'), { recursive: true });
  writeFileSync(path.join(externalRoot, 'site', 'index.html'), '<h1>External</h1>');
  symlinkSync(externalRoot, symlinkRoot);

  for (const [fixtureRoot, reason] of [[path.join(root, 'missing-fixtures'), 'root_missing'], [symlinkRoot, 'root_symlink']]) {
    const plan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot });
    assert.equal(plan.execution_eligible, false);
    assert.equal(plan.fixture_selection.status, 'planning_empty');
    assert.equal(plan.fixture_selection.exclusions[0].reason, reason);
  }
});

test('fixture selection reports one executable fixture and rejects typoed targets', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-one-selection-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'websites', 'valid-one'), { recursive: true });
  writeFileSync(path.join(fixtureRoot, 'websites', 'valid-one', 'index.html'), '<h1>Fixture</h1>');
  mkdirSync(path.join(fixtureRoot, 'websites', 'nested', 'valid-two'), { recursive: true });
  writeFileSync(path.join(fixtureRoot, 'websites', 'nested', 'valid-two', 'index.html'), '<h1>Nested fixture</h1>');

  const plan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot });
  assert.equal(plan.execution_eligible, true);
  assert.equal(plan.fixture_count, 2);
  assert.deepEqual(plan.fixture_selection.selected_fixture_ids, ['nested-valid-two', 'valid-one']);
  const nestedTargetPlan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot, targetFixture: 'nested-valid-two' });
  assert.deepEqual(nestedTargetPlan.fixture_selection.selected_fixture_ids, ['nested-valid-two']);
  assert.throws(
    () => buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot, targetFixture: 'typo' }),
    /--target-fixture "typo" was not found/,
  );
  const badRootPlan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot: path.join(root, 'missing') });
  assert.equal(badRootPlan.execution_eligible, false);
  assert.equal(badRootPlan.fixture_selection.exclusions[0].reason, 'root_missing');
});

test('fixture discovery rejects symlinked entrypoints and wrapper help explains empty selection behavior', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-symlink-entrypoint-'));
  const fixtureRoot = path.join(root, 'fixtures');
  const fixtureDirectory = path.join(fixtureRoot, 'linked-entrypoint');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(root, 'source.html'), '<h1>Source</h1>');
  symlinkSync(path.join(root, 'source.html'), path.join(fixtureDirectory, 'index.html'));

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot });
  assert.equal(matrix.count, 0);
  const help = spawnSync(process.execPath, [path.join(packageRoot, 'tools', 'run-fixture-matrix.mjs'), '--help'], { encoding: 'utf8' });
  assert.equal(help.status, 0, help.stderr);
  assert.match(help.stdout, /Empty selections\s+Execution is refused; --dry-run prints bounded selection diagnostics/);
});

test('fixture discovery rejects symlinked corpus roots and planning recursively counts nested fixtures', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-corpus-root-selection-'));
  const externalRoot = path.join(root, 'external-fixtures');
  const symlinkedCorpus = path.join(root, 'symlinked-corpus');
  mkdirSync(path.join(externalRoot, 'site'), { recursive: true });
  writeFileSync(path.join(externalRoot, 'site', 'index.html'), '<h1>External</h1>');
  mkdirSync(symlinkedCorpus, { recursive: true });
  symlinkSync(externalRoot, path.join(symlinkedCorpus, 'websites'));

  assert.deepEqual(resolveFixtureSearchRoots(symlinkedCorpus), [symlinkedCorpus]);
  assert.equal(createFixtureMatrix({ fixture_root: symlinkedCorpus }).count, 0);

  const fixtureRoot = path.join(root, 'fixtures');
  const staticSiteImporter = path.join(root, 'static-site-importer');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (let index = 1; index <= syntheticFixtureCount; index += 1) {
    const fixture = path.join(fixtureRoot, 'websites', 'nested', `fixture-${String(index).padStart(2, '0')}`);
    mkdirSync(fixture, { recursive: true });
    writeFileSync(path.join(fixture, 'index.html'), '<h1>Nested</h1>');
  }
  symlinkSync(externalRoot, path.join(fixtureRoot, 'solved'));

  assert.deepEqual(resolveFixtureSearchRoots(fixtureRoot), [path.join(fixtureRoot, 'websites')]);
  const plan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot });
  assert.equal(plan.active_fixture_count, syntheticFixtureCount);
  assert.equal(plan.solved_fixture_count, 0);
  assert.equal(plan.fixture_coverage.gate.status, 'passed');
  assert.equal(plan.fixture_selection.exclusions.some((row) => row.reason === 'missing_entrypoint'), false);
  assert.ok(plan.fixture_selection.exclusions.some((row) => row.reason === 'root_symlink'));
});

test('top-level symlink fixture roots stay planning-empty and never stage an external corpus', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-top-level-symlink-root-'));
  const externalRoot = path.join(root, 'external-fixtures');
  const fixtureRoot = path.join(root, 'fixture-root');
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const outputDirectory = path.join(root, 'artifacts');
  mkdirSync(path.join(externalRoot, 'external-site'), { recursive: true });
  mkdirSync(staticSiteImporter, { recursive: true });
  writeFileSync(path.join(externalRoot, 'external-site', 'index.html'), '<h1>External</h1>');
  symlinkSync(externalRoot, fixtureRoot);

  const plan = buildFixtureMatrixRunPlan({ staticSiteImporter, fixtureRoot });
  assert.equal(plan.execution_eligible, false);
  assert.equal(plan.fixture_selection.status, 'planning_empty');
  assert.equal(plan.fixture_selection.exclusions[0].reason, 'root_symlink');
  const dryRun = spawnSync(process.execPath, [path.join(packageRoot, 'tools', 'run-fixture-matrix.mjs'), '--static-site-importer', staticSiteImporter, '--fixture-root', fixtureRoot, '--dry-run'], { encoding: 'utf8' });
  assert.equal(dryRun.status, 0, dryRun.stderr);
  assert.equal(JSON.parse(dryRun.stdout).fixture_selection.exclusions[0].reason, 'root_symlink');
  const execution = spawnSync(process.execPath, [path.join(packageRoot, 'tools', 'run-fixture-matrix.mjs'), '--static-site-importer', staticSiteImporter, '--fixture-root', fixtureRoot, '--skip-install', '--skip-sync'], { encoding: 'utf8' });
  assert.equal(execution.status, 1);
  assert.match(execution.stderr, /requires at least one executable fixture/);

  const planned = await runFixtureMatrix({ fixtureRoot, outputDirectory, staticSiteImporterPath: staticSiteImporter, run: false });
  assert.equal(planned.summary.fixture_count, 0);
  assert.equal(existsSync(path.join(outputDirectory, 'external-site', 'artifact.json')), false);
  await assert.rejects(
    runFixtureMatrix({ fixtureRoot, outputDirectory, staticSiteImporterPath: staticSiteImporter, run: true }),
    /requires a complete eligible fixture inventory/,
  );
});

test('fixture matrix operator rejects contradictory local and Lab routing', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-routing-conflict-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

  assert.throws(() => buildFixtureMatrixRunPlan({
    local: true,
    runner: 'homeboy-lab',
    staticSiteImporter,
    fixtureRoot,
  }), /--local selects local placement and cannot be combined with --runner homeboy-lab/);

  assert.throws(() => buildFixtureMatrixRunPlan({
    local: true,
    labOnly: true,
    staticSiteImporter,
    fixtureRoot,
  }), /--local selects local placement and cannot be combined with --lab-only/);

  assert.throws(() => buildFixtureMatrixRunPlan({
    local: true,
    allowLocalFallback: true,
    staticSiteImporter,
    fixtureRoot,
  }), /--allow-local-fallback selects lab-or-local placement and cannot be combined with --local/);

  assert.throws(() => buildFixtureMatrixRunPlan({
    labOnly: true,
    allowLocalFallback: true,
    staticSiteImporter,
    fixtureRoot,
  }), /--allow-local-fallback selects lab-or-local placement and cannot be combined with --lab-only/);

  assert.throws(() => buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    allowLocalFallback: true,
    staticSiteImporter,
    fixtureRoot,
  }), /--allow-local-fallback selects unpinned lab-or-local placement and cannot be combined with --runner homeboy-lab/);
});

test('--solved-only selects exactly the valid solved fixture corpus', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-solved-only-selection-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'websites', 'active-site'), { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'solved', 'solved-b'), { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'solved', 'solved-a'), { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'solved', 'incomplete'), { recursive: true });
  writeFileSync(path.join(fixtureRoot, 'websites', 'active-site', 'index.html'), '<h1>Active</h1>');
  writeFileSync(path.join(fixtureRoot, 'solved', 'solved-a', 'index.html'), '<h1>Solved A</h1>');
  writeFileSync(path.join(fixtureRoot, 'solved', 'solved-b', 'index.html'), '<h1>Solved B</h1>');

  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot,
    solvedOnly: true,
    skipInstall: true,
    skipSync: true,
  });

  assert.deepEqual(plan.lane_filter.fixture_ids, ['solved-a', 'solved-b']);
  assert.equal(plan.lane_filter.fixture_corpus, 'solved');
  assert.equal(plan.solved_fixture_count, 2);
  assert.equal(plan.selected_active_fixture_count, 0);
  assert.equal(plan.selected_solved_fixture_count, 2);
  assert.ok(plan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_FIXTURE_CORPUS=solved'));

  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    fixture_ids: plan.lane_filter.fixture_ids,
    fixture_corpus: plan.lane_filter.fixture_corpus,
  });
  assert.equal(matrix.count, 2);
  assert.ok(matrix.fixtures.every((fixture) => fixture.fixture_corpus === 'solved'));
});

test('fixture matrix deterministic dry-run phase plans route setup locally and workload by mode', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-routing-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

  const setupPlan = buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    staticSiteImporter,
    fixtureRoot,
  });
  assert.deepEqual(setupPlan.steps.slice(0, -1).map((step) => step.args), [
    ['--placement', 'local', 'rig', 'install', path.dirname(path.dirname(fileURLToPath(import.meta.url))), '--id', 'static-site-importer-fixture-matrix', '--reinstall'],
    ['--placement', 'local', 'rig', 'sync', 'static-site-importer-fixture-matrix'],
  ]);
  assert.deepEqual(setupPlan.phase_plan, {
    schema: FIXTURE_MATRIX_PHASE_PLAN_SCHEMA,
    phases: setupPlan.steps.map(({ phase, placement, resolved_placement, reason, retry_command }) => ({
      phase,
      placement,
      resolved_placement,
      reason,
      retry_command,
    })),
  });
  assert.ok(setupPlan.steps.slice(0, -1).every((step) => step.phase === 'controller-setup' && step.placement === 'auto' && step.resolved_placement === 'controller-local'));
  assert.ok(setupPlan.steps.slice(0, -1).every((step) => step.args[0] === '--placement' && step.args[1] === 'local'));
  assert.equal(setupPlan.steps.at(-1).phase, 'fixture-workload');
  assert.equal(setupPlan.steps.at(-1).resolved_placement, 'lab:homeboy-lab');

  const labPlan = buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  const labArgs = labPlan.steps.at(-1).args;
  assert.equal(labPlan.execution_target, 'lab:homeboy-lab');
  assert.equal(labPlan.placement, 'lab');
  assert.equal(labPlan.shared_state, '');
  assert.equal(labPlan.artifact_root, '');
  assert.match(labPlan.steps.at(-1).label, /lab:homeboy-lab/);
  assert.deepEqual(labArgs.slice(-2), ['--runner', 'homeboy-lab']);
  assert.equal(labArgs.includes('--shared-state'), false);
  assert.equal(labArgs.includes('--artifact-root'), false);

  const explicitMacTempLabPlan = buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    staticSiteImporter,
    fixtureRoot,
    sharedState: '/var/folders/ab/example/shared-state',
    artifactRoot: '/private/var/folders/ab/example/artifacts',
    skipInstall: true,
    skipSync: true,
  });
  assert.ok(explicitMacTempLabPlan.steps.at(-1).args.includes('/var/folders/ab/example/shared-state'));
  assert.ok(explicitMacTempLabPlan.steps.at(-1).args.includes('/private/var/folders/ab/example/artifacts'));
  const explicitMacTempWarningCodes = explicitMacTempLabPlan.warnings.map((warning) => warning.code);
  assert.ok(explicitMacTempWarningCodes.includes('lab_local_shared_state_path'));
  assert.ok(explicitMacTempWarningCodes.includes('lab_local_artifact_root_path'));

  const localPlan = buildFixtureMatrixRunPlan({
    local: true,
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  const localArgs = localPlan.steps.at(-1).args;
  assert.equal(localPlan.execution_target, 'local');
  assert.equal(localPlan.placement, 'local');
  assert.match(localPlan.steps.at(-1).label, /local/);
  assert.deepEqual(localArgs.slice(-2), ['--placement', 'local']);
  assert.equal(localArgs.includes('--runner'), false);

  const runnerLocalPlan = buildFixtureMatrixRunPlan({
    runner: 'local',
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  const runnerLocalArgs = runnerLocalPlan.steps.at(-1).args;
  assert.equal(runnerLocalPlan.execution_target, 'local');
  assert.equal(runnerLocalPlan.runner, '');
  assert.equal(runnerLocalPlan.local, true);
  assert.deepEqual(runnerLocalArgs.slice(-2), ['--placement', 'local']);
  assert.equal(runnerLocalArgs.includes('--runner'), false);

  const labOnlyPlan = buildFixtureMatrixRunPlan({
    labOnly: true,
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(labOnlyPlan.execution_target, 'lab');
  assert.deepEqual(labOnlyPlan.steps.at(-1).args.slice(-2), ['--placement', 'lab']);

  const unnamedFallbackPlan = buildFixtureMatrixRunPlan({
    allowLocalFallback: true,
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(unnamedFallbackPlan.execution_target, 'lab-or-local');
  assert.deepEqual(unnamedFallbackPlan.steps.at(-1).args.slice(-2), ['--placement', 'lab-or-local']);
  assert.ok(!unnamedFallbackPlan.warnings.some((warning) => warning.code === 'lab_auto_offload_risk'));

  const autoPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(autoPlan.execution_target, 'auto');
  assert.deepEqual(autoPlan.steps.at(-1).args.slice(-2), ['--placement', 'auto']);
  assert.equal(autoPlan.steps.at(-1).resolved_placement, 'auto');
});

test('fixture matrix phase retries identify setup ownership and the exact command', () => {
  const setup = {
    phase: 'controller-setup',
    label: 'Sync/materialize rig components (lab:homeboy-lab)',
    command: 'homeboy',
    args: ['rig', 'sync', 'static-site-importer-fixture-matrix'],
  };
  assert.equal(
    phaseFailureDiagnostic(setup, 17),
    'Sync/materialize rig components (lab:homeboy-lab) failed with exit 17 during controller-setup. Retry: homeboy rig sync static-site-importer-fixture-matrix',
  );
});

test('fixture matrix operator projects exact Homeboy arguments for every routing mode', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-routing-projection-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

  const cases = [
    ['auto', {}, ['--placement', 'auto']],
    ['explicit runner', { runner: 'homeboy-lab' }, ['--runner', 'homeboy-lab']],
    ['local', { local: true }, ['--placement', 'local']],
    ['runner local alias', { runner: 'local' }, ['--placement', 'local']],
    ['lab only', { labOnly: true }, ['--placement', 'lab']],
    ['local fallback', { allowLocalFallback: true }, ['--placement', 'lab-or-local']],
  ];

  for (const [name, routing, expected] of cases) {
    const args = buildFixtureMatrixRunPlan({
      ...routing,
      staticSiteImporter,
      fixtureRoot,
      skipInstall: true,
      skipSync: true,
    }).steps.at(-1).args;
    const start = args.findIndex((arg) => arg === '--placement' || arg === '--runner');
    assert.deepEqual(args.slice(start), expected, name);
  }
});

test('fixture matrix operator plan forwards complexity lane settings', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-complexity-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const laneFixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(laneFixtureRoot, 'fixture-a'), { recursive: true });

  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: laneFixtureRoot,
    complexity: '2',
    maxComplexity: '3',
    skipInstall: true,
    skipSync: true,
  });
  const benchArgs = plan.steps.at(-1).args;

  assert.deepEqual(plan.lane_filter, { complexity: '2', max_complexity: '3' });
  assert.ok(benchArgs.includes('bench_env.SSI_FIXTURE_MATRIX_COMPLEXITY=2'));
  assert.ok(benchArgs.includes('bench_env.SSI_FIXTURE_MATRIX_MAX_COMPLEXITY=3'));
});

test('fixture matrix operator preserves exact-zero visual thresholds', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-zero-visual-threshold-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const zeroThresholdFixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(zeroThresholdFixtureRoot, 'fixture-a'), { recursive: true });

  const benchArgs = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: zeroThresholdFixtureRoot,
    pixelThreshold: '0',
    visualParityPixelmatchThreshold: '0',
    visualParityMaxVerticalShift: '0',
    visualParityMaxHorizontalShift: '0',
    skipInstall: true,
    skipSync: true,
  }).steps.at(-1).args;

  for (const setting of [
    'bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD=0',
    'bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXELMATCH_THRESHOLD=0',
    'bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_VERTICAL_SHIFT=0',
    'bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_HORIZONTAL_SHIFT=0',
  ]) {
    assert.ok(benchArgs.includes(setting), `${setting} must survive CLI normalization`);
  }
});

test('fixture matrix records generic child command failures for failed WP Codebox batches', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-codebox-failure-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const failureFixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const helperPath = path.join(root, 'wp-codebox-recipe-helper.cjs');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(failureFixtureRoot, 'failing-fixture'), { recursive: true });
  writeFileSync(path.join(failureFixtureRoot, 'failing-fixture', 'index.html'), '<h1>Failing fixture</h1>');
  writeFileSync(helperPath, `
function wpCodeboxBin() { return '/tmp/wp-codebox'; }
function wpCodeboxCommand(bin) { return { command: bin, args: [] }; }
async function runWpCodeboxRecipe(options) {
  if (options.cwd !== require('node:path').dirname(options.outputFile)) {
    throw new Error('recipe-run did not receive the matrix output directory as cwd');
  }
  const error = new Error('recipe-run failed');
  error.code = 17;
  error.signal = 'SIGKILL';
  error.stdout = 'stdout line 1\\nstdout line 2';
  error.stderr = 'stderr line 1\\nstderr line 2';
  throw error;
}
module.exports = { wpCodeboxBin, wpCodeboxCommand, runWpCodeboxRecipe };
`, 'utf8');
  const previousHelper = process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER;
  const previousFixtureRoot = process.env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT;
  const previousOutputDirectory = process.env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY;
  const previousImporterPath = process.env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH;
  const previousRun = process.env.SSI_FIXTURE_MATRIX_RUN;
  const previousBatchSize = process.env.SSI_FIXTURE_MATRIX_BATCH_SIZE;
  const previousVisualParityFullPage = process.env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE;
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = helperPath;

  try {
    const { summary, runtimeError } = await runFixtureMatrix({
      fixtureRoot: failureFixtureRoot,
      outputDirectory,
      staticSiteImporterPath: staticSiteImporter,
      run: true,
      batchSize: 1,
    });
    const failure = summary.runtime.child_command_failures[0];

    // The child's raw failure cause propagates as the runtime error message. The
    // child's real stderr + stdout tails are surfaced for attribution on the
    // structured child-command failure below (`stderr_tail`/`stdout_tail`).
    // Folding those tails into the Error *message* (#560) now lives in the
    // production WP Codebox recipe helper (quarantined behind tools/wp-codebox
    // in PR #573), which this test mocks, so the rig path keeps the bare cause.
    assert.match(runtimeError.message, /^recipe-run failed/);
    assert.equal(summary.runtime.exit_code, 17);
    assert.equal(failure.schema, 'homeboy/child-command-failure/v1');
    assert.equal(failure.exit_status, 17);
    assert.equal(failure.error_code, 17);
    assert.equal(failure.error_signal, 'SIGKILL');
    assert.equal(failure.batch_id, 'batch-001');
    const expectedCodeboxArtifactsDirectory = path.join(root, 'artifacts-wp-codebox-batch-001-recovery-failing-fixture-artifacts');
    assert.deepEqual(failure.command_argv, [
      '/tmp/wp-codebox',
      'recipe-run',
      '--recipe',
      failure.artifact_refs.batch_recipe,
      '--artifacts', expectedCodeboxArtifactsDirectory,
      '--output', failure.artifact_refs.batch_output,
      '--json',
    ]);
    assert.equal(failure.command, failure.command_argv.join(' '));
    assert.equal(failure.stdout_tail, 'stdout line 1\nstdout line 2');
    assert.equal(failure.stderr_tail, 'stderr line 1\nstderr line 2');
    assert.equal(failure.artifact_refs.artifacts_directory, expectedCodeboxArtifactsDirectory);
    assert.equal(failure.artifact_refs.fixture_artifacts_directory, outputDirectory);
    assert.equal(failure.artifact_refs.codebox_artifacts_directory, expectedCodeboxArtifactsDirectory);
    assert.equal(path.dirname(expectedCodeboxArtifactsDirectory), path.dirname(outputDirectory));
    assert.equal(expectedCodeboxArtifactsDirectory.startsWith(`${outputDirectory}${path.sep}`), false);
    assert.equal(failure.artifact_refs.output_file, failure.artifact_refs.batch_output);
    assert.ok(readFileSync(path.join(outputDirectory, 'cli-run.json'), 'utf8').includes('child_command_failures'));

    process.env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT = failureFixtureRoot;
    process.env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY = path.join(root, 'bench-export-artifacts');
    process.env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH = staticSiteImporter;
    process.env.SSI_FIXTURE_MATRIX_RUN = '1';
    process.env.SSI_FIXTURE_MATRIX_BATCH_SIZE = '1';
    process.env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE = '1';
    // A failing batch must NOT make the bench reject: rejecting makes the harness
    // discard the whole lane as an assertion_failure. Instead the bench returns
    // the aggregate with the failed fixture counted (so the
    // `failed_fixture_count <= 0` result-gate fails the run without discarding it)
    // and keeps the child-command failure in metadata for attribution.
    const benchResult = await runFixtureMatrixBench();
    assert.equal(benchResult.metrics.fixture_count, 1);
    assert.equal(benchResult.metrics.passed_fixture_count, 0);
    assert.equal(benchResult.metrics.failed_fixture_count, 1);
    assert.equal(benchResult.metadata.child_command_failures[0].exit_status, 17);
    assert.equal(benchResult.metadata.child_command_failures[0].error_signal, 'SIGKILL');
    assert.equal(
      benchResult.metadata.child_command_failures[0].artifact_refs.artifacts_directory,
      `${process.env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY}-wp-codebox-batch-001-recovery-failing-fixture-artifacts`,
    );
    const benchBatchRecipe = JSON.parse(readFileSync(benchResult.metadata.child_command_failures[0].artifact_refs.batch_recipe, 'utf8'));
    const benchVisualStep = benchBatchRecipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
    assert.equal(visualCompareMatrixComparison(benchVisualStep).fullPage, true, 'bench defaults visual parity to full-page screenshots');
  } finally {
    if (previousHelper === undefined) {
      delete process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER;
    } else {
      process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = previousHelper;
    }
    restoreEnv('SSI_FIXTURE_MATRIX_FIXTURE_ROOT', previousFixtureRoot);
    restoreEnv('SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY', previousOutputDirectory);
    restoreEnv('SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH', previousImporterPath);
    restoreEnv('SSI_FIXTURE_MATRIX_RUN', previousRun);
    restoreEnv('SSI_FIXTURE_MATRIX_BATCH_SIZE', previousBatchSize);
    restoreEnv('SSI_FIXTURE_MATRIX_VISUAL_PARITY_FULL_PAGE', previousVisualParityFullPage);
  }
});

test('WP Codebox recipe runner streams oversized child output and reads result JSON from --output', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-codebox-large-output-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const largeOutputFixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const fakeCodeboxBin = path.join(root, 'fake-wp-codebox.mjs');
  const fixtureId = 'large-output-fixture';
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(largeOutputFixtureRoot, fixtureId), { recursive: true });
  writeFileSync(path.join(largeOutputFixtureRoot, fixtureId, 'index.html'), '<h1>Large output fixture</h1>');
  writeFileSync(fakeCodeboxBin, `#!/usr/bin/env node
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
const outputIndex = process.argv.indexOf('--output');
const outputFile = outputIndex >= 0 ? process.argv[outputIndex + 1] : '';
const fixtureId = process.env.SSI_TEST_FAKE_WP_CODEBOX_FIXTURE_ID || 'large-output-fixture';
if (outputFile) {
  writeFileSync(outputFile, JSON.stringify({ cwd: process.cwd(), results: [{ fixture_id: fixtureId, status: 'succeeded' }] }));
}
const chunk = 'stdout chunk '.padEnd(1024 * 1024, 'x');
for (let index = 0; index < 12; index += 1) {
  process.stdout.write(chunk);
}
`, 'utf8');
  chmodSync(fakeCodeboxBin, 0o755);

  const { summary, runtimeError } = await runFixtureMatrix({
    fixtureRoot: largeOutputFixtureRoot,
    outputDirectory,
    staticSiteImporterPath: staticSiteImporter,
    run: true,
    batchSize: 1,
    visualParity: false,
    wpCodeboxBin: fakeCodeboxBin,
  });

  assert.equal(runtimeError, null);
  assert.equal(summary.result_summary.succeeded, 1);
  assert.equal(summary.child_command_failures?.length || 0, 0);
  const childOutput = JSON.parse(readFileSync(path.join(outputDirectory, 'wp-codebox-output-batch-001.json'), 'utf8'));
  assert.equal(realpathSync(childOutput.cwd), realpathSync(outputDirectory));
  assert.deepEqual(childOutput.results, [{ fixture_id: fixtureId, status: 'succeeded' }]);
});

test('WP Codebox recipe runner falls back when the CLI rejects recipe-run --output', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-codebox-output-fallback-'));
  const outputFile = path.join(root, 'wp-codebox-output.json');
  const recipeFile = path.join(root, 'recipe.json');
  const artifactsDir = path.join(root, 'artifacts');
  const fakeCodeboxBin = path.join(root, 'fake-wp-codebox-no-output.mjs');
  mkdirSync(artifactsDir, { recursive: true });
  writeFileSync(recipeFile, '{}');
  writeFileSync(fakeCodeboxBin, `#!/usr/bin/env node
if (process.argv.includes('--output')) {
  process.stderr.write('Unknown option: --output\\n');
  process.exit(1);
}
const payload = JSON.stringify({ results: [{ fixture_id: 'fallback-fixture', status: 'succeeded' }] });
process.stdout.write(payload);
`, 'utf8');
  chmodSync(fakeCodeboxBin, 0o755);

  const result = await runWpCodeboxRecipe({ recipeFile, artifactsDir, outputFile, wpCodeboxBin: fakeCodeboxBin });

  assert.deepEqual(result.json, { results: [{ fixture_id: 'fallback-fixture', status: 'succeeded' }] });
  assert.deepEqual(JSON.parse(readFileSync(outputFile, 'utf8')), result.json);
});

test('WP Codebox recipe runner keeps bounded tails when oversized child output fails', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-codebox-large-failure-'));
  const outputFile = path.join(root, 'wp-codebox-output.json');
  const recipeFile = path.join(root, 'recipe.json');
  const artifactsDir = path.join(root, 'artifacts');
  const fakeCodeboxBin = path.join(root, 'fake-wp-codebox-fail.mjs');
  mkdirSync(artifactsDir, { recursive: true });
  writeFileSync(recipeFile, '{}');
  writeFileSync(fakeCodeboxBin, `#!/usr/bin/env node
import { writeFileSync } from 'node:fs';
const outputIndex = process.argv.indexOf('--output');
const outputFile = outputIndex >= 0 ? process.argv[outputIndex + 1] : '';
if (outputFile) {
  writeFileSync(outputFile, JSON.stringify({ results: [] }));
}
const stdoutChunk = 'stdout chunk '.padEnd(1024 * 1024, 'x');
const stderrChunk = 'stderr chunk '.padEnd(1024 * 1024, 'y');
for (let index = 0; index < 12; index += 1) {
  process.stdout.write(stdoutChunk);
  process.stderr.write(stderrChunk);
}
process.exit(23);
`, 'utf8');
  chmodSync(fakeCodeboxBin, 0o755);

  await assert.rejects(
    runWpCodeboxRecipe({ recipeFile, artifactsDir, outputFile, wpCodeboxBin: fakeCodeboxBin }),
    (error) => {
      assert.equal(error.code, 23);
      assert.equal(error.signal, '');
      assert.ok(error.stdout.length <= 64 * 1024);
      assert.ok(error.stderr.length <= 64 * 1024);
      assert.match(error.message, /^wp-codebox recipe-run failed with exit 23/);
      return true;
    },
  );
  assert.deepEqual(JSON.parse(readFileSync(outputFile, 'utf8')), { results: [] });
});

test('CLI --no-visual-parity disables visual steps and records a safe WP Codebox replay command', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-focused-codebox-replay-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const cliFixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(cliFixtureRoot, 'fixture-a'), { recursive: true });
  writeFileSync(path.join(cliFixtureRoot, 'fixture-a', 'index.html'), '<h1>Focused replay fixture</h1>');

  const result = spawnSync(process.execPath, [
    path.join(packageRoot, 'bench', 'static-site-fixture-matrix.bench.mjs'),
    '--fixture-root', cliFixtureRoot,
    '--output-directory', outputDirectory,
    '--static-site-importer-path', staticSiteImporter,
    '--max-depth', '1',
    '--no-visual-parity',
  ], {
    encoding: 'utf8',
    env: {
      ...process.env,
      HOMEBOY_WP_CODEBOX_RECIPE_HELPER: '',
      HOMEBOY_WP_CODEBOX_BIN: '',
      SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN: '',
      WP_CODEBOX_BIN: '',
    },
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(result.stdout, /"replay"/);

  const recipeFile = path.join(outputDirectory, 'wp-codebox-static-site-fixture-matrix-recipe.json');
  const recipe = JSON.parse(readFileSync(recipeFile, 'utf8'));
  const summary = JSON.parse(readFileSync(path.join(outputDirectory, 'cli-run.json'), 'utf8'));
  assert.equal(recipe.workflow.steps.some((step) => step.command === 'wordpress.visual-compare'), false);
  assert.equal(summary.replay.artifacts_directory, path.join(root, 'artifacts-wp-codebox-replay-artifacts'));
  assert.equal(summary.replay.artifacts_directory.startsWith(`${outputDirectory}${path.sep}`), false);
  assert.deepEqual(summary.replay.argv, [
    'wp-codebox',
    'recipe-run',
    '--recipe', recipeFile,
    '--artifacts', summary.replay.artifacts_directory,
    '--json',
  ]);
  assert.match(summary.replay.command, /wp-codebox recipe-run --recipe .* --artifacts .* --json/);
});

test('CLI surface coverage reaches bench recipe browser evidence steps', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-surface-coverage-cli-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const cliFixtureRoot = path.join(root, 'fixtures');
  const fixtureDirectory = path.join(cliFixtureRoot, 'artist');
  const outputDirectory = path.join(root, 'artifacts');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'index.html'), '<h1>Home</h1>');
  writeFileSync(path.join(fixtureDirectory, 'contact.html'), '<h1>Contact</h1>');
  writeFileSync(path.join(fixtureDirectory, 'merch.html'), '<h1>Merch</h1>');

  const result = spawnSync(process.execPath, [
    path.join(packageRoot, 'bench', 'static-site-fixture-matrix.bench.mjs'),
    '--fixture-root', cliFixtureRoot,
    '--output-directory', outputDirectory,
    '--static-site-importer-path', staticSiteImporter,
    '--max-depth', '1',
    '--surface-coverage', '2',
    '--animated-media', 'first-frame',
  ], {
    encoding: 'utf8',
    env: {
      ...process.env,
      HOMEBOY_WP_CODEBOX_RECIPE_HELPER: '',
      HOMEBOY_WP_CODEBOX_BIN: '',
      SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN: '',
      WP_CODEBOX_BIN: '',
    },
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  const recipe = JSON.parse(readFileSync(path.join(outputDirectory, 'wp-codebox-static-site-fixture-matrix-recipe.json'), 'utf8'));
  const editorOpenSteps = recipe.workflow.steps.filter((step) => step.command === 'wordpress.editor-open');
  const visualSteps = recipe.workflow.steps.filter((step) => step.command === 'wordpress.visual-compare');
  assert.equal(editorOpenSteps.length, 3);
  assert.equal(visualSteps.length, 3);
  assert.ok(editorOpenSteps[1].args.includes('post-type=page'));
  assert.ok(editorOpenSteps[1].args.includes('post-slug=contact'));
  assert.ok(editorOpenSteps[2].args.includes('post-slug=merch'));
  assert.equal(visualCompareMatrixComparison(visualSteps[2]).candidateUrl, '/merch/');
  for (const visualStep of visualSteps) {
    assert.equal(visualCompareMatrixComparison(visualStep).animatedMedia, 'first-frame');
  }
  const summary = JSON.parse(readFileSync(path.join(outputDirectory, 'cli-run.json'), 'utf8'));
  assert.equal(summary.metadata.animated_media, 'first-frame');
});

test('CLI rejects an unsupported animated media policy before recipe generation', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-animated-media-invalid-cli-'));
  const outputDirectory = path.join(root, 'artifacts');
  const result = spawnSync(process.execPath, [
    path.join(packageRoot, 'bench', 'static-site-fixture-matrix.bench.mjs'),
    '--output-directory', outputDirectory,
    '--animated-media', 'frame-2',
  ], { encoding: 'utf8' });

  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /animated-media must be allow or first-frame/);
  assert.equal(existsSync(path.join(outputDirectory, 'wp-codebox-static-site-fixture-matrix-recipe.json')), false);
});

test('runFixtureMatrix surface coverage reaches executed batch recipes', async () => {
  const snapshot = snapshotConcurrencyEnv();
  const workspace = setupConcurrencyWorkspace('ssi-surface-coverage-batch-', 0);
  const fixtureDirectory = path.join(workspace.fixtureRoot, 'artist');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'index.html'), '<h1>Home</h1>');
  writeFileSync(path.join(fixtureDirectory, 'contact.html'), '<h1>Contact</h1>');
  writeFileSync(path.join(fixtureDirectory, 'merch.html'), '<h1>Merch</h1>');
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = workspace.helperPath;

  try {
    const { summary, runtimeError } = await runFixtureMatrix({
      id: 'surface-batch-matrix',
      fixtureRoot: workspace.fixtureRoot,
      outputDirectory: workspace.outputDirectory,
      staticSiteImporterPath: workspace.staticSiteImporter,
      run: true,
      batchSize: 1,
      concurrency: 1,
      surfaceCoverage: 2,
      runtimePresentationEvidence: true,
    });

    assert.equal(runtimeError, null);
    const batchRecipe = JSON.parse(readFileSync(summary.runtime.batches[0].recipe_file, 'utf8'));
    const editorOpenSteps = batchRecipe.workflow.steps.filter((step) => step.command === 'wordpress.editor-open');
    const visualSteps = batchRecipe.workflow.steps.filter((step) => step.command === 'wordpress.visual-compare');
    const probeIndex = batchRecipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'runtime-presentation-evidence');
    const mergeIndex = batchRecipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'runtime-presentation-evidence-merge');
    const importIndex = batchRecipe.workflow.steps.findIndex((step) => step.metadata?.phase === 'import');
    assert.equal(editorOpenSteps.length, 3);
    assert.equal(visualSteps.length, 3);
    assert.ok(editorOpenSteps[1].args.includes('post-type=page'));
    assert.ok(editorOpenSteps[1].args.includes('post-slug=contact'));
    assert.ok(editorOpenSteps[2].args.includes('post-slug=merch'));
    assert.equal(visualCompareMatrixComparison(visualSteps[1]).candidateUrl, '/contact/');
    assert.equal(visualCompareMatrixComparison(visualSteps[2]).candidateUrl, '/merch/');
    assert.ok(probeIndex >= 0);
    assert.ok(mergeIndex > probeIndex);
    assert.ok(importIndex > mergeIndex);
  } finally {
    restoreConcurrencyEnv(snapshot);
  }
});

function visualCompareMatrixComparison(step) {
  const matrixArg = step.args.find((arg) => typeof arg === 'string' && arg.startsWith('matrix-json='));
  assert.ok(matrixArg, 'expected wordpress.visual-compare to use matrix-json');
  const matrix = JSON.parse(matrixArg.slice('matrix-json='.length));
  assert.equal(matrix.comparisons.length, 1);
  return matrix.comparisons[0];
}

function fakeGitRunner(stateByPath) {
  return (cwd, args) => {
    const state = stateByPath[path.resolve(cwd)];
    if (!state) {
      return { status: 1, stdout: '', stderr: 'not a git repo' };
    }
    const joined = args.join(' ');
    if (joined === 'rev-parse --is-inside-work-tree') {
      return { status: 0, stdout: 'true', stderr: '' };
    }
    if (joined === 'rev-parse --abbrev-ref HEAD') {
      return { status: 0, stdout: state.branch || 'trunk', stderr: '' };
    }
    if (joined === 'rev-parse HEAD') {
      return { status: 0, stdout: state.commit || 'deadbeef', stderr: '' };
    }
    if (joined === 'status --porcelain') {
      return { status: 0, stdout: state.dirty ? ' M file.php' : '', stderr: '' };
    }
    if (joined === 'rev-parse --abbrev-ref --symbolic-full-name @{upstream}') {
      return state.upstream
        ? { status: 0, stdout: state.upstream, stderr: '' }
        : { status: 128, stdout: '', stderr: 'no upstream' };
    }
    if (args[0] === 'rev-list') {
      return { status: 0, stdout: `${state.behind || 0}\t${state.ahead || 0}`, stderr: '' };
    }
    return { status: 1, stdout: '', stderr: 'unhandled git command' };
  };
}

test('code freshness guard blocks stale overrides unless explicitly allowed', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-freshness-stale-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const blocksEngine = path.join(root, 'blocks-engine');
  const staleFixtureRoot = path.join(blocksEngine, 'fixtures', 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(staleFixtureRoot, 'fixture-a'), { recursive: true });

  const gitRunner = fakeGitRunner({
    [path.resolve(blocksEngine)]: { branch: 'trunk', upstream: 'origin/trunk', behind: 33, ahead: 0, commit: 'staleabc' },
    [path.resolve(staticSiteImporter)]: { branch: 'main', upstream: 'origin/main', behind: 0, ahead: 0, commit: 'freshxyz' },
  });

  const stalePlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    runId: 'ssi-freshness-stale',
    skipInstall: true,
    skipSync: true,
    gitRunner,
  });

  assert.equal(stalePlan.code_freshness.would_block, true);
  assert.deepEqual(stalePlan.code_freshness.stale_overrides, ['blocks_engine_php_transformer_path']);
  assert.equal(stalePlan.code_freshness.paths.blocks_engine_php_transformer_path.status, 'behind');
  assert.equal(stalePlan.code_freshness.paths.blocks_engine_php_transformer_path.behind, 33);
  assert.equal(stalePlan.code_freshness.paths.static_site_importer.status, 'fresh');
  assert.equal(stalePlan.transformer_commit, 'staleabc');
  assert.ok(stalePlan.warnings.some((warning) => warning.code === 'stale_override'));
  assert.equal(stalePlan.warnings.some((warning) => warning.code === 'stale_override_allowed'), false);

  const allowedPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    runId: 'ssi-freshness-stale-allowed',
    skipInstall: true,
    skipSync: true,
    allowStaleOverride: true,
    gitRunner,
  });

  assert.equal(allowedPlan.code_freshness.would_block, true);
  assert.equal(allowedPlan.allow_stale_override, true);
  assert.ok(allowedPlan.warnings.some((warning) => warning.code === 'stale_override_allowed'));
});

test('code freshness guard lets fresh and diverged overrides through with accurate status', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-freshness-fresh-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const blocksEngine = path.join(root, 'blocks-engine');
  const freshFixtureRoot = path.join(blocksEngine, 'fixtures', 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(freshFixtureRoot, 'fixture-a'), { recursive: true });

  const transformerReference = 'b'.repeat(40);
  const gitRunner = fakeGitRunner({
    [path.resolve(blocksEngine)]: { branch: 'trunk', upstream: 'origin/trunk', behind: 0, ahead: 2, commit: transformerReference },
    [path.resolve(staticSiteImporter)]: { branch: 'main', upstream: 'origin/main', behind: 0, ahead: 0, commit: 'freshcommit' },
  });
  const freshPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    runId: 'ssi-freshness-fresh',
    skipInstall: true,
    skipSync: true,
    dependencyOverlayReferences: true,
    gitRunner,
  });

  assert.equal(freshPlan.code_freshness.would_block, false);
  assert.deepEqual(freshPlan.code_freshness.stale_overrides, []);
  assert.equal(freshPlan.code_freshness.paths.blocks_engine_php_transformer_path.status, 'ahead');
  assert.equal(freshPlan.dependency_overrides.blocks_engine_php_transformer.reference, transformerReference);
  assert.ok(freshPlan.steps.at(-1).args.includes(`bench_env.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_REFERENCE=${transformerReference}`));
  assert.equal(freshPlan.warnings.some((warning) => warning.code === 'stale_override'), false);

  const compatiblePlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    runId: 'ssi-freshness-compatible',
    skipInstall: true,
    skipSync: true,
    gitRunner,
  });
  assert.equal(compatiblePlan.dependency_overrides.blocks_engine_php_transformer.reference, undefined);
  assert.equal(compatiblePlan.steps.at(-1).args.some((arg) => arg.includes('SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_REFERENCE')), false);

  const diverged = resolvePathFreshness(
    'blocks_engine_php_transformer_path',
    blocksEngine,
    fakeGitRunner({
      [path.resolve(blocksEngine)]: { branch: 'trunk', upstream: 'origin/trunk', behind: 5, ahead: 3, dirty: true, commit: 'divergedc' },
    }),
  );
  assert.equal(diverged.status, 'diverged');
  assert.equal(diverged.stale, true);
  assert.equal(diverged.dirty, true);
});

test('code freshness marks non-git override paths without blocking', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-freshness-nongit-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const blocksEngine = path.join(root, 'blocks-engine');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(blocksEngine, 'fixtures', 'websites', 'fixture-a'), { recursive: true });

  const freshness = buildCodeFreshness(
    {
      staticSiteImporter,
      blocksEngine,
      blocksEnginePhpTransformerPath: blocksEngine,
    },
    fakeGitRunner({}),
  );

  assert.equal(freshness.would_block, false);
  assert.equal(freshness.paths.blocks_engine_php_transformer_path.in_git_repo, false);
  assert.equal(freshness.paths.blocks_engine_php_transformer_path.status, 'not_git');
});

function restoreEnv(key, value) {
  if (value === undefined) {
    delete process.env[key];
  } else {
    process.env[key] = value;
  }
}

test('fixture matrix dry-run plan surfaces local fallback and dirty workspace warnings', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-warning-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const warningFixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(warningFixtureRoot, 'fixture-a'), { recursive: true });

  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: warningFixtureRoot,
    runId: 'proof/run 1',
    allowLocalFallback: true,
    allowDirtyLabWorkspace: true,
    skipInstall: true,
    skipSync: true,
  });

  assert.equal(plan.namespace, 'proof-run-1');
  assert.equal(plan.temp_root, '/tmp/static-site-importer-fixture-matrix-proof-run-1');
  // Explicit fallback resolves to lab-or-local rather than auto.
  assert.deepEqual(plan.warnings.map((warning) => warning.code), [
    'local_fallback_allowed',
    'dirty_lab_workspace_allowed',
  ]);
  assert.equal(plan.fixture_coverage.gate.status, 'passed');
});

test('--local selects typed local placement and suppresses the auto-placement warning', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-local-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const localFixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(localFixtureRoot, 'fixture-a'), { recursive: true });

  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: localFixtureRoot,
    local: true,
    skipInstall: true,
    skipSync: true,
  });

  // The auto-placement warning is gone; the local-placement note replaces it.
  const codes = plan.warnings.map((warning) => warning.code);
  assert.ok(!codes.includes('lab_auto_offload_risk'));
  assert.ok(codes.includes('forced_local_execution'));
  assert.equal(plan.local, true);

  // The bench step carries typed local placement so local-only paths stay local.
  const benchStep = plan.steps.at(-1);
  assert.deepEqual(benchStep.args.slice(-2), ['--placement', 'local']);
});

test('operator summary preserves matrix rollups for fanout agents', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-operator-summary-'));
  const outputFile = path.join(root, 'homeboy-bench.json');
  writeFileSync(outputFile, JSON.stringify({
    run_id: 'ssi-matrix-rollup-proof',
    result_summary: {
      failed: 71,
      finding_count: 1126,
      groups: { runtime_target_gap: 806 },
      top_pattern_families: [
        { key: 'runtime_target_gap:runtime_dependency_missing_dom_target:canvas', count: 312, fixture_ids: ['shader-site'] },
      ],
      fixture_exemplars: [
        { fixture_id: 'shader-site', selector: 'canvas', reason: 'Runtime target missing.' },
      ],
      diagnostic_blind_spots: [
        { kind: 'missing_source_context', count: 12 },
      ],
    },
  }));

  const summary = summarizeRun({
    mode: 'development-override',
    run_id: 'planned-run',
    fixture_count: 71,
    output_file: outputFile,
  });

  assert.equal(summary.run_id, 'ssi-matrix-rollup-proof');
  assert.deepEqual(summary.run_refs, {
    homeboy_run_id: 'ssi-matrix-rollup-proof',
    show: 'homeboy runs show ssi-matrix-rollup-proof',
    artifacts: 'homeboy runs artifacts ssi-matrix-rollup-proof',
  });
  assert.equal(summary.top_pattern_families[0].count, 312);
  assert.equal(summary.fixture_exemplars[0].fixture_id, 'shader-site');
  assert.equal(summary.diagnostic_blind_spots[0].kind, 'missing_source_context');
  assert.equal(summary.lane_identity, null);
});

test('summarizeBenchRun emits the operator summary on a gate-FAIL instead of throwing', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-bench-gate-fail-'));
  const outputFile = path.join(root, 'homeboy-bench.json');
  writeFileSync(outputFile, JSON.stringify({
    run_id: 'ssi-live-2',
    result_summary: {
      succeeded: 0,
      failed: 2,
      finding_count: 22,
      groups: { runtime_target_gap: 18, dropped_images: 4 },
    },
    artifacts: { run: 'homeboy-runs:ssi-live-2', report: 'https://example.test/report.json' },
  }));

  const plan = {
    mode: 'development-override',
    run_id: 'planned-run',
    fixture_count: 2,
    output_file: outputFile,
  };

  // The bench exited non-zero (gate-FAIL) but wrote a valid result payload.
  let result;
  assert.doesNotThrow(() => {
    result = summarizeBenchRun({ plan, benchStatus: 1, benchLabel: 'Run SSI fixture matrix bench' });
  });

  assert.equal(result.gateFailed, true);
  assert.equal(result.summary.status, 'failed');
  assert.equal(result.summary.run_id, 'ssi-live-2');
  assert.equal(result.summary.passed_fixture_count, 0);
  assert.equal(result.summary.failed_fixture_count, 2);
  assert.equal(result.summary.finding_count, 22);
  assert.deepEqual(result.summary.top_buckets[0], { key: 'runtime_target_gap', count: 18 });
  assert.equal(result.summary.run_refs.show, 'homeboy runs show ssi-live-2');
  assert.deepEqual(result.summary.artifact_urls, ['homeboy-runs:ssi-live-2', 'https://example.test/report.json']);
});

test('operator summary preserves solved-only lane identity and corpus counts', () => {
  const summary = summarizeRun({
    mode: 'release-proof',
    run_id: 'solved-only-proof',
    lane_identity: SOLVED_ONLY_LANE_ID,
    active_fixture_count: 72,
    solved_fixture_count: 3,
    selected_fixture_count: 3,
    selected_active_fixture_count: 0,
    selected_solved_fixture_count: 3,
    fixture_count: 3,
    output_file: path.join(tmpdir(), 'missing-homeboy-bench.json'),
  });

  assert.equal(summary.lane_identity, SOLVED_ONLY_LANE_ID);
  assert.equal(summary.active_fixture_count, 72);
  assert.equal(summary.solved_fixture_count, 3);
  assert.equal(summary.selected_fixture_count, 3);
  assert.equal(summary.selected_active_fixture_count, 0);
  assert.equal(summary.selected_solved_fixture_count, 3);
});

test('summarizeBenchRun reports a clean pass when the bench exits zero', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-bench-pass-'));
  const outputFile = path.join(root, 'homeboy-bench.json');
  writeFileSync(outputFile, JSON.stringify({
    run_id: 'ssi-pass',
    result_summary: { succeeded: 2, failed: 0, finding_count: 0 },
  }));

  const result = summarizeBenchRun({
    plan: { mode: 'release-proof', run_id: 'planned-run', fixture_count: 2, output_file: outputFile },
    benchStatus: 0,
    benchLabel: 'Run SSI fixture matrix bench',
  });

  assert.equal(result.gateFailed, false);
  assert.equal(result.summary.status, 'passed');
  assert.equal(result.summary.passed_fixture_count, 2);
  assert.equal(result.summary.failed_fixture_count, 0);
});

test('summarizeBenchRun still throws when a non-zero bench produced no parseable result', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-bench-crash-'));
  const missingOutput = path.join(root, 'never-written.json');

  // No output file at all -> genuine crash, keep throwing.
  assert.throws(
    () => summarizeBenchRun({
      plan: {
        mode: 'development-override',
        run_id: 'planned-run',
        output_file: missingOutput,
        steps: [{
          phase: 'fixture-workload',
          label: 'Run SSI fixture matrix bench',
          command: 'homeboy',
          args: ['bench', '--rig', 'static-site-importer-fixture-matrix'],
        }],
      },
      benchStatus: 1,
      benchLabel: 'Run SSI fixture matrix bench',
    }),
    /Run SSI fixture matrix bench failed with exit 1 during fixture-workload\. Retry: homeboy bench --rig static-site-importer-fixture-matrix/,
  );

  // Output exists but is unparseable / carries no result payload -> still a crash.
  const garbageOutput = path.join(root, 'garbage.json');
  writeFileSync(garbageOutput, 'not json at all');
  assert.throws(
    () => summarizeBenchRun({
      plan: { mode: 'development-override', run_id: 'planned-run', output_file: garbageOutput },
      benchStatus: 1,
      benchLabel: 'Run SSI fixture matrix bench',
    }),
    /failed with exit 1/,
  );
});

test('mapWithConcurrency runs bounded N in parallel and preserves input ordering', async () => {
  const items = Array.from({ length: 10 }, (_value, index) => index);
  let inFlight = 0;
  let peakInFlight = 0;

  const results = await mapWithConcurrency(items, 3, async (value) => {
    inFlight += 1;
    peakInFlight = Math.max(peakInFlight, inFlight);
    // Yield so the pool genuinely overlaps work rather than resolving instantly.
    await new Promise((resolve) => setTimeout(resolve, 5));
    inFlight -= 1;
    return value * 2;
  });

  // Up to 3 workers actually overlapped (proves real parallelism), never more.
  assert.equal(peakInFlight, 3);
  // Results stay aligned to input order regardless of completion order.
  assert.deepEqual(results, items.map((value) => value * 2));
});

test('mapWithConcurrency handles empty input and caps the pool at item count', async () => {
  assert.deepEqual(await mapWithConcurrency([], 4, async () => 1), []);

  let peakInFlight = 0;
  let inFlight = 0;
  const results = await mapWithConcurrency([1, 2], 8, async (value) => {
    inFlight += 1;
    peakInFlight = Math.max(peakInFlight, inFlight);
    await new Promise((resolve) => setTimeout(resolve, 5));
    inFlight -= 1;
    return value;
  });
  assert.deepEqual(results, [1, 2]);
  assert.equal(peakInFlight, 2);
});

test('boundedConcurrency clamps to the hard cap and falls back on invalid input', () => {
  assert.equal(boundedConcurrency('8', 4, 16), 8);
  assert.equal(boundedConcurrency('500', 4, 16), 16);
  assert.equal(boundedConcurrency(undefined, 4, 16), 4);
  assert.equal(boundedConcurrency('0', 4, 16), 4);
  assert.equal(boundedConcurrency('not-a-number', 4, 16), 4);
  assert.equal(boundedConcurrency('-3', 4, 16), 4);
});

// A configurable fake WP Codebox recipe runner, injected through the production
// `HOMEBOY_WP_CODEBOX_RECIPE_HELPER` seam, so these tests exercise the real
// `runFixtureMatrix` batch-execution path (provision -> collect -> aggregate)
// without ever spinning a sandbox. Behavior is driven live from env vars so a
// single helper module can serve every scenario:
//   - SSI_TEST_RECIPE_STATS_FILE  : where to persist peak concurrent in-flight.
//   - SSI_TEST_RECIPE_ORDER       : 'forward' | 'reverse' batch completion order.
//   - SSI_TEST_RECIPE_BATCH_COUNT : total batches (for reverse-order delays).
//   - SSI_TEST_RECIPE_UNIT_MS     : per-batch delay unit so batches overlap.
//   - SSI_TEST_RECIPE_THROW_BATCH : batch number that throws (isolation test).
// Module-level peak tracking is fresh per test because each test writes its own
// uniquely-pathed helper file (Node caches require() by resolved path).
function writeConcurrencyRecipeHelper(filePath) {
  writeFileSync(filePath, `
const fs = require('node:fs');

let inFlight = 0;
let peakInFlight = 0;

function recordPeak() {
  const file = process.env.SSI_TEST_RECIPE_STATS_FILE;
  if (!file) return;
  try {
    fs.writeFileSync(file, JSON.stringify({ peak_in_flight: peakInFlight }));
  } catch {}
}

function batchNumberFromOutput(outputFile) {
  const tail = String(outputFile || '').split('batch-')[1];
  const parsed = parseInt(tail, 10);
  return Number.isInteger(parsed) ? parsed : 0;
}

// The recipe references each fixture via "--slug=<id>" tokens in the wp-cli
// command args (no top-level fixture_id key), so derive the batch's fixtures by
// scanning for those slug tokens. Slugs are simple, space-delimited, unquoted
// values, so a plain split is enough and dodges template-literal escaping.
function fixtureIdsFromRecipe(recipeFile) {
  const ids = new Set();
  try {
    const text = fs.readFileSync(recipeFile, 'utf8');
    const segments = text.split('--slug=');
    for (let index = 1; index < segments.length; index += 1) {
      const slug = segments[index].split(' ')[0].trim();
      if (slug) {
        ids.add(slug);
      }
    }
  } catch {}
  return [...ids];
}

function wpCodeboxBin() { return '/tmp/wp-codebox'; }
function wpCodeboxCommand(bin) { return { command: bin, args: [] }; }

async function runWpCodeboxRecipe(options = {}) {
  const recipe = fs.readFileSync(options.recipeFile, 'utf8');
  const capturedRecipes = process.env.SSI_TEST_RECIPE_CAPTURE_FILE;
  if (capturedRecipes) {
    const captured = fs.existsSync(capturedRecipes) ? JSON.parse(fs.readFileSync(capturedRecipes, 'utf8')) : [];
    captured.push(JSON.parse(recipe));
    fs.writeFileSync(capturedRecipes, JSON.stringify(captured));
  }
  const batchNumber = batchNumberFromOutput(options.outputFile);
  inFlight += 1;
  peakInFlight = Math.max(peakInFlight, inFlight);
  recordPeak();

  const unit = Number(process.env.SSI_TEST_RECIPE_UNIT_MS || '15');
  const total = Number(process.env.SSI_TEST_RECIPE_BATCH_COUNT || '0');
  const order = process.env.SSI_TEST_RECIPE_ORDER || 'forward';
  // Reverse completion: the earliest batch waits longest so it finishes last.
  const delay = order === 'reverse'
    ? (total - batchNumber + 1) * unit
    : batchNumber * unit;
  await new Promise((resolve) => setTimeout(resolve, Math.max(1, delay)));

  inFlight -= 1;

  const throwBatch = Number(process.env.SSI_TEST_RECIPE_THROW_BATCH || '0');
  if (throwBatch && throwBatch === batchNumber) {
    const error = new Error('recipe-run failed for batch ' + batchNumber);
    error.code = 19;
    error.stdout = '';
    error.stderr = 'boom';
    throw error;
  }

  const fixtureIds = fixtureIdsFromRecipe(options.recipeFile);
  return {
    exitCode: 0,
    outputFile: options.outputFile,
    json: { results: fixtureIds.map((id) => ({ fixture_id: id, status: 'succeeded' })) },
  };
}

module.exports = { wpCodeboxBin, wpCodeboxCommand, runWpCodeboxRecipe };
`, 'utf8');
}

// Stand up a workspace with N single-fixture batches and a configured fake
// recipe runner; returns the env keys touched so the caller can restore them.
function setupConcurrencyWorkspace(prefix, fixtureCount) {
  const root = mkdtempSync(path.join(tmpdir(), prefix));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const concurrencyFixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const helperPath = path.join(root, 'wp-codebox-recipe-helper.cjs');
  const statsFile = path.join(root, 'recipe-stats.json');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (let index = 1; index <= fixtureCount; index += 1) {
    const fixtureDir = path.join(concurrencyFixtureRoot, `fixture-${String(index).padStart(2, '0')}`);
    mkdirSync(fixtureDir, { recursive: true });
    writeFileSync(path.join(fixtureDir, 'index.html'), `<h1>Fixture ${index}</h1>`);
  }
  writeConcurrencyRecipeHelper(helperPath);
  return { root, staticSiteImporter, fixtureRoot: concurrencyFixtureRoot, outputDirectory, helperPath, statsFile };
}

const CONCURRENCY_ENV_KEYS = [
  'HOMEBOY_WP_CODEBOX_RECIPE_HELPER',
  'SSI_TEST_RECIPE_STATS_FILE',
  'SSI_TEST_RECIPE_ORDER',
  'SSI_TEST_RECIPE_BATCH_COUNT',
  'SSI_TEST_RECIPE_UNIT_MS',
  'SSI_TEST_RECIPE_THROW_BATCH',
  'SSI_TEST_RECIPE_CAPTURE_FILE',
];

function snapshotConcurrencyEnv() {
  return Object.fromEntries(CONCURRENCY_ENV_KEYS.map((key) => [key, process.env[key]]));
}

function restoreConcurrencyEnv(snapshot) {
  for (const key of CONCURRENCY_ENV_KEYS) {
    restoreEnv(key, snapshot[key]);
  }
}

test('runFixtureMatrix caps WP Codebox batches in flight at the configured concurrency', async () => {
  const snapshot = snapshotConcurrencyEnv();
  const workspace = setupConcurrencyWorkspace('ssi-concurrency-inflight-', 6);
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = workspace.helperPath;
  process.env.SSI_TEST_RECIPE_STATS_FILE = workspace.statsFile;
  process.env.SSI_TEST_RECIPE_UNIT_MS = '20';

  try {
    const { summary, runtimeError } = await runFixtureMatrix({
      id: 'inflight-matrix',
      fixtureRoot: workspace.fixtureRoot,
      outputDirectory: workspace.outputDirectory,
      staticSiteImporterPath: workspace.staticSiteImporter,
      run: true,
      batchSize: 1,
      concurrency: 2,
      visualParity: false,
    });

    assert.equal(runtimeError, null);
    // 6 single-fixture batches all executed.
    assert.equal(summary.runtime.batches.length, 6);
    assert.equal(summary.runtime.concurrency, 2);
    assert.ok(Number.isFinite(summary.metadata.performance.artifact_writing_ms));
    assert.ok(Number.isFinite(summary.metadata.performance.batch_execution_ms));
    assert.ok(Number.isFinite(summary.metadata.performance.result_assembly_ms));
    assert.equal(summary.metadata.source_staging.status, 'skipped');
    assert.ok(summary.metadata.artifact_bytes.total > 0);
    assert.ok(summary.runtime.batches.every((batch) => Number.isFinite(batch.performance.child_recipe_run_ms)));
    assert.ok(summary.runtime.batches.every((batch) => batch.artifact_bytes.batch_recipe > 0));

    const stats = JSON.parse(readFileSync(workspace.statsFile, 'utf8'));
    // At most N (=2) sandboxes were ever live at once, and the pool genuinely
    // reached the cap (proves real parallelism, not accidental serialization).
    assert.ok(stats.peak_in_flight <= 2, `peak ${stats.peak_in_flight} exceeded concurrency 2`);
    assert.equal(stats.peak_in_flight, 2);
  } finally {
    restoreConcurrencyEnv(snapshot);
  }
});

test('runFixtureMatrix uses the candidate transformer overlay for planning and import in one recipe', async () => {
  const snapshot = snapshotConcurrencyEnv();
  const workspace = setupConcurrencyWorkspace('ssi-discovery-overlay-', 1);
  const transformerPath = path.join(workspace.root, 'blocks-engine', 'php-transformer');
  const reference = 'c'.repeat(40);
  const captureFile = path.join(workspace.root, 'recipes.json');
  mkdirSync(transformerPath, { recursive: true });
  writeFileSync(path.join(transformerPath, 'composer.json'), JSON.stringify({ name: 'automattic/blocks-engine-php-transformer' }));
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = workspace.helperPath;
  process.env.SSI_TEST_RECIPE_CAPTURE_FILE = captureFile;

  try {
    const { summary, runtimeError } = await runFixtureMatrix({
      id: 'discovery-overlay-matrix',
      fixtureRoot: workspace.fixtureRoot,
      outputDirectory: workspace.outputDirectory,
      staticSiteImporterPath: workspace.staticSiteImporter,
      blocksEnginePhpTransformerPath: transformerPath,
      blocksEnginePhpTransformerReference: reference,
      run: true,
      batchSize: 1,
      concurrency: 1,
      visualParity: false,
    });

    assert.equal(runtimeError, null);
    const recipes = JSON.parse(readFileSync(captureFile, 'utf8'));
    assert.equal(recipes.length, 1, 'planning and import execute in one WP Codebox runtime');
    const combinedRecipe = recipes[0];
    const importRecipe = JSON.parse(readFileSync(summary.runtime.batches[0].recipe_file, 'utf8'));
    const expectedOverlay = {
      kind: 'composer-package',
      package: 'automattic/blocks-engine-php-transformer',
      consumer: 'static-site-importer',
      source: transformerPath,
      reference,
    };

    assert.ok(combinedRecipe.workflow.steps.some((step) => step.args?.some((arg) => arg.includes('plan-artifact-dependencies'))));
    assert.ok(combinedRecipe.workflow.steps.some((step) => step.metadata?.phase === 'import'));
    assert.deepEqual(combinedRecipe.inputs.dependency_overlays, [expectedOverlay]);
    assert.deepEqual(importRecipe.inputs.dependency_overlays, [expectedOverlay]);
  } finally {
    restoreConcurrencyEnv(snapshot);
  }
});

test('runFixtureMatrix aggregates batch results order-independently of completion order', async () => {
  const snapshot = snapshotConcurrencyEnv();
  const workspace = setupConcurrencyWorkspace('ssi-concurrency-order-', 4);
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = workspace.helperPath;
  process.env.SSI_TEST_RECIPE_BATCH_COUNT = '4';
  process.env.SSI_TEST_RECIPE_UNIT_MS = '10';

  const runMatrix = async (order) => {
    process.env.SSI_TEST_RECIPE_ORDER = order;
    const { summary, runtimeError } = await runFixtureMatrix({
      id: 'order-matrix',
      fixtureRoot: workspace.fixtureRoot,
      outputDirectory: path.join(workspace.root, `artifacts-${order}`),
      staticSiteImporterPath: workspace.staticSiteImporter,
      run: true,
      batchSize: 1,
      concurrency: 4,
      visualParity: false,
    });
    assert.equal(runtimeError, null);
    return summary;
  };

  try {
    const forward = await runMatrix('forward');
    const reverse = await runMatrix('reverse');

    // Same fixtures, same metrics regardless of which sandbox finished first.
    const metrics = (summary) => ({
      fixture_count: summary.fixture_count,
      succeeded: summary.result_summary.succeeded,
      failed: summary.result_summary.failed,
      not_run: summary.result_summary.not_run,
      finding_count: summary.result_summary.finding_count,
    });
    assert.deepEqual(metrics(reverse), metrics(forward));
    assert.equal(metrics(forward).succeeded, 4);

    // Batch summaries and fixture identities stay in deterministic matrix order
    // even though reverse completion finishes batch 4 before batch 1.
    const batchOrder = (summary) => summary.runtime.batches.map((batch) => batch.batch);
    const fixtureOrder = (summary) => summary.runtime.batches.flatMap((batch) => batch.fixture_ids);
    assert.deepEqual(batchOrder(forward), [1, 2, 3, 4]);
    assert.deepEqual(batchOrder(reverse), [1, 2, 3, 4]);
    assert.deepEqual(fixtureOrder(reverse), fixtureOrder(forward));
  } finally {
    restoreConcurrencyEnv(snapshot);
  }
});

test('runFixtureMatrix isolates a throwing batch so sibling batches still complete', async () => {
  const snapshot = snapshotConcurrencyEnv();
  const workspace = setupConcurrencyWorkspace('ssi-concurrency-isolation-', 4);
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = workspace.helperPath;
  process.env.SSI_TEST_RECIPE_BATCH_COUNT = '4';
  process.env.SSI_TEST_RECIPE_UNIT_MS = '5';
  process.env.SSI_TEST_RECIPE_THROW_BATCH = '2';

  try {
    const { summary, runtimeError } = await runFixtureMatrix({
      id: 'isolation-matrix',
      fixtureRoot: workspace.fixtureRoot,
      outputDirectory: workspace.outputDirectory,
      staticSiteImporterPath: workspace.staticSiteImporter,
      run: true,
      batchSize: 1,
      concurrency: 4,
      visualParity: false,
    });

    // The throwing batch surfaces as the runtime error + exit code, but the run
    // still produced a full summary rather than rejecting.
    assert.ok(runtimeError);
    assert.match(runtimeError.message, /batch 2/);
    assert.equal(summary.runtime.exit_code, 19);

    // Exactly the one failing batch is recorded as a child-command failure.
    const failures = summary.runtime.child_command_failures;
    assert.equal(failures.length, 1);
    assert.equal(failures[0].batch_id, 'batch-002');
    assert.equal(failures[0].exit_status, 19);

    // All four batches still ran; the three non-throwing siblings succeeded,
    // proving one batch's failure did not sink the others.
    assert.equal(summary.runtime.batches.length, 4);
    assert.equal(summary.result_summary.succeeded, 3);
    assert.equal(summary.result_summary.failed, 1);
  } finally {
    restoreConcurrencyEnv(snapshot);
  }
});

test('runFixtureMatrix recovers healthy fixtures from a poisoned batch sandbox', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-recovery-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const failureFixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const helperPath = path.join(root, 'wp-codebox-recipe-helper.cjs');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (const fixtureId of ['healthy-before', 'hanging', 'healthy-after']) {
    const fixtureDirectory = path.join(failureFixtureRoot, fixtureId);
    mkdirSync(fixtureDirectory, { recursive: true });
    writeFileSync(path.join(fixtureDirectory, 'index.html'), `<h1>${fixtureId}</h1>`);
  }
  writeFileSync(helperPath, `
const fs = require('node:fs');
function fixtureIds(recipeFile) {
  return [...new Set(fs.readFileSync(recipeFile, 'utf8').split('--slug=').slice(1).map((part) => part.split(' ')[0]))];
}
function wpCodeboxBin() { return '/tmp/wp-codebox'; }
function wpCodeboxCommand(bin) { return { command: bin, args: [] }; }
async function runWpCodeboxRecipe(options) {
  const ids = fixtureIds(options.recipeFile);
  if (ids.includes('hanging')) {
    const error = new Error('fixture hanging exceeded its deadline');
    error.code = 124;
    error.stderr = 'hanging fixture stderr';
    error.stdout = 'hanging fixture stdout';
    throw error;
  }
  return { exitCode: 0, outputFile: options.outputFile, json: { results: ids.map((fixture_id) => ({ fixture_id, status: 'succeeded' })) } };
}
module.exports = { wpCodeboxBin, wpCodeboxCommand, runWpCodeboxRecipe };
`, 'utf8');
  const previousHelper = process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER;
  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = helperPath;

  try {
    const { summary, runtimeError } = await runFixtureMatrix({
      id: 'poisoned-batch-recovery',
      fixtureRoot: failureFixtureRoot,
      outputDirectory,
      staticSiteImporterPath: staticSiteImporter,
      run: true,
      batchSize: 3,
      visualParity: false,
    });

    assert.match(runtimeError.message, /hanging/);
    assert.equal(summary.result_summary.succeeded, 2);
    assert.equal(summary.result_summary.failed, 1);
    assert.deepEqual(summary.runtime.child_command_failures.map((failure) => failure.fixture_ids), [['hanging']]);
    const recovery = summary.runtime.batches[0].recovery_attempts;
    assert.equal(recovery.length, 3);
    assert.deepEqual(recovery.map((attempt) => attempt.fixture_ids[0]).sort(), ['hanging', 'healthy-after', 'healthy-before']);
    assert.equal(recovery.find((attempt) => attempt.fixture_ids[0] === 'hanging').stderr_tail, 'hanging fixture stderr');
    assert.equal(summary.runtime.batches[0].stderr_tail, 'hanging fixture stderr');
  } finally {
    restoreEnv('HOMEBOY_WP_CODEBOX_RECIPE_HELPER', previousHelper);
  }
});

test('fixture matrix emits Homeboy runner-progress lifecycle events and isolates a silent shared batch promptly', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-progress-'));
  const fixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const wpCodeboxBin = path.join(root, 'wp-codebox');
  for (const fixtureId of ['healthy-before', 'hanging', 'healthy-after']) {
    mkdirSync(path.join(fixtureRoot, fixtureId), { recursive: true });
    writeFileSync(path.join(fixtureRoot, fixtureId, 'index.html'), `<h1>${fixtureId}</h1>`);
  }
  writeFileSync(wpCodeboxBin, `#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const recipePath = process.argv[process.argv.indexOf('--recipe') + 1];
const recipe = fs.readFileSync(recipePath, 'utf8');
const recovery = path.basename(recipePath).match(/-recovery-(.+)\\.json$/);
const ids = recovery ? [recovery[1]] : [...new Set(recipe.split('--slug=').slice(1).map((part) => part.split(' ')[0]))];
if (ids.includes('hanging')) {
  setInterval(() => {}, 1000);
} else {
  const output = process.argv[process.argv.indexOf('--output') + 1];
  fs.writeFileSync(output, JSON.stringify({ results: ids.map((fixture_id) => ({ fixture_id, status: 'succeeded' })) }));
}
`, 'utf8');
  chmodSync(wpCodeboxBin, 0o755);
  const events = [];
  const startedAt = Date.now();
  const { summary } = await runFixtureMatrix({
    id: 'progress-matrix',
    fixtureRoot,
    outputDirectory,
    staticSiteImporterPath: path.join(root, 'static-site-importer'),
    run: true,
    batchSize: 3,
    recoveryConcurrency: 2,
    batchInactivityTimeoutMs: 1000,
    visualParity: false,
    wpCodeboxBin,
    progress: (event) => events.push(event),
  });
  assert.ok(Date.now() - startedAt < 4000, 'inactivity recovery must not wait for a long command timeout');
  assert.equal(summary.result_summary.succeeded, 2);
  assert.equal(summary.result_summary.failed, 1);
  assert.deepEqual(events.map((event) => event.schema), Array(events.length).fill(FIXTURE_MATRIX_PROGRESS_SCHEMA));
  assert.ok(events.every((event) => Number.isInteger(event.completed) && event.completed >= 0 && event.total === 3 && event.current_item));
  assert.ok(events.find((event) => event.phase === 'batch' && event.metadata.lifecycle_status === 'timeout'));
  assert.ok(events.find((event) => event.phase === 'fixture' && event.metadata.lifecycle_status === 'timeout' && event.current_item === 'hanging'));
  const recoveryStart = events.findIndex((event) => event.phase === 'recovery' && event.metadata.lifecycle_status === 'started');
  const healthyAfterComplete = events.findIndex((event) => event.phase === 'fixture' && event.metadata.lifecycle_status === 'completed' && event.current_item === 'healthy-after');
  const matrixComplete = events.findIndex((event) => event.phase === 'matrix' && event.metadata.lifecycle_status === 'failed');
  assert.ok(recoveryStart > events.findIndex((event) => event.phase === 'batch' && event.metadata.lifecycle_status === 'timeout'));
  assert.ok(healthyAfterComplete > recoveryStart && healthyAfterComplete < matrixComplete);
  assert.equal(events.at(-1).completed, 3);

  // This mirrors Homeboy #7874's accepted child envelope. SSI lifecycle detail
  // stays in metadata, while an injected terminal-state field is rejected.
  const accepted = events.map((event) => parseHomeboyRunnerProgress(`${FIXTURE_MATRIX_PROGRESS_PREFIX}${JSON.stringify(event)}`));
  assert.ok(accepted.every(Boolean), 'start, completion, failure, timeout, and recovery events are accepted by Homeboy');
  assert.ok(accepted.some((event) => event.metadata.lifecycle_status === 'started'));
  assert.ok(accepted.some((event) => event.metadata.lifecycle_status === 'completed'));
  assert.ok(accepted.some((event) => event.metadata.lifecycle_status === 'failed'));
  assert.ok(accepted.some((event) => event.metadata.lifecycle_status === 'timeout'));
  assert.ok(accepted.some((event) => event.phase === 'recovery'));
  assert.equal(parseHomeboyRunnerProgress(`${FIXTURE_MATRIX_PROGRESS_PREFIX}${JSON.stringify({ ...events[0], status: 'succeeded' })}`), null);
});

function parseHomeboyRunnerProgress(line) {
  const payload = line.startsWith(FIXTURE_MATRIX_PROGRESS_PREFIX) ? line.slice(FIXTURE_MATRIX_PROGRESS_PREFIX.length) : '';
  try {
    const event = JSON.parse(payload);
    const allowed = new Set(['schema', 'phase', 'current_item', 'completed', 'total', 'metadata']);
    if (event.schema !== FIXTURE_MATRIX_PROGRESS_SCHEMA
      || Object.keys(event).some((key) => !allowed.has(key))
      || (!event.phase && !event.current_item && event.completed === undefined && event.total === undefined && event.metadata === undefined)
      || (event.completed !== undefined && event.total !== undefined && event.completed > event.total)) {
      return null;
    }
    return event;
  } catch {
    return null;
  }
}

test('runFixtureMatrixBench returns a partial result with survivors aggregated when a batch fails', async () => {
  // The bench-harness entry point is where the whole-run discard used to live:
  // any failing batch made `runFixtureMatrixBench` throw, so the harness recorded
  // an assertion_failure and dropped the aggregate (every survivor lost). This
  // proves the harness boundary now isolates the failure -- the bench returns
  // normally with the survivors aggregated and the failure counted, so the rig's
  // `failed_fixture_count <= 0` result-gate fails the run WITHOUT discarding it.
  const concurrencySnapshot = snapshotConcurrencyEnv();
  const benchEnvKeys = [
    'SSI_FIXTURE_MATRIX_FIXTURE_ROOT',
    'SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY',
    'SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH',
    'SSI_FIXTURE_MATRIX_RUN',
    'SSI_FIXTURE_MATRIX_BATCH_SIZE',
    'SSI_FIXTURE_MATRIX_CONCURRENCY',
    'SSI_FIXTURE_MATRIX_VISUAL_PARITY',
  ];
  const benchEnvSnapshot = Object.fromEntries(benchEnvKeys.map((key) => [key, process.env[key]]));
  const workspace = setupConcurrencyWorkspace('ssi-bench-isolation-', 4);

  process.env.HOMEBOY_WP_CODEBOX_RECIPE_HELPER = workspace.helperPath;
  process.env.SSI_TEST_RECIPE_BATCH_COUNT = '4';
  process.env.SSI_TEST_RECIPE_UNIT_MS = '5';
  process.env.SSI_TEST_RECIPE_THROW_BATCH = '2';
  process.env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT = workspace.fixtureRoot;
  process.env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY = workspace.outputDirectory;
  process.env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH = workspace.staticSiteImporter;
  process.env.SSI_FIXTURE_MATRIX_RUN = '1';
  process.env.SSI_FIXTURE_MATRIX_BATCH_SIZE = '1';
  process.env.SSI_FIXTURE_MATRIX_CONCURRENCY = '4';
  process.env.SSI_FIXTURE_MATRIX_VISUAL_PARITY = '0';

  try {
    // Does not reject: the failing batch is recorded, not fatal.
    const benchResult = await runFixtureMatrixBench();

    // The aggregate spans all four fixtures: the three surviving batches passed
    // and only the failing batch is counted as failed.
    assert.equal(benchResult.metrics.fixture_count, 4);
    assert.equal(benchResult.metrics.passed_fixture_count, 3);
    assert.equal(benchResult.metrics.failed_fixture_count, 1);
    assert.equal(benchResult.metrics.not_run_fixture_count, 0);
    assert.equal(benchResult.metadata.execution_requested, true);
    assert.equal(benchResult.metadata.execution_status, 'requested');
    assert.deepEqual(benchResult.metadata.execution_evidence, {
      status: 'requested',
      blind_spots: [],
    });

    // The result-gate (failed_fixture_count <= 0) will fail on this, while the
    // partial result is still emitted and the failing batch stays attributable.
    const failures = benchResult.metadata.child_command_failures;
    assert.equal(failures.length, 1);
    assert.equal(failures[0].batch_id, 'batch-002');
    assert.equal(failures[0].exit_status, 19);

    // The aggregate result artifact was written for the lane to record.
    const resultPayload = JSON.parse(readFileSync(benchResult.artifacts.result.path, 'utf8'));
    assert.equal(resultPayload.summary.succeeded, 3);
    assert.equal(resultPayload.summary.failed, 1);
  } finally {
    restoreConcurrencyEnv(concurrencySnapshot);
    for (const key of benchEnvKeys) {
      restoreEnv(key, benchEnvSnapshot[key]);
    }
  }
});

test('runFixtureMatrixBench reads workload args from context.args when imported', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-bench-context-args-'));
  const contextFixtureRoot = path.join(root, 'context-fixtures');
  const argvFixtureRoot = path.join(root, 'argv-fixtures');
  const outputDirectory = path.join(root, 'context-artifacts');
  const argvOutputDirectory = path.join(root, 'argv-artifacts');
  const staticSiteImporter = path.join(root, 'static-site-importer');
  mkdirSync(path.join(contextFixtureRoot, 'context-fixture'), { recursive: true });
  mkdirSync(path.join(argvFixtureRoot, 'argv-fixture'), { recursive: true });
  mkdirSync(staticSiteImporter, { recursive: true });
  writeFileSync(path.join(contextFixtureRoot, 'context-fixture', 'index.html'), '<h1>Context fixture</h1>');
  writeFileSync(path.join(argvFixtureRoot, 'argv-fixture', 'index.html'), '<h1>Argv fixture</h1>');

  const previousArgv = process.argv;
  const benchEnvKeys = [
    'SSI_FIXTURE_MATRIX_FIXTURE_ROOT',
    'SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY',
    'SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH',
    'SSI_FIXTURE_MATRIX_RUN',
    'HOMEBOY_BENCH_ARTIFACTS_DIR',
  ];
  const benchEnvSnapshot = Object.fromEntries(benchEnvKeys.map((key) => [key, process.env[key]]));

  for (const key of benchEnvKeys) {
    delete process.env[key];
  }
  process.argv = [
    'node',
    'homeboy-nodejs-bench-runner',
    '--fixture-root', argvFixtureRoot,
    '--output-directory', argvOutputDirectory,
    '--static-site-importer-path', staticSiteImporter,
  ];

  try {
    const benchResult = await runFixtureMatrixBench({
      args: [
        '--fixture-root', contextFixtureRoot,
        '--output-directory', outputDirectory,
        '--static-site-importer-path', staticSiteImporter,
      ],
    });

    assert.equal(benchResult.metrics.fixture_count, 1);
    assert.equal(benchResult.metadata.fixture_root, path.resolve(contextFixtureRoot));
    assert.equal(benchResult.metadata.output_directory, path.resolve(outputDirectory));
    assert.equal(benchResult.metadata.execution_requested, false);
    assert.equal(benchResult.metadata.execution_status, 'not_requested');
    assert.deepEqual(benchResult.metadata.execution_evidence, {
      status: 'plan_only',
      gate_reason: 'execution_not_requested',
      blind_spots: [
        'transformer_execution',
        'wordpress_materialization',
        'editor_validation',
        'visual_parity',
      ],
    });
    const matrix = JSON.parse(readFileSync(benchResult.artifacts.matrix.path, 'utf8'));
    assert.deepEqual(matrix.fixtures.map((fixture) => fixture.id), ['context-fixture']);
    assert.equal(existsSync(path.join(argvOutputDirectory, 'matrix.json')), false);
  } finally {
    process.argv = previousArgv;
    for (const key of benchEnvKeys) {
      restoreEnv(key, benchEnvSnapshot[key]);
    }
  }
});

test('compares finding packet deltas by repair dimensions', () => {
  const summary = compareFindingPackets({
    base_label: 'main',
    candidate_label: 'candidate',
    top: 5,
    base: [
      { kind: 'unsupported_html_fallback', group_key: 'static_site_import_quality', repair_bucket: 'runtime_target_gap', fixture_id: 'hero-site', candidate_repo: 'blocks-engine', selector: 'script:nth-of-type(1)' },
      { kind: 'document_metadata_routed', group_key: 'dropped_images', repair_bucket: 'dropped_images', fixture_id: 'shop-site', candidate_repo: 'static-site-importer', selector: '.gallery img' },
    ],
    candidate: [
      { kind: 'document_metadata_routed', group_key: 'dropped_images', repair_bucket: 'dropped_images', fixture_id: 'shop-site', candidate_repo: 'static-site-importer', selector: '.gallery img' },
      { kind: 'document_metadata_routed', group_key: 'dropped_images', repair_bucket: 'dropped_images', fixture_id: 'portfolio-site', candidate_repo: 'static-site-importer', selector: '.gallery img' },
      { kind: 'invalid_block_content', group_key: 'invalid_block_content', repair_bucket: 'invalid_block_content', fixture_id: 'portfolio-site', candidate_repo: 'blocks-engine', selector: '#hero .cta' },
    ],
  });

  assert.deepEqual(summary.totals, { base: 2, candidate: 3, delta: 1 });
  assert.deepEqual(summary.dimensions.bucket.slice(0, 2), [
    { key: 'dropped_images', base: 1, candidate: 2, delta: 1 },
    { key: 'invalid_block_content', base: 0, candidate: 1, delta: 1 },
  ]);
  assert.ok(summary.dimensions.bucket.some((row) => row.key === 'runtime_target_gap' && row.delta === -1));
  assert.deepEqual(summary.dimensions.fixture_id[0], { key: 'portfolio-site', base: 0, candidate: 2, delta: 2 });
  assert.equal(selectorFamily('script:nth-of-type(1)'), 'script');
  assert.equal(selectorFamily('#hero .cta'), 'id:hero');
});

test('recipe runs editor-validate-blocks against imported content after each import', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-validation-recipe-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });

  // [activate, prepare, validate, sidecar-readback, visual-font-setup, suppress-onboarding, identity, editor-open, editor-validate-blocks]
  assert.equal(recipe.workflow.steps[1].command, 'wordpress.wp-cli');
  assert.match(recipe.workflow.steps[1].args[0], /prepare-artifact-dependencies/);
  assert.equal(recipe.workflow.steps[2].command, 'wordpress.wp-cli');
  assert.match(recipe.workflow.steps[2].args[0], /static-site-importer validate-artifact/);
  const preflightStep = recipe.workflow.steps.find((step) => step.metadata?.phase === 'editor-preflight');
  assert.equal(preflightStep.command, 'wordpress.wp-cli');
  assert.match(preflightStep.args[0], /woocommerce_onboarding_profile/);
  assert.match(preflightStep.args[0], /persisted_preferences/);
  assert.match(preflightStep.args[0], /welcomeGuide/);
  const transportedEval = preflightStep.args[0]
    .replace(/^command=eval '/, '')
    .replace(/'$/, '')
    .replace(/'\\''/g, "'");
  const preflightLint = spawnSync('php', ['-l'], { input: `<?php\n${transportedEval}`, encoding: 'utf8' });
  assert.equal(preflightLint.status, 0, preflightLint.stderr);
  const identityStep = recipe.workflow.steps.find((step) => step.metadata?.phase === 'materialized-surface-identity');
  assert.equal(identityStep.command, 'wordpress.wp-cli');
  assert.equal(identityStep.metadata.phase, 'materialized-surface-identity');
  const editorOpenStep = recipe.workflow.steps.find((step) => step.command === 'wordpress.editor-open');
  assert.equal(editorOpenStep.command, 'wordpress.editor-open');
  assert.ok(editorOpenStep.args.includes('target=front-page'));
  assert.ok(editorOpenStep.args.includes('capture=screenshot,editor-state,editor-validity'));
  assert.ok(editorOpenStep.args.includes('presentation-url=/'));
  assert.ok(editorOpenStep.args.includes('presentation-frontend-selector=.wp-block-post-content'));
  assert.ok(editorOpenStep.args.includes('artifact-prefix=files/browser/editor-open/simple-site'));
  const editorStep = recipe.workflow.steps.find((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND);
  assert.equal(editorStep.command, EDITOR_VALIDATE_BLOCKS_COMMAND);
  assert.equal(editorStep.command, 'wordpress.editor-validate-blocks');
  assert.equal(editorStep.args.some((arg) => arg.includes('post-new.php')), false);
  assert.equal(editorStep.args.some((arg) => arg.startsWith('post-type=')), false);
  assert.ok(editorStep.args.includes('target=front-page'));
  assert.equal(editorStep.args.some((arg) => arg.startsWith('capture=')), false);
  assert.equal(editorStep.allowFailure, true);
  assert.equal(recipe.workflow.steps.some((step) => step.command === 'wordpress.editor-actions'), false);

  const solvedCandidateRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    requireSolvedCandidate: true,
  });
  const persistenceStep = solvedCandidateRecipe.workflow.steps.find((step) => step.metadata?.phase === 'editor-persistence');
  assert.equal(persistenceStep.command, 'wordpress.editor-actions');
  assert.ok(persistenceStep.args.includes('target=front-page'));
  assert.ok(persistenceStep.args.some((arg) => arg.includes('"kind":"savePost"') && arg.includes('ssi-solved-editability-simple-site')));
  assert.ok(persistenceStep.args.some((arg) => arg.includes('"kind":"insertBlock"') && arg.includes('ssi-solved-editability-simple-site')));
  assert.ok(persistenceStep.args.some((arg) => arg.includes('"kind":"selectBlock"')));
  assert.ok(persistenceStep.args.some((arg) => arg.includes('"kind":"moveBlock"') && arg.includes('"position":1')));
  assert.ok(persistenceStep.args.some((arg) => arg.includes('"kind":"reload"')));
  assert.ok(persistenceStep.args.some((arg) => arg.includes('"kind":"inspectState"')));
  const persistenceVerifyStep = solvedCandidateRecipe.workflow.steps.find((step) => step.metadata?.phase === 'editor-persistence-verify');
  assert.equal(persistenceVerifyStep.command, 'wordpress.wp-cli');
  assert.match(persistenceVerifyStep.args[0], /command=eval/);
  const persistenceValidationStep = solvedCandidateRecipe.workflow.steps.find((step) => step.metadata?.phase === 'editor-persistence-validation');
  assert.equal(persistenceValidationStep.command, EDITOR_VALIDATE_BLOCKS_COMMAND);
  assert.equal(persistenceValidationStep.allowFailure, false);
  assert.ok(persistenceValidationStep.args.includes('target=front-page'));
  assert.equal(solvedCandidateRecipe.workflow.steps.filter((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND).length, 1);

  const disabled = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
  });
  assert.equal(disabled.workflow.steps.some((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND), false);
});

test('fixture matrix browser surfaces default to front page and opt into bounded secondary pages', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-surface-fixture-'));
  const fixtureDirectory = path.join(root, 'artist');
  mkdirSync(path.join(fixtureDirectory, 'about'), { recursive: true });
  mkdirSync(path.join(fixtureDirectory, 'merch'), { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'fixture.json'), JSON.stringify({ id: 'artist', label: 'Artist' }));
  writeFileSync(path.join(fixtureDirectory, 'index.html'), '<main>Home</main>');
  writeFileSync(path.join(fixtureDirectory, 'about.html'), '<main>About flat</main>');
  writeFileSync(path.join(fixtureDirectory, 'about', 'index.html'), '<main>About nested</main>');
  writeFileSync(path.join(fixtureDirectory, 'about', 'team.html'), '<main>Team</main>');
  writeFileSync(path.join(fixtureDirectory, 'contact.html'), '<main><form><input name="email"></form></main>');
  writeFileSync(path.join(fixtureDirectory, 'faculty.html'), '<main>Faculty</main>');
  writeFileSync(path.join(fixtureDirectory, 'merch', 'index.html'), '<main><button>Add to cart</button></main>');
  writeFileSync(path.join(fixtureDirectory, 'news.html'), '<main>News</main>');
  writeFileSync(path.join(fixtureDirectory, 'programs.html'), '<main>Programs</main>');

  const discoveredMatrix = createFixtureMatrix({ fixture_root: root, id: 'surface-recipe-test' });
  const matrix = { ...discoveredMatrix, fixtures: discoveredMatrix.fixtures.filter((fixture) => fixture.id === 'artist'), count: 1 };
  assert.deepEqual(selectFixtureSurfaces(matrix.fixtures[0]).map((surface) => surface.id), ['front-page']);
  assert.deepEqual(selectFixtureSurfaces(matrix.fixtures[0], { surfaceCoverage: { maxExtraSurfaces: 1 } }).map((surface) => surface.id), ['front-page', 'about']);
  assert.deepEqual(selectFixtureSurfaces(matrix.fixtures[0], { surfaceCoverage: 8 }).map((surface) => surface.id), ['front-page', 'about', 'about--2', 'about-team', 'contact', 'faculty', 'merch', 'news', 'programs']);
  assert.equal(normalizeSurfaceCoverageOptions({ surfaceCoverage: 99 }).extraSurfaceCount, MAX_EXTRA_SURFACE_COUNT);

  const defaultRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });
  assert.equal(defaultRecipe.workflow.steps.filter((step) => step.command === 'wordpress.editor-open').length, 1);
  assert.equal(defaultRecipe.workflow.steps.filter((step) => step.command === 'wordpress.visual-compare').length, 1);

  const multiSurfaceRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    surfaceCoverage: { maxExtraSurfaces: 3 },
  });
  const editorOpenSteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.command === 'wordpress.editor-open');
  const editorValidationSteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND);
  const identitySteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.metadata?.phase === 'materialized-surface-identity');
  const visualSteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.command === 'wordpress.visual-compare');

  assert.equal(editorOpenSteps.length, 4);
  assert.equal(editorValidationSteps.length, 4);
  assert.equal(identitySteps.length, 4);
  assert.equal(visualSteps.length, 4);
  assert.ok(editorOpenSteps[0].args.includes('artifact-prefix=files/browser/editor-open/artist'));
  assert.ok(editorOpenSteps[1].args.includes('post-type=page'));
  assert.ok(editorOpenSteps[1].args.includes('post-slug=about'));
  assert.ok(editorOpenSteps[1].args.includes('presentation-url=/about/'));
  assert.equal(editorOpenSteps[1].args.some((arg) => arg.startsWith('url=')), false);
  assert.ok(editorOpenSteps[1].args.includes('artifact-prefix=files/browser/editor-open/artist/about'));
  assert.ok(editorOpenSteps[2].args.includes('post-type=page'));
  assert.ok(editorOpenSteps[2].args.includes('post-slug=about'));
  assert.ok(editorOpenSteps[2].args.includes('artifact-prefix=files/browser/editor-open/artist/about--2'));
  assert.ok(editorValidationSteps[1].args.includes('post-type=page'));
  assert.ok(editorValidationSteps[1].args.includes('post-slug=about'));
  assert.equal(editorValidationSteps[1].args.some((arg) => arg.startsWith('url=')), false);

  assert.ok(editorOpenSteps[3].args.includes('post-slug=about/team'));
  assert.equal(editorOpenSteps[3].metadata.route, '/about/team/');
  assert.equal(editorOpenSteps[3].metadata.source_entry, 'about/team.html');
  assert.ok(editorValidationSteps[3].args.includes('post-slug=about/team'));
  assert.equal(identitySteps[3].metadata.post_slug, 'about/team');
  assert.equal(identitySteps[3].metadata.route, '/about/team/');

  const aboutComparison = visualCompareMatrixComparison(visualSteps[1]);
  assert.equal(aboutComparison.name, 'artist--about');
  assert.equal(aboutComparison.sourceUrl, 'file:///tmp/artifacts/artist/source/about.html');
  assert.equal(aboutComparison.candidateUrl, '/about/');
  const nestedAboutComparison = visualCompareMatrixComparison(visualSteps[2]);
  assert.equal(nestedAboutComparison.name, 'artist--about--2');
  assert.equal(nestedAboutComparison.sourceUrl, 'file:///tmp/artifacts/artist/source/about/index.html');
  assert.equal(nestedAboutComparison.candidateUrl, '/about/');
  const teamComparison = visualCompareMatrixComparison(visualSteps[3]);
  assert.equal(teamComparison.candidateUrl, '/about/team/');
  assert.equal(multiSurfaceRecipe.metadata.surface_coverage.extra_surfaces_per_fixture, 3);
  assert.equal(multiSurfaceRecipe.metadata.surface_coverage.total_surface_count, 4);
  assert.equal(multiSurfaceRecipe.metadata.runtime_cost_warnings[0].code, 'surface_coverage_runtime_cost');
});

test('--no-editor-validation skips the editor browser step while keeping native-rate + findings', () => {
  // The editor browser step launches a browser per site and is the
  // slowest per-fixture step. --no-editor-validation skips it (companion to
  // --no-visual-parity) so findings/native-rate still get collected. This proves
  // the full thread: CLI flag -> bench env -> recipe step omission, plus that the
  // result still carries native-rate/findings with no editor-validity data.
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-no-editor-validation-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const planFixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(planFixtureRoot, 'fixture-a'), { recursive: true });

  // Default: editor-validation enabled, no skip env setting (unchanged behavior).
  const enabledPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: planFixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(enabledPlan.editor_validation.enabled, true);
  assert.equal(
    enabledPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_EDITOR_VALIDATION=0'),
    false,
  );

  // --no-editor-validation -> options.editorValidation === false -> env=0 setting
  // threaded into the bench (mirrors --no-visual-parity exactly).
  const skippedPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: planFixtureRoot,
    editorValidation: false,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(skippedPlan.editor_validation.enabled, false);
  assert.ok(skippedPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_EDITOR_VALIDATION=0'));

  // Recipe: the editor-validate-blocks step is present by default and omitted when
  // disabled, while the import/validate-artifact step (which feeds native-rate and
  // findings) always survives.
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'no-editor-validation-recipe' });
  const enabledRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });
  assert.ok(enabledRecipe.workflow.steps.some((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND));

  const skippedRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
  });
  assert.equal(
    skippedRecipe.workflow.steps.some((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND),
    false,
  );
  assert.ok(skippedRecipe.workflow.steps.some((step) => /static-site-importer validate-artifact/.test(step.args?.[0] ?? '')));

  // With the editor-validation step skipped there is no validateBlock editor-validity
  // data, but native-rate (from block composition) and findings still flow.
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'passed',
        // 8 native, 2 core/html => native_conversion_rate 0.8.
        block_type_counts: {
          'core/paragraph': 6,
          'core/heading': 2,
          'core/html': 2,
        },
        diagnostics: [
          { kind: 'core_html_block', loss_class: 'native_conversion', message: 'Fell back to core/html.' },
        ],
      },
    ],
  });
  assert.equal(result.summary.editor_quality.native_conversion_rate, 0.8);
  assert.equal(result.summary.editor_quality.editor_validated_fixture_count, 0);
  assert.ok(result.findings.length >= 1);
});

test('editorBlockValidationStep emits editor-validate-blocks against real imported content', () => {
  // Defaults to the imported front page because the import step has just set
  // page_on_front, while the imported post ID is not known at recipe-build time.
  const fallback = editorBlockValidationStep({ fixture: { id: 'simple' } });
  assert.equal(fallback.command, 'wordpress.editor-validate-blocks');
  assert.equal(fallback.allowFailure, true);
  assert.deepEqual(fallback.args, ['target=front-page']);

  // An explicit editor URL (e.g. post.php?post=<id>&action=edit) is honored.
  const byUrl = editorBlockValidationStep({ fixture: { id: 'shop', editor_url: '/wp-admin/post.php?post=42&action=edit' } });
  assert.equal(byUrl.command, 'wordpress.editor-validate-blocks');
  assert.ok(byUrl.args.includes('url=/wp-admin/post.php?post=42&action=edit'));
  assert.equal(byUrl.args.some((arg) => arg.startsWith('capture=')), false);

  // An imported post id is preferred over a URL.
  const byPostId = editorBlockValidationStep({ fixture: { id: 'shop', post_id: 99 } });
  assert.ok(byPostId.args.includes('post-id=99'));

  const byPostSlug = editorBlockValidationStep({
    fixture: { id: 'shop' },
    surface: { post_slug: 'contact', post_type: 'page' },
  });
  assert.deepEqual(byPostSlug.args, ['post-type=page', 'post-slug=contact']);
  assert.equal(byPostSlug.metadata.post_slug, 'contact');

  // Wait passthrough stays available.
  const withWait = editorBlockValidationStep({
    fixture: { id: 'shop', post_id: 99, editor_wait_selector: '.is-root-container' },
  });
  assert.ok(withWait.args.includes('post-id=99'));
  assert.ok(withWait.args.includes('wait-selector=.is-root-container'));
});

test('editor-canvas-probe invalid-block warnings become gating editor_block_invalid findings', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-canvas-invalid-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        diagnostics: collectEditorValidationDiagnostics({
          summary: {
            selectorSummary: {
              groups: [
                {
                  name: 'editor_block_invalid',
                  selector: '.block-editor-warning',
                  count: 2,
                  visible_count: 2,
                  first_match: { text: 'This block contains unexpected or invalid content' },
                },
              ],
            },
          },
        }),
      },
    ],
  });

  const finding = result.findings[0];
  assert.equal(finding.kind, 'editor_block_invalid');
  assert.equal(finding.group_key, 'editor_block_invalid');
  assert.equal(finding.repair_bucket, 'editor_block_invalid');
  assert.equal(finding.candidate_repo, '');
  assert.equal(finding.loss_class, 'editor_block_invalid');
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(finding.selector, '.block-editor-warning');
  assert.equal(result.summary.unacceptable_finding_count, 1);
  assert.equal(result.summary.failed, 1);
  assert.equal(result.summary.succeeded, 0);
  assert.equal(result.fixtures[0].status, 'failed');
});

test('per-block editor validity (isValid=false) becomes an editor_block_invalid finding with block name and selector', () => {
  const diagnostics = collectEditorValidationDiagnostics({
    editor_validation: {
      blocks: [
        { name: 'core/paragraph', clientId: 'abc-1', isValid: true },
        {
          name: 'core/columns',
          clientId: 'abc-2',
          isValid: false,
          issues: ['Block validation failed for "core/columns"'],
        },
      ],
    },
  });

  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].kind, 'editor_block_invalid');
  assert.equal(diagnostics[0].block_name, 'core/columns');
  assert.equal(diagnostics[0].selector, '[data-block="abc-2"]');

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-block-validity-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'failed', diagnostics }],
  });
  assert.equal(result.findings[0].observed_block_name, 'core/columns');
  assert.equal(result.findings[0].loss_acceptance, 'unacceptable');
  assert.equal(result.fixtures[0].status, 'failed');
});

test('valid editor blocks produce no editor_block_invalid findings', () => {
  const noWarnings = collectEditorValidationDiagnostics({
    summary: {
      selectorSummary: {
        groups: [{ name: 'editor_block_invalid', selector: '.block-editor-warning', count: 0, visible_count: 0 }],
      },
    },
    editor_validation: {
      blocks: [
        { name: 'core/paragraph', clientId: 'ok-1', isValid: true },
        { name: 'core/heading', clientId: 'ok-2', isValid: true },
      ],
    },
  });
  assert.deepEqual(noWarnings, []);

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-valid-negative-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'passed', diagnostics: noWarnings }],
  });
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('editor_block_invalid findings collected from fixture artifacts gate the matrix', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-validation-artifact-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-validation-artifact-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'editor-canvas-summary.json'), JSON.stringify({
    schema: 'wp-codebox/editor-canvas-probe/v1',
    summary: {
      selectorSummary: {
        groups: [
          {
            name: 'editor_block_invalid',
            selector: '.block-editor-warning',
            count: 1,
            visible_count: 1,
            first_match: { text: 'This block contains unexpected or invalid content' },
          },
        ],
      },
    },
  }));

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const finding = result.findings.find((item) => item.kind === 'editor_block_invalid');
  assert.ok(finding, 'expected an editor_block_invalid finding from the canvas-probe artifact');
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(result.fixtures[0].status, 'failed');
});

const ALL_VALID_EDITOR_VALIDATE_BLOCKS = {
  ...completeEditorValidation,
  result_count: 3,
  total_blocks: 3,
  valid_blocks: 3,
  results: [
    { name: 'core/heading', isValid: true, issues: [] },
    { name: 'core/paragraph', isValid: true, issues: [] },
    { name: 'core/image', isValid: true, issues: [] },
  ],
};

test('collectEditorValidation reads the editor-validate-blocks shape into headline metrics', () => {
  const metrics = collectEditorValidation(ALL_VALID_EDITOR_VALIDATE_BLOCKS);
  assert.equal(metrics.validation_method, 'wp.blocks.validateBlock');
  assert.equal(metrics.validation_provider, 'wordpress-block-editor');
  assert.equal(metrics.schema, 'wp-codebox/editor-validate-blocks/v1');
  assert.equal(metrics.content_source, 'edited-post-content');
  assert.equal(metrics.block_types_registered, 42);
  assert.equal(metrics.result_count, 3);
  assert.equal(metrics.results_complete, true);
  assert.equal(metrics.total_blocks, 3);
  assert.equal(metrics.valid_blocks, 3);
  assert.equal(metrics.invalid_blocks, 0);
  assert.equal(collectEditorValidation({ unrelated: true }), null);
});

test('collectEditorValidation normalizes recipe browser evidence to the command schema', () => {
  const metrics = collectEditorValidation({
    ...ALL_VALID_EDITOR_VALIDATE_BLOCKS,
    schema: 'wp-codebox/recipe-browser-evidence/v1',
  });

  assert.equal(metrics.schema, 'wp-codebox/editor-validate-blocks/v1');
  assert.equal(metrics.content_source, 'edited-post-content');
  assert.equal(metrics.block_types_registered, 42);
});

test('collectEditorValidation derives cross-surface totals from authoritative block results', () => {
  const metrics = collectEditorValidation({
    validation_method: 'wp.blocks.validateBlock',
    validation_provider: 'wordpress-block-editor',
    total_blocks: 1,
    valid_blocks: 1,
    invalid_blocks: 0,
    results: [
      { name: 'core/separator', isValid: false, issues: ['Invalid separator'] },
      { name: 'core/heading', isValid: true, issues: [] },
      { name: 'core/column', isValid: false, issues: ['Invalid column'] },
      { name: 'core/paragraph', isValid: true, issues: [] },
    ],
  });

  assert.equal(metrics.total_blocks, 4);
  assert.equal(metrics.valid_blocks, 2);
  assert.equal(metrics.invalid_blocks, 2);
});

test('editor-validate-blocks all-valid output reports a 1.0 valid-block rate with zero invalid and no findings', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-validate-valid-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-validate-valid-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(
    path.join(fixtureDirectory, 'editor-validate-blocks.json'),
    JSON.stringify({ fixture_id: 'simple-site', success: true, ...ALL_VALID_EDITOR_VALIDATE_BLOCKS }),
  );

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const fixture = result.fixtures[0];

  assert.equal(fixture.editor_quality.editor_validated, true);
  assert.equal(fixture.editor_quality.validation_method, EDITOR_VALIDATION_METHOD);
  assert.equal(fixture.editor_quality.validation_method, 'wp.blocks.validateBlock');
  assert.equal(fixture.editor_quality.editor_valid_block_rate, 1);
  assert.equal(fixture.editor_quality.invalid_block_count, 0);
  assert.equal(result.findings.some((finding) => finding.kind === 'editor_block_invalid'), false);

  // Summary-level editor-quality surfaces the real validity, distinct from PHP.
  assert.equal(result.summary.editor_quality.validation_method, 'wp.blocks.validateBlock');
  assert.equal(result.summary.editor_quality.editor_valid_block_rate, 1);
  assert.equal(result.summary.editor_quality.invalid_block_count, 0);
  assert.equal(result.summary.editor_quality.editor_validated_fixture_count, 1);
  assert.equal(fixture.status, 'passed');
});

test('editor-validate-blocks result from a codebox execution is associated to the fixture via the import step slug', () => {
  // Real shape: the per-fixture wp-codebox executions run in order
  // ([..., validate-artifact, editor-validate-blocks]). The editor step carries
  // NO fixture id of its own and emits its result as JSON on `result.stdout`,
  // so the collector must derive the fixture from the import step's --slug and
  // thread it forward to the editor execution. This is the wiring that makes a
  // `target=front-page` run report real imported-block counts.
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-validate-codebox-'));
  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    id: 'editor-validate-codebox-test',
    fixtures: [{ id: 'simple-site', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') }],
  });
  const codeboxOutput = {
    success: true,
    schema: 'wp-codebox/recipe-run-result/v1',
    executions: [
      {
        command: 'wordpress.wp-cli',
        args: ['command=static-site-importer validate-artifact --artifact=/wordpress/wp-content/uploads/x/simple-site/artifact.json --slug=simple-site --name=Simple --allow-missing-woocommerce --allow-failure'],
        result: { schema: 'wp-codebox/runtime-command-result/v1', status: 'ok', stdout: JSON.stringify({ success: true, fixture_id: 'simple-site', import_report: { theme_slug: 'simple-site' } }) },
      },
      {
        command: 'wordpress.editor-validate-blocks',
        args: ['target=front-page'],
        result: {
          schema: 'wp-codebox/runtime-command-result/v1',
          status: 'ok',
          stdout: JSON.stringify({
            schema: 'wp-codebox/editor-validate-blocks/v1',
            validation_method: 'wp.blocks.validateBlock',
            validation_provider: 'wordpress-block-editor',
            total_blocks: 5,
            valid_blocks: 4,
            invalid_blocks: 1,
            results: [
              { name: 'core/navigation', isValid: false, issues: ['Block validation failed for "core/navigation"'] },
              { name: 'core/heading', isValid: true, issues: [] },
              { name: 'core/paragraph', isValid: true, issues: [] },
              { name: 'core/image', isValid: true, issues: [] },
              { name: 'core/spacer', isValid: true, issues: [] },
            ],
          }),
        },
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const fixture = result.fixtures[0];

  // The real validateBlock counts are surfaced on the fixture, not lost.
  assert.equal(fixture.editor_validation.total_blocks, 5);
  assert.equal(fixture.editor_validation.valid_blocks, 4);
  assert.equal(fixture.editor_validation.invalid_blocks, 1);
  assert.equal(fixture.editor_quality.validation_method, 'wp.blocks.validateBlock');
  // The one invalid block becomes a gating editor_block_invalid finding.
  const finding = result.findings.find((item) => item.kind === 'editor_block_invalid');
  assert.ok(finding, 'expected an editor_block_invalid finding from the codebox editor-validate result');
  assert.equal(finding.loss_acceptance, 'unacceptable');
});

test('fixture matrix recipe steps emit fixture attribution metadata for import editor and visual phases', () => {
  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    id: 'recipe-step-metadata-test',
    fixtures: [{ id: 'simple-site', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') }],
  });
  const recipe = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/static-site-importer', artifactsDirectory: '/artifacts/static-site-importer-fixture-matrix' });
  const steps = recipe.workflow.steps.filter((step) => step.metadata?.fixture_id === 'simple-site');

  assert.equal(steps.find((step) => step.metadata.phase === 'import').metadata.artifact, '/artifacts/static-site-importer-fixture-matrix/simple-site/artifact.json');
  assert.equal(steps.find((step) => step.metadata.phase === 'editor').metadata.target, 'front-page');
  assert.equal(steps.find((step) => step.metadata.phase === 'editor').allowFailure, true);
  assert.equal(steps.find((step) => step.metadata.phase === 'visual').metadata.candidate_url, '/');
  assert.equal(steps.find((step) => step.metadata.phase === 'visual').allowFailure, true);
  assert.match(steps.find((step) => step.metadata.phase === 'visual').metadata.source_url, /simple-site\/source\/index\.html$/);
});

test('stepFailures are attributed by metadata fixture_id before phase index fallback and expose slow fixture metadata', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-step-failures-metadata-'));
  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    id: 'step-failures-metadata-test',
    fixtures: [
      { id: 'fixture-alpha', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') },
      { id: 'fixture-beta', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') },
    ],
  });
  const codeboxOutput = {
    schema: 'wp-codebox/recipe-run-result/v1',
    executions: [
      {
        command: 'wordpress.visual-compare',
        recipePhase: 'visual',
        recipeStepIndex: 7,
        recipeStepMetadata: { fixture_id: 'fixture-alpha', phase: 'visual', source_url: 'file:///alpha/index.html', candidate_url: '/alpha/' },
        args: ['source-url=file:///alpha/index.html', 'candidate-url=/alpha/'],
      },
    ],
    stepFailures: [
      {
        recipePhase: 'visual',
        recipeStepIndex: 7,
        metadata: { fixture_id: 'fixture-beta', phase: 'visual', source_url: 'file:///beta/index.html', candidate_url: '/beta/' },
        command: 'wordpress.visual-compare',
        duration_ms: 120000,
        timeout_class: 'browser_navigation_timeout',
        message: 'Visual compare timed out.',
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const alpha = result.fixtures.find((fixture) => fixture.fixture_id === 'fixture-alpha');
  const beta = result.fixtures.find((fixture) => fixture.fixture_id === 'fixture-beta');
  const diagnostic = beta.diagnostics.find((item) => item.kind === 'visual_timeout');

  assert.equal(alpha.diagnostics.some((item) => item.kind === 'visual_timeout'), false);
  assert.equal(diagnostic.recipe_step_index, 7);
  assert.equal(diagnostic.recipe_phase, 'visual');
  assert.equal(diagnostic.command, 'wordpress.visual-compare');
  assert.equal(diagnostic.loss_class, 'visual_timeout');
  assert.equal(diagnostic.duration_ms, 120000);
  assert.equal(diagnostic.timeout_class, 'browser_navigation_timeout');
  assert.equal(diagnostic.source_url, 'file:///beta/index.html');
  assert.equal(diagnostic.candidate_url, '/beta/');
  assert.equal(result.slow_fixtures[0].fixture_id, 'fixture-beta');
  assert.equal(result.summary.slow_fixtures[0].fixture_id, 'fixture-beta');
  assert.equal(result.summary.metadata.slow_fixtures[0].timeout_class, 'browser_navigation_timeout');
  assert.deepEqual(beta.quality_gate.failure_categories, ['harness_diagnostic', 'visual_timeout']);
  assert.equal(result.summary.fixture_failure_categories.visual_timeout, 1);
});

test('visual candidate-capture timeouts classify as fixture-attributed visual_timeout evidence', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-candidate-timeout-'));
  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    id: 'visual-candidate-timeout-test',
    fixtures: [{ id: 'cursed-pangolin-fanwiki', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') }],
  });
  const codeboxOutput = {
    schema: 'wp-codebox/recipe-run-result/v1',
    executions: [
      {
        command: 'wordpress.visual-compare',
        recipePhase: 'visual',
        recipeStepIndex: 30,
        recipeStepMetadata: { fixture_id: 'cursed-pangolin-fanwiki', phase: 'visual', source_url: 'file:///fanwiki/index.html', candidate_url: '/' },
        args: ['source-url=file:///fanwiki/index.html', 'candidate-url=/'],
      },
    ],
    stepFailures: [
      {
        recipePhase: 'visual',
        recipeStepIndex: 30,
        command: 'wordpress.visual-compare',
        duration_ms: 120001,
        message: 'candidate-capture exceeded 120000ms.',
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const fixture = result.fixtures[0];
  const finding = result.findings.find((item) => item.kind === 'visual_timeout');

  assert.ok(finding, 'expected visual_timeout finding');
  assert.equal(finding.fixture_id, 'cursed-pangolin-fanwiki');
  assert.equal(finding.duration_ms, 120001);
  assert.equal(finding.candidate_url, '/');
  assert.deepEqual(fixture.quality_gate.failure_categories, ['harness_diagnostic', 'visual_timeout']);
  assert.equal(result.summary.fixture_failure_categories.visual_timeout, 1);
  assert.equal(result.summary.fixture_failure_categories.missing_evidence, undefined);
  assert.equal(result.slow_fixtures[0].fixture_id, 'cursed-pangolin-fanwiki');
});

test('step_failures fall back to recipe phase index when metadata fixture_id is absent', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-step-failures-fallback-'));
  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    id: 'step-failures-fallback-test',
    fixtures: [{ id: 'simple-site', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') }],
  });
  const codeboxOutput = {
    schema: 'wp-codebox/recipe-run-result/v1',
    executions: [
      {
        command: 'wordpress.editor-validate-blocks',
        recipePhase: 'editor',
        recipeStepIndex: 3,
        recipeStepMetadata: { fixture_id: 'simple-site', phase: 'editor', post_id: 42 },
        args: ['post-id=42'],
      },
    ],
    step_failures: [
      {
        phase: 'editor',
        index: 3,
        command: 'wordpress.editor-validate-blocks',
        durationMs: 2500,
        error: 'Editor validation failed.',
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const diagnostic = result.fixtures[0].diagnostics.find((item) => item.kind === 'recipe_step_failure');

  assert.equal(diagnostic.recipe_step_index, 3);
  assert.equal(diagnostic.recipe_phase, 'editor');
  assert.equal(diagnostic.post_id, 42);
  assert.equal(diagnostic.duration_ms, 2500);
  assert.match(diagnostic.reason, /Editor validation failed/);
});

test('child_command_failures with fixture metadata attribute runtime failures without fallback smearing', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-child-command-failure-'));
  const matrix = createFixtureMatrix({
    fixture_root: fixtureRoot,
    id: 'child-command-failure-test',
    fixtures: [
      { id: 'fixture-alpha', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') },
      { id: 'fixture-beta', fixture_path: path.join(fixtureRoot, 'simple-site'), directory: path.join(fixtureRoot, 'simple-site') },
    ],
  });
  const codeboxOutput = {
    results: [{ fixture_id: 'fixture-alpha', success: true }],
    runtime: {
      child_command_failures: [
        {
          kind: 'child_command_failed',
          batch_id: 'batch-002',
          fixture_ids: ['fixture-beta'],
          command: { argv: ['wp-codebox', 'recipe-run', '/tmp/batch-002.json'] },
          exit_status: null,
          error_code: 'ENOENT',
          error_signal: 'SIGKILL',
          stdout_tail: 'runtime stdout tail',
          stderr_tail: 'runtime stderr tail',
          recipe_file: '/tmp/batch-002.json',
          output_file: '/tmp/batch-002-output.json',
          artifacts_directory: '/tmp/batch-002-artifacts',
          replay_command: { argv: ['wp-codebox', 'recipe-run', '--recipe', '/tmp/batch-002.json'] },
          message: 'WP Codebox recipe-run exited without a status.',
          artifact_refs: { batch_recipe: '/tmp/batch-002.json' },
        },
      ],
    },
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const alpha = result.fixtures.find((fixture) => fixture.fixture_id === 'fixture-alpha');
  const beta = result.fixtures.find((fixture) => fixture.fixture_id === 'fixture-beta');
  const finding = result.findings.find((item) => item.fixture_id === 'fixture-beta');

  assert.equal(alpha.status, 'passed');
  assert.equal(beta.status, 'failed');
  assert.equal(finding.kind, 'recipe_step_failure');
  assert.equal(finding.loss_class, 'runtime_execution_failed');
  assert.equal(finding.command, 'wp-codebox recipe-run /tmp/batch-002.json');
  assert.deepEqual(finding.command_argv, ['wp-codebox', 'recipe-run', '/tmp/batch-002.json']);
  assert.equal(finding.error_code, 'ENOENT');
  assert.equal(finding.error_signal, 'SIGKILL');
  assert.equal(finding.stdout_tail, 'runtime stdout tail');
  assert.equal(finding.stderr_tail, 'runtime stderr tail');
  assert.equal(finding.recipe_file, '/tmp/batch-002.json');
  assert.equal(finding.output_file, '/tmp/batch-002-output.json');
  assert.equal(finding.artifacts_directory, '/tmp/batch-002-artifacts');
  assert.deepEqual(finding.replay_command.argv, ['wp-codebox', 'recipe-run', '--recipe', '/tmp/batch-002.json']);
  assert.equal(result.summary.unacceptable_loss_classes.runtime_execution_failed, 1);
  assert.equal(result.summary.fixture_failure_categories.runtime_execution_failed, 1);
  assert.equal(result.summary.fixture_failure_categories.fixture_failed, undefined);
  assert.equal(result.summary.fixture_failure_categories.missing_evidence, undefined);
  assert.equal(result.summary.fixture_exemplars[0].batch_id, 'batch-002');
  assert.equal(result.summary.fixture_exemplars[0].stderr_tail, 'runtime stderr tail');
});

test('unavailable editor validation fails honestly without fabricated validated-block metrics', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-validate-unavailable-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-validate-unavailable-test' });
  const codeboxOutput = {
    success: false,
    schema: 'wp-codebox/recipe-run-result/v1',
    executions: [
      {
        command: 'wordpress.wp-cli',
        args: ['command=static-site-importer validate-artifact --artifact=/wordpress/wp-content/uploads/x/simple-site/artifact.json --slug=simple-site --name=Simple --allow-missing-woocommerce --allow-failure'],
        result: { schema: 'wp-codebox/runtime-command-result/v1', status: 'ok', stdout: JSON.stringify({ success: true, fixture_id: 'simple-site' }) },
      },
      {
        command: 'wordpress.editor-validate-blocks',
        args: ['target=front-page'],
        result: {
          schema: 'wp-codebox/runtime-command-result/v1',
          status: 'error',
          error: 'Unknown command wordpress.editor-validate-blocks',
        },
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const fixture = result.fixtures[0];

  assert.equal(fixture.status, 'failed');
  assert.equal(fixture.editor_validation, null);
  assert.notEqual(fixture.editor_quality.editor_validated, true);
  assert.equal(fixture.editor_quality.editor_validated_block_total, undefined);
  assert.equal(fixture.editor_quality.invalid_block_count, undefined);
  assert.match(fixture.error, /Unknown command wordpress\.editor-validate-blocks/);
});

test('editor-validate-blocks invalid block is counted and surfaced as a gating finding with name and reason', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-editor-validate-invalid-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'editor-validate-invalid-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(
    path.join(fixtureDirectory, 'editor-validate-blocks.json'),
    JSON.stringify({
      fixture_id: 'simple-site',
      success: false,
      schema: 'wp-codebox/editor-validate-blocks/v1',
      validation_method: 'wp.blocks.validateBlock',
      validation_provider: 'wordpress-block-editor',
      total_blocks: 3,
      valid_blocks: 2,
      invalid_blocks: 1,
      results: [
        { name: 'core/heading', isValid: true, issues: [] },
        { name: 'core/columns', isValid: false, issues: ['Block validation failed for "core/columns": content mismatch'] },
        { name: 'core/paragraph', isValid: true, issues: [] },
      ],
    }),
  );

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const fixture = result.fixtures[0];

  // Real editor-validity: 2/3 valid, one invalid.
  assert.equal(fixture.editor_quality.validation_method, 'wp.blocks.validateBlock');
  assert.equal(fixture.editor_quality.invalid_block_count, 1);
  assert.equal(fixture.editor_quality.editor_valid_block_rate, 0.6667);
  assert.equal(result.summary.editor_quality.invalid_block_count, 1);
  assert.equal(result.summary.editor_quality.editor_valid_block_rate, 0.6667);

  // The invalid block flows into a gating editor_block_invalid finding carrying
  // the block name and the validateBlock issue reason.
  const finding = result.findings.find((item) => item.kind === 'editor_block_invalid');
  assert.ok(finding, 'expected an editor_block_invalid finding for the invalid block');
  assert.equal(finding.observed_block_name, 'core/columns');
  assert.match(finding.reason, /core\/columns/);
  assert.match(finding.reason, /content mismatch/);
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(result.findings.some((item) => item.kind === 'invalid_block_content'), false);
  assert.equal(result.summary.fixture_categories.missing_evidence, undefined);
  assert.equal(fixture.status, 'failed');
});

test('scores editor-quality metrics from generic block composition and rolls them up', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-editor-quality-'));
  const marketing = path.join(root, 'marketing-static');
  const docs = path.join(root, 'docs-blog');
  mkdirSync(marketing, { recursive: true });
  mkdirSync(docs, { recursive: true });
  writeFileSync(path.join(marketing, 'index.html'), '<h1>Landing</h1>');
  writeFileSync(path.join(marketing, 'fixture.json'), JSON.stringify({ class: 'marketing/static' }));
  writeFileSync(path.join(docs, 'index.html'), '<article>Docs</article>');
  writeFileSync(path.join(docs, 'fixture.json'), JSON.stringify({ class: 'docs/blog' }));
  const matrix = createFixtureMatrix({ fixture_root: root, id: 'editor-quality-test' });

  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'marketing-static',
        status: 'passed',
        // 8 native (core/* + jetpack/* + woocommerce/*), 2 core/html => 0.8 / 0.2.
        block_type_counts: {
          'core/paragraph': 4,
          'core/heading': 2,
          'jetpack/contact-form': 1,
          'woocommerce/product': 1,
          'core/html': 2,
        },
      },
      {
        fixture_id: 'docs-blog',
        status: 'passed',
        // 6 native, 4 core/html => 0.6 / 0.4.
        block_type_counts: {
          'core/paragraph': 6,
          'core/html': 4,
        },
      },
    ],
  });

  const marketingFixture = result.fixtures.find((fixture) => fixture.fixture_id === 'marketing-static');
  assert.equal(marketingFixture.editor_quality.block_total, 10);
  assert.equal(marketingFixture.editor_quality.native_block_count, 8);
  assert.equal(marketingFixture.editor_quality.core_html_block_count, 2);
  assert.equal(marketingFixture.editor_quality.native_conversion_rate, 0.8);
  assert.equal(marketingFixture.editor_quality.core_html_fallback_ratio, 0.2);
  assert.equal(marketingFixture.editor_quality.source, 'block_type_breakdown');
  assert.equal(marketingFixture.editor_quality.editor_invalid_count, 0);

  // Aggregate uses summed totals (14 native / 20 total = 0.7; 6 core/html / 20 = 0.3).
  assert.equal(result.summary.editor_quality.block_total, 20);
  assert.equal(result.summary.editor_quality.native_block_count, 14);
  assert.equal(result.summary.editor_quality.core_html_block_count, 6);
  assert.equal(result.summary.editor_quality.native_conversion_rate, 0.7);
  assert.equal(result.summary.editor_quality.core_html_fallback_ratio, 0.3);
  assert.equal(result.summary.editor_quality.scored_fixture_count, 2);
  assert.equal(result.summary.editor_quality.native_rate_gate.enabled, false);

  // Per-class rollup carries the same generic metric.
  assert.equal(result.summary.quality_budgets['docs/blog'].editor_quality.native_conversion_rate, 0.6);
  assert.equal(result.summary.classes['marketing/static'].editor_quality.native_conversion_rate, 0.8);
});

test('parseSerializedBlockNames extracts wp: block names and normalizes core blocks', () => {
  const markup = [
    '<!-- wp:heading -->\n<h2>Title</h2>\n<!-- /wp:heading -->',
    '<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->',
    '<!-- wp:jetpack/contact-form {"subject":"x"} -->...<!-- /wp:jetpack/contact-form -->',
    '<!-- wp:spacer {"height":"20px"} /-->',
    '<!-- wp:html -->\n<svg></svg>\n<!-- /wp:html -->',
  ].join('\n');

  assert.deepEqual(parseSerializedBlockNames(markup), [
    'core/heading',
    'core/paragraph',
    'jetpack/contact-form',
    'core/spacer',
    'core/html',
  ]);
  // Closing comments and non-block content never count, and non-strings are safe.
  assert.deepEqual(parseSerializedBlockNames('<p>no blocks here</p>'), []);
  assert.deepEqual(parseSerializedBlockNames(null), []);
});

test('collectBlockComposition computes native rate from serialized post_content (7 native + 3 core/html => 0.7 / 0.3)', () => {
  const native = [
    '<!-- wp:heading -->\n<h2>H</h2>\n<!-- /wp:heading -->',
    '<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->',
    '<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->',
    '<!-- wp:list -->\n<ul><li>x</li></ul>\n<!-- /wp:list -->',
    '<!-- wp:image {"id":1} -->\n<figure></figure>\n<!-- /wp:image -->',
    '<!-- wp:jetpack/contact-form -->...<!-- /wp:jetpack/contact-form -->',
    '<!-- wp:woocommerce/product-collection -->...<!-- /wp:woocommerce/product-collection -->',
  ];
  const coreHtml = [
    '<!-- wp:html -->\n<svg></svg>\n<!-- /wp:html -->',
    '<!-- wp:html -->\n<canvas></canvas>\n<!-- /wp:html -->',
    '<!-- wp:html -->\n<audio></audio>\n<!-- /wp:html -->',
  ];
  const composition = collectBlockComposition({ post_content: [...native, ...coreHtml].join('\n') });

  assert.equal(composition.source, 'serialized_blocks');
  assert.equal(composition.block_total, 10);
  assert.equal(composition.native_block_count, 7);
  assert.equal(composition.core_html_block_count, 3);

  // The same composition drives the per-fixture editor-quality score.
  const editorQuality = computeFixtureEditorQuality({ fixture_id: 'serialized', block_composition: composition }, []);
  assert.equal(editorQuality.scored, true);
  assert.equal(editorQuality.native_conversion_rate, 0.7);
  assert.equal(editorQuality.core_html_fallback_ratio, 0.3);
});

test('collectBlockComposition derives the rate from SSI import-report block_documents on live runs', () => {
  // Shape that real Lab/WP Codebox runs emit: SSI records each materialized page's
  // total block_count plus its core/html + freeform fallback counts. No explicit
  // block_type_counts map is present, which is why the metric used to stay unscored.
  const payload = {
    import_report: {
      materialized_content: {
        block_documents: [
          { source_path: 'posts/page-home.post_content', block_count: 5, core_html_block_count: 1, freeform_block_count: 0 },
          { source_path: 'posts/page-faq.post_content', block_count: 5, core_html_block_count: 2, freeform_block_count: 0 },
        ],
      },
      // Generated-theme duplicates the materialized pages; must not be double counted.
      generated_theme: {
        block_documents: [
          { source_path: 'posts/page-home.post_content', block_count: 5, core_html_block_count: 1, freeform_block_count: 0 },
          { source_path: 'posts/page-faq.post_content', block_count: 5, core_html_block_count: 2, freeform_block_count: 0 },
        ],
      },
    },
  };
  const composition = collectBlockComposition(payload);

  assert.equal(composition.source, 'block_documents');
  assert.equal(composition.block_total, 10);
  assert.equal(composition.core_html_block_count, 3);
  // native = total - core/html - freeform = 10 - 3 - 0 = 7.
  assert.equal(composition.native_block_count, 7);
});

test('native_conversion_rate populates end-to-end from an import-report block_documents payload', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'native-rate-live-run-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'passed',
        import_report: {
          materialized_content: {
            block_documents: [
              // 10 total blocks, 3 of them core/html => 7 native => 0.7 native rate.
              { source_path: 'posts/page-home.post_content', block_count: 10, core_html_block_count: 3, freeform_block_count: 0 },
            ],
          },
        },
      },
    ],
  });

  const fixture = result.fixtures.find((row) => row.fixture_id === 'simple-site');
  assert.equal(fixture.editor_quality.scored, true);
  assert.equal(fixture.editor_quality.source, 'block_documents');
  assert.equal(fixture.editor_quality.native_conversion_rate, 0.7);
  assert.equal(fixture.editor_quality.core_html_fallback_ratio, 0.3);
  // The aggregate now carries a real native rate instead of a 0/0 null.
  assert.equal(result.summary.editor_quality.native_conversion_rate, 0.7);
  assert.equal(result.summary.editor_quality.core_html_fallback_ratio, 0.3);
  assert.equal(result.summary.editor_quality.scored_fixture_count, 1);
});

test('opt-in native-rate gate fails low-native fixtures while editor_invalid_count reuses #537 findings', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'native-rate-gate-test' });
  const makeResult = () => ({
    fixture_id: 'simple-site',
    status: 'passed',
    // 3 native / 7 total ≈ 0.43 native conversion rate.
    block_type_counts: { 'core/paragraph': 3, 'core/html': 4 },
    diagnostics: [
      { kind: 'editor_block_invalid', selector: '.block-editor-warning', message: 'Editor rendered 1 invalid-block warning for the imported post.' },
    ],
  });

  // Gate off (default): metrics are scored, but no native-rate finding is emitted.
  const ungated = normalizeFixtureMatrixResult({ matrix, results: [makeResult()] });
  assert.equal(ungated.fixtures[0].editor_quality.editor_invalid_count, 1);
  assert.ok(ungated.fixtures[0].editor_quality.native_conversion_rate < 0.5);
  assert.equal(ungated.findings.some((finding) => finding.kind === 'native_conversion_rate_below_min'), false);

  // Gate on: the low-native fixture earns an unacceptable finding and fails.
  const gated = normalizeFixtureMatrixResult({ matrix, results: [makeResult()], editorQuality: { minNativeRate: 0.8 } });
  const finding = gated.findings.find((row) => row.kind === 'native_conversion_rate_below_min');
  assert.ok(finding, 'expected a native_conversion_rate_below_min finding when the gate is enabled');
  assert.equal(finding.loss_class, 'low_native_conversion');
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(gated.fixtures[0].status, 'failed');
  assert.equal(gated.summary.editor_quality.native_rate_gate.enabled, true);
  assert.equal(gated.summary.editor_quality.native_rate_gate.min_native_rate, 0.8);
});

test('recipe runs a wordpress.visual-compare visual-parity step after each import', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-recipe-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    pixelThreshold: 0.05,
    visualParityPixelmatchThreshold: 0.05,
  });

  // Resolve semantic phases so transport steps can be inserted without weakening the ordering contract.
  const visualSetupStep = recipe.workflow.steps.find((step) => step.metadata?.phase === 'visual-setup');
  assert.equal(visualSetupStep.command, 'wordpress.wp-cli');
  assert.equal(visualSetupStep.metadata.phase, 'visual-setup');
  assert.match(visualSetupStep.args[0], /wp_update_custom_css_post/);
  const visualStep = recipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
  assert.equal(visualStep.command, 'wordpress.visual-compare');
  const comparison = visualCompareMatrixComparison(visualStep);
  assert.equal(comparison.sourceUrl, 'file:///tmp/artifacts/simple-site/source/index.html');
  assert.equal(comparison.candidateUrl, '/');
  assert.equal(comparison.fullPage, true);
  assert.equal(comparison.threshold, 0.05);

  const defaultThresholdRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });
  const defaultThresholdVisualStep = defaultThresholdRecipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
  assert.equal(defaultThresholdVisualStep.command, 'wordpress.visual-compare');
  assert.equal(visualCompareMatrixComparison(defaultThresholdVisualStep).threshold, 0.01, 'visual parity defaults to a sub-perceptual colour distance, not a bit-exact render');

  const disabled = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    visualParity: false,
  });
  assert.equal(disabled.workflow.steps.some((step) => step.command === 'wordpress.visual-compare'), false);
});

test('visualParityCompareStep composes the existing wordpress.visual-compare command with per-fixture overrides', () => {
  const step = visualParityCompareStep({
    fixture: { id: 'shop', source_url: 'http://127.0.0.1:4173/shop/index.html', candidate_url: '/?p=42' },
    visualParityPixelmatchThreshold: 0.2,
  });
  assert.equal(step.command, 'wordpress.visual-compare');
  assert.equal(step.allowFailure, true);
  const comparison = visualCompareMatrixComparison(step);
  assert.equal(comparison.name, 'shop');
  assert.equal(comparison.sourceUrl, 'http://127.0.0.1:4173/shop/index.html');
  assert.equal(comparison.candidateUrl, '/?p=42');
  assert.equal(comparison.threshold, 0.2);
  assert.equal(comparison.sourceLabel, 'shop-source');
  assert.equal(comparison.candidateLabel, 'shop-candidate');
  assert.equal(comparison.fullPage, true);
});

test('visual attribution options normalize positive limits and targeted selector lists', () => {
  assert.deepEqual(normalizeVisualAttributionOptions({
    maxExplanationElements: '500',
    max_explanation_candidates: 600,
    explainSelectors: [' .hero ', '.hero', '', '#footer'],
  }), {
    maxExplanationElements: 500,
    maxExplanationCandidates: 600,
    explainSelectors: ['.hero', '#footer'],
  });
  assert.deepEqual(normalizeVisualAttributionOptions({
    maxExplanationElements: '0',
    maxExplanationCandidates: '1.5',
    explainSelectors: ' .hero, #footer, .hero ',
  }), {
    maxExplanationElements: undefined,
    maxExplanationCandidates: undefined,
    explainSelectors: ['.hero', '#footer'],
  });
});

test('visual parity defaults require the candidate font readiness record', () => {
  const step = visualParityCompareStep({ fixture: { id: 'shop' } });
  assert.equal(step.args[0], 'matrix-json={"comparisons":[{"name":"shop","sourceUrl":"/wp-content/uploads/static-site-importer-fixture-matrix/shop/source/index.html","candidateUrl":"/","sourceLabel":"shop-source","candidateLabel":"shop-candidate","viewport":"1280x1600","fullPage":true,"waitFor":"duration","durationMs":"4000ms","blockExternalRequests":true,"candidateRequiredReadinessRecord":"#static-site-importer-font-readiness","threshold":0.01}]}');
});

test('visual attribution options reach every fixture matrix comparison', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-recipe-'));
  const fixtureDirectory = path.join(root, 'fixture');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'index.html'), '<h1>Home</h1>');
  writeFileSync(path.join(fixtureDirectory, 'contact.html'), '<h1>Contact</h1>');
  const recipe = buildFixtureMatrixRecipe({
    matrix: createFixtureMatrix({ fixture_root: root }),
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    surfaceCoverage: 1,
    maxExplanationElements: 500,
    maxExplanationCandidates: 600,
    explainSelectors: '.hero, #footer',
    animatedMedia: 'first-frame',
  });
  const comparisons = recipe.workflow.steps
    .filter((step) => step.command === 'wordpress.visual-compare')
    .map(visualCompareMatrixComparison);
  assert.equal(comparisons.length, 2);
  for (const comparison of comparisons) {
    assert.equal(comparison.maxExplanationElements, 500);
    assert.equal(comparison.maxExplanationCandidates, 600);
    assert.deepEqual(comparison.explainSelectors, ['.hero', '#footer']);
    assert.equal(comparison.animatedMedia, 'first-frame');
  }
});

test('fixture matrix maps visual attribution environment settings', () => {
  const options = optionsFromEnv({
    SSI_FIXTURE_MATRIX_MAX_EXPLANATION_ELEMENTS: '500',
    SSI_FIXTURE_MATRIX_MAX_EXPLANATION_CANDIDATES: '600',
    SSI_FIXTURE_MATRIX_EXPLAIN_SELECTORS: '.hero, #footer',
  });
  assert.equal(options.maxExplanationElements, 500);
  assert.equal(options.maxExplanationCandidates, 600);
  assert.deepEqual(options.explainSelectors, ['.hero', '#footer']);
});

test('fixture matrix maps the portable transformer reference setting', () => {
  const reference = 'c'.repeat(40);
  assert.equal(optionsFromEnv({
    SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_REFERENCE: reference,
  }).blocksEnginePhpTransformerReference, reference);
});

test('fixture matrix maps visual parity external request isolation', () => {
  assert.equal(optionsFromEnv({ SSI_FIXTURE_MATRIX_VISUAL_PARITY_BLOCK_EXTERNAL_REQUESTS: '0' }).visualParityBlockExternalRequests, false);
  assert.equal(optionsFromEnv({ SSI_FIXTURE_MATRIX_VISUAL_PARITY_BLOCK_EXTERNAL_REQUESTS: '1' }).visualParityBlockExternalRequests, true);
});

test('fixture matrix maps and validates the animated media capture policy', () => {
  assert.equal(optionsFromEnv({ SSI_FIXTURE_MATRIX_ANIMATED_MEDIA: 'first-frame' }).animatedMedia, 'first-frame');
  assert.throws(
    () => visualParityCompareStep({ fixture: { id: 'animated' }, animatedMedia: 'frame-2' }),
    /animated-media must be allow or first-frame/,
  );
});

test('fixture matrix forwards visual parity external request isolation to recipes', async () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-external-requests-'));
  await runFixtureMatrix({
    fixtureRoot,
    outputDirectory,
    staticSiteImporterPath: packageRoot,
    visualParityBlockExternalRequests: false,
  });
  const recipe = JSON.parse(readFileSync(path.join(outputDirectory, 'wp-codebox-static-site-fixture-matrix-recipe.json'), 'utf8'));
  const visualStep = recipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
  assert.equal(visualCompareMatrixComparison(visualStep).blockExternalRequests, false);
});

test('fixture matrix operator plan exposes and forwards visual attribution settings', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture'), { recursive: true });
  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot,
    maxExplanationElements: '500',
    maxExplanationCandidates: '600',
    explainSelectors: '.hero, #footer',
  });
  assert.deepEqual(plan.visual_attribution, {
    max_explanation_elements: 500,
    max_explanation_candidates: 600,
    explain_selectors: ['.hero', '#footer'],
  });
  const args = plan.steps.at(-1).args;
  assert.ok(args.includes('bench_env.SSI_FIXTURE_MATRIX_MAX_EXPLANATION_ELEMENTS=500'));
  assert.ok(args.includes('bench_env.SSI_FIXTURE_MATRIX_MAX_EXPLANATION_CANDIDATES=600'));
  assert.ok(args.includes('bench_env.SSI_FIXTURE_MATRIX_EXPLAIN_SELECTORS=.hero,#footer'));
});

test('fixture matrix evidence records effective visual attribution settings', async () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-evidence-'));
  const { summary } = await runFixtureMatrix({
    fixtureRoot,
    outputDirectory,
    staticSiteImporterPath: packageRoot,
    maxExplanationElements: 500,
    maxExplanationCandidates: 600,
    explainSelectors: '.hero, #footer',
  });
  assert.deepEqual(summary.metadata.visual_attribution, {
    maxExplanationElements: 500,
    maxExplanationCandidates: 600,
    explainSelectors: ['.hero', '#footer'],
  });
});

test('visualParityCompareStep requests full-page capture by default with explicit opt-out', () => {
  const defaultStep = visualParityCompareStep({ fixture: { id: 'tall' } });
  assert.equal(visualCompareMatrixComparison(defaultStep).fullPage, true);

  for (const optOut of [
    { fixture: { id: 'tall' }, fullPage: false },
    { fixture: { id: 'tall' }, full_page: 'false' },
    { fixture: { id: 'tall' }, visual_parity_full_page: '0' },
  ]) {
    assert.equal(visualCompareMatrixComparison(visualParityCompareStep(optOut)).fullPage, false);
  }
});

test('default visual-parity source-url targets the staged same-origin source tree', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-source-url-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });

  const visualStep = recipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
  assert.equal(
    visualCompareMatrixComparison(visualStep).sourceUrl,
    '/wp-content/uploads/static-site-importer-fixture-matrix/simple-site/source/index.html',
  );
  // Candidate defaults to the imported front page served at `/`.
  assert.equal(visualCompareMatrixComparison(visualStep).candidateUrl, '/');
});

test('explicit visual-parity source base can still target a served uploads path', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-served-source-url-test' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    staticSiteImporterPath: '/tmp/static-site-importer',
    visualParitySourceBaseUrl: '/wp-content/uploads/static-site-importer-fixture-matrix',
  });

  const visualStep = recipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
  assert.equal(visualCompareMatrixComparison(visualStep).sourceUrl, '/wp-content/uploads/static-site-importer-fixture-matrix/simple-site/source/index.html');
});

test('default visual-parity source-url follows nested fixture entrypoint', () => {
  const step = visualParityCompareStep({
    fixture: { id: 'liquid-bonsai', entrypoint: 'saveweb2zip-com-liquidbonsai-com/index.html' },
    sourceBaseUrl: '/wp-content/uploads/static-site-importer-fixture-matrix',
  });

  assert.ok(
    visualCompareMatrixComparison(step).sourceUrl === '/wp-content/uploads/static-site-importer-fixture-matrix/liquid-bonsai/source/saveweb2zip-com-liquidbonsai-com/index.html',
  );
});

test('stageFixtureSource copies the normalized fixture source into the served source/ subdir', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-parity-stage-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-stage-test' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix });

  const sourceDir = path.join(outputDirectory, 'simple-site', 'source');
  // The fixture's own files (index.html + style.css) are served from source/,
  // preserving their relative layout so assets resolve.
  assert.ok(existsSync(path.join(sourceDir, 'index.html')), 'staged source index.html should exist');
  assert.ok(existsSync(path.join(sourceDir, 'style.css')), 'staged source style.css should exist');
  const stagedHtml = readFileSync(path.join(sourceDir, 'index.html'), 'utf8');
  assert.match(stagedHtml, /data-ssi-visual-parity-deterministic/);
  assert.match(stagedHtml, /animation-duration: 0\.001ms !important/);
  assert.ok(stagedHtml.includes(VISUAL_PARITY_DETERMINISTIC_CSS.trim()));
  assert.match(stagedHtml, /data-ssi-visual-parity-svg-normalization/);
  assert.match(stagedHtml, /clone\.style\.setProperty\('color', computed\.color\)/);
  assert.match(stagedHtml, /new XMLSerializer\(\)\.serializeToString\(clone\)/);
  // The import payload (artifact.json) is still written alongside, unchanged.
  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'artifact.json')), 'artifact.json should still be written');
  assert.equal(written.metadata.source_staging.status, 'staged');
  assert.ok(written.metadata.artifact_bytes.staged_source > 0);
  assert.ok(Number.isFinite(written.metadata.performance.artifact_writing_ms));
});

test('staged visual source rebases site-root assets without changing the import artifact', () => {
  const fixtureDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-root-relative-source-'));
  const sourceDirectory = path.join(fixtureDirectory, 'fixture');
  mkdirSync(path.join(sourceDirectory, 'nested'), { recursive: true });
  mkdirSync(path.join(sourceDirectory, 'assets', 'css'), { recursive: true });
  writeFileSync(path.join(sourceDirectory, 'index.html'), '<link rel="stylesheet" href="/assets/css/site.css"><style>@font-face{src:url("/fonts/example.woff2")}</style><img src="/media/hero.jpg" srcset="/media/hero.jpg 1x, /media/hero@2x.jpg 2x" style="background:url(\'/media/hero.jpg\')"><a href="//external.test/page">External</a>');
  writeFileSync(path.join(sourceDirectory, 'nested', 'index.html'), '<script src="/assets/app.js"></script><style>.hero{background:url(/media/hero.jpg)}</style><a href="#section">Section</a>');
  writeFileSync(path.join(sourceDirectory, 'assets', 'css', 'site.css'), '@import "/assets/css/base.css"; .hero{background:url(\'/media/hero.jpg?size=large#crop\')} .icon{background:url(data:image/png;base64,AA)}');

  const fixture = { id: 'Root Relative', directory: sourceDirectory };
  const artifact = buildFixtureArtifact(fixture);
  const artifactHtml = Buffer.from(artifact.files.find((file) => file.path === 'website/index.html').content_base64, 'base64').toString('utf8');
  assert.match(artifactHtml, /href="\/assets\/css\/site\.css"/);

  stageFixtureSource(fixture, fixtureDirectory);
  const rootHtml = readFileSync(path.join(fixtureDirectory, 'source', 'index.html'), 'utf8');
  const nestedHtml = readFileSync(path.join(fixtureDirectory, 'source', 'nested', 'index.html'), 'utf8');
  const css = readFileSync(path.join(fixtureDirectory, 'source', 'assets', 'css', 'site.css'), 'utf8');
  assert.match(rootHtml, /href="\.\/assets\/css\/site\.css"/);
  assert.match(rootHtml, /src="\.\/media\/hero\.jpg"/);
  assert.match(rootHtml, /srcset="\.\/media\/hero\.jpg 1x, \.\/media\/hero@2x\.jpg 2x"/);
  assert.match(rootHtml, /url\("\.\/fonts\/example\.woff2"\)/);
  assert.match(rootHtml, /style="background:url\('\.\/media\/hero\.jpg'\)"/);
  assert.match(rootHtml, /href="\/\/external\.test\/page"/);
  assert.match(nestedHtml, /src="\.\.\/assets\/app\.js"/);
  assert.match(nestedHtml, /url\(\.\.\/media\/hero\.jpg\)/);
  assert.match(nestedHtml, /href="#section"/);
  assert.match(css, /@import "\.\.\/\.\.\/assets\/css\/base\.css"/);
  assert.match(css, /url\('\.\.\/\.\.\/media\/hero\.jpg\?size=large#crop'\)/);
  assert.match(css, /url\(data:image\/png;base64,AA\)/);
});

test('platform attribution is excluded from both import artifacts and visual baselines', () => {
  const fixtureDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-platform-chrome-source-'));
  const sourceDirectory = path.join(fixtureDirectory, 'fixture');
  mkdirSync(sourceDirectory, { recursive: true });
  writeFileSync(path.join(sourceDirectory, 'index.html'), '<main><h1>Authored page</h1></main><div id="weebly-footer-signup-container-v3"><a href="https://www.weebly.com/signup"><div>Powered by Weebly</div></a></div>');

  const fixture = { id: 'Platform Chrome', directory: sourceDirectory };
  const artifact = buildFixtureArtifact(fixture);
  const artifactHtml = Buffer.from(artifact.files[0].content_base64, 'base64').toString('utf8');
  assert.doesNotMatch(artifactHtml, /weebly-footer-signup-container-v3/);
  assert.equal(artifact.source_metadata.source_exclusions[0].reason_code, 'platform_attribution_removed');
  assert.match(artifact.source_metadata.source_exclusions[0].removed_sha256, /^[a-f0-9]{64}$/);

  stageFixtureSource(fixture, fixtureDirectory);
  const stagedHtml = readFileSync(path.join(fixtureDirectory, 'source', 'index.html'), 'utf8');
  assert.match(stagedHtml, /Authored page/);
  assert.doesNotMatch(stagedHtml, /weebly-footer-signup-container-v3/);
});

test('staged visual source uses the runtime-materialized local font stylesheet', () => {
  const fixtureDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-parity-font-source-'));
  const sourceDirectory = path.join(fixtureDirectory, 'fixture');
  mkdirSync(sourceDirectory, { recursive: true });
  mkdirSync(path.join(sourceDirectory, 'css'), { recursive: true });
  writeFileSync(path.join(sourceDirectory, 'index.html'), '<html><head><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Example" rel="stylesheet"><style>@import url("https://fonts.googleapis.com/css2?family=Inline");</style><link rel="stylesheet" href="css/style.css"></head><body>Example</body></html>');
  writeFileSync(path.join(sourceDirectory, 'css', 'style.css'), '@import url("https://fonts.googleapis.com/css2?family=External:wght@400;700&display=swap");\nbody { font-family: External, sans-serif; }');

  stageFixtureSource({ id: 'Font Fixture', directory: sourceDirectory }, fixtureDirectory);
  const html = readFileSync(path.join(fixtureDirectory, 'source', 'index.html'), 'utf8');
  const css = readFileSync(path.join(fixtureDirectory, 'source', 'css', 'style.css'), 'utf8');
  assert.doesNotMatch(html, /rel="preconnect"/);
  assert.match(html, /href="\.\/assets\/css\/embedded-fonts\.css"/);
  assert.doesNotMatch(html, /href="https:\/\/fonts\.googleapis\.com\/css2/);
  assert.match(html, /@import url\("\.\/assets\/css\/embedded-fonts\.css"\)/);
  assert.doesNotMatch(html, /https:\/\/fonts\.googleapis\.com\/(?:css|css2)\?/);
  assert.match(css, /@import url\("\.\.\/assets\/css\/embedded-fonts\.css"\)/);
  assert.doesNotMatch(css, /https:\/\/fonts\.googleapis\.com\/(?:css|css2)\?/);
  assert.match(html, /fetch\("\.\/assets\/css\/embedded-fonts\.css"\)/);
  assert.equal(new URL('./assets/css/embedded-fonts.css', pathToFileURL(path.join(fixtureDirectory, 'source', 'index.html'))).pathname, path.join(fixtureDirectory, 'source', 'assets', 'css', 'embedded-fonts.css'));
  assert.equal(new URL('../assets/css/embedded-fonts.css', pathToFileURL(path.join(fixtureDirectory, 'source', 'css', 'style.css'))).pathname, path.join(fixtureDirectory, 'source', 'assets', 'css', 'embedded-fonts.css'));
});

test('visual parity stages candidate materialized font assets for the local source capture', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-font-source-stage' });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
  });
  const step = recipe.workflow.steps.find((candidate) => candidate.metadata?.source_relationship === 'copied-from-generated-theme-font-assets');
  const command = step?.args?.[0] || '';
  const encoded = command.match(/([A-Za-z0-9+/=]{100,})/)?.[1] || '';
  const code = Buffer.from(encoded, 'base64').toString('utf8');

  assert.equal(step?.command, 'wordpress.wp-cli');
  assert.equal(step?.metadata?.source_relationship, 'copied-from-generated-theme-font-assets');
  assert.doesNotMatch(command, /\$(?:source_root|source_assets|theme_assets|stylesheet|font)\b/);
  assert.match(code, /get_stylesheet_directory\(\).*embedded-fonts\.css/s);
  assert.match(code, /\$source_root = "\/artifacts\/simple-site\/source"/);
  assert.match(code, /\$source_assets = \$source_root.*\/assets/s);
  assert.match(code, /\$source_assets.*\/fonts/s);
  const lint = spawnSync('php', ['-l'], { input: `<?php\n${code}`, encoding: 'utf8' });
  assert.equal(lint.status, 0, lint.stderr || lint.stdout);
});

test('fixture recipe stages source files into the WordPress runtime', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-parity-runtime-source-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-runtime-source-test' });
  writeFixtureMatrixArtifacts({ outputDirectory, matrix });
  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: outputDirectory,
    playgroundArtifactsDirectory: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });
  assert.ok(recipe.inputs.stagedFiles.some((file) => file.target.endsWith('/simple-site/source/index.html')));
  assert.ok(recipe.inputs.stagedFiles.some((file) => file.target.endsWith('/simple-site/source/style.css')));
});

test('writeFixtureMatrixArtifacts skips raw source staging when visual evidence is disabled', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-no-visual-source-skip-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'no-visual-source-skip-test' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix, visualParity: false, liveWpParity: false });

  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'artifact.json')), 'artifact.json should still be written');
  assert.equal(existsSync(path.join(outputDirectory, 'simple-site', 'source', 'index.html')), false);
  assert.equal(written.metadata.source_staging.status, 'skipped');
  assert.equal(written.metadata.source_staging.reason, 'visual_live_wp_and_runtime_presentation_evidence_disabled');
  assert.equal(written.metadata.artifact_bytes.staged_source, 0);
  assert.ok(written.metadata.artifact_bytes.fixture_artifacts > 0);
  assert.ok(written.metadata.artifact_bytes.total >= written.metadata.artifact_bytes.fixture_artifacts);
  assert.ok(Number.isFinite(written.metadata.performance.artifact_writing_ms));
});

test('writeFixtureMatrixArtifacts preserves source staging for live-WP parity evidence', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-live-wp-source-stage-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'live-wp-source-stage-test' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix, visualParity: false, liveWpParity: true });

  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'source', 'index.html')));
  assert.equal(written.metadata.source_staging.status, 'staged');
  assert.ok(written.metadata.artifact_bytes.staged_source > 0);
});

test('writeFixtureMatrixArtifacts preserves source staging for runtime presentation evidence', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-runtime-evidence-source-stage-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'runtime-evidence-source-stage-test' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix, visualParity: false, runtimePresentationEvidence: true });

  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'source', 'index.html')));
  assert.equal(written.metadata.source_staging.status, 'staged');
  assert.ok(written.metadata.artifact_bytes.staged_source > 0);
});

test('stageFixtureSource direct call returns staged relative paths', () => {
  const fixtureDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-parity-stage-direct-'));
  const staged = stageFixtureSource(
    { id: 'simple-site', directory: path.join(fixtureRoot, 'simple-site') },
    fixtureDirectory,
  );
  assert.ok(staged.includes('index.html'));
  assert.ok(existsSync(path.join(fixtureDirectory, 'source', 'index.html')));
});

test('wordpressServedPath strips the /wordpress docroot prefix', () => {
  assert.equal(
    wordpressServedPath('/wordpress/wp-content/uploads/foo'),
    '/wp-content/uploads/foo',
  );
  // Already-served paths are returned normalized but unchanged in meaning.
  assert.equal(wordpressServedPath('/wp-content/uploads/foo'), '/wp-content/uploads/foo');
});

test('(a) visual-compare mismatch at/under threshold produces no finding', () => {
  const payload = {
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 1000, totalPixels: 2048000, dimensionMismatch: false },
  };
  // ratio ~0.0005, threshold 0.1 -> captured, no diagnostic.
  assert.deepEqual(collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true }), []);

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-under-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'passed', diagnostics: collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true }) }],
  });
  assert.equal(result.findings.some((finding) => finding.kind === VISUAL_PARITY_MISMATCH_KIND), false);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('(b) visual-compare mismatch over threshold with gate on becomes a gating unacceptable finding', () => {
  const payload = {
    schema: 'homeboy/VisualParityArtifact/v1',
    summary: { mismatch_pixels: 600000, total_pixels: 2048000, dimension_mismatch: false },
    artifacts: { source_screenshot: 'files/browser/visual-compare/source.png', candidate_screenshot: 'files/browser/visual-compare/candidate.png', diff_screenshot: 'files/browser/visual-compare/diff.png' },
  };
  const diagnostics = collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true });
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].kind, VISUAL_PARITY_MISMATCH_KIND);
  assert.equal(diagnostics[0].gate, true);

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-gate-on-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'passed', diagnostics }],
  });
  const finding = result.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(finding, 'expected a visual_parity_mismatch finding');
  assert.equal(finding.group_key, 'visual_parity_mismatch');
  assert.equal(finding.repair_bucket, 'visual_parity_mismatch');
  assert.equal(finding.candidate_repo, '');
  assert.equal(finding.loss_class, 'visual_parity_mismatch');
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(result.summary.unacceptable_finding_count, 1);
  assert.equal(result.fixtures[0].status, 'failed');
});

test('fixture gate failures expose distinct categories for visual, evidence, and editor-invalid failures', () => {
  const base = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'failure-category-test' });
  const fixture = base.fixtures[0];
  const matrix = {
    ...base,
    count: 3,
    fixtures: [
      { ...fixture, id: 'visual-clean-editor' },
      { ...fixture, id: 'evidence-gap-clean-editor' },
      { ...fixture, id: 'editor-invalid' },
    ],
  };
  const cleanEditorQuality = {
    block_composition: { total_blocks: 1, block_counts: { 'core/paragraph': 1 } },
    editor_validation: { validation_method: EDITOR_VALIDATION_METHOD, total_blocks: 1, valid_blocks: 1, invalid_blocks: 0 },
  };
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'visual-clean-editor',
        status: 'passed',
        ...cleanEditorQuality,
        diagnostics: [{ kind: VISUAL_PARITY_MISMATCH_KIND, gate: true, selector: '.hero', message: 'Visual parity mismatch over threshold.' }],
      },
      {
        fixture_id: 'evidence-gap-clean-editor',
        status: 'failed',
        ...cleanEditorQuality,
        diagnostics: [{ kind: 'static_site_fixture_diagnostic', message: 'Generic SSI diagnostic without selector or artifact evidence.' }],
      },
      {
        fixture_id: 'editor-invalid',
        status: 'passed',
        diagnostics: [{ kind: 'editor_block_invalid', selector: '.wp-block[data-block].is-invalid', message: 'This block contains unexpected or invalid content.' }],
      },
    ],
  });

  const byId = new Map(result.fixtures.map((row) => [row.fixture_id, row]));
  assert.deepEqual(byId.get('visual-clean-editor').quality_gate.failure_categories, ['visual_mismatch']);
  assert.equal(byId.get('visual-clean-editor').editor_quality.editor_invalid_count, 0);
  assert.ok(!byId.get('visual-clean-editor').quality_gate.failure_categories.includes('editor_invalid'));
  assert.deepEqual(byId.get('evidence-gap-clean-editor').quality_gate.failure_categories, ['harness_diagnostic', 'missing_evidence', 'unsupported_loss']);
  assert.equal(byId.get('evidence-gap-clean-editor').editor_quality.editor_invalid_count, 0);
  assert.deepEqual(byId.get('editor-invalid').quality_gate.failure_categories, ['editor_invalid']);
  assert.equal(result.summary.fixture_failure_categories.visual_mismatch, 1);
  assert.equal(result.summary.fixture_failure_categories.harness_diagnostic, 1);
  assert.equal(result.summary.fixture_failure_categories.missing_evidence, 1);
  assert.equal(result.summary.fixture_failure_categories.editor_invalid, 1);
  assert.equal(result.summary.fixture_failure_categories.unsupported_loss, 1);
  assert.deepEqual(result.summary.gate_failure_reasons.map((row) => row.category), [
    'visual_mismatch',
    'harness_diagnostic',
    'editor_invalid',
  ]);
  assert.deepEqual(result.summary.gate_failure_reasons[1].categories, ['harness_diagnostic', 'missing_evidence', 'unsupported_loss']);
});

test('(c) visual-compare mismatch over threshold with gate off is captured but non-gating', () => {
  const payload = {
    schema: 'homeboy/VisualParityArtifact/v1',
    summary: { mismatch_pixels: 600000, total_pixels: 2048000, dimension_mismatch: false },
  };
  const diagnostics = collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: false });
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].gate, undefined);

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-gate-off-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'passed', diagnostics }],
  });
  const finding = result.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(finding, 'expected a captured visual_parity_mismatch finding');
  assert.equal(finding.loss_acceptance, 'acceptable');
  assert.equal(result.summary.unacceptable_finding_count, 0);
  assert.equal(result.fixtures[0].status, 'passed');
});

test('visual-compare artifacts collected from fixture files gate the matrix when gating is opted in', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-parity-artifact-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-artifact-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'visual-diff.json'), JSON.stringify({
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 700000, totalPixels: 2048000, dimensionMismatch: false },
    files: {
      sourceScreenshot: 'files/browser/visual-compare/source.png',
      candidateScreenshot: 'files/browser/visual-compare/candidate.png',
      diffScreenshot: 'files/browser/visual-compare/diff.png',
      visualDiff: 'files/browser/visual-compare/visual-diff.json',
    },
  }));

  const gated = collectFixtureMatrixRunResults({ matrix, outputDirectory, visualParity: { threshold: 0.1, gate: true } });
  const finding = gated.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(finding, 'expected a visual_parity_mismatch finding from the visual-compare artifact');
  assert.equal(finding.loss_acceptance, 'unacceptable');
  assert.equal(gated.fixtures[0].status, 'failed');
  // The visual_parity_artifacts slot captures screenshots + diff + metrics.
  assert.equal(gated.fixtures[0].visual_parity_artifacts.schema, 'static-site-importer/visual-parity-artifacts/v1');
  assert.equal(gated.fixtures[0].visual_parity_artifacts.artifacts.diff_screenshot.status, 'captured');
  assert.equal(gated.fixtures[0].visual_parity_artifacts.metrics.mismatch_pixels, 700000);
  assert.equal(finding.artifact_refs.find((ref) => ref.artifact_id === 'diff_screenshot')?.path, 'files/browser/visual-compare/diff.png');
  const exemplar = gated.summary.top_pattern_families.find((family) => family.kind === VISUAL_PARITY_MISMATCH_KIND)?.exemplars[0];
  assert.equal(exemplar.artifact_refs.find((ref) => ref.artifact_id === 'diff_screenshot')?.path, 'files/browser/visual-compare/diff.png');

  // Same artifact, gate off (default) -> captured, non-gating.
  const captured = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const capturedFinding = captured.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(capturedFinding, 'expected the mismatch to still be captured');
  assert.equal(capturedFinding.loss_acceptance, 'acceptable');
  assert.equal(captured.fixtures[0].status, 'passed');
});

test('surface lineage persists reviewer-facing visual refs and explicit absent-attribution blind spots', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-surface-lineage-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'surface-lineage-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'passed',
      surface_records: [{
          surface_id: 'front-page', source_url: 'https://source.test/', candidate_url: 'https://candidate.test/', post_id: 42, post_type: 'page', post_slug: 'home',
          artifact_refs: [{ artifact_id: 'editor-state', kind: 'editor-canvas', path: 'files/browser/editor/state.json' }],
      }],
      artifact_refs: [{ artifact_id: 'materialization-receipt--primary', kind: 'materialization-sidecar', path: 'materialization-receipt--primary.json' }],
      matrix_evidence: { materialization_receipt: { schema: 'static-site-importer/materialization-receipt/v1', status: 'completed' } },
      visual_parity_comparisons: [{
        surface_id: 'front-page',
        source_url: 'https://source.test/',
        candidate_url: 'https://candidate.test/',
        visual_parity_artifacts: { artifacts: {
          source_screenshot: { kind: 'source_screenshot', ref: { path: 'files/browser/source.png' } },
          imported_screenshot: { kind: 'imported_screenshot', ref: { path: 'files/browser/candidate.png' } },
          diff_screenshot: { kind: 'diff_screenshot', ref: { path: 'files/browser/diff.png' } },
        } },
      }],
    }],
  });

  writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result });
  const surface = JSON.parse(readFileSync(path.join(outputDirectory, 'simple-site', 'surface-lineage--front-page-d365228668b8.json'), 'utf8'));
  assert.equal(surface.surface_id, 'simple-site:front-page');
  assert.equal(surface.surface.source_url, 'https://source.test/');
  assert.equal(surface.surface.candidate_url, 'https://candidate.test/');
  assert.equal(surface.imported_post.id, '42');
  assert.deepEqual(surface.artifacts.map((ref) => ref.path).sort(), ['files/browser/candidate.png', 'files/browser/diff.png', 'files/browser/editor/state.json', 'files/browser/source.png']);
  assert.deepEqual(surface.blind_spots.map((spot) => spot.kind), ['dom_attribution_absent', 'css_selector_attribution_absent']);
  const persisted = JSON.parse(readFileSync(path.join(outputDirectory, 'static-site-fixture-matrix-result.json'), 'utf8'));
  assert.ok(persisted.fixtures[0].artifact_refs.some((ref) => ref.kind === 'surface-lineage' && ref.artifact_id.startsWith('surface_lineage_simple-site-') && ref.artifact_id.endsWith('_front-page-d365228668b8')));
});

test('surface lineage v2 joins explicit cross-stage identities and preserves typed lane distinctions', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'surface-lineage-contract-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{
      fixture_id: 'simple-site',
      status: 'passed',
      surface_records: [
        { surface_id: 'front-page', role: 'editor', post_id: '42', post_type: 'page', post_slug: 'home', status: 'completed' },
        { surface_id: 'disabled-preview', role: 'editor', post_id: '42', status: 'disabled' },
      ],
      matrix_evidence: {
        materialization_receipt: { status: 'completed', plan_hash: 'plan-abc' },
        materialization_sidecar: {
          status: 'verified', matrix_run_id: 'matrix-run-7', attempt_id: 'attempt-2', source_artifact_sha256: 'a'.repeat(64),
          documents: [{ post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'b'.repeat(64) }],
        },
      },
      visual_parity_comparisons: [
        { surface_id: 'front-page', visual_parity_artifacts: { artifacts: { diff_screenshot: { kind: 'diff', ref: { path: 'diff.png' } } } } },
        { surface_id: 'missing-preview' },
      ],
    }],
  });
  const bySurface = new Map(result.fixtures[0].surface_lineage.map((row) => [row.surface.id, row]));
  const frontPage = bySurface.get('front-page');
  assert.equal(frontPage.schema, 'static-site-importer/fixture-surface-lineage/v2');
  assert.deepEqual(frontPage.lineage, { matrix_run_id: 'matrix-run-7', attempt_id: 'attempt-2', fixture_id: 'simple-site', surface_id: 'front-page', source_artifact_sha256: 'a'.repeat(64), plan_hash: 'plan-abc' });
  assert.deepEqual(frontPage.materialized_document, { status: 'available', post_id: '42', post_type: 'page', post_slug: 'home', serialized_content_sha256: 'b'.repeat(64) });
  assert.equal(frontPage.lanes.transform.status, 'available');
  assert.equal(frontPage.lanes.materialization.status, 'available');
  assert.equal(frontPage.lanes.editor.status, 'available');
  assert.equal(frontPage.lanes.visual.status, 'available');
  assert.equal(bySurface.get('disabled-preview').lanes.editor.status, 'disabled');
  assert.equal(bySurface.get('missing-preview').lanes.visual.status, 'missing');

  const unavailable = normalizeFixtureMatrixResult({ matrix, results: [{ fixture_id: 'simple-site', status: 'failed', matrix_evidence: { materialization_receipt: { status: 'failed' }, materialization_sidecar: { status: 'missing' } } }] }).fixtures[0].surface_lineage[0];
  assert.equal(unavailable.lanes.transform.status, 'unavailable');
  assert.equal(unavailable.lanes.materialization.status, 'missing');
  assert.equal(unavailable.lanes.editor.status, 'unavailable');
  assert.equal(unavailable.lanes.visual.status, 'unavailable');
  assert.equal(unavailable.materialized_document.status, 'missing');

  const failed = normalizeFixtureMatrixResult({ matrix, results: [{ fixture_id: 'simple-site', status: 'failed', matrix_evidence: { materialization_receipt: { status: 'failed' }, materialization_sidecar: { status: 'verified' } } }] }).fixtures[0].surface_lineage[0];
  assert.equal(failed.lanes.materialization.status, 'failed');
  assert.equal(failed.materialized_document.status, 'failed');
});

test('surface lineage v2 retains deterministic visual truncation counters', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'surface-lineage-truncation-test' });
  const refs = Array.from({ length: 30 }, (_, index) => ({ artifact_id: `visual-${String(29 - index).padStart(2, '0')}`, kind: 'visual', path: `visual/${29 - index}.png` }));
  const result = normalizeFixtureMatrixResult({ matrix, results: [{ fixture_id: 'simple-site', status: 'passed', surface_records: [{ surface_id: 'front-page', role: 'visual', artifact_refs: refs }] }] });
  const visualData = result.fixtures[0].surface_lineage[0].visual_data;
  assert.deepEqual(visualData.truncation, { retained_count: 25, truncated_count: 5 });
  assert.deepEqual(visualData.refs.map((ref) => ref.artifact_id), [...visualData.refs].map((ref) => ref.artifact_id).sort());
});

test('surface lineage artifact refs are fixture-scoped for globally resolvable export', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-surface-lineage-refs-'));
  const baseMatrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'surface-lineage-ref-test' });
  const fixture = baseMatrix.fixtures[0];
  const matrix = {
    ...baseMatrix,
    fixtures: [fixture, { ...fixture, id: '89-static-site-importer-architecture' }],
    count: 2,
  };
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: matrix.fixtures.map((entry) => ({ fixture_id: entry.id, status: 'passed' })),
  });

  writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result });
  const refs = result.fixtures.slice(0, 2).flatMap((fixture) => fixture.artifact_refs.filter((ref) => ref.kind === 'surface-lineage'));
  assert.equal(new Set(refs.map((ref) => ref.artifact_id)).size, refs.length);
  assert.ok(refs.every((ref) => existsSync(ref.path)));
});

test('surface lineage slugs hostile route IDs without changing their logical identity', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-hostile-surface-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'hostile-surface-test' });
  const hostileId = '../../editor?surface=<script>';
  const result = normalizeFixtureMatrixResult({ matrix, results: [{
    fixture_id: 'simple-site', status: 'passed',
    surface_records: [{ surface_id: hostileId, role: 'visual', source_url: 'https://source.test/', artifact_refs: [{ artifact_id: 'diff', path: 'diff.png' }] }],
  }] });
  writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result });
  const bundle = result.fixtures[0].surface_lineage.find((surface) => surface.surface.id === hostileId);
  const ref = result.fixtures[0].artifact_refs.find((item) => item.kind === 'surface-lineage' && item.path.endsWith(`surface-lineage--${bundle.surface.artifact_slug}.json`));
  assert.match(bundle.surface.artifact_slug, /^[a-z0-9-]+-[a-f0-9]{12}$/);
  assert.equal(ref.path.includes(hostileId), false);
  assert.equal(path.dirname(ref.path), path.join(outputDirectory, 'simple-site'));
  assert.equal(existsSync(ref.path), true);
});

test('surface records ignore unrelated import payloads and prefer editor identity over visual capture', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'surface-record-selection-test' });
  const records = collectSurfaceRecords([
    { fixture_id: 'simple-site', command: 'wordpress.wp-cli', post_id: 'wrong-import-post', target: 'wrong-import-target' },
    { fixture_id: 'simple-site', command: 'wordpress.visual-compare', metadata: { surface_id: 'front-page', source_url: 'https://source.test/', candidate_url: 'https://candidate.test/', post_id: 'visual-post' } },
    { fixture_id: 'simple-site', command: 'wordpress.editor-validate-blocks', metadata: { surface_id: 'front-page', post_id: 'editor-post', post_type: 'page', post_slug: 'home', target: '/wp-admin/post.php?post=editor-post' } },
  ]);
  assert.equal(records.length, 2);
  assert.deepEqual(records.map((record) => record.role), ['visual', 'editor']);
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'passed', surface_records: records }],
  });
  const surface = result.fixtures[0].surface_lineage[0];
  assert.equal(surface.imported_post.id, 'editor-post');
  assert.equal(surface.imported_post.editor_target, '/wp-admin/post.php?post=editor-post');
  assert.equal(surface.surface.source_url, 'https://source.test/');
});

test('visual evidence report infers viewport evidence from visual-compare metrics', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-metric-viewport-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-metric-viewport-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(path.join(fixtureDirectory, 'source'), { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'artifact.json'), '{}');
  writeFileSync(path.join(fixtureDirectory, 'source', 'index.html'), '<h1>Simple SSI Fixture</h1>');

  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'passed',
        visual_parity_artifacts: {
          metrics: {
            mismatch_pixels: 0,
            total_pixels: 1000,
            mismatch_ratio: 0,
            viewport: { width: 390, height: 844 },
            full_page: true,
          },
          artifacts: {
            imported_screenshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/candidate-mobile.png', kind: 'browser-visual-candidate-screenshot' } },
          },
        },
      },
    ],
  });

  const report = buildVisualParityEvidenceReport({ outputDirectory, matrix, result });
  const viewport = report.fixtures[0].evidence.viewports.rows[0];

  assert.equal(report.summary.viewport_evidence_fixture_count, 1);
  assert.equal(report.summary.mobile_viewport_fixture_count, 1);
  assert.equal(viewport.phase, 'visual-compare');
  assert.equal(viewport.width, 390);
  assert.equal(viewport.height, 844);
  assert.equal(report.fixtures[0].risk.reasons.includes('missing mobile viewport evidence'), false);
});

test('visual-compare sidecars are normalized and retained under the bench artifact root', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-persisted-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-codebox-artifacts-'));
  const runtimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', 'files', 'browser', 'visual-compare', 'simple-site');
  mkdirSync(runtimeDirectory, { recursive: true });
  for (const fileName of ['source.png', 'candidate.png', 'diff.png']) {
    writeFileSync(path.join(runtimeDirectory, fileName), `fake ${fileName}`);
  }
  writeFileSync(path.join(runtimeDirectory, 'visual-diff.json'), JSON.stringify({
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 120, totalPixels: 1000, mismatchRatio: 0.12, dimensionMismatch: false },
  }));
  writeFileSync(path.join(runtimeDirectory, 'visual-explanation.json'), JSON.stringify({
    schema: 'wp-codebox/visual-explanation/v1',
    mismatchRegions: [{ x: 10, y: 20, width: 30, height: 40, pixels: 120 }],
    selectorDeltas: [{ selector: '.hero', sourcePath: '0.1', candidatePath: '0.1', boundingBox: { delta: { y: 12 } }, styles: [{ property: 'color', source: '#000', candidate: '#fff' }] }],
  }));
  for (const fileName of ['source-dom-snapshot.json', 'candidate-dom-snapshot.json']) {
    writeFileSync(path.join(runtimeDirectory, fileName), JSON.stringify({
      schema: 'wp-codebox/browser-dom-snapshot/v1',
      snapshot: { elementCount: 3, capturedElements: [{ path: '0.1' }], truncated: false },
    }));
  }
  const secondaryRuntimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', 'files', 'browser', 'visual-compare', 'simple-site--contact');
  mkdirSync(secondaryRuntimeDirectory, { recursive: true });
  writeFileSync(path.join(secondaryRuntimeDirectory, 'candidate.png'), 'secondary candidate');

  const result = {
    fixtures: [
      {
        fixture_id: 'simple-site',
        candidate_provenance: { '0.1': { block: 'core/cover' } },
        diagnostics: [
          {
            kind: VISUAL_PARITY_MISMATCH_KIND,
            artifact_refs: [
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'source_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site/source.png' },
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'candidate_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site/candidate.png' },
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'diff_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site/diff.png' },
            ],
          },
          {
            kind: VISUAL_PARITY_MISMATCH_KIND,
            artifact_refs: [
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'candidate_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site--contact/candidate.png' },
            ],
          },
        ],
        visual_parity_artifacts: {
          schema: 'static-site-importer/visual-parity-artifacts/v1',
          owner: 'codebox_runtime',
          artifacts: {
            source_screenshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/source.png' } },
            imported_screenshot: { status: 'pending', capture_state: 'not_captured' },
            diff_screenshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/diff.png' } },
            visual_diff: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/visual-diff.json' } },
            visual_explanation: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/visual-explanation.json' } },
            source_dom_snapshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/source-dom-snapshot.json' } },
            candidate_dom_snapshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/candidate-dom-snapshot.json' } },
          },
        },
      },
    ],
  };

  let normalizerInput;
  let visualAttributionLoaderCalls = 0;
  const persisted = materializeVisualCompareArtifacts({
    result,
    outputDirectory,
    codeboxArtifactsDirectory,
    visualAttributionLoader() {
      visualAttributionLoaderCalls += 1;
      return {
        normalizeWordPressVisualAttribution(input) {
          normalizerInput = input;
          return {
            schema: 'homeboy/WordPressVisualAttribution/v1',
            mismatch_regions: input.visualExplanation.mismatchRegions,
            selector_deltas: [{ bounding_box: { delta: { y: 12 } } }],
            top_findings: Array.from({ length: 8 }, (_, index) => ({ kind: index === 0 ? 'geometry' : 'style', selector: '.hero', property: `property-${index}` })),
            computed_style_deltas: { paint: [{ property: 'color' }], typography: [{ property: 'font-size' }, { property: 'line-height' }] },
            elements: { changed: [{ path: '0.1' }], added: [], removed: [{ path: '0.2' }] },
            summary: { changed: 1, added: 0, removed: 1 },
            limitations: ['Attribution uses bounded browser evidence.'],
          };
        },
      };
    },
  });
  const fixture = persisted.result.fixtures[0];

  assert.equal(visualAttributionLoaderCalls, 1);
  assert.deepEqual(Object.keys(persisted.artifacts).sort(), [
    'visual_compare_simple-site--contact_candidate.png',
    'visual_compare_simple-site_candidate',
    'visual_compare_simple-site_candidate-dom-snapshot.json',
    'visual_compare_simple-site_diff',
    'visual_compare_simple-site_source',
    'visual_compare_simple-site_source-dom-snapshot.json',
    'visual_compare_simple-site_visual-attribution',
    'visual_compare_simple-site_visual-diff.json',
    'visual_compare_simple-site_visual-explanation.json',
  ]);
  for (const [key, artifact] of Object.entries(persisted.artifacts)) {
    assert.ok(existsSync(artifact.path), `${key} should point at a retained artifact`);
    assert.equal(artifact.path.includes('homeboy-run-'), false, 'persisted artifact must not live in a transient Homeboy runtime dir');
    assert.ok(artifact.path.startsWith(path.join(outputDirectory, 'visual-compare', 'simple-site')));
  }
  assert.equal(readFileSync(path.join(outputDirectory, 'visual-compare', 'simple-site', 'source.png'), 'utf8'), 'fake source.png');
  assert.equal(fixture.visual_parity_artifacts.owner, 'bench_artifact_root');
  assert.equal(fixture.visual_parity_artifacts.artifacts.source_screenshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'source.png'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.source_screenshot.ref.artifact_id, 'visual_compare_simple-site_source');
  assert.equal(fixture.visual_parity_artifacts.artifacts.imported_screenshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'candidate.png'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.diff_screenshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'diff.png'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.diff_screenshot.ref.artifact_id, 'visual_compare_simple-site_diff');
  assert.equal(fixture.diagnostics[0].artifact_refs.find((ref) => ref.artifact_id === 'visual_compare_simple-site_diff').path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'diff.png'));
  assert.equal(fixture.diagnostics[1].artifact_refs[0].path, path.join(outputDirectory, 'visual-compare', 'simple-site--contact', 'candidate.png'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_diff.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'visual-diff.json'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.source_dom_snapshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'source-dom-snapshot.json'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_attribution.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'visual-attribution.json'));
  assert.equal(normalizerInput.visualExplanation.selectorDeltas[0].boundingBox.delta.y, 12);
  assert.equal(normalizerInput.sourceDomSnapshot.snapshot.elementCount, 3);
  assert.deepEqual(normalizerInput.candidateProvenance, { '0.1': { block: 'core/cover' } });
  assert.equal(JSON.parse(readFileSync(fixture.visual_parity_artifacts.artifacts.visual_attribution.ref.path, 'utf8')).top_findings.length, 8);
  assert.deepEqual(fixture.visual_parity_artifacts.visual_attribution_summary, {
    schema: 'homeboy/WordPressVisualAttribution/v1',
    status: 'available',
    mismatch_region_count: 1,
    selector_delta_count: 1,
    geometry_delta_count: 1,
    computed_style_delta_counts: { paint: 1, typography: 2 },
    changed_count: 1,
    added_count: 0,
    removed_count: 1,
    top_findings: Array.from({ length: 5 }, (_, index) => ({ kind: index === 0 ? 'geometry' : 'style', selector: '.hero', property: `property-${index}` })),
    limitations_count: 1,
    attribution_ref: path.join(outputDirectory, 'visual-compare', 'simple-site', 'visual-attribution.json'),
  });
});

test('visual-compare materialization retains non-colliding fixture and surface artifact sets', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-multi-surface-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-multi-surface-codebox-'));
  const makeComparison = (surfaceId) => {
    const relative = `files/browser/visual-compare/simple-site--${surfaceId}`;
    const runtimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', relative);
    mkdirSync(runtimeDirectory, { recursive: true });
    for (const fileName of ['source.png', 'candidate.png', 'diff.png']) {
      writeFileSync(path.join(runtimeDirectory, fileName), `${surfaceId}:${fileName}`);
    }
    return {
      surface_id: surfaceId,
      visual_parity_artifacts: {
        artifacts: Object.fromEntries([
          ['source_screenshot', 'source.png'],
          ['imported_screenshot', 'candidate.png'],
          ['diff_screenshot', 'diff.png'],
        ].map(([slot, fileName]) => [slot, { status: 'captured', ref: { path: `${relative}/${fileName}` } }])),
      },
    };
  };
  const primary = makeComparison('front-page');
  const secondary = makeComparison('about');
  const persisted = materializeVisualCompareArtifacts({
    outputDirectory,
    codeboxArtifactsDirectory,
    result: {
      fixtures: [{
        fixture_id: 'simple-site',
        diagnostics: [{ artifact_refs: [{ artifact_id: 'diff_screenshot', path: 'files/browser/visual-compare/simple-site--about/diff.png' }] }],
        visual_parity_artifacts: primary.visual_parity_artifacts,
        visual_parity_comparisons: [primary, secondary],
      }],
    },
  });
  const fixture = persisted.result.fixtures[0];
  const secondaryArtifacts = fixture.visual_parity_comparisons[1].visual_parity_artifacts.artifacts;
  const normalized = normalizeFixtureMatrixResult({
    matrix: { id: 'multi-surface-materialization', fixture_root: '/tmp', fixtures: [{ id: 'simple-site', fixture_path: '/tmp/simple-site' }] },
    results: persisted.result.fixtures,
  }).fixtures[0];

  assert.ok(existsSync(path.join(outputDirectory, 'visual-compare', 'simple-site', 'source.png')));
  assert.ok(existsSync(path.join(outputDirectory, 'visual-compare', 'simple-site', 'about', 'source.png')));
  assert.deepEqual(Object.keys(persisted.artifacts).sort(), [
    'visual_compare_simple-site_about_candidate',
    'visual_compare_simple-site_about_diff',
    'visual_compare_simple-site_about_source',
    'visual_compare_simple-site_candidate',
    'visual_compare_simple-site_diff',
    'visual_compare_simple-site_source',
  ]);
  assert.notEqual(fixture.visual_parity_artifacts.artifacts.source_screenshot.ref.path, secondaryArtifacts.source_screenshot.ref.path);
  assert.equal(secondaryArtifacts.diff_screenshot.ref.artifact_id, 'visual_compare_simple-site_about_diff');
  assert.equal(fixture.diagnostics[0].artifact_refs[0].path, secondaryArtifacts.diff_screenshot.ref.path);
  assert.equal(normalized.visual_parity_comparisons[1].visual_parity_artifacts.artifacts.source_screenshot.ref.path, secondaryArtifacts.source_screenshot.ref.path);
});

test('visual-compare aggregate intake retains source-path-identified routes without surface metadata', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-aggregate-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-aggregate-codebox-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-aggregate-intake-test' });
  const makeComparison = (sourcePath, artifactDirectory) => {
    const runtimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', artifactDirectory);
    mkdirSync(runtimeDirectory, { recursive: true });
    const files = {};
    for (const [key, fileName] of Object.entries({ sourceScreenshot: 'source.png', candidateScreenshot: 'candidate.png', diffScreenshot: 'diff.png' })) {
      writeFileSync(path.join(runtimeDirectory, fileName), `${sourcePath}:${fileName}`);
      files[key] = `${artifactDirectory}/${fileName}`;
    }
    return {
      source: { path: sourcePath },
      summary: { mismatchPixels: 20, totalPixels: 100, mismatchRatio: 0.2 },
      files,
    };
  };
  const frontPage = makeComparison('index.html', 'files/browser/visual-compare/3-artist-music');
  const music = makeComparison('music.html', 'files/browser/visual-compare/3-artist-music--music');
  const collected = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    codeboxOutput: {
      fixture_id: 'simple-site',
      status: 'completed',
      comparisons: [frontPage, music],
    },
    visualParity: { threshold: 0.1, gate: true },
  });
  const persisted = materializeVisualCompareArtifacts({ result: collected, outputDirectory, codeboxArtifactsDirectory });
  const fixture = persisted.result.fixtures[0];
  const comparisons = fixture.visual_parity_comparisons;
  const bySurface = Object.fromEntries(comparisons.map((comparison) => [comparison.surface_id, comparison]));
  const retainedRefs = comparisons.flatMap((comparison) => Object.values(comparison.visual_parity_artifacts.artifacts)
    .filter((slot) => slot.status === 'captured')
    .map((slot) => slot.ref?.path)
    .filter(Boolean));

  assert.deepEqual(comparisons.map((comparison) => comparison.surface_id).sort(), ['front-page', 'music']);
  assert.equal(bySurface.music.visual_parity_artifacts.artifacts.diff_screenshot.ref.artifact_id, 'visual_compare_simple-site_music_diff');
  assert.ok(retainedRefs.every((ref) => ref.startsWith(path.join(outputDirectory, 'visual-compare', 'simple-site'))));
  assert.equal(new Set(retainedRefs).size, retainedRefs.length, 'route artifact refs must not collide');
  assert.deepEqual(Object.keys(persisted.artifacts).sort(), [
    'visual_compare_simple-site_candidate',
    'visual_compare_simple-site_diff',
    'visual_compare_simple-site_music_candidate',
    'visual_compare_simple-site_music_diff',
    'visual_compare_simple-site_music_source',
    'visual_compare_simple-site_source',
  ]);
  assert.ok(fixture.diagnostics.every((diagnostic) => diagnostic.artifact_refs.every((ref) => ref.path.startsWith(path.join(outputDirectory, 'visual-compare', 'simple-site')))));
});

test('visual-compare attribution degrades explicitly when sidecars or the extension normalizer are unavailable', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-degraded-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-degraded-codebox-'));
  const runtimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', 'files', 'browser', 'visual-compare', 'simple-site');
  mkdirSync(runtimeDirectory, { recursive: true });
  writeFileSync(path.join(runtimeDirectory, 'visual-diff.json'), JSON.stringify({ schema: 'wp-codebox/visual-compare/v1', comparison: { mismatchPixels: 1, totalPixels: 10 } }));
  let visualAttributionLoaderCalls = 0;

  const persisted = materializeVisualCompareArtifacts({
    outputDirectory,
    codeboxArtifactsDirectory,
    visualAttributionLoader() {
      visualAttributionLoaderCalls += 1;
      return null;
    },
    result: {
      fixtures: [{
        fixture_id: 'simple-site',
        visual_parity_artifacts: {
          artifacts: {
            visual_diff: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/visual-diff.json' } },
          },
        },
      }],
    },
  });

  const attributionPath = persisted.result.fixtures[0].visual_parity_artifacts.artifacts.visual_attribution.ref.path;
  const attribution = JSON.parse(readFileSync(attributionPath, 'utf8'));
  assert.equal(visualAttributionLoaderCalls, 1);
  assert.equal(attribution.schema, 'static-site-importer/visual-attribution-unavailable/v1');
  assert.ok(attribution.limitations.some((message) => message.includes('normalizer was unavailable')));
  assert.ok(attribution.limitations.some((message) => message.includes('DOM snapshot')));
  assert.equal(persisted.result.fixtures[0].visual_parity_artifacts.visual_attribution_summary.status, 'limited');
  assert.equal(persisted.result.fixtures[0].visual_parity_artifacts.visual_attribution_summary.limitations_count, 3);
});

test('visual-compare materialization preserves evidence when the primary visual diff was not retained', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-missing-diff-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-missing-diff-codebox-'));
  const secondaryDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', 'files', 'browser', 'visual-compare', 'simple-site--contact');
  mkdirSync(secondaryDirectory, { recursive: true });
  writeFileSync(path.join(secondaryDirectory, 'candidate.png'), 'secondary candidate');

  const persisted = materializeVisualCompareArtifacts({
    outputDirectory,
    codeboxArtifactsDirectory,
    result: {
      fixtures: [{
        fixture_id: 'simple-site',
        diagnostics: [{
          kind: VISUAL_PARITY_MISMATCH_KIND,
          artifact_refs: [
            {
              schema: 'homeboy/artifact-ref/v1',
              artifact_id: 'candidate_screenshot',
              kind: 'visual-parity',
              path: 'files/browser/visual-compare/simple-site--contact/candidate.png',
            },
            {
              schema: 'homeboy/artifact-ref/v1',
              artifact_id: 'visual_diff',
              kind: 'visual-parity',
              path: 'files/browser/visual-compare/simple-site/visual-diff.json',
            },
          ],
        }],
        visual_parity_artifacts: {
          artifacts: {
            visual_diff: { status: 'captured', ref: { path: 'files/browser/visual-compare/simple-site/visual-diff.json' } },
          },
        },
      }],
    },
  });
  const fixture = persisted.result.fixtures[0];

  assert.ok(existsSync(fixture.diagnostics[0].artifact_refs[0].path));
  assert.equal(fixture.diagnostics[0].artifact_refs.length, 1, 'unpersisted artifact refs are omitted');
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_diff.status, 'pending');
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_diff.capture_state, 'artifact_not_persisted');
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_diff.reason, 'artifact_not_persisted');
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_diff.ref, undefined);
  assert.equal(fixture.visual_parity_artifacts.artifacts.visual_attribution, undefined);
  assert.equal(persisted.artifacts['visual_compare_simple-site_visual-attribution'], undefined);
  assert.deepEqual(fixture.visual_parity_artifacts.visual_attribution_summary, {
    schema: 'static-site-importer/visual-attribution-unavailable/v1',
    status: 'unavailable',
    reason: 'primary_visual_diff_not_persisted',
    limitations: ['Primary visual diff was not retained, so visual attribution was not materialized.'],
  });
});

test('visual attribution normalizer resolves WordPress provider manifests before legacy extension paths', () => {
  const directModuleRoot = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-direct-'));
  const directModulePath = path.join(directModuleRoot, 'lib', 'wordpress-visual-attribution.js');
  mkdirSync(path.dirname(directModulePath), { recursive: true });
  writeFileSync(path.join(directModuleRoot, 'index.js'), "throw new Error('package root must not load');\n");
  writeFileSync(directModulePath, 'module.exports.normalizeWordPressVisualAttribution = () => ({ source: \'direct\' });\n');

  const providerRoot = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-provider-'));
  const providerModulePath = path.join(providerRoot, 'lib', 'wordpress-visual-attribution.js');
  const providerManifestPath = path.join(providerRoot, 'lib', 'helper-manifest.js');
  mkdirSync(path.dirname(providerModulePath), { recursive: true });
  writeFileSync(providerModulePath, 'module.exports.normalizeWordPressVisualAttribution = () => ({ source: \'provider\' });\n');
  writeFileSync(providerManifestPath, 'module.exports = {};\n');

  const legacyRoot = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-legacy-'));
  writeFileSync(path.join(legacyRoot, 'index.js'), 'module.exports.normalizeWordPressVisualAttribution = () => ({ source: \'legacy\' });\n');

  const invalidDirectModuleRoot = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-invalid-direct-'));
  const invalidDirectModulePath = path.join(invalidDirectModuleRoot, 'lib', 'wordpress-visual-attribution.js');
  mkdirSync(path.dirname(invalidDirectModulePath), { recursive: true });
  writeFileSync(path.join(invalidDirectModuleRoot, 'index.js'), 'module.exports.normalizeWordPressVisualAttribution = () => ({ source: \'package-root\' });\n');
  writeFileSync(invalidDirectModulePath, 'module.exports = {};\n');

  const missingModuleRoot = mkdtempSync(path.join(tmpdir(), 'ssi-visual-attribution-missing-'));
  const originalHelperManifest = process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST;
  const originalExtensionPath = process.env.HOMEBOY_EXTENSION_PATH;
  try {
    process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST = providerManifestPath;
    process.env.HOMEBOY_EXTENSION_PATH = legacyRoot;

    assert.equal(resolveWordPressVisualAttributionNormalizer({ homeboyExtensionPath: directModuleRoot })().source, 'direct');
    assert.equal(resolveWordPressVisualAttributionNormalizer()().source, 'provider');

    delete process.env.HOMEBOY_EXTENSION_PATH;
    process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST = path.join(providerRoot, 'helper-manifest.js');
    assert.equal(resolveWordPressVisualAttributionNormalizer(), null);

    process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST = path.join(providerRoot, 'lib', 'missing-helper-manifest.js');
    assert.equal(resolveWordPressVisualAttributionNormalizer(), null);

    delete process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST;
    process.env.HOMEBOY_EXTENSION_PATH = legacyRoot;
    assert.equal(resolveWordPressVisualAttributionNormalizer()().source, 'legacy');
    assert.equal(resolveWordPressVisualAttributionNormalizer({ homeboyExtensionPath: invalidDirectModuleRoot }), null);
    assert.equal(resolveWordPressVisualAttributionNormalizer({ homeboyExtensionPath: missingModuleRoot }), null);
  } finally {
    if (originalHelperManifest === undefined) {
      delete process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST;
    } else {
      process.env.HOMEBOY_WORDPRESS_HELPER_MANIFEST = originalHelperManifest;
    }
    if (originalExtensionPath === undefined) {
      delete process.env.HOMEBOY_EXTENSION_PATH;
    } else {
      process.env.HOMEBOY_EXTENSION_PATH = originalExtensionPath;
    }
  }
});

test('visual-compare dimension mismatch gates even with zero pixel metrics when gating is on', () => {
  const payload = { comparison: { mismatchPixels: 0, totalPixels: 0, dimensionMismatch: true } };
  const diagnostics = collectVisualParityDiagnostics(payload, { gate: true });
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].dimension_mismatch, true);
});

test('required candidate readiness failure is an evidence gap, not a pixel regression', () => {
  const payload = {
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 200, totalPixels: 1000, dimensionMismatch: false },
    captureDiagnostics: {
      candidate: {
        effectiveCapture: {
          readiness: {
            records: [{ selector: '#static-site-importer-font-readiness', expectedStatus: 'loaded', observedStatus: 'missing', status: 'invalid' }],
          },
        },
      },
    },
  };
  const diagnostics = collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true });
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].kind, 'visual_parity_evidence_incomplete');
  assert.equal(diagnostics[0].loss_class, 'evidence_gap');
  assert.equal(diagnostics[0].visual_parity_gate, true);
});

test('(fair) dimension-dominated raw ratio does NOT gate when the overlap is faithful', () => {
  // 1380x7248 source vs 1280x5017 candidate, overlap pixel-perfect. The raw union
  // ratio is huge (the canvas-size band) but the fair overlap ratio is 0, so a
  // faithful styled import must NOT produce a gating finding.
  const totalPixels = 1380 * 7248;
  const overlapPixels = 1280 * 5017;
  const payload = {
    schema: 'wp-codebox/visual-compare/v1',
    comparison: {
      mismatchPixels: totalPixels - overlapPixels,
      totalPixels,
      dimensionMismatch: true,
      overlapMismatchPixels: 0,
      overlapPixels,
      dimensionDeltaPixels: totalPixels - overlapPixels,
    },
  };
  assert.deepEqual(collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true }), []);
});

test('(fair) a real in-overlap difference still gates on the fair ratio', () => {
  // 20% of the overlap genuinely differs even though dimensions also differ. The
  // fair ratio (0.2) exceeds the threshold, so it gates and reports overlap counts.
  const overlapPixels = 1280 * 5017;
  const overlapMismatchPixels = Math.round(overlapPixels * 0.2);
  const totalPixels = 1380 * 7248;
  const payload = {
    schema: 'wp-codebox/visual-compare/v1',
    comparison: {
      mismatchPixels: overlapMismatchPixels + (totalPixels - overlapPixels),
      totalPixels,
      dimensionMismatch: true,
      overlapMismatchPixels,
      overlapPixels,
      dimensionDeltaPixels: totalPixels - overlapPixels,
    },
  };
  const diagnostics = collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true });
  assert.equal(diagnostics.length, 1);
  assert.ok(Math.abs(diagnostics[0].mismatch_ratio - 0.2) < 0.001, `gating ratio should be the fair ~0.2, got ${diagnostics[0].mismatch_ratio}`);
  assert.equal(diagnostics[0].mismatch_pixels, overlapMismatchPixels);
  assert.equal(diagnostics[0].total_pixels, overlapPixels);
  assert.ok(diagnostics[0].raw_mismatch_ratio > diagnostics[0].mismatch_ratio, 'raw ratio should exceed fair ratio');
});

test('(fair) pre-overlap evidence falls back to the raw ratio for gating', () => {
  // Older wp-codebox evidence with no overlap fields still gates on the raw ratio.
  const payload = {
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 600000, totalPixels: 2048000, dimensionMismatch: false },
  };
  const diagnostics = collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true });
  assert.equal(diagnostics.length, 1);
  assert.ok(Math.abs(diagnostics[0].mismatch_ratio - 600000 / 2048000) < 1e-9);
});

test('visual-compare diagnostics retain bounded generic visual-explanation evidence', () => {
  const payload = {
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 600000, totalPixels: 2048000, dimensionMismatch: false },
    visual_explanation: {
      schema: 'wp-codebox/visual-explanation/v1',
      summary: { selector_diagnostic_count: 7, property_diagnostic_count: 1, layout_diagnostic_count: 1, capture_diagnostic_count: 1 },
      selectors: Array.from({ length: 7 }, (_, index) => ({ selector: `.card-${index}`, reason: `selector mismatch ${index}` })),
      properties: [{ selector: '.hero', property: 'font-size', source_value: '48px', target_value: '32px', reason: 'computed style differs' }],
      layout: [{ selector: '.hero', source_rect: { width: 1280 }, target_rect: { width: 960 }, delta: { width: -320 } }],
      capture: [{ phase: 'source', viewport: { width: 1280, height: 720 }, message: 'captured bounded viewport' }],
    },
  };

  const diagnostics = collectVisualParityDiagnostics(payload, { threshold: 0.1, gate: true });
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].kind, VISUAL_PARITY_MISMATCH_KIND);
  assert.equal(diagnostics[0].visual_explanation_summary.selector_diagnostic_count, 7);
  assert.equal(diagnostics[0].visual_selector_diagnostics.length, 5, 'selector evidence is bounded');
  assert.equal(diagnostics[0].visual_selector_diagnostics[0].selector, '.card-0');
  assert.equal(diagnostics[0].visual_property_diagnostics[0].property, 'font-size');
  assert.equal(diagnostics[0].visual_layout_diagnostics[0].selector, '.hero');
  assert.equal(diagnostics[0].visual_capture_diagnostics[0].phase, 'source');

  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-explanation-finding-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [{ fixture_id: 'simple-site', status: 'passed', diagnostics }],
  });
  const finding = result.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(finding, 'expected visual parity finding');
  assert.equal(finding.loss_class, 'visual_parity_mismatch');
  assert.equal(finding.repair_bucket, 'visual_parity_mismatch');
  assert.equal(finding.visual_selector_diagnostics.length, 5);
  assert.equal(finding.visual_property_diagnostics[0].property, 'font-size');
});

test('visual parity findings preserve generic attribution fields and bounded context', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-attribution-finding-test' });
  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'passed',
        diagnostics: [
          {
            id: 'visual-001',
            kind: VISUAL_PARITY_MISMATCH_KIND,
            category: 'visual',
            severity: 'warning',
            summary: 'Button styling differs between source and import.',
            reason_code: 'visual_style_delta',
            repair_bucket: 'visual_parity_mismatch',
            pattern_family: 'visual_parity_mismatch:button_style:class:hero',
            confidence: 0.82,
            selector_evidence: {
              source_selector: '.hero .cta',
              target_selector: '.wp-block-button__link',
              source_text: 'Start now',
              target_text: 'Start now',
            },
            property_evidence: [
              {
                property: 'background-color',
                source_value: '#111111',
                target_value: '#ffffff',
                delta: 'changed',
              },
            ],
            style_deltas: [
              {
                property: 'border-radius',
                source_value: '999px',
                target_value: '4px',
                severity: 'warning',
              },
            ],
          },
        ],
      },
    ],
  });

  const finding = result.findings.find((item) => item.id === 'visual-001');
  assert.ok(finding, 'expected visual parity finding');
  assert.equal(finding.loss_class, 'visual_parity_mismatch');
  assert.equal(finding.reason_code, 'visual_style_delta');
  assert.equal(finding.repair_bucket, 'visual_parity_mismatch');
  assert.equal(finding.pattern_family, 'visual_parity_mismatch:button_style:class:hero');
  assert.equal(finding.confidence, 0.82);
  assert.equal(finding.selector, '.hero .cta');
  assert.equal(finding.selector_family, 'class:hero');
  assert.equal(finding.source_snippet, 'Start now');
  assert.equal(finding.observed_output, 'Start now');
  assert.equal(finding.selector_evidence.target_selector, '.wp-block-button__link');
  assert.equal(finding.property_evidence[0].property, 'background-color');
  assert.equal(finding.style_deltas[0].property, 'border-radius');
  assert.equal(result.summary.diagnostic_blind_spots.some((spot) => spot.kind === 'missing_source_context'), false);
});

test('visual-explanation.json is merged into collected visual parity artifacts generically', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-explanation-artifact-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-explanation-artifact-test' });
  const fixtureDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'visual-compare.json'), JSON.stringify({
    schema: 'wp-codebox/visual-compare/v1',
    comparison: { mismatchPixels: 700000, totalPixels: 2048000, dimensionMismatch: false },
  }));
  writeFileSync(path.join(fixtureDirectory, 'visual-explanation.json'), JSON.stringify({
    visual_explanation: {
      schema: 'wp-codebox/visual-explanation/v1',
      selector_diagnostic_count: 1,
      property_diagnostic_count: 1,
      selector_diagnostics: [{ selector: 'a.cta', reason: 'button alignment differs' }],
      property_diagnostics: [{ selector: 'a.cta', property: 'background-color', source_value: '#000', target_value: '#111' }],
    },
  }));

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, visualParity: { threshold: 0.1, gate: true } });
  const finding = result.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(finding, 'expected visual parity finding from collected files');
  assert.equal(finding.loss_class, 'visual_parity_mismatch');
  assert.equal(finding.visual_selector_diagnostics[0].selector, 'a.cta');
  assert.equal(finding.visual_property_diagnostics[0].property, 'background-color');
  assert.equal(result.fixtures[0].visual_parity_artifacts.visual_explanation.selector_diagnostics[0].selector, 'a.cta');
  assert.equal(result.fixtures[0].visual_parity_artifacts.visual_explanation.property_diagnostics[0].property, 'background-color');
});

test('WP Codebox recipe browserEvidence visual refs are preserved with fixture identity', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-codebox-browser-evidence-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'codebox-browser-evidence-test' });
  const codeboxOutput = {
    schema: 'wp-codebox/recipe-run/v1',
    executions: [
      {
        command: 'wordpress.wp-cli',
        args: ['command=static-site-importer validate-artifact --artifact=/artifacts/simple-site/artifact.json --slug=simple-site --allow-failure'],
        recipePhase: 'steps',
        recipeStepIndex: 1,
        exitCode: 0,
      },
      {
        command: 'wordpress.visual-compare',
        args: ['source-label=simple-site-source', 'candidate-label=simple-site-candidate'],
        recipePhase: 'steps',
        recipeStepIndex: 2,
        exitCode: 0,
      },
    ],
    browserEvidence: [
      {
        schema: 'wp-codebox/recipe-browser-evidence/v1',
        phase: 'steps',
        index: 2,
        command: 'wordpress.visual-compare',
        status: 'completed',
        files: {
          sourceScreenshot: { path: 'files/browser/visual-compare/source.png', kind: 'browser-visual-source-screenshot' },
          candidateScreenshot: { path: 'files/browser/visual-compare/candidate.png', kind: 'browser-visual-candidate-screenshot' },
          diffScreenshot: { path: 'files/browser/visual-compare/diff.png', kind: 'browser-visual-diff-screenshot' },
          visualDiff: { path: 'files/browser/visual-compare/visual-diff.json', kind: 'browser-visual-diff' },
          visualExplanation: { path: 'files/browser/visual-compare/visual-explanation.json', kind: 'browser-visual-explanation' },
          sourceDomSnapshot: { path: 'files/browser/visual-compare/source-dom-snapshot.json', kind: 'browser-source-dom-snapshot' },
          candidateDomSnapshot: { path: 'files/browser/visual-compare/candidate-dom-snapshot.json', kind: 'browser-candidate-dom-snapshot' },
          summary: { path: 'files/browser/visual-compare/summary.json', kind: 'browser-summary' },
        },
        summary: {
          visualCompare: {
            mismatchPixels: 357562,
            totalPixels: 2048000,
            mismatchRatio: 357562 / 2048000,
            overlapMismatchPixels: 357562,
            overlapPixels: 2048000,
            dimensionMismatch: false,
            captureDiagnostics: [{ phase: 'candidate', message: 'captured imported viewport' }],
          },
          visualExplanation: {
            schema: 'wp-codebox/visual-explanation/v1',
            selector_diagnostic_count: 1,
            layout_diagnostic_count: 1,
            capture_diagnostic_count: 1,
            selector_deltas: [{ selector: '.hero', reason: 'text shifted' }],
            layout_drift: [{ selector: '.hero', delta: { y: 12 }, message: 'hero moved down' }],
          },
        },
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput, visualParity: { threshold: 0.1, gate: true } });
  const fixture = result.fixtures[0];
  const artifacts = fixture.visual_parity_artifacts.artifacts;
  const finding = result.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);

  assert.equal(fixture.fixture_id, 'simple-site');
  assert.equal(fixture.visual_parity_artifacts.metrics.mismatch_pixels, 357562);
  assert.equal(artifacts.source_screenshot.status, 'captured');
  assert.equal(artifacts.source_screenshot.ref.path, 'files/browser/visual-compare/source.png');
  assert.equal(artifacts.imported_screenshot.ref.path, 'files/browser/visual-compare/candidate.png');
  assert.equal(artifacts.diff_screenshot.ref.path, 'files/browser/visual-compare/diff.png');
  assert.equal(artifacts.visual_diff.ref.path, 'files/browser/visual-compare/visual-diff.json');
  assert.equal(artifacts.visual_explanation.ref.path, 'files/browser/visual-compare/visual-explanation.json');
  assert.equal(artifacts.source_dom_snapshot.ref.path, 'files/browser/visual-compare/source-dom-snapshot.json');
  assert.equal(artifacts.candidate_dom_snapshot.ref.path, 'files/browser/visual-compare/candidate-dom-snapshot.json');
  assert.equal(fixture.visual_parity_artifacts.visual_explanation.selector_diagnostics[0].selector, '.hero');
  assert.equal(fixture.visual_parity_artifacts.visual_explanation.layout_diagnostics[0].selector, '.hero');
  assert.ok(finding, 'expected a visual parity finding from WP Codebox browserEvidence');
  assert.equal(finding.visual_selector_diagnostics[0].selector, '.hero');
  assert.equal(finding.visual_layout_diagnostics[0].message, 'hero moved down');
  assert.equal(finding.visual_capture_diagnostics[0].phase, 'candidate');
});

test('visual parity evidence report summarizes staged output evidence and layout risks', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-evidence-report-'));
  const fixtureRootDirectory = path.join(outputDirectory, 'fixtures');
  const fixtureDirectory = path.join(fixtureRootDirectory, 'simple-site');
  mkdirSync(fixtureDirectory, { recursive: true });
  writeFileSync(path.join(fixtureDirectory, 'index.html'), '<h1>Simple SSI Fixture</h1>');
  writeFileSync(path.join(fixtureDirectory, 'fixture.json'), JSON.stringify({ class: 'marketing/static' }));

  const matrix = createFixtureMatrix({ fixture_root: fixtureRootDirectory, id: 'visual-evidence-report-test' });
  const artifactDirectory = path.join(outputDirectory, 'simple-site');
  mkdirSync(path.join(artifactDirectory, 'source'), { recursive: true });
  mkdirSync(path.join(artifactDirectory, 'files', 'browser'), { recursive: true });
  writeFileSync(path.join(artifactDirectory, 'artifact.json'), JSON.stringify({ schema: 'blocks-engine/php-transformer/site-artifact/v1' }));
  writeFileSync(path.join(artifactDirectory, 'source', 'index.html'), '<h1>Simple SSI Fixture</h1>');
  writeFileSync(path.join(artifactDirectory, 'files', 'browser', 'snapshot.html'), '<main class="wp-site-blocks"><h1>Simple SSI Fixture</h1></main>');

  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [
      {
        fixture_id: 'simple-site',
        status: 'failed',
        missing_assets: [{ kind: 'missing_asset', path: 'images/hero.png', message: 'Missing imported asset.' }],
        block_composition: { block_total: 5, native_block_count: 4, core_html_block_count: 1 },
        visual_parity_artifacts: {
          metrics: { mismatch_pixels: 0, total_pixels: 1000, mismatch_ratio: 0 },
          capture_diagnostics: [{ phase: 'candidate', viewport: { width: 1280, height: 720 }, message: 'desktop viewport captured' }],
          visual_attribution_summary: {
            schema: 'homeboy/WordPressVisualAttribution/v1',
            status: 'available',
            mismatch_region_count: 2,
            selector_delta_count: 3,
            geometry_delta_count: 1,
            computed_style_delta_counts: { paint: 2 },
            changed_count: 3,
            added_count: 1,
            removed_count: 0,
            top_findings: [{ kind: 'geometry', selector: '.hero' }],
            limitations_count: 0,
            attribution_ref: 'visual-compare/simple-site/visual-attribution.json',
          },
          artifacts: {
            source_screenshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/source.png', kind: 'browser-visual-source-screenshot' } },
            imported_screenshot: { status: 'captured', ref: { path: 'files/browser/visual-compare/candidate.png', kind: 'browser-visual-candidate-screenshot' } },
          },
        },
      },
    ],
  });

  const report = buildVisualParityEvidenceReport({ outputDirectory, matrix, result });
  const fixture = report.fixtures[0];

  assert.equal(report.schema, 'static-site-importer/visual-parity-evidence-report/v1');
  assert.equal(report.summary.fixture_count, 1);
  assert.equal(report.summary.generated_artifact_fixture_count, 1);
  assert.equal(report.summary.staged_source_fixture_count, 1);
  assert.equal(report.summary.imported_snapshot_fixture_count, 1);
  assert.equal(report.summary.visual_compare_fixture_count, 1);
  assert.equal(report.summary.screenshot_fixture_count, 1);
  assert.equal(report.summary.viewport_evidence_fixture_count, 1);
  assert.equal(report.summary.mobile_viewport_fixture_count, 0);
  assert.equal(report.summary.visual_attribution_fixture_count, 1);
  assert.equal(report.summary.limited_visual_attribution_fixture_count, 0);
  assert.equal(report.summary.missing_asset_fixture_count, 1);
  assert.equal(report.summary.core_html_fixture_count, 1);
  assert.equal(fixture.asset_resolution.missing_asset_count, 1);
  assert.equal(fixture.block_theme.native_conversion_rate, 0.8);
  assert.equal(fixture.block_theme.core_html_block_count, 1);
  assert.equal(fixture.evidence.visual_attribution.status, 'available');
  assert.equal(fixture.evidence.visual_attribution.geometry_delta_count, 1);
  assert.equal(fixture.evidence.visual_attribution.attribution_ref, 'visual-compare/simple-site/visual-attribution.json');
  assert.ok(fixture.risk.reasons.includes('missing mobile viewport evidence'));
  assert.ok(fixture.risk.reasons.includes('1 missing asset signal(s)'));
  assert.ok(fixture.risk.reasons.includes('1 core/html block(s)'));
});

test('visual parity evidence report requires captured comparison evidence', () => {
  const fixtures = [
    {
      fixture_id: 'editor-screenshot-only',
      artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'editor-canvas', path: 'files/browser/editor-open.png' }],
      visual_parity_artifacts: {
        artifacts: {
          visual_diff: { status: 'pending', capture_state: 'not_captured' },
        },
      },
    },
    { fixture_id: 'metrics', visual_parity_artifacts: { metrics: { mismatch_pixels: 0, total_pixels: 1000 } } },
    { fixture_id: 'captured-source', visual_parity_artifacts: { artifacts: { source_screenshot: { status: 'captured' } } } },
    { fixture_id: 'captured-candidate', visual_parity_artifacts: { artifacts: { imported_screenshot: { status: 'captured' } } } },
    { fixture_id: 'captured-diff', visual_parity_artifacts: { artifacts: { diff_screenshot: { status: 'captured' } } } },
    { fixture_id: 'normalized-comparison', visual_parity_comparisons: [{ source_path: '/' }] },
    { fixture_id: 'typed-compare-ref', artifact_refs: [{ artifact_id: 'visual-compare-result', kind: 'diagnostic' }] },
    { fixture_id: 'typed-diff-ref', artifact_refs: [{ artifact_id: 'result', kind: 'browser-visual-diff' }] },
  ];
  const report = buildVisualParityEvidenceReport({ result: { fixtures } });
  const rows = new Map(report.fixtures.map((fixture) => [fixture.fixture_id, fixture]));

  assert.equal(report.summary.visual_compare_fixture_count, fixtures.length - 1);
  assert.equal(rows.get('editor-screenshot-only').evidence.visual_compare.status, 'missing');
  assert.equal(rows.get('editor-screenshot-only').evidence.screenshots.status, 'present');
  for (const fixture of fixtures.slice(1)) {
    assert.equal(rows.get(fixture.fixture_id).evidence.visual_compare.status, 'present', fixture.fixture_id);
  }
});

test('fixture matrix result artifacts include visual parity evidence JSON and markdown reports', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-evidence-artifacts-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-evidence-artifact-test' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix });
  const reportPath = path.join(outputDirectory, 'visual-parity-evidence-report.json');
  const markdownPath = path.join(outputDirectory, 'visual-parity-evidence-report.md');
  const report = JSON.parse(readFileSync(reportPath, 'utf8'));

  assert.equal(report.schema, 'static-site-importer/visual-parity-evidence-report/v1');
  assert.ok(existsSync(markdownPath));
  assert.ok(written.artifact_refs.some((ref) => ref.artifact_id === 'visual-parity-evidence-report'));
  assert.ok(written.artifact_refs.some((ref) => ref.artifact_id === 'visual-parity-evidence-report-markdown'));
});

// #554: at lane scale (~30+ fixtures) the aggregate result used to retain each
// fixture's raw serialized `post_content`/block markup (via `raw: input` and the
// #552 block-composition path) plus uncapped finding snippets, so JSON.stringify
// of the assembled result exceeded V8's ~512MB per-string ceiling and threw
// `Invalid string length`. The output must now be bounded by #fixtures/#findings,
// not by raw content volume.
test('bounds the assembled output regardless of per-fixture raw content volume (#554)', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-bounded-output-'));
  const fixtureCount = 40;
  // ~5MB serialized post_content + many large finding snippets per fixture, so
  // the raw input dwarfs any safe serialized-output bound.
  const hugePostContent = '<!-- wp:paragraph --><p>'.concat('x'.repeat(5 * 1024 * 1024), '</p><!-- /wp:paragraph -->');
  const hugeSnippet = '<section>'.concat('y'.repeat(200 * 1024), '</section>');
  let rawContentBytes = 0;

  const results = [];
  for (let index = 0; index < fixtureCount; index += 1) {
    const id = `marketing-${String(index).padStart(3, '0')}`;
    const directory = path.join(root, id);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), '<h1>Landing</h1>');
    writeFileSync(path.join(directory, 'fixture.json'), JSON.stringify({ class: 'marketing/static' }));

    // Many findings, each carrying a large source snippet / observed output.
    const diagnostics = [];
    for (let findingIndex = 0; findingIndex < 12; findingIndex += 1) {
      diagnostics.push({
        kind: 'runtime_dependency_missing_dom_target',
        repair_bucket: 'runtime_target_gap',
        candidate_repo: 'blocks-engine',
        source_path: `website/page-${findingIndex}.html`,
        selector: `#widget-${findingIndex}`,
        source_html_preview: hugeSnippet,
        emitted_block_preview: hugeSnippet,
        message: `Runtime target missing for widget ${findingIndex}: ${hugeSnippet}`,
      });
      rawContentBytes += hugeSnippet.length * 2 + hugeSnippet.length;
    }

    results.push({
      fixture_id: id,
      status: 'failed',
      // The #552 block-composition path: counts come from block_type_counts; the
      // raw markup below must NOT survive into the assembled output.
      block_type_counts: { 'core/paragraph': 7, 'core/html': 3 },
      post_content: hugePostContent,
      import_report: {
        materialized_content: {
          block_documents: [
            { source_path: 'posts/page-home.post_content', block_count: 10, core_html_block_count: 3, freeform_block_count: 0, post_content: hugePostContent },
          ],
        },
      },
      diagnostics,
    });
    rawContentBytes += hugePostContent.length * 2;
  }

  const matrix = createFixtureMatrix({ fixture_root: root, id: 'bounded-output-scale-test' });
  assert.equal(matrix.fixtures.length, fixtureCount);

  const result = normalizeFixtureMatrixResult({ matrix, results });

  // The assembled aggregate must serialize without throwing `Invalid string
  // length`, and stay well under a safe bound regardless of raw content volume.
  let serialized;
  assert.doesNotThrow(() => { serialized = JSON.stringify(result); }, 'assembled result must JSON.stringify successfully');
  const serializedBytes = Buffer.byteLength(serialized, 'utf8');
  const FIFTY_MB = 50 * 1024 * 1024;
  assert.ok(serializedBytes < FIFTY_MB, `serialized output ${serializedBytes} bytes must stay under ${FIFTY_MB} bytes`);
  // The raw inputs are an order of magnitude larger than the bound: output size
  // is decoupled from raw content volume, not merely "small for this fixture set".
  assert.ok(rawContentBytes > 200 * 1024 * 1024, 'sanity: the raw inputs must dwarf the output bound');
  assert.ok(serializedBytes * 10 < rawContentBytes, 'output must be bounded independently of raw content volume');

  // Raw bulk is dropped: no `raw` blob is retained on fixtures or findings, and
  // no full-length serialized body survives.
  assert.ok(result.fixtures.every((fixture) => fixture.raw === undefined), 'fixture results must not retain raw input');
  assert.ok(result.findings.every((finding) => finding.raw === undefined), 'findings must not retain the raw diagnostic');
  assert.ok(result.findings.every((finding) => finding.source_snippet.length < hugeSnippet.length), 'finding snippets must be truncated');
  const retainedPostContent = result.fixtures[0].import_report.materialized_content.block_documents[0].post_content;
  assert.ok(retainedPostContent.length < hugePostContent.length, 'retained report markup must be truncated');

  // Metrics survive the bounding intact: native rate, block counts, and finding
  // counts are computed from the full input before raw bulk is dropped.
  assert.equal(result.summary.editor_quality.block_total, fixtureCount * 10);
  assert.equal(result.summary.editor_quality.native_block_count, fixtureCount * 7);
  assert.equal(result.summary.editor_quality.core_html_block_count, fixtureCount * 3);
  assert.equal(result.summary.editor_quality.native_conversion_rate, 0.7);
  assert.equal(result.summary.fixture_count, fixtureCount);
  assert.ok(result.summary.finding_count >= fixtureCount, 'every fixture must contribute findings');
});

test('live-WP parity capture step is opt-in and off by default', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'live-wp-default' });

  const off = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi' });
  assert.equal(
    off.workflow.steps.some((step) => step.command === 'wordpress.capture-html'),
    false,
    'capture-html is not emitted unless live-WP parity is explicitly enabled',
  );
  assert.equal(liveWpParityEnabled({}), false);
  assert.equal(liveWpParityEnabled({ live_wp_parity: true }), true);
});

test('live-WP parity capture step renders DOM HTML deterministically with external requests blocked', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'live-wp-on' });
  const recipe = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', liveWpParity: true });

  const captureSteps = recipe.workflow.steps.filter((step) => step.command === 'wordpress.capture-html');
  assert.ok(captureSteps.length >= 1, 'one capture-html step per fixture when enabled');
  const args = captureSteps[0].args;
  assert.ok(args.includes('capture=html'), 'captures DOM HTML, not a screenshot');
  assert.ok(args.includes('network-policy=block'), 'blocks external requests for determinism');
  assert.ok(args.some((arg) => arg.startsWith('url=')), 'targets the imported candidate URL');
  assert.ok(args.every((arg) => !arg.includes('screenshot')), 'never requests a screenshot');

  // Same inputs -> identical step (the recipe builder is pure).
  const repeat = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', liveWpParity: true });
  assert.deepEqual(
    repeat.workflow.steps.filter((step) => step.command === 'wordpress.capture-html'),
    captureSteps,
  );

  // The standalone step builder honors a per-fixture candidate override.
  const overridden = liveWpParityCaptureStep({ fixture: { id: 'x', candidate_url: '/about/' } });
  assert.equal(overridden.allowFailure, true);
  assert.equal(overridden.metadata.fixture_id, 'x');
  assert.ok(overridden.args.includes('url=/about/'));
});

test('runLiveWpParity feeds the captured snapshot to the blocks-engine CLI and surfaces live-WP vs proxy', () => {
  const cliReport = {
    schema: 'blocks-engine/php-transformer/live-wp-parity-report/v1',
    source: 'index.html',
    candidate: 'snapshot.html',
    live_wp: {
      status: 'fail',
      parity: { score: 0.91, property_parity: 0.97, coverage: 0.94 },
      summary: { source_total: 100, matched_total: 94, finding_total: 6 },
      matches: [
        {
          source_selector: 'a.cta',
          target_selector: 'a.cta.wp-element-button',
          style_deltas: [{ property: 'background-color', source: '#ff0000', target: '' }],
        },
      ],
    },
    comparison: { live_wp_score: 0.91, proxy_score: 0.7328, delta: 0.1772 },
  };

  const calls = [];
  const exec = (command, args) => {
    calls.push({ command, args });
    return { status: 0, stdout: JSON.stringify(cliReport), stderr: '' };
  };

  const result = runLiveWpParity({
    sourceHtmlPath: '/fixtures/15-saas/index.html',
    candidateHtmlPath: '/artifacts/15-saas/files/browser/snapshot.html',
    blocksEnginePhpTransformerPath: '/repo/php-transformer',
    exec,
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].command, 'php');
  assert.ok(calls[0].args[0].endsWith(path.join('tools', 'live-wp-parity', 'run.php')));
  assert.ok(calls[0].args.includes('--with-proxy'));
  assert.ok(calls[0].args.includes('--json'));
  assert.ok(calls[0].args.includes('/artifacts/15-saas/files/browser/snapshot.html'));

  assert.equal(result.schema, 'static-site-importer/live-wp-parity-result/v1');
  assert.equal(result.score, 0.91);
  assert.equal(result.finding_total, 6);
  assert.equal(result.comparison.proxy_score, 0.7328);
  assert.equal(result.comparison.delta, 0.1772);
  assert.equal(result.property_diffs.length, 1);
  assert.equal(result.property_diffs[0].property, 'background-color');
  assert.equal(result.property_diffs[0].source_selector, 'a.cta');
});

test('runLiveWpParity surfaces a CLI failure rather than a bogus parity result', () => {
  const exec = () => ({ status: 2, stdout: '', stderr: 'Candidate file not found: snapshot.html' });
  assert.throws(
    () => runLiveWpParity({
      sourceHtmlPath: '/s.html',
      candidateHtmlPath: '/c.html',
      blocksEnginePhpTransformerPath: '/repo/php-transformer',
      exec,
    }),
    /live-wp-parity CLI failed/,
  );
});

test('normalizeLiveWpParityReport bounds the per-property diff list', () => {
  const matches = [{
    source_selector: 's',
    target_selector: 't',
    style_deltas: Array.from({ length: 40 }, (_, i) => ({ property: `p${i}`, source: 'a', target: 'b' })),
  }];
  const normalized = normalizeLiveWpParityReport({ live_wp: { matches, parity: { score: 0.5 } } }, { diffLimit: 10 });
  assert.equal(normalized.property_diffs.length, 10);
  assert.equal(normalized.score, 0.5);
  assert.equal(normalized.comparison, undefined, 'no comparison block when the CLI omits --with-proxy');
});

// End-to-end toggle wiring (PR #578 follow-up): proves the live-WP parity toggle
// is threaded flag -> env -> recipe -> collector, and that the OFF path is
// byte-identical to today (capture step absent, result carries no live_wp_parity).
test('--live-wp-parity threads flag -> env into the bench, OFF leaves it absent', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-live-wp-parity-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const planFixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(planFixtureRoot, 'fixture-a'), { recursive: true });

  // Default: no live-WP parity env setting (unchanged behavior).
  const offPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: planFixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  assert.equal(
    offPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_LIVE_WP_PARITY=1'),
    false,
    'no live-WP parity bench env is emitted unless the flag is passed',
  );

  // --live-wp-parity -> options.liveWpParity === true -> env=1 setting threaded
  // into the bench (mirrors --visual-parity-gate).
  const onPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot: planFixtureRoot,
    liveWpParity: true,
    skipInstall: true,
    skipSync: true,
  });
  assert.ok(onPlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_LIVE_WP_PARITY=1'));
});

test('live-WP parity toggle adds the capture step + invokes the collector when ON, byte-identical OFF', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'live-wp-toggle' });
  const fixtureId = matrix.fixtures[0].id;

  // RECIPE: OFF is byte-identical to the same recipe with no live-WP input, and
  // emits no capture-html step. ON appends exactly one capture-html step.
  const invocation = { runId: 'live-wp-toggle-run', attemptId: 'live-wp-toggle-attempt' };
  const recipeBaseline = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', ...invocation });
  const recipeOff = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', liveWpParity: false, ...invocation });
  assert.deepEqual(recipeOff, recipeBaseline, 'liveWpParity:false leaves the recipe byte-identical to today');
  assert.equal(recipeOff.workflow.steps.some((step) => step.command === 'wordpress.capture-html'), false);
  const recipeOn = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', liveWpParity: true });
  assert.equal(recipeOn.workflow.steps.filter((step) => step.command === 'wordpress.capture-html').length, 1);

  // COLLECTOR: stage the captured rendered DOM snapshot + the source so the
  // host-side collector has both sides to compare.
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-live-wp-collector-'));
  mkdirSync(path.join(outputDirectory, fixtureId, 'files', 'browser'), { recursive: true });
  mkdirSync(path.join(outputDirectory, fixtureId, 'source'), { recursive: true });
  writeFileSync(path.join(outputDirectory, fixtureId, 'files', 'browser', 'snapshot.html'), '<html><body>candidate</body></html>', 'utf8');
  writeFileSync(path.join(outputDirectory, fixtureId, 'source', 'index.html'), '<html><body>source</body></html>', 'utf8');

  const cliReport = {
    schema: 'blocks-engine/php-transformer/live-wp-parity-report/v1',
    source: 'index.html',
    candidate: 'snapshot.html',
    live_wp: {
      status: 'fail',
      parity: { score: 0.88, property_parity: 0.95, coverage: 0.9 },
      summary: { source_total: 50, matched_total: 45, finding_total: 5 },
      matches: [],
    },
    comparison: { live_wp_score: 0.88, proxy_score: 0.7, delta: 0.18 },
  };
  const calls = [];
  const exec = (command, args) => {
    calls.push({ command, args });
    return { status: 0, stdout: JSON.stringify(cliReport), stderr: '' };
  };

  // OFF (and absent) are byte-identical and carry no live_wp_parity.
  const resultAbsent = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const resultOff = collectFixtureMatrixRunResults({ matrix, outputDirectory, liveWpParity: { enabled: false, exec } });
  assert.deepEqual(resultOff, resultAbsent, 'disabled live-WP parity is byte-identical to the default collector result');
  assert.equal(resultAbsent.fixtures[0].live_wp_parity, undefined, 'no live_wp_parity key on the default result');
  assert.equal(calls.length, 0, 'the comparator is never invoked when the toggle is off');

  // ON: the comparator runs with --with-proxy and the result carries the live-WP
  // score, the render-free proxy score, and the live-vs-proxy delta.
  const resultOn = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    liveWpParity: { enabled: true, blocksEnginePhpTransformerPath: '/repo/php-transformer', exec },
  });
  assert.equal(calls.length, 1, 'the comparator is invoked once per fixture when on');
  assert.ok(calls[0].args.includes('--with-proxy'), 'the collector requests the render-free proxy delta');
  assert.ok(calls[0].args.includes(path.join(outputDirectory, fixtureId, 'files', 'browser', 'snapshot.html')));
  const liveWp = resultOn.fixtures[0].live_wp_parity;
  assert.ok(liveWp, 'the fixture result carries a live-WP parity result when on');
  assert.equal(liveWp.schema, 'static-site-importer/live-wp-parity-result/v1');
  assert.equal(liveWp.score, 0.88);
  assert.equal(liveWp.comparison.proxy_score, 0.7);
  assert.equal(liveWp.comparison.delta, 0.18);
});

test('live-WP parity collector failure is isolated and never sinks the lane', () => {
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'live-wp-isolation' });
  const fixtureId = matrix.fixtures[0].id;
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-live-wp-isolation-'));
  mkdirSync(path.join(outputDirectory, fixtureId, 'files', 'browser'), { recursive: true });
  mkdirSync(path.join(outputDirectory, fixtureId, 'source'), { recursive: true });
  writeFileSync(path.join(outputDirectory, fixtureId, 'files', 'browser', 'snapshot.html'), '<html></html>', 'utf8');
  writeFileSync(path.join(outputDirectory, fixtureId, 'source', 'index.html'), '<html></html>', 'utf8');

  // Comparator hard-fails: the collector swallows it (no live_wp_parity) rather
  // than throwing out of the lane.
  const exec = () => ({ status: 2, stdout: '', stderr: 'boom' });
  const result = collectFixtureMatrixRunResults({
    matrix,
    outputDirectory,
    liveWpParity: { enabled: true, blocksEnginePhpTransformerPath: '/repo/php-transformer', exec },
  });
  assert.equal(result.fixtures[0].live_wp_parity, undefined, 'a comparator failure yields no live-WP result, not an aborted lane');
  assert.equal(result.schema, 'static-site-importer/fixture-matrix-result/v1');
});

function visualComparePayload({ sourceScreenshot, candidateScreenshot, diffScreenshot, mismatchPixels, totalPixels, overlapMismatchPixels, overlapPixels, dimensionMismatch = false, mismatchRegions = [] }) {
  return {
    schema: 'wp-codebox/visual-compare-matrix/v1',
    comparisons: [
      {
        name: 'synthetic',
        source: { url: 'file:///synthetic/index.html' },
        files: { sourceScreenshot, candidateScreenshot, ...(diffScreenshot ? { diffScreenshot } : {}) },
        comparison: {
          mismatchPixels,
          totalPixels,
          overlapMismatchPixels,
          overlapPixels,
          dimensionMismatch,
          ...(mismatchRegions.length ? { mismatchRegions } : {}),
        },
      },
    ],
  };
}

function visualDiffClassificationFixture(name, mutate) {
  const fixtureArtifactsDirectory = mkdtempSync(path.join(tmpdir(), `ssi-visual-classify-${name}-`));
  const visualDirectory = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'visual-compare', name);
  mkdirSync(visualDirectory, { recursive: true });
  const source = blankPng(48, 40);
  const candidate = blankPng(48, 40);
  mutate(source, candidate);
  const diff = exactDiffPng(source, candidate);
  writePng(path.join(visualDirectory, 'source.png'), source);
  writePng(path.join(visualDirectory, 'candidate.png'), candidate);
  writePng(path.join(visualDirectory, 'diff.png'), diff);
  const mismatchPixels = countDiffPixels(diff);
  return {
    fixtureArtifactsDirectory,
    payload: visualComparePayload({
      sourceScreenshot: `files/browser/visual-compare/${name}/source.png`,
      candidateScreenshot: `files/browser/visual-compare/${name}/candidate.png`,
      diffScreenshot: `files/browser/visual-compare/${name}/diff.png`,
      mismatchPixels,
      totalPixels: 48 * 40,
      overlapMismatchPixels: mismatchPixels,
      overlapPixels: 48 * 40,
    }),
  };
}

function exactDiffPng(source, candidate) {
  const image = blankPng(source.width, source.height);
  fillRect(image, 0, 0, image.width, image.height, [0, 0, 0, 0]);
  for (let y = 0; y < source.height; y += 1) {
    for (let x = 0; x < source.width; x += 1) {
      const index = ((y * source.width) + x) << 2;
      const differs = source.data[index] !== candidate.data[index]
        || source.data[index + 1] !== candidate.data[index + 1]
        || source.data[index + 2] !== candidate.data[index + 2]
        || source.data[index + 3] !== candidate.data[index + 3];
      if (differs) {
        image.data[index] = 255;
        image.data[index + 1] = 0;
        image.data[index + 2] = 0;
        image.data[index + 3] = 255;
      }
    }
  }
  return image;
}

function countDiffPixels(diff) {
  let pixels = 0;
  for (let y = 0; y < diff.height; y += 1) {
    for (let x = 0; x < diff.width; x += 1) {
      const index = ((y * diff.width) + x) << 2;
      if (diff.data[index] || diff.data[index + 1] || diff.data[index + 2]) {
        pixels += 1;
      }
    }
  }
  return pixels;
}

function syntheticVisualParityPng(width, height) {
  const image = blankPng(width, height);
  fillRect(image, 0, 0, width, height, [245, 245, 245, 255]);
  fillRect(image, 0, 12, width, 24, [28, 28, 28, 255]);
  fillRect(image, 6, 18, 16, 12, [220, 64, 64, 255]);
  fillRect(image, 26, 44, 15, 20, [32, 96, 220, 255]);
  fillRect(image, 4, 72, width - 8, 8, [20, 140, 80, 255]);
  return image;
}

function blankPng(width, height) {
  const image = new PNG({ width, height });
  fillRect(image, 0, 0, width, height, [255, 255, 255, 255]);
  return image;
}

function shiftedPng(source, xOffset, yOffset) {
  const image = blankPng(source.width, source.height);
  for (let y = 0; y < source.height; y += 1) {
    for (let x = 0; x < source.width; x += 1) {
      const targetX = x + xOffset;
      const targetY = y + yOffset;
      if (targetX < 0 || targetY < 0 || targetX >= image.width || targetY >= image.height) {
        continue;
      }
      const sourceIndex = ((y * source.width) + x) << 2;
      const targetIndex = ((targetY * image.width) + targetX) << 2;
      image.data[targetIndex] = source.data[sourceIndex];
      image.data[targetIndex + 1] = source.data[sourceIndex + 1];
      image.data[targetIndex + 2] = source.data[sourceIndex + 2];
      image.data[targetIndex + 3] = source.data[sourceIndex + 3];
    }
  }
  return image;
}

function fillRect(image, x, y, width, height, rgba) {
  for (let row = y; row < y + height; row += 1) {
    for (let column = x; column < x + width; column += 1) {
      const index = ((row * image.width) + column) << 2;
      image.data[index] = rgba[0];
      image.data[index + 1] = rgba[1];
      image.data[index + 2] = rgba[2];
      image.data[index + 3] = rgba[3];
    }
  }
}

function writePng(filePath, image) {
  writeFileSync(filePath, PNG.sync.write(image));
}

test('fixture discovery includes both websites and solved corpus directories', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-corpus-discovery-'));
  const websitesDir = path.join(root, 'websites', 'active-site');
  const solvedDir = path.join(root, 'solved', 'solved-site');
  mkdirSync(websitesDir, { recursive: true });
  mkdirSync(solvedDir, { recursive: true });
  writeFileSync(path.join(websitesDir, 'index.html'), '<h1>Active</h1>');
  writeFileSync(path.join(websitesDir, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
  writeFileSync(path.join(solvedDir, 'index.html'), '<h1>Solved</h1>');
  writeFileSync(path.join(solvedDir, 'fixture.json'), JSON.stringify({ fixture_class: 'docs/blog' }));

  const matrix = createFixtureMatrix({ fixture_root: root, id: 'corpus-discovery-test' });

  assert.equal(matrix.fixture_root, root);
  assert.deepEqual(matrix.fixture_directories, ['websites', 'solved']);
  assert.equal(matrix.count, 2);
  const ids = matrix.fixtures.map((fixture) => fixture.id).sort();
  assert.deepEqual(ids, ['active-site', 'solved-site']);
  const solvedFixture = matrix.fixtures.find((fixture) => fixture.id === 'solved-site');
  assert.equal(solvedFixture.fixture_corpus, 'solved');
  assert.equal(solvedFixture.fixture_class, 'docs/blog');
});

test('fixture discovery falls back to single root when no websites subdirectory exists', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-single-root-discovery-'));
  const fixtureDir = path.join(root, 'simple-site');
  mkdirSync(fixtureDir, { recursive: true });
  writeFileSync(path.join(fixtureDir, 'index.html'), '<h1>Simple</h1>');
  writeFileSync(path.join(fixtureDir, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));

  const matrix = createFixtureMatrix({ fixture_root: root, id: 'single-root-discovery-test' });

  assert.equal(matrix.fixture_root, root);
  assert.equal(matrix.fixture_directories, undefined);
  assert.equal(matrix.count, 1);
  assert.equal(matrix.fixtures[0].id, 'simple-site');
  assert.equal(matrix.fixtures[0].fixture_corpus, 'active');
});

test('fixture id filters select an active target plus solved regressions without staging copies', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-id-filter-'));
  for (const [corpus, id] of [['websites', 'target-site'], ['websites', 'other-site'], ['solved', 'solved-site']]) {
    const directory = path.join(root, corpus, id);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), `<h1>${id}</h1>`);
    writeFileSync(path.join(directory, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
  }

  const matrix = createFixtureMatrix({
    fixture_root: root,
    fixture_ids: 'target-site,solved-site',
  });

  assert.deepEqual(matrix.filter.fixture_ids, ['solved-site', 'target-site']);
  assert.deepEqual(matrix.fixtures.map((fixture) => fixture.id), ['solved-site', 'target-site']);
  assert.equal(matrix.fixtures.find((fixture) => fixture.id === 'solved-site').fixture_corpus, 'solved');
});

test('solved fixture that regresses is reported as solved_regression', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'solved-regression-test',
    fixtures: [
      {
        fixture_id: 'cv',
        fixture_corpus: 'solved',
        fixture_path: '/fixtures/solved/cv',
        status: 'passed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/cv/screenshot.png' }],
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0.05 } },
        block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
        editor_quality: { editor_validated_block_total: 8, editor_invalid_count: 0, core_html_block_count: 0 },
      },
    ],
    findings: [
      {
        fixture_id: 'cv',
        kind: 'visual_parity_mismatch',
        loss_class: 'visual_parity_mismatch',
        loss_acceptance: 'unacceptable',
        reason: 'Aligned visual parity mismatch: 5% exceed threshold.',
      },
    ],
  });
  const decision = registry.fixture_decisions.find((row) => row.fixture_id === 'cv');

  assert.equal(decision.fixture_corpus, 'solved');
  assert.equal(decision.acceptance_status, 'solved_regression');
  assert.equal(decision.solved_candidate, false);
  assert.equal(registry.summary.fixture_decision_counts.solved_regression, 1);
  assert.deepEqual(registry.summary.fixture_decision_groups.solved_regression, ['cv']);
});

test('solved fixture that stays solved_candidate keeps solved_candidate status', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'solved-stays-solved-test',
    fixtures: [
      {
        fixture_id: 'cv',
        fixture_corpus: 'solved',
        fixture_path: '/fixtures/solved/cv',
        status: 'passed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/cv/screenshot.png' }],
        editor_presentation: completeEditorPresentation,
        editor_interaction: completeEditorInteraction,
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
        block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
        editor_validation: completeEditorValidation,
        editor_quality: { editor_validated_block_total: 8, editor_invalid_count: 0, core_html_block_count: 0 },
      },
    ],
    findings: [],
  });
  const decision = registry.fixture_decisions.find((row) => row.fixture_id === 'cv');

  assert.equal(decision.fixture_corpus, 'solved');
  assert.equal(decision.acceptance_status, 'solved_candidate');
  assert.equal(decision.solved_candidate, true);
});

test('solved-candidate gate hard-fails regressions while preserving acceptance evidence', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-solved-candidate-gate-'));
  for (const [corpus, id] of [['websites', 'target-site'], ['solved', 'solved-site']]) {
    const directory = path.join(root, corpus, id);
    mkdirSync(directory, { recursive: true });
    writeFileSync(path.join(directory, 'index.html'), `<h1>${id}</h1>`);
    writeFileSync(path.join(directory, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
  }
  const matrix = createFixtureMatrix({ fixture_root: root });
  const acceptedResult = (fixtureId) => ({
    fixture_id: fixtureId,
    status: 'passed',
    artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: `files/browser/editor-open/${fixtureId}/screenshot.png` }],
    editor_presentation: completeEditorPresentation,
    editor_interaction: completeEditorInteraction,
    visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
    block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
    editor_validation: completeEditorValidation,
  });
  const solvedRegression = {
    ...acceptedResult('solved-site'),
    visual_parity_artifacts: { comparison: { mismatch_ratio: 0.05 } },
    diagnostics: [{
      fixture_id: 'solved-site',
      kind: 'visual_parity_mismatch',
      loss_class: 'visual_parity_mismatch',
      loss_acceptance: 'unacceptable',
      reason: 'Exact visual parity regressed.',
    }],
  };

  const result = normalizeFixtureMatrixResult({
    matrix,
    results: [acceptedResult('target-site'), solvedRegression],
    requireSolvedCandidate: true,
  });

  assert.equal(result.summary.succeeded, 1);
  assert.equal(result.summary.failed, 1);
  assert.deepEqual(result.summary.solved_candidate_gate, {
    enabled: true,
    required_status: 'solved_candidate',
    failed_fixture_count: 1,
    failed_fixture_ids: ['solved-site'],
  });
  assert.equal(result.summary.fixture_failure_categories.solved_regression, 1);
  assert.ok(result.summary.gate_failure_reasons.some((reason) => reason.fixture_id === 'solved-site' && reason.category === 'solved_regression'));
  assert.equal(result.gutenberg_incompatibility_registry.fixture_decisions.find((decision) => decision.fixture_id === 'solved-site').acceptance_status, 'solved_regression');
});

test('solved-candidate registry rejects counts-only editor validation and contradictory presentation summaries', () => {
  const base = {
    fixture_id: 'cv',
    status: 'passed',
    artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot' }],
    visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
    block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
    editor_validation: completeEditorValidation,
    editor_presentation: completeEditorPresentation,
    editor_interaction: completeEditorInteraction,
  };
  const countsOnly = buildGutenbergIncompatibilityRegistry({
    fixtures: [{ ...base, editor_validation: { total_blocks: 8, valid_blocks: 8, invalid_blocks: 0 } }], findings: [],
  }).fixture_decisions[0];
  const contradictoryPresentation = buildGutenbergIncompatibilityRegistry({
    fixtures: [{ ...base, editor_presentation: { ...completeEditorPresentation, observed_identities: [], observed_identity_count: 0 } }], findings: [],
  }).fixture_decisions[0];

  assert.equal(countsOnly.acceptance_status, 'evidence_gap');
  assert.equal(contradictoryPresentation.acceptance_status, 'evidence_gap');
});

test('solved-candidate registry rejects legacy stylesheet-only presentation evidence', () => {
  const identity = 'e'.repeat(64);
  const fixture = {
    fixture_id: 'legacy',
    status: 'passed',
    artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot' }],
    visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
    block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
    editor_validation: completeEditorValidation,
    editor_presentation: {
      ...completeEditorPresentation,
      schema: 'static-site-importer/editor-presentation-evidence/v1',
      expected_identities: [identity],
      observed_identities: [identity],
    },
    editor_interaction: completeEditorInteraction,
    import_report: { blocks_engine: { wordpress_site_plan: { asset_count: 1, assets: [{ kind: 'css', content_hash: identity, scopes: [{ kind: 'global' }] }] } } },
  };
  const complete = buildGutenbergIncompatibilityRegistry({ fixtures: [fixture], findings: [] }).fixture_decisions[0];
  const ambiguous = buildGutenbergIncompatibilityRegistry({
    fixtures: [{ ...fixture, import_report: { blocks_engine: { wordpress_site_plan: { asset_count: 2, assets: fixture.import_report.blocks_engine.wordpress_site_plan.assets } } } }], findings: [],
  }).fixture_decisions[0];

  assert.equal(complete.acceptance_status, 'evidence_gap');
  assert.equal(ambiguous.acceptance_status, 'evidence_gap');
});
