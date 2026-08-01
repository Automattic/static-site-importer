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
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { randomUUID } from 'node:crypto';

/**
 * Internal dependencies
 */
import {
  WEBSITE_ARTIFACT_SCHEMA,
  GENERATED_ARTIFACT_METADATA_FILENAME,
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
import { runtimePresentationEvidenceArtifactPath, runtimePresentationEvidenceEnabled, runtimePresentationEvidenceMergeStep, runtimePresentationEvidenceProbeStep } from '../runtime-presentation-evidence.mjs';

const SOURCE_EXCLUSION_RULES = JSON.parse(
  fs.readFileSync(new URL('../../../includes/source-exclusion-rules.json', import.meta.url), 'utf8'),
).rules;

export function buildFixtureArtifact(fixture, options = {}) {
  const normalized = normalizeFixture(fixture);
  const files = collectFixtureFiles(normalized.directory, options);
  const generatedArtifactMetadata = readGeneratedArtifactMetadata(normalized.directory);
  const sourceExclusions = [];
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
    let payload = fs.readFileSync(file.absolute_path);
    if (isHtmlPath(file.relative_path)) {
      const result = normalizeSourceHtml(payload.toString('utf8'), `website/${file.relative_path}`);
      payload = Buffer.from(result.html);
      sourceExclusions.push(...result.exclusions);
    }
    return {
      path: `website/${file.relative_path}`,
      source_path: file.absolute_path,
      type: file.type,
      bytes: payload.length,
      content_base64: payload.toString('base64'),
    };
  });

  return {
    schema: WEBSITE_ARTIFACT_SCHEMA,
    entrypoint: DEFAULT_ENTRYPOINT,
    entry_path: DEFAULT_ENTRYPOINT,
    ...(generatedArtifactMetadata.compiler_limits ? { compiler_limits: generatedArtifactMetadata.compiler_limits } : {}),
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
      source_exclusions: sourceExclusions,
    },
  };
}

function readGeneratedArtifactMetadata(directory) {
  const metadataPath = path.join(directory, GENERATED_ARTIFACT_METADATA_FILENAME);
  if (!fs.existsSync(metadataPath)) {
    return {};
  }
  try {
    const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
    return metadata?.schema === 'static-site-importer/generated-artifact-metadata/v1' ? metadata : {};
  } catch {
    return {};
  }
}

// Stage a fixture's normalized static source (index.html + css/js/images) into the
// matrix artifacts tree so the in-sandbox WordPress origin can serve it for the
// visual-parity `source-url`. Files land at
// `<fixtureDirectory>/<VISUAL_PARITY_SOURCE_SUBDIR>/<relative_path>`, preserving
// each fixture's own relative asset layout so the served page resolves its CSS,
// JS, and images exactly as the original did. The fixture's `artifact.json`
// import payload uses the same normalization policy so excluded source-platform
// chrome cannot create a false visual mismatch. Returns the staged paths.
// Without this, `source-url` points at an unserved path and the visual-compare
// source capture hangs to the 120s timeout (the #563 visual-parity gap).
export function stageFixtureSource(fixture, fixtureDirectory, options = {}) {
  const normalized = normalizeFixture(fixture);
  const files = collectFixtureFiles(normalized.directory, options);
  const sourceRoot = path.join(fixtureDirectory, VISUAL_PARITY_SOURCE_SUBDIR);
  const staged = [];
  for (const file of files) {
    const destination = path.join(sourceRoot, file.relative_path);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    if (isHtmlPath(file.relative_path)) {
      const result = normalizeSourceHtml(fs.readFileSync(file.absolute_path, 'utf8'), `website/${file.relative_path}`);
      fs.writeFileSync(destination, result.html);
      injectDeterministicSourceCss(destination, normalized.id);
    } else {
      fs.copyFileSync(file.absolute_path, destination);
    }
    staged.push(file.relative_path);
  }
  return staged;
}

function normalizeSourceHtml(html, sourcePath) {
  const original = html;
  const exclusions = [];
  for (const rule of SOURCE_EXCLUSION_RULES) {
    if (!rule?.selector?.startsWith('#')) continue;
    const removed = removeElementById(html, rule.selector.slice(1));
    if (!removed) continue;
    html = removed.html;
    exclusions.push({
      schema: 'static-site-importer/source-exclusion/v1',
      action: 'removed',
      category: rule.category || 'source_chrome',
      provider: rule.provider || '',
      rule_id: rule.id || '',
      selector: rule.selector,
      source_path: sourcePath,
      reason_code: rule.reason_code || 'source_chrome_removed',
      removed_sha256: sha256(removed.element),
    });
  }
  for (const exclusion of exclusions) {
    exclusion.source_sha256 = sha256(original);
    exclusion.normalized_sha256 = sha256(html);
  }
  return { html, exclusions };
}

