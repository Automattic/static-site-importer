<?php
/**
 * WooCommerce product loop for a producer-declared product grid.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-static-site-importer-product-grid-runtime.php';

/**
 * Replaces each `generic/product-grids/v1` grid with a WooCommerce product
 * loop that repeats the imported card.
 *
 * The producer describes the card as an item template: one card's block
 * markup, the slots that differ per product, and each product's slot values.
 * The loop is `woocommerce/product-collection` over the seeded products, and
 * its `product-template` is that card:
 *
 * - the `name` slot becomes `core/post-title` and the `price` slot becomes
 *   `woocommerce/product-price`, each keeping the source block's own classes
 *   and styles, so both show live product data in the editor and front end;
 * - the `description` slot shows the product's live description;
 * - the `add_to_cart` slot keeps the source control and wires it to
 *   WooCommerce's AJAX add-to-cart;
 * - every other slot renders the current product's own source block.
 *
 * The source grid wrapper stays, minus the attributes its script used to
 * redraw the cards. `Static_Site_Importer_Product_Grid_Runtime` fills the
 * slots and flattens WooCommerce's list wrappers, so the source grid CSS lays
 * out the cards exactly as before. Products are seeded by the product
 * adapter; this adapter writes nothing.
 */
final class Static_Site_Importer_Woo_Product_Grid_Materializer {
	/**
	 * Describe the grid adapter.
	 *
	 * @return array<string,mixed> Adapter contract.
	 */
	public static function adapter(): array {
		return array(
			'id'                       => 'woocommerce_product_grid',
			'entity_type'              => 'product_grid',
			'entity_collection'        => 'product_grids',
			'rejected_rows_degrade'    => true,
			'capability'               => 'shop',
			'provider'                 => 'woocommerce',
			'label'                    => 'WooCommerce product grid',
			'waiver_arg'               => 'allow_missing_woocommerce',
			'validator'                => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_product_grids_manifest' ),
			'materializer'             => array( self::class, 'materialize' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/woocommerce-product-grid-rollback/v1',
			'binding_callback'         => array( self::class, 'binding_block_markup' ),
			'classic_binding_callback' => array( self::class, 'binding_classic_render' ),
			'report_callback'          => array( self::class, 'new_report' ),
			'dependencies'             => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'woocommerce',
					'plugin_file'           => 'woocommerce/woocommerce.php',
					'availability_callback' => array( self::class, 'woocommerce_available' ),
					'missing_apis'          => array( 'WC_Product' ),
				),
			),
		);
	}

	/** Whether WooCommerce's product API is available. */
	public static function woocommerce_available(): bool {
		return class_exists( 'WC_Product' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Initialize a report.
	 *
	 * @param string $status Initial status.
	 * @return array<string,mixed> Report.
	 */
	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'        => $status,
			'reason'        => '',
			'counts'        => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'product_grids' => array(),
		);
	}

	/**
	 * Map every grid whose script hooks can be retired; skip the rest so their
	 * cards keep the per-product controls.
	 *
	 * @param array<string,mixed> $manifest Validated manifest.
	 * @param array<string,mixed> $args Import options.
	 * @return array<string,mixed> Report.
	 */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$grids     = is_array( $manifest['product_grids'] ?? null ) ? array_values( array_filter( $manifest['product_grids'], 'is_array' ) ) : array();
		$available = self::woocommerce_available();
		$report    = self::new_report( empty( $grids ) || ! $available ? 'skipped' : 'completed' );
		if ( empty( $grids ) ) {
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}
		if ( ! $available ) {
			$report['reason'] = 'woocommerce_inactive';
		}
		foreach ( $grids as $grid ) {
			$reason = $available ? '' : 'woocommerce_inactive';
			if ( '' === $reason && null === self::retirable_hooks( $grid['bindings'][0]['superseded_runtime_selectors'] ?? array() ) ) {
				$reason = 'runtime_hook_not_retirable';
			}
			$status                    = '' === $reason ? 'mapped' : 'skipped';
			$report['product_grids'][] = array_filter(
				array(
					'source_path' => (string) ( $grid['source_path'] ?? '' ),
					'selector'    => (string) ( $grid['selector'] ?? '' ),
					'status'      => $status,
					'reason'      => $reason,
				),
				static fn( string $value ): bool => '' !== $value
			);
			++$report['counts'][ $status ];
		}
		return $report;
	}

	/**
	 * The loop reads seeded products; this adapter created nothing to restore.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return array<string,mixed> Rollback status.
	 */
	public static function rollback( array $report ): array {
		unset( $report );
		return array( 'status' => 'rolled_back' );
	}

	/**
	 * Render a mapped grid binding.
	 *
	 * @param array<string,mixed> $entity Grid entity.
	 * @param array<string,mixed> $result Mapping result.
	 */
	public static function binding_block_markup( array $entity, array $result ): string {
		return 'mapped' === ( $result['status'] ?? '' ) ? self::grid_block_markup( $entity ) : '';
	}

	/**
	 * Classic themes render products through WooCommerce's products shortcode.
	 *
	 * @param array<string,mixed> $entity Grid entity.
	 * @param array<string,mixed> $result Mapping result.
	 * @return array<string,mixed> Shortcode binding.
	 */
	public static function binding_classic_render( array $entity, array $result ): array {
		$ids = 'mapped' === ( $result['status'] ?? '' ) ? array_keys( self::product_ids( $entity ) ) : array();
		return empty( $ids ) ? array() : array(
			'kind'    => 'shortcode',
			'content' => '[products ids="' . implode( ',', $ids ) . '" orderby="post__in" limit="' . count( $ids ) . '"]',
		);
	}

	/**
	 * The attribute names a grid's runtime selectors hook on, or null when a
	 * selector is not an attribute (`[name]`, `[name=value]`) or id (`#id`)
	 * selector and so cannot be retired without touching the design's classes.
	 *
	 * @param mixed $selectors Superseded runtime selectors.
	 * @return array<string,string|null>|null Attribute name => id value for `#id`, else null.
	 */
	public static function retirable_hooks( mixed $selectors ): ?array {
		$hooks = array();
		foreach ( is_array( $selectors ) ? $selectors : array() as $selector ) {
			$selector = trim( (string) $selector );
			if ( 1 === preg_match( '/^\[([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:[~|^$*]?=[^\]]*)?\]$/', $selector, $match ) ) {
				$hooks[ strtolower( $match[1] ) ] = null;
			} elseif ( 1 === preg_match( '/^#([-a-zA-Z0-9_]+)$/', $selector, $match ) ) {
				$hooks['id'] = $match[1];
			} else {
				return null;
			}
		}
		return $hooks;
	}

	/**
	 * Seeded product ids for the grid's items, in source order.
	 *
	 * @param array<string,mixed> $entity Grid entity.
	 * @return array<int,array<string,mixed>> Product id => that item's slot values.
	 */
	public static function product_ids( array $entity ): array {
		$ids = array();
		foreach ( is_array( $entity['items'] ?? null ) ? $entity['items'] : array() as $item ) {
			$slug = is_array( $item ) ? sanitize_title( (string) ( $item['product'] ?? '' ) ) : '';
			$post = '' !== $slug ? get_page_by_path( $slug, OBJECT, 'product' ) : null;
			if ( $post instanceof WP_Post && ! isset( $ids[ $post->ID ] ) ) {
				$ids[ $post->ID ] = is_array( $item['slots'] ?? null ) ? $item['slots'] : array();
			}
		}
		return $ids;
	}

	/**
	 * The grid wrapper around a product loop of the template card.
	 *
	 * @param array<string,mixed> $entity Grid entity.
	 */
	public static function grid_block_markup( array $entity ): string {
		$items = self::product_ids( $entity );
		$card  = self::card_markup( $entity, $items );
		$loop  = '' === $card ? null : self::single_block(
			Static_Site_Importer_Woo_Product_Seeder::product_collection_block_markup(
				array_keys( $items ),
				max( 1, min( 6, count( $items ) ) ),
				abs( crc32( (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' ) ) ) % 1000000,
				$card,
				Static_Site_Importer_Product_Grid_Runtime::LOOP_CLASS
			)
		);
		return null === $loop ? '' : self::grid_around( $entity, $loop );
	}

	/**
	 * The grid wrapper, retired from its script, around the template card
	 * repeated by the product collection it sits in, such as a store
	 * listing's archive collection.
	 *
	 * @param array<string,mixed> $entity Grid entity.
	 */
	public static function inherited_grid_block_markup( array $entity ): string {
		$card = self::card_markup( $entity, self::product_ids( $entity ) );
		return '' === $card ? '' : self::grid_around( $entity, self::single_block( '<!-- wp:woocommerce/product-template -->' . $card . '<!-- /wp:woocommerce/product-template -->' ) );
	}

	/**
	 * The template card with its slots filled, or '' when no seeded product
	 * or slot path resolves.
	 *
	 * @param array<string,mixed>            $entity Grid entity.
	 * @param array<int,array<string,mixed>> $items Product id => slot values.
	 */
	private static function card_markup( array $entity, array $items ): string {
		$template = is_array( $entity['item_template'] ?? null ) ? $entity['item_template'] : array();
		$card     = self::single_block( (string) ( $template['block_markup'] ?? '' ) );
		if ( empty( $items ) || null === $card ) {
			return '';
		}
		$source_card = serialize_block( $card );
		$source_name = '';
		foreach ( $template['slots'] ?? array() as $source_slot ) {
			if ( 'name' !== ( $source_slot['role'] ?? '' ) ) {
				continue;
			}
			$name_node = $card;
			foreach ( $source_slot['path'] ?? array() as $index ) {
				$name_node = $name_node['innerBlocks'][ $index ] ?? array();
			}
			if ( ! empty( $name_node ) ) {
				$source_name = trim( html_entity_decode( wp_strip_all_tags( serialize_block( $name_node ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
		}
		// A shared badge alone cannot identify the template product. Compare
		// all captured slots before selecting the source card's product.
		$template_id = 0;
		$best_score  = -1;
		foreach ( $items as $product_id => $values ) {
			$score = 0;
			foreach ( $template['slots'] ?? array() as $source_slot ) {
				$captured = $values[ $source_slot['id'] ?? '' ]['markup'] ?? '';
				if ( '' === $captured ) {
					continue;
				}
				$source_node = $card;
				foreach ( $source_slot['path'] ?? array() as $index ) {
					$source_node = $source_node['innerBlocks'][ $index ] ?? array();
				}
				if ( self::single_block( $captured ) === $source_node ) {
					++$score;
				}
			}
			if ( $score > $best_score ) {
				$best_score  = $score;
				$template_id = $product_id;
			}
		}
		unset( $card['attrs']['metadata']['name'] );
		foreach ( is_array( $template['slots'] ?? null ) ? $template['slots'] : array() as $slot ) {
			if ( ! is_array( $slot ) || ! is_array( $slot['path'] ?? null ) || ! self::fill_slot( $card, $slot['path'], $slot, $items, $source_card, $source_name, $template_id ) ) {
				return '';
			}
		}
		return serialize_block( $card );
	}

	/**
	 * The source grid element, minus its script hooks, around one inner block.
	 *
	 * @param array<string,mixed>      $entity Grid entity.
	 * @param array<string,mixed>|null $inner Inner block.
	 */
	private static function grid_around( array $entity, ?array $inner ): string {
		$binding = is_array( $entity['bindings'][0] ?? null ) ? $entity['bindings'][0] : array();
		$hooks   = self::retirable_hooks( $binding['superseded_runtime_selectors'] ?? array() );
		$grid    = self::single_block( (string) ( $binding['search_block_markup'] ?? '' ) );
		if ( null === $inner || null === $hooks || null === $grid || 1 === count( $grid['innerContent'] ) || ! is_string( $grid['innerContent'][0] ) ) {
			return '';
		}
		$grid = self::without_hooks( $grid, $hooks );
		$open = (string) ( $grid['innerContent'][0] ?? '' );
		$tail = (string) ( $grid['innerContent'][ count( $grid['innerContent'] ) - 1 ] ?? '' );

		$grid['innerBlocks']  = array( $inner );
		$grid['innerContent'] = array( $open, null, $tail );
		$grid['innerHTML']    = $open . $tail;
		return serialize_block( $grid );
	}

	/**
	 * Turn the template block at `$path` into its slot.
	 *
	 * @param array<string,mixed>            $block Template block.
	 * @param array<int,mixed>               $path Inner-block indexes.
	 * @param array<string,mixed>            $slot Slot definition.
	 * @param array<int,array<string,mixed>> $items Product id => slot values.
	 * @param string                         $source_card Original card markup.
	 * @param string                         $source_name Name shown in the card.
	 * @param int                            $template_id Product matched across the whole card.
	 */
	private static function fill_slot( array &$block, array $path, array $slot, array $items, string $source_card = '', string $source_name = '', int $template_id = 0 ): bool {
		$node = &$block;
		foreach ( $path as $index ) {
			if ( ! is_int( $index ) || ! isset( $node['innerBlocks'][ $index ] ) ) {
				return false;
			}
			$node = &$node['innerBlocks'][ $index ];
		}
		$role = (string) ( $slot['role'] ?? 'field' );
		if ( 'name' === $role && 'core/heading' === $node['blockName'] ) {
			$node = self::dynamic_block( 'core/post-title', self::presentation_attrs( $node['attrs'], array( 'level' => (int) ( $node['attrs']['level'] ?? 2 ) ) ) );
			return true;
		}
		if ( 'price' === $role ) {
			$node = self::dynamic_block( 'woocommerce/product-price', self::presentation_attrs( $node['attrs'], array( 'isDescendentOfQueryLoop' => true ) ) );
			return true;
		}
		$marker = array( 'role' => in_array( $role, array( 'description', 'add_to_cart' ), true ) ? $role : 'field' );
		if ( 'field' === $marker['role'] ) {
			$id                 = (string) ( $slot['id'] ?? '' );
			$marker['optional'] = ! empty( $slot['optional'] );
			$marker['values']   = array();
			foreach ( $items as $product_id => $values ) {
				$markup = is_array( $values[ $id ] ?? null ) ? (string) ( $values[ $id ]['markup'] ?? '' ) : '';
				if ( '' !== $markup ) {
					$marker['values'][ (string) $product_id ] = $markup;
				}
			}
			// Match the actual template slot when called without the whole card.
			$source_markup = serialize_block( $node );
			if ( ! $template_id ) {
				foreach ( $marker['values'] as $product_id => $markup ) {
					if ( $markup === $source_markup || self::single_block( $markup ) === $node ) {
						$template_id = (int) $product_id;
						break;
					}
				}
			}
			if ( $template_id ) {
				$marker['template'] = Static_Site_Importer_Product_Grid_Runtime::template_values( $template_id, '' !== $source_card ? $source_card : $source_markup, '' !== $source_card ? 'field' : $role );
				if ( '' !== $source_name ) {
					$marker['template']['name'] = $source_name;
				}
			}
		}
		$node['attrs']['metadata'][ Static_Site_Importer_Product_Grid_Runtime::SLOT_KEY ] = $marker;
		return true;
	}

	/**
	 * The source block's classes and style supports on a replacement block.
	 *
	 * @param array<string,mixed> $attrs Source attributes.
	 * @param array<string,mixed> $own Replacement attributes.
	 * @return array<string,mixed> Merged attributes.
	 */
	private static function presentation_attrs( array $attrs, array $own ): array {
		return array_merge( array_intersect_key( $attrs, array_flip( array( 'className', 'textAlign', 'textColor', 'backgroundColor', 'fontSize', 'fontFamily', 'style' ) ) ), $own );
	}

	/**
	 * Build a dynamic block.
	 *
	 * @param string              $name Block name.
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return array<string,mixed> Parsed block.
	 */
	private static function dynamic_block( string $name, array $attrs ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Parse exactly one block.
	 *
	 * @param string $markup Serialized block.
	 * @return array<string,mixed>|null Parsed block.
	 */
	private static function single_block( string $markup ): ?array {
		$blocks = array_values( array_filter( parse_blocks( $markup ), static fn( array $block ): bool => is_string( $block['blockName'] ?? null ) ) );
		return 1 === count( $blocks ) ? $blocks[0] : null;
	}

	/**
	 * Remove the script hook attributes from the grid's own element: its
	 * serialized attributes and the opening tag of its saved markup.
	 *
	 * @param array<string,mixed>       $block Source block.
	 * @param array<string,string|null> $hooks Attribute name => id value for `#id`.
	 * @return array<string,mixed> Retired block.
	 */
	private static function without_hooks( array $block, array $hooks ): array {
		$strip          = static function ( mixed $value ) use ( &$strip, $hooks ): mixed {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			foreach ( $value as $key => $child ) {
				if ( 'attributes' === $key && is_array( $child ) ) {
					foreach ( array_keys( $hooks ) as $name ) {
						unset( $value[ $key ][ $name ] );
					}
					continue;
				}
				$value[ $key ] = $strip( $child );
			}
			return $value;
		};
		$block['attrs'] = $strip( $block['attrs'] );
		if ( isset( $hooks['id'] ) && ( $block['attrs']['anchor'] ?? null ) === $hooks['id'] ) {
			unset( $block['attrs']['anchor'] );
		}
		if ( is_string( $block['innerContent'][0] ?? null ) ) {
			$tags = new WP_HTML_Tag_Processor( $block['innerContent'][0] );
			if ( $tags->next_tag() ) {
				foreach ( array_keys( $hooks ) as $name ) {
					$tags->remove_attribute( $name );
				}
				$block['innerContent'][0] = $tags->get_updated_html();
			}
		}
		return $block;
	}
}
