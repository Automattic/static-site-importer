// Result normalization, quality gating, summary/aggregate rollups, and artifact
// writing for the Static Site Importer fixture matrix.
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
  FIXTURE_MATRIX_RESULT_SCHEMA,
  ACCEPTABLE_LOSS_CLASSES,
  UNACCEPTABLE_LOSS_CLASSES,
} from './shared/constants.mjs';
import {
  normalizeArray,
  objectValue,
  numberValue,
  compactObject,
  pushUnique,
  countBy,
  requiredString,
  writeJsonFile,
  artifactRef,
} from './shared/utils.mjs';
import { boundBlob } from './shared/bounds.mjs';
import {
  createFixtureMatrix,
  classifyFixture,
  normalizeFixtureClass,
  fixtureClassRank,
  fixtureManifestCoverage,
  normalizeManifestCapabilities,
  normalizeManifestRiskProfile,
  normalizeManifestQualityBudgets,
} from './fixtures.mjs';
import {
  findingsForFixtureResult,
  dedupeFindings,
  isActionableFinding,
  selectorFamily,
  patternFamily,
} from './findings.mjs';
import {
  buildGutenbergIncompatibilityRegistry,
  renderGutenbergIncompatibilityRegistryMarkdown,
} from './gutenberg-incompatibility-registry.mjs';
import {
  buildVisualParityEvidenceReport,
  renderVisualParityEvidenceReportMarkdown,
} from './visual-evidence-report.mjs';
import {
  collectBlockComposition,
  computeFixtureEditorQuality,
  attachFixtureEditorQuality,
  aggregateEditorQuality,
  normalizeNativeRateGateOptions,
  buildNativeRateGateFindings,
  accumulateEditorQuality,
  finalizeEditorQuality,
} from './collectors/quality-metrics.mjs';
import { buildFixtureArtifact, stageFixtureSource } from './steps/recipe-builder.mjs';

export function normalizeFixtureMatrixResult(input = {}) {
  const matrix = input.matrix || createFixtureMatrix(input);
  const generationStatus = input.generationStatus || input.generation_status || 'succeeded';
  const executionStatus = normalizeExecutionStatus(input);
  const executionRequested = executionStatus !== 'not_requested';
  const results = normalizeArray(input.results || input.fixture_results || input.fixtureResults).map(normalizeFixtureResult);
  const resultByFixture = new Map(results.map((result) => [result.fixture_id, result]));
  const fixtureResults = matrix.fixtures.map((fixture) => attachFixtureTaxonomy(
    resultByFixture.get(fixture.id) || normalizeFixtureResult({ fixture_id: fixture.id, fixture_path: fixture.fixture_path, status: 'not_run' }),
    fixture,
  ));
  const baseFindings = dedupeFindings(fixtureResults.flatMap((result) => findingsForFixtureResult(result, { matrix, executionRequested })));
  // Editor-quality metrics are computed from generic block-composition data plus
  // the #537 editor-invalid findings. Scoring always runs; gating is opt-in.
  const nativeRateGate = normalizeNativeRateGateOptions(input.editorQuality || input.editor_quality || input);
  const editorQualityByFixture = new Map(fixtureResults.map((result) => [result.fixture_id, computeFixtureEditorQuality(result, baseFindings)]));
  const nativeRateGateFindings = nativeRateGate.minNativeRate > 0
    ? buildNativeRateGateFindings(fixtureResults, editorQualityByFixture, nativeRateGate)
    : [];
  const findings = dedupeFindings([...baseFindings, ...nativeRateGateFindings]);
  const actionableFindings = findings.filter(isActionableFinding);
  const grouped = groupFindings(actionableFindings);
  const acceptableActionableFindings = actionableFindings.filter((finding) => finding.loss_acceptance === 'acceptable');
  const unacceptableActionableFindings = actionableFindings.filter((finding) => finding.loss_acceptance !== 'acceptable');
  const gatedFixtureResults = fixtureResults.map((result) => attachFixtureEditorQuality(applyFixtureQualityGate(result, findings, { executionRequested }), editorQualityByFixture.get(result.fixture_id)));
  const lossClassCounts = countBy(findings, (finding) => finding.loss_class || 'unsupported_loss');
  const acceptanceCounts = countBy(findings, (finding) => finding.loss_acceptance || 'unacceptable');
  const classRollups = fixtureClassRollups(gatedFixtureResults, findings);
  const fanoutGroups = buildFanoutGroups(actionableFindings);
  const categoryRollups = fixtureCategoryRollups(gatedFixtureResults, findings);
  const gutenbergIncompatibilityRegistry = buildGutenbergIncompatibilityRegistry({
    schema: FIXTURE_MATRIX_RESULT_SCHEMA,
    matrix_id: matrix.id,
    fixtures: gatedFixtureResults,
    findings,
  });
  const slowFixtures = normalizeSlowFixtures(input.slow_fixtures || input.slowFixtures);
  const evidenceReadiness = matrixEvidenceReadiness(gatedFixtureResults);

  return {
    schema: FIXTURE_MATRIX_RESULT_SCHEMA,
    matrix_id: matrix.id,
    fixture_root: matrix.fixture_root,
    summary: {
      generation_status: generationStatus,
      execution_status: executionStatus,
      fixture_count: matrix.fixtures.length,
      succeeded: gatedFixtureResults.filter((result) => result.status === 'passed').length,
      failed: gatedFixtureResults.filter((result) => result.status === 'failed').length,
      not_run: gatedFixtureResults.filter((result) => result.raw_status === 'not_run').length,
      finding_count: findings.length,
      actionable_finding_count: actionableFindings.length,
      non_actionable_finding_count: findings.length - actionableFindings.length,
      acceptable_finding_count: acceptanceCounts.acceptable || 0,
      unacceptable_finding_count: acceptanceCounts.unacceptable || 0,
      loss_classes: lossClassCounts,
      acceptable_loss_classes: Object.fromEntries(Object.entries(lossClassCounts).filter(([key]) => ACCEPTABLE_LOSS_CLASSES.has(key))),
      unacceptable_loss_classes: Object.fromEntries(Object.entries(lossClassCounts).filter(([key]) => UNACCEPTABLE_LOSS_CLASSES.has(key))),
      preserved_runtime_island_count: lossClassCounts.preserved_runtime_island || 0,
      fixture_categories: categoryRollups.fixture_categories,
      fixture_failure_categories: categoryRollups.fixture_failure_categories,
      gate_failure_reasons: categoryRollups.gate_failure_reasons,
      visual_diff_cause_summary: aggregateVisualDiffCauseSummary(gatedFixtureResults),
      visual_diff_top_causes: visualDiffTopCauses(gatedFixtureResults),
      gutenberg_incompatibility_registry: gutenbergIncompatibilityRegistry.summary,
      groups: Object.fromEntries(Object.entries(grouped).map(([key, items]) => [key, items.length])),
      top_pattern_families: topPatternFamilies(actionableFindings),
      top_acceptable_pattern_families: topPatternFamilies(acceptableActionableFindings),
      top_unacceptable_pattern_families: topPatternFamilies(unacceptableActionableFindings),
      unacceptable_candidate_repos: candidateRepoRollups(unacceptableActionableFindings),
      fixture_exemplars: fixtureExemplars(actionableFindings),
      diagnostic_blind_spots: diagnosticBlindSpots(actionableFindings),
      manifest_coverage: matrix.manifest_coverage || fixtureManifestCoverage(matrix.fixtures),
      fixture_classes: Object.fromEntries(Object.entries(classRollups).map(([key, row]) => [key, row.fixture_count])),
      capabilities: capabilityRollups(gatedFixtureResults, findings),
      risk_profiles: riskProfileRollups(gatedFixtureResults, findings),
      classes: classRollups,
      quality_budgets: qualityBudgetSummaries(classRollups),
      editor_quality: aggregateEditorQuality([...editorQualityByFixture.values()], nativeRateGate),
      slow_fixtures: slowFixtures,
      matrix_evidence_readiness: evidenceReadiness,
      metadata: {
        slow_fixtures: slowFixtures,
      },
    },
    fixtures: gatedFixtureResults,
    findings,
    gutenberg_incompatibility_registry: gutenbergIncompatibilityRegistry,
    slow_fixtures: slowFixtures,
    metadata: {
      slow_fixtures: slowFixtures,
    },
    fanout_groups: fanoutGroups.map((group, index) => ({ ...group, index })),
  };
}

