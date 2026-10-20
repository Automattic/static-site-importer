<?php
/**
 * WooCommerce product archive for a producer-declared store listing.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns each `generic/product-listings/v1` store listing into WooCommerce's
 * product archive.
 *
 * A source shop page lists products in a grid and lets a script filter, sort
 * and count them. The listing region becomes one `woocommerce/product-collection`
 * that inherits the archive query, so WooCommerce runs the filtering, sorting
 * and paging:
 *
 * - the grid element lists the archive's products with the imported card
 *   (see the product-grid adapter);
 * - a category facet becomes `product-filter-taxonomy`, any other facet a
 *   `product-filter-attribute` of the seeded attribute, and a price range
 *   `product-filter-price`, all inside one `product-filters` block placed
 *   where the source filters were;
 * - the sort control becomes `catalog-sorting` and the result count
 *   `product-results-count`.
 *
 * A control WooCommerce cannot express keeps its source block. The page
 * keeps the result, and the theme serves it as the `archive-product`
 * template, which WooCommerce renders at the shop page's URL.
 */
final class Static_Site_Importer_Woo_Product_Listing_Materializer {
	/** Theme template WooCommerce renders for the shop page and product taxonomies. */
	public const ARCHIVE_TEMPLATE = 'templates/archive-product.html';

	public static function adapter(): array {
		return array(
			'id'                       => 'woocommerce_product_listing',
			'entity_type'              => 'product_listing',
			'entity_collection'        => 'product_listings',
			'capability'               => 'shop',
			'provider'                 => 'woocommerce',
			'label'                    => 'WooCommerce product archive',
			'waiver_arg'               => 'allow_missing_woocommerce',
			'validator'                => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_product_listings_manifest' ),
			'materializer'             => array( self::class, 'materialize' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/woocommerce-product-listing-rollback/v1',
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

	public static function woocommerce_available(): bool {
		return class_exists( 'WC_Product' ) && function_exists( 'wc_attribute_taxonomy_id_by_name' );
	}

	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'           => $status,
			'reason'           => '',
			'counts'           => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'product_listings' => array(),
			'rollback'         => array( 'menu_order' => array() ),
		);
	}

	/**
	 * Map every listing that adopted its grid; the others keep their source
	 * controls. The first mapped listing's grid sets the archive's default
	 * order: WooCommerce sorts its catalog by menu order, then title.
	 */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$listings  = is_array( $manifest['product_listings'] ?? null ) ? array_values( array_filter( $manifest['product_listings'], 'is_array' ) ) : array();
		$available = self::woocommerce_available();
		$report    = self::new_report( empty( $listings ) || ! $available ? 'skipped' : 'completed' );
		if ( empty( $listings ) ) {
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}
		if ( ! $available ) {
			$report['reason'] = 'woocommerce_inactive';
		}
		foreach ( $listings as $listing ) {
			$reason = $available ? '' : 'woocommerce_inactive';
			if ( '' === $reason && ( ! is_array( $listing['grid'] ?? null ) || empty( $listing['bindings'] ) ) ) {
				$reason = 'listing_grid_not_adopted';
			}
			$status                       = '' === $reason ? 'mapped' : 'skipped';
			$report['product_listings'][] = array_filter(
				array(
					'source_path' => (string) ( $listing['source_path'] ?? '' ),
					'selector'    => (string) ( $listing['selector'] ?? '' ),
					'status'      => $status,
					'reason'      => $reason,
				),
				static fn( string $value ): bool => '' !== $value
			);
			++$report['counts'][ $status ];
			if ( 'mapped' === $status && array() === $report['rollback']['menu_order'] ) {
				$report['rollback']['menu_order'] = self::apply_source_order( $listing['grid'] );
			}
		}
		return $report;
	}

