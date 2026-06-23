<?php
/**
 * Block theme exporter.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Transformer_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-transformer-adapter.php';
}

/**
 * Exports WordPress block themes to Blocks Engine website artifacts.
 */
class Static_Site_Importer_Theme_Exporter {

	/**
	 * Export an imported or active block theme as a website artifact.
	 *
	 * @param array $args Export args.
	 * @return array{website_artifact:array<string,mixed>}|WP_Error
	 */
	public static function export_theme( array $args = array() ) {
		$transformer = new Static_Site_Importer_Transformer_Adapter();
		if ( ! $transformer->supports_blocks_to_html() ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to export a website artifact.' );
		}

		$theme_slug = isset( $args['theme_slug'] ) && '' !== trim( (string) $args['theme_slug'] ) ? sanitize_title( (string) $args['theme_slug'] ) : self::active_theme_slug();
		if ( '' === $theme_slug ) {
			return new WP_Error( 'static_site_importer_missing_theme_slug', 'A theme_slug input is required when no active theme can be detected.' );
		}

		$theme_dir = self::export_theme_dir( $theme_slug );
		if ( '' === $theme_dir || ! is_dir( $theme_dir ) ) {
			return new WP_Error( 'static_site_importer_theme_not_found', sprintf( 'Theme directory not found for %s.', $theme_slug ) );
		}

		$entrypoint      = self::export_artifact_path( isset( $args['entrypoint'] ) ? (string) $args['entrypoint'] : 'website/index.html', 'website/index.html' );
		$root            = self::export_artifact_root( isset( $args['root'] ) ? (string) $args['root'] : '', $entrypoint );
		$include_pages   = $args['include_pages'] ?? true;
		$source_metadata = isset( $args['source_metadata'] ) && is_array( $args['source_metadata'] ) ? $args['source_metadata'] : array();
		$diagnostics     = array();
		$files           = array();

		$stylesheet = self::export_theme_stylesheet_file( $theme_dir, $root );
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
			$files[]       = self::export_file_entry(
				$entrypoint,
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page', $transformer ), $theme_slug, null !== $stylesheet ),
				'document',
				'entrypoint'
			);
		} else {
			$front_page_id = self::export_front_page_id();
			$first         = true;
			foreach ( $pages as $page ) {
				$page_id   = isset( $page->ID ) ? (int) $page->ID : 0;
				$is_front  = $first || ( $front_page_id > 0 && $page_id === $front_page_id );
				$path      = $is_front ? $entrypoint : self::export_page_artifact_path( $page, $root );
				$template  = $is_front ? 'front-page' : 'page';
				$page_html = $transformer->blocks_to_html( isset( $page->post_content ) ? (string) $page->post_content : '' );

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, self::export_theme_chrome_html( $theme_dir, $template, $transformer ), self::export_page_title( $page, $theme_slug ), null !== $stylesheet ),
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

		$files = array_merge( $files, self::export_theme_asset_files( $theme_dir, $root, $diagnostics ) );

		$import_report = self::read_theme_import_report( $theme_dir );
		if ( ! empty( $import_report ) ) {
			$files[] = self::export_file_entry(
				$root . '/import-report.json',
				self::json_encode_pretty( $import_report ),
				'metadata',
				'report',
				array(
					'source' => array(
						'type' => 'static-site-importer-import-report',
					),
				)
			);

			$source_documents = isset( $import_report['source_documents'] ) && is_array( $import_report['source_documents'] ) ? $import_report['source_documents'] : array();
			if ( ! empty( $source_documents ) ) {
				$files[] = self::export_file_entry(
					$root . '/source-documents.json',
					self::json_encode_pretty( $source_documents ),
					'metadata',
					'source-document',
					array(
						'source' => array(
							'type' => 'static-site-importer-source-documents',
						),
					)
				);
			}
		}

		$report = array(
			'status'          => 'completed',
			'theme_slug'      => $theme_slug,
			'theme_dir'       => $theme_dir,
			'root'            => $root,
			'entrypoint'      => $entrypoint,
			'file_count'      => count( $files ),
			'page_count'      => count( $pages ),
			'source_metadata' => $source_metadata,
			'diagnostics'     => $diagnostics,
		);
		if ( ! empty( $import_report ) ) {
			$report['import_report'] = $import_report;
		}

		$website_artifact = self::export_website_artifact( $theme_slug, $root, $entrypoint, $files, $report, $source_metadata );

		return array(
			'website_artifact' => $website_artifact,
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
			if ( $theme->exists() ) {
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
		if ( ! is_array( $include_pages ) || empty( $include_pages ) ) {
			return self::order_front_page_first( array_values( $pages ) );
		}

		$allowed = array_fill_keys( array_map( 'strval', $include_pages ), true );
		return self::order_front_page_first( array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $allowed ): bool {
					$page_id   = (string) $page->ID;
					$page_slug = (string) $page->post_name;
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

		return $pages[0] ?? null;
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
	private static function export_theme_chrome_html( string $theme_dir, string $template, Static_Site_Importer_Transformer_Adapter $transformer ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html', $transformer );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html', $transformer );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html ) {
			$converted_template = $transformer->blocks_to_html( $template_html );
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
	private static function convert_theme_block_file_to_html( string $path, Static_Site_Importer_Transformer_Adapter $transformer ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content ? '' : $transformer->blocks_to_html( $content );
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
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This method emits standalone static HTML, not a WordPress-rendered page.
			$head .= '<link rel="stylesheet" href="style.css">';
		}

		return '<!doctype html>' . "\n"
			. '<html><head>' . $head . '<title>' . esc_html( $title ) . '</title></head><body>' . "\n"
			. trim( $chrome['before'] . "\n" . $page_html . "\n" . $chrome['after'] ) . "\n"
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
		$encoding = self::is_binary_content( $content ) ? 'base64' : 'utf8';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact files are explicitly represented as base64 for transport.
		$body  = 'base64' === $encoding ? base64_encode( $content ) : $content;
		$entry = array(
			'path'      => $path,
			'content'   => $body,
			'kind'      => $kind,
			'role'      => $role,
			'mime_type' => self::export_mime_type( $path ),
			'encoding'  => $encoding,
			'bytes'     => strlen( $content ),
			'sha256'    => hash( 'sha256', $content ),
		);
		if ( ! empty( $diagnostics ) ) {
			$entry = array_merge( $entry, $diagnostics );
		}

		return $entry;
	}

	/**
	 * Build the website artifact envelope consumed by Blocks Engine.
	 *
	 * @param string              $theme_slug      Theme slug.
	 * @param string              $root            Artifact root.
	 * @param string              $entrypoint      Entrypoint path.
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param array<string,mixed> $report          Export report.
	 * @param array<string,mixed> $source_metadata Source metadata.
	 * @return array<string,mixed>
	 */
	private static function export_website_artifact( string $theme_slug, string $root, string $entrypoint, array $files, array $report, array $source_metadata ): array {
		$generated_at = self::export_generated_at();
		$id           = 'website-artifact-' . $theme_slug . '-' . substr( hash( 'sha256', self::json_encode_pretty( array( $entrypoint, $files ) ) ), 0, 12 );

		return array(
			'schema'        => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
			'artifact_type' => 'website',
			'version'       => 1,
			'id'            => $id,
			'generated_at'  => $generated_at,
			'theme_slug'    => $theme_slug,
			'root'          => $root,
			'entrypoint'    => $entrypoint,
			'files'         => $files,
			'report'        => $report,
			'reports'       => self::export_report_refs( $files ),
			'import'        => array(
				'status'      => empty( $report['diagnostics'] ) ? 'passed' : 'warning',
				'theme_slug'  => $theme_slug,
				'source_path' => $entrypoint,
				'warnings'    => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'warning' ),
				'errors'      => self::export_diagnostic_messages( $report['diagnostics'] ?? array(), 'error' ),
			),
			'validation'    => array(
				'status'     => self::export_validation_status( $report['diagnostics'] ?? array() ),
				'checked_at' => $generated_at,
				'checks'     => array(
					array(
						'name'    => 'entrypoint-present',
						'status'  => self::export_has_file( $files, $entrypoint ) ? 'passed' : 'failed',
						'message' => 'The website artifact entrypoint is present in the exported file set.',
					),
				),
			),
			'provenance'    => array(
				'producer'          => 'static-site-importer',
				'source_metadata'   => $source_metadata,
				'materialized_from' => array(
					'type'       => 'wordpress-block-theme',
					'theme_slug' => $theme_slug,
				),
			),
		);
	}

	/**
	 * Export the theme stylesheet when present.
	 *
	 * @param string $theme_dir Theme directory.
	 * @return array<string,mixed>|null
	 */
	private static function export_theme_stylesheet_file( string $theme_dir, string $root ): ?array {
		$content = self::read_file_if_readable( $theme_dir . '/style.css' );
		if ( '' === $content ) {
			return null;
		}

		return self::export_file_entry( $root . '/style.css', $content, 'asset', 'stylesheet' );
	}

	/**
	 * Export browser assets that can be replayed with the website artifact.
	 *
	 * @param string                    $theme_dir   Theme directory.
	 * @param string                    $root        Artifact root.
	 * @param array<int,array<string,mixed>> $diagnostics Export diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function export_theme_asset_files( string $theme_dir, string $root, array &$diagnostics ): array {
		$assets_dir = $theme_dir . '/assets';
		if ( ! is_dir( $assets_dir ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $assets_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $item ) {
			if ( ! $item instanceof SplFileInfo || ! $item->isFile() || ! $item->isReadable() ) {
				continue;
			}

			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $assets_dir ) ) ), '/' );
			$path     = self::export_artifact_path( $root . '/assets/' . $relative, '' );
			if ( '' === $path || ! self::export_is_supported_asset_path( $path ) ) {
				$diagnostics[] = array(
					'level'   => 'warning',
					'code'    => 'static_site_importer_export_asset_skipped',
					'message' => 'A theme asset was skipped because its path or type is not supported for static export.',
					'path'    => $relative,
				);
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local generated theme artifacts for export.
			$content = file_get_contents( $item->getPathname() );
			if ( false === $content ) {
				continue;
			}

			$files[] = self::export_file_entry( $path, (string) $content, self::export_kind_from_path( $path ), self::export_role_from_path( $path ) );
		}

		usort(
			$files,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['path'] ?? '' ), (string) ( $right['path'] ?? '' ) );
			}
		);

		return $files;
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
	 * Resolve the artifact root from input or entrypoint.
	 *
	 * @param string $root       Requested root.
	 * @param string $entrypoint Entrypoint path.
	 * @return string
	 */
	private static function export_artifact_root( string $root, string $entrypoint ): string {
		$root = self::normalize_route_path( $root );
		if ( '' !== $root && ! str_contains( $root, '/' ) ) {
			return $root;
		}

		$parts = explode( '/', $entrypoint );
		return '' !== $parts[0] ? $parts[0] : 'website';
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
	 * Build a page artifact path.
	 *
	 * @param object $page Page object.
	 * @return string
	 */
	private static function export_page_artifact_path( object $page, string $root ): string {
		$slug = isset( $page->post_name ) && '' !== trim( (string) $page->post_name ) ? sanitize_title( (string) $page->post_name ) : 'page-' . ( isset( $page->ID ) ? (int) $page->ID : uniqid() );
		return self::export_artifact_path( $root . '/' . $slug . '/index.html', $root . '/page/index.html' );
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
	 * Resolve a static export MIME type from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_mime_type( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'text/html',
			'css'         => 'text/css',
			'js', 'mjs'    => 'text/javascript',
			'json'        => 'application/json',
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg', 'jpeg'  => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			default       => 'application/octet-stream',
		};
	}

	/**
	 * Infer an exported file kind from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_kind_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm' => 'document',
			'css'         => 'asset',
			'js', 'mjs'    => 'asset',
			'json'        => 'metadata',
			default       => 'asset',
		};
	}

	/**
	 * Infer a static artifact file role from path.
	 *
	 * @param string $path Artifact path.
	 * @return string
	 */
	private static function export_role_from_path( string $path ): string {
		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'css'        => 'stylesheet',
			'js', 'mjs'   => 'script',
			'json'       => 'metadata',
			default      => 'asset',
		};
	}

	/**
	 * Check whether an asset path is supported for static export.
	 *
	 * @param string $path Artifact path.
	 * @return bool
	 */
	private static function export_is_supported_asset_path( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'woff', 'woff2' ), true );
	}

