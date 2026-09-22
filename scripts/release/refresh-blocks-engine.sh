#!/usr/bin/env bash
set -euo pipefail

# SSI resolves its own Blocks Engine dependency freshness. It is never told
# about a new upstream release — Blocks Engine does not notify this
# repository, and this repository does not accept an external payload
# describing what to pin. Composer discovery, availability checks, lock
# regeneration, and rollback are all owned by the WordPress extension's
# `release.update_dependency` action; this script only supplies the
# coordinates for the two Blocks Engine packages SSI depends on.
#
# No newer release for either package is a successful no-op: this script
# still exits 0, the caller's `git diff` finds nothing to commit, and SSI's
# own release (if any) proceeds unaffected. A failed resolution, a failed
# Composer solve, or a rejected verification exits non-zero and leaves
# composer.json/composer.lock byte-for-byte unchanged — the extension action
# writes through a temp directory and only moves files into place on success.

invoke() {
  local action_payload
  action_payload="$(jq -c --arg path "$(pwd)" '.release.local_path = $path' <<<"${1}")"
  homeboy extension action wordpress release.update_dependency --payload "${action_payload}"
}

# automattic/blocks-engine-php-transformer is an exact Packagist version pin
# (composer.json requires "0.16.1", not a caret range), so Composer cannot
# discover a replacement without an explicit policy constraint. The
# discovery_constraint documents that policy: accept the newest 0.16.x
# stable release, never jump a minor line unnoticed, and never accept a
# prerelease (the extension action enforces both).
php_payload='{"release":{"component_id":"static-site-importer"},"dependency":{"package":"automattic/blocks-engine-php-transformer","version":"latest","latest_stable":true,"discovery_constraint":"^0.16.0","allow_constraint_replacement":true,"expected_source":"https://github.com/Automattic/blocks-engine-php-transformer.git"}}'
if [[ "${HOMEBOY_DRY_RUN:-false}" == "true" ]]; then
  php_payload="$(jq '.dependency.mode = "dry-run"' <<<"${php_payload}")"
fi
invoke "${php_payload}"

# automattic/blocks-engine-figma-transformer is an inline monorepo-archive
# package (Composer type: "package", not a registry entry), so it has no
# Composer version index to discover against. Homeboy's own release
# coordinate resolver reads the newest exact stable tag directly from the
# Blocks Engine repository instead.
coordinates="$(homeboy release resolve https://github.com/Automattic/blocks-engine.git --prefix figma-transformer)"
coordinate_data="$(jq -cer '.data // .result.data // empty' <<<"${coordinates}")"
figma_payload="$(jq -cn \
  --arg version "$(jq -r '.version' <<<"${coordinate_data}")" \
  --arg tag "$(jq -r '.tag' <<<"${coordinate_data}")" \
  --arg sha "$(jq -r '.commit' <<<"${coordinate_data}")" \
  '{release:{component_id:"static-site-importer"},dependency:{package:"automattic/blocks-engine-figma-transformer",version:$version,tag:$tag,sha:$sha,expected_source:"https://github.com/Automattic/blocks-engine.git",expected_source_sha:$sha}}')"
if [[ "${HOMEBOY_DRY_RUN:-false}" == "true" ]]; then
  figma_payload="$(jq '.dependency.mode = "dry-run"' <<<"${figma_payload}")"
fi
invoke "${figma_payload}"
