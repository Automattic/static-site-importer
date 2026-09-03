<?php
/**
 * Applies the canonical Blocks Engine WordPress site plan to a WordPress runtime.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime as Blocks_Engine_WordPress_Runtime;

require_once __DIR__ . '/class-static-site-importer-stylesheet-materializer.php';
require_once __DIR__ . '/class-static-site-importer-protected-page-policy.php';
require_once __DIR__ . '/class-static-site-importer-default-content.php';
require_once __DIR__ . '/class-static-site-importer-route-document-metadata.php';
if ( ! class_exists( 'Static_Site_Importer_Theme_Materialization_Strategy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-theme-materialization-strategy.php';
}
if ( ! class_exists( 'Static_Site_Importer_Classic_Theme_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-classic-theme-projection.php';
}
if ( ! class_exists( 'Static_Site_Importer_Current_Site_Capabilities' ) ) {
	require_once __DIR__ . '/class-static-site-importer-current-site-capabilities.php';
}
if ( ! class_exists( 'Static_Site_Importer_Quality_Budget_Admission' ) ) {
	require_once __DIR__ . '/class-static-site-importer-quality-budget-admission.php';
}

final class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public const RECEIPT_SCHEMA                    = 'static-site-importer/materialization-receipt/v2';
	private const RECONCILIATION_META_KEY          = '_static_site_importer_reconciliation_identity';
	private const PRODUCER_RECONCILIATION_META_KEY = '_blocks_engine_reconciliation_identity';
	private const BLOCK_PROVENANCE_LIMIT           = 50;

	/**
	 * Materialize a fully canonical v2 plan. Compilation and plan validation belong to Blocks Engine.
	 *
	 * @param array<string,mixed> $plan Canonical v2 plan.
	 * @param array<string,mixed> $args Materialization options.
	 * @return array<string,mixed> Receipt.
	 */
	public static function materialize( array $plan, array $args = array() ): array {
		$prepared = self::prepare_for_materialization( $plan, $args );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			return $prepared['receipt'];
		}
		return self::materialize_prepared( $prepared );
	}

	/**
	 * Apply prepared runtime declarations and the canonical plan as one transaction.
	 *
	 * Theme_Generator supplies compiler-owned declarations and projects the public
	 * import result; this boundary owns every WordPress/provider mutation.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function materialize_prepared_lifecycle( array $prepared, array $lifecycle, $companion_payload, array $gutenberg_gaps, array $theme_materialization ) {
		$args      = is_array( $prepared['args'] ?? null ) ? $prepared['args'] : array();
		$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $lifecycle, is_array( $prepared['resolved'] ?? null ) ? $prepared['resolved'] : array() );
		$classic   = Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === ( $args['theme_materialization'] ?? null );
		$preflight = $classic ? Static_Site_Importer_Theme_Generator::preflight_classic_runtime_entity_bindings( $prepared['args']['classic_theme_projection'], $lifecycle, $args ) : Static_Site_Importer_Theme_Generator::preflight_runtime_entity_binding_anchors( $prepared['resolved'] ?? array(), $lifecycle, $args );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$page_ready = ! empty( $args['page_ready_checkpoint'] );
		if ( $page_ready && Static_Site_Importer_Entity_Materializer_Registry::page_ready_requires_final_hydration( $lifecycle, $args ) ) {
			return new WP_Error(
				'static_site_importer_page_ready_runtime_bindings_deferred',
				'Page-ready materialization requires runtime entity bindings and must wait for complete-snapshot hydration.',
				array(
					'status'                => 'deferred',
					'materialization_scope' => 'page_ready',
				)
			);
		}
		$companion = self::materialize_companion_dependency( $companion_payload, $prepared );
		if ( is_wp_error( $companion ) ) {
			return $companion;
		}
		$dependencies = $page_ready ? array() : self::materialize_runtime_dependencies( $lifecycle, $args );
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}
		$entity_result = $page_ready ? array(
			'reports' => array(),
			'error'   => null,
		) : Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $lifecycle, $args );
		$entities      = $entity_result['reports'];
		if ( null !== $entity_result['error'] ) {
			return self::lifecycle_failure( $entity_result['error'], $lifecycle, $dependencies, $entities, 'entity_materialization' );
		}
		$bindings = $page_ready ? array() : Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $entities );
		if ( is_wp_error( $bindings ) ) {
			return self::lifecycle_failure(
				array(
					'code'    => $bindings->get_error_code(),
					'message' => $bindings->get_error_message(),
				),
				$lifecycle,
				$dependencies,
				$entities,
				'runtime_entity_bindings'
			);
		}
		$prepared['args']['runtime_entity_bindings'] = $classic ? array() : $bindings;
		if ( $classic ) {
			$classic_bindings = Static_Site_Importer_Entity_Materializer_Registry::classic_bindings( $lifecycle, $entities );
			if ( is_wp_error( $classic_bindings ) ) {
				return self::lifecycle_failure(
					array(
						'code'    => $classic_bindings->get_error_code(),
						'message' => $classic_bindings->get_error_message(),
					),
					$lifecycle,
					$dependencies,
					$entities,
					'classic_runtime_entity_bindings'
				);
			}
			$projection = Static_Site_Importer_Classic_Theme_Projection::apply_runtime_bindings( $prepared['args']['classic_theme_projection'], $classic_bindings );
			if ( is_wp_error( $projection ) ) {
				return self::lifecycle_failure(
					array(
						'code'    => $projection->get_error_code(),
						'message' => $projection->get_error_message(),
					),
					$lifecycle,
					$dependencies,
					$entities,
					'classic_runtime_projection'
				);
			}
			$prepared['args']['classic_theme_projection']  = $projection;
			$prepared['base_resolved']                     = Static_Site_Importer_Classic_Theme_Projection::with_projection_writes( $prepared['base_resolved'], $projection, (string) $prepared['theme']['uri'], (string) ( $prepared['args']['name'] ?? $prepared['theme']['slug'] ) );
			$prepared['prepared_resolved_projection_hash'] = self::prepared_resolved_projection_hash( $prepared['base_resolved'] );
			$prepared['args']['classic_runtime_bindings']  = $classic_bindings;
		}
		$prepared['args']['provider_layout_overlays']     = $page_ready ? array() : Static_Site_Importer_Entity_Materializer_Registry::provider_layout_overlays( $entities );
		$prepared['args']['font_materialization']         = $page_ready ? array() : ( $prepared['args']['font_materialization'] ?? array() );
		$prepared['args']['activate']                     = $page_ready ? false : ! empty( $prepared['args']['activate'] );
		$prepared['args']['defer_materialization_commit'] = true;

		$receipt                                  = self::materialize_prepared( $prepared );
		$receipt['completed']['companion_plugin'] = $companion;
		$receipt['extensions']['gutenberg_gaps']  = Static_Site_Importer_Theme_Generator::project_gutenberg_gaps( $gutenberg_gaps, (string) ( $companion['status'] ?? 'not_materialized' ) );
		$receipt['completed']['runtime_declarations']['dependencies'] = $dependencies;
		$receipt['completed']['runtime_declarations']['entities']     = $entities;
		$receipt['runtime_lifecycle']                                 = $lifecycle;
		if ( $classic ) {
			$receipt['completed']['runtime_declarations']['classic_html_bindings'] = $prepared['args']['classic_runtime_bindings'] ?? array();
		}
		$receipt['theme_materialization'] = $theme_materialization;
		if ( 'completed' !== $receipt['status'] ) {
			$error = $receipt['errors'][0] ?? array();
			Static_Site_Importer_Theme_Generator::append_entity_compensation( $receipt, $lifecycle, $entities, 'wordpress_site_plan_materialization', (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ) );
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan materialization failed.' ), $receipt );
		}
		return array(
			'receipt'      => $receipt,
			'lifecycle'    => $lifecycle,
			'dependencies' => $dependencies,
			'entities'     => $entities,
		);
	}

	/** Materialize the compiler-declared companion before provider dependencies, in every materialization scope. */
	private static function materialize_companion_dependency( $payload, array $prepared ) {
		$args = is_array( $prepared['args'] ?? null ) ? $prepared['args'] : array();
		if ( null === $payload ) {
			return array(
				'status' => 'skipped',
				'reason' => 'companion_plugin_payload_absent',
			);
		}
		if ( array_key_exists( 'materialize_dependencies', $args ) && false === (bool) $args['materialize_dependencies'] ) {
			return array(
				'status' => 'skipped',
				'reason' => 'dependency_materialization_disabled',
			);
		}
		$payload    = self::resolve_companion_asset_references( $payload, $prepared['plan'] ?? array(), $prepared['resolved'] ?? array() );
		$dependency = Static_Site_Importer_Dependency_Manager::companion_plugin_dependency( $payload );
		$result     = Static_Site_Importer_Dependency_Manager::materialize_companion_dependency( $dependency, ! empty( $args['overwrite'] ) );
		if ( 'failed' === ( $result['status'] ?? '' ) ) {
			$error = $result['error'] ?? array();
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_companion_plugin_materialization_failed' ), (string) ( $error['message'] ?? 'Companion-plugin materialization failed.' ), $result );
		}
		return $result;
	}

	/** Resolve browser-visible asset references carried by generated block renders. */
	private static function resolve_companion_asset_references( array $payload, array $plan, array $resolved ): array {
		$tokens    = isset( $plan['reference_tokens'] ) && is_array( $plan['reference_tokens'] ) ? $plan['reference_tokens'] : array();
		$theme_uri = isset( $resolved['resolution']['theme_uri'] ) && is_string( $resolved['resolution']['theme_uri'] ) ? $resolved['resolution']['theme_uri'] : '';
		if ( empty( $tokens ) || '' === $theme_uri ) {
			return $payload;
		}

		$entries       = array_values( array_filter( $plan['pages'] ?? array(), static fn( mixed $page ): bool => is_array( $page ) && ! empty( $page['entrypoint'] ) ) );
		$entry         = $entries[0] ?? null;
		$origin        = is_array( $entry ) && is_string( $entry['source_path'] ?? null ) ? $entry['source_path'] : '';
		$root          = '' === $origin || '.' === dirname( $origin ) ? '' : trim( dirname( $origin ), '/' );
		$canonicalizer = new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer( $tokens, $root );
		$references    = WordPressSitePlanResolver::references( $tokens, $theme_uri );

		foreach ( $payload['blocks'] ?? array() as $index => $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['render'] ?? null ) ) {
				continue;
			}
			$canonical                             = $canonicalizer->content( $block['render'], $origin );
			$payload['blocks'][ $index ]['render'] = WordPressSitePlanResolver::resolvePayload( $canonical, $references );
		}

		return $payload;
	}

	/** Public because checkpoint preparation provisions the same typed dependencies. */
	public static function materialize_runtime_dependencies( array $lifecycle, array $args ) {
		return Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle, $args );
	}

	/** Return a provider-compensated error before canonical plan mutation begins. */
	private static function lifecycle_failure( array $error, array $lifecycle, array $dependencies, array $entities, string $stage ): WP_Error {
		$failure = array(
			'status'            => 'partial',
			'runtime_lifecycle' => $lifecycle,
			'dependencies'      => $dependencies,
			'entities'          => $entities,
		);
		Static_Site_Importer_Theme_Generator::append_entity_compensation( $failure, $lifecycle, $entities, $stage, (string) $error['code'] );
		return new WP_Error( (string) $error['code'], (string) $error['message'], $failure );
	}

	/**
	 * Validate and resolve every destination without mutating WordPress or the filesystem.
	 *
	 * The resulting state may be passed to materialize_prepared(). That method prepares
	 * again before writing so changes after this check cannot bypass conflict protection.
	 *
	 * @param array<string,mixed> $plan Canonical v2 plan.
	 * @param array<string,mixed> $args Materialization options.
	 * @return array<string,mixed> Prepared state or a rejected receipt.
	 */
	public static function prepare( array $plan, array $args = array() ): array {
		// Payload readers hold transient workspace access and must never enter a
		// canonical plan hash, prepared-plan persistence, or receipt projection.
		$payload_reader = $args['_static_site_importer_payload_reader'] ?? null;
		unset( $args['_static_site_importer_payload_reader'] );
		$strategy = Static_Site_Importer_Theme_Materialization_Strategy::normalize( $args );
		if ( is_wp_error( $strategy ) ) {
			return self::failed_strategy_receipt( $plan, $args, $strategy );
		}
		$args['theme_materialization'] = $strategy['strategy'];
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] && ! is_array( $args['classic_theme_projection'] ?? null ) ) {
			return self::failed_strategy_receipt( $plan, $args, new WP_Error( 'static_site_importer_classic_source_projection_missing', 'Classic materialization requires the SSI source-artifact projection prepared before this plan-only boundary.' ) );
		}
		$default_content = self::discover_default_content( $args );
		$state           = array(
			'plan'                         => $plan,
			'plan_identity'                => self::plan_identity( $plan ),
			'receipt_instance_id'          => self::receipt_instance_id(),
			'diagnostics'                  => array(),
			'applied'                      => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'                      => array(),
			'existing_matches'             => array( 'pages' => array() ),
			'report_destinations'          => isset( $args['report_destinations'] ) && is_array( $args['report_destinations'] ) ? $args['report_destinations'] : array(),
			'external_report_destinations' => isset( $args['external_report_destinations'] ) && is_array( $args['external_report_destinations'] ) ? $args['external_report_destinations'] : array(),
			'args'                         => $args,
			'payload_reader'               => is_object( $payload_reader ) ? $payload_reader : null,
			'default_content'              => $default_content,
			'rollback'                     => array(
				'posts'   => array(),
				'files'   => array(),
				'options' => array(),
			),
		);

		try {
			if ( array() === $state['plan_identity'] ) {
				throw new InvalidArgumentException( 'canonical_plan_identity_mismatch' );
			}
			WordPressSitePlan::assertValid( $plan );
		} catch ( InvalidArgumentException $error ) {
			$state['diagnostics'][]  = array( 'reason_code' => 'canonical_plan_rejected' );
			$state['failure_reason'] = 'canonical_plan_rejected';
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}
		$state['editability_report'] = self::editability_report_admission( $plan );
		if ( 'rejected' === $state['editability_report']['status'] ) {
			$state['diagnostics'][]  = $state['editability_report']['diagnostic'];
			$state['failure_reason'] = (string) ( $state['editability_report']['diagnostic']['reason_code'] ?? 'editability_report_rejected' );
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}

		try {
			$slug = sanitize_key( (string) ( $args['slug'] ?? '' ) );
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			$theme_root = get_theme_root();
			if ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Validates the native theme destination before atomic local writes.
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			$theme_dir = trailingslashit( $theme_root ) . $slug;
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			$theme_uri = trailingslashit( get_theme_root_uri() ) . $slug;
			try {
				// Resolver proof is canonical semantics, not an inference from copied files.
				$resolved = ( new WordPressSitePlanResolver() )->resolve(
					$plan,
					array(
						'theme_uri'            => $theme_uri,
						'require_proven_dynamic_client_assets' => $args['require_proven_dynamic_client_assets'] ?? true,
						'runtime_capabilities' => array( 'asset_materialization' ),
					)
				);
			} catch ( InvalidArgumentException $error ) {
				throw new InvalidArgumentException( $error->getMessage(), 0, $error );
			}
			$state['base_resolved'] = $resolved;
			if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] ) {
				$projection = Static_Site_Importer_Classic_Theme_Projection::prepare_for_materialization( $args['classic_theme_projection'], $resolved );
				if ( is_wp_error( $projection ) ) {
					throw new InvalidArgumentException( (string) $projection->get_error_code() );
				}
				$args['classic_theme_projection'] = $projection;
				$resolved['writes']               = Static_Site_Importer_Classic_Theme_Projection::resolved_writes( $resolved, Static_Site_Importer_Classic_Theme_Projection::writes( $args['classic_theme_projection'], $resolved, $theme_uri, (string) ( $args['name'] ?? $slug ) ) );
				foreach ( $resolved['pages'] as &$page ) {
					$page['resolved_block_markup'] = '';
				}
				unset( $page );
				$state['base_resolved'] = $resolved;
			}
			$state['prepared_resolved_projection_hash'] = self::prepared_resolved_projection_hash( $resolved );
			$state['resolved']                          = $resolved;
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			$state['theme_dir']                = $theme_dir;
			$state['theme']                    = array(
				'slug' => $slug,
				'dir'  => $theme_dir,
				'uri'  => $theme_uri,
			);
			$state['quality_budget_admission'] = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved, $args );
			self::preflight_state( $state, ! empty( $args['overwrite'] ), (string) ( $args['import_run_id'] ?? '' ) );
		} catch ( InvalidArgumentException $error ) {
			if ( isset( $state['preflight_error'] ) && is_wp_error( $state['preflight_error'] ) ) {
				return array(
					'status'  => 'rejected',
					'receipt' => self::rejected_receipt_from_error( $state, $state['preflight_error'] ),
				);
			}
			$state['diagnostics'][]  = array( 'reason_code' => $error->getMessage() );
			$state['failure_reason'] = $error->getMessage();
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}
		$state['status']      = 'prepared';
		$state['args']        = $args;
		$state['preparation'] = array(
			'canonical_validations'       => 1,
			'plan_resolutions'            => 1,
			'destination_preflights'      => 1,
			'immutable_projection_reused' => false,
		);
		return $state;
	}

	/** Prepare the canonical plan state that may safely precede provider provisioning. */
	public static function prepare_for_materialization( array $plan, array $args = array() ): array {
		$args     = self::with_report_destinations( $args );
		$prepared = self::prepare( $plan, $args );
		return 'prepared' === ( $prepared['status'] ?? '' ) ? self::admit_prepared( $prepared ) : $prepared;
	}

	/** Add the importer-owned report targets before canonical destination preflight. */
	private static function with_report_destinations( array $args ): array {
		$theme_dir = trailingslashit( get_theme_root() ) . sanitize_key( (string) ( $args['slug'] ?? '' ) );
		$reports   = array( $theme_dir . '/static-site-importer-manifest.json' );
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$reports = array_merge( $reports, array( $theme_dir . '/import-report.json', $theme_dir . '/import-validation-result.json', $theme_dir . '/finding-packets.json' ) );
		}
		$external = array();
		if ( ! empty( $args['report'] ) ) {
			$external = array( (string) $args['report'], trailingslashit( dirname( (string) $args['report'] ) ) . 'import-validation-result.json', trailingslashit( dirname( (string) $args['report'] ) ) . 'finding-packets.json' );
			$reports  = array_merge( $reports, $external );
		}
		$args['report_destinations']          = $reports;
		$args['external_report_destinations'] = $external;
		return $args;
	}

	/**
	 * Verify all reference-backed payloads before related runtime work begins.
	 *
	 * The success marker is intentionally ephemeral: it is neither part of the
	 * canonical plan hash nor projected into materialization receipts. Individual
	 * writes still reread and verify their payload immediately before mutation.
	 *
	 * @param array<string,mixed> $prepared Prepared materialization state.
	 * @return array<string,mixed> Prepared state or a rejected receipt.
	 */
	public static function admit_prepared( array $prepared ): array {
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) || ! isset( $prepared['resolved']['writes'] ) || ! is_array( $prepared['resolved']['writes'] ) ) {
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt(
					'rejected',
					array(
						'plan'             => isset( $prepared['plan'] ) && is_array( $prepared['plan'] ) ? $prepared['plan'] : array(),
						'plan_identity'    => is_array( $prepared['plan_identity'] ?? null ) ? $prepared['plan_identity'] : array(),
						'diagnostics'      => array( array( 'reason_code' => 'invalid_prepared_state' ) ),
						'failure_reason'   => 'invalid_prepared_state',
						'applied'          => array(
							'posts'                => array(),
							'files'                => array(),
							'operations'           => array(),
							'runtime_declarations' => array(
								'asset_publications' => array(),
								'entity_bindings'    => array(),
							),
						),
						'skipped'          => array(),
						'existing_matches' => array( 'pages' => array() ),
					)
				),
			);
		}
		if ( ! empty( $prepared['payload_references_admitted'] ) ) {
			return $prepared;
		}
		$references = self::verify_payload_references( $prepared['resolved']['writes'], is_object( $prepared['payload_reader'] ?? null ) ? $prepared['payload_reader'] : null );
		if ( is_wp_error( $references ) ) {
			$prepared['diagnostics'][]  = array( 'reason_code' => $references->get_error_code() );
			$prepared['failure_reason'] = $references->get_error_code();
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $prepared ),
			);
		}
		$prepared['payload_references_admitted'] = true;
		return $prepared;
	}

	/** Return a non-mutating receipt for a rejected strategy before destination preflight. */
	private static function failed_strategy_receipt( array $plan, array $args, WP_Error $error ): array {
		$state = array(
			'plan'                  => $plan,
			'plan_identity'         => self::plan_identity( $plan ),
			'receipt_instance_id'   => self::receipt_instance_id(),
			'diagnostics'           => array( array( 'reason_code' => $error->get_error_code() ) ),
			'failure_reason'        => $error->get_error_code(),
			'applied'               => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'               => array(),
			'existing_matches'      => array( 'pages' => array() ),
			'args'                  => $args,
			'theme_materialization' => is_array( $error->get_error_data() ) && isset( $error->get_error_data()['theme_materialization'] ) && is_array( $error->get_error_data()['theme_materialization'] ) ? $error->get_error_data()['theme_materialization'] : self::strategy_evidence( $args ),
		);
		return array(
			'status'  => 'rejected',
			'receipt' => self::receipt( 'rejected', $state ),
		);
	}

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) || ! isset( $prepared['plan'] ) || ! is_array( $prepared['plan'] ) || ! self::valid_receipt_instance_id( $prepared['receipt_instance_id'] ?? null ) ) {
			return self::receipt(
				'rejected',
				array(
					'plan'             => array(),
					'plan_identity'    => array(),
					'diagnostics'      => array( array( 'reason_code' => 'invalid_prepared_state' ) ),
					'failure_reason'   => 'invalid_prepared_state',
					'applied'          => array(
						'posts'                => array(),
						'files'                => array(),
						'operations'           => array(),
						'runtime_declarations' => array(
							'asset_publications' => array(),
							'entity_bindings'    => array(),
						),
					),
					'skipped'          => array(),
					'existing_matches' => array( 'pages' => array() ),
				)
			);
		}
		$state = self::refresh_prepared_destination( $prepared );
		if ( 'prepared' !== ( $state['status'] ?? '' ) ) {
			return $state['receipt'];
		}
		$state = self::admit_prepared( $state );
		if ( 'prepared' !== ( $state['status'] ?? '' ) ) {
			return $state['receipt'];
		}
		$capabilities = Static_Site_Importer_Current_Site_Capabilities::check_plan( $state );
		if ( is_wp_error( $capabilities ) ) {
			return self::failed_receipt_from_error( $state, $capabilities );
		}
		try {
			self::validate_materialized_block_documents( $state['resolved'], $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
		} catch ( InvalidArgumentException $error ) {
			$state['failure_reason'] = $error->getMessage();
			return self::receipt( 'rejected', $state );
		}
		$args             = $state['args'];
		$font_overlay     = $state['font_overlay'];
		$viewport_overlay = $state['viewport_overlay'];

		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			self::journal_post( $state, $page );
			$post = self::materialize_page( $page, $state['source_ids'], (string) ( $args['import_run_id'] ?? '' ) );
			if ( is_wp_error( $post ) ) {
				return self::failed_receipt( $state, $post->get_error_code() );
			}
			if ( empty( $page['planned_existing_id'] ) ) {
				$state['rollback']['posts'][ $post ] = array( 'existing' => false );
			}
			$state['applied']['posts'][]                           = array(
				'id'                      => $post,
				'source_path'             => $page['source_path'],
				'reconciliation_identity' => $page['reconciliation_identity'],
			);
			$state['page_ids'][ $page['reconciliation_identity'] ] = $post;
			$state['source_ids'][ $page['source_path'] ]           = $post;
			if ( ! self::write_post_meta( $post, self::RECONCILIATION_META_KEY, (string) $page['reconciliation_identity'] ) || ! self::write_post_meta( $post, self::PRODUCER_RECONCILIATION_META_KEY, (string) $page['reconciliation_identity'] ) ) {
				return self::failed_receipt( $state, 'materialization_reconciliation_metadata_write_failed' );
			}
			$materialized_markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] );
			$provenance          = array(
				'schema'                  => 'static-site-importer/page-provenance/v1',
				'import_run_id'           => (string) ( $args['import_run_id'] ?? '' ),
				'source_path'             => $page['source_path'],
				'reconciliation_identity' => $page['reconciliation_identity'],
				'content_hash'            => hash( 'sha256', $materialized_markup ),
			);
			$document_title      = Static_Site_Importer_Route_Document_Metadata::title_from_page( $page );
			if ( '' !== $document_title ) {
				$provenance['document_title'] = $document_title;
			}
			update_post_meta(
				$post,
				'_static_site_importer_provenance',
				wp_json_encode( $provenance )
			);
			foreach ( $state['applied']['runtime_declarations']['entity_bindings'] as &$binding_report ) {
				if ( ( $binding_report['source_path'] ?? '' ) === $page['source_path'] ) {
					$fragment          = (string) ( $binding_report['replacement_block_markup'] ?? '' );
					$persisted_content = function_exists( 'get_post_field' ) ? get_post_field( 'post_content', $post ) : null;
					if ( ! is_string( $persisted_content ) || '' === $fragment || ! str_contains( $persisted_content, $fragment ) ) {
						$binding_report['status'] = 'unresolved';
						continue;
					}
					$binding_report['status']                    = 'completed';
					$binding_report['post_id']                   = $post;
					$binding_report['persisted_fragment_hash']   = hash( 'sha256', $fragment );
					$binding_report['materialized_content_hash'] = hash( 'sha256', $persisted_content );
				}
			}
			unset( $binding_report );
		}
		$route_links = self::rewrite_materialized_route_links( $state );
		if ( is_wp_error( $route_links ) ) {
			return self::failed_receipt_from_error( $state, $route_links );
		}

		$short_write_attempt = 0;
		foreach ( $state['resolved']['writes'] as $write ) {
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			self::journal_file( $state, $path );
			if ( isset( $state['composed_theme_writes'][ $path ] ) && is_file( $path ) && self::file_hash( $path ) === hash( 'sha256', $state['composed_theme_writes'][ $path ] ) ) {
				$state['applied']['files'][] = self::canonical_file_receipt( $path, $write );
				continue;
			}
			if ( ! empty( $args['preserve_existing_theme_bootstrap'] ) && 'theme_bootstrap' === ( $write['kind'] ?? '' ) && is_file( $state['theme_dir'] . '/' . $write['target_path'] ) ) {
				$result = self::merge_batch_bootstrap( $state['theme_dir'], $write );
				if ( is_wp_error( $result ) ) {
					return self::failed_receipt( $state, $result->get_error_code() );
				}
				$state['applied']['files'][] = $result;
				continue;
			}
			if ( ! empty( $args['preserve_existing_theme_bootstrap'] ) && in_array( $write['kind'] ?? '', array( 'theme_scaffold', 'theme_bootstrap', 'theme_template' ), true ) && is_file( $state['theme_dir'] . '/' . $write['target_path'] ) ) {
				continue;
			}
			$chunk_writer = self::injected_failure( $args, 'theme_write_short' ) ? static function ( $stream, string $data ) use ( &$short_write_attempt ): int {
				if ( 0 < $short_write_attempt++ ) {
					return 0;
				}
				$length = max( 1, intdiv( strlen( $data ), 2 ) );
				return (int) fwrite( $stream, substr( $data, 0, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Deterministic test-only short-write injection.
			} : null;
			$result       = self::write_file( $state['theme_dir'], $write, $state['payload_reader'], $chunk_writer );
			if ( is_wp_error( $result ) ) {
				return self::failed_receipt( $state, $result->get_error_code() );
			}
			$state['applied']['files'][] = $result;
		}
		$provider_layout_overlays = isset( $args['provider_layout_overlays'] ) && is_array( $args['provider_layout_overlays'] ) ? $args['provider_layout_overlays'] : array();
		if ( ! empty( $provider_layout_overlays ) ) {
			$provider_layout_materialization = self::apply_provider_layout_overlays( $state, $provider_layout_overlays );
			if ( is_wp_error( $provider_layout_materialization ) ) {
				return self::failed_receipt( $state, $provider_layout_materialization->get_error_code() );
			}
			$state['applied']['provider_layout_overlays'] = $provider_layout_materialization;
		}
		$publications = self::verify_asset_publications( $state );
		if ( is_wp_error( $publications ) ) {
			return self::failed_receipt( $state, $publications->get_error_code() );
		}
		$font_materialization = self::apply_font_overlay( $state, $font_overlay );
		if ( is_wp_error( $font_materialization ) ) {
			return self::failed_receipt_from_error( $state, $font_materialization );
		}
		$state['applied']['font_materialization'] = $font_materialization;
		$viewport_materialization                = self::apply_viewport_overlay( $state, $viewport_overlay );
		if ( is_wp_error( $viewport_materialization ) ) {
			return self::failed_receipt_from_error( $state, $viewport_materialization );
		}
		$state['applied']['viewport_metadata'] = $viewport_materialization;
		$svg_receipts                             = self::verify_svg_font_materialization( $state );
		if ( is_wp_error( $svg_receipts ) ) {
			return self::failed_receipt( $state, $svg_receipts->get_error_code() );
		}
		if ( self::injected_failure( $args, 'font_verification' ) ) {
			return self::failed_receipt( $state, 'injected_font_verification_failure' );
		}
		if ( ! empty( $font_materialization['svg_receipts'] ) ) {
			$publications = self::verify_asset_publications( $state );
			if ( is_wp_error( $publications ) ) {
				return self::failed_receipt( $state, $publications->get_error_code() );
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			self::journal_runtime( $state );
			foreach ( $state['resolved']['operations'] as $operation ) {
				if ( 'create_page' === $operation['kind'] ) {
					continue;
				}
				$result = self::apply_operation( $operation, $state['page_ids'], $args );
				if ( is_wp_error( $result ) ) {
					return self::failed_receipt( $state, $result->get_error_code() );
				}
				$state['applied']['operations'][] = $result;
			}
			switch_theme( $state['theme']['slug'] );
			if ( ! self::active_theme_matches( $state['theme']['slug'] ) ) {
				return self::failed_receipt( $state, 'activate_theme_not_applied' );
			}
			$state['applied']['operations'][] = array(
				'kind'       => 'activate_theme',
				'theme_slug' => $state['theme']['slug'],
			);
			if ( self::injected_failure( $args, 'after_activation' ) ) {
				return self::failed_receipt( $state, 'injected_after_activation_failure' );
			}
			if ( ! isset( $args['disable_smilies'] ) || false !== (bool) $args['disable_smilies'] ) {
				if ( ! self::write_option( 'use_smilies', false ) ) {
					return self::failed_receipt( $state, 'disable_smilies_not_applied' );
				}
				$state['applied']['runtime_policy']['disable_smilies'] = true;
				if ( self::injected_failure( $args, 'after_use_smilies' ) ) {
					return self::failed_receipt( $state, 'injected_after_use_smilies_failure' );
				}
			}
			if ( '' !== trim( (string) ( $args['site_title'] ?? '' ) ) ) {
				$title = sanitize_text_field( (string) $args['site_title'] );
				if ( ! self::write_option( 'blogname', $title ) ) {
					return self::failed_receipt( $state, 'site_title_not_applied' );
				}
				$state['applied']['operations'][] = array( 'kind' => 'site_title' );
				if ( self::injected_failure( $args, 'after_blogname' ) ) {
					return self::failed_receipt( $state, 'injected_after_blogname_failure' );
				}
			}
		}

		$state['applied']['runtime_policy']['remove_default_content'] = ! empty( $args['remove_default_content'] )
			? Static_Site_Importer_Default_Content::remove( $state['default_content'] )
			: array(
				'status'  => 'skipped',
				'reason'  => 'disabled',
				'removed' => array(
					'posts'    => array(),
					'comments' => array(),
				),
				'skipped' => array(),
			);

		return self::receipt( 'completed', $state );
	}

	/**
	 * Recheck mutable destinations without repeating canonical validation and resolution.
	 *
	 * @param array<string,mixed> $prepared Previously validated immutable projection.
	 * @return array<string,mixed>
	 */
	private static function refresh_prepared_destination( array $prepared ): array {
		$plan           = $prepared['plan'] ?? null;
		$base_resolved  = $prepared['base_resolved'] ?? null;
		$args           = isset( $prepared['args'] ) && is_array( $prepared['args'] ) ? $prepared['args'] : array();
		$payload_reader = is_object( $prepared['payload_reader'] ?? null ) ? $prepared['payload_reader'] : null;
		if ( ! is_array( $plan ) || ! is_array( $base_resolved ) || ! self::valid_receipt_instance_id( $prepared['receipt_instance_id'] ?? null ) || self::plan_identity( $plan ) !== ( $prepared['plan_identity'] ?? null ) || self::prepared_resolved_projection_hash( $base_resolved ) !== ( $prepared['prepared_resolved_projection_hash'] ?? '' ) ) {
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt(
					'rejected',
					array(
						'plan'             => is_array( $plan ) ? $plan : array(),
						'plan_identity'    => array(),
						'diagnostics'      => array( array( 'reason_code' => 'prepared_projection_changed' ) ),
						'failure_reason'   => 'prepared_projection_changed',
						'applied'          => array(
							'posts'                => array(),
							'files'                => array(),
							'operations'           => array(),
							'runtime_declarations' => array(
								'asset_publications' => array(),
								'entity_bindings'    => array(),
							),
						),
						'skipped'          => array(),
						'existing_matches' => array( 'pages' => array() ),
					)
				),
			);
		}

		$slug       = sanitize_key( (string) ( $args['slug'] ?? '' ) );
		$theme_root = get_theme_root();
		$theme_uri  = trailingslashit( get_theme_root_uri() ) . $slug;
		$theme_dir  = trailingslashit( $theme_root ) . $slug;
		$state      = array(
			'plan'                              => $plan,
			'plan_identity'                     => $prepared['plan_identity'],
			'receipt_instance_id'               => $prepared['receipt_instance_id'],
			'editability_report'                => $prepared['editability_report'] ?? array(),
			'base_resolved'                     => $base_resolved,
			'prepared_resolved_projection_hash' => $prepared['prepared_resolved_projection_hash'],
			'resolved'                          => $base_resolved,
			'diagnostics'                       => array(),
			'applied'                           => array(
				'posts'                => array(),
				'files'                => array(),
				'operations'           => array(),
				'runtime_declarations' => array(
					'asset_publications' => array(),
					'entity_bindings'    => array(),
				),
			),
			'skipped'                           => array(),
			'existing_matches'                  => array( 'pages' => array() ),
			'report_destinations'               => isset( $args['report_destinations'] ) && is_array( $args['report_destinations'] ) ? $args['report_destinations'] : array(),
			'external_report_destinations'      => isset( $args['external_report_destinations'] ) && is_array( $args['external_report_destinations'] ) ? $args['external_report_destinations'] : array(),
			'theme_dir'                         => $theme_dir,
			'theme'                             => array(
				'slug' => $slug,
				'dir'  => $theme_dir,
				'uri'  => $theme_uri,
			),
			'args'                              => $args,
			'payload_reader'                    => $payload_reader,
			'font_overlay'                      => isset( $prepared['font_overlay'] ) && is_array( $prepared['font_overlay'] ) ? $prepared['font_overlay'] : null,
			'viewport_overlay'                  => isset( $prepared['viewport_overlay'] ) && is_array( $prepared['viewport_overlay'] ) ? $prepared['viewport_overlay'] : null,
			'default_content'                   => isset( $prepared['default_content'] ) && is_array( $prepared['default_content'] ) ? $prepared['default_content'] : array(),
			'rollback'                          => array(
				'posts'   => array(),
				'files'   => array(),
				'options' => array(),
			),
			'preparation'                       => array(
				'canonical_validations'       => 1,
				'plan_resolutions'            => 1,
				'destination_preflights'      => 2,
				'immutable_projection_reused' => true,
			),
		);

		try {
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'invalid_theme_slug' );
			}
			if ( ! is_dir( $theme_root ) || ! is_writable( $theme_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Revalidates the prepared native theme destination before mutation.
				throw new InvalidArgumentException( 'theme_destination_not_ready' );
			}
			if ( is_link( $theme_dir ) || ( file_exists( $theme_dir ) && ! is_dir( $theme_dir ) ) ) {
				throw new InvalidArgumentException( 'unsafe_theme_destination' );
			}
			if ( ( $prepared['theme']['dir'] ?? null ) !== $theme_dir || ( $prepared['theme']['uri'] ?? null ) !== $theme_uri ) {
				throw new InvalidArgumentException( 'prepared_destination_changed' );
			}
			self::apply_runtime_entity_bindings( $state['resolved'], isset( $args['runtime_entity_bindings'] ) && is_array( $args['runtime_entity_bindings'] ) ? $args['runtime_entity_bindings'] : array(), $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			$state['quality_budget_admission'] = Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $state['resolved'], $args );
			if ( Static_Site_Importer_Quality_Budget_Admission::rejects_materialization( $state['quality_budget_admission'] ) ) {
				$state['diagnostics'][]  = array(
					'reason_code'    => 'quality_budget_failed',
					'quality_budget' => $state['quality_budget_admission'],
				);
				$state['failure_reason'] = 'quality_budget_failed';
				return array(
					'status'  => 'rejected',
					'receipt' => self::receipt( 'rejected', $state ),
				);
			}
			self::validate_materialized_block_documents( $state['resolved'], $state['applied']['runtime_declarations']['entity_bindings'], $state['diagnostics'] );
			self::preflight_state( $state, ! empty( $args['overwrite'] ), (string) ( $args['import_run_id'] ?? '' ) );
		} catch ( InvalidArgumentException $error ) {
			if ( isset( $state['preflight_error'] ) && is_wp_error( $state['preflight_error'] ) ) {
				return array(
					'status'  => 'rejected',
					'receipt' => self::rejected_receipt_from_error( $state, $state['preflight_error'] ),
				);
			}
			$state['diagnostics'][]  = array( 'reason_code' => $error->getMessage() );
			$state['failure_reason'] = $error->getMessage();
			return array(
				'status'  => 'rejected',
				'receipt' => self::receipt( 'rejected', $state ),
			);
		}

		$state['status'] = 'prepared';
		return $state;
	}

	/** @param array<string,mixed> $state */
	private static function preflight_state( array &$state, bool $overwrite, string $import_run_id = '' ): void {
		$pages_by_route      = array();
		$state['page_ids']   = array();
		$state['source_ids'] = array();
		foreach ( $state['resolved']['pages'] as &$page ) {
			if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC !== ( $state['args']['theme_materialization'] ?? null ) && ( ! isset( $page['resolved_block_markup'] ) || ! is_string( $page['resolved_block_markup'] ) || '' === trim( $page['resolved_block_markup'] ) ) ) {
				throw new InvalidArgumentException( 'page_missing_final_block_markup' );
			}
			$route = (string) ( $page['route']['path'] ?? '' );
			if ( isset( $pages_by_route[ $route ] ) ) {
				throw new InvalidArgumentException( 'duplicate_page_route' );
			}
			$pages_by_route[ $route ] = true;

			// The materializer is the consumer boundary: it trusts the type and
			// date the producer declared on the plan row and only falls back to
			// consumer-side detection when the compiler left the row undecided
			// ('page', its default). Classification runs once here so the
			// existing-match, conflict, and materialize paths read one value.
			$classification                            = Static_Site_Importer_Document_Type_Classifier::classify( $page );
			$page['post_type']                         = $classification['post_type'];
			$page['metadata']['detected_date']         = $classification['date'];
			$page['metadata']['classification_signal'] = $classification['signal'];
			$existing                                  = self::reconciled_post( $page['reconciliation_identity'] );
			if ( $existing ) {
				$page = self::plan_existing_page( $state, $page, $existing, 'reconciliation_identity_match' );
				continue;
			}
			$conflict = '' === trim( $route, '/' ) ? null : get_page_by_path( trim( $route, '/' ), OBJECT, $page['post_type'] );
			if ( $conflict && ! $overwrite && ! self::post_belongs_to_run( $conflict, $import_run_id ) ) {
				throw new InvalidArgumentException( 'post_conflict' );
			}
			if ( $conflict ) {
				$page = self::plan_existing_page( $state, $page, $conflict, 'canonical_route_match' );
			}
		}
		unset( $page );
		$state['ordered_pages'] = self::parent_ordered_pages( $state['resolved']['pages'], $import_run_id );
		if ( null === $state['ordered_pages'] ) {
			throw new InvalidArgumentException( 'invalid_page_parent_identity' );
		}
		foreach ( $state['resolved']['operations'] as $operation ) {
			if ( 'create_page' === $operation['kind'] ) {
				continue;
			}
			if ( 'site_reading' !== $operation['kind'] || ( ! isset( $state['page_ids'][ $operation['front_page_reconciliation_identity'] ] ) && ! self::page_exists_in_plan( $state['resolved']['pages'], $operation['front_page_reconciliation_identity'] ) ) ) {
				throw new InvalidArgumentException( 'unsupported_operation' );
			}
		}
		$overlay_writes = self::provider_layout_stylesheet_writes( $state, $state['args']['provider_layout_overlays'] ?? array() );
		if ( is_wp_error( $overlay_writes ) ) {
			throw new InvalidArgumentException( 'provider_layout_overlay_rejected' );
		}
		$state['provider_layout_overlay_writes'] = $overlay_writes;
		$font_resolved                           = $state['resolved'];
		foreach ( $font_resolved['writes'] as &$write ) {
			$path = $state['theme_dir'] . '/' . (string) ( $write['target_path'] ?? '' );
			if ( isset( $overlay_writes[ $path ] ) ) {
				$write['payload']      = array(
					'encoding' => 'utf8',
					'data'     => $overlay_writes[ $path ],
				);
				$write['payload_hash'] = hash( 'sha256', $overlay_writes[ $path ] );
			}
		}
		unset( $write );
		$font_overlay = isset( $state['font_overlay'] ) && is_array( $state['font_overlay'] )
			? $state['font_overlay']
			: Static_Site_Importer_Font_Materializer::prepare_overlay(
				isset( $state['args']['font_materialization'] ) && is_array( $state['args']['font_materialization'] ) ? $state['args']['font_materialization'] : array(),
				$font_resolved
			);
		if ( is_wp_error( $font_overlay ) ) {
			$state['preflight_error'] = $font_overlay;
			throw new InvalidArgumentException( sanitize_key( (string) $font_overlay->get_error_code() ) );
		}
		$viewport_overlay = isset( $state['viewport_overlay'] ) && is_array( $state['viewport_overlay'] )
			? $state['viewport_overlay']
			: Static_Site_Importer_Viewport_Metadata_Materializer::prepare_overlay( $font_resolved, $font_overlay );
		$state['font_overlay']          = $font_overlay;
		$state['viewport_overlay']      = $viewport_overlay;
		$state['composed_theme_writes'] = array_merge( $overlay_writes, self::font_overlay_writes( $state['theme_dir'], $font_overlay ), self::viewport_overlay_writes( $state['theme_dir'], $viewport_overlay ) );
		foreach ( $state['resolved']['writes'] as $write ) {
			if ( null !== self::payload_reference( $write ) && ! self::valid_payload_reference( self::payload_reference( $write ) ) ) {
				throw new InvalidArgumentException( 'payload_reference_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $write['target_path'];
			if ( ! self::safe_destination( $state['theme_dir'], $write['target_path'] ) ) {
				throw new InvalidArgumentException( 'unsafe_destination_path' );
			}
			if ( is_dir( $path ) || ( file_exists( $path ) && ! $overwrite && ! self::theme_belongs_to_run( $state['theme_dir'], $import_run_id ) && self::file_hash( $path ) !== self::payload_hash( $write ) && ( ! isset( $state['composed_theme_writes'][ $path ] ) || self::file_hash( $path ) !== hash( 'sha256', $state['composed_theme_writes'][ $path ] ) ) ) ) {
				if ( isset( $overlay_writes[ $path ] ) ) {
					throw new InvalidArgumentException( 'provider_layout_overlay_rejected' );
				}
				throw new InvalidArgumentException( 'file_conflict' );
			}
		}
		foreach ( $state['report_destinations'] ?? array() as $path ) {
			$parent = is_string( $path ) ? dirname( $path ) : '';
			while ( '' !== $parent && ! file_exists( $parent ) ) {
				$parent = dirname( $parent );
			}
			if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ( file_exists( $path ) && ! is_writable( $path ) ) || '' === $parent || is_link( $parent ) || ! is_dir( $parent ) || ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflights native report destinations used by atomic local writes.
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
		foreach ( $state['external_report_destinations'] ?? array() as $path ) {
			if ( ! self::safe_external_report_destination( $path ) ) {
				throw new InvalidArgumentException( 'report_destination_not_ready' );
			}
		}
	}

	/**
	 * External report output is a CLI-only operator seam. Every artifact must be
	 * a new file directly beneath one existing, physical directory.
	 */
	public static function safe_external_report_destination( $path ): bool {
		if ( ! is_string( $path ) || '' === $path || str_contains( str_replace( '\\', '/', $path ), '/../' ) || str_starts_with( str_replace( '\\', '/', $path ), '../' ) || str_ends_with( str_replace( '\\', '/', $path ), '/..' ) || str_contains( str_replace( '\\', '/', $path ), '/./' ) ) {
			return false;
		}
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || is_link( $path ) || file_exists( $path ) || is_link( $parent ) || ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflights explicit CLI report destinations before atomic local writes.
			return false;
		}
		while ( DIRECTORY_SEPARATOR !== $parent && '.' !== $parent ) {
			if ( is_link( $parent ) ) {
				return false;
			}
			$parent = dirname( $parent );
		}

		return true;
	}

	/** @param array<string,mixed> $page @param array<string,int> $source_ids */
	private static function materialize_page( array $page, array $source_ids, string $import_run_id = '' ) {
		$post_type = sanitize_key( (string) ( $page['post_type'] ?? 'page' ) );
		if ( ! self::is_valid_post_type( $post_type ) ) {
			$post_type = 'page';
		}
		// Pages keep the plan's canonical route ancestry as post_parent, resolved
		// from this run's source ids or a previously materialized run (batch
		// re-imports). Posts must not carry the synthetic page ancestor: their
		// permalink comes from the site post permalink structure, so carrying it
		// would materialize them at a page-shaped path, not the plan route.
		if ( 'page' === $post_type ) {
			$parent = '' === $page['parent_source_path'] ? 0 : ( $source_ids[ $page['parent_source_path'] ] ?? self::existing_source_page_id( $page['parent_source_path'], $import_run_id ) );
			if ( $parent <= 0 && '' !== $page['parent_source_path'] ) {
				return new WP_Error(
					'missing_parent_page',
					'The parent route has not been materialized by this import run.',
					array(
						'source_path'        => $page['source_path'],
						'parent_source_path' => $page['parent_source_path'],
					)
				);
			}
		} else {
			$parent = 0;
		}
		$post = array(
			'ID'           => (int) ( $page['planned_existing_id'] ?? 0 ),
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_title'   => (string) $page['title'],
			'post_name'    => (string) $page['slug'],
			'post_parent'  => $parent,
			'post_content' => wp_slash( (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ) ),
		);
		if ( ! empty( $page['metadata']['detected_date'] ) ) {
			// The classifier emits UTC. post_date_gmt stores that absolute value;
			// wp_insert_post derives the site-local post_date from it using the
			// configured timezone, so writing it here would double-shift display
			// on non-UTC sites. Only dated documents set the date at all.
			$post['post_date_gmt'] = (string) $page['metadata']['detected_date'];
		}
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return (int) $id;
	}

	/** Persist and verify importer-owned post metadata. */
	private static function write_post_meta( int $id, string $key, string $value ): bool {
		update_post_meta( $id, $key, $value );
		return metadata_exists( 'post', $id, $key ) && (string) get_post_meta( $id, $key, true ) === $value;
	}

	/** Resolve destination-independent route references after WordPress has assigned every permalink. */
	private static function rewrite_materialized_route_links( array &$state ) {
		$routes              = array();
		$front_page_identity = self::front_page_reconciliation_identity( $state['resolved']['operations'] ?? array() );
		foreach ( $state['ordered_pages'] as $page ) {
			$source_path = (string) ( $page['source_path'] ?? '' );
			$route       = self::normalized_route_path( (string) ( $page['route']['path'] ?? '' ) );
			$post_id     = (int) ( $state['source_ids'][ $source_path ] ?? 0 );
			$permalink   = $post_id > 0 && function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : false;
			if ( '' !== $route && is_string( $permalink ) && '' !== $permalink ) {
				$routes[ $route ] = (string) ( $page['reconciliation_identity'] ?? '' ) === $front_page_identity ? home_url( '/' ) : $permalink;
			}
		}
		if ( array() === $routes ) {
			return true;
		}

		foreach ( $state['ordered_pages'] as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$source_path = (string) ( $page['source_path'] ?? '' );
			$post_id     = (int) ( $state['source_ids'][ $source_path ] ?? 0 );
			$content     = $post_id > 0 && function_exists( 'get_post_field' ) ? get_post_field( 'post_content', $post_id ) : null;
			if ( ! is_string( $content ) ) {
				return new WP_Error( 'route_link_rewrite_failed', 'Materialized page content could not be read for route-link resolution.', array( 'source_path' => $source_path ) );
			}
			$rewritten = self::rewrite_route_references( $content, $routes );
			if ( $rewritten !== $content ) {
				$updated = wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $rewritten ),
					),
					true
				);
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$provenance = json_decode( (string) get_post_meta( $post_id, '_static_site_importer_provenance', true ), true );
				if ( is_array( $provenance ) ) {
					$provenance['content_hash'] = hash( 'sha256', $rewritten );
					update_post_meta( $post_id, '_static_site_importer_provenance', wp_json_encode( $provenance ) );
				}
			}
			foreach ( $state['applied']['runtime_declarations']['entity_bindings'] as &$binding_report ) {
				if ( ( $binding_report['source_path'] ?? '' ) === $source_path && 'completed' === ( $binding_report['status'] ?? '' ) ) {
					$binding_report['materialized_content_hash'] = hash( 'sha256', $rewritten );
				}
			}
			unset( $binding_report );
			foreach ( $state['resolved']['pages'] as &$resolved_page ) {
				if ( ( $resolved_page['source_path'] ?? '' ) === $source_path ) {
					$resolved_page['materialized_block_markup'] = $rewritten;
					break;
				}
			}
			unset( $resolved_page );
		}

		return true;
	}

	/** @param array<int,array<string,mixed>> $operations */
	private static function front_page_reconciliation_identity( array $operations ): string {
		foreach ( $operations as $operation ) {
			if ( 'site_reading' === ( $operation['kind'] ?? null ) && is_string( $operation['front_page_reconciliation_identity'] ?? null ) ) {
				return $operation['front_page_reconciliation_identity'];
			}
		}

		return '';
	}

	/** @param array<string,string> $routes */
	private static function rewrite_route_references( string $content, array $routes ): string {
		$replace = static function ( array $matches ) use ( $routes ): string {
			$value = (string) $matches[2];
			if ( '' === $value || preg_match( '~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $value ) ) {
				return $matches[0];
			}
			$suffix = '';
			if ( preg_match( '/^([^?#]*)(.*)$/', $value, $parts ) ) {
				$value  = $parts[1];
				$suffix = $parts[2];
			}
			$route = self::normalized_route_path( $value );
			return isset( $routes[ $route ] ) ? $matches[1] . $routes[ $route ] . $suffix . $matches[3] : $matches[0];
		};
		foreach ( array(
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*["\'])([^"\']+)(["\'])/i',
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\")([^"\\\\]*)(\\\\")/i',
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)/i',
			'/(["\'](?:url|href|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i',
		) as $pattern ) {
			$content = preg_replace_callback( $pattern, $replace, $content ) ?? $content;
		}

		return $content;
	}

	private static function normalized_route_path( string $path ): string {
		if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
			return '';
		}
		$normalized = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : $normalized;
	}

	/** Apply exact provider bindings to the resolved projection while retaining canonical plan markup. */
	private static function apply_runtime_entity_bindings( array &$plan, array $bindings, array &$reports, array &$diagnostics ): void {
		$pages = array();
		foreach ( $plan['pages'] as $index => $page ) {
			$pages[ $page['source_path'] ] = $index;
		}
		foreach ( $bindings as $binding ) {
			if ( ! is_array( $binding ) ) {
				throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
			}
		}
		usort(
			$bindings,
			static function ( array $left, array $right ): int {
				$group = strcmp( (string) ( $left['source_path'] ?? '' ) . "\n" . (string) ( $left['search_block_markup'] ?? '' ), (string) ( $right['source_path'] ?? '' ) . "\n" . (string) ( $right['search_block_markup'] ?? '' ) );
				return 0 !== $group ? $group : (int) ( $right['occurrence'] ?? 0 ) <=> (int) ( $left['occurrence'] ?? 0 );
			}
		);
		$seen = array();
		foreach ( $bindings as $binding ) {
			$selectors = $binding['superseded_runtime_selectors'] ?? array();
			if ( ! is_array( $binding ) || 'static-site-importer/runtime-entity-binding/v1' !== ( $binding['schema'] ?? null ) || ! is_int( $binding['occurrence'] ?? null ) || $binding['occurrence'] < 1 || ! is_string( $binding['source_path'] ?? null ) || ! isset( $pages[ $binding['source_path'] ] ) || ! is_string( $binding['search_block_markup'] ?? null ) || '' === trim( $binding['search_block_markup'] ) || ! is_string( $binding['replacement_block_markup'] ?? null ) || '' === trim( $binding['replacement_block_markup'] ) || ! is_string( $binding['reconciliation_identity'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $binding['reconciliation_identity'] ) || isset( $seen[ $binding['reconciliation_identity'] ] ) || ! is_array( $selectors ) ) {
				throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
			}
			$selectors = array_values( array_unique( $selectors ) );
			foreach ( $selectors as $selector ) {
				if ( ! is_string( $selector ) || '' === trim( $selector ) || strlen( $selector ) > 1024 ) {
					throw new InvalidArgumentException( 'runtime_entity_binding_invalid' );
				}
			}
			$seen[ $binding['reconciliation_identity'] ] = true;
			self::validate_runtime_entity_binding_fragment( $binding, $diagnostics );
			$index   = $pages[ $binding['source_path'] ];
			$content = (string) ( $plan['pages'][ $index ]['materialized_block_markup'] ?? $plan['pages'][ $index ]['resolved_block_markup'] ?? '' );
			if ( substr_count( $content, $binding['search_block_markup'] ) < $binding['occurrence'] ) {
				throw new InvalidArgumentException( 'runtime_entity_binding_cardinality_mismatch' );
			}
			$position = 0;
			for ( $occurrence = 0; $occurrence < $binding['occurrence']; ++$occurrence ) {
				$position = strpos( $content, $binding['search_block_markup'], $position );
				if ( false === $position ) {
					throw new InvalidArgumentException( 'runtime_entity_binding_cardinality_mismatch' );
				}
				if ( $occurrence + 1 < $binding['occurrence'] ) {
					$position += strlen( $binding['search_block_markup'] );
				}
			}
			$materialized = substr( $content, 0, $position ) . $binding['replacement_block_markup'] . substr( $content, $position + strlen( $binding['search_block_markup'] ) );
			$plan['pages'][ $index ]['materialized_block_markup'] = $materialized;
			$reports[ $binding['reconciliation_identity'] ]       = array(
				'status'                           => 'prepared',
				'reconciliation_identity'          => $binding['reconciliation_identity'],
				'source_path'                      => $binding['source_path'],
				'role'                             => $binding['role'] ?? '',
				'declaration_id'                   => $binding['declaration_id'] ?? '',
				'fallback_reconciliation_identity' => $binding['fallback_reconciliation_identity'] ?? '',
				'fallback_hash'                    => $binding['fallback_hash'] ?? '',
				'materialized_block_hash'          => $binding['materialized_block_hash'] ?? '',
				'replacement_block_markup'         => $binding['replacement_block_markup'],
				'provider'                         => $binding['provider'] ?? '',
				'superseded_runtime_selectors'     => $selectors,
			);
		}
		foreach ( $reports as &$report ) {
			$index                               = $pages[ $report['source_path'] ];
			$report['materialized_content_hash'] = hash( 'sha256', (string) ( $plan['pages'][ $index ]['materialized_block_markup'] ?? $plan['pages'][ $index ]['resolved_block_markup'] ) );
		}
		unset( $report );
	}

	/**
	 * Reject a provider fragment unless Core can round-trip it as a complete block document.
	 *
	 * @param array<string,mixed> $binding     Runtime entity binding.
	 * @param array<int,mixed>    $diagnostics Admission diagnostics.
	 * @return void
	 */
	private static function validate_runtime_entity_binding_fragment( array $binding, array &$diagnostics ): void {
		if ( ! self::is_complete_block_document( (string) $binding['replacement_block_markup'] ) ) {
			$diagnostics[] = array(
				'reason_code'             => 'runtime_entity_binding_replacement_invalid',
				'source_path'             => $binding['source_path'],
				'reconciliation_identity' => $binding['reconciliation_identity'],
				'declaration_id'          => $binding['declaration_id'] ?? '',
				'provider'                => $binding['provider'] ?? '',
			);
			throw new InvalidArgumentException( 'runtime_entity_binding_replacement_invalid' );
		}
	}

	/**
	 * Admit each post document after runtime dependencies and bindings are ready.
	 *
	 * @param array<string,mixed> $plan        Resolved plan with runtime replacements.
	 * @param array<string,mixed> $bindings    Binding reports keyed by reconciliation identity.
	 * @param array<int,mixed>    $diagnostics Admission diagnostics.
	 * @return void
	 */
	private static function validate_materialized_block_documents( array $plan, array $bindings, array &$diagnostics ): void {
		foreach ( $plan['pages'] ?? array() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$markup        = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
			$source_path   = (string) ( $page['source_path'] ?? '' );
			$page_bindings = array_values(
				array_filter(
					$bindings,
					static fn ( $binding ): bool => is_array( $binding ) && ( $binding['source_path'] ?? '' ) === $source_path
				)
			);
			if ( ! self::is_complete_block_document( $markup ) ) {
				$diagnostic = array(
					'reason_code' => 'runtime_entity_bound_block_document_invalid',
					'source_path' => $source_path,
				);
				if ( array() !== $page_bindings ) {
					$diagnostic['binding_reconciliation_identities'] = array_values( array_filter( array_column( $page_bindings, 'reconciliation_identity' ), 'is_string' ) );
				}
				$diagnostics[] = $diagnostic;
				throw new InvalidArgumentException( 'runtime_entity_bound_block_document_invalid' );
			}
			$admission   = self::block_document_editor_admission( $markup, $source_path );
			$diagnostics = array_merge( $diagnostics, $admission['diagnostics'] );
			if ( ! $admission['admitted'] ) {
				throw new InvalidArgumentException( 'unsupported_persisted_block' );
			}
		}
	}

	/**
	 * Use WordPress's parser and serializer as the transport boundary.
	 *
	 * @param string $markup Block document markup.
	 * @return bool
	 */
	private static function is_complete_block_document( string $markup ): bool {
		$runtime = new Blocks_Engine_WordPress_Runtime();
		$blocks  = $runtime->parseBlocks( $markup );
		if ( ! self::block_document_has_only_blocks( $blocks ) ) {
			return false;
		}
		$serialized    = $runtime->serializeBlocks( $blocks );
		$round_tripped = $runtime->parseBlocks( $serialized );
		return self::block_document_has_only_blocks( $round_tripped ) && self::block_document_topology( $blocks ) === self::block_document_topology( $round_tripped );
	}

	/**
	 * Verify that the editor can load every persisted block without a name allowlist.
	 *
	 * @return array{admitted:bool,diagnostics:array<int,array<string,mixed>>}
	 */
	private static function block_document_editor_admission( string $markup, string $source_path ): array {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			throw new InvalidArgumentException( 'block_editor_admission_unavailable' );
		}
		$registry    = WP_Block_Type_Registry::get_instance();
		$diagnostics = array();
		$unsupported = false;
		$inspect     = static function ( array $blocks, ?string $parent_name = null ) use ( &$inspect, $registry, $source_path, &$diagnostics, &$unsupported ): void {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
					continue;
				}
				$name       = $block['blockName'];
				$block_type = $registry->get_registered( $name );
				if ( ! $block_type ) {
					$unsupported = true;
					if ( self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'unsupported_persisted_block',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'block_classification' => 'unsupported',
						);
					}
				} else {
					$classification = str_starts_with( $name, 'core/' ) ? 'registered_core' : 'registered_provider';
					$owners         = $GLOBALS['static_site_importer_companion_block_owners'] ?? array();
					if ( 'registered_provider' === $classification && is_array( $owners ) && isset( $owners[ $name ] ) && is_array( $owners[ $name ] ) ) {
						$classification = 'declared_companion_dependency';
					}
					if ( is_array( $block_type->parent ) && ! in_array( $parent_name, $block_type->parent, true ) && self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'block_parent_requirement_not_met',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'parent_block_name'    => $parent_name,
							'block_classification' => $classification,
						);
					}
					$parent_type = null === $parent_name ? null : $registry->get_registered( $parent_name );
					if ( $parent_type && is_array( $parent_type->allowed_blocks ) && ! in_array( $name, $parent_type->allowed_blocks, true ) && self::BLOCK_PROVENANCE_LIMIT > count( $diagnostics ) ) {
						$diagnostics[] = array(
							'reason_code'          => 'block_child_not_allowed',
							'source_path'          => $source_path,
							'block_name'           => $name,
							'parent_block_name'    => $parent_name,
							'block_classification' => $classification,
						);
					}
				}
				$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
				$inspect( $inner_blocks, $name );
			}
		};
		$inspect( ( new Blocks_Engine_WordPress_Runtime() )->parseBlocks( $markup ) );
		return array(
			'admitted'    => ! $unsupported,
			'diagnostics' => $diagnostics,
		);
	}

	/** @param array<array-key,mixed> $blocks @return bool */
	private static function block_document_has_only_blocks( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return false;
			}
			$name = $block['blockName'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					return false;
				}
				continue;
			}
			if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) || ! self::block_document_has_only_blocks( $block['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<array-key,mixed> $blocks @return array<int,mixed> */
	private static function block_document_topology( array $blocks ): array {
		$topology = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
				continue;
			}
			$inner_blocks = $block['innerBlocks'] ?? array();
			if ( ! is_array( $inner_blocks ) ) {
				$inner_blocks = array();
			}
			$topology[] = array(
				'name'  => $block['blockName'],
				'inner' => self::block_document_topology( $inner_blocks ),
			);
		}
		return $topology;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $page */
	private static function plan_existing_page( array &$state, array $page, WP_Post $existing, string $reason ): array {
		$id                          = (int) $existing->ID;
		$protected                   = Static_Site_Importer_Protected_Page_Policy::is_protected_page( $existing );
		$page['planned_existing_id'] = $id;
		$state['page_ids'][ $page['reconciliation_identity'] ] = $id;
		$state['source_ids'][ $page['source_path'] ]           = $id;
		$row                                  = array(
			'post_id'     => $id,
			'source_path' => $page['source_path'],
			'route'       => $page['route']['path'],
			'permalink'   => function_exists( 'get_permalink' ) ? get_permalink( $existing ) : $page['route']['path'],
			'slug'        => $page['slug'],
			'post_type'   => $page['post_type'],
			'protected'   => $protected,
			'reason'      => $reason,
		);
		$state['existing_matches']['pages'][] = $row;
		if ( $protected ) {
			$page['skip_materialization'] = true;
			$state['skipped'][]           = $row;
		}
		return $page;
	}

	/** @param array<string,mixed> $write */
	private static function write_file( string $theme_dir, array $write, ?object $payload_reader = null, ?Closure $chunk_writer = null ) {
		$path          = $theme_dir . '/' . $write['target_path'];
		$declared_hash = self::payload_hash( $write );
		if ( is_file( $path ) && '' !== $declared_hash && self::file_hash( $path ) === $declared_hash ) {
			return array(
				'target_path'             => $write['target_path'],
				'hash'                    => self::file_hash( $path ),
				'payload_hash'            => $write['payload_hash'] ?? $declared_hash,
				'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
			);
		}
		$data = self::write_payload_bytes( $write, $payload_reader );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
			return new WP_Error( 'theme_directory_create_failed' );
		}
		$temp    = tempnam( dirname( $path ), '.ssi-plan-' );
		$stream  = false !== $temp ? fopen( $temp, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the canonical theme write into an atomic temporary file.
		$written = is_resource( $stream ) && self::write_all( $stream, $data, $chunk_writer ) && fflush( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flushes complete canonical write bytes before publication.
		$closed  = ! is_resource( $stream ) || fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes canonical write before atomic publication.
		if ( false === $data || false === $temp || ! $written || ! $closed || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomically materializes only complete canonical declared theme writes.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed temporary materialization file.
			}
			return new WP_Error( 'theme_write_failed' );
		}
		return array(
			'target_path'             => $write['target_path'],
			'hash'                    => self::file_hash( $path ),
			'payload_hash'            => $write['payload_hash'] ?? hash( 'sha256', $data ),
			'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
		);
	}

	/** Write every canonical byte or fail before the temporary file can be published. */
	private static function write_all( $stream, string $data, ?Closure $chunk_writer = null ): bool {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$remaining = substr( $data, $offset );
			$written   = null === $chunk_writer ? fwrite( $stream, $remaining ) : $chunk_writer( $stream, $remaining ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Handles short writes while materializing canonical theme bytes.
			if ( ! is_int( $written ) || 0 >= $written || strlen( $remaining ) < $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/** Report final bytes while retaining the canonical write's reconciliation identity. */
	private static function canonical_file_receipt( string $path, array $write ): array {
		return array(
			'target_path'             => $write['target_path'],
			'hash'                    => self::file_hash( $path ),
			'payload_hash'            => $write['payload_hash'] ?? self::payload_hash( $write ),
			'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
		);
	}

	/** Include generated stylesheet targets in canonical file receipt projections. */
	private static function record_overlay_stylesheet_file( array &$files, string $path, string $target, string $content ): void {
		foreach ( $files as $file ) {
			if ( ( $file['target_path'] ?? null ) === $target ) {
				return;
			}
		}
		$files[] = array(
			'target_path'             => $target,
			'hash'                    => self::file_hash( $path ),
			'payload_hash'            => hash( 'sha256', $content ),
			'reconciliation_identity' => hash( 'sha256', $target . "\n" . $target ),
		);
	}

	/** Merge a validated later-batch bootstrap as an idempotent PHP include. */
	private static function merge_batch_bootstrap( string $theme_dir, array $write ) {
		$bootstrap = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a declared canonical bootstrap payload.
		if ( false === $bootstrap || ! is_string( $bootstrap ) || ! str_starts_with( ltrim( $bootstrap ), '<?php' ) ) {
			return new WP_Error( 'theme_bootstrap_merge_invalid' );
		}
		$hash      = hash( 'sha256', $bootstrap );
		$include   = 'static-site-importer-batch-bootstrap/' . $hash . '.php';
		$functions = $theme_dir . '/' . $write['target_path'];
		$current   = file_get_contents( $functions ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the importer-owned generated bootstrap.
		if ( false === $current ) {
			return new WP_Error( 'theme_bootstrap_merge_read_failed' );
		}
		$require = "\nrequire_once __DIR__ . '/" . $include . "';\n";
		if ( ! str_contains( $current, $require ) ) {
			$include_write = self::write_file(
				$theme_dir,
				array(
					'target_path'  => $include,
					'source_path'  => $write['source_path'],
					'payload'      => array(
						'encoding' => 'utf8',
						'data'     => $bootstrap,
					),
					'payload_hash' => $hash,
				)
			);
			if ( is_wp_error( $include_write ) ) {
				return $include_write;
			}
			$functions_write = self::write_file(
				$theme_dir,
				array(
					'target_path'  => $write['target_path'],
					'source_path'  => $write['source_path'],
					'payload'      => array(
						'encoding' => 'utf8',
						'data'     => $current . $require,
					),
					'payload_hash' => hash( 'sha256', $current . $require ),
				)
			);
			if ( is_wp_error( $functions_write ) ) {
				return $functions_write;
			}
		}
		return array(
			'target_path'             => $write['target_path'],
			'hash'                    => self::file_hash( $functions ),
			'payload_hash'            => hash( 'sha256', (string) file_get_contents( $functions ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reports the merged importer-owned bootstrap payload.
			'reconciliation_identity' => $write['reconciliation_identity'] ?? hash( 'sha256', $write['source_path'] . "\n" . $write['target_path'] ),
		);
	}

	/** Persist admitted provider overlay CSS and its frontend delivery bootstrap. */
	private static function apply_provider_layout_overlays( array &$state, array $overlays ) {
		$admitted = array_filter( $overlays, static fn( $overlay ): bool => null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $overlay ) );
		if ( empty( $admitted ) ) {
			return new WP_Error( 'provider_layout_overlay_rejected' );
		}
		$writes = self::provider_layout_stylesheet_writes( $state, $admitted );
		if ( is_wp_error( $writes ) ) {
			return $writes;
		}
		$reports = array();
		foreach ( $writes as $path => $content ) {
			$target   = ltrim( substr( $path, strlen( trailingslashit( $state['theme_dir'] ) ) ), '/' );
			$existing = file_exists( $path ) ? ( is_readable( $path ) ? file_get_contents( $path ) : false ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verifies an existing generated stylesheet before reconciliation.
			if ( false === $existing ) {
				return new WP_Error( 'provider_layout_stylesheet_read_failed' );
			}
			$composed = $state['composed_theme_writes'][ $path ] ?? $content;
			if ( $composed === $existing ) {
				$reports[] = array(
					'target_path' => $target,
					'hash'        => hash( 'sha256', $existing ),
					'status'      => 'already_satisfied',
				);
				self::record_overlay_stylesheet_file( $state['applied']['files'], $path, $target, $existing );
				continue;
			}
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path' => $target,
					'source_path' => $target,
					'payload'     => array(
						'encoding' => 'utf8',
						'data'     => $content,
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['status'] = 'applied';
			$reports[]        = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = self::canonical_file_receipt( $path, $file );
				}
			}
			self::record_overlay_stylesheet_file( $state['applied']['files'], $path, $target, $content );
		}
		return array(
			'status' => array_filter( $reports, static fn( array $report ): bool => 'applied' === ( $report['status'] ?? '' ) ) ? 'completed' : 'already_satisfied',
			'files'  => $reports,
		);
	}

	/** Derive expected overlay-composed stylesheets and frontend delivery bootstrap. */
	private static function provider_layout_stylesheet_writes( array $state, array $overlays ) {
		if ( empty( $overlays ) ) {
			return array();
		}
		foreach ( $overlays as $overlay ) {
			if ( null === Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $overlay ) ) {
				return new WP_Error( 'provider_layout_overlay_rejected' );
			}
		}
		$stylesheets = array();
		$source_css  = '';
		$functions   = null;
		foreach ( $state['resolved']['writes'] as $write ) {
			$target = (string) ( $write['target_path'] ?? '' );
			$css    = self::payload_data( $write );
			if ( str_ends_with( $target, '.css' ) && '' === $source_css ) {
				$source_css = $css;
			}
			if ( in_array( $target, array( 'style.css', 'assets/css/editor-style.css' ), true ) ) {
				$stylesheets[ $state['theme_dir'] . '/' . $target ] = $css;
			}
			if ( 'functions.php' === $target ) {
				$functions = $css;
			}
		}
		if ( ! is_string( $functions ) || ! str_starts_with( ltrim( $functions ), '<?php' ) ) {
			return new WP_Error( 'provider_layout_stylesheet_missing' );
		}
		if ( isset( $stylesheets[ $state['theme_dir'] . '/style.css' ], $stylesheets[ $state['theme_dir'] . '/assets/css/editor-style.css' ] ) ) {
			$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( $state['theme_dir'], '', '', array(), array(), $overlays, $stylesheets );
		} elseif ( '' !== $source_css ) {
			$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( $state['theme_dir'], (string) $state['theme']['slug'], $source_css, array(), array(), $overlays );
		} else {
			return new WP_Error( 'provider_layout_stylesheet_missing' );
		}
		$bootstrap                                        = "\n/* Static Site Importer provider layout overlay delivery. */\nadd_action( 'wp_enqueue_scripts', static function (): void {\n\twp_enqueue_style( 'static-site-importer-provider-layout-overlay', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n}, 20 );\n";
		$writes[ $state['theme_dir'] . '/functions.php' ] = str_contains( $functions, $bootstrap ) ? $functions : $functions . $bootstrap;
		return $writes;
	}

	/** @param array<mixed> $state @param array{writes:array<int,array<string,string>>,diagnostics:array<int,array<string,string>>} $overlay */
	private static function apply_font_overlay( array &$state, array $overlay ) {
		$reports = array();
		foreach ( $overlay['writes'] as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = 'base64' === ( $write['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['content'] ?? '' ), true ) : (string) ( $write['content'] ?? '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the declared font overlay payload for idempotent reconciliation.
			if ( false === $content ) {
				return new WP_Error( 'static_site_importer_font_materialization_payload_invalid' );
			}
			if ( str_ends_with( strtolower( $target ), '.svg' ) && ! empty( $overlay['svg_consumers'] ) && ! self::valid_svg_font_receipt( $overlay['svg_receipts'] ?? array(), $state['resolved']['writes'], $write ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			if ( ! self::safe_destination( $state['theme_dir'], $target ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_destination_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $target;
			if ( is_file( $path ) && self::file_hash( $path ) === hash( 'sha256', $content ) ) {
				$result    = array(
					'target_path'             => $target,
					'hash'                    => self::file_hash( $path ),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "font-materialization\n" . $target ),
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'status'                  => 'already_satisfied',
				);
				$reports[] = $result;
				$file      = $result;
				unset( $file['status'] );
				$replaced = false;
				foreach ( $state['applied']['files'] as $index => $applied_file ) {
					if ( ( $applied_file['target_path'] ?? null ) === $target ) {
						$state['applied']['files'][ $index ] = $file;
						$replaced                            = true;
						break;
					}
				}
				if ( ! $replaced ) {
					$state['applied']['files'][] = $file;
				}
				continue;
			}
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'payload'                 => array(
						'encoding' => (string) ( $write['encoding'] ?? 'utf8' ),
						'data'     => (string) ( $write['content'] ?? '' ),
					),
					'payload_hash'            => 'base64' === ( $write['encoding'] ?? 'utf8' ) ? hash( 'sha256', (string) base64_decode( (string) ( $write['content'] ?? '' ), true ) ) : hash( 'sha256', (string) ( $write['content'] ?? '' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Hashes decoded declared font payload bytes.
					'reconciliation_identity' => hash( 'sha256', "font-materialization\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['source_path'] = (string) ( $write['source_path'] ?? $target );
			$reports[]             = $result;
			$replaced              = false;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
					$replaced                            = true;
					break;
				}
			}
			if ( ! $replaced ) {
				$state['applied']['files'][] = $result;
			}
		}
		return array(
			'status'         => array_filter( $reports, static fn( array $report ): bool => 'already_satisfied' !== ( $report['status'] ?? '' ) ) ? 'completed' : 'already_satisfied',
			'files'          => $reports,
			'diagnostics'    => $overlay['diagnostics'],
			'faces'          => $overlay['faces'] ?? array(),
			'required_faces' => $overlay['required_faces'] ?? array(),
			'svg_receipts'   => $overlay['svg_receipts'] ?? array(),
			'svg_consumers'  => $overlay['svg_consumers'] ?? array(),
		);
	}

	/** @return array<string,string> */
	private static function font_overlay_writes( string $theme_dir, array $overlay ): array {
		$writes = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = 'base64' === ( $write['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['content'] ?? '' ), true ) : (string) ( $write['content'] ?? '' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the declared font overlay payload for preflight hashing.
			if ( '' !== $target && is_string( $content ) ) {
				$writes[ $theme_dir . '/' . $target ] = $content;
			}
		}
		return $writes;
	}

	/** @param array<string,mixed> $overlay */
	private static function apply_viewport_overlay( array &$state, array $overlay ) {
		if ( 'materialized' !== ( $overlay['status'] ?? '' ) ) {
			return array(
				'status'      => (string) ( $overlay['status'] ?? 'not_requested' ),
				'declaration' => '',
				'files'       => array(),
				'diagnostics' => $overlay['diagnostics'] ?? array(),
			);
		}
		$reports = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			$target  = (string) ( $write['target_path'] ?? '' );
			$content = (string) ( $write['content'] ?? '' );
			if ( ! self::safe_destination( $state['theme_dir'], $target ) || ! str_starts_with( ltrim( $content ), '<?php' ) ) {
				return new WP_Error( 'static_site_importer_viewport_metadata_materialization_invalid' );
			}
			$path = $state['theme_dir'] . '/' . $target;
			self::journal_file( $state, $path );
			$result = self::write_file(
				$state['theme_dir'],
				array(
					'target_path'             => $target,
					'source_path'             => (string) ( $write['source_path'] ?? $target ),
					'payload'                 => array( 'encoding' => 'utf8', 'data' => $content ),
					'payload_hash'            => hash( 'sha256', $content ),
					'reconciliation_identity' => hash( 'sha256', "viewport-metadata\n" . $target ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$reports[] = $result;
			foreach ( $state['applied']['files'] as $index => $file ) {
				if ( ( $file['target_path'] ?? null ) === $target ) {
					$state['applied']['files'][ $index ] = $result;
					continue 2;
				}
			}
			$state['applied']['files'][] = $result;
		}
		return array(
			'status'      => 'completed',
			'declaration' => (string) ( $overlay['declaration'] ?? '' ),
			'files'       => $reports,
			'diagnostics' => $overlay['diagnostics'] ?? array(),
		);
	}

	/** @param array<string,mixed> $overlay @return array<string,string> */
	private static function viewport_overlay_writes( string $theme_dir, array $overlay ): array {
		$writes = array();
		foreach ( $overlay['writes'] ?? array() as $write ) {
			if ( is_array( $write ) && is_string( $write['target_path'] ?? null ) && is_string( $write['content'] ?? null ) ) {
				$writes[ $theme_dir . '/' . $write['target_path'] ] = $write['content'];
			}
		}
		return $writes;
	}

	/** Verify every canonical asset publication against its resolved write and references. */
	private static function verify_asset_publications( array &$state ) {
		$writes = array();
		foreach ( $state['resolved']['writes'] as $write ) {
			$writes[ $write['reconciliation_identity'] ] = $write;
		}
		$files = array();
		foreach ( $state['applied']['files'] as $file ) {
			$files[ $file['reconciliation_identity'] ] = $file;
		}
		$references = array();
		foreach ( $state['resolved']['resolution']['asset_publication_references'] ?? array() as $reference ) {
			$references[ $reference['declaration_reconciliation_identity'] ][] = $reference;
		}

		foreach ( $state['resolved']['runtime_declarations'] as $declaration ) {
			if ( 'asset_publication' !== ( $declaration['kind'] ?? '' ) ) {
				continue;
			}
			$id    = $declaration['reconciliation_identity'];
			$write = null;
			foreach ( $state['resolved']['writes'] as $candidate ) {
				if ( ( $candidate['source_path'] ?? '' ) === $declaration['source_path'] && ( $candidate['kind'] ?? '' ) === 'theme_asset' ) {
					$write = $candidate;
					break;
				}
			}
			$applied     = is_array( $write ) ? ( $files[ $write['reconciliation_identity'] ] ?? null ) : null;
			$svg_receipt = self::svg_receipt_for_write( $state['applied']['font_materialization']['svg_receipts'] ?? array(), $write );
			$valid       = is_array( $write ) && is_array( $applied )
				&& ( is_array( $svg_receipt )
					? self::valid_svg_font_receipt_binding( $svg_receipt, $write ) && $svg_receipt['output_sha256'] === $applied['hash']
					: ( $write['canonical_payload_hash'] ?? $write['payload_hash'] ) === $declaration['expected_content_hash'] && $applied['hash'] === $write['payload_hash'] );
			foreach ( $references[ $id ] ?? array() as $reference ) {
				$target         = $writes[ $reference['write_reconciliation_identity'] ] ?? null;
				$target_path    = is_array( $target ) ? $state['theme_dir'] . '/' . $target['target_path'] : '';
				$target_content = '' !== $target_path && is_file( $target_path ) ? file_get_contents( $target_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verifies the final declared theme write.
				$valid          = $valid && is_array( $target )
					&& $reference['target_path'] === $target['target_path']
					&& is_string( $target_content )
					&& hash( 'sha256', $target_content ) === $target['payload_hash']
					&& substr_count( $target_content, $reference['expected_resolved_url'] ) === $reference['count'];
			}
			$state['applied']['runtime_declarations']['asset_publications'][ $id ] = array(
				'status'                        => $valid ? 'completed' : 'failed',
				'capability'                    => $declaration['destination']['capability'],
				'source_path'                   => $declaration['source_path'],
				'target_path'                   => is_array( $write ) ? $write['target_path'] : '',
				'write_reconciliation_identity' => is_array( $write ) ? $write['reconciliation_identity'] : '',
				'expected_content_hash'         => $declaration['expected_content_hash'],
				'actual_content_hash'           => is_array( $applied ) ? $applied['hash'] : '',
				'references'                    => $references[ $id ] ?? array(),
			);
			if ( ! $valid ) {
				return new WP_Error( 'static_site_importer_asset_publication_verification_failed' );
			}
		}
		return true;
	}

	private static function svg_receipt_for_write( array $receipts, mixed $write ): ?array {
		if ( ! is_array( $write ) ) {
			return null;
		}
		foreach ( $receipts as $receipt ) {
			if ( is_array( $receipt ) && ( $write['target_path'] ?? null ) === ( $receipt['target_path'] ?? null ) && ( $write['reconciliation_identity'] ?? null ) === ( $receipt['write_reconciliation_identity'] ?? null ) ) {
				return $receipt;
			}
		}
		return null;
	}

	/** Verify required SVG receipts even when no asset-publication declaration exists. */
	private static function verify_svg_font_materialization( array $state ) {
		$receipts  = $state['applied']['font_materialization']['svg_receipts'] ?? array();
		$consumers = $state['applied']['font_materialization']['svg_consumers'] ?? array();
		$files     = $state['applied']['font_materialization']['files'] ?? array();
		$seen      = array();
		foreach ( $receipts as $receipt ) {
			if ( ! is_array( $receipt ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			$write = null;
			foreach ( $state['resolved']['writes'] as $candidate ) {
				if ( is_array( $candidate ) && ( $receipt['write_reconciliation_identity'] ?? null ) === ( $candidate['reconciliation_identity'] ?? null ) ) {
					$write = $candidate;
					break;
				}
			}
			if ( ! self::valid_svg_font_receipt_binding( $receipt, $write ?? array() ) || isset( $seen[ $receipt['consumer_id'] ?? '' ] ) ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
			$seen[ $receipt['consumer_id'] ] = true;
			$path                            = $state['theme_dir'] . '/' . $receipt['target_path'];
			$file                            = array_values( array_filter( $files, static fn( mixed $row ): bool => is_array( $row ) && ( $receipt['target_path'] ?? null ) === ( $row['target_path'] ?? null ) ) )[0] ?? null;
			if ( ! is_array( $file ) || ! is_file( $path ) || self::file_hash( $path ) !== $receipt['output_sha256'] || ( $file['hash'] ?? null ) !== $receipt['output_sha256'] ) {
				return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
			}
		}
		$required_ids = array();
		foreach ( $consumers as $consumer ) {
			if ( is_array( $consumer ) && true === ( $consumer['required'] ?? null ) && is_string( $consumer['id'] ?? null ) ) {
				$required_ids[] = $consumer['id'];
			}
		}
		sort( $required_ids, SORT_STRING );
		$receipt_ids = array_keys( $seen );
		sort( $receipt_ids, SORT_STRING );
		if ( $required_ids !== $receipt_ids ) {
			return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
		}
		if ( ! empty( $consumers ) ) {
			foreach ( $files as $file ) {
				if ( is_array( $file ) && str_ends_with( strtolower( (string) ( $file['target_path'] ?? '' ) ), '.svg' ) && ! self::svg_receipt_for_target( $receipts, $file['target_path'] ?? '' ) ) {
					return new WP_Error( 'static_site_importer_font_materialization_svg_receipt_invalid' );
				}
			}
		}
		return true;
	}

	private static function svg_receipt_for_target( array $receipts, string $target ): bool {
		foreach ( $receipts as $receipt ) {
			if ( is_array( $receipt ) && ( $receipt['target_path'] ?? null ) === $target ) {
				return true;
			}
		}
		return false;
	}

	private static function valid_svg_font_receipt_binding( array $receipt, array $write ): bool {
		return 'static-site-importer/svg-font-materialization-receipt/v1' === ( $receipt['schema'] ?? null )
			&& ( $write['target_path'] ?? null ) === ( $receipt['target_path'] ?? null )
			&& ( $write['reconciliation_identity'] ?? null ) === ( $receipt['write_reconciliation_identity'] ?? null )
			&& hash( 'sha256', self::payload_data( $write ) ) === ( $receipt['input_sha256'] ?? null )
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['output_sha256'] ?? '' ) )
			&& true === ( $receipt['required'] ?? null )
			&& ! empty( $receipt['face_ids'] ) && is_array( $receipt['observed_font_sha256'] ?? null ) && ! empty( $receipt['observed_font_sha256'] );
	}

	/** Validate the only permitted post-canonical SVG mutation. */
	private static function valid_svg_font_receipt( array $receipts, array $canonical_writes, array $overlay_write ): bool {
		$target  = $overlay_write['target_path'] ?? null;
		$content = (string) ( $overlay_write['content'] ?? '' );
		foreach ( $receipts as $receipt ) {
			if ( ! is_array( $receipt ) || 'static-site-importer/svg-font-materialization-receipt/v1' !== ( $receipt['schema'] ?? null ) || true !== ( $receipt['required'] ?? null ) || ( $receipt['target_path'] ?? null ) !== $target || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['input_sha256'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['output_sha256'] ?? '' ) ) || ! is_array( $receipt['face_ids'] ?? null ) || empty( $receipt['face_ids'] ) || ! is_array( $receipt['receipt_ids'] ?? null ) || count( $receipt['face_ids'] ) !== count( $receipt['receipt_ids'] ) || ! is_array( $receipt['observed_font_sha256'] ?? null ) || empty( $receipt['observed_font_sha256'] ) ) {
				continue;
			}
			foreach ( $canonical_writes as $canonical ) {
				if ( ( $receipt['write_reconciliation_identity'] ?? null ) === ( $canonical['reconciliation_identity'] ?? null ) && ( $canonical['target_path'] ?? null ) === $target && hash( 'sha256', self::payload_data( $canonical ) ) === $receipt['input_sha256'] && hash( 'sha256', $content ) === $receipt['output_sha256'] && str_contains( $content, 'data:font/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function payload_data( array $write ): string {
		if ( null !== self::payload_reference( $write ) ) {
			return '';
		}
		$data = $write['payload']['data'] ?? '';
		return 'base64' === ( $write['payload']['encoding'] ?? null ) && is_string( $data ) ? (string) base64_decode( $data, true ) : (string) $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical payload bytes for receipt validation.
	}

	/** @param array<string,mixed> $operation @param array<string,int> $page_ids */
	private static function apply_operation( array $operation, array $page_ids, array $args = array() ) {
		$id = $page_ids[ $operation['front_page_reconciliation_identity'] ] ?? 0;
		if ( ! $id ) {
			return new WP_Error( 'operation_target_missing' );
		}
		if ( ! self::write_option( 'show_on_front', $operation['show_on_front'] ) ) {
			return new WP_Error( 'show_on_front_not_applied' );
		}
		if ( self::injected_failure( $args, 'after_show_on_front' ) ) {
			return new WP_Error( 'injected_after_show_on_front_failure' );
		}
		if ( ! self::write_option( 'page_on_front', $id ) ) {
			return new WP_Error( 'page_on_front_not_applied' );
		}
		if ( self::injected_failure( $args, 'after_page_on_front' ) ) {
			return new WP_Error( 'injected_after_page_on_front_failure' );
		}
		return array(
			'kind'                    => $operation['kind'],
			'order'                   => $operation['order'],
			'reconciliation_identity' => $operation['front_page_reconciliation_identity'],
		);
	}

	private static function reconciled_post( string $identity ) {
		// The reconciliation meta key is unique per document, so no post_type
		// filter is needed; 'any' covers posts, pages, and custom import types.
		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'meta_key'    => self::RECONCILIATION_META_KEY,
				'meta_value'  => $identity,
				'numberposts' => 1,
			)
		);
		return isset( $posts[0] ) ? $posts[0] : null;
	}

	private static function existing_source_page_id( string $source_path, string $import_run_id ): int {
		if ( '' === $import_run_id ) {
			return 0; }
		$posts = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'meta_key'    => '_static_site_importer_provenance',
				'numberposts' => -1,
			)
		);
		foreach ( $posts as $post ) {
			$provenance = json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true );
			if ( is_array( $provenance ) && ( $provenance['source_path'] ?? '' ) === $source_path && ( $provenance['import_run_id'] ?? '' ) === $import_run_id ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	private static function post_belongs_to_run( WP_Post $post, string $import_run_id ): bool {
		if ( '' === $import_run_id ) {
			return false; }
		$provenance = json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true );
		return is_array( $provenance ) && ( $provenance['import_run_id'] ?? '' ) === $import_run_id;
	}

	/**
	 * Whether a post type is registered on the runtime import target.
	 *
	 * Any registered type is valid; internal types without an object (revision,
	 * nav_menu_item, wp_template_part) fall back to 'page'.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function is_valid_post_type( string $post_type ): bool {
		if ( function_exists( 'get_post_type_object' ) ) {
			return get_post_type_object( $post_type ) instanceof WP_Post_Type;
		}
		return in_array( $post_type, array( 'page', 'post' ), true );
	}

	/** @param array<int,array<string,mixed>> $pages */
	private static function page_exists_in_plan( array $pages, string $identity ): bool {
		foreach ( $pages as $page ) {
			if ( $page['reconciliation_identity'] === $identity ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>>|null */
	private static function parent_ordered_pages( array $pages, string $import_run_id = '' ): ?array {
		$remaining = array();
		foreach ( $pages as $page ) {
			$remaining[ $page['source_path'] ] = $page;
		}
		$ordered          = array();
		$external_parents = array();
		while ( ! empty( $remaining ) ) {
			$progress = false;
			foreach ( $remaining as $source => $page ) {
				$parent = $page['parent_source_path'];
				if ( '' !== $parent && ! isset( $ordered[ $parent ] ) && ! isset( $external_parents[ $parent ] ) ) {
					if ( ! isset( $remaining[ $parent ] ) ) {
						if ( self::existing_source_page_id( $parent, $import_run_id ) <= 0 ) {
							return null; }
						$external_parents[ $parent ] = true;
						$progress                    = true;
						continue;
					}
					continue;
				}
				$ordered[ $source ] = $page;
				unset( $remaining[ $source ] );
				$progress = true;
			}
			if ( ! $progress ) {
				return null;
			}
		}
		return array_values( $ordered );
	}

	private static function theme_belongs_to_run( string $theme_dir, string $import_run_id ): bool {
		if ( '' === $import_run_id ) {
			return false; }
		$manifest = $theme_dir . '/static-site-importer-manifest.json';
		$data     = is_file( $manifest ) ? json_decode( (string) file_get_contents( $manifest ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer-owned run manifest for batch reconciliation.
		return is_array( $data ) && ( $data['import_run_id'] ?? '' ) === $import_run_id;
	}

	private static function safe_destination( string $theme_dir, string $target ): bool {
		$current = rtrim( $theme_dir, '/' );
		foreach ( explode( '/', dirname( $target ) ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			$current .= '/' . $segment;
			if ( is_link( $current ) || ( file_exists( $current ) && ! is_dir( $current ) ) || ( is_dir( $current ) && ! is_writable( $current ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Rejects unsafe native path segments before atomic local writes.
				return false;
			}
		}
		return ! is_link( $theme_dir . '/' . $target );
	}

	/** @param array<string,mixed> $write */
	private static function payload_hash( array $write ): string {
		$reference = self::payload_reference( $write );
		if ( null !== $reference ) {
			return self::valid_payload_reference( $reference ) ? $reference['sha256'] : '';
		}
		$data = 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical payload bytes before hashing.
		return is_string( $data ) ? hash( 'sha256', $data ) : '';
	}

	/** Return a declared binary reference without treating absent inline payloads as references. */
	private static function payload_reference( array $write ): ?array {
		$reference = $write['payload_reference'] ?? ( $write['payload']['reference'] ?? null );
		return is_array( $reference ) ? $reference : ( array_key_exists( 'payload_reference', $write ) || array_key_exists( 'reference', $write['payload'] ?? array() ) ? array() : null );
	}

	private static function valid_payload_reference( array $reference ): bool {
		return 'blocks-engine/payload-reference/v1' === ( $reference['schema'] ?? null )
			&& is_string( $reference['id'] ?? null ) && '' !== $reference['id']
			&& is_string( $reference['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $reference['sha256'] )
			&& ( ! isset( $reference['bytes'] ) || ( is_int( $reference['bytes'] ) && $reference['bytes'] >= 0 ) );
	}

	/**
	 * Admit every reference-backed write before materialization starts.
	 *
	 * Each payload is read and discarded independently so an unavailable or
	 * corrupted later resource cannot leave earlier pages or files behind.
	 * write_payload_bytes() repeats the verification immediately before a write
	 * to keep the write boundary safe if workspace contents change meanwhile.
	 *
	 * @param array<int,array<string,mixed>> $writes
	 */
	private static function verify_payload_references( array $writes, ?object $payload_reader ) {
		foreach ( $writes as $write ) {
			if ( null === self::payload_reference( $write ) ) {
				continue;
			}
			$bytes = self::write_payload_bytes( $write, $payload_reader );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
		}
		return true;
	}

	/** Resolve a reference exactly once at the write boundary and verify declared raw bytes. */
	private static function write_payload_bytes( array $write, ?object $payload_reader ) {
		$reference = self::payload_reference( $write );
		if ( null === $reference ) {
			return 'base64' === $write['payload']['encoding'] ? base64_decode( $write['payload']['data'], true ) : $write['payload']['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared canonical artifact payload bytes.
		}
		if ( ! self::valid_payload_reference( $reference ) ) {
			return new WP_Error( 'static_site_importer_payload_reference_invalid' );
		}
		if ( ! is_object( $payload_reader ) || ! is_callable( array( $payload_reader, 'read' ) ) ) {
			return new WP_Error( 'static_site_importer_payload_reader_missing' );
		}
		try {
			$bytes = $payload_reader->read( $reference );
		} catch ( Throwable ) {
			return new WP_Error( 'static_site_importer_payload_reference_unavailable' );
		}
		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'static_site_importer_payload_reference_unavailable' );
		}
		if ( ( isset( $reference['bytes'] ) && strlen( $bytes ) !== $reference['bytes'] ) || ! hash_equals( $reference['sha256'], hash( 'sha256', $bytes ) ) ) {
			return new WP_Error( 'static_site_importer_payload_reference_hash_mismatch' );
		}
		return $bytes;
	}

	private static function file_hash( string $path ): string {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Preflight hashes a declared destination file.
		return false === $data ? '' : hash( 'sha256', $data );
	}

	/** @param array<string,mixed> $state */
	private static function failed_receipt( array $state, int|string $reason ): array {
		$state['diagnostics'][]  = array( 'reason_code' => (string) $reason );
		$state['failure_reason'] = (string) $reason;
		self::rollback( $state );
		return self::receipt( 'partial', $state );
	}

	/** @param array<string,mixed> $state */
	private static function failed_receipt_from_error( array $state, WP_Error $error ): array {
		$state['diagnostics'][]  = array( 'reason_code' => $error->get_error_code() );
		$state['failure_reason'] = $error->get_error_code();
		$data                    = $error->get_error_data();
		if ( is_array( $data ) ) {
			foreach ( $data as $diagnostic ) {
				if ( ! is_array( $diagnostic ) ) {
					continue;
				}
				$reason = (string) ( $diagnostic['reason_code'] ?? $diagnostic['reason'] ?? $diagnostic['code'] ?? '' );
				if ( '' !== $reason ) {
					$state['diagnostics'][] = array_merge( $diagnostic, array( 'reason_code' => $reason ) );
				}
			}
		}
		self::rollback( $state );
		return self::receipt( 'partial', $state );
	}

	/** Preserve typed preflight diagnostics without claiming filesystem mutation. */
	private static function rejected_receipt_from_error( array $state, WP_Error $error ): array {
		unset( $state['preflight_error'] );
		$state['diagnostics'][]  = array( 'reason_code' => $error->get_error_code() );
		$state['failure_reason'] = $error->get_error_code();
		$data                    = $error->get_error_data();
		if ( is_array( $data ) ) {
			foreach ( $data as $diagnostic ) {
				if ( ! is_array( $diagnostic ) ) {
					continue;
				}
				$reason = (string) ( $diagnostic['reason_code'] ?? $diagnostic['reason'] ?? $diagnostic['code'] ?? '' );
				if ( '' !== $reason ) {
					$state['diagnostics'][] = array_merge( $diagnostic, array( 'reason_code' => $reason ) );
				}
			}
		}
		return self::receipt( 'rejected', $state );
	}

	/** Journal a post before any insert/update so failed theme writes restore it exactly enough for SSI ownership. */
	private static function journal_post( array &$state, array $page ): void {
		$id = (int) ( $page['planned_existing_id'] ?? 0 );
		if ( isset( $state['rollback']['posts'][ $id ] ) ) {
			return; }
		$post = 0 < $id && function_exists( 'get_post' ) ? get_post( $id, ARRAY_A ) : null;
		if ( $post ) {
			$state['rollback']['posts'][ $id ] = array(
				'existing'                                => true,
				'post'                                    => $post,
				'provenance'                              => get_post_meta( $id, '_static_site_importer_provenance', true ),
				'reconciliation_identity'                 => get_post_meta( $id, self::RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity'        => get_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity_exists' => metadata_exists( 'post', $id, self::PRODUCER_RECONCILIATION_META_KEY ),
			);
			return;
		}
		$state['rollback']['posts'][ 'new:' . (string) $page['source_path'] ] = array(
			'existing'    => false,
			'source_path' => (string) $page['source_path'],
		);
	}

	/** Journal original file bytes before atomic replacement. */
	private static function journal_file( array &$state, string $path ): void {
		if ( isset( $state['rollback']['files'][ $path ] ) ) {
			return; }
		$content                             = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Captures pre-write importer destination bytes for rollback.
		$state['rollback']['files'][ $path ] = false !== $content ? array(
			'exists'  => true,
			'content' => $content,
		) : array( 'exists' => false );
	}

	/** Snapshot all runtime state this materializer can mutate before activation. */
	private static function journal_runtime( array &$state ): void {
		foreach ( array( 'stylesheet', 'template', 'show_on_front', 'page_on_front', 'use_smilies', 'blogname' ) as $option ) {
			if ( isset( $state['rollback']['options'][ $option ] ) ) {
				continue;
			}
			$missing                                 = '__static_site_importer_missing_' . $option . '__';
			$value                                   = get_option( $option, $missing );
			$state['rollback']['options'][ $option ] = array(
				'exists' => $value !== $missing,
				'value'  => $value,
			);
		}
	}

	private static function write_option( string $option, mixed $value ): bool {
		if ( get_option( $option, null ) === $value ) {
			return true;
		}
		return false !== update_option( $option, $value ) && get_option( $option, null ) === $value;
	}

	/** @param array<string,mixed> $args @return array<string,mixed> */
	private static function discover_default_content( array &$args ): array {
		$requested                      = isset( $args['remove_default_content'] ) ? (bool) $args['remove_default_content'] : true;
		$args['remove_default_content'] = (bool) apply_filters( 'static_site_importer_remove_default_content', $requested, $args );
		return $args['remove_default_content'] ? Static_Site_Importer_Default_Content::discover() : array(
			'eligible' => false,
			'posts'    => array(),
			'comments' => array(),
		);
	}

	private static function active_theme_matches( string $stylesheet, ?string $template = null ): bool {
		$template = $template ?? $stylesheet;
		return ( function_exists( 'get_stylesheet' ) ? get_stylesheet() : get_option( 'stylesheet', '' ) ) === $stylesheet
			&& ( function_exists( 'get_template' ) ? get_template() : get_option( 'template', '' ) ) === $template;
	}

	private static function injected_failure( array $args, string $stage ): bool {
		return (string) ( $args['inject_materialization_failure'] ?? '' ) === $stage;
	}

	/** Add a late file mutation to a deferred materialization receipt. */
	public static function journal_receipt_file( array &$receipt, string $path ): void {
		if ( isset( $receipt['transaction'] ) && is_object( $receipt['transaction'] ) && is_array( $receipt['transaction']->state ?? null ) ) {
			self::journal_file( $receipt['transaction']->state, $path );
		}
	}

	/** Add a late post mutation to a deferred materialization receipt. */
	public static function journal_receipt_post( array &$receipt, int $id ): void {
		if ( $id <= 0 || ! isset( $receipt['transaction'] ) || ! is_object( $receipt['transaction'] ) || ! is_array( $receipt['transaction']->state ?? null ) ) {
			return;
		}
		if ( isset( $receipt['transaction']->state['rollback']['posts'][ $id ] ) ) {
			return;
		}
		$post = function_exists( 'get_post' ) ? get_post( $id, ARRAY_A ) : null;
		if ( $post ) {
			$receipt['transaction']->state['rollback']['posts'][ $id ] = array(
				'existing'                                => true,
				'post'                                    => $post,
				'provenance'                              => get_post_meta( $id, '_static_site_importer_provenance', true ),
				'reconciliation_identity'                 => get_post_meta( $id, self::RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity'        => get_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY, true ),
				'producer_reconciliation_identity_exists' => metadata_exists( 'post', $id, self::PRODUCER_RECONCILIATION_META_KEY ),
			);
		}
	}

	/** Commit a deferred receipt after every durable projection has completed. */
	public static function commit_receipt( array &$receipt ): void {
		unset( $receipt['transaction'] );
	}

	/** Roll back a deferred receipt, including late files and options, in reverse journal order. */
	public static function rollback_receipt( array &$receipt, string $reason ): array {
		if ( ! isset( $receipt['transaction'] ) || ! is_object( $receipt['transaction'] ) || ! is_array( $receipt['transaction']->state ?? null ) ) {
			return $receipt;
		}
		$state                   = $receipt['transaction']->state;
		$state['diagnostics'][]  = array( 'reason_code' => $reason );
		$state['failure_reason'] = $reason;
		self::rollback( $state );
		$result                        = self::receipt( 'partial', $state );
		$receipt['transaction']->state = $state;
		$result['transaction']         = $receipt['transaction'];
		return $result;
	}

	/** Revert importer-owned runtime state, writes, and posts on a failed receipt. */
	private static function rollback( array &$state ): void {
		if ( ! empty( $state['rollback']['done'] ) ) {
			return; }
		$state['rollback']['done'] = true;
		self::restore_runtime( $state );
		foreach ( array_reverse( $state['rollback']['files'] ?? array(), true ) as $path => $before ) {
			if ( ! is_array( $before ) ) {
				continue; }
			try {
				if ( ! empty( $before['exists'] ) && is_string( $before['content'] ?? null ) ) {
					self::restore_file( $path, $before['content'] );
				} elseif ( is_file( $path ) && ! wp_delete_file( $path ) ) {
					throw new RuntimeException( 'materialization_rollback_file_delete_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'file', (string) $path, $error );
			}
		}
		foreach ( array_reverse( $state['applied']['posts'] ?? array() ) as $applied ) {
			$id     = (int) ( $applied['id'] ?? 0 );
			$before = $state['rollback']['posts'][ $id ] ?? $state['rollback']['posts'][ 'new:' . (string) ( $applied['source_path'] ?? '' ) ] ?? null;
			if ( ! is_array( $before ) || $id <= 0 ) {
				continue; }
			try {
				if ( ! empty( $before['existing'] ) ) {
					wp_update_post( $before['post'] );
					update_post_meta( $id, '_static_site_importer_provenance', $before['provenance'] );
					update_post_meta( $id, self::RECONCILIATION_META_KEY, $before['reconciliation_identity'] );
					if ( ! empty( $before['producer_reconciliation_identity_exists'] ) ) {
						if ( ! self::write_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY, (string) $before['producer_reconciliation_identity'] ) ) {
							throw new RuntimeException( 'materialization_rollback_post_meta_restore_failed' );
						}
					} else {
						delete_post_meta( $id, self::PRODUCER_RECONCILIATION_META_KEY );
						if ( metadata_exists( 'post', $id, self::PRODUCER_RECONCILIATION_META_KEY ) ) {
							throw new RuntimeException( 'materialization_rollback_post_meta_delete_failed' );
						}
					}
				} elseif ( function_exists( 'wp_delete_post' ) && ! wp_delete_post( $id, true ) ) {
					throw new RuntimeException( 'materialization_rollback_post_delete_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'post', (string) $id, $error );
			}
		}
		$state['applied']['files'] = array();
		$state['applied']['posts'] = array();
		$state['diagnostics'][]    = array( 'reason_code' => 'materialization_rolled_back' );
	}

	/** Restore options and the prior active theme before deleting generated theme files. */
	private static function restore_runtime( array &$state ): void {
		$options    = $state['rollback']['options'] ?? array();
		$stylesheet = $options['stylesheet']['value'] ?? null;
		$template   = $options['template']['value'] ?? null;
		if ( ! empty( $options['stylesheet']['exists'] ) && ! empty( $options['template']['exists'] ) && is_string( $stylesheet ) && '' !== $stylesheet && is_string( $template ) && '' !== $template && function_exists( 'switch_theme' ) ) {
			try {
				switch_theme( $stylesheet );
				if ( ! self::active_theme_matches( $stylesheet, $template ) ) {
					throw new RuntimeException( 'materialization_rollback_theme_restore_failed' );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'theme', $stylesheet, $error );
			}
		}
		foreach ( array_reverse( $options, true ) as $option => $before ) {
			try {
				if ( ! empty( $before['exists'] ) ) {
					update_option( $option, $before['value'] );
				} elseif ( function_exists( 'delete_option' ) ) {
					delete_option( $option );
				}
			} catch ( Throwable $error ) {
				self::record_rollback_failure( $state, 'option', (string) $option, $error );
			}
		}
	}

	/** Retain bounded failure evidence without replaying the immutable journal on retry. */
	private static function record_rollback_failure( array &$state, string $kind, string $target, Throwable $error ): void {
		$state['rollback']['partial'] = true;
		if ( count( $state['rollback']['failures'] ?? array() ) < 32 ) {
			$state['rollback']['failures'][] = array(
				'kind'   => $kind,
				'target' => $target,
				'code'   => $error->getMessage(),
			);
		}
		$state['diagnostics'][] = array(
			'reason_code' => 'materialization_rollback_' . $kind . '_failed',
			'target'      => $target,
		);
	}

	/** Restore a journaled file through the WordPress filesystem abstraction. */
	private static function restore_file( string $path, string $content ): void {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! is_callable( array( $wp_filesystem, 'put_contents' ) ) || ! call_user_func( array( $wp_filesystem, 'put_contents' ), $path, $content, 0644 ) ) {
			throw new RuntimeException( 'materialization_rollback_file_restore_failed' );
		}
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private static function receipt( string $status, array $state ): array {
		unset( $state['font_overlay'], $state['viewport_overlay'], $state['provider_layout_overlay_writes'], $state['composed_theme_writes'], $state['preflight_error'] );
		$plan                   = $state['plan'];
		$resolved_plan          = $state['resolved'] ?? $plan;
		$materialized_pages     = array();
		$block_provenance       = array();
		$block_provenance_count = 0;
		$written_sources        = array_fill_keys( array_filter( array_column( $state['applied']['posts'] ?? array(), 'source_path' ), 'is_string' ), true );
		$receipt_pages          = $resolved_plan['pages'] ?? array();
		foreach ( $receipt_pages as &$page ) {
			if ( isset( $written_sources[ $page['source_path'] ] ) ) {
				$materialized_markup = (string) ( $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ?? '' );
				++$block_provenance_count;
				if ( count( $block_provenance ) < self::BLOCK_PROVENANCE_LIMIT ) {
					$block_provenance[] = self::block_provenance( $page, $materialized_markup );
				}
				if ( isset( $page['materialized_block_markup'] ) ) {
					$materialized_pages[ $page['source_path'] ] = array(
						'block_markup' => $page['materialized_block_markup'],
						'content_hash' => hash( 'sha256', $page['materialized_block_markup'] ),
					);
				}
			}
			if ( isset( $page['materialized_block_markup'] ) ) {
				unset( $page['materialized_block_markup'] );
			}
		}
		unset( $page );
		$resolved_plan['pages'] = $receipt_pages;
		$errors                 = array();
		$pages                  = isset( $state['source_ids'] ) && is_array( $state['source_ids'] ) ? $state['source_ids'] : array();
		if ( isset( $state['failure_reason'] ) && is_string( $state['failure_reason'] ) && '' !== $state['failure_reason'] ) {
			$errors[] = array(
				'code'    => $state['failure_reason'],
				'message' => $state['failure_reason'],
			);
		}
		$receipt = array(
			'schema'                    => self::RECEIPT_SCHEMA,
			'status'                    => $status,
			'plan_identity'             => $state['plan_identity'],
			'receipt_instance_id'       => self::valid_receipt_instance_id( $state['receipt_instance_id'] ?? null ) ? $state['receipt_instance_id'] : self::receipt_instance_id(),
			'plan'                      => $resolved_plan,
			'theme'                     => $state['theme'] ?? array(),
			'completed'                 => array(
				'pages'                      => $pages,
				'files'                      => $state['applied']['files'],
				'operations'                 => $state['applied']['operations'],
				'runtime_declarations'       => $state['applied']['runtime_declarations'] ?? array( 'asset_publications' => array() ),
				'font_materialization'       => $state['applied']['font_materialization'] ?? array(
					'status'      => 'not_requested',
					'files'       => array(),
					'diagnostics' => array(),
				),
				'viewport_metadata'          => $state['applied']['viewport_metadata'] ?? array(
					'status'      => 'not_requested',
					'declaration' => '',
					'files'       => array(),
					'diagnostics' => array(),
				),
				'provider_layout_overlays'   => $state['applied']['provider_layout_overlays'] ?? array(
					'status' => 'not_requested',
					'files'  => array(),
				),
				'runtime_policy'             => array(
					'disable_smilies'        => array(
						'requested' => isset( $state['args']['disable_smilies'] ) ? (bool) $state['args']['disable_smilies'] : true,
						'applied'   => isset( $state['applied']['runtime_policy']['disable_smilies'] ) && true === $state['applied']['runtime_policy']['disable_smilies'],
					),
					'remove_default_content' => array(
						'requested' => isset( $state['args']['remove_default_content'] ) ? (bool) $state['args']['remove_default_content'] : true,
						'report'    => $state['applied']['runtime_policy']['remove_default_content'] ?? array( 'status' => 'not_applied' ),
					),
				),
				'materialized_pages'         => $materialized_pages,
				'block_provenance'           => $block_provenance,
				'block_provenance_count'     => $block_provenance_count,
				'block_provenance_truncated' => $block_provenance_count > count( $block_provenance ),
				'declaration_ids'            => array_keys( $state['applied']['runtime_declarations']['asset_publications'] ?? array() ),
			),
			'reconciliation_identities' => array_merge( array_column( $plan['pages'] ?? array(), 'reconciliation_identity' ), array_column( $plan['writes'] ?? array(), 'reconciliation_identity' ), array_column( $plan['runtime_declarations'] ?? array(), 'reconciliation_identity' ) ),
			'wordpress'                 => $state['applied']['posts'],
			'generated_files'           => $state['applied']['files'],
			'operations'                => $state['applied']['operations'],
			'skipped_targets'           => $state['skipped'],
			'existing_matches'          => $state['existing_matches'],
			'rollback'                  => array(
				'status'   => ! empty( $state['rollback']['partial'] ) ? 'partial' : ( ! empty( $state['rollback']['done'] ) ? 'rolled_back' : 'not_requested' ),
				'failures' => array_slice( is_array( $state['rollback']['failures'] ?? null ) ? $state['rollback']['failures'] : array(), 0, 32 ),
			),
			'preparation'               => $state['preparation'] ?? array(),
			'editability_report'        => $state['editability_report'] ?? array(
				'schema' => 'static-site-importer/editability-report-admission/v1',
				'status' => 'not_checked',
			),
			'quality_budget_admission'  => $state['quality_budget_admission'] ?? Static_Site_Importer_Quality_Budget_Admission::evaluate( $plan, $resolved_plan, $state['args'] ?? array() ),
			'diagnostics'               => $state['diagnostics'],
			'errors'                    => $errors,
			'theme_materialization'     => $state['theme_materialization'] ?? self::strategy_evidence( $state['args'] ?? array() ),
		);
		$receipt['quality_budget_admission']['mechanical_status'] = 'completed' === $status ? 'completed' : $status;
		if ( ! empty( $state['args']['defer_materialization_commit'] ) && 'completed' === $status ) {
			$receipt['transaction'] = (object) array( 'state' => $state );
		}
		return $receipt;
	}

	/** Generate a server-side receipt identity that cannot be inferred from the plan. */
	private static function receipt_instance_id(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** Validate the persistent receipt identity across deferred materialization phases. */
	private static function valid_receipt_instance_id( mixed $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $id );
	}

	/**
	 * Admit producer-owned editability evidence without recreating its metrics or policy.
	 *
	 * Current Blocks Engine plans carry the required policy but not the report binding.
	 * That compatibility path is explicit and can be retired by setting
	 * quality.editability_report_required on plans from upgraded producers.
	 *
	 * @param array<string,mixed> $plan
	 * @return array<string,mixed>
	 */
	private static function editability_report_admission( array $plan ): array {
		$quality = isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array();
		$policy  = isset( $quality['editability_policy'] ) && is_array( $quality['editability_policy'] ) ? $quality['editability_policy'] : array();
		$base    = array(
			'schema'        => 'static-site-importer/editability-report-admission/v1',
			'owning_layer'  => 'blocks-engine',
			'policy_schema' => (string) ( $policy['schema'] ?? '' ),
		);
		if ( array() !== $policy && ( 'blocks-engine/php-transformer/editability-policy/v1' !== ( $policy['schema'] ?? null ) || 'required' !== ( $policy['enforcement'] ?? null ) || ! in_array( $policy['status'] ?? null, array( 'passed', 'failed' ), true ) || ! isset( $policy['failures'] ) || ! is_array( $policy['failures'] ) ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_policy_invalid' );
		}
		if ( 'failed' === ( $policy['status'] ?? null ) ) {
			return array_merge(
				$base,
				array(
					'status'     => 'failed',
					'diagnostic' => array(
						'reason_code'        => 'editability_policy_failed',
						'owning_layer'       => 'blocks-engine',
						'threshold_failures' => array_slice( array_values( array_filter( $policy['failures'], 'is_array' ) ), 0, 10 ),
					),
				)
			);
		}

		$report = $quality['editability_report'] ?? null;
		if ( null === $report ) {
			if ( ! empty( $quality['editability_report_required'] ) ) {
				return self::rejected_editability_report_admission( $base, 'editability_report_required' );
			}
			return array_merge(
				$base,
				array(
					'status'     => 'compatibility_policy_only',
					'diagnostic' => array(
						'reason_code'  => 'editability_report_compatibility_policy_only',
						'owning_layer' => 'blocks-engine',
					),
				)
			);
		}
		if ( ! is_array( $report ) || 'blocks-engine/php-transformer/editability-report/v2' !== ( $report['schema'] ?? null ) || ! is_array( $report['metrics'] ?? null ) || ! is_array( $report['block_types'] ?? null ) || ! is_array( $report['signals'] ?? null ) || ! is_array( $report['signal_totals'] ?? null ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_report_schema_invalid' );
		}
		$bound_hash = $quality['editability_report_plan_hash'] ?? null;
		if ( ! is_string( $bound_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $bound_hash ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_report_plan_hash_invalid' );
		}
		$unbound_plan = $plan;
		unset( $unbound_plan['plan_identity'], $unbound_plan['quality']['editability_report'], $unbound_plan['quality']['editability_report_plan_hash'], $unbound_plan['quality']['editability_report_required'] );
		$expected_hash = WordPressSitePlan::planIdentity( $unbound_plan )['hash'];
		if ( ! hash_equals( $expected_hash, $bound_hash ) ) {
			return self::rejected_editability_report_admission( $base, 'editability_report_plan_hash_mismatch' );
		}
		return array_merge(
			$base,
			array(
				'status'        => 'passed',
				'report_schema' => $report['schema'],
				'plan_hash'     => $bound_hash,
				'diagnostic'    => array(
					'reason_code'  => 'editability_report_verified',
					'owning_layer' => 'blocks-engine',
				),
			)
		);
	}

	/** @param array<string,mixed> $base @param array<int,mixed> $failures @return array<string,mixed> */
	private static function rejected_editability_report_admission( array $base, string $reason_code, array $failures = array() ): array {
		$diagnostic = array(
			'reason_code'  => $reason_code,
			'owning_layer' => 'blocks-engine',
		);
		if ( array() !== $failures ) {
			$diagnostic['threshold_failures'] = array_slice( array_values( array_filter( $failures, 'is_array' ) ), 0, 10 );
		}
		return array_merge(
			$base,
			array(
				'status'     => 'rejected',
				'diagnostic' => $diagnostic,
			)
		);
	}

	/** @return array<string,mixed> */
	private static function strategy_evidence( array $args ): array {
		$strategy = Static_Site_Importer_Theme_Materialization_Strategy::normalize( $args );
		if ( is_wp_error( $strategy ) ) {
			return array(
				'schema'      => 'static-site-importer/theme-materialization-evidence/v1',
				'status'      => 'invalid',
				'reason_code' => $strategy->get_error_code(),
			);
		}
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] && is_array( $args['classic_theme_projection'] ?? null ) ) {
			$strategy['evidence']['status']            = 'source_artifact_projection';
			$strategy['evidence']['projection_schema'] = $args['classic_theme_projection']['schema'] ?? '';
		}
		return $strategy['evidence'];
	}

	/**
	 * Record bounded stage evidence for one WordPress page without retaining markup.
	 *
	 * @param array<string,mixed> $page              Resolved compiler page.
	 * @param string              $materialized_markup WordPress post-content markup.
	 * @return array<string,mixed>
	 */
	private static function block_provenance( array $page, string $materialized_markup ): array {
		$resolved_markup   = (string) ( $page['resolved_block_markup'] ?? '' );
		$resolved_evidence = self::bounded_block_markup_evidence( $resolved_markup );
		$stages            = array(
			array(
				'stage'  => 'blocks-engine/wordpress-site-plan-resolver',
				'output' => $resolved_evidence,
			),
		);
		if ( $resolved_markup !== $materialized_markup ) {
			$stages[] = array(
				'stage'        => 'static-site-importer/runtime-entity-bindings',
				'input_sha256' => $resolved_evidence['sha256'],
				'output'       => self::bounded_block_markup_evidence( $materialized_markup ),
			);
		}

		return array(
			// This mirrors the page meta provenance written during materialization.
			'source' => array(
				'schema'                  => 'static-site-importer/page-provenance/v1',
				'source_path'             => (string) ( $page['source_path'] ?? '' ),
				'reconciliation_identity' => (string) ( $page['reconciliation_identity'] ?? '' ),
			),
			'stages' => $stages,
		);
	}

	/** @return array{sha256:string,bytes:int} */
	private static function bounded_block_markup_evidence( string $markup ): array {
		return array(
			'sha256' => hash( 'sha256', $markup ),
			'bytes'  => strlen( $markup ),
		);
	}

	/** @param array<string,mixed> $plan */
	private static function hash( array $plan ): string {
		$context = hash_init( 'sha256' );
		self::hash_json_value( $context, $plan );
		return hash_final( $context );
	}

	/** @return array{schema:string,hash:string}|array{} */
	private static function plan_identity( array $plan ): array {
		$identity = $plan['plan_identity'] ?? null;
		if ( ! is_array( $identity ) || 'blocks-engine/wordpress-site-plan-identity/v1' !== ( $identity['schema'] ?? null ) || ! is_string( $identity['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $identity['hash'] ) ) {
			return array();
		}
		return array(
			'schema' => $identity['schema'],
			'hash'   => $identity['hash'],
		);
	}

	/** Hash the resolved projection only for prepare-to-write change detection. */
	public static function prepared_resolved_projection_hash( array $projection ): string {
		return self::hash( $projection );
	}

	/** Stream the exact JSON token sequence previously passed to hash(). */
	private static function hash_json_value( $context, mixed $value ): void {
		if ( ! is_array( $value ) ) {
			hash_update( $context, (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( self::json_list( $value ) ) {
			hash_update( $context, '[' );
			foreach ( $value as $index => $item ) {
				if ( 0 !== $index ) {
					hash_update( $context, ',' );
				}
				self::hash_json_value( $context, $item );
			}
			hash_update( $context, ']' );
			return;
		}
		hash_update( $context, '{' );
		$first = true;
		foreach ( $value as $key => $item ) {
			if ( ! $first ) {
				hash_update( $context, ',' );
			}
			$first = false;
			hash_update( $context, (string) wp_json_encode( (string) $key, JSON_UNESCAPED_SLASHES ) );
			hash_update( $context, ':' );
			self::hash_json_value( $context, $item );
		}
		hash_update( $context, '}' );
	}

	/** Match PHP's array-to-JSON list detection without materializing JSON. */
	private static function json_list( array $value ): bool {
		$index = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}
		return true;
	}
}