	/**
	 * Detect binary content that should be inlined as base64.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	private static function is_binary_content( string $content ): bool {
		return str_contains( $content, "\0" ) || ! preg_match( '//u', $content );
	}

	/**
	 * JSON encode with stable options and a PHP fallback for smoke tests.
	 *
	 * @param mixed $data Data to encode.
	 * @return string
	 */
	private static function json_encode_pretty( mixed $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Smoke tests load this class without WordPress helpers.
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded . "\n" : "{}\n";
	}

	/**
	 * Return the export timestamp.
	 *
	 * @return string
	 */
	private static function export_generated_at(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Build report file references from exported files.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @return array<int,array<string,string>>
	 */
	private static function export_report_refs( array $files ): array {
		$refs = array();
		foreach ( $files as $file ) {
			$role = (string) ( $file['role'] ?? '' );
			if ( in_array( $role, array( 'report', 'source-document' ), true ) ) {
				$refs[] = array(
					'role' => $role,
					'path' => (string) ( $file['path'] ?? '' ),
				);
			}
		}

		return $refs;
	}

	/**
	 * Extract diagnostic messages by level/severity.
	 *
	 * @param mixed  $diagnostics Diagnostics.
	 * @param string $level       Level to collect.
	 * @return array<int,string>
	 */
	private static function export_diagnostic_messages( mixed $diagnostics, string $level ): array {
		if ( ! is_array( $diagnostics ) ) {
			return array();
		}

		$messages = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$diagnostic_level = (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) );
			if ( $level === $diagnostic_level ) {
				$messages[] = (string) ( $diagnostic['message'] ?? ( $diagnostic['code'] ?? '' ) );
			}
		}

		return array_values( array_filter( $messages ) );
	}

	/**
	 * Resolve validation status from diagnostics.
	 *
	 * @param mixed $diagnostics Diagnostics.
	 * @return string
	 */
	private static function export_validation_status( mixed $diagnostics ): string {
		if ( ! is_array( $diagnostics ) ) {
			return 'passed';
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && 'error' === (string) ( $diagnostic['level'] ?? ( $diagnostic['severity'] ?? '' ) ) ) {
				return 'failed';
			}
		}

		return empty( $diagnostics ) ? 'passed' : 'warning';
	}

	/**
	 * Check whether a file path exists in the export set.
	 *
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param string                        $path  Artifact path.
	 * @return bool
	 */
	private static function export_has_file( array $files, string $path ): bool {
		foreach ( $files as $file ) {
			if ( (string) ( $file['path'] ?? '' ) === $path ) {
				return true;
			}
		}

		return false;
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
}
