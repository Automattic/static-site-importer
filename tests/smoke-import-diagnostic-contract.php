<?php
/**
 * Smoke coverage for the SSI-owned import diagnostic contract.
 *
 * Run from the repository root:
 * php tests/smoke-import-diagnostic-contract.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$diagnostics = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'status'        => 'failed',
		'success'       => false,
		'import_report' => array(
			'quality'       => array(
				'invalid_block_count'                   => 1,
				'runtime_dependency_parity_issue_count' => 1,
				'semantic_parity_failure_count'         => 1,
			),
			'diagnostics'   => array(
				array(
					'type'        => 'document_metadata_routed',
					'source_path' => 'website/index.html',
					'severity'    => 'info',
					'message'     => 'Full-document metadata/assets were routed through the generated_theme.document_metadata contract instead of generated page block content.',
				),
				array(
					'type'        => 'dropped_image_asset',
					'source_path' => 'assets/hero.jpg',
					'message'     => 'Dropped image asset.',
				),
				array(
					'type'        => 'invalid_block_content',
					'source_path' => 'templates/front-page.html',
				),
			),
			'blocks_engine' => array(
				'runtime_dependency_parity' => array(
					'missing_dom_targets' => array(
						array(
							'type'     => 'runtime_dependency_target_missing',
							'selector' => '#canvas',
						),
					),
				),
				'semantic_parity'           => array(
					'findings' => array(
						array(
							'type'        => 'navigation_missing',
							'source_path' => 'index.html',
							'selector'    => 'header nav',
						),
					),
				),
			),
		),
	)
);

$assert( 'static-site-importer/import-diagnostics/v1' === ( $diagnostics['schema'] ?? '' ), 'schema' );
$assert( 5 === ( $diagnostics['diagnostic_summary']['total'] ?? 0 ), 'total-count' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['dropped_images'] ?? 0 ), 'dropped-images-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['static_site_import_quality'] ?? 0 ), 'document-metadata-import-quality-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['invalid_block_content'] ?? 0 ), 'invalid-block-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['runtime_target_gap'] ?? 0 ), 'runtime-target-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['semantic_parity'] ?? 0 ), 'semantic-parity-bucket' );
$assert( 'static-site-importer' === ( $diagnostics['by_repair_bucket']['dropped_images'][0]['parser_owner'] ?? '' ), 'dropped-images-owner' );
$assert( 'document_metadata_routed' === ( $diagnostics['by_repair_bucket']['static_site_import_quality'][0]['type'] ?? '' ), 'document-metadata-type' );
$assert( 'blocks-engine' === ( $diagnostics['by_repair_bucket']['runtime_target_gap'][0]['parser_owner'] ?? '' ), 'runtime-target-owner' );
$assert( '#canvas' === ( $diagnostics['runtime_dependency_target_gaps'][0]['selector'] ?? '' ), 'runtime-target-selector' );
$assert( 'header nav' === ( $diagnostics['by_repair_bucket']['semantic_parity'][0]['selector'] ?? '' ), 'semantic-selector' );
$assert( array() === ( $diagnostics['artifact_refs'] ?? null ), 'no-runtime-artifact-requirement' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import diagnostic contract smoke passed (' . $assertions . " assertions)\n";
