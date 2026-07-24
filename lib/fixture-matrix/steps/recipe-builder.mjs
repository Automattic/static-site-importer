// WP Codebox recipe building (import + editor-validation + visual-parity steps)
// and fixture-artifact construction for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).
/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

/**
 * Internal dependencies
 */
import {
  WEBSITE_ARTIFACT_SCHEMA,
  DEFAULT_ENTRYPOINT,
  DEFAULT_IMPORTER_SLUG,
  VISUAL_PARITY_SOURCE_SUBDIR,
  VISUAL_PARITY_DETERMINISTIC_CSS,
} from '../shared/constants.mjs';
import {
  normalizeArray,
  isImagePath,
  requiredString,
  shellToken,
} from '../shared/utils.mjs';
import { createFixtureMatrix, normalizeFixture, collectFixtureFiles } from '../fixtures.mjs';
import { editorOpenStep } from './editor-open-step.mjs';
import { editorBlockValidationStep } from './editor-validation-step.mjs';
import { visualParityCompareStep, normalizeVisualParityRecipeOptions } from './visual-parity-step.mjs';
import { liveWpParityCaptureStep, liveWpParityEnabled } from './live-wp-parity-step.mjs';
import { fixtureStepMetadata } from './shared.mjs';
import { selectFixtureSurfaces, summarizeSurfaceCoverage } from './surfaces.mjs';

const CAPABILITY_PLUGIN_PROVISIONING = {
  'commerce-products': [
    { slug: 'woocommerce', label: 'WooCommerce' },
  ],
  forms: [
    { slug: 'jetpack', label: 'Jetpack' },
  ],
};

// A capability-required provider must be actually usable before import, not just
// installed. Plugin installation stays best-effort (it can flake or retry), but a
// silently-missing provider previously degraded a required capability into a
// fallback finding (e.g. a `forms` fixture emitting `html_form_fallback` because
// Jetpack never activated). Each provider declares the PHP runtime predicate that
// proves its capability is materializable so the readiness gate can fail closed.
const CAPABILITY_PROVIDER_READINESS = {
  woocommerce: {
    label: 'WooCommerce',
    // The commerce provider is ready when its plugin is active and the
    // `[add_to_cart]` shortcode SSI seeds products through is registered.
    predicate: "is_plugin_active('woocommerce/woocommerce.php') && shortcode_exists('add_to_cart')",
  },
  jetpack: {
    label: 'Jetpack',
    // The form provider is ready only when the `jetpack/contact-form` block is
    // registered AND actually renders a form on the frontend. Merely finding the
    // Contact_Form class is a false positive: the class autoloads via Composer,
    // but Jetpack gates the block's render callback behind an active module, so an
    // unprovisioned install renders the seeded form to an empty string. Prove the
    // real behavior by rendering a minimal contact-form block and requiring a
    // `<form>` in the output, so a non-rendering provider fails closed here.
    predicate: "class_exists('WP_Block_Type_Registry') && WP_Block_Type_Registry::get_instance()->is_registered('jetpack/contact-form') && ( false !== stripos( do_blocks('<!-- wp:jetpack/contact-form --><!-- wp:jetpack/field-name {\\\"label\\\":\\\"Name\\\"} /--><!-- /wp:jetpack/contact-form -->'), '<form' ) )",
  },
};

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
      fixture_capabilities: normalized.capabilities,
      fixture_risk_profile: normalized.risk_profile,
      fixture_quality_budgets: normalized.quality_budgets,
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
    if (isHtmlPath(file.relative_path)) {
      injectDeterministicSourceCss(destination, normalized.id);
    }
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
  const stagedFiles = normalizeArray(input.stagedFiles || input.staged_files);
  const extraPlugins = [importer.extraPlugin, ...normalizeArray(input.extraPlugins || input.extra_plugins)];
  const editorValidationEnabled = input.editorValidation !== false && input.editor_validation !== false;
  const editorOpenEnabled = editorValidationEnabled && input.editorOpen !== false && input.editor_open !== false;
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
  const derivedSourceBaseUrl = playgroundArtifactsDirectory
    ? playgroundSourceBaseUrl(playgroundArtifactsDirectory)
    : pathToFileURL(artifactsDirectory).toString().replace(/\/+$/, '');
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
  const surfaceCoverage = summarizeSurfaceCoverage(matrix.fixtures, input);

  if (playgroundArtifactsDirectory) {
    for (const fixture of matrix.fixtures) {
      stagedFiles.push({
        source: path.join(artifactsDirectory, fixture.id, 'artifact.json'),
        target: path.join(playgroundArtifactsDirectory, fixture.id, 'artifact.json'),
      });
      for (const relativePath of collectStagedSourcePaths(artifactsDirectory, fixture.id)) {
        stagedFiles.push({
          source: path.join(artifactsDirectory, fixture.id, VISUAL_PARITY_SOURCE_SUBDIR, relativePath),
          target: path.join(playgroundArtifactsDirectory, fixture.id, VISUAL_PARITY_SOURCE_SUBDIR, relativePath),
        });
      }
    }
  }

  return {
    schema: 'wp-codebox/workspace-recipe/v1',
    runtime: {
      wp: input.wordpressVersion || input.wordpress_version || 'latest',
      blueprint: input.blueprint || {},
    },
    inputs: {
      mounts,
      stagedFiles,
      extra_plugins: extraPlugins,
      ...(dependencyOverrideSetup.dependencyOverlays.length
        ? { dependency_overlays: dependencyOverrideSetup.dependencyOverlays }
        : {}),
    },
    workflow: {
      steps: [
        importer.activationStep,
        ...matrix.fixtures.flatMap((fixture) => fixtureWorkflowSteps({
          fixture,
          input,
          commandArtifactsDirectory,
          editorOpenEnabled,
          editorValidationEnabled,
          editorValidationOptions,
          visualParityEnabled,
          visualParityRecipeOptions,
          liveWpParityCaptureEnabled,
        })),
      ],
    },
    artifacts: {
      directory: artifactsDirectory,
    },
    metadata: {
      surface_coverage: surfaceCoverage,
      runtime_cost_warnings: surfaceCoverage.enabled ? [surfaceCoverageRuntimeWarning(surfaceCoverage)] : [],
    },
  };
}

