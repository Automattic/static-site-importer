<?php
/**
 * WP-CLI transport adapters.
 *
 * @package StaticSiteImporter
 */
if ( ! function_exists( 'static_site_importer_cli_write_validation_output' ) ) {
	/**
	 * Write validation output to a file when requested, otherwise stdout.
	 *
	 * @param string $json   Validation JSON.
	 * @param string $output Output path.
	 * @return void
	 */
	function static_site_importer_cli_write_validation_output( string $json, string $output ): void {
		if ( '' === $output ) {
			WP_CLI::line( $json );
			return;
		}

		$directory = dirname( $output );
		if ( ! is_dir( $directory ) ) {
			$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : false;
			if ( ! $created ) {
				WP_CLI::error( 'Failed to create validation output directory.' );
			}
		}

		if ( false === file_put_contents( $output, $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes operator-requested validation artifact.
			WP_CLI::error( 'Failed to write validation output file.' );
		}

		WP_CLI::line(
			(string) wp_json_encode(
				array(
					'schema' => 'static-site-importer/validation-cli-output/v1',
					'output' => $output,
				),
				JSON_UNESCAPED_SLASHES
			)
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_approved_plan' ) ) {
	/** Read an explicit JSON plan response from a local regular file. */
	function static_site_importer_cli_approved_plan( array $assoc_args ) {
		$path = isset( $assoc_args['plan'] ) ? (string) $assoc_args['plan'] : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return new WP_Error( 'static_site_importer_cli_plan_invalid', 'Apply requires --plan=<readable JSON plan response file>.' );
		}
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an explicit operator-owned plan response.
		$plan = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $plan ) ? $plan : new WP_Error( 'static_site_importer_cli_plan_invalid', 'Apply plan must be a JSON object.' );
	}
}

if ( ! function_exists( 'static_site_importer_cli_import' ) ) {
	/** Run an import with the explicit, local WP-CLI report output seam. */
	function static_site_importer_cli_import( array $input ): array {
		$report = isset( $input['report'] ) ? (string) $input['report'] : '';
		unset( $input['report'], $input['_cli_request_bundle_dir'] );
		return Static_Site_Importer_Canonical_Import_Service::import_with_cli_report( $input, $report );
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_max_steps' ) ) {
	function static_site_importer_cli_import_max_steps(): int {
		return 256;
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_error' ) ) {
	/** @return array<string,mixed> */
	function static_site_importer_cli_import_error( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_read_request_json' ) ) {
	/** Read a canonical import ability request from a local regular file. */
	function static_site_importer_cli_read_request_json( string $path ) {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return new WP_Error( 'static_site_importer_cli_request_invalid', 'Import requires --request=<readable JSON request file>.' );
		}
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an explicit operator-owned import request.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return new WP_Error( 'static_site_importer_cli_request_invalid', 'Import request must be a JSON object.' );
		}
		return $data;
	}
}

if ( ! function_exists( 'static_site_importer_cli_request_bundle_path' ) ) {
	/** Resolve a request-bundled path without trusting a caller-supplied filesystem path. */
	function static_site_importer_cli_request_bundle_path( string $request_path, string $reference ) {
		$prefix = 'request-bundle:';
		if ( ! str_starts_with( $reference, $prefix ) ) {
			return null;
		}
		$relative = substr( $reference, strlen( $prefix ) );
		$base     = realpath( dirname( $request_path ) );
		if ( false === $base || '' === $relative || str_starts_with( $relative, '/' ) || str_starts_with( $relative, '\\' ) ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'The request-bundle reference must name a source beneath the request directory.' );
		}

		$cursor = $base;
		foreach ( preg_split( '#[\\\\/]#', $relative ) ?: array() as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'The request-bundle reference must not contain empty or traversal segments.' );
			}
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $cursor ) ) {
				return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'The request-bundle source and its parent directories must not be symlinks.' );
			}
		}

		$resolved = realpath( $cursor );
		if ( false === $resolved || ! str_starts_with( $resolved, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $resolved ) ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'The request-bundle source must be readable and beneath the request directory.' );
		}
		return $resolved;
	}
}

