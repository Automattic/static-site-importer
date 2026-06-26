<?php
/**
 * Codebox-backed validation product path contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Contract' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-contract.php';
}

/**
 * Builds and dispatches SSI validation requests for disposable Codebox runtimes.
 */
class Static_Site_Importer_Codebox_Validation {

	private const REQUEST_SCHEMA  = 'static-site-importer/codebox-validation-request/v1';
	private const RESULT_SCHEMA   = 'static-site-importer/codebox-validation-result/v1';
	private const ARTIFACT_SCHEMA = 'static-site-importer/codebox-validation-artifacts/v1';

	/**
	 * Register the default WP Codebox host-delegation bridge.
	 *
	 * @return void
	 */
	public static function register_default_provider(): void {
		add_filter( 'static_site_importer_codebox_validation_result', array( self::class, 'validate_in_current_codebox_runtime' ), 5, 3 );
		add_filter( 'static_site_importer_codebox_validation_result', array( self::class, 'delegate_to_wp_codebox_host_delegation' ), 10, 3 );
	}

	/**
	 * Run SSI validation directly when already executing inside a disposable Codebox runtime.
	 *
	 * @param mixed               $result  Existing provider result.
	 * @param array<string,mixed> $request SSI validation request.
	 * @param array<string,mixed> $input   Raw caller input.
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function validate_in_current_codebox_runtime( mixed $result, array $request, array $input ) {
		unset( $input );

		if ( null !== $result ) {
			return $result;
		}

		if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
			return null;
		}

		$source   = isset( $request['source'] ) && is_array( $request['source'] ) ? $request['source'] : array();
		$artifact = isset( $source['artifact'] ) && is_array( $source['artifact'] ) ? $source['artifact'] : array();
		if ( empty( $artifact ) ) {
			return null;
		}

		$import_args = isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array();
		$slug        = isset( $import_args['slug'] ) ? sanitize_title( (string) $import_args['slug'] ) : 'ssi-codebox-validation';
		if ( '' === $slug ) {
			$slug = 'ssi-codebox-validation';
		}

		$artifact_dir = self::local_validation_artifact_dir( $slug );
		if ( is_wp_error( $artifact_dir ) ) {
			return $artifact_dir;
		}

		$report_path = trailingslashit( $artifact_dir ) . 'import-report.json';
		$result      = Static_Site_Importer_Theme_Generator::import_website_artifact(
			$artifact,
			array_merge(
				$import_args,
				array(
					'report'          => $report_path,
					'source_metadata' => array_merge(
						isset( $import_args['source_metadata'] ) && is_array( $import_args['source_metadata'] ) ? $import_args['source_metadata'] : array(),
						array( 'codebox_validation_provider' => 'static-site-importer/current-runtime' )
					),
				)
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::provider_result_from_local_import( $result, $artifact_dir );
	}

	/**
	 * Delegate SSI validation requests to WP Codebox host providers.
	 *
	 * @param mixed               $result  Existing provider result.
	 * @param array<string,mixed> $request SSI validation request.
	 * @param array<string,mixed> $input   Raw caller input.
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function delegate_to_wp_codebox_host_delegation( mixed $result, array $request, array $input ) {
		if ( null !== $result ) {
			return $result;
		}

		$delegation_request       = self::host_delegation_request( $request, $input );
		$host_delegation_callback = array( 'WP_Codebox_Abilities', 'request_host_delegation' );
		$host_delegation_methods  = class_exists( 'WP_Codebox_Abilities' ) ? get_class_methods( 'WP_Codebox_Abilities' ) : array();
		if ( in_array( 'request_host_delegation', $host_delegation_methods, true ) && is_callable( $host_delegation_callback ) ) {
			$delegation_result = call_user_func( $host_delegation_callback, $delegation_request );
		} elseif ( function_exists( 'has_filter' ) && has_filter( 'wp_codebox_host_delegation_request' ) ) {
			$delegation_result = apply_filters( 'wp_codebox_host_delegation_request', null, $delegation_request );
		} else {
			return null;
		}

		if ( is_wp_error( $delegation_result ) ) {
			return $delegation_result;
		}

		return self::provider_result_from_host_delegation( $delegation_result );
	}

	/**
	 * Validate an imported/generated site in a disposable Codebox runtime.
	 *
	 * @param array<string,mixed> $input Validation input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate( array $input ) {
		$request = self::build_request( $input );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		/**
		 * Filters the SSI Codebox validation request before runtime dispatch.
		 *
		 * WP Codebox integrations may attach orchestration metadata here, but
		 * should not execute the run. Execution belongs to
		 * `static_site_importer_codebox_validation_result`.
		 *
		 * @param array<string,mixed> $request Validation request.
		 * @param array<string,mixed> $input   Raw caller input.
		 */
		$request = apply_filters( 'static_site_importer_codebox_validation_request', $request, $input );

