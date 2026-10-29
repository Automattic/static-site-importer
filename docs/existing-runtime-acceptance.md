# Caller-owned existing-runtime acceptance

Issue #1925 composes the existing review runner, editor-presentation/oracle and
acceptance/owner-handoff contracts. It does not introduce a DLA comparator.

## Invocation

```sh
npm run review:existing-runtime -- --acceptance-config /absolute/path/review-config.mjs
```

The same caller config runs the real DLA/Gutenberg integration regression:

```sh
SSI_ACCEPTANCE_INTEGRATION_CONFIG=/absolute/path/review-config.mjs \
SSI_ACCEPTANCE_BROWSER_SMOKE=1 node --test tools/run-existing-runtime-review.test.mjs
```

This regression verifies editor text and image persistence, authenticated draft
preview, original post/media isolation, and draft cleanup against a supplied
disposable WordPress runtime. Missing render/presentation proof still prevents
acceptance even when the editor save succeeds.

Programmatic entry: `runExistingRuntimeAcceptance(config)` from
`lib/run-existing-runtime-acceptance.mjs`. The config module default-exports the
same object. Paths in the config are absolute or relative to the invocation's
working directory. The legacy single-route CLI remains available; it is not the
unified acceptance contract.

The command writes `OUTPUT/existing-runtime-acceptance.json`, schema
`static-site-importer/existing-runtime-acceptance/v2`, with retained artifacts,
SHA-256 references, identities, scope, stage-owned failures and pending reasons.
Exit status is zero only for `accepted`. `failed` takes precedence over `pending`.
Missing browser/auth/evidence is pending; measured HTTP/runtime fatals fail.

## Dependency contract — candidate capability, not a published dependency

DLA #493 is a **candidate API**. Supply exactly one of:

* `runtimeModule`: the absolute public ESM entry of the separately validated DLA
  checkout/package;
* `runtimePackage`: an explicitly installed package specifier resolved through
  its public exports.

SSI calls the public named export `checkFidelity`; no private package paths,
release selection, installation of a newest version, or fallback SSI scoring.
No DLA dependency version is declared by this change. An absent/incompatible or
unvalidated implementation leaves both fidelity gates pending.

The caller/orchestrator supplies `runtimeIdentity` and `runtimeValidation`, a
`{ path, sha256 }` reference relative to `evidenceRoot`. The validation artifact
must contain:

```json
{
  "schema": "static-site-importer/fidelity-runtime-validation/v1",
  "runtime_identity": "caller-verified-checkout-or-package-identity",
  "public_entry_sha256": "SHA256_OF_RESOLVED_PUBLIC_ENTRY_BYTES",
  "status": "passed",
  "frozen_capture": true,
  "materialization_no_origin_visits": true
}
```

These are prerequisite validation results produced by the caller, not a way to
assert that acceptance passed. The runner checks the supplied artifact hash,
runtime identity and resolved entry bytes. Validation of the full DLA runtime
and its transitive dependencies belongs to the prerequisite verifier.

Capture happens before this runner, in DLA's actual `captureWebsite` session.
That session must produce `DIRECTORY/fidelity-reference.json` with hash-bound
source observations, cleaned DOM and screenshots. `reference` is its exact
`{ path: 'fidelity-reference.json', sha256 }` relative to `directory`.

The runner calls, with the same explicit scope:

```js
await checkFidelity({ directory, stage: 'capture', routes, widths, states });
await checkFidelity({ directory, stage: 'materialization', candidateUrl, routes, widths, states });
```

No source URL is passed and no `drift` check is called. Live-source drift is a
separate invocation owned by the caller. Gate B must remain unchanged if the
live origin mutates after capture; validated DLA must compare the frozen capture
to WordPress without revisiting origin.

### Public report integration boundary

The runner consumes the actual public `FidelityReport` returned by DLA #493 and
checks it against the newly persisted `DIRECTORY/compare/STAGE/report.json`.
It does not require DLA to implement an SSI-specific wire format. DLA supplies
numeric `coverage.required` and `coverage.measured`, and individual `scores`
with `stage`, `route`, `viewport`, `state`, `pass` and failure details.

SSI binds these to the exact invocation's reference hash and candidate URL,
retains hash-verified report/screenshot bytes, and expands the DLA scores into
the acceptance manifest's cells. Duplicate, missing or mismatched cells remain
pending. `public_coverage` preserves DLA's original coverage unchanged.
Descriptive unknowns outside the explicitly requested scope are retained as
limitations; required unproven cells in `pending` block acceptance.

