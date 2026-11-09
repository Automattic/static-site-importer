#!/usr/bin/env bash
# Re-materialize a captured source the way `studio create --from` does: the
# Static Site Importer release zip imports the staged capture through
# `wp static-site-importer import`, with its bundled transformer replaced by a
# candidate checkout. Serves the imported site and prints its URL; the server
# stops when the caller kills the process group or removes the site.
#
# Usage: reimport.sh <php-transformer dir> <capture root> <site dir>
# Env: BARE_WP (template from bare-wp.sh), SSI_ZIP (Static Site Importer release zip)
set -euo pipefail
transformer=$(cd "$1" && pwd); capture=$(cd "$2" && pwd); site=$3
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')
here=$(cd "$(dirname "$0")" && pwd)
bash "$here/bare-wp.sh" "${BARE_WP:?}"
rm -rf "$site"; cp -a "$BARE_WP" "$site"; site=$(cd "$site" && pwd)
wp="php $BARE_WP/wp-cli.phar --path=$site"
url="http://127.0.0.1:$port"
$wp option update home "$url" --quiet; $wp option update siteurl "$url" --quiet
$wp rewrite structure '/%postname%/' --quiet

# The release, with the candidate transformer in its vendor slot. PSR-4 then
# resolves every transformer class from the candidate: drop the classmap
# entries that pin the bundled copy's files.
unzip -q "${SSI_ZIP:?}" -d "$site/wp-content/plugins"
ssi="$site/wp-content/plugins/static-site-importer"
slot="$ssi/vendor/automattic/blocks-engine-php-transformer"
rm -rf "$slot"; mkdir -p "$slot"
cp -a "$transformer/php-transformer.php" "$transformer/src" "$transformer/resources" "$transformer/VERSION" "$transformer/composer.json" "$slot/"
sed -i '/blocks-engine-php-transformer\/src\//d' "$ssi/vendor/composer/autoload_classmap.php" "$ssi/vendor/composer/autoload_static.php"
$wp plugin activate static-site-importer --quiet

# Stage the capture beside the request, as Studio does.
name=$(jq -r '.title // "Imported Site"' "$capture/capture-receipt.json")
mkdir -p "$site/.studio-import"; cp -a "$capture" "$site/.studio-import/source"
jq -n --arg name "$name" --arg source "$capture" '{
  operation: "apply", name: $name, site_title: $name, activate: true, overwrite: true,
  client_script_policy: "isolated_preview", client_script_isolated: true,
  client_script_provenance: {ref: "import-loop-reimport"},
  source_metadata: {source: "studio-create-from", source_path: $source},
  fail_on_quality: true, require_proven_dynamic_client_assets: true,
  seed_entities: true, materialize_dependencies: true,
  source: {type: "files", ref: "request-bundle:source"}}' > "$site/.studio-import/request.json"
# A quality-gate refusal still materializes the site, as Studio keeps its
# preview (import.sh): serve it, so findings on a site that fails the gate can
# be checked. Any other failure refuses the head.
(cd "$site" && $wp static-site-importer import --request=.studio-import/request.json > "$site/.studio-import/receipt.log" 2>&1) \
  || grep -q '"code":"static_site_importer_quality_failed"' "$site/.studio-import/receipt.log" \
  || { tail -c 2000 "$site/.studio-import/receipt.log" >&2; exit 1; }

cat > "$site/.router.php" <<'PHP'
<?php
$file = __DIR__ . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_file( $file ) ) return false;
require __DIR__ . '/index.php';
PHP
setsid php -S "127.0.0.1:$port" -t "$site" "$site/.router.php" > "$site/.server.log" 2>&1 < /dev/null &
echo $! > "$site/.server.pid"
for _ in $(seq 50); do curl -fs -o /dev/null "$url/" && break; sleep 0.2; done
echo "$url"
