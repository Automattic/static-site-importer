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
node tools/run-outcome-corpus.mjs \
  --manifest /private/corpus.json --output /private/runs/new \
  --producer-repository <owner/repo> \
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

When the override is used, the caller owns provisioning and credentials; SSI owns capture/import composition
and outcome evaluation. The installed editor's registry validates every inventoried
post, page, template, part and navigation document. Missing authentication or
editor scripts is unavailable, never a server-parse-only pass.

## Remaining work

The default toolchain resolves the latest GitHub Release of the capture producer
named by `--producer-repository` (using `gh`), takes its single `.tgz` package,
checks its published SHA-256 digest when present, and reads `provenance.json`
from the unpacked package. The capture CLI is the package's single `bin` entry.
`--producer-version <version>` selects a release;
`--producer-tarball /path/to/package.tgz` uses an offline local pack instead.
Local packs must also carry matching package/provenance names and versions and
an exact 40- or 64-hex source commit. The source commit is not inferred from the
release tag: packing precedes the release version-bump commit.

SSI identity comes from the checkout HEAD, plugin version header and porcelain
git status. Dirty state is retained in reports and called out in the matrix.
The installed Blocks Engine transformer carries `VERSION`, but no commit
provenance. Its version is checked against SSI's pin and installed Composer
metadata; the latter is retained as a declaration, not promoted to artifact
commit proof. The commit is `unverified`, with an upstream-provenance warning.

The default provisions fresh pinned SQLite WordPress per site through
`lib/disposable-wordpress.mjs`, imports through the consumer CLI request contract,
logs in to the installed editor and destroys the database and server even after
failure. Downloaded inputs are retained beside the output in `<output>.toolchain`
and the site's `downloads` directory. A runtime is never reused.

Captured fixture directories may include `outcome-bindings.json` containing the
JSON portions of the existing outcome configuration (source hosts, exhaustive
interactive capture, control/form bindings, documents, aliases and colour schemes).
Explicit values override automatic bindings, including individual document,
control and form entries. The runtime supplies inventory and theme-switch executors.

The default reads only `blocks-engine-capture/capture-receipt/v1` and its declared
reports (including the cleanup evidence path), plus the website's `_redirects`.
Capture sidecars remain outside the website; both directory and JSON artifact
intake are supported. No producer-specific filename is consulted.

- Redirect source paths become aliases with destination-relative expected paths;
  duplicate-route receipt entries also declare aliases. String aliases remain valid
  explicit bindings. Alias probes verify one redirect to the declared destination.
- A complete `source-cleanup` report declares telemetry markers: hosts and selectors
  of telemetry/analytics records with action `remove` or `removed`. Only actual
  removal records count, not policy rules. Complete reports with no such records
  explicitly declare an empty array. Truncated, failed or absent evidence does not.
  Selectors are also checked against the candidate head DOM.
- Documents use the retained PHP source server as `sourceOrigin`. A uniquely
  preserved declared content landmark or document scope class is a boundary;
  otherwise `main` is used only when both documents contain exactly one. Without
  a boundary the full-document check stays unsupported. The source server
  expands `<!--#include virtual="/parts/…" -->` shared parts with Blocks
  Engine's include grammar, so source documents are the pages a visitor saw.
- The full-document check compares what a visitor perceives in the chrome
  around the content, on each side of the boundary: text in reading order
  (whitespace-insensitive), the set of link destinations, labelled images, and
  landmarks, headings and controls. `/about/index.html`, `/about.html`,
  `/about/` and `/about` are one destination. A fragment the source page never
  defines matches the same fragment on any page. Wrapper markup, repeated links
  to one destination, decorative images and WordPress's skip link are not
  differences. Each failing row lists its `differences`.
- Captured interaction pages supply widths, components and route navigation.
  Source native forms are enumerated exhaustively. Candidate identities require
  a unique preserved id or `data-blocks-engine-capture-*` evidence attribute;
  classes, text and positional similarity are not identities. Transitions also
  require captured boolean visibility expectations or changed `hidden`/ARIA
  attributes in captured before/after snapshots. Unsupported controls retain
  individual reasons, including kinds the probe cannot exercise.
- A preserved form can be checked for missing delivery ownership (a failure),
  but automatic binding never invents a recipient, provider, submission fields
  or success assertion. An unidentifiable form remains unsupported. Explicit
  form contracts enable real submission probes.

A passing committed multi-site end-to-end regression proof remains unfinished.
Override declarations also still need installed-artifact verification. The default must not yet be
used as complete release certification.

Broader architecture slices remain tracked in
#2431 and its linked issues; this change does not close that issue.
