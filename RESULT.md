# PR: Treat full-width block-flow form wrappers as plain block flow

Fixes #2133.

## Summary

A form wrapper `div` that groups some but not all fields (a first/last name
row) and declares `width: 100%` declined the form-plugin → Jetpack mapping
with `form_receipt_loss_unaccepted` / `provider_wrapper_layout_unrepresentable`.
The form stayed a `core/html` fallback and the page failed the import quality
gate. `width: 100%` on a block-flow box that declares no horizontal margin,
padding or border is that box's default content width, so it now counts as
plain block flow — the same reasoning #2132 applied to `display: block` — and
no longer declines the mapping.

## Root cause

In `includes/class-static-site-importer-form-layout-projection.php`:

- `project_wrapper_chains` only projects single-control wrappers, so a wrapper
  around two fields stays unmapped.
- The wrapper-as-form-container path only accepts wrappers covering all mapped
  fields, so a partial group does not qualify there either.
- `finalize_overlay_targets` then classifies the unmapped wrapper's facts.
  `width` is not in the provider layout overlay's `box_property_map()`, so the
  `{width: 100%}` fact became a topology loss
  (`provider_wrapper_layout_unrepresentable`), which
  `Form_Mapping_Decision::requires_gate` treats as gate-blocking.

## Fix

`non_block_flow_facts()` (introduced by #2132 for `display: block`) now also
strips a `width: 100%` declaration when the box is in block flow (no other
`display` value) and declares no horizontal margin, padding or border — the
exact conditions under which `width: 100%` equals a block box's default
content width. The unrepresented-wrapper classification's skip condition
becomes "all declared facts reduce to plain block flow" instead of requiring a
literal `display: block`. Any other `display` value, a different width, or a
horizontal box declaration keeps the existing decline/loss behavior.

## Reduced fixture

```html
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Repro</title>
<style>.complex{width:100%}</style>
</head><body>
<form method="post" action="/contact/">
<div class="complex"><input type="text" name="first" aria-label="First"><input type="text" name="last" aria-label="Last"></div>
<div><input type="text" name="title" aria-label="Title"></div>
<button type="submit">Submit</button>
</form>
</body></html>
```

## Evidence

New `full-width-field-group-wrapper-maps-as-block-flow` case in
`tests/form-materializer-smoke.php` compiles the fixture above through Blocks
Engine and seeds it:

- **Before (main):** `status: skipped`, `reason: form_receipt_loss_unaccepted`,
  `mapping_decision.status: declined` with a topology loss
  `provider_wrapper_layout_unrepresentable` on `wrapper-0` (`div.complex`,
  `{width: 100%}`); the form stays `core/html` and fails the quality gate.
- **After (this branch):** `status: mapped`, `runtime_mapped: true`,
  `mapping_decision.status: mapped` with no losses, and the block markup holds
  exactly 3 `jetpack/field-text` fields plus the submit button — no field loss
  and no wrapper topology/box loss.

- `node tools/run-test-manifest.mjs tests/form-materializer-smoke.php`:
  PASS (454 assertions; fails on main with exactly this new case).
- `npm test` (full manifest): passed 142, failed 0, the existing fixture
  corpus stays green.

---

_AI disclosure: this change was written with Claude (Opus) via the Claude Code
CLI, orchestrated through an agent task loop. The AI read the issue, reproduced
the decline with the reduced fixture, implemented the fix and regression test,
and ran the test manifest; results were verified against the repository's
existing test harness._
