<?php
/**
 * WooCommerce product seeding for validated store manifests.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns validated store manifest products into simple or variable WooCommerce products.
 */
class Static_Site_Importer_Woo_Product_Seeder {

	/** Post meta key that identifies the manifest source image an attachment materializes, for dedup. */
	private const SOURCE_IMAGE_META_KEY = '_static_site_importer_source_image';

	/** WooCommerce's store visibility option ("coming soon" mode). */
	private const COMING_SOON_OPTION = 'woocommerce_coming_soon';

	/**
	 * Return the WooCommerce simple-product adapter definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function adapter(): array {
		return array(
			'id'                       => 'woocommerce_simple_product',
			'entity_type'              => 'product',
			'entity_collection'        => 'products',
			'capability'               => 'shop',
			'provider'                 => 'woocommerce',
			'label'                    => 'WooCommerce simple product',
			'report_key'               => 'product_seeding',
			'waiver_arg'               => 'allow_missing_woocommerce',
			'validator'                => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_woo_products_manifest' ),
			'materializer'             => array( self::class, 'seed' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/woocommerce-product-rollback/v1',
			'binding_callback'         => array( self::class, 'binding_block_markup' ),
			'classic_binding_callback' => array( self::class, 'binding_classic_render' ),
			'report_callback'          => array( self::class, 'new_report' ),
			'presentation'             => 'Static_Site_Importer_Commerce_Presentation',
			'dependencies'             => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'woocommerce',
					'plugin_file'           => 'woocommerce/woocommerce.php',
					'availability_callback' => array( self::class, 'woocommerce_available' ),
					'missing_apis'          => array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ),
				),
			),
		);
	}

	/**
	 * Return a Woo-owned control for one resolved product binding.
	 *
	 * A single-product entity binds to one seeded product via a cart-control
	 * shortcode, exactly as before. A `product_grid` entity — a detected
	 * collection of seeded products sharing one canonical source-page anchor —
	 * binds to every one of its resolved seeded products at once via the native
	 * `woocommerce/product-collection` block instead, so the whole grid renders
	 * real products rather than staying frozen source markup with no single
	 * product to bind to.
	 */
	public static function binding_block_markup( array $entity, array $result ): string {
		if ( 'product_grid' === ( $entity['entity_kind'] ?? '' ) ) {
			return self::binding_grid_block_markup( $entity );
		}
		$id = isset( $result['id'] ) ? (int) $result['id'] : 0;
		if ( 'add_to_cart_form' === ( $entity['entity_kind'] ?? '' ) ) {
			return $id > 0 ? '<!-- wp:woocommerce/single-product {"productId":' . $id . '} --><div class="wp-block-woocommerce-single-product"><!-- wp:woocommerce/add-to-cart-form /--></div><!-- /wp:woocommerce/single-product -->' : '';
		}
		return $id > 0 ? '<!-- wp:shortcode -->[add_to_cart id="' . $id . '" class="ssi-commerce-control"]<!-- /wp:shortcode -->' : '';
	}

	/** Return fixed Woo shortcode data for a classic runtime binding. */
	public static function binding_classic_render( array $entity, array $result ): array {
		$id = isset( $result['id'] ) ? (int) $result['id'] : 0;
		if ( 'add_to_cart_form' === ( $entity['entity_kind'] ?? '' ) ) {
			return $id > 0 ? array(
				'kind'    => 'shortcode',
				'content' => '[add_to_cart id="' . $id . '" show_price="false"]',
			) : array();
		}
		return $id > 0 ? array(
			'kind'    => 'shortcode',
			'content' => '[add_to_cart id="' . $id . '" class="ssi-commerce-control"]',
		) : array();
	}

	/**
	 * Bind a resolved product-grid entity to its seeded products.
	 *
	 * @param array<string,mixed> $entity Grid entity: `product_ids` (ints, source display
	 *                                    order) and an optional `columns` layout hint.
	 */
	private static function binding_grid_block_markup( array $entity ): string {
		$product_ids = is_array( $entity['product_ids'] ?? null )
			? array_values( array_filter( array_map( 'intval', $entity['product_ids'] ), static fn ( int $id ): bool => $id > 0 ) )
			: array();
		if ( empty( $product_ids ) ) {
			return '';
		}
		$columns  = isset( $entity['columns'] ) && is_int( $entity['columns'] ) ? $entity['columns'] : 4;
		$query_id = isset( $entity['query_id'] ) && is_int( $entity['query_id'] ) ? $entity['query_id'] : 0;
		return self::product_collection_block_markup( $product_ids, $columns, $query_id );
	}

