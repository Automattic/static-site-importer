// Visual-parity collector (#538): turns `wordpress.visual-compare` evidence into
// `visual_parity_mismatch` diagnostics + the SSI visual-parity-artifacts slot,
// and resolves the opt-in pixel gate for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).
/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

/**
 * Internal dependencies
 */
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
const DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_VERTICAL_SHIFT = 64;
const DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_HORIZONTAL_SHIFT = 0;
const DEFAULT_VISUAL_PARITY_ALIGNMENT_OFFSET_TOLERANCE = 2;
const DEFAULT_VISUAL_PARITY_ALIGNMENT_PIXELMATCH_THRESHOLD = 0.1;
const DEFAULT_VISUAL_DIFF_CLASSIFICATION_MAX_REGIONS = 8;
const DEFAULT_VISUAL_DIFF_CLASSIFICATION_MIN_PIXELS = 4;

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
  const { threshold, gate, offsetTolerance } = normalizeVisualParityGateOptions(options);
  const diagnostics = [];
  for (const comparison of collectVisualParityComparisons(payload, options)) {
    const gateRatio = comparison.aligned_mismatch_ratio ?? comparison.mismatch_ratio;
    // Dimension mismatch is only a hard gate when we cannot measure a fair ratio
    // (no overlap region — e.g. a zero-area/failed capture).
    const dimensionForcesFinding = comparison.dimension_mismatch && !comparison.has_overlap_signal;
    if (gateRatio > threshold || dimensionForcesFinding) {
      const percent = (gateRatio * 100).toFixed(2);
      const rawPercent = (comparison.raw_mismatch_ratio * 100).toFixed(2);
      const thresholdPercent = (threshold * 100).toFixed(2);
      const fairPixels = comparison.aligned_mismatch_pixels ?? (comparison.has_overlap_signal ? comparison.overlap_mismatch_pixels : comparison.mismatch_pixels);
      const fairTotal = comparison.aligned_total_pixels ?? (comparison.has_overlap_signal ? comparison.overlap_pixels : comparison.total_pixels);
      diagnostics.push({
        kind: VISUAL_PARITY_MISMATCH_KIND,
        ...(gate ? { gate: true, visual_parity_gate: true } : {}),
        source_path: comparison.source_path || '',
        observed_output: `${percent}% pixels differ after alignment (${fairPixels}/${fairTotal})`,
        mismatch_pixels: fairPixels,
        total_pixels: fairTotal,
        mismatch_ratio: gateRatio,
        aligned_mismatch_pixels: comparison.aligned_mismatch_pixels,
        aligned_total_pixels: comparison.aligned_total_pixels,
        aligned_mismatch_ratio: comparison.aligned_mismatch_ratio,
        detected_offset: comparison.detected_offset,
        alignment_pixelmatch_threshold: comparison.alignment_pixelmatch_threshold,
        visual_diff_regions: comparison.visual_diff_regions,
        visual_diff_cause_summary: comparison.visual_diff_cause_summary,
        raw_mismatch_pixels: comparison.mismatch_pixels,
        raw_total_pixels: comparison.total_pixels,
        raw_mismatch_ratio: comparison.raw_mismatch_ratio,
        overlap_mismatch_pixels: comparison.overlap_mismatch_pixels,
        overlap_pixels: comparison.overlap_pixels,
        overlap_mismatch_ratio: comparison.mismatch_ratio,
        threshold,
        dimension_mismatch: comparison.dimension_mismatch,
        dimension_delta_pixels: comparison.dimension_delta_pixels,
        artifact_refs: visualParityArtifactRefs(comparison),
        ...visualExplanationDiagnosticFields(comparison.visual_explanation),
        message: dimensionForcesFinding
          ? `Visual parity dimension mismatch between source and imported output with no measurable overlap region (raw ${comparison.mismatch_pixels}/${comparison.total_pixels} pixels, ${rawPercent}%).`
          : `Aligned visual parity mismatch: ${fairPixels}/${fairTotal} pixels (${percent}%) exceed the ${thresholdPercent}% threshold (raw full-page ${rawPercent}%, detected offset ${formatDetectedOffset(comparison.detected_offset)}).`,
      });
    }
    if (detectedOffsetMagnitude(comparison.detected_offset) > offsetTolerance) {
      diagnostics.push({
        kind: 'visual_parity_offset',
        loss_class: 'visual_parity_mismatch',
        source_path: comparison.source_path || '',
        detected_offset: comparison.detected_offset,
        aligned_mismatch_ratio: comparison.aligned_mismatch_ratio,
        alignment_pixelmatch_threshold: comparison.alignment_pixelmatch_threshold,
        raw_mismatch_ratio: comparison.raw_mismatch_ratio,
        threshold,
        offset_tolerance: offsetTolerance,
        artifact_refs: visualParityArtifactRefs(comparison),
        message: `Visual parity alignment detected an offset of ${formatDetectedOffset(comparison.detected_offset)}, above the ${offsetTolerance}px reporting tolerance.`,
      });
    }
  }
  return diagnostics;
}

