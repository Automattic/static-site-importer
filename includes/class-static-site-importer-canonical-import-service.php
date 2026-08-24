<?php
/**
 * Canonical application service for source imports and approved plans.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Direct_Artifact_Import' ) ) {
	require_once __DIR__ . '/class-static-site-importer-direct-artifact-import.php';
}

class Static_Site_Importer_Canonical_Import_Service {
	private static string $cli_report_destination = '';

	/**
	 * Run an import with an operator-owned CLI report destination.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function import_with_cli_report( array $input, string $report ): array {
		$previous                     = self::$cli_report_destination;
		self::$cli_report_destination = $report;
		try {
			return self::import( $input );
		} finally {
			self::$cli_report_destination = $previous;
		}
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function import( array $input ): array {
		if ( array_key_exists( 'report', $input ) ) {
			return self::error( 'static_site_importer_report_destination_forbidden', 'Report destinations are owned by the importer and are not accepted through Abilities.' );
		}
		$source    = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		$type      = (string) ( $source['type'] ?? '' );
		$operation = (string) ( $input['operation'] ?? 'apply' );
		if ( ! in_array( $operation, array( 'plan', 'apply' ), true ) ) {
			return self::error( 'static_site_importer_invalid_import_operation', 'operation must be plan or apply.' );
		}
		if ( 'apply' === $operation && isset( $input['plan'] ) && is_array( $input['plan'] ) ) {
			return self::apply_approved_plan( $input );
		}
		if ( ! in_array( $type, array( 'html', 'files', 'zip', 'url' ), true ) ) {
			return self::error( 'static_site_importer_invalid_import_source', 'source.type must be html, files, zip, or url.' );
		}
		if ( self::direct_artifact_continuation_available() && in_array( $type, array( 'html', 'files' ), true ) && '' !== (string) ( $source['import_id'] ?? '' ) ) {
			$args = self::direct_artifact_args( $input );
			$result = Static_Site_Importer_Direct_Artifact_Import::resume( (string) $source['import_id'], $args, $type, $operation, $source );
			return is_wp_error( $result ) ? self::error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() ) : $result;
		}

		$provenance = array( 'type' => $type );
		$reference  = (string) ( $source['ref'] ?? '' );
		if ( 'zip' === $type && ! empty( $source['zip']['staged_path'] ) ) {
			return self::error( 'static_site_importer_staged_archive_forbidden', 'Staged archive paths must come from a server-owned opaque reference resolver.' );
		}
		if ( '' !== $reference ) {
			$resolved = apply_filters( 'static_site_importer_resolve_source_reference', null, $reference, $type, $input );
			if ( ! is_array( $resolved ) ) {
				return self::error( 'static_site_importer_source_reference_unresolved', 'The opaque source reference was not resolved by a server-owned resolver.' );
			}
			$resolved_source = isset( $resolved['source'] ) && is_array( $resolved['source'] ) ? $resolved['source'] : $resolved;
			if ( isset( $resolved_source['type'] ) && $type !== (string) $resolved_source['type'] ) {
				return self::error( 'static_site_importer_source_reference_type_mismatch', 'The resolved source type does not match the requested source type.' );
			}
			$source     = array_merge( $source, $resolved_source, array( 'type' => $type ) );
			$provenance = array_merge( $provenance, array( 'ref' => $reference ), isset( $resolved['provenance'] ) && is_array( $resolved['provenance'] ) ? $resolved['provenance'] : array() );
		}
		if ( 'url' === $type ) {
			if ( 'apply' === $operation ) {
				return self::error( 'static_site_importer_url_apply_requires_plan', 'Apply a completed URL import by supplying its approved canonical plan.' );
			}
			return self::import_url_operation( $input, $source );
		}

		$runtime_source = array(
			'entrypoint' => (string) ( $source['entrypoint'] ?? '' ),
			'metadata'   => isset( $source['metadata'] ) && is_array( $source['metadata'] ) ? $source['metadata'] : array(),
		);
		if ( 'html' === $type ) {
			$runtime_source['html'] = (string) ( $source['html'] ?? '' );
		} elseif ( 'files' === $type ) {
			$runtime_source['files'] = isset( $source['files'] ) && is_array( $source['files'] ) ? $source['files'] : array();
		} elseif ( ! empty( $source['zip']['staged_path'] ) ) {
			$runtime_source['files'] = static_site_importer_staged_archive_files( $source['zip'], true );
			if ( is_wp_error( $runtime_source['files'] ) ) {
				return self::error( (string) $runtime_source['files']->get_error_code(), $runtime_source['files']->get_error_message(), $runtime_source['files']->get_error_data() );
			}
			if ( 'apply' === $operation ) {
				$payload_reader = static_site_importer_staged_archive_payload_reader( $source['zip'] );
				if ( is_wp_error( $payload_reader ) ) {
					return self::error( (string) $payload_reader->get_error_code(), $payload_reader->get_error_message(), $payload_reader->get_error_data() );
				}
			}
		} else {
			$runtime_source['archive'] = isset( $source['zip'] ) && is_array( $source['zip'] ) ? $source['zip'] : array();
		}
		if ( ! function_exists( 'static_site_importer_source_runtime' ) ) {
			return self::error( 'static_site_importer_source_normalizer_unavailable', 'The canonical source normalizer is unavailable.' );
		}
		$runtime = static_site_importer_source_runtime( $runtime_source );
		if ( is_wp_error( $runtime ) ) {
			return self::error( (string) $runtime->get_error_code(), $runtime->get_error_message(), $runtime->get_error_data() );
		}
		$artifact = $runtime['artifact'];
		if ( empty( $artifact ) ) {
			return self::error( 'static_site_importer_missing_website_artifact', 'The source did not normalize to a website artifact.' );
		}
		$provenance = array_merge(
			$provenance,
			array(
				'provider'        => $runtime['provider'],
				'source_metadata' => $runtime['source_metadata'],
			)
		);
		$args       = self::direct_artifact_args( $input );
		if ( isset( $payload_reader ) ) {
			$args['_static_site_importer_payload_reader'] = $payload_reader;
		}
		if ( self::direct_artifact_continuation_available() && in_array( $type, array( 'html', 'files' ), true ) && 'resume' !== $args['runtime_lifecycle_phase'] && self::artifact_html_page_count( $artifact ) > 1 ) {
			$result = Static_Site_Importer_Direct_Artifact_Import::start( $artifact, $args, $type, $operation, $provenance );
			return is_wp_error( $result ) ? self::error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() ) : $result;
		}
		if ( 'plan' === $operation ) {
			return self::plan_artifact( $artifact, $args, $type, $provenance );
		}
		$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
		if ( is_wp_error( $result ) ) {
			return self::error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}
		return self::success( $result, $input );
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	private static function direct_artifact_args( array $input ): array {
		$args = Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );
		if ( '' !== $args['runtime_lifecycle_phase'] ) {
			$args['runtime_lifecycle_invocation_id'] = wp_generate_uuid4();
		}
		if ( '' !== self::$cli_report_destination ) {
			$args['report'] = self::$cli_report_destination;
		}
		return $args;
	}

	/** @param array<string,mixed> $artifact */
	private static function artifact_html_page_count( array $artifact ): int {
		$count = 0;
		foreach ( is_array( $artifact['files'] ?? null ) ? $artifact['files'] : array() as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = strtolower( (string) ( $file['path'] ?? '' ) );
			$mime = strtolower( (string) ( $file['mime_type'] ?? '' ) );
			if ( str_ends_with( $path, '.html' ) || str_ends_with( $path, '.htm' ) || str_contains( $mime, 'html' ) ) {
				++$count;
			}
		}
		return $count;
	}

	private static function direct_artifact_continuation_available(): bool {
		return function_exists( 'wp_json_encode' ) && function_exists( 'wp_mkdir_p' ) && function_exists( 'wp_upload_dir' );
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function apply_approved_plan( array $input ): array {
		$approved       = $input['plan'];
		$plan           = isset( $approved['plan'] ) && is_array( $approved['plan'] ) ? $approved['plan'] : $approved;
		$payload_reader = self::approved_plan_payload_reader( $input, $approved );
		if ( is_wp_error( $payload_reader ) ) {
			return self::error( (string) $payload_reader->get_error_code(), $payload_reader->get_error_message(), $payload_reader->get_error_data() );
		}
		$classic = isset( $approved['classic_materialization'] ) && is_array( $approved['classic_materialization'] ) ? $approved['classic_materialization'] : ( isset( $input['classic_materialization'] ) && is_array( $input['classic_materialization'] ) ? $input['classic_materialization'] : null );
		if ( is_array( $classic ) ) {
			$artifact   = $classic['artifact'] ?? null;
			$projection = $classic['projection'] ?? null;
			$args       = $classic['normalized_args'] ?? null;
			if ( 'static-site-importer/classic-plan-input/v2' !== ( $classic['schema'] ?? '' ) || ! is_array( $args ) || 'classic' !== ( $args['theme_materialization'] ?? '' ) || ! is_array( $artifact ) || ! is_array( $projection ) || ! is_array( $classic['plan_identity'] ?? null ) || $plan['plan_identity'] !== $classic['plan_identity'] || hash( 'sha256', (string) wp_json_encode( $artifact ) ) !== ( $classic['artifact_hash'] ?? '' ) || hash( 'sha256', (string) wp_json_encode( $projection ) ) !== ( $classic['projection_hash'] ?? '' ) || self::handoff_hash( $args ) !== ( $classic['args_hash'] ?? '' ) ) {
				return self::error( 'static_site_importer_classic_plan_input_changed', 'The approved classic artifact or projection does not match its immutable plan input.' );
			}
			$projection_hash = $classic['projection_hash'];
			$rebuilt         = Static_Site_Importer_Classic_Theme_Projection::build( $artifact, $plan );
			if ( is_wp_error( $rebuilt ) || hash( 'sha256', (string) wp_json_encode( $rebuilt ) ) !== $projection_hash ) {
				return self::error( 'static_site_importer_classic_projection_changed', 'The approved classic projection could not be reproduced from its immutable artifact.' );
			}
			$args['approved_classic_plan_identity']   = $classic['plan_identity'];
			$args['approved_classic_projection_hash'] = (string) $projection_hash;
			if ( is_object( $payload_reader ) ) {
				$args['_static_site_importer_payload_reader'] = $payload_reader;
			}
			$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
			if ( is_wp_error( $result ) ) {
				return self::error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
			}
			return array(
				'success'               => true,
				'operation'             => 'apply',
				'plan'                  => $plan,
				'applied_plan'          => $plan,
				'applied_plan_identity' => $classic['plan_identity'],
				'result'                => $result,
				'error'                 => null,
			);
		}
		if ( is_object( $payload_reader ) ) {
			$input['_static_site_importer_payload_reader'] = $payload_reader;
		}
		$receipt = self::materialize_wordpress_site_plan( $input );
		$success = 'completed' === ( $receipt['status'] ?? '' );
		return array(
			'success'   => $success,
			'operation' => 'apply',
			'plan'      => $plan,
			'result'    => $receipt,
			'error'     => $success ? null : ( $receipt['errors'][0] ?? array(
				'code'    => 'static_site_importer_materialization_failed',
				'message' => 'The approved plan could not be materialized.',
			) ),
		);
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function materialize_wordpress_site_plan( array $input ): array {
		return Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array(), $input );
	}

	/** @param array<array-key,mixed> $value */
	public static function handoff_hash( array $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::handoff_hashable( $value ) ) );
	}

	/** @param array<array-key,mixed> $value @return array<array-key,mixed> */
	private static function handoff_hashable( array $value ): array {
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$item = self::handoff_hashable( $item );
			}
		}
		unset( $item );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/** @param array<string,mixed> $artifact @param array<string,mixed> $args @param array<string,mixed> $provenance @return array<string,mixed> */
	public static function plan_artifact( array $artifact, array $args, string $type, array $provenance ): array {
		$compiled = Static_Site_Importer_Theme_Generator::compile_website_artifact( $artifact, $args );
		if ( is_wp_error( $compiled ) ) {
			return self::error( (string) $compiled->get_error_code(), $compiled->get_error_message(), $compiled->get_error_data() );
		}
		$response = array(
			'success'     => true,
			'operation'   => 'plan',
			'plan'        => $compiled['plan'],
			'diagnostics' => $compiled['plan']['diagnostics'] ?? array(),
			'quality'     => $compiled['plan']['quality'] ?? array(),
			'source'      => array(
				'type'       => $type,
				'identity'   => hash( 'sha256', (string) wp_json_encode( $artifact ) ),
				'provenance' => $provenance,
			),
		);
		if ( 'classic' === ( $compiled['args']['theme_materialization'] ?? '' ) ) {
			$encoded_artifact                    = wp_json_encode( $compiled['artifact'] );
			$encoded_projection                  = wp_json_encode( $compiled['args']['classic_theme_projection'] ?? array() );
			$response['classic_materialization'] = array(
				'schema'          => 'static-site-importer/classic-plan-input/v2',
				'plan_identity'   => $compiled['plan']['plan_identity'] ?? array(),
				'artifact_hash'   => hash( 'sha256', false !== $encoded_artifact ? $encoded_artifact : '' ),
				'projection_hash' => hash( 'sha256', false !== $encoded_projection ? $encoded_projection : '' ),
				'args_hash'       => self::handoff_hash( $compiled['args'] ),
				'artifact'        => $compiled['artifact'],
				'projection'      => $compiled['args']['classic_theme_projection'],
				'normalized_args' => $compiled['args'],
			);
		}
		return $response;
	}

	/** @param array<string,mixed> $input @param array<string,mixed> $source @return array<string,mixed> */
	public static function import_url_operation( array $input, array $source ): array {
		$url_input = array_merge(
			$input,
			array(
				'url'       => (string) ( $source['url'] ?? '' ),
				'import_id' => (string) ( $source['import_id'] ?? '' ),
			)
		);
		if ( '' !== self::$cli_report_destination ) {
			$url_input['report'] = self::$cli_report_destination;
		}
		$result = Static_Site_Importer_URL_Import_Runtime::run_operation( $url_input );
		if ( is_wp_error( $result ) ) {
			return self::error( (string) $result->get_error_code(), $result->get_error_message(), $result->get_error_data() );
		}
		$continuation = array(
			'success'               => true,
			'operation'             => (string) ( $input['operation'] ?? 'apply' ),
			'import_id'             => (string) ( $result['import_id'] ?? '' ),
			'continuation'          => ! empty( $result['continuation'] ),
			'continuation_reason'   => (string) ( $result['continuation_reason'] ?? '' ),
			'import_report_summary' => is_array( $result['import_report_summary'] ?? null ) ? $result['import_report_summary'] : array(),
			'url_batch_run'         => is_array( $result['url_batch_run'] ?? null ) ? $result['url_batch_run'] : array(),
		);
		if ( ! empty( $result['continuation'] ) ) {
			return $continuation;
		}
		$terminal = is_array( $result['terminal_batch_result'] ?? null ) ? $result['terminal_batch_result'] : array();
		if ( 'plan' !== ( $input['operation'] ?? 'apply' ) ) {
			return array_merge( self::success( $result, $input ), $continuation );
		}
		if ( ! is_array( $terminal['plan'] ?? null ) ) {
			return self::error( 'static_site_importer_url_plan_missing', 'The completed URL acquisition did not produce a canonical plan.' );
		}
		$response = array_merge(
			$continuation,
			array(
				'plan'        => $terminal['plan'],
				'diagnostics' => array_merge( is_array( $continuation['url_batch_run']['diagnostics'] ?? null ) ? $continuation['url_batch_run']['diagnostics'] : array(), is_array( $terminal['diagnostics'] ?? null ) ? $terminal['diagnostics'] : array() ),
				'quality'     => is_array( $terminal['quality'] ?? null ) ? $terminal['quality'] : array(),
				'source'      => array(
					'type'       => 'url',
					'identity'   => hash( 'sha256', (string) wp_json_encode( $terminal['plan'] ) ),
					'provenance' => array(
						'url'           => (string) ( $source['url'] ?? '' ),
						'import_id'     => (string) ( $result['import_id'] ?? '' ),
						'url_batch_run' => $continuation['url_batch_run'],
					),
				),
			)
		);
		$args     = Static_Site_Importer_Website_Artifact_Import_Input::normalize( $input );
		if ( 'classic' === $args['theme_materialization'] && is_array( $terminal['artifact'] ?? null ) ) {
			$projection = Static_Site_Importer_Classic_Theme_Projection::build( $terminal['artifact'], $terminal['plan'] );
			if ( is_wp_error( $projection ) ) {
				return self::error( (string) $projection->get_error_code(), $projection->get_error_message(), $projection->get_error_data() );
			}
			$response['classic_materialization'] = array(
				'schema'          => 'static-site-importer/classic-plan-input/v2',
				'plan_identity'   => $terminal['plan']['plan_identity'] ?? array(),
				'artifact_hash'   => hash( 'sha256', (string) wp_json_encode( $terminal['artifact'] ) ),
				'projection_hash' => hash( 'sha256', (string) wp_json_encode( $projection ) ),
				'args_hash'       => self::handoff_hash( $args ),
				'artifact'        => $terminal['artifact'],
				'projection'      => $projection,
				'normalized_args' => $args,
			);
		}
		return $response;
	}

	/** Reacquire a resolver-owned ZIP reader only while applying its approved plan. */
	public static function approved_plan_payload_reader( array $input, array $approved ) {
		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : ( isset( $approved['source'] ) && is_array( $approved['source'] ) ? $approved['source'] : array() );
		if ( 'zip' !== (string) ( $source['type'] ?? '' ) ) {
			return null;
		}
		$reference = (string) ( $source['ref'] ?? ( $source['provenance']['ref'] ?? '' ) );
		if ( '' === $reference ) {
			return null;
		}
		$resolved = apply_filters( 'static_site_importer_resolve_source_reference', null, $reference, 'zip', $input );
		if ( ! is_array( $resolved ) ) {
			return new WP_Error( 'static_site_importer_source_reference_unresolved', 'The opaque source reference was not resolved by a server-owned resolver.' );
		}
		$resolved_source = isset( $resolved['source'] ) && is_array( $resolved['source'] ) ? $resolved['source'] : $resolved;
		$archive         = isset( $resolved_source['zip'] ) && is_array( $resolved_source['zip'] ) ? $resolved_source['zip'] : array();
		if ( empty( $archive['staged_path'] ) ) {
			return new WP_Error( 'static_site_importer_staged_archive_invalid', 'The approved plan requires a resolver-owned staged ZIP archive.' );
		}
		return static_site_importer_staged_archive_payload_reader( $archive );
	}

	/** @param array<string,mixed> $result @param array<string,mixed> $input @return array<string,mixed> */
	public static function success( array $result, array $input ): array {
		$contract = self::success_diagnostics_contract( $result );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'static_site_importer_import_completed', $contract, $result, $input );
		}
		return array(
			'success'             => true,
			'result'              => $result,
			'diagnostics'         => isset( $contract['diagnostics'] ) && is_array( $contract['diagnostics'] ) ? $contract['diagnostics'] : array(),
			'fixture_diagnostics' => $contract,
		);
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	public static function success_diagnostics_contract( array $result ): array {
		$validation  = isset( $result['import_validation_result'] ) && is_array( $result['import_validation_result'] ) ? $result['import_validation_result'] : array();
		$quality     = isset( $result['quality'] ) && is_array( $result['quality'] ) ? $result['quality'] : array();
		$diagnostics = isset( $validation['diagnostics'] ) && is_array( $validation['diagnostics'] ) ? $validation['diagnostics'] : array();
		$report      = isset( $result['import_report'] ) && is_array( $result['import_report'] ) ? $result['import_report'] : array();
		if ( empty( $report ) ) {
			$report = array(
				'quality'     => $quality,
				'diagnostics' => $diagnostics,
			);
		}
		$input = array(
			'success'                  => true,
			'status'                   => isset( $result['import_report_summary']['status'] ) && is_scalar( $result['import_report_summary']['status'] ) ? (string) $result['import_report_summary']['status'] : 'completed',
			'slug'                     => isset( $result['theme_slug'] ) ? (string) $result['theme_slug'] : '',
			'name'                     => isset( $result['theme_name'] ) ? (string) $result['theme_name'] : '',
			'import_validation_result' => $validation,
			'import_report'            => $report,
			'materialization_receipt'  => isset( $result['materialization_receipt'] ) && is_array( $result['materialization_receipt'] ) ? $result['materialization_receipt'] : array(),
		);
		return class_exists( 'Static_Site_Importer_Diagnostic_Contract' ) ? Static_Site_Importer_Diagnostic_Contract::build( $input ) : array( 'diagnostics' => $diagnostics );
	}

	/** @param mixed $data @return array<string,mixed> */
	public static function error( string $code, string $message, $data = null ): array {
		$summary     = is_array( $data ) && isset( $data['import_report_summary'] ) && is_array( $data['import_report_summary'] ) ? $data['import_report_summary'] : self::failure_report_summary( $code, $message );
		$diagnostics = self::error_diagnostics( $code, $message, $data, $summary );
		$fixture     = class_exists( 'Static_Site_Importer_Diagnostic_Contract' ) ? Static_Site_Importer_Diagnostic_Contract::build(
			array(
				'success'                  => false,
				'status'                   => 'failed',
				'diagnostics'              => $diagnostics,
				'import_validation_result' => is_array( $data ) && is_array( $data['import_validation_result'] ?? null ) ? $data['import_validation_result'] : array(),
				'import_report'            => is_array( $data ) && is_array( $data['import_report'] ?? null ) ? $data['import_report'] : array(),
			)
		) : array( 'diagnostics' => $diagnostics );
		$payload     = array(
			'success'               => false,
			'error'                 => array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
			'import_report_summary' => $summary,
			'diagnostics'           => $diagnostics,
			'errors'                => $diagnostics,
			'fixture_diagnostics'   => $fixture,
		);
		if ( is_array( $data ) && is_array( $data['import_validation_result'] ?? null ) ) {
			$payload['import_validation_result'] = $data['import_validation_result'];
		}
		if ( is_array( $data ) && is_array( $data['finding_packets'] ?? null ) ) {
			$payload['finding_packets'] = $data['finding_packets'];
		}
		return $payload;
	}

	/** @param mixed $data @param array<string,mixed> $summary @return array<int,array<string,mixed>> */
	public static function error_diagnostics( string $code, string $message, $data, array $summary ): array {
		$candidates = array( is_array( $data ) && is_array( $data['import_validation_result']['diagnostics'] ?? null ) ? $data['import_validation_result']['diagnostics'] : array(), is_array( $summary['diagnostics'] ?? null ) ? $summary['diagnostics'] : array() );
		foreach ( $candidates as $candidate ) {
			$diagnostics = array_values( array_filter( $candidate, array( self::class, 'is_actionable_error_diagnostic' ) ) );
			if ( ! empty( $diagnostics ) ) {
				return $diagnostics;
			}
		}
		return array(
			array(
				'type'        => 'validation_error',
				'kind'        => 'validation_error',
				'severity'    => 'error',
				'code'        => $code,
				'reason_code' => $code,
				'reason'      => $code,
				'message'     => $message,
				'stage'       => 'validation',
				'owner'       => 'static-site-importer',
			),
		);
	}

	public static function is_actionable_error_diagnostic( $diagnostic ): bool {
		if ( ! is_array( $diagnostic ) ) {
			return false; }
		foreach ( array( 'type', 'kind', 'code', 'reason_code', 'reason', 'error_code', 'source_path', 'path', 'source', 'selector' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && '' !== trim( (string) $diagnostic[ $field ] ) && ! preg_match( '/^\d+$/', trim( (string) $diagnostic[ $field ] ) ) ) {
				return true; }
		}
		return false;
	}

	/** @return array<string,mixed> */
	public static function failure_report_summary( string $code, string $message ): array {
		return array(
			'status'                => 'failed',
			'quality_pass'          => false,
			'fail_import'           => true,
			'failure_reasons'       => array( $code ),
			'core_html_block_count' => 0,
			'freeform_block_count'  => 0,
			'invalid_block_count'   => 0,
			'diagnostic_count'      => 1,
			'error'                 => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
