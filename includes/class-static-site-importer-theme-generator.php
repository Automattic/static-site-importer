<?php
/**
 * Block theme generator.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Generic.Formatting.MultipleStatementAlignment -- The generator keeps localized assignment alignment; PHPCBF exhausts memory on this large file.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Site_Identity' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-identity.php';
}

if ( ! class_exists( 'Static_Site_Importer_Content_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-content-policy.php';
}

if ( ! class_exists( 'Static_Site_Importer_Block_Document_Reporter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-block-document-reporter.php';
}

if ( ! class_exists( 'Static_Site_Importer_Report_Diagnostics' ) ) {
	require_once __DIR__ . '/class-static-site-importer-report-diagnostics.php';
}
if ( ! class_exists( 'Static_Site_Importer_Client_Script_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-client-script-policy.php';
}
if ( ! class_exists( 'Static_Site_Importer_Lifecycle_Compile_Checkpoint' ) ) {
	require_once __DIR__ . '/class-static-site-importer-lifecycle-compile-checkpoint.php';
}

/**
 * Generates a block theme from a static HTML document.
 */
class Static_Site_Importer_Theme_Generator {

	/**
	 * Import a website artifact bundle as a block theme.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function import_website_artifact( array $artifact, array $args = array() ) {
		$request_artifact    = $artifact;
		$checkpoint_owner    = Static_Site_Importer_Lifecycle_Compile_Checkpoint::current_owner();
		$phase               = (string) ( $args['runtime_lifecycle_phase'] ?? '' );
		$prepared_invocation = (string) ( $args['runtime_lifecycle_request_id'] ?? '' );
		$current_invocation  = (string) ( $args['runtime_lifecycle_invocation_id'] ?? '' );
		$request_args = $args;
		$checkpoint  = null;
		$resume_args = array();
		if ( 'resume' === $phase && '' !== (string) ( $args['runtime_lifecycle_checkpoint'] ?? '' ) ) {
			$checkpoint = Static_Site_Importer_Lifecycle_Compile_Checkpoint::load(
				(string) $args['runtime_lifecycle_checkpoint'],
				$request_artifact,
				$request_args,
				$checkpoint_owner,
				(string) ( $args['_static_site_importer_lifecycle_checkpoint_root'] ?? '' )
			);
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$compiled_import = $checkpoint['payload'];
			if ( ! isset( $compiled_import['artifact'], $compiled_import['args'], $compiled_import['plan'], $compiled_import['gutenberg_gaps'], $compiled_import['materialization_plan'], $compiled_import['theme_materialization'] ) || ! is_array( $compiled_import['artifact'] ) || ! is_array( $compiled_import['args'] ) || ! is_array( $compiled_import['plan'] ) || ! is_array( $compiled_import['gutenberg_gaps'] ) || ! is_array( $compiled_import['materialization_plan'] ) || ! is_array( $compiled_import['theme_materialization'] ) ) {
				return new WP_Error( 'static_site_importer_lifecycle_checkpoint_invalid', 'The lifecycle compile checkpoint payload is invalid.' );
			}
			$resume_args = array(
				'runtime_lifecycle_phase'         => $phase,
				'runtime_lifecycle_request_id'    => $prepared_invocation,
				'runtime_lifecycle_invocation_id' => $current_invocation,
				'runtime_lifecycle_checkpoint'    => (string) $request_args['runtime_lifecycle_checkpoint'],
			);
		} else {
			$compiled_import = self::compile_website_artifact( $artifact, $args );
			if ( is_wp_error( $compiled_import ) ) {
				return $compiled_import;
			}
		}
		$artifact              = $compiled_import['artifact'];
		$args                  = $compiled_import['args'];
		$args                  = array_merge( $args, $resume_args );
		$plan                  = $compiled_import['plan'];
		$gutenberg_gaps        = $compiled_import['gutenberg_gaps'];
		$companion_payload     = $compiled_import['companion_payload'];
		$materialization_plan  = $compiled_import['materialization_plan'];
		$theme_materialization = $compiled_import['theme_materialization'];
		$args['font_materialization'] = isset( $materialization_plan['theme']['font_materialization'] ) && is_array( $materialization_plan['theme']['font_materialization'] ) ? $materialization_plan['theme']['font_materialization'] : array();
		if ( ! empty( $args['fail_on_quality'] ) && empty( $plan['quality']['pass'] ) ) {
			return new WP_Error(
				'static_site_importer_quality_gate_failed',
				'Website artifact did not pass the canonical plan quality gate.',
				array(
					'quality'     => $plan['quality'] ?? array(),
					'diagnostics' => $plan['diagnostics'] ?? array(),
				)
			);
		}
		$lifecycle = self::prepare_wordpress_site_plan_lifecycle( $plan, $args );
		if ( is_wp_error( $lifecycle ) ) {
			return $lifecycle;
		}
		if ( 'plan' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
			$encoded_artifact = wp_json_encode( $artifact );
			return Static_Site_Importer_Entity_Materializer_Registry::dependency_plan( $lifecycle, hash( 'sha256', false !== $encoded_artifact ? $encoded_artifact : '' ) );
		}
		if ( 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
			$handle = Static_Site_Importer_Lifecycle_Compile_Checkpoint::create(
				$request_artifact,
				$request_args,
				array(
					'artifact'              => $artifact,
					'args'                  => $args,
					'plan'                  => $plan,
					'gutenberg_gaps'        => $gutenberg_gaps,
					'companion_payload'     => $companion_payload,
					'materialization_plan'  => $materialization_plan,
					'theme_materialization' => $theme_materialization,
				),
				$checkpoint_owner,
				(string) ( $args['_static_site_importer_lifecycle_checkpoint_root'] ?? '' )
			);
			if ( is_wp_error( $handle ) ) {
				return $handle;
			}
			$dependencies = self::materialize_prepared_dependencies( $lifecycle, $args );
			if ( is_wp_error( $dependencies ) ) {
				Static_Site_Importer_Lifecycle_Compile_Checkpoint::discard( $handle, (string) ( $args['_static_site_importer_lifecycle_checkpoint_root'] ?? '' ) );
				return $dependencies;
			}
			return array(
				'status'                       => 'dependencies_prepared',
				'runtime_lifecycle'            => $lifecycle,
				'dependencies'                 => $dependencies,
				'fresh_runtime'                => array(
					'request_id'              => (string) ( $args['runtime_lifecycle_invocation_id'] ?? '' ),
					'lifecycle_checkpoint_id' => $handle,
				),
				'runtime_lifecycle_checkpoint' => $handle,
			);
		}

		if ( is_array( $checkpoint ) ) {
			$claimed = Static_Site_Importer_Lifecycle_Compile_Checkpoint::claim( $checkpoint['workspace'] );
			if ( is_wp_error( $claimed ) ) {
				return $claimed;
			}
		}
		$result = self::materialize_compiled_website_artifact( $artifact, $args, $plan, $gutenberg_gaps, $companion_payload, $lifecycle, $theme_materialization );
		if ( is_array( $checkpoint ) ) {
			$checkpoint['workspace']->cleanup( 'success' );
		}
		return $result;
	}

	/** Compile an artifact into its immutable canonical WordPress site plan. */
	public static function compile_website_artifact( array $artifact, array $args = array() ) {
		$precompiled   = ! empty( $args['_static_site_importer_precompiled_source'] ) && is_array( $args['compiled_artifact_result'] ?? null );
		$strategy = Static_Site_Importer_Theme_Materialization_Strategy::normalize( $args );
		if ( is_wp_error( $strategy ) ) {
			return $strategy;
		}
		$args['theme_materialization'] = $strategy['strategy'];
		$source_policy = $precompiled ? true : Static_Site_Importer_Content_Policy::validate_artifact( $artifact );
		if ( is_wp_error( $source_policy ) ) {
			return $source_policy;
		}
		$script_policy                       = $precompiled ? array(
			'artifact' => $artifact,
			'report'   => $args['source_metadata']['collection']['script_policy'] ?? array(),
		) : Static_Site_Importer_Client_Script_Policy::apply( $artifact, $args );
		$artifact                            = $script_policy['artifact'];
		$args['client_script_policy_report'] = $script_policy['report'];
		$compiler_class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		if ( ! class_exists( $compiler_class ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to import a website artifact.' );
		}
		// site_title (blogname) intentionally stays restricted to an explicit arg
		// or a real extracted document title; it never falls back to the host or
		// generic constant the way the theme name/slug do.
		if ( empty( $args['site_title'] ) ) {
			$site_title = Static_Site_Importer_Site_Identity::title_from_website_artifact( $artifact );
			if ( '' !== $site_title ) {
				$args['site_title'] = $site_title;
			}
		}
		$identity = Static_Site_Importer_Site_Identity::resolve(
			array(
				'site_title' => isset( $args['site_title'] ) ? (string) $args['site_title'] : '',
				'name'       => isset( $args['name'] ) ? (string) $args['name'] : '',
				'slug'       => isset( $args['slug'] ) ? (string) $args['slug'] : '',
				'artifact'   => $artifact,
				'url'        => isset( $args['url'] ) ? (string) $args['url'] : '',
			)
		);
		if ( empty( $args['name'] ) ) {
			$args['name'] = $identity['name'];
		}
		if ( empty( $args['slug'] ) ) {
			$args['slug'] = $identity['slug'];
		}
		if ( empty( $args['source_artifact_reference'] ) ) {
			$args['source_artifact_reference'] = self::source_artifact_reference_from_artifact( $artifact, $args );
		}

		// A URL batch run composes this canonical compiler result before the one
		// serialized WordPress mutation. Direct callers retain whole-artifact compilation.
		$compiled = isset( $args['compiled_artifact_result'] ) && is_array( $args['compiled_artifact_result'] ) ? $args['compiled_artifact_result'] : ( new $compiler_class() )->compile( $artifact )->toArray();
		if ( ! is_array( $compiled ) ) {
			return new WP_Error( 'static_site_importer_invalid_transformer_result', 'Blocks Engine php-transformer returned an invalid result.' );
		}
		$plan = isset( $compiled['source_reports']['wordpress_site_plan'] ) && is_array( $compiled['source_reports']['wordpress_site_plan'] ) ? $compiled['source_reports']['wordpress_site_plan'] : array();
		if ( empty( $plan ) ) {
			$diagnostics = isset( $compiled['source_reports']['wordpress_site_plan_diagnostics'] ) && is_array( $compiled['source_reports']['wordpress_site_plan_diagnostics'] ) ? wp_json_encode( $compiled['source_reports']['wordpress_site_plan_diagnostics'] ) : '';
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce a WordPress site plan.' . ( false !== $diagnostics ? ' ' . $diagnostics : '' ), $compiled );
		}
		$companion_payload = null;
		$gutenberg_gaps    = isset( $compiled['source_reports']['gutenberg_gaps'] ) && is_array( $compiled['source_reports']['gutenberg_gaps'] ) ? $compiled['source_reports']['gutenberg_gaps'] : array();
		if ( array_key_exists( 'companion_plugin_payload', $compiled['source_reports'] ?? array() ) ) {
			$companion_payload = $compiled['source_reports']['companion_plugin_payload'];
			if ( ! is_array( $companion_payload ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_payload_invalid', 'Compiled companion_plugin_payload must be an object.' );
			}
			$companion_payload['site_slug'] = '' !== (string) ( $companion_payload['site_slug'] ?? '' ) ? (string) $companion_payload['site_slug'] : $args['slug'];
			$companion_payload['site_name'] = '' !== (string) ( $companion_payload['site_name'] ?? '' ) ? (string) $companion_payload['site_name'] : $args['name'];
			$companion_validation = Static_Site_Importer_Companion_Plugin::validate_payload( $companion_payload );
			if ( is_wp_error( $companion_validation ) ) {
				return $companion_validation;
			}
		}
		$plan = self::bridge_product_grid_findings_to_runtime_declarations( $plan );
		if ( isset( $args['approved_classic_plan_hash'] ) && is_string( $args['approved_classic_plan_hash'] ) && ! hash_equals( $args['approved_classic_plan_hash'], hash( 'sha256', (string) wp_json_encode( $plan ) ) ) ) {
			return new WP_Error( 'static_site_importer_approved_classic_plan_changed', 'Recompilation did not reproduce the approved canonical classic plan.' );
		}
		if ( Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === $strategy['strategy'] ) {
			$projection = Static_Site_Importer_Classic_Theme_Projection::build( $artifact, $plan );
			if ( is_wp_error( $projection ) ) {
				return $projection;
			}
			$args['classic_theme_projection'] = $projection;
			if ( isset( $args['approved_classic_projection_hash'] ) && is_string( $args['approved_classic_projection_hash'] ) && ! hash_equals( $args['approved_classic_projection_hash'], hash( 'sha256', (string) wp_json_encode( $projection ) ) ) ) {
				return new WP_Error( 'static_site_importer_approved_classic_projection_changed', 'Recompilation did not reproduce the approved classic projection.' );
			}
			$strategy['evidence']['status'] = 'source_artifact_projection';
			$strategy['evidence']['projection_schema'] = $projection['schema'];
		}
		$materialization_plan = isset( $compiled['source_reports']['materialization_plan'] ) && is_array( $compiled['source_reports']['materialization_plan'] ) ? $compiled['source_reports']['materialization_plan'] : array();
		return array(
			'artifact'              => $artifact,
			'args'                  => $args,
			'compiled'              => $compiled,
			'plan'                  => $plan,
			'gutenberg_gaps'        => $gutenberg_gaps,
			'companion_payload'     => $companion_payload,
			'materialization_plan'  => $materialization_plan,
			'theme_materialization' => $strategy['evidence'],
		);
	}

	/** Materialize a previously compiled canonical plan through the existing write path. */
	private static function materialize_compiled_website_artifact( array $artifact, array $args, array $plan, array $gutenberg_gaps, $companion_payload, array $lifecycle, array $theme_materialization ) {

		$theme_dir = trailingslashit( get_theme_root() ) . $args['slug'];
		$report_destinations = array( $theme_dir . '/static-site-importer-manifest.json' );
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$report_destinations = array_merge( $report_destinations, array( $theme_dir . '/import-report.json', $theme_dir . '/import-validation-result.json', $theme_dir . '/finding-packets.json' ) );
		}
		$external_report_destinations = array();
		if ( ! empty( $args['report'] ) ) {
			$external_report_destinations[] = (string) $args['report'];
			$external_report_destinations[] = trailingslashit( dirname( (string) $args['report'] ) ) . 'import-validation-result.json';
			$external_report_destinations[] = trailingslashit( dirname( (string) $args['report'] ) ) . 'finding-packets.json';
			$report_destinations = array_merge( $report_destinations, $external_report_destinations );
		}
		$args['report_destinations'] = $report_destinations;
		$args['external_report_destinations'] = $external_report_destinations;
		$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare( $plan, $args );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			$receipt = isset( $prepared['receipt'] ) && is_array( $prepared['receipt'] ) ? $prepared['receipt'] : array();
			$error   = $receipt['errors'][0] ?? array();
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan destination preflight failed.' ), $receipt );
		}
		$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::admit_prepared( $prepared );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			$receipt = isset( $prepared['receipt'] ) && is_array( $prepared['receipt'] ) ? $prepared['receipt'] : array();
			$error   = $receipt['errors'][0] ?? array();
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan payload admission failed.' ), $receipt );
		}
		$lifecycle = self::with_resolved_runtime_binding_manifests( $lifecycle, $prepared['resolved'] ?? array() );
		$classic = Static_Site_Importer_Theme_Materialization_Strategy::CLASSIC === ( $args['theme_materialization'] ?? null );
		$binding_preflight = $classic ? self::preflight_classic_runtime_entity_bindings( $prepared['args']['classic_theme_projection'], $lifecycle, $args ) : self::preflight_runtime_entity_binding_anchors( $prepared['resolved'] ?? array(), $lifecycle, $args );
		if ( is_wp_error( $binding_preflight ) ) {
			return $binding_preflight;
		}
		$page_ready = ! empty( $args['page_ready_checkpoint'] );
		if ( $page_ready && self::page_ready_requires_final_hydration( $lifecycle, $args ) ) {
			return new WP_Error(
				'static_site_importer_page_ready_runtime_bindings_deferred',
				'Page-ready materialization requires runtime entity bindings and must wait for complete-snapshot hydration.',
				array(
					'status'                => 'deferred',
					'materialization_scope' => 'page_ready',
				)
			);
		}
		$companion_materialization = array(
			'status' => 'skipped',
			'reason' => 'companion_plugin_payload_absent',
		);
		if ( null !== $companion_payload ) {
			if ( $page_ready || ( array_key_exists( 'materialize_dependencies', $args ) && false === (bool) $args['materialize_dependencies'] ) ) {
				$companion_materialization = array(
					'status' => 'skipped',
					'reason' => $page_ready ? 'page_ready_scope' : 'dependency_materialization_disabled',
				);
			} else {
				$dependency                 = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $companion_payload );
				$companion_materialization = Static_Site_Importer_Entity_Materializer_Registry::materialize_companion_dependency( $dependency, ! empty( $args['overwrite'] ) );
				if ( 'failed' === ( $companion_materialization['status'] ?? '' ) ) {
					$error = $companion_materialization['error'] ?? array();
					return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_companion_plugin_materialization_failed' ), (string) ( $error['message'] ?? 'Companion-plugin materialization failed.' ), $companion_materialization );
				}
			}
		}
		$dependencies = $page_ready ? array() : self::materialize_prepared_dependencies( $lifecycle, $args );
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}
		$entity_result = $page_ready ? array(
			'reports' => array(),
			'error'   => null,
		) : self::materialize_prepared_entities( $lifecycle, $args );
		$entities      = $entity_result['reports'];
		if ( null !== $entity_result['error'] ) {
			$error = $entity_result['error'];
			$failure = array(
				'status'            => 'partial',
				'runtime_lifecycle' => $lifecycle,
				'dependencies'      => $dependencies,
				'entities'          => $entities,
			);
			self::append_entity_compensation( $failure, $lifecycle, $entities, 'entity_materialization', (string) $error['code'] );
			return new WP_Error(
				$error['code'],
				$error['message'],
				$failure
			);
		}
		$bindings = $page_ready ? array() : self::runtime_entity_bindings( $lifecycle, $entities );
		if ( is_wp_error( $bindings ) ) {
			$failure = array(
				'status'            => 'partial',
				'runtime_lifecycle' => $lifecycle,
				'dependencies'      => $dependencies,
				'entities'          => $entities,
			);
			self::append_entity_compensation( $failure, $lifecycle, $entities, 'runtime_entity_bindings', (string) $bindings->get_error_code() );
			return new WP_Error(
				$bindings->get_error_code(),
				$bindings->get_error_message(),
				$failure
			);
		}
		$prepared['args']['runtime_entity_bindings']    = $classic ? array() : $bindings;
		if ( $classic ) {
			$classic_bindings = self::classic_runtime_entity_bindings( $lifecycle, $entities );
			if ( is_wp_error( $classic_bindings ) ) {
				$failure = array(
					'status'            => 'partial',
					'runtime_lifecycle' => $lifecycle,
					'dependencies'      => $dependencies,
					'entities'          => $entities,
				);
				self::append_entity_compensation( $failure, $lifecycle, $entities, 'classic_runtime_entity_bindings', (string) $classic_bindings->get_error_code() );
				return new WP_Error( $classic_bindings->get_error_code(), $classic_bindings->get_error_message(), $failure );
			}
			$projection = Static_Site_Importer_Classic_Theme_Projection::apply_runtime_bindings( $prepared['args']['classic_theme_projection'], $classic_bindings );
			if ( is_wp_error( $projection ) ) {
				$failure = array(
					'status'            => 'partial',
					'runtime_lifecycle' => $lifecycle,
					'dependencies'      => $dependencies,
					'entities'          => $entities,
				);
				self::append_entity_compensation( $failure, $lifecycle, $entities, 'classic_runtime_projection', (string) $projection->get_error_code() );
				return new WP_Error( $projection->get_error_code(), $projection->get_error_message(), $failure );
			}
			$prepared['args']['classic_theme_projection'] = $projection;
			$prepared['base_resolved'] = Static_Site_Importer_Classic_Theme_Projection::with_projection_writes( $prepared['base_resolved'], $projection, (string) $prepared['theme']['uri'], (string) ( $prepared['args']['name'] ?? $prepared['theme']['slug'] ) );
			$prepared['base_resolved_hash'] = hash( 'sha256', (string) wp_json_encode( $prepared['base_resolved'], JSON_UNESCAPED_SLASHES ) );
			$prepared['args']['classic_runtime_bindings'] = $classic_bindings;
		}
		$prepared['args']['provider_layout_overlays']   = $page_ready ? array() : self::provider_layout_overlays_from_entity_reports( $entities );
		$prepared['args']['font_materialization']       = $page_ready ? array() : $prepared['args']['font_materialization'];
		$prepared['args']['activate']                   = $page_ready ? false : ! empty( $prepared['args']['activate'] );
		$prepared['args']['defer_materialization_commit'] = true;
		$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $prepared );
		$receipt['completed']['companion_plugin'] = $companion_materialization;
		$receipt['extensions']['gutenberg_gaps'] = self::project_gutenberg_gaps( $gutenberg_gaps, (string) ( $companion_materialization['status'] ?? 'not_materialized' ) );
		$receipt['completed']['runtime_declarations']['dependencies'] = $dependencies;
		$receipt['completed']['runtime_declarations']['entities'] = $entities;
		$receipt['runtime_lifecycle'] = $lifecycle;
		if ( $classic ) {
			$receipt['completed']['runtime_declarations']['classic_html_bindings'] = $prepared['args']['classic_runtime_bindings'] ?? array();
		}
		$receipt['theme_materialization'] = $theme_materialization;
		if ( 'completed' !== $receipt['status'] ) {
			$error = $receipt['errors'][0] ?? array();
			self::append_entity_compensation( $receipt, $lifecycle, $entities, 'wordpress_site_plan_materialization', (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ) );
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan materialization failed.' ), $receipt );
		}
		try {
			return self::public_result_from_wordpress_site_plan_receipt( $receipt, $args, $lifecycle, $dependencies, $entities );
		} catch ( Throwable $error ) {
			$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::rollback_receipt( $receipt, 'static_site_importer_projection_write_failed' );
			$receipt['status'] = 'partial';
			$receipt['errors'][] = array(
				'code'    => 'static_site_importer_projection_write_failed',
				'message' => $error->getMessage(),
			);
			$stage = 'report_persistence' === (string) ( $args['inject_materialization_failure'] ?? '' ) ? 'report_persistence' : 'public_projection';
			self::append_entity_compensation( $receipt, $lifecycle, $entities, $stage, 'static_site_importer_projection_write_failed' );
			return new WP_Error( 'static_site_importer_projection_write_failed', 'Website materialization completed partially because a public projection could not be written.', $receipt );
		}
	}

	/** Compensate completed entities in reverse order and retain bounded residual evidence. */
	private static function rollback_materialized_entities( array $lifecycle, array $reports ): array {
		$compensation = array(
			'schema'    => 'static-site-importer/entity-compensation-receipt/v1',
			'status'    => 'rolled_back',
			'entities'  => array(),
			'errors'    => array(),
			'truncated' => false,
		);
		foreach ( array_reverse( array_keys( $lifecycle['entities'] ?? array() ) ) as $id ) {
			$prepared = $lifecycle['entities'][ $id ];
			if ( ! is_array( $prepared ) || ! is_array( $prepared['adapter'] ?? null ) || ! is_array( $reports[ $id ] ?? null ) ) {
				continue; }
			$adapter = $prepared['adapter'];
			try {
				$result = Static_Site_Importer_Entity_Materializer_Registry::rollback( $adapter, $reports[ $id ] );
			} catch ( Throwable $error ) {
				$result = new WP_Error( 'static_site_importer_entity_rollback_exception', $error->getMessage() );
			}
			$entry = array(
				'entity_id' => (string) $id,
				'adapter'   => (string) ( $adapter['provider'] ?? '' ),
			);
			if ( is_wp_error( $result ) ) {
				$entry['status'] = 'failed';
				$entry['errors'] = array(
					array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				);
			} elseif ( is_array( $result ) ) {
				$entry['status'] = (string) ( $result['status'] ?? 'failed' );
				$entry['rollback'] = self::bounded_entity_rollback_result( $result );
				$entry['residual_state'] = self::entity_rollback_residual_state( $entry['rollback'] );
			} else {
				$entry['status'] = 'failed';
				$entry['errors'] = array(
					array(
						'code'    => 'static_site_importer_entity_rollback_invalid',
						'message' => 'The entity rollback callback did not return a receipt.',
					),
				);
			}
			if ( ! in_array( $entry['status'], array( 'rolled_back', 'skipped', 'not_requested' ), true ) ) {
				$compensation['status'] = 'partial';
				$compensation['errors'][] = array(
					'entity_id' => $entry['entity_id'],
					'adapter'   => $entry['adapter'],
					'status'    => $entry['status'],
				);
			}
			if ( count( $compensation['entities'] ) < 32 ) {
				$compensation['entities'][] = $entry;
			} else {
				$compensation['truncated'] = true; }
		}
		$compensation['errors'] = array_slice( $compensation['errors'], 0, 32 );
		return $compensation;
	}

	/** Attach failure context and compensation diagnostics to public and internal receipts. */
	private static function append_entity_compensation( array &$result, array $lifecycle, array $reports, string $stage, string $code ): void {
		$compensation = self::rollback_materialized_entities( $lifecycle, $reports );
		$result['failure_context'] = array(
			'stage' => $stage,
			'code'  => $code,
		);
		$result['entity_compensation'] = $compensation;
		$result['diagnostics'] = is_array( $result['diagnostics'] ?? null ) ? $result['diagnostics'] : array();
		$result['diagnostics'][] = array(
			'reason_code' => $code,
			'stage'       => $stage,
		);
		foreach ( $compensation['entities'] as $entry ) {
			if ( 'rolled_back' !== ( $entry['status'] ?? '' ) ) {
				$result['diagnostics'][] = array(
					'reason_code' => 'entity_compensation_' . (string) $entry['status'],
					'stage'       => $stage,
					'entity_id'   => $entry['entity_id'],
					'adapter'     => $entry['adapter'],
				);
			}
		}
		if ( isset( $result['completed']['runtime_declarations'] ) && is_array( $result['completed']['runtime_declarations'] ) ) {
			$result['completed']['runtime_declarations']['entity_compensation'] = $compensation;
		}
		if ( 'partial' === $compensation['status'] && 'completed' === ( $result['status'] ?? null ) ) {
			$result['status'] = 'partial'; }
	}

	/** Keep provider rollback diagnostics useful without exposing unbounded provider receipts. */
	private static function bounded_entity_rollback_result( array $result ): array {
		$bounded = array();
		foreach ( array( 'status', 'reason', 'product_cleanup_failures', 'term_cleanup_failures', 'form_cleanup_failures' ) as $key ) {
			if ( ! array_key_exists( $key, $result ) ) {
				continue; }
			$bounded[ $key ] = is_array( $result[ $key ] ) ? array_slice( $result[ $key ], 0, 32 ) : $result[ $key ];
		}
		return $bounded;
	}

	/** Name provider objects that could remain after a partial compensation. */
	private static function entity_rollback_residual_state( array $rollback ): array {
		$residual = array();
		if ( ! empty( $rollback['product_cleanup_failures'] ) ) {
			$residual['products'] = $rollback['product_cleanup_failures']; }
		if ( ! empty( $rollback['term_cleanup_failures'] ) ) {
			$residual['terms'] = $rollback['term_cleanup_failures']; }
		if ( ! empty( $rollback['form_cleanup_failures'] ) ) {
			$residual['forms'] = $rollback['form_cleanup_failures']; }
		return $residual;
	}

	/** Collect only structured compiler overlays emitted by successful form seeding. */
	private static function provider_layout_overlays_from_entity_reports( array $reports ): array {
		$overlays = array();
		foreach ( $reports as $report ) {
			foreach ( is_array( $report['forms'] ?? null ) ? $report['forms'] : array() as $form ) {
				if ( is_array( $form['provider_layout_overlay_css'] ?? null ) && ! empty( $form['provider_layout_overlay_css'] ) ) {
					$overlays[] = $form['provider_layout_overlay_css'];
				}
			}
		}
		return $overlays;
	}

	/** Project compiler gap rows into stable materialization diagnostics. */
	private static function project_gutenberg_gaps( array $gaps, string $materialization_status = 'not_materialized' ): array {
		$projected = array();
		foreach ( $gaps as $index => $gap ) {
			if ( ! is_array( $gap ) ) {
				continue;
			}
			$row = array(
				'id'                     => isset( $gap['id'] ) && is_scalar( $gap['id'] ) ? (string) $gap['id'] : 'gutenberg-gap-' . ( $index + 1 ),
				'type'                   => 'gutenberg_gap',
				'code'                   => 'gutenberg_gap',
				'materialization_status' => $materialization_status,
			);
			foreach ( array( 'block_name', 'source_path', 'path', 'message', 'reason_code' ) as $field ) {
				if ( isset( $gap[ $field ] ) && is_scalar( $gap[ $field ] ) ) {
					$row[ $field ] = (string) $gap[ $field ];
				}
			}
			if ( isset( $gap['references'] ) && is_array( $gap['references'] ) ) {
				$row['references'] = $gap['references'];
			}
			$projected[] = $row;
		}

		return $projected;
	}

	/**
	 * Declare detected Blocks Engine product grids for the canonical v2 lifecycle.
	 *
	 * Product-grid findings carry product data but no canonical block replacement
	 * anchors, so this bridge seeds only the explicit commerce entities. Provider
	 * bindings remain limited to declarations that include their own exact anchors.
	 *
	 * @param array<string,mixed> $plan Compiled WordPress site plan.
	 * @return array<string,mixed>
	 */
	private static function bridge_product_grid_findings_to_runtime_declarations( array $plan ): array {
		$diagnostics = isset( $plan['diagnostics'] ) && is_array( $plan['diagnostics'] ) ? $plan['diagnostics'] : array();
		$products    = Static_Site_Importer_Report_Diagnostics::product_grid_manifest_products( $diagnostics );
		if ( empty( $products ) ) {
			return $plan;
		}

		$adapter    = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
		$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic(
			$adapter,
			array(
				'schema_version' => 1,
				'products'       => $products,
			)
		);
		if ( ! empty( $validation['errors'] ) || empty( $validation['products'] ) ) {
			return $plan;
		}
		$source_path = (string) ( $plan['source']['entry_path'] ?? '' );
		$products = self::normalize_classic_product_grid_entities( $validation['products'], $source_path );
		if ( is_wp_error( $products ) ) {
			return $plan;
		}

		$declarations = isset( $plan['runtime_declarations'] ) && is_array( $plan['runtime_declarations'] ) ? $plan['runtime_declarations'] : array();
		foreach ( $declarations as $declaration ) {
			if ( is_array( $declaration ) && 'entity_collection' === ( $declaration['kind'] ?? null ) && 'products' === ( $declaration['type'] ?? null ) ) {
				return $plan;
			}
		}

		$identity    = hash( 'sha256', "static-site-importer/product-grid-bridge/v1\n" . wp_json_encode( $products ) );
		$declarations[] = array(
			'kind'                    => 'dependency',
			'capability'              => 'shop',
			'source_path'             => $source_path,
			'required_for'            => array( 'entity_collection:products' ),
			'reconciliation_identity' => hash( 'sha256', $identity . "\ndependency" ),
		);
		$declarations[] = array(
			'kind'                    => 'entity_collection',
			'type'                    => 'products',
			'source_path'             => $source_path,
			'payload'                 => array(
				'schema'   => 'generic/products/v1',
				'entities' => $products,
			),
			'reconciliation_identity' => hash( 'sha256', $identity . "\nentities" ),
		);
		$plan['runtime_declarations'] = $declarations;
		return $plan;
	}

	/** Normalize bridge report source_selectors into one exact leaf source identity. */
	private static function normalize_classic_product_grid_entities( array $products, string $default_source ) {
		$normalized = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				return new WP_Error( 'static_site_importer_classic_product_shape_invalid', 'Product-grid bridge contains an invalid product.' ); }
			$selectors = is_array( $product['source_selectors'] ?? null ) ? array_values( array_unique( array_filter( array_map( 'trim', $product['source_selectors'] ) ) ) ) : array();
			$leaves = array_values( array_filter( $selectors, static fn( string $selector ): bool => str_contains( $selector, '#' ) || str_contains( $selector, ':nth-child(' ) || preg_match( '/(?:^|\s)(?:li|article|\.product-card)(?:\b|[.#:])/', $selector ) ) );
			if ( 1 !== count( $leaves ) ) {
				return new WP_Error( 'static_site_importer_classic_product_selector_ambiguous', 'Product-grid bridge requires exactly one product leaf selector per product.' ); }
			$product['selector'] = $leaves[0];
			$product['source_path'] = isset( $product['source_path'] ) && is_string( $product['source_path'] ) && '' !== $product['source_path'] ? $product['source_path'] : $default_source;
			if ( '' === $product['source_path'] ) {
				return new WP_Error( 'static_site_importer_classic_product_source_missing', 'Product-grid bridge requires a source path.' ); }
			$normalized[] = $product;
		}
		return $normalized;
	}

	/**
	 * Project canonical materialization facts into the established public result envelope.
	 *
	 * @param array<string,mixed> $receipt  Materialization receipt.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function public_result_from_wordpress_site_plan_receipt( array $receipt, array $args, array $lifecycle = array(), array $dependencies = array(), array $entities = array() ): array {
		$plan        = $receipt['plan'];
		$theme        = $receipt['theme'];
		$diagnostics  = self::diagnostics_after_completed_entity_bindings( isset( $plan['diagnostics'] ) && is_array( $plan['diagnostics'] ) ? $plan['diagnostics'] : array(), $receipt );
		if ( isset( $args['compiler_options'] ) && is_array( $args['compiler_options'] ) && ! empty( $args['compiler_options'] ) ) {
			$diagnostics[] = array(
				'code'    => 'static_site_importer_compiler_options_ignored',
				'type'    => 'static-site-importer',
				'message' => 'The compiler_options import argument is accepted for backward compatibility but is no longer honored because the Blocks Engine compiler ignores it at the artifact compile boundary.',
			);
		}
		$quality      = isset( $plan['quality'] ) && is_array( $plan['quality'] ) ? $plan['quality'] : array();
		$entity_lifecycle = array(
			'status'       => $lifecycle['status'] ?? 'not_requested',
			'entities'     => $entities,
			'dependencies' => $dependencies,
		);
		$diagnostics = array_merge( $diagnostics, $lifecycle['diagnostics'] ?? array() );
		$gutenberg_gaps = isset( $receipt['extensions']['gutenberg_gaps'] ) && is_array( $receipt['extensions']['gutenberg_gaps'] ) ? $receipt['extensions']['gutenberg_gaps'] : array();
		$diagnostics = array_merge( $diagnostics, $gutenberg_gaps );
		$report       = array(
			'schema'                           => 'static-site-importer/import-report/v1',
			'import_run_id'                    => self::import_run_id( $args ),
			'blocks_engine'                    => array(
				'transformer'         => self::transformer_provenance(),
				'wordpress_site_plan' => $plan,
				'gutenberg_gaps'      => $gutenberg_gaps,
			),
			'quality'                          => $quality,
			'client_script_policy'             => $args['client_script_policy_report'] ?? array(),
			'theme_materialization'            => $receipt['theme_materialization'] ?? array(),
			'diagnostics'                      => $diagnostics,
			'entity_lifecycle'                 => $entity_lifecycle,
			'companion_plugin_materialization' => $receipt['completed']['companion_plugin'] ?? array(
				'status' => 'skipped',
				'reason' => 'companion_plugin_payload_absent',
			),
			'generated_theme'                  => array(
				'wordpress_site_plan' => $plan,
				'document_metadata'   => self::document_metadata_from_plan_receipt( $plan ),
				'template_parts'      => array_map(
					static fn( array $part ): array => array(
						'path'    => 'parts/' . $part['slug'] . '.html',
						'content' => $part['resolved_block_markup'],
					),
					$plan['template_parts']
				),
				'block_documents'     => array_map(
					static function ( array $page ) use ( $receipt ): array {
						$materialized = $receipt['completed']['materialized_pages'][ $page['source_path'] ]['block_markup'] ?? $page['resolved_block_markup'] ?? '';
						$document = array(
							'path'    => 'posts/page-' . ( ! empty( $page['entrypoint'] ) ? 'home' : $page['slug'] ) . '.post_content',
							'content' => $materialized,
						);
						if ( isset( $page['core_html_block_count'] ) ) {
							$document['core_html_block_count'] = $page['core_html_block_count'];
						}
						return $document;
					},
					$plan['pages']
				),
			),
			'source_documents'                 => array(
				'source'                       => 'blocks_engine',
				'blocks_engine_document_count' => count( $plan['pages'] ),
				'blocks_engine_documents'      => array_map(
					static fn( array $page ): array => array(
						'source_path' => $page['source_path'],
						'slug'        => ! empty( $page['entrypoint'] ) ? 'home' : $page['slug'],
						'permalink'   => ! empty( $page['entrypoint'] ) ? '/' : '/' . $page['slug'] . '/',
					),
					$plan['pages']
				),
				'counts_by_format'             => array(
					'html'     => count( $plan['pages'] ),
					'markdown' => 0,
					'mdx'      => 0,
				),
			),
		);
		$report['source_artifact'] = array( 'hash' => (string) ( $args['artifact_hash'] ?? $plan['source']['source_hash'] ) );
		$report['materialization_receipt'] = $receipt;
		$quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $report, $args );
		$artifact = array_merge(
			isset( $args['source_artifact_reference'] ) && is_array( $args['source_artifact_reference'] ) ? $args['source_artifact_reference'] : array(),
			array_filter(
				array(
					'schema'      => $plan['source']['schema'] ?? null,
					'source_hash' => $plan['source']['source_hash'] ?? null,
					'entry_path'  => $plan['source']['entry_path'] ?? null,
				)
			)
		);
		$artifact['hash'] = (string) ( $args['artifact_hash'] ?? $artifact['hash'] ?? $plan['source']['source_hash'] );
		// The resolved write plan is authoritative, including files retained from a
		// previous batch. Applied receipts omit intentionally preserved bootstrap
		// and scaffold writes, which must remain owned rather than becoming stale.
		$desired_files = array_map(
			static fn( array $write ): array => array(
				'path' => $write['target_path'],
				'kind' => $write['kind'],
			),
			array_values( array_filter( $receipt['plan']['writes'] ?? array(), static fn( $write ): bool => is_array( $write ) && is_scalar( $write['target_path'] ?? null ) && is_scalar( $write['kind'] ?? null ) ) )
		);
		$desired_file_paths = array_fill_keys( array_column( $desired_files, 'path' ), true );
		foreach ( $receipt['completed']['files'] ?? array() as $file ) {
			$path = is_array( $file ) && is_scalar( $file['target_path'] ?? null ) ? (string) $file['target_path'] : '';
			if ( '' === $path || isset( $desired_file_paths[ $path ] ) ) {
				continue;
			}
			$desired_file_paths[ $path ] = true;
			$desired_files[] = array(
				'path' => $path,
				'kind' => is_scalar( $file['kind'] ?? null ) ? (string) $file['kind'] : 'materialized_theme_file',
			);
		}
		$desired_assets = array_map(
			static fn( array $asset ): array => array(
				'source_path' => $asset['source_path'],
				'theme_path'  => $asset['target_path'],
			),
			$plan['assets']
		);
		foreach ( $receipt['completed']['font_materialization']['files'] ?? array() as $file ) {
			$path = is_array( $file ) && is_scalar( $file['target_path'] ?? null ) ? (string) $file['target_path'] : '';
			if ( '' === $path || isset( $desired_file_paths[ $path ] ) ) {
				continue;
			}
			$desired_file_paths[ $path ] = true;
			$desired_files[] = array(
				'path' => $path,
				'kind' => 'font_materialization',
			);
			$desired_assets[] = array(
				'source_path' => (string) ( $file['source_path'] ?? 'theme.font_materialization' ),
				'theme_path'  => $path,
			);
		}
		$manifest = array(
			'schema'          => 'static-site-importer/source-of-truth-manifest/v1',
			'version'         => 1,
			'import_run_id'   => $report['import_run_id'],
			'artifact'        => array_merge( $artifact, array( 'provenance' => $plan['source']['provenance'] ) ),
			'manifest_path'   => 'static-site-importer-manifest.json',
			'generated_theme' => array(
				'slug' => $theme['slug'],
				'dir'  => $theme['dir'],
			),
			'desired'         => array(
				'pages'  => array(),
				'files'  => array_merge(
					$desired_files,
					array(
						array(
							'path' => 'static-site-importer-manifest.json',
							'kind' => 'ssi_manifest',
						),
					)
				),
				'assets' => $desired_assets,
			),
		);
		foreach ( $plan['pages'] as $page ) {
			$source_path = $page['source_path'];
			$id = (int) ( $receipt['completed']['pages'][ $source_path ] ?? 0 );
			$match = null;
			foreach ( $receipt['existing_matches']['pages'] ?? array() as $candidate ) {
				if ( ( $candidate['source_path'] ?? '' ) === $source_path ) {
					$match = $candidate;
					break;
				}
			}
			$manifest['desired']['pages'][] = array(
				'source_path'             => $source_path,
				'materialized_post_id'    => $id,
				'reconciliation_identity' => $page['reconciliation_identity'],
				'content_hash'            => $receipt['completed']['materialized_pages'][ $source_path ]['content_hash'] ?? $page['content_hash'],
				'route'                   => $page['route']['path'],
				'permalink'               => $match['permalink'] ?? $page['route']['path'],
				'slug'                    => $page['slug'],
				'post_type'               => $page['post_type'],
				'protected'               => ! empty( $match['protected'] ),
				'provenance_meta_key'     => ! empty( $match['protected'] ) ? '' : '_static_site_importer_provenance',
			);
		}
		if ( ! empty( $args['batch_import'] ) ) {
			$previous = self::read_source_of_truth_manifest( $theme['dir'] . '/static-site-importer-manifest.json' );
			if ( is_array( $previous['desired'] ?? null ) ) {
				foreach ( array( 'pages', 'files', 'assets' ) as $kind ) {
					$keys = array();
					foreach ( $manifest['desired'][ $kind ] as $item ) {
						$keys[ (string) ( $item['source_path'] ?? $item['path'] ?? $item['theme_path'] ?? '' ) ] = true;
					}
					foreach ( $previous['desired'][ $kind ] ?? array() as $item ) {
						$key = (string) ( $item['source_path'] ?? $item['path'] ?? $item['theme_path'] ?? '' );
						if ( '' !== $key && ! isset( $keys[ $key ] ) ) {
							$manifest['desired'][ $kind ][] = $item;
						}
					}
				}
			}
		}
		$manifest['existing_matches'] = $receipt['existing_matches'] ?? array( 'pages' => array() );
		$cleanup = self::cleanup_stale_generated_theme_files( $theme['dir'], $manifest, $args, $receipt );
		if ( is_wp_error( $cleanup ) ) {
			throw new RuntimeException( $cleanup->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The internal cleanup error is propagated as an exception message.
		}
		$manifest['cleanup'] = $cleanup;
		$report['source_of_truth'] = $manifest;
		$visual_parity = array(
			'schema'    => 'static-site-importer/visual-parity-artifacts/v1',
			'status'    => 'pending',
			'owner'     => 'codebox_runtime',
			'artifacts' => array(
				'import_report'     => array(
					'status' => 'captured',
					'ref'    => array( 'artifact_name' => 'import-report.json' ),
				),
				'source_screenshot' => array( 'status' => 'pending' ),
				'visual_diff'       => array( 'capture_state' => 'not_captured' ),
			),
		);
		$report['visual_parity_artifacts'] = $visual_parity;
		$validation = array(
			'schema'                  => 'blocks-engine/import-validation-result/v1',
			'artifact_type'           => 'ImportValidationResult',
			'status'                  => ! empty( $quality['pass'] ) ? 'passed' : 'failed',
			'diagnostics'             => $diagnostics,
			'quality'                 => $quality,
			'visual_parity_artifacts' => $visual_parity,
		);
		$findings   = array(
			'schema'        => 'blocks-engine/finding-packets/v1',
			'artifact_type' => 'FindingPacketSet',
			'findings'      => $diagnostics,
		);
		$theme_dir  = $theme['dir'];
		$manifest_path = $theme_dir . '/static-site-importer-manifest.json';
		self::write_plan_projection( $manifest_path, $manifest, $receipt );
		$report_path = '';
		$validation_path = '';
		$findings_path = '';
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$report_path = $theme_dir . '/import-report.json';
			$validation_path = $theme_dir . '/import-validation-result.json';
			$findings_path = $theme_dir . '/finding-packets.json';
			self::write_plan_projection( $report_path, $report, $receipt );
			self::write_plan_projection( $validation_path, $validation, $receipt );
			self::write_plan_projection( $findings_path, $findings, $receipt );
		}
		$external_report_path = '';
		$external_validation_result_path = '';
		$external_finding_packets_path = '';
		if ( '' !== trim( (string) ( $args['report'] ?? '' ) ) ) {
			$external_report_path = (string) $args['report'];
			$external_dir = dirname( $external_report_path );
			$external_validation_result_path = trailingslashit( $external_dir ) . 'import-validation-result.json';
			$external_finding_packets_path = trailingslashit( $external_dir ) . 'finding-packets.json';
			foreach ( array( $external_report_path, $external_validation_result_path, $external_finding_packets_path ) as $path ) {
				if ( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $path ) ) {
					throw new RuntimeException( 'External report destination changed after preflight.' );
				}
			}
			self::write_plan_projection( $external_report_path, $report, $receipt );
			self::write_plan_projection( $external_validation_result_path, $validation, $receipt );
			self::write_plan_projection( $external_finding_packets_path, $findings, $receipt );
		}
		if ( 'report_persistence' === (string) ( $args['inject_materialization_failure'] ?? '' ) ) {
			throw new RuntimeException( 'Injected report persistence failure.' );
		}
		Static_Site_Importer_WordPress_Site_Plan_Materializer::commit_receipt( $receipt );
		return array(
			'theme_slug'                      => $theme['slug'],
			'theme_name'                      => isset( $args['name'] ) ? (string) $args['name'] : $theme['slug'],
			'theme_dir'                       => $theme['dir'],
			'report_path'                     => $report_path,
			'validation_result_path'          => $validation_path,
			'finding_packets_path'            => $findings_path,
			'external_report_path'            => $external_report_path,
			'external_validation_result_path' => $external_validation_result_path,
			'external_finding_packets_path'   => $external_finding_packets_path,
			'manifest_path'                   => $manifest_path,
			'pages'                           => $receipt['completed']['pages'],
			'import_report'                   => $report,
			'import_report_summary'           => array(
				'status'           => $receipt['status'],
				'diagnostic_count' => count( $diagnostics ),
			),
			'import_validation_result'        => $validation,
			'finding_packets'                 => $findings,
			'quality'                         => $quality,
			'source_of_truth'                 => $manifest,
			'progress_events'                 => array(
				array(
					'schema'   => 'wp-codebox/live-progress-event/v1',
					'phase'    => 'ssi.materialization.completed',
					'progress' => array( 'percent' => 100 ),
				),
				array(
					'schema'   => 'wp-codebox/live-progress-event/v1',
					'phase'    => 'ssi.reporting.completed',
					'progress' => array( 'percent' => 100 ),
				),
				array(
					'schema'   => 'wp-codebox/live-progress-event/v1',
					'phase'    => 'ssi.saved.completed',
					'progress' => array( 'percent' => 100 ),
				),
			),
			'materialization_receipt'         => $receipt,
		);
	}

	/** @return array<string,mixed> */
	private static function read_source_of_truth_manifest( string $path ): array {
		if ( ! is_file( $path ) ) {
			return array();
		}
		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the prior importer-owned source-of-truth manifest for a resumable batch.
		return is_array( $manifest ) && 'static-site-importer/source-of-truth-manifest/v1' === ( $manifest['schema'] ?? '' ) ? $manifest : array();
	}

	/** Retain source diagnostics unless a persisted provider replacement explicitly covers them. */
	private static function diagnostics_after_completed_entity_bindings( array $diagnostics, array $receipt ): array {
		$superseded_runtime_selectors = array();
		foreach ( $receipt['completed']['runtime_declarations']['entity_bindings'] ?? array() as $binding ) {
			if ( ! is_array( $binding ) || 'completed' !== ( $binding['status'] ?? null ) ) {
				continue;
			}
			foreach ( $binding['superseded_runtime_selectors'] ?? array() as $selector ) {
				if ( is_string( $selector ) ) {
					$superseded_runtime_selectors[ (string) ( $binding['source_path'] ?? '' ) . "\n" . $selector ] = true;
				}
			}
		}
		return array_values( array_filter( $diagnostics, static fn( mixed $diagnostic ): bool => ! is_array( $diagnostic ) || 'preserved_runtime_island' !== ( $diagnostic['code'] ?? null ) || ! isset( $superseded_runtime_selectors[ (string) ( $diagnostic['source_path'] ?? '' ) . "\n" . (string) ( $diagnostic['selector'] ?? '' ) ] ) ) );
	}

	/**
	 * Report the installed Blocks Engine compiler identity without projecting its result.
	 *
	 * @return array{package:string,version:string,reference:string}
	 */
	private static function transformer_provenance(): array {
		$package   = 'automattic/blocks-engine-php-transformer';
		$version   = '';
		$reference = '';
		$class     = '\\Composer\\InstalledVersions';

		if ( class_exists( $class ) && $class::isInstalled( $package ) ) {
			try {
				$pretty_version = $class::getPrettyVersion( $package );
				$version        = (string) ( '' !== $pretty_version ? $pretty_version : $version );
				if ( method_exists( $class, 'getReference' ) ) {
					$reference_value = $class::getReference( $package );
					$reference       = (string) ( '' !== $reference_value ? $reference_value : $reference );
				}
			} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Missing Composer metadata stays absent so downstream evidence stays incomplete.
			}
		}

		return array(
			'package'   => $package,
			'version'   => $version,
			'reference' => $reference,
		);
	}

	/**
	 * Normalize and validate every SSI-owned runtime declaration before WordPress writes.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function prepare_wordpress_site_plan_lifecycle( array $plan, array $args ) {
		$lifecycle = array(
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
			$key = (string) ( $declaration['reconciliation_identity'] ?? '' );
			if ( 'asset_publication' === $kind ) {
				continue;
			}
			$name = (string) ( $declaration[ 'entity_collection' === $kind ? 'type' : 'capability' ] ?? '' );
			$capability = self::runtime_declaration_capability( $kind, $name );
			$required = self::runtime_declaration_is_required( $declaration, $declarations );
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
			$adapter = '' === $adapter_key ? Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( $capability ) : Static_Site_Importer_Entity_Materializer_Registry::adapter( $adapter_key );
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
			if ( 'entity_collection' === $kind ) {
				$entities = isset( $declaration['payload']['entities'] ) && is_array( $declaration['payload']['entities'] ) ? $declaration['payload']['entities'] : array();
				if ( 'form' === $capability ) {
					$entities = array_map( static fn( $entity ) => is_array( $entity ) ? Static_Site_Importer_Report_Diagnostics::apply_form_binding_presentation( $entity ) : $entity, $entities );
				}
				$manifest = 'shop' === $capability ? array(
					'schema_version' => 1,
					'products'       => $entities,
				) : array( 'forms' => $entities );
				$validation = 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ? array( 'errors' => array() ) : Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, $manifest );
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
				$normalized_manifest = 'shop' === $capability ? array(
					'schema_version' => 1,
					'products'       => $validation['products'] ?? array(),
				) : array( 'forms' => $validation['forms'] ?? array() );
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
		}
		if ( isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) && ! empty( $args['products_manifest'] ) ) {
			$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
			$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, $args['products_manifest'] );
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
			$lifecycle['entities']['caller_override'] = array(
				'adapter'     => $adapter,
				'manifest'    => $args['products_manifest'],
				'declaration' => array(
					'reconciliation_identity' => 'caller_override',
					'kind'                    => 'entity_collection',
				),
			);
			$lifecycle['status'] = 'caller_override';
		} elseif ( ! empty( $lifecycle['dependencies'] ) || ! empty( $lifecycle['entities'] ) ) {
			$lifecycle['status'] = 'runtime_declarations';
		}
		return $lifecycle;
	}

	/**
	 * Project provider binding anchors through the same resolver output that writes pages.
	 *
	 * Canonical declarations remain in the lifecycle for audit and dependency planning.
	 * Only their destination-dependent binding markup is replaced when the resolver
	 * provides a declaration with the same reconciliation identity.
	 *
	 * @param array<string,mixed> $lifecycle Prepared canonical lifecycle.
	 * @param array<string,mixed> $resolved Resolved WordPress site plan.
	 * @return array<string,mixed>
	 */
	private static function with_resolved_runtime_binding_manifests( array $lifecycle, array $resolved ): array {
		$declarations = isset( $resolved['runtime_declarations'] ) && is_array( $resolved['runtime_declarations'] ) ? $resolved['runtime_declarations'] : array();
		if ( empty( $declarations ) ) {
			return $lifecycle;
		}

		$resolved_entities = array();
		foreach ( $declarations as $declaration ) {
			$id = is_array( $declaration ) ? (string) ( $declaration['reconciliation_identity'] ?? '' ) : '';
			if ( '' !== $id && 'entity_collection' === ( $declaration['kind'] ?? '' ) && isset( $declaration['payload']['entities'] ) && is_array( $declaration['payload']['entities'] ) ) {
				$resolved_entities[ $id ] = $declaration['payload']['entities'];
			}
		}

		foreach ( $lifecycle['entities'] as $id => &$prepared ) {
			$entities = $resolved_entities[ $id ] ?? null;
			if ( ! is_array( $entities ) || ! isset( $prepared['manifest'] ) || ! is_array( $prepared['manifest'] ) ) {
				continue;
			}
			$key = isset( $prepared['manifest']['products'] ) ? 'products' : 'forms';
			$canonical_entities = isset( $prepared['manifest'][ $key ] ) && is_array( $prepared['manifest'][ $key ] ) ? $prepared['manifest'][ $key ] : array();
			$resolved_by_key = array();
			foreach ( $entities as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' );
				if ( '' !== $entity_key ) {
					$resolved_by_key[ $entity_key ] = $entity;
				}
			}
			foreach ( $canonical_entities as $index => $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' );
				if ( isset( $resolved_by_key[ $entity_key ]['bindings'] ) && is_array( $resolved_by_key[ $entity_key ]['bindings'] ) ) {
					$prepared['manifest'][ $key ][ $index ]['bindings'] = $resolved_by_key[ $entity_key ]['bindings'];
				}
			}
		}
		unset( $prepared );

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
		if ( 'entity_collection' === $kind && in_array( $name, array( 'form', 'forms' ), true ) ) {
			return 'form';
		}
		return '';
	}

	/**
	 * Runtime bindings change page markup with provider-owned identities, so the
	 * page-ready checkpoint cannot safely materialize their static fallback.
	 */
	private static function page_ready_requires_final_hydration( array $lifecycle, array $args ): bool {
		foreach ( $lifecycle['entities'] as $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			$manifest = isset( $prepared['manifest'] ) && is_array( $prepared['manifest'] ) ? $prepared['manifest'] : array();
			$entities = isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : ( isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : array() );
			foreach ( $entities as $entity ) {
				if ( is_array( $entity ) && ! empty( $entity['bindings'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function materialize_prepared_dependencies( array $lifecycle, array $args ) {
		$reports = array();
		foreach ( $lifecycle['dependencies'] as $id => $prepared ) {
			$adapter = $prepared['adapter'];
			$waived = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? '' ) ] );
			if ( $waived ) {
				$reports[ $id ] = array(
					'status'   => 'waived',
					'provider' => $adapter['provider'] ?? '',
				);
				continue;
			}
			if ( empty( $args['materialize_dependencies'] ) && ! Static_Site_Importer_Entity_Materializer_Registry::dependencies_available( $adapter ) && ! empty( $prepared['required'] ) ) {
				return new WP_Error(
					'static_site_importer_required_runtime_dependency_missing',
					'A required runtime dependency is unavailable and dependency materialization is disabled.',
					array(
						'status'         => 'rejected',
						'declaration_id' => $id,
					)
				);
			}
			$reports[ $id ] = ! empty( $args['materialize_dependencies'] ) ? Static_Site_Importer_Entity_Materializer_Registry::materialize_plugin_dependencies( $adapter, ! empty( $args['overwrite'] ) ) : array( 'status' => 'available' );
			foreach ( $reports[ $id ] as $plugin_report ) {
				if ( is_array( $plugin_report ) && 'failed' === ( $plugin_report['status'] ?? '' ) ) {
					return new WP_Error(
						'static_site_importer_required_runtime_dependency_failed',
						'SSI could not install or activate a required runtime dependency.',
						array(
							'status'         => 'partial',
							'declaration_id' => $id,
							'dependency'     => $plugin_report,
						)
					);
				}
			}
			if ( 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
				continue;
			}
			if ( ! Static_Site_Importer_Entity_Materializer_Registry::dependencies_available( $adapter ) && ! empty( $prepared['required'] ) ) {
				return new WP_Error(
					'static_site_importer_required_runtime_dependency_missing',
					'SSI could not prepare a required runtime dependency.',
					array(
						'status'                    => 'partial',
						'completed_declaration_ids' => array_keys( $reports ),
						'dependency_reports'        => $reports,
					)
				);
			}
		}
		return $reports;
	}

	/** @return array{reports:array<string,mixed>,error:?array{code:string,message:string}} */
	private static function materialize_prepared_entities( array $lifecycle, array $args ): array {
		$reports = array();
		$required = array_filter( $lifecycle['entities'], static fn( array $prepared ): bool => ! empty( $prepared['required'] ) );
		if ( empty( $args['seed_entities'] ) && empty( $required ) ) {
			return array(
				'reports' => $reports,
				'error'   => null,
			);
		}
		foreach ( $lifecycle['entities'] as $id => $prepared ) {
			$adapter = $prepared['adapter'];
			if ( ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? '' ) ] ) ) {
				$reports[ $id ] = array(
					'status'   => 'waived',
					'provider' => $adapter['provider'] ?? '',
				);
				continue;
			}
			$report = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $prepared['manifest'] );
			if ( is_wp_error( $report ) ) {
				$reports[ $id ] = array(
					'status' => 'error',
					'reason' => $report->get_error_code(),
				);
				return array(
					'reports' => $reports,
					'error'   => array(
						'code'    => (string) $report->get_error_code(),
						'message' => $report->get_error_message(),
					),
				);
			}
			$reports[ $id ] = $report;
			$counts = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
			$expected = count( isset( $prepared['manifest']['products'] ) && is_array( $prepared['manifest']['products'] ) ? $prepared['manifest']['products'] : ( $prepared['manifest']['forms'] ?? array() ) );
			$completed = array_sum( array_map( 'intval', array_intersect_key( $counts, array_flip( array( 'created', 'updated', 'mapped', 'skipped' ) ) ) ) );
			if ( in_array( $report['status'] ?? '', array( 'failed', 'error' ), true ) || ! empty( $counts['failed'] ) || ! empty( $counts['error'] ) || ( ! empty( $prepared['required'] ) && $completed < $expected ) ) {
				$code = isset( $report['code'] ) && is_scalar( $report['code'] ) ? (string) $report['code'] : 'static_site_importer_entity_materialization_failed';
				$message = isset( $report['error'] ) && is_scalar( $report['error'] ) ? (string) $report['error'] : ( isset( $report['reason'] ) && is_scalar( $report['reason'] ) && '' !== (string) $report['reason'] ? (string) $report['reason'] : 'Runtime entity materialization failed for declaration: ' . $id . '.' );
				return array(
					'reports' => $reports,
					'error'   => array(
						'code'    => $code,
						'message' => $message,
					),
				);
			}
		}
		return array(
			'reports' => $reports,
			'error'   => null,
		);
	}

	/** Build exact provider-owned block replacements without consulting diagnostics. */
	private static function runtime_entity_bindings( array $lifecycle, array $reports ) {
		$bindings = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$manifest = isset( $prepared['manifest'] ) && is_array( $prepared['manifest'] ) ? $prepared['manifest'] : array();
			$report   = isset( $reports[ $declaration_id ] ) && is_array( $reports[ $declaration_id ] ) ? $reports[ $declaration_id ] : array();
			if ( 'waived' === ( $report['status'] ?? '' ) ) {
				continue;
			}
			$entity_key = isset( $manifest['products'] ) ? 'products' : 'forms';
			$manifest_entities = isset( $manifest[ $entity_key ] ) && is_array( $manifest[ $entity_key ] ) ? $manifest[ $entity_key ] : array();
			$result_entities = isset( $report[ $entity_key ] ) && is_array( $report[ $entity_key ] ) ? $report[ $entity_key ] : array();
			$results = array();
			foreach ( $result_entities as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}
				$key = 'products' === $entity_key ? (string) ( $result['slug'] ?? '' ) : (string) ( $result['source_path'] ?? '' ) . "\n" . (string) ( $result['selector'] ?? '' );
				$results[ $key ] = $result;
			}
			foreach ( $manifest_entities as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$entity_bindings = isset( $entity['bindings'] ) && is_array( $entity['bindings'] ) ? $entity['bindings'] : array();
				if ( empty( $entity_bindings ) ) {
					continue;
				}
				$key = 'products' === $entity_key ? (string) ( $entity['slug'] ?? '' ) : (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' );
				$result = $results[ $key ] ?? array();
				$replacement = Static_Site_Importer_Entity_Materializer_Registry::binding_block_markup( $prepared['adapter'], $entity, $result );
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
				foreach ( $entity_bindings as $binding ) {
					$bindings[] = array(
						'schema'                           => 'static-site-importer/runtime-entity-binding/v1',
						'source_path'                      => $binding['source_path'],
						'search_block_markup'              => $binding['search_block_markup'],
						'replacement_block_markup'         => $replacement,
						'occurrence'                       => $binding['occurrence'],
						'role'                             => $binding['role'],
						'declaration_id'                   => $declaration_id,
						'reconciliation_identity'          => hash( 'sha256', "static-site-importer/runtime-entity-binding/v1\n{$declaration_id}\n{$binding['source_path']}\n{$binding['occurrence']}\n" . hash( 'sha256', $binding['search_block_markup'] ) ),
						'fallback_reconciliation_identity' => 'form' === $binding['role'] ? Static_Site_Importer_Report_Diagnostics::fallback_reconciliation_identity( $entity ) : '',
						'fallback_hash'                    => 'form' === $binding['role'] ? Static_Site_Importer_Report_Diagnostics::fallback_reconciliation_hash( $entity ) : '',
						'materialized_block_hash'          => 'form' === $binding['role'] ? hash( 'sha256', $replacement ) : '',
						'provider'                         => $prepared['adapter']['provider'] ?? '',
						'superseded_runtime_selectors'     => $binding['superseded_runtime_selectors'] ?? array(),
					);
				}
			}
		}
		return $bindings;
	}

	/** Build classic bindings from canonical entity source selectors, never block anchors. */
	private static function classic_runtime_entity_bindings( array $lifecycle, array $reports ) {
		$bindings = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			if ( ! is_callable( $prepared['adapter']['classic_binding_callback'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_classic_provider_render_unavailable', 'Classic provider entity lacks an adapter-owned server render callback.', array( 'declaration_id' => $declaration_id ) );
			}
			$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$report = is_array( $reports[ $declaration_id ] ?? null ) ? $reports[ $declaration_id ] : array();
			if ( 'waived' === ( $report['status'] ?? '' ) ) {
				continue; }
			$key = isset( $manifest['products'] ) ? 'products' : 'forms';
			$results = array();
			foreach ( $report[ $key ] ?? array() as $result ) {
				if ( is_array( $result ) ) {
					$results[ 'products' === $key ? (string) ( $result['slug'] ?? '' ) : (string) ( $result['source_path'] ?? '' ) . "\n" . (string) ( $result['selector'] ?? '' ) ] = $result; }
			}
			foreach ( $manifest[ $key ] ?? array() as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue; }
				$entity_key = 'products' === $key ? (string) ( $entity['slug'] ?? '' ) : (string) ( $entity['source_path'] ?? '' ) . "\n" . (string) ( $entity['selector'] ?? '' );
				$render = Static_Site_Importer_Entity_Materializer_Registry::binding_classic_render( $prepared['adapter'], $entity, $results[ $entity_key ] ?? array() );
				$source = (string) ( $entity['source_path'] ?? '' );
				// Products can share a detected grid container. Bind only the canonical
				// leaf selector; replacing the container once per product is ambiguous.
				$selectors = array_filter( array( $entity['selector'] ?? '' ) );
				if ( empty( $render ) || '' === $source || empty( $selectors ) ) {
					return new WP_Error( 'static_site_importer_classic_html_binding_unresolved', 'A required provider entity lacks adapter-owned server render output or a canonical HTML source selector.', array( 'declaration_id' => $declaration_id ) );
				}
				foreach ( $selectors as $index => $selector ) {
					if ( ! is_string( $selector ) || '' === trim( $selector ) ) {
						return new WP_Error( 'static_site_importer_classic_html_binding_invalid', 'Classic provider source selector is invalid.' ); }
					$id = hash( 'sha256', "static-site-importer/classic-html-binding/v1\n{$declaration_id}\n{$source}\n{$selector}\n" . ( $index + 1 ) );
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

	/** Validate every classic source identity before dependencies or seeders run. */
	private static function preflight_classic_runtime_entity_bindings( array $projection, array $lifecycle, array $args ) {
		$bindings = array();
		$claims = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			if ( ! is_callable( $prepared['adapter']['classic_binding_callback'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_classic_provider_render_unavailable', 'Classic provider entity lacks an adapter-owned server render callback.', array( 'declaration_id' => $declaration_id ) );
			}
			$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$key = isset( $manifest['products'] ) ? 'products' : 'forms';
			foreach ( $manifest[ $key ] ?? array() as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue; }
				$source = (string) ( $entity['source_path'] ?? '' );
				$selector = (string) ( $entity['selector'] ?? '' );
				if ( '' === $source || '' === $selector ) {
					return new WP_Error( 'static_site_importer_classic_html_binding_invalid', 'Classic provider entity lacks a canonical leaf source selector.' ); }
				$claim = $source . "\n" . $selector . "\n1";
				if ( isset( $claims[ $claim ] ) ) {
					return new WP_Error(
						'static_site_importer_classic_html_binding_duplicate',
						'Classic provider entities claim the same source DOM identity.',
						array(
							'selector'    => $selector,
							'source_path' => $source,
						)
					); }
				$claims[ $claim ] = true;
				$bindings[] = array(
					'source_path' => $source,
					'selector'    => $selector,
					'occurrence'  => 1,
				);
			}
		}
		return Static_Site_Importer_Classic_Theme_Projection::preflight_bindings( $projection, $bindings );
	}

	/** Verify every declared source anchor before providers create or update entities. */
	private static function preflight_runtime_entity_binding_anchors( array $plan, array $lifecycle, array $args ) {
		$pages = array();
		foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
			if ( is_array( $page ) && is_string( $page['source_path'] ?? null ) ) {
				$pages[ $page['source_path'] ] = $page;
			}
		}
		$claims = array();
		$ranges = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			$manifest = isset( $prepared['manifest'] ) && is_array( $prepared['manifest'] ) ? $prepared['manifest'] : array();
			$entities = isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : ( isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : array() );
			foreach ( $entities as $entity ) {
				$entity_bindings = is_array( $entity ) && is_array( $entity['bindings'] ?? null ) ? $entity['bindings'] : array();
				foreach ( $entity_bindings as $binding ) {
					if ( empty( $binding ) ) {
						continue;
					}
					$claim = $binding['source_path'] . "\n" . hash( 'sha256', $binding['search_block_markup'] ) . "\n" . $binding['occurrence'];
					if ( isset( $claims[ $claim ] ) ) {
						return new WP_Error(
							'static_site_importer_runtime_binding_claim_conflict',
							'Two provider entities claim the same canonical source-page binding occurrence.',
							array(
								'status'         => 'rejected',
								'declaration_id' => $declaration_id,
							)
						);
					}
					$claims[ $claim ] = true;
					$page = $pages[ $binding['source_path'] ] ?? array();
					if ( ! empty( $page['skip_materialization'] ) ) {
						return new WP_Error(
							'static_site_importer_runtime_binding_target_protected',
							'A provider binding targets a protected page that cannot be materialized.',
							array(
								'status'         => 'rejected',
								'declaration_id' => $declaration_id,
							)
						);
					}
					$matches = substr_count( (string) ( $page['resolved_block_markup'] ?? '' ), (string) $binding['search_block_markup'] );
					if ( $matches < (int) $binding['occurrence'] ) {
						return new WP_Error(
							'static_site_importer_runtime_binding_cardinality_mismatch',
							'A canonical provider binding does not have its declared source-page occurrence.',
							array(
								'status'         => 'rejected',
								'declaration_id' => $declaration_id,
							)
						);
					}
					$content = (string) $page['resolved_block_markup'];
					$position = 0;
					for ( $occurrence = 0; $occurrence < (int) $binding['occurrence']; ++$occurrence ) {
						$found = strpos( $content, $binding['search_block_markup'], $position );
						if ( false === $found ) {
							return new WP_Error(
								'static_site_importer_runtime_binding_cardinality_mismatch',
								'A canonical provider binding does not have its declared source-page occurrence.',
								array(
									'status'         => 'rejected',
									'declaration_id' => $declaration_id,
								)
							);
						}
						$position = $found;
						if ( $occurrence + 1 < (int) $binding['occurrence'] ) {
							$position += strlen( $binding['search_block_markup'] );
						}
					}
					$end = $position + strlen( $binding['search_block_markup'] );
					foreach ( $ranges[ $binding['source_path'] ] ?? array() as $range ) {
						if ( $position < $range['end'] && $end > $range['start'] ) {
							return new WP_Error(
								'static_site_importer_runtime_binding_claim_conflict',
								'Provider entity bindings claim overlapping canonical source-page ranges.',
								array(
									'status'         => 'rejected',
									'declaration_id' => $declaration_id,
								)
							);
						}
					}
					$ranges[ $binding['source_path'] ][] = array(
						'start' => $position,
						'end'   => $end,
					);
				}
			}
		}
		return true;
	}

	/** @param array<string,mixed> $plan @return array<string,mixed> */
	private static function document_metadata_from_plan_receipt( array $plan ): array {
		foreach ( $plan['pages'] as $page ) {
			if ( ! empty( $page['entrypoint'] ) && isset( $page['document_metadata'] ) && is_array( $page['document_metadata'] ) ) {
				$metadata = array_merge( array( 'schema' => 'static-site-importer/document-metadata/v1' ), $page['document_metadata'] );
				foreach ( array(
					'links'   => 'href',
					'scripts' => 'src',
				) as $kind => $field ) {
					if ( ! isset( $metadata[ $kind ] ) || ! is_array( $metadata[ $kind ] ) ) {
						continue;
					}
					foreach ( $metadata[ $kind ] as &$declaration ) {
						if ( is_array( $declaration ) && isset( $declaration['resolved_url'] ) ) {
							$declaration[ $field ] = $declaration['resolved_url'];
						}
					}
					unset( $declaration );
				}
				return $metadata;
			}
		}
		return array( 'schema' => 'static-site-importer/document-metadata/v1' );
	}

	/** @param array<string,mixed> $payload */
	private static function write_plan_projection( string $path, array $payload, array &$receipt = array() ): void {
		Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_file( $receipt, $path );
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$data = false === $json ? false : $json . "\n";
		$temp = is_string( $data ) ? tempnam( dirname( $path ), '.ssi-projection-' ) : false;
		$written = is_string( $data ) && false !== $temp ? file_put_contents( $temp, $data ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomically writes preflighted public import artifacts.
		if ( false === $data || false === $temp || strlen( $data ) !== $written || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename atomically publishes the preflighted artifact.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed atomic projection temporary file.
			}
			throw new RuntimeException( 'Failed to write a preflighted import artifact.' );
		}
	}

	/**
	 * Materialize a compiled website artifact directly into WordPress theme artifacts.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	/**
	 * Build the canonical progress timeline returned to host chat/Codebox callers.
	 *
	 * @param string               $import_run_id Import run id.
	 * @param string               $theme_slug    Theme slug.
	 * @param array<string,int>    $page_ids      Materialized page IDs.
	 * @param array<string,string> $writes        Theme file writes.
	 * @param array<string,mixed>  $quality       Quality summary.
	 * @param array<string,mixed>  $validation    Validation result.
	 * @param string               $report_path   External report path.
	 * @return array<int,array<string,mixed>>
	 */
	private static function import_progress_events( string $import_run_id, string $theme_slug, array $page_ids, array $writes, array $quality, array $validation, string $report_path ): array {
		$now               = gmdate( 'c' );
		$page_count        = count( $page_ids );
		$file_count        = count( $writes );
		$diagnostic_count  = isset( $validation['diagnostics'] ) && is_array( $validation['diagnostics'] ) ? count( $validation['diagnostics'] ) : 0;
		$quality_passed    = empty( $quality['fail_import'] );
		$review_pending    = ! $quality_passed;
		$common            = array(
			'schema'        => 'wp-codebox/live-progress-event/v1',
			'run_id'        => $import_run_id,
			'source_schema' => 'static-site-importer/materialization-progress/v1',
			'timestamp'     => $now,
		);

		return array(
			array_merge(
				$common,
				array(
					'phase'    => 'ssi.materialization.completed',
					'status'   => 'succeeded',
					'label'    => 'Materialized WordPress content',
					'progress' => array(
						'current'   => $page_count,
						'completed' => $page_count,
						'total'     => $page_count,
						'percent'   => 100,
						'unit'      => 'pages',
					),
					'detail'   => array(
						'theme_slug' => $theme_slug,
						'file_count' => $file_count,
					),
				)
			),
			array_merge(
				$common,
				array(
					'phase'       => 'ssi.validation.completed',
					'status'      => $quality_passed ? 'succeeded' : 'failed',
					'label'       => $quality_passed ? 'Validation passed' : 'Validation needs review',
					'diagnostics' => array(
						'count' => $diagnostic_count,
					),
				)
			),
			array_merge(
				$common,
				array(
					'phase'     => $review_pending ? 'ssi.review.pending' : 'ssi.saved.completed',
					'status'    => $review_pending ? 'running' : 'succeeded',
					'label'     => $review_pending ? 'Review pending' : 'Saved to WordPress',
					'artifacts' => array_filter(
						array(
							'import_report' => '' !== $report_path ? array(
								'path' => $report_path,
								'kind' => 'json',
							) : null,
						)
					),
				)
			),
		);
	}

	/**
	 * Remove generated theme files from the previous SSI manifest when absent from the new desired manifest.
	 *
	 * @param string              $theme_dir        Theme directory.
	 * @param array<string,mixed> $current_manifest Current source-of-truth manifest.
	 * @param array<string,mixed> $args             Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function cleanup_stale_generated_theme_files( string $theme_dir, array $current_manifest, array $args = array(), array &$receipt = array() ) {
		$previous_manifest_path = trailingslashit( $theme_dir ) . 'static-site-importer-manifest.json';
		$cleanup                = array(
			'enabled'                => true,
			'policy'                 => 'previous_manifest_file_targets_only',
			'previous_manifest_path' => 'static-site-importer-manifest.json',
			'deleted'                => array(),
			'skipped'                => array(),
			'pages'                  => array(
				'enabled'     => true,
				'policy'      => 'previous_manifest_provenance_report_first',
				'action'      => self::stale_page_action( $args ),
				'stale_pages' => array(),
				'skipped'     => array(),
				'counts'      => array(
					'stale_pages'   => 0,
					'pages_drafted' => 0,
					'pages_deleted' => 0,
					'skipped'       => 0,
				),
				'notes'       => array( 'Stale SSI-owned pages are reported by default. Drafting requires explicit stale_page_action=draft; deletion is not supported here.' ),
			),
			'counts'                 => array(
				'deleted'       => 0,
				'skipped'       => 0,
				'pages_drafted' => 0,
				'pages_deleted' => 0,
			),
			'protected'              => array(
				'pages_deleted' => 0,
				'pages_drafted' => 0,
				'notes'         => array( 'Page deletion is intentionally disabled; this cleanup only removes prior SSI-generated theme files and assets.' ),
			),
		);
		if ( (string) ( $args['inject_materialization_failure'] ?? '' ) === 'stale_cleanup' ) {
			return new WP_Error( 'injected_stale_cleanup_failure', 'Injected stale cleanup failure.' );
		}

		if ( ! is_file( $previous_manifest_path ) ) {
			$cleanup['skipped'][] = array(
				'path'   => 'static-site-importer-manifest.json',
				'reason' => 'previous_manifest_missing',
			);
			$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
			return $cleanup;
		}

		$previous_manifest_json = file_get_contents( $previous_manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned local manifest file.
		if ( false === $previous_manifest_json ) {
			return new WP_Error( 'static_site_importer_previous_manifest_read_failed', 'Failed to read the previous Static Site Importer manifest.' );
		}

		$previous_manifest = json_decode( $previous_manifest_json, true );
		if ( ! is_array( $previous_manifest ) || 'static-site-importer/source-of-truth-manifest/v1' !== (string) ( $previous_manifest['schema'] ?? '' ) ) {
			$cleanup['skipped'][] = array(
				'path'   => 'static-site-importer-manifest.json',
				'reason' => 'previous_manifest_invalid',
			);
			$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );
			return $cleanup;
		}

		$page_reconciliation = self::reconcile_stale_manifest_pages( $previous_manifest, $current_manifest, $cleanup['pages']['action'], $receipt );
		if ( is_wp_error( $page_reconciliation ) ) {
			return $page_reconciliation;
		}
		$cleanup['pages']                         = $page_reconciliation;
		$cleanup['protected']['pages_deleted']    = (int) ( $page_reconciliation['counts']['pages_deleted'] ?? 0 );
		$cleanup['protected']['pages_drafted']    = (int) ( $page_reconciliation['counts']['pages_drafted'] ?? 0 );
		$cleanup['counts']['pages_deleted']       = (int) ( $page_reconciliation['counts']['pages_deleted'] ?? 0 );
		$cleanup['counts']['pages_drafted']       = (int) ( $page_reconciliation['counts']['pages_drafted'] ?? 0 );

		$current_paths  = self::manifest_theme_file_paths( $current_manifest );
		$previous_paths = self::manifest_theme_file_paths( $previous_manifest );
		$stale_paths    = array_values( array_diff( array_keys( $previous_paths ), array_keys( $current_paths ) ) );

		foreach ( $stale_paths as $relative ) {
			$path = trailingslashit( $theme_dir ) . $relative;
			if ( ! file_exists( $path ) ) {
				$cleanup['skipped'][] = array(
					'path'   => $relative,
					'reason' => 'already_missing',
				);
				continue;
			}

			if ( ! is_file( $path ) ) {
				$cleanup['skipped'][] = array(
					'path'   => $relative,
					'reason' => 'not_a_file',
				);
				continue;
			}
			Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_file( $receipt, $path );

			if ( ! wp_delete_file( $path ) ) {
				return new WP_Error( 'static_site_importer_stale_generated_file_delete_failed', sprintf( 'Failed to delete stale generated theme file: %s', $relative ) );
			}

			$cleanup['deleted'][] = array(
				'path'   => $relative,
				'reason' => 'absent_from_current_manifest',
			);
		}
		$cleanup['counts']['deleted'] = count( $cleanup['deleted'] );
		$cleanup['counts']['skipped'] = count( $cleanup['skipped'] );

		return $cleanup;
	}

	/**
	 * Resolve the explicitly requested stale page action.
	 *
	 * @param array<string,mixed> $args Import args.
	 * @return string
	 */
	private static function stale_page_action( array $args ): string {
		$action = isset( $args['stale_page_action'] ) && is_scalar( $args['stale_page_action'] ) ? sanitize_key( (string) $args['stale_page_action'] ) : '';
		if ( '' === $action ) {
			$option = get_option( 'static_site_importer_stale_page_action', '' );
			$action = is_scalar( $option ) ? sanitize_key( (string) $option ) : '';
		}

		return 'draft' === $action ? 'draft' : 'report_only';
	}

	/**
	 * Report or draft SSI-owned pages present in the previous manifest but absent from the current desired pages.
	 *
	 * @param array<string,mixed> $previous_manifest Previous source-of-truth manifest.
	 * @param array<string,mixed> $current_manifest  Current source-of-truth manifest.
	 * @param string              $action            Reconciliation action.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function reconcile_stale_manifest_pages( array $previous_manifest, array $current_manifest, string $action, array &$receipt = array() ) {
		$reconciliation = array(
			'enabled'     => true,
			'policy'      => 'previous_manifest_provenance_report_first',
			'action'      => 'draft' === $action ? 'draft' : 'report_only',
			'stale_pages' => array(),
			'skipped'     => array(),
			'counts'      => array(
				'stale_pages'   => 0,
				'pages_drafted' => 0,
				'pages_deleted' => 0,
				'skipped'       => 0,
			),
			'notes'       => array( 'Only pages with valid Static Site Importer provenance meta are eligible. Protected pages and pages without SSI provenance are never mutated.' ),
		);

		$current_sources = array();
		$current_posts   = array();
		foreach ( self::manifest_pages( $current_manifest ) as $page ) {
			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			$post_id     = (int) ( $page['materialized_post_id'] ?? 0 );
			if ( '' !== $source_path ) {
				$current_sources[ $source_path ] = true;
			}
			if ( $post_id > 0 ) {
				$current_posts[ $post_id ] = true;
			}
		}

		foreach ( self::manifest_pages( $previous_manifest ) as $page ) {
			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			$post_id     = (int) ( $page['materialized_post_id'] ?? 0 );
			if ( '' !== $source_path && isset( $current_sources[ $source_path ] ) ) {
				continue;
			}
			if ( $post_id <= 0 || isset( $current_posts[ $post_id ] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'reason'      => 'post_missing',
				);
				continue;
			}

			if ( Static_Site_Importer_Page_Materializer::is_protected_page( $post ) ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'slug'        => (string) $post->post_name,
					'reason'      => 'protected_page',
				);
				continue;
			}

			$provenance = self::page_provenance( $post_id );
			if ( empty( $provenance ) ) {
				$reconciliation['skipped'][] = array(
					'post_id'     => $post_id,
					'source_path' => $source_path,
					'slug'        => (string) $post->post_name,
					'reason'      => 'missing_static_site_importer_provenance',
				);
				continue;
			}

			$row = array(
				'post_id'         => $post_id,
				'post_type'       => (string) $post->post_type,
				'slug'            => (string) $post->post_name,
				'source_path'     => $source_path,
				'previous_status' => (string) $post->post_status,
				'action'          => 'report_only',
			);

			if ( 'draft' === $reconciliation['action'] ) {
				if ( 'draft' !== $post->post_status ) {
					Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_post( $receipt, $post_id );
					$result = wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => 'draft',
						),
						true
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					++$reconciliation['counts']['pages_drafted'];
				}
				$row['action']     = 'drafted';
				$row['new_status'] = 'draft';
			}

			$reconciliation['stale_pages'][] = $row;
		}

		$reconciliation['counts']['stale_pages'] = count( $reconciliation['stale_pages'] );
		$reconciliation['counts']['skipped']     = count( $reconciliation['skipped'] );

		return $reconciliation;
	}

	/**
	 * Extract desired pages from a manifest.
	 *
	 * @param array<string,mixed> $manifest Source-of-truth manifest.
	 * @return array<int,array<string,mixed>>
	 */
	private static function manifest_pages( array $manifest ): array {
		$desired = isset( $manifest['desired'] ) && is_array( $manifest['desired'] ) ? $manifest['desired'] : array();
		$pages   = isset( $desired['pages'] ) && is_array( $desired['pages'] ) ? $desired['pages'] : array();

		return array_values( array_filter( $pages, 'is_array' ) );
	}

	/**
	 * Read valid Static Site Importer page provenance meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private static function page_provenance( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, '_static_site_importer_provenance', true );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$provenance = json_decode( $raw, true );
		if ( ! is_array( $provenance ) || 'static-site-importer/page-provenance/v1' !== (string) ( $provenance['schema'] ?? '' ) ) {
			return array();
		}

		return $provenance;
	}

	/**
	 * Extract safe theme-relative file paths from a source-of-truth manifest.
	 *
	 * @param array<string,mixed> $manifest Source-of-truth manifest.
	 * @return array<string,true>
	 */
	private static function manifest_theme_file_paths( array $manifest ): array {
		$desired = isset( $manifest['desired'] ) && is_array( $manifest['desired'] ) ? $manifest['desired'] : array();
		$paths   = array();

		foreach ( $desired['files'] ?? array() as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$relative = self::normalize_manifest_theme_relative_path( isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '' );
			if ( '' !== $relative ) {
				$paths[ $relative ] = true;
			}
		}

		foreach ( $desired['assets'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$relative = self::normalize_manifest_theme_relative_path( isset( $asset['theme_path'] ) && is_scalar( $asset['theme_path'] ) ? (string) $asset['theme_path'] : '' );
			if ( '' !== $relative ) {
				$paths[ $relative ] = true;
			}
		}

		return $paths;
	}

	/**
	 * Normalize a manifest theme-relative path for safe file cleanup.
	 *
	 * @param string $path Manifest path.
	 * @return string
	 */
	private static function normalize_manifest_theme_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		if ( '' === $path || str_contains( $path, "\0" ) || str_starts_with( $path, '../' ) || str_contains( $path, '/../' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Export an imported or active block theme as a website artifact.
	 *
	 * @param array $args Export args.
	 * @return array{website_artifact:array<string,mixed>}|WP_Error
	 */
	public static function export_theme( array $args = array() ) {
		if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to export a website artifact.' );
		}

		$theme_slug = isset( $args['theme_slug'] ) && '' !== trim( (string) $args['theme_slug'] ) ? sanitize_title( (string) $args['theme_slug'] ) : self::active_theme_slug();
		if ( '' === $theme_slug ) {
			return new WP_Error( 'static_site_importer_missing_theme_slug', 'A theme_slug input is required when no active theme can be detected.' );
		}

		$theme_dir = self::export_theme_dir( $theme_slug );
		if ( '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'static_site_importer_theme_not_found', sprintf( 'Theme directory not found for %s.', $theme_slug ) );
		}

		$entrypoint      = self::export_artifact_path( isset( $args['entrypoint'] ) ? (string) $args['entrypoint'] : 'website/index.html', 'website/index.html' );
		$root            = self::export_artifact_root( isset( $args['root'] ) ? (string) $args['root'] : '', $entrypoint );
		$include_pages   = $args['include_pages'] ?? true;
		$source_metadata = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$diagnostics     = array();
		$files           = array();

		$stylesheet = self::export_theme_stylesheet_file( $theme_dir, $root );
		if ( null !== $stylesheet ) {
			$files[] = $stylesheet;
		}

		$pages      = self::export_pages( $include_pages );
		$post_count = 0;
		if ( empty( $pages ) ) {
			$diagnostics[] = array(
				'level'   => 'warning',
				'code'    => 'static_site_importer_export_no_pages',
				'message' => 'No published pages were available to export; generated an entrypoint from theme templates only.',
			);
			$files[] = self::export_file_entry(
				$entrypoint,
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page' ), $theme_slug, null !== $stylesheet ),
				'document',
				'entrypoint'
			);
		} else {
			$front_page_id = self::export_front_page_id();
			$first         = true;
			$planned       = array();
			$used_paths    = array();
			// Reserve every page route before assigning post routes so shared
			// slugs resolve identically regardless of get_posts() ordering: pages
			// keep the clean /root/<slug>/ path and a colliding post moves under
			// /root/post/<slug>/. WP guarantees unique slugs only within a type.
			$front_planned_id = 0;
			foreach ( $pages as $page ) {
				$page_id  = isset( $page->ID ) ? (int) $page->ID : 0;
				$is_front = $first || ( $front_page_id > 0 && $page_id === $front_page_id );
				$first    = false;
				if ( 'post' === ( $page->post_type ?? '' ) && ! $is_front ) {
					continue;
				}
				if ( $is_front ) {
					$front_planned_id = $page_id;
				}
				$path                = $is_front ? $entrypoint : self::export_page_artifact_path( $page, $root );
				$used_paths[ $path ] = true;
				$planned[]           = array(
					'page'     => $page,
					'path'     => $path,
					'is_front' => $is_front,
				);
			}
			foreach ( $pages as $page ) {
				if ( 'post' !== ( $page->post_type ?? '' ) || ( isset( $page->ID ) && (int) $page->ID === $front_planned_id ) ) {
					continue;
				}
				++$post_count;
				$path = self::export_page_artifact_path( $page, $root );
				if ( isset( $used_paths[ $path ] ) ) {
					$path = self::export_artifact_path( $root . '/post/' . ( isset( $page->post_name ) ? sanitize_title( (string) $page->post_name ) : (string) ( isset( $page->ID ) ? (int) $page->ID : 0 ) ) . '/index.html', $root . '/post/page/index.html' );
				}
				$used_paths[ $path ] = true;
				$planned[]           = array(
					'page'     => $page,
					'path'     => $path,
					'is_front' => false,
				);
			}
			foreach ( $planned as $plan ) {
				$page     = $plan['page'];
				$path     = $plan['path'];
				$is_front = $plan['is_front'];
				$page_id  = isset( $page->ID ) ? (int) $page->ID : 0;
				$template = $is_front ? 'front-page' : 'page';
				$page_html = self::blocks_to_html( isset( $page->post_content ) ? (string) $page->post_content : '' );

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, self::export_theme_chrome_html( $theme_dir, $template ), self::export_page_title( $page, $theme_slug ), null !== $stylesheet ),
					'document',
					$is_front ? 'entrypoint' : 'page',
					array(
						'post_id'   => $page_id,
						'post_name' => isset( $page->post_name ) ? (string) $page->post_name : '',
					)
				);
			}
		}

		$files = array_merge( $files, self::export_theme_asset_files( $theme_dir, $root, $diagnostics ) );

		$import_report = self::read_theme_import_report( $theme_dir );
		if ( ! empty( $import_report ) ) {
			$files[] = self::export_file_entry(
				$root . '/import-report.json',
				self::json_encode_pretty( $import_report ),
				'metadata',
				'report',
				array(
					'source' => array(
						'type' => 'static-site-importer-import-report',
					),
				)
			);

			$source_documents = isset( $import_report['source_documents'] ) && is_array( $import_report['source_documents'] ) ? $import_report['source_documents'] : array();
			if ( ! empty( $source_documents ) ) {
				$files[] = self::export_file_entry(
					$root . '/source-documents.json',
					self::json_encode_pretty( $source_documents ),
					'metadata',
					'source-document',
					array(
						'source' => array(
							'type' => 'static-site-importer-source-documents',
						),
					)
				);
			}
		}

		$report = array(
			'status'          => 'completed',
			'theme_slug'      => $theme_slug,
			'theme_dir'       => $theme_dir,
			'root'            => $root,
			'entrypoint'      => $entrypoint,
			'file_count'      => count( $files ),
			'page_count'      => count( $pages ) - $post_count, // pages keep the page_count contract; posts are reported separately
			'post_count'      => $post_count,
			'source_metadata' => $source_metadata,
			'diagnostics'     => $diagnostics,
		);
		if ( ! empty( $import_report ) ) {
			$report['import_report'] = $import_report;
		}

		$website_artifact = self::export_website_artifact( $theme_slug, $root, $entrypoint, $files, $report, $source_metadata );

		return array(
			'website_artifact' => $website_artifact,
		);
	}

	/**
	 * Resolve the active theme slug.
	 *
	 * @return string
	 */
	private static function active_theme_slug(): string {
		if ( function_exists( 'get_stylesheet' ) ) {
			return sanitize_title( (string) get_stylesheet() );
		}

		return '';
	}

	/**
	 * Resolve a theme directory for export.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function export_theme_dir( string $theme_slug ): string {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $theme_slug );
			if ( is_object( $theme ) && method_exists( $theme, 'exists' ) && $theme->exists() && method_exists( $theme, 'get_stylesheet_directory' ) ) {
				return (string) $theme->get_stylesheet_directory();
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			return trailingslashit( get_theme_root( $theme_slug ) ) . $theme_slug;
		}

		return '';
	}

	/**
	 * Get published pages selected by include_pages.
	 *
	 * @param mixed $include_pages Include pages argument.
	 * @return array<int,object>
	 */
	private static function export_pages( $include_pages ): array {
		if ( false === $include_pages || ! function_exists( 'get_posts' ) ) {
			$page = self::export_front_page();
			return null === $page ? array() : array( $page );
		}

		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $pages ) ) {
			return array();
		}

		if ( ! is_array( $include_pages ) || empty( $include_pages ) ) {
			return self::order_front_page_first( array_values( $pages ) );
		}

		$allowed = array_fill_keys( array_map( 'strval', $include_pages ), true );
		return self::order_front_page_first(
			array_values(
				array_filter(
					$pages,
					static function ( $page ) use ( $allowed ): bool {
						$page_id   = isset( $page->ID ) ? (string) $page->ID : '';
						$page_slug = isset( $page->post_name ) ? (string) $page->post_name : '';
						return isset( $allowed[ $page_id ] ) || isset( $allowed[ $page_slug ] );
					}
				)
			)
		);
	}

	/**
	 * Order exported pages so the configured front page becomes the entrypoint.
	 *
	 * @param array<int,object> $pages Pages.
	 * @return array<int,object>
	 */
	private static function order_front_page_first( array $pages ): array {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id <= 0 ) {
			return $pages;
		}

		usort(
			$pages,
			static function ( object $left, object $right ) use ( $front_page_id ): int {
				$left_is_front  = isset( $left->ID ) && (int) $left->ID === $front_page_id;
				$right_is_front = isset( $right->ID ) && (int) $right->ID === $front_page_id;
				if ( $left_is_front === $right_is_front ) {
					return 0;
				}

				return $left_is_front ? -1 : 1;
			}
		);

		return $pages;
	}

	/**
	 * Get the configured front page post.
	 *
	 * @return object|null
	 */
	private static function export_front_page(): ?object {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id > 0 && function_exists( 'get_post' ) ) {
			$page = get_post( $front_page_id );
			if ( is_object( $page ) ) {
				return $page;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		return $pages[0] ?? null;
	}

	/**
	 * Get the configured front page ID.
	 *
	 * @return int
	 */
	private static function export_front_page_id(): int {
		if ( ! function_exists( 'get_option' ) || 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		return (int) get_option( 'page_on_front' );
	}

	/**
	 * Convert template parts around exported page content.
	 *
	 * @param string $theme_dir Theme directory.
	 * @param string $template  Template slug.
	 * @return array{before:string,after:string}
	 */
	private static function export_theme_chrome_html( string $theme_dir, string $template ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html' );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html' );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html ) {
			$converted_template = self::blocks_to_html( $template_html );
			if ( '' !== trim( $converted_template ) && '' === trim( $before . $after ) ) {
				$before = $converted_template;
			}
		}

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Convert a block markup file to HTML.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function convert_theme_block_file_to_html( string $path ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content ? '' : self::blocks_to_html( $content );
	}

	/**
	 * Render serialized block markup with Blocks Engine's native format bridge.
	 *
	 * @param string $block_markup Serialized blocks.
	 * @return string
	 */
	private static function blocks_to_html( string $block_markup ): string {
		$result = blocks_engine_php_transformer_convert_format( $block_markup, 'blocks', 'html' );
		return isset( $result['documents'][0]['content'] ) && is_scalar( $result['documents'][0]['content'] ) ? (string) $result['documents'][0]['content'] : '';
	}

	/**
	 * Read a file when available.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_file_if_readable( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
		$content = file_get_contents( $path );
		return false === $content ? '' : (string) $content;
	}

	/**
	 * Build a full static HTML document.
	 *
	 * @param string                            $page_html       Converted page body HTML.
	 * @param array{before:string,after:string} $chrome          Converted theme chrome.
	 * @param string                            $title           Document title.
	 * @param bool                              $include_styles  Whether to link exported CSS.
	 * @return string
	 */
	private static function export_html_document( string $page_html, array $chrome, string $title, bool $include_styles ): string {
		$head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		if ( $include_styles ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This method emits standalone static HTML, not a WordPress-rendered page.
			$head .= '<link rel="stylesheet" href="style.css">';
		}

		return '<!doctype html>' . "\n"
			. '<html><head>' . $head . '<title>' . esc_html( $title ) . '</title></head><body>' . "\n"
			. trim( (string) ( $chrome['before'] ?? '' ) . "\n" . $page_html . "\n" . ( $chrome['after'] ?? '' ) ) . "\n"
			. '</body></html>' . "\n";
	}

	/**
	 * Build an artifact file entry.
	 *
	 * @param string              $path        Artifact path.
	 * @param string              $content     File content.
	 * @param string              $kind        File kind.
	 * @param string              $role        File role.
	 * @param array<string,mixed> $diagnostics Optional diagnostics/metadata.
	 * @return array<string,mixed>
	 */
	private static function export_file_entry( string $path, string $content, string $kind, string $role, array $diagnostics = array() ): array {
		$encoding = self::is_binary_content( $content ) ? 'base64' : 'utf8';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact files are explicitly represented as base64 for transport.
		$body = 'base64' === $encoding ? base64_encode( $content ) : $content;
		$entry = array(
			'path'      => $path,
			'content'   => $body,
			'kind'      => $kind,
			'role'      => $role,
			'mime_type' => self::export_mime_type( $path ),
			'encoding'  => $encoding,
			'bytes'     => strlen( $content ),
			'sha256'    => hash( 'sha256', $content ),
		);
		if ( ! empty( $diagnostics ) ) {
			$entry = array_merge( $entry, $diagnostics );
		}

		return $entry;
	}

	/**
	 * Build the website artifact envelope consumed by Blocks Engine.
	 *
	 * @param string                         $theme_slug      Theme slug.
	 * @param string                         $root            Artifact root.
	 * @param string                         $entrypoint      Entrypoint path.
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param array<string,mixed>            $report          Export report.
	 * @param array<string,mixed>            $source_metadata Source metadata.
	 * @return array<string,mixed>
	 */
	private static function export_website_artifact( string $theme_slug, string $root, string $entrypoint, array $files, array $report, array $source_metadata ): array {
		$generated_at = self::export_generated_at();
		$id           = 'website-artifact-' . $theme_slug . '-' . substr( hash( 'sha256', self::json_encode_pretty( array( $entrypoint, $files ) ) ), 0, 12 );

		return array(
			'schema'        => 'blocks-engine/php-transformer/site-artifact/v1',
			'artifact_type' => 'website',
			'version'       => 1,
			'id'            => $id,
			'generated_at'  => $generated_at,
			'theme_slug'    => $theme_slug,
			'root'          => $root,
			'entrypoint'    => $entrypoint,
			'files'         => $files,
			'report'        => $report,
			'reports'       => self::export_report_refs( $files ),
			'import'        => array(
				'status'      => empty( $report['diagnostics'] ) ? 'passed' : 'warning',
				'theme_slug'  => $theme_slug,
				'source_path' => $entrypoint,
				'warnings'    => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'warning' ),
				'errors'      => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'error' ),
			),
			'validation'    => array(
				'status'     => self::export_validation_status( $report['diagnostics'] ?? array() ),
				'checked_at' => $generated_at,
				'checks'     => array(
					array(
						'name'    => 'entrypoint-present',
						'status'  => self::export_has_file( $files, $entrypoint ) ? 'passed' : 'failed',
						'message' => 'The website artifact entrypoint is present in the exported file set.',
					),
				),
			),
			'provenance'    => array(
				'producer'          => 'static-site-importer',
				'source_metadata'   => $source_metadata,
				'materialized_from' => array(
					'type'       => 'wordpress-block-theme',
					'theme_slug' => $theme_slug,
				),
			),
		);
	}

	/**
	 * Export the theme stylesheet when present.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>|null
	 */
	private static function export_theme_stylesheet_file( string $theme_dir, string $root ): ?array {
		$content = self::read_file_if_readable( $theme_dir . '/style.css' );
		if ( '' === $content ) {
			return null;
		}

		return self::export_file_entry( $root . '/style.css', $content, 'asset', 'stylesheet' );
	}

	/**
	 * Export browser assets that can be replayed with the website artifact.
	 *
	 * @param string                         $theme_dir   Theme directory.
	 * @param string                         $root        Artifact root.
	 * @param array<int,array<string,mixed>> $diagnostics Export diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function export_theme_asset_files( string $theme_dir, string $root, array &$diagnostics ): array {
		$assets_dir = $theme_dir . '/assets';
		if ( ! is_dir( $assets_dir ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $assets_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( ! $item instanceof SplFileInfo || ! $item->isFile() || ! $item->isReadable() ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $assets_dir ) ) ), '/' );
			$path     = self::export_artifact_path( $root . '/assets/' . $relative, '' );
			if ( '' === $path || ! self::export_is_supported_asset_path( $path ) ) {
				$diagnostics[] = array(
					'level'   => 'warning',
					'code'    => 'static_site_importer_export_asset_skipped',
					'message' => 'A theme asset was skipped because its path or type is not supported for static export.',
					'path'    => $relative,
				);
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
			$content = file_get_contents( $item->getPathname() );
			if ( false === $content ) {
				continue;
			}

			$files[] = self::export_file_entry( $path, (string) $content, self::export_kind_from_path( $path ), self::export_role_from_path( $path ) );
		}

		usort(
			$files,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['path'] ?? '' ), (string) ( $right['path'] ?? '' ) );
			}
		);

		return $files;
	}

	/**
	 * Normalize an exported artifact path.
	 *
	 * @param string $path     Requested path.
	 * @param string $fallback Fallback path.
	 * @return string
	 */
	private static function export_artifact_path( string $path, string $fallback ): string {
		$path = self::normalize_route_path( $path );
		if ( '' === $path || str_ends_with( $path, '/' ) ) {
			return $fallback;
		}

		return $path;
	}

	/**
	 * Resolve the artifact root from input or entrypoint.
	 *
	 * @param string $root       Requested root.
	 * @param string $entrypoint Entrypoint path.
	 * @return string
	 */
	private static function export_artifact_root( string $root, string $entrypoint ): string {
		$root = self::normalize_route_path( $root );
		if ( '' !== $root && ! str_contains( $root, '/' ) ) {
			return $root;
		}

		$parts = explode( '/', $entrypoint );
		return '' !== ( $parts[0] ?? '' ) ? $parts[0] : 'website';
	}

	/**
	 * Normalize a route-like path without resolving outside the source root.
	 *
	 * @param string $path Route path.
	 * @return string
	 */
	private static function normalize_route_path( string $path ): string {
		$path_without_query = strtok( $path, '?' );
		$path               = str_replace( '\\', '/', false === $path_without_query ? $path : $path_without_query );
		$path               = ltrim( $path, '/' );
		$segments           = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Build a page artifact path.
	 *
	 * @param object $page Page object.
	 * @return string
	 */
	private static function export_page_artifact_path( object $page, string $root ): string {
		$slug = isset( $page->post_name ) && '' !== trim( (string) $page->post_name ) ? sanitize_title( (string) $page->post_name ) : 'page-' . ( isset( $page->ID ) ? (int) $page->ID : uniqid() );
		return self::export_artifact_path( $root . '/' . $slug . '/index.html', $root . '/page/index.html' );
	}

	/**
	 * Resolve a page title for export.
	 *
	 * @param object $page       Page object.
	 * @param string $theme_slug Fallback theme slug.
	 * @return string
	 */
	private static function export_page_title( object $page, string $theme_slug ): string {
		if ( isset( $page->post_title ) && '' !== trim( (string) $page->post_title ) ) {
			return (string) $page->post_title;
		}

		return $theme_slug;
	}

	/**
	 * Resolve a static export MIME type from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_mime_type( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'text/html',
			'css'         => 'text/css',
			'js', 'mjs'    => 'text/javascript',
			'json'        => 'application/json',
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg', 'jpeg'  => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			default       => 'application/octet-stream',
		};
	}

	/**
	 * Infer an exported file kind from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_kind_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'document',
			'css'         => 'asset',
			'js', 'mjs'    => 'asset',
			'json'        => 'metadata',
			default       => 'asset',
		};
	}

	/**
	 * Infer a static artifact file role from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_role_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'css'        => 'stylesheet',
			'js', 'mjs'   => 'script',
			'json'       => 'metadata',
			default      => 'asset',
		};
	}

	/**
	 * Check whether an asset path is supported for static export.
	 *
	 * @param string $path Artifact path.
	 * @return bool
	 */
	private static function export_is_supported_asset_path( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'woff', 'woff2' ), true );
	}

	/**
	 * Detect binary content that should be inlined as base64.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	private static function is_binary_content( string $content ): bool {
		return str_contains( $content, "\0" ) || ! preg_match( '//u', $content );
	}

	/**
	 * JSON encode with stable options and a PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data to encode.
	 * @return string
	 */
	private static function json_encode_pretty( mixed $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Smoke tests load this class without WordPress helpers.
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded . "\n" : "{}\n";
	}

	/**
	 * Return the export timestamp.
	 *
	 * @return string
	 */
	private static function export_generated_at(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Build report file references from exported files.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @return array<int,array<string,string>>
	 */
	private static function export_report_refs( array $files ): array {
		$refs = array();
		foreach ( $files as $file ) {
			$role = (string) ( $file['role'] ?? '' );
			if ( in_array( $role, array( 'report', 'source-document' ), true ) ) {
				$refs[] = array(
					'role' => $role,
					'path' => (string) ( $file['path'] ?? '' ),
				);
			}
		}

		return $refs;
	}

	/**
	 * Extract diagnostic messages by level/severity.
	 *
	 * @param mixed  $diagnostics Diagnostics.
	 * @param string $level       Level to collect.
	 * @return array<int,string>
	 */
	private static function export_diagnostic_messages( mixed $diagnostics, string $level ): array {
		if ( ! is_array( $diagnostics ) ) {
			return array();
		}

		$messages = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$diagnostic_level = (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) );
			if ( $level === $diagnostic_level ) {
				$messages[] = (string) ( $diagnostic['message'] ?? ( $diagnostic['code'] ?? '' ) );
			}
		}

		return array_values( array_filter( $messages ) );
	}

	/**
	 * Resolve validation status from diagnostics.
	 *
	 * @param mixed $diagnostics Diagnostics.
	 * @return string
	 */
	private static function export_validation_status( mixed $diagnostics ): string {
		if ( ! is_array( $diagnostics ) ) {
			return 'passed';
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && 'error' === (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) ) ) {
				return 'failed';
			}
		}

		return empty( $diagnostics ) ? 'passed' : 'warning';
	}

	/**
	 * Check whether a file path exists in the export set.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param string                         $path  Artifact path.
	 * @return bool
	 */
	private static function export_has_file( array $files, string $path ): bool {
		foreach ( $files as $file ) {
			if ( (string) ( $file['path'] ?? '' ) === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the import report bundled with an SSI-generated theme.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>
	 */
	private static function read_theme_import_report( string $theme_dir ): array {
		$report = self::read_file_if_readable( $theme_dir . '/import-report.json' );
		if ( '' === $report ) {
			return array();
		}

		$decoded = json_decode( $report, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build a stable import run id for report and provenance joins.
	 *
	 * @param array<string,mixed> $args Import args.
	 * @return string
	 */
	private static function import_run_id( array $args ): string {
		if ( isset( $args['import_run_id'] ) && is_scalar( $args['import_run_id'] ) && '' !== trim( (string) $args['import_run_id'] ) ) {
			return sanitize_key( (string) $args['import_run_id'] );
		}

		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'ssi-' . wp_generate_uuid4();
		}

		return 'ssi-' . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Whether to persist report/validation/finding JSON into the generated theme.
	 *
	 * @param array<string,mixed> $args Import args.
	 * @return bool
	 */
	private static function write_theme_report_artifacts_enabled( array $args ): bool {
		if ( ! array_key_exists( 'write_theme_report_artifacts', $args ) ) {
			return false;
		}

		return false !== filter_var( $args['write_theme_report_artifacts'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Extract artifact identity fields supplied with a source artifact.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function source_artifact_reference_from_artifact( array $artifact, array $args = array() ): array {
		$reference = array(
			'schema'     => isset( $artifact['schema'] ) && is_scalar( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'id'         => '',
			'hash'       => '',
			'hash_algo'  => '',
			'entrypoint' => isset( $artifact['entrypoint'] ) && is_scalar( $artifact['entrypoint'] ) ? (string) $artifact['entrypoint'] : '',
		);

		foreach ( array( 'artifact_id', 'id', 'run_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$reference['id'] = (string) $args[ $key ];
				break;
			}
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$reference['id'] = (string) $artifact[ $key ];
				break;
			}
		}

		foreach ( array( 'artifact_hash', 'hash', 'sha256' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$reference['hash'] = (string) $args[ $key ];
				break;
			}
			if ( isset( $artifact[ $key ] ) && is_scalar( $artifact[ $key ] ) && '' !== trim( (string) $artifact[ $key ] ) ) {
				$reference['hash'] = (string) $artifact[ $key ];
				break;
			}
		}

		if ( isset( $args['artifact_hash_algo'] ) && is_scalar( $args['artifact_hash_algo'] ) ) {
			$reference['hash_algo'] = (string) $args['artifact_hash_algo'];
		} elseif ( isset( $artifact['hash_algo'] ) && is_scalar( $artifact['hash_algo'] ) ) {
			$reference['hash_algo'] = (string) $artifact['hash_algo'];
		} elseif ( isset( $artifact['sha256'] ) || isset( $args['sha256'] ) ) {
			$reference['hash_algo'] = 'sha256';
		}

		return array_filter(
			$reference,
			static fn ( $value ): bool => '' !== $value
		);
	}

	/**
	 * Extract artifact identity fields from the compiled result and import args.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function source_artifact_reference_from_compiled( array $compiled, array $args = array() ): array {
		$reference  = isset( $args['source_artifact_reference'] ) && is_array( $args['source_artifact_reference'] ) ? $args['source_artifact_reference'] : array();
		$provenance = isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : array();
		$input      = isset( $compiled['input'] ) && is_array( $compiled['input'] ) ? $compiled['input'] : array();

		foreach ( array(
			'id'        => array( 'artifact_id', 'id', 'run_id' ),
			'hash'      => array( 'artifact_hash', 'hash', 'sha256' ),
			'hash_algo' => array( 'artifact_hash_algo', 'hash_algo' ),
		) as $target => $keys ) {
			if ( isset( $reference[ $target ] ) && is_scalar( $reference[ $target ] ) && '' !== trim( (string) $reference[ $target ] ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				foreach ( array( $args, $provenance, $input ) as $source ) {
					if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
						$reference[ $target ] = (string) $source[ $key ];
						break 2;
					}
				}
			}
		}

		if ( ! isset( $reference['entrypoint'] ) && isset( $input['entry_path'] ) && is_scalar( $input['entry_path'] ) ) {
			$reference['entrypoint'] = (string) $input['entry_path'];
		}

		return array_filter(
			$reference,
			static fn ( $value ): bool => is_scalar( $value ) ? '' !== trim( (string) $value ) : ! empty( $value )
		);
	}
}
