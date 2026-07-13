# Companion Block Strategy

Static Site Importer should use generated companion blocks only for generic
runtime/editor gaps that WordPress core blocks cannot model with stable,
editable Gutenberg markup. The Blocks Engine producer stays product-neutral: it
emits typed facts, diagnostics, and `source_reports.companion_plugin_payload`.
SSI owns WordPress runtime materialization: generated PHP-only dynamic blocks,
provider dependency checks, activation, and import diagnostics.

The existing seam is enough for first implementations:

- Blocks Engine emits `static-site-importer/companion-plugin/v1` payloads under `source_reports.companion_plugin_payload`.
- SSI materializes that payload as a generated plugin dependency under `companion_plugins.dependencies`.
- The generated plugin registers PHP-only dynamic blocks and can carry scoped island JS without a build step.
- Provider-backed features should continue to use SSI entity materializer adapters before falling back to a companion block.

## Candidate Blocks

### `ssi/<site>/svg-artwork`

Purpose: editable arbitrary SVG artwork whose DOM graph cannot be represented by `core/image` because it depends on inline `defs`, gradients, filters, masks, symbols, clip paths, ID references, or document-context styling.

Schema:

- `svg`: sanitized inline SVG string.
- `viewBox`: optional string copied from source or inferred from width/height.
- `title`: optional accessible title.
- `description`: optional accessible description.
- `preserveAspectRatio`: optional string.
- `sourceSelector`: diagnostic-only source selector.

Runtime ownership: the companion plugin server-renders sanitized SVG with `get_block_wrapper_attributes()`. Blocks Engine owns detection, sanitization facts, and the decision that `core/image` cannot preserve the source. SSI owns the final WordPress render and dependency diagnostic.

Provider integration: none. This is a true Gutenberg/runtime gap, not a provider-backed entity.

Acceptance criteria:

- Complex SVG DOM survives without `core/html` or data URI fallback.
- Unsafe elements, event handlers, and `javascript:` URLs are stripped before render.
- Editor loads the block without invalid-content warnings.
- Frontend and editor previews preserve dimensions, ID-reference paint, masks, gradients, and filters within fixture tolerance.
- Matrix registry maps `inline-svg-filter-gradient` to this block when recurrence and no-core-path gates are met.

### `ssi/<site>/commerce-control`

Purpose: purchase UI controls that require WooCommerce product/cart runtime rather than static button markup: add-to-cart buttons, quantity steppers, variation/option selectors, price/cart state, and cart counters.

Schema:

- `productRef`: object with `sourceSelector`, optional `sku`, optional `slug`, optional `productId` after SSI materialization.
- `controlType`: `add_to_cart`, `quantity`, `variation_selector`, `cart_counter`, or `checkout_link`.
- `label`: optional visible label.
- `quantity`: optional integer default.
- `options`: optional array of variation/choice descriptors.
- `fallbackMarkup`: optional sanitized static markup for missing-provider diagnostics only.

Runtime ownership: WooCommerce owns product/cart mutation. Blocks Engine detects commerce controls and product-grid facts. SSI maps products through the shop adapter, resolves product IDs, and renders WooCommerce-backed controls from the companion block only when Woo runtime is available.

Provider integration: required `shop` capability, default provider `woocommerce`. Missing WooCommerce remains a dependency failure unless explicitly waived. The block should not emulate cart mutation in custom JS.

Acceptance criteria:

- Detected product controls bind to materialized Woo products rather than inert static buttons.
- Missing WooCommerce produces existing commerce dependency diagnostics, not a silent static fallback.
- Quantity/add-to-cart actions update Woo cart state through Woo APIs.
- Product cards remain editable as layout/content while controls are provider-backed runtime islands.
- Matrix registry maps `js-commerce-controls` to provider-materializable unless no Woo mapping exists.

### `ssi/<site>/runtime-island`

Purpose: bounded application JS interactions that cannot be rebuilt as native blocks or provider entities, while preserving source DOM targets and scoped first-party scripts.

Schema:

- `islandId`: stable hash from source selector/markup.
- `kind`: `control`, `canvas`, `template`, `widget`, or `script_target`.
- `markup`: sanitized bounded source markup.
- `scripts`: array of carried script handles or relative asset paths.
- `runtimeRequirement`: `client_script_execution`, `canvas_api`, or a future generic requirement.
- `sourceSelector`: source selector used by runtime parity diagnostics.

Runtime ownership: the companion plugin renders the markup and enqueues only the scoped script assets when the block renders. Blocks Engine owns runtime-island detection and the `runtime-island-package/v1` facts. SSI owns mapping those facts into the companion payload and reporting carried runtime as theme-independent.

Provider integration: none by default. Provider-owned behavior should use a provider block instead.

Acceptance criteria:

- First-party scripts with required DOM targets are materialized or enqueued by the companion plugin.
- Telemetry/analytics scripts remain droppable and do not create blocks.
- Runtime dependency parity no longer reports missing script targets for carried islands.
- Scripts enqueue only on pages where the block renders.
- The block remains bounded: no full-page app shells unless the whole source page is explicitly classified as an application fixture.

### `ssi/<site>/provider-form`

Purpose: form shell for source forms that Jetpack mapping cannot fully represent but that still have mappable provider semantics.

Schema:

- `formRef`: object with `sourceSelector` and optional provider form ID after SSI materialization.
- `provider`: `jetpack` by default, filterable through SSI form capability adapters.
- `fields`: normalized source controls with `name`, `type`, `label`, `required`, and `options`.
- `submitLabel`: optional string.
- `successMessage`: optional string.
- `fallbackMarkup`: optional sanitized static markup for missing-provider diagnostics only.

Runtime ownership: the selected form provider owns submission, validation, spam handling, and storage. SSI owns adapter selection and post-content grafting. A companion form block is only for provider shell cases that cannot be expressed by the provider's native block output.

Provider integration: required `form` capability, default provider `jetpack`, filterable via existing SSI materializer adapters.

Acceptance criteria:

- Jetpack-mappable forms continue to materialize as Jetpack blocks/markup without companion blocks.
- Unmappable controls produce actionable form diagnostics instead of pretending feature parity.
- Provider availability is reported through existing dependency rows.
- The block never implements a custom submission backend when a provider is selected.

## Build Order

Build `svg-artwork` first.

Reasons:

- It is the cleanest true Gutenberg gap: no provider dependency and no cart/submission semantics.
- The registry already identifies `inline-svg-filter-gradient` as a strong candidate.
- Blocks Engine already distinguishes native-image-compatible SVGs from SVGs requiring inline document context, so the first implementation can be narrow.
- It reduces current fixture blockers caused by complex SVG artwork falling back to `core/html` or data URI preservation while keeping the result editable and dynamic.

Second should be `commerce-control`, because Woo product materialization already exists and the remaining gap is binding detected controls to product/cart runtime. `runtime-island` should follow once scoped script packaging into companion payload is proven. `provider-form` should be last unless Jetpack evidence shows repeated unmappable-but-provider-capable shells, because existing Jetpack form materialization should remain preferred.

## Diagnostic Policy

- `custom-block-candidate`: use when the pattern has no core block path, no provider owns the runtime, and recurrence clears the registry threshold.
- `provider_materializable`: use when WooCommerce or a form provider can own the behavior.
- `runtime-island`: use when preserving bounded DOM plus scoped script execution is the honest result.
- `transformer_gap`: use when Blocks Engine can emit better native/core markup and no companion block should exist.

Companion blocks are not a catch-all. Each one needs a typed schema, source diagnostics, runtime owner, provider decision, and acceptance evidence from the fixture matrix before promotion.
