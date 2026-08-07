<?php
/**
 * Content-only boundary for untrusted website artifacts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

final class Static_Site_Importer_Content_Policy {
	/** Files that can be copied from an untrusted static-site artifact. */
	private const STATIC_EXTENSIONS = array(
		'html',
		'htm',
		'css',
		'js',
		'mjs',
		'json',
		'map',
		'xml',
		'txt',
		'md',
		'markdown',
		'svg',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'webp',
		'avif',
		'ico',
		'bmp',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
		'mp3',
		'mp4',
		'webm',
		'ogg',
		'wav',
		'pdf',
	);

	/** Assets that a compiler may carry into a generated companion plugin. */
	private const COMPANION_ASSET_EXTENSIONS = array( 'js', 'mjs', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot' );

	/** @return true|WP_Error */
	public static function validate_artifact( array $artifact ) {
		$files = $artifact['files'] ?? null;
		if ( ! is_array( $files ) ) {
			return new WP_Error( 'static_site_importer_artifact_files_invalid', 'Website artifacts must declare files as an array.' );
		}
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['path'] ) || ! is_scalar( $file['path'] ) ) {
				return new WP_Error( 'static_site_importer_artifact_file_invalid', 'Website artifacts must declare a path for every file.' );
			}
			$path = (string) $file['path'];
			if ( ! self::is_static_path( $path ) ) {
				return new WP_Error( 'static_site_importer_executable_source_rejected', sprintf( 'Untrusted artifact file %s is not static content.', $path ), array( 'path' => $path ) );
			}
			$content = self::file_content( $file );
			if ( null !== $content && self::contains_server_code( $content ) ) {
				return new WP_Error( 'static_site_importer_executable_source_rejected', sprintf( 'Untrusted artifact file %s contains server-side code.', $path ), array( 'path' => $path ) );
			}
		}
		return true;
	}

	public static function is_static_path( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' !== $extension && in_array( $extension, self::STATIC_EXTENSIONS, true );
	}

	public static function is_companion_asset_path( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' !== $extension && in_array( $extension, self::COMPANION_ASSET_EXTENSIONS, true );
	}

	public static function contains_server_code( string $content ): bool {
		return preg_match( '/<\?(?:php|=|[[:space:]])/i', $content ) === 1;
	}

	/** @param array<string,mixed> $file */
	private static function file_content( array $file ): ?string {
		if ( isset( $file['content'] ) && is_scalar( $file['content'] ) ) {
			return (string) $file['content'];
		}
		if ( ! isset( $file['content_base64'] ) || ! is_scalar( $file['content_base64'] ) ) {
			return null;
		}
		$decoded = base64_decode( (string) $file['content_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validates untrusted artifact bytes.
		return false === $decoded ? null : $decoded;
	}
}
