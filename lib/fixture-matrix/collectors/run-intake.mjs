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
import { createHash } from 'node:crypto';

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
  collectVisualParityArtifactComparisons,
  normalizeVisualParityGateOptions,
} from './visual-parity.mjs';
import { VISUAL_TIMEOUT_KIND } from '../shared/constants.mjs';
import {
  collectLiveWpParity,
  normalizeLiveWpParityCollectorOptions,
} from './live-wp-parity.mjs';
import { normalizeFixtureMatrixResult, normalizeFixtureResult } from '../result.mjs';
import { collectRuntimePresentationEvidence } from '../runtime-presentation-evidence.mjs';
import { PROVIDER_SUBMISSION_EVIDENCE_SCHEMA } from '../provider-submission-evidence.mjs';

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
    const attemptId = input.sidecarAttemptId || input.sidecar_attempt_id || 'primary';
    const fileSidecar = readMaterializationSidecar(fixtureArtifactsDirectory, fixture.id, matrix.id, attemptId);
    const sidecar = fileSidecar.status !== 'missing'
      ? fileSidecar
      : readTransportedMaterializationSidecar(codeboxOutput, fixtureArtifactsDirectory, fixture.id, matrix.id, attemptId);
    const payloads = [
      ...runtimePayloads.filter((payload) => fixtureIdentity(payload) === fixture.id),
      ...readFixturePayloadFiles(fixtureArtifactsDirectory),
      ...(sidecar.status === 'verified' ? [{ fixture_id: fixture.id, materialization_runtime_sidecar: sidecar.payload, materialization_receipt: sidecar.payload.receipt }] : []),
    ];
    return normalizeCollectedFixtureResult({ fixture, payloads, fixtureArtifactsDirectory, codeboxError, visualParity, liveWpParity, dependencyOverrides: input.dependencyOverrides || input.dependency_overrides, sidecar });
  });

  return normalizeFixtureMatrixResult({ matrix, results, slow_fixtures: slowFixtures, surfaceCoverage: input.surfaceCoverage || input.surface_coverage });
}

function normalizeCollectedFixtureResult({ fixture, payloads, fixtureArtifactsDirectory, codeboxError, visualParity, liveWpParity, dependencyOverrides, sidecar }) {
  const policyPayloads = payloads.filter(isNegativePolicyPayload);
  const positivePayloads = payloads.filter((payload) => !isNegativePolicyPayload(payload));
  const merged = mergeObjects(positivePayloads);
  const runtimePresentationEvidence = collectRuntimePresentationEvidence(positivePayloads);
  const visualParityOptions = { ...visualParity, fixtureArtifactsDirectory };
  const policyRejections = collectPolicyRejections(policyPayloads, fixtureArtifactsDirectory);
  const policyGateFailed = policyRejections?.status === 'failed';
  const diagnostics = attachDiagnosticLineage([
    ...collectFixtureDiagnostics(merged, { visualParity: visualParityOptions }),
    ...runtimePresentationEvidence.diagnostics,
    ...collectPolicyRejectionDiagnostics(policyRejections),
  ], payloads);
  const visualParityArtifacts = collectVisualParityArtifacts(merged, visualParityOptions);
  const visualParityComparisons = collectVisualParityComparisons(positivePayloads, visualParityOptions);
  const matrixEvidence = collectMatrixEvidence(merged, { dependencyOverrides, sidecar });
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
  const success = inferFixtureSuccess(merged, diagnostics, error, positivePayloads.length, policyGateFailed);
  return normalizeFixtureResult({
    fixture_id: fixture.id,
    fixture_path: fixture.fixture_path,
    status: fixtureStatus(positivePayloads.length, error, success, policyGateFailed),
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
    artifact_refs: collectFixtureArtifactRefs(positivePayloads, fixtureArtifactsDirectory),
    artifacts: merged.artifacts || {},
    editor_canvas: merged.editor_canvas || merged.editorCanvas || merged.editor_canvas_summary || merged.editorCanvasSummary || null,
    editor_open: merged.editor_open || merged.editorOpen || null,
    editor_presentation: collectEditorPresentation(merged, matrixEvidence),
    editor_interaction: collectEditorInteraction(payloads),
    visual_parity_artifacts: visualParityArtifacts,
    ...(visualParityComparisons.length ? { visual_parity_comparisons: visualParityComparisons } : {}),
    surface_records: collectSurfaceRecords(positivePayloads),
    visual_diff_regions: visualParityArtifacts?.visual_diff_regions || [],
    visual_diff_cause_summary: visualParityArtifacts?.visual_diff_cause_summary || null,
    visual_diff_classification: visualParityArtifacts?.visual_diff_classification || null,
    live_wp_parity: liveWpParityResult,
    matrix_evidence: matrixEvidence,
    provider_submission_evidence: collectProviderSubmissionEvidence(payloads),
    svg_font_embedding_evidence: merged.svg_font_embedding_evidence || merged.svgFontEmbeddingEvidence || null,
    policy_rejections: policyRejections,
    ...(runtimePresentationEvidence.evidence ? { runtime_presentation_evidence: runtimePresentationEvidence.evidence } : {}),
    raw: { payloads },
  });
}

function isNegativePolicyPayload(payload) {
  return recipePhase(payload) === 'negative-policy';
}

// Recipe builders emit `metadata.phase`; retain legacy spellings only while
// reading older runner payloads.
function recipePhase(payload) {
  const metadata = objectValue(payload?.metadata);
  return firstString([
    metadata.phase,
    payload?.phase,
    metadata.recipe_phase,
    metadata.recipePhase,
    payload?.recipe_phase,
    payload?.recipePhase,
  ]);
}

