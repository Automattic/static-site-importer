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
import { createFixtureMatrix, normalizeFixture, collectFixtureArtifactFiles, pathinfoExtension } from '../fixtures.mjs';
import { editorOpenStep } from './editor-open-step.mjs';
import { editorBlockValidationStep } from './editor-validation-step.mjs';
import { visualParityCompareStep, normalizeVisualParityRecipeOptions } from './visual-parity-step.mjs';
import { liveWpParityCaptureStep, liveWpParityEnabled } from './live-wp-parity-step.mjs';
import { fixtureStepMetadata } from './shared.mjs';
import { selectFixtureSurfaces, summarizeSurfaceCoverage } from './surfaces.mjs';
import { RUNTIME_PRESENTATION_EVIDENCE_ARTIFACT_FILENAME, runtimePresentationEvidenceArtifactPath, runtimePresentationEvidenceEnabled, runtimePresentationEvidenceMergeStep, runtimePresentationEvidenceProbeStep } from '../runtime-presentation-evidence.mjs';

const SOURCE_EXCLUSION_RULES = JSON.parse(
  fs.readFileSync(new URL('../../../includes/source-exclusion-rules.json', import.meta.url), 'utf8'),
).rules;

export function buildFixtureArtifact(fixture, options = {}) {
  const normalized = normalizeFixture(fixture);
  const collected = collectFixtureArtifactFiles(normalized.directory, options);
  const files = collected.files;
  const generatedArtifactMetadata = readGeneratedArtifactMetadata(normalized.directory);
  const sourceExclusions = [];
  // Encode EVERY file as `content_base64`, byte-for-byte matching the real
  // product path. The canonical SSI import request reads
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
      has_js: artifactFiles.some((file) => ['js', 'mjs'].includes(pathinfoExtension(file.path))),
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
      artifact_exclusions: collected.exclusions,
      source_exclusions: sourceExclusions,
    },
  };
}

