<?php
/**
 * WooCommerce order tracking for a producer-declared order-lookup form.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Binds each `generic/order-lookups/v1` order-lookup form to WooCommerce's own order tracking.
 *
 * An order-lookup form is a script-owned order number + checkout email + submit
 * form the producer declares for the shop capability instead of a generic form.
 * WooCommerce ships no order-tracking block, but its `[woocommerce_order_tracking]`
 * shortcode renders exactly those two inputs, so the entity's binding anchor is
 * replaced with the shortcode block and classic themes render through the same
 * shortcode. The tracking form is provider state, so materialization writes
 * nothing and rollback has nothing to undo.
 */
final class Static_Site_Importer_Woo_Order_Lookup_Materializer {
	public static function adapter(): array {
		return array(
			'id'                       => 'woocommerce_order_lookup',
			'entity_type'              => 'order_lookup',
			'entity_collection'        => 'order_lookups',
			'capability'               => 'shop',
			'provider'                 => 'woocommerce',
			'label'                    => 'WooCommerce order tracking',
			'waiver_arg'               => 'allow_missing_woocommerce',
			'validator'                => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_order_lookups_manifest' ),
			'materializer'             => array( self::class, 'materialize' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/woocommerce-order-lookup-rollback/v1',
			'binding_callback'         => array( self::class, 'binding_block_markup' ),
			'classic_binding_callback' => array( self::class, 'binding_classic_render' ),
			'report_callback'          => array( self::class, 'new_report' ),
			'dependencies'             => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'woocommerce',
					'plugin_file'           => 'woocommerce/woocommerce.php',
					'availability_callback' => array( self::class, 'woocommerce_available' ),
					'missing_apis'          => array( 'WC_Shortcode_Order_Tracking' ),
				),
			),
		);
	}

	public static function woocommerce_available(): bool {
		return class_exists( 'WC_Shortcode_Order_Tracking' ) && function_exists( 'wc_get_order' );
	}

	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'        => $status,
			'reason'        => '',
			'counts'        => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'order_lookups' => array(),
		);
	}

	/** Map every validated order-lookup form to the provider tracking form; decline them all when it is absent. */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$lookups = is_array( $manifest['order_lookups'] ?? null ) ? array_values( array_filter( $manifest['order_lookups'], 'is_array' ) ) : array();
		$mapped  = self::woocommerce_available();
		$report  = self::new_report( empty( $lookups ) || ! $mapped ? 'skipped' : 'completed' );
		if ( empty( $lookups ) ) {
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}
		if ( ! $mapped ) {
			$report['reason'] = 'woocommerce_inactive';
		}
		foreach ( $lookups as $lookup ) {
			$row = array(
				'source_path' => (string) ( $lookup['source_path'] ?? '' ),
				'selector'    => (string) ( $lookup['selector'] ?? '' ),
				'status'      => $mapped ? 'mapped' : 'skipped',
			);
			// The declared identity travels with its result row so registry result
			// lookups key the same way the validated entity does.
			$identity = $lookup['fallback_identity'] ?? $lookup['source_fallback_identity'] ?? $lookup['fallback_reconciliation_identity'] ?? '';
			if ( is_string( $identity ) && '' !== $identity ) {
				$row['fallback_identity'] = $identity;
			}
			$report['order_lookups'][] = $row;
			++$report['counts'][ $mapped ? 'mapped' : 'skipped' ];
		}
		return $report;
	}

	/** Order tracking lives in WooCommerce; this adapter created nothing to restore. */
	public static function rollback( array $report ): array {
		unset( $report );
		return array( 'status' => 'rolled_back' );
	}

	/**
	 * WooCommerce's order-tracking shortcode block. WooCommerce ships no
	 * order-tracking block, so the provider form renders through the shortcode
	 * the same way classic themes render it.
	 */
	public static function binding_block_markup( array $entity, array $result ): string {
		unset( $entity );
		return 'mapped' === ( $result['status'] ?? '' ) ? '<!-- wp:shortcode -->[woocommerce_order_tracking]<!-- /wp:shortcode -->' : '';
	}

	/** Classic themes render order tracking through WooCommerce's order tracking shortcode. */
	public static function binding_classic_render( array $entity, array $result ): array {
		unset( $entity );
		return 'mapped' === ( $result['status'] ?? '' ) ? array(
			'kind'    => 'shortcode',
			'content' => '[woocommerce_order_tracking]',
		) : array();
	}
}
