# Canonical shared HTML chrome

The source is one site tree. Route HTML references shared fragments with
`<!--#include virtual="/parts/header.html" -->`. Blocks Engine owns resolution
and the canonical WordPress site plan; SSI persists that plan through its
existing materializer. No expanded authoring bundle or SSI include resolver is
added.

Acceptance depends on the artifact contract and compiler capabilities, not the
identity of the source producer. SSI adds no producer-specific dependency or
detection.

## Disposable acceptance

`tests/acceptance/run-shared-html-chrome.mjs` runs six independent Codebox sites:
bare and `website/` roots, each through artifact, files and ZIP ingress. Each
site checks whole and retained reference-backed compilation, persisted page and
part records, effective native template/page references, rendered DOM order,
metadata and styles, a native template-part REST edit reaching both pages, and
invalid references causing zero page writes. The focused fixture requires zero
fallback blocks.

The sites bootstrap a callback-free test theme. Oracle renders reset native
request-scoped style state and re-evaluate core theme support after an in-request
theme switch. Separate ingress cases use separate runtimes so theme callbacks
cannot leak across cases. Every site is destroyed on completion.

For release integration, mount the SSI checkout read-only and overlay the complete
dependency tree installed from its current `composer.lock`. `--released` requires
the installed compiler version to equal SSI's exact dependency pin and Reflection
verifies compiler classes come from vendor. No owning-source mount or prepended
autoloader participates in this proof.

```sh
WP_CODEBOX_CLI=/path/to/wp-codebox/packages/cli/dist/index.js \
SSI_RELEASED_ROOT=/path/to/released/static-site-importer \
SSI_SHARED_CHROME_EVIDENCE_ROOT=/path/to/existing/evidence-directory \
node tests/acceptance/run-shared-html-chrome.mjs --released
```

Use Node 26.10.0. The fixture pins WordPress 7.1.2 and PHP 8.3.32. `--baseline`
checks only source ingestion and explicitly reports compact acceptance as
`not_run`; it cannot silently substitute released classes for candidate proof.

For unreleased compiler work, omit `--released` and additionally set
`SSI_SHARED_CHROME_COMPILER_ROOT=/path/to/blocks-engine/php-transformer`.
That mode prepends a test-only owning-source loader and Reflection verifies all
compiler classes came from that source. Caller-owned anonymous PayloadReader
implementations are not compiler classes. Source-override proof remains separate
from release integration.

## Evidence

All six candidate cases passed on 2026-10-06, including native edits, staged
materialization, typed invalid-reference errors and zero fallback blocks. The
runtime result must contain `shared-chrome-acceptance-passed` and Codebox
`success: true`. The runner retains each result, recipe and logs under the
explicit evidence directory. The tests are operator-only in `test-manifest.json`.

All six **released dependency** cases passed on 2026-10-07 with SSI on current
main's locked Blocks Engine 0.36.1, WordPress 7.1.2 and PHP 8.3.32. The exact
installed version matched the pin and every compiler class came from vendor.
No source override was mounted. Native editing, whole/staged materialization,
invalid-reference rejection before writes and zero fallback blocks passed for
artifact/files/ZIP ingress under both roots.

## Rollout

Blocks Engine's include support is released and current SSI pins version 0.36.1.
Run `composer install` against the lockfile, then run the six-case `--released`
proof before shipping. Ship the consumer changes before the producer starts
emitting compact references. The
test-only source override is verification machinery, not production integration.
