<?php
/**
 * Media Library ownership for imported page images.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Moves page-owned raster images into the Media Library.
 *
 * The site plan resolves every image to a file the generated theme ships, so
 * after an import an owner sees an empty Media Library and image blocks with
 * no attachment. Each core/image block in materialized page content whose
 * source is a raster file inside the generated theme becomes an attachment
 * (one per source file), and the block is bound to it the way the editor
 * binds an uploaded image: `id` attribute, `wp-image-{id}` class, attachment
 * URL. Theme chrome (template parts), SVG icons, and CSS backgrounds stay
 * theme-owned design assets.
 */
final class Static_Site_Importer_Media_Library_Materializer {

	public const SOURCE_ASSET_META_KEY = '_static_site_importer_source_asset';

	private const RASTER_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	/**
	 * Bind page image blocks to Media Library attachments.
	 *
	 * @param array<mixed> $state Materialization transaction state.
	 * @return array{attachment_count:int,replaceable_media_count:int,bound_block_count:int,site_icon?:int}|WP_Error
	 */
	public static function materialize( array &$state ) {
		$theme_uri = rtrim( (string) ( $state['theme']['uri'] ?? '' ), '/' );
		$theme_dir = rtrim( (string) ( $state['theme_dir'] ?? '' ), '/' );
		$report    = array(
			'attachment_count'        => 0,
			'replaceable_media_count' => 0,
			'bound_block_count'       => 0,
		);
		if ( '' === $theme_uri || '' === $theme_dir || ! function_exists( 'parse_blocks' ) || ! function_exists( 'wp_insert_attachment' ) ) {
			return $report;
		}

		$attachments = array();
		foreach ( $state['ordered_pages'] ?? array() as $page ) {
			if ( ! empty( $page['skip_materialization'] ) ) {
				continue;
			}
			$source_path = (string) ( $page['source_path'] ?? '' );
			$post_id     = (int) ( $state['source_ids'][ $source_path ] ?? 0 );
			$content     = $post_id > 0 ? get_post_field( 'post_content', $post_id ) : null;
			if ( ! is_string( $content ) || ! str_contains( $content, '<!-- wp:image' ) ) {
				continue;
			}

			// Edit only each image block in place. Re-serializing the whole
			// page would re-encode unrelated blocks (a provider form, for one)
			// and break fragment identities recorded earlier in this import.
			$bound     = 0;
			$error     = null;
			$rewritten = '';
			$offset    = 0;
			// A plain loop, not a callback: the match handler writes the
			// transaction's attachment journal, which must be $state itself.
			if ( preg_match_all( '/<!--\s+wp:image(\s+\{.*?\})?\s+-->(.*?)<!--\s+\/wp:image\s+-->/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $found ) {
					$match      = array( $found[0][0], $found[1][0], $found[2][0] );
					$rewritten .= substr( $content, $offset, $found[0][1] - $offset );
					$rewritten .= null === $error ? self::bind_image_block( $match, $theme_uri, $theme_dir, $state, $attachments, $report, $bound, $error ) : $match[0];
					$offset     = $found[0][1] + strlen( $found[0][0] );
				}
			}
			$rewritten .= substr( $content, $offset );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
			if ( 0 === $bound ) {
				continue;
			}
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $rewritten ),
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$provenance = json_decode( (string) get_post_meta( $post_id, '_static_site_importer_provenance', true ), true );
			if ( is_array( $provenance ) ) {
				$provenance['content_hash'] = hash( 'sha256', $rewritten );
				Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $post_id, '_static_site_importer_provenance', (string) wp_json_encode( $provenance ) );
			}
			foreach ( $state['applied']['runtime_declarations']['entity_bindings'] ?? array() as $index => $binding_report ) {
				if ( ( $binding_report['source_path'] ?? '' ) === $source_path && 'completed' === ( $binding_report['status'] ?? '' ) ) {
					$state['applied']['runtime_declarations']['entity_bindings'][ $index ]['materialized_content_hash'] = hash( 'sha256', $rewritten );
				}
			}
			foreach ( $state['resolved']['pages'] as &$resolved_page ) {
				if ( ( $resolved_page['source_path'] ?? '' ) === $source_path ) {
					$resolved_page['materialized_block_markup'] = $rewritten;
					break;
				}
			}
			unset( $resolved_page );
			$report['bound_block_count'] += $bound;
		}
		$error               = null;
		$report['site_icon'] = self::materialize_site_icon( $state, $theme_uri, $theme_dir, $attachments, $error );
		if ( $error instanceof WP_Error ) {
			return $error;
		}
		$report['attachment_count'] = count( array_filter( $attachments ) );

		return $report;
	}

	/**
	 * Make the source favicon the WordPress site icon, so the owner sees and can
	 * change it under Site Identity. An owner's existing icon is never replaced.
	 *
	 * @param array<mixed>      $state
	 * @param array<string,int> $attachments
	 */
	private static function materialize_site_icon( array &$state, string $theme_uri, string $theme_dir, array &$attachments, ?WP_Error &$error ): int {
		if ( (int) get_option( 'site_icon', 0 ) > 0 ) {
			return 0;
		}
		$pages = $state['resolved']['pages'] ?? array();
		usort( $pages, static fn( $left, $right ): int => (int) empty( $left['entrypoint'] ) <=> (int) empty( $right['entrypoint'] ) );
		foreach ( array( 'icon', 'apple-touch-icon' ) as $wanted ) {
			foreach ( $pages as $page ) {
				foreach ( ( is_array( $page['document_metadata']['links'] ?? null ) ? $page['document_metadata']['links'] : array() ) as $link ) {
					$rels = preg_split( '/\s+/', strtolower( trim( (string) ( $link['rel'] ?? '' ) ) ) );
					$rels = is_array( $rels ) ? $rels : array();
					$url  = (string) ( $link['resolved_url'] ?? '' );
					if ( ! in_array( $wanted, $rels, true ) || '' === $url ) {
						continue;
					}
					$relative = self::theme_relative_raster( $url, $theme_uri );
					if ( null === $relative ) {
						continue;
					}
					if ( ! array_key_exists( $relative, $attachments ) ) {
						$file                     = $theme_dir . '/' . $relative;
						$identity                 = basename( $theme_dir ) . '/' . $relative . '#' . ( is_readable( $file ) ? (string) hash_file( 'sha256', $file ) : '' );
						$attachments[ $relative ] = self::attachment_for( $file, $relative, $identity, 'Site icon', $state, $error );
						if ( null !== $error ) {
							return 0;
						}
					}
					if ( $attachments[ $relative ] > 0 ) {
						// Journal the prior value now; the runtime snapshot runs later.
						if ( ! isset( $state['rollback']['options']['site_icon'] ) ) {
							$state['rollback']['options']['site_icon'] = array(
								'exists' => false !== get_option( 'site_icon', false ),
								'value'  => get_option( 'site_icon', 0 ),
							);
						}
						update_option( 'site_icon', $attachments[ $relative ] );
						return $attachments[ $relative ];
					}
				}
			}
		}
		return 0;
	}

	/**
	 * Bind one serialized core/image block to its attachment.
	 *
	 * @param array<int,string>   $block_match       Regex match: whole block, attribute JSON, inner HTML.
	 * @param array<mixed>        $state
	 * @param array<string,int>   $attachments Attachment ID per theme-relative source (0 when not bindable).
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 */
	private static function bind_image_block( array $block_match, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$report, int &$bound, ?WP_Error &$error ): string {
		$attrs = '' !== trim( (string) ( $block_match[1] ?? '' ) ) ? json_decode( trim( $block_match[1] ), true ) : array();
		if ( ! is_array( $attrs ) || ! empty( $attrs['id'] ) || str_contains( $block_match[2], '<!-- wp:' ) ) {
			return $block_match[0];
		}
		if ( ! preg_match( '/<img\b[^>]*\bsrc="([^"]+)"/i', $block_match[2], $src_match ) ) {
			return $block_match[0];
		}
		$relative = self::theme_relative_raster( html_entity_decode( $src_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $theme_uri );
		if ( null === $relative ) {
			return $block_match[0];
		}
		++$report['replaceable_media_count'];
		if ( ! array_key_exists( $relative, $attachments ) ) {
			$alt = preg_match( '/<img\b[^>]*\balt="([^"]*)"/i', $block_match[2], $alt_match ) ? html_entity_decode( $alt_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			// Keyed by theme and file content, so a later import of different
			// bytes, or another theme, never reuses this attachment.
			$file                     = $theme_dir . '/' . $relative;
			$identity                 = basename( $theme_dir ) . '/' . $relative . '#' . ( is_readable( $file ) ? (string) hash_file( 'sha256', $file ) : '' );
			$attachments[ $relative ] = self::attachment_for( $file, $relative, $identity, $alt, $state, $error );
			if ( null !== $error ) {
				return $block_match[0];
			}
		}
		$attachment_id = $attachments[ $relative ];
		$url           = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : false;
		if ( ! is_string( $url ) || '' === $url ) {
			return $block_match[0];
		}

		$attrs['id'] = $attachment_id;
		$inner       = (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( array $img ) use ( $src_match, $url, $attachment_id ): string {
				$tag = str_replace( 'src="' . $src_match[1] . '"', 'src="' . esc_url( $url ) . '"', $img[0] );
				if ( preg_match( '/\bclass="[^"]*"/i', $tag ) ) {
					return (string) preg_replace( '/\bclass="([^"]*)"/i', 'class="$1 wp-image-' . $attachment_id . '"', $tag, 1 );
				}
				return (string) preg_replace( '/^<img\b/i', '<img class="wp-image-' . $attachment_id . '"', $tag, 1 );
			},
			$block_match[2],
			1
		);
		++$bound;

		return '<!-- wp:image ' . serialize_block_attributes( $attrs ) . ' -->' . $inner . '<!-- /wp:image -->';
	}

	/** Theme-relative path of a raster file the generated theme serves, or null. */
	private static function theme_relative_raster( string $src, string $theme_uri ): ?string {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$base = (string) wp_parse_url( $theme_uri, PHP_URL_PATH );
		if ( '' === $path || '' === $base || ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}
		$relative = rawurldecode( substr( $path, strlen( $base ) + 1 ) );
		if ( str_contains( $relative, '..' ) || ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::RASTER_EXTENSIONS, true ) ) {
			return null;
		}
		return $relative;
	}

	/** Create (or reuse) the attachment for one theme-relative image file. */
	private static function attachment_for( string $file, string $relative, string $identity, string $alt, array &$state, ?WP_Error &$error ): int {
		$existing = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'inherit',
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => self::SOURCE_ASSET_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per distinct import image.
				'meta_value'    => $identity, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup per distinct import image.
			)
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file written by this import.
		if ( false === $bytes ) {
			return 0;
		}
		$upload = wp_upload_bits( sanitize_file_name( basename( $relative ) ), null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			$error = new WP_Error( 'media_library_upload_failed', 'An imported page image could not be added to the Media Library.', array( 'source_asset' => $relative ) );
			return 0;
		}
		$mime = (string) wp_check_filetype( $upload['file'] )['type'];
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		$title         = '' !== trim( $alt ) ? trim( $alt ) : pathinfo( $relative, PATHINFO_FILENAME );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			$error = new WP_Error( 'media_library_attachment_failed', 'An imported page image could not be registered as an attachment.', array( 'source_asset' => $relative ) );
			return 0;
		}
		// Journaled apart from pages: receipts and asset scoping read applied
		// posts as page content. Rollback deletes these with their files.
		$state['applied']['attachments'][] = (int) $attachment_id;

		foreach ( array( 'wp-admin/includes/image.php', 'wp-admin/includes/media.php', 'wp-admin/includes/file.php' ) as $include ) {
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) && is_readable( ABSPATH . $include ) ) {
				require_once ABSPATH . $include;
			}
		}
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		}
		update_post_meta( $attachment_id, self::SOURCE_ASSET_META_KEY, $identity );
		if ( '' !== trim( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		}

		return (int) $attachment_id;
	}
}
