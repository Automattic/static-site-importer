// Visual-parity collector (#538): turns `wordpress.visual-compare` evidence into
// `visual_parity_mismatch` diagnostics + the SSI visual-parity-artifacts slot,
// and resolves the opt-in pixel gate for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import {
  VISUAL_PARITY_MISMATCH_KIND,
  DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD,
} from '../shared/constants.mjs';
import {
  normalizeArray,
  objectValue,
  firstNumber,
  firstString,
  finiteNumber,
  compactObject,
  clampRatio,
  isTruthySignal,
  artifactRef,
} from '../shared/utils.mjs';
import { boundBlob, truncateString } from '../shared/bounds.mjs';

const VISUAL_EXPLANATION_SUMMARY_LIMIT = 5;

// Turn `wordpress.visual-compare` evidence into `visual_parity_mismatch`
// diagnostics, gated on the TRUSTWORTHY (dimension-fair) ratio.
//
// The legacy gate compared `mismatch_pixels/total_pixels` over the union canvas
// (max width × max height of source vs candidate). When the two renders differ in
// size — the common case, since the static source frequently lays out wider/taller
// than the imported WordPress page — that raw ratio is dominated by the canvas-size
// band (one side real content, the other transparent fill) and tells you almost
// nothing about real visual fidelity. It also hard-failed on ANY dimension mismatch.
//
// The trustworthy gate instead compares the FAIR ratio: pixel mismatch over the
// common overlap region only (`overlap_mismatch_pixels/overlap_pixels`, emitted by
// wp-codebox). The dimension delta is reported as a SEPARATE signal rather than
// smeared into the gate. A dimension mismatch only forces a finding when no overlap
// signal is available (a degenerate/empty capture), where the fair ratio cannot be
// computed. When the runtime predates overlap metrics, the fair ratio falls back to
// the raw ratio so older evidence still gates as before. Matches at or under the
// threshold emit nothing.
export function collectVisualParityDiagnostics(payload, options = {}) {
  const { threshold, gate } = normalizeVisualParityGateOptions(options);
  const diagnostics = [];
  for (const comparison of collectVisualParityComparisons(payload)) {
    // Dimension mismatch is only a hard gate when we cannot measure a fair ratio
    // (no overlap region — e.g. a zero-area/failed capture).
    const dimensionForcesFinding = comparison.dimension_mismatch && !comparison.has_overlap_signal;
    if (comparison.mismatch_ratio <= threshold && !dimensionForcesFinding) {
      continue;
    }
    const percent = (comparison.mismatch_ratio * 100).toFixed(2);
    const rawPercent = (comparison.raw_mismatch_ratio * 100).toFixed(2);
    const thresholdPercent = (threshold * 100).toFixed(2);
    const fairPixels = comparison.has_overlap_signal ? comparison.overlap_mismatch_pixels : comparison.mismatch_pixels;
    const fairTotal = comparison.has_overlap_signal ? comparison.overlap_pixels : comparison.total_pixels;
    diagnostics.push({
      kind: VISUAL_PARITY_MISMATCH_KIND,
      ...(gate ? { gate: true, visual_parity_gate: true } : {}),
      source_path: comparison.source_path || '',
      observed_output: `${percent}% pixels differ in overlap (${fairPixels}/${fairTotal})`,
      mismatch_pixels: fairPixels,
      total_pixels: fairTotal,
      mismatch_ratio: comparison.mismatch_ratio,
      raw_mismatch_pixels: comparison.mismatch_pixels,
      raw_total_pixels: comparison.total_pixels,
      raw_mismatch_ratio: comparison.raw_mismatch_ratio,
      overlap_mismatch_pixels: comparison.overlap_mismatch_pixels,
      overlap_pixels: comparison.overlap_pixels,
      threshold,
      dimension_mismatch: comparison.dimension_mismatch,
      dimension_delta_pixels: comparison.dimension_delta_pixels,
      artifact_refs: visualParityArtifactRefs(comparison),
      ...visualExplanationDiagnosticFields(comparison.visual_explanation),
      message: dimensionForcesFinding
        ? `Visual parity dimension mismatch between source and imported output with no measurable overlap region (raw ${comparison.mismatch_pixels}/${comparison.total_pixels} pixels, ${rawPercent}%).`
        : `Pixel visual parity mismatch: ${fairPixels}/${fairTotal} overlap pixels (${percent}%) exceed the ${thresholdPercent}% threshold (raw full-page ${rawPercent}%, dimension delta ${comparison.dimension_delta_pixels} px).`,
    });
  }
  return diagnostics;
}