function surfaceCoverageRuntimeWarning(surfaceCoverage) {
  return {
    code: 'surface_coverage_runtime_cost',
    message: `Surface coverage is enabled: ${surfaceCoverage.total_surface_count} browser surfaces will run across ${surfaceCoverage.fixture_count} fixtures (${surfaceCoverage.extra_surfaces_per_fixture} extra per fixture, max ${surfaceCoverage.max_extra_surfaces}).`,
  };
}

function fixtureWorkflowSteps(options) {
  const {
    fixture,
    input,
    commandArtifactsDirectory,
    editorOpenEnabled,
    editorValidationEnabled,
    editorValidationOptions,
    visualParityEnabled,
    visualParityRecipeOptions,
    liveWpParityCaptureEnabled,
  } = options;
  const surfaces = selectFixtureSurfaces(fixture, input);

  return [
    ...fixturePluginProvisioningSteps(fixture),
    ...jetpackFormsActivationSteps(fixture),
    ...fixtureCapabilityReadinessSteps(fixture),
    importFixtureStep(fixture, commandArtifactsDirectory),
    ...(input.svgFontEvidence || input.svg_font_evidence ? [svgFontEvidenceStep(fixture)] : []),
    woocommerceOnboardingSuppressionStep(fixture),
    ...surfaces.flatMap((surface, index) => [
      ...(editorOpenEnabled ? [editorOpenStep({
        fixture,
        surface: editorSurface(surface),
        ...editorValidationOptions,
        artifactPrefix: editorArtifactPrefix(fixture, surface),
      })] : []),
      ...(editorValidationEnabled ? [editorBlockValidationStep({ fixture, surface: editorSurface(surface), ...editorValidationOptions })] : []),
      ...(visualParityEnabled && index === 0 ? [visualParityDeterministicCssStep(fixture)] : []),
      ...(visualParityEnabled ? [visualParityCompareStep({ fixture, surface, ...visualParityRecipeOptions })] : []),
    ]),
    ...(liveWpParityCaptureEnabled ? [liveWpParityCaptureStep({ fixture, ...input })] : []),
  ];
}

