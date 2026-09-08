#!/usr/bin/env bash
set -euo pipefail

# This runner owns a throwaway Docker project and never addresses a developer site.
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
for command in docker curl node timeout; do command -v "$command" >/dev/null || { printf 'Missing %s\n' "$command" >&2; exit 2; }; done
test -d "$root/vendor" || { printf 'Run composer install before this acceptance test.\n' >&2; exit 2; }
test -d "$root/node_modules" || { printf 'Run npm install before this acceptance test.\n' >&2; exit 2; }
evidence="${SSI_EDITOR_EVIDENCE_DIR:?Set SSI_EDITOR_EVIDENCE_DIR outside the repository and temporary directory.}"
mkdir -p "$evidence"
exec > >(tee -a "$evidence/runner.log") 2>&1

work="$(mktemp -d "${TMPDIR:-/tmp}/ssi-editor-acceptance.XXXXXX")"
project="ssi_editor_${RANDOM}_$$"
port="${SSI_EDITOR_PORT:-$((18000 + RANDOM % 1000))}"
network="${project}_network"
volume="${project}_wordpress"
wordpress_image="${SSI_EDITOR_WORDPRESS_IMAGE:-wordpress:php8.3-apache}"
cli_image="${SSI_EDITOR_CLI_IMAGE:-wordpress:cli-php8.3}"
db_host="mysql"
db_name="wordpress"
db_user="wordpress"
db_password="wordpress"
cleanup() { docker logs "${project}_wordpress" > "$evidence/wordpress.log" 2>&1 || true; docker logs "${project}_db" > "$evidence/mysql.log" 2>&1 || true; docker rm --force "${project}_wordpress" "${project}_db" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; docker volume rm "$volume" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
run() { timeout "${SSI_EDITOR_COMMAND_TIMEOUT:-180}" "$@"; }
wait_for() { local label="$1" command="$2"; local attempt; for attempt in $(seq 1 60); do if eval "$command"; then return 0; fi; sleep 2; done; printf 'Timed out waiting for %s after 120 seconds.\n' "$label" >&2; return 1; }

cat > "$work/request.json" <<'EOF'
{"operation":"apply","source":{"type":"files","entrypoint":"index.html","files":[{"path":"index.html","content":"<!doctype html><html><head><style>.map { box-sizing: border-box; max-width: 100%; }</style></head><body><header><p>Editor acceptance</p></header><main><h1>Iframe acceptance</h1><iframe class=\"map\" title=\"Map\" src=\"https://example.test/map\" width=\"640\" height=\"360\" loading=\"lazy\"></iframe></main><footer><p>Generated theme document</p></footer></body></html>"}]},"slug":"editor-acceptance","name":"Editor acceptance","activate":true,"overwrite":true}
EOF
# The CLI runs as www-data. It can traverse this unlistable mount, read the fixture, and write only in its unlistable output directory.
chmod 0711 "$work"
chmod 0644 "$work/request.json"
mkdir "$work/output"
chmod 0733 "$work/output"

run docker network create "$network" >/dev/null
run docker volume create "$volume" >/dev/null
run docker run --detach --name "${project}_db" --network "$network" --network-alias "$db_host" -e MYSQL_DATABASE="$db_name" -e MYSQL_USER="$db_user" -e MYSQL_PASSWORD="$db_password" -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
wait_for 'MySQL' "run docker exec ${project}_db mysqladmin ping -u${db_user} -p${db_password}"
run docker run --detach --name "${project}_wordpress" --network "$network" --publish "127.0.0.1:${port}:80" -e WORDPRESS_DB_HOST="$db_host" -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume}:/var/www/html" -v "${root}:/var/www/html/wp-content/plugins/static-site-importer" "$wordpress_image" >/dev/null
wp=(run docker run --rm --network "$network" --user 33:33 -e WORDPRESS_DB_HOST="$db_host" -e WORDPRESS_DB_NAME="$db_name" -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume}:/var/www/html" -v "${root}:/var/www/html/wp-content/plugins/static-site-importer" -v "${work}:/work" "$cli_image" wp --allow-root)
wait_for 'WordPress files' "curl --silent --fail http://127.0.0.1:${port}/wp-login.php >/dev/null"
"${wp[@]}" core install --url="http://127.0.0.1:${port}" --title='SSI Editor Acceptance' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" core version | tee "$evidence/wordpress-version.txt"
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" plugin install gutenberg --activate
"${wp[@]}" plugin get gutenberg --field=version | tee "$evidence/gutenberg-version.txt"
"${wp[@]}" static-site-importer import --request=/work/request.json --report=/work/output/import-report.json | tee "$evidence/import-result.jsonl"
for report in import-report import-validation-result finding-packets; do
	run docker run --rm --user 33:33 --entrypoint cat -v "${work}:/work" "$cli_image" "/work/output/${report}.json" > "$evidence/${report}.json"
done
# The canonical import receipt is the authority for the persisted entry page;
# source slugs can legitimately differ from the request's theme slug.
post_id="$(node -e 'const fs=require("fs"); const report=JSON.parse(fs.readFileSync(process.argv[1], "utf8")); const receipt=report.materialization_receipt; const pages=receipt && receipt.completed && receipt.completed.pages; if (!receipt || receipt.status !== "completed" || !pages || typeof pages !== "object") process.exit(1); const ids=[...new Set(Object.values(pages).filter((id) => Number.isInteger(id) && id > 0))]; if (ids.length !== 1) process.exit(1); process.stdout.write(String(ids[0]));' "$evidence/import-report.json")"
test -n "$post_id"
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tools/editor-companion-document-inventory.php "$post_id" | tee "$evidence/inventory.json"
block_name="$(node -e 'const i=require(process.argv[1]); const n=[...new Set(i.documents.flatMap(d=>d.blocks))].find(n=>n.endsWith("/visual-iframe")); if (!n) process.exit(1); process.stdout.write(n)' "$evidence/inventory.json")"
negative_markup="<!-- wp:${block_name} {\"src\":\"https://example.test/bad\",\"title\":\"Bad\"} --><iframe title=\"Bad\" src=\"https://example.test/not-matching\"></iframe><!-- /wp:${block_name} -->"
negative_post_id="$("${wp[@]}" post create --post_type=page --post_status=publish --post_title='Invalid companion acceptance' --post_content="$negative_markup" --porcelain)"
SSI_EDITOR_WP_URL="http://127.0.0.1:${port}" SSI_EDITOR_POST_ID="$post_id" SSI_EDITOR_NEGATIVE_POST_ID="$negative_post_id" SSI_EDITOR_USER=admin SSI_EDITOR_PASSWORD=password SSI_EDITOR_INVENTORY="$evidence/inventory.json" SSI_EDITOR_EVIDENCE_DIR="$evidence" run node "$root/tests/editor-companion-acceptance.mjs" | tee "$evidence/browser.json"
printf 'Evidence retained at %s\n' "$evidence"
