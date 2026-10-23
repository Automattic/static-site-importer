# #2315: shared image / CSS background ownership

Investigation only; **no production deduplication change**. Fixes #2315 is the intended follow-up, not a claim made by this patch. The requested stop condition applies: the safe fix crosses the Blocks Engine asset-plan contract. Exact source/byte overlap also cannot be established from the projected evidence.

## Measurements and evidence limits

Recounting the supplied `securetia` inventories (bytes, not rounded MB):

| Inventory | Files | Bytes |
| --- | ---: | ---: |
| Theme `assets/website/external/`, all types | 372 | 93,365,916 |
| Same, excluding 39 WOFF2 files and one CSS file | 332 | 92,808,149 |
| Uploads, all files | 1,108 | 250,908,050 |
| Attachment records | 375 | — |

The reported 333-file media count is not reproduced by this inventory: 332 excludes fonts and CSS but includes SVG and PDF. The attachment metadata has 375 distinct `securetia#<sha256>` identities, and every attachment's original `file` occurs in the uploads inventory. The identity is **theme slug + SHA-256 of the source bytes**, not a source URL; see `ensure_attachment()`.

Cross-joining theme external media against attachment original filenames and upload sizes gives:

* 103 exact-basename/equal-size candidates, totaling 42,414,769 theme bytes.
* 276 candidates / 62,111,087 theme bytes when allowing WordPress's numeric filename collision suffix on the attachment (`name-1.png`, etc.). Each theme file has only one candidate under that rule. This includes the 103 above, not an additional 276.
* These are **candidate overlaps, not proven byte duplicates or measured savings**. For example, `logseek_animacia3n2-AE0rrx7Nq2FpDM19.gif` has an 8,703,364-byte theme inventory entry and an equal-size attachment original (ID 466). The attachment records provide its source hash, but the theme inventory does not.

The projection has HTML/CSS, generated templates, manifest and file-size inventories, but **no external image payloads or upload payloads**. Only one of the 372 external paths has a corresponding capture payload: the Google Fonts CSS. The manifest's desired asset entries contain source/target paths, not hashes. Thus hashing attachments and theme media, or joining theme media to attachment source URLs, is impossible with these inputs. The opaque external namespace does not recover the original source host. Obtain either the original artifact's file records (source URL + SHA-256), or theme/upload originals, to produce the exact overlap list requested by the issue. Do not use filename/size matching to delete files.

The supplied 727-generated-size count is retained as upstream evidence, not independently verified. A simple `-WIDTHxHEIGHT.ext` filename classification finds 715 files / 116,328,031 bytes; original-upload names, scaled originals and missing attachment-size metadata prevent treating that heuristic as authoritative.

## Ownership and cause

1. Blocks Engine `WordPressSitePlan::assetWrites()` emits a `theme_asset` write for each planned asset. `AssetReferenceCanonicalizer` rewrites both HTML image references and CSS `url()` references to the same declared source token.
2. Blocks Engine `WordPressSitePlanResolver::references()` resolves page tokens to `theme_uri/target_path`. `referencesForWrite()` resolves CSS tokens to paths relative to the theme stylesheet. There is no per-source attachment destination in that resolver context.
3. SSI `Static_Site_Importer_Site_Plan_Persistence` writes resolved files before calling `Static_Site_Importer_Media_Library_Materializer::materialize()`.
4. SSI's `ensure_attachment()` hashes page image files and `attachment_for()` calls `wp_upload_bits()` to copy them to uploads. It deduplicates **between attachments**, then binds page image markup to attachment URLs. It does not retire the theme write or rebind CSS/template consumers. The class explicitly documents CSS backgrounds and template chrome as theme-owned assets. Even an image used only in page markup can therefore retain its original theme copy.
5. Deleting the theme image alone breaks CSS. Editing CSS after the fact creates a second asset-resolution implementation: Blocks Engine validates resolved write payloads against `resolveWritePayload()` and validates publication references/write identities. The manifest, export, reimport and rollback would also need to agree on the new owner.

SSI owns the extra upload and knows WordPress attachment IDs. **Blocks Engine owns the plan/token/destination contract that must support relocating the shared source safely.** This is not a source-platform issue and should not be solved with source-host or filename rules.

## Neutral compiler reduction

`tests/fixtures/shared-image-background/` uses one generated 32×32 PNG both in `<img>` and in an external stylesheet background. Run `tools/probe-shared-image-ownership.php` with the repository's WordPress HTML API bootstrap. This invokes the real installed compiler and resolver, without Playwright or Studio.

Observed before (Blocks Engine 0.44.103):

* PNG: 123 bytes; exactly **one** source asset / `assets/shared.png` write.
* Page markup: two occurrences of its canonical token (block attribute and saved image); CSS: one occurrence of that **same token**.
* Resolved page image: `https://destination.test/themes/reduction/assets/shared.png`.
* Resolved `assets/style.css` URL: `shared.png` (the theme image).

This verifies that the compiler does not allocate a second source image for CSS. The second stored copy is introduced later by SSI's attachment copy, with no corresponding plan relocation. The probe characterizes current behavior; it is deliberately **not** a purported failing-then-passing deduplication regression. No after measurement exists because implementation is stopped at the contract/design boundary.

## Proposal for the owning loop

Extend the existing asset token/publication primitive, not CSS search-and-replace:

1. BE declares source identities and consumer roles (page/native-media vs design-only), preserving exact bytes and crop/rendition distinctions. SSI selects attachment ownership when a source is promoted to the Media Library; CSS-only design assets can stay theme-owned.
2. SSI creates/reuses the attachment once, journals it, and supplies a per-token destination URL to the BE resolver **before final publication**. This must work for both generated-theme and companion-plugin destinations. BE resolves all consumers through that destination: page markup, block attributes, templates, stylesheet `url()` (including suffixes), editor styles and runtime publication declarations.
3. BE omits the relocated theme payload write and represents the external destination in validation/publication expectations. SSI records the final ownership in the manifest and keeps export, cleanup, rollback and reimport consistent. WordPress-generated responsive sizes remain legitimate separate derivatives, not duplicates to delete.
4. Carry this neutral fixture into a full materialization regression: **before**, one theme original + one upload original (2 × 123 = 246 source bytes, excluding WP derivatives); **after target**, zero theme originals + one upload original (123 bytes), and CSS and `<img>` resolve to that upload. Prove the test fails on `origin/main`. Cover CSS-only assets staying theme-owned, query/fragment preservation, shared template/editor consumers, same-basename different bytes, failed upload/rollback and repeat import.

Decision needed: approval of attachment-backed token destinations / publication ownership in BE, then SSI consumes that capability. No vendor edits or local post-resolution dedupe shim are proposed.
