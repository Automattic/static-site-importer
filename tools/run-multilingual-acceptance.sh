#!/usr/bin/env bash
set -euo pipefail
root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
root="${1:-$root}"
evidence="${SSI_MULTILINGUAL_EVIDENCE:-$root/artifacts/multilingual}"
mkdir -p "$evidence"
chmod 0733 "$evidence"
project="ssi_multilingual_${RANDOM}_$$"
network="${project}_network"
volume="${project}_wordpress"
port="${SSI_MULTILINGUAL_PORT:-19601}"
mounts=(-v "$volume:/var/www/html" -v "$root:/var/www/html/wp-content/plugins/static-site-importer")
cleanup() {
  docker logs "${project}_wordpress" > "$evidence/wordpress.log" 2>&1 || true
  docker logs "${project}_db" > "$evidence/database.log" 2>&1 || true
  docker rm --force "${project}_wordpress" "${project}_db" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  docker volume rm "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null
docker run --detach --name "${project}_db" --network "$network" --network-alias mysql -e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress -e MYSQL_RANDOM_ROOT_PASSWORD=yes mysql:8.4 >/dev/null
docker run --detach --name "${project}_wordpress" --network "$network" --publish "127.0.0.1:$port:80" -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress "${mounts[@]}" wordpress:beta-php8.3-apache >/dev/null
wp=(timeout 900 docker run --rm --network "$network" --user 33:33 -e SSI_MULTILINGUAL_DISPOSABLE=1 -e WORDPRESS_DB_HOST=mysql -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress "${mounts[@]}" wordpress:cli-php8.3 wp --allow-root --user=admin)
ready=0
for attempt in $(seq 1 60); do
  if docker exec "${project}_db" mysql -uwordpress -pwordpress wordpress -e 'SELECT 1' >/dev/null 2>&1 && curl --silent --fail "http://127.0.0.1:$port/wp-login.php" >/dev/null; then ready=1; break; fi
  sleep 2
done
if test "$ready" != 1; then
  exit 1
fi
"${wp[@]}" core install --url="http://127.0.0.1:$port" --title='Native multilingual acceptance' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
"${wp[@]}" rewrite structure '/%postname%/' --hard
"${wp[@]}" plugin activate static-site-importer
"${wp[@]}" eval '$GLOBALS["is_apache"] = true; add_filter("got_rewrite", "__return_true"); flush_rewrite_rules(true);'
"${wp[@]}" eval-file wp-content/plugins/static-site-importer/tests/acceptance/multilingual-wordpress.php | tee "$evidence/native.jsonl"
curl --silent --show-error --fail "http://127.0.0.1:$port/" --output "$evidence/home.html"
curl --silent --show-error --fail "http://127.0.0.1:$port/fr/" --output "$evidence/french.html"
node -e 'const fs=require("node:fs"); const html=fs.readFileSync(process.argv[1],"utf8"); if(!html.includes("trp-language-switcher") || !html.includes("/fr/") || !html.includes("Hello community")) throw Error("Native language selector or source document missing"); console.log(JSON.stringify({status:"passed",nativeSelector:true,frenchRoute:true,sourceContent:true}));' "$evidence/home.html" | tee "$evidence/frontend.json"
node "$root/tests/acceptance/multilingual-browser.mjs" "http://127.0.0.1:$port" "$evidence"
