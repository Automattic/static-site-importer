#!/usr/bin/env bash
set -euo pipefail
# Source-only fixture, never wp-load.php and never a developer WordPress install.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version=7.2-beta3
revision=716e0856735658fa5a5966a9640674bc72e5a5f0 # WordPress/WordPress $version
destination="$root/vendor/wordpress-core"
if [[ -n "${STATIC_SITE_IMPORTER_WP_ROOT:-}" ]]; then
  test -r "$STATIC_SITE_IMPORTER_WP_ROOT/wp-includes/html-api/class-wp-html-tag-processor.php"
  exit 0
fi
marker="$destination/ssi-fixture-source.txt"
if test -r "$marker" && ! grep -qx "WordPress/WordPress $version $revision" "$marker"; then
  # A fixture this script provisioned at an older pin: replace it.
  rm -rf "$destination"
fi
if test -r "$destination/wp-includes/html-api/class-wp-html-tag-processor.php"; then
  exit 0
fi
test ! -e "$destination" || { printf 'Existing fixture directory is incomplete: %s\n' "$destination" >&2; exit 2; }
test -d "$root/vendor" || { printf 'Run composer install first.\n' >&2; exit 2; }
work="$(mktemp -d "$root/vendor/.wordpress-core-fixture.XXXXXX")"
trap 'rm -rf "$work"' EXIT
# The pinned source archive is over 60 MiB; a healthy download on a slow CI
# connection can exceed three minutes. Bound stalls separately from total time
# and suppress the progress meter so failures stay visible in gate logs.
curl --fail --silent --show-error --location --retry 3 --connect-timeout 15 --speed-limit 1024 --speed-time 60 --max-time 900 "https://codeload.github.com/WordPress/WordPress/tar.gz/$revision" --output "$work/core.tar.gz"
mkdir "$work/core"
tar --extract --gzip --file "$work/core.tar.gz" --directory "$work/core" --strip-components=1
test -r "$work/core/wp-includes/html-api/class-wp-html-tag-processor.php"
printf 'WordPress/WordPress %s %s\n' "$version" "$revision" > "$work/core/ssi-fixture-source.txt"
mv "$work/core" "$destination"
