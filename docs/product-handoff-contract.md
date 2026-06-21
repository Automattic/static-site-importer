# Product Handoff Contract

Static Site Importer's product handoff uses four machine-readable envelopes. The JSON fixture at `tests/fixtures/product-handoff-contract/v1.json` is the canonical contract sample used by tests.

## Stages

1. Product caller provides an input artifact with schema `blocks-engine/php-transformer/site-artifact/v1`.
2. Blocks Engine compiles it and returns `blocks-engine/php-transformer/result/v1` with `source_reports.materialization_plan` using `blocks-engine/php-transformer/materialization-plan/v1`.
3. SSI consumes the materialization plan, writes WordPress pages/theme/assets, and returns an import report with nested `blocks-engine/import-validation-result/v1` and `blocks-engine/finding-packets/v1` artifacts.
4. Codebox may validate the WordPress result and return `wp-codebox/validation-artifact-envelope/v1` with artifact references for rendered output, visual comparison, WordPress state, import report, and diagnostics.

## Ownership

- Blocks Engine owns static artifact compilation and the materialization plan.
- SSI owns WordPress writes and the import report.
- Codebox owns optional WordPress validation and artifact references.
- WPSG, Data Machine, and Studio Native consume these outputs directly; they should not depend on legacy SSI wrapper history.

## Boundary

Blocks Engine stays out of Codebox details. If a caller wants Codebox validation, it asks Codebox after SSI materializes WordPress and passes SSI output or runtime references through the Codebox-owned envelope.