export function normalizeVisualParityGateOptions(options = {}) {
  const source = objectValue(options);
  return {
    threshold: clampRatio(finiteNumber(source.threshold ?? source.pixelThreshold ?? source.pixel_threshold ?? source.visualParityPixelThreshold ?? source.visual_parity_pixel_threshold, DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD)),
    gate: isTruthySignal(source.gate ?? source.visualParityGate ?? source.visual_parity_gate),
    alignment: !isFalseySignal(source.alignment ?? source.visualParityAlignment ?? source.visual_parity_alignment ?? true),
    maxVerticalShift: Math.max(0, Math.floor(finiteNumber(source.maxVerticalShift ?? source.max_vertical_shift ?? source.visualParityMaxVerticalShift ?? source.visual_parity_max_vertical_shift, DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_VERTICAL_SHIFT))),
    maxHorizontalShift: Math.max(0, Math.floor(finiteNumber(source.maxHorizontalShift ?? source.max_horizontal_shift ?? source.visualParityMaxHorizontalShift ?? source.visual_parity_max_horizontal_shift, DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_HORIZONTAL_SHIFT))),
    offsetTolerance: Math.max(0, Math.floor(finiteNumber(source.offsetTolerance ?? source.offset_tolerance ?? source.visualParityOffsetTolerance ?? source.visual_parity_offset_tolerance, DEFAULT_VISUAL_PARITY_ALIGNMENT_OFFSET_TOLERANCE))),
    pixelmatchThreshold: clampRatio(finiteNumber(source.pixelmatchThreshold ?? source.pixelmatch_threshold ?? source.visualParityPixelmatchThreshold ?? source.visual_parity_pixelmatch_threshold, DEFAULT_VISUAL_PARITY_ALIGNMENT_PIXELMATCH_THRESHOLD)),
    fixtureArtifactsDirectory: firstString([source.fixtureArtifactsDirectory, source.fixture_artifacts_directory]),
  };
}

function computeAlignedVisualParity(comparison, options = {}) {
  const sourcePath = resolveVisualParityArtifactPath(comparison.source_screenshot, options.fixtureArtifactsDirectory);
  const candidatePath = resolveVisualParityArtifactPath(comparison.candidate_screenshot, options.fixtureArtifactsDirectory);
  if (!sourcePath || !candidatePath) {
    return null;
  }
  try {
    const source = PNG.sync.read(fs.readFileSync(sourcePath));
    const candidate = PNG.sync.read(fs.readFileSync(candidatePath));
    return findBestVisualParityOffset(source, candidate, {
      maxVerticalShift: options.maxVerticalShift,
      maxHorizontalShift: options.maxHorizontalShift,
      pixelmatchThreshold: comparison.pixelmatch_threshold ?? options.pixelmatchThreshold,
    });
  } catch {
    return null;
  }
}

export function classifyVisualDiffRegions(payload, options = {}) {
  const gateOptions = normalizeVisualParityGateOptions(options);
  const comparisons = collectVisualParityComparisons(payload, { ...gateOptions, ...options });
  return comparisons[0]?.visual_diff_classification || null;
}

export function findBestVisualParityOffset(source, candidate, options = {}) {
  const maxVerticalShift = Math.max(0, Math.floor(finiteNumber(options.maxVerticalShift, DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_VERTICAL_SHIFT)));
  const maxHorizontalShift = Math.max(0, Math.floor(finiteNumber(options.maxHorizontalShift, DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_HORIZONTAL_SHIFT)));
  const pixelmatchThreshold = clampRatio(finiteNumber(options.pixelmatchThreshold ?? options.pixelmatch_threshold ?? options.threshold, DEFAULT_VISUAL_PARITY_ALIGNMENT_PIXELMATCH_THRESHOLD));
  let best = null;
  for (let y = -maxVerticalShift; y <= maxVerticalShift; y += 1) {
    for (let x = -maxHorizontalShift; x <= maxHorizontalShift; x += 1) {
      const score = scoreVisualParityOffset(source, candidate, x, y, pixelmatchThreshold);
      if (!score) {
        continue;
      }
      if (!best
        || score.aligned_mismatch_ratio < best.aligned_mismatch_ratio
        || (score.aligned_mismatch_ratio === best.aligned_mismatch_ratio && detectedOffsetMagnitude(score.detected_offset) < detectedOffsetMagnitude(best.detected_offset))) {
        best = score;
      }
    }
  }
  return best;
}

function scoreVisualParityOffset(source, candidate, x, y, threshold) {
  const sourceX = Math.max(0, -x);
  const candidateX = Math.max(0, x);
  const sourceY = Math.max(0, -y);
  const candidateY = Math.max(0, y);
  const width = Math.min(source.width - sourceX, candidate.width - candidateX);
  const height = Math.min(source.height - sourceY, candidate.height - candidateY);
  if (width <= 0 || height <= 0) {
    return null;
  }
  const sourceCrop = new Uint8Array(width * height * 4);
  const candidateCrop = new Uint8Array(width * height * 4);
  copyPngCrop(source, sourceCrop, width, height, sourceX, sourceY);
  copyPngCrop(candidate, candidateCrop, width, height, candidateX, candidateY);
  const mismatchPixels = pixelmatch(sourceCrop, candidateCrop, null, width, height, { threshold, includeAA: false });
  const totalPixels = width * height;
  return {
    aligned_mismatch_pixels: mismatchPixels,
    aligned_total_pixels: totalPixels,
    aligned_mismatch_ratio: totalPixels > 0 ? mismatchPixels / totalPixels : 0,
    detected_offset: { x: normalizeZeroOffset(x), y: normalizeZeroOffset(y) },
    alignment_pixelmatch_threshold: threshold,
  };
}

function normalizeZeroOffset(value) {
  return Object.is(value, -0) ? 0 : value;
}

function copyPngCrop(image, target, width, height, sourceX, sourceY) {
  const bytesPerPixel = 4;
  const targetStride = width * bytesPerPixel;
  const sourceStride = image.width * bytesPerPixel;
  for (let row = 0; row < height; row += 1) {
    const sourceStart = ((sourceY + row) * sourceStride) + (sourceX * bytesPerPixel);
    const targetStart = row * targetStride;
    image.data.copy(target, targetStart, sourceStart, sourceStart + targetStride);
  }
}

function resolveVisualParityArtifactPath(ref, fixtureArtifactsDirectory) {
  if (!ref || typeof ref !== 'string') {
    return '';
  }
  if (path.isAbsolute(ref) && fs.existsSync(ref)) {
    return ref;
  }
  if (fixtureArtifactsDirectory) {
    const candidate = path.join(fixtureArtifactsDirectory, ref);
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }
  return '';
}

function detectedOffsetMagnitude(offset) {
  const value = objectValue(offset);
  return Math.max(Math.abs(finiteNumber(value.x, 0)), Math.abs(finiteNumber(value.y, 0)));
}

