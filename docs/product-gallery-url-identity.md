# Product gallery URL identity (#2679)

## Root cause and evidence

Issue #2679 reports retained authored carousels whose saved image sources mix uploads URLs, Squarespace CDN URLs, and site-plan asset tokens, while product rows still contain `/media/` paths and CDN URLs. Exact string-set equality therefore rejects a complete product gallery.

The change since SSI v1.33.371 is Blocks Engine commit `db93863fc7` (Localize media in JSON-string block attributes like their HTML mirror, #4645), shipped in php-transformer v0.45.240 and pulled into SSI by `640a0fca` (v0.45.238 → v0.45.241). AssetReferenceCanonicalizer now descends into JSON-string attributes and tokenizes their HTML media references, including thumbnail/control markup. `/media/` paths are capture inputs, not the newly introduced rewriting stage. SSI's existing Media Library projection subsequently resolves eligible asset tokens to uploads URLs before `write_whole_page_template()` calls `bind_document()`.

## Contract

Product gallery binding compares exact resource identities from plan assets, capture-record source URL aliases, and token-keyed attachment resolutions. WooCommerce-created uploads can also be resolved through their exact attachment source-image metadata. No basename or filename-substring matching is used.

The selection rule remains one smallest complete-image-set subtree, preferring the subtree with captured thumbnail presentation. Both preflight presentation collection and final template binding use the same aliases. Existing native galleries remain idempotent.

Replaced source photos with an attachment owner are withheld from theme writes only when no remaining served document or textual theme write references that resource. Shared design assets and photos without an attachment owner are retained.

The mixed-URL table case and packaging checks extend the existing `smoke-woo-product-whole-page.php`, already registered in both `test-manifest.json` and `homeboy.json` (the Test Inventory contract).

AI disclosure: implemented with model `cook-heavy` using the pi coding tool.
