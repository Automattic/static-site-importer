// WP Codebox recipe building (import + editor-validation + visual-parity steps)
// and fixture-artifact construction for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import {
  WEBSITE_ARTIFACT_SCHEMA,
  DEFAULT_ENTRYPOINT,
  DEFAULT_IMPORTER_SLUG,
  VISUAL_PARITY_SOURCE_SUBDIR,
} from '../shared/constants.mjs';
import {
  normalizeArray,
  isImagePath,
  requiredString,
  shellToken,
} from '../shared/utils.mjs';
import { createFixtureMatrix, normalizeFixture, collectFixtureFiles } from '../fixtures.mjs';
import { editorBlockValidationStep } from './editor-validation-step.mjs';
import { visualParityCompareStep, normalizeVisualParityRecipeOptions } from './visual-parity-step.mjs';
import { liveWpParityCaptureStep, liveWpParityEnabled } from './live-wp-parity-step.mjs';

export function buildFixtureArtifact(fixture, options = {}) {
  const normalized = normalizeFixture(fixture);
  const files = collectFixtureFiles(normalized.directory, options);
  // Encode EVERY file as `content_base64`, byte-for-byte matching the real
  // product path. The SSI `import-theme` CLI (static-site-importer.php) reads
  // each source file and emits `'content_base64' => base64_encode( $content )`
  // unconditionally — there is no plain-`content` branch in the product. The
  // matrix previously diverged here, base64-encoding only binary payloads and
  // sending text (CSS/HTML/JS/JSON/SVG) as plain `content`. That divergence hid
  // a catastrophic transformer bug: inline CSS was dropped only on the base64
  // path, so a real import shipped an empty `style.css` (unstyled site) while
  // the matrix's plain-content artifacts passed green. Mirroring the product's
  // encoding exactly means the gate can never again exercise a payload shape the
  // product does not actually produce.
  const artifactFiles = files.map((file) => {
    const payload = fs.readFileSync(file.absolute_path);
    return {
      path: `website/${file.relative_path}`,
      source_path: file.absolute_path,
      type: file.type,
      bytes: file.bytes,
      content_base64: payload.toString('base64'),
    };
  });

  return {
    schema: WEBSITE_ARTIFACT_SCHEMA,
    entrypoint: DEFAULT_ENTRYPOINT,
    entry_path: DEFAULT_ENTRYPOINT,
    files: artifactFiles,
    summary: {
      file_count: artifactFiles.length,
      entry_path: DEFAULT_ENTRYPOINT,
      has_css: artifactFiles.some((file) => file.path.endsWith('.css')),
      has_js: artifactFiles.some((file) => file.path.endsWith('.js')),
      has_images: artifactFiles.some((file) => isImagePath(file.path)),
    },
    source_metadata: {
      fixture_id: normalized.id,
      fixture_path: normalized.directory,
      fixture_entrypoint: normalized.entrypoint,
      fixture_class: normalized.fixture_class,
      fixture_tags: normalized.tags,
      fixture_complexity: normalized.complexity,
    },
  };
}

// Stage a fixture's ORIGINAL static source (index.html + css/js/images) into the
// matrix artifacts tree so the in-sandbox WordPress origin can serve it for the
// visual-parity `source-url`. Files land at
// `<fixtureDirectory>/<VISUAL_PARITY_SOURCE_SUBDIR>/<relative_path>`, preserving
// each fixture's own relative asset layout so the served page resolves its CSS,
// JS, and images exactly as the original did. The fixture's `artifact.json`
// import payload is unchanged; this is a parallel, web-servable copy of the raw
// source. Returns the list of staged relative paths. Without this, `source-url`
// points at an unserved path and the visual-compare source capture hangs to the
// 120s timeout (the #563 visual-parity gap).
export function stageFixtureSource(fixture, fixtureDirectory, options = {}) {
  const normalized = normalizeFixture(fixture);
  const files = collectFixtureFiles(normalized.directory, options);
  const sourceRoot = path.join(fixtureDirectory, VISUAL_PARITY_SOURCE_SUBDIR);
  const staged = [];
  for (const file of files) {
    const destination = path.join(sourceRoot, file.relative_path);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.copyFileSync(file.absolute_path, destination);
    staged.push(file.relative_path);
  }
  return staged;
}

