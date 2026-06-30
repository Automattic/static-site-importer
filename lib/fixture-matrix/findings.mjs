// Finding classification, loss-class derivation, and honest loss-acceptance
// (#535) for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import path from 'node:path';

import {
  DEFAULT_FINDING_GROUPS,
  ACCEPTABLE_LOSS_CLASSES,
  UNACCEPTABLE_LOSS_CLASSES,
  RUNTIME_CARRIED_SIGNAL_KEYS,
  VISUAL_PARITY_GATE_SIGNAL_KEYS,
  LOW_NATIVE_CONVERSION_KIND,
  VISUAL_PARITY_MISMATCH_KIND,
  EDITOR_BLOCK_INVALID_KIND,
} from './shared/constants.mjs';
import {
  normalizeArray,
  objectValue,
  isTruthySignal,
} from './shared/utils.mjs';
import { truncateString } from './shared/bounds.mjs';

export function classifyStaticSiteFinding(input = {}) {
  const haystack = [input.kind, input.type, input.code, input.category, input.repair_bucket, input.group_key, input.message, input.reason, input.detail]
    .filter(Boolean)
    .join(' ');
  for (const [group_key, group] of Object.entries(DEFAULT_FINDING_GROUPS)) {
    if (group.patterns.some((pattern) => pattern.test(haystack))) {
      return { group_key, candidate_repo: group.candidate_repo, repair_mode: group.repair_mode };
    }
  }
  return {
    group_key: DEFAULT_FINDING_GROUPS[input.group_key] ? input.group_key : 'static_site_import_quality',
    candidate_repo: input.candidate_repo || 'static-site-importer',
    repair_mode: input.repair_mode || 'import-validation',
  };
}

export function findingsForFixtureResult(result, context = {}) {
  const diagnostics = normalizeArray(result.diagnostics || result.findings || result.messages);
  const findings = diagnostics.map((diagnostic, index) => normalizeDiagnosticFinding(diagnostic, result, index));
  if (result.status === 'failed' && findings.length === 0) {
    findings.push(normalizeDiagnosticFinding({ kind: 'fixture_failed', message: result.error || 'Static-site fixture validation failed without a structured diagnostic.' }, result, 0));
  }
  if (context.executionRequested !== false && context.matrix?.fixtures?.some((fixture) => fixture.id === result.fixture_id) && result.status === 'not_run') {
    findings.push(normalizeDiagnosticFinding({ kind: 'fixture_not_run', message: 'Static-site fixture was discovered but did not produce a validation result.' }, result, 0));
  }
  return findings;
}

export function normalizeDiagnosticFinding(diagnostic, result, index) {
  const raw = diagnostic && typeof diagnostic === 'object' ? diagnostic : { message: String(diagnostic || '') };
  const rawSource = objectValue(raw.source);
  const rawObserved = objectValue(raw.observed);
  const rawExpected = objectValue(raw.expected);
  const rawReproduction = objectValue(raw.reproduction_context || raw.reproductionContext);
  const message = raw.message || raw.reason || raw.detail || rawObserved.reason_code || rawExpected.outcome || raw.code || result.error || '';
  const group = classifyStaticSiteFinding({ ...raw, message });
  const kind = raw.kind || raw.code || raw.type || rawObserved.reason_code || 'static_site_fixture_diagnostic';
  const selector = raw.selector || rawSource.selector || rawReproduction.selector || '';
  const sourcePath = raw.source_path || raw.path || rawSource.path || rawReproduction.source_path || result.fixture_path || '';
  const repairBucket = raw.repair_bucket || group.group_key;
  const countOnlyDiagnostic = isCountOnlyStaticSiteFixtureDiagnostic({ raw, result, kind, message, selector });
  const lossClass = countOnlyDiagnostic ? 'native_conversion' : classifyLossClass({ raw, kind, group_key: group.group_key, repair_bucket: repairBucket, message, result });
  const lossAcceptance = resolveLossAcceptance(lossClass, raw);
  return {
    id: raw.id || `${result.fixture_id || 'fixture'}:${group.group_key}:${index + 1}`,
    kind,
    category: raw.category || group.group_key,
    group_key: group.group_key,
    repair_bucket: repairBucket,
    severity: raw.severity || (result.status === 'failed' ? 'error' : 'warning'),
    fixture_id: result.fixture_id || '',
    fixture_class: result.fixture_class || result.taxonomy?.fixture_class || 'unknown',
    path: sourcePath,
    source_path: sourcePath,
    selector,
    selector_family: selectorFamily(selector),
    pattern_family: patternFamily({ ...raw, kind, group_key: group.group_key, repair_bucket: repairBucket, selector }),
    // Bound the free-text payload fields: a source snippet / observed block
    // output can carry an entire serialized `post_content` body, which at lane
    // scale balloons the aggregate past V8's per-string limit (#554). Truncate
    // to a sane cap so per-finding size is bounded; classification/metrics above
    // are derived from the full diagnostic before truncation.
    reason: truncateString(message),
    source_snippet: truncateString(raw.source_html_preview || raw.html_excerpt || rawSource.snippet || ''),
    observed_output: truncateString(raw.emitted_block_preview || rawObserved.output || ''),
    observed_block_name: raw.block_name || rawObserved.block_name || '',
    repair_mode: raw.repair_mode || group.repair_mode,
    candidate_repo: raw.candidate_repo || group.candidate_repo,
    loss_class: lossClass,
    loss_acceptance: lossAcceptance,
    acceptable_loss: lossAcceptance === 'acceptable',
    actionability: countOnlyDiagnostic ? 'count_only' : 'actionable',
    actionable: !countOnlyDiagnostic,
    artifact_refs: normalizeArray(raw.artifact_refs),
    // The full raw diagnostic is intentionally NOT retained on the finding: it
    // duplicates the (already-bounded) classified fields and can carry raw
    // serialized markup. All classification above is computed from `raw` before
    // this point; downstream consumers read the bounded top-level fields.
  };
}