function collectPolicyRejections(payloads, fixtureArtifactsDirectory) {
  if (payloads.length === 0) return null;
  const artifact = readJsonFileIfExists(path.join(fixtureArtifactsDirectory, 'policy-rejection-artifact.json')) || {};
  const expectedCode = 'static_site_importer_executable_source_rejected';
  const rejected = payloads.some((payload) => policyPayloadErrorCodes(payload).includes(expectedCode));
  const files = normalizeArray(artifact.files);
  return {
    schema: 'static-site-importer/fixture-matrix-policy-rejections/v1',
    classification: 'expected_policy_rejection',
    status: rejected ? 'passed' : 'failed',
    receipts: files.map((file) => ({
      source_path: String(file?.source_path || ''),
      artifact_path: String(file?.path || ''),
      reason: 'not_static_import_content',
      expected_error_code: expectedCode,
      status: rejected ? 'rejected' : 'unexpectedly_accepted',
    })),
  };
}

function collectPolicyRejectionDiagnostics(policyRejections) {
  if (policyRejections?.status !== 'failed') return [];
  return [{
    kind: 'fixture_policy_unexpected_acceptance',
    loss_class: 'fixture_policy_unexpected_acceptance',
    gate: true,
    fixture_scoped: true,
    message: 'Fixture policy probe accepted excluded non-static source content.',
  }];
}

function policyPayloadErrorCodes(payload) {
  const summary = objectValue(payload?.summary);
  return [
    summary.error_code,
    ...normalizeArray(payload?.diagnostics).map((diagnostic) => diagnostic?.code || diagnostic?.reason_code),
  ].filter((value) => typeof value === 'string');
}

export function collectEditorPresentation(payload, matrixEvidence = null) {
  const source = objectValue(objectValue(payload.editor_open || payload.editorOpen).summary).editorPresentation
    || objectValue(payload.summary).editorPresentation
    || payload.editorPresentation
    || payload.editor_presentation;
  const observed = objectValue(source);
  const observedIdentities = [...new Set(normalizeArray(observed.generatedPresentationIdentities || observed.generated_presentation_identities).map((value) => String(value).toLowerCase()).filter((value) => /^[a-f0-9]{64}$/.test(value)))].sort();
  // The evidence provider resolves the edited post through the Blocks Engine
  // editor-presentation asset contract. SSI compares identities only; it must
  // not duplicate route/reconciliation scope policy from the owning plan layer.
  const expectedIdentities = [...new Set(normalizeArray(observed.expectedGeneratedPresentationIdentities || observed.expected_generated_presentation_identities).map((value) => String(value).toLowerCase()).filter((value) => /^[a-f0-9]{64}$/.test(value)))].sort();
  const expectedIdentitiesComplete = observed.expectedGeneratedPresentationIdentitiesComplete === true
    || observed.expected_generated_presentation_identities_complete === true;
  if (!source && expectedIdentities.length === 0) return null;
  const missingIdentities = expectedIdentities.filter((identity) => !observedIdentities.includes(identity));
  const iframeCount = Number(observed.iframeCount || observed.iframe_count || 0);
  const idleCanvas = objectValue(observed.idleCanvas || observed.idle_canvas);
  const matchedRendering = objectValue(observed.matchedRendering || observed.matched_rendering);
  return {
    schema: 'static-site-importer/editor-presentation-evidence/v3',
    provider_schema: observed.schema || '',
    canvas_document_type: observed.canvasDocumentType || observed.canvas_document_type || '',
    iframe_count: iframeCount,
    expected_identity_count: expectedIdentities.length,
    observed_identity_count: observedIdentities.length,
    expected_identities: expectedIdentities,
    observed_identities: observedIdentities,
    missing_identities: missingIdentities,
    expected_identities_complete: expectedIdentitiesComplete,
    coverage_complete: expectedIdentitiesComplete && expectedIdentities.length > 0 && missingIdentities.length === 0,
    idle_canvas: {
      schema: idleCanvas.schema || '',
      status: idleCanvas.status || '',
      onboarding_modal_count: Number(idleCanvas.onboardingModalCount ?? idleCanvas.onboarding_modal_count ?? -1),
    },
    matched_rendering: {
      schema: matchedRendering.schema || '',
      status: matchedRendering.status || '',
      equivalent_canvas_widths: matchedRendering.equivalentCanvasWidths === true || matchedRendering.equivalent_canvas_widths === true,
      major_geometry_drift: booleanValue(matchedRendering.majorGeometryDrift ?? matchedRendering.major_geometry_drift),
      unreadable_content: booleanValue(matchedRendering.unreadableContent ?? matchedRendering.unreadable_content),
      hidden_content: booleanValue(matchedRendering.hiddenContent ?? matchedRendering.hidden_content),
      unresolved_asset_count: Number(matchedRendering.unresolvedAssetCount ?? matchedRendering.unresolved_asset_count ?? -1),
      frontend_screenshot: firstString([matchedRendering.frontendScreenshot, matchedRendering.frontend_screenshot]),
      editor_screenshot: firstString([matchedRendering.editorScreenshot, matchedRendering.editor_screenshot]),
      diff_screenshot: firstString([matchedRendering.diffScreenshot, matchedRendering.diff_screenshot]),
    },
  };
}

function booleanValue(value) {
  return typeof value === 'boolean' ? value : null;
}

