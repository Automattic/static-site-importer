<?php
/**
 * WordPress page materialization helpers for website artifact imports.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Site_Identity' ) ) {
	require_once __DIR__ . '/class-static-site-importer-site-identity.php';
}

/**
 * Creates, updates, and describes WordPress pages from source pages.
 */
class Static_Site_Importer_Page_Materializer {
	/**
	 * Create page shells so links can be rewritten before content conversion.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages Pages.
	 * @return array<string,int>|WP_Error
	 */
	public static function create_page_shells( array $pages ) {
		$page_ids = array();
		foreach ( $pages as $filename => $page ) {
			$title  = self::page_title( $filename, $page );
			$slug   = self::page_slug( $filename, $page );
			$status = self::page_status( $page );
			$type   = self::page_post_type( $page );

			$existing = get_page_by_path( $slug, OBJECT, $type );
			if ( $existing instanceof WP_Post && self::is_protected_page( $existing ) ) {
				$page_ids[ $filename ] = (int) $existing->ID;
				continue;
			}

			$postarr = array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_type'    => $type,
				'post_content' => '',
			);

			if ( $existing instanceof WP_Post ) {
				$postarr['ID'] = $existing->ID;
			}

			$page_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $page_id ) ) {
				return $page_id;
			}

			$page_ids[ $filename ] = (int) $page_id;
		}

		return $page_ids;
	}

	/**
	 * Describe the intended WordPress targets before materialization writes.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages Pages.
	 * @return array<string,array<string,mixed>> Target rows keyed by source filename.
	 */
	public static function page_targets( array $pages ): array {
		$targets = array();
		foreach ( $pages as $filename => $page ) {
			$slug     = self::page_slug( $filename, $page );
			$type     = self::page_post_type( $page );
			$existing = get_page_by_path( $slug, OBJECT, $type );
			$row      = array(
				'source_path'          => $page->source_key(),
				'target_type'          => 'wordpress_post',
				'post_type'            => $type,
				'slug'                 => $slug,
				'title'                => self::page_title( $filename, $page ),
				'status'               => self::page_status( $page ),
				'existing_post_id'     => 0,
				'existing_status'      => '',
				'protected'            => false,
				'materialized_post_id' => 0,
			);

			if ( $existing instanceof WP_Post ) {
				$row['existing_post_id'] = (int) $existing->ID;
				$row['existing_status']  = (string) $existing->post_status;
				$row['protected']        = self::is_protected_page( $existing );
			}

			$targets[ $filename ] = $row;
		}

		return $targets;
	}

	/**
	 * Build page-specific template and pattern artifacts.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Pages.
	 * @param string                                          $theme_slug Theme slug.
	 * @param array<string,array<string,mixed>>                 $assets     Materialized assets keyed by source path.
	 * @param array<string,string>                              $permalinks Imported page permalinks keyed by source path.
	 * @return array{patterns:array<string,string>,files:array<string,string>,asset_writes:array<string,string>,contents:array<string,string>,diagnostics:array<int,array<string,mixed>>}
	 */
	public static function page_artifacts( array $pages, string $theme_slug, array $assets = array(), array $permalinks = array() ): array {
		$patterns    = array();
		$files       = array();
		$contents    = array();
		$asset_writes = array();
		$diagnostics = array();

		foreach ( $pages as $filename => $page ) {
			$slug         = self::page_slug( $filename, $page );
			$pattern_slug = sanitize_key( $theme_slug ) . '/page-' . $slug;
			$content      = self::rewrite_materialized_asset_references( self::source_page_content_blocks( $page, $diagnostics ), $assets, $page->source_key(), $permalinks );
			$content      = self::promote_inline_svg_html_blocks( $content, $theme_slug, $slug, $asset_writes, $diagnostics );
			$content      = self::promote_navigation_list_blocks( $content, $diagnostics );

			$patterns[ $filename ] = $pattern_slug;
			$files[ $filename ]    = Static_Site_Importer_Theme_Materializer::pattern_file( self::page_title( $filename, $page ), $pattern_slug, $content );
			$contents[ $filename ] = $content;
		}

		return array(
			'patterns'    => $patterns,
			'files'       => $files,
			'asset_writes' => $asset_writes,
			'contents'    => $contents,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Promote safe SVG-only core/html blocks to materialized theme SVG assets.
	 *
	 * @param string                           $markup       Serialized block markup.
	 * @param string                           $theme_slug   Generated theme slug.
	 * @param string                           $page_slug    Page slug for stable asset names.
	 * @param array<string,string>             $asset_writes Theme-relative SVG writes, passed by reference.
	 * @param array<int,array<string,mixed>>    $diagnostics  Diagnostics, passed by reference.
	 * @return string Updated serialized block markup.
	 */
	private static function promote_inline_svg_html_blocks( string $markup, string $theme_slug, string $page_slug, array &$asset_writes, array &$diagnostics ): string {
		if ( '' === trim( $markup ) || ! str_contains( $markup, '<!-- wp:html' ) || ! str_contains( $markup, '<svg' ) ) {
			return $markup;
		}

		return preg_replace_callback(
			'/<!-- wp:html(?:\s+(\{.*?\}))? -->(.*?)<!-- \/wp:html -->/s',
			static function ( array $matches ) use ( $theme_slug, $page_slug, &$asset_writes, &$diagnostics ): string {
				$attrs = '' !== trim( (string) $matches[1] ) ? json_decode( (string) $matches[1], true ) : array();
				$html  = isset( $attrs['content'] ) && is_scalar( $attrs['content'] ) ? (string) $attrs['content'] : (string) $matches[2];
				$svg   = self::safe_inline_svg( html_entity_decode( trim( $html ), ENT_QUOTES | ENT_HTML5 ) );
				if ( '' === $svg ) {
					return $matches[0];
				}

				$hash          = substr( sha1( $svg ), 0, 12 );
				$asset_path    = 'assets/materialized/inline-svg/' . sanitize_title( $page_slug ) . '-' . $hash . '.svg';
				$asset_writes[ $asset_path ] = $svg . "\n";
				$url           = trailingslashit( get_theme_root_uri( sanitize_key( $theme_slug ) ) ) . sanitize_key( $theme_slug ) . '/' . $asset_path;
				$alt           = self::svg_accessible_label( $svg );
				$block_attrs   = array_filter(
					array(
						'url'       => esc_url_raw( $url ),
						'alt'       => $alt,
						'className' => 'blocks-engine-inline-svg',
					),
					static fn( $value ): bool => '' !== $value
				);
				$attrs_json    = wp_json_encode( $block_attrs, JSON_UNESCAPED_SLASHES );
				$alt_attribute = '' !== $alt ? ' alt="' . esc_attr( $alt ) . '"' : ' alt=""';

				$diagnostics[] = array(
					'type'        => 'inline_svg_materialized',
					'source'      => 'static-site-importer/page-materializer',
					'asset_path'  => $asset_path,
					'block_name'  => 'core/image',
					'message'     => 'Safe inline SVG core/html block was materialized as a theme SVG asset and core/image block.',
				);

				return '<!-- wp:image ' . ( false !== $attrs_json ? $attrs_json : '{}' ) . ' --><figure class="wp-block-image blocks-engine-inline-svg"><img src="' . esc_url( $url ) . '"' . $alt_attribute . '/></figure><!-- /wp:image -->';
			},
			$markup
		) ?? $markup;
	}

	/**
	 * Return sanitized SVG markup when the payload is a self-contained SVG image.
	 *
	 * @param string $svg Candidate SVG markup.
	 * @return string Safe SVG markup, or empty string when unsupported.
	 */
	private static function safe_inline_svg( string $svg ): string {
		$svg = trim( $svg );
		if ( '' === $svg || ! preg_match( '/^<svg\b[\s\S]*<\/svg>$/i', $svg ) ) {
			return '';
		}
		if ( preg_match( '/<(script|foreignObject|iframe|object|embed)\b/i', $svg ) || preg_match( '/\son[a-z]+\s*=/i', $svg ) ) {
			return '';
		}
		if ( preg_match( '/\b(?:href|xlink:href|src)\s*=\s*(["\'])(?!#)[^"\']+\1/i', $svg ) ) {
			return '';
		}

		return $svg;
	}

	/**
	 * Extract a compact accessible label from SVG metadata.
	 *
	 * @param string $svg SVG markup.
	 * @return string Label, or empty string.
	 */
	private static function svg_accessible_label( string $svg ): string {
		if ( preg_match( '/\baria-label\s*=\s*(["\'])(.*?)\1/i', $svg, $matches ) ) {
			return sanitize_text_field( html_entity_decode( (string) $matches[2], ENT_QUOTES | ENT_HTML5 ) );
		}
		if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $svg, $matches ) ) {
			return sanitize_text_field( html_entity_decode( wp_strip_all_tags( (string) $matches[1] ), ENT_QUOTES | ENT_HTML5 ) );
		}

		return '';
	}

	/**
	 * Store imported page bodies on their corresponding WordPress pages.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages    Pages.
	 * @param array<string,int>                               $page_ids Page IDs keyed by filename.
	 * @param array<string,string>                            $contents Converted block markup keyed by filename.
	 * @return true|WP_Error
	 */
	public static function write_page_contents( array $pages, array $page_ids, array $contents ) {
		foreach ( array_keys( $pages ) as $filename ) {
			$page_id = $page_ids[ $filename ] ?? 0;
			$post    = $page_id ? get_post( $page_id ) : null;
			if ( $post instanceof WP_Post && self::is_protected_page( $post ) ) {
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => wp_slash( trim( $contents[ $filename ] ?? '' ) ),
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
	 * Persist import provenance on pages owned by Static Site Importer.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages          Pages.
	 * @param array<string,int>                               $page_ids       Page IDs keyed by filename.
	 * @param array<string,array<string,mixed>>                $page_targets   Target rows keyed by filename.
	 * @param array<string,mixed>                              $manifest       Source-of-truth manifest.
	 * @return true|WP_Error
	 */
	public static function record_page_provenance( array $pages, array $page_ids, array $page_targets, array $manifest ) {
		foreach ( array_keys( $pages ) as $filename ) {
			$page_id = (int) ( $page_ids[ $filename ] ?? 0 );
			$post    = $page_id > 0 ? get_post( $page_id ) : null;
			if ( ! $post instanceof WP_Post || self::is_protected_page( $post ) ) {
				continue;
			}

			$target     = $page_targets[ $filename ] ?? array();
			$provenance = array(
				'schema'        => 'static-site-importer/page-provenance/v1',
				'import_run_id' => (string) ( $manifest['import_run_id'] ?? '' ),
				'artifact'      => isset( $manifest['artifact'] ) && is_array( $manifest['artifact'] ) ? $manifest['artifact'] : array(),
				'source_path'   => (string) ( $target['source_path'] ?? $filename ),
				'target'        => array(
					'post_id'   => $page_id,
					'post_type' => (string) ( $target['post_type'] ?? $post->post_type ),
					'slug'      => (string) ( $target['slug'] ?? $post->post_name ),
				),
			);

			$json = wp_json_encode( $provenance, JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				return new WP_Error( 'static_site_importer_page_provenance_encode_failed', 'Failed to encode page provenance metadata.' );
			}

			update_post_meta( $page_id, '_static_site_importer_provenance', wp_slash( $json ) );
			update_post_meta( $page_id, '_static_site_importer_import_run_id', (string) ( $manifest['import_run_id'] ?? '' ) );
			update_post_meta( $page_id, '_static_site_importer_source_path', (string) ( $target['source_path'] ?? $filename ) );
		}

		return true;
	}

	/**
	 * Build permalink map keyed by source filename.
	 *
	 * @param array<string,int> $page_ids Page IDs keyed by filename.
	 * @return array<string,string>
	 */
	public static function page_permalinks( array $page_ids ): array {
		$permalinks = array();
		foreach ( $page_ids as $filename => $page_id ) {
			$permalink = get_permalink( $page_id );
			if ( false !== $permalink ) {
				$permalinks[ $filename ] = $permalink;
				$basename                = basename( $filename );
				if ( ! isset( $permalinks[ $basename ] ) ) {
					$permalinks[ $basename ] = $permalink;
				}
			}
		}

		return $permalinks;
	}

	/**
	 * Build a WordPress page title from a source document.
	 *
	 * @param string                           $filename Source filename.
	 * @param Static_Site_Importer_Source_Page $page     Source page.
	 * @return string
	 */
	public static function page_title( string $filename, Static_Site_Importer_Source_Page $page ): string {
		$title = $page->metadata_value( 'title' );
		if ( '' !== trim( $title ) ) {
			return sanitize_text_field( $title );
		}

		if ( self::is_root_index_source_filename( $filename ) ) {
			return 'Home';
		}

		$title = Static_Site_Importer_Site_Identity::strip_title_suffix( $page->document()->title() );
		if ( '' !== trim( $title ) ) {
			return trim( $title );
		}

		return ucwords( str_replace( '-', ' ', self::page_slug( $filename, $page ) ) );
	}

	/**
	 * Build a WordPress page slug from a source path.
	 *
	 * @param string                                $filename Source filename.
	 * @param Static_Site_Importer_Source_Page|null $page     Source page.
	 * @return string
	 */
	public static function page_slug( string $filename, ?Static_Site_Importer_Source_Page $page = null ): string {
		if ( $page instanceof Static_Site_Importer_Source_Page && 'wordpress_document_artifact' === $page->type() && self::is_index_source_filename( $filename ) && filter_var( $page->metadata_value( 'entrypoint' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 'home';
		}

		if ( $page instanceof Static_Site_Importer_Source_Page && '' !== trim( $page->metadata_value( 'slug' ) ) ) {
			$slug = sanitize_title( $page->metadata_value( 'slug' ) );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		$extensionless = preg_replace( '/\.(?:html?)$/i', '', self::normalize_route_path( $filename ) );
		$extensionless = trim( (string) $extensionless, '/' );

		if ( self::is_root_index_source_filename( $filename ) ) {
			return 'home';
		}

		if ( str_ends_with( $extensionless, '/index' ) ) {
			$extensionless = substr( $extensionless, 0, -6 );
		}

		return sanitize_title( str_replace( '/', '-', $extensionless ) );
	}

	/**
	 * Build a safe WordPress page status from source metadata.
	 *
	 * @param Static_Site_Importer_Source_Page $page Source page.
	 * @return string
	 */
	public static function page_status( Static_Site_Importer_Source_Page $page ): string {
		$status = sanitize_key( $page->metadata_value( 'status' ) );

		return in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish';
	}

	/**
	 * Build a safe WordPress post type from source metadata.
	 *
	 * @param Static_Site_Importer_Source_Page $page Source page.
	 * @return string
	 */
	public static function page_post_type( Static_Site_Importer_Source_Page $page ): string {
		$post_type = sanitize_key( $page->metadata_value( 'post_type' ) );
		if ( '' === $post_type ) {
			return 'page';
		}

		$post_type_object = get_post_type_object( $post_type );
		return $post_type_object instanceof WP_Post_Type ? $post_type : 'page';
	}

	/**
	 * Determine whether an existing page is protected from importer writes.
	 *
	 * The `static_site_importer_protected_pages` option accepts slugs, paths, or
	 * numeric post IDs. The filter lets host products inject their own policy.
	 *
	 * @param WP_Post $post Existing WordPress post.
	 * @return bool
	 */
	public static function is_protected_page( WP_Post $post ): bool {
		$protected = get_option( 'static_site_importer_protected_pages', array() );
		if ( is_string( $protected ) ) {
			$protected = preg_split( '/[\s,]+/', $protected );
		}
		if ( ! is_array( $protected ) ) {
			$protected = array();
		}

		$tokens = array_filter(
			array_map(
				static function ( $value ): string {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				$protected
			),
			static fn( string $value ): bool => '' !== $value
		);

		$path = trim( (string) get_page_uri( $post ), '/' );
		$slug = (string) $post->post_name;
		$id   = (string) $post->ID;

		$is_protected = in_array( $id, $tokens, true ) || in_array( $slug, $tokens, true ) || in_array( $path, $tokens, true ) || in_array( '/' . $path, $tokens, true );

		return (bool) apply_filters( 'static_site_importer_is_protected_page', $is_protected, $post, $tokens );
	}

	/**
	 * Prepare one source page body for WordPress writes.
	 *
	 * @param Static_Site_Importer_Source_Page $page        Source page.
	 * @param array<int,array<string,mixed>>    $diagnostics Diagnostics, passed by reference.
	 * @return string
	 */
	private static function source_page_content_blocks( Static_Site_Importer_Source_Page $page, array &$diagnostics ): string {
		$source_path = $page->source_key();
		if ( 'blocks' === $page->body_format() ) {
			return trim( $page->body() );
		}

		if ( 'html' === $page->body_format() ) {
			$body = trim( $page->body() );
			if ( '' === $body ) {
				return '';
			}

			$blocks = self::html_to_blocks( $body, $source_path, $diagnostics );
			if ( '' !== trim( $blocks ) ) {
				return $blocks;
			}

			$diagnostics[] = array(
				'type'        => 'html_to_blocks_empty_output',
				'source'      => 'blocks-engine/html-to-blocks',
				'source_path' => $source_path,
				'format'      => 'html',
				'message'     => 'Blocks Engine HTML-to-blocks conversion did not return serialized block markup.',
			);
			return '';
		}

		if ( 'blocks' !== $page->body_format() ) {
			$diagnostics[] = array(
				'type'        => 'unsupported_document_artifact_format',
				'source'      => 'blocks-engine/documents',
				'source_path' => $source_path,
				'format'      => $page->body_format(),
				'message'     => 'Website artifact imports require document artifacts with serialized block markup.',
			);
			return '';
		}

		return '';
	}

	/**
	 * Convert raw HTML document content to serialized block markup.
	 *
	 * @param string                       $body        HTML body markup.
	 * @param string                       $source_path Source path for diagnostics.
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics, passed by reference.
	 */
	public static function html_to_blocks( string $body, string $source_path, array &$diagnostics ): string {
		if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
			$diagnostics[] = array(
				'type'        => 'missing_transformer_bridge',
				'source'      => 'blocks-engine/html-to-blocks',
				'source_path' => $source_path,
				'message'     => 'Blocks Engine php-transformer is required to convert HTML document artifacts to blocks.',
			);
			return '';
		}

		$result = call_user_func( 'blocks_engine_php_transformer_convert_format', $body, 'html', 'blocks' );

		foreach ( isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array() as $diagnostic ) {
			if ( is_array( $diagnostic ) ) {
				/** @var array<string,mixed> $diagnostic */
				$diagnostics[] = array_merge(
					$diagnostic,
					array(
						'source'      => 'blocks-engine/html-to-blocks',
						'source_path' => $source_path,
					)
				);
			}
		}

		$serialized_blocks = isset( $result['serialized_blocks'] ) && is_scalar( $result['serialized_blocks'] ) ? trim( (string) $result['serialized_blocks'] ) : '';

		return self::reduce_safe_html_fallback_blocks( $serialized_blocks );
	}

	/**
	 * Replace safe static core/html fallbacks with native core blocks.
	 *
	 * @param string $markup Serialized block markup.
	 * @return string
	 */
	private static function reduce_safe_html_fallback_blocks( string $markup ): string {
		if ( '' === trim( $markup ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_block' ) ) {
			return $markup;
		}

		$markup      = self::serialize_blocks_with_reduced_fallbacks( parse_blocks( $markup ) );
		$diagnostics = array();

		return self::promote_navigation_list_blocks( $markup, $diagnostics );
	}

	/**
	 * Promote simple list blocks inside nav-like containers to native navigation blocks.
	 *
	 * @param string                         $markup      Serialized block markup.
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics, passed by reference.
	 * @return string
	 */
	private static function promote_navigation_list_blocks( string $markup, array &$diagnostics ): string {
		if ( '' === trim( $markup ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}

		$blocks  = parse_blocks( $markup );
		$changed = false;
		self::promote_navigation_list_blocks_in_tree( $blocks, false, $changed, $diagnostics );

		return $changed ? serialize_blocks( $blocks ) : $markup;
	}

	/**
	 * Recursively promote nav-list blocks in parsed block trees.
	 *
	 * @param array<int,array<string,mixed>>  $blocks      Parsed blocks.
	 * @param bool                           $inside_nav  Whether the parent context is nav-like.
	 * @param bool                           $changed     Whether the tree changed, passed by reference.
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics, passed by reference.
	 */
	private static function promote_navigation_list_blocks_in_tree( array &$blocks, bool $inside_nav, bool &$changed, array &$diagnostics ): void {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name        = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$is_nav_like = $inside_nav || self::is_navigation_like_block( $name, $attrs );

			if ( $is_nav_like && 'core/list' === $name ) {
				$navigation = self::navigation_block_from_list_block( $block );
				if ( null !== $navigation ) {
					$block      = $navigation;
					$changed    = true;
					$diagnostics[] = array(
						'type'       => 'navigation_list_materialized',
						'source'     => 'static-site-importer/page-materializer',
						'block_name' => 'core/navigation',
						'message'    => 'A simple list inside a navigation-like HTML artifact section was materialized as a core/navigation block.',
					);
					continue;
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::promote_navigation_list_blocks_in_tree( $block['innerBlocks'], $is_nav_like, $changed, $diagnostics );
			}
		}
		unset( $block );
	}

	/**
	 * Detect containers that represent site navigation from generic HTML artifact names/classes.
	 *
	 * @param string              $block_name Block name.
	 * @param array<string,mixed> $attrs      Block attrs.
	 * @return bool
	 */
	private static function is_navigation_like_block( string $block_name, array $attrs ): bool {
		$tag = isset( $attrs['tagName'] ) && is_scalar( $attrs['tagName'] ) ? strtolower( (string) $attrs['tagName'] ) : '';
		if ( 'core/navigation' === $block_name || 'nav' === $tag ) {
			return true;
		}

		$class = isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) ? strtolower( (string) $attrs['className'] ) : '';
		return '' !== $class && (bool) preg_match( '/(^|[-_\s])(?:nav|navbar|navigation|menu|menubar)([-_\s]|$)/', $class );
	}

	/**
	 * Convert a parsed list block to a parsed navigation block when every item is simple text/link content.
	 *
	 * @param array<string,mixed> $block Parsed list block.
	 * @return array<string,mixed>|null
	 */
	private static function navigation_block_from_list_block( array $block ): ?array {
		$items = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		if ( empty( $items ) ) {
			return null;
		}

		$markup = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || 'core/list-item' !== ( $item['blockName'] ?? '' ) ) {
				return null;
			}
			$link = self::navigation_link_markup_from_list_item_block( $item );
			if ( null === $link ) {
				return null;
			}
			$markup .= $link;
		}

		$attrs = array();
		if ( isset( $block['attrs']['className'] ) && is_scalar( $block['attrs']['className'] ) && '' !== trim( (string) $block['attrs']['className'] ) ) {
			$attrs['className'] = (string) $block['attrs']['className'];
		}

		$navigation = parse_blocks( self::serialized_block_markup( 'core/navigation', $attrs, $markup ) );
		return isset( $navigation[0] ) && is_array( $navigation[0] ) ? $navigation[0] : null;
	}

	/**
	 * Build navigation-link markup from a parsed list item.
	 *
	 * @param array<string,mixed> $block Parsed list item block.
	 * @return string|null
	 */
	private static function navigation_link_markup_from_list_item_block( array $block ): ?string {
		$content = isset( $block['attrs']['content'] ) && is_scalar( $block['attrs']['content'] ) ? (string) $block['attrs']['content'] : '';
		if ( '' === trim( $content ) && isset( $block['innerHTML'] ) && is_scalar( $block['innerHTML'] ) ) {
			$content = preg_replace( '/^\s*<li[^>]*>|<\/li>\s*$/i', '', (string) $block['innerHTML'] ) ?? '';
		}
		if ( '' === trim( $content ) || str_contains( $content, '<ul' ) || str_contains( $content, '<ol' ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML( '<!doctype html><html><body><div data-ssi-nav-item="1">' . $content . '</div></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}

		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//*[@data-ssi-nav-item="1"]' )->item( 0 );
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		$link = null;
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			if ( $child instanceof DOMText && '' === trim( (string) $child->textContent ) ) {
				continue;
			}
			if ( $child instanceof DOMElement && 'a' === strtolower( $child->tagName ) && null === $link ) {
				$link = $child;
				continue;
			}
			if ( $child instanceof DOMElement ) {
				return null;
			}
		}

		$label_source = $link instanceof DOMElement ? $link : $root;
		$label        = self::safe_inline_html( $label_source );
		if ( null === $label || '' === trim( wp_strip_all_tags( $label ) ) ) {
			return null;
		}

		$attrs = array(
			'label' => $label,
			'type'  => 'custom',
			'kind'  => 'custom',
			'url'   => $link instanceof DOMElement && '' !== trim( $link->getAttribute( 'href' ) ) ? $link->getAttribute( 'href' ) : '',
		);

		return self::navigation_link_block( $attrs );
	}

	/**
	 * Serialize parsed blocks while allowing fallback children to collapse or expand.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return string
	 */
	private static function serialize_blocks_with_reduced_fallbacks( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			if ( in_array( $name, array( 'core/html', 'core/freeform' ), true ) ) {
				$output .= self::fallback_html_to_native_blocks( self::fallback_block_html( $block ), $name );
				continue;
			}

			$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( array() === $inner_blocks ) {
				$output .= serialize_block( $block );
				continue;
			}

			$output .= self::serialize_container_block_with_reduced_fallbacks( $block, $inner_blocks );
		}

		return trim( $output );
	}

	/**
	 * Serialize a block, replacing each null placeholder with reduced child output.
	 *
	 * @param array<string,mixed>            $block        Parsed block.
	 * @param array<int,array<string,mixed>> $inner_blocks Parsed inner blocks.
	 * @return string
	 */
	private static function serialize_container_block_with_reduced_fallbacks( array $block, array $inner_blocks ): string {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( '' === $name ) {
			return serialize_block( $block );
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$body  = '';
		$index = 0;
		foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $chunk ) {
			if ( null === $chunk ) {
				$body .= isset( $inner_blocks[ $index ] ) ? self::serialize_blocks_with_reduced_fallbacks( array( $inner_blocks[ $index ] ) ) : '';
				++$index;
				continue;

			}

			$body .= is_scalar( $chunk ) ? (string) $chunk : '';
		}

		return self::serialized_block_markup( $name, $attrs, $body );
	}

	/**
	 * Extract raw HTML from a fallback block.
	 *
	 * @param array<string,mixed> $block Parsed fallback block.
	 * @return string
	 */
	private static function fallback_block_html( array $block ): string {
		if ( isset( $block['attrs']['content'] ) && is_scalar( $block['attrs']['content'] ) ) {
			return (string) $block['attrs']['content'];
		}

		return isset( $block['innerHTML'] ) && is_scalar( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
	}

	/**
	 * Convert a bounded raw HTML fragment to serialized native core block markup.
	 *
	 * @param string $html Raw HTML fallback content.
	 * @return string Empty when the fallback was empty; original fallback when unsafe.
	 */
	private static function fallback_html_to_native_blocks( string $html, string $fallback_block_name = 'core/html' ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$converted = self::safe_html_fragment_to_blocks( $html );
		if ( null === $converted ) {
			return self::serialized_block_markup( $fallback_block_name, array( 'content' => $html ), $html );
		}

		return $converted;
	}

	/**
	 * Convert a safe static HTML fragment into core blocks.
	 *
	 * @param string $html Raw HTML fragment.
	 * @return string|null Serialized block markup, or null when unsupported.
	 */
	private static function safe_html_fragment_to_blocks( string $html ): ?string {
		if ( preg_match( '/<\s*(?:script|style|iframe|canvas|svg|select|textarea)\b/i', $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML( '<!doctype html><html><body><div data-ssi-fragment-root="1">' . $html . '</div></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}

		$xpath = new DOMXPath( $dom );
		$query = $xpath->query( '//*[@data-ssi-fragment-root="1"]' );
		if ( false === $query ) {
			return null;
		}

		$root = $query->item( 0 );
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		$blocks = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$block = self::safe_dom_node_to_block_markup( $child );
			if ( null === $block ) {
				return null;
			}
			$blocks .= $block;
		}

		return trim( $blocks );
	}

	/**
	 * Convert a safe DOM node to serialized core block markup.
	 *
	 * @param DOMNode $node DOM node.
	 * @return string|null Serialized block markup, empty whitespace, or null when unsupported.
	 */
	private static function safe_dom_node_to_block_markup( DOMNode $node ): ?string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( (string) $node->textContent );
			return '' === $text ? '' : self::paragraph_block( self::escape_html( $text ), array() );
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		if ( $node->hasAttribute( 'style' ) ) {
			return null;
		}

		$tag   = strtolower( $node->tagName );
		$attrs = self::class_attrs_from_element( $node );

		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			$content = self::safe_inline_html( $node );
			return null === $content ? null : self::heading_block( (int) $matches[1], $content, $attrs );
		}

		if ( 'p' === $tag ) {
			$content = self::safe_inline_html( $node );
			return null === $content ? null : self::paragraph_block( $content, $attrs );
		}

		if ( in_array( $tag, array( 'a', 'button' ), true ) ) {
			$content = self::safe_inline_html( $node );
			if ( null === $content || '' === trim( wp_strip_all_tags( $content ) ) ) {
				return null;
			}
			$button_attrs = array_merge( $attrs, array( 'text' => $content ) );
			if ( 'a' === $tag && '' !== trim( $node->getAttribute( 'href' ) ) ) {
				$button_attrs['url'] = $node->getAttribute( 'href' );
			}
			return self::buttons_block( self::button_block( $button_attrs ) );
		}

		if ( 'nav' === $tag ) {
			return self::navigation_block( $node, $attrs );
		}

		if ( 'form' === $tag ) {
			return self::search_block( $node, $attrs );
		}

		if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
			return self::list_block( $node, 'ol' === $tag, $attrs );
		}

		if ( 'img' === $tag ) {
			return self::image_block( $node, $attrs );
		}

		if ( 'picture' === $tag ) {
			$image = self::first_descendant_element( $node, 'img' );
			return $image instanceof DOMElement ? self::image_block( $image, $attrs ) : null;
		}

		if ( 'figure' === $tag ) {
			return self::figure_block( $node, $attrs );
		}

		if ( 'blockquote' === $tag ) {
			return self::quote_block( $node, $attrs );
		}

		if ( 'hr' === $tag ) {
			return self::separator_block( $attrs );
		}

		if ( 'label' === $tag ) {
			$content = self::safe_inline_html( $node );
			return null === $content ? null : self::paragraph_block( $content, $attrs );
		}

		if ( 'input' === $tag ) {
			$type  = strtolower( trim( $node->getAttribute( 'type' ) ) );
			$name  = strtolower( trim( $node->getAttribute( 'name' ) ) );
			$value = trim( $node->getAttribute( 'value' ) );
			if ( in_array( $type, array( 'search', 'text' ), true ) && in_array( $name, array( 's', 'search', 'q' ), true ) ) {
				return self::search_input_block( $node, $attrs );
			}
			if ( in_array( $type, array( 'button', 'submit', 'reset' ), true ) && '' !== $value ) {
				return self::buttons_block( self::button_block( array_merge( $attrs, array( 'text' => self::escape_html( $value ) ) ) ) );
			}
			return null;
		}

		if ( 'figcaption' === $tag ) {
			$content = self::safe_inline_html( $node );
			return null === $content ? null : self::paragraph_block( $content, $attrs );
		}

		if ( in_array( $tag, array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav' ), true ) ) {
			if ( 'nav' === $tag ) {
				$navigation = self::navigation_block( $node, $attrs );
				if ( null !== $navigation ) {
					return $navigation;
				}
			}

			$query = self::query_grid_block( $node, $attrs );
			if ( null !== $query ) {
				return $query;
			}

			$button_group = self::button_group_from_element( $node );
			if ( null !== $button_group ) {
				return $button_group;
			}

			$children = '';
			foreach ( iterator_to_array( $node->childNodes ) as $child ) {
				$child_markup = self::safe_dom_node_to_block_markup( $child );
				if ( null === $child_markup ) {
					return null;
				}
				$children .= $child_markup;
			}

			if ( '' === trim( $children ) ) {
				return '';
			}

			if ( 'div' !== $tag ) {
				$attrs['tagName'] = $tag;
			}
			return self::group_block( $children, $attrs );
		}

		return null;
	}

	/**
	 * Extract className block attrs from an element.
	 *
	 * @param DOMElement $element Element.
	 * @return array<string,string>
	 */
	private static function class_attrs_from_element( DOMElement $element ): array {
		$class = trim( preg_replace( '/\s+/', ' ', $element->getAttribute( 'class' ) ) ?? '' );
		return '' === $class ? array() : array( 'className' => $class );
	}

	/**
	 * Return child elements, ignoring whitespace text nodes.
	 *
	 * @param DOMElement $element Element.
	 * @return DOMElement[]
	 */
	private static function element_children( DOMElement $element ): array {
		$children = array();
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( $child instanceof DOMText && '' === trim( (string) $child->textContent ) ) {
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				return array();
			}
			$children[] = $child;
		}

		return $children;
	}

	/**
	 * Return the first descendant element with the requested tag name.
	 *
	 * @param DOMElement $element Element.
	 * @param string     $tag     Tag name.
	 * @return DOMElement|null
	 */
	private static function first_descendant_element( DOMElement $element, string $tag ): ?DOMElement {
		$matches = $element->getElementsByTagName( $tag );
		$match   = $matches->item( 0 );
		return $match instanceof DOMElement ? $match : null;
	}

	/**
	 * Serialize safe inline HTML from a text-bearing element.
	 *
	 * @param DOMElement $element Element.
	 * @return string|null
	 */
	private static function safe_inline_html( DOMElement $element ): ?string {
		$html = '';
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$html .= self::escape_html( (string) $child->textContent );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( ! in_array( $tag, array( 'a', 'br', 'strong', 'b', 'em', 'i', 'span', 'small', 'mark', 'sub', 'sup' ), true ) || $child->hasAttribute( 'style' ) ) {
				return null;
			}

			$inner = self::safe_inline_html( $child );
			if ( null === $inner ) {
				return null;
			}

			if ( 'br' === $tag ) {
				$html .= '<br>';
				continue;
			}

			$attrs = '';
			if ( 'a' === $tag && '' !== trim( $child->getAttribute( 'href' ) ) ) {
				$attrs .= ' href="' . self::escape_attr( $child->getAttribute( 'href' ) ) . '"';
			}
			if ( '' !== trim( $child->getAttribute( 'class' ) ) ) {
				$attrs .= ' class="' . self::escape_attr( $child->getAttribute( 'class' ) ) . '"';
			}
			$html .= '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
		}

		return trim( $html );
	}

	/**
	 * Build a paragraph block.
	 *
	 * @param string              $content Inner HTML.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string
	 */
	private static function paragraph_block( string $content, array $attrs ): string {
		$attrs['content'] = $content;
		return self::serialized_block_markup( 'core/paragraph', $attrs, '<p' . self::class_attribute( $attrs ) . '>' . $content . '</p>' );
	}

	/**
	 * Build a heading block.
	 *
	 * @param int                 $level   Heading level.
	 * @param string              $content Inner HTML.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string
	 */
	private static function heading_block( int $level, string $content, array $attrs ): string {
		$level            = max( 1, min( 6, $level ) );
		$attrs['level']   = $level;
		$attrs['content'] = $content;
		return self::serialized_block_markup( 'core/heading', $attrs, '<h' . $level . ' class="wp-block-heading' . self::extra_classes( $attrs ) . '">' . $content . '</h' . $level . '>' );
	}

	/**
	 * Build a group block.
	 *
	 * @param string              $children Child block markup.
	 * @param array<string,mixed> $attrs    Block attrs.
	 * @return string
	 */
	private static function group_block( string $children, array $attrs ): string {
		$tag = isset( $attrs['tagName'] ) && is_string( $attrs['tagName'] ) ? $attrs['tagName'] : 'div';
		return self::serialized_block_markup( 'core/group', $attrs, '<' . $tag . ' class="wp-block-group' . self::extra_classes( $attrs ) . '">' . $children . '</' . $tag . '>' );
	}

	/**
	 * Build a list block.
	 *
	 * @param DOMElement          $element List element.
	 * @param bool                $ordered Whether the list is ordered.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function list_block( DOMElement $element, bool $ordered, array $attrs ): ?string {
		$items = '';
		foreach ( self::element_children( $element ) as $child ) {
			if ( 'li' !== strtolower( $child->tagName ) ) {
				return null;
			}
			$content = self::safe_inline_html( $child );
			if ( null === $content ) {
				return null;
			}
			$items .= self::serialized_block_markup( 'core/list-item', array( 'content' => $content ), '<li>' . $content . '</li>' );
		}

		if ( '' === $items ) {
			return '';
		}

		if ( $ordered ) {
			$attrs['ordered'] = true;
		}

		$tag = $ordered ? 'ol' : 'ul';
		return self::serialized_block_markup( 'core/list', $attrs, '<' . $tag . ' class="wp-block-list' . self::extra_classes( $attrs ) . '">' . $items . '</' . $tag . '>' );
	}

	/**
	 * Build a navigation block from a safe static navigation fragment.
	 *
	 * @param DOMElement          $element Navigation element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function navigation_block( DOMElement $element, array $attrs ): ?string {
		$items = self::navigation_items_from_element( $element );
		if ( null === $items || '' === $items ) {
			return null;
		}

		return self::serialized_block_markup( 'core/navigation', $attrs, $items );
	}

	/**
	 * Convert simple nav descendants into navigation-link inner blocks.
	 *
	 * @param DOMElement $element Source element.
	 * @return string|null
	 */
	private static function navigation_items_from_element( DOMElement $element ): ?string {
		$items = '';
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( $child instanceof DOMText && '' === trim( (string) $child->textContent ) ) {
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
				foreach ( self::element_children( $child ) as $list_item ) {
					if ( 'li' !== strtolower( $list_item->tagName ) ) {
						return null;
					}
					$item = self::navigation_item_from_element( $list_item );
					if ( null === $item ) {
						return null;
					}
					$items .= $item;
				}
				continue;
			}

			$item = self::navigation_item_from_element( $child );
			if ( null === $item ) {
				return null;
			}
			$items .= $item;
		}

		return $items;
	}

	/**
	 * Convert a single nav child into a navigation-link block.
	 *
	 * @param DOMElement $element Source element.
	 * @return string|null
	 */
	private static function navigation_item_from_element( DOMElement $element ): ?string {
		$link_element = 'a' === strtolower( $element->tagName ) ? $element : null;
		if ( null === $link_element ) {
			$children = self::element_children( $element );
			if ( 1 === count( $children ) && 'a' === strtolower( $children[0]->tagName ) ) {
				$link_element = $children[0];
			}
		}

		$label_source = $link_element instanceof DOMElement ? $link_element : $element;
		$label        = self::safe_inline_html( $label_source );
		if ( null === $label || '' === trim( wp_strip_all_tags( $label ) ) ) {
			return null;
		}

		$url   = $link_element instanceof DOMElement && '' !== trim( $link_element->getAttribute( 'href' ) ) ? $link_element->getAttribute( 'href' ) : '';
		$attrs = array(
			'label' => $label,
			'type'  => 'custom',
			'kind'  => 'custom',
			'url'   => $url,
		);

		return self::navigation_link_block( $attrs );
	}

	/**
	 * Build a navigation link block with visible fallback HTML.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private static function navigation_link_block( array $attrs ): string {
		$label = isset( $attrs['label'] ) && is_scalar( $attrs['label'] ) ? (string) $attrs['label'] : '';
		$url   = isset( $attrs['url'] ) && is_scalar( $attrs['url'] ) ? (string) $attrs['url'] : '';
		$html  = '<a class="wp-block-navigation-item__content"' . ( '' === $url ? '' : ' href="' . self::escape_attr( $url ) . '"' ) . '><span class="wp-block-navigation-item__label">' . $label . '</span></a>';

		return self::serialized_block_markup( 'core/navigation-link', $attrs, $html );
	}

	/**
	 * Build an image block.
	 *
	 * @param DOMElement          $element Image element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function image_block( DOMElement $element, array $attrs, string $caption = '' ): ?string {
		$src = trim( $element->getAttribute( 'src' ) );
		if ( '' === $src ) {
			return null;
		}

		$attrs['url'] = $src;
		$alt          = $element->getAttribute( 'alt' );
		if ( '' !== $alt ) {
			$attrs['alt'] = $alt;
		}
		if ( '' !== trim( $caption ) ) {
			$attrs['caption'] = $caption;
		}

		return self::serialized_block_markup( 'core/image', $attrs, '<figure class="wp-block-image' . self::extra_classes( $attrs ) . '"><img src="' . self::escape_attr( $src ) . '" alt="' . self::escape_attr( $alt ) . '" />' . ( '' === trim( $caption ) ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' ) . '</figure>' );
	}

	/**
	 * Convert recurring post-card grids into a native query/post-template scaffold.
	 *
	 * @param DOMElement          $element Container element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function query_grid_block( DOMElement $element, array $attrs ): ?string {
		$articles = array_values(
			array_filter(
				self::element_children( $element ),
				static fn ( DOMElement $child ): bool => 'article' === strtolower( $child->tagName )
			)
		);
		if ( count( $articles ) < 2 ) {
			return null;
		}

		$class = strtolower( $element->getAttribute( 'class' ) );
		if ( ! preg_match( '/(^|[\s_-])(archive|blog|card-grid|grid|loop|posts?|query)([\s_-]|$)/', $class ) ) {
			return null;
		}

		$columns = min( 4, max( 2, count( $articles ) ) );
		$attrs   = array_merge(
			$attrs,
			array(
				'query' => array(
					'perPage' => min( 12, count( $articles ) ),
					'pages'   => 0,
					'offset'  => 0,
					'postType' => 'post',
					'order'   => 'desc',
					'orderBy' => 'date',
					'inherit' => true,
				),
			)
		);
		$template_attrs = array(
			'layout' => array(
				'type'        => 'grid',
				'columnCount' => $columns,
			),
		);
		$card_attrs     = self::class_attrs_from_element( $articles[0] );
		$card_attrs['tagName'] = 'article';
		$card           = self::group_block(
			self::serialized_block_markup( 'core/post-featured-image', array( 'isLink' => true ), '<figure class="wp-block-post-featured-image"><a href="#" target="_self"></a></figure>' ) .
			self::serialized_block_markup( 'core/post-title', array( 'isLink' => true ), '<h2 class="wp-block-post-title"><a href="#" target="_self"></a></h2>' ) .
			self::serialized_block_markup( 'core/post-date', array(), '<div class="wp-block-post-date"></div>' ) .
			self::serialized_block_markup( 'core/post-excerpt', array(), '<div class="wp-block-post-excerpt"></div>' ),
			$card_attrs
		);

		$post_template = self::serialized_block_markup( 'core/post-template', $template_attrs, $card );
		return self::serialized_block_markup( 'core/query', $attrs, '<div class="wp-block-query' . self::extra_classes( $attrs ) . '">' . $post_template . '</div>' );
	}

	/**
	 * Convert containers that only wrap multiple CTAs into one buttons block.
	 *
	 * @param DOMElement $element Container element.
	 * @return string|null
	 */
	private static function button_group_from_element( DOMElement $element ): ?string {
		$children = self::element_children( $element );
		if ( count( $children ) < 2 ) {
			return null;
		}
		$container_class = strtolower( $element->getAttribute( 'class' ) );
		$looks_like_buttons = (bool) preg_match( '/(^|[\s_-])(actions?|buttons?|cta|call-to-action)([\s_-]|$)/', $container_class );

		$buttons = '';
		foreach ( $children as $child ) {
			if ( ! in_array( strtolower( $child->tagName ), array( 'a', 'button' ), true ) ) {
				return null;
			}
			$child_class = strtolower( $child->getAttribute( 'class' ) );
			if ( preg_match( '/(^|[\s_-])(button|btn|cta|call-to-action)([\s_-]|$)/', $child_class ) ) {
				$looks_like_buttons = true;
			}
			$content = self::safe_inline_html( $child );
			if ( null === $content || '' === trim( wp_strip_all_tags( $content ) ) ) {
				return null;
			}
			$button_attrs = array_merge( self::class_attrs_from_element( $child ), array( 'text' => $content ) );
			if ( 'a' === strtolower( $child->tagName ) && '' !== trim( $child->getAttribute( 'href' ) ) ) {
				$button_attrs['url'] = $child->getAttribute( 'href' );
			}
			$buttons .= self::button_block( $button_attrs );
		}
		if ( ! $looks_like_buttons ) {
			return null;
		}

		return self::buttons_block( $buttons );
	}

	/**
	 * Build an image block from a static figure/picture wrapper.
	 *
	 * @param DOMElement          $element Figure element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function figure_block( DOMElement $element, array $attrs ): ?string {
		$children = self::element_children( $element );
		if ( array() === $children || count( $children ) > 2 ) {
			return null;
		}

		$image   = null;
		$caption = '';
		foreach ( $children as $child ) {
			$tag = strtolower( $child->tagName );
			if ( 'img' === $tag ) {
				$image = $child;
				continue;
			}
			if ( 'picture' === $tag ) {
				$image = self::first_descendant_element( $child, 'img' );
				continue;
			}
			if ( 'figcaption' === $tag ) {
				$caption = self::safe_inline_html( $child );
				if ( null === $caption ) {
					return null;
				}
				continue;
			}
			return null;
		}

		if ( ! $image instanceof DOMElement ) {
			return null;
		}

		$src = trim( $image->getAttribute( 'src' ) );
		if ( '' === $src ) {
			return null;
		}

		$attrs['url'] = $src;
		$alt          = $image->getAttribute( 'alt' );
		if ( '' !== $alt ) {
			$attrs['alt'] = $alt;
		}
		if ( '' !== $caption ) {
			$attrs['caption'] = $caption;
		}

		return self::serialized_block_markup( 'core/image', $attrs, '<figure class="wp-block-image' . self::extra_classes( $attrs ) . '"><img src="' . self::escape_attr( $src ) . '" alt="' . self::escape_attr( $alt ) . '" />' . ( '' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' ) . '</figure>' );
	}

	/**
	 * Build a static quote block.
	 *
	 * @param DOMElement          $element Blockquote element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function quote_block( DOMElement $element, array $attrs ): ?string {
		$value    = '';
		$citation = '';
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text = trim( (string) $child->textContent );
				if ( '' !== $text ) {
					$value .= '<p>' . self::escape_html( $text ) . '</p>';
				}
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag     = strtolower( $child->tagName );
			$content = self::safe_inline_html( $child );
			if ( null === $content ) {
				return null;
			}
			if ( 'cite' === $tag ) {
				$citation = $content;
				continue;
			}
			if ( 'p' !== $tag ) {
				return null;
			}
			$value .= '<p>' . $content . '</p>';
		}

		if ( '' === trim( $value ) ) {
			return null;
		}

		$attrs['value'] = $value;
		if ( '' !== $citation ) {
			$attrs['citation'] = $citation;
		}

		return self::serialized_block_markup( 'core/quote', $attrs, '<blockquote class="wp-block-quote' . self::extra_classes( $attrs ) . '">' . $value . ( '' === $citation ? '' : '<cite>' . $citation . '</cite>' ) . '</blockquote>' );
	}

	/**
	 * Build a separator block.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private static function separator_block( array $attrs ): string {
		return self::serialized_block_markup( 'core/separator', $attrs, '<hr class="wp-block-separator has-alpha-channel-opacity' . self::extra_classes( $attrs ) . '" />' );
	}

	/**
	 * Build a static search block from a non-runtime search form.
	 *
	 * @param DOMElement          $element Form element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string|null
	 */
	private static function search_block( DOMElement $element, array $attrs ): ?string {
		$inputs = iterator_to_array( $element->getElementsByTagName( 'input' ) );
		if ( array() === $inputs || 0 !== $element->getElementsByTagName( 'textarea' )->length || 0 !== $element->getElementsByTagName( 'select' )->length ) {
			return null;
		}

		$search_input = null;
		$button_text  = '';
		foreach ( $inputs as $input ) {
			$type = strtolower( trim( $input->getAttribute( 'type' ) ) );
			$name = strtolower( trim( $input->getAttribute( 'name' ) ) );
			if ( in_array( $type, array( '', 'search', 'text' ), true ) && in_array( $name, array( '', 's', 'search', 'q' ), true ) ) {
				$search_input = $input;
				continue;
			}
			if ( in_array( $type, array( 'submit', 'button' ), true ) ) {
				$button_text = trim( $input->getAttribute( 'value' ) );
				continue;
			}
			if ( 'hidden' !== $type ) {
				return null;
			}
		}

		foreach ( iterator_to_array( $element->getElementsByTagName( 'button' ) ) as $button ) {
			$content = self::safe_inline_html( $button );
			if ( null === $content ) {
				return null;
			}
			$button_text = trim( wp_strip_all_tags( $content ) );
		}

		$class = ' ' . strtolower( $element->getAttribute( 'class' ) ) . ' ';
		$role  = strtolower( trim( $element->getAttribute( 'role' ) ) );
		if ( ! $search_input instanceof DOMElement || ( 'search' !== $role && ! str_contains( $class, ' search' ) && ! str_contains( $class, 'search-' ) && ! str_contains( $class, '-search' ) ) ) {
			return null;
		}

		$label       = trim( $search_input->getAttribute( 'aria-label' ) );
		$placeholder = trim( $search_input->getAttribute( 'placeholder' ) );
		$action      = trim( $element->getAttribute( 'action' ) );
		if ( '' === $label ) {
			$label = 'Search';
		}
		if ( '' === $action ) {
			$action = '/';
		}
		if ( '' === $button_text ) {
			$button_text = 'Search';
		}

		return self::search_block_markup( $attrs, $label, $placeholder, $button_text, $action );
	}

	/**
	 * Build a static search block from a standalone generated search input.
	 *
	 * @param DOMElement          $element Input element.
	 * @param array<string,mixed> $attrs   Block attrs.
	 * @return string
	 */
	private static function search_input_block( DOMElement $element, array $attrs ): string {
		$label       = trim( $element->getAttribute( 'aria-label' ) );
		$placeholder = trim( $element->getAttribute( 'placeholder' ) );
		if ( '' === $label ) {
			$label = 'Search';
		}
		return self::search_block_markup( $attrs, $label, $placeholder, 'Search', '/' );
	}

	/**
	 * Serialize a static search block.
	 *
	 * @param array<string,mixed> $attrs       Block attrs.
	 * @param string              $label       Search label.
	 * @param string              $placeholder Input placeholder.
	 * @param string              $button_text Button text.
	 * @param string              $action      Form action URL.
	 * @return string
	 */
	private static function search_block_markup( array $attrs, string $label, string $placeholder, string $button_text, string $action ): string {
		$attrs['label']       = $label;
		$attrs['buttonText']  = $button_text;
		$attrs['placeholder'] = $placeholder;
		$attrs['query']       = array( 'post_type' => 'post' );

		return self::serialized_block_markup( 'core/search', $attrs, '<form role="search" method="get" class="wp-block-search' . self::extra_classes( $attrs ) . '" action="' . self::escape_attr( $action ) . '"><label class="wp-block-search__label">' . self::escape_html( $label ) . '</label><div class="wp-block-search__inside-wrapper"><input class="wp-block-search__input" placeholder="' . self::escape_attr( $placeholder ) . '" value="" type="search" name="s" required /><button aria-label="' . self::escape_attr( $button_text ) . '" class="wp-block-search__button wp-element-button" type="submit">' . self::escape_html( $button_text ) . '</button></div></form>' );
	}

	/**
	 * Build a buttons wrapper.
	 *
	 * @param string $button Button block markup.
	 * @return string
	 */
	private static function buttons_block( string $button ): string {
		return self::serialized_block_markup( 'core/buttons', array(), '<div class="wp-block-buttons">' . $button . '</div>' );
	}

	/**
	 * Build a button block.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private static function button_block( array $attrs ): string {
		$text = isset( $attrs['text'] ) && is_scalar( $attrs['text'] ) ? (string) $attrs['text'] : '';
		$url  = isset( $attrs['url'] ) && is_scalar( $attrs['url'] ) ? (string) $attrs['url'] : '';
		$html = '<div class="wp-block-button' . self::extra_classes( $attrs ) . '"><a class="wp-block-button__link wp-element-button"' . ( '' === $url ? '' : ' href="' . self::escape_attr( $url ) . '"' ) . '>' . $text . '</a></div>';

		return self::serialized_block_markup( 'core/button', $attrs, $html );
	}

	/**
	 * Serialize a block wrapper around body HTML.
	 *
	 * @param string              $name Block name.
	 * @param array<string,mixed> $attrs Block attrs.
	 * @param string              $body Block body.
	 * @return string
	 */
	private static function serialized_block_markup( string $name, array $attrs, string $body ): string {
		$comment_name = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$attrs_json   = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return '<!-- wp:' . $comment_name . $attrs_json . ' -->' . $body . '<!-- /wp:' . $comment_name . ' -->';
	}

	/**
	 * Render a class attribute for blocks without a default wrapper class.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private static function class_attribute( array $attrs ): string {
		return isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) && '' !== trim( (string) $attrs['className'] ) ? ' class="' . self::escape_attr( (string) $attrs['className'] ) . '"' : '';
	}

	/**
	 * Render extra classes after a core wrapper class.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return string
	 */
	private static function extra_classes( array $attrs ): string {
		return isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) && '' !== trim( (string) $attrs['className'] ) ? ' ' . self::escape_attr( (string) $attrs['className'] ) : '';
	}

	/**
	 * Escape text content.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function escape_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escape an HTML attribute value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function escape_attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Rewrite source-relative asset URLs to generated-theme asset URLs.
	 *
	 * @param string                              $markup Serialized block markup.
	 * @param array<string,array<string,mixed>>   $assets Materialized assets keyed by source path.
	 * @param string                              $source_path Source page path for resolving page-relative URLs.
	 * @param array<string,string>                $permalinks Imported page permalinks keyed by source path.
	 * @return string Updated markup.
	 */
	private static function rewrite_materialized_asset_references( string $markup, array $assets, string $source_path = '', array $permalinks = array() ): string {
		if ( '' === trim( $markup ) || ( empty( $assets ) && empty( $permalinks ) ) ) {
			return $markup;
		}

		$replacements = array();
		foreach ( $permalinks as $source => $permalink ) {
			$normalized_source = self::normalize_route_path( $source );
			if ( '' !== $normalized_source && '' !== trim( (string) $permalink ) ) {
				$replacements[ $normalized_source ] = (string) $permalink;
			}
		}
		foreach ( $assets as $source => $asset ) {
			if ( ! isset( $asset['final_url'] ) || ! is_scalar( $asset['final_url'] ) ) {
				continue;
			}

			$normalized_source = self::normalize_route_path( $source );
			if ( '' !== $normalized_source && ! isset( $replacements[ $normalized_source ] ) ) {
				$replacements[ $normalized_source ] = (string) $asset['final_url'];
				if ( str_starts_with( $normalized_source, 'website/' ) ) {
					$replacements[ substr( $normalized_source, strlen( 'website/' ) ) ] = (string) $asset['final_url'];
				}
			}
		}

		if ( empty( $replacements ) ) {
			return $markup;
		}

		$source_dir = dirname( self::normalize_route_path( $source_path ) );

		$markup = preg_replace_callback(
			'/"(url|href|src)"\s*:\s*"([^"]*)"/i',
			static function ( array $matches ) use ( $replacements ): string {
				$url        = html_entity_decode( (string) $matches[2], ENT_QUOTES | ENT_HTML5 );
				$normalized = self::normalize_route_path( $url );
				if ( '' === $normalized || ! isset( $replacements[ $normalized ] ) ) {
					return $matches[0];
				}

				return '"' . $matches[1] . '":' . wp_json_encode( esc_url( $replacements[ $normalized ] ) );
			},
			$markup
		) ?? $markup;

		$markup = preg_replace_callback(
			'/\burl\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
			static function ( array $matches ) use ( $replacements, $source_dir ): string {
				$url        = html_entity_decode( (string) $matches[2], ENT_QUOTES | ENT_HTML5 );
				$normalized = self::normalize_route_path( $url );
				if ( '' !== $normalized && isset( $replacements[ $normalized ] ) ) {
					return 'url("' . esc_url( $replacements[ $normalized ] ) . '")';
				}

				if ( '' === $normalized || '' === $source_dir || '.' === $source_dir || str_starts_with( $url, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
					return $matches[0];
				}

				$resolved = self::normalize_route_path( $source_dir . '/' . $url );
				if ( '' === $resolved || ! isset( $replacements[ $resolved ] ) ) {
					return $matches[0];
				}

				return 'url("' . esc_url( $replacements[ $resolved ] ) . '")';
			},
			$markup
		) ?? $markup;

		return preg_replace_callback(
			'/\b(src|href)=([' . "'\"" . '])([^' . "'\"" . ']*)\2/i',
			static function ( array $matches ) use ( $replacements, $source_dir ): string {
				$url        = html_entity_decode( (string) $matches[3], ENT_QUOTES | ENT_HTML5 );
				$normalized = self::normalize_route_path( $url );
				if ( '' !== $normalized && isset( $replacements[ $normalized ] ) ) {
					return $matches[1] . '=' . $matches[2] . esc_url( $replacements[ $normalized ] ) . $matches[2];
				}

				if ( '' === $normalized || '' === $source_dir || '.' === $source_dir || str_starts_with( $url, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
					return $matches[0];
				}

				$resolved = self::normalize_route_path( $source_dir . '/' . $url );
				if ( '' === $resolved || ! isset( $replacements[ $resolved ] ) ) {
					return $matches[0];
				}

				return $matches[1] . '=' . $matches[2] . esc_url( $replacements[ $resolved ] ) . $matches[2];
			},
			$markup
		) ?? $markup;
	}

	/**
	 * Check whether a source filename is the site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_index_source_filename( string $filename ): bool {
		return in_array( strtolower( basename( $filename ) ), array( 'index.html' ), true );
	}

	/**
	 * Check whether a source filename is the root site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_root_index_source_filename( string $filename ): bool {
		return in_array( strtolower( trim( self::normalize_route_path( $filename ), '/' ) ), array( 'index.html' ), true );
	}

	/**
	 * Normalize a route-like path without resolving outside the source root.
	 *
	 * @param string $path Route path.
	 * @return string
	 */
	private static function normalize_route_path( string $path ): string {
		$path_without_query = strtok( $path, '?' );
		$path               = str_replace( '\\', '/', false === $path_without_query ? $path : $path_without_query );
		$path               = ltrim( $path, '/' );
		$segments           = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}
}
