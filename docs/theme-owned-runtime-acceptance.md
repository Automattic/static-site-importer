# Generated-theme runtime ownership (#2014)

Generated-theme imports package generated blocks, metadata, audited renderers,
editor assets, preserved islands, form presentation, internal links, source-route
redirects and external metrics under `ssi-runtime/` in their generated theme.
`functions.php` loads `ssi-runtime/runtime.php` through WordPress's theme-file API.
The loader is also valid when the existing batch-bootstrap primitive includes it
from a subdirectory. Switching themes ends this runtime lifecycle.

Existing-theme imports keep their explicitly destination-owned plugin. They do
not write PHP into the destination site's existing theme. WooCommerce and Jetpack
remain independent provider dependencies; moving the SSI runtime does not replace
their entity storage or submission handling.

## Contract and security

The Blocks Engine producer contract is still
`blocks-engine/wordpress-companion-plugin/v1`. The producer name is not an
instruction to install a plugin. SSI selects the owner, independently of payload
fields such as `mu_plugin`. Both destinations reuse the content-only validator,
audited renderer dispatch, JSON-backed data wrappers and script dependency
manifests. No source-authored PHP is admitted.

The theme package is composed into the resolved plan before mutable destination
preflight. Its writes use the existing conflict checks, hash verification,
reconciliation identities and rollback journal. Before editor admission, the
materializer rebuilds the package from validated data, verifies every projected
runtime file against the rebuilt bytes, journals and writes the package, then
registers the metadata blocks. Exact owner type and runtime path are required to
reuse a registered block name. This also applies to page-ready imports before
theme activation. The package remains independent of SSI and the compiler at
runtime. Receipts use `completed.theme_runtime`; exports identify the theme owner
under `provenance.materialized_from.runtime_owner`.

## Disposable Lab acceptance contract

Run on Lab with Docker, PHP, Node, Composer dependencies, npm dependencies and
Playwright Chromium available. Never run against a host WordPress installation.
The runner owns two disposable WordPress/MySQL volumes and cleans them on exit.

The standalone suite's core fixture is WordPress **6.8.3**, pinned to
WordPress/WordPress commit **ba9e7f97f08a7fbb88fbfa35641bcc230500b37a**.
`tools/prepare-wordpress-core-fixture.sh` downloads this immutable source archive
into the existing harness-supported `vendor/wordpress-core` location. `npm test`
prepares it before standalone PHP tests and sets `STATIC_SITE_IMPORTER_WP_ROOT`
to its absolute checkout-relative path, so isolated HOME directories do not
change resolution. An explicit `STATIC_SITE_IMPORTER_WP_ROOT` remains authoritative
and fails if unavailable; tests are not skipped or weakened. Preparation reads
core source only and does not bootstrap a site or database. An incomplete existing
fixture directory is rejected rather than overwritten.

Exact commands:

```sh
bash tools/prepare-wordpress-core-fixture.sh
npm test
npm run test:runtime-package
SSI_EDITOR_EVIDENCE_DIR=/tmp/ssi-theme-runtime-2014-evidence npm run test:editor-companion-acceptance
```

The editor gate retains the robust canonical export/digest verification, second
site import, saved content, provenance, owner inventory and HTTP redirect checks
from the upstream companion acceptance. It adds assertions that no generated
plugin/MU-loader or active companion option exists; theme-owned block metadata
and assets survive importer deactivation; provider presentation hooks execute;
Gutenberg validates, edits, saves and reloads the blocks (with a genuinely invalid
negative control); and source/import canvas pixels match with zero changed
pixels. The real browser checks the authored canvas script's paint, successful
theme script URLs and native internal-link target before export and after SSI
deactivation on both sites. Visual evidence is bounded to the named canvas
runtime region, not a claim of whole-site visual parity. Provider evidence covers
the audited presentation hook, not a Jetpack submission or WooCommerce mutation.
Both import receipts must retain zero fallback.

Evidence includes `runner.log`, `acceptance-command.txt`, source commit/change,
retained source request, runtime image digests and versions, import reports,
Gutenberg screenshots/save responses, canonical export envelope and digests,
second-site receipts, runtime inventories, HTTP headers, paired runtime screenshots,
pixel diffs and browser script/link observations. Keep the evidence outside the
repository. Controller-owned gates remain the authority for PR eligibility.

## Attempt observations and evidence boundary

The implementation starts from fresh upstream `1a51ea04`. The issue and released
producer contract were inspected. Static PHP syntax, Node syntax, Bash syntax,
test-inventory validation and `git diff --check` were checked in this isolated
attempt. These are source observations, not final gate results.

The attempt could not execute Lab runtime checks: `ssh -o BatchMode=yes -o
ConnectTimeout=10 lab true` failed because `lab` could not resolve; `homeboy runner
list` exposed only `local`; `homeboy ssh list` exposed no SSH targets. Thus no Lab
`npm test`, runtime-package or Docker acceptance result, screenshots or runtime
artifact directory is claimed here. The acceptance paths above are the intended
controller run paths, not observed artifacts. Real runtime verification and any
resulting corrections remain required before review readiness.

Implemented with OpenAI **gpt-6.1-sol**, via **OpenCode** tools in an isolated
**Homeboy** attempt. Homeboy owns harvesting, authoritative gates and publication.
