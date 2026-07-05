// Editor-fidelity collector: imported frontend screenshot vs block-editor canvas
// screenshot scoring and artifact slot normalization.

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
  DEFAULT_EDITOR_FRONTEND_PARITY_PIXEL_THRESHOLD,
  EDITOR_RENDER_DIVERGENCE_KIND,
} from '../shared/constants.mjs';
import {
  artifactRef,
  clampRatio,
  compactObject,
  finiteNumber,
  firstNumber,
  firstString,
  isTruthySignal,
  normalizeArray,
  objectValue,
} from '../shared/utils.mjs';
import { findBestVisualParityOffset } from './visual-parity.mjs';

const DEFAULT_PIXELMATCH_THRESHOLD = 0.1;
const DEFAULT_MAX_VERTICAL_SHIFT = 64;
const DEFAULT_MAX_HORIZONTAL_SHIFT = 0;

export function collectImportedFrontPage(payload) {
  const row = importedPageRows(payload).find((page) => page.is_front_page || page.source_path === 'website/index.html' || /(?:^|\/)index\.html$/i.test(page.source_path || ''))
    || importedPageRows(payload)[0]
    || null;
  return row ? compactObject(row) : null;
}

export function collectEditorFrontendParity(payload, options = {}) {
  const normalized = normalizeEditorFrontendParity(payload, options);
  const fallbackArtifacts = fallbackEditorFrontendArtifacts(payload);
  if (!normalized && !fallbackArtifacts) {
    return null;
  }
  return {
    schema: 'static-site-importer/editor-frontend-parity/v1',
    metrics: normalized ? compactObject({
      raw_mismatch_pixels: normalized.raw_mismatch_pixels,
      raw_total_pixels: normalized.raw_total_pixels,
      raw_mismatch_ratio: normalized.raw_mismatch_ratio,
      aligned_mismatch_pixels: normalized.aligned_mismatch_pixels,
      aligned_total_pixels: normalized.aligned_total_pixels,
      aligned_mismatch_ratio: normalized.aligned_mismatch_ratio,
      detected_offset: normalized.detected_offset,
      alignment_pixelmatch_threshold: normalized.alignment_pixelmatch_threshold,
    }) : {},
    artifacts: normalized ? editorFrontendParityArtifacts(normalized) : fallbackArtifacts,
  };
}

function fallbackEditorFrontendArtifacts(payload) {
  const editorScreenshot = firstRef(editorScreenshotRefs(payload));
  const frontendScreenshot = firstRef(frontendScreenshotRefs(payload));
  if (!editorScreenshot && !frontendScreenshot) {
    return null;
  }
  return editorFrontendParityArtifacts({ editor_screenshot: editorScreenshot, frontend_screenshot: frontendScreenshot });
}

export function collectEditorFrontendParityDiagnostics(payload, options = {}) {
  const parity = normalizeEditorFrontendParity(payload, options);
  if (!parity) {
    return [];
  }
  const gate = normalizeEditorFrontendParityOptions(options).gate;
  const threshold = normalizeEditorFrontendParityOptions(options).threshold;
  const ratio = parity.aligned_mismatch_ratio ?? parity.raw_mismatch_ratio;
  if (ratio <= threshold) {
    return [];
  }
  const percent = (ratio * 100).toFixed(2);
  const thresholdPercent = (threshold * 100).toFixed(2);
  return [{
    kind: EDITOR_RENDER_DIVERGENCE_KIND,
    loss_class: 'editor_render_divergence',
    ...(gate ? { gate: true, editor_frontend_parity_gate: true } : {}),
    post_id: parity.post_id,
    mismatch_ratio: ratio,
    raw_mismatch_ratio: parity.raw_mismatch_ratio,
    aligned_mismatch_ratio: parity.aligned_mismatch_ratio,
    raw_mismatch_pixels: parity.raw_mismatch_pixels,
    raw_total_pixels: parity.raw_total_pixels,
    aligned_mismatch_pixels: parity.aligned_mismatch_pixels,
    aligned_total_pixels: parity.aligned_total_pixels,
    detected_offset: parity.detected_offset,
    threshold,
    artifact_refs: editorFrontendParityArtifactRefs(parity),
    message: `Editor canvas diverges from imported frontend: ${percent}% pixels differ after alignment, exceeding the ${thresholdPercent}% threshold.`,
  }];
}

