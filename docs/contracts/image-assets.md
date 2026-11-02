# Capture resource records → WordPress attachments and verified theme files

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
record. Raster dimensions come from `wp_getimagesize`/SVG inspection; fonts,
documents and video verify by byte signature (WOFF/WOFF2/sfnt, PDF/ZIP/OOXML/
gzip/7z/RTF, MP4/WebM/Theora) against the declared content type. Missing,
conflicting or unverified originals fail with `image_resource_unresolved`; they
never fall back to an optimizer response or placeholder. The compiler must
declare recorded non-font destinations according to their consumers: records
with a block consumer use `media_library`; records without a block consumer stay
`theme`. Fonts stay theme-owned. An unsupported ownership
declaration fails explicitly rather than claiming a successful destination, and
theme-owned records verify their exact declared write bytes immediately before
the theme package materializes.

For admitted media assets:

- attachment identity uses source resource URL **and** original digest, scoped
  to the import theme; aliases reuse the same attachment, including on reimport;
- authored image alt text supplies a meaningful filename and title; absent alt
  leaves the image decorative and uses the original basename for its title;
  documents, archives and video keep the original basename and carry no
  WordPress-generated renditions, only file identity and size metadata;
- attachment meta `_static_site_importer_asset_record` retains original facts,
  aliases and categorized references; `completed.image_assets` in the public
  materialization receipt adds `destination.attachment_id` and `destination.url`
  for attachment-backed records (theme-owned fonts and images appear only as theme files);
- captured inline srcset descriptors for the same resource are removed before
  compile, producing native image blocks rather than raw responsive-media;
  independently identified candidates are not collapsed;
- the existing compiler token resolver binds content, parts, menus, head and CSS
  references to the same destination. Document links become `wp:file` blocks,
  video becomes `wp:video`, and captured fonts stay theme-owned: theme.json
  `fontFace` entries own the family, while imported `@font-face` CSS resolves to
  the written theme files. Native Site Identity reuses image attachments;
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

`tests/fixtures/resource-asset-capture/artifact.json` extends the same proof to
non-image resources: licensed Roboto subsets served with lying
`application/octet-stream` headers, a PDF terms document, a ZIP download and a
WebM video with poster, all linked from content and navigation. Refresh it with
`tools/capture-resource-record-fixture.mjs` against the same capture producer.
`tests/smoke-resource-asset-records.php` compiles it in the default gate and
`tests/smoke-resource-asset-record-import.php` uploads it in disposable
WordPress: one attachment per document, download and video with every content,
navigation and CSS reference rewritten, four verified theme-owned font files
behind theme.json fontFace, and an explicitly failed import for a lying digest.
The standalone record test also exercises consumer-routed recorded assets,
verified theme writes, and digest/MIME rejection through the real materialization
helpers with a stubbed WordPress upload/database boundary.

## Follow-up slices

- Record-owned picture/art-direction conversion and broader Cover/Media & Text
  fixtures; the existing native resolver bindings remain in use.
- Document and video WordPress metadata enrichment (duration, dimensions),
  legacy OLE2 document signatures, and the broader builder/gallery/inline-icon
  fixture matrix.
- Producer record-owned acquisition inventory and additional optimizer adapters.