function normalizeSlowFixtures(value) {
  return normalizeArray(value)
    .map((row) => compactObject(objectValue(row)))
    .filter((row) => row.fixture_id)
    .sort((left, right) => numberValue(right.duration_ms) - numberValue(left.duration_ms) || String(left.fixture_id).localeCompare(String(right.fixture_id)));
}

function matrixEvidenceReadiness(fixtures) {
  const rows = normalizeArray(fixtures).map((fixture) => {
    const evidence = objectValue(fixture.matrix_evidence || fixture.matrixEvidence);
    const readiness = evidence.readiness || (fixture.raw_status === 'not_run' ? 'not_captured' : 'legacy_evidence_missing');
    return {
      fixture_id: fixture.fixture_id,
      readiness,
      missing: normalizeArray(evidence.missing).map(String).sort(),
    };
  });
  const counts = countBy(rows, (row) => row.readiness);
  return {
    schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1',
    status: counts.legacy_evidence_missing ? 'incomplete' : (counts.not_captured ? 'not_captured' : 'verified'),
    counts,
    fixtures: rows,
  };
}

function normalizeExecutionStatus(input = {}) {
  const explicit = input.executionStatus || input.execution_status;
  if (explicit) {
    return String(explicit);
  }
  if (input.executionRequested === false || input.execution_requested === false || input.run === false) {
    return 'not_requested';
  }
  return 'requested';
}

function applyFixtureQualityGate(result, findings, options = {}) {
  if (options.executionRequested === false && result.status === 'not_run') {
    return {
      ...result,
      raw_status: result.status,
      success: false,
      quality_gate: {
        status: 'not_run',
        acceptable_finding_count: 0,
        unacceptable_finding_count: 0,
        loss_classes: {},
      },
    };
  }

  const fixtureFindings = findings.filter((finding) => finding.fixture_id === result.fixture_id);
  const unacceptableFindings = fixtureFindings.filter((finding) => finding.loss_acceptance === 'unacceptable');
  const fixtureCategories = uniqueSorted(fixtureFindings.flatMap(findingCategories));
  const failureCategories = uniqueSorted(unacceptableFindings.flatMap(findingCategories));
  const status = unacceptableFindings.length > 0 ? 'failed' : 'passed';
  return {
    ...result,
    raw_status: result.status,
    status,
    success: status === 'passed',
    quality_gate: {
      status,
      acceptable_finding_count: fixtureFindings.length - unacceptableFindings.length,
      unacceptable_finding_count: unacceptableFindings.length,
      loss_classes: countBy(fixtureFindings, (finding) => finding.loss_class || 'unsupported_loss'),
      fixture_categories: fixtureCategories,
      failure_categories: failureCategories,
      gate_failure_reasons: gateFailureReasons(unacceptableFindings),
    },
  };
}

