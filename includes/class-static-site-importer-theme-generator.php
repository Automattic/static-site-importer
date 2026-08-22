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
		if ( isset( $args['compiled_artifact_result'] ) && is_array( $args['compiled_artifact_result'] ) ) {
			$compiled = $args['compiled_artifact_result'];
		} else {
			$compiler_result = ( new $compiler_class() )->compile( $artifact );
			$view_method     = 'toWordPressSitePlanView';
			$compiled        = is_callable( array( $compiler_result, $view_method ) ) ? ( new ReflectionMethod( $compiler_result, $view_method ) )->invoke( $compiler_result ) : $compiler_result->toArray();
		}
		if ( ! is_array( $compiled ) ) {
			return new WP_Error( 'static_site_importer_invalid_transformer_result', 'Blocks Engine php-transformer returned an invalid result.' );
		}
		$source_reports = 'blocks-engine/wordpress-site-plan-view/v1' === ( $compiled['schema'] ?? '' ) ? array(
			'wordpress_site_plan'             => $compiled['wordpress_site_plan'] ?? array(),
			'wordpress_site_plan_diagnostics' => $compiled['diagnostics'] ?? array(),
			'gutenberg_gaps'                  => $compiled['gutenberg_gaps'] ?? array(),
			'companion_plugin_payload'        => $compiled['companion_plugin_payload'] ?? array(),
			'materialization_plan'            => array( 'theme' => array( 'font_materialization' => $compiled['font_materialization'] ?? array() ) ),
		) : ( is_array( $compiled['source_reports'] ?? null ) ? $compiled['source_reports'] : array() );
		$plan = isset( $source_reports['wordpress_site_plan'] ) && is_array( $source_reports['wordpress_site_plan'] ) ? $source_reports['wordpress_site_plan'] : array();
		if ( empty( $plan ) ) {
			$diagnostics = isset( $source_reports['wordpress_site_plan_diagnostics'] ) && is_array( $source_reports['wordpress_site_plan_diagnostics'] ) ? wp_json_encode( $source_reports['wordpress_site_plan_diagnostics'] ) : '';
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce a WordPress site plan.' . ( false !== $diagnostics ? ' ' . $diagnostics : '' ), $compiled );
		}
		$companion_payload = null;
		$gutenberg_gaps    = isset( $source_reports['gutenberg_gaps'] ) && is_array( $source_reports['gutenberg_gaps'] ) ? $source_reports['gutenberg_gaps'] : array();
		if ( ! empty( $source_reports['companion_plugin_payload'] ) ) {
			$companion_payload = $source_reports['companion_plugin_payload'];
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
		if ( isset( $args['approved_classic_plan_identity'] ) && is_array( $args['approved_classic_plan_identity'] ) && $args['approved_classic_plan_identity'] !== ( $plan['plan_identity'] ?? null ) ) {
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
		$materialization_plan = isset( $source_reports['materialization_plan'] ) && is_array( $source_reports['materialization_plan'] ) ? $source_reports['materialization_plan'] : array();
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
			$prepared['prepared_resolved_projection_hash'] = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepared_resolved_projection_hash( $prepared['base_resolved'] );
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
			'plan_identity'                    => $receipt['plan_identity'] ?? array(),
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
				'total_count'                  => count( $plan['pages'] ),
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
		$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
		$validation = $report['import_validation_result'];
		$findings   = $report['finding_packets'];
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
		$temp = tempnam( dirname( $path ), '.ssi-projection-' );
		$stream = false !== $temp ? fopen( $temp, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams a public projection into an atomic temporary file.
		$written = is_resource( $stream ) && self::write_json_projection( $stream, $payload, 0 ) && self::write_all( $stream, "\n" ) && fflush( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flushes the complete temporary projection before publication.
		$closed = ! is_resource( $stream ) || fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the complete temporary projection before publication.
		if ( ! $written || ! $closed || false === $temp || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename atomically publishes the complete preflighted artifact.
			if ( is_string( $temp ) && file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed atomic projection temporary file.
			}
			throw new RuntimeException( 'Failed to write a preflighted import artifact.' );
		}
	}

	/** Stream JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES without a full payload string. */
	private static function write_json_projection( $stream, mixed $value, int $depth ): bool {
		if ( 512 < $depth ) {
			return false;
		}
		if ( is_object( $value ) ) {
			$value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
			return self::write_json_object( $stream, $value, $depth );
		}
		if ( ! is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return false !== $json && self::write_all( $stream, $json );
		}
		$is_list = self::json_list( $value );
		return self::write_json_container( $stream, $value, $depth, $is_list );
	}

	/** @param array<mixed> $value */
	private static function write_json_object( $stream, array $value, int $depth ): bool {
		return self::write_json_container( $stream, $value, $depth, false );
	}

	/** @param array<mixed> $value */
	private static function write_json_container( $stream, array $value, int $depth, bool $is_list ): bool {
		if ( empty( $value ) ) {
			return self::write_all( $stream, $is_list ? '[]' : '{}' );
		}
		if ( ! self::write_all( $stream, $is_list ? '[' : '{' ) ) {
			return false;
		}
		$first = true;
		foreach ( $value as $key => $item ) {
			if ( ! self::write_all( $stream, $first ? "\n" : ",\n" ) || ! self::write_all( $stream, str_repeat( '    ', $depth + 1 ) ) ) {
				return false;
			}
			$first = false;
			if ( ! $is_list ) {
				$key_json = wp_json_encode( (string) $key, JSON_UNESCAPED_SLASHES );
				if ( false === $key_json || ! self::write_all( $stream, $key_json . ': ' ) ) {
					return false;
				}
			}
			if ( ! self::write_json_projection( $stream, $item, $depth + 1 ) ) {
				return false;
			}
		}
		return self::write_all( $stream, "\n" . str_repeat( '    ', $depth ) . ( $is_list ? ']' : '}' ) );
	}

	/** Write every byte or fail before the temporary projection can be published. */
	private static function write_all( $stream, string $data ): bool {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$written = fwrite( $stream, substr( $data, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Handles short writes while streaming a public projection.
			if ( ! is_int( $written ) || 0 >= $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/** Match PHP's array-to-JSON list detection without encoding an array. */
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

			if ( Static_Site_Importer_Protected_Page_Policy::is_protected_page( $post ) ) {
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