function svgFontEvidenceStep(fixture) {
  const code = `$root = get_stylesheet_directory();
$files = array();
$font_families = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if (!$file->isFile() || 'css' !== strtolower($file->getExtension())) { continue; }
	$content = file_get_contents($file->getPathname());
	if (false === $content || !preg_match_all('#https://fonts\\.googleapis\\.com/(?:css|css2)\\?[^\\s"\)]+#i', $content, $urls)) { continue; }
	foreach ($urls[0] as $url) {
		if (!preg_match_all('/(?:[?&])family=([^&]+)/', html_entity_decode($url), $families)) { continue; }
		foreach ($families[1] as $family) {
			$font_families[] = trim(explode(':', urldecode($family), 2)[0]);
		}
	}
}
$font_families = array_values(array_unique(array_filter($font_families)));
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
	if (!$file->isFile() || 'svg' !== strtolower($file->getExtension())) { continue; }
	$content = file_get_contents($file->getPathname());
	if (false === $content) { continue; }
	$uses_planned_font = false;
	if (preg_match('/<text\\b/i', $content)) {
		foreach ($font_families as $family) {
			if (false !== stripos($content, $family)) { $uses_planned_font = true; break; }
		}
	}
	$files[] = array(
		'path' => ltrim(str_replace('\\\\', '/', substr($file->getPathname(), strlen($root))), '/'),
		'bytes' => strlen($content),
		'sha256' => hash('sha256', $content),
		'uses_planned_font' => $uses_planned_font,
		'has_font_face' => str_contains($content, '@font-face'),
		'has_data_font' => str_contains($content, 'data:font/'),
	);
}
usort($files, static fn($left, $right) => strcmp($left['path'], $right['path']));
$expected = count(array_filter($files, static fn($file) => $file['uses_planned_font']));
$embedded = count(array_filter($files, static fn($file) => $file['uses_planned_font'] && $file['has_data_font']));
$complete = 0 === $expected || $expected === $embedded;
WP_CLI::line(wp_json_encode(array(
	'status' => $complete ? 'success' : 'failed',
	'success' => $complete,
	'svg_font_embedding_evidence' => array(
		'schema' => 'static-site-importer/svg-font-embedding-evidence/v1',
		'status' => $complete ? 'complete' : 'required_fonts_missing',
		'planned_font_families' => $font_families,
		'svg_count' => count($files),
		'expected_font_svg_count' => $expected,
		'embedded_font_svg_count' => $embedded,
		'files' => $files,
	),
), JSON_UNESCAPED_SLASHES));
if (!$complete) { WP_CLI::error('Required self-contained SVG fonts are missing; visual parity capture is not valid.'); }`;
  const encoded = Buffer.from(code, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${encoded}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'svg-font-evidence'),
  };
}

function editorSurface(surface) {
  if (surface.id === 'front-page') {
    return surface;
  }
  const postSlug = String(surface.target || '').split('/').filter(Boolean).join('-');
  return { ...surface, post_type: 'page', post_slug: postSlug };
}

function editorArtifactPrefix(fixture, surface) {
  if (surface.id === 'front-page') {
    return `files/browser/editor-open/${fixture.id}`;
  }
  return `files/browser/editor-open/${fixture.id}/${surface.id}`;
}

function importFixtureStep(fixture, commandArtifactsDirectory) {
  return {
    command: 'wordpress.wp-cli',
    args: [
      `command=static-site-importer validate-artifact --artifact=${shellToken(path.join(commandArtifactsDirectory, fixture.id, 'artifact.json'))} --slug=${shellToken(fixture.id)} --name=${shellToken(fixture.label)} --allow-failure`,
    ],
    metadata: fixtureStepMetadata(fixture, 'import', {
      artifact: path.join(commandArtifactsDirectory, fixture.id, 'artifact.json'),
    }),
  };
}

function visualParityDeterministicCssStep(fixture) {
  const css = Buffer.from(VISUAL_PARITY_DETERMINISTIC_CSS, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [
      `command=eval ${shellToken(`wp_update_custom_css_post(base64_decode('${css}'));`)}`,
    ],
    metadata: fixtureStepMetadata(fixture, 'visual-setup'),
  };
}

function isHtmlPath(filePath) {
  return /\.html?$/i.test(filePath);
}