export function collectEditorInteraction(payloads) {
  const payload = normalizeArray(payloads).find((value) => {
    const row = objectValue(value);
    const isEditorActions = row.command === 'wordpress.editor-actions' || row.artifactType === 'editor-actions' || row.artifact_type === 'editor-actions';
    return isEditorActions && normalizeArray(row.steps).length > 0 && Object.keys(objectValue(row.summary)).length > 0;
  });
  if (!payload) return null;
  const row = objectValue(payload);
  const summary = objectValue(row.summary);
  const steps = normalizeArray(row.steps).filter((step) => objectValue(step).index !== 0);
  const byKind = new Map(steps.map((step) => [objectValue(step).kind, objectValue(step)]));
  const transition = (kind) => {
    const step = objectValue(byKind.get(kind));
    return {
      status: step.status || '',
      ...(Object.keys(objectValue(step.editorMutation || step.editor_mutation)).length
        ? { mutation_status: objectValue(step.editorMutation || step.editor_mutation).status || '' }
        : {}),
    };
  };
  const save = objectValue(summary.editorSave || summary.editor_save);
  const validity = objectValue(summary.editorValidity || summary.editor_validity);
  return {
    schema: 'static-site-importer/editor-interaction-evidence/v1',
    provider_schema: 'wp-codebox/editor-actions/v1',
    selection: transition('selectBlock'),
    text_mutation: transition('insertBlock'),
    block_movement: transition('moveBlock'),
    save: { schema: save.schema || '', status: save.status || '', marker_present: save.markerPresent === true || save.marker_present === true },
    reload: transition('reload'),
    post_save_validation: { schema: validity.schema || '', status: validity.status || '' },
  };
}

export function collectSurfaceRecords(payloads) {
  return payloads.flatMap((payload, index) => {
    const metadata = objectValue(payload.metadata);
    const explicitId = firstString([metadata.surface_id, metadata.surfaceId, payload.surface_id, payload.surfaceId]);
    const command = firstString([payload.command, metadata.command]);
    const phase = recipePhase(payload);
    const role = /visual-compare|visual/i.test(command) || phase === 'visual'
      ? 'visual'
      : /editor-(?:open|validate)|editor/i.test(command) || phase === 'editor'
        ? 'editor'
        : '';
    // Import/transform payloads routinely contain a post-like value but do not
    // identify a browser surface. They must not overwrite editor/capture lineage.
    if (!explicitId && !role) return [];
    return [compactObject({
      surface_id: explicitId || 'front-page',
      role,
      command,
      provenance_index: index,
      source_url: firstString([metadata.source_url, metadata.sourceUrl, payload.source_url, payload.sourceUrl]),
      candidate_url: firstString([metadata.candidate_url, metadata.candidateUrl, payload.candidate_url, payload.candidateUrl]),
      post_id: firstString([metadata.post_id, metadata.postId, payload.post_id, payload.postId]),
      post_type: firstString([metadata.post_type, metadata.postType, payload.post_type, payload.postType]),
      post_slug: firstString([metadata.post_slug, metadata.postSlug, payload.post_slug, payload.postSlug]),
      target: firstString([metadata.target, payload.target]),
      status: firstString([payload.status, metadata.status]),
      success: typeof payload.success === 'boolean' ? payload.success : undefined,
      artifact_refs: collectPayloadArtifactRefs(payload),
    })];
  });
}

// Keep each command result separate: merging runtime payloads is appropriate for
// fixture status, but it otherwise discards secondary route visual evidence.
function collectVisualParityComparisons(payloads, visualParityOptions) {
  return payloads
    .flatMap((payload) => {
      const metadata = objectValue(payload.metadata);
      const explicitSurfaceId = firstString([metadata.surface_id, metadata.surfaceId, payload.surface_id, payload.surfaceId]);
      return collectVisualParityArtifactComparisons(payload, visualParityOptions).map((comparison) => ({
        surface_id: visualComparisonSurfaceId({ explicitSurfaceId, comparison }),
        source_url: firstString([metadata.source_url, metadata.sourceUrl]),
        candidate_url: firstString([metadata.candidate_url, metadata.candidateUrl]),
        visual_parity_artifacts: comparison.visual_parity_artifacts,
      }));
    })
    .filter((comparison) => comparison.visual_parity_artifacts);
}

// Provider and capture results can be retried within one fixture run. Only join
// them to a diagnostic when their bounded run/step/diagnostic identity matches.
function attachDiagnosticLineage(diagnostics, payloads) {
  return diagnostics.map((diagnostic) => {
    const row = objectValue(diagnostic);
    const correlation = correlationIdentity(row);
    const evidence = payloads.flatMap((payload) => correlatedLineage(payload, correlation));
    const lineage = evidence.reduce((result, item) => ({ ...result, ...item }), {});
    const boundary = firstString([row.attribution_boundary, row.attributionBoundary, inferredDiagnosticBoundary(row)]);
    return {
      ...row,
      ...(boundary ? { attribution_boundary: boundary } : {}),
      ...(Object.keys(lineage).length > 0 ? { attribution_evidence: lineage } : {}),
    };
  });
}

function inferredDiagnosticBoundary(diagnostic) {
  const kind = String(diagnostic.kind || diagnostic.code || '').toLowerCase();
  const lossClass = String(diagnostic.loss_class || diagnostic.lossClass || '').toLowerCase();
  if (['editor_block_invalid', 'invalid_block_content', 'low_native_conversion', 'runtime_target_gap'].includes(kind) || ['editor_block_invalid', 'invalid_block_content', 'low_native_conversion', 'runtime_target_gap'].includes(lossClass)) return 'transform';
  if (['importer_materialization_bug', 'missing_asset', 'dropped_images'].includes(kind) || ['importer_materialization_bug', 'missing_asset'].includes(lossClass)) return 'materialization';
  return '';
}

