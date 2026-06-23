<?php
/**
 * Importer REST routes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	$params = array_merge(
		$input,
		array(
			'activate'  => array_key_exists( 'activate', $input ) ? ! empty( $input['activate'] ) : true,
			'overwrite' => array_key_exists( 'overwrite', $input ) ? ! empty( $input['overwrite'] ) : true,
		)
	);
	$result = static_site_importer_rest_create_preview( array( 'artifact' => $artifact ), Static_Site_Importer_Figma_Import::import_input( $params, $artifact ), $params );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$response = rest_ensure_response( Static_Site_Importer_Figma_Import::runner_response( $result ) );
	static_site_importer_rest_add_figma_cors_headers( $response, $request );

	return $response;
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
	if ( function_exists( 'current_user_can' ) && current_user_can( 'switch_themes' ) ) {
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
	/** @var mixed $params */
	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = $request->get_params();
	}

	$source = isset( $params['source'] ) && is_array( $params['source'] ) ? $params['source'] : array();
	$input  = static_site_importer_rest_import_args( $params );

	if ( ! static_site_importer_rest_should_apply_to_current_site( $params ) ) {
		$result = static_site_importer_rest_create_preview( $source, $input, $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	$result = static_site_importer_rest_apply_to_current_site( $source, $input, $params );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Determine whether the request explicitly targets the current WordPress site.
 *
 * @param array<string,mixed> $params Request params.
 * @return bool
 */
function static_site_importer_rest_should_apply_to_current_site( array $params ): bool {
	return ! empty( $params['apply_to_current_site'] );
}

/**
 * Apply an import to the installed WordPress site.
 *
 * @param array<string,mixed> $source Source payload.
 * @param array<string,mixed> $input  Import args.
 * @param array<string,mixed> $params Request params.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_apply_to_current_site( array $source, array $input, array $params ) {
	$decorate_current_site_preview = static function ( $result ) {
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

	if ( isset( $source['url'] ) && '' !== trim( (string) $source['url'] ) ) {
		$input['url'] = esc_url_raw( (string) $source['url'] );
		if ( isset( $params['provider'] ) ) {
			$input['provider'] = sanitize_key( (string) $params['provider'] );
		}
		if ( isset( $params['provider_args'] ) && is_array( $params['provider_args'] ) ) {
			$input['provider_args'] = $params['provider_args'];
		}

		return $decorate_current_site_preview( static_site_importer_rest_execute_import_ability( 'static-site-importer/import-url', $input, 'static_site_importer_ability_import_url' ) );
	} else {
		$artifact = static_site_importer_rest_source_artifact( $source );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}

		$input['artifact'] = $artifact;

		return $decorate_current_site_preview( static_site_importer_rest_execute_import_ability( 'static-site-importer/import-website-artifact', $input, 'static_site_importer_ability_import_website_artifact' ) );
	}
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
 * Create a standalone preview request without mutating the current site.
 *
 * @param array<string,mixed> $source Source payload.
 * @param array<string,mixed> $input  Import args.
 * @param array<string,mixed> $params Request params.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_create_preview( array $source, array $input, array $params ) {
	$request_id = static_site_importer_rest_preview_request_id( $params );
	$source_url = isset( $source['url'] ) ? esc_url_raw( (string) $source['url'] ) : '';
	$artifact   = array();
	$attempt    = static_site_importer_rest_preview_attempt_base( $request_id, $source, $artifact, $params );

	if ( '' === trim( $source_url ) || isset( $source['html'] ) || isset( $source['files'] ) || isset( $source['archive'] ) ) {
		$artifact = static_site_importer_rest_source_artifact( $source );
		if ( is_wp_error( $artifact ) ) {
			static_site_importer_rest_persist_preview_attempt( static_site_importer_rest_preview_attempt_with_error( $attempt, $artifact ) );
			return $artifact;
		}

		$attempt = static_site_importer_rest_preview_attempt_base( $request_id, $source, $artifact, $params );
	}

	$request = array(
		'schema'      => 'static-site-importer/preview-request/v1',
		'request_id'  => $request_id,
		'source'      => array_filter(
			array(
				'url'      => $source_url,
				'artifact' => $artifact,
			)
		),
		'import_args' => $input,
		'provider'    => isset( $params['provider'] ) ? sanitize_key( (string) $params['provider'] ) : '',
	);

	$result = static_site_importer_rest_codebox_preview_result( $request, $params );
	if ( is_wp_error( $result ) ) {
		static_site_importer_rest_persist_preview_attempt( static_site_importer_rest_preview_attempt_with_error( $attempt, $result ) );
		return $result;
	}

	$normalized = static_site_importer_rest_normalize_preview_result( $result );
	$attempt    = static_site_importer_rest_preview_attempt_with_result( $attempt, $normalized );
	static_site_importer_rest_persist_preview_attempt( $attempt );
	$normalized['preview_attempt'] = static_site_importer_rest_preview_attempt_public_summary( $attempt );

	return $normalized;
}

/**
 * Build a precise preview-unavailable response.
 *
 * @param array<string,mixed> $request Preview request.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_unavailable_result( array $request ): array {
	return array(
		'success'  => false,
		'preview'  => array(
			'status'  => 'unavailable',
			'message' => __( 'Preview unavailable: WP Codebox is unavailable, not installed, or does not provide the required browser Playground session API.', 'static-site-importer' ),
		),
		'provider' => 'wp-codebox/create-browser-playground-session',
		'request'  => $request,
	);
}

/**
 * Normalize preview output to the REST preview contract.
 *
 * @param array<string,mixed> $result Preview result.
 * @return array<string,mixed>
 */
function static_site_importer_rest_normalize_preview_result( array $result ): array {
	$preview    = isset( $result['preview'] ) && is_array( $result['preview'] ) ? $result['preview'] : $result;
	$url        = isset( $preview['url'] ) ? esc_url_raw( (string) $preview['url'] ) : '';
	$playground = isset( $preview['playground'] ) && is_array( $preview['playground'] ) ? $preview['playground'] : array();
	if ( isset( $playground['blueprint_url'] ) ) {
		$playground['blueprint_url'] = esc_url_raw( (string) $playground['blueprint_url'] );
	}

	$preview = array_filter(
		array_merge(
			$preview,
			array(
				'status'     => isset( $preview['status'] ) ? sanitize_key( (string) $preview['status'] ) : 'ready',
				'url'        => $url,
				'playground' => $playground,
			)
		)
	);

	return array_merge(
		$result,
		array(
			'success' => array_key_exists( 'success', $result ) ? (bool) $result['success'] : ( ! empty( $url ) || ! empty( $playground['blueprint_url'] ) ),
			'preview' => $preview,
		)
	);
}

/**
 * Create a preview through WP Codebox's disposable browser Playground session API.
 *
 * @param array<string,mixed> $request Preview request.
 * @param array<string,mixed> $params  Raw REST params.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_codebox_preview_result( array $request, array $params ) {
	$create_session = class_exists( 'WP_Codebox_Abilities' ) ? array( 'WP_Codebox_Abilities', 'create_browser_playground_session' ) : null;
	if ( ! is_callable( $create_session ) ) {
		return static_site_importer_rest_preview_unavailable_result( $request );
	}

	$codebox_input = static_site_importer_rest_codebox_preview_input( $request, $params );
	if ( is_wp_error( $codebox_input ) ) {
		return $codebox_input;
	}

	/** @var mixed $session */
	$session = call_user_func( $create_session, $codebox_input );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	if ( ! is_array( $session ) ) {
		return new WP_Error( 'static_site_importer_codebox_preview_result_invalid', __( 'WP Codebox preview returned an invalid session response.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	return static_site_importer_rest_codebox_preview_from_session( $session, $request );
}

/**
 * Build WP Codebox browser Playground input from an SSI preview request.
 *
 * @param array<string,mixed> $request Preview request.
 * @param array<string,mixed> $params  Raw REST params.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_codebox_preview_input( array $request, array $params ) {
	$source      = isset( $request['source'] ) && is_array( $request['source'] ) ? $request['source'] : array();
	$artifact    = isset( $source['artifact'] ) && is_array( $source['artifact'] ) ? $source['artifact'] : array();
	$import_args = isset( $request['import_args'] ) && is_array( $request['import_args'] ) ? $request['import_args'] : array();

	$input = array(
		'goal'                        => __( 'Create a disposable WordPress preview for a Static Site Importer request.', 'static-site-importer' ),
		'include_raw_browser_session' => true,
		'target'                      => array(
			'kind' => 'static-site-importer-preview',
			'ref'  => 'static-site-importer/importer',
		),
		'expected_artifacts'          => array( 'preview', 'static-site-importer-report' ),
		'context'                     => array(
			'product'        => 'static-site-importer',
			'request_schema' => (string) ( $request['schema'] ?? 'static-site-importer/preview-request/v1' ),
			'request_id'     => (string) ( $request['request_id'] ?? '' ),
			'source_url'     => isset( $source['url'] ) ? (string) $source['url'] : '',
		),
		'artifact_files'              => static_site_importer_rest_codebox_artifact_files( $artifact ),
		'runtime'                     => array(
			'plugins'          => array(
				array_filter(
					array(
						'slug'     => 'static-site-importer',
						'path'     => defined( 'STATIC_SITE_IMPORTER_PATH' ) ? STATIC_SITE_IMPORTER_PATH : '',
						'activate' => true,
					)
				),
			),
			'prepared_runtime' => array(
				'enabled'   => true,
				'cache_key' => 'static-site-importer-preview',
			),
		),
		'browser_runner'              => array(
			'task_path'     => '/tmp/static-site-importer-preview-request.json',
			'result_path'   => '/tmp/static-site-importer-preview-result.json',
			'invocation'    => array(
				'type'  => 'function',
				'name'  => ! empty( $artifact ) ? 'static_site_importer_ability_import_website_artifact' : 'static_site_importer_ability_import_url',
				'input' => array_filter(
					array(
						'artifact' => $artifact,
						'url'      => isset( $source['url'] ) ? (string) $source['url'] : '',
					) + $import_args
				),
			),
			'capture_paths' => array(
				array(
					'path'      => '/tmp/static-site-importer-preview-result.json',
					'name'      => 'static-site-importer-preview-result',
					'kind'      => 'json',
					'mime_type' => 'application/json',
				),
			),
		),
		'orchestrator'                => array(
			'type' => 'static-site-importer-preview',
			'id'   => 'static-site-importer/importer',
		),
	);

	/**
	 * Filters the WP Codebox browser Playground input for SSI previews.
	 *
	 * Deployments may add generic WP Codebox runtime packages, browser plugins, or
	 * Playground options here. SSI intentionally does not hardcode environment paths.
	 *
	 * @param array<string,mixed> $input   WP Codebox browser session input.
	 * @param array<string,mixed> $request SSI preview request.
	 * @param array<string,mixed> $params  Raw REST params.
	 */
	/** @var mixed $filtered_input */
	$filtered_input = apply_filters( 'static_site_importer_codebox_preview_input', $input, $request, $params );
	if ( ! is_array( $filtered_input ) ) {
		return new WP_Error( 'static_site_importer_codebox_preview_input_invalid', __( 'WP Codebox preview input filters must return an array.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	return $filtered_input;
}

/**
 * Convert SSI website artifact files to WP Codebox browser artifact files.
 *
 * @param array<string,mixed> $artifact Website artifact.
 * @return array<int,array<string,mixed>>
 */
function static_site_importer_rest_codebox_artifact_files( array $artifact ): array {
	$artifact_files = array();
	$files          = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();

	foreach ( $files as $file ) {
		if ( ! is_array( $file ) ) {
			continue;
		}

		$path = isset( $file['path'] ) ? (string) $file['path'] : '';
		$path = preg_replace( '#^website/#', '', $path );
		$path = static_site_importer_rest_codebox_artifact_path( (string) $path );
		if ( '' === $path ) {
			continue;
		}

		$artifact_file = array(
			'path' => $path,
			'kind' => preg_match( '/\.html?$/i', $path ) ? 'html' : 'asset',
		);

		if ( isset( $file['content_base64'] ) ) {
			$artifact_file['encoding']       = 'base64';
			$artifact_file['content_base64'] = (string) $file['content_base64'];
		} else {
			$artifact_file['encoding'] = 'utf-8';
			$artifact_file['content']  = isset( $file['content'] ) ? (string) $file['content'] : '';
		}

		$artifact_files[] = $artifact_file;
	}

	return $artifact_files;
}

/**
 * Normalize a path for WP Codebox browser artifact files.
 *
 * @param string $path Artifact path.
 * @return string
 */
function static_site_importer_rest_codebox_artifact_path( string $path ): string {
	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '#(^|/)\.(?=/|$)#', '', $path );
	$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
	$path = preg_replace( '#[^A-Za-z0-9_./-]#', '-', $path );
	$path = preg_replace( '#/+#', '/', (string) $path );
	$path = trim( (string) $path, '/' );

	return '' !== $path ? $path : '';
}

/**
 * Normalize a WP Codebox browser session into SSI's preview response shape.
 *
 * @param array<string,mixed> $session WP Codebox browser session.
 * @param array<string,mixed> $request SSI preview request.
 * @return array<string,mixed>
 */
function static_site_importer_rest_codebox_preview_from_session( array $session, array $request ): array {
	$playground    = isset( $session['playground'] ) && is_array( $session['playground'] ) ? $session['playground'] : array();
	$artifacts     = isset( $session['artifacts'] ) && is_array( $session['artifacts'] ) ? $session['artifacts'] : array();
	$blueprint_url = static_site_importer_rest_codebox_blueprint_url( $session );
	$extraction    = static_site_importer_rest_codebox_preview_url_extraction( $playground, $artifacts, $blueprint_url );
	$preview_url   = (string) ( $extraction['selected_url'] ?? '' );

	if ( '' === $preview_url && '' === $blueprint_url ) {
		return array(
			'success'  => false,
			'preview'  => array(
				'status'  => 'unavailable',
				'message' => __( 'Preview unavailable: WP Codebox did not return a preview URL or Playground blueprint URL.', 'static-site-importer' ),
			),
			'provider' => 'wp-codebox/create-browser-playground-session',
			'codebox'  => array(
				'session'                => static_site_importer_rest_codebox_session_summary( $session ),
				'preview_url_extraction' => $extraction,
			),
			'request'  => $request,
		);
	}

	return array(
		'success'  => true,
		'preview'  => array_filter(
			array(
				'status'     => 'ready',
				'url'        => $preview_url,
				'playground' => array_filter(
					array(
						'blueprint_url' => $blueprint_url,
						'preview_url'   => isset( $playground['preview_url'] ) ? (string) $playground['preview_url'] : '',
						'scope'         => isset( $playground['scope'] ) ? (string) $playground['scope'] : '',
					)
				),
			)
		),
		'provider' => 'wp-codebox/create-browser-playground-session',
		'codebox'  => array(
			'session'                => static_site_importer_rest_codebox_session_summary( $session ),
			'preview_url_extraction' => $extraction,
		),
	);
}

/**
 * Extract the best reviewer-facing preview URL from a WP Codebox session.
 *
 * @param array<string,mixed> $playground Playground contract.
 * @param array<string,mixed> $artifacts  Artifact contract.
 * @return string
 */
function static_site_importer_rest_codebox_preview_url( array $playground, array $artifacts ): string {
	$extraction = static_site_importer_rest_codebox_preview_url_extraction( $playground, $artifacts, '' );

	return (string) ( $extraction['selected_url'] ?? '' );
}

/**
 * Describe preview URL extraction without exposing raw session internals.
 *
 * @param array<string,mixed> $playground    Playground contract.
 * @param array<string,mixed> $artifacts     Artifact contract.
 * @param string              $blueprint_url Playground blueprint URL.
 * @return array<string,mixed>
 */
function static_site_importer_rest_codebox_preview_url_extraction( array $playground, array $artifacts, string $blueprint_url ): array {
	$candidates = array(
		'playground.preview_public_url' => $playground['preview_public_url'] ?? null,
		'playground.site_url'           => $playground['site_url'] ?? null,
		'playground.preview_url'        => $playground['preview_url'] ?? null,
		'artifacts.preview_url'         => $artifacts['preview_url'] ?? null,
	);
	$summary    = array();

	foreach ( $candidates as $key => $value ) {
		$url       = is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
		$absolute  = preg_match( '#^https?://#i', $url );
		$summary[] = array(
			'key'      => $key,
			'present'  => '' !== $url,
			'absolute' => (bool) $absolute,
		);
		if ( $absolute ) {
			return array(
				'status'       => 'absolute_preview_url_found',
				'selected_key' => $key,
				'selected_url' => $url,
				'candidates'   => $summary,
			);
		}
	}

	return array(
		'status'        => '' !== $blueprint_url ? 'blueprint_url_found' : 'missing_absolute_preview_url',
		'selected_key'  => '' !== $blueprint_url ? 'blueprint_url' : '',
		'selected_url'  => '',
		'blueprint_url' => $blueprint_url,
		'candidates'    => $summary,
	);
}

/**
 * Build a Playground blueprint URL from a WP Codebox executable blueprint ref.
 *
 * @param array<string,mixed> $session WP Codebox browser session.
 * @return string
 */
function static_site_importer_rest_codebox_blueprint_url( array $session ): string {
	$executable_blueprint_ref = class_exists( 'WP_Codebox_Browser_Task_Builder' ) ? array( 'WP_Codebox_Browser_Task_Builder', 'executable_blueprint_ref' ) : null;
	if ( ! is_callable( $executable_blueprint_ref ) ) {
		return '';
	}

	/** @var mixed $blueprint_ref */
	$blueprint_ref = call_user_func( $executable_blueprint_ref, $session );
	if ( ! is_array( $blueprint_ref ) ) {
		return '';
	}

	$endpoint = isset( $blueprint_ref['hydration_endpoint'] ) ? (string) $blueprint_ref['hydration_endpoint'] : ( isset( $blueprint_ref['endpoint'] ) ? (string) $blueprint_ref['endpoint'] : '' );
	if ( '' === $endpoint ) {
		return '';
	}

	$blueprint_endpoint = str_starts_with( $endpoint, 'http://' ) || str_starts_with( $endpoint, 'https://' ) ? $endpoint : rest_url( ltrim( $endpoint, '/' ) );

	return esc_url_raw( 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $blueprint_endpoint ) );
}

/**
 * Create a compact Codebox session summary for diagnostics.
 *
 * @param array<string,mixed> $session WP Codebox browser session.
 * @return array<string,mixed>
 */
function static_site_importer_rest_codebox_session_summary( array $session ): array {
	$session_envelope = isset( $session['session'] ) && is_array( $session['session'] ) ? $session['session'] : array();
	$playground       = isset( $session['playground'] ) && is_array( $session['playground'] ) ? $session['playground'] : array();
	$artifacts        = isset( $session['artifacts'] ) && is_array( $session['artifacts'] ) ? $session['artifacts'] : array();

	return array_filter(
		array(
			'schema'          => isset( $session['schema'] ) ? (string) $session['schema'] : '',
			'execution'       => isset( $session['execution'] ) ? (string) $session['execution'] : '',
			'execution_scope' => isset( $session['execution_scope'] ) ? (string) $session['execution_scope'] : '',
			'session_id'      => (string) ( $session_envelope['id'] ?? $session['session_id'] ?? '' ),
			'status'          => (string) ( $session_envelope['status'] ?? $session['status'] ?? '' ),
			'playground_keys' => array_keys( $playground ),
			'artifact_keys'   => array_keys( $artifacts ),
		)
	);
}

/**
 * Build a preview request id.
 *
 * @param array<string,mixed> $params Request params.
 * @return string
 */
function static_site_importer_rest_preview_request_id( array $params ): string {
	foreach ( array( 'request_id', 'correlation_id', 'preview_attempt_id' ) as $key ) {
		if ( isset( $params[ $key ] ) && '' !== trim( (string) $params[ $key ] ) ) {
			return sanitize_key( (string) $params[ $key ] );
		}
	}

	return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : 'ssi-preview-' . substr( hash( 'sha256', microtime( true ) . wp_rand() ), 0, 16 );
}

/**
 * Build preview-attempt evidence before the provider response.
 *
 * @param string              $request_id Request id.
 * @param array<string,mixed> $source     Source payload.
 * @param array<string,mixed> $artifact   Website artifact.
 * @param array<string,mixed> $params     Request params.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_attempt_base( string $request_id, array $source, array $artifact, array $params ): array {
	return array(
		'schema'         => 'static-site-importer/preview-attempt/v1',
		'request_id'     => $request_id,
		'correlation_id' => isset( $params['correlation_id'] ) ? sanitize_key( (string) $params['correlation_id'] ) : $request_id,
		'timestamp'      => gmdate( 'c' ),
		'source'         => static_site_importer_rest_preview_source_summary( $source, $artifact ),
		'artifact'       => static_site_importer_rest_preview_artifact_summary( $artifact ),
		'provider'       => 'wp-codebox/create-browser-playground-session',
	);
}

/**
 * Summarize the request source without file contents.
 *
 * @param array<string,mixed> $source   Source payload.
 * @param array<string,mixed> $artifact Website artifact.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_source_summary( array $source, array $artifact ): array {
	$types = array();
	if ( isset( $source['url'] ) && '' !== trim( (string) $source['url'] ) ) {
		$types[] = 'url';
	}
	if ( isset( $source['html'] ) && '' !== trim( (string) $source['html'] ) ) {
		$types[] = 'html';
	}
	if ( isset( $source['files'] ) && is_array( $source['files'] ) && ! empty( $source['files'] ) ) {
		$types[] = 'files';
	}
	if ( isset( $source['archive'] ) && is_array( $source['archive'] ) && ! empty( $source['archive'] ) ) {
		$types[] = 'archive';
	}

	return array_merge(
		array( 'type' => 1 === count( $types ) ? $types[0] : ( empty( $types ) ? 'unknown' : 'mixed' ) ),
		static_site_importer_rest_preview_file_summary( $artifact )
	);
}

/**
 * Summarize artifact files without content.
 *
 * @param array<string,mixed> $artifact Website artifact.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_file_summary( array $artifact ): array {
	$files       = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
	$paths       = array();
	$total_bytes = 0;

	foreach ( $files as $file ) {
		if ( ! is_array( $file ) ) {
			continue;
		}
		$path  = isset( $file['path'] ) ? (string) $file['path'] : '';
		$bytes = 0;
		if ( isset( $file['content'] ) ) {
			$bytes = strlen( (string) $file['content'] );
		} elseif ( isset( $file['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes artifact payload content for size accounting.
			$decoded = base64_decode( (string) $file['content_base64'], true );
			$bytes   = false === $decoded ? 0 : strlen( $decoded );
		}
		$total_bytes += $bytes;
		if ( count( $paths ) < 50 ) {
			$paths[] = array_filter(
				array(
					'path'  => $path,
					'bytes' => $bytes,
					'kind'  => preg_match( '/\.html?$/i', $path ) ? 'html' : 'asset',
				)
			);
		}
	}

	return array(
		'file_count'  => count( $files ),
		'total_bytes' => $total_bytes,
		'paths'       => $paths,
	);
}

/**
 * Summarize the website artifact without content.
 *
 * @param array<string,mixed> $artifact Website artifact.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_artifact_summary( array $artifact ): array {
	$files = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();

	return array_filter(
		array(
			'schema'     => isset( $artifact['schema'] ) ? (string) $artifact['schema'] : '',
			'entrypoint' => isset( $artifact['entrypoint'] ) ? (string) $artifact['entrypoint'] : '',
			'file_count' => count( $files ),
		)
	);
}

/**
 * Add WP_Error details to preview-attempt evidence.
 *
 * @param array<string,mixed> $attempt Preview attempt.
 * @param WP_Error            $error   Error.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_attempt_with_error( array $attempt, WP_Error $error ): array {
	$attempt['final'] = array(
		'success' => false,
		'status'  => 'error',
		'error'   => array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		),
	);

	return $attempt;
}

/**
 * Add provider result details to preview-attempt evidence.
 *
 * @param array<string,mixed> $attempt Preview attempt.
 * @param array<string,mixed> $result  Normalized preview result.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_attempt_with_result( array $attempt, array $result ): array {
	$preview = isset( $result['preview'] ) && is_array( $result['preview'] ) ? $result['preview'] : array();
	$codebox = isset( $result['codebox'] ) && is_array( $result['codebox'] ) ? $result['codebox'] : array();
	if ( ! empty( $codebox ) ) {
		$attempt['codebox'] = $codebox;
	}
	$attempt['preview_url_extraction'] = isset( $codebox['preview_url_extraction'] ) && is_array( $codebox['preview_url_extraction'] ) ? $codebox['preview_url_extraction'] : array();
	$attempt['final']                  = array(
		'success' => (bool) ( $result['success'] ?? false ),
		'status'  => (string) ( $preview['status'] ?? ( ! empty( $result['success'] ) ? 'ready' : 'unavailable' ) ),
		'error'   => empty( $result['success'] ) ? array( 'message' => (string) ( $preview['message'] ?? '' ) ) : array(),
	);

	return $attempt;
}

/**
 * Return a public preview-attempt summary for the REST response.
 *
 * @param array<string,mixed> $attempt Preview attempt.
 * @return array<string,mixed>
 */
function static_site_importer_rest_preview_attempt_public_summary( array $attempt ): array {
	return array_filter(
		array(
			'schema'         => 'static-site-importer/preview-attempt-ref/v1',
			'request_id'     => (string) ( $attempt['request_id'] ?? '' ),
			'correlation_id' => (string) ( $attempt['correlation_id'] ?? '' ),
			'status'         => (string) ( $attempt['final']['status'] ?? '' ),
			'file_count'     => isset( $attempt['source']['file_count'] ) ? (int) $attempt['source']['file_count'] : null,
		)
	);
}

/**
 * Persist sanitized preview attempts in a non-autoloaded option ring buffer.
 *
 * @param array<string,mixed> $attempt Preview attempt.
 * @return void
 */
function static_site_importer_rest_persist_preview_attempt( array $attempt ): void {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}

	$option   = 'static_site_importer_preview_attempts';
	$attempts = get_option( $option, array() );
	if ( ! is_array( $attempts ) ) {
		$attempts = array();
	}
	$attempts[] = $attempt;
	$limit      = max( 1, (int) apply_filters( 'static_site_importer_preview_attempt_limit', 25 ) );
	$attempts   = array_slice( $attempts, -1 * $limit );

	update_option( $option, $attempts, false );
}

/**
 * Build import args from REST input.
 *
 * @param array<string,mixed> $params Request params.
 * @return array<string,mixed>
 */
function static_site_importer_rest_import_args( array $params ): array {
	return array(
		'slug'                      => isset( $params['slug'] ) ? sanitize_title( (string) $params['slug'] ) : '',
		'name'                      => isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '',
		'activate'                  => ! empty( $params['activate'] ),
		'overwrite'                 => ! empty( $params['overwrite'] ),
		'fail_on_quality'           => ! empty( $params['fail_on_quality'] ),
		'allow_missing_woocommerce' => ! empty( $params['allow_missing_woocommerce'] ),
		'source_metadata'           => array(
			'source' => 'static_site_importer_block',
		),
	);
}

/**
 * Convert raw HTML or uploaded file JSON into a website artifact.
 *
 * @param array<string,mixed> $source Source payload.
 * @return array<string,mixed>|WP_Error
 */
function static_site_importer_rest_source_artifact( array $source ) {
	if ( isset( $source['artifact'] ) && is_array( $source['artifact'] ) ) {
		return $source['artifact'];
	}

	$files = array();

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

			$path = isset( $file['path'] ) ? static_site_importer_rest_artifact_path( (string) $file['path'] ) : '';
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

	return array(
		'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
		'entrypoint' => static_site_importer_rest_entrypoint( $files ),
		'files'      => $files,
	);
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

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes uploaded ZIP archive payload content.
	$content = isset( $archive['content_base64'] ) ? base64_decode( (string) $archive['content_base64'], true ) : false;
	if ( false === $content ) {
		return new WP_Error( 'static_site_importer_invalid_archive_content', __( 'Uploaded ZIP archive content could not be decoded.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$tmp = tempnam( sys_get_temp_dir(), 'ssi-zip-' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- ZipArchive requires a local temp file path for uploaded archive staging.
	if ( false === $tmp || false === file_put_contents( $tmp, $content ) ) {
		return new WP_Error( 'static_site_importer_archive_tempfile_failed', __( 'Uploaded ZIP archive could not be staged for extraction.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp ) ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return new WP_Error( 'static_site_importer_archive_open_failed', __( 'Uploaded ZIP archive could not be opened.', 'static-site-importer' ), array( 'status' => 400 ) );
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

		$file_content = $zip->getFromIndex( $i );
		if ( false === $file_content ) {
			$zip->close();
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new WP_Error( 'static_site_importer_archive_entry_read_failed', __( 'A ZIP archive entry could not be read.', 'static-site-importer' ), array( 'status' => 400 ) );
		}

		$files[] = array(
			'path'           => $path,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
			'content_base64' => base64_encode( $file_content ),
		);
	}

	$zip->close();
	if ( file_exists( $tmp ) ) {
		wp_delete_file( $tmp );
	}

	return $files;
}

/**
 * Normalize uploaded file paths into artifact paths.
 *
 * @param string $path File path.
 * @return string
 */
function static_site_importer_rest_artifact_path( string $path ): string {
	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
	$path = ltrim( (string) $path, '/' );
	$path = preg_replace( '#/+#', '/', $path );

	if ( '' === $path ) {
		return '';
	}

	return str_starts_with( $path, 'website/' ) ? $path : 'website/' . $path;
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
