<?php
/**
 * Smoke coverage for provider/runtime report rows.
 *
 * Run from the repository root:
 * php tests/smoke-provider-runtime-report-summary.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
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

$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
$report['form_seeding'] = array(
	'provider'     => 'jetpack',
	'status'       => 'completed',
	'form_count'   => 2,
	'mapped_count' => 1,
	'waived'       => false,
);
$report['product_finding_seeding'] = array(
	'provider'      => 'woocommerce',
	'status'        => 'completed',
	'product_count' => 2,
	'mapped_count'  => 1,
	'counts'        => array(
		'created' => 1,
		'updated' => 1,
		'skipped' => 0,
		'error'   => 0,
	),
	'waived'        => false,
);
$report['diagnostics'][] = array(
	'type'             => 'unsupported_html_fallback',
	'diagnostic_code'  => 'html_product_grid_fallback',
	'source_path'      => 'website/shop.html',
	'container_selector' => 'ul.products',
	'runtime_mapped'   => true,
	'mapped_provider'  => 'woocommerce',
	'products'         => array(
		array(
			'name'             => 'Aero Mug',
			'has_cart_control' => true,
		),
		array(
			'name'             => 'Trail Pack',
			'has_cart_control' => false,
		),
	),
);
$report['blocks_engine']['runtime_dependency_parity'] = array(
	'schema'                              => 'blocks-engine/runtime-dependency-parity/v1',
	'status'                              => 'reported',
	'missing_dom_target_count'            => 1,
	'unsupported_element_reference_count' => 1,
	'vendor_telemetry_script_count'       => 1,
);

Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
$summary = $report['compact_summary']['provider_runtime'] ?? array();
$rows    = $summary['rows'] ?? array();

$assert( 'static-site-importer/provider-runtime-summary/v1' === ( $summary['schema'] ?? '' ), 'summary-schema' );
$assert( 1 === ( $summary['forms_materialized_count'] ?? 0 ), 'summary-forms-materialized-count' );
$assert( 2 === ( $summary['woo_products_seeded_count'] ?? 0 ), 'summary-woo-products-seeded-count' );
$assert( 1 === ( $summary['cart_runtime_controls_preserved_count'] ?? 0 ), 'summary-cart-controls-preserved-count' );
$assert( 2 === ( $summary['runtime_gap_count'] ?? 0 ), 'summary-runtime-gap-count' );
$assert( 2 === ( $summary['fake_or_unsupported_count'] ?? 0 ), 'summary-fake-or-unsupported-count' );
$assert( 'provider_materialized' === ( $rows[0]['status'] ?? '' ), 'form-row-provider-materialized' );
$assert( 'jetpack' === ( $rows[0]['provider'] ?? '' ), 'form-row-provider-jetpack' );
$assert( 1 === ( $rows[0]['materialized_count'] ?? 0 ), 'form-row-materialized-count' );
$assert( 'provider_materialized' === ( $rows[1]['status'] ?? '' ), 'shop-row-provider-materialized' );
$assert( 2 === ( $rows[1]['seeded_count'] ?? 0 ), 'shop-row-seeded-count' );
$assert( 1 === ( $rows[1]['cart_runtime_controls_preserved_count'] ?? 0 ), 'shop-row-cart-controls-count' );
$assert( 'runtime_gap' === ( $rows[2]['status'] ?? '' ), 'runtime-row-gap-status' );
$assert( 1 === ( $rows[2]['missing_dom_target_count'] ?? 0 ), 'runtime-row-missing-target-count' );
$assert( 1 === ( $rows[2]['unsupported_element_count'] ?? 0 ), 'runtime-row-unsupported-count' );
$assert( 1 === ( $rows[2]['telemetry_notice_count'] ?? 0 ), 'runtime-row-telemetry-notice-count' );
$assert( $summary === ( $report['import_validation_result']['provider_runtime'] ?? array() ), 'validation-result-carries-same-summary' );

$waived_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/contact.html' );
$waived_report['form_seeding'] = array(
	'provider'     => 'jetpack',
	'status'       => 'skipped',
	'reason'       => 'jetpack_inactive',
	'available'    => false,
	'form_count'   => 1,
	'mapped_count' => 0,
	'waived'       => true,
);
$waived_report['product_finding_seeding'] = array(
	'provider'      => 'woocommerce',
	'status'        => 'skipped',
	'reason'        => 'woocommerce_inactive',
	'product_count' => 1,
	'counts'        => array( 'created' => 0, 'updated' => 0, 'skipped' => 1, 'error' => 0 ),
	'waived'        => false,
);
Static_Site_Importer_Report_Diagnostics::finalize_report( $waived_report, array() );
$waived_summary = $waived_report['compact_summary']['provider_runtime'] ?? array();
$waived_rows    = $waived_summary['rows'] ?? array();

$assert( 1 === ( $waived_summary['waived_dependency_count'] ?? 0 ), 'waived-summary-count' );
$assert( 1 === ( $waived_summary['missing_provider_count'] ?? 0 ), 'missing-provider-summary-count' );
$assert( 'waived_dependency' === ( $waived_rows[0]['status'] ?? '' ), 'form-row-waived-dependency' );
$assert( 'missing_provider' === ( $waived_rows[1]['status'] ?? '' ), 'shop-row-missing-provider' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: provider/runtime report summary smoke passed (' . $assertions . " assertions)\n";