function correlatedLineage(payload, correlation) {
  if (!correlation) return [];
  const row = objectValue(payload);
  const payloadCorrelation = correlationIdentity(row);
  if (!sameCorrelation(correlation, payloadCorrelation)) return [];
  const importReport = objectValue(row.import_report || row.importReport || row.report);
  const provider = objectValue(row.provider_adapter || row.providerAdapter || importReport.provider_adapter || importReport.providerAdapter);
  const capture = objectValue(row.capture_contract || row.captureContract || row.visual_capture || row.visualCapture);
  const result = compactObject({
    provider_adapter: Object.keys(provider).length > 0 ? lineageStatus(provider) : undefined,
    capture: Object.keys(capture).length > 0 ? lineageStatus(capture) : undefined,
    correlation,
  });
  return Object.keys(result).length > 1 ? [result] : [];
}

function correlationIdentity(value) {
  const row = objectValue(value);
  const metadata = objectValue(row.metadata);
  const runId = firstString([row.run_id, row.runId, metadata.run_id, metadata.runId]);
  const stepId = firstString([row.step_id, row.stepId, row.recipe_step_index, row.recipeStepIndex, metadata.step_id, metadata.stepId, metadata.recipe_step_index, metadata.recipeStepIndex]);
  const diagnosticId = firstString([row.diagnostic_id, row.diagnosticId, row.id]);
  return runId || stepId || diagnosticId ? compactObject({ run_id: runId, step_id: stepId, diagnostic_id: diagnosticId }) : null;
}

function sameCorrelation(left, right) {
  if (!left || !right) return false;
  const keys = ['run_id', 'step_id', 'diagnostic_id'].filter((key) => left[key] || right[key]);
  return keys.length > 0 && keys.every((key) => left[key] && right[key] && left[key] === right[key]);
}

function lineageStatus(value) {
  const row = objectValue(value);
  return compactObject({ schema: firstString([row.schema]), status: firstString([row.status, row.result]), provider: firstString([row.provider, row.name]) });
}

function visualComparisonSurfaceId({ explicitSurfaceId, comparison }) {
  return explicitSurfaceId
    || surfaceIdFromSourcePath(comparison.source_path)
    || surfaceIdFromVisualArtifacts(comparison.visual_parity_artifacts)
    || 'front-page';
}

