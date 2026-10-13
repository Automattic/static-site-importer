# Website Artifact Evidence

A website artifact can carry evidence about how it was produced, next to its pages. Static Site Importer reads that evidence through producer-neutral contracts, so any producer (a capture tool, an exporter, a site generator) can supply it:

- **Source findings**: `website/source-findings.json`, schema `static-site-importer/source-findings/v1`. SSI owns this contract.
- **Capture evidence names**: attributes and classes in captured HTML. Blocks Engine owns this vocabulary (Automattic/blocks-engine `docs/contracts/capture-evidence.md`, php-transformer `Contract\CaptureEvidence`).
- **Device selection scripts**: two generated scripts SSI's client-script policy keeps byte for byte.

## Source findings

`website/source-findings.json` lists the units the producer dropped or degraded, so they stay visible in the import report. The file is evidence, not site content: SSI removes it from the artifact before compiling.

```json
{
  "schema": "static-site-importer/source-findings/v1",
  "entrypoint": "index.html",
  "complete": false,
  "usable": true,
  "summary": { "routes_discovered": 9, "routes_captured": 7 },
  "findings": [
    {
      "code": "route_capture_degraded",
      "severity": "warning",
      "unit": "route",
      "loss_class": "degraded_unit",
      "location": { "url": "https://example.test/about/", "path": "about.html" },
      "message": "mobile screenshot timed out",
      "metrics": { "failedStages": 1 },
      "owning_layer": "example-capture"
    },
    {
      "code": "route_capture_failed",
      "severity": "warning",
      "unit": "route",
      "loss_class": "omitted_unit",
      "location": { "url": "https://example.test/slow/" },
      "message": "navigation timeout",
      "owning_layer": "example-capture"
    }
  ]
}
```

### Document

| Field | Required | Type |
|---|---|---|
| `schema` | yes | `static-site-importer/source-findings/v1` |
| `findings` | yes | list of finding rows, in report order |
| `entrypoint` | no | string |
| `complete` | no | boolean: the producer captured every unit it set out to |
| `usable` | no | boolean: the producer judges the artifact importable |
| `summary` | no | object of producer counts |

### Finding row

Each row describes itself; SSI keeps no registry of producer codes.

| Field | Required | Values |
|---|---|---|
| `code` | yes | Producer code. Lower-case letters, digits, `_` and `-`. |
| `severity` | yes | `warning`, `error` or `info` |
| `unit` | yes | `route`, `page`, `block`, `asset`, `stylesheet`, `profile`, `item` or `file` |
| `loss_class` | yes | `omitted_unit` (the unit is gone from the import) or `degraded_unit` (the unit stays with reduced fidelity) |
| `location` | yes | Object with at least one of `url` (source URL) and `path` (path relative to the website root). `profile` optionally names the capture profile, such as a device. |
| `message` | yes | Human-readable text; SSI keeps the first 512 bytes. |
| `owning_layer` | yes | Slug naming the producer layer that lost the unit, such as `example-capture`: lower-case letters and digits separated by `-`, `_` or `.`, at most 64 characters. |
| `metrics` | no | Object; numeric entries are kept. |

### How SSI reads it

- Each row becomes one import-report row with `stage: capture` and the row's own `code`, `severity`, `unit`, `loss_class` and `owning_layer`. Its `location` is `website/<path>` when `path` is present, else `url`; `url` also stays on the row, and `url` and `profile` go into its `context`.
- `omitted_unit` and `degraded_unit` rows are accepted, reported losses: they are counted in `loss_counts` and the compact summary and never fail the import.
- A row whose `loss_class` or `unit` is outside the vocabulary above is kept, visibly unrecognized: it is classified `unrecognized_loss_class` and counted, and an unknown loss class is recorded as vocabulary drift. It is never dropped.
- A row whose `code` SSI or Blocks Engine registers as a reported loss is classified by that owner's registry.
- Validation is all or nothing. A file that cannot be read, is not JSON, has another schema, or has a malformed row (a missing required field, an unknown `severity`, an `owning_layer` that is not a slug, a `location` without `url` or `path`) adds one `source_findings_unreadable` warning and no finding rows. The import continues.
- An artifact without the file imports unchanged.
- The file is read up to 1 MiB; that bound is the only row bound.

`Static_Site_Importer_Source_Findings` implements the reader. `tests/smoke-source-findings.php` covers it, and `tests/smoke-source-findings-equivalence.php` proves the contract imports a producer's findings exactly as the producer-specific reader it replaced did.

## Capture evidence names

Captured HTML can mark what the producer observed. Blocks Engine publishes the names; an attribute is `data-blocks-engine-capture-<name>` and a class is `blocks-engine-capture-<name>`. `Static_Site_Importer_Capture_Evidence` is the one place SSI names the ones it reads:

| Name | Read by | Meaning |
|---|---|---|
| `data-blocks-engine-capture-device-selection` | client-script policy | Marker on the device selection script |
| `data-blocks-engine-capture-device-body` | client-script policy | Marker on the device body script |
| `data-blocks-engine-capture-selected-document` | device selection scripts | Selected device document key on `<html>` |
| `data-blocks-engine-capture-selected-viewport` | device selection scripts | The viewport `<meta>` the selection rewrites |
| `data-blocks-engine-capture-document-unavailable` | device selection scripts | Device document key without a viewport |
| `data-blocks-engine-capture-device-style` | device selection scripts | Device document key of an inert stylesheet |
| `data-blocks-engine-capture-source-media` | device selection scripts | Original `media` of an inert stylesheet |
| `data-blocks-engine-capture-member-login` | internal-link runtime | Provider of a member sign-in control |
| `blocks-engine-capture-member-login-<provider>` | internal-link runtime | Class form of the member sign-in marker, which block conversion keeps |

A marked member sign-in control links to `wp_login_url()`. Generated themes and companion plugins carry their own copy of the internal-link runtime with these names inlined as literals.

## Device selection scripts

A producer that captures one document per device keeps each device stylesheet inert (`media="not all"`, marked with `data-blocks-engine-capture-device-style` and `data-blocks-engine-capture-source-media`) and emits two scripts:

- `<script data-blocks-engine-capture-device-selection="">` picks the document for the visitor's user agent, stamps it on `<html>`, applies its viewport and `<html>` attributes, and activates its stylesheets.
- `<script data-blocks-engine-capture-device-body="">` applies the selected document's `<body>` attributes.

The client-script policy keeps exactly these two scripts when their bodies match the template in `Static_Site_Importer_Client_Script_Policy` byte for byte and the embedded data passes its allowlist; every other script is dropped like any imported code. `tests/fixtures/device-selection/index.html` is the reference page. `tools/regenerate-device-selection-fixture.mjs <module> --producer <name@revision>` regenerates it from a producer module that exports `assembleDeviceDocuments()` and `installDeviceSelection()`, and `tests/smoke-client-script-policy.php` proves the producer's output matches.
