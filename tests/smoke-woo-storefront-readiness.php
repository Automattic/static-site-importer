<?php
/**
 * Storefront report smoke: minimal Woo API stand-ins, not a live Woo install.
 * Run: php tests/smoke-woo-storefront-readiness.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
spl_autoload_register(
	static function ( string $class ): void {
		if ( str_starts_with( $class, 'Static_Site_Importer_' ) ) {
			$path = dirname( __DIR__ ) . '/includes/class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
		}
	}
);

function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function is_wp_error( $value ): bool {
	return false;
}
function get_option( $name, $default = false ) {
	return $GLOBALS['ssi_options'][ $name ] ?? $default;
}
function get_post( $id ) {
	return $GLOBALS['ssi_pages'][ $id ] ?? null;
}
function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return $GLOBALS['ssi_woo'];
}

class SSI_Readiness_Gateway {
	public $id = 'fixture';
	public $enabled = 'yes';
	public $usable = true;
	public $throws = false;
	public $settings = array( 'testmode' => 'yes', 'secret_key' => 'NEVER-SERIALIZE' );
	public function get_option( $name, $default = null ) {
		return $this->settings[ $name ] ?? $default;
	}
	public function is_available(): bool {
		if ( $this->throws ) {
			throw new RuntimeException( 'NEVER-SERIALIZE' );
		}
		return $this->usable;
	}
}
class WC_Shipping_Zones {
	public static function get_zones(): array {
		return array( 7 => array( 'zone_id' => 7 ) );
	}
}
class WC_Shipping_Zone {
	public function __construct( private int $id ) {}
	public function get_shipping_methods( $enabled_only = false ): array {
		if ( ! $enabled_only ) {
			throw new RuntimeException( 'Expected enabled-only inspection.' );
		}
		return $this->id === $GLOBALS['ssi_shipping_zone'] ? array( (object) array( 'enabled' => 'yes' ) ) : array();
	}
}
// Woo's WC()->payment_gateways()->payment_gateways() returns registered instances.
$GLOBALS['ssi_woo'] = new class() {
	public $manager;
	public function __construct() {
		$this->manager = new class() {
			public $gateways = array();
			public function payment_gateways(): array {
				return $this->gateways;
			}
		};
	}
	public function payment_gateways() {
		return $this->manager;
	}
};
$GLOBALS['ssi_options'] = array( 'woocommerce_cart_page_id' => 11, 'woocommerce_checkout_page_id' => 12 );
$GLOBALS['ssi_pages'] = array(
	11 => (object) array( 'post_type' => 'page', 'post_status' => 'publish' ),
	12 => (object) array( 'post_type' => 'page', 'post_status' => 'publish' ),
);
$GLOBALS['ssi_shipping_zone'] = -1;
$lifecycle = array( 'entities' => array( 'products' => array( 'adapter' => array( 'provider' => 'woocommerce' ) ) ) );
$entities = array( 'products' => array( 'products' => array(
	array( 'id' => 41, 'status' => 'created' ),
	array( 'id' => 41, 'status' => 'updated' ),
	array( 'id' => 0, 'status' => 'error' ),
) ) );
$receipt = array(
	'status' => 'completed',
	'plan' => array(
		'schema' => 'blocks-engine/wordpress-site-plan/v2',
		'source' => array( 'source_hash' => 'storefront-smoke', 'provenance' => array() ),
		'pages' => array(),
		'template_parts' => array(),
		'assets' => array(),
		'writes' => array(),
	),
	'theme' => array( 'slug' => 'storefront-smoke', 'dir' => '/tmp/storefront-smoke' ),
	'completed' => array( 'runtime_declarations' => array( 'entity_bindings' => array(
		array( 'provider' => 'woocommerce', 'status' => 'completed' ),
		array( 'provider' => 'woocommerce', 'status' => 'unresolved' ),
		array( 'provider' => 'other', 'status' => 'completed' ),
	) ) ),
);
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
};
$snapshot = static function () use ( $receipt, $lifecycle, $entities, $assert ): array {
	$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array(), $lifecycle, array(), $entities, 'storefront-smoke', array(), array() );
	// Cross the actual conversion report JSON boundary, not just the inspector.
	$data = json_decode( json_encode( $projection['report']->to_array() ), true );
	$assert( isset( $data['commerce']['storefront'] ), 'conversion report exposes commerce.storefront' );
	$assert( ! str_contains( json_encode( $data ), 'NEVER-SERIALIZE' ), 'credentials and exception messages are absent' );
	return $data['commerce']['storefront'];
};
$before = json_encode( array( $GLOBALS['ssi_options'], $GLOBALS['ssi_woo'] ) );
$none = $snapshot();
$assert( $none['checkout_available'] && ! $none['payment_ready'], 'no gateway leaves checkout available but payment unready' );
$assert( 1 === $none['product_count'] && 1 === $none['binding_count'], 'unique successful products and completed Woo bindings counted' );
$assert( $none['cart']['exists'] && $none['checkout']['exists'], 'Woo options point to published pages' );
$assert( array() === $none['payment']['gateways'], 'no gateways is explicit' );
$assert( in_array( 'usable_payment_gateway', $none['missing_operator_configuration'], true ), 'operator payment configuration is missing' );
$assert( 2 === $none['shipping']['zone_count'] && ! $none['shipping']['has_enabled_method'], 'shipping counts explicit plus rest-of-world zones' );
$assert( $before === json_encode( array( $GLOBALS['ssi_options'], $GLOBALS['ssi_woo'] ) ), 'inspection changes no options or gateways' );

$gateway = new SSI_Readiness_Gateway();
$GLOBALS['ssi_woo']->manager->gateways = array( $gateway );
$GLOBALS['ssi_shipping_zone'] = 0;
$ready = $snapshot();
$assert( $ready['payment_ready'] && $ready['checkout_available'], 'test gateway is payment ready' );
$assert( array( 'id' => 'fixture', 'available' => true, 'enabled' => true, 'usable' => true, 'test_mode' => true ) === $ready['payment']['gateways'][0], 'gateway allowlist reports enabled usable test mode' );
$assert( array() === $ready['missing_operator_configuration'] && $ready['shipping']['has_enabled_method'], 'enabled rest-of-world method recognized' );
$gateway->enabled = 'no';
$assert( ! $snapshot()['payment_ready'], 'disabled gateway is not ready' );
$gateway->enabled = 'yes';
$gateway->usable = false;
$assert( ! $snapshot()['payment_ready'], 'enabled but unavailable gateway is not ready' );
$gateway->usable = true;
$gateway->throws = true;
$assert( $snapshot()['payment']['inspection_failed'] && ! $snapshot()['payment_ready'], 'gateway errors fail closed without leaking text' );
$gateway->throws = false;
$gateway->settings = array();
$assert( null === $snapshot()['payment']['gateways'][0]['test_mode'], 'unexposed mode is unknown, not live' );
$GLOBALS['ssi_pages'][12]->post_status = 'draft';
$assert( ! $snapshot()['checkout_available'], 'draft checkout is unavailable' );
$GLOBALS['ssi_pages'][12]->post_status = 'publish';
$GLOBALS['ssi_pages'][12]->post_type = 'post';
$assert( ! $snapshot()['checkout_available'], 'non-page checkout is unavailable' );
$GLOBALS['ssi_options']['woocommerce_checkout_page_id'] = 999;
$assert( ! $snapshot()['checkout_available'], 'stale option is unavailable' );
echo "PASS: storefront readiness (minimal Woo API stand-ins; no live Woo runtime).\n";
