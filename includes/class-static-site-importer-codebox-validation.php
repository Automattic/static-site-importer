<?php
/**
 * Codebox-backed validation product path contract.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		add_filter( 'static_site_importer_codebox_validation_result', array( self::class, 'validate_in_local_runtime' ), 5, 3 );
		add_filter( 'static_site_importer_codebox_validation_result', array( self::class, 'delegate_to_wp_codebox_host_delegation' ), 10, 3 );
	}

	/**
	 * Run the SSI validation recipe in the current WordPress runtime when explicitly enabled for local development.
	 *
	 * @param mixed               $result  Existing provider result.
	 * @param array<string,mixed> $request SSI validation request.
	 * @param array<string,mixed> $input   Raw caller input.
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function validate_in_local_runtime( mixed $result, array $request, array $input ) {
		if ( null !== $result || ! self::local_runtime_provider_enabled( $input ) ) {
			return $result;
		}

		if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
			return null;
		}

		$artifact = isset( $request['source']['artifact'] ) && is_array( $request['source']['artifact'] ) ? $request['source']['artifact'] : array();
		if ( empty( $artifact ) ) {
			return null;
		}

		$import_args             = isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array();
		$import_args['activate'] = true;
		$import_args['overwrite'] = true;

		$import_result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
		if ( is_wp_error( $import_result ) ) {
			return $import_result;
		}

		$theme_dir = (string) ( $import_result['theme_dir'] ?? '' );
		$artifact_dir = trailingslashit( $theme_dir ) . 'validation-artifacts';
		if ( '' !== $theme_dir && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $artifact_dir );
		}

		$source_url          = self::materialize_source_artifact_for_browser( $artifact, $artifact_dir );
		$source_screenshot   = self::capture_browser_screenshot( $source_url, $artifact_dir . '/source-screenshot.png' );
		$imported_screenshot = self::capture_browser_screenshot( function_exists( 'home_url' ) ? home_url( '/' ) : '', $artifact_dir . '/imported-screenshot.png' );
		$visual_diff         = self::write_visual_diff_artifact( $source_screenshot['path'] ?? '', $imported_screenshot['path'] ?? '', $artifact_dir . '/visual-diff.json', $artifact_dir . '/visual-diff.png' );

		$screenshots = array();
		if ( ! empty( $source_screenshot['artifact'] ) ) {
			$screenshots[] = $source_screenshot['artifact'];
		}
		if ( ! empty( $imported_screenshot['artifact'] ) ) {
			$screenshots[] = $imported_screenshot['artifact'];
		}

		$diffs = array();
		if ( ! empty( $visual_diff['artifact'] ) ) {
			$diffs[] = $visual_diff['artifact'];
		}
		if ( ! empty( $visual_diff['image_artifact'] ) ) {
			$diffs[] = $visual_diff['image_artifact'];
		}

		$quality_pass = ! empty( $import_result['quality']['pass'] ) && ( ! isset( $visual_diff['summary']['pixel_delta_percent'] ) || (float) $visual_diff['summary']['pixel_delta_percent'] <= 5.0 );

		$provider_artifacts = array(
			'generated_theme'         => self::local_artifact_ref( (string) ( $import_result['theme_slug'] ?? '' ), 'wordpress-theme-directory' ),
			'import_report'           => self::local_artifact_ref( 'import-report.json', 'blocks-engine/import-report' ),
			'block_validation_result' => self::local_artifact_ref( 'import-validation-result.json', 'blocks-engine/import-validation-result' ),
			'screenshots'             => $screenshots,
			'diffs'                   => $diffs,
			'raw'                     => array(
				self::local_artifact_ref( 'finding-packets.json', 'blocks-engine/finding-packets' ),
			),
		);
		if ( ! empty( $imported_screenshot['artifact'] ) ) {
			$provider_artifacts['browser_render_evidence'] = self::local_artifact_ref( 'validation-artifacts/imported-screenshot.png', 'wp-codebox/browser-screenshot' );
		}

		return array(
			'success'   => $quality_pass,
			'status'    => $quality_pass ? 'succeeded' : 'failed',
			'runtime'   => array(
				'provider' => 'static-site-importer/local-runtime',
				'status'   => 'completed',
			),
			'summary'   => array(
				'quality_pass'          => $quality_pass,
				'import_report'         => is_readable( (string) ( $import_result['report_path'] ?? '' ) ) ? 'captured' : 'missing',
				'block_validation'      => is_readable( (string) ( $import_result['validation_result_path'] ?? '' ) ) ? 'captured' : 'missing',
				'browser_render'        => ! empty( $imported_screenshot['artifact'] ) ? 'captured' : 'missing',
				'screenshot_artifacts'  => count( $screenshots ),
				'visual_diff_artifacts' => count( $diffs ),
				'theme_slug'            => (string) ( $import_result['theme_slug'] ?? '' ),
			) + ( isset( $visual_diff['summary'] ) && is_array( $visual_diff['summary'] ) ? $visual_diff['summary'] : array() ),
			'artifacts' => $provider_artifacts,
		);
	}

	/**
	 * Delegate SSI validation requests to WP Codebox/Homeboy host providers.
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

		$delegation_request = self::host_delegation_request( $request, $input );
		if ( class_exists( 'WP_Codebox_Abilities' ) && method_exists( 'WP_Codebox_Abilities', 'request_host_delegation' ) ) {
			$delegation_result = WP_Codebox_Abilities::request_host_delegation( $delegation_request );
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
		 * WP Codebox/Homeboy integrations may attach orchestration metadata here, but
		 * should not execute the run. Execution belongs to
		 * `static_site_importer_codebox_validation_result`.
		 *
		 * @param array<string,mixed> $request Validation request.
		 * @param array<string,mixed> $input   Raw caller input.
		 */
		$request = apply_filters( 'static_site_importer_codebox_validation_request', $request, $input );
		if ( ! is_array( $request ) ) {
			return new WP_Error( 'static_site_importer_codebox_validation_request_invalid', 'Codebox validation request filters must return an array.' );
		}

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

		if ( ! is_array( $result ) ) {
			return new WP_Error( 'static_site_importer_codebox_validation_result_invalid', 'Codebox validation providers must return an array result, WP_Error, or null.' );
		}

		return self::normalize_provider_result( $request, $result );
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
				'input_keys'     => array_values( array_map( 'strval', array_keys( $input ) ) ),
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

		return array(
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
	}

	/**
	 * Build a concrete blocked result when no runtime provider is registered.
	 *
	 * @param array<string,mixed> $request Validation request.
	 * @return array<string,mixed>
	 */
	private static function provider_unavailable_result( array $request ): array {
		return array(
			'success'       => false,
			'schema'        => self::RESULT_SCHEMA,
			'status'        => 'blocked',
			'product_path'  => 'static-site-importer/validate-in-codebox',
			'request'       => self::request_summary( $request ),
			'artifacts'     => self::artifact_contract( $request, array() ),
			'runtime'       => array(
				'provider' => 'wp-codebox/homeboy',
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
					'owner'        => 'wp-codebox/homeboy',
					'capability'   => 'static-site-importer Codebox validation provider',
					'missing'      => 'No provider is registered on static_site_importer_codebox_validation_result to run the SSI import, block validation, browser render capture, screenshot capture, and visual diff capture inside a disposable WP Codebox runtime.',
					'needed_shape' => 'Accept static-site-importer/codebox-validation-request/v1 and return static-site-importer/codebox-validation-result/v1 with durable artifact references for generated theme/archive, import report, block validation result, browser/render evidence metadata, screenshots, and diffs.',
				),
			),
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
			'schema'                 => self::ARTIFACT_SCHEMA,
			'generated_theme'        => self::first_ref( $artifacts['generated_theme'] ?? array(), $request['source']['generated_theme_ref'] ?? array() ),
			'theme_archive'          => self::first_ref( $artifacts['theme_archive'] ?? array(), $request['source']['theme_archive_ref'] ?? array() ),
			'import_report'          => self::normalize_artifact_ref( $artifacts['import_report'] ?? array() ),
			'block_validation_result' => self::normalize_artifact_ref( $artifacts['block_validation_result'] ?? array() ),
			'browser_render_evidence' => self::normalize_artifact_ref( $artifacts['browser_render_evidence'] ?? array() ),
			'screenshots'            => self::normalize_artifact_refs( $artifacts['screenshots'] ?? array() ),
			'diffs'                  => self::normalize_artifact_refs( $artifacts['diffs'] ?? array() ),
			'raw'                    => self::normalize_artifact_refs( $artifacts['raw'] ?? array() ),
			'required'               => self::required_artifacts(),
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
			'schema'             => $request['schema'] ?? self::REQUEST_SCHEMA,
			'product_path'       => $request['product_path'] ?? 'static-site-importer/validate-in-codebox',
			'execution_scope'    => $request['execution_scope'] ?? 'disposable-wp-codebox-runtime',
			'has_inline_artifact' => ! empty( $artifact ),
			'artifact_schema'    => isset( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'import_args'        => isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array(),
			'required_artifacts' => isset( $request['required_artifacts'] ) && is_array( $request['required_artifacts'] ) ? array_values( $request['required_artifacts'] ) : self::required_artifacts(),
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

	/**
	 * Determine whether same-runtime validation has been explicitly enabled.
	 *
	 * @param array<string,mixed> $input Raw caller input.
	 * @return bool
	 */
	private static function local_runtime_provider_enabled( array $input ): bool {
		if ( ! empty( $input['local_runtime_provider'] ) ) {
			return true;
		}
		if ( defined( 'STATIC_SITE_IMPORTER_LOCAL_CODEBOX_VALIDATION' ) && STATIC_SITE_IMPORTER_LOCAL_CODEBOX_VALIDATION ) {
			return true;
		}
		if ( false !== getenv( 'STATIC_SITE_IMPORTER_LOCAL_CODEBOX_VALIDATION' ) && '' !== getenv( 'STATIC_SITE_IMPORTER_LOCAL_CODEBOX_VALIDATION' ) ) {
			return true;
		}

		return function_exists( 'get_option' ) && (bool) get_option( 'static_site_importer_local_codebox_validation', false );
	}

	/**
	 * Write inline website artifact files to a browser-readable local source directory.
	 *
	 * @param array<string,mixed> $artifact     Website artifact.
	 * @param string              $artifact_dir Validation artifact directory.
	 * @return string Browser URL for the source entrypoint.
	 */
	private static function materialize_source_artifact_for_browser( array $artifact, string $artifact_dir ): string {
		if ( '' === $artifact_dir || ! is_dir( $artifact_dir ) ) {
			return '';
		}

		$source_dir = $artifact_dir . '/source';
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $source_dir );
		}
		if ( ! is_dir( $source_dir ) ) {
			return '';
		}

		$files = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		if ( empty( $files ) && isset( $artifact['html'] ) ) {
			$files = array(
				array(
					'path'    => 'website/index.html',
					'content' => '<!doctype html><html><head><meta charset="utf-8"><title>Source Fixture</title><link rel="stylesheet" href="styles.css"></head><body>' . (string) $artifact['html'] . '<script src="script.js"></script></body></html>',
				),
				array(
					'path'    => 'website/styles.css',
					'content' => (string) ( $artifact['css'] ?? '' ),
				),
				array(
					'path'    => 'website/script.js',
					'content' => (string) ( $artifact['js'] ?? '' ),
				),
			);
		}
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) ) {
				continue;
			}

			$path = self::safe_relative_artifact_path( (string) $file['path'] );
			if ( '' === $path ) {
				continue;
			}

			$target = $source_dir . '/' . $path;
			$parent = dirname( $target );
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $parent );
			}

			$content = isset( $file['content_base64'] ) ? base64_decode( (string) $file['content_base64'], true ) : (string) ( $file['content'] ?? '' );
			if ( false === $content ) {
				continue;
			}

			file_put_contents( $target, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local dev validation writes throwaway artifacts.
		}

		$entrypoint = self::safe_relative_artifact_path( (string) ( $artifact['entrypoint'] ?? 'website/index.html' ) );
		$source_entry = $source_dir . '/' . $entrypoint;
		if ( ! is_readable( $source_entry ) && is_readable( $source_dir . '/website/index.html' ) ) {
			$source_entry = $source_dir . '/website/index.html';
		}

		return is_readable( $source_entry ) ? 'file://' . $source_entry : '';
	}

	/**
	 * Capture a screenshot with Playwright CLI when available.
	 *
	 * @param string $url    Browser URL.
	 * @param string $target Target screenshot path.
	 * @return array{path?:string,artifact?:array<string,string>}
	 */
	private static function capture_browser_screenshot( string $url, string $target ): array {
		if ( '' === $url || '' === $target ) {
			return array();
		}

		$channel = false !== getenv( 'STATIC_SITE_IMPORTER_LOCAL_PLAYWRIGHT_CHANNEL' ) && '' !== getenv( 'STATIC_SITE_IMPORTER_LOCAL_PLAYWRIGHT_CHANNEL' ) ? (string) getenv( 'STATIC_SITE_IMPORTER_LOCAL_PLAYWRIGHT_CHANNEL' ) : 'chrome';
		$command = 'npm exec --yes playwright screenshot -- --channel=' . escapeshellarg( $channel ) . ' --full-page --wait-for-timeout=1000 --viewport-size=1280,900 ' . escapeshellarg( $url ) . ' ' . escapeshellarg( $target ) . ' 2>&1';
		exec( $command, $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Explicit local development provider only.
		if ( 0 !== $exit_code || ! is_readable( $target ) ) {
			return array();
		}

		return array(
			'path'     => $target,
			'artifact' => self::local_artifact_ref( 'validation-artifacts/' . basename( $target ), str_contains( basename( $target ), 'source' ) ? 'source_screenshot' : 'imported_screenshot' ),
		);
	}

	/**
	 * Write a simple PNG pixel-diff summary and image.
	 *
	 * @param string $source_path   Source screenshot path.
	 * @param string $imported_path Imported screenshot path.
	 * @param string $json_path     Diff summary path.
	 * @param string $image_path    Diff image path.
	 * @return array<string,mixed>
	 */
	private static function write_visual_diff_artifact( string $source_path, string $imported_path, string $json_path, string $image_path ): array {
		if ( ! extension_loaded( 'gd' ) || ! is_readable( $source_path ) || ! is_readable( $imported_path ) ) {
			return array();
		}

		$source = imagecreatefrompng( $source_path );
		$imported = imagecreatefrompng( $imported_path );
		if ( false === $source || false === $imported ) {
			return array();
		}

		$width = min( imagesx( $source ), imagesx( $imported ) );
		$height = min( imagesy( $source ), imagesy( $imported ) );
		$diff_image = imagecreatetruecolor( $width, $height );
		$total_delta = 0;
		$mismatched = 0;
		$total = max( 1, $width * $height );

		for ( $y = 0; $y < $height; ++$y ) {
			for ( $x = 0; $x < $width; ++$x ) {
				$source_rgb = imagecolorat( $source, $x, $y );
				$imported_rgb = imagecolorat( $imported, $x, $y );
				$delta_r = abs( ( ( $source_rgb >> 16 ) & 0xff ) - ( ( $imported_rgb >> 16 ) & 0xff ) );
				$delta_g = abs( ( ( $source_rgb >> 8 ) & 0xff ) - ( ( $imported_rgb >> 8 ) & 0xff ) );
				$delta_b = abs( ( $source_rgb & 0xff ) - ( $imported_rgb & 0xff ) );
				$delta = ( $delta_r + $delta_g + $delta_b ) / 3;
				$total_delta += $delta;
				if ( $delta > 16 ) {
					++$mismatched;
				}

				imagesetpixel( $diff_image, $x, $y, imagecolorallocate( $diff_image, (int) $delta, 0, 0 ) );
			}
		}

		imagepng( $diff_image, $image_path );
		imagedestroy( $source );
		imagedestroy( $imported );
		imagedestroy( $diff_image );

		$summary = array(
			'pixel_delta_percent' => round( ( $mismatched / $total ) * 100, 4 ),
			'average_delta'       => round( $total_delta / $total, 4 ),
			'compared_width'      => $width,
			'compared_height'     => $height,
		);
		file_put_contents( $json_path, wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local dev validation writes throwaway artifacts.

		return array(
			'summary'        => $summary,
			'artifact'       => self::local_artifact_ref( 'validation-artifacts/visual-diff.json', 'visual_diff' ),
			'image_artifact' => self::local_artifact_ref( 'validation-artifacts/visual-diff.png', 'visual_diff_image' ),
		);
	}

	/**
	 * Build a portable local artifact ref by artifact name only.
	 *
	 * @param string $artifact_name Artifact name or relative path.
	 * @param string $kind          Artifact kind.
	 * @return array<string,string>
	 */
	private static function local_artifact_ref( string $artifact_name, string $kind ): array {
		return array_filter(
			array(
				'artifact_name' => $artifact_name,
				'kind'          => $kind,
			)
		);
	}

	/**
	 * Normalize a website artifact path for writing below the source validation directory.
	 *
	 * @param string $path Raw artifact path.
	 * @return string
	 */
	private static function safe_relative_artifact_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
		$path = trim( preg_replace( '#/+#', '/', (string) $path ), '/' );

		return preg_match( '#^[A-Za-z0-9_./-]+$#', $path ) ? $path : '';
	}
}
