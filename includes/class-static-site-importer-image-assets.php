<?php
/**
 * Capture-owned resource identity at the importer boundary.
 *
 * The capture producer records images, fonts, documents and video under one
 * contract; this class keeps that single intake, identity and verification
 * path. Fonts stay theme-owned; every other recorded resource becomes one
 * Media Library attachment.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

/** Carries verified capture records, not URL-shaped rendition guesses. */
final class Static_Site_Importer_Image_Assets {
	private const KINDS                  = array( 'image', 'font', 'document', 'video' );
	private const IMAGE_CONTENT_TYPES    = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif', 'image/svg+xml' );
	private const FONT_CONTENT_TYPES     = array( 'font/woff2', 'font/woff', 'font/ttf', 'font/otf' );
	private const DOCUMENT_CONTENT_TYPES = array(
		'application/pdf',
		'application/zip',
		'application/gzip',
		'application/x-7z-compressed',
		'application/rtf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	);
	private const VIDEO_CONTENT_TYPES    = array( 'video/mp4', 'video/webm', 'video/ogg' );
	private const FONT_FACE_FIELDS       = array( 'family', 'weight', 'style', 'unicode_range', 'format' );

	/** Consume optional capture evidence before it can become a theme asset. */
	public static function extract( array $artifact, ?object $reader = null ) {
		$records = null;
		$files   = array();
		foreach ( $artifact['files'] ?? array() as $file ) {
			$name = basename( (string) ( $file['path'] ?? '' ) );
			if ( ! in_array( $name, array( 'image-assets.json', 'capture-receipt.json' ), true ) ) {
				$files[] = $file;
				continue;
			}
			$bytes = Static_Site_Importer_Content_Policy::file_bytes( $file, $reader );
			if ( ! is_string( $bytes ) || strlen( $bytes ) > 32 * 1024 * 1024 ) {
				return self::invalid();
			}
			$data = json_decode( $bytes, true );
			if ( ! is_array( $data ) ) {
				return self::invalid();
			}
			if ( 'image-assets.json' === $name ) {
				if ( 1 !== ( $data['version'] ?? null ) || ! is_array( $data['images'] ?? null ) ) {
					return self::invalid();
				}
				$records = $data['images'];
			} elseif ( null === $records ) {
				if ( 'blocks-engine-capture/capture-receipt/v1' !== ( $data['schema'] ?? null ) || ! is_array( $data['assets'] ?? null ) ) {
					return self::invalid();
				}
				$records = array_values( array_filter( $data['assets'], static fn( mixed $asset ): bool => is_array( $asset ) && array_key_exists( 'original', $asset ) ) );
			}
		}
		$artifact['files'] = $files;
		if ( empty( $records ) ) {
			return array(
				'artifact' => $artifact,
				'records'  => array(),
			);
		}
		$mapped  = array();
		$aliases = array();
		$by_hash = array();
		foreach ( $files as $file ) {
			$reference = $file['payload_reference'] ?? $file['payload']['reference'] ?? null;
			$hash      = is_array( $reference ) ? ( $reference['sha256'] ?? '' ) : '';
			if ( '' === $hash ) {
				$bytes = Static_Site_Importer_Content_Policy::file_bytes( $file, $reader );
				$hash  = is_string( $bytes ) ? hash( 'sha256', $bytes ) : '';
			}
			$by_hash[ $hash ][] = $file['path'];
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! self::record_is_valid( $record, $aliases ) ) {
				return self::invalid();
			}
			foreach ( $record['source_urls'] as $url ) {
				$aliases[ $url ] = true;
			}
			$matches = $by_hash[ $record['original']['sha256'] ] ?? array();
			if ( isset( $record['path'] ) ) {
				// Receipt paths disambiguate distinct resources with equal bytes.
				$matches = array_values( array_filter( $matches, static fn( string $path ): bool => $path === $record['path'] || 'website/' . $record['path'] === $path || 'website/' . $path === $record['path'] ) );
			}
			if ( empty( $matches ) ) {
				return self::invalid();
			}
			foreach ( $matches as $path ) {
				if ( isset( $mapped[ $path ] ) ) {
					return self::invalid();
				}
				$mapped[ $path ] = $record;
			}
		}
		foreach ( $artifact['files'] as &$file ) {
			if ( ! preg_match( '/\.html?$/i', $file['path'] ) ) {
				continue;
			}
			$html = Static_Site_Importer_Content_Policy::file_bytes( $file, $reader );
			if ( ! is_string( $html ) ) {
				return self::invalid();
			}
			$tags = new WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
				$src           = (string) $tags->get_attribute( 'src' );
				$path          = self::reference_path( $src, $file['path'] );
				$same_resource = isset( $mapped[ $path ] );
				foreach ( \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser::urls( (string) $tags->get_attribute( 'srcset' ) ) as $candidate ) {
					$candidate_path = self::reference_path( $candidate, $file['path'] );
					$same_resource  = $same_resource && isset( $mapped[ $candidate_path ] ) && $mapped[ $candidate_path ]['original'] === $mapped[ $path ]['original'];
				}
				if ( $same_resource ) {
					// Capture has already selected and exported the original. The
					// destination owns responsive sizes, not captured descriptors.
					$tags->remove_attribute( 'srcset' );
					$tags->remove_attribute( 'sizes' );
				}
			}
			$updated = $tags->get_updated_html();
			if ( $updated !== $html ) {
				unset( $file['payload_reference'], $file['payload'], $file['content_base64'] );
				$file['content'] = $updated;
			}
		}
		unset( $file );
		return array(
			'artifact' => $artifact,
			'records'  => $mapped,
		);
	}

	/** One validated record: a declared kind whose original, aliases and references agree. */
	private static function record_is_valid( array $record, array $aliases ): bool {
		$kind = self::kind( $record );
		if ( isset( $record['kind'] ) && ! in_array( $kind, self::KINDS, true ) ) {
			return false;
		}
		// Ownership is declared, never inferred: a font claiming the Media Library
		// (or an attachment-bound resource claiming theme ownership) is conflicting
		// evidence, and capture owns this decision.
		$owner = $record['destination_owner'] ?? ( 'font' === $kind ? 'theme' : 'media-library' );
		if ( self::destination_owner( $record ) !== $owner ) {
			return false;
		}
		$original = $record['original'] ?? null;
		$types    = match ( $kind ) {
			'font'     => self::FONT_CONTENT_TYPES,
			'document' => self::DOCUMENT_CONTENT_TYPES,
			'video'    => self::VIDEO_CONTENT_TYPES,
			default    => self::IMAGE_CONTENT_TYPES,
		};
		$valid = is_array( $original ) && is_string( $original['source_url'] ?? null ) && filter_var( $original['source_url'], FILTER_VALIDATE_URL ) && preg_match( '#^https?://#', $original['source_url'] ) && is_string( $original['sha256'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/', $original['sha256'] ) && in_array( $original['content_type'] ?? null, $types, true );
		if ( 'image' === $kind ) {
			$valid = $valid && is_int( $original['width'] ?? null ) && $original['width'] >= 1 && is_int( $original['height'] ?? null ) && $original['height'] >= 1 && is_bool( $original['alpha'] ?? null );
		}
		if ( ! $valid || ! is_array( $record['source_urls'] ?? null ) || empty( $record['source_urls'] ) || ! is_array( $record['references'] ?? null ) ) {
			return false;
		}
		foreach ( $record['source_urls'] as $url ) {
			if ( ! is_string( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) || isset( $aliases[ $url ] ) ) {
				return false;
			}
		}
		foreach ( $record['references'] as $reference ) {
			if ( ! is_array( $reference ) || ! in_array( $reference['kind'] ?? null, array( 'content', 'navigation', 'head', 'CSS' ), true ) || ! is_string( $reference['document_url'] ?? null ) || ! is_string( $reference['location'] ?? null ) || ! in_array( $reference['source_url'] ?? null, $record['source_urls'], true ) ) {
				return false;
			}
			// Font uses retain their declared @font-face descriptors.
			if ( isset( $reference['font_face'] ) ) {
				$face = $reference['font_face'];
				if ( ! is_array( $face ) || array_keys( $face ) !== array_filter( array_keys( $face ), static fn( string $field ): bool => in_array( $field, self::FONT_FACE_FIELDS, true ) ) ) {
					return false;
				}
				foreach ( self::FONT_FACE_FIELDS as $field ) {
					if ( isset( $face[ $field ] ) && ! is_string( $face[ $field ] ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/** The capture-declared resource kind; records from before the contract defaulted to images. */
	public static function kind( array $record ): string {
		$kind = $record['kind'] ?? 'image';
		return in_array( $kind, self::KINDS, true ) ? $kind : 'image';
	}

	/** Fonts stay theme-owned design assets; every other recorded resource is attachment-bound. */
	public static function destination_owner( array $record ): string {
		return 'font' === self::kind( $record ) ? 'theme' : 'media-library';
	}

	/** Non-font resources follow plan consumers; fonts stay theme-owned. */
	public static function plan_destination( array $record, array $asset ): string {
		if ( 'font' === self::kind( $record ) ) {
			return 'theme';
		}
		foreach ( $asset['consumers'] ?? array() as $consumer ) {
			if ( 'block' === ( $consumer['kind'] ?? '' ) ) {
				return 'media_library';
			}
		}
		return 'theme';
	}

	/** Export-root-relative and document-relative references share one file key. */
	private static function reference_path( string $url, string $document ): string {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$path = strtok( $url, '?#' );
			return ( str_starts_with( $document, 'website/' ) ? 'website/' : '' ) . ltrim( $path, '/' );
		}
		return \Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath::resolveRelativePath( $url, $document );
	}

	/** Validate staged bytes again immediately before writing: digest, byte-sniffed MIME and every size the record declares. */
	public static function verify( string $file, array $record ): bool {
		$original = $record['original'];
		if ( hash_file( 'sha256', $file ) !== $original['sha256'] || filesize( $file ) < 1 ) {
			return false;
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded staged capture bytes.
		if ( ! is_string( $bytes ) ) {
			return false;
		}
		if ( 'image' === self::kind( $record ) && 'image/svg+xml' !== $original['content_type'] ) {
			$size = wp_getimagesize( $file );
			return is_array( $size ) && $size[0] === $original['width'] && $size[1] === $original['height'] && ( $size['mime'] ?? '' ) === $original['content_type'] && ( ! $original['alpha'] || 'image/jpeg' !== $original['content_type'] );
		}
		return self::verify_bytes( $bytes, $record );
	}

	/** Validate one record's declared bytes against its digest, byte-sniffed MIME and image dimensions. */
	public static function verify_bytes( string $bytes, array $record ): bool {
		$original = $record['original'];
		if ( '' === $bytes || ! hash_equals( $original['sha256'], hash( 'sha256', $bytes ) ) ) {
			return false;
		}
		if ( 'image' === self::kind( $record ) ) {
			if ( 'image/svg+xml' === $original['content_type'] ) {
				$svg = strlen( $bytes ) <= 2 * 1024 * 1024 ? \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StandaloneSvgAsset::inspect( $bytes ) : array();
				return 'supported' === ( $svg['status'] ?? '' ) && ( $svg['width'] ?? null ) === $original['width'] && ( $svg['height'] ?? null ) === $original['height'];
			}
			$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid captured image bytes fail verification.
			return is_array( $size ) && $size[0] === $original['width'] && $size[1] === $original['height'] && $size['mime'] === $original['content_type'] && ( ! $original['alpha'] || 'image/jpeg' !== $original['content_type'] );
		}
		$sniffed = match ( self::kind( $record ) ) {
			'font'     => self::font_content_type( $bytes ),
			'document' => self::document_content_type( $bytes ),
			'video'    => self::video_content_type( $bytes ),
			default    => null,
		};
		// Response headers and extensions are not evidence; the declared MIME must
		// match what the bytes themselves announce.
		return $sniffed === $original['content_type'];
	}

	/** First value unpacked from a fixed-width binary field, or -1 when unreadable. */
	private static function unpacked( string $format, string $bytes ): int {
		$values = unpack( $format, $bytes );
		return is_array( $values ) ? (int) $values[1] : -1;
	}

	/** Signature of WOFF/WOFF2/sfnt font bytes, mirroring the capture producer's verifier. */
	private static function font_content_type( string $bytes ): ?string {
		if ( strlen( $bytes ) < 12 ) {
			return null;
		}
		$tables = self::unpacked( 'n', substr( $bytes, 4, 2 ) );
		if ( strlen( $bytes ) >= 48 && self::unpacked( 'n', substr( $bytes, 12, 2 ) ) > 0 && strlen( $bytes ) === self::unpacked( 'N', substr( $bytes, 8, 4 ) ) ) {
			return 'wOF2' === substr( $bytes, 0, 4 ) ? 'font/woff2' : ( 'wOFF' === substr( $bytes, 0, 4 ) ? 'font/woff' : null );
		}
		if ( $tables > 0 && $tables <= 512 && strlen( $bytes ) >= 12 + $tables * 16 ) {
			if ( 'OTTO' === substr( $bytes, 0, 4 ) ) {
				return 'font/otf';
			}
			if ( 0x00010000 === self::unpacked( 'N', substr( $bytes, 0, 4 ) ) || 'true' === substr( $bytes, 0, 4 ) ) {
				return 'font/ttf';
			}
		}
		return null;
	}

	/** Signature of document and archive bytes; unknown downloads stay unresolved. */
	private static function document_content_type( string $bytes ): ?string {
		if ( str_starts_with( $bytes, '%PDF-' ) && str_contains( substr( $bytes, -1024 ), '%%EOF' ) ) {
			return 'application/pdf';
		}
		if ( strlen( $bytes ) >= 22 && 0x04034b50 === self::unpacked( 'V', substr( $bytes, 0, 4 ) ) ) {
			// OOXML packages declare their document kind in the ZIP member inventory.
			if ( str_contains( $bytes, '[Content_Types].xml' ) ) {
				if ( str_contains( $bytes, 'word/document.xml' ) ) {
					return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
				}
				if ( str_contains( $bytes, 'xl/workbook.xml' ) ) {
					return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				}
				if ( str_contains( $bytes, 'ppt/presentation.xml' ) ) {
					return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
				}
			}
			return 'application/zip';
		}
		if ( str_starts_with( $bytes, "\x1f\x8b" ) ) {
			return 'application/gzip';
		}
		if ( str_starts_with( $bytes, "\x37\x7a\xbc\xaf\x27\x1c" ) ) {
			return 'application/x-7z-compressed';
		}
		if ( str_starts_with( $bytes, '{\\rtf' ) ) {
			return 'application/rtf';
		}
		return null;
	}

	/** Signature of MP4/WebM/Theora bytes. */
	private static function video_content_type( string $bytes ): ?string {
		if ( strlen( $bytes ) >= 16 && 'ftyp' === substr( $bytes, 4, 4 ) ) {
			return 'video/mp4';
		}
		if ( strlen( $bytes ) >= 16 && 0x1a45dfa3 === self::unpacked( 'N', substr( $bytes, 0, 4 ) ) && str_contains( substr( $bytes, 0, 4096 ), 'webm' ) ) {
			return 'video/webm';
		}
		if ( strlen( $bytes ) >= 4 && 'OggS' === substr( $bytes, 0, 4 ) && str_contains( $bytes, 'theora' ) ) {
			return 'video/ogg';
		}
		return null;
	}

	/** A record is authoritative: never silently fall back to a rendition or placeholder. */
	private static function invalid(): WP_Error {
		return new WP_Error( 'image_resource_unresolved', 'Capture asset records must identify an available, verified original with unambiguous source URLs.' );
	}
}
