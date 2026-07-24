# Product Handoff Contract

Static Site Importer's product handoff uses four machine-readable envelopes. The JSON fixture at `tests/fixtures/product-handoff-contract/v1.json` is the canonical contract sample used by tests.

## Stages

1. Product caller provides an input artifact with schema `blocks-engine/php-transformer/site-artifact/v1`.
2. Blocks Engine compiles it and returns `blocks-engine/php-transformer/result/v1` with `source_reports.wordpress_site_plan` using `blocks-engine/wordpress-site-plan/v2`.
3. SSI validates its destination, reports, caller overrides, and typed runtime declarations before mutation; it prepares declared dependencies, applies the canonical WordPress site plan, seeds declared entities, then writes report projections. The materializer receipt (`static-site-importer/materialization-receipt/v1`) is the mutation boundary and records completed pages, files, operations, and runtime declarations for partial results.
4. Codebox may validate the WordPress result and return `wp-codebox/validation-artifact-envelope/v1` with artifact references for rendered output, visual comparison, WordPress state, import report, and diagnostics.

## Ownership

- Blocks Engine owns static artifact compilation and the WordPressSitePlan v2. SSI consumes that canonical plan directly without compiled-site or materialization-plan v1 compatibility paths.
- SSI owns WordPress writes, page provenance post meta, the generated theme `static-site-importer-manifest.json`, and the import report.
- Codebox owns optional WordPress validation and artifact references.
- Product callers consume these outputs directly; they should not depend on legacy SSI wrapper history.

## Commerce Findings

When the canonical plan contains a Blocks Engine `html_product_grid_fallback`, SSI converts only its extracted product rows into a required `shop` dependency and `products` entity collection before the v2 lifecycle runs. Those declarations use the existing WooCommerce simple-product adapter and seeder. The finding does not itself provide canonical replacement-block anchors, so SSI does not infer cart-control bindings from selectors; provider bindings require explicit source anchors in the entity declaration.

## Boundary

Blocks Engine stays out of Codebox details. If a caller wants Codebox validation, it asks Codebox after SSI materializes WordPress and passes SSI output or runtime references through the Codebox-owned envelope.

## Source Of Truth

Every SSI import run has an `import_run_id`. When callers or compiler results provide artifact identity, SSI carries the artifact id/hash into the import report, page provenance meta, and generated theme manifest.

The source-of-truth manifest uses schema `static-site-importer/source-of-truth-manifest/v1` and records desired pages, files, and assets plus existing matched page targets when available. On overwrite/re-import, SSI may remove prior SSI-generated theme files/assets that appeared in the previous manifest and are absent from the current desired manifest. Unknown user files survive, and WordPress page deletion remains disabled until a later reviewed page reconciliation policy is added.