function injectDeterministicSourceCss(filePath, fixtureId) {
  const html = fs.readFileSync(filePath, 'utf8');
  const style = `<style data-ssi-visual-parity-deterministic>\n${VISUAL_PARITY_DETERMINISTIC_CSS}\n</style>`;
  const fontUrl = `/wp-content/themes/${themeSlug(fixtureId)}/assets/css/embedded-fonts.css`;
  const fontStylesheet = `<link rel="stylesheet" href="${fontUrl}" data-ssi-visual-parity-fonts>`;
  const svgNormalization = `<script data-ssi-visual-parity-svg-normalization>
(async function () {
  if (document.readyState === 'loading') await new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  let fontCss = '';
  try {
    const response = await fetch(${JSON.stringify(fontUrl)});
    if (response.ok) fontCss = await response.text();
  } catch {}
  await Promise.all(Array.from(document.querySelectorAll('svg')).map(async (svg) => {
    const box = svg.getBoundingClientRect();
    if (!(box.width > 0 && box.height > 0)) return;
    const clone = svg.cloneNode(true);
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    if (fontCss && clone.querySelector('text')) {
      const fontStyle = document.createElementNS('http://www.w3.org/2000/svg', 'style');
      fontStyle.textContent = fontCss;
      clone.prepend(fontStyle);
    }
    const image = document.createElement('img');
    for (const name of ['class', 'role', 'aria-label', 'aria-hidden']) {
      if (svg.hasAttribute(name)) image.setAttribute(name, svg.getAttribute(name));
    }
    const computed = getComputedStyle(svg);
    clone.style.setProperty('color', computed.color);
    image.style.cssText = svg.getAttribute('style') || '';
    image.style.setProperty('display', computed.display);
    image.style.setProperty('vertical-align', computed.verticalAlign);
    image.style.setProperty('width', box.width + 'px');
    image.style.setProperty('height', box.height + 'px');
    image.src = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)], { type: 'image/svg+xml' }));
    await image.decode().catch(() => undefined);
    svg.replaceWith(image);
  }));
  document.documentElement.setAttribute('data-ssi-visual-parity-svg-normalized', 'true');
}());
</script>`;
  const withLocalFonts = html.replace(/<link\b(?=[^>]*\bhref=["']https:\/\/fonts\.googleapis\.com\/[^"']+["'])[^>]*>/gi, fontStylesheet);
  const withStyles = /<\/head>/i.test(withLocalFonts)
    ? withLocalFonts.replace(/<\/head>/i, `${style}\n</head>`)
    : `${style}\n${withLocalFonts}`;
  const updated = /<\/body>/i.test(withStyles)
    ? withStyles.replace(/<\/body>/i, `${svgNormalization}\n</body>`)
    : `${withStyles}\n${svgNormalization}`;
  fs.writeFileSync(filePath, updated);
}

function collectStagedSourcePaths(artifactsDirectory, fixtureId) {
  const root = path.join(artifactsDirectory, fixtureId, VISUAL_PARITY_SOURCE_SUBDIR);
  if (!fs.existsSync(root)) return [];
  const paths = [];
  const visit = (directory) => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const absolute = path.join(directory, entry.name);
      if (entry.isDirectory()) visit(absolute);
      else if (entry.isFile()) paths.push(path.relative(root, absolute));
    }
  };
  visit(root);
  return paths.sort();
}

function playgroundSourceBaseUrl(playgroundArtifactsDirectory) {
  const normalized = String(playgroundArtifactsDirectory).replace(/\\/g, '/').replace(/^\/wordpress/, '');
  return normalized.startsWith('/') ? normalized.replace(/\/+$/, '') : `/${normalized.replace(/^\/+|\/+$/g, '')}`;
}

function themeSlug(value) {
  return String(value || 'fixture').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'fixture';
}

function fixturePluginProvisioningSteps(fixture) {
  const plugins = fixtureCapabilityPlugins(fixture);
  const steps = plugins.map((plugin) => ({
    command: 'wordpress.plugin-setup',
    allowFailure: true,
    args: [
      'action=install',
      `plugin=${shellToken(plugin.slug)}`,
      'activate=true',
    ],
    metadata: fixtureStepMetadata(fixture, 'provision-plugin', {
      capability: plugin.capability,
      plugin_slug: plugin.slug,
      plugin_label: plugin.label,
    }),
  }));
  return steps;
}

