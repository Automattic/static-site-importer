/**
 * External dependencies
 */
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

/**
 * Internal dependencies
 */
import {
  clampRatio,
  compactObject,
  finiteNumber,
  firstString,
  normalizeArray,
  objectValue,
} from './shared/utils.mjs';

const DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_VERTICAL_SHIFT = 64;
const DEFAULT_VISUAL_PARITY_ALIGNMENT_MAX_HORIZONTAL_SHIFT = 0;
const DEFAULT_VISUAL_PARITY_ALIGNMENT_PIXELMATCH_THRESHOLD = 0.1;
const DEFAULT_VISUAL_PARITY_ALIGNMENT_OFFSET_TOLERANCE = 2;

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

export function classifyVisualDiffComparisonImages({ source, candidate, diff, comparison, pixelmatchThreshold, maxRegions, minPixels }) {
  const mask = visualDiffMismatchMask(source, candidate, pixelmatchThreshold);
  const computedRegions = visualCompareMismatchRegions(mask, maxRegions);
  const fallbackRegions = comparison.mismatch_regions?.length ? comparison.mismatch_regions : visualCompareMismatchRegions(diff, maxRegions);
  const statsDiff = computedRegions.length > 0 ? mask : diff;
  const regions = (computedRegions.length > 0 ? computedRegions : fallbackRegions)
    .filter((region) => region.pixels >= minPixels)
    .slice(0, maxRegions);
  const classifiedRegions = regions.map((region) => classifyVisualDiffRegion({ region, source, candidate, diff: statsDiff, comparison }))
    .filter(Boolean);
  if (classifiedRegions.length === 0) {
    return null;
  }
  return {
    visual_diff_regions: classifiedRegions,
    visual_diff_cause_summary: visualDiffCauseSummary(classifiedRegions),
  };
}

export function detectedOffsetMagnitude(offset) {
  const value = objectValue(offset);
  return Math.max(Math.abs(finiteNumber(value.x, 0)), Math.abs(finiteNumber(value.y, 0)));
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
  const background = { source: sampleImageBackground(source), candidate: sampleImageBackground(candidate) };
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
