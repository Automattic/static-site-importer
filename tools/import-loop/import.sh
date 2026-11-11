#!/usr/bin/env bash
# Loop step: materialize the source at Blocks Engine trunk and report where the
# evidence lives.
#
# The first pass captures the source with `studio create --from --keep-source`
# and keeps the capture. Every pass, including the first, re-imports that frozen
# capture through the latest Static Site Importer release with the transformer
# from Blocks Engine `trunk` (gates/reimport.sh, as triage, the Cook gate and
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

# Earlier passes served their sites from `php -S`; stop them.
for pid in "$runs"/*/.server.pid; do [ -f "$pid" ] && kill "$(cat "$pid")" 2> /dev/null || true; done

if [ ! -f "$capture/capture-receipt.json" ]; then
  first="$pass-capture"
  studio site list --format json 2>/dev/null | jq -e --arg site "$runs/$(basename "$first")" '.[] | select(.path == $site)' > /dev/null &&
    studio site delete "$runs/$(basename "$first")" > /dev/null 2>&1
  rm -rf "$first" "$first-source"
  status=0
  studio create --path "$first" --from "$url" --keep-source --skip-browser 2>&1 | tee "$first.log" || status=$?
  # Only a preserved quality-failed preview is a usable non-zero result.
  if (( status != 0 )) && ! grep -q 'static_site_importer_quality_failed' "$first.log"; then exit "$status"; fi
  [ -f "$first-source/$host/capture-receipt.json" ] || { echo "studio create kept no capture for $host" >&2; exit 1; }
  rm -rf "$capture"; mkdir -p "$(dirname "$capture")"; cp -a "$first-source/$host" "$capture"
  studio site stop --path "$first" > /dev/null 2>&1 || true
fi

# The transformer at Blocks Engine trunk, and the importer release Studio installs.
rm -rf "$pass"; mkdir -p "$pass/source"
git -C "$be" fetch -q origin trunk
trunk=$(git -C "$be" rev-parse FETCH_HEAD)
git -C "$be" archive "$trunk" php-transformer | tar -x -C "$pass"
# The newest release whose zip is uploaded: auto-release publishes the tag a
# few minutes before its asset.
tag=$(gh api 'repos/Automattic/static-site-importer/releases?per_page=10' --jq '[.[] | select(.draft | not) | select(any(.assets[]; .name == "static-site-importer.zip" and .state == "uploaded"))][0].tag_name')
gh release download "$tag" -R Automattic/static-site-importer -p static-site-importer.zip -O "$pass/static-site-importer.zip"
ln -s "$capture" "$pass/source/$host"

site_url=$(SSI_ZIP="$pass/static-site-importer.zip" BARE_WP="$bare" bash "$here/gates/reimport.sh" "$pass/php-transformer" "$capture" "$pass/site" 2> "$pass/reimport.log" | tail -1) ||
  { tail -c 2000 "$pass/reimport.log" >&2; exit 1; }
[ -n "$site_url" ] || { echo "re-import produced no site URL" >&2; exit 1; }
jq -n --arg url "$url" --arg site "$pass/site" --arg site_url "$site_url" --arg log "$pass/reimport.log" --arg source "$pass/source" \
  --arg wp_cli "php $bare/wp-cli.phar" --arg blocks_engine "$trunk" --arg ssi_zip "$pass/static-site-importer.zip" --arg ssi_release "$tag" \
  '{url:$url, site:$site, site_url:$site_url, log:$log, source:$source, wp_cli:$wp_cli, blocks_engine:$blocks_engine, ssi_zip:$ssi_zip, ssi_release:$ssi_release}' > "${HOMEBOY_STEP_OUTPUT:-/dev/stdout}"
