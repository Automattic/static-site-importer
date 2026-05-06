# Static Site Importer

Import a static site into WordPress pages and a companion block theme using the bundled [Block Format Bridge](https://github.com/chubes4/block-format-bridge) converter.

Static Site Importer is a WordPress plugin. It installs [Block Format Bridge](https://github.com/chubes4/block-format-bridge) through Composer and loads its bundled converter directly from `vendor/`.

## What It Does

- Adds an **Import Static Site** button on the **Appearance -> Themes** screen.
- Accepts pasted HTML, one public HTML URL, a direct `.html` / `.htm` upload, or a ZIP containing a static-site folder with an `index.html` shell/chrome entry point.
- Allows ZIP/CLI source-site imports to include nested `.md` / `.markdown` content documents; `.mdx` is skipped with explicit diagnostics because MDX runtime components are not supported.
- Provides a WP-CLI importer for a local HTML entry file or one public HTML URL; the local source file does not need to be named `index.html` unless you want automatic front-page assignment.
- Discovers readable sibling `*.html` files beside the selected entry file and recursive Markdown content documents under the source tree, then imports them as WordPress pages.
- Converts static HTML fragments through `bfb_convert( $html, 'html', 'blocks' )` and Markdown content through `bfb_convert( $markdown, 'markdown', 'blocks' )`.
- Stores converted page bodies on the imported WordPress pages as `post_content`.
- Generates a block theme with shared header/footer template parts, `core/post-content` templates, page patterns for reusable/reference artifacts, `theme.json`, `style.css`, and optional `assets/site.js`.
- Rewrites local `.html` links to the imported WordPress page permalinks.
- Creates deterministic `wp_navigation` posts for supported header/footer navigation and references them from generated template parts.
- Keeps imported pages native and editor-visible; page content belongs to WordPress pages while the generated theme owns shared chrome, background decoration, styles, scripts, and template wrappers.
- Optionally activates the generated theme and assigns the imported `index.html` page as the front page when that page exists.

## Requirements

- WordPress 6.6 or later.
- PHP 8.1 or later.
- Composer dependencies installed for development/source checkouts.
- Node dependencies installed only when running the JavaScript block-validation smoke tests.

The current Composer dependency is [`chubes4/block-format-bridge:^0.7.1`](https://github.com/chubes4/block-format-bridge). That package includes the prefixed HTML-to-blocks converter used by the importer.

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

PHP smokes run inside WordPress and load the plugin's bundled Block Format Bridge copy through the plugin runtime:

```bash
wp eval-file tests/smoke-admin-import-html-entry.php
wp eval-file tests/smoke-url-import-entry.php
wp eval-file tests/smoke-editor-style-support.php
wp eval-file tests/smoke-wordpress-is-dead-fixture.php
wp eval-file tests/smoke-mixed-source-fixture.php
```

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

- The importer is intentionally static-site-to-block-theme glue. Block Format Bridge owns format conversion; HTML-to-block transform fidelity belongs upstream in BFB/h2bc.
- The importer currently discovers flat sibling `*.html` files beside the selected entry file and recursive Markdown content documents; it does not crawl arbitrary nested HTML routes.
- Admin imports accept pasted HTML, one public URL, a direct `.html` / `.htm` file, or a ZIP with a root `index.html` or exactly one nested `index.html`; CLI imports take a direct HTML entry path or one public URL.
- MDX, Astro, Eleventy, Hugo, and other runtime/build orchestration is out of scope. Build those projects to static HTML first, or provide plain `.md` / `.markdown` source content alongside the HTML shell.
- Linked local stylesheets and inline styles are copied into `style.css`; inline scripts are copied into `assets/site.js`. Other asset copying is not a general-purpose crawler yet.
- Navigation persistence is limited to supported header/footer shapes that can be converted into deterministic `wp_navigation` entities without guessing.
- External live triage has exercised additional static sites; committed first-party fixtures include `tests/fixtures/wordpress-is-dead/` and `tests/fixtures/mixed-source-site/`.

## Boundary

This plugin owns the static-site import workflow and generated WordPress artifacts. [Block Format Bridge](https://github.com/chubes4/block-format-bridge) owns content-format conversion.

Imported pages remain WordPress pages for routing, titles, front-page assignment, editor visibility, and body content edits. Their imported body layouts live on the page posts as block markup in `post_content`. The generated block theme owns shared header/footer parts, optional background decoration, frontend/editor styles, scripts, and template wrappers that render page bodies through `core/post-content`; the generic `templates/page.html` stays the fallback for pages created after import.
