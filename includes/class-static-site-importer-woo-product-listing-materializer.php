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
 * - the sort control becomes `catalog-sorting` and the result count
 *   `product-results-count`;
 * - a category or seeded-attribute facet (buttons, a select or radios) and a
 *   price range keep their source block, wired to the archive's own query
 *   parameter: the server renders the requested state and choosing an option
 *   loads the archive URL with that parameter. WooCommerce's filter blocks
 *   draw their own chips, checkboxes and two-handle slider, in term order and
 *   without the source's "all" option, so the source controls are what keep
 *   the source design.
 *
 * Any other control keeps its source block unchanged. The theme serves this
 * archive markup as `archive-product`. The page instead uses a hand-picked
 * collection of its grid's seeded products and keeps all source controls.
 */
final class Static_Site_Importer_Woo_Product_Listing_Materializer {
	/** Theme template WooCommerce renders for the shop page and product taxonomies. */
	public const ARCHIVE_TEMPLATE = 'templates/archive-product.html';

	/** Block metadata naming the archive query parameter a source control sets. */
	public const CONTROL_KEY = 'staticSiteImporterListingControl';

	/** Script handle that turns a wired source control's change into the archive URL. */
	private const CONTROL_SCRIPT = 'static-site-importer-listing-controls';

	public static function adapter(): array {
		return array(
			'id'                       => 'woocommerce_product_listing',
			'entity_type'              => 'product_listing',
			'entity_collection'        => 'product_listings',
			'rejected_rows_degrade'    => true,
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
			$status = '' === $reason ? 'mapped' : 'skipped';
			$row    = array_filter(
				array(
					'source_path' => (string) ( $listing['source_path'] ?? '' ),
					'selector'    => (string) ( $listing['selector'] ?? '' ),
					'status'      => $status,
					'reason'      => $reason,
				),
				static fn( string $value ): bool => '' !== $value
			);
			if ( $available && ! empty( $listing['category']['name'] ) && ! empty( $listing['category']['slug'] ) ) {
				$row = array_merge( $row, self::materialize_category( $listing ) );
				if ( isset( $row['error'] ) ) {
					$status           = 'error';
					$row['status']    = $status;
					$report['status'] = 'error';
				}
			}
			$report['product_listings'][] = $row;
			++$report['counts'][ $status ];
			if ( 'mapped' === $status && array() === $report['rollback']['menu_order'] ) {
				$report['rollback']['menu_order'] = self::apply_source_order( $listing['grid'] );
			}
		}
		return $report;
	}