export function isActionableFinding(finding) {
  return finding.actionable !== false;
}

function isCountOnlyStaticSiteFixtureDiagnostic({ raw, result, kind, message, selector }) {
  if (kind !== 'static_site_fixture_diagnostic' || selector) {
    return false;
  }

  const rawObject = raw && typeof raw === 'object' ? raw : {};
  const sourcePath = rawObject.source_path || rawObject.path;
  const sourceIsFixturePath = sourcePath && result?.fixture_path && path.resolve(String(sourcePath)) === path.resolve(String(result.fixture_path));
  const hasActionableContext = Boolean(
    rawObject.code
    || rawObject.type
    || rawObject.reason_code
    || rawObject.detail
    || (sourcePath && !sourceIsFixturePath)
    || rawObject.source?.selector
    || rawObject.source?.snippet
    || rawObject.observed?.output
  );
  return !hasActionableContext && /^\d+(?:\.\d+)?$/.test(String(message || '').trim());
}

function classifyLossClass({ raw, kind, group_key, repair_bucket, message, result }) {
  const explicit = normalizeLossClass(raw.loss_class || raw.lossClass || raw.classification?.loss_class || raw.classification?.lossClass || raw.acceptability || raw.quality_class || raw.qualityClass);
  if (explicit) {
    return explicit;
  }

  const haystack = [kind, group_key, repair_bucket, message, raw.reason, raw.detail].filter(Boolean).join(' ');
  if (/preserved[_\s-]+runtime[_\s-]+island|runtime island preserved|runtime[_\s-]+island/i.test(haystack)) {
    return 'preserved_runtime_island';
  }
  if (/native[_\s-]+conversion|converted natively|native block/i.test(haystack)) {
    return 'native_conversion';
  }
  if (/editable[_\s-]+approximation|editable approximation|approximation/i.test(haystack)) {
    return 'editable_approximation';
  }
  if (kind === 'fixture_not_run' || group_key === 'fixture_not_run') {
    return 'fixture_not_run';
  }
  if (kind === 'fixture_failed' || group_key === 'fixture_failed') {
    return 'fixture_failed';
  }
  if (group_key === 'low_native_conversion' || kind === LOW_NATIVE_CONVERSION_KIND || /native conversion rate .* below/i.test(haystack)) {
    return 'low_native_conversion';
  }
  if (group_key === 'visual_parity_mismatch' || kind === VISUAL_PARITY_MISMATCH_KIND || /visual parity mismatch|pixel (?:diff|mismatch)/i.test(haystack)) {
    return 'visual_parity_mismatch';
  }
  if (group_key === 'editor_block_invalid' || kind === EDITOR_BLOCK_INVALID_KIND || /editor block validation|block-editor-warning|this block contains unexpected or invalid content/i.test(haystack)) {
    return 'editor_block_invalid';
  }
  if (group_key === 'invalid_block_content' || /invalid block|block validation/i.test(haystack)) {
    return 'invalid_block_content';
  }
  if (group_key === 'dropped_images' || group_key === 'broken_svg' || /missing asset|dropped image|missing image|asset.*missing/i.test(haystack)) {
    return 'missing_asset';
  }
  if (/missing output|output.*missing|empty output/i.test(haystack)) {
    return 'missing_output';
  }
  if (/materialization/i.test(haystack)) {
    return 'importer_materialization_bug';
  }
  if (result.status === 'failed') {
    return 'unsupported_loss';
  }
  return 'native_conversion';
}

