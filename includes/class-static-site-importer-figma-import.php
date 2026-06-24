<?php
/**
 * Figma import adapter.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Figma import requests into website artifacts and imports them.
 */
class Static_Site_Importer_Figma_Import {
	/**
	 * Import a Figma request through the existing website artifact import ability.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	public static function import( array $input ): array {
		$artifact = self::website_artifact_from_input( $input );
		if ( is_wp_error( $artifact ) ) {
			/** @var WP_Error $artifact */
			return static_site_importer_ability_error( (string) $artifact->get_error_code(), $artifact->get_error_message(), $artifact->get_error_data() );
		}

		$import_input         = self::import_input( $input, $artifact );
		$validation_artifacts = self::validation_artifacts( $input, $artifact, $import_input );
		if ( ! empty( $validation_artifacts ) ) {
			$import_input['validation_artifacts'] = $validation_artifacts;
		}
		return static_site_importer_ability_import_website_artifact( $import_input );
	}

	/**
	 * Diagnose a Figma runner request through the production transform boundary.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function diagnostics_report( array $input ) {
		$artifact = self::website_artifact_from_input( $input );
		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}

		$import_input = self::import_input( $input, $artifact );
		$scenegraph   = self::scenegraph_from_input( $input );

		$transform_diagnostics = array();
		if ( ! empty( $scenegraph ) && function_exists( 'blocks_engine_figma_transformer_transform_scenegraph' ) ) {
			$transform = blocks_engine_figma_transformer_transform_scenegraph( $scenegraph, self::transform_options( $input ) );
			if ( is_object( $transform ) && is_callable( array( $transform, 'toArray' ) ) ) {
				$transform = $transform->toArray();
			}
			if ( is_array( $transform ) ) {
				$transform_diagnostics = $transform['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
			}
		}

		return array(
			'schema'                  => 'static-site-importer/figma-diagnostics/v1',
			'success'                 => true,
			'request'                 => self::diagnostics_request_summary( $input ),
			'artifact'                => self::diagnostics_artifact_summary( $artifact ),
			'transform_diagnostics'   => is_array( $transform_diagnostics ) ? $transform_diagnostics : array(),
			'production_import_input' => array(
				'slug'      => (string) ( $import_input['slug'] ?? '' ),
				'name'      => (string) ( $import_input['name'] ?? '' ),
				'activate'  => (bool) ( $import_input['activate'] ?? false ),
				'overwrite' => (bool) ( $import_input['overwrite'] ?? false ),
			),
		);
	}

	/**
	 * Summarize the Figma request without echoing the full design payload.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function diagnostics_request_summary( array $input ): array {
		$scenegraph = self::scenegraph_from_input( $input );
		$source     = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();

		return array_filter(
			array(
				'has_scenegraph'  => ! empty( $scenegraph ),
				'has_bundle'      => isset( $input['artifact_bundle'] ) && is_array( $input['artifact_bundle'] ),
				'frame_id'        => isset( $input['frame_id'] ) ? (string) $input['frame_id'] : '',
				'source_file_key' => isset( $source['fileKey'] ) ? (string) $source['fileKey'] : '',
				'node_ids'        => isset( $source['nodeIds'] ) && is_array( $source['nodeIds'] ) ? array_values( array_map( 'strval', $source['nodeIds'] ) ) : array(),
			)
		);
	}

	/**
	 * Summarize the generated website artifact boundary.
	 *
	 * @param array<string,mixed> $artifact Website artifact.
	 * @return array<string,mixed>
	 */
	private static function diagnostics_artifact_summary( array $artifact ): array {
		$files       = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$paths       = array();
		$asset_paths = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['path'] ) || ! is_scalar( $file['path'] ) ) {
				continue;
			}

			$path    = (string) $file['path'];
			$paths[] = $path;
			if ( str_contains( $path, '/assets/' ) ) {
				$asset_paths[] = $path;
			}
		}

		return array(
			'schema'      => (string) ( $artifact['schema'] ?? '' ),
			'root'        => (string) ( $artifact['root'] ?? '' ),
			'entrypoint'  => (string) ( $artifact['entrypoint'] ?? '' ),
			'file_count'  => count( $paths ),
			'asset_count' => count( $asset_paths ),
			'asset_paths' => $asset_paths,
		);
	}

	/**
	 * Build a Figma plugin runner response for browser clients.
	 *
	 * @param array<string,mixed> $ability_result Ability result.
	 * @return array<string,mixed>
	 */
	public static function runner_response( array $ability_result ): array {
		if ( empty( $ability_result['success'] ) ) {
			$error = isset( $ability_result['error'] ) && is_array( $ability_result['error'] ) ? $ability_result['error'] : array();

			return array(
				'schema'  => 'figma-to-wordpress/runner-response/v1',
				'success' => false,
				'status'  => 'failed',
				'error'   => array(
					'code'    => (string) ( $error['code'] ?? 'static_site_importer_figma_import_failed' ),
					'message' => (string) ( $error['message'] ?? 'Figma import failed.' ),
					'data'    => $error['data'] ?? null,
				),
			);
		}

		$preview    = isset( $ability_result['preview'] ) && is_array( $ability_result['preview'] ) ? $ability_result['preview'] : array();
		$playground = isset( $preview['playground'] ) && is_array( $preview['playground'] ) ? $preview['playground'] : array();
		$open_url   = isset( $preview['url'] ) ? (string) $preview['url'] : '';
		if ( '' === $open_url && isset( $playground['blueprint_url'] ) ) {
			$open_url = (string) $playground['blueprint_url'];
		}

		return array(
			'schema'          => 'figma-to-wordpress/runner-response/v1',
			'success'         => true,
			'status'          => 'created',
			'open_url'        => '' !== $open_url ? $open_url : home_url( '/' ),
			'preview_session' => $preview,
			'materialization' => self::materialization_summary( $ability_result ),
		);
	}

	/**
	 * Build a compact materialization summary for browser runner clients.
	 *
	 * @param array<string,mixed> $ability_result Import ability result.
	 * @return array<string,mixed>
	 */
	private static function materialization_summary( array $ability_result ): array {
		$result  = isset( $ability_result['result'] ) && is_array( $ability_result['result'] ) ? $ability_result['result'] : array();
		$summary = isset( $result['import_report_summary'] ) && is_array( $result['import_report_summary'] ) ? $result['import_report_summary'] : array();

		return array_filter(
			array(
				'success'          => ! empty( $ability_result['success'] ),
				'theme_slug'       => isset( $result['theme_slug'] ) ? (string) $result['theme_slug'] : '',
				'theme_name'       => isset( $result['theme_name'] ) ? (string) $result['theme_name'] : '',
				'pages'            => isset( $result['pages'] ) && is_array( $result['pages'] ) ? $result['pages'] : array(),
				'quality_pass'     => isset( $summary['quality_pass'] ) ? (bool) $summary['quality_pass'] : null,
				'diagnostic_count' => isset( $summary['diagnostic_count'] ) ? (int) $summary['diagnostic_count'] : null,
				'fallback_count'   => isset( $summary['fallback_count'] ) ? (int) $summary['fallback_count'] : null,
			)
		);
	}

	/**
	 * Build a website artifact from a Figma import request.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function website_artifact_from_input( array $input ) {
		$figma_file = self::figma_file_from_input( $input );
		if ( ! empty( $figma_file ) ) {
			return self::website_artifact_from_figma_file( $figma_file, $input );
		}

		$scenegraph = self::scenegraph_from_input( $input );
		if ( ! empty( $scenegraph ) ) {
			$artifact = self::website_artifact_from_scenegraph( $scenegraph, $input );
			if ( ! is_wp_error( $artifact ) || ! isset( $input['artifact_bundle'] ) || ! is_array( $input['artifact_bundle'] ) ) {
				return $artifact;
			}
		}

		if ( isset( $input['artifact_bundle'] ) && is_array( $input['artifact_bundle'] ) ) {
			return self::website_artifact_from_bundle( $input['artifact_bundle'], $input );
		}

		return new WP_Error( 'static_site_importer_figma_source_missing', 'Figma imports require an artifact_bundle or a Figma scenegraph.', array( 'status' => 400 ) );
	}

	/**
	 * Convert the current Figma plugin artifact bundle into a website artifact.
	 *
	 * @param array<string,mixed> $bundle Figma plugin artifact bundle.
	 * @param array<string,mixed> $input  Full request input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function website_artifact_from_bundle( array $bundle, array $input ) {
		$files = self::normalize_files( isset( $bundle['files'] ) && is_array( $bundle['files'] ) ? $bundle['files'] : array(), (string) ( $bundle['root'] ?? 'website/' ) );
		if ( empty( $files ) ) {
			return new WP_Error( 'static_site_importer_figma_bundle_empty', 'The Figma artifact bundle did not contain importable files.', array( 'status' => 400 ) );
		}

		return array(
			'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
			'root'       => 'website',
			'entrypoint' => self::entrypoint( isset( $bundle['entrypoint'] ) ? (string) $bundle['entrypoint'] : '', $files ),
			'files'      => $files,
			'provenance' => self::provenance( $input ),
		);
	}

	/**
	 * Transform a raw Figma scenegraph into a website artifact.
	 *
	 * @param array<string,mixed> $scenegraph Figma scenegraph.
	 * @param array<string,mixed> $input      Full request input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function website_artifact_from_scenegraph( array $scenegraph, array $input ) {
		if ( ! function_exists( 'blocks_engine_figma_transformer_transform_scenegraph' ) ) {
			return new WP_Error( 'static_site_importer_figma_transformer_unavailable', 'Blocks Engine Figma transformer is not available.', array( 'status' => 501 ) );
		}

		$transform = blocks_engine_figma_transformer_transform_scenegraph( $scenegraph, self::transform_options( $input ) );

		return self::website_artifact_from_transform( $transform, $input );
	}

	/**
	 * Transform an uploaded .fig file into a website artifact.
	 *
	 * @param array<string,mixed> $figma_file Uploaded .fig source payload.
	 * @param array<string,mixed> $input      Full request input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function website_artifact_from_figma_file( array $figma_file, array $input ) {
		if ( ! function_exists( 'blocks_engine_figma_transformer_transform_file' ) ) {
			return new WP_Error( 'static_site_importer_figma_transformer_unavailable', 'Blocks Engine Figma transformer is not available.', array( 'status' => 501 ) );
		}

		$name = isset( $figma_file['name'] ) ? (string) $figma_file['name'] : '';
		if ( ! preg_match( '/\.fig$/i', $name ) ) {
			return new WP_Error( 'static_site_importer_figma_file_type_invalid', 'Figma file uploads must use a .fig file.', array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes uploaded .fig payload content.
		$content = isset( $figma_file['content_base64'] ) ? base64_decode( (string) $figma_file['content_base64'], true ) : false;
		if ( false === $content ) {
			return new WP_Error( 'static_site_importer_figma_file_content_invalid', 'Uploaded .fig content could not be decoded.', array( 'status' => 400 ) );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'ssi-fig-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Transformer requires a local file path for .fig archive inspection.
		if ( false === $tmp || false === file_put_contents( $tmp, $content ) ) {
			return new WP_Error( 'static_site_importer_figma_file_tempfile_failed', 'Uploaded .fig file could not be staged for transformation.', array( 'status' => 500 ) );
		}

		try {
			$transform = blocks_engine_figma_transformer_transform_file( $tmp, self::transform_options( $input ) );
		} finally {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		return self::website_artifact_from_transform( $transform, $input );
	}

	/**
	 * Convert a Blocks Engine Figma transform result into SSI's website artifact shape.
	 *
	 * @param mixed               $transform Transform result.
	 * @param array<string,mixed> $input     Full request input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function website_artifact_from_transform( $transform, array $input ) {
		if ( is_object( $transform ) && is_callable( array( $transform, 'toArray' ) ) ) {
			$transform = $transform->toArray();
		}
		if ( ! is_array( $transform ) ) {
			return new WP_Error( 'static_site_importer_figma_transform_failed', 'Blocks Engine Figma transformer returned an invalid result.', array( 'status' => 500 ) );
		}
		if ( isset( $transform['status'] ) && 'failed' === (string) $transform['status'] ) {
			return new WP_Error( 'static_site_importer_figma_transform_failed', 'Blocks Engine Figma transformer failed.', array(
				'status'    => 500,
				'transform' => $transform,
			) );
		}

		$files = self::normalize_files( isset( $transform['files'] ) && is_array( $transform['files'] ) ? $transform['files'] : array(), 'website/' );
		if ( empty( $files ) ) {
			return new WP_Error( 'static_site_importer_figma_transform_empty', 'Blocks Engine Figma transformer did not produce importable files.', array(
				'status'    => 500,
				'transform' => $transform,
			) );
		}

		return array(
			'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
			'root'       => 'website',
			'entrypoint' => self::entrypoint( 'website/index.html', $files ),
			'files'      => $files,
			'provenance' => self::provenance( $input ) + array( 'transform' => 'blocks-engine/figma-transformer' ),
		);
	}

	/**
	 * Extract the uploaded .fig file payload from supported request shapes.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function figma_file_from_input( array $input ): array {
		if ( isset( $input['figma_file'] ) && is_array( $input['figma_file'] ) ) {
			return $input['figma_file'];
		}

		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		if ( isset( $source['figma_file'] ) && is_array( $source['figma_file'] ) ) {
			return $source['figma_file'];
		}

		return array();
	}

	/**
	 * Normalize import files into the website/ artifact root.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @param string                         $root  Source root.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_files( array $files, string $root ): array {
		$normalized = array();
		$root       = trim( str_replace( '\\', '/', $root ), '/' );
		foreach ( $files as $file ) {
			if ( ! isset( $file['path'] ) ) {
				continue;
			}

			$path = self::artifact_path( (string) $file['path'], $root );
			if ( '' === $path ) {
				continue;
			}

			$record = array( 'path' => $path );
			if ( isset( $file['content_base64'] ) ) {
				$record['content_base64'] = (string) $file['content_base64'];
			} else {
				$record['content'] = isset( $file['content'] ) ? (string) $file['content'] : '';
			}

			if ( isset( $file['mime_type'] ) ) {
				$record['mime_type'] = (string) $file['mime_type'];
			}

			$normalized[] = $record;
		}

		return $normalized;
	}

	/**
	 * Normalize an artifact path under website/.
	 */
	private static function artifact_path( string $path, string $root ): string {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#(^|/)\.\.(?=/|$)#', '', $path );
		$path = preg_replace( '#[^A-Za-z0-9_./-]#', '-', (string) $path );
		$path = trim( preg_replace( '#/+#', '/', (string) $path ), '/' );

		if ( '' !== $root && str_starts_with( $path, $root . '/' ) ) {
			$path = substr( $path, strlen( $root ) + 1 );
		}

		return '' === $path ? '' : 'website/' . ltrim( $path, '/' );
	}

	/**
	 * Pick the entrypoint file.
	 *
	 * @param string                         $entrypoint Requested entrypoint.
	 * @param array<int,array<string,mixed>> $files      Normalized files.
	 */
	private static function entrypoint( string $entrypoint, array $files ): string {
		$paths      = array_map( static fn( array $file ): string => (string) ( $file['path'] ?? '' ), $files );
		$entrypoint = self::artifact_path( $entrypoint, 'website' );

		if ( '' !== $entrypoint && in_array( $entrypoint, $paths, true ) ) {
			return $entrypoint;
		}

		return in_array( 'website/index.html', $paths, true ) ? 'website/index.html' : (string) reset( $paths );
	}

	/**
	 * Extract a scenegraph from supported request shapes.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function scenegraph_from_input( array $input ): array {
		if ( isset( $input['scenegraph'] ) && is_array( $input['scenegraph'] ) ) {
			return $input['scenegraph'];
		}
		if ( isset( $input['figma'] ) && is_array( $input['figma'] ) ) {
			$figma = $input['figma'];
			if ( isset( $figma['scenegraph'] ) && is_array( $figma['scenegraph'] ) ) {
				return $figma['scenegraph'];
			}

			return $figma;
		}

		return array();
	}

	/**
	 * Collect runtime validation artifact refs when the runner requests visual/block validation.
	 *
	 * @param array<string,mixed> $input        Figma import input.
	 * @param array<string,mixed> $artifact     Website artifact.
	 * @param array<string,mixed> $import_input Import args.
	 * @return array<string,mixed>
	 */
	private static function validation_artifacts( array $input, array $artifact, array $import_input ): array {
		if ( isset( $input['validation_artifacts'] ) && is_array( $input['validation_artifacts'] ) ) {
			return $input['validation_artifacts'];
		}

		$validation = isset( $input['validation'] ) && is_array( $input['validation'] ) ? $input['validation'] : array();
		if ( empty( $validation ) || ( array_key_exists( 'enabled', $validation ) && empty( $validation['enabled'] ) ) ) {
			return array();
		}

		if ( empty( $validation['visual_parity'] ) && empty( $validation['block_validation'] ) ) {
			return array();
		}

		if ( ! class_exists( 'Static_Site_Importer_Codebox_Validation' ) ) {
			return array();
		}

		$result = Static_Site_Importer_Codebox_Validation::validate(
			array(
				'artifact'         => $artifact,
				'slug'             => $import_input['slug'] ?? '',
				'name'             => $import_input['name'] ?? '',
				'activate'         => false,
				'overwrite'        => true,
				'compiler_options' => isset( $import_input['compiler_options'] ) && is_array( $import_input['compiler_options'] ) ? $import_input['compiler_options'] : array(),
				'source_metadata'  => isset( $import_input['source_metadata'] ) && is_array( $import_input['source_metadata'] ) ? $import_input['source_metadata'] : array(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array();
		}

		return self::validation_artifacts_from_codebox_result( $result );
	}

	/**
	 * Map Codebox validation result refs into SSI visual parity artifact slots.
	 *
	 * @param array<string,mixed> $result Codebox validation result.
	 * @return array<string,mixed>
	 */
	private static function validation_artifacts_from_codebox_result( array $result ): array {
		$artifacts   = isset( $result['artifacts'] ) && is_array( $result['artifacts'] ) ? $result['artifacts'] : array();
		$screenshots = isset( $artifacts['screenshots'] ) && is_array( $artifacts['screenshots'] ) ? array_values( $artifacts['screenshots'] ) : array();
		$diffs       = isset( $artifacts['diffs'] ) && is_array( $artifacts['diffs'] ) ? array_values( $artifacts['diffs'] ) : array();

		return array_filter(
			array(
				'browser_render'      => isset( $artifacts['browser_render_evidence'] ) && is_array( $artifacts['browser_render_evidence'] ) ? $artifacts['browser_render_evidence'] : array(),
				'block_validation'    => isset( $artifacts['block_validation_result'] ) && is_array( $artifacts['block_validation_result'] ) ? $artifacts['block_validation_result'] : array(),
				'source_screenshot'   => isset( $screenshots[0] ) && is_array( $screenshots[0] ) ? $screenshots[0] : array(),
				'imported_screenshot' => isset( $screenshots[1] ) && is_array( $screenshots[1] ) ? $screenshots[1] : array(),
				'visual_diff'         => isset( $diffs[0] ) && is_array( $diffs[0] ) ? $diffs[0] : array(),
				'codebox_validation'  => $result,
			),
			static fn( mixed $value ): bool => array() !== $value
		);
	}

	/**
	 * Build import ability input from Figma request fields.
	 *
	 * @param array<string,mixed> $input    Figma import input.
	 * @param array<string,mixed> $artifact Website artifact.
	 * @return array<string,mixed>
	 */
	public static function import_input( array $input, array $artifact ): array {
		$title = self::display_title( $input, $artifact );

		return array(
			'artifact'                  => $artifact,
			'slug'                      => isset( $input['slug'] ) ? (string) $input['slug'] : '',
			'name'                      => isset( $input['name'] ) ? (string) $input['name'] : $title,
			'site_title'                => $title,
			'activate'                  => array_key_exists( 'activate', $input ) ? ! empty( $input['activate'] ) : true,
			'overwrite'                 => array_key_exists( 'overwrite', $input ) ? ! empty( $input['overwrite'] ) : true,
			'fail_on_quality'           => ! empty( $input['fail_on_quality'] ),
			'allow_missing_woocommerce' => ! empty( $input['allow_missing_woocommerce'] ),
			'compiler_options'          => isset( $input['compiler_options'] ) && is_array( $input['compiler_options'] ) ? $input['compiler_options'] : array(),
			'source_metadata'           => self::provenance( $input ),
		);
	}

	/**
	 * Derive a human-readable title for the imported site.
	 *
	 * @param array<string,mixed> $input    Figma import input.
	 * @param array<string,mixed> $artifact Website artifact.
	 */
	private static function display_title( array $input, array $artifact ): string {
		foreach ( array( 'site_title', 'title', 'name' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
				return sanitize_text_field( (string) $input[ $key ] );
			}
		}

		$metadata = self::metadata_from_artifact( $artifact );
		if ( isset( $metadata['title'] ) && is_scalar( $metadata['title'] ) && '' !== trim( (string) $metadata['title'] ) ) {
			return sanitize_text_field( (string) $metadata['title'] );
		}

		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
		if ( isset( $source['name'] ) && is_scalar( $source['name'] ) && '' !== trim( (string) $source['name'] ) ) {
			return sanitize_text_field( (string) $source['name'] );
		}

		$figma = isset( $input['figma'] ) && is_array( $input['figma'] ) ? $input['figma'] : array();
		if ( isset( $figma['name'] ) && is_scalar( $figma['name'] ) && '' !== trim( (string) $figma['name'] ) ) {
			return sanitize_text_field( (string) $figma['name'] );
		}

		return 'Figma Import';
	}

	/**
	 * Read metadata.json from a website artifact when present.
	 *
	 * @param array<string,mixed> $artifact Website artifact.
	 * @return array<string,mixed>
	 */
	private static function metadata_from_artifact( array $artifact ): array {
		$files = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['path'] ) || ! is_scalar( $file['path'] ) ) {
				continue;
			}

			if ( 'website/metadata.json' !== (string) $file['path'] && 'metadata.json' !== (string) $file['path'] ) {
				continue;
			}

			$content = isset( $file['content'] ) && is_scalar( $file['content'] ) ? (string) $file['content'] : '';
			if ( '' === trim( $content ) ) {
				continue;
			}

			$decoded = json_decode( $content, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * Build transformer options from request fields.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function transform_options( array $input ): array {
		$options = isset( $input['transform_options'] ) && is_array( $input['transform_options'] ) ? $input['transform_options'] : array();
		if ( isset( $input['frame_id'] ) ) {
			$options['frame_id'] = (string) $input['frame_id'];
		}

		return $options;
	}

	/**
	 * Build provenance metadata for generated artifacts/imports.
	 *
	 * @param array<string,mixed> $input Figma import input.
	 * @return array<string,mixed>
	 */
	private static function provenance( array $input ): array {
		$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();

		return array(
			'source'      => 'figma-to-wordpress',
			'schema'      => (string) ( $input['schema'] ?? 'static-site-importer/import-figma/v1' ),
			'figma'       => array_filter(
				array(
					'file_key'    => isset( $source['fileKey'] ) ? (string) $source['fileKey'] : '',
					'node_ids'    => isset( $source['nodeIds'] ) && is_array( $source['nodeIds'] ) ? array_values( array_map( 'strval', $source['nodeIds'] ) ) : array(),
					'exported_at' => isset( $source['exportedAt'] ) ? (string) $source['exportedAt'] : '',
				)
			),
			'import_goal' => isset( $input['goal'] ) ? (string) $input['goal'] : '',
		);
	}
}
