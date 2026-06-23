<?php
/**
 * Entity materializer registry primitives.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers import-time entity validators, dependency requirements, and writers.
 */
class Static_Site_Importer_Entity_Materializer_Registry {

	/**
	 * Return the first adapter that handles product rows.
	 *
	 * @return array<string,mixed>
	 */
	public static function product_adapter(): array {
		return self::adapter( 'woocommerce_simple_product' );
	}

	/**
	 * Return a registered adapter by id.
	 *
	 * @param string $id Adapter id.
	 * @return array<string,mixed>
	 */
	public static function adapter( string $id ): array {
		$adapters = self::adapters();
		return $adapters[ $id ] ?? array();
	}

	/**
	 * Validate an entity manifest through the adapter callback.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @param mixed               $data    Manifest data.
	 * @return array{products:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_manifest( array $adapter, mixed $data ): array {
		$validator = $adapter['validator'] ?? null;
		if ( is_callable( $validator ) ) {
			$result = call_user_func( $validator, $data );
			if ( is_array( $result ) ) {
				return array(
					'products' => isset( $result['products'] ) && is_array( $result['products'] ) ? $result['products'] : array(),
					'errors'   => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array(),
				);
			}
		}

		return array(
			'products' => array(),
			'errors'   => array(
				array(
					'path'    => '$',
					'message' => 'Entity materializer validator is unavailable.',
				),
			),
		);
	}

	/**
	 * Materialize validated entities through the adapter callback.
	 *
	 * @param array<string,mixed> $adapter  Adapter definition.
	 * @param array<string,mixed> $manifest Validated manifest.
	 * @return array<string,mixed>
	 */
	public static function materialize( array $adapter, array $manifest ): array {
		$materializer = $adapter['materializer'] ?? null;
		if ( is_callable( $materializer ) ) {
			$result = call_user_func( $materializer, $manifest );
			if ( is_array( $result ) ) {
				return $result;
			}
		}

		$report           = self::new_entity_report( $adapter );
		$report['reason'] = 'materializer_unavailable';
		return $report;
	}

