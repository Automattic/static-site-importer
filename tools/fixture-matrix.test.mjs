import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import runFixtureMatrixBench, {
  boundedConcurrency,
  composerPathRepositoryConfig,
  fixtureMatrixBatchRunSummary,
  mapWithConcurrency,
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
  liveWpParityCaptureStep,
  liveWpParityEnabled,
  runLiveWpParity,
  normalizeLiveWpParityReport,
  buildFixtureArtifact,
  createFixtureMatrix,
  editorBlockValidationStep,
  EDITOR_INVALID_BLOCK_SELECTOR_GROUP,
  EDITOR_VALIDATE_BLOCKS_COMMAND,
  EDITOR_VALIDATION_METHOD,
  normalizeFixtureMatrixResult,
  normalizeLossClass,
  stageFixtureSource,
  VISUAL_PARITY_MISMATCH_KIND,
  visualParityCompareStep,
  wordpressServedPath,
  writeFixtureMatrixArtifacts,
} from '../lib/fixture-matrix.mjs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import { wpCodeboxBin } from './wp-codebox/recipe.mjs';

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
});

test('fixture-matrix rig requires env-backed WP Codebox editor and visual capabilities', () => {
  const rig = JSON.parse(readFileSync(path.join(packageRoot, 'rigs', 'static-site-importer-fixture-matrix', 'rig.json'), 'utf8'));
  const tool = rig.requirements.runner_tools.find((item) => item.tool === 'wp-codebox');

  assert.ok(tool, 'expected a wp-codebox runner tool requirement');
  assert.equal(tool.command, 'wp-codebox');
  assert.deepEqual(tool.env, ['HOMEBOY_WP_CODEBOX_BIN']);
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
  assert.equal(recipe.metadata, undefined);
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
  writeFileSync(path.join(shop, 'fixture.json'), JSON.stringify({ class: 'ecommerce/catalog', tags: ['Shop', 'has-cart'], complexity: 3 }));
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
  assert.equal(shopFixture.complexity, 3);
  // Complexity is clamped into the documented 1-5 range.
  assert.equal(shaderFixture.complexity, 5);
  assert.deepEqual(shaderFixture.tags, []);

  // An explicit class injected by tests/runner/result-merge still takes precedence.
  assert.equal(classifyFixture({ fixture_class: 'docs/blog', directory: shop }).fixture_class, 'docs/blog');
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

  // A clear, loud warning naming each offending fixture was emitted.
  const warningText = warnings.join('');
  assert.match(warningText, /WARNING:.*no-manifest.*no fixture\.json/s);
  assert.match(warningText, /WARNING:.*bad-class.*invalid class "totally-made-up"/s);
  assert.match(warningText, /Failed to parse.*broken-json/s);
});

test('filters the matrix by manifest class and tag lane', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-filter-'));
  const cases = [
    ['landing', { class: 'marketing/static', tags: ['restaurant', 'has-form'] }],
    ['brochure', { class: 'marketing/static', tags: ['agency'] }],
    ['storefront', { class: 'ecommerce/catalog', tags: ['restaurant'] }],
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
});