export function buildFixtureMatrixRecipe(input = {}) {
  const matrix = input.matrix || createFixtureMatrix(input);
  const artifactsDirectory = input.artifactsDirectory || input.artifacts_directory || '/artifacts/static-site-importer-fixture-matrix';
  const playgroundArtifactsDirectory = input.playgroundArtifactsDirectory || input.playground_artifacts_directory;
  const commandArtifactsDirectory = playgroundArtifactsDirectory || artifactsDirectory;
  const importer = normalizeStaticSiteImporterPlugin(input);
  const dependencyOverrideSetup = buildDependencyOverrideSetup(input, importer);
  const mounts = normalizeArray(input.mounts);
  const extraPlugins = [importer.extraPlugin, ...normalizeArray(input.extraPlugins || input.extra_plugins)];
  const editorValidationEnabled = input.editorValidation !== false && input.editor_validation !== false;
  // Real-content validation options forwarded to the editor-validate-blocks step.
  // No empty-post default: when nothing concrete is provided, the step targets
  // `front-page`, which wp-codebox resolves to the imported static front page
  // (`page_on_front`) at runtime so it validates real imported content.
  const editorValidationOptions = {
    url: input.editorValidationUrl || input.editor_validation_url,
    postType: input.editorValidationPostType || input.editor_validation_post_type,
    target: input.editorValidationTarget || input.editor_validation_target,
    waitSelector: input.editorValidationWaitSelector || input.editor_validation_wait_selector,
    waitTimeout: input.editorValidationWaitTimeout || input.editor_validation_wait_timeout,
  };
  const visualParityEnabled = input.visualParity !== false && input.visual_parity !== false;
  // Keep source capture out of the WordPress preview proxy. The staged source
  // files are local artifacts, so a file:// URL captures the original static site
  // directly while the candidate still renders through WordPress at `/`.
  const derivedSourceBaseUrl = pathToFileURL(artifactsDirectory).toString().replace(/\/+$/, '');
  const visualParityRecipeOptions = normalizeVisualParityRecipeOptions({
    ...(derivedSourceBaseUrl ? { sourceBaseUrl: derivedSourceBaseUrl } : {}),
    ...input,
  });
  // Optional live-WP parity capture: off by default so the render-free static gate
  // stays the primary, always-on signal. When enabled, append a deterministic
  // `wordpress.capture-html` step (DOM HTML, external requests blocked, no
  // screenshot) per fixture; the captured snapshot.html is fed host-side to the
  // blocks-engine live-wp-parity runner (see collectors/live-wp-parity.mjs).
  const liveWpParityCaptureEnabled = liveWpParityEnabled(input);

  if (playgroundArtifactsDirectory) {
    mounts.push({
      source: artifactsDirectory,
      target: playgroundArtifactsDirectory,
      mode: 'readwrite',
    });
  }

  return {
    schema: 'wp-codebox/workspace-recipe/v1',
    runtime: {
      wp: input.wordpressVersion || input.wordpress_version || 'latest',
      blueprint: input.blueprint || {},
    },
    inputs: {
      mounts,
      extra_plugins: extraPlugins,
      ...(dependencyOverrideSetup.dependencyOverlays.length
        ? { dependency_overlays: dependencyOverrideSetup.dependencyOverlays }
        : {}),
    },
    ...(Object.keys(dependencyOverrideSetup.metadata).length
      ? { metadata: { dependency_overrides: dependencyOverrideSetup.metadata } }
      : {}),
    workflow: {
      steps: [
        importer.activationStep,
        ...matrix.fixtures.flatMap((fixture) => [
          {
            command: 'wordpress.wp-cli',
            args: [
              `command=static-site-importer validate-artifact --artifact=${shellToken(path.join(commandArtifactsDirectory, fixture.id, 'artifact.json'))} --slug=${shellToken(fixture.id)} --name=${shellToken(fixture.label)} --allow-missing-woocommerce --allow-failure`,
            ],
          },
          ...(editorValidationEnabled ? [editorBlockValidationStep({ fixture, ...editorValidationOptions })] : []),
          ...(visualParityEnabled ? [visualParityCompareStep({ fixture, ...visualParityRecipeOptions })] : []),
          ...(liveWpParityCaptureEnabled ? [liveWpParityCaptureStep({ fixture, ...input })] : []),
        ]),
      ],
    },
    artifacts: {
      directory: artifactsDirectory,
    },
  };
}

