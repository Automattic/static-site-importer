## Summary

Partial implementation of #2861; not ready to merge. Shared checks and occurrence-count acceptance replace the separate collateral/editability gate paths. Browser interactions and reversible theme restyling have been added. See `tools/import-loop/README.md` for remaining gaps.

## Tests

```
node --test tests/import-loop-progress.test.mjs
# tests 6; pass 6; fail 0
for f in tools/import-loop/*.mjs tools/import-loop/gates/*.mjs; do node --check "$f" || exit; done
# exit 0
git diff --check
# exit 0
```

## Deterministic gate retry

Registered `tests/import-loop-progress.test.mjs` in `test-manifest.json` as a Node test, resolving the reported undeclared-test failure. Moved the pinned compare client identity into `tools/import-loop/compare-client.json`, following the producer-neutrality guard's requirement that operator tools configure their capture producer.

Commands and outputs:

```text
npm ci
# exit 0
npm run test:inventory
# exit 0
node --test tests/import-loop-progress.test.mjs
# tests 6; pass 6; fail 0
```

The full `npm test` run reached 221 passing test files and 3 failures: the producer-neutrality failure (subsequently fixed and independently rechecked), a long-workspace-path ENOENT, and an existing solved-site-promotion assertion expecting WP Codebox v0.28.3 while the workflow pins v0.33.2. The full gate is therefore not claimed green.

## Deterministic promotion-contract retry

Updated the stale promotion-contract assertions to the workflow's existing WP Codebox v0.33.2 package, checksum, and commit, and the caller's composer-pinned Blocks Engine tag resolution. No runtime pins or acceptance requirements were weakened.

Verified release identity with:

```text
gh api repos/Automattic/wp-codebox/releases/tags/v0.33.2
# wp-codebox-workspace-0.33.2.tgz
# sha256:244674ee07718768a6710f31ba36489fac1157fb1991afa405723e4b581860df
gh api repos/Automattic/wp-codebox/git/tags/a7ca9fe6e29c86612a79e72ae816770f4f78e5f6
# commit 86f79300a4eb85487fbca4b088801b5ea9dd25ba
npm ci
# exit 0
node --test tools/verify-solved-site-promotion.test.mjs tests/import-loop-progress.test.mjs
# tests 57; pass 57; fail 0
npm test
# passed: 221; failed: 3; intentionally skipped: 0
# Remaining failures: external HTTPS fetch ETIMEDOUT / ENETUNREACH.
# The immutable WP Codebox promotion-contract test passes.
git diff --check
# exit 0
```

The complete suite is not claimed green, and the historical end-to-end and coverage gaps below remain unresolved.

## Historical end-to-end attempt (not established)

Capture: `/home/chubes/Studio/loop-runs/sdnunca-chain1-p1-source/sdnunca.wixsite.com`.
Importer ZIP: `/home/chubes/Studio/loop-runs/sdnunca-chain1-p1-triage/static-site-importer.zip`.
Bare WordPress: `/home/chubes/Studio/loop-runs/bare-wp`.
Artifacts: `/tmp/ssi-2861` (machine-local, not committed).

GitHub's current PR heads and bases were queried with:

```
gh pr view 5104 -R Automattic/blocks-engine --json baseRefOid,headRefOid,body
gh pr view 5174 -R Automattic/blocks-engine --json baseRefOid,headRefOid,body
```

Outputs:

```
5104 base 59a1aea43078346d70d6512492e0ccc801fc6b6c
5104 head 96d8791241163f3823ca92d5c2881529cd97fa6f
5174 base 144e299cf03a375160e26d7ecee04bdde6fc0e3f
5174 head add4da8b3eb3fe7b849fd3dcd41e4ec8080ca720
```

Each commit's `php-transformer` was extracted with `git archive` and imported:

```sh
SSI_ZIP=<zip above> BARE_WP=<bare above> bash tools/import-loop/gates/reimport.sh \
  <extracted php-transformer> <capture above> /tmp/ssi-2861/<site>
```

All four re-imports succeeded, printing local serving URLs. Gate commands:

```sh
node tools/import-loop/gates/accept.mjs \
  --base-url http://127.0.0.1:56393 --candidate-url http://127.0.0.1:51491 \
  --base-site /tmp/ssi-2861/base --candidate-site /tmp/ssi-2861/candidate \
  --capture <capture above> --url https://sdnunca.wixsite.com/ \
  --signature c94c3ba4298d --wp-cli 'php <bare above>/wp-cli.phar' \
  --out /tmp/ssi-2861/checks-v2
node tools/import-loop/gates/accept.mjs \
  --base-url <5174-base URL> --candidate-url <5174-candidate URL> \
  --base-site /tmp/ssi-2861/5174-base --candidate-site /tmp/ssi-2861/5174-candidate \
  --capture <capture above> --url https://sdnunca.wixsite.com/ \
  --signature 6d8bf4985b52 --wp-cli 'php <bare above>/wp-cli.phar' \
  --out /tmp/ssi-2861/5174-checks
```

The first compare reported:

```
materialization / desktop 390px baseline UNPROVEN:
Error: browserType.launch: Executable doesn't exist at
/home/chubes/.cache/ms-playwright/chromium_headless_shell-1254/chrome-headless-shell-linux64/chrome-headless-shell
```

Installed the release's browser using:

```
npx --yes --package=https://github.com/Automattic/data-liberation-agent/releases/download/v0.33.204/data-liberation-0.33.204.tgz playwright install chromium
# Chrome Headless Shell ... v1254 downloaded
```

The gate runs did not finish during verification. Their before/after artifacts must not be presented as a passing end-to-end test. The supplied capture's frozen comparison scope contains only `/`; other captured routes are correctly marked unverified by this implementation.

A separate diagnostic WP-CLI walk of saved page blocks returned:

```
5104 base:      {"ssi-my-site-1/captured-dialog":2}
5104 candidate: []
5174 base:      {"ssi-my-site-1/captured-dialog":2}
5174 candidate: {"ssi-my-site-1/captured-dialog":2}
```

These are not the issue's requested historical results: current final PR heads differ from the earlier historical candidates retained in the loop directories. Correct historical commit/capture pairing and complete gate runs are still required. The 7→5 behavior is covered only by a deterministic unit test, not claimed as an end-to-end result.

Implemented by Homeboy Cook (Pi, openai/gpt-6.1-sol). Issue, prompt and review by Franklin (Claude Opus via Pi, Roadie).
