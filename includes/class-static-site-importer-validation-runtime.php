<?php
/**
 * Static Site Importer validation runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Website_Artifact_Import_Input' ) ) {
	require_once __DIR__ . '/class-static-site-importer-website-artifact-import-input.php';
}

/**
 * Runs SSI import validation in the current WordPress runtime.
 */
class Static_Site_Importer_Validation_Runtime {

	public const RESULT_SCHEMA                    = 'static-site-importer/import-validation-result/v1';
	public const FIXTURE_MATRIX_RESULT_SCHEMA     = 'static-site-importer/fixture-matrix-validation-result/v1';
	public const LIFECYCLE_ARTIFACT_DIGEST_SCHEMA = 'static-site-importer/lifecycle-artifact-digest/v1';

	/**
	 * Project the full validation result into the bounded fixture-matrix contract.
	 *
	 * The full import report can exceed runtime command-capture limits. Matrix
	 * consumers need actionable diagnostics and attribution evidence, not the raw
	 * materialized documents carried by that report.
	 *
	 * @param array<string,mixed> $result Full validation result.
	 * @return array<string,mixed>
	 */
	public static function fixture_matrix_result( array $result ): array {
		$fixture_diagnostics = isset( $result['fixture_diagnostics'] ) && is_array( $result['fixture_diagnostics'] )
			? $result['fixture_diagnostics']
			: Static_Site_Importer_Diagnostic_Contract::build( $result );

		$diagnostics = isset( $fixture_diagnostics['diagnostics'] ) && is_array( $fixture_diagnostics['diagnostics'] )
			? $fixture_diagnostics['diagnostics']
			: array();
		unset(
			$fixture_diagnostics['diagnostics'],
			$fixture_diagnostics['runtime_dependency_target_gaps'],
			$fixture_diagnostics['asset_diagnostics'],
			$fixture_diagnostics['svg_diagnostics'],
			$fixture_diagnostics['button_style_loss_hints']
		);

		return array(
			'schema'              => self::FIXTURE_MATRIX_RESULT_SCHEMA,
			'fixture_id'          => isset( $result['fixture_id'] ) && is_scalar( $result['fixture_id'] ) ? (string) $result['fixture_id'] : '',
			'status'              => isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : '',
			'success'             => ! empty( $result['success'] ),
			'quality'             => array( 'pass' => ! empty( $result['success'] ) ),
			'diagnostics'         => $diagnostics,
			'fixture_diagnostics' => $fixture_diagnostics,
			'artifacts'           => isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : array(),
		);
	}

