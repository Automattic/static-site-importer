# Quality Admission v1

`static-site-importer/quality-admission/v1` is an additive materialization receipt and import-report field. It separates `mechanical_status` (WordPress writes completed) from `production_ready` (`passed`, `hard_budget_failed`, `evidence_failed`, or `unknown`).

Callers configure it with `quality_admission`:

```php
array(
    'mode' => 'production_ready', // evidence (default), preview, or production_ready.
    'budgets' => array(
        'max_raw_html_fallback_count' => 0,
        'max_unresolved_media_count' => 0,
        'max_unresolved_dependency_count' => 0,
        'max_theme_bootstrap_bytes' => 250000,
        'max_stylesheet_asset_count' => 20,
    ),
)
```

Budgets are opt-in hard limits. `preview` preserves materialized output and reports the production decision for inspection. Existing callers without canonical quality evidence receive `unknown`; SSI never infers visual or editor parity from successful writes. Metrics use the canonical resolved plan and finalized report only: native blocks, raw HTML fallback counts/families, unresolved media/dependencies, bootstrap bytes, stylesheet assets, and supplied visual/editor evidence. Compiler repairs remain owned upstream by Blocks Engine.
