// Editor-validation collector (#537): turns editor-canvas-probe / validateBlock
// evidence into `editor_block_invalid` diagnostics for the Static Site Importer
// fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import {
  EDITOR_BLOCK_INVALID_KIND,
  EDITOR_INVALID_BLOCK_SELECTOR_GROUP,
  EDITOR_INVALID_BLOCK_SELECTORS,
  EDITOR_BLOCK_INVALID_DEFAULT_DETAIL,
  EDITOR_VALIDATE_BLOCKS_SCHEMA,
  EDITOR_VALIDATION_METHOD,
  EDITOR_VALIDATION_PROVIDER,
} from '../shared/constants.mjs';
import {
  normalizeArray,
  numberValue,
  firstNumber,
  firstString,
  compactObject,
  objectValue,
  diagnosticMessage,
} from '../shared/utils.mjs';

// Turn editor-validation evidence (either explicit per-block validity from a
// `validateBlock`/getBlocks pass, or `wordpress.editor-canvas-probe`
// invalid-block warning matches) into `editor_block_invalid` diagnostics. These
// flow through `normalizeDiagnosticFinding` and gate via the
// `editor_block_invalid` unacceptable loss class. Valid blocks emit nothing.
export function collectEditorValidationDiagnostics(payload) {
  const diagnostics = [];

  for (const block of collectEditorValidatedBlocks(payload)) {
    if (isInvalidEditorBlock(block)) {
      diagnostics.push(editorInvalidBlockDiagnostic(block));
    }
  }

  for (const group of collectEditorCanvasInvalidGroups(payload)) {
    const count = numberValue(group.visible_count ?? group.visibleCount ?? group.count);
    if (count <= 0) {
      continue;
    }
    const detail = firstString([group.first_match?.text, group.firstMatch?.text, EDITOR_BLOCK_INVALID_DEFAULT_DETAIL]);
    diagnostics.push({
      kind: EDITOR_BLOCK_INVALID_KIND,
      selector: group.selector || EDITOR_INVALID_BLOCK_SELECTORS[0],
      source_path: group.source_path || group.sourcePath || '',
      observed_output: detail,
      message: `Editor rendered ${count} invalid-block warning${count === 1 ? '' : 's'} for the imported post (${detail}).`,
    });
  }

  return diagnostics;
}

function collectEditorValidatedBlocks(payload) {
  const blocks = [
    ...normalizeArray(payload.editor_blocks || payload.editorBlocks),
    ...normalizeArray(payload.editor_validation?.blocks || payload.editorValidation?.blocks),
    ...normalizeArray(payload.editor_validation?.results || payload.editorValidation?.results),
    ...normalizeArray(payload.editor_state?.blocks || payload.editorState?.blocks),
  ];
  // `wordpress.editor-validate-blocks` (wp.blocks.validateBlock) emits the
  // per-block `{ name, isValid, issues }` set at the payload top level.
  if (isEditorValidateBlocksPayload(payload)) {
    blocks.push(...normalizeArray(payload.results));
  }
  return blocks;
}

// Recognize a `wordpress.editor-validate-blocks` payload (the real
// `wp.blocks.validateBlock` editor-validation result). Matches either the
// payload itself or a nested `editor_validation`/`editorValidation` slot.
export function isEditorValidateBlocksPayload(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }
  if (value.schema === EDITOR_VALIDATE_BLOCKS_SCHEMA) {
    return true;
  }
  if (value.validation_method === EDITOR_VALIDATION_METHOD || value.validationMethod === EDITOR_VALIDATION_METHOD) {
    return true;
  }
  const hasCounts = ['total_blocks', 'totalBlocks', 'valid_blocks', 'validBlocks', 'invalid_blocks', 'invalidBlocks']
    .some((key) => Object.hasOwn(value, key));
  return hasCounts && Array.isArray(value.results);
}

function editorValidatePayload(payload) {
  for (const candidate of [payload, payload?.editor_validation, payload?.editorValidation]) {
    if (isEditorValidateBlocksPayload(candidate)) {
      return candidate;
    }
  }
  return null;
}