function surfaceIdFromSourcePath(sourcePath) {
  const fileName = String(sourcePath || '').split(/[?#]/, 1)[0].split('/').pop() || '';
  const stem = fileName.replace(/\.html?$/i, '');
  return stem && stem !== 'index' ? stem : '';
}

function surfaceIdFromVisualArtifacts(artifacts) {
  for (const slot of Object.values(objectValue(artifacts?.artifacts))) {
    const refPath = firstString([slot?.ref?.path, slot?.ref?.file, slot?.path]);
    const directory = refPath.split('/').filter(Boolean).at(-2) || '';
    const match = directory.match(/--([^/]+)$/);
    if (match?.[1]) {
      return match[1];
    }
  }
  return '';
}

const WORDPRESS_SITE_PLAN_SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
const MATERIALIZATION_PLAN_ASSET_LIMIT = 50;

// Keep enough runtime evidence to attribute CSS/JS behavior without retaining
// arbitrary source payloads in matrix artifacts.
export function collectMatrixEvidence(payload, options = {}) {
  const fixtureDiagnostics = objectValue(payload.fixture_diagnostics || payload.fixtureDiagnostics);
  const blocksEngine = objectValue(payload.blocks_engine || payload.blocksEngine || payload.import_report?.blocks_engine || payload.importReport?.blocks_engine || payload.report?.blocks_engine || fixtureDiagnostics.blocks_engine || fixtureDiagnostics.blocksEngine);
  const transformer = objectValue(blocksEngine.transformer || blocksEngine.transformer_provenance || blocksEngine.transformerProvenance);
  const plan = objectValue(blocksEngine.wordpress_site_plan || blocksEngine.wordpressSitePlan);
  const importReport = objectValue(payload.import_report || payload.importReport || payload.report);
  const materializationReceipt = objectValue(payload.materialization_receipt || payload.materializationReceipt || importReport.materialization_receipt || importReport.materializationReceipt || fixtureDiagnostics.materialization_receipt || fixtureDiagnostics.materializationReceipt);
  const planIdentity = objectValue(materializationReceipt.plan_identity || materializationReceipt.planIdentity);
  const providerAdapter = objectValue(payload.provider_adapter || payload.providerAdapter || importReport.provider_adapter || importReport.providerAdapter || fixtureDiagnostics.provider_adapter || fixtureDiagnostics.providerAdapter);
  const captureContract = objectValue(payload.capture_contract || payload.captureContract || payload.visual_capture || payload.visualCapture || fixtureDiagnostics.capture_contract || fixtureDiagnostics.captureContract);
  const sidecar = options.sidecar || { status: 'absent' };
  const declaredOverlays = options.dependencyOverlays || options.dependency_overlays;
  const override = Array.isArray(declaredOverlays)
    ? objectValue(declaredOverlays.find((overlay) => overlay?.kind === 'composer-package' && overlay?.package === 'automattic/blocks-engine-php-transformer' && overlay?.consumer === 'static-site-importer'))
    : objectValue(options.dependencyOverrides || options.dependency_overrides).blocks_engine_php_transformer || objectValue(options.dependencyOverrides || options.dependency_overrides).blocksEnginePhpTransformer || {};
  const completedMaterialization = objectValue(materializationReceipt.completed);
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
    ...(completedMaterializationReceipt(materializationReceipt, planIdentity) ? [] : ['materialization_receipt']),
  ];
  const sourceAssets = normalizeArray(plan.assets);
  const blockProvenance = normalizeArray(materializationReceipt.block_provenance || materializationReceipt.blockProvenance || completedMaterialization.block_provenance || completedMaterialization.blockProvenance).slice(0, 50).map(blockProvenanceSummary);
  const assetCount = declaredAssetCount(plan) ?? sourceAssets.length;
  const assets = sourceAssets
    .map(materializationPlanAssetSummary)
    .filter((asset) => Object.keys(asset).length > 0)
    .sort((left, right) => String(left.path || left.source || '').localeCompare(String(right.path || right.source || '')))
    .slice(0, MATERIALIZATION_PLAN_ASSET_LIMIT);
  return {
    schema: 'static-site-importer/fixture-matrix-runtime-evidence/v1',
    readiness: missing.length === 0 ? 'verified' : 'runtime_evidence_incomplete',
    missing,
    transformer: provenance,
    lineage: compactObject({
      development_override: Object.keys(override).length > 0 ? compactObject({
        package: firstString([override.package]),
        reference: firstString([override.reference]),
      }) : undefined,
      artifact: plan.schema === WORDPRESS_SITE_PLAN_SCHEMA ? { schema: plan.schema } : undefined,
      provider_adapter: Object.keys(providerAdapter).length > 0 ? compactObject({
        schema: firstString([providerAdapter.schema]),
        status: firstString([providerAdapter.status, providerAdapter.result]),
        provider: firstString([providerAdapter.provider, providerAdapter.name]),
      }) : undefined,
      capture: Object.keys(captureContract).length > 0 ? compactObject({
        schema: firstString([captureContract.schema]),
        status: firstString([captureContract.status, captureContract.result]),
        source: firstString([captureContract.source]),
        candidate: firstString([captureContract.candidate]),
      }) : undefined,
    }),
    wordpress_site_plan: compactObject({
      schema: plan.schema,
      asset_count: assetCount,
      assets_truncated: !sitePlanAssetsComplete(plan) || sourceAssets.length > MATERIALIZATION_PLAN_ASSET_LIMIT,
      assets,
    }),
    materialization_receipt: compactObject({
      schema: materializationReceipt.schema,
      status: materializationReceipt.status,
      plan_hash: materializationReceipt.plan_hash || materializationReceipt.planHash || planIdentity.hash,
      plan_identity: Object.keys(planIdentity).length > 0 ? compactObject({ schema: planIdentity.schema, hash: planIdentity.hash }) : undefined,
      page_count: Number.isFinite(Number(materializationReceipt.page_count ?? materializationReceipt.pageCount)) ? Number(materializationReceipt.page_count ?? materializationReceipt.pageCount) : Object.keys(objectValue(completedMaterialization.pages)).length,
      file_count: Number.isFinite(Number(materializationReceipt.file_count ?? materializationReceipt.fileCount)) ? Number(materializationReceipt.file_count ?? materializationReceipt.fileCount) : normalizeArray(completedMaterialization.files).length,
      operation_count: Number.isFinite(Number(materializationReceipt.operation_count ?? materializationReceipt.operationCount)) ? Number(materializationReceipt.operation_count ?? materializationReceipt.operationCount) : normalizeArray(completedMaterialization.operations).length,
      declaration_count: Number.isFinite(Number(materializationReceipt.declaration_count ?? materializationReceipt.declarationCount)) ? Number(materializationReceipt.declaration_count ?? materializationReceipt.declarationCount) : normalizeArray(completedMaterialization.declaration_ids || completedMaterialization.declarationIds).length,
      ...(materializationReceipt.block_provenance_count !== undefined || materializationReceipt.blockProvenanceCount !== undefined ? { block_provenance_count: Number.isFinite(Number(materializationReceipt.block_provenance_count ?? materializationReceipt.blockProvenanceCount)) ? Number(materializationReceipt.block_provenance_count ?? materializationReceipt.blockProvenanceCount) : blockProvenance.length } : {}),
      ...(materializationReceipt.block_provenance_truncated !== undefined || materializationReceipt.blockProvenanceTruncated !== undefined ? { block_provenance_truncated: Boolean(materializationReceipt.block_provenance_truncated ?? materializationReceipt.blockProvenanceTruncated) } : {}),
      ...(blockProvenance.length > 0 ? { block_provenance: blockProvenance } : {}),
    }),
    materialization_sidecar: compactObject({
      status: sidecar.status,
      schema: sidecar.payload?.schema,
      matrix_run_id: sidecar.payload?.run_id,
      attempt_id: sidecar.payload?.attempt_id,
      source_artifact_sha256: sidecar.payload?.artifact_sha256,
      documents: sidecar.payload?.documents,
      documents_truncated: sidecar.payload?.documents_truncated,
      documents_total: sidecar.payload?.documents_total,
      content_sha256: sidecar.payload?.content_sha256,
      artifact_sha256: sidecar.payload?.artifact_sha256,
      provenance: sidecar.payload?.provenance,
      provider_totals: sidecar.payload?.receipt?.provider_totals,
      computed_layout_totals: sidecar.payload?.receipt?.computed_layout_totals,
      truncation: sidecar.payload?.receipt?.truncated,
      ...(sidecar.status !== 'verified' && sidecar.status !== 'absent' ? { reason: sidecar.status } : {}),
    }),
    import_command: compactObject({
      status: sidecar.payload?.command_result?.status,
      success: sidecar.payload?.command_result?.success,
      error_code: sidecar.payload?.command_result?.error_code,
      error_hash: sidecar.payload?.command_result?.error_hash,
    }),
    front_page_options: compactObject({
      show_on_front: sidecar.payload?.front_page_options?.show_on_front,
      page_on_front: sidecar.payload?.front_page_options?.page_on_front,
    }),
    template_parts: templateParts,
  };
}

function completedMaterializationReceipt(receipt, planIdentity) {
  if (receipt.status !== 'completed') {
    return false;
  }
  if (receipt.schema === 'static-site-importer/materialization-receipt/v1') {
    return true;
  }
  return receipt.schema === 'static-site-importer/materialization-receipt/v2'
    && planIdentity.schema === 'blocks-engine/wordpress-site-plan-identity/v1'
    && sha256(planIdentity.hash);
}

function declaredAssetCount(plan) {
  const value = Number(plan.asset_count ?? plan.assetCount);
  return Number.isInteger(value) && value >= 0 ? value : null;
}

function sitePlanAssetsComplete(plan) {
  const assets = normalizeArray(plan.assets);
  const declaredCount = declaredAssetCount(plan);
  return plan.assets_truncated !== true
    && plan.assetsTruncated !== true
    && (declaredCount === null || declaredCount === assets.length);
}

function blockProvenanceSummary(provenance) {
  const row = objectValue(provenance);
  const source = objectValue(row.source);
  return compactObject({
    source: compactObject({
      schema: firstString([source.schema]),
      source_path: firstString([source.source_path, source.sourcePath]),
      reconciliation_identity: firstString([source.reconciliation_identity, source.reconciliationIdentity]),
    }),
    stages: normalizeArray(row.stages).slice(0, 2).map((stage) => {
      const record = objectValue(stage);
      const output = objectValue(record.output);
      return compactObject({ stage: firstString([record.stage]), input_sha256: firstString([record.input_sha256, record.inputSha256]), output: compactObject({ sha256: firstString([output.sha256]), bytes: Number.isFinite(Number(output.bytes)) ? Number(output.bytes) : undefined }) });
    }),
  });
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
  const scopes = normalizeArray(row.scopes).map((scope) => objectValue(scope));
  return compactObject({
    path: firstString([row.path, row.target_path, row.targetPath]),
    source: firstString([row.source, row.source_path, row.sourcePath]),
    role: firstString([row.role, row.intent]),
    kind: firstString([row.kind]),
    type: firstString([row.type, row.media_type, row.mediaType, row.mime_type, row.mimeType]),
    placement: firstString([row.placement]),
    defer: typeof row.defer === 'boolean' ? row.defer : undefined,
    async: typeof row.async === 'boolean' ? row.async : undefined,
    ...(scopes.length > 0 ? { scopes } : {}),
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
  const explicitStatus = row.preservation_status || row.preservationStatus || source.preservation_status || source.preservationStatus;
  return (String(row.acceptability || '').trim() === 'acceptable_preservation'
    && /accepted[_-]runtime[_-]preservation|preserved[_-]runtime[_-]island/i.test(String(row.repair_mode || row.repairMode || row.repair_bucket || row.repairBucket || row.group_key || row.groupKey || row.loss_class || row.lossClass || ''))
    || /accepted[_-]runtime[_-]preservation/i.test(String(explicitStatus || '')))
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
  const sidecars = fs.existsSync(fixtureArtifactsDirectory)
    ? fs.readdirSync(fixtureArtifactsDirectory).filter((fileName) => /^materialization-receipt--[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.json$/.test(fileName))
    : [];
  for (const fileName of ['artifact.json', 'policy-rejection-artifact.json', 'validation-result.json', 'import-report.json', ...sidecars]) {
    const filePath = path.join(fixtureArtifactsDirectory, fileName);
    if (fs.existsSync(filePath)) {
      refs.push(artifactRef(fileName.replace(/\.json$/, ''), filePath, fileName === 'artifact.json' ? 'input' : 'diagnostic'));
    }
  }
  return dedupeArtifactRefs(refs);
}

const MATERIALIZATION_SIDECAR_SCHEMAS = new Set([
  'static-site-importer/materialization-runtime-sidecar/v1',
  'static-site-importer/materialization-runtime-sidecar/v2',
]);
const MATERIALIZED_DOCUMENT_IDENTITY_FIELDS = ['post_id', 'post_type', 'post_slug', 'serialized_content_sha256'];
const MATERIALIZED_DOCUMENT_LINEAGE_FIELDS = ['source_path', 'route', ...MATERIALIZED_DOCUMENT_IDENTITY_FIELDS];

// Sidecars are written by the import command before its verbose JSON reaches a
// capped transport. Validate both correlation and immutable fixture input before
// allowing this compact evidence to replace any truncated command output.
function readMaterializationSidecar(directory, fixtureId, runId, attemptId) {
  const filePath = path.join(directory, `materialization-receipt--${attemptId}.json`);
  if (!fs.existsSync(filePath)) return { status: 'missing', path: filePath };
  if (fs.statSync(filePath).size > 32 * 1024) return { status: 'malformed', path: filePath };
  let payload;
  try {
    payload = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return { status: 'malformed', path: filePath };
  }
  return validateMaterializationSidecarPayload(payload, directory, fixtureId, runId, attemptId, filePath);
}

function readTransportedMaterializationSidecar(value, directory, fixtureId, runId, attemptId) {
  const candidates = [];
  visit(value, new Set());
  for (const candidate of candidates) {
    const validated = validateMaterializationSidecarPayload(candidate.payload, directory, fixtureId, runId, attemptId, candidate.path);
    if (validated.status === 'verified') return validated;
  }
  return { status: 'missing', reason: 'missing' };

  function visit(current, seen) {
    if (!current || typeof current !== 'object' || seen.has(current)) return;
    seen.add(current);
    if (Array.isArray(current)) {
      current.forEach((entry) => visit(entry, seen));
      return;
    }
    const row = objectValue(current);
    if (MATERIALIZATION_SIDECAR_SCHEMAS.has(row.schema)) {
      candidates.push({ payload: row, path: '' });
    }
    if (row.schema === 'wp-codebox/recipe-declared-artifact-result/v1' && row.status === 'collected') {
      const payload = objectValue(row.parsedJson || row.parsed_json);
      if (MATERIALIZATION_SIDECAR_SCHEMAS.has(payload.schema)) {
        candidates.push({ payload, path: objectValue(row.materialized).path || row.path || '' });
      }
    }
    for (const key of ['stdout', 'stderr', 'output', 'result']) {
      for (const parsed of parseJsonPayloadsFromText(row[key])) {
        visit(parsed, seen);
      }
    }
    Object.values(row).forEach((entry) => visit(entry, seen));
  }
}

function validateMaterializationSidecarPayload(payload, directory, fixtureId, runId, attemptId, filePath) {
  const row = objectValue(payload);
  if (!validSidecar(row)) return { status: 'malformed', path: filePath };
  if (row.fixture_id !== fixtureId) return { status: 'cross_fixture', path: filePath };
  if (row.run_id !== runId || row.step_id !== 'import' || row.attempt_id !== attemptId) return { status: 'stale', path: filePath };
  const artifactPath = path.join(directory, 'artifact.json');
  if (!fs.existsSync(artifactPath) || row.artifact_sha256 !== fileSha256(artifactPath)) return { status: 'hash_mismatch', path: filePath };
  const expectedContentHash = sidecarContentHash(row);
  if (!row.content_sha256 || row.content_sha256 !== expectedContentHash) return { status: 'hash_mismatch', path: filePath };
  return { status: 'verified', path: filePath, payload: row };
}

function validSidecar(row) {
  const receipt = objectValue(row.receipt);
  const allowed = ['schema', 'fixture_id', 'run_id', 'step_id', 'attempt_id', 'artifact_sha256', 'provenance', 'durability', 'receipt', 'command_result', 'front_page_options', 'content_sha256'];
  if (row.schema === 'static-site-importer/materialization-runtime-sidecar/v2') {
    allowed.push('documents', 'documents_truncated', 'documents_total');
  }
  if (Object.keys(row).some((key) => !allowed.includes(key))) return false;
  if (!MATERIALIZATION_SIDECAR_SCHEMAS.has(row.schema) || !safeToken(row.fixture_id, 80) || !safeToken(row.run_id, 160) || row.step_id !== 'import' || !safeToken(row.attempt_id, 80) || !sha256(row.artifact_sha256) || !sha256(row.content_sha256)) return false;
  if (!validProvenance(row.provenance) || !validDurability(row.durability) || !validReceipt(receipt) || (row.command_result !== undefined && !validCommandResult(row.command_result)) || (row.front_page_options !== undefined && !validFrontPageOptions(row.front_page_options))) return false;
  if (row.schema === 'static-site-importer/materialization-runtime-sidecar/v2' && !validDocuments(row.documents, row.documents_truncated, row.documents_total)) return false;
  return true;
}

function validDocuments(documents, truncated, total) {
  return Array.isArray(documents) && documents.length <= 25 && typeof truncated === 'boolean' && boundedCount(total)
    && total >= documents.length && truncated === (total > documents.length)
    && documents.every(validMaterializedDocument);
}

// Runtime sidecars record source and route joins alongside the post identity.
// Four-field rows were persisted before that complete contract reached intake.
function validMaterializedDocument(document) {
  const row = objectValue(document);
  const identity = safeToken(row.post_id, 20)
    && safeToken(row.post_type, 80)
    && safeToken(row.post_slug, 200)
    && sha256(row.serialized_content_sha256);
  if (!identity) return false;

  const fields = Object.keys(row);
  if (fields.length === 4) {
    return fields.every((field) => MATERIALIZED_DOCUMENT_IDENTITY_FIELDS.includes(field));
  }
  return fields.length === 6
    && fields.every((field) => MATERIALIZED_DOCUMENT_LINEAGE_FIELDS.includes(field))
    && validMaterializedDocumentLineageValue(row.source_path)
    && validMaterializedDocumentRoute(row.route);
}

// Match the PHP sidecar writer: printable ASCII, 500-byte maximum, and an
// absolute path for routes returned from wp_parse_url().
function validMaterializedDocumentLineageValue(value) {
  return typeof value === 'string' && value.length > 0 && value.length <= 500 && /^[\x20-\x7e]+$/.test(value);
}

function validMaterializedDocumentRoute(value) {
  return validMaterializedDocumentLineageValue(value) && value.startsWith('/');
}

function validDurability(value) {
  const row = objectValue(value);
  return Object.keys(row).length === 2
    && ['available', 'unavailable'].includes(row.file_fsync)
    && ['attempted', 'unavailable'].includes(row.directory_fsync);
}

function validProvenance(value) {
  const row = objectValue(value);
  return Object.keys(row).length === 2 && row.provider === 'static-site-importer/current-runtime' && ['completed', 'failed'].includes(row.provider_status);
}

function validReceipt(row) {
  const allowed = ['schema', 'status', 'plan_hash', 'plan_identity', 'page_count', 'file_count', 'operation_count', 'loss_count', 'failure_code', 'provider_totals', 'computed_layout_totals', 'operation_rows', 'loss_rows', 'truncated'];
  if (Object.keys(row).some((key) => !allowed.includes(key))) return false;
  if (!['static-site-importer/materialization-receipt/v1', 'static-site-importer/materialization-receipt/v2'].includes(row.schema) || !['completed', 'failed'].includes(row.status)) return false;
  if (row.status === 'failed') return boundedCount(row.page_count) && boundedCount(row.file_count) && boundedCount(row.operation_count) && boundedCount(row.loss_count) && safeToken(row.failure_code, 80);
  if (row.schema === 'static-site-importer/materialization-receipt/v1' && !sha256(row.plan_hash)) return false;
  if (row.schema === 'static-site-importer/materialization-receipt/v2' && !validPlanIdentity(row.plan_identity)) return false;
  if (!['page_count', 'file_count', 'operation_count', 'loss_count'].every((key) => boundedCount(row[key]))) return false;
  if (!validCounts(row.provider_totals, ['completed']) || !validCounts(row.computed_layout_totals, ['applied', 'losses', 'operations'])) return false;
  if (!Array.isArray(row.operation_rows) || !Array.isArray(row.loss_rows) || row.operation_rows.length > 25 || row.loss_rows.length > 25) return false;
  if (![...row.operation_rows, ...row.loss_rows].every(validSidecarRow)) return false;
  const truncated = objectValue(row.truncated);
  return Object.keys(truncated).length === 2 && typeof truncated.operation_rows === 'boolean' && typeof truncated.loss_rows === 'boolean';
}

function validPlanIdentity(value) {
  const identity = objectValue(value);
  return Object.keys(identity).length === 2
    && identity.schema === 'blocks-engine/wordpress-site-plan-identity/v1'
    && sha256(identity.hash);
}

function validCommandResult(value) {
  const row = objectValue(value);
  return Object.keys(row).every((key) => ['status', 'success', 'error_code', 'error_hash'].includes(key))
    && ['completed', 'failed'].includes(row.status)
    && typeof row.success === 'boolean'
    && row.success === (row.status === 'completed')
    && typeof row.error_code === 'string'
    && (row.status === 'completed' || safeToken(row.error_code, 80))
    && sha256(row.error_hash);
}

function validFrontPageOptions(value) {
  const row = objectValue(value);
  return Object.keys(row).length === 2 && safeToken(row.show_on_front, 20) && boundedCount(row.page_on_front);
}

function validCounts(value, keys) {
  const row = objectValue(value);
  return Object.keys(row).every((key) => keys.includes(key)) && Object.values(row).every(boundedCount);
}

function validSidecarRow(value) {
  const row = objectValue(value);
  return Object.keys(row).every((key) => ['kind', 'status', 'reason_code', 'hash'].includes(key))
    && safeToken(row.kind, 80) && (!Object.hasOwn(row, 'status') || safeToken(row.status, 40))
    && (!Object.hasOwn(row, 'reason_code') || safeToken(row.reason_code, 80)) && sha256(row.hash);
}

function safeToken(value, maximum) {
  return typeof value === 'string' && value.length > 0 && value.length <= maximum && /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/.test(value);
}

function sha256(value) {
  return typeof value === 'string' && /^(?:sha256:)?[a-f0-9]{64}$/.test(value);
}

function boundedCount(value) {
  return Number.isInteger(value) && value >= 0 && value <= 10000000;
}

function sidecarContentHash(sidecar) {
  const { content_sha256: _contentHash, ...unsigned } = sidecar;
  return createHash('sha256').update(JSON.stringify(unsigned)).digest('hex');
}

function fileSha256(filePath) {
  return createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
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
    if (Array.isArray(value)) {
      value.forEach((filePath, index) => {
        if (typeof filePath === 'string' && filePath) {
          refs.push({ artifact_id: `editor-open-${key}-${index + 1}`, kind: 'editor-canvas', path: filePath });
        }
      });
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
    failure_stage: failure.failure_stage,
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
      payloads.push({ fixture_id: fixtureId, metadata: objectValue(value.metadata), ...parsed });
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
  return value.schema === PROVIDER_SUBMISSION_EVIDENCE_SCHEMA || ['status', 'success', 'ok', 'passed', 'error', 'diagnostics', 'findings', 'summary', 'artifacts', 'upstream_gaps', 'runtime_target_gaps', 'blocks_engine', 'import_report', 'provider_adapter', 'providerAdapter', 'provider_submission_evidence', 'providerSubmissionEvidence', 'capture_contract', 'captureContract', 'visual_capture', 'visualCapture', 'editor_canvas', 'editorCanvas', 'editor_open', 'editorOpen']
    .some((key) => Object.hasOwn(value, key));
}

function collectProviderSubmissionEvidence(payloads) {
  const rows = payloads.flatMap((payload) => payload?.schema === PROVIDER_SUBMISSION_EVIDENCE_SCHEMA
    ? [payload]
    : normalizeArray(payload?.provider_submission_evidence || payload?.providerSubmissionEvidence));
  return [...new Map(rows.map((row) => [JSON.stringify(row), row])).values()];
}

function readFixturePayloadFiles(directory) {
  return ['validation-result.json', 'result.json', 'import-report.json', 'quality.json', 'blocks-engine-diagnostics.json', 'provider-submission-evidence.json', 'editor-validation.json', 'editor-validate-blocks.json', 'editor-open.json', 'editor-summary.json', 'editor-action-summary.json', 'editor-state.json', 'editor-validity.json', 'editor-canvas-summary.json', 'visual-compare.json', 'visual-diff.json', 'visual-parity.json', 'visual-explanation.json']
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

function inferFixtureSuccess(payload, diagnostics, error, payloadCount, policyGateFailed = false) {
  if (policyGateFailed) return false;
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

function fixtureStatus(payloadCount, error, success, policyGateFailed = false) {
  if (policyGateFailed) {
    return 'failed';
  }
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
