# Issue 1997: coupled content-policy repairs

Attempt-local observations only. Homeboy owns the authoritative final gates.

## Source relationship and change boundary

- Immutable base: `d8e4c5be867bf87fc1525e6692e6d46cba311004`.
- Inspected retained implementation `46a141d6` for reader-threading evidence; no cherry-pick or HEAD change.
- Runtime repair: HTML policy uses actual `WP_HTML_Tag_Processor` token/comment APIs. Completed HTML tag syntax (including both quoted attributes and unquoted residue) and core-recognized HTML comments are inert. Bogus comments/processing instructions, document text, and raw-text bodies are scanned. Marker-bearing incomplete input fails closed. Other textual formats retain the raw scan. Encoded examples are never promoted to raw markers by decoding.
- Runtime repair: canonical normalization, direct freeze, and compilation policy receive the owning reader. Text references use the existing retention schema/aliases and digest verification, with a mandatory integer byte count bounded by the compiler hard cap (or the smaller redirects cap). Malformed declarations remain visible to policy instead of disappearing during normalization. Media is not dereferenced by content policy.
- Freeze lifecycle: policy's verified-byte consumer publishes textual bytes directly into the existing immutable workspace. Retention skips those exact admitted contracts. The direct regression checks one reader call for scanning plus freezing; the artifact remains reference-backed.
- Regression fixtures are neutral, shortened representations of the reported quoted `data-code` tutorial example and active PHP counterparts. They exercise real inline, filesystem, ZIP extraction, and ZIP-reference readers. Direct and website-input source-normalizer stubs were replaced with the real normalizer; the direct ZIP shape stubs were replaced with a physical archive.

## Runtime and verification capability

WordPress **6.8.3** core HTML API was downloaded from `https://wordpress.org/wordpress-6.8.3.tar.gz` into attempt scratch and copied into ignored `vendor/wordpress-core` for the original observations. The follow-up gate replay exposed that ignored dependency: `php tests/smoke-content-only-policy.php` exited before assertions because core was missing. The eight required upstream PHP files and license are now included verbatim in `tests/fixtures/wordpress-core` as an offline, test-only fallback. Tests still prefer the existing `STATIC_SITE_IMPORTER_WP_ROOT`, `vendor/johnpbloch/wordpress-core`, and `vendor/wordpress-core` conventions, and fail explicitly for an unavailable or incompatible selected core. No site/database bootstrap is used. The original installed compiler reported **v0.35.0**.

The website-input compilation regression verifies admission by reaching the real compiler-capability boundary (`static_site_importer_missing_transformer`) in its intentionally compiler-free smoke runtime. Rejection cases must stop earlier with the exact source-policy error. CLI/direct smokes separately exercise the installed compiler and durable continuation. These checks do not establish live site materialization, browser serialization, or full public-capture parity.

## Before observations

The following command runs the same neutral policy regressions against the original policy class, without changing files or HEAD. This substitutes only the policy class, not the whole import pipeline:

```sh
BASELINE=HEAD php -r 'define("ABSPATH", getcwd() . "/"); require "includes/class-static-site-importer-runtime-capabilities.php"; $baseline = shell_exec("git show " . escapeshellarg(getenv("BASELINE") . ":includes/class-static-site-importer-content-policy.php")); eval(substr(str_replace("Static_Site_Importer_Content_Policy", "SSI_Baseline_Content_Policy", $baseline), 5)); $test = file_get_contents("tests/smoke-content-only-policy.php"); $test = str_replace(["Static_Site_Importer_Content_Policy::", "__DIR__"], ["SSI_Baseline_Content_Policy::", var_export(getcwd() . "/tests", true)], $test); eval(substr($test, 5));'
```

Result: **exit 1, 19 failing assertions**: six inert-context examples, missing/non-reader handling, seven invalid reference shapes/counts, digest/size mismatch, authoritative reference versus inline decoy, and unreadable filesystem payload.

Repeated the identical command with `BASELINE=46a141d6`. Result: **exit 1, 10 failing assertions**: core's `--!>` comment ending, bogus/XML comments, SCRIPT/STYLE tag-looking strings, SCRIPT comment-looking content, scalar reference, absent bytes, over-cap bytes, and reference versus inline decoy. Thus the reviewed raw-text and malformed-reference corrections have fail-before evidence against the retained implementation too.

## After observations

```sh
php tests/smoke-content-only-policy.php
php tests/smoke-cli-import-continuation.php
php tests/smoke-direct-artifact-import.php
php tests/smoke-website-artifact-import-input.php
git diff --check
```

All exited **0**. Outputs: content-only policy passed; CLI passed **211 assertions**, locked compiler v0.35.0; direct artifact import passed; website artifact import input passed **241 assertions**; diff check clean.

