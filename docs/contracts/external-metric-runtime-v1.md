# External metric runtime

SSI consumes only `entity_collection:external_metrics` declarations using
`generic/external-metric/v1` and `generic/block-binding/v1`. The producing
Blocks Engine declaration carries source provenance, a hash-bound captured
fallback, an allowlisted metric/aggregation/format descriptor, and one exact
Paragraph or Heading `content` anchor. SSI independently validates the slug,
provider, source/metric pairing, fallback hash and native text-leaf binding.
An invalid declaration is rejected before materialization.

The generated companion owns the native `ssi/external-metric` binding source,
its validated WordPress.org configuration and editor controls. It does not
depend on SSI or Blocks Engine at render time. Source endpoints are fixed to
the WordPress.org plugin-information and historical-download APIs; arbitrary
URLs are not declarations. `downloads_all_time` sums integer `all_time`
values from the historical endpoint; `num_ratings` reads the individual
`num_ratings` field. Install/download grouping and `+`, and version `v`, follow
the declaration's explicit locale/format descriptor.

The runtime deduplicates endpoint requests within a PHP request, caches for one
hour, bounds each HTTP request to five seconds/one MiB and the complete lookup
to fifteen seconds, and backs off failed lookups. A complete fresh value writes
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
revalidates the declaration and builds a new standalone companion. A separate
GitHub provider is not implemented; stars/forks and other non-WordPress.org
sources remain unresolved until they have a source-proven bounded provider
contract and runtime acceptance.
