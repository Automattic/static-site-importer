# Import loop

`loop.json` is a Homeboy workflow that turns a public site into a WordPress
site and fixes what the import gets wrong, one pass at a time. Each pass:

1. **imports** the site: the first pass captures it with `studio create --from`,
   and every pass re-imports that frozen capture through the latest Static Site
   Importer release with the Blocks Engine transformer at trunk;
2. **evaluates** the import (the checks below) into `findings.json`;
3. **triages** each blocker into a GitHub issue on its owning repository;
4. **cooks** a fix for each issue, accepted only if the whole-site gate measures
   its finding reduced with nothing else regressed;
5. **confirms** each fix PR by re-measuring its exact head;
6. **merges** confirmed PRs once they are green, at that head;
7. **scores** the pass into `<runs>/<slug>-scoreboard.jsonl`.

It starts the next pass until no blocker is left with an issue to work on,
the blockers are unchanged for 3 passes, or 60 passes have run.

## Run the loop

Run it from a checkout that stays on `main` while it runs:

```sh
cd tools/import-loop
homeboy workflow run "$PWD/loop.json" \
  --input url=https://example.com/ \
  --input slug=example \
  --input loop_dir="$PWD" \
  --input runs_dir="$HOME/loop-runs" \
  --input blocks_engine="$HOME/loop-runs/example-be" \
  --input cook_model=<model route>
```

- `slug` names the run's directories, issues and scoreboard; use a new slug
  for a fresh run.
- `blocks_engine` is a clone of Blocks Engine with an `origin` remote; each
  pass fetches `trunk` there and imports with that commit's `php-transformer`.
- Optional: `wp_cli` (default `studio wp`) and `cook_placement` (default
  `auto`). Add `--notification-transport` and `--notification-route` for pass
  notifications.

`homeboy runs show <instance>` reports progress. Pass output lands in
`<runs_dir>/<slug>-p<N>-{eval,triage,confirm}/`.

## Shared import checks

`evaluate.mjs` and `gates/accept.mjs` call `checks.mjs`. Parity runs the pinned
Data Liberation release through `npx --yes --package=<release tgz>` (as Studio
does), records the exact command and log, and requires evidence for every
captured route at 390/768/1440. Missing cells remain blockers; they are never
inferred from a sampled pass. Install that release's Playwright Chromium before
running. Capture evidence is copied into the output directory and never edited
in place.

Editor checks open imported pages, inspect parsed blocks, exercise selection,
List View, saved text, and bounded Media Library replacement of every non-inert
image, and restore post content. Each image probe reports its own finding,
named by the image's src path or anchor, so a Replace failure names its image.
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
Parity findings are split by the scorer's failure class (title, link, motion,
text position, and any others it emits), each class its own finding, signature,
and owner — title is the imported site's identity, so it is owned by
`static-site-importer` while the rest stay with `blocks-engine`. A fix to one
class moves its own count; a cell without measured scores stays one unverified
finding.

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
  screenshot; full controls coverage and browser fixtures for the per-image
  replacement probes are still missing.
- Non-transformer Cook gates still require repository-specific re-import support.
- `confirm --after` supports live counts, but the workflow does not yet invoke
  that live comparison automatically after the next import.
