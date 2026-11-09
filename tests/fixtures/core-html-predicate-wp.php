<?php
// Minimal eval-file harness using the same core parser and form runtime as
// the materializer smoke test. Production invokes the helper through WP-CLI.
require_once __DIR__ . '/core-html-api.php';
$wp_root = require __DIR__ . '/wordpress-core-root.php';
require_once __DIR__ . '/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/form-seeder-cli-stubs.php';
$args = array_slice( $argv, 4 );
require $argv[3];
