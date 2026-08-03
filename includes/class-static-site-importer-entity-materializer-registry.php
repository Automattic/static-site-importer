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
	 * Per-capability provider selection contract.
	 *
	 * Each capability declares a default provider, the core setting/option that
	 * overrides it, and the capability-scoped filter consumers use to register or
	 * route to a different adapter (Gravity Forms, CF7, EDD, and so on). The
	 * registry sits behind this so a capability resolves to exactly one adapter.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function capabilities(): array {
		return array(
			'form' => array(
				'default_provider' => 'jetpack',
				'option'           => 'static_site_importer_form_plugin',
				'filter'           => 'ssi_form_plugin',
			),
			'shop' => array(
				'default_provider' => 'woocommerce',
				'option'           => 'static_site_importer_shop_plugin',
				'filter'           => 'ssi_shop_plugin',
			),
		);
	}

	/**
	 * Resolve the selected provider id for a capability.
	 *
	 * Resolution order: capability default, core setting/option override, the
	 * capability-scoped filter, then the cross-capability provider filter.
	 *
	 * @param string $capability Capability key.
	 * @return string
	 */
	public static function provider_for( string $capability ): string {
		$capabilities = self::capabilities();
		$config       = $capabilities[ $capability ] ?? array();
		$provider     = (string) ( $config['default_provider'] ?? '' );

		$option_key = (string) ( $config['option'] ?? '' );
		if ( '' !== $option_key && function_exists( 'get_option' ) ) {
			$stored = get_option( $option_key, '' );
			if ( is_string( $stored ) && '' !== trim( $stored ) ) {
				$provider = trim( $stored );
			}
		}

		$capability_filter = (string) ( $config['filter'] ?? '' );
		if ( '' !== $capability_filter && function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the provider selected for a single materializer capability.
			 *
			 * @param string $provider   Selected provider id.
			 * @param string $capability Capability key.
			 */
			$provider = (string) apply_filters( $capability_filter, $provider, $capability );
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the provider selected for any materializer capability.
			 *
			 * @param string $provider   Selected provider id.
			 * @param string $capability Capability key.
			 */
			$provider = (string) apply_filters( 'ssi_entity_materializer_provider', $provider, $capability );
		}

		return $provider;
	}

	/**
	 * Resolve the registered adapter that serves a capability's selected provider.
	 *
	 * @param string $capability Capability key.
	 * @return array<string,mixed>
	 */
	public static function adapter_for_capability( string $capability ): array {
		$adapters = self::adapters();
		$provider = self::provider_for( $capability );

		$capability_adapters = array();
		foreach ( $adapters as $adapter ) {
			if ( (string) ( $adapter['capability'] ?? '' ) !== $capability ) {
				continue;
			}

			$capability_adapters[] = $adapter;
			if ( (string) ( $adapter['provider'] ?? '' ) === $provider ) {
				return $adapter;
			}
		}

		// No adapter matched the selected provider; fall back to the first
		// adapter registered for the capability so a misconfigured provider does
		// not silently drop materialization.
		return $capability_adapters[0] ?? array();
	}

	/**
	 * Return the adapter that materializes detected forms.
	 *
	 * @return array<string,mixed>
	 */
	public static function form_adapter(): array {
		return self::adapter_for_capability( 'form' );
	}

	/**
	 * Return the adapter that handles product rows.
	 *
	 * @return array<string,mixed>
	 */
	public static function product_adapter(): array {
		$adapter = self::adapter_for_capability( 'shop' );
		return ! empty( $adapter ) ? $adapter : self::adapter( 'woocommerce_simple_product' );
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
	 * Validate an entity manifest and return the validator's native result shape.
	 *
	 * Unlike validate_manifest(), this does not coerce the result to the product
	 * contract, so capability adapters (forms, and future entity types) keep their
	 * own validated keys (e.g. `forms`).
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @param mixed               $data    Manifest data.
	 * @return array<string,mixed>
	 */
	public static function validate_manifest_generic( array $adapter, mixed $data ): array {
		$validator = $adapter['validator'] ?? null;
		if ( is_callable( $validator ) ) {
			$result = call_user_func( $validator, $data );
			if ( is_array( $result ) ) {
				return $result;
			}
		}

		return array(
			'errors' => array(
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
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize( array $adapter, array $manifest ) {
		$materializer = $adapter['materializer'] ?? null;
		if ( is_callable( $materializer ) ) {
			$result = call_user_func( $materializer, $manifest );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_array( $result ) ) {
				return $result;
			}
		}

		$report           = self::new_entity_report( $adapter );
		$report['reason'] = 'materializer_unavailable';
		return $report;
	}

	/** Resolve provider-owned block markup for one canonical entity binding. */
	public static function binding_block_markup( array $adapter, array $entity, array $result ): string {
		$callback = $adapter['binding_callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return '';
		}
		$markup = call_user_func( $callback, $entity, $result );
		return is_string( $markup ) ? trim( $markup ) : '';
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
				$dependency['availability_callback'] ?? null,
				$dependency['preparation_callback'] ?? null
			);
		}

		foreach ( self::companion_dependencies( $adapter ) as $dependency ) {
			$slug = (string) ( $dependency['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$reports[ $slug ] = self::materialize_companion_dependency( $dependency );
		}

		return $reports;
	}

	/**
	 * Build a generated companion-plugin dependency definition from a payload.
	 *
	 * Companion plugins are per-site and generated at import time, so they are not
	 * static adapter entries like the WooCommerce/Jetpack directory slugs. This
	 * builder produces a dependency definition of type `companion_plugin` that the
	 * install path and diagnostics treat as a first-class declared dependency,
	 * distinct from directory slugs.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<string,mixed>
	 */
	public static function companion_plugin_dependency( array $payload ): array {
		$slug        = Static_Site_Importer_Companion_Plugin::plugin_slug( $payload );
		$plugin_file = Static_Site_Importer_Companion_Plugin::plugin_file( $payload );
		$mu_plugin   = ! empty( $payload['mu_plugin'] );

		$dependency = array(
			'type'        => 'companion_plugin',
			'slug'        => $slug,
			'plugin_file' => $plugin_file,
			'mu_plugin'   => $mu_plugin,
			'payload'     => $payload,
		);

		$dependency['availability_callback'] = static function () use ( $dependency ): bool {
			return self::companion_plugin_available( $dependency );
		};

		return $dependency;
	}

	/**
	 * Determine whether a generated companion plugin is installed and active.
	 *
	 * @param array<string,mixed> $dependency Companion dependency definition.
	 * @return bool
	 */
	public static function companion_plugin_available( array $dependency ): bool {
		$plugin_file = (string) ( $dependency['plugin_file'] ?? '' );
		if ( '' === $plugin_file ) {
			return false;
		}

		if ( ! empty( $dependency['mu_plugin'] ) ) {
			if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
				return false;
			}
			return file_exists( rtrim( (string) WPMU_PLUGIN_DIR, '/' ) . '/' . $plugin_file );
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
	}

	/**
	 * Materialize a generated companion-plugin dependency.
	 *
	 * @param array<string,mixed> $dependency Companion dependency definition.
	 * @return array<string,mixed>
	 */
	public static function materialize_companion_dependency( array $dependency ): array {
		$payload = isset( $dependency['payload'] ) && is_array( $dependency['payload'] ) ? $dependency['payload'] : array();
		return Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin(
			$payload,
			$dependency['availability_callback'] ?? null
		);
	}

	/**
	 * Build the dependency report row for a generated companion plugin.
	 *
	 * Mirrors the directory-plugin dependency row shape so the gate/diagnostics
	 * surface a companion the same way they surface WooCommerce/Jetpack, but keys
	 * it by the namespaced companion slug and flags its `generated` source.
	 *
	 * @param array<string,mixed> $dependency Companion dependency definition.
	 * @param bool                $waived     Whether enforcement is waived.
	 * @return array<string,mixed>
	 */
	public static function companion_dependency_row( array $dependency, bool $waived ): array {
		$active = self::companion_plugin_available( $dependency );

		$block_names     = array();
		$island_handles  = array();
		$runtime_scripts = array();
		$payload         = isset( $dependency['payload'] ) && is_array( $dependency['payload'] ) ? $dependency['payload'] : array();
		$scaffold        = empty( $payload ) ? null : Static_Site_Importer_Companion_Plugin::scaffold( $payload );
		if ( is_array( $scaffold ) && isset( $scaffold['block_names'] ) && is_array( $scaffold['block_names'] ) ) {
			$block_names = array_values( array_map( 'strval', $scaffold['block_names'] ) );
		}
		if ( is_array( $scaffold ) && isset( $scaffold['island_handles'] ) && is_array( $scaffold['island_handles'] ) ) {
			$island_handles = array_values( array_map( 'strval', $scaffold['island_handles'] ) );
		}
		if ( is_array( $scaffold ) && isset( $scaffold['runtime_scripts'] ) && is_array( $scaffold['runtime_scripts'] ) ) {
			$runtime_scripts = array_values( $scaffold['runtime_scripts'] );
		}

		return array(
			'type'            => 'companion_plugin',
			'source'          => 'generated',
			'slug'            => (string) ( $dependency['slug'] ?? '' ),
			'plugin_file'     => (string) ( $dependency['plugin_file'] ?? '' ),
			'mu_plugin'       => ! empty( $dependency['mu_plugin'] ),
			'required'        => true,
			'active'          => $active,
			'waived'          => $waived,
			'block_names'     => $block_names,
			// Preserved island JS handles this companion carries + enqueues
			// scoped; lets the gate/diagnostics treat preserved island JS as
			// companion-plugin-carried instead of theme-coupled.
			'island_handles'  => $island_handles,
			'runtime_scripts' => $runtime_scripts,
		);
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
				'id'               => 'woocommerce_simple_product',
				'entity_type'      => 'product',
				'capability'       => 'shop',
				'provider'         => 'woocommerce',
				'label'            => 'WooCommerce simple product',
				'report_key'       => 'product_seeding',
				'waiver_arg'       => 'allow_missing_woocommerce',
				'validator'        => array( self::class, 'validate_woo_products_manifest' ),
				'materializer'     => array( 'Static_Site_Importer_Woo_Product_Seeder', 'seed' ),
				'binding_callback' => array( 'Static_Site_Importer_Woo_Product_Seeder', 'binding_block_markup' ),
				'report_callback'  => array( 'Static_Site_Importer_Woo_Product_Seeder', 'new_report' ),
				'presentation'     => 'Static_Site_Importer_Commerce_Presentation',
				'dependencies'     => array(
					array(
						'type'                  => 'wp_org_plugin',
						'slug'                  => 'woocommerce',
						'plugin_file'           => 'woocommerce/woocommerce.php',
						'availability_callback' => array( 'Static_Site_Importer_Woo_Product_Seeder', 'woocommerce_available' ),
						'missing_apis'          => array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ),
					),
				),
			),
			'jetpack_contact_form'       => array(
				'id'               => 'jetpack_contact_form',
				'entity_type'      => 'form',
				'capability'       => 'form',
				'provider'         => 'jetpack',
				'label'            => 'Jetpack contact form',
				'report_key'       => 'form_seeding',
				'waiver_arg'       => 'allow_missing_jetpack',
				'validator'        => array( self::class, 'validate_forms_manifest' ),
				'materializer'     => array( 'Static_Site_Importer_Form_Seeder', 'seed' ),
				'binding_callback' => array( 'Static_Site_Importer_Form_Seeder', 'binding_block_markup' ),
				'report_callback'  => array( 'Static_Site_Importer_Form_Seeder', 'new_report' ),
				'dependencies'     => array(
					array(
						'type'                  => 'wp_org_plugin',
						'slug'                  => 'jetpack',
						'plugin_file'           => 'jetpack/jetpack.php',
						'availability_callback' => array( 'Static_Site_Importer_Form_Seeder', 'jetpack_forms_available' ),
						'preparation_callback'  => array( 'Static_Site_Importer_Form_Seeder', 'prepare_jetpack_forms_runtime' ),
						'missing_apis'          => array( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form', 'jetpack/contact-form', 'jetpack/field-text' ),
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
	 * Register the frontend presentation for every adapter that declares one.
	 *
	 * Each adapter may name a `Static_Site_Importer_Provider_Presentation`
	 * subclass under its `presentation` key. This keeps a provider fully described
	 * in one place — materialization, binding, reporting, and presentation — and
	 * lets the plugin bootstrap register all of them without hardcoding each.
	 *
	 * @return void
	 */
	public static function register_presentations(): void {
		$registered = array();
		foreach ( self::adapters() as $adapter ) {
			$presentation = isset( $adapter['presentation'] ) ? (string) $adapter['presentation'] : '';
			if ( '' === $presentation || isset( $registered[ $presentation ] ) ) {
				continue;
			}
			if ( ! class_exists( $presentation ) || ! is_subclass_of( $presentation, 'Static_Site_Importer_Provider_Presentation' ) ) {
				continue;
			}

			$registered[ $presentation ] = true;
			call_user_func( array( $presentation, 'register' ) );
		}
	}

	/**
	 * Validate the generated Woo products manifest contract.
	 *
	 * @param mixed $data Decoded JSON data.
	 * @return array{products:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_woo_products_manifest( mixed $data ): array {
		$products   = array();
		$errors     = array();
		$seen_slugs = array();

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
			if ( '' !== $slug && isset( $seen_slugs[ $slug ] ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.slug',
					'message' => 'slug must be unique within one product collection.',
				);
			}
			$seen_slugs[ $slug ] = true;
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
			if ( isset( $product['bindings'] ) ) {
				if ( ! is_array( $product['bindings'] ) || ! array_is_list( $product['bindings'] ) || empty( $product['bindings'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.bindings',
						'message' => 'bindings must be a non-empty list of canonical source-page replacement anchors.',
					);
				} else {
					$bindings = array();
					foreach ( $product['bindings'] as $binding_index => $candidate ) {
						$binding = self::normalize_block_binding( $candidate );
						if ( null === $binding || empty( $binding ) ) {
							$errors[] = array(
								'path'    => $path_prefix . '.bindings[' . $binding_index . ']',
								'message' => 'binding must be a canonical generic/block-binding/v1 source-page replacement anchor.',
							);
							continue;
						}
						$bindings[] = $binding;
					}
					if ( ! empty( $bindings ) ) {
						$summary['bindings'] = $bindings;
					}
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
	 * Validate detected form runtime islands into a normalized forms manifest.
	 *
	 * Each form carries the preserved <form> fallback metadata (action/method
	 * form attributes plus the source control list). A form is only seedable when
	 * it exposes at least one control the provider can map; submit-only forms are
	 * rejected because they cannot reach feature parity.
	 *
	 * @param mixed $data Forms manifest data.
	 * @return array{forms:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_forms_manifest( mixed $data ): array {
		$forms      = array();
		$errors     = array();
		$seen_forms = array();

		if ( ! is_array( $data ) ) {
			return array(
				'forms'  => array(),
				'errors' => array(
					array(
						'path'    => '$',
						'message' => 'forms_manifest must be an object or array of forms.',
					),
				),
			);
		}

		$rows = isset( $data['forms'] ) && is_array( $data['forms'] ) ? $data['forms'] : $data;

		$index = 0;
		foreach ( $rows as $form ) {
			$path_prefix = '$.forms[' . $index . ']';
			++$index;
			if ( ! is_array( $form ) ) {
				$errors[] = array(
					'path'    => $path_prefix,
					'message' => 'Form must be an object.',
				);
				continue;
			}

			$controls = isset( $form['controls'] ) && is_array( $form['controls'] ) ? array_values( array_filter( $form['controls'], 'is_array' ) ) : array();
			$mappable = array_filter(
				$controls,
				static function ( array $control ): bool {
					$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
					$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
					return ! in_array( $type, array( 'submit', 'hidden', 'reset', 'image', 'file', 'button' ), true ) && '' !== ( $type . $tag );
				}
			);

			if ( empty( $mappable ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.controls',
					'message' => 'Form must declare at least one mappable input control.',
				);
				continue;
			}

			$row = array(
				'selector'    => isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '',
				'source_path' => isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '',
				'form'        => isset( $form['form'] ) && is_array( $form['form'] ) ? $form['form'] : array(),
				'controls'    => $controls,
			);
			if ( array_key_exists( 'control_topology', $form ) ) {
				$topology = self::normalize_form_control_topology( $form['control_topology'], count( $controls ) );
				if ( isset( $topology['error'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.control_topology',
						'message' => $topology['error'],
					);
					continue;
				}
				if ( ! array_key_exists( 'topology', $topology ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.control_topology',
						'message' => 'Control topology normalization did not produce a topology.',
					);
					continue;
				}
				$row['control_topology'] = $topology['topology'];
			}
			if ( array_key_exists( 'layout_graph', $form ) ) {
				$graph = self::normalize_computed_layout_graph( $form['layout_graph'] );
				if ( isset( $graph['error'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.layout_graph',
						'message' => $graph['error'],
					);
					continue; }
				if ( ! array_key_exists( 'graph', $graph ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.layout_graph',
						'message' => 'Computed layout normalization did not produce a graph.',
					);
					continue;
				}
				$row['layout_graph'] = $graph['graph'];
			}
			$form_key = $row['source_path'] . "\n" . $row['selector'];
			if ( isset( $seen_forms[ $form_key ] ) ) {
				$errors[] = array(
					'path'    => $path_prefix,
					'message' => 'source_path and selector must identify one unique form.',
				);
				continue;
			}
			$seen_forms[ $form_key ] = true;
			if ( isset( $form['bindings'] ) ) {
				if ( ! is_array( $form['bindings'] ) || ! array_is_list( $form['bindings'] ) || empty( $form['bindings'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.bindings',
						'message' => 'bindings must be a non-empty list of canonical source-page replacement anchors.',
					);
					continue;
				}
				$bindings = array();
				foreach ( $form['bindings'] as $binding_index => $candidate ) {
					$binding = self::normalize_block_binding( $candidate );
					if ( null === $binding || empty( $binding ) ) {
						$errors[] = array(
							'path'    => $path_prefix . '.bindings[' . $binding_index . ']',
							'message' => 'binding must be a canonical generic/block-binding/v1 source-page replacement anchor.',
						);
						continue;
					}
					$bindings[] = $binding;
				}
				if ( ! empty( $bindings ) ) {
					$row['bindings'] = $bindings;
				}
			}
			$forms[] = $row;
		}

		// Forms validate per row: a single unmappable form (for example a
		// submit-only search form) is rejected without discarding the other
		// mappable forms, so partial feature parity is still materialized.
		return array(
			'forms'  => $forms,
			'errors' => $errors,
		);
	}

	/** @return array{graph?:array<string,mixed>,error?:string} */
	private static function normalize_computed_layout_graph( mixed $candidate ): array {
		if ( ! is_array( $candidate ) || 'generic/computed-layout-graph/v1' !== ( $candidate['schema'] ?? null ) || 'source_css_cascade' !== ( $candidate['basis'] ?? null ) || ! is_bool( $candidate['truncated'] ?? null ) || ! is_array( $candidate['limits'] ?? null ) || ! is_int( $candidate['limits']['nodes'] ?? null ) || ! is_int( $candidate['limits']['depth'] ?? null ) || ! is_int( $candidate['limits']['rules_per_node'] ?? null ) || $candidate['limits']['nodes'] < 1 || $candidate['limits']['nodes'] > 128 || $candidate['limits']['depth'] < 0 || $candidate['limits']['depth'] > 8 || $candidate['limits']['rules_per_node'] < 1 || $candidate['limits']['rules_per_node'] > 16 || ! is_array( $candidate['nodes'] ?? null ) || ! array_is_list( $candidate['nodes'] ) || count( $candidate['nodes'] ) > $candidate['limits']['nodes'] || ! is_array( $candidate['variants'] ?? null ) || ! is_array( $candidate['diagnostics'] ?? null ) ) {
			return array( 'error' => 'layout_graph must be a bounded canonical generic/computed-layout-graph/v1 graph.' );
		}
		if ( ! self::has_only_keys( $candidate, array( 'schema', 'basis', 'truncated', 'limits', 'nodes', 'variants', 'diagnostics' ) ) || ! self::has_only_keys( $candidate['limits'], array( 'nodes', 'depth', 'rules_per_node' ) ) ) {
			return array( 'error' => 'layout_graph contains unknown canonical keys.' );
		}
		if ( $candidate['truncated'] ) {
			return array( 'error' => 'layout_graph is truncated.' );
		}
		$seen  = array();
		$nodes = array();
		foreach ( $candidate['nodes'] as $node ) {
			if ( ! is_array( $node ) || ! self::has_only_keys( $node, array( 'id', 'kind', 'parent', 'order', 'source', 'layout', 'provenance' ) ) || ! is_string( $node['id'] ?? null ) || ! preg_match( '/^(?:form|wrapper-[0-9]+|control-[0-9]+)$/D', $node['id'] ) || isset( $seen[ $node['id'] ] ) || ! in_array( $node['kind'] ?? null, array( 'container', 'control' ), true ) || ! is_int( $node['order'] ?? null ) || $node['order'] < 0 || ! is_array( $node['source'] ?? null ) || ! is_array( $node['layout'] ?? null ) || ! is_array( $node['provenance'] ?? null ) ) {
				return array( 'error' => 'layout_graph contains an unsupported canonical node.' );
			}
			$parent = $node['parent'] ?? null;
			if ( null !== $parent && ( ! is_string( $parent ) || ! isset( $seen[ $parent ] ) ) ) {
				return array( 'error' => 'computed_layout_graph parents must precede children.' );
			}
			$source = $node['source'];
			if ( ! self::has_only_keys( $source, array( 'tag', 'id', 'classes' ) ) || ! is_string( $source['tag'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9-]{0,30}$/D', $source['tag'] ) || ( isset($source['id']) && ( ! is_string($source['id']) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $source['id']) ) ) || ! is_array($source['classes'] ?? null) || count($source['classes']) > 8 ) {
				return array( 'error' => 'layout_graph source identity is unsafe.' );
			}
			$layout      = $node['layout'];
			$layout_keys = array( 'display', 'columns', 'rows', 'gap', 'row_gap', 'column_gap', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'column', 'row', 'area', 'item_placement' );
			foreach ( $layout as $field => $value ) {
				if ( ! in_array( $field, $layout_keys, true ) || ( ! is_scalar( $value ) && ! is_array( $value ) ) ) {
					return array( 'error' => 'layout_graph layout facts must use only producer-supported keys.' );
				}
			}
			$clean               = array(
				'id'         => $node['id'],
				'kind'       => $node['kind'],
				'parent'     => $parent,
				'order'      => $node['order'],
				'source'     => array_intersect_key($source, array_flip(array( 'tag', 'id', 'classes' ))),
				'layout'     => array_intersect_key($layout, array_flip($layout_keys)),
				'provenance' => array_slice($node['provenance'], 0, 16),
			);
			$seen[ $node['id'] ] = true;
			$nodes[]             = $clean;
		}
		if ( count( $candidate['variants'] ) > 256 || ! array_is_list( $candidate['variants'] ) ) {
			return array( 'error' => 'layout_graph variants exceed the producer contract bounds.' );
		}
		$variants = array();
		foreach ( $candidate['variants'] as $variant ) {
			if ( ! is_array( $variant ) || ! self::has_only_keys( $variant, array( 'node', 'condition', 'layout_patch', 'precedence', 'provenance' ) ) || ! is_string( $variant['node'] ?? null ) || ! isset( $seen[ $variant['node'] ] ) || ! self::valid_layout_condition( $variant['condition'] ?? null ) || ! is_array( $variant['layout_patch'] ?? null ) || array() === $variant['layout_patch'] || ! is_array( $variant['precedence'] ?? null ) || ! is_array( $variant['provenance'] ?? null ) || count( $variant['provenance'] ) > 16 ) {
				return array( 'error' => 'layout_graph contains an unsupported canonical variant.' );
			}
			foreach ( $variant['layout_patch'] as $property => $value ) {
				if ( ! isset( self::layout_property_map()[ $property ] ) || ! is_string( $value ) || '' === trim( $value ) || ! isset( $variant['precedence'][ self::layout_property_map()[ $property ] ] ) ) {
					return array( 'error' => 'layout_graph variant layout facts are malformed.' );
				}
			}
			foreach ( $variant['precedence'] as $property => $precedence ) {
				if ( ! isset( self::layout_producer_property_map()[ $property ] ) || ! isset( $variant['layout_patch'][ self::layout_producer_property_map()[ $property ] ] ) || ! is_array( $precedence ) || ! self::has_only_keys( $precedence, array( 'source_order', 'specificity', 'important' ) ) || ! is_int( $precedence['source_order'] ?? null ) || ! is_int( $precedence['specificity'] ?? null ) || ! is_bool( $precedence['important'] ?? null ) ) {
					return array( 'error' => 'layout_graph variant precedence is malformed.' );
				}
			}
			foreach ( $variant['provenance'] as $fact ) {
				if ( ! is_array( $fact ) || ! self::has_only_keys( $fact, array( 'source_path', 'source_sha256', 'selector', 'condition', 'properties' ) ) || ! is_string( $fact['source_path'] ?? null ) || ! preg_match( '~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$~D', $fact['source_path'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ?? '' ) || ! is_string( $fact['selector'] ?? null ) || '' === trim( $fact['selector'] ) || strlen( $fact['selector'] ) > 1024 || $fact['condition'] !== $variant['condition'] || ! is_array( $fact['properties'] ?? null ) || array() === $fact['properties'] || count( $fact['properties'] ) > 19 || array_filter( $fact['properties'], static fn( $property ): bool => ! is_string( $property ) || ! isset( self::layout_producer_property_map()[ $property ] ) || ! isset( $variant['layout_patch'][ self::layout_producer_property_map()[ $property ] ] ) ) ) {
					return array( 'error' => 'layout_graph variant provenance is malformed.' );
				}
			}
			$variants[] = $variant;
		}
		return array(
			'graph' => array(
				'schema'      => 'generic/computed-layout-graph/v1',
				'basis'       => $candidate['basis'],
				'truncated'   => false,
				'limits'      => array_intersect_key($candidate['limits'], array_flip(array( 'nodes', 'depth', 'rules_per_node' ))),
				'nodes'       => $nodes,
				'variants'    => $variants,
				'diagnostics' => array_slice($candidate['diagnostics'], 0, 32),
			),
		);
	}

	private static function valid_layout_condition( mixed $condition, int $depth = 0 ): bool {
		if ( ! is_array( $condition ) || $depth > 8 ) {
			return false;
		}
		if ( 'all' === ( $condition['kind'] ?? null ) ) {
			return self::has_only_keys( $condition, array( 'kind', 'conditions' ) ) && is_array( $condition['conditions'] ?? null ) && ! empty( $condition['conditions'] ) && count( $condition['conditions'] ) <= 8 && array_reduce( $condition['conditions'], static fn( bool $valid, $item ): bool => $valid && self::valid_layout_condition( $item, $depth + 1 ), true );
		}
		return self::has_only_keys( $condition, array( 'kind', 'query' ) ) && in_array( $condition['kind'] ?? null, array( 'media', 'container', 'supports' ), true ) && is_string( $condition['query'] ?? null ) && '' !== trim( $condition['query'] ) && strlen( $condition['query'] ) <= 1024;
	}

	/**
	 * Normalize the bounded generic form-control topology without applying any
	 * provider semantics. A truncated or incomplete tree cannot preserve source
	 * parentage, so it is reported instead of falling back to a flat form.
	 *
	 * @return array{topology?:array<string,mixed>,error?:string}
	 */
	private static function normalize_form_control_topology( mixed $candidate, int $control_count ): array {
		if ( ! is_array( $candidate ) || 'generic/form-control-topology/v1' !== ( $candidate['schema'] ?? null ) ) {
			return array( 'error' => 'control_topology must use generic/form-control-topology/v1.' );
		}
		$max_depth = $candidate['max_depth'] ?? null;
		$max_nodes = $candidate['max_nodes'] ?? null;
		$nodes     = $candidate['nodes'] ?? null;
		if ( ! is_int( $max_depth ) || $max_depth < 0 || $max_depth > 8 || ! is_int( $max_nodes ) || $max_nodes < 1 || $max_nodes > 128 || ! is_array( $nodes ) || ! array_is_list( $nodes ) || count( $nodes ) > $max_nodes ) {
			return array( 'error' => 'control_topology exceeds the supported generic bounds.' );
		}
		if ( true === ( $candidate['truncated'] ?? false ) ) {
			return array( 'error' => 'control_topology is truncated and cannot preserve source control parentage.' );
		}
		if ( ! isset( $candidate['truncated'] ) || ! is_bool( $candidate['truncated'] ) ) {
			return array( 'error' => 'control_topology.truncated must be a boolean.' );
		}
		if ( ! self::has_only_keys( $candidate, array( 'schema', 'max_depth', 'max_nodes', 'nodes', 'truncated' ) ) ) {
			return array( 'error' => 'control_topology contains unknown canonical keys.' );
		}

		$normalized = array();
		$seen_ids   = array();
		$controls   = array();
		$orders     = array();
		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) || ! self::has_only_keys( $node, ( $node['kind'] ?? null ) === 'wrapper' ? array( 'id', 'kind', 'parent', 'order', 'depth', 'tag', 'source_id', 'class' ) : array( 'id', 'kind', 'parent', 'order', 'depth', 'control' ) ) || ! is_string( $node['id'] ?? null ) || ! preg_match( '/^(?:wrapper|control)-[A-Za-z0-9_-]{1,80}$/D', $node['id'] ) || isset( $seen_ids[ $node['id'] ] ) || ! in_array( $node['kind'] ?? null, array( 'wrapper', 'control' ), true ) || ! is_int( $node['order'] ?? null ) || $node['order'] < 0 || ! is_int( $node['depth'] ?? null ) || $node['depth'] < 0 || $node['depth'] > $max_depth ) {
				return array( 'error' => 'control_topology contains an unsupported node.' );
			}
			$parent = $node['parent'] ?? null;
			if ( null !== $parent && ( ! is_string( $parent ) || ! isset( $seen_ids[ $parent ] ) ) ) {
				return array( 'error' => 'control_topology nodes must reference an earlier parent.' );
			}
			$parent_key = null === $parent ? '$root' : $parent;
			if ( isset( $orders[ $parent_key ][ $node['order'] ] ) ) {
				return array( 'error' => 'control_topology sibling order must be unique.' );
			}
			if ( null === $parent && 0 !== $node['depth'] ) {
				return array( 'error' => 'control_topology root nodes must have depth zero.' );
			}
			if ( null !== $parent && ( 'wrapper' !== $seen_ids[ $parent ]['kind'] || $node['depth'] !== $seen_ids[ $parent ]['depth'] + 1 ) ) {
				return array( 'error' => 'control_topology node depth and parent must describe a wrapper tree.' );
			}

			$normalized_node = array(
				'id'     => $node['id'],
				'kind'   => $node['kind'],
				'parent' => $parent,
				'order'  => $node['order'],
				'depth'  => $node['depth'],
			);
			if ( 'control' === $node['kind'] ) {
				if ( ! str_starts_with( $node['id'], 'control-' ) || ! is_int( $node['control'] ?? null ) || $node['control'] < 0 || $node['control'] >= $control_count || isset( $controls[ $node['control'] ] ) ) {
					return array( 'error' => 'control_topology control references must be unique flat control indexes.' );
				}
				$controls[ $node['control'] ] = true;
				$normalized_node['control']   = $node['control'];
			} else {
				if ( ! str_starts_with( $node['id'], 'wrapper-' ) ) {
					return array( 'error' => 'control_topology wrapper ids must match their node kind.' );
				}
				foreach ( array( 'tag', 'source_id', 'class' ) as $field ) {
					if ( ! isset( $node[ $field ] ) ) {
						continue;
					}
					$value = $node[ $field ];
					$valid = is_string( $value ) && ( 'tag' === $field ? in_array( $value, array( 'article', 'aside', 'dd', 'div', 'dl', 'dt', 'fieldset', 'footer', 'header', 'label', 'li', 'main', 'nav', 'ol', 'p', 'section', 'span', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul' ), true ) : (bool) preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}(?: [A-Za-z_][A-Za-z0-9_-]{0,79}){0,7}$/D', $value ) );
					if ( ! $valid ) {
						return array( 'error' => 'control_topology presentation hooks must be bounded safe identifiers and supported Gutenberg group tags.' );
					}
					$normalized_node[ $field ] = $value;
				}
			}
			$seen_ids[ $node['id'] ]                 = $normalized_node;
			$orders[ $parent_key ][ $node['order'] ] = true;
			$normalized[]                            = $normalized_node;
		}
		if ( count( $controls ) !== $control_count ) {
			return array( 'error' => 'control_topology must preserve every flat control exactly once.' );
		}

		return array(
			'topology' => array(
				'schema'    => 'generic/form-control-topology/v1',
				'max_depth' => $max_depth,
				'max_nodes' => $max_nodes,
				'nodes'     => $normalized,
				'truncated' => false,
			),
		);
	}

	/** @return array<string,string> */
	private static function layout_property_map(): array {
		return array(
			'display'         => 'display',
			'columns'         => 'grid-template-columns',
			'rows'            => 'grid-template-rows',
			'gap'             => 'gap',
			'row_gap'         => 'row-gap',
			'column_gap'      => 'column-gap',
			'column'          => 'grid-column',
			'row'             => 'grid-row',
			'area'            => 'grid-area',
			'direction'       => 'flex-direction',
			'wrap'            => 'flex-wrap',
			'align_items'     => 'align-items',
			'align_content'   => 'align-content',
			'justify_content' => 'justify-content',
			'align_self'      => 'align-self',
			'justify_self'    => 'justify-self',
			'order'           => 'order',
			'flex'            => 'flex',
			'flex_grow'       => 'flex-grow',
			'flex_shrink'     => 'flex-shrink',
			'flex_basis'      => 'flex-basis',
		);
	}

	/** @return array<string,string> */
	private static function layout_producer_property_map(): array {
		return array_flip( self::layout_property_map() );
	}

	/** @param array<int,string> $allowed */
	private static function has_only_keys( array $candidate, array $allowed ): bool {
		return array() === array_diff( array_keys( $candidate ), $allowed );
	}

	/** @return array<string,mixed>|null */
	private static function normalize_block_binding( mixed $binding ): ?array {
		if ( null === $binding ) {
			return array();
		}
		if ( ! is_array( $binding ) || 'generic/block-binding/v1' !== ( $binding['schema'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! preg_match( '#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[^\x00-\x1f]+$#', $binding['source_path'] ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || strlen( $binding['search_block_markup'] ) > 262144 || ! is_string( $binding['role'] ?? null ) || ! in_array( $binding['role'], array( 'commerce_controls', 'form' ), true ) ) {
			return null;
		}
		$normalized = array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => $binding['source_path'],
			'search_block_markup' => $binding['search_block_markup'],
			'occurrence'          => $binding['occurrence'],
			'role'                => $binding['role'],
		);
		if ( isset( $binding['superseded_runtime_selectors'] ) ) {
			if ( ! is_array( $binding['superseded_runtime_selectors'] ) || array() === $binding['superseded_runtime_selectors'] ) {
				return null;
			}
			$selectors = array_values( array_unique( $binding['superseded_runtime_selectors'] ) );
			foreach ( $selectors as $selector ) {
				if ( ! is_string( $selector ) || '' === trim( $selector ) || strlen( $selector ) > 1024 ) {
					return null;
				}
			}
			$normalized['superseded_runtime_selectors'] = $selectors;
		}
		return $normalized;
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
	 * Return generated companion-plugin dependency definitions for an adapter.
	 *
	 * @param array<string,mixed> $adapter Adapter definition.
	 * @return array<int,array<string,mixed>>
	 */
	private static function companion_dependencies( array $adapter ): array {
		$dependencies = isset( $adapter['dependencies'] ) && is_array( $adapter['dependencies'] ) ? $adapter['dependencies'] : array();
		return array_values(
			array_filter(
				$dependencies,
				static fn ( mixed $dependency ): bool => is_array( $dependency ) && 'companion_plugin' === (string) ( $dependency['type'] ?? '' )
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
