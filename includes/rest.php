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
	$params = $request->get_json_params();

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

	return rest_ensure_response(
		array(
			'success'               => true,
			'result'                => $result,
			'import_report_summary' => isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array(),
		)
	);
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
	if ( isset( $source['url'] ) && '' !== trim( (string) $source['url'] ) ) {
		$input['url'] = esc_url_raw( (string) $source['url'] );
		if ( isset( $params['provider'] ) ) {
			$input['provider'] = sanitize_key( (string) $params['provider'] );
		}
		if ( isset( $params['provider_args'] ) && is_array( $params['provider_args'] ) ) {
			$input['provider_args'] = $params['provider_args'];
		}

		return Static_Site_Importer_URL_Import_Runtime::import_url( $input );
	} else {
		$artifact = static_site_importer_rest_source_artifact( $source );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}

		return Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $input );
	}
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
	$source_url = isset( $source['url'] ) ? esc_url_raw( (string) $source['url'] ) : '';
	$artifact   = array();

	if ( '' === trim( $source_url ) || isset( $source['html'] ) || isset( $source['files'] ) || isset( $source['archive'] ) ) {
		$artifact = static_site_importer_rest_source_artifact( $source );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}
	}

	$request = array(
		'schema'      => 'static-site-importer/preview-request/v1',
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
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new WP_Error( 'static_site_importer_codebox_preview_result_invalid', __( 'WP Codebox preview must return an array result or WP_Error.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	return static_site_importer_rest_normalize_preview_result( $result );
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
	$create_session = array( 'WP_Codebox_Abilities', 'create_browser_playground_session' );
	if ( ! is_callable( $create_session ) ) {
		return static_site_importer_rest_preview_unavailable_result( $request );
	}

	$codebox_input = static_site_importer_rest_codebox_preview_input( $request, $params );
	if ( is_wp_error( $codebox_input ) ) {
		return $codebox_input;
	}

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
		'goal'               => __( 'Create a disposable WordPress preview for a Static Site Importer request.', 'static-site-importer' ),
		'target'             => array(
			'kind' => 'static-site-importer-preview',
			'ref'  => 'static-site-importer/importer',
		),
		'expected_artifacts' => array( 'preview', 'static-site-importer-report' ),
		'context'            => array(
			'product'        => 'static-site-importer',
			'request_schema' => (string) ( $request['schema'] ?? 'static-site-importer/preview-request/v1' ),
			'source_url'     => isset( $source['url'] ) ? (string) $source['url'] : '',
		),
		'artifact_files'     => static_site_importer_rest_codebox_artifact_files( $artifact ),
		'browser_runner'     => array(
			'task_path'   => '/tmp/static-site-importer-preview-request.json',
			'result_path' => '/tmp/static-site-importer-preview-result.json',
			'invocation'  => array(
				'type'  => 'ability',
				'name'  => ! empty( $artifact ) ? 'static-site-importer/import-website-artifact' : 'static-site-importer/import-url',
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
		'orchestrator'       => array(
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
	$preview_url   = static_site_importer_rest_codebox_preview_url( $playground, $artifacts );
	$blueprint_url = static_site_importer_rest_codebox_blueprint_url( $session );

	if ( '' === $preview_url && '' === $blueprint_url ) {
		return array(
			'success'  => false,
			'preview'  => array(
				'status'  => 'unavailable',
				'message' => __( 'Preview unavailable: WP Codebox did not return a preview URL or Playground blueprint URL.', 'static-site-importer' ),
			),
			'provider' => 'wp-codebox/create-browser-playground-session',
			'codebox'  => array(
				'session' => static_site_importer_rest_codebox_session_summary( $session ),
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
			'session' => static_site_importer_rest_codebox_session_summary( $session ),
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
	foreach ( array( $playground['preview_public_url'] ?? '', $playground['site_url'] ?? '', $playground['preview_url'] ?? '', $artifacts['preview_url'] ?? '' ) as $candidate ) {
		$candidate = esc_url_raw( (string) $candidate );
		if ( preg_match( '#^https?://#i', $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Build a Playground blueprint URL from a WP Codebox executable blueprint ref.
 *
 * @param array<string,mixed> $session WP Codebox browser session.
 * @return string
 */
function static_site_importer_rest_codebox_blueprint_url( array $session ): string {
	$executable_blueprint_ref = array( 'WP_Codebox_Browser_Task_Builder', 'executable_blueprint_ref' );
	if ( ! is_callable( $executable_blueprint_ref ) ) {
		return '';
	}

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

	return array_filter(
		array(
			'schema'          => isset( $session['schema'] ) ? (string) $session['schema'] : '',
			'execution'       => isset( $session['execution'] ) ? (string) $session['execution'] : '',
			'execution_scope' => isset( $session['execution_scope'] ) ? (string) $session['execution_scope'] : '',
			'session_id'      => isset( $session_envelope['id'] ) ? (string) $session_envelope['id'] : '',
			'status'          => isset( $session_envelope['status'] ) ? (string) $session_envelope['status'] : '',
		)
	);
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

			if ( isset( $file['content'] ) ) {
				$files[] = array(
					'path'    => $path,
					'content' => (string) $file['content'],
				);
				continue;
			}

			if ( isset( $file['content_base64'] ) ) {
				$content = base64_decode( (string) $file['content_base64'], true );
				if ( false === $content ) {
					return new WP_Error( 'static_site_importer_invalid_file_content', __( 'Uploaded file content could not be decoded.', 'static-site-importer' ), array( 'status' => 400 ) );
				}

				$files[] = array(
					'path'           => $path,
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
		return new WP_Error( 'static_site_importer_missing_source', __( 'Add a website URL, site files, or raw HTML to start.', 'static-site-importer' ), array( 'status' => 400 ) );
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

	$content = isset( $archive['content_base64'] ) ? base64_decode( (string) $archive['content_base64'], true ) : false;
	if ( false === $content ) {
		return new WP_Error( 'static_site_importer_invalid_archive_content', __( 'Uploaded ZIP archive content could not be decoded.', 'static-site-importer' ), array( 'status' => 400 ) );
	}

	$tmp = tempnam( sys_get_temp_dir(), 'ssi-zip-' );
	if ( false === $tmp || false === file_put_contents( $tmp, $content ) ) {
		return new WP_Error( 'static_site_importer_archive_tempfile_failed', __( 'Uploaded ZIP archive could not be staged for extraction.', 'static-site-importer' ), array( 'status' => 500 ) );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp ) ) {
		@unlink( $tmp );
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

		$file_content = $zip->getFromIndex( $i );
		if ( false === $file_content ) {
			$zip->close();
			@unlink( $tmp );
			return new WP_Error( 'static_site_importer_archive_entry_read_failed', __( 'A ZIP archive entry could not be read.', 'static-site-importer' ), array( 'status' => 400 ) );
		}

		$files[] = array(
			'path'           => $path,
			'content_base64' => base64_encode( $file_content ),
		);
	}

	$zip->close();
	@unlink( $tmp );

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
