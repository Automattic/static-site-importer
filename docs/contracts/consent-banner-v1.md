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

With registered `jetpack/cookie-consent`, activation writes valid serialized markup to `cookie_consent_template`, never to page content. `completed.consent_banner` reports the option owner and status. The option joins the existing transaction journal: rollback deletes a newly created option or restores its previous value. Without the registered block, no option is written and one `consent_banner_not_materialized` finding names the missing runtime. Jetpack owns footer rendering on all routes, expiry, stored consent and WordAds CMP suppression; no replacement banner runtime is shipped.

## Verification

`tests/fixtures/consent-banner/` is a neutral two-route import intent. The WordPress acceptance imports through the installed Composer compiler and normal persistence, exercises Jetpack's actual footer, cookie/CMP suppression, and rollback; run it with `wp eval-file tests/acceptance/consent-banner-wordpress.php` in a disposable WordPress site with the importer active. Run once with Jetpack's Blocks module active and once without Jetpack (with the option initially absent). `consent-banner-browser.mjs` checks both routes, real accept click and reload; set `SSI_CONSENT_ORIGIN` to that site.

The regression fails before the extraction integration at “Consent fact survives compilation as intent, not content”; the same artifact passes after integration. The default Node regression validates emitted markup against Jetpack's real `save()` and core Buttons/Button saves, plus Jetpack's own serialized fixture.

Vendored Jetpack validation inputs come from `Automattic/jetpack` commit `fa3b274cb33a6eda842325b6b619928362a4ff72`, `projects/plugins/jetpack/extensions/blocks/cookie-consent/{save.jsx,block.json,constants.js,test/fixtures/jetpack__cookie-consent.serialized.html}`. They retain Jetpack's GPL licensing. Runtime browser verification used the current-source `cookie-consent.php`: Jetpack 16.3.1's additional wrapper is an upstream dismissal defect fixed by that current source, not a reason to diverge from the block save contract.
