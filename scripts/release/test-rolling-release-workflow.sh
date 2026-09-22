#!/usr/bin/env bash
set -euo pipefail

# SSI is fully decoupled from Blocks Engine: this script never receives an
# external payload, so these checks exercise the one behavior the wrapper
# actually has left — always resolving both Blocks Engine coordinates itself
# and forwarding them through `extension action --payload`, with dry-run
# propagated to both, and a real invoke failure aborting before the second
# (Figma) call runs.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/release/refresh-blocks-engine.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

cat >"${TMP_DIR}/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$1 $2 $3" == "release resolve https://github.com/Automattic/blocks-engine.git" ]]; then
  printf '%s\n' '{"data":{"version":"0.3.0","tag":"figma-transformer-v0.3.0","commit":"0123456789012345678901234567890123456789"}}'
  exit 0
fi
if [[ "$1 $2 $3 $4 $5" == "extension action wordpress release.update_dependency --payload" ]]; then
  printf '%s --payload-json=%s\n' "$*" "$6" >> "${HOMEBOY_CAPTURE}"
else
  printf '%s\n' "$*" >> "${HOMEBOY_CAPTURE}"
fi
if [[ "${HOMEBOY_FAIL_INVOKE:-false}" == "true" ]]; then
  exit 1
fi
SH
chmod +x "${TMP_DIR}/homeboy"

# --- Normal run: both packages always self-resolved, no external input ---
: >"${TMP_DIR}/capture"
HOMEBOY_CAPTURE="${TMP_DIR}/capture" PATH="${TMP_DIR}:${PATH}" "${SCRIPT}" >/dev/null

php_call="$(sed -n '1p' "${TMP_DIR}/capture")"
figma_call="$(sed -n '2p' "${TMP_DIR}/capture")"

grep -F -- 'release.update_dependency' <<<"${php_call}" >/dev/null
grep -F -- 'automattic/blocks-engine-php-transformer' <<<"${php_call}" >/dev/null
grep -F -- '"latest_stable":true' <<<"${php_call}" >/dev/null
grep -F -- '"discovery_constraint":"^0.16.0"' <<<"${php_call}" >/dev/null
grep -F -- 'expected_source' <<<"${php_call}" >/dev/null

grep -F -- 'automattic/blocks-engine-figma-transformer' <<<"${figma_call}" >/dev/null
grep -F -- '"version":"0.3.0"' <<<"${figma_call}" >/dev/null
grep -F -- '"tag":"figma-transformer-v0.3.0"' <<<"${figma_call}" >/dev/null
grep -F -- '"sha":"0123456789012345678901234567890123456789"' <<<"${figma_call}" >/dev/null

# Neither call carries a dry-run mode marker on a real run.
if grep -Eq -- '"mode"[[:space:]]*:[[:space:]]*"dry-run"' "${TMP_DIR}/capture"; then
  printf 'FAIL: a non-dry-run invocation must not mark either payload dry-run\n' >&2
  exit 1
fi

# --- Dry-run: both payloads carry the dry-run mode marker ---
: >"${TMP_DIR}/capture"
HOMEBOY_CAPTURE="${TMP_DIR}/capture" PATH="${TMP_DIR}:${PATH}" HOMEBOY_DRY_RUN=true \
  "${SCRIPT}" >/dev/null

dry_run_markers="$(grep -cE -- '"mode"[[:space:]]*:[[:space:]]*"dry-run"' "${TMP_DIR}/capture")"
[ "${dry_run_markers}" -eq 2 ]
grep -F -- 'automattic/blocks-engine-php-transformer' "${TMP_DIR}/capture" >/dev/null
grep -F -- 'automattic/blocks-engine-figma-transformer' "${TMP_DIR}/capture" >/dev/null

# --- A real extension action failure aborts the script (nonzero exit) so the
# caller's git-diff-gated commit step never runs against a partial refresh,
# and the Figma call (which runs second) is never reached ---
: >"${TMP_DIR}/capture"
set +e
HOMEBOY_CAPTURE="${TMP_DIR}/capture" HOMEBOY_FAIL_INVOKE=true PATH="${TMP_DIR}:${PATH}" \
  "${SCRIPT}" >/dev/null 2>&1
status=$?
set -e
[ "${status}" -ne 0 ]
[ "$(wc -l <"${TMP_DIR}/capture" | tr -d ' ')" -eq 1 ]
grep -F -- 'automattic/blocks-engine-php-transformer' "${TMP_DIR}/capture" >/dev/null
if grep -F -- 'automattic/blocks-engine-figma-transformer' "${TMP_DIR}/capture" >/dev/null; then
  printf 'FAIL: a failed PHP-transformer invoke must stop before resolving Figma\n' >&2
  exit 1
fi

printf 'PASS: rolling release dependency delegation checks\n'
