<?php
/**
 * WooCommerce cart for a producer-declared cart view.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Binds each `generic/carts/v1` cart view to WooCommerce's own cart.
 *
 * A source cart page is a script-owned region that renders lines and totals
 * from the source site's client storage. Its producer binding anchors on that
 * whole region, so replacing it with the provider cart both shows the real
 * WooCommerce cart and retires the source rendering. The cart is provider
 * state, so materialization writes nothing and rollback has nothing to undo.
 */
final class Static_Site_Importer_Woo_Cart_Materializer {
	public static function adapter(): array {
		return array(
			'id'                       => 'woocommerce_cart',
			'entity_type'              => 'cart',
			'entity_collection'        => 'carts',
			'rejected_rows_degrade'    => true,
			'capability'               => 'shop',
			'provider'                 => 'woocommerce',
			'label'                    => 'WooCommerce cart',
			'waiver_arg'               => 'allow_missing_woocommerce',
			'validator'                => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_carts_manifest' ),
			'materializer'             => array( self::class, 'materialize' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/woocommerce-cart-rollback/v1',
			'binding_callback'         => array( self::class, 'binding_block_markup' ),
			'classic_binding_callback' => array( self::class, 'binding_classic_render' ),
			'report_callback'          => array( self::class, 'new_report' ),
			'dependencies'             => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'woocommerce',
					'plugin_file'           => 'woocommerce/woocommerce.php',
					'availability_callback' => array( self::class, 'woocommerce_available' ),
					'missing_apis'          => array( 'WC_Cart' ),
				),
			),
		);
	}

	public static function woocommerce_available(): bool {
		return class_exists( 'WC_Cart' ) && function_exists( 'wc_get_page_id' );
	}

	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status' => $status,
			'reason' => '',
			'counts' => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'carts'  => array(),
		);
	}

	/** Map every validated cart view to the provider cart; decline them all when it is absent. */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$carts  = is_array( $manifest['carts'] ?? null ) ? array_values( array_filter( $manifest['carts'], 'is_array' ) ) : array();
		$mapped = self::woocommerce_available();
		$report = self::new_report( empty( $carts ) || ! $mapped ? 'skipped' : 'completed' );
		if ( empty( $carts ) ) {
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}
		if ( ! $mapped ) {
			$report['reason'] = 'woocommerce_inactive';
		}
		foreach ( $carts as $cart ) {
			$report['carts'][] = array(
				'source_path' => (string) ( $cart['source_path'] ?? '' ),
				'selector'    => (string) ( $cart['selector'] ?? '' ),
				'status'      => $mapped ? 'mapped' : 'skipped',
			);
			++$report['counts'][ $mapped ? 'mapped' : 'skipped' ];
		}
		return $report;
	}

	/** The cart lives in WooCommerce sessions; this adapter created nothing to restore. */
	public static function rollback( array $report ): array {
		unset( $report );
		return array( 'status' => 'rolled_back' );
	}

	/**
	 * WooCommerce's cart block. Its renderer expands the bare block into the
	 * current default inner blocks (filled cart, totals, empty cart).
	 */
	public static function binding_block_markup( array $entity, array $result ): string {
		unset( $entity );
		return 'mapped' === ( $result['status'] ?? '' ) ? '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart alignwide is-loading"></div><!-- /wp:woocommerce/cart -->' : '';
	}

	/** Classic themes render the cart through WooCommerce's cart shortcode. */
	public static function binding_classic_render( array $entity, array $result ): array {
		unset( $entity );
		return 'mapped' === ( $result['status'] ?? '' ) ? array(
			'kind'    => 'shortcode',
			'content' => '[woocommerce_cart]',
		) : array();
	}
}