test('rolls fixture matrix summaries up by fixture class and repair bucket', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-fixture-class-rollups-'));
  const shop = path.join(root, 'shop-catalog');
  const docs = path.join(root, 'docs-blog');
  mkdirSync(shop, { recursive: true });
  mkdirSync(docs, { recursive: true });
  writeFileSync(path.join(shop, 'index.html'), '<h1>Shop</h1>');
  writeFileSync(path.join(shop, 'fixture.json'), JSON.stringify({ class: 'ecommerce/catalog' }));
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
  assert.equal(result.summary.fixture_exemplars[0].fixture_id, 'simple-site');
  assert.equal(result.summary.fixture_exemplars[0].source_snippet, '<canvas id="hero"></canvas>');
  assert.equal(result.fanout_groups[0].count, 1);
  assert.ok(result.summary.diagnostic_blind_spots.some((spot) => spot.kind === 'generic_finding_family'));
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
  const packageRoot = path.join(repoRoot, 'php-transformer');
  mkdirSync(packageRoot, { recursive: true });
  writeFileSync(path.join(packageRoot, 'composer.json'), JSON.stringify({
    name: 'automattic/blocks-engine-php-transformer',
  }));

  assert.equal(resolveBlocksEnginePhpTransformerPath(repoRoot), packageRoot);
  assert.equal(resolveBlocksEnginePhpTransformerPath(packageRoot), packageRoot);
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
  const fixtureRoot = path.join(blocksEngine, 'fixtures', 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (let index = 1; index <= CANONICAL_FIXTURE_COUNT; index += 1) {
    mkdirSync(path.join(fixtureRoot, `fixture-${String(index).padStart(2, '0')}`), { recursive: true });
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
  assert.equal(plan.fixture_root, fixtureRoot);
  assert.equal(plan.fixture_count, CANONICAL_FIXTURE_COUNT);
  assert.equal(plan.fixture_count_matches_canonical, true);
  assert.equal(plan.namespace, 'ssi-matrix-dev-proof');
  assert.equal(plan.temp_root, '/tmp/static-site-importer-fixture-matrix-ssi-matrix-dev-proof');
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
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT=${fixtureRoot}`));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH=${staticSiteImporter}`));
  assert.ok(benchStep.args.includes(`bench_env.SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH=${blocksEngine}`));
  assert.ok(benchStep.args.includes('bench_env.SSI_FIXTURE_MATRIX_RUN=1'));
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
});

