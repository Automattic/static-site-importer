#!/usr/bin/env bash
# Loop step: materialize the source at Blocks Engine trunk and report where the
# evidence lives.
#
# The capture client is the latest release of compare-client.json's repository,
# resolved at runtime as Studio resolves its importer. The first pass captures
# the source with it and the arguments in compare-client.json, and keeps the
# capture with the client it used (<capture>/.capture-client.json, which
# evaluate, triage and every gate compare with): `--reference-sample all
# --route-limit all` freezes reference evidence for every route, so every route
# is compared. A pass re-captures when a newer client has been released, or the
# arguments changed, since the kept capture. Every pass, including the first,
# re-imports the frozen capture through the latest Static Site Importer release
# with the transformer from Blocks Engine `trunk` (gates/reimport.sh, as triage, the Cook gate and
# confirm do). A merged fix is therefore measured on the very next pass, without
# waiting for a release to carry it into Studio.
#
# Usage: import.sh <url> <pass dir> <capture dir> <blocks-engine clone>
set -euo pipefail
url=$1; pass=$2; capture=$3; be=$4
here=$(cd "$(dirname "$0")" && pwd)
host=$(node -e 'console.log(new URL(process.argv[1]).hostname)' "$url")
export GITHUB_TOKEN="$(gh auth token)"
mkdir -p "$(dirname "$pass")"
runs=$(cd "$(dirname "$pass")" && pwd)
bare="$runs/bare-wp"

# This slug's earlier passes served their sites from `php -S` (reimport.sh
# records the server in <pass>/site); stop them. Other slugs share $runs and
# their loops still use their servers.
for pid in "${pass%-p*}"-p[0-9]*/site/.server.pid; do [ -f "$pid" ] && kill -- "-$(cat "$pid")" 2> /dev/null || true; done

# The newest release with its package uploaded: auto-release publishes the tag
# a few minutes before its asset.
repo=$(jq -r --arg r "$(jq -r .owner_repo "$here/compare-client.json")" '.[$r].github' "$here/repos.json")
package=$(gh api "repos/$repo/releases?per_page=10" --jq "[.[] | select((.draft or .prerelease) | not) | .assets[] | select(.state == \"uploaded\" and (.name | endswith(\"$(jq -r .asset "$here/compare-client.json")\")))][0].browser_download_url")
[ -n "$package" ] || { echo "no released capture client package in $repo" >&2; exit 1; }
client="$pass-capture-client.json"
jq --arg package "$package" '{package: $package} + del(.asset)' "$here/compare-client.json" > "$client"
if [ ! -f "$capture/capture-receipt.json" ] || ! cmp -s "$client" "$capture/.capture-client.json"; then
  first="$pass-capture"
  rm -rf "$first-source"
  capture_args=(); while IFS= read -r arg; do capture_args+=("$arg"); done < <(jq -r '.capture_args[]' "$client")
  npx --yes "--package=$(jq -r .package "$client")" "$(jq -r .executable "$client")" "$url" --output "$first-source" \
    "${capture_args[@]}" 2>&1 | tee "$first.log" || true
  # The client names the capture after the site's host and path.
  kept=$(dirname "$(ls "$first-source"/*/capture-receipt.json 2> /dev/null | head -1 || true)")
  [ -f "$kept/capture-receipt.json" ] || { echo "the capture client kept no capture for $url (see $first.log)" >&2; exit 1; }
  rm -rf "$capture"; mkdir -p "$(dirname "$capture")"; mv "$kept" "$capture"; cp "$client" "$capture/.capture-client.json"
  rm -rf "$first-source"
fi

# The transformer at Blocks Engine trunk, and the importer release Studio installs.
rm -rf "$pass"; mkdir -p "$pass/source"
git -C "$be" fetch -q origin trunk
trunk=$(git -C "$be" rev-parse FETCH_HEAD)
git -C "$be" archive "$trunk" php-transformer | tar -x -C "$pass"
# The newest release whose zip is uploaded: auto-release publishes the tag a
# few minutes before its asset.
tag=$(bash "$here/ssi-release.sh" "$pass/static-site-importer.zip")
ln -s "$capture" "$pass/source/$host"

site_url=$(SSI_ZIP="$pass/static-site-importer.zip" BARE_WP="$bare" bash "$here/gates/reimport.sh" "$pass/php-transformer" "$capture" "$pass/site" 2> "$pass/reimport.log" | tail -1) ||
  { tail -c 2000 "$pass/reimport.log" >&2; exit 1; }
[ -n "$site_url" ] || { echo "re-import produced no site URL" >&2; exit 1; }
jq -n --arg url "$url" --arg site "$pass/site" --arg site_url "$site_url" --arg log "$pass/reimport.log" --arg source "$pass/source" \
  --arg wp_cli "php $bare/wp-cli.phar" --arg blocks_engine "$trunk" --arg ssi_zip "$pass/static-site-importer.zip" --arg ssi_release "$tag" \
  '{url:$url, site:$site, site_url:$site_url, log:$log, source:$source, wp_cli:$wp_cli, blocks_engine:$blocks_engine, ssi_zip:$ssi_zip, ssi_release:$ssi_release}' > "${HOMEBOY_STEP_OUTPUT:-/dev/stdout}"
