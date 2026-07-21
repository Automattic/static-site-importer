// Run-result intake for the Static Site Importer fixture matrix: reads WP
// Codebox runtime payloads + per-fixture artifact files back out, normalizes
// them into fixture results, and threads the per-concern collectors together.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).
/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

/**
 * Internal dependencies
 */
import {
  normalizeArray,
  objectValue,
  numberValue,
  firstString,
  compactObject,
  mergeObjects,
  diagnosticMessage,
  requiredString,
  readJsonFileIfExists,
  artifactRef,
  parseJsonPayloadsFromText,
} from '../shared/utils.mjs';
import { createFixtureMatrix } from '../fixtures.mjs';
import { dedupeDiagnostics } from '../findings.mjs';
import { collectQualityMetrics, collectBlockComposition } from './quality-metrics.mjs';
import { collectEditorValidationDiagnostics, collectEditorValidation } from './editor-validation.mjs';
import {
  collectVisualParityDiagnostics,
  collectVisualParityArtifacts,
  normalizeVisualParityGateOptions,
} from './visual-parity.mjs';
import { VISUAL_TIMEOUT_KIND } from '../shared/constants.mjs';
import {
  collectLiveWpParity,
  normalizeLiveWpParityCollectorOptions,
} from './live-wp-parity.mjs';
import { normalizeFixtureMatrixResult, normalizeFixtureResult } from '../result.mjs';

export function collectFixtureMatrixRunResults(input = {}) {
  const matrix = input.matrix || createFixtureMatrix(input);
  const outputDirectory = requiredString(input.outputDirectory || input.output_directory, 'outputDirectory');
  const codeboxOutput = input.codeboxOutput || input.codebox_output || readJsonFileIfExists(input.outputFile || input.output_file) || null;
  const codeboxError = input.codeboxError || input.codebox_error || null;
  const runtimePayloads = collectRuntimePayloads(codeboxOutput);
  runtimePayloads.push(...collectChildCommandFailurePayloads(input.childCommandFailures || input.child_command_failures || codeboxOutput?.child_command_failures || codeboxOutput?.runtime?.child_command_failures));
  const slowFixtures = collectSlowFixtureDiagnostics(runtimePayloads);
  const visualParity = normalizeVisualParityGateOptions(input.visualParity || input.visual_parity || input);
  // Opt-in live-WP parity collection. Off by default: when absent (or disabled),
  // `enabled` is false and no live-WP comparison runs, so the per-fixture result
  // is byte-identical to today. When on, each fixture's captured rendered DOM is
  // scored against the staged source by the blocks-engine comparator.
  const liveWpParity = normalizeLiveWpParityCollectorOptions(input.liveWpParity || input.live_wp_parity);
  const results = matrix.fixtures.map((fixture) => {
    const fixtureArtifactsDirectory = path.join(outputDirectory, fixture.id);
    const payloads = [
      ...runtimePayloads.filter((payload) => fixtureIdentity(payload) === fixture.id),
      ...readFixturePayloadFiles(fixtureArtifactsDirectory),
    ];
    return normalizeCollectedFixtureResult({ fixture, payloads, fixtureArtifactsDirectory, codeboxError, visualParity, liveWpParity });
  });

  return normalizeFixtureMatrixResult({ matrix, results, slow_fixtures: slowFixtures });
}

function normalizeCollectedFixtureResult({ fixture, payloads, fixtureArtifactsDirectory, codeboxError, visualParity, liveWpParity }) {
  const merged = mergeObjects(payloads);
  const visualParityOptions = { ...visualParity, fixtureArtifactsDirectory };
  const diagnostics = collectFixtureDiagnostics(merged, { visualParity: visualParityOptions });
  const visualParityArtifacts = collectVisualParityArtifacts(merged, visualParityOptions);
  const visualParityComparisons = collectVisualParityComparisons(payloads, visualParityOptions);
  // Best-effort live-WP parity (opt-in). Returns null when disabled or when the
  // capture/source/comparator is unavailable, keeping the lane isolated.
  const liveWpParityResult = collectLiveWpParity({
    fixtureArtifactsDirectory,
    entrypoint: fixture.entrypoint,
    options: liveWpParity,
  });
  const error = firstString([
    merged.error,
    merged.message && isFailurePayload(merged) ? merged.message : '',
    codeboxError && payloads.length === 0 ? codeboxError.message || String(codeboxError) : '',
  ]);
  const success = inferFixtureSuccess(merged, diagnostics, error, payloads.length);
  return normalizeFixtureResult({
    fixture_id: fixture.id,
    fixture_path: fixture.fixture_path,
    status: fixtureStatus(payloads.length, error, success),
    success,
    error,
    ssi_validation: merged.ssi_validation || merged.ssiValidation || merged.validation || merged.static_site_importer || null,
    import_report: merged.import_report || merged.importReport || merged.report || null,
    quality_metrics: collectQualityMetrics(merged),
    block_composition: collectBlockComposition(merged),
    // Real `wp.blocks.validateBlock` editor-validity from the
    // `wordpress.editor-validate-blocks` command, distinct from the PHP
    // round-trip's structural `invalid_block_counts`.
    editor_validation: collectEditorValidation(merged),
    blocks_engine_diagnostics: collectBlocksEngineDiagnostics(merged),
    invalid_block_counts: collectInvalidBlockCounts(merged),
    missing_assets: collectMissingAssets(merged),
    runtime_target_gaps: collectRuntimeTargetGaps(merged),
    diagnostics,
    artifact_refs: collectFixtureArtifactRefs(payloads, fixtureArtifactsDirectory),
    artifacts: merged.artifacts || {},
    editor_canvas: merged.editor_canvas || merged.editorCanvas || merged.editor_canvas_summary || merged.editorCanvasSummary || null,
    editor_open: merged.editor_open || merged.editorOpen || null,
    visual_parity_artifacts: visualParityArtifacts,
    ...(visualParityComparisons.length ? { visual_parity_comparisons: visualParityComparisons } : {}),
    visual_diff_regions: visualParityArtifacts?.visual_diff_regions || [],
    visual_diff_cause_summary: visualParityArtifacts?.visual_diff_cause_summary || null,
    visual_diff_classification: visualParityArtifacts?.visual_diff_classification || null,
    live_wp_parity: liveWpParityResult,
    matrix_evidence: collectMatrixEvidence(merged),
    svg_font_embedding_evidence: merged.svg_font_embedding_evidence || merged.svgFontEmbeddingEvidence || null,
    raw: { payloads },
  });
}

