<?php
/** Run: php tests/smoke-materialization-coverage.php */

// A bounded WordPress-free shim. The registry and dependency manager already
// guard every WordPress call with function_exists, so only the pieces this
// declaration actually reads are provided, and each one is steerable so the
// test can move a provider or a plugin without touching the code under test.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_available_plugins'] = array();
$GLOBALS['ssi_provider_override'] = array();

function get_option( string $name, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions
	$overrides = array( 'static_site_importer_form_plugin' => 'form', 'static_site_importer_shop_plugin' => 'shop' );
	$capability = $overrides[ $name ] ?? '';
	return '' !== $capability && isset( $GLOBALS['ssi_provider_override'][ $capability ] ) ? $GLOBALS['ssi_provider_override'][ $capability ] : $default_value;
}

function wp_json_encode( $data, int $flags = 0 ) { // phpcs:ignore WordPress.NamingConventions
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-dependency-manager.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-materialization-coverage.php';

// The adapters name their availability callbacks; satisfy them from the shim.
if ( ! class_exists( 'Static_Site_Importer_Form_Seeder' ) ) {
	class Static_Site_Importer_Form_Seeder {
		public static function adapter(): array {
			return array(
				'id'                   => 'jetpack_contact_form',
				'entity_type'          => 'form',
				'entity_collection'    => 'forms',
				'capability'           => 'form',
				'provider'             => 'jetpack',
				'rollback_contract_id' => 'static-site-importer/jetpack-form-rollback/v1',
				'dependencies'         => array(
					array(
						'type'                  => 'wp_org_plugin',
						'slug'                  => 'jetpack',
						'plugin_file'           => 'jetpack/jetpack.php',
						'availability_callback' => array( self::class, 'jetpack_forms_available' ),
					),
				),
			);
		}
		public static function jetpack_forms_available(): bool {
			return in_array( 'jetpack', $GLOBALS['ssi_available_plugins'], true );
		}
		public static function required_block_types(): array {
			return array();
		}
		public static function required_runtime_apis(): array {
			return array();
		}
	}
}
if ( ! class_exists( 'Static_Site_Importer_Woo_Product_Seeder' ) ) {
	class Static_Site_Importer_Woo_Product_Seeder {
		public static function adapter(): array {
			return array(
				'id'                   => 'woocommerce_simple_product',
				'entity_type'          => 'product',
				'entity_collection'    => 'products',
				'capability'           => 'shop',
				'provider'             => 'woocommerce',
				'rollback_contract_id' => 'static-site-importer/woocommerce-product-rollback/v1',
				'dependencies'         => array(
					array(
						'type'                  => 'wp_org_plugin',
						'slug'                  => 'woocommerce',
						'plugin_file'           => 'woocommerce/woocommerce.php',
						'availability_callback' => array( self::class, 'woocommerce_available' ),
					),
				),
			);
		}
		public static function woocommerce_available(): bool {
			return in_array( 'woocommerce', $GLOBALS['ssi_available_plugins'], true );
		}
	}
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$coverage = Static_Site_Importer_Materialization_Coverage::declare_coverage();
$assert( Static_Site_Importer_Materialization_Coverage::SCHEMA === $coverage['schema'], 'coverage carries its versioned schema' );
$assert( array( 'form', 'shop' ) === array_keys( $coverage['capabilities'] ), 'coverage answers for every registered capability and invents none' );

// The importer describes its own runtime. It must not borrow a capture
// producer's vocabulary, because a destination that does stops accepting
// artifacts from any other producer.
$serialized = (string) wp_json_encode( $coverage );
foreach ( array( 'data-liberation', 'source-capability', 'booking', 'membership', 'embeds', 'dialogs', 'navigation' ) as $foreign ) {
	$assert( ! str_contains( $serialized, $foreign ), "coverage stays free of the foreign term {$foreign}" );
}

$form = $coverage['capabilities']['form'];
$assert( 'jetpack' === $form['provider'] && 'default' === $form['selected_from'], 'the form capability reports its default provider selection' );
$assert( 'provider_unavailable' === $form['status'] && 'dependencies_unavailable' === $form['reason'], 'an absent provider is reported as unavailable rather than native' );
$assert( 'jetpack_contact_form' === $form['adapter'] && 'form' === $form['entity_type'] && 'forms' === $form['entity_collection'], 'coverage names the adapter and the entity it materializes' );
$assert( array( array( 'type' => 'wp_org_plugin', 'slug' => 'jetpack', 'available' => false ) ) === $form['dependencies'], 'each declared dependency reports its own availability' );
$assert( array() === $coverage['native'], 'nothing is native while its provider is missing' );

// A satisfied dependency is the only thing that makes a capability native.
$GLOBALS['ssi_available_plugins'] = array( 'jetpack' );
$available                        = Static_Site_Importer_Materialization_Coverage::declare_coverage();
$assert( 'native' === $available['capabilities']['form']['status'] && '' === $available['capabilities']['form']['reason'], 'a satisfied dependency makes the capability native' );
$assert( array( 'form' ) === $available['native'], 'the native list names exactly the capabilities this runtime can materialize' );
$assert( 'provider_unavailable' === $available['capabilities']['shop']['status'], 'one satisfied provider does not vouch for another' );
$GLOBALS['ssi_available_plugins'] = array();

// A configured provider is never routed to a different adapter, so an
// unserviceable selection is a coverage answer rather than a silent fallback.
$GLOBALS['ssi_provider_override'] = array( 'form' => 'gravity-forms' );
$overridden                       = Static_Site_Importer_Materialization_Coverage::declare_coverage();
$assert( 'gravity-forms' === $overridden['capabilities']['form']['provider'] && 'configured' === $overridden['capabilities']['form']['selected_from'], 'an overridden provider is reported as configured' );
$assert( 'unsupported' === $overridden['capabilities']['form']['status'] && 'provider_has_no_adapter' === $overridden['capabilities']['form']['reason'], 'a provider with no adapter is unsupported, not quietly rerouted to the default' );
$assert( ! isset( $overridden['capabilities']['form']['adapter'] ), 'an unserviceable selection names no adapter' );
$GLOBALS['ssi_provider_override'] = array();

$unknown = Static_Site_Importer_Materialization_Coverage::capability_coverage( 'booking' );
$assert( 'unsupported' === $unknown['status'] && 'capability_not_registered' === $unknown['reason'], 'a capability this importer does not register is answered, not guessed' );

print "materialization coverage smoke passed\n";