function fixtureCategoryRollups(fixtureResults, findings) {
  const findingsByFixture = new Map();
  for (const finding of findings) {
    const fixtureId = finding.fixture_id || '';
    findingsByFixture.set(fixtureId, [...(findingsByFixture.get(fixtureId) || []), finding]);
  }

  const fixtureCategories = {};
  const fixtureFailureCategories = {};
  const reasonRows = [];
  for (const result of fixtureResults) {
    const fixtureFindings = findingsByFixture.get(result.fixture_id) || [];
    const unacceptableFindings = fixtureFindings.filter((finding) => finding.loss_acceptance === 'unacceptable');
    for (const category of uniqueSorted(fixtureFindings.flatMap(findingCategories))) {
      fixtureCategories[category] = (fixtureCategories[category] || 0) + 1;
    }
    if (result.status !== 'failed') {
      continue;
    }
    for (const category of uniqueSorted(unacceptableFindings.flatMap(findingCategories))) {
      fixtureFailureCategories[category] = (fixtureFailureCategories[category] || 0) + 1;
    }
    reasonRows.push(...gateFailureReasons(unacceptableFindings).map((reason) => ({ fixture_id: result.fixture_id, ...reason })));
  }

  return {
    fixture_categories: fixtureCategories,
    fixture_failure_categories: fixtureFailureCategories,
    gate_failure_reasons: reasonRows,
  };
}

function gateFailureReasons(findings) {
  return findings.map((finding) => {
    const categories = findingCategories(finding);
    return compactObject({
      category: categories[0] || 'unsupported_loss',
      categories,
      kind: finding.kind,
      loss_class: finding.loss_class || 'unsupported_loss',
      repair_bucket: finding.repair_bucket || finding.group_key,
      candidate_repo: finding.candidate_repo,
      reason: finding.reason,
    });
  });
}

function findingCategories(finding) {
  const lossClass = finding.loss_class || 'unsupported_loss';
  const categories = [];
  if (lossClass === 'preserved_runtime_island' && finding.loss_acceptance === 'acceptable') {
    categories.push('accepted_runtime_preservation');
  }
  if (lossClass === 'visual_parity_mismatch') {
    categories.push('visual_mismatch');
  }
  if (lossClass === 'visual_timeout' || finding.kind === 'visual_timeout') {
    categories.push('visual_timeout');
  }
  if (lossClass === 'editor_block_invalid' || lossClass === 'invalid_block_content' || finding.group_key === 'editor_block_invalid') {
    categories.push('editor_invalid');
  }
  if (lossClass === 'runtime_execution_failed') {
    categories.push('runtime_execution_failed');
  }
  if (isHarnessDiagnosticFinding(finding)) {
    categories.push('harness_diagnostic');
  }
  if (isMissingEvidenceFinding(finding)) {
    categories.push('missing_evidence');
  }
  if (lossClass === 'unsupported_loss') {
    categories.push('unsupported_loss');
  }
  if (categories.length === 0 && finding.loss_acceptance === 'unacceptable') {
    categories.push(lossClass);
  }
  return uniqueSorted(categories);
}

function isHarnessDiagnosticFinding(finding) {
  return ['fixture_not_run', 'fixture_failed', 'static_site_fixture_diagnostic', 'import_diagnostic', 'diagnostic', 'visual_timeout'].includes(finding.kind)
    || (finding.group_key === 'static_site_import_quality' && isMissingEvidenceFinding(finding));
}

function isMissingEvidenceFinding(finding) {
  return !finding.selector
    && !finding.source_snippet
    && !finding.observed_output
    && !hasPrimitiveEvidence(finding)
    && !hasStructuredEvidence(finding)
    && normalizeArray(finding.artifact_refs).length === 0;
}

function hasPrimitiveEvidence(finding) {
  if (finding.kind !== 'visual_timeout' && finding.loss_class !== 'visual_timeout' && finding.kind !== 'recipe_step_failure') {
    return false;
  }
  return [
    finding.duration_ms,
    finding.timeout_class,
    finding.source_url,
    finding.candidate_url,
    finding.url,
    finding.command,
  ].some((value) => value !== undefined && value !== null && String(value).trim() !== '');
}

function hasStructuredEvidence(finding) {
  if (finding.loss_class === 'visual_parity_mismatch' || finding.kind === 'visual_parity_mismatch') {
    return true;
  }

  if (finding.loss_class === 'editor_block_invalid' || finding.kind === 'editor_block_invalid') {
    return Boolean(finding.observed_block_name || finding.reason || finding.observed_output);
  }

  if (finding.loss_class === 'native_conversion' && finding.loss_acceptance === 'acceptable') {
    return Boolean(finding.reason && finding.source_path);
  }

  return [
    finding.visual_diff,
    finding.selector_evidence,
    finding.property_evidence,
    finding.style_deltas,
    finding.visual_explanation_summary,
    finding.visual_selector_diagnostics,
    finding.visual_property_diagnostics,
    finding.visual_layout_diagnostics,
    finding.visual_capture_diagnostics,
  ].some((value) => {
    if (Array.isArray(value)) {
      return value.length > 0;
    }
    return Object.keys(objectValue(value)).length > 0;
  });
}

