# Capture image records → WordPress attachments

Refs Automattic/data-liberation-agent#1035 (keep open).

SSI consumes optional `image-assets.json` (`version: 1`, `images`) or the
`original`-bearing `assets` in `capture-receipt.json`
(`blocks-engine-capture/capture-receipt/v1`). Stage the evidence beside the
portable website and declare it in `source.metadata.reports`, as with other
capture reports. Raw capture request bundles already forward receipt-declared
reports. The capture producer's `docs/image-assets.md` owns the source schema.

The intake adapter removes the evidence from site content. Original SHA-256
selects the exported file; receipt `path` disambiguates equal bytes at different
paths. URL spelling, hash filenames and image-service directory conventions do
not establish resource identity. Records survive direct-artifact checkpoints
and compilation as importer-owned evidence, outside the immutable compiler plan.

Before upload SSI verifies the staged digest, dimensions and MIME against the
record. Missing, conflicting or unverified originals fail with
`image_resource_unresolved`; they never fall back to an optimizer response or
placeholder. The compiler must declare `media_library` ownership for recorded
images. An unsupported ownership declaration fails explicitly rather than
claiming a successful attachment destination.

For admitted media assets:

- attachment identity uses source resource URL **and** original digest, scoped
  to the import theme; aliases reuse the same attachment, including on reimport;
- authored image alt text supplies a meaningful filename and title; absent alt
  leaves the image decorative and uses the original basename for its title;
- attachment meta `_static_site_importer_image_asset` retains original facts,
  aliases and categorized references; `completed.image_assets` in the public
  materialization receipt adds `destination.attachment_id` and `destination.url`;
- captured inline srcset descriptors for the same resource are removed before
  compile, producing native image blocks rather than raw responsive-media;
  independently identified candidates are not collapsed;
- the existing compiler token resolver binds content, parts, menus, head and CSS
  references to the same destination. Native Site Identity reuses that attachment;
- original bytes are not transcoded. A scoped output-format filter prevents
  site-wide JPEG conversion of alpha-bearing WordPress intermediate sizes.

Legacy artifacts without records keep their exact captured image bytes and
content-addressed deduplication. URL-shaped largest-rendition promotion and its
sibling scan are retired: only a capture record can select a different original.

## Proof

`tests/fixtures/image-asset-capture/artifact.json` contains a real Chromium
`captureWebsite` portable export: Next.js-shaped JPEG optimizer responses,
transparent WebP original, inline srcset, external CSS background, lightbox and
head icon. Only the loopback origin was normalized to `https://example.org`;
image bytes and exported markup are retained. Refresh it with
`tools/capture-image-record-fixture.mjs`, pointing `CAPTURE_PRODUCER_ROOT` at a
built public data-liberation-agent checkout.

The default standalone and Gutenberg tests compile this export and validate the
attachment-bound native image with registered `@wordpress/blocks` save rules.
`tests/smoke-image-asset-record-import.php`, run with `wp eval-file` in disposable
WordPress, actually uploads it, proves one attachment across those references,
checks generated alpha sizes under an adversarial JPEG conversion filter, and
proves reimport/rollback ownership. Set `SSI_IMAGE_RECORD_MARKUP` to retain its
saved content for the same Gutenberg validator.

## Follow-up slices

- Blocks Engine destination ownership for CSS-only and head-only records. Its
  current plan validator requires destinations to follow block consumers; SSI
  must not fabricate a block consumer or mutate an approved plan to bypass it.
- Record-owned picture/art-direction conversion and broader Cover/Media & Text
  fixtures; the existing native resolver bindings remain in use.
- Non-image resource records, proxy font byte verification and the broader
  builder/gallery/inline-icon fixture matrix.
- Producer record-owned acquisition inventory and additional optimizer adapters.
