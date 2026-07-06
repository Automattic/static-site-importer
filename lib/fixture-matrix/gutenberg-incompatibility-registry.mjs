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
  const findings = normalizeArray(result.findings);

  for (const finding of findings) {
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
  const fixtureDecisions = buildFixtureDecisions(fixtures, findings, patterns);
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
      fixture_decision_counts: countBy(fixtureDecisions, (row) => row.acceptance_status),
      fixture_decision_groups: groupFixtureDecisionsByAcceptance(fixtureDecisions),
      editor_validity_counts: countBy(fixtureDecisions, (row) => row.editor_validity_status),
      limitation_type_counts: countBy(patterns, (row) => row.limitation_type),
      top_patterns: patterns.slice(0, 10).map((row) => ({ pattern_key: row.pattern_key, classification: row.classification, fixture_count: row.fixture_count, finding_count: row.finding_count, impact_score: row.impact_score })),
    },
    fixture_decisions: fixtureDecisions,
    patterns,
  };
}

export function renderGutenbergIncompatibilityRegistryMarkdown(registry = {}) {
  const lines = [
    '# Gutenberg Incompatibility Registry',
    '',
    'Generated from Static Site Importer fixture-matrix findings. This records generic HTML/CSS/runtime patterns where core blocks cannot preserve source parity without a fallback, transformer fix, runtime island, or future custom block.',
    'Tracked no-core-block-path candidates remain `convertible` until they appear in enough distinct fixtures to satisfy the promotion rule; broader corpus runs are expected to promote recurring form/contact/commerce patterns once recurrence is observed.',
    '',
    `Schema: \`${registry.schema || GUTENBERG_INCOMPATIBILITY_REGISTRY_SCHEMA}\``,
    `Matrix: \`${registry.matrix_id || '(unknown)'}\``,
    `Promotion rule: ${registry.promotion_rule?.rule || ''}`,
    '',
    '| Rank | Pattern | Limitation Type | Classification | Fixtures | Findings | Impact | Reason |',
    '| ---: | --- | --- | --- | ---: | ---: | ---: | --- |',
  ];
  normalizeArray(registry.patterns).forEach((row, index) => {
    lines.push(`| ${index + 1} | \`${row.pattern_key}\` | ${row.limitation_type || '(unknown)'} | ${row.classification} | ${row.fixture_count} | ${row.finding_count} | ${row.impact_score} | ${table(row.impossible_in_core_reason)} |`);
  });
  const decisionGroups = objectValue(registry.summary?.fixture_decision_groups);
  if (Object.keys(decisionGroups).length > 0) {
    lines.push('', '## Fixture Decision Groups', '', '| Acceptance | Fixtures |', '| --- | --- |');
    for (const status of Object.keys(decisionGroups).sort()) {
      lines.push(`| ${status} | ${normalizeArray(decisionGroups[status]).map((fixtureId) => `\`${fixtureId}\``).join(', ') || '(none)'} |`);
    }
  }
  lines.push('', '## Fixture Decisions', '', '| Fixture | Acceptance | Frontend Visual | Editor Canvas | Block Validity | Native Editability | Runtime/HTML Islands | Gutenberg Gaps | Visual-only Patterns | Reason |', '| --- | --- | --- | --- | --- | --- | ---: | --- | --- | --- |');
  for (const row of normalizeArray(registry.fixture_decisions)) {
    lines.push(`| \`${row.fixture_id}\` | ${row.acceptance_status || '(unknown)'} | ${row.frontend_visual_status || '(unknown)'} | ${row.editor_canvas_status || '(unknown)'} | ${row.block_validity_status || row.editor_validity_status || '(unknown)'} | ${row.native_editability_status} | ${row.visible_runtime_or_html_islands || 0} | ${(row.gutenberg_gap_patterns || []).map((key) => `\`${key}\``).join(', ') || '(none)'} | ${(row.visual_only_patterns || []).map((key) => `\`${key}\``).join(', ') || '(none)'} | ${table(row.solved_candidate_reason || row.acceptance_reason || '') || '(none)'} |`);
  }
  lines.push('', '## Pattern Evidence', '');
  for (const row of normalizeArray(registry.patterns)) {
    lines.push(`### ${row.pattern_key}`, '', `- Description: ${row.description}`, `- Limitation type: \`${row.limitation_type || '(unknown)'}\``, `- Fallback kind: \`${row.fallback_kind}\``, `- Classification: \`${row.classification}\``, `- Fixtures: ${row.fixtures.join(', ') || '(none)'}`, `- Reason: ${row.impossible_in_core_reason}`);
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
  const fallbackKind = dominantKey(row.fallback_kinds) || row.fallback_kind;
  return compactObject({
    pattern_key: row.pattern_key,
    description: row.description,
    fallback_kind: fallbackKind,
    fallback_kinds: sortCountObject(row.fallback_kinds),
    impossible_in_core_reason: row.impossible_in_core_reason,
    classification,
    limitation_type: limitationType({ ...row, classification, fallback_kind: fallbackKind }),
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

function buildFixtureDecisions(fixtures, findings, patterns) {
  const patternsByFixture = new Map();
  for (const row of patterns) {
    for (const fixtureId of normalizeArray(row.fixtures)) {
      patternsByFixture.set(fixtureId, [...(patternsByFixture.get(fixtureId) || []), row]);
    }
  }
  const findingsByFixture = new Map();
  for (const finding of findings) {
    const fixtureId = finding.fixture_id || '';
    findingsByFixture.set(fixtureId, [...(findingsByFixture.get(fixtureId) || []), finding]);
  }
  return fixtures.map((fixture) => {
    const fixtureId = fixture.fixture_id || fixture.id || '';
    return fixtureDecision(fixture, findingsByFixture.get(fixtureId) || [], patternsByFixture.get(fixtureId) || []);
  });
}

function fixtureDecision(fixture, fixtureFindings, fixturePatterns) {
  const editorInvalidCount = editorInvalidCountForFixture(fixture, fixtureFindings);
  const editorValidated = editorValidatedForFixture(fixture);
  const visibleHtmlIslandCount = visibleHtmlIslandCountForFixture(fixture, fixtureFindings);
  const runtimeIslandCount = fixtureFindings.filter((finding) => finding.loss_class === 'preserved_runtime_island').length;
  const gutenbergGapPatterns = uniqueSorted(fixturePatterns.filter((row) => row.limitation_type === 'real_gutenberg_gap').map((row) => row.pattern_key));
  const transformerGapPatterns = uniqueSorted(fixturePatterns.filter((row) => row.limitation_type === 'transformer_gap').map((row) => row.pattern_key));
  const visualOnlyPatterns = uniqueSorted(fixturePatterns.filter((row) => row.limitation_type === 'visual_only_style_drift').map((row) => row.pattern_key));
  const runtimeIslandPatterns = uniqueSorted(fixturePatterns.filter((row) => row.limitation_type === 'intentional_runtime_preservation').map((row) => row.pattern_key));
  const editorRiskPatterns = uniqueSorted(fixturePatterns.filter((row) => row.limitation_type === 'editor_validity_risk').map((row) => row.pattern_key));
  const editorValidityStatus = editorInvalidCount > 0 ? 'invalid_blocks' : editorValidated ? 'valid' : 'not_validated';
  const frontendVisualStatus = frontendVisualStatusForFixture(fixture, fixtureFindings, visualOnlyPatterns);
  const editorCanvasStatus = editorCanvasStatusForFixture(fixture, fixtureFindings, editorRiskPatterns);
  const nativeEditabilityStatus = nativeEditabilityStatusForFixture({
    editorValidityStatus,
    visibleHtmlIslandCount,
    runtimeIslandCount,
    gutenbergGapPatterns,
    transformerGapPatterns,
  });
  const visibleRuntimeOrHtmlIslands = visibleHtmlIslandCount + runtimeIslandCount;
  const acceptance = acceptanceDecision({
    frontendVisualStatus,
    editorCanvasStatus,
    editorValidityStatus,
    nativeEditabilityStatus,
    visibleRuntimeOrHtmlIslands,
    gutenbergGapPatterns,
    transformerGapPatterns,
    visualOnlyPatterns,
    runtimeIslandPatterns,
    editorRiskPatterns,
  });
  return compactObject({
    fixture_id: fixture.fixture_id || fixture.id || '',
    frontend_visual_status: frontendVisualStatus,
    editor_canvas_status: editorCanvasStatus,
    block_validity_status: editorValidityStatus,
    editor_validity_status: editorValidityStatus,
    native_editability_status: nativeEditabilityStatus,
    visible_html_island_count: visibleHtmlIslandCount,
    runtime_island_count: runtimeIslandCount,
    visible_runtime_or_html_islands: visibleRuntimeOrHtmlIslands,
    gutenberg_gap_patterns: gutenbergGapPatterns,
    transformer_gap_patterns: transformerGapPatterns,
    intentional_runtime_patterns: runtimeIslandPatterns,
    visual_only_patterns: visualOnlyPatterns,
    editor_risk_patterns: editorRiskPatterns,
    solved_candidate: acceptance.solved_candidate,
    solved_candidate_reason: acceptance.reason,
    acceptance_status: acceptance.status,
    acceptance_reason: acceptance.reason,
  });
}

function nativeEditabilityStatusForFixture({ editorValidityStatus, visibleHtmlIslandCount, runtimeIslandCount, gutenbergGapPatterns, transformerGapPatterns }) {
  if (editorValidityStatus === 'invalid_blocks') {
    return 'editor_invalid';
  }
  if (gutenbergGapPatterns.length > 0) {
    return 'custom_block_candidate';
  }
  if (runtimeIslandCount > 0) {
    return 'runtime_island_preserved';
  }
  if (visibleHtmlIslandCount > 0 || transformerGapPatterns.length > 0) {
    return 'html_islands_or_transformer_gap';
  }
  if (editorValidityStatus === 'not_validated') {
    return 'unknown';
  }
  return 'native_editable';
}

function frontendVisualStatusForFixture(fixture, fixtureFindings, visualOnlyPatterns) {
  if (hasProviderRuntimeBlocker(fixtureFindings)) {
    return 'provider_runtime_blocked';
  }
  if (visualOnlyPatterns.length > 0 || fixtureFindings.some((finding) => finding.loss_class === 'visual_parity_mismatch' || finding.kind === 'visual_parity_mismatch')) {
    return 'visual_mismatch';
  }
  if (fixture.status === 'passed' || hasVisualEvidence(fixture)) {
    return 'passed';
  }
  return 'not_evaluated';
}

function editorCanvasStatusForFixture(fixture, fixtureFindings, editorRiskPatterns) {
  if (hasProviderRuntimeBlocker(fixtureFindings)) {
    return 'provider_runtime_blocked';
  }
  if (editorRiskPatterns.length > 0 || fixtureFindings.some((finding) => finding.kind === 'editor_render_divergence' || finding.loss_class === 'editor_render_divergence')) {
    return 'diverged';
  }
  if (hasEditorCanvasEvidence(fixture)) {
    return 'visible';
  }
  return 'not_captured';
}

function acceptanceDecision({ frontendVisualStatus, editorCanvasStatus, editorValidityStatus, nativeEditabilityStatus, visibleRuntimeOrHtmlIslands, gutenbergGapPatterns, transformerGapPatterns, visualOnlyPatterns, runtimeIslandPatterns, editorRiskPatterns }) {
  if (frontendVisualStatus === 'provider_runtime_blocked' || editorCanvasStatus === 'provider_runtime_blocked') {
    return { solved_candidate: false, status: 'provider_runtime_blocker', reason: 'required frontend visual or editor canvas evidence is blocked by the provider/runtime' };
  }
  if (frontendVisualStatus === 'not_evaluated' || editorCanvasStatus === 'not_captured' || editorValidityStatus === 'not_validated') {
    return { solved_candidate: false, status: 'evidence_gap', reason: 'required frontend visual, editor canvas, or block-validity evidence is missing' };
  }
  if (editorValidityStatus === 'invalid_blocks' || editorCanvasStatus === 'diverged' || editorRiskPatterns.length > 0) {
    return { solved_candidate: false, status: 'editor_blocker', reason: 'editor canvas or block validation evidence shows imported content is not reliably visible and valid in the editor' };
  }
  if (nativeEditabilityStatus !== 'native_editable' || visibleRuntimeOrHtmlIslands > 0 || gutenbergGapPatterns.length > 0 || transformerGapPatterns.length > 0 || runtimeIslandPatterns.length > 0) {
    return { solved_candidate: false, status: 'native_editability_blocker', reason: 'frontend-visible content still depends on runtime/html islands, transformer gaps, or a real Gutenberg capability gap' };
  }
  if (frontendVisualStatus === 'visual_mismatch' || visualOnlyPatterns.length > 0) {
    return { solved_candidate: false, status: 'visual_only_blocker', reason: 'native editor evidence is acceptable but frontend visual parity still differs from the source' };
  }
  return { solved_candidate: true, status: 'solved_candidate', reason: 'passed frontend visual parity, editor canvas evidence, block validity, and native editability without limitation patterns' };
}

function hasProviderRuntimeBlocker(fixtureFindings) {
  return fixtureFindings.some((finding) => ['runtime_execution_failed', 'visual_timeout', 'fixture_not_run', 'fixture_failed', 'visual_evidence_missing'].includes(finding.loss_class) || ['visual_timeout', 'fixture_not_run', 'fixture_failed'].includes(finding.kind));
}

function hasVisualEvidence(fixture) {
  return Object.keys(objectValue(fixture.visual_parity_artifacts || fixture.visualParityArtifacts || fixture.visual_diff_classification || fixture.visualDiffClassification)).length > 0
    || normalizeArray(fixture.visual_diff_regions || fixture.visualDiffRegions).length > 0;
}

function hasEditorCanvasEvidence(fixture) {
  return Object.keys(objectValue(fixture.editor_canvas || fixture.editorCanvas || fixture.editor_canvas_summary || fixture.editorCanvasSummary || fixture.editor_open || fixture.editorOpen)).length > 0
    || normalizeArray(fixture.artifact_refs || fixture.artifactRefs).some((ref) => /editor-(?:open|state|canvas|summary)|editor_open|editor_canvas/i.test(String(ref.path || ref.href || ref.artifact_id || ref.kind || '')));
}

function limitationType(row) {
  if (row.classification === 'runtime-island') {
    return 'intentional_runtime_preservation';
  }
  if (row.pattern_key === 'editor-render-divergence' || (row.fallback_kind === 'fidelity_loss' && !row.pattern_key.startsWith('visual-'))) {
    return 'editor_validity_risk';
  }
  if (row.pattern_key.startsWith('visual-')) {
    return 'visual_only_style_drift';
  }
  if (row.no_core_block_path) {
    return 'real_gutenberg_gap';
  }
  return 'transformer_gap';
}

function visibleHtmlIslandCountForFixture(fixture, fixtureFindings) {
  const blockTypes = objectValue(fixture.block_composition?.block_types || fixture.block_composition?.block_type_counts || fixture.blockComposition?.blockTypes || fixture.blockComposition?.blockTypeCounts);
  const blockCompositionCount = numberValue(blockTypes['core/html'] || fixture.block_composition?.core_html_block_count || fixture.blockComposition?.coreHtmlBlockCount || fixture.editor_quality?.core_html_block_count);
  const findingCount = fixtureFindings.filter((finding) => fallbackKindForFinding(finding, { fallback_kind: '' }) === 'core_html').length;
  return Math.max(blockCompositionCount, findingCount);
}

function editorInvalidCountForFixture(fixture, fixtureFindings) {
  return numberValue(fixture.editor_quality?.editor_invalid_count || fixture.editor_quality?.invalid_block_count || fixture.editor_validation?.invalid_block_count || fixture.editor_validation?.invalid_count)
    + fixtureFindings.filter((finding) => finding.loss_class === 'editor_block_invalid' || finding.loss_class === 'invalid_block_content').length;
}

function editorValidatedForFixture(fixture) {
  return numberValue(fixture.editor_quality?.editor_validated_block_total || fixture.editor_validation?.block_total || fixture.editor_validation?.total_block_count || fixture.editor_validation?.total) > 0;
}

function countBy(items, iteratee) {
  const counts = {};
  for (const item of normalizeArray(items)) {
    const key = iteratee(item) || 'unknown';
    counts[key] = (counts[key] || 0) + 1;
  }
  return sortCountObject(counts);
}

function groupFixtureDecisionsByAcceptance(fixtureDecisions) {
  const groups = {};
  for (const decision of normalizeArray(fixtureDecisions)) {
    const status = decision.acceptance_status || 'unknown';
    groups[status] = groups[status] || [];
    pushUnique(groups[status], decision.fixture_id || 'unknown');
  }
  return Object.fromEntries(Object.entries(groups).sort(([left], [right]) => left.localeCompare(right)).map(([status, fixtureIds]) => [status, [...fixtureIds].sort()]));
}

function uniqueSorted(values) {
  return [...new Set(values.filter(Boolean))].sort();
}

function sortRows(left, right) {
  return classificationRank(left.classification) - classificationRank(right.classification)
    || Number(Boolean(right.no_core_block_path)) - Number(Boolean(left.no_core_block_path))
    || right.fixture_count - left.fixture_count
    || patternRank(left.pattern_key) - patternRank(right.pattern_key)
    || right.impact_score - left.impact_score
    || right.finding_count - left.finding_count
    || left.pattern_key.localeCompare(right.pattern_key);
}

function classificationRank(value) {
  return value === 'custom-block-candidate' ? 0 : value === 'convertible' ? 1 : 2;
}

function patternRank(patternKey) {
  const index = PATTERNS.findIndex((item) => item.pattern_key === patternKey);
  return index === -1 ? PATTERNS.length : index;
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