function resolveLossAcceptance(lossClass, raw) {
  if (lossClass === 'preserved_runtime_island') {
    // Feature parity: a preserved interactive island is only acceptable when the
    // required runtime/behavior was actually carried or mapped. Absent that
    // explicit positive signal, the behavior is dead and the gate must fail.
    return hasRuntimeCarriedSignal(raw) ? 'acceptable' : 'unacceptable';
  }
  if (lossClass === 'visual_parity_mismatch') {
    // Opt-in gate: a pixel mismatch is captured but non-gating by default
    // (capture-only). It only becomes an unacceptable, gating finding when the
    // run explicitly opted into gating (the parser stamps a gate signal).
    return hasVisualParityGateSignal(raw) ? 'unacceptable' : 'acceptable';
  }
  return ACCEPTABLE_LOSS_CLASSES.has(lossClass) ? 'acceptable' : 'unacceptable';
}

function hasVisualParityGateSignal(raw) {
  const rawObject = objectValue(raw);
  const classification = objectValue(rawObject.classification);
  return VISUAL_PARITY_GATE_SIGNAL_KEYS.some((key) => isTruthySignal(rawObject[key]) || isTruthySignal(classification[key]));
}

function hasRuntimeCarriedSignal(raw) {
  const rawObject = objectValue(raw);
  const classification = objectValue(rawObject.classification);
  return RUNTIME_CARRIED_SIGNAL_KEYS.some((key) => isTruthySignal(rawObject[key]) || isTruthySignal(classification[key]));
}

export function patternFamily(finding) {
  return [
    finding.repair_bucket || finding.group_key || 'static_site_import_quality',
    finding.kind || 'diagnostic',
    selectorFamily(finding.selector),
  ].join(':');
}

export function selectorFamily(selector) {
  const value = String(selector || '').trim();
  if (!value) {
    return '(none)';
  }

  const firstToken = value.split(/\s+|\s*[>+~]\s*/).find(Boolean) || value;
  if (firstToken.startsWith('#')) {
    return `id:${firstToken.slice(1).split(/[:.#[\]]/)[0] || '(unknown)'}`;
  }
  if (firstToken.startsWith('.')) {
    return `class:${firstToken.slice(1).split(/[:.#[\]]/)[0] || '(unknown)'}`;
  }
  if (firstToken.startsWith('[')) {
    return `attr:${firstToken.slice(1).split(/[=\]]/)[0] || '(unknown)'}`;
  }

  return firstToken.split(/[:.#[\]]/)[0] || firstToken;
}

export function dedupeDiagnostics(diagnostics) {
  const seen = new Set();
  return diagnostics.filter((diagnostic) => {
    const normalized = objectValue(diagnostic);
    const key = [normalized.kind || normalized.code || normalized.type || normalized.reason_code, normalized.message || normalized.reason, normalized.path || normalized.source_path, normalized.selector]
      .map((part) => String(part || ''))
      .join('\u0000');
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

export function dedupeFindings(findings) {
  const seen = new Set();
  return findings.filter((finding) => {
    const key = finding.selector || finding.source_snippet
      ? [finding.fixture_id, finding.source_path, finding.selector || finding.selector_family, finding.source_snippet, finding.loss_class].join('\u0000')
      : [finding.fixture_id, finding.loss_class, finding.kind, finding.group_key, finding.reason].join('\u0000');
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

export function normalizeLossClass(value) {
  const normalized = String(value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  const aliases = {
    acceptable: 'native_conversion',
    native: 'native_conversion',
    native_conversion: 'native_conversion',
    editable: 'editable_approximation',
    editable_approximation: 'editable_approximation',
    preserved_runtime_island: 'preserved_runtime_island',
    // The php-transformer emits the loss class as `runtime_island_preserved`
    // (see blocks-engine php-transformer FallbackDiagnostic/HtmlTransformer). Alias
    // it to the canonical `preserved_runtime_island` so the explicit-normalization
    // path wins deterministically instead of relying on the wording regex below.
    runtime_island_preserved: 'preserved_runtime_island',
    runtime_island: 'preserved_runtime_island',
    unsupported: 'unsupported_loss',
    unsupported_loss: 'unsupported_loss',
    materialization_bug: 'importer_materialization_bug',
    importer_materialization_bug: 'importer_materialization_bug',
    invalid_block: 'invalid_block_content',
    invalid_block_output: 'invalid_block_output',
    invalid_block_content: 'invalid_block_content',
    editor_block_invalid: 'editor_block_invalid',
    editor_invalid_block: 'editor_block_invalid',
    low_native_conversion: 'low_native_conversion',
    native_conversion_rate_below_min: 'low_native_conversion',
    visual_parity_mismatch: 'visual_parity_mismatch',
    visual_parity: 'visual_parity_mismatch',
    pixel_mismatch: 'visual_parity_mismatch',
    missing_asset: 'missing_asset',
    missing_assets: 'missing_asset',
    missing_output: 'missing_output',
    fixture_not_run: 'fixture_not_run',
    not_run: 'fixture_not_run',
    fixture_failed: 'fixture_failed',
  };
  const lossClass = aliases[normalized] || normalized;
  return ACCEPTABLE_LOSS_CLASSES.has(lossClass) || UNACCEPTABLE_LOSS_CLASSES.has(lossClass) ? lossClass : '';
}