// Keep each command result separate: merging runtime payloads is appropriate for
// fixture status, but it otherwise discards secondary route visual evidence.
function collectVisualParityComparisons(payloads, visualParityOptions) {
  return payloads
    .map((payload) => {
      const artifacts = collectVisualParityArtifacts(payload, visualParityOptions);
      if (!artifacts) {
        return null;
      }
      const metadata = objectValue(payload.metadata);
      return {
        surface_id: firstString([metadata.surface_id, metadata.surfaceId, payload.surface_id, payload.surfaceId]) || 'front-page',
        visual_parity_artifacts: artifacts,
      };
    })
    .filter(Boolean);
}

const WORDPRESS_SITE_PLAN_SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
const MATERIALIZATION_PLAN_ASSET_LIMIT = 50;

// Keep enough runtime evidence to attribute CSS/JS behavior without retaining
// arbitrary source payloads in matrix artifacts.
function collectMatrixEvidence(payload) {
  const blocksEngine = objectValue(payload.blocks_engine || payload.blocksEngine || payload.import_report?.blocks_engine || payload.importReport?.blocks_engine || payload.report?.blocks_engine);
  const transformer = objectValue(blocksEngine.transformer || blocksEngine.transformer_provenance || blocksEngine.transformerProvenance);
  const plan = objectValue(blocksEngine.wordpress_site_plan || blocksEngine.wordpressSitePlan);
  const importReport = objectValue(payload.import_report || payload.importReport || payload.report);
  const generatedTheme = objectValue(importReport.generated_theme || importReport.generatedTheme || payload.generated_theme || payload.generatedTheme);
  const templateParts = normalizeArray(generatedTheme.template_parts || generatedTheme.templateParts)
    .map(templatePartEvidenceSummary)
    .filter((part) => Object.keys(part).length > 0);
  const provenance = compactObject({
    package: firstString([transformer.package, transformer.name]),
    version: firstString([transformer.version, transformer.pretty_version, transformer.prettyVersion]),
    reference: firstString([transformer.source_fingerprint, transformer.sourceFingerprint, transformer.reference, transformer.commit, transformer.source_reference, transformer.sourceReference]),
    package_reference: firstString([transformer.reference, transformer.commit, transformer.source_reference, transformer.sourceReference]),
    source_fingerprint: firstString([transformer.source_fingerprint, transformer.sourceFingerprint]),
  });
  const missing = [
    ...(isConcreteTransformerValue(provenance.package) ? [] : ['transformer_package']),
    ...(isConcreteTransformerValue(provenance.version) ? [] : ['transformer_version']),
    ...(isConcreteTransformerValue(provenance.reference) ? [] : ['transformer_reference']),
    ...(plan.schema === WORDPRESS_SITE_PLAN_SCHEMA ? [] : ['wordpress_site_plan']),
  ];
  const sourceAssets = normalizeArray(plan.assets);
  const assets = sourceAssets
    .map(materializationPlanAssetSummary)
    .filter((asset) => Object.keys(asset).length > 0)
    .sort((left, right) => String(left.path || left.source || '').localeCompare(String(right.path || right.source || '')))
    .slice(0, MATERIALIZATION_PLAN_ASSET_LIMIT);
  return {
    schema: 'static-site-importer/fixture-matrix-runtime-evidence/v1',
    readiness: missing.length === 0 ? 'verified' : 'legacy_evidence_missing',
    missing,
    transformer: provenance,
    wordpress_site_plan: compactObject({
      schema: plan.schema,
      asset_count: sourceAssets.length,
      assets,
    }),
    template_parts: templateParts,
  };
}

function isConcreteTransformerValue(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized !== '' && normalized !== 'unknown' && normalized !== 'dev-unknown' && !/^(?:sha256:)?0+$/.test(normalized);
}

