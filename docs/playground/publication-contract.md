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

The versioned blueprint installs `static-site-importer.zip` from the same GitHub
Release tag. `docs/playground/blueprint.json` is a release template: the
workflow replaces `{{RELEASE_TAG}}` while publishing it. The
`playground/extensions/latest/` and `playground/latest/`
locations are convenience copies updated only after the immutable tag files are
published. README uses those convenience URLs; reproducible consumers use the
tagged URLs. This avoids combining a mutable `main` blueprint with released
plugin or extension assets.

The workflow provisions GitHub Pages from `gh-pages` when it is absent, retains
previous tagged directories, and verifies the immutable launch in a real browser
before advancing either `latest` alias. It then verifies the exact README launch
URL. The workflow never creates or edits a release; Homeboy remains the sole
release owner.
