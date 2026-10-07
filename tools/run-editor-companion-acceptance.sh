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
printf 'SSI_EDITOR_EVIDENCE_DIR=%s npm run test:editor-companion-acceptance\n' "$evidence" > "$evidence/acceptance-command.txt"
git -C "$root" rev-parse HEAD > "$evidence/source-commit.txt"
git -C "$root" diff --binary > "$evidence/source-change.patch"

work="$(mktemp -d "${TMPDIR:-/tmp}/ssi-editor-acceptance.XXXXXX")"
project="ssi_editor_${RANDOM}_$$"
port="${SSI_EDITOR_PORT:-$((18000 + RANDOM % 1000))}"
port2="$((port + 1000))"
network="${project}_network"
volume="${project}_wordpress"
volume2="${project}_wordpress_reimport"
wordpress_image="${SSI_EDITOR_WORDPRESS_IMAGE:-wordpress:php8.3-apache}"
cli_image="${SSI_EDITOR_CLI_IMAGE:-wordpress:cli-php8.3}"
db_host="mysql"
db_name="wordpress"
db_user="wordpress"
db_password="wordpress"
cleanup() { docker logs "${project}_wordpress" > "$evidence/wordpress.log" 2>&1 || true; docker logs "${project}_db" > "$evidence/mysql.log" 2>&1 || true; docker logs "${project}_wordpress_reimport" > "$evidence/wordpress-reimport.log" 2>&1 || true; docker logs "${project}_db_reimport" > "$evidence/mysql-reimport.log" 2>&1 || true; docker rm --force "${project}_wordpress" "${project}_db" "${project}_wordpress_reimport" "${project}_db_reimport" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; docker volume rm "$volume" "$volume2" >/dev/null 2>&1 || true; rm -rf "$work"; }
trap cleanup EXIT
run() { timeout "${SSI_EDITOR_COMMAND_TIMEOUT:-180}" "$@"; }
wait_for() { local label="$1" command="$2"; local attempt; for attempt in $(seq 1 60); do if eval "$command"; then return 0; fi; sleep 2; done; printf 'Timed out waiting for %s after 120 seconds.\n' "$label" >&2; return 1; }