	/**
	 * Build the native WooCommerce product-display block markup for a resolved
	 * set of seeded product ids, in source display order.
	 *
	 * `woocommerce/product-collection` is WooCommerce's own current block for
	 * rendering a set of products (the block used by its `Product Collection`
	 * patterns and its own blockified archive-product template; it superseded
	 * `woocommerce/all-products`). Its built-in `hand-picked` collection —
	 * `query.woocommerceHandPickedProducts` with `query.orderBy: "post__in"` —
	 * is WooCommerce's own generic mechanism for binding the block to a
	 * specific, ordered set of already-existing products; it renders
	 * server-side with no additional configuration, using the exact
	 * `product-template` shape WooCommerce's own patterns emit (product image,
	 * title, price, and add-to-cart button per product).
	 *
	 * @param array<int,int> $product_ids Seeded WooCommerce product ids, in source display order.
	 * @param int             $columns     Grid column count.
	 * @param int             $query_id    Stable per-page query id so multiple grids on one page do not collide.
	 */
	/**
	 * A hand-picked product collection over `$product_ids`, in that order.
	 *
	 * @param string $template_markup Inner `product-template` blocks; WooCommerce's
	 *                                default card when empty.
	 * @param string $class_name      Class on the collection wrapper of a template loop.
	 */
	public static function product_collection_block_markup( array $product_ids, int $columns, int $query_id, string $template_markup = '', string $class_name = '' ): string {
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( empty( $product_ids ) ) {
			return '';
		}

		$attrs = array(
			'queryId'              => max( 0, $query_id ),
			'query'                => array(
				'perPage'                       => max( 1, min( 100, count( $product_ids ) ) ),
				'pages'                         => 1,
				'offset'                        => 0,
				'postType'                      => 'product',
				'order'                         => 'asc',
				'orderBy'                       => 'post__in',
				'search'                        => '',
				'exclude'                       => array(),
				'inherit'                       => false,
				'taxQuery'                      => array(),
				'isProductCollectionBlock'      => true,
				'woocommerceOnSale'             => false,
				'woocommerceStockStatus'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'woocommerceAttributes'         => array(),
				'woocommerceHandPickedProducts' => $product_ids,
			),
			'tagName'              => 'div',
			'dimensions'           => array(
				'widthType'  => 'fill',
				'fixedWidth' => '',
			),
			'displayLayout'        => array(
				'type'    => 'flex',
				'columns' => max( 1, min( 6, $columns ) ),
			),
			'collection'           => 'woocommerce/product-collection/hand-picked',
			'queryContextIncludes' => array( 'collection' ),
			'align'                => 'wide',
		);
		// WooCommerce's default card takes the shared commerce presentation; a
		// source card template carries its own and names its own class.
		$classes = 'wp-block-woocommerce-product-collection alignwide ssi-commerce-control';
		if ( '' !== $template_markup ) {
			unset( $attrs['align'] );
			$attrs['className'] = $class_name;
			$classes            = trim( 'wp-block-woocommerce-product-collection ' . $class_name );
		}
		if ( '' === $template_markup ) {
			$template_markup = '<!-- wp:woocommerce/product-image {"showSaleBadge":false,"isDescendentOfQueryLoop":true} /-->'
				. '<!-- wp:post-title {"textAlign":"center","level":3,"isLink":true,"fontSize":"medium","__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->'
				. '<!-- wp:woocommerce/product-price {"textAlign":"center","isDescendentOfQueryLoop":true,"fontSize":"small"} /-->'
				. '<!-- wp:woocommerce/product-button {"textAlign":"center","isDescendentOfQueryLoop":true} /-->';
		}

		$attrs_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( ! is_string( $attrs_json ) ) {
			return '';
		}

		return '<!-- wp:woocommerce/product-collection ' . $attrs_json . ' -->'
			. '<div class="' . $classes . '">'
			. '<!-- wp:woocommerce/product-template -->'
			. $template_markup
			. '<!-- /wp:woocommerce/product-template -->'
			. '</div>'
			. '<!-- /wp:woocommerce/product-collection -->';
	}

	/**
	 * Seed simple products from a validated manifest.
	 *
	 * The manifest contract is owned by the products.json validator. This class only
	 * consumes the normalized array shape after that validation has succeeded.
	 *
	 * @param array<string, mixed> $manifest Validated product manifest.
	 * @param array<string, mixed> $args     Import-scoped context. `resolved_product_images` (when
	 *                                       present) maps a manifest `image` source path to its real
	 *                                       materialized bytes, resolved the same way source media
	 *                                       referenced by page content already resolves.
	 * @return array<string, mixed>
	 */
	public static function seed( array $manifest, array $args = array() ): array {
		$products = self::manifest_products( $manifest );
		$report   = self::new_report( 'not_run' );

		if ( empty( $products ) ) {
			$report['status'] = 'skipped';
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}

		if ( ! self::woocommerce_available() ) {
			$report['status']            = 'skipped';
			$report['reason']            = 'woocommerce_inactive';
			$report['counts']['skipped'] = count( $products );
			foreach ( $products as $product ) {
				$report['products'][] = array(
					'slug'   => self::string_value( $product, 'slug' ),
					'name'   => self::string_value( $product, 'name' ),
					'status' => 'skipped',
					'reason' => 'woocommerce_inactive',
				);
			}

			return $report;
		}

		$report['status'] = 'completed';

		$report['rollback'] = array(
			'created_variations'         => array(),
			'created_terms'              => array(),
			'created_attachments'        => array(),
			'created_product_attributes' => array(
				'attributes' => array(),
				'terms'      => array(),
			),
		);
		foreach ( $products as $product ) {
			$existing = get_page_by_path( sanitize_title( self::string_value( $product, 'slug' ) ), OBJECT, 'product' );
			if ( $existing instanceof WP_Post && 'product' !== $existing->post_type ) {
				$existing = null;
			}
			$before = $existing instanceof WP_Post ? self::product_state( (int) $existing->ID ) : null;
			if ( is_array( $before ) ) {
				$report['rollback'][ (int) $existing->ID ] = $before; }
			$row                  = self::seed_product( $product, $report['rollback']['created_terms'], $before, $args, $report['rollback']['created_attachments'], $report['rollback']['created_product_attributes'], $report['rollback']['created_variations'] );
			$report['products'][] = $row;

			$status = $row['status'] ?? 'error';
			if ( isset( $report['counts'][ $status ] ) ) {
				++$report['counts'][ $status ];
			} else {
				++$report['counts']['error'];
			}
		}
		if ( $report['counts']['created'] + $report['counts']['updated'] > 0 ) {
			$report['store_visibility'] = self::publish_fresh_store();
		}

		return $report;
	}

