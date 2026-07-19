<?php
/**
 * Materialize importer-owned media into the WordPress Media Library.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Media_Materializer {
	/**
	 * Create attachments for sanitized SVG assets emitted by the transformer.
	 *
	 * @param array<string,mixed>                         $artifacts           Compiled artifacts.
	 * @param array<string,array<string,mixed>>           $materialized_assets Materialized asset reports keyed by source path.
	 * @return array{attachments:array<int,array<string,mixed>>,replacements:array<string,array{url:string,id:int}>}|WP_Error
	 */
	public static function materialize_sanitized_svgs( array $artifacts, array $materialized_assets ) {
		$site   = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		$assets = isset( $site['assets'] ) && is_array( $site['assets'] ) ? $site['assets'] : array();
		$result = array(
			'attachments' => array(),
			'replacements' => array(),
		);
		$processed = array();
		if ( empty( $assets ) ) {
			return $result;
		}

		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'wp_insert_attachment' ) || ! function_exists( 'wp_get_attachment_url' ) ) {
			return new WP_Error( 'static_site_importer_media_api_unavailable', 'WordPress media APIs are required to materialize generated SVG attachments.' );
		}

		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return new WP_Error( 'static_site_importer_media_upload_directory_unavailable', is_array( $uploads ) ? (string) ( $uploads['error'] ?? 'WordPress upload directory is unavailable.' ) : 'WordPress upload directory is unavailable.' );
		}

		$directory = trailingslashit( (string) $uploads['basedir'] ) . 'static-site-importer';
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'static_site_importer_media_directory_failed', sprintf( 'Failed to create generated media directory: %s', $directory ) );
		}

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ! self::is_sanitized_svg_asset( $asset ) ) {
				continue;
			}

			$source_path = self::safe_source_path( (string) ( $asset['path'] ?? ( $asset['source'] ?? '' ) ) );
			if ( '' === $source_path || isset( $processed[ $source_path ] ) || ! isset( $materialized_assets[ $source_path ] ) ) {
				continue;
			}
			$processed[ $source_path ] = true;

			$content = self::asset_content( $asset );
			if ( '' === $content || ! self::is_safe_svg( $content ) ) {
				return new WP_Error( 'static_site_importer_generated_svg_unsafe', sprintf( 'Generated SVG failed the attachment safety gate: %s', $source_path ) );
			}

			$hash     = hash( 'sha256', $content );
			$stem     = sanitize_title( pathinfo( basename( $source_path ), PATHINFO_FILENAME ) );
			$filename = ( '' === $stem ? 'generated-svg' : $stem ) . '-' . substr( $hash, 0, 16 ) . '.svg';
			$file     = trailingslashit( $directory ) . $filename;
			$url      = trailingslashit( (string) $uploads['baseurl'] ) . 'static-site-importer/' . rawurlencode( $filename );

			if ( ! file_exists( $file ) ) {
				$written = Static_Site_Importer_Theme_Materializer::write_file( $file, $content . ( str_ends_with( $content, "\n" ) ? '' : "\n" ) );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}

			$attachment_id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
			if ( $attachment_id <= 0 ) {
				$attachment_id = wp_insert_attachment(
					array(
						'post_mime_type' => 'image/svg+xml',
						'post_title'     => sanitize_text_field( str_replace( '-', ' ', '' === $stem ? 'Generated SVG' : $stem ) ),
						'post_status'    => 'inherit',
					),
					$file,
					0,
					true
				);
				if ( is_wp_error( $attachment_id ) ) {
					return $attachment_id;
				}
				$attachment_id = (int) $attachment_id;
				if ( function_exists( 'update_attached_file' ) ) {
					update_attached_file( $attachment_id, $file );
				}
				if ( function_exists( 'update_post_meta' ) ) {
					update_post_meta( $attachment_id, '_static_site_importer_asset_hash', $hash );
					update_post_meta( $attachment_id, '_static_site_importer_source_path', $source_path );
				}
			}

			$attachment_url = (string) wp_get_attachment_url( $attachment_id );
			if ( '' === $attachment_url ) {
				return new WP_Error( 'static_site_importer_attachment_url_missing', sprintf( 'Generated SVG attachment has no URL: %s', $source_path ) );
			}

			$old_url = isset( $materialized_assets[ $source_path ]['final_url'] ) && is_scalar( $materialized_assets[ $source_path ]['final_url'] ) ? (string) $materialized_assets[ $source_path ]['final_url'] : '';
			if ( '' !== $old_url ) {
				$result['replacements'][ $old_url ] = array(
					'url' => $attachment_url,
					'id'  => $attachment_id,
				);
			}
			$result['attachments'][] = array(
				'id'          => $attachment_id,
				'url'         => $attachment_url,
				'source_path' => $source_path,
				'hash'        => $hash,
				'mime_type'   => 'image/svg+xml',
			);
		}

		return $result;
	}

	/**
	 * Rewrite generated media URLs in serialized block content.
	 *
	 * @param string                                      $markup       Serialized blocks.
	 * @param array<string,array{url:string,id:int}>       $replacements Attachment replacements keyed by old URL.
	 * @return string
	 */
	public static function rewrite_block_media( string $markup, array $replacements ): string {
		foreach ( $replacements as $old_url => $replacement ) {
			$markup = str_replace( $old_url, $replacement['url'], $markup );
		}

		return $markup;
	}

	/** @param array<string,mixed> $asset */
	private static function is_sanitized_svg_asset( array $asset ): bool {
		$mime = strtolower( (string) ( $asset['mime_type'] ?? ( $asset['media_type'] ?? '' ) ) );
		$role = strtolower( str_replace( '-', '_', (string) ( $asset['source_role'] ?? '' ) ) );

		return 'image/svg+xml' === $mime && 'importer_owned' === $role && true === ( $asset['pipeline_sanitized'] ?? false );
	}

	/** @param array<string,mixed> $asset */
	private static function asset_content( array $asset ): string {
		if ( isset( $asset['content'] ) && is_scalar( $asset['content'] ) ) {
			return trim( (string) $asset['content'] );
		}
		if ( isset( $asset['content_base64'] ) && is_scalar( $asset['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes trusted generated asset content.
			$decoded = base64_decode( (string) $asset['content_base64'], true );
			return false === $decoded ? '' : trim( $decoded );
		}

		return '';
	}

	private static function safe_source_path( string $path ): string {
		$path = ltrim( str_replace( '\\', '/', trim( $path ) ), '/' );
		return '' === $path || str_contains( $path, '..' ) ? '' : $path;
	}

	private static function is_safe_svg( string $svg ): bool {
		return 1 === preg_match( '/^<svg\b[\s\S]*<\/svg>$/i', trim( $svg ) )
			&& 0 === preg_match( '/<(?:script|foreignObject|iframe|object|embed)\b/i', $svg )
			&& 0 === preg_match( '/\son[a-z]+\s*=/i', $svg )
			&& 0 === preg_match( '/\b(?:href|xlink:href|src)\s*=\s*(["\'])(?!#)[^"\']+\1/i', $svg );
	}
}
