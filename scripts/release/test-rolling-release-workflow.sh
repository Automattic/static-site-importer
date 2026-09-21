#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/release/refresh-blocks-engine.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

cat >"${TMP_DIR}/homeboy" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "${HOMEBOY_CAPTURE}"
SH
chmod +x "${TMP_DIR}/homeboy"

HOMEBOY_CAPTURE="${TMP_DIR}/capture" PATH="${TMP_DIR}:${PATH}" \
  HOMEBOY_DEPENDENCY_PAYLOAD='{"component":"blocks-engine-php-transformer","version":"0.16.2","tag":"v0.16.2","sha":"0123456789012345678901234567890123456789"}' \
  "${SCRIPT}" >/dev/null
grep -F -- 'release.update_dependency' "${TMP_DIR}/capture" >/dev/null
grep -F -- 'blocks-engine-php-transformer' "${TMP_DIR}/capture" >/dev/null

HOMEBOY_CAPTURE="${TMP_DIR}/capture" PATH="${TMP_DIR}:${PATH}" \
  HOMEBOY_DEPENDENCY_PAYLOAD='' HOMEBOY_DRY_RUN=true \
  "${SCRIPT}" >/dev/null
grep -F -- 'release.update_dependency' "${TMP_DIR}/capture" >/dev/null
grep -F -- '--data {}' "${TMP_DIR}/capture" >/dev/null

set +e
HOMEBOY_CAPTURE="${TMP_DIR}/capture" PATH="${TMP_DIR}:${PATH}" \
  HOMEBOY_DEPENDENCY_PAYLOAD='not-json' "${SCRIPT}" >/dev/null 2>&1
status=$?
set -e
[ "${status}" -ne 0 ]

set +e
HOMEBOY_CAPTURE="${TMP_DIR}/capture" PATH="${TMP_DIR}:${PATH}" \
  HOMEBOY_DEPENDENCY_PAYLOAD='{"component":"blocks-engine","version":"0.16.2","tag":"v0.16.2","sha":"0123456789012345678901234567890123456789"}' \
  "${SCRIPT}" >/dev/null 2>&1
status=$?
set -e
[ "${status}" -ne 0 ]

printf 'PASS: rolling release dependency delegation checks\n'
