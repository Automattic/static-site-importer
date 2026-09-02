# Acceptance Handoff v1

`static-site-importer/acceptance-handoff/v1` is a source-neutral, portable root
for corpus quality admission. Every artifact is copied below the root and exposed
only through a relative SHA-256 reference. The contract is implemented by
`lib/fixture-matrix/acceptance-handoff.mjs` and its JSON schema is adjacent.

The producer records supplied input identity, compiler identity, a
`blocks-engine/wordpress-site-plan/v2`, terminal materialization receipt and
resolved route/entity mapping, route-and-viewport visual evidence, route-bound
editor evidence, and the quality-admission projection. It never fills absent
facts from aggregate status.

Fixtures may declare required provider submissions in `fixture.json`. Their
runtime-owned evidence envelopes are copied into the handoff and verified
against the exact fixture, route, form identity, pinned WordPress-owned provider,
site-plan hash, and terminal materialization receipt. Notification is a separate
capability and must remain unattempted by this submission proof.

`passed` requires all references to verify, a valid site plan, a completed
materialization receipt, complete route evidence, and a passed quality projection.
`failed` retains a failed materialization or quality disposition. All other cases
are `not_proven` with stable reason codes from the schema.
