# SSI #1969 — canonical transformer lineage review

## Change and source relationship

Tracker: https://github.com/Automattic/static-site-importer/issues/1969.

Verified upstream base: `23ac2cde8c3d01bce9d2e114cbda3a02604d8397`.
Runtime-tested candidate: `53fb07487c61194cb4ff4e046e083c2f941dea64`.
The publication candidate adds this evidence document to the same implementation.

- Default URL evaluation reads the candidate consumer's `composer.lock` and uses the existing matrix `release-proof` mode without a transformer overlay.
- An explicitly requested transformer is copied into an evaluation-scoped snapshot, SHA-256 addressed, and checked through actual PHP autoload/callable exports before matrix launch.
- Incompatible preparation produces typed blocked evaluation evidence. Existing capture/candidate identity, completed-action dedupe, editor probes and acceptance gates remain authoritative.
- The SSI-owned fixture rig uses Homeboy's canonical implicit component-path binding, removing its rejected legacy `path_setting` field.

The locked compiler is `automattic/blocks-engine-php-transformer` **v0.34.3**, reference `e84d87cf049ba81dd615659c16a1504b6f7ff78f`.
The lock SHA-256 is `c2009ad4604c476666ac73058eafc6ad8f06beeb1efe786f1e273fec35868b36`.
Both `AssetAnalysis\\CssUrlRewriter::rewrite` and `AssetAnalysis\\SrcsetParser::parse` are callable from the installed package.
Explicit-overlay checks cover these required exports, not every compiler API.

## Deterministic verification

On the final implementation candidate:

- `npm run test:inventory`: passed.
- `npm test`: 121 selected manifest entries passed, zero failed (96 standalone PHP and 25 Node entries).
- The controller suite includes 10 passing tests covering pinned default/replay selection, real compatible exports, missing/forged exports, directory aliases and immutable evaluation refusal.
- `git diff --check`: passed.

The manifest excludes WordPress-runtime, browser/Codebox and operator-only entries; selected Node suites can contain conditional runtime skips. These gates do not establish completed retained-site acceptance.

## Real retained-capture runtime evidence

The original eight-route Quinn capture was reused after verifying its original receipt, handoff and content hashes. No recapture or reopening of completed actions was performed.

| Identity | Value |
| --- | --- |
| Source SHA-256 | `e40fb1ae670f7b0acf36ff0adfd3f52e53d610715e77f185a50847e8f040a1ad` |
| Receipt SHA-256 | `ce9773d0a8b2186b9015250bdeea3f279dc0f523868c859e4d41fb8a68b02258` |
| Content SHA-256 | `dd59fdbe28dc47fb21b6f6c4ea2e8598590a7d4775dc68679f685720a3a52e97` |
| Handoff SHA-256 | `31929a4d86fd43a8b0697b5b60c48d2b5670f9c968a67838443a244a50645738` |
| Matrix run | `ssi-1969-quinn-retained-53fb07487c61-canonical-rig-20261006` |
| Bench | `2a6c38fa-ccc8-4324-be43-e6126b57c1c6` |
| Codebox run | `run_25d595bf17e745b7a20c7df122fb2fa9` |
| Disposable runtime | `runtime-mux6vy3p-1hygak` |

The canonical generated recipe mounts the exact candidate with no dependency overlays and retains all eight editor probes. Homeboy's local worker was interrupted with `ECHILD`; its matrix result records `execution_status: not_requested` and `not_run: 1`. That receipt remains incomplete.

The same generated recipe was then executed directly using the official portable Codebox v0.32.1 package. Command logs record Composer autoloader provisioning with exit code 0, plugin activation with exit code 0 and an active-plugin readback containing SSI. Workflow step 0 also returned exit code 0, `Success: Plugin already activated.` The earlier missing-export activation fatal did not recur.

The run subsequently timed out during workflow step 1, `static-site-importer plan-artifact-dependencies --retain-compile-checkpoint`, before import/editor/visual completion. Its manifest records `RecipeRunTimeoutError`, code `recipe-run-timeout`, elapsed **600009 ms** against a **600000 ms** budget, failure phase `run_workloads`, and runtime status `destroyed`. The outer CLI invocation exceeded its separate 900-second tool budget waiting for exit. Timeout containment inside Codebox did execute; the remaining planning/teardown behavior needs separate diagnosis.

**Acceptance boundary:** pinned-package activation is proven. Completed retained import, editor validity, exact visual parity, zero fallback blocks and a solved-site promotion receipt are not proven. A new immutable evaluation is required after the planning blocker is resolved.

## Managed and direct provenance

Managed Cook `ssi-transformer-lineage-1969-20261006` retained the implementation from OpenCode session `ses_eed496775ffeeIxvN3mSgV1TgW`, model `openai/gpt-6.1-sol`. Its inventory gate passed; the full gate recorded 120 passing entries and one failure. The corrective provider attempt timed out before coding; native continuation refused retry. These outcomes remain failed.

Chris Huber explicitly authorized direct completion and publication in coordinator session `ses_f20fb98fdffe74WIq0U1RktA2b`. PHP reflection proved that the compatible snapshot's export filenames used a canonical temporary-directory path while the caller retained its alias. Direct completion canonicalized the PHP root with `realpath()` and added an alias regression preserving the package digest and export checks. Earlier tool-timeout verification runs remain interrupted; complete reruns passed.

The Linux runner continued to refuse execution because its daemon admission session was non-fresh. Direct verification used the isolated candidate and a disposable WordPress runtime. Finalization occurred outside managed Cook implementation, with deterministic gates and runtime limitations recorded for publication.

**AI assistance:** OpenAI `gpt-6.1-sol` through OpenCode implemented and reviewed the repair, ran verification, inspected retained runtime evidence and authored this review under Chris Huber's direction.
