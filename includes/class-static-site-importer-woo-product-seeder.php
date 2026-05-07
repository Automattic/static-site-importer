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

		foreach ( $products as $product ) {
			$row                  = self::seed_product( $product );
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
	private static function seed_product( array $manifest_product ): array {
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

			$category_ids = self::ensure_category_ids( self::category_names( $manifest_product ) );
			if ( ! empty( $category_ids ) ) {
				wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
			}

			return array(
				'id'           => $product_id,
				'slug'         => $slug,
				'name'         => $name,
				'status'       => $status,
				'category_ids' => $category_ids,
			);
		} catch ( Throwable $exception ) {
			return array(
				'slug'   => $slug,
				'name'   => $name,
				'status' => 'error',
				'error'  => $exception->getMessage(),
			);
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
	 * @return array<int, int>
	 */
	private static function ensure_category_ids( array $category_names ): array {
		$term_ids = array();
		foreach ( $category_names as $category_name ) {
			$term = term_exists( $category_name, 'product_cat' );
			// term_exists() actually returns 0|null|int|array per WP core; PHPStan narrowing is incorrect here.
			/** @phpstan-ignore-next-line identical.alwaysFalse */
			if ( 0 === $term || null === $term ) {
				$term = wp_insert_term( $category_name, 'product_cat' );
			}

			if ( is_wp_error( $term ) ) {
				continue;
			}

			/** @phpstan-ignore-next-line function.alreadyNarrowedType, isset.offset, booleanAnd.alwaysTrue */
			if ( is_array( $term ) && isset( $term['term_id'] ) ) {
				$term_ids[] = (int) $term['term_id'];
			} elseif ( is_int( $term ) ) {
				$term_ids[] = $term;
			}
		}

		return array_values( array_unique( array_filter( $term_ids ) ) );
	}
}
