<?php
/**
 * Smoke coverage for binding a producer cart view to WooCommerce's cart.
 *
 * A `generic/carts/v1` declaration resolves to the WooCommerce cart adapter
 * under the shop capability, beside (not instead of) the products adapter.
 * Each cart view's `commerce_cart` anchor is replaced with the native
 * `woocommerce/cart` block, so the imported cart page shows the provider cart
 * and the source script loses the region it rendered into. Without
 * WooCommerce the anchor keeps its source markup, and a shop provider with no
 * cart adapter leaves the optional declaration as a warning.
 *
 * Run: php tests/smoke-woo-cart-binding.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
	public function get_error_data(): mixed {
		return $this->data; }
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
function apply_filters( string $hook, mixed $value ): mixed {
	foreach ( $GLOBALS['ssi_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$anchor  = '<!-- wp:custom/layout-shell {"wrappers":[{"tagName":"section","attributes":{"data-cart-root":""}}]} --><section data-cart-root><!-- wp:paragraph --><p>Your cart is empty</p><!-- /wp:paragraph --></section><!-- /wp:custom/layout-shell -->';
$binding = array(
	'schema'                       => 'generic/block-binding/v1',
	'source_path'                  => 'cart.html',
	'search_block_markup'          => $anchor,
	'occurrence'                   => 1,
	'role'                         => 'commerce_cart',
	'superseded_runtime_selectors' => array( '[data-cart-root]' ),
);
$cart    = array(
	'source_path' => 'cart.html',
	'selector'    => 'main > section:nth-of-type(2)',
	'bindings'    => array( $binding ),
);
$cart_id = str_repeat( 'c', 64 );
$plan    = array(
	'runtime_declarations' => array(
		array(
			'kind'                    => 'dependency',
			'capability'              => 'shop',
			'reconciliation_identity' => str_repeat( 'a', 64 ),
			'required_for'            => array( 'entity_collection:products' ),
		),
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'products',
			'reconciliation_identity' => str_repeat( 'b', 64 ),
			'payload'                 => array(
				'schema'   => 'generic/products/v1',
				'entities' => array(
					array(
						'name'          => 'Alpha Item',
						'slug'          => 'alpha-item',
						'regular_price' => '10.00',
					),
				),
			),
		),
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'carts',
			'reconciliation_identity' => $cart_id,
			'payload'                 => array(
				'schema'   => 'generic/carts/v1',
				'entities' => array( $cart ),
			),
		),
	),
);

// --- 1. Adapter resolution: the cart is a shop collection, not the shop default.
$default = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop' );
$carts   = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'carts' );
$assert( 'woocommerce_simple_product' === ( $default['id'] ?? '' ), 'shop-default-adapter-stays-the-product-adapter', (string) ( $default['id'] ?? '' ) );
$assert( 'woocommerce_cart' === ( $carts['id'] ?? '' ), 'carts-collection-resolves-to-the-woo-cart-adapter', (string) ( $carts['id'] ?? '' ) );
$assert( 'woocommerce_cart' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'cart' )['id'] ?? '' ), 'cart-alias-resolves-to-the-woo-cart-adapter' );
$assert( 'woocommerce_simple_product' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'products' )['id'] ?? '' ), 'products-collection-still-resolves-to-the-product-adapter' );

$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ), 'store-with-a-cart-view-plans-cleanly', is_wp_error( $lifecycle ) ? $lifecycle->get_error_message() . ' ' . wp_json_encode( $lifecycle->get_error_data() ) : '' );
$prepared = is_wp_error( $lifecycle ) ? array() : ( $lifecycle['entities'][ $cart_id ] ?? array() );
$assert( 'woocommerce_cart' === ( $prepared['adapter']['id'] ?? '' ), 'cart-declaration-plans-through-the-woo-cart-adapter' );
$assert( 1 === count( $prepared['manifest']['carts'] ?? array() ) && 'commerce_cart' === ( $prepared['manifest']['carts'][0]['bindings'][0]['role'] ?? '' ), 'cart-manifest-keeps-its-validated-commerce-cart-anchor' );

// --- 2. Without WooCommerce the cart declines and the source markup stays.
$declined = Static_Site_Importer_Entity_Materializer_Registry::materialize( $carts, $prepared['manifest'] ?? array() );
$assert( 'skipped' === ( $declined['status'] ?? '' ) && 1 === ( $declined['counts']['skipped'] ?? 0 ), 'cart-declines-when-woocommerce-is-absent', (string) wp_json_encode( $declined ) );
$declined_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $cart_id => $prepared ) ), array( $cart_id => $declined ) );
$assert( array() === $declined_bindings, 'declined-cart-leaves-the-source-anchor-unreplaced', (string) wp_json_encode( $declined_bindings ) );

// --- 3. With WooCommerce the anchor becomes the native cart block.
if ( ! class_exists( 'WC_Cart' ) ) {
	class WC_Cart {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}
function wc_get_page_id( string $page ): int {
	return 'cart' === $page ? 7 : 0; }
$mapped = Static_Site_Importer_Entity_Materializer_Registry::materialize( $carts, $prepared['manifest'] ?? array() );
$assert( 'completed' === ( $mapped['status'] ?? '' ) && 1 === ( $mapped['counts']['mapped'] ?? 0 ), 'cart-maps-when-woocommerce-is-active', (string) wp_json_encode( $mapped ) );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $cart_id => $prepared ) ), array( $cart_id => $mapped ) );
$assert( is_array( $bindings ) && 1 === count( $bindings ), 'mapped-cart-produces-one-binding', (string) wp_json_encode( $bindings ) );
$record = is_array( $bindings ) ? ( $bindings[0] ?? array() ) : array();
$assert( str_starts_with( (string) ( $record['replacement_block_markup'] ?? '' ), '<!-- wp:woocommerce/cart -->' ) && str_ends_with( (string) ( $record['replacement_block_markup'] ?? '' ), '<!-- /wp:woocommerce/cart -->' ), 'cart-anchor-is-replaced-by-the-woocommerce-cart-block', (string) ( $record['replacement_block_markup'] ?? '' ) );
$assert( $anchor === ( $record['search_block_markup'] ?? '' ) && 'commerce_cart' === ( $record['role'] ?? '' ) && 'woocommerce' === ( $record['provider'] ?? '' ), 'cart-binding-targets-the-whole-script-region' );
$assert( array( '[data-cart-root]' ) === ( $record['superseded_runtime_selectors'] ?? array() ), 'cart-binding-retires-the-source-cart-script-lookup' );
$assert(
	array(
		'kind'    => 'shortcode',
		'content' => '[woocommerce_cart]',
	) === Static_Site_Importer_Woo_Cart_Materializer::binding_classic_render( $cart, $mapped['carts'][0] ?? array() ),
	'classic-themes-render-the-woocommerce-cart-shortcode'
);
$assert( 'rolled_back' === ( Static_Site_Importer_Woo_Cart_Materializer::rollback( $mapped )['status'] ?? '' ), 'cart-rollback-has-nothing-to-restore' );

// --- 4. The validator accepts only canonical commerce_cart anchors.
$wrong_role = $cart;
$wrong_role['bindings'][0]['role'] = 'commerce_collection';
$rejected = Static_Site_Importer_Entity_Materializer_Registry::validate_carts_manifest( array( 'carts' => array( $wrong_role, $cart ) ) );
$assert( 1 === count( $rejected['carts'] ) && '$.carts[0]' === ( $rejected['errors'][0]['path'] ?? '' ), 'validator-rejects-a-non-cart-anchor-row', (string) wp_json_encode( $rejected ) );
$unanchored = $cart;
unset( $unanchored['bindings'] );
$assert( array() === Static_Site_Importer_Entity_Materializer_Registry::validate_carts_manifest( array( 'carts' => array( $unanchored ) ) )['carts'], 'validator-rejects-a-cart-without-an-anchor' );

// --- 5. A shop provider with no cart adapter leaves the optional cart as a warning.
$GLOBALS['ssi_test_filters']['static_site_importer_entity_materializers'][] = static function ( array $adapters ): array {
	$alternate                         = $adapters['woocommerce_simple_product'];
	$alternate['id']                   = 'alternate_shop_products';
	$alternate['provider']             = 'alternate-shop';
	$alternate['rollback_contract_id'] = 'static-site-importer/alternate-shop-rollback/v1';
	$adapters[ $alternate['id'] ]      = $alternate;
	return $adapters;
};
$GLOBALS['ssi_test_filters']['ssi_shop_plugin'][] = static fn(): string => 'alternate-shop';
$alternate_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( 'alternate_shop_products' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop' )['id'] ?? '' ), 'alternate-provider-serves-the-shop-capability' );
$assert( ! is_wp_error( $alternate_lifecycle ) && ! isset( $alternate_lifecycle['entities'][ $cart_id ] ), 'alternate-provider-does-not-plan-the-cart', is_wp_error( $alternate_lifecycle ) ? $alternate_lifecycle->get_error_message() : '' );
$codes = is_wp_error( $alternate_lifecycle ) ? array() : array_column( $alternate_lifecycle['diagnostics'] ?? array(), 'code', 'reconciliation_identity' );
$assert( 'unsupported_optional_runtime_declaration' === ( $codes[ $cart_id ] ?? '' ), 'alternate-provider-reports-the-cart-as-an-unsupported-optional-declaration', (string) wp_json_encode( $codes ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Woo cart binding smoke passed (' . $assertions . " assertions).\n";