	/**
	 * Ensure the listing category and append grid membership, retaining a rollback receipt.
	 *
	 * @param array<string,mixed> $listing Prepared listing.
	 * @return array<string,mixed> Category receipt, including any partial assignments on error.
	 */
	private static function materialize_category( array $listing ): array {
		$category = $listing['category'];
		$term     = get_term_by( 'slug', (string) $category['slug'], 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			// The product seeder names the same category after the listing.
			$term = get_term_by( 'name', (string) $category['name'], 'product_cat' );
		}
		$created = false;
		if ( $term instanceof WP_Term ) {
			$term_id = (int) $term->term_id;
		} else {
			$inserted = wp_insert_term( (string) $category['name'], 'product_cat', array( 'slug' => (string) $category['slug'] ) );
			if ( is_wp_error( $inserted ) ) {
				return array( 'error' => $inserted->get_error_message() );
			}
			$term_id = (int) $inserted['term_id'];
			$created = true;
		}
		$row = array(
			'term_id'              => $term_id,
			'term_created'         => $created,
			'assigned_product_ids' => array(),
			'added_product_ids'    => array(),
		);
		$ids = is_array( $listing['grid'] ?? null ) ? Static_Site_Importer_Woo_Product_Grid_Materializer::product_ids( $listing['grid'] ) : array();
		foreach ( array_keys( $ids ) as $id ) {
			$existing = has_term( $term_id, 'product_cat', $id );
			$assigned = wp_set_object_terms( $id, $term_id, 'product_cat', true );
			if ( is_wp_error( $assigned ) ) {
				$row['error'] = $assigned->get_error_message();
				break;
			}
			$row['assigned_product_ids'][] = $id;
			if ( ! $existing ) {
				$row['added_product_ids'][] = $id;
			}
		}
		return $row;
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

	/** Restore product ordering and remove only category data added by this receipt. */
	public static function rollback( array $report ): array {
		$failures = array();
		foreach ( $report['product_listings'] ?? array() as $row ) {
			$term_id = (int) ( $row['term_id'] ?? 0 );
			foreach ( $row['added_product_ids'] ?? array() as $id ) {
				$removed = wp_remove_object_terms( (int) $id, $term_id, 'product_cat' );
				if ( is_wp_error( $removed ) || false === $removed ) {
					$failures[] = (int) $id;
				}
			}
		}
		foreach ( $report['product_listings'] ?? array() as $row ) {
			$term_id = (int) ( $row['term_id'] ?? 0 );
			if ( ! empty( $row['term_created'] ) ) {
				$deleted = wp_delete_term( $term_id, 'product_cat' );
				if ( is_wp_error( $deleted ) || false === $deleted ) {
					$failures[] = $term_id;
				}
			}
		}
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
		return 'mapped' === ( $result['status'] ?? '' ) ? self::collection_block_markup( $entity, true ) : '';
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
		return self::collection_block_markup( $entity, false );
	}

	/**
	 * Build the page's hand-picked collection or the archive's inheriting collection.
	 *
	 * @param array<string,mixed> $entity Listing with its adopted grid.
	 * @param bool                $page   Whether to use the page query and source controls.
	 * @return string
	 */
	private static function collection_block_markup( array $entity, bool $page ): string {
		$grid        = is_array( $entity['grid'] ?? null ) ? $entity['grid'] : array();
		$product_ids = $page ? array_keys( Static_Site_Importer_Woo_Product_Grid_Materializer::product_ids( $grid ) ) : array();
		$page        = $page && array() !== $product_ids;
		$region      = '';
		foreach ( is_array( $entity['bindings'] ?? null ) ? $entity['bindings'] : array() as $binding ) {
			$region = is_array( $binding ) && 'commerce_listing' === ( $binding['role'] ?? '' ) ? (string) ( $binding['search_block_markup'] ?? '' ) : $region;
		}
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
		foreach ( ! $page && is_array( $entity['control_bindings'] ?? null ) ? $entity['control_bindings'] : array() as $binding ) {
			$search      = is_array( $binding ) ? (string) ( $binding['search_block_markup'] ?? '' ) : '';
			$replacement = self::control_block_markup( $controls[ (string) ( $binding['control'] ?? '' ) ] ?? array(), $search );
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
		$listing = $blocks[0];

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
			'className'  => Static_Site_Importer_Product_Grid_Runtime::LOOP_CLASS,
		);
		if ( $page ) {
			$attrs['query']['inherit']                       = false;
			$attrs['query']['woocommerceHandPickedProducts'] = $product_ids;
			$attrs['query']['orderBy']                       = 'post__in';
			$attrs['query']['perPage']                       = count( $product_ids );
			$attrs['query']['pages']                         = 1;
			$attrs['collection']                             = 'woocommerce/product-collection/hand-picked';
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( ! is_string( $json ) ) {
			return '';
		}
		return '<!-- wp:woocommerce/product-collection ' . $json . ' --><div class="wp-block-woocommerce-product-collection ' . Static_Site_Importer_Product_Grid_Runtime::LOOP_CLASS . '">'
			. serialize_block( $listing )
			. '</div><!-- /wp:woocommerce/product-collection -->';
	}

	/**
	 * The WooCommerce block for one listing control, the source block wired to
	 * the archive's query parameter, or '' when the control keeps its source.
	 */
	private static function control_block_markup( array $control, string $source ): string {
		$kind  = (string) ( $control['kind'] ?? '' );
		$wired = self::control_parameter( $control, $source );
		if ( null !== $wired ) {
			return self::wired_source_markup( $source, $wired );
		}
		if ( 'sort' === $kind ) {
			return '<!-- wp:woocommerce/catalog-sorting /-->';
		}
		return 'result_count' === $kind ? '<!-- wp:woocommerce/product-results-count /-->' : '';
	}

	private static function attribute_id( array $control ): int {
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) || ! function_exists( 'wc_sanitize_taxonomy_name' ) ) {
			return 0;
		}
		$slug = wc_sanitize_taxonomy_name( (string) ( $control['key'] ?? '' ) );
		return '' === $slug ? 0 : (int) wc_attribute_taxonomy_id_by_name( $slug );
	}

