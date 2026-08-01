# Testing Instructions: Restore repo-wide lint gate (#750)

## What changed

1. **PHPCS formatting auto-fix** across `includes/` and `static-site-importer.php` (whitespace, array alignment, spacing only — verified `php -l` clean and isolated semantic diff).
2. **Integration boundary fix**: dropped the dead second argument to `ArtifactCompiler::compile()` in `includes/class-static-site-importer-theme-generator.php:93`. The tagged transformer (0.4.10) accepts one argument; the option array (`include_conversion_report`) was discarded. Conversion reports still emit unconditionally in `source_reports`. The public `compiler_options` import field is kept for backward compatibility but no longer forwarded.
3. **PHPStan baseline cleanup**: removed the stale `class-static-site-importer-transformer-adapter.php` entry (file no longer exists).
4. **Easy PHPCS wins**: replaced short ternaries, precomputed a `count()` loop bound, and annotated one intentional empty catch.
5. **CI lint gate restored**: `.github/workflows/test.yml` now sets the homeboy-action `commands` input to `review lint,test` instead of `review test`, so the lint gate runs on every PR/push alongside tests.

## Requirements

- PHP 8.1+ (composer `platform.php` is 8.2.0)
- Node 24 for `npm test`
- PHPCS with WordPress Coding Standards 3.x (`WordPress-Extra` standard) for manual phpcs checks

## Automated tests

```bash
npm test                 # fast lane: standalone PHP + node tests
php tests/smoke-wordpress-site-plan-materializer.php   # exercises compile() path
php tests/smoke-website-artifact-import-input.php      # exercises compiler_options API surface
php tests/smoke-webfont-producer-consumer.php          # font materializer (edited)
```

All should pass. Note: fixture-matrix reports 2 pre-existing failures (`failing-fixture` / `large-output-fixture` missing manifest) that reproduce on clean `main` and are unrelated to this change.

## Lint checks

```bash
# PHPCS (WordPress-Extra standard) — should be 74 errors / 11 files remaining, all pre-existing debt (Yoda conditions, missing docblocks, DOM snake_case names, escaping). None in lines changed by this PR.
phpcs --standard=WordPress-Extra includes/ static-site-importer.php

# PHP syntax sanity — exits on first failure
for f in static-site-importer.php includes/*.php; do php -l "$f" || exit 1; done
```

## Manual verification

1. Import a website artifact via `wp static-site-importer import-url <url>` (or UI block) and confirm the import report still includes the conversion report section. The transformer emits it unconditionally; this PR only removed the dead option forwarding.
2. Confirm no fatal on `php -l` for all edited files.
3. CI: push branch, confirm the matrix-generated `Homeboy Test (PHP 8.1)`, `Homeboy Test (PHP 8.2)`, `Homeboy Test (PHP 8.3)`, and `Homeboy Test (PHP 8.4)` checks now include the lint stage and pass (branch protection owner adds them to the required list).

## Known remaining debt (follow-up)

- 74 WPCS errors remain across 11 files (Yoda conditions, missing param/return docblocks, DOM camelCase property names, unescaped output). Cleaning these is a separate lint-debt PR; this change restores the gate so new debt stops accumulating.
