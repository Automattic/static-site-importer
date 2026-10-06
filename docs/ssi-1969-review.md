# SSI #1969 — canonical transformer lineage review

## Source relationship and change

- Fetched base: `origin/main`, `8b8915158e944428b9d495973f4912c29ed0e520`.
- Checkout HEAD: `8b8915158e944428b9d495973f4912c29ed0e520`; implementation is an uncommitted working-tree candidate for coordinator harvest.
- Implementation and regression coverage: `tools/url-loop-controller.mjs` and `tools/url-loop-controller.test.mjs`.
- Prepared by OpenAI `gpt-6.1-sol` (`openai/gpt-6.1-sol`) through OpenCode in a Homeboy-supplied isolated attempt. Homeboy owns authoritative post-harvest gates; the coordinator owns retained-capture acceptance and publication review.

| Runtime change | Existing source contract reused | Verification capability | Evidence boundary |
| --- | --- | --- | --- |
| Default evaluation selects the candidate consumer lock | Matrix `release-proof` mode; existing Codebox consumer provisioning | Reads the real lock; verifies default/replay command selection | Matrix launch is intercepted in controller regressions; disposable WordPress activation is unverified here |
| Explicit overlay is copied into a fresh evaluation-scoped snapshot and SHA-256 addressed | Existing transformer package resolver and Composer overlay `reference` field | Real installed package passes PHP autoload/callable checks; missing and forged exports fail before launch | Checks the two required asset-analysis exports, not every compiler API or WordPress lifecycle behavior |
| Preparation failure emits a blocked evaluation with evidence | Existing evaluation artifact and source/capture/candidate identities | Missing path, wrong package, missing/forged export, and immutable-result refusal coverage | This is preparation evidence, not a failed activation relabeled as successful |
| Compiler identity is retained in evaluation output and forwarded for explicit overlays | Canonical `package`, `version`, `reference`, `composer_lock_sha256`; existing runtime matrix receipts | Default lock identity and explicit snapshot reference are asserted | Real retained-run matrix receipts must be collected by the coordinator |

## Compiler and export observations

`composer install --no-interaction --prefer-dist` succeeded in the isolated checkout and generated autoload files without changing the declared lock.

- Package: `automattic/blocks-engine-php-transformer`.
- Current installed/locked version: `v0.34.2`.
- Source/dist reference: `44f425f349a27f1dd1025324d325c8cdd9d04e94`.
- `Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter` autoloads from the installed package; `rewrite` is callable.
- `Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser` autoloads from the installed package; `parse` is callable.
- Explicit candidates must pass the same class/method checks from their own snapshot. A missing PHP executable or incompatible export produces preparation evidence rather than another compiler selection.

## Local verification observations

- `npm run test:inventory`: passed.
- `npm test`: completed successfully; 121 selected manifest entries passed, zero failed (96 standalone PHP, 25 Node entries). The manifest excludes 19 WordPress-runtime, 2 browser/Codebox, and 20 operator-only entries; some selected Node suites also contain conditional runtime skips.
- `node --test tools/url-loop-controller.test.mjs`: 10 passed, zero failed/skipped, including the native Homeboy WorkJob capture/recheck test.
- One full-suite invocation was interrupted by the tool's 120-second timeout at the controller suite. A focused rerun passed, followed by a complete successful full-suite rerun with a longer tool timeout.
- `git diff --check`: passed.

These are attempt observations, not controller-owned final gate results.

## Retained evidence and acceptance handoff

The retained Quinn capture and prior results were not accessed or modified. Regression coverage replays one synthetic eight-route handoff across two candidate identities, verifies unchanged handoff bytes and capture hashes, and requests all seven secondary surfaces. Existing matrix/editor URL targeting and zero-fallback, visual, editor, and promotion gates remain in use.

Backend limitation: `runtime_unavailable / codebox_runtime_outside_attempt_access`. The supplied acceptance runtime is on Linux; this attempt is macOS and external Codebox cache discovery was denied by the attempt access boundary. No disposable WordPress activation or real retained-capture acceptance was executed. Historical admission/version skew was not diagnosed or altered.

Coordinator follow-up: harvest an immutable candidate, run authoritative gates, and evaluate the retained eight-route capture through native supported placement using a new immutable run/action identity and a legal action budget. Retain both default pinned-package activation proof and explicit overlay activation/refusal proof. Solved-site acceptance requires the existing promotion receipt; this implementation makes no solved-site claim.

## Coordinator completion and provenance

Managed Cook `ssi-transformer-lineage-1969-20261006` retained the implementation from OpenCode session `ses_eed496775ffeeIxvN3mSgV1TgW`, model `openai/gpt-6.1-sol`. The controller-owned inventory gate passed; its full gate recorded 120 passing entries and one failure. The corrective provider attempt failed its readiness timeout before coding, and native continuation refused retry. These outcomes remain recorded as failures.

Chris explicitly authorized direct OpenCode completion in coordinator session `ses_f20fb98fdffe74WIq0U1RktA2b`. A real PHP readback proved both required exports were callable but their reflection filenames used a canonical temporary path while the snapshot root retained its alias. The coordinator canonicalized the PHP root with `realpath()` and added an explicit directory-alias regression retaining the same immutable package digest and required export checks.

Coordinator `npm run test:inventory`, `npm test` and `git diff --check` passed on the corrected candidate: 121 manifest entries passed, zero failed, including all 10 controller tests. An earlier coordinator full-suite command reached its tool timeout and was retained as interrupted; the complete rerun passed with an appropriate time budget. These direct verification results do not rewrite the earlier managed gate or imply WordPress activation acceptance.

The coordinator provisioned the official portable Codebox v0.32.1 workspace package for disposable runtime verification, while the supplied Linux runner remained version-skewed. Real retained-capture import proof and PR CI are distinct from the passing deterministic gates above.
