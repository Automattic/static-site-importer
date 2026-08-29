# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Static Site Importer is a WordPress plugin that materializes static sites / website artifacts into WordPress pages and a companion block theme. It is the WordPress intake/materialization layer only: generic artifact compilation and format conversion belong to the **Blocks Engine PHP transformer** Composer dependency (`Automattic\BlocksEngine\PhpTransformer`). SSI consumes that package's `source_reports.wordpress_site_plan` (schema `blocks-engine/wordpress-site-plan/v2`), writes WordPress state, and returns a materialization receipt plus `static-site-importer/import-report/v1`. A `core/html` block in imported page content is a conversion quality bug to fix in this stack, not something to hide in the product layer.

Inputs: pasted HTML, one public HTML URL, direct `.html`/`.htm` upload, ZIP, or a `blocks-engine/php-transformer/site-artifact/v1` bundle. `.md`/`.markdown` files beside the entry are imported as pages; `.mdx` is skipped with explicit diagnostics.

## Commands

```bash
composer install        # PHP deps (Blocks Engine transformers, league/commonmark). vendor/ is required.
npm install             # Node tooling (fixture matrix, block validation smokes)
```

### Tests

The canonical test inventory is `test-manifest.json` (plus its standalone-PHP projection `homeboy-test-manifest.json`). Every test is classified `standalone-php` | `wordpress-runtime` | `node` | `browser-wp-codebox` | `operator-only`.

```bash
npm test                # Fast lane: standalone PHP + node tests
npm run test:all        # Full CI projection (runtime + browser lanes; operator-only reported, not run)
npm run test:inventory  # Verify manifest integrity / no undeclared executable tests
npm run test:runtime-package   # runtime-package-manifest contract + ability registration idempotence
```

Single tests:

```bash
php tests/smoke-site-identity.php      # standalone PHP smoke — stubs WP funcs itself, run from repo root
node --test tools/fixture-matrix.test.mjs
wp eval-file tests/smoke-wordpress-is-dead-fixture.php   # wordpress-runtime smokes
```

### Fixture matrix & validation

```bash
npm run test:fixture-matrix            # product-level importer quality gate
npm run test:fixture-matrix -- --help  # paths for blocks-engine corpus, fixtures, rig
npm run test:js-block-validation -- /path/to/wp-content/themes/<slug>   # Gutenberg parser/validator on generated theme
npm run test:validation                # full local harness: imports fixture into configured WP, runs PHP + JS smokes
```

The fixture matrix rig lives under `bench/`, `tools/`, `lib/fixture-matrix/` (composable modules; `lib/fixture-matrix.mjs` is a behavior-preserving facade), `tests/fixtures/`, and `rigs/`. Canonical site corpus lives in the Blocks Engine repo, not here — SSI keeps only minimal matrix smoke fixtures under `tests/fixtures/fixture-matrix`.

## Architecture

- `static-site-importer.php` — plugin bootstrap: defines constants, loads the two Blocks Engine transformer entry PHP files, `require_once`s every class in `includes/`, registers the block and REST routes, and (when `WP_CLI`) registers all `wp static-site-importer <cmd>` commands. No classes defined here, only the CLI closures.
- `includes/abilities.php` — **ability layer**, the primary programmatic + CLI entrypoint. Registers `static-site-importer/*` abilities (category `static-site-importer`): `import`, `materialize-wordpress-site-plan`, `validate-artifact`, `import-figma`, `export-theme`. CLI commands and REST wrap these same ability functions. When touching import behavior, normalize options here and let entrypoints project them (see recent commits on entrypoint parity).
- `includes/rest.php` — `wp-json/static-site-importer/*` routes (e.g. `import-website-artifact`), thin wrappers around abilities.
- `includes/block.php` + `blocks/importer/` — editor block (`static-site-importer/importer`, block.json v3), pure PHP render path, no JS framework.
- Materialization pipeline (in dependency order): `class-static-site-importer-document.php` (parse/split HTML), `class-static-site-importer-page-materializer.php` (largest; page body conversion + link rewriting), `class-static-site-importer-stylesheet-materializer.php`, `class-static-site-importer-entity-materializer-registry.php`, `class-static-site-importer-theme-generator.php` (block theme assembly), `class-static-site-importer-font-materializer.php`, `class-static-site-importer-woo-product-seeder.php` / `class-static-site-importer-form-seeder.php`, `class-static-site-importer-report-diagnostics.php` (largest file; import reports and finding packets).
- `class-static-site-importer-wordpress-site-plan-materializer.php` — generic plan-only boundary: consumes a Blocks Engine wordpress-site-plan JSON (`static-site-importer/materialize-wordpress-site-plan`), owns preflight/materialization/reconciliation.
- `class-static-site-importer-theme-exporter.php` — `static-site-importer/export-theme`, re-encodes an imported theme back to a `blocks-engine/php-transformer/site-artifact/v1` bundle.
- Contract schemas are first-class: `runtime-package-manifest.json` (canonical package composition for constrained runtime consumers), `homeboy.json` (release metadata), `registry/gutenberg-incompatibility-registry.schema.json`, docs under `docs/contracts/`.

## Test model

Standalone PHP smokes (`php tests/smoke-*.php`) self-bootstrap: they define `ABSPATH`, stub minimal WP functions (`__`, `sanitize_text_field`, …), require the targeted class, and run checks with assertions. Most test logic is in `includes/` classes with a smoke asserting behavior — unit tests are the exception, not the rule. `wordpress-runtime` tests (`wp eval-file …`) and `tests/StaticSiteImporterFallbackDiagnosticsTest.php` (phpunit) require actual WP. The codebase favors schema-locked smoke verification over attestation-style fluff; a change to a conversion/materialization path should add or update a smoke plus fixture evidence.

## Release workflow

Homeboy-managed (`homeboy.json`). Ground rules:

- Do not edit `docs/CHANGELOG.md` manually — Homeboy generates it from commits at release time.
- Do not hand-bump the plugin version — it is declared in two places in `static-site-importer.php` (header `Version:` and `STATIC_SITE_IMPORTER_VERSION`) and updated only by Homeboy release.
- Use conventional commits (`feat:` / `fix:` / `refactor:` / `test:` / `release:` …) so release notes and changelog stay meaningful.