export function normalizeEditorFrontendParityOptions(options = {}) {
  const source = objectValue(options);
  return {
    threshold: clampRatio(finiteNumber(source.threshold ?? source.pixelThreshold ?? source.pixel_threshold ?? source.editorFrontendParityThreshold ?? source.editor_frontend_parity_threshold, DEFAULT_EDITOR_FRONTEND_PARITY_PIXEL_THRESHOLD)),
    gate: isTruthySignal(source.gate ?? source.editorFrontendParityGate ?? source.editor_frontend_parity_gate),
    maxVerticalShift: Math.max(0, Math.floor(finiteNumber(source.maxVerticalShift ?? source.max_vertical_shift, DEFAULT_MAX_VERTICAL_SHIFT))),
    maxHorizontalShift: Math.max(0, Math.floor(finiteNumber(source.maxHorizontalShift ?? source.max_horizontal_shift, DEFAULT_MAX_HORIZONTAL_SHIFT))),
    pixelmatchThreshold: clampRatio(finiteNumber(source.pixelmatchThreshold ?? source.pixelmatch_threshold, DEFAULT_PIXELMATCH_THRESHOLD)),
    fixtureArtifactsDirectory: firstString([source.fixtureArtifactsDirectory, source.fixture_artifacts_directory]),
  };
}

function normalizeEditorFrontendParity(payload, rawOptions = {}) {
  const options = normalizeEditorFrontendParityOptions(rawOptions);
  const editorScreenshot = firstRef(editorScreenshotRefs(payload));
  const frontendScreenshot = firstRef(frontendScreenshotRefs(payload));
  const editorPath = resolveArtifactPath(editorScreenshot, options.fixtureArtifactsDirectory);
  const frontendPath = resolveArtifactPath(frontendScreenshot, options.fixtureArtifactsDirectory);
  if (!editorPath || !frontendPath) {
    return null;
  }
  try {
    const editor = PNG.sync.read(fs.readFileSync(editorPath));
    const frontend = PNG.sync.read(fs.readFileSync(frontendPath));
    const raw = scoreOverlap(frontend, editor, options.pixelmatchThreshold);
    const aligned = findBestVisualParityOffset(frontend, editor, {
      maxVerticalShift: options.maxVerticalShift,
      maxHorizontalShift: options.maxHorizontalShift,
      pixelmatchThreshold: options.pixelmatchThreshold,
    });
    return compactObject({
      post_id: importedPostId(payload),
      editor_screenshot: editorScreenshot,
      frontend_screenshot: frontendScreenshot,
      raw_mismatch_pixels: raw.mismatchPixels,
      raw_total_pixels: raw.totalPixels,
      raw_mismatch_ratio: raw.totalPixels > 0 ? raw.mismatchPixels / raw.totalPixels : 0,
      aligned_mismatch_pixels: aligned?.aligned_mismatch_pixels,
      aligned_total_pixels: aligned?.aligned_total_pixels,
      aligned_mismatch_ratio: aligned?.aligned_mismatch_ratio,
      detected_offset: aligned?.detected_offset,
      alignment_pixelmatch_threshold: options.pixelmatchThreshold,
    });
  } catch {
    return null;
  }
}

function scoreOverlap(left, right, threshold) {
  const width = Math.min(left.width, right.width);
  const height = Math.min(left.height, right.height);
  if (width <= 0 || height <= 0) {
    return { mismatchPixels: 0, totalPixels: 0 };
  }
  const leftCrop = new Uint8Array(width * height * 4);
  const rightCrop = new Uint8Array(width * height * 4);
  copyPngCrop(left, leftCrop, width, height);
  copyPngCrop(right, rightCrop, width, height);
  return { mismatchPixels: pixelmatch(leftCrop, rightCrop, null, width, height, { threshold, includeAA: false }), totalPixels: width * height };
}

function copyPngCrop(image, target, width, height) {
  const bytesPerPixel = 4;
  const targetStride = width * bytesPerPixel;
  const sourceStride = image.width * bytesPerPixel;
  for (let row = 0; row < height; row += 1) {
    image.data.copy(target, row * targetStride, row * sourceStride, row * sourceStride + targetStride);
  }
}

function importedPageRows(payload) {
  const root = objectValue(payload);
  const report = objectValue(root.import_report || root.importReport || root.report || root.result?.import_report || root.result?.importReport);
  const sourceDocs = objectValue(root.source_documents || root.sourceDocuments || report.source_documents || report.sourceDocuments || root.result?.source_documents || root.result?.sourceDocuments);
  const sourcePages = normalizeArray(sourceDocs.pages || sourceDocs.Pages);
  const pagesMap = objectValue(root.pages || root.result?.pages || report.pages);
  const sourceOfTruthPages = normalizeArray(objectValue(objectValue(root.source_of_truth || root.sourceOfTruth || report.source_of_truth || report.sourceOfTruth).desired).pages);
  const rows = [];
  for (const page of sourcePages) {
    const row = pageRow(page);
    if (row.post_id) rows.push(row);
  }
  for (const page of sourceOfTruthPages) {
    const row = pageRow(page);
    if (row.post_id) rows.push(row);
  }
  for (const [sourcePath, postId] of Object.entries(pagesMap)) {
    const numeric = Number(postId);
    if (Number.isInteger(numeric) && numeric > 0) {
      rows.push({ source_path: sourcePath, post_id: numeric, post_type: 'page' });
    }
  }
  return dedupePages(rows);
}