export function normalizeVisualParityGateOptions(options = {}) {
  const source = objectValue(options);
  return {
    threshold: clampRatio(finiteNumber(source.threshold ?? source.pixelThreshold ?? source.pixel_threshold ?? source.visualParityPixelThreshold ?? source.visual_parity_pixel_threshold, DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD)),
    gate: isTruthySignal(source.gate ?? source.visualParityGate ?? source.visual_parity_gate),
  };
}

// Collect candidate visual-compare records from either the normalized
// `homeboy/VisualParityArtifact/v1` artifact (summary.*), the raw
// `wp-codebox/visual-compare/v1` diff (comparison.*), or loosely-shaped payloads.
function collectVisualParityComparisons(payload) {
  const candidates = [
    ...normalizeArray(payload.visual_parity || payload.visualParity),
    ...normalizeArray(payload.visual_parity_artifacts || payload.visualParityArtifacts),
    ...normalizeArray(payload.visual_compare || payload.visualCompare),
    ...normalizeArray(payload.visual_diff || payload.visualDiff),
    ...normalizeArray(payload.visual_parity?.comparisons || payload.visualParity?.comparisons),
    ...normalizeArray(payload.visual_explanation?.comparisons || payload.visualExplanation?.comparisons),
  ];
  if (isVisualParityPayload(payload)) {
    candidates.push(payload);
  }
  return candidates.map(normalizeVisualParityComparison).filter(Boolean);
}

function isVisualParityPayload(payload) {
  const value = objectValue(payload);
  if (typeof value.schema === 'string' && /visual.?compare|visualparityartifact/i.test(value.schema)) {
    return true;
  }
  return Boolean(value.comparison && typeof value.comparison === 'object')
    || (objectValue(value.summary).mismatch_pixels !== undefined)
    || (objectValue(value.summary).total_pixels !== undefined)
    || Boolean(objectValue(objectValue(value.summary).visualCompare || objectValue(value.summary).visual_compare).mismatchPixels !== undefined)
    || Boolean(normalizeVisualExplanation(value));
}

