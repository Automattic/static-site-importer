/**
 * External dependencies
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { PNG } from 'pngjs';

/**
 * Internal dependencies
 */
import runFixtureMatrixBench, {
  boundedConcurrency,
  composerPathRepositoryConfig,
  fixtureMatrixBatchRunSummary,
  mapWithConcurrency,
  materializeVisualCompareArtifacts,
  resolveBlocksEnginePhpTransformerPath,
  runFixtureMatrix,
} from '../bench/static-site-fixture-matrix.bench.mjs';
import {
  buildCodeFreshness,
  buildFixtureMatrixRunPlan,
  CANONICAL_FIXTURE_COUNT,
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
  VISUAL_PARITY_DETERMINISTIC_CSS,
  VISUAL_PARITY_MISMATCH_KIND,
  visualParityCompareStep,
  wordpressServedPath,
  writeFixtureMatrixArtifacts,
} from '../lib/fixture-matrix.mjs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import { runWpCodeboxRecipe, wpCodeboxBin } from './wp-codebox/recipe.mjs';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const fixtureRoot = path.join(packageRoot, 'tests', 'fixtures', 'fixture-matrix');

test('discovers SSI fixtures and writes Blocks Engine site artifacts', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-matrix-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'test-matrix' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix });
  const artifact = JSON.parse(readFileSync(path.join(outputDirectory, 'simple-site', 'artifact.json'), 'utf8'));

  assert.equal(matrix.schema, 'static-site-importer/fixture-matrix/v1');
  assert.equal(matrix.count, 1);
  assert.equal(matrix.fixtures[0].id, 'simple-site');
  assert.equal(artifact.schema, 'blocks-engine/php-transformer/site-artifact/v1');
  // Files are base64-encoded exactly like the product's `import-theme` CLI, so
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

test('gutenberg incompatibility registry separates fixture decision axes', () => {
  const registry = buildGutenbergIncompatibilityRegistry({
    matrix_id: 'decision-axis-map',
    fixtures: [
      {
        fixture_id: 'cv',
        status: 'passed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/cv/screenshot.png' }],
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
        block_composition: { block_total: 8, native_block_count: 8, core_html_block_count: 0 },
        editor_quality: { editor_validated_block_total: 8, editor_invalid_count: 0, core_html_block_count: 0 },
      },
      {
        fixture_id: 'artist',
        status: 'failed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/artist/screenshot.png' }],
        block_composition: { block_total: 10, native_block_count: 9, core_html_block_count: 1 },
        editor_quality: { editor_validated_block_total: 10, editor_invalid_count: 0, core_html_block_count: 1 },
        visual_diff_regions: [{ dominant_cause: 'position_offset', pixel_count: 2500 }],
      },
      {
        fixture_id: 'coffee',
        status: 'failed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/coffee/screenshot.png' }],
        editor_quality: { editor_validated_block_total: 12, editor_invalid_count: 0, core_html_block_count: 0 },
        visual_diff_regions: [{ dominant_cause: 'font_metric_drift', pixel_count: 900 }],
      },
      {
        fixture_id: 'saas',
        status: 'failed',
        artifact_refs: [{ artifact_id: 'editor-open-screenshot', kind: 'screenshot', path: 'files/browser/editor-open/saas/screenshot.png' }],
        visual_parity_artifacts: { comparison: { mismatch_ratio: 0 } },
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
  assert.equal(decisions.cv.block_validity_status, 'valid');
  assert.equal(decisions.cv.editor_validity_status, 'valid');
  assert.equal(decisions.cv.native_editability_status, 'native_editable');
  assert.equal(decisions.cv.solved_candidate, true);
  assert.equal(decisions.cv.acceptance_status, 'solved_candidate');
  assert.equal(decisions.cv.solved_candidate_reason, 'passed frontend visual parity, editor canvas evidence, block validity, and native editability without limitation patterns');
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
  assert.equal(decisions['cv-missing-editor-evidence'].native_editability_status, 'native_editable');
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
  // with the SAME `content_base64` encoding the real SSI `import-theme` CLI
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
  assert.match(recipe.workflow.steps[1].args[0], /static-site-importer validate-artifact/);
  assert.match(recipe.workflow.steps[1].args[0], /--allow-failure/);
  assert.doesNotMatch(recipe.workflow.steps[1].args[0], /--allow-missing-woocommerce/);
  assert.deepEqual(recipe.inputs.stagedFiles[0], {
    source: '/tmp/artifacts/simple-site/artifact.json',
    target: '/wordpress/wp-content/uploads/static-site-importer-fixture-matrix/simple-site/artifact.json',
  });
  assert.deepEqual(recipe.inputs.mounts, []);
});

