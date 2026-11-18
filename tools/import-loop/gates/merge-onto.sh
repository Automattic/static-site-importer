#!/usr/bin/env bash
# Write <path>... of commit <rev> merged onto commit <onto> to <out>, the way
# the change would ship, without touching any worktree or index. Fetches <onto>
# when it is not yet local. Exit 3 when the merge conflicts.
# Usage: merge-onto.sh <git dir> <onto> <rev> <out> <path>...
set -euo pipefail
repo=$1; onto=$2; rev=$3; out=$4; shift 4
git -C "$repo" cat-file -e "$onto^{commit}" 2>/dev/null || git -C "$repo" fetch -q origin "$onto"
if ! merged=$(git -C "$repo" merge-tree --write-tree --name-only --no-messages "$onto" "$rev"); then
  files=$(tail -n +2 <<<"$merged" | sort -u | paste -sd, -)
  echo "import_failed: head conflicts with ${onto:0:8} in ${files}: merge ${onto} into the branch and resolve them" >&2; exit 3
fi
tree=${merged%%$'\n'*}
mkdir -p "$out"; git -C "$repo" archive "$tree" "$@" | tar -x -C "$out"