function templatePartEvidenceSummary(part) {
  const row = objectValue(part);
  return compactObject({
    path: firstString([row.path]),
    origin: firstString([row.origin]),
    source_paths: normalizeArray(row.source_paths || row.sourcePaths),
    block_markup_hash: firstString([row.block_markup_hash, row.blockMarkupHash]),
    block_markup_bytes: Number.isFinite(Number(row.block_markup_bytes ?? row.blockMarkupBytes)) ? Number(row.block_markup_bytes ?? row.blockMarkupBytes) : undefined,
    block_names: normalizeArray(row.block_names || row.blockNames),
    contains_core_html: typeof row.contains_core_html === 'boolean' ? row.contains_core_html : row.containsCoreHtml,
    control_marker_count: Number.isFinite(Number(row.control_marker_count ?? row.controlMarkerCount)) ? Number(row.control_marker_count ?? row.controlMarkerCount) : undefined,
  });
}

function materializationPlanAssetSummary(asset) {
  const row = objectValue(asset);
  return compactObject({
    path: firstString([row.path, row.target_path, row.targetPath]),
    source: firstString([row.source, row.source_path, row.sourcePath]),
    role: firstString([row.role, row.intent]),
    kind: firstString([row.kind]),
    type: firstString([row.type, row.media_type, row.mediaType, row.mime_type, row.mimeType]),
    placement: firstString([row.placement]),
    defer: typeof row.defer === 'boolean' ? row.defer : undefined,
    async: typeof row.async === 'boolean' ? row.async : undefined,
    payload_present: typeof row.payload_present === 'boolean' ? row.payload_present : undefined,
    payload_sha256: firstString([row.payload_sha256, row.payloadSha256, row.hash]),
    payload_bytes: Number.isFinite(Number(row.payload_bytes ?? row.payloadBytes ?? row.bytes)) ? Number(row.payload_bytes ?? row.payloadBytes ?? row.bytes) : undefined,
  });
}

function collectFixtureDiagnostics(payload, options = {}) {
  const editorValidationDiagnostics = collectEditorValidationDiagnostics(payload);
  const diagnostics = [
    ...normalizeArray(payload.diagnostics),
    ...normalizeArray(payload.fixture_diagnostics?.diagnostics || payload.fixtureDiagnostics?.diagnostics),
    ...normalizeArray(payload.findings),
    ...collectFindingPacketDiagnostics(payload),
    ...normalizeArray(payload.messages),
    ...normalizeArray(payload.errors),
    ...normalizeArray(payload.warnings),
    ...collectImportReportDiagnostics(payload),
    ...normalizeArray(payload.upstream_gaps || payload.upstreamGaps).map((gap) => ({ kind: 'upstream_gap', ...objectValue(gap), message: diagnosticMessage(gap) || gap.missing || 'Upstream capability gap detected.' })),
    ...collectBlocksEngineDiagnostics(payload),
    ...collectRuntimeTargetGaps(payload).map((gap) => ({ kind: 'runtime_target_gap', ...objectValue(gap), message: diagnosticMessage(gap) || 'Runtime target gap detected.' })),
    ...collectMissingAssets(payload).map((asset) => ({ kind: missingAssetKind(asset), ...objectValue(asset), message: diagnosticMessage(asset) || 'Missing imported asset.' })),
    ...editorValidationDiagnostics,
    ...collectVisualParityDiagnostics(payload, options.visualParity),
  ].map(normalizeActionableDiagnosticPayload).filter(Boolean);
  const invalidBlockCount = Object.values(collectInvalidBlockCounts(payload)).reduce((sum, value) => sum + numberValue(value), 0);
  if (invalidBlockCount > 0 && editorValidationDiagnostics.length === 0) {
    diagnostics.push({ kind: 'invalid_block_content', synthetic_summary: true, message: `${invalidBlockCount} invalid block${invalidBlockCount === 1 ? '' : 's'} reported by SSI validation.` });
  }
  return dedupeDiagnostics(propagateAcceptedRuntimePreservation(suppressMaterializedScriptFallbackEchoes(diagnostics)));
}

function collectImportReportDiagnostics(payload) {
  const reports = [
    objectValue(payload),
    objectValue(payload.import_report || payload.importReport || payload.report),
  ];
  const blocksEngine = objectValue(payload.blocks_engine || payload.blocksEngine);
  if (Object.keys(blocksEngine).length > 0) {
    reports.push(objectValue(blocksEngine.conversion_report || blocksEngine.conversionReport));
  }

  const diagnostics = reports.flatMap((report) => [
    ...normalizeArray(report.diagnostics),
    ...seedingReportDiagnostics(report, 'product_seeding', 'product_seeding_failed'),
    ...seedingReportDiagnostics(report, 'form_seeding', 'form_seeding_failed'),
  ]);
  return suppressMaterializedScriptFallbackEchoes(diagnostics);
}

function suppressMaterializedScriptFallbackEchoes(diagnostics) {
  const materializedScripts = new Set(diagnostics
    .filter((diagnostic) => ['runtime_script_materialized'].includes(String(diagnostic?.code || diagnostic?.kind || diagnostic?.type || '')))
    .map(scriptDiagnosticKey)
    .filter(Boolean));

  return diagnostics.filter((diagnostic) => !isRawScriptFallback(diagnostic) || !materializedScripts.has(scriptDiagnosticKey(diagnostic)));
}

