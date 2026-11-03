# Consent banner evidence v1

An optional `website/consent-banner.json` is producer-neutral site-level evidence and intent, never a page or compiler resource. The importer extracts it before compilation, including inline, base64 and payload-reference inputs. It is bounded to 1 MiB. Invalid evidence produces `consent_banner_unreadable` without failing the import.

```json
{
  "schema": "static-site-importer/consent-banner/v1",
  "text": "This website uses cookies for analytics…",
  "accept_label": "Accept",
  "policy_link": { "label": "Privacy policy", "href": "https://example.test/privacy/" },
  "position": "bottom",
  "colors": {
    "background": "#222",
    "text": "#fff",
    "button_background": "#999",
    "button_text": "#fff"
  },
  "source_selector": "div.example-consent"
}
```

The schema discriminator identifies the contract. Only `text` and `accept_label` are required payload fields; they must be nonempty strings. Text and labels are plain visible text, escaped on output. Optional `policy_link` has `label` and an absolute HTTP(S) `href`. Optional `position` (`top`, `bottom`, `center`) and `source_selector` retain provenance: Jetpack owns positioning and uses its native bottom banner layout. Optional colors map to the banner and button `style.color.text` / `style.color.background`; arbitrary source CSS is not imported.

When dependency materialization is enabled, consent intent declares Jetpack through the ordinary provider dependency plan. Preparation installs/activates Jetpack and enables its Blocks module. The block registers in the lifecycle's fresh request only on connected sites or sites where Jetpack offline mode is enabled (such as local sites). Elsewhere, missing runtime degrades to the finding below; Jetpack is still provisioned during prepare. Only Blocks is enabled, not Forms. With provisioning disabled or `allow_missing_jetpack`, missing runtime also degrades to the finding below.

With registered `jetpack/cookie-consent`, activation writes valid serialized markup to `cookie_consent_template`, never to page content. `completed.consent_banner` reports the option owner and status. The option joins the existing transaction journal: rollback deletes a newly created option or restores its previous value. Without the registered block, no option is written and one `consent_banner_not_materialized` finding names the missing runtime. Jetpack owns footer rendering on all routes, expiry, stored consent and WordAds CMP suppression; no replacement banner runtime is shipped.

## Optional presentation

The v1 fact accepts an optional `presentation` object. Existing `span`, `edge_offset`, `padding`, font typography, and button presentation remain supported. `edge_offset` targets `top` when `position` is `top`, otherwise `bottom`.

- `gap`: one or two typed lengths, row then column (for example `"0px"` or `"4px 8px"`), emitted as `gap` on the banner. A single value applies to both axes; omission preserves existing output.
- `text_margin`: one to four typed lengths (for example `"0px"`), applied to the message paragraph.
- `button.border_width`: a single typed length; `button.border_style`: `solid`, `dashed`, `dotted`, `double`, or `none`; `button.border_color`: a color accepted by the existing color validator. These serialize through `core/button`'s `style.border` with matching inline styles and border color class.
- `button.line_height`: a single typed length, serialized through `core/button`'s `style.typography.lineHeight`.
- `layout`: a list of entries with required `direction` (`row` or `column`), optional `align_items` (`center`, `flex-start`, `flex-end`, `stretch`), and optional `button_align` (`start`, `end`). Optional `min_width` and `max_width` are finite nonnegative pixel numbers, with minimum no greater than maximum. Bounds are inclusive media queries; an entry without bounds applies at all widths. In column layouts, `button_align` aligns the buttons at the requested horizontal end. Optional per-entry `text_margin` accepts the same typed length shorthand as the top-level field and overrides that fallback inside the entry's media gate. Invalid per-entry margins reject that layout entry.

For example: `"layout": [{"max_width":767,"direction":"column","button_align":"end"},{"min_width":768,"direction":"row","align_items":"center"}]`.

Typed lengths accept zero or nonnegative numeric values with supported CSS length units, never arbitrary declarations or functions. Invalid fields or layout entries are ignored. When valid text margin or layout is provided, the paragraph flexes and the banner does not wrap. Generated rules are scoped to `.ssi-consent-banner`; without these new fields, existing output is unchanged.

## Verification

`tests/fixtures/consent-banner/` is a neutral two-route import intent. The WordPress acceptance imports through the installed Composer compiler and normal persistence, exercises Jetpack's actual footer, cookie/CMP suppression, and rollback; run it with `wp eval-file tests/acceptance/consent-banner-wordpress.php` in a disposable WordPress site with the importer active. Run once with Jetpack's Blocks module active and once without Jetpack (with the option initially absent). `consent-banner-browser.mjs` checks both routes, real accept click and reload; set `SSI_CONSENT_ORIGIN` to that site.

`consent-banner-lifecycle.php` runs with `plan`, `prepare`, then `resume` as three separate `wp eval-file` requests on a disposable site initially without Jetpack. It verifies dependency planning, provisioning and native option materialization. The default Node regression also checks that consent intent enters the ordinary dependency plan: it fails on main with “Consent fact must plan the native Jetpack block dependency” and passes after this change. The default Node regression validates emitted markup against Jetpack's real `save()` and core Buttons/Button saves, plus Jetpack's own serialized fixture.

Vendored Jetpack validation inputs come from `Automattic/jetpack` commit `fa3b274cb33a6eda842325b6b619928362a4ff72`, `projects/plugins/jetpack/extensions/blocks/cookie-consent/{save.jsx,block.json,constants.js,test/fixtures/jetpack__cookie-consent.serialized.html}`. They retain Jetpack's GPL licensing. Runtime browser verification uses released Jetpack 16.3.1, including its wrapper, and waits for the native consent cookie rather than assuming an inner-dialog opacity transition.
