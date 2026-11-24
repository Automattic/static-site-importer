<?php
/**
 * Runs tools/import-loop/structure.php over a fixture site's documents with
 * WordPress Core's own parse_blocks() (pinned source fixture), and prints the
 * structure JSON the corpus records.
 *
 * Usage: php harness.php <site.json>
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI harness for a node test.
$core = require dirname( __DIR__ ) . '/wordpress-core-root.php';
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
foreach ( array( 'utf8.php', 'html-api/class-wp-html-span.php', 'class-wp-block-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php', 'blocks.php' ) as $file ) {
	require_once $core . '/wp-includes/' . $file;
}
define( 'SSI_CORPUS_STRUCTURE_LIBRARY', true );
require dirname( __DIR__, 3 ) . '/tools/import-loop/structure.php';
echo json_encode( ssi_corpus_structure( json_decode( file_get_contents( $argv[1] ), true ) ) ) . "\n";
