// Run-result intake for the Static Site Importer fixture matrix: reads WP
// Codebox runtime payloads + per-fixture artifact files back out, normalizes
// them into fixture results, and threads the per-concern collectors together.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import fs from 'node:fs';
import path from 'node:path';

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

  return normalizeFixtureMatrixResult({ matrix, results });
}

function normalizeCollectedFixtureResult({ fixture, payloads, fixtureArtifactsDirectory, codeboxError, visualParity, liveWpParity }) {
  const merged = mergeObjects(payloads);
  const diagnostics = collectFixtureDiagnostics(merged, { visualParity });
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
    artifact_refs: collectFixtureArtifactRefs(merged, fixtureArtifactsDirectory),
    artifacts: merged.artifacts || {},
    visual_parity_artifacts: collectVisualParityArtifacts(merged),
    live_wp_parity: liveWpParityResult,
    raw: { payloads },
  });
}

function collectFixtureDiagnostics(payload, options = {}) {
  const diagnostics = [
    ...normalizeArray(payload.diagnostics),
    ...normalizeArray(payload.fixture_diagnostics?.diagnostics || payload.fixtureDiagnostics?.diagnostics),
    ...normalizeArray(payload.findings),
    ...collectFindingPacketDiagnostics(payload),
    ...normalizeArray(payload.messages),
    ...normalizeArray(payload.errors),
    ...normalizeArray(payload.warnings),
    ...normalizeArray(payload.upstream_gaps || payload.upstreamGaps).map((gap) => ({ kind: 'upstream_gap', ...objectValue(gap), message: diagnosticMessage(gap) || gap.missing || 'Upstream capability gap detected.' })),
    ...collectBlocksEngineDiagnostics(payload),
    ...collectRuntimeTargetGaps(payload).map((gap) => ({ kind: 'runtime_target_gap', ...objectValue(gap), message: diagnosticMessage(gap) || 'Runtime target gap detected.' })),
    ...collectMissingAssets(payload).map((asset) => ({ kind: missingAssetKind(asset), ...objectValue(asset), message: diagnosticMessage(asset) || 'Missing imported asset.' })),
    ...collectEditorValidationDiagnostics(payload),
    ...collectVisualParityDiagnostics(payload, options.visualParity),
  ];
  const invalidBlockCount = Object.values(collectInvalidBlockCounts(payload)).reduce((sum, value) => sum + numberValue(value), 0);
  if (invalidBlockCount > 0) {
    diagnostics.push({ kind: 'invalid_block_content', message: `${invalidBlockCount} invalid block${invalidBlockCount === 1 ? '' : 's'} reported by SSI validation.` });
  }
  return dedupeDiagnostics(propagateAcceptedRuntimePreservation(diagnostics));
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
  ];
}

function collectFixtureArtifactRefs(payload, fixtureArtifactsDirectory) {
  const refs = [...normalizeArray(payload.artifact_refs || payload.artifactRefs), ...normalizeArray(payload.artifacts?.refs)];
  for (const [key, value] of Object.entries(payload.artifacts || {})) {
    if (value && typeof value === 'object' && !Array.isArray(value) && (value.path || value.file || value.href)) {
      refs.push({ artifact_id: key, kind: value.kind || key, ...value });
    } else if (typeof value === 'string') {
      refs.push({ artifact_id: key, kind: key, path: value });
    }
  }
  for (const fileName of ['artifact.json', 'validation-result.json', 'import-report.json']) {
    const filePath = path.join(fixtureArtifactsDirectory, fileName);
    if (fs.existsSync(filePath)) {
      refs.push(artifactRef(fileName.replace(/\.json$/, ''), filePath, fileName === 'artifact.json' ? 'input' : 'diagnostic'));
    }
  }
  return refs;
}

function collectRuntimePayloads(value) {
  const payloads = [];
  visitRuntimePayloads(value, '', payloads, new Set());
  payloads.push(...collectRecipeBrowserEvidencePayloads(value));
  return payloads;
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
    .map((evidence) => ({ fixture_id: fixtureByStep.get(`${evidence.phase}:${evidence.index}`) || fixtureIdentity(evidence), ...evidence }))
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
  return ['status', 'success', 'ok', 'passed', 'error', 'diagnostics', 'findings', 'summary', 'artifacts', 'upstream_gaps', 'runtime_target_gaps', 'blocks_engine', 'import_report']
    .some((key) => Object.hasOwn(value, key));
}

function readFixturePayloadFiles(directory) {
  return ['validation-result.json', 'result.json', 'import-report.json', 'quality.json', 'blocks-engine-diagnostics.json', 'editor-validation.json', 'editor-validate-blocks.json', 'editor-canvas-summary.json', 'visual-compare.json', 'visual-diff.json', 'visual-parity.json', 'visual-explanation.json']
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
