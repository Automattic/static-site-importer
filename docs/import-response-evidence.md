# Retained import evidence

`blocks_engine.wordpress_site_plan` is removed. Reports carry
`blocks_engine.plan_summary`: schema, plan identity, page/asset counts, asset
metadata and compiler quality metrics. It is not an executable plan.
The response artifact named `wordpress_site_plan` owns the plan.

`import_report.quality.fallbacks` is the canonical fallback list. The plan and
result details use `fallbacks_ref` instead. Runtime entity content shared by
source records, resolved entities and lifecycle manifests lives in the
`runtime_evidence` artifact, keyed by SHA-256. Distinct resolved entities keep
distinct identities. Live materialization inputs are not modified.

References contain an `artifact` key (in `response_artifacts.artifacts`) and a
JSON Pointer `path` within that artifact. Resolve using the artifact descriptor,
not a path supplied by source content. PHP consumers that have loaded the
artifacts can use
`Static_Site_Importer_Canonical_Import_Service::resolve_response_evidence()`;
it performs no filesystem reads. Fields ending in `_ref` resolve to their
unsuffixed names. The materialization receipt retains its transaction semantics;
only retained evidence is projected to references.

Quality gates, diagnostic provenance, failed-plan evidence and compact metrics
read `plan_summary`. Acceptance handoffs use the plan response artifact when
available; matrix collectors use the summary for counts and asset evidence.
Compiler envelopes still use `source_reports.wordpress_site_plan` unchanged.

Blocks Engine follow-up: content-address each form fallback's per-instance
`layout_graph`. SSI deliberately does not rewrite producer-owned layout graphs.
