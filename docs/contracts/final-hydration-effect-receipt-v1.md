# Final hydration effect receipt v1

Schema: `static-site-importer/final-hydration-effect-receipt/v1`.

SSI writes one receipt before and after each final hydration importer effect. Receipt identity is SHA-256 over run identity, batch identity, source snapshot hash, and compiled plan hash. Identity exists before provider mutation and never uses a provider-generated ID.

Receipt states:

- `effect_started`: durable intent exists; provider call may be in progress or may have completed before process interruption.
- `verified`: importer returned a result and SSI persisted that result before the URL batch manifest checkpoint.
- `failed`: provider/importer returned an error. Retry may be attempted.
- `needs_manual_recovery`: provider call may have completed but no verified result exists. SSI must not blindly replay it.
- `unsupported`: adapter cannot provide durable recovery guarantees.

Receipts are stored in the importer-owned artifact workspace under `effects/<receipt_id>.json` and published with atomic temp-file plus rename. Unknown schema, version, or receipt identity fails closed.

A verified receipt lets URL batch resume reuse final importer result without invoking final hydration again. This does not claim atomicity for WordPress or external providers. It only closes the durable checkpoint window after an importer call returns.
