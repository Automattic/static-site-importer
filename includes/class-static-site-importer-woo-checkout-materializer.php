<?php
/**
 * WooCommerce checkout for a producer-declared checkout view.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Binds each `generic/checkouts/v1` checkout view to WooCommerce's own checkout.
 *
 * A source checkout page is a script-owned region that renders the order
 * review from the source site's client storage. Its producer binding anchors
 * on that whole region, so replacing it with the provider checkout both shows
 * the real WooCommerce checkout and retires the source rendering. The checkout
 * is provider state, so materialization writes nothing and rollback has
 * nothing to undo.
 *
 * It shares the cart materializer's commerce-view implementation — the two
 * differ only in block name, shortcode and collection key — and carries only
 * that difference.
 */
final class Static_Site_Importer_Woo_Checkout_Materializer {

	/** What identifies the script-owned checkout view collection this class binds. */
	private const SPEC = array(
		'id'                   => 'woocommerce_checkout',
		'owner'                => self::class,
		'entity_type'          => 'checkout',
		'entity_collection'    => 'checkouts',
		'label'                => 'WooCommerce checkout',
		'block'                => 'woocommerce/checkout',
		'shortcode'            => 'woocommerce_checkout',
		'missing_api'          => 'WC_Checkout',
		'validator'            => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_checkouts_manifest' ),
		'rollback_contract_id' => 'static-site-importer/woocommerce-checkout-rollback/v1',
	);

	public static function adapter(): array {
		return Static_Site_Importer_Woo_Cart_Materializer::commerce_view_adapter( self::SPEC );
	}

	public static function woocommerce_available(): bool {
		return Static_Site_Importer_Woo_Cart_Materializer::commerce_view_available( self::SPEC );
	}

	public static function new_report( string $status = 'skipped' ): array {
		return Static_Site_Importer_Woo_Cart_Materializer::commerce_view_report( self::SPEC, $status );
	}

	/** Map every validated checkout view to the provider checkout; decline them all when it is absent. */
	public static function materialize( array $manifest, array $args = array() ): array {
		return Static_Site_Importer_Woo_Cart_Materializer::commerce_view_materialize( self::SPEC, $manifest, $args );
	}

	public static function rollback( array $report ): array {
		return Static_Site_Importer_Woo_Cart_Materializer::rollback( $report );
	}

	public static function binding_block_markup( array $entity, array $result ): string {
		return Static_Site_Importer_Woo_Cart_Materializer::commerce_view_block_markup( self::SPEC, $entity, $result );
	}

	/** Classic themes render the checkout through WooCommerce's checkout shortcode. */
	public static function binding_classic_render( array $entity, array $result ): array {
		return Static_Site_Importer_Woo_Cart_Materializer::commerce_view_classic_render( self::SPEC, $entity, $result );
	}
}
