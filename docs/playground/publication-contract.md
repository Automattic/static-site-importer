# Playground Publication Contract

The GitHub Pages `gh-pages` branch is the browser-facing publication origin for
Playground assets. GitHub Release Assets are not used by the browser because
their redirect target does not provide the CORS response required by
`resolvePHPExtension`.

For every Homeboy-owned plugin release tag `<tag>`, the release workflow builds
from that tag and publishes these immutable files:

- `https://automattic.github.io/static-site-importer/playground/extensions/<tag>/static-site-importer-zstd-php8.5-jspi.manifest.json`
- `https://automattic.github.io/static-site-importer/playground/extensions/<tag>/static-site-importer-zstd-php8.5-jspi.so`
- `https://automattic.github.io/static-site-importer/playground/<tag>.blueprint.json`
- `https://automattic.github.io/static-site-importer/playground/<tag>/static-site-importer.zip`
- `https://automattic.github.io/static-site-importer/playground/<tag>/static-site-importer-playground-demo.zip`
- `https://automattic.github.io/static-site-importer/playground/<tag>/playground-to-wordpress-com.zip`

The versioned blueprint installs the infrastructure-only `static-site-importer.zip`
and demo-only importer block ZIP from GitHub Pages. The workflow downloads the
release ZIP, verifies its release-published SHA-256 digest, and mirrors it to
the immutable Pages release directory. It builds the demo ZIP from
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
