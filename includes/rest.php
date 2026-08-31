<?php
/**
 * Importer REST routes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Site_Identity' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-identity.php';
}

if ( ! class_exists( 'Static_Site_Importer_Content_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-content-policy.php';
}

if ( ! class_exists( 'Static_Site_Importer_URL_Import_Runtime' ) ) {
	require_once __DIR__ . '/class-static-site-importer-url-import-runtime.php';
}

/**
 * Register Static Site Importer REST routes.
 *
 * @return void
 */
function static_site_importer_register_rest_routes(): void {
	register_rest_route(
		'static-site-importer/v1',
		'/imports',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'static_site_importer_rest_create_import',
			'permission_callback' => 'static_site_importer_rest_manage_permission',
		)
	);

	register_rest_route(
		'static-site-importer/v1',
		'/import-figma',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'static_site_importer_rest_import_figma',
				'permission_callback' => 'static_site_importer_rest_import_figma_permission',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => 'static_site_importer_rest_import_figma_preflight',
				'permission_callback' => '__return_true',
			),
		)
	);

	register_rest_route(
		'static-site-importer/v1',
		'/import-figma-file',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'static_site_importer_rest_import_figma_file',
			'permission_callback' => 'static_site_importer_rest_manage_permission',
		)
	);
}

/**
 * Permission callback for Figma runner imports.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function static_site_importer_rest_import_figma_permission( WP_REST_Request $request ) {
	$operator = static_site_importer_rest_manage_permission();
	if ( true === $operator ) {
		return true;
	}

	if ( static_site_importer_rest_import_figma_allows_local_runner( $request ) ) {
		return true;
	}

	return $operator;
}

/**
 * Handle CORS preflight for the Figma runner endpoint.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function static_site_importer_rest_import_figma_preflight( WP_REST_Request $request ): WP_REST_Response {
	$response = new WP_REST_Response( null, 204 );
	static_site_importer_rest_add_figma_cors_headers( $response, $request );

	return $response;
}

/**
 * Import a Figma runner request and return the Figma plugin runner response shape.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function static_site_importer_rest_import_figma( WP_REST_Request $request ) {
	$input = $request->get_json_params();

	$artifact = Static_Site_Importer_Figma_Import::website_artifact_from_input( $input );
	if ( is_wp_error( $artifact ) ) {
		return $artifact;
	}

	$params       = array_merge(
		$input,
		array(
			'activate'  => array_key_exists( 'activate', $input ) ? ! empty( $input['activate'] ) : true,
			'overwrite' => array_key_exists( 'overwrite', $input ) ? ! empty( $input['overwrite'] ) : true,
		)
	);
	$import_input = Static_Site_Importer_Figma_Import::import_input( $params, $artifact );
	$result       = static_site_importer_rest_execute_import_ability(
		'static-site-importer/import',
		array_merge( $import_input, array( 'source' => static_site_importer_ability_files_source( $artifact ) ) ),
		'static_site_importer_ability_import'
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$response = rest_ensure_response( Static_Site_Importer_Figma_Import::runner_response( $result ) );
	static_site_importer_rest_add_figma_cors_headers( $response, $request );

	return $response;
}

/**
 * Import a multipart .fig upload.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function static_site_importer_rest_import_figma_file( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	$file  = isset( $files['figma_file'] ) && is_array( $files['figma_file'] ) ? $files['figma_file'] : array();
	$name  = isset( $file['name'] ) ? (string) $file['name'] : '';
	$tmp   = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

	if ( '' === $name || '' === $tmp || ! empty( $file['error'] ) ) {
		return new WP_Error( 'static_site_importer_figma_file_missing', __( 'Upload a Figma .fig file to start.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$input = static_site_importer_rest_import_args( $request->get_params() );
	if ( empty( $input['slug'] ) ) {
		$input['slug'] = sanitize_title( preg_replace( '/\.fig$/i', '', $name ) );
	}
	if ( empty( $input['name'] ) ) {
		$input['name'] = preg_replace( '/\.fig$/i', '', $name );
	}

	$artifact = Static_Site_Importer_Figma_Import::website_artifact_from_figma_upload( $tmp, $name, $input );
	if ( is_wp_error( $artifact ) ) {
		return $artifact;
	}

	$input['artifact'] = $artifact;

	$input['activate']  = array_key_exists( 'activate', $request->get_params() ) ? ! empty( $request->get_param( 'activate' ) ) : true;
	$input['overwrite'] = array_key_exists( 'overwrite', $request->get_params() ) ? ! empty( $request->get_param( 'overwrite' ) ) : true;
	$result             = static_site_importer_rest_execute_import_ability(
		'static-site-importer/import',
		array_merge( $input, array( 'source' => static_site_importer_ability_files_source( $artifact ) ) ),
		'static_site_importer_ability_import'
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Add CORS headers for the local Figma runner endpoint when explicitly enabled.
 *
 * @param mixed           $response REST response.
 * @param WP_REST_Request $request  REST request.
 * @return void
 */