	/**
	 * Decide store visibility for a WooCommerce install that has not been configured yet.
	 *
	 * A fresh WooCommerce install defers its launch default to the first admin
	 * request (`woocommerce_newly_installed`), which adds
	 * `woocommerce_coming_soon=yes` and hides every store route behind a
	 * placeholder. Seeded products are part of an imported public site, so while
	 * that default is still pending the store is published with the rest of the
	 * import. add_option() never overrides a value the site owner already chose,
	 * and WooCommerce's own deferred add_option() then keeps this decision.
	 *
	 * @return array{status:string,coming_soon:string}
	 */
	private static function publish_fresh_store(): array {
		$current = get_option( self::COMING_SOON_OPTION, null );
		if ( null !== $current ) {
			return array(
				'status'      => 'kept',
				'coming_soon' => (string) $current,
			);
		}
		if ( 'yes' !== get_option( 'woocommerce_newly_installed', null ) ) {
			return array(
				'status'      => 'kept',
				'coming_soon' => 'unset',
			);
		}
		add_option( self::COMING_SOON_OPTION, 'no' );
		return array(
			'status'      => 'published',
			'coming_soon' => 'no',
		);
	}

	/** Restore existing product post/meta/category state or delete products created by this receipt. */
	public static function rollback( array $report ): array {
		$failures = array();
		foreach ( array_reverse( $report['rollback']['created_variations'] ?? array() ) as $variation_id ) {
			if ( get_post( (int) $variation_id ) && ! wp_delete_post( (int) $variation_id, true ) ) {
				$failures[] = array(
					'product_id' => $variation_id,
					'failures'   => array( 'variation_delete_failed' ),
				);
			}
		}
		foreach ( array_reverse( $report['products'] ?? array() ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 || ! empty( $row['compensated'] ) ) {
				continue; }
			$result = self::restore_product( $id, $report['rollback'][ $id ] ?? null );
			if ( ! empty( $result['failures'] ) ) {
				$failures[] = array(
					'product_id' => $id,
					'failures'   => $result['failures'],
				); }
		}
		if ( 'published' === ( $report['store_visibility']['status'] ?? '' ) ) {
			delete_option( self::COMING_SOON_OPTION );
		}
		$term_failures       = array_merge( self::cleanup_terms( $report['rollback']['created_terms'] ?? array() ), self::cleanup_product_attributes( $report['rollback']['created_product_attributes'] ?? array() ) );
		$attachment_failures = self::cleanup_attachments( $report['rollback']['created_attachments'] ?? array() );
		return array(
			'status'                      => empty( $failures ) && empty( $term_failures ) && empty( $attachment_failures ) ? 'rolled_back' : 'partial',
			'product_cleanup_failures'    => $failures,
			'term_cleanup_failures'       => $term_failures,
			'attachment_cleanup_failures' => $attachment_failures,
		);
	}

