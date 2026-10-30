# Private outcome corpus

Refs #2431. This slice adds a private manifest contract and composes the existing
URL intake, fixture matrix and runtime acceptance entry points. No private
manifest or generated evidence belongs in the public repository.

The manifest contract is [outcome-corpus-v1.schema.json](contracts/outcome-corpus-v1.schema.json).
Use opaque stable IDs. Each entry chooses an HTTP(S) source URL or a captured
artifact directory (relative paths resolve beside the manifest). An artifact
must satisfy the existing generated-artifact intake contract. Routes optionally
restrict acceptance scope. `expected_decisions` record exact full-document
before/after differences with an explanation; they cannot exempt a check.

Run from the SSI checkout:

```sh
SSI_OUTCOME_TOOLCHAIN=/private/toolchain.mjs node tools/run-outcome-corpus.mjs \
  --manifest /private/corpus.json --output /private/runs/new \
  --compare /private/runs/previous/matrix.json
```

The output directory must be fresh. Omit `--compare` for the first run.
`matrix.json` and `matrix.md` contain check × site results and changed cells;
comparison ignores evidence-path churn. Any fail or unavailable cell fails the
run. Each site's `report.json` records the outcome report and toolchain identity,
including exact versions and commits. Import and acceptance artifacts are retained
under that site. Evidence may include private content and runtime origins.

## Runtime integration

The private toolchain module exports a default object with:

- `staticSiteImporter`, `blocksEngine`: paths consumed by the existing matrix.
- `identities`: `capture` (Data Liberation Agent), `ssi`, `blocks_engine`, each with `version` and full `commit`.
- `capture`: the existing URL intake capture configuration (`command` argv with
  `{url}` and `{output}`, and optional `receipt_schema`). Use the installed DLA
  release's capture entry point.
- `matrixArgs`: optional existing fixture-matrix command-line options.
- `openRuntime({site, directory, imported})`: resolves to `{config, close}`.
  `config` is the existing runtime acceptance configuration, including disposable
  destination origin, authentication, route inventory and outcome capture bindings.
  `close` disposes the runtime, including on failure. Never point at production.

The caller owns provisioning and credentials; SSI owns capture/import composition
and outcome evaluation. The installed editor's registry validates every inventoried
post, page, template, part and navigation document. Missing authentication or
editor scripts is unavailable, never a server-parse-only pass.

## Remaining work

The current adapter does not automatically resolve or verify current paired
release artifacts; supplied identity declarations are not yet checked against
installed binaries. A self-provisioning disposable runtime adapter and a committed
multi-site end-to-end regression proof remain required before this slice can
certify a private release corpus.

The release gate's pinned WordPress, WP-CLI and SQLite provisioning is now shared
in `lib/disposable-wordpress.mjs`. It rejects reused runtime directories and
removes partially staged runtimes on provisioning failure. The default outcome
toolchain is still blocked on installed-artifact commit provenance: the published
DLA `v0.33.43` archive records its version in `package.json` but does not carry a
commit identity. A release tag alone is not installed-artifact evidence. The
producer must ship commit provenance before the default can satisfy that contract.

Broader architecture slices remain tracked in
#2431 and its linked issues; this change does not close that issue.