		/**
		 * Executes an SSI validation request in a disposable Codebox runtime.
		 *
		 * The provider should import the supplied website artifact or generated theme
		 * output into the sandbox, run SSI import validation, run block validation,
		 * collect browser/render evidence, and return durable artifact references.
		 * Return null when no provider is available.
		 *
		 * @param array<string,mixed>|WP_Error|null $result  Provider result.
		 * @param array<string,mixed>               $request Validation request.
		 * @param array<string,mixed>               $input   Raw caller input.
		 */
		$result = apply_filters( 'static_site_importer_codebox_validation_result', null, $request, $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( null === $result ) {
			return self::provider_unavailable_result( $request );
		}

		return self::normalize_provider_result( $request, $result );
	}

	/**
	 * Build a structured validation result for failures that happen before provider output exists.
	 *
	 * @param WP_Error            $error Validation error.
	 * @param array<string,mixed> $input Raw caller input.
	 * @return array<string,mixed>
	 */
	public static function error_result_from_wp_error( WP_Error $error, array $input = array() ): array {
		$request = self::build_error_request_summary( $input );
		$status  = 'static_site_importer_codebox_validation_source_missing' === $error->get_error_code() ? 'blocked' : 'failed';

		$result = array(
			'success'       => false,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => $status,
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => $request,
			'artifacts'     => self::artifact_contract( array( 'source' => array() ), array() ),
			'runtime'       => array(),
			'summary'       => array(
				'quality_pass' => false,
				'error_code'   => $error->get_error_code(),
			),
			'upstream_gaps' => array(),
		);

		$result['fixture_diagnostics'] = self::fixture_diagnostics(
			array(
				'slug'        => isset( $request['import_args']['slug'] ) ? (string) $request['import_args']['slug'] : '',
				'name'        => isset( $request['import_args']['name'] ) ? (string) $request['import_args']['name'] : '',
				'request'     => $request,
				'status'      => $status,
				'success'     => false,
				'artifacts'   => $result['artifacts'],
				'diagnostics' => array(
					array(
						'type'        => 'validation_request_error',
						'severity'    => 'error',
						'code'        => $error->get_error_code(),
						'reason_code' => $error->get_error_code(),
						'message'     => $error->get_error_message(),
						'stage'       => 'request_validation',
						'owner'       => 'static-site-importer',
					),
				),
			)
		);

		return $result;
	}

