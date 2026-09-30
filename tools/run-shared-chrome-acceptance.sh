#!/usr/bin/env bash
set -euo pipefail

# All writes target one disposable Docker project. The operator provides the
# exact producer source checkout; Composer release pins remain untouched.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
engine="${SSI_SHARED_CHROME_BLOCKS_ENGINE_PATH:?Supply the paired Blocks Engine checkout}"
evidence="${SSI_SHARED_CHROME_EVIDENCE:?Supply an evidence directory outside this checkout}"
test -f "$engine/php-transformer/src/WordPressSitePlan/NavigationEntityProjection.php"
test -f "$root/vendor/autoload.php"
mkdir -p "$evidence/source"
chmod 0733 "$evidence"
cp "$root/tests/fixtures/shared-chrome/"* "$evidence/source/"
node -e 'const fs=require("fs");const path=require("path");const source=process.argv[1];const files=fs.readdirSync(source).map(name=>({path:name,content:fs.readFileSync(path.join(source,name),"utf8")}));fs.writeFileSync(process.argv[2],JSON.stringify({operation:"apply",source:{type:"files",entrypoint:"index.html",files},slug:"shared-chrome-acceptance",name:"Shared Chrome",activate:true,overwrite:true}));' "$evidence/source" "$evidence/request.json"
project="ssi_chrome_${RANDOM}_$$"
port="${SSI_SHARED_CHROME_PORT:-$((18500 + RANDOM % 500))}"
network="${project}_net"
volume="${project}_wp"
cleanup() {
  docker exec "${project}_wp" chmod -R a+rX /evidence >/dev/null 2>&1 || true
  docker logs "${project}_wp" > "$evidence/wordpress.log" 2>&1 || true
  docker rm --force "${project}_wp" "${project}_db" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  docker volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null
docker run --detach --name "${project}_db" --network "$network" --network-alias mysql -e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
for attempt in $(seq 1 60); do if docker exec "${project}_db" mysqladmin ping -uwordpress -pwordpress >/dev/null 2>&1; then break; fi; sleep 2; done
docker run --detach --name "${project}_wp" --network "$network" --publish "127.0.0.1:${port}:80" -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -v "$volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$engine:/candidate:ro" -v "$evidence:/evidence" wordpress:php8.3-apache >/dev/null
wp=(docker run --rm --network "$network" --user 33:33 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -e SSI_SHARED_CHROME_DISPOSABLE=1 -v "$volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$engine:/candidate:ro" -v "$evidence:/evidence" wordpress:cli-php8.3 wp --allow-root)
for attempt in $(seq 1 60); do if curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null; then break; fi; sleep 2; done
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='Shared Chrome Acceptance' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
wp+=(--user=admin)
"${wp[@]}" core update --version="${SSI_SHARED_CHROME_WORDPRESS_VERSION:-nightly}" --force
# This explicit test dependency overlay loads before the importer. It exercises
# both dirty source trees and records their digests rather than pretending the
# published Composer dependency contains an unreleased producer contract.
docker exec "${project}_wp" mkdir -p /var/www/html/wp-content/mu-plugins
docker cp "$root/tests/fixtures/runtime/paired-engine-autoload.php" "${project}_wp:/var/www/html/wp-content/mu-plugins/00-paired-engine.php"
"${wp[@]}" core version > "$evidence/wordpress-version.txt"
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" static-site-importer import --request=/evidence/request.json --report=/evidence/import-report.json | tee "$evidence/import-result.jsonl"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/shared-chrome-runtime.php
SSI_SHARED_CHROME_URL="http://127.0.0.1:${port}" SSI_SHARED_CHROME_EVIDENCE="$evidence" node "$root/tests/shared-chrome-browser.mjs"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/shared-chrome-runtime.php rollback
mkdir -p "$evidence/reimport"
chmod 0733 "$evidence/reimport"
"${wp[@]}" static-site-importer import --request=/evidence/request.json --report=/evidence/reimport/import-report.json | tee "$evidence/reimport-result.jsonl"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/shared-chrome-runtime.php reimport