function normalizeVisualParityComparison(value) {
  const obj = objectValue(value);
  const summary = objectValue(obj.summary);
  const visualCompare = objectValue(summary.visualCompare || summary.visual_compare);
  const comparison = objectValue(obj.comparison);
  const mismatchPixels = firstNumber([summary.mismatch_pixels, summary.mismatchPixels, visualCompare.mismatchPixels, visualCompare.mismatch_pixels, comparison.mismatchPixels, comparison.mismatch_pixels, obj.mismatch_pixels, obj.mismatchPixels]);
  const totalPixels = firstNumber([summary.total_pixels, summary.totalPixels, visualCompare.totalPixels, visualCompare.total_pixels, comparison.totalPixels, comparison.total_pixels, obj.total_pixels, obj.totalPixels]);
  const explicitRatio = firstNumber([summary.mismatch_ratio, summary.mismatchRatio, visualCompare.mismatchRatio, visualCompare.mismatch_ratio, comparison.mismatchRatio, comparison.mismatch_ratio, obj.mismatch_ratio, obj.mismatchRatio]);
  // Dimension-fair (overlap-region) metrics emitted by wp-codebox's trustworthy
  // visual-compare. When present these drive the gate; otherwise the fair ratio
  // falls back to the raw union-canvas ratio for backward compatibility.
  const overlapMismatchPixels = firstNumber([summary.overlap_mismatch_pixels, summary.overlapMismatchPixels, visualCompare.overlapMismatchPixels, visualCompare.overlap_mismatch_pixels, comparison.overlapMismatchPixels, comparison.overlap_mismatch_pixels, obj.overlap_mismatch_pixels, obj.overlapMismatchPixels]);
  const overlapPixels = firstNumber([summary.overlap_pixels, summary.overlapPixels, visualCompare.overlapPixels, visualCompare.overlap_pixels, comparison.overlapPixels, comparison.overlap_pixels, obj.overlap_pixels, obj.overlapPixels]);
  const overlapRatio = firstNumber([summary.overlap_mismatch_ratio, summary.overlapMismatchRatio, visualCompare.overlapMismatchRatio, visualCompare.overlap_mismatch_ratio, comparison.overlapMismatchRatio, comparison.overlap_mismatch_ratio, obj.overlap_mismatch_ratio, obj.overlapMismatchRatio]);
  const dimensionDeltaPixels = firstNumber([summary.dimension_delta_pixels, summary.dimensionDeltaPixels, visualCompare.dimensionDeltaPixels, visualCompare.dimension_delta_pixels, comparison.dimensionDeltaPixels, comparison.dimension_delta_pixels, obj.dimension_delta_pixels, obj.dimensionDeltaPixels]);
  const dimensionMismatch = Boolean(summary.dimension_mismatch ?? summary.dimensionMismatch ?? visualCompare.dimensionMismatch ?? visualCompare.dimension_mismatch ?? comparison.dimensionMismatch ?? comparison.dimension_mismatch ?? obj.dimension_mismatch);
  const hasMetrics = [mismatchPixels, totalPixels, explicitRatio, overlapRatio, overlapMismatchPixels].some((metric) => Number.isFinite(metric));
  if (!hasMetrics && !dimensionMismatch) {
    return null;
  }
  const safeMismatch = Number.isFinite(mismatchPixels) ? mismatchPixels : 0;
  const safeTotal = Number.isFinite(totalPixels) ? totalPixels : 0;
  const rawRatio = safeTotal > 0 ? safeMismatch / safeTotal : (Number.isFinite(explicitRatio) ? explicitRatio : 0);
  // The fair ratio is the overlap mismatch over the overlap area. Prefer explicit
  // counts, then an explicit overlap ratio, then degrade to the raw ratio.
  const safeOverlapPixels = Number.isFinite(overlapPixels) ? overlapPixels : 0;
  const safeOverlapMismatch = Number.isFinite(overlapMismatchPixels) ? overlapMismatchPixels : 0;
  const hasOverlapSignal = safeOverlapPixels > 0 || Number.isFinite(overlapRatio);
  const fairRatio = safeOverlapPixels > 0
    ? safeOverlapMismatch / safeOverlapPixels
    : (Number.isFinite(overlapRatio) ? overlapRatio : rawRatio);
  const safeDimensionDelta = Number.isFinite(dimensionDeltaPixels)
    ? dimensionDeltaPixels
    : (safeTotal > 0 && safeOverlapPixels > 0 ? safeTotal - safeOverlapPixels : 0);
  const files = objectValue(obj.files);
  const artifacts = objectValue(obj.artifacts);
  const sourceObject = objectValue(obj.source);
  const visualExplanation = normalizeVisualExplanation(obj);
  return {
    mismatch_pixels: safeMismatch,
    total_pixels: safeTotal,
    // `mismatch_ratio` is the GATING signal: the dimension-fair ratio.
    mismatch_ratio: fairRatio,
    raw_mismatch_ratio: rawRatio,
    overlap_mismatch_pixels: safeOverlapMismatch,
    overlap_pixels: safeOverlapPixels,
    has_overlap_signal: hasOverlapSignal,
    dimension_delta_pixels: safeDimensionDelta,
    dimension_mismatch: dimensionMismatch,
    source_path: firstString([sourceObject.path, sourceObject.url, obj.source_path, obj.sourcePath]),
    source_screenshot: firstRef([artifacts.source_screenshot, files.sourceScreenshot, obj.source_screenshot, obj.sourceScreenshot]),
    candidate_screenshot: firstRef([artifacts.candidate_screenshot, artifacts.imported_screenshot, files.candidateScreenshot, obj.candidate_screenshot, obj.candidateScreenshot]),
    diff_screenshot: firstRef([artifacts.diff_screenshot, files.diffScreenshot, obj.diff_screenshot, obj.diffScreenshot]),
    visual_diff: firstRef([artifacts.visual_diff, files.visualDiff, obj.visual_diff, obj.visualDiff]),
    visual_explanation_ref: firstRef([artifacts.visual_explanation, files.visualExplanation, visualCompare.explanation, obj.visual_explanation_ref, obj.visualExplanationRef]),
    visual_explanation: visualExplanation,
  };
}

function firstRef(values) {
  for (const value of values) {
    if (typeof value === 'string' && value.trim()) {
      return value.trim();
    }
    const obj = objectValue(value);
    const ref = firstString([obj.path, obj.file, obj.href, obj.url, obj.artifact_name, obj.artifactName]);
    if (ref) {
      return ref;
    }
  }
  return '';
}