	/**
	 * Build an adapter-owned empty entity report.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @return array<string,mixed>
	 */
	public static function new_entity_report( array $adapter ): array {
		$report_callback = $adapter['report_callback'] ?? null;
		if ( is_callable( $report_callback ) ) {
			$report = call_user_func( $report_callback );
			if ( is_array( $report ) ) {
				return $report;
			}
		}

		return array(
			'status'   => 'skipped',
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
	 * Ensure adapter plugin dependencies are installed and active.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @return array<string,array<string,mixed>>
	 */
	public static function materialize_plugin_dependencies( array $adapter ): array {
		$reports = array();
		foreach ( self::plugin_dependencies( $adapter ) as $dependency ) {
			$slug = (string) ( $dependency['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$reports[ $slug ] = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
				$slug,
				(string) ( $dependency['plugin_file'] ?? '' ),
				$dependency['availability_callback'] ?? null
			);
		}

		return $reports;
	}

	/**
	 * Build the commerce dependency report rows for an adapter.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @param array<string,mixed> $intent  Detected commerce intent.
	 * @param bool                $waived  Whether dependency enforcement is waived.
	 * @return array<string,array<string,mixed>>
	 */
	public static function dependency_rows( array $adapter, array $intent, bool $waived ): array {
		$rows = array();
		foreach ( self::plugin_dependencies( $adapter ) as $dependency ) {
			$slug = (string) ( $dependency['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$active        = self::dependency_available( $dependency );
			$rows[ $slug ] = array(
				'required'      => true,
				'active'        => $active,
				'sources'       => isset( $intent['sources'] ) && is_array( $intent['sources'] ) ? $intent['sources'] : array(),
				'product_count' => (int) ( $intent['product_count'] ?? 0 ),
				'waived'        => $waived,
				'missing_apis'  => $active ? array() : self::missing_apis( $dependency ),
			);
		}

		return $rows;
	}

	/**
	 * Check whether every required plugin dependency is available.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @return bool
	 */
	public static function dependencies_available( array $adapter ): bool {
		foreach ( self::plugin_dependencies( $adapter ) as $dependency ) {
			if ( ! self::dependency_available( $dependency ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return the first plugin dependency slug for legacy report compatibility.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @return string
	 */
	public static function primary_dependency_slug( array $adapter ): string {
		$dependencies = self::plugin_dependencies( $adapter );
		$dependency   = reset( $dependencies );
		return is_array( $dependency ) && isset( $dependency['slug'] ) ? (string) $dependency['slug'] : '';
	}

	/**
	 * Registered entity materializers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function adapters(): array {
		$adapters = array(
			'woocommerce_simple_product' => array(
				'id'              => 'woocommerce_simple_product',
				'entity_type'     => 'product',
				'label'           => 'WooCommerce simple product',
				'report_key'      => 'product_seeding',
				'waiver_arg'      => 'allow_missing_woocommerce',
				'validator'       => array( self::class, 'validate_woo_products_manifest' ),
				'materializer'    => array( 'Static_Site_Importer_Woo_Product_Seeder', 'seed' ),
				'report_callback' => array( 'Static_Site_Importer_Woo_Product_Seeder', 'new_report' ),
				'dependencies'    => array(
					array(
						'type'                  => 'wp_org_plugin',
						'slug'                  => 'woocommerce',
						'plugin_file'           => 'woocommerce/woocommerce.php',
						'availability_callback' => array( 'Static_Site_Importer_Woo_Product_Seeder', 'woocommerce_available' ),
						'missing_apis'          => array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ),
					),
				),
			),
		);

		/**
		 * Filters registered SSI entity materializers.
		 *
		 * @param array<string,array<string,mixed>> $adapters Adapter definitions keyed by id.
		 */
		/** @var mixed $filtered */
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_entity_materializers', $adapters ) : $adapters;
		return is_array( $filtered ) ? $filtered : $adapters;
	}

	/**
	 * Validate the generated Woo products manifest contract.
	 *
	 * @param mixed $data Decoded JSON data.
	 * @return array{products:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_woo_products_manifest( mixed $data ): array {
		$products = array();
		$errors   = array();

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return array(
				'products' => array(),
				'errors'   => array(
					array(
						'path'    => '$',
						'message' => 'products_manifest must be an object with schema_version and products fields.',
					),
				),
			);
		}

		if ( 1 !== (int) ( $data['schema_version'] ?? 0 ) ) {
			$errors[] = array(
				'path'    => '$.schema_version',
				'message' => 'schema_version must be 1.',
			);
		}
		if ( ! isset( $data['products'] ) || ! is_array( $data['products'] ) || ! array_is_list( $data['products'] ) ) {
			$errors[] = array(
				'path'    => '$.products',
				'message' => 'products must be a JSON array.',
			);
			return array(
				'products' => array(),
				'errors'   => $errors,
			);
		}

		foreach ( $data['products'] as $index => $product ) {
			$path_prefix = '$.products[' . $index . ']';
			if ( ! is_array( $product ) || array_is_list( $product ) ) {
				$errors[] = array(
					'path'    => $path_prefix,
					'message' => 'Product must be an object.',
				);
				continue;
			}

			$name          = self::manifest_string( $product, 'name' );
			$slug          = self::manifest_string( $product, 'slug' );
			$regular_price = self::manifest_string( $product, 'regular_price' );
			$sale_price    = self::manifest_string( $product, 'sale_price', false );
			if ( '' === $name ) {
				$errors[] = array(
					'path'    => $path_prefix . '.name',
					'message' => 'name is required and must be a non-empty string.',
				);
			}
			if ( '' === $slug || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.slug',
					'message' => 'slug is required and must be a lowercase URL slug.',
				);
			}
			if ( '' === $regular_price || ! self::is_manifest_price( $regular_price ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.regular_price',
					'message' => 'regular_price is required and must be a decimal string such as "19.00".',
				);
			}
			if ( '' !== $sale_price && ! self::is_manifest_price( $sale_price ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.sale_price',
					'message' => 'sale_price must be a decimal string such as "15.00" when provided.',
				);
			}
			foreach ( array( 'description', 'short_description', 'status', 'stock_status', 'image' ) as $field ) {
				if ( isset( $product[ $field ] ) && ! is_string( $product[ $field ] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.' . $field,
						'message' => $field . ' must be a string when provided.',
					);
				}
			}
			foreach ( array( 'categories', 'source_selectors' ) as $field ) {
				if ( ! isset( $product[ $field ] ) ) {
					continue;
				}
				$values = self::manifest_string_collection( $product[ $field ] );
				if ( null === $values ) {
					$errors[] = array(
						'path'    => $path_prefix . '.' . $field,
						'message' => $field . ' must be an array of strings when provided.',
					);
					continue;
				}
				foreach ( $values as $value_index => $value ) {
					if ( '' === trim( $value ) ) {
						$errors[] = array(
							'path'    => $path_prefix . '.' . $field . '[' . $value_index . ']',
							'message' => $field . ' entries must be non-empty strings.',
						);
					}
				}
			}
			if ( isset( $product['stock_quantity'] ) && ! is_int( $product['stock_quantity'] ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.stock_quantity',
					'message' => 'stock_quantity must be an integer when provided.',
				);
			}

			$summary = array(
				'name'          => $name,
				'slug'          => $slug,
				'regular_price' => $regular_price,
			);
			foreach ( array( 'sale_price', 'description', 'short_description', 'categories', 'image', 'status', 'stock_status', 'stock_quantity', 'source_selectors' ) as $field ) {
				if ( array_key_exists( $field, $product ) ) {
					$summary[ $field ] = $product[ $field ];
				}
			}
			$products[] = $summary;
		}

		return array(
			'products' => empty( $errors ) ? $products : array(),
			'errors'   => $errors,
		);
	}

	/**
	 * Return plugin dependency definitions.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @return array<int,array<string,mixed>>
	 */
	private static function plugin_dependencies( array $adapter ): array {
		$dependencies = isset( $adapter['dependencies'] ) && is_array( $adapter['dependencies'] ) ? $adapter['dependencies'] : array();
		return array_values(
			array_filter(
				$dependencies,
				static fn ( mixed $dependency ): bool => is_array( $dependency ) && 'wp_org_plugin' === (string) ( $dependency['type'] ?? '' )
			)
		);
	}

	/**
	 * Check one plugin dependency availability callback.
	 *
	 * @param array<string,mixed> $dependency Dependency definition.
	 * @return bool
	 */
	private static function dependency_available( array $dependency ): bool {
		$callback = $dependency['availability_callback'] ?? null;
		return is_callable( $callback ) && true === (bool) call_user_func( $callback );
	}

	/**
	 * Return missing API labels for a dependency.
	 *
	 * @param array<string,mixed> $dependency Dependency definition.
	 * @return array<int,string>
	 */
	private static function missing_apis( array $dependency ): array {
		$apis = isset( $dependency['missing_apis'] ) && is_array( $dependency['missing_apis'] ) ? $dependency['missing_apis'] : array();
		return array_values( array_filter( array_map( 'strval', $apis ) ) );
	}

	/**
	 * Read a string field from a decoded manifest object.
	 *
	 * @param array<string,mixed> $data     Manifest object.
	 * @param string              $key      Field key.
	 * @param bool                $required Whether missing fields should return an empty string.
	 * @return string
	 */
	private static function manifest_string( array $data, string $key, bool $required = true ): string {
		if ( ! array_key_exists( $key, $data ) || ! is_string( $data[ $key ] ) ) {
			return '';
		}

		$value = trim( $data[ $key ] );
		return $required || '' !== $value ? $value : '';
	}

	/**
	 * Normalize list or keyed-map string collections from products_manifest.
	 *
	 * @param mixed $value Raw manifest field value.
	 * @return array<int|string,string>|null
	 */
	private static function manifest_string_collection( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$normalized = array();
		foreach ( $value as $key => $entry ) {
			if ( ! is_string( $entry ) ) {
				return null;
			}
			$normalized[ $key ] = $entry;
		}

		return $normalized;
	}

	/**
	 * Check whether a manifest price uses a stable decimal string format.
	 *
	 * @param string $price Price string.
	 * @return bool
	 */
	private static function is_manifest_price( string $price ): bool {
		return 1 === preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{2})?$/', $price );
	}
}
