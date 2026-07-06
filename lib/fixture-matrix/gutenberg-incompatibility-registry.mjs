// Gutenberg incompatibility registry aggregation for fixture-matrix runs.
// Consumes generic findings, visual diff regions, and future editor render divergence signals.

import { boundBlob } from './shared/bounds.mjs';
import { compactObject, normalizeArray, numberValue, objectValue, pushUnique, slug } from './shared/utils.mjs';

export const GUTENBERG_INCOMPATIBILITY_REGISTRY_SCHEMA = 'static-site-importer/gutenberg-incompatibility-registry/v1';
export const DEFAULT_CUSTOM_BLOCK_CANDIDATE_FIXTURE_THRESHOLD = 2;

const PATTERNS = [
  pattern('static-form', 'Static newsletter/contact/search-style form markup that requires fields, submission semantics, validation, and response behavior.', 'core_html', 'WordPress core has no generic form block with field schema, submit handling, validation state, and submission response behavior.', 'custom-block-candidate', true, /<form\b|\bnewsletter\b|\bcontact\s+form\b|\bsubscribe\b/i),
  pattern('js-commerce-controls', 'Commerce controls such as quantity steppers, add-to-cart controls, cart counters, and product option interactions.', 'runtime_island', 'Core blocks do not provide commerce purchase controls, quantity-stepper behavior, product option state, cart mutation, or add-to-cart runtime semantics.', 'custom-block-candidate', true, /quantity|\bqty\b|add[-_\s]?to[-_\s]?cart|\bcart\b|checkout|product[-_\s]?grid|product[-_\s]?option|woocommerce|\bprice\b/i),
  pattern('contact-layout', 'Contact/social layout islands that combine contact affordances, decorative media, and layout wrappers not represented by a single core block.', 'core_html', 'Core blocks can represent individual contact links and media, but not an arbitrary contact widget/layout island with source-specific wrapper semantics as one editable primitive.', 'custom-block-candidate', true, /\bcontact(?:[-_\s]?(?:content|layout|card|section|widget|info|details?))?\b|mailto:|tel:|\bsocial[-_\s]?(?:links?|icons?)\b/i),
  pattern('inline-svg-filter-gradient', 'Inline SVG artwork that depends on defs such as filters, masks, clip paths, gradients, symbols, or data-URI SVG preservation.', 'core_html', 'Core image/media blocks cannot preserve arbitrary inline SVG DOM, defs/filter graphs, gradient IDs, masks, clip paths, or scriptable SVG structure as editable block attributes.', 'custom-block-candidate', true, /<svg\b|<filter\b|<lineargradient\b|<radialgradient\b|<clippath\b|<mask\b|<defs\b|data:image\/svg\+xml|svg[-_\s]?(?:filter|gradient|mask|clip|defs)/i),
  pattern('css-grid-masonry', 'CSS grid/masonry layouts that rely on dense auto-placement, masonry-like columns, or source-order-independent packing.', 'fidelity_loss', 'Core layout blocks expose grid/flex controls but do not model masonry packing, dense auto-placement, or arbitrary CSS grid placement semantics as editable attributes.', 'custom-block-candidate', true, /masonry|grid-auto-flow\s*:\s*dense|column-count|columns\s*:|css[-_\s]?grid|grid-template|grid-area/i),
  pattern('position-sticky-nav', 'Sticky/fixed navigation or header behavior coupled to source scroll state, offsets, or JavaScript classes.', 'fidelity_loss', 'Core navigation/group blocks can approximate simple sticky positioning, but source-specific scroll state, offset choreography, and JS class toggles require transformer/runtime work before a custom block decision.', 'convertible', false, /position\s*:\s*(?:sticky|fixed)|sticky[-_\s]?nav|fixed[-_\s]?(?:nav|header)|scroll[-_\s]?(?:state|class)/i),
  pattern('editor-render-divergence', 'Markup renders on the frontend but diverges or disappears in the block editor canvas.', 'fidelity_loss', 'Editor render divergence means a block representation exists but editor serialization/rendering cannot reproduce the frontend output without transformer or block support.', 'convertible', false, /editor[_\s-]?render[_\s-]?divergence|editor canvas divergence|frontend.*editor/i),
  pattern('legitimate-runtime-island', 'Preserved interactive/runtime island whose behavior is carried intentionally rather than converted into static core block attributes.', 'runtime_island', 'The source behavior depends on runtime JavaScript or browser APIs that core static blocks do not execute as editable content.', 'runtime-island', true, /runtime island|runtime_island|script requires runtime|canvas|webgl|audio|video player|animation runtime/i),
];

