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
				'default_provider' => 'external_source',
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
			'redirects'        => array(
				'default_provider' => 'redirection',
				'option'           => 'static_site_importer_redirects_plugin',
				'filter'           => 'ssi_redirects_plugin',
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
	public static function adapter_for_capability( string $capability, string $collection = '' ): array {
		$adapters   = self::adapters();
		$provider   = self::provider_for( $capability );
		$collection = strtolower( $collection );

		// A provider may own several collections under one capability (a shop's
		// products and its cart). A named collection resolves to the provider's
		// adapter for it; a collection no adapter owns resolves to the provider's
		// default adapter, and one owned only by another provider resolves to none.
		$claimed = false;
		$default = array();
		foreach ( $adapters as $adapter ) {
			if ( (string) ( $adapter['capability'] ?? '' ) !== $capability ) {
				continue;
			}
			$accepts = '' !== $collection && in_array( $collection, array( (string) ( $adapter['entity_collection'] ?? '' ), (string) ( $adapter['entity_type'] ?? '' ) ), true );
			$claimed = $claimed || $accepts;
			if ( (string) ( $adapter['provider'] ?? '' ) !== $provider ) {
				continue;
			}
			if ( '' === $collection || $accepts ) {
				return $adapter;
			}
			$default = empty( $default ) ? $adapter : $default;
		}

		// A configured provider is an explicit security/transaction boundary.
		// Never route it to a different adapter implicitly.
		return $claimed ? array() : $default;
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
			$required = $required || in_array( $capability, array( 'multilingual', 'redirects' ), true ) || ( 'entity_collection' === $kind && 'events' === $capability );
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
			$adapter     = '' === $adapter_key ? self::adapter_for_capability( $capability, 'entity_collection' === $kind ? $name : '' ) : self::adapter( $adapter_key );
			if ( empty( $adapter ) && ! $required && 'entity_collection' === $kind && '' === $adapter_key && ! empty( self::adapter_for_capability( $capability ) ) ) {
				// The configured provider serves the capability but not this optional collection.
				$lifecycle['diagnostics'][] = array(
					'code'                    => 'unsupported_optional_runtime_declaration',
					'severity'                => 'warning',
					'reconciliation_identity' => $key,
					'message'                 => 'The configured ' . $capability . ' provider has no adapter for optional declaration ' . $name . '.',
				);
				continue;
			}
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
			if ( ! in_array( $adapter['materialization_stage'] ?? 'before_pages', array( 'before_pages', 'after_pages' ), true ) ) {
				return new WP_Error( 'static_site_importer_runtime_adapter_stage_invalid', 'The selected native provider has an unsupported materialization stage.' );
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
				if ( 'after_pages' === ( $adapter['materialization_stage'] ?? '' ) ) {
					$lifecycle['entities'][ $key ] = array(
						'adapter'     => $adapter,
						'declaration' => $declaration,
						'manifest'    => array( $adapter['entity_collection'] => array() ),
						'required'    => $required,
					);
				}
				continue;
			}
			if ( 'entity_collection' !== $kind ) {
				continue;
			}
			$entities = isset( $declaration['payload']['entities'] ) && is_array( $declaration['payload']['entities'] ) ? $declaration['payload']['entities'] : array();
			if ( 'form' === $capability ) {
				$entities = self::with_form_source_html( $entities, $plan );
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
				// parity is still materialized. An opted-in region row keeps the page's
				// converted source markup, so a declaration with no usable row
				// is skipped the same way instead of failing the import (#2054).
				// Other collections with no usable row, and an error about the
				// manifest itself rather than one of its rows, are rejected
				// outright. The rejection carries the collection and error count
				// alongside the errors themselves, because the user-facing gate
				// message is built from this data and "failed validation" without
				// the facts is not actionable (#1785).
				if ( ! self::errors_are_row_scoped( $validation['errors'], $collection ) || ( empty( $accepted ) && empty( $adapter['rejected_rows_degrade'] ) ) ) {
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
				if ( empty( $accepted ) ) {
					continue;
				}
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
		// Providers may declare intent from canonical facts or their owned state.
		// The selected capability still resolves through the ordinary provider boundary.
		foreach ( self::adapters() as $detector ) {
			$callback   = $detector['intent_callback'] ?? null;
			$capability = (string) ( $detector['capability'] ?? '' );
			if ( ! is_callable( $callback ) || ! call_user_func( $callback, $plan, $args ) ) {
				continue; }
			$declared = false;
			foreach ( $lifecycle['dependencies'] as $dependency ) {
				$declared = $declared || ( $dependency['adapter']['capability'] ?? '' ) === $capability;
			}
			if ( $declared ) {
				continue; }
			$adapter = self::adapter_for_capability( $capability );
			if ( empty( $adapter ) ) {
				return new WP_Error( 'static_site_importer_runtime_provider_unavailable', 'No provider is configured for required canonical capability: ' . $capability . '.' );
			}
			if ( ! in_array( $adapter['materialization_stage'] ?? 'before_pages', array( 'before_pages', 'after_pages' ), true ) ) {
				return new WP_Error( 'static_site_importer_runtime_adapter_stage_invalid', 'The selected native provider has an unsupported materialization stage.' );
			}
			$id                               = hash( 'sha256', "static-site-importer/provider-intent/v1\n" . $capability . "\n" . (string) ( $plan['source']['entry_path'] ?? '' ) );
			$declaration                      = array(
				'kind'                    => 'dependency',
				'capability'              => $capability,
				'reconciliation_identity' => $id,
			);
			$lifecycle['dependencies'][ $id ] = array(
				'adapter'     => $adapter,
				'declaration' => $declaration,
				'required'    => true,
			);
			if ( 'after_pages' === ( $adapter['materialization_stage'] ?? '' ) ) {
				$lifecycle['entities'][ $id ] = array(
					'adapter'     => $adapter,
					'declaration' => $declaration,
					'manifest'    => array( $adapter['entity_collection'] => array() ),
					'required'    => true,
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

	/**
	 * Report the rows of a runtime entity declaration a provider validator rejected.
	 *
	 * Entity validators are per row by contract, so the declaration is still
	 * materialized from the rows that validated (`runtime_entity_rows_rejected`),
	 * and an opted-in region declaration with no valid row is dropped whole
	 * (`runtime_declaration_rejected`, a degraded unit and an accepted, reported
	 * loss). The rejected rows keep the converted source markup already on the
	 * page and their bindings are ignored. The finding names the first rejected
	 * row's path; `errors` lists every row.
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
		$errors     = array_values( array_filter( $errors, 'is_array' ) );
		$path       = (string) ( $errors[0]['path'] ?? '' );
		$reason     = (string) ( $errors[0]['message'] ?? '' );
		$dropped    = 0 === $accepted;
		return array(
			'id'                      => ( $dropped ? 'runtime-declaration-rejected-' : 'runtime-entity-rows-rejected-' ) . hash( 'sha256', $declaration_id . "\n" . $collection ),
			'code'                    => $dropped ? 'runtime_declaration_rejected' : 'runtime_entity_rows_rejected',
			'type'                    => 'static-site-importer',
			'severity'                => 'warning',
			'stage'                   => 'entity_materialization',
			'loss_class'              => $dropped ? Static_Site_Importer_Diagnostic_Loss_Classes::DEGRADED_UNIT : Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
			'unit'                    => 'item',
			'owning_layer'            => Static_Site_Importer_Diagnostic_Loss_Classes::OWNING_LAYER_BLOCKS_ENGINE,
			'path'                    => $path,
			'declaration_id'          => $declaration_id,
			'reconciliation_identity' => $declaration_id,
			'provider'                => (string) ( $adapter['provider'] ?? '' ),
			'entity_collection'       => $collection,
			'declared_count'          => $declared,
			'accepted_count'          => $accepted,
			'rejected_count'          => $rejected,
			'errors'                  => array_slice( $errors, 0, 16 ),
			'message'                 => $dropped
				? 'The ' . $collection . ' declaration was dropped: ' . $rejected . ' of ' . $declared . ' declared ' . $collection . ' failed SSI provider validation (' . $path . ': ' . $reason . '). The imported pages keep their converted source markup and its runtime bindings are ignored.'
				: $rejected . ' of ' . $declared . ' declared ' . $collection . ' failed SSI provider validation and were skipped (' . $path . ': ' . $reason . '); ' . $accepted . ' materialized. The imported pages keep their converted source markup for the skipped rows.',
		);
	}

	/** Whether every validator error names one row of the collection rather than the manifest itself. */
	private static function errors_are_row_scoped( array $errors, string $collection ): bool {
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || ! str_starts_with( (string) ( $error['path'] ?? '' ), '$.' . $collection . '[' ) ) {
				return false;
			}
		}
		return true;
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
		if ( 'entity_collection' === $kind && in_array( $name, array( 'product', 'products', 'cart', 'carts', 'product_grids', 'product_listings', 'order_lookup', 'order_lookups' ), true ) ) {
			return 'shop';
		}
		if ( 'entity_collection' === $kind && 'external_metrics' === $name ) {
			return 'external_metrics';
		}
		return 'entity_collection' === $kind && in_array( $name, array( 'form', 'forms' ), true ) ? 'form' : '';
	}

	/**
	 * Recover source description elements omitted from older form declarations.
	 *
	 * @param array $entities Declared form entities.
	 * @param array $plan     Site plan carrying the matching fallback evidence.
	 * @return array
	 */
	public static function with_form_source_html( array $entities, array $plan ): array {
		$sources = array();
		foreach ( $plan['quality']['fallbacks'] ?? array() as $fallback ) {
			if ( is_array( $fallback ) && 'html_form_fallback' === ( $fallback['diagnostic_code'] ?? $fallback['code'] ?? '' ) ) {
				$key             = (string) ( $fallback['source_path'] ?? $fallback['source'] ?? '' ) . "\n" . (string) ( $fallback['selector'] ?? '' );
				$sources[ $key ] = $fallback;
			}
		}
		foreach ( $entities as &$entity ) {
			if ( is_array( $entity ) ) {
				$key = (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' );
				if ( isset( $sources[ $key ] ) ) {
					$entity['form_intent'] = Static_Site_Importer_Form_Fallback_Contract::intent( $sources[ $key ] );
					if ( ! isset( $entity['html'] ) && is_string( $sources[ $key ]['html'] ?? null ) ) {
						$entity['html'] = $sources[ $key ]['html'];
					}
				}
			}
		}
		unset( $entity );
		return array_values( array_filter( $entities, static fn( $entity ): bool => ! is_array( $entity ) || 'form' === Static_Site_Importer_Form_Fallback_Contract::route( $entity ) ) );
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
		$dropped = array();
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
					// The resolver projects destination references into the markup a
					// provider renders in place of page content: binding anchors, and
					// a collection's item template card and per-item slot values.
					foreach ( array( 'bindings', 'item_template', 'items' ) as $resolved_field ) {
						if ( isset( $resolved_by_key[ $entity_key ][ $resolved_field ] ) && is_array( $resolved_by_key[ $entity_key ][ $resolved_field ] ) ) {
							$prepared['manifest'][ $key ][ $index ][ $resolved_field ] = $resolved_by_key[ $entity_key ][ $resolved_field ];
						}
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
				$entities = self::with_form_source_html( $entities, $resolved );
				$entities = array_map( static fn( $entity ) => is_array( $entity ) ? self::prepare_form_entity( $entity ) : $entity, $entities );
			}
			$manifest = array( $key => $entities );
			if ( 'products' === $key ) {
				$manifest['schema_version'] = 1;
			}
			$validation = self::validate_manifest_generic( $prepared['adapter'], $manifest );
			if ( ! empty( $validation['errors'] ) ) {
				// An opted-in region row the validator rejects degrades the same way an
				// inline one does: the rejected rows keep their converted source
				// markup, and a declaration with no valid row is dropped whole.
				if ( empty( $prepared['adapter']['rejected_rows_degrade'] ) || ! self::errors_are_row_scoped( $validation['errors'], $key ) ) {
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
				$accepted                   = is_array( $validation[ $key ] ?? null ) ? $validation[ $key ] : array();
				$lifecycle['diagnostics'][] = self::rejected_runtime_entity_rows_diagnostic( $id, $prepared['adapter'], count( $entities ), count( $accepted ), $validation['errors'] );
				if ( empty( $accepted ) ) {
					$dropped[] = $id;
					continue;
				}
				$validation[ $key ] = $accepted;
			}
			$prepared['manifest'] = array( $key => $validation[ $key ] ?? array() );
			if ( 'products' === $key ) {
				$prepared['manifest']['schema_version'] = 1;
			}
		}
		unset( $prepared );
		foreach ( $dropped as $id ) {
			unset( $lifecycle['entities'][ $id ], $lifecycle['dependencies'][ $id ] );
		}
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
			if ( ( $adapter['materialization_stage'] ?? 'before_pages' ) !== ( $args['materialization_stage'] ?? 'before_pages' ) ) {
				continue;
			}
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

	/**
	 * Attach producer add-to-cart form anchors to an already seeded product.
	 * Matching is deliberately independent of labels, titles and button text.
	 *
	 * @param array<string,mixed> $lifecycle Validated entity lifecycle.
	 * @param array<string,mixed> $reports Seeded provider reports, extended with binding results.
	 * @param array<int,mixed>    $fallbacks Producer fallback diagnostics.
	 * @param array<string,mixed> $resolved Resolved plan containing the binding target documents.
	 * @return array<string,mixed> Lifecycle including form-only product bindings.
	 */
	public static function with_product_form_bindings( array $lifecycle, array &$reports, array $fallbacks, array $resolved ): array {
		$documents = Static_Site_Importer_Site_Plan_Preparation::runtime_binding_documents( $resolved );
		foreach ( $fallbacks as $fallback ) {
			if ( ! is_array( $fallback ) || 'commerce_add_to_cart' !== Static_Site_Importer_Form_Fallback_Contract::intent( $fallback ) ) {
				continue;
			}
			$source        = (string) ( $fallback['source_path'] ?? $fallback['source'] ?? '' );
			$declared      = false;
			$anchor_hashes = self::fallback_anchor_hashes( $fallback );
			// Canonical producer anchors own this page region. Carry the form
			// diagnostic with each binding so its receipt joins the same fallback.
			// A binding naming its own pre-projection anchor hash joins only the
			// fallback whose own anchor hashes to it, so two placements of one
			// purchase form on a page each keep their own fallback instead of the
			// page's last one overwriting every placement.
			foreach ( $lifecycle['entities'] ?? array() as $declaration_id => $prepared ) {
				if ( 'products' !== ( $prepared['adapter']['entity_collection'] ?? '' ) ) {
					continue;
				}
				foreach ( $prepared['manifest']['products'] ?? array() as $product_index => $product ) {
					foreach ( $product['bindings'] ?? array() as $binding_index => $binding ) {
						if ( 'commerce_add_to_cart_form' !== ( $binding['role'] ?? '' ) || '' === $source || self::product_page_key( $source ) !== self::product_page_key( (string) ( $binding['source_path'] ?? '' ) ) ) {
							continue;
						}
						$projected_hash = $binding['projected_anchor']['source_block_hash'] ?? null;
						if ( is_string( $projected_hash ) && ! in_array( $projected_hash, $anchor_hashes, true ) ) {
							continue;
						}
						$lifecycle['entities'][ $declaration_id ]['manifest']['products'][ $product_index ]['bindings'][ $binding_index ]['product_form_fallback'] = $fallback;
						$declared = true;
					}
				}
			}
			if ( $declared ) {
				continue;
			}
			$matches = array( array(), array() );
			foreach ( $lifecycle['entities'] ?? array() as $declaration_id => $prepared ) {
				if ( 'products' !== ( $prepared['adapter']['entity_collection'] ?? '' ) ) {
					continue;
				}
				foreach ( $prepared['manifest']['products'] ?? array() as $product ) {
					$result = array();
					foreach ( $reports[ $declaration_id ]['products'] ?? array() as $row ) {
						if ( ( $row['slug'] ?? '' ) === ( $product['slug'] ?? '' ) && in_array( $row['status'] ?? '', array( 'created', 'updated' ), true ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
							$result = $row;
						}
					}
					if ( empty( $result ) ) {
						continue;
					}
					$candidate = array( $prepared['adapter'], $result );
					foreach ( array( 'source_page', 'source_path', 'url' ) as $field ) {
						// Card/grid occurrences share the page source, not its product identity.
						if ( empty( $product['selector'] ) && empty( $product['bindings'] ) && is_string( $product[ $field ] ?? null ) && '' !== $source && self::product_page_key( $source ) === self::product_page_key( $product[ $field ] ) ) {
							$matches[0][] = $candidate;
							break;
						}
					}
					foreach ( $fallback['controls'] ?? array() as $control ) {
						if ( ! is_array( $control ) || 'hidden' !== ( $control['type'] ?? '' ) || 'id' !== ( $control['name'] ?? '' ) || '' === (string) ( $control['value'] ?? '' ) ) {
							continue;
						}
						foreach ( $product['variants'] ?? array() as $variant ) {
							foreach ( array( 'source_id', 'id', 'sku' ) as $field ) {
								if ( (string) ( $variant[ $field ] ?? '' ) === (string) $control['value'] ) {
									$matches[1][ (string) $result['id'] ] = $candidate;
								}
							}
						}
					}
				}
			}
			if ( 1 < count( $matches[0] ) ) {
				$id             = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback );
				$reports[ $id ] = array( 'product_form_binding_reason' => 'ambiguous_page_product' );
				continue;
			}
			$match = empty( $matches[0] ) ? $matches[1] : $matches[0];
			if ( 1 !== count( $match ) ) {
				continue;
			}
			list( $adapter, $result ) = array_values( $match )[0];
			$anchors                  = is_array( $fallback['bindings'] ?? null ) ? $fallback['bindings'] : array();
			if ( empty( $anchors ) ) {
				$region = Static_Site_Importer_Product_Finding_Materializer::serialize_readable_graft_anchor( $fallback['readable_blocks'] ?? array() );
				if ( '' !== $region ) {
					$anchors[] = array(
						'source_path'         => $source,
						'search_block_markup' => $region,
						'occurrence'          => 1,
					);
				}
			}
			$anchors = array_values(
				array_filter(
					$anchors,
					static function ( $anchor ) use ( $documents, $resolved ): bool {
						if ( ! is_array( $anchor ) || ! is_string( $anchor['search_block_markup'] ?? null ) || '' === trim( $anchor['search_block_markup'] ) || ! is_int( $anchor['occurrence'] ?? null ) || $anchor['occurrence'] < 1 ) {
							return false;
						}
						$document = $documents[ $anchor['source_path'] ?? '' ] ?? null;
						$markup   = null === $document ? '' : (string) ( $resolved[ $document['group'] ][ $document['index'] ]['resolved_block_markup'] ?? '' );
						return substr_count( $markup, $anchor['search_block_markup'] ) >= $anchor['occurrence'];
					}
				)
			);
			if ( empty( $anchors ) ) {
				$id             = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback );
				$reports[ $id ] = array( 'product_form_binding_reason' => 'anchor_not_in_page' );
				continue;
			}
			foreach ( $anchors as &$anchor ) {
				$anchor['role'] = 'commerce_add_to_cart_form';
			}
			unset( $anchor );
			$entity                       = $fallback;
			$entity['entity_kind']        = 'add_to_cart_form';
			$entity['slug']               = $result['slug'];
			$entity['bindings']           = $anchors;
			$id                           = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback );
			$lifecycle['entities'][ $id ] = array(
				'adapter'  => $adapter,
				'manifest' => array( 'products' => array( $entity ) ),
			);
			$reports[ $id ]               = array( 'products' => array( $result ) );
		}
		return $lifecycle;
	}

	/**
	 * The pre-projection anchor hashes one fallback stands for: its single
	 * binding anchor, or every anchor in its binding list.
	 *
	 * @param array<string,mixed> $fallback Producer fallback diagnostics.
	 * @return array<int,string>
	 */
	private static function fallback_anchor_hashes( array $fallback ): array {
		$hashes         = array();
		$single_markup  = is_array( $fallback['binding'] ?? null ) ? ( $fallback['binding']['search_block_markup'] ?? null ) : null;
		$anchor_markups = is_array( $fallback['bindings'] ?? null ) ? $fallback['bindings'] : array();
		if ( is_string( $single_markup ) && '' !== $single_markup ) {
			$hashes[] = hash( 'sha256', $single_markup );
		}
		foreach ( $anchor_markups as $anchor ) {
			$markup = is_array( $anchor ) ? ( $anchor['search_block_markup'] ?? null ) : null;
			if ( is_string( $markup ) && '' !== $markup ) {
				$hashes[] = hash( 'sha256', $markup );
			}
		}
		return $hashes;
	}

	/**
	 * Canonical route comparison, never basename-only matching across products.
	 *
	 * @param string $path Producer URL or source filename.
	 * @return string
	 */
	private static function product_page_key( string $path ): string {
		$path = (string) parse_url( $path, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Also used before WordPress loads.
		$path = preg_replace( '#^(?:/?website/)|(?:/index\\.html$)|(?:\\.html$)#', '', $path );
		return trim( (string) $path, '/' );
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
						$entity = $member['entity'];
						if ( 'products' === $entity_key && 'commerce_add_to_cart_form' === ( $member['binding']['role'] ?? '' ) ) {
							$entity                = array_merge( $entity, $member['binding']['product_form_fallback'] ?? array() );
							$entity['entity_kind'] = 'add_to_cart_form';
						}
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
						$record = self::block_binding_record( $declaration_id, $member['binding'], $replacement, $entity, $prepared['adapter'] );
						if ( 'woocommerce_product_listing' === ( $prepared['adapter']['id'] ?? '' ) && 'commerce_listing' === ( $member['binding']['role'] ?? '' ) ) {
							$record['archive_replacement_block_markup'] = Static_Site_Importer_Woo_Product_Listing_Materializer::archive_block_markup( $entity );
						}
						$bindings[] = $record;
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
		// An order lookup is a source form fallback the shop provider owns, so its
		// receipt carries the same form fallback reconciliation identity and hash
		// a form binding does.
		$is_grid          = in_array( $binding['role'], array( 'commerce_collection', 'commerce_product_grid' ), true );
		$is_form_contract = in_array( $binding['role'], array( 'form', 'order_lookup', 'commerce_add_to_cart_form' ), true );
		// A canonical product-grid binding records the grid's own source selector so
		// quality reconciliation can join the detected grid finding to this receipt
		// by the one evidence both sides carry, instead of a preserved-region hash
		// the finding no longer derives (Automattic/blocks-engine#3088). A store
		// listing that adopted a grid replaces that grid inside its own region (see
		// with_adopted_product_regions()), so its receipt answers for the grid.
		// A cart binding replaces its whole cart view the same way and joins its
		// finding by the cart view's own selector.
		$grid_entity                       = in_array( $binding['role'], array( 'commerce_product_grid', 'commerce_cart' ), true ) ? $entity : ( 'commerce_listing' === $binding['role'] && is_array( $entity['grid'] ?? null ) ? $entity['grid'] : array() );
		$grid_selector                     = is_string( $grid_entity['selector'] ?? null ) ? trim( (string) $grid_entity['selector'] ) : '';
		list( $grid_identity, $grid_hash ) = '' === $grid_selector ? array( '', '' ) : self::page_region_fallback_identity( self::fallback_region( $binding['role'] ), (string) $binding['source_path'], $grid_selector );
		return array(
			'schema'                           => 'static-site-importer/runtime-entity-binding/v1',
			'source_path'                      => $binding['source_path'],
			'search_block_markup'              => $binding['search_block_markup'],
			'replacement_block_markup'         => $replacement,
			'occurrence'                       => $binding['occurrence'],
			'role'                             => $binding['role'],
			'declaration_id'                   => $declaration_id,
			'selector'                         => $grid_selector,
			'reconciliation_identity'          => hash( 'sha256', "static-site-importer/runtime-entity-binding/v1\n{$declaration_id}\n{$binding['source_path']}\n{$binding['occurrence']}\n" . hash( 'sha256', $binding['search_block_markup'] ) ),
			'fallback_reconciliation_identity' => $is_form_contract ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $entity ) : ( '' !== $grid_selector ? $grid_identity : ( $is_grid ? self::product_grid_fallback_reconciliation_identity( $binding ) : '' ) ),
			'fallback_hash'                    => $is_form_contract ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $entity ) : ( '' !== $grid_selector ? $grid_hash : ( $is_grid ? hash( 'sha256', $binding['search_block_markup'] ) : '' ) ),
			'materialized_block_hash'          => $is_form_contract || $is_grid || '' !== $grid_selector ? hash( 'sha256', $replacement ) : '',
			'provider'                         => $adapter['provider'] ?? '',
			'superseded_runtime_selectors'     => $binding['superseded_runtime_selectors'] ?? array(),
			'replaced_fallback_identities'     => $is_form_contract ? self::replaced_fallback_identities( $entity ) : array(),
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

	/** The replaced-region kind a provider binding role reconciles as. */
	public static function fallback_region( string $role ): string {
		return 'commerce_cart' === $role ? 'cart-view' : 'product-grid';
	}

	/**
	 * Derive a provider-replaced region's fallback reconciliation identity and
	 * source-evidence hash from the canonical source page plus the region's own
	 * source selector — the one evidence both the detected finding and a
	 * completed binding receipt carry. A product grid (`product-grid`) and a
	 * cart view (`cart-view`) are replaced whole by their provider block, and
	 * since Automattic/blocks-engine#3088 a grid finding carries no
	 * `readable_blocks`, so no preserved-region hash exists on the finding side;
	 * the page-scoped selector is the matching evidence instead. Both
	 * reconciliation sides call this one derivation, so the pair can never drift
	 * apart, and the region kind keeps a grid and a cart on one page apart.
	 *
	 * @return array{0:string,1:string} [identity, fallback_hash], both '' when the page/selector pair is incomplete.
	 */
	public static function page_region_fallback_identity( string $region, string $source_path, string $selector ): array {
		$source_path = trim( $source_path );
		$selector    = trim( $selector );
		if ( '' === $source_path || '' === $selector ) {
			return array( '', '' );
		}
		$fallback_hash = hash( 'sha256', "static-site-importer/{$region}-fallback/v2\n{$source_path}\n{$selector}" );
		$identity      = hash( 'sha256', "static-site-importer/{$region}-fallback-reconciliation/v2\n{$source_path}\n{$selector}\n{$fallback_hash}" );
		return array( $identity, $fallback_hash );
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
		require_once __DIR__ . '/class-static-site-importer-redirection-materializer.php';
		require_once __DIR__ . '/class-static-site-importer-woo-cart-materializer.php';
		require_once __DIR__ . '/class-static-site-importer-woo-order-lookup-materializer.php';
		require_once __DIR__ . '/class-static-site-importer-woo-product-grid-materializer.php';
		require_once __DIR__ . '/class-static-site-importer-woo-product-listing-materializer.php';
		// A capability's first adapter is its default; collection adapters follow it.
		foreach ( array( 'Static_Site_Importer_Woo_Product_Seeder', 'Static_Site_Importer_Woo_Cart_Materializer', 'Static_Site_Importer_Woo_Order_Lookup_Materializer', 'Static_Site_Importer_Woo_Product_Grid_Materializer', 'Static_Site_Importer_Woo_Product_Listing_Materializer', 'Static_Site_Importer_Form_Seeder', 'Static_Site_Importer_TEC_Event_Seeder', 'Static_Site_Importer_External_Metric_Runtime', 'Static_Site_Importer_TranslatePress_Materializer', 'Static_Site_Importer_Redirection_Materializer' ) as $owner ) {
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

	/** Let native providers retain portable runtime state through the shared export. */
	public static function export_runtime_features( array $artifact, array $args ) {
		foreach ( self::adapters() as $adapter ) {
			$callback = $adapter['export_callback'] ?? null;
			if ( ! is_callable( $callback ) ) {
				continue; }
			$artifact = call_user_func( $callback, $artifact, $args );
			if ( is_wp_error( $artifact ) ) {
				return $artifact; }
			if ( ! is_array( $artifact ) ) {
				return new WP_Error( 'static_site_importer_provider_export_invalid', 'A native provider returned no portable export artifact.' );
			}
		}
		return $artifact;
	}

	/**
	 * Validate a `generic/carts/v1` manifest: each cart view names its source
	 * page and selector and carries exactly its canonical `commerce_cart` anchor.
	 *
	 * @param mixed $data Decoded manifest.
	 * @return array{carts:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_carts_manifest( mixed $data ): array {
		$rows   = is_array( $data ) && is_array( $data['carts'] ?? null ) && array_is_list( $data['carts'] ) ? $data['carts'] : null;
		$errors = array();
		$carts  = array();
		if ( null === $rows ) {
			return array(
				'carts'  => array(),
				'errors' => array(
					array(
						'path'    => '$.carts',
						'message' => 'carts must be a list of cart views.',
					),
				),
			);
		}
		foreach ( $rows as $index => $row ) {
			$path     = '$.carts[' . $index . ']';
			$bindings = is_array( $row ) && is_array( $row['bindings'] ?? null ) && array_is_list( $row['bindings'] ) ? array_map( static fn( mixed $binding ): ?array => self::normalize_block_binding( $binding ), $row['bindings'] ) : array();
			$valid    = array() !== $bindings && array() === array_filter( $bindings, static fn( ?array $binding ): bool => empty( $binding ) || 'commerce_cart' !== $binding['role'] );
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['source_path'] ?? '' ) ) || '' === trim( (string) ( $row['selector'] ?? '' ) ) || ! $valid ) {
				$errors[] = array(
					'path'    => $path,
					'message' => 'cart view must carry source_path, selector and canonical commerce_cart generic/block-binding/v1 anchors.',
				);
				continue;
			}
			$carts[] = array(
				'source_path' => (string) $row['source_path'],
				'selector'    => (string) $row['selector'],
				'bindings'    => $bindings,
			);
		}
		return array(
			'carts'  => $carts,
			'errors' => $errors,
		);
	}

	/**
	 * Validate a `generic/order-lookups/v1` manifest: each order-lookup form
	 * names its source page and selector, its order and email fields plus submit
	 * label, and carries exactly its canonical `order_lookup` anchor. The form
	 * fallback reconciliation metadata a producer stamps on the entity is kept so
	 * its completed binding receipt proves which source form fallback it replaced.
	 *
	 * @param mixed $data Decoded manifest.
	 * @return array{order_lookups:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_order_lookups_manifest( mixed $data ): array {
		$rows   = is_array( $data ) && is_array( $data['order_lookups'] ?? null ) && array_is_list( $data['order_lookups'] ) ? $data['order_lookups'] : null;
		$errors = array();
		$forms  = array();
		if ( null === $rows ) {
			return array(
				'order_lookups' => array(),
				'errors'        => array(
					array(
						'path'    => '$.order_lookups',
						'message' => 'order_lookups must be a list of order-lookup forms.',
					),
				),
			);
		}
		foreach ( $rows as $index => $row ) {
			$path     = '$.order_lookups[' . $index . ']';
			$bindings = is_array( $row ) && is_array( $row['bindings'] ?? null ) && array_is_list( $row['bindings'] ) ? array_map( static fn( mixed $binding ): ?array => self::normalize_block_binding( $binding ), $row['bindings'] ) : array();
			$fields   = is_array( $row ) && is_array( $row['fields'] ?? null ) ? $row['fields'] : array();
			$valid    = 1 === count( $bindings ) && ! empty( $bindings[0] ) && 'order_lookup' === $bindings[0]['role'];
			foreach ( array( 'order', 'email' ) as $field_key ) {
				$field = is_array( $fields[ $field_key ] ?? null ) ? $fields[ $field_key ] : null;
				$valid = $valid && null !== $field && is_string( $field['label'] ?? null ) && '' !== trim( $field['label'] ) && ( ! isset( $field['placeholder'] ) || is_string( $field['placeholder'] ) );
			}
			$valid = $valid && '' !== trim( (string) ( $row['submit_label'] ?? '' ) );
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['source_path'] ?? '' ) ) || '' === trim( (string) ( $row['selector'] ?? '' ) ) || ! $valid ) {
				$errors[] = array(
					'path'    => $path,
					'message' => 'order-lookup form must carry source_path, selector, labelled order and email fields, a submit label and one canonical order_lookup generic/block-binding/v1 anchor.',
				);
				continue;
			}
			$form = array(
				'source_path'  => (string) $row['source_path'],
				'selector'     => (string) $row['selector'],
				'fields'       => array(
					'order' => array_filter( array_intersect_key( $fields['order'], array_flip( array( 'label', 'placeholder' ) ) ), 'is_string' ),
					'email' => array_filter( array_intersect_key( $fields['email'], array_flip( array( 'label', 'placeholder' ) ) ), 'is_string' ),
				),
				'submit_label' => (string) $row['submit_label'],
				'bindings'     => $bindings,
			);
			// A producer that stamps the replaced form fallback onto the entity keeps
			// that metadata through validation: the identity names the fallback and
			// the captured form facts hash it, the way form entities carry them.
			if ( isset( $row['form'] ) && is_array( $row['form'] ) ) {
				$form['form'] = $row['form'];
			}
			if ( isset( $row['controls'] ) && is_array( $row['controls'] ) ) {
				$form['controls'] = array_values( array_filter( $row['controls'], 'is_array' ) );
			}
			$fallback_identity = $row['fallback_identity'] ?? $row['source_fallback_identity'] ?? $row['fallback_reconciliation_identity'] ?? '';
			if ( is_string( $fallback_identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fallback_identity ) ) {
				$form['fallback_identity'] = $fallback_identity;
			}
			$replaced = self::replaced_fallback_identities( $row );
			if ( array() !== $replaced ) {
				$form['replaced_fallback_identities'] = $replaced;
			}
			$forms[] = $form;
		}
		return array(
			'order_lookups' => $forms,
			'errors'        => $errors,
		);
	}

	/**
	 * Validate a producer product-grid manifest: each grid names its products,
	 * one card's block markup with the slots that vary per product, and one
	 * canonical `commerce_product_grid` anchor.
	 *
	 * @return array{product_grids:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_product_grids_manifest( mixed $data ): array {
		$rows   = is_array( $data ) && is_array( $data['product_grids'] ?? null ) && array_is_list( $data['product_grids'] ) ? $data['product_grids'] : null;
		$errors = array();
		$grids  = array();
		if ( null === $rows ) {
			return array(
				'product_grids' => array(),
				'errors'        => array(
					array(
						'path'    => '$.product_grids',
						'message' => 'product_grids must be a list of product grids.',
					),
				),
			);
		}
		foreach ( $rows as $index => $row ) {
			$path     = '$.product_grids[' . $index . ']';
			$template = is_array( $row ) && is_array( $row['item_template'] ?? null ) ? $row['item_template'] : array();
			$bindings = is_array( $row ) && is_array( $row['bindings'] ?? null ) && array_is_list( $row['bindings'] ) ? array_map( static fn( mixed $binding ): ?array => self::normalize_block_binding( $binding ), $row['bindings'] ) : array();
			$slots    = is_array( $template['slots'] ?? null ) && array_is_list( $template['slots'] ) ? $template['slots'] : array();
			$items    = is_array( $row ) && is_array( $row['items'] ?? null ) && array_is_list( $row['items'] ) ? $row['items'] : array();
			$valid    = 1 === count( $bindings ) && ! empty( $bindings[0] ) && 'commerce_product_grid' === $bindings[0]['role']
				&& 'generic/collection-item-template/v1' === ( $template['schema'] ?? null ) && is_string( $template['block_markup'] ?? null ) && '' !== trim( $template['block_markup'] )
				&& array() !== $slots && array() === array_filter( $slots, static fn( mixed $slot ): bool => ! is_array( $slot ) || ! is_string( $slot['id'] ?? null ) || ! is_string( $slot['role'] ?? null ) || ! is_array( $slot['path'] ?? null ) || ! array_is_list( $slot['path'] ) || array() !== array_filter( $slot['path'], static fn( mixed $step ): bool => ! is_int( $step ) || $step < 0 ) )
				&& array() !== $items && array() === array_filter( $items, static fn( mixed $item ): bool => ! is_array( $item ) || ! is_string( $item['product'] ?? null ) || '' === trim( $item['product'] ) || ( isset( $item['slots'] ) && ! is_array( $item['slots'] ) ) );
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['source_path'] ?? '' ) ) || '' === trim( (string) ( $row['selector'] ?? '' ) ) || ! $valid ) {
				$errors[] = array(
					'path'    => $path,
					'message' => 'product grid must carry source_path, selector, products, a generic/collection-item-template/v1 item template and one canonical commerce_product_grid anchor.',
				);
				continue;
			}
			$grids[] = array(
				'source_path'   => (string) $row['source_path'],
				'selector'      => (string) $row['selector'],
				'item_template' => $template,
				'items'         => $items,
				'bindings'      => $bindings,
			);
		}
		return array(
			'product_grids' => $grids,
			'errors'        => $errors,
		);
	}

	/**
	 * Validate `generic/product-listings/v1` store listings: a listing region,
	 * the grid it lists, and its filter, sort and count controls, each control
	 * anchored on its own source block.
	 *
	 * @return array{product_listings:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	public static function validate_product_listings_manifest( mixed $data ): array {
		$rows     = is_array( $data ) && is_array( $data['product_listings'] ?? null ) && array_is_list( $data['product_listings'] ) ? $data['product_listings'] : null;
		$errors   = array();
		$listings = array();
		if ( null === $rows ) {
			return array(
				'product_listings' => array(),
				'errors'           => array(
					array(
						'path'    => '$.product_listings',
						'message' => 'product_listings must be a list of product listings.',
					),
				),
			);
		}
		foreach ( $rows as $index => $row ) {
			$controls = is_array( $row ) && is_array( $row['controls'] ?? null ) && array_is_list( $row['controls'] ) ? $row['controls'] : array();
			$bindings = is_array( $row ) && is_array( $row['bindings'] ?? null ) && array_is_list( $row['bindings'] ) ? array_map( static fn( mixed $binding ): ?array => self::normalize_block_binding( $binding ), $row['bindings'] ) : array();
			$ids      = array();
			foreach ( $controls as $control ) {
				$ids[] = is_array( $control ) && is_string( $control['id'] ?? null ) && is_string( $control['kind'] ?? null ) ? $control['id'] : '';
			}
			$regions = 0;
			$valid   = array() !== $bindings && ! in_array( '', $ids, true ) && count( $ids ) === count( array_unique( $ids ) );
			foreach ( $valid ? $bindings : array() as $binding ) {
				$regions += 'commerce_listing' === ( $binding['role'] ?? '' ) ? 1 : 0;
				$valid    = $valid && ! empty( $binding ) && ( 'commerce_listing' === $binding['role'] || ( 'commerce_listing_control' === $binding['role'] && in_array( $binding['control'] ?? '', $ids, true ) ) );
			}
			if ( ! is_array( $row ) || ! $valid || 1 !== $regions || '' === trim( (string) ( $row['source_path'] ?? '' ) ) || '' === trim( (string) ( $row['selector'] ?? '' ) ) || '' === trim( (string) ( $row['grid_selector'] ?? '' ) ) ) {
				$errors[] = array(
					'path'    => '$.product_listings[' . $index . ']',
					'message' => 'product listing must carry source_path, selector, grid_selector, controls with unique ids and kinds, one commerce_listing anchor and commerce_listing_control anchors naming those controls.',
				);
				continue;
			}
			$listings[] = array(
				'source_path'   => (string) $row['source_path'],
				'selector'      => (string) $row['selector'],
				'grid_selector' => (string) $row['grid_selector'],
				'controls'      => $controls,
				'bindings'      => $bindings,
			);
		}
		return array(
			'product_listings' => $listings,
			'errors'           => $errors,
		);
	}

	/**
	 * A product grid replaces its whole source region with one provider loop,
	 * so every other provider binding that claims a range inside that region —
	 * each card's own controls, or the region itself as a product collection —
	 * is superseded by it. Grids whose script hooks cannot be retired, and
	 * grids whose region is not on its page, adopt nothing and keep the
	 * per-card bindings.
	 *
	 * A store listing whose region holds an adopted grid adopts that grid in
	 * turn: the listing becomes one WooCommerce archive collection around the
	 * listing region, the grid lists the archive's products, and the listing's
	 * controls become WooCommerce's filter, sort and count blocks. The listing
	 * then replaces its whole region, so its control anchors and the grid's
	 * own anchor are carried inside it. A listing without an adopted grid in
	 * its region, or whose only control is a result count, keeps its source
	 * controls, and its grid keeps its own product collection.
	 *
	 * @param array<string,mixed> $lifecycle Lifecycle with resolved manifests.
	 * @param array<string,mixed> $plan      Resolved site plan.
	 * @return array<string,mixed>
	 */
	public static function with_adopted_product_regions( array $lifecycle, array $plan ): array {
		$pages      = array();
		$categories = array();
		$members    = array();
		foreach ( Static_Site_Importer_Site_Plan_Preparation::runtime_binding_documents( $plan ) as $source_path => $document ) {
			$page                  = $plan[ $document['group'] ][ $document['index'] ];
			$pages[ $source_path ] = (string) ( $page['resolved_block_markup'] ?? '' );
			// A listing page is its product category; a template listing is
			// the catalog archive itself.
			if ( 'pages' === $document['group'] ) {
				$categories[ $source_path ] = array(
					'name' => (string) ( $page['title'] ?? '' ),
					'slug' => (string) ( $page['slug'] ?? '' ),
				);
			}
		}
		$range   = static function ( array $binding ) use ( $pages ): ?array {
			$content = $pages[ (string) ( $binding['source_path'] ?? '' ) ] ?? '';
			$search  = (string) ( $binding['search_block_markup'] ?? '' );
			$found   = -1;
			for ( $occurrence = 0; '' !== $search && $occurrence < (int) ( $binding['occurrence'] ?? 0 ); ++$occurrence ) {
				$found = strpos( $content, $search, $found + 1 );
				if ( false === $found ) {
					return null;
				}
			}
			return $found < 0 ? null : array( (string) $binding['source_path'], $found, $found + strlen( $search ) );
		};
		$within  = static fn( array $inner, array $outer ): bool => $outer[0] === $inner[0] && $outer[1] <= $inner[1] && $inner[2] <= $outer[2];
		$adopted = array();
		$grids   = array();
		foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
			if ( 'product_grids' !== ( $prepared['adapter']['entity_collection'] ?? '' ) ) {
				continue;
			}
			$kept = array();
			foreach ( $prepared['manifest']['product_grids'] ?? array() as $grid ) {
				$binding = is_array( $grid ) && is_array( $grid['bindings'][0] ?? null ) ? $grid['bindings'][0] : array();
				$claim   = array() !== $binding && null !== Static_Site_Importer_Woo_Product_Grid_Materializer::retirable_hooks( $binding['superseded_runtime_selectors'] ?? array() ) ? $range( $binding ) : null;
				if ( null !== $claim ) {
					$adopted[] = array( $claim, (string) $id );
					$grids[]   = array( $claim, $grid );
					$kept[]    = $grid;
				}
			}
			$lifecycle['entities'][ $id ]['manifest']['product_grids'] = $kept;
		}
		foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
			if ( 'product_listings' !== ( $prepared['adapter']['entity_collection'] ?? '' ) ) {
				continue;
			}
			foreach ( $prepared['manifest']['product_listings'] ?? array() as $index => $listing ) {
				$listing['category'] = $categories[ (string) ( $listing['source_path'] ?? '' ) ] ?? array(
					'name' => '',
					'slug' => '',
				);
				$region              = array_values( array_filter( is_array( $listing['bindings'] ?? null ) ? $listing['bindings'] : array(), static fn( mixed $binding ): bool => is_array( $binding ) && 'commerce_listing' === ( $binding['role'] ?? '' ) ) );
				// A listing becomes WooCommerce's archive only for a control that
				// changes what the archive query shows. A result count alone
				// is already true of the source grid, which keeps its own
				// product collection.
				$queried = array_filter( is_array( $listing['controls'] ?? null ) ? $listing['controls'] : array(), static fn( mixed $control ): bool => is_array( $control ) && 'result_count' !== ( $control['kind'] ?? '' ) );
				$claim   = 1 === count( $region ) && array() !== $queried ? $range( $region[0] ) : null;
				$owned   = null;
				foreach ( null === $claim ? array() : $grids as $grid ) {
					if ( null === $owned && $within( $grid[0], $claim ) && ( $grid[1]['source_path'] ?? null ) === ( $listing['source_path'] ?? null ) && ( $grid[1]['selector'] ?? null ) === ( $listing['grid_selector'] ?? null ) ) {
						$owned = $grid[1];
					}
				}
				// The region anchor is the listing's one binding; its controls
				// are replaced inside that region.
				$listing['grid']             = $owned;
				$listing['control_bindings'] = array_values( array_filter( is_array( $listing['bindings'] ?? null ) ? $listing['bindings'] : array(), static fn( mixed $binding ): bool => is_array( $binding ) && 'commerce_listing_control' === ( $binding['role'] ?? '' ) ) );
				$listing['bindings']         = null === $owned ? array() : $region;
				if ( null !== $owned ) {
					$adopted[] = array( $claim, (string) $id );
				}
				$lifecycle['entities'][ $id ]['manifest']['product_listings'][ $index ] = $listing;
				foreach ( is_array( $owned['items'] ?? null ) && '' !== $listing['category']['name'] ? $owned['items'] : array() as $item ) {
					$slug = is_array( $item ) ? sanitize_title( (string) ( $item['product'] ?? '' ) ) : '';
					if ( '' !== $slug ) {
						$members[ $slug ][] = $listing['category']['name'];
					}
				}
			}
		}
		// Listing membership is product data: the seeder assigns each product's
		// categories in one pass, whichever entity materializes first.
		foreach ( $members as $slug => $names ) {
			foreach ( $lifecycle['entities'] ?? array() as $id => $prepared ) {
				if ( 'products' !== ( $prepared['adapter']['entity_collection'] ?? '' ) ) {
					continue;
				}
				foreach ( $prepared['manifest']['products'] ?? array() as $index => $product ) {
					if ( is_array( $product ) && sanitize_title( (string) ( $product['slug'] ?? '' ) ) === $slug ) {
						$categories = is_array( $product['categories'] ?? null ) ? $product['categories'] : array();
						$lifecycle['entities'][ $id ]['manifest']['products'][ $index ]['categories'] = array_values( array_unique( array_merge( $categories, $names ) ) );
					}
				}
			}
		}
		if ( empty( $adopted ) ) {
			return $lifecycle;
		}
		foreach ( $lifecycle['entities'] as $id => $prepared ) {
			$collection = (string) ( $prepared['adapter']['entity_collection'] ?? '' );
			if ( ! is_array( $prepared['manifest'][ $collection ] ?? null ) ) {
				continue;
			}
			foreach ( $prepared['manifest'][ $collection ] as $index => $entity ) {
				if ( ! is_array( $entity ) || ! is_array( $entity['bindings'] ?? null ) ) {
					continue;
				}
				$kept = array_values(
					array_filter(
						$entity['bindings'],
						static function ( mixed $binding ) use ( $range, $adopted, $within, $id ): bool {
							$claim = is_array( $binding ) ? $range( $binding ) : null;
							foreach ( null === $claim ? array() : $adopted as $owner ) {
								// A region's own anchor is the one claim it keeps.
								if ( $within( $claim, $owner[0] ) && ( (string) $id !== $owner[1] || $claim !== $owner[0] ) ) {
									return false;
								}
							}
							return true;
						}
					)
				);
				$lifecycle['entities'][ $id ]['manifest'][ $collection ][ $index ]['bindings'] = $kept;
			}
		}
		return $lifecycle;
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
			foreach ( array( 'description', 'short_description', 'status', 'stock_status', 'sku', 'sale_price' ) as $field ) {
				if ( array_key_exists( $field, $product ) && ! is_string( $product[ $field ] ) ) {
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
			foreach ( array( 'categories', 'source_selectors', 'tags' ) as $field ) {
				if ( ! array_key_exists( $field, $product ) ) {
					continue;
				}
				$values = self::manifest_string_collection( $product[ $field ] );
				if ( null === $values || ( 'tags' === $field && ! array_is_list( $values ) ) ) {
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
			// A producer's single shopper-facing `category` joins `categories`.
			if ( isset( $product['category'] ) ) {
				if ( ! is_string( $product['category'] ) || '' === trim( $product['category'] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.category',
						'message' => 'category must be a non-empty string when provided.',
					);
				} else {
					$product['categories'] = array_values( array_unique( array_merge( is_array( $product['categories'] ?? null ) ? $product['categories'] : array(), array( trim( $product['category'] ) ) ) ) );
				}
			}
			if ( isset( $product['attributes'] ) ) {
				$attributes = self::manifest_product_attributes( $product['attributes'] );
				if ( null === $attributes ) {
					$errors[] = array(
						'path'    => $path_prefix . '.attributes',
						'message' => 'attributes must be a list of { key, label, value, value_label } objects with non-empty key, label, and value strings when provided.',
					);
				} else {
					$product['attributes'] = $attributes;
				}
			}
			if ( isset( $product['stock_quantity'] ) && ! is_int( $product['stock_quantity'] ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.stock_quantity',
					'message' => 'stock_quantity must be an integer when provided.',
				);
			}

			if ( array_key_exists( 'stock_status', $product ) && ! in_array( $product['stock_status'], array( 'instock', 'outofstock' ), true ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.stock_status',
					'message' => 'stock_status must be instock or outofstock.',
				);
			}
			if ( array_key_exists( 'images', $product ) ) {
				$images = $product['images'];
				if ( ! is_array( $images ) || ! array_is_list( $images ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.images',
						'message' => 'images must be a list of {src, alt} objects.',
					);
				} else {
					foreach ( $images as $entry ) {
						if ( ! is_array( $entry ) || ! is_string( $entry['src'] ?? null ) || '' === trim( $entry['src'] ) || ! is_string( $entry['alt'] ?? null ) ) {
							$errors[] = array(
								'path'    => $path_prefix . '.images',
								'message' => 'images must contain {src, alt} strings with non-empty src.',
							);
						}
					}
					if ( ! empty( $images ) && empty( $errors ) ) {
						$image = $images[0];
					}
				}
			}
			unset( $product['losses'] );
			if ( array_key_exists( 'variants', $product ) ) {
				$variants = self::manifest_product_variants( $product['variants'] );
				if ( null === $variants ) {
					unset( $product['variants'] );
					$product['losses'][] = array(
						'dimension'   => 'product',
						'reason_code' => 'unsupported_control_attribute',
						'attribute'   => 'variants',
					);
				} else {
					$product['variants'] = $variants;
				}
			}

			$summary = array(
				'name'          => $name,
				'slug'          => $slug,
				'regular_price' => $regular_price,
			);
			foreach ( array( 'sale_price', 'description', 'short_description', 'categories', 'attributes', 'status', 'stock_status', 'stock_quantity', 'source_selectors', 'sku', 'images', 'tags', 'variants', 'losses', 'source_page', 'source_path', 'url' ) as $field ) {
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

			if ( 'form' !== Static_Site_Importer_Form_Fallback_Contract::route( $form ) ) {
				continue; // Non-contact intents retain their commerce or preserved runtime route.
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
				'form_intent' => Static_Site_Importer_Form_Fallback_Contract::intent( $form ),
			);
			// Source HTML is inspected, never rendered, to retain description classes
			// that older producers omit from control metadata.
			if ( is_string( $form['html'] ?? null ) ) {
				$row['html'] = $form['html'];
			}
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
		if ( ! is_array( $binding ) || 'generic/block-binding/v1' !== ( $binding['schema'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! preg_match( '#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[^\x00-\x1f]+$#', $binding['source_path'] ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || strlen( $binding['search_block_markup'] ) > self::RUNTIME_DECLARATION_PAYLOAD_MAX_BYTES || ! is_string( $binding['role'] ?? null ) || ! in_array( $binding['role'], array( 'commerce_controls', 'commerce_collection', 'commerce_product_grid', 'commerce_listing', 'commerce_listing_control', 'commerce_cart', 'order_lookup', 'commerce_add_to_cart_form', 'form' ), true ) || ( isset( $binding['control'] ) && ( ! is_string( $binding['control'] ) || '' === $binding['control'] ) ) ) {
			return null;
		}
		$normalized = array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => $binding['source_path'],
			'search_block_markup' => $binding['search_block_markup'],
			'occurrence'          => $binding['occurrence'],
			'role'                => $binding['role'],
		);
		if ( isset( $binding['control'] ) ) {
			$normalized['control'] = $binding['control'];
		}
		// The producer's pre-projection anchor hash names which source fallback
		// this placement replaces, so keep it through normalization.
		$source_block_hash = $binding['projected_anchor']['source_block_hash'] ?? null;
		if ( is_string( $source_block_hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $source_block_hash ) ) {
			$normalized['projected_anchor'] = array( 'source_block_hash' => $source_block_hash );
		}
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

	/** Normalize variants atomically: partial option sets must never be seeded. */
	private static function manifest_product_variants( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$seen = array();
		foreach ( $value as &$variant ) {
			if ( ! is_array( $variant ) || ! is_string( $variant['regular_price'] ?? null ) || ! self::is_manifest_price( $variant['regular_price'] ) || ! in_array( $variant['stock_status'] ?? null, array( 'instock', 'outofstock' ), true ) ) {
				return null;
			}
			foreach ( array( 'sku', 'image', 'sale_price' ) as $field ) {
				if ( array_key_exists( $field, $variant ) && ( ! is_string( $variant[ $field ] ) || ( 'sale_price' === $field && ! self::is_manifest_price( $variant[ $field ] ) ) ) ) {
					return null;
				}
			}
			$attributes = self::manifest_product_attributes( $variant['attributes'] ?? null );
			if ( empty( $attributes ) ) {
				return null;
			}
			$combination = array();
			foreach ( $attributes as $attribute ) {
				$key = $attribute['key'];
				if ( ! preg_match( '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $key ) || ! preg_match( '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $attribute['value'] ) || isset( $combination[ $key ] ) ) {
					return null;
				}
				$combination[ $key ] = $attribute['value'];
			}
			ksort( $combination );
			$signature = http_build_query( $combination );
			if ( isset( $seen[ $signature ] ) ) {
				return null;
			}
			$seen[ $signature ]    = true;
			$variant['attributes'] = $attributes;
		}
		return $value;
	}

	/**
	 * Normalize shopper-facing global attribute values.
	 *
	 * @param mixed $value Candidate attribute list.
	 * @return array<int,array{key:string,label:string,value:string,value_label:string}>|null Null when malformed.
	 */
	private static function manifest_product_attributes( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$attributes = array();
		foreach ( $value as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				return null;
			}
			$text = array();
			foreach ( array( 'key', 'label', 'value', 'value_label' ) as $field ) {
				$candidate = $attribute[ $field ] ?? '';
				if ( ! is_string( $candidate ) || ( 'value_label' !== $field && '' === trim( $candidate ) ) ) {
					return null;
				}
				$text[ $field ] = trim( $candidate );
			}
			$attributes[] = array(
				'key'         => $text['key'],
				'label'       => $text['label'],
				'value'       => $text['value'],
				'value_label' => '' === $text['value_label'] ? $text['value'] : $text['value_label'],
			);
		}
		return $attributes;
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