test('fixture capability manifests drive per-fixture plugin provisioning without waivers', () => {
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

  assert.deepEqual(fixtureSteps('plain-site').map((step) => step.command), ['wordpress.wp-cli']);
  assert.deepEqual(fixtureSteps('shop-site').map((step) => step.command), ['wordpress.plugin-setup', 'wordpress.wp-cli']);
  assert.deepEqual(fixtureSteps('shop-forms-site').map((step) => step.command), ['wordpress.plugin-setup', 'wordpress.plugin-setup', 'wordpress.wp-cli']);
  assert.deepEqual(fixtureSteps('shop-site')[0].args, ['action=install', 'plugin=woocommerce', 'activate=true']);
  assert.deepEqual(fixtureSteps('shop-forms-site')[0].args, ['action=install', 'plugin=woocommerce', 'activate=true']);
  assert.deepEqual(fixtureSteps('shop-forms-site')[1].args, ['action=install', 'plugin=jetpack', 'activate=true']);
  assert.equal(fixtureSteps('shop-site')[0].allowFailure, true);
  assert.equal(recipe.workflow.steps.some((step) => /--allow-missing-woocommerce/.test(step.args?.[0] || '')), false);
});

test('fixture-matrix rig requires env-backed WP Codebox editor and visual capabilities', () => {
  const rig = JSON.parse(readFileSync(path.join(packageRoot, 'rigs', 'static-site-importer-fixture-matrix', 'rig.json'), 'utf8'));
  const tool = rig.requirements.runner_tools.find((item) => item.tool === 'wp-codebox');

  assert.ok(tool, 'expected a wp-codebox runner tool requirement');
  assert.equal(tool.command, 'wp-codebox');
  assert.deepEqual(tool.env, ['HOMEBOY_WP_CODEBOX_BIN']);
  assert.ok(tool.capabilities.includes('wordpress.editor-open'));
  assert.ok(tool.capabilities.includes('wordpress.editor-validate-blocks'));
  assert.ok(tool.capabilities.includes('wordpress.visual-compare'));
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

  const recipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    dependencyOverrides: {
      blocks_engine_php_transformer: {
        package: 'automattic/blocks-engine-php-transformer',
        path: transformerPath,
      },
    },
  });

  assert.deepEqual(recipe.inputs.dependency_overlays[0], {
    kind: 'composer-package',
    package: 'automattic/blocks-engine-php-transformer',
    consumer: 'static-site-importer',
    source: transformerPath,
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

test('falls back to unknown with a loud warning when the manifest is missing or invalid', () => {
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

  // No heuristic guessing — every manifest-less/invalid fixture is unknown.
  assert.equal(byId.get('no-manifest').fixture_class, 'unknown');
  assert.deepEqual(byId.get('no-manifest').taxonomy.signals, ['manifest_missing']);
  assert.equal(byId.get('bad-class').fixture_class, 'unknown');
  assert.deepEqual(byId.get('bad-class').taxonomy.signals, ['manifest_invalid_class']);
  assert.equal(byId.get('broken-json').fixture_class, 'unknown');
  assert.equal(matrix.manifest_coverage.gate.status, 'warning');
  assert.equal(matrix.manifest_coverage.unknown_fixture_class_count, 3);
  assert.equal(matrix.manifest_coverage.missing_manifest_count, 2);
  assert.equal(matrix.manifest_coverage.invalid_class_count, 1);
  assert.deepEqual(matrix.manifest_coverage.unknown_fixture_ids, ['bad-class', 'broken-json', 'no-manifest']);

  // A clear, loud warning naming each offending fixture was emitted.
  const warningText = warnings.join('');
  assert.match(warningText, /WARNING:.*no-manifest.*no fixture\.json/s);
  assert.match(warningText, /WARNING:.*bad-class.*invalid class "totally-made-up"/s);
  assert.match(warningText, /Failed to parse.*broken-json/s);
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

test('fixture matrix intake preserves editor-open canvas evidence for acceptance decisions', () => {
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
        editor_canvas: {
          selector_summary: {
            groups: [{ name: 'block-list', selector: '.block-editor-block-list__layout', count: 4 }],
          },
        },
        editor_open: {
          artifacts: {
            screenshot: 'files/browser/editor-open/simple-site/screenshot.png',
          },
        },
      },
    ],
  };

  const result = collectFixtureMatrixRunResults({ matrix, outputDirectory, codeboxOutput });
  const registry = buildGutenbergIncompatibilityRegistry(result);
  const decision = registry.fixture_decisions.find((row) => row.fixture_id === 'simple-site');

  assert.equal(result.fixtures[0].editor_canvas.selector_summary.groups[0].count, 4);
  assert.equal(result.fixtures[0].editor_open.artifacts.screenshot, 'files/browser/editor-open/simple-site/screenshot.png');
  assert.equal(decision.editor_canvas_status, 'visible');
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

test('materializes generated artifact roots into matrix-compatible fixtures', () => {
  const sourceRoot = mkdtempSync(path.join(tmpdir(), 'ssi-generated-artifacts-'));
  const fixtureOutput = mkdtempSync(path.join(tmpdir(), 'ssi-generated-fixtures-'));
  mkdirSync(path.join(sourceRoot, 'static-sites', 'alpha', 'assets'), { recursive: true });
  writeFileSync(path.join(sourceRoot, 'static-sites', 'alpha', 'index.html'), '<h1>Alpha</h1>');
  writeFileSync(path.join(sourceRoot, 'static-sites', 'alpha', 'assets', 'style.css'), 'body { color: black; }');
  mkdirSync(path.join(sourceRoot, 'artifact-candidate'), { recursive: true });
  writeFileSync(path.join(sourceRoot, 'artifact-candidate', 'artifact.json'), JSON.stringify({
    schema: 'blocks-engine/php-transformer/site-artifact/v1',
    metadata: { site: 'Beta Site' },
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

test('builds one-command canonical Blocks Engine fixture matrix plan', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-canonical-matrix-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const blocksEngine = path.join(root, 'blocks-engine');
  const canonicalFixtureRoot = path.join(blocksEngine, 'fixtures', 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (let index = 1; index <= CANONICAL_FIXTURE_COUNT; index += 1) {
    mkdirSync(path.join(canonicalFixtureRoot, `fixture-${String(index).padStart(2, '0')}`), { recursive: true });
  }

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
  assert.equal(plan.fixture_root, canonicalFixtureRoot);
  assert.equal(plan.fixture_count, CANONICAL_FIXTURE_COUNT);
  assert.equal(plan.fixture_count_matches_canonical, true);
  assert.equal(plan.namespace, 'ssi-matrix-dev-proof');
  assert.equal(plan.temp_root, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof');
  assert.equal(plan.output_file, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof/ssi-matrix-dev-proof.homeboy-bench.json');
  assert.equal(plan.shared_state, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof/shared-state');
  assert.equal(plan.artifact_root, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof/artifacts');
  assert.deepEqual(plan.warnings, []);
  assert.equal(plan.dependency_overrides.blocks_engine_php_transformer.path, blocksEngine);
  assert.equal(plan.steps.some((step) => step.args.includes('install')), false);
  assert.ok(plan.steps.some((step) => step.args.includes('sync')));

  const benchStep = plan.steps.at(-1);
  assert.deepEqual(benchStep.args.slice(0, 7), ['bench', '--rig', 'static-site-importer-fixture-matrix', '--profile', 'fixture-matrix', '--iterations', '1']);
  assert.equal(benchStep.command, '/tmp/homeboy-latest');
  assert.ok(benchStep.args.includes('--runner'));
  assert.ok(benchStep.args.includes('homeboy-lab'));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT=${canonicalFixtureRoot}`));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH=${staticSiteImporter}`));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH=${blocksEngine}`));
  assert.ok(benchStep.args.includes('bench_env.SSI_FIXTURE_MATRIX_RUN=1'));
  assert.ok(benchStep.args.includes('bench_env.SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE=1'));
  assert.ok(benchStep.args.includes('static_site_importer_fixture_matrix_namespace=ssi-matrix-dev-proof'));
  assert.ok(benchStep.args.includes('/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof/artifacts'));
  assert.deepEqual(benchStep.args.slice(-3), ['--', '--batch-size', '5']);

  const releasePlan = buildFixtureMatrixRunPlan({
    mode: 'release-proof',
    staticSiteImporter,
    blocksEngine,
    passthrough: [],
  });
  assert.deepEqual(releasePlan.dependency_overrides, {});
  assert.equal(releasePlan.steps.at(-1).args.some((arg) => arg.includes('SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH')), false);

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
  assert.equal(surfacePlan.surface_coverage.max_browser_surface_count, CANONICAL_FIXTURE_COUNT * (MAX_EXTRA_SURFACE_COUNT + 1));
  assert.ok(surfacePlan.warnings.some((warning) => warning.code === 'surface_coverage_runtime_cost'));
  assert.ok(surfacePlan.steps.at(-1).args.includes('bench_env.SSI_FIXTURE_MATRIX_SURFACE_COVERAGE=99'));

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
  }), /--local forces hot execution on this machine and cannot be combined with --runner homeboy-lab/);

  assert.throws(() => buildFixtureMatrixRunPlan({
    local: true,
    labOnly: true,
    staticSiteImporter,
    fixtureRoot,
  }), /--local forces hot execution on this machine and cannot be combined with --lab-only/);
});

test('fixture matrix operator composes legible local-hot and Lab offload routing', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-routing-plan-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

  const labPlan = buildFixtureMatrixRunPlan({
    runner: 'homeboy-lab',
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  const labArgs = labPlan.steps.at(-1).args;
  assert.equal(labPlan.execution_target, 'lab-offload:homeboy-lab');
  assert.match(labPlan.steps.at(-1).label, /lab-offload:homeboy-lab/);
  assert.ok(labArgs.includes('--runner'));
  assert.ok(labArgs.includes('homeboy-lab'));
  assert.equal(labArgs.includes('--force-hot'), false);
  assert.equal(labArgs.includes('--allow-local-hot'), false);

  const localPlan = buildFixtureMatrixRunPlan({
    local: true,
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  const localArgs = localPlan.steps.at(-1).args;
  assert.equal(localPlan.execution_target, 'local-hot');
  assert.match(localPlan.steps.at(-1).label, /local-hot/);
  assert.ok(localArgs.includes('--force-hot'));
  assert.ok(localArgs.includes('--allow-local-hot'));
  assert.equal(localArgs.includes('--runner'), false);

  const runnerLocalPlan = buildFixtureMatrixRunPlan({
    runner: 'local',
    staticSiteImporter,
    fixtureRoot,
    skipInstall: true,
    skipSync: true,
  });
  const runnerLocalArgs = runnerLocalPlan.steps.at(-1).args;
  assert.equal(runnerLocalPlan.execution_target, 'local-hot');
  assert.equal(runnerLocalPlan.runner, '');
  assert.equal(runnerLocalPlan.local, true);
  assert.ok(runnerLocalArgs.includes('--force-hot'));
  assert.ok(runnerLocalArgs.includes('--allow-local-hot'));
  assert.equal(runnerLocalArgs.includes('--runner'), false);
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
async function runWpCodeboxRecipe() {
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
    const expectedCodeboxArtifactsDirectory = path.join(root, 'artifacts-wp-codebox-batch-001-artifacts');
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
      `${process.env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY}-wp-codebox-batch-001-artifacts`,
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
import { writeFileSync } from 'node:fs';
const outputIndex = process.argv.indexOf('--output');
const outputFile = outputIndex >= 0 ? process.argv[outputIndex + 1] : '';
const fixtureId = process.env.SSI_TEST_FAKE_WP_CODEBOX_FIXTURE_ID || 'large-output-fixture';
if (outputFile) {
  writeFileSync(outputFile, JSON.stringify({ results: [{ fixture_id: fixtureId, status: 'succeeded' }] }));
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
  assert.deepEqual(JSON.parse(readFileSync(path.join(outputDirectory, 'wp-codebox-output-batch-001.json'), 'utf8')), {
    results: [{ fixture_id: fixtureId, status: 'succeeded' }],
  });
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
  assert.ok(editorOpenSteps[1].args.includes('url=/contact/'));
  assert.ok(editorOpenSteps[2].args.includes('url=/merch/'));
  assert.equal(visualCompareMatrixComparison(visualSteps[2]).candidateUrl, '/merch/');
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
    });

    assert.equal(runtimeError, null);
    const batchRecipe = JSON.parse(readFileSync(summary.runtime.batches[0].recipe_file, 'utf8'));
    const editorOpenSteps = batchRecipe.workflow.steps.filter((step) => step.command === 'wordpress.editor-open');
    const visualSteps = batchRecipe.workflow.steps.filter((step) => step.command === 'wordpress.visual-compare');
    assert.equal(editorOpenSteps.length, 3);
    assert.equal(visualSteps.length, 3);
    assert.ok(editorOpenSteps[1].args.includes('url=/contact/'));
    assert.ok(editorOpenSteps[2].args.includes('url=/merch/'));
    assert.equal(visualCompareMatrixComparison(visualSteps[1]).candidateUrl, '/contact/');
    assert.equal(visualCompareMatrixComparison(visualSteps[2]).candidateUrl, '/merch/');
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

  const freshPlan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    blocksEngine,
    runId: 'ssi-freshness-fresh',
    skipInstall: true,
    skipSync: true,
    gitRunner: fakeGitRunner({
      [path.resolve(blocksEngine)]: { branch: 'trunk', upstream: 'origin/trunk', behind: 0, ahead: 2, commit: 'aheadcommit' },
      [path.resolve(staticSiteImporter)]: { branch: 'main', upstream: 'origin/main', behind: 0, ahead: 0, commit: 'freshcommit' },
    }),
  });

  assert.equal(freshPlan.code_freshness.would_block, false);
  assert.deepEqual(freshPlan.code_freshness.stale_overrides, []);
  assert.equal(freshPlan.code_freshness.paths.blocks_engine_php_transformer_path.status, 'ahead');
  assert.equal(freshPlan.warnings.some((warning) => warning.code === 'stale_override'), false);

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
  // The single-fixture temp corpus drifts from the canonical pin, so the plan
  // surfaces a non-silent drift warning alongside the routing warnings.
  assert.deepEqual(plan.warnings.map((warning) => warning.code), [
    'lab_auto_offload_risk',
    'local_fallback_allowed',
    'dirty_lab_workspace_allowed',
    'canonical_fixture_count_drift',
  ]);
  assert.equal(plan.fixture_count_matches_canonical, false);
  assert.match(
    plan.warnings.find((warning) => warning.code === 'canonical_fixture_count_drift').message,
    /CANONICAL_FIXTURE_COUNT is \d+/,
  );
});

test('--local forces hot local execution and suppresses the auto-offload-risk warning', () => {
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

  // The auto-offload risk warning is gone; the forced-local note replaces it.
  const codes = plan.warnings.map((warning) => warning.code);
  assert.ok(!codes.includes('lab_auto_offload_risk'));
  assert.ok(codes.includes('forced_local_execution'));
  assert.equal(plan.local, true);

  // The bench step carries --force-hot --allow-local-hot so homeboy bench stays
  // local instead of offloading local-only paths to a connected Lab runner.
  const benchStep = plan.steps.at(-1);
  assert.ok(benchStep.args.includes('--force-hot'));
  assert.ok(benchStep.args.includes('--allow-local-hot'));
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
      plan: { mode: 'development-override', run_id: 'planned-run', output_file: missingOutput },
      benchStatus: 1,
      benchLabel: 'Run SSI fixture matrix bench',
    }),
    /Run SSI fixture matrix bench failed with exit 1/,
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

  // [activate, validate(simple-site), editor-open(simple-site), editor-validate-blocks(simple-site)]
  assert.equal(recipe.workflow.steps[1].command, 'wordpress.wp-cli');
  assert.match(recipe.workflow.steps[1].args[0], /static-site-importer validate-artifact/);
  const editorOpenStep = recipe.workflow.steps[2];
  assert.equal(editorOpenStep.command, 'wordpress.editor-open');
  assert.ok(editorOpenStep.args.includes('target=front-page'));
  assert.ok(editorOpenStep.args.includes('capture=screenshot,editor-state,editor-validity'));
  assert.ok(editorOpenStep.args.includes('artifact-prefix=files/browser/editor-open/simple-site'));
  const editorStep = recipe.workflow.steps[3];
  assert.equal(editorStep.command, EDITOR_VALIDATE_BLOCKS_COMMAND);
  assert.equal(editorStep.command, 'wordpress.editor-validate-blocks');
  assert.equal(editorStep.args.some((arg) => arg.includes('post-new.php')), false);
  assert.equal(editorStep.args.some((arg) => arg.startsWith('post-type=')), false);
  assert.ok(editorStep.args.includes('target=front-page'));
  assert.equal(editorStep.args.some((arg) => arg.startsWith('capture=')), false);
  assert.equal(editorStep.allowFailure, true);

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
  writeFileSync(path.join(fixtureDirectory, 'contact.html'), '<main><form><input name="email"></form></main>');
  writeFileSync(path.join(fixtureDirectory, 'merch', 'index.html'), '<main><button>Add to cart</button></main>');

  const discoveredMatrix = createFixtureMatrix({ fixture_root: root, id: 'surface-recipe-test' });
  const matrix = { ...discoveredMatrix, fixtures: discoveredMatrix.fixtures.filter((fixture) => fixture.id === 'artist'), count: 1 };
  assert.deepEqual(selectFixtureSurfaces(matrix.fixtures[0]).map((surface) => surface.id), ['front-page']);
  assert.deepEqual(selectFixtureSurfaces(matrix.fixtures[0], { surfaceCoverage: { maxExtraSurfaces: 1 } }).map((surface) => surface.id), ['front-page', 'about']);
  assert.deepEqual(selectFixtureSurfaces(matrix.fixtures[0], { surfaceCoverage: 99 }).map((surface) => surface.id), ['front-page', 'about', 'about--2', 'contact', 'merch']);
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
    surfaceCoverage: { maxExtraSurfaces: 2 },
  });
  const editorOpenSteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.command === 'wordpress.editor-open');
  const editorValidationSteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND);
  const visualSteps = multiSurfaceRecipe.workflow.steps.filter((step) => step.command === 'wordpress.visual-compare');

  assert.equal(editorOpenSteps.length, 3);
  assert.equal(editorValidationSteps.length, 3);
  assert.equal(visualSteps.length, 3);
  assert.ok(editorOpenSteps[0].args.includes('artifact-prefix=files/browser/editor-open/artist'));
  assert.ok(editorOpenSteps[1].args.includes('url=/about/'));
  assert.ok(editorOpenSteps[1].args.includes('artifact-prefix=files/browser/editor-open/artist/about'));
  assert.ok(editorOpenSteps[2].args.includes('url=/about/'));
  assert.ok(editorOpenSteps[2].args.includes('artifact-prefix=files/browser/editor-open/artist/about--2'));
  assert.ok(editorValidationSteps[1].args.includes('url=/about/'));

  const aboutComparison = visualCompareMatrixComparison(visualSteps[1]);
  assert.equal(aboutComparison.name, 'artist--about');
  assert.equal(aboutComparison.sourceUrl, 'file:///tmp/artifacts/artist/source/about.html');
  assert.equal(aboutComparison.candidateUrl, '/about/');
  const nestedAboutComparison = visualCompareMatrixComparison(visualSteps[2]);
  assert.equal(nestedAboutComparison.name, 'artist--about--2');
  assert.equal(nestedAboutComparison.sourceUrl, 'file:///tmp/artifacts/artist/source/about/index.html');
  assert.equal(nestedAboutComparison.candidateUrl, '/about/');
  assert.equal(multiSurfaceRecipe.metadata.surface_coverage.extra_surfaces_per_fixture, 2);
  assert.equal(multiSurfaceRecipe.metadata.surface_coverage.total_surface_count, 3);
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
  assert.equal(finding.candidate_repo, 'blocks-engine');
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
  schema: 'wp-codebox/editor-validate-blocks/v1',
  validation_method: 'wp.blocks.validateBlock',
  validation_provider: 'wordpress-block-editor',
  total_blocks: 3,
  valid_blocks: 3,
  invalid_blocks: 0,
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
  assert.equal(metrics.total_blocks, 3);
  assert.equal(metrics.valid_blocks, 3);
  assert.equal(metrics.invalid_blocks, 0);
  assert.equal(collectEditorValidation({ unrelated: true }), null);
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
  });

  // [activate, validate(simple-site), editor-open(simple-site), editor-validation(simple-site), visual-setup(simple-site), visual-compare(simple-site)]
  const visualSetupStep = recipe.workflow.steps[4];
  assert.equal(visualSetupStep.command, 'wordpress.wp-cli');
  assert.equal(visualSetupStep.metadata.phase, 'visual-setup');
  assert.match(visualSetupStep.args[0], /wp_update_custom_css_post/);
  const visualStep = recipe.workflow.steps[5];
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
  assert.equal(visualCompareMatrixComparison(defaultThresholdVisualStep).threshold, 0, 'visual parity defaults to exact pixel parity');

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
    pixelThreshold: 0.2,
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

test('default visual-parity source-url targets the staged source/ subdir as a file URL', () => {
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
    'file:///tmp/artifacts/simple-site/source/index.html',
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

test('stageFixtureSource copies the raw fixture source into the served source/ subdir', () => {
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
  // The import payload (artifact.json) is still written alongside, unchanged.
  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'artifact.json')), 'artifact.json should still be written');
  assert.equal(written.metadata.source_staging.status, 'staged');
  assert.ok(written.metadata.artifact_bytes.staged_source > 0);
  assert.ok(Number.isFinite(written.metadata.performance.artifact_writing_ms));
});

test('writeFixtureMatrixArtifacts skips raw source staging when visual evidence is disabled', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-no-visual-source-skip-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'no-visual-source-skip-test' });
  const written = writeFixtureMatrixArtifacts({ outputDirectory, matrix, visualParity: false, liveWpParity: false });

  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'artifact.json')), 'artifact.json should still be written');
  assert.equal(existsSync(path.join(outputDirectory, 'simple-site', 'source', 'index.html')), false);
  assert.equal(written.metadata.source_staging.status, 'skipped');
  assert.equal(written.metadata.source_staging.reason, 'visual_and_live_wp_parity_disabled');
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
  assert.equal(finding.candidate_repo, 'blocks-engine');
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

test('visual-compare PNGs are copied to the bench artifact root and registered', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-persisted-output-'));
  const codeboxArtifactsDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-codebox-artifacts-'));
  const runtimeDirectory = path.join(codeboxArtifactsDirectory, 'runtime-123', 'files', 'browser', 'visual-compare', 'simple-site');
  mkdirSync(runtimeDirectory, { recursive: true });
  for (const fileName of ['source.png', 'candidate.png', 'diff.png']) {
    writeFileSync(path.join(runtimeDirectory, fileName), `fake ${fileName}`);
  }

  const result = {
    fixtures: [
      {
        fixture_id: 'simple-site',
        diagnostics: [
          {
            kind: VISUAL_PARITY_MISMATCH_KIND,
            artifact_refs: [
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'source_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site/source.png' },
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'candidate_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site/candidate.png' },
              { schema: 'homeboy/artifact-ref/v1', artifact_id: 'diff_screenshot', kind: 'visual-parity', path: 'files/browser/visual-compare/simple-site/diff.png' },
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
          },
        },
      },
    ],
  };

  const persisted = materializeVisualCompareArtifacts({ result, outputDirectory, codeboxArtifactsDirectory });
  const fixture = persisted.result.fixtures[0];

  assert.deepEqual(Object.keys(persisted.artifacts).sort(), [
    'visual_compare_simple-site_candidate',
    'visual_compare_simple-site_diff',
    'visual_compare_simple-site_source',
  ]);
  for (const [key, artifact] of Object.entries(persisted.artifacts)) {
    assert.ok(existsSync(artifact.path), `${key} should point at a copied PNG`);
    assert.equal(artifact.path.includes('homeboy-run-'), false, 'persisted artifact must not live in a transient Homeboy runtime dir');
    assert.ok(artifact.path.startsWith(path.join(outputDirectory, 'visual-compare', 'simple-site')));
  }
  assert.equal(readFileSync(path.join(outputDirectory, 'visual-compare', 'simple-site', 'source.png'), 'utf8'), 'fake source.png');
  assert.equal(fixture.visual_parity_artifacts.owner, 'bench_artifact_root');
  assert.equal(fixture.visual_parity_artifacts.artifacts.source_screenshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'source.png'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.imported_screenshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'candidate.png'));
  assert.equal(fixture.visual_parity_artifacts.artifacts.diff_screenshot.ref.path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'diff.png'));
  assert.equal(fixture.diagnostics[0].artifact_refs.find((ref) => ref.artifact_id === 'diff_screenshot').path, path.join(outputDirectory, 'visual-compare', 'simple-site', 'diff.png'));
});

test('visual-compare dimension mismatch gates even with zero pixel metrics when gating is on', () => {
  const payload = { comparison: { mismatchPixels: 0, totalPixels: 0, dimensionMismatch: true } };
  const diagnostics = collectVisualParityDiagnostics(payload, { gate: true });
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].dimension_mismatch, true);
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
  assert.equal(fixture.visual_parity_artifacts.visual_explanation.selector_diagnostics[0].selector, '.hero');
  assert.equal(fixture.visual_parity_artifacts.visual_explanation.layout_diagnostics[0].selector, '.hero');
  assert.ok(finding, 'expected a visual parity finding from WP Codebox browserEvidence');
  assert.equal(finding.visual_selector_diagnostics[0].selector, '.hero');
  assert.equal(finding.visual_layout_diagnostics[0].message, 'hero moved down');
  assert.equal(finding.visual_capture_diagnostics[0].phase, 'candidate');
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
  const recipeBaseline = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi' });
  const recipeOff = buildFixtureMatrixRecipe({ matrix, staticSiteImporterPath: '/tmp/ssi', liveWpParity: false });
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
