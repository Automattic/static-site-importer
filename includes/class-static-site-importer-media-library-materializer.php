<?php
/**
 * Media Library ownership for imported page images.
 *
 * @package StaticSiteImporter
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-static-site-importer-ico-asset.php';

/**
 * Moves plan-owned media into the Media Library; capture-owned fonts stay
 * theme-owned and are verified before the theme package is written.
 *
 * The site plan resolves every image to a file the generated theme ships, so
 * after an import an owner sees an empty Media Library and image blocks with
 * no attachment. Raster files referenced by materialized page content — a
 * core/image, core/cover, or core/media-text block, or an img inside another
 * block's saved markup — become attachments. Identical bytes share one
 * attachment for legacy plans; capture records instead own resource identity,
 * one attachment per recorded image, document or video. Native blocks are
 * bound the way the editor binds an uploaded image: media id,
 * `wp-image-{id}` class, attachment URL. Theme chrome (template parts), SVG
 * icons, and CSS backgrounds stay theme-owned design assets; recorded fonts
 * stay theme-owned because theme.json fontFace owns them.
 */
final class Static_Site_Importer_Media_Library_Materializer {

	public const SOURCE_ASSET_META_KEY  = '_static_site_importer_source_asset';
	public const SOURCE_RECORD_META_KEY = '_static_site_importer_asset_record';

