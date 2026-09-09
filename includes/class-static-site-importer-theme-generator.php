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

if ( ! class_exists( 'Static_Site_Importer_Receipt_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-receipt-projection.php';
}

require_once __DIR__ . '/class-static-site-importer-entity-compensation.php';

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
if ( ! class_exists( 'Static_Site_Importer_Failed_Plan_Validation' ) ) {
	require_once __DIR__ . '/class-static-site-importer-failed-plan-validation.php';
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
		$payload_reader = is_object( $args['_static_site_importer_payload_reader'] ?? null ) ? $args['_static_site_importer_payload_reader'] : null;
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
		if ( is_object( $payload_reader ) ) {
			$args['_static_site_importer_payload_reader'] = $payload_reader;
		}
		$plan                  = $compiled_import['plan'];
		$gutenberg_gaps        = $compiled_import['gutenberg_gaps'];
		$companion_payload     = $compiled_import['companion_payload'];
		$materialization_plan  = $compiled_import['materialization_plan'];
		$theme_materialization = $compiled_import['theme_materialization'];
		$args['font_materialization'] = isset( $materialization_plan['theme']['font_materialization'] ) && is_array( $materialization_plan['theme']['font_materialization'] ) ? $materialization_plan['theme']['font_materialization'] : array();
		$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, $args );
		if ( is_wp_error( $lifecycle ) ) {
			return $lifecycle;
		}
		if ( 'plan' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
			$encoded_artifact = wp_json_encode( $artifact );
			return Static_Site_Importer_Dependency_Manager::dependency_plan( $lifecycle, hash( 'sha256', false !== $encoded_artifact ? $encoded_artifact : '' ) );
		}
		$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare_for_materialization( $plan, $args );
		if ( 'prepared' !== ( $prepared['status'] ?? '' ) ) {
			$receipt = isset( $prepared['receipt'] ) && is_array( $prepared['receipt'] ) ? $prepared['receipt'] : array();
			$error   = $receipt['errors'][0] ?? array();
			if ( 'failed' === ( $receipt['editability_report']['status'] ?? '' ) ) {
				return self::failed_editability_admission( $plan, $args, $compiled_import, $receipt );
			}
			return new WP_Error( (string) ( $error['code'] ?? 'static_site_importer_materialization_failed' ), (string) ( $error['message'] ?? 'WordPress site plan destination preflight failed.' ), $receipt );
		}
		$args = $prepared['args'];
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
			$dependencies = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_runtime_dependencies( $lifecycle, $args );
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
		$result = Static_Site_Importer_Prepared_Plan_Application::materialize( $prepared, $lifecycle, $companion_payload, $gutenberg_gaps, $theme_materialization );
		if ( is_array( $checkpoint ) ) {
			$checkpoint['workspace']->cleanup( 'success' );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::project_materialization_result( $result, $args );
	}

	/** Build the normal failed-import evidence for a producer-required policy rejection. */
	private static function failed_editability_admission( array $plan, array $args, array $compiled_import, array $receipt ): WP_Error {
		$artifacts = Static_Site_Importer_Failed_Plan_Validation::build( $plan, $args, is_array( $compiled_import['compiled'] ?? null ) ? $compiled_import['compiled'] : array() );
		try {
			Static_Site_Importer_Failed_Plan_Validation::persist( $artifacts, (string) ( $args['failed_plan_report_destination'] ?? '' ) );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'static_site_importer_failed_plan_report_persistence_failed', $error->getMessage(), $receipt );
		}
		$prefix = (string) ( $args['failed_plan_artifact_prefix'] ?? '' );
		$data   = array_merge(
			$receipt,
			$artifacts,
			array(
				'import_report_summary' => $artifacts['import_report_summary'],
				'failed_plan_artifacts' => Static_Site_Importer_Failed_Plan_Validation::artifact_refs( $prefix ),
			)
		);
		return new WP_Error( 'static_site_importer_quality_gate_failed', 'Website artifact did not pass the producer-required editability policy.', $data );
	}

	/** Project one completed canonical lifecycle into the compatibility result envelope. */
	private static function project_materialization_result( array $result, array $args ) {
		try {
			return self::public_result_from_wordpress_site_plan_receipt( $result['receipt'], $args, $result['lifecycle'], $result['dependencies'], $result['entities'] );
		} catch ( Throwable $error ) {
			$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::rollback_receipt( $result['receipt'], 'static_site_importer_projection_write_failed' );
			$receipt['status'] = 'partial';
			$receipt['errors'][] = array(
				'code'    => 'static_site_importer_projection_write_failed',
				'message' => $error->getMessage(),
			);
			$stage = 'report_persistence' === (string) ( $args['inject_materialization_failure'] ?? '' ) ? 'report_persistence' : 'public_projection';
			Static_Site_Importer_Entity_Compensation::append( $receipt, $result['lifecycle'], $result['entities'], $stage, 'static_site_importer_projection_write_failed' );
			return new WP_Error( 'static_site_importer_projection_write_failed', 'Website materialization completed partially because a public projection could not be written.', $receipt );
		}
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
			$compiled        = $compiler_result->toWordPressSitePlanView();
		}
		if ( 'blocks-engine/wordpress-site-plan-view/v1' !== ( $compiled['schema'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_invalid_transformer_result', 'Blocks Engine php-transformer returned an invalid WordPress site plan view.' );
		}
		$plan = is_array( $compiled['wordpress_site_plan'] ?? null ) ? $compiled['wordpress_site_plan'] : array();
		if ( empty( $plan ) ) {
			$diagnostics = is_array( $compiled['diagnostics'] ?? null ) ? wp_json_encode( $compiled['diagnostics'] ) : '';
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce a WordPress site plan.' . ( false !== $diagnostics ? ' ' . $diagnostics : '' ), $compiled );
		}
		// The compile boundary is the only seam holding both the canonical plan's
		// asset-to-route scopes and the source artifact; derive author-stylesheet
		// coverage findings here and let the report projection publish them.
		$args['missing_author_stylesheet_diagnostics'] = Static_Site_Importer_Report_Diagnostics::missing_author_stylesheet_diagnostics( $plan, $artifact );
		$companion_payload = null;
		$gutenberg_gaps    = is_array( $compiled['gutenberg_gaps'] ?? null ) ? $compiled['gutenberg_gaps'] : array();
		if ( ! empty( $compiled['companion_plugin_payload'] ) ) {
			$companion_payload = $compiled['companion_plugin_payload'];
			if ( ! is_array( $companion_payload ) ) {
				return new WP_Error( 'static_site_importer_companion_plugin_payload_invalid', 'Compiled companion_plugin_payload must be an object.' );
			}
			$companion_payload = Static_Site_Importer_Companion_Plugin::without_theme_owned_scripts( $companion_payload, is_array( $plan['assets'] ?? null ) ? $plan['assets'] : array() );
			if ( ! Static_Site_Importer_Companion_Plugin::has_materializable_content( $companion_payload ) ) {
				$companion_payload = null;
			} else {
				$companion_payload['site_slug'] = '' !== (string) ( $companion_payload['site_slug'] ?? '' ) ? (string) $companion_payload['site_slug'] : $args['slug'];
				$companion_payload['site_name'] = '' !== (string) ( $companion_payload['site_name'] ?? '' ) ? (string) $companion_payload['site_name'] : $args['name'];
				$companion_validation = Static_Site_Importer_Companion_Plugin::validate_payload( $companion_payload );
				if ( is_wp_error( $companion_validation ) ) {
					return $companion_validation;
				}
			}
		}
		if ( isset( $args['approved_classic_plan_identity'] ) && is_array( $args['approved_classic_plan_identity'] ) && ( $plan['plan_identity'] ?? null ) !== $args['approved_classic_plan_identity'] ) {
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
		$materialization_plan = array( 'theme' => array( 'font_materialization' => is_array( $compiled['font_materialization'] ?? null ) ? $compiled['font_materialization'] : array() ) );
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

	/**
	 * Project canonical materialization facts into the established public result envelope.
	 *
	 * @param array<string,mixed> $receipt  Materialization receipt.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>
	 */
	private static function public_result_from_wordpress_site_plan_receipt( array $receipt, array $args, array $lifecycle = array(), array $dependencies = array(), array $entities = array() ): array {
		$plan  = $receipt['plan'];
		$theme = $receipt['theme'];
		$projection = Static_Site_Importer_Receipt_Projection::compose(
			$receipt,
			$args,
			$lifecycle,
			$dependencies,
			$entities,
			self::import_run_id( $args ),
			self::transformer_provenance(),
			Static_Site_Importer_Build_Provenance::describe()
		);
		$report   = $projection['report'];
		$manifest = $projection['manifest'];
		if ( ! empty( $args['batch_import'] ) ) {
			$previous_manifest = self::read_source_of_truth_manifest( $theme['dir'] . '/static-site-importer-manifest.json' );
			$manifest          = Static_Site_Importer_Receipt_Projection::merge_previous_manifest( $manifest, $previous_manifest );
		}
		$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
		$manifest['existing_matches'] = $receipt['existing_matches'] ?? array( 'pages' => array() );
		$cleanup = self::cleanup_stale_generated_theme_files( $theme['dir'], $manifest, $args, $receipt );
		if ( is_wp_error( $cleanup ) ) {
			throw new RuntimeException( $cleanup->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The internal cleanup error is propagated as an exception message.
		}
		$manifest['cleanup'] = $cleanup;
		$final = Static_Site_Importer_Receipt_Projection::finalize( $report, $manifest, $plan, $receipt, $args, $quality );
		$validation = $final['validation'];
		$findings = $final['findings'];
		$theme_dir     = $theme['dir'];
		$manifest_path = $theme_dir . '/static-site-importer-manifest.json';
		self::write_plan_projection( $manifest_path, $manifest, $receipt );
		$report_path     = '';
		$validation_path = '';
		$findings_path   = '';
		if ( ! empty( $args['write_theme_report_artifacts'] ) ) {
			$report_path     = $theme_dir . '/import-report.json';
			$validation_path = $theme_dir . '/import-validation-result.json';
			$findings_path   = $theme_dir . '/finding-packets.json';
			self::write_plan_projection( $report_path, $report->to_array(), $receipt );
			self::write_plan_projection( $validation_path, $validation, $receipt );
			self::write_plan_projection( $findings_path, $findings, $receipt );
		}
		$external_report_path            = '';
		$external_validation_result_path = '';
		$external_finding_packets_path   = '';
		if ( '' !== trim( (string) ( $args['report'] ?? '' ) ) ) {
			$external_report_path            = (string) $args['report'];
			$external_dir                    = dirname( $external_report_path );
			$external_validation_result_path = trailingslashit( $external_dir ) . 'import-validation-result.json';
			$external_finding_packets_path   = trailingslashit( $external_dir ) . 'finding-packets.json';
			foreach ( array( $external_report_path, $external_validation_result_path, $external_finding_packets_path ) as $path ) {
				if ( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $path ) ) {
					throw new RuntimeException( 'External report destination changed after preflight.' );
				}
			}
			self::write_plan_projection( $external_report_path, $report->to_array(), $receipt );
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
			'import_report'                   => $report->to_array(),
			'import_report_summary'           => $report['compact_summary'],
			'import_validation_result'        => $validation,
			'finding_packets'                 => $findings,
			'fixture_diagnostics'             => $final['fixture_diagnostics'],
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