if ( ! function_exists( 'static_site_importer_cli_request_bundle_files' ) ) {
	/** Project a local source tree as metadata-only payload references. */
	function static_site_importer_cli_request_bundle_files( string $directory ) {
		if ( ! is_dir( $directory ) ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'A files request-bundle reference must resolve to a directory.' );
		}
		$files = array();
		$paths = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $item ) {
				if ( $item->isLink() ) {
					return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'Request-bundle source trees must not contain symlinks.' );
				}
				if ( ! $item->isFile() || ! $item->isReadable() ) {
					continue;
				}
				$absolute = $item->getRealPath();
				$relative = false !== $absolute ? str_replace( DIRECTORY_SEPARATOR, '/', substr( $absolute, strlen( $directory ) + 1 ) ) : '';
				if ( '' === $relative || ( function_exists( 'static_site_importer_rest_artifact_path' ) && '' === static_site_importer_rest_artifact_path( $relative ) ) ) {
					continue;
				}
				if ( function_exists( 'static_site_importer_rest_should_include_artifact_file' ) && ! static_site_importer_rest_should_include_artifact_file( $relative ) ) {
					continue;
				}
				if ( class_exists( 'Static_Site_Importer_Content_Policy' ) && ! Static_Site_Importer_Content_Policy::is_static_path( $relative ) ) {
					return new WP_Error( 'static_site_importer_executable_source_rejected', 'Request-bundle source trees may contain static content only.' );
				}
				$bytes  = $item->getSize();
				$digest = hash_file( 'sha256', $absolute );
				if ( false === $digest ) {
					return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'A request-bundle source file could not be verified.' );
				}
				$id             = 'request-bundle-file:' . rawurlencode( $relative );
				$paths[ $id ]   = $absolute;
				$files[]        = array(
					'path'              => $relative,
					'payload_reference' => array(
						'schema' => 'blocks-engine/payload-reference/v1',
						'id'     => $id,
						'bytes'  => $bytes,
						'sha256' => $digest,
					),
				);
				if ( 10000 < count( $files ) ) {
					return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'A request-bundle source tree may contain at most 10,000 static files.' );
				}
			}
		} catch ( UnexpectedValueException $error ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', $error->getMessage() );
		}
		if ( empty( $files ) ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'The request-bundle source tree contains no static files.' );
		}
		usort( $files, static fn( array $left, array $right ): int => strcmp( $left['path'], $right['path'] ) );
		return array(
			'files'          => $files,
			'payload_reader' => new class( $paths ) {
				/** @param array<string,string> $paths */
				public function __construct( private array $paths ) {}
				public function read( array $reference ): string {
					$id   = (string) ( $reference['id'] ?? '' );
					$path = $this->paths[ $id ] ?? '';
					$real = '' !== $path && ! is_link( $path ) ? realpath( $path ) : false;
					$data = $path === $real ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a verified CLI request-bundle payload on demand.
					if ( false === $data ) {
						throw new RuntimeException( 'The request-bundle payload is unavailable.' );
					}
					return $data;
				}
			},
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_prepare_request_bundle' ) ) {
	/** Register the server-owned resolver for a source staged beside the explicit request. */
	function static_site_importer_cli_prepare_request_bundle( array $input, string $request_path ) {
		$source    = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		$reference = (string) ( $source['ref'] ?? '' );
		if ( ! str_starts_with( $reference, 'request-bundle:' ) ) {
			return $input;
		}
		$type = (string) ( $source['type'] ?? '' );
		if ( ! in_array( $type, array( 'files', 'zip' ), true ) ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_type_invalid', 'Request-bundle references support files and zip sources.' );
		}
		$resolved_path = static_site_importer_cli_request_bundle_path( $request_path, $reference );
		if ( is_wp_error( $resolved_path ) ) {
			return $resolved_path;
		}
		$bundle = 'files' === $type ? static_site_importer_cli_request_bundle_files( $resolved_path ) : null;
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		if ( 'zip' === $type && ! is_file( $resolved_path ) ) {
			return new WP_Error( 'static_site_importer_cli_request_bundle_invalid', 'A zip request-bundle reference must resolve to a regular file.' );
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter(
				'static_site_importer_resolve_source_reference',
				static function ( $resolved, string $candidate, string $candidate_type ) use ( $reference, $resolved_path, $type, $bundle ) {
					if ( null !== $resolved || $reference !== $candidate || $type !== $candidate_type ) {
						return $resolved;
					}
					if ( 'files' === $type ) {
						return array(
							'source'         => array( 'type' => 'files', 'files' => $bundle['files'] ),
							'payload_reader' => $bundle['payload_reader'],
							'provenance'     => array( 'transport' => 'cli-request-bundle' ),
						);
					}
					return array(
						'source'     => array(
							'type' => 'zip',
							'zip'  => array(
								'name'        => basename( $resolved_path ),
								'staged_path' => $resolved_path,
							),
						),
						'provenance' => array( 'transport' => 'cli-request-bundle' ),
					);
				},
				10,
				3
			);
		}
		$input['_cli_request_bundle_dir'] = realpath( dirname( $request_path ) );
		return $input;
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_options' ) ) {
	/** @param array<string,mixed> $assoc_args @return array<string,mixed> */
	function static_site_importer_cli_import_options( array $assoc_args ): array {
		return array(
			'operation'                    => isset( $assoc_args['operation'] ) ? (string) $assoc_args['operation'] : 'apply',
			'slug'                         => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
			'name'                         => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
			'site_title'                   => isset( $assoc_args['site-title'] ) ? (string) $assoc_args['site-title'] : '',
			'activate'                     => isset( $assoc_args['activate'] ),
			'overwrite'                    => isset( $assoc_args['overwrite'] ),
			'disable_smilies'              => ! isset( $assoc_args['no-disable-smilies'] ),
			'remove_default_content'       => ! isset( $assoc_args['keep-default-content'] ),
			'fail_on_quality'              => isset( $assoc_args['fail-on-quality'] ),
			'allow_missing_woocommerce'    => isset( $assoc_args['allow-missing-woocommerce'] ),
			'materialize_dependencies'     => ! isset( $assoc_args['skip-dependency-materialization'] ),
			'report'                       => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
			'asset_materialization_policy' => isset( $assoc_args['asset-materialization-policy'] ) ? (string) $assoc_args['asset-materialization-policy'] : '',
			'theme_materialization'        => isset( $assoc_args['theme-materialization'] ) ? (string) $assoc_args['theme-materialization'] : 'block',
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_apply_import_id' ) ) {
	/**
	 * Project an opaque SSI-owned import_id onto the next ability request.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	function static_site_importer_cli_apply_import_id( array $input, string $import_id ): array {
		if ( '' === $import_id ) {
			return $input;
		}
		$type   = (string) ( $input['source']['type'] ?? '' );
		$source = array(
			'type'      => $type,
			'import_id' => $import_id,
		);
		if ( 'url' === $type ) {
			$source['url'] = (string) ( $input['source']['url'] ?? '' );
		}
		$input['source'] = $source;
		return $input;
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_input' ) ) {
	/**
	 * Normalize host command arguments into a canonical import ability request.
	 *
	 * @param array<int,string>   $args
	 * @param array<string,mixed> $assoc_args
	 * @return array<string,mixed>|WP_Error
	 */
	function static_site_importer_cli_import_input( array $args, array $assoc_args ) {
		unset( $args );
		$has_request = isset( $assoc_args['request'] );
		$has_url     = isset( $assoc_args['url'] );
		$has_plan    = isset( $assoc_args['plan'] );
		if ( (int) $has_request + (int) $has_url + (int) $has_plan > 1 ) {
			return new WP_Error( 'static_site_importer_cli_request_conflict', 'Provide exactly one of --request, --url, or --plan.' );
		}
		if ( $has_request ) {
			$request_path = (string) $assoc_args['request'];
			$input        = static_site_importer_cli_read_request_json( $request_path );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			$input = static_site_importer_cli_prepare_request_bundle( $input, $request_path );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			if ( isset( $assoc_args['report'] ) ) {
				$input['report'] = (string) $assoc_args['report'];
			}
		} elseif ( $has_plan ) {
			$plan = static_site_importer_cli_approved_plan( $assoc_args );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$input         = static_site_importer_cli_import_options( $assoc_args );
			$input['plan'] = $plan;
		} elseif ( $has_url ) {
			$url = trim( (string) $assoc_args['url'] );
			if ( '' === $url ) {
				return new WP_Error( 'static_site_importer_cli_url_invalid', 'Provide a public source URL.' );
			}
			$input           = static_site_importer_cli_import_options( $assoc_args );
			$input['source'] = array(
				'type' => 'url',
				'url'  => $url,
			);
		} else {
			return new WP_Error( 'static_site_importer_cli_request_invalid', 'Import requires --request=<readable JSON request file>.' );
		}
		$operation = (string) ( $input['operation'] ?? 'apply' );
		if ( ! in_array( $operation, array( 'plan', 'apply' ), true ) ) {
			return new WP_Error( 'static_site_importer_invalid_import_operation', 'operation must be plan or apply.' );
		}
		$input['operation'] = $operation;
		if ( isset( $assoc_args['import-id'] ) ) {
			$input = static_site_importer_cli_apply_import_id( $input, (string) $assoc_args['import-id'] );
		}
		if ( isset( $input['plan'] ) && is_array( $input['plan'] ) ) {
			return $input;
		}
		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		if ( ! in_array( (string) ( $source['type'] ?? '' ), array( 'html', 'files', 'zip', 'url' ), true ) ) {
			return new WP_Error( 'static_site_importer_invalid_import_source', 'source.type must be html, files, zip, or url.' );
		}
		return $input;
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_receipt' ) ) {
	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	function static_site_importer_cli_import_receipt( array $result, int $steps ): array {
		if ( ! empty( $result['continuation'] ) ) {
			$result = static_site_importer_cli_import_error( 'static_site_importer_cli_nonterminal_receipt', 'A continuation is not a terminal import receipt.' );
		}
		$success = ! empty( $result['success'] ) && empty( $result['continuation'] );
		return array(
			'schema'   => 'static-site-importer/import-cli-receipt/v1',
			'status'   => $success ? 'completed' : 'failed',
			'steps'    => $steps,
			'response' => $result,
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_decode_import_step' ) ) {
	function static_site_importer_cli_decode_import_step( string $output ): ?array {
		$lines = preg_split( '/\R/', trim( $output ) );
		if ( ! is_array( $lines ) ) {
			return null;
		}
		for ( $index = count( $lines ) - 1; $index >= 0; --$index ) {
			$decoded = json_decode( $lines[ $index ], true );
			if ( is_array( $decoded ) && ! array_is_list( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_fresh_runtime_spec' ) ) {
	/**
	 * @return array{command:string,options:array<string,mixed>}
	 */
	function static_site_importer_cli_import_fresh_runtime_spec( string $request_path ): array {
		return array(
			'command' => 'static-site-importer import --single-step --request=' . escapeshellarg( $request_path ),
			'options' => array(
				'launch'     => true,
				'exit_error' => false,
				'return'     => 'all',
			),
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_write_step_request' ) ) {
	/**
	 * @param array<string,mixed> $input
	 * @return string|WP_Error
	 */
	function static_site_importer_cli_write_step_request( array $input ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $input, JSON_UNESCAPED_SLASHES ) : false;
		if ( false === $json ) {
			return new WP_Error( 'static_site_importer_cli_step_request_encode_failed', 'The import continuation request could not be encoded.' );
		}
		$temp_dir = isset( $input['_cli_request_bundle_dir'] ) && is_dir( $input['_cli_request_bundle_dir'] ) ? (string) $input['_cli_request_bundle_dir'] : sys_get_temp_dir();
		$temp     = tempnam( $temp_dir, 'ssi-import-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- Writes a bounded host-owned continuation request for a fresh WP-CLI runtime.
		if ( false === $temp || false === file_put_contents( $temp, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a bounded host-owned continuation request for a fresh WP-CLI runtime.
			return new WP_Error( 'static_site_importer_cli_step_request_write_failed', 'The import continuation request could not be written.' );
		}
		return $temp;
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_run_fresh_runtime' ) ) {
	/** @param array<string,mixed> $input @return array<string,mixed> */
	function static_site_importer_cli_import_run_fresh_runtime( array $input ): array {
		$path = static_site_importer_cli_write_step_request( $input );
		if ( is_wp_error( $path ) ) {
			return static_site_importer_cli_import_error( (string) $path->get_error_code(), $path->get_error_message() );
		}
		try {
			if ( ! class_exists( 'WP_CLI' ) ) {
				return static_site_importer_cli_import_error( 'static_site_importer_cli_runtime_unavailable', 'WP-CLI is unavailable for a fresh import runtime.' );
			}
			$spec    = static_site_importer_cli_import_fresh_runtime_spec( $path );
			$raw     = WP_CLI::runcommand( $spec['command'], $spec['options'] );
			$stdout  = is_object( $raw ) ? (string) ( $raw->stdout ?? '' ) : ( is_string( $raw ) ? $raw : '' );
			$decoded = static_site_importer_cli_decode_import_step( $stdout );
			if ( ! is_array( $decoded ) ) {
				return static_site_importer_cli_import_error( 'static_site_importer_cli_step_response_invalid', 'A fresh import runtime did not return JSON.' );
			}
			return $decoded;
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the host-owned continuation request after the fresh runtime returns.
			}
		}
	}
}

if ( ! function_exists( 'static_site_importer_cli_run_import_host' ) ) {
	/**
	 * Drive bounded ability steps until a terminal result.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	function static_site_importer_cli_run_import_host( array $input, ?callable $invoke = null, int $max_steps = 0 ): array {
		$invoke    = $invoke ?? 'static_site_importer_cli_import_run_fresh_runtime';
		$max_steps = min( 1024, max( 1, $max_steps > 0 ? $max_steps : static_site_importer_cli_import_max_steps() ) );
		$steps     = 0;
		while ( $steps < $max_steps ) {
			++$steps;
			$result = $invoke( $input );
			if ( ! is_array( $result ) ) {
				$result = static_site_importer_cli_import_error( 'static_site_importer_cli_step_response_invalid', 'An import step did not return an object.' );
			}
			if ( empty( $result['continuation'] ) ) {
				return static_site_importer_cli_import_receipt( $result, $steps );
			}
			$import_id = (string) ( $result['import_id'] ?? '' );
			if ( '' === $import_id ) {
				return static_site_importer_cli_import_receipt(
					static_site_importer_cli_import_error( 'static_site_importer_cli_import_id_missing', 'A continuation did not include an opaque import_id.' ),
					$steps
				);
			}
			$input = static_site_importer_cli_apply_import_id( $input, $import_id );
		}
		return static_site_importer_cli_import_receipt(
			static_site_importer_cli_import_error( 'static_site_importer_cli_continuation_bound_exceeded', 'The import exceeded its bounded continuation steps.' ),
			$max_steps
		);
	}
}

if ( ! function_exists( 'static_site_importer_cli_emit_import_receipt' ) ) {
	/** @param array<string,mixed> $receipt */
	function static_site_importer_cli_emit_import_receipt( array $receipt ): void {
		$json = wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			WP_CLI::error( 'Failed to encode import receipt.' );
		}
		WP_CLI::line( (string) $json );
		if ( 'completed' !== ( $receipt['status'] ?? '' ) ) {
			WP_CLI::halt( 1 );
		}
	}
}

if ( ! function_exists( 'static_site_importer_cli_emit_import_step' ) ) {
	/** @param array<string,mixed> $result */
	function static_site_importer_cli_emit_import_step( array $result ): void {
		$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			WP_CLI::error( 'Failed to encode import step result.' );
		}
		WP_CLI::line( (string) $json );
		if ( empty( $result['success'] ) ) {
			WP_CLI::halt( 1 );
		}
	}
}

if ( ! function_exists( 'static_site_importer_cli_import_command' ) ) {
	/** Canonical host command for static-site-importer/import. */
	function static_site_importer_cli_import_command( array $args, array $assoc_args ): void {
		$input = static_site_importer_cli_import_input( $args, $assoc_args );
		if ( is_wp_error( $input ) ) {
			static_site_importer_cli_emit_import_receipt(
				static_site_importer_cli_import_receipt(
					static_site_importer_cli_import_error( (string) $input->get_error_code(), $input->get_error_message() ),
					0
				)
			);
			return;
		}
		if ( isset( $assoc_args['single-step'] ) ) {
			static_site_importer_cli_emit_import_step( static_site_importer_cli_import( $input ) );
			return;
		}
		$max_steps = isset( $assoc_args['max-steps'] ) ? (int) $assoc_args['max-steps'] : 0;
		static_site_importer_cli_emit_import_receipt( static_site_importer_cli_run_import_host( $input, null, $max_steps ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'static-site-importer materialize-wordpress-site-plan',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$input = isset( $assoc_args['plan'] ) ? file_get_contents( (string) $assoc_args['plan'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-supplied canonical plan.
			$plan  = is_string( $input ) ? json_decode( $input, true ) : null;
			if ( ! is_array( $plan ) || empty( $assoc_args['slug'] ) ) {
				WP_CLI::error( 'Provide --plan=<canonical-plan.json> and --slug=<theme-slug>.' );
			}
			$receipt = Static_Site_Importer_Canonical_Import_Service::materialize_wordpress_site_plan(
				array(
					'plan'                   => $plan,
					'slug'                   => (string) $assoc_args['slug'],
					'activate'               => isset( $assoc_args['activate'] ),
					'site_title'             => isset( $assoc_args['site-title'] ) ? (string) $assoc_args['site-title'] : '',
					'overwrite'              => isset( $assoc_args['overwrite'] ),
					'disable_smilies'        => ! isset( $assoc_args['no-disable-smilies'] ),
					'remove_default_content' => ! isset( $assoc_args['keep-default-content'] ),
				)
			);
			WP_CLI::line( (string) wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES ) );
			if ( 'completed' !== $receipt['status'] ) {
				WP_CLI::halt( 1 );
			}
		}
	);

	WP_CLI::add_command( 'static-site-importer import', 'static_site_importer_cli_import_command' );

	WP_CLI::add_command(
		'static-site-importer plan-artifact-dependencies',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$input = static_site_importer_cli_artifact_input( $assoc_args );
			if ( is_wp_error( $input ) ) {
				WP_CLI::error( $input->get_error_message() );
			}
			$result = Static_Site_Importer_Validation_Runtime::plan_artifact_dependencies( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode dependency plan.' );
			}
			if ( ! empty( $assoc_args['output'] ) && false === file_put_contents( (string) $assoc_args['output'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes an explicit host handoff artifact.
				WP_CLI::error( 'Failed to write dependency plan output.' );
			}
			WP_CLI::line( (string) $json );
		}
	);

	WP_CLI::add_command(
		'static-site-importer prepare-artifact-dependencies',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$input = static_site_importer_cli_artifact_input( $assoc_args );
			if ( is_wp_error( $input ) ) {
				WP_CLI::error( $input->get_error_message() );
			}
			$result = Static_Site_Importer_Validation_Runtime::prepare_artifact_dependencies( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			/** @var array<string,mixed> $result */
			$artifact_digest = Static_Site_Importer_Validation_Runtime::lifecycle_artifact_digest_from_file( (string) ( $assoc_args['artifact'] ?? '' ) );
			if ( is_wp_error( $artifact_digest ) ) {
				WP_CLI::error( $artifact_digest->get_error_message() );
			}
			$result['artifact_digest'] = $artifact_digest;
			$json                      = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode dependency preparation receipt.' );
			}
			if ( empty( $assoc_args['receipt'] ) || false === file_put_contents( (string) $assoc_args['receipt'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes its explicit lifecycle handoff receipt.
				WP_CLI::error( 'Dependency preparation requires a writable --receipt path.' );
			}
			WP_CLI::line( (string) $json );
		}
	);

	WP_CLI::add_command(
		'static-site-importer validate-artifact',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$halt_on_failure = ! isset( $assoc_args['allow-failure'] ) && false !== ( $assoc_args['error-on-fail'] ?? true ) && ! isset( $assoc_args['no-error-on-fail'] );
			$format          = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'full';
			if ( ! in_array( $format, array( 'full', 'fixture-matrix' ), true ) ) {
				WP_CLI::error( 'The --format value must be full or fixture-matrix.' );
			}

			$input = array(
				'slug'                                 => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                                 => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'                             => ! isset( $assoc_args['no-activate'] ),
				'overwrite'                            => ! isset( $assoc_args['no-overwrite'] ),
				'fail_on_quality'                      => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce'            => isset( $assoc_args['allow-missing-woocommerce'] ),
				'require_proven_dynamic_client_assets' => ! isset( $assoc_args['allow-unproven-dynamic-client-assets'] ),
			);
			$input = static_site_importer_cli_apply_client_script_args( $input, $assoc_args );
			if ( isset( $assoc_args['host-staged-dependencies'] ) ) {
				$input['materialize_dependencies'] = false;
			}
			$output = isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : '';
			if ( isset( $assoc_args['artifact-dir'] ) ) {
				$input['artifact_dir'] = (string) $assoc_args['artifact-dir'];
			}

			if ( isset( $assoc_args['artifact'] ) ) {
				$artifact_json = file_get_contents( (string) $assoc_args['artifact'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-provided artifact file.
				$artifact      = json_decode( false === $artifact_json ? '' : $artifact_json, true );
				if ( ! is_array( $artifact ) ) {
					WP_CLI::error( 'The --artifact file must contain a JSON object.' );
				}

				$input['artifact'] = $artifact;
			}
			if ( isset( $assoc_args['lifecycle-receipt'] ) ) {
				$receipt_json  = file_get_contents( (string) $assoc_args['lifecycle-receipt'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads its explicit lifecycle handoff receipt.
				$receipt       = json_decode( false === $receipt_json ? '' : $receipt_json, true );
				$artifact_path = isset( $assoc_args['artifact'] ) ? (string) $assoc_args['artifact'] : '';
				if ( ! is_array( $receipt ) || 'static-site-importer/runtime-lifecycle-receipt/v1' !== ( $receipt['schema'] ?? '' ) || 'dependencies_prepared' !== ( $receipt['status'] ?? '' ) || ! isset( $input['artifact'] ) || ! Static_Site_Importer_Validation_Runtime::lifecycle_receipt_matches_artifact( $receipt, $artifact_path, $input['artifact'] ) ) {
					WP_CLI::error( 'The --lifecycle-receipt must be a completed receipt for this exact artifact.' );
				}
				$input['runtime_lifecycle_phase']      = 'resume';
				$input['runtime_lifecycle_request_id'] = (string) ( $receipt['fresh_runtime']['request_id'] ?? '' );
				$input['runtime_lifecycle_checkpoint'] = (string) ( $receipt['fresh_runtime']['lifecycle_checkpoint_id'] ?? $receipt['runtime_lifecycle_checkpoint'] ?? '' );
			}

			if ( isset( $assoc_args['generated-theme-ref'] ) ) {
				$input['generated_theme_ref'] = array( 'artifact_ref' => (string) $assoc_args['generated-theme-ref'] );
			}

			if ( isset( $assoc_args['theme-archive-ref'] ) ) {
				$input['theme_archive_ref'] = array( 'artifact_ref' => (string) $assoc_args['theme-archive-ref'] );
			}
			$sidecar_contract = static_site_importer_cli_materialization_sidecar_contract( $assoc_args );
			if ( is_wp_error( $sidecar_contract ) ) {
				WP_CLI::error( $sidecar_contract->get_error_message(), 1 );
			}

			$result = Static_Site_Importer_Validation_Runtime::validate_artifact( $input );
			if ( is_wp_error( $result ) ) {
				$error_result = Static_Site_Importer_Validation_Runtime::error_result_from_wp_error( $result, $input );
				if ( 'fixture-matrix' === $format ) {
					$error_result = Static_Site_Importer_Validation_Runtime::fixture_matrix_result( $error_result );
				}
				if ( true === $sidecar_contract ) {
					$sidecar_result = static_site_importer_cli_write_materialization_sidecar( $error_result, $assoc_args );
					if ( is_wp_error( $sidecar_result ) ) {
						WP_CLI::error( $sidecar_result->get_error_message(), 1 );
					}
				}
				$json = wp_json_encode( $error_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( false === $json ) {
					WP_CLI::error( $result->get_error_message() );
				}

				static_site_importer_cli_write_validation_output( (string) $json, $output );
				if ( $halt_on_failure ) {
					WP_CLI::halt( 1 );
				}

				return;
			}

			if ( true === $sidecar_contract ) {
				$sidecar_result = static_site_importer_cli_write_materialization_sidecar( $result, $assoc_args );
				if ( is_wp_error( $sidecar_result ) ) {
					WP_CLI::error( $sidecar_result->get_error_message(), 1 );
				}
			}
			if ( 'fixture-matrix' === $format ) {
				$result = Static_Site_Importer_Validation_Runtime::fixture_matrix_result( $result );
			}
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode validation result.' );
				return;
			}

			static_site_importer_cli_write_validation_output( $json, $output );
			if ( $halt_on_failure && empty( $result['success'] ) ) {
				WP_CLI::halt( 1 );
			}
		}
	);

	WP_CLI::add_command(
		'static-site-importer figma-diagnostics',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );

			if ( empty( $assoc_args['input'] ) ) {
				WP_CLI::error( 'Provide a Figma request JSON file with --input=<path>.' );
				return;
			}

			$input_json = file_get_contents( (string) $assoc_args['input'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-provided request file.
			$input      = json_decode( false === $input_json ? '' : $input_json, true );
			if ( ! is_array( $input ) ) {
				WP_CLI::error( 'The --input file must contain a JSON object.' );
				return;
			}

			$result = Static_Site_Importer_Figma_Import::diagnostics_report( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
				return;
			}

			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode Figma diagnostics result.' );
				return;
			}

			WP_CLI::line( $json );
		}
	);
}

/** Build the common artifact input for lifecycle commands without provider setup. */
function static_site_importer_cli_artifact_input( array $assoc_args ) {
	if ( empty( $assoc_args['artifact'] ) ) {
		return new WP_Error( 'static_site_importer_cli_artifact_missing', 'Provide an artifact JSON file with --artifact.' );
	}
	$artifact_json = file_get_contents( (string) $assoc_args['artifact'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-provided artifact file.
	$artifact      = json_decode( false === $artifact_json ? '' : $artifact_json, true );
	if ( ! is_array( $artifact ) ) {
		return new WP_Error( 'static_site_importer_cli_artifact_invalid', 'The --artifact file must contain a JSON object.' );
	}
	return static_site_importer_cli_apply_client_script_args(
		array(
			'artifact'  => $artifact,
			'slug'      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
			'name'      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
			'activate'  => ! isset( $assoc_args['no-activate'] ),
			'overwrite' => ! isset( $assoc_args['no-overwrite'] ),
		),
		$assoc_args
	);
}

/** Apply the explicit isolated-preview script policy shared by artifact lifecycle commands. */
function static_site_importer_cli_apply_client_script_args( array $input, array $assoc_args ): array {
	if ( isset( $assoc_args['client-script-policy'] ) ) {
		$input['client_script_policy'] = (string) $assoc_args['client-script-policy'];
	}
	if ( isset( $assoc_args['client-script-provenance'] ) ) {
		$input['client_script_provenance'] = array( 'ref' => (string) $assoc_args['client-script-provenance'] );
	}
	if ( isset( $assoc_args['client-script-isolated'] ) ) {
		$input['client_script_isolated'] = true;
	}

	return $input;
}

/**
 * A sidecar is an opt-in CLI contract. Legacy validate-artifact callers retain
 * their prior result and exit behavior; partial receipt identities fail early.
 *
 * @param array<string,mixed> $args CLI arguments.
 * @return bool|WP_Error True when required, false when absent.
 */
function static_site_importer_cli_materialization_sidecar_contract( array $args ) {
	$keys    = array( 'receipt-sidecar', 'receipt-run-id', 'receipt-step-id', 'receipt-attempt-id' );
	$present = array_filter( $keys, static fn( string $key ): bool => array_key_exists( $key, $args ) );
	if ( empty( $present ) ) {
		return false;
	}
	if ( count( $present ) !== count( $keys ) ) {
		return new WP_Error( 'static_site_importer_sidecar_contract_partial', 'Materialization sidecar requires --receipt-sidecar, --receipt-run-id, --receipt-step-id, and --receipt-attempt-id together.' );
	}
	return true;
}

/**
 * Persist compact matrix evidence before verbose WP-CLI output can be truncated.
 *
 * @param array<string,mixed> $result Validation result.
 * @param array<string,mixed> $args CLI arguments.
 */
function static_site_importer_cli_write_materialization_sidecar( array $result, array $args ) {
	$path       = isset( $args['receipt-sidecar'] ) ? (string) $args['receipt-sidecar'] : '';
	$fixture_id = isset( $result['fixture_id'] ) ? (string) $result['fixture_id'] : ( isset( $args['slug'] ) ? (string) $args['slug'] : '' );
	$run_id     = isset( $args['receipt-run-id'] ) ? (string) $args['receipt-run-id'] : '';
	$step_id    = isset( $args['receipt-step-id'] ) ? (string) $args['receipt-step-id'] : '';
	$attempt_id = isset( $args['receipt-attempt-id'] ) ? (string) $args['receipt-attempt-id'] : '';
	if ( '' === $path || ! static_site_importer_cli_sidecar_token( $fixture_id, 80 ) || ! static_site_importer_cli_sidecar_token( $run_id, 160 ) || 'import' !== $step_id || ! static_site_importer_cli_sidecar_token( $attempt_id, 80 ) ) {
		return new WP_Error( 'static_site_importer_sidecar_identity_invalid', 'Required materialization sidecar identity is missing or invalid.' );
	}
	$artifact_path = isset( $args['artifact'] ) ? (string) $args['artifact'] : '';
	$artifact_hash = is_readable( $artifact_path ) ? hash_file( 'sha256', $artifact_path ) : '';
	if ( ! is_string( $artifact_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $artifact_hash ) ) {
		return new WP_Error( 'static_site_importer_sidecar_artifact_hash_missing', 'Required materialization sidecar artifact hash could not be calculated.' );
	}
	$receipt                   = isset( $result['materialization_receipt'] ) && is_array( $result['materialization_receipt'] ) ? $result['materialization_receipt'] : array();
	$completed                 = isset( $receipt['completed'] ) && is_array( $receipt['completed'] ) ? $receipt['completed'] : array();
	$is_completed              = 'static-site-importer/materialization-receipt/v2' === ( $receipt['schema'] ?? '' ) && 'completed' === ( $receipt['status'] ?? '' ) && is_array( $receipt['plan_identity'] ?? null ) && is_string( $receipt['plan_identity']['schema'] ?? null ) && is_string( $receipt['plan_identity']['hash'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/', $receipt['plan_identity']['hash'] ) && isset( $completed['pages'], $completed['files'] ) && is_array( $completed['pages'] ) && is_array( $completed['files'] );
	$summary                   = $is_completed ? static_site_importer_cli_materialization_summary( $receipt, $result ) : static_site_importer_cli_failed_materialization_summary( $result );
	$documents                 = $is_completed ? static_site_importer_cli_materialized_documents( $completed['pages'] ) : array(
		'rows'      => array(),
		'truncated' => false,
		'total'     => 0,
	);
	$sidecar                   = array(
		'schema'              => 'static-site-importer/materialization-runtime-sidecar/v2',
		'fixture_id'          => $fixture_id,
		'run_id'              => $run_id,
		'step_id'             => $step_id,
		'attempt_id'          => $attempt_id,
		'artifact_sha256'     => $artifact_hash,
		'provenance'          => array(
			'provider'        => (string) ( $result['runtime']['provider'] ?? 'static-site-importer/current-runtime' ),
			'provider_status' => $is_completed ? 'completed' : 'failed',
		),
		'durability'          => array(
			'file_fsync'      => function_exists( 'fsync' ) ? 'available' : 'unavailable',
			'directory_fsync' => function_exists( 'fsync' ) ? 'attempted' : 'unavailable',
		),
		'receipt'             => $summary,
		'command_result'      => array(
			'status'     => $is_completed ? 'completed' : 'failed',
			'success'    => $is_completed,
			'error_code' => $is_completed ? '' : static_site_importer_cli_sidecar_token_value( $result['error']['code'] ?? $result['code'] ?? 'import_failed', 80 ),
			'error_hash' => hash( 'sha256', (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		),
		'front_page_options'  => array(
			'show_on_front' => static_site_importer_cli_sidecar_token_value( get_option( 'show_on_front' ), 20 ),
			'page_on_front' => min( 10000000, max( 0, (int) get_option( 'page_on_front' ) ) ),
		),
		'documents'           => $documents['rows'],
		'documents_truncated' => $documents['truncated'],
		'documents_total'     => $documents['total'],
	);
	$sidecar['content_sha256'] = hash( 'sha256', (string) wp_json_encode( $sidecar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	$json                      = wp_json_encode( $sidecar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json || strlen( $json ) > 32768 ) {
		return new WP_Error( 'static_site_importer_sidecar_too_large', 'Required materialization sidecar exceeds its 32 KiB bound.' );
	}
	$directory = dirname( $path );
	if ( ! wp_mkdir_p( $directory ) ) {
		return new WP_Error( 'static_site_importer_sidecar_directory_failed', 'Required materialization sidecar directory could not be created.' );
	}
	$temp = tempnam( $directory, '.ssi-sidecar-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- same-directory temporary file is required for atomic rename.
	if ( false === $temp ) {
		return new WP_Error( 'static_site_importer_sidecar_temp_failed', 'Required materialization sidecar temporary file could not be created.' );
	}
	$handle = fopen( $temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- explicit CLI artifact publication.
	try {
		$bytes = strlen( $json ) + 1;
		if ( false === $handle || fwrite( $handle, $json . "\n" ) !== $bytes || ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) || ! fclose( $handle ) || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory publication.
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after failed atomic publish.
			}
			return new WP_Error( 'static_site_importer_sidecar_persist_failed', 'Required materialization sidecar could not be atomically persisted.' );
		}
		static_site_importer_cli_fsync_directory( $directory );
	} finally {
		if ( file_exists( $temp ) ) {
			unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only this bounded temporary sidecar.
		}
	}
	return true;
}

/**
 * Project materialized posts to bounded source, route, and content identities for matrix joins.
 *
 * @param array<string,mixed> $pages Materialization receipt source-path to post-id map.
 * @return array{rows:array<int,array<string,mixed>>,truncated:bool,total:int}
 */
function static_site_importer_cli_materialized_documents( array $pages ): array {
	$rows     = array();
	$max_rows = 25;
	$total    = 0;
	foreach ( $pages as $source_path => $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$permalink   = get_permalink( $post );
		$source_path = static_site_importer_cli_sidecar_lineage_value( $source_path );
		$route       = static_site_importer_cli_sidecar_route_value( wp_parse_url( (string) $permalink, PHP_URL_PATH ) );
		if ( '' === $source_path || '' === $route ) {
			continue;
		}
		++$total;
		if ( count( $rows ) >= $max_rows ) {
			continue;
		}
		$rows[] = array(
			'source_path'               => $source_path,
			'route'                     => $route,
			'post_id'                   => (string) $post->ID,
			'post_type'                 => (string) $post->post_type,
			'post_slug'                 => (string) $post->post_name,
			'serialized_content_sha256' => hash( 'sha256', (string) $post->post_content ),
		);
	}

	return array(
		'rows'      => $rows,
		'truncated' => $total > count( $rows ),
		'total'     => $total,
	);
}

/** @return array<string,mixed> */
function static_site_importer_cli_failed_materialization_summary( array $result ): array {
	$error_code = static_site_importer_cli_sidecar_token_value( $result['error']['code'] ?? $result['code'] ?? 'import_failed', 80 );
	return array(
		'schema'          => 'static-site-importer/materialization-receipt/v2',
		'status'          => 'failed',
		'page_count'      => 0,
		'file_count'      => 0,
		'operation_count' => 0,
		'loss_count'      => 1,
		'failure_code'    => $error_code ? $error_code : 'import_failed',
	);
}

function static_site_importer_cli_fsync_directory( string $directory ): void {
	if ( ! function_exists( 'fsync' ) ) {
		return;
	}
	$handle = @fopen( $directory, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- best-effort directory durability on supported platforms.
	if ( false !== $handle ) {
		@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unsupported directory fsync remains non-fatal.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the best-effort directory handle.
	}
}

/** @return array<string,mixed> */
function static_site_importer_cli_materialization_summary( array $receipt, array $result ): array {
	$completed      = isset( $receipt['completed'] ) && is_array( $receipt['completed'] ) ? $receipt['completed'] : array();
	$operations     = isset( $completed['operations'] ) && is_array( $completed['operations'] ) ? $completed['operations'] : array();
	$diagnostics    = isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array();
	$operation_rows = array();
	$loss_rows      = array();
	foreach ( array_slice( $operations, 0, 25 ) as $operation ) {
		if ( is_array( $operation ) ) {
			$row = array_filter(
				array(
					'kind'        => static_site_importer_cli_sidecar_token_value( $operation['kind'] ?? $operation['type'] ?? $operation['operation'] ?? '', 80 ),
					'status'      => static_site_importer_cli_sidecar_token_value( $operation['status'] ?? '', 40 ),
					'reason_code' => static_site_importer_cli_sidecar_token_value( $operation['reason_code'] ?? '', 80 ),
					'hash'        => hash( 'sha256', (string) wp_json_encode( $operation ) ),
				)
			);
			if ( ! empty( $row['kind'] ) ) {
				$operation_rows[] = $row;
			}
		}
	}
	foreach ( array_slice( $diagnostics, 0, 25 ) as $diagnostic ) {
		if ( is_array( $diagnostic ) ) {
			$row = array_filter(
				array(
					'kind'        => static_site_importer_cli_sidecar_token_value( $diagnostic['kind'] ?? $diagnostic['code'] ?? $diagnostic['type'] ?? '', 80 ),
					'reason_code' => static_site_importer_cli_sidecar_token_value( $diagnostic['reason_code'] ?? '', 80 ),
					'hash'        => hash( 'sha256', (string) wp_json_encode( $diagnostic ) ),
				)
			);
			if ( ! empty( $row['kind'] ) ) {
				$loss_rows[] = $row;
			}
		}
	}
	$layout        = isset( $receipt['computed_layout'] ) && is_array( $receipt['computed_layout'] ) ? $receipt['computed_layout'] : array();
	$plan_identity = is_array( $receipt['plan_identity'] ?? null ) ? $receipt['plan_identity'] : array();
	return array(
		'schema'                 => 'static-site-importer/materialization-receipt/v2',
		'status'                 => 'completed',
		'plan_identity'          => $plan_identity,
		'page_count'             => min( 10000000, count( $completed['pages'] ?? array() ) ),
		'file_count'             => min( 10000000, count( $completed['files'] ?? array() ) ),
		'operation_count'        => min( 10000000, count( $operations ) ),
		'loss_count'             => min( 10000000, count( $diagnostics ) ),
		'provider_totals'        => array( 'completed' => ! empty( $result['runtime']['provider'] ) ? 1 : 0 ),
		'computed_layout_totals' => array_filter(
			array(
				'applied'    => isset( $layout['applied'] ) ? (int) $layout['applied'] : null,
				'losses'     => isset( $layout['losses'] ) ? (int) $layout['losses'] : null,
				'operations' => count( array_filter( $operations, static fn( $operation ): bool => is_array( $operation ) && false !== strpos( (string) wp_json_encode( $operation ), 'computed_layout' ) ) ),
			),
			static fn( $value ): bool => null !== $value
		),
		'operation_rows'         => $operation_rows,
		'loss_rows'              => $loss_rows,
		'truncated'              => array(
			'operation_rows' => count( $operations ) > 25,
			'loss_rows'      => count( $diagnostics ) > 25,
		),
	);
}

function static_site_importer_cli_sidecar_token( $value, int $maximum ): bool {
	return is_string( $value ) && 0 < strlen( $value ) && $maximum >= strlen( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value );
}

function static_site_importer_cli_sidecar_token_value( $value, int $maximum ): string {
	$value = is_scalar( $value ) ? (string) $value : '';
	return static_site_importer_cli_sidecar_token( $value, $maximum ) ? $value : '';
}

/**
 * The matrix sidecar keeps source lineage printable and bounded so its compact
 * transport can retain it without accepting control characters.
 *
 * @param mixed $value Source path from the materialization receipt.
 */
function static_site_importer_cli_sidecar_lineage_value( $value ): string {
	$value = is_string( $value ) ? $value : '';
	return 0 < strlen( $value ) && 500 >= strlen( $value ) && 1 === preg_match( '/^[\x20-\x7E]+$/', $value ) ? $value : '';
}

/**
 * @param mixed $value URL path returned by wp_parse_url().
 */
function static_site_importer_cli_sidecar_route_value( $value ): string {
	$value = static_site_importer_cli_sidecar_lineage_value( $value );
	return '/' === substr( $value, 0, 1 ) ? $value : '';
}