function uniqueSorted(values) {
  return [...new Set(values.filter(Boolean))].sort();
}

function attachFixtureTaxonomy(result, fixture) {
  const taxonomy = fixture.taxonomy || classifyFixture(fixture);
  const fixtureClass = normalizeFixtureClass(result.fixture_class) !== 'unknown' ? normalizeFixtureClass(result.fixture_class) : taxonomy.fixture_class;
  const tags = result.tags?.length ? result.tags : (fixture.tags || []);
  const complexity = result.complexity ?? fixture.complexity ?? null;
  const capabilities = result.capabilities?.length ? result.capabilities : (fixture.capabilities || []);
  const riskProfile = result.risk_profile && result.risk_profile !== 'unknown' ? result.risk_profile : (fixture.risk_profile || 'unknown');
  const qualityBudgets = Object.keys(result.quality_budgets || {}).length ? result.quality_budgets : (fixture.quality_budgets || {});
  return {
    ...result,
    fixture_path: result.fixture_path || fixture.fixture_path,
    fixture_corpus: fixture.fixture_corpus || result.fixture_corpus || 'active',
    fixture_class: fixtureClass,
    tags,
    complexity,
    capabilities,
    risk_profile: riskProfile,
    quality_budgets: qualityBudgets,
    taxonomy: {
      ...taxonomy,
      ...result.taxonomy,
      fixture_class: fixtureClass,
      tags,
      complexity,
      capabilities,
      risk_profile: riskProfile,
      quality_budgets: qualityBudgets,
    },
  };
}

export function normalizeFixtureResult(input) {
  let status = input.status || 'not_run';
  if (!input.status && input.success === true) {
    status = 'passed';
  } else if (!input.status && input.success === false) {
    status = 'failed';
  }
  const liveWpParityResult = input.live_wp_parity || input.liveWpParity || null;
  return {
    fixture_id: input.fixture_id || input.fixtureId || input.id || '',
    fixture_path: input.fixture_path || input.fixturePath || input.path || '',
    fixture_class: normalizeFixtureClass(input.fixture_class || input.fixtureClass || input.taxonomy?.fixture_class) || 'unknown',
    tags: normalizeArray(input.tags ?? input.taxonomy?.tags).map((tag) => String(tag || '').trim()).filter(Boolean),
    complexity: input.complexity ?? input.taxonomy?.complexity ?? null,
    capabilities: normalizeManifestCapabilities(input.capabilities ?? input.taxonomy?.capabilities),
    risk_profile: normalizeManifestRiskProfile(input.risk_profile ?? input.riskProfile ?? input.taxonomy?.risk_profile ?? input.taxonomy?.riskProfile),
    quality_budgets: normalizeManifestQualityBudgets(input.quality_budgets ?? input.qualityBudgets ?? input.taxonomy?.quality_budgets ?? input.taxonomy?.qualityBudgets),
    taxonomy: input.taxonomy || {},
    status,
    success: status === 'passed',
    error: input.error || input.message || '',
    // Block composition is computed from the FULL input (which may carry
    // serialized `post_content`/block markup) into bounded COUNTS first; the raw
    // markup is then discarded by never retaining `input` and by bounding the
    // report blobs below. See #554 / bounds.mjs.
    block_composition: input.block_composition || input.blockComposition || collectBlockComposition(input),
    // Real `wp.blocks.validateBlock` editor-validity (total/valid/invalid blocks
    // + validation_method), distinct from the PHP round-trip. Round-trips through
    // re-normalization so editor-quality scoring can read it.
    editor_validation: input.editor_validation || input.editorValidation || null,
    // Retained report blobs can carry raw serialized markup (e.g.
    // `import_report.materialized_content.block_documents[].post_content`). Bound
    // every retained string so the per-fixture result scales with #findings, not
    // with raw content volume. Counts/metrics inside these blobs are untouched.
    ssi_validation: boundBlob(input.ssi_validation || input.ssiValidation || null),
    import_report: boundBlob(input.import_report || input.importReport || null),
    quality_metrics: boundBlob(input.quality_metrics || input.qualityMetrics || {}),
    blocks_engine_diagnostics: boundBlob(normalizeArray(input.blocks_engine_diagnostics || input.blocksEngineDiagnostics)),
    invalid_block_counts: input.invalid_block_counts || input.invalidBlockCounts || {},
    missing_assets: boundBlob(normalizeArray(input.missing_assets || input.missingAssets)),
    runtime_target_gaps: boundBlob(normalizeArray(input.runtime_target_gaps || input.runtimeTargetGaps)),
    diagnostics: boundBlob(normalizeArray(input.diagnostics || input.findings || input.messages)),
    artifact_refs: normalizeArray(input.artifact_refs || input.artifactRefs),
    artifacts: input.artifacts || {},
    editor_canvas: boundBlob(input.editor_canvas || input.editorCanvas || null),
    editor_open: boundBlob(input.editor_open || input.editorOpen || null),
    visual_parity_artifacts: input.visual_parity_artifacts || input.visualParityArtifacts || null,
    visual_diff_regions: normalizeArray(input.visual_diff_regions || input.visualDiffRegions),
    visual_diff_cause_summary: input.visual_diff_cause_summary || input.visualDiffCauseSummary || null,
    visual_diff_classification: input.visual_diff_classification || input.visualDiffClassification || null,
    matrix_evidence: boundBlob(input.matrix_evidence || input.matrixEvidence || null),
    svg_font_embedding_evidence: boundBlob(input.svg_font_embedding_evidence || input.svgFontEmbeddingEvidence || null),
    // Opt-in live-WP parity result (live-WP score + render-free proxy score +
    // delta). Only attached when the collector produced one; absent => the key is
    // omitted entirely so a default (toggle-off) result is byte-identical to today.
    ...(liveWpParityResult ? { live_wp_parity: liveWpParityResult } : {}),
  };
}

