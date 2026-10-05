# Form Style Ownership

The validated form presentation graph is the source of authored control and
label facts. Its target map routes those properties to the provider element
that owns the corresponding rendered box, and the existing provider overlay
compiles both base and conditional facts. Do not add parallel form CSS or
inline style resets for properties represented by that graph: doing so can
override source facts, including shorthand/longhand cascades and responsive
patches. Provider-default resets are retained, emitted before authored facts,
and skipped for an explicitly owned property at that destination.

Provider defaults should be released only for properties that the source did
not own. Responsive graph patches must remain property-scoped; a patch to one
property must not reset unrelated base facts. The submit control's min-height
reset is scoped to the inner control; source layout min-height on the form
wrapper remains a separate graph target. Runtime projection does not inject
inline min-height declarations on either element. Existing inline styles pass
through unchanged.

Blocks Engine currently emits at most 16 class tokens for context items and
submit presentation. SSI normalization must not truncate that producer-bounded
set to eight. A class is not proof that it owns any particular property.
Class-bearing context keeps resolved normalized styles in a deterministic
per-item identity class and emits those facts as a scoped low-specificity
fallback through the existing provider overlay. The selector uses only one
specificity class (the retained owner class); its identity and form scope are
inside `:where()`. The stylesheet materializer places fallback rules before
source author CSS, so equal-specificity author class rules and more-specific
base/media selectors override only the properties they declare. This depends
on the source stylesheet being retained and this ordering; selectors with
greater specificity are supported, while there is no property-ownership
inference from a class token. Classless context keeps the existing inline
block-style serialization. Frontend and editor fallbacks are separate
validated fields and share the overlay byte limits. The neutral fixture
verifies ancestor-resolved font-family/line-height fallback with an author
responsive font-size patch that changes only size, in both projections, at
390, 768, 1440, and 1600px. Interleaved context is not placed as a
before/after block; SSI retains its bounded omitted-item count so the loss is
visible instead of silent.

The neutral transformer fixture confirms producer evidence for in-form
heading/paragraph text, class tokens (including tokens after position eight),
resolved base typography, and an explicit `unrepresented_context` list for
copy between controls. SSI now retains the omitted-item count. The same
producer fixture demonstrates a wrapper gap: its inline `min-height` on the
submit-parent div is absent from serialized `layout_graph.nodes`.

The pinned Blocks Engine v0.26.7 `FormLayoutGraphBuilder` v2 contract lists
`width` and `height`, but not `min-height`, in both `PROPERTIES` and
`LAYOUT_KEYS`; SSI therefore rejects a v2 `min_height` fact rather than
quietly widening only the consumer contract. That wrapper fact requires a
paired producer schema/normalizer update and dependency revision before SSI
can project it. No wrapper value is inferred from site identity or
screenshots. A paired extension must carry bounded node identity, source
selector/path/hash provenance, parent/order relation, a per-property base
value, and property-scoped media patches. Then the existing graph and target
map can project it without site-specific CSS.