Every required cell must be uniquely measured and proven. Missing tablet,
unsupported interaction, stale reference, artifact hash mismatch, missing or
ambiguous cells are pending. Valid measured failures retain their stage. SSI
does not compute fidelity scores or turn conversion quality into visual parity.

## Caller config

```js
export default {
  directory: '/captures/site',
  manifest: '/review-inputs/scope.json',
  outputDirectory: '/reviews/site',
  evidenceRoot: '/review-inputs',
  candidateOrigin: 'http://localhost:8886',
  portableOrigin: 'http://localhost:8887', // serves only the frozen capture directory
  candidateIdentity: { path: 'candidate.json', sha256: 'ACTUAL_FILE_SHA256' },
  reference: { path: 'fidelity-reference.json', sha256: 'ACTUAL_REFERENCE_SHA256' },
  importRunId: 'actual-import-run-id',
  importReport: { path: 'import-report.json', sha256: 'ACTUAL_FILE_SHA256' },
  runtimeModule: '/validated-dla/public-entry.mjs', // or runtimePackage
  runtimeIdentity: 'caller-verified-checkout-or-package-identity',
  runtimeValidation: { path: 'dla-validation.json', sha256: 'ACTUAL_FILE_SHA256' },
  editorId: 7,
  authProvider: 'studio-auto-login', // existing Studio adapter
  // Alternatively authenticate({page, candidateOrigin, postId}) supplied by caller,
  // or authEndpoint: URL / async ({candidateOrigin, postId}) => URL.
  // Credentials/cookies remain inside this hook, never inside an evidence file.
  candidateInventory: async ({ page, candidateOrigin }) => {
    // Caller enumerates ALL imported candidate routes from the real runtime.
    // Return [{route, postId, postType}], not a subset guessed from scope.
    await page.goto(new URL('/wp-admin/post.php?post=42&action=edit', candidateOrigin).href);
    await page.waitForFunction(() => Boolean(window.wp?.apiFetch));
    return page.evaluate(async () => {
      const posts = await window.wp.apiFetch({ path: '/wp/v2/pages?context=edit&per_page=100' });
      // For larger sites paginate; include other imported REST types as needed.
      return posts.map(post => ({ route: new URL(post.link).pathname, postId: post.id, postType: 'pages' }));
    });
  },
  routeToPost: [{
    route: '/', postId: 42, postType: 'pages',
    text: { blockName: 'core/paragraph', identityAttribute: 'className', attributeValue: 'review-text', attribute: 'content' },
    image: { blockName: 'core/image', identityAttribute: 'className', attributeValue: 'review-image', urlAttribute: 'url', idAttribute: 'id', attachmentId: 93, frontendSelector: 'main .review-image img' },
    presentationMap: '/review-inputs/home-presentation.json',
  }],
  // Optional existing workflow artifacts, consumed with their existing predicates:
  // acceptanceHandoff: {path: 'acceptance-handoff.json', sha256: '...'},
  // ownerHandoff: {path: 'owner-handoff-evidence.json', sha256: '...'},
};
```

`scope.json` declares every captured route, e.g.
`{"routes":["/","/about/"],"widths":[390,768,1440],"states":["baseline"]}`.
Omitting widths/states freezes those same defaults. Acceptance is scoped to this
explicit manifest; it does not certify omitted routes or states. Editor-presentation measurement supports
baseline; other desired editor states explicitly remain pending.

