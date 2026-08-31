# Owner Handoff Evidence v1

`static-site-importer/owner-handoff-evidence/v1` is a source-neutral, hash-bound
report that answers whether a generated WordPress site is ready for ordinary
ownership. It composes existing owning-layer receipts instead of reimplementing
their checks. The contract is implemented by
`includes/class-static-site-importer-owner-handoff-evidence.php` and
`lib/fixture-matrix/owner-handoff-evidence.mjs`.

Bindings require `blocks-engine/wordpress-site-plan-identity/v1` and the SHA-256
of the terminal `static-site-importer/materialization-receipt/v2` (or v1). The
producer never fills absent facts from aggregate status.

## Result statuses

- `pass` — required evidence is present and the owning layer already passed.
- `hard_failure` — required evidence is present and the owning layer failed.
- `required_owner_decision` — ownership can proceed only after an explicit owner action.
- `acceptable_conversion` — declared degradation that does not block ordinary ownership.
- `informational` — non-blocking inventory or note.
- `evidence_gap` — mandatory evidence is missing. Absence is never success.

`accepted_built_allowed` is true only when every mandatory dimension is `pass`,
`acceptable_conversion`, or `informational`. Hard failures and evidence gaps
block accepted/built handoff. Caller workflows decide whether a failed or
incomplete report also blocks publication.

Every non-pass finding includes affected route/component, evidence reference,
owning repository, and recommended next action.
