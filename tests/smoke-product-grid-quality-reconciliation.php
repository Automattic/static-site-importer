<?php
/**
 * Smoke coverage for provider-materialized product-grid and commerce-controls
 * quality reconciliation.
 *
 * Since Automattic/blocks-engine#3088 a `html_product_grid_fallback` finding
 * carries no `readable_blocks`, so its reconciliation identity is the canonical
 * source page plus the grid's own source selector: exactly the evidence a
 * completed `commerce_product_grid` binding receipt carries once the binding
 * record records the grid's selector. A commerce-controls finding resolves only
 * when every control it lists sits inside such a resolved grid, and then the
 * grid's own receipt proves it. Both report resolution rows resolved or
 * unresolved, and the fallback counts stay consistent.
 *
 * Run from the repository root:
 * php tests/smoke-product-grid-quality-reconciliation.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone test shim supplies the unavailable WordPress encoder.
	}
}

require_once ABSPATH . 'includes/class-static-site-importer-form-fallback-contract.php';
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-diagnostic-projection.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$grid_selector       = 'ul.products';
$grid_source_path    = 'shop.html';
$replacement_markup  = '<!-- wp:woocommerce/product-collection --><div class="wp-block-woocommerce-product-collection">loop</div><!-- /wp:woocommerce/product-collection -->';
$persisted_content   = '<!-- wp:heading --><h2>Shop</h2><!-- /wp:heading -->' . $replacement_markup;
$page_hash           = hash( 'sha256', $persisted_content );
$materialized_hash   = hash( 'sha256', $replacement_markup );
$binding_identity    = hash( 'sha256', 'commerce-product-grid-binding' );
// The page-and-selector identity derivation is part of this contract. On the
// pre-fix codebase the derivation does not exist yet, so the receipt carries no
// joining identity and every reconciliation assertion below fails.
list( $grid_identity, $grid_fallback_hash ) = method_exists( 'Static_Site_Importer_Entity_Materializer_Registry', 'product_grid_fallback_identity' )
	? Static_Site_Importer_Entity_Materializer_Registry::product_grid_fallback_identity( $grid_source_path, $grid_selector )
	: array( '', '' );

// A grid finding exactly as Blocks Engine now emits it: page and selector
// evidence, no readable_blocks region at all.
$grid_finding = array(
	'type'               => 'unsupported_html_fallback',
	'code'               => 'html_product_grid_fallback',
	'diagnostic_code'    => 'html_product_grid_fallback',
	'kind'               => 'html_product_grid_fallback',
	'source_path'        => $grid_source_path,
	'selector'           => $grid_selector,
	'container_selector' => $grid_selector,
	'products'           => array(
		array( 'name' => 'Tour Tee', 'slug' => 'tour-tee', 'regular_price' => '30.00' ),
		array( 'name' => 'Signed CD', 'slug' => 'signed-cd', 'regular_price' => '15.00' ),
	),
);
$bound_controls_finding = array(
	'type'            => 'unsupported_html_fallback',
	'code'            => 'html_commerce_controls_fallback',
	'diagnostic_code' => 'html_commerce_controls_fallback',
	'kind'            => 'html_commerce_controls_fallback',
	'source_path'     => $grid_source_path,
	'selector'        => $grid_selector,
	'controls'        => array(
		array( 'source_selector' => $grid_selector . ' > li:nth-child(1)', 'has_cart_control' => true ),
		array( 'source_selector' => $grid_selector . ' > li:nth-child(2)', 'has_cart_control' => true ),
	),
);
$outside_controls_finding = array(
	'type'            => 'unsupported_html_fallback',
	'code'            => 'html_commerce_controls_fallback',
	'diagnostic_code' => 'html_commerce_controls_fallback',
	'kind'            => 'html_commerce_controls_fallback',
	'source_path'     => $grid_source_path,
	'selector'        => 'form.newsletter',
	'controls'        => array(
		array( 'source_selector' => 'form.newsletter > button', 'has_cart_control' => true ),
	),
);
$unbound_grid_finding = array(
	'type'               => 'unsupported_html_fallback',
	'code'               => 'html_product_grid_fallback',
	'diagnostic_code'    => 'html_product_grid_fallback',
	'kind'               => 'html_product_grid_fallback',
	'source_path'        => $grid_source_path,
	'selector'           => 'ul.featured',
	'container_selector' => 'ul.featured',
	'products'           => array(
		array( 'name' => 'Lone Item', 'slug' => 'lone-item', 'regular_price' => '9.00' ),
		array( 'name' => 'Other Item', 'slug' => 'other-item', 'regular_price' => '8.00' ),
	),
);

// The completed `commerce_product_grid` entity binding the persistence receipt
// carries once the provider loop replaced the grid: the grid's own source
// selector recorded on the binding row so the finding can join on it.
$grid_binding = array(
	'schema'                           => 'static-site-importer/runtime-entity-binding/v1',
	'status'                           => 'completed',
	'reconciliation_identity'          => $binding_identity,
	'source_path'                      => $grid_source_path,
	'selector'                         => $grid_selector,
	'role'                             => 'commerce_product_grid',
	'declaration_id'                   => 'grids-declaration',
	'fallback_reconciliation_identity' => $grid_identity,
	'fallback_hash'                    => $grid_fallback_hash,
	'materialized_block_hash'          => $materialized_hash,
	'persisted_fragment_hash'          => $materialized_hash,
	'materialized_content_hash'        => $page_hash,
	'provider'                         => 'woocommerce',
	'superseded_runtime_selectors'     => array( '[data-shop-grid]' ),
	'replaced_fallback_identities'     => array(),
);

$report = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 4 ),
		'diagnostics'             => array( $grid_finding, $bound_controls_finding, $outside_controls_finding, $unbound_grid_finding ),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array(
					$grid_source_path => array( 'content_hash' => $page_hash ),
				),
				'runtime_declarations' => array(
					'entity_bindings' => array( $grid_binding ),
				),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );

$resolutions = $report['quality_resolutions']['resolutions'] ?? array();
$assert( 4 === ( $report['quality_resolutions']['source_fallback_count'] ?? 0 ), 'grid-quality-source-fallback-counts-every-finding', (string) wp_json_encode( $report['quality_resolutions'] ?? null ) );
$assert( 2 === ( $report['quality_resolutions']['resolved_by_provider'] ?? -1 ), 'grid-quality-resolves-the-bound-grid-and-its-controls', (string) wp_json_encode( $report['quality_resolutions'] ?? null ) );
$assert( 2 === ( $report['quality_resolutions']['unresolved_fallback_count'] ?? -1 ), 'grid-quality-leaves-the-outside-controls-and-unbound-grid-unresolved', (string) wp_json_encode( $report['quality_resolutions'] ?? null ) );
$assert( 4 === count( $resolutions ), 'grid-quality-reports-every-finding-in-resolutions', (string) wp_json_encode( $resolutions ) );
$assert( 2 === ( $report['quality']['fallback_count'] ?? -1 ), 'grid-quality-fallback-count-stays-consistent', (string) wp_json_encode( $report['quality'] ?? null ) );

// Diagnostics order: grid, controls, controls, grid — the grid loop resolves
// before the controls loop, so the rows follow that order.
$bound_grid_row          = $resolutions[0] ?? array();
$unbound_grid_row        = $resolutions[1] ?? array();
$bound_controls_row      = $resolutions[2] ?? array();
$outside_controls_row    = $resolutions[3] ?? array();
$assert( 'resolved_by_provider' === ( $bound_grid_row['state'] ?? '' ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $bound_grid_row['fallback_reconciliation_identity'] ?? '' ) ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $bound_grid_row['fallback_hash'] ?? '' ) ), 'grid-quality-resolves-the-selector-scoped-grid-finding', (string) wp_json_encode( $bound_grid_row ) );
$assert( 'static-site-importer/quality-resolution-receipt/v1' === ( $bound_grid_row['receipt']['schema'] ?? '' ) && 'completed' === ( $bound_grid_row['receipt']['status'] ?? '' ) && $grid_selector === ( $bound_grid_row['receipt']['selector'] ?? '' ) && 'woocommerce' === ( $bound_grid_row['receipt']['provider'] ?? '' ) && ( $bound_grid_row['receipt']['fallback_hash'] ?? '' ) === ( $bound_grid_row['fallback_hash'] ?? '' ), 'grid-quality-resolution-carries-the-completed-grid-receipt', (string) wp_json_encode( $bound_grid_row['receipt'] ?? null ) );
$assert( 'unresolved' === ( $unbound_grid_row['state'] ?? '' ) && array() === ( $unbound_grid_row['receipt'] ?? array( 'unexpected' ) ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $unbound_grid_row['fallback_reconciliation_identity'] ?? '' ) ), 'grid-quality-leaves-a-grid-without-a-binding-unresolved', (string) wp_json_encode( $unbound_grid_row ) );
$assert( 'resolved_by_provider' === ( $bound_controls_row['state'] ?? '' ), 'grid-quality-resolves-controls-inside-the-resolved-grid', (string) wp_json_encode( $bound_controls_row ) );
$assert( ( $bound_controls_row['receipt'] ?? array() ) === ( $bound_grid_row['receipt'] ?? array() ) && ( $bound_controls_row['fallback_reconciliation_identity'] ?? '' ) === ( $bound_grid_row['fallback_reconciliation_identity'] ?? '' ) && ( $bound_controls_row['fallback_hash'] ?? '' ) === ( $bound_grid_row['fallback_hash'] ?? '' ), 'grid-quality-controls-resolution-carries-the-grid-receipt', (string) wp_json_encode( array( $bound_controls_row, $bound_grid_row ) ) );
$assert( 'unresolved' === ( $outside_controls_row['state'] ?? '' ) && array() === ( $outside_controls_row['receipt'] ?? array( 'unexpected' ) ), 'grid-quality-leaves-controls-outside-every-resolved-grid-unresolved', (string) wp_json_encode( $outside_controls_row ) );

$diagnostics = $report['diagnostics'] ?? array();
$states      = array();
foreach ( $diagnostics as $diagnostic ) {
	$states[] = array(
		'code'   => (string) ( $diagnostic['diagnostic_code'] ?? '' ),
		'state'  => (string) ( $diagnostic['fallback_resolution']['state'] ?? '' ),
		'receipt' => isset( $diagnostic['fallback_resolution']['receipt'] ) && is_array( $diagnostic['fallback_resolution']['receipt'] ) ? $diagnostic['fallback_resolution']['receipt'] : array(),
	);
}
$assert( 4 === count( $states ) && 'resolved_by_provider' === $states[0]['state'] && 'resolved_by_provider' === $states[1]['state'] && 'unresolved' === $states[2]['state'] && 'unresolved' === $states[3]['state'], 'grid-quality-marks-every-finding-resolution-on-the-diagnostic', (string) wp_json_encode( $states ) );
$assert( ( $states[1]['receipt'] ?? array() ) === ( $states[0]['receipt'] ?? array() ), 'grid-quality-marks-the-controls-diagnostic-with-its-grid-receipt', (string) wp_json_encode( array( $states[0]['receipt'] ?? null, $states[1]['receipt'] ?? null ) ) );

// A completed grid binding that cannot prove its selector cannot resolve the
// finding: the selector on the receipt is what makes the join, so a binding
// row without one (as produced before the record carried it) stays unresolved.
$selectorless_report = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 1 ),
		'diagnostics'             => array( $grid_finding ),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array(
					$grid_source_path => array( 'content_hash' => $page_hash ),
				),
				'runtime_declarations' => array(
					'entity_bindings' => array( array_diff_key( $grid_binding, array( 'selector' => '' ) ) ),
				),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $selectorless_report );
$assert( 1 === ( $selectorless_report['quality_resolutions']['unresolved_fallback_count'] ?? -1 ) && 0 === ( $selectorless_report['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 'unresolved' === ( $selectorless_report['quality_resolutions']['resolutions'][0]['state'] ?? '' ), 'grid-quality-rejects-a-binding-receipt-without-the-grid-selector', (string) wp_json_encode( $selectorless_report['quality_resolutions'] ?? null ) );

// A tampered persisted page digest leaves the grid and its controls unresolved:
// the receipt must prove the replacement actually persisted.
$tampered_report = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 2 ),
		'diagnostics'             => array( $grid_finding, $bound_controls_finding ),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array(
					$grid_source_path => array( 'content_hash' => hash( 'sha256', 'tampered persisted page' ) ),
				),
				'runtime_declarations' => array(
					'entity_bindings' => array( $grid_binding ),
				),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $tampered_report );
$assert( 2 === ( $tampered_report['quality_resolutions']['unresolved_fallback_count'] ?? -1 ) && 0 === ( $tampered_report['quality_resolutions']['resolved_by_provider'] ?? -1 ), 'grid-quality-rejects-a-grid-receipt-whose-page-digest-was-tampered', (string) wp_json_encode( $tampered_report['quality_resolutions'] ?? null ) );

// A store listing that adopted the grid replaces the grid inside its own
// region, so the grid's own binding is dropped and the listing's receipt is the
// one that must answer for the grid and the cart controls it superseded. The
// record is built by the registry exactly as materialization builds it.
$record_method = new ReflectionMethod( Static_Site_Importer_Entity_Materializer_Registry::class, 'block_binding_record' );
$listing_entity = array(
	'source_path'   => $grid_source_path,
	'selector'      => 'section.shop',
	'grid_selector' => $grid_selector,
	'controls'      => array( array( 'id' => 'sort', 'kind' => 'sort' ) ),
	'grid'          => array( 'source_path' => $grid_source_path, 'selector' => $grid_selector ),
);
$listing_binding = array(
	'schema'                       => 'generic/block-binding/v1',
	'source_path'                  => $grid_source_path,
	'search_block_markup'          => '<!-- wp:html --><section class="shop">listing</section><!-- /wp:html -->',
	'occurrence'                   => 1,
	'role'                         => 'commerce_listing',
	'superseded_runtime_selectors' => array(),
);
$listing_record = $record_method->invoke( null, 'listings-declaration', $listing_binding, $replacement_markup, $listing_entity, array( 'provider' => 'woocommerce' ) );
$assert( $grid_selector === ( $listing_record['selector'] ?? '' ) && $grid_identity === ( $listing_record['fallback_reconciliation_identity'] ?? '' ) && $materialized_hash === ( $listing_record['materialized_block_hash'] ?? '' ), 'listing-record-answers-for-its-adopted-grid', (string) wp_json_encode( $listing_record ) );
$listing_report = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 2 ),
		'diagnostics'             => array( $grid_finding, $bound_controls_finding ),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array(
					$grid_source_path => array( 'content_hash' => $page_hash ),
				),
				'runtime_declarations' => array(
					'entity_bindings' => array(
						array_merge(
							$listing_record,
							array(
								'status'                    => 'completed',
								'persisted_fragment_hash'   => $materialized_hash,
								'materialized_content_hash' => $page_hash,
							)
						),
					),
				),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $listing_report );
$assert( 2 === ( $listing_report['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 0 === ( $listing_report['quality_resolutions']['unresolved_fallback_count'] ?? -1 ), 'listing-receipt-resolves-its-adopted-grid-and-the-grid-controls', (string) wp_json_encode( $listing_report['quality_resolutions'] ?? null ) );

// A listing that adopted no grid answers for no grid finding.
$gridless_record = $record_method->invoke( null, 'listings-declaration', $listing_binding, $replacement_markup, array_diff_key( $listing_entity, array( 'grid' => true ) ), array( 'provider' => 'woocommerce' ) );
$assert( '' === ( $gridless_record['selector'] ?? 'unexpected' ) && '' === ( $gridless_record['fallback_reconciliation_identity'] ?? 'unexpected' ), 'listing-without-an-adopted-grid-answers-for-no-grid', (string) wp_json_encode( $gridless_record ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Product grid quality reconciliation smoke passed (' . $assertions . " assertions).\n";
