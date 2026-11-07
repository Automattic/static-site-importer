# Block theme scorecard — phase 1

Issue: https://github.com/Automattic/static-site-importer/issues/2120 (including the Blackcat and dgti.uerj.br measurements).

Every import report carries `block_theme_scorecard`. After materialization the diagnostic projection measures the generated theme and reads persisted route posts, using WordPress `parse_blocks`. The focused measurement class is read-only; callers supply source CSS, distinct-menu and existing editability/design-system evidence. A missing theme/parser is explicitly `not_measured`, never a synthetic pass.

Nine check groups carry `measured`, `pass_threshold` and `status` (`pass`, `fail`, `not_measured`). The owner-handoff report card includes a table of the signals without changing its acceptance dimensions or quality gate. Thresholds are provisional, intentionally visible and not import admission policy. Gating comes in phase 2 after a fixture baseline.

Example section (one check shown):

```json
{
  "block_theme_scorecard": {
    "schema": "static-site-importer/block-theme-scorecard/v1",
    "gating": false,
    "checks": {
      "template_economy": {
        "measured": {
          "custom_template_count": 3,
          "distinct_template_structures": 2,
          "distinct_custom_template_structures": 1,
          "repeated_block_markup_bytes": 208
        },
        "pass_threshold": {
          "custom_templates_max": 1,
          "repeated_block_markup_bytes_max": 0
        },
        "status": "fail"
      }
    }
  }
}
```

Template fingerprints retain block names, hierarchy, layout type and tag name, ignoring text, IDs and other attributes. Repeated bytes count surplus copies of identical serialized subtrees across templates (largest repeated subtrees first), excluding template-part/post-content references and whitespace. This avoids nested double counting. Navigation-shaped button rows are a heuristic (three or more children in `core/buttons`). Source CSS bytes reuse Blocks Engine's `unique_source_css_bytes`; unavailable source totals produce `not_measured` asset proportions rather than a zero denominator. Content totals sum metrics, including depth; worst-route depth is the meaningful depth threshold.

AI disclosure: Claude Opus via Pi orchestrated and reviewed; a Homeboy Cook agent implemented.
