# Issue 2014: preserved candidate and remediation

## Source relationship and preservation boundary

The controller preserved the authorized harvested candidate as
`0d09ade7f45135111df5ac2ae3d8995854aa0bce`, from Cook
`agent-task-1c79ac00-857b-4c97-8864-fb5ea7d5ec19`. This preservation commit was
**outside Homeboy finalization**. Convergence merge `aa183516` incorporates
upstream `3915c25e183fd404f2a5714fb76d72e9b44cd667`. Remediation preserves both.

Prior source evidence supplied by the controller: snapshot `1a51ea04` plus the
candidate diff; Lab runner job `af6d7322-23a6-4d86-9722-f7cd7486704e`, reporting
119 passed / 4 failed. This attempt reproduced 120 passed / 3 failed manifest
entries after preparing dependencies and WordPress core. The two topology test
failures shared one assertion about core's backslash representation.

Implementation and verification assistance: **OpenAI gpt-6.1-sol**, through
**OpenCode** and **Homeboy**. Publication belongs to controller finalization.

## Corrections and evidence boundaries

- Exporter repair uses its existing theme-directory resolver, including the
  requested export theme's metric configuration. Standalone export remains
  deterministic without `get_stylesheet_directory`. Export smoke coverage is
  structural; it does not prove a live external metric provider fetch.
- Preflight admits only pending block names from a validated destination runtime
  package. Persistence repeats admission against WordPress's actual registry.
  Existing unknown-block, conflict and filesystem preflight checks still run.
- Runtime registration refreshes existing metadata/render declarations, removes
  stale registrations owned by the same destination, rejects foreign ownership
  and journals registration rollback. Real WordPress probes exercise an already
  loaded callback and a changed render/attribute payload in one request.
- Generated callback configuration reads fresh declarations; repeated lifecycle
  registration does not overwrite existing registry entries. Classic projection
  recomposes the loader without duplicating the previously composed runtime files.
- Export/reimport repair retains only local, materialized document-script
  references in page provenance and exports portable references to packaged
  script assets. Tests reject traversal, query, foreign and non-script targets,
  and verify nested-route references and supported attributes.
- Standalone materializer captures filter registration; font requests are checked
  by exact URL sequence, including upstream's deferred report-publication import.
  Form serialization checks core byte equivalence, lossless backslashes and
  comment safety rather than requiring one historical escape spelling.
- Docker fixtures use the existing provenance-bound isolated-preview script
  policy. Shared-Lab MySQL native AIO exhaustion (`io_setup EAGAIN`) was observed;
  disposable databases use MySQL's supported non-native-AIO backend.

## Reproduction on Linux Lab

```sh
export PATH=/home/chubes/.local/bin:/usr/local/bin:/usr/bin:/bin
export PLAYWRIGHT_BROWSERS_PATH=/home/chubes/.cache/ms-playwright
npm ci
composer install --no-interaction --prefer-dist
npm test
npm run test:runtime-package
SSI_EDITOR_EVIDENCE_DIR=/home/chubes/Developer/ssi-theme-runtime-2014-evidence/repair-11 npm run test:editor-companion-acceptance
```

Provider runs are **attempt observations**, never authoritative final gate
results. Homeboy separately reruns the declared controller-owned gates after
harvesting the candidate. Hydrated snapshots without `.git` must initialize Git
and stage `tests tools` for inventory before these commands.

Real acceptance records WordPress/Gutenberg versions, image digests, source
revision/diff, import reports, browser validation/save, frontend script responses,
provider wrapper projection through real WordPress hooks, canonical export file
hashes and second-site import provenance, HTTP redirect locations, and lifecycle
after SSI deactivation on both sites. Provider evidence is the packaged wrapper
projection hook; it is not a full Jetpack submission or delivery acceptance.

Visual comparison is explicitly bounded to the 100 × 60 authored canvas at a
1440 × 1000 viewport. Zero changed pixels in that region does not establish
whole-page, remote iframe, responsive-corpus, or broader visual parity.

Failures and corrections are retained under the allowed Lab artifact directory:
the root runner log records initial admission/paint failures; `diagnostic-3` and
`diagnostic-4` capture missing script delivery; `repair-5`/`repair-6` capture
script-policy corrections and second-database startup failure; `repair-7` records
the native-AIO failure; `repair-8` records stale README wording; `repair-9` records
the second-site missing script reference; `repair-10` records the completed round
trip and corrected regex warning. `repair-11` is the corrected-byte rerun.

Observed results on corrected implementation bytes: `npm test` reports 123
passed / 0 failed manifest entries; `npm run test:runtime-package` passes five
Node tests and the PHP ability-registration idempotency smoke. Docker acceptance
`repair-11` completes successfully on WordPress 7.1.2 / Gutenberg 24.1.0, including
the second-site painted canvas after importer deactivation. Each of its three
canvas comparisons reports zero changed pixels. These remain provider
observations pending Homeboy's independent final gates.
