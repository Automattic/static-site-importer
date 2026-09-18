<?php
/**
 * Smoke coverage for binding a detected product grid to its seeded products.
 *
 * A `html_product_grid_fallback` finding's products are seeded as real
 * WooCommerce products through the standard shop adapter (proven by
 * tests/smoke-product-materializer.php). This file proves the *binding* half:
 * a detected grid resolves to the native `woocommerce/product-collection`
 * block instead of staying frozen source markup, while the pre-existing
 * single-product `[add_to_cart]` binding keeps working unchanged.
 *
 * Run from the repository root:
 * php tests/smoke-product-grid-binding.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}
		public function get_error_code(): string {
			return $this->code; }
		public function get_error_message(): string {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}

// The grid anchor and quality-gate reconciliation both serialize a readable
// fallback block tree through WordPress Core, exactly like the proven
// single-product-graft path already does.
$wp_root = (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' );
$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
if ( is_readable( $parser ) && is_readable( $blocks ) ) {
	require_once $parser;
	require_once $blocks;
}
if ( ! function_exists( 'serialize_blocks' ) ) {
	fwrite( STDERR, "FAIL: WordPress block serialization is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-runtime-entity-binding-validation.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// --- 1. Woo_Product_Seeder::binding_block_markup(): the grid case ----------

$grid_markup = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup(
	array(
		'entity_kind' => 'product_grid',
		'product_ids' => array( 101, 205, 7 ),
	),
	array()
);
$assert( str_starts_with( $grid_markup, '<!-- wp:woocommerce/product-collection ' ), 'grid-binding-emits-product-collection-block' );
$assert( str_contains( $grid_markup, '"woocommerceHandPickedProducts":[101,205,7]' ), 'grid-binding-hand-picks-every-resolved-id-in-order' );
$assert( str_contains( $grid_markup, '"orderBy":"post__in"' ), 'grid-binding-orders-by-post-in-to-preserve-source-order' );
$assert( str_contains( $grid_markup, '"collection":"woocommerce\/product-collection\/hand-picked"' ), 'grid-binding-uses-hand-picked-collection' );
$assert( str_contains( $grid_markup, '"columns":4' ), 'grid-binding-defaults-to-four-columns-without-a-source-layout-signal' );
$assert( str_contains( $grid_markup, '<!-- wp:woocommerce/product-template -->' ), 'grid-binding-emits-a-product-template' );
$assert( str_contains( $grid_markup, 'wp:woocommerce/product-image' ) && str_contains( $grid_markup, 'wp:woocommerce/product-price' ) && str_contains( $grid_markup, 'wp:woocommerce/product-button' ), 'grid-binding-product-template-carries-image-price-and-cart-button' );
$assert( 1 === preg_match( '/<!--\s*\/wp:woocommerce\/product-collection\s*-->\s*$/', $grid_markup ), 'grid-binding-closes-its-own-block' );

$grid_columns = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup(
	array(
		'entity_kind' => 'product_grid',
		'product_ids' => array( 9, 10, 11, 12, 13 ),
		'columns'     => 5,
	),
	array()
);
$assert( str_contains( $grid_columns, '"columns":5' ), 'grid-binding-honors-a-declared-column-count' );

$grid_no_ids = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'entity_kind' => 'product_grid', 'product_ids' => array() ), array() );
$assert( '' === $grid_no_ids, 'grid-binding-with-no-resolved-products-stays-unresolved' );

// --- 2. Negative: the single-product `[add_to_cart]` binding is unchanged --

$single_markup = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'slug' => 'aero-mug' ), array( 'id' => 42 ) );
$assert( '<!-- wp:shortcode -->[add_to_cart id="42" class="ssi-commerce-control"]<!-- /wp:shortcode -->' === $single_markup, 'single-product-binding-still-emits-the-add-to-cart-shortcode' );
$single_no_id = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'slug' => 'aero-mug' ), array() );
$assert( '' === $single_no_id, 'single-product-binding-without-a-seeded-id-stays-unresolved' );
$single_classic = Static_Site_Importer_Woo_Product_Seeder::binding_classic_render( array( 'slug' => 'aero-mug' ), array( 'id' => 42 ) );
$assert( array(
	'kind'    => 'shortcode',
	'content' => '[add_to_cart id="42" class="ssi-commerce-control"]',
) === $single_classic, 'single-product-classic-binding-still-emits-the-add-to-cart-shortcode' );

// --- 3. Product_Finding_Materializer::product_grid_binding_anchors() -------

$readable_blocks = array(
	array(
		'blockName'    => 'core/group',
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerContent' => array( '<div class="wp-block-group products">grid markup</div>' ),
	),
);
$diagnostics = array(
	array(
		'type'               => 'unsupported_html_fallback',
		'diagnostic_code'    => 'html_product_grid_fallback',
		'loss_class'         => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'        => 'website/browse.html',
		'container_selector' => 'ul.products',
		'selector'           => 'ul.products',
		'readable_blocks'    => $readable_blocks,
		'products'           => array(
			array( 'name' => 'Apex Dynamics', 'slug' => 'apex-dynamics', 'regular_price' => '49.99', 'has_cart_control' => false ),
			array( 'name' => 'Solaris Ring', 'slug' => 'solaris-ring', 'regular_price' => '64.99', 'has_cart_control' => false ),
			array( 'name' => 'Prism Wave', 'slug' => 'prism-wave', 'regular_price' => '54.99', 'has_cart_control' => false ),
		),
	),
	// A second grid with no readable_blocks proves the "no derivable anchor"
	// finding is skipped rather than guessed at.
	array(
		'type'               => 'unsupported_html_fallback',
		'diagnostic_code'    => 'html_product_grid_fallback',
		'source_path'        => 'website/home.html',
		'container_selector' => 'ul.featured',
		'products'           => array(
			array( 'name' => 'Lone Item', 'slug' => 'lone-item', 'regular_price' => '9.99', 'has_cart_control' => false ),
		),
	),
);

$expected_region = serialize_blocks( $readable_blocks );
$anchors          = Static_Site_Importer_Report_Diagnostics::product_grid_binding_anchors( $diagnostics );
$assert( 3 === count( $anchors ), 'grid-anchors-cover-every-product-in-the-anchored-grid' );
foreach ( array( 'apex-dynamics', 'solaris-ring', 'prism-wave' ) as $slug ) {
	$assert( isset( $anchors[ $slug ] ), 'grid-anchors-key-by-manifest-slug-' . $slug );
	$assert( 'website/browse.html' === ( $anchors[ $slug ]['source_path'] ?? '' ), 'grid-anchors-carry-the-finding-source-path-' . $slug );
	$assert( $expected_region === ( $anchors[ $slug ]['search_block_markup'] ?? '' ), 'grid-anchors-carry-the-serialized-readable-region-' . $slug );
}
$assert( ! isset( $anchors['lone-item'] ), 'grid-anchors-skip-a-finding-with-no-readable-blocks-rather-than-guessing' );

// --- 4. Theme_Generator::attach_product_grid_bindings_to_runtime_declarations

$attach = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'attach_product_grid_bindings_to_runtime_declarations' );
$plan   = array(
	'diagnostics'           => $diagnostics,
	'runtime_declarations'  => array(
		array( 'kind' => 'dependency', 'capability' => 'shop', 'source_path' => 'website/browse.html', 'required_for' => array( 'entity_collection:products' ) ),
		array(
			'kind'        => 'entity_collection',
			'type'        => 'products',
			'source_path' => 'website/browse.html',
			'payload'     => array(
				'schema'   => 'generic/products/v1',
				'entities' => array(
					array( 'name' => 'Apex Dynamics', 'slug' => 'apex-dynamics', 'regular_price' => '49.99', 'source_path' => 'website/browse.html', 'selector' => 'li:nth-child(1)' ),
					array( 'name' => 'Solaris Ring', 'slug' => 'solaris-ring', 'regular_price' => '64.99', 'source_path' => 'website/browse.html', 'selector' => 'li:nth-child(2)' ),
					array( 'name' => 'Prism Wave', 'slug' => 'prism-wave', 'regular_price' => '54.99', 'source_path' => 'website/browse.html', 'selector' => 'li:nth-child(3)' ),
					// Already bound by the compiler: must be left untouched.
					array( 'name' => 'Already Bound', 'slug' => 'already-bound', 'regular_price' => '1.00', 'bindings' => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/browse.html', 'search_block_markup' => 'x', 'occurrence' => 1, 'role' => 'commerce_controls' ) ) ),
					array( 'name' => 'Lone Item', 'slug' => 'lone-item', 'regular_price' => '9.99', 'source_path' => 'website/home.html', 'selector' => 'li:nth-child(1)' ),
				),
			),
		),
	),
);
$bridged_plan  = $attach->invoke( null, $plan );
$bridged_products = $bridged_plan['runtime_declarations'][1]['payload']['entities'];
$by_slug           = array();
foreach ( $bridged_products as $product ) {
	$by_slug[ $product['slug'] ] = $product;
}
foreach ( array( 'apex-dynamics', 'solaris-ring', 'prism-wave' ) as $slug ) {
	$binding = $by_slug[ $slug ]['bindings'][0] ?? null;
	$assert( is_array( $binding ) && 'commerce_collection' === ( $binding['role'] ?? '' ), 'bridge-attaches-a-commerce-collection-binding-' . $slug );
	$assert( is_array( $binding ) && $expected_region === ( $binding['search_block_markup'] ?? '' ), 'bridge-binding-anchor-matches-the-finding-region-' . $slug );
	$assert( is_array( $binding ) && 'website/browse.html' === ( $binding['source_path'] ?? '' ), 'bridge-binding-anchor-carries-the-finding-source-path-' . $slug );
	$assert( is_array( $binding ) && 1 === ( $binding['occurrence'] ?? 0 ), 'bridge-binding-anchor-occurrence-is-one-' . $slug );
}
$assert( array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/browse.html', 'search_block_markup' => 'x', 'occurrence' => 1, 'role' => 'commerce_controls' ) ) === $by_slug['already-bound']['bindings'], 'bridge-leaves-a-compiler-bound-declaration-untouched' );
$assert( ! isset( $by_slug['lone-item']['bindings'] ), 'bridge-leaves-an-unanchorable-declaration-unbound' );

// --- 5. validate_woo_products_manifest() accepts the new shared-anchor role

$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_woo_products_manifest(
	array(
		'schema_version' => 1,
		'products'       => array(
			array(
				'name'          => 'Apex Dynamics',
				'slug'          => 'apex-dynamics',
				'regular_price' => '49.99',
				'bindings'      => array(
					array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/browse.html', 'search_block_markup' => $expected_region, 'occurrence' => 1, 'role' => 'commerce_collection' ),
				),
			),
		),
	)
);
$assert( empty( $validated['errors'] ), 'manifest-validator-accepts-commerce-collection-role', (string) wp_json_encode( $validated['errors'] ?? array() ) );
$assert( 'commerce_collection' === ( $validated['products'][0]['bindings'][0]['role'] ?? '' ), 'manifest-validator-preserves-commerce-collection-role' );

// --- 6. Entity_Materializer_Registry::block_bindings(): shared-anchor coalescing

$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
$grid_binding = array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/browse.html', 'search_block_markup' => $expected_region, 'occurrence' => 1, 'role' => 'commerce_collection' );
$manifest_entities = array(
	array( 'name' => 'Apex Dynamics', 'slug' => 'apex-dynamics', 'regular_price' => '49.99', 'bindings' => array( $grid_binding ) ),
	array( 'name' => 'Solaris Ring', 'slug' => 'solaris-ring', 'regular_price' => '64.99', 'bindings' => array( $grid_binding ) ),
	// Declined (not seeded): must be excluded from the resolved id list without failing the group.
	array( 'name' => 'Declined Item', 'slug' => 'declined-item', 'regular_price' => '19.99', 'bindings' => array( $grid_binding ) ),
	// A distinct single-product page binding on the same declaration must keep resolving on its own.
	array( 'name' => 'Solo Product', 'slug' => 'solo-product', 'regular_price' => '9.99', 'bindings' => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/solo.html', 'search_block_markup' => '<!-- wp:shortcode -->[[SSI_SOLO_PRODUCT]]<!-- /wp:shortcode -->', 'occurrence' => 1, 'role' => 'commerce_controls' ) ) ),
);
$lifecycle = array(
	'entities' => array(
		'products-declaration' => array(
			'adapter'  => $adapter,
			'manifest' => array( 'products' => $manifest_entities ),
		),
	),
);
$reports = array(
	'products-declaration' => array(
		'products' => array(
			array( 'slug' => 'apex-dynamics', 'id' => 201, 'status' => 'created' ),
			array( 'slug' => 'solaris-ring', 'id' => 202, 'status' => 'created' ),
			array( 'slug' => 'declined-item', 'status' => 'skipped', 'reason' => 'woocommerce_inactive' ),
			array( 'slug' => 'solo-product', 'id' => 303, 'status' => 'created' ),
		),
	),
);
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $reports );
$assert( ! is_wp_error( $bindings ), 'coalesced-block-bindings-do-not-error', is_wp_error( $bindings ) ? $bindings->get_error_message() : '' );
$assert( is_array( $bindings ) && 2 === count( $bindings ), 'coalesced-block-bindings-produce-one-grid-binding-and-one-single-binding' );
$grid_result   = null;
$solo_result   = null;
foreach ( $bindings as $binding ) {
	if ( 'website/browse.html' === ( $binding['source_path'] ?? '' ) ) {
		$grid_result = $binding;
	} elseif ( 'website/solo.html' === ( $binding['source_path'] ?? '' ) ) {
		$solo_result = $binding;
	}
}
$assert( null !== $grid_result, 'coalesced-block-bindings-include-the-shared-grid-anchor' );
$assert( is_array( $grid_result ) && str_contains( (string) ( $grid_result['replacement_block_markup'] ?? '' ), '"woocommerceHandPickedProducts":[201,202]' ), 'coalesced-block-bindings-hand-pick-every-seeded-member-and-skip-the-declined-one' );
$assert( is_array( $grid_result ) && 'commerce_collection' === ( $grid_result['role'] ?? '' ), 'coalesced-block-bindings-keep-the-commerce-collection-role' );
$assert( is_array( $grid_result ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $grid_result['fallback_reconciliation_identity'] ?? '' ) ), 'coalesced-block-bindings-derive-a-fallback-reconciliation-identity' );
$assert( is_array( $grid_result ) && hash( 'sha256', $expected_region ) === ( $grid_result['fallback_hash'] ?? '' ), 'coalesced-block-bindings-fallback-hash-matches-the-shared-anchor' );
$assert( null !== $solo_result && '<!-- wp:shortcode -->[add_to_cart id="303" class="ssi-commerce-control"]<!-- /wp:shortcode -->' === ( $solo_result['replacement_block_markup'] ?? '' ), 'coalesced-block-bindings-leave-an-unrelated-single-product-binding-unchanged' );

// Every member declined leaves nothing to bind for that shared anchor at all,
// exactly like one declined single-product entity already leaves its own
// anchor untouched today (a declined row is a deliberate provider decision,
// not a materialization failure).
$all_declined_reports = array(
	'products-declaration' => array(
		'products' => array(
			array( 'slug' => 'apex-dynamics', 'status' => 'skipped', 'reason' => 'woocommerce_inactive' ),
			array( 'slug' => 'solaris-ring', 'status' => 'skipped', 'reason' => 'woocommerce_inactive' ),
			array( 'slug' => 'declined-item', 'status' => 'skipped', 'reason' => 'woocommerce_inactive' ),
			array( 'slug' => 'solo-product', 'id' => 303, 'status' => 'created' ),
		),
	),
);
$all_declined_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $all_declined_reports );
$assert( ! is_wp_error( $all_declined_bindings ) && 1 === count( $all_declined_bindings ) && 'website/solo.html' === ( $all_declined_bindings[0]['source_path'] ?? '' ), 'coalesced-block-bindings-drop-a-shared-anchor-whose-every-member-is-declined' );

// --- 7. preflight_runtime_entity_binding_anchors(): shared-claim allowance -

$shared_page = array(
	'source_path'            => 'website/browse.html',
	'resolved_block_markup'  => $expected_region,
	'skip_materialization'   => false,
);
$preflight_plan = array( 'pages' => array( $shared_page ) );
$preflight_lifecycle_ok = array(
	'entities' => array(
		'products-declaration' => array(
			'adapter'  => array( 'waiver_arg' => '' ),
			'manifest' => array( 'products' => array( $manifest_entities[0], $manifest_entities[1], $manifest_entities[2] ) ),
		),
	),
);
$preflight_ok = Static_Site_Importer_Runtime_Entity_Binding_Validation::preflight_runtime_entity_binding_anchors( $preflight_plan, $preflight_lifecycle_ok, array() );
$assert( true === $preflight_ok, 'preflight-allows-multiple-products-to-share-one-commerce-collection-claim' );

$non_collection_binding = array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'website/browse.html', 'search_block_markup' => $expected_region, 'occurrence' => 1, 'role' => 'commerce_controls' );
$preflight_lifecycle_conflict = array(
	'entities' => array(
		'products-declaration' => array(
			'adapter'  => array( 'waiver_arg' => '' ),
			'manifest' => array(
				'products' => array(
					array( 'name' => 'A', 'slug' => 'a', 'regular_price' => '1.00', 'bindings' => array( $non_collection_binding ) ),
					array( 'name' => 'B', 'slug' => 'b', 'regular_price' => '1.00', 'bindings' => array( $non_collection_binding ) ),
				),
			),
		),
	),
);
$preflight_conflict = Static_Site_Importer_Runtime_Entity_Binding_Validation::preflight_runtime_entity_binding_anchors( $preflight_plan, $preflight_lifecycle_conflict, array() );
$assert( is_wp_error( $preflight_conflict ) && 'static_site_importer_runtime_binding_claim_conflict' === $preflight_conflict->get_error_code(), 'preflight-still-rejects-a-duplicate-claim-for-every-non-grid-role' );

// --- 8. Quality_Gates reconciliation: unresolved_fallback_count reaches 0 --

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-quality-gates.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';

$grid_diagnostic = $diagnostics[0];
$report          = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/browse.html' );
$report->append_diagnostic( $grid_diagnostic );
$report->merge_quality( array( 'fallback_count' => 1 ) );
$report['materialization_receipt'] = array(
	'completed' => array(
		'runtime_declarations' => array(
			'entity_bindings' => array(
				array_merge(
					$grid_result,
					array(
						'status'                   => 'completed',
						'persisted_fragment_hash'  => hash( 'sha256', (string) $grid_result['replacement_block_markup'] ),
						'materialized_content_hash' => hash( 'sha256', 'materialized-page-content-with-the-grid-replacement' ),
					)
				),
			),
		),
		'materialized_pages'    => array(
			'website/browse.html' => array( 'content_hash' => hash( 'sha256', 'materialized-page-content-with-the-grid-replacement' ) ),
		),
	),
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
$resolutions = $report['quality_resolutions'];
$assert( 1 === ( $resolutions['source_fallback_count'] ?? 0 ), 'quality-reconciliation-counts-the-one-detected-grid-fallback' );
$assert( 1 === ( $resolutions['resolved_by_provider'] ?? 0 ), 'quality-reconciliation-resolves-the-grid-fallback-with-a-completed-receipt' );
$assert( 0 === ( $resolutions['unresolved_fallback_count'] ?? -1 ), 'quality-reconciliation-unresolved-fallback-count-reaches-zero' );
$assert( 0 === ( $report->quality()['fallback_count'] ?? -1 ), 'quality-reconciliation-decrements-fallback-count-to-zero' );

// Negative: no completed receipt at all leaves the grid fallback unresolved.
$unresolved_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/browse.html' );
$unresolved_report->append_diagnostic( $grid_diagnostic );
$unresolved_report->merge_quality( array( 'fallback_count' => 1 ) );
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $unresolved_report );
$unresolved_resolutions = $unresolved_report['quality_resolutions'];
$assert( 0 === ( $unresolved_resolutions['resolved_by_provider'] ?? -1 ) && 1 === ( $unresolved_resolutions['unresolved_fallback_count'] ?? 0 ), 'quality-reconciliation-leaves-an-unbound-grid-fallback-unresolved' );

// A finding with no derivable anchor at all (no readable_blocks) never claims
// a resolution it cannot prove.
$no_anchor_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/home.html' );
$no_anchor_report->append_diagnostic( $diagnostics[1] );
$no_anchor_report->merge_quality( array( 'fallback_count' => 1 ) );
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $no_anchor_report );
$no_anchor_resolutions = $no_anchor_report['quality_resolutions'];
$assert( array() === ( $no_anchor_resolutions['resolutions'] ?? array( 'unexpected' ) ), 'quality-reconciliation-skips-a-finding-with-no-derivable-anchor' );
$assert( 1 === ( $no_anchor_report->quality()['fallback_count'] ?? 0 ), 'quality-reconciliation-leaves-fallback-count-unchanged-without-a-derivable-anchor' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: product grid binding smoke passed (' . $assertions . " assertions)\n";