function pattern(pattern_key, description, fallback_kind, impossible_in_core_reason, base_classification, no_core_block_path, match) {
  return { pattern_key, description, fallback_kind, impossible_in_core_reason, base_classification, no_core_block_path, match };
}

export function buildGutenbergIncompatibilityRegistry(result = {}, options = {}) {
  const threshold = positiveInteger(options.customBlockCandidateFixtureThreshold ?? options.custom_block_candidate_fixture_threshold, DEFAULT_CUSTOM_BLOCK_CANDIDATE_FIXTURE_THRESHOLD);
  const rows = new Map();
  const fixtures = normalizeArray(result.fixtures);

  for (const finding of normalizeArray(result.findings)) {
    const patternDefinition = classifyFindingPattern(finding);
    if (!patternDefinition) {
      continue;
    }
    addEvidence(rows, patternDefinition, {
      fixture_id: finding.fixture_id,
      fallback_kind: fallbackKindForFinding(finding, patternDefinition),
      impact: numberValue(finding.impact || finding.pixel_count || finding.count || 1) || 1,
      selector: finding.selector,
      source_path: finding.source_path || finding.path,
      source_snippet: finding.source_snippet,
      reason: finding.reason || patternDefinition.impossible_in_core_reason,
      signal: finding.kind || finding.reason_code || finding.loss_class,
      observed_block_name: finding.observed_block_name,
    });
  }

  for (const fixture of fixtures) {
    addVisualDiffEvidence(rows, fixture);
    addEditorRenderDivergenceEvidence(rows, fixture);
    addCoreHtmlCompositionEvidence(rows, fixture);
  }

  const patterns = [...rows.values()].map((row) => finalizeRow(row, threshold)).sort(sortRows);
  return {
    schema: GUTENBERG_INCOMPATIBILITY_REGISTRY_SCHEMA,
    matrix_id: result.matrix_id || '',
    generated_from: {
      result_schema: result.schema || '',
      fixture_count: fixtures.length,
      finding_count: normalizeArray(result.findings).length,
    },
    promotion_rule: {
      custom_block_candidate_fixture_threshold: threshold,
      rule: `classification becomes custom-block-candidate when no_core_block_path is true and the pattern appears in at least ${threshold} distinct fixtures; runtime-island patterns remain runtime-island.`,
    },
    summary: {
      pattern_count: patterns.length,
      custom_block_candidate_count: patterns.filter((row) => row.classification === 'custom-block-candidate').length,
      convertible_count: patterns.filter((row) => row.classification === 'convertible').length,
      runtime_island_count: patterns.filter((row) => row.classification === 'runtime-island').length,
      top_patterns: patterns.slice(0, 10).map((row) => ({ pattern_key: row.pattern_key, classification: row.classification, fixture_count: row.fixture_count, finding_count: row.finding_count, impact_score: row.impact_score })),
    },
    patterns,
  };
}