// Generic visual-explanation intake. The upstream schema may evolve; this accepts
// the documented sample shape from the subagent prompt without product buckets:
// selector/property/layout/capture diagnostics are summarized and bounded.
function normalizeVisualExplanation(value) {
  const obj = objectValue(value);
  const visualCompare = objectValue(objectValue(obj.summary).visualCompare || objectValue(obj.summary).visual_compare);
  const explanation = objectValue(obj.visual_explanation || obj.visualExplanation || obj.explanation || objectValue(obj.summary).visualExplanation || objectValue(obj.summary).visual_explanation || visualCompare.visualExplanation || visualCompare.visual_explanation || (isVisualExplanationPayload(obj) ? obj : null));
  const summary = { ...objectValue(obj.summary), ...objectValue(explanation.summary) };
  const selectors = summarizeVisualEvidenceItems([
    ...normalizeArray(explanation.selector_diagnostics || explanation.selectorDiagnostics),
    ...normalizeArray(explanation.selectors),
    ...normalizeArray(explanation.selector_mismatches || explanation.selectorMismatches),
    ...normalizeArray(explanation.selector_deltas || explanation.selectorDeltas),
    ...normalizeArray(obj.selector_diagnostics || obj.selectorDiagnostics),
    ...normalizeArray(obj.selector_deltas || obj.selectorDeltas),
  ], ['selector', 'source_selector', 'target_selector', 'reason', 'message', 'mismatch_ratio', 'mismatch_pixels']);
  const properties = summarizeVisualEvidenceItems([
    ...normalizeArray(explanation.property_diagnostics || explanation.propertyDiagnostics),
    ...normalizeArray(explanation.properties),
    ...normalizeArray(explanation.property_diffs || explanation.propertyDiffs),
    ...normalizeArray(obj.property_diagnostics || obj.propertyDiagnostics),
  ], ['selector', 'source_selector', 'target_selector', 'property', 'source_value', 'target_value', 'expected', 'observed', 'delta', 'reason', 'message']);
  const layout = summarizeVisualEvidenceItems([
    ...normalizeArray(explanation.layout_diagnostics || explanation.layoutDiagnostics),
    ...normalizeArray(explanation.layout),
    ...normalizeArray(explanation.layout_diffs || explanation.layoutDiffs),
    ...normalizeArray(explanation.layout_drift || explanation.layoutDrift),
    ...normalizeArray(obj.layout_diagnostics || obj.layoutDiagnostics),
    ...normalizeArray(obj.layout_drift || obj.layoutDrift),
    ...normalizeArray(visualCompare.layout_drift || visualCompare.layoutDrift),
  ], ['selector', 'source_selector', 'target_selector', 'property', 'source_rect', 'target_rect', 'expected', 'observed', 'delta', 'reason', 'message']);
  const capture = summarizeVisualEvidenceItems([
    ...normalizeArray(explanation.capture_diagnostics || explanation.captureDiagnostics),
    ...normalizeArray(explanation.capture),
    ...normalizeArray(obj.capture_diagnostics || obj.captureDiagnostics),
    ...normalizeArray(visualCompare.capture_diagnostics || visualCompare.captureDiagnostics),
  ], ['phase', 'selector', 'source_url', 'target_url', 'viewport', 'full_page', 'reason', 'message']);
  const counts = compactObject({
    selector_diagnostic_count: evidenceCount(summary.selector_diagnostic_count ?? summary.selectorDiagnosticCount ?? explanation.selector_diagnostic_count ?? explanation.selectorDiagnosticCount, selectors.length),
    property_diagnostic_count: evidenceCount(summary.property_diagnostic_count ?? summary.propertyDiagnosticCount ?? explanation.property_diagnostic_count ?? explanation.propertyDiagnosticCount, properties.length),
    layout_diagnostic_count: evidenceCount(summary.layout_diagnostic_count ?? summary.layoutDiagnosticCount ?? explanation.layout_diagnostic_count ?? explanation.layoutDiagnosticCount, layout.length),
    capture_diagnostic_count: evidenceCount(summary.capture_diagnostic_count ?? summary.captureDiagnosticCount ?? explanation.capture_diagnostic_count ?? explanation.captureDiagnosticCount, capture.length),
  });
  if (selectors.length === 0 && properties.length === 0 && layout.length === 0 && capture.length === 0 && Object.keys(counts).length === 0) {
    return null;
  }
  return boundBlob(compactObject({
    schema: firstString([explanation.schema, obj.schema]),
    summary: counts,
    selector_diagnostics: selectors,
    property_diagnostics: properties,
    layout_diagnostics: layout,
    capture_diagnostics: capture,
  }));
}

