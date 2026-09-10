# Provider Presentation Destinations

The source presentation graph describes controls and labels independently of the
destination provider. A provider adapter maps each source role to one or more
rendered destinations. The shared overlay compiler validates and projects that
map; provider markup knowledge stays in the adapter.

Each `presentation_targets` entry has a control `index` and `destinations`:

```json
{
  "index": 0,
  "destinations": [
    {
      "role": "control",
      "selector": ".ssi-form-123456789abc .ssi-node-123456789abc",
      "properties": ["background_color", "padding_block_start"],
      "aliases": {"background_color": "--provider-input-background"}
    }
  ]
}
```

- `properties` selects captured facts for a destination. An empty list selects
  none, making a destination with only structural resets possible.
- `aliases` additionally projects a selected fact to an adapter-declared CSS
  variable. Values still pass the common presentation-value validator.
- `resets` neutralizes provider-added structure using a bounded property set.
  These are adapter decisions, separate from captured source facts.
- Optional `priority: "important"` establishes an explicit adapter override of
  provider resets. Priority is not accepted inside source property values.
- All destinations remain within the generated form scope. Output is validated
  again before stylesheet admission.
- Every captured property must have a destination; incomplete mappings produce
  a loss instead of silently dropping presentation.

Flat `control` and `label` targets normalize to a destination containing all
supported source properties. New adapters emit explicit destination lists.

## Composite Fields

Jetpack's phone renderer contains a country selector, a search input, a telephone
value input, and a hidden submitted value. Its adapter assigns role-qualified
generated hooks to the rendered shell, primary value, and restored value carrier.
The country search is not the primary input even though it appears first.

Auxiliary ownership and source containment are distinct: a country-selector
branch belongs to a phone field, but its source wrappers belong around the
provider prefix, not around the telephone value. Wrapper reconstruction preserves
those separate branches and retains provider interactivity attributes.

The generated companion carries this runtime projection, so the imported site
does not need Static Site Importer to remain installed.