	/**
	 * Validate a website artifact in the current runtime.
	 *
	 * @param array<string,mixed> $input         Validation input.
	 * @param string              $invocation_id Server-owned identity for this invocation.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate_artifact( array $input, string $invocation_id = '' ) {
		$artifact = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		if ( empty( $artifact ) ) {
			return new WP_Error( 'static_site_importer_validation_artifact_missing', 'Validation requires an artifact JSON object.' );
		}

		$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : 'static-site-importer-validation';
		if ( '' === $slug ) {
			$slug = 'static-site-importer-validation';
		}

		$artifact_dir = self::artifact_dir( $input, $slug );
		if ( is_wp_error( $artifact_dir ) ) {
			return $artifact_dir;
		}

		$input['slug']            = $slug;
		$input['name']            = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : $slug;
		$input['report']          = trailingslashit( $artifact_dir ) . 'import-report.json';
		$input['source_metadata'] = array_merge(
			isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
			array( 'validation_provider' => 'static-site-importer/current-runtime' )
		);
		$import_args              = Static_Site_Importer_Website_Artifact_Import_Input::normalize(
			$input,
			array(
				'activate'                             => true,
				'overwrite'                            => true,
				'materialize_dependencies'             => true,
				'require_proven_dynamic_client_assets' => true,
				'report'                               => (string) $input['report'],
			)
		);
		if ( isset( $input['runtime_lifecycle_phase'] ) ) {
			$import_args['runtime_lifecycle_phase'] = (string) $input['runtime_lifecycle_phase'];
		}
		if ( isset( $input['runtime_lifecycle_request_id'] ) ) {
			$import_args['runtime_lifecycle_request_id'] = (string) $input['runtime_lifecycle_request_id'];
		}
		$import_args['runtime_lifecycle_invocation_id'] = self::invocation_id( $invocation_id );

		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::result_from_import( $result, $artifact_dir, $import_args );
	}

	/**
	 * Prepare plugin dependencies in this request; callers resume validation in another request.
	 *
	 * @param array<string,mixed> $input         Validation input.
	 * @param string              $invocation_id Server-owned identity for this invocation.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function prepare_artifact_dependencies( array $input, string $invocation_id = '' ) {
		$input['runtime_lifecycle_phase']  = 'prepare';
		$input['materialize_dependencies'] = true;
		$artifact                          = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		if ( empty( $artifact ) ) {
			return new WP_Error( 'static_site_importer_validation_artifact_missing', 'Dependency preparation requires an artifact JSON object.' );
		}
		$slug                                   = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : 'static-site-importer-validation';
		$input['slug']                          = '' === $slug ? 'static-site-importer-validation' : $slug;
		$input['name']                          = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : $input['slug'];
		$import_args                            = Static_Site_Importer_Website_Artifact_Import_Input::normalize(
			$input,
			array(
				'activate'                 => true,
				'overwrite'                => true,
				'materialize_dependencies' => true,
			)
		);
		$import_args['runtime_lifecycle_phase'] = 'prepare';
		$import_args['runtime_lifecycle_invocation_id'] = self::invocation_id( $invocation_id );
		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$encoded_artifact = wp_json_encode( $artifact );
		return array(
			'schema'            => 'static-site-importer/runtime-lifecycle-receipt/v1',
			'status'            => (string) ( $result['status'] ?? 'failed' ),
			'artifact_sha256'   => hash( 'sha256', false !== $encoded_artifact ? $encoded_artifact : '' ),
			'slug'              => $input['slug'],
			'fresh_runtime'     => $result['fresh_runtime'] ?? array(),
			'dependencies'      => $result['dependencies'] ?? array(),
			'runtime_lifecycle' => $result['runtime_lifecycle'] ?? array(),
		);
	}

	/**
	 * Build a streaming digest for the exact JSON file handed between CLI lifecycle steps.
	 *
	 * This supplements, but does not replace, the canonical artifact_sha256 receipt field.
	 *
	 * @param string $artifact_path Artifact JSON file path.
	 * @return array<string,string>|WP_Error
	 */
	public static function lifecycle_artifact_digest_from_file( string $artifact_path ) {
		$digest = is_readable( $artifact_path ) ? hash_file( 'sha256', $artifact_path ) : false;
		if ( ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_artifact_digest_missing', 'The lifecycle artifact digest could not be calculated.' );
		}

		return array(
			'schema'         => self::LIFECYCLE_ARTIFACT_DIGEST_SCHEMA,
			'representation' => 'artifact-json-file-bytes',
			'sha256'         => $digest,
		);
	}

