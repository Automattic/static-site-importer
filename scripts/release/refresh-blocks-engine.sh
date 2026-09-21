#!/usr/bin/env bash
set -euo pipefail

# Composer discovery, availability checks, lock regeneration, and rollback are
# owned by the WordPress extension. This caller only forwards the publication
# coordinates supplied by Homeboy or by repository_dispatch.

payload="${HOMEBOY_DEPENDENCY_PAYLOAD:-}"
if [[ -z "${payload}" ]]; then
  payload='{}'
fi

if ! jq -e 'type == "object"' <<<"${payload}" >/dev/null 2>&1; then
  echo "HOMEBOY_DEPENDENCY_PAYLOAD must be a JSON object" >&2
  exit 1
fi

# repository_dispatch carries the generic release event shape. Convert only
# that envelope to the generic extension action shape; the extension owns the
# package mapping and Composer policy.
if jq -e '.component and .version and .tag and .sha' <<<"${payload}" >/dev/null 2>&1; then
  component="$(jq -r '.component' <<<"${payload}")"
  case "${component}" in
    blocks-engine-php-transformer) package='automattic/blocks-engine-php-transformer' ;;
    blocks-engine-figma-transformer) package='automattic/blocks-engine-figma-transformer' ;;
    *) echo "Unsupported Blocks Engine component: ${component}" >&2; exit 1 ;;
  esac
  payload="$(jq --arg component "${component}" --arg package "${package}" '
    {
      release: {component_id: "static-site-importer"},
      dependency: {
        released_id: $component,
        package: $package,
        version: .version,
        tag: .tag,
        sha: .sha
      }
    }
  ' <<<"${payload}")"
fi

if [[ "${HOMEBOY_DRY_RUN:-false}" == "true" ]]; then
  export HOMEBOY_EXTENSION_DRY_RUN=true
fi

homeboy extension action wordpress release.update_dependency --data "${payload}"