	private const RASTER_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	/** Portable upload extension for each verified non-image content type. */
	private const RESOURCE_CONTENT_TYPE_EXTENSIONS = array(
		'font/woff2'                  => 'woff2',
		'font/woff'                   => 'woff',
		'font/ttf'                    => 'ttf',
		'font/otf'                    => 'otf',
		'application/pdf'             => 'pdf',
		'application/zip'             => 'zip',
		'application/gzip'            => 'gz',
		'application/x-7z-compressed' => '7z',
		'application/rtf'             => 'rtf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'video/mp4'                   => 'mp4',
		'video/webm'                  => 'webm',
		'video/ogg'                   => 'ogv',
	);

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
		$by_hash     = array();
		foreach ( $state['ordered_pages'] ?? array() as $page ) {
			if ( ! empty( $page['skip_materialization'] ) && empty( $page['provider_owned'] ) ) {
				continue;
			}
			$source_path = (string) ( $page['source_path'] ?? '' );
			$post_id     = (int) ( $state['source_ids'][ $source_path ] ?? 0 );
			$content     = Static_Site_Importer_Site_Plan_Persistence::served_document( $state, $page );
			// An explicit provider claim wins; otherwise the site plan's
			// featured-image handoff names the theme asset and its alt text.
			$featured_image = is_array( $page['metadata']['featured_image'] ?? null ) ? $page['metadata']['featured_image'] : array();
			$featured_path  = (string) ( $page['provider_featured_image_target_path'] ?? '' );
			$featured_alt   = '';
			if ( '' === $featured_path && is_string( $featured_image['target_path'] ?? null ) ) {
				$featured_path = $featured_image['target_path'];
				$featured_alt  = is_string( $featured_image['alt'] ?? null ) ? $featured_image['alt'] : '';
			}
			$social_id = 0;
			if ( '' === $featured_path ) {
				foreach ( Static_Site_Importer_Route_Head_Metadata::from_page( $page, $state['resolved'] ) as $tag ) {
					if ( ! in_array( $tag['key'], array( 'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src' ), true ) ) {
						continue;
					}
					$social_id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $tag['content'] ) : 0;
					if ( $social_id > 0 ) {
						break;
					}
					$relative = self::theme_relative_asset( $tag['content'], $theme_uri, array_merge( self::RASTER_EXTENSIONS, array( 'svg' ) ) );
					if ( null !== $relative ) {
						foreach ( $state['plan']['assets'] ?? array() as $asset ) {
							if ( ( $asset['target_path'] ?? '' ) === $relative ) {
								$social_id = (int) ( $state['resolved']['resolution']['attachment_resolutions'][ $asset['token'] ]['id'] ?? 0 );
								break;
							}
						}
						if ( $social_id <= 0 ) {
							$featured_path = $relative;
						}
						break;
					}
					return new WP_Error( 'route_social_image_unresolved', 'The authored social image must resolve to an imported Media Library asset.' );
				}
			}
			if ( $social_id > 0 ) {
				set_post_thumbnail( $post_id, $social_id );
				if ( (int) get_post_thumbnail_id( $post_id ) !== $social_id ) {
					return new WP_Error( 'route_social_image_write_failed' );
				}
			}
			if ( ! is_string( $content ) || ( ! self::content_references_media( $content ) && '' === $featured_path ) ) {
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
					$rewritten .= null === $error ? self::bind_image_block( $match, $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $error ) : $match[0];
					$offset     = $found[0][1] + strlen( $found[0][0] );
				}
			}
			$rewritten .= substr( $content, $offset );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
			$rewritten = self::bind_referenced_images( $rewritten, $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $error );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
			if ( '' !== $featured_path ) {
				// Plan-owned media already has an attachment; explicit provider
				// paths still take precedence and resolve from the theme.
				$token    = (string) ( $featured_image['asset_token'] ?? '' );
				$featured = empty( $page['provider_featured_image_target_path'] ) ? (int) ( $state['resolved']['resolution']['attachment_resolutions'][ $token ]['id'] ?? 0 ) : 0;
				if ( $featured <= 0 ) {
					$relative = self::theme_relative_asset( $theme_uri . '/' . $featured_path, $theme_uri, array_merge( self::RASTER_EXTENSIONS, array( 'svg' ) ) );
					$featured = null === $relative ? 0 : self::ensure_attachment( $theme_dir, $relative, $featured_alt, $state, $attachments, $by_hash, $error );
				}
				if ( null !== $error || $featured <= 0 ) {
					return $error ?? new WP_Error( 'provider_featured_image_unresolved', 'The provider source image could not be materialized.' );
				}
				set_post_thumbnail( $post_id, $featured );
				if ( (int) get_post_thumbnail_id( $post_id ) !== $featured ) {
					return new WP_Error( 'provider_featured_image_write_failed', 'The native provider image was not persisted.' );
				}
			}
			if ( $rewritten === $content ) {
				continue;
			}
			$updated = Static_Site_Importer_Site_Plan_Persistence::write_served_document( $state, $page, $rewritten );
			if ( is_wp_error( $updated ) ) {
				return $updated;
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
		$error = null;
		// Explicit branding is resolved together after activation. Keep the early
		// raster fallback only for legacy plans without native icon evidence.
		$report['site_icon'] = empty( $state['args']['native_site_identity_evidence']['icon'] ) ? self::materialize_site_icon( $state, $theme_uri, $theme_dir, $attachments, $by_hash, $error ) : 0;
		if ( $error instanceof WP_Error ) {
			return $error;
		}
		$report['attachment_count'] = count( array_unique( array_filter( $attachments ) ) );

		return $report;
	}

	/** Apply explicit branding through native WordPress settings after activation. */
	public static function materialize_identity( array &$state ) {
		if ( empty( $state['args']['activate'] ) || Static_Site_Importer_Import_Destination::EXISTING_THEME === ( $state['args']['destination'] ?? '' ) ) {
			return array( 'status' => 'not_applied' );
		}
		$evidence    = is_array( $state['args']['native_site_identity_evidence'] ?? null ) ? $state['args']['native_site_identity_evidence'] : array();
		$theme_uri   = (string) ( $state['theme']['uri'] ?? '' );
		$theme_dir   = (string) ( $state['theme_dir'] ?? '' );
		$attachments = array();
		$by_hash     = array();
		$report      = array();
		foreach ( array(
			'logo' => 'site_logo',
			'icon' => 'site_icon',
		) as $field => $option ) {
			$current = (int) get_option( $option, 0 );
			$source  = $evidence[ $field . '_source_path' ] ?? null;
			$entry   = array(
				'status'        => 'absent',
				'attachment_id' => 0,
				'source_path'   => $source,
			);
			if ( ! empty( $evidence['truncated'] ) || ! empty( $evidence['invalid_jsonld'] ) ) {
				$entry['status'] = 'unknown_evidence';
			}
			if ( 'icon' === $field && isset( $evidence['manifest_status'] ) && 'parsed' !== $evidence['manifest_status'] ) {
				$entry['status'] = 'unresolved_manifest';
			}
			if ( 'logo' === $field && isset( $evidence['logo_status'] ) ) {
				$entry['status'] = $evidence['logo_status'];
			}
			if ( $current > 0 ) {
				$entry['status']        = 'preserved_owner_value';
				$entry['attachment_id'] = $current;
				$report[ $option ]      = $entry;
				continue;
			}
			$id = 'icon' === $field && empty( $evidence['icon'] ) ? (int) ( $state['pending_site_icon'] ?? 0 ) : 0;
			if ( $id <= 0 && is_string( $source ) ) {
				foreach ( $state['plan']['assets'] ?? array() as $asset ) {
					if ( ( $asset['source_path'] ?? null ) === $source ) {
						$id = (int) ( $state['resolved']['resolution']['attachment_resolutions'][ $asset['token'] ]['id'] ?? 0 );
						break;
					}
				}
			}
			if ( $id <= 0 && ! empty( $evidence[ $field ] ) ) {
				$write = null;
				foreach ( $state['resolved']['writes'] ?? array() as $candidate ) {
					if ( is_string( $source ) && ( $candidate['source_path'] ?? null ) === $source && 'theme_asset' === ( $candidate['kind'] ?? '' ) ) {
						$write = $candidate;
						break;
					}
				}
				if ( null === $write ) {
					$entry['status'] = 'unresolved_asset';
				} else {
					$url      = rtrim( $theme_uri, '/' ) . '/' . $write['target_path'];
					$relative = self::theme_relative_raster( $url, $theme_uri );
					$svg      = null;
					$ico      = null;
					if ( null === $relative && 'svg' === strtolower( pathinfo( $write['target_path'], PATHINFO_EXTENSION ) ) ) {
						$relative = self::theme_relative_asset( $url, $theme_uri, array( 'svg' ) );
						$svg      = null === $relative ? null : self::svg_asset_metadata( $theme_dir . '/' . $relative );
					}
					if ( null === $relative && 'ico' === strtolower( pathinfo( $write['target_path'], PATHINFO_EXTENSION ) ) ) {
						$relative = self::theme_relative_asset( $url, $theme_uri, array( 'ico' ) );
						$ico      = null === $relative ? null : self::ico_asset_metadata( $theme_dir . '/' . $relative );
					}
					if ( null === $relative ) {
						$entry['status'] = 'unsupported_format';
					} elseif ( null !== $svg && 'supported' !== $svg['status'] ) {
						$entry['status'] = $svg['status'];
					} elseif ( null !== $ico && 'supported' !== $ico['status'] ) {
						$entry['status'] = $ico['status'];
						$entry['reason'] = $ico['reason'];
					} elseif ( ! function_exists( 'wp_insert_attachment' ) ) {
						$entry['status'] = 'runtime_unavailable';
					} else {
						$error = null;
						$id    = self::ensure_attachment( $theme_dir, $relative, 'Site ' . $field, $state, $attachments, $by_hash, $error );
						if ( null !== $error ) {
							return $error;
						}
						if ( $id <= 0 ) {
							$entry['status'] = 'unresolved_asset';
						}
					}
				}
			}
			if ( $id > 0 ) {
				Static_Site_Importer_Site_Plan_Persistence::journal_option( $state, $option );
				if ( ! Static_Site_Importer_Site_Plan_Persistence::write_option( $option, $id ) ) {
					// ssi-error-class: operational
					return new WP_Error( 'native_' . $option . '_not_applied' );
				}
				$entry['status']        = 'applied';
				$entry['attachment_id'] = $id;
			}
			$report[ $option ] = $entry;
		}
		$tagline = trim( (string) ( $state['args']['site_tagline'] ?? $evidence['tagline'] ?? '' ) );
		if ( '' === $tagline ) {
			$tagline = trim( (string) ( $evidence['tagline'] ?? '' ) );
		}
		$report['blogdescription'] = array( 'status' => '' === $tagline ? 'absent' : 'preserved_owner_value' );
		if ( '' !== $tagline && '' === trim( (string) get_option( 'blogdescription', '' ) ) ) {
			Static_Site_Importer_Site_Plan_Persistence::journal_option( $state, 'blogdescription' );
			if ( ! Static_Site_Importer_Site_Plan_Persistence::write_option( 'blogdescription', sanitize_text_field( $tagline ) ) ) {
				return new WP_Error( 'site_tagline_not_applied' );
			}
			$report['blogdescription']['status'] = 'applied';
		}
		return $report;
	}

	/**
	 * Make the source favicon the WordPress site icon, so the owner sees and can
	 * change it under Site Identity. An owner's existing icon is never replaced.
	 *
	 * @param array<mixed>      $state
	 * @param array<string,int> $attachments
	 * @param array<string,int> $by_hash     Attachment ID per content hash.
	 */
	private static function materialize_site_icon( array &$state, string $theme_uri, string $theme_dir, array &$attachments, array &$by_hash, ?WP_Error &$error ): int {
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
					foreach ( $state['resolved']['resolution']['attachment_resolutions'] ?? array() as $attachment ) {
						if ( $url === $attachment['url'] ) {
							$state['pending_site_icon'] = $attachment['id'];
							return $attachment['id'];
						}
					}
					$relative = self::theme_relative_raster( $url, $theme_uri );
					if ( null === $relative ) {
						continue;
					}
					if ( ! array_key_exists( $relative, $attachments ) ) {
						self::ensure_attachment( $theme_dir, $relative, 'Site icon', $state, $attachments, $by_hash, $error );
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
						// Attachment creation may precede activation, but global Site
						// Identity is only changed in the authorized activation transaction.
						$state['pending_site_icon'] = $attachments[ $relative ];
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
	 * @param array<int,string>                                                             $block_match       Regex match: whole block, attribute JSON, inner HTML.
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments Attachment ID per theme-relative source (0 when not bindable).
	 * @param array<string,int>                                                             $by_hash     Attachment ID per content hash.
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 */
	private static function bind_image_block( array $block_match, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, int &$bound, ?WP_Error &$error ): string {
		$attrs = '' !== trim( (string) ( $block_match[1] ?? '' ) ) ? json_decode( trim( $block_match[1] ), true ) : array();
		if ( ! is_array( $attrs ) || str_contains( $block_match[2], '<!-- wp:' ) ) {
			return $block_match[0];
		}
		$tags = new WP_HTML_Tag_Processor( $block_match[2] );
		if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $block_match[0];
		}
		// An authored candidate family describes these exact bytes. The block
		// carries it in metadata because core/image saves no srcset or sizes.
		$src       = (string) $tags->get_attribute( 'src' );
		$selection = $attrs['metadata']['blocksEngineImageSelection'] ?? null;
		$authored  = is_array( $selection ) && is_string( $selection['srcset'] ?? null ) && '' !== $selection['srcset'] && ( $selection['src'] ?? null ) === $src;
		// Captured IDs belong to the source site, even when the number happens
		// to exist here. Only the generated theme asset establishes ownership.
		$attachment_id = self::attachment_id_for_url( $src, (string) $tags->get_attribute( 'alt' ), $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $error );
		if ( null !== $error ) {
			return $block_match[0];
		}
		$url = $attachment_id > 0 ? ( $authored ? wp_get_original_image_url( $attachment_id ) : wp_get_attachment_url( $attachment_id ) ) : false;
		if ( ! is_string( $url ) || '' === $url ) {
			return $block_match[0];
		}
		if ( $authored ) {
			$srcset = self::rewrite_srcset( $selection['srcset'], $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $error );
			if ( null !== $error ) {
				return $block_match[0];
			}
			// The selection stays keyed to the image it describes, now bound.
			$attrs['metadata']['blocksEngineImageSelection'] = array_merge(
				$selection,
				array(
					'src'    => $url,
					'srcset' => $srcset,
				)
			);
		}

		$attrs['id'] = $attachment_id;
		if ( isset( $attrs['url'] ) ) {
			$attrs['url'] = $url;
		}
		$tags->set_attribute( 'src', $url );
		// Change the img identity alone. Figure/className presentation and its
		// source CSS selectors, including source wp-image classes, stay intact.
		$classes = preg_split( '/\s+/', (string) $tags->get_attribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( preg_match( '/^wp-image-\d+$/', $class ) ) {
				$tags->remove_class( $class );
			}
		}
		$tags->add_class( 'wp-image-' . $attachment_id );
		$inner = $tags->get_updated_html();
		++$bound;

		return '<!-- wp:image ' . serialize_block_attributes( $attrs ) . ' -->' . $inner . '<!-- /wp:image -->';
	}

	/** Theme-relative path of a raster file the generated theme serves, or null. */
	private static function theme_relative_raster( string $src, string $theme_uri ): ?string {
		return self::theme_relative_asset( $src, $theme_uri, self::RASTER_EXTENSIONS );
	}

	/** Resolve only the explicitly supported formats within the generated theme. */
	private static function theme_relative_asset( string $src, string $theme_uri, array $extensions ): ?string {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$base = (string) wp_parse_url( $theme_uri, PHP_URL_PATH );
		if ( '' === $path || '' === $base || ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}
		$relative = rawurldecode( substr( $path, strlen( $base ) + 1 ) );
		if ( str_contains( $relative, '..' ) || ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), $extensions, true ) ) {
			return null;
		}
		return $relative;
	}

	/**
	 * Create (or reuse) one attachment for identical file bytes in this theme.
	 *
	 * @param array<mixed>      $state
	 * @param array<string,int> $attachments Attachment ID per theme-relative source (0 when not bindable).
	 * @param array<string,int> $by_hash     Attachment ID per content hash.
	 */
	private static function ensure_attachment( string $theme_dir, string $relative, string $alt, array &$state, array &$attachments, array &$by_hash, ?WP_Error &$error ): int {
		return self::attachment_from_source( $theme_dir, $relative, $alt, $state, $attachments, $by_hash, $error );
	}

	/**
	 * Shared content-addressed attachment creation for staged bytes and theme fallbacks.
	 *
	 * @param array<mixed>      $state Transaction state.
	 * @param array<string,int> $attachments Attachment IDs by source path.
	 * @param array<string,int> $by_hash Attachment IDs by content hash.
	 */
	private static function attachment_from_source( string $theme_dir, string $relative, string $alt, array &$state, array &$attachments, array &$by_hash, ?WP_Error &$error ): int {
		if ( array_key_exists( $relative, $attachments ) ) {
			return $attachments[ $relative ];
		}
		$file   = $theme_dir . '/' . $relative;
		$record = $state['staged_resource_records'][ $relative ] ?? null;
		if ( is_array( $record ) ) {
			require_once __DIR__ . '/class-static-site-importer-image-assets.php';
			if ( ! Static_Site_Importer_Image_Assets::verify( $file, $record ) ) {
				$error = new WP_Error( 'image_resource_unresolved', 'The staged resource does not match its capture record.' );
				return 0;
			}
			$identity                 = basename( $theme_dir ) . '#resource#' . hash( 'sha256', $record['original']['source_url'] . '#' . $record['original']['sha256'] );
			$attachments[ $relative ] = self::attachment_for( $file, $relative, $identity, $alt, $state, $error, $record );
			return $attachments[ $relative ];
		}
		$hash = is_readable( $file ) ? (string) hash_file( 'sha256', $file ) : '';
		if ( '' !== $hash && isset( $by_hash[ $hash ] ) ) {
			$attachments[ $relative ] = $by_hash[ $hash ];
			return $by_hash[ $hash ];
		}
		// Keyed by theme and file content, so a later import of different bytes,
		// or another theme, never reuses this attachment. The same bytes at two
		// paths share one attachment.
		$identity                 = basename( $theme_dir ) . '#' . $hash;
		$attachments[ $relative ] = '' === $hash ? 0 : self::attachment_for( $file, $relative, $identity, $alt, $state, $error );
		if ( $attachments[ $relative ] > 0 ) {
			$by_hash[ $hash ] = $attachments[ $relative ];
		}
		return $attachments[ $relative ];
	}

	/**
	 * Upload plan-routed media from verified compiled bytes, never from the theme.
	 * Capture records own resource identity; legacy plans keep exact captured
	 * bytes. WordPress owns responsive sizes, never URL-shaped sibling guesses.
	 *
	 * @return array|WP_Error Token resolutions or an upload failure.
	 */
	public static function resolve_plan_assets( array &$state ) {
		require_once __DIR__ . '/class-static-site-importer-image-assets.php';
		$by_source = array_column( $state['plan']['assets'] ?? array(), null, 'source_path' );
		foreach ( $state['args']['image_asset_records'] ?? array() as $source => $record ) {
			if ( Static_Site_Importer_Image_Assets::plan_destination( $record, $by_source[ $source ] ?? array() ) !== ( $by_source[ $source ]['destination'] ?? '' ) ) {
				return new WP_Error( 'image_resource_unresolved', 'The compiler must declare each recorded resource its consumer-owned destination.', array( 'source_path' => $source ) );
			}
		}
		$assets = array_filter( $state['plan']['assets'] ?? array(), static fn( array $asset ): bool => 'media_library' === ( $asset['destination'] ?? '' ) );
		if ( 'blocks-engine/wordpress-site-plan/v3' !== ( $state['plan']['schema'] ?? '' ) || empty( $assets ) || ! function_exists( 'wp_insert_attachment' ) ) {
			return array();
		}
		$root  = get_temp_dir() . 'ssi-media-' . wp_generate_uuid4();
		$dir   = $root . '/' . basename( $state['theme_dir'] );
		$files = array();
		try {
			$writes = array_column( $state['resolved']['writes'], null, 'target_path' );
			foreach ( $state['plan']['assets'] as $asset ) {
				if ( 'media_library' !== ( $asset['destination'] ?? '' ) ) {
					continue;
				}
				$relative = $asset['target_path'];
				$write    = $writes[ $relative ] ?? null;
				if ( ! is_array( $write ) || ! Static_Site_Importer_Site_Plan_Persistence::safe_destination( $dir, $relative ) ) {
					return new WP_Error( 'media_library_source_missing' );
				}
				$record = $state['args']['image_asset_records'][ $asset['source_path'] ] ?? null;
				if ( is_array( $record ) ) {
					$state['staged_resource_records'][ $relative ] = $record;
				}
				$files[] = $dir . '/' . $relative;
				$result  = Static_Site_Importer_Site_Plan_Persistence::write_file( $dir, $write, $state['payload_reader'] ?? null );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$attachments = array();
			$by_hash     = array();
			$resolutions = array();
			$error       = null;
			foreach ( $assets as $asset ) {
				$alt       = '';
				$documents = array_merge( $state['resolved']['pages'], $state['resolved']['templates'], $state['resolved']['template_parts'], $state['resolved']['synced_patterns'] ?? array() );
				foreach ( $documents as $page ) {
					$markup = (string) ( $page['resolved_block_markup'] ?? '' );
					if ( preg_match_all( '/<img\\b[^>]*>/i', $markup, $tags ) ) {
						foreach ( $tags[0] as $tag ) {
							if ( str_contains( $tag, $asset['target_path'] ) ) {
								$alt = self::alt_from_tag( $tag );
								break 2;
							}
						}
					}
				}
				$id = self::ensure_attachment( $dir, $asset['target_path'], $alt, $state, $attachments, $by_hash, $error );
				if ( $error ) {
					return $error;
				}
				// Artwork WordPress cannot hold as an attachment (unproven SVG or
				// ICO, non-image bytes) keeps its unresolved theme destination.
				if ( ! $id ) {
					if ( isset( $state['staged_resource_records'][ $asset['target_path'] ] ) ) {
						return new WP_Error( 'image_resource_unresolved', 'The recorded original could not become its declared destination.' );
					}
					continue;
				}
				$resolutions[ $asset['token'] ] = array(
					'id'  => $id,
					'url' => wp_get_attachment_url( $id ),
				);
			}
			return $resolutions;
		} finally {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
				$parent = dirname( $file );
				while ( str_starts_with( $parent, $root ) && is_dir( $parent ) ) {
					if ( ! @rmdir( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only empty temporary staging directories.
						break;
					}
					$parent = dirname( $parent );
				}
			}
		}
	}

	/** Create (or reuse) the attachment for one theme-relative image file. */
	private static function attachment_for( string $file, string $relative, string $identity, string $alt, array &$state, ?WP_Error &$error, ?array $record = null ): int {
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
		if ( null !== $record && 'image' !== Static_Site_Importer_Image_Assets::kind( $record ) ) {
			return self::resource_attachment_for( $bytes, $relative, $identity, $state, $error, $record );
		}
		$svg = 'svg' === strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) ? self::svg_asset_metadata( $file ) : null;
		if ( null !== $svg && 'supported' !== $svg['status'] ) {
			return 0;
		}
		if ( 'ico' === strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) && 'supported' !== Static_Site_Importer_Ico_Asset::inspect( $bytes )['status'] ) {
			return 0;
		}
		// Admit only compiler-validated artwork during this synchronous upload.
		// Ordinary uploads keep WordPress's existing SVG MIME policy.
		$svg_mime = static function ( array $mimes ): array {
			$mimes['svg'] = 'image/svg+xml';
			return $mimes;
		};
		if ( null !== $svg ) {
			add_filter( 'upload_mimes', $svg_mime );
		}
		$filename = basename( $relative );
		if ( null !== $record ) {
			$extensions = array(
				'image/png'     => 'png',
				'image/jpeg'    => 'jpg',
				'image/webp'    => 'webp',
				'image/gif'     => 'gif',
				'image/avif'    => 'avif',
				'image/svg+xml' => 'svg',
			);
			$stem       = pathinfo( rawurldecode( (string) wp_parse_url( $record['original']['source_url'], PHP_URL_PATH ) ), PATHINFO_FILENAME );
			$filename   = ( '' !== trim( $alt ) ? sanitize_title( $alt ) : sanitize_title( $stem ) ) . '.' . ( $extensions[ $record['original']['content_type'] ] ?? pathinfo( $relative, PATHINFO_EXTENSION ) );
		}
		try {
			$upload = wp_upload_bits( sanitize_file_name( $filename ), null, $bytes );
		} finally {
			if ( null !== $svg ) {
				remove_filter( 'upload_mimes', $svg_mime );
			}
		}
		if ( ! empty( $upload['error'] ) ) {
			$error = new WP_Error( 'media_library_upload_failed', 'An imported page image could not be added to the Media Library.', array( 'source_asset' => $relative ) );
			return 0;
		}
		$mime = null !== $svg ? 'image/svg+xml' : (string) wp_check_filetype( $upload['file'] )['type'];
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		$title         = '' !== trim( $alt ) ? trim( $alt ) : str_replace( array( '-', '_' ), ' ', pathinfo( $filename, PATHINFO_FILENAME ) );
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
		if ( null !== $svg ) {
			wp_update_attachment_metadata(
				$attachment_id,
				array(
					'width'    => $svg['width'],
					'height'   => $svg['height'],
					'file'     => _wp_relative_upload_path( $upload['file'] ),
					'filesize' => strlen( $bytes ),
					'sizes'    => array(),
				)
			);
		} elseif ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			// Site-wide format filters must not turn a transparent capture into
			// opaque intermediate sizes. Core still owns sizing and metadata.
			$preserve_alpha = static function ( array $formats ) use ( $mime ): array {
				$formats[ $mime ] = $mime;
				return $formats;
			};
			$alpha          = ! empty( $record['original']['alpha'] );
			if ( $alpha ) {
				add_filter( 'image_editor_output_format', $preserve_alpha, PHP_INT_MAX );
			}
			try {
				wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
			} finally {
				if ( $alpha ) {
					remove_filter( 'image_editor_output_format', $preserve_alpha, PHP_INT_MAX );
				}
			}
		}
		update_post_meta( $attachment_id, self::SOURCE_ASSET_META_KEY, $identity );
		if ( null !== $record ) {
			update_post_meta( $attachment_id, self::SOURCE_RECORD_META_KEY, $record );
		}
		if ( '' !== trim( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * Upload one verified non-image capture record as a single Media Library
	 * attachment: documents and video are attachment-bound media.
	 *
	 * The staged bytes were already signature-verified against the record, so
	 * the record's content type is the upload MIME; only that one verified
	 * extension is admitted during this synchronous upload. No WordPress
	 * renditions exist for documents, archives or video, and captured fonts
	 * never arrive here: theme.json fontFace keeps them theme-owned.
	 *
	 * @param string              $bytes    Verified staged bytes.
	 * @param string              $relative Staged asset path (diagnostics).
	 * @param string              $identity Content- and source-scoped attachment identity.
	 * @param array<string,mixed> $state    Transaction state.
	 * @param WP_Error|null       $error    Set when the upload or insert fails.
	 * @param array<string,mixed> $record   Verified capture record.
	 */
	private static function resource_attachment_for( string $bytes, string $relative, string $identity, array &$state, ?WP_Error &$error, array $record ): int {
		$mime      = (string) ( $record['original']['content_type'] ?? '' );
		$extension = self::RESOURCE_CONTENT_TYPE_EXTENSIONS[ $mime ] ?? '';
		if ( '' === $mime || '' === $extension ) {
			// Verified bytes without a portable extension keep their unresolved
			// theme destination rather than an ambiguous attachment.
			return 0;
		}
		$stem = rawurldecode( (string) wp_parse_url( (string) ( $record['original']['source_url'] ?? '' ), PHP_URL_PATH ) );
		$stem = pathinfo( $stem, PATHINFO_FILENAME );
		$stem = '' !== trim( (string) $stem ) ? (string) $stem : pathinfo( $relative, PATHINFO_FILENAME );
		// Admit exactly the verified extension and MIME during this upload;
		// ordinary uploads keep WordPress's existing MIME policy.
		$admitted = static function ( array $mimes ) use ( $extension, $mime ): array {
			$mimes[ $extension ] = $mime;
			return $mimes;
		};
		add_filter( 'upload_mimes', $admitted );
		try {
			$upload = wp_upload_bits( sanitize_file_name( $stem . '.' . $extension ), null, $bytes );
		} finally {
			remove_filter( 'upload_mimes', $admitted );
		}
		if ( ! empty( $upload['error'] ) ) {
			$error = new WP_Error( 'media_library_upload_failed', 'An imported resource could not be added to the Media Library.', array( 'source_asset' => $relative ) );
			return 0;
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => trim( str_replace( array( '-', '_' ), ' ', $stem ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			$error = new WP_Error( 'media_library_attachment_failed', 'An imported resource could not be registered as an attachment.', array( 'source_asset' => $relative ) );
			return 0;
		}
		// Journaled apart from pages: receipts and asset scoping read applied
		// posts as page content. Rollback deletes these with their files.
		$state['applied']['attachments'][] = (int) $attachment_id;
		wp_update_attachment_metadata(
			(int) $attachment_id,
			array(
				'file'     => _wp_relative_upload_path( $upload['file'] ),
				'filesize' => strlen( $bytes ),
				'sizes'    => array(),
			)
		);
		update_post_meta( (int) $attachment_id, self::SOURCE_ASSET_META_KEY, $identity );
		update_post_meta( (int) $attachment_id, self::SOURCE_RECORD_META_KEY, $record );
		return (int) $attachment_id;
	}

	/**
	 * Verify theme-owned capture records against the exact bytes the plan is
	 * about to write: fonts and images without block consumers stay theme-owned;
	 * their digest and
	 * byte-sniffed MIME must match before any theme file lands.
	 *
	 * @param array<string,mixed> $state Transaction state after resolution.
	 * @return true|WP_Error
	 */
	public static function verify_record_theme_writes( array $state ) {
		$by_source = array_column( $state['plan']['assets'] ?? array(), null, 'source_path' );
		$writes    = array_column( $state['resolved']['writes'] ?? array(), null, 'target_path' );
		foreach ( $state['args']['image_asset_records'] ?? array() as $source => $record ) {
			if ( 'theme' !== ( $by_source[ $source ]['destination'] ?? '' ) ) {
				continue;
			}
			$write = $writes[ (string) ( $by_source[ $source ]['target_path'] ?? '' ) ] ?? null;
			if ( ! is_array( $write ) || 'theme_asset' !== ( $write['kind'] ?? '' ) ) {
				return new WP_Error( 'image_resource_unresolved', 'The recorded theme asset is missing its declared theme write.', array( 'source_path' => $source ) );
			}
			require_once __DIR__ . '/class-static-site-importer-image-assets.php';
			$bytes = Static_Site_Importer_Site_Plan_Persistence::write_payload_bytes( $write, $state['payload_reader'] ?? null );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			if ( ! is_string( $bytes ) || ! Static_Site_Importer_Image_Assets::verify_bytes( $bytes, $record ) ) {
				return new WP_Error( 'image_resource_unresolved', 'The staged theme asset does not match its capture record.', array( 'source_path' => $source ) );
			}
		}
		return true;
	}

	/** Inspect bounded local ICO bytes before any upload or identity mutation. */
	private static function ico_asset_metadata( string $file ): array {
		if ( ! is_readable( $file ) || filesize( $file ) > 2 * 1024 * 1024 ) {
			return array(
				'status' => 'invalid_ico',
				'reason' => 'unreadable_or_byte_limit',
			);
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local canonical asset written by this import.
		return false === $bytes ? array(
			'status' => 'invalid_ico',
			'reason' => 'unreadable',
		) : Static_Site_Importer_Ico_Asset::inspect( $bytes );
	}

	/** Reuse the owning compiler's standalone artwork contract for native media. */
	private static function svg_asset_metadata( string $file ): array {
		if ( ! class_exists( \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StandaloneSvgAsset::class ) ) {
			return array( 'status' => 'svg_runtime_unavailable' );
		}
		if ( ! is_readable( $file ) || filesize( $file ) > 2 * 1024 * 1024 ) {
			return array( 'status' => 'invalid_svg' );
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local canonical asset written by this import.
		return false === $bytes ? array( 'status' => 'invalid_svg' ) : \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\StandaloneSvgAsset::inspect( $bytes );
	}

	/** Page markup that may reference a replaceable raster, including escaped block attributes. */
	private static function content_references_media( string $content ): bool {
		return str_contains( $content, '<!-- wp:image' )
			|| str_contains( $content, '<!-- wp:cover' )
			|| str_contains( $content, '<!-- wp:media-text' )
			|| str_contains( $content, '<img' )
			|| str_contains( $content, '\\u003cimg' );
	}

	/**
	 * Bind raster images that are not already a core/image block.
	 *
	 * Companion blocks keep the image inside an attribute string. Cover and
	 * media-text keep it in a media URL attribute plus inner HTML. Only those
	 * references are rewritten; surrounding blocks keep their exact bytes.
	 *
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments
	 * @param array<string,int>                                                             $by_hash
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 */
	private static function bind_referenced_images( string $content, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, int &$bound, ?WP_Error &$error ): string {
		$rewritten = '';
		$offset    = 0;
		foreach ( self::block_openers( $content ) as $opener ) {
			if ( null !== $error ) {
				$rewritten .= substr( $content, $offset );
				return $rewritten;
			}
			$rewritten .= substr( $content, $offset, $opener['start'] - $offset );
			$rewritten .= self::bind_block_opener( $opener, $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $error );
			$offset     = $opener['end'];
		}
		$rewritten .= substr( $content, $offset );
		if ( null !== $error || ! str_contains( $rewritten, '<img' ) ) {
			return $rewritten;
		}

		$spans      = self::comment_spans( $rewritten );
		$bound_html = '';
		$cursor     = 0;
		$owners     = array();
		$owner_ids  = array();
		$openers    = array();
		foreach ( self::block_openers( $rewritten ) as $block_opener ) {
			$openers[ $block_opener['start'] ] = $block_opener;
		}
		foreach ( $spans as $span ) {
			$top         = count( $owners ) - 1;
			$image_class = self::image_class_for_owner( $owners[ $top ] ?? null );
			$segment     = self::rewrite_markup( substr( $rewritten, $cursor, $span['start'] - $cursor ), $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $error, $image_class, self::unbound_media_text_size_slug( $owners[ $top ] ?? null ) );
			if ( null !== $error ) {
				return $rewritten;
			}
			$bound_html .= $segment['html'];
			if ( $top >= 0 ) {
				$owner_ids[ $top ] = array_merge( $owner_ids[ $top ], $segment['ids'] );
			}
			$comment     = substr( $rewritten, $span['start'], $span['end'] - $span['start'] );
			$bound_html .= $comment;
			if ( isset( $openers[ $span['start'] ] ) && ! $openers[ $span['start'] ]['self'] ) {
				$owners[]    = array(
					'name'   => $openers[ $span['start'] ]['name'],
					'json'   => $openers[ $span['start'] ]['json'],
					'raw'    => $openers[ $span['start'] ]['raw'],
					'offset' => strlen( $bound_html ) - strlen( $comment ),
				);
				$owner_ids[] = array();
			} elseif ( preg_match( '~^<!--\s+/wp:([a-z0-9/-]+)\s+-->$~', $comment, $closing ) && ( $owners[ count( $owners ) - 1 ]['name'] ?? null ) === $closing[1] ) {
				$owner  = array_pop( $owners );
				$unique = array_values( array_unique( array_filter( array_pop( $owner_ids ) ?? array() ) ) );
				if ( null !== self::unbound_media_text_size_slug( $owner ) && 1 === count( $unique ) ) {
					$attrs              = json_decode( $owner['json'], true );
					$attrs['mediaType'] = 'image';
					$attrs['mediaId']   = $unique[0];
					$opener             = '<!-- wp:' . $owner['name'] . ' ' . serialize_block_attributes( $attrs ) . ' -->';
					$bound_html         = substr_replace( $bound_html, $opener, $owner['offset'], strlen( $owner['raw'] ) );
				}
			}
			$cursor = $span['end'];
		}
		$tail        = self::rewrite_markup( substr( $rewritten, $cursor ), $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $error, self::image_class_for_owner( $owners[ count( $owners ) - 1 ] ?? null ) );
		$bound_html .= $tail['html'];

		return null === $error ? $bound_html : $rewritten;
	}

	/** Size slug for a media-text owner whose opener has no mediaId yet, else null. */
	private static function unbound_media_text_size_slug( ?array $owner ): ?string {
		if ( ! in_array( $owner['name'] ?? '', array( 'media-text', 'core/media-text' ), true ) ) {
			return null;
		}
		$attrs = '' === $owner['json'] ? array() : json_decode( $owner['json'], true );
		if ( ! is_array( $attrs ) || (int) ( $attrs['mediaId'] ?? 0 ) > 0 ) {
			return null;
		}
		return (string) ( $attrs['mediaSizeSlug'] ?? 'full' );
	}

	/** The media-text save contract derives both image classes from mediaId. */
	private static function image_class_for_owner( ?array $owner ): ?string {
		if ( ! in_array( $owner['name'] ?? '', array( 'media-text', 'core/media-text' ), true ) ) {
			return null;
		}
		$attrs = json_decode( $owner['json'], true );
		$id    = (int) ( $attrs['mediaId'] ?? 0 );
		return $id > 0 ? 'wp-image-' . $id . ' size-' . ( $attrs['mediaSizeSlug'] ?? 'full' ) : '';
	}

	/**
	 * @param array{start:int,end:int,name:string,json:string,self:bool,raw:string}         $opener
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments
	 * @param array<string,int>                                                             $by_hash
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 */
	private static function bind_block_opener( array $opener, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, int &$bound, ?WP_Error &$error ): string {
		$raw = $opener['raw'];
		if ( in_array( $opener['name'], array( 'image', 'core/image' ), true ) || '' === $opener['json'] ) {
			return $raw;
		}
		$attrs = json_decode( $opener['json'], true );
		if ( ! is_array( $attrs ) ) {
			return $raw;
		}
		$ids     = array();
		$changed = false;
		$id_keys = array(
			'url'      => 'id',
			'mediaUrl' => 'mediaId',
		);
		foreach ( $id_keys as $source_key => $id_key ) {
			if ( ! isset( $attrs[ $source_key ] ) || ! is_string( $attrs[ $source_key ] ) ) {
				continue;
			}
			$id = self::attachment_id_for_url( $attrs[ $source_key ], '', $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $error );
			if ( null !== $error || $id <= 0 ) {
				continue;
			}
			$url = wp_get_attachment_url( $id );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$attrs[ $source_key ] = $url;
			$attrs[ $id_key ]     = $id;
			$ids[]                = $id;
			$changed              = true;
			++$bound;
		}
		foreach ( $attrs as $key => $value ) {
			if ( ! is_string( $value ) || ! str_contains( $value, '<img' ) ) {
				continue;
			}
			$rewritten = self::rewrite_markup( $value, $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $error );
			if ( null !== $error ) {
				return $raw;
			}
			if ( $rewritten['html'] === $value ) {
				continue;
			}
			$attrs[ $key ] = $rewritten['html'];
			$ids           = array_merge( $ids, $rewritten['ids'] );
			$changed       = true;
		}
		$unique = array_values( array_unique( array_filter( $ids ) ) );
		if ( 1 === count( $unique ) && empty( $attrs['id'] ) && empty( $attrs['mediaId'] ) ) {
			$attrs['id'] = $unique[0];
			$changed     = true;
		}
		if ( ! $changed ) {
			return $raw;
		}
		$encoded = serialize_block_attributes( $attrs );

		return '<!-- wp:' . $opener['name'] . ' ' . $encoded . ( $opener['self'] ? ' /-->' : ' -->' );
	}

	/**
	 * Rewrite img and source tags whose raster sources live in the generated theme.
	 *
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments
	 * @param array<string,int>                                                             $by_hash
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 * @return array{html:string,ids:array<int,int>}
	 */
	private static function rewrite_markup( string $html, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, int &$bound, ?WP_Error &$error, ?string $image_class = null, ?string $unbound_size_slug = null ): array {
		$ids = array();
		if ( ! str_contains( $html, '<img' ) && ! str_contains( $html, '<source' ) ) {
			return array(
				'html' => $html,
				'ids'  => $ids,
			);
		}
		$rewritten = preg_replace_callback(
			'/<(?:img|source)\b[^>]*>/i',
			static function ( array $tag ) use ( $theme_uri, $theme_dir, &$state, &$attachments, &$by_hash, &$report, &$bound, &$error, &$ids, $image_class, $unbound_size_slug ): string {
				if ( null !== $error ) {
					return $tag[0];
				}
				return self::rewrite_media_tag( $tag[0], $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $bound, $ids, $error, $image_class, $unbound_size_slug );
			},
			$html
		);

		return array(
			'html' => is_string( $rewritten ) ? $rewritten : $html,
			'ids'  => $ids,
		);
	}

	/**
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments
	 * @param array<string,int>                                                             $by_hash
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 * @param array<int,int>                                                                $ids
	 */
	private static function rewrite_media_tag( string $tag, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, int &$bound, array &$ids, ?WP_Error &$error, ?string $image_class, ?string $unbound_size_slug = null ): string {
		$src_id             = 0;
		$authored_selection = (bool) preg_match( '/\bsrcset="[^"\s]/i', $tag );
		if ( preg_match( '/\bsrc="([^"]*)"/i', $tag, $src_match ) ) {
			$src_id = self::attachment_id_for_url( html_entity_decode( $src_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), self::alt_from_tag( $tag ), $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $error );
			if ( null !== $error ) {
				return $tag;
			}
			if ( $src_id > 0 ) {
				$url = $authored_selection ? wp_get_original_image_url( $src_id ) : wp_get_attachment_url( $src_id );
				if ( is_string( $url ) && '' !== $url ) {
					$tag   = str_replace( 'src="' . $src_match[1] . '"', 'src="' . esc_url( $url ) . '"', $tag );
					$ids[] = $src_id;
					++$bound;
				}
			}
		}
		if ( preg_match( '/\bsrcset="([^"]*)"/i', $tag, $srcset_match ) ) {
			$srcset = self::rewrite_srcset( $srcset_match[1], $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $error );
			if ( null !== $error ) {
				return $tag;
			}
			if ( $srcset !== $srcset_match[1] ) {
				$tag = str_replace( 'srcset="' . $srcset_match[1] . '"', 'srcset="' . esc_attr( $srcset ) . '"', $tag );
			}
		}
		if ( '' === $image_class && null !== $unbound_size_slug && $src_id > 0 ) {
			// Media-text without a serialized mediaId: the opener is given this id once its block closes.
			$image_class = 'wp-image-' . $src_id . ' size-' . $unbound_size_slug;
		}
		if ( '' === $image_class || $src_id <= 0 || ! str_starts_with( strtolower( $tag ), '<img' ) ) {
			return $tag;
		}
		$tags = new WP_HTML_Tag_Processor( $tag );
		if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $tag;
		}
		$classes = preg_split( '/\s+/', (string) $tags->get_attribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			if ( preg_match( '/^wp-image-\d+$/', $class ) || ( null !== $image_class && str_starts_with( $class, 'size-' ) ) ) {
				$tags->remove_class( $class );
			}
		}
		foreach ( explode( ' ', $image_class ?? 'wp-image-' . $src_id ) as $class ) {
			$tags->add_class( $class );
		}
		return $tags->get_updated_html();
	}

	/**
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments
	 * @param array<string,int>                                                             $by_hash
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 */
	private static function rewrite_srcset( string $srcset, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, ?WP_Error &$error ): string {
		// Candidate commas belong to the shared producer parser, not the URL
		// scanner used for arbitrary markup. Descriptorless candidates end in
		// commas while URL-internal commas remain part of the asset identity.
		return \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser::rewrite(
			html_entity_decode( $srcset, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			static function ( string $url ) use ( $theme_uri, $theme_dir, &$state, &$attachments, &$by_hash, &$report, &$error ): string {
				$id = self::attachment_id_for_url( $url, '', $theme_uri, $theme_dir, $state, $attachments, $by_hash, $report, $error );
				if ( null !== $error || $id <= 0 ) {
					return $url;
				}
				// Core may replace attachment "full" with a scaled upload. Authored
				// descriptors and pixel identity belong to the original image bytes.
				$canonical = wp_get_original_image_url( $id );
				return is_string( $canonical ) && '' !== $canonical ? $canonical : $url;
			}
		);
	}

	/**
	 * @param array<mixed>                                                                  $state
	 * @param array<string,int>                                                             $attachments
	 * @param array<string,int>                                                             $by_hash
	 * @param array{attachment_count:int,replaceable_media_count:int,bound_block_count:int} $report
	 */
	private static function attachment_id_for_url( string $url, string $alt, string $theme_uri, string $theme_dir, array &$state, array &$attachments, array &$by_hash, array &$report, ?WP_Error &$error ): int {
		$relative = self::theme_relative_raster( $url, $theme_uri );
		if ( null === $relative ) {
			return 0;
		}
		++$report['replaceable_media_count'];
		$id = self::ensure_attachment( $theme_dir, $relative, $alt, $state, $attachments, $by_hash, $error );
		return null === $error ? $id : 0;
	}

	private static function alt_from_tag( string $tag ): string {
		if ( ! preg_match( '/\balt="([^"]*)"/i', $tag, $alt_match ) ) {
			return '';
		}
		return html_entity_decode( $alt_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * @return array<int,array{start:int,end:int,name:string,json:string,self:bool,raw:string}>
	 */
	private static function block_openers( string $content ): array {
		$openers = array();
		$offset  = 0;
		$length  = strlen( $content );
		for ( $pos = strpos( $content, '<!-- wp:', $offset ); false !== $pos; $pos = strpos( $content, '<!-- wp:', $offset ) ) {
			$cursor = $pos + 8;
			if ( ! preg_match( '/\G([a-z0-9\/-]+)/', $content, $name_match, 0, $cursor ) ) {
				$offset = $pos + 8;
				continue;
			}
			$name    = $name_match[1];
			$cursor += strlen( $name );
			while ( $cursor < $length && ( ' ' === $content[ $cursor ] || "\t" === $content[ $cursor ] ) ) {
				++$cursor;
			}
			$json = '';
			if ( $cursor < $length && '{' === $content[ $cursor ] ) {
				$json_end = self::json_object_end( $content, $cursor );
				if ( null === $json_end ) {
					$offset = $pos + 8;
					continue;
				}
				$json   = substr( $content, $cursor, $json_end - $cursor );
				$cursor = $json_end;
			}
			while ( $cursor < $length && ( ' ' === $content[ $cursor ] || "\t" === $content[ $cursor ] ) ) {
				++$cursor;
			}
			$self = str_starts_with( substr( $content, $cursor ), '/-->' );
			if ( ! $self && ! str_starts_with( substr( $content, $cursor ), '-->' ) ) {
				$offset = $pos + 8;
				continue;
			}
			$end       = $cursor + ( $self ? 4 : 3 );
			$openers[] = array(
				'start' => $pos,
				'end'   => $end,
				'name'  => $name,
				'json'  => $json,
				'self'  => $self,
				'raw'   => substr( $content, $pos, $end - $pos ),
			);
			$offset    = $end;
		}
		return $openers;
	}

	/**
	 * @return array<int,array{start:int,end:int}>
	 */
	private static function comment_spans( string $content ): array {
		$spans  = array();
		$offset = 0;
		for ( $pos = strpos( $content, '<!--', $offset ); false !== $pos; $pos = strpos( $content, '<!--', $offset ) ) {
			$end = strpos( $content, '-->', $pos );
			if ( false === $end ) {
				break;
			}
			$spans[] = array(
				'start' => $pos,
				'end'   => $end + 3,
			);
			$offset  = $end + 3;
		}
		return $spans;
	}

	private static function json_object_end( string $content, int $start ): ?int {
		$length    = strlen( $content );
		$depth     = 0;
		$in_string = false;
		$escape    = false;
		for ( $index = $start; $index < $length; $index++ ) {
			$char = $content[ $index ];
			if ( $in_string ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escape = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $char ) {
				$in_string = true;
				continue;
			}
			if ( '{' === $char ) {
				++$depth;
				continue;
			}
			if ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index + 1;
				}
			}
		}
		return null;
	}
}
