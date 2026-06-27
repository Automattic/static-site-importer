<?php
/**
 * Smoke test: SSI consumes Blocks Engine semantic parity diagnostics.
 *
 * Run from the repository root:
 * php tests/smoke-semantic-parity-diagnostics.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$report   = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$compiled = array(
	'schema'          => 'blocks-engine/php-transformer/result/v1',
	'semantic_parity' => array(
		'schema'   => 'blocks-engine/semantic-parity-report/v1',
		'status'   => 'failed',
		'summary'  => array(
			'source_nav_count'     => 1,
			'generated_nav_count'  => 0,
			'matched_nav_items'    => 0,
			'mismatched_nav_items' => 1,
		),
		'findings' => array(
			array(
				'type'         => 'navigation_missing',
				'source_path'  => 'index.html',
				'selector'     => 'header nav',
				'message'      => 'Source navigation exists but generated output has no core navigation/menu parity.',
				'source_label' => 'About',
				'source_url'   => '/about/',
			),
			array(
				'type'          => 'navigation_url_mismatch',
				'source_path'   => 'index.html',
				'selector'      => 'header nav a:nth-child(2)',
				'source_label'  => 'Donate',
				'generated_url' => '#',
				'source_url'    => '/donate/',
			),
			array(
				'type'        => 'main_landmark_missing',
				'source_path' => 'index.html',
				'landmark'    => 'main',
			),
		),
	),
);

Static_Site_Importer_Report_Diagnostics::record_blocks_engine_result( $report, $compiled );
$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );

$assert( isset( $report['blocks_engine']['semantic_parity'] ), 'semantic-parity-preserved' );
$assert( 3 === ( $report['blocks_engine']['semantic_parity']['finding_count'] ?? 0 ), 'semantic-parity-finding-count' );
$assert( 3 === ( $quality['semantic_parity_failure_count'] ?? 0 ), 'quality-counts-semantic-parity' );
$assert( false === ( $quality['fail_import'] ?? true ), 'default-import-does-not-fail' );
$assert( in_array( 'semantic_parity_failure', $quality['failure_reasons'] ?? array(), true ), 'quality-reason-recorded' );

$validation = $report['import_validation_result'] ?? array();
$assert( 'reported' === ( $validation['quality_gates']['semantic_parity']['status'] ?? '' ), 'validation-gate-reported' );
$assert( 3 === ( $validation['quality_gates']['semantic_parity']['count'] ?? 0 ), 'validation-gate-count' );
$assert( 3 === count( $validation['quality_gates']['semantic_parity']['diagnostic_refs'] ?? array() ), 'validation-gate-diagnostic-refs' );
$assert( 3 === count( $validation['diagnostics'] ?? array() ), 'validation-artifact-exposes-diagnostics' );
$assert( 'semantic_parity_navigation_missing' === ( $validation['diagnostics'][0]['type'] ?? '' ), 'validation-artifact-preserves-diagnostic-type' );
$assert( 'header nav' === ( $validation['diagnostics'][0]['selector'] ?? '' ), 'validation-artifact-preserves-selector' );
$assert( 3 === ( $report['finding_packets']['count'] ?? 0 ), 'finding-packets-created' );
$assert( 'blocks-engine/php-transformer' === ( $validation['quality_gates']['semantic_fidelity']['owner'] ?? '' ), 'semantic-fidelity-owner' );

$types = array_map(
	static fn ( array $diagnostic ): string => (string) ( $diagnostic['type'] ?? '' ),
	$report['diagnostics']
);
$assert( in_array( 'semantic_parity_navigation_missing', $types, true ), 'navigation-missing-diagnostic' );
$assert( in_array( 'semantic_parity_navigation_mismatch', $types, true ), 'navigation-mismatch-diagnostic' );
$assert( in_array( 'semantic_parity_landmark_missing', $types, true ), 'landmark-missing-diagnostic' );

$fail_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
Static_Site_Importer_Report_Diagnostics::record_blocks_engine_result( $fail_report, $compiled );
$fail_quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $fail_report, array( 'fail_on_quality' => true ) );
$assert( true === ( $fail_quality['fail_import'] ?? false ), 'fail-on-quality-fails' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: semantic parity diagnostics smoke passed (' . $assertions . " assertions)\n";