	/**
	 * Number the grid's products in source order.
	 *
	 * @return array<int,int> Each reordered product's previous menu order, by id.
	 */
	private static function apply_source_order( array $grid ): array {
		$previous = array();
		$position = 0;
		foreach ( is_array( $grid['items'] ?? null ) ? $grid['items'] : array() as $item ) {
			$slug    = is_array( $item ) ? sanitize_title( (string) ( $item['product'] ?? '' ) ) : '';
			$product = '' === $slug ? null : get_page_by_path( $slug, OBJECT, 'product' );
			if ( ! $product instanceof WP_Post || isset( $previous[ $product->ID ] ) ) {
				continue;
			}
			$previous[ $product->ID ] = (int) $product->menu_order;
			++$position;
			if ( $position !== (int) $product->menu_order ) {
				wp_update_post(
					array(
						'ID'         => $product->ID,
						'menu_order' => $position,
					)
				);
			}
		}
		return $previous;
	}

	/** Restore the menu order of every product the listing reordered. */
	public static function rollback( array $report ): array {
		$failures = array();
		foreach ( is_array( $report['rollback']['menu_order'] ?? null ) ? $report['rollback']['menu_order'] : array() as $id => $menu_order ) {
			$product = get_post( (int) $id );
			if ( ! $product instanceof WP_Post || (int) $product->menu_order === (int) $menu_order ) {
				continue;
			}
			$restored = wp_update_post(
				array(
					'ID'         => (int) $id,
					'menu_order' => (int) $menu_order,
				),
				true
			);
			if ( is_wp_error( $restored ) ) {
				$failures[] = (int) $id;
			}
		}
		return array() === $failures ? array( 'status' => 'rolled_back' ) : array(
			'status'   => 'partial',
			'failures' => $failures,
		);
	}

	public static function binding_block_markup( array $entity, array $result ): string {
		return 'mapped' === ( $result['status'] ?? '' ) ? self::archive_block_markup( $entity ) : '';
	}

	/** Classic themes keep the source listing; WooCommerce's classic archive has no imported design. */
	public static function binding_classic_render( array $entity, array $result ): array {
		unset( $entity, $result );
		return array();
	}