function formatDetectedOffset(offset) {
  const value = objectValue(offset);
  return `x=${finiteNumber(value.x, 0)}px, y=${finiteNumber(value.y, 0)}px`;
}

function isFalseySignal(value) {
  if (value === undefined || value === null) {
    return false;
  }
  if (typeof value === 'string') {
    return ['', 'false', '0', 'no', 'none', 'null', 'undefined'].includes(value.trim().toLowerCase());
  }
  if (typeof value === 'number') {
    return !Number.isFinite(value) || value === 0;
  }
  return value === false;
}

// Collect candidate visual-compare records from either the normalized
// `homeboy/VisualParityArtifact/v1` artifact (summary.*), the raw
// `wp-codebox/visual-compare/v1` diff (comparison.*), or loosely-shaped payloads.
function collectVisualParityComparisons(payload, options = {}) {
  const candidates = [
    ...normalizeArray(payload.visual_parity || payload.visualParity),
    ...normalizeArray(payload.visual_parity_artifacts || payload.visualParityArtifacts),
    ...normalizeArray(payload.visual_compare || payload.visualCompare),
    ...normalizeArray(payload.visual_diff || payload.visualDiff),
    ...normalizeArray(payload.comparisons),
    ...normalizeArray(payload.visual_parity?.comparisons || payload.visualParity?.comparisons),
    ...normalizeArray(payload.visual_explanation?.comparisons || payload.visualExplanation?.comparisons),
  ];
  if (isVisualParityPayload(payload)) {
    candidates.push(payload);
  }
  const gateOptions = normalizeVisualParityGateOptions(options);
  return dedupeVisualParityComparisons(candidates.map((candidate) => normalizeVisualParityComparison(candidate, gateOptions)).filter(Boolean));
}

function dedupeVisualParityComparisons(comparisons) {
  const byMetrics = new Map();
  for (const comparison of comparisons) {
    const key = [
      comparison.mismatch_pixels,
      comparison.total_pixels,
      comparison.overlap_mismatch_pixels,
      comparison.overlap_pixels,
      comparison.aligned_mismatch_pixels,
      comparison.aligned_total_pixels,
      comparison.detected_offset?.x,
      comparison.detected_offset?.y,
      comparison.dimension_delta_pixels,
      comparison.dimension_mismatch,
    ].join('\u0000');
    const existing = byMetrics.get(key);
    byMetrics.set(key, existing ? mergeVisualParityComparison(existing, comparison) : comparison);
  }
  return [...byMetrics.values()];
}

