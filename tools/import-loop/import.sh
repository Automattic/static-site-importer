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
studio create --path "$site" --from "$url" --keep-source --skip-browser 2>&1 | tee "$site.log"
site_url=$(grep -oE 'Site URL: +http://[^ ]+' "$site.log" | tail -1 | awk '{print $NF}')
[ -n "$site_url" ] || { echo "import produced no site URL" >&2; exit 1; }
jq -n --arg url "$url" --arg site "$site" --arg site_url "$site_url" --arg log "$site.log" --arg source "$site-source" \
  '{url:$url, site:$site, site_url:$site_url, log:$log, source:$source}' > "${HOMEBOY_STEP_OUTPUT:-/dev/stdout}"