	/**
	 * The archive query parameter a facet or a price range sets. A facet
	 * lists each source option's parameter value in source order, `''` for
	 * its reset option; a range names the bound that clears the parameter.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function control_parameter( array $control, string $source ): ?array {
		$kind = (string) ( $control['kind'] ?? '' );
		if ( 'range' === $kind && 'price' === ( $control['field'] ?? '' ) && is_numeric( $control['min'] ?? null ) && is_numeric( $control['max'] ?? null ) ) {
			$max = 'min' !== ( $control['bound'] ?? 'max' );
			return array(
				'param' => $max ? 'max_price' : 'min_price',
				'shape' => 'range',
				'clear' => (string) ( $max ? $control['max'] : $control['min'] ),
			);
		}
		if ( 'sort' === $kind ) {
			return self::sort_parameter( $control );
		}
		if ( 'result_count' === $kind ) {
			// The source's own wording, with the archive's total in place of its number.
			return 1 === preg_match( '/\d/', (string) ( $control['label'] ?? '' ) ) ? array( 'shape' => 'count' ) : null;
		}
		$shape = (string) ( $control['shape'] ?? '' );
		if ( 'facet' !== $kind || ! in_array( $shape, array( 'select', 'buttons', 'radio' ), true ) ) {
			return null;
		}
		$role  = (string) ( $control['role'] ?? '' );
		$param = 'category' === $role ? 'categories' : ( 'attribute' === $role && self::attribute_id( $control ) > 0 ? 'filter_' . wc_sanitize_taxonomy_name( (string) $control['key'] ) : '' );
		if ( '' === $param ) {
			return null;
		}
		$values = array();
		foreach ( is_array( $control['options'] ?? null ) ? $control['options'] : array() as $option ) {
			$value = is_array( $option ) && is_scalar( $option['value'] ?? null ) ? (string) $option['value'] : '';
			// Seeded categories are slugged from their name, attribute terms keep the value.
			$values[] = ! empty( $option['reset'] ) ? '' : ( 'categories' === $param ? sanitize_title( $value ) : $value );
		}
		$wired = array(
			'param'  => $param,
			'shape'  => $shape,
			'values' => $values,
		);
		if ( 'buttons' === $shape ) {
			$wired['state_class'] = self::button_state_class( $source );
		}
		return array() === $values ? null : $wired;
	}

	/**
	 * The source sort select wired to WooCommerce's `orderby`, each option
	 * mapped to the catalog order it names; null when an option names an order
	 * WooCommerce cannot run, so the control falls back to `catalog-sorting`.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function sort_parameter( array $control ): ?array {
		$orders = array(
			'default'    => '',
			'newest'     => 'date',
			'price_asc'  => 'price',
			'price_desc' => 'price-desc',
			'name_asc'   => 'title-asc',
			'name_desc'  => 'title-desc',
			'popularity' => 'popularity',
			'rating'     => 'rating',
		);
		$values = array();
		foreach ( is_array( $control['options'] ?? null ) ? $control['options'] : array() as $option ) {
			$order = is_array( $option ) && is_string( $option['order'] ?? null ) ? $option['order'] : '';
			if ( ! array_key_exists( $order, $orders ) ) {
				return null;
			}
			$values[] = $orders[ $order ];
		}
		return array() === $values ? null : array(
			'param'  => 'orderby',
			'shape'  => 'select',
			'values' => $values,
		);
	}

	/**
	 * The class only the source's selected button carries, which marks the
	 * requested option; '' when no single button stands out.
	 */
	private static function button_state_class( string $source ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return '';
		}
		$owners = array();
		$index  = 0;
		$tags   = new WP_HTML_Tag_Processor( $source );
		while ( $tags->next_tag() ) {
			if ( ! self::is_button( $tags ) ) {
				continue;
			}
			$classes = $tags->get_attribute( 'class' );
			$classes = preg_split( '/\s+/', is_string( $classes ) ? trim( $classes ) : '', -1, PREG_SPLIT_NO_EMPTY );
			foreach ( array_unique( is_array( $classes ) ? $classes : array() ) as $class ) {
				$owners[ $class ][] = $index;
			}
			++$index;
		}
		$unique  = array_filter( $owners, static fn( array $buttons ): bool => 1 === count( $buttons ) );
		$holders = array_unique( array_merge( array(), ...array_values( $unique ) ) );
		return $index > 2 && 1 === count( $holders ) ? implode( ' ', array_keys( $unique ) ) : '';
	}

	/** Whether the processor is on one of a facet's options: an option, a radio or a button. */
	private static function is_option( WP_HTML_Tag_Processor $tags, string $shape ): bool {
		if ( 'select' === $shape ) {
			return 'OPTION' === $tags->get_tag();
		}
		if ( 'radio' === $shape ) {
			return 'INPUT' === $tags->get_tag() && 'radio' === strtolower( (string) $tags->get_attribute( 'type' ) );
		}
		return self::is_button( $tags );
	}

	private static function is_button( WP_HTML_Tag_Processor $tags ): bool {
		return ! $tags->is_tag_closer() && ( 'BUTTON' === $tags->get_tag() || 'button' === $tags->get_attribute( 'role' ) );
	}

	/** The source control block, carrying the parameter it sets. */
	private static function wired_source_markup( string $source, array $wired ): string {
		$blocks = parse_blocks( $source );
		if ( 1 !== count( $blocks ) || ! is_string( $blocks[0]['blockName'] ?? null ) ) {
			return '';
		}
		$blocks[0]['attrs']['metadata'][ self::CONTROL_KEY ] = $wired;
		return serialize_block( $blocks[0] );
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
		add_filter( 'render_block', array( self::class, 'render_control' ), 10, 2 );
	}

	/**
	 * Render a wired source control in the state the archive request asks
	 * for, and mark it for the script that loads the archive URL on change.
	 */
	public static function render_control( string $content, array $block ): string {
		$wired = $block['attrs']['metadata'][ self::CONTROL_KEY ] ?? null;
		if ( is_array( $wired ) && 'count' === ( $wired['shape'] ?? null ) ) {
			return self::render_count( $content );
		}
		$param = is_array( $wired ) && is_string( $wired['param'] ?? null ) ? $wired['param'] : '';
		if ( '' === $param || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public archive filter, read like WooCommerce reads it.
		$requested = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
		$shape     = (string) ( $wired['shape'] ?? '' );
		$values    = is_array( $wired['values'] ?? null ) ? array_map( 'strval', array_values( $wired['values'] ) ) : array();
		if ( 'buttons' === $shape && 'categories' === $param ) {
			list( $content, $values ) = self::live_category_buttons( $content, $values, (string) ( $wired['state_class'] ?? '' ) );
		}
		$tags = new WP_HTML_Tag_Processor( $content );
		if ( 'range' === $shape ) {
			if ( ! $tags->next_tag( array( 'tag_name' => 'input' ) ) ) {
				return $content;
			}
			$tags->set_attribute( 'data-ssi-listing-param', $param );
			$tags->set_attribute( 'data-ssi-listing-clear', (string) ( $wired['clear'] ?? '' ) );
			if ( is_numeric( $requested ) ) {
				$tags->set_attribute( 'value', $requested );
			}
			self::enqueue_control_script();
			return $tags->get_updated_html();
		}
		// The select itself, or the element holding the buttons or radios.
		if ( ! $tags->next_tag( 'select' === $shape ? array( 'tag_name' => 'select' ) : null ) ) {
			return $content;
		}
		$tags->set_attribute( 'data-ssi-listing-param', $param );
		$tags->set_attribute( 'data-ssi-listing-clear', '' );
		// WooCommerce reads a comma list; a single-choice control shows its first value.
		$chosen = explode( ',', $requested )[0];
		if ( ! in_array( $chosen, $values, true ) ) {
			// Nothing this control offers is requested: keep the source state.
			$chosen = null;
		}
		$state = trim( (string) ( $wired['state_class'] ?? '' ) );
		$index = 0;
		$total = count( $values );
		while ( $index < $total && $tags->next_tag() ) {
			if ( ! self::is_option( $tags, $shape ) ) {
				continue;
			}
			$value = $values[ $index++ ];
			if ( 'buttons' === $shape ) {
				$tags->set_attribute( 'data-ssi-listing-value', $value );
			} else {
				$tags->set_attribute( 'value', $value );
			}
			if ( null === $chosen ) {
				continue;
			}
			$on = $value === $chosen;
			if ( 'buttons' !== $shape ) {
				$attribute = 'select' === $shape ? 'selected' : 'checked';
				$on ? $tags->set_attribute( $attribute, true ) : $tags->remove_attribute( $attribute );
				continue;
			}
			$tags->set_attribute( 'aria-pressed', $on ? 'true' : 'false' );
			foreach ( '' === $state ? array() : explode( ' ', $state ) as $class ) {
				$on ? $tags->add_class( $class ) : $tags->remove_class( $class );
			}
		}
		self::enqueue_control_script();
		return $tags->get_updated_html();
	}

	/**
	 * The category buttons for the store's current categories: the source's
	 * reset buttons stay, and each category with products gets a copy of the
	 * source's first category button, in source order and then by name, so a
	 * category added after the import appears and an emptied one leaves.
	 *
	 * @param array<int,string> $values Parameter value of each source button.
	 * @return array{0:string,1:array<int,string>} The buttons and their values.
	 */
	private static function live_category_buttons( string $content, array $values, string $state ): array {
		$unchanged = array( $content, $values );
		if ( ! function_exists( 'get_terms' ) || ! preg_match_all( '#<button\b[^>]*>.*?</button>#is', $content, $found, PREG_OFFSET_CAPTURE ) || count( $found[0] ) !== count( $values ) ) {
			return $unchanged;
		}
		$template = null;
		$before   = array();
		$after    = array();
		foreach ( $found[0] as $index => $match ) {
			if ( '' !== $values[ $index ] ) {
				$template = $template ?? $match[0];
			} elseif ( null === $template ) {
				$before[] = $match[0];
			} else {
				$after[] = $match[0];
			}
		}
		$terms = null === $template ? array() : get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);
		if ( ! is_array( $terms ) || array() === $terms ) {
			return $unchanged;
		}
		$rank = array_flip( $values );
		usort( $terms, static fn( WP_Term $a, WP_Term $b ): int => array( $rank[ $a->slug ] ?? PHP_INT_MAX, $a->name ) <=> array( $rank[ $b->slug ] ?? PHP_INT_MAX, $b->name ) );
		$buttons = '';
		$live    = array_fill( 0, count( $before ), '' );
		foreach ( $terms as $term ) {
			$buttons .= self::term_button( (string) $template, $term->name, $state );
			$live[]   = $term->slug;
		}
		$live  = array_merge( $live, array_fill( 0, count( $after ), '' ) );
		$last  = $found[0][ count( $found[0] ) - 1 ];
		$start = (int) $found[0][0][1];
		$end   = (int) $last[1] + strlen( $last[0] );
		return array( substr( $content, 0, $start ) . implode( '', $before ) . $buttons . implode( '', $after ) . substr( $content, $end ), $live );
	}

	/** A copy of the source category button naming one category, unselected. */
	private static function term_button( string $template, string $name, string $state ): string {
		$label = trim( html_entity_decode( wp_strip_all_tags( $template ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$tags  = new WP_HTML_Tag_Processor( $template );
		if ( ! $tags->next_tag() ) {
			return '';
		}
		// A data-* hook carrying the button's label names the new category.
		foreach ( $tags->get_attribute_names_with_prefix( 'data-' ) ?? array() as $attribute ) {
			if ( $label === $tags->get_attribute( $attribute ) ) {
				$tags->set_attribute( $attribute, $name );
			}
		}
		foreach ( '' === trim( $state ) ? array() : explode( ' ', trim( $state ) ) as $class ) {
			$tags->remove_class( $class );
		}
		$html = $tags->get_updated_html();
		return preg_match( '#^(<button\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>).*(</button>)$#is', $html, $parts ) ? $parts[1] . esc_html( $name ) . $parts[2] : '';
	}

	/**
	 * The source count text with the number of products the archive found,
	 * replacing the first number in its text; unchanged outside a query.
	 */
	private static function render_count( string $content ): string {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof WP_Query ) {
			return $content;
		}
		$counted = preg_replace( '/(>[^<\d]*)\d[\d,.]*/', '${1}' . (int) $query->found_posts, $content, 1 );
		return is_string( $counted ) ? $counted : $content;
	}

	private static function enqueue_control_script(): void {
		if ( ! function_exists( 'wp_register_script' ) || wp_script_is( self::CONTROL_SCRIPT, 'enqueued' ) ) {
			return;
		}
		wp_register_script( self::CONTROL_SCRIPT, false, array(), STATIC_SITE_IMPORTER_VERSION, array( 'in_footer' => true ) );
		wp_add_inline_script( self::CONTROL_SCRIPT, self::control_script() );
		wp_enqueue_script( self::CONTROL_SCRIPT );
	}

	/** Load the archive's first page with the control's parameter set, or cleared at its reset value. */
	public static function control_script(): string {
		return <<<'JS'
function ssiListingLoad(control, value) {
	var url = new URL(window.location.href);
	var param = control.getAttribute('data-ssi-listing-param');
	url.pathname = url.pathname.replace(/\/page\/\d+\/?$/, '/');
	url.searchParams.delete('paged');
	if (value === control.getAttribute('data-ssi-listing-clear')) {
		url.searchParams.delete(param);
	} else {
		url.searchParams.set(param, value);
	}
	window.location.assign(url.toString());
}
document.addEventListener('change', function (event) {
	var target = event.target instanceof Element ? event.target : null;
	var control = target ? target.closest('[data-ssi-listing-param]') : null;
	if (control) ssiListingLoad(control, target.value);
});
document.addEventListener('click', function (event) {
	var option = event.target instanceof Element ? event.target.closest('[data-ssi-listing-value]') : null;
	var control = option ? option.closest('[data-ssi-listing-param]') : null;
	if (!control) return;
	event.preventDefault();
	ssiListingLoad(control, option.getAttribute('data-ssi-listing-value'));
});
JS;
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