function mergeVisualParityComparison(left, right) {
  return {
    ...left,
    source_path: left.source_path || right.source_path,
    source_screenshot: left.source_screenshot || right.source_screenshot,
    candidate_screenshot: left.candidate_screenshot || right.candidate_screenshot,
    diff_screenshot: left.diff_screenshot || right.diff_screenshot,
    visual_diff: left.visual_diff || right.visual_diff,
    visual_explanation_ref: left.visual_explanation_ref || right.visual_explanation_ref,
    visual_explanation: left.visual_explanation || right.visual_explanation,
    mismatch_regions: left.mismatch_regions?.length ? left.mismatch_regions : right.mismatch_regions,
    visual_diff_regions: left.visual_diff_regions?.length ? left.visual_diff_regions : right.visual_diff_regions,
    visual_diff_cause_summary: Object.keys(left.visual_diff_cause_summary || {}).length ? left.visual_diff_cause_summary : right.visual_diff_cause_summary,
    visual_diff_classification: left.visual_diff_classification || right.visual_diff_classification,
    aligned_mismatch_pixels: left.aligned_mismatch_pixels ?? right.aligned_mismatch_pixels,
    aligned_total_pixels: left.aligned_total_pixels ?? right.aligned_total_pixels,
    aligned_mismatch_ratio: left.aligned_mismatch_ratio ?? right.aligned_mismatch_ratio,
    detected_offset: left.detected_offset || right.detected_offset,
    alignment_pixelmatch_threshold: left.alignment_pixelmatch_threshold ?? right.alignment_pixelmatch_threshold,
  };
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

function normalizeVisualParityComparison(value, options = {}) {
  const obj = objectValue(value);
  const summary = objectValue(obj.summary);
  const visualCompare = objectValue(summary.visualCompare || summary.visual_compare);
  const comparison = objectValue(obj.comparison);
  const compareOptions = objectValue(obj.options || summary.options || visualCompare.options || comparison.options);
  const mismatchPixels = firstNumber([summary.mismatch_pixels, summary.mismatchPixels, visualCompare.mismatchPixels, visualCompare.mismatch_pixels, comparison.mismatchPixels, comparison.mismatch_pixels, obj.mismatch_pixels, obj.mismatchPixels]);
  const totalPixels = firstNumber([summary.total_pixels, summary.totalPixels, visualCompare.totalPixels, visualCompare.total_pixels, comparison.totalPixels, comparison.total_pixels, obj.total_pixels, obj.totalPixels]);
  const explicitRatio = firstNumber([summary.mismatch_ratio, summary.mismatchRatio, visualCompare.mismatchRatio, visualCompare.mismatch_ratio, comparison.mismatchRatio, comparison.mismatch_ratio, obj.mismatch_ratio, obj.mismatchRatio]);
  // Dimension-fair (overlap-region) metrics emitted by wp-codebox's trustworthy
  // visual-compare. When present these drive the gate; otherwise the fair ratio
  // falls back to the raw union-canvas ratio for backward compatibility.
  const overlapMismatchPixels = firstNumber([summary.overlap_mismatch_pixels, summary.overlapMismatchPixels, visualCompare.overlapMismatchPixels, visualCompare.overlap_mismatch_pixels, comparison.overlapMismatchPixels, comparison.overlap_mismatch_pixels, obj.overlap_mismatch_pixels, obj.overlapMismatchPixels]);
  const overlapRatio = firstNumber([summary.overlap_mismatch_ratio, summary.overlapMismatchRatio, visualCompare.overlapMismatchRatio, visualCompare.overlap_mismatch_ratio, comparison.overlapMismatchRatio, comparison.overlap_mismatch_ratio, obj.overlap_mismatch_ratio, obj.overlapMismatchRatio]);
  const dimensionMismatch = Boolean(summary.dimension_mismatch ?? summary.dimensionMismatch ?? visualCompare.dimensionMismatch ?? visualCompare.dimension_mismatch ?? comparison.dimensionMismatch ?? comparison.dimension_mismatch ?? obj.dimension_mismatch);
  const hasMetrics = [mismatchPixels, totalPixels, explicitRatio, overlapRatio, overlapMismatchPixels].some((metric) => Number.isFinite(metric));
  if (!hasMetrics && !dimensionMismatch) {
    return null;
  }
  const overlapPixels = firstNumber([summary.overlap_pixels, summary.overlapPixels, visualCompare.overlapPixels, visualCompare.overlap_pixels, comparison.overlapPixels, comparison.overlap_pixels, obj.overlap_pixels, obj.overlapPixels]);
  const dimensionDeltaPixels = firstNumber([summary.dimension_delta_pixels, summary.dimensionDeltaPixels, visualCompare.dimensionDeltaPixels, visualCompare.dimension_delta_pixels, comparison.dimensionDeltaPixels, comparison.dimension_delta_pixels, obj.dimension_delta_pixels, obj.dimensionDeltaPixels]);
  const safeMismatch = Number.isFinite(mismatchPixels) ? mismatchPixels : 0;
  const safeTotal = Number.isFinite(totalPixels) ? totalPixels : 0;
  let rawRatio = 0;
  if (safeTotal > 0) {
    rawRatio = safeMismatch / safeTotal;
  } else if (Number.isFinite(explicitRatio)) {
    rawRatio = explicitRatio;
  }
  // The fair ratio is the overlap mismatch over the overlap area. Prefer explicit
  // counts, then an explicit overlap ratio, then degrade to the raw ratio.
  const safeOverlapPixels = Number.isFinite(overlapPixels) ? overlapPixels : 0;
  const safeOverlapMismatch = Number.isFinite(overlapMismatchPixels) ? overlapMismatchPixels : 0;
  const hasOverlapSignal = safeOverlapPixels > 0 || Number.isFinite(overlapRatio);
  let fairRatio = rawRatio;
  if (safeOverlapPixels > 0) {
    fairRatio = safeOverlapMismatch / safeOverlapPixels;
  } else if (Number.isFinite(overlapRatio)) {
    fairRatio = overlapRatio;
  }
  let safeDimensionDelta = 0;
  if (Number.isFinite(dimensionDeltaPixels)) {
    safeDimensionDelta = dimensionDeltaPixels;
  } else if (safeTotal > 0 && safeOverlapPixels > 0) {
    safeDimensionDelta = safeTotal - safeOverlapPixels;
  }
  const files = objectValue(obj.files);
  const artifacts = objectValue(obj.artifacts);
  const artifactSlots = objectValue(objectValue(obj.visual_parity_artifacts || obj.visualParityArtifacts).artifacts);
  const sourceObject = objectValue(obj.source);
  const visualExplanation = normalizeVisualExplanation(obj);
  const mismatchRegions = normalizeMismatchRegions([
    ...normalizeArray(comparison.regions || comparison.mismatchRegions || comparison.mismatch_regions),
    ...normalizeArray(obj.regions || obj.mismatchRegions || obj.mismatch_regions),
    ...normalizeArray(visualExplanation?.mismatch_regions || visualExplanation?.mismatchRegions),
  ]);
  const normalized = {
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
    pixelmatch_threshold: firstNumber([compareOptions.threshold, compareOptions.pixelmatchThreshold, compareOptions.pixelmatch_threshold]),
    source_path: firstString([sourceObject.path, sourceObject.url, obj.source_path, obj.sourcePath]),
    source_screenshot: firstRef([artifacts.source_screenshot, artifactSlots.source_screenshot, files.sourceScreenshot, obj.source_screenshot, obj.sourceScreenshot]),
    candidate_screenshot: firstRef([artifacts.candidate_screenshot, artifacts.imported_screenshot, artifactSlots.candidate_screenshot, artifactSlots.imported_screenshot, files.candidateScreenshot, obj.candidate_screenshot, obj.candidateScreenshot]),
    diff_screenshot: firstRef([artifacts.diff_screenshot, artifactSlots.diff_screenshot, files.diffScreenshot, obj.diff_screenshot, obj.diffScreenshot]),
    visual_diff: firstRef([artifacts.visual_diff, artifactSlots.visual_diff, files.visualDiff, obj.visual_diff, obj.visualDiff]),
    visual_explanation_ref: firstRef([artifacts.visual_explanation, artifactSlots.visual_explanation, files.visualExplanation, visualCompare.explanation, obj.visual_explanation_ref, obj.visualExplanationRef]),
    visual_explanation: visualExplanation,
    mismatch_regions: mismatchRegions,
  };
  const aligned = options.alignment ? computeAlignedVisualParity(normalized, options) : null;
  const withAlignment = aligned ? { ...normalized, ...aligned } : normalized;
  const classification = classifyVisualParityComparisonRegions(withAlignment, options);
  return classification ? { ...withAlignment, ...classification } : withAlignment;
}

function normalizeMismatchRegions(values) {
  return values.map((value) => {
    const row = objectValue(value);
    const x = Math.floor(finiteNumber(row.x, NaN));
    const y = Math.floor(finiteNumber(row.y, NaN));
    const width = Math.floor(finiteNumber(row.width, NaN));
    const height = Math.floor(finiteNumber(row.height, NaN));
    const pixels = Math.floor(finiteNumber(row.pixels ?? row.pixel_count ?? row.pixelCount, width * height));
    if (![x, y, width, height, pixels].every(Number.isFinite) || width <= 0 || height <= 0 || pixels <= 0) {
      return null;
    }
    return { x, y, width, height, pixels };
  }).filter(Boolean);
}

function classifyVisualParityComparisonRegions(comparison, options = {}) {
  const sourcePath = resolveVisualParityArtifactPath(comparison.source_screenshot, options.fixtureArtifactsDirectory);
  const candidatePath = resolveVisualParityArtifactPath(comparison.candidate_screenshot, options.fixtureArtifactsDirectory);
  const diffPath = resolveVisualParityArtifactPath(comparison.diff_screenshot, options.fixtureArtifactsDirectory);
  if (!sourcePath || !candidatePath || !diffPath) {
    return null;
  }
  try {
    const source = PNG.sync.read(fs.readFileSync(sourcePath));
    const candidate = PNG.sync.read(fs.readFileSync(candidatePath));
    const diff = PNG.sync.read(fs.readFileSync(diffPath));
    const pixelmatchThreshold = clampRatio(finiteNumber(comparison.pixelmatch_threshold ?? options.pixelmatchThreshold ?? options.pixelmatch_threshold, DEFAULT_VISUAL_PARITY_ALIGNMENT_PIXELMATCH_THRESHOLD));
    const mask = visualDiffMismatchMask(source, candidate, pixelmatchThreshold);
    const computedRegions = visualCompareMismatchRegions(mask, positiveInteger(options.maxRegions ?? options.visualDiffMaxRegions, DEFAULT_VISUAL_DIFF_CLASSIFICATION_MAX_REGIONS));
    const fallbackRegions = comparison.mismatch_regions?.length ? comparison.mismatch_regions : visualCompareMismatchRegions(diff, positiveInteger(options.maxRegions ?? options.visualDiffMaxRegions, DEFAULT_VISUAL_DIFF_CLASSIFICATION_MAX_REGIONS));
    const statsDiff = computedRegions.length > 0 ? mask : diff;
    const regions = (computedRegions.length > 0 ? computedRegions : fallbackRegions)
      .filter((region) => region.pixels >= DEFAULT_VISUAL_DIFF_CLASSIFICATION_MIN_PIXELS)
      .slice(0, positiveInteger(options.maxRegions ?? options.visualDiffMaxRegions, DEFAULT_VISUAL_DIFF_CLASSIFICATION_MAX_REGIONS));
    const classifiedRegions = regions.map((region) => classifyVisualDiffRegion({ region, source, candidate, diff: statsDiff, comparison }))
      .filter(Boolean);
    if (classifiedRegions.length === 0) {
      return null;
    }
    const summary = visualDiffCauseSummary(classifiedRegions);
    const artifact = {
      schema: 'static-site-importer/visual-diff-classification/v1',
      source_screenshot: comparison.source_screenshot,
      candidate_screenshot: comparison.candidate_screenshot,
      diff_screenshot: comparison.diff_screenshot,
      pixelmatch: {
        threshold: pixelmatchThreshold,
        metric: 'perceptual_yiq_color_distance',
      },
      detected_offset: comparison.detected_offset,
      visual_diff_regions: classifiedRegions,
      visual_diff_cause_summary: summary,
    };
    return {
      visual_diff_regions: classifiedRegions,
      visual_diff_cause_summary: summary,
      visual_diff_classification: artifact,
    };
  } catch {
    return null;
  }
}

function visualDiffMismatchMask(source, candidate, threshold) {
  const width = Math.min(source.width, candidate.width);
  const height = Math.min(source.height, candidate.height);
  if (width <= 0 || height <= 0) {
    return new PNG({ width: Math.max(source.width, candidate.width), height: Math.max(source.height, candidate.height) });
  }
  const sourceCanvas = visualDiffCanvas(source, width, height);
  const candidateCanvas = visualDiffCanvas(candidate, width, height);
  const mask = new PNG({ width, height });
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const index = ((y * width) + x) << 2;
      const isMismatch = yiqColorDistance(
        { r: sourceCanvas.data[index], g: sourceCanvas.data[index + 1], b: sourceCanvas.data[index + 2], a: sourceCanvas.data[index + 3] },
        { r: candidateCanvas.data[index], g: candidateCanvas.data[index + 1], b: candidateCanvas.data[index + 2], a: candidateCanvas.data[index + 3] },
      ) > threshold || Math.abs(sourceCanvas.data[index + 3] - candidateCanvas.data[index + 3]) > 16;
      if (isMismatch) {
        mask.data[index] = 255;
        mask.data[index + 3] = 255;
      }
    }
  }
  return mask;
}

