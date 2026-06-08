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
 * Creates, updates, and describes WordPress pages from BAC source pages.
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
			$postarr  = array(
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
	 * @return array{patterns:array<string,string>,files:array<string,string>,contents:array<string,string>,diagnostics:array<int,array<string,mixed>>}
	 */
	public static function page_artifacts( array $pages, string $theme_slug ): array {
		$patterns    = array();
		$files       = array();
		$contents    = array();
		$diagnostics = array();

		foreach ( $pages as $filename => $page ) {
			$slug         = self::page_slug( $filename, $page );
			$pattern_slug = sanitize_key( $theme_slug ) . '/page-' . $slug;
			$content      = self::source_page_content_blocks( $page, $diagnostics );

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
		if ( $page instanceof Static_Site_Importer_Source_Page && 'bac_document' === $page->type() && self::is_index_source_filename( $filename ) && filter_var( $page->metadata_value( 'entrypoint' ), FILTER_VALIDATE_BOOLEAN ) ) {
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
	 * Prepare one BAC document body for WordPress writes.
	 *
	 * @param Static_Site_Importer_Source_Page $page        Source page.
	 * @param array<int,array<string,mixed>>    $diagnostics Diagnostics, passed by reference.
	 * @return string
	 */
	private static function source_page_content_blocks( Static_Site_Importer_Source_Page $page, array &$diagnostics ): string {
		$source_path = $page->source_key();
		if ( 'blocks' !== $page->body_format() ) {
			$diagnostics[] = array(
				'type'        => 'unsupported_document_artifact_format',
				'source'      => 'block-artifact-compiler/documents',
				'source_path' => $source_path,
				'format'      => $page->body_format(),
				'message'     => 'Website artifact imports require BAC document artifacts with serialized block markup.',
			);
			return '';
		}

		return trim( $page->body() );
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
