#!/usr/bin/env bash
# Disposable real WordPress: public CLI import, fresh requests, core theme switch.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
evidence="${SSI_ROUTE_METADATA_EVIDENCE:?Supply an evidence directory outside the checkout}"
mkdir -p "$evidence"
chmod 0777 "$evidence"
project="ssi_owners_${RANDOM}_$$"
port="$((20000 + RANDOM % 1000))"
cleanup() {
 docker logs "${project}_wp" > "$evidence/wordpress.log" 2>&1 || true
 docker rm -f "${project}_wp" "${project}_db" >/dev/null 2>&1 || true
 docker network rm "$project" >/dev/null 2>&1 || true
 docker volume rm "$project" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$project" >/dev/null
docker volume create "$project" >/dev/null
docker run -d --name "${project}_db" --network "$project" --network-alias mysql -e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
for attempt in $(seq 1 60); do
 if docker exec "${project}_db" mysqladmin ping -uwordpress -pwordpress >/dev/null 2>&1; then break; fi
 sleep 2
done
docker run -d --name "${project}_wp" --network "$project" -p "127.0.0.1:${port}:80" -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -v "$project:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$evidence:/evidence" wordpress:7.0.4-php8.3-apache >/dev/null
wp=(docker run --rm --network "$project" --user 33:33 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -e SSI_ROUTE_METADATA_DISPOSABLE=1 -v "$project:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$evidence:/evidence" wordpress:cli-php8.3 wp --allow-root)
for attempt in $(seq 1 60); do
 if curl -sf "http://127.0.0.1:${port}/wp-login.php" >/dev/null; then break; fi
 sleep 2
done
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='Owner fixture' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" core update --version=7.1 --force
"${wp[@]}" core update-db
"${wp[@]}" plugin activate static-site-importer
# A registered platform storage contract, not a mock of the importer. No head
# emitter is installed: the fallback must read only native fields on both themes.
docker exec "${project}_wp" mkdir -p /var/www/html/wp-content/mu-plugins
docker exec -i "${project}_wp" sh -c 'tee /var/www/html/wp-content/mu-plugins/platform-fields.php >/dev/null' <<'PHP'
<?php
add_action( 'init', static function () {
 foreach ( array( 'jetpack_seo_html_title', 'advanced_seo_description' ) as $key ) {
  register_meta( 'post', $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
 }
} );
PHP
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/route-ownership-wordpress.php import | tee "$evidence/import.log"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/route-ownership-wordpress.php before
request() {
 local phase="$1"
 while IFS= read -r url; do
  local name
  name="$(printf '%s' "$url" | sha256sum | cut -c1-12)"
  curl -fsS "$url" > "$evidence/${phase}-${name}.html"
 done < <(node -e 'for (const p of JSON.parse(require("fs").readFileSync(process.argv[1])).pages) console.log(p.url)' "$evidence/before.json")
 curl -sS -D "$evidence/${phase}-redirect.txt" -o /dev/null "http://127.0.0.1:${port}/old-garden"
}
request before
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/route-ownership-wordpress.php reimport
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/route-ownership-wordpress.php reimported
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/route-ownership-wordpress.php rollback
"${wp[@]}" theme install twentytwentyfive --force
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/route-ownership-wordpress.php switch
request after
node "$root/tools/verify-route-ownership.mjs" "$evidence"
