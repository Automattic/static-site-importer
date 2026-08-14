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

	/** Return a Woo-owned cart control for one seeded product binding. */
	public static function binding_block_markup( array $entity, array $result ): string {
		unset( $entity );
		$id = isset( $result['id'] ) ? (int) $result['id'] : 0;
		return $id > 0 ? '<!-- wp:shortcode -->[add_to_cart id="' . $id . '"]<!-- /wp:shortcode -->' : '';
	}

	/** Return fixed Woo shortcode data for a classic runtime binding. */
	public static function binding_classic_render( array $entity, array $result ): array {
		unset( $entity );
		$id = isset( $result['id'] ) ? (int) $result['id'] : 0;
		return $id > 0 ? array(
			'kind'    => 'shortcode',
			'content' => '[add_to_cart id="' . $id . '"]',
		) : array();
	}

	/**
	 * Seed simple products from a validated manifest.
	 *
	 * The manifest contract is owned by the products.json validator. This class only
	 * consumes the normalized array shape after that validation has succeeded.
	 *
	 * @param array<string, mixed> $manifest Validated product manifest.
	 * @return array<string, mixed>
	 */
	public static function seed( array $manifest ): array {
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

		$report['rollback'] = array( 'created_terms' => array() );
		foreach ( $products as $product ) {
			$existing = get_page_by_path( sanitize_title( self::string_value( $product, 'slug' ) ), OBJECT, 'product' );
			$before   = $existing instanceof WP_Post ? self::product_state( (int) $existing->ID ) : null;
			if ( is_array( $before ) ) {
				$report['rollback'][ (int) $existing->ID ] = $before; }
			$row                  = self::seed_product( $product, $report['rollback']['created_terms'], $before );
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
		$term_failures = self::cleanup_terms( $report['rollback']['created_terms'] ?? array() );
		return array(
			'status'                   => empty( $failures ) && empty( $term_failures ) ? 'rolled_back' : 'partial',
			'product_cleanup_failures' => $failures,
			'term_cleanup_failures'    => $term_failures,
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
	 * @param array<string, mixed> $manifest_product Validated product manifest row.
	 * @return array<string, mixed>
	 */
	private static function seed_product( array $manifest_product, array &$created_terms = array(), ?array $before = null ): array {
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

			return array(
				'id'           => $product_id,
				'slug'         => $slug,
				'name'         => $name,
				'status'       => $status,
				'category_ids' => $category_ids,
			);
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
		if ( is_wp_error( $updated ) || ! $updated ) {
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
			$term = get_term( $term_id, 'product_cat' );
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

	/** @return int|WP_Error */
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
