#!/usr/bin/env bash
# Loop step: import one source with Studio and report where the evidence lives.
set -euo pipefail
url=$1; site=$2
export GITHUB_TOKEN="$(gh auth token)"
mkdir -p "$(dirname "$site")"
# Each pass starts one Studio site, and Studio caps running sites. Only the
# current pass's site serves the evaluation; stop the loop's earlier ones.
runs=$(cd "$(dirname "$site")" && pwd)
studio site list --format json 2>/dev/null | jq -r --arg runs "$runs/" '.[] | select(.running and (.path | startswith($runs))) | .path' |
  while read -r old; do studio site stop --path "$old" > /dev/null 2>&1 || echo "could not stop $old" >&2; done
# A retried import starts clean: an earlier attempt may have left this pass's
# site half-created. An import fails for a while when the environment is full
# or an upstream release regressed; the step retries hourly until it recovers.
studio site list --format json 2>/dev/null | jq -e --arg site "$runs/$(basename "$site")" '.[] | select(.path == $site)' > /dev/null &&
  studio site delete "$runs/$(basename "$site")" > /dev/null 2>&1
rm -rf "$site" "$site-source"
status=0
studio create --path "$site" --from "$url" --keep-source --skip-browser 2>&1 | tee "$site.log" || status=$?
# Only a preserved quality-failed preview is a usable non-zero result.
if (( status != 0 )) && ! grep -q 'static_site_importer_quality_failed' "$site.log"; then
  exit "$status"
fi
site_url=$(grep -oE '(Site URL|URL): +https?://[^[:space:]]+' "$site.log" | tail -1 | awk '{print $NF}' || true)
[ -n "$site_url" ] && [ -d "$site" ] || { echo "import produced no site URL or site" >&2; exit 1; }
studio site start --path "$site" >&2
jq -n --arg url "$url" --arg site "$site" --arg site_url "$site_url" --arg log "$site.log" --arg source "$site-source" \
  '{url:$url, site:$site, site_url:$site_url, log:$log, source:$source}' > "${HOMEBOY_STEP_OUTPUT:-/dev/stdout}"