function static_site_importer_rest_add_figma_cors_headers( $response, WP_REST_Request $request ): void {
	if ( ! $response instanceof WP_REST_Response ) {
		return;
	}

	if ( ! static_site_importer_rest_import_figma_allows_local_runner( $request ) ) {
		return;
	}

	$origin = (string) $request->get_header( 'origin' );
	if ( '' === $origin ) {
		$origin = 'null';
	}

	$response->header( 'Access-Control-Allow-Origin', $origin );
	$response->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
	$response->header( 'Access-Control-Allow-Headers', 'content-type, x-wp-nonce' );
	$response->header( 'Vary', 'Origin', false );
}

/**
 * Determine whether the local Figma runner is explicitly enabled for this site.
 *
 * @param WP_REST_Request $request REST request.
 * @return bool
 */
function static_site_importer_rest_import_figma_allows_local_runner( WP_REST_Request $request ): bool {
	if ( ! (bool) get_option( 'static_site_importer_figma_allow_local_runner', false ) ) {
		return false;
	}

	$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( ! in_array( $site_host, static_site_importer_rest_figma_allowed_site_hosts(), true ) ) {
		return false;
	}

	$origin = (string) $request->get_header( 'origin' );
	if ( '' === $origin || 'null' === $origin ) {
		return true;
	}

	$origin_host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );

	return in_array( $origin_host, array( 'localhost', '127.0.0.1', '::1' ), true );
}

/**
 * Return site hosts that may expose the unauthenticated local Figma runner route.
 *
 * Local development hosts are allowed by default. Public/proxied runtimes must be
 * explicitly opted in with the static_site_importer_figma_allowed_site_hosts option.
 *
 * @return array<int,string>
 */
function static_site_importer_rest_figma_allowed_site_hosts(): array {
	$hosts      = array( 'localhost', '127.0.0.1', '::1' );
	$configured = get_option( 'static_site_importer_figma_allowed_site_hosts', array() );
	if ( is_string( $configured ) ) {
		$configured_hosts = preg_split( '/[\s,]+/', $configured );
		$configured       = false === $configured_hosts ? array() : $configured_hosts;
	}
	if ( is_array( $configured ) ) {
		foreach ( $configured as $host ) {
			if ( is_scalar( $host ) ) {
				$hosts[] = (string) $host;
			}
		}
	}

	$hosts = array_values(
		array_unique(
			array_filter(
				array_map(
					static fn( string $host ): string => strtolower( trim( $host ) ),
					$hosts
				),
				static fn( string $host ): bool => '' !== $host
			)
		)
	);

	/**
	 * Filters hosts allowed to expose the unauthenticated local Figma runner route.
	 *
	 * @param array<int,string> $hosts Allowed lowercase hostnames.
	 */
	$hosts = apply_filters( 'static_site_importer_figma_allowed_site_hosts', $hosts );

	return array_values( array_filter( array_map( 'strval', $hosts ) ) );
}

/**
 * Require a site operator for import mutations.
 *
 * @return true|WP_Error
 */
function static_site_importer_rest_manage_permission() {
	$allowed = ! function_exists( 'current_user_can' ) || current_user_can( 'switch_themes' );

	/**
	 * Filters whether the current request may run import mutations.
	 *
	 * Host products can grant their own product-specific capability without giving
	 * users broad theme-management access.
	 *
	 * @param bool $allowed Whether the current request is allowed.
	 */
	$allowed = (bool) apply_filters( 'static_site_importer_can_manage_imports', $allowed );

	if ( $allowed ) {
		return true;
	}

	return new WP_Error(
		'static_site_importer_forbidden',
		__( 'You are not allowed to run static site imports on this site.', 'static-site-importer' ),
		array( 'status' => function_exists( 'is_user_logged_in' ) && is_user_logged_in() ? 403 : 401 )
	);
}