export function writeFixtureMatrixArtifacts(input = {}) {
  const startedAt = nowMs();
  const outputDirectory = requiredString(input.outputDirectory || input.output_directory, 'outputDirectory');
  const matrix = input.matrix || createFixtureMatrix(input);
  const result = input.result || normalizeFixtureMatrixResult({ ...input, matrix, execution_status: input.execution_status || input.executionStatus || 'not_requested' });
  const stageSources = shouldStageFixtureSources(input);
  const artifactBytes = {
    fixture_artifacts: 0,
    staged_source: 0,
    matrix: 0,
    result_artifacts: 0,
    total: 0,
  };

  fs.mkdirSync(outputDirectory, { recursive: true });
  for (const fixture of matrix.fixtures) {
    const fixtureDirectory = path.join(outputDirectory, fixture.id);
    const artifactFile = path.join(fixtureDirectory, 'artifact.json');
    fs.mkdirSync(fixtureDirectory, { recursive: true });
    writeJsonFile(artifactFile, buildFixtureArtifact(fixture, input));
    artifactBytes.fixture_artifacts += fileBytes(artifactFile);
    if (stageSources) {
      // Stage the raw source site alongside artifact.json so the in-sandbox
      // WordPress origin can serve it for the visual-parity `source-url`.
      const staged = stageFixtureSource(fixture, fixtureDirectory, input);
      artifactBytes.staged_source += staged.reduce((total, relativePath) => total + fileBytes(path.join(fixtureDirectory, 'source', relativePath)), 0);
    }
  }

  const matrixFile = path.join(outputDirectory, 'matrix.json');
  writeJsonFile(matrixFile, matrix);
  artifactBytes.matrix = fileBytes(matrixFile);
  writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result });
  artifactBytes.result_artifacts = [
    'static-site-fixture-matrix-result.json',
    'summary.json',
    'finding-packets.json',
    'gutenberg-incompatibility-registry.json',
    'gutenberg-incompatibility-registry.md',
    'visual-parity-evidence-report.json',
    'visual-parity-evidence-report.md',
  ].reduce((total, fileName) => total + fileBytes(path.join(outputDirectory, fileName)), 0);
  artifactBytes.total = artifactBytes.fixture_artifacts + artifactBytes.staged_source + artifactBytes.matrix + artifactBytes.result_artifacts;

  return {
    matrix,
    result,
    metadata: {
      performance: {
        artifact_writing_ms: elapsedMs(startedAt),
      },
      artifact_bytes: artifactBytes,
      source_staging: {
        status: stageSources ? 'staged' : 'skipped',
        reason: stageSources ? 'visual_evidence_enabled' : 'visual_and_live_wp_parity_disabled',
      },
    },
    artifact_refs: [
      artifactRef('matrix', path.join(outputDirectory, 'matrix.json'), 'matrix'),
      artifactRef('result', path.join(outputDirectory, 'static-site-fixture-matrix-result.json'), 'diagnostic'),
      artifactRef('summary', path.join(outputDirectory, 'summary.json'), 'summary'),
      artifactRef('finding-packets', path.join(outputDirectory, 'finding-packets.json'), 'diagnostic'),
      artifactRef('visual-diff-classification', path.join(outputDirectory, 'visual-diff-classification.json'), 'diagnostic'),
      artifactRef('visual-parity-evidence-report', path.join(outputDirectory, 'visual-parity-evidence-report.json'), 'diagnostic'),
      artifactRef('visual-parity-evidence-report-markdown', path.join(outputDirectory, 'visual-parity-evidence-report.md'), 'summary'),
      artifactRef('gutenberg-incompatibility-registry', path.join(outputDirectory, 'gutenberg-incompatibility-registry.json'), 'diagnostic'),
      artifactRef('gutenberg-incompatibility-registry-report', path.join(outputDirectory, 'gutenberg-incompatibility-registry.md'), 'summary'),
    ],
  };
}

export function writeFixtureMatrixResultArtifacts(input = {}) {
  const outputDirectory = requiredString(input.outputDirectory || input.output_directory, 'outputDirectory');
  const matrix = input.matrix || createFixtureMatrix(input);
  const result = input.result || normalizeFixtureMatrixResult({ ...input, matrix });
  writeJsonFile(path.join(outputDirectory, 'static-site-fixture-matrix-result.json'), result);
  writeJsonFile(path.join(outputDirectory, 'summary.json'), result.summary);
  writeJsonFile(path.join(outputDirectory, 'finding-packets.json'), result.findings);
  writeJsonFile(path.join(outputDirectory, 'visual-diff-classification.json'), visualDiffClassificationArtifact(result));
  const visualEvidenceReport = buildVisualParityEvidenceReport({ outputDirectory, matrix, result });
  writeJsonFile(path.join(outputDirectory, 'visual-parity-evidence-report.json'), visualEvidenceReport);
  fs.writeFileSync(path.join(outputDirectory, 'visual-parity-evidence-report.md'), renderVisualParityEvidenceReportMarkdown(visualEvidenceReport));
  writeJsonFile(path.join(outputDirectory, 'gutenberg-incompatibility-registry.json'), result.gutenberg_incompatibility_registry || buildGutenbergIncompatibilityRegistry(result));
  fs.writeFileSync(path.join(outputDirectory, 'gutenberg-incompatibility-registry.md'), renderGutenbergIncompatibilityRegistryMarkdown(result.gutenberg_incompatibility_registry || buildGutenbergIncompatibilityRegistry(result)));
  return result;
}

