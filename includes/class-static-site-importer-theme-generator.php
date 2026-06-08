<?php
/**
 * Block theme generator.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Generic.Formatting.MultipleStatementAlignment -- The generator keeps localized assignment alignment; PHPCBF exhausts memory on this large file.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a block theme from a static HTML document.
 */
class Static_Site_Importer_Theme_Generator {

	/**
	 * Scoped conversion quality report for the active import.
	 *
	 * @var array<string, mixed>
	 */
	private static array $conversion_report = array();

	/**
	 * Generated theme directory for import-scoped asset writes.
	 *
	 * @var string
	 */
	private static string $active_theme_dir = '';

	/**
	 * Generated theme URI for import-scoped asset references.
	 *
	 * @var string
	 */
	private static string $active_theme_uri = '';

	/**
	 * Caller-supplied asset map keyed by source-relative path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_asset_map = array();

	/**
	 * BAC file artifacts materialized into the generated theme, keyed by source path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_artifact_materialized_assets = array();

	/**
	 * Active local asset materialization policy.
	 *
	 * @var string
	 */
	private static string $active_asset_materialization_policy = 'copy_to_theme';

	/**
	 * Resolved local asset metadata keyed by original and rewritten references.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_asset_metadata = array();

	/**
	 * Local asset report rows already recorded for this import.
	 *
	 * @var array<string, true>
	 */
	private static array $recorded_local_asset_keys = array();

	/**
	 * Materialized inline SVG assets keyed by SVG content hash.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $materialized_svg_assets = array();

	/**
	 * Extracted safe SVG symbol sprites keyed by symbol id.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $svg_sprite_symbols = array();

	/**
	 * Materialized SVG sprite files keyed by sprite content hash.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $materialized_svg_sprites = array();

	/**
	 * Classes observed on generated core/button wrappers during this import.
	 *
	 * @var array<string, true>
	 */
	private static array $button_wrapper_classes = array();

	/**
	 * CSS classes that identify absolute-positioned imported visual layers.
	 *
	 * @var array<string, true>
	 */
	private static array $decorative_empty_group_classes = array();

	/**
	 * Import a website artifact bundle as a block theme.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function import_website_artifact( array $artifact, array $args = array() ) {
		if ( ! function_exists( 'bac_compile_website_artifact' ) || ! function_exists( 'bac_summarize_result' ) ) {
			return new WP_Error( 'static_site_importer_missing_bac', 'Block Artifact Compiler is required to import a website artifact.' );
		}

		$compiler_options = isset( $args['compiler_options'] ) && is_array( $args['compiler_options'] ) ? $args['compiler_options'] : array();
		$compiled         = bac_compile_website_artifact( $artifact, array_merge( array( 'include_bfb_report' => true ), $compiler_options ) );
		$document_pages   = self::bac_document_pages( $compiled );
		if ( is_wp_error( $document_pages ) ) {
			return $document_pages;
		}

		if ( 'failed' === (string) ( $compiled['status'] ?? '' ) || empty( $document_pages ) ) {
			return new WP_Error( 'static_site_importer_artifact_compile_failed', 'Website artifact compilation did not produce BAC compiled-site document pages.', $compiled );
		}

		return self::import_compiled_website_artifact( $compiled, $args );
	}

	/**
	 * Materialize a compiled website artifact directly into WordPress theme artifacts.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @param array<string,mixed> $args     Import args.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function import_compiled_website_artifact( array $compiled, array $args = array() ) {
		$artifacts    = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$theme_name   = isset( $args['name'] ) && '' !== trim( (string) $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : 'Imported Website Artifact';
		$theme_slug   = isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : sanitize_title( $theme_name );
		if ( '' === $theme_slug ) {
			$theme_slug = 'imported-website-artifact';
		}

		$theme_root = get_theme_root();
		$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;
		if ( file_exists( $theme_dir ) && empty( $args['overwrite'] ) ) {
			return new WP_Error( 'static_site_importer_theme_exists', sprintf( 'Theme already exists: %s', $theme_slug ) );
		}

		$result = Static_Site_Importer_Theme_Materializer::ensure_dirs( $theme_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$source_metadata           = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$source_metadata['source'] = 'website_artifact';
		$html_path                 = (string) ( $compiled['provenance']['source'] ?? ( $compiled['input']['entry_path'] ?? 'website_artifact' ) );
		self::$conversion_report   = self::new_conversion_report( $html_path, $source_metadata );
		self::record_website_artifact_compiler_result( $compiled );
		self::record_direct_website_artifact_source_summary( $compiled );

		self::$active_theme_dir         = $theme_dir;
		self::$active_theme_uri         = trailingslashit( get_theme_root_uri( $theme_slug ) ) . $theme_slug;
		$asset_policy = self::normalize_asset_materialization_policy( $args['asset_materialization_policy'] ?? '' );
		if ( is_wp_error( $asset_policy ) ) {
			return $asset_policy;
		}

		self::$active_asset_map         = self::normalize_asset_map( isset( $args['asset_map'] ) && is_array( $args['asset_map'] ) ? $args['asset_map'] : array() );
		self::$active_artifact_materialized_assets = array();
		self::$active_asset_materialization_policy = $asset_policy;
		self::$active_asset_metadata    = array();
		self::$recorded_local_asset_keys    = array();
		self::$conversion_report['assets']['policy']         = 'theme';
		self::$conversion_report['asset_map']['supplied']    = ! empty( self::$active_asset_map );
		self::$conversion_report['asset_map']['entry_count'] = count( self::$active_asset_map );
		self::$conversion_report['assets']['local_policy']   = self::$active_asset_materialization_policy;
		self::$materialized_svg_assets  = array();
		self::$svg_sprite_symbols       = array();
		self::$materialized_svg_sprites = array();
		self::$button_wrapper_classes   = array();

		$document_pages = self::bac_document_pages( $compiled );
		if ( is_wp_error( $document_pages ) ) {
			return $document_pages;
		}

		$page_ids = self::create_page_shells( $document_pages );
		if ( is_wp_error( $page_ids ) ) {
			return $page_ids;
		}

		$permalinks     = self::page_permalinks( $page_ids );
		$route_map      = self::source_route_map( $document_pages, $permalinks );
		$page_artifacts = self::page_artifacts( $document_pages, $route_map, $theme_slug );

		$materialized = self::materialize_website_artifact_files_to_theme( $theme_dir, $artifacts );
		if ( is_wp_error( $materialized ) ) {
			return $materialized;
		}
		self::record_website_artifact_document_metadata( $artifacts );

		$template_part_writes = self::template_part_artifact_writes( $theme_dir, $artifacts );
		if ( is_wp_error( $template_part_writes ) ) {
			return $template_part_writes;
		}
		$has_footer_part      = isset( $template_part_writes[ $theme_dir . '/parts/footer.html' ] );

		$writes = array(
			$theme_dir . '/style.css'                   => self::style_css( $theme_name, $materialized['css'], array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/assets/css/editor-style.css' => self::editor_style_css( $materialized['css'], array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/functions.php'               => self::functions_php( $theme_slug ),
			$theme_dir . '/theme.json'                  => self::theme_json( $theme_name, $materialized['css'] ),
			$theme_dir . '/templates/front-page.html'   => self::content_template( '', $has_footer_part ),
			$theme_dir . '/templates/page.html'         => self::content_template( '', $has_footer_part ),
			$theme_dir . '/templates/index.html'        => self::content_template( '', $has_footer_part ),
		);
		$writes = array_merge( $writes, $template_part_writes );
		$result         = self::write_page_contents( $document_pages, $page_ids, $page_artifacts['contents'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::analyze_imported_page_content_documents( $document_pages, $page_artifacts['contents'] );

		self::record_bac_source_documents_summary( $artifacts['documents'] ?? array(), $document_pages, $page_ids, $permalinks );
		foreach ( array_keys( $page_artifacts['patterns'] ) as $filename ) {
			$page = $document_pages[ $filename ] ?? null;
			$slug = $page instanceof Static_Site_Importer_Source_Page ? self::page_slug( $filename, $page ) : self::page_slug( $filename );
			if ( '' === $slug ) {
				continue;
			}

			$writes[ $theme_dir . '/templates/page-' . $slug . '.html' ] = self::content_template( '', $has_footer_part );
		}

		if ( '' !== trim( $materialized['js'] ) ) {
			$writes[ $theme_dir . '/assets/site.js' ] = $materialized['js'];
		}

		self::analyze_generated_theme_block_documents( $writes, $theme_dir );
		self::$conversion_report['theme_slug'] = $theme_slug;
		self::record_product_seeding_report( $args );
		self::record_commerce_dependency_check( $args );
		$quality     = self::finalize_quality_report( $args );
		$summary     = self::import_report_summary( self::$conversion_report, $quality );
		self::$conversion_report['compact_summary'] = $summary;
		$report_json = wp_json_encode( self::$conversion_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $report_json ) {
			return new WP_Error( 'static_site_importer_report_encode_failed', 'Failed to encode import report JSON.' );
		}

		$writes[ $theme_dir . '/import-report.json' ] = $report_json . "\n";
		foreach ( $writes as $path => $content ) {
			$result = Static_Site_Importer_Theme_Materializer::write_file( $path, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$external_report_path = '';
		if ( isset( $args['report'] ) && '' !== trim( (string) $args['report'] ) ) {
			$external_report_path = (string) $args['report'];
			$result               = Static_Site_Importer_Theme_Materializer::write_external_report( $external_report_path, $report_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			$front_page_id = self::front_page_id( $page_ids );
			if ( 0 === $front_page_id && ! empty( $page_ids ) ) {
				$front_page_id = (int) reset( $page_ids );
			}

			switch_theme( $theme_slug );
			if ( 0 !== $front_page_id ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $front_page_id );
			}
		}

		return array(
			'theme_slug'            => $theme_slug,
			'theme_name'            => $theme_name,
			'theme_dir'             => $theme_dir,
			'report_path'           => $theme_dir . '/import-report.json',
			'external_report_path'  => $external_report_path,
			'import_report_summary' => $summary,
			'pages'                 => $page_ids,
			'quality'               => $quality,
			'source_documents'      => self::$conversion_report['source_documents'],
		);
	}

	/**
	 * Normalize BAC WordPress document artifacts into source pages.
	 *
	 * SSI consumes BAC's compiled-site page contract when available, then attaches
	 * the referenced `wordpress_artifacts.documents[]` block markup for WordPress
	 * materialization.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string, Static_Site_Importer_Source_Page>|WP_Error
	 */
	private static function bac_document_pages( array $compiled ) {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$documents = isset( $artifacts['documents'] ) && is_array( $artifacts['documents'] ) ? $artifacts['documents'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		if ( ! empty( $documents ) ) {
			if ( 'block-artifact-compiler/compiled-site/v1' !== (string) ( $site['schema'] ?? '' ) || ! isset( $site['pages'] ) || ! is_array( $site['pages'] ) ) {
				return new WP_Error( 'static_site_importer_bac_compiled_site_missing', 'BAC document artifacts require the block-artifact-compiler/compiled-site/v1 site contract.' );
			}

			$documents = self::bac_documents_from_compiled_site_pages( $site['pages'], $documents );
			if ( is_wp_error( $documents ) ) {
				return $documents;
			}
		}

		$pages     = array();

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact( $document );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$pages[ $page->source_key() ] = $page;
		}

		return $pages;
	}

	/**
	 * Attach BAC document markup to compiled-site page contracts.
	 *
	 * @param array<int,array<string,mixed>> $site_pages Compiled-site page rows.
	 * @param array<int,mixed>               $documents  BAC document artifacts.
	 * @return array<int,array<string,mixed>>|WP_Error Document artifacts ordered by compiled-site pages.
	 */
	private static function bac_documents_from_compiled_site_pages( array $site_pages, array $documents ) {
		$documents_by_source = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = isset( $document['source_path'] ) && is_scalar( $document['source_path'] ) ? (string) $document['source_path'] : '';
			if ( '' !== $source_path ) {
				$documents_by_source[ $source_path ] = $document;
			}
		}

		$compiled_documents = array();
		foreach ( $site_pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$source_path = isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '';
			if ( '' === $source_path ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_missing_source', 'BAC compiled-site page is missing source_path.' );
			}
			if ( ! isset( $documents_by_source[ $source_path ] ) ) {
				return new WP_Error( 'static_site_importer_compiled_site_page_missing_document', sprintf( 'BAC compiled-site page does not reference a document artifact: %s', $source_path ) );
			}

			$document = array_merge( $documents_by_source[ $source_path ], array_filter(
				array(
					'source_path' => $source_path,
					'slug'        => isset( $page['slug'] ) && is_scalar( $page['slug'] ) ? (string) $page['slug'] : '',
					'route_key'   => isset( $page['route_key'] ) && is_scalar( $page['route_key'] ) ? (string) $page['route_key'] : '',
					'post_type'   => isset( $page['post_type'] ) && is_scalar( $page['post_type'] ) ? (string) $page['post_type'] : '',
					'title'       => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
				),
				static fn ( string $value ): bool => '' !== $value
			) );

			if ( ! empty( $page['entrypoint'] ) ) {
				$document['entrypoint'] = '1';
			}

			$compiled_documents[] = $document;
		}