/**
 * Create an import from a URL, raw HTML, or uploaded file bundle.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function static_site_importer_rest_create_import( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	/** @var array<string,mixed>|null $params WordPress returns null when no JSON body was parsed. */
	if ( ! is_array( $params ) ) {
		$params = $request->get_params();
	}

	$source = isset( $params['source'] ) && is_array( $params['source'] ) ? $params['source'] : array();
	$input  = static_site_importer_rest_import_args( $params );
	if ( isset( $params['provider'] ) ) {
		$input['provider'] = sanitize_key( (string) $params['provider'] );
	}
	if ( isset( $params['provider_args'] ) && is_array( $params['provider_args'] ) ) {
		$input['provider_args'] = $params['provider_args'];
	}
	if ( static_site_importer_rest_is_url_only_source( $source ) ) {
		$url_result = static_site_importer_rest_route_url_import( $source, $input );
		if ( is_wp_error( $url_result ) ) {
			return $url_result;
		}

		return rest_ensure_response( $url_result );
	}

	$result = static_site_importer_rest_apply_to_current_site( $source, $input );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Apply an import to the installed WordPress site.
 *
 * @param array<string,mixed> $source Source payload.
 * @param array<string,mixed> $input  Import args.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_apply_to_current_site( array $source, array $input ) {
	// Current-site materialization is always inert even when a request carries preview options.
	$input['client_script_policy']     = 'inert';
	$input['client_script_isolated']   = false;
	$input['client_script_provenance'] = array();
	$decorate_current_site_preview     = static function ( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$preview_url = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$preview     = isset( $result['preview'] ) && is_array( $result['preview'] ) ? $result['preview'] : array();
		if ( '' !== $preview_url ) {
			$preview['url'] = $preview_url;
		}
		$preview['status'] = isset( $preview['status'] ) ? $preview['status'] : 'ready';

		$result['preview'] = $preview;

		return $result;
	};

	$runtime = static_site_importer_rest_source_runtime( $source, $input );
	if ( is_wp_error( $runtime ) ) {
		return $runtime;
	}

	$source_metadata = isset( $input['source_metadata'] ) && is_array( $input['source_metadata'] ) ? $input['source_metadata'] : array();
	$source_metadata = array_merge( $source_metadata, $runtime['source_metadata'] );
	if ( 'url' === (string) ( $source_metadata['source_type'] ?? '' ) && '' !== (string) $runtime['provider'] ) {
		$source_metadata['url_import_provider'] = (string) $runtime['provider'];
	}
	$input['source_metadata'] = $source_metadata;
	$input['source']          = static_site_importer_ability_files_source( $runtime['artifact'] );

	return $decorate_current_site_preview( static_site_importer_rest_execute_import_ability( 'static-site-importer/import', $input, 'static_site_importer_ability_import' ) );
}

/**
 * Execute a mutating SSI import through the shared ability boundary.
 *
 * @param string              $ability_name      Ability name.
 * @param array<string,mixed> $input             Ability input.
 * @param callable-string     $fallback_callback Local callback for non-Abilities test/runtime contexts.
 * @param bool                $prefer_fallback   Whether to call the local callback before wp_get_ability().
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_execute_import_ability( string $ability_name, array $input, string $fallback_callback, bool $prefer_fallback = false ) {
	if ( $prefer_fallback ) {
		return call_user_func( $fallback_callback, $input );
	}

	if ( function_exists( 'wp_get_ability' ) ) {
		$ability = wp_get_ability( $ability_name );
		if ( is_object( $ability ) ) {
			$result = $ability->execute( $input );

			return $result;
		}
	}

	return call_user_func( $fallback_callback, $input );
}

/**
 * Route a URL-only REST import through the canonical unified import ability.
 *
 * The unified `static-site-importer/import` ability dispatches on `type`; setting
	 * `type=url` routes to {@see Static_Site_Importer_Canonical_Import_Service::import_url_operation()}.
 * This helper shapes the input the ability expects and unwraps the result
 * envelope into the REST response shape.
 *
 * @param array<string,mixed> $source Source payload (expected to contain `url`).
 * @param array<string,mixed> $input  Normalized import args.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_route_url_import( array $source, array $input ) {
	$url       = isset( $source['url'] ) ? (string) $source['url'] : '';
	$import_id = isset( $source['import_id'] ) ? (string) $source['import_id'] : ( isset( $input['import_id'] ) ? (string) $input['import_id'] : '' );

	$ability_in = array_merge(
		$input,
		array(
			'source' => array_merge(
				isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array(),
				array(
					'type'      => 'url',
					'url'       => $url,
					'import_id' => $import_id,
				)
			),
		)
	);

	$result = static_site_importer_rest_execute_import_ability(
		'static-site-importer/import',
		$ability_in,
		'static_site_importer_ability_import'
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! empty( $result['continuation'] ) ) {
		return array(
			'success'               => true,
			'continuation'          => true,
			'continuation_reason'   => isset( $result['continuation_reason'] ) ? (string) $result['continuation_reason'] : '',
			'import_id'             => isset( $result['import_id'] ) ? (string) $result['import_id'] : '',
			'url_batch_run'         => isset( $result['url_batch_run'] ) && is_array( $result['url_batch_run'] ) ? $result['url_batch_run'] : array(),
			'import_report_summary' => isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array(),
		);
	}

	return array(
		'success'               => true,
		'import_id'             => isset( $result['import_id'] ) ? (string) $result['import_id'] : '',
		'result'                => isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array(),
		'diagnostics'           => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(),
		'fixture_diagnostics'   => isset( $result['fixture_diagnostics'] ) && is_array( $result['fixture_diagnostics'] ) ? $result['fixture_diagnostics'] : array(),
		'import_report_summary' => isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array(),
		'terminal_batch_result' => isset( $result['url_batch_run']['terminal_batch_result'] ) && is_array( $result['url_batch_run']['terminal_batch_result'] )
			? $result['url_batch_run']['terminal_batch_result']
			: array(),
	);
}

/**
 * Build import args from REST input.
 *
 * @param array<string,mixed> $params Request params.
 * @return array<string,mixed>
 */
