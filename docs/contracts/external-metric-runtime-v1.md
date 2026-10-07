# External metric runtime

SSI consumes only `entity_collection:external_metrics` declarations using
`generic/external-metric/v1` and `generic/block-binding/v1`. The producing
Blocks Engine declaration carries one bounded `generic/external-metric-source/v1`
recipe, explicit source/operator provenance, a hash-bound captured fallback,
top-level metric plus typed extraction/aggregation/format data, and one exact
Paragraph or Heading `content` anchor. SSI independently validates the recipe,
fallback hash and native text-leaf binding. Legacy provider-reader declarations
are rejected.

The generated companion owns the native `ssi/external-metric` binding source,
its validated declarative source recipes, generic HTTP/JSON interpreter, editor
controls and public-IP classifier. It does not depend on SSI or Blocks Engine
at render time. Recipes use bounded HTTPS GET requests, constrained path/query
resource substitution, JSON media types and JSON Pointers, explicit type
checks, aggregation, freshness and locale formatting; they expose no code
execution, credentials, request bodies, redirects or arbitrary transport
options. Provider-shaped legacy `provider` fields are rejected.
`success_count` can declare a typed extraction prerequisite; only responses
that pass it count, so HTTP-200 JSON error objects are not silently treated as
successful resources. String extraction rejects controls and markup delimiters
before entering a native text leaf.
WordPress.org plugin information/download history, GitHub repository counts and
neutral JSON sources are recipes on that same lifecycle.

The runtime deduplicates endpoint requests within a PHP request, caches for the
recipe's explicit freshness, bounds each HTTP request to five seconds/one MiB
and the complete lookup to fifteen seconds/100 resources, and backs off failed
lookups. A complete fresh value writes
a timestamped fresh receipt and last-known-good value. A failed, invalid, or
partial response never contributes a zero or incomplete aggregate: rendering
uses the last-known-good value as stale, otherwise the captured text fallback;
an empty fallback is unresolved. These statuses are retained in
`static_site_importer_external_metric_receipts`. Refresh/detach controls operate
on the native text leaf, leaving owner text, links, icons and layout as ordinary
WordPress blocks.

SSI export reads the active companion's trusted metric configuration and the
persisted native block bindings. It exports captured fallback text together
with source/build provenance and new canonical native leaf anchors. Reimport
revalidates the declaration and builds a new standalone companion with the
same source recipes and no provider credentials.
