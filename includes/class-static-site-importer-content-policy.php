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
		'webmanifest',
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
		'docx',
	);

	/** Static formats whose source bytes are inspected for server-side code. */
	private const TEXTUAL_EXTENSIONS = array( 'html', 'htm', 'css', 'js', 'mjs', 'json', 'webmanifest', 'map', 'xml', 'txt', 'md', 'markdown', 'svg' );

	/** Assets that a compiler may carry into a generated companion plugin. */
	private const COMPANION_ASSET_EXTENSIONS = array( 'js', 'mjs', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'cur', 'woff', 'woff2', 'ttf', 'otf', 'eot' );

	/** Root-level Netlify `_redirects` manifests are bounded import metadata, not pages. */
	public const REDIRECTS_MANIFEST_MAX_BYTES = 65536;

	/** Portable extensions for downloaded assets whose URL paths carry no filename extension. */
	private const PORTABLE_CONTENT_TYPE_EXTENSIONS = array(
		'text/css'                  => 'css',
		'text/javascript'           => 'js',
		'application/javascript'    => 'js',
		'application/json'          => 'json',
		'application/manifest+json' => 'json',
		'image/jpeg'                => 'jpg',
		'image/png'                 => 'png',
		'image/gif'                 => 'gif',
		'image/webp'                => 'webp',
		'image/avif'                => 'avif',
		'image/svg+xml'             => 'svg',
		'image/bmp'                 => 'bmp',
		'image/x-icon'              => 'ico',
		'image/vnd.microsoft.icon'  => 'ico',
		'font/woff'                 => 'woff',
		'application/font-woff'     => 'woff',
		'font/woff2'                => 'woff2',
		'font/ttf'                  => 'ttf',
		'font/otf'                  => 'otf',
		'video/mp4'                 => 'mp4',
		'video/quicktime'           => 'mov',
		'video/webm'                => 'webm',
		'audio/mpeg'                => 'mp3',
		'audio/ogg'                 => 'ogg',
		'audio/wav'                 => 'wav',
		'application/pdf'           => 'pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
	);

	/**
	 * @param array<string,mixed> $artifact Website artifact.
	 * @param object|null $payload_reader Canonical source payload reader.
	 * @param callable|null $retain_text_payload Optional freeze-boundary consumer of verified textual bytes.
	 * @return true|WP_Error
	 */
	public static function validate_artifact( array $artifact, ?object $payload_reader = null, ?callable $retain_text_payload = null ) {
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
			if ( self::is_redirects_manifest_path( $path ) ) {
				$bytes = self::declared_bytes( $file );
				if ( null !== $bytes && $bytes > self::REDIRECTS_MANIFEST_MAX_BYTES ) {
					return new WP_Error( 'static_site_importer_executable_source_rejected', sprintf( 'Untrusted artifact file %s is not static content.', $path ), array( 'path' => $path ) );
				}
			}
			if ( self::is_textual_path( $path ) ) {
				$content = self::file_bytes( $file, $payload_reader );
				if ( $content instanceof WP_Error ) {
					return $content;
				}
				if ( null !== $content && self::path_contains_server_code( $path, $content ) ) {
					return new WP_Error( 'static_site_importer_executable_source_rejected', sprintf( 'Untrusted artifact file %s contains server-side code.', $path ), array( 'path' => $path ) );
				}
				if ( null !== $content && null !== $retain_text_payload && ( isset( $file['payload_reference'] ) || isset( $file['payload']['reference'] ) ) ) {
					$retained = $retain_text_payload( $file, $content );
					if ( $retained instanceof WP_Error ) {
						return $retained;
					}
				}
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
		if ( self::is_redirects_manifest_path( $path ) ) {
			return true;
		}
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' !== $extension && in_array( $extension, self::STATIC_EXTENSIONS, true );
	}

	public static function is_redirects_manifest_path( string $path ): bool {
		$normalized = str_replace( '\\', '/', $path );
		$normalized = ltrim( $normalized, '/' );
		if ( str_starts_with( $normalized, 'website/' ) ) {
			$normalized = substr( $normalized, strlen( 'website/' ) );
		}

		return '_redirects' === $normalized;
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
		if ( self::is_redirects_manifest_path( $path ) ) {
			return true;
		}
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

	/** Only HTML honors inert markup; SVG, JS, CSS, and other sources stay raw. */
	public static function path_contains_server_code( string $path, string $content ): bool {
		if ( ! self::contains_server_code( $content ) ) {
			return false;
		}
		if ( ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'html', 'htm' ), true ) ) {
			return true;
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return true;
		}

		// Preserve encoded examples when core decodes text nodes. This does not
		// change tag/comment boundaries, and raw-text bodies are never decoded.
		$tokens = new WP_HTML_Tag_Processor( str_replace( '&', '&amp;', $content ) );
		while ( $tokens->next_token() ) {
			$type = $tokens->get_token_type();
			// Core 7.1 exposes PHP-style instructions separately from bogus
			// comments. Their target is part of the original server-code opener.
			if ( '#processing-instruction' === $type && self::contains_server_code( '<?' . $tokens->get_tag() ) ) {
				return true;
			}
			if ( '#comment' === $type ) {
				// Only actual HTML comments are inert. PHP processing instructions
				// become bogus comments in HTML, but remain active source here.
				if ( in_array( $tokens->get_comment_type(), array( WP_HTML_Tag_Processor::COMMENT_AS_HTML_COMMENT, WP_HTML_Tag_Processor::COMMENT_AS_ABRUPTLY_CLOSED_COMMENT ), true ) ) {
					continue;
				}
				if ( self::contains_server_code( '<' . $tokens->get_full_comment_text() ) ) {
					return true;
				}
			}
			// Completed tag syntax (including attribute values) is inert. Core
			// exposes SCRIPT/STYLE and other raw-text bodies on the tag token,
			// so these must be scanned even though next_token skips their markup.
			if ( self::contains_server_code( $tokens->get_modifiable_text() ) ) {
				return true;
			}
		}
		// A marker in incomplete markup cannot establish an inert context.
		return $tokens->paused_at_incomplete_token();
	}

	/** Resolve textual references using the owning retention contract, before scanning. */
	private static function file_bytes( array $file, ?object $payload_reader ) {
		if ( ! array_key_exists( 'payload_reference', $file ) && ! ( is_array( $file['payload'] ?? null ) && array_key_exists( 'reference', $file['payload'] ) ) ) {
			return self::file_content( $file );
		}
		require_once __DIR__ . '/class-static-site-importer-site-plan-persistence.php';
		require_once __DIR__ . '/class-static-site-importer-compiler-limits.php';
		$reference = Static_Site_Importer_Site_Plan_Persistence::payload_reference( $file );
		$maximum   = Static_Site_Importer_Compiler_Limits::resolve()['max_file_bytes'];
		if ( self::is_redirects_manifest_path( (string) $file['path'] ) ) {
			$maximum = self::REDIRECTS_MANIFEST_MAX_BYTES;
		}
		if ( ! Static_Site_Importer_Site_Plan_Persistence::valid_payload_reference( $reference ) || ! is_int( $reference['bytes'] ?? null ) || $reference['bytes'] > $maximum ) {
			return new WP_Error( 'static_site_importer_payload_reference_invalid', 'Textual source references require a bounded byte count and SHA-256 digest.' );
		}
		return Static_Site_Importer_Site_Plan_Persistence::write_payload_bytes( $file, $payload_reader );
	}

	/** @param array<string,mixed> $file */
	private static function declared_bytes( array $file ): ?int {
		$content = self::file_content( $file );
		if ( null !== $content ) {
			return strlen( $content );
		}
		$reference = isset( $file['payload_reference'] ) && is_array( $file['payload_reference'] ) ? $file['payload_reference'] : null;
		if ( null === $reference || ! isset( $reference['bytes'] ) || ! is_numeric( $reference['bytes'] ) ) {
			return null;
		}

		return (int) $reference['bytes'];
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