// Jetpack's contact-form block only registers its render callback when the
// `contact-form` module is active, and Jetpack refuses to load any module on an
// unconnected site unless it is in offline mode (Jetpack::load_modules() returns
// early otherwise). A generated/local site has no WPCOM connection, so the block
// would render to an empty string on the frontend and the seeded form would
// vanish. Provision the sanctioned local/dev path — offline mode plus the active
// module — via an mu-plugin so it applies to every subsequent frontend request,
// matching how an unconnected Jetpack install is expected to run forms locally.
// Only emitted when the fixture actually provisions Jetpack.
function jetpackFormsActivationSteps(fixture) {
  if (!fixtureCapabilityPlugins(fixture).some((plugin) => plugin.slug === 'jetpack')) {
    return [];
  }
  const muPlugin = [
    '<?php',
    "add_filter( 'jetpack_offline_mode', '__return_true', 9 );",
    "add_filter( 'jetpack_active_modules', function ( $modules ) {",
    '  $modules = (array) $modules;',
    "  if ( ! in_array( 'contact-form', $modules, true ) ) {",
    "    $modules[] = 'contact-form';",
    '  }',
    '  return $modules;',
    '}, 9 );',
    '',
  ].join('\n');
  const php = `$dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins'; if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); } $ok = file_put_contents( $dir . '/ssi-fixture-jetpack-forms.php', ${phpSingleQuoted(muPlugin)} ); if ( false === $ok ) { fwrite(STDERR, 'Failed to write Jetpack Forms activation mu-plugin.'); exit(1); } echo 'jetpack-forms-activated';`;
  return [
    {
      command: 'wordpress.run-php',
      args: [`code=${php}`],
      metadata: fixtureStepMetadata(fixture, 'provider-preflight', {
        setup: 'activate-jetpack-forms-module',
        provider: 'jetpack',
      }),
    },
  ];
}

function woocommerceOnboardingSuppressionStep(fixture) {
  return {
    command: 'wordpress.wp-cli',
    allowFailure: true,
    args: [
      `command=eval ${shellToken("delete_transient('_wc_activation_redirect'); update_option('woocommerce_onboarding_profile', array('completed' => true, 'skipped' => true)); update_option('woocommerce_task_list_hidden', 'yes');")}`,
    ],
    metadata: fixtureStepMetadata(fixture, 'editor-preflight', {
      setup: 'suppress-onboarding-redirect',
    }),
  };
}

function fixtureCapabilityPlugins(fixture) {
  const seen = new Set();
  const plugins = [];
  for (const capability of normalizeArray(fixture.capabilities).map((item) => String(item || '').trim().toLowerCase()).filter(Boolean)) {
    for (const plugin of CAPABILITY_PLUGIN_PROVISIONING[capability] || []) {
      if (seen.has(plugin.slug)) {
        continue;
      }
      seen.add(plugin.slug);
      plugins.push({ ...plugin, capability });
    }
  }
  return plugins;
}

// Emit a fail-closed readiness assertion per capability-required provider so a
// silently-missing provider (e.g. a Jetpack install that flaked under
// `allowFailure`) surfaces as a hard, visible error before import instead of
// degrading the required capability into a fallback finding. The install step
// keeps `allowFailure`; this gate does not.
function fixtureCapabilityReadinessSteps(fixture) {
  const steps = [];
  for (const plugin of fixtureCapabilityPlugins(fixture)) {
    const readiness = CAPABILITY_PROVIDER_READINESS[plugin.slug];
    if (!readiness) {
      continue;
    }
    const message = `${readiness.label} is required for the "${plugin.capability}" capability but its runtime is not available after provisioning. Install/activate ${readiness.label} on this WordPress version before running the fixture matrix, or remove the "${plugin.capability}" capability from ${fixture.id}.`;
    const php = `if ( ! function_exists('is_plugin_active') ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; } if ( ! ( ${readiness.predicate} ) ) { fwrite(STDERR, ${phpSingleQuoted(message)}); exit(1); } echo ${phpSingleQuoted(`${plugin.slug}-ready`)};`;
    steps.push({
      command: 'wordpress.run-php',
      args: [`code=${php}`],
      metadata: fixtureStepMetadata(fixture, 'capability-readiness', {
        capability: plugin.capability,
        plugin_slug: plugin.slug,
        plugin_label: readiness.label,
      }),
    });
  }
  return steps;
}

// Wrap a literal string as a single-quoted PHP string, escaping backslashes and
// single quotes per PHP single-quote rules.
function phpSingleQuoted(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function buildDependencyOverrideSetup(input, importer) {
  const overrides = input.dependencyOverrides || input.dependency_overrides || {};
  const blocksEnginePhpTransformer = overrides.blocks_engine_php_transformer || overrides.blocksEnginePhpTransformer;
  const rawPackagePath = blocksEnginePhpTransformer?.path || '';
  if (!rawPackagePath) {
    return { dependencyOverlays: [] };
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
  };
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
