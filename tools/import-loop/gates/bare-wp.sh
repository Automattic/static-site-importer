#!/usr/bin/env bash
# Provision a bare SQLite WordPress at $1 for repository contracts that need
# WordPress loaded (latest, as Studio installs; WP_VERSION overrides).
# Idempotent: an existing install is kept. With <template>, an install made
# elsewhere is copied and relocated instead, so a gate needs no network.
# Usage: bare-wp.sh <dir> [template]
set -euo pipefail
dir=$1
[ -f "$dir/bootstrap.php" ] && exit 0
# The install pins its own path in db.php and bootstrap.php only.
relocate() {
  plugin="$dir/wp-content/plugins/sqlite-database-integration"
  sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$plugin#;s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$plugin/db.copy" > "$dir/wp-content/db.php"
  printf '<?php require %s;\n' "'$dir/wp-load.php'" > "$dir/bootstrap.php"
}
if [ -n "${2:-}" ]; then
  mkdir -p "$(dirname "$dir")"; cp -a "$2" "$dir"; dir=$(cd "$dir" && pwd); relocate; exit 0
fi
mkdir -p "$dir"
curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$dir/wp-cli.phar"
wp="php $dir/wp-cli.phar"
$wp core download --path="$dir" --version="${WP_VERSION:-latest}" --quiet
curl -fsSL https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -o "$dir/sqlite.zip"
unzip -q "$dir/sqlite.zip" -d "$dir/wp-content/plugins" && rm "$dir/sqlite.zip"
relocate
cp "$dir/wp-config-sample.php" "$dir/wp-config.php"
$wp --path="$dir" core install --url=http://127.0.0.1 --title=bare --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