`candidate.json` is caller-produced runtime identity evidence with
`candidate_origin`, `import_run_id`, `reference_sha256`, and `manifest_sha256`
(hash of the input manifest's exact bytes). These must match this invocation.
The route inventory must match scope and supplied route-to-post mapping exactly;
Gutenberg/REST subsequently verifies each mapped original's permalink and the
authenticated editor ID. `import-report.json` must be the real SSI
`static-site-importer/import-report/v1` for that import run. Only a hash-verified
numeric `quality.fallback_count === 0` can pass conversion quality. A caller
boolean cannot replace measurement.

## Outcome checks (Refs #2431)

V2 replaces the v1 acceptance predicate: missing outcome evidence now prevents
acceptance. One hash-bound `artifacts/outcomes.json` report uses
`static-site-importer/outcome-checks/v1`, with `pass`, `fail` or `unsupported`
and evidence for `link_graph`, `block_validity`, `head_singletons`,
`control_inventory`, `forms`, `readability`, and `full_document`. The previous
five-check predicate is replaced: retained reports without document outcomes
cannot pass.
Failures fail the aggregate; unsupported required checks leave it pending.
The portable consumer rechecks the retained report and import/origin/route binding.

Supply `outcomes` in the same caller config:

```js
outcomes: {
  sourceHosts: ['source.example', 'cdn.source.example'], // exhaustive source + CDN hosts
  aliases: ['/old-path/'], // exhaustive aliases; [] explicitly means none
  telemetryMarkers: ['source-analytics'], // source telemetry fingerprints; [] means none
  collectInventory: async ({page, candidateOrigin}) => {
    // Execute lib/outcome-inventory.php inside this imported WordPress runtime,
    // then return ssi_collect_outcome_inventory() as decoded JSON. The caller
    // owns transport (e.g. WP CLI eval-file), just as it owns authentication.
  },
},
```

### Interactive source evidence

Also supply `outcomes.capture` (`static-site-importer/interactive-capture/v1`).
Its exhaustive `pages` array has one row per declared route: `route`, `sourceUrl`,
`interactionStates` (the capture's `blocks-engine-capture/interaction-states/v1`
or `/v2` envelope, including every state), `navigation` (every route-navigation
item, with `id` and source `destination`), and `forms` (every source form, with
`id`). Explicit empty arrays mean none; missing route inventories are unsupported.
Preserve source identities when adapting capture artifacts; never derive this
inventory from the imported DOM, which would hide dropped controls/forms.

Supply `outcomes.controls[id]` with a unique candidate `trigger` selector. For
`dialog`, `listbox`, `disclosure`, and `tabs` also supply `target`, `expected`
(visibility boolean or attribute string), optional `attribute`, and keyboard
activation `key` (default Enter). Each source member/transition must have its own
identity and binding. Navigation destinations come from source evidence, **not**
the candidate binding; local source destinations map to the candidate origin.
Every control is probed in a fresh logged-out context by click and real Tab/keyboard
activation at every manifest width. Missing bindings/unknown kinds are listed as
unsupported with reasons; measured dead triggers/wrong destinations fail and are
also listed as unreproduced. `unsupportedReason` explicitly records an unsupported
binding, never a waiver that passes acceptance.

Supply `outcomes.forms[id]` with `selector`, `fields` (accessible textbox name to
safe test value), `successSelector`, and at least one nonempty `recipient`,
`provider`, or `ownerHandoff` item describing the owner's remaining delivery work.
Probes require named fields, an explicit local POST handler, a successful actual
POST response, and newly visible success UI at each width. Missing ownership or
submission paths fail. Source provider endpoints are never intentionally contacted;
external/GET/JS-only provider paths currently fail rather than being certified.
Run only against a disposable import: these probes submit test data. Recipient/
provider declarations describe recovered routing, not proof of email delivery.
See `tests/acceptance/outcome-interactive-config.mjs` for a complete neutral example.

### Readability and full-document evidence

Declare `outcomes.colourSchemes` as a nonempty array of `light` and/or `dark`.
At every declared width and scheme, a fresh logged-out page measures computed
foreground/background colours for each visible text node and control. Evidence
includes selector, text, effective colours, ratio and WCAG AA threshold (4.5:1,
or 3:1 for large text). Alpha colours are composited. Uniform, decoded repeating
background images are measurable, including transparent pixels; nonuniform images,
unsafe canvas reads, unsupported geometry, generated content, filters, blending,
opacity and unmeasurable foregrounds are explicitly unsupported, never assumed
white or passed. Hidden text is excluded. Unsupported nodes leave acceptance pending;
a measured failure still fails even when other nodes are unsupported.

Supply `outcomes.documents[route]` with retained source `html`, `sourceOrigin`,
`sourceContent` and `candidateContent` unique selectors identifying the post-content
boundaries. The full-document check compares ordered chrome before and after those
boundaries: text, destinations, images and semantic/control elements. It includes
hidden chrome (including admin-bar remnants); it ignores script/style plumbing and
neutral wrappers, not banner text or widgets. Local source URLs normalize to route
paths. Head singletons remain owned by the existing head check.

Intentional differences require `documents[route].decisions` rows with a nonempty
`reason` and exact `expected` and `actual` chrome snapshots (the same objects retained
in evidence). A decision cannot wildcard or ignore subsequent additions. Missing
source evidence or ambiguous boundaries is unsupported. The real fixture records
the exact WordPress accessibility skip-link addition, not a blanket theme waiver.

The shipped PHP collector enumerates all five post types across statuses and
effective file-backed templates/parts. It calls real `parse_blocks()` and accounts
for balanced block comments separately because the core parser tolerates malformed
closing delimiters. Link scanning covers raw content, navigation, templates, parts
and logged-out head output. Alias probes disable automatic redirects and require
one destination-host redirect followed by a 200; chains and cycles fail.
Head probes require every declared singleton and destination-origin URL values.
Robots/generator/pingback metadata is conservatively rejected regardless of
provenance; caller-declared telemetry fingerprints are rejected too.

`node tests/acceptance/outcome-runner.mjs` imports a neutral HTML artifact into a
**fresh disposable** WordPress 7.1+ runtime via the real compiler/materializer,
then calls the public acceptance runner and portable consumer. Supply
`STATIC_SITE_IMPORTER_WP_CLI`, `SSI_OUTCOME_ORIGIN` (the served HTTP origin), and
`SSI_OUTCOME_DISPOSABLE=1`. It demonstrates a passing outcome baseline and failing
link, malformed navigation delimiter, duplicate canonical, dead trigger, wrong
navigation destination, handlerless form, low-contrast text, white-on-white control,
and injected footer regressions. Its disclosure and form
are imported from source HTML; the fixture-owned local provider handles submissions.
It deliberately does not fabricate DLA/editor evidence: unrelated gates stay pending.

Remaining slices: fixed real-source corpus and release matrix; CSS links and
shared-chrome anchors; installed-editor validation for every document (the existing
Gate C still validates mapped route posts); theme-switch independence. These are outside this check set and
are not certified by its pass result. No legacy comparator or editor save gate is
retired: the new outcome set replaces only the former outcome-free aggregate
acceptance predicate.

## Gate C and reusable evidence

Gate C validates the original with Gutenberg `wp.blocks.validateBlock`, clones
content into an unpublished disposable draft, resolves unique caller-mapped
registered blocks in `core/block-editor` (including registered companion blocks),
updates existing text and image attributes and calls `core/editor.savePost()`.
It reloads the editor, checks the same block positions and persisted attributes,
and verifies the authenticated draft preview renders the changed text/image.
Images use an existing, different Media Library attachment; no media is created.
Missing/ambiguous editable surfaces remain pending. No-op/failed saves fail.

Existing editor-presentation measurement and pixel oracle are reused for every
width against `portableOrigin`, which the caller must serve from the **frozen
directory**, with its captured CSS/images. This does not contact live source.
Missing presentation evidence remains pending; measured presentation divergence
fails. Originals are never dispatched for editing. Full original REST post and
referenced image attachment snapshots are hash-checked afterward. Draft deletion
is verified, and cleanup failure stays in evidence even after earlier failures.

`consumeExistingRuntimeAcceptance(root, report)` from
`lib/existing-runtime-acceptance.mjs` verifies retained artifact hashes and
re-evaluates fidelity, editor and measured fallback requirements. The aggregate
does not promote built/solved status; optional acceptance and owner handoffs keep
their existing dispositions and broader requirements. Reports redact credential
keys and credential-bearing URLs; auth hooks/endpoints are never serialized.

## Bounded observations and remaining integration evidence

```sh
SSI_ACCEPTANCE_BROWSER_SMOKE=1 node --test tools/run-existing-runtime-review.test.mjs tools/visual-parity-oracle.test.mjs tools/acceptance-handoff.test.mjs tools/owner-handoff-evidence.test.mjs
```

This exercises aggregate/stage/scope/hash/persistence predicates, a neutral real
Chromium health/fatal fixture and an explicitly unproven public-API seam fixture.
The seam fixture has no comparison/scoring implementation. It cannot validate
DLA fidelity or real WordPress editing. A validated DLA runtime, real WordPress
endpoint and retained Crosby were not supplied for this attempt. Capture/candidate
geometry, live-origin immutability and successful/failed real Gutenberg text/image
roundtrips must be observed with those supplied inputs; absence stays pending.
Homeboy owns the authoritative `npm test` gate after harvest.

AI assistance: OpenAI **gpt-6.1-sol via OpenCode**.
