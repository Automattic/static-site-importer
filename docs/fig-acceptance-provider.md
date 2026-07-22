# Figma Acceptance Provider

`tools/fig-acceptance-provider.mjs` adapts a completed Blocks Engine
`figma-fixture-matrix.php` summary and the SSI fixture-matrix result. It copies
the Figma-owned stages from `acceptance_readiness.stage_paths`, validates their
fixture/source identities, and re-homes every reference under the evaluator
artifact root. It does not consume `acceptance_evidence` or reinterpret metrics.

Run `npm run fig-fixture-e2e -- --blocks-engine /path/to/blocks-engine --fixture /fixtures/a.fig --fixture /fixtures/b.fig --fixture /fixtures/c.fig --run` first. For each completed fixture, compose the provider with the paths emitted by that command:

```sh
npm run fig-acceptance-provider -- \
  --fig /fixtures/a.fig --fixture-id a \
  --fixture-output /artifacts/acceptance/fixtures/a \
  --transform-summary /artifacts/fig-fixture-e2e/figma-transform/summary.json \
  --matrix-result /artifacts/fig-fixture-e2e/ssi-matrix/static-site-fixture-matrix-result.json \
  --matrix-output /artifacts/fig-fixture-e2e/ssi-matrix \
  --site-plan /artifacts/site-plans/a.json \
  --provider-identity static-site-importer@COMMIT \
  --runtime-identity wp-codebox@VERSION \
  --html-wordpress-mobile-parity html-wordpress-mobile.json \
  --figma-wordpress-desktop-parity figma-wordpress-desktop.json \
  --figma-wordpress-mobile-parity figma-wordpress-mobile.json
```

The adapter owns the `.fig`, transform summary, matrix result, and matrix output
arguments above; `run-fig-fixture-e2e.mjs` emits all of those locations in its
summary. The deployable site plan remains an explicit input because the bounded
matrix result is not the deployable artifact. Provider/runtime identities and
the three named downstream parity inputs are external because current workflows
do not emit them.

Each named parity file must use this schema. It identifies the unavailable stage
rather than disguising it as aggregate acceptance evidence.

```json
{
  "schema": "static-site-importer/fig-acceptance-parity-input/v1",
  "stage": "html_wordpress_mobile_parity",
  "source_screenshot": "/absolute/source.png",
  "rendered_screenshot": "/absolute/rendered.png",
  "diff_report": {
    "metrics": { "pixel_difference_count": 0, "geometry_difference_count": 0 }
  }
}
```

Blocks Engine supplies both Figma-to-HTML stages and responsive selection
directly from its versioned acceptance-readiness projection. SSI supplies
`html_wordpress_desktop_parity` directly from each fixture's
`visual_parity_artifacts`: `source_screenshot`, `imported_screenshot`,
`visual_diff`, and `metrics`. Mobile HTML-to-WordPress and both end-to-end stages
are unavailable from current SSI output, so omission fails with the exact stage.

The emitted stage files use
`blocks-engine/figma-wordpress-stage-evidence/v1`, with `fixture_id`, `stage`,
`source_sha256`, `status: "passed"`, and stage-specific metrics/references.

## Direct three-Fig run

Pass `--acceptance-config=/path/to/config.json` to `fig-fixture-e2e` to compose
all fixture fragments and run Blocks Engine's production evaluator without
`--no-run-providers`. The config shape is:

```json
{
  "provider_identity": "static-site-importer@COMMIT",
  "runtime_identity": "wp-codebox@VERSION",
  "fixtures": {
    "fisiostetic": {
      "site_plan": "/absolute/fisiostetic-site-plan.json",
      "html_wordpress_mobile_parity": "/absolute/html-wordpress-mobile.json",
      "figma_wordpress_desktop_parity": "/absolute/figma-wordpress-desktop.json",
      "figma_wordpress_mobile_parity": "/absolute/figma-wordpress-mobile.json"
    }
  }
}
```

Provide one entry for each selected fixture. Outputs are written under
`<output-directory>/production-acceptance`, including `manifest.json`, each
fixture's 13 stage files, copied evidence artifacts, and evaluator `summary.json`.