	/**
	 * Build the runtime request envelope.
	 *
	 * @param array<string,mixed> $input Validation input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function build_request( array $input ) {
		$artifact            = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		$generated_theme_ref = self::normalize_artifact_ref( $input['generated_theme_ref'] ?? array() );
		$theme_archive_ref   = self::normalize_artifact_ref( $input['theme_archive_ref'] ?? array() );

		if ( empty( $artifact ) && empty( $generated_theme_ref ) && empty( $theme_archive_ref ) ) {
			return new WP_Error( 'static_site_importer_codebox_validation_source_missing', 'Codebox validation requires an artifact, generated_theme_ref, or theme_archive_ref input.' );
		}

		$import_args = array(
			'slug'                         => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
			'name'                         => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
			'activate'                     => array_key_exists( 'activate', $input ) ? (bool) $input['activate'] : true,
			'overwrite'                    => array_key_exists( 'overwrite', $input ) ? (bool) $input['overwrite'] : true,
			'fail_on_quality'              => ! empty( $input['fail_on_quality'] ),
			'allow_missing_woocommerce'    => ! empty( $input['allow_missing_woocommerce'] ),
			'asset_materialization_policy' => isset( $input['asset_materialization_policy'] ) ? (string) $input['asset_materialization_policy'] : '',
			'compiler_options'             => isset( $input['compiler_options'] ) && is_array( $input['compiler_options'] ) ? $input['compiler_options'] : array(),
			'source_metadata'              => isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
		);

		return array(
			'schema'             => self::REQUEST_SCHEMA,
			'product_path'       => 'static-site-importer/validate-in-codebox',
			'execution_scope'    => 'disposable-wp-codebox-runtime',
			'source'             => array(
				'artifact'            => $artifact,
				'generated_theme_ref' => $generated_theme_ref,
				'theme_archive_ref'   => $theme_archive_ref,
			),
			'import_args'        => array_filter(
				$import_args,
				static fn ( mixed $value ): bool => ! ( '' === $value || array() === $value )
			),
			'validation'         => array(
				'import_report'    => true,
				'block_validation' => true,
				'browser_render'   => true,
				'screenshots'      => true,
				'visual_diff'      => true,
			),
			'required_artifacts' => self::required_artifacts(),
			'operator_notes'     => self::operator_notes( $input ),
		);
	}

	/**
	 * Build a WP Codebox host-delegation request for SSI validation.
	 *
	 * @param array<string,mixed> $request SSI validation request.
	 * @param array<string,mixed> $input   Raw caller input.
	 * @return array<string,mixed>
	 */
	private static function host_delegation_request( array $request, array $input ): array {
		return array(
			'schema'     => 'wp-codebox/host-delegation-request/v1',
			'request_id' => self::request_id(),
			'task'       => 'static-site-importer.validate-in-codebox',
			'task_input' => $request,
			'execution'  => array(
				'kind'         => 'runtime-validation',
				'product_path' => 'static-site-importer/validate-in-codebox',
			),
			'metadata'   => array(
				'product'        => 'static-site-importer',
				'request_schema' => self::REQUEST_SCHEMA,
				'input_keys'     => array_map( 'strval', array_keys( $input ) ),
			),
		);
	}

	/**
	 * Normalize WP Codebox host-delegation output into the SSI provider shape.
	 *
	 * @param mixed $delegation_result WP Codebox host delegation result.
	 * @return array<string,mixed>|WP_Error|null
	 */
	private static function provider_result_from_host_delegation( mixed $delegation_result ) {
		if ( null === $delegation_result ) {
			return null;
		}

		if ( ! is_array( $delegation_result ) ) {
			return new WP_Error( 'static_site_importer_codebox_host_delegation_result_invalid', 'WP Codebox host delegation providers must return an array, WP_Error, or null.' );
		}

		if ( 'wp-codebox/host-delegation-result/v1' !== (string) ( $delegation_result['schema'] ?? '' ) ) {
			return $delegation_result;
		}

		$status = sanitize_key( (string) ( $delegation_result['status'] ?? '' ) );
		if ( 'unavailable' === $status ) {
			return null;
		}

		$result    = isset( $delegation_result['result'] ) && is_array( $delegation_result['result'] ) ? $delegation_result['result'] : array();
		$artifacts = isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : ( isset( $delegation_result['artifacts'] ) && is_array( $delegation_result['artifacts'] ) ? $delegation_result['artifacts'] : array() );
		$summary   = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();

		$provider_result = array(
			'success'   => ! empty( $delegation_result['success'] ),
			'status'    => self::provider_status_from_host_delegation_status( $status, ! empty( $delegation_result['success'] ) ),
			'runtime'   => array(
				'provider'                => (string) ( $delegation_result['provider'] ?? 'wp-codebox/host-delegation' ),
				'host_delegation_status'  => $status,
				'host_delegation_request' => (string) ( $delegation_result['request_id'] ?? '' ),
			),
			'summary'   => $summary,
			'artifacts' => $artifacts,
		);

		if ( ! empty( $result['upstream_gaps'] ) && is_array( $result['upstream_gaps'] ) ) {
			$provider_result['upstream_gaps'] = array_values( $result['upstream_gaps'] );
		} elseif ( empty( $provider_result['success'] ) && ! empty( $delegation_result['error'] ) ) {
			$provider_result['upstream_gaps'] = array( $delegation_result['error'] );
		}

		return $provider_result;
	}

