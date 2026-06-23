<?php
/**
 * WordPress page materialization helpers for website artifact imports.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
	 * Build page-specific template and pattern artifacts.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Pages.
	 * @param string                                          $theme_slug Theme slug.
	 * @param array<string,array<string,mixed>>                 $assets     Materialized assets keyed by source path.
	 * @param array<string,string>                              $permalinks Imported page permalinks keyed by source path.
	 * @return array{patterns:array<string,string>,files:array<string,string>,contents:array<string,string>,diagnostics:array<int,array<string,mixed>>}
	 */
	public static function page_artifacts( array $pages, string $theme_slug, array $assets = array(), array $permalinks = array() ): array {
		$patterns    = array();
		$files       = array();
		$contents    = array();
		$diagnostics = array();

		foreach ( $pages as $filename => $page ) {
			$slug         = self::page_slug( $filename, $page );
			$pattern_slug = sanitize_key( $theme_slug ) . '/page-' . $slug;
			$content      = self::rewrite_materialized_asset_references( self::source_page_content_blocks( $page, $diagnostics ), $assets, $page->source_key(), $permalinks );

			$patterns[ $filename ] = $pattern_slug;
			$files[ $filename ]    = Static_Site_Importer_Theme_Materializer::pattern_file( self::page_title( $filename, $page ), $pattern_slug, $content );
			$contents[ $filename ] = $content;
		}

		return array(
			'patterns'    => $patterns,
			'files'       => $files,
			'contents'    => $contents,
			'diagnostics' => $diagnostics,
		);
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

		$title = preg_replace( '/\s+(?:\x{2014}|-)\s+.+$/u', '', $page->document()->title() );
		if ( '' !== trim( (string) $title ) ) {
			return trim( (string) $title );
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
	private static function html_to_blocks( string $body, string $source_path, array &$diagnostics ): string {
		$result = blocks_engine_php_transformer_convert_format( $body, 'html', 'blocks' );

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

		return isset( $result['serialized_blocks'] ) && is_scalar( $result['serialized_blocks'] ) ? trim( (string) $result['serialized_blocks'] ) : '';
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