export function renderGutenbergIncompatibilityRegistryMarkdown(registry = {}) {
  const lines = [
    '# Gutenberg Incompatibility Registry',
    '',
    'Generated from Static Site Importer fixture-matrix findings. This records generic HTML/CSS/runtime patterns where core blocks cannot preserve source parity without a fallback, transformer fix, runtime island, or future custom block.',
    '',
    `Schema: \`${registry.schema || GUTENBERG_INCOMPATIBILITY_REGISTRY_SCHEMA}\``,
    `Matrix: \`${registry.matrix_id || '(unknown)'}\``,
    `Promotion rule: ${registry.promotion_rule?.rule || ''}`,
    '',
    '| Rank | Pattern | Classification | Fixtures | Findings | Impact | Reason |',
    '| ---: | --- | --- | ---: | ---: | ---: | --- |',
  ];
  normalizeArray(registry.patterns).forEach((row, index) => {
    lines.push(`| ${index + 1} | \`${row.pattern_key}\` | ${row.classification} | ${row.fixture_count} | ${row.finding_count} | ${row.impact_score} | ${table(row.impossible_in_core_reason)} |`);
  });
  lines.push('', '## Pattern Evidence', '');
  for (const row of normalizeArray(registry.patterns)) {
    lines.push(`### ${row.pattern_key}`, '', `- Description: ${row.description}`, `- Fallback kind: \`${row.fallback_kind}\``, `- Classification: \`${row.classification}\``, `- Fixtures: ${row.fixtures.join(', ') || '(none)'}`, `- Reason: ${row.impossible_in_core_reason}`);
    if (row.example?.selector || row.example?.source_snippet) {
      lines.push(`- Example selector/snippet: \`${String(row.example.selector || row.example.source_snippet).replace(/`/g, '\\`').slice(0, 180)}\``);
    }
    lines.push('');
  }
  return `${lines.join('\n')}\n`;
}

function classifyFindingPattern(finding) {
  const explicitKey = slug(finding.pattern_key || finding.patternKey || finding.reason_code || finding.reasonCode);
  const explicit = explicitKey ? PATTERNS.find((item) => item.pattern_key === explicitKey) : null;
  if (explicit) {
    return explicit;
  }
  const diagnostic = diagnosticPattern(finding);
  if (diagnostic) {
    return diagnostic;
  }
  const haystack = findingHaystack(finding);
  for (const item of PATTERNS) {
    if (item.match.test(haystack)) {
      return item;
    }
  }
  if (finding.observed_block_name === 'core/html' || finding.loss_class === 'unsupported_loss' || /core\/html/i.test(haystack)) {
    return pattern(fallbackPatternKey(finding), 'Generic core/html fallback that needs a more specific transformer reason code.', 'core_html', finding.reason || 'The transformer emitted core/html or unsupported fallback without a stable incompatibility pattern.', 'convertible', false, /$a/);
  }
  if (finding.loss_class === 'preserved_runtime_island') {
    return PATTERNS.find((item) => item.pattern_key === 'legitimate-runtime-island');
  }
  if (finding.loss_class === 'visual_parity_mismatch' && finding.loss_acceptance === 'unacceptable') {
    return pattern(`visual-${slug(finding.reason_code || finding.pattern_family || 'fidelity-loss')}`, 'Visual parity loss from structured visual-diff classification.', 'fidelity_loss', finding.reason || 'Visual diff classification found a parity loss after block conversion.', 'convertible', false, /$a/);
  }
  return null;
}

function diagnosticPattern(finding) {
  const keys = [
    finding.reason_code,
    finding.reasonCode,
    finding.pattern_family,
    finding.patternFamily,
    finding.repair_bucket,
    finding.repairBucket,
    finding.diagnostic_code,
    finding.diagnosticCode,
    finding.kind,
  ].map(slug).filter(Boolean);
  for (const key of keys) {
    const mapped = DIAGNOSTIC_PATTERN_KEYS[key];
    if (mapped) {
      return PATTERNS.find((item) => item.pattern_key === mapped);
    }
  }
  return null;
}

