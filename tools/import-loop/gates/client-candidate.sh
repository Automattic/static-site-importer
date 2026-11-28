#!/usr/bin/env bash
# A capture-client candidate, measured as it would ship: build and pack the
# client from <client source> (the client repository's tree merged onto the
# release commit the pass captured with), re-export the pass's frozen capture
# <base capture> of <url> with it (`--resume` and compare-client.json's
# `capture_args`) into <capture>, then import that capture on <php-transformer
# dir> (gates/reimport.sh) and print the served site's URL.
#
# The re-export reuses every captured route and its frozen reference, so the
# candidate is compared with the same source evidence the pass evaluated: a
# live re-capture differs from it in a cell or two each time, and acceptance
# reads that as a regression. A change to how the client captures the browser
# shows in the next pass's own capture instead. The packed client stays as
# <capture>/.client.tgz so the compare uses the same build
# (IMPORT_LOOP_CLIENT_PACKAGE in checks.mjs), and <capture>/.capture-client.json
# names it as the client the capture was exported with, as import.sh does for
# a release capture. The merged tree is no git checkout, so pack skips the
# release's provenance stamp (prepack).
#
# Usage: client-candidate.sh <client source> <url> <base capture> <capture> <php-transformer dir> <site dir>
# Env: BARE_WP, SSI_ZIP (as gates/reimport.sh)
set -euo pipefail
src=$(cd "$1" && pwd); url=$2; base=$3; capture=$4; transformer=$5; site=$6
here=$(cd "$(dirname "$0")" && pwd)
client="$here/../compare-client.json"
work=$(mktemp -d); trap 'chmod -R u+w "$work" 2>/dev/null; rm -rf "$work"' EXIT
(cd "$src" && npm ci --ignore-scripts --no-audit --no-fund && npm run build && npm pack --ignore-scripts --pack-destination "$work") >&2 \
  || { echo "import_failed: the candidate client does not build" >&2; exit 1; }
package=$(ls "$work"/*.tgz | head -1)
capture_args=(); while IFS= read -r arg; do capture_args+=("$arg"); done < <(jq -r '.capture_args[]' "$client")
# The client resumes the site directory it names for <url> under --output.
kept="$work/source/$(node -e 'const u = new URL(process.argv[1]); console.log((u.hostname + u.pathname).toLowerCase().replace(/\/$/, "").replace(/[^a-z0-9.-]/g, "-").replace(/-+/g, "-").replace(/^-|-$/g, ""))' "$url")"
mkdir -p "$work/source"; cp -RL "$base" "$kept"; chmod -R u+w "$kept"; rm -f "$kept/.client.tgz" "$kept/.capture-client.json"
npx --yes "--package=$package" "$(jq -r .executable "$client")" "$url" --output "$work/source" --resume "${capture_args[@]}" >&2 || true
[ -f "$kept/capture-receipt.json" ] || { echo "import_failed: the candidate client kept no capture for $url" >&2; exit 1; }
rm -rf "$capture"; mkdir -p "$(dirname "$capture")"; mv "$kept" "$capture"; cp "$package" "$capture/.client.tgz"
jq -n --arg package "$(cd "$capture" && pwd)/.client.tgz" '{package: $package}' > "$capture/.capture-client.json"
bash "$here/reimport.sh" "$transformer" "$capture" "$site"
