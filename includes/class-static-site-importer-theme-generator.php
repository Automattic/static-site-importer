<?php
/**
 * Block theme generator.
 *
 * @package StaticSiteImporter
 */

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
	 * Validated commerce context for the active import.
	 *
	 * Static Site Importer does not validate product manifests here; callers that
	 * own validation can supply the normalized context through import args.
	 *
	 * @var array<string, mixed>
	 */
	private static array $active_commerce_context = array();

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
	 * Source site directory for import-scoped local asset reads.
	 *
	 * @var string
	 */
	private static string $active_source_dir = '';

	/**
	 * Caller-supplied asset map keyed by source-relative path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $active_asset_map = array();

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
	 * Import a static-site HTML entry file as a block theme.
	 *
	 * @param string $html_path  HTML entry file path.
	 * @param array  $args       Import args.
	 * @return array{theme_slug:string,theme_name:string,theme_dir:string,report_path:string,external_report_path:string,source_dir:string,source_deleted:bool,source_cleanup_error:string,pages:array<string,int>,quality:array<string,mixed>,source_documents:array<string,mixed>}|WP_Error
	 */
	public static function import_theme( string $html_path, array $args = array() ) {
		if ( ! function_exists( 'bfb_convert' ) ) {
			return new WP_Error( 'static_site_importer_missing_bfb', 'Block Format Bridge is required to import a static site.' );
		}

		$document = Static_Site_Importer_Document::from_file( $html_path );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$theme_name = isset( $args['name'] ) && '' !== trim( (string) $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : $document->title();
		$theme_slug = isset( $args['slug'] ) && '' !== trim( (string) $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : sanitize_title( $theme_name );
		if ( '' === $theme_slug ) {
			$theme_slug = 'imported-static-site';
		}

		$theme_root = get_theme_root();
		$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;

		if ( file_exists( $theme_dir ) && empty( $args['overwrite'] ) ) {
			return new WP_Error( 'static_site_importer_theme_exists', sprintf( 'Theme already exists: %s', $theme_slug ) );
		}

		$result = self::ensure_dirs( $theme_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$site_dir = dirname( $html_path );
		$pages    = self::collect_pages( $site_dir );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		if ( empty( $pages ) ) {
			$source_page = Static_Site_Importer_Source_Page::from_html_file( $site_dir, $html_path );
			if ( is_wp_error( $source_page ) ) {
				return $source_page;
			}

			$pages = array( $source_page->source_key() => $source_page );
		}

		$page_ids = self::create_page_shells( $pages );
		if ( is_wp_error( $page_ids ) ) {
			return $page_ids;
		}

		$permalinks              = self::page_permalinks( $page_ids );
		$route_map               = self::source_route_map( $pages, $permalinks );
		$fragments               = $document->fragments();
		self::$conversion_report = self::new_conversion_report( $html_path, isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array() );
		self::record_source_region_selection( $document, $html_path );
		self::record_source_documents_summary( $site_dir, $pages, $permalinks );
		self::record_products_manifest( $site_dir );
		$inferred_commerce_context     = self::should_infer_commerce_context( $args ) ? self::infer_commerce_context_from_pages( $pages ) : array();
		self::$active_commerce_context = self::commerce_context_from_args( $args, $inferred_commerce_context );
		self::suppress_manifest_diagnostic_when_html_supplies_products();
		self::record_commerce_context_summary();

		self::$active_theme_dir         = $theme_dir;
		self::$active_theme_uri         = trailingslashit( get_theme_root_uri( $theme_slug ) ) . $theme_slug;
		self::$active_source_dir        = $site_dir;
		self::$active_asset_map         = self::normalize_asset_map( isset( $args['asset_map'] ) && is_array( $args['asset_map'] ) ? $args['asset_map'] : array() );
		self::$conversion_report['asset_map']['supplied']    = ! empty( self::$active_asset_map );
		self::$conversion_report['asset_map']['entry_count'] = count( self::$active_asset_map );
		self::$materialized_svg_assets  = array();
		self::$svg_sprite_symbols       = array();
		self::$materialized_svg_sprites = array();
		self::$button_wrapper_classes   = array();

		$site_css                             = self::site_css( $site_dir, $document );
		self::$decorative_empty_group_classes = array_fill_keys(
			self::decorative_empty_group_classes_from_css( $site_css ),
			true
		);
		self::pre_register_svg_symbol_sprites( $fragments, $pages );

		$entry_source        = basename( $html_path );
		$background_source   = 'background:' . $entry_source;
		$header_source       = 'header:' . $entry_source;
		$footer_source       = 'footer:' . $entry_source;
		$background_fragment = self::rewrite_asset_map_image_references( self::rewrite_internal_links( $fragments['background'], $route_map, $entry_source, $background_source ), $entry_source, $background_source );
		$header_fragment     = self::rewrite_asset_map_image_references( self::rewrite_internal_links( $fragments['header'], $route_map, $entry_source, $header_source ), $entry_source, $header_source );
		$footer_fragment     = self::rewrite_asset_map_image_references( self::rewrite_internal_links( $fragments['footer'], $route_map, $entry_source, $footer_source ), $entry_source, $footer_source );
		$background_blocks   = self::convert_fragment( $background_fragment, 'background:index.html' );
		$header_blocks       = self::convert_header_fragment( self::strip_active_classes( $header_fragment ), $theme_slug );
		$has_footer_part     = '' !== trim( $fragments['footer'] );
		$footer_blocks       = $has_footer_part ? self::convert_footer_fragment( $footer_fragment, $theme_slug ) : '';

		$page_artifacts = self::page_artifacts( $pages, $route_map, $theme_slug );

		$result = self::write_page_contents( $pages, $page_ids, $page_artifacts['contents'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$writes = array(
			$theme_dir . '/style.css'                   => self::style_css( $theme_name, $site_css, array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/assets/css/editor-style.css' => self::editor_style_css( $site_css, array_keys( self::$button_wrapper_classes ) ),
			$theme_dir . '/functions.php'               => self::functions_php( $theme_slug ),
			$theme_dir . '/theme.json'                  => self::theme_json( $theme_name, $site_css ),
			$theme_dir . '/parts/header.html'           => $header_blocks,
			$theme_dir . '/templates/front-page.html'   => self::content_template( $background_blocks, $has_footer_part ),
			$theme_dir . '/templates/page.html'         => self::content_template( $background_blocks, $has_footer_part ),
			$theme_dir . '/templates/index.html'        => self::content_template( $background_blocks, $has_footer_part ),
		);
		if ( $has_footer_part ) {
			$writes[ $theme_dir . '/parts/footer.html' ] = $footer_blocks;
		}

		foreach ( $page_artifacts['patterns'] as $filename => $pattern_slug ) {
			$page = $pages[ $filename ] ?? null;
			$slug = $page instanceof Static_Site_Importer_Source_Page ? self::page_slug( $filename, $page ) : self::page_slug( $filename );
			if ( '' === $pattern_slug || '' === $slug ) {
				continue;
			}

			$writes[ $theme_dir . '/templates/page-' . $slug . '.html' ] = self::content_template( $background_blocks, $has_footer_part );
			$writes[ $theme_dir . '/patterns/page-' . $slug . '.php' ]   = $page_artifacts['files'][ $filename ] ?? '';
		}

		$inline_js = $document->inline_js();
		if ( '' !== $inline_js ) {
			$writes[ $theme_dir . '/assets/site.js' ] = $inline_js;
		}

		self::analyze_generated_theme_block_documents( $writes, $theme_dir );
		self::record_visual_fidelity_targets( $pages, $page_ids, $permalinks, $writes, $theme_dir );
		self::record_semantic_fidelity_targets( $pages, $page_ids, $permalinks, $writes, $theme_dir );
		self::record_product_seeding_report( $args );
		self::record_commerce_dependency_check( $args );
		$quality     = self::finalize_quality_report( $args );
		$report_json = wp_json_encode( self::$conversion_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $report_json ) {
			return new WP_Error( 'static_site_importer_report_encode_failed', 'Failed to encode import report JSON.' );
		}

		if ( ! $has_footer_part && file_exists( $theme_dir . '/parts/footer.html' ) && ! wp_delete_file( $theme_dir . '/parts/footer.html' ) ) {
			return new WP_Error( 'static_site_importer_stale_footer_delete_failed', 'Failed to remove stale footer template part.' );
		}

		$writes[ $theme_dir . '/import-report.json' ] = $report_json . "\n";

		foreach ( $writes as $path => $content ) {
			$result = self::write_file( $path, $content );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$external_report_path = '';
		if ( isset( $args['report'] ) && '' !== trim( (string) $args['report'] ) ) {
			$external_report_path = (string) $args['report'];
			$result               = self::write_external_report( $external_report_path, $report_json . "\n" );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $args['activate'] ) ) {
			switch_theme( $theme_slug );

			$front_page_id = self::front_page_id( $page_ids );
			if ( $front_page_id > 0 ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $front_page_id );
			}
		}

		$source_deleted       = false;
		$source_cleanup_error = '';
		if ( empty( $args['keep_source'] ) ) {
			if ( ! empty( $quality['pass'] ) ) {
				$cleanup_result = self::delete_source_dir( $site_dir, $html_path );
				if ( is_wp_error( $cleanup_result ) ) {
					$source_cleanup_error = $cleanup_result->get_error_message();
				} else {
					$source_deleted = true;
				}
			} else {
				$source_cleanup_error = 'import quality checks reported issues';
			}
		}

		return array(
			'theme_slug'            => $theme_slug,
			'theme_name'            => $theme_name,
			'theme_dir'             => $theme_dir,
			'report_path'           => $theme_dir . '/import-report.json',
			'external_report_path'  => $external_report_path,
			'import_report_summary' => self::import_report_summary( self::$conversion_report, $quality ),
			'source_dir'            => $site_dir,
			'source_deleted'        => $source_deleted,
			'source_cleanup_error'  => $source_cleanup_error,
			'pages'                 => $page_ids,
			'quality'               => $quality,
			'source_documents'      => self::$conversion_report['source_documents'],
		);
	}

	/**
	 * Export an imported or active block theme as static-site artifacts.
	 *
	 * @param array $args Export args.
	 * @return array{artifact_set:array<string,mixed>,files:array<int,array<string,mixed>>,report:array<string,mixed>}|WP_Error
	 */
	public static function export_theme( array $args = array() ) {
		if ( ! function_exists( 'bfb_convert' ) ) {
			return new WP_Error( 'static_site_importer_missing_bfb', 'Block Format Bridge is required to export a static site.' );
		}

		$theme_slug = isset( $args['theme_slug'] ) && '' !== trim( (string) $args['theme_slug'] ) ? sanitize_title( (string) $args['theme_slug'] ) : self::active_theme_slug();
		if ( '' === $theme_slug ) {
			return new WP_Error( 'static_site_importer_missing_theme_slug', 'A theme_slug input is required when no active theme can be detected.' );
		}

		$theme_dir = self::export_theme_dir( $theme_slug );
		if ( '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'static_site_importer_theme_not_found', sprintf( 'Theme directory not found for %s.', $theme_slug ) );
		}

		$entrypoint      = self::export_artifact_path( isset( $args['entrypoint'] ) ? (string) $args['entrypoint'] : 'static-site/index.html', 'static-site/index.html' );
		$include_pages   = $args['include_pages'] ?? true;
		$source_metadata = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$diagnostics     = array();
		$files           = array();

		$stylesheet = self::export_theme_stylesheet_file( $theme_dir );
		if ( null !== $stylesheet ) {
			$files[] = $stylesheet;
		}

		$pages = self::export_pages( $include_pages );
		if ( empty( $pages ) ) {
			$diagnostics[] = array(
				'level'   => 'warning',
				'code'    => 'static_site_importer_export_no_pages',
				'message' => 'No published pages were available to export; generated an entrypoint from theme templates only.',
			);
			$files[] = self::export_file_entry(
				$entrypoint,
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page' ), $theme_slug, null !== $stylesheet ),
				'document',
				'entrypoint'
			);
		} else {
			$front_page_id = self::export_front_page_id();
			$first         = true;
			foreach ( $pages as $page ) {
				$page_id   = isset( $page->ID ) ? (int) $page->ID : 0;
				$is_front  = $first || ( $front_page_id > 0 && $page_id === $front_page_id );
				$path      = $is_front ? $entrypoint : self::export_page_artifact_path( $page );
				$template  = $is_front ? 'front-page' : 'page';
				$page_html = bfb_convert( isset( $page->post_content ) ? (string) $page->post_content : '', 'blocks', 'html' );

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, self::export_theme_chrome_html( $theme_dir, $template ), self::export_page_title( $page, $theme_slug ), null !== $stylesheet ),
					'document',
					$is_front ? 'entrypoint' : 'page',
					array(
						'post_id'   => $page_id,
						'post_name' => isset( $page->post_name ) ? (string) $page->post_name : '',
					)
				);

				$first = false;
			}
		}

		$import_report = self::read_theme_import_report( $theme_dir );
		$report        = array(
			'status'          => 'completed',
			'theme_slug'      => $theme_slug,
			'theme_dir'       => $theme_dir,
			'entrypoint'      => $entrypoint,
			'file_count'      => count( $files ),
			'page_count'      => count( $pages ),
			'source_metadata' => $source_metadata,
			'diagnostics'     => $diagnostics,
		);
		if ( ! empty( $import_report ) ) {
			$report['import_report'] = $import_report;
		}

		return array(
			'artifact_set' => array(
				'schema'     => 'static-site-importer/static-site-artifact-set/v1',
				'theme_slug' => $theme_slug,
				'entrypoint' => $entrypoint,
				'files'      => $files,
				'report'     => $report,
			),
			'files'        => $files,
			'report'       => $report,
		);
	}

	/**
	 * Resolve the active theme slug.
	 *
	 * @return string
	 */
	private static function active_theme_slug(): string {
		if ( function_exists( 'get_stylesheet' ) ) {
			return sanitize_title( (string) get_stylesheet() );
		}

		return '';
	}

	/**
	 * Resolve a theme directory for export.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string
	 */
	private static function export_theme_dir( string $theme_slug ): string {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $theme_slug );
			if ( is_object( $theme ) && method_exists( $theme, 'exists' ) && $theme->exists() && method_exists( $theme, 'get_stylesheet_directory' ) ) {
				return (string) $theme->get_stylesheet_directory();
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			return trailingslashit( get_theme_root( $theme_slug ) ) . $theme_slug;
		}

		return '';
	}

	/**
	 * Get published pages selected by include_pages.
	 *
	 * @param mixed $include_pages Include pages argument.
	 * @return array<int,object>
	 */
	private static function export_pages( $include_pages ): array {
		if ( false === $include_pages || ! function_exists( 'get_posts' ) ) {
			$page = self::export_front_page();
			return null === $page ? array() : array( $page );
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $pages ) ) {
			return array();
		}

		if ( ! is_array( $include_pages ) || empty( $include_pages ) ) {
			return self::order_front_page_first( array_values( $pages ) );
		}

		$allowed = array_fill_keys( array_map( 'strval', $include_pages ), true );
		return self::order_front_page_first( array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $allowed ): bool {
					$page_id   = isset( $page->ID ) ? (string) $page->ID : '';
					$page_slug = isset( $page->post_name ) ? (string) $page->post_name : '';
					return isset( $allowed[ $page_id ] ) || isset( $allowed[ $page_slug ] );
				}
			)
		) );
	}

	/**
	 * Order exported pages so the configured front page becomes the entrypoint.
	 *
	 * @param array<int,object> $pages Pages.
	 * @return array<int,object>
	 */
	private static function order_front_page_first( array $pages ): array {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id <= 0 ) {
			return $pages;
		}

		usort(
			$pages,
			static function ( object $left, object $right ) use ( $front_page_id ): int {
				$left_is_front  = isset( $left->ID ) && (int) $left->ID === $front_page_id;
				$right_is_front = isset( $right->ID ) && (int) $right->ID === $front_page_id;
				if ( $left_is_front === $right_is_front ) {
					return 0;
				}

				return $left_is_front ? -1 : 1;
			}
		);

		return $pages;
	}

	/**
	 * Get the configured front page post.
	 *
	 * @return object|null
	 */
	private static function export_front_page(): ?object {
		$front_page_id = self::export_front_page_id();
		if ( $front_page_id > 0 && function_exists( 'get_post' ) ) {
			$page = get_post( $front_page_id );
			if ( is_object( $page ) ) {
				return $page;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		return is_array( $pages ) && isset( $pages[0] ) && is_object( $pages[0] ) ? $pages[0] : null;
	}

	/**
	 * Get the configured front page ID.
	 *
	 * @return int
	 */
	private static function export_front_page_id(): int {
		if ( ! function_exists( 'get_option' ) || 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		return (int) get_option( 'page_on_front' );
	}

	/**
	 * Convert template parts around exported page content.
	 *
	 * @param string $theme_dir Theme directory.
	 * @param string $template  Template slug.
	 * @return array{before:string,after:string}
	 */
	private static function export_theme_chrome_html( string $theme_dir, string $template ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html' );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html' );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html ) {
			$converted_template = bfb_convert( $template_html, 'blocks', 'html' );
			if ( '' !== trim( $converted_template ) && '' === trim( $before . $after ) ) {
				$before = $converted_template;
			}
		}

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * Convert a block markup file to HTML.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function convert_theme_block_file_to_html( string $path ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content ? '' : bfb_convert( $content, 'blocks', 'html' );
	}

	/**
	 * Read a file when available.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_file_if_readable( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
		$content = file_get_contents( $path );
		return false === $content ? '' : (string) $content;
	}

	/**
	 * Build a full static HTML document.
	 *
	 * @param string                    $page_html       Converted page body HTML.
	 * @param array{before:string,after:string} $chrome          Converted theme chrome.
	 * @param string                    $title           Document title.
	 * @param bool                      $include_styles  Whether to link exported CSS.
	 * @return string
	 */
	private static function export_html_document( string $page_html, array $chrome, string $title, bool $include_styles ): string {
		$head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		if ( $include_styles ) {
			$head .= '<link rel="stylesheet" href="style.css">';
		}

		return '<!doctype html>' . "\n"
			. '<html><head>' . $head . '<title>' . esc_html( $title ) . '</title></head><body>' . "\n"
			. trim( (string) ( $chrome['before'] ?? '' ) . "\n" . $page_html . "\n" . ( $chrome['after'] ?? '' ) ) . "\n"
			. '</body></html>' . "\n";
	}

	/**
	 * Build an artifact file entry.
	 *
	 * @param string              $path        Artifact path.
	 * @param string              $content     File content.
	 * @param string              $kind        File kind.
	 * @param string              $role        File role.
	 * @param array<string,mixed> $diagnostics Optional diagnostics/metadata.
	 * @return array<string,mixed>
	 */
	private static function export_file_entry( string $path, string $content, string $kind, string $role, array $diagnostics = array() ): array {
		$entry = array(
			'path'    => $path,
			'content' => $content,
			'kind'    => $kind,
			'role'    => $role,
		);
		if ( ! empty( $diagnostics ) ) {
			$entry['diagnostics'] = $diagnostics;
		}

		return $entry;
	}

	/**
	 * Export the theme stylesheet when present.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>|null
	 */
	private static function export_theme_stylesheet_file( string $theme_dir ): ?array {
		$content = self::read_file_if_readable( $theme_dir . '/style.css' );
		if ( '' === $content ) {
			return null;
		}

		return self::export_file_entry( 'static-site/style.css', $content, 'asset', 'stylesheet' );
	}

	/**
	 * Normalize an exported artifact path.
	 *
	 * @param string $path     Requested path.
	 * @param string $fallback Fallback path.
	 * @return string
	 */
	private static function export_artifact_path( string $path, string $fallback ): string {
		$path = self::normalize_route_path( $path );
		if ( '' === $path || str_ends_with( $path, '/' ) ) {
			return $fallback;
		}

		return $path;
	}

	/**
	 * Build a page artifact path.
	 *
	 * @param object $page Page object.
	 * @return string
	 */
	private static function export_page_artifact_path( object $page ): string {
		$slug = isset( $page->post_name ) && '' !== trim( (string) $page->post_name ) ? sanitize_title( (string) $page->post_name ) : 'page-' . ( isset( $page->ID ) ? (int) $page->ID : uniqid() );
		return self::export_artifact_path( 'static-site/' . $slug . '/index.html', 'static-site/page/index.html' );
	}

	/**
	 * Resolve a page title for export.
	 *
	 * @param object $page       Page object.
	 * @param string $theme_slug Fallback theme slug.
	 * @return string
	 */
	private static function export_page_title( object $page, string $theme_slug ): string {
		if ( isset( $page->post_title ) && '' !== trim( (string) $page->post_title ) ) {
			return (string) $page->post_title;
		}

		return $theme_slug;
	}

	/**
	 * Read the import report bundled with an SSI-generated theme.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>
	 */
	private static function read_theme_import_report( string $theme_dir ): array {
		$report = self::read_file_if_readable( $theme_dir . '/import-report.json' );
		if ( '' === $report ) {
			return array();
		}

		$decoded = json_decode( $report, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Collect importable source pages from a static site directory.
	 *
	 * @param string $site_dir Site directory.
	 * @return array<string, Static_Site_Importer_Source_Page>|WP_Error
	 */
	private static function collect_pages( string $site_dir ) {
		$pages = array();
		foreach ( self::collect_html_paths( $site_dir ) as $path ) {
			$page = Static_Site_Importer_Source_Page::from_html_file( $site_dir, $path );
			if ( is_wp_error( $page ) ) {
				continue;
			}

			$pages[ $page->source_key() ] = $page;
		}

		foreach ( self::collect_markdown_paths( $site_dir ) as $path ) {
			$page = Static_Site_Importer_Source_Page::from_markdown_file( $site_dir, $path );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$pages[ $page->source_key() ] = $page;
		}

		uksort(
			$pages,
			static function ( string $left, string $right ): int {
				$left_is_root_index  = self::is_root_index_source_filename( $left );
				$right_is_root_index = self::is_root_index_source_filename( $right );
				if ( $left_is_root_index && ! $right_is_root_index ) {
					return -1;
				}
				if ( ! $left_is_root_index && $right_is_root_index ) {
					return 1;
				}

				$left_is_index  = self::is_index_source_filename( $left );
				$right_is_index = self::is_index_source_filename( $right );
				if ( $left_is_index && ! $right_is_index ) {
					return -1;
				}
				if ( ! $left_is_index && $right_is_index ) {
					return 1;
				}

				return strnatcasecmp( $left, $right );
			}
		);

		return $pages;
	}

	/**
	 * Collect HTML source files anywhere under the static site root.
	 *
	 * @param string $site_dir Site directory.
	 * @return array<int, string>
	 */
	private static function collect_html_paths( string $site_dir ): array {
		$paths = array();
		if ( ! is_dir( $site_dir ) ) {
			return $paths;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $site_dir, FilesystemIterator::SKIP_DOTS ),
				static function ( SplFileInfo $file ): bool {
					if ( $file->isDir() ) {
						return self::is_importable_source_directory( $file->getFilename() );
					}

					return self::is_html_source_path( $file->getFilename() );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() ) {
				$paths[] = $file->getPathname();
			}
		}

		sort( $paths, SORT_NATURAL | SORT_FLAG_CASE );
		return $paths;
	}

	/**
	 * Collect Markdown source files anywhere under the static site root.
	 *
	 * @param string $site_dir Site directory.
	 * @return array<int, string>
	 */
	private static function collect_markdown_paths( string $site_dir ): array {
		$paths = array();
		if ( ! is_dir( $site_dir ) ) {
			return $paths;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $site_dir, FilesystemIterator::SKIP_DOTS ),
				static function ( SplFileInfo $file ): bool {
					if ( $file->isDir() ) {
						return self::is_importable_source_directory( $file->getFilename() );
					}

					return self::is_markdown_source_path( $file->getFilename() );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() ) {
				$paths[] = $file->getPathname();
			}
		}

		sort( $paths, SORT_NATURAL | SORT_FLAG_CASE );
		return $paths;
	}

	/**
	 * Check whether a path is a Markdown content source.
	 *
	 * @param string $path File path or basename.
	 * @return bool
	 */
	private static function is_markdown_source_path( string $path ): bool {
		return 1 === preg_match( '/\.mark?down$/i', $path ) || 1 === preg_match( '/\.md$/i', $path );
	}

	/**
	 * Check whether a path is an HTML content source.
	 *
	 * @param string $path File path or basename.
	 * @return bool
	 */
	private static function is_html_source_path( string $path ): bool {
		return 1 === preg_match( '/\.html?$/i', $path );
	}

	/**
	 * Check whether a source directory should be scanned for importable pages.
	 *
	 * @param string $name Directory basename.
	 * @return bool
	 */
	private static function is_importable_source_directory( string $name ): bool {
		return ! in_array( $name, array( '.git', 'node_modules', 'vendor' ), true ) && ! str_starts_with( $name, '.' );
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

			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			$postarr  = array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_type'    => 'page',
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
	 * Rewrite local image references through the caller-supplied asset map.
	 *
	 * @param string $html        HTML fragment.
	 * @param string $source_path Source-relative source path.
	 * @param string $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_asset_map_image_references( string $html, string $source_path, string $source ): string {
		if ( '' === trim( $html ) || empty( self::$active_asset_map ) || ! str_contains( strtolower( $html ), '<img' ) ) {
			return $html;
		}

		$doc = self::load_fragment_document( $html );
		$root = $doc->documentElement;
		if ( ! $root instanceof DOMElement ) {
			return $html;
		}

		$changed = false;
		foreach ( $root->getElementsByTagName( 'img' ) as $image ) {
			$src = trim( $image->getAttribute( 'src' ) );
			if ( '' === $src || ! self::is_local_url( $src ) ) {
				continue;
			}

			$lookup = self::resolve_asset_map_reference( $src, $source_path, $source );
			if ( null === $lookup ) {
				continue;
			}

			$entry = $lookup['entry'];
			if ( isset( $entry['url'] ) && '' !== trim( (string) $entry['url'] ) ) {
				$image->setAttribute( 'src', esc_url_raw( (string) $entry['url'] ) );
				$changed = true;
			}

			if ( isset( $entry['attachment_id'] ) && (int) $entry['attachment_id'] > 0 ) {
				$attachment_id = (int) $entry['attachment_id'];
				$image->setAttribute( 'data-id', (string) $attachment_id );
				$image->setAttribute( 'class', self::append_class_token( $image->getAttribute( 'class' ), 'wp-image-' . $attachment_id ) );
				$changed = true;
			}

			foreach ( array( 'width', 'height' ) as $dimension ) {
				if ( isset( $entry[ $dimension ] ) && (int) $entry[ $dimension ] > 0 && ! $image->hasAttribute( $dimension ) ) {
					$image->setAttribute( $dimension, (string) (int) $entry[ $dimension ] );
					$changed = true;
				}
			}

			if ( isset( $entry['alt'] ) && '' !== trim( (string) $entry['alt'] ) && '' === trim( $image->getAttribute( 'alt' ) ) ) {
				$image->setAttribute( 'alt', (string) $entry['alt'] );
				$changed = true;
			}
		}

		return $changed ? self::node_inner_html( $doc, $root ) : $html;
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
	 * Convert shared header chrome while preserving navigation as an editable entity.
	 *
	 * @param string $html       Header HTML fragment.
	 * @param string $theme_slug Imported theme slug.
	 * @return string
	 */
	private static function convert_header_fragment( string $html, string $theme_slug ): string {
		$html   = self::materialize_inline_svg_icons( $html, 'theme-part:header' );
		$doc    = self::load_fragment_document( $html );
		$header = self::sole_child_element( $doc );
		$root   = $doc->documentElement;
		if ( ! $header instanceof DOMElement && $root instanceof DOMElement ) {
			$children = self::direct_element_children( $root );
			if ( count( $children ) > 1 ) {
				return implode(
					'',
					array_map(
						static fn ( DOMElement $child ): string => self::theme_part_element_block( $doc, $child, $theme_slug, 'header' ),
						$children
					)
				);
			}
		}

		if ( $header instanceof DOMElement && 'nav' === strtolower( $header->tagName ) ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}
		if ( ! $header instanceof DOMElement || 'header' !== strtolower( $header->tagName ) ) {
			return self::convert_fragment( $html, 'theme-part:header' );
		}

		$header_children = self::direct_element_children( $header );
		if ( 1 !== count( $header_children ) || 'div' !== strtolower( $header_children[0]->tagName ) ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}

		$inner          = $header_children[0];
		$inner_children = self::direct_element_children( $inner );
		if ( 2 !== count( $inner_children ) || 'a' !== strtolower( $inner_children[0]->tagName ) || 'nav' !== strtolower( $inner_children[1]->tagName ) ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}

		$navigation_blocks = self::navigation_ref_block( $inner_children[1], $theme_slug, 'header' );
		if ( null === $navigation_blocks ) {
			return self::theme_part_element_block( $doc, $header, $theme_slug, 'header' );
		}

		$inner_blocks = self::theme_part_element_block( $doc, $inner_children[0], $theme_slug, 'header' ) . $navigation_blocks;
		return self::group_block( self::group_block( $inner_blocks, $inner->getAttribute( 'class' ), 'div', $inner->getAttribute( 'id' ) ), $header->getAttribute( 'class' ), 'header', $header->getAttribute( 'id' ) );
	}

	/**
	 * Convert shared footer chrome while preserving navigation as an editable entity.
	 *
	 * @param string $html       Footer HTML fragment.
	 * @param string $theme_slug Imported theme slug.
	 * @return string
	 */
	private static function convert_footer_fragment( string $html, string $theme_slug ): string {
		$html   = self::materialize_inline_svg_icons( $html, 'theme-part:footer' );
		$doc    = self::load_fragment_document( $html );
		$footer = self::sole_child_element( $doc );
		if ( ! $footer instanceof DOMElement || 'footer' !== strtolower( $footer->tagName ) ) {
			return self::convert_fragment( $html, 'theme-part:footer' );
		}

		$footer_children = self::direct_element_children( $footer );
		if ( 1 !== count( $footer_children ) || 'div' !== strtolower( $footer_children[0]->tagName ) ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$container          = $footer_children[0];
		$container_children = self::direct_element_children( $container );
		if ( 2 === count( $container_children ) && 'div' === strtolower( $container_children[0]->tagName ) && 'ul' === strtolower( $container_children[1]->tagName ) ) {
			$list_blocks = self::footer_navigation_or_list_block( $doc, $container_children[1], $theme_slug, 'footer' );
			if ( null === $list_blocks ) {
				return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
			}

			$container_blocks = self::theme_part_element_block( $doc, $container_children[0], $theme_slug, 'footer' ) . $list_blocks;
			return self::group_block( self::group_block( $container_blocks, $container->getAttribute( 'class' ) ), $footer->getAttribute( 'class' ), 'footer' );
		}

		if ( 1 !== count( $container_children ) || 'div' !== strtolower( $container_children[0]->tagName ) ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$row          = $container_children[0];
		$row_children = self::direct_element_children( $row );
		if ( 2 !== count( $row_children ) || 'div' !== strtolower( $row_children[0]->tagName ) || 'ul' !== strtolower( $row_children[1]->tagName ) ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$list_blocks = self::footer_navigation_or_list_block( $doc, $row_children[1], $theme_slug, 'footer' );
		if ( null === $list_blocks ) {
			return self::theme_part_element_block( $doc, $footer, $theme_slug, 'footer' );
		}

		$row_blocks       = self::theme_part_element_block( $doc, $row_children[0], $theme_slug, 'footer' ) . $list_blocks;
		$container_blocks = self::group_block( $row_blocks, $row->getAttribute( 'class' ), 'div', $row->getAttribute( 'id' ) );
		return self::group_block( self::group_block( $container_blocks, $container->getAttribute( 'class' ), 'div', $container->getAttribute( 'id' ) ), $footer->getAttribute( 'class' ), 'footer', $footer->getAttribute( 'id' ) );
	}

	/**
	 * Build editable-enough block markup for shared theme chrome without delegating the whole part to core/html.
	 *
	 * @param DOMDocument $doc        Source DOM document.
	 * @param DOMElement  $element    Source element.
	 * @param string      $theme_slug Imported theme slug.
	 * @param string      $location   Theme part location.
	 * @return string
	 */
	private static function theme_part_element_block( DOMDocument $doc, DOMElement $element, string $theme_slug, string $location ): string {
		$tag = strtolower( $element->tagName );
		if ( 'footer' === $location && in_array( $tag, array( 'ul', 'ol' ), true ) && self::can_convert_element_to_navigation( $element ) ) {
			$list = self::footer_link_list_block( $doc, $element );
			if ( null !== $list ) {
				return $list;
			}
		}

		if ( self::should_preserve_navigation_list_owner_classes( $element ) ) {
			$list = self::footer_link_list_block( $doc, $element );
			if ( null !== $list ) {
				return $list;
			}
		}

		if ( self::can_convert_element_to_navigation( $element ) ) {
			$navigation = self::navigation_ref_block( $element, $theme_slug, $location );
			if ( null !== $navigation ) {
				return $navigation;
			}
		}

		if ( 'a' === $tag ) {
			return self::link_element_block( $doc, $element );
		}

		if ( 'img' === $tag ) {
			return self::image_element_block( $doc, $element );
		}

		if ( self::should_preserve_theme_part_phrasing_element( $element ) ) {
			return self::paragraph_block( self::node_inner_html( $doc, $element ), $element->getAttribute( 'class' ) );
		}

		if ( self::is_link_cluster_container( $element ) ) {
			return self::group_block( self::theme_part_child_blocks( $doc, $element, $theme_slug, $location ), $element->getAttribute( 'class' ), 'div', $element->getAttribute( 'id' ) );
		}

		if ( self::element_has_only_phrasing_content( $element ) ) {
			return self::paragraph_block( self::node_inner_html( $doc, $element ), $element->getAttribute( 'class' ) );
		}

		$children = self::theme_part_child_blocks( $doc, $element, $theme_slug, $location );
		if ( '' === trim( $children ) ) {
			if ( self::is_empty_decorative_theme_part_element( $element ) ) {
				return self::group_block( '', self::append_class_token( $element->getAttribute( 'class' ), 'static-site-importer-decorative-layer' ), 'div', $element->getAttribute( 'id' ) );
			}

			$text = trim( $element->textContent );
			if ( '' !== $text ) {
				return self::paragraph_block( esc_html( $text ), $element->getAttribute( 'class' ) );
			}

			return self::html_block( self::node_html( $doc, $element ) );
		}

		$wrapper_tag = in_array( $tag, array( 'header', 'footer' ), true ) ? $tag : 'div';
		if ( 'nav' === $tag && ! str_contains( $children, '<!-- wp:navigation ' ) ) {
			$wrapper_tag = 'nav';
		}

		$class_name = $element->getAttribute( 'class' );
		if ( 'nav' === $tag ) {
			$class_name = self::append_class_token( $class_name, 'static-site-importer-source-nav' );
		}

		return self::group_block( $children, $class_name, $wrapper_tag, $element->getAttribute( 'id' ) );
	}

	/**
	 * Convert direct child nodes for a shared theme part.
	 *
	 * @param DOMDocument $doc        Source DOM document.
	 * @param DOMElement  $element    Source element.
	 * @param string      $theme_slug Imported theme slug.
	 * @param string      $location   Theme part location.
	 * @return string
	 */
	private static function theme_part_child_blocks( DOMDocument $doc, DOMElement $element, string $theme_slug, string $location ): string {
		$blocks = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) {
				$blocks[] = self::paragraph_block( esc_html( trim( $child->textContent ) ) );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( self::element_contains_svg_or_form( $child ) ) {
				$blocks[] = self::html_block( self::node_html( $doc, $child ) );
				continue;
			}

			$blocks[] = self::theme_part_element_block( $doc, $child, $theme_slug, $location );
		}

		return implode( '', array_filter( $blocks ) );
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
	 * Convert an anchor in shared chrome to a button block when it visually acts like a CTA.
	 *
	 * @param DOMDocument $doc     Source DOM document.
	 * @param DOMElement  $element Anchor element.
	 * @return string
	 */
	private static function link_element_block( DOMDocument $doc, DOMElement $element ): string {
		$href  = trim( $element->getAttribute( 'href' ) );
		$label = trim( $element->textContent );
		if ( '' === $href || '' === $label ) {
			return self::html_block( self::node_html( $doc, $element ) );
		}

		$class = trim( $element->getAttribute( 'class' ) );
		if ( preg_match( '/(^|[-_\s])(brand|logo)([-_\s]|$)/i', $class ) ) {
			return self::convert_fragment( self::node_html( $doc, $element ), 'theme-part:brand-anchor' );
		}

		if ( preg_match( '/(^|[-_\s])(btn|button|cta|pill)([-_\s]|$)/i', $class ) ) {
			$attrs = array( 'url' => esc_url_raw( $href ) );
			if ( '' !== $class ) {
				$attrs['className'] = $class;
				self::record_button_wrapper_classes( $class );
			}

			return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' --><div class="wp-block-button ' . esc_attr( $class ) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
		}

		return self::paragraph_block( '<a href="' . esc_url( $href ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $label ) . '</a>' );
	}

	/**
	 * Convert an image in shared chrome to a native image block.
	 *
	 * @param DOMDocument $doc        Source DOM document.
	 * @param DOMElement  $element    Image element.
	 * @param string      $class_name Optional class override.
	 * @param string      $href       Optional link href.
	 * @return string
	 */
	private static function image_element_block( DOMDocument $doc, DOMElement $element, string $class_name = '', string $href = '' ): string {
		$src = trim( $element->getAttribute( 'src' ) );
		if ( '' === $src ) {
			return self::html_block( self::node_html( $doc, $element ) );
		}

		$materialized_src = self::materialize_local_image_asset( $src, 'theme-part:image' );
		if ( null !== $materialized_src ) {
			$src = $materialized_src;
		}

		$class = trim( '' !== $class_name ? $class_name : $element->getAttribute( 'class' ) );
		$attrs = array(
			'url'      => esc_url_raw( $src ),
			'sizeSlug' => 'large',
		);
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}
		if ( (int) $element->getAttribute( 'data-id' ) > 0 ) {
			$attrs['id'] = (int) $element->getAttribute( 'data-id' );
			$class       = self::append_class_token( $class, 'wp-image-' . $attrs['id'] );
			if ( '' !== $class ) {
				$attrs['className'] = $class;
			}
		}
		if ( '' !== $href ) {
			$attrs['href']            = esc_url_raw( $href );
			$attrs['linkDestination'] = 'custom';
		}

		$figure_class = trim( 'wp-block-image size-large ' . $class );
		$alt = $element->getAttribute( 'alt' );

		$img_attrs = array(
			'src' => esc_url( $src ),
			'alt' => esc_attr( $alt ),
		);
		if ( isset( $attrs['id'] ) ) {
			$img_attrs['class'] = esc_attr( 'wp-image-' . $attrs['id'] );
		}
		foreach ( array( 'width', 'height' ) as $dimension ) {
			if ( (int) $element->getAttribute( $dimension ) > 0 ) {
				$img_attrs[ $dimension ] = (string) (int) $element->getAttribute( $dimension );
			}
		}

		$img_markup = '<img';
		foreach ( $img_attrs as $name => $value ) {
			$img_markup .= ' ' . $name . '="' . $value . '"';
		}
		$img_markup .= '/>';

		if ( '' !== $href ) {
			$img_markup = '<a href="' . esc_url( $href ) . '">' . $img_markup . '</a>';
		}

		return '<!-- wp:image ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' --><figure class="' . esc_attr( $figure_class ) . '">' . $img_markup . '</figure><!-- /wp:image -->';
	}

	/**
	 * Copy a local image referenced by a theme part into the generated theme.
	 *
	 * @param string $src    Source image URL/path.
	 * @param string $source Diagnostic source label.
	 * @return string|null Generated theme URL, or null when the source should be left unchanged.
	 */
	private static function materialize_local_image_asset( string $src, string $source ): ?string {
		if ( '' === self::$active_source_dir || '' === self::$active_theme_dir || '' === self::$active_theme_uri || ! self::is_local_url( $src ) ) {
			return null;
		}

		$path = wp_parse_url( $src, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === trim( $path ) ) {
			return null;
		}

		$path = ltrim( rawurldecode( $path ), '/' );
		if ( '' === $path || str_contains( $path, '..' ) ) {
			return null;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif' ), true ) ) {
			return null;
		}

		$source_file = realpath( trailingslashit( self::$active_source_dir ) . $path );
		$source_root = realpath( self::$active_source_dir );
		if ( false === $source_file || false === $source_root || ! is_file( $source_file ) || 0 !== strpos( $source_file, trailingslashit( $source_root ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local static-site asset selected for import.
		$contents = file_get_contents( $source_file );
		if ( false === $contents || '' === $contents ) {
			return null;
		}

		if ( 'svg' === $extension ) {
			$contents = self::sanitize_inline_svg( $contents );
			if ( null === $contents ) {
				self::record_unsafe_inline_svg( $source, $src );
				return null;
			}
		}

		$hash     = substr( md5( $contents ), 0, 12 );
		$basename = sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) );
		if ( '' === $basename ) {
			$basename = 'image';
		}

		$relative = 'assets/media/' . $basename . '-' . $hash . '.' . $extension;
		$target   = trailingslashit( self::$active_theme_dir ) . $relative;
		$dir      = dirname( $target );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes generated theme asset files during import.
		if ( false === file_put_contents( $target, $contents ) ) {
			return null;
		}

		return trailingslashit( self::$active_theme_uri ) . $relative;
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
	 * Build a visible editable list block for footer utility links.
	 *
	 * @param DOMDocument $doc     Source DOM document.
	 * @param DOMElement  $element Source list element.
	 * @return string|null
	 */
	private static function footer_link_list_block( DOMDocument $doc, DOMElement $element ): ?string {
		$tag = strtolower( $element->tagName );
		if ( ! in_array( $tag, array( 'ul', 'ol' ), true ) ) {
			return null;
		}

		$items = array();
		foreach ( self::direct_element_children( $element ) as $child ) {
			if ( 'li' !== strtolower( $child->tagName ) || 0 === $child->getElementsByTagName( 'a' )->length ) {
				return null;
			}

			$item_attrs = array();
			$class      = trim( $child->getAttribute( 'class' ) );
			if ( '' !== $class ) {
				$item_attrs['className'] = $class;
			}

			$comment_attrs = empty( $item_attrs ) ? '' : ' ' . wp_json_encode( $item_attrs, JSON_UNESCAPED_SLASHES );
			$class_attr    = '' === $class ? '' : ' class="' . esc_attr( $class ) . '"';
			$items[]       = '<!-- wp:list-item' . $comment_attrs . ' --><li' . $class_attr . '>' . self::node_inner_html( $doc, $child ) . '</li><!-- /wp:list-item -->';
		}

		if ( empty( $items ) ) {
			return null;
		}

		$attrs = array();
		if ( 'ol' === $tag ) {
			$attrs['ordered'] = true;
		}

		$class = trim( $element->getAttribute( 'class' ) );
		if ( '' !== $class ) {
			$attrs['className'] = $class;
		}

		$comment_attrs = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
		$class_attr    = trim( 'wp-block-list ' . $class );

		return '<!-- wp:list' . $comment_attrs . ' --><' . $tag . ' class="' . esc_attr( $class_attr ) . '">' . implode( '', $items ) . '</' . $tag . '><!-- /wp:list -->';
	}

	/**
	 * Build a footer navigation entity when the row owns one utility menu, otherwise a visible list.
	 *
	 * @param DOMDocument $doc        Source DOM document.
	 * @param DOMElement  $element    Source list element.
	 * @param string      $theme_slug Imported theme slug.
	 * @param string      $location   Theme part location.
	 * @return string|null
	 */
	private static function footer_navigation_or_list_block( DOMDocument $doc, DOMElement $element, string $theme_slug, string $location ): ?string {
		if ( self::can_convert_element_to_navigation( $element ) ) {
			$navigation = self::navigation_ref_block( $element, $theme_slug, $location );
			if ( null !== $navigation ) {
				return $navigation;
			}
		}

		return self::footer_link_list_block( $doc, $element );
	}

	/**
	 * Build a group block wrapper.
	 *
	 * @param string $inner     Inner block markup.
	 * @param string $class_name Source class attribute.
	 * @param string $tag_name   Wrapper tag name.
	 * @param string $anchor     Source ID attribute for native group anchor support.
	 * @return string
	 */
	private static function group_block( string $inner, string $class_name = '', string $tag_name = 'div', string $anchor = '' ): string {
		$class_name = trim( $class_name );
		$tag_name   = strtolower( $tag_name );
		$anchor     = trim( $anchor );
		$attrs      = array();
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}
		if ( 'div' !== $tag_name ) {
			$attrs['tagName'] = $tag_name;
		}
		if ( '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
		}

		$comment_attrs = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
		$class_attr    = trim( 'wp-block-group ' . $class_name );
		$id_attr       = '' === $anchor ? '' : ' id="' . esc_attr( $anchor ) . '"';

		return '<!-- wp:group' . $comment_attrs . ' --><' . $tag_name . $id_attr . ' class="' . esc_attr( $class_attr ) . '">' . $inner . '</' . $tag_name . '><!-- /wp:group -->';
	}

	/**
	 * Build an HTML block.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private static function html_block( string $html ): string {
		return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
	}

	/**
	 * Build a paragraph block.
	 *
	 * @param string $inner_html  Paragraph inner HTML.
	 * @param string $class_name  Source class attribute.
	 * @return string
	 */
	private static function paragraph_block( string $inner_html, string $class_name = '' ): string {
		$class_name = trim( $class_name );
		$attrs      = '' === $class_name ? '' : ' ' . wp_json_encode( array( 'className' => $class_name ), JSON_UNESCAPED_SLASHES );
		$class_attr = '' === $class_name ? '' : ' class="' . esc_attr( $class_name ) . '"';

		return '<!-- wp:paragraph' . $attrs . ' --><p' . $class_attr . '>' . $inner_html . '</p><!-- /wp:paragraph -->';
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
		$body        = $page->body();

		if ( 'markdown' === $page->body_format() ) {
			$body = self::rewrite_markdown_links( $body, $route_map, $source_path, $source );
		} else {
			$body = self::rewrite_internal_links( $body, $route_map, $source_path, $source );
			$body = self::rewrite_asset_map_image_references( $body, $source_path, $source );
		}

		return self::convert_fragment( $body, $source, $page->body_format() );
	}

	/**
	 * Rewrite Markdown inline links/images using the shared source route map.
	 *
	 * @param string               $markdown    Markdown source.
	 * @param array<string,string> $route_map   Route map.
	 * @param string               $source_path Source-relative source path.
	 * @param string               $source      Diagnostic source label.
	 * @return string
	 */
	private static function rewrite_markdown_links( string $markdown, array $route_map, string $source_path, string $source ): string {
		if ( '' === trim( $markdown ) ) {
			return $markdown;
		}

		return preg_replace_callback(
			'/(\!?)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
			static function ( array $matches ) use ( $route_map, $source_path, $source ): string {
				$prefix      = $matches[1];
				$label       = $matches[2];
				$destination = trim( $matches[3], '<>' );

				if ( '!' === $prefix ) {
					if ( self::is_local_url( $destination ) ) {
						self::record_unresolved_internal_link( $source, $source_path, $destination, 'local_asset_not_materialized' );
					}
					return $matches[0];
				}

				$replacement = self::resolve_route_href( $destination, $source_path, $route_map );
				if ( null === $replacement ) {
					self::record_unresolved_internal_link( $source, $source_path, $destination );
					return $matches[0];
				}

				return '[' . $label . '](' . $replacement . ')';
			},
			$markdown
		) ?? $markdown;
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
	 * Collect inline and linked local CSS.
	 *
	 * @param string                        $site_dir Site directory.
	 * @param Static_Site_Importer_Document $document Source document.
	 * @return string
	 */
	private static function site_css( string $site_dir, Static_Site_Importer_Document $document ): string {
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
				$css[] = trim( $contents );
			}
		}

		$inline = $document->inline_css();
		if ( '' !== $inline ) {
			$css[] = $inline;
		}

		return trim( implode( "\n\n", array_filter( $css ) ) );
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

		$result = self::write_file( $path, $svg . "\n" );
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

		$result = self::write_file( $path, $svg . "\n" );
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
	 * Convert source content to block markup.
	 *
	 * @param string $html   Source content.
	 * @param string $source Source label.
	 * @param string $format Source format.
	 * @return string
	 */
	private static function convert_fragment( string $html, string $source = 'fragment', string $format = 'html' ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( 'html' === $format ) {
			$html = self::materialize_inline_svg_icons( $html, $source );
		}

		self::start_conversion_fragment( $source, $html );
		$fallback_listener     = static function ( string $element_html, array $context, array $block ) use ( $source ): void {
			self::record_unsupported_fallback( $source, $element_html, $context, $block );
		};
		$content_loss_listener = static function ( int $original_text_length, int $serialized_text_length ) use ( $source ): void {
			self::record_content_loss( $source, $original_text_length, $serialized_text_length );
		};

		$commerce_metadata_listener = static function ( array $metadata ) use ( $source ): void {
			self::record_commerce_conversion_metadata( $source, $metadata );
		};
		$commerce_request_listener  = static function ( array $request ) use ( $source ): void {
			self::record_commerce_conversion_metadata( $source, array_merge( array( 'type' => 'materialization_request' ), $request ) );
		};

		add_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10, 3 );
		add_action( 'html_to_blocks_conversion_aborted_content_loss', $content_loss_listener, 10, 2 );
		add_action( 'bfb_conversion_metadata', $commerce_metadata_listener, 10, 1 );
		add_action( 'bfb_materialization_request', $commerce_request_listener, 10, 1 );
		$conversion_options = empty( self::$active_commerce_context ) ? array() : self::conversion_options( $source );
		$blocks             = self::compile_fragment_to_blocks( $html, $source, $format, $conversion_options );
		remove_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10 );
		remove_action( 'html_to_blocks_conversion_aborted_content_loss', $content_loss_listener, 10 );
		remove_action( 'bfb_conversion_metadata', $commerce_metadata_listener, 10 );
		remove_action( 'bfb_materialization_request', $commerce_request_listener, 10 );

		if ( '' === $blocks ) {
			self::record_conversion_empty( $source, $html );
		}
		$blocks = self::restore_dropped_empty_decorative_groups( $html, self::mark_empty_decorative_group_blocks( $blocks, $source ) );
		self::finish_conversion_fragment( $source, $blocks );

		return '' === $blocks ? '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->' : $blocks;
	}

	/**
	 * Compile one source fragment into serialized block markup.
	 *
	 * Block Artifact Compiler owns the semantic artifact envelope. SSI remains the
	 * materializer that writes the compiler result into WordPress theme artifacts.
	 *
	 * @param string               $html    Source fragment.
	 * @param string               $source  Source label.
	 * @param string               $format  Source format.
	 * @param array<string, mixed> $options Conversion options.
	 * @return string Serialized block markup.
	 */
	private static function compile_fragment_to_blocks( string $html, string $source, string $format, array $options ): string {
		if ( 'html' !== $format ) {
			// @phpstan-ignore-next-line function.notFound -- Loaded by the bundled Block Format Bridge runtime.
			return (string) bfb_convert( $html, $format, 'blocks', $options );
		}

		$compiled = bac_compile_fragment( $html, $source, $format, $options );
		self::record_block_artifact_compiler_result( $source, $compiled );

		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		return isset( $artifacts['block_markup'] ) ? (string) $artifacts['block_markup'] : '';
	}

	/**
	 * Record a compact Block Artifact Compiler summary on the import report.
	 *
	 * @param string              $source   Source label.
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	private static function record_block_artifact_compiler_result( string $source, array $compiled ): void {
		$summary           = bac_summarize_result( $compiled );
		$summary['source'] = '' !== (string) ( $summary['source'] ?? '' ) ? (string) $summary['source'] : $source;

		self::$conversion_report['block_artifact_compiler']['available']      = true;
		self::$conversion_report['block_artifact_compiler']['fragments'][]    = $summary;
		self::$conversion_report['block_artifact_compiler']['fragment_count'] = count( self::$conversion_report['block_artifact_compiler']['fragments'] );

		if ( isset( self::$conversion_report['conversion_fragments'][ $source ] ) ) {
			self::$conversion_report['conversion_fragments'][ $source ]['compiler'] = $summary;
		}
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
				'svg_icons'   => array(),
				'svg_sprites' => array(),
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
	 * Record source document counts and skipped/linked source diagnostics.
	 *
	 * @param string                                           $site_dir    Source site directory.
	 * @param array<string, Static_Site_Importer_Source_Page> $pages       Imported pages.
	 * @param array<string,string>                             $permalinks Imported page permalinks.
	 * @return void
	 */
	private static function record_source_documents_summary( string $site_dir, array $pages, array $permalinks ): void {
		$counts = array(
			'html'     => 0,
			'markdown' => 0,
			'mdx'      => 0,
		);
		foreach ( $pages as $page ) {
			$format = $page->type();
			if ( isset( $counts[ $format ] ) ) {
				++$counts[ $format ];
			}
		}

		$mdx_files     = self::collect_source_files_by_extension( $site_dir, array( 'mdx' ) );
		$counts['mdx'] = count( $mdx_files );
		foreach ( $mdx_files as $mdx_file ) {
			self::$conversion_report['diagnostics'][] = array(
				'type'    => 'unsupported_source_document',
				'format'  => 'mdx',
				'source'  => $mdx_file,
				'message' => 'MDX source documents are not supported by Static Site Importer and were skipped. Build MDX to static HTML first, or provide plain .md/.markdown content.',
			);
		}

		$unresolved                                  = self::collect_unresolved_source_links( $pages, $permalinks );
		self::$conversion_report['source_documents'] = array_merge(
			self::$conversion_report['source_documents'],
			array(
				'total_count'           => array_sum( $counts ),
				'counts_by_format'      => $counts,
				'skipped_mdx_count'     => count( $mdx_files ),
				'unresolved_links'      => $unresolved,
				'unresolved_link_count' => count( $unresolved ),
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
	 * Collect source file paths by extension under a source directory.
	 *
	 * @param string            $site_dir   Source site directory.
	 * @param array<int,string> $extensions Lowercase extensions.
	 * @return array<int,string> Relative source paths.
	 */
	private static function collect_source_files_by_extension( string $site_dir, array $extensions ): array {
		$files = array();
		$root  = realpath( $site_dir );
		if ( false === $root ) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $site_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() || ! in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
				continue;
			}

			$path = $file->getPathname();
			if ( ! self::path_is_under( $path, $root ) ) {
				continue;
			}

			$files[] = str_replace( DIRECTORY_SEPARATOR, '/', ltrim( substr( $path, strlen( trailingslashit( $root ) ) ), DIRECTORY_SEPARATOR ) );
		}

		sort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Collect local links that do not resolve to imported source documents.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Imported pages.
	 * @param array<string,string>                             $permalinks Imported page permalinks.
	 * @return array<int,array{source:string,href:string}>
	 */
	private static function collect_unresolved_source_links( array $pages, array $permalinks ): array {
		$unresolved = array();
		foreach ( $pages as $filename => $page ) {
			$source = $page->body();
			foreach ( self::extract_local_source_links( $source, 'markdown' === $page->body_format() ) as $href ) {
				$path = strtok( explode( '#', $href, 2 )[0], '?' );
				$key  = self::resolve_source_link_key( $filename, false === $path ? $href : $path );
				if ( isset( $permalinks[ $key ] ) || isset( $permalinks[ basename( $key ) ] ) ) {
					continue;
				}

				$unresolved[] = array(
					'source' => $filename,
					'href'   => $href,
				);
			}
		}

		return $unresolved;
	}

	/**
	 * Extract local HTML or Markdown links from source content.
	 *
	 * @param string $source      Source content.
	 * @param bool   $is_markdown Whether the source is Markdown.
	 * @return array<int,string>
	 */
	private static function extract_local_source_links( string $source, bool $is_markdown ): array {
		$links = array();
		$regex = $is_markdown ? '/\]\(([^)]+)\)/' : '/\bhref=("|\')([^"\']+)(\1)/i';
		if ( preg_match_all( $regex, $source, $matches ) ) {
			$matched_hrefs = $matches[1];
			if ( ! $is_markdown ) {
				$matched_hrefs = isset( $matches[2] ) ? $matches[2] : array();
			}

			foreach ( $matched_hrefs as $href ) {
				$href = trim( html_entity_decode( (string) $href, ENT_QUOTES ) );
				if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) ) {
					continue;
				}

				$href_path = strtok( explode( '#', $href, 2 )[0], '?' );
				$extension = strtolower( pathinfo( false !== $href_path ? $href_path : $href, PATHINFO_EXTENSION ) );
				if ( in_array( $extension, array( 'html', 'htm', 'md', 'markdown', 'mdx' ), true ) ) {
					$links[] = $href;
				}
			}
		}

		return array_values( array_unique( $links ) );
	}

	/**
	 * Resolve a local link against its source document path.
	 *
	 * @param string $source_filename Current source filename.
	 * @param string $href_path       Link path without query or fragment.
	 * @return string Source document key.
	 */
	private static function resolve_source_link_key( string $source_filename, string $href_path ): string {
		$key = ltrim( str_replace( '\\', '/', $href_path ), './' );
		if ( '' === $key || str_starts_with( $key, '/' ) ) {
			return ltrim( $key, '/' );
		}

		$base_dir = dirname( $source_filename );
		if ( '.' === $base_dir || '' === $base_dir || str_starts_with( $href_path, './' ) ) {
			$combined = ( '.' === $base_dir || '' === $base_dir ) ? $key : $base_dir . '/' . ltrim( $href_path, './' );
		} elseif ( ! str_contains( $key, '/' ) ) {
			$combined = $base_dir . '/' . $key;
		} else {
			$combined = $key;
		}

		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', $combined ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}

			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	/**
	 * Resolve validated commerce context supplied by the caller.
	 *
	 * @param array<string, mixed> $args             Import args.
	 * @param array<string, mixed> $inferred_context Inferred commerce context.
	 * @return array<string, mixed>
	 */
	private static function commerce_context_from_args( array $args, array $inferred_context = array() ): array {
		$manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
		if ( is_array( $manifest ) && true === ( $manifest['valid'] ?? false ) ) {
			self::set_commerce_product_inference_report( 'manifest', isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : array() );
			return array(
				'source'   => 'products.json',
				'products' => isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : array(),
			);
		}

		if ( isset( $args['commerce_context'] ) && is_array( $args['commerce_context'] ) ) {
			return $args['commerce_context'];
		}

		if ( ! empty( $inferred_context ) ) {
			return $inferred_context;
		}

		return array();
	}

	/**
	 * Determine whether SSI should infer products from source HTML.
	 *
	 * @param array<string, mixed> $args Import args.
	 * @return bool
	 */
	private static function should_infer_commerce_context( array $args ): bool {
		$manifest = self::$conversion_report['commerce']['products_manifest'] ?? array();
		if ( is_array( $manifest ) && true === ( $manifest['valid'] ?? false ) ) {
			return false;
		}

		return ! isset( $args['commerce_context'] ) || ! is_array( $args['commerce_context'] );
	}

	/**
	 * Do not warn about an optional manifest when source HTML supplied product context.
	 *
	 * @return void
	 */
	private static function suppress_manifest_diagnostic_when_html_supplies_products(): void {
		if ( ! in_array( self::$active_commerce_context['source'] ?? '', array( 'json_ld', 'html_cards' ), true ) || empty( self::$conversion_report['diagnostics'] ) ) {
			return;
		}

		self::$conversion_report['diagnostics'] = array_values(
			array_filter(
				self::$conversion_report['diagnostics'],
				static fn ( $diagnostic ): bool => ! is_array( $diagnostic ) || 'products_manifest_invalid' !== ( $diagnostic['code'] ?? '' )
			)
		);
	}

	/**
	 * Infer commerce context from static storefront HTML when no valid manifest exists.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages Source pages.
	 * @return array<string, mixed>
	 */
	private static function infer_commerce_context_from_pages( array $pages ): array {
		$json_ld_products = array();
		$card_products    = array();
		$selector_hints   = array();
		$rejected         = array();

		foreach ( $pages as $filename => $page ) {
			if ( 'html' !== $page->type() ) {
				continue;
			}

			$html = self::read_visual_probe_file( $page->path() );
			if ( '' === trim( $html ) ) {
				continue;
			}

			$doc = self::load_html_document( $html );
			if ( null === $doc ) {
				continue;
			}

			$json_ld_products = array_merge( $json_ld_products, self::json_ld_products_from_document( $doc, $filename ) );
			$cards            = self::product_card_products_from_document( $doc, $filename );
			$card_products    = array_merge( $card_products, $cards['products'] );
			$selector_hints   = array_merge( $selector_hints, $cards['selector_hints'] );
			$rejected         = array_merge( $rejected, $cards['rejected'] );
		}

		if ( ! empty( $json_ld_products ) ) {
			$products = self::dedupe_products_by_slug( $json_ld_products );
			self::set_commerce_product_inference_report( 'json_ld', $products, array(), array(), array() );
			return array(
				'source'   => 'json_ld',
				'products' => $products,
			);
		}

		if ( count( $card_products ) >= 2 ) {
			$products = self::dedupe_products_by_slug( $card_products );
			self::set_commerce_product_inference_report( 'html_cards', $products, $selector_hints, $rejected, array() );
			return array(
				'source'         => 'html_cards',
				'products'       => $products,
				'selector_hints' => $selector_hints,
			);
		}

		if ( ! empty( $card_products ) ) {
			foreach ( $card_products as $product ) {
				$rejected[] = array(
					'source_filename' => isset( $product['source_filename'] ) ? (string) $product['source_filename'] : '',
					'selector'        => isset( $product['card_selector'] ) ? (string) $product['card_selector'] : '',
					'reason'          => 'html_card_inference_requires_repeated_products',
				);
			}
		}

		$warnings = array( 'No valid products.json manifest, JSON-LD Product data, or visible product cards with prices were found.' );
		self::set_commerce_product_inference_report( 'none', array(), $selector_hints, $rejected, $warnings );
		self::$conversion_report['diagnostics'][] = array(
			'code'     => 'commerce_product_inference_none',
			'severity' => 'warning',
			'source'   => 'html',
			'message'  => $warnings[0],
		);

		return array();
	}

	/**
	 * Store commerce product inference diagnostics on the import report.
	 *
	 * @param string           $strategy       Extraction strategy.
	 * @param array<int,array> $products       Products inferred or selected.
	 * @param array<int,array> $selector_hints Selector hints.
	 * @param array<int,array> $rejected       Rejected candidates.
	 * @param array<int,string>  $warnings      Warnings.
	 * @return void
	 */
	private static function set_commerce_product_inference_report( string $strategy, array $products, array $selector_hints = array(), array $rejected = array(), array $warnings = array() ): void {
		self::$conversion_report['commerce_product_inference'] = array(
			'strategy'                => $strategy,
			'product_count'           => count( $products ),
			'skipped_candidate_count' => count( $rejected ),
			'selector_hints'          => $selector_hints,
			'warnings'                => $warnings,
			'rejected_candidates'     => $rejected,
			'products'                => array_map(
				static function ( array $product ): array {
					$summary = array(
						'name'          => isset( $product['name'] ) ? (string) $product['name'] : '',
						'slug'          => isset( $product['slug'] ) ? (string) $product['slug'] : '',
						'regular_price' => isset( $product['regular_price'] ) ? (string) $product['regular_price'] : '',
					);
					foreach ( array( 'source_filename', 'card_selector', 'image', 'categories' ) as $field ) {
						if ( isset( $product[ $field ] ) ) {
							$summary[ $field ] = $product[ $field ];
						}
					}
					return $summary;
				},
				$products
			),
		);
	}

	/**
	 * Parse a full HTML document for commerce extraction.
	 *
	 * @param string $html Source HTML.
	 * @return DOMDocument|null
	 */
	private static function load_html_document( string $html ): ?DOMDocument {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $doc : null;
	}

	/**
	 * Extract JSON-LD Product objects from a document.
	 *
	 * @param DOMDocument $doc      Source document.
	 * @param string      $filename Source filename.
	 * @return array<int, array<string, mixed>>
	 */
	private static function json_ld_products_from_document( DOMDocument $doc, string $filename ): array {
		$products = array();
		foreach ( $doc->getElementsByTagName( 'script' ) as $script ) {
			if ( ! str_contains( strtolower( $script->getAttribute( 'type' ) ), 'ld+json' ) ) {
				continue;
			}

			$data = json_decode( trim( $script->textContent ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				continue;
			}

			foreach ( self::json_ld_product_nodes( $data ) as $node ) {
				$product = self::product_from_json_ld_node( $node, $filename );
				if ( ! empty( $product ) ) {
					$products[] = $product;
				}
			}
		}

		return $products;
	}

	/**
	 * Find Product-shaped nodes in decoded JSON-LD.
	 *
	 * @param mixed $node Decoded JSON-LD node.
	 * @return array<int, array<string, mixed>>
	 */
	private static function json_ld_product_nodes( $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}

		$nodes = array();
		$type  = $node['@type'] ?? '';
		$types = is_array( $type ) ? $type : array( $type );
		foreach ( $types as $candidate_type ) {
			if ( is_string( $candidate_type ) && 'product' === strtolower( $candidate_type ) ) {
				$nodes[] = $node;
				break;
			}
		}

		foreach ( array( '@graph', 'itemListElement', 'item' ) as $key ) {
			if ( isset( $node[ $key ] ) ) {
				$nodes = array_merge( $nodes, self::json_ld_product_nodes( $node[ $key ] ) );
			}
		}

		if ( array_is_list( $node ) ) {
			foreach ( $node as $child ) {
				$nodes = array_merge( $nodes, self::json_ld_product_nodes( $child ) );
			}
		}

		return $nodes;
	}

	/**
	 * Normalize a JSON-LD Product node into the seeder product shape.
	 *
	 * @param array<string, mixed> $node     JSON-LD node.
	 * @param string               $filename Source filename.
	 * @return array<string, mixed>
	 */
	private static function product_from_json_ld_node( array $node, string $filename ): array {
		$name  = self::commerce_scalar( $node['name'] ?? '' );
		$price = self::commerce_price_from_json_ld_offer( $node['offers'] ?? array() );
		if ( '' === $name || '' === $price ) {
			return array();
		}

		$url  = self::commerce_scalar( $node['url'] ?? '' );
		$slug = '' !== $url ? sanitize_title( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) : sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		$image = $node['image'] ?? '';
		if ( is_array( $image ) ) {
			$image = reset( $image );
		}

		$product = array(
			'name'            => $name,
			'slug'            => $slug,
			'regular_price'   => $price,
			'description'     => self::commerce_scalar( $node['description'] ?? '' ),
			'image'           => self::commerce_scalar( $image ),
			'source_filename' => $filename,
			'card_selector'   => 'script[type="application/ld+json"]',
		);

		$category = self::commerce_scalar( $node['category'] ?? '' );
		if ( '' !== $category ) {
			$product['categories'] = array( $category );
		}

		return $product;
	}

	/**
	 * Extract a price from JSON-LD offers.
	 *
	 * @param mixed $offers Offers node.
	 * @return string
	 */
	private static function commerce_price_from_json_ld_offer( $offers ): string {
		if ( is_array( $offers ) && array_is_list( $offers ) ) {
			foreach ( $offers as $offer ) {
				$price = self::commerce_price_from_json_ld_offer( $offer );
				if ( '' !== $price ) {
					return $price;
				}
			}
			return '';
		}

		if ( ! is_array( $offers ) ) {
			return self::normalize_commerce_price( self::commerce_scalar( $offers ) );
		}

		foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $key ) {
			$price = self::normalize_commerce_price( self::commerce_scalar( $offers[ $key ] ?? '' ) );
			if ( '' !== $price ) {
				return $price;
			}
		}

		return '';
	}

	/**
	 * Extract visible product cards from a document.
	 *
	 * @param DOMDocument $doc      Source document.
	 * @param string      $filename Source filename.
	 * @return array{products:array<int,array<string,mixed>>,selector_hints:array<int,array<string,mixed>>,rejected:array<int,array<string,string>>}
	 */
	private static function product_card_products_from_document( DOMDocument $doc, string $filename ): array {
		$products       = array();
		$selector_hints = array();
		$rejected       = array();
		$xpath          = new DOMXPath( $doc );
		$nodes          = $xpath->query( '//*[self::article or self::li or self::div][contains(translate(concat(" ", @class, " ", @id, " ", @itemtype, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "product") or @data-product-slug or @data-product-name or @data-price]' );

		if ( ! $nodes instanceof DOMNodeList ) {
			return array(
				'products'       => array(),
				'selector_hints' => array(),
				'rejected'       => array(),
			);
		}

		foreach ( $nodes as $node ) {
			if (
				! $node instanceof DOMElement ||
				! self::element_looks_like_product_card( $node ) ||
				self::has_descendant_product_candidate( $node )
			) {
				continue;
			}

			$selector = self::commerce_selector_for_element( $node );
			$product  = self::product_from_card_element( $doc, $node, $filename, $selector );
			if ( empty( $product ) ) {
				if ( '' !== trim( (string) $node->textContent ) ) {
					$rejected[] = array(
						'source_filename' => $filename,
						'selector'        => $selector,
						'reason'          => 'missing_name_or_price',
					);
				}
				continue;
			}

			$products[]       = $product;
			$selector_hints[] = array(
				'source_filename' => $filename,
				'card_selector'   => $selector,
			);
		}

		return array(
			'products'       => $products,
			'selector_hints' => self::dedupe_selector_hints( $selector_hints ),
			'rejected'       => $rejected,
		);
	}

	/**
	 * Check whether a broad candidate contains a more specific product/card candidate.
	 *
	 * @param DOMElement $element Candidate element.
	 * @return bool
	 */
	private static function has_descendant_product_candidate( DOMElement $element ): bool {
		foreach ( $element->getElementsByTagName( '*' ) as $child ) {
			if ( self::element_looks_like_product_card( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an element looks like a product/card candidate.
	 *
	 * @param DOMElement $element Element.
	 * @return bool
	 */
	private static function element_looks_like_product_card( DOMElement $element ): bool {
		$tag       = strtolower( $element->tagName );
		$haystack  = strtolower( $element->getAttribute( 'class' ) . ' ' . $element->getAttribute( 'id' ) );
		$valid_tag = in_array( $tag, array( 'article', 'li', 'div' ), true );

		return $valid_tag && (
			$element->hasAttribute( 'data-product-slug' ) ||
			$element->hasAttribute( 'data-product-name' ) ||
			$element->hasAttribute( 'data-price' ) ||
			( str_contains( $haystack, 'product' ) && preg_match( '/(?:^|[-_\s])(?:card|item|tile)(?:$|[-_\s])/', $haystack ) )
		);
	}

	/**
	 * Normalize one visible product card into the seeder product shape.
	 *
	 * @param DOMDocument $doc      Source document.
	 * @param DOMElement  $element  Card element.
	 * @param string      $filename Source filename.
	 * @param string      $selector Selector hint.
	 * @return array<string, mixed>
	 */
	private static function product_from_card_element( DOMDocument $doc, DOMElement $element, string $filename, string $selector ): array {
		$name  = self::product_card_name( $element );
		$price = self::normalize_commerce_price( self::product_card_price_text( $element ) );
		if ( '' === $name || '' === $price ) {
			return array();
		}

		$slug = sanitize_title( $element->getAttribute( 'data-product-slug' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		$product = array(
			'name'            => $name,
			'slug'            => $slug,
			'regular_price'   => $price,
			'source_filename' => $filename,
			'card_selector'   => $selector,
		);

		$image = self::product_card_image( $element );
		if ( '' !== $image ) {
			$product['image'] = $image;
		}

		$category = self::product_card_category( $element );
		if ( '' !== $category ) {
			$product['categories'] = array( $category );
		}

		return $product;
	}

	/**
	 * Extract a product name from a card.
	 *
	 * @param DOMElement $element Card element.
	 * @return string
	 */
	private static function product_card_name( DOMElement $element ): string {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$headings = $element->getElementsByTagName( $tag );
			if ( $headings->length > 0 ) {
				return self::clean_commerce_text( $headings->item( 0 )->textContent ?? '' );
			}
		}

		foreach ( array( 'data-product-name', 'aria-label', 'title' ) as $attribute ) {
			$value = self::clean_commerce_text( $element->getAttribute( $attribute ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract raw price text from a card.
	 *
	 * @param DOMElement $element Card element.
	 * @return string
	 */
	private static function product_card_price_text( DOMElement $element ): string {
		foreach ( array( 'data-price', 'data-regular-price' ) as $attribute ) {
			$value = self::clean_commerce_text( $element->getAttribute( $attribute ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		foreach ( $element->getElementsByTagName( '*' ) as $child ) {
			$haystack = strtolower( $child->getAttribute( 'class' ) . ' ' . $child->getAttribute( 'data-price' ) );
			if ( str_contains( $haystack, 'price' ) ) {
				return self::clean_commerce_text( $child->textContent );
			}
		}

		return self::clean_commerce_text( $element->textContent );
	}

	/**
	 * Extract image source from a product card.
	 *
	 * @param DOMElement $element Card element.
	 * @return string
	 */
	private static function product_card_image( DOMElement $element ): string {
		$images = $element->getElementsByTagName( 'img' );
		if ( $images->length <= 0 ) {
			return '';
		}

		$image = $images->item( 0 );
		return $image instanceof DOMElement ? trim( $image->getAttribute( 'src' ) ) : '';
	}

	/**
	 * Infer a product category from nearby section context.
	 *
	 * @param DOMElement $element Card element.
	 * @return string
	 */
	private static function product_card_category( DOMElement $element ): string {
		$parent = $element->parentNode;
		while ( $parent instanceof DOMElement ) {
			if ( in_array( strtolower( $parent->tagName ), array( 'section', 'main' ), true ) ) {
				foreach ( array( 'h1', 'h2', 'h3' ) as $tag ) {
					$headings = $parent->getElementsByTagName( $tag );
					if ( $headings->length > 0 ) {
						$category = self::clean_commerce_text( $headings->item( 0 )->textContent ?? '' );
						if ( '' !== $category && self::product_card_name( $element ) !== $category ) {
							return $category;
						}
					}
				}
			}

			$parent = $parent->parentNode;
		}

		return '';
	}

	/**
	 * Build a stable selector hint for a DOM element.
	 *
	 * @param DOMElement $element Element.
	 * @return string
	 */
	private static function commerce_selector_for_element( DOMElement $element ): string {
		$slug = sanitize_title( $element->getAttribute( 'data-product-slug' ) );
		if ( '' !== $slug ) {
			return '[data-product-slug="' . $slug . '"]';
		}

		$id = sanitize_html_class( $element->getAttribute( 'id' ) );
		if ( '' !== $id ) {
			return '#' . $id;
		}

		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );
		$classes = is_array( $classes ) ? array_values( array_filter( array_map( 'sanitize_html_class', $classes ) ) ) : array();
		if ( ! empty( $classes ) ) {
			return strtolower( $element->tagName ) . '.' . implode( '.', array_slice( $classes, 0, 2 ) );
		}

		return strtolower( $element->tagName );
	}

	/**
	 * Normalize scalar commerce data.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function commerce_scalar( $value ): string {
		return is_scalar( $value ) ? self::clean_commerce_text( (string) $value ) : '';
	}

	/**
	 * Normalize visible text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function clean_commerce_text( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
	}

	/**
	 * Normalize a storefront price to the products.json decimal string shape.
	 *
	 * @param string $price Raw price.
	 * @return string
	 */
	private static function normalize_commerce_price( string $price ): string {
		if ( ! preg_match( '/(?:[$€£]\s*)?([0-9]+(?:,[0-9]{3})*(?:\.[0-9]{1,2})?|[0-9]+)(?:\s*(?:USD|EUR|GBP))?/i', $price, $matches ) ) {
			return '';
		}

		$number = str_replace( ',', '', $matches[1] );
		return number_format( (float) $number, 2, '.', '' );
	}

	/**
	 * Dedupe products by slug.
	 *
	 * @param array<int, array<string, mixed>> $products Products.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_products_by_slug( array $products ): array {
		$seen   = array();
		$result = array();
		foreach ( $products as $product ) {
			$slug = isset( $product['slug'] ) ? (string) $product['slug'] : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$result[]      = $product;
		}

		return $result;
	}

	/**
	 * Dedupe selector hints.
	 *
	 * @param array<int, array<string, mixed>> $hints Selector hints.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe_selector_hints( array $hints ): array {
		$seen   = array();
		$result = array();
		foreach ( $hints as $hint ) {
			$key = ( $hint['source_filename'] ?? '' ) . '|' . ( $hint['card_selector'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = $hint;
		}

		return $result;
	}

	/**
	 * Build BFB conversion options for one source fragment.
	 *
	 * @param string $source Source fragment label.
	 * @return array<string, mixed>
	 */
	private static function conversion_options( string $source ): array {
		if ( empty( self::$active_commerce_context ) ) {
			return array();
		}

		return array(
			'context' => array(
				'commerce' => array_merge(
					self::$active_commerce_context,
					array(
						'source_fragment' => $source,
					)
				),
			),
			'source'  => array(
				'fragment' => $source,
			),
		);
	}

	/**
	 * Record and validate a generated store products manifest when present.
	 *
	 * @param string $site_dir Static site directory.
	 * @return void
	 */
	private static function record_products_manifest( string $site_dir ): void {
		$path = trailingslashit( $site_dir ) . 'products.json';
		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an optional local static-site manifest for report-only validation.
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			self::set_products_manifest_report(
				$path,
				false,
				array(),
				array(
					array(
						'path'    => '$',
						'message' => 'products.json could not be read.',
					),
				)
			);
			return;
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			self::set_products_manifest_report(
				$path,
				false,
				array(),
				array(
					array(
						'path'    => '$',
						'message' => 'products.json is not valid JSON: ' . json_last_error_msg(),
					),
				)
			);
			return;
		}

		$validation = self::validate_products_manifest( $data );
		self::set_products_manifest_report( $path, empty( $validation['errors'] ), $validation['products'], $validation['errors'] );
	}

	/**
	 * Store products manifest validation results on the import report.
	 *
	 * @param string               $path     Manifest path.
	 * @param bool                 $valid    Whether the manifest is valid.
	 * @param array<int,array>     $products Validated product summaries.
	 * @param array<int,array>     $errors   Validation errors.
	 * @return void
	 */
	private static function set_products_manifest_report( string $path, bool $valid, array $products, array $errors ): void {
		self::$conversion_report['commerce'] = array(
			'products_manifest' => array(
				'present'       => true,
				'path'          => $path,
				'contract'      => array(
					'schema'          => 'static-site-importer/products.json',
					'schema_version'  => 1,
					'required_fields' => array( 'name', 'slug', 'regular_price' ),
					'optional_fields' => array( 'sale_price', 'description', 'short_description', 'categories', 'image', 'status', 'stock_status', 'stock_quantity', 'source_selectors' ),
				),
				'valid'         => $valid,
				'product_count' => count( $products ),
				'products'      => $products,
				'errors'        => $errors,
			),
		);

		if ( $valid ) {
			return;
		}

		self::$conversion_report['diagnostics'][] = array(
			'code'     => 'products_manifest_invalid',
			'severity' => 'warning',
			'source'   => 'products.json',
			'message'  => 'products.json is present but does not match the Static Site Importer generated-store contract.',
			'errors'   => $errors,
		);
	}

	/**
	 * Record whether commerce context is available for this import.
	 *
	 * @return void
	 */
	private static function record_commerce_context_summary(): void {
		if ( empty( self::$active_commerce_context ) ) {
			return;
		}

		$products = isset( self::$active_commerce_context['products'] ) && is_array( self::$active_commerce_context['products'] ) ? self::$active_commerce_context['products'] : array();
		self::$conversion_report['commerce_context']['supplied']       = true;
		self::$conversion_report['commerce_context']['source']         = isset( self::$active_commerce_context['source'] ) ? (string) self::$active_commerce_context['source'] : 'import_args';
		self::$conversion_report['commerce_context']['product_count']  = count( $products );
		self::$conversion_report['commerce_context']['selector_hints'] = self::commerce_selector_hints( self::$active_commerce_context );
	}

	/**
	 * Extract source filename and selector hints from validated commerce context.
	 *
	 * @param array<string, mixed> $context Validated commerce context.
	 * @return array<int, array<string, mixed>>
	 */
	private static function commerce_selector_hints( array $context ): array {
		$hints = array();
		if ( isset( $context['selector_hints'] ) && is_array( $context['selector_hints'] ) ) {
			foreach ( $context['selector_hints'] as $hint ) {
				if ( is_array( $hint ) ) {
					$hints[] = self::normalize_commerce_selector_hint( $hint );
				}
			}
		}

		$products = isset( $context['products'] ) && is_array( $context['products'] ) ? $context['products'] : array();
		foreach ( $products as $product ) {
			if ( is_array( $product ) ) {
				$hint = self::normalize_commerce_selector_hint( $product );
				if ( ! empty( $hint ) ) {
					$hints[] = $hint;
				}
			}
		}

		return $hints;
	}

	/**
	 * Normalize one commerce selector hint for report output.
	 *
	 * @param array<string, mixed> $source Source hint data.
	 * @return array<string, mixed>
	 */
	private static function normalize_commerce_selector_hint( array $source ): array {
		$hint = array();
		foreach ( array( 'source_file', 'source_filename', 'selector', 'grid_selector', 'card_selector' ) as $key ) {
			if ( isset( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
				$hint[ $key ] = (string) $source[ $key ];
			}
		}

		return $hint;
	}

	/**
	 * Validate the generated store products manifest contract.
	 *
	 * @param mixed $data Decoded JSON data.
	 * @return array{products:array<int,array<string,mixed>>,errors:array<int,array<string,string>>}
	 */
	private static function validate_products_manifest( $data ): array {
		$products = array();
		$errors   = array();

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return array(
				'products' => array(),
				'errors'   => array(
					array(
						'path'    => '$',
						'message' => 'products.json must be an object with schema_version and products fields.',
					),
				),
			);
		}

		if ( 1 !== (int) ( $data['schema_version'] ?? 0 ) ) {
			$errors[] = array(
				'path'    => '$.schema_version',
				'message' => 'schema_version must be 1.',
			);
		}

		if ( ! isset( $data['products'] ) || ! is_array( $data['products'] ) || ! array_is_list( $data['products'] ) ) {
			$errors[] = array(
				'path'    => '$.products',
				'message' => 'products must be a JSON array.',
			);
			return array(
				'products' => array(),
				'errors'   => $errors,
			);
		}

		foreach ( $data['products'] as $index => $product ) {
			$path_prefix = '$.products[' . $index . ']';
			if ( ! is_array( $product ) || array_is_list( $product ) ) {
				$errors[] = array(
					'path'    => $path_prefix,
					'message' => 'Product must be an object.',
				);
				continue;
			}

			$name          = self::manifest_string( $product, 'name' );
			$slug          = self::manifest_string( $product, 'slug' );
			$regular_price = self::manifest_string( $product, 'regular_price' );
			$sale_price    = self::manifest_string( $product, 'sale_price', false );

			if ( '' === $name ) {
				$errors[] = array(
					'path'    => $path_prefix . '.name',
					'message' => 'name is required and must be a non-empty string.',
				);
			}

			if ( '' === $slug || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.slug',
					'message' => 'slug is required and must be a lowercase URL slug.',
				);
			}

			if ( '' === $regular_price || ! self::is_manifest_price( $regular_price ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.regular_price',
					'message' => 'regular_price is required and must be a decimal string such as "19.00".',
				);
			}

			if ( '' !== $sale_price && ! self::is_manifest_price( $sale_price ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.sale_price',
					'message' => 'sale_price must be a decimal string such as "15.00" when provided.',
				);
			}

			foreach ( array( 'description', 'short_description', 'status', 'stock_status', 'image' ) as $field ) {
				if ( isset( $product[ $field ] ) && ! is_string( $product[ $field ] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.' . $field,
						'message' => $field . ' must be a string when provided.',
					);
				}
			}

			foreach ( array( 'categories', 'source_selectors' ) as $field ) {
				if ( ! isset( $product[ $field ] ) ) {
					continue;
				}
				if ( ! is_array( $product[ $field ] ) || ! array_is_list( $product[ $field ] ) ) {
					$errors[] = array(
						'path'    => $path_prefix . '.' . $field,
						'message' => $field . ' must be an array of strings when provided.',
					);
					continue;
				}

				foreach ( $product[ $field ] as $value_index => $value ) {
					if ( ! is_string( $value ) || '' === trim( $value ) ) {
						$errors[] = array(
							'path'    => $path_prefix . '.' . $field . '[' . $value_index . ']',
							'message' => $field . ' entries must be non-empty strings.',
						);
					}
				}
			}

			if ( isset( $product['stock_quantity'] ) && ! is_int( $product['stock_quantity'] ) ) {
				$errors[] = array(
					'path'    => $path_prefix . '.stock_quantity',
					'message' => 'stock_quantity must be an integer when provided.',
				);
			}

			$summary = array(
				'name'          => $name,
				'slug'          => $slug,
				'regular_price' => $regular_price,
			);
			foreach ( array( 'sale_price', 'description', 'short_description', 'categories', 'image', 'status', 'stock_status', 'stock_quantity', 'source_selectors' ) as $field ) {
				if ( array_key_exists( $field, $product ) ) {
					$summary[ $field ] = $product[ $field ];
				}
			}

			$products[] = $summary;
		}

		return array(
			'products' => empty( $errors ) ? $products : array(),
			'errors'   => $errors,
		);
	}

	/**
	 * Read a string field from a decoded manifest object.
	 *
	 * @param array<string,mixed> $data     Manifest object.
	 * @param string              $key      Field key.
	 * @param bool                $required Whether missing fields should return an empty string.
	 * @return string
	 */
	private static function manifest_string( array $data, string $key, bool $required = true ): string {
		if ( ! array_key_exists( $key, $data ) ) {
			return '';
		}

		$value = $data[ $key ];
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		return $required || '' !== $value ? $value : '';
	}

	/**
	 * Check whether a manifest price uses a stable decimal string format.
	 *
	 * @param string $price Price string.
	 * @return bool
	 */
	private static function is_manifest_price( string $price ): bool {
		return 1 === preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{2})?$/', $price );
	}

	/**
	 * Record practical source/generated targets for external visual fidelity gates.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages          Imported pages.
	 * @param array<string, int>                                                       $page_ids       Page IDs keyed by source filename.
	 * @param array<string, string>                                                    $permalinks     Page permalinks keyed by source filename.
	 * @param array<string, string>                                                    $writes         Generated files keyed by absolute path.
	 * @param string                                                                   $theme_dir      Generated theme directory.
	 * @return void
	 */
	private static function record_visual_fidelity_targets( array $pages, array $page_ids, array $permalinks, array $writes, string $theme_dir ): void {
		$theme_prefix = trailingslashit( $theme_dir );

		foreach ( $pages as $filename => $page ) {
			$source_html = self::read_visual_probe_file( $page->path() );
			$slug        = self::page_slug( $filename, $page );
			$template    = '' === $slug ? '' : 'templates/page-' . $slug . '.html';
			$pattern     = '' === $slug ? '' : 'patterns/page-' . $slug . '.php';
			$generated   = self::generated_visual_probe_markup( $writes, $theme_prefix, $pattern );
			$footer_part = isset( $writes[ $theme_prefix . 'parts/footer.html' ] ) ? 'parts/footer.html' : '';

			self::$conversion_report['visual_fidelity']['comparison_targets'][] = array(
				'source_file'            => $page->path(),
				'source_filename'        => $filename,
				'source_type'            => $page->type(),
				'wordpress_page_id'      => $page_ids[ $filename ] ?? null,
				'wordpress_url'          => $permalinks[ $filename ] ?? '',
				'generated_template'     => $template,
				'generated_pattern'      => $pattern,
				'source_probe_counts'    => self::visual_probe_counts( $source_html ),
				'generated_probe_counts' => self::visual_probe_counts( $generated ),
				'comparison_hooks'       => array(
					'screenshot'      => array(
						'source'         => $page->path(),
						'frontend'       => $permalinks[ $filename ] ?? '',
						'generated'      => $permalinks[ $filename ] ?? '',
						'editor_surface' => 'site_editor_canvas',
					),
					'render_surfaces' => array(
						'source_static'      => array(
							'type' => 'file',
							'url'  => $page->path(),
						),
						'wordpress_frontend' => array(
							'type' => 'url',
							'url'  => $permalinks[ $filename ] ?? '',
						),
						'site_editor_canvas' => array(
							'type'              => 'wp-admin',
							'url'               => admin_url( 'site-editor.php' ),
							'wordpress_page_id' => $page_ids[ $filename ] ?? null,
						),
					),
					'layout_probes'   => self::visual_layout_probes(),
					'hero'            => array( '.hero', 'header', '[class*=hero]' ),
					'buttons'         => array( 'a[class*=btn]', 'a[class*=button]', 'a[class*=cta]', 'button', '.wp-block-button__link' ),
					'visible_chrome'  => array( 'nav', 'header', 'footer', '.site-header', '.nav-shell', '.top-nav' ),
					'code_visuals'    => array( '.code-window', '.terminal', '[class*=code-window]', '[class*=terminal]', 'pre[class*=code]' ),
					'problem_grids'   => array( '.problem-grid', '.problems-grid', '.problem-cards', '[class*=problem][class*=grid]', '[class*=problem][class*=cards]' ),
					'generated_files' => array_values( array_filter( array( $template, $pattern, 'parts/header.html', $footer_part, 'style.css' ) ) ),
				),
			);
		}
	}

	/**
	 * Record source/generated targets for external semantic fidelity gates.
	 *
	 * @param array<string, Static_Site_Importer_Source_Page> $pages      Imported pages.
	 * @param array<string, int>                                                       $page_ids   Page IDs keyed by source filename.
	 * @param array<string, string>                                                    $permalinks Page permalinks keyed by source filename.
	 * @param array<string, string>                                                    $writes     Generated files keyed by absolute path.
	 * @param string                                                                   $theme_dir  Generated theme directory.
	 * @return void
	 */
	private static function record_semantic_fidelity_targets( array $pages, array $page_ids, array $permalinks, array $writes, string $theme_dir ): void {
		$theme_prefix = trailingslashit( $theme_dir );
		$theme_parts  = self::generated_semantic_theme_parts( $writes, $theme_prefix );

		foreach ( $pages as $filename => $page ) {
			$source_html = self::read_visual_probe_file( $page->path() );
			$slug        = self::page_slug( $filename, $page );
			$template    = '' === $slug ? '' : 'templates/page-' . $slug . '.html';
			$pattern     = '' === $slug ? '' : 'patterns/page-' . $slug . '.php';
			$generated   = self::generated_visual_probe_markup( $writes, $theme_prefix, $pattern );

			self::$conversion_report['semantic_fidelity']['comparison_targets'][] = array(
				'source_file'           => $page->path(),
				'source_filename'       => $filename,
				'source_type'           => $page->type(),
				'wordpress_page_id'     => $page_ids[ $filename ] ?? null,
				'wordpress_url'         => $permalinks[ $filename ] ?? '',
				'generated_template'    => $template,
				'generated_pattern'     => $pattern,
				'generated_theme_parts' => $theme_parts,
				'generated_files'       => array_values( array_filter( array_merge( array( $template, $pattern ), $theme_parts ) ) ),
				'regions'               => self::semantic_regions( $source_html, $generated ),
				'semantic_selectors'    => self::semantic_selectors(),
				'comparison_hooks'      => array(
					'landmarks' => array( 'header', 'nav', 'main', 'footer', '[role=banner]', '[role=navigation]', '[role=main]', '[role=contentinfo]' ),
					'actions'   => array( 'a', 'button', '[role=button]', '.wp-block-button__link' ),
					'identity'  => array( '[class*=brand]', '[class*=logo]', '[class*=wordmark]', 'img[alt*=logo i]', 'svg[aria-label*=logo i]' ),
					'sections'  => array( '[class*=nav]', '[class*=footer]', '[class*=header]', '[class*=card]', '[class*=cta]', '[class*=status]' ),
				),
			);
		}
	}

	/**
	 * Generated theme parts that contribute shared page semantics.
	 *
	 * @param array<string, string> $writes       Generated files keyed by absolute path.
	 * @param string                $theme_prefix Absolute theme directory with trailing slash.
	 * @return array<int, string>
	 */
	private static function generated_semantic_theme_parts( array $writes, string $theme_prefix ): array {
		$parts = array();
		foreach ( array( 'parts/header.html', 'parts/footer.html' ) as $relative_path ) {
			if ( isset( $writes[ $theme_prefix . $relative_path ] ) ) {
				$parts[] = $relative_path;
			}
		}

		return $parts;
	}

	/**
	 * Broad semantic regions present in either source or generated markup.
	 *
	 * @param string $source_html Source HTML.
	 * @param string $generated   Generated block markup.
	 * @return array<int, string>
	 */
	private static function semantic_regions( string $source_html, string $generated ): array {
		$regions = array();
		$markup  = $source_html . "\n" . $generated;
		foreach ( array( 'header', 'nav', 'main', 'footer' ) as $region ) {
			if ( 'main' === $region || self::visual_probe_count( $markup, $region ) > 0 ) {
				$regions[] = $region;
			}
		}

		return $regions;
	}

	/**
	 * Broad selectors the benchmark harness should fingerprint for semantic drift.
	 *
	 * @return array<int, string>
	 */
	private static function semantic_selectors(): array {
		return array(
			'header',
			'nav',
			'main',
			'footer',
			'a',
			'button',
			'img',
			'svg',
			'[role=banner]',
			'[role=navigation]',
			'[role=main]',
			'[role=contentinfo]',
			'[role=button]',
			'[class*=brand]',
			'[class*=logo]',
			'[class*=wordmark]',
			'[class*=nav]',
			'[class*=footer]',
			'[class*=header]',
			'[class*=card]',
			'[class*=cta]',
			'[class*=status]',
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

		if ( null === $manifest && ! empty( self::$active_commerce_context['products'] ) && is_array( self::$active_commerce_context['products'] ) ) {
			$manifest = self::$active_commerce_context['products'];
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
	 * Commerce intent is detected when the import has either a validated
	 * products.json manifest or any commerce_context (caller-supplied,
	 * inferred from JSON-LD, or inferred from product cards) carrying at
	 * least one product. When intent is present, WooCommerce must be active
	 * or the caller must explicitly waive the requirement via
	 * allow_missing_woocommerce. Without intent, no commerce.dependencies
	 * shape is recorded and behavior is unchanged.
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
	 * Browser-level visual probes the benchmark harness should run for every target.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function visual_layout_probes(): array {
		return array(
			'nav_chrome'   => array(
				'selectors'  => array( 'nav', '.site-header', '.nav-shell', '.top-nav', '[class*=nav]' ),
				'assertions' => array( 'visible', 'bounding_box_nonzero', 'frontend_editor_box_parity', 'frontend_editor_display_parity' ),
			),
			'code_visual'  => array(
				'selectors'  => array( '.code-window', '.terminal', '[class*=code-window]', '[class*=terminal]', 'pre[class*=code]' ),
				'assertions' => array( 'visible', 'bounding_box_nonzero', 'frontend_editor_visibility_parity' ),
			),
			'problem_grid' => array(
				'selectors'            => array( '.problem-grid', '.problems-grid', '.problem-cards', '[class*=problem][class*=grid]', '[class*=problem][class*=cards]' ),
				'desktop_min_width_px' => 960,
				'min_columns'          => 2,
				'assertions'           => array( 'visible', 'children_same_row_desktop', 'frontend_editor_column_parity' ),
			),
			'hero_region'  => array(
				'selectors'  => array( '.hero', 'header', '[class*=hero]' ),
				'assertions' => array( 'visible', 'bounding_box_nonzero', 'source_frontend_editor_box_parity' ),
			),
		);
	}

	/**
	 * Read an HTML file for best-effort visual probe metadata.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function read_visual_probe_file( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local static-site source files for report-only visual probe metadata.
		$contents = is_readable( $path ) ? file_get_contents( $path ) : '';
		return false === $contents ? '' : $contents;
	}

	/**
	 * Collect generated markup relevant to one page's visual comparison.
	 *
	 * @param array<string, string> $writes       Generated files keyed by absolute path.
	 * @param string                $theme_prefix Absolute theme directory with trailing slash.
	 * @param string                $pattern      Theme-relative page pattern path.
	 * @return string
	 */
	private static function generated_visual_probe_markup( array $writes, string $theme_prefix, string $pattern ): string {
		$parts = array();
		foreach ( array( 'parts/header.html', $pattern, 'parts/footer.html' ) as $relative_path ) {
			if ( '' === $relative_path ) {
				continue;
			}

			$path = $theme_prefix . $relative_path;
			if ( isset( $writes[ $path ] ) ) {
				$parts[] = self::generated_block_document_markup( $relative_path, $writes[ $path ] );
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Count visual probe anchors that a browser-based harness should compare.
	 *
	 * @param string $html HTML or block markup.
	 * @return array<string, int>
	 */
	private static function visual_probe_counts( string $html ): array {
		return array(
			'hero_candidates'        => self::visual_probe_count( $html, 'hero' ),
			'button_candidates'      => self::visual_probe_count( $html, 'button' ),
			'nav_candidates'         => self::visual_probe_count( $html, 'nav' ),
			'footer_candidates'      => self::visual_probe_count( $html, 'footer' ),
			'code_visual_candidates' => self::visual_probe_count( $html, 'code_visual' ),
			'grid_candidates'        => self::visual_probe_count( $html, 'grid' ),
			'core_button_blocks'     => self::count_block_name_in_markup( $html, 'core/button' ),
		);
	}

	/**
	 * Count one visual probe family in markup.
	 *
	 * @param string $html  HTML or block markup.
	 * @param string $probe Probe family.
	 * @return int
	 */
	private static function visual_probe_count( string $html, string $probe ): int {
		if ( '' === trim( $html ) ) {
			return 0;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return 0;
		}

		$count = 0;
		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			if ( self::element_matches_visual_probe( $element, $probe ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Check whether one DOM element matches a visual probe family.
	 *
	 * @param DOMElement $element DOM element.
	 * @param string     $probe   Probe family.
	 * @return bool
	 */
	private static function element_matches_visual_probe( DOMElement $element, string $probe ): bool {
		$tag     = strtolower( $element->tagName );
		$classes = preg_split( '/\s+/', strtolower( trim( $element->getAttribute( 'class' ) ) ) );
		$classes = is_array( $classes ) ? array_filter( $classes ) : array();

		if ( 'hero' === $probe ) {
			return 'header' === $tag || self::class_tokens_contain_fragment( $classes, 'hero' );
		}

		if ( 'header' === $probe ) {
			return 'header' === $tag || self::class_tokens_contain_fragment( $classes, 'header' );
		}

		if ( 'main' === $probe ) {
			return 'main' === $tag || 'main' === strtolower( $element->getAttribute( 'role' ) ) || self::class_tokens_contain_fragment( $classes, 'main' );
		}

		if ( 'button' === $probe ) {
			return 'button' === $tag || 'button' === strtolower( $element->getAttribute( 'role' ) ) || self::class_tokens_contain_any_fragment( $classes, array( 'btn', 'button', 'cta', 'pill' ) );
		}

		if ( 'nav' === $probe ) {
			return 'nav' === $tag || self::class_tokens_contain_any_fragment( $classes, array( 'nav', 'navigation' ) );
		}

		if ( 'footer' === $probe ) {
			return 'footer' === $tag || self::class_tokens_contain_fragment( $classes, 'footer' );
		}

		if ( 'code_visual' === $probe ) {
			return self::class_tokens_contain_any_fragment( $classes, array( 'code-window', 'terminal' ) ) || ( 'pre' === $tag && self::class_tokens_contain_fragment( $classes, 'code' ) );
		}

		if ( 'grid' === $probe ) {
			return self::class_tokens_contain_any_fragment( $classes, array( 'grid', 'cards' ) );
		}

		return false;
	}

	/**
	 * Check whether class tokens contain any listed fragment.
	 *
	 * @param array<int, string> $classes   Class tokens.
	 * @param array<int, string> $fragments Fragments to match.
	 * @return bool
	 */
	private static function class_tokens_contain_any_fragment( array $classes, array $fragments ): bool {
		foreach ( $fragments as $fragment ) {
			if ( self::class_tokens_contain_fragment( $classes, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether class tokens contain a fragment.
	 *
	 * @param array<int, string> $classes  Class tokens.
	 * @param string             $fragment Fragment to match.
	 * @return bool
	 */
	private static function class_tokens_contain_fragment( array $classes, string $fragment ): bool {
		foreach ( $classes as $class ) {
			if ( str_contains( $class, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count one block type in serialized block markup.
	 *
	 * @param string $markup     Serialized block markup.
	 * @param string $block_name Block name.
	 * @return int
	 */
	private static function count_block_name_in_markup( string $markup, string $block_name ): int {
		if ( '' === trim( $markup ) || ! function_exists( 'parse_blocks' ) ) {
			return 0;
		}

		$count = 0;
		/** @var array<int, array<string, mixed>> $blocks */
		$blocks = parse_blocks( $markup );
		self::count_block_name_in_blocks( $blocks, $block_name, $count );

		return $count;
	}

	/**
	 * Recursively count one block type in parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks     Parsed block list.
	 * @param string                           $block_name Block name.
	 * @param int                              $count      Running count.
	 * @return void
	 */
	private static function count_block_name_in_blocks( array $blocks, string $block_name, int &$count ): void {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? null ) === $block_name ) {
				++$count;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::count_block_name_in_blocks( $block['innerBlocks'], $block_name, $count );
			}
		}
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
	 * Record the start of one conversion fragment.
	 *
	 * @param string $source Source fragment label.
	 * @param string $html   Source HTML.
	 * @return void
	 */
	private static function start_conversion_fragment( string $source, string $html ): void {
		self::$conversion_report['conversion_fragments'][ $source ] = array(
			'source'             => $source,
			'input_length'       => strlen( $html ),
			'input_text_length'  => strlen( wp_strip_all_tags( $html ) ),
			'output_length'      => 0,
			'fallback_count'     => 0,
			'content_loss_count' => 0,
			'empty_conversion'   => false,
		);
	}

	/**
	 * Record the end of one conversion fragment.
	 *
	 * @param string $source Source fragment label.
	 * @param string $blocks Converted block markup.
	 * @return void
	 */
	private static function finish_conversion_fragment( string $source, string $blocks ): void {
		if ( ! isset( self::$conversion_report['conversion_fragments'][ $source ] ) ) {
			return;
		}

		self::$conversion_report['conversion_fragments'][ $source ]['output_length']      = strlen( $blocks );
		self::$conversion_report['conversion_fragments'][ $source ]['output_text_length'] = strlen( wp_strip_all_tags( $blocks ) );
		self::record_button_wrapper_classes_from_blocks( $blocks );
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

		$entry = array(
			'type'                => $type,
			'source'              => $source,
			'selector'            => '' !== $selector ? $selector : null,
			'excerpt'             => self::diagnostic_excerpt( wp_strip_all_tags( $element_html ) ),
			'source_html_preview' => self::diagnostic_excerpt( $element_html ),
			'reason'              => isset( $context['reason'] ) ? (string) $context['reason'] : 'unknown',
			'tag_name'            => isset( $context['tag_name'] ) ? (string) $context['tag_name'] : self::diagnostic_tag_name_from_html( $element_html ),
			'block_name'          => isset( $block['blockName'] ) ? (string) $block['blockName'] : null,
			'converter'           => 'html-to-blocks-converter',
			'stage'               => isset( $context['stage'] ) ? (string) $context['stage'] : 'html_to_blocks',
			'html_length'         => strlen( $element_html ),
			'html_excerpt'        => self::diagnostic_excerpt( $element_html ),
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
	 * Record commerce-related metadata emitted during conversion.
	 *
	 * @param string               $source   Source fragment label.
	 * @param array<string, mixed> $metadata Conversion metadata.
	 * @return void
	 */
	private static function record_commerce_conversion_metadata( string $source, array $metadata ): void {
		if ( empty( self::$active_commerce_context ) || ! self::is_commerce_metadata( $metadata ) ) {
			return;
		}

		self::$conversion_report['commerce_context']['diagnostics'][] = array(
			'source'   => $source,
			'metadata' => $metadata,
		);
	}

	/**
	 * Determine whether conversion metadata is commerce-related.
	 *
	 * @param array<string, mixed> $metadata Conversion metadata.
	 * @return bool
	 */
	private static function is_commerce_metadata( array $metadata ): bool {
		$encoded  = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		$haystack = is_string( $encoded ) ? strtolower( $encoded ) : '';

		return str_contains( $haystack, 'commerce' ) || str_contains( $haystack, 'product' ) || str_contains( $haystack, 'woocommerce' );
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
		return $quality;
	}

	/**
	 * Normalize import diagnostics into a stable, machine-actionable shape.
	 *
	 * Existing human-readable fields are preserved for backwards compatibility.
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
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();

		return array(
			'status'                => ! empty( $quality['fail_import'] ) ? 'failed' : 'completed',
			'entry_file'            => isset( $report['entry_file'] ) ? (string) $report['entry_file'] : '',
			'quality_pass'          => ! empty( $quality['pass'] ),
			'fail_import'           => ! empty( $quality['fail_import'] ),
			'failure_reasons'       => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'fallback_count'        => (int) ( $quality['fallback_count'] ?? 0 ),
			'core_html_block_count' => (int) ( $quality['core_html_block_count'] ?? 0 ),
			'freeform_block_count'  => (int) ( $quality['freeform_block_count'] ?? 0 ),
			'invalid_block_count'   => (int) ( $quality['invalid_block_count'] ?? 0 ),
			'content_loss_count'    => (int) ( $quality['content_loss_count'] ?? 0 ),
			'diagnostic_count'      => count( $diagnostics ),
		);
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
	 * Ensure theme directories exist.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return true|WP_Error
	 */
	private static function ensure_dirs( string $theme_dir ) {
		foreach ( array( $theme_dir, $theme_dir . '/templates', $theme_dir . '/parts', $theme_dir . '/patterns', $theme_dir . '/assets', $theme_dir . '/assets/css', $theme_dir . '/assets/icons', $theme_dir . '/assets/media' ) as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'static_site_importer_mkdir_failed', sprintf( 'Failed to create directory: %s', $dir ) );
			}
		}

		return true;
	}

	/**
	 * Write a generated file.
	 *
	 * @param string $path    File path.
	 * @param string $content File content.
	 * @return true|WP_Error
	 */
	private static function write_file( string $path, string $content ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes generated block-theme files to the selected theme directory.
		$result = file_put_contents( $path, $content );
		if ( false === $result ) {
			return new WP_Error( 'static_site_importer_write_failed', sprintf( 'Failed to write file: %s', $path ) );
		}

		return true;
	}

	/**
	 * Write a copy of the import report to a caller-selected path.
	 *
	 * @param string $path    Report path.
	 * @param string $content Report JSON.
	 * @return true|WP_Error
	 */
	private static function write_external_report( string $path, string $content ) {
		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'static_site_importer_report_mkdir_failed', sprintf( 'Failed to create report directory: %s', $dir ) );
		}

		return self::write_file( $path, $content );
	}

	/**
	 * Determine whether a path resolves inside a base directory.
	 *
	 * @param string $path Path to test.
	 * @param string $base Base directory.
	 * @return bool
	 */
	private static function path_is_under( string $path, string $base ): bool {
		$real_path = realpath( $path );
		$real_base = realpath( $base );

		if ( false === $real_path || false === $real_base ) {
			return false;
		}

		return 0 === strpos( trailingslashit( $real_path ), trailingslashit( $real_base ) );
	}

	/**
	 * Delete the source static-site directory after a clean import.
	 *
	 * @param string $site_dir  Static site source directory.
	 * @param string $html_path Entry HTML file path.
	 * @return true|WP_Error
	 */
	private static function delete_source_dir( string $site_dir, string $html_path ) {
		$source_dir = realpath( $site_dir );
		$entry_file = realpath( $html_path );
		if ( false === $source_dir || false === $entry_file || ! is_dir( $source_dir ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_missing', 'Source directory is not available.' );
		}

		if ( 0 !== strpos( $entry_file, trailingslashit( $source_dir ) ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_mismatch', 'Entry HTML file is not inside the source directory.' );
		}

		$protected_dirs = array_filter(
			array_map(
				'realpath',
				array(
					ABSPATH,
					WP_CONTENT_DIR,
					get_theme_root(),
				)
			)
		);
		if ( DIRECTORY_SEPARATOR === $source_dir || in_array( $source_dir, $protected_dirs, true ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_protected', sprintf( 'Refusing to delete protected source directory: %s', $source_dir ) );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes caller-provided temporary static-site source directories after a clean import.
				if ( ! rmdir( $path ) ) {
					return new WP_Error( 'static_site_importer_source_cleanup_failed', sprintf( 'Failed to remove source directory: %s', $path ) );
				}
				continue;
			}

			if ( ! wp_delete_file( $path ) ) {
				return new WP_Error( 'static_site_importer_source_cleanup_failed', sprintf( 'Failed to remove source file: %s', $path ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes caller-provided temporary static-site source directories after a clean import.
		if ( ! rmdir( $source_dir ) ) {
			return new WP_Error( 'static_site_importer_source_cleanup_failed', sprintf( 'Failed to remove source directory: %s', $source_dir ) );
		}

		return true;
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
		$layout_gap_bridge          = self::imported_group_layout_gap_bridge_css();
		$css                        = self::scope_source_button_css( $css, $button_classes );

		return "/*\nTheme Name: " . $theme_name . "\nAuthor: Static Site Importer\nDescription: Imported from static HTML using Block Format Bridge.\nVersion: 0.1.0\nRequires at least: 6.6\n*/\n\n" . $css . "\n" . $button_bridge . $admin_bar_bridge . $source_nav_selector_bridge . $layout_gap_bridge;
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
		$layout_gap_bridge          = self::imported_group_layout_gap_bridge_css();
		$css                        = self::scope_source_button_css( $css, $button_classes );

		return "/*\nStatic Site Importer editor styles.\nGenerated separately from frontend style.css so editor wrapper repairs do not leak to public rendering.\n*/\n\n" . $css . "\n" . $button_bridge . $source_nav_selector_bridge . $layout_gap_bridge . $editor_bridge . $editor_reveal_bridge;
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
	 * Build compatibility rules for source anchor classes moved onto core/button wrappers.
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

			$selectors = array();
			foreach ( explode( ',', $prelude ) as $selector ) {
				$bridge_selector = self::button_style_bridge_selector( trim( $selector ), $button_classes );
				if ( null !== $bridge_selector ) {
					$selectors[] = $bridge_selector;
				}
			}

			if ( $selectors ) {
				$rules[] = implode( ', ', array_unique( $selectors ) ) . ' { ' . $body . ' }';
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
