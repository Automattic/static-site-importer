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
 * Turns already-validated store manifest products into simple WooCommerce products.
 */
class Static_Site_Importer_Woo_Product_Seeder {

	/** Post meta key that identifies the manifest source image an attachment materializes, for dedup. */
	private const SOURCE_IMAGE_META_KEY = '_static_site_importer_source_image';

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
		unset( $entity );
		$id = isset( $result['id'] ) ? (int) $result['id'] : 0;
		return $id > 0 ? '<!-- wp:shortcode -->[add_to_cart id="' . $id . '" class="ssi-commerce-control"]<!-- /wp:shortcode -->' : '';
	}

	/** Return fixed Woo shortcode data for a classic runtime binding. */
	public static function binding_classic_render( array $entity, array $result ): array {
		unset( $entity );
		$id = isset( $result['id'] ) ? (int) $result['id'] : 0;
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
	private static function product_collection_block_markup( array $product_ids, int $columns, int $query_id ): string {
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

		$attrs_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs ) : json_encode( $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( ! is_string( $attrs_json ) ) {
			return '';
		}

		return '<!-- wp:woocommerce/product-collection ' . $attrs_json . ' -->'
			. '<div class="wp-block-woocommerce-product-collection alignwide ssi-commerce-control">'
			. '<!-- wp:woocommerce/product-template -->'
			. '<!-- wp:woocommerce/product-image {"showSaleBadge":false,"isDescendentOfQueryLoop":true} /-->'
			. '<!-- wp:post-title {"textAlign":"center","level":3,"isLink":true,"fontSize":"medium","__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->'
			. '<!-- wp:woocommerce/product-price {"textAlign":"center","isDescendentOfQueryLoop":true,"fontSize":"small"} /-->'
			. '<!-- wp:woocommerce/product-button {"textAlign":"center","isDescendentOfQueryLoop":true} /-->'
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
			'created_terms'       => array(),
			'created_attachments' => array(),
		);
		foreach ( $products as $product ) {
			$existing = get_page_by_path( sanitize_title( self::string_value( $product, 'slug' ) ), OBJECT, 'product' );
			$before   = $existing instanceof WP_Post ? self::product_state( (int) $existing->ID ) : null;
			if ( is_array( $before ) ) {
				$report['rollback'][ (int) $existing->ID ] = $before; }
			$row                  = self::seed_product( $product, $report['rollback']['created_terms'], $before, $args, $report['rollback']['created_attachments'] );
			$report['products'][] = $row;

			$status = $row['status'] ?? 'error';
			if ( isset( $report['counts'][ $status ] ) ) {
				++$report['counts'][ $status ];
			} else {
				++$report['counts']['error'];
			}
		}

		return $report;
	}

	/** Restore existing product post/meta/category state or delete products created by this receipt. */
	public static function rollback( array $report ): array {
		$failures = array();
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
		$term_failures       = self::cleanup_terms( $report['rollback']['created_terms'] ?? array() );
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
	 * @param array<string, mixed>  $manifest_product     Validated product manifest row.
	 * @param array<int, int>       $created_terms        Term ids created so far this run, by reference.
	 * @param array<string, mixed>|null $before           Prior product state for an existing product.
	 * @param array<string, mixed>  $args                 Import-scoped context (see seed()).
	 * @param array<int, int>       $created_attachments  Attachment ids created so far this run, by reference.
	 * @return array<string, mixed>
	 */
	private static function seed_product( array $manifest_product, array &$created_terms = array(), ?array $before = null, array $args = array(), array &$created_attachments = array() ): array {
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
		$product  = null;
		$status   = 'created';

		if ( $existing instanceof WP_Post && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $existing->ID );
			$status  = 'updated';
		}

		if ( ! $product instanceof WC_Product_Simple ) {
			$product = new WC_Product_Simple();
		}

		$product_id          = 0;
		$created_term_offset = count( $created_terms );
		$losses              = array();
		try {
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

			$image_source = self::string_value( $manifest_product, 'image' );
			if ( '' !== $image_source ) {
				$attachment_id = self::resolve_product_image_attachment( $image_source, self::string_value( $manifest_product, 'image_alt' ), $name, $args, $created_attachments );
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
				throw new RuntimeException( $category_ids->get_error_message() );
			}
			if ( ! empty( $category_ids ) ) {
				$assigned = self::assign_product_categories( $product_id, $category_ids );
				if ( is_wp_error( $assigned ) ) {
					throw new RuntimeException( $assigned->get_error_message() );
				}
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
				$rollback           = self::restore_product( $product_id, $before );
				$term_failures      = self::cleanup_terms( array_slice( $created_terms, $created_term_offset ) );
				$row['id']          = $product_id;
				$row['compensated'] = empty( $rollback['failures'] ) && empty( $term_failures );
				$row['rollback']    = array(
					'product_failures'      => $rollback['failures'],
					'term_cleanup_failures' => $term_failures,
				);
			}
			return $row;
		}
	}

	/** Capture state before an existing product can be changed. */
	private static function product_state( int $product_id ): array {
		return array(
			'post'  => get_post( $product_id, ARRAY_A ),
			'meta'  => get_post_meta( $product_id ),
			'terms' => wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ),
		);
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
		foreach ( $before['meta'] as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $product_id, $key, $value ); }
		}
		$terms = self::assign_product_categories( $product_id, $before['terms'] );
		if ( is_wp_error( $terms ) ) {
			$failures[] = 'product_terms_restore_failed'; }
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
	 * @param string                $source               Artifact-relative manifest `image` source path.
	 * @param string                $alt                  Manifest `image_alt` text, if any.
	 * @param string                $product_name         Product name, used as a title fallback.
	 * @param array<string, mixed>  $args                 Import-scoped context (see seed()).
	 * @param array<int, int>       $created_attachments  Attachment ids created so far this run, by reference.
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
	 * @param array<int, int> $created_terms Created term IDs, updated by reference.
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