	/**
	 * Convert local SSI import output into provider result shape.
	 *
	 * @param array<string,mixed> $import_result Local importer result.
	 * @param string              $artifact_dir  Directory containing validation artifacts.
	 * @return array<string,mixed>
	 */
	private static function provider_result_from_local_import( array $import_result, string $artifact_dir ): array {
		$report_path            = (string) ( $import_result['external_report_path'] ?? $import_result['report_path'] ?? '' );
		$validation_result_path = (string) ( $import_result['external_validation_result_path'] ?? $import_result['validation_result_path'] ?? '' );
		$finding_packets_path   = (string) ( $import_result['external_finding_packets_path'] ?? $import_result['finding_packets_path'] ?? '' );
		$quality                = isset( $import_result['quality'] ) && is_array( $import_result['quality'] ) ? $import_result['quality'] : array();
		$quality_pass           = ! empty( $quality['pass'] );

		return array(
			'success'       => $quality_pass,
			'status'        => $quality_pass ? 'succeeded' : 'failed',
			'runtime'       => array(
				'provider'     => 'static-site-importer/current-codebox-runtime',
				'status'       => 'completed',
				'artifact_dir' => basename( $artifact_dir ),
			),
			'summary'       => array(
				'quality_pass'          => $quality_pass,
				'import_report'         => is_readable( $report_path ) ? 'captured' : 'missing',
				'block_validation'      => is_readable( $validation_result_path ) ? 'captured' : 'missing',
				'browser_render'        => 'pending',
				'screenshot_artifacts'  => 0,
				'visual_diff_artifacts' => 0,
				'theme_slug'            => (string) ( $import_result['theme_slug'] ?? '' ),
			),
			'import_report' => self::read_json_object_file( $report_path ),
			'artifacts'     => array(
				'generated_theme'         => array(
					'artifact_ref' => (string) ( $import_result['theme_slug'] ?? '' ),
					'kind'         => 'wordpress-theme-directory',
					'status'       => 'materialized',
				),
				'import_report'           => self::local_file_artifact_ref( $report_path, 'blocks-engine/import-report' ),
				'block_validation_result' => self::local_file_artifact_ref( $validation_result_path, 'blocks-engine/import-validation-result' ),
				'raw'                     => array_filter(
					array(
						self::local_file_artifact_ref( $finding_packets_path, 'blocks-engine/finding-packets' ),
					)
				),
			),
		);
	}

	/**
	 * Create a per-validation artifact directory in uploads.
	 *
	 * @param string $slug Fixture/import slug.
	 * @return string|WP_Error
	 */
	private static function local_validation_artifact_dir( string $slug ) {
		$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : sys_get_temp_dir();
		$run_id     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'validation-', true );
		$directory  = trailingslashit( $base_dir ) . 'static-site-importer/codebox-validation-' . sanitize_title( $slug ) . '-' . sanitize_key( $run_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone smoke-test fallback when WordPress filesystem helpers are unavailable.
		$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : ( is_dir( $directory ) || mkdir( $directory, 0777, true ) );
		if ( ! $created ) {
			return new WP_Error( 'static_site_importer_codebox_validation_artifact_dir_failed', 'Could not create Codebox validation artifact directory.' );
		}

		return $directory;
	}