	/**
	 * Build an initial report shape.
	 *
	 * @param string $status Report status.
	 * @return array<string, mixed>
	 */
	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'   => $status,
			'reason'   => '',
			'counts'   => array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'products' => array(),
		);
	}

	/**
	 * Extract the validator-owned products list from a manifest.
	 *
	 * @param array<string, mixed> $manifest Validated product manifest.
	 * @return array<int, array<string, mixed>>
	 */
	private static function manifest_products( array $manifest ): array {
		$products = isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : $manifest;

		return array_values(
			array_filter(
				$products,
				static fn ( $product ): bool => is_array( $product )
			)
		);
	}

	/**
	 * Determine whether WooCommerce product APIs are available.
	 *
	 * Public so the theme generator can run a dependency gate before commerce
	 * imports proceed without a runtime that can host the seeded products.
	 *
	 * @return bool
	 */
	public static function woocommerce_available(): bool {
		return class_exists( 'WC_Product_Simple' ) && post_type_exists( 'product' ) && taxonomy_exists( 'product_cat' );
	}

	/**
	 * Create or update one product.
	 *
	 * @param array<string, mixed>      $manifest_product     Validated product manifest row.
	 * @param array<int, int>           $created_terms        Term ids created so far this run, by reference.
	 * @param array<string, mixed>|null $before           Prior product state for an existing product.
	 * @param array<string, mixed>      $args                 Import-scoped context (see seed()).
	 * @param array<int, int>           $created_attachments  Attachment ids created so far this run, by reference.
	 * @param array<string, array>      $created_attributes   Global attributes and attribute terms created so far this run, by reference.
	 * @return array<string, mixed>
	 */
	private static function seed_product( array $manifest_product, array &$created_terms = array(), ?array $before = null, array $args = array(), array &$created_attachments = array(), array &$created_attributes = array(), array &$created_variations = array() ): array {
		$slug = sanitize_title( self::string_value( $manifest_product, 'slug' ) );
		$name = self::string_value( $manifest_product, 'name' );

		if ( '' === $slug || '' === $name ) {
			return array(
				'slug'   => $slug,
				'name'   => $name,
				'status' => 'error',
				'error'  => 'validated product row is missing slug or name',
			);
		}

		$existing = get_page_by_path( $slug, OBJECT, 'product' );
		if ( $existing instanceof WP_Post && 'product' !== $existing->post_type ) {
			$existing = null;
		}
		$product = null;
		$status  = 'created';

		if ( $existing instanceof WP_Post && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $existing->ID );
			$status  = 'updated';
		}

		$variants    = $manifest_product['variants'] ?? array();
		$variable    = ! empty( $variants );
		$existing_id = $existing instanceof WP_Post ? $existing->ID : 0;
		if ( $variable ) {
			if ( ! $product instanceof WC_Product_Variable ) {
				$product = new WC_Product_Variable( $existing_id );
			}
		} elseif ( ! $product instanceof WC_Product_Simple ) {
			$product = new WC_Product_Simple( $existing_id );
		}

		$product_id          = 0;
		$created_term_offset = count( $created_terms );
		$attribute_offsets   = array( count( $created_attributes['attributes'] ?? array() ), count( $created_attributes['terms'] ?? array() ) );
		$losses              = $manifest_product['losses'] ?? array();
		$variation_offset    = count( $created_variations );
		try {
			self::apply_sku( $product, $manifest_product, $losses );
			$product->set_name( $name );
			$product->set_slug( $slug );
			$product->set_status( self::post_status( $manifest_product ) );
			$product->set_description( wp_kses_post( self::string_value( $manifest_product, 'description' ) ) );
			$product->set_short_description( wp_kses_post( self::string_value( $manifest_product, 'short_description' ) ) );
			$product->set_regular_price( self::price_value( $manifest_product, 'regular_price' ) );
			$product->set_sale_price( self::price_value( $manifest_product, 'sale_price' ) );

			$stock_status = self::string_value( $manifest_product, 'stock_status' );
			if ( '' !== $stock_status ) {
				$product->set_stock_status( $stock_status );
			}

			if ( array_key_exists( 'stock_quantity', $manifest_product ) && '' !== (string) $manifest_product['stock_quantity'] ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( max( 0, (int) $manifest_product['stock_quantity'] ) );
			}

			$images       = $manifest_product['images'] ?? array();
			$image_source = $images[0]['src'] ?? self::string_value( $manifest_product, 'image' );
			if ( '' !== $image_source ) {
				$attachment_id = self::resolve_product_image_attachment( $image_source, $images[0]['alt'] ?? self::string_value( $manifest_product, 'image_alt' ), $name, $args, $created_attachments );
				if ( $attachment_id > 0 ) {
					$product->set_image_id( $attachment_id );
				} else {
					$losses[] = array(
						'dimension'   => 'product',
						'reason_code' => 'unsupported_control_attribute',
						'attribute'   => 'image',
					);
				}
			}

			if ( ! empty( $images ) ) {
				$gallery_ids = array();
				foreach ( array_slice( $images, 1 ) as $image ) {
					$id = self::resolve_product_image_attachment( $image['src'], $image['alt'], $name, $args, $created_attachments );
					if ( $id > 0 ) {
						$gallery_ids[] = $id;
					} else {
						$losses[] = array(
							'dimension'   => 'product',
							'reason_code' => 'unsupported_control_attribute',
							'attribute'   => 'images',
						);
					}
				}
				$product->set_gallery_image_ids( array_values( array_unique( $gallery_ids ) ) );
			}

			$attribute_rows = $manifest_product['attributes'] ?? array();
			foreach ( $variants as $variant ) {
				$attribute_rows = array_merge( $attribute_rows, $variant['attributes'] );
			}
			if ( $variable || isset( $manifest_product['attributes'] ) ) {
				$attributes = self::product_attributes( $attribute_rows, $created_attributes, $losses );
				foreach ( $variants as $variant ) {
					foreach ( $variant['attributes'] as $attribute ) {
						$taxonomy = wc_attribute_taxonomy_name( wc_sanitize_taxonomy_name( $attribute['key'] ) );
						if ( ! isset( $attributes[ $taxonomy ] ) ) {
							// ssi-error-class: operational
							throw new RuntimeException( 'Variant attribute could not be created.' );
						}
						$attributes[ $taxonomy ]->set_variation( true );
					}
				}
				$product->set_attributes( $attributes );
			}

			$product_id = (int) $product->save();
			if ( $product_id <= 0 ) {
				return array(
					'slug'   => $slug,
					'name'   => $name,
					'status' => 'error',
					'error'  => 'WooCommerce did not return a product ID',
				);
			}

			$category_ids = self::ensure_category_ids( self::category_names( $manifest_product ), $created_terms );
			if ( is_wp_error( $category_ids ) ) {
				// ssi-error-class: propagated
				throw new RuntimeException( $category_ids->get_error_message() );
			}
			if ( ! empty( $category_ids ) ) {
				$assigned = self::assign_product_categories( $product_id, $category_ids );
				if ( is_wp_error( $assigned ) ) {
					// ssi-error-class: propagated
					throw new RuntimeException( $assigned->get_error_message() );
				}
			}

			if ( isset( $manifest_product['tags'] ) ) {
				$tag_ids = array();
				foreach ( $manifest_product['tags'] as $tag ) {
					$term = term_exists( $tag, 'product_tag' );
					if ( ! $term ) {
						$term = wp_insert_term( $tag, 'product_tag' );
						if ( is_wp_error( $term ) ) {
							// ssi-error-class: propagated
							throw new RuntimeException( $term->get_error_message() );
						}
						$created_attributes['terms'][] = array(
							'taxonomy' => 'product_tag',
							'term_id'  => (int) $term['term_id'],
						);
					}
					$tag_ids[] = (int) $term['term_id'];
				}
				$assigned = wp_set_object_terms( $product_id, $tag_ids, 'product_tag' );
				if ( is_wp_error( $assigned ) ) {
					// ssi-error-class: operational
					throw new RuntimeException( 'Product tags could not be assigned.' );
				}
			}
			foreach ( $variants as $variant ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
				$variation_attributes = array();
				foreach ( $variant['attributes'] as $attribute ) {
					$variation_attributes[ wc_attribute_taxonomy_name( wc_sanitize_taxonomy_name( $attribute['key'] ) ) ] = sanitize_title( $attribute['value'] );
				}
				$variation->set_attributes( $variation_attributes );
				$variation->set_regular_price( self::price_value( $variant, 'regular_price' ) );
				$variation->set_sale_price( self::price_value( $variant, 'sale_price' ) );
				$variation->set_stock_status( $variant['stock_status'] );
				self::apply_sku( $variation, $variant, $losses );
				$source = $variant['image'] ?? '';
				if ( '' !== $source && $source !== $image_source ) {
					$id = self::resolve_product_image_attachment( $source, '', $name, $args, $created_attachments );
					if ( $id > 0 ) {
						$variation->set_image_id( $id );
					} else {
						$losses[] = array(
							'dimension'   => 'product',
							'reason_code' => 'unsupported_control_attribute',
							'attribute'   => 'variants.image',
						);
					}
				}
				$id = (int) $variation->save();
				if ( $id <= 0 ) {
					// ssi-error-class: operational
					throw new RuntimeException( 'WooCommerce did not return a variation ID.' );
				}
				$created_variations[] = $id;
			}
			if ( $variable ) {
				WC_Product_Variable::sync( $product_id );
			}

			$row = array(
				'id'           => $product_id,
				'slug'         => $slug,
				'name'         => $name,
				'status'       => $status,
				'category_ids' => $category_ids,
			);
			if ( ! empty( $losses ) ) {
				$row['losses'] = $losses;
			}
			return $row;
		} catch ( Throwable $exception ) {
			$row = array(
				'slug'   => $slug,
				'name'   => $name,
				'status' => 'error',
				'error'  => $exception->getMessage(),
			);
			if ( $product_id > 0 ) {
				$variation_failures = array();
				foreach ( array_reverse( array_slice( $created_variations, $variation_offset ) ) as $id ) {
					if ( ! wp_delete_post( $id, true ) ) {
						$variation_failures[] = 'variation_delete_failed';
					}
				}
				$rollback             = self::restore_product( $product_id, $before );
				$rollback['failures'] = array_merge( $rollback['failures'], $variation_failures );
				$term_failures        = array_merge(
					self::cleanup_terms( array_slice( $created_terms, $created_term_offset ) ),
					self::cleanup_product_attributes(
						array(
							'attributes' => array_slice( $created_attributes['attributes'] ?? array(), $attribute_offsets[0] ),
							'terms'      => array_slice( $created_attributes['terms'] ?? array(), $attribute_offsets[1] ),
						)
					)
				);
				$row['id']            = $product_id;
				$row['compensated']   = empty( $rollback['failures'] ) && empty( $term_failures );
				$row['rollback']      = array(
					'product_failures'      => $rollback['failures'],
					'term_cleanup_failures' => $term_failures,
				);
			}
			return $row;
		}
	}

	/**
	 * A duplicate merchant SKU is a field loss, not a failed product.
	 *
	 * @param WC_Product           $product Product or variation receiving the SKU.
	 * @param array<string, mixed> $row     Manifest product or variant row.
	 * @param array<int, array>    $losses  Field losses, by reference.
	 */
	private static function apply_sku( object $product, array $row, array &$losses ): void {
		if ( ! array_key_exists( 'sku', $row ) ) {
			return;
		}
		try {
			$product->set_sku( $row['sku'] );
		} catch ( WC_Data_Exception $exception ) {
			if ( 'product_invalid_sku' !== $exception->getErrorCode() ) {
				throw $exception;
			}
			$product->set_sku( '' );
			$losses[] = array(
				'dimension'   => 'product',
				'reason_code' => 'unsupported_control_attribute',
				'attribute'   => 'sku',
				'sku'         => $row['sku'],
			);
		}
	}

	/** Capture state before an existing product can be changed. */
	private static function product_state( int $product_id ): array {
		$attribute_terms = array();
		foreach ( self::attribute_taxonomies() as $taxonomy ) {
			$attribute_terms[ $taxonomy ] = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
		}
		return array(
			'post'            => get_post( $product_id, ARRAY_A ),
			'meta'            => get_post_meta( $product_id ),
			'terms'           => wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ),
			'attribute_terms' => $attribute_terms,
			'tags'            => taxonomy_exists( 'product_tag' ) ? wp_get_object_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) ) : array(),
			'product_type'    => taxonomy_exists( 'product_type' ) ? wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'ids' ) ) : array(),
		);
	}

	/** @return array<int,string> Registered global product attribute taxonomies. */
	private static function attribute_taxonomies(): array {
		return function_exists( 'wc_get_attribute_taxonomy_names' ) ? array_values( array_filter( (array) wc_get_attribute_taxonomy_names(), 'taxonomy_exists' ) ) : array();
	}

	/** Restore a product or remove a newly created one, reporting every failed compensation step. */
	private static function restore_product( int $product_id, ?array $before ): array {
		$failures = array();
		if ( ! is_array( $before ) ) {
			$deleted = wp_delete_post( $product_id, true );
			if ( ! $deleted ) {
				$failures[] = 'product_delete_failed'; }
			return array( 'failures' => $failures );
		}
		$updated = wp_update_post( $before['post'], true );
		if ( is_wp_error( $updated ) ) {
			$failures[] = 'product_restore_failed'; }
		foreach ( get_post_meta( $product_id ) as $key => $_ ) {
			delete_post_meta( $product_id, $key ); }
		// get_post_meta() returns stored values still serialized; add_post_meta()
		// would serialize them again, turning array meta such as
		// `_product_attributes` into a string.
		foreach ( $before['meta'] as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $product_id, $key, maybe_unserialize( $value ) ); }
		}
		$terms = self::assign_product_categories( $product_id, $before['terms'] );
		if ( is_wp_error( $terms ) ) {
			$failures[] = 'product_terms_restore_failed'; }
		foreach ( array(
			'product_tag'  => 'tags',
			'product_type' => 'product_type',
		) as $taxonomy => $key ) {
			if ( taxonomy_exists( $taxonomy ) && is_wp_error( wp_set_object_terms( $product_id, array_map( 'intval', $before[ $key ] ?? array() ), $taxonomy ) ) ) {
				$failures[] = 'product_terms_restore_failed';
			}
		}
		foreach ( self::attribute_taxonomies() as $taxonomy ) {
			$restored = wp_set_object_terms( $product_id, array_map( 'intval', (array) ( $before['attribute_terms'][ $taxonomy ] ?? array() ) ), $taxonomy );
			if ( is_wp_error( $restored ) ) {
				$failures[] = 'product_attribute_terms_restore_failed';
				break;
			}
		}
		return array( 'failures' => $failures );
	}

	/** Remove newly-created empty categories in reverse creation order. */
	private static function cleanup_terms( array $term_ids ): array {
		$failures = array();
		foreach ( array_reverse( array_unique( array_map( 'intval', $term_ids ) ) ) as $term_id ) {
			$term  = get_term( $term_id, 'product_cat' );
			$count = is_object( $term ) ? ( get_object_vars( $term )['count'] ?? 0 ) : 0;
			if ( ! is_object( $term ) || (int) $count > 0 ) {
				continue; }
			$deleted = wp_delete_term( $term_id, 'product_cat' );
			if ( is_wp_error( $deleted ) || false === $deleted ) {
				$failures[] = $term_id; }
		}
		return $failures;
	}

	/**
	 * Remove the attribute terms, then the global attributes, this run created,
	 * keeping any a product still uses.
	 *
	 * @param array<string,array> $created `attributes` ids and `terms` { taxonomy, term_id } rows.
	 * @return array<int,int|string> Terms or attributes that could not be removed.
	 */
	private static function cleanup_product_attributes( array $created ): array {
		$failures = array();
		foreach ( array_reverse( is_array( $created['terms'] ?? null ) ? $created['terms'] : array() ) as $row ) {
			$term = get_term( (int) ( $row['term_id'] ?? 0 ), (string) ( $row['taxonomy'] ?? '' ) );
			if ( ! is_object( $term ) || (int) ( get_object_vars( $term )['count'] ?? 0 ) > 0 ) {
				continue;
			}
			$deleted = wp_delete_term( (int) $row['term_id'], (string) $row['taxonomy'] );
			if ( is_wp_error( $deleted ) || false === $deleted ) {
				$failures[] = (int) $row['term_id'];
			}
		}
		foreach ( array_reverse( array_map( 'intval', is_array( $created['attributes'] ?? null ) ? $created['attributes'] : array() ) ) as $attribute_id ) {
			$attribute = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( $attribute_id ) : null;
			if ( ! is_object( $attribute ) ) {
				continue;
			}
			$remaining = get_terms(
				array(
					'taxonomy'   => (string) $attribute->slug,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( is_array( $remaining ) && array() !== $remaining ) {
				continue;
			}
			if ( true !== wc_delete_attribute( $attribute_id ) ) {
				$failures[] = 'attribute:' . $attribute_id;
			}
		}
		return $failures;
	}

	/**
	 * A product's attribute values as global WooCommerce attributes: each
	 * attribute key becomes a `pa_<key>` attribute named by its label, and each
	 * value a term of it named by its shopper-facing label, so WooCommerce's
	 * attribute filters can list and filter by them. A term this run creates
	 * also receives an `order` term meta — the position WooCommerce's
	 * `menu_order` term ordering reads — one past the taxonomy's existing
	 * terms, so its values present in first-seen source order instead of name
	 * order; reused terms keep their stored order. A key WooCommerce cannot
	 * use as an attribute slug is reported as a loss.
	 *
	 * @param array<int,array<string,string>> $rows    Validated attribute rows.
	 * @param array<string,array>             $created Created attributes and terms, by reference.
	 * @param array<int,array<string,string>> $losses  Product losses, by reference.
	 * @return array<string,WC_Product_Attribute>
	 */
	private static function product_attributes( array $rows, array &$created, array &$losses ): array {
		$attributes = array();
		foreach ( $rows as $row ) {
			$slug   = wc_sanitize_taxonomy_name( (string) ( $row['key'] ?? '' ) );
			$id     = '' === $slug ? 0 : (int) wc_attribute_taxonomy_id_by_name( $slug );
			$create = 0 === $id && '' !== $slug;
			if ( $create ) {
				$id = wc_create_attribute(
					array(
						'name' => (string) $row['label'],
						'slug' => $slug,
					)
				);
			}
			if ( is_wp_error( $id ) || (int) $id <= 0 ) {
				$losses[] = array(
					'dimension'   => 'product',
					'reason_code' => 'unsupported_control_attribute',
					'attribute'   => 'attributes.' . (string) ( $row['key'] ?? '' ),
				);
				continue;
			}
			$taxonomy = wc_attribute_taxonomy_name( $slug );
			if ( $create ) {
				$created['attributes'][] = (int) $id;
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				// WooCommerce registers attribute taxonomies on init; one created
				// during this request is registered here so its terms can be added.
				register_taxonomy(
					$taxonomy,
					array( 'product' ),
					array(
						'hierarchical' => false,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					)
				);
			}
			$term_slug = sanitize_title( (string) $row['value'] );
			$existing  = term_exists( $term_slug, $taxonomy );
			if ( is_array( $existing ) ) {
				$term_id = (int) $existing['term_id'];
			} else {
				$order = self::next_attribute_term_order( $taxonomy );
				$term  = wp_insert_term( (string) $row['value_label'], $taxonomy, array( 'slug' => $term_slug ) );
				if ( is_wp_error( $term ) ) {
					// ssi-error-class: propagated
					throw new RuntimeException( esc_html( $term->get_error_message() ) );
				}
				$term_id            = (int) $term['term_id'];
				$created['terms'][] = array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				);
				update_term_meta( $term_id, 'order', $order );
			}
			if ( ! isset( $attributes[ $taxonomy ] ) ) {
				$attributes[ $taxonomy ] = new WC_Product_Attribute();
				$attributes[ $taxonomy ]->set_id( (int) $id );
				$attributes[ $taxonomy ]->set_name( $taxonomy );
				$attributes[ $taxonomy ]->set_position( count( $attributes ) - 1 );
				$attributes[ $taxonomy ]->set_visible( true );
				$attributes[ $taxonomy ]->set_variation( false );
			}
			$attributes[ $taxonomy ]->set_options( array_values( array_unique( array_merge( $attributes[ $taxonomy ]->get_options(), array( $term_id ) ) ) ) );
		}
		return $attributes;
	}

	/**
	 * The next first-seen position for a created attribute term: one past the
	 * taxonomy's current highest `order` term meta, or its existing term count
	 * when no term carries one yet. Existing terms are only read, never
	 * written, so a term WooCommerce or an earlier run ordered stays ordered.
	 *
	 * @param string $taxonomy Attribute taxonomy name.
	 * @return int
	 */
	private static function next_attribute_term_order( string $taxonomy ): int {
		$term_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( ! is_array( $term_ids ) ) {
			return 0;
		}
		$highest = null;
		foreach ( $term_ids as $term_id ) {
			$order = get_term_meta( (int) $term_id, 'order', true );
			if ( is_numeric( $order ) ) {
				$highest = null === $highest ? (int) $order : max( $highest, (int) $order );
			}
		}

		return null === $highest ? count( $term_ids ) : $highest + 1;
	}

	/** Remove attachments created by this run, in reverse creation order. */
	private static function cleanup_attachments( array $attachment_ids ): array {
		$failures = array();
		foreach ( array_reverse( array_unique( array_map( 'intval', $attachment_ids ) ) ) as $attachment_id ) {
			if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
				continue;
			}
			$deleted = wp_delete_attachment( $attachment_id, true );
			if ( false === $deleted || null === $deleted ) {
				$failures[] = $attachment_id;
			}
		}
		return $failures;
	}

	/**
	 * Resolve a manifest product image to a WooCommerce-ready attachment id.
	 *
	 * The same source image reused by several products resolves to the same
	 * attachment: an already-materialized attachment for this exact source is
	 * found and reused before anything new is created.
	 *
	 * @param string               $source               Artifact-relative manifest `image` source path.
	 * @param string               $alt                  Manifest `image_alt` text, if any.
	 * @param string               $product_name         Product name, used as a title fallback.
	 * @param array<string, mixed> $args                 Import-scoped context (see seed()).
	 * @param array<int, int>      $created_attachments  Attachment ids created so far this run, by reference.
	 * @return int Attachment post id, or 0 when the image could not be resolved.
	 */
	private static function resolve_product_image_attachment( string $source, string $alt, string $product_name, array $args, array &$created_attachments ): int {
		$existing = self::existing_source_attachment_id( $source );
		if ( $existing > 0 ) {
			self::apply_attachment_alt_text( $existing, $alt );
			return $existing;
		}

		$resolved = is_array( $args['resolved_product_images'][ $source ] ?? null ) ? $args['resolved_product_images'][ $source ] : null;
		$bytes    = is_array( $resolved ) && is_string( $resolved['bytes'] ?? null ) ? $resolved['bytes'] : '';
		if ( '' === $bytes || ! function_exists( 'wp_upload_bits' ) ) {
			return 0;
		}

		$target_path = is_array( $resolved ) && is_string( $resolved['target_path'] ?? null ) && '' !== $resolved['target_path'] ? $resolved['target_path'] : $source;
		$filename    = sanitize_file_name( basename( $target_path ) );
		if ( '' === $filename ) {
			return 0;
		}

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$declared_mime_type = is_array( $resolved ) && is_string( $resolved['mime_type'] ?? null ) ? $resolved['mime_type'] : '';
		$mime_type          = '' !== $declared_mime_type ? $declared_mime_type : (string) wp_check_filetype( $upload['file'] )['type'];
		if ( ! str_starts_with( $mime_type, 'image/' ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime_type,
				'post_title'     => '' !== $product_name ? $product_name : $filename,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( $attachment_id <= 0 ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}

		self::require_admin_media_dependencies();
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		update_post_meta( $attachment_id, self::SOURCE_IMAGE_META_KEY, $source );
		self::apply_attachment_alt_text( $attachment_id, $alt );
		$created_attachments[] = $attachment_id;

		return $attachment_id;
	}

	/** Find an already-materialized attachment for one exact manifest source image. */
	private static function existing_source_attachment_id( string $source ): int {
		if ( '' === $source ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => self::SOURCE_IMAGE_META_KEY,
				'meta_value'    => $source,
			)
		);
		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/** Apply manifest-supplied alt text to an attachment, when present. */
	private static function apply_attachment_alt_text( int $attachment_id, string $alt ): void {
		if ( $attachment_id > 0 && '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		}
	}

	/** Load the WordPress admin media dependencies wp_generate_attachment_metadata() requires. */
	private static function require_admin_media_dependencies(): void {
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			return;
		}
		foreach ( array( 'wp-admin/includes/image.php', 'wp-admin/includes/media.php', 'wp-admin/includes/file.php' ) as $relative ) {
			$file = ABSPATH . $relative;
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Get a string manifest value.
	 *
	 * @param array<string, mixed> $product Product row.
	 * @param string               $key     Field key.
	 * @return string
	 */
	private static function string_value( array $product, string $key ): string {
		$value = $product[ $key ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Get a WooCommerce-compatible price string.
	 *
	 * @param array<string, mixed> $product Product row.
	 * @param string               $key     Field key.
	 * @return string
	 */
	private static function price_value( array $product, string $key ): string {
		$value = self::string_value( $product, $key );
		if ( '' === $value ) {
			return '';
		}

		return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : (string) preg_replace( '/[^0-9.]/', '', $value );
	}

	/**
	 * Resolve the product post status.
	 *
	 * @param array<string, mixed> $product Product row.
	 * @return string
	 */
	private static function post_status( array $product ): string {
		$status = self::string_value( $product, 'status' );
		if ( '' === $status ) {
			$status = self::string_value( $product, 'post_status' );
		}

		return in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish';
	}

	/**
	 * Extract category names from the manifest row.
	 *
	 * @param array<string, mixed> $product Product row.
	 * @return array<int, string>
	 */
	private static function category_names( array $product ): array {
		$categories = $product['categories'] ?? ( $product['category_names'] ?? array() );
		if ( is_string( $categories ) ) {
			$categories = array( $categories );
		}
		if ( ! is_array( $categories ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn ( $category ): string => is_scalar( $category ) ? trim( (string) $category ) : '',
					$categories
				)
			)
		);
	}

	/**
	 * Ensure product categories exist and return term IDs.
	 *
	 * @param array<int, string> $category_names Category names.
	 * @param array<int, int>    $created_terms Created term IDs, updated by reference.
	 * @return array<int, int>|WP_Error
	 */
	private static function ensure_category_ids( array $category_names, array &$created_terms = array() ) {
		$term_ids = array();
		foreach ( $category_names as $category_name ) {
			$term_id = self::ensure_category_id( $category_name, $created_terms );
			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}
			$term_ids[] = $term_id;
		}

		return array_values( array_unique( array_filter( $term_ids ) ) );
	}

	/** @param array<int,int> $created_terms @return int|WP_Error */
	private static function ensure_category_id( string $category_name, array &$created_terms ) {
		/** @var mixed $term */
		$term    = term_exists( $category_name, 'product_cat' );
		$created = false;
		if ( ! is_int( $term ) && ! is_array( $term ) ) {
			$term    = wp_insert_term( $category_name, 'product_cat' );
			$created = true;
			if ( is_wp_error( $term ) ) {
				return $term;
			}
		}
		if ( is_int( $term ) && $term > 0 ) {
			return $term;
		}
		if ( is_array( $term ) && isset( $term['term_id'] ) && is_scalar( $term['term_id'] ) && (int) $term['term_id'] > 0 ) {
			$term_id = (int) $term['term_id'];
			if ( $created ) {
				$created_terms[] = $term_id;
			}
			return $term_id;
		}
		return new WP_Error( 'static_site_importer_product_category_create_failed', 'WooCommerce product category could not be created.' );
	}

	/** @return true|WP_Error */
	private static function assign_product_categories( int $product_id, array $category_ids ) {
		/** @var mixed $assigned */
		$assigned = wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}
		return false === $assigned ? new WP_Error( 'static_site_importer_product_category_assignment_failed', 'WooCommerce product categories could not be assigned.' ) : true;
	}
}
