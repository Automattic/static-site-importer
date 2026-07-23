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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $directory ) {
		return is_dir( $directory ) || mkdir( $directory, 0777, true );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return false;
	}
}

if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
	class Static_Site_Importer_Theme_Generator {
		public static array $last_args = array();

		public static function import_website_artifact( array $artifact, array $args = array() ): array {
			self::$last_args = $args;

			return array(
				'quality'    => array( 'pass' => true ),
				'theme_slug' => 'validation-theme',
			);
		}
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
			'quality'       => array(
				'block_count'                    => 12,
				'semantic_parity_failure_count' => 1,
			),
			'blocks_engine' => array(
				'transformer'         => array(
					'package'   => 'automattic/blocks-engine-php-transformer',
					'version'   => 'dev-trunk',
					'reference' => str_repeat( 'a', 40 ),
				),
				'wordpress_site_plan' => array(
					'schema' => 'blocks-engine/wordpress-site-plan/v2',
					'assets' => array(
						array(
							'target_path'    => 'assets/app.js',
							'payload_sha256' => 'asset-hash',
							'content_base64' => str_repeat( 'x', 4096 ),
						),
					),
				),
			),
			'diagnostics'   => array(
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
		'external_report_path'    => $report_path,
		'quality'                 => array( 'pass' => false ),
		'theme_slug'              => 'ssi-fixture-theme',
		'materialization_receipt' => array(
			'schema'    => 'static-site-importer/materialization-receipt/v1',
			'status'    => 'completed',
			'plan_hash' => 'plan-hash',
			'completed' => array(
				'pages'           => array( 'index.html' => 1 ),
				'files'           => array( array( 'target_path' => 'style.css' ) ),
				'operations'      => array(),
				'declaration_ids' => array( 'runtime-app' ),
			),
		),
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
$assert( str_repeat( 'a', 40 ) === ( $result['fixture_diagnostics']['blocks_engine']['transformer']['reference'] ?? '' ), 'transformer-provenance-preserved' );
$assert( 1 === ( $result['fixture_diagnostics']['blocks_engine']['wordpress_site_plan']['asset_count'] ?? 0 ), 'site-plan-asset-count-preserved' );
$assert( ! isset( $result['fixture_diagnostics']['blocks_engine']['wordpress_site_plan']['assets'][0]['content_base64'] ), 'site-plan-asset-payload-omitted' );
$assert( 'completed' === ( $result['fixture_diagnostics']['materialization_receipt']['status'] ?? '' ), 'materialization-receipt-status-preserved' );
$assert( 1 === ( $result['fixture_diagnostics']['materialization_receipt']['page_count'] ?? 0 ), 'materialization-receipt-counts-preserved' );

$default_artifact_dir = $artifact_dir . '/default-materialization';
$default_result       = Static_Site_Importer_Validation_Runtime::validate_artifact(
	array(
		'artifact'     => array( 'schema' => 'test/website-artifact/v1' ),
		'artifact_dir' => $default_artifact_dir,
		'slug'         => 'default-materialization',
	)
);
$assert( true === ( Static_Site_Importer_Theme_Generator::$last_args['materialize_dependencies'] ?? null ), 'validation-defaults-dependency-materialization-on' );
$assert( true === ( $default_result['request']['import_args']['materialize_dependencies'] ?? null ), 'validation-result-records-default-dependency-materialization' );

$override_artifact_dir = $artifact_dir . '/disabled-materialization';
$override_result       = Static_Site_Importer_Validation_Runtime::validate_artifact(
	array(
		'artifact'                 => array( 'schema' => 'test/website-artifact/v1' ),
		'artifact_dir'             => $override_artifact_dir,
		'slug'                     => 'disabled-materialization',
		'materialize_dependencies' => false,
	)
);
$assert( false === ( Static_Site_Importer_Theme_Generator::$last_args['materialize_dependencies'] ?? null ), 'validation-honors-disabled-dependency-materialization' );
$assert( false === ( $override_result['request']['import_args']['materialize_dependencies'] ?? null ), 'validation-result-records-disabled-dependency-materialization' );

unlink( $report_path );
rmdir( $default_artifact_dir );
rmdir( $override_artifact_dir );
rmdir( $artifact_dir );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: validation runtime diagnostics smoke passed (' . $assertions . " assertions)\n";