const DIAGNOSTIC_PATTERN_KEYS = {
  html_form_fallback: 'static-form',
  form_requires_runtime: 'static-form',
  interactive_form: 'static-form',
  preserve_form_markup_or_replace_with_form_block_integration: 'static-form',
  html_product_grid_fallback: 'js-commerce-controls',
  commerce_product_grid: 'js-commerce-controls',
  commerce_product_grid_detected: 'js-commerce-controls',
  interactive_control_behavior_lost: 'js-commerce-controls',
  interactive_control: 'js-commerce-controls',
  restore_interactive_behavior: 'js-commerce-controls',
  html_inline_svg_fallback: 'inline-svg-filter-gradient',
  html_unsafe_inline_svg: 'inline-svg-filter-gradient',
  inline_svg: 'inline-svg-filter-gradient',
  materialize_static_asset: 'inline-svg-filter-gradient',
};

function findingHaystack(finding) {
  return [finding.pattern_key, finding.patternKey, finding.kind, finding.category, finding.group_key, finding.repair_bucket, finding.reason_code, finding.reasonCode, finding.pattern_family, finding.patternFamily, finding.reason, finding.selector, finding.selector_family, finding.source_path, finding.source_snippet, finding.observed_output, finding.observed_block_name, finding.loss_class].filter(Boolean).join(' ');
}

function fallbackPatternKey(finding) {
  if (finding.reason_code || finding.reasonCode) {
    return slug(finding.reason_code || finding.reasonCode);
  }
  if (finding.pattern_family) {
    return slug(finding.pattern_family);
  }
  if (finding.selector_family && finding.selector_family !== '(none)') {
    return `core-html-${slug(finding.selector_family)}`;
  }
  return 'core-html-unspecified';
}

function fallbackKindForFinding(finding, patternDefinition) {
  if (finding.observed_block_name === 'core/html' || /core\/html/i.test(finding.reason || finding.observed_output || '')) {
    return 'core_html';
  }
  if (finding.loss_class === 'preserved_runtime_island') {
    return 'runtime_island';
  }
  if (finding.loss_class === 'missing_asset' && /data:image/i.test(finding.source_snippet || finding.reason || '')) {
    return 'data_uri';
  }
  if (finding.loss_class === 'visual_parity_mismatch' || finding.loss_class === 'editor_block_invalid') {
    return 'fidelity_loss';
  }
  return patternDefinition.fallback_kind;
}

function addVisualDiffEvidence(rows, fixture) {
  for (const region of normalizeArray(fixture.visual_diff_regions || fixture.visualDiffRegions)) {
    const cause = region.dominant_cause || region.cause;
    if (!cause) {
      continue;
    }
    const definition = pattern(`visual-${slug(cause)}`, `Visual diff region classified as ${cause}.`, 'fidelity_loss', `Visual diff classifier attributed mismatch pixels to ${cause}; inspect selector evidence to decide whether this is transformer-convertible or a missing block capability.`, 'convertible', false, /$a/);
    addEvidence(rows, definition, { fixture_id: fixture.fixture_id, fallback_kind: 'fidelity_loss', impact: numberValue(region.pixel_count), selector: region.mapped_selector || region.selector, reason: definition.impossible_in_core_reason, signal: 'visual_diff_classification' });
  }
}

function addEditorRenderDivergenceEvidence(rows, fixture) {
  const definition = PATTERNS.find((item) => item.pattern_key === 'editor-render-divergence');
  for (const divergence of normalizeArray(fixture.editor_render_divergence || fixture.editorRenderDivergence || fixture.editor_render_divergences || fixture.editorRenderDivergences)) {
    const row = objectValue(divergence);
    addEvidence(rows, definition, { fixture_id: fixture.fixture_id, fallback_kind: 'fidelity_loss', impact: numberValue(row.impact || row.pixel_count || row.count || 1), selector: row.selector, source_snippet: row.source_snippet || row.sourceSnippet, reason: row.reason || row.message || definition.impossible_in_core_reason, signal: 'editor_render_divergence' });
  }
}

