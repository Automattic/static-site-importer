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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '' ) {}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-asset-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document-metadata-reporter.php';

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
$assert( 'typed-report' === $report->get( 'theme_slug' ), 'top-level-set' );

$report->append_diagnostic( array( 'type' => 'document_metadata_routed' ) );
$assert( 1 === count( $report->diagnostics() ), 'append-diagnostic' );

$nested_write_notice = false;
set_error_handler(
	static function ( int $severity, string $message ) use ( &$nested_write_notice ): bool {
		if ( str_contains( $message, 'Indirect modification of overloaded element' ) ) {
			$nested_write_notice = true;
			return true;
		}
		return false;
	}
);
$report['quality']['fallback_count'] = 99;
restore_error_handler();
$assert( $nested_write_notice, 'nested-arrayaccess-write-warns' );
$assert( 99 !== ( $report->quality()['fallback_count'] ?? null ), 'nested-arrayaccess-write-does-not-persist' );

$report->merge_quality( array( 'fallback_count' => 2 ) );
$assert( 2 === $report->quality()['fallback_count'], 'merge-quality' );

$roundtrip = $report->to_array();
$assert( is_array( $roundtrip ), 'to-array-is-array' );
$assert( 'typed-report' === $roundtrip['theme_slug'], 'to-array-preserves-writes' );
$assert( $roundtrip === Static_Site_Importer_Import_Report::from_array( $roundtrip )->to_array(), 'from-array-roundtrip' );

$unknown_threw = false;
try {
	$report->set( 'not_a_real_top_level_key', 1 );
} catch ( InvalidArgumentException $e ) {
	$unknown_threw = str_contains( $e->getMessage(), 'not_a_real_top_level_key' );
}
$assert( $unknown_threw, 'unknown-key-throws' );

$isolated = Static_Site_Importer_Import_Report::from_array( array() );
$policy   = Static_Site_Importer_Asset_Reporter::initialize_report( $isolated, array() );
$assert( 'copy_to_theme' === $policy, 'asset-reporter-default-policy' );
$assert( 'theme' === $isolated->section( 'assets' )['policy'], 'asset-reporter-sets-policy' );
$assert( 'copy_to_theme' === $isolated->section( 'assets' )['local_policy'], 'asset-reporter-sets-local-policy' );
$assert( false === $isolated->section( 'asset_map' )['supplied'], 'asset-reporter-empty-map' );

$isolated = Static_Site_Importer_Import_Report::from_array( array() );
Static_Site_Importer_Document_Metadata_Reporter::record( $isolated, array() );
$assert( array() === $isolated->diagnostics(), 'metadata-reporter-ignores-missing-contract' );
Static_Site_Importer_Document_Metadata_Reporter::record(
	$isolated,
	array(
		'document_metadata' => array(
			'schema'      => 'blocks-engine/php-transformer/document-metadata/v1',
			'source_path' => 'website/index.html',
			'title'       => 'Home',
			'meta'        => array(),
			'links'       => array(),
			'styles'      => array(),
			'scripts'     => array(),
		),
	)
);
$assert( 'document_metadata_routed' === ( $isolated->diagnostics()[0]['type'] ?? '' ), 'metadata-reporter-appends-diagnostic' );
$assert( 'website/index.html' === ( $isolated->section( 'generated_theme' )['document_metadata']['source_path'] ?? '' ), 'metadata-reporter-stores-document-metadata' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report smoke passed (' . $assertions . " assertions)\n";
