#!/usr/bin/env bash
# Loop step: pin the loop's tools at the current commit of a ref, so a running
# chain picks up merged tool changes on its next pass instead of being
# relaunched. Each commit's tools live in a detached worktree at
# <runs>/loops/<commit> that nothing changes afterwards, and dependencies are
# installed once per lockfile. A commit is pinned once for every chain.
#
# Usage: tools.sh <checkout> <ref> <runs dir>   (ref: a branch such as origin/main, or a commit)
# Writes {"dir": "<pinned>/tools/import-loop", "commit": "<sha>"}.
set -euo pipefail
repo=$1; ref=$2; runs=$3
mkdir -p "$runs/loops"
# Concurrent fetches into one checkout race on its ref locks: retry.
for try in 1 2 3 4 5; do git -C "$repo" fetch -q origin 2> /dev/null && break; [ $try = 5 ] && git -C "$repo" fetch -q origin; sleep $((RANDOM % 5 + 1)); done
commit=$(git -C "$repo" rev-parse --verify "$ref^{commit}")
pinned="$runs/loops/$commit"
# Chains pin concurrently: each builds under its own name and the first to
# finish renames it into place; the others discard theirs. rename(2) onto a
# directory another chain already installed fails, where mv would move into it.
rename() { node -e 'require("fs").renameSync(process.argv[1], process.argv[2])' "$1" "$2" 2> /dev/null; }
if [ ! -d "$pinned" ]; then
  tmp="$pinned.tmp.$$"
  trap '[ -d "$tmp" ] && git -C "$repo" worktree remove --force "$tmp"' EXIT
  git -C "$repo" worktree add -q --detach "$tmp" "$commit"
  # Shared per lockfile. The directory must be named node_modules: Node
  # resolves a package's own imports from its real path's node_modules.
  deps="$runs/loops/deps-$(git -C "$repo" rev-parse "$commit:package-lock.json")"
  if [ ! -d "$deps/node_modules" ]; then
    (cd "$tmp" && npm ci --silent)
    mkdir -p "$deps"
    rename "$tmp/node_modules" "$deps/node_modules" || rm -rf "$tmp/node_modules"
  fi
  ln -s "$deps/node_modules" "$tmp/node_modules"
  rename "$tmp" "$pinned" && git -C "$repo" worktree repair "$pinned" 2> /dev/null
fi
# A pass outlives no workflow's 36h deadline, so a pin no pass has used for two
# days serves no chain: remove it.
touch "$pinned"
find "$runs/loops" -mindepth 1 -maxdepth 1 -type d -name '[0-9a-f]*' ! -name 'deps-*' ! -name '*.tmp.*' -mtime +2 -print | while read -r stale; do
  git -C "$repo" worktree remove --force "$stale" 2> /dev/null || rm -rf "$stale"
done
jq -n --arg dir "$pinned/tools/import-loop" --arg commit "$commit" '{dir:$dir, commit:$commit}' > "${HOMEBOY_STEP_OUTPUT:-/dev/stdout}"
