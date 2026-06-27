<?php
/**
 * Smoke coverage for product-facing diagnostic loss classes.
 *
 * Run from the repository root:
 * php tests/smoke-diagnostic-loss-classes.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$fixtures = array(
	'native'      => array(
		'diagnostic' => array(
			'type'        => 'document_metadata_routed',
			'source_path' => 'website/index.html',
		),
		'expected'   => 'native_conversion',
	),
	'editable'    => array(
		'diagnostic' => array(
			'type'       => 'core_html_block',
			'block_name' => 'core/html',
		),
		'expected'   => 'editable_approximation',
	),
	'runtime'     => array(
		'diagnostic' => array(
			'type'   => 'interaction_candidate',
			'reason' => 'native_conversion_report_interaction_candidate',
		),
		'expected'   => 'preserved_runtime_island',
	),
	'runtime-reason-phrase' => array(
		'diagnostic' => array(
			'type'   => 'dom',
			'reason' => 'Runtime-dependent source markup was preserved as a bounded runtime island.',
		),
		'expected'   => 'preserved_runtime_island',
	),
	'unsupported' => array(
		'diagnostic' => array(
			'type' => 'content_loss_abort',
		),
		'expected'   => 'unsupported_loss',
	),
	'importer'    => array(
		'diagnostic' => array(
			'type' => 'svg_materialization_failure',
		),
		'expected'   => 'importer_materialization_bug',
	),
);

foreach ( $fixtures as $label => $fixture ) {
	$assert(
		$fixture['expected'] === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $fixture['diagnostic'] ),
		'loss-class-' . $label
	);
}

$counts = Static_Site_Importer_Diagnostic_Loss_Classes::counts( array_column( $fixtures, 'diagnostic' ) );
$assert( 1 === ( $counts['native_conversion'] ?? 0 ), 'counts-native' );
$assert( 1 === ( $counts['editable_approximation'] ?? 0 ), 'counts-editable' );
$assert( 2 === ( $counts['preserved_runtime_island'] ?? 0 ), 'counts-runtime' );
$assert( 1 === ( $counts['unsupported_loss'] ?? 0 ), 'counts-unsupported' );
$assert( 1 === ( $counts['importer_materialization_bug'] ?? 0 ), 'counts-importer' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: diagnostic loss classes smoke passed (' . $assertions . " assertions)\n";