function visualDiffCanvas(image, width, height) {
  if (image.width === width && image.height === height) {
    return image;
  }
  const canvas = new PNG({ width, height });
  const copyHeight = Math.min(image.height, height);
  const copyWidth = Math.min(image.width, width);
  for (let y = 0; y < copyHeight; y += 1) {
    const sourceStart = (image.width * y) << 2;
    const targetStart = (width * y) << 2;
    image.data.copy(canvas.data, targetStart, sourceStart, sourceStart + (copyWidth << 2));
  }
  return canvas;
}

function positiveInteger(value, fallback) {
  const parsed = Math.floor(finiteNumber(value, fallback));
  return parsed > 0 ? parsed : fallback;
}

function classifyVisualDiffRegion({ region, source, candidate, diff, comparison }) {
  const bbox = { x: region.x, y: region.y, width: region.width, height: region.height };
  const stats = visualDiffRegionStats({ region, source, candidate, diff, offset: comparison.detected_offset });
  const mapped = mapRegionToVisualExplanation(region, comparison.visual_explanation);
  const cause = dominantVisualDiffCause(stats, mapped, comparison.detected_offset);
  return compactObject({
    bbox,
    pixel_count: region.pixels,
    dominant_cause: cause.cause,
    confidence: cause.confidence,
    ...(mapped.selector ? { mapped_selector: mapped.selector } : {}),
    stats: compactObject({
      mean_yiq_distance: roundMetric(stats.meanYiqDistance),
      edge_divergence_ratio: roundMetric(stats.edgeDivergenceRatio),
      source_content_ratio: roundMetric(stats.sourceContentRatio),
      candidate_content_ratio: roundMetric(stats.candidateContentRatio),
      source_bbox_content_ratio: roundMetric(stats.sourceBboxContentRatio),
      candidate_bbox_content_ratio: roundMetric(stats.candidateBboxContentRatio),
      shifted_match_ratio: Number.isFinite(stats.shiftedMatchRatio) ? roundMetric(stats.shiftedMatchRatio) : undefined,
    }),
  });
}

