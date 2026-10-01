<?php
/** Portable homepage previews become generated-theme thumbnails. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Theme_Screenshot {
	/** Resolve the preview adjacent to the artifact entrypoint. */
	public static function from_artifact( array $artifact ): ?array {
		$directory  = dirname( (string) ( $artifact['entrypoint'] ?? 'index.html' ) );
		$path       = ( '.' === $directory ? '' : $directory . '/' ) . 'site-preview.png';
		$candidates = array_filter(
			is_array( $artifact['files'] ?? null ) ? $artifact['files'] : array(),
			static fn( mixed $file, mixed $key ): bool => ( is_array( $file ) ? ( $file['path'] ?? null ) : $key ) === $path,
			ARRAY_FILTER_USE_BOTH
		);
		$files      = Static_Site_Importer_Diagnostic_Projection::artifact_file_contents( array( 'files' => $candidates ) );
		$bytes      = $files[ $path ] ?? null;
		if ( ! is_string( $bytes ) || ! self::valid_png( $bytes ) ) {
			return null;
		}
		return array(
			'source_path' => $path,
			'payload'     => array(
				'encoding' => 'base64',
				'data'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact transport.
			),
		);
	}

	/** Add the thumbnail to the existing transactional write/preflight contract. */
	public static function with_write( array $resolved, array $args ): array {
		$preview = $args['theme_screenshot'] ?? null;
		if ( ! is_array( $preview ) || Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $args['destination'] ?? '' ) ) {
			return $resolved;
		}
		$bytes = base64_decode( (string) ( $preview['payload']['data'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary artifact transport.
		if ( ! is_string( $bytes ) || ! self::valid_png( $bytes ) ) {
			return $resolved;
		}
		$resolved['writes'][] = array(
			'target_path'             => 'screenshot.png',
			'source_path'             => (string) ( $preview['source_path'] ?? 'site-preview.png' ),
			'kind'                    => 'theme_asset',
			'payload'                 => array(
				'encoding' => 'base64',
				'data'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary theme asset.
			),
			'payload_hash'            => hash( 'sha256', $bytes ),
			'reconciliation_identity' => hash( 'sha256', "theme-screenshot\nscreenshot.png" ),
		);
		return $resolved;
	}

	private static function valid_png( string $bytes ): bool {
		if ( strlen( $bytes ) > 10 * 1024 * 1024 || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return false;
		}
		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Optional malformed preview does not fail an import.
		return is_array( $size ) && IMAGETYPE_PNG === $size[2] && $size[0] > 0 && $size[1] > 0 && $size[0] <= 4096 && $size[1] <= 4096;
	}
}