	/**
	 * Verify that a lifecycle receipt binds to the supplied artifact.
	 *
	 * Versioned file digests are authoritative for newer CLI receipts and avoid
	 * serializing the decoded artifact a second time. Receipts without one retain
	 * the original canonical wp_json_encode() identity for compatibility.
	 *
	 * @param array<string,mixed> $receipt       Lifecycle receipt.
	 * @param string              $artifact_path Artifact JSON file path.
	 * @param array<string,mixed> $artifact      Decoded artifact for legacy receipts.
	 */
	public static function lifecycle_receipt_matches_artifact( array $receipt, string $artifact_path, array $artifact ): bool {
		if ( isset( $receipt['artifact_digest'] ) ) {
			$digest = $receipt['artifact_digest'];
			if ( ! is_array( $digest ) || self::LIFECYCLE_ARTIFACT_DIGEST_SCHEMA !== ( $digest['schema'] ?? '' ) || 'artifact-json-file-bytes' !== ( $digest['representation'] ?? '' ) || ! is_string( $digest['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $digest['sha256'] ) ) {
				return false;
			}

			$current_digest = self::lifecycle_artifact_digest_from_file( $artifact_path );

			return ! is_wp_error( $current_digest ) && hash_equals( $digest['sha256'], $current_digest['sha256'] );
		}

		// v1 receipts predate file-byte digests and retain their canonical identity.
		$encoded_artifact = wp_json_encode( $artifact );
		$artifact_hash    = false !== $encoded_artifact ? hash( 'sha256', $encoded_artifact ) : '';

		return is_string( $receipt['artifact_sha256'] ?? null ) && hash_equals( $receipt['artifact_sha256'], $artifact_hash );
	}

	/** Resolve the server-owned identity for one validation invocation. */
	private static function invocation_id( string $invocation_id ): string {
		return '' !== $invocation_id ? $invocation_id : wp_generate_uuid4();
	}

	/** Build a registry-derived dependency plan without installing packages. */
	public static function plan_artifact_dependencies( array $input ) {
		$artifact = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		if ( empty( $artifact ) ) {
			return new WP_Error( 'static_site_importer_validation_artifact_missing', 'Dependency planning requires an artifact JSON object.' );
		}
		$input['runtime_lifecycle_phase']  = 'plan';
		$input['materialize_dependencies'] = false;
		$slug                              = sanitize_title( (string) ( $input['slug'] ?? 'static-site-importer-validation' ) );
		$input['slug']                     = '' !== $slug ? $slug : 'static-site-importer-validation';
		$input['name']                     = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : $input['slug'];
		$args                              = Static_Site_Importer_Website_Artifact_Import_Input::normalize(
			$input,
			array(
				'activate'                 => true,
				'overwrite'                => true,
				'materialize_dependencies' => false,
			)
		);
		$args['runtime_lifecycle_phase']   = 'plan';
		return Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
	}

	/**
	 * Convert a WP_Error into the validation result shape.
	 *
	 * @param WP_Error            $error Validation error.
	 * @param array<string,mixed> $input Raw validation input.
	 * @return array<string,mixed>
	 */
	public static function error_result_from_wp_error( WP_Error $error, array $input = array() ): array {
		$slug       = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		$error_data = $error->get_error_data();
		$receipt    = is_array( $error_data ) && 'static-site-importer/materialization-receipt/v1' === ( $error_data['schema'] ?? '' ) ? $error_data : array();
		$diagnostic = array(
			'type'        => 'validation_error',
			'severity'    => 'error',
			'code'        => $error->get_error_code(),
			'reason_code' => $error->get_error_code(),
			'message'     => $error->get_error_message(),
			'stage'       => 'validation',
			'owner'       => 'static-site-importer',
		);
		if ( is_array( $error_data ) && isset( $error_data['dependency_reports'] ) && is_array( $error_data['dependency_reports'] ) ) {
			$encoded = wp_json_encode( array( 'dependency_reports' => $error_data['dependency_reports'] ) );
			if ( is_string( $encoded ) ) {
				$diagnostic['observed_output'] = substr( $encoded, 0, 4000 );
			}
		}
		$result                        = array(
			'success'                 => false,
			'schema'                  => self::RESULT_SCHEMA,
			'status'                  => 'failed',
			'fixture_id'              => $slug,
			'request'                 => array(
				'import_args' => array_filter(
					array(
						'slug' => $slug,
						'name' => isset( $input['name'] ) ? (string) $input['name'] : '',
					)
				),
			),
			'summary'                 => array(
				'quality_pass' => false,
				'error_code'   => $error->get_error_code(),
			),
			'diagnostics'             => array( $diagnostic ),
			'materialization_receipt' => $receipt,
			'artifacts'               => array(),
			'import_report'           => array(),
		);
		$result['fixture_diagnostics'] = Static_Site_Importer_Diagnostic_Contract::build( $result );
		$result['diagnostics']         = isset( $result['fixture_diagnostics']['diagnostics'] ) && is_array( $result['fixture_diagnostics']['diagnostics'] ) ? $result['fixture_diagnostics']['diagnostics'] : array();
		$result['diagnostic_summary']  = isset( $result['fixture_diagnostics']['diagnostic_summary'] ) && is_array( $result['fixture_diagnostics']['diagnostic_summary'] ) ? $result['fixture_diagnostics']['diagnostic_summary'] : array();

		return $result;
	}

	/**
	 * Build the result envelope from importer output.
	 *
	 * @param array<string,mixed> $import_result Import result.
	 * @param string              $artifact_dir  Artifact directory.
	 * @param array<string,mixed> $import_args   Import args.
	 * @return array<string,mixed>
	 */
	private static function result_from_import( array $import_result, string $artifact_dir, array $import_args ): array {
		$report_path            = (string) ( $import_result['external_report_path'] ?? $import_result['report_path'] ?? '' );
		$validation_result_path = (string) ( $import_result['external_validation_result_path'] ?? $import_result['validation_result_path'] ?? '' );
		$finding_packets_path   = (string) ( $import_result['external_finding_packets_path'] ?? $import_result['finding_packets_path'] ?? '' );
		$quality                = isset( $import_result['quality'] ) && is_array( $import_result['quality'] ) ? $import_result['quality'] : array();
		$quality_pass           = ! empty( $quality['pass'] );
		$import_report          = self::read_json_object_file( $report_path );
		if ( empty( $import_report ) && isset( $import_result['import_report'] ) && is_array( $import_result['import_report'] ) ) {
			$import_report = $import_result['import_report'];
		}

		$result                        = array(
			'success'                 => $quality_pass,
			'schema'                  => self::RESULT_SCHEMA,
			'status'                  => $quality_pass ? 'passed' : 'failed',
			'fixture_id'              => (string) ( $import_args['slug'] ?? '' ),
			'request'                 => array( 'import_args' => $import_args ),
			'runtime'                 => array(
				'provider'     => 'static-site-importer/current-runtime',
				'status'       => 'completed',
				'artifact_dir' => basename( $artifact_dir ),
			),
			'summary'                 => array(
				'quality_pass'     => $quality_pass,
				'import_report'    => is_readable( $report_path ) ? 'captured' : 'missing',
				'block_validation' => is_readable( $validation_result_path ) ? 'captured' : 'missing',
				'theme_slug'       => (string) ( $import_result['theme_slug'] ?? '' ),
			),
			'import_report'           => $import_report,
			'materialization_receipt' => isset( $import_result['materialization_receipt'] ) && is_array( $import_result['materialization_receipt'] ) ? $import_result['materialization_receipt'] : array(),
			'artifacts'               => array(
				'generated_theme'         => array(
					'artifact_ref' => (string) ( $import_result['theme_slug'] ?? '' ),
					'kind'         => 'wordpress-theme-directory',
					'status'       => 'materialized',
				),
				'import_report'           => self::local_file_artifact_ref( $report_path, 'static-site-importer/import-report' ),
				'block_validation_result' => self::local_file_artifact_ref( $validation_result_path, 'static-site-importer/import-validation-result' ),
				'finding_packets'         => self::local_file_artifact_ref( $finding_packets_path, 'static-site-importer/finding-packets' ),
			),
		);
		$result['fixture_diagnostics'] = Static_Site_Importer_Diagnostic_Contract::build( $result );
		$result['diagnostics']         = isset( $result['fixture_diagnostics']['diagnostics'] ) && is_array( $result['fixture_diagnostics']['diagnostics'] ) ? $result['fixture_diagnostics']['diagnostics'] : array();
		$result['diagnostic_summary']  = isset( $result['fixture_diagnostics']['diagnostic_summary'] ) && is_array( $result['fixture_diagnostics']['diagnostic_summary'] ) ? $result['fixture_diagnostics']['diagnostic_summary'] : array();

		return $result;
	}

	/**
	 * Resolve or create validation artifact directory.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $slug  Fixture slug.
	 * @return string|WP_Error
	 */
	private static function artifact_dir( array $input, string $slug ) {
		if ( isset( $input['artifact_dir'] ) && is_string( $input['artifact_dir'] ) && '' !== $input['artifact_dir'] ) {
			$directory = $input['artifact_dir'];
		} else {
			$upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
			$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : sys_get_temp_dir();
			$directory  = trailingslashit( $base_dir ) . 'static-site-importer/validation-' . sanitize_title( $slug ) . '-' . sanitize_key( uniqid( '', true ) );
		}

		$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : false;
		if ( ! $created ) {
			return new WP_Error( 'static_site_importer_validation_artifact_dir_failed', 'Could not create validation artifact directory.' );
		}

		return $directory;
	}

	/**
	 * Build a local artifact ref.
	 *
	 * @param string $path File path.
	 * @param string $kind Artifact kind.
	 * @return array<string,string>
	 */
	private static function local_file_artifact_ref( string $path, string $kind ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}

		return array(
			'artifact_ref' => basename( $path ),
			'path'         => $path,
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
}