function dominantVisualDiffCause(stats, mapped, offset) {
  const offsetMagnitude = detectedOffsetMagnitude(offset);
  const textHint = /^(?:a|button|span|p|h[1-6]|li|label|strong|em|small)$/i.test(mapped.tag || '') || Boolean(mapped.textChanged);
  const colorHint = mapped.styleProperties.some((property) => /color|background|fill|stroke/i.test(property));
  const geometryHint = Boolean(mapped.boundingBoxChanged) || mapped.styleProperties.some((property) => /radius|width|height|margin|padding|border|gap|top|left|right|bottom/i.test(property));
  const oneSideBlank = Math.min(stats.sourceContentRatio, stats.candidateContentRatio) < 0.08 && Math.max(stats.sourceContentRatio, stats.candidateContentRatio) > 0.18;
  const regionAspect = Math.max(stats.regionWidth, stats.regionHeight) / Math.max(1, Math.min(stats.regionWidth, stats.regionHeight));
  if (offsetMagnitude > DEFAULT_VISUAL_PARITY_ALIGNMENT_OFFSET_TOLERANCE && Number.isFinite(stats.shiftedMatchRatio) && stats.shiftedMatchRatio > 0.75) {
    return { cause: 'position_offset', confidence: 0.86 };
  }
  if (oneSideBlank && (stats.regionWidth < 12 || stats.regionHeight < 12 || regionAspect > 3)) {
    return { cause: 'restyle_geometry', confidence: 0.72 };
  }
  if (oneSideBlank && Math.min(stats.sourceBboxContentRatio, stats.candidateBboxContentRatio) < 0.08) {
    return { cause: 'missing_or_extra_element', confidence: 0.9 };
  }
  if (textHint && stats.edgeDivergenceRatio >= 0.12 && stats.meanYiqDistance < 0.35) {
    return { cause: 'text_typography', confidence: 0.78 };
  }
  if ((colorHint || stats.meanYiqDistance >= 0.18) && stats.edgeDivergenceRatio < 0.14 && Math.abs(stats.sourceContentRatio - stats.candidateContentRatio) < 0.25) {
    return { cause: 'color_shift', confidence: colorHint ? 0.86 : 0.78 };
  }
  if (geometryHint || stats.edgeDivergenceRatio >= 0.14) {
    return { cause: 'restyle_geometry', confidence: geometryHint ? 0.82 : 0.72 };
  }
  return { cause: stats.meanYiqDistance >= 0.12 ? 'color_shift' : 'restyle_geometry', confidence: 0.55 };
}

function visualDiffRegionStats({ region, source, candidate, diff, offset }) {
  const background = {
    source: sampleImageBackground(source),
    candidate: sampleImageBackground(candidate),
  };
  let mismatchPixels = 0;
  let yiqDistance = 0;
  let sourceContent = 0;
  let candidateContent = 0;
  let sourceBboxContent = 0;
  let candidateBboxContent = 0;
  let bboxPixels = 0;
  let edgeDivergence = 0;
  let edgeSamples = 0;
  let shiftedSamples = 0;
  let shiftedMatches = 0;
  const dx = Math.floor(finiteNumber(offset?.x, 0));
  const dy = Math.floor(finiteNumber(offset?.y, 0));
  for (let y = region.y; y < region.y + region.height; y += 1) {
    for (let x = region.x; x < region.x + region.width; x += 1) {
      if (!diffPixel(diff, x, y)) {
        const sourceColor = pixelAt(source, x, y);
        const candidateColor = pixelAt(candidate, x, y);
        bboxPixels += 1;
        if (isContentPixel(sourceColor, background.source)) {
          sourceBboxContent += 1;
        }
        if (isContentPixel(candidateColor, background.candidate)) {
          candidateBboxContent += 1;
        }
        continue;
      }
      const sourceColor = pixelAt(source, x, y);
      const candidateColor = pixelAt(candidate, x, y);
      bboxPixels += 1;
      mismatchPixels += 1;
      yiqDistance += yiqColorDistance(sourceColor, candidateColor);
      if (isContentPixel(sourceColor, background.source)) {
        sourceContent += 1;
        sourceBboxContent += 1;
      }
      if (isContentPixel(candidateColor, background.candidate)) {
        candidateContent += 1;
        candidateBboxContent += 1;
      }
      const sourceEdge = edgeAt(source, x, y);
      const candidateEdge = edgeAt(candidate, x, y);
      if (sourceEdge || candidateEdge) {
        edgeSamples += 1;
        if (sourceEdge !== candidateEdge) {
          edgeDivergence += 1;
        }
      }
      if (dx || dy) {
        const shiftedCandidate = pixelAt(candidate, x + dx, y + dy);
        if (shiftedCandidate) {
          shiftedSamples += 1;
          if (yiqColorDistance(sourceColor, shiftedCandidate) < 0.08) {
            shiftedMatches += 1;
          }
        }
      }
    }
  }
  return {
    meanYiqDistance: mismatchPixels > 0 ? yiqDistance / mismatchPixels : 0,
    regionWidth: region.width,
    regionHeight: region.height,
    edgeDivergenceRatio: edgeSamples > 0 ? edgeDivergence / edgeSamples : 0,
    sourceContentRatio: mismatchPixels > 0 ? sourceContent / mismatchPixels : 0,
    candidateContentRatio: mismatchPixels > 0 ? candidateContent / mismatchPixels : 0,
    sourceBboxContentRatio: bboxPixels > 0 ? sourceBboxContent / bboxPixels : 0,
    candidateBboxContentRatio: bboxPixels > 0 ? candidateBboxContent / bboxPixels : 0,
    shiftedMatchRatio: shiftedSamples > 0 ? shiftedMatches / shiftedSamples : undefined,
  };
}

