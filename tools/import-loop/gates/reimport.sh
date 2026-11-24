#!/usr/bin/env bash
# Re-materialize a captured source the way `studio create --from` does: the
# Static Site Importer release zip imports the staged capture through
# `wp static-site-importer import`, with its bundled transformer replaced by a
# candidate checkout. Serves the imported site and prints its URL; the server
# stops when the caller kills the process group or removes the site.
# A site left in maintenance mode (<site>/.maintenance, or a home URL answering
# 503 with Retry-After) is refused: exit 1 with the receipt tail and a final
# `site_not_serving: <reason>` line on stderr.
#
# Usage: reimport.sh <php-transformer dir> <capture root> <site dir>
# Env: BARE_WP (template from bare-wp.sh), SSI_ZIP (Static Site Importer release zip),
#      SSI_SOURCE (optional: a candidate importer's sources, laid over the release's)
set -euo pipefail
# The capture resolves to its physical path: the importer refuses a symlinked source.
transformer=$(cd "$1" && pwd); capture=$(cd "$2" && pwd -P); site=$3
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')
here=$(cd "$(dirname "$0")" && pwd)
bash "$here/bare-wp.sh" "${BARE_WP:?}"
rm -rf "$site"; cp -a "$BARE_WP" "$site"; site=$(cd "$site" && pwd)
wp="php $BARE_WP/wp-cli.phar --path=$site"
url="http://127.0.0.1:$port"
$wp option update home "$url" --quiet; $wp option update siteurl "$url" --quiet
$wp rewrite structure '/%postname%/' --quiet
# A served gate site must not upgrade itself: a background (cron) upgrade
# writes <site>/.maintenance and leaves it when interrupted, and every page then
# serves the Maintenance screen.
$wp config set AUTOMATIC_UPDATER_DISABLED true --raw --quiet

# The release, with the candidate transformer in its vendor slot. PSR-4 then
# resolves every transformer class from the candidate: drop the classmap
# entries that pin the bundled copy's files.
unzip -q "${SSI_ZIP:?}" -d "$site/wp-content/plugins"
ssi="$site/wp-content/plugins/static-site-importer"
# A candidate importer replaces the release's PHP sources (repos.json
# `candidate.paths`); its built assets and dependencies stay as released.
if [ -n "${SSI_SOURCE:-}" ]; then
  for entry in "$SSI_SOURCE"/*; do rm -rf "$ssi/$(basename "$entry")"; cp -a "$entry" "$ssi/"; done
fi
slot="$ssi/vendor/automattic/blocks-engine-php-transformer"
rm -rf "$slot"; mkdir -p "$slot"
cp -a "$transformer/php-transformer.php" "$transformer/src" "$transformer/resources" "$transformer/VERSION" "$transformer/composer.json" "$slot/"
# `-i.bak` is the in-place form both GNU and BSD (macOS) sed accept.
sed -i.bak '/blocks-engine-php-transformer\/src\//d' "$ssi/vendor/composer/autoload_classmap.php" "$ssi/vendor/composer/autoload_static.php"
rm -f "$ssi/vendor/composer/autoload_classmap.php.bak" "$ssi/vendor/composer/autoload_static.php.bak"
$wp plugin activate static-site-importer --quiet

# Stage the capture beside the request, as Studio does.
name=$(jq -r '.title // "Imported Site"' "$capture/capture-receipt.json")
# A gate input is a read-only snapshot: the staged copy stays writable, so
# the site can be removed.
mkdir -p "$site/.studio-import"; cp -R "$capture" "$site/.studio-import/source"; chmod -R u+w "$site/.studio-import/source"
jq -n --arg name "$name" --arg source "$capture" '{
  operation: "apply", name: $name, site_title: $name, activate: true, overwrite: true,
  client_script_policy: "isolated_preview", client_script_isolated: true,
  client_script_provenance: {ref: "import-loop-reimport"},
  source_metadata: {source: "studio-create-from", source_path: $source},
  require_proven_dynamic_client_assets: true,
  seed_entities: true, materialize_dependencies: true,
  source: {type: "files", ref: "request-bundle:source"}}' > "$site/.studio-import/request.json"
# A quality-gate refusal still materializes the site, as Studio keeps its
# preview (import.sh): serve it, so findings on a site that fails the gate can
# be checked. Any other failure refuses the head.
(cd "$site" && $wp static-site-importer import --request=.studio-import/request.json > "$site/.studio-import/receipt.log" 2>&1) \
  || grep -q '"code":"static_site_importer_quality_failed"' "$site/.studio-import/receipt.log" \
  || { tail -c 2000 "$site/.studio-import/receipt.log" >&2; exit 1; }

# A site left in maintenance mode imports "successfully" but serves only the
# Maintenance screen: refuse it, so nothing measures that screen as the import.
# `wp_maintenance()` answers 503 with Retry-After while <site>/.maintenance exists.
refuse() {
  tail -c 2000 "$site/.studio-import/receipt.log" >&2 || true
  if [ -f "$site/.server.pid" ]; then kill -- "-$(cat "$site/.server.pid")" 2>/dev/null || true; fi
  echo "site_not_serving: $1 {\"code\":\"site_in_maintenance_mode\"}" >&2
  exit 1
}
[ -e "$site/.maintenance" ] && refuse "$site/.maintenance exists after import; every page would serve the Maintenance screen"

cat > "$site/.router.php" <<'PHP'
<?php
$file = __DIR__ . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_file( $file ) ) return false;
require __DIR__ . '/index.php';
PHP
# The block editor fires dozens of parallel requests; one worker serializes them.
# The server leads its own process group, so its owner stops it with its
# workers (`kill -- -<pid>`). Retention stops it once the pass is scored; the
# deadline bounds a server whose owner died before that. A runner restart
# stops it too, so later steps run <site>/.serve.sh to serve the site again at
# the same URL; while the site answers, the script does nothing.
cat > "$site/.serve.sh" <<SERVE
#!/usr/bin/env bash
curl -fs -m 30 -o /dev/null "$url/" && exit 0
PHP_CLI_SERVER_WORKERS=8 setsid timeout -k 30 "\${IMPORT_LOOP_SERVER_TTL:-24h}" php -S "127.0.0.1:$port" -t "$site" "$site/.router.php" >> "$site/.server.log" 2>&1 < /dev/null &
echo \$! > "$site/.server.pid"
for _ in \$(seq 50); do curl -fs -o /dev/null "$url/" && exit 0; sleep 0.2; done
echo "site_unreachable: $url did not answer after serving $site" >&2; exit 1
SERVE
bash "$site/.serve.sh" > /dev/null 2>&1 || true
[ -e "$site/.maintenance" ] && refuse "$site/.maintenance appeared once the site was served"
headers=$(curl -s -m 60 -o /dev/null -D - "$url/" 2>/dev/null | tr -d '\r' || true)
if [[ ${headers%%$'\n'*} =~ ^HTTP/[0-9.]+\ 503 ]] && grep -qi '^retry-after:' <<< "$headers"; then
  refuse "$url/ answers 503 with Retry-After (the WordPress Maintenance screen)"
fi
echo "$url"