cat > "$work/request.json" <<'EOF'
{"operation":"apply","source":{"type":"files","entrypoint":"index.html","files":[{"path":"index.html","content":"<!doctype html><html><head><style>.map { box-sizing: border-box; max-width: 100%; }</style></head><body><header><p>Editor acceptance</p></header><main><h1>Iframe acceptance</h1><iframe class=\"map\" title=\"Map\" src=\"https://example.test/map\" width=\"640\" height=\"360\" loading=\"lazy\"></iframe><canvas id=\"acceptance-chart\" width=\"100\" height=\"60\"></canvas><script>const ctx = document.getElementById(\"acceptance-chart\").getContext(\"2d\"); ctx.fillStyle = \"rgb(220, 20, 60)\"; ctx.fillRect(0, 0, 100, 60);</script><a href=\"index.html\">Source home route</a></main><footer><p>Generated theme document</p></footer></body></html>"}]},"slug":"editor-acceptance","name":"Editor acceptance","activate":true,"overwrite":true}
EOF
# The CLI runs as www-data. It can traverse this unlistable mount, read the fixture, and write only in its unlistable output directory.
chmod 0711 "$work"
chmod 0644 "$work/request.json"
cp "$work/request.json" "$evidence/source-request.json"
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
docker image inspect "$wordpress_image" "$cli_image" --format '{{json .RepoDigests}}' > "$evidence/runtime-image-digests.jsonl"
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" plugin install gutenberg --activate
"${wp[@]}" plugin get gutenberg --field=version | tee "$evidence/gutenberg-version.txt"
"${wp[@]}" static-site-importer import --request=/work/request.json --report=/work/output/import-report.json | tee "$evidence/import-result.jsonl"
"${wp[@]}" plugin is-active static-site-importer
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/theme-runtime-inventory.php | tee "$evidence/provider-runtime-lifecycle.json"
run node "$root/tools/theme-runtime-browser-proof.mjs" "http://127.0.0.1:${port}/" "$evidence/source-request.json" "$evidence" initial
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
SSI_EDITOR_WP_URL="http://127.0.0.1:${port}" SSI_EDITOR_POST_ID="$post_id" SSI_EDITOR_NEGATIVE_POST_ID="$negative_post_id" SSI_EDITOR_USER=admin SSI_EDITOR_PASSWORD=password SSI_EDITOR_INVENTORY="$evidence/inventory.json" SSI_EDITOR_EVIDENCE_DIR="$evidence" SSI_EDITOR_LIFECYCLE="$evidence/provider-runtime-lifecycle.json" run node "$root/tests/editor-companion-acceptance.mjs" | tee "$evidence/browser.json"
"${wp[@]}" eval '$post_id = (int) '"$post_id"'; $post = get_post( $post_id ); wp_update_post( array( "ID" => $post_id, "post_content" => (string) $post->post_content . "<!-- wp:paragraph --><p>External directory installs: 42,000</p><!-- /wp:paragraph -->" ) );'
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/companion-roundtrip.php export | tee "$evidence/theme-export-summary.json"
run docker run --rm --user 33:33 --entrypoint cat -v "${work}:/work" "$cli_image" /work/output/export-envelope.json > "$evidence/export-envelope.json"
node -e 'const fs=require("fs");const crypto=require("crypto");const envelope=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));const a=envelope.website_artifact;if(!a||a.schema!=="blocks-engine/php-transformer/site-artifact/v1"||!a.id||!Array.isArray(a.files)||!a.files.length)process.exit(1);for(const f of a.files){if(!f.sha256)throw new Error(`missing export digest: ${f.path}`);const bytes=f.encoding==="base64"?Buffer.from(f.content_base64||"","base64"):Buffer.from(f.content||"");if(crypto.createHash("sha256").update(bytes).digest("hex")!==f.sha256)throw new Error(`export digest mismatch: ${f.path}`)}const {schema,entrypoint,files,...metadata}=a;fs.writeFileSync(process.argv[2],JSON.stringify({operation:"apply",source:{type:"files",entrypoint,files,metadata},slug:"editor-acceptance-reimport",name:"Editor Acceptance Reimport",activate:true,overwrite:true}));process.stdout.write(JSON.stringify({artifact_id:a.id,file_count:files.length,provenance:a.provenance||{},request:process.argv[2]}))' "$work/output/export-envelope.json" "$work/reimport-request.json" | tee "$evidence/reimport-request-summary.json"
run docker volume create "$volume2" >/dev/null
run docker run --detach --name "${project}_db_reimport" --network "$network" --network-alias mysql-reimport -e MYSQL_DATABASE=wordpress_reimport -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
wait_for 'reimport MySQL' "run docker exec ${project}_db_reimport mysqladmin ping -u${db_user} -p${db_password}"
run docker run --detach --name "${project}_wordpress_reimport" --network "$network" --publish "127.0.0.1:${port2}:80" -e WORDPRESS_DB_HOST=mysql-reimport -e WORDPRESS_DB_NAME=wordpress_reimport -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume2}:/var/www/html" -v "${root}:/var/www/html/wp-content/plugins/static-site-importer" "$wordpress_image" >/dev/null
wp2=(run docker run --rm --network "$network" --user 33:33 -e WORDPRESS_DB_HOST=mysql-reimport -e WORDPRESS_DB_NAME=wordpress_reimport -e WORDPRESS_DB_USER="$db_user" -e WORDPRESS_DB_PASSWORD="$db_password" -v "${volume2}:/var/www/html" -v "${root}:/var/www/html/wp-content/plugins/static-site-importer" -v "${work}:/work" "$cli_image" wp --allow-root)
wait_for 'reimport WordPress files' "curl --silent --fail http://127.0.0.1:${port2}/wp-login.php >/dev/null"
"${wp2[@]}" core install --url="http://127.0.0.1:${port2}" --title='SSI Companion Reimport' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp2[@]}" core version | tee "$evidence/reimport-wordpress-version.txt"
"${wp2[@]}" plugin activate static-site-importer
"${wp2[@]}" plugin install gutenberg --activate
"${wp2[@]}" static-site-importer import --request=/work/reimport-request.json | tee "$evidence/reimport-result.jsonl"
"${wp2[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/theme-runtime-inventory.php | tee "$evidence/reimport-provider-runtime-lifecycle.json"
run docker run --rm --user 33:33 --entrypoint cat -v "${work}:/work" "$cli_image" /work/reimport-request.json > "$evidence/reimport-request.json"
exported_artifact_id="$(node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).website_artifact.id)' "$work/output/export-envelope.json")"
test -n "$exported_artifact_id"
"${wp2[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/companion-roundtrip.php verify-reimport "$exported_artifact_id" | tee "$evidence/reimport-companion.json"
node -e 'const fs=require("fs");const r=require(process.argv[1]);const lines=fs.readFileSync(process.argv[2],"utf8").trim().split(/\n/);const receipt=JSON.parse(lines[lines.length-1]);const quality=receipt.response?.result?.import_report_summary;if(!r.importer_active||!r.theme_runtime_loaded||!r.no_generated_plugin||!r.theme_identity_truth||!r.readme_inventory_truth||!r.readme_snapshot_truth||!r.provenance_truth||!r.source_provenance_truth||r.source_artifact.id!==process.argv[3]||r.route_source_path!=="index.html"||!quality?.quality_pass||quality.fallback_count!==0) {console.error(JSON.stringify({r,quality}));process.exit(1)} console.log("PASS: second-site import has native quality and truthful theme inventory, provenance and route")' "$evidence/reimport-companion.json" "$evidence/reimport-result.jsonl" "$exported_artifact_id"
curl --silent --fail "http://127.0.0.1:${port2}/" > "$evidence/reimport-frontend.html"
node -e 'const fs=require("fs");const html=fs.readFileSync(process.argv[1],"utf8");if(!html.includes("example.test/updated-map")||!html.includes("42,000"))process.exit(1);console.log("PASS: exported saved page content renders on the reimported site")' "$evidence/reimport-frontend.html"
route_status="$(curl --silent --dump-header "$evidence/reimport-route-headers.txt" --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${port2}/index.html")"
test "$route_status" = 301
printf 'Reimport source route returned HTTP %s\n' "$route_status" | tee "$evidence/reimport-route.txt"
node -e 'const fs=require("fs");const result=require(process.argv[1]);const headers=fs.readFileSync(process.argv[2],"utf8");const match=headers.match(/^Location:\s*(.+)$/im);if(!match||new URL(match[1].trim()).href!==new URL(result.route_redirect_target).href){console.error(JSON.stringify({location:match?.[1],expected:result.route_redirect_target}));process.exit(1)}console.log("PASS: source-route HTTP Location matches the materialized page permalink")' "$evidence/reimport-companion.json" "$evidence/reimport-route-headers.txt"
"${wp[@]}" plugin deactivate static-site-importer
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/theme-runtime-inventory.php | tee "$evidence/theme-after-importer-removal.json"
node -e 'const r=require(process.argv[1]);if(r.importer_active||!r.no_generated_plugin||!r.theme_runtime_loaded||!r.readme_inventory_truth||!r.theme_provider_runtime_loaded){console.error(JSON.stringify(r));process.exit(1)}console.log("PASS: theme runtime, inventory and assets survive importer removal")' "$evidence/theme-after-importer-removal.json"
curl --silent --fail "http://127.0.0.1:${port}/" > "$evidence/frontend-after-importer-removal.html"
node -e 'const fs=require("fs"); const html=fs.readFileSync(process.argv[1], "utf8"); if(!html.includes("example.test/updated-map")) process.exit(1); console.log("PASS: persisted edited frontend still renders after importer removal")' "$evidence/frontend-after-importer-removal.html"
run node "$root/tools/theme-runtime-browser-proof.mjs" "http://127.0.0.1:${port}/" "$evidence/source-request.json" "$evidence" importer-removed
"${wp2[@]}" plugin deactivate static-site-importer
"${wp2[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/theme-runtime-inventory.php | tee "$evidence/reimport-theme-after-importer-removal.json"
"${wp2[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/companion-roundtrip.php verify-removed "$exported_artifact_id" | tee "$evidence/reimport-companion-after-importer-removal.json"
node -e 'const r=require(process.argv[1]);if(r.importer_active||!r.theme_runtime_loaded||!r.no_generated_plugin||!r.theme_identity_truth||!r.readme_inventory_truth||!r.readme_snapshot_truth||!r.provenance_truth||!r.source_provenance_truth||r.source_artifact.id!==process.argv[2]){console.error(JSON.stringify(r));process.exit(1)}console.log("PASS: reimported theme and route runtime survive SSI removal")' "$evidence/reimport-companion-after-importer-removal.json" "$exported_artifact_id"
curl --silent --fail "http://127.0.0.1:${port2}/" > "$evidence/reimport-frontend-after-importer-removal.html"
node -e 'const fs=require("fs");const html=fs.readFileSync(process.argv[1],"utf8");if(!html.includes("example.test/updated-map")||!html.includes("42,000"))process.exit(1);console.log("PASS: reimported frontend remains operational after SSI removal")' "$evidence/reimport-frontend-after-importer-removal.html"
run node "$root/tools/theme-runtime-browser-proof.mjs" "http://127.0.0.1:${port2}/" "$evidence/source-request.json" "$evidence" reimport-importer-removed
route_status="$(curl --silent --dump-header "$evidence/reimport-route-after-importer-removal-headers.txt" --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${port2}/index.html")"
test "$route_status" = 301
node -e 'const fs=require("fs");const result=require(process.argv[1]);const headers=fs.readFileSync(process.argv[2],"utf8");const match=headers.match(/^Location:\s*(.+)$/im);if(!match||new URL(match[1].trim()).href!==new URL(result.route_redirect_target).href){console.error(JSON.stringify({location:match?.[1],expected:result.route_redirect_target}));process.exit(1)}console.log("PASS: source route still redirects correctly after SSI removal")' "$evidence/reimport-companion-after-importer-removal.json" "$evidence/reimport-route-after-importer-removal-headers.txt"
printf 'Evidence retained at %s\n' "$evidence"
