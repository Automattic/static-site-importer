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
# LOOP_WP_CLI override the workflow's defaults. Any further arguments, such as
# --notification-transport and --notification-route, go to `homeboy workflow run`.
#
# The run is pinned to this checkout's commit: its tools run from a detached
# worktree at $LOOP_RUNS_DIR/loops/<commit>, so later checkouts and other runs
# cannot change them mid-run. Dependencies are installed once per lockfile.
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
commit=$(git -C "$repo" rev-parse HEAD)
pinned="$runs/loops/$commit"
if [ ! -d "$pinned" ]; then
  mkdir -p "$runs/loops"
  git -C "$repo" worktree add -q --detach "$pinned.tmp" "$commit"
  # Shared per lockfile. The directory must be named node_modules: Node
  # resolves a package's own imports from its real path's node_modules.
  deps="$runs/loops/deps-$(git -C "$repo" rev-parse "$commit:package-lock.json")/node_modules"
  [ -d "$deps" ] || { npm ci --silent --prefix "$pinned.tmp" && mkdir -p "$(dirname "$deps")" && mv "$pinned.tmp/node_modules" "$deps"; }
  ln -s "$deps" "$pinned.tmp/node_modules"
  git -C "$repo" worktree move "$pinned.tmp" "$pinned"
fi
here="$pinned/tools/import-loop"
be="$runs/clones/blocks-engine"
mkdir -p "$runs/clones"
[ -d "$be/.git" ] || git clone -q https://github.com/Automattic/blocks-engine.git "$be"
optional=()
[ -n "${LOOP_COOK_PLACEMENT:-}" ] && optional+=(--input "cook_placement=$LOOP_COOK_PLACEMENT")
[ -n "${LOOP_WP_CLI:-}" ] && optional+=(--input "wp_cli=$LOOP_WP_CLI")
echo "import loop: $url as $slug in $runs, tools at $commit" >&2
exec homeboy workflow run "$here/loop.json" \
  --input "url=$url" --input "slug=$slug" --input "loop_dir=$here" --input "runs_dir=$runs" \
  --input "blocks_engine=$be" --input "cook_model=$LOOP_COOK_MODEL" ${optional[@]+"${optional[@]}"} "$@"
