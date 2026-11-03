#!/usr/bin/env bash
# Provision a bare SQLite WordPress at $1 for repository contracts that need
# WordPress loaded. Idempotent: an existing install is kept.
# Usage: bare-wp.sh <dir>
set -euo pipefail
dir=$1
[ -f "$dir/bootstrap.php" ] && exit 0
mkdir -p "$dir"
curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$dir/wp-cli.phar"
wp="php $dir/wp-cli.phar"
$wp core download --path="$dir" --version=7.0.4 --quiet
curl -fsSL https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip -o "$dir/sqlite.zip"
unzip -q "$dir/sqlite.zip" -d "$dir/wp-content/plugins" && rm "$dir/sqlite.zip"
plugin="$dir/wp-content/plugins/sqlite-database-integration"
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$plugin#;s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$plugin/db.copy" > "$dir/wp-content/db.php"
cp "$dir/wp-config-sample.php" "$dir/wp-config.php"
$wp --path="$dir" core install --url=http://127.0.0.1 --title=bare --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
printf '<?php require %s;\n' "'$dir/wp-load.php'" > "$dir/bootstrap.php"
