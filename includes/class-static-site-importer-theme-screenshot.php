<?php
/** Portable homepage previews become generated-theme thumbnails. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Theme_Screenshot {
	/** Bounded preview evidence outcomes so omission is diagnosable instead of silent. */
	public const STATUS_ABSENT       = 'absent';
	public const STATUS_INVALID      = 'invalid';
	public const STATUS_UNAVAILABLE  = 'unavailable';
	public const STATUS_MATERIALIZED = 'materialized';

	public const EVIDENCE_SCHEMA = 'static-site-importer/theme-preview-evidence/v1';

	/** Resolve the preview adjacent to the artifact entrypoint. */
	public static function from_artifact( array $artifact, ?object $payload_reader = null ): ?array {
		$evidence = self::evidence_from_artifact( $artifact, $payload_reader );
		return is_array( $evidence['preview'] ?? null ) ? $evidence['preview'] : null;
	}

	/**
	 * Resolve one bounded evidence record for the artifact's optional preview.
	 *
	 * The status set is closed: absent (no preview beside the entrypoint),
	 * invalid (bytes exist but are not a usable PNG), unavailable (the
	 * declared payload reference could not be read through the supplied
	 * reader), and materialized (valid bytes resolved for the theme write).
	 *
	 * @return array{schema:string,status:string,source_path:string,transport:?string,bytes:?int,sha256:?string,preview:?array<string,mixed>}
	 */
	public static function evidence_from_artifact( array $artifact, ?object $payload_reader = null ): array {
		$directory  = dirname( (string) ( $artifact['entrypoint'] ?? 'index.html' ) );
		$path       = ( '.' === $directory ? '' : $directory . '/' ) . 'site-preview.png';
		$evidence   = array(
			'schema'      => self::EVIDENCE_SCHEMA,
			'status'      => self::STATUS_ABSENT,
			'source_path' => $path,
			'transport'   => null,
			'bytes'       => null,
			'sha256'      => null,
			'preview'     => null,
		);
		$candidates = array_filter(
			is_array( $artifact['files'] ?? null ) ? $artifact['files'] : array(),
			static fn( mixed $file, mixed $key ): bool => ( is_array( $file ) ? ( $file['path'] ?? null ) : $key ) === $path,
			ARRAY_FILTER_USE_BOTH
		);
		if ( array() === $candidates ) {
			return $evidence;
		}
		$candidate = array_values( $candidates )[0];
		if ( ! is_array( $candidate ) ) {
			return $evidence;
		}

		$reference = isset( $candidate['payload_reference'] ) && is_array( $candidate['payload_reference'] ) ? $candidate['payload_reference'] : null;
		if ( null !== $reference ) {
			$evidence['transport'] = 'payload_reference';
			$bytes                 = self::read_reference( $reference, $payload_reader );
			if ( null === $bytes ) {
				$evidence['status'] = self::STATUS_UNAVAILABLE;
				return $evidence;
			}
		} else {
			$evidence['transport'] = 'content';
			$files                 = Static_Site_Importer_Diagnostic_Projection::artifact_file_contents( array( 'files' => $candidates ) );
			$bytes                 = $files[ $path ] ?? null;
			if ( ! is_string( $bytes ) ) {
				$evidence['status'] = self::STATUS_UNAVAILABLE;
				return $evidence;
			}
		}

		$evidence['bytes'] = strlen( $bytes );
		if ( ! self::valid_png( $bytes ) ) {
			$evidence['status'] = self::STATUS_INVALID;
			$evidence['sha256'] = hash( 'sha256', $bytes );
			return $evidence;
		}
		$evidence['status']  = self::STATUS_MATERIALIZED;
		$evidence['sha256']  = hash( 'sha256', $bytes );
		$evidence['preview'] = array(
			'source_path' => $path,
			'payload'     => array(
				'encoding' => 'base64',
				'data'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact transport.
			),
		);
		return $evidence;
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

	/** Strip the transport payload from an evidence record for report projection. */
	public static function bounded_evidence( array $evidence ): array {
		unset( $evidence['preview'] );
		return $evidence;
	}

	/** Read one declared payload reference through the supplied reader. */
	private static function read_reference( array $reference, ?object $payload_reader ): ?string {
		if ( ! is_object( $payload_reader ) || ! is_callable( array( $payload_reader, 'read' ) ) ) {
			return null;
		}
		try {
			$bytes = $payload_reader->read( $reference );
		} catch ( Throwable ) {
			return null;
		}
		return is_string( $bytes ) ? $bytes : null;
	}

	private static function valid_png( string $bytes ): bool {
		if ( strlen( $bytes ) > 10 * 1024 * 1024 || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return false;
		}
		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Optional malformed preview does not fail an import.
		return is_array( $size ) && IMAGETYPE_PNG === $size[2] && $size[0] > 0 && $size[1] > 0 && $size[0] <= 4096 && $size[1] <= 4096;
	}
}
