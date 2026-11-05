# Reduced commerce device capture (#2655)

Neutral shop and product documents, with desktop/mobile copies inside the capture
vocabulary's `data-blocks-engine-capture-device-document` containers. The shop has
two named, priced cards with quantity inputs and cart buttons; the product has
Product JSON-LD and a purchase form. No original site's assets or branding remain.

`smoke-commerce-device-variants.php` compiles these documents, validates provider
manifests, runs the Woo grid adapter, applies import-path bindings, and reconciles
the persisted-page receipts. WordPress catalog lookups and seeded Woo product IDs
are standalone doubles; compilation, provider markup, anchor replacement, and
quality reconciliation use production code.

Observed against origin/main with the same fixture and harness:

- Current grid declarations: 2 unsupported fallbacks (both purchase forms).
- Legacy shared `commerce_collection` declarations: 6 unsupported fallbacks
  (both grids, both cart-control findings, both purchase forms).
- Fixed: 0 in both handoffs. Tampering with persisted page hashes leaves all 6
  unresolved. Purchase receipts must carry a nonempty fallback hash.

Identical form anchors previously matched every device copy by hash, allowing
later metadata to overwrite earlier placements. The legacy collection receipt
also lacked its grid selector. Finally, flat compiler diagnostics omitted the
form/control metadata that the source fallback hash actually represents. The
fix preserves source occurrence and restores exact-location producer evidence,
using the existing form identity/hash mechanism rather than widening quality
acceptance by product name or page alone.
