#!/usr/bin/env bash
# Blocks Engine contracts its CI runs beyond `composer test:unit`, against the
# bare WordPress at $BARE_WP. Runs from the repository root.
set -euo pipefail
cd php-transformer
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
export BODY_CONTEXT_WORDPRESS_BOOTSTRAP="${BARE_WP:?}/bootstrap.php" BODY_CONTEXT_ARTIFACT="$tmp/artifact.json" BODY_CONTEXT_EVIDENCE="$tmp/evidence.json"
[ -d tools/visual-parity/node_modules/playwright ] || npm ci --silent --prefix tools/visual-parity
php tests/contract/document-body-context.php
PLAYWRIGHT_MODULE="$PWD/tools/visual-parity/node_modules/playwright/index.mjs" node tests/contract/document-body-context-browser.mjs