function pageRow(page) {
  const obj = objectValue(page);
  const target = objectValue(obj.target);
  const postId = firstNumber([obj.post_id, obj.postId, obj.materialized_post_id, obj.materializedPostId, target.post_id, target.postId]);
  return compactObject({
    source_path: firstString([obj.source_path, obj.sourcePath, obj.path, obj.filename, obj.file]),
    post_id: Number.isInteger(postId) && postId > 0 ? postId : undefined,
    post_type: firstString([obj.post_type, obj.postType, target.post_type, target.postType, 'page']),
    permalink: firstString([obj.permalink, obj.url]),
    is_front_page: Boolean(obj.is_front_page || obj.isFrontPage || /(?:^|\/)index\.html$/i.test(String(obj.source_path || obj.path || ''))),
  });
}

function dedupePages(rows) {
  const seen = new Set();
  return rows.filter((row) => {
    const key = `${row.post_id}:${row.source_path || ''}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function importedPostId(payload) {
  return collectImportedFrontPage(payload)?.post_id || firstNumber([payload.post_id, payload.postId]);
}

function editorScreenshotRefs(payload) {
  const files = objectValue(payload.files);
  const artifacts = objectValue(payload.artifacts);
  const editor = objectValue(payload.editor_frontend_parity || payload.editorFrontendParity || payload.editor_fidelity || payload.editorFidelity);
  return [
    files.screenshot,
    artifacts.editor_screenshot,
    artifacts.editorScreenshot,
    editor.editor_screenshot,
    editor.editorScreenshot,
    payload.editor_screenshot,
    payload.editorScreenshot,
  ];
}

function frontendScreenshotRefs(payload) {
  const artifacts = objectValue(payload.artifacts);
  const visualArtifacts = objectValue(objectValue(payload.visual_parity_artifacts || payload.visualParityArtifacts).artifacts);
  const editor = objectValue(payload.editor_frontend_parity || payload.editorFrontendParity || payload.editor_fidelity || payload.editorFidelity);
  return [
    artifacts.imported_screenshot,
    artifacts.candidate_screenshot,
    visualArtifacts.imported_screenshot?.ref,
    visualArtifacts.candidate_screenshot?.ref,
    editor.frontend_screenshot,
    editor.frontendScreenshot,
    payload.frontend_screenshot,
    payload.frontendScreenshot,
  ];
}

function firstRef(values) {
  for (const value of values) {
    if (typeof value === 'string' && value.trim()) return value.trim();
    const obj = objectValue(value);
    const ref = firstString([obj.path, obj.file, obj.href, obj.url, obj.artifact_name, obj.artifactName]);
    if (ref) return ref;
  }
  return '';
}

function resolveArtifactPath(ref, fixtureArtifactsDirectory) {
  if (!ref || typeof ref !== 'string') return '';
  if (path.isAbsolute(ref) && fs.existsSync(ref)) return ref;
  if (fixtureArtifactsDirectory) {
    const candidate = path.join(fixtureArtifactsDirectory, ref);
    if (fs.existsSync(candidate)) return candidate;
  }
  return '';
}

function editorFrontendParityArtifacts(parity) {
  const slot = (ref, kind, reason) => (ref
    ? { status: 'captured', kind, ref: artifactRef(kind, ref, 'editor-fidelity') }
    : { status: 'pending', kind, capture_state: 'not_captured', reason });
  return {
    editor_screenshot: slot(parity.editor_screenshot, 'editor_screenshot', 'Editor canvas screenshot was not captured.'),
    frontend_screenshot: slot(parity.frontend_screenshot, 'frontend_screenshot', 'Imported frontend screenshot was not captured.'),
  };
}

function editorFrontendParityArtifactRefs(parity) {
  return [
    ['editor_screenshot', parity.editor_screenshot],
    ['frontend_screenshot', parity.frontend_screenshot],
  ].filter(([, ref]) => Boolean(ref)).map(([id, ref]) => artifactRef(id, ref, 'editor-fidelity'));
}