function visualDiffCauseSummary(regions) {
  const summary = {};
  for (const region of regions) {
    summary[region.dominant_cause] = (summary[region.dominant_cause] || 0) + region.pixel_count;
  }
  return Object.fromEntries(Object.entries(summary).sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])));
}

function visualCompareMismatchRegions(diff, maxRegions) {
  const visited = new Uint8Array(diff.width * diff.height);
  const regions = [];
  for (let y = 0; y < diff.height; y += 1) {
    for (let x = 0; x < diff.width; x += 1) {
      const index = y * diff.width + x;
      if (visited[index] || !diffPixel(diff, x, y)) {
        continue;
      }
      regions.push(floodDiffRegion(diff, x, y, visited));
    }
  }
  return regions.sort((a, b) => b.pixels - a.pixels).slice(0, maxRegions);
}

function floodDiffRegion(diff, startX, startY, visited) {
  const stack = [[startX, startY]];
  let minX = startX;
  let maxX = startX;
  let minY = startY;
  let maxY = startY;
  let pixels = 0;
  while (stack.length > 0) {
    const [x, y] = stack.pop();
    if (x < 0 || y < 0 || x >= diff.width || y >= diff.height) {
      continue;
    }
    const index = y * diff.width + x;
    if (visited[index] || !diffPixel(diff, x, y)) {
      continue;
    }
    visited[index] = 1;
    pixels += 1;
    minX = Math.min(minX, x);
    maxX = Math.max(maxX, x);
    minY = Math.min(minY, y);
    maxY = Math.max(maxY, y);
    stack.push([x + 1, y], [x - 1, y], [x, y + 1], [x, y - 1]);
  }
  return { x: minX, y: minY, width: maxX - minX + 1, height: maxY - minY + 1, pixels };
}

function diffPixel(image, x, y) {
  const color = pixelAt(image, x, y);
  return Boolean(color && (color.r > 0 || color.g > 0 || color.b > 0));
}

function sampleImageBackground(image) {
  return pixelAt(image, 0, 0) || { r: 255, g: 255, b: 255, a: 255 };
}

function pixelAt(image, x, y) {
  if (!image || x < 0 || y < 0 || x >= image.width || y >= image.height) {
    return null;
  }
  const offset = ((y * image.width) + x) << 2;
  return { r: image.data[offset], g: image.data[offset + 1], b: image.data[offset + 2], a: image.data[offset + 3] };
}

function isContentPixel(color, background) {
  if (!color || color.a < 16) {
    return false;
  }
  return yiqColorDistance(color, background) > 0.06;
}

function edgeAt(image, x, y) {
  const center = pixelAt(image, x, y);
  const right = pixelAt(image, x + 1, y);
  const down = pixelAt(image, x, y + 1);
  if (!center || (!right && !down)) {
    return false;
  }
  return (right && luminanceDelta(center, right) > 32) || (down && luminanceDelta(center, down) > 32);
}

function luminanceDelta(left, right) {
  return Math.abs(luminance(left) - luminance(right));
}

function luminance(color) {
  return (0.299 * color.r) + (0.587 * color.g) + (0.114 * color.b);
}

function yiqColorDistance(left, right) {
  if (!left || !right) {
    return 1;
  }
  const y = ((left.r - right.r) * 0.29889531) + ((left.g - right.g) * 0.58662247) + ((left.b - right.b) * 0.11448223);
  const i = ((left.r - right.r) * 0.59597799) - ((left.g - right.g) * 0.2741761) - ((left.b - right.b) * 0.32180189);
  const q = ((left.r - right.r) * 0.21147017) - ((left.g - right.g) * 0.52261711) + ((left.b - right.b) * 0.31114694);
  return Math.sqrt((y * y * 0.5053) + (i * i * 0.299) + (q * q * 0.1957)) / 255;
}

function mapRegionToVisualExplanation(region, visualExplanation) {
  const explanation = objectValue(visualExplanation);
  const candidates = [
    ...normalizeArray(explanation.changes),
    ...normalizeArray(explanation.selectors),
    ...normalizeArray(explanation.selector_diagnostics),
    ...normalizeArray(explanation.property_diagnostics),
    ...normalizeArray(explanation.layout_diagnostics),
  ].map((item) => visualExplanationCandidate(item)).filter(Boolean);
  let best = null;
  for (const candidate of candidates) {
    const score = candidate.bbox ? bboxOverlapRatio(region, candidate.bbox) : 0;
    if (!best || score > best.score) {
      best = { ...candidate, score };
    }
  }
  return best && (best.score > 0 || best.selector || best.tag) ? best : { styleProperties: [] };
}

function visualExplanationCandidate(item) {
  const row = objectValue(item);
  const changes = objectValue(row.changes);
  const boundingBox = objectValue(changes.boundingBox || changes.bounding_box || row.boundingBox || row.bounding_box || row.source_rect || row.sourceRect);
  const sourceBox = objectValue(boundingBox.source || boundingBox);
  const candidateBox = objectValue(boundingBox.candidate);
  const bbox = unionBbox(normalizeBbox(sourceBox), normalizeBbox(candidateBox));
  const styles = objectValue(changes.styles || row.styles || row.style_deltas || row.styleDeltas);
  return {
    selector: firstString([row.selector, row.source_selector, row.sourceSelector, row.target_selector, row.targetSelector, row.path]),
    tag: firstString([row.tag, row.tagName, row.tag_name]),
    bbox,
    styleProperties: Object.keys(styles),
    boundingBoxChanged: Boolean(changes.boundingBox || changes.bounding_box || row.source_rect || row.sourceRect),
    textChanged: Boolean(changes.text || row.text || row.source_text || row.sourceText || row.target_text || row.targetText),
  };
}

