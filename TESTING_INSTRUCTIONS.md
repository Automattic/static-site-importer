# Testing instructions for #732

## What changed

A valid compiler font plan that exceeds SSI's fixed Google Fonts byte caps (CSS > 256 KiB or aggregate woff2 > 4 MiB) used to hard-abort the entire import. Now the import falls back to a preserved `@import` of the original Google stylesheet and continues, with a diagnostic that reports the exact cap reason and observed bytes.

## Install

1. Back up your existing `wp-content/plugins/static-site-importer/` directory.
2. Unzip `static-site-importer-fix-732.zip` over your WordPress install so the new files land at `wp-content/plugins/static-site-importer/`.
3. Activate (or reactivate) **Static Site Importer** from **Plugins**.
4. Make sure the **Blocks Engine** PHP transformer dependency is installed (`composer install` from the plugin root if not already).

## Test 1: standalone smoke (headless)

From the plugin root:

```bash
php tests/smoke-google-fonts-cap-fallback.php
php tests/smoke-webfont-producer-consumer.php
```

The first new smoke covers CSS-too-large and aggregate-too-large fallbacks. The second is the producer-path regression sentinel and must still pass.

## Test 2: full fast lane

```bash
npm test
npm run test:inventory
```

A pre-existing failure in `tests/smoke-url-batch-import.php` is unrelated to this fix.

## Test 3: end-to-end repro (per issue)

The issue repro is the fixture matrix against `29-multilingual-i18n`. The expected behavior changes:

```sh
node tools/run-fixture-matrix.mjs --local --static-site-importer <path-to-this-plugin> --blocks-engine <path-to-blocks-engine> --target-fixture 29-multilingual-i18n --surface-coverage 7 --wp-codebox-bin <path-to-wp-codebox/bin/wp-codebox-source.mjs> --allow-stale-override --skip-install --skip-sync
```

What to look for:

- The matrix output must NOT contain `static_site_importer_font_materialization_failed`.
- The static front page is created.
- Browser surfaces (Chrome/SVG parity surfaces) render text using the Noto Sans JP / Noto Naskh Arabic families.
- The Homeboy evidence run shows a `font_materialization_partial_preserved` diagnostic with one of these inner reasons: `google_fonts_stylesheet_preserved_due_to_size` (CSS > 256 KiB) or `google_fonts_payloads_partial_preserved` (aggregate woff2 > 4 MiB). The diagnostic's `details.observed_bytes`, `details.limit_bytes`, and `details.url` fields should be populated.

## Test 4: happy path regression

Run a small Google Fonts fixture (1 family, 1 weight) through any normal import path. Embedded font asset should still be downloaded and embedded as a `data:font/woff2;base64,…` URL inside `assets/css/embedded-fonts.css`. No `font_materialization_partial_preserved` diagnostic should appear.

## Rollback

If something goes wrong, deactivate the plugin and restore the backup from step 1 of Install.

## Files touched in this fix

- `includes/class-static-site-importer-font-materializer.php` — return shape change in `resolve_google_font_faces()` and `embed_font_sources()`; new preservation branch in `prepare_overlay()`; new `diagnostic_with_detail()` helper
- `tests/smoke-google-fonts-cap-fallback.php` — new standalone PHP smoke
- `test-manifest.json`, `homeboy-test-manifest.json` — registered the new smoke

Producer path (`materialize_producer_faces`) is untouched.
