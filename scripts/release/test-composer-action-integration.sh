#!/usr/bin/env bash
set -euo pipefail

# This is intentionally an action-level acceptance test, not a stub of the
# Homeboy CLI. It installs the released WordPress extension into isolated
# Homeboy roots and invokes the real release.update_dependency action against
# disposable Composer projects.
#
# Requires a Homeboy CLI build that supports `extension action --payload`
# with exit-code propagation (Extra-Chill/homeboy#14857). That fix has not
# shipped in a release yet as of this writing (latest is v0.382.0); this
# test will fail with "unexpected argument '--payload'" against any
# currently released homeboy binary until a new release ships. It is wired
# to the reusable release workflow for when that release exists — it is not
# run as a release gate today.

WORK_DIR="$(mktemp -d -t ssi-composer-action.XXXXXX)"
trap 'rm -rf "${WORK_DIR}"' EXIT

HOMEBOY_BIN="${HOMEBOY_BIN:-homeboy}"
EXTENSION_SOURCE="${EXTENSION_SOURCE:-https://github.com/Extra-Chill/homeboy-extensions.git}"
EXTENSION_REF="${EXTENSION_REF:-7f28a979517a96e003d0510d64bc5139c259837d}" # wordpress-v3.48.6
export HOMEBOY_CONFIG_ROOT="${WORK_DIR}/homeboy-config"
export HOMEBOY_DATA_DIR="${WORK_DIR}/homeboy-data"

"${HOMEBOY_BIN}" extension install "${EXTENSION_SOURCE}" \
  --id wordpress --ref "${EXTENSION_REF}" >/dev/null

make_archive() {
  local name="$1"
  mkdir -p "${WORK_DIR}/${name}"
  printf '{"name":"acme/%s","autoload":{"psr-4":{"Acme\\\\%s\\\\":"src/"}}}\n' "${name}" "${name}" >"${WORK_DIR}/${name}/composer.json"
  mkdir -p "${WORK_DIR}/${name}/src"
  printf '<?php\n' >"${WORK_DIR}/${name}/src/Library.php"
  (cd "${WORK_DIR}/${name}" && zip -qr "${WORK_DIR}/${name}.zip" .)
}

make_archive library
make_archive inline

mkdir -p "${WORK_DIR}/composer-repo"
cp "${WORK_DIR}/library.zip" "${WORK_DIR}/composer-repo/library-1.0.0.zip"
cp "${WORK_DIR}/library.zip" "${WORK_DIR}/composer-repo/library-1.1.0.zip"
cat >"${WORK_DIR}/composer-repo/packages.json" <<JSON
{"packages":{"acme/library":{"1.0.0":{"name":"acme/library","version":"1.0.0","dist":{"type":"zip","url":"file://${WORK_DIR}/composer-repo/library-1.0.0.zip"}},"1.1.0":{"name":"acme/library","version":"1.1.0","dist":{"type":"zip","url":"file://${WORK_DIR}/composer-repo/library-1.1.0.zip"}}}}}
JSON

run_action() {
  local project="$1" payload="$2"
  local component_id
  component_id="$(basename "${project}")"
  if [[ ! -f "${project}/.homeboy-created" ]]; then
    "${HOMEBOY_BIN}" component create --local-path "${project}" --extension wordpress >/dev/null
    touch "${project}/.homeboy-created"
  fi
  "${HOMEBOY_BIN}" component set "${component_id}" --local-path "${project}" >/dev/null
  payload="$(jq --arg path "${project}" '.release.local_path = $path' <<<"${payload}")"
  (cd "${project}" && "${HOMEBOY_BIN}" extension action wordpress release.update_dependency --payload "${payload}")
}

NORMAL="${WORK_DIR}/normal"
mkdir -p "${NORMAL}"
git -C "${NORMAL}" init -q
cat >"${NORMAL}/composer.json" <<JSON
{"repositories":[{"type":"composer","url":"file://${WORK_DIR}/composer-repo"}],"require":{"acme/library":"1.0.0"}}
JSON
run_action "${NORMAL}" '{"release":{"component_id":"fixture"},"dependency":{"package":"acme/library","version":"latest","latest_stable":true,"discovery_constraint":"^1.0","allow_constraint_replacement":true}}'
jq -e '.require["acme/library"] == "1.1.0"' "${NORMAL}/composer.json" >/dev/null
jq -e '.packages[] | select(.name == "acme/library") | .version == "1.1.0"' "${NORMAL}/composer.lock" >/dev/null

normal_hash="$(shasum "${NORMAL}/composer.json" "${NORMAL}/composer.lock")"
run_action "${NORMAL}" '{"release":{"component_id":"fixture"},"dependency":{"package":"acme/library","version":"1.1.0"}}' >/dev/null
[ "${normal_hash}" = "$(shasum "${NORMAL}/composer.json" "${NORMAL}/composer.lock")" ]

set +e
run_action "${NORMAL}" '{"release":{"component_id":"fixture"},"dependency":{"package":"acme/library","version":"1.0.0"}}' >/dev/null 2>&1
status=$?
set -e
[ "${status}" -ne 0 ]
[ "${normal_hash}" = "$(shasum "${NORMAL}/composer.json" "${NORMAL}/composer.lock")" ]

set +e
run_action "${NORMAL}" '{"release":{"component_id":"fixture"},"dependency":{"package":"acme/missing","version":"1.0.0"}}' >/dev/null 2>&1
status=$?
set -e
[ "${status}" -ne 0 ]

set +e
run_action "${NORMAL}" '{"release":{"component_id":"fixture"},"dependency":{"package":"acme/library","version":"9.9.9"}}' >/dev/null 2>&1
status=$?
set -e
[ "${status}" -ne 0 ]
[ "${normal_hash}" = "$(shasum "${NORMAL}/composer.json" "${NORMAL}/composer.lock")" ]

INLINE="${WORK_DIR}/inline"
mkdir -p "${INLINE}"
git -C "${INLINE}" init -q
cp "${WORK_DIR}/inline.zip" "${INLINE}/inline.zip"
cat >"${INLINE}/composer.json" <<JSON
{"repositories":[{"type":"package","package":{"name":"acme/inline","version":"1.0.0","source":{"type":"git","url":"https://example.test/acme/inline.git","reference":"old-mirror"},"dist":{"type":"zip","url":"file://${INLINE}/inline.zip","reference":"old-mirror"}}}],"require":{"acme/inline":"1.0.0"}}
JSON
(cd "${INLINE}" && composer install --no-interaction --no-progress >/dev/null)
# sha and expected_source_sha are the same value here because that is how
# SSI's own caller invokes this action for the Figma coordinate: both are
# populated from the one commit `homeboy release resolve` returns, so the
# action's post-write assertion is verifying its own rewrite, not comparing
# two independently-sourced values.
run_action "${INLINE}" '{"release":{"component_id":"fixture"},"dependency":{"package":"acme/inline","version":"1.1.0","tag":"v1.1.0","sha":"new-mirror","expected_source":"https://example.test/acme/inline.git","expected_source_sha":"new-mirror"}}' >/dev/null
jq -e '.require["acme/inline"] == "1.1.0"' "${INLINE}/composer.json" >/dev/null
jq -e '.packages[] | select(.name == "acme/inline") | .version == "1.1.0" and .source.reference == "new-mirror"' "${INLINE}/composer.lock" >/dev/null

printf 'PASS: real Homeboy Composer action fixture acceptance\n'
