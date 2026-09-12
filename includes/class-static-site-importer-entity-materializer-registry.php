<?php
/**
 * Entity materializer registry primitives.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Form_Fallback_Contract' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-fallback-contract.php';
}
if ( ! class_exists( 'Static_Site_Importer_Provider_Form_Runtime_V1' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
}

/**
 * Registers import-time entity validators, dependency requirements, and writers.
 */
class Static_Site_Importer_Entity_Materializer_Registry {

	private const FORM_CONTROL_TOPOLOGY_MAX_DEPTH = 16;
	private const FAILURE_DIAGNOSTIC_MAX_ROWS     = 10;
	private const FAILURE_DIAGNOSTIC_MAX_BYTES    = 256;
	private const FAILURE_DIAGNOSTIC_SCAN_BUDGET  = 10;

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

		// A configured provider is an explicit security/transaction boundary.
		// Never route it to a different adapter implicitly.
		return array();
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
		return self::adapter_for_capability( 'shop' );
	}

	/** Resolve submission evidence through the selected form provider adapter. */
	public static function submission_evidence_adapter(): array {
		return self::form_adapter();
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

	/** Roll back a provider receipt. Classic transactions fail closed without this contract. */
	public static function rollback( array $adapter, array $report ) {
		$rollback = $adapter['rollback_callback'] ?? null;
		return is_callable( $rollback ) ? call_user_func( $rollback, $report ) : new WP_Error( 'static_site_importer_entity_rollback_unavailable', 'The selected entity provider does not declare rollback support.' );
	}

	/** Return a registered immutable identity for the adapter's rollback behavior. */
	public static function rollback_contract_id( array $adapter ): string {
		$id = $adapter['rollback_contract_id'] ?? null;
		return is_string( $id ) && 1 === preg_match( '~^[a-z0-9][a-z0-9._/-]{2,127}$~', $id ) ? $id : '';
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

	/** Resolve adapter-owned classic server render data without inspecting source HTML. */
	public static function binding_classic_render( array $adapter, array $entity, array $result ): array {
		$callback = $adapter['classic_binding_callback'] ?? null;
		$render   = is_callable( $callback ) ? call_user_func( $callback, $entity, $result ) : array();
		return is_array( $render ) && in_array( $render['kind'] ?? null, array( 'shortcode', 'blocks' ), true ) && is_string( $render['content'] ?? null ) && '' !== trim( $render['content'] ) ? $render : array();
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

	/** Normalize typed runtime declarations into validated provider lifecycle entries. */
	public static function plan_runtime_lifecycle( array $plan, array $args ) {
		$lifecycle    = array(
			'status'       => 'not_requested',
			'dependencies' => array(),
			'entities'     => array(),
			'diagnostics'  => array(),
		);
		$declarations = isset( $plan['runtime_declarations'] ) && is_array( $plan['runtime_declarations'] ) ? $plan['runtime_declarations'] : array();
		foreach ( $declarations as $declaration ) {
			if ( ! is_array( $declaration ) ) {
				continue;
			}
			$kind = (string) ( $declaration['kind'] ?? '' );
			$key  = (string) ( $declaration['reconciliation_identity'] ?? '' );
			if ( 'asset_publication' === $kind ) {
				continue;
			}
			$name       = (string) ( $declaration[ 'entity_collection' === $kind ? 'type' : 'capability' ] ?? '' );
			$capability = self::runtime_declaration_capability( $kind, $name );
			$required   = self::runtime_declaration_is_required( $declaration, $declarations );
			if ( '' === $capability ) {
				if ( $required ) {
					return new WP_Error(
						'static_site_importer_unsupported_required_runtime_declaration',
						'SSI cannot materialize required runtime declaration: ' . $name . '.',
						array(
							'status'         => 'rejected',
							'declaration_id' => $key,
						)
					);
				}
				$lifecycle['diagnostics'][] = array(
					'code'                    => 'unsupported_optional_runtime_declaration',
					'severity'                => 'warning',
					'reconciliation_identity' => $key,
					'message'                 => 'SSI has no configured adapter for optional declaration ' . $name . '.',
				);
				continue;
			}
			$adapter_key = (string) ( $declaration['adapter_key'] ?? $declaration['payload']['adapter_key'] ?? '' );
			$adapter     = '' === $adapter_key ? self::adapter_for_capability( $capability ) : self::adapter( $adapter_key );
			if ( empty( $adapter ) ) {
				return new WP_Error(
					'static_site_importer_runtime_provider_unavailable',
					'SSI has no configured provider for runtime capability: ' . $capability . '.',
					array(
						'status'         => 'rejected',
						'declaration_id' => $key,
					)
				);
			}
			if ( (string) ( $adapter['capability'] ?? '' ) !== $capability ) {
				return new WP_Error(
					'static_site_importer_runtime_adapter_invalid',
					'Runtime declaration adapter does not support its declared capability.',
					array(
						'status'         => 'rejected',
						'declaration_id' => $key,
					)
				);
			}
			if ( 'dependency' === $kind ) {
				$lifecycle['dependencies'][ $key ] = array(
					'adapter'     => $adapter,
					'declaration' => $declaration,
					'required'    => $required,
				);
				continue;
			}
			if ( 'entity_collection' !== $kind ) {
				continue;
			}
			$entities = isset( $declaration['payload']['entities'] ) && is_array( $declaration['payload']['entities'] ) ? $declaration['payload']['entities'] : array();
			if ( 'form' === $capability ) {
				$entities = array_map( static fn( $entity ) => is_array( $entity ) ? self::prepare_form_entity( $entity ) : $entity, $entities );
			}
			$manifest   = 'shop' === $capability ? array(
				'schema_version' => 1,
				'products'       => $entities,
			) : array( 'forms' => $entities );
			$validation = 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ? array( 'errors' => array() ) : self::validate_manifest_generic( $adapter, $manifest );
			if ( ! empty( $validation['errors'] ) ) {
				return new WP_Error(
					'static_site_importer_runtime_entity_invalid',
					'Runtime entity declaration failed SSI provider validation.',
					array(
						'status'         => 'rejected',
						'declaration_id' => $key,
						'errors'         => $validation['errors'],
					)
				);
			}
			// Dependency preparation intentionally defers provider validation until
			// resume, but its checkpoint must still retain every declared entity.
			$normalized_manifest           = 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ? $manifest : ( 'shop' === $capability ? array(
				'schema_version' => 1,
				'products'       => $validation['products'] ?? array(),
			) : array( 'forms' => $validation['forms'] ?? array() ) );
			$lifecycle['entities'][ $key ] = array(
				'adapter'     => $adapter,
				'manifest'    => $normalized_manifest,
				'declaration' => $declaration,
				'required'    => $required,
			);
			if ( ! isset( $lifecycle['dependencies'][ $key ] ) ) {
				$lifecycle['dependencies'][ $key ] = array(
					'adapter'     => $adapter,
					'declaration' => $declaration,
					'required'    => $required,
				);
			}
		}
		if ( isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) && ! empty( $args['products_manifest'] ) ) {
			$adapter    = self::product_adapter();
			$validation = self::validate_manifest_generic( $adapter, $args['products_manifest'] );
			if ( ! empty( $validation['errors'] ) ) {
				return new WP_Error(
					'static_site_importer_products_manifest_invalid',
					'Caller products_manifest failed SSI provider validation.',
					array(
						'status' => 'rejected',
						'errors' => $validation['errors'],
					)
				);
			}
			$lifecycle['dependencies']['caller_override'] = array(
				'adapter'     => $adapter,
				'declaration' => array(
					'reconciliation_identity' => 'caller_override',
					'kind'                    => 'dependency',
				),
			);
			$lifecycle['entities']['caller_override']     = array(
				'adapter'     => $adapter,
				'manifest'    => $args['products_manifest'],
				'declaration' => array(
					'reconciliation_identity' => 'caller_override',
					'kind'                    => 'entity_collection',
				),
			);
			$lifecycle['status']                          = 'caller_override';
		} elseif ( ! empty( $lifecycle['dependencies'] ) || ! empty( $lifecycle['entities'] ) ) {
			$lifecycle['status'] = 'runtime_declarations';
		}
		return $lifecycle;
	}

	private static function runtime_declaration_is_required( array $declaration, array $declarations ): bool {
		$key = (string) ( $declaration['kind'] ?? '' ) . ':' . (string) ( $declaration['type'] ?? $declaration['capability'] ?? '' );
		if ( ! empty( $declaration['required_for'] ) ) {
			return true;
		}
		foreach ( $declarations as $candidate ) {
			if ( is_array( $candidate ) && in_array( $key, $candidate['required_for'] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function runtime_declaration_capability( string $kind, string $name ): string {
		$name = strtolower( $name );
		if ( 'dependency' === $kind && in_array( $name, array( 'shop', 'form' ), true ) ) {
			return $name;
		}
		if ( 'entity_collection' === $kind && in_array( $name, array( 'product', 'products' ), true ) ) {
			return 'shop';
		}
		return 'entity_collection' === $kind && in_array( $name, array( 'form', 'forms' ), true ) ? 'form' : '';
	}

	/** Bounded adapter from canonical binding markup to provider-neutral presentation facts. */
	public static function prepare_form_entity( array $entity ): array {
		$presentations = array();
		$control_shape = self::ordered_form_control_identity( isset( $entity['controls'] ) && is_array( $entity['controls'] ) ? $entity['controls'] : array() );
		$bindings      = isset( $entity['bindings'] ) && is_array( $entity['bindings'] ) ? $entity['bindings'] : array();
		foreach ( $bindings as $binding ) {
			if ( ! is_array( $binding ) || 'generic/block-binding/v1' !== ( $binding['schema'] ?? null ) || 'form' !== ( $binding['role'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || strlen( $binding['search_block_markup'] ) > 262144 ) {
				continue;
			}
			$manifest = Static_Site_Importer_Form_Fallback_Contract::manifest_from_html( $binding['search_block_markup'] );
			if ( self::ordered_form_control_identity( $manifest['controls'] ) !== $control_shape ) {
				return $entity;
			}
			$presentation = Static_Site_Importer_Form_Fallback_Contract::presentation_from_html( $binding['search_block_markup'], is_string( $entity['selector'] ?? null ) ? $entity['selector'] : '', $binding['occurrence'] );
			if ( 'generic/form-presentation/v1' === ( $presentation['schema'] ?? null ) ) {
				$presentations[] = $presentation;
			}
		}
		$presentation_keys = array_map( static fn( array $presentation ): string => hash( 'sha256', (string) wp_json_encode( array_intersect_key( $presentation, array_flip( array( 'context_before', 'context_after', 'interleaved_context', 'submit_presentation', 'textarea_heights', 'textarea_height_omitted_count' ) ) ) ) ), $presentations );
		if ( empty( $presentations ) || 1 !== count( array_unique( $presentation_keys ) ) ) {
			return $entity;
		}
		$controls = isset( $entity['controls'] ) && is_array( $entity['controls'] ) ? $entity['controls'] : array();
		$form     = isset( $entity['form'] ) && is_array( $entity['form'] ) ? $entity['form'] : array();
		foreach ( array( 'context_before', 'context_after', 'submit_presentation' ) as $key ) {
			if ( isset( $presentations[0][ $key ] ) ) {
				$form[ $key ] = $presentations[0][ $key ];
			}
		}
		if ( ! empty( $presentations[0]['interleaved_context'] ) ) {
			$form['interleaved_context'] = true;
		}
		if ( ! empty( $presentations[0]['textarea_height_omitted_count'] ) ) {
			$form['textarea_height_omitted_count'] = (int) $presentations[0]['textarea_height_omitted_count'];
		}
		foreach ( $presentations[0]['textarea_heights'] ?? array() as $index => $height ) {
			if ( isset( $controls[ $index ] ) && is_string( $height ) ) {
				$controls[ $index ]['height'] = $height;
			}
		}
		$entity['form']     = $form;
		$entity['controls'] = $controls;
		return $entity;
	}

	/** @param array<int,mixed> $controls @return array<int,string> */
	private static function ordered_form_control_identity( array $controls ): array {
		return array_map(
			static function ( $control ): string {
				if ( ! is_array( $control ) ) {
					return '';
				}
				$identity = array_map( static fn( string $key ): string => isset( $control[ $key ] ) && is_scalar( $control[ $key ] ) ? strtolower( trim( (string) $control[ $key ] ) ) : '', array( 'tag', 'type', 'name', 'id' ) );
				if ( '' === $identity[1] && in_array( $identity[0], array( 'select', 'textarea' ), true ) ) {
					$identity[1] = $identity[0];
				}
				return hash( 'sha256', implode( ':', $identity ) );
			},
			array_values( $controls )
		);
	}

	/** Project resolver-expanded manifest entities into validated lifecycle manifests. */
	public static function with_resolved_binding_manifests( array $lifecycle, array $resolved ) {
		$manifests = array();
		foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
			$declaration = is_array( $prepared ) && is_array( $prepared['declaration'] ?? null ) ? $prepared['declaration'] : array();
			$payload     = is_array( $declaration['payload'] ?? null ) ? $declaration['payload'] : null;
			if ( ! is_array( $payload ) || 'blocks-engine/runtime-entity-manifest/v1' !== ( $payload['schema'] ?? null ) ) {
				continue;
			}
			$declaration_id = $declaration['reconciliation_identity'] ?? null;
			if ( ! is_string( $id ) || $id !== $declaration_id || ! preg_match( '/^[a-f0-9]{64}$/', $id ) || isset( $manifests[ $id ] ) || 'entity_collection' !== ( $declaration['kind'] ?? null ) || ! is_string( $declaration['type'] ?? null ) || ! is_string( $payload['entity_schema'] ?? null ) || '' === $payload['entity_schema'] ) {
				return self::runtime_entity_resolution_error( 'Runtime entity manifest declaration is malformed or duplicated.' );
			}
			$manifests[ $id ] = array(
				'type'          => $declaration['type'],
				'entity_schema' => $payload['entity_schema'],
			);
		}
		$resolved_declarations = isset( $resolved['runtime_declarations'] ) && is_array( $resolved['runtime_declarations'] ) ? $resolved['runtime_declarations'] : array();
		$expanded              = array();
		if ( ! empty( $manifests ) ) {
			$resolved_manifests = array();
			foreach ( $resolved_declarations as $declaration ) {
				$payload = is_array( $declaration ) && is_array( $declaration['payload'] ?? null ) ? $declaration['payload'] : null;
				if ( ! is_array( $payload ) || 'blocks-engine/runtime-entity-manifest/v1' !== ( $payload['schema'] ?? null ) ) {
					continue;
				}
				$id = $declaration['reconciliation_identity'] ?? null;
				if ( ! is_string( $id ) || ! isset( $manifests[ $id ] ) || isset( $resolved_manifests[ $id ] ) || 'entity_collection' !== ( $declaration['kind'] ?? null ) || ( $declaration['type'] ?? null ) !== $manifests[ $id ]['type'] || ( $payload['entity_schema'] ?? null ) !== $manifests[ $id ]['entity_schema'] ) {
					return self::runtime_entity_resolution_error( 'Runtime entity manifest declaration does not match its lifecycle declaration.' );
				}
				$resolved_manifests[ $id ] = true;
			}
			if ( count( $manifests ) !== count( $resolved_manifests ) || array_diff_key( $manifests, $resolved_manifests ) ) {
				return self::runtime_entity_resolution_error( 'Runtime entity manifest declaration projection is incomplete.' );
			}

			$resolutions = $resolved['runtime_entity_resolution'] ?? null;
			if ( ! is_array( $resolutions ) || ! array_is_list( $resolutions ) ) {
				return self::runtime_entity_resolution_error( 'Runtime entity manifest resolution is missing or malformed.' );
			}
			foreach ( $resolutions as $resolution ) {
				if ( ! is_array( $resolution ) || array( 'reconciliation_identity', 'kind', 'type', 'entity_schema', 'entities' ) !== array_keys( $resolution ) ) {
					return self::runtime_entity_resolution_error( 'Runtime entity manifest resolution has an invalid shape.' );
				}
				$id = $resolution['reconciliation_identity'];
				if ( ! is_string( $id ) || ! isset( $manifests[ $id ] ) || isset( $expanded[ $id ] ) || 'entity_collection' !== $resolution['kind'] || $manifests[ $id ]['type'] !== $resolution['type'] || $manifests[ $id ]['entity_schema'] !== $resolution['entity_schema'] || ! is_array( $resolution['entities'] ) || ! array_is_list( $resolution['entities'] ) ) {
					return self::runtime_entity_resolution_error( 'Runtime entity manifest resolution does not match its declaration.' );
				}
				$expanded[ $id ] = $resolution['entities'];
			}
			if ( count( $manifests ) !== count( $expanded ) || array_diff_key( $manifests, $expanded ) ) {
				return self::runtime_entity_resolution_error( 'Runtime entity manifest resolution is incomplete.' );
			}
		}

		if ( ! is_array( $lifecycle['entities'] ?? null ) ) {
			return self::runtime_entity_resolution_error( 'Runtime entity manifest lifecycle is malformed.' );
		}
		$resolved_direct_entities = array();
		foreach ( $resolved_declarations as $declaration ) {
			$id = is_array( $declaration ) ? (string) ( $declaration['reconciliation_identity'] ?? '' ) : '';
			if ( '' !== $id && ! isset( $manifests[ $id ] ) && 'entity_collection' === ( $declaration['kind'] ?? null ) && is_array( $declaration['payload']['entities'] ?? null ) ) {
				$resolved_direct_entities[ $id ] = $declaration['payload']['entities'];
			}
		}
		foreach ( $lifecycle['entities'] as $id => &$prepared ) {
			if ( ! isset( $manifests[ $id ] ) ) {
				$entities = $resolved_direct_entities[ $id ] ?? null;
				if ( ! is_array( $entities ) || ! is_array( $prepared['manifest'] ?? null ) ) {
					continue; // Direct entity payloads retain their legacy lifecycle behavior.
				}
				$key                = isset( $prepared['manifest']['products'] ) ? 'products' : 'forms';
				$resolved_by_key    = array();
				$canonical_entities = is_array( $prepared['manifest'][ $key ] ?? null ) ? $prepared['manifest'][ $key ] : array();
				foreach ( $entities as $entity ) {
					if ( ! is_array( $entity ) ) {
						continue;
					}
					$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : self::form_entity_key( $entity );
					if ( '' !== $entity_key ) {
						$resolved_by_key[ $entity_key ] = $entity;
					}
				}
				foreach ( $canonical_entities as $index => $entity ) {
					if ( ! is_array( $entity ) ) {
						continue;
					}
					$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : self::form_entity_key( $entity );
					if ( isset( $resolved_by_key[ $entity_key ]['bindings'] ) && is_array( $resolved_by_key[ $entity_key ]['bindings'] ) ) {
						$prepared['manifest'][ $key ][ $index ]['bindings'] = $resolved_by_key[ $entity_key ]['bindings'];
					}
				}
				continue;
			}
			if ( ! is_array( $prepared ) || ! is_array( $prepared['adapter'] ?? null ) ) {
				return self::runtime_entity_resolution_error( 'Runtime entity manifest lifecycle entry is malformed.' );
			}
			$key      = 'shop' === ( $prepared['adapter']['capability'] ?? null ) ? 'products' : 'forms';
			$entities = $expanded[ $id ];
			if ( 'form' === ( $prepared['adapter']['capability'] ?? null ) ) {
				$entities = array_map( static fn( $entity ) => is_array( $entity ) ? self::prepare_form_entity( $entity ) : $entity, $entities );
			}
			$manifest   = 'products' === $key ? array(
				'schema_version' => 1,
				'products'       => $entities,
			) : array( 'forms' => $entities );
			$validation = self::validate_manifest_generic( $prepared['adapter'], $manifest );
			if ( ! empty( $validation['errors'] ) ) {
				return new WP_Error(
					'static_site_importer_runtime_entity_resolution_invalid',
					'Resolver-expanded runtime entities failed SSI provider validation.',
					array(
						'status'         => 'rejected',
						'declaration_id' => $id,
						'errors'         => $validation['errors'],
					)
				);
			}
			$prepared['manifest'] = 'products' === $key ? array(
				'schema_version' => 1,
				'products'       => $validation['products'] ?? array(),
			) : array( 'forms' => $validation['forms'] ?? array() );
		}
		unset( $prepared );
		return $lifecycle;
	}

	private static function runtime_entity_resolution_error( string $message ): WP_Error {
		return new WP_Error( 'static_site_importer_runtime_entity_resolution_invalid', $message, array( 'status' => 'rejected' ) );
	}

	/** Runtime bindings cannot be persisted by the page-ready checkpoint. */
	public static function page_ready_requires_final_hydration( array $lifecycle, array $args ): bool {
		foreach ( $lifecycle['entities'] ?? array() as $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$entities = is_array( $manifest['products'] ?? null ) ? $manifest['products'] : ( is_array( $manifest['forms'] ?? null ) ? $manifest['forms'] : array() );
			foreach ( $entities as $entity ) {
				if ( is_array( $entity ) && ! empty( $entity['bindings'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Materialize all prepared provider entities and retain their receipts. */
	public static function materialize_lifecycle_entities( array $lifecycle, array $args ): array {
		$reports  = array();
		$required = array_filter( $lifecycle['entities'] ?? array(), static fn( array $prepared ): bool => ! empty( $prepared['required'] ) || self::lifecycle_entity_has_bindings( $prepared ) );
		if ( empty( $args['seed_entities'] ) && empty( $required ) ) {
			return array(
				'reports' => $reports,
				'error'   => null,
			);
		}
		foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
			$adapter = $prepared['adapter'];
			if ( ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? '' ) ] ) ) {
				$reports[ $id ] = array(
					'status'   => 'waived',
					'provider' => $adapter['provider'] ?? '',
				);
				continue;
			}
			$report = self::materialize( $adapter, $prepared['manifest'] );
			if ( $report instanceof WP_Error ) {
				$reports[ $id ] = array(
					'status' => 'error',
					'reason' => $report->get_error_code(),
				);
				return array(
					'reports' => $reports,
					'error'   => array(
						'code'        => (string) $report->get_error_code(),
						'message'     => self::failure_message( self::failure_diagnostics( $id, $adapter, $prepared['manifest'], $reports[ $id ] ), $report->get_error_message() ),
						'diagnostics' => self::failure_diagnostics( $id, $adapter, $prepared['manifest'], $reports[ $id ] ),
					),
				);
			}
			$report         = self::normalize_failure_rows( $adapter, $prepared['manifest'], $report );
			$reports[ $id ] = $report;
			$counts         = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
			$expected       = count( is_array( $prepared['manifest']['products'] ?? null ) ? $prepared['manifest']['products'] : ( $prepared['manifest']['forms'] ?? array() ) );
			// A provider that declines one entity reports it as skipped. The compiled
			// source fallback stays at that binding anchor, so the declaration is
			// accounted for whether or not the entity carries a page binding.
			$completed = array_sum( array_map( 'intval', array_intersect_key( $counts, array_flip( array( 'created', 'updated', 'mapped', 'skipped' ) ) ) ) );
			if ( in_array( $report['status'] ?? '', array( 'failed', 'error' ), true ) || ! empty( $counts['failed'] ) || ! empty( $counts['error'] ) || ( ( ! empty( $prepared['required'] ) || self::lifecycle_entity_has_bindings( $prepared ) ) && $completed < $expected ) ) {
				$code        = isset( $report['code'] ) && is_scalar( $report['code'] ) ? (string) $report['code'] : 'static_site_importer_entity_materialization_failed';
				$diagnostics = self::failure_diagnostics( (string) $id, $adapter, $prepared['manifest'], $report );
				$fallback    = isset( $report['error'] ) && is_scalar( $report['error'] ) ? (string) $report['error'] : ( isset( $report['reason'] ) && is_scalar( $report['reason'] ) && '' !== (string) $report['reason'] ? (string) $report['reason'] : 'Runtime entity materialization failed.' );
				return array(
					'reports' => $reports,
					'error'   => array(
						'code'        => $code,
						'message'     => self::failure_message( $diagnostics, $fallback ),
						'diagnostics' => $diagnostics,
					),
				);
			}
		}
		return array(
			'reports' => $reports,
			'error'   => null,
		);
	}

	/** Build shallow, adapter-neutral evidence for a terminal provider failure. */
	public static function failure_diagnostics( string $declaration_id, array $adapter, array $manifest, array $report ): array {
		$rows        = is_array( $report['failure_rows'] ?? null ) ? $report['failure_rows'] : array();
		$provider    = self::failure_text( $report['provider'] ?? $adapter['provider'] ?? '', 80 );
		$available   = self::failure_availability( $report );
		$diagnostics = array();
		$scanned     = 0;
		foreach ( $rows as $row ) {
			if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
				break;
			}
			if ( ! is_array( $row ) || ! is_array( $row['result'] ?? null ) || ! in_array( $row['result']['status'] ?? '', array( 'error', 'failed' ), true ) ) {
				continue;
			}
			$diagnostics[] = self::failure_diagnostic_row( $declaration_id, $adapter, is_array( $row['entity'] ?? null ) ? $row['entity'] : array(), $row['result'], $provider, $available );
			if ( self::FAILURE_DIAGNOSTIC_MAX_ROWS <= count( $diagnostics ) ) {
				break;
			}
		}
		if ( empty( $diagnostics ) && ( in_array( $report['status'] ?? '', array( 'error', 'failed' ), true ) || ! empty( $report['counts']['error'] ) || ! empty( $report['counts']['failed'] ) ) ) {
			$diagnostics[] = self::failure_diagnostic_row( $declaration_id, $adapter, array(), $report, $provider, $available );
		}
		return $diagnostics;
	}

	/** Normalize adapter-specific result collections into the generic failure row contract. */
	private static function normalize_failure_rows( array $adapter, array $manifest, array $report ): array {
		$declared = $adapter['entity_collection'] ?? '';
		if ( ! is_string( $declared ) || ! is_array( $manifest[ $declared ] ?? null ) || ! is_array( $report[ $declared ] ?? null ) ) {
			return $report;
		}
		$failure_rows = array();
		$scanned      = 0;
		foreach ( $report[ $declared ] as $index => $result ) {
			if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
				break;
			}
			$failure_rows[] = array(
				'entity' => is_array( $manifest[ $declared ][ $index ] ?? null ) ? $manifest[ $declared ][ $index ] : array(),
				'result' => $result,
			);
		}
		$report['failure_rows'] = $failure_rows;
		return $report;
	}

	/** @param array<int,mixed> $diagnostics @return array<int,array<string,mixed>> */
	public static function project_public_diagnostics( array $diagnostics ): array {
		$projected = array();
		$scanned   = 0;
		foreach ( $diagnostics as $diagnostic ) {
			if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
				break;
			}
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$row = self::project_public_diagnostic( $diagnostic );
			if ( ! empty( $row ) ) {
				$projected[] = $row;
			}
			if ( self::FAILURE_DIAGNOSTIC_MAX_ROWS <= count( $projected ) ) {
				break;
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	public static function project_public_error_data( array $data ): array {
		$projected = array();
		foreach ( array( 'status', 'code', 'phase', 'declaration_id' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = self::project_public_token( $data[ $field ], 128 );
				if ( '' !== $value ) {
					$projected[ $field ] = $value;
				}
			}
		}
		$import_id = self::project_public_import_id( $data['import_id'] ?? null );
		if ( '' !== $import_id ) {
			$projected['import_id'] = $import_id;
		}
		foreach ( array( 'success', 'completed' ) as $field ) {
			if ( is_bool( $data[ $field ] ?? null ) ) {
				$projected[ $field ] = $data[ $field ];
			}
		}
		if ( is_bool( $data['resumable'] ?? null ) ) {
			$projected['resumable'] = $data['resumable'];
		}
		if ( is_array( $data['artifact_run'] ?? null ) ) {
			$artifact_run = self::project_public_artifact_run( $data['artifact_run'] );
			if ( ! empty( $artifact_run ) ) {
				$projected['artifact_run'] = $artifact_run;
			}
		}
		if ( is_array( $data['diagnostics'] ?? null ) ) {
			$projected['diagnostics'] = self::project_public_diagnostics( $data['diagnostics'] );
		}
		if ( is_array( $data['import_validation_result']['diagnostics'] ?? null ) ) {
			$projected['import_validation_result'] = array(
				'diagnostics' => self::project_public_diagnostics( $data['import_validation_result']['diagnostics'] ),
			);
		}
		if ( is_array( $data['import_report_summary'] ?? null ) ) {
			$summary = self::project_public_error_summary( $data['import_report_summary'] );
			if ( ! empty( $summary ) ) {
				$projected['import_report_summary'] = $summary;
			}
		}
		if ( is_array( $data['quality'] ?? null ) ) {
			$quality = self::project_public_error_summary( $data['quality'] );
			if ( ! empty( $quality ) ) {
				$projected['quality'] = $quality;
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $artifact_run @return array<string,mixed> */
	private static function project_public_artifact_run( array $artifact_run ): array {
		$projected = array();
		foreach ( array( 'state', 'phase' ) as $field ) {
			if ( isset( $artifact_run[ $field ] ) ) {
				$value = self::project_public_token( $artifact_run[ $field ], 128 );
				if ( '' !== $value ) {
					$projected[ $field ] = $value;
				}
			}
		}
		$artifact_identity = self::project_public_hash( $artifact_run['artifact_identity'] ?? null );
		if ( '' !== $artifact_identity ) {
			$projected['artifact_identity'] = $artifact_identity;
		}
		if ( is_array( $artifact_run['progress'] ?? null ) ) {
			$progress = array();
			foreach ( array( 'page_count', 'prepared_count', 'receipt_count', 'remaining' ) as $field ) {
				if ( is_numeric( $artifact_run['progress'][ $field ] ?? null ) ) {
					$progress[ $field ] = max( 0, (int) $artifact_run['progress'][ $field ] );
				}
			}
			if ( ! empty( $progress ) ) {
				$projected['progress'] = $progress;
			}
		}
		if ( is_array( $artifact_run['work'] ?? null ) ) {
			$work = array();
			foreach ( array( 'content_policy_applications', 'client_script_policy_applications', 'payloads_retained', 'shared_prepares', 'page_prepare_passes', 'page_plans_prepared', 'compile_batches', 'pages_compiled', 'compositions', 'materialization_claims', 'materialization_attempts', 'materializations', 'lifecycle_preparation_claims', 'lifecycle_preparation_attempts', 'lifecycle_preparations' ) as $field ) {
				if ( is_numeric( $artifact_run['work'][ $field ] ?? null ) ) {
					$work[ $field ] = max( 0, (int) $artifact_run['work'][ $field ] );
				}
			}
			if ( ! empty( $work ) ) {
				$projected['work'] = $work;
			}
		}
		if ( is_array( $artifact_run['failures'] ?? null ) ) {
			$failures = array();
			foreach ( array_slice( $artifact_run['failures'], 0, self::FAILURE_DIAGNOSTIC_MAX_ROWS ) as $failure ) {
				if ( ! is_array( $failure ) ) {
					continue;
				}
				$row = array();
				foreach ( array( 'phase', 'exception_class' ) as $field ) {
					$value = self::project_public_token( $failure[ $field ] ?? null, 128 );
					if ( '' !== $value ) {
						$row[ $field ] = $value;
					}
				}
				$artifact_identity = self::project_public_hash( $failure['artifact_identity'] ?? null );
				if ( '' !== $artifact_identity ) {
					$row['artifact_identity'] = $artifact_identity;
				}
				if ( ! empty( $row ) ) {
					$failures[] = $row;
				}
			}
			if ( ! empty( $failures ) ) {
				$projected['failures'] = $failures;
			}
		}
		return $projected;
	}

	/** @param array<string,mixed> $summary @return array<string,mixed> */
	private static function project_public_error_summary( array $summary ): array {
		$projected = array();
		foreach ( array( 'status' ) as $field ) {
			if ( isset( $summary[ $field ] ) ) {
				$value = self::project_public_token( $summary[ $field ], 128 );
				if ( '' !== $value ) {
					$projected[ $field ] = $value;
				}
			}
		}
		foreach ( array( 'quality_pass', 'fail_import', 'pass' ) as $field ) {
			if ( is_bool( $summary[ $field ] ?? null ) ) {
				$projected[ $field ] = $summary[ $field ];
			}
		}
		foreach ( array( 'failure_reasons' ) as $field ) {
			if ( ! is_array( $summary[ $field ] ?? null ) ) {
				continue;
			}
			$values  = array();
			$scanned = 0;
			foreach ( $summary[ $field ] as $value ) {
				if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
					break;
				}
				$value = self::project_public_token( $value, 128 );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
			if ( ! empty( $values ) ) {
				$projected[ $field ] = $values;
			}
		}
		foreach ( array( 'core_html_block_count', 'fallback_count', 'diagnostic_count', 'loss_count' ) as $field ) {
			if ( is_numeric( $summary[ $field ] ?? null ) ) {
				$projected[ $field ] = max( 0, (int) $summary[ $field ] );
			}
		}
		return $projected;
	}

	/** @param array<int,array<string,mixed>> $diagnostics */
	public static function project_public_error_message( string $code, array $diagnostics = array() ): string {
		$source_path = isset( $diagnostics[0]['source_path'] ) ? (string) $diagnostics[0]['source_path'] : '';
		if ( '' !== $source_path ) {
			return 'Materialization failed for ' . $source_path . '.';
		}
		return 'Materialization failed (' . self::project_public_token( $code, 128, 'materialization_failed' ) . ').';
	}

	/** @param array<string,mixed> $diagnostic @return array<string,mixed> */
	private static function project_public_diagnostic( array $diagnostic ): array {
		$row = array();
		foreach ( array( 'code', 'type', 'kind', 'severity', 'declaration_id', 'entity_type', 'provider', 'reason_code', 'provider_availability_reason' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) ) {
				$row[ $field ] = self::project_public_token( $diagnostic[ $field ], 128 );
			}
		}
		if ( isset( $diagnostic['provider_available'] ) && is_bool( $diagnostic['provider_available'] ) ) {
			$row['provider_available'] = $diagnostic['provider_available'];
		}
		if ( isset( $diagnostic['loss_count'] ) && is_numeric( $diagnostic['loss_count'] ) ) {
			$row['loss_count'] = max( 0, (int) $diagnostic['loss_count'] );
		}
		if ( isset( $diagnostic['source_path'] ) ) {
			$source_path = self::project_public_source_path( $diagnostic['source_path'] );
			if ( '' !== $source_path ) {
				$row['source_path'] = $source_path;
			}
		}
		if ( isset( $diagnostic['selector'] ) ) {
			$selector = self::project_public_selector( $diagnostic['selector'] );
			if ( '' !== $selector ) {
				$row['selector'] = $selector;
			}
		}
		if ( isset( $diagnostic['reconciliation_identity'] ) && is_string( $diagnostic['reconciliation_identity'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $diagnostic['reconciliation_identity'] ) ) {
			$row['reconciliation_identity'] = $diagnostic['reconciliation_identity'];
		}
		if ( is_array( $diagnostic['binding_reconciliation_identities'] ?? null ) ) {
			$identities = array();
			$scanned    = 0;
			foreach ( $diagnostic['binding_reconciliation_identities'] as $identity ) {
				if ( self::FAILURE_DIAGNOSTIC_SCAN_BUDGET <= $scanned++ ) {
					break;
				}
				if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
					$identities[] = $identity;
				}
			}
			if ( ! empty( $identities ) ) {
				$row['binding_reconciliation_identities'] = $identities;
			}
		}
		if ( empty( $row ) ) {
			return array();
		}
		if ( empty( $row['code'] ) ) {
			$row['code'] = 'materialization_failed';
		}
		$code           = is_string( $row['code'] ) ? $row['code'] : 'materialization_failed';
		$row['code']    = $code;
		$row['message'] = self::project_public_error_message( $code, array( $row ) );
		return $row;
	}

	private static function project_public_source_path( $value ): string {
		$value = self::project_public_text( $value );
		if ( '' === $value || str_contains( $value, '\\' ) || str_contains( $value, '..' ) || preg_match( '/^[A-Za-z]:|[?#:]/', $value ) || preg_match( '#^(?:https?|file)://#i', $value ) ) {
			return '';
		}
		if ( str_starts_with( $value, '$' ) ) {
			return 1 === preg_match( '/^\$[.\[\]A-Za-z0-9_-]*$/', $value ) ? $value : '';
		}
		if ( str_starts_with( $value, '/' ) ) {
			return '';
		}
		return 1 === preg_match( '#^[\pL\pN][\pL\pN_.\-/]*$#u', $value ) ? $value : '';
	}

	private static function project_public_selector( $value ): string {
		$value = self::project_public_text( $value );
		return 1 === preg_match( '/^[A-Za-z0-9_.#\-\[\]=\s>+~,*]+$/', $value ) ? $value : '';
	}

	private static function project_public_token( $value, int $bytes, string $fallback = '' ): string {
		$value = self::project_public_text( $value, $bytes );
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $value ) ? $value : $fallback;
	}

	private static function project_public_hash( $value ): string {
		$value = self::project_public_text( $value, 64 );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function project_public_import_id( $value ): string {
		$token = self::project_public_token( $value, 128 );
		return '' !== $token ? $token : self::project_public_hash( $value );
	}

	private static function project_public_text( $value, int $bytes = self::FAILURE_DIAGNOSTIC_MAX_BYTES ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( '/(?:password|secret|token|authorization|api[_-]?key|cookie|bearer)\s*[:=]?/i', $value ) || preg_match( '#(?:https?|file)://#i', $value ) || ! preg_match( '//u', $value ) ) {
			return '';
		}
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		preg_match_all( '/./us', $value, $characters );
		$text = '';
		foreach ( $characters[0] as $character ) {
			if ( $bytes < strlen( $text . $character ) ) {
				break;
			}
			$text .= $character;
		}
		return $text;
	}

	private static function failure_diagnostic_row( string $declaration_id, array $adapter, array $entity, array $row, string $provider, ?bool $available ): array {
		$source_path           = self::failure_text( $row['source_path'] ?? $entity['source_path'] ?? '', self::FAILURE_DIAGNOSTIC_MAX_BYTES );
		$selector              = self::failure_text( $row['selector'] ?? $entity['selector'] ?? '', self::FAILURE_DIAGNOSTIC_MAX_BYTES );
		$reason                = self::failure_text( $row['reason_code'] ?? $row['reason'] ?? $row['error_code'] ?? '', 128 );
		$loss_count            = self::failure_loss_count( $row );
		$diagnostic            = array_filter(
			array(
				'code'                         => 'provider_entity_materialization_failed',
				'kind'                         => 'entity_materialization_failure',
				'severity'                     => 'error',
				'declaration_id'               => self::failure_text( $declaration_id, 128 ),
				'entity_type'                  => self::failure_text( $adapter['entity_type'] ?? rtrim( isset( $entity['type'] ) ? (string) $entity['type'] : '', 's' ), 80 ),
				'provider'                     => $provider,
				'provider_available'           => $available,
				'provider_availability_reason' => self::failure_text( $row['provider_availability_reason'] ?? $row['availability_reason'] ?? ( false === $available ? $reason : '' ), 128 ),
				'source_path'                  => $source_path,
				'selector'                     => $selector,
				'reason_code'                  => $reason,
				'loss_count'                   => $loss_count,
			),
			static fn( $value ): bool => null !== $value && '' !== $value
		);
		$location              = '' === $source_path ? 'the declared entity' : $source_path . ( '' === $selector ? '' : ' (' . $selector . ')' );
		$availability          = null === $available ? 'availability was not reported' : ( $available ? 'provider is available' : 'provider is unavailable' );
		$loss                  = null === $loss_count ? '' : ' Losses reported: ' . $loss_count . '.';
		$diagnostic['message'] = self::failure_text( sprintf( '%s failed to materialize %s; %s%s.%s', '' === $provider ? 'The provider' : $provider, $location, $availability, '' === $reason ? '' : '; reason: ' . $reason, $loss ), self::FAILURE_DIAGNOSTIC_MAX_BYTES );
		return $diagnostic;
	}

	private static function failure_message( array $diagnostics, string $fallback ): string {
		return isset( $diagnostics[0]['message'] ) && is_string( $diagnostics[0]['message'] ) ? $diagnostics[0]['message'] : self::failure_text( $fallback, self::FAILURE_DIAGNOSTIC_MAX_BYTES );
	}

	private static function failure_availability( array $report ): ?bool {
		foreach ( array( 'provider_available', 'available' ) as $key ) {
			if ( is_bool( $report[ $key ] ?? null ) ) {
				return $report[ $key ];
			}
		}
		return null;
	}

	private static function failure_loss_count( array $row ): ?int {
		foreach ( array( 'loss_count', 'unaccepted_receipt_loss_count' ) as $key ) {
			if ( is_numeric( $row[ $key ] ?? null ) ) {
				return max( 0, (int) $row[ $key ] );
			}
		}
		return is_array( $row['form_receipt_unaccepted_losses'] ?? null ) ? count( $row['form_receipt_unaccepted_losses'] ) : null;
	}

	private static function failure_text( $value, int $bytes ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( strlen( $value ) <= $bytes || ! preg_match_all( '/./us', $value, $characters ) ) {
			return strlen( $value ) <= $bytes ? $value : '';
		}
		$text = '';
		foreach ( $characters[0] as $character ) {
			if ( $bytes < strlen( $text . $character ) ) {
				break;
			}
			$text .= $character;
		}
		return $text;
	}

	/**
	 * Report whether a provider declined to represent one entity.
	 *
	 * A declined row is a deliberate provider decision -- an unsupported control
	 * topology, or a layout the provider cannot carry without losing fidelity --
	 * not a materialization failure. The page keeps the compiled source markup at
	 * that binding anchor, so the binding is dropped instead of failing the import.
	 * A result row that is absent entirely is still unresolved and stays fatal.
	 *
	 * @param array<string,mixed> $result Provider result row for one entity.
	 */
	public static function entity_result_declined( array $result ): bool {
		return 'skipped' === ( $result['status'] ?? '' );
	}

	/** A canonical page binding makes its provider entity part of materialization. */
	private static function lifecycle_entity_has_bindings( array $prepared ): bool {
		$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
		$entities = is_array( $manifest['products'] ?? null ) ? $manifest['products'] : ( is_array( $manifest['forms'] ?? null ) ? $manifest['forms'] : array() );
		foreach ( $entities as $entity ) {
			if ( is_array( $entity ) && ! empty( $entity['bindings'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Build exact provider-owned block replacements without consulting diagnostics. */
	public static function block_bindings( array $lifecycle, array $reports ) {
		$bindings = array();
		foreach ( $lifecycle['entities'] ?? array() as $declaration_id => $prepared ) {
			$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$report   = is_array( $reports[ $declaration_id ] ?? null ) ? $reports[ $declaration_id ] : array();
			if ( 'waived' === ( $report['status'] ?? '' ) ) {
				continue;
			}
			$entity_key        = isset( $manifest['products'] ) ? 'products' : 'forms';
			$manifest_entities = is_array( $manifest[ $entity_key ] ?? null ) ? $manifest[ $entity_key ] : array();
			$result_entities   = is_array( $report[ $entity_key ] ?? null ) ? $report[ $entity_key ] : array();
			$results           = array();
			foreach ( $result_entities as $result ) {
				if ( is_array( $result ) ) {
					$key             = 'products' === $entity_key ? (string) ( $result['slug'] ?? '' ) : self::form_entity_key( $result );
					$results[ $key ] = $result;
				}
			}
			foreach ( $manifest_entities as $entity ) {
				if ( ! is_array( $entity ) || empty( $entity['bindings'] ) ) {
					continue;
				}
				$key    = 'products' === $entity_key ? (string) ( $entity['slug'] ?? '' ) : self::form_entity_key( $entity );
				$result = is_array( $results[ $key ] ?? null ) ? $results[ $key ] : array();
				if ( self::entity_result_declined( $result ) ) {
					continue;
				}
				$replacement = self::binding_block_markup( $prepared['adapter'], $entity, $result );
				if ( '' === $replacement ) {
					return new WP_Error(
						'static_site_importer_runtime_binding_unresolved',
						'A required provider entity did not produce binding block markup.',
						array(
							'declaration_id' => $declaration_id,
							'entity_key'     => $key,
						)
					);
				}
				foreach ( $entity['bindings'] as $binding ) {
					$bindings[] = array(
						'schema'                           => 'static-site-importer/runtime-entity-binding/v1',
						'source_path'                      => $binding['source_path'],
						'search_block_markup'              => $binding['search_block_markup'],
						'replacement_block_markup'         => $replacement,
						'occurrence'                       => $binding['occurrence'],
						'role'                             => $binding['role'],
						'declaration_id'                   => $declaration_id,
						'reconciliation_identity'          => hash( 'sha256', "static-site-importer/runtime-entity-binding/v1\n{$declaration_id}\n{$binding['source_path']}\n{$binding['occurrence']}\n" . hash( 'sha256', $binding['search_block_markup'] ) ),
						'fallback_reconciliation_identity' => 'form' === $binding['role'] ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $entity ) : '',
						'fallback_hash'                    => 'form' === $binding['role'] ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $entity ) : '',
						'materialized_block_hash'          => 'form' === $binding['role'] ? hash( 'sha256', $replacement ) : '',
						'provider'                         => $prepared['adapter']['provider'] ?? '',
						'superseded_runtime_selectors'     => $binding['superseded_runtime_selectors'] ?? array(),
					);
				}
			}
		}
		return $bindings;
	}

	/** Build classic bindings from canonical entity selectors. */
	public static function classic_bindings( array $lifecycle, array $reports ) {
		$bindings = array();
		foreach ( $lifecycle['entities'] ?? array() as $declaration_id => $prepared ) {
			if ( ! is_callable( $prepared['adapter']['classic_binding_callback'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_classic_provider_render_unavailable', 'Classic provider entity lacks an adapter-owned server render callback.', array( 'declaration_id' => $declaration_id ) );
			}
			$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$report   = is_array( $reports[ $declaration_id ] ?? null ) ? $reports[ $declaration_id ] : array();
			if ( 'waived' === ( $report['status'] ?? '' ) ) {
				continue;
			}
			$key     = isset( $manifest['products'] ) ? 'products' : 'forms';
			$results = array();
			foreach ( $report[ $key ] ?? array() as $result ) {
				if ( is_array( $result ) ) {
					$results[ 'products' === $key ? (string) ( $result['slug'] ?? '' ) : self::form_entity_key( $result ) ] = $result;
				}
			}
			foreach ( $manifest[ $key ] ?? array() as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : self::form_entity_key( $entity );
				$result     = is_array( $results[ $entity_key ] ?? null ) ? $results[ $entity_key ] : array();
				if ( self::entity_result_declined( $result ) ) {
					continue;
				}
				$render    = self::binding_classic_render( $prepared['adapter'], $entity, $result );
				$source    = (string) ( $entity['source_path'] ?? '' );
				$selectors = array_filter( array( $entity['selector'] ?? '' ) );
				if ( empty( $render ) || '' === $source || empty( $selectors ) ) {
					return new WP_Error( 'static_site_importer_classic_html_binding_unresolved', 'A required provider entity lacks adapter-owned server render output or a canonical HTML source selector.', array( 'declaration_id' => $declaration_id ) );
				}
				foreach ( $selectors as $index => $selector ) {
					if ( ! is_string( $selector ) || '' === trim( $selector ) ) {
						return new WP_Error( 'static_site_importer_classic_html_binding_invalid', 'Classic provider source selector is invalid.' );
					}
					$id         = hash( 'sha256', "static-site-importer/classic-html-binding/v1\n{$declaration_id}\n{$source}\n{$selector}\n" . ( $index + 1 ) );
					$bindings[] = array(
						'schema'                  => 'static-site-importer/classic-html-binding/v1',
						'source_path'             => $source,
						'selector'                => $selector,
						'occurrence'              => 1,
						'replacement_html'        => '<div class="static-site-importer-runtime-binding" data-static-site-importer-binding="' . $id . '"><!--static-site-importer-binding:' . $id . '--></div>',
						'render'                  => $render,
						'reconciliation_identity' => $id,
						'declaration_id'          => $declaration_id,
						'provider'                => $prepared['adapter']['provider'] ?? '',
						'status'                  => 'completed',
					);
				}
			}
		}
		return $bindings;
	}

	/** Return a form's producer identity without collapsing responsive variants. */
	private static function form_entity_key( array $form ): string {
		$identity = $form['fallback_identity'] ?? $form['source_fallback_identity'] ?? $form['fallback_reconciliation_identity'] ?? '';
		if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $identity ) ) {
			return $identity;
		}
		return (string) ( $form['source_path'] ?? '' ) . "\n" . (string) ( $form['selector'] ?? '' );
	}

	/** Collect structured overlays emitted by successful form adapters. */
	public static function provider_layout_overlays( array $reports ): array {
		$overlays = array();
		foreach ( $reports as $report ) {
			foreach ( is_array( $report['forms'] ?? null ) ? $report['forms'] : array() as $form ) {
				// A declined form leaves no provider block in the page, so its overlay
				// would target selectors that were never materialized.
				if ( ! empty( $form['runtime_mapped'] ) && is_array( $form['provider_layout_overlay_css'] ?? null ) && ! empty( $form['provider_layout_overlay_css'] ) ) {
					$overlays[] = $form['provider_layout_overlay_css'];
				}
			}
		}
		return $overlays;
	}

	/**
	 * Registered entity materializers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function adapters(): array {
		$adapters = array(
			'woocommerce_simple_product' => array(
				'id'                       => 'woocommerce_simple_product',
				'entity_type'              => 'product',
				'entity_collection'        => 'products',
				'capability'               => 'shop',
				'provider'                 => 'woocommerce',
				'label'                    => 'WooCommerce simple product',
				'report_key'               => 'product_seeding',
				'waiver_arg'               => 'allow_missing_woocommerce',
				'validator'                => array( self::class, 'validate_woo_products_manifest' ),
				'materializer'             => array( 'Static_Site_Importer_Woo_Product_Seeder', 'seed' ),
				'rollback_callback'        => array( 'Static_Site_Importer_Woo_Product_Seeder', 'rollback' ),
				'rollback_contract_id'     => 'static-site-importer/woocommerce-product-rollback/v1',
				'binding_callback'         => array( 'Static_Site_Importer_Woo_Product_Seeder', 'binding_block_markup' ),
				'classic_binding_callback' => array( 'Static_Site_Importer_Woo_Product_Seeder', 'binding_classic_render' ),
				'report_callback'          => array( 'Static_Site_Importer_Woo_Product_Seeder', 'new_report' ),
				'presentation'             => 'Static_Site_Importer_Commerce_Presentation',
				'dependencies'             => array(
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
				'id'                       => 'jetpack_contact_form',
				'entity_type'              => 'form',
				'entity_collection'        => 'forms',
				'capability'               => 'form',
				'provider'                 => 'jetpack',
				'label'                    => 'Jetpack contact form',
				'report_key'               => 'form_seeding',
				'waiver_arg'               => 'allow_missing_jetpack',
				'validator'                => array( self::class, 'validate_forms_manifest' ),
				'materializer'             => array( 'Static_Site_Importer_Form_Seeder', 'seed' ),
				'rollback_callback'        => array( 'Static_Site_Importer_Form_Seeder', 'rollback' ),
				'rollback_contract_id'     => 'static-site-importer/jetpack-form-rollback/v1',
				'binding_callback'         => array( 'Static_Site_Importer_Form_Seeder', 'binding_block_markup' ),
				'classic_binding_callback' => array( 'Static_Site_Importer_Form_Seeder', 'binding_classic_render' ),
				'report_callback'          => array( 'Static_Site_Importer_Form_Seeder', 'new_report' ),
				'submission_evidence'      => array(
					'can_accept_callback' => array( 'Static_Site_Importer_Provider_Submission_Evidence', 'jetpack_can_accept' ),
					'submit'              => array( 'Static_Site_Importer_Provider_Submission_Evidence', 'submit_jetpack' ),
					'cleanup'             => array( 'Static_Site_Importer_Provider_Submission_Evidence', 'cleanup_feedback' ),
				),
				'dependencies'             => array(
					array(
						'type'                  => 'wp_org_plugin',
						'slug'                  => 'jetpack',
						'plugin_file'           => 'jetpack/jetpack.php',
						'availability_callback' => array( 'Static_Site_Importer_Form_Seeder', 'jetpack_forms_available' ),
						'preparation_callback'  => array( 'Static_Site_Importer_Form_Seeder', 'prepare_jetpack_forms_runtime' ),
						'provider_readiness'    => array(
							'required_block_types' => Static_Site_Importer_Form_Seeder::required_block_types(),
							'required_classes'     => Static_Site_Importer_Form_Seeder::required_runtime_apis(),
						),
						'missing_apis'          => array(
							'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form',
							'jetpack/contact-form',
							'jetpack/field-text',
							'jetpack/field-number',
							'jetpack/field-email',
							'jetpack/field-url',
							'jetpack/field-date',
							'jetpack/field-textarea',
							'jetpack/field-select',
							'jetpack/field-checkbox',
							'jetpack/field-radio',
							'jetpack/label',
							'jetpack/input',
							'jetpack/options',
							'jetpack/option',
						),
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
		if ( ! is_array( $filtered ) ) {
			return $adapters;
		}
		foreach ( $filtered as $id => $adapter ) {
			if ( ! is_array( $adapter ) || (string) ( $adapter['id'] ?? '' ) !== (string) $id || '' === self::rollback_contract_id( $adapter ) ) {
				unset( $filtered[ $id ] );
			}
		}
		return $filtered;
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

			$row               = array(
				'selector'    => isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '',
				'source_path' => isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '',
				'form'        => isset( $form['form'] ) && is_array( $form['form'] ) ? $form['form'] : array(),
				'controls'    => $controls,
			);
			$fallback_identity = $form['fallback_identity'] ?? $form['source_fallback_identity'] ?? $form['fallback_reconciliation_identity'] ?? '';
			if ( is_string( $fallback_identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fallback_identity ) ) {
				$row['fallback_identity'] = $fallback_identity;
			}
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
			if ( array_key_exists( 'presentation_graph', $form ) ) {
				$presentation = self::normalize_form_presentation_graph( $form['presentation_graph'] );
				if ( isset( $presentation['error'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.presentation_graph',
						'message' => $presentation['error'],
					);
					continue;
				}
				if ( ! array_key_exists( 'graph', $presentation ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.presentation_graph',
						'message' => 'Form presentation normalization did not produce a graph.',
					);
					continue;
				}
				$row['presentation_graph'] = $presentation['graph'];
			}
			$form_key = self::form_entity_key( $row );
			if ( isset( $seen_forms[ $form_key ] ) ) {
				$errors[] = array(
					'path'    => $path_prefix,
					'message' => 'fallback identity, or source_path and selector when unavailable, must identify one unique form.',
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
		$schema         = is_array( $candidate ) ? ( $candidate['schema'] ?? null ) : null;
		$is_v2          = 'generic/computed-layout-graph/v2' === $schema;
		$expected_depth = $is_v2 ? 16 : 8;
		if ( ! is_array( $candidate ) || ( ! $is_v2 && 'generic/computed-layout-graph/v1' !== $schema ) || 'source_css_cascade' !== ( $candidate['basis'] ?? null ) || ! is_bool( $candidate['truncated'] ?? null ) || ! is_array( $candidate['limits'] ?? null ) || ! is_int( $candidate['limits']['nodes'] ?? null ) || ! is_int( $candidate['limits']['depth'] ?? null ) || ! is_int( $candidate['limits']['rules_per_node'] ?? null ) || $candidate['limits']['nodes'] < 1 || $candidate['limits']['nodes'] > 128 || $expected_depth !== $candidate['limits']['depth'] || $candidate['limits']['rules_per_node'] < 1 || $candidate['limits']['rules_per_node'] > 16 || ! is_array( $candidate['nodes'] ?? null ) || ! array_is_list( $candidate['nodes'] ) || count( $candidate['nodes'] ) > $candidate['limits']['nodes'] || ! is_array( $candidate['variants'] ?? null ) || ! is_array( $candidate['diagnostics'] ?? null ) ) {
			return array( 'error' => 'layout_graph must use a bounded canonical computed-layout graph schema with its exact versioned depth.' );
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
			if ( ! is_array( $node ) || ! self::has_only_keys( $node, array( 'id', 'kind', 'parent', 'order', 'source', 'layout', 'provenance', 'sizing' ) ) || ! is_string( $node['id'] ?? null ) || ! preg_match( '/^(?:form|wrapper-[0-9]+|control-[0-9]+)$/D', $node['id'] ) || isset( $seen[ $node['id'] ] ) || ! in_array( $node['kind'] ?? null, array( 'container', 'control' ), true ) || ! is_int( $node['order'] ?? null ) || $node['order'] < 0 || ! is_array( $node['source'] ?? null ) || ! is_array( $node['layout'] ?? null ) || ! is_array( $node['provenance'] ?? null ) ) {
				return array( 'error' => 'layout_graph contains an unsupported canonical node.' );
			}
			$parent = $node['parent'] ?? null;
			if ( null !== $parent && ( ! is_string( $parent ) || ! isset( $seen[ $parent ] ) ) ) {
				return array( 'error' => 'computed_layout_graph parents must precede children.' );
			}
			$source = $node['source'];
			if ( ! self::has_only_keys( $source, array( 'tag', 'id', 'classes' ) ) || ! is_string( $source['tag'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9-]{0,30}$/D', $source['tag'] ) || ( isset( $source['id'] ) && ( ! is_string( $source['id'] ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $source['id'] ) ) ) || ! is_array( $source['classes'] ?? null ) || count( $source['classes'] ) > 8 ) {
				return array( 'error' => 'layout_graph source identity is unsafe.' );
			}
			$layout      = $node['layout'];
			$layout_keys = array( 'display', 'columns', 'rows', 'gap', 'row_gap', 'column_gap', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'order', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'column', 'row', 'area', 'item_placement' );
			if ( $is_v2 ) {
				array_push( $layout_keys, 'width', 'height', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end' );
			}
			foreach ( $layout as $field => $value ) {
				if ( ! in_array( $field, $layout_keys, true ) || ( ! is_scalar( $value ) && ! is_array( $value ) ) || ( 'width' === $field && ( ! is_string( $value ) || '' === trim( $value ) ) ) ) {
					return array( 'error' => 'layout_graph layout facts must use only producer-supported keys.' );
				}
			}
			$sizing = $node['sizing'] ?? null;
			if ( null !== $sizing && ( ! $is_v2 || ! is_array( $sizing ) || ! self::has_only_keys( $sizing, array( 'kind', 'axis', 'container', 'grid_column' ) ) || 'control' !== $node['kind'] || 'grid_track' !== ( $sizing['kind'] ?? null ) || 'inline' !== ( $sizing['axis'] ?? null ) || ! is_string( $sizing['container'] ?? null ) || $node['parent'] !== $sizing['container'] || ! is_string( $sizing['grid_column'] ?? null ) || '' === trim( $sizing['grid_column'] ) || isset( $layout['width'] ) || ! isset( $seen[ $sizing['container'] ] ) ) ) {
				return array( 'error' => 'layout_graph sizing evidence is malformed.' );
			}
			$clean = array(
				'id'         => $node['id'],
				'kind'       => $node['kind'],
				'parent'     => $parent,
				'order'      => $node['order'],
				'source'     => array_intersect_key( $source, array_flip( array( 'tag', 'id', 'classes' ) ) ),
				'layout'     => array_intersect_key( $layout, array_flip( $layout_keys ) ),
				'provenance' => array_slice( $node['provenance'], 0, 16 ),
			);
			if ( is_array( $sizing ) ) {
				$clean['sizing'] = $sizing;
			}
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
				if ( ! isset( self::layout_property_map( $is_v2 )[ $property ] ) || ! is_string( $value ) || '' === trim( $value ) || ! isset( $variant['precedence'][ self::layout_property_map( $is_v2 )[ $property ] ] ) ) {
					return array( 'error' => 'layout_graph variant layout facts are malformed.' );
				}
			}
			foreach ( $variant['precedence'] as $property => $precedence ) {
				if ( ! isset( self::layout_producer_property_map( $is_v2 )[ $property ] ) || ! isset( $variant['layout_patch'][ self::layout_producer_property_map( $is_v2 )[ $property ] ] ) || ! is_array( $precedence ) || ! self::has_only_keys( $precedence, array( 'source_order', 'specificity', 'important' ) ) || ! is_int( $precedence['source_order'] ?? null ) || ! is_int( $precedence['specificity'] ?? null ) || ! is_bool( $precedence['important'] ?? null ) ) {
					return array( 'error' => 'layout_graph variant precedence is malformed.' );
				}
			}
			foreach ( $variant['provenance'] as $fact ) {
				if ( ! is_array( $fact ) || ! self::has_only_keys( $fact, array( 'source_path', 'source_sha256', 'selector', 'condition', 'properties' ) ) || ! is_string( $fact['source_path'] ?? null ) || ! preg_match( '~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$~D', $fact['source_path'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ?? '' ) || ! is_string( $fact['selector'] ?? null ) || '' === trim( $fact['selector'] ) || strlen( $fact['selector'] ) > 1024 || $fact['condition'] !== $variant['condition'] || ! is_array( $fact['properties'] ?? null ) || array() === $fact['properties'] || count( $fact['properties'] ) > ( $is_v2 ? 20 : 19 ) || array_filter( $fact['properties'], static fn( $property ): bool => ! is_string( $property ) || ! isset( self::layout_producer_property_map( $is_v2 )[ $property ] ) || ! isset( $variant['layout_patch'][ self::layout_producer_property_map( $is_v2 )[ $property ] ] ) ) ) {
					return array( 'error' => 'layout_graph variant provenance is malformed.' );
				}
			}
			$variants[] = $variant;
		}
		return array(
			'graph' => array(
				'schema'      => $schema,
				'basis'       => $candidate['basis'],
				'truncated'   => false,
				'limits'      => array_intersect_key( $candidate['limits'], array_flip( array( 'nodes', 'depth', 'rules_per_node' ) ) ),
				'nodes'       => $nodes,
				'variants'    => $variants,
				'diagnostics' => array_slice( $candidate['diagnostics'], 0, 32 ),
			),
		);
	}

	/** @return array{graph?:array<string,mixed>,error?:string} */
	private static function normalize_form_presentation_graph( mixed $candidate ): array {
		$is_v2         = is_array( $candidate ) && 'generic/computed-form-presentation/v2' === ( $candidate['schema'] ?? null );
		$expected_keys = $is_v2 ? array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'visual_parts', 'visual_groups', 'control_containers', 'variants', 'diagnostics' ) : array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'variants', 'diagnostics' );
		if ( ! is_array( $candidate ) || ( ! $is_v2 && 'generic/computed-form-presentation/v1' !== ( $candidate['schema'] ?? null ) ) || 'source_css_cascade' !== ( $candidate['basis'] ?? null ) || true === ( $candidate['truncated'] ?? null ) || ! is_bool( $candidate['truncated'] ?? null ) || ! self::has_only_keys( $candidate, $expected_keys ) || ! is_array( $candidate['limits'] ?? null ) || ! self::has_only_keys( $candidate['limits'], array( 'controls', 'rules_per_role' ) ) || 128 !== ( $candidate['limits']['controls'] ?? null ) || 32 !== ( $candidate['limits']['rules_per_role'] ?? null ) || ! is_array( $candidate['controls'] ?? null ) || ! array_is_list( $candidate['controls'] ) || count( $candidate['controls'] ) > 128 || ! is_array( $candidate['variants'] ?? null ) || ! array_is_list( $candidate['variants'] ) || count( $candidate['variants'] ) > 256 || ! is_array( $candidate['diagnostics'] ?? null ) || ! array_is_list( $candidate['diagnostics'] ) || count( $candidate['diagnostics'] ) > 32 || ( $is_v2 && ( ! is_array( $candidate['visual_parts'] ?? null ) || ! array_is_list( $candidate['visual_parts'] ) || count( $candidate['visual_parts'] ) > 128 || ! is_array( $candidate['visual_groups'] ?? null ) || ! array_is_list( $candidate['visual_groups'] ) || count( $candidate['visual_groups'] ) > 128 || ! is_array( $candidate['control_containers'] ?? null ) || ! array_is_list( $candidate['control_containers'] ) || count( $candidate['control_containers'] ) > 128 ) ) || array_filter( $candidate['diagnostics'], static fn( $diagnostic ): bool => ! is_string( $diagnostic ) || '' === trim( $diagnostic ) || strlen( $diagnostic ) > 1100 ) ) {
			return array( 'error' => 'presentation_graph must be a complete bounded generic/computed-form-presentation/v1 or v2 graph.' );
		}
		$properties = self::form_presentation_properties();
		$seen       = array();
		$controls   = array();
		foreach ( $candidate['controls'] as $row ) {
			if ( ! is_array( $row ) || ! self::has_only_keys( $row, $is_v2 ? array( 'index', 'control', 'label', 'required_marker' ) : array( 'index', 'control', 'label' ) ) || ! is_int( $row['index'] ?? null ) || $row['index'] < 0 || $row['index'] >= 128 || isset( $seen[ $row['index'] ] ) || ( ! isset( $row['control'] ) && ! isset( $row['label'] ) && ! isset( $row['required_marker'] ) ) ) {
				return array( 'error' => 'presentation_graph contains an unsupported control row.' );
			}
			$clean = array( 'index' => $row['index'] );
			foreach ( $is_v2 ? array( 'control', 'label', 'required_marker' ) : array( 'control', 'label' ) as $role ) {
				if ( ! isset( $row[ $role ] ) ) {
					continue;
				}
				$normalized = self::normalize_form_presentation_role( $row[ $role ], $properties, null, 'required_marker' === $role );
				if ( isset( $normalized['error'] ) ) {
					return $normalized;
				}
				$clean[ $role ] = $normalized['role'];
			}
			$seen[ $row['index'] ] = true;
			$controls[]            = $clean;
		}
		$visual_parts = array();
		$part_indexes = array();
		foreach ( $candidate['visual_parts'] ?? array() as $part ) {
			if ( ! is_array( $part ) || ! self::has_only_keys( $part, array( 'id', 'index', 'kind', 'source_selector', 'markup', 'intrinsic_size', 'source_css' ) ) || ! is_string( $part['id'] ?? null ) || ! preg_match( '/^control-[0-9]+-svg-[0-9]+$/D', $part['id'] ) || isset( $part_indexes[ $part['id'] ] ) || ! is_int( $part['index'] ?? null ) || $part['index'] < 0 || $part['index'] >= 128 || 'inline_svg' !== ( $part['kind'] ?? null ) || ! is_string( $part['source_selector'] ?? null ) || '' === trim( $part['source_selector'] ) || strlen( $part['source_selector'] ) > 2048 || ! is_string( $part['markup'] ?? null ) || strlen( $part['markup'] ) > 16384 || ! Static_Site_Importer_Provider_Form_Runtime_V1::valid_inline_svg( $part['markup'] ) || ! is_array( $part['source_css'] ?? null ) || ! in_array( $part['source_css']['state'] ?? null, array( 'known', 'unknown' ), true ) ) {
				return array( 'error' => 'presentation_graph visual part is malformed or unsafe.' );
			}
			if ( 'known' === $part['source_css']['state'] ) {
				$source_css = self::normalize_form_presentation_role( array(
					'styles'     => $part['source_css']['styles'] ?? null,
					'provenance' => $part['source_css']['provenance'] ?? null,
				), $properties, null );
				if ( isset( $source_css['error'] ) || ! self::has_only_keys( $part['source_css'], array( 'state', 'styles', 'provenance' ) ) ) {
					return array( 'error' => 'presentation_graph visual part source CSS is malformed.' );
				}
				$part['source_css'] = array(
					'state' => 'known',
					...$source_css['role'],
				);
			} elseif ( ! self::has_only_keys( $part['source_css'], array( 'state' ) ) ) {
				return array( 'error' => 'presentation_graph visual part unknown CSS is malformed.' );
			}
			$part_indexes[ $part['id'] ] = $part['index'];
			$visual_parts[]              = array_intersect_key( $part, array_flip( array( 'id', 'index', 'kind', 'source_selector', 'markup', 'intrinsic_size', 'source_css' ) ) );
		}
		$visual_groups = array();
		$group_ids     = array();
		foreach ( $candidate['visual_groups'] ?? array() as $group ) {
			if ( ! is_array( $group ) || ! self::has_only_keys( $group, array( 'id', 'source_selector', 'part_ids', 'source_css' ) ) || ! is_string( $group['id'] ?? null ) || ! preg_match( '/^visual-group-[a-f0-9]{16}$/D', $group['id'] ) || isset( $group_ids[ $group['id'] ] ) || ! is_string( $group['source_selector'] ?? null ) || '' === trim( $group['source_selector'] ) || strlen( $group['source_selector'] ) > 2048 || ! is_array( $group['part_ids'] ?? null ) || ! array_is_list( $group['part_ids'] ) || count( $group['part_ids'] ) < 2 || count( $group['part_ids'] ) > 32 || count( array_unique( $group['part_ids'] ) ) !== count( $group['part_ids'] ) || array_filter( $group['part_ids'], static fn( $id ): bool => ! is_string( $id ) || ! isset( $part_indexes[ $id ] ) ) || ! is_array( $group['source_css'] ?? null ) || ! in_array( $group['source_css']['state'] ?? null, array( 'known', 'unknown' ), true ) ) {
				return array( 'error' => 'presentation_graph visual group is malformed.' );
			}
			if ( 'known' === $group['source_css']['state'] ) {
				$source_css = self::normalize_form_presentation_role( array(
					'styles'     => $group['source_css']['styles'] ?? null,
					'provenance' => $group['source_css']['provenance'] ?? null,
				), $properties, null );
				if ( isset( $source_css['error'] ) || ! self::has_only_keys( $group['source_css'], array( 'state', 'styles', 'provenance' ) ) ) {
					return array( 'error' => 'presentation_graph visual group source CSS is malformed.' );
				}
				$group['source_css'] = array(
					'state' => 'known',
					...$source_css['role'],
				);
			} elseif ( ! self::has_only_keys( $group['source_css'], array( 'state' ) ) ) {
				return array( 'error' => 'presentation_graph visual group unknown CSS is malformed.' );
			}
			$group_ids[ $group['id'] ] = true;
			$visual_groups[]           = $group;
		}
		$control_containers = array();
		$container_indexes  = array();
		foreach ( $candidate['control_containers'] ?? array() as $container ) {
			$chrome_properties = array_flip( array( 'background', 'background_color', 'border', 'border_color', 'border_style', 'border_width', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color', 'border_top_style', 'border_right_style', 'border_bottom_style', 'border_left_style', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width', 'border_radius', 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius' ) );
			if ( ! is_array( $container ) || ! self::has_only_keys( $container, array( 'index', 'source_selector', 'styles', 'provenance' ) ) || ! is_int( $container['index'] ?? null ) || $container['index'] < 0 || $container['index'] >= 128 || isset( $container_indexes[ $container['index'] ] ) || ! is_string( $container['source_selector'] ?? null ) || '' === trim( $container['source_selector'] ) || strlen( $container['source_selector'] ) > 2048 || ! is_array( $container['styles'] ?? null ) || array_diff_key( $container['styles'], $chrome_properties ) ) {
				return array( 'error' => 'presentation_graph control container is malformed.' );
			}
			$role = self::normalize_form_presentation_role( array(
				'styles'     => $container['styles'],
				'provenance' => $container['provenance'] ?? null,
			), array_intersect_key( $properties, $chrome_properties ), null, true );
			if ( isset( $role['error'] ) ) {
				return $role;
			}
			$container_indexes[ $container['index'] ] = true;
			$control_containers[]                     = array(
				'index'           => $container['index'],
				'source_selector' => $container['source_selector'],
				...$role['role'],
			);
		}
		$variants = array();
		foreach ( $candidate['variants'] as $variant ) {
			if ( 'control_container' === ( $variant['role'] ?? null ) && ! isset( $container_indexes[ $variant['index'] ?? -1 ] ) ) {
				return array( 'error' => 'presentation_graph container variant has no source owner.' );
			}
			$is_visual = 'visual_part' === ( $variant['role'] ?? null );
			$is_group  = 'visual_group' === ( $variant['role'] ?? null );
			if ( ! is_array( $variant ) || ! self::has_only_keys( $variant, $is_visual ? array( 'index', 'role', 'part_id', 'condition', 'style_patch', 'precedence', 'provenance' ) : ( $is_group ? array( 'role', 'group_id', 'condition', 'style_patch', 'precedence', 'provenance' ) : array( 'index', 'role', 'condition', 'style_patch', 'precedence', 'provenance' ) ) ) || ( ! $is_group && ( ! is_int( $variant['index'] ?? null ) || $variant['index'] < 0 || $variant['index'] >= 128 ) ) || ! in_array( $variant['role'] ?? null, $is_v2 ? array( 'control', 'label', 'required_marker', 'control_container', 'visual_part', 'visual_group' ) : array( 'control', 'label' ), true ) || ( $is_visual && ( ! is_string( $variant['part_id'] ?? null ) || ( $part_indexes[ $variant['part_id'] ] ?? -1 ) !== $variant['index'] ) ) || ( $is_group && ( ! is_string( $variant['group_id'] ?? null ) || ! isset( $group_ids[ $variant['group_id'] ] ) ) ) || ! self::valid_layout_condition( $variant['condition'] ?? null ) || ! is_array( $variant['style_patch'] ?? null ) || empty( $variant['style_patch'] ) || ! is_array( $variant['precedence'] ?? null ) || ! is_array( $variant['provenance'] ?? null ) ) {
				return array( 'error' => 'presentation_graph contains an unsupported variant.' );
			}
			$role = self::normalize_form_presentation_role(
				array(
					'styles'     => $variant['style_patch'],
					'provenance' => $variant['provenance'],
				),
				'control_container' === $variant['role'] ? array_intersect_key( $properties, $chrome_properties ?? array() ) : $properties,
				$variant['condition']
			);
			if ( isset( $role['error'] ) ) {
				return $role;
			}
			foreach ( $variant['precedence'] as $property => $precedence ) {
				$key = str_replace( '-', '_', (string) $property );
				if ( ! isset( $properties[ $key ], $role['role']['styles'][ $key ] ) || ! is_array( $precedence ) || ! self::has_only_keys( $precedence, array( 'source_order', 'specificity', 'important' ) ) || ! is_int( $precedence['source_order'] ?? null ) || ! is_int( $precedence['specificity'] ?? null ) || ! is_bool( $precedence['important'] ?? null ) ) {
					return array( 'error' => 'presentation_graph variant precedence is malformed.' );
				}
			}
			$variants[] = array_filter( array(
				'index'       => $variant['index'] ?? null,
				'role'        => $variant['role'],
				'part_id'     => $variant['part_id'] ?? null,
				'group_id'    => $variant['group_id'] ?? null,
				'condition'   => $variant['condition'],
				'style_patch' => $role['role']['styles'],
				'precedence'  => $variant['precedence'],
				'provenance'  => $role['role']['provenance'],
			), static fn( $value ): bool => null !== $value );
		}
		foreach ( $control_containers as $container ) {
			if ( empty( $container['styles'] ) && ! array_filter( $variants, static fn( array $variant ): bool => 'control_container' === $variant['role'] && ( $variant['index'] ?? null ) === $container['index'] ) ) {
				return array( 'error' => 'presentation_graph empty container has no conditional presentation.' );
			}
		}
		return array(
			'graph' => array(
				'schema'      => $is_v2 ? 'generic/computed-form-presentation/v2' : 'generic/computed-form-presentation/v1',
				'basis'       => 'source_css_cascade',
				'truncated'   => false,
				'limits'      => array(
					'controls'       => 128,
					'rules_per_role' => 32,
				),
				'controls'    => $controls,
				...( $is_v2 ? array(
					'visual_parts'       => $visual_parts,
					'visual_groups'      => $visual_groups,
					'control_containers' => $control_containers,
				) : array() ),
				'variants'    => $variants,
				'diagnostics' => $candidate['diagnostics'],
			),
		);
	}

	/** @param array<string,string> $properties @return array{role?:array<string,mixed>,error?:string} */
	private static function normalize_form_presentation_role( mixed $candidate, array $properties, ?array $condition, bool $allow_empty = false ): array {
		if ( ! is_array( $candidate ) || count( $candidate ) !== 2 || ! self::has_only_keys( $candidate, array( 'styles', 'provenance' ) ) || ! is_array( $candidate['styles'] ?? null ) || ( ! $allow_empty && empty( $candidate['styles'] ) ) || ! is_array( $candidate['provenance'] ?? null ) || count( $candidate['provenance'] ) > 16 ) {
			return array( 'error' => 'presentation_graph role facts are malformed.' );
		}
		foreach ( $candidate['styles'] as $key => $value ) {
			if ( ! isset( $properties[ $key ] ) || ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 160 ) {
				return array( 'error' => 'presentation_graph contains an unsupported style fact.' );
			}
		}
		foreach ( $candidate['provenance'] as $fact ) {
			if ( ! is_array( $fact ) || ! self::has_only_keys( $fact, array( 'source_path', 'source_sha256', 'selector', 'condition', 'properties' ) ) || ! is_string( $fact['source_path'] ?? null ) || ! preg_match( '~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$~D', $fact['source_path'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ?? '' ) || ! is_string( $fact['selector'] ?? null ) || '' === trim( $fact['selector'] ) || strlen( $fact['selector'] ) > 1024 || ( $fact['condition'] ?? null ) !== $condition || ! is_array( $fact['properties'] ?? null ) || empty( $fact['properties'] ) || array_filter( $fact['properties'], static fn( $property ): bool => ! is_string( $property ) || ! isset( $properties[ str_replace( '-', '_', $property ) ], $candidate['styles'][ str_replace( '-', '_', $property ) ] ) ) ) {
				return array( 'error' => 'presentation_graph provenance is malformed.' );
			}
		}
		return array(
			'role' => array(
				'styles'     => $candidate['styles'],
				'provenance' => $candidate['provenance'],
			),
		);
	}

	/** @return array<string,string> */
	private static function form_presentation_properties(): array {
		$properties = array( 'appearance', 'background', 'background_color', 'border', 'border_color', 'border_style', 'border_width', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color', 'border_top_style', 'border_right_style', 'border_bottom_style', 'border_left_style', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width', 'border_radius', 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius', 'box_sizing', 'color', 'display', 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'height', 'letter_spacing', 'line_height', 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end', 'max_width', 'min_height', 'min_width', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block_start', 'padding_block_end', 'padding_inline_start', 'padding_inline_end', 'text_align', 'text_decoration', 'text_indent', 'text_transform', 'vertical_align', 'width' );
		$properties = array_merge( $properties, array( 'align_items', 'align_self', 'bottom', 'flex', 'flex_basis', 'flex_direction', 'flex_grow', 'flex_shrink', 'gap', 'inset', 'justify_content', 'justify_self', 'left', 'margin_block', 'margin_inline', 'order', 'position', 'right', 'top', 'transform', 'z_index' ) );
		return array_combine( $properties, array_map( static fn( string $property ): string => str_replace( '_', '-', $property ), $properties ) );
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
		if ( ! is_int( $max_depth ) || $max_depth < 0 || $max_depth > self::FORM_CONTROL_TOPOLOGY_MAX_DEPTH || ! is_int( $max_nodes ) || $max_nodes < 1 || $max_nodes > 128 || ! is_array( $nodes ) || ! array_is_list( $nodes ) || count( $nodes ) > $max_nodes ) {
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
			if ( ! is_array( $node ) || ! self::has_only_keys( $node, ( $node['kind'] ?? null ) === 'wrapper' ? array( 'id', 'kind', 'parent', 'order', 'depth', 'tag', 'source_id', 'class', 'fieldset_semantics' ) : array( 'id', 'kind', 'parent', 'order', 'depth', 'control' ) ) || ! is_string( $node['id'] ?? null ) || ! preg_match( '/^(?:wrapper|control)-[A-Za-z0-9_-]{1,80}$/D', $node['id'] ) || isset( $seen_ids[ $node['id'] ] ) || ! in_array( $node['kind'] ?? null, array( 'wrapper', 'control' ), true ) || ! is_int( $node['order'] ?? null ) || $node['order'] < 0 || ! is_int( $node['depth'] ?? null ) || $node['depth'] < 0 || $node['depth'] > $max_depth ) {
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
				if ( isset( $node['fieldset_semantics'] ) ) {
					if ( 'fieldset' !== ( $node['tag'] ?? '' ) || ! in_array( $node['fieldset_semantics'], array( 'plain_group', 'labelled_group', 'disabled_group', 'attributed_group' ), true ) ) {
						return array( 'error' => 'control_topology fieldset semantics must describe a fieldset wrapper.' );
					}
					$normalized_node['fieldset_semantics'] = $node['fieldset_semantics'];
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
	private static function layout_property_map( bool $include_width = false ): array {
		$map = array(
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
		if ( $include_width ) {
			$map['width']                 = 'width';
			$map['height']                = 'height';
			$map['margin_block_start']    = 'margin-block-start';
			$map['margin_block_end']      = 'margin-block-end';
			$map['margin_inline_start']   = 'margin-inline-start';
			$map['margin_inline_end']     = 'margin-inline-end';
		}
		return $map;
	}

	/** @return array<string,string> */
	private static function layout_producer_property_map( bool $include_width = false ): array {
		return array_flip( self::layout_property_map( $include_width ) );
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
