<?php
/**
 * Typed import-report envelope coverage.
 *
 * Run from the repository root:
 * php tests/smoke-import-report.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$assert( $report instanceof Static_Site_Importer_Import_Report, 'factory-returns-typed-report' );
$assert( Static_Site_Importer_Import_Report::SCHEMA === $report['schema'], 'schema-constant' );
$assert( 'index.html' === $report['entry_file'], 'arrayaccess-read' );

$report['theme_slug'] = 'typed-report';
$assert( 'typed-report' === $report['theme_slug'], 'arrayaccess-write-known-key' );

$report['diagnostics'][] = array( 'type' => 'document_metadata_routed' );
$assert( 1 === count( $report['diagnostics'] ), 'nested-append' );

$roundtrip = $report->to_array();
$assert( is_array( $roundtrip ), 'to-array-is-array' );
$assert( 'typed-report' === $roundtrip['theme_slug'], 'to-array-preserves-writes' );
$assert( $roundtrip === Static_Site_Importer_Import_Report::from_array( $roundtrip )->to_array(), 'from-array-roundtrip' );

$unknown_threw = false;
try {
	$report['not_a_real_top_level_key'] = 1;
} catch ( InvalidArgumentException $e ) {
	$unknown_threw = str_contains( $e->getMessage(), 'not_a_real_top_level_key' );
}
$assert( $unknown_threw, 'unknown-key-throws' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report smoke passed (' . $assertions . " assertions)\n";