		return $compiled_documents;
	}

	/**
	 * Normalize BAC template part artifacts into generated theme writes.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return array<string,string>|WP_Error Absolute write paths keyed to serialized block markup.
	 */
	private static function template_part_artifact_writes( string $theme_dir, array $artifacts ) {
		$result = Static_Site_Importer_Theme_Materializer::template_part_artifact_writes( $theme_dir, $artifacts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['reports'] as $report ) {
			self::$conversion_report['generated_theme']['template_parts'][] = $report;
		}

		return $result['writes'];
	}

	/**
	 * Check whether a source filename is the site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_index_source_filename( string $filename ): bool {
		return in_array( strtolower( basename( $filename ) ), array( 'index.html', 'index.md', 'index.markdown' ), true );
	}

	/**
	 * Check whether a source filename is the root site index.
	 *
	 * @param string $filename Source filename.
	 * @return bool
	 */
	private static function is_root_index_source_filename( string $filename ): bool {
		return in_array( strtolower( trim( self::normalize_route_path( $filename ), '/' ) ), array( 'index.html', 'index.md', 'index.markdown' ), true );
	}

	/**
	 * Get imported front page ID for HTML or Markdown index sources.
	 *
	 * @param array<string,int> $page_ids Page IDs keyed by source filename.
	 * @return int
	 */
	private static function front_page_id( array $page_ids ): int {
		foreach ( $page_ids as $filename => $page_id ) {
			if ( self::is_index_source_filename( $filename ) ) {
				return (int) $page_id;
			}
		}

		return 0;
	}

	/**
	 * Create page shells so links can be rewritten before content conversion.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages Pages.
	 * @return array<string,int>|WP_Error
	 */
	private static function create_page_shells( array $pages ) {
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
	 * @param array<string,string>                          $route_map Route map.
	 * @param string                                                                    $theme_slug Theme slug.
	 * @return array{patterns:array<string,string>,files:array<string,string>,contents:array<string,string>}
	 */
	private static function page_artifacts( array $pages, array $route_map, string $theme_slug ): array {
		$patterns = array();
		$files    = array();
		$contents = array();

		foreach ( $pages as $filename => $page ) {
			$slug         = self::page_slug( $filename, $page );
			$pattern_slug = sanitize_key( $theme_slug ) . '/page-' . $slug;
			$content      = self::source_page_content_blocks( $page, $route_map );
			self::record_button_wrapper_classes_from_blocks( $content );

			$patterns[ $filename ] = $pattern_slug;
			$files[ $filename ]    = self::pattern_file( self::page_title( $filename, $page ), $pattern_slug, $content );
			$contents[ $filename ] = $content;
		}

		return array(
			'patterns' => $patterns,
			'files'    => $files,
			'contents' => $contents,
		);
	}

	/**
	 * Store imported page bodies on their corresponding WordPress pages.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages    Pages.
	 * @param array<string,int>                                                         $page_ids Page IDs keyed by filename.
	 * @param array<string,string>                                                      $contents Converted block markup keyed by filename.
	 * @return true|WP_Error
	 */
	private static function write_page_contents( array $pages, array $page_ids, array $contents ) {
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
	 * Analyze canonical imported page post content for conversion quality.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages    Pages.
	 * @param array<string,string>                           $contents Converted block markup keyed by filename.
	 * @return void
	 */
	private static function analyze_imported_page_content_documents( array $pages, array $contents ): void {
		foreach ( $pages as $filename => $page ) {
			$slug    = self::page_slug( $filename, $page );
			$source  = '' !== $slug ? 'posts/page-' . $slug . '.post_content' : 'posts/' . sanitize_title( $filename ) . '.post_content';
			$content = $contents[ $filename ] ?? '';
			if ( '' === trim( $content ) ) {
				continue;
			}

			$analysis = self::analyze_generated_block_document( $source, $content );
			self::$conversion_report['generated_theme']['block_documents'][] = $analysis;
		}
	}

	/**
	 * Build permalink map keyed by source filename.
	 *
	 * @param array<string,int> $page_ids Page IDs keyed by filename.
	 * @return array<string,string>
	 */
	private static function page_permalinks( array $page_ids ): array {
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
	 * Build a route map for source document links.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Pages.
	 * @param array<string,string>                          $permalinks Permalinks keyed by source path.
	 * @return array<string,string>
	 */
	private static function source_route_map( array $pages, array $permalinks ): array {
		$candidates = array();
		foreach ( $pages as $source_path => $page ) {
			$permalink = $permalinks[ $source_path ] ?? '';
			if ( '' === $permalink ) {
				continue;
			}

			foreach ( self::source_route_keys( $page->source_key() ) as $key ) {
				$candidates[ $key ][ $permalink ] = true;
			}
		}

		$route_map = array();
		foreach ( $candidates as $key => $matches ) {
			if ( 1 === count( $matches ) ) {
				$route_map[ $key ] = (string) array_key_first( $matches );
			}
		}

		return $route_map;
	}

	/**
	 * Build deterministic route keys for a discovered source path.
	 *
	 * @param string $relative_path Source-relative path.
	 * @return array<int,string>
	 */
	private static function source_route_keys( string $relative_path ): array {
		$relative_path = self::normalize_route_path( $relative_path );
		if ( '' === $relative_path ) {
			return array();
		}

		$extensionless = preg_replace( '/\.(?:html?|md|markdown)$/i', '', $relative_path );
		$extensionless = '' === trim( (string) $extensionless ) ? $relative_path : (string) $extensionless;
		$extensions    = array( 'html', 'htm', 'md', 'markdown' );
		$keys          = array( $relative_path, '/' . $relative_path, './' . $relative_path );

		foreach ( $extensions as $extension ) {
			$keys[] = $extensionless . '.' . $extension;
			$keys[] = '/' . $extensionless . '.' . $extension;
		}

		if ( preg_match( '#(^|/)index$#i', $extensionless ) ) {
			$clean  = preg_replace( '#(^|/)index$#i', '$1', $extensionless );
			$clean  = trim( (string) $clean, '/' );
			$keys[] = '' === $clean ? '/' : $clean;
			$keys[] = '' === $clean ? '/' : '/' . $clean . '/';
			if ( '' !== $clean ) {
				$keys[] = $clean . '/';
			}
		} else {
			$keys[] = $extensionless;
			$keys[] = '/' . $extensionless;
			$keys[] = $extensionless . '/';
			$keys[] = '/' . $extensionless . '/';
		}

		return array_values( array_unique( array_filter( $keys, static fn ( string $key ): bool => '' !== trim( $key ) ) ) );
	}

	/**
	 * Rewrite local document links to imported WordPress page permalinks.
	 *
	 * @param string               $html        HTML fragment.
	 * @param array<string,string> $route_map   Route map.
	 * @param string               $source_path Source-relative source path.
	 * @param string               $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_internal_links( string $html, array $route_map, string $source_path, string $source ): string {
		if ( '' === trim( $html ) || empty( $route_map ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/\bhref=("|\')([^"\']+)(\1)/i',
			static function ( array $matches ) use ( $route_map, $source_path, $source ): string {
				$href        = html_entity_decode( $matches[2], ENT_QUOTES );
				$replacement = self::resolve_route_href( $href, $source_path, $route_map );
				if ( null === $replacement ) {
					self::record_unresolved_internal_link( $source, $source_path, $href );
					return $matches[0];
				}

				return 'href=' . $matches[1] . esc_url( $replacement ) . $matches[3];
			},
			$html
		) ?? $html;
	}

	/**
	 * Rewrite local media URLs inside already-converted block markup.
	 *
	 * BAC document artifacts already contain block comments. DOM-based rewriting would
	 * discard those comments, so use focused attribute rewrites that preserve markup.
	 *
	 * @param string $markup      Block markup.
	 * @param string $source_path Source-relative source path.
	 * @param string $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_block_markup_local_asset_references( string $markup, string $source_path, string $source ): string {
		if ( '' === trim( $markup ) || ! preg_match( '/\b(?:src|poster|srcset)\s*=/i', $markup ) ) {
			return $markup;
		}

		$markup = preg_replace_callback(
			'/\b(src|poster)\s*=\s*("|\')([^"\']*)(\2)/i',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$url = self::normalize_block_markup_local_asset_url( html_entity_decode( (string) $matches[3], ENT_QUOTES ) );
				if ( '' === trim( $url ) || ! self::is_local_url( $url ) ) {
					return $matches[0];
				}

				$asset = self::resolve_local_asset_reference( $url, $source_path, $source );
				if ( null === $asset ) {
					return $matches[0];
				}

				return $matches[1] . '=' . $matches[2] . esc_url_raw( (string) $asset['url'] ) . $matches[4];
			},
			$markup
		) ?? $markup;

		return preg_replace_callback(
			'/\bsrcset\s*=\s*("|\')([^"\']*)(\1)/i',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$srcset    = html_entity_decode( (string) $matches[2], ENT_QUOTES );
				$rewritten = self::rewrite_srcset_asset_references( $srcset, $source_path, $source );
				if ( $rewritten === $srcset ) {
					return $matches[0];
				}

				return 'srcset=' . $matches[1] . esc_attr( $rewritten ) . $matches[3];
			},
			$markup
		) ?? $markup;
	}

	/**
	 * Normalize source-local paths that were serialized as URL-shaped block attrs.
	 *
	 * @param string $url Block attribute URL.
	 * @return string URL or source-local path.
	 */
	private static function normalize_block_markup_local_asset_url( string $url ): string {
		$url = trim( $url );
		if ( self::is_local_url( $url ) ) {
			return $url;
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || str_contains( $host, '.' ) || ! in_array( $host, array( 'assets', 'asset', 'img', 'imgs', 'image', 'images', 'media', 'static' ), true ) ) {
			return $url;
		}

		$path = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			return $url;
		}

		return $host . '/' . $path;
	}

	/**
	 * Ensure image block className attrs are reflected on the serialized figure.
	 *
	 * @param string $markup Serialized block markup.
	 * @return string Repaired markup.
	 */
	private static function repair_image_block_class_markup( string $markup ): string {
		if ( '' === trim( $markup ) || ! str_contains( $markup, '<!-- wp:image' ) || ! str_contains( $markup, '"className"' ) ) {
			return $markup;
		}

		return preg_replace_callback(
			'/<!--\s+wp:image\s+(\{.*?\})\s+-->\s*<figure\b([^>]*)class=("|\')([^"\']*)(\3)/s',
			static function ( array $matches ): string {
				$attrs = json_decode( html_entity_decode( (string) $matches[1], ENT_QUOTES ), true );
				if ( ! is_array( $attrs ) || empty( $attrs['className'] ) || ! is_string( $attrs['className'] ) ) {
					return $matches[0];
				}

				$class = (string) $matches[4];
				foreach ( preg_split( '/\s+/', trim( $attrs['className'] ) ) ?: array() as $token ) {
					$token = sanitize_html_class( $token );
					if ( '' !== $token ) {
						$class = self::append_class_token( $class, $token );
					}
				}

				return '<!-- wp:image ' . $matches[1] . ' --><figure' . $matches[2] . 'class=' . $matches[3] . esc_attr( $class ) . $matches[5];
			},
			$markup
		) ?? $markup;
	}

	/**
	 * Rewrite one srcset attribute value through local asset materialization.
	 *
	 * @param string $srcset      Source srcset attribute.
	 * @param string $source_path Source-relative source path.
	 * @param string $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_srcset_asset_references( string $srcset, string $source_path, string $source ): string {
		$candidates = array();
		$changed    = false;
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}

			$parts      = preg_split( '/\s+/', $candidate, 2 );
			$url        = $parts[0] ?? '';
			$descriptor = $parts[1] ?? '';
			$asset      = self::resolve_local_asset_reference( $url, $source_path, $source );
			if ( null !== $asset ) {
				$url     = $asset['url'];
				$changed = true;
			}

			$candidates[] = trim( $url . ( '' === $descriptor ? '' : ' ' . $descriptor ) );
		}

		return $changed ? implode( ', ', $candidates ) : $srcset;
	}

	/**
	 * Add resolved metadata attributes to media elements before conversion.
	 *
	 * @param DOMElement          $element Media element.
	 * @param array<string,mixed> $asset   Asset metadata.
	 * @return void
	 */
	private static function apply_asset_metadata_to_media_element( DOMElement $element, array $asset ): void {
		$attachment_id = isset( $asset['attachment_id'] ) ? (int) $asset['attachment_id'] : (int) ( $asset['id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			$element->setAttribute( 'data-id', (string) $attachment_id );
			$element->setAttribute( 'class', self::append_class_token( $element->getAttribute( 'class' ), 'wp-image-' . $attachment_id ) );
		}

		foreach ( array( 'width', 'height' ) as $dimension ) {
			if ( isset( $asset[ $dimension ] ) && (int) $asset[ $dimension ] > 0 && ! $element->hasAttribute( $dimension ) ) {
				$element->setAttribute( $dimension, (string) (int) $asset[ $dimension ] );
			}
		}

		if ( isset( $asset['alt'] ) && '' !== trim( (string) $asset['alt'] ) && '' === trim( $element->getAttribute( 'alt' ) ) ) {
			$element->setAttribute( 'alt', (string) $asset['alt'] );
		}
	}

	/**
	 * Strip static active classes from shared chrome before every page reuses it.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private static function strip_active_classes( string $html ): string {
		if ( '' === trim( $html ) || ! str_contains( $html, 'active' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/\sclass=("|\')([^"\']*)(\1)/i',
			static function ( array $matches ): string {
				$classes = preg_split( '/\s+/', trim( $matches[2] ) );
				$classes = false === $classes ? array() : $classes;
				$classes = array_values(
					array_filter(
						$classes,
						static fn ( string $class_name ): bool => 'active' !== $class_name
					)
				);

				if ( empty( $classes ) ) {
					return '';
				}

				return ' class=' . $matches[1] . implode( ' ', $classes ) . $matches[3];
			},
			$html
		) ?? $html;
	}

	/**
	 * Check whether an empty header/footer element is CSS-declared decorative chrome.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function is_empty_decorative_theme_part_element( DOMElement $element ): bool {
		if ( 'div' !== strtolower( $element->tagName ) || empty( self::$decorative_empty_group_classes ) ) {
			return false;
		}

		if ( '' !== trim( $element->textContent ) || ! empty( self::direct_element_children( $element ) ) ) {
			return false;
		}

		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
		$classes = false === $classes ? array() : $classes;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether direct text exists outside child elements.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function element_has_direct_non_whitespace_text( DOMElement $element ): bool {
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an element is pure navigation rather than branded chrome containing links.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function can_convert_element_to_navigation( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );
		if ( ! in_array( $tag, array( 'nav', 'ul', 'ol' ), true ) || self::element_has_direct_non_whitespace_text( $element ) ) {
			return false;
		}

		foreach ( self::direct_element_children( $element ) as $child ) {
			$child_tag = strtolower( $child->tagName );
			if ( 'nav' === $tag && in_array( $child_tag, array( 'ul', 'ol' ), true ) ) {
				continue;
			}

			if ( 'nav' === $tag && 'a' === $child_tag && self::is_simple_navigation_anchor( $child ) ) {
				continue;
			}

			if ( in_array( $tag, array( 'ul', 'ol' ), true ) && 'li' === $child_tag ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Check whether a navigation list's source classes must stay on the list owner.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function should_preserve_navigation_list_owner_classes( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );
		return in_array( $tag, array( 'ul', 'ol' ), true ) && '' !== trim( $element->getAttribute( 'class' ) ) && self::can_convert_element_to_navigation( $element );
	}

	/**
	 * Check whether a direct nav anchor is a menu item rather than branded chrome.
	 *
	 * @param DOMElement $element Anchor element.
	 * @return bool
	 */
	private static function is_simple_navigation_anchor( DOMElement $element ): bool {
		if ( preg_match( '/(^|[-_\s])(brand|logo)([-_\s]|$)/i', $element->getAttribute( 'class' ) ) ) {
			return false;
		}

		foreach ( self::direct_element_children( $element ) as $child ) {
			if ( 'span' !== strtolower( $child->tagName ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Detect decorative or interactive markup better preserved as raw HTML inside a smaller island.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function element_contains_svg_or_form( DOMElement $element ): bool {
		foreach ( array( 'svg', 'canvas', 'form', 'input', 'button', 'select', 'textarea' ) as $tag ) {
			if ( $element->getElementsByTagName( $tag )->length > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a dedicated link cluster should keep its source wrapper.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function is_link_cluster_container( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );
		if ( ! in_array( $tag, array( 'div', 'span' ), true ) || self::element_has_direct_non_whitespace_text( $element ) ) {
			return false;
		}

		$class = $element->getAttribute( 'class' );
		if ( ! preg_match( '/(^|[-_\s])(actions?|buttons?|cta|links?)([-_\s]|$)/i', $class ) ) {
			return false;
		}

		$children = self::direct_element_children( $element );
		if ( count( $children ) < 2 ) {
			return false;
		}

		foreach ( $children as $child ) {
			if ( 'a' !== strtolower( $child->tagName ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether classed theme chrome should keep source element ownership.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function should_preserve_theme_part_phrasing_element( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );
		if ( ! in_array( $tag, array( 'div', 'span' ), true ) || ! self::element_has_only_phrasing_content( $element ) ) {
			return false;
		}

		$class = trim( $element->getAttribute( 'class' ) );
		if ( '' === $class ) {
			return false;
		}

		return preg_match( '/(^|[-_\s])(brand|logo|wordmark|name|badge|meta|copy)([-_\s]|$)/i', $class ) === 1;
	}

	/**
	 * Check whether an element can be represented as one paragraph with inline markup.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function element_has_only_phrasing_content( DOMElement $element ): bool {
		if ( self::element_contains_svg_or_form( $element ) ) {
			return false;
		}

		if ( in_array( strtolower( $element->tagName ), array( 'header', 'footer', 'main', 'nav', 'section', 'article', 'aside' ), true ) ) {
			return false;
		}

		$has_content = false;
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$has_content = $has_content || '' !== trim( $child->textContent );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$has_content = true;
			if ( ! self::is_phrasing_element( $child ) ) {
				return false;
			}
		}

		return $has_content;
	}

	/**
	 * Check whether an element is valid phrasing content inside a paragraph.
	 *
	 * @param DOMElement $element Source element.
	 * @return bool
	 */
	private static function is_phrasing_element( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );
		if ( ! in_array( $tag, array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' ), true ) ) {
			return false;
		}

		foreach ( self::direct_element_children( $element ) as $child ) {
			if ( ! self::is_phrasing_element( $child ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize caller-supplied asset map entries by source-relative key.
	 *
	 * @param array<string, mixed> $asset_map Raw asset map.
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_asset_map( array $asset_map ): array {
		$normalized = array();
		foreach ( $asset_map as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				continue;
			}

			$path = self::normalize_asset_map_key( $key );
			if ( '' === $path ) {
				continue;
			}

			$normalized[ $path ] = $entry;
		}

		return $normalized;
	}

	/**
	 * Normalize and validate the local asset materialization policy.
	 *
	 * @param mixed $policy Raw policy value.
	 * @return string|WP_Error
	 */
	private static function normalize_asset_materialization_policy( $policy ) {
		$policy = is_string( $policy ) ? sanitize_key( $policy ) : '';
		if ( '' === $policy ) {
			return 'copy_to_theme';
		}

		if ( in_array( $policy, array( 'copy_to_theme', 'use_map' ), true ) ) {
			return $policy;
		}

		return new WP_Error( 'static_site_importer_invalid_asset_materialization_policy', 'Asset materialization policy must be one of: copy_to_theme, use_map.' );
	}

	/**
	 * Resolve a local source URL through the active asset map and record the lookup.
	 *
	 * @param string $url         Source URL or path.
	 * @param string $source_path Source-relative document path.
	 * @param string $source      Diagnostic source label.
	 * @return array{key:string,entry:array<string,mixed>}|null
	 */
	private static function resolve_asset_map_reference( string $url, string $source_path, string $source ): ?array {
		if ( empty( self::$active_asset_map ) || ! self::is_local_url( $url ) ) {
			return null;
		}

		$key = self::asset_map_lookup_key( $url, $source_path );
		if ( '' === $key ) {
			return null;
		}

		if ( isset( self::$active_asset_map[ $key ] ) ) {
			self::record_asset_map_lookup( 'resolved', $source, $source_path, $url, $key, self::$active_asset_map[ $key ] );
			return array(
				'key'   => $key,
				'entry' => self::$active_asset_map[ $key ],
			);
		}

		self::record_asset_map_lookup( 'unresolved', $source, $source_path, $url, $key );
		return null;
	}

	/**
	 * Convert a source URL/path to an asset-map key relative to the source root.
	 *
	 * @param string $url         Source URL or path.
	 * @param string $source_path Source-relative document path.
	 * @return string
	 */
	private static function asset_map_lookup_key( string $url, string $source_path ): string {
		$path = wp_parse_url( html_entity_decode( $url, ENT_QUOTES ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === trim( $path ) ) {
			return '';
		}

		$path = rawurldecode( $path );
		if ( str_starts_with( $path, '/' ) ) {
			return self::normalize_asset_map_key( $path );
		}

		$base = trim( dirname( self::normalize_route_path( $source_path ) ), './' );
		return self::normalize_asset_map_key( ( '.' === $base || '' === $base ? '' : $base . '/' ) . $path );
	}

	/**
	 * Normalize an asset-map key without allowing traversal outside the source root.
	 *
	 * @param string $path Asset path.
	 * @return string
	 */
	private static function normalize_asset_map_key( string $path ): string {
		$path     = str_replace( '\\', '/', html_entity_decode( $path, ENT_QUOTES ) );
		$path     = ltrim( $path, '/' );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				if ( empty( $segments ) ) {
					return '';
				}

				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Record asset map lookup diagnostics in the import report.
	 *
	 * @param string              $status      Lookup status.
	 * @param string              $source      Diagnostic source label.
	 * @param string              $source_path Source-relative document path.
	 * @param string              $url         Original URL.
	 * @param string              $key         Asset map key.
	 * @param array<string,mixed> $entry       Optional resolved map entry.
	 * @return void
	 */
	private static function record_asset_map_lookup( string $status, string $source, string $source_path, string $url, string $key, array $entry = array() ): void {
		if ( ! isset( self::$conversion_report['asset_map'] ) || ! is_array( self::$conversion_report['asset_map'] ) ) {
			self::$conversion_report['asset_map'] = array(
				'supplied'         => ! empty( self::$active_asset_map ),
				'entry_count'      => count( self::$active_asset_map ),
				'resolved_count'   => 0,
				'unresolved_count' => 0,
				'resolved'         => array(),
				'unresolved'       => array(),
			);
		}

		$row = array(
			'source'      => $source,
			'source_path' => $source_path,
			'url'         => $url,
			'key'         => $key,
		);

		if ( 'resolved' === $status ) {
			foreach ( array( 'url', 'attachment_id', 'mime_type', 'width', 'height', 'alt' ) as $field ) {
				if ( isset( $entry[ $field ] ) ) {
					$row[ $field ] = $entry[ $field ];
				}
			}

			self::$conversion_report['asset_map']['resolved'][] = $row;
			++self::$conversion_report['asset_map']['resolved_count'];
			return;
		}

		self::$conversion_report['asset_map']['unresolved'][] = $row;
		++self::$conversion_report['asset_map']['unresolved_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'        => 'asset_map_unresolved',
			'source'      => $source,
			'source_path' => $source_path,
			'url'         => $url,
			'key'         => $key,
			'message'     => 'Local image reference had no matching asset_map entry; leaving the source reference unchanged.',
		);
	}

	/**
	 * Resolve a source-local asset URL from the explicit map or BAC file artifacts.
	 *
	 * @param string $url         Source URL or path.
	 * @param string $source_path Source-relative document path.
	 * @param string $source      Diagnostic source label.
	 * @return array<string,mixed>|null
	 */
	private static function resolve_local_asset_reference( string $url, string $source_path, string $source ): ?array {
		if ( ! self::is_local_url( $url ) ) {
			return null;
		}

		$key = self::asset_map_lookup_key( $url, $source_path );
		if ( '' === $key ) {
			self::record_local_asset_diagnostic( 'local_asset_unsafe_path', $source, $source_path, $url, '', 'Local asset reference resolves outside the static source root; leaving it unchanged.' );
			return null;
		}

		if ( 'use_map' === self::$active_asset_materialization_policy ) {
			$mapped = self::resolve_asset_map_reference( $url, $source_path, $source );
			if ( null === $mapped ) {
				return null;
			}

			$entry = $mapped['entry'];
			if ( ! isset( $entry['url'] ) || '' === trim( (string) $entry['url'] ) ) {
				self::record_local_asset_diagnostic( 'asset_map_missing_url', $source, $source_path, $url, $key, 'Asset map entry did not provide a URL; leaving the source reference unchanged.' );
				return null;
			}

			$entry['url'] = esc_url_raw( (string) $entry['url'] );
			self::record_local_asset_outcome( 'mapped', $source, $source_path, $url, $key, $entry );
			self::remember_asset_metadata( $url, $key, $entry );
			return $entry;
		}

		if ( isset( self::$active_artifact_materialized_assets[ $key ] ) ) {
			$asset = self::$active_artifact_materialized_assets[ $key ];
			self::record_local_asset_outcome( 'copied', $source, $source_path, $url, $key, $asset );
			self::remember_asset_metadata( $url, $key, $asset );
			return $asset;
		}

		self::record_local_asset_diagnostic( 'local_asset_not_materialized', $source, $source_path, $url, $key, 'Local asset reference was not present in BAC file artifacts or the supplied asset_map; leaving it unchanged.' );
		return null;
	}

	/**
	 * Infer image dimensions when PHP can read them safely.
	 *
	 * @param string $path Absolute image path.
	 * @return array<string,int>
	 */
	private static function image_dimensions( string $path ): array {
		if ( ! function_exists( 'getimagesize' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Suppresses warnings from probing local image dimensions.
		set_error_handler( static fn(): bool => true );
		try {
			$size = getimagesize( $path );
		} finally {
			restore_error_handler();
		}
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return array();
		}

		return array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);
	}

	/**
	 * Add available alt text to cached asset metadata.
	 *
	 * @param string              $url   Original source reference.
	 * @param array<string,mixed> $asset Asset metadata.
	 * @param string              $alt   Alt text.
	 * @return array<string,mixed>
	 */
	private static function add_local_asset_alt_metadata( string $url, array $asset, string $alt ): array {
		$alt = trim( $alt );
		$key = isset( $asset['path'] ) ? (string) $asset['path'] : '';
		if ( '' === $alt || '' === $key ) {
			return $asset;
		}

		if ( isset( $asset['alt'] ) && '' !== trim( (string) $asset['alt'] ) ) {
			return $asset;
		}

		$asset['alt'] = $alt;
		if ( isset( $asset['attachment_id'] ) && (int) $asset['attachment_id'] > 0 && function_exists( 'update_post_meta' ) ) {
			update_post_meta( (int) $asset['attachment_id'], '_wp_attachment_image_alt', $alt );
		}

		self::remember_asset_metadata( $url, $key, $asset );

		return $asset;
	}

	/**
	 * Store metadata under all keys H2BC/BFB may see during conversion.
	 *
	 * @param string              $url   Original source reference.
	 * @param string              $key   Source-relative key.
	 * @param array<string,mixed> $asset Asset metadata.
	 * @return void
	 */
	private static function remember_asset_metadata( string $url, string $key, array $asset ): void {
		foreach ( array( $url, html_entity_decode( $url, ENT_QUOTES ), $key, '/' . ltrim( $key, '/' ), $asset['url'] ?? '' ) as $metadata_key ) {
			$metadata_key = trim( (string) $metadata_key );
			if ( '' !== $metadata_key ) {
				self::$active_asset_metadata[ $metadata_key ] = $asset;
			}
		}
	}

	/**
	 * Record a local asset policy outcome row in the import report.
	 *
	 * @param string              $source      Diagnostic source label.
	 * @param string              $source_path Source-relative source path.
	 * @param string              $url         Original URL.
	 * @param string              $key         Source-relative key.
	 * @param array<string,mixed> $asset       Asset metadata.
	 * @return void
	 */
	private static function record_local_asset_outcome( string $outcome, string $source, string $source_path, string $url, string $key, array $asset ): void {
		if ( ! isset( self::$conversion_report['assets']['local'] ) || ! is_array( self::$conversion_report['assets']['local'] ) ) {
			self::$conversion_report['assets']['local'] = array();
		}

		$report_key = self::$active_asset_materialization_policy . ':' . (string) ( $asset['policy'] ?? 'theme' ) . ':' . $key;
		if ( isset( self::$recorded_local_asset_keys[ $report_key ] ) ) {
			return;
		}
		self::$recorded_local_asset_keys[ $report_key ] = true;

		$row = array(
			'source'                 => $source,
			'source_path'            => $source_path,
			'url'                    => $url,
			'key'                    => $key,
			'policy'                 => $asset['policy'] ?? 'theme',
			'materialization_policy' => self::$active_asset_materialization_policy,
			'outcome'                => $outcome,
			'rewritten_url'          => $asset['url'] ?? '',
			'theme_path'             => $asset['theme_path'] ?? '',
			'final_url'              => $asset['url'] ?? '',
			'mime_type'              => $asset['mime_type'] ?? '',
		);

		foreach ( array( 'id', 'attachment_id', 'width', 'height', 'alt' ) as $field ) {
			if ( isset( $asset[ $field ] ) ) {
				$row[ $field ] = $asset[ $field ];
			}
		}

		self::$conversion_report['assets']['local'][] = $row;
	}

	/**
	 * Record an actionable local asset diagnostic.
	 *
	 * @param string $type        Diagnostic type.
	 * @param string $source      Diagnostic source label.
	 * @param string $source_path Source-relative source path.
	 * @param string $url         Original URL.
	 * @param string $key         Source-relative key.
	 * @param string $message     Diagnostic message.
	 * @return void
	 */
	private static function record_local_asset_diagnostic( string $type, string $source, string $source_path, string $url, string $key, string $message ): void {
		self::$conversion_report['diagnostics'][] = array(
			'type'                   => $type,
			'category'               => 'unresolved_asset',
			'suggested_repair_class' => 'materialize_or_rewrite_asset',
			'source'                 => $source,
			'source_path'            => $source_path,
			'url'                    => $url,
			'key'                    => $key,
			'message'                => $message,
		);
	}

	/**
	 * Build a reference to a deterministic wp_navigation entity.
	 *
	 * @param DOMElement $element    Navigation source element.
	 * @param string     $theme_slug Imported theme slug.
	 * @param string     $location   Navigation location name.
	 * @return string|null
	 */
	private static function navigation_ref_block( DOMElement $element, string $theme_slug, string $location ): ?string {
		$links = self::navigation_links_from_element( $element );
		if ( empty( $links ) ) {
			return null;
		}

		$navigation_id = self::upsert_navigation_post( $theme_slug, $location, $links );
		if ( is_wp_error( $navigation_id ) ) {
			return null;
		}

		$attrs = array( 'ref' => (int) $navigation_id );
		$class = trim( $element->getAttribute( 'class' ) );
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}

		return '<!-- wp:navigation ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
	}

	/**
	 * Extract top-level navigation links from a nav or list element.
	 *
	 * @param DOMElement $element Navigation source element.
	 * @return array<int, array{label:string,url:string,className?:string}>
	 */
	private static function navigation_links_from_element( DOMElement $element ): array {
		$links = array();
		foreach ( $element->getElementsByTagName( 'a' ) as $anchor ) {
			if ( '' === trim( $anchor->getAttribute( 'href' ) ) ) {
				continue;
			}

			$link  = array(
				'label' => trim( $anchor->textContent ),
				'url'   => esc_url_raw( $anchor->getAttribute( 'href' ) ),
			);
			$class = trim( $anchor->getAttribute( 'class' ) );
			if ( '' !== $class ) {
				$link['className'] = $class;
			}

			if ( '' !== $link['label'] && '' !== $link['url'] ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * Create or update a deterministic wp_navigation post.
	 *
	 * @param string                                                        $theme_slug Imported theme slug.
	 * @param string                                                        $location   Navigation location name.
	 * @param array<int, array{label:string,url:string,className?:string}> $links      Navigation links.
	 * @return int|WP_Error
	 */
	private static function upsert_navigation_post( string $theme_slug, string $location, array $links ) {
		if ( ! post_type_exists( 'wp_navigation' ) ) {
			return new WP_Error( 'static_site_importer_missing_navigation_post_type', 'The wp_navigation post type is not available.' );
		}

		$slug     = sanitize_title( $theme_slug . '-' . $location . '-navigation' );
		$existing = get_page_by_path( $slug, OBJECT, 'wp_navigation' );
		$postarr  = array(
			'post_title'   => ucwords( str_replace( '-', ' ', $theme_slug ) ) . ' ' . ucfirst( $location ) . ' Navigation',
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'wp_navigation',
			'post_content' => self::navigation_post_content( $links ),
		);

		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = $existing->ID;
		}

		return wp_insert_post( $postarr, true );
	}

	/**
	 * Build wp_navigation entity content from link data.
	 *
	 * @param array<int, array{label:string,url:string,className?:string}> $links Navigation links.
	 * @return string
	 */
	private static function navigation_post_content( array $links ): string {
		$blocks = array();
		foreach ( $links as $link ) {
			$attrs = array(
				'label'          => $link['label'],
				'url'            => $link['url'],
				'kind'           => 'custom',
				'isTopLevelLink' => true,
			);
			if ( ! empty( $link['className'] ) ) {
				$attrs['className'] = $link['className'];
			}

			$blocks[] = '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Parse an HTML fragment into a wrapper document.
	 *
	 * @param string $html HTML fragment.
	 * @return DOMDocument
	 */
	private static function load_fragment_document( string $html ): DOMDocument {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div data-static-site-importer-root="1">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $doc;
	}

	/**
	 * Get the only direct child element from a wrapped fragment document.
	 *
	 * @param DOMDocument $doc DOM document.
	 * @return DOMElement|null
	 */
	private static function sole_child_element( DOMDocument $doc ): ?DOMElement {
		$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		$children = self::direct_element_children( $root );
		return 1 === count( $children ) ? $children[0] : null;
	}

	/**
	 * Get direct element children.
	 *
	 * @param DOMElement $element Element.
	 * @return array<int, DOMElement>
	 */
	private static function direct_element_children( DOMElement $element ): array {
		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Serialize a DOM element.
	 *
	 * @param DOMDocument $doc  DOM document.
	 * @param DOMElement  $node Element.
	 * @return string
	 */
	private static function node_html( DOMDocument $doc, DOMElement $node ): string {
		$html = $doc->saveHTML( $node );
		return false === $html ? '' : $html;
	}

	/**
	 * Serialize a DOM element's children.
	 *
	 * @param DOMDocument $doc  DOM document.
	 * @param DOMElement  $node Element.
	 * @return string
	 */
	private static function node_inner_html( DOMDocument $doc, DOMElement $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$fragment = $doc->saveHTML( $child );
			if ( false !== $fragment ) {
				$html .= $fragment;
			}
		}

		return trim( $html );
	}

	/**
	 * Convert one source page body to blocks after shared link rewriting.
	 *
	 * @param Static_Site_Importer_Source_Page $page      Source page.
	 * @param array<string,string>             $route_map Route map.
	 * @return string
	 */
	private static function source_page_content_blocks( Static_Site_Importer_Source_Page $page, array $route_map ): string {
		$source_path = $page->source_key();
		$source      = 'main:' . $source_path;
		$body        = self::route_external_script_tags_from_page_body( $page->body(), $source_path, $source );
		$body        = self::rewrite_internal_links( $body, $route_map, $source_path, $source );
		if ( 'blocks' !== $page->body_format() ) {
			self::$conversion_report['diagnostics'][] = array(
				'type'        => 'unsupported_document_artifact_format',
				'source'      => $source,
				'source_path' => $source_path,
				'format'      => $page->body_format(),
				'message'     => 'Website artifact imports require BAC document artifacts with serialized block markup.',
			);
			return '';
		}

		$body = self::rewrite_block_markup_local_asset_references( $body, $source_path, $source );
		$body = self::repair_image_block_class_markup( $body );
		return trim( $body );
	}

	/**
	 * Route generated external script references out of editable page content.
	 *
	 * @param string $body        Page body or block markup.
	 * @param string $source_path Source-relative document path.
	 * @param string $source      Diagnostic source label.
	 * @return string Body with external script elements removed.
	 */
	private static function route_external_script_tags_from_page_body( string $body, string $source_path, string $source ): string {
		if ( '' === trim( $body ) || stripos( $body, '<script' ) === false ) {
			return $body;
		}

		$routed = preg_replace_callback(
			'/<script\b([^>]*)>\s*<\/script>/is',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$attributes = self::parse_simple_html_attributes( $matches[1] ?? '' );
				$src        = isset( $attributes['src'] ) ? trim( (string) $attributes['src'] ) : '';
				if ( '' === $src ) {
					return $matches[0];
				}

				self::record_page_body_script_asset( $src, $source_path, $source, $attributes );
				return '';
			},
			$body
		) ?? $body;

		return self::remove_empty_core_html_blocks( $routed );
	}

	/**
	 * Remove empty raw-HTML block wrappers left after routing script tags.
	 *
	 * @param string $body Block markup.
	 * @return string Block markup without empty core/html wrappers.
	 */
	private static function remove_empty_core_html_blocks( string $body ): string {
		return preg_replace( '/<!--\s+wp:html\s+-->\s*<!--\s+\/wp:html\s+-->/i', '', $body ) ?? $body;
	}

	/**
	 * Parse a conservative HTML attribute string.
	 *
	 * @param string $attribute_string Raw tag attribute string.
	 * @return array<string,string|bool>
	 */
	private static function parse_simple_html_attributes( string $attribute_string ): array {
		$attributes = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name  = strtolower( $match[1] );
				$value = true;
				if ( isset( $match[3] ) && '' !== $match[3] ) {
					$value = $match[3];
				} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
					$value = $match[4];
				} elseif ( isset( $match[5] ) && '' !== $match[5] ) {
					$value = $match[5];
				}

				$attributes[ $name ] = is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) : $value;
			}
		}

		return $attributes;
	}

	/**
	 * Record a script reference routed out of page block content.
	 *
	 * @param string                    $src         Script source.
	 * @param string                    $source_path Source-relative document path.
	 * @param string                    $source      Diagnostic source label.
	 * @param array<string,string|bool> $attributes  Parsed script attributes.
	 */
	private static function record_page_body_script_asset( string $src, string $source_path, string $source, array $attributes ): void {
		if ( ! isset( self::$conversion_report['assets']['scripts'] ) || ! is_array( self::$conversion_report['assets']['scripts'] ) ) {
			self::$conversion_report['assets']['scripts'] = array();
		}

		$asset = self::resolve_local_asset_reference( $src, $source_path, $source );
		$row   = array(
			'source'      => $source,
			'source_path' => $source_path,
			'src'         => esc_url_raw( $src ),
			'placement'   => 'page_body',
			'routed_to'   => 'theme_asset_metadata',
			'attributes'  => array_intersect_key( $attributes, array_fill_keys( array( 'type', 'defer', 'async', 'crossorigin', 'integrity' ), true ) ),
		);

		if ( null !== $asset ) {
			$row['asset'] = $asset;
		}

		self::$conversion_report['assets']['scripts'][] = $row;
	}

	/**
	 * Build a WordPress page title from a source document.
	 *
	 * @param string                           $filename Source filename.
	 * @param Static_Site_Importer_Source_Page $page     Source page.
	 * @return string
	 */
	private static function page_title( string $filename, Static_Site_Importer_Source_Page $page ): string {
		$title = $page->metadata_value( 'title' );
		if ( '' !== trim( $title ) ) {
			return sanitize_text_field( $title );
		}

		if ( self::is_root_index_source_filename( $filename ) ) {
			return 'Home';
		}

		$title = preg_replace( '/\s+[—-]\s+.+$/u', '', $page->document()->title() );
		if ( '' !== trim( (string) $title ) ) {
			return trim( (string) $title );
		}

		if ( 'markdown' === $page->type() && preg_match( '/^#\s+(.+)$/m', $page->body(), $matches ) ) {
			return trim( $matches[1] );
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
	private static function page_slug( string $filename, ?Static_Site_Importer_Source_Page $page = null ): string {
		if ( $page instanceof Static_Site_Importer_Source_Page && 'bac_document' === $page->type() && self::is_index_source_filename( $filename ) && filter_var( $page->metadata_value( 'entrypoint' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 'home';
		}

		if ( $page instanceof Static_Site_Importer_Source_Page && '' !== trim( $page->metadata_value( 'slug' ) ) ) {
			$slug = sanitize_title( $page->metadata_value( 'slug' ) );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		$extensionless = preg_replace( '/\.(?:html?|md|markdown)$/i', '', self::normalize_route_path( $filename ) );
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
	private static function page_status( Static_Site_Importer_Source_Page $page ): string {
		$status = sanitize_key( $page->metadata_value( 'status' ) );

		return in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish';
	}

	/**
	 * Build a safe WordPress post type from source metadata.
	 *
	 * @param Static_Site_Importer_Source_Page $page Source page.
	 * @return string
	 */
	private static function page_post_type( Static_Site_Importer_Source_Page $page ): string {
		$post_type = sanitize_key( $page->metadata_value( 'post_type' ) );
		if ( '' === $post_type ) {
			return 'page';
		}

		$post_type_object = get_post_type_object( $post_type );
		return $post_type_object instanceof WP_Post_Type ? $post_type : 'page';
	}

	/**
	 * Collect inline and linked local CSS.
	 *
	 * @param string                        $site_dir Site directory.
	 * @param Static_Site_Importer_Document $document          Source document.
	 * @param string                        $inline_source_path Source-relative path for inline style URL resolution.
	 * @return string
	 */
	private static function site_css( string $site_dir, Static_Site_Importer_Document $document, string $inline_source_path = 'index.html' ): string {
		$css           = array();
		$real_site_dir = realpath( $site_dir );
		$real_site_dir = false === $real_site_dir ? $site_dir : $real_site_dir;
		foreach ( $document->stylesheet_hrefs() as $href ) {
			$href_path = strtok( $href, '?' );
			$path      = realpath( trailingslashit( $site_dir ) . ltrim( false === $href_path ? $href : $href_path, '/' ) );
			if ( false === $path || ! str_starts_with( $path, $real_site_dir ) || ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local static-site stylesheet files from the import directory.
			$contents = file_get_contents( $path );
			if ( false !== $contents ) {
				$source_path = ltrim( str_replace( '\\', '/', substr( $path, strlen( trailingslashit( $real_site_dir ) ) ) ), '/' );
				$css[]       = trim( self::rewrite_css_asset_references( $contents, $source_path, 'stylesheet:' . $source_path ) );
			}
		}

		$inline = $document->inline_css();
		if ( '' !== $inline ) {
			$css[] = self::rewrite_css_asset_references( $inline, $inline_source_path, 'inline-style:' . $inline_source_path );
		}

		return trim( implode( "\n\n", array_filter( $css ) ) );
	}

	/**
	 * Rewrite local CSS url(...) references through local asset materialization.
	 *
	 * @param string $css         CSS source.
	 * @param string $source_path Source-relative stylesheet path.
	 * @param string $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_css_asset_references( string $css, string $source_path, string $source ): string {
		if ( '' === trim( $css ) || ! str_contains( strtolower( $css ), 'url(' ) ) {
			return $css;
		}

		return preg_replace_callback(
			'/url\(\s*(["\']?)(.*?)\1\s*\)/i',
			static function ( array $matches ) use ( $source_path, $source ): string {
				$url = trim( html_entity_decode( (string) $matches[2], ENT_QUOTES ) );
				if ( '' === $url || ! self::is_local_url( $url ) ) {
					return $matches[0];
				}

				$asset = self::resolve_local_asset_reference( $url, $source_path, $source );
				if ( null === $asset ) {
					return $matches[0];
				}

				return 'url("' . esc_url_raw( (string) $asset['url'] ) . '")';
			},
			$css
		) ?? $css;
	}

	/**
	 * Resolve a local link href through the source route map.
	 *
	 * @param string               $href        Link href.
	 * @param string               $source_path Source-relative source path.
	 * @param array<string,string> $route_map   Route map.
	 * @return string|null
	 */
	private static function resolve_route_href( string $href, string $source_path, array $route_map ): ?string {
		if ( ! self::is_local_url( $href ) || str_starts_with( $href, '#' ) ) {
			return null;
		}

		$parts      = wp_parse_url( $href );
		$path       = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query      = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$fragment   = isset( $parts['fragment'] ) ? (string) $parts['fragment'] : '';
		$lookup_key = str_starts_with( $path, '/' ) ? self::normalize_route_path( $path ) : self::normalize_route_path( dirname( $source_path ) . '/' . $path );

		$keys = array( $lookup_key, '/' . $lookup_key );
		if ( str_ends_with( $path, '/' ) ) {
			$keys[] = trailingslashit( $lookup_key );
			$keys[] = '/' . trailingslashit( $lookup_key );
		}

		foreach ( array_values( array_unique( $keys ) ) as $key ) {
			if ( isset( $route_map[ $key ] ) ) {
				$replacement = $route_map[ $key ];
				if ( '' !== $query ) {
					$replacement = add_query_arg( array(), $replacement ) . '?' . $query;
				}
				if ( '' !== $fragment ) {
					$replacement .= '#' . $fragment;
				}

				return $replacement;
			}
		}

		return null;
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

	/**
	 * Check whether a URL is local to the imported source tree.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	private static function is_local_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return false;
		}

		$lower = strtolower( $url );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $lower ) || str_starts_with( $lower, '//' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Materialize safe inline SVG elements as generated theme assets.
	 *
	 * @param string $html   HTML fragment.
	 * @param string $source Source fragment label.
	 * @return string
	 */
	private static function materialize_inline_svg_icons( string $html, string $source ): string {
		if ( '' === trim( $html ) || ! str_contains( strtolower( $html ), '<svg' ) || '' === self::$active_theme_dir ) {
			return $html;
		}

		$doc      = self::load_fragment_document( $html );
		$svgs     = iterator_to_array( $doc->getElementsByTagName( 'svg' ) );
		$changed  = false;
		$sequence = 0;

		foreach ( $svgs as $svg ) {
			if ( ! $svg->parentNode instanceof DOMNode ) {
				continue;
			}

			++$sequence;
			$svg_html = self::node_html( $doc, $svg );
			$sprite   = self::sanitize_svg_symbol_sprite( $svg_html );
			if ( null !== $sprite ) {
				$asset = self::write_svg_sprite_asset( $sprite['svg'], $sprite['symbols'], $source, $sequence );
				if ( is_wp_error( $asset ) ) {
					self::record_svg_materialization_failure( $source, $svg_html, $asset );
					continue;
				}

				$svg->parentNode->removeChild( $svg );
				$changed = true;
				continue;
			}

			$sprite_use_svg = self::svg_from_sprite_use_reference( $doc, $svg );
			if ( null !== $sprite_use_svg ) {
				$asset = self::write_svg_icon_asset( $sprite_use_svg, $source, $sequence, 'svg_symbol_use' );
				if ( is_wp_error( $asset ) ) {
					self::record_svg_materialization_failure( $source, $svg_html, $asset );
					continue;
				}

				$img = self::image_node_for_svg_asset( $doc, $svg, $asset );
				$svg->parentNode->replaceChild( $img, $svg );
				$changed = true;
				continue;
			}

			if ( self::is_local_svg_use_reference( $svg ) ) {
				self::record_svg_sprite_reference_failure( $source, $svg_html, 'missing_symbol' );
				continue;
			}

			$safe_svg = self::sanitize_inline_svg( $svg_html );
			if ( null === $safe_svg ) {
				self::record_unsafe_inline_svg( $source, $svg_html );
				continue;
			}

			$asset = self::write_svg_icon_asset( $safe_svg, $source, $sequence, 'inline_svg_icon' );
			if ( is_wp_error( $asset ) ) {
				self::record_svg_materialization_failure( $source, $svg_html, $asset );
				continue;
			}

			$img = self::image_node_for_svg_asset( $doc, $svg, $asset );
			$svg->parentNode->replaceChild( $img, $svg );
			$changed = true;
		}

		if ( ! $changed ) {
			return $html;
		}

		$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
			return $html;
		}

		$output = '';
		foreach ( $root->childNodes as $child ) {
			$fragment = $doc->saveHTML( $child );
			if ( false !== $fragment ) {
				$output .= $fragment;
			}
		}

		return '' === trim( $output ) ? $html : $output;
	}

	/**
	 * Discover sprites before converting chrome so header/footer use references can resolve.
	 *
	 * @param array{background:string,header:string,main:string,footer:string} $entry_fragments Entry document fragments.
	 * @param array<string, Static_Site_Importer_Source_Page>                  $pages           Imported pages.
	 * @return void
	 */
	private static function pre_register_svg_symbol_sprites( array $entry_fragments, array $pages ): void {
		foreach ( $entry_fragments as $name => $html ) {
			self::register_svg_symbol_sprites_from_html( (string) $html, $name . ':index.html' );
		}

		foreach ( $pages as $filename => $page ) {
			$fragments = $page->document()->fragments();
			self::register_svg_symbol_sprites_from_html( $fragments['main'], 'main:' . $filename );
		}
	}

	/**
	 * Register safe symbol sprites from a fragment without mutating that fragment.
	 *
	 * @param string $html   HTML fragment.
	 * @param string $source Source fragment label.
	 * @return void
	 */
	private static function register_svg_symbol_sprites_from_html( string $html, string $source ): void {
		if ( '' === trim( $html ) || ! str_contains( strtolower( $html ), '<symbol' ) || '' === self::$active_theme_dir ) {
			return;
		}

		$doc      = self::load_fragment_document( $html );
		$svgs     = iterator_to_array( $doc->getElementsByTagName( 'svg' ) );
		$sequence = 0;
		foreach ( $svgs as $svg ) {
			++$sequence;
			$svg_html = self::node_html( $doc, $svg );
			$sprite   = self::sanitize_svg_symbol_sprite( $svg_html );
			if ( null === $sprite ) {
				continue;
			}

			$asset = self::write_svg_sprite_asset( $sprite['svg'], $sprite['symbols'], $source, $sequence );
			if ( is_wp_error( $asset ) ) {
				self::record_svg_materialization_failure( $source, $svg_html, $asset );
			}
		}
	}

	/**
	 * Build an image node for a materialized SVG asset while preserving visual hooks.
	 *
	 * @param DOMDocument                $doc              Fragment document.
	 * @param DOMElement                 $svg              Source SVG element.
	 * @param array<string, string>      $asset            Materialized asset metadata.
	 * @return DOMElement
	 */
	private static function image_node_for_svg_asset( DOMDocument $doc, DOMElement $svg, array $asset ): DOMElement {
		$img = $doc->createElement( 'img' );
		$img->setAttribute( 'src', $asset['url'] );
		$img->setAttribute( 'alt', self::svg_accessible_label( $svg ) );
		if ( $svg->hasAttribute( 'class' ) ) {
			$img->setAttribute( 'class', $svg->getAttribute( 'class' ) );
		}
		foreach ( array( 'aria-hidden', 'role' ) as $attribute ) {
			if ( $svg->hasAttribute( $attribute ) ) {
				$img->setAttribute( $attribute, $svg->getAttribute( $attribute ) );
			}
		}

		return $img;
	}

	/**
	 * Sanitize an inline SVG symbol sprite and return symbol metadata.
	 *
	 * @param string $svg_html SVG markup.
	 * @return array{svg:string,symbols:array<string,array<string,string>>}|null Sanitized sprite, or null when not a safe sprite.
	 */
	private static function sanitize_svg_symbol_sprite( string $svg_html ): ?array {
		if ( ! str_contains( strtolower( $svg_html ), '<symbol' ) ) {
			return null;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( trim( $svg_html ), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement instanceof DOMElement || 'svg' !== strtolower( $doc->documentElement->tagName ) ) {
			return null;
		}

		$allowed_tags = array_fill_keys(
			array( 'svg', 'symbol', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'title', 'desc' ),
			true
		);
		$symbols      = array();
		foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
			if ( ! isset( $allowed_tags[ $node->tagName ] ) ) {
				return null;
			}

			foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
				$name  = $attribute->name;
				$lower = strtolower( $name );
				$value = $attribute->value;
				if ( 'style' === $lower && self::is_hidden_svg_sprite_style( $value ) ) {
					$node->removeAttribute( $name );
					continue;
				}

				if ( str_starts_with( $lower, 'on' ) || preg_match( '/(?:javascript:|data:|url\s*\(|href\s*=)/i', $value ) ) {
					return null;
				}

				if ( ! in_array( $name, array( 'xmlns', 'id', 'viewBox', 'viewbox', 'width', 'height', 'fill', 'fill-opacity', 'stroke', 'stroke-opacity', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points', 'transform', 'opacity', 'class', 'role', 'aria-hidden', 'aria-label', 'focusable' ), true ) ) {
					return null;
				}
			}
		}

		foreach ( $doc->getElementsByTagName( 'symbol' ) as $symbol ) {
			$id = trim( $symbol->getAttribute( 'id' ) );
			if ( '' === $id || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $id ) ) {
				return null;
			}

			$view_box       = $symbol->hasAttribute( 'viewBox' ) ? $symbol->getAttribute( 'viewBox' ) : $symbol->getAttribute( 'viewbox' );
			$symbols[ $id ] = array(
				'id'      => $id,
				'viewBox' => $view_box,
				'inner'   => self::node_inner_html( $doc, $symbol ),
			);
		}

		if ( empty( $symbols ) ) {
			return null;
		}

		if ( ! $doc->documentElement->hasAttribute( 'xmlns' ) ) {
			$doc->documentElement->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}

		$svg = $doc->saveXML( $doc->documentElement );
		return false === $svg ? null : array(
			'svg'     => $svg,
			'symbols' => $symbols,
		);
	}

	/**
	 * Check whether a sprite style only hides the symbol definitions from layout.
	 *
	 * @param string $style Style attribute value.
	 * @return bool
	 */
	private static function is_hidden_svg_sprite_style( string $style ): bool {
		$declarations = array_filter( array_map( 'trim', explode( ';', strtolower( $style ) ) ) );
		if ( empty( $declarations ) ) {
			return false;
		}

		foreach ( $declarations as $declaration ) {
			if ( ! in_array( $declaration, array( 'display:none', 'display: none', 'visibility:hidden', 'visibility: hidden' ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve a local SVG use reference into a standalone safe SVG icon asset payload.
	 *
	 * @param DOMDocument $doc Fragment document.
	 * @param DOMElement  $svg Source SVG element.
	 * @return string|null Standalone SVG, or null when not backed by an extracted sprite symbol.
	 */
	private static function svg_from_sprite_use_reference( DOMDocument $doc, DOMElement $svg ): ?string {
		$href = self::local_svg_use_href( $svg );
		if ( null === $href || ! isset( self::$svg_sprite_symbols[ $href ] ) ) {
			return null;
		}

		$symbol = self::$svg_sprite_symbols[ $href ];
		$attrs  = array(
			'xmlns' => 'http://www.w3.org/2000/svg',
		);
		foreach ( array( 'viewBox', 'width', 'height', 'fill', 'stroke', 'role', 'aria-hidden', 'aria-label', 'class' ) as $attribute ) {
			if ( $svg->hasAttribute( $attribute ) ) {
				$attrs[ $attribute ] = $svg->getAttribute( $attribute );
			}
		}
		if ( empty( $attrs['viewBox'] ) && ! empty( $symbol['viewBox'] ) ) {
			$attrs['viewBox'] = $symbol['viewBox'];
		}

		$markup = '<svg';
		foreach ( $attrs as $name => $value ) {
			if ( '' !== trim( $value ) ) {
				$markup .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		$markup .= '>' . $symbol['inner'] . '</svg>';

		return self::sanitize_inline_svg( $markup );
	}

	/**
	 * Check whether an SVG is a local symbol use reference.
	 *
	 * @param DOMElement $svg Source SVG element.
	 * @return bool
	 */
	private static function is_local_svg_use_reference( DOMElement $svg ): bool {
		return null !== self::local_svg_use_href( $svg );
	}

	/**
	 * Extract a single local symbol href from an SVG use-reference icon.
	 *
	 * @param DOMElement $svg Source SVG element.
	 * @return string|null Symbol id without the # prefix.
	 */
	private static function local_svg_use_href( DOMElement $svg ): ?string {
		$uses = $svg->getElementsByTagName( 'use' );
		if ( 1 !== $uses->length ) {
			return null;
		}

		$use = $uses->item( 0 );
		if ( ! $use instanceof DOMElement ) {
			return null;
		}

		$href = trim( $use->getAttribute( 'href' ) );
		if ( '' === $href ) {
			$href = trim( $use->getAttribute( 'xlink:href' ) );
		}

		if ( ! str_starts_with( $href, '#' ) ) {
			return null;
		}

		$id = substr( $href, 1 );
		return preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $id ) ? $id : null;
	}

	/**
	 * Validate an inline SVG against a conservative icon-safe subset.
	 *
	 * @param string $svg_html SVG markup.
	 * @return string|null
	 */
	private static function sanitize_inline_svg( string $svg_html ): ?string {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( trim( $svg_html ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $doc->documentElement instanceof DOMElement || 'svg' !== strtolower( $doc->documentElement->tagName ) ) {
			return null;
		}

		$allowed_tags       = array_fill_keys(
			array(
				'svg',
				'g',
				'path',
				'circle',
				'rect',
				'line',
				'polyline',
				'polygon',
				'ellipse',
				'title',
				'desc',
				'defs',
				'clipPath',
				'linearGradient',
				'radialGradient',
				'stop',
			),
			true
		);
		$allowed_attributes = array_fill_keys(
			array(
				'xmlns',
				'viewBox',
				'viewbox',
				'width',
				'height',
				'fill',
				'fill-opacity',
				'stroke',
				'stroke-opacity',
				'stroke-width',
				'stroke-linecap',
				'stroke-linejoin',
				'stroke-miterlimit',
				'stroke-dasharray',
				'stroke-dashoffset',
				'd',
				'cx',
				'cy',
				'r',
				'rx',
				'ry',
				'x',
				'y',
				'x1',
				'y1',
				'x2',
				'y2',
				'points',
				'transform',
				'opacity',
				'preserveAspectRatio',
				'preserveaspectratio',
				'fill-rule',
				'clip-rule',
				'clip-path',
				'class',
				'role',
				'aria-hidden',
				'aria-label',
				'focusable',
				'id',
				'offset',
				'stop-color',
				'stop-opacity',
				'gradientUnits',
				'gradientTransform',
			),
			true
		);

		foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
			if ( ! isset( $allowed_tags[ $node->tagName ] ) ) {
				return null;
			}

			foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
				$name  = $attribute->name;
				$lower = strtolower( $name );
				$value = $attribute->value;
				if ( 'style' === $lower ) {
					if ( null === self::safe_svg_dimension_style( $value ) ) {
						return null;
					}
					continue;
				}

				if ( str_starts_with( $lower, 'on' ) || ! isset( $allowed_attributes[ $name ] ) || preg_match( '/(?:javascript:|data:|url\s*\()/i', $value ) ) {
					return null;
				}
			}
		}

		if ( ! $doc->documentElement->hasAttribute( 'xmlns' ) ) {
			$doc->documentElement->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}
		if ( $doc->documentElement->hasAttribute( 'viewbox' ) && ! $doc->documentElement->hasAttribute( 'viewBox' ) ) {
			$doc->documentElement->setAttribute( 'viewBox', $doc->documentElement->getAttribute( 'viewbox' ) );
			$doc->documentElement->removeAttribute( 'viewbox' );
		}
		if ( $doc->documentElement->hasAttribute( 'preserveaspectratio' ) && ! $doc->documentElement->hasAttribute( 'preserveAspectRatio' ) ) {
			$doc->documentElement->setAttribute( 'preserveAspectRatio', $doc->documentElement->getAttribute( 'preserveaspectratio' ) );
			$doc->documentElement->removeAttribute( 'preserveaspectratio' );
		}

		$svg = $doc->saveXML( $doc->documentElement );
		return false === $svg ? null : $svg;
	}

	/**
	 * Parse an inline SVG style attribute that only carries safe dimensions.
	 *
	 * @param string $style Style attribute value.
	 * @return array{width?:string,height?:string}|null Dimensions, or null when unsafe/unsupported.
	 */
	private static function safe_svg_dimension_style( string $style ): ?array {
		$dimensions = array();
		foreach ( explode( ';', $style ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration ) {
				continue;
			}

			if ( 1 !== preg_match( '/^(width|height)\s*:\s*([0-9]+(?:\.[0-9]+)?)px$/i', $declaration, $matches ) ) {
				return null;
			}

			$dimensions[ strtolower( $matches[1] ) ] = $matches[2];
		}

		return $dimensions;
	}

	/**
	 * Write one sanitized SVG sprite asset and register its symbol ids.
	 *
	 * @param string                                          $svg      Sanitized sprite markup.
	 * @param array<string, array<string, string>>            $symbols  Symbol metadata keyed by id.
	 * @param string                                          $source   Source fragment label.
	 * @param int                                             $sequence Sequence within the fragment.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function write_svg_sprite_asset( string $svg, array $symbols, string $source, int $sequence ) {
		$hash = substr( hash( 'sha256', $svg ), 0, 16 );
		if ( isset( self::$materialized_svg_sprites[ $hash ] ) ) {
			foreach ( $symbols as $id => $symbol ) {
				self::$svg_sprite_symbols[ $id ] = $symbol;
			}

			return self::$materialized_svg_sprites[ $hash ];
		}

		$name     = sanitize_title( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $source ) . '-sprite-' . $sequence );
		$name     = '' === $name ? 'svg-sprite-' . $sequence : $name;
		$relative = 'assets/icons/' . $name . '-' . $hash . '.svg';
		$path     = trailingslashit( self::$active_theme_dir ) . $relative;
		$dir      = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'static_site_importer_svg_sprite_mkdir_failed', sprintf( 'Failed to create SVG sprite directory: %s', $dir ) );
		}

		$result = Static_Site_Importer_Theme_Materializer::write_file( $path, $svg . "\n" );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $symbols as $id => $symbol ) {
			self::$svg_sprite_symbols[ $id ] = $symbol;
		}

		// phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Keep the compact local variable readable beside the longer static writes below.
		$asset = array(
			'name'       => basename( $relative ),
			'path'       => $relative,
			'url'        => trailingslashit( self::$active_theme_uri ) . $relative,
			'hash'       => $hash,
			'source'     => $source,
			'symbol_ids' => array_keys( $symbols ),
		);
		self::$materialized_svg_sprites[ $hash ] = $asset;
		self::$conversion_report['assets']['svg_sprites'][] = $asset;

		return $asset;
	}

	/**
	 * Write one sanitized SVG icon asset and return its generated metadata.
	 *
	 * @param string $svg            Sanitized SVG markup.
	 * @param string $source         Source fragment label.
	 * @param int    $sequence       Sequence within the fragment.
	 * @param string $classification Upstream or SSI asset classification.
	 * @return array<string, string>|WP_Error
	 */
	private static function write_svg_icon_asset( string $svg, string $source, int $sequence, string $classification ) {
		$hash = substr( hash( 'sha256', $svg ), 0, 16 );
		if ( isset( self::$materialized_svg_assets[ $hash ] ) ) {
			return self::$materialized_svg_assets[ $hash ];
		}

		$name     = sanitize_title( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $source ) . '-' . $sequence );
		$name     = '' === $name ? 'icon-' . $sequence : $name;
		$relative = 'assets/icons/' . $name . '-' . $hash . '.svg';
		$path     = trailingslashit( self::$active_theme_dir ) . $relative;
		$dir      = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'static_site_importer_svg_icon_mkdir_failed', sprintf( 'Failed to create SVG icon directory: %s', $dir ) );
		}

		$result = Static_Site_Importer_Theme_Materializer::write_file( $path, $svg . "\n" );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Keep the compact local variable readable beside the longer static writes below.
		$asset = array(
			'name'           => basename( $relative ),
			'path'           => $relative,
			'url'            => trailingslashit( self::$active_theme_uri ) . $relative,
			'hash'           => $hash,
			'source'         => $source,
			'block'          => 'core/image',
			'classification' => $classification,
		);
		self::$materialized_svg_assets[ $hash ] = $asset;
		self::$conversion_report['assets']['svg_icons'][] = $asset;

		return $asset;
	}

	/**
	 * Extract a safe alt label from SVG accessibility nodes/attributes.
	 *
	 * @param DOMElement $svg SVG element.
	 * @return string
	 */
	private static function svg_accessible_label( DOMElement $svg ): string {
		foreach ( array( 'aria-label', 'title' ) as $attribute ) {
			$label = trim( $svg->getAttribute( $attribute ) );
			if ( '' !== $label ) {
				return $label;
			}
		}

		$title = $svg->getElementsByTagName( 'title' )->item( 0 );
		return $title instanceof DOMElement ? trim( $title->textContent ) : '';
	}

	/**
	 * Record an unsafe inline SVG that could not be materialized.
	 *
	 * @param string $source   Source fragment label.
	 * @param string $svg_html SVG markup.
	 * @return void
	 */
	private static function record_unsafe_inline_svg( string $source, string $svg_html ): void {
		++self::$conversion_report['quality']['unsafe_svg_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'         => 'unsafe_inline_svg',
			'source'       => $source,
			'html_length'  => strlen( $svg_html ),
			'html_excerpt' => self::diagnostic_excerpt( $svg_html ),
		);
	}

	/**
	 * Record a filesystem failure while materializing a safe inline SVG.
	 *
	 * @param string   $source   Source fragment label.
	 * @param string   $svg_html SVG markup.
	 * @param WP_Error $error    Write error.
	 * @return void
	 */
	private static function record_svg_materialization_failure( string $source, string $svg_html, WP_Error $error ): void {
		++self::$conversion_report['quality']['svg_materialization_failure_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'          => 'svg_materialization_failure',
			'source'        => $source,
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			'html_length'   => strlen( $svg_html ),
			'html_excerpt'  => self::diagnostic_excerpt( $svg_html ),
		);
	}

	/**
	 * Record a local SVG use reference that could not be resolved from an extracted sprite.
	 *
	 * @param string $source   Source fragment label.
	 * @param string $svg_html SVG markup.
	 * @param string $reason   Failure reason.
	 * @return void
	 */
	private static function record_svg_sprite_reference_failure( string $source, string $svg_html, string $reason ): void {
		++self::$conversion_report['quality']['svg_sprite_reference_failure_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'         => 'svg_sprite_reference_failure',
			'source'       => $source,
			'reason'       => $reason,
			'html_length'  => strlen( $svg_html ),
			'html_excerpt' => self::diagnostic_excerpt( $svg_html ),
		);
	}

	/**
	 * Record the bundle-level compiler result used by import_website_artifact().
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	private static function record_website_artifact_compiler_result( array $compiled ): void {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		self::$conversion_report['block_artifact_compiler']['available']        = true;
		self::$conversion_report['block_artifact_compiler']['website_artifact'] = array(
			'summary'     => bac_summarize_result( $compiled ),
			'provenance'  => isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : array(),
			'input'       => isset( $compiled['input'] ) && is_array( $compiled['input'] ) ? $compiled['input'] : array(),
			'diagnostics' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array(),
		);

		if ( 'block-artifact-compiler/compiled-site/v1' === (string) ( $site['schema'] ?? '' ) ) {
			self::$conversion_report['block_artifact_compiler']['compiled_site'] = self::compiled_site_report_payload( $site );
		}
	}

	/**
	 * Preserve the BAC compiled-site contract in the SSI import report.
	 *
	 * @param array<string,mixed> $site BAC compiled-site artifact.
	 * @return array<string,mixed>
	 */
	private static function compiled_site_report_payload( array $site ): array {
		$pages          = isset( $site['pages'] ) && is_array( $site['pages'] ) ? $site['pages'] : array();
		$shared_regions = isset( $site['shared_regions'] ) && is_array( $site['shared_regions'] ) ? $site['shared_regions'] : array();
		$theme_assets   = isset( $site['theme_assets'] ) && is_array( $site['theme_assets'] ) ? $site['theme_assets'] : array();

		return array(
			'schema'              => (string) ( $site['schema'] ?? '' ),
			'page_count'          => count( $pages ),
			'pages'               => self::compiled_site_pages_report_payload( $pages ),
			'shared_region_count' => count( $shared_regions ),
			'shared_regions'      => $shared_regions,
			'theme_assets'        => array(
				'styles'  => isset( $theme_assets['styles'] ) && is_array( $theme_assets['styles'] ) ? $theme_assets['styles'] : array(),
				'scripts' => isset( $theme_assets['scripts'] ) && is_array( $theme_assets['scripts'] ) ? $theme_assets['scripts'] : array(),
			),
			'provenance'          => isset( $site['provenance'] ) && is_array( $site['provenance'] ) ? $site['provenance'] : array(),
		);
	}

	/**
	 * Normalize compiled-site page rows for reports.
	 *
	 * @param array<int,mixed> $pages Compiled-site page rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compiled_site_pages_report_payload( array $pages ): array {
		$normalized = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$normalized[] = array(
				'source_path' => isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '',
				'route_key'   => isset( $page['route_key'] ) && is_scalar( $page['route_key'] ) ? (string) $page['route_key'] : '',
				'slug'        => isset( $page['slug'] ) && is_scalar( $page['slug'] ) ? (string) $page['slug'] : '',
				'post_type'   => isset( $page['post_type'] ) && is_scalar( $page['post_type'] ) ? (string) $page['post_type'] : '',
				'title'       => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
				'entrypoint'  => ! empty( $page['entrypoint'] ),
				'artifact'    => isset( $page['artifact'] ) && is_scalar( $page['artifact'] ) ? (string) $page['artifact'] : '',
			);
		}

		return $normalized;
	}

	/**
	 * Record source and contract notes for direct website-artifact materialization.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	private static function record_direct_website_artifact_source_summary( array $compiled ): void {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$files     = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$source    = (string) ( $compiled['provenance']['source'] ?? ( $compiled['input']['entry_path'] ?? 'website_artifact' ) );

		$source_documents = array_merge(
			self::$conversion_report['source_documents'],
			array(
				'total_count'      => 1,
				'counts_by_format' => array(
					'html'     => 1,
					'markdown' => 0,
					'mdx'      => 0,
				),
			)
		);

		self::$conversion_report['source_documents'] = $source_documents;
		self::$conversion_report['source_documents']['direct_website_artifact'] = array(
			'source'     => '' !== $source ? $source : 'website_artifact',
			'file_count' => count( $files ),
		);

		self::$conversion_report['diagnostics'][] = array(
			'type'        => 'website_artifact_materialization_contract_note',
			'source'      => '' !== $source ? $source : 'website_artifact',
			'message'     => 'Direct materialization consumed BAC block_markup, documents, files, and compiled-site artifacts. SSI owns WordPress writes while BAC owns materializer-neutral site/theme compilation.',
			'contract'    => 'block-artifact-compiler/result/v1',
			'constraints' => 'report_only',
		);
	}

	/**
	 * Record compiler-routed full-document metadata and head asset references.
	 *
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return void
	 */
	private static function record_website_artifact_document_metadata( array $artifacts ): void {
		$metadata = isset( $artifacts['document_metadata'] ) && is_array( $artifacts['document_metadata'] ) ? $artifacts['document_metadata'] : array();
		if ( 'block-artifact-compiler/document-metadata/v1' !== (string) ( $metadata['schema'] ?? '' ) ) {
			return;
		}

		$normalized = array(
			'schema'      => 'static-site-importer/document-metadata/v1',
			'source'      => 'block-artifact-compiler/document_metadata',
			'source_path' => isset( $metadata['source_path'] ) && is_scalar( $metadata['source_path'] ) ? (string) $metadata['source_path'] : '',
			'title'       => isset( $metadata['title'] ) && is_scalar( $metadata['title'] ) ? sanitize_text_field( (string) $metadata['title'] ) : '',
			'meta'        => self::normalize_document_metadata_rows( $metadata['meta'] ?? array(), array( 'charset', 'name', 'property', 'http_equiv', 'content' ) ),
			'links'       => self::normalize_document_metadata_rows( $metadata['links'] ?? array(), array( 'rel', 'href', 'as', 'type', 'media', 'crossorigin', 'integrity' ) ),
			'styles'      => self::normalize_hashed_head_assets( $metadata['styles'] ?? array() ),
			'scripts'     => self::normalize_document_scripts( $metadata['scripts'] ?? array() ),
		);

		self::$conversion_report['generated_theme']['document_metadata'] = $normalized;
		self::$conversion_report['diagnostics'][] = array(
			'type'        => 'document_metadata_routed',
			'source'      => $normalized['source_path'],
			'severity'    => 'info',
			'stage'       => 'website_artifact_materialization',
			'message'     => 'Full-document head metadata/assets were routed through the generated_theme.document_metadata contract instead of generated page block content.',
			'meta_count'  => count( $normalized['meta'] ),
			'link_count'  => count( $normalized['links'] ),
			'style_count' => count( $normalized['styles'] ),
			'script_count' => count( $normalized['scripts'] ),
		);
	}

	/**
	 * Normalize scalar rows from the document metadata contract.
	 *
	 * @param mixed             $rows    Raw rows.
	 * @param array<int,string> $allowed Allowed keys.
	 * @return array<int,array<string,string>>
	 */
	private static function normalize_document_metadata_rows( mixed $rows, array $allowed ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array();
			foreach ( $allowed as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $row[ $key ] );
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize hashed inline head asset summaries.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_hashed_head_assets( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$hash = isset( $row['hash'] ) && is_scalar( $row['hash'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $row['hash'] ) : '';
			$bytes = isset( $row['bytes'] ) ? max( 0, (int) $row['bytes'] ) : 0;
			if ( '' === $hash && 0 === $bytes ) {
				continue;
			}

			$normalized[] = array(
				'bytes' => $bytes,
				'hash'  => $hash,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize script references from the document metadata contract.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_document_scripts( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array();
			foreach ( array( 'src', 'type', 'crossorigin', 'integrity' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					$item[ $key ] = sanitize_text_field( (string) $row[ $key ] );
				}
			}

			foreach ( array( 'defer', 'async' ) as $key ) {
				if ( isset( $row[ $key ] ) ) {
					$item[ $key ] = (bool) $row[ $key ];
				}
			}

			if ( isset( $row['inline'] ) ) {
				$inline = self::normalize_hashed_head_assets( array( $row['inline'] ) );
				if ( ! empty( $inline ) ) {
					$item['inline'] = $inline[0];
				}
			}

			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Write compiler-emitted files that can be consumed without re-importing HTML.
	 *
	 * @param string              $theme_dir Theme directory.
	 * @param array<string,mixed> $artifacts WordPress artifacts from BAC.
	 * @return array{css:string,js:string}|WP_Error
	 */
	private static function materialize_website_artifact_files_to_theme( string $theme_dir, array $artifacts ) {
		$result = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, self::$active_theme_uri, $artifacts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['diagnostics'] as $diagnostic ) {
			self::$conversion_report['diagnostics'][] = $diagnostic;
		}
		self::$active_artifact_materialized_assets = array_merge( self::$active_artifact_materialized_assets, $result['assets'] );

		return array(
			'css' => $result['css'],
			'js'  => $result['js'],
		);
	}

	/**
	 * Mark empty absolute-positioned groups so editor CSS can hide only decorative placeholders.
	 *
	 * @param string $block_markup Serialized block markup.
	 * @return string Serialized block markup.
	 */
	private static function mark_empty_decorative_group_blocks( string $block_markup, string $source = '' ): string {
		if ( '' === trim( $block_markup ) || empty( self::$decorative_empty_group_classes ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $block_markup;
		}

		/** @var array<int, array<string, mixed>> $blocks */
		$blocks                 = parse_blocks( $block_markup );
		$changed                = false;
		$normalized_html_blocks = 0;
		self::normalize_decorative_html_blocks_in_tree( $blocks, $changed, $normalized_html_blocks );
		if ( $normalized_html_blocks > 0 && '' !== $source ) {
			self::clear_normalized_decorative_fallbacks( $source, $normalized_html_blocks );
		}
		self::mark_empty_decorative_group_blocks_in_tree( $blocks, $changed );

		// @phpstan-ignore-next-line argument.type -- Parsed blocks are normalized before serializing.
		return $changed ? serialize_blocks( $blocks ) : $block_markup;
	}

	/**
	 * Restore empty decorative divs when the converter drops them from card bodies.
	 *
	 * @param string $html         Source HTML fragment.
	 * @param string $block_markup Serialized block markup.
	 * @return string Serialized block markup.
	 */
	private static function restore_dropped_empty_decorative_groups( string $html, string $block_markup ): string {
		if ( '' === trim( $html ) || '' === trim( $block_markup ) || empty( self::$decorative_empty_group_classes ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $block_markup;
		}

		$restore = self::empty_decorative_groups_by_parent_class( $html );
		if ( empty( $restore ) ) {
			return $block_markup;
		}

		/** @var array<int, array<string, mixed>> $blocks */
		$blocks  = parse_blocks( $block_markup );
		$changed = false;
		self::restore_dropped_empty_decorative_groups_in_tree( $blocks, $restore, $changed );

		// @phpstan-ignore-next-line argument.type -- Parsed blocks are normalized before serializing.
		return $changed ? serialize_blocks( $blocks ) : $block_markup;
	}

	/**
	 * Extract decorative empty div blocks keyed by their parent class token.
	 *
	 * @param string $html Source HTML fragment.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function empty_decorative_groups_by_parent_class( string $html ): array {
		$doc     = self::load_fragment_document( $html );
		$restore = array();

		foreach ( $doc->getElementsByTagName( 'div' ) as $element ) {
			if ( ! self::is_empty_decorative_theme_part_element( $element ) || ! $element->parentNode instanceof DOMElement ) {
				continue;
			}

			$matched = false;
			$block   = self::decorative_group_block_from_element( $element, $matched );
			if ( null === $block || ! $matched ) {
				continue;
			}

			$parent_classes = preg_split( '/\s+/', trim( $element->parentNode->getAttribute( 'class' ) ) );
			$parent_classes = false === $parent_classes ? array() : $parent_classes;
			foreach ( $parent_classes as $parent_class ) {
				if ( '' !== $parent_class && self::class_token_looks_like_card_container( $parent_class ) ) {
					$restore[ $parent_class ][] = $block;
				}
			}
		}

		return $restore;
	}

	/**
	 * Whether a class token identifies a card-like container that can safely receive restored layers.
	 *
	 * @param string $class_name Class token.
	 * @return bool
	 */
	private static function class_token_looks_like_card_container( string $class_name ): bool {
		return str_contains( $class_name, 'card' ) || str_contains( $class_name, 'gallery' ) || str_contains( $class_name, 'product' ) || str_contains( $class_name, 'category' );
	}

	/**
	 * Restore decorative blocks inside matching generated group blocks.
	 *
	 * @param array<int, array<string, mixed>>              $blocks  Parsed blocks.
	 * @param array<string, array<int, array<string,mixed>>> $restore Blocks keyed by parent class.
	 * @param bool                                          $changed Whether any block changed.
	 * @return void
	 */
	private static function restore_dropped_empty_decorative_groups_in_tree( array &$blocks, array $restore, bool &$changed ): void {
		foreach ( $blocks as &$block ) {
			if ( 'core/group' === ( $block['blockName'] ?? '' ) ) {
				$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
				$classes = false === $classes ? array() : $classes;
				foreach ( $classes as $class ) {
					if ( ! isset( $restore[ $class ] ) || self::block_contains_any_decorative_group( $block, $restore[ $class ] ) ) {
						continue;
					}

					$inner_blocks          = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
					$block['innerBlocks']  = array_merge( $restore[ $class ], $inner_blocks );
					$block['innerContent'] = self::prepend_inner_content_placeholders( is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array(), count( $restore[ $class ] ) );
					$changed               = true;
					break;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::restore_dropped_empty_decorative_groups_in_tree( $block['innerBlocks'], $restore, $changed );
			}
		}
		unset( $block );
	}

	/**
	 * Add innerContent placeholders for restored leading inner blocks.
	 *
	 * @param array<int, mixed> $inner_content Existing innerContent.
	 * @param int              $count         Number of leading blocks to insert.
	 * @return array<int, mixed>
	 */
	private static function prepend_inner_content_placeholders( array $inner_content, int $count ): array {
		$placeholders = array_fill( 0, max( 0, $count ), null );
		if ( empty( $inner_content ) ) {
			return $placeholders;
		}

		$first = array_shift( $inner_content );
		return array_merge( array( $first ), $placeholders, $inner_content );
	}

	/**
	 * Check whether a generated block already contains any decorative class slated for restore.
	 *
	 * @param array<string, mixed>             $block        Parsed block.
	 * @param array<int, array<string, mixed>> $restore_list Candidate restored blocks.
	 * @return bool
	 */
	private static function block_contains_any_decorative_group( array $block, array $restore_list ): bool {
		$haystack = wp_json_encode( $block, JSON_UNESCAPED_SLASHES );
		$haystack = is_string( $haystack ) ? $haystack : '';
		foreach ( $restore_list as $restore_block ) {
			$attrs      = is_array( $restore_block['attrs'] ?? null ) ? $restore_block['attrs'] : array();
			$class_name = (string) ( $attrs['className'] ?? '' );
			if ( '' !== $class_name && str_contains( $haystack, $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert raw HTML islands made only of decorative empty divs into native groups.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                            $changed Whether any block changed.
	 * @return void
	 */
	private static function normalize_decorative_html_blocks_in_tree( array &$blocks, bool &$changed, int &$normalized_html_blocks ): void {
		$block_total = count( $blocks );
		for ( $index = 0; $index < $block_total; ++$index ) {
			if ( ! empty( $blocks[ $index ]['innerBlocks'] ) && is_array( $blocks[ $index ]['innerBlocks'] ) ) {
				self::normalize_decorative_html_blocks_in_tree( $blocks[ $index ]['innerBlocks'], $changed, $normalized_html_blocks );
			}

			if ( 'core/html' !== ( $blocks[ $index ]['blockName'] ?? '' ) ) {
				continue;
			}

			$replacement = self::decorative_group_blocks_from_html( (string) ( $blocks[ $index ]['innerHTML'] ?? '' ) );
			if ( null === $replacement ) {
				continue;
			}

			array_splice( $blocks, $index, 1, $replacement );
			$replacement_count = count( $replacement );
			$index            += $replacement_count - 1;
			$block_total      += $replacement_count - 1;
			$changed           = true;
			++$normalized_html_blocks;
		}
	}

	/**
	 * Remove fallback diagnostics that were recovered as native decorative group blocks.
	 *
	 * @param string $source Source fragment label.
	 * @param int    $count  Number of normalized fallback blocks.
	 * @return void
	 */
	private static function clear_normalized_decorative_fallbacks( string $source, int $count ): void {
		self::$conversion_report['quality']['fallback_count'] = max( 0, (int) self::$conversion_report['quality']['fallback_count'] - $count );
		if ( isset( self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] ) ) {
			self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] = max( 0, (int) self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'] - $count );
		}

		for ( $index = count( self::$conversion_report['diagnostics'] ) - 1; $index >= 0 && $count > 0; --$index ) {
			$diagnostic = self::$conversion_report['diagnostics'][ $index ];
			if ( 'unsupported_html_fallback' !== ( $diagnostic['type'] ?? '' ) || ( $diagnostic['source'] ?? '' ) !== $source ) {
				continue;
			}

			array_splice( self::$conversion_report['diagnostics'], $index, 1 );
			--$count;
		}
	}

	/**
	 * Convert an HTML fragment to group blocks when it only contains decorative empty div layers.
	 *
	 * @param string $html HTML fragment.
	 * @return array<int, array<string, mixed>>|null Replacement group blocks, or null when not safe.
	 */
	private static function decorative_group_blocks_from_html( string $html ): ?array {
		if ( '' === trim( $html ) || ! str_contains( $html, '<div' ) ) {
			return null;
		}

		$doc      = self::load_fragment_document( $html );
		$root     = $doc->documentElement;
		$blocks   = array();
		$matched  = false;
		$has_node = false;
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$has_node = true;
			$block    = self::decorative_group_block_from_element( $child, $matched );
			if ( null === $block ) {
				return null;
			}

			$blocks[] = $block;
		}

		return $has_node && $matched ? $blocks : null;
	}

	/**
	 * Convert one empty decorative div tree to a parsed group block.
	 *
	 * @param DOMElement $element Source element.
	 * @param bool       $matched Whether a decorative class was found.
	 * @return array<string, mixed>|null Parsed block, or null when not safe.
	 */
	private static function decorative_group_block_from_element( DOMElement $element, bool &$matched ): ?array {
		if ( 'div' !== strtolower( $element->tagName ) ) {
			return null;
		}

		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				return null;
			}

			$child_block = self::decorative_group_block_from_element( $child, $matched );
			if ( null === $child_block ) {
				return null;
			}

			$children[] = $child_block;
		}

		$class_name = trim( $element->getAttribute( 'class' ) );
		$classes    = preg_split( '/\s+/', $class_name );
		$classes    = false === $classes ? array() : $classes;
		$is_layer   = false;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				$is_layer = true;
				$matched  = true;
				break;
			}
		}

		if ( empty( $children ) && $is_layer ) {
			$class_name = self::append_class_token( $class_name, 'static-site-importer-decorative-layer' );
		}

		$attrs = array();
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}

		$class_attr    = esc_attr( trim( 'wp-block-group ' . $class_name ) );
		$inner_content = array( '<div class="' . $class_attr . '">' );
		foreach ( $children as $_child ) {
			$inner_content[] = null;
		}
		$inner_content[] = '</div>';

		if ( empty( $children ) ) {
			$inner_content = array( '<div class="' . $class_attr . '"></div>' );
		}

		return array(
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Recursively mark empty decorative group blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                            $changed Whether any block changed.
	 * @return void
	 */
	private static function mark_empty_decorative_group_blocks_in_tree( array &$blocks, bool &$changed ): void {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::mark_empty_decorative_group_blocks_in_tree( $block['innerBlocks'], $changed );
			}

			if ( 'core/group' !== ( $block['blockName'] ?? '' ) || ! self::is_empty_decorative_group_block( $block ) ) {
				continue;
			}

			$attrs              = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$attrs['className'] = self::append_class_token( (string) ( $attrs['className'] ?? '' ), 'static-site-importer-decorative-layer' );
			$block['attrs']     = $attrs;

			foreach ( array( 'innerHTML' ) as $key ) {
				if ( isset( $block[ $key ] ) && is_string( $block[ $key ] ) ) {
					$block[ $key ] = self::append_class_to_first_html_class_attribute( $block[ $key ], 'static-site-importer-decorative-layer' );
				}
			}

			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as &$content ) {
					if ( is_string( $content ) ) {
						$content = self::append_class_to_first_html_class_attribute( $content, 'static-site-importer-decorative-layer' );
					}
				}
				unset( $content );
			}

			$changed = true;
		}
		unset( $block );
	}

	/**
	 * Check whether a parsed group block is empty and styled as a decorative layer.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return bool Whether the block is an empty decorative group.
	 */
	private static function is_empty_decorative_group_block( array $block ): bool {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		if ( '' !== trim( wp_strip_all_tags( $inner_html ) ) ) {
			return false;
		}

		$attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
		$classes = false === $classes ? array() : $classes;
		foreach ( $classes as $class ) {
			if ( isset( self::$decorative_empty_group_classes[ $class ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Append a class token if it is not already present.
	 *
	 * @param string $classes Existing classes.
	 * @param string $class_name_to_append Class to append.
	 * @return string Updated classes.
	 */
	private static function append_class_token( string $classes, string $class_name_to_append ): string {
		$tokens = preg_split( '/\s+/', trim( $classes ) );
		$tokens = false === $tokens ? array() : $tokens;
		if ( ! in_array( $class_name_to_append, $tokens, true ) ) {
			$tokens[] = $class_name_to_append;
		}

		return trim( implode( ' ', array_filter( $tokens ) ) );
	}

	/**
	 * Append a class to the first HTML class attribute in a serialized block fragment.
	 *
	 * @param string $html  HTML fragment.
	 * @param string $class_name_to_append Class to append.
	 * @return string Updated HTML fragment.
	 */
	private static function append_class_to_first_html_class_attribute( string $html, string $class_name_to_append ): string {
		$updated = preg_replace_callback(
			'/class="([^"]*)"/',
			static function ( array $matches ) use ( $class_name_to_append ): string {
				return 'class="' . esc_attr( self::append_class_token( html_entity_decode( $matches[1], ENT_QUOTES ), $class_name_to_append ) ) . '"';
			},
			$html,
			1
		);

		return null === $updated ? $html : $updated;
	}

	/**
	 * Initialize a conversion report.
	 *
	 * @param string $html_path Imported entry file.
	 * @return array<string, mixed>
	 */
	private static function new_conversion_report( string $html_path, array $source_metadata = array() ): array {
		return array(
			'version'                 => 1,
			'entry_file'              => $html_path,
			'source'                  => array_merge(
				array(
					'type' => empty( $source_metadata ) ? 'file' : (string) ( $source_metadata['source_type'] ?? 'file' ),
				),
				$source_metadata
			),
			'quality'                 => array(
				'pass'                               => true,
				'fallback_count'                     => 0,
				'content_loss_count'                 => 0,
				'empty_conversion_count'             => 0,
				'core_html_block_count'              => 0,
				'freeform_block_count'               => 0,
				'invalid_block_count'                => 0,
				'invalid_block_document_count'       => 0,
				'unsafe_svg_count'                   => 0,
				'svg_materialization_failure_count'  => 0,
				'svg_sprite_reference_failure_count' => 0,
				'commerce_dependency_failures'       => 0,
				'failure_reasons'                    => array(),
			),
			'source_documents'        => array(
				'total_count'                => 0,
				'counts_by_format'           => array(
					'html'     => 0,
					'markdown' => 0,
					'mdx'      => 0,
				),
				'skipped_mdx_count'          => 0,
				'unresolved_links'           => array(),
				'unresolved_link_count'      => 0,
				'markdown_parse_error_count' => 0,
			),
			'conversion_fragments'    => array(),
			'source_region_selection' => array(
				'entry_file'                    => '',
				'page_body'                     => null,
				'extracted_header'              => null,
				'extracted_footer'              => null,
				'unassigned_regions'            => array(),
				'intentionally_ignored_regions' => array(),
				'counts'                        => array(
					'source_landmarks'              => array(
						'main'   => 0,
						'header' => 0,
						'nav'    => 0,
						'footer' => 0,
					),
					'unassigned_regions'            => 0,
					'intentionally_ignored_regions' => 0,
				),
				'notes'                         => array(
					'Reports which source region became the page body, the extracted header/footer parts, and any meaningful direct body children that were not assigned to a generated region. Reporting only — does not change conversion behavior.',
				),
			),
			'commerce_context'        => array(
				'supplied'       => false,
				'source'         => 'none',
				'product_count'  => 0,
				'selector_hints' => array(),
				'diagnostics'    => array(),
			),
			'assets'                  => array(
				'policy'       => 'theme',
				'local_policy' => 'copy_to_theme',
				'svg_icons'    => array(),
				'svg_sprites'  => array(),
				'local'        => array(),
			),
			'asset_map'               => array(
				'supplied'         => false,
				'entry_count'      => 0,
				'resolved_count'   => 0,
				'unresolved_count' => 0,
				'resolved'         => array(),
				'unresolved'       => array(),
			),
			'block_artifact_compiler' => array(
				'available'      => true,
				'fragment_count' => 0,
				'fragments'      => array(),
			),
			'generated_theme'         => array(
				'document_metadata' => array(),
				'template_parts'    => array(),
				'block_documents' => array(),
				'freeform_blocks' => array(),
			),
			'visual_fidelity'         => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source and generated DOM probes, render URLs, and theme artifacts for visual comparison; screenshot capture and computed-style/layout thresholds belong to the benchmark harness.',
				),
			),
			'semantic_fidelity'       => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source/generated semantic comparison targets; browser DOM extraction and semantic fingerprint comparison belong to the benchmark harness.',
				),
			),
			'diagnostics'             => array(),
			'notes'                   => array(
				'Block Artifact Compiler owns the website-artifact to WordPress-artifact envelope; Static Site Importer materializes the result and records compiler summaries.',
				'Block Format Bridge owns HTML-to-block transform fidelity; Static Site Importer records converter diagnostics and quality gates the generated theme.',
				'Generated-theme block validation uses WordPress server-side block parsing and serialization checks; editor-runtime validation remains the exact Gutenberg authority.',
				'Visual fidelity requires browser rendering; use visual_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
				'Semantic fidelity requires browser DOM extraction; use semantic_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
			),
		);
	}

	/**
	 * Record page-body extraction decisions for the import report.
	 *
	 * Captures the page-body source mode/selector, extracted header/footer
	 * selectors, and any meaningful direct body children that were not
	 * assigned to a generated region. Reporting only — does not change
	 * conversion behavior.
	 *
	 * @param Static_Site_Importer_Document $document  Entry document.
	 * @param string                        $html_path Entry HTML path.
	 * @return void
	 */
	private static function record_source_region_selection( Static_Site_Importer_Document $document, string $html_path ): void {
		$selection               = $document->selection_report();
		$selection['entry_file'] = $html_path;
		// Preserve notes already present on the report shape.
		$existing_notes = self::$conversion_report['source_region_selection']['notes'] ?? array();
		if ( ! empty( $existing_notes ) ) {
			$selection['notes'] = $existing_notes;
		}

		self::$conversion_report['source_region_selection'] = $selection;

		foreach ( $selection['unassigned_regions'] as $region ) {
			self::$conversion_report['diagnostics'][] = array(
				'type'       => 'source_region_unassigned',
				'role'       => $region['role'] ?? 'unassigned_body_child',
				'reason'     => $region['reason'] ?? '',
				'selector'   => $region['selector'] ?? '',
				'tag'        => $region['tag'] ?? '',
				'line_range' => $region['line_range'] ?? null,
				'excerpt'    => $region['excerpt'] ?? '',
				'source'     => $html_path,
				'message'    => 'Source region was not assigned to a generated theme part or page pattern. Inspect source_region_selection.unassigned_regions in import-report.json for the selector path and source line range.',
			);
		}
	}

	/**
	 * Record BAC-owned source document materialization details.
	 *
	 * @param array<int,mixed>                                   $documents  BAC document artifacts.
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Imported pages/posts.
	 * @param array<string,int>                                $page_ids   Imported post IDs keyed by source path.
	 * @param array<string,string>                             $permalinks Imported permalinks keyed by source path.
	 * @return void
	 */
	private static function record_bac_source_documents_summary( array $documents, array $pages, array $page_ids, array $permalinks ): void {
		$records = array();
		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$source_path = self::normalize_route_path( isset( $document['source_path'] ) ? (string) $document['source_path'] : (string) ( $document['path'] ?? '' ) );
			if ( '' === $source_path ) {
				$source_path = self::normalize_route_path( isset( $document['slug'] ) ? (string) $document['slug'] : '' );
			}
			if ( '' === $source_path || ! isset( $pages[ $source_path ] ) ) {
				continue;
			}

			$page        = $pages[ $source_path ];
			$post_id     = (int) ( $page_ids[ $source_path ] ?? 0 );
			$post_type   = self::page_post_type( $page );
			$diagnostics = isset( $document['diagnostics'] ) && is_array( $document['diagnostics'] ) ? array_values( $document['diagnostics'] ) : array();

			$record = array(
				'source_path'  => $source_path,
				'post_id'      => $post_id,
				'post_type'    => $post_type,
				'slug'         => self::page_slug( $source_path, $page ),
				'title'        => self::page_title( $source_path, $page ),
				'status'       => self::page_status( $page ),
				'permalink'    => $permalinks[ $source_path ] ?? '',
				'diagnostics'  => $diagnostics,
				'materialized' => $post_id > 0,
			);

			$records[] = $record;
			foreach ( $diagnostics as $diagnostic ) {
				if ( is_array( $diagnostic ) ) {
					$diagnostic['source']                     = isset( $diagnostic['source'] ) && '' !== trim( (string) $diagnostic['source'] ) ? (string) $diagnostic['source'] : $source_path;
					self::$conversion_report['diagnostics'][] = $diagnostic;
				}
			}
		}

		self::$conversion_report['source_documents'] = array_merge(
			self::$conversion_report['source_documents'],
			array(
				'source'             => 'block_artifact_compiler',
				'total_count'        => count( $records ),
				'counts_by_format'   => array_merge(
					self::$conversion_report['source_documents']['counts_by_format'] ?? array(),
					array( 'bac_document' => count( $records ) )
				),
				'bac_documents'      => $records,
				'bac_document_count' => count( $records ),
			)
		);
	}

	/**
	 * Record a local source link or asset that could not be resolved/materialized.
	 *
	 * @param string $source      Diagnostic source label.
	 * @param string $source_path Source-relative source path.
	 * @param string $href        Original href or source URL.
	 * @param string $type        Diagnostic type.
	 * @return void
	 */
	private static function record_unresolved_internal_link( string $source, string $source_path, string $href, string $type = 'unresolved_internal_link' ): void {
		if ( ! self::is_local_url( $href ) ) {
			return;
		}

		self::$conversion_report['diagnostics'][] = array(
			'type'        => $type,
			'source'      => $source,
			'source_path' => $source_path,
			'href'        => $href,
		);
	}

	/**
	 * Record WooCommerce product seeding results for an already-validated manifest.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_product_seeding_report( array $args ): void {
		$manifest = isset( $args['products_manifest'] ) && is_array( $args['products_manifest'] ) ? $args['products_manifest'] : null;
		if ( null === $manifest ) {
			$report_manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
			if ( is_array( $report_manifest ) && true === ( $report_manifest['valid'] ?? false ) ) {
				$manifest = isset( $report_manifest['products'] ) && is_array( $report_manifest['products'] ) ? $report_manifest['products'] : array();
			}
		}

		if ( null === $manifest ) {
			self::$conversion_report['product_seeding']           = Static_Site_Importer_Woo_Product_Seeder::new_report();
			self::$conversion_report['product_seeding']['reason'] = 'no_validated_manifest';
			return;
		}

		self::$conversion_report['product_seeding'] = Static_Site_Importer_Woo_Product_Seeder::seed( $manifest );
	}

	/**
	 * Record the WooCommerce dependency check for commerce-bearing imports.
	 *
	 * Commerce intent is detected when the artifact import has a validated
	 * products manifest or compiler-supplied commerce context carrying at least
	 * one product. When intent is present, WooCommerce must be active or the
	 * caller must explicitly waive the requirement via allow_missing_woocommerce.
	 * Without intent, no commerce.dependencies shape is recorded.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return void
	 */
	private static function record_commerce_dependency_check( array $args ): void {
		$intent = self::commerce_dependency_intent();
		if ( ! $intent['present'] ) {
			return;
		}

		$woocommerce_active = Static_Site_Importer_Woo_Product_Seeder::woocommerce_available();
		$waived             = ! empty( $args['allow_missing_woocommerce'] );

		$dependencies = array(
			'woocommerce' => array(
				'required'      => true,
				'active'        => $woocommerce_active,
				'sources'       => $intent['sources'],
				'product_count' => $intent['product_count'],
				'waived'        => $waived,
				'missing_apis'  => $woocommerce_active ? array() : array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ),
			),
		);

		if ( ! isset( self::$conversion_report['commerce'] ) || ! is_array( self::$conversion_report['commerce'] ) ) {
			self::$conversion_report['commerce'] = array();
		}
		self::$conversion_report['commerce']['dependencies'] = $dependencies;

		if ( $woocommerce_active ) {
			self::$conversion_report['diagnostics'][] = array(
				'code'          => 'woocommerce_present',
				'severity'      => 'info',
				'source'        => 'commerce.dependencies.woocommerce',
				'message'       => 'WooCommerce is active; commerce-bearing import will seed products.',
				'product_count' => $intent['product_count'],
				'sources'       => $intent['sources'],
			);
			return;
		}

		if ( $waived ) {
			self::$conversion_report['diagnostics'][] = array(
				'code'          => 'woocommerce_waived',
				'severity'      => 'warning',
				'source'        => 'commerce.dependencies.woocommerce',
				'message'       => 'Commerce-bearing import proceeded without WooCommerce because allow_missing_woocommerce was set; products were not seeded.',
				'product_count' => $intent['product_count'],
				'sources'       => $intent['sources'],
			);
			return;
		}

		++self::$conversion_report['quality']['commerce_dependency_failures'];
		self::$conversion_report['diagnostics'][] = array(
			'code'          => 'woocommerce_missing',
			'severity'      => 'error',
			'source'        => 'commerce.dependencies.woocommerce',
			'message'       => 'WooCommerce is required for this import. The source declared products but WooCommerce is not active. Install and activate WooCommerce, or pass allow_missing_woocommerce to import the theme without seeding products.',
			'product_count' => $intent['product_count'],
			'sources'       => $intent['sources'],
		);

		if ( isset( self::$conversion_report['product_seeding'] ) && is_array( self::$conversion_report['product_seeding'] ) ) {
			self::$conversion_report['product_seeding']['reason'] = 'woocommerce_required_but_missing';
		}
	}

	/**
	 * Detect commerce intent for the active import.
	 *
	 * @return array{present:bool,sources:array<int,string>,product_count:int}
	 */
	private static function commerce_dependency_intent(): array {
		$sources       = array();
		$product_count = 0;

		$manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
		if ( is_array( $manifest ) && true === ( $manifest['valid'] ?? false ) ) {
			$manifest_count = (int) ( $manifest['product_count'] ?? 0 );
			if ( $manifest_count > 0 ) {
				$sources[]     = 'products_manifest';
				$product_count = $manifest_count;
			}
		}

		$context = self::$conversion_report['commerce_context'] ?? array();
		if ( is_array( $context ) && true === ( $context['supplied'] ?? false ) ) {
			$context_count = (int) ( $context['product_count'] ?? 0 );
			if ( $context_count > 0 ) {
				$source = (string) ( $context['source'] ?? 'commerce_context' );
				if ( '' === $source ) {
					$source = 'commerce_context';
				}
				if ( ! in_array( $source, $sources, true ) ) {
					$sources[] = $source;
				}
				if ( $context_count > $product_count ) {
					$product_count = $context_count;
				}
			}
		}

		return array(
			'present'       => ! empty( $sources ),
			'sources'       => $sources,
			'product_count' => $product_count,
		);
	}

	/**
	 * Analyze generated theme block documents before writing the import report.
	 *
	 * @param array<string,string> $writes    Generated files keyed by absolute path.
	 * @param string               $theme_dir Generated theme directory.
	 * @return void
	 */
	private static function analyze_generated_theme_block_documents( array $writes, string $theme_dir ): void {
		foreach ( $writes as $path => $content ) {
			$relative_path = ltrim( str_replace( trailingslashit( $theme_dir ), '', $path ), '/' );
			if ( ! self::is_generated_block_document_path( $relative_path ) ) {
				continue;
			}

			$block_markup = self::generated_block_document_markup( $relative_path, $content );
			$analysis     = self::analyze_generated_block_document( $relative_path, $block_markup );
			self::$conversion_report['generated_theme']['block_documents'][] = $analysis;
		}
	}

	/**
	 * Determine whether a generated file should contain block markup.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @return bool
	 */
	private static function is_generated_block_document_path( string $relative_path ): bool {
		return str_starts_with( $relative_path, 'templates/' ) || str_starts_with( $relative_path, 'parts/' ) || str_starts_with( $relative_path, 'patterns/' );
	}

	/**
	 * Extract block markup from a generated block document.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @param string $content       Generated file content.
	 * @return string
	 */
	private static function generated_block_document_markup( string $relative_path, string $content ): string {
		if ( str_starts_with( $relative_path, 'patterns/' ) ) {
			$parts = explode( '?>', $content, 2 );
			return trim( 2 === count( $parts ) ? $parts[1] : $content );
		}

		return trim( $content );
	}

	/**
	 * Analyze one generated block document for server-visible quality issues.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @param string $block_markup  Block markup.
	 * @return array<string,mixed>
	 */
	private static function analyze_generated_block_document( string $relative_path, string $block_markup ): array {
		$blocks          = parse_blocks( $block_markup );
		$block_count     = 0;
		$core_html_count = 0;
		$freeform_count  = 0;
		$invalid_count   = 0;

		/** @var array<int, array<string, mixed>> $analyzed_blocks */
		$analyzed_blocks = $blocks;
		self::analyze_generated_block_list( $analyzed_blocks, $block_count, $core_html_count, $freeform_count, $invalid_count, $relative_path );

		$serialized             = serialize_blocks( $blocks );
		$serialization_mismatch = self::normalize_block_document_for_report( $block_markup ) !== self::normalize_block_document_for_report( $serialized );
		if ( $serialization_mismatch ) {
			++$invalid_count;
		}

		self::$conversion_report['quality']['core_html_block_count'] += $core_html_count;
		self::$conversion_report['quality']['freeform_block_count']  += $freeform_count;
		self::$conversion_report['quality']['invalid_block_count']   += $invalid_count;
		if ( $invalid_count > 0 ) {
			++self::$conversion_report['quality']['invalid_block_document_count'];
			self::$conversion_report['diagnostics'][] = array(
				'type'                   => 'invalid_block_document',
				'source'                 => $relative_path,
				'block_count'            => $block_count,
				'core_html_block_count'  => $core_html_count,
				'freeform_block_count'   => $freeform_count,
				'invalid_block_count'    => $invalid_count,
				'serialization_mismatch' => $serialization_mismatch,
				'original_excerpt'       => self::diagnostic_excerpt( $block_markup ),
				'serialized_excerpt'     => self::diagnostic_excerpt( $serialized ),
			);
		}

		return array(
			'path'                   => $relative_path,
			'block_count'            => $block_count,
			'core_html_block_count'  => $core_html_count,
			'freeform_block_count'   => $freeform_count,
			'invalid_block_count'    => $invalid_count,
			'serialization_mismatch' => $serialization_mismatch,
		);
	}

	/**
	 * Walk parsed blocks for generated-theme quality metrics.
	 *
	 * @param array<int,array<string,mixed>> $blocks          Parsed blocks.
	 * @param int                           $block_count     Total named block count.
	 * @param int                           $core_html_count HTML block count.
	 * @param int                           $freeform_count  Freeform block count.
	 * @param int                           $invalid_count   Invalid block count.
	 * @param string                        $source          Theme-relative source document path.
	 * @param array<int,int>                $path            Parsed block path.
	 * @return void
	 */
	private static function analyze_generated_block_list( array $blocks, int &$block_count, int &$core_html_count, int &$freeform_count, int &$invalid_count, string $source = '', array $path = array() ): void {
		foreach ( $blocks as $index => $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
			if ( is_string( $name ) && '' !== $name ) {
				++$block_count;
				if ( 'core/html' === $name ) {
					++$core_html_count;
					self::record_generated_core_html_block( $source, array_merge( $path, array( $index ) ), $block );
				}
				if ( 'core/freeform' === $name ) {
					++$freeform_count;
					self::record_generated_freeform_block( $source, array_merge( $path, array( $index ) ), $block, false );
				}
			} elseif ( '' !== trim( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
				++$freeform_count;
				++$invalid_count;
				self::record_generated_freeform_block( $source, array_merge( $path, array( $index ) ), $block, true );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::analyze_generated_block_list( $block['innerBlocks'], $block_count, $core_html_count, $freeform_count, $invalid_count, $source, array_merge( $path, array( $index ) ) );
			}
		}
	}

	/**
	 * Record an actionable generated core/html block diagnostic.
	 *
	 * @param string              $source Theme-relative source document path.
	 * @param array<int,int>      $path   Parsed block path.
	 * @param array<string,mixed> $block  Parsed block.
	 * @return void
	 */
	private static function record_generated_core_html_block( string $source, array $path, array $block ): void {
		$html = '';
		if ( isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ) {
			$html = $block['attrs']['content'];
		} elseif ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		self::$conversion_report['diagnostics'][] = self::fallback_diagnostic_entry(
			'core_html_block',
			$source,
			$html,
			array(
				'reason' => 'generated_document_contains_core_html',
				'stage'  => 'generated_theme_block_analysis',
				'path'   => implode( '.', $path ),
			),
			$block
		);
	}

	/**
	 * Record an actionable generated freeform block diagnostic.
	 *
	 * @param string              $source     Theme-relative source document path.
	 * @param array<int,int>      $path       Parsed block path.
	 * @param array<string,mixed> $block      Parsed block.
	 * @param bool                $malformed  Whether the block parser exposed raw HTML without a block name.
	 * @return void
	 */
	private static function record_generated_freeform_block( string $source, array $path, array $block, bool $malformed ): void {
		$html = '';
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		$emitted = '';
		if ( ! $malformed && function_exists( 'serialize_blocks' ) ) {
			// @phpstan-ignore-next-line argument.type -- Parsed block shape comes from WordPress parse_blocks().
			$emitted = serialize_blocks( array( $block ) );
		}
		if ( '' === trim( $emitted ) ) {
			$emitted = $html;
		}

		$entry                          = self::fallback_diagnostic_entry(
			'freeform_block',
			$source,
			$html,
			array(
				'reason' => $malformed ? 'generated_document_contains_malformed_freeform_html' : 'generated_document_contains_core_freeform',
				'stage'  => 'generated_theme_block_analysis',
				'path'   => implode( '.', $path ),
			),
			$block
		);
		$entry['emitted_block_preview'] = self::diagnostic_excerpt( $emitted );
		$entry['malformed']             = $malformed;

		self::$conversion_report['diagnostics'][]                        = $entry;
		self::$conversion_report['generated_theme']['freeform_blocks'][] = $entry;
	}

	/**
	 * Normalize generated markup enough to avoid formatting-only report noise.
	 *
	 * @param string $markup Block document markup.
	 * @return string
	 */
	private static function normalize_block_document_for_report( string $markup ): string {
		$markup = str_replace( array( "\r\n", "\r" ), "\n", trim( $markup ) );
		$markup = preg_replace( '/>\s+</', '><', $markup );
		$markup = preg_replace( '/\s+/', ' ', is_string( $markup ) ? $markup : '' );

		return is_string( $markup ) ? trim( $markup ) : '';
	}

	/**
	 * Record generated core/button wrapper classes from serialized block markup.
	 *
	 * @param string $blocks Serialized block markup.
	 * @return void
	 */
	private static function record_button_wrapper_classes_from_blocks( string $blocks ): void {
		if ( '' === trim( $blocks ) || ! function_exists( 'parse_blocks' ) ) {
			return;
		}

		/** @var array<int, array<string, mixed>> $parsed_blocks */
		$parsed_blocks = parse_blocks( $blocks );
		self::record_button_wrapper_classes_from_parsed_blocks( $parsed_blocks );
	}

	/**
	 * Record generated core/button wrapper classes from parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed block list.
	 * @return void
	 */
	private static function record_button_wrapper_classes_from_parsed_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( 'core/button' === ( $block['blockName'] ?? null ) && ! empty( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ) {
				self::record_button_wrapper_classes( $block['attrs']['className'] );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::record_button_wrapper_classes_from_parsed_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Record individual class tokens present on generated core/button wrappers.
	 *
	 * @param string $class_name Space-separated class attribute.
	 * @return void
	 */
	private static function record_button_wrapper_classes( string $class_name ): void {
		$classes = preg_split( '/\s+/', trim( $class_name ) );
		if ( ! is_array( $classes ) ) {
			return;
		}

		foreach ( $classes as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				self::$button_wrapper_classes[ $class ] = true;
			}
		}
	}

	/**
	 * Record an unsupported HTML fallback emitted by h2bc.
	 *
	 * @param string $source       Source fragment label.
	 * @param string $element_html Unsupported HTML.
	 * @param array  $context      Fallback context.
	 * @param array  $block        Generated block.
	 * @return void
	 */
	private static function record_unsupported_fallback( string $source, string $element_html, array $context, array $block ): void {
		++self::$conversion_report['quality']['fallback_count'];
		++self::$conversion_report['conversion_fragments'][ $source ]['fallback_count'];
		self::$conversion_report['diagnostics'][] = self::fallback_diagnostic_entry( 'unsupported_html_fallback', $source, $element_html, $context, $block );
	}

	/**
	 * Build a normalized fallback/core-html diagnostic entry.
	 *
	 * @param string              $type         Diagnostic type.
	 * @param string              $source       Source fragment or generated document path.
	 * @param string              $element_html Source HTML fragment.
	 * @param array<string,mixed> $context      Diagnostic context.
	 * @param array<string,mixed> $block        Generated or parsed block.
	 * @return array<string,mixed>
	 */
	private static function fallback_diagnostic_entry( string $type, string $source, string $element_html, array $context, array $block ): array {
		$selector = isset( $context['selector'] ) && is_scalar( $context['selector'] ) ? trim( (string) $context['selector'] ) : '';
		if ( '' === $selector ) {
			$selector = self::diagnostic_selector_from_html( $element_html );
		}

		$emitted = '';
		if ( function_exists( 'serialize_blocks' ) ) {
			// @phpstan-ignore-next-line argument.type -- Parsed block shape comes from WordPress parse_blocks() or h2bc.
			$emitted = serialize_blocks( array( $block ) );
		}
		if ( '' === trim( $emitted ) || preg_match( '/^<!--\s+wp:[^>]+\/-->$/', trim( $emitted ) ) ) {
			$emitted = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : $element_html;
		}

		$entry = array(
			'type'                  => $type,
			'source'                => $source,
			'selector'              => '' !== $selector ? $selector : null,
			'excerpt'               => self::diagnostic_excerpt( wp_strip_all_tags( $element_html ) ),
			'source_html_preview'   => self::diagnostic_excerpt( $element_html ),
			'emitted_block_preview' => self::diagnostic_excerpt( $emitted ),
			'reason'                => isset( $context['reason'] ) ? (string) $context['reason'] : 'unknown',
			'tag_name'              => isset( $context['tag_name'] ) ? (string) $context['tag_name'] : self::diagnostic_tag_name_from_html( $element_html ),
			'block_name'            => isset( $block['blockName'] ) ? (string) $block['blockName'] : null,
			'converter'             => 'html-to-blocks-converter',
			'stage'                 => isset( $context['stage'] ) ? (string) $context['stage'] : 'html_to_blocks',
			'html_length'           => strlen( $element_html ),
			'html_excerpt'          => self::diagnostic_excerpt( $element_html ),
		);

		if ( isset( $context['occurrence'] ) ) {
			$entry['occurrence'] = (int) $context['occurrence'];
		}

		if ( isset( $context['path'] ) && is_scalar( $context['path'] ) ) {
			$entry['block_path'] = (string) $context['path'];
		}

		return $entry;
	}

	/**
	 * Record a content-loss abort emitted by h2bc.
	 *
	 * @param string $source                 Source fragment label.
	 * @param int    $original_text_length   Original text length.
	 * @param int    $serialized_text_length Serialized text length.
	 * @return void
	 */
	private static function record_content_loss( string $source, int $original_text_length, int $serialized_text_length ): void {
		++self::$conversion_report['quality']['content_loss_count'];
		++self::$conversion_report['conversion_fragments'][ $source ]['content_loss_count'];
		self::$conversion_report['diagnostics'][] = array(
			'type'                   => 'content_loss_abort',
			'source'                 => $source,
			'original_text_length'   => $original_text_length,
			'serialized_text_length' => $serialized_text_length,
		);
	}

	/**
	 * Record an empty conversion result for a non-empty fragment.
	 *
	 * @param string $source Source fragment label.
	 * @param string $html   Source HTML.
	 * @return void
	 */
	private static function record_conversion_empty( string $source, string $html ): void {
		++self::$conversion_report['quality']['empty_conversion_count'];
		self::$conversion_report['conversion_fragments'][ $source ]['empty_conversion'] = true;

		self::$conversion_report['diagnostics'][] = array(
			'type'         => 'empty_conversion',
			'source'       => $source,
			'html_length'  => strlen( $html ),
			'html_excerpt' => self::diagnostic_excerpt( $html ),
		);
	}

	/**
	 * Finalize quality summary and gate status.
	 *
	 * @param array $args Import args.
	 * @return array<string, mixed>
	 */
	private static function finalize_quality_report( array $args ): array {
		self::normalize_import_diagnostics();

		$quality = self::$conversion_report['quality'];
		$reasons = array();
		if ( $quality['fallback_count'] > 0 ) {
			$reasons[] = 'unsupported_html_fallback';
		}
		if ( $quality['content_loss_count'] > 0 ) {
			$reasons[] = 'content_loss_abort';
		}
		if ( $quality['empty_conversion_count'] > 0 ) {
			$reasons[] = 'empty_conversion';
		}
		if ( $quality['core_html_block_count'] > 0 ) {
			$reasons[] = 'core_html_block';
		}
		if ( $quality['freeform_block_count'] > 0 ) {
			$reasons[] = 'freeform_block';
		}
		if ( $quality['invalid_block_count'] > 0 ) {
			$reasons[] = 'invalid_block';
		}
		if ( $quality['unsafe_svg_count'] > 0 ) {
			$reasons[] = 'unsafe_inline_svg';
		}
		if ( $quality['svg_materialization_failure_count'] > 0 ) {
			$reasons[] = 'svg_materialization_failure';
		}
		if ( $quality['svg_sprite_reference_failure_count'] > 0 ) {
			$reasons[] = 'svg_sprite_reference_failure';
		}
		if ( ( $quality['commerce_dependency_failures'] ?? 0 ) > 0 ) {
			$reasons[] = 'woocommerce_missing';
		}

		$quality['pass']            = empty( $reasons );
		$quality['failure_reasons'] = $reasons;
		$quality['fail_import']     = false;
		if ( ! empty( $args['fail_on_quality'] ) && ! $quality['pass'] ) {
			$quality['fail_import'] = true;
		}
		if ( array_key_exists( 'max_fallbacks', $args ) && null !== $args['max_fallbacks'] && $quality['fallback_count'] > (int) $args['max_fallbacks'] ) {
			$quality['fail_import'] = true;
		}
		// Commerce dependency failures are always import-failing regardless of --fail-on-quality.
		// Silently producing a no-products WordPress site for a commerce import is qualitatively
		// worse than a layout fallback, so the gate trips even without an explicit failure flag.
		if ( in_array( 'woocommerce_missing', $reasons, true ) ) {
			$quality['fail_import'] = true;
		}

		$quality['diagnostic_refs']         = self::quality_diagnostic_refs( self::$conversion_report['diagnostics'] ?? array() );
		self::$conversion_report['quality'] = $quality;
		self::normalize_source_document_diagnostic_refs();
		self::$conversion_report['artifact_diagnostics'] = Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer::build(
			array( 'diagnostics' => self::$conversion_report['diagnostics'] ?? array() ),
			array(
				'source'          => 'static-site-importer',
				'stage'           => 'import',
				'observationType' => 'static-site-importer/import-report',
				'refs'            => array(
					array(
						'path' => 'import-report.json',
						'kind' => 'static-site-importer/import-report',
					),
				),
			)
		);
		return $quality;
	}

	/**
	 * Normalize import diagnostics into a stable, machine-actionable shape.
	 *
	 * Existing human-readable fields remain alongside the machine fields.
	 * The added fields give repair loops generic, path-addressed records without
	 * coupling the report to a specific downstream consumer.
	 *
	 * @return void
	 */
	private static function normalize_import_diagnostics(): void {
		if ( empty( self::$conversion_report['diagnostics'] ) || ! is_array( self::$conversion_report['diagnostics'] ) ) {
			return;
		}

		$normalized = array();
		foreach ( array_values( self::$conversion_report['diagnostics'] ) as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$type        = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'import_diagnostic';
			$source      = isset( $diagnostic['source'] ) && is_scalar( $diagnostic['source'] ) ? (string) $diagnostic['source'] : '';
			$source_path = isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : self::diagnostic_source_path( $source );
			$reason_code = self::diagnostic_reason_code( $type, $diagnostic );

			$machine = array(
				'id'                     => self::diagnostic_id( $index, $type, $source_path, $reason_code ),
				'severity'               => self::diagnostic_severity( $type ),
				'category'               => self::diagnostic_category( $type ),
				'reason_code'            => $reason_code,
				'suggested_repair_class' => self::diagnostic_repair_class( $type ),
				'source_path'            => $source_path,
			);

			$selector = isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? trim( (string) $diagnostic['selector'] ) : '';
			if ( '' !== $selector ) {
				$machine['selector'] = $selector;
			}

			if ( isset( $diagnostic['line'] ) && is_numeric( $diagnostic['line'] ) ) {
				$machine['line'] = (int) $diagnostic['line'];
			}

			if ( isset( $diagnostic['line_range'] ) ) {
				$machine['line_range'] = $diagnostic['line_range'];
			}

			$context = self::diagnostic_context( $diagnostic );
			if ( ! empty( $context ) ) {
				$machine['context'] = $context;
			}

			$normalized[] = array_merge( $machine, $diagnostic );
		}

		self::$conversion_report['diagnostics'] = $normalized;
	}

	/**
	 * Build quality counter references into normalized diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,array<int,string>> Diagnostic IDs keyed by quality count.
	 */
	private static function quality_diagnostic_refs( array $diagnostics ): array {
		$types_by_count = array(
			'fallback_count'                     => array( 'unsupported_html_fallback' ),
			'content_loss_count'                 => array( 'content_loss_abort' ),
			'empty_conversion_count'             => array( 'empty_conversion' ),
			'core_html_block_count'              => array( 'core_html_block' ),
			'freeform_block_count'               => array( 'freeform_block' ),
			'invalid_block_count'                => array( 'invalid_block_document' ),
			'unsafe_svg_count'                   => array( 'unsafe_inline_svg' ),
			'svg_materialization_failure_count'  => array( 'svg_materialization_failure' ),
			'svg_sprite_reference_failure_count' => array( 'svg_sprite_reference_failure' ),
			'commerce_dependency_failures'       => array( 'commerce_dependency_failure' ),
		);

		$refs = array();
		foreach ( $types_by_count as $count_key => $types ) {
			$refs[ $count_key ] = array_values(
				array_filter(
					array_map(
						static function ( array $diagnostic ) use ( $types ): string {
							return in_array( $diagnostic['type'] ?? '', $types, true ) && isset( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '';
						},
						$diagnostics
					)
				)
			);
		}

		return $refs;
	}

	/**
	 * Link source-document counts back to concrete diagnostics.
	 *
	 * @return void
	 */
	private static function normalize_source_document_diagnostic_refs(): void {
		$diagnostics = isset( self::$conversion_report['diagnostics'] ) && is_array( self::$conversion_report['diagnostics'] ) ? self::$conversion_report['diagnostics'] : array();
		$refs        = array(
			'unresolved_link_count'      => array(),
			'skipped_mdx_count'          => array(),
			'markdown_parse_error_count' => array(),
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || empty( $diagnostic['id'] ) ) {
				continue;
			}

			$type = (string) ( $diagnostic['type'] ?? '' );
			if ( 'unresolved_internal_link' === $type ) {
				$refs['unresolved_link_count'][] = (string) $diagnostic['id'];
			} elseif ( 'unsupported_source_document' === $type ) {
				$refs['skipped_mdx_count'][] = (string) $diagnostic['id'];
			} elseif ( 'markdown_parse_error' === $type ) {
				$refs['markdown_parse_error_count'][] = (string) $diagnostic['id'];
			}
		}

		self::$conversion_report['source_documents']['diagnostic_refs'] = $refs;
	}

	/**
	 * Build a stable diagnostic ID.
	 *
	 * @param int    $index       Zero-based diagnostic position.
	 * @param string $type        Diagnostic type.
	 * @param string $source_path Source-relative path.
	 * @param string $reason_code Reason code.
	 * @return string Diagnostic ID.
	 */
	private static function diagnostic_id( int $index, string $type, string $source_path, string $reason_code ): string {
		$slug = sanitize_key( $type . '-' . $reason_code . '-' . $source_path );
		if ( '' === $slug ) {
			$slug = 'import-diagnostic';
		}

		return sprintf( 'diag-%03d-%s', $index + 1, substr( $slug, 0, 80 ) );
	}

	/**
	 * Infer a source-relative path from a diagnostic source label.
	 *
	 * @param string $source Diagnostic source label.
	 * @return string Source-relative path or generated artifact path.
	 */
	private static function diagnostic_source_path( string $source ): string {
		if ( str_contains( $source, ':' ) ) {
			return (string) substr( $source, strpos( $source, ':' ) + 1 );
		}

		return $source;
	}

	/**
	 * Normalize a diagnostic reason code.
	 *
	 * @param string              $type       Diagnostic type.
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return string Reason code.
	 */
	private static function diagnostic_reason_code( string $type, array $diagnostic ): string {
		foreach ( array( 'reason_code', 'reason', 'error_code' ) as $key ) {
			if ( isset( $diagnostic[ $key ] ) && is_scalar( $diagnostic[ $key ] ) && '' !== trim( (string) $diagnostic[ $key ] ) ) {
				return sanitize_key( (string) $diagnostic[ $key ] );
			}
		}

		return sanitize_key( $type );
	}

	/**
	 * Classify diagnostic severity for repair prioritization.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Severity.
	 */
	private static function diagnostic_severity( string $type ): string {
		if ( in_array( $type, array( 'content_loss_abort', 'empty_conversion', 'invalid_block_document', 'commerce_dependency_failure' ), true ) ) {
			return 'error';
		}

		return 'warning';
	}

	/**
	 * Classify diagnostics by generic repair category.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Category.
	 */
	private static function diagnostic_category( string $type ): string {
		$categories = array(
			'local_asset_not_materialized'         => 'unresolved_asset',
			'unresolved_internal_link'             => 'broken_internal_link',
			'unsafe_inline_svg'                    => 'unsafe_svg',
			'svg_materialization_failure'          => 'unresolved_asset',
			'svg_sprite_reference_failure'         => 'unresolved_asset',
			'unsupported_source_document'          => 'unsupported_source',
			'unsupported_html_fallback'            => 'unsupported_element',
			'core_html_block'                      => 'fallback_block',
			'freeform_block'                       => 'fallback_block',
			'invalid_block_document'               => 'conversion_quality',
			'content_loss_abort'                   => 'conversion_quality',
			'empty_conversion'                     => 'conversion_quality',
			'source_region_unassigned'             => 'source_region',
			'commerce_dependency_failure'          => 'conversion_quality',
			'commerce_product_inference_unmatched' => 'conversion_quality',
		);

		return $categories[ $type ] ?? 'import_quality';
	}

	/**
	 * Suggest a generic repair class for a diagnostic type.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Suggested repair class.
	 */
	private static function diagnostic_repair_class( string $type ): string {
		$classes = array(
			'local_asset_not_materialized'         => 'materialize_or_rewrite_asset',
			'unresolved_internal_link'             => 'rewrite_or_create_internal_target',
			'unsafe_inline_svg'                    => 'sanitize_or_externalize_svg',
			'svg_materialization_failure'          => 'materialize_or_rewrite_asset',
			'svg_sprite_reference_failure'         => 'materialize_or_rewrite_asset',
			'unsupported_source_document'          => 'convert_source_document',
			'unsupported_html_fallback'            => 'replace_unsupported_html',
			'core_html_block'                      => 'replace_fallback_block',
			'freeform_block'                       => 'replace_fallback_block',
			'invalid_block_document'               => 'repair_generated_block_markup',
			'content_loss_abort'                   => 'repair_source_conversion',
			'empty_conversion'                     => 'repair_source_conversion',
			'source_region_unassigned'             => 'assign_or_ignore_source_region',
			'commerce_dependency_failure'          => 'install_or_configure_dependency',
			'commerce_product_inference_unmatched' => 'provide_structured_product_data',
		);

		return $classes[ $type ] ?? 'inspect_import_diagnostic';
	}

	/**
	 * Extract concise diagnostic context for repair prompts.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return array<string,mixed> Context fields.
	 */
	private static function diagnostic_context( array $diagnostic ): array {
		$context = array();
		foreach ( array( 'href', 'tag', 'tag_name', 'block_name', 'block_path', 'excerpt', 'html_excerpt', 'source_html_preview', 'error_message' ) as $key ) {
			if ( array_key_exists( $key, $diagnostic ) && null !== $diagnostic[ $key ] && '' !== $diagnostic[ $key ] ) {
				$context[ $key ] = $diagnostic[ $key ];
			}
		}

		return $context;
	}

	/**
	 * Build the compact report summary consumed by validation harnesses.
	 *
	 * @param array<string, mixed> $report  Full conversion report.
	 * @param array<string, mixed> $quality Finalized quality summary.
	 * @return array<string, mixed>
	 */
	private static function import_report_summary( array $report, array $quality ): array {
		$diagnostics      = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$source_documents = isset( $report['source_documents'] ) && is_array( $report['source_documents'] ) ? $report['source_documents'] : array();

		return array(
			'schema'                       => 'static-site-importer/import-metrics/v1',
			'version'                      => 1,
			'report_version'               => (int) ( $report['version'] ?? 0 ),
			'status'                       => ! empty( $quality['fail_import'] ) ? 'failed' : 'completed',
			'theme_slug'                   => isset( $report['theme_slug'] ) ? (string) $report['theme_slug'] : '',
			'entry_file'                   => isset( $report['entry_file'] ) ? (string) $report['entry_file'] : '',
			'compiler'                     => self::compact_import_report_compiler_summary( $report ),
			'quality_pass'                 => ! empty( $quality['pass'] ),
			'fail_import'                  => ! empty( $quality['fail_import'] ),
			'failure_reasons'              => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'fallback_count'               => (int) ( $quality['fallback_count'] ?? 0 ),
			'content_loss_count'           => (int) ( $quality['content_loss_count'] ?? 0 ),
			'empty_conversion_count'       => (int) ( $quality['empty_conversion_count'] ?? 0 ),
			'core_html_block_count'        => (int) ( $quality['core_html_block_count'] ?? 0 ),
			'freeform_block_count'         => (int) ( $quality['freeform_block_count'] ?? 0 ),
			'invalid_block_count'          => (int) ( $quality['invalid_block_count'] ?? 0 ),
			'invalid_block_document_count' => (int) ( $quality['invalid_block_document_count'] ?? 0 ),
			'source_document_count'        => (int) ( $source_documents['total_count'] ?? 0 ),
			'unresolved_link_count'        => (int) ( $source_documents['unresolved_link_count'] ?? 0 ),
			'diagnostic_count'             => count( $diagnostics ),
			'diagnostic_summary'           => self::compact_import_report_diagnostic_summary( $diagnostics ),
			'warning_summaries'            => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'warning' ),
			'error_summaries'              => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'error' ),
			'diagnostics'                  => self::compact_import_report_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Summarize compiler evidence for compact import metrics.
	 *
	 * @param array<string,mixed> $report Full conversion report.
	 * @return array<string,mixed>
	 */
	private static function compact_import_report_compiler_summary( array $report ): array {
		$compiler = isset( $report['block_artifact_compiler'] ) && is_array( $report['block_artifact_compiler'] ) ? $report['block_artifact_compiler'] : array();
		$summary  = array(
			'available'      => ! empty( $compiler['available'] ),
			'fragment_count' => (int) ( $compiler['fragment_count'] ?? 0 ),
		);

		$website_artifact = isset( $compiler['website_artifact'] ) && is_array( $compiler['website_artifact'] ) ? $compiler['website_artifact'] : array();
		$compiler_summary = isset( $website_artifact['summary'] ) && is_array( $website_artifact['summary'] ) ? $website_artifact['summary'] : array();
		if ( empty( $compiler_summary ) && ! empty( $compiler['fragments'][0]['summary'] ) && is_array( $compiler['fragments'][0]['summary'] ) ) {
			$compiler_summary = $compiler['fragments'][0]['summary'];
		}

		foreach ( array( 'schema', 'status', 'source' ) as $field ) {
			if ( isset( $compiler_summary[ $field ] ) && is_scalar( $compiler_summary[ $field ] ) ) {
				$summary[ $field ] = (string) $compiler_summary[ $field ];
			}
		}

		if ( isset( $compiler_summary['diagnostic_count'] ) ) {
			$summary['diagnostic_count'] = (int) $compiler_summary['diagnostic_count'];
		}

		return $summary;
	}

	/**
	 * Summarize compact diagnostics by severity.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,int>
	 */
	private static function compact_import_report_diagnostic_summary( array $diagnostics ): array {
		$summary = array(
			'total'   => 0,
			'error'   => 0,
			'warning' => 0,
			'notice'  => 0,
			'info'    => 0,
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			++$summary['total'];
			$severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'warning';
			if ( ! array_key_exists( $severity, $summary ) ) {
				$severity = 'warning';
			}

			++$summary[ $severity ];
		}

		return $summary;
	}

	/**
	 * Build concise diagnostic summaries for a severity bucket.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @param string                         $severity    Severity to include.
	 * @return array<int,array<string,string>>
	 */
	private static function compact_import_report_diagnostic_summaries_by_severity( array $diagnostics, string $severity ): array {
		$summaries = array();
		foreach ( $diagnostics as $diagnostic ) {
			$diagnostic_severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'warning';
			if ( $severity !== $diagnostic_severity ) {
				continue;
			}

			$summaries[] = array(
				'id'      => isset( $diagnostic['id'] ) && is_scalar( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '',
				'type'    => isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic',
				'source'  => self::diagnostic_summary_source( $diagnostic ),
				'message' => self::diagnostic_summary_message( $diagnostic ),
			);

			if ( count( $summaries ) >= 10 ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Resolve a concise diagnostic source label.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function diagnostic_summary_source( array $diagnostic ): string {
		foreach ( array( 'source_path', 'source' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) ) {
				return (string) $diagnostic[ $field ];
			}
		}

		return '';
	}

	/**
	 * Resolve a concise diagnostic message.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function diagnostic_summary_message( array $diagnostic ): string {
		foreach ( array( 'message', 'reason', 'excerpt', 'error_message', 'html_excerpt' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && '' !== trim( (string) $diagnostic[ $field ] ) ) {
				return self::diagnostic_excerpt( (string) $diagnostic[ $field ] );
			}
		}

		return isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic';
	}

	/**
	 * Keep summary diagnostics compact while preserving repair-agent evidence.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<int,array<string,mixed>> Compact diagnostics for validation harnesses.
	 */
	private static function compact_import_report_diagnostics( array $diagnostics ): array {
		$fields = array(
			'id',
			'type',
			'severity',
			'category',
			'reason_code',
			'suggested_repair_class',
			'source_path',
			'source',
			'selector',
			'excerpt',
			'source_html_preview',
			'emitted_block_preview',
			'block_name',
			'block_path',
			'converter',
			'stage',
			'reason',
			'message',
			'tag_name',
			'html_excerpt',
			'context',
		);

		$compact = array();
		foreach ( array_slice( $diagnostics, 0, 50 ) as $diagnostic ) {
			$row = array();
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $diagnostic ) || null === $diagnostic[ $field ] || '' === $diagnostic[ $field ] || array() === $diagnostic[ $field ] ) {
					continue;
				}

				$row[ $field ] = $diagnostic[ $field ];
			}

			if ( ! empty( $row ) ) {
				$compact[] = $row;
			}
		}

		return $compact;
	}

	/**
	 * Infer a compact CSS-like selector from an HTML preview.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	private static function diagnostic_selector_from_html( string $html ): string {
		if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b([^>]*)>/i', $html, $match ) ) {
			return '';
		}

		$selector = strtolower( (string) $match[1] );
		$attrs    = (string) $match[2];
		if ( preg_match( '/\sid\s*=\s*(["\'])(.*?)\1/i', $attrs, $id_match ) ) {
			$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id_match[2] );
			if ( is_string( $id ) && '' !== $id ) {
				$selector .= '#' . $id;
			}
		}

		if ( preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $class_match ) ) {
			$classes = preg_split( '/\s+/', trim( (string) $class_match[2] ) );
			if ( is_array( $classes ) ) {
				foreach ( array_slice( $classes, 0, 3 ) as $class ) {
					$class = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
					if ( is_string( $class ) && '' !== $class ) {
						$selector .= '.' . $class;
					}
				}
			}
		}

		return $selector;
	}

	/**
	 * Infer the first element tag name from an HTML preview.
	 *
	 * @param string $html Source HTML.
	 * @return string|null
	 */
	private static function diagnostic_tag_name_from_html( string $html ): ?string {
		if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b/i', $html, $match ) ) {
			return null;
		}

		return strtoupper( (string) $match[1] );
	}

	/**
	 * Build a compact diagnostic excerpt.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	private static function diagnostic_excerpt( string $html ): string {
		$excerpt = preg_replace( '/\s+/', ' ', trim( $html ) );
		$excerpt = is_string( $excerpt ) ? $excerpt : trim( $html );
		return substr( $excerpt, 0, 300 );
	}

	/**
	 * Build style.css.
	 *
	 * @param string $theme_name Theme name.
	 * @param string $css        Source CSS.
	 * @return string
	 */
	private static function style_css( string $theme_name, string $css, array $button_classes = array() ): string {
		$button_bridge              = self::button_style_bridge_css( $css, $button_classes );
		$admin_bar_bridge           = self::admin_bar_top_chrome_css( $css );
		$source_nav_selector_bridge = self::source_nav_selector_bridge_css( $css );
		$source_display_bridge      = self::source_display_selector_bridge_css( $css );
		$image_block_bridge         = self::source_image_block_selector_bridge_css( $css );
		$form_control_bridge        = self::source_form_control_selector_bridge_css( $css );
		$layout_gap_bridge          = self::imported_group_layout_gap_bridge_css();
		$css                        = self::scope_source_button_css( $css, $button_classes );

		return "/*\nTheme Name: " . $theme_name . "\nAuthor: Static Site Importer\nDescription: Materialized from a compiled website artifact.\nVersion: 0.1.0\nRequires at least: 6.6\n*/\n\n" . $css . "\n" . $button_bridge . $admin_bar_bridge . $source_nav_selector_bridge . $source_display_bridge . $image_block_bridge . $layout_gap_bridge . $form_control_bridge;
	}

	/**
	 * Build editor-style.css.
	 *
	 * @param string $css Source CSS.
	 * @return string
	 */
	private static function editor_style_css( string $css, array $button_classes = array() ): string {
		$button_bridge              = self::button_style_bridge_css( $css, $button_classes );
		$editor_bridge              = self::editor_absolute_overlay_css( $css );
		$editor_reveal_bridge       = self::editor_reveal_animation_css( $css );
		$source_nav_selector_bridge = self::source_nav_selector_bridge_css( $css );
		$source_display_bridge      = self::source_display_selector_bridge_css( $css );
		$image_block_bridge         = self::source_image_block_selector_bridge_css( $css );
		$form_control_bridge        = self::source_form_control_selector_bridge_css( $css );
		$layout_gap_bridge          = self::imported_group_layout_gap_bridge_css();
		$css                        = self::scope_source_button_css( $css, $button_classes );

		return "/*\nStatic Site Importer editor styles.\nGenerated separately from frontend style.css so editor wrapper repairs do not leak to public rendering.\n*/\n\n" . $css . "\n" . $button_bridge . $source_nav_selector_bridge . $source_display_bridge . $image_block_bridge . $layout_gap_bridge . $form_control_bridge . $editor_bridge . $editor_reveal_bridge;
	}

	/**
	 * Build layout-gap reset rules for imported source groups.
	 *
	 * Static HTML spacing is preserved by the imported source CSS. WordPress adds
	 * block layout gap/margins to generated group wrappers, which can stretch
	 * stacked hero and section groups beyond the source document height.
	 *
	 * @return string Additional CSS rules.
	 */
	private static function imported_group_layout_gap_bridge_css(): string {
		return "\n/* Static Site Importer: preserve source-authored spacing inside converted source wrappers. */\n.wp-block-post-content.is-layout-flow > *,\n.wp-block-group.is-layout-flow > *,\n.wp-block-group.is-vertical > * { margin-block-start: 0; margin-block-end: 0; }\n.wp-block-group.is-layout-flex,\n.wp-block-group.is-vertical { gap: 0; }\n";
	}

	/**
	 * Build selector parity rules for source classes converted to group blocks.
	 *
	 * WordPress layout classes can override source-authored rules on converted group
	 * wrappers. Re-emitting simple class rules with the block wrapper class keeps
	 * authored layouts intact, including media-query overrides, without hard-coded
	 * selectors.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_display_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) ) {
			return '';
		}

		$rules = self::source_display_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source class rules on converted group wrappers. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build source selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int,string> CSS rules.
	 */
	private static function source_display_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$depth      = 1;
			$position   = $body_start;
			while ( $position < $length && $depth > 0 ) {
				$char = $css[ $position ];
				if ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char ) {
					--$depth;
				}

				++$position;
			}

			$body   = substr( $css, $body_start, max( 0, $position - $body_start - 1 ) );
			$offset = $position;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_display_selector_bridge_rules_from_css( $body );
				if ( ! empty( $nested ) ) {
					$rules[] = $prelude . ' { ' . implode( ' ', $nested ) . ' }';
				}

				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_display_selector_bridge_selector( trim( $selector ) );
				if ( null !== $rewritten ) {
					$selectors[] = $rewritten;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' {' . trim( $body ) . '}';
		}

		return $rules;
	}

	/**
	 * Rewrite one source selector for converted group display parity.
	 *
	 * @param string $selector Source selector.
	 * @return string|null Rewritten selector, or null when not applicable.
	 */
	private static function source_display_selector_bridge_selector( string $selector ): ?string {
		if ( '' === $selector || str_contains( $selector, '.wp-block-' ) || str_contains( $selector, '#' ) || str_contains( $selector, ':' ) || str_contains( $selector, '[' ) || str_contains( $selector, ' ' ) || str_contains( $selector, '>' ) || str_contains( $selector, '+' ) || str_contains( $selector, '~' ) ) {
			return null;
		}

		if ( ! preg_match( '/^(?:[a-z][a-z0-9_-]*)?((?:\.[a-z0-9_-]+)+)$/i', $selector, $match ) ) {
			return null;
		}

		return '.wp-block-group' . $match[1];
	}

	/**
	 * Build selector parity rules for source image grids converted to image blocks.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_image_block_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( strtolower( $css ), 'img' ) ) {
			return '';
		}

		$rules = self::source_image_block_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source image-grid selectors on native image blocks. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build image block selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int,string> CSS rules.
	 */
	private static function source_image_block_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$depth      = 1;
			$position   = $body_start;
			while ( $position < $length && $depth > 0 ) {
				$char = $css[ $position ];
				if ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char ) {
					--$depth;
				}

				++$position;
			}

			$body   = substr( $css, $body_start, max( 0, $position - $body_start - 1 ) );
			$offset = $position;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_image_block_selector_bridge_rules_from_css( $body );
				if ( ! empty( $nested ) ) {
					$rules[] = $prelude . ' { ' . implode( ' ', $nested ) . ' }';
				}

				continue;
			}

			$selectors = array();
			$resets    = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_image_block_selector_bridge_selector( trim( $selector ) );
				if ( empty( $rewritten ) ) {
					continue;
				}

				$selectors = array_merge( $selectors, $rewritten['selectors'] );
				$resets[]  = $rewritten['reset'];
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			foreach ( array_unique( $resets ) as $reset ) {
				$rules[] = $reset . ' {margin:0}';
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' {' . trim( $body ) . '}';
		}

		return $rules;
	}

	/**
	 * Rewrite one source image selector for native image block wrappers.
	 *
	 * @param string $selector Source selector.
	 * @return array{reset:string,selectors:array<int,string>}|null Rewritten selectors.
	 */
	private static function source_image_block_selector_bridge_selector( string $selector ): ?array {
		if ( '' === $selector || str_contains( $selector, '.wp-block-' ) ) {
			return null;
		}

		if ( ! preg_match( '/^((?:[a-z][a-z0-9_-]*)?(?:\.[a-z0-9_-]+)+)\s*>?\s*img((?::first-child|:not\(:first-child\))?)$/i', $selector, $match ) ) {
			return null;
		}

		$container = self::source_display_selector_bridge_selector( $match[1] );
		if ( null === $container ) {
			return null;
		}

		$pseudo = $match[2] ?? '';
		$figure = $container . ' > .wp-block-image' . $pseudo;
		return array(
			'reset'     => $container . ' > .wp-block-image',
			'selectors' => array( $figure, $figure . ' img' ),
		);
	}

	/**
	 * Build selector parity rules for source form controls converted to block surrogates.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_form_control_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! preg_match( '/\b(?:label|input|select|textarea)\b/i', $css ) ) {
			return '';
		}

		$rules = self::source_form_control_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source form-control selectors on native static form surrogates. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build source form-control selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int,string> CSS rules.
	 */
	private static function source_form_control_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_form_control_selector_bridge_rules_from_css( $body );
				foreach ( $nested as $rule ) {
					$rules[] = $prelude . ' { ' . $rule . ' }';
				}
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_form_control_selector_bridge_selector( trim( $selector ) );
				if ( null !== $rewritten ) {
					$selectors[] = $rewritten;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' {' . $body . '}';
		}

		return $rules;
	}

	/**
	 * Rewrite a source form-control selector for h2bc static form surrogates.
	 *
	 * @param string $selector Source selector.
	 * @return string|null Rewritten selector, or null when not applicable.
	 */
	private static function source_form_control_selector_bridge_selector( string $selector ): ?string {
		if ( '' === $selector || str_contains( $selector, '.wp-block-' ) ) {
			return null;
		}

		$rewritten = preg_replace_callback(
			'/(^|[\s>+~,(])(?:label|input|select|textarea)(?=($|[\s>+~),.#:\[]))/i',
			static function ( array $match ): string {
				$token = strtolower( $match[0] );
				$lead  = $match[1];
				$tag   = trim( substr( $token, strlen( $lead ) ) );

				switch ( $tag ) {
					case 'label':
						$replacement = '.static-form-field';
						break;
					case 'input':
						$replacement = '.static-form-control.static-form-input';
						break;
					case 'select':
						$replacement = '.static-form-control.static-form-select';
						break;
					case 'textarea':
						$replacement = '.static-form-control.static-form-textarea';
						break;
					default:
						$replacement = $tag;
				}

				return $lead . $replacement;
			},
			$selector
		);

		if ( ! is_string( $rewritten ) || $rewritten === $selector ) {
			return null;
		}

		return $rewritten;
	}

	/**
	 * Build selector parity rules for source nav wrappers converted to group blocks.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional CSS rules.
	 */
	private static function source_nav_selector_bridge_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( strtolower( $css ), 'nav' ) ) {
			return '';
		}

		$rules = self::source_nav_selector_bridge_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source nav wrapper selectors on converted navigation groups. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build source nav selector bridge rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> CSS rules.
	 */
	private static function source_nav_selector_bridge_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested = self::source_nav_selector_bridge_rules_from_css( $body );
				foreach ( $nested as $rule ) {
					$rules[] = $prelude . ' { ' . $rule . ' }';
				}
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$rewritten = self::source_nav_selector_bridge_selector( trim( $selector ) );
				if ( null !== $rewritten ) {
					$selectors[] = $rewritten;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules[] = implode( ', ', array_unique( $selectors ) ) . ' { ' . $body . ' }';
		}

		return $rules;
	}

	/**
	 * Rewrite a selector so source nav wrappers match converted group wrappers.
	 *
	 * @param string $selector CSS selector.
	 * @return string|null Rewritten selector, or null when no safe nav token exists.
	 */
	private static function source_nav_selector_bridge_selector( string $selector ): ?string {
		if ( '' === $selector || str_starts_with( $selector, '@' ) ) {
			return null;
		}

		$rewritten = preg_replace( '/(^|[\s>+~])nav(?=($|[\s>+~.#:\[]))/', '$1.static-site-importer-source-nav', $selector );
		if ( ! is_string( $rewritten ) || $rewritten === $selector ) {
			return null;
		}

		return $rewritten;
	}

	/**
	 * Build frontend admin-bar offsets for imported fixed/sticky top chrome.
	 *
	 * WordPress adds body.admin-bar but does not offset arbitrary imported fixed
	 * headers. Only selectors that already declare fixed/sticky positioning,
	 * a top value, and header/nav-like naming receive generated offsets.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional frontend CSS rules.
	 */
	private static function admin_bar_top_chrome_css( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( $css, 'position' ) || ! str_contains( $css, 'top' ) ) {
			return '';
		}

		$rules = self::admin_bar_top_chrome_rules_from_css( $css );
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build admin-bar offset rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> CSS rules.
	 */
	private static function admin_bar_top_chrome_rules_from_css( string $css ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$rules = array_merge( $rules, self::admin_bar_top_chrome_rules_from_css( $body ) );
				continue;
			}

			if ( ! preg_match( '/(?:^|;)\s*position\s*:\s*(?:fixed|sticky)\s*(?:!important\s*)?(?:;|$)/i', $body ) ) {
				continue;
			}

			$top = self::css_declaration_value( $body, 'top' );
			if ( null === $top ) {
				continue;
			}

			$desktop_top = self::admin_bar_offset_top_value( $top, '32px' );
			$mobile_top  = self::admin_bar_offset_top_value( $top, '46px' );
			if ( null === $desktop_top || null === $mobile_top ) {
				continue;
			}

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$selector = trim( $selector );
				if ( self::selector_is_plausible_top_chrome( $selector ) ) {
					$source_nav_selector = self::source_nav_selector_bridge_selector( $selector );
					$selectors[]         = 'body.admin-bar ' . ( $source_nav_selector ?? $selector );
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$selector_list = implode( ', ', array_unique( $selectors ) );
			$rules[]       = $selector_list . ' { top: ' . $desktop_top . '; }';
			$rules[]       = '@media screen and (max-width: 782px) { ' . $selector_list . ' { top: ' . $mobile_top . '; } }';
		}

		return $rules;
	}

	/**
	 * Extract one CSS declaration value from a rule body.
	 *
	 * @param string $body     CSS declaration body.
	 * @param string $property Property name.
	 * @return string|null Declaration value.
	 */
	private static function css_declaration_value( string $body, string $property ): ?string {
		if ( ! preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+)\s*(?:;|$)/i', $body, $match ) ) {
			return null;
		}

		return trim( $match[1] );
	}

	/**
	 * Add one WordPress admin-bar height to a source top value.
	 *
	 * @param string $top    Source top declaration value.
	 * @param string $offset Admin-bar height.
	 * @return string|null Offset top value, or null when unsafe to rewrite.
	 */
	private static function admin_bar_offset_top_value( string $top, string $offset ): ?string {
		$top       = trim( $top );
		$important = '';
		if ( preg_match( '/\s*!important\s*$/i', $top ) ) {
			$important = ' !important';
			$top       = trim( preg_replace( '/\s*!important\s*$/i', '', $top ) ?? $top );
		}

		if ( '' === $top || preg_match( '/[;{}]/', $top ) || preg_match( '/^(?:auto|inherit|initial|revert|unset)$/i', $top ) || str_starts_with( $top, '-' ) ) {
			return null;
		}

		if ( preg_match( '/^0(?:[a-z%]+)?$/i', $top ) ) {
			return $offset . $important;
		}

		return 'calc(' . $top . ' + ' . $offset . ')' . $important;
	}

	/**
	 * Determine whether a selector plausibly targets imported top chrome.
	 *
	 * @param string $selector CSS selector.
	 * @return bool Whether the selector is narrow enough for admin-bar offsets.
	 */
	private static function selector_is_plausible_top_chrome( string $selector ): bool {
		$selector = trim( strtolower( $selector ) );
		if ( '' === $selector || str_starts_with( $selector, '@' ) || preg_match( '/(?:footer|bottom|modal|dialog|popup|overlay|sidebar|drawer)/', $selector ) ) {
			return false;
		}

		return (bool) preg_match( '/(?:header|masthead|nav|navbar|navigation|topbar|app-bar|toolbar|fixed-top|sticky-top)/', $selector );
	}

	/**
	 * Build editor-only wrapper normalization for imported absolute overlay blocks.
	 *
	 * The Site Editor inserts block-list wrappers between imported section/group blocks
	 * and their children. When a source child is absolutely positioned, that extra
	 * wrapper can become the visible stack item instead of the imported child.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional editor-only CSS rules.
	 */
	private static function editor_absolute_overlay_css( string $css ): string {
		$classes = self::absolute_position_classes_from_css( $css );
		if ( empty( $classes ) ) {
			return '';
		}

		$selectors = array();
		foreach ( $classes as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				$selectors[] = '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .' . $class . ')';
			}
		}

		if ( empty( $selectors ) ) {
			return '';
		}

		$selectors[] = '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .wp-block-group.static-site-importer-decorative-layer)';
		$selectors[] = '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block.wp-block-group.static-site-importer-decorative-layer';

		$group_selector    = '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer';
		$placeholder_rules = array(
			$group_selector . ' .block-editor-block-variation-picker',
			$group_selector . ' .components-placeholder',
			$group_selector . ' .block-list-appender',
			$group_selector . ' .block-editor-button-block-appender',
		);

		$css  = "\n/* Static Site Importer: let Site Editor wrappers preserve imported absolute overlay stacking. */\n" . implode( ', ', array_unique( $selectors ) ) . ' { display: contents; }' . "\n";
		$css .= "\n/* Static Site Importer: hide empty decorative layer group controls in the Site Editor. */\n" . implode( ', ', array_unique( $placeholder_rules ) ) . ' { display: none; }' . "\n";

		return $css;
	}

	/**
	 * Build editor-only visibility resets for JS-driven reveal animation starts.
	 *
	 * Source sites often initialize reveal targets with opacity:0 plus a transform,
	 * then use frontend JavaScript to animate them into place. The editor canvas does
	 * not run that frontend script, so scope the neutralization to editor styles.
	 *
	 * @param string $css Source CSS.
	 * @return string Additional editor-only CSS rules.
	 */
	private static function editor_reveal_animation_css( string $css ): string {
		$classes = self::reveal_animation_classes_from_css( $css );
		if ( empty( $classes ) ) {
			return '';
		}

		$selectors = array();
		foreach ( $classes as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				$selectors[] = '.editor-styles-wrapper .' . $class;
			}
		}

		if ( empty( $selectors ) ) {
			return '';
		}

		return "\n/* Static Site Importer: show JS-revealed animation content in editor canvases. */\n" . implode( ', ', array_unique( $selectors ) ) . ' { opacity: 1 !important; transform: none !important; }' . "\n";
	}

	/**
	 * Collect terminal selector classes from rules declaring hidden transform reveals.
	 *
	 * @param string $css Source CSS.
	 * @return array<int, string> Class names.
	 */
	private static function reveal_animation_classes_from_css( string $css ): array {
		$css       = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		$lower_css = strtolower( $css );
		if ( '' === trim( $css ) || ! str_contains( $lower_css, 'opacity' ) || ! str_contains( $lower_css, 'transform' ) || ! str_contains( $css, '.' ) ) {
			return array();
		}

		$classes = self::reveal_animation_classes_from_css_scope( $css );
		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Collect hidden transform reveal classes inside one CSS block list.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> Class names.
	 */
	private static function reveal_animation_classes_from_css_scope( string $css ): array {
		$classes = array();
		$length  = strlen( $css );
		$offset  = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$classes = array_merge( $classes, self::reveal_animation_classes_from_css_scope( $body ) );
				continue;
			}

			$opacity   = self::css_declaration_value( $body, 'opacity' );
			$transform = self::css_declaration_value( $body, 'transform' );
			if ( ! self::css_opacity_is_zero( $opacity ) || null === $transform || preg_match( '/^none\s*(?:!important\s*)?$/i', $transform ) ) {
				continue;
			}

			foreach ( explode( ',', $prelude ) as $selector ) {
				$classes = array_merge( $classes, self::selector_terminal_classes( trim( $selector ) ) );
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Determine whether an opacity declaration hides the element.
	 *
	 * @param string|null $opacity Opacity declaration value.
	 * @return bool Whether the value is zero.
	 */
	private static function css_opacity_is_zero( ?string $opacity ): bool {
		if ( null === $opacity ) {
			return false;
		}

		$opacity = trim( preg_replace( '/\s*!important\s*$/i', '', $opacity ) ?? $opacity );
		return (bool) preg_match( '/^0(?:\.0+)?%?$/', $opacity );
	}

	/**
	 * Collect CSS classes that identify empty decorative layers.
	 *
	 * @param string $css Source CSS.
	 * @return array<int, string> Class names.
	 */
	private static function decorative_empty_group_classes_from_css( string $css ): array {
		$classes = array_merge(
			self::absolute_position_classes_from_css( $css ),
			self::sized_decorative_classes_from_css( $css )
		);
		$classes = array_values( array_unique( $classes ) );
		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Collect terminal selector classes from rules that size decorative empty layers.
	 *
	 * @param string $css Source CSS.
	 * @return array<int, string> Class names.
	 */
	private static function sized_decorative_classes_from_css( string $css ): array {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( $css, '.' ) ) {
			return array();
		}

		return self::sized_decorative_classes_from_css_scope( $css );
	}

	/**
	 * Collect sized decorative classes inside one CSS block list.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> Class names.
	 */
	private static function sized_decorative_classes_from_css_scope( string $css ): array {
		$classes = array();
		$length  = strlen( $css );
		$offset  = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$classes = array_merge( $classes, self::sized_decorative_classes_from_css_scope( $body ) );
				continue;
			}

			if ( ! preg_match( '/(?:^|;)\s*(?:min-)?height\s*:/i', $body ) && ! preg_match( '/(?:^|;)\s*aspect-ratio\s*:/i', $body ) ) {
				continue;
			}

			foreach ( explode( ',', $prelude ) as $selector ) {
				$classes = array_merge( $classes, self::selector_terminal_classes( trim( $selector ) ) );
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Collect terminal selector classes from rules declaring position:absolute.
	 *
	 * @param string $css Source CSS.
	 * @return array<int, string> Class names.
	 */
	private static function absolute_position_classes_from_css( string $css ): array {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( $css, 'position' ) || ! str_contains( $css, '.' ) ) {
			return array();
		}

		$classes = self::absolute_position_classes_from_css_scope( $css );
		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Collect absolute-position classes inside one CSS block list.
	 *
	 * @param string $css CSS to inspect.
	 * @return array<int, string> Class names.
	 */
	private static function absolute_position_classes_from_css_scope( string $css ): array {
		$classes = array();
		$length  = strlen( $css );
		$offset  = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$classes = array_merge( $classes, self::absolute_position_classes_from_css_scope( $body ) );
				continue;
			}

			if ( ! preg_match( '/(?:^|;)\s*position\s*:\s*absolute\s*(?:;|$)/i', $body ) ) {
				continue;
			}

			foreach ( explode( ',', $prelude ) as $selector ) {
				$classes = array_merge( $classes, self::selector_terminal_classes( trim( $selector ) ) );
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Extract classes from the final compound selector that identifies the styled element.
	 *
	 * @param string $selector CSS selector.
	 * @return array<int, string> Class names.
	 */
	private static function selector_terminal_classes( string $selector ): array {
		if ( '' === $selector || ! preg_match( '/([^\s>+~]+)(?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*\s*$/', $selector, $selector_match ) ) {
			return array();
		}

		if ( ! preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)/', $selector_match[1], $class_matches ) ) {
			return array();
		}

		return array_values( array_unique( $class_matches[1] ) );
	}

	/**
	 * Keep copied source button selectors from restyling generated core/button wrappers.
	 *
	 * @param string             $css            Source CSS.
	 * @param array<int, string> $button_classes Classes observed on generated core/button wrappers.
	 * @return string Scoped source CSS.
	 */
	private static function scope_source_button_css( string $css, array $button_classes ): string {
		$button_classes = array_fill_keys( array_filter( array_map( 'strval', $button_classes ) ), true );
		if ( '' === trim( $css ) || ! str_contains( $css, '.' ) || empty( $button_classes ) ) {
			return $css;
		}

		return self::scope_source_button_css_scope( $css, $button_classes );
	}

	/**
	 * Scope selectors inside one CSS block list, preserving nested media/supports scopes.
	 *
	 * @param string              $css            CSS to rewrite.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string Rewritten CSS.
	 */
	private static function scope_source_button_css_scope( string $css, array $button_classes ): string {
		$rewritten = '';
		$length    = strlen( $css );
		$offset    = 0;

		while ( $offset < $length && preg_match( '/\G(\s*)([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$leading    = $match[1];
			$prelude    = trim( $match[2] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = substr( $css, $body_start, $body_end - $body_start );
			$offset = $body_end + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$body = self::scope_source_button_css_scope( $body, $button_classes );
			} else {
				$selectors = array();
				foreach ( explode( ',', $prelude ) as $selector ) {
					$selectors[] = self::source_button_selector_without_wrapper_match( trim( $selector ), $button_classes ) ?? trim( $selector );
				}

				$prelude = implode( ', ', $selectors );
			}

			$rewritten .= $leading . $prelude . ' {' . $body . '}';
		}

		return $rewritten . substr( $css, $offset );
	}

	/**
	 * Build CSS bridge rules for source anchor classes moved onto core/button wrappers.
	 *
	 * @param string             $css            Source CSS.
	 * @param array<int, string> $button_classes Classes observed on generated core/button wrappers.
	 * @return string Additional CSS rules.
	 */
	private static function button_style_bridge_css( string $css, array $button_classes ): string {
		$css            = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		$button_classes = array_fill_keys( array_filter( array_map( 'strval', $button_classes ) ), true );
		if ( '' === trim( $css ) || ! str_contains( $css, '.' ) || empty( $button_classes ) ) {
			return '';
		}

		$rules = array_merge(
			self::button_style_bridge_reset_rules( $button_classes ),
			self::button_style_bridge_rules_from_css( $css, $button_classes )
		);

		if ( ! $rules ) {
			return '';
		}

		return "\n/* Static Site Importer: preserve source anchor styles on core/button links. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build reset rules that prevent WordPress button defaults from leaking into source-converted buttons.
	 *
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return array<int, string>
	 */
	private static function button_style_bridge_reset_rules( array $button_classes ): array {
		$selectors = array();
		foreach ( array_keys( $button_classes ) as $class ) {
			if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
				$selectors[] = '.wp-block-button.' . $class . ' > .wp-block-button__link:where(.wp-element-button)';
			}
		}

		if ( empty( $selectors ) ) {
			return array();
		}

		return array(
			implode( ', ', $selectors ) . ' { background: transparent; border: 0; border-radius: 0; box-shadow: none; color: inherit; display: inline; font: inherit; height: auto; line-height: inherit; max-width: none; min-width: 0; padding: 0; text-decoration: inherit; width: auto; }',
		);
	}

	/**
	 * Build bridge rules from one CSS scope, preserving nested media/supports scopes.
	 *
	 * @param string              $css            CSS to inspect.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return array<int, string>
	 */
	private static function button_style_bridge_rules_from_css( string $css, array $button_classes ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;

		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$prelude    = trim( $match[1] );
			$body_start = $offset + strlen( $match[0] );
			$body_end   = self::find_css_block_end( $css, $body_start );
			if ( null === $body_end ) {
				break;
			}

			$body   = trim( substr( $css, $body_start, $body_end - $body_start ) );
			$offset = $body_end + 1;

			if ( '' === $prelude || '' === $body ) {
				continue;
			}

			if ( str_starts_with( $prelude, '@' ) ) {
				$nested_rules = self::button_style_bridge_rules_from_css( $body, $button_classes );
				if ( $nested_rules ) {
					$rules[] = $prelude . " {\n" . implode( "\n", $nested_rules ) . "\n}";
				}
				continue;
			}

			$link_selectors    = array();
			$wrapper_selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$bridge_selector = self::button_style_bridge_selector( trim( $selector ), $button_classes );
				if ( null !== $bridge_selector ) {
					$link_selectors[] = $bridge_selector;
				}

				$wrapper_selector = self::button_wrapper_layout_bridge_selector( trim( $selector ), $button_classes );
				if ( null !== $wrapper_selector ) {
					$wrapper_selectors[] = $wrapper_selector;
				}
			}

			if ( $link_selectors ) {
				$rules[] = implode( ', ', array_unique( $link_selectors ) ) . ' { ' . $body . ' }';
			}

			$layout_body = self::button_wrapper_layout_declarations( $body );
			if ( $wrapper_selectors && '' !== $layout_body ) {
				$rules[] = implode( ', ', array_unique( $wrapper_selectors ) ) . ' { ' . $layout_body . ' }';
			}
		}

		return $rules;
	}

	/**
	 * Find the matching closing brace for a CSS block body.
	 *
	 * @param string $css        CSS text.
	 * @param int    $body_start Offset immediately after the opening brace.
	 * @return int|null Offset of the matching closing brace.
	 */
	private static function find_css_block_end( string $css, int $body_start ): ?int {
		$depth  = 1;
		$length = strlen( $css );
		for ( $index = $body_start; $index < $length; $index++ ) {
			if ( '{' === $css[ $index ] ) {
				++$depth;
			} elseif ( '}' === $css[ $index ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index;
				}
			}
		}

		return null;
	}

	/**
	 * Rewrite one source selector to target a generated core/button inner link.
	 *
	 * @param string              $selector       Source selector.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string|null Bridge selector, or null when selector does not target a known button class.
	 */
	private static function button_style_bridge_selector( string $selector, array $button_classes ): ?string {
		if ( '' === $selector || ! preg_match( '/^(.*?)([^\s>+~]+)$/', $selector, $selector_match ) ) {
			return null;
		}

		$prefix = $selector_match[1];
		$target = $selector_match[2];
		if ( ! preg_match( '/^(?:(a|button))?((?:\.[A-Za-z_-][A-Za-z0-9_-]*)+)((?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*)$/i', $target, $target_match ) ) {
			return null;
		}

		$classes = array();
		foreach ( explode( '.', ltrim( $target_match[2], '.' ) ) as $class ) {
			if ( isset( $button_classes[ $class ] ) ) {
				$classes[] = $class;
			}
		}

		if ( empty( $classes ) ) {
			return null;
		}

		return $prefix . '.wp-block-button.' . implode( '.', $classes ) . ' > .wp-block-button__link' . $target_match[3];
	}

	/**
	 * Rewrite one source selector to target a generated core/button wrapper for layout declarations.
	 *
	 * @param string              $selector       Source selector.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string|null Bridge selector, or null when selector does not target a known button class.
	 */
	private static function button_wrapper_layout_bridge_selector( string $selector, array $button_classes ): ?string {
		if ( '' === $selector || ! preg_match( '/^(.*?)([^\s>+~]+)$/', $selector, $selector_match ) ) {
			return null;
		}

		$prefix = $selector_match[1];
		$target = $selector_match[2];
		if ( ! preg_match( '/^(?:(a|button))?((?:\.[A-Za-z_-][A-Za-z0-9_-]*)+)(?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*$/i', $target, $target_match ) ) {
			return null;
		}

		$classes = array();
		foreach ( explode( '.', ltrim( $target_match[2], '.' ) ) as $class ) {
			if ( isset( $button_classes[ $class ] ) ) {
				$classes[] = $class;
			}
		}

		if ( empty( $classes ) ) {
			return null;
		}

		return $prefix . '.wp-block-button.' . implode( '.', $classes );
	}

	/**
	 * Keep layout-affecting declarations on the core/button wrapper.
	 *
	 * @param string $body CSS declaration body.
	 * @return string Filtered declaration body.
	 */
	private static function button_wrapper_layout_declarations( string $body ): string {
		$declarations = array();
		foreach ( explode( ';', $body ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration || ! str_contains( $declaration, ':' ) ) {
				continue;
			}

			list( $property ) = explode( ':', $declaration, 2 );
			$property         = strtolower( trim( $property ) );
			if ( preg_match( '/^(?:width|min-width|max-width|flex(?:-.+)?|margin(?:-.+)?|align-self|justify-self|place-self|order)$/', $property ) ) {
				$declarations[] = $declaration;
			}
		}

		return implode( ';', $declarations );
	}

	/**
	 * Exclude generated core/button wrappers from a copied source selector.
	 *
	 * @param string              $selector       Source selector.
	 * @param array<string, true> $button_classes Classes observed on generated core/button wrappers.
	 * @return string|null Rewritten selector, or null when the selector cannot match a wrapper.
	 */
	private static function source_button_selector_without_wrapper_match( string $selector, array $button_classes ): ?string {
		if ( '' === $selector || str_contains( $selector, '.wp-block-button' ) || ! preg_match( '/^(.*?)([^\s>+~]+)$/', $selector, $selector_match ) ) {
			return null;
		}

		$target = $selector_match[2];
		if ( ! preg_match( '/^([A-Za-z][A-Za-z0-9_-]*)?((?:\.[A-Za-z_-][A-Za-z0-9_-]*)+)((?::[A-Za-z_-][A-Za-z0-9_-]*(?:\([^)]*\))?)*)$/i', $target, $target_match ) ) {
			return null;
		}

		$tag_name = strtolower( $target_match[1] );
		if ( in_array( $tag_name, array( 'a', 'button' ), true ) ) {
			return null;
		}

		foreach ( explode( '.', ltrim( $target_match[2], '.' ) ) as $class ) {
			if ( isset( $button_classes[ $class ] ) ) {
				return $selector_match[1] . $target_match[1] . $target_match[2] . ':not(.wp-block-button)' . $target_match[3];
			}
		}

		return null;
	}

	/**
	 * Build functions.php.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function functions_php( string $theme_slug ): string {
		$style_handle  = sanitize_key( $theme_slug ) . '-style';
		$editor_handle = sanitize_key( $theme_slug ) . '-editor-style';
		$script_handle = sanitize_key( $theme_slug ) . '-site';

		return "<?php\n" .
			"/**\n" .
			" * Generated theme bootstrap.\n" .
			" */\n\n" .
			"add_action( 'after_setup_theme', static function (): void {\n" .
			"\tadd_theme_support( 'editor-styles' );\n" .
			"\tadd_editor_style( 'assets/css/editor-style.css' );\n" .
			"} );\n\n" .
			"add_action( 'wp_enqueue_scripts', static function (): void {\n" .
			"\twp_enqueue_style( '" . $style_handle . "', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );\n" .
			"\tif ( file_exists( get_template_directory() . '/assets/site.js' ) ) {\n" .
			"\t\twp_enqueue_script( '" . $script_handle . "', get_template_directory_uri() . '/assets/site.js', array(), wp_get_theme()->get( 'Version' ), true );\n" .
			"\t}\n" .
			"} );\n\n" .
			"add_action( 'enqueue_block_editor_assets', static function (): void {\n" .
			"\twp_enqueue_style( '" . $editor_handle . "', get_template_directory_uri() . '/assets/css/editor-style.css', array(), wp_get_theme()->get( 'Version' ) );\n" .
			"} );\n";
	}

	/**
	 * Build theme.json.
	 *
	 * @param string $theme_name Theme name.
	 * @param string $css        Source CSS.
	 * @return string
	 */
	private static function theme_json( string $theme_name, string $css ): string {
		$data = array(
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'title'    => $theme_name,
			'settings' => array(
				'layout' => array(
					'contentSize' => '760px',
					'wideSize'    => '1200px',
				),
			),
		);

		$design_tokens = self::design_tokens_from_css( $css );
		if ( ! empty( $design_tokens['palette'] ) ) {
			$data['settings']['color']['palette'] = $design_tokens['palette'];
		}

		$data['styles']['spacing']['blockGap'] = '0';

		if ( ! empty( $design_tokens['styles'] ) ) {
			$data['styles']['color'] = $design_tokens['styles'];
		}

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Extract conservative design tokens from obvious :root custom properties.
	 *
	 * @param string $css Source CSS.
	 * @return array{palette:array<int,array{slug:string,name:string,color:string}>,styles:array<string,string>}
	 */
	private static function design_tokens_from_css( string $css ): array {
		$palette = array();
		$styles  = array();
		$seen    = array();

		if ( '' === trim( $css ) || ! preg_match_all( '/:root\s*\{([^}]*)\}/i', $css, $root_matches ) ) {
			return array(
				'palette' => $palette,
				'styles'  => $styles,
			);
		}

		foreach ( $root_matches[1] as $root_body ) {
			$root_body = (string) preg_replace( '/\/\*.*?\*\//s', '', $root_body );
			if ( ! preg_match_all( '/--([A-Za-z0-9_-]+)\s*:\s*([^;{}]+);/', $root_body, $property_matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $property_matches as $property_match ) {
				$token_name = strtolower( $property_match[1] );
				$color      = trim( $property_match[2] );
				$slug       = sanitize_title( $token_name );

				if ( '' === $slug || isset( $seen[ $slug ] ) || ! self::is_safe_color_value( $color ) ) {
					continue;
				}

				$seen[ $slug ] = true;
				$palette[]     = array(
					'slug'  => $slug,
					'name'  => ucwords( str_replace( array( '-', '_' ), ' ', $token_name ) ),
					'color' => $color,
				);

				if ( ! isset( $styles['background'] ) && in_array( $slug, array( 'bg', 'background' ), true ) ) {
					$styles['background'] = 'var(--wp--preset--color--' . $slug . ')';
				}

				if ( ! isset( $styles['text'] ) && in_array( $slug, array( 'fg', 'foreground', 'text' ), true ) ) {
					$styles['text'] = 'var(--wp--preset--color--' . $slug . ')';
				}
			}
		}

		return array(
			'palette' => $palette,
			'styles'  => $styles,
		);
	}

	/**
	 * Check whether a CSS value is safe to expose as a theme palette color.
	 *
	 * @param string $value CSS value.
	 * @return bool
	 */
	private static function is_safe_color_value( string $value ): bool {
		$value = trim( $value );

		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return true;
		}

		return (bool) preg_match( '/^(?:rgb|rgba|hsl|hsla)\(\s*[-+0-9.%\s,\/]+\s*\)$/i', $value );
	}

	/**
	 * Build a template that renders imported page content.
	 *
	 * @param string $background_blocks Background decoration blocks.
	 * @param bool   $has_footer_part   Whether a shared footer template part was generated.
	 * @return string
	 */
	private static function content_template( string $background_blocks, bool $has_footer_part ): string {
		$footer_part = $has_footer_part ? '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' : '';

		return trim(
			'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n\n" .
			$background_blocks . "\n\n" .
			'<!-- wp:post-content {"tagName":"main"} /-->' . "\n\n" .
			$footer_part
		) . "\n";
	}

	/**
	 * Build a theme pattern file for an imported page body.
	 *
	 * @param string $title        Pattern title.
	 * @param string $pattern_slug Pattern slug.
	 * @param string $content      Block markup.
	 * @return string
	 */
	private static function pattern_file( string $title, string $pattern_slug, string $content ): string {
		return "<?php\n" .
			"/**\n" .
			' * Title: ' . $title . "\n" .
			' * Slug: ' . $pattern_slug . "\n" .
			" * Categories: static-site-importer\n" .
			" */\n" .
			"?>\n" .
			trim( $content ) . "\n";
	}
}
