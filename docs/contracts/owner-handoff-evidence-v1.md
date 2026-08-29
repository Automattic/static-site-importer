# Owner Handoff Evidence v1

`static-site-importer/owner-handoff-evidence/v1` is the fail-closed owner-handoff
contract for a generated WordPress site. It composes decisions made by owning
evidence producers; it does not reconstruct visual, editability, provider,
accessibility, performance, or deployment policy.

The root binds to a `blocks-engine/wordpress-site-plan-identity/v1` and the
SHA-256 of the complete canonicalized
`static-site-importer/materialization-receipt/v2`. The receipt must carry the
same plan identity. `bindings.verified` is false, with a stable typed gap, when
either identity is absent or mismatched.

## Evidence Inputs

Each dimension accepts a content-addressed artifact reference after the terminal
materialization receipt exists:

```json
{
  "sha256": "<hash of artifact>",
  "artifact": {
    "schema": "static-site-importer/owner-handoff-dimension-evidence/v1",
    "dimension": "visual_acceptance",
    "subject": {
      "plan_identity": {
        "schema": "blocks-engine/wordpress-site-plan-identity/v1",
        "hash": "<canonical plan hash>"
      },
      "materialization_receipt_sha256": "<complete receipt hash>"
    },
    "status": "pass",
    "source_evidence": [{
      "schema": "static-site-importer/owner-handoff-source-evidence/v1",
      "sha256": "<producer artifact hash>",
      "artifact": {
        "schema": "static-site-importer/owner-handoff-source-evidence/v1",
        "dimension": "visual_acceptance",
        "status": "pass",
        "subject": {
          "plan_identity": { "schema": "blocks-engine/wordpress-site-plan-identity/v1", "hash": "<canonical plan hash>" },
          "materialization_receipt_sha256": "<complete receipt hash>"
        }
      }
    }],
    "findings": []
  }
}
```

Owning producers adapt their policy result into the source envelope; the composer
requires that exact versioned schema and derives the dimension status from it.
The composer verifies each source artifact, the dimension artifact hash, and both
subject bindings. A bare
`status: passed`, an unsupported schema, an altered artifact, or evidence for a
different plan/receipt becomes `evidence_gap`; none can manufacture a pass.
Repository ownership is fixed by dimension rather than accepted from callers.
At most 32 source artifacts and 100 bounded findings are consumed per dimension.

Non-pass artifacts carry one or more findings. Every finding has a stable reason
code, route, component, owning source-evidence reference, summary, and next
action. The dimension status is deterministically aggregated from its findings.
This supports several route/component findings without collapsing them into one
aggregate assertion.

The normal import report emits a post-commit baseline and does not accept
pre-materialization caller evidence: receipt identity is not known until the
transaction completes. Runtime/reviewer workflows compose supplied dimension
artifacts in a post-materialization step.

The materialization receipt's already validated, plan-bound
`editability-report-admission/v1` is consumed directly. No other aggregate import
metric is promoted automatically to a pass. Unsupported or incomplete current
evidence remains a typed gap.

## Statuses

- `pass`: hash-bound mandatory evidence is present and its owning layer passed.
- `hard_failure`: a measured failure that blocks accepted/built handoff.
- `owner_decision`: ordinary ownership requires an explicit owner action.
- `acceptable_conversion`: a declared, non-blocking conversion.
- `informational`: a non-blocking finding.
- `evidence_gap`: mandatory evidence is absent, malformed, or mismatched.

## Dimensions

- route and content completeness
- desktop and mobile visual acceptance
- meaningful editability and shared-region ownership
- editor presentation and persisted edit operations
- Media Library ownership for replaceable media
- internal-link portability and external-link inventory
- interaction and provider-functionality receipts
- rendered metadata, site identity, and unresolved placeholders
- keyboard/accessibility evidence
- bounded frontend performance evidence
- dependency, deployment, and rollback readiness
- deterministic owner-task evidence

The owner-task artifact uses `static-site-importer/owner-task-check/v1`. It must
contain text edit, image replacement, navigation edit, shared-footer edit, and
form-recipient edit operations. Each operation requires before/after hashes,
an actual state change, successful save/reload and post-save validation, and source evidence. Recipient
values are not part of this public projection.

## Report Card

`report_card` is a concise projection using
`static-site-importer/owner-handoff-report-card/v1`. Its `evidence_sha256` binds
it to the canonical root fields before `evidence_sha256` and `report_card` are
added, so it cannot introduce a second decision.

Serialized admission is never trusted alone. `admits_accepted_or_built()` requires
the terminal receipt and original owning evidence, recomposes the document, and
compares its canonical hash.

`blocked` contains a hard failure. `not_proven` contains a mandatory gap or an
invalid binding. `needs_owner` has only owner decisions remaining. `ready` has
neither. Accepted/built handoff requires verified bindings and no hard failures
or evidence gaps; caller workflows retain publication policy.