function scriptDiagnosticKey(diagnostic) {
  const row = objectValue(diagnostic);
  const sourcePath = firstString([row.source_path, row.sourcePath, row.path, row.source]);
  const selector = firstString([row.selector, row.runtime_target_selector, row.runtimeTargetSelector]);
  return sourcePath && selector ? `${sourcePath}\u0000${selector}` : '';
}

function isRawScriptFallback(diagnostic) {
  const row = objectValue(diagnostic);
  return /html[_-]script[_-]fallback|script[_-]requires[_-]runtime/i.test([
    row.code,
    row.diagnostic_code,
    row.kind,
    row.type,
    row.reason,
    row.reason_code,
  ].filter(Boolean).join(' '));
}

function seedingReportDiagnostics(report, key, kind) {
  const seeding = objectValue(report[key] || report[toCamelCase(key)]);
  if (Object.keys(seeding).length === 0) {
    return [];
  }
  const status = String(seeding.status || '').toLowerCase();
  const reason = String(seeding.reason || '').toLowerCase();
  if (status === 'skipped' && ['no_validated_manifest', 'empty_validated_manifest', 'no_form_findings', 'no_product_findings'].includes(reason)) {
    return [];
  }
  const counts = objectValue(seeding.counts);
  const errorCount = numberValue(counts.error);
  if (status === 'completed' && errorCount === 0) {
    return [];
  }
  return [{
    kind,
    loss_class: 'importer_materialization_bug',
    severity: status === 'skipped' ? 'warning' : 'error',
    source_path: key,
    message: seeding.reason || `${key} did not complete cleanly.`,
    status: seeding.status,
    reason: seeding.reason,
  }];
}

function toCamelCase(value) {
  return String(value || '').replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
}

function normalizeActionableDiagnosticPayload(diagnostic) {
  const row = objectValue(diagnostic);
  if (Object.keys(row).length === 0) {
    return null;
  }

  // Runtime command telemetry proves that an evidence step ran, but it is not a
  // quality diagnostic. The concrete visual/editor collectors below turn the
  // same payload family into actionable findings when there is an actual issue.
  const keys = Object.keys(row).sort();
  if (keys.every((key) => ['command', 'durationMs', 'finishedAt', 'startedAt', 'timing'].includes(key))) {
    return null;
  }

  const source = objectValue(row.source_diagnostic || row.sourceDiagnostic || row.source);
  const kind = firstString([row.kind, row.code, row.type, row.reason_code, row.reasonCode, source.kind, source.code, source.type, source.reason_code, source.reasonCode, row.loss_class, row.lossClass]);
  return kind ? { ...row, kind } : null;
}

function propagateAcceptedRuntimePreservation(diagnostics) {
  const accepted = new Set();
  for (const diagnostic of diagnostics) {
    const row = objectValue(diagnostic);
    if (!isAcceptedRuntimePreservation(row)) {
      continue;
    }
    const key = runtimePreservationKey(row);
    if (key) {
      accepted.add(key);
    }
    const selectorKey = runtimePreservationSelectorKey(row);
    if (selectorKey) {
      accepted.add(selectorKey);
    }
  }

  if (accepted.size === 0) {
    return diagnostics;
  }

  return diagnostics.map((diagnostic) => {
    const row = objectValue(diagnostic);
    if (row.runtime_carried || row.runtimeCarried || !isScriptRuntimeDiagnostic(row) || !(accepted.has(runtimePreservationKey(row)) || accepted.has(runtimePreservationSelectorKey(row)))) {
      return diagnostic;
    }
    return { ...row, runtime_carried: true };
  });
}

function isAcceptedRuntimePreservation(row) {
  const source = objectValue(row.source_diagnostic || row.sourceDiagnostic);
  return String(row.acceptability || '').trim() === 'acceptable_preservation'
    && /accepted[_-]runtime[_-]preservation|preserved[_-]runtime[_-]island/i.test(String(row.repair_mode || row.repairMode || row.repair_bucket || row.repairBucket || row.group_key || row.groupKey || row.loss_class || row.lossClass || ''))
    && isScriptRuntimeDiagnostic({ ...source, ...row });
}

function isScriptRuntimeDiagnostic(row) {
  const source = objectValue(row.source_diagnostic || row.sourceDiagnostic);
  const haystack = [
    row.code,
    row.kind,
    row.type,
    row.reason,
    row.reason_code,
    row.reasonCode,
    row.message,
    row.tag,
    row.tag_name,
    row.tagName,
    source.code,
    source.kind,
    source.type,
    source.reason,
    source.reason_code,
    source.reasonCode,
  ].filter(Boolean).join(' ');
  return /html[_\s-]+script[_\s-]+fallback|script[_\s-]+requires[_\s-]+runtime|\bscript\b/i.test(haystack);
}

function runtimePreservationKey(row) {
  const source = objectValue(row.source_diagnostic || row.sourceDiagnostic);
  const selector = String(row.selector || source.selector || '').trim();
  if (!selector) {
    return '';
  }
  const sourcePath = String(row.source_path || row.sourcePath || row.path || source.source_path || source.sourcePath || source.path || '').trim();
  return `${sourcePath || '(unknown)'}\u0000${selector}`;
}

