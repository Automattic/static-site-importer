#!/usr/bin/env bash
set -euo pipefail
root="${1:-$(git rev-parse --show-toplevel)}"
evidence="${SSI_REDIRECTION_EVIDENCE:-$root/artifacts/redirection}"
mkdir -p "$evidence"
chmod 0733 "$evidence"
project="ssi_redirection_${RANDOM}_$$"
network="${project}_network"
volume="${project}_wordpress"
second_volume="${project}_second"
port="${SSI_REDIRECTION_PORT:-19603}"
mounts=(-v "$volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer")
cleanup() {
  docker logs "${project}_wordpress" > "$evidence/wordpress.log" 2>&1 || true
  docker logs "${project}_db" > "$evidence/database.log" 2>&1 || true
  docker rm --force "${project}_wordpress" "${project}_second" "${project}_static" "${project}_db" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  docker volume rm "$volume" >/dev/null 2>&1 || true
  docker volume rm "$second_volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null
# Synchronous I/O keeps this disposable proof independent of shared-host AIO quotas.
docker run --detach --name "${project}_db" --network "$network" --network-alias mysql -e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 --innodb-use-native-aio=0 >/dev/null
docker run --detach --name "${project}_wordpress" --network "$network" --publish "127.0.0.1:$port:80" -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress "${mounts[@]}" wordpress:beta-php8.3-apache >/dev/null
wp=(timeout 900 docker run --rm --network "$network" --user 33:33 -e SSI_REDIRECTION_DISPOSABLE=1 -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress "${mounts[@]}" wordpress:cli-php8.3 wp --allow-root --user=admin)
ready=0
for attempt in $(seq 1 60); do
  if docker exec "${project}_db" mysql -uwordpress -pwordpress wordpress -e 'SELECT 1' >/dev/null 2>&1 && curl --silent --fail "http://127.0.0.1:$port/wp-login.php" >/dev/null; then ready=1; break; fi
  sleep 2
done
if test "$ready" != 1; then exit 1; fi
"${wp[@]}" core install --url="http://127.0.0.1:$port" --title='Native redirect acceptance' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" rewrite structure '/%postname%/' --hard
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" eval '$GLOBALS["is_apache"] = true; add_filter("got_rewrite", "__return_true"); flush_rewrite_rules(true);'
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/redirection-wordpress.php | tee "$evidence/native.jsonl"
curl --silent --show-error --dump-header "$evidence/get.headers" --output /dev/null "http://127.0.0.1:$port/old?trace=preserved"
curl --silent --show-error --head "http://127.0.0.1:$port/old" --output "$evidence/head.headers"
curl --silent --show-error --fail "http://127.0.0.1:$port/owner-destination/" --output "$evidence/owner.html"
node -e 'const fs=require("node:fs"); const get=fs.readFileSync(process.argv[1]+"/get.headers","utf8"); const head=fs.readFileSync(process.argv[1]+"/head.headers","utf8"); if(!get.includes("301") || !head.includes("301") || !get.includes("owner-destination") || !get.includes("trace=preserved") || !head.includes("owner-destination")) throw Error("Native owner-edited GET/HEAD/query redirect failed"); console.log(JSON.stringify({status:"passed",get:301,head:301,queryPreserved:true,ownerEditRespected:true}));' "$evidence" | tee "$evidence/http.json"
curl --silent --show-error --dump-header "$evidence/event.headers" --output /dev/null "http://127.0.0.1:$port/event-detail"
curl --silent --show-error --fail --location "http://127.0.0.1:$port/event-detail" --output "$evidence/event.html"
node -e 'const fs=require("node:fs");const root=process.argv[1];if(!fs.readFileSync(root+"/event.headers","utf8").includes("301")||!fs.readFileSync(root+"/event.html","utf8").includes("Committed provider-owned event content."))throw Error("Native CPT redirect did not reach its committed source document");' "$evidence"
node -e 'const fs=require("node:fs"),path=require("node:path"),crypto=require("node:crypto"); const root=process.argv[1]; const artifact=JSON.parse(fs.readFileSync(root+"/export.json","utf8")); for(const file of artifact.files){const bytes=Buffer.from(file.content,file.encoding==="base64"?"base64":"utf8");if(file.sha256&&crypto.createHash("sha256").update(bytes).digest("hex")!==file.sha256)throw Error("Export bytes changed");const target=path.resolve(root,"served",file.path);if(!target.startsWith(path.resolve(root,"served")+path.sep))throw Error("Unsafe artifact path");fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,bytes);} console.log("Full exported artifact materialized with verified hashes");' "$evidence"
docker run --detach --name "${project}_static" --network "$network" --publish "127.0.0.1:19605:80" -v "$evidence/served/website:/usr/local/apache2/htdocs:ro" httpd:alpine >/dev/null
for attempt in $(seq 1 30); do if curl --silent --fail 'http://127.0.0.1:19605/owner-destination/' --output "$evidence/served-owner.html"; then break; fi; sleep 1; done
node -e 'const html=require("node:fs").readFileSync(process.argv[1],"utf8");if(!html.includes("Owner-managed route."))throw Error("The complete exported target did not serve its saved owner content");' "$evidence/served-owner.html"
docker volume create "$second_volume" >/dev/null
second_mounts=(-v "$second_volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer")
docker run --detach --name "${project}_second" --network "$network" --publish '127.0.0.1:19604:80' -e WORDPRESS_TABLE_PREFIX=roundtrip_ -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress "${second_mounts[@]}" wordpress:beta-php8.3-apache >/dev/null
second_wp=(timeout 900 docker run --rm --network "$network" --user 33:33 -e WORDPRESS_TABLE_PREFIX=roundtrip_ -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress "${second_mounts[@]}" wordpress:cli-php8.3 wp --allow-root --user=admin)
for attempt in $(seq 1 30); do if curl --silent --fail 'http://127.0.0.1:19604/wp-login.php' >/dev/null; then break; fi; sleep 1; done
"${second_wp[@]}" core install --url='http://127.0.0.1:19604' --title='Native redirect roundtrip' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${second_wp[@]}" rewrite structure '/%postname%/' --hard
"${second_wp[@]}" plugin activate static-site-importer
"${second_wp[@]}" eval '$GLOBALS["is_apache"] = true; add_filter("got_rewrite", "__return_true"); flush_rewrite_rules(true);'
"${second_wp[@]}" static-site-importer import --request=wp-content/plugins/static-site-importer/artifacts/redirection/reimport-request.json --keep-source | tee "$evidence/roundtrip.jsonl"
curl --silent --show-error --dump-header "$evidence/roundtrip.headers" --output /dev/null 'http://127.0.0.1:19604/old'
curl --silent --show-error --fail 'http://127.0.0.1:19604/owner-destination/' --output "$evidence/roundtrip-owner.html"
node -e 'const fs=require("node:fs");const root=process.argv[1],headers=fs.readFileSync(root+"/roundtrip.headers","utf8"),html=fs.readFileSync(root+"/roundtrip-owner.html","utf8");if(!headers.includes("301")||!headers.includes("19604/owner-destination/")||!html.includes("Owner-managed route."))throw Error("Second-site native redirect and target content did not survive export/reimport");console.log(JSON.stringify({status:"passed",servedFullExport:true,secondSiteRedirect:301,secondSiteTarget:true}));' "$evidence" | tee "$evidence/roundtrip.json"
node "$root/tests/acceptance/redirection-browser.mjs" "http://127.0.0.1:$port" "$evidence"
