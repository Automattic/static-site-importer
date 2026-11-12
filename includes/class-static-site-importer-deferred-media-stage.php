<?php
/**
 * Durable, bounded capture media imports, separate from compilation.
 *
 * A capture made with deferred media references its media as `/deferred-media/<hash>/<name>` paths and lists them
 * in `media-queue.json`. Content imports reference each file's source URL, so
 * an imported post shows the source image before its media is imported. The
 * media stage then sideloads the downloaded files in bounded, resumable
 * batches and rewrites those source URLs to attachment URLs.
 *
 * @package StaticSiteImporter
 */

/** Deferred media service. */
final class Static_Site_Importer_Deferred_Media_Stage {
	/** Any producer's media queue: `<producer>/media-queue/v1`. */
	const QUEUE_SCHEMA_PATTERN = '~^[a-z0-9][a-z0-9._-]*/media-queue/v1$~';
	const MAX_BATCH    = 500;

	/**
	 * Read and validate a local capture queue without loading its media.
	 *
	 * @return array<string,array<string,mixed>> Queue entries keyed by deferred path.
	 */
	public static function queue( string $root ): array {
		$file = $root . '/media-queue.json';
		if ( ! is_file( $file ) || is_link( $file ) || filesize( $file ) > 64 * 1024 * 1024 ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local capture manifest.
		if ( ! is_array( $data ) || ! is_string( $data['schema'] ?? null ) || 1 !== preg_match( self::QUEUE_SCHEMA_PATTERN, $data['schema'] ) || ! is_array( $data['entries'] ?? null ) ) {
			return array();
		}
		$entries = array();
		foreach ( $data['entries'] as $entry ) {
			$path = is_array( $entry ) ? ( $entry['path'] ?? '' ) : '';
			if ( is_string( $path ) && preg_match( '#^deferred-media/[a-zA-Z0-9_-]+/[^/\\\\]+$#D', $path ) && ! in_array( basename( $path ), array( '.', '..' ), true ) && '' !== self::source_url( $entry ) ) {
				// Several references can share one downloaded file; the first names it.
				$entries[ $path ] = $entries[ $path ] ?? $entry;
			}
		}
		return $entries;
	}

	/**
	 * Point a document's deferred media references at their source URLs.
	 *
	 * The compiler leaves external URLs alone, so the imported content stays
	 * correct before, during and after the media stage.
	 *
	 * @param array<string,array<string,mixed>> $queue Queue entries keyed by deferred path.
	 */
	public static function source_references( string $html, array $queue ): string {
		return (string) preg_replace_callback(
			'#/?(deferred-media/[a-zA-Z0-9_-]+/[^"\'\s()<>]+)#',
			static fn( array $reference ): string => isset( $queue[ $reference[1] ] ) ? self::source_url( $queue[ $reference[1] ] ) : $reference[0],
			$html
		);
	}

	/**
	 * Open (or extend) the media stage for a completed content import.
	 *
	 * @return array<string,mixed> The receipt `media` block, empty without a queue.
	 */
	public static function register( array $state ): array {
		$root = $state['args']['source_metadata']['deferred_media']['root'] ?? '';
		if ( ! is_string( $root ) || '' === $root ) {
			return array();
		}
		$ref              = 'ssi-media-' . hash( 'sha256', $root );
		$journal          = get_option( $ref, array() );
		$journal          = is_array( $journal ) ? $journal : array();
		$journal['root']  = $root;
		$journal['posts'] = array_values( array_unique( array_merge( $journal['posts'] ?? array(), array_map( 'intval', array_column( $state['applied']['posts'] ?? array(), 'id' ) ) ) ) );
		$journal['entries'] = $journal['entries'] ?? array();
		update_option( $ref, $journal, false );
		return self::receipt( $ref, $journal, self::queue( $root ) );
	}

	/**
	 * Import at most one batch of queue entries, then rewrite the imported
	 * content once for the whole batch.
	 *
	 * Attachments carry their queue identity and content rewrites replace only
	 * source URLs, so a batch interrupted before its journal write repeats
	 * without duplicating either.
	 *
	 * @param array{ref?:string,batch?:int} $input Stage reference and batch size.
	 * @return array<string,mixed>|WP_Error The progress receipt.
	 */
	public static function run( array $input ) {
		$ref = (string) ( $input['ref'] ?? '' );
		if ( ! preg_match( '/^ssi-media-[a-f0-9]{64}$/D', $ref ) ) {
			return new WP_Error( 'static_site_importer_deferred_media_reference_invalid', 'Use the media ref from the import receipt.' );
		}
		if ( ! is_array( get_option( $ref ) ) ) {
			return new WP_Error( 'static_site_importer_deferred_media_not_found', 'No deferred media stage exists for this reference.' );
		}
		// An OS-owned lock is released even when a worker is interrupted or killed.
		$uploads = wp_upload_dir();
		$lock    = fopen( $uploads['basedir'] . '/' . $ref . '.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Cross-process advisory lock, not an upload.
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release lock handle.
			}
			return new WP_Error( 'static_site_importer_deferred_media_busy', 'This media stage is running. Retry after the current worker finishes.' );
		}
		try {
			$journal = get_option( $ref );
			$queue   = self::queue( $journal['root'] );
			$batch   = max( 1, min( self::MAX_BATCH, (int) ( $input['batch'] ?? 50 ) ) );
			$root    = realpath( $journal['root'] . '/website' );
			$done    = array();
			$bound   = array();
			foreach ( $queue as $path => $entry ) {
				if ( isset( $journal['entries'][ $path ] ) ) {
					continue;
				}
				if ( count( $done ) === $batch ) {
					break;
				}
				$file   = realpath( $journal['root'] . '/website/' . $path );
				$id     = 0;
				$reason = '';
				if ( 'done' !== ( $entry['status'] ?? '' ) ) {
					$reason = 'source_' . ( is_string( $entry['status'] ?? null ) ? $entry['status'] : 'pending' );
				} elseif ( false === $file || false === $root || ! str_starts_with( $file, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $file ) ) {
					$reason = 'file_missing';
				} else {
					$state = array();
					$error = null;
					$id    = Static_Site_Importer_Media_Library_Materializer::deferred_attachment( $file, $path, $ref . ':' . $path, $state, $error );
					if ( ! $id ) {
						$reason = $error ? $error->get_error_code() : 'attachment_not_supported';
					}
				}
				$url           = $id ? (string) wp_get_attachment_url( $id ) : self::source_url( $entry );
				$done[ $path ] = array(
					'attachment_id' => $id,
					'reason'        => $reason,
					'url'           => $url,
				);
				if ( $id ) {
					$bound[ self::source_url( $entry ) ] = array(
						'url' => $url,
						'id'  => $id,
					);
				}
			}
			if ( $bound ) {
				$rewritten = self::rewrite_posts( $journal['posts'], $bound );
				if ( is_wp_error( $rewritten ) ) {
					return $rewritten;
				}
			}
			$journal['entries'] = array_merge( $journal['entries'], $done );
			update_option( $ref, $journal, false );
			return self::receipt( $ref, $journal, $queue );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release lock handle.
		}
	}

