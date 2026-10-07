#!/usr/bin/env bash
set -euo pipefail
# Source-only fixture, never wp-load.php and never a developer WordPress install.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
revision=ba9e7f97f08a7fbb88fbfa35641bcc230500b37a # WordPress/WordPress 6.8.3
destination="$root/vendor/wordpress-core"
if [[ -n "${STATIC_SITE_IMPORTER_WP_ROOT:-}" ]]; then
  test -r "$STATIC_SITE_IMPORTER_WP_ROOT/wp-includes/html-api/class-wp-html-tag-processor.php"
  exit 0
fi
if test -r "$destination/wp-includes/html-api/class-wp-html-tag-processor.php"; then
  exit 0
fi
test ! -e "$destination" || { printf 'Existing fixture directory is incomplete: %s\n' "$destination" >&2; exit 2; }
test -d "$root/vendor" || { printf 'Run composer install first.\n' >&2; exit 2; }
work="$(mktemp -d "$root/vendor/.wordpress-core-fixture.XXXXXX")"
trap 'rm -rf "$work"' EXIT
curl --fail --location --retry 3 --connect-timeout 15 --max-time 180 "https://codeload.github.com/WordPress/WordPress/tar.gz/$revision" --output "$work/core.tar.gz"
mkdir "$work/core"
tar --extract --gzip --file "$work/core.tar.gz" --directory "$work/core" --strip-components=1
test -r "$work/core/wp-includes/html-api/class-wp-html-tag-processor.php"
printf 'WordPress/WordPress 6.8.3 %s\n' "$revision" > "$work/core/ssi-fixture-source.txt"
mv "$work/core" "$destination"
