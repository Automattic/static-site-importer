<?php
/**
 * Inspect-only WooCommerce storefront report projection.
 *
 * @package StaticSiteImporter
 */

final class Static_Site_Importer_Woo_Storefront_Readiness {
	/** Build a snapshot only for imports with a Woo adapter. */
	public static function project( array $lifecycle, array $entities, array $receipt ): array {
		$woo_ids = array();
		foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
			if ( 'woocommerce' === ( $prepared['adapter']['provider'] ?? '' ) ) {
				$woo_ids[] = $id;
			}
		}
		if ( empty( $woo_ids ) ) {
			return array();
		}
		$products = array();
		foreach ( $woo_ids as $id ) {
			foreach ( $entities[ $id ]['products'] ?? array() as $product ) {
				if ( in_array( $product['status'] ?? '', array( 'created', 'updated' ), true ) && ! empty( $product['id'] ) ) {
					$products[ (int) $product['id'] ] = true;
				}
			}
		}
		$bindings  = 0;
		$completed = $receipt['completed']['runtime_declarations'] ?? array();
		foreach ( array_merge( $completed['entity_bindings'] ?? array(), $completed['classic_html_bindings'] ?? array() ) as $binding ) {
			if ( 'woocommerce' === ( $binding['provider'] ?? '' ) && 'completed' === ( $binding['status'] ?? '' ) ) {
				++$bindings;
			}
		}
		$cart     = self::page( 'cart' );
		$checkout = self::page( 'checkout' );
		$payment  = self::payment();
		$shipping = self::shipping();
		$missing  = array();
		if ( ! $cart['exists'] ) {
			$missing[] = 'cart_page';
		}
		if ( ! $checkout['exists'] ) {
			$missing[] = 'checkout_page';
		}
		if ( ! $payment['ready'] ) {
			$missing[] = 'usable_payment_gateway';
		}
		if ( ! $shipping['has_enabled_method'] ) {
			$missing[] = 'shipping_method';
		}
		return array(
			'schema'                         => 'static-site-importer/commerce-storefront/v1',
			'product_count'                  => count( $products ),
			'binding_count'                  => $bindings,
			'cart'                           => $cart,
			'checkout'                       => $checkout,
			'checkout_available'             => $checkout['exists'],
			'payment_ready'                  => $payment['ready'],
			'payment'                        => $payment,
			'shipping'                       => $shipping,
			'missing_operator_configuration' => $missing,
		);
	}

	/** Woo page options must point to published WordPress pages. */
	private static function page( string $name ): array {
		$id   = function_exists( 'get_option' ) ? (int) get_option( 'woocommerce_' . $name . '_page_id', 0 ) : 0;
		$post = $id > 0 && function_exists( 'get_post' ) ? get_post( $id ) : null;
		return array(
			'page_id' => max( 0, $id ),
			'exists'  => $post && 'page' === $post->post_type && 'publish' === $post->post_status,
		);
	}

	/** Never serialize gateway objects, settings, credentials, or exception text. */
	private static function payment(): array {
		$result = array(
			'api_available'     => function_exists( 'WC' ),
			'gateways'          => array(),
			'ready'             => false,
			'inspection_failed' => false,
		);
		if ( ! $result['api_available'] ) {
			return $result;
		}
		try {
			$manager = WC()->payment_gateways();
			foreach ( $manager->payment_gateways() as $gateway ) {
				$enabled = 'yes' === $gateway->enabled;
				$usable  = false;
				try {
					$usable = $enabled && (bool) $gateway->is_available();
				} catch ( Throwable $error ) {
					// Some providers require a frontend session. Do not create one.
					$result['inspection_failed'] = true;
				}
				$mode                 = $gateway->get_option( 'testmode', null ) ?? ( $gateway->testmode ?? null );
				$result['gateways'][] = array(
					'id'        => (string) $gateway->id,
					'available' => true,
					'enabled'   => $enabled,
					'usable'    => $usable,
					'test_mode' => in_array( $mode, array( 'yes', 'no', true, false ), true ) ? in_array( $mode, array( 'yes', true ), true ) : null,
				);
				$result['ready']      = $result['ready'] || $usable;
			}
		} catch ( Throwable $error ) {
			$result['inspection_failed'] = true;
		}
		return $result;
	}

	/** Include Woo's implicit rest-of-world zone (0), without loading locations. */
	private static function shipping(): array {
		$result = array(
			'api_available'      => class_exists( 'WC_Shipping_Zones' ) && class_exists( 'WC_Shipping_Zone' ),
			'zone_count'         => 0,
			'has_enabled_method' => false,
			'inspection_failed'  => false,
		);
		if ( ! $result['api_available'] ) {
			return $result;
		}
		try {
			$zones                = WC_Shipping_Zones::get_zones();
			$result['zone_count'] = count( $zones ) + 1;
			foreach ( array_merge( array( 0 ), array_keys( $zones ) ) as $id ) {
				$zone = new WC_Shipping_Zone( (int) $id );
				if ( ! empty( $zone->get_shipping_methods( true ) ) ) {
					$result['has_enabled_method'] = true;
					break;
				}
			}
		} catch ( Throwable $error ) {
			$result['inspection_failed'] = true;
		}
		return $result;
	}
}
