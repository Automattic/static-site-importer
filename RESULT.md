# Editor Saved-Image Probe Result

## Change

`editor_saved_image` (tools/import-loop/editor.mjs) now runs the Media Library
Replace flow on every non-inert `core/image` up to a bounded five per page,
instead of only the first. Each probe is its own finding whose subject names
the image — the src path on the site, else the anchor href, else the probe
position — so a Replace timeout can no longer be scored anonymously against an
unrelated fix (the `ba5a797c79bc` collateral that refuted the header template
part on Automattic/blocks-engine#5744). A failed probe dismisses a leftover
media modal before the next one, and the naming lives in the pure helper
tools/import-loop/image-subject.mjs with unit tests.

## Verification

- `node --test tests/import-loop-image-subject.test.mjs` passed: 3 tests.
- `node --test tests/import-loop-progress.test.mjs
  tools/reduce-finding/import-loop.test.mjs` passed: 11 tests.
- `npm test` selected 238 tests: 234 passed; 4 failed only on transient
  external fetch timeouts (ETIMEDOUT to wordpress.org asset hosts) and all
  four pass when re-run individually.
- `npm run test:inventory` passed.
- Homeboy review lint passed (PHPCS, ESLint, PHPStan) on the changed workspace.

## Acceptance (sdnunca trunk import)

- Fresh trunk re-import of the frozen sdnunca capture: Blocks Engine trunk
  `609c9b3f` (php-transformer v0.45.593), Static Site Importer release
  `v1.34.10`, five routes served at `http://127.0.0.1:47333`
  (loop-runs/sdnunca-2957-p1).
- The evaluate step with this change named every failing image on `/`:
  `img:/wp-content/uploads/2026/11/image-989824.avif`,
  `img:/wp-content/uploads/2026/11/image-989824-1.avif`,
  `img:/wp-content/uploads/2026/11/image-989840-1.avif`, and a positional
  `img#2` (an image block with neither src nor anchor), plus three named
  images on `/about/`. The old first-image-only probe reported nothing on this
  import — its single probe passed on both routes — so these per-image
  failures were invisible before.
- Post content was restored after the probes: original image references
  intact, no edit markers, no leftover restore files. Every named subject
  matches a real image in that page's `post_content`.
- Findings: loop-runs/sdnunca-2957-p1-eval/findings.json; import receipt:
  loop-runs/sdnunca-2957-import.log.
