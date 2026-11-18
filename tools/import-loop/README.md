# Import loop

`loop.json` is a Homeboy workflow that turns a public site into a WordPress
site and fixes what the import gets wrong, one pass at a time. Each pass:

1. **imports** the site: the first pass captures every route with the pinned
   capture client in `compare-client.json`, freezing reference evidence for each
   one so every route is compared, and every pass re-imports that frozen capture through the latest Static Site
   Importer release with the Blocks Engine transformer at trunk;
2. **evaluates** the import (the checks below) into `findings.json`;
3. **triages** each blocker into a GitHub issue on its owning repository;
4. **cooks** a fix for each issue, accepted only if the whole-site gate measures
   its finding reduced with nothing else regressed;
5. **confirms** each fix PR by re-measuring its exact head merged onto what
   the pass measured: a Blocks Engine head as the transformer, a Static Site
   Importer head as the importer, and a Data Liberation Agent head as the
   capture client, which captures the source again and compares with itself
   (`gates/client-candidate.sh`), and
6. **merges** it once it is green, at that head. Each Cook's fix is confirmed
   and merged as soon as that Cook settles (a `per_item` step), and open fixes
   no Cook works this pass are confirmed alongside the Cooks;
7. **scores** the pass into `<runs>/<slug>-scoreboard.jsonl`.

A refuted fix goes back to a Cook on its own branch. Each rework is recorded
on the issue and counts toward its Cook cap like a failed Cook, so a fix that
keeps failing reaches a design round and then an exception; an excepted
finding's refuted PR is closed.

It starts the next pass until no blocker is left with an issue to work on,
the blockers are unchanged for 3 passes, or 60 passes have run.

## Run the loop

Give it a URL:

```sh
LOOP_COOK_MODEL=<model route> tools/import-loop/run.sh https://example.com/
```

`run.sh` derives the slug from the URL's host and path, keeps runs in
`$LOOP_RUNS_DIR` (default `~/loop-runs`), and keeps its own Blocks Engine
clone at `<runs>/clones/blocks-engine`, fetching `trunk` into it each pass.
Reusing a slug continues that site's captures and issues; pass a second
argument for a fresh run. `LOOP_COOK_PLACEMENT` and `LOOP_WP_CLI` override
the workflow defaults (`auto` and `studio wp`), and any further arguments,
such as `--notification-transport` and `--notification-route`, go to
`homeboy workflow run`.

Each pass first pins the tools at the current commit of `$LOOP_TOOLS_REF`
(default `origin/main`) in the checkout it was started from (`tools.sh`), so a
merged change to the loop's scripts reaches every running chain on its next
pass. Set `LOOP_TOOLS_REF` to a commit to hold a run on fixed tools. A change to
`loop.json` itself takes effect on the next launch. When the capture client or
its arguments in `compare-client.json` change, the next pass captures the site
again.

`homeboy runs show <instance>` reports progress. Pass output lands in
`<runs>/<slug>-p<N>-{eval,triage,confirm}/`.

## Regression attribution

After evaluation, `bisect.mjs` runs alongside triage when `progress.added` is
non-empty and the preceding pass's `triage.json` records a different Blocks
Engine base. It searches non-release first-parent commits using the same
capture and importer zip, comparing only added signatures on their recorded
cells against the preceding evaluation's counts. The current pass supplies
the bad endpoint; the previous evaluation supplies the good baseline.

`<slug>-p<N>-bisect/bisect.json` records probes, signatures, the boundary and
culprit PR (including its diff stat), or an inconclusive narrowed range.
Build, import and unmeasured-check failures never name a culprit. The search
uses at most `ceil(log2(N)) + 1` probes, at most two simultaneous workers within
`checkHeads`' CPU-derived width, and a step deadline. A runs-directory cache
keyed by site and both bases avoids repeating a range, including inconclusive
ranges. Trunk history is assumed monotone for the added signatures.

Triage joins attribution before producing Cook prompts, posts retry-safe
`import-loop:regressed-by` comments on tracked findings and the culprit PR,
and adds the cause and diff stat to affected Cook guidance. Standalone
replays may override `--prev`, `--cur`, `--previous` and `--previous-findings`;
they also require `--findings`, `--source`, `--ssi-zip`, `--blocks-engine` and
`--work`. Normal workflow runs derive preceding artifact paths from `--slug`
and `--iteration`.

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

Quality checks hold the import to what developer-built block themes do
(`quality.mjs`). A page of 25 or more blocks may have at most 1.1 groups per
content block and a nesting depth of 10, and at most 10% of the site's block
style values may be literal rather than theme presets (CSS-wide keywords such
as `inherit` count as neither). Each threshold sits just past the maximum
`calibrate.mjs` measures across Twenty Twenty-Five, Twenty Twenty-Four, Ollie
and Frost; rerun it with more themes or `--site <imported WordPress>` to see an
import against them. Each route may load at most 90 KB of theme CSS beyond
Global Styles and core block styles; developer block themes load 1.5-36 KB,
and Extendable, the heaviest measured, 85 KB. A structure repeated three or more times on a page stays
an advisory, because developer pages repeat cards from one pattern too.

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