function visualDiffClassificationArtifact(result) {
  const fixtures = normalizeArray(result.fixtures).map((fixture) => {
    const artifacts = objectValue(fixture.visual_parity_artifacts || fixture.visualParityArtifacts);
    return compactObject({
      fixture_id: fixture.fixture_id,
      visual_diff_regions: normalizeArray(fixture.visual_diff_regions || artifacts.visual_diff_regions),
      visual_diff_cause_summary: fixture.visual_diff_cause_summary || artifacts.visual_diff_cause_summary || null,
      artifact_refs: normalizeArray(fixture.artifact_refs),
    });
  }).filter((fixture) => fixture.visual_diff_regions?.length || Object.keys(objectValue(fixture.visual_diff_cause_summary)).length > 0);
  return {
    schema: 'static-site-importer/visual-diff-classification-run/v1',
    matrix_id: result.matrix_id,
    summary: {
      visual_diff_cause_summary: aggregateVisualDiffCauseSummary(result.fixtures),
      visual_diff_top_causes: visualDiffTopCauses(result.fixtures),
    },
    fixtures,
  };
}

function aggregateVisualDiffCauseSummary(fixtures) {
  const summary = {};
  for (const fixture of normalizeArray(fixtures)) {
    const artifacts = objectValue(fixture.visual_parity_artifacts || fixture.visualParityArtifacts);
    const causeSummary = objectValue(fixture.visual_diff_cause_summary || fixture.visualDiffCauseSummary || artifacts.visual_diff_cause_summary || artifacts.visualDiffCauseSummary);
    for (const [cause, pixels] of Object.entries(causeSummary)) {
      const value = Number(pixels);
      if (Number.isFinite(value)) {
        summary[cause] = (summary[cause] || 0) + value;
      }
    }
  }
  return Object.fromEntries(Object.entries(summary).sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])));
}

function visualDiffTopCauses(fixtures, limit = 5) {
  return Object.entries(aggregateVisualDiffCauseSummary(fixtures))
    .slice(0, limit)
    .map(([cause, pixel_count]) => ({ cause, pixel_count }));
}

