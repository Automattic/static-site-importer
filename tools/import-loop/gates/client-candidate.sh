#!/usr/bin/env bash
# A capture-client candidate, measured as it would ship: build and pack the
# client from <client source> (the client repository's tree merged onto the
# release commit the pass captured with), capture <url> with it and compare-client.json's
# `capture_args` into <capture>, then import that capture on <php-transformer
# dir> (gates/reimport.sh) and print the served site's URL. The packed client
# stays as <capture>/.client.tgz so the compare uses the same build
# (IMPORT_LOOP_CLIENT_PACKAGE in checks.mjs). The merged tree is no git
# checkout, so pack skips the release's provenance stamp (prepack).
#
# Usage: client-candidate.sh <client source> <url> <capture> <php-transformer dir> <site dir>
# Env: BARE_WP, SSI_ZIP (as gates/reimport.sh)
set -euo pipefail
src=$(cd "$1" && pwd); url=$2; capture=$3; transformer=$4; site=$5
here=$(cd "$(dirname "$0")" && pwd)
client="$here/../compare-client.json"
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
(cd "$src" && npm ci --ignore-scripts --no-audit --no-fund && npm run build && npm pack --ignore-scripts --pack-destination "$work") >&2 \
  || { echo "import_failed: the candidate client does not build" >&2; exit 1; }
package=$(ls "$work"/*.tgz | head -1)
capture_args=(); while IFS= read -r arg; do capture_args+=("$arg"); done < <(jq -r '.capture_args[]' "$client")
npx --yes "--package=$package" "$(jq -r .executable "$client")" "$url" --output "$work/source" "${capture_args[@]}" >&2 || true
kept=$(dirname "$(ls "$work"/source/*/capture-receipt.json 2> /dev/null | head -1 || true)")
[ -f "$kept/capture-receipt.json" ] || { echo "import_failed: the candidate client kept no capture for $url" >&2; exit 1; }
rm -rf "$capture"; mkdir -p "$(dirname "$capture")"; mv "$kept" "$capture"; cp "$package" "$capture/.client.tgz"
bash "$here/reimport.sh" "$transformer" "$capture" "$site"
