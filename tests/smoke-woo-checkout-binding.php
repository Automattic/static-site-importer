<?php
/**
 * Smoke coverage for binding a producer checkout view to WooCommerce's checkout.
 *
 * A `generic/checkouts/v1` declaration resolves to the WooCommerce checkout
 * adapter under the shop capability, beside (not instead of) the products and
 * cart adapters. Each checkout view's `commerce_checkout` anchor is replaced
 * with the native `woocommerce/checkout` block, so the imported checkout page
 * shows the provider checkout and the source script loses the region it
 * rendered into. Without WooCommerce the anchor keeps its source markup, and a
 * shop provider with no checkout adapter leaves the optional declaration as a
 * warning.
 *
 * Run: php tests/smoke-woo-checkout-binding.php
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

$anchor   = '<!-- wp:custom/layout-shell {"wrappers":[{"tagName":"section","attributes":{"data-checkout-root":""}}]} --><section data-checkout-root><!-- wp:paragraph --><p>Review your order</p><!-- /wp:paragraph --></section><!-- /wp:custom/layout-shell -->';
$binding  = array(
	'schema'                       => 'generic/block-binding/v1',
	'source_path'                  => 'checkout.html',
	'search_block_markup'          => $anchor,
	'occurrence'                   => 1,
	'role'                         => 'commerce_checkout',
	'superseded_runtime_selectors' => array( '[data-checkout-root]' ),
);
$checkout = array(
	'source_path' => 'checkout.html',
	'selector'    => 'main > section:nth-of-type(2)',
	'bindings'    => array( $binding ),
);
$checkout_id = str_repeat( 'e', 64 );
$plan        = array(
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
			'type'                    => 'checkouts',
			'reconciliation_identity' => $checkout_id,
			'payload'                 => array(
				'schema'   => 'generic/checkouts/v1',
				'entities' => array( $checkout ),
			),
		),
	),
);

// --- 1. Adapter resolution: the checkout is a shop collection, not the shop default.
$default   = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop' );
$checkouts = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'checkouts' );
$assert( 'woocommerce_simple_product' === ( $default['id'] ?? '' ), 'shop-default-adapter-stays-the-product-adapter', (string) ( $default['id'] ?? '' ) );
$assert( 'woocommerce_checkout' === ( $checkouts['id'] ?? '' ), 'checkouts-collection-resolves-to-the-woo-checkout-adapter', (string) ( $checkouts['id'] ?? '' ) );
$assert( 'woocommerce_checkout' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'checkout' )['id'] ?? '' ), 'checkout-alias-resolves-to-the-woo-checkout-adapter' );
$assert( 'woocommerce_simple_product' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'products' )['id'] ?? '' ), 'products-collection-still-resolves-to-the-product-adapter' );
$assert( 'woocommerce_cart' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'carts' )['id'] ?? '' ), 'carts-collection-still-resolves-to-the-cart-adapter' );

$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ), 'store-with-a-checkout-view-plans-cleanly', is_wp_error( $lifecycle ) ? $lifecycle->get_error_message() . ' ' . wp_json_encode( $lifecycle->get_error_data() ) : '' );
$prepared = is_wp_error( $lifecycle ) ? array() : ( $lifecycle['entities'][ $checkout_id ] ?? array() );
$assert( 'woocommerce_checkout' === ( $prepared['adapter']['id'] ?? '' ), 'checkout-declaration-plans-through-the-woo-checkout-adapter', (string) ( $prepared['adapter']['id'] ?? '' ) );
$assert( 1 === count( $prepared['manifest']['checkouts'] ?? array() ) && 'commerce_checkout' === ( $prepared['manifest']['checkouts'][0]['bindings'][0]['role'] ?? '' ), 'checkout-manifest-keeps-its-validated-commerce-checkout-anchor', (string) wp_json_encode( $prepared['manifest'] ?? array() ) );

// --- 2. Without WooCommerce the checkout declines and the source markup stays.
$declined = Static_Site_Importer_Entity_Materializer_Registry::materialize( $checkouts, $prepared['manifest'] ?? array() );
$assert( 'skipped' === ( $declined['status'] ?? '' ) && 1 === ( $declined['counts']['skipped'] ?? 0 ), 'checkout-declines-when-woocommerce-is-absent', (string) wp_json_encode( $declined ) );
$declined_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $checkout_id => $prepared ) ), array( $checkout_id => $declined ) );
$assert( array() === $declined_bindings, 'declined-checkout-leaves-the-source-anchor-unreplaced', (string) wp_json_encode( $declined_bindings ) );

// --- 3. With WooCommerce the anchor becomes the native checkout block.
if ( ! class_exists( 'WC_Checkout' ) ) {
	class WC_Checkout {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}
function wc_get_page_id( string $page ): int {
	return 'checkout' === $page ? 9 : 0; }
$mapped = Static_Site_Importer_Entity_Materializer_Registry::materialize( $checkouts, $prepared['manifest'] ?? array() );
$assert( 'completed' === ( $mapped['status'] ?? '' ) && 1 === ( $mapped['counts']['mapped'] ?? 0 ), 'checkout-maps-when-woocommerce-is-active', (string) wp_json_encode( $mapped ) );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $checkout_id => $prepared ) ), array( $checkout_id => $mapped ) );
$assert( is_array( $bindings ) && 1 === count( $bindings ), 'mapped-checkout-produces-one-binding', (string) wp_json_encode( $bindings ) );
$record = is_array( $bindings ) ? ( $bindings[0] ?? array() ) : array();
$assert( str_starts_with( (string) ( $record['replacement_block_markup'] ?? '' ), '<!-- wp:woocommerce/checkout -->' ) && str_ends_with( (string) ( $record['replacement_block_markup'] ?? '' ), '<!-- /wp:woocommerce/checkout -->' ), 'checkout-anchor-is-replaced-by-the-woocommerce-checkout-block', (string) ( $record['replacement_block_markup'] ?? '' ) );
$assert( $anchor === ( $record['search_block_markup'] ?? '' ) && 'commerce_checkout' === ( $record['role'] ?? '' ) && 'woocommerce' === ( $record['provider'] ?? '' ), 'checkout-binding-targets-the-whole-script-region' );
$assert( array( '[data-checkout-root]' ) === ( $record['superseded_runtime_selectors'] ?? array() ), 'checkout-binding-retires-the-source-checkout-script-lookup' );
$assert( 'main > section:nth-of-type(2)' === ( $record['selector'] ?? '' ) && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $record['fallback_reconciliation_identity'] ?? '' ) ) && hash( 'sha256', (string) ( $record['replacement_block_markup'] ?? '' ) ) === ( $record['materialized_block_hash'] ?? '' ), 'checkout-receipt-joins-its-finding-by-the-checkout-view-selector', (string) wp_json_encode( $record ) );
$assert(
	array(
		'kind'    => 'shortcode',
		'content' => '[woocommerce_checkout]',
	) === Static_Site_Importer_Woo_Checkout_Materializer::binding_classic_render( $checkout, $mapped['checkouts'][0] ?? array() ),
	'classic-themes-render-the-woocommerce-checkout-shortcode'
);
$assert( 'rolled_back' === ( Static_Site_Importer_Woo_Checkout_Materializer::rollback( $mapped )['status'] ?? '' ), 'checkout-rollback-has-nothing-to-restore' );

// --- 4. The validator accepts only canonical commerce_checkout anchors.
$wrong_role = $checkout;
$wrong_role['bindings'][0]['role'] = 'commerce_collection';
$rejected = Static_Site_Importer_Entity_Materializer_Registry::validate_checkouts_manifest( array( 'checkouts' => array( $wrong_role, $checkout ) ) );
$assert( 1 === count( $rejected['checkouts'] ) && '$.checkouts[0]' === ( $rejected['errors'][0]['path'] ?? '' ), 'validator-rejects-a-non-checkout-anchor-row', (string) wp_json_encode( $rejected ) );
$unanchored = $checkout;
unset( $unanchored['bindings'] );
$assert( array() === Static_Site_Importer_Entity_Materializer_Registry::validate_checkouts_manifest( array( 'checkouts' => array( $unanchored ) ) )['checkouts'], 'validator-rejects-a-checkout-without-an-anchor' );

// --- 5. A shop provider with no checkout adapter leaves the optional checkout as a warning.
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
$assert( ! is_wp_error( $alternate_lifecycle ) && ! isset( $alternate_lifecycle['entities'][ $checkout_id ] ), 'alternate-provider-does-not-plan-the-checkout', is_wp_error( $alternate_lifecycle ) ? $alternate_lifecycle->get_error_message() : '' );
$codes = is_wp_error( $alternate_lifecycle ) ? array() : array_column( $alternate_lifecycle['diagnostics'] ?? array(), 'code', 'reconciliation_identity' );
$assert( 'unsupported_optional_runtime_declaration' === ( $codes[ $checkout_id ] ?? '' ), 'alternate-provider-reports-the-checkout-as-an-unsupported-optional-declaration', (string) wp_json_encode( $codes ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Woo checkout binding smoke passed (' . $assertions . " assertions).\n";
