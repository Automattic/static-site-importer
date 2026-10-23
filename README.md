# Static Site Importer

Import a static site or generated website artifact into WordPress pages and an intentional companion block or classic theme.

[![Try Static Site Importer in WordPress Playground](https://img.shields.io/badge/Try_Static_Site_Importer_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?php=8.5&blueprint-url=https%3A%2F%2Fautomattic.github.io%2Fstatic-site-importer%2Fplayground%2Flatest%2Fblueprint.json)

Static Site Importer is a WordPress plugin. It requires the [Blocks Engine PHP transformer](https://github.com/Automattic/blocks-engine/tree/trunk/php-transformer) Composer package. Production compilation uses `ArtifactCompiler::compile()`; export still calls `blocks_engine_php_transformer_convert_format()`.

## Development packages

Build a production-shaped development ZIP using immutable Blocks Engine source without changing this checkout's Composer files:

```bash
npm run build:dev-package -- --blocks-engine-path ../blocks-engine --blocks-engine-ref origin/trunk
```

To test an unreleased Blocks Engine change, point `--blocks-engine-path` at that checkout and omit `--blocks-engine-ref`: the package then uses the checkout's `HEAD`, and the build refuses to run while `php-transformer/` or `figma-transformer/` have uncommitted changes, because only committed bytes are packaged. With neither option, `../blocks-engine` at `origin/trunk` is used.

The command resolves the requested ref once, archives `php-transformer/` and `figma-transformer/` from that commit into an isolated temporary snapshot, installs production dependencies there, and delegates ZIP assembly to `homeboy review build`. The ZIP and adjacent provenance JSON are written to `build/`. Use `--output-dir <path>` to select another destination. The receipt records SSI `HEAD`, a dirty worktree identity when present, the Blocks Engine ref and SHA, Composer lock digest, ZIP digest, and schema version.

## Runtime package profiles

`runtime-package-manifest.json` is the canonical package-composition contract for constrained or embedded WordPress runtimes. Consumers locate the manifest inside the normal plugin release, select a named profile, copy only matching relative paths, and fail if any declared `required_files` entry is absent. The contract is deployment-neutral: storage, archive transport, scheduling, and runtime infrastructure remain consumer concerns.

`website-artifact-import` remains the default release profile and provides the full artifact import, validation, WordPress site-plan materialization, and manifest-inspection abilities with all production Composer dependencies, including the Blocks Engine PHP and Figma transformers. `html-site-import` provides those same abilities for HTML string, file, URL, ZIP, and HTML website-artifact inputs while excluding Figma, optional Markdown conversion dependencies, and SSI's root Composer runtime. Select it for a development package with `npm run build:dev-package -- --runtime-profile html-site-import`; package consumers select the same name from `runtime-package-manifest.json`. Markdown and MDX inputs return an explicit unsupported-capability diagnostic in that reduced runtime. The immutable contract is also available from `static-site-importer/get-runtime-package-manifest` for runtime discovery.

## Canonical Site Plans

`static-site-importer/materialize-wordpress-site-plan` is the generic plan-only boundary for a `blocks-engine/wordpress-site-plan/v2` produced by Blocks Engine. SSI calls the package's canonical validator and resolver, then owns WordPress/filesystem preflight, materialization, reconciliation, and the `static-site-importer/materialization-receipt/v2` response. Plan, report, classic handoff, and receipt bindings use the producer's `blocks-engine/wordpress-site-plan-identity/v1`; the materializer keeps its `prepared_resolved_projection_hash` separate for prepare-to-write TOCTOU detection. It accepts no source HTML or transformer result envelope.

For an isolated runtime matrix, invoke the ability with `plan`, `slug`, and optional `overwrite`, or use:

```bash
wp static-site-importer materialize-wordpress-site-plan --plan=/path/to/plan.json --slug=generated-site
```

## Source URL continuity

Imported documents and internal links use native WordPress permalinks. Redirection is the sole owner of old-source URL continuity: imports with source documents prepare the plugin and create owner-manageable exact-path 301 rules wherever source paths differ, including `.html`, `index.html`, and nested directory aliases. Switching themes does not remove these rules. Generated themes and companion plugins contain no source-route resolver or permalink override; keep Redirection active to retain old URLs. Portable exports carry active rules through `_redirects` for reimport.

## Client Script Policy

Every artifact is passed through `client_script_policy` before Blocks Engine compilation and WordPress materialization. The default is `inert`: SSI removes executable inline, local, remote, module, telemetry, and `data:` script markup, removes bundled JavaScript assets, and records each disposition in `import_report.client_script_policy`. JSON data scripts are quarantined in the report and are not emitted into the generated site.

`isolated_preview` is the sole preservation opt-in. It requires an explicit `client_script_provenance` object with a non-empty `ref` and a runtime isolation assertion. It is intended only for an isolated disposable preview runtime. Preserved scripts remain `untrusted_imported_code`; artifact carriage, local paths, and source type never establish trust. Current-site REST imports forcibly use `inert`. Existing `include_scripts` URL collection callers no longer preserve scripts; callers must request `script_policy: isolated_preview`, supply provenance, and run only in an isolated preview environment.

### Unproven dynamic client asset references

Blocks Engine marks a plan `reference_semantics.dynamic_client_assets.status = not_proven` when a preserved local script builds asset URLs at runtime (dynamic imports, script injection, runtime URL construction), because those references cannot be proven against the theme's asset surface. By default SSI fails closed: destination preparation rejects the unproven plan with the canonical reason code.

Callers can opt into treating unproven references as a reported loss with `require_proven_dynamic_client_assets => false` (CLI: `--allow-unproven-dynamic-client-assets`). SSI then drops exactly the unprovable scripts from the artifact, strips their `<script>` tags and script preloads, recompiles a proven plan, and records one typed `unproven_dynamic` row per unproven reference (script path, referencing document, `src`, and `sha256`) in the `client_script_policy.dropped` report section the materialization receipt projects. Proven scripts and all non-script files are untouched.

## Architecture Stack

Static Site Importer is the WordPress materialization layer for static website inputs. It accepts two related shapes:

- Static source imports: an HTML entry file, pasted HTML document, public HTML URL, bounded public static-site collection, direct HTML upload, or ZIP source tree.
- Generated website artifacts: a `blocks-engine/php-transformer/site-artifact/v1` bundle emitted by website generation or browser runtimes.

The conversion stack is split by responsibility:

- **Static Site Importer** owns WordPress intake, safety checks, page/theme creation, asset placement, import reports, quality gates, and intentional block or classic theme materialization.
- **Blocks Engine PHP transformer** owns the generic `ArtifactCompiler`, its diagnostics, and the `source_reports.wordpress_site_plan` v2 output. SSI materializes that plan into WordPress and returns the receipt and import report.

## Existing Companion Block Contracts

Companion refresh accepts new blocks, additive attributes, authoring defaults,
styles and implementation updates. Removing a registration or changing/removing
an existing attribute schema triggers a saved-usage lookup before files are written.
It parses candidate WordPress posts (including revisions, reusable blocks and
template posts), then active theme templates and parts. Text mentions and block
name prefixes are not treated as actual instances.

If an instance still uses that contract, refresh returns
`static_site_importer_companion_saved_contract_changed` with the block name,
changed attributes and a post/template reference. An unused schema may change.
An unavailable or capped lookup returns `static_site_importer_companion_usage_unverified`.
`overwrite` is not a migration of every saved instance, including revisions.

This check targets schema-driven data loss. JavaScript/save-function compatibility,
default-value behavior and switching to another companion plugin remain separate
concerns. Run `tests/companion-persistence.php` in a disposable WordPress site for
the real multi-request regression and legitimate-refresh proof.

## Form Provider Mapping

Jetpack form materialization prepares normalized controls, topology, provider layout/presentation destinations, and bounded receipt losses as a `Static_Site_Importer_Form_Mapping_Plan`. The plan resolves one mapped/declined decision before final form serialization; runtime binding and report projection consume that same decision. Visual-state collection reads the admitted plan without serializing a provider form.

The existing `static_site_importer_form_receipt_loss_accepted` waiver filter receives the complete proposed emission row, including markup and overlays. When that filter is installed and a loss needs its decision, the plan lazily serializes the proposal and reuses those exact bytes for the final report. Declined proposals remain report evidence and do not become runtime bindings.

Run `php tests/smoke-form-mapping-plan.php` for the phase-ordering, waiver, and loss-overflow contract, and the form-materializer smoke/topology and rendered-layout suites for output and presentation coverage.

## Portable External Metric Bindings

Producer-declared WordPress.org metrics bind directly to native Paragraph and Heading text leaves. The generated companion carries fixed provider endpoints, validated configuration and source provenance, one-hour caching, per-request request deduplication, last-known-good fallback, editor refresh/detach controls, and runtime freshness receipts. Materialization receipts report the configured binding; after a frontend request, `static_site_importer_external_metric_receipts` records `fresh`, `stale`, `captured_fallback`, or `unresolved` with the fetch timestamp. Failed or partial aggregates never become zero. GitHub stars/forks remain unresolved until their separate bounded provider is implemented. See [the external metric runtime contract](docs/contracts/external-metric-runtime-v1.md).

## Content-Only Security Boundary

All HTML, folders, ZIPs, URLs, and website artifact objects are untrusted static content. SSI accepts only explicit static asset extensions and server-side source markers never reach compilation. One decision, `Static_Site_Importer_Content_Policy::admit_artifact()`, runs once per import where the artifact is frozen for compilation, whatever the intake (REST files or ZIP, staged ZIP, CLI request bundle, URL collection, provided artifact):

- a non-static asset is skipped with a `static_site_importer_non_static_file_skipped` finding;
- a non-entry page that is not static content (a server page, or HTML carrying server code) is dropped with a `static_site_importer_non_static_page_dropped` finding, and the remaining pages import;
- the entrypoint (the declared one, or a root `index`/`default`/`home` document) and the root `_redirects` manifest fail closed with `static_site_importer_executable_source_rejected`. Compiler-produced companion payloads are independently revalidated before any generated plugin file is written or activated. Companion block renders accept static HTML only; SSI emits its own fixed PHP wrapper to output that markup, so source PHP cannot be preserved or executed. Existing payloads that relied on PHP render templates or PHP companion assets must migrate their behavior to blocks, data bindings, or client-side JavaScript.

A URL import admits each collected batch through the same decision, with the site entry URL's page as the entrypoint. Its fetch guards stay its own: a private or reserved address, a disallowed scheme, embedded credentials, a TLS failure, a deadline, or a failure to fetch the site entry fails the import. A non-entry route that cannot be fetched (an HTTP error status, an oversize or empty response, a timeout, a client-rendered shell) is left out with a `static_site_importer_url_route_omitted` finding, and an asset that cannot be fetched keeps its source URL.

When a generated artifact contains full-document HTML, Static Site Importer routes document metadata, head content, styles, scripts, and page body fragments to the right WordPress destinations before calling the conversion stack. A `core/html` block in imported page content is therefore a materialization/conversion quality issue to fix in this stack, not a product-layer workaround to hide upstream.

## What It Does

- Accepts pasted HTML, one public HTML URL, a direct `.html` / `.htm` upload, or a ZIP containing a static-site folder with an `index.html` shell/chrome entry point.
- Allows ZIP/CLI source-site imports to include nested `.md` / `.markdown` content documents; `.mdx` is skipped with explicit diagnostics because MDX runtime components are not supported.
- Provides one WP-CLI importer, `wp static-site-importer import`, for pasted HTML, website files, ZIP archives, and public URLs through the canonical `static-site-importer/import` ability.
- Discovers readable sibling `*.html` files beside the selected entry file and recursive Markdown content documents under the source tree, then imports them as WordPress pages.
- Compiles static HTML fragments and Markdown content through Blocks Engine `ArtifactCompiler`.
- Stores converted page bodies on the imported WordPress pages as `post_content`.
- Generates a block theme with shared header/footer template parts, `core/post-content` templates, page patterns for reusable/reference artifacts, `theme.json`, `style.css`, and optional `assets/site.js`.
- Rewrites local `.html` links to the imported WordPress page permalinks.
- Creates deterministic `wp_navigation` posts for supported header/footer navigation and references them from generated template parts.
- Consumes producer-owned `explicit_refs/v1` navigation references, validates all declarations before writes, and binds IDs before page persistence. Full destinations, submenu structure and authored item presentation stay producer-owned; SSI performs no inline-menu signature matching. Navigation uses explicit post-type reconciliation, participates in rollback, and is exposed in `materialization_receipt.completed.navigation_entities`.
- Persists each producer-declared `synced_patterns` entity (`explicit_refs/v1`) as one synced `wp_block`, reconciled by identity on reimport, and replaces every `{{wordpress-site-plan:pattern:…}}` `core/block` ref with its post ID. Runtime entity bindings addressed to a pattern's `source_path` apply inside the pattern, an owner-edited pattern is not overwritten, and patterns are reported in `materialization_receipt.completed.synced_patterns`.
- Keeps imported pages native and editor-visible; page content belongs to WordPress pages while the generated theme owns shared chrome, background decoration, styles, scripts, and template wrappers.
- Keeps nested shared chrome references at their original positions inside page-owned layout containers. One template part owns the shared content while its occurrences preserve authored containment and order.
- Optionally activates the generated theme and assigns the imported `index.html` page as the front page when that page exists.
- Names the generated theme from the resolved imported site title unless the caller supplies an explicit name.
- Removes untouched WordPress installation content (`Hello world!`, `Sample Page`, and the sample comment) from fresh sites by default.

## Requirements

- WordPress 7.1 or later.
- PHP 8.2 or later.
- Composer dependencies installed with `composer install`.
- Node dependencies installed only when running the JavaScript block-validation smoke tests.

SSI pins `automattic/blocks-engine-php-transformer:0.33.0` from Packagist. The committed Composer lockfile records the immutable source reference for the `php-transformer-v0.33.0` release.

At runtime, SSI loads the transformer package from `vendor/` and compiles with `new ArtifactCompiler()->compile()`. Theme export still calls `blocks_engine_php_transformer_convert_format()`.

## Runtime Interfaces

Static Site Importer is an infrastructure plugin. Its supported runtime surfaces are WordPress abilities, WP-CLI commands, REST endpoints, and PHP consumer APIs. It does not register an admin screen or ship an end-user Gutenberg block.

## Browser Playground Demo

Open the separate demo interface in a disposable WordPress Playground site:

[![Try Static Site Importer in WordPress Playground](https://img.shields.io/badge/Try_Static_Site_Importer_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?php=8.5&blueprint-url=https%3A%2F%2Fautomattic.github.io%2Fstatic-site-importer%2Fplayground%2Flatest%2Fblueprint.json)

The release blueprint installs the infrastructure-only Static Site Importer package and a separate demo plugin built from `demos/playground-importer/`. The demo plugin owns the `static-site-importer/importer` block and consumes SSI's public REST contract to import into the disposable Playground site. The SSI plugin and its runtime package contain no demo UI or demo registration.

Testers can enter one public URL, upload static site files, choose a folder, upload a ZIP, or paste HTML. Figma upload is enabled only when the Playground runtime provides the optional zstd extension; all other source types remain available without it. Tagged blueprints and demo packages are published through the [Playground publication contract](docs/playground/publication-contract.md), while the README uses the browser-verified `playground/latest/blueprint.json` alias.

URL imports collect a complete canonical plan before applying it once to the existing Playground site. The demo's PHP.wasm-only network adapter resolves public A/AAAA records through Google's DNS-over-HTTPS endpoint and uses WordPress HTTP over Playground's browser/proxy transport. SSI retains public-address classification, redirect validation, response limits, and the inert script policy. Native WordPress installations continue to use SSI's IP-pinned transport. After successful materialization, the demo opens the imported homepage; the `/import/` page and **Move to WordPress.com** toolbar remain available. Failed imports stay on the importer with their error.

## Site Identity and Default Content

The imported site's resolved title is also the generated theme name, so a producer's generic package name does not replace the site identity. Callers can still pass `name` and `slug`. Developers can customize the final values with `static_site_importer_theme_name` and `static_site_importer_theme_slug`:

```php
add_filter( 'static_site_importer_theme_name', static fn ( string $name ): string => $name . ' Theme' );
add_filter( 'static_site_importer_theme_slug', static fn ( string $slug ): string => 'custom-' . $slug );
```

Imports remove untouched core seed content on sites where WordPress still reports `fresh_site`. Records are fingerprinted before page materialization and checked again before deletion, so edited or replaced content is preserved. Set the canonical import argument `remove_default_content` to `false`, pass `--keep-default-content` to WP-CLI import commands, or use the `static_site_importer_remove_default_content` filter to disable cleanup.

## REST Imports

`POST /wp-json/static-site-importer/v1/imports` normalizes `source` (`url`, `html`, `files`, `archive`, or `artifact`) and executes the canonical `static-site-importer/import` ability in the installed WordPress runtime. Product-specific interfaces and disposable preview orchestration belong in their owning product layers and consume the same ability contract.

URL intake rules:

- Every built-in URL import collects the bounded site through the resumable batch engine. It reads the origin's `/sitemap.xml`, follows same-origin HTML links, and collects directly referenced page assets and nested CSS assets.
- SSI owns collection, batch, deadline, asset, byte, pacing, and script-policy defaults. Hosts can adjust this policy with the `static_site_importer_url_batch_import_args` filter.
- `static-site-importer/import` accepts `{ source: { type: "url", url, import_id? }, operation }`. The first URL apply returns an opaque `import_id`; continuation supplies that ID in the same source envelope. SSI resolves the server-owned workspace and validates the URL, import options, and current user; no filesystem path is accepted or returned.
- URL collection uses the frozen static artifact policy: executable and data scripts are omitted with reason-coded provenance because the server-rendered output does not require them.
- All source HTML is preserved. On the block-theme path SSI does not classify a subtree as non-authored chrome; deciding what content is in scope belongs to the capture adapter and the Blocks Engine plan. Classic projection still extracts header/footer/background fragments from source HTML.
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
- Archive entries with absolute paths or `../` traversal segments are normalized before extraction. Entries that are not static content follow the content-only decision above.
- The entry-count and total-uncompressed-size limits reject the archive. An entry over a per-entry limit (uncompressed size or compression ratio) is skipped unread with a `static_site_importer_archive_entry_skipped` finding, unless it is the entrypoint or the redirects manifest, which fail closed.
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

The canonical ability uses `source.type` (`html`, `files`, `zip`, `url`, or `figma`) and `operation` (`plan` or `apply`). Figma sources accept a raw `figma_file`, scenegraph, or artifact bundle and normalize through the Blocks Engine Figma transformer before canonical materialization. Planning returns the canonical WordPress site plan, diagnostics, quality evidence, and source provenance without writing to the destination. Reference-backed sources use opaque `source.ref` values resolved only by the server-side `static_site_importer_resolve_source_reference` filter; ability callers never provide filesystem paths. All source HTML is preserved: SSI has no provider or platform axis and offers no option to strip source markup.

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

The host prints one `static-site-importer/import-cli-receipt/v1` object. Use `--report=/absolute/path/to/import-report.json` for the operator-owned report destination and `--max-steps=<count>` to cap continuation steps. By default the host continues while every step advances the import and stops after 16 consecutive steps without progress. `--single-step` is the internal fresh-runtime seam; host integrations invoke the command without it. `--url=` is a minimal ergonomic source argument.

The receipt is the single quality verdict. When the import's quality gate sets `fail_import` (for example `fail_on_quality` with failure reasons), the receipt is `status: "failed"` and `response.error` is `{ "code": "static_site_importer_quality_failed", "message": … }`, where the message names each failure reason with its count when available (for example `3 unsupported_html_fallback failures`), or the fallback-block count when no reasons are recorded; the import's evidence stays in `response`. A `completed` receipt never carries `fail_import: true`.

A `files` request-bundle source may point at a raw Blocks Engine capture directory (its `capture-receipt.json` has the `blocks-engine-capture/capture-receipt/v1` schema). SSI imports the receipt's `websiteRoot` and, when the request has no `source.metadata.reports`, forwards the receipt's `reports` (capture-root-relative sidecar paths) as the declared reports, read from the capture root and validated with the same safe-relative rules; declared reports the capture does not hold are skipped and symlinked ones are refused. An explicit `source.metadata.reports` always wins, and a receipt without `reports` forwards none.

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

- `style.css` and `theme.json` are plan-owned writes from Blocks Engine (`ThemeJsonProjection` and stylesheet assets). SSI materializes those files rather than re-deriving palettes from `:root`.
- `functions.php` enqueues frontend styles, editor styles, and optional generated `assets/site.js`.
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

## URL Loop Intake

The bounded URL entrypoint retains one fresh capture from a caller-configured
capture command, then passes that exact generated-artifact tree through SSI's
existing fixture intake. It never interprets fallback counts as solved-site
acceptance and does not run a matrix unless explicitly requested:

```bash
node tools/url-loop-intake.mjs https://quinn-fluid-demo.squarespace.com/ \
  --capture-command '["npx","--yes","--package=<package spec>","<bin>","{url}","--output","{output}"]' \
  --output-root /path/to/retained/quinn-capture \
  --run-matrix --static-site-importer /path/to/static-site-importer \
  --blocks-engine /path/to/blocks-engine
```

#### Capture configuration

The harness names no capture producer. The caller supplies it:

| Setting | Flag | Environment | Default |
|---|---|---|---|
| Capture command: a JSON argv array. `{url}` (the normalized source URL) and `{output}` (the retained capture directory) are substituted in every element and must each appear at least once. | `--capture-command` | `SSI_URL_LOOP_CAPTURE_COMMAND` | none: the run is refused with `capture_command_missing` |
| Expected receipt schema | `--capture-receipt-schema` | `SSI_URL_LOOP_CAPTURE_RECEIPT_SCHEMA` | `blocks-engine-capture/capture-receipt/v1` |

A flag wins over the environment. A bare `argv[0]` resolves against the bounded
child `PATH` (the configured Node directory plus the standard system
directories), and the recorded command is the absolute program that ran. The
command must write exactly one directory under `{output}` holding
`capture-receipt.json` and a `website/` tree.

To run with Data Liberation Agent, use **v0.27.0 or later**; earlier releases
write the retired `data-liberation/capture-receipt/v1` receipt and are blocked
with `capture_receipt_schema_unknown`. For v0.27.0 (commit
`2986ca1e386e994696d41a47e94edb8dd3665c43`, asset SHA256
`2611d686a804f0db20c1306fbfc807d8785a62040e462a555c2f02fb53cfedc3`):

```bash
export SSI_URL_LOOP_CAPTURE_COMMAND='["npx","--yes","--package=https://github.com/Automattic/data-liberation-agent/releases/download/v0.27.0/data-liberation-0.27.0.tgz","data-liberation","{url}","--output","{output}"]'
```

Pin an immutable release asset rather than a moving npm tag so repeated runs use
the same producer bytes. The operator then supplies only the URL on later runs.

The resulting `url-loop-handoff.json` records
derived SHA256 provenance for the normalized URL, receipt bytes, and retained
capture content, plus the capture receipt,
observed component identities (the capture identity is the configured argv, its
SHA256 and the expected receipt schema), normalized fixture, canonical matrix summary and
artifact references when requested, stage failures, and replay commands. A capture receipt must
declare the expected receipt schema, with `source.url` bound to the normalized
URL and a complete route summary (`routesCaptured === routesDiscovered`, with no
failed or skipped routes). Ambiguous artifact directories, partial captures,
missing files, and a missing capture command are blocked before SSI intake. A complete capture
without a requested matrix is `needs_evaluation`; matrix evidence is accepted only
when the selected fixture is explicitly `verified` in the canonical runtime
evidence summary. WordPress/editor evidence remains owned by the existing fixture
matrix and is required by solved-site promotion; this entrypoint never claims solved
status.

### Durable, bounded evaluation

`tools/url-loop-controller.mjs` turns one URL into a bounded, hands-off repair
loop (`homeboy/workflow/v1`) across SSI, Blocks Engine and a configured capture
producer. The operator supplies the URL; the loop opens upstream pull requests
until the source passes or a budget or typed blocker stops it.

The capture producer is operator configuration: the repository the loop
composes and repairs for capture, and the commands that build it, capture a URL
and compare a capture. Pass it as `--capture-producer '<JSON>'` or set
`SSI_URL_LOOP_CAPTURE_PRODUCER` on the runner. Placeholders are `{checkout}`
(the composed checkout), `{url}`, `{output}`, `{capture}` (the directory with
the Blocks Engine `capture-receipt.json`), `{stage}` (`capture` or
`materialization`) and `{candidate}` (the served WordPress origin). For example,
with Data Liberation Agent:

```json
{
  "repository": "data-liberation-agent",
  "checkout": "/path/to/data-liberation-agent",
  "remote": "https://github.com/Automattic/data-liberation-agent.git",
  "base": "main",
  "build": [["npm", "ci", "--no-audit", "--no-fund"], ["npm", "run", "build"]],
  "capture": ["node", "{checkout}/dist/cli.js", "{url}", "--output", "{output}", "--route-limit", "12", "--reference-sample", "all"],
  "compare": ["node", "{checkout}/dist/cli.js", "compare", "{capture}", "--stage", "{stage}"],
  "compare_candidate": ["--candidate", "{candidate}"],
  "failure_details": {"file": "{capture}/diagnostics.json", "pointer": "/failures"},
  "verify": ["npm test"]
}
```

`compare_report` (default `{capture}/compare/{stage}/report.json`) names the
report whose `pass` decides each comparison. Its contract is `pass`, `status`,
and optional `scores[]` entries with `pass` and `failures`; failing entries are
included in repair evidence. Optional `failure_details` identifies a JSON file
and pointer to an array of capture failure details, which are included in
repair evidence when available. `task_url` names the producer's tracker.

```bash
node tools/url-loop-controller.mjs start \
  --url https://housing-resolutions.com/ \
  --capture-producer "$(cat capture-producer.json)" \
  --task-url https://github.com/<owner>/<repo>/issues/<n> \
  --blocks-engine /path/to/blocks-engine \
  --wp-codebox-bin /path/to/wp-codebox \
  --max-iterations 3 --max-step-runs 20 --deadline 10h
homeboy workflow status <instance printed by start>
```

Each iteration runs four steps:

1. **`evaluate`** composes the candidate and measures it:
   - **Compose.** For each repository it checks out the public base (`main`;
     `trunk` for Blocks Engine; the configured base for the producer) and merges
     this instance's repair branches `url-loop/<instance>/<repo>-i<n>` in
     iteration order, so every iteration measures all earlier repairs. Base and
     merged SHAs are recorded; a conflict is the typed blocker
     `candidate_merge_conflict:<repo>:<branch>`.
   - **Capture.** It builds the composed producer, captures the live URL, finds
     the capture by its receipt and runs the `capture` comparison.
   - **Import.** It builds the SSI development package against the composed
     Blocks Engine (`tools/build-dev-package.mjs`) and imports the capture into
     a disposable WP Codebox Playground with `recipe-run`: WordPress 7.1,
     WP-CLI, Jetpack preinstalled for forms, and SSI driven through
     `import --single-step --state` because PHP.wasm has no subprocesses.
   - **Compare.** While Codebox serves the imported site, the `materialization`
     comparison measures WordPress against the capture.
 2. **`repair`** is one Homeboy Cook step with `foreach` over the routed repair
    items. It fans out up to three independent repository repairs; each Cook
    receives that repository's evidence, tracker, branch and verify commands.
    The `any` join lets the next evaluation run when at least one repair passes,
    even if another repository's repair fails. If every repair fails, the join
    fails the workflow instance. Repairs open ready-for-review PRs; nothing merges
    automatically.

**Verdict.** `accepted` requires a passing capture comparison, a passing
materialization comparison, `quality_pass`, and zero unsupported fallback, core
HTML, freeform and invalid blocks. This is the loop stop rule; SSI's fail-closed
solved-site promotion receipt remains the separate publication step. Failing
evidence with an owner is `findings`; missing or unroutable evidence is
`blocked`, which fails the step and the instance.

**Routing.** Failed capture routes (from the receipt) and capture-comparison
failures go to the capture producer; finding packets owned by `blocks-engine/*`
go to Blocks Engine; other finding packets, import failures and
materialization-comparison failures go to SSI. Each routed repository gets
`evaluations/<instance>/i<n>/repairs/<repo>.json` with its findings, the
composed component SHAs, stage reports, import quality and a sorted `scope`
array. Scope is derived from finding packet owners of the form
`<repository>/<component>` when that top-level component directory exists in
the composed checkout. Bare repository owners and failures without an owner do
not add scope.

The repair Cook prompt names the owning component paths (`scope_text`) and says
the fix belongs there: changes elsewhere do not address the finding. For a
scoped repair, its verify program first requires at least one changed path
under a scope directory, considering both committed changes against the
repository's pinned base and uncommitted working-tree changes, before running
the configured repository tests. Unscoped repairs run only their configured
tests.

**Options.** `--task-url` (or `SSI_URL_LOOP_TASK_URL`) names the tracker every
repair Cook works on; it is required unless every repository names its own
through `--ssi-task-url`, `--blocks-engine-task-url` and the producer's
`task_url`. `--ssi-verify` and `--blocks-engine-verify`
(repeatable) override the Cook gates, which default to `npm test` and
`composer --working-dir=php-transformer test` inside Blocks Engine's reference
PHP runtime (`php-transformer/tools/reference-runtime/run.sh`, the PHP and
libxml2 WordPress.com serves from), so a runner's own PHP build cannot change
the recorded serialized output the gate compares. `SSI_BLOCKS_ENGINE_PATH` and
`HOMEBOY_WP_CODEBOX_BIN` configure the Blocks Engine checkout and Codebox CLI on
a runner. Steps run with an empty environment plus the declared public
toolchain names (`PATH`, `HOME`, `XDG_*`, `PLAYWRIGHT_*`, npm and Composer
caches) captured where `start` runs. Evaluation children get a temp directory
owned by the iteration (`evaluations/<instance>/i<n>/tmp`), never an inherited
one. `--fix-timeout-minutes` (default 40) is each repair provider's base deadline;
Homeboy extends a provider still producing output to three times that.
`--fix-secret-env <NAME>` (repeatable) names an environment variable each repair
Cook asks Homeboy to hydrate for its provider (the Cook step's `secret-env`),
resolved from the runner's configured secrets; providers otherwise see a
filtered environment, so a credential such as a provider header key must be
named here.
`--instance <token>` forks an
independent loop for the same source. `workflow` instead of `start` prints the
generated definition for `homeboy workflow validate -` without admitting it.

## Product Handoff Contract

The product handoff contract is defined in `docs/product-handoff-contract.md` and locked by `tests/fixtures/product-handoff-contract/v1.json` plus `tests/smoke-product-handoff-contract.php`.

Owner readiness after materialization uses `static-site-importer/owner-handoff-evidence/v1`. It binds the canonical plan identity and materialization receipt hashes, composes existing parity/editability/media/provider/metadata/runtime receipts, and renders the same envelope as a user-facing report card. Missing mandatory evidence is an `evidence_gap`; it is never treated as a pass. `accepted_built_allowed` is false when the report contains hard failures or evidence gaps. See `docs/contracts/owner-handoff-evidence-v1.md`.

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

`test-manifest.json` is the canonical repository-wide test inventory. It classifies every executable test as standalone PHP, WordPress runtime, Node, browser/WP Codebox, or operator-only acceptance. Explicit `command` values are arrays of executable and argument strings. `npm test` runs the fast standalone PHP and Node projection; it reports the environment-heavy lanes as skipped. `npm run test:all` is the complete CI/reviewer command and runs configured runtime lanes while reporting operator-only acceptance commands explicitly. `npm run test:inventory` verifies that every executable test is declared once and that `standalone_php_test_paths` in `homeboy.json` remains the deterministic standalone-PHP projection used by Homeboy.

### PHP Smokes

PHP smokes are declared in `test-manifest.json`; run the standalone lane with `npm test` or the complete configured lanes with `npm run test:all`.

Running every `tests/form-*.php` and `tests/smoke-form-*.php` also includes the real-provider rendering test, `smoke-form-repeated-instance-ids.php`. Its WordPress runtime must have Jetpack installed, active, and its Forms module enabled (installing the plugin alone is insufficient):

```bash
wp --path="$STATIC_SITE_IMPORTER_WP_ROOT" plugin install jetpack --activate
wp --path="$STATIC_SITE_IMPORTER_WP_ROOT" jetpack module activate contact-form
for t in tests/form-*.php tests/smoke-form-*.php; do php "$t" || exit 1; done
```

Use a disposable test WordPress installation; these setup commands change its installed plugins and active modules.

### Error Classification

Every `new WP_Error(` and `throw new` in `includes/*.php` is classified in `tests/fixtures/error-classification.json`, and `php tests/smoke-error-classification.php` fails on any site that is not. Classes are `integrity` (paths, containment, permissions, digests, identity, producer contracts), `operational` (size, time, memory, runtime availability), `representability` (source content this layer cannot express, with a `unit` of `item`, `block`, `page`, `route` or `asset`), `propagated` (an error from another layer passed through) and `programmer`.

- A `WP_Error` with a literal code is registered by its code.
- A computed code, or any `throw new`, is registered as `<file>#<normalized expression>` and needs a comment on its line or the line above:

  ```php
  // ssi-error-class: integrity
  throw new RuntimeException( 'Artifact workspace directory cannot be a symlink.' );
  ```

  Use `representability:<unit>` for representability, for example `// ssi-error-class: representability:page`.
- Registry entries whose site no longer exists fail, so remove them with the code.
- `representability_pending` can only shrink. A new limit should degrade the smallest unit and record a finding instead of failing the import. When a pending site is converted, move its key to `representability_converted` with `finding_code`, `unit`, `owning_layer` and `proof_smoke`, a registered smoke that shows the finding reaching `Static_Site_Importer_Receipt_Projection::compose()`.

`php tests/smoke-error-classification.php --list` prints every site with its key as JSON lines.

### Loss Ledger Findings

The import report records each finding once, with a loss class and an acceptability. Classes come from `Static_Site_Importer_Diagnostic_Loss_Classes`. Producers report their own losses through `website/source-findings.json` ([`docs/contracts/website-artifact-evidence.md`](docs/contracts/website-artifact-evidence.md)).

| Acceptability | Loss classes | Meaning |
|---|---|---|
| `acceptable_conversion` | `native_conversion`, `editable_approximation` | Converted to editable blocks. |
| `acceptable_preservation` | `preserved_runtime_island` | Preserved as a bounded runtime island. |
| `accepted_reported_loss` | `page_compile_omitted`, `degraded_unit`, `omitted_unit` | A producer hit a representability limit confined to one unit. The import completes and the loss is reported. |
| `unacceptable_imported_output_defect` | `unsupported_loss`, `importer_materialization_bug`, `unrecognized_loss_class` | The imported output is defective. |

Degrade codes SSI and Blocks Engine own are classified by code, before any explicit `loss_class`, contract repair lane or heuristic. `unit` and `owning_layer` fill in only when the producer row names none. Other producers' rows carry their own `loss_class`, `unit` and `owning_layer` (see source findings below):

| Code | Loss class | Unit | Owning layer |
|---|---|---|---|
| `page_compile_omitted` | `page_compile_omitted` | `page` | `blocks-engine` |
| `editability_policy_degraded` | `degraded_unit` | `page` | `blocks-engine` |
| `html_include_budget_exceeded` | `degraded_unit` | `page` | `blocks-engine` |
| `html_include_directive_ignored` | `degraded_unit` | `page` | `blocks-engine` |
| `glyph_color_undecodable` | `degraded_unit` | `item` | `blocks-engine` |
| `wordpress_site_plan_unresolved_navigation_link` | `degraded_unit` | `item` | `blocks-engine` |
| `runtime_dependency_target_missing` | `degraded_unit` | `block` | `blocks-engine` |
| `wordpress_site_plan_colliding_asset_target` | `degraded_unit` | `asset` | `blocks-engine` |
| `wordpress_site_plan_omitted_link_declaration` | `omitted_unit` | `item` | `blocks-engine` |
| `runtime_entity_dropped` | `omitted_unit` | `item` | `blocks-engine` |
| `runtime_declaration_dropped` | `omitted_unit` | `declaration` | `blocks-engine` |
| `runtime_entity_binding_unanchored` | `omitted_unit` | `binding` | `blocks-engine` |
| `runtime_form_graph_rejected` | `omitted_unit` | `item` | `blocks-engine` |
| `runtime_declaration_rejected` | `degraded_unit` | `item` | `blocks-engine` |
| `document_head_attribute_dropped` | `omitted_unit` | `item` | `blocks-engine` |
| `document_head_element_dropped` | `omitted_unit` | `item` | `blocks-engine` |
| `static_site_importer_non_static_file_skipped` | `omitted_unit` | `file` | `static-site-importer` |
| `static_site_importer_non_static_page_dropped` | `omitted_unit` | `page` | `static-site-importer` |
| `static_site_importer_archive_entry_skipped` | `omitted_unit` | `file` | `static-site-importer` |
| `static_site_importer_url_route_omitted` | `omitted_unit` | `route` | `static-site-importer` |
| `font_face_unavailable` | `omitted_unit` | `asset` | `static-site-importer` |
| `omitted_artifact_files`, `omitted_artifact_file` | `omitted_unit` | `file` | `blocks-engine` |

`degraded_unit` keeps the unit with reduced fidelity; `omitted_unit` removes it from the import. Blocks Engine's file-drop codes (`file_limit_exceeded`, `artifact_file_too_large`, `artifact_total_too_large`, and the aggregate `artifact_inputs_rejected`) are re-owned as `omitted_artifact_files` / `omitted_artifact_file`; the rest of the site imports, and `dropped_artifact_files` stays a quality failure reason, so a `fail_on_quality` caller can still refuse the import. A `font_face_unavailable` row carries a `resolution` of `dropped` (the theme uses its fallback font) or `remote_import_retained` (the source's Google `@import` is kept); a font digest mismatch fails closed. Blocks Engine repair lanes `restore_compiled_document_identity` and `restore_projected_selector_binding` map to `unsupported_loss` and `editable_approximation`.

#### Source findings

A website artifact may carry `website/source-findings.json` (schema `static-site-importer/source-findings/v1`): the routes, pages, blocks, assets, stylesheets or profiles its producer dropped or degraded. At intake, `Static_Site_Importer_Source_Findings` removes the file from the site and adds one report row per finding, with the row's own `code`, `severity`, `unit`, `loss_class` (`omitted_unit` or `degraded_unit`), `owning_layer`, `location`, `message` and numeric `metrics`. None of these rows fails the import. A row outside SSI's vocabulary stays visibly `unrecognized_loss_class`. An artifact without the file imports unchanged; a file that cannot be read or fails validation adds one `source_findings_unreadable` warning. The contract, including the capture evidence names SSI reads from Blocks Engine's vocabulary, is [`docs/contracts/website-artifact-evidence.md`](docs/contracts/website-artifact-evidence.md).

#### Canonical finding fields

`Static_Site_Importer_Diagnostic_Projection::normalize_import_diagnostics()` gives every report row the same canonical fields. The mapping is additive: each producer field also stays on the row under its own name.

| Canonical field | Filled from, first non-empty |
|---|---|
| `code` | `code`, `diagnostic_code`, `type` |
| `severity` | producer `severity`, else derived from `type` |
| `unit` | producer `unit`, then the degrade-code table, else `''` |
| `location` | `source_path`, `path`, `url`, then Blocks Engine `context.source_path`. A nested `context.source_path` also fills a missing top-level `source_path`. |
| `message` | `message`, `summary`, `reason` (producer free text), `error_message` |
| `reason_code` | `reason_code`, `reason`, `error_code`, else `type` |
| `metrics` | numeric `metrics` entries. Blocks Engine editability `context.metric`/`actual`/`maximum` becomes `<metric>` and `<metric>_maximum`, and `omitted_count` is copied in. Empty map when none. |
| `owning_layer` | producer `owning_layer`, then the degrade-code table, then `static-site-importer` for a `static_site_importer_*` code or `blocks-engine` for a Blocks Engine `source` class, else `''` |
| `loss_class` | classification above |
| `acceptability` | derived from the final `loss_class` |

Single compiler diagnostics keep `unit`, `owning_layer`, `reason_code`, `source_path`, `path`, `url`, `selector`, numeric `metrics` and `omitted_count` through the compiler diagnostic bound.

#### Compact summary

`compact_summary` (`static-site-importer/import-metrics/v1`) carries `loss_class_summary` (every class), `loss_count` and `loss_counts` (classes whose acceptability is `accepted_reported_loss` or `unacceptable_imported_output_defect`), and `omitted_page_count`. Its `diagnostics` list keeps `code`, `location`, `url`, `path`, `value` and `metrics` along with the classification fields.

The list holds at most 50 rows. When the report has more, rows are kept by tier: imported-output defects, then accepted reported losses, then acceptable conversions and preservations, in report order within each tier. Kept rows are emitted in report order.

`php tests/smoke-wordpress-site-plan-materializer.php` runs outside WordPress and verifies that Blocks Engine's direct `ArtifactCompiler` output is consumed through `source_reports.wordpress_site_plan` v2 and materialized into the stable receipt contract.

The `wordpress-is-dead` smoke verifies the multi-page fixture, generated block-theme artifacts, internal-link rewrites, persistent navigation entities, source CSS preservation, editor style support, plan-owned `theme.json` / `style.css`, and selector fidelity across stored/rendered paths. The `mixed-source-site` smoke verifies an Astro-like source tree with `index.html`, nested Markdown content documents, explicit skipped-MDX diagnostics, report source counts, and generated page block markup.

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