function addCoreHtmlCompositionEvidence(rows, fixture) {
  const blockTypes = objectValue(fixture.block_composition?.block_types || fixture.blockComposition?.blockTypes);
  const count = numberValue(blockTypes['core/html'] || fixture.editor_quality?.core_html_block_count);
  if (count <= 0) {
    return;
  }
  const definition = pattern('core-html-unspecified', 'Fixture contains core/html fallback blocks without structured transformer reason diagnostics.', 'core_html', 'Block composition counted core/html fallback blocks but no stable source pattern was emitted; add transformer reason codes for attribution.', 'convertible', false, /$a/);
  addEvidence(rows, definition, { fixture_id: fixture.fixture_id, fallback_kind: 'core_html', impact: count, reason: definition.impossible_in_core_reason, signal: 'block_composition_core_html' });
}

function addEvidence(rows, definition, evidence) {
  if (!definition || !evidence.fixture_id) {
    return;
  }
  const row = rows.get(definition.pattern_key) || { ...definition, fallback_kinds: {}, fixtures: [], fixture_counts: {}, selectors: [], source_paths: [], signals: {}, examples: [], finding_count: 0, impact_score: 0 };
  row.finding_count += 1;
  row.impact_score += Math.max(1, numberValue(evidence.impact || 1));
  row.fallback_kinds[evidence.fallback_kind || definition.fallback_kind] = (row.fallback_kinds[evidence.fallback_kind || definition.fallback_kind] || 0) + 1;
  row.signals[evidence.signal || 'finding'] = (row.signals[evidence.signal || 'finding'] || 0) + 1;
  row.fixture_counts[evidence.fixture_id] = (row.fixture_counts[evidence.fixture_id] || 0) + 1;
  pushUnique(row.fixtures, evidence.fixture_id, 100);
  pushUnique(row.selectors, evidence.selector, 10);
  pushUnique(row.source_paths, evidence.source_path, 10);
  if (row.examples.length < 3) {
    row.examples.push(compactObject({ fixture_id: evidence.fixture_id, selector: evidence.selector, source_path: evidence.source_path, source_snippet: evidence.source_snippet, reason: evidence.reason, observed_block_name: evidence.observed_block_name, signal: evidence.signal }));
  }
  rows.set(definition.pattern_key, row);
}

function finalizeRow(row, threshold) {
  const fixtureCount = row.fixtures.length;
  const classification = row.base_classification === 'runtime-island' ? 'runtime-island' : row.no_core_block_path && fixtureCount >= threshold ? 'custom-block-candidate' : row.base_classification === 'custom-block-candidate' ? 'convertible' : row.base_classification;
  return compactObject({
    pattern_key: row.pattern_key,
    description: row.description,
    fallback_kind: dominantKey(row.fallback_kinds) || row.fallback_kind,
    fallback_kinds: sortCountObject(row.fallback_kinds),
    impossible_in_core_reason: row.impossible_in_core_reason,
    classification,
    no_core_block_path: row.no_core_block_path,
    fixture_count: fixtureCount,
    finding_count: row.finding_count,
    impact_score: Number(row.impact_score.toFixed(2)),
    fixtures: [...row.fixtures].sort(),
    fixture_counts: sortCountObject(row.fixture_counts),
    selectors: row.selectors,
    source_paths: row.source_paths,
    signals: sortCountObject(row.signals),
    example: row.examples[0] ? boundBlob(row.examples[0]) : undefined,
    examples: boundBlob(row.examples),
  });
}

function sortRows(left, right) {
  return classificationRank(left.classification) - classificationRank(right.classification) || right.fixture_count - left.fixture_count || right.impact_score - left.impact_score || right.finding_count - left.finding_count || left.pattern_key.localeCompare(right.pattern_key);
}

function classificationRank(value) {
  return value === 'custom-block-candidate' ? 0 : value === 'convertible' ? 1 : 2;
}

function sortCountObject(value) {
  return Object.fromEntries(Object.entries(value || {}).sort((left, right) => right[1] - left[1] || left[0].localeCompare(right[0])));
}

function dominantKey(value) {
  return Object.entries(sortCountObject(value))[0]?.[0] || '';
}

function positiveInteger(value, fallback) {
  const parsed = Math.floor(Number(value));
  return parsed > 0 ? parsed : fallback;
}

function table(value) {
  return String(value || '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');
}
