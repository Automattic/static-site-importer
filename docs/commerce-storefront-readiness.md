# Storefront readiness report

Imports with a WooCommerce adapter add `commerce.storefront` to the conversion
report persisted as `import-report.json`. Other imports retain an empty commerce
section. This is an inspect-only snapshot at final report composition, after
entity and page materialization; it does not configure gateways or create a cart,
session, order, or payment. No remote verification is performed by SSI.

Contract: `schema: static-site-importer/commerce-storefront/v1`.

- `product_count`: unique successful product IDs in the import's Woo reports,
  not the store-wide product inventory or variation count.
- `binding_count`: completed Woo binding receipts (block or classic), not the
  number of products inside a bound grid.
- `cart` and `checkout`: Woo page-option `page_id` and `exists`, true only for
  an existing published WordPress page. `checkout_available` mirrors checkout
  existence; it does not prove logged-out reachability or payment collection.
- `payment.api_available`: Woo API presence; `gateways` lists registered gateway
  IDs with `available`, `enabled`, `usable` and nullable boolean `test_mode`.
  `usable` requires enabled plus `is_available()` in the current import context.
  `payment_ready` mirrors `payment.ready`: at least one usable gateway, including
  a test gateway. Unknown test mode is null, never inferred as live. The standard
  `testmode` option/property is recognized; provider-specific modes are unknown.
- `shipping.zone_count` includes the implicit rest-of-world zone (0).
  `has_enabled_method` checks enabled methods in any zone; it does not claim an
  address-specific rate. `api_available` and `inspection_failed` distinguish
  unavailable APIs and inspection errors from an empty configuration.
- `missing_operator_configuration`: stable action keys `cart_page`,
  `checkout_page`, `usable_payment_gateway`, `shipping_method`, when absent.
  Shipping is informational: virtual-only catalogs may not need it. It does not
  gate payment readiness. Exceptions fail closed and their text is never emitted.

Only gateway identifiers and readiness booleans are serialized, never settings,
objects, credentials, titles, account connections or exception text. Availability
is a context-dependent provider API signal, not an independent audit of accounts,
credentials, taxes, delivery eligibility, or a successful payment.

## Before / after

Before: the conversion report has no `commerce.storefront` readiness result.
After, with published Woo pages but no gateway (excerpt):

```json
{"commerce":{"storefront":{"product_count":1,"binding_count":1,"checkout_available":true,"payment_ready":false,"payment":{"api_available":true,"gateways":[],"ready":false,"inspection_failed":false},"missing_operator_configuration":["usable_payment_gateway","shipping_method"]}}}
```

With an enabled usable test gateway and an enabled shipping method (excerpt):

```json
{"commerce":{"storefront":{"checkout_available":true,"payment_ready":true,"payment":{"api_available":true,"gateways":[{"id":"fixture","available":true,"enabled":true,"usable":true,"test_mode":true}],"ready":true,"inspection_failed":false},"missing_operator_configuration":[]}}}
```

## Validation performed

The readiness smoke uses minimal Woo API stand-ins, not a bootstrapped WooCommerce
installation. It exercises the real conversion-report composition and JSON
boundary for both readiness states, disabled/unusable gateways, provider errors,
missing pages, shipping zone 0 and credential exclusion. Against main's receipt
projection it fails with `conversion report exposes commerce.storefront`.

Commands run successfully:

```sh
export STATIC_SITE_IMPORTER_WP_ROOT=/tmp/ssi-outcome-wordpress
composer install --no-interaction --quiet && php tests/smoke-product-materializer.php
php tests/smoke-woo-storefront-readiness.php
node tools/run-test-manifest.mjs --check
node tools/run-test-manifest.mjs
homeboy --placement local review lint static-site-importer --path .
```

The product smoke's existing Woo mock needed the missing `update_meta_data()`
method to satisfy the seeder's existing source-route metadata call. Its assertions
are unchanged. An older local WordPress source fixture failed its existing
backslash-escaping assertion; the commands above use a fixture implementing that
Core serialization behavior. No unrelated tests were modified.

AI disclosure: implemented and reviewed by AI model `cook-heavy` through Homeboy
Cook, including the readiness projection, smoke fixture, and documentation.
