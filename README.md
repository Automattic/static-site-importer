# Static Site Importer

Import a static site or generated website artifact into WordPress pages and a companion block theme.

Static Site Importer is a WordPress plugin. It requires the [Blocks Engine PHP transformer](https://github.com/Automattic/blocks-engine/tree/trunk/php-transformer) plugin and calls that plugin's canonical helper functions for generic artifact compilation and format conversion.

## Architecture Stack

Static Site Importer is the WordPress materialization layer for static website inputs. It accepts two related shapes:

- Static source imports: an HTML entry file, pasted HTML document, public HTML URL, direct HTML upload, or ZIP source tree.
- Generated website artifacts: a `block-artifact-compiler/website-artifact/v1` bundle, such as the website artifact emitted by Studio Web or WP Codebox browser runtimes.

The conversion stack is split by responsibility:

- **Static Site Importer** owns WordPress intake, safety checks, page/theme creation, asset placement, import reports, quality gates, and block-theme materialization.
- **Blocks Engine PHP transformer** owns the generic artifact compiler, `blocks-engine/php-transformer/compiled-site/v1` source report, source documents, asset reports, and format conversion helpers. SSI maps those generic results into its current WordPress materialization envelope inside `Static_Site_Importer_Transformer_Adapter`.

When a generated artifact contains full-document HTML, Static Site Importer routes document metadata, head content, styles, scripts, and page body fragments to the right WordPress destinations before calling the conversion stack. A `core/html` block in imported page content is therefore a materialization/conversion quality issue to fix in this stack, not a product-layer workaround to hide upstream.

## What It Does

- Adds an **Import Static Site** button on the **Appearance -> Themes** screen.
- Accepts pasted HTML, one public HTML URL, a direct `.html` / `.htm` upload, or a ZIP containing a static-site folder with an `index.html` shell/chrome entry point.
- Allows ZIP/CLI source-site imports to include nested `.md` / `.markdown` content documents; `.mdx` is skipped with explicit diagnostics because MDX runtime components are not supported.
- Provides a WP-CLI importer for a local HTML entry file or one public HTML URL; the local source file does not need to be named `index.html` unless you want automatic front-page assignment.
- Discovers readable sibling `*.html` files beside the selected entry file and recursive Markdown content documents under the source tree, then imports them as WordPress pages.
- Compiles static HTML fragments and Markdown content through the Blocks Engine PHP transformer plugin helpers.
- Stores converted page bodies on the imported WordPress pages as `post_content`.
- Generates a block theme with shared header/footer template parts, `core/post-content` templates, page patterns for reusable/reference artifacts, `theme.json`, `style.css`, and optional `assets/site.js`.
- Rewrites local `.html` links to the imported WordPress page permalinks.
- Creates deterministic `wp_navigation` posts for supported header/footer navigation and references them from generated template parts.
- Keeps imported pages native and editor-visible; page content belongs to WordPress pages while the generated theme owns shared chrome, background decoration, styles, scripts, and template wrappers.
- Optionally activates the generated theme and assigns the imported `index.html` page as the front page when that page exists.

## Requirements

- WordPress 6.6 or later.
- PHP 8.1 or later.
- Blocks Engine PHP transformer plugin installed and active.
- Node dependencies installed only when running the JavaScript block-validation smoke tests.

SSI does not load the transformer through Packagist or a local `vendor/` autoloader at runtime; WordPress loads the transformer plugin, and SSI calls `blocks_engine_php_transformer_compile_artifact()` and `blocks_engine_php_transformer_convert_format()`.

## Admin Usage

1. Open **Appearance -> Themes** and click **Import Static Site** beside the standard **Add Theme** button.
2. Paste a single HTML document, enter a public `http` / `https` URL, upload a single `.html` / `.htm` file, or upload a ZIP containing a static-site folder with an `index.html` entry point and optional `.md` / `.markdown` content documents.
3. Optionally provide a theme name and slug.
4. Leave **Activate imported theme** checked if the generated theme should become active immediately.

The admin path always overwrites an existing generated theme with the same slug. Pasted HTML, fetched URL HTML, and direct HTML uploads are copied into a generated upload work directory as `index.html` and imported as a single-page site. ZIP uploads are for multi-page static sites or bundled source-site exports; they are extracted to an upload work directory, the selected `index.html` is used as the entry file, sibling HTML files from that extracted site directory are imported, and nested `.md` / `.markdown` files are imported as content pages. The importer does not require the original source model to be a single `index.html`; it needs one selected HTML entry file for shared shell/chrome and imports the source content documents it can read.

URL intake rules:

- Fetches one URL only; this is not a crawler and does not execute JavaScript.
- Only `http` and `https` URLs are accepted.
- Localhost, loopback, link-local, private, and otherwise reserved IP targets are rejected before connecting.
- Redirect targets are revalidated with the same policy and capped.
- Requests use a timeout and maximum response size, require an HTML-like content type, and do not forward cookies, authorization headers, or embedded URL credentials.
- Import reports include source URL, final URL, status code, content type, fetch timestamps, response size, and redirect history.

ZIP intake rules:

- A root-level `index.html` wins when present.
- If there is no root-level `index.html`, the ZIP may contain exactly one nested `index.html`, such as `site-export/index.html`.
- If there are multiple nested `index.html` files and no root `index.html`, the import fails so the entry point is not guessed.
- Archive entries with absolute paths, `../` traversal segments, or server-side executable extensions are rejected before extraction when PHP's `ZipArchive` inspection is available.
- `.md` and `.markdown` files under the selected source tree are imported as pages. `.mdx` files are not executed or parsed as Markdown; they are skipped and listed in `import-report.json` diagnostics.

## Generated Store Contract

Static store generators can expose products directly in raw HTML. Product cards using `.product-card` with a visible heading and price are accepted as commerce context and do not require a separate manifest. Generators may also include an optional `products.json` file beside the selected entry HTML file. When present, Static Site Importer validates the manifest and records the contract result under `commerce.products_manifest` in `import-report.json`.

Minimal schema:

```json
{
  "schema_version": 1,
  "products": [
    {
      "name": "Signal Hoodie",
      "slug": "signal-hoodie",
      "regular_price": "64.00"
    }
  ]
}
```

Required fields:

- `schema_version`: integer `1`.
- `products`: array of product objects.
- `products[].name`: non-empty string.
- `products[].slug`: lowercase URL slug using letters, numbers, and hyphens.
- `products[].regular_price`: decimal string such as `19.00`.

Optional product fields:

- `sale_price`: decimal string.
- `description` and `short_description`: strings.
- `categories`: array of non-empty category-name strings.
- `image`: string path relative to the static site source.
- `status`: string product post status metadata.
- `stock_status`: string stock status metadata.
- `stock_quantity`: integer stock quantity.
- `source_selectors`: array of non-empty CSS selector strings for source-product cards.

Invalid manifests do not abort the import. The report marks the manifest invalid and records path-addressed errors such as `$.products[0].slug`. If raw HTML product cards supply product context, the optional manifest does not add a top-level `products_manifest_invalid` diagnostic.

## WooCommerce Dependency

Commerce-bearing imports require WooCommerce. Commerce intent is detected when any of these signals are present:

- a valid `products.json` manifest with at least one product, or
- caller-supplied `commerce_context` with at least one product, or
- inferred commerce context from JSON-LD `Product` data or visible product cards.

When intent is present and WooCommerce is not active, Static Site Importer first tries to materialize the dependency deterministically by installing and activating WooCommerce from WordPress.org inside the active WordPress runtime. This keeps product support in the WordPress/PHP materializer rather than relying on the generating agent to install plugins by prompt convention.

The dependency materialization result is recorded under `plugin_materialization.plugins.woocommerce` with the plugin slug, plugin file, source, attempted flag, install/activate actions, status, and any error. If WooCommerce is already loaded, the status is `already_available`; if the runtime installs and activates it, the status is `installed_activated`; if installation or activation is unavailable, the status is `failed` and the normal dependency gate still protects the import.

When WooCommerce remains unavailable after materialization, Static Site Importer hard-fails the import by default. Theme files are still written so the import report and generated artifacts can be inspected. The failure surfaces three ways:

- `commerce.dependencies.woocommerce` block on the import report (`required`, `active`, `waived`, `sources`, `product_count`, `missing_apis`).
- A `woocommerce_missing` error diagnostic in the report `diagnostics[]` list.
- `quality.failure_reasons[]` contains `woocommerce_missing`, `quality.commerce_dependency_failures` is non-zero, and `quality.fail_import` is set regardless of `--fail-on-quality`.

Pass `--allow-missing-woocommerce` (CLI) or `'allow_missing_woocommerce' => true` (PHP API) to import the theme without seeding products. The waiver records a `woocommerce_waived` warning diagnostic and clears the dependency failure. Pass `--skip-dependency-materialization` (CLI) or `'materialize_dependencies' => false` (PHP API) only for tests or hosts that intentionally forbid plugin installation. Non-commerce imports (no manifest, no inferred context) are unaffected: no `commerce.dependencies` block is recorded and no dependency diagnostics are emitted.

This materializer is intentionally generic: WooCommerce is the first plugin-backed entity path, and the same pattern should be used for bbPress forums/topics, Jetpack-backed features, and other popular WordPress.org plugins. The source artifact declares or implies plugin-backed intent; SSI materializes the plugin in PHP; then a plugin-specific seeder creates native WordPress/plugin entities and records diagnostics.

## CLI Usage

```bash
wp static-site-importer import-theme /path/to/site/index.html \
  --slug=wordpress-is-dead \
  --name="WordPress Is Dead" \
  --activate \
  --overwrite

wp static-site-importer import-theme \
  --url=https://example.com/ \
  --slug=example-import \
  --keep-source \
  --report=report.json

wp static-site-importer import-url https://example.com/ \
  --slug=example-import \
  --keep-source \
  --report=report.json

# Commerce-bearing import on a host without WooCommerce: skip seeding and continue.
wp static-site-importer import-theme /path/to/store/index.html \
  --slug=store-no-woo \
  --allow-missing-woocommerce \
  --keep-source
```

The CLI path imports all readable sibling `*.html` files in the same directory as the provided entry file plus recursive `.md` / `.markdown` content documents under the source tree. The entry file supplies the theme title, shared source chrome, background decoration, styles, and inline scripts; each source content file supplies a WordPress page body. `.mdx` files are unsupported and reported as skipped diagnostics.

`index.html` has special front-page behavior: it becomes the `home` page slug and, when `--activate` is used, is assigned as the site's static front page. If the imported directory has no `index.html`, the pages are still imported, but the importer does not assign `page_on_front` automatically.

By default, source directories are deleted after a successful clean import so generated upload work directories do not accumulate. Sources are preserved when conversion quality checks report issues. Use `--keep-source` with CLI imports when you want to keep the original local source directory or fetched URL fixture after a successful clean import for debugging or development. Import reports include a `source_documents` summary with counts by format, skipped MDX count, unresolved local links, and Markdown parse-error diagnostics.

## Generated Theme Shape

An import writes a conventional block theme directory under `wp-content/themes/<slug>/`:

```text
<slug>/
  style.css
  functions.php
  theme.json
  assets/site.js          # only when the source has inline JS
  parts/header.html
  parts/footer.html
  templates/front-page.html
  templates/index.html
  templates/page.html
  templates/page-<page>.html
  patterns/page-<page>.php
```

Important behavior:

- `style.css` contains the source linked local stylesheets, inline styles, and compatibility rules that preserve source button classes on `core/button` links.
- `functions.php` enqueues frontend styles, editor styles, and optional generated `assets/site.js`.
- `theme.json` extracts conservative color palette tokens from obvious `:root` CSS custom properties.
- Shared chrome is stored in `parts/header.html` and, when present in the source, `parts/footer.html`.
- Generated templates are lightweight block-theme wrappers: header template part, imported background decoration, `core/post-content`, and optional footer template part.
- Imported WordPress page posts store the converted page body in `post_content`, so routing, titles, front-page assignment, editor visibility, and body edits stay native.
- Page patterns are generated as reusable/reference copies of each converted page body; they are not the primary storage for imported page content.

## Website Artifact Export

`static-site-importer/export-theme` exports an imported or active block theme as a Blocks Engine website artifact. SSI owns the WordPress import/export/materialization path; Blocks Engine PHP transformer owns generic website artifact compilation. Studio Web, WP Codebox, and other products should consume the exported `website_artifact` object rather than SSI-specific static-site wrappers.

The export envelope includes:

- `schema: "block-artifact-compiler/website-artifact/v1"`, `artifact_type: "website"`, `version`, `id`, `generated_at`, `root`, and `entrypoint`.
- `files[]` entries with safe artifact-relative paths, `role`, `kind`, `mime_type`, `encoding`, `bytes`, `sha256`, and inline `content`.
- UTF-8 text content by default; binary content is transported as Base64 with `encoding: "base64"`.
- source/materialization provenance under `provenance`.
- import/validation summaries and `reports[]` references for repair loops.
- `import-report.json` and `source-documents.json` metadata files when the exported theme has SSI import provenance.

The default root is `website` with `entrypoint: "website/index.html"`. Callers can pass any safe single-segment root with a matching entrypoint, such as `root: "artifact"` and `entrypoint: "artifact/index.html"`.

## Validation

The repository has both WordPress-side fixture coverage and generated-artifact validation.

### Full Validation Harness

Run the full local contract from the repository root:

```bash
npm install
npm run test:validation
```

The harness imports `tests/fixtures/wordpress-is-dead/` into the configured WordPress site, then runs the PHP smokes and the JavaScript block-validation smoke in dependency order.

By default it uses:

```text
studio wp --path /Users/chubes/Studio/intelligence-chubes4
```

Useful overrides:

```bash
STATIC_SITE_IMPORTER_SITE_PATH=/path/to/site npm run test:validation
STATIC_SITE_IMPORTER_WP_CLI="wp" npm run test:validation
npm run test:validation -- --skip-import /path/to/wp-content/themes/wordpress-is-dead
npm run test:validation -- --json
```

### PHP Smokes

PHP smokes run inside WordPress with the Blocks Engine PHP transformer plugin loaded through the plugin runtime:

```bash
wp eval-file tests/smoke-admin-import-html-entry.php
wp eval-file tests/smoke-url-import-entry.php
wp eval-file tests/smoke-editor-style-support.php
wp eval-file tests/smoke-wordpress-is-dead-fixture.php
wp eval-file tests/smoke-mixed-source-fixture.php
```

`php tests/smoke-transformer-adapter.php` runs outside WordPress and verifies the SSI-owned transformer adapter uses Blocks Engine format conversion for export rendering, consumes the native compiled-site/source-document/asset reports, and keeps WordPress page mapping in SSI.

The native compiled-site report may include route metadata for HTML pages before every route has block markup. SSI's adapter records the generic report fields it consumes, but only forwards pages with matching materializable document artifacts to the current WordPress page materializer.

The `wordpress-is-dead` smoke verifies the multi-page fixture, generated block-theme artifacts, internal-link rewrites, persistent navigation entities, source CSS preservation, editor style support, conservative `theme.json` palette extraction, and selector fidelity across stored/rendered paths. The `mixed-source-site` smoke verifies an Astro-like source tree with `index.html`, nested Markdown content documents, explicit skipped-MDX diagnostics, report source counts, and generated page block markup.

### PHPUnit Fixture Test

`tests/StaticSiteImporterFixtureTest.php` mirrors the `wordpress-is-dead` fixture contract in PHPUnit form for the Homeboy WordPress test runner and CI.

```bash
homeboy test static-site-importer --path /path/to/static-site-importer
```

The GitHub workflow runs `Extra-Chill/homeboy-action@v2` with the `test` command across PHP 8.1, 8.2, 8.3, and 8.4.

### JavaScript Block Validation

The generated-theme JavaScript smoke runs Gutenberg's parser and block validator against generated theme artifacts:

```bash
npm install
npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
npm run test:js-block-validation -- --json /path/to/wp-content/themes/wordpress-is-dead
```

If no path is passed, the smoke uses `STATIC_SITE_IMPORTER_THEME_DIR`, then `WP_CONTENT_DIR/themes/wordpress-is-dead` when `WP_CONTENT_DIR` is set, then a local `wordpress-is-dead` directory under the repository root.

It validates `parts/header.html`, `parts/footer.html`, `patterns/*.php`, and `templates/*.html`, and reports invalid blocks with the file, nested block path, block name, validation reason, and failure summaries grouped by block name and file.

## Release Workflow

This repo is Homeboy-managed:

- `homeboy.json` declares the component ID, WordPress extension, version target in `static-site-importer.php`, and generated changelog target at `docs/CHANGELOG.md`.
- Do not edit `docs/CHANGELOG.md` manually. Homeboy owns changelog generation from commits at release time.
- Do not hand-bump plugin versions. Homeboy updates version targets during release.
- Use conventional commits so release notes and changelog entries are meaningful.

## Current Boundaries And Limitations

- The importer is intentionally static-site/artifact-to-block-theme glue. Blocks Engine PHP transformer owns generic artifact compilation, format conversion, and conversion reports; SSI owns WordPress uploads, import workflows, media, route rewriting, page/product materialization, and theme assembly.
- The importer currently discovers flat sibling `*.html` files beside the selected entry file and recursive Markdown content documents; it does not crawl arbitrary nested HTML routes.
- Admin imports accept pasted HTML, one public URL, a direct `.html` / `.htm` file, or a ZIP with a root `index.html` or exactly one nested `index.html`; CLI imports take a direct HTML entry path or one public URL.
- MDX, Astro, Eleventy, Hugo, and other runtime/build orchestration is out of scope. Build those projects to static HTML first, or provide plain `.md` / `.markdown` source content alongside the HTML shell.
- Linked local stylesheets and inline styles are copied into `style.css`; inline scripts are copied into `assets/site.js`. Other asset copying is not a general-purpose crawler yet.
- Navigation persistence is limited to supported header/footer shapes that can be converted into deterministic `wp_navigation` entities without guessing.
- External live triage has exercised additional static sites; committed first-party fixtures include `tests/fixtures/wordpress-is-dead/` and `tests/fixtures/mixed-source-site/`.

## Boundary

This plugin owns static-site and website-artifact import workflows plus generated WordPress artifacts. [Blocks Engine PHP transformer](https://github.com/Automattic/blocks-engine/tree/trunk/php-transformer) owns generic artifact compilation, including the materializer-neutral `blocks-engine/php-transformer/compiled-site/v1` page route and theme-asset report. SSI maps that report into its WordPress import contract and keeps product-specific page/product mapping here.

The intended dependency direction is:

```text
Static Site Importer -> Blocks Engine PHP transformer
```

SSI import reports consume Blocks Engine PHP transformer result envelopes for conversion-quality diagnostics, and record the compiled-site contract when importing website artifacts. Legacy schema names remain wire contracts only; SSI should not call lower-level converter packages directly or re-derive semantic page-route intent when the transformer supplies it.

Imported pages remain WordPress pages for routing, titles, front-page assignment, editor visibility, and body content edits. Their imported body layouts live on the page posts as block markup in `post_content`. The generated block theme owns shared header/footer parts, optional background decoration, frontend/editor styles, scripts, and template wrappers that render page bodies through `core/post-content`; the generic `templates/page.html` stays the fallback for pages created after import.
