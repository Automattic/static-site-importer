# Purchase fallback placement ownership

Blocks Engine's product runtime-declaration producer owns emitting one
`commerce_add_to_cart_form` binding per purchase-box placement, including device
copies. Product identity is not placement identity. Subscription boxes may remain
`form_intent: unknown`; labels are not evidence of purchase ownership.

SSI accepts older declarations with an omitted device copy only when a form
fallback on the same product page has exactly the source-region bytes of an
explicit purchase binding and its anchor occurrence exists in the resolved page.
Each recovered occurrence gets its own replacement binding and receipt. Copies
with different region bytes require explicit bindings from the upstream producer;
SSI does not infer those from product titles or submit-button text.

A completed purchase receipt may reconcile a form-family finding at the same
page and selector, using that finding's own identity and real fallback hash.
This includes a form and controls finding sharing one placement. Unknown runtime
forms elsewhere remain unresolved. Fragment and persisted-page hash checks still
apply; placement matching does not make stale persistence valid.

The reduced commerce device fixture models two unknown subscription purchase
forms, one declared purchase anchor, and a controls finding only at that anchor.
It checks separate real-hash receipts for both copies and rejects stale pages.