function normalizeBbox(value) {
  const row = objectValue(value);
  const x = finiteNumber(row.x, NaN);
  const y = finiteNumber(row.y, NaN);
  const width = finiteNumber(row.width, NaN);
  const height = finiteNumber(row.height, NaN);
  return [x, y, width, height].every(Number.isFinite) && width > 0 && height > 0 ? { x, y, width, height } : null;
}

function unionBbox(left, right) {
  if (!left) {
    return right;
  }
  if (!right) {
    return left;
  }
  const x1 = Math.min(left.x, right.x);
  const y1 = Math.min(left.y, right.y);
  const x2 = Math.max(left.x + left.width, right.x + right.width);
  const y2 = Math.max(left.y + left.height, right.y + right.height);
  return { x: x1, y: y1, width: x2 - x1, height: y2 - y1 };
}

function bboxOverlapRatio(left, right) {
  const x1 = Math.max(left.x, right.x);
  const y1 = Math.max(left.y, right.y);
  const x2 = Math.min(left.x + left.width, right.x + right.width);
  const y2 = Math.min(left.y + left.height, right.y + right.height);
  const overlap = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
  const area = Math.max(1, left.width * left.height);
  return overlap / area;
}

function roundMetric(value) {
  return Math.round(value * 1000) / 1000;
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
  const mismatchRegions = normalizeMismatchRegions(normalizeArray(explanation.mismatchRegions || explanation.mismatch_regions));
  const changes = summarizeVisualExplanationChanges(normalizeArray(explanation.changes));
  const counts = compactObject({
    selector_diagnostic_count: evidenceCount(summary.selector_diagnostic_count ?? summary.selectorDiagnosticCount ?? explanation.selector_diagnostic_count ?? explanation.selectorDiagnosticCount, selectors.length),
    property_diagnostic_count: evidenceCount(summary.property_diagnostic_count ?? summary.propertyDiagnosticCount ?? explanation.property_diagnostic_count ?? explanation.propertyDiagnosticCount, properties.length),
    layout_diagnostic_count: evidenceCount(summary.layout_diagnostic_count ?? summary.layoutDiagnosticCount ?? explanation.layout_diagnostic_count ?? explanation.layoutDiagnosticCount, layout.length),
    capture_diagnostic_count: evidenceCount(summary.capture_diagnostic_count ?? summary.captureDiagnosticCount ?? explanation.capture_diagnostic_count ?? explanation.captureDiagnosticCount, capture.length),
  });
  if (selectors.length === 0 && properties.length === 0 && layout.length === 0 && capture.length === 0 && mismatchRegions.length === 0 && changes.length === 0 && Object.keys(counts).length === 0) {
    return null;
  }
  return boundBlob(compactObject({
    schema: firstString([explanation.schema, obj.schema]),
    summary: counts,
    selector_diagnostics: selectors,
    property_diagnostics: properties,
    layout_diagnostics: layout,
    capture_diagnostics: capture,
    mismatch_regions: mismatchRegions,
    changes,
  }));
}

function summarizeVisualExplanationChanges(values) {
  return values.map((value) => {
    const row = objectValue(value);
    const changes = objectValue(row.changes);
    const boundingBox = objectValue(changes.boundingBox || changes.bounding_box);
    return boundBlob(compactObject({
      path: firstString([row.path, row.selector]),
      tag: firstString([row.tag, row.tagName, row.tag_name]),
      changes: compactObject({
        text: changes.text ? true : undefined,
        boundingBox: changes.boundingBox || changes.bounding_box ? boundingBox : undefined,
        styles: changes.styles ? Object.fromEntries(Object.keys(objectValue(changes.styles)).map((key) => [key, true])) : undefined,
      }),
    }));
  }).filter((value) => Object.keys(value).length > 0).slice(0, VISUAL_EXPLANATION_SUMMARY_LIMIT);
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
export function collectVisualParityArtifacts(payload, options = {}) {
  const comparisons = collectVisualParityComparisons(payload, options);
  if (comparisons.length === 0) {
    return null;
  }
  const comparison = selectBestVisualParityArtifactComparison(comparisons);
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
      aligned_mismatch_pixels: comparison.aligned_mismatch_pixels,
      aligned_total_pixels: comparison.aligned_total_pixels,
      aligned_mismatch_ratio: comparison.aligned_mismatch_ratio,
      detected_offset: comparison.detected_offset,
      alignment_pixelmatch_threshold: comparison.alignment_pixelmatch_threshold,
      raw_mismatch_ratio: comparison.raw_mismatch_ratio,
      overlap_mismatch_pixels: comparison.overlap_mismatch_pixels,
      overlap_pixels: comparison.overlap_pixels,
      dimension_delta_pixels: comparison.dimension_delta_pixels,
      dimension_mismatch: comparison.dimension_mismatch,
    }),
    ...(comparison.visual_diff_regions?.length ? { visual_diff_regions: comparison.visual_diff_regions } : {}),
    ...(comparison.visual_diff_cause_summary ? { visual_diff_cause_summary: comparison.visual_diff_cause_summary } : {}),
    ...(comparison.visual_diff_classification ? { visual_diff_classification: comparison.visual_diff_classification } : {}),
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

function selectBestVisualParityArtifactComparison(comparisons) {
  return [...comparisons].sort((a, b) => visualParityArtifactScore(b) - visualParityArtifactScore(a))[0];
}

function visualParityArtifactScore(comparison) {
  return [
    comparison.source_screenshot,
    comparison.candidate_screenshot,
    comparison.diff_screenshot,
    comparison.visual_diff,
    comparison.visual_explanation_ref,
  ].filter(Boolean).length
    + (comparison.visual_diff_classification ? 10 : 0)
    + (comparison.visual_diff_regions?.length ? 5 : 0)
    + (comparison.total_pixels > 0 ? 1 : 0);
}
