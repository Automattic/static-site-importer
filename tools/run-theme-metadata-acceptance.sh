#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
evidence="${SSI_THEME_METADATA_EVIDENCE:?Supply evidence directory outside this checkout}"
core_archive="${SSI_WORDPRESS_CORE_ARCHIVE:?Supply the verified WordPress 7.1 core archive}"
expected_sha256="a874a9c66927ba4e21f30dd88b31c1df12f5a25049e81efb4ceab856da43c27b"
actual_sha256="$(sha256sum "$core_archive" | cut -d' ' -f1)"
test "$actual_sha256" = "$expected_sha256"
test -f "$root/vendor/autoload.php"
mkdir -p "$evidence"
chmod 0733 "$evidence"
project="ssi_theme_meta_${RANDOM}_$$"
network="${project}_net"
volume="${project}_wp"
port="$((19000 + RANDOM % 1000))"
cleanup() {
	if docker inspect "${project}_wp" >/dev/null 2>&1; then
		docker logs "${project}_wp" > "$evidence/wordpress.log" 2>&1 || true
	fi
	docker rm --force "${project}_wp" "${project}_db" >/dev/null 2>&1 || true
	docker network rm "$network" >/dev/null 2>&1 || true
	docker volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$network" >/dev/null
docker run --detach --name "${project}_db" --network "$network" --network-alias mysql \
	-e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress \
	-e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
for attempt in $(seq 1 60); do
	if docker exec "${project}_db" mysqladmin ping -uwordpress -pwordpress >/dev/null 2>&1; then break; fi
	sleep 2
done
docker run --detach --name "${project}_wp" --network "$network" --publish "127.0.0.1:${port}:80" \
	-e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
	-v "$volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" \
	-v "$evidence:/evidence" wordpress:7.0.4-php8.3-apache >/dev/null
wp=(docker run --rm --network "$network" --user 33:33 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
	-e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
	-e SSI_THEME_METADATA_DISPOSABLE=1 -v "$volume:/var/www/html" \
	-v "$root:/var/www/html/wp-content/plugins/static-site-importer:ro" -v "$evidence:/evidence" \
	wordpress:cli-php8.3 wp --allow-root)
for attempt in $(seq 1 60); do
	if curl --silent --fail "http://127.0.0.1:${port}/wp-login.php" >/dev/null; then break; fi
	sleep 2
done
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='Theme Metadata Acceptance' \
	--admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" core version > "$evidence/bootstrap-core-version.txt"

# Overlay only the supplied core payload after the image created wp-config.php and
# the database. It contains no host config, content tree, or database state.
docker run --rm -v "$volume:/target" -v "$core_archive:/core.tar.gz:ro" alpine:latest \
	sh -c 'tar -xzf /core.tar.gz -C /target --no-same-owner --exclude="._*"'
"${wp[@]}" core version > "$evidence/wordpress-core-version.txt"
test "$(<"$evidence/wordpress-core-version.txt")" = '7.1'
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/theme-metadata-wordpress.php \
	| tee "$evidence/runtime-stdout.jsonl"
docker exec "${project}_wp" sh -c 'test -f /var/www/html/wp-config.php && test -d /var/www/html/wp-content'
printf 'core_archive_sha256=%s\ncore_version=%s\n' "$actual_sha256" "$(<"$evidence/wordpress-core-version.txt")" > "$evidence/runtime-identity.txt"
