#!/usr/bin/env bash
# Reproduces the Automattic/blocks-engine#1914 reduction proof.
# Needs: php 8.2+, node, a blocks-engine clone in $BLOCKS_ENGINE, npm ci in this repo.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"; repo="$(cd "$here/../../../.." && pwd)"
be="${BLOCKS_ENGINE:?set BLOCKS_ENGINE to a blocks-engine clone}"
work="$(mktemp -d)"; trap 'git -C "$be" worktree remove --force "$work/pre" >/dev/null 2>&1 || true; git -C "$be" worktree remove --force "$work/fix" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
fix=3128cb9cf7cd52d5281c6871f680cd2b2966ace8
for name in pre fix; do
  rev=$fix; [ "$name" = pre ] && rev="$fix^1"
  git -C "$be" worktree add -q --no-checkout --detach "$work/$name" "$rev"
  git -C "$work/$name" sparse-checkout set php-transformer >/dev/null
  git -C "$work/$name" checkout -q --detach "$rev"
done
predicate() { node "$here/predicate.mjs" "$work/$1/php-transformer" "$2"; }
predicate pre "$here/page.html" || { echo 'page does not reproduce before the fix'; exit 1; }
! predicate fix "$here/page.html" || { echo 'page still fails after the fix'; exit 1; }
node "$repo/tools/reduce-finding.mjs" --input "$here/page.html" --output "$work/out" \
  --predicate "node '$here/predicate.mjs' '$work/pre/php-transformer' {candidate}" \
  --stage materialization --name be-1914-where-specificity --max-calls 2000 > "$work/reduction.log"
predicate pre "$work/out/source.html" || { echo 'reduced page lost the defect'; exit 1; }
! predicate fix "$work/out/source.html" || { echo 'reduced page fails after the fix for another reason'; exit 1; }
diff -u "$here/reduced.html" "$work/out/source.html" && echo 'proof ok: reduced page matches reduced.html'
