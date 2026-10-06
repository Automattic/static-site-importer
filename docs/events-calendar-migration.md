# Native event migration

Source-backed schema.org Event detail documents materialize through the configurable `events` capability. The default provider is The Events Calendar; `static_site_importer_events_plugin` and `ssi_events_plugin` use the same provider selection boundary as forms and commerce.

The producer declares stable event identities and `blocks-engine/whole-page-candidate/v1` on unambiguous detail pages. Homepage mentions, synthetic routes, source parents and protected pages retain their existing page behavior. SSI consumes these typed facts rather than detecting event-like source HTML.

## Structured source facts

The inert client-script policy removes script markup and executable assets. Before removal, bounded inline JSON-LD objects become `metadata.structured_data` records with `type: application/ld+json` and decoded `data`. External script sources and malformed JSON are excluded. Admission is bounded to 32 script observations, 256 KiB total structured data per document, and JSON depth 24. The producer carries these facts through canonical compilation without emitting their original script markup.

## Native ownership

- TEC is installed/activated through the generic dependency registry when the source declares event intent.
- Supported repository APIs create/update events and venues, including real occurrence rows. Explicit source UTC offsets preserve the exact instant; an offset-only source is stored in TEC's supported UTC timezone.
- Providers receive the exact resolved Gutenberg document and canonical page/declaration/entity identities. Before suppressing an imported source page, SSI compares the actual saved native content to that resolved document. A provider-reported image boolean or self-reported content hash is not ownership proof.
- One published native destination owns each accepted source event. Original paths, static-file aliases and captured redirect aliases resolve to its canonical permalink through the existing source-route runtime. Unrelated occupied routes reject ownership.
- Native image binding reuses the ordinary canonical Media Library materializer and verifies source bytes; provider featured images use those same attachments.
- A new TEC configuration enables its native block editor when the setting is absent. Explicit owner editor preferences are preserved.
- Event reconciliation uses TEC's supported query-filter suppression so past events cannot disappear behind an upcoming-only query and be duplicated.
- Reimport keeps destination identities and image attachments stable. Page/route/media journals and provider compensation restore owned state after a later failure.

## Verification

`tests/acceptance/tec-wordpress.php` requires `SSI_TEC_DISPOSABLE=1` and a real disposable WordPress/TEC installation. `SSI_TEC_AUTOMATIC_EVENTS=1` exercises ordinary captured JSON-LD rather than caller-added declarations. It uses the canonical CLI's fresh-runtime continuation, verifies UTC instants and occurrences, adopts existing source pages, checks image bytes and reimport, and proves late rollback and route-conflict protection.

`tests/acceptance/tec-browser.mjs <site-url> <evidence-directory>` consumes the native proof and verifies original GET/HEAD redirects, the native calendar, Gutenberg validity, and meaningful event edits that survive save/reload and frontend rendering. Supply an installed Playwright module through `SSI_TEC_PLAYWRIGHT_MODULE` when running a relocated harness.

Tickets, registrations, recurrence and attendee migration require their own source-backed provider contracts. A passing neutral fixture is feature proof, not a claim of full-site visual parity for every event site.
