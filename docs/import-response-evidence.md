# Retained import evidence

Reports carry `blocks_engine.plan_summary` (identity, counts, asset metadata and
compiler metrics), never an embedded executable plan.

For direct artifact imports, the `wordpress_site_plan` response descriptor points
to the existing `composed-result.json` checkpoint. Its SHA-256 and byte count
identify the whole checkpoint; `json_pointer` selects
`/payload/result/wordpress_site_plan`. The checkpoint survives terminal pruning
and expires with its import workspace. Other import paths without a compose
checkpoint retain a standalone plan artifact. No second plan is written when a
checkpoint is available.

The checkpoint is the compiler plan. The materialization receipt's
`plan_resolution` retains execution-time runtime resolution and bindings, while
`plan_ref` identifies the compiler plan. Consumers must not interpret the
checkpoint as a resolved execution plan.

`import_report.quality.fallbacks` owns response fallback evidence when there is
no compiler checkpoint. When a checkpoint exists, matching fallback rows and
runtime entity bodies reference its evidence instead; report-only metadata stays
in reference overrides. Equal form fields in runtime entities reference the
canonical fallback using JSON Pointers. Result details and standalone retained
plans use `fallbacks_ref`.

`runtime_evidence.records` owns shared runtime entity bodies not already available
in the checkpoint, keyed by SHA-256.
Record wrappers are traversed before storage, not stored as expanded copies.
Source and resolved bindings live in each reference's `overrides`, outside the
shared body; resolving the reference restores the original entity view. This
keeps source declarations and execution bindings distinct without persisting two
large entity bodies. Live materialization inputs are not modified.

References contain `artifact` (a key in `response_artifacts.artifacts`) and a JSON
Pointer `path` inside the loaded artifact. An empty pointer selects the root.
`Static_Site_Importer_Canonical_Import_Service::load_response_artifact()` loads a
trusted importer-owned descriptor, verifies its digest and follows its optional
`json_pointer`. `resolve_response_evidence()` resolves already loaded artifacts
recursively, applies binding overrides and restores `_ref` fields to their
unsuffixed names; it performs no filesystem reads. Load descriptors only from a
trusted response, never from source content. Acceptance handoffs also verify the
descriptor and follow its pointer before consuming the plan.

Quality gates, diagnostic provenance, failed-plan evidence and compact metrics
read `plan_summary`. Compiler envelopes continue to use
`source_reports.wordpress_site_plan` unchanged.

Blocks Engine follow-up: reduce repeated diagnostics inside producer-owned form
`layout_graph` nodes. SSI does not rewrite those graphs or compiler checkpoints.
