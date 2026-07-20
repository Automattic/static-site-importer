#!/usr/bin/env bash
set -euo pipefail

# Build the PHP 8.5 JSPI side module with the same Emscripten toolchain that
# @php-wasm/compile-extension uses for the Playground PHP runtime.
readonly EXT_ZSTD_REF='0bf5825ad683e637211a0eacec4fe545992f5b67'
readonly LIBZSTD_REF='63779c798237346c2b245c546c40b72a5a5913fe'
readonly PHP_WASM_COMPILE_EXTENSION_VERSION='3.1.45'
readonly ARTIFACT_NAME='static-site-importer-zstd-php8.5-jspi.so'
readonly MANIFEST_NAME='static-site-importer-zstd-php8.5-jspi.manifest.json'
readonly RELEASE_BASE_URL='https://github.com/Automattic/static-site-importer/releases/latest/download'

root="$(git rev-parse --show-toplevel)"
out_dir="${1:-$root/dist/php-wasm-zstd}"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

git clone --quiet --no-checkout https://github.com/kjdev/php-ext-zstd.git "$work_dir/php-ext-zstd"
git -C "$work_dir/php-ext-zstd" checkout --quiet --detach "$EXT_ZSTD_REF"
git -C "$work_dir/php-ext-zstd" submodule update --init --recursive

actual_libzstd_ref="$(git -C "$work_dir/php-ext-zstd/zstd" rev-parse HEAD)"
if [ "$actual_libzstd_ref" != "$LIBZSTD_REF" ]; then
	echo "Unexpected libzstd source: $actual_libzstd_ref" >&2
	exit 1
fi

rm -rf "$out_dir"
mkdir -p "$out_dir"
npx --yes "@php-wasm/compile-extension@$PHP_WASM_COMPILE_EXTENSION_VERSION" \
	--source "$work_dir/php-ext-zstd" \
	--name zstd \
	--php-versions 8.5 \
	--extra-cflags '-U__x86_64__' \
	--out "$out_dir"

module_path="$out_dir/zstd-php8.5-jspi.so"
if [ ! -f "$module_path" ]; then
	echo "PHP.wasm compiler did not produce $module_path" >&2
	exit 1
fi
mv "$module_path" "$out_dir/$ARTIFACT_NAME"

node -e '
const fs = require("node:fs");
const [output, manifestName, artifactName, releaseBase] = process.argv.slice(1);
const manifest = {
  name: "zstd",
  version: "0.13.3",
  mode: "php-extension",
  artifacts: [{ phpVersion: "8.5", sourcePath: `${releaseBase}/${artifactName}` }],
};
fs.writeFileSync(`${output}/${manifestName}`, JSON.stringify(manifest, null, 2) + "\n");
manifest.artifacts[0].sourcePath = artifactName;
fs.writeFileSync(`${output}/manifest.json`, JSON.stringify(manifest, null, 2) + "\n");
' "$out_dir" "$MANIFEST_NAME" "$ARTIFACT_NAME" "$RELEASE_BASE_URL"

printf 'Built %s and %s in %s\n' "$ARTIFACT_NAME" "$MANIFEST_NAME" "$out_dir"