	/**
	 * The listing region inside an archive product collection, with the grid
	 * listing the archive's products and each control a WooCommerce block.
	 */
	public static function archive_block_markup( array $entity ): string {
		$region = '';
		foreach ( is_array( $entity['bindings'] ?? null ) ? $entity['bindings'] : array() as $binding ) {
			$region = is_array( $binding ) && 'commerce_listing' === ( $binding['role'] ?? '' ) ? (string) ( $binding['search_block_markup'] ?? '' ) : $region;
		}
		$grid        = is_array( $entity['grid'] ?? null ) ? $entity['grid'] : array();
		$grid_anchor = (string) ( $grid['bindings'][0]['search_block_markup'] ?? '' );
		$grid_markup = array() === $grid ? '' : Static_Site_Importer_Woo_Product_Grid_Materializer::inherited_grid_block_markup( $grid );
		if ( '' === $region || '' === $grid_markup || 1 !== substr_count( $region, $grid_anchor ) ) {
			return '';
		}
		$region = str_replace( $grid_anchor, $grid_markup, $region );

		$controls = array();
		foreach ( is_array( $entity['controls'] ?? null ) ? $entity['controls'] : array() as $control ) {
			if ( is_array( $control ) ) {
				$controls[ (string) ( $control['id'] ?? '' ) ] = $control;
			}
		}
		foreach ( is_array( $entity['control_bindings'] ?? null ) ? $entity['control_bindings'] : array() as $binding ) {
			$search      = is_array( $binding ) ? (string) ( $binding['search_block_markup'] ?? '' ) : '';
			$replacement = self::control_block_markup( $controls[ (string) ( $binding['control'] ?? '' ) ] ?? array() );
			// A control WooCommerce cannot express, or one the listing does not
			// hold exactly once, keeps its source block.
			if ( '' !== $replacement && '' !== $search && 1 === substr_count( $region, $search ) ) {
				$region = str_replace( $search, $replacement, $region );
			}
		}

		$blocks = parse_blocks( $region );
		$blocks = array_values( array_filter( $blocks, static fn( array $block ): bool => is_string( $block['blockName'] ?? null ) ) );
		if ( 1 !== count( $blocks ) ) {
			return '';
		}
		$listing = self::with_filters_wrapper( $blocks[0] );

		$attrs = array(
			'queryId'    => abs( crc32( (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' ) ) ) % 1000000,
			'query'      => array(
				'perPage'                  => 9,
				'pages'                    => 0,
				'offset'                   => 0,
				'postType'                 => 'product',
				'order'                    => 'asc',
				'orderBy'                  => 'title',
				'search'                   => '',
				'exclude'                  => array(),
				'inherit'                  => true,
				'taxQuery'                 => array(),
				'isProductCollectionBlock' => true,
				'woocommerceOnSale'        => false,
				'woocommerceStockStatus'   => array( 'instock', 'outofstock', 'onbackorder' ),
				'woocommerceAttributes'    => array(),
			),
			'tagName'    => 'div',
			'collection' => 'woocommerce/product-collection/product-catalog',
			'className'  => Static_Site_Importer_Woo_Product_Grid_Materializer::LOOP_CLASS,
		);
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( ! is_string( $json ) ) {
			return '';
		}
		return '<!-- wp:woocommerce/product-collection ' . $json . ' --><div class="wp-block-woocommerce-product-collection ' . Static_Site_Importer_Woo_Product_Grid_Materializer::LOOP_CLASS . '">'
			. serialize_block( $listing )
			. '</div><!-- /wp:woocommerce/product-collection -->';
	}

	/** The WooCommerce block for one listing control, or '' when it has none. */
	private static function control_block_markup( array $control ): string {
		$kind = (string) ( $control['kind'] ?? '' );
		if ( 'facet' === $kind && 'category' === ( $control['role'] ?? '' ) ) {
			return '<!-- wp:woocommerce/product-filter-taxonomy {"taxonomy":"product_cat","displayStyle":"woocommerce/product-filter-chips"} --><div class="wp-block-woocommerce-product-filter-taxonomy"><!-- wp:woocommerce/product-filter-chips /--></div><!-- /wp:woocommerce/product-filter-taxonomy -->';
		}
		if ( 'facet' === $kind && 'attribute' === ( $control['role'] ?? '' ) && function_exists( 'wc_attribute_taxonomy_id_by_name' ) && function_exists( 'wc_sanitize_taxonomy_name' ) ) {
			$slug = wc_sanitize_taxonomy_name( (string) ( $control['key'] ?? '' ) );
			$id   = '' === $slug ? 0 : (int) wc_attribute_taxonomy_id_by_name( $slug );
			// A source select or radio group picks one value.
			$select = in_array( $control['shape'] ?? '', array( 'select', 'radio' ), true ) ? 'single' : 'multiple';
			return $id <= 0 ? '' : '<!-- wp:woocommerce/product-filter-attribute {"attributeId":' . $id . ',"displayStyle":"woocommerce/product-filter-chips","selectType":"' . $select . '"} --><div class="wp-block-woocommerce-product-filter-attribute"><!-- wp:woocommerce/product-filter-chips /--></div><!-- /wp:woocommerce/product-filter-attribute -->';
		}
		if ( 'range' === $kind && 'price' === ( $control['field'] ?? '' ) ) {
			return '<!-- wp:woocommerce/product-filter-price --><div class="wp-block-woocommerce-product-filter-price"><!-- wp:woocommerce/product-filter-price-slider /--></div><!-- /wp:woocommerce/product-filter-price -->';
		}
		if ( 'sort' === $kind ) {
			return '<!-- wp:woocommerce/catalog-sorting /-->';
		}
		return 'result_count' === $kind ? '<!-- wp:woocommerce/product-results-count /-->' : '';
	}

	/**
	 * Place one `product-filters` block around the children of the deepest
	 * block that holds every filter, so the filters keep their source place.
	 */
	private static function with_filters_wrapper( array $block ): array {
		$paths = self::filter_paths( $block, array() );
		if ( empty( $paths ) ) {
			return $block;
		}
		// The deepest block holding every filter: the longest common path of
		// the filters' parents.
		$common = array_slice( $paths[0], 0, -1 );
		foreach ( $paths as $path ) {
			$parent = array_slice( $path, 0, -1 );
			$limit  = min( count( $common ), count( $parent ) );
			$length = 0;
			while ( $length < $limit && $common[ $length ] === $parent[ $length ] ) {
				++$length;
			}
			$common = array_slice( $common, 0, $length );
		}
		return self::wrap_children( $block, $common );
	}

	/**
	 * Paths of the filter blocks under a block.
	 *
	 * @param array<int,int> $path Path of `$block`.
	 * @return array<int,array<int,int>>
	 */
	private static function filter_paths( array $block, array $path ): array {
		if ( in_array( $block['blockName'] ?? '', array( 'woocommerce/product-filter-taxonomy', 'woocommerce/product-filter-attribute', 'woocommerce/product-filter-price' ), true ) ) {
			return array( $path );
		}
		$paths = array();
		foreach ( $block['innerBlocks'] ?? array() as $index => $inner ) {
			$paths = array_merge( $paths, self::filter_paths( $inner, array_merge( $path, array( (int) $index ) ) ) );
		}
		return $paths;
	}

	/**
	 * Move the children of the block at `$path` into a `product-filters` block.
	 *
	 * @param array<int,int> $path Inner-block indexes.
	 */
	private static function wrap_children( array $block, array $path ): array {
		if ( array() !== $path ) {
			$index                          = (int) array_shift( $path );
			$block['innerBlocks'][ $index ] = self::wrap_children( $block['innerBlocks'][ $index ], $path );
			return $block;
		}
		$content = $block['innerContent'];
		$last    = count( $content ) - 1;
		$start   = is_string( $content[0] ?? null ) ? 1 : 0;
		$end     = $last > 0 && is_string( $content[ $last ] ) ? $last : $last + 1;
		$open    = 1 === $start ? (string) $content[0] : '';
		$close   = $end === $last ? (string) $content[ $last ] : '';
		$middle  = array_slice( $content, $start, $end - $start );
		// On narrow screens WooCommerce would fold the filters into an overlay
		// the source never had.
		$filters               = array(
			'blockName'    => 'woocommerce/product-filters',
			'attrs'        => array( 'overlayMode' => 'never' ),
			'innerBlocks'  => $block['innerBlocks'],
			'innerContent' => array_merge( array( '<div class="wp-block-woocommerce-product-filters">' ), $middle, array( '</div>' ) ),
		);
		$filters['innerHTML']  = implode( '', array_filter( $filters['innerContent'], 'is_string' ) );
		$block['innerBlocks']  = array( $filters );
		$block['innerContent'] = array( $open, null, $close );
		$block['innerHTML']    = $open . $close;
		return $block;
	}

	/**
	 * Whether the active theme designs WooCommerce's product archive, so the
	 * shop page's URL is served by that archive.
	 */
	public static function theme_designs_product_archive(): bool {
		return function_exists( 'get_theme_file_path' ) && is_readable( get_theme_file_path( self::ARCHIVE_TEMPLATE ) );
	}

	public static function register(): void {
		add_filter( 'blocks_engine_document_id', array( self::class, 'archive_document_id' ) );
		add_filter( 'loop_shop_per_page', array( self::class, 'archive_per_page' ), 20 );
	}

	/** A source listing shows every product at once, so its archive has one page. */
	public static function archive_per_page( mixed $per_page ): mixed {
		return self::theme_designs_product_archive() ? -1 : $per_page;
	}

	/**
	 * The archive template holds the shop page's imported listing, so the
	 * archive takes that page's document styles.
	 */
	public static function archive_document_id( mixed $id ): mixed {
		if ( ! empty( $id ) || ! function_exists( 'is_shop' ) || ! function_exists( 'wc_get_page_id' ) || ! ( is_shop() || is_product_taxonomy() ) || ! self::theme_designs_product_archive() ) {
			return $id;
		}
		$page = (int) wc_get_page_id( 'shop' );
		return $page > 0 ? $page : $id;
	}
}
