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

# The registry-backed PHP transformer is resolved from Composer's available
# stable releases, not from a raw Blocks Engine monorepo tag. Its declared
# 0.x discovery range intentionally admits cross-minor releases; the owning
# release import gate tests the refreshed package before the SSI release.
# `release.update_dependency` resolves Composer's stable version, verifies the
# published source, replaces the exact pin and lock atomically, and refuses a
# downgrade. A minor release not yet mirrored to Packagist therefore cannot
# become an unavailable Composer pin.
#
# Figma transformer is an inline monorepo-archive package, so it still needs
# exact upstream tag and commit coordinates.
resolve_coordinates() {
  local coordinates
  coordinates="$(homeboy release resolve https://github.com/Automattic/blocks-engine.git --prefix "${1}")"
  jq -cer '.data // .result.data // empty' <<<"${coordinates}"
}

dry_run() {
  if [[ "${HOMEBOY_DRY_RUN:-false}" == "true" ]]; then
    jq -c '.dependency.mode = "dry-run"' <<<"${1}"
  else
    printf '%s\n' "${1}"
  fi
}

# The PHP Transformer's Packagist source/dist refs belong to its subtree
# mirror; Composer resolves those refs with the selected available version.
# Do not derive or assert them from the separate monorepo tag SHA.
php_payload='{"release":{"component_id":"static-site-importer"},"dependency":{"package":"automattic/blocks-engine-php-transformer","version":"latest","discovery_constraint":">=0.1.0 <1.0.0","allow_constraint_replacement":true,"expected_source":"https://github.com/Automattic/blocks-engine-php-transformer.git"}}'
invoke "$(dry_run "${php_payload}")"

# automattic/blocks-engine-figma-transformer is an inline monorepo-archive
# package (Composer type: "package", not a registry entry), so the archive is
# pinned to the exact monorepo tag and commit.
figma="$(resolve_coordinates figma-transformer)"
figma_payload="$(jq -cn \
  --arg version "$(jq -r '.version' <<<"${figma}")" \
  --arg tag "$(jq -r '.tag' <<<"${figma}")" \
  --arg sha "$(jq -r '.commit' <<<"${figma}")" \
  '{release:{component_id:"static-site-importer"},dependency:{package:"automattic/blocks-engine-figma-transformer",version:$version,tag:$tag,sha:$sha,expected_source:"https://github.com/Automattic/blocks-engine.git",expected_source_sha:$sha}}')"
invoke "$(dry_run "${figma_payload}")"
