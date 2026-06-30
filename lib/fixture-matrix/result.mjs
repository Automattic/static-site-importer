// Result normalization, quality gating, summary/aggregate rollups, and artifact
// writing for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import fs from 'node:fs';
import path from 'node:path';

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
} from './fixtures.mjs';
import {
  findingsForFixtureResult,
  dedupeFindings,
  isActionableFinding,
  selectorFamily,
  patternFamily,
} from './findings.mjs';
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
      groups: Object.fromEntries(Object.entries(grouped).map(([key, items]) => [key, items.length])),
      top_pattern_families: topPatternFamilies(actionableFindings),
      top_acceptable_pattern_families: topPatternFamilies(acceptableActionableFindings),
      top_unacceptable_pattern_families: topPatternFamilies(unacceptableActionableFindings),
      unacceptable_candidate_repos: candidateRepoRollups(unacceptableActionableFindings),
      fixture_exemplars: fixtureExemplars(actionableFindings),
      diagnostic_blind_spots: diagnosticBlindSpots(actionableFindings),
      fixture_classes: Object.fromEntries(Object.entries(classRollups).map(([key, row]) => [key, row.fixture_count])),
      classes: classRollups,
      quality_budgets: qualityBudgetSummaries(classRollups),
      editor_quality: aggregateEditorQuality([...editorQualityByFixture.values()], nativeRateGate),
    },
    fixtures: gatedFixtureResults,
    findings,
    fanout_groups: fanoutGroups.map((group, index) => ({ ...group, index })),
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
    },
  };
}

function attachFixtureTaxonomy(result, fixture) {
  const taxonomy = fixture.taxonomy || classifyFixture(fixture);
  const fixtureClass = normalizeFixtureClass(result.fixture_class) !== 'unknown' ? normalizeFixtureClass(result.fixture_class) : taxonomy.fixture_class;
  const tags = result.tags?.length ? result.tags : (fixture.tags || []);
  const complexity = result.complexity ?? fixture.complexity ?? null;
  return {
    ...result,
    fixture_path: result.fixture_path || fixture.fixture_path,
    fixture_class: fixtureClass,
    tags,
    complexity,
    taxonomy: {
      ...taxonomy,
      ...result.taxonomy,
      fixture_class: fixtureClass,
      tags,
      complexity,
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
    visual_parity_artifacts: input.visual_parity_artifacts || input.visualParityArtifacts || null,
    // Opt-in live-WP parity result (live-WP score + render-free proxy score +
    // delta). Only attached when the collector produced one; absent => the key is
    // omitted entirely so a default (toggle-off) result is byte-identical to today.
    ...(liveWpParityResult ? { live_wp_parity: liveWpParityResult } : {}),
  };
}

export function writeFixtureMatrixArtifacts(input = {}) {
  const outputDirectory = requiredString(input.outputDirectory || input.output_directory, 'outputDirectory');
  const matrix = input.matrix || createFixtureMatrix(input);
  const result = input.result || normalizeFixtureMatrixResult({ ...input, matrix, execution_status: input.execution_status || input.executionStatus || 'not_requested' });

  fs.mkdirSync(outputDirectory, { recursive: true });
  for (const fixture of matrix.fixtures) {
    const fixtureDirectory = path.join(outputDirectory, fixture.id);
    fs.mkdirSync(fixtureDirectory, { recursive: true });
    writeJsonFile(path.join(fixtureDirectory, 'artifact.json'), buildFixtureArtifact(fixture, input));
    // Stage the raw source site alongside artifact.json so the in-sandbox
    // WordPress origin can serve it for the visual-parity `source-url`.
    stageFixtureSource(fixture, fixtureDirectory, input);
  }

  writeJsonFile(path.join(outputDirectory, 'matrix.json'), matrix);
  writeFixtureMatrixResultArtifacts({ outputDirectory, matrix, result });

  return {
    matrix,
    result,
    artifact_refs: [
      artifactRef('matrix', path.join(outputDirectory, 'matrix.json'), 'matrix'),
      artifactRef('result', path.join(outputDirectory, 'static-site-fixture-matrix-result.json'), 'diagnostic'),
      artifactRef('summary', path.join(outputDirectory, 'summary.json'), 'summary'),
      artifactRef('finding-packets', path.join(outputDirectory, 'finding-packets.json'), 'diagnostic'),
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
  return result;
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
  });
}

function diagnosticBlindSpots(findings) {
  const spots = [];
  const genericFindings = findings.filter((finding) => isGenericFinding(finding));
  const missingSourceContext = findings.filter((finding) => !finding.selector && !finding.source_snippet && !finding.observed_output);
  if (genericFindings.length > 0) {
    spots.push(blindSpot('generic_finding_family', genericFindings, 'Findings need a specific type, repair bucket, or reason code before fanout.'));
  }
  if (missingSourceContext.length > 0) {
    spots.push(blindSpot('missing_source_context', missingSourceContext, 'Findings need selector, source snippet, or observed block output for direct transformer repair.'));
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
