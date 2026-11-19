<?php
/**
 * Smoke coverage for provider-materialized product-grid, cart-view and
 * commerce-controls quality reconciliation.
 *
 * Since Automattic/blocks-engine#3088 a `html_product_grid_fallback` finding
 * carries no `readable_blocks`, so its reconciliation identity is the canonical
 * source page plus the grid's own source selector: exactly the evidence a
 * completed `commerce_product_grid` binding receipt carries once the binding
 * record records the grid's selector. A commerce-controls finding resolves only
 * when every control it lists sits inside such a resolved grid, and then the
 * grid's own receipt proves it. Both report resolution rows resolved or
 * unresolved, and the fallback counts stay consistent. A source cart drawer
 * with no binding of its own is subsumed by the import's persisted native
 * cart, and stays unresolved when there is none. The drawer's `<dialog>`
 * unsupported-element finding at the same source node resolves on that same
 * proof, and only then.
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

$grid_selector      = 'ul.products';
$grid_source_path   = 'shop.html';
$replacement_markup = '<!-- wp:woocommerce/product-collection --><div class="wp-block-woocommerce-product-collection">loop</div><!-- /wp:woocommerce/product-collection -->';
$persisted_content  = '<!-- wp:heading --><h2>Shop</h2><!-- /wp:heading -->' . $replacement_markup;
$page_hash          = hash( 'sha256', $persisted_content );
$materialized_hash  = hash( 'sha256', $replacement_markup );
$binding_identity   = hash( 'sha256', 'commerce-product-grid-binding' );
// The page-and-selector identity derivation is part of this contract. On the
// pre-fix codebase the derivation does not exist yet, so the receipt carries no
// joining identity and every reconciliation assertion below fails.
list( $grid_identity, $grid_fallback_hash ) = method_exists( 'Static_Site_Importer_Entity_Materializer_Registry', 'page_region_fallback_identity' )
	? Static_Site_Importer_Entity_Materializer_Registry::page_region_fallback_identity( 'product-grid', $grid_source_path, $grid_selector )
	: array( '', '' );

// A grid finding exactly as Blocks Engine now emits it: page and selector
// evidence, no readable_blocks region at all.
$grid_finding             = array(
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
$bound_controls_finding   = array(
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
$unbound_grid_finding     = array(
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
$bound_grid_row       = $resolutions[0] ?? array();
$unbound_grid_row     = $resolutions[1] ?? array();
$bound_controls_row   = $resolutions[2] ?? array();
$outside_controls_row = $resolutions[3] ?? array();
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
$record_method   = new ReflectionMethod( Static_Site_Importer_Entity_Materializer_Registry::class, 'block_binding_record' );
$listing_entity  = array(
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
$listing_record  = $record_method->invoke( null, 'listings-declaration', $listing_binding, $replacement_markup, $listing_entity, array( 'provider' => 'woocommerce' ) );
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

// A cart or checkout view the shop provider's own block replaced whole joins
// its finding by the view's own page and selector, exactly like a grid. The
// record is built by the registry exactly as materialization builds it.
foreach ( array(
	array( 'cart', 'commerce_cart', 'woocommerce/cart', 'html_cart_view_fallback' ),
	array( 'checkout', 'commerce_checkout', 'woocommerce/checkout', 'html_checkout_view_fallback' ),
) as list( $view, $role, $block, $code ) ) {
	$cart_selector  = 'main > section.' . $view;
	$cart_markup    = '<!-- wp:' . $block . ' --><div class="wp-block-' . str_replace( '/', '-', $block ) . ' alignwide is-loading"></div><!-- /wp:' . $block . ' -->';
	$cart_page_hash = hash( 'sha256', '<!-- wp:heading --><h2>' . $view . '</h2><!-- /wp:heading -->' . $cart_markup );
	$cart_finding   = array(
		'type'            => 'unsupported_html_fallback',
		'code'            => $code,
		'diagnostic_code' => $code,
		'source_path'     => $view . '.html',
		'selector'        => $cart_selector,
	);
	$cart_record    = $record_method->invoke(
		null,
		$view . 's-declaration',
		array(
			'schema'                       => 'generic/block-binding/v1',
			'source_path'                  => $view . '.html',
			'search_block_markup'          => '<!-- wp:html --><section class="' . $view . '">' . $view . '</section><!-- /wp:html -->',
			'occurrence'                   => 1,
			'role'                         => $role,
			'superseded_runtime_selectors' => array( '[data-' . $view . '-page]' ),
		),
		$cart_markup,
		array(
			'source_path' => $view . '.html',
			'selector'    => $cart_selector,
		),
		array( 'provider' => 'woocommerce' )
	);
	$cart_receipt   = array_merge(
		$cart_record,
		array(
			'status'                    => 'completed',
			'persisted_fragment_hash'   => hash( 'sha256', $cart_markup ),
			'materialized_content_hash' => $cart_page_hash,
		)
	);
	$assert( ( $cart_record['selector'] ?? '' ) === $cart_selector && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $cart_record['fallback_reconciliation_identity'] ?? '' ) ) && hash( 'sha256', $cart_markup ) === ( $cart_record['materialized_block_hash'] ?? '' ), $view . '-record-carries-its-view-selector', (string) wp_json_encode( $cart_record ) );
	// A grid finding at the same page and selector is a different region kind and
	// stays unresolved by the view receipt.
	$cart_grid_finding = array_merge(
		$grid_finding,
		array(
			'source_path'        => $view . '.html',
			'selector'           => $cart_selector,
			'container_selector' => $cart_selector,
		)
	);
	$cart_report       = Static_Site_Importer_Import_Report::from_array(
		array(
			'quality'                 => array( 'fallback_count' => 2 ),
			'diagnostics'             => array( $cart_finding, $cart_grid_finding ),
			'materialization_receipt' => array(
				'completed' => array(
					'materialized_pages'   => array( $view . '.html' => array( 'content_hash' => $cart_page_hash ) ),
					'runtime_declarations' => array( 'entity_bindings' => array( $cart_receipt ) ),
				),
			),
		)
	);
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $cart_report );
	$cart_rows = $cart_report['quality_resolutions']['resolutions'] ?? array();
	$assert( 1 === ( $cart_report['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 1 === ( $cart_report['quality']['fallback_count'] ?? -1 ), $view . '-receipt-resolves-its-view-finding', (string) wp_json_encode( $cart_report['quality_resolutions'] ?? null ) );
	$assert( 'resolved_by_provider' === ( $cart_rows[0]['state'] ?? '' ) && 'unresolved' === ( $cart_rows[1]['state'] ?? '' ), $view . '-receipt-answers-only-for-its-view', (string) wp_json_encode( $cart_rows ) );
}

// A cart page whose persisted digest does not match leaves the finding unresolved.
$tampered_cart = Static_Site_Importer_Import_Report::from_array(
	array(
		'quality'                 => array( 'fallback_count' => 1 ),
		'diagnostics'             => array( $cart_finding ),
		'materialization_receipt' => array(
			'completed' => array(
				'materialized_pages'   => array( 'cart.html' => array( 'content_hash' => hash( 'sha256', 'tampered cart page' ) ) ),
				'runtime_declarations' => array( 'entity_bindings' => array( $cart_receipt ) ),
			),
		),
	)
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $tampered_cart );
$assert( 0 === ( $tampered_cart['quality_resolutions']['resolved_by_provider'] ?? -1 ), 'view-quality-rejects-a-tampered-page', (string) wp_json_encode( $tampered_cart['quality_resolutions'] ?? null ) );

// A source cart drawer outside any grid (a product page's bag dialog) has no
// binding of its own. When the import persisted a native cart (a completed
// `commerce_cart` receipt whose block landed on its page), the drawer finding
// is subsumed by that cart and its row names it. With no native cart, or a
// native cart that was not persisted, it stays unresolved. A checkout view
// is never subsumed by a cart, and a checkout receipt subsumes no drawer.
$native_markup     = '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart alignwide is-loading"></div><!-- /wp:woocommerce/cart -->';
$native_page       = hash( 'sha256', '<!-- wp:heading --><h1>Cart</h1><!-- /wp:heading -->' . $native_markup );
$native_record     = $record_method->invoke(
	null,
	'carts-declaration',
	array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'website/cart.html',
		'search_block_markup' => '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group">Your bag</section><!-- /wp:group -->',
		'occurrence'          => 1,
		'role'                => 'commerce_cart',
	),
	$native_markup,
	array(
		'source_path' => 'website/cart.html',
		'selector'    => 'main > section.cart',
	),
	array( 'provider' => 'woocommerce' )
);
$native_receipt    = array_merge(
	$native_record,
	array(
		'status'                    => 'completed',
		'persisted_fragment_hash'   => hash( 'sha256', $native_markup ),
		'materialized_content_hash' => $native_page,
	)
);
$drawer_finding    = array(
	'type'            => 'unsupported_html_fallback',
	'code'            => 'html_cart_view_fallback',
	'diagnostic_code' => 'html_cart_view_fallback',
	'source_path'     => 'website/product/oak-tray.html',
	'selector'        => 'main > section > aside > div.order > dialog',
);
$drawer_report     = static function ( array $diagnostics, array $bindings, string $cart_page_hash ): Static_Site_Importer_Import_Report {
	$report = Static_Site_Importer_Import_Report::from_array(
		array(
			'quality'                 => array( 'fallback_count' => count( $diagnostics ) ),
			'diagnostics'             => $diagnostics,
			'materialization_receipt' => array(
				'completed' => array(
					'materialized_pages'   => array(
						'website/cart.html'             => array( 'content_hash' => $cart_page_hash ),
						'website/product/oak-tray.html' => array( 'content_hash' => hash( 'sha256', 'oak tray page' ) ),
					),
					'runtime_declarations' => array( 'entity_bindings' => $bindings ),
				),
			),
		)
	);
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
	return $report;
};
$drawer_rows       = static function ( Static_Site_Importer_Import_Report $report ): array {
	$rows = array();
	foreach ( $report->diagnostics() as $diagnostic ) {
		$rows[ (string) ( $diagnostic['source_path'] ?? '' ) . ' ' . (string) ( $diagnostic['code'] ?? '' ) ] = $diagnostic['fallback_resolution'] ?? array();
	}
	return $rows;
};
$cart_page_finding = array_merge( $drawer_finding, array(
	'source_path' => 'website/cart.html',
	'selector'    => 'main > section.cart',
) );
$checkout_drawer   = array_merge( $drawer_finding, array(
	'code'            => 'html_checkout_view_fallback',
	'diagnostic_code' => 'html_checkout_view_fallback',
) );

$subsumed = $drawer_report( array( $cart_page_finding, $drawer_finding, $checkout_drawer ), array( $native_receipt ), $native_page );
$rows     = $drawer_rows( $subsumed );
$drawer   = $rows['website/product/oak-tray.html html_cart_view_fallback'] ?? array();
$assert( 'resolved_by_provider' === ( $rows['website/cart.html html_cart_view_fallback']['state'] ?? '' ) && ! isset( $rows['website/cart.html html_cart_view_fallback']['subsumed_by'] ), 'native-cart-page-view-resolves-through-its-own-receipt', (string) wp_json_encode( $rows ) );
$assert( 'resolved_by_provider' === ( $drawer['state'] ?? '' ) && '' === ( $drawer['reason'] ?? 'x' ), 'cart-drawer-outside-any-grid-is-subsumed-by-the-native-cart', (string) wp_json_encode( $drawer ) );
$assert(
	array(
		'kind'                            => 'native_cart',
		'role'                            => 'commerce_cart',
		'provider'                        => 'woocommerce',
		'source_path'                     => 'website/cart.html',
		'selector'                        => 'main > section.cart',
		'binding_reconciliation_identity' => $native_record['reconciliation_identity'] ?? '',
	) === ( $drawer['subsumed_by'] ?? null ) && ( $native_receipt['fallback_reconciliation_identity'] ?? '' ) === ( $drawer['receipt']['fallback_reconciliation_identity'] ?? null ),
	'subsumed-cart-drawer-row-names-the-native-cart',
	(string) wp_json_encode( $drawer )
);
$drawer_resolution = array_values( array_filter( $subsumed['quality_resolutions']['resolutions'] ?? array(), static fn( array $row ): bool => isset( $row['subsumed_by'] ) ) );
$assert( 1 === count( $drawer_resolution ) && 'website/cart.html' === ( $drawer_resolution[0]['subsumed_by']['source_path'] ?? '' ), 'quality-resolutions-carry-the-subsumed-row', (string) wp_json_encode( $subsumed['quality_resolutions'] ?? null ) );
$assert( 'unresolved' === ( $rows['website/product/oak-tray.html html_checkout_view_fallback']['state'] ?? '' ) && 2 === ( $subsumed['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 1 === ( $subsumed['quality']['fallback_count'] ?? -1 ), 'checkout-view-is-not-subsumed-by-the-native-cart', (string) wp_json_encode( $subsumed['quality_resolutions'] ?? null ) );

$no_cart = $drawer_report( array( $drawer_finding ), array(), $native_page );
$assert( 'unresolved' === ( $drawer_rows( $no_cart )['website/product/oak-tray.html html_cart_view_fallback']['state'] ?? '' ) && 'no_provider_entity' === ( $drawer_rows( $no_cart )['website/product/oak-tray.html html_cart_view_fallback']['reason'] ?? '' ) && 1 === ( $no_cart['quality']['fallback_count'] ?? -1 ), 'cart-drawer-without-a-native-cart-stays-a-failure', (string) wp_json_encode( $no_cart['quality_resolutions'] ?? null ) );

$unpersisted = $drawer_report( array( $drawer_finding ), array( $native_receipt ), hash( 'sha256', 'cart page without the cart block' ) );
$assert( 'unresolved' === ( $drawer_rows( $unpersisted )['website/product/oak-tray.html html_cart_view_fallback']['state'] ?? '' ) && 1 === ( $unpersisted['quality']['fallback_count'] ?? -1 ), 'cart-drawer-stays-a-failure-when-the-native-cart-was-not-persisted', (string) wp_json_encode( $unpersisted['quality_resolutions'] ?? null ) );

$own_record  = $record_method->invoke(
	null,
	'carts-declaration',
	array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'website/product/oak-tray.html',
		'search_block_markup' => '<!-- wp:group {"tagName":"dialog"} --><dialog class="wp-block-group">Your bag</dialog><!-- /wp:group -->',
		'occurrence'          => 1,
		'role'                => 'commerce_cart',
	),
	$native_markup,
	array(
		'source_path' => 'website/product/oak-tray.html',
		'selector'    => $drawer_finding['selector'],
	),
	array( 'provider' => 'woocommerce' )
);
$own_receipt = array_merge(
	$own_record,
	array(
		'status'                    => 'completed',
		'persisted_fragment_hash'   => hash( 'sha256', $native_markup ),
		'materialized_content_hash' => hash( 'sha256', 'drawer page without the cart block' ),
	)
);
$own_failed  = $drawer_report( array( $drawer_finding ), array( $native_receipt, $own_receipt ), $native_page );
$own_row     = $drawer_rows( $own_failed )['website/product/oak-tray.html html_cart_view_fallback'] ?? array();
$assert( 'unresolved' === ( $own_row['state'] ?? '' ) && 'provider_replacement_not_persisted' === ( $own_row['reason'] ?? '' ) && ! isset( $own_row['subsumed_by'] ), 'cart-view-whose-own-replacement-failed-is-not-subsumed', (string) wp_json_encode( $own_row ) );

$checkout_only = $drawer_report( array( $drawer_finding ), array( array_merge( $native_receipt, array( 'role' => 'commerce_checkout' ) ) ), $native_page );
$assert( 'unresolved' === ( $drawer_rows( $checkout_only )['website/product/oak-tray.html html_cart_view_fallback']['state'] ?? '' ), 'checkout-receipt-does-not-subsume-a-cart-drawer', (string) wp_json_encode( $checkout_only['quality_resolutions'] ?? null ) );

// Blocks Engine also reports the drawer's `<dialog>` as an unsupported
// element at the same source node as the cart-view finding. When the native
// cart subsumes the drawer, that element finding resolves on the same proof
// and names the same cart. With no native cart it stays a failure, and an
// unsupported element anywhere else on the page is never resolved by it.
$dialog_finding = array(
	'type'        => 'unsupported_element',
	'code'        => 'html_unsupported_element',
	'reason_code' => 'html_unsupported_element',
	'source_path' => 'website/product/oak-tray.html',
	'selector'    => $drawer_finding['selector'],
	'tag'         => 'dialog',
);
$other_element  = array_merge( $dialog_finding, array( 'selector' => 'main > section > aside > div.order > dialog > video' ) );

$with_dialog = $drawer_report( array( $drawer_finding, $dialog_finding, $other_element ), array( $native_receipt ), $native_page );
$dialog_rows = array();
foreach ( $with_dialog->diagnostics() as $diagnostic ) {
	if ( 'html_unsupported_element' === ( $diagnostic['code'] ?? '' ) ) {
		$dialog_rows[ (string) $diagnostic['selector'] ] = $diagnostic['fallback_resolution'] ?? array();
	}
}
$dialog_row = $dialog_rows[ $drawer_finding['selector'] ] ?? array();
$assert( 'resolved_by_provider' === ( $dialog_row['state'] ?? '' ) && ( $drawer['subsumed_by'] ?? null ) === ( $dialog_row['subsumed_by'] ?? false ) && ( $native_receipt['fallback_reconciliation_identity'] ?? '' ) === ( $dialog_row['receipt']['fallback_reconciliation_identity'] ?? null ) && 'html_cart_view_fallback' === ( $dialog_row['resolved_with']['code'] ?? '' ), 'subsumed-drawer-dialog-element-resolves-on-the-native-cart', (string) wp_json_encode( $dialog_row ) );
$assert( array() === ( $dialog_rows['main > section > aside > div.order > dialog > video'] ?? null ), 'unsupported-element-elsewhere-is-not-resolved-by-the-drawer', (string) wp_json_encode( $dialog_rows ) );
$assert( 2 === ( $with_dialog['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 1 === ( $with_dialog['quality']['fallback_count'] ?? -1 ) && 1 === count( array_filter( $with_dialog['quality_resolutions']['resolutions'] ?? array(), static fn( array $row ): bool => isset( $row['resolved_with'] ) && isset( $row['subsumed_by'] ) ) ), 'subsumed-drawer-dialog-element-leaves-the-fallback-count', (string) wp_json_encode( $with_dialog['quality_resolutions'] ?? null ) );

Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $with_dialog );
$assert( 2 === ( $with_dialog['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 1 === ( $with_dialog['quality']['fallback_count'] ?? -1 ), 'subsumed-drawer-dialog-element-reconciles-idempotently', (string) wp_json_encode( $with_dialog['quality_resolutions'] ?? null ) );

$dialog_no_cart = $drawer_report( array( $drawer_finding, $dialog_finding ), array(), $native_page );
$dialog_no_row  = array();
foreach ( $dialog_no_cart->diagnostics() as $diagnostic ) {
	if ( 'html_unsupported_element' === ( $diagnostic['code'] ?? '' ) ) {
		$dialog_no_row = $diagnostic;
	}
}
$assert( ! isset( $dialog_no_row['fallback_resolution'] ) && 0 === ( $dialog_no_cart['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 2 === ( $dialog_no_cart['quality']['fallback_count'] ?? -1 ), 'drawer-dialog-element-without-a-native-cart-stays-a-failure', (string) wp_json_encode( $dialog_no_cart['quality_resolutions'] ?? null ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Product grid quality reconciliation smoke passed (' . $assertions . " assertions).\n";
