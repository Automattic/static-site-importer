<?php
/**
 * Content-only boundary for untrusted website artifacts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( 'Static_Site_Importer_Runtime_Capabilities' ) ) {
	require_once __DIR__ . '/class-static-site-importer-runtime-capabilities.php';
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
		'cur',
		'bmp',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
		'mp3',
		'mp4',
		'mov',
		'webm',
		'ogg',
		'wav',
		'pdf',
	);

	/** Static formats whose source bytes are inspected for server-side code. */
	private const TEXTUAL_EXTENSIONS = array( 'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'xml', 'txt', 'md', 'markdown', 'svg' );

	/** Assets that a compiler may carry into a generated companion plugin. */
	private const COMPANION_ASSET_EXTENSIONS = array( 'js', 'mjs', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'cur', 'woff', 'woff2', 'ttf', 'otf', 'eot' );

	/** Portable extensions for downloaded assets whose URL paths carry no filename extension. */
	private const PORTABLE_CONTENT_TYPE_EXTENSIONS = array(
		'text/css'                 => 'css',
		'text/javascript'          => 'js',
		'application/javascript'   => 'js',
		'application/json'         => 'json',
		'image/jpeg'               => 'jpg',
		'image/png'                => 'png',
		'image/gif'                => 'gif',
		'image/webp'               => 'webp',
		'image/avif'               => 'avif',
		'image/svg+xml'            => 'svg',
		'image/bmp'                => 'bmp',
		'image/x-icon'             => 'ico',
		'image/vnd.microsoft.icon' => 'ico',
		'font/woff'                => 'woff',
		'application/font-woff'    => 'woff',
		'font/woff2'               => 'woff2',
		'font/ttf'                 => 'ttf',
		'font/otf'                 => 'otf',
		'video/mp4'                => 'mp4',
		'video/quicktime'          => 'mov',
		'video/webm'               => 'webm',
		'audio/mpeg'               => 'mp3',
		'audio/ogg'                => 'ogg',
		'audio/wav'                => 'wav',
		'application/pdf'          => 'pdf',
	);

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
			$path   = (string) $file['path'];
			$format = self::source_format( $path );
			if ( null !== $format && ! Static_Site_Importer_Runtime_Capabilities::supports_source_format( $format ) ) {
				return new WP_Error(
					'static_site_importer_source_format_unsupported',
					sprintf( 'The current runtime does not include the %s conversion capability required by %s.', $format, $path ),
					array(
						'path'   => $path,
						'format' => $format,
					)
				);
			}
			if ( ! self::is_static_path( $path ) ) {
				return new WP_Error( 'static_site_importer_executable_source_rejected', sprintf( 'Untrusted artifact file %s is not static content.', $path ), array( 'path' => $path ) );
			}
			$content = self::file_content( $file );
			if ( null !== $content && self::is_textual_path( $path ) && self::contains_server_code( $content ) ) {
				return new WP_Error( 'static_site_importer_executable_source_rejected', sprintf( 'Untrusted artifact file %s contains server-side code.', $path ), array( 'path' => $path ) );
			}
		}
		return true;
	}

	private static function source_format( string $path ): ?string {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'md', 'markdown' => 'markdown',
			'mdx'            => 'mdx',
			default          => null,
		};
	}

	public static function is_static_path( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' !== $extension && in_array( $extension, self::STATIC_EXTENSIONS, true );
	}

	/**
	 * Infer a portable, import-safe extension for a downloaded asset whose URL
	 * path carries no filename extension, such as Google Fonts /css2 or CDN
	 * photo endpoints. Unknown or non-static content types return an empty
	 * string so the static-content boundary keeps rejecting them.
	 *
	 * @param string $content_type Fetched content type, optionally with parameters.
	 * @return string Portable extension with no leading dot, or '' when none applies.
	 */
	public static function portable_extension( string $content_type ): string {
		$normalized = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		$extension  = self::PORTABLE_CONTENT_TYPE_EXTENSIONS[ $normalized ] ?? '';
		return '' !== $extension && in_array( $extension, self::STATIC_EXTENSIONS, true ) ? $extension : '';
	}

	public static function is_textual_path( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' !== $extension && in_array( $extension, self::TEXTUAL_EXTENSIONS, true );
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