// This separate input intentionally contains only excluded source files. The
// positive artifact remains content-only while the matrix exercises rejection.
export function buildFixturePolicyArtifact(fixture, options = {}) {
  const normalized = normalizeFixture(fixture);
  const exclusions = collectFixtureArtifactFiles(normalized.directory, options).exclusions;
  return {
    schema: WEBSITE_ARTIFACT_SCHEMA,
    entrypoint: DEFAULT_ENTRYPOINT,
    files: exclusions.map((exclusion) => ({
      path: exclusion.artifact_path,
      source_path: exclusion.source_path,
      content_base64: fs.readFileSync(exclusion.source_path).toString('base64'),
    })),
    source_metadata: { fixture_id: normalized.id, policy_lane: 'negative' },
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
  const files = collectFixtureArtifactFiles(normalized.directory, options).files;
  const sourceRoot = path.join(fixtureDirectory, VISUAL_PARITY_SOURCE_SUBDIR);
  const staged = [];
  for (const file of files) {
    const destination = path.join(sourceRoot, file.relative_path);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    if (isHtmlPath(file.relative_path)) {
      const result = normalizeSourceHtml(fs.readFileSync(file.absolute_path, 'utf8'), `website/${file.relative_path}`);
      fs.writeFileSync(destination, rewriteStagedSourceHtml(result.html, file.relative_path));
      injectDeterministicSourceCss(destination, sourceRoot);
    } else if (isCssPath(file.relative_path)) {
      fs.writeFileSync(destination, rewriteStagedSourceCss(fs.readFileSync(file.absolute_path, 'utf8'), file.relative_path));
      localizeSourceFontStylesheets(destination, sourceRoot);
    } else {
      fs.copyFileSync(file.absolute_path, destination);
    }
    staged.push(file.relative_path);
  }
  return staged;
}

function stagedSourceUrl(url, relativePath) {
  if (!url.startsWith('/') || url.startsWith('//')) return url;
  const prefix = path.posix.relative(path.posix.dirname(relativePath.replace(/\\/g, '/')), '.') || '.';
  return `${prefix}/${url.slice(1)}`;
}

function rewriteStagedSourceHtml(html, relativePath) {
  const attributes = html.replace(/\b(href|src|poster|action)\s*=\s*(["'])(\/(?!\/)[^"']*)\2/gi, (_match, name, quote, url) => (
    `${name}=${quote}${stagedSourceUrl(url, relativePath)}${quote}`
  ));
  const srcsets = attributes.replace(/\bsrcset\s*=\s*(["'])([^"']*)\1/gi, (_match, quote, value) => {
    const rebased = value.replace(/(^|,\s*)(\/(?!\/)[^\s,]+)/g, (_candidate, separator, url) => `${separator}${stagedSourceUrl(url, relativePath)}`);
    return `srcset=${quote}${rebased}${quote}`;
  });
  const styleAttributes = srcsets.replace(/(\sstyle\s*=\s*)(["'])([\s\S]*?)\2/gi, (_match, prefix, quote, css) => (
    `${prefix}${quote}${rewriteStagedSourceCss(css, relativePath)}${quote}`
  ));
  return styleAttributes.replace(/(<style\b[^>]*>)([\s\S]*?)(<\/style>)/gi, (_match, opening, css, closing) => (
    `${opening}${rewriteStagedSourceCss(css, relativePath)}${closing}`
  ));
}

function rewriteStagedSourceCss(css, relativePath) {
  return css
    .replace(/url\(\s*(["']?)(\/(?!\/)[^"')\s]+)\1\s*\)/gi, (_match, quote, url) => `url(${quote}${stagedSourceUrl(url, relativePath)}${quote})`)
    .replace(/(@import\s+)(["'])(\/(?!\/)[^"']+)\2/gi, (_match, prefix, quote, url) => `${prefix}${quote}${stagedSourceUrl(url, relativePath)}${quote}`);
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
  const dependencyOverlays = input.dependencyOverlays || input.dependency_overlays || buildDependencyOverrideSetup(input, importer).dependencyOverlays;
  const phasedDependencyPlanning = input.phasedDependencyPlanning === true || input.phased_dependency_planning === true;
  const dependencyPlanPath = phasedDependencyPlanning
    ? path.join(commandArtifactsDirectory, `dependency-plan--${sidecarToken(attemptId)}.json`)
    : '';
  const dependencyPlanArtifactName = phasedDependencyPlanning ? `ssi-dependency-plan-${sidecarToken(attemptId)}` : '';
  const mounts = normalizeArray(input.mounts);
  const stagedFiles = normalizeArray(input.stagedFiles || input.staged_files);
  const extraPlugins = [
    importer.extraPlugin,
    ...(phasedDependencyPlanning ? [] : hostStageDependencyPlan(input.dependencyPlan || input.dependency_plan)),
    ...normalizeArray(input.extraPlugins || input.extra_plugins),
  ];
  const editorValidationEnabled = input.editorValidation !== false && input.editor_validation !== false;
  const editorOpenEnabled = editorValidationEnabled && input.editorOpen !== false && input.editor_open !== false;
  const editorPersistenceRequired = input.requireSolvedCandidate === true || input.require_solved_candidate === true;
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
  const themeMaterialization = normalizeThemeMaterialization(input.themeMaterialization ?? input.theme_materialization);
  const surfaceCoverage = summarizeSurfaceCoverage(matrix.fixtures, input);

  if (playgroundArtifactsDirectory) {
    for (const fixture of matrix.fixtures) {
      stagedFiles.push({
        source: path.join(artifactsDirectory, fixture.id, 'artifact.json'),
        target: path.join(playgroundArtifactsDirectory, fixture.id, 'artifact.json'),
      });
      stagedFiles.push({
        source: path.join(artifactsDirectory, fixture.id, 'policy-rejection-artifact.json'),
        target: path.join(playgroundArtifactsDirectory, fixture.id, 'policy-rejection-artifact.json'),
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
      ...(dependencyOverlays.length
        ? { dependency_overlays: dependencyOverlays }
        : {}),
    },
    workflow: {
      steps: [
        importer.activationStep,
        ...(phasedDependencyPlanning ? dependencyPlanningSteps(matrix.fixtures, commandArtifactsDirectory, dependencyPlanPath, dependencyPlanArtifactName) : []),
        ...matrix.fixtures.flatMap((fixture) => fixtureWorkflowSteps({
          fixture,
          input,
          commandArtifactsDirectory,
          runId,
          attemptId,
          editorOpenEnabled,
          editorValidationEnabled,
          editorPersistenceRequired,
          editorValidationOptions,
          visualParityEnabled,
          visualParityRecipeOptions,
          liveWpParityCaptureEnabled,
          runtimePresentationEvidence,
          themeMaterialization,
          playgroundArtifactsDirectory,
          dependencyPlan: input.dependencyPlan || input.dependency_plan,
          dependencyPlanPath,
        })),
      ],
    },
    artifacts: {
      directory: artifactsDirectory,
      typed: [
        ...(phasedDependencyPlanning ? [{
          name: dependencyPlanArtifactName,
          type: 'static-site-importer/runtime-dependency-plan',
          path: dependencyPlanPath,
          required: true,
          parseJson: true,
          contentType: 'application/json',
          payloadSchema: 'static-site-importer/runtime-dependency-plan/v1',
          metadata: { run_id: runId, step_id: 'dependency-plan', attempt_id: attemptId },
        }] : []),
        ...matrix.fixtures.map((fixture) => ({
        name: `ssi-materialization-sidecar-${fixture.id}-${sidecarToken(attemptId)}`,
        type: 'static-site-importer/materialization-runtime-sidecar',
        path: path.join(commandArtifactsDirectory, fixture.id, `materialization-receipt--${sidecarToken(attemptId)}.json`),
        required: false,
        parseJson: true,
        contentType: 'application/json',
        payloadSchema: 'static-site-importer/materialization-runtime-sidecar/v2',
        metadata: { fixture_id: fixture.id, run_id: runId, step_id: 'import', attempt_id: attemptId },
        })),
      ],
    },
    metadata: {
      surface_coverage: surfaceCoverage,
      runtime_cost_warnings: surfaceCoverage.enabled ? [surfaceCoverageRuntimeWarning(surfaceCoverage)] : [],
      provider_dependency_setup: [],
    },
  };
}

export function normalizeFixtureMatrixDependencyOverlays(input = {}) {
  return buildDependencyOverrideSetup(input, normalizeStaticSiteImporterPlugin(input)).dependencyOverlays;
}

function surfaceCoverageRuntimeWarning(surfaceCoverage) {
  return {
    code: 'surface_coverage_runtime_cost',
    message: `Surface coverage is enabled: ${surfaceCoverage.total_surface_count} browser surfaces will run across ${surfaceCoverage.fixture_count} fixtures (${surfaceCoverage.extra_surfaces_per_fixture} extra per fixture, max ${surfaceCoverage.max_extra_surfaces}).`,
  };
}

function dependencyPlanningSteps(fixtures, commandArtifactsDirectory, dependencyPlanPath, dependencyPlanArtifactName) {
  const plans = fixtures.map((fixture) => ({
    fixture,
    path: path.join(commandArtifactsDirectory, fixture.id, 'dependency-plan.json'),
  }));
  const encodedPlans = Buffer.from(JSON.stringify(plans.map(({ fixture, path: planPath }) => ({ fixture_id: fixture.id, path: planPath }))), 'utf8').toString('base64');
  const mergeCode = `$plans = json_decode(base64_decode('${encodedPlans}'), true);
$entries = array();
$artifact_hashes = array();
foreach ($plans as $planned) {
	$contents = file_get_contents((string) $planned['path']);
	$plan = false === $contents ? null : json_decode($contents, true);
	if (!is_array($plan) || 'static-site-importer/runtime-dependency-plan/v1' !== ($plan['schema'] ?? '') || !isset($plan['entries']) || !is_array($plan['entries'])) { WP_CLI::error('Fixture dependency plan is malformed.'); }
	$artifact_hashes[] = (string) ($plan['artifact_sha256'] ?? '');
	foreach ($plan['entries'] as $entry) {
		if (!is_array($entry) || 'wordpress.org-plugin' !== ($entry['source_kind'] ?? '') || 'required' !== ($entry['activation'] ?? '') || !preg_match('/^[a-z0-9][a-z0-9-_]*$/i', (string) ($entry['slug'] ?? '')) || !preg_match('/^[a-z0-9][a-z0-9-_]*\\/[A-Za-z0-9._-]+\\.php$/', (string) ($entry['plugin_entrypoint'] ?? ''))) { WP_CLI::error('Fixture dependency plan contains an unsupported package artifact.'); }
		$key = (string) $entry['source_kind'] . ':' . (string) $entry['slug'] . ':' . (string) $entry['plugin_entrypoint'];
		if (!isset($entries[$key])) { $entries[$key] = $entry; $entries[$key]['fixture_ids'] = array(); }
		$entries[$key]['fixture_ids'][] = (string) $planned['fixture_id'];
	}
}
sort($artifact_hashes, SORT_STRING);
ksort($entries, SORT_STRING);
foreach ($entries as &$entry) { $entry['fixture_ids'] = array_values(array_unique($entry['fixture_ids'])); sort($entry['fixture_ids'], SORT_STRING); }
unset($entry);
$merged = array('schema' => 'static-site-importer/runtime-dependency-plan/v1', 'artifact_sha256' => implode(',', $artifact_hashes), 'entries' => array_values($entries));
$json = wp_json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (false === $json || false === file_put_contents(${JSON.stringify(dependencyPlanPath)}, $json . "\n")) { WP_CLI::error('Failed to persist merged fixture dependency plan.'); }
WP_CLI::line($json);`;
  const encodedMergeCode = Buffer.from(mergeCode, 'utf8').toString('base64');
  return [
    ...plans.map(({ fixture, path: planPath }) => ({
      command: 'wordpress.wp-cli',
      args: [`command=static-site-importer plan-artifact-dependencies --artifact=${shellToken(path.join(commandArtifactsDirectory, fixture.id, 'artifact.json'))} --slug=${shellToken(fixture.id)} --name=${shellToken(fixture.label)} --output=${shellToken(planPath)}`],
      metadata: fixtureStepMetadata(fixture, 'dependency-plan', { artifact: path.join(commandArtifactsDirectory, fixture.id, 'artifact.json'), output: planPath }),
    })),
    {
      command: 'wordpress.wp-cli',
      args: [`command=eval ${shellToken(`eval(base64_decode('${encodedMergeCode}'));`)}`],
      pluginInput: {
        artifact: dependencyPlanArtifactName,
        packages: {
          resolver: 'wordpress.org-latest-stable',
          items: '/entries',
          map: { slug: '/slug', pluginFile: '/plugin_entrypoint' },
        },
      },
      metadata: { phase: 'dependency-plan-merge', output: dependencyPlanPath, fixture_count: fixtures.length },
    },
  ];
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
    editorPersistenceRequired,
    editorValidationOptions,
    visualParityEnabled,
    visualParityRecipeOptions,
    liveWpParityCaptureEnabled,
    runtimePresentationEvidence,
    themeMaterialization,
    playgroundArtifactsDirectory,
    dependencyPlan,
    dependencyPlanPath,
  } = options;
  const surfaces = selectFixtureSurfaces(fixture, input);

  return [
    ...(runtimePresentationEvidence ? (() => {
      const artifact = buildFixtureArtifact(fixture);
      const evidenceSurfaces = surfaces.map((surface) => ({
        surface,
        outputArtifact: runtimePresentationEvidenceArtifactPath(fixture, surface),
      }));
      return [
        ...evidenceSurfaces.map(({ surface, outputArtifact }) => runtimePresentationEvidenceProbeStep({
          fixture,
          surface,
          sourceUrl: `${visualParityRecipeOptions.sourceBaseUrl.replace(/\/+$/, '')}/${fixture.id}/${VISUAL_PARITY_SOURCE_SUBDIR}/${surface.source_entry}`,
          artifact,
          outputArtifact,
          ...(playgroundArtifactsDirectory ? { outputRuntimePath: path.join(commandArtifactsDirectory, outputArtifact) } : {}),
          viewport: visualParityRecipeOptions.viewport,
        })),
        runtimePresentationEvidenceMergeStep({ fixture, artifactRoot: commandArtifactsDirectory, outputArtifacts: evidenceSurfaces.map(({ outputArtifact }) => outputArtifact) }),
      ];
    })() : []),
    prepareFixtureDependenciesStep(fixture, commandArtifactsDirectory, runId, attemptId, runtimePresentationEvidence ? RUNTIME_PRESENTATION_EVIDENCE_ARTIFACT_FILENAME : 'artifact.json'),
    ...(fs.existsSync(fixture.directory) && collectFixtureArtifactFiles(fixture.directory).exclusions.length ? [negativePolicyStep(fixture, commandArtifactsDirectory)] : []),
    ...(dependencyPlanPath ? [providerReadinessFromPlanStep(fixture, dependencyPlanPath)] : providerReadinessSteps(fixture, dependencyPlan)),
    importFixtureStep(fixture, commandArtifactsDirectory, runId, attemptId, runtimePresentationEvidence ? RUNTIME_PRESENTATION_EVIDENCE_ARTIFACT_FILENAME : 'artifact.json', themeMaterialization),
    materializationSidecarReadbackStep(fixture, commandArtifactsDirectory, attemptId),
    ...providerSubmissionEvidenceSteps(fixture, commandArtifactsDirectory, attemptId),
    ...(visualParityEnabled ? [materializeVisualSourceFontsStep(fixture, commandArtifactsDirectory)] : []),
    ...(input.svgFontEvidence || input.svg_font_evidence ? [svgFontEvidenceStep(fixture)] : []),
    woocommerceOnboardingSuppressionStep(fixture),
    ...surfaces.flatMap((surface, index) => [
      materializedSurfaceIdentityStep(fixture, editorSurface(surface)),
      ...(editorOpenEnabled ? [editorOpenStep({
        fixture,
        surface: editorSurface(surface),
        ...editorValidationOptions,
        artifactPrefix: editorArtifactPrefix(fixture, surface),
      })] : []),
      ...(editorValidationEnabled && !(editorPersistenceRequired && index === 0)
        ? [editorBlockValidationStep({ fixture, surface: editorSurface(surface), ...editorValidationOptions })]
        : []),
      ...(visualParityEnabled && index === 0 ? [visualParityDeterministicCssStep(fixture)] : []),
      ...(visualParityEnabled ? [visualParityCompareStep({ fixture, surface, ...visualParityRecipeOptions })] : []),
    ]),
    ...(editorPersistenceRequired ? editorPersistenceSteps(fixture) : []),
    ...(liveWpParityCaptureEnabled ? [liveWpParityCaptureStep({ fixture, ...input })] : []),
  ];
}

function negativePolicyStep(fixture, commandArtifactsDirectory) {
  const artifactPath = path.join(commandArtifactsDirectory, fixture.id, 'policy-rejection-artifact.json');
  return {
    command: 'wordpress.wp-cli',
    // Keep the expected rejection inside SSI's public validation boundary. Its
    // failure payload is classified separately by matrix intake, not as a
    // positive-lane import failure.
    args: [`command=static-site-importer validate-artifact --artifact=${shellToken(artifactPath)} --slug=${shellToken(fixture.id)} --name=${shellToken(`${fixture.label} policy rejection`)} --format=fixture-matrix --allow-failure`],
    metadata: fixtureStepMetadata(fixture, 'negative-policy', {
      artifact: artifactPath,
      classification: 'expected_policy_rejection',
      expected_error_code: 'static_site_importer_executable_source_rejected',
    }),
  };
}

function editorPersistenceSteps(fixture) {
  const marker = `ssi-solved-editability-${fixture.id}`;
  const postSaveValidation = editorBlockValidationStep({ fixture, target: 'front-page' });
  const verifyCode = `$post_id = (int) get_option('page_on_front');
$content = $post_id > 0 ? (string) get_post_field('post_content', $post_id) : '';
$persisted = $post_id > 0 && str_contains($content, '${marker}');
WP_CLI::line(wp_json_encode(array('schema' => 'static-site-importer/editor-persistence/v1', 'post_id' => $post_id, 'marker' => '${marker}', 'persisted' => $persisted)));
if (!$persisted) { WP_CLI::error('Gutenberg edit did not persist after save and reload.'); }`;
  const encodedVerifyCode = Buffer.from(verifyCode, 'utf8').toString('base64');
  return [
    {
      command: 'wordpress.editor-actions',
      args: [
        'target=front-page',
        'capture=steps,errors,editor-state,editor-validity',
        'wait-timeout=45s',
        'step-timeout=45s',
        'timeout=120s',
        `steps-json=${JSON.stringify([
          { kind: 'insertBlock', name: 'core/paragraph', content: marker, select: true },
          { kind: 'selectBlock', index: 0 },
          { kind: 'moveBlock', index: 0, position: 1 },
          { kind: 'savePost', marker },
          { kind: 'reload' },
          { kind: 'inspectState' },
        ])}`,
      ],
      metadata: fixtureStepMetadata(fixture, 'editor-persistence'),
    },
    {
      command: 'wordpress.wp-cli',
      args: [`command=eval ${shellToken(`eval(base64_decode('${encodedVerifyCode}'));`)}`],
      metadata: fixtureStepMetadata(fixture, 'editor-persistence-verify'),
    },
    {
      ...postSaveValidation,
      allowFailure: false,
      metadata: fixtureStepMetadata(fixture, 'editor-persistence-validation', { target: 'front-page' }),
    },
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
  const postSlug = String(surface.target || '').split('/').filter(Boolean).join('/');
  return { ...surface, post_type: 'page', post_slug: postSlug };
}

// Resolve the exact post immediately after import. The editor commands retain a
// portable path target, while this receipt supplies the materialized post ID and
// prevents an unavailable secondary surface from falling through to another page.
function materializedSurfaceIdentityStep(fixture, surface) {
  const sourceEntry = String(surface.source_entry || '');
  const route = String(surface.target || '');
  const postType = String(surface.post_type || 'page');
  const postSlug = String(surface.post_slug || '');
  const lookup = surface.id === 'front-page'
    ? "$post_id = 'page' === get_option('show_on_front') ? (int) get_option('page_on_front') : 0; $post = $post_id > 0 ? get_post($post_id) : null;"
    : `$post = get_page_by_path(${JSON.stringify(postSlug)}, OBJECT, ${JSON.stringify(postType)}); $post_id = $post instanceof WP_Post ? (int) $post->ID : 0;`;
  const code = `${lookup}
$available = $post instanceof WP_Post && (int) $post->ID > 0;
$result = array(
  'schema' => 'static-site-importer/materialized-surface-identity/v1',
  'fixture_id' => ${JSON.stringify(fixture.id)},
  'surface_id' => ${JSON.stringify(surface.id)},
  'status' => $available ? 'available' : 'unavailable',
  'reason' => $available ? '' : 'materialized_post_missing',
  'source_entry' => ${JSON.stringify(sourceEntry)},
  'route' => ${JSON.stringify(route)},
  'post_id' => $available ? (string) $post->ID : '',
  'post_type' => $available ? (string) $post->post_type : ${JSON.stringify(postType)},
  'post_slug' => $available ? (string) $post->post_name : ${JSON.stringify(postSlug)},
);
WP_CLI::line(wp_json_encode($result, JSON_UNESCAPED_SLASHES));
if (!$available) { WP_CLI::error('Materialized editor surface is unavailable.'); }`;
  const encoded = Buffer.from(code, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${encoded}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'materialized-surface-identity', {
      surface_id: surface.id,
      source_entry: sourceEntry,
      route,
      post_type: postType,
      ...(postSlug ? { post_slug: postSlug } : {}),
    }),
  };
}

function editorArtifactPrefix(fixture, surface) {
  if (surface.id === 'front-page') {
    return `files/browser/editor-open/${fixture.id}`;
  }
  return `files/browser/editor-open/${fixture.id}/${surface.id}`;
}

function importFixtureStep(fixture, commandArtifactsDirectory, runId, attemptId, artifactFilename = 'artifact.json', themeMaterialization = 'block') {
  const fixtureDirectory = path.join(commandArtifactsDirectory, fixture.id);
  const sidecarName = `materialization-receipt--${sidecarToken(attemptId)}.json`;
  const dynamicClientAssetsFlag = fixture.allow_unproven_dynamic_client_assets ? ' --allow-unproven-dynamic-client-assets' : '';
  const clientScriptFlags = isolatedPreviewClientScriptFlags(runId, fixture.id);
  const themeMaterializationFlag = themeMaterialization === 'classic' ? ' --theme-materialization=classic' : '';
  return {
    command: 'wordpress.wp-cli',
    args: [
      `command=static-site-importer validate-artifact --artifact=${shellToken(path.join(fixtureDirectory, artifactFilename))} --slug=${shellToken(fixture.id)} --name=${shellToken(fixture.label)} --lifecycle-receipt=${shellToken(path.join(fixtureDirectory, `dependency-receipt--${sidecarToken(attemptId)}.json`))} --format=fixture-matrix --receipt-sidecar=${shellToken(path.join(fixtureDirectory, sidecarName))} --receipt-run-id=${shellToken(runId)} --receipt-step-id=import --receipt-attempt-id=${shellToken(attemptId)} --allow-failure${themeMaterializationFlag}${dynamicClientAssetsFlag}${clientScriptFlags}`,
    ],
    metadata: fixtureStepMetadata(fixture, 'import', {
      artifact: path.join(commandArtifactsDirectory, fixture.id, artifactFilename),
    }),
  };
}

function normalizeThemeMaterialization(value) {
  const strategy = value === undefined || value === null || value === '' ? 'block' : String(value);
  if (!['block', 'classic'].includes(strategy)) {
    throw new Error('themeMaterialization must be block or classic.');
  }
  return strategy;
}

function materializationSidecarReadbackStep(fixture, commandArtifactsDirectory, attemptId) {
  const sidecarPath = path.join(commandArtifactsDirectory, fixture.id, `materialization-receipt--${sidecarToken(attemptId)}.json`);
  const code = `$path = ${JSON.stringify(sidecarPath)};
if (!is_file($path) || !is_readable($path)) { WP_CLI::error('Materialization sidecar is unavailable.'); }
$size = filesize($path);
if (false === $size || $size > 32768) { WP_CLI::error('Materialization sidecar exceeds its transport bound.'); }
$contents = file_get_contents($path);
$payload = false === $contents ? null : json_decode($contents, true);
if (!is_array($payload) || !in_array($payload['schema'] ?? '', array('static-site-importer/materialization-runtime-sidecar/v1', 'static-site-importer/materialization-runtime-sidecar/v2'), true)) { WP_CLI::error('Materialization sidecar is malformed.'); }
WP_CLI::line(wp_json_encode($payload, JSON_UNESCAPED_SLASHES));`;
  const encoded = Buffer.from(code, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${encoded}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'materialization-sidecar-readback', { attempt_id: attemptId }),
  };
}

function prepareFixtureDependenciesStep(fixture, commandArtifactsDirectory, runId, attemptId, artifactFilename = 'artifact.json') {
  const fixtureDirectory = path.join(commandArtifactsDirectory, fixture.id);
  return {
    command: 'wordpress.wp-cli',
    args: [`command=static-site-importer prepare-artifact-dependencies --artifact=${shellToken(path.join(fixtureDirectory, artifactFilename))} --slug=${shellToken(fixture.id)} --name=${shellToken(fixture.label)} --receipt=${shellToken(path.join(fixtureDirectory, `dependency-receipt--${sidecarToken(attemptId)}.json`))}${isolatedPreviewClientScriptFlags(runId, fixture.id)}`],
    metadata: fixtureStepMetadata(fixture, 'dependency-prepare', { artifact: path.join(commandArtifactsDirectory, fixture.id, artifactFilename) }),
  };
}

function isolatedPreviewClientScriptFlags(runId, fixtureId) {
  const provenance = `fixture-matrix:${runId}:${fixtureId}`;
  return ` --client-script-policy=isolated_preview --client-script-provenance=${shellToken(provenance)} --client-script-isolated`;
}

function providerSubmissionEvidenceSteps(fixture, commandArtifactsDirectory, attemptId) {
  const requirements = (fixture.provider_submissions || []).filter((row) => row?.required === true);
  if (requirements.length === 0) return [];
  const fixtureDirectory = path.join(commandArtifactsDirectory, fixture.id);
  const config = Buffer.from(JSON.stringify({
    fixture_id: fixture.id,
    requirements,
    sidecar_path: path.join(fixtureDirectory, `materialization-receipt--${sidecarToken(attemptId)}.json`),
    output_path: path.join(fixtureDirectory, 'provider-submission-evidence.json'),
    output_artifact: `${fixture.id}/provider-submission-evidence.json`,
  }), 'utf8').toString('base64');
  const code = `$config = json_decode(base64_decode('${config}'), true);
if (!is_array($config) || !class_exists('Static_Site_Importer_Provider_Submission_Evidence')) { WP_CLI::error('Provider submission evidence is unavailable.'); }
$evidence = Static_Site_Importer_Provider_Submission_Evidence::verify_runtime($config);
WP_CLI::line(wp_json_encode(array('provider_submission_evidence' => $evidence), JSON_UNESCAPED_SLASHES));`;
  return [{
    command: 'wordpress.wp-cli',
    allowFailure: true,
    args: [`command=eval ${shellToken(`eval(base64_decode('${Buffer.from(code, 'utf8').toString('base64')}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'provider-submission-evidence', {
      evidence_schema: 'static-site-importer/provider-submission-evidence/v1',
      output_artifact: `${fixture.id}/provider-submission-evidence.json`,
    }),
  }];
}

function providerReadinessFromPlanStep(fixture, dependencyPlanPath) {
  const code = `$contents = file_get_contents(${JSON.stringify(dependencyPlanPath)});
$plan = false === $contents ? null : json_decode($contents, true);
if (!is_array($plan) || 'static-site-importer/runtime-dependency-plan/v1' !== ($plan['schema'] ?? '') || !isset($plan['entries']) || !is_array($plan['entries'])) { WP_CLI::error('Merged fixture dependency plan is unavailable.'); }
$required_blocks = array(); $required_classes = array(); $preparation_callbacks = array();
foreach ($plan['entries'] as $entry) {
	if (!is_array($entry) || (isset($entry['fixture_ids']) && is_array($entry['fixture_ids']) && !in_array(${JSON.stringify(fixture.id)}, $entry['fixture_ids'], true))) { continue; }
	$readiness = isset($entry['provider_readiness']) && is_array($entry['provider_readiness']) ? $entry['provider_readiness'] : array();
	foreach ($readiness['required_block_types'] ?? array() as $name) { if (is_string($name)) { $required_blocks[$name] = $name; } }
	foreach ($readiness['required_classes'] ?? array() as $name) { if (is_string($name)) { $required_classes[$name] = $name; } }
	$callback = $readiness['preparation_callback'] ?? null;
	if (is_array($callback) && 2 === count($callback) && is_string($callback[0]) && is_string($callback[1])) { $preparation_callbacks[implode('::', $callback)] = $callback; }
}
$preparation_errors = array();
foreach ($preparation_callbacks as $callback_name => $callback) {
	if (!is_callable($callback)) { $preparation_errors[] = array('callback' => $callback_name, 'result' => 'invalid'); continue; }
	$prepared = call_user_func($callback);
	if (is_wp_error($prepared)) { $preparation_errors[] = array('callback' => $callback_name, 'result' => 'wp_error', 'code' => (string) $prepared->get_error_code()); }
	elseif (false === $prepared) { $preparation_errors[] = array('callback' => $callback_name, 'result' => 'false'); }
}
$blocks = WP_Block_Type_Registry::get_instance();
$missing_blocks = array_values(array_filter(array_values($required_blocks), static fn($name) => !$blocks->is_registered($name)));
$missing_classes = array_values(array_filter(array_values($required_classes), static fn($name) => !class_exists($name)));
$ready = empty($preparation_errors) && empty($missing_blocks) && empty($missing_classes);
$result = array('schema' => 'static-site-importer/provider-readiness/v1', 'ready' => $ready, 'preparation_errors' => $preparation_errors, 'missing_block_types' => $missing_blocks, 'missing_classes' => $missing_classes);
WP_CLI::line(wp_json_encode($result, JSON_UNESCAPED_SLASHES));
if (!$ready) { WP_CLI::error('Provider dependency readiness failed.'); }`;
  const encoded = Buffer.from(code, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${encoded}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'provider-readiness', { dependency_plan: dependencyPlanPath }),
  };
}

function providerReadinessSteps(fixture, plan) {
  const requirements = providerReadinessRequirements(fixture.id, plan);
  if (!requirements) return [];
  const encodedBlocks = Buffer.from(JSON.stringify(requirements.required_block_types), 'utf8').toString('base64');
  const encodedClasses = Buffer.from(JSON.stringify(requirements.required_classes), 'utf8').toString('base64');
  const encodedCallbacks = Buffer.from(JSON.stringify(requirements.preparation_callbacks), 'utf8').toString('base64');
  const code = `$required_blocks = json_decode(base64_decode('${encodedBlocks}'), true); $required_classes = json_decode(base64_decode('${encodedClasses}'), true); $preparation_callbacks = json_decode(base64_decode('${encodedCallbacks}'), true); $preparation_errors = array(); foreach ($preparation_callbacks as $callback) { if (!is_array($callback) || 2 !== count($callback) || !is_string($callback[0]) || !is_string($callback[1]) || !is_callable($callback)) { array_push($preparation_errors, array('callback'=>'invalid_callback','result'=>'invalid')); continue; } $callback_name = implode('::', $callback); $prepared = call_user_func($callback); if (function_exists('is_wp_error') && is_wp_error($prepared)) { $error_data = $prepared->get_error_data(); $bounded_data = array(); if (is_array($error_data)) { if (isset($error_data['missing']) && is_array($error_data['missing'])) { $bounded_data['missing'] = array_slice(array_values($error_data['missing']), 0, 20); } if (isset($error_data['details']) && is_array($error_data['details'])) { $bounded_data['details'] = array_slice($error_data['details'], 0, 20, true); } } array_push($preparation_errors, array('callback'=>$callback_name,'result'=>'wp_error','code'=>(string) $prepared->get_error_code(),'data'=>$bounded_data)); } elseif (false === $prepared) { array_push($preparation_errors, array('callback'=>$callback_name,'result'=>'false')); } } $blocks = WP_Block_Type_Registry::get_instance(); $missing_blocks = array(); foreach ($required_blocks as $name) { if (!$blocks->is_registered($name)) { array_push($missing_blocks, $name); } } $missing_classes = array(); foreach ($required_classes as $name) { if (!class_exists($name)) { array_push($missing_classes, $name); } } $ready = empty($preparation_errors) && empty($missing_blocks) && empty($missing_classes); WP_CLI::line(wp_json_encode(array('schema'=>'static-site-importer/provider-readiness/v1','ready'=>$ready,'preparation_errors'=>$preparation_errors,'missing_block_types'=>$missing_blocks,'missing_classes'=>$missing_classes,'required_block_types'=>$required_blocks,'required_classes'=>$required_classes))); if (!$ready) { WP_CLI::error('Required provider requirements are not available.'); }`;
  const transportSafeCode = `eval(base64_decode('${Buffer.from(code, 'utf8').toString('base64')}'));`;
  return [{ command: 'wordpress.wp-cli', args: [`command=eval ${shellToken(transportSafeCode)}`], metadata: fixtureStepMetadata(fixture, 'provider-readiness') }];
}

function providerReadinessRequirements(fixtureId, plan) {
  const entries = Array.isArray(plan?.entries) ? plan.entries : [];
  const requiredBlockTypes = new Set();
  const requiredClasses = new Set();
  const preparationCallbacks = new Map();
  for (const entry of entries) {
    if (Array.isArray(entry.fixture_ids) && !entry.fixture_ids.includes(fixtureId)) continue;
    for (const name of entry?.provider_readiness?.required_block_types || []) requiredBlockTypes.add(name);
    for (const name of entry?.provider_readiness?.required_classes || []) requiredClasses.add(name);
    const callback = entry?.provider_readiness?.preparation_callback;
    if (Array.isArray(callback) && callback.length === 2 && callback.every((part) => typeof part === 'string')) {
      preparationCallbacks.set(JSON.stringify(callback), callback);
    }
  }
  if (requiredBlockTypes.size === 0 && requiredClasses.size === 0) return null;
  return {
    required_block_types: [...requiredBlockTypes].sort(),
    required_classes: [...requiredClasses].sort(),
    preparation_callbacks: [...preparationCallbacks.values()],
  };
}

// Convert a registry-derived plan to the generic Codebox source contract. The
// matrix has no provider-specific mapping: only SSI's discovery plan supplies
// package names and entrypoints.
export function hostStageDependencyPlan(plan) {
  if (plan === undefined || plan === null) return [];
  if (!plan || plan.schema !== 'static-site-importer/runtime-dependency-plan/v1' || !Array.isArray(plan.entries)) {
    throw new Error('SSI dependency plan must use static-site-importer/runtime-dependency-plan/v1.');
  }
  return plan.entries.map((entry) => {
    if (entry?.source_kind !== 'wordpress.org-plugin' || !/^[a-z0-9][a-z0-9-_]*$/i.test(entry.slug || '') || !/^[a-z0-9][a-z0-9-_]*\/[A-Za-z0-9._-]+\.php$/.test(entry.plugin_entrypoint || '') || entry.activation !== 'required') {
      throw new Error('SSI dependency plan contains an unsupported package artifact.');
    }
    if (!entry.host_resolution || !/^[a-f0-9]{64}$/i.test(entry.host_resolution.archive_sha256 || '') || !entry.host_resolution.archive_path || !entry.host_resolution.version) {
      throw new Error('SSI dependency plan requires an immutable host package resolution receipt.');
    }
    return {
      source: entry.host_resolution.archive_path,
      slug: entry.slug,
      pluginFile: entry.plugin_entrypoint,
      sha256: entry.host_resolution.archive_sha256,
      activate: true,
      metadata: {
        dependency_plan_schema: plan.schema,
        artifact_sha256: plan.artifact_sha256,
        provenance: entry.provenance,
        resolution_policy: entry.version_policy,
        host_resolution: entry.host_resolution,
      },
    };
  });
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

function materializeVisualSourceFontsStep(fixture, commandArtifactsDirectory) {
  const sourceRoot = path.join(commandArtifactsDirectory, fixture.id, VISUAL_PARITY_SOURCE_SUBDIR);
  const code = `$source_root = ${JSON.stringify(sourceRoot)};
$source_assets = $source_root . '/assets';
$theme_assets = get_stylesheet_directory() . '/assets';
$stylesheet = $theme_assets . '/css/embedded-fonts.css';
if (is_file($stylesheet)) {
\twp_mkdir_p($source_assets . '/css');
\twp_mkdir_p($source_assets . '/fonts');
\tif (!copy($stylesheet, $source_assets . '/css/embedded-fonts.css')) { WP_CLI::error('Could not stage the materialized local font stylesheet for source capture.'); }
\tforeach (glob($theme_assets . '/fonts/*') ?: array() as $font) {
\t\tif (is_file($font) && !copy($font, $source_assets . '/fonts/' . basename($font))) { WP_CLI::error('Could not stage a materialized local font asset for source capture.'); }
\t}
}`;
  const encoded = Buffer.from(code, 'utf8').toString('base64');
  return {
    command: 'wordpress.wp-cli',
    args: [`command=eval ${shellToken(`eval(base64_decode('${encoded}'));`)}`],
    metadata: fixtureStepMetadata(fixture, 'visual-font-setup', {
      source_root: sourceRoot,
      source_relationship: 'copied-from-generated-theme-font-assets',
    }),
  };
}

function isHtmlPath(filePath) {
  return /\.html?$/i.test(filePath);
}

function isCssPath(filePath) {
  return /\.css$/i.test(filePath);
}

function injectDeterministicSourceCss(filePath, sourceRoot) {
  const html = fs.readFileSync(filePath, 'utf8');
  const style = `<style data-ssi-visual-parity-deterministic>\n${VISUAL_PARITY_DETERMINISTIC_CSS}\n</style>`;
  const fontUrl = localSourceFontStylesheetUrl(filePath, sourceRoot);
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
    const hasDocumentAnimation = [svg, ...svg.querySelectorAll('*')]
      .some((element) => getComputedStyle(element).animationName !== 'none');
    if (hasDocumentAnimation) {
      const animations = svg.getAnimations({ subtree: true });
      const animatedStyles = new Map();
      for (const animation of animations) {
        animation.currentTime = 0;
        animation.pause();
        const target = animation.effect && animation.effect.target;
        if (!target) continue;
        const properties = animatedStyles.get(target) || new Set();
        for (const frame of animation.effect.getKeyframes()) {
          for (const property of Object.keys(frame)) {
            if (!['offset', 'computedOffset', 'easing', 'composite'].includes(property)) properties.add(property);
          }
        }
        animatedStyles.set(target, properties);
      }
      for (const [target, properties] of animatedStyles) {
        const computed = getComputedStyle(target);
        const values = Array.from(properties, (property) => {
          const cssProperty = property.startsWith('--') ? property : property.replace(/[A-Z]/g, (letter) => '-' + letter.toLowerCase());
          return [cssProperty, computed.getPropertyValue(cssProperty)];
        });
        target.style.setProperty('animation', 'none', 'important');
        for (const [property, value] of values) target.style.setProperty(property, value, 'important');
      }
      return;
    }
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
  const withoutRemoteFontHints = html.replace(/<link\b(?=[^>]*\brel=["'][^"']*\b(?:preconnect|dns-prefetch)\b[^"']*["'])(?=[^>]*\bhref=["']https:\/\/fonts\.(?:googleapis|gstatic)\.com(?:\/[^"']*)?["'])[^>]*>\s*/gi, '');
  const withLocalFonts = withoutRemoteFontHints.replace(/<link\b(?=[^>]*\bhref=["']https:\/\/fonts\.googleapis\.com\/[^"']+["'])[^>]*>/gi, fontStylesheet);
  const withLocalizedImports = rewriteSourceFontStylesheetUrls(withLocalFonts, fontUrl);
  const withStyles = /<\/head>/i.test(withLocalizedImports)
    ? withLocalizedImports.replace(/<\/head>/i, `${style}\n</head>`)
    : `${style}\n${withLocalizedImports}`;
  const updated = /<\/body>/i.test(withStyles)
    ? withStyles.replace(/<\/body>/i, `${svgNormalization}\n</body>`)
    : `${withStyles}\n${svgNormalization}`;
  fs.writeFileSync(filePath, updated);
}

function localizeSourceFontStylesheets(filePath, sourceRoot) {
  const css = fs.readFileSync(filePath, 'utf8');
  const fontUrl = localSourceFontStylesheetUrl(filePath, sourceRoot);
  fs.writeFileSync(filePath, rewriteSourceFontStylesheetUrls(css, fontUrl));
}

function localSourceFontStylesheetUrl(filePath, sourceRoot) {
  const target = path.join(sourceRoot, 'assets', 'css', 'embedded-fonts.css');
  const relative = path.relative(path.dirname(filePath), target).replace(/\\/g, '/');
  return relative.startsWith('.') ? relative : `./${relative}`;
}

function rewriteSourceFontStylesheetUrls(content, fontUrl) {
  return content
    .replace(/(["'])https:\/\/fonts\.googleapis\.com\/(?:css|css2)\?[^"']+\1/gi, (_match, quote) => `${quote}${fontUrl}${quote}`)
    .replace(/https:\/\/fonts\.googleapis\.com\/(?:css|css2)\?[^"'()<>\s;]+/gi, fontUrl);
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

function woocommerceOnboardingSuppressionStep(fixture) {
  return {
    command: 'wordpress.wp-cli',
    allowFailure: true,
    args: [
      `command=eval ${shellToken("delete_transient('_wc_activation_redirect'); update_option('woocommerce_onboarding_profile', array('completed' => true, 'skipped' => true)); update_option('woocommerce_task_list_hidden', 'yes'); update_user_option(1, 'persisted_preferences', array_replace_recursive((array) get_user_option('persisted_preferences', 1), array('core/edit-post' => array('welcomeGuide' => false, 'welcomeGuideTemplate' => false))));")}`,
    ],
    metadata: fixtureStepMetadata(fixture, 'editor-preflight', {
      setup: 'suppress-editor-onboarding',
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