function buildDependencyOverrideSetup(input, importer) {
  const overrides = input.dependencyOverrides || input.dependency_overrides || {};
  const blocksEnginePhpTransformer = overrides.blocks_engine_php_transformer || overrides.blocksEnginePhpTransformer;
  const rawPackagePath = blocksEnginePhpTransformer?.path || '';
  if (!rawPackagePath) {
    return { dependencyOverlays: [], metadata: {} };
  }

  const packagePath = path.resolve(rawPackagePath);
  const packageName = blocksEnginePhpTransformer.package || 'automattic/blocks-engine-php-transformer';
  if (packageName !== 'automattic/blocks-engine-php-transformer') {
    throw new Error(`Unsupported SSI dependency override package: ${packageName}`);
  }
  const packageComposerFile = path.join(packagePath, 'composer.json');
  if (!fs.existsSync(packageComposerFile)) {
    throw new Error(`SSI dependency override package composer.json not found: ${packageComposerFile}`);
  }
  const packageComposer = JSON.parse(fs.readFileSync(packageComposerFile, 'utf8'));
  if (packageComposer?.name !== packageName) {
    throw new Error(`SSI dependency override path must contain ${packageName}: ${packagePath}`);
  }

  return {
    dependencyOverlays: [
      {
        kind: 'composer-package',
        package: packageName,
        consumer: importer.slug,
        source: packagePath,
      },
    ],
    metadata: {
      blocks_engine_php_transformer: {
        package: packageName,
        source_path: packagePath,
        applied_by: 'wp_codebox_dependency_overlay',
        consumer: importer.slug,
      },
    },
  };
}

function dependencyOverrideComposerSetupPhp({ pluginPath, packagePath, packageName }) {
  return `
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}
$pluginPath = ${phpString(pluginPath)};
$packagePath = ${phpString(packagePath)};
$packageName = ${phpString(packageName)};
$composerFile = $pluginPath . '/composer.json';
$packageComposerFile = $packagePath . '/composer.json';
if (!file_exists($composerFile)) {
    fwrite(STDERR, "Static Site Importer composer.json not found at $composerFile\\n");
    exit(1);
}
if (!file_exists($packageComposerFile)) {
    fwrite(STDERR, "Dependency override composer.json not found at $packageComposerFile\\n");
    exit(1);
}
$packageComposer = json_decode(file_get_contents($packageComposerFile), true);
if (!is_array($packageComposer) || ($packageComposer['name'] ?? '') !== $packageName) {
    fwrite(STDERR, "Dependency override package mismatch at $packageComposerFile\\n");
    exit(1);
}
$composer = json_decode(file_get_contents($composerFile), true);
if (!is_array($composer)) {
    fwrite(STDERR, "Static Site Importer composer.json is invalid JSON at $composerFile\\n");
    exit(1);
}
$constraint = $composer['require'][$packageName] ?? '0.1.15';
$version = preg_match('/^\\^?(\\d+\\.\\d+\\.\\d+)$/', trim((string) $constraint), $matches) ? $matches[1] : '0.1.15';
$composer['repositories'] = isset($composer['repositories']) && is_array($composer['repositories']) ? $composer['repositories'] : [];
$composer['repositories']['blocks-engine-php-transformer-dev'] = [
    'type' => 'path',
    'url' => $packagePath,
    'canonical' => true,
    'options' => [
        'symlink' => false,
        'versions' => [ $packageName => $version ],
    ],
];
file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\\n");
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(['composer', 'update', $packageName, '--with-dependencies', '--no-interaction', '--prefer-source', '--no-progress'], $descriptorSpec, $pipes, $pluginPath);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start composer for SSI dependency override\\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
if ($exitCode !== 0) {
    fwrite(STDERR, "SSI dependency override composer update failed with exit $exitCode\\n" . $stderr . $stdout);
    exit($exitCode ?: 1);
}
`;
}

function phpString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function wpCliDoubleQuotedToken(value) {
  const text = String(value || '');
  return `"${text.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

// Convert an in-sandbox WordPress filesystem path into its web-served path by
// stripping the docroot prefix. WP Codebox installs WordPress at `/wordpress`, so
// `/wordpress/wp-content/uploads/foo` is served at `/wp-content/uploads/foo`. A
// path already rooted at `/wp-content` (no `/wordpress` prefix) is returned as-is.
export function wordpressServedPath(filesystemPath, docroot = '/wordpress') {
  const normalized = `/${String(filesystemPath).replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+$/, '')}`;
  const prefix = `${docroot.replace(/\/+$/, '')}/`;
  return normalized.startsWith(prefix) ? `/${normalized.slice(prefix.length)}` : normalized;
}

export function normalizeStaticSiteImporterPlugin(input = {}) {
  const source = requiredString(input.staticSiteImporterPath || input.static_site_importer_path, 'staticSiteImporterPath');
  const slugValue = input.staticSiteImporterSlug || input.static_site_importer_slug || DEFAULT_IMPORTER_SLUG;
  const pluginFile = input.staticSiteImporterPlugin || input.static_site_importer_plugin || `${slugValue}/${slugValue}.php`;
  return {
    slug: slugValue,
    extraPlugin: {
      source,
      slug: slugValue,
      activate: true,
    },
    activationStep: {
      command: 'wordpress.wp-cli',
      args: [`command=plugin activate ${pluginFile}`],
    },
  };
}