function static_site_importer_rest_import_args( array $params ): array {
	$params['slug']            = isset( $params['slug'] ) ? sanitize_title( (string) $params['slug'] ) : '';
	$params['name']            = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
	$params['source_metadata'] = array_merge(
		isset( $params['source_metadata'] ) && is_array( $params['source_metadata'] ) ? $params['source_metadata'] : array(),
		array( 'source' => 'static_site_importer_rest' )
	);

	return Static_Site_Importer_Website_Artifact_Import_Input::normalize( $params );
}

/**
 * Convert raw HTML or uploaded file JSON into a website artifact.
 *
 * @param array<string,mixed> $source Source payload.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_source_artifact( array $source ) {
	$runtime = static_site_importer_source_runtime( $source );
	if ( is_wp_error( $runtime ) ) {
		return $runtime;
	}

	return $runtime['artifact'];
}

/**
 * Convert REST source input into the normalized website artifact runtime envelope.
 *
 * @param array<string,mixed> $source Source payload.
 * @return array{artifact:array<string,mixed>,source_metadata:array<string,mixed>,provider:string}|WP_Error
 */
function static_site_importer_source_runtime( array $source ) {
	if ( isset( $source['artifact'] ) && is_array( $source['artifact'] ) ) {
		return array(
			'artifact'        => $source['artifact'],
			'source_metadata' => array(),
			'provider'        => 'provided-artifact',
		);
	}

	if ( isset( $source['figma_file'] ) && is_array( $source['figma_file'] ) ) {
		$artifact = Static_Site_Importer_Figma_Import::website_artifact_from_input( array( 'source' => $source ) );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}

		return array(
			'artifact'        => $artifact,
			'source_metadata' => array(),
			'provider'        => 'figma-file',
		);
	}

	if ( static_site_importer_rest_is_url_only_source( $source ) ) {
		return new WP_Error(
			'static_site_importer_url_source_routed_separately',
			__( 'URL sources are routed through the static-site-importer/import ability and must be handled by the unified import path before reaching this dispatcher.', 'static-site-importer' ),
			array( 'status' => 500 )
		);
	}

	$files        = array();
	$metadata     = isset( $source['metadata'] ) && is_array( $source['metadata'] ) ? $source['metadata'] : array();
	$report_paths = isset( $metadata['reports'] ) && is_array( $metadata['reports'] ) ? $metadata['reports'] : array();

	if ( isset( $source['html'] ) && '' !== trim( (string) $source['html'] ) ) {
		$files[] = array(
			'path'    => 'website/index.html',
			'content' => (string) $source['html'],
		);
	}

	if ( isset( $source['files'] ) && is_array( $source['files'] ) ) {
		foreach ( $source['files'] as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$path = isset( $file['path'] ) ? static_site_importer_rest_source_file_path( (string) $file['path'], $report_paths ) : '';
			if ( '' === $path ) {
				continue;
			}

			if ( ! static_site_importer_rest_should_include_artifact_file( $path ) ) {
				continue;
			}

			if ( isset( $file['content'] ) ) {
				$files[] = array(
					'path'    => $path,
					'content' => (string) $file['content'],
				);
				continue;
			}

			if ( isset( $file['payload_reference'] ) && is_array( $file['payload_reference'] ) ) {
				$files[] = array(
					'path'              => $path,
					'payload_reference' => $file['payload_reference'],
				);
				continue;
			}

			if ( isset( $file['content_base64'] ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes uploaded artifact payload content.
				$content = base64_decode( (string) $file['content_base64'], true );
				if ( false === $content ) {
					return new WP_Error( 'static_site_importer_invalid_file_content', __( 'Uploaded file content could not be decoded.', 'static-site-importer' ), array( 'status' => 400 ) );
				}

				$files[] = array(
					'path'           => $path,
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
					'content_base64' => base64_encode( $content ),
				);
			}
		}
	}

	if ( isset( $source['archive'] ) && is_array( $source['archive'] ) ) {
		$archive_files = static_site_importer_rest_archive_files( $source['archive'] );
		if ( is_wp_error( $archive_files ) ) {
			return $archive_files;
		}

		$files = array_merge( $files, $archive_files );
	}

	if ( empty( $files ) ) {
		return new WP_Error( 'static_site_importer_missing_source', __( 'Add a website URL, upload file(s), or paste HTML to start.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$source_quality = static_site_importer_rest_validate_static_html_sources( $files );
	if ( is_wp_error( $source_quality ) ) {
		return $source_quality;
	}

	$entrypoint = isset( $source['entrypoint'] ) ? static_site_importer_rest_artifact_path( (string) $source['entrypoint'] ) : '';
	if ( '' === $entrypoint || ! in_array( $entrypoint, array_column( $files, 'path' ), true ) ) {
		$entrypoint = static_site_importer_rest_entrypoint( $files );
	}

	$artifact      = array_merge(
		$metadata,
		array(
			'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
			'entrypoint' => $entrypoint,
			'files'      => $files,
		)
	);
	$source_policy = Static_Site_Importer_Content_Policy::validate_artifact( $artifact );
	if ( is_wp_error( $source_policy ) ) {
		return $source_policy;
	}

	return array(
		'artifact'        => $artifact,
		'source_metadata' => array(),
		'provider'        => 'rest-source',
	);
}

/**
 * Backward-compatible REST wrapper around the canonical source normalizer.
 *
 * @param array<string,mixed> $source Source payload.
 * @param array<string,mixed> $input  Import input.
 * @return array{artifact:array<string,mixed>,source_metadata:array<string,mixed>,provider:string}|WP_Error
 */
function static_site_importer_rest_source_runtime( array $source, array $input = array() ) {
	unset( $input ); // Retained for compatibility with callers using the former provider-args parameter.

	return static_site_importer_source_runtime( $source );
}

/**
 * Validate imported HTML files before building an artifact.
 *
 * @param array<int,array<string,mixed>> $files Artifact files.
 * @return true|WP_Error
 */
function static_site_importer_rest_validate_static_html_sources( array $files ) {
	foreach ( $files as $file ) {
		$path = isset( $file['path'] ) ? (string) $file['path'] : '';
		if ( ! preg_match( '/\.html?$/i', $path ) ) {
			continue;
		}

		$content = '';
		if ( isset( $file['content'] ) ) {
			$content = (string) $file['content'];
		} elseif ( isset( $file['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes uploaded HTML artifact content for source-quality validation.
			$decoded = base64_decode( (string) $file['content_base64'], true );
			if ( false === $decoded ) {
				continue;
			}
			$content = $decoded;
		}

		$diagnostic = Static_Site_Importer_URL_Fetcher::html_source_diagnostic( $content );
		if ( ! empty( $diagnostic ) && 'error' === ( $diagnostic['severity'] ?? '' ) ) {
			$diagnostic['source_path'] = $path;

			return new WP_Error(
				'static_site_importer_client_rendered_app_shell',
				__( 'This source appears to be a JavaScript-rendered application shell. Static Site Importer can import server-rendered HTML, but this source needs a browser-rendered capture before it can produce WordPress blocks.', 'static-site-importer' ),
				array(
					'status'     => 422,
					'diagnostic' => $diagnostic,
				)
			);
		}
	}

	return true;
}

/**
 * Determine whether a source contains only a URL and no inline/uploaded artifact.
 *
 * @param array<string,mixed> $source Source payload.
 * @return bool
 */
function static_site_importer_rest_is_url_only_source( array $source ): bool {
	if ( ! isset( $source['url'] ) || '' === trim( (string) $source['url'] ) ) {
		return false;
	}

	if ( isset( $source['html'] ) && '' !== trim( (string) $source['html'] ) ) {
		return false;
	}

	foreach ( array( 'files', 'archive', 'figma_file' ) as $key ) {
		if ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) && ! empty( $source[ $key ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Extract a ZIP archive payload into normalized website artifact files.
 *
 * @param array<string,mixed> $archive Archive payload.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function static_site_importer_rest_archive_files( array $archive ) {
	$name = isset( $archive['name'] ) ? (string) $archive['name'] : ( isset( $archive['path'] ) ? (string) $archive['path'] : '' );
	if ( ! preg_match( '/\.zip$/i', $name ) ) {
		return new WP_Error( 'static_site_importer_invalid_archive_type', __( 'ZIP uploads must use a .zip file.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'static_site_importer_zip_unavailable', __( 'ZIP archive extraction is unavailable on this server.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	$encoded_content = isset( $archive['content_base64'] ) ? (string) $archive['content_base64'] : '';
	$limits          = static_site_importer_rest_archive_limits();
	if ( strlen( $encoded_content ) > $limits['max_encoded_bytes'] ) {
		return static_site_importer_rest_archive_limit_error( 'encoded_bytes_exceeded' );
	}

	// This upper bound is calculated without allocating the decoded archive.
	if ( intdiv( strlen( $encoded_content ) + 3, 4 ) * 3 > $limits['max_decoded_bytes'] ) {
		return static_site_importer_rest_archive_limit_error( 'decoded_bytes_exceeded' );
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes uploaded ZIP archive payload content after its encoded and decoded sizes are bounded.
	$content = base64_decode( $encoded_content, true );
	if ( false === $content ) {
		return new WP_Error( 'static_site_importer_invalid_archive_content', __( 'Uploaded ZIP archive content could not be decoded.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$tmp = tempnam( sys_get_temp_dir(), 'ssi-zip-' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- ZipArchive requires a local temp file path for uploaded archive staging.
	if ( false === $tmp || false === file_put_contents( $tmp, $content ) ) {
		return new WP_Error( 'static_site_importer_archive_tempfile_failed', __( 'Uploaded ZIP archive could not be staged for extraction.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	return static_site_importer_archive_files_from_path( $tmp, $limits, true );
}

/**
 * Extract a server-owned staged ZIP without taking lifecycle ownership.
 *
 * The canonical ability calls this only after an opaque reference resolver has
 * supplied the path. Direct ability and REST inputs never route staged paths here.
 *
 * @param array<string,mixed> $archive Resolved archive payload.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function static_site_importer_staged_archive_files( array $archive, bool $return_payload_references = false ) {
	$path = static_site_importer_staged_archive_path( $archive );
	if ( is_wp_error( $path ) ) {
		return $path;
	}

	return static_site_importer_archive_files_from_path( $path, static_site_importer_staged_archive_limits(), false, $return_payload_references );
}

/** Resolve a server-owned staged archive without transferring ownership to SSI. */
function static_site_importer_staged_archive_path( array $archive ) {
	$name = isset( $archive['name'] ) ? (string) $archive['name'] : '';
	$path = isset( $archive['staged_path'] ) ? (string) $archive['staged_path'] : '';
	if ( ! preg_match( '/\.zip$/i', $name ) ) {
		return new WP_Error( 'static_site_importer_invalid_archive_type', __( 'ZIP uploads must use a .zip file.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$is_absolute = str_starts_with( $path, '/' ) || 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $path );
	$real_path   = $is_absolute && ! is_link( $path ) ? realpath( $path ) : false;
	if ( false === $real_path || ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
		return new WP_Error( 'static_site_importer_staged_archive_invalid', __( 'The staged ZIP archive is unavailable.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$size = filesize( $real_path );
	if ( false === $size || $size > static_site_importer_staged_archive_limits()['max_archive_bytes'] ) {
		return static_site_importer_rest_archive_limit_error( 'staged_bytes_exceeded' );
	}

	return $real_path;
}

/** Return a transient reader for verified entries in a resolver-owned staged ZIP. */
function static_site_importer_staged_archive_payload_reader( array $archive ) {
	$path = static_site_importer_staged_archive_path( $archive );
	if ( is_wp_error( $path ) ) {
		return $path;
	}
	$interface = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\PayloadReader';
	if ( ! interface_exists( $interface ) ) {
		return new WP_Error( 'static_site_importer_missing_transformer_capability', 'Blocks Engine php-transformer does not expose the staged payload reader contract.' );
	}

	return new class( $path ) implements \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader {
		public function __construct( private string $archive_path ) {}

		public function read( array $reference ): string {
			$id = $reference['id'];
			if ( ! str_starts_with( $id, 'zip-entry:' ) ) {
				throw new RuntimeException( 'The staged payload reference is invalid.' );
			}
			$entry = rawurldecode( substr( $id, strlen( 'zip-entry:' ) ) );
			if ( '' === $entry || static_site_importer_rest_artifact_path( $entry ) === '' ) {
				throw new RuntimeException( 'The staged payload reference is invalid.' );
			}
			$path = static_site_importer_staged_archive_path(
				array(
					'name'        => 'payload.zip',
					'staged_path' => $this->archive_path,
				)
			);
			if ( is_wp_error( $path ) ) {
				throw new RuntimeException( 'The staged archive is unavailable.' );
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				throw new RuntimeException( 'The staged archive is unavailable.' );
			}
			try {
				$stat   = $zip->statName( $entry );
				$limits = static_site_importer_staged_archive_limits();
				if ( ! is_array( $stat ) || (int) $stat['size'] !== $reference['bytes'] || (int) $stat['size'] > $limits['max_entry_uncompressed_bytes'] || ( 0 === (int) $stat['comp_size'] ? (int) $stat['size'] > 0 : (int) $stat['size'] / (int) $stat['comp_size'] > $limits['max_compression_ratio'] ) ) {
					throw new RuntimeException( 'The staged payload byte count changed.' );
				}
				$bytes = $zip->getFromName( $entry );
			} finally {
				$zip->close();
			}
			if ( false === $bytes ) {
				throw new RuntimeException( 'The staged payload is unavailable.' );
			}
			return $bytes;
		}
	};
}

/**
 * Validate and extract a ZIP from a local path.
 *
 * @param string            $archive_path Local ZIP path.
 * @param array<string,int> $limits       Bounded archive policy.
 * @param bool              $delete_path  Whether this importer owns the path.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function static_site_importer_archive_files_from_path( string $archive_path, array $limits, bool $delete_path, bool $return_payload_references = false ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'static_site_importer_zip_unavailable', __( 'ZIP archive extraction is unavailable on this server.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	$cleanup = static function ( $zip = null ) use ( $archive_path, $delete_path ): void {
		if ( $zip instanceof ZipArchive ) {
			$zip->close();
		}
		if ( $delete_path && file_exists( $archive_path ) ) {
			wp_delete_file( $archive_path );
		}
	};

	$zip = new ZipArchive();
	if ( true !== $zip->open( $archive_path ) ) {
		$cleanup();
		return new WP_Error( 'static_site_importer_archive_open_failed', __( 'Uploaded ZIP archive could not be opened.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	if ( $zip->numFiles > $limits['max_entries'] ) {
		$cleanup( $zip );
		return static_site_importer_rest_archive_limit_error( 'entry_count_exceeded' );
	}

	$total_uncompressed_bytes = 0;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$stat = $zip->statIndex( $i );
		if ( ! is_array( $stat ) || $stat['size'] < 0 || $stat['comp_size'] < 0 ) {
			$cleanup( $zip );
			return new WP_Error( 'static_site_importer_archive_metadata_invalid', __( 'A ZIP archive entry has invalid metadata.', 'static-site-importer' ), array( 'status' => 400 ) );
		}

		$entry_uncompressed_bytes = (int) $stat['size'];
		$entry_compressed_bytes   = (int) $stat['comp_size'];
		if ( $entry_uncompressed_bytes > $limits['max_entry_uncompressed_bytes'] ) {
			$cleanup( $zip );
			return static_site_importer_rest_archive_limit_error( 'entry_uncompressed_bytes_exceeded' );
		}

		$total_uncompressed_bytes += $entry_uncompressed_bytes;
		if ( $total_uncompressed_bytes > $limits['max_total_uncompressed_bytes'] ) {
			$cleanup( $zip );
			return static_site_importer_rest_archive_limit_error( 'total_uncompressed_bytes_exceeded' );
		}

		if ( 0 === $entry_compressed_bytes ? $entry_uncompressed_bytes > 0 : $entry_uncompressed_bytes / $entry_compressed_bytes > $limits['max_compression_ratio'] ) {
			$cleanup( $zip );
			return static_site_importer_rest_archive_limit_error( 'compression_ratio_exceeded' );
		}
	}

	$files = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$entry = $zip->getNameIndex( $i );
		if ( false === $entry || str_ends_with( $entry, '/' ) || str_starts_with( $entry, '__MACOSX/' ) ) {
			continue;
		}

		$path = static_site_importer_rest_artifact_path( $entry );
		if ( '' === $path ) {
			continue;
		}

		if ( ! static_site_importer_rest_should_include_artifact_file( $path ) ) {
			continue;
		}

		if ( ! Static_Site_Importer_Content_Policy::is_static_path( $path ) ) {
			$cleanup( $zip );
			return new WP_Error(
				'static_site_importer_executable_source_rejected',
				__( 'ZIP archives may contain static content only.', 'static-site-importer' ),
				array(
					'status' => 400,
					'path'   => $path,
				)
			);
		}

		$file_content = $zip->getFromIndex( $i );
		if ( false === $file_content ) {
			$cleanup( $zip );
			return new WP_Error( 'static_site_importer_archive_entry_read_failed', __( 'A ZIP archive entry could not be read.', 'static-site-importer' ), array( 'status' => 400 ) );
		}

		if ( $return_payload_references && ! Static_Site_Importer_Content_Policy::is_textual_path( $path ) ) {
			$files[] = array(
				'path'              => $path,
				'payload_reference' => array(
					'schema' => 'blocks-engine/payload-reference/v1',
					'id'     => 'zip-entry:' . rawurlencode( $entry ),
					'bytes'  => strlen( $file_content ),
					'sha256' => hash( 'sha256', $file_content ),
				),
			);
			continue;
		}

		$files[] = array(
			'path'           => $path,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
			'content_base64' => base64_encode( $file_content ),
		);
	}

	$cleanup( $zip );

	return $files;
}

/**
 * Return bounded ZIP intake limits.
 *
 * @return array<string,int>
 */
function static_site_importer_rest_archive_limits(): array {
	$hard_limits = array(
		'max_encoded_bytes'            => 52428800,
		'max_decoded_bytes'            => 39321600,
		'max_entries'                  => 5000,
		'max_entry_uncompressed_bytes' => 26214400,
		'max_total_uncompressed_bytes' => 104857600,
		'max_compression_ratio'        => 200,
	);
	$defaults    = array(
		'max_encoded_bytes'            => 26214400,
		'max_decoded_bytes'            => 19660800,
		'max_entries'                  => 1000,
		'max_entry_uncompressed_bytes' => 10485760,
		'max_total_uncompressed_bytes' => 52428800,
		'max_compression_ratio'        => 100,
	);
	$limits      = apply_filters( 'static_site_importer_archive_limits', $defaults );
	$limits      = is_array( $limits ) ? $limits : $defaults;

	foreach ( $hard_limits as $key => $maximum ) {
		$candidate      = isset( $limits[ $key ] ) ? (int) $limits[ $key ] : $defaults[ $key ];
		$limits[ $key ] = min( $maximum, max( 1, $candidate ) );
	}

	return $limits;
}

/**
 * Return hard-bounded limits for server-owned staged ZIP archives.
 *
 * @return array<string,int>
 */
function static_site_importer_staged_archive_limits(): array {
	$hard_limits = array(
		'max_archive_bytes'            => 262144000,
		'max_entries'                  => 10000,
		'max_entry_uncompressed_bytes' => 67108864,
		'max_total_uncompressed_bytes' => 268435456,
		'max_compression_ratio'        => 200,
	);
	$defaults    = array(
		'max_archive_bytes'            => 209715200,
		'max_entries'                  => 5000,
		'max_entry_uncompressed_bytes' => 52428800,
		'max_total_uncompressed_bytes' => 262144000,
		'max_compression_ratio'        => 100,
	);
	$limits      = apply_filters( 'static_site_importer_staged_archive_limits', $defaults );
	$limits      = is_array( $limits ) ? $limits : $defaults;

	foreach ( $hard_limits as $key => $maximum ) {
		$candidate      = isset( $limits[ $key ] ) ? (int) $limits[ $key ] : $defaults[ $key ];
		$limits[ $key ] = min( $maximum, max( 1, $candidate ) );
	}

	return $limits;
}

/**
 * Build a stable archive-policy error without exposing archive contents.
 *
 * @param string $reason Policy reason code.
 * @return WP_Error
 */
function static_site_importer_rest_archive_limit_error( string $reason ): WP_Error {
	$code = 'static_site_importer_archive_' . $reason;

	return new WP_Error(
		$code,
		__( 'The ZIP archive exceeds the configured safety limit.', 'static-site-importer' ),
		array(
			'status'     => 400,
			'diagnostic' => array( 'code' => $code ),
		)
	);
}

/**
 * Normalize uploaded file paths into artifact paths.
 *
 * @param string $path File path.
 * @return string
 */
function static_site_importer_rest_artifact_path( string $path ): string {
	$path = static_site_importer_rest_normalize_artifact_path( $path );

	if ( '' === $path ) {
		return '';
	}

	return str_starts_with( $path, 'website/' ) ? $path : 'website/' . $path;
}

/**
 * Normalize a source file path while preserving paths declared by an artifact report manifest.
 *
 * @param string       $path         File path.
 * @param array<mixed> $report_paths Artifact report paths.
 * @return string
 */
function static_site_importer_rest_source_file_path( string $path, array $report_paths ): string {
	$path = static_site_importer_rest_normalize_artifact_path( $path );
	if ( '' === $path ) {
		return '';
	}

	foreach ( $report_paths as $report_path ) {
		if ( is_string( $report_path ) && static_site_importer_rest_normalize_artifact_path( $report_path ) === $path ) {
			return $path;
		}
	}

	return static_site_importer_rest_artifact_path( $path );
}

/**
 * Normalize a relative artifact path without assigning it to the website root.
 *
 * @param string $path File path.
 * @return string
 */
function static_site_importer_rest_normalize_artifact_path( string $path ): string {
	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
	$path = ltrim( (string) $path, '/' );
	$path = preg_replace( '#/+#', '/', $path );

	return (string) $path;
}

/**
 * Determine whether an uploaded artifact file belongs to the static site.
 *
 * @param string $path Normalized artifact path.
 * @return bool
 */
function static_site_importer_rest_should_include_artifact_file( string $path ): bool {
	$path  = str_replace( '\\', '/', $path );
	$path  = preg_replace( '#/+#', '/', $path );
	$path  = preg_replace( '#^website/#', '', ltrim( (string) $path, '/' ) );
	$parts = array_values(
		array_filter(
			explode( '/', (string) $path ),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		)
	);
	$name  = end( $parts );

	if ( false === $name ) {
		return false;
	}

	if ( '.DS_Store' === $name ) {
		return false;
	}

	if ( preg_match( '/\.fig$/i', (string) $name ) ) {
		return false;
	}

	return ! ( 'result.json' === strtolower( (string) $name ) && ! in_array( 'assets', $parts, true ) );
}

/**
 * Pick an entrypoint from artifact files.
 *
 * @param array<int,array<string,mixed>> $files Artifact files.
 * @return string
 */
function static_site_importer_rest_entrypoint( array $files ): string {
	foreach ( array( 'website/index.html', 'website/home.html' ) as $candidate ) {
		foreach ( $files as $file ) {
			if ( isset( $file['path'] ) && $candidate === (string) $file['path'] ) {
				return $candidate;
			}
		}
	}

	foreach ( $files as $file ) {
		$path = isset( $file['path'] ) ? (string) $file['path'] : '';
		if ( preg_match( '/\.html?$/i', $path ) ) {
			return $path;
		}
	}

	return 'website/index.html';
}
