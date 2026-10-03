# Provider-owned source-page routes (#1898)

## Current boundary

`Static_Site_Importer_Prepared_Plan_Application::materialize()` provisions provider
entities before `materialize_prepared()`, then passes only block bindings and layout
overlays to page persistence. `Static_Site_Importer_Site_Plan_Preparation::preflight_state()`
rechecks every resolved page's route and existing post, and
`Static_Site_Importer_Site_Plan_Persistence::materialize_prepared()` publishes
every non-protected page. A seeded provider CPT therefore does not replace its
original imported page. A block binding replaces a fragment, not the page.

The source-route runtime resolves only 404 requests and queries published
`page`/`post` records. It cannot redirect an occupied source route or find a
provider CPT. The page journal restores posts it updates, while provider
compensation uses the provider's independent receipt and rollback callback.
Neither journal currently covers an inferred transfer of content, image, route
metadata, or publication status between those two owners. In particular,
`rewrite_materialized_route_links()` expects source IDs for the planned pages,
and reading operations can refer to a page reconciliation identity.

## Smallest contract needed before accepting ownership

The producer must declare a **whole-page ownership candidate** on the canonical
page and corresponding runtime entity, with the exact `source_path`, canonical
`route.path`, page reconciliation identity, and entity declaration/row identity.
This is separate from a fragment binding or a date/slug classifier result. The
candidate must survive canonical validation, resolution, and checkpoint replay;
an unrecognized candidate cannot authorize suppression, and a mismatched
candidate must reject the handoff. A provider's materialization result may then
opt in with an exact `source_path` + route + destination post ID claim tied to
that candidate.
The provider's absence, decline, or skipped row is **not** an ownership claim.

Before suppressing the source page, the consumer must prove all of the following
against the refreshed prepared plan and the live destination:

1. Exactly one candidate and one successful provider result match the same
   source path, route, declaration, and row. No two pages or provider rows may
   claim either route or destination ID. A stale route, changed plan identity,
   skipped/waived result, missing CPT, or protected/unowned existing page fails
   closed. The importer must not accept a caller-supplied `skip_materialization`
   or a bare post ID as authority.
2. The destination is a published post of the declared provider type, with the
   compiled, resolved Gutenberg block document (including its image references)
   persisted and verified. The adapter owns any provider-specific image/featured
   image writes and must include them in its rollback receipt. SSI should pass
   the resolved source document to the adapter rather than reparse source HTML.
3. The canonical route resolves to that post, or an installed redirect handles
   GET/HEAD for both 404 and a previously occupied imported page route. Static
   source-file aliases also resolve unambiguously to the same destination. A
   redirect must not override unrelated public content or loop to itself.
4. A previously published importer-owned source page is journaled and made
   non-public; a first import does not create it. Route-link resolution and
   receipt IDs must use the provider destination. Front-page operations and
   parent-page dependencies need explicit handling or must reject the claim.
   Re-import must reconcile the same destination and remove stale ownership
   metadata without accumulating aliases.
5. Failure after any mutation restores the source page, route metadata, and
   provider post/image through their respective journals. Compensation must
   replay once, with an explicit mutation receipt even if a later validation
   fails; a failed or ambiguous claim must not silently publish two copies.

This contract can extend the existing entity result and canonical page/entity
declaration; it does not require an event-specific path rule in SSI. The provider
adapter in #1896 can opt in once it supplies the declared document and rollback
proof. Until then, keep the original imported page when the provider is absent
or declines, and do not interpret a successfully seeded entity as a route claim.

## Neutral acceptance fixture for the implementation

Use two unrelated source pages, `website/notes/one.html` at `/notes/one` and
`website/notes/two.html` at `/notes/two`, each with distinct compiled block
markup and image. A fake provider claims only `one` and persists that exact
document on a registered CPT; `two` stays a published page. Assert one public
owner per route, the original file route and canonical route reach the CPT,
its block/image bytes survive, and no `one` page is published. Repeat the run
with the same reconciliation identities, then inject a late failure and verify
both the provider compensation and page/route rollback. Repeat with an absent
provider and verify both original pages publish.

Negative cases: a result with `/notes/old-one` for `one`, a destination ID
belonging to `two`, two claims for `/notes/one`, a stale unpublished CPT, and a
canonical route occupied by an unrelated post. Each must reject ownership
before SSI page/route mutation and compensate any already seeded provider row;
none may pick the first matching meta row.