// Normalize a `wordpress.editor-validate-blocks` payload into the headline
// editor-validity metrics — `total_blocks` / `valid_blocks` / `invalid_blocks`
// plus the `validation_method`/`validation_provider`. Counts come from the
// command's own totals when present, otherwise they are recomputed from the
// per-block `results`. Returns null when no validateBlock evidence is present
// (never fabricated). These feed `result.editor_validation`, which the
// editor-quality collector turns into `editor_valid_block_rate` /
// `invalid_block_count` distinct from the PHP round-trip.
export function collectEditorValidation(payload) {
  const source = editorValidatePayload(objectValue(payload));
  if (!source) {
    return null;
  }
  const results = normalizeArray(source.results).filter((entry) => entry && typeof entry === 'object');
  const totalFromResults = results.length;
  const invalidFromResults = results.filter((block) => isInvalidEditorBlock(block)).length;
  const total = pickCount(source.total_blocks ?? source.totalBlocks, totalFromResults);
  const invalid = pickCount(source.invalid_blocks ?? source.invalidBlocks, invalidFromResults);
  const valid = pickCount(source.valid_blocks ?? source.validBlocks, Math.max(0, total - invalid));
  return compactObject({
    validation_method: firstString([source.validation_method, source.validationMethod, EDITOR_VALIDATION_METHOD]),
    validation_provider: firstString([source.validation_provider, source.validationProvider, EDITOR_VALIDATION_PROVIDER]),
    total_blocks: total,
    valid_blocks: valid,
    invalid_blocks: invalid,
  });
}

function pickCount(value, fallback) {
  const number = firstNumber([value]);
  return Number.isFinite(number) ? number : numberValue(fallback);
}

function isInvalidEditorBlock(block) {
  if (!block || typeof block !== 'object') {
    return false;
  }
  if (block.isValid === false || block.is_valid === false || block.valid === false) {
    return true;
  }
  // A `validateBlock`-style result: [isValid, issues] or { isValid }.
  if (Array.isArray(block.validation)) {
    return block.validation[0] === false;
  }
  return false;
}

function editorInvalidBlockDiagnostic(block) {
  const name = block.name || block.block_name || block.blockName || '';
  const clientId = block.clientId || block.client_id || '';
  const selector = block.selector || (clientId ? `[data-block="${clientId}"]` : '');
  const detail = firstString([
    Array.isArray(block.issues) ? block.issues.join('; ') : '',
    Array.isArray(block.validationIssues) ? block.validationIssues.map((issue) => diagnosticMessage(issue)).filter(Boolean).join('; ') : '',
    block.validity_detail || block.validityDetail,
    block.reason,
    EDITOR_BLOCK_INVALID_DEFAULT_DETAIL,
  ]);
  return {
    kind: EDITOR_BLOCK_INVALID_KIND,
    block_name: name,
    observed_block_name: name,
    selector,
    source_path: block.source_path || block.source || '',
    observed_output: firstString([block.originalContent, block.expectedContent, block.html_excerpt]),
    message: `Editor reported block "${name || 'unknown'}" as invalid: ${detail}.`,
  };
}

function collectEditorCanvasInvalidGroups(payload) {
  const groups = [];
  for (const summary of collectEditorCanvasSummaries(payload)) {
    for (const group of normalizeArray(summary.selectorSummary?.groups || summary.selector_summary?.groups || summary.groups)) {
      if (isEditorInvalidBlockGroup(group)) {
        groups.push(group);
      }
    }
  }
  return groups;
}

function collectEditorCanvasSummaries(payload) {
  const summaries = [];
  for (const candidate of [
    payload.summary,
    payload.editor_canvas || payload.editorCanvas,
    payload.editor_validation?.canvas || payload.editorValidation?.canvas,
  ]) {
    for (const value of normalizeArray(candidate)) {
      if (value && typeof value === 'object' && (value.selectorSummary || value.selector_summary || value.groups)) {
        summaries.push(value);
      }
    }
  }
  return summaries;
}

function isEditorInvalidBlockGroup(group) {
  if (!group || typeof group !== 'object') {
    return false;
  }
  const name = String(group.name || '').toLowerCase();
  if (name === EDITOR_INVALID_BLOCK_SELECTOR_GROUP || /invalid|warning/.test(name)) {
    return true;
  }
  return /block-editor-warning|is-invalid/.test(String(group.selector || ''));
}
