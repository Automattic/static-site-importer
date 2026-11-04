<?php
/**
 * WooCommerce blocks for producer-declared script-owned commerce views.
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
 *
 * The checkout collection shares this implementation: the two differ only in
 * block name, shortcode and collection key, so a view spec carries those and
 * every step — adapter definition, report, materialization and bindings — is
 * one shared code path (see Static_Site_Importer_Woo_Checkout_Materializer).
 */
final class Static_Site_Importer_Woo_Cart_Materializer {

	/** What identifies the script-owned cart view collection this class binds. */
	private const SPEC = array(
		'id'                   => 'woocommerce_cart',
		'owner'                => self::class,
		'entity_type'          => 'cart',
		'entity_collection'    => 'carts',
		'label'                => 'WooCommerce cart',
		'block'                => 'woocommerce/cart',
		'shortcode'            => 'woocommerce_cart',
		'missing_api'          => 'WC_Cart',
		'validator'            => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_carts_manifest' ),
		'rollback_contract_id' => 'static-site-importer/woocommerce-cart-rollback/v1',
	);

	public static function adapter(): array {
		return self::commerce_view_adapter( self::SPEC );
	}

	/** The adapter for one script-owned commerce view collection WooCommerce serves. */
	public static function commerce_view_adapter( array $spec ): array {
		$owner = (string) $spec['owner'];
		return array(
			'id'                       => (string) $spec['id'],
			'entity_type'              => (string) $spec['entity_type'],
			'entity_collection'        => (string) $spec['entity_collection'],
			'rejected_rows_degrade'    => true,
			'capability'               => 'shop',
			'provider'                 => 'woocommerce',
			'label'                    => (string) $spec['label'],
			'waiver_arg'               => 'allow_missing_woocommerce',
			'validator'                => $spec['validator'],
			'materializer'             => array( $owner, 'materialize' ),
			'rollback_callback'        => array( $owner, 'rollback' ),
			'rollback_contract_id'     => (string) $spec['rollback_contract_id'],
			'binding_callback'         => array( $owner, 'binding_block_markup' ),
			'classic_binding_callback' => array( $owner, 'binding_classic_render' ),
			'report_callback'          => array( $owner, 'new_report' ),
			'dependencies'             => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'woocommerce',
					'plugin_file'           => 'woocommerce/woocommerce.php',
					'availability_callback' => array( $owner, 'woocommerce_available' ),
					'missing_apis'          => array( (string) $spec['missing_api'] ),
				),
			),
		);
	}

	/** Whether WooCommerce itself serves this view's provider state. */
	public static function commerce_view_available( array $spec ): bool {
		return class_exists( (string) $spec['missing_api'] ) && function_exists( 'wc_get_page_id' );
	}

	public static function woocommerce_available(): bool {
		return self::commerce_view_available( self::SPEC );
	}

	public static function new_report( string $status = 'skipped' ): array {
		return self::commerce_view_report( self::SPEC, $status );
	}

	/** One report row per validated view, mirroring every other collection report. */
	public static function commerce_view_report( array $spec, string $status = 'skipped' ): array {
		return array_merge(
			array(
				'status' => $status,
				'reason' => '',
				'counts' => array(
					'mapped'  => 0,
					'skipped' => 0,
					'error'   => 0,
				),
			),
			array( (string) $spec['entity_collection'] => array() )
		);
	}

	/** Map every validated view to the provider block; decline them all when it is absent. */
	public static function commerce_view_materialize( array $spec, array $manifest, array $args = array() ): array {
		unset( $args );
		$collection = (string) $spec['entity_collection'];
		$views      = is_array( $manifest[ $collection ] ?? null ) ? array_values( array_filter( $manifest[ $collection ], 'is_array' ) ) : array();
		$mapped     = self::commerce_view_available( $spec );
		$report     = self::commerce_view_report( $spec, empty( $views ) || ! $mapped ? 'skipped' : 'completed' );
		if ( empty( $views ) ) {
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}
		if ( ! $mapped ) {
			$report['reason'] = 'woocommerce_inactive';
		}
		foreach ( $views as $view ) {
			$report[ $collection ][] = array(
				'source_path' => (string) ( $view['source_path'] ?? '' ),
				'selector'    => (string) ( $view['selector'] ?? '' ),
				'status'      => $mapped ? 'mapped' : 'skipped',
			);
			++$report['counts'][ $mapped ? 'mapped' : 'skipped' ];
		}
		return $report;
	}

	public static function materialize( array $manifest, array $args = array() ): array {
		return self::commerce_view_materialize( self::SPEC, $manifest, $args );
	}

	/** These views live in WooCommerce sessions; this adapter created nothing to restore. */
	public static function rollback( array $report ): array {
		unset( $report );
		return array( 'status' => 'rolled_back' );
	}

	/**
	 * The provider block for one view. Its renderer expands the bare block into
	 * the current default inner blocks (the cart's filled cart, totals and empty
	 * cart; the checkout's order review and payment fields).
	 */
	public static function commerce_view_block_markup( array $spec, array $entity, array $result ): string {
		unset( $entity );
		if ( 'mapped' !== ( $result['status'] ?? '' ) ) {
			return '';
		}
		$block = (string) $spec['block'];
		return '<!-- wp:' . $block . ' --><div class="wp-block-' . str_replace( '/', '-', $block ) . ' alignwide is-loading"></div><!-- /wp:' . $block . ' -->';
	}

	public static function binding_block_markup( array $entity, array $result ): string {
		return self::commerce_view_block_markup( self::SPEC, $entity, $result );
	}

	/** Classic themes render these views through WooCommerce's matching shortcode. */
	public static function commerce_view_classic_render( array $spec, array $entity, array $result ): array {
		unset( $entity );
		return 'mapped' === ( $result['status'] ?? '' ) ? array(
			'kind'    => 'shortcode',
			'content' => '[' . (string) $spec['shortcode'] . ']',
		) : array();
	}

	public static function binding_classic_render( array $entity, array $result ): array {
		return self::commerce_view_classic_render( self::SPEC, $entity, $result );
	}
}