function runtimePreservationSelectorKey(row) {
  const source = objectValue(row.source_diagnostic || row.sourceDiagnostic);
  const selector = String(row.selector || source.selector || '').trim();
  return selector ? `(selector)\u0000${selector}` : '';
}

function collectFindingPacketDiagnostics(payload) {
  return [
    ...normalizeArray(payload.finding_packets?.packets || payload.findingPackets?.packets),
    ...normalizeArray(payload.import_report?.finding_packets?.packets || payload.importReport?.finding_packets?.packets),
    ...normalizeArray(payload.report?.finding_packets?.packets),
  ].map(findingPacketDiagnostic).filter(Boolean);
}

function findingPacketDiagnostic(packet) {
  const row = objectValue(packet);
  if (Object.keys(row).length === 0) {
    return null;
  }
  const source = objectValue(row.source_diagnostic || row.sourceDiagnostic || row.source);
  const kind = firstString([row.kind, row.code, row.type, row.reason_code, row.reasonCode, source.kind, source.code, source.type, source.reason_code, source.reasonCode]);
  if (!kind) {
    return null;
  }
  return { ...row, kind };
}

function collectFixtureArtifactRefs(payloads, fixtureArtifactsDirectory) {
  const refs = normalizeArray(payloads).flatMap(collectPayloadArtifactRefs);
  for (const fileName of ['artifact.json', 'validation-result.json', 'import-report.json']) {
    const filePath = path.join(fixtureArtifactsDirectory, fileName);
    if (fs.existsSync(filePath)) {
      refs.push(artifactRef(fileName.replace(/\.json$/, ''), filePath, fileName === 'artifact.json' ? 'input' : 'diagnostic'));
    }
  }
  return dedupeArtifactRefs(refs);
}

function collectPayloadArtifactRefs(payload) {
  const refs = [...normalizeArray(payload.artifact_refs || payload.artifactRefs), ...normalizeArray(payload.artifacts?.refs)];
  for (const [key, value] of Object.entries(payload.artifacts || {})) {
    if (value && typeof value === 'object' && !Array.isArray(value) && (value.path || value.file || value.href)) {
      refs.push({ artifact_id: key, kind: value.kind || key, ...value });
    } else if (typeof value === 'string') {
      refs.push({ artifact_id: key, kind: key, path: value });
    }
  }
  for (const [key, value] of Object.entries(objectValue(objectValue(payload.editor_open || payload.editorOpen).files))) {
    if (typeof value === 'string' && value) {
      refs.push({ artifact_id: `editor-open-${key}`, kind: 'editor-canvas', path: value });
    }
  }
  return dedupeArtifactRefs(refs);
}