	/** The URL a queue entry's references take until its file is imported. */
	private static function source_url( array $entry ): string {
		foreach ( array( 'originalUrl', 'url' ) as $key ) {
			if ( is_string( $entry[ $key ] ?? null ) && preg_match( '#^https?://#i', $entry[ $key ] ) ) {
				return $entry[ $key ];
			}
		}
		return '';
	}

	/**
	 * Rewrite a batch's source URLs to attachment URLs in the imported posts.
	 *
	 * @param array<int,int>                         $post_ids Posts the content import created.
	 * @param array<string,array{url:string,id:int}> $bound    Attachments by source URL.
	 * @return true|WP_Error
	 */
	private static function rewrite_posts( array $post_ids, array $bound ) {
		$urls = array_combine( array_keys( $bound ), array_column( $bound, 'url' ) );
		$ids  = array_combine( array_column( $bound, 'url' ), array_column( $bound, 'id' ) );
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || strtr( $post->post_content, $urls ) === $post->post_content ) {
				continue;
			}
			$updated = serialize_blocks( self::rewrite_blocks( parse_blocks( $post->post_content ), $urls, $ids ) );
			$result  = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $updated,
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Rewrite markup and nested attributes together, binding core image IDs.
	 *
	 * @param array<array-key,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<string,string>           $urls   Attachment URL by source URL.
	 * @param array<string,int>              $ids    Attachment ID by attachment URL.
	 */
	private static function rewrite_blocks( array $blocks, array $urls, array $ids ): array {
		$replace = static function ( $value ) use ( &$replace, $urls ) {
			if ( is_array( $value ) ) {
				return array_map( $replace, $value );
			}
			return is_string( $value ) ? strtr( $value, $urls ) : $value;
		};
		foreach ( $blocks as &$block ) {
			$block['attrs']        = $replace( $block['attrs'] );
			$block['innerHTML']    = $replace( $block['innerHTML'] );
			$block['innerContent'] = $replace( $block['innerContent'] );
			$block['innerBlocks']  = self::rewrite_blocks( $block['innerBlocks'], $urls, $ids );
			if ( 'core/image' === $block['blockName'] ) {
				$tags = new WP_HTML_Tag_Processor( (string) $block['innerHTML'] );
				$src  = $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ? (string) $tags->get_attribute( 'src' ) : '';
				if ( isset( $ids[ $src ] ) ) {
					$block['attrs']['id']  = $ids[ $src ];
					$block['innerHTML']    = self::bind_image_class( $block['innerHTML'], $ids[ $src ] );
					$block['innerContent'] = array_map( static fn( $part ) => is_string( $part ) ? self::bind_image_class( $part, $ids[ $src ] ) : $part, $block['innerContent'] );
				}
			}
		}
		return $blocks;
	}

	/** Give an image block's img the attachment identity class core/image saves. */
	private static function bind_image_class( string $html, int $attachment_id ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $html;
		}
		$classes = preg_split( '/\s+/', (string) $tags->get_attribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( preg_match( '/^wp-image-\d+$/', $class ) ) {
				$tags->remove_class( $class );
			}
		}
		$tags->add_class( 'wp-image-' . $attachment_id );
		return $tags->get_updated_html();
	}

	/**
	 * Project queue progress and per-path failures into a receipt media block.
	 *
	 * @param array<string,array<string,mixed>> $queue Queue entries keyed by deferred path.
	 */
	private static function receipt( string $ref, array $journal, array $queue ): array {
		return array(
			'ref'      => $ref,
			'pending'  => count( array_diff_key( $queue, $journal['entries'] ) ),
			'imported' => count( array_filter( $journal['entries'], static fn( array $entry ): bool => $entry['attachment_id'] > 0 ) ),
			'failed'   => array_filter( $journal['entries'], static fn( array $entry ): bool => '' !== $entry['reason'] ),
		);
	}
}
