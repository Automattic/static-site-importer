# Generated-host add-page evidence (#2810)

Measured on **homeboy-lab**, disposable WordPress 7.1 with SQLite, 1440×900 browser viewport. The current Blocks Engine checkout has **71 fixture directories**, not the 34 in the original request. ZIPs were excluded. Each fixture first created and activated its generated theme, then imported the entry document again with a new filename and slug using `existing_theme`.

## Primary probe comparison

| Generated host run | Fixtures imported | Added pages | Unregistered | core/html | Freeform | core/missing | Editor invalid / measured pages |
|---|---:|---:|---:|---:|---:|---:|---:|
| Change | 71/71 | 90 | 0 | 14 | 0 | 0 | 0 / 84 |
| origin/main | 14/71 | 31 | 0 | 2 | 0 | 0 | 0 / 29 |

The baseline refused 57 fixtures with `unsupported_persisted_block`. The change's first run of `20-switchback-woocommerce-extra-hard` could not install Jetpack during **host creation**, leaving Twenty Twenty-Five active; a clean retry created the generated host and added its page successfully. The table uses that retry. Non-HTML source formats in some fixtures can produce additional pages, so page counts exceed fixture counts.

| Run | Visual pairs measured | Mismatch min | Median | Max |
|---|---:|---:|---:|---:|
| Change | 85 | 0.0478 | 0.4115 | 0.9638 |
| origin/main | 29 | 0.0495 | 0.5094 | 0.9451 |

**Limits:** screenshot and editor timeouts remain explicit probe errors, not passes. Six added pages lack editor measurements. High visual mismatches remain: this slice fixes runtime admission, not Global Styles' omitted keyframes/fonts/nesting or source chrome. Multi-page source screenshot mapping was improved after this run; these visual numbers are exploratory, not a visual acceptance threshold.

Raw per-fixture results, requests, screenshots, diffs and summary JSON are retained on homeboy-lab under `/tmp/ssi-evidence-change-{0..7}` and `/tmp/ssi-evidence-main-{0..7}`; the retry is `/tmp/ssi-retry20`. Shards used distinct ports and the same hash-pinned download cache. The harness accepts `FIXTURE_ROOT`, `OUT`, `PORT`, and `PLUGIN_ROOT` for reproduction against a baseline worktree. `RUN_SMOKE=1` also runs the WordPress acceptance tests during provisioning.

## End-to-end runtime ownership

`tests/smoke-existing-theme-runtime.php` exercises the public ability across fresh CLI requests on a small fixture:

| Stage | Host block directories | Result |
|---|---|---|
| Generated paragraph-only host | none | Original runtime bytes snapshotted |
| Add description-list page | `description-list` | Page uses generating host's namespace |
| Re-import page | `description-list` | Runtime bytes unchanged |
| Remove import | none | Every runtime file hash equals original snapshot |

The added files are `ssi-runtime/blocks/description-list/*`; only `companion.json` changes outside that directory. The existing-theme Global Styles acceptance test also passed on the same disposable runtime, including unchanged Twenty Twenty-Five/plugin/mu-plugin hashes and owner CSS preservation.

Related issue: https://github.com/Automattic/static-site-importer/issues/2810

AI assistance: OpenAI coding assistant (Pi model alias `cook-heavy`) via Pi implemented the runtime fold-in, probe, tests and this evidence report, and ran local verification. The harness does not expose a more specific model identity.