function shouldStageFixtureSources(input = {}) {
  return input.visualParity !== false
    && input.visual_parity !== false
    || input.liveWpParity === true
    || input.live_wp_parity === true;
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

function topPatternFamilies(findings, limit = 10) {
  const families = new Map();
  for (const finding of findings) {
    const key = finding.pattern_family || patternFamily(finding);
    const row = families.get(key) || {
      key,
      count: 0,
      repair_bucket: finding.repair_bucket || finding.group_key || '',
      kind: finding.kind || '',
      candidate_repo: finding.candidate_repo || '',
      fixture_ids: [],
      selectors: [],
      exemplars: [],
    };
    row.count += 1;
    pushUnique(row.fixture_ids, finding.fixture_id, 5);
    pushUnique(row.selectors, finding.selector, 5);
    if (row.exemplars.length < 3) {
      row.exemplars.push(fixtureExemplar(finding));
    }
    families.set(key, row);
  }
  return [...families.values()]
    .sort((left, right) => right.count - left.count || left.key.localeCompare(right.key))
    .slice(0, limit);
}

function fixtureExemplars(findings, limit = 10) {
  const exemplars = [];
  const seen = new Set();
  for (const finding of findings) {
    const exemplar = fixtureExemplar(finding);
    const key = [exemplar.pattern_family, exemplar.fixture_id, exemplar.selector, exemplar.source_path].join('\u0000');
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    exemplars.push(exemplar);
    if (exemplars.length >= limit) {
      break;
    }
  }
  return exemplars;
}

function fixtureExemplar(finding) {
  return compactObject({
    fixture_id: finding.fixture_id,
    pattern_family: finding.pattern_family || patternFamily(finding),
    repair_bucket: finding.repair_bucket || finding.group_key,
    kind: finding.kind,
    candidate_repo: finding.candidate_repo,
    source_path: finding.source_path || finding.path,
    selector: finding.selector,
    selector_family: finding.selector_family || selectorFamily(finding.selector),
    reason: finding.reason,
    source_snippet: finding.source_snippet,
    observed_block_name: finding.observed_block_name,
    observed_output: finding.observed_output,
    batch_id: finding.batch_id,
    recipe_phase: finding.recipe_phase,
    recipe_step_index: finding.recipe_step_index,
    command: finding.command,
    command_argv: finding.command_argv,
    exit_status: finding.exit_status,
    stdout_tail: finding.stdout_tail,
    stderr_tail: finding.stderr_tail,
    recipe_file: finding.recipe_file,
    output_file: finding.output_file,
    artifacts_directory: finding.artifacts_directory,
    replay_command: finding.replay_command,
    artifact_refs: normalizeArray(finding.artifact_refs).length > 0 ? normalizeArray(finding.artifact_refs) : undefined,
    visual_explanation_summary: finding.visual_explanation_summary,
  });
}

function diagnosticBlindSpots(findings) {
  const spots = [];
  const genericFindings = findings.filter((finding) => isGenericFinding(finding));
  const missingSourceContext = findings.filter((finding) => !finding.selector && !finding.source_snippet && !finding.observed_output);
  const missingRuntimeContext = findings.filter((finding) => finding.loss_class === 'runtime_execution_failed' && !finding.command && !finding.batch_id && !finding.stderr_tail && !finding.stdout_tail && normalizeArray(finding.artifact_refs).length === 0);
  if (genericFindings.length > 0) {
    spots.push(blindSpot('generic_finding_family', genericFindings, 'Findings need a specific type, repair bucket, or reason code before fanout.'));
  }
  if (missingSourceContext.length > 0) {
    spots.push(blindSpot('missing_source_context', missingSourceContext, 'Findings need selector, source snippet, or observed block output for direct transformer repair.'));
  }
  if (missingRuntimeContext.length > 0) {
    spots.push(blindSpot('missing_runtime_failure_context', missingRuntimeContext, 'Runtime failures need batch id, command, bounded stdout/stderr tails, artifact refs, or replay command for direct reproduction.'));
  }
  return spots;
}

function blindSpot(kind, findings, recommendation) {
  return {
    kind,
    count: findings.length,
    recommendation,
    exemplars: fixtureExemplars(findings, 5),
  };
}

function isGenericFinding(finding) {
  return ['static_site_fixture_diagnostic', 'import_diagnostic', 'diagnostic'].includes(finding.kind)
    || ['static_site_import_quality'].includes(finding.group_key)
    || !finding.reason;
}

function groupFindings(findings) {
  return findings.reduce((groups, finding) => {
    const key = finding.group_key || 'static_site_import_quality';
    groups[key] = groups[key] || [];
    groups[key].push(finding);
    return groups;
  }, {});
}

function buildFanoutGroups(findings) {
  const groups = new Map();
  for (const finding of findings) {
    const acceptance = finding.loss_acceptance === 'acceptable' ? 'acceptable' : 'unacceptable';
    const pattern = finding.pattern_family || patternFamily(finding);
    const candidateRepo = finding.candidate_repo || 'unknown';
    const key = `${acceptance}:${candidateRepo}:${pattern}`;
    const row = groups.get(key) || {
      group_key: key,
      acceptance,
      candidate_repo: candidateRepo,
      pattern_family: pattern,
      count: 0,
      top_pattern_families: [],
      fixture_exemplars: [],
      findings: [],
    };
    row.count += 1;
    row.findings.push(finding);
    groups.set(key, row);
  }

  return [...groups.values()]
    .map((group) => ({
      ...group,
      top_pattern_families: topPatternFamilies(group.findings, 5),
      fixture_exemplars: fixtureExemplars(group.findings, 5),
    }))
    .sort(fanoutGroupSort);
}

function fanoutGroupSort(left, right) {
  const acceptanceDelta = acceptanceRank(left.acceptance) - acceptanceRank(right.acceptance);
  if (acceptanceDelta !== 0) {
    return acceptanceDelta;
  }
  return right.count - left.count
    || genericBucketRank(left) - genericBucketRank(right)
    || left.candidate_repo.localeCompare(right.candidate_repo)
    || left.pattern_family.localeCompare(right.pattern_family);
}

function acceptanceRank(value) {
  return value === 'unacceptable' ? 0 : 1;
}

function genericBucketRank(group) {
  return group.pattern_family === 'static_site_import_quality:static_site_fixture_diagnostic:(none)' ? 1 : 0;
}

function candidateRepoRollups(findings, limit = 10) {
  const repos = new Map();
  for (const finding of findings) {
    const key = finding.candidate_repo || 'unknown';
    const row = repos.get(key) || {
      candidate_repo: key,
      count: 0,
      fixture_ids: [],
      loss_classes: {},
      repair_buckets: {},
      top_pattern_families: [],
      fixture_exemplars: [],
      findings: [],
    };
    row.count += 1;
    pushUnique(row.fixture_ids, finding.fixture_id, 10);
    row.loss_classes[finding.loss_class || 'unsupported_loss'] = (row.loss_classes[finding.loss_class || 'unsupported_loss'] || 0) + 1;
    row.repair_buckets[finding.repair_bucket || finding.group_key || 'static_site_import_quality'] = (row.repair_buckets[finding.repair_bucket || finding.group_key || 'static_site_import_quality'] || 0) + 1;
    row.findings.push(finding);
    row.top_pattern_families = topPatternFamilies(row.findings, 5);
    row.fixture_exemplars = fixtureExemplars(row.findings, 5);
    repos.set(key, row);
  }

  return [...repos.values()]
    .map(({ findings: _findings, ...row }) => row)
    .sort((left, right) => right.count - left.count || left.candidate_repo.localeCompare(right.candidate_repo))
    .slice(0, limit);
}

function capabilityRollups(fixtureResults, findings) {
  const byFixture = findingsByFixtureId(findings);
  const byCapability = {};
  for (const result of fixtureResults) {
    for (const capability of normalizeManifestCapabilities(result.capabilities)) {
      const row = byCapability[capability] || taxonomyRollup(capability);
      addFixtureToTaxonomyRollup(row, result, byFixture.get(result.fixture_id) || []);
      byCapability[capability] = row;
    }
  }
  return sortTaxonomyRollups(byCapability);
}

function riskProfileRollups(fixtureResults, findings) {
  const byFixture = findingsByFixtureId(findings);
  const byRiskProfile = {};
  for (const result of fixtureResults) {
    const key = normalizeManifestRiskProfile(result.risk_profile);
    const row = byRiskProfile[key] || taxonomyRollup(key);
    addFixtureToTaxonomyRollup(row, result, byFixture.get(result.fixture_id) || []);
    byRiskProfile[key] = row;
  }
  return sortTaxonomyRollups(byRiskProfile);
}

function findingsByFixtureId(findings) {
  const byFixture = new Map();
  for (const finding of findings) {
    const fixtureId = finding.fixture_id || '';
    byFixture.set(fixtureId, [...(byFixture.get(fixtureId) || []), finding]);
  }
  return byFixture;
}

function taxonomyRollup(key) {
  return {
    key,
    fixture_count: 0,
    passed: 0,
    failed: 0,
    not_run: 0,
    finding_count: 0,
    acceptable_finding_count: 0,
    unacceptable_finding_count: 0,
    loss_classes: {},
    repair_buckets: {},
  };
}

function addFixtureToTaxonomyRollup(row, result, findings) {
  row.fixture_count += 1;
  row[result.status] = (row[result.status] || 0) + 1;
  if (result.raw_status === 'not_run' && result.status !== 'not_run') {
    row.not_run += 1;
  }
  for (const finding of findings) {
    const bucket = finding.repair_bucket || finding.group_key || 'static_site_import_quality';
    row.finding_count += 1;
    row.loss_classes[finding.loss_class || 'unsupported_loss'] = (row.loss_classes[finding.loss_class || 'unsupported_loss'] || 0) + 1;
    if (finding.loss_acceptance === 'acceptable') {
      row.acceptable_finding_count += 1;
    } else {
      row.unacceptable_finding_count += 1;
    }
    row.repair_buckets[bucket] = (row.repair_buckets[bucket] || 0) + 1;
  }
}

function sortTaxonomyRollups(rollups) {
  return Object.fromEntries(Object.entries(rollups)
    .sort(([leftKey, left], [rightKey, right]) => right.fixture_count - left.fixture_count || leftKey.localeCompare(rightKey)));
}

function fixtureClassRollups(fixtureResults, findings) {
  const byClass = {};
  for (const result of fixtureResults) {
    const key = normalizeFixtureClass(result.fixture_class) || 'unknown';
    const row = byClass[key] || classRollup(key);
    row.fixture_count += 1;
    row[result.status] = (row[result.status] || 0) + 1;
    if (result.raw_status === 'not_run' && result.status !== 'not_run') {
      row.not_run += 1;
    }
    accumulateEditorQuality(row.editor_quality, result.editor_quality);
    byClass[key] = row;
  }

  for (const finding of findings) {
    const key = normalizeFixtureClass(finding.fixture_class) || 'unknown';
    const row = byClass[key] || classRollup(key);
    const bucket = finding.repair_bucket || finding.group_key || 'static_site_import_quality';
    row.finding_count += 1;
    row.loss_classes[finding.loss_class || 'unsupported_loss'] = (row.loss_classes[finding.loss_class || 'unsupported_loss'] || 0) + 1;
    if (finding.loss_acceptance === 'acceptable') {
      row.acceptable_finding_count += 1;
    } else {
      row.unacceptable_finding_count += 1;
    }
    row.repair_buckets[bucket] = (row.repair_buckets[bucket] || 0) + 1;
    row.candidate_repos[finding.candidate_repo || 'unknown'] = (row.candidate_repos[finding.candidate_repo || 'unknown'] || 0) + 1;
    byClass[key] = row;
  }

  return Object.fromEntries(Object.entries(byClass)
    .map(([key, row]) => [key, { ...row, editor_quality: finalizeEditorQuality(row.editor_quality) }])
    .sort(([left], [right]) => fixtureClassRank(left) - fixtureClassRank(right)));
}

function classRollup(key) {
  return {
    fixture_class: key,
    fixture_count: 0,
    passed: 0,
    failed: 0,
    not_run: 0,
    finding_count: 0,
    acceptable_finding_count: 0,
    unacceptable_finding_count: 0,
    loss_classes: {},
    repair_buckets: {},
    candidate_repos: {},
    editor_quality: { scored_fixture_count: 0, block_total: 0, native_block_count: 0, core_html_block_count: 0, editor_invalid_count: 0, editor_validated_fixture_count: 0, editor_validated_block_total: 0, editor_valid_block_count: 0, invalid_block_count: 0 },
  };
}

function qualityBudgetSummaries(classRollups) {
  return Object.fromEntries(Object.entries(classRollups).map(([key, row]) => {
    const dominantRepairBuckets = Object.entries(row.repair_buckets)
      .map(([bucket, count]) => ({ bucket, count }))
      .sort((left, right) => right.count - left.count || left.bucket.localeCompare(right.bucket));
    return [key, {
      fixture_class: key,
      fixture_count: row.fixture_count,
      passed: row.passed,
      failed: row.failed,
      not_run: row.not_run,
      finding_count: row.finding_count,
      acceptable_finding_count: row.acceptable_finding_count,
      unacceptable_finding_count: row.unacceptable_finding_count,
      loss_classes: row.loss_classes,
      preserved_runtime_island_count: row.loss_classes.preserved_runtime_island || 0,
      findings_per_fixture: row.fixture_count ? Number((row.finding_count / row.fixture_count).toFixed(2)) : 0,
      dominant_repair_buckets: dominantRepairBuckets.slice(0, 5),
      editor_quality: row.editor_quality,
    }];
  }));
}
