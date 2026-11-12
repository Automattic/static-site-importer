<?php
/**
 * Optional WooCommerce cart navigation for producer-declared native controls.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-static-site-importer-product-grid-runtime.php';

/** Maps controls without replacing their editable block type. */
final class Static_Site_Importer_Woo_Cart_Links_Materializer {
	/** Provider lifecycle contract. */
	public static function adapter(): array {
		return array(
			'id'                   => 'woocommerce_cart_links',
			'entity_type'          => 'cart_link',
			'entity_collection'    => 'cart_links',
			'capability'           => 'shop',
			'provider'             => 'woocommerce',
			'label'                => 'WooCommerce cart links',
			'validator'            => array( self::class, 'validate' ),
			'materializer'         => array( self::class, 'materialize' ),
			'binding_callback'     => array( self::class, 'binding_block_markup' ),
			'rollback_callback'    => array( self::class, 'rollback' ),
			'rollback_contract_id' => 'static-site-importer/woocommerce-cart-links-rollback/v1',
			'dependencies'         => array(),
		);
	}

	/** This optional enhancement must never install a provider. */
	public static function available(): bool {
		return function_exists( 'wc_get_cart_url' ) && function_exists( 'WC' );
	}

	/** Validate exact block anchors and keep unsupported optional rows unchanged. */
	public static function validate( mixed $manifest ): array {
		$rows = array();
		foreach ( is_array( $manifest ) ? ( $manifest['cart_links'] ?? array() ) : array() as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['source_path'] ?? null ) || ! is_string( $row['selector'] ?? null ) || empty( $row['bindings'] ) || ! is_array( $row['bindings'] ) ) {
				continue;
			}
			$valid = true;
			foreach ( $row['bindings'] as $binding ) {
				$blocks = is_array( $binding ) && is_string( $binding['search_block_markup'] ?? null ) ? parse_blocks( $binding['search_block_markup'] ) : array();
				$valid  = $valid && 'generic/block-binding/v1' === ( $binding['schema'] ?? '' ) && 'commerce_cart_link' === ( $binding['role'] ?? '' ) && is_string( $binding['source_path'] ?? null ) && '' !== $binding['source_path'] && is_int( $binding['occurrence'] ?? null ) && $binding['occurrence'] > 0 && 1 === count( $blocks ) && ! empty( $blocks[0]['blockName'] );
			}
			if ( $valid ) {
				$rows[] = $row;
			}
		}
		return array(
			'cart_links' => $rows,
			'errors'     => array(),
		);
	}

	/** Stable identity shared by render markers and AJAX fragments. */
	private static function identity( array $binding ): string {
		return substr( hash( 'sha256', $binding['source_path'] . "\n" . $binding['search_block_markup'] . "\n" . $binding['occurrence'] ), 0, 24 );
	}

	/** Retain badge templates for AJAX requests, which do not render the page. */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$key      = Static_Site_Importer_Product_Grid_Runtime::CART_BADGES_OPTION;
		$previous = get_option( $key, array() );
		$badges   = is_array( $previous ) ? $previous : array();
		$report   = array(
			'status'          => 'completed',
			'counts'          => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'cart_links'      => array(),
			'previous_badges' => $previous,
		);
		foreach ( $manifest['cart_links'] ?? array() as $entity ) {
			$status = self::available() ? 'mapped' : 'skipped';
			if ( 'mapped' === $status ) {
				foreach ( $entity['bindings'] as $binding ) {
					$id    = self::identity( $binding );
					$badge = Static_Site_Importer_Product_Grid_Runtime::cart_badge( $binding['search_block_markup'], (string) ( $entity['count_badge']['selector'] ?? '' ), $id );
					if ( '' !== $badge ) {
						$badges[ $id ] = $badge;
					}
				}
			}
			$report['cart_links'][] = array(
				'source_path' => $entity['source_path'],
				'selector'    => $entity['selector'],
				'status'      => $status,
			);
			++$report['counts'][ $status ];
		}
		if ( self::available() && $badges !== $previous ) {
			update_option( $key, $badges, false );
		}
		return $report;
	}

	/** Restore the prior fragment inventory on a failed import. */
	public static function rollback( array $report ): array {
		update_option( Static_Site_Importer_Product_Grid_Runtime::CART_BADGES_OPTION, $report['previous_badges'] ?? array(), false );
		return array( 'status' => 'rolled_back' );
	}

	/** Mark the original block, leaving its saved markup and native type intact. */
	public static function binding_block_markup( array $entity, array $result ): string {
		if ( 'mapped' !== ( $result['status'] ?? '' ) ) {
			return '';
		}
		$binding = $entity['bindings'][0];
		$block   = parse_blocks( $binding['search_block_markup'] )[0];
		$block['attrs']['metadata'][ Static_Site_Importer_Product_Grid_Runtime::CART_LINK_KEY ] = array(
			'id'             => self::identity( $binding ),
			'badge_selector' => (string) ( $entity['count_badge']['selector'] ?? '' ),
		);
		return serialize_block( $block );
	}
}
