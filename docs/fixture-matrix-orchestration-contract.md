# SSI Fixture Matrix Orchestration Contract

This rig does not require unmerged Static Site Importer support in Homeboy or
Homeboy Extensions. It calls the current generic WP Codebox recipe execution
surface and supplies all SSI policy from this package.

## Required Current Interfaces

- `${package.root}/tools/wp-codebox/recipe.mjs` must expose `runWpCodeboxRecipe(options)` as a pass-through to the upstream Homeboy Extensions helper.
- `runWpCodeboxRecipe(options)` must accept `recipeFile`, `artifactsDir`,
  `outputFile`, and optional `wpCodeboxBin` without adding Rigs-owned watchdog,
  dedupe, CLI-discovery, or fallback artifact behavior.
- WP Codebox recipes must accept schema `wp-codebox/workspace-recipe/v1`.
- WP Codebox workflow steps must support `command: "wordpress.wp-cli"` with an
  `args` entry containing a `command=...` WP-CLI string.
- WP Codebox recipe inputs must support `extra_plugins` entries with `source`,
  `slug`, and `activate`, plus `mounts` entries with `source`, `target`, and
  `mode`.

## SSI-Owned Inputs

- Fixture root discovery starts at `--fixture-root`, defaults to an `index.html`
  entrypoint, and descends two directory levels unless `--max-depth` is set.
- Generated artifact intake starts at `--artifact-root` and materializes
  structural candidates into a normal fixture root before matrix discovery.
- The Static Site Importer plugin source is `--static-site-importer-path`.
- The default plugin slug is `static-site-importer`.
- The default activation file is
  `static-site-importer/static-site-importer.php`.

## SSI-Owned Artifacts

- `matrix.json`: discovered fixture matrix.
- `intake`: optional `cli-run.json` summary section describing generated
  artifact roots materialized into matrix-compatible fixtures.
- `<fixture-id>/artifact.json`: Blocks Engine site artifact input for SSI.
- `wp-codebox-static-site-fixture-matrix-recipe.json`: full matrix recipe.
- `wp-codebox-static-site-fixture-matrix-batch-NNN.json`: batch recipes when
  `--run` is used.
- `static-site-fixture-matrix-result.json`: normalized fixture results.
- `summary.json`: aggregate pass/fail/finding counts.
- `finding-packets.json`: grouped diagnostics for repair fanout.
- `cli-run.json`: command summary and runtime metadata.

## Diagnostic Interpretation

The rig classifies SSI validation payloads into product repair groups here rather
than in generic Homeboy layers:

- `button_style_loss` maps to `blocks-engine` transformer style parity.
- `broken_svg` maps to `blocks-engine` SVG transformer parity.
- `dropped_images` maps to `static-site-importer` asset materialization.
- `invalid_block_content` maps to `blocks-engine` block validation parity.
- `runtime_target_gap` maps to `blocks-engine` runtime DOM target parity.
- Unclassified findings remain `static_site_import_quality` for SSI import
  validation.
