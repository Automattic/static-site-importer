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
`static-site-importer/existing-runtime-acceptance/v1`, with retained artifacts,
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
  "schema": "static-site-importer/dla-runtime-validation/v1",
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
