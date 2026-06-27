<?php
/**
 * Static Site Importer validation runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs SSI import validation in the current WordPress runtime.
 */
class Static_Site_Importer_Validation_Runtime {

	public const RESULT_SCHEMA = 'static-site-importer/import-validation-result/v1';

	/**
	 * Validate a website artifact in the current runtime.
	 *
	 * @param array<string,mixed> $input Validation input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate_artifact( array $input ) {
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

		$report_path = trailingslashit( $artifact_dir ) . 'import-report.json';
		$import_args = array(
			'slug'                      => $slug,
			'name'                      => isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : $slug,
			'activate'                  => array_key_exists( 'activate', $input ) ? (bool) $input['activate'] : true,
			'overwrite'                 => array_key_exists( 'overwrite', $input ) ? (bool) $input['overwrite'] : true,
			'fail_on_quality'           => ! empty( $input['fail_on_quality'] ),
			'allow_missing_woocommerce' => ! empty( $input['allow_missing_woocommerce'] ),
			'report'                    => $report_path,
			'source_metadata'           => array_merge(
				isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array(),
				array( 'validation_provider' => 'static-site-importer/current-runtime' )
			),
		);

		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::result_from_import( $result, $artifact_dir, $import_args );
	}

	/**
	 * Convert a WP_Error into the validation result shape.
	 *
	 * @param WP_Error            $error Validation error.
	 * @param array<string,mixed> $input Raw validation input.
	 * @return array<string,mixed>
	 */
	public static function error_result_from_wp_error( WP_Error $error, array $input = array() ): array {
		$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		$result = array(
			'success'      => false,
			'schema'       => self::RESULT_SCHEMA,
			'status'       => 'failed',
			'fixture_id'   => $slug,
			'request'      => array(
				'import_args' => array_filter(
					array(
						'slug' => $slug,
						'name' => isset( $input['name'] ) ? (string) $input['name'] : '',
					)
				),
			),
			'summary'      => array(
				'quality_pass' => false,
				'error_code'   => $error->get_error_code(),
			),
			'diagnostics'  => array(
				array(
					'type'        => 'validation_error',
					'severity'    => 'error',
					'code'        => $error->get_error_code(),
					'reason_code' => $error->get_error_code(),
					'message'     => $error->get_error_message(),
					'stage'       => 'validation',
					'owner'       => 'static-site-importer',
				),
			),
			'artifacts'    => array(),
			'import_report' => array(),
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

		$result = array(
			'success'       => $quality_pass,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => $quality_pass ? 'passed' : 'failed',
			'fixture_id'    => (string) ( $import_args['slug'] ?? '' ),
			'request'       => array( 'import_args' => $import_args ),
			'runtime'       => array(
				'provider'      => 'static-site-importer/current-runtime',
				'status'        => 'completed',
				'artifact_dir'  => basename( $artifact_dir ),
			),
			'summary'       => array(
				'quality_pass'          => $quality_pass,
				'import_report'         => is_readable( $report_path ) ? 'captured' : 'missing',
				'block_validation'      => is_readable( $validation_result_path ) ? 'captured' : 'missing',
				'theme_slug'            => (string) ( $import_result['theme_slug'] ?? '' ),
			),
			'import_report' => $import_report,
			'artifacts'     => array(
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

		$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : ( is_dir( $directory ) || mkdir( $directory, 0777, true ) );
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
