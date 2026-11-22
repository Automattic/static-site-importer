#!/usr/bin/env bash
# The Static Site Importer release zip the loop imports with, written to $1.
# SSI_ZIP names a pre-downloaded release zip (no network or gh auth needed);
# otherwise the newest release whose zip has finished uploading is downloaded:
# auto-release publishes the tag a few minutes before its asset.
# Prints the release tag, read from the zip's plugin header.
set -euo pipefail
out=$1
if [ -n "${SSI_ZIP:-}" ]; then
  [ -f "$SSI_ZIP" ] || { echo "SSI_ZIP is not a file: $SSI_ZIP" >&2; exit 1; }
  [ "$(realpath "$SSI_ZIP")" = "$(realpath -m "$out")" ] || cp "$SSI_ZIP" "$out"
else
  tag=$(gh api 'repos/Automattic/static-site-importer/releases?per_page=10' --jq '[.[] | select(.draft | not) | select(any(.assets[]; .name == "static-site-importer.zip" and .state == "uploaded"))][0].tag_name')
  gh release download "$tag" -R Automattic/static-site-importer -p static-site-importer.zip -O "$out" --clobber
fi
version=$(unzip -p "$out" static-site-importer/static-site-importer.php | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -1)
[ -n "$version" ] || { echo "no Version header in $out" >&2; exit 1; }
echo "v$version"
