# Shared import checks

`evaluate.mjs` and `gates/accept.mjs` call `checks.mjs`. Parity runs the pinned
Data Liberation release through `npx --yes --package=<release tgz>` (as Studio
does), records the exact command and log, and requires evidence for every
captured route at 390/768/1440. Missing cells remain blockers; they are never
inferred from a sampled pass. Install that release's Playwright Chromium before
running. Capture evidence is copied into the output directory and never edited
in place.

Editor checks open imported pages, inspect parsed blocks, exercise selection,
List View, saved text and Media Library replacement, and restore post content.
A List View failure reports the group count, total block count, max depth, and
the deepest root-to-leaf chain with each level's className/anchor
(`list-view.mjs`), so the finding names the DOM chain to inspect in the capture.
Theme checks run authenticated browser REST requests and render a reversible
Global Styles restyle. These are disposable test imports: authentication resets
the first administrator's password, as the previous editor checker did.

`progress.mjs` compares block instance counts (not boolean predicate results).
Acceptance requires fewer own occurrences in the recorded cells, no growing or
new unrelated blocker, and no new parity failure or missing evidence. Output
contains `blockers.removed`, `added`, and `unchanged`, each with before/after
counts. Reduction predicates are diagnostics, not acceptance gates. The old
computed-style collateral and report-only editability gates were removed.

Run:

```sh
node --test tests/import-loop-progress.test.mjs
node tools/import-loop/evaluate.mjs --url <source> --wp-url <import> \
  --site <wordpress> --source <kept-source-parent> --out <pass-p1-eval> \
  --wp-cli 'php <wp-cli.phar>'
node tools/import-loop/gates/accept.mjs --url <source> --capture <capture> \
  --base-url <base> --candidate-url <candidate> \
  --base-site <wordpress> --candidate-site <wordpress> \
  --signature <finding-signature> --cells '<route/width cells JSON>' \
  --wp-cli 'php <wp-cli.phar>' --out <artifacts>
node tools/import-loop/confirm.mjs --triage <triage.json> \
  --blocks-engine <clone> --work <output> --after <next-live-findings.json>
```

`evaluate` accepts `--before <previous findings>` and automatically finds the
previous `-pN-eval` directory in the workflow's naming convention. Counts are
included in the workflow's stop-unchanged progress key.

## Remaining verification/coverage gaps

This implementation is not yet a completed implementation of issue #2861:

- The historical runs have not yet established the requested #5104 unchanged
  captured-dialog and #5174 7→5 end-to-end results.
- Frozen capture comparison may omit routes; missing route/width cells fail
  closed, but the CLI cannot synthesize missing frozen references.
- Theme restyling uses the authenticated Global Styles API, not the Styles UI;
  shared-header editing and source page-type template coverage need stronger
  behavioral tests. Asset proportionality still uses report metrics.
- Canvas parity currently compares rendered semantic text/style samples, not a
  screenshot; full controls coverage and image selection need browser fixtures.
- Non-transformer Cook gates still require repository-specific re-import support.
- `confirm --after` supports live counts, but the workflow does not yet invoke
  that live comparison automatically after the next import.