test('fixture matrix records generic child command failures for failed WP Codebox batches', async () => {
  const root = mkdtempSync(path.join(tmpdir(), 'ssi-codebox-failure-'));
  const staticSiteImporter = path.join(root, 'static-site-importer');
  const fixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const helperPath = path.join(root, 'wp-codebox-recipe-helper.cjs');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'failing-fixture'), { recursive: true });
  writeFileSync(path.join(fixtureRoot, 'failing-fixture', 'index.html'), '<h1>Failing fixture</h1>');
  writeFileSync(helperPath, `
function wpCodeboxBin() { return '/tmp/wp-codebox'; }
function wpCodeboxCommand(bin) { return { command: bin, args: [] }; }
async function runWpCodeboxRecipe() {
  const error = new Error('recipe-run failed');
  error.code = 17;
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
      fixtureRoot,
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
    assert.equal(failure.batch_id, 'batch-001');
    const expectedCodeboxArtifactsDirectory = path.join(root, 'artifacts-wp-codebox-batch-001-artifacts');
    assert.deepEqual(failure.command.argv, [
      '/tmp/wp-codebox',
      'recipe-run',
      failure.artifact_refs.batch_recipe,
      '--artifacts-dir', expectedCodeboxArtifactsDirectory,
      '--output', failure.artifact_refs.batch_output,
    ]);
    assert.equal(failure.stdout_tail, 'stdout line 1\nstdout line 2');
    assert.equal(failure.stderr_tail, 'stderr line 1\nstderr line 2');
    assert.equal(failure.artifact_refs.artifacts_directory, expectedCodeboxArtifactsDirectory);
    assert.equal(failure.artifact_refs.fixture_artifacts_directory, outputDirectory);
    assert.equal(failure.artifact_refs.codebox_artifacts_directory, expectedCodeboxArtifactsDirectory);
    assert.equal(path.dirname(expectedCodeboxArtifactsDirectory), path.dirname(outputDirectory));
    assert.equal(expectedCodeboxArtifactsDirectory.startsWith(`${outputDirectory}${path.sep}`), false);
    assert.equal(failure.artifact_refs.output_file, failure.artifact_refs.batch_output);
    assert.ok(readFileSync(path.join(outputDirectory, 'cli-run.json'), 'utf8').includes('child_command_failures'));

    process.env.SSI_FIXTURE_MATRIX_FIXTURE_ROOT = fixtureRoot;
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
    assert.equal(
      benchResult.metadata.child_command_failures[0].artifact_refs.artifacts_directory,
      `${process.env.SSI_FIXTURE_MATRIX_OUTPUT_DIRECTORY}-wp-codebox-batch-001-artifacts`,
    );
    const benchBatchRecipe = JSON.parse(readFileSync(benchResult.metadata.child_command_failures[0].artifact_refs.batch_recipe, 'utf8'));
    const benchVisualStep = benchBatchRecipe.workflow.steps.find((step) => step.command === 'wordpress.visual-compare');
    assert.ok(benchVisualStep.args.includes('full-page=true'), 'bench env can opt visual parity back into full-page screenshots');
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
  const fixtureRoot = path.join(blocksEngine, 'fixtures', 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

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
  const fixtureRoot = path.join(blocksEngine, 'fixtures', 'websites');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

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
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot,
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
  const fixtureRoot = path.join(root, 'fixtures');
  mkdirSync(staticSiteImporter, { recursive: true });
  mkdirSync(path.join(fixtureRoot, 'fixture-a'), { recursive: true });

  const plan = buildFixtureMatrixRunPlan({
    staticSiteImporter,
    fixtureRoot,
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
  const fixtureRoot = path.join(root, 'fixtures');
  const outputDirectory = path.join(root, 'artifacts');
  const helperPath = path.join(root, 'wp-codebox-recipe-helper.cjs');
  const statsFile = path.join(root, 'recipe-stats.json');
  mkdirSync(staticSiteImporter, { recursive: true });
  for (let index = 1; index <= fixtureCount; index += 1) {
    const fixtureDir = path.join(fixtureRoot, `fixture-${String(index).padStart(2, '0')}`);
    mkdirSync(fixtureDir, { recursive: true });
    writeFileSync(path.join(fixtureDir, 'index.html'), `<h1>Fixture ${index}</h1>`);
  }
  writeConcurrencyRecipeHelper(helperPath);
  return { root, staticSiteImporter, fixtureRoot, outputDirectory, helperPath, statsFile };
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

  // [activate, validate(simple-site), editor-validate-blocks(simple-site)]
  assert.equal(recipe.workflow.steps[1].command, 'wordpress.wp-cli');
  assert.match(recipe.workflow.steps[1].args[0], /static-site-importer validate-artifact/);
  const editorStep = recipe.workflow.steps[2];
  assert.equal(editorStep.command, EDITOR_VALIDATE_BLOCKS_COMMAND);
  assert.equal(editorStep.command, 'wordpress.editor-validate-blocks');
  assert.equal(editorStep.args.some((arg) => arg.includes('post-new.php')), false);
  assert.equal(editorStep.args.some((arg) => arg.startsWith('post-type=')), false);
  assert.ok(editorStep.args.includes('target=front-page'));
  assert.equal(editorStep.args.some((arg) => arg.startsWith('capture=')), false);

  const disabled = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
    editorValidation: false,
  });
  assert.equal(disabled.workflow.steps.some((step) => step.command === EDITOR_VALIDATE_BLOCKS_COMMAND), false);
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

  // [activate, validate(simple-site), editor-validation(simple-site), visual-compare(simple-site)]
  const visualStep = recipe.workflow.steps[3];
  assert.equal(visualStep.command, 'wordpress.visual-compare');
  assert.ok(visualStep.args.some((arg) => arg.startsWith('source-url=')));
  assert.ok(visualStep.args.some((arg) => arg.startsWith('candidate-url=')));
  assert.ok(visualStep.args.includes('block-external-requests=true'));
  assert.ok(visualStep.args.includes('threshold=0.05'));

  const defaultThresholdRecipe = buildFixtureMatrixRecipe({
    matrix,
    artifactsDirectory: '/tmp/artifacts',
    staticSiteImporterPath: '/tmp/static-site-importer',
  });
  const defaultThresholdVisualStep = defaultThresholdRecipe.workflow.steps[3];
  assert.equal(defaultThresholdVisualStep.command, 'wordpress.visual-compare');
  assert.ok(defaultThresholdVisualStep.args.includes('threshold=0'), 'visual parity defaults to exact pixel parity');

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
  assert.ok(step.args.includes('source-url=http://127.0.0.1:4173/shop/index.html'));
  assert.ok(step.args.includes('candidate-url=/?p=42'));
  assert.ok(step.args.includes('threshold=0.2'));
  assert.ok(step.args.includes('source-label=shop-source'));
  assert.ok(step.args.includes('candidate-label=shop-candidate'));
  assert.ok(step.args.includes('block-external-requests=true'));
  assert.ok(visualParityCompareStep({ fixture: { id: 'shop' }, block_external_requests: false }).args.includes('block-external-requests=false'));
});

test('visualParityCompareStep demotes full-page capture to an opt-in (default bounded viewport)', () => {
  // Default: full-page is OFF (the OOM-prone unbounded screenshot is no longer
  // the default; the deterministic static parity gate is the primary signal).
  const defaultStep = visualParityCompareStep({ fixture: { id: 'tall' } });
  assert.ok(defaultStep.args.includes('full-page=false'));

  // Opt-in per fixture re-enables full-page evidence.
  for (const optIn of [
    { fixture: { id: 'tall' }, fullPage: true },
    { fixture: { id: 'tall' }, visual_parity_full_page: true },
    { fixture: { id: 'tall' }, visualParityFullPage: true },
  ]) {
    assert.ok(visualParityCompareStep(optIn).args.includes('full-page=true'));
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

  // [activate, validate(simple-site), editor-validation(simple-site), visual-compare(simple-site)]
  const visualStep = recipe.workflow.steps[3];
  const sourceArg = visualStep.args.find((arg) => arg.startsWith('source-url='));
  assert.equal(
    sourceArg,
    'source-url=file:///tmp/artifacts/simple-site/source/index.html',
  );
  // Candidate defaults to the imported front page served at `/`.
  assert.ok(visualStep.args.includes('candidate-url=/'));
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

  const visualStep = recipe.workflow.steps[3];
  assert.ok(visualStep.args.includes('source-url=/wp-content/uploads/static-site-importer-fixture-matrix/simple-site/source/index.html'));
});

test('default visual-parity source-url follows nested fixture entrypoint', () => {
  const step = visualParityCompareStep({
    fixture: { id: 'liquid-bonsai', entrypoint: 'saveweb2zip-com-liquidbonsai-com/index.html' },
    sourceBaseUrl: '/wp-content/uploads/static-site-importer-fixture-matrix',
  });

  assert.ok(
    step.args.includes('source-url=/wp-content/uploads/static-site-importer-fixture-matrix/liquid-bonsai/source/saveweb2zip-com-liquidbonsai-com/index.html'),
  );
});

test('stageFixtureSource copies the raw fixture source into the served source/ subdir', () => {
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-visual-parity-stage-'));
  const matrix = createFixtureMatrix({ fixture_root: fixtureRoot, id: 'visual-parity-stage-test' });
  writeFixtureMatrixArtifacts({ outputDirectory, matrix });

  const sourceDir = path.join(outputDirectory, 'simple-site', 'source');
  // The fixture's own files (index.html + style.css) are served from source/,
  // preserving their relative layout so assets resolve.
  assert.ok(existsSync(path.join(sourceDir, 'index.html')), 'staged source index.html should exist');
  assert.ok(existsSync(path.join(sourceDir, 'style.css')), 'staged source style.css should exist');
  assert.equal(
    readFileSync(path.join(sourceDir, 'index.html'), 'utf8'),
    readFileSync(path.join(fixtureRoot, 'simple-site', 'index.html'), 'utf8'),
  );
  // The import payload (artifact.json) is still written alongside, unchanged.
  assert.ok(existsSync(path.join(outputDirectory, 'simple-site', 'artifact.json')), 'artifact.json should still be written');
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

  // Same artifact, gate off (default) -> captured, non-gating.
  const captured = collectFixtureMatrixRunResults({ matrix, outputDirectory });
  const capturedFinding = captured.findings.find((item) => item.kind === VISUAL_PARITY_MISMATCH_KIND);
  assert.ok(capturedFinding, 'expected the mismatch to still be captured');
  assert.equal(capturedFinding.loss_acceptance, 'acceptable');
  assert.equal(captured.fixtures[0].status, 'passed');
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
