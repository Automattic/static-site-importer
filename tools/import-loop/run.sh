#!/usr/bin/env bash
# Start the import loop on a site: every other input has a default.
#
#   LOOP_COOK_MODEL=<model route> tools/import-loop/run.sh <site name | url> [slug] [homeboy workflow run options]
#
# A site name reads its URL from sites.json, and the slug defaults to the name.
# Otherwise the slug defaults to the URL's host and path. Reusing a slug continues that
# site's captures and issues; pass a new one for a fresh run. Runs land in
# $LOOP_RUNS_DIR (default ~/loop-runs). The loop keeps its own Blocks Engine
# clone there and fetches trunk into it each pass. LOOP_COOK_PLACEMENT and
# LOOP_WP_CLI override the workflow's defaults. LOOP_CORPUS (a frozen capture
# corpus, see corpus.mjs) and LOOP_CORPUS_SITES make confirm refuse any fix that
# regresses another corpus site. Any further arguments, such as
# --notification-transport and --notification-route, go to `homeboy workflow run`.
#
# The run follows $LOOP_TOOLS_REF (default origin/main) of this checkout: each
# pass pins that ref's current commit (tools.sh), so merged tool changes reach
# a running chain on its next pass. A commit is a ref too, and holds a run on
# fixed tools. A change to loop.json itself takes effect on the next launch.
set -euo pipefail
url=${1:?usage: run.sh <site name | url> [slug] [homeboy workflow run options]}; shift
if [ "${url#*://}" = "$url" ]; then
  slug=$url
  url=$(jq -er --arg name "$url" '.[$name].url' "$(dirname "$0")/sites.json") || { echo "run.sh: no site '$slug' in sites.json" >&2; exit 2; }
else
  slug=$(node -e 'const u = new URL(process.argv[1]); console.log(`${u.hostname}${u.pathname}`.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 60))' "$url")
fi
if [ $# -gt 0 ] && [ "${1#-}" = "$1" ]; then slug=$1; shift; fi
: "${LOOP_COOK_MODEL:?set LOOP_COOK_MODEL to the model route Cook uses}"
repo=$(cd "$(dirname "$0")/../.." && pwd)
runs=${LOOP_RUNS_DIR:-$HOME/loop-runs}
ref=${LOOP_TOOLS_REF:-origin/main}
here=$(bash "$(dirname "$0")/tools.sh" "$repo" "$ref" "$runs" | jq -r .dir)
be="$runs/clones/blocks-engine"
mkdir -p "$runs/clones"
[ -d "$be/.git" ] || git clone -q https://github.com/Automattic/blocks-engine.git "$be"
optional=()
[ -n "${LOOP_COOK_PLACEMENT:-}" ] && optional+=(--input "cook_placement=$LOOP_COOK_PLACEMENT")
[ -n "${LOOP_WP_CLI:-}" ] && optional+=(--input "wp_cli=$LOOP_WP_CLI")
[ -n "${LOOP_CORPUS:-}" ] && optional+=(--input "corpus=$LOOP_CORPUS")
[ -n "${LOOP_CORPUS_SITES:-}" ] && optional+=(--input "corpus_sites=$LOOP_CORPUS_SITES")
echo "import loop: $url as $slug in $runs, tools following $ref" >&2
exec homeboy workflow run "$here/loop.json" \
  --input "url=$url" --input "slug=$slug" --input "loop_dir=$here" --input "tools_repo=$repo" --input "tools_ref=$ref" --input "runs_dir=$runs" \
  --input "blocks_engine=$be" --input "cook_model=$LOOP_COOK_MODEL" ${optional[@]+"${optional[@]}"} "$@"
