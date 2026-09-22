# Playground Publication Contract

The GitHub Pages `gh-pages` branch is the browser-facing publication origin for
the blueprint, demo packages, and optional extension assets. The SSI plugin ZIP
comes from its canonical GitHub Release asset. Playground's Blueprint downloader
supports a CORS proxy fallback for that download; direct browser CORS access is
not a requirement for Blueprint URL resources. The optional extension loader has
a separate direct-CORS requirement and retains its Pages origin.

For every Homeboy-owned plugin release tag `<tag>`, the release workflow builds
from that tag and publishes these immutable files:

- `https://automattic.github.io/static-site-importer/playground/extensions/<tag>/static-site-importer-zstd-php8.5-jspi.manifest.json`
- `https://automattic.github.io/static-site-importer/playground/extensions/<tag>/static-site-importer-zstd-php8.5-jspi.so`
- `https://automattic.github.io/static-site-importer/playground/<tag>.blueprint.json`
- `https://automattic.github.io/static-site-importer/playground/<tag>/static-site-importer-playground-demo.zip`
- `https://automattic.github.io/static-site-importer/playground/<tag>/playground-to-wordpress-com.zip`

The versioned blueprint installs the infrastructure-only `static-site-importer.zip`
from GitHub Releases and the demo-only importer block ZIP from GitHub Pages. The
workflow downloads the release ZIP and verifies its release-published SHA-256 digest
before binding that digest into the blueprint. It builds the demo ZIP from
`demos/playground-importer/` and pins all three packages by SHA-256. The
migration archive is built from the
public codeload source archive for commit
`4688faf6e76071af3e8becd1500071f29b4ec63b`; the workflow verifies its fixed
SHA-256 before extraction and packages the upstream runtime files
(`playground-to-wordpress-com.php`, `includes-export.php`, `README.md`, and
`assets/`). `docs/playground/blueprint.json` is a release template: the workflow
replaces `{{RELEASE_TAG}}`, `{{PACKAGE_SHA256}}`, `{{DEMO_PACKAGE_SHA256}}`, and
`{{MIGRATION_PACKAGE_SHA256}}` while publishing it. README uses the safe
`playground/latest/blueprint.json` convenience URL without loading an optional
side module. Reproducible consumers use the tagged blueprint URL.

GitHub Pages is configured once at the repository level to publish the
`gh-pages` branch root. The workflow verifies that target before building,
retains previous tagged directories, and validates successful HTTP responses
before treating Pages as ready. It advances and browser-verifies the safe
blueprint alias independently. The optional extension is then verified in a real
browser before `playground/extensions/latest/` advances. A failed extension
verification therefore cannot take down the README demo. Publication can be
resumed for an existing tag through `workflow_dispatch`; publication commits are
idempotent. The workflow never creates or edits a release. Homeboy remains the sole
release owner.

Pages currently packages the separate demo and migration plugins because they
are not SSI runtime release artifacts. Moving those packages into Homeboy-owned
release assets is a separate packaging change; it is not necessary to mirror the
SSI ZIP. Existing tagged publications remain available for reproducible consumers.

## Disposable runtime policy

The blueprint installs a small host-policy MU plugin that disables
`static_site_importer_retain_response_artifacts`. The demo receives compact
results, diagnostics, page counts and receipt identity without retaining full
`import_report`, `materialization_receipt` or `result_details` JSON files after
success. This is independent of `write_theme_report_artifacts` (already false by
default). Continuation checkpoints and source payloads still follow their
execution retention policy; generated site files and the site manifest remain
available for the WordPress.com handoff.

## Browser acceptance

Before advancing the README alias, `npm run test:playground-inputs` boots fresh
browser instances for pasted restaurant HTML, a public URL, a nested six-page
Northstar Pantry ZIP, and the Brightwell Dental ZIP with a local SVG asset and
provider-backed forms. ZIP fixtures are archived from Blocks Engine commit
`12c93f35529c563257d144b511b0dbd30eb89ebe`. Set `PLAYGROUND_FIXTURE_REPO` to that
checkout to run the same matrix locally. Each success verifies theme activation,
page counts, retained importer access, and the WordPress.com migration screen;
negative cases cover private URLs and a ZIP without an entry document.

`Published Playground smoke` runs this read-only matrix against the exact README
URL daily and on manual dispatch. It never publishes or advances an alias.
For candidate review, `PLAYGROUND_PLUGIN_ZIP`, `PLAYGROUND_DEMO_ZIP`, and
`PLAYGROUND_BLUEPRINT_FILE` substitute local artifacts in the browser while
preserving package checksum verification. `PLAYGROUND_SOURCE_URL` and
`PLAYGROUND_SOURCE_ZIP` select a source for the single-launch verifier.
