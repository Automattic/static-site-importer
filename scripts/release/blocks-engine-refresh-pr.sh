#!/usr/bin/env bash
# Markdown backticks in printf formats are intentionally literal.
# shellcheck disable=SC2016
set -euo pipefail

# Called only after the owning refresh/test steps, never writes dependency pins.
# failure LOG: publish the checkout's refreshed manifests, then exit nonzero.
# resolved: retire the incident PR only after a successful release.
[[ "${HOMEBOY_DRY_RUN:-false}" != true ]] || exit 0
branch=deps/blocks-engine-refresh
title='Blocks Engine pin refresh breaks SSI tests'
repo="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
run_url="${GITHUB_SERVER_URL:-https://github.com}/${repo}/actions/runs/${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
open_pr() {
  gh pr list --repo "$repo" --head "$branch" --base main --state open --json number --jq '.[0].number // empty'
}

case "${1:-}" in
  failure)
    log="${2:?test output log is required}"
    body="$(mktemp)"
    trap 'rm -f "$body"' EXIT
    # Read the old pins from the tested main commit and new pins from the lock.
    old_lock="$(git show HEAD:composer.lock)"
    {
      printf 'The refreshed Blocks Engine pins failed SSI validation. Nothing was pushed to main; release remains blocked.\n\n'
      for package in php-transformer figma-transformer; do
        name="automattic/blocks-engine-${package}"
        old="$(jq -er --arg name "$name" '.packages[] | select(.name == $name) | .version' <<<"$old_lock")"
        new="$(jq -er --arg name "$name" '.packages[] | select(.name == $name) | .version' composer.lock)"
        printf -- '- %s: `%s` → `%s`\n' "$package" "$old" "$new"
      done
      printf '\nFailing run: %s\n\n### Failing tests\n\n' "$run_url"
      # Homeboy WordPress runners emit *_SMOKE_FAIL:path:exit=N markers.
      # Also retain PHPUnit failure names and Node TAP `not ok` test names.
      failures="$(perl -pe 's/\e\[[0-9;]*[mK]//g' "$log" | awk '
        /((HOST|PHP|JS|SHELL)_SMOKE_FAIL):/ { sub(/^.*SMOKE_FAIL:/, ""); sub(/:exit=.*/, ""); print; next }
        /^[[:space:]]*[0-9]+\) / { sub(/^[[:space:]]*[0-9]+\) /, ""); print; next }
        /^[[:space:]]*not ok [0-9]+ / { sub(/^[[:space:]]*not ok [0-9]+ -? ?/, ""); print; next }
        /^[[:space:]]*✖ / && !/✖ failing tests:/ { sub(/^[[:space:]]*✖ /, ""); sub(/ \([0-9.]+ms\).*$/, ""); print; next }
        /^[[:space:]]*FAIL[: ]/ { sub(/^[[:space:]]*FAIL[: ] */, ""); print }
      ' | sort -u)"
      if [[ -n "$failures" ]]; then
        while IFS= read -r failure; do printf -- '- `%s`\n' "$failure"; done <<<"$failures"
      else
        printf 'No test names were reported (setup or runner failure). Inspect the failing run above.\n'
      fi
      printf '\n### Recovery\n\nPush compat fixes to `%s`. A maintainer pushing a commit to this branch triggers Test CI; the workflow GITHUB_TOKEN push/PR may not trigger it.\n' "$branch"
      printf '\nWhile this PR is open, later failed refreshes only update this description; they never reset the branch, so compat work pushed here is kept. Update the pins on this branch with `scripts/release/refresh-blocks-engine.sh` if a newer engine is listed above. A later successful release closes this PR and deletes the branch.\n'
      printf '\nMotivating incident: https://github.com/%s/pull/2940.\n' "$repo"
    } > "$body"

    pr="$(open_pr)"
    if [[ -n "$pr" ]]; then
      # An open incident PR may carry maintainer compat commits: report, never reset.
      gh pr edit "$pr" --repo "$repo" --title "$title" --body-file "$body"
    else
      # No open PR: start the branch fresh from main plus the latest pins.
      git config user.name 'github-actions[bot]'
      git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
      git add composer.json composer.lock
      git commit -m 'fix(deps): refresh Blocks Engine pins to latest release'
      git push --force origin "HEAD:refs/heads/$branch"
      gh pr create --repo "$repo" --head "$branch" --base main --title "$title" --body-file "$body"
    fi
    exit 1
    ;;
  resolved)
    pr="$(open_pr)"
    if [[ -n "$pr" ]]; then
      release_url="$(gh release view --repo "$repo" --json url --jq .url)"
      gh pr close "$pr" --repo "$repo" --comment "Refreshed pins now pass SSI validation. Released: ${release_url} (run: ${run_url})."
    fi
    # A merged PR may already be closed, with its branch still present.
    if [[ -n "$(git ls-remote --heads origin "refs/heads/$branch")" ]]; then
      git push origin --delete "$branch"
    fi
    ;;
  *) echo 'Usage: blocks-engine-refresh-pr.sh failure LOG | resolved' >&2; exit 2 ;;
esac
