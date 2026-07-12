# Gutenberg Incompatibility Registry

The fixture matrix emits `gutenberg-incompatibility-registry.json` and `gutenberg-incompatibility-registry.md` for every run. The JSON artifact is the deterministic decision engine for identifying recurring HTML/CSS/runtime patterns where WordPress core blocks cannot preserve source parity without `core/html`, a data URI fallback, a runtime island, or measurable fidelity loss.

Schema: `registry/gutenberg-incompatibility-registry.schema.json`

## Pattern Record

Each pattern row contains:

- `pattern_key`: stable generic taxonomy key, never a fixture-specific key.
- `description`: human description of the source architecture.
- `fallback_kind`: one of `core_html`, `data_uri`, `runtime_island`, or `fidelity_loss`.
- `impossible_in_core_reason`: concrete reason core blocks cannot represent the structure, behavior, or visual exactly.
- `classification`: `convertible`, `custom-block-candidate`, or `runtime-island`.
- `limitation_type`: decision-axis bucket: `real_gutenberg_gap`, `transformer_gap`, `intentional_runtime_preservation`, `visual_only_style_drift`, or `editor_validity_risk`.
- `no_core_block_path`: whether existing core block semantics have no direct path to parity.
- `fixture_count`, `fixtures`, `fixture_counts`: recurrence evidence across fixtures.
- `finding_count`, `signals`, `fallback_kinds`, `impact_score`: aggregate impact from normalized matrix findings, visual diff regions, block composition, and future editor render divergence signals.
- `example` / `examples`: bounded source selector/snippet/reason evidence.

## Promotion Rule

Default threshold: `2` distinct fixtures.

A pattern is promoted to `custom-block-candidate` when `no_core_block_path` is true and the pattern appears in at least the threshold number of distinct fixtures. Runtime-island patterns stay `runtime-island` even when recurring. Patterns with a plausible core-block or transformer path stay `convertible` until evidence proves core cannot express them.

## Fixture Decisions

The registry also emits `fixture_decisions[]` so acceptance decisions do not require re-reading pattern evidence by hand:

- `summary.fixture_decision_counts`: deterministic counts by `acceptance_status`.
- `summary.fixture_decision_groups`: deterministic, sorted fixture IDs by `acceptance_status`; use this for quick review of solved candidates and blocker cohorts before drilling into the full decision table.
- `editor_validity_status`: `valid`, `invalid_blocks`, or `not_validated`; this is the corrupt/invalid block risk axis.
- `native_editability_status`: `native_editable`, `editor_invalid`, `custom_block_candidate`, `runtime_island_preserved`, `html_islands_or_transformer_gap`, or `unknown`.
- `visible_html_island_count`: visible `core/html` island pressure from block composition and findings.
- `gutenberg_gap_patterns`: real Gutenberg/custom-block candidate gaps affecting that fixture.
- `transformer_gap_patterns`: fallback or attribution gaps that should be fixed in SSI/Blocks Engine before calling something a Gutenberg limitation.
- `intentional_runtime_patterns`: runtime islands preserved by design.
- `visual_only_patterns`: frontend visual drift patterns that do not imply invalid blocks or lost editability by themselves.
- `solved_candidate_reason`: present only when the fixture passed, editor validation passed, no HTML/runtime islands remain, and no registry limitation pattern is attached.
- `acceptance_status`: `solved_candidate`, `visual_only_blocker`, `editor_blocker`, `native_editability_blocker`, `provider_runtime_blocker`, or `evidence_gap`. Missing frontend/editor/block-validity evidence is `evidence_gap`; `provider_runtime_blocker` is reserved for failures where the provider/runtime blocked evidence capture.

## Seeded Generic Pattern Keys

- `static-form`: newsletter/contact/static form markup with fields, submission semantics, validation, and response behavior. Core has no generic form block.
- `js-commerce-controls`: quantity steppers, add-to-cart controls, cart counters, product option state, and commerce mutation behavior. Core blocks do not provide purchase-control semantics.
- `inline-svg-filter-gradient`: inline SVG DOM with `defs`, filters, masks, clip paths, gradients, symbols, or data-URI SVG preservation. Core image/media blocks cannot preserve arbitrary editable SVG DOM graphs.
- `css-grid-masonry`: masonry/dense grid layouts that require source-order-independent packing or arbitrary grid placement semantics.
- `position-sticky-nav`: sticky/fixed navigation or header behavior coupled to scroll state or offsets. This is initially `convertible` because simple sticky layout can be approximated by core layout/navigation blocks.
- `editor-render-divergence`: future editor-fidelity signal for frontend/editor render divergence.
- `legitimate-runtime-island`: expected runtime behavior that is intentionally preserved rather than converted into static editable attributes.

## Existing Signal Inputs

- Normalized finding packets from `lib/fixture-matrix/findings.mjs`, including `loss_class`, `reason_code`, `pattern_family`, `selector`, `source_snippet`, and `observed_block_name`.
- Core HTML block composition counts from `lib/fixture-matrix/collectors/quality-metrics.mjs` via `block_composition` / `editor_quality.core_html_block_count`.
- Visual diff region causes from `visual_diff_regions` / `visual-diff-classification.json`.
- Future editor-fidelity lane rows named `editor_render_divergence` or `editor_render_divergences` on a fixture result.

## Current Strong Custom-Block Candidate Shapes

- Static form: `<form>` architecture with input/select/textarea controls, submit buttons, labels, hidden fields, validation/response state, and newsletter/contact semantics. This should become a generic form block candidate when recurrence crosses threshold.
- JS commerce controls: product purchase controls containing quantity inputs/steppers, add-to-cart buttons, option selectors, price/cart state, and runtime mutation. This should become a commerce control block candidate when recurrence crosses threshold.
- SVG filter/gradient artwork: inline SVGs with defs/filter/gradient/mask/clip-path graphs or SVG data URIs whose DOM/ID graph must survive exactly. This should become an SVG artwork block candidate when recurrence crosses threshold.

See `docs/companion-block-strategy.md` for the typed companion block contracts,
runtime ownership boundaries, provider integration rules, and recommended build
order for these candidates.
