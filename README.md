# Static Site Importer

Import a static site or generated website artifact into WordPress pages and an intentional companion block or classic theme.

[![Try Static Site Importer in WordPress Playground](https://img.shields.io/badge/Try_Static_Site_Importer_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?php=8.5&blueprint-url=https%3A%2F%2Fautomattic.github.io%2Fstatic-site-importer%2Fplayground%2Flatest%2Fblueprint.json)

Static Site Importer is a WordPress plugin. It requires the [Blocks Engine PHP transformer](https://github.com/Automattic/blocks-engine/tree/trunk/php-transformer) Composer package and calls that package's canonical helper functions for generic artifact compilation and format conversion.

## Development packages

Build a production-shaped development ZIP using immutable Blocks Engine source without changing this checkout's Composer files:

```bash
npm run build:dev-package -- --blocks-engine-path ../blocks-engine --blocks-engine-ref origin/trunk
```

The command resolves the requested ref once, archives `php-transformer/` and `figma-transformer/` from that commit into an isolated temporary snapshot, installs production dependencies there, and delegates ZIP assembly to `homeboy review build`. The ZIP and adjacent provenance JSON are written to `build/`. Use `--output-dir <path>` to select another destination. The receipt records SSI `HEAD`, a dirty worktree identity when present, the Blocks Engine ref and SHA, Composer lock digest, ZIP digest, and schema version.

## Runtime package profiles

`runtime-package-manifest.json` is the canonical package-composition contract for constrained or embedded WordPress runtimes. Consumers locate the manifest inside the normal plugin release, select a named profile, copy only matching relative paths, and fail if any declared `required_files` entry is absent. The contract is deployment-neutral: storage, archive transport, scheduling, and runtime infrastructure remain consumer concerns.

The initial `website-artifact-import` profile provides the artifact import, validation, WordPress site-plan materialization, and manifest-inspection abilities. It includes SSI's WordPress/PHP runtime and the Blocks Engine PHP transformer dependency while excluding Figma, tests, tools, docs, Node dependencies, and other development-only trees. The same immutable contract is available from `static-site-importer/get-runtime-package-manifest` for runtime discovery.

## Playground package integrity

Generated Playground previews accept an SSI package only when it declares `url`, `version`, and a SHA-256 `sha256` (or `digest`) value. Production selection accepts either the exact GitHub release asset URL for that version or a content-addressed URL containing the declared digest. Playground downloads the archive to its virtual filesystem, verifies its SHA-256 before `installPlugin`, and records the version, digest, and URL in the preview request provenance.

Hosts provide the package through the `static_site_importer_playground_package` filter or the public blueprint primitive's explicit `package` option. A bundled runtime passes `install => false`; this remains the WordPress Build content-addressed package flow and never downloads a second SSI archive. Development-only package URLs require an explicit `development => true` selection and still require a valid digest. Mutable aliases such as `releases/latest` are rejected.

## Canonical Site Plans

`static-site-importer/materialize-wordpress-site-plan` is the generic plan-only boundary for a `blocks-engine/wordpress-site-plan/v2` produced by Blocks Engine. SSI calls the package's canonical validator and resolver, then owns WordPress/filesystem preflight, materialization, reconciliation, and the `static-site-importer/materialization-receipt/v2` response. Plan, report, classic handoff, and receipt bindings use the producer's `blocks-engine/wordpress-site-plan-identity/v1`; the materializer keeps its `prepared_resolved_projection_hash` separate for prepare-to-write TOCTOU detection. It accepts no source HTML or transformer result envelope.

For an isolated runtime matrix, invoke the ability with `plan`, `slug`, and optional `overwrite`, or use:

```bash
wp static-site-importer materialize-wordpress-site-plan --plan=/path/to/plan.json --slug=generated-site
```

## Client Script Policy

Every artifact is passed through `client_script_policy` before Blocks Engine compilation and WordPress materialization. The default is `inert`: SSI removes executable inline, local, remote, module, telemetry, and `data:` script markup, removes bundled JavaScript assets, and records each disposition in `import_report.client_script_policy`. JSON data scripts are quarantined in the report and are not emitted into the generated site.

`isolated_preview` is the sole preservation opt-in. It requires an explicit `client_script_provenance` object with a non-empty `ref` and a runtime isolation assertion. It is intended only for an isolated disposable preview runtime. Preserved scripts remain `untrusted_imported_code`; artifact carriage, local paths, and source type never establish trust. Current-site REST imports forcibly use `inert`. Existing `include_scripts` URL collection callers no longer preserve scripts; callers must request `script_policy: isolated_preview`, supply provenance, and run only in an isolated preview environment.

## Architecture Stack

Static Site Importer is the WordPress materialization layer for static website inputs. It accepts two related shapes:

- Static source imports: an HTML entry file, pasted HTML document, public HTML URL, bounded public static-site collection, direct HTML upload, or ZIP source tree.
- Generated website artifacts: a `blocks-engine/php-transformer/site-artifact/v1` bundle emitted by website generation or browser runtimes.

The conversion stack is split by responsibility:

- **Static Site Importer** owns WordPress intake, safety checks, page/theme creation, asset placement, import reports, quality gates, and intentional block or classic theme materialization.
- **Blocks Engine PHP transformer** owns the generic `ArtifactCompiler`, its diagnostics, and the `source_reports.wordpress_site_plan` v2 output. SSI materializes that plan into WordPress and returns the receipt and import report.

## Content-Only Security Boundary

All HTML, folders, ZIPs, URLs, and website artifact objects are untrusted static content. SSI accepts only explicit static asset extensions and rejects server-side source markers before compilation. Compiler-produced companion payloads are independently revalidated before any generated plugin file is written or activated. Companion block renders accept static HTML only; SSI emits its own fixed PHP wrapper to output that markup, so source PHP cannot be preserved or executed. Existing payloads that relied on PHP render templates or PHP companion assets must migrate their behavior to blocks, data bindings, or client-side JavaScript.

When a generated artifact contains full-document HTML, Static Site Importer routes document metadata, head content, styles, scripts, and page body fragments to the right WordPress destinations before calling the conversion stack. A `core/html` block in imported page content is therefore a materialization/conversion quality issue to fix in this stack, not a product-layer workaround to hide upstream.

## What It Does

- Adds an **Import Static Site** button on the **Appearance -> Themes** screen.
- Accepts pasted HTML, one public HTML URL, a direct `.html` / `.htm` upload, or a ZIP containing a static-site folder with an `index.html` shell/chrome entry point.
- Allows ZIP/CLI source-site imports to include nested `.md` / `.markdown` content documents; `.mdx` is skipped with explicit diagnostics because MDX runtime components are not supported.
- Provides one WP-CLI importer, `wp static-site-importer import`, for pasted HTML, website files, ZIP archives, and public URLs through the canonical `static-site-importer/import` ability.
- Discovers readable sibling `*.html` files beside the selected entry file and recursive Markdown content documents under the source tree, then imports them as WordPress pages.
- Compiles static HTML fragments and Markdown content through the Blocks Engine PHP transformer package helpers.
- Stores converted page bodies on the imported WordPress pages as `post_content`.
- Generates a block theme with shared header/footer template parts, `core/post-content` templates, page patterns for reusable/reference artifacts, `theme.json`, `style.css`, and optional `assets/site.js`.
- Rewrites local `.html` links to the imported WordPress page permalinks.
- Creates deterministic `wp_navigation` posts for supported header/footer navigation and references them from generated template parts.
- Keeps imported pages native and editor-visible; page content belongs to WordPress pages while the generated theme owns shared chrome, background decoration, styles, scripts, and template wrappers.
- Optionally activates the generated theme and assigns the imported `index.html` page as the front page when that page exists.
- Names the generated theme from the resolved imported site title unless the caller supplies an explicit name.
- Removes untouched WordPress installation content (`Hello world!`, `Sample Page`, and the sample comment) from fresh sites by default.

## Requirements

- WordPress 6.6 or later.
- PHP 8.2 or later.
- Composer dependencies installed with `composer install`.
- Node dependencies installed only when running the JavaScript block-validation smoke tests.

SSI requires `automattic/blocks-engine-php-transformer:^0.4.3`. Until the package is published on Packagist, `composer.json` includes an explicit package repository for the `php-transformer-v0.4.3` tag with autoloading rooted at the Blocks Engine monorepo archive's `php-transformer/src/` directory. Remove that repository override once Packagist serves the package metadata.

At runtime, SSI loads the transformer package from `vendor/` and calls `blocks_engine_php_transformer_compile_artifact()` and `blocks_engine_php_transformer_convert_format()` directly.

## Admin Usage

1. Open **Appearance -> Themes** and click **Import Static Site** beside the standard **Add Theme** button.
2. Paste a single HTML document, enter a public `http` / `https` URL, upload a single `.html` / `.htm` file, or upload a ZIP containing a static-site folder with an `index.html` entry point and optional `.md` / `.markdown` content documents.
3. Optionally provide a theme name and slug.
4. Leave **Activate imported theme** checked if the generated theme should become active immediately.

The admin path always overwrites an existing generated theme with the same slug. Pasted HTML, fetched URL HTML, and direct HTML uploads are copied into a generated upload work directory as `index.html` and imported as a single-page site. ZIP uploads are for multi-page static sites or bundled source-site exports; they are extracted to an upload work directory, the selected `index.html` is used as the entry file, sibling HTML files from that extracted site directory are imported, and nested `.md` / `.markdown` files are imported as content pages. The importer does not require the original source model to be a single `index.html`; it needs one selected HTML entry file for shared shell/chrome and imports the source content documents it can read.

## Site Identity and Default Content

The imported site's resolved title is also the generated theme name, so a producer's generic package name does not replace the site identity. Callers can still pass `name` and `slug`. Developers can customize the final values with `static_site_importer_theme_name` and `static_site_importer_theme_slug`:

```php
add_filter( 'static_site_importer_theme_name', static fn ( string $name ): string => $name . ' Theme' );
add_filter( 'static_site_importer_theme_slug', static fn ( string $slug ): string => 'custom-' . $slug );
```

Imports remove untouched core seed content on sites where WordPress still reports `fresh_site`. Records are fingerprinted before page materialization and checked again before deletion, so edited or replaced content is preserved. Set the canonical import argument `remove_default_content` to `false`, pass `--keep-default-content` to WP-CLI import commands, or use the `static_site_importer_remove_default_content` filter to disable cleanup.

## Theming The Importer Block

The `static-site-importer/importer` block ships neutral, standalone-friendly defaults, and is fully themeable by a host so it can match the host's design system — without forking the block, patching its stylesheet, or using forced style overrides. There are three complementary, additive seams. Standalone consumers who set nothing still get the default importer.

### 1. Design tokens (CSS custom properties)

The block's every color, radius, spacing, and typography decision reads from a `--ssi-importer-*` custom property declared on the `.ssi-importer` root. Override the tokens on `.ssi-importer` (or any ancestor) to reskin the importer to your palette. Because they are ordinary custom properties, a later, equal-or-higher-specificity rule wins on the cascade — no `!important` needed.

```css
/* Host theme: map the importer onto your own design system. */
.ssi-importer {
	--ssi-importer-surface: var( --my-surface );
	--ssi-importer-fg: var( --my-fg );
	--ssi-importer-fg-muted: var( --my-muted );
	--ssi-importer-accent: var( --my-accent );
	--ssi-importer-accent-fg: var( --my-accent-contrast );
	--ssi-importer-border: var( --my-border );
	--ssi-importer-radius: 12px;
}
```

Token surface (each declared with a default and consumed via `var()`):

| Token | Controls |
| --- | --- |
| `--ssi-importer-surface` | Panel background |
| `--ssi-importer-surface-muted` | Dropzone background |
| `--ssi-importer-surface-dragging` | Dropzone background while dragging |
| `--ssi-importer-fg` | Primary text |
| `--ssi-importer-fg-muted` | Secondary/help text |
| `--ssi-importer-accent` | Button background |
| `--ssi-importer-accent-fg` | Button text |
| `--ssi-importer-accent-hover` | Button hover background |
| `--ssi-importer-border` | Field borders |
| `--ssi-importer-border-subtle` | Panel border |
| `--ssi-importer-dropzone-border` | Dropzone dashed border |
| `--ssi-importer-dropzone-border-dragging` | Dropzone border while dragging |
| `--ssi-importer-success` | Success/status text |
| `--ssi-importer-radius` | Panel corner radius |
| `--ssi-importer-radius-field` | Field corner radius |
| `--ssi-importer-radius-dropzone` | Dropzone corner radius |
| `--ssi-importer-radius-pill` | Button (pill) radius |
| `--ssi-importer-shadow` | Panel elevation |
| `--ssi-importer-max-width` | Panel max width |
| `--ssi-importer-gap` | Vertical rhythm |
| `--ssi-importer-padding` | Panel padding |
| `--ssi-importer-font` | Font family |
| `--ssi-importer-title-size` | Title font size |

### 2. Wrapper classes (`static_site_importer_block_wrapper_classes`)

Append a scoping class to the block wrapper — for example to bound your token overrides to your own surface. The base `ssi-importer` class is always re-asserted, so the defaults and base styles keep applying.

```php
add_filter(
	'static_site_importer_block_wrapper_classes',
	static function ( string $classes ): string {
		return $classes . ' my-theme-importer';
	}
);
```

### 3. Wrapper attributes (`static_site_importer_block_wrapper_attributes`)

Attach extra attributes to the wrapper — most usefully an inline `style` that projects your design tokens onto the importer's custom properties from PHP, or `data-`/`aria-` hooks. Attribute names are sanitized and values are escaped; the block's own `data-static-site-importer*` hooks and its `class` cannot be overridden through this filter.

```php
add_filter(
	'static_site_importer_block_wrapper_attributes',
	static function ( array $attrs ): array {
		$attrs['style'] = '--ssi-importer-accent:#3858e9;--ssi-importer-surface:#101517';
		return $attrs;
	}
);
```

## Browser Playground Demo

Open Static Site Importer in a disposable WordPress Playground site:

[![Try Static Site Importer in WordPress Playground](https://img.shields.io/badge/Try_Static_Site_Importer_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?php=8.5&blueprint-url=https%3A%2F%2Fautomattic.github.io%2Fstatic-site-importer%2Fplayground%2Flatest%2Fblueprint.json)

The blueprint installs and activates the packaged Static Site Importer release, logs the visitor in, and opens `/import/` with the `static-site-importer/importer` block configured to generate a WordPress website. Testers can enter one public URL to collect and liberate a site into editable WordPress blocks, upload site files, choose a folder, upload a ZIP, or paste HTML. The canonical demo boots without optional PHP side modules. Figma upload is enabled only when the active runtime provides a zstd decoder; otherwise the importer explains that the capability is unavailable while keeping every other source type usable.

The optional extension manifest, side module, and safe launch blueprint are published to the repository's GitHub Pages site. Each release gets immutable versioned assets. The safe `playground/latest/blueprint.json` alias advances and is browser-verified independently; optional extension aliases advance only after their own runtime proof passes. This keeps an experimental side module from taking down the canonical demo while GitHub Pages supplies the CORS headers required by Playground. The extension is produced from pinned `php-ext-zstd` and vendored `libzstd` source by the release workflow; no unpublished branch, localhost URL, PECL installation, or host `zstd` executable is used.

### Direct Playground Previews

`POST /wp-json/static-site-importer/v1/imports` builds a website artifact from `source` (`url`, `html`, `files`, `archive`, or `artifact`). It opens a disposable Playground by default; set `apply_to_current_site: true` to import into the installed WordPress site instead.

The default response has `mode: "playground"`, `provider: "static-site-importer/direct-playground-blueprint"`, and `preview.url`. `preview.url` and `preview.playground.blueprint_url` are direct `https://playground.wordpress.net/#...` URLs; `preview.playground.preview_url` is `/` and `preview.playground.ref` identifies the generated blueprint.

PHP consumers can build the same blueprint with `static_site_importer_playground_import_steps()` or `static_site_importer_playground_import_blueprint()`, and create a direct preview response with `static_site_importer_build_playground_preview()`. For normalized website artifacts, call `static_site_importer_import_website_artifact_with_disposition()`; consumers can claim that flow through the `static_site_importer_import_disposition` filter, or return `null` to use the built-in non-destructive Playground preview.

URL intake rules:

- Every built-in URL import collects the bounded site through the resumable batch engine. It reads the origin's `/sitemap.xml`, follows same-origin HTML links, and collects directly referenced page assets and nested CSS assets.
- SSI owns collection, batch, deadline, asset, byte, pacing, and script-policy defaults. Hosts can adjust this policy with the `static_site_importer_url_batch_import_args` filter.
- `static-site-importer/import` accepts `{ source: { type: "url", url, import_id? }, operation }`. The first URL apply returns an opaque `import_id`; continuation supplies that ID in the same source envelope. SSI resolves the server-owned workspace and validates the URL, import options, and current user; no filesystem path is accepted or returned.
- URL collection uses the frozen static artifact policy: executable and data scripts are omitted with reason-coded provenance because the server-rendered output does not require them.
- Registered source-exclusion rules remove non-authored platform chrome before asset discovery and compilation. Each removal records selector, provider, reason, and before/after hashes under `source_metadata.collection.source_exclusions`; set `exclude_platform_chrome=false` to preserve the raw source.
- Extensions can add or replace source-exclusion rules with the `static_site_importer_source_exclusion_rules` filter. Rules use stable ID selectors and reason-coded categories so removals remain explicit and auditable.
- External assets must be directly referenced by fetched HTML or CSS and pass the same public-IP and redirect validation as page URLs.
- Only `http` and `https` URLs are accepted.
- Localhost, loopback, link-local, private, and otherwise reserved IP targets are rejected before connecting.
- Address classification is owned by SSI and behaves identically on every supported PHP version. Addresses are compared as packed bytes against an explicit RFC 6890 block table, IPv4-in-IPv6 encodings such as `::ffff:127.0.0.1` are unmapped so the classified address is the address the transport connects to, and shared address space (`100.64.0.0/10`) is treated as non-public.
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

The canonical ability uses `source.type` (`html`, `files`, `zip`, or `url`) and `operation` (`plan` or `apply`). Planning returns the canonical WordPress site plan, diagnostics, quality evidence, and source provenance without writing to the destination. Reference-backed sources use opaque `source.ref` values resolved only by the server-side `static_site_importer_resolve_source_reference` filter; ability callers never provide filesystem paths.

`static-site-importer import` is the canonical host command. Its request file is the exact `static-site-importer/import` ability input, so every source type and option has one contract across PHP, REST, and WP-CLI. The command owns bounded continuation: it relaunches the same import step in fresh WordPress runtimes, passes SSI's opaque `import_id`, and prints only the terminal JSON result. A terminal failure prints the same machine-readable envelope and exits nonzero.

```bash
wp static-site-importer import --request=/absolute/path/to/import-request.json
```

```json
{
  "operation": "apply",
  "source": {
    "type": "files",
    "entrypoint": "index.html",
    "files": [
      {
        "path": "index.html",
        "content": "<main><h1>Portable site</h1></main>"
      }
    ]
  },
  "slug": "portable-site",
  "name": "Portable Site",
  "activate": true,
  "overwrite": true
}
```

The host prints one `static-site-importer/import-cli-receipt/v1` object. Use `--report=/absolute/path/to/import-report.json` for the operator-owned report destination and `--max-steps=<count>` to bound continuation (default 256). `--single-step` is the internal fresh-runtime seam; host integrations invoke the command without it. `--url=` is a minimal ergonomic source argument.

Use `--theme-materialization=block|classic`; `block` is the default. Use `--operation=plan` or `"operation": "plan"` in the request to emit a plan without writes. Apply a saved response with `--plan=/absolute/path/to/plan-response.json`. A classic plan response includes a versioned, hashed normalized artifact, projection, and complete normalized arguments bundle. Apply verifies every digest and requires the immutable `theme_materialization=classic` strategy before running the full classic lifecycle.

```bash
wp static-site-importer import --url=https://example.com/ \
  --operation=plan \
  --slug=example-site \
  > url-plan-receipt.json

jq '.response' url-plan-receipt.json > url-plan.json
wp static-site-importer import --plan=/absolute/path/to/url-plan.json

# Commerce-bearing import: put allow_missing_woocommerce in the request JSON.
wp static-site-importer import --request=/absolute/path/to/store-request.json
```

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

`static-site-importer/export-theme` exports an imported or active block theme as a Blocks Engine website artifact. SSI owns the WordPress import/export/materialization path; Blocks Engine PHP transformer owns generic website artifact compilation. Product callers should consume the exported `website_artifact` object instead of SSI-specific static-site wrappers.

The export envelope includes:

- `schema: "blocks-engine/php-transformer/site-artifact/v1"`, `artifact_type: "website"`, `version`, `id`, `generated_at`, `root`, and `entrypoint`.
- `files[]` entries with safe artifact-relative paths, `role`, `kind`, `mime_type`, `encoding`, `bytes`, `sha256`, and inline `content`.
- UTF-8 text content by default; binary content is transported as Base64 with `encoding: "base64"`.
- source/materialization provenance under `provenance`.
- import/validation summaries and `reports[]` references for repair loops.
- `import-report.json` and `source-documents.json` metadata files when the exported theme has SSI import provenance.

The default root is `website` with `entrypoint: "website/index.html"`. Callers can pass any safe single-segment root with a matching entrypoint, such as `root: "artifact"` and `entrypoint: "artifact/index.html"`. The import ability accepts the same canonical website artifact through `artifact`.

## Product Handoff Contract

The product handoff contract is defined in `docs/product-handoff-contract.md` and locked by `tests/fixtures/product-handoff-contract/v1.json` plus `tests/smoke-product-handoff-contract.php`.

The handoff path is:

- product caller sends a `blocks-engine/php-transformer/site-artifact/v1` input artifact;
- Blocks Engine `ArtifactCompiler` returns `blocks-engine/php-transformer/result/v1` with `source_reports.wordpress_site_plan` using `blocks-engine/wordpress-site-plan/v2`;
- SSI consumes that v2 plan, writes WordPress state, and returns a materialization receipt plus `static-site-importer/import-report/v1` with import validation and finding packet artifacts;
- Codebox may validate the WordPress result and return `wp-codebox/validation-artifact-envelope/v1` artifact references.

Blocks Engine does not know about Codebox. Products that need sandbox validation request it after SSI materializes WordPress.

### Current-runtime validation

`static-site-importer/validate-artifact` validates a Blocks Engine website artifact in the current WordPress runtime and returns `static-site-importer/import-validation-result/v1` importer diagnostics. The ability accepts `artifact` plus normal import options; it is exposed through the Abilities REST API when that API is available.

The matching CLI command reads an artifact JSON object, imports it with activation, overwrite, and dependency materialization enabled by default, and writes the result to stdout or `--output`:

```bash
wp static-site-importer validate-artifact \
  --artifact=/path/to/website-artifact.json \
  --slug=example-import \
  --name="Example Import" \
  --output=/path/to/validation-result.json
```

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

### Test Inventory

`test-manifest.json` is the canonical repository-wide test inventory. It classifies every executable test as standalone PHP, WordPress runtime, Node, browser/WP Codebox, or operator-only acceptance. Explicit `command` values are arrays of executable and argument strings. `npm test` runs the fast standalone PHP and Node projection; it reports the environment-heavy lanes as skipped. `npm run test:all` is the complete CI/reviewer command and runs configured runtime lanes while reporting operator-only acceptance commands explicitly. `npm run test:inventory` verifies that every executable test is declared once and that `homeboy-test-manifest.json` remains the deterministic standalone-PHP projection used by Homeboy.

### PHP Smokes

PHP smokes run inside WordPress with SSI's Composer dependencies installed:

```bash
wp eval-file tests/smoke-admin-import-html-entry.php
wp eval-file tests/smoke-url-import-entry.php
wp eval-file tests/smoke-editor-style-support.php
wp eval-file tests/smoke-wordpress-is-dead-fixture.php
wp eval-file tests/smoke-mixed-source-fixture.php
```

`php tests/smoke-wordpress-site-plan-materializer.php` runs outside WordPress and verifies that Blocks Engine's direct `ArtifactCompiler` output is consumed through `source_reports.wordpress_site_plan` v2 and materialized into the stable receipt contract.

The `wordpress-is-dead` smoke verifies the multi-page fixture, generated block-theme artifacts, internal-link rewrites, persistent navigation entities, source CSS preservation, editor style support, conservative `theme.json` palette extraction, and selector fidelity across stored/rendered paths. The `mixed-source-site` smoke verifies an Astro-like source tree with `index.html`, nested Markdown content documents, explicit skipped-MDX diagnostics, report source counts, and generated page block markup.

### PHPUnit Fixture Test

`tests/StaticSiteImporterFixtureTest.php` mirrors the `wordpress-is-dead` fixture contract in PHPUnit form for the Homeboy WordPress test runner and CI.

```bash
homeboy test static-site-importer --path /path/to/static-site-importer
```

The GitHub workflow runs `Extra-Chill/homeboy-action@v2` with the `test` command across PHP 8.2, 8.3, 8.4, and 8.5.

### JavaScript Block Validation

The generated-theme JavaScript smoke runs Gutenberg's parser and block validator against generated theme artifacts:

```bash
npm install
npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
npm run test:js-block-validation -- --json /path/to/wp-content/themes/wordpress-is-dead
```

If no path is passed, the smoke uses `STATIC_SITE_IMPORTER_THEME_DIR`, then `WP_CONTENT_DIR/themes/wordpress-is-dead` when `WP_CONTENT_DIR` is set, then a local `wordpress-is-dead` directory under the repository root.

It validates `parts/header.html`, `parts/footer.html`, `patterns/*.php`, and `templates/*.html`, and reports invalid blocks with the file, nested block path, block name, validation reason, and failure summaries grouped by block name and file.

### Fixture Matrix Rig

The repo owns the Static Site Importer fixture matrix under `bench/`, `tools/`, `lib/fixture-matrix/`, `fixtures/`, and `rigs/static-site-importer-fixture-matrix/`. This is the product-level development gate for importer quality against generated static-site artifacts and the Blocks Engine fixture corpus.

```bash
npm run test:fixture-matrix
node bench/static-site-fixture-matrix.bench.mjs \
  --static-site-importer-path . \
  --blocks-engine-php-transformer-path /path/to/blocks-engine/php-transformer \
  --fixture-root /path/to/blocks-engine/fixtures/websites
```

See `docs/fixture-matrix.md` for the Homeboy/Lab/WP Codebox workflow, generated-artifact intake, visual parity, editor validation, and Blocks Engine corpus/override usage. SSI keeps only minimal matrix smoke fixtures under `tests/fixtures/fixture-matrix`; the canonical site corpus lives in Blocks Engine.

## Release Workflow

This repo is Homeboy-managed:

- `homeboy.json` declares the component ID, WordPress extension, version target in `static-site-importer.php`, and generated changelog target at `docs/CHANGELOG.md`.
- Do not edit `docs/CHANGELOG.md` manually. Homeboy owns changelog generation from commits at release time.
- Do not hand-bump plugin versions. Homeboy updates version targets during release.
- Use conventional commits so release notes and changelog entries are meaningful.

## Current Boundaries And Limitations

- The importer is intentionally static-site/artifact-to-block-theme glue. Blocks Engine PHP transformer owns generic artifact compilation, format conversion, and conversion reports; SSI owns WordPress uploads, import workflows, media, route rewriting, page/product materialization, and theme assembly.
- Local source imports discover flat sibling `*.html` files beside the selected entry file and recursive Markdown content documents. Bounded URL collection discovers sitemap and same-origin linked HTML routes but does not execute JavaScript or perform platform-specific API extraction.
- Admin imports accept pasted HTML, one public URL, a direct `.html` / `.htm` file, or a ZIP with a root `index.html` or exactly one nested `index.html`; CLI imports take a direct HTML entry path or one public URL.
- MDX, Astro, Eleventy, Hugo, and other runtime/build orchestration is out of scope. Build those projects to static HTML first, or provide plain `.md` / `.markdown` source content alongside the HTML shell.
- Linked local stylesheets and inline styles are copied into `style.css`; inline scripts are copied into `assets/site.js`. Bounded URL collection packages directly referenced HTML/CSS assets, while local source intake does not independently crawl missing assets.
- Navigation persistence is limited to supported header/footer shapes that can be converted into deterministic `wp_navigation` entities without guessing.
- External live triage has exercised additional static sites; committed first-party fixtures include `tests/fixtures/wordpress-is-dead/` and `tests/fixtures/mixed-source-site/`.

## Boundary

This plugin owns static-site and website-artifact import workflows plus generated WordPress artifacts. [Blocks Engine PHP transformer](https://github.com/Automattic/blocks-engine/tree/trunk/php-transformer) owns generic artifact compilation and emits `source_reports.wordpress_site_plan` using `blocks-engine/wordpress-site-plan/v2`; SSI materializes that plan and records its receipt.

The intended dependency direction is:

```text
Static Site Importer -> Blocks Engine PHP transformer
```

SSI import reports record `blocks_engine.transformer` provenance and `blocks_engine.wordpress_site_plan`; they do not project compiled-site or materialization-plan v1 payloads. Blocks Engine schemas are the active wire contract; SSI should not call lower-level converter packages directly or re-derive semantic page-route intent when the transformer supplies it.

Imported pages remain WordPress pages for routing, titles, front-page assignment, editor visibility, and body content edits. Their imported body layouts live on the page posts as block markup in `post_content`. The generated block theme owns shared header/footer parts, optional background decoration, frontend/editor styles, scripts, and template wrappers that render page bodies through `core/post-content`; the generic `templates/page.html` stays the fallback for pages created after import.
