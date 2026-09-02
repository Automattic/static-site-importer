# Provider Submission Evidence v1

`static-site-importer/provider-submission-evidence/v1` is a provider-neutral,
runtime-owned proof that a materialized form submits inside the target WordPress
runtime. SSI consumes this envelope; it does not emulate the provider runtime.

A fixture opts in with `fixture.json` `provider_submissions` rows containing
`required: true`, `page_route`, a SHA-256 `form_identity`, `provider_id`, and
`provider_owner: wordpress`.
Fixtures without required rows have no submission gate.

Each evidence envelope binds the fixture and resolved route/entity mapping, form
identity, pinned provider id/version, site-plan hash, and canonical SHA-256 of
the terminal materialization receipt. It proves required-field rejection, valid
success UI with a deterministic WordPress-local receipt, provider-failure UI,
and duplicate-submit single-receipt behavior. The endpoint must be WordPress
local, no source or external endpoint may be contacted, and notification must
be declared separate and unattempted. `artifact_ref.path` identifies the
runtime artifact included in solved-site promotion's immutable manifest.