Additional standalone observations, all exit **0**:

```sh
php tests/smoke-rest-import.php
php tests/smoke-source-normalizer.php
php tests/smoke-canonical-import-ability.php
php tests/smoke-rest-url-import-helpers.php
php tests/smoke-compiler-file-limits.php
php tests/smoke-url-site-collector.php
php tests/smoke-html-runtime-capabilities.php
php tests/smoke-artifact-run-primitives.php
php tests/smoke-companion-plugin.php
php tests/smoke-companion-plugin-js.php
git diff --name-only -- '*.php' | xargs -n 1 php -l
php -l tests/fixtures/core-html-api.php
php -l tests/fixtures/content-policy-intakes.php
npm run test:inventory
```

The site-only `smoke-rest-import-normalization.php` was attempted as standalone PHP and exited 1 at its ABSPATH prerequisite; it was not run in a site. No live runtime was touched.

## Public-source observation

A fresh HTTP fetch of the cited tutorial returned **79,510 bytes**, SHA-256 `8dc9534332721f02d28346b21f6c4a2a350484aa5edcc4e9246360cfaeffa245`. Its PHP `data-code` snippet is HTML-encoded (`&lt;?php`), so both the old raw scan and the repaired scan accept those fetched bytes. This is not the retained, reserialized literal-attribute capture: that replay remains parent-owned. The neutral literal-attribute fixtures preserve the reported discriminator explicitly.

## Independent current-core review

The parent replayed the retained literal-attribute capture on WordPress **7.1.3** through directory/reference, inline and staged ZIP intake; all three accepted the tutorial. The parent also detected that core 7.1 emits a PHP opener as `#processing-instruction`, whereas the attempt's 6.8.3 tokenizer emitted a bogus comment. Before correction, running the content-policy regression with `STATIC_SITE_IMPORTER_WP_ROOT` pointing to the 7.1.3 runtime failed `core-tokenizer:outside-php`, `core-tokenizer:inert-then-active` and `reference-authoritative-over-inline-decoy`. The policy now scans the processing-instruction target as part of the original opener, with explicit uppercase and target-prefix regression cases. All four focused PHP gates and `git diff --check` passed after that correction on the 7.1.3 core source. Independent live policy probes rejected real PHP, tag-shaped PHP in SCRIPT/STYLE, scalar references and references without byte counts, while accepting the quoted tutorial example.

## Full retained-capture import proof

The DLA **0.19.3** retained capture of the public source was packaged as a ZIP, SHA-256 `be26817f82f400c49a06947ac862a66d8b005cfa085f98a34811cdf414916ce7`. Three fresh Studio sites used WordPress **7.1.3** and PHP **8.4**:

| Input/package | Observed result |
| --- | --- |
| Capture ZIP / published SSI **1.24.25** | Failed immediately with `static_site_importer_executable_source_rejected` on `website/2024/01/08/part-2-building-your-training-data-for-fine-tuning/index.html`. |
| Same capture ZIP / repaired development package | Completed **26/26** pages in **49 seconds** of import, zero reported fallback blocks. |
| Capture directory / same repaired package | Completed **26/26** pages in **43 seconds** of import, zero reported fallback blocks, **59** retained payload references. |

The repaired package used SSI base `d8e4c5be867bf87fc1525e6692e6d46cba311004` plus the reviewed policy diff and pinned Blocks Engine **0.35.0**, revision `0ae8e429f98d`; package SHA-256 `7e738e406d31ec0d6e151fe4f170e6258ca1ed356a0dbbc60699175f5325e4fa`.

Reproduction recipe (paths are operator-provided):

```sh
npm run build:dev-package -- --blocks-engine-path /path/to/blocks-engine --blocks-engine-ref php-transformer-v0.35.0 --output-dir /path/to/packages
studio create --from /path/to/capture.zip --path /path/to/fresh-zip-site --static-site-importer-path /path/to/repaired-package.zip --skip-browser --skip-log-details
studio create --from /path/to/capture/website --path /path/to/fresh-directory-site --static-site-importer-path /path/to/repaired-package.zip --skip-browser --skip-log-details
```

These results establish the content-policy repair and full materialization through both transports. They do not establish visual parity or a production deployment. The public source already has missing media, and visual/editor findings remain separate work.

## Contribution

Implemented and verified by **OpenAI gpt-6.1-sol via OpenCode**: contract tracing, core API inspection, scoped runtime changes, physical reader/archive regressions, before/after probes, and bounded checks. Homeboy/parent owns candidate harvesting, commits, authoritative gates, and independent public-capture review.