function dedupeArtifactRefs(refs) {
  const seen = new Set();
  return normalizeArray(refs).filter((ref) => {
    const row = objectValue(ref);
    const key = [row.artifact_id || row.id || '', row.kind || '', row.path || row.file || row.href || ''].join('\u0000');
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

function collectRuntimePayloads(value) {
  const payloads = [];
  visitRuntimePayloads(value, '', payloads, new Set());
  payloads.push(...collectRecipeStepFailurePayloads(value));
  payloads.push(...collectRecipeBrowserEvidencePayloads(value));
  return payloads.map(normalizeEditorOpenPayload);
}

// `wordpress.editor-open` writes its browser evidence as a command result with a
// top-level `files` map. Normalize that native shape at the intake boundary so the
// matrix consumes the same screenshot/state/validity evidence the runner emitted.
function normalizeEditorOpenPayload(payload) {
  if (payload?.command !== 'wordpress.editor-open') {
    return payload;
  }
  const files = objectValue(payload.files);
  if (typeof files.screenshot !== 'string' || !files.screenshot) {
    return payload;
  }
  return {
    ...payload,
    editor_open: {
      schema: 'wp-codebox/editor-open/v1',
      target: payload.target,
      requested_url: payload.requestedUrl || payload.requested_url,
      final_url: payload.finalUrl || payload.final_url,
      files,
      summary: objectValue(payload.summary),
    },
    editor_canvas: {
      status: 'captured',
      screenshot: files.screenshot,
    },
  };
}

function collectRecipeStepFailurePayloads(value) {
  const root = objectValue(value);
  const failures = normalizeArray(root.stepFailures || root.step_failures).filter((failure) => failure && typeof failure === 'object');
  if (failures.length === 0) {
    return [];
  }

  const context = recipeStepContext(root);
  return failures.map((failure) => {
    const row = objectValue(failure);
    const metadata = objectValue(row.metadata);
    const fallback = context.get(stepContextKey(row)) || {};
    const fixtureId = metadata.fixture_id || metadata.fixtureId || fixtureIdentity(row) || fallback.fixture_id || '';
    if (!fixtureId) {
      return null;
    }
    return {
      fixture_id: fixtureId,
      diagnostics: [recipeStepFailureDiagnostic(row, { ...fallback, metadata: { ...fallback.metadata, ...metadata } })],
    };
  }).filter(Boolean);
}

function collectChildCommandFailurePayloads(value) {
  return normalizeArray(value).flatMap((failure) => {
    const row = objectValue(failure);
    const fixtureIds = failureFixtureIds(row);
    if (fixtureIds.length === 0) {
      return [];
    }
    const diagnostic = childCommandFailureDiagnostic(row);
    return fixtureIds.map((fixtureId) => ({
      fixture_id: fixtureId,
      diagnostics: [diagnostic],
    }));
  });
}

function failureFixtureIds(failure) {
  const metadata = objectValue(failure.metadata);
  return [...new Set([
    ...normalizeArray(failure.fixture_ids || failure.fixtureIds || metadata.fixture_ids || metadata.fixtureIds),
    failure.fixture_id || failure.fixtureId || metadata.fixture_id || metadata.fixtureId,
  ].filter(Boolean).map((fixtureId) => String(fixtureId)))].sort();
}

function childCommandFailureDiagnostic(failure) {
  return compactObject({
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
    artifact_refs: failure.artifact_refs || failure.artifactRefs,
    reason: diagnosticMessage(failure) || 'WP Codebox child command failed.',
    message: diagnosticMessage(failure) || 'WP Codebox child command failed.',
  });
}

function printableFailureCommand(failure) {
  if (typeof failure?.command === 'string') {
    return failure.command;
  }
  if (typeof failure?.command?.command === 'string') {
    return failure.command.command;
  }
  const argv = failure?.command_argv || failure?.commandArgv || failure?.command?.argv;
  return Array.isArray(argv) ? argv.map((value) => String(value)).join(' ') : undefined;
}

function recipeStepContext(root) {
  const context = new Map();
  for (const execution of normalizeArray(root.executions).filter((item) => item && typeof item === 'object')) {
    const metadata = objectValue(execution.recipeStepMetadata || execution.recipe_step_metadata || execution.metadata);
    const fixtureId = metadata.fixture_id || metadata.fixtureId || fixtureIdentity(execution);
    const row = {
      fixture_id: fixtureId,
      command: execution.command,
      args: execution.args,
      metadata,
      duration_ms: durationMs(execution),
    };
    for (const key of stepContextKeys(execution)) {
      if (key && fixtureId) {
        context.set(key, row);
      }
    }
  }
  return context;
}

function stepContextKeys(row) {
  const metadata = objectValue(row.metadata);
  return [
    stepContextKey(row),
    stepContextKey({ phase: metadata.phase, index: row.recipeStepIndex ?? row.recipe_step_index ?? metadata.recipe_step_index ?? metadata.recipeStepIndex }),
    stepContextKey({ recipePhase: metadata.phase, recipeStepIndex: row.recipeStepIndex ?? row.recipe_step_index ?? metadata.recipe_step_index ?? metadata.recipeStepIndex }),
  ].filter(Boolean);
}

function stepContextKey(row) {
  const metadata = objectValue(row.metadata);
  const phase = row.recipePhase ?? row.recipe_phase ?? row.phase ?? metadata.phase ?? metadata.recipePhase ?? metadata.recipe_phase;
  const index = row.recipeStepIndex ?? row.recipe_step_index ?? row.index ?? metadata.recipeStepIndex ?? metadata.recipe_step_index ?? metadata.index;
  return phase !== undefined && index !== undefined ? `${phase}:${index}` : '';
}

function recipeStepFailureDiagnostic(failure, context = {}) {
  const metadata = objectValue(context.metadata);
  const command = failure.command || context.command || metadata.command || '';
  const args = normalizeArray(failure.args || context.args);
  const fields = commandFields(command, args);
  const recipePhase = failure.recipePhase ?? failure.recipe_phase ?? failure.phase ?? metadata.phase ?? metadata.recipePhase ?? metadata.recipe_phase;
  const timeoutClass = failure.timeoutClass || failure.timeout_class || metadata.timeoutClass || metadata.timeout_class;
  const visualTimeout = isVisualStep(command, recipePhase) && isTimeoutFailure(failure, timeoutClass);
  const message = recipeStepFailureMessage(failure);
  return compactObject({
    kind: visualTimeout ? VISUAL_TIMEOUT_KIND : 'recipe_step_failure',
    group_key: visualTimeout ? VISUAL_TIMEOUT_KIND : 'wp_codebox_recipe_step_failure',
    loss_class: visualTimeout ? VISUAL_TIMEOUT_KIND : 'runtime_execution_failed',
    loss_acceptance: 'unacceptable',
    recipe_step_index: failure.recipeStepIndex ?? failure.recipe_step_index ?? failure.index ?? metadata.recipeStepIndex ?? metadata.recipe_step_index ?? metadata.index,
    recipe_phase: recipePhase,
    command,
    duration_ms: durationMs(failure) || context.duration_ms,
    timeout_class: timeoutClass,
    url: failure.url || metadata.url || fields.url,
    source_url: failure.source_url || failure.sourceUrl || metadata.source_url || metadata.sourceUrl || fields.source_url,
    candidate_url: failure.candidate_url || failure.candidateUrl || metadata.candidate_url || metadata.candidateUrl || fields.candidate_url,
    post_id: failure.post_id || failure.postId || metadata.post_id || metadata.postId || fields.post_id,
    artifact: failure.artifact || metadata.artifact || fields.artifact,
    reason: message || failure.status || 'WP Codebox recipe step failed.',
    message: message || 'WP Codebox recipe step failed.',
  });
}

function recipeStepFailureMessage(failure) {
  return firstString([
    diagnosticMessage(failure),
    diagnosticMessage(failure.error),
    diagnosticMessage(objectValue(failure.error).cause),
    typeof failure.error === 'string' ? failure.error : '',
  ]);
}

function isVisualStep(command, recipePhase) {
  return command === 'wordpress.visual-compare' || String(recipePhase || '').toLowerCase() === 'visual';
}

function isTimeoutFailure(failure, timeoutClass) {
  const haystack = [timeoutClass, failure.kind, failure.code, failure.type, failure.message, failure.reason, recipeStepFailureMessage(failure), failure.status]
    .filter(Boolean)
    .join(' ');
  return /timeout|timed out|exceeded/i.test(haystack) || durationMs(failure) >= 120000;
}

function collectSlowFixtureDiagnostics(payloads) {
  return payloads.flatMap((payload) => normalizeArray(payload.diagnostics)
    .filter((diagnostic) => ['recipe_step_failure', VISUAL_TIMEOUT_KIND].includes(objectValue(diagnostic).kind))
    .map((diagnostic) => compactObject({ fixture_id: payload.fixture_id, ...objectValue(diagnostic) }))
    .filter((diagnostic) => diagnostic.fixture_id && (diagnostic.duration_ms || diagnostic.timeout_class)));
}

function durationMs(row) {
  const value = row.duration_ms ?? row.durationMs ?? row.duration ?? row.timing?.duration_ms ?? row.timing?.durationMs;
  const number = Number(value);
  return Number.isFinite(number) ? number : undefined;
}

function commandFields(command, args) {
  const fields = {};
  for (const arg of args) {
    if (typeof arg !== 'string') {
      continue;
    }
    for (const token of arg.split(/\s+/)) {
      const match = token.match(/^([A-Za-z0-9_-]+)=(.+)$/) || token.match(/^--([A-Za-z0-9_-]+)=(.+)$/);
      if (!match) {
        continue;
      }
      const key = match[1].replace(/-/g, '_');
      const value = match[2].replace(/^'|'$/g, '');
      if (['url', 'source_url', 'candidate_url', 'post_id', 'artifact'].includes(key)) {
        fields[key] = value;
      }
    }
  }
  if (command === 'wordpress.wp-cli' && !fields.artifact) {
    for (const arg of args) {
      const artifact = typeof arg === 'string' ? arg.match(/--artifact=([^\s]+)/) : null;
      if (artifact) {
        fields.artifact = artifact[1].replace(/^'|'$/g, '');
      }
    }
  }
  return fields;
}

function collectRecipeBrowserEvidencePayloads(value) {
  const root = objectValue(value);
  const executions = normalizeArray(root.executions).filter((execution) => execution && typeof execution === 'object');
  const fixtureByStep = new Map();
  let carriedFixtureId = '';
  for (const execution of executions) {
    const fixtureId = fixtureIdentity(execution) || carriedFixtureId;
    const phase = execution.recipePhase;
    const index = execution.recipeStepIndex;
    if (fixtureId && phase !== undefined && index !== undefined) {
      fixtureByStep.set(`${phase}:${index}`, fixtureId);
    }
    if (fixtureId) {
      carriedFixtureId = fixtureId;
    }
  }

  return normalizeArray(root.browserEvidence || root.browser_evidence)
    .filter((evidence) => evidence && typeof evidence === 'object')
    .map((evidence) => ({ fixture_id: fixtureIdentity(evidence) || fixtureByStep.get(`${evidence.phase}:${evidence.index}`), ...evidence }))
    .filter((evidence) => evidence.fixture_id);
}

function visitRuntimePayloads(value, inheritedFixtureId, payloads, seen) {
  if (!value || typeof value !== 'object' || seen.has(value)) {
    return;
  }
  seen.add(value);
  const fixtureId = fixtureIdentity(value) || inheritedFixtureId;
  if (fixtureId && hasPayloadData(value)) {
    payloads.push({ fixture_id: fixtureId, ...value });
  }
  for (const key of ['stdout', 'stderr', 'output', 'result']) {
    for (const parsed of parseJsonPayloadsFromText(value[key])) {
      payloads.push({ fixture_id: fixtureId, ...parsed });
    }
  }
  if (Array.isArray(value)) {
    // Recipe steps run in per-fixture order ([import, editor-validate, ...]);
    // the import step carries the fixture slug while the editor step does not.
    // Thread the last-seen fixture id forward across sibling executions so the
    // editor result inherits the fixture it validated. (`new Set()` per element
    // is unnecessary; `seen` already guards re-entry.)
    let carried = inheritedFixtureId;
    for (const child of value) {
      const childFixtureId = (child && typeof child === 'object') ? (fixtureIdentity(child) || carried) : carried;
      visitRuntimePayloads(child, childFixtureId, payloads, seen);
      if (childFixtureId) {
        carried = childFixtureId;
      }
    }
    return;
  }
  for (const child of Object.values(value)) {
    visitRuntimePayloads(child, fixtureId, payloads, seen);
  }
}

function hasPayloadData(value) {
  return ['status', 'success', 'ok', 'passed', 'error', 'diagnostics', 'findings', 'summary', 'artifacts', 'upstream_gaps', 'runtime_target_gaps', 'blocks_engine', 'import_report', 'editor_canvas', 'editorCanvas', 'editor_open', 'editorOpen']
    .some((key) => Object.hasOwn(value, key));
}

function readFixturePayloadFiles(directory) {
  return ['validation-result.json', 'result.json', 'import-report.json', 'quality.json', 'blocks-engine-diagnostics.json', 'editor-validation.json', 'editor-validate-blocks.json', 'editor-open.json', 'editor-summary.json', 'editor-state.json', 'editor-validity.json', 'editor-canvas-summary.json', 'visual-compare.json', 'visual-diff.json', 'visual-parity.json', 'visual-explanation.json']
    .map((fileName) => readJsonFileIfExists(path.join(directory, fileName)))
    .filter(Boolean);
}

function fixtureIdentity(payload) {
  return payload?.fixture_id
    || payload?.fixtureId
    || payload?.fixture?.id
    || payload?.fixture?.slug
    || payload?.fixture_diagnostics?.fixture?.slug
    || payload?.fixtureDiagnostics?.fixture?.slug
    || payload?.request?.import_args?.slug
    || payload?.request?.importArgs?.slug
    || payload?.metadata?.fixture_id
    || payload?.metadata?.fixtureId
    || fixtureIdFromExecutionArgs(payload)
    || '';
}

// Derive the fixture slug from a wp-codebox execution's args. The import step is
// `wordpress.wp-cli command=static-site-importer validate-artifact --slug=<id>
// --artifact=.../<id>/artifact.json`, so its slug is the only place the fixture
// id appears on the (otherwise id-less) per-fixture executions. The
// editor-validate-blocks step that follows carries no id of its own; surfacing
// the slug here lets `visitRuntimePayloads` thread it forward to that step.
function fixtureIdFromExecutionArgs(payload) {
  const args = payload?.args;
  if (!Array.isArray(args)) {
    return '';
  }
  for (const arg of args) {
    if (typeof arg !== 'string') {
      continue;
    }
    const slug = arg.match(/--slug=([^\s]+)/);
    if (slug) {
      return slug[1];
    }
    const artifact = arg.match(/--artifact=\S*\/([^/\s]+)\/artifact\.json/);
    if (artifact) {
      return artifact[1];
    }
  }
  return '';
}

function collectInvalidBlockCounts(payload) {
  const quality = collectQualityMetrics(payload);
  return compactObject({
    invalid_block_count: payload.invalid_block_count || payload.invalidBlockCount || quality.invalid_block_count,
    invalid_blocks: payload.invalid_blocks || payload.invalidBlocks || quality.invalid_blocks,
    editor_invalid_blocks: payload.editor_invalid_blocks || payload.editorInvalidBlocks || quality.editor_invalid_blocks,
  });
}

function collectMissingAssets(payload) {
  return [
    ...normalizeArray(payload.missing_assets || payload.missingAssets),
    ...normalizeArray(payload.dropped_images || payload.droppedImages),
    ...normalizeArray(payload.import_report?.missing_assets || payload.importReport?.missing_assets),
    ...normalizeArray(payload.report?.missing_assets),
  ];
}

function collectRuntimeTargetGaps(payload) {
  return [
    ...normalizeArray(payload.runtime_target_gaps || payload.runtimeTargetGaps),
    ...normalizeArray(payload.runtime_targets_missing || payload.runtimeTargetsMissing),
    ...normalizeArray(payload.blocks_engine?.runtime_target_gaps || payload.blocksEngine?.runtimeTargetGaps),
  ];
}

function collectBlocksEngineDiagnostics(payload) {
  return [
    ...normalizeArray(payload.blocks_engine_diagnostics || payload.blocksEngineDiagnostics),
    ...normalizeArray(payload.blocks_engine?.diagnostics || payload.blocksEngine?.diagnostics),
    ...normalizeArray(payload.transformer_diagnostics || payload.transformerDiagnostics),
  ];
}

function inferFixtureSuccess(payload, diagnostics, error, payloadCount) {
  if (payload.success === true || payload.ok === true || payload.passed === true) {
    return diagnostics.length === 0 && !error;
  }
  if (payload.ok === false || payload.passed === false || payload.status === 'error') {
    return false;
  }
  if (payload.success === false || payload.status === 'failed') {
    return diagnostics.length > 0 && !error;
  }
  if (payload.status === 'passed' || payload.status === 'success') {
    return diagnostics.length === 0 && !error;
  }
  return payloadCount > 0 && diagnostics.length === 0 && !error;
}

function fixtureStatus(payloadCount, error, success) {
  if (payloadCount === 0 && !error) {
    return 'not_run';
  }
  return success ? 'passed' : 'failed';
}

function isFailurePayload(payload) {
  return payload.success === false || payload.ok === false || payload.status === 'failed' || payload.status === 'error';
}

function missingAssetKind(value) {
  const message = diagnosticMessage(value);
  return /\.svg(?:\b|$)/i.test(message) ? 'broken_svg' : 'dropped_images';
}
