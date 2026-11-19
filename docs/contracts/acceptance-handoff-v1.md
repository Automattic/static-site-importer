# Acceptance Handoff v1

`static-site-importer/acceptance-handoff/v1` is a source-neutral, portable root
for corpus quality admission. Every artifact is copied below the root and exposed
only through a relative SHA-256 reference. The contract is implemented by
`lib/fixture-matrix/acceptance-handoff.mjs` and its JSON schema is adjacent.

The producer records supplied input identity, compiler identity, a
`blocks-engine/wordpress-site-plan/v3` (v2 is still accepted), terminal materialization receipt and
resolved route/entity mapping, route-and-viewport visual evidence, route-bound
editor evidence, authoring-parity evidence, and the quality-admission projection. It never fills absent
facts from aggregate status.

Fixtures may declare required provider submissions in `fixture.json`. Their
runtime-owned evidence envelopes are copied into the handoff and verified
against the exact fixture, route, form identity, pinned WordPress-owned provider,
site-plan hash, and terminal materialization receipt. Notification is a separate
capability and must remain unattempted by this submission proof.

`passed` requires all references to verify, a valid site plan, a completed
materialization receipt, complete route evidence, passing authoring parity, and a passed quality projection.
`failed` retains a failed materialization or quality disposition. All other cases
are `not_proven` with stable reason codes from the schema.

## Authoring parity

`authoring_parity_evidence` references a hash-bound JSON array. Each record is
`{cluster, sibling, sibling_template, probe_template, landmarks: {imported,
new_post}, pass}` (optional `differences` names failing landmarks). The adjacent
schema defines `authoringCluster` and `authoringLandmarks` for this artifact.
Clusters are post type × actual served template, plus taxonomy × served archive
template. Pages are included. Template identity is observed on the frontend,
not inferred from an empty `_wp_page_template` assignment.

The import-loop collector publishes a fresh item with its sibling's taxonomy
terms, compares both at 1440×900, and removes the probe in a finally block. Fresh
term archives are probed with a temporary term and published item. A temporary
MU observer supplies actual template identity and is also removed. Collection
errors are unverified blockers, never passes. Landmark counts cover title,
date, author, terms, complementary/sidebar regions, related queries and comments;
content left and width must both be measured and differ by at most four pixels.
Sidebar regions are counted within the main layout; related queries in global
headers/footers do not count. Missing evidence produces
`authoring_parity_evidence_missing`; invalid or failing evidence produces
`authoring_parity_failed`. Consumers recompute landmark verdicts.

Run `node tools/import-loop/authoring-parity.mjs <site-path> [wp-cli]` against a
disposable import to collect the array. The import loop records it as
`authoring_parity_evidence`, and matrix intake preserves that field for the
handoff. Solved-site verification and fixture promotion both require passing
evidence; promotion consumes the passed handoff before moving files.

### Design evidence

The issue's lloydlofthouse.com r20 probe (SSI 1.35.10) found three distinct
single-post article templates (`52a1b08b7d4b`, `338c5defc41a`, `6672aaaac095`)
whose newly authored posts fell back to the default template and failed title,
date, comments and content-column checks. This is historical design evidence,
not a claim that this checkout re-ran that site. The collector additionally
counts sidebar and related-query regions to catch omissions the draft missed.
