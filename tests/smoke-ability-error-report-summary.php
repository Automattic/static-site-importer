<?php
/**
 * Smoke coverage for ability errors preserving import report summaries.
 *
 * Run from the repository root:
 * php tests/smoke-ability-error-report-summary.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook_name ): bool {
		unset( $hook_name );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		unset( $hook_name );
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, string $callback ): void {
		unset( $hook_name, $callback );
	}
}

require_once dirname( __DIR__ ) . '/includes/abilities.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$summary = array(
	'status'                => 'failed',
	'quality_pass'          => false,
	'fail_import'           => true,
	'failure_reasons'       => array( 'core_html_block' ),
	'core_html_block_count' => 1,
);

$result = static_site_importer_ability_error(
	'static_site_importer_quality_gate_failed',
	'Import failed quality gates; materialization was not completed.',
	array(
		'import_report_summary' => $summary,
		'quality'               => array( 'fail_import' => true ),
	)
);

$assert( false === ( $result['success'] ?? true ), 'ability-error-fails' );
$assert( $summary === ( $result['import_report_summary'] ?? array() ), 'preserves-import-report-summary' );
$assert( 'core_html_block' === ( $result['import_report_summary']['failure_reasons'][0] ?? '' ), 'preserves-failure-reason' );
$assert( true === ( $result['error']['data']['quality']['fail_import'] ?? false ), 'preserves-error-data' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: ability error report summary smoke passed (' . $assertions . " assertions)\n";
