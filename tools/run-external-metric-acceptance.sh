#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
evidence="${SSI_EXTERNAL_METRICS_EVIDENCE:?Supply a task evidence directory outside this checkout}"
core_archive="${SSI_WORDPRESS_CORE_ARCHIVE:?Supply the verified WordPress 7.1 core-only archive}"
expected_sha256="a874a9c66927ba4e21f30dd88b31c1df12f5a25049e81efb4ceab856da43c27b"
actual_sha256="$(sha256sum "$core_archive" | cut -d' ' -f1)"
test "$actual_sha256" = "$expected_sha256"
test -f "$root/vendor/autoload.php"
mkdir -p "$evidence"
chmod 0733 "$evidence"
project="ssi_metrics_${RANDOM}_$$"
network="${project}_net"
volume="${project}_wp"
port="$((20000 + RANDOM % 1000))"
volume2="${project}_wp2"
port2="$((21000 + RANDOM % 1000))"
cleanup() {
	if docker inspect "${project}_wp" >/dev/null 2>&1; then docker logs "${project}_wp" > "$evidence/wordpress.log" 2>&1 || true; fi
	if docker inspect "${project}_wp2" >/dev/null 2>&1; then docker logs "${project}_wp2" > "$evidence/wordpress-second-site.log" 2>&1 || true; fi
	if docker inspect "${project}_db2" >/dev/null 2>&1; then docker logs "${project}_db2" > "$evidence/mysql-second-site.log" 2>&1 || true; fi
	if docker inspect "${project}_wp2" >/dev/null 2>&1; then docker logs "${project}_wp2" > "$evidence/wordpress-second-site.log" 2>&1 || true; fi
	docker rm --force "${project}_wp" "${project}_db" "${project}_wp2" "${project}_db2" >/dev/null 2>&1 || true
	docker network rm "$network" >/dev/null 2>&1 || true
	docker volume rm "$volume" "$volume2" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$network" >/dev/null
docker run --detach --name "${project}_db" --network "$network" --network-alias mysql \
	-e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress \
	-e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
for attempt in $(seq 1 60); do if docker exec "${project}_db" mysqladmin ping -uwordpress -pwordpress >/dev/null 2>&1; then break; fi; sleep 2; done
docker run --detach --name "${project}_wp" --network "$network" --publish "127.0.0.1:${port}:80" \
	-e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
	-v "$volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" \
	-v "$evidence:/evidence" wordpress:7.0.4-php8.3-apache >/dev/null
wp=(docker run --rm --network "$network" --user 33:33 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
	-e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
	-e SSI_EXTERNAL_METRICS_DISPOSABLE=1 -v "$volume:/var/www/html" \
	-v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$evidence:/evidence" \
	wordpress:cli-php8.3 wp --allow-root)
for attempt in $(seq 1 60); do if curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null; then break; fi; sleep 2; done
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='External Metrics Acceptance' \
	--admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" core version > "$evidence/bootstrap-core-version.txt"
docker run --rm -v "$volume:/target" -v "$core_archive:/core.tar.gz:ro" alpine:latest \
	sh -c 'tar -xzf /core.tar.gz -C /target --no-same-owner --exclude="._*"'
"${wp[@]}" core version > "$evidence/wordpress-core-version.txt"
test "$(<"$evidence/wordpress-core-version.txt")" = '7.1'
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-wordpress.php \
	| tee "$evidence/materialization.jsonl"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-runtime-wordpress.php \
	| tee "$evidence/runtime.jsonl"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-provider-responses-wordpress.php \
	| tee "$evidence/provider-responses.jsonl"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-expiry-wordpress.php \
	| tee "$evidence/expiry.jsonl"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-export-wordpress.php \
	| tee "$evidence/export-result.jsonl"
docker run --detach --name "${project}_db2" --network "$network" --network-alias mysql2 \
	-e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress \
	-e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 --innodb-use-native-aio=0 >/dev/null
for attempt in $(seq 1 60); do if docker exec "${project}_db2" mysqladmin ping -uwordpress -pwordpress >/dev/null 2>&1; then break; fi; sleep 2; done
docker volume create "$volume2" >/dev/null
docker run --detach --name "${project}_wp2" --network "$network" --publish "127.0.0.1:${port2}:80" \
	-e WORDPRESS_DB_HOST=mysql2 -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
	-v "$volume2:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$evidence:/evidence" \
	wordpress:7.0.4-php8.3-apache >/dev/null
wp2=(docker run --rm --network "$network" --user 33:33 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
	-e WORDPRESS_DB_HOST=mysql2 -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
	-e SSI_EXTERNAL_METRICS_DISPOSABLE=1 -v "$volume2:/var/www/html" \
	-v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$evidence:/evidence" \
	wordpress:cli-php8.3 wp --allow-root)
for attempt in $(seq 1 60); do if curl --silent --fail "http://127.0.0.1:${port2}/wp-login.php" >/dev/null; then break; fi; sleep 2; done
"${wp2[@]}" core install --url="http://127.0.0.1:${port2}" --title='External Metrics Reimport' \
	--admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp2[@]}" core version > "$evidence/wordpress-second-site-version.txt"
docker run --rm -v "$volume2:/target" -v "$core_archive:/core.tar.gz:ro" alpine:latest \
	sh -c 'tar -xzf /core.tar.gz -C /target --no-same-owner --exclude="._*"'
"${wp2[@]}" core version > "$evidence/wordpress-second-site-core-version.txt"
test "$(<"$evidence/wordpress-second-site-core-version.txt")" = '7.1'
"${wp2[@]}" plugin activate static-site-importer
"${wp2[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-import-export-wordpress.php \
	| tee "$evidence/reimport-result.jsonl"
"${wp2[@]}" plugin deactivate static-site-importer
"${wp2[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/external-metrics-standalone-wordpress.php \
	| tee "$evidence/standalone-result.jsonl"
post_id="$(node -e 'const fs=require("fs");const r=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(String(r.post_id))' "$evidence/materialization.jsonl")"
SSI_EXTERNAL_METRICS_WP_URL="http://127.0.0.1:${port}" SSI_EXTERNAL_METRICS_POST_ID="$post_id" \
	SSI_EXTERNAL_METRICS_USER=admin SSI_EXTERNAL_METRICS_PASSWORD=password SSI_EXTERNAL_METRICS_EVIDENCE="$evidence" \
	node "$root/tests/acceptance/external-metrics-editor.mjs" | tee "$evidence/editor-run.jsonl"
printf 'core_archive_sha256=%s\ncore_version=%s\n' "$actual_sha256" "$(<"$evidence/wordpress-core-version.txt")" > "$evidence/runtime-identity.txt"
printf 'External metric materialization evidence retained at %s\n' "$evidence"
