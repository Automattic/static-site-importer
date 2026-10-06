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
if ( ! class_exists( 'Static_Site_Importer_Provider_Layout_Overlay' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-layout-overlay.php';
}
if ( ! class_exists( 'Static_Site_Importer_Provider_Form_Runtime_V1' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}

/**
 * Registers import-time entity validators, dependency requirements, and writers.
 */
class Static_Site_Importer_Entity_Materializer_Registry {

	private const FAILURE_DIAGNOSTIC_MAX_ROWS           = 10;
	private const FAILURE_DIAGNOSTIC_MAX_BYTES          = 256;
	private const FAILURE_DIAGNOSTIC_SCAN_BUDGET        = 10;
	private const RUNTIME_DECLARATION_PAYLOAD_MAX_BYTES = 10485760;

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
			'external_metrics' => array(
				'default_provider' => 'wordpress.org',
				'option'           => '',
				'filter'           => '',
			),
			'form'             => array(
				'default_provider' => 'jetpack',
				'option'           => 'static_site_importer_form_plugin',
				'filter'           => 'ssi_form_plugin',
			),
			'shop'             => array(
				'default_provider' => 'woocommerce',
				'option'           => 'static_site_importer_shop_plugin',
				'filter'           => 'ssi_shop_plugin',
			),
			'events'           => array(
				'default_provider' => 'the-events-calendar',
				'option'           => 'static_site_importer_events_plugin',
				'filter'           => 'ssi_events_plugin',
			),
			'multilingual'     => array(
				'default_provider' => 'translatepress-multilingual',
				'option'           => 'static_site_importer_multilingual_plugin',
				'filter'           => 'ssi_multilingual_plugin',
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
	 * @param array<string,mixed> $args     Import-scoped context (e.g. resolved source assets) an
	 *                                      adapter's materializer may opt into reading.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize( array $adapter, array $manifest, array $args = array() ) {
		$materializer = $adapter['materializer'] ?? null;
		if ( is_callable( $materializer ) ) {
			$result = call_user_func( $materializer, $manifest, $args );
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
			// Reference-backed event manifests have no inline rows during prepare.
			// Their declared capability still requires native provider hydration.
			$required = $required || 'multilingual' === $capability || ( 'entity_collection' === $kind && 'events' === $capability );
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
			$collection = (string) ( $adapter['entity_collection'] ?? '' );
			$manifest   = array( $collection => $entities );
			if ( 'external_metrics' === $collection ) {
				$manifest['source_path']             = (string) ( $declaration['source_path'] ?? '' );
				$manifest['validate_anchor_content'] = true;
			}
			if ( 'products' === $collection ) {
				$manifest['schema_version'] = 1;
			}
			// Dependency preparation intentionally defers provider validation until
			// resume. Runtime entity manifests carry content-hash refs, not entity
			// bodies; with_resolved_binding_manifests() validates their resolved rows.
			$defer_validation = 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) || 'blocks-engine/runtime-entity-manifest/v1' === ( $declaration['payload']['schema'] ?? null );
			$validation       = $defer_validation ? array( 'errors' => array() ) : self::validate_manifest_generic( $adapter, $manifest );
			$accepted         = is_array( $validation[ $collection ] ?? null ) ? $validation[ $collection ] : array();
			if ( ! empty( $validation['errors'] ) ) {
				// Entity validators report per row: an unmappable row is rejected
				// without discarding the rows that did validate, so partial feature
				// parity is still materialized. Honour that here -- only a
				// declaration that produced no usable row is rejected outright.
				//
				// The rejection carries the collection and error count alongside the
				// errors themselves, because the user-facing gate message is built
				// from this data and "failed validation" without the facts is not
				// actionable (#1785).
				if ( empty( $accepted ) ) {
					return new WP_Error(
						'static_site_importer_runtime_entity_invalid',
						'Runtime entity declaration failed SSI provider validation.',
						array(
							'status'            => 'rejected',
							'declaration_id'    => $key,
							'entity_collection' => $collection,
							'error_count'       => count( $validation['errors'] ),
							'errors'            => $validation['errors'],
						)
					);
				}
				$lifecycle['diagnostics'][] = self::rejected_runtime_entity_rows_diagnostic( $key, $adapter, count( $entities ), count( $accepted ), $validation['errors'] );
			}
			// Deferred validation must still retain every declared entity.
			$normalized_manifest = $defer_validation ? $manifest : array( $collection => $accepted );
			if ( 'products' === $collection && ! $defer_validation ) {
				$normalized_manifest['schema_version'] = 1;
			}
			$lifecycle['entities'][ $key ] = array(
				'adapter'     => $adapter,
				'manifest'    => $normalized_manifest,
				'declaration' => $declaration,
				'required'    => $required || ( 'events' === $capability && ! empty( $entities ) ),
			);
			if ( ! isset( $lifecycle['dependencies'][ $key ] ) ) {
				$lifecycle['dependencies'][ $key ] = array(
					'adapter'     => $adapter,
					'declaration' => $declaration,
					'required'    => $required || ( 'events' === $capability && ! empty( $entities ) ),
				);
			}
			if ( 'events' === $capability && ! empty( $entities ) ) {
				$lifecycle['dependencies'][ $key ]['required'] = true;
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

	/**
	 * Report the rows of a runtime entity declaration a provider validator rejected.
	 *
	 * Entity validators are per row by contract, so the declaration is still
	 * materialized from the rows that validated. The rejected rows keep the
	 * converted source markup already on the page, which is the same loss class
	 * as a provider decline.
	 *
	 * @param string              $declaration_id Declaration reconciliation identity.
	 * @param array<string,mixed> $adapter        Adapter definition.
	 * @param int                 $declared       Declared row count.
	 * @param int                 $accepted       Validated row count.
	 * @param array<int,mixed>    $errors         Validator errors for the rejected rows.
	 * @return array<string,mixed>
	 */
	private static function rejected_runtime_entity_rows_diagnostic( string $declaration_id, array $adapter, int $declared, int $accepted, array $errors ): array {
		$collection = (string) ( $adapter['entity_collection'] ?? 'entities' );
		$rejected   = max( 0, $declared - $accepted );
		return array(
			'id'                      => 'runtime-entity-rows-rejected-' . hash( 'sha256', $declaration_id . "\n" . $collection ),
			'code'                    => 'runtime_entity_rows_rejected',
			'type'                    => 'static-site-importer',
			'severity'                => 'warning',
			'stage'                   => 'entity_materialization',
			'loss_class'              => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
			'declaration_id'          => $declaration_id,
			'reconciliation_identity' => $declaration_id,
			'provider'                => (string) ( $adapter['provider'] ?? '' ),
			'entity_collection'       => $collection,
			'declared_count'          => $declared,
			'accepted_count'          => $accepted,
			'rejected_count'          => $rejected,
			'errors'                  => array_slice( array_values( array_filter( $errors, 'is_array' ) ), 0, 16 ),
			'message'                 => $rejected . ' of ' . $declared . ' declared ' . $collection . ' failed SSI provider validation and were skipped; the remaining ' . $accepted . ' materialized. The imported pages keep their converted source markup for the skipped rows.',
		);
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
		if ( 'dependency' === $kind && array_key_exists( $name, self::capabilities() ) ) {
			return $name;
		}
		if ( 'entity_collection' === $kind && 'multilingual' === $name ) {
			return 'multilingual';
		}
		if ( 'entity_collection' === $kind && in_array( $name, array( 'event', 'events' ), true ) ) {
			return 'events';
		}
		if ( 'entity_collection' === $kind && in_array( $name, array( 'product', 'products' ), true ) ) {
			return 'shop';
		}
		if ( 'entity_collection' === $kind && 'external_metrics' === $name ) {
			return 'external_metrics';
		}
		return 'entity_collection' === $kind && in_array( $name, array( 'form', 'forms' ), true ) ? 'form' : '';
	}

	/** Copy producer presentation facts onto the form entity without re-parsing source HTML. */
	public static function prepare_form_entity( array $entity ): array {
		$normalized         = Static_Site_Importer_Form_Fallback_Contract::normalize_form_metadata( $entity );
		$entity['form']     = $normalized['form'];
		$entity['controls'] = $normalized['controls'];
		return $entity;
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
				$key                = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
				$resolved_by_key    = array();
				$canonical_entities = is_array( $prepared['manifest'][ $key ] ?? null ) ? $prepared['manifest'][ $key ] : array();
				foreach ( $entities as $entity ) {
					if ( ! is_array( $entity ) ) {
						continue;
					}
					$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : ( 'external_metrics' === $key ? (string) ( $entity['id'] ?? '' ) : self::form_entity_key( $entity ) );
					if ( '' !== $entity_key ) {
						$resolved_by_key[ $entity_key ] = $entity;
					}
				}
				foreach ( $canonical_entities as $index => $entity ) {
					if ( ! is_array( $entity ) ) {
						continue;
					}
					$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : ( 'external_metrics' === $key ? (string) ( $entity['id'] ?? '' ) : self::form_entity_key( $entity ) );
					if ( isset( $resolved_by_key[ $entity_key ]['bindings'] ) && is_array( $resolved_by_key[ $entity_key ]['bindings'] ) ) {
						$prepared['manifest'][ $key ][ $index ]['bindings'] = $resolved_by_key[ $entity_key ]['bindings'];
					}
				}
				continue;
			}
			if ( ! is_array( $prepared ) || ! is_array( $prepared['adapter'] ?? null ) ) {
				return self::runtime_entity_resolution_error( 'Runtime entity manifest lifecycle entry is malformed.' );
			}
			$key      = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
			$entities = $expanded[ $id ];
			if ( 'form' === ( $prepared['adapter']['capability'] ?? null ) ) {
				$entities = array_map( static fn( $entity ) => is_array( $entity ) ? self::prepare_form_entity( $entity ) : $entity, $entities );
			}
			$manifest = array( $key => $entities );
			if ( 'products' === $key ) {
				$manifest['schema_version'] = 1;
			}
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
			$prepared['manifest'] = array( $key => $validation[ $key ] ?? array() );
			if ( 'products' === $key ) {
				$prepared['manifest']['schema_version'] = 1;
			}
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
			$manifest   = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$collection = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
			$entities   = is_array( $manifest[ $collection ] ?? null ) ? $manifest[ $collection ] : array();
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
			$entity_args                                        = $args;
			$entity_args['declaration_reconciliation_identity'] = (string) $id;
			$report = self::materialize( $adapter, $prepared['manifest'], $entity_args );
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
			$collection     = (string) ( $adapter['entity_collection'] ?? '' );
			$expected       = count( is_array( $prepared['manifest'][ $collection ] ?? null ) ? $prepared['manifest'][ $collection ] : array() );
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
		$manifest   = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
		$collection = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
		$entities   = is_array( $manifest[ $collection ] ?? null ) ? $manifest[ $collection ] : array();
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
			$entity_key        = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
			$manifest_entities = is_array( $manifest[ $entity_key ] ?? null ) ? $manifest[ $entity_key ] : array();
			$result_entities   = is_array( $report[ $entity_key ] ?? null ) ? $report[ $entity_key ] : array();
			$results           = array();
			foreach ( $result_entities as $result ) {
				if ( is_array( $result ) ) {
					$key             = 'products' === $entity_key ? (string) ( $result['slug'] ?? '' ) : ( 'external_metrics' === $entity_key ? (string) ( $result['id'] ?? '' ) : self::form_entity_key( $result ) );
					$results[ $key ] = $result;
				}
			}

			// Group individual bindings — not whole entities — that declare one
			// shared `commerce_collection` anchor (a detected product grid,
			// where every member product's own binding for that grid points at
			// the same one preserved source-page region) so that shared anchor
			// resolves to exactly one replacement instead of racing N per-product
			// replacements against the same source-page occurrence. Grouping by
			// individual binding, rather than by an entity's full binding list,
			// is what lets one product legitimately belong to more than one grid
			// at once: it then carries one `commerce_collection` binding per
			// grid, each judged and coalesced against its own grid's members
			// independently of how many other anchors that same entity also
			// claims. Every other binding (forms, single products) keeps its
			// established one-binding-one-resolution, unchanged.
			$groups      = array();
			$group_order = array();
			foreach ( $manifest_entities as $entity ) {
				if ( ! is_array( $entity ) || empty( $entity['bindings'] ) || ! is_array( $entity['bindings'] ) ) {
					continue;
				}
				$key    = 'products' === $entity_key ? (string) ( $entity['slug'] ?? '' ) : ( 'external_metrics' === $entity_key ? (string) ( $entity['id'] ?? '' ) : self::form_entity_key( $entity ) );
				$result = is_array( $results[ $key ] ?? null ) ? $results[ $key ] : array();
				if ( self::entity_result_declined( $result ) ) {
					continue;
				}
				foreach ( $entity['bindings'] as $binding ) {
					if ( ! is_array( $binding ) ) {
						continue;
					}
					$is_collection = 'commerce_collection' === ( $binding['role'] ?? '' );
					$group_key     = $is_collection
						? 'collection:' . (string) ( $binding['source_path'] ?? '' ) . "\n" . hash( 'sha256', (string) ( $binding['search_block_markup'] ?? '' ) ) . "\n" . (string) ( $binding['occurrence'] ?? '' )
						: 'single:' . $key . ':' . count( $groups );
					if ( ! isset( $groups[ $group_key ] ) ) {
						$groups[ $group_key ] = array();
						$group_order[]        = $group_key;
					}
					$groups[ $group_key ][] = array(
						'key'     => $key,
						'entity'  => $entity,
						'binding' => $binding,
						'result'  => $result,
					);
				}
			}

			foreach ( $group_order as $group_key ) {
				$members = $groups[ $group_key ];
				if ( 1 === count( $members ) || ! str_starts_with( $group_key, 'collection:' ) ) {
					foreach ( $members as $member ) {
						$entity      = $member['entity'];
						$replacement = self::binding_block_markup( $prepared['adapter'], $entity, $member['result'] );
						if ( '' === $replacement ) {
							return new WP_Error(
								'static_site_importer_runtime_binding_unresolved',
								'A required provider entity did not produce binding block markup.',
								array(
									'declaration_id' => $declaration_id,
									'entity_key'     => $member['key'],
								)
							);
						}
						$bindings[] = self::block_binding_record( $declaration_id, $member['binding'], $replacement, $entity, $prepared['adapter'] );
					}
					continue;
				}

				// A shared product-grid anchor: resolve every member's own seeded
				// result into one grid entity and produce exactly one replacement
				// for the whole shared preserved-region occurrence.
				$product_ids = array();
				foreach ( $members as $member ) {
					$id = isset( $member['result']['id'] ) ? (int) $member['result']['id'] : 0;
					if ( $id > 0 ) {
						$product_ids[] = $id;
					}
				}
				$anchor_binding = $members[0]['binding'];
				$grid_entity    = array(
					'entity_kind' => 'product_grid',
					'product_ids' => $product_ids,
				);
				$replacement    = self::binding_block_markup( $prepared['adapter'], $grid_entity, array() );
				if ( '' === $replacement ) {
					return new WP_Error(
						'static_site_importer_runtime_binding_unresolved',
						'A required provider entity did not produce binding block markup.',
						array(
							'declaration_id' => $declaration_id,
							'entity_key'     => 'product_grid:' . (string) ( $anchor_binding['source_path'] ?? '' ),
						)
					);
				}
				$bindings[] = self::block_binding_record( $declaration_id, $anchor_binding, $replacement, $grid_entity, $prepared['adapter'] );
			}
		}
		return $bindings;
	}

	/** Build one canonical block-binding replacement record for one resolved anchor. */
	private static function block_binding_record( string $declaration_id, array $binding, string $replacement, array $entity, array $adapter ): array {
		return array(
			'schema'                           => 'static-site-importer/runtime-entity-binding/v1',
			'source_path'                      => $binding['source_path'],
			'search_block_markup'              => $binding['search_block_markup'],
			'replacement_block_markup'         => $replacement,
			'occurrence'                       => $binding['occurrence'],
			'role'                             => $binding['role'],
			'declaration_id'                   => $declaration_id,
			'reconciliation_identity'          => hash( 'sha256', "static-site-importer/runtime-entity-binding/v1\n{$declaration_id}\n{$binding['source_path']}\n{$binding['occurrence']}\n" . hash( 'sha256', $binding['search_block_markup'] ) ),
			'fallback_reconciliation_identity' => 'form' === $binding['role'] ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $entity ) : ( 'commerce_collection' === $binding['role'] ? self::product_grid_fallback_reconciliation_identity( $binding ) : '' ),
			'fallback_hash'                    => 'form' === $binding['role'] ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $entity ) : ( 'commerce_collection' === $binding['role'] ? hash( 'sha256', $binding['search_block_markup'] ) : '' ),
			'materialized_block_hash'          => in_array( $binding['role'], array( 'form', 'commerce_collection' ), true ) ? hash( 'sha256', $replacement ) : '',
			'provider'                         => $adapter['provider'] ?? '',
			'superseded_runtime_selectors'     => $binding['superseded_runtime_selectors'] ?? array(),
			'replaced_fallback_identities'     => 'form' === $binding['role'] ? self::replaced_fallback_identities( $entity ) : array(),
		);
	}

	/**
	 * Source form fallbacks one shared provider form stands for. A producer that
	 * moved identical chrome into a template part keeps one entity and lists the
	 * fallback of every page it replaced, its own included.
	 *
	 * @param array<string,mixed> $entity Form entity.
	 * @return array<int,string>
	 */
	private static function replaced_fallback_identities( array $entity ): array {
		$identities = array();
		foreach ( is_array( $entity['replaced_fallback_identities'] ?? null ) ? $entity['replaced_fallback_identities'] : array() as $identity ) {
			if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity ) ) {
				$identities[ $identity ] = true;
			}
		}
		return array_keys( $identities );
	}

	/** Derive a product-grid fallback's reconciliation identity from its own resolved binding anchor. */
	private static function product_grid_fallback_reconciliation_identity( array $binding ): string {
		$source_path = (string) ( $binding['source_path'] ?? '' );
		$hash        = hash( 'sha256', (string) ( $binding['search_block_markup'] ?? '' ) );
		return hash( 'sha256', "static-site-importer/product-grid-fallback/v1\n{$source_path}\n{$hash}" );
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
			$key     = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
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
	 * Collect registered entity materializers from their owning seeders.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function adapters(): array {
		$adapters = array();
		if ( ! class_exists( 'Static_Site_Importer_TEC_Event_Seeder' ) ) {
			require_once __DIR__ . '/class-static-site-importer-tec-event-seeder.php';
		}
		require_once __DIR__ . '/class-static-site-importer-translatepress-materializer.php';
		foreach ( array( 'Static_Site_Importer_Woo_Product_Seeder', 'Static_Site_Importer_Form_Seeder', 'Static_Site_Importer_TEC_Event_Seeder', 'Static_Site_Importer_External_Metric_Runtime', 'Static_Site_Importer_TranslatePress_Materializer' ) as $owner ) {
			// Seeders may be absent or stubbed in standalone harnesses.
			// @phpstan-ignore-next-line booleanNot.alwaysFalse -- Optional classes are stubbed in standalone coverage harnesses.
			if ( ! is_callable( array( $owner, 'adapter' ) ) ) {
				continue;
			}
			$adapter = call_user_func( array( $owner, 'adapter' ) );
			// @phpstan-ignore-next-line function.alreadyNarrowedType -- Adapter shape is not guaranteed when a harness stubs the seeder.
			if ( ! is_array( $adapter ) ) {
				continue;
			}
			$id = (string) ( $adapter['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$adapters[ $id ] = $adapter;
		}

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
				continue;
			}
			$collection = is_string( $adapter['entity_collection'] ?? null ) ? trim( $adapter['entity_collection'] ) : '';
			if ( '' === $collection ) {
				$collection = 'shop' === ( $adapter['capability'] ?? null ) ? 'products' : 'forms';
			}
			$adapter['entity_collection'] = $collection;
			$filtered[ $id ]              = $adapter;
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
			foreach ( array( 'description', 'short_description', 'status', 'stock_status' ) as $field ) {
				if ( isset( $product[ $field ] ) && ! is_string( $product[ $field ] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.' . $field,
						'message' => $field . ' must be a string when provided.',
					);
				}
			}
			$image = array(
				'src' => '',
				'alt' => '',
			);
			if ( array_key_exists( 'image', $product ) ) {
				$image = self::manifest_product_image( $product['image'] );
				if ( null === $image ) {
					$errors[] = array(
						'path'    => $path_prefix . '.image',
						'message' => 'image must be a string source path, or an { src, alt } object with a non-empty src, when provided.',
					);
					$image    = array(
						'src' => '',
						'alt' => '',
					);
				}
			}
			if ( isset( $product['image_alt'] ) && ! is_string( $product['image_alt'] ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.image_alt',
					'message' => 'image_alt must be a string when provided.',
				);
			} elseif ( '' === $image['alt'] && isset( $product['image_alt'] ) && is_string( $product['image_alt'] ) ) {
				// A sibling `image_alt` field carries alt text for producers that
				// keep `image` a bare source path (e.g. the product-finding bridge).
				$image['alt'] = trim( $product['image_alt'] );
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
			foreach ( array( 'sale_price', 'description', 'short_description', 'categories', 'status', 'stock_status', 'stock_quantity', 'source_selectors' ) as $field ) {
				if ( array_key_exists( $field, $product ) ) {
					$summary[ $field ] = $product[ $field ];
				}
			}
			if ( '' !== $image['src'] ) {
				$summary['image'] = $image['src'];
			}
			if ( '' !== $image['alt'] ) {
				$summary['image_alt'] = $image['alt'];
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
			$replaced = self::replaced_fallback_identities( $form );
			if ( array() !== $replaced ) {
				$row['replaced_fallback_identities'] = $replaced;
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
			if ( array_key_exists( 'sibling_relations', $form ) ) {
				$relations = self::normalize_form_sibling_relations( $form['sibling_relations'], count( $controls ) );
				if ( isset( $relations['error'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.sibling_relations',
						'message' => $relations['error'],
					);
					continue;
				}
				if ( ! array_key_exists( 'relations', $relations ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.sibling_relations',
						'message' => 'Sibling relation normalization did not produce relations.',
					);
					continue;
				}
				$row['sibling_relations'] = $relations['relations'];
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
			if ( array_key_exists( 'source_contract_losses', $form ) ) {
				// The producer withheld an exhausted source graph and named why. Carry
				// those names so the materializer reports a partial contract as partial.
				$source_losses = $form['source_contract_losses'];
				if ( ! is_array( $source_losses ) || ! array_is_list( $source_losses ) || array() === $source_losses || count( $source_losses ) > 32 || array_filter( $source_losses, static fn( $loss ): bool => ! is_string( $loss ) || '' === trim( $loss ) || strlen( $loss ) > 1100 ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.source_contract_losses',
						'message' => 'source_contract_losses must be a bounded list of producer diagnostics.',
					);
					continue;
				}
				$row['source_contract_losses'] = array_values( array_unique( $source_losses ) );
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
	/**
	 * Admit a producer form layout graph.
	 *
	 * Blocks Engine owns the graph contract and validates it with
	 * FormLayoutGraphBuilder::assertValid(), including safe source identity.
	 * The importer adds only its own materialization policy: a truncated graph
	 * is incomplete evidence, presentation facts must be ones the provider can
	 * express, and diagnostics are bounded.
	 *
	 * @return array{graph?:array<string,mixed>,error?:string}
	 */
	private static function normalize_computed_layout_graph( mixed $candidate ): array {
		if ( ! is_array( $candidate ) ) {
			return array( 'error' => 'layout_graph must be a producer computed-layout graph.' );
		}
		try {
			\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder::assertValid( $candidate );
		} catch ( InvalidArgumentException $error ) {
			return array( 'error' => 'layout_graph violates the producer contract: ' . $error->getMessage() );
		}
		if ( $candidate['truncated'] ) {
			return array( 'error' => 'layout_graph is truncated.' );
		}
		$nodes = array();
		foreach ( $candidate['nodes'] as $node ) {
			$clean = array(
				'id'         => $node['id'],
				'kind'       => $node['kind'],
				'parent'     => $node['parent'] ?? null,
				'order'      => $node['order'],
				'source'     => $node['source'],
				'layout'     => $node['layout'],
				'provenance' => $node['provenance'],
			);
			if ( array_key_exists( 'presentation', $node ) ) {
				// Resolved presentation belongs to a source container element, never to
				// the provider-owned form root or a native control.
				$presentation = 'form' !== $node['id'] ? self::normalize_element_presentation( $node['presentation'] ) : array( 'error' => 'layout_graph presentation may only describe a source container element.' );
				if ( ! isset( $presentation['presentation'] ) ) {
					return array( 'error' => $presentation['error'] ?? 'layout_graph presentation normalization did not produce presentation facts.' );
				}
				$clean['presentation'] = $presentation['presentation'];
			}
			if ( is_array( $node['sizing'] ?? null ) ) {
				$clean['sizing'] = $node['sizing'];
			}
			$nodes[] = $clean;
		}
		return array(
			'graph' => array(
				'schema'      => $candidate['schema'],
				'basis'       => $candidate['basis'],
				'truncated'   => false,
				'limits'      => $candidate['limits'],
				'nodes'       => $nodes,
				'variants'    => $candidate['variants'],
				'diagnostics' => array_slice( $candidate['diagnostics'], 0, 32 ),
			),
		);
	}

	/**
	 * Admit a producer form presentation graph.
	 *
	 * Blocks Engine owns the graph contract and validates it with
	 * FormPresentationGraphBuilder::assertValid(). The importer adds only its
	 * own materialization policy: a truncated graph is incomplete evidence, and
	 * every style fact must be one the provider layout overlay can express.
	 *
	 * @return array{graph?:array<string,mixed>,error?:string}
	 */
	private static function normalize_form_presentation_graph( mixed $candidate ): array {
		if ( ! is_array( $candidate ) ) {
			return array( 'error' => 'presentation_graph must be a producer form presentation graph.' );
		}
		try {
			\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder::assertValid( $candidate );
		} catch ( InvalidArgumentException $error ) {
			return array( 'error' => 'presentation_graph violates the producer contract: ' . $error->getMessage() );
		}
		if ( true === $candidate['truncated'] ) {
			return array( 'error' => 'presentation_graph is truncated and cannot be materialized.' );
		}
		$properties = Static_Site_Importer_Provider_Layout_Overlay::presentation_property_map();
		$roles      = array_merge(
			...array_map(
				static fn( array $row ): array => array_values( array_intersect_key( $row, array_flip( array( 'control', 'label', 'required_marker' ) ) ) ),
				$candidate['controls']
			)
		);
		$roles      = array_merge( $roles, $candidate['control_containers'] ?? array() );
		$styles     = array_merge( array_column( $roles, 'styles' ), array_column( $candidate['variants'], 'style_patch' ) );
		foreach ( $styles as $role_styles ) {
			foreach ( array_keys( $role_styles ) as $key ) {
				if ( ! isset( $properties[ $key ] ) ) {
					return array( 'error' => 'presentation_graph contains a style fact the provider cannot materialize.' );
				}
			}
		}

		return array( 'graph' => $candidate );
	}


	/**
	 * Admit a producer control topology.
	 *
	 * Blocks Engine owns the contract (FormControlTopologyBuilder::assertValid());
	 * the importer refuses a truncated topology, which cannot preserve parentage.
	 *
	 * @return array{topology?:array<string,mixed>,error?:string}
	 */
	private static function normalize_form_control_topology( mixed $candidate, int $control_count ): array {
		if ( ! is_array( $candidate ) ) {
			return array( 'error' => 'control_topology must be a producer control topology.' );
		}
		if ( true === ( $candidate['truncated'] ?? false ) ) {
			return array( 'error' => 'control_topology is truncated and cannot preserve source control parentage.' );
		}
		try {
			\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder::assertValid( $candidate, $control_count );
		} catch ( InvalidArgumentException $error ) {
			return array( 'error' => 'control_topology violates the producer contract: ' . $error->getMessage() );
		}
		foreach ( $candidate['nodes'] as $index => $node ) {
			if ( isset( $node['legend'] ) ) {
				$candidate['nodes'][ $index ]['legend'] = trim( preg_replace( '/\s+/', ' ', $node['legend'] ) ?? '' );
			}
			$candidate['nodes'][ $index ]['parent'] = $node['parent'] ?? null;
		}
		return array( 'topology' => $candidate );
	}

	/**
	 * Admit producer sibling relations (FormControlTopologyBuilder::assertSiblingRelations());
	 * the importer refuses truncated relations, which cannot preserve adjacency.
	 *
	 * @return array{relations?:array<string,mixed>,error?:string}
	 */
	private static function normalize_form_sibling_relations( mixed $candidate, int $control_count ): array {
		if ( ! is_array( $candidate ) ) {
			return array( 'error' => 'sibling_relations must be producer sibling relations.' );
		}
		try {
			\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder::assertSiblingRelations( $candidate, $control_count );
		} catch ( InvalidArgumentException $error ) {
			return array( 'error' => 'sibling_relations violates the producer contract: ' . $error->getMessage() );
		}
		if ( $candidate['truncated'] ) {
			return array( 'error' => 'sibling_relations is truncated and cannot preserve direct source adjacency.' );
		}
		return array( 'relations' => $candidate );
	}

	/**
	 * Admit the resolved source presentation a v3 graph attaches to a container.
	 *
	 * Blocks Engine owns the envelope contract through
	 * FormPresentationGraphBuilder::assertElement(); the importer refuses a
	 * truncated presentation and any style the provider overlay cannot express.
	 *
	 * @return array{presentation?:array<string,mixed>,error?:string}
	 */
	private static function normalize_element_presentation( mixed $candidate ): array {
		if ( ! is_array( $candidate ) ) {
			return array( 'error' => 'layout_graph element presentation must be a producer element presentation.' );
		}
		try {
			\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder::assertElement( $candidate );
		} catch ( InvalidArgumentException $error ) {
			return array( 'error' => 'layout_graph element presentation violates the producer contract: ' . $error->getMessage() );
		}
		if ( false !== $candidate['truncated'] ) {
			return array( 'error' => 'layout_graph element presentation is truncated.' );
		}
		$properties = Static_Site_Importer_Provider_Layout_Overlay::presentation_property_map();
		foreach ( array_merge( array( $candidate['styles'] ), array_column( $candidate['variants'], 'styles' ) ) as $styles ) {
			if ( array_diff_key( $styles, $properties ) ) {
				return array( 'error' => 'layout_graph element presentation contains a style the provider cannot materialize.' );
			}
		}
		return array( 'presentation' => $candidate );
	}

	/** @return array<string,mixed>|null */
	private static function normalize_block_binding( mixed $binding ): ?array {
		if ( null === $binding ) {
			return array();
		}
		if ( ! is_array( $binding ) || 'generic/block-binding/v1' !== ( $binding['schema'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! preg_match( '#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[^\x00-\x1f]+$#', $binding['source_path'] ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || strlen( $binding['search_block_markup'] ) > self::RUNTIME_DECLARATION_PAYLOAD_MAX_BYTES || ! is_string( $binding['role'] ?? null ) || ! in_array( $binding['role'], array( 'commerce_controls', 'commerce_collection', 'form' ), true ) ) {
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

	/**
	 * Normalize a manifest product `image` field into its src/alt parts.
	 *
	 * A producer may declare an image as a bare artifact-relative source path
	 * (the current Blocks Engine shape) or as an `{ src, alt }` object (the
	 * shape a producer carrying detected alt text emits). Both are admitted so
	 * the seeder always reads one normalized shape.
	 *
	 * @param mixed $image Raw manifest `image` field.
	 * @return array{src:string,alt:string}|null Null when the field is present but malformed.
	 */
	private static function manifest_product_image( mixed $image ): ?array {
		if ( is_string( $image ) ) {
			return array(
				'src' => trim( $image ),
				'alt' => '',
			);
		}

		if ( is_array( $image ) && ! array_is_list( $image ) ) {
			$src = isset( $image['src'] ) && is_string( $image['src'] ) ? trim( $image['src'] ) : '';
			$alt = isset( $image['alt'] ) && is_string( $image['alt'] ) ? trim( $image['alt'] ) : '';
			return '' === $src ? null : array(
				'src' => $src,
				'alt' => $alt,
			);
		}

		return null;
	}
}
