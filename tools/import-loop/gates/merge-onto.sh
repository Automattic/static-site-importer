#!/usr/bin/env bash
# Write <path> of commit <rev> merged onto commit <onto> to <out>, the way the
# change would ship, without touching any worktree or index. Fetches trunk when
# <onto> is not yet local. Exit 3 when the merge conflicts.
# Usage: merge-onto.sh <git dir> <onto> <rev> <path> <out>
set -euo pipefail
repo=$1; onto=$2; rev=$3; root=$4; out=$5
git -C "$repo" cat-file -e "$onto^{commit}" 2>/dev/null || git -C "$repo" fetch -q origin trunk
merged=$(git -C "$repo" merge-tree --write-tree "$onto" "$rev") || { echo "import_failed: head conflicts with trunk ${onto:0:8}" >&2; exit 3; }
tree=${merged%%$'\n'*}
mkdir -p "$out"; git -C "$repo" archive "$tree" "$root" | tar -x -C "$out"
