#!/usr/bin/env bash
# Start the import loop on a URL: every other input has a default.
#
#   LOOP_COOK_MODEL=<model route> tools/import-loop/run.sh <url> [slug] [homeboy workflow run options]
#
# The slug defaults to the URL's host and path. Reusing a slug continues that
# site's captures and issues; pass a new one for a fresh run. Runs land in
# $LOOP_RUNS_DIR (default ~/loop-runs). The loop keeps its own Blocks Engine
# clone there and fetches trunk into it each pass. LOOP_COOK_PLACEMENT and
# LOOP_WP_CLI override the workflow's defaults. Any further arguments, such as
# --notification-transport and --notification-route, go to `homeboy workflow run`.
set -euo pipefail
url=${1:?usage: run.sh <url> [slug] [homeboy workflow run options]}; shift
slug=$(node -e 'const u = new URL(process.argv[1]); console.log(`${u.hostname}${u.pathname}`.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 60))' "$url")
if [ $# -gt 0 ] && [ "${1#-}" = "$1" ]; then slug=$1; shift; fi
: "${LOOP_COOK_MODEL:?set LOOP_COOK_MODEL to the model route Cook uses}"
here=$(cd "$(dirname "$0")" && pwd)
runs=${LOOP_RUNS_DIR:-$HOME/loop-runs}
be="$runs/clones/blocks-engine"
mkdir -p "$runs/clones"
[ -d "$be/.git" ] || git clone -q https://github.com/Automattic/blocks-engine.git "$be"
optional=()
[ -n "${LOOP_COOK_PLACEMENT:-}" ] && optional+=(--input "cook_placement=$LOOP_COOK_PLACEMENT")
[ -n "${LOOP_WP_CLI:-}" ] && optional+=(--input "wp_cli=$LOOP_WP_CLI")
echo "import loop: $url as $slug in $runs" >&2
exec homeboy workflow run "$here/loop.json" \
  --input "url=$url" --input "slug=$slug" --input "loop_dir=$here" --input "runs_dir=$runs" \
  --input "blocks_engine=$be" --input "cook_model=$LOOP_COOK_MODEL" ${optional[@]+"${optional[@]}"} "$@"
