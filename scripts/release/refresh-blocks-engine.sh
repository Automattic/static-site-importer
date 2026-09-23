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

# Both packages track the newest stable Blocks Engine release of their
# component. Homeboy's release coordinate resolver reads that exact tag
# straight from the Blocks Engine repository, so there is one source of truth
# for "what is released" and no version policy to keep in step by hand.
#
# Blocks Engine is pre-1.0 and bumps its minor version for any breaking or
# feature change, so a release routinely crosses a minor line. Accepting it is
# deliberate: whether the new version actually works is decided by this
# repository's release import gate, which installs the built artifact and
# imports a real site before anything is tagged. The update itself never
# downgrades — the extension action refuses a version older than the lock.
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

# automattic/blocks-engine-php-transformer is published to Packagist from the
# blocks-engine-php-transformer subtree mirror. Mirror commits are rewritten
# by the subtree split, so the monorepo commit is not a mirror commit and is
# deliberately not asserted; the exact version is.
php="$(resolve_coordinates php-transformer)"
php_payload="$(jq -cn \
  --arg version "$(jq -r '.version' <<<"${php}")" \
  '{release:{component_id:"static-site-importer"},dependency:{package:"automattic/blocks-engine-php-transformer",version:$version,expected_source:"https://github.com/Automattic/blocks-engine-php-transformer.git"}}')"
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