function removeElementById(html, id) {
  const escapedId = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const openingPattern = new RegExp(`<([a-z][a-z0-9:-]*)\\b[^>]*\\bid\\s*=\\s*(?:"${escapedId}"|'${escapedId}'|${escapedId})(?:\\s|/?>)`, 'i');
  const opening = openingPattern.exec(html);
  if (!opening) return null;
  const tag = opening[1].toLowerCase();
  const start = opening.index;
  const remainder = html.slice(start);
  const tags = new RegExp(`</?${tag}\\b[^>]*>`, 'gi');
  let depth = 0;
  let match;
  while ((match = tags.exec(remainder))) {
    if (match[0].startsWith('</')) depth -= 1;
    else if (!match[0].trimEnd().endsWith('/>')) depth += 1;
    if (depth === 0) {
      const length = match.index + match[0].length;
      return {
        html: html.slice(0, start) + html.slice(start + length),
        element: remainder.slice(0, length),
      };
    }
  }
  return null;
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

export function buildFixtureMatrixRecipe(input = {}) {
  const matrix = input.matrix || createFixtureMatrix(input);
  const artifactsDirectory = input.artifactsDirectory || input.artifacts_directory || '/artifacts/static-site-importer-fixture-matrix';
  const playgroundArtifactsDirectory = input.playgroundArtifactsDirectory || input.playground_artifacts_directory;
  const commandArtifactsDirectory = playgroundArtifactsDirectory || artifactsDirectory;
  const runId = input.runId || input.run_id || `${matrix.id}-${randomUUID()}`;
  const attemptId = input.attemptId || input.attempt_id || randomUUID();
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
  const runtimePresentationEvidence = runtimePresentationEvidenceEnabled(input);
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
          runId,
          attemptId,
          editorOpenEnabled,
          editorValidationEnabled,
          editorValidationOptions,
          visualParityEnabled,
           visualParityRecipeOptions,
           liveWpParityCaptureEnabled,
           runtimePresentationEvidence,
           playgroundArtifactsDirectory,
        })),
      ],
    },
    artifacts: {
      directory: artifactsDirectory,
      typed: matrix.fixtures.map((fixture) => ({
        name: `ssi-materialization-sidecar-${fixture.id}-${sidecarToken(attemptId)}`,
        type: 'static-site-importer/materialization-runtime-sidecar',
        path: path.join(commandArtifactsDirectory, fixture.id, `materialization-receipt--${sidecarToken(attemptId)}.json`),
        required: false,
        parseJson: true,
        contentType: 'application/json',
        payloadSchema: 'static-site-importer/materialization-runtime-sidecar/v1',
        metadata: { fixture_id: fixture.id, run_id: runId, step_id: 'import', attempt_id: attemptId },
      })),
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
    runId,
    attemptId,
    editorOpenEnabled,
    editorValidationEnabled,
    editorValidationOptions,
    visualParityEnabled,
    visualParityRecipeOptions,
    liveWpParityCaptureEnabled,
    runtimePresentationEvidence,
    playgroundArtifactsDirectory,
  } = options;
  const surfaces = selectFixtureSurfaces(fixture, input);

  return [
    ...(runtimePresentationEvidence ? (() => {
      const outputArtifact = runtimePresentationEvidenceArtifactPath(fixture);
      return [
        runtimePresentationEvidenceProbeStep({
          fixture,
          sourceUrl: `${visualParityRecipeOptions.sourceBaseUrl.replace(/\/+$/, '')}/${fixture.id}/${VISUAL_PARITY_SOURCE_SUBDIR}/${fixture.entrypoint}`,
          artifact: buildFixtureArtifact(fixture),
          outputArtifact,
          ...(playgroundArtifactsDirectory ? { outputRuntimePath: path.join(commandArtifactsDirectory, outputArtifact) } : {}),
          viewport: visualParityRecipeOptions.viewport,
        }),
        runtimePresentationEvidenceMergeStep({ fixture, artifactRoot: commandArtifactsDirectory, outputArtifact }),
      ];
    })() : []),
    importFixtureStep(fixture, commandArtifactsDirectory, runId, attemptId),
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

function importFixtureStep(fixture, commandArtifactsDirectory, runId, attemptId) {
  const fixtureDirectory = path.join(commandArtifactsDirectory, fixture.id);
  const sidecarName = `materialization-receipt--${sidecarToken(attemptId)}.json`;
  const dynamicClientAssetsFlag = fixture.allow_unproven_dynamic_client_assets ? ' --allow-unproven-dynamic-client-assets' : '';
  return {
    command: 'wordpress.wp-cli',
    timeoutMs: 15 * 60 * 1000,
    args: [
      `command=static-site-importer validate-artifact --artifact=${shellToken(path.join(fixtureDirectory, 'artifact.json'))} --slug=${shellToken(fixture.id)} --name=${shellToken(fixture.label)} --format=fixture-matrix --receipt-sidecar=${shellToken(path.join(fixtureDirectory, sidecarName))} --receipt-run-id=${shellToken(runId)} --receipt-step-id=import --receipt-attempt-id=${shellToken(attemptId)} --allow-failure${dynamicClientAssetsFlag}`,
    ],
    metadata: fixtureStepMetadata(fixture, 'import', {
      artifact: path.join(commandArtifactsDirectory, fixture.id, 'artifact.json'),
    }),
  };
}

function sidecarToken(value) {
  const token = String(value || '').trim();
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/.test(token)) throw new Error('Fixture matrix sidecar attempt id must be a 1-80 character safe token.');
  return token;
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
  const reference = blocksEnginePhpTransformer.reference || '';
  if (reference && !/^[a-f0-9]{40,64}$/i.test(reference)) {
    throw new Error('SSI dependency override reference must be a 40-64 character hexadecimal immutable reference');
  }

  return {
    dependencyOverlays: [
      {
        kind: 'composer-package',
        package: packageName,
        consumer: importer.slug,
        source: packagePath,
        ...(reference ? { reference } : {}),
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