function isVisualExplanationPayload(value) {
  return typeof value.schema === 'string' && /visual.?explanation/i.test(value.schema);
}

function summarizeVisualEvidenceItems(values, keys) {
  return values
    .map((value) => summarizeVisualEvidenceItem(value, keys))
    .filter((value) => Object.keys(value).length > 0)
    .slice(0, VISUAL_EXPLANATION_SUMMARY_LIMIT);
}

function summarizeVisualEvidenceItem(value, keys) {
  const obj = objectValue(value);
  if (Object.keys(obj).length === 0) {
    return typeof value === 'string' ? { message: truncateString(value) } : {};
  }
  return boundBlob(compactObject(Object.fromEntries(keys.map((key) => [key, obj[key] ?? obj[toCamelCase(key)]]))));
}

function visualExplanationDiagnosticFields(visualExplanation) {
  if (!visualExplanation) {
    return {};
  }
  return {
    visual_explanation_summary: visualExplanation.summary || {},
    visual_selector_diagnostics: visualExplanation.selector_diagnostics || [],
    visual_property_diagnostics: visualExplanation.property_diagnostics || [],
    visual_layout_diagnostics: visualExplanation.layout_diagnostics || [],
    visual_capture_diagnostics: visualExplanation.capture_diagnostics || [],
  };
}

function evidenceCount(value, fallback) {
  const number = Number(value);
  if (Number.isFinite(number) && number >= 0) {
    return number;
  }
  return fallback > 0 ? fallback : undefined;
}

function toCamelCase(value) {
  return value.replace(/_([a-z])/g, (_, char) => char.toUpperCase());
}

function visualParityArtifactRefs(comparison) {
  return [
    ['source_screenshot', comparison.source_screenshot],
    ['candidate_screenshot', comparison.candidate_screenshot],
    ['diff_screenshot', comparison.diff_screenshot],
    ['visual_diff', comparison.visual_diff],
  ]
    .filter(([, ref]) => Boolean(ref))
    .map(([id, ref]) => artifactRef(id, ref, 'visual-parity'));
}

// Capture visual-compare evidence into the SSI `visual_parity_artifacts` slot
// shape so screenshots, the diff, and the mismatch metrics surface on the
// fixture result even when gating is off. Returns null when no visual data was
// produced (the runtime did not render).
export function collectVisualParityArtifacts(payload) {
  const comparisons = collectVisualParityComparisons(payload);
  if (comparisons.length === 0) {
    return null;
  }
  const comparison = comparisons[0];
  const slot = (ref, kind, reason) => (ref
    ? { status: 'captured', kind, ref: artifactRef(kind, ref, 'visual-parity') }
    : { status: 'pending', kind, capture_state: 'not_captured', reason });
  return {
    schema: 'static-site-importer/visual-parity-artifacts/v1',
    owner: 'codebox_runtime',
    metrics: compactObject({
      mismatch_pixels: comparison.mismatch_pixels,
      total_pixels: comparison.total_pixels,
      // `mismatch_ratio` is the dimension-fair (overlap) ratio — the trustworthy
      // signal. Raw union-canvas figures are retained for evidence/diagnosis.
      mismatch_ratio: comparison.mismatch_ratio,
      raw_mismatch_ratio: comparison.raw_mismatch_ratio,
      overlap_mismatch_pixels: comparison.overlap_mismatch_pixels,
      overlap_pixels: comparison.overlap_pixels,
      dimension_delta_pixels: comparison.dimension_delta_pixels,
      dimension_mismatch: comparison.dimension_mismatch,
    }),
    ...(comparison.visual_explanation ? { visual_explanation: comparison.visual_explanation } : {}),
    artifacts: {
      source_screenshot: slot(comparison.source_screenshot, 'source_screenshot', 'Source screenshot was not captured by the runtime.'),
      imported_screenshot: slot(comparison.candidate_screenshot, 'imported_screenshot', 'Imported WordPress screenshot was not captured by the runtime.'),
      diff_screenshot: slot(comparison.diff_screenshot, 'diff_screenshot', 'Diff screenshot was not captured by the runtime.'),
      visual_diff: slot(comparison.visual_diff, 'visual_diff', 'Visual diff output was not captured by the runtime.'),
      visual_explanation: slot(comparison.visual_explanation_ref, 'visual_explanation', 'Visual explanation output was not captured by the runtime.'),
    },
  };
}
