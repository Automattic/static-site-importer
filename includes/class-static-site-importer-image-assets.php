<?php
/**
 * Capture-owned image identity at the importer boundary.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

/** Carries verified capture records, not URL-shaped rendition guesses. */
final class Static_Site_Importer_Image_Assets {
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
			if ( ! is_array( $record ) ) {
				return self::invalid();
			}
			$original = $record['original'] ?? null;
			if ( ! is_array( $original ) || ! is_string( $original['source_url'] ?? null ) || ! filter_var( $original['source_url'], FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#', $original['source_url'] ) || ! is_string( $original['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $original['sha256'] ) || ! in_array( $original['content_type'] ?? null, array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif', 'image/svg+xml' ), true ) || ! is_int( $original['width'] ?? null ) || $original['width'] < 1 || ! is_int( $original['height'] ?? null ) || $original['height'] < 1 || ! is_bool( $original['alpha'] ?? null ) || ! is_array( $record['source_urls'] ?? null ) || empty( $record['source_urls'] ) || ! is_array( $record['references'] ?? null ) ) {
				return self::invalid();
			}
			foreach ( $record['source_urls'] as $url ) {
				if ( ! is_string( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) || isset( $aliases[ $url ] ) ) {
					return self::invalid();
				}
				$aliases[ $url ] = true;
			}
			foreach ( $record['references'] as $reference ) {
				if ( ! is_array( $reference ) || ! in_array( $reference['kind'] ?? null, array( 'content', 'navigation', 'head', 'CSS' ), true ) || ! is_string( $reference['document_url'] ?? null ) || ! is_string( $reference['location'] ?? null ) || ! in_array( $reference['source_url'] ?? null, $record['source_urls'], true ) ) {
					return self::invalid();
				}
			}
			$matches = $by_hash[ $original['sha256'] ] ?? array();
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

	/** Export-root-relative and document-relative references share one file key. */
	private static function reference_path( string $url, string $document ): string {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$path = strtok( $url, '?#' );
			return ( str_starts_with( $document, 'website/' ) ? 'website/' : '' ) . ltrim( $path, '/' );
		}
		return \Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath::resolveRelativePath( $url, $document );
	}

	/** Validate staged bytes again immediately before upload. */
	public static function verify( string $file, array $record ): bool {
		$original = $record['original'];
		if ( 'image/svg+xml' === $original['content_type'] ) {
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded staged capture artwork.
			$svg   = false !== $bytes && strlen( $bytes ) <= 2 * 1024 * 1024 ? \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StandaloneSvgAsset::inspect( $bytes ) : array();
			return 'supported' === ( $svg['status'] ?? '' ) && ( $svg['width'] ?? null ) === $original['width'] && ( $svg['height'] ?? null ) === $original['height'] && hash_file( 'sha256', $file ) === $original['sha256'];
		}
		$size = wp_getimagesize( $file );
		return is_array( $size ) && $size[0] === $original['width'] && $size[1] === $original['height'] && ( $size['mime'] ?? '' ) === $original['content_type'] && hash_file( 'sha256', $file ) === $original['sha256'] && ( ! $original['alpha'] || 'image/jpeg' !== $original['content_type'] );
	}

	/** A record is authoritative: never silently fall back to a rendition. */
	private static function invalid(): WP_Error {
		return new WP_Error( 'image_resource_unresolved', 'Capture image records must identify an available, verified original with unambiguous source URLs.' );
	}
}
