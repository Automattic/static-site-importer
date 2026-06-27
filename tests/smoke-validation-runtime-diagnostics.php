<?php
/**
 * Smoke coverage for validation-runtime diagnostic propagation.
 *
 * Run from the repository root:
 * php tests/smoke-validation-runtime-diagnostics.php
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

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );

		return trim( is_string( $title ) ? $title : '', '-' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-validation-runtime.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$artifact_dir = sys_get_temp_dir() . '/ssi-validation-runtime-diagnostics-' . uniqid( '', true );
mkdir( $artifact_dir, 0777, true );
$report_path = $artifact_dir . '/import-report.json';

file_put_contents(
	$report_path,
	json_encode(
		array(
			'quality'     => array(
				'semantic_parity_failure_count' => 1,
			),
			'diagnostics' => array(
				array(
					'type'        => 'semantic_parity_navigation_missing',
					'severity'    => 'warning',
					'source_path' => 'website/index.html',
					'selector'    => 'footer nav',
					'reason'      => 'Source navigation menu was not represented as a core/navigation block.',
				),
			),
		),
		JSON_PRETTY_PRINT
	)
);

$method = new ReflectionMethod( Static_Site_Importer_Validation_Runtime::class, 'result_from_import' );
$result = $method->invoke(
	null,
	array(
		'external_report_path' => $report_path,
		'quality'              => array( 'pass' => false ),
		'theme_slug'           => 'ssi-fixture-theme',
	),
	$artifact_dir,
	array(
		'slug' => 'fixture-22',
		'name' => 'Fixture 22',
	)
);

$assert( false === ( $result['success'] ?? true ), 'quality-failure-reflected' );
$assert( isset( $result['fixture_diagnostics']['diagnostics'] ), 'nested-fixture-diagnostics-present' );
$assert( 1 === count( $result['diagnostics'] ?? array() ), 'top-level-diagnostics-present' );
$assert( 'semantic_parity_navigation_missing' === ( $result['diagnostics'][0]['type'] ?? '' ), 'top-level-diagnostic-type-preserved' );
$assert( 'footer nav' === ( $result['diagnostics'][0]['selector'] ?? '' ), 'top-level-diagnostic-selector-preserved' );
$assert( 1 === ( $result['diagnostic_summary']['total'] ?? 0 ), 'top-level-diagnostic-summary-present' );

unlink( $report_path );
rmdir( $artifact_dir );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: validation runtime diagnostics smoke passed (' . $assertions . " assertions)\n";