	/**
	 * Build a reviewer-safe artifact reference for a local artifact file.
	 *
	 * @param string $path Local file path.
	 * @param string $kind Artifact kind.
	 * @return array<string,string>
	 */
	private static function local_file_artifact_ref( string $path, string $kind ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}

		return array(
			'artifact_ref' => basename( $path ),
			'kind'         => $kind,
		);
	}

	/**
	 * Read a JSON object file.
	 *
	 * @param string $path JSON file path.
	 * @return array<string,mixed>
	 */
	private static function read_json_object_file( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer-owned validation artifact.
		$data = json_decode( false === $json ? '' : $json, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Map WP Codebox host-delegation statuses to SSI provider statuses.
	 *
	 * @param string $status  Host-delegation status.
	 * @param bool   $success Whether host delegation succeeded.
	 * @return string
	 */
	private static function provider_status_from_host_delegation_status( string $status, bool $success ): string {
		if ( 'completed' === $status || ( $success && '' === $status ) ) {
			return 'succeeded';
		}

		if ( 'accepted' === $status ) {
			return 'running';
		}

		return $success ? 'succeeded' : 'failed';
	}

	/**
	 * Create a stable-enough request id for host delegation.
	 *
	 * @return string
	 */
	private static function request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'ssi-codebox-', true );
	}

	/**
	 * Normalize provider output into the SSI result contract.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @param array<string,mixed> $result  Provider result.
	 * @return array<string,mixed>
	 */
	private static function normalize_provider_result( array $request, array $result ): array {
		$status    = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : ( ! empty( $result['success'] ) ? 'succeeded' : 'failed' );
		$artifacts = self::artifact_contract( $request, isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : array() );
		$output    = array(
			'success'       => 'succeeded' === $status,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => $status,
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => self::request_summary( $request ),
			'artifacts'     => $artifacts,
			'runtime'       => isset( $result['runtime'] ) && is_array( $result['runtime'] ) ? $result['runtime'] : array(),
			'summary'       => isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array(),
			'upstream_gaps' => isset( $result['upstream_gaps'] ) && is_array( $result['upstream_gaps'] ) ? array_values( $result['upstream_gaps'] ) : array(),
		);

		$output['fixture_diagnostics'] = self::fixture_diagnostics(
			array_merge(
				$result,
				array(
					'status'    => $status,
					'success'   => $output['success'],
					'request'   => $output['request'],
					'artifacts' => $artifacts,
				)
			)
		);

		return $output;
	}

	/**
	 * Build a concrete blocked result when no runtime provider is registered.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @return array<string,mixed>
	 */
	private static function provider_unavailable_result( array $request ): array {
		$result = array(
			'success'       => false,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => 'blocked',
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => self::request_summary( $request ),
			'artifacts'     => self::artifact_contract( $request, array() ),
			'runtime'       => array(
				'provider' => 'wp-codebox',
				'status'   => 'missing_provider',
			),
			'summary'       => array(
				'quality_pass'          => false,
				'import_report'         => 'missing',
				'block_validation'      => 'missing',
				'browser_render'        => 'missing',
				'screenshot_artifacts'  => 0,
				'visual_diff_artifacts' => 0,
			),
			'upstream_gaps' => array(
				array(
					'owner'        => 'wp-codebox',
					'capability'   => 'static-site-importer Codebox validation provider',
					'missing'      => 'No provider is registered on static_site_importer_codebox_validation_result to run the SSI import, block validation, browser render capture, screenshot capture, and visual diff capture inside a disposable WP Codebox runtime.',
					'needed_shape' => 'Accept static-site-importer/codebox-validation-request/v1 and return static-site-importer/codebox-validation-result/v1 with durable artifact references for generated theme/archive, import report, block validation result, browser/render evidence metadata, screenshots, and diffs.',
				),
			),
		);

		$result['fixture_diagnostics'] = self::fixture_diagnostics(
			array(
				'status'        => 'blocked',
				'success'       => false,
				'request'       => $result['request'],
				'artifacts'     => $result['artifacts'],
				'summary'       => $result['summary'],
				'upstream_gaps' => $result['upstream_gaps'],
				'diagnostics'   => array(
					array(
						'type'        => 'runtime_provider_unavailable',
						'severity'    => 'error',
						'code'        => 'missing_provider',
						'reason_code' => 'missing_provider',
						'message'     => 'No Codebox validation provider is registered.',
						'stage'       => 'runtime_dispatch',
						'owner'       => 'wp-codebox',
					),
				),
			)
		);

		return $result;
	}

	/**
	 * Build the importer-owned diagnostics envelope.
	 *
	 * @param array<string,mixed> $result Provider or synthesized result.
	 * @return array<string,mixed>
	 */
	private static function fixture_diagnostics( array $result ): array {
		return Static_Site_Importer_Diagnostic_Contract::build( $result );
	}

	/**
	 * Build a request summary from raw input when request creation failed.
	 *
	 * @param array<string,mixed> $input Raw caller input.
	 * @return array<string,mixed>
	 */
	private static function build_error_request_summary( array $input ): array {
		return array(
			'schema'              => self::REQUEST_SCHEMA,
			'product_path'        => 'static-site-importer/validate-in-codebox',
			'execution_scope'     => 'disposable-wp-codebox-runtime',
			'has_inline_artifact' => isset( $input['artifact'] ) && is_array( $input['artifact'] ) && ! empty( $input['artifact'] ),
			'artifact_schema'     => isset( $input['artifact']['schema'] ) && is_scalar( $input['artifact']['schema'] ) ? (string) $input['artifact']['schema'] : '',
			'import_args'         => array_filter(
				array(
					'slug' => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
					'name' => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '',
				),
				static fn ( string $value ): bool => '' !== $value
			),
			'required_artifacts'  => self::required_artifacts(),
		);
	}

	/**
	 * Build the artifact metadata contract.
	 *
	 * @param array<string,mixed> $request   Validation request.
	 * @param array<string,mixed> $artifacts Provider artifacts.
	 * @return array<string,mixed>
	 */
	private static function artifact_contract( array $request, array $artifacts ): array {
		return array(
			'schema'                  => self::ARTIFACT_SCHEMA,
			'generated_theme'         => self::first_ref( $artifacts['generated_theme'] ?? array(), $request['source']['generated_theme_ref'] ?? array() ),
			'theme_archive'           => self::first_ref( $artifacts['theme_archive'] ?? array(), $request['source']['theme_archive_ref'] ?? array() ),
			'import_report'           => self::normalize_artifact_ref( $artifacts['import_report'] ?? array() ),
			'block_validation_result' => self::normalize_artifact_ref( $artifacts['block_validation_result'] ?? array() ),
			'browser_render_evidence' => self::normalize_artifact_ref( $artifacts['browser_render_evidence'] ?? array() ),
			'screenshots'             => self::normalize_artifact_refs( $artifacts['screenshots'] ?? array() ),
			'diffs'                   => self::normalize_artifact_refs( $artifacts['diffs'] ?? array() ),
			'raw'                     => self::normalize_artifact_refs( $artifacts['raw'] ?? array() ),
			'required'                => self::required_artifacts(),
		);
	}

	/**
	 * Required validation artifacts.
	 *
	 * @return array<int,string>
	 */
	private static function required_artifacts(): array {
		return array(
			'generated_theme_or_theme_archive',
			'import_report',
			'block_validation_result',
			'browser_render_evidence',
			'screenshot_refs_when_available',
			'diff_refs_when_available',
		);
	}

	/**
	 * Summarize a request without inline artifact content.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @return array<string,mixed>
	 */
	private static function request_summary( array $request ): array {
		$source   = isset( $request['source'] ) && is_array( $request['source'] ) ? $request['source'] : array();
		$artifact = isset( $source['artifact'] ) && is_array( $source['artifact'] ) ? $source['artifact'] : array();

		return array(
			'schema'              => $request['schema'] ?? self::REQUEST_SCHEMA,
			'product_path'        => $request['product_path'] ?? 'static-site-importer/validate-in-codebox',
			'execution_scope'     => $request['execution_scope'] ?? 'disposable-wp-codebox-runtime',
			'has_inline_artifact' => ! empty( $artifact ),
			'artifact_schema'     => isset( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'import_args'         => isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array(),
			'required_artifacts'  => isset( $request['required_artifacts'] ) && is_array( $request['required_artifacts'] ) ? array_values( $request['required_artifacts'] ) : self::required_artifacts(),
		);
	}

	/**
	 * Preserve local-only paths only as operator notes.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	private static function operator_notes( array $input ): array {
		$notes = array();
		foreach ( array( 'generated_theme_ref', 'theme_archive_ref' ) as $field ) {
			if ( empty( $input[ $field ] ) || ! is_array( $input[ $field ] ) ) {
				continue;
			}

			foreach ( array( 'path', 'local_path', 'file' ) as $key ) {
				$value = isset( $input[ $field ][ $key ] ) ? (string) $input[ $field ][ $key ] : '';
				if ( self::is_local_only_reference( $value ) ) {
					$notes['local_refs'][ $field ][ $key ] = $value;
				}
			}
		}

		return $notes;
	}

	/**
	 * Normalize a list of artifact references.
	 *
	 * @param mixed $refs Raw refs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_artifact_refs( mixed $refs ): array {
		if ( ! is_array( $refs ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $refs as $ref ) {
			$normalized_ref = self::normalize_artifact_ref( $ref );
			if ( ! empty( $normalized_ref ) ) {
				$normalized[] = $normalized_ref;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize an artifact reference for reviewer-facing output.
	 *
	 * @param mixed $ref Raw ref.
	 * @return array<string,mixed>
	 */
	private static function normalize_artifact_ref( mixed $ref ): array {
		if ( ! is_array( $ref ) ) {
			return array();
		}

		$allowed = array( 'artifact_id', 'artifact_ref', 'artifact_name', 'url', 'path', 'relative_path', 'sha256', 'media_type', 'label', 'kind', 'status' );
		$output  = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $ref ) ) {
				continue;
			}

			$value = is_scalar( $ref[ $key ] ) ? (string) $ref[ $key ] : $ref[ $key ];
			if ( is_string( $value ) && self::is_local_only_reference( $value ) ) {
				continue;
			}

			$output[ $key ] = $value;
		}

		return $output;
	}

	/**
	 * Return the first non-empty normalized ref.
	 *
	 * @param mixed $primary  Primary ref.
	 * @param mixed $fallback Fallback ref.
	 * @return array<string,mixed>
	 */
	private static function first_ref( mixed $primary, mixed $fallback ): array {
		$primary_ref = self::normalize_artifact_ref( $primary );
		if ( ! empty( $primary_ref ) ) {
			return $primary_ref;
		}

		return self::normalize_artifact_ref( $fallback );
	}

	/**
	 * Detect host-local paths and URLs that should not be reviewer-facing refs.
	 *
	 * @param string $value Reference value.
	 * @return bool
	 */
	private static function is_local_only_reference( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		return str_starts_with( $value, '/' )
			|| str_starts_with( $value, 'file:' )
			|| str_contains( $value, 'localhost' )
			|| str_contains( $value, '127.0.0.1' );
	}
}
