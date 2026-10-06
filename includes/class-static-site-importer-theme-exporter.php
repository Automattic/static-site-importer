<?php
/**
 * Block theme exporter.
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
 * Exports WordPress block themes to website artifacts.
 */
class Static_Site_Importer_Theme_Exporter {

	/**
	 * Export an imported or active block theme as a website artifact.
	 *
	 * @param array $args Export args.
	 * @return array{website_artifact:array<string,mixed>}|WP_Error
	 */
	public static function export_theme( array $args = array() ) {
		if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
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
		$used_paths      = array();
		$post_artifact_paths = array();

		$stylesheet = self::export_theme_stylesheet_file( $theme_dir, $root );
		if ( null !== $stylesheet ) {
			$files[] = $stylesheet;
		}
		$global_stylesheet = self::export_global_stylesheet_file( $root );
		if ( null !== $global_stylesheet ) {
			$files[] = $global_stylesheet;
		}

		$pages      = self::export_pages( $include_pages );
		$post_count = 0;
		if ( empty( $pages ) ) {
			$diagnostics[]             = array(
				'level'   => 'warning',
				'code'    => 'static_site_importer_export_no_pages',
				'message' => 'No published pages were available to export; generated an entrypoint from theme templates only.',
			);
			$files[]                   = self::export_file_entry(
				$entrypoint,
				self::export_html_document( '', self::export_theme_chrome_html( $theme_dir, 'front-page' ), $theme_slug, null !== $stylesheet, null !== $global_stylesheet ),
				'document',
				'entrypoint'
			);
			$used_paths[ $entrypoint ] = true;
		} else {
			$front_page_id = self::export_front_page_id();
			$first         = true;
			$planned       = array();
			// Reserve every page route before assigning post routes so shared
			// slugs resolve identically regardless of get_posts() ordering: pages
			// keep the clean /root/<slug>/ path and a colliding post moves under
			// /root/post/<slug>/. WP guarantees unique slugs only within a type.
			$front_planned_id = 0;
			foreach ( $pages as $page ) {
				$page_id  = isset( $page->ID ) ? (int) $page->ID : 0;
				$is_front = $first || ( $front_page_id > 0 && $page_id === $front_page_id );
				$first    = false;
				if ( 'post' === ( $page->post_type ?? '' ) && ! $is_front ) {
					continue;
				}
				if ( $is_front ) {
					$front_planned_id = $page_id;
				}
				$path                = $is_front ? $entrypoint : self::export_page_artifact_path( $page, $root );
				$used_paths[ $path ] = true;
				$planned[]           = array(
					'page'     => $page,
					'path'     => $path,
					'is_front' => $is_front,
				);
			}
			foreach ( $pages as $page ) {
				if ( 'post' !== ( $page->post_type ?? '' ) || ( isset( $page->ID ) && (int) $page->ID === $front_planned_id ) ) {
					continue;
				}
				++$post_count;
				$path = self::export_page_artifact_path( $page, $root );
				if ( isset( $used_paths[ $path ] ) ) {
					$path = self::export_artifact_path( $root . '/post/' . ( isset( $page->post_name ) ? sanitize_title( (string) $page->post_name ) : (string) ( isset( $page->ID ) ? (int) $page->ID : 0 ) ) . '/index.html', $root . '/post/page/index.html' );
				}
				$used_paths[ $path ] = true;
				$post_artifact_paths[ (int) ( $page->ID ?? 0 ) ] = $path;
				$planned[]           = array(
					'page'     => $page,
					'path'     => $path,
					'is_front' => false,
				);
			}
			foreach ( $planned as $plan ) {
				$page      = $plan['page'];
				$path      = $plan['path'];
				$is_front  = $plan['is_front'];
				$page_id   = isset( $page->ID ) ? (int) $page->ID : 0;
				$template  = $is_front ? 'front-page' : 'page';
				$page_html = self::export_resolved_template_html( $page, $theme_slug, $is_front );
				$chrome    = '' === $page_html
					? self::export_theme_chrome_html( $theme_dir, $template )
					: array(
						'before' => '',
						'after'  => '',
					);
				if ( '' === $page_html ) {
					$page_html = self::blocks_to_html( isset( $page->post_content ) ? (string) $page->post_content : '' );
				}
				$site_origin = rtrim( home_url( '/' ), '/' );
				$page_html   = str_replace( $site_origin . '/', '/', $page_html );
				if ( 'post' === ( $page->post_type ?? '' ) ) {
					$target_route = '/' . trim( (string) preg_replace( '#/index\.html$#', '', substr( $path, strlen( $root ) ) ), '/' );
					$source_routes = array_merge(
						array( (string) wp_parse_url( get_permalink( $page ), PHP_URL_PATH ) ),
						function_exists( 'get_post_meta' ) ? array_map( 'strval', get_post_meta( $page_id, '_static_site_importer_source_route', false ) ) : array()
					);
					foreach ( array_unique( array_filter( $source_routes ) ) as $source_route ) {
						$page_html = str_replace( 'href="' . trailingslashit( $source_route ) . '"', 'href="' . trailingslashit( $target_route ) . '"', $page_html );
					}
				}

				$files[] = self::export_file_entry(
					$path,
					self::export_html_document( $page_html, $chrome, self::export_page_title( $page, $theme_slug ), null !== $stylesheet, null !== $global_stylesheet ),
					'document',
					$is_front ? 'entrypoint' : 'page',
					array(
						'post_id'   => $page_id,
						'post_name' => isset( $page->post_name ) ? (string) $page->post_name : '',
						'metadata'  => 'post' === ( $page->post_type ?? '' ) ? array(
							'post_type' => 'post',
							'route_path' => '/' . trim( (string) preg_replace( '#/index\.html$#', '', substr( $path, strlen( $root ) ) ), '/' ),
						) : array(),
					)
				);
			}
		}

		$taxonomy_archive_files = self::export_taxonomy_archive_files( $theme_slug, $root, $used_paths, $post_artifact_paths, null !== $global_stylesheet, $diagnostics );
		$files                  = array_merge( $files, $taxonomy_archive_files );
		$taxonomy_archive_count = count( array_unique( array_map( static fn( array $file ): string => (string) ( $file['taxonomy'] ?? '' ) . ':' . (string) ( $file['term_id'] ?? '' ), $taxonomy_archive_files ) ) );

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
			'status'                      => 'completed',
			'theme_slug'                  => $theme_slug,
			'theme_dir'                   => $theme_dir,
			'root'                        => $root,
			'entrypoint'                  => $entrypoint,
			'file_count'                  => count( $files ),
			'page_count'                  => count( $pages ) - $post_count, // pages keep the page_count contract; posts are reported separately
			'post_count'                  => $post_count,
			'taxonomy_archive_count'      => $taxonomy_archive_count,
			'taxonomy_archive_page_count' => count( $taxonomy_archive_files ),
			'source_metadata'             => $source_metadata,
			'diagnostics'                 => $diagnostics,
		);
		if ( ! empty( $import_report ) ) {
			$report['import_report'] = $import_report;
		}

		$website_artifact = self::export_website_artifact( $theme_slug, $root, $entrypoint, $files, $report, $source_metadata );

		return array(
			'website_artifact' => $website_artifact,
		);
	}

	/** Export current native taxonomy archives through the active theme's block templates. */
	private static function export_taxonomy_archive_files( string $theme_slug, string $root, array $used_paths, array $post_artifact_paths, bool $include_global_styles, array &$diagnostics ): array {
		if ( ! function_exists( 'get_terms' ) || ! function_exists( 'get_term_link' ) || ! function_exists( 'get_block_template' ) || ! function_exists( 'do_blocks' ) ) {
			return array();
		}

		$files = array();
		global $wp;
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);
			if ( is_wp_error( $terms ) ) {
				$diagnostics[] = array(
					'level'    => 'warning',
					'code'     => 'static_site_importer_export_taxonomy_query_failed',
					'taxonomy' => $taxonomy,
				);
				continue;
			}

			foreach ( $terms as $term ) {
				$term_url = get_term_link( $term );
				if ( is_wp_error( $term_url ) ) {
					continue;
				}
				$route = wp_parse_url( (string) $term_url, PHP_URL_PATH );
				if ( ! is_string( $route ) || '' === trim( $route, '/' ) ) {
					continue;
				}
				$path = self::export_artifact_path( $root . '/' . trim( $route, '/' ) . '/index.html', '' );
				if ( '' === $path || isset( $used_paths[ $path ] ) ) {
					$diagnostics[] = array(
						'level'    => 'warning',
						'code'     => 'static_site_importer_export_taxonomy_route_conflict',
						'taxonomy' => $taxonomy,
						'term_id'  => (int) $term->term_id,
						'path'     => $path,
					);
					continue;
				}

				$template_slug = ( 'category' === $taxonomy ? 'category-' : 'tag-' ) . sanitize_title( (string) $term->slug );
				$template      = get_block_template( $theme_slug . '//' . $template_slug );
				if ( ! $template instanceof WP_Block_Template ) {
					continue;
				}

				$query_args = array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => max( 1, (int) get_option( 'posts_per_page', 10 ) ),
					'paged'          => 1,
				);
				if ( 'category' === $taxonomy ) {
					$query_args['category_name'] = (string) $term->slug;
				} else {
					$query_args['tag'] = (string) $term->slug;
				}
				$max_pages = max( 1, (int) ( new WP_Query( $query_args ) )->max_num_pages );
				for ( $page_number = 1; $page_number <= $max_pages; ++$page_number ) {
					$query_args['paged'] = $page_number;
					$previous_query      = $GLOBALS['wp_query'] ?? null;
					$previous_post       = $GLOBALS['post'] ?? null;
					$previous_request    = (string) $wp->request;
					// The inherited Query Loop reads the archive query and path from WordPress's normal globals.
					// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The exporter renders the native archive template against its real query context.
					$archive_query      = new WP_Query( $query_args );
					$GLOBALS['wp_query'] = $archive_query;
					$GLOBALS['post']     = null;
					$wp->request         = trim( $route, '/' );
					try {
						$html = do_blocks( $template->content );
					} finally {
						$GLOBALS['wp_query'] = $previous_query;
						$GLOBALS['post']     = $previous_post;
						$wp->request         = $previous_request;
					}
					// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
					if ( '' === trim( $html ) ) {
						continue;
					}

					$page_route = 1 === $page_number ? $route : trailingslashit( $route ) . 'page/' . $page_number;
					$page_path  = self::export_artifact_path( $root . '/' . trim( $page_route, '/' ) . '/index.html', '' );
					if ( '' === $page_path || isset( $used_paths[ $page_path ] ) ) {
						$diagnostics[] = array(
							'level'        => 'warning',
							'code'         => 'static_site_importer_export_taxonomy_route_conflict',
							'taxonomy'     => $taxonomy,
							'term_id'      => (int) $term->term_id,
							'source_route' => $page_route,
							'path'         => $page_path,
						);
						continue;
					}
					$used_paths[ $page_path ] = true;
					$site_origin              = untrailingslashit( home_url( '/' ) );
					$html                     = str_replace( $site_origin . '/', '/', $html );
					$html                     = preg_replace( '#href="/page/([0-9]+)/"#', 'href="' . trailingslashit( $route ) . 'page/$1/"', $html ) ?? $html;
					$html                     = preg_replace( '#href="' . preg_quote( $site_origin, '#' ) . '/page/([0-9]+)/"#', 'href="' . trailingslashit( $route ) . 'page/$1/"', $html ) ?? $html;
					if ( $page_number > 1 ) {
						$previous_route = 2 === $page_number ? trailingslashit( $route ) : trailingslashit( $route ) . 'page/' . ( $page_number - 1 ) . '/';
						$html           = preg_replace( '#href="/page/([0-9]+)/"#', 'href="' . $previous_route . '"', $html ) ?? $html;
						if ( ! str_contains( $html, 'wp-block-query-pagination-previous' ) ) {
							$html = preg_replace( '#(<nav\b[^>]*class="[^"]*wp-block-query-pagination[^"]*"[^>]*>)#', '$1<a href="' . esc_url( $previous_route ) . '" class="wp-block-query-pagination-previous">Previous Page</a>', $html, 1 ) ?? $html;
						}
					}
					if ( $page_number >= $max_pages ) {
						$html = preg_replace( '#<a href="[^"]+" class="wp-block-query-pagination-next">.*?</a>#s', '', $html ) ?? $html;
					}
					$html = preg_replace_callback(
						'#(?:href|src)="/([^\"]+)"#',
						static function ( array $match ) use ( $page_route ): string {
							$prefix = str_repeat( '../', substr_count( trim( $page_route, '/' ), '/' ) + 1 );
							return str_replace( '="/', '="' . $prefix, $match[0] );
						},
						$html
					) ?? $html;
					foreach ( $archive_query->posts ?? array() as $archive_post ) {
						if ( ! $archive_post instanceof WP_Post || 'post' !== $archive_post->post_type ) {
							continue;
						}
						$artifact_post_path = (string) ( $post_artifact_paths[ (int) $archive_post->ID ] ?? self::export_page_artifact_path( $archive_post, $root ) );
						$target_route = preg_replace( '#/index\.html$#', '', substr( $artifact_post_path, strlen( $root ) ) );
						$portable_href = '/' . trim( (string) $target_route, '/' ) . '/';
						$escaped_href  = function_exists( 'esc_attr' ) ? esc_attr( $portable_href ) : htmlspecialchars( $portable_href, ENT_QUOTES, 'UTF-8' );
						$source_routes = array_merge(
							array( (string) wp_parse_url( get_permalink( $archive_post ), PHP_URL_PATH ) ),
							function_exists( 'get_post_meta' ) ? array_map( 'strval', get_post_meta( (int) $archive_post->ID, '_static_site_importer_source_route', false ) ) : array()
						);
						foreach ( array_unique( array_filter( $source_routes ) ) as $source_post_route ) {
							$source_relative = str_repeat( '../', substr_count( trim( $page_route, '/' ), '/' ) + 1 ) . ltrim( $source_post_route, '/' );
							$html            = str_replace( 'href="' . rtrim( home_url( $source_post_route ), '/' ) . '"', 'href="' . $escaped_href . '"', $html );
							$html            = str_replace( 'href="' . trailingslashit( $source_post_route ) . '"', 'href="' . $escaped_href . '"', $html );
							$html            = str_replace( 'href="' . $source_relative . '"', 'href="' . $escaped_href . '"', $html );
						}
					}
					$files[] = self::export_file_entry(
						$page_path,
						self::export_html_document(
							$html,
							array(
								'before' => '',
								'after'  => '',
							),
							(string) $term->name,
							true,
							$include_global_styles,
							str_repeat( '../', substr_count( trim( $page_route, '/' ), '/' ) + 1 )
						),
						'document',
						'taxonomy-archive',
						array(
							'taxonomy'     => $taxonomy,
							'term_id'      => (int) $term->term_id,
							'source_route' => $page_route,
							'page'         => $page_number,
						)
					);
				}
			}
		}

		return $files;
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
				'post_type'      => array( 'page', 'post' ),
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
		return self::order_front_page_first(
			array_values(
				array_filter(
					$pages,
					static function ( $page ) use ( $allowed ): bool {
						$page_id   = (string) $page->ID;
						$page_slug = (string) $page->post_name;
						return isset( $allowed[ $page_id ] ) || isset( $allowed[ $page_slug ] );
					}
				)
			)
		);
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
				'post_type'      => array( 'page', 'post' ),
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
	private static function export_theme_chrome_html( string $theme_dir, string $template ): array {
		$before = self::convert_theme_block_file_to_html( $theme_dir . '/parts/header.html' );
		$after  = self::convert_theme_block_file_to_html( $theme_dir . '/parts/footer.html' );

		$template_html = self::read_file_if_readable( $theme_dir . '/templates/' . $template . '.html' );
		if ( '' === $template_html && 'front-page' !== $template ) {
			$template_html = self::read_file_if_readable( $theme_dir . '/templates/index.html' );
		}

		if ( '' !== $template_html ) {
			$converted_template = self::blocks_to_html( $template_html );
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
	 * Render the template WordPress resolves for a document.
	 *
	 * get_block_template() and resolve_block_template() apply WordPress's normal
	 * database-over-theme source precedence. do_blocks() then expands nested
	 * template parts through core's template-part renderer.
	 *
	 * @param object $page       Document being exported.
	 * @param string $theme_slug Active theme stylesheet.
	 * @param bool   $is_front   Whether this is the front page.
	 * @return string
	 */
	private static function export_resolved_template_html( object $page, string $theme_slug, bool $is_front ): string {
		if ( ! function_exists( 'get_block_template' ) || ! function_exists( 'do_blocks' ) ) {
			return '';
		}

		$template = null;
		$slug     = '';
		if ( function_exists( 'get_page_template_slug' ) && isset( $page->ID ) ) {
			$slug = (string) get_page_template_slug( (int) $page->ID );
		}
		if ( '' !== $slug && 'default' !== $slug ) {
			$template = get_block_template( $theme_slug . '//' . $slug );
		}

		if ( null === $template && function_exists( 'resolve_block_template' ) ) {
			$hierarchy = self::export_template_hierarchy( $page, $is_front );
			$template  = resolve_block_template( $hierarchy[0], $hierarchy, '' );
		}
		if ( null === $template || '' === $template->content ) {
			return '';
		}

		global $post;
		$previous_post = $post ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Dynamic block rendering reads the exported document from the global post context.
		$post = $page;
		if ( function_exists( 'setup_postdata' ) ) {
			setup_postdata( $page );
		}
		$html = do_blocks( (string) $template->content );
		if ( function_exists( 'wp_reset_postdata' ) ) {
			wp_reset_postdata();
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the caller's global post after rendering the exported document.
		$post = $previous_post;

		return $html;
	}

	/**
	 * Build the standard block-template candidates for an exported singular post.
	 *
	 * @param object $page     Document being exported.
	 * @param bool   $is_front Whether this is the front page.
	 * @return array<int,string>
	 */
	private static function export_template_hierarchy( object $page, bool $is_front ): array {
		$slug      = isset( $page->post_name ) ? sanitize_title( (string) $page->post_name ) : '';
		$id        = isset( $page->ID ) ? (int) $page->ID : 0;
		$post_type = isset( $page->post_type ) ? sanitize_key( (string) $page->post_type ) : 'page';
		$hierarchy = $is_front ? array( 'front-page', 'home' ) : array();
		if ( 'page' === $post_type ) {
			if ( '' !== $slug ) {
				$hierarchy[] = 'page-' . $slug;
			}
			if ( $id > 0 ) {
				$hierarchy[] = 'page-' . $id;
			}
			$hierarchy[] = 'page';
		} else {
			$hierarchy[] = 'single-' . $post_type;
			$hierarchy[] = 'single';
		}
		$hierarchy[] = 'singular';
		$hierarchy[] = 'index';

		return array_values( array_unique( $hierarchy ) );
	}

	/**
	 * Convert a block markup file to HTML.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function convert_theme_block_file_to_html( string $path ): string {
		$content = self::read_file_if_readable( $path );
		return '' === $content ? '' : self::blocks_to_html( $content );
	}

	/**
	 * Render serialized block markup with Blocks Engine's native format bridge.
	 *
	 * @param string $block_markup Serialized blocks.
	 * @return string
	 */
	private static function blocks_to_html( string $block_markup ): string {
		$result = blocks_engine_php_transformer_convert_format( $block_markup, 'blocks', 'html' );
		return isset( $result['documents'][0]['content'] ) && is_scalar( $result['documents'][0]['content'] ) ? (string) $result['documents'][0]['content'] : '';
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
	 * @param string                            $page_html       Converted page body HTML.
	 * @param array{before:string,after:string} $chrome          Converted theme chrome.
	 * @param string                            $title           Document title.
	 * @param bool                              $include_styles  Whether to link exported CSS.
	 * @return string
	 */
	private static function export_html_document( string $page_html, array $chrome, string $title, bool $include_styles, bool $include_global_styles = false, string $asset_prefix = '' ): string {
		$head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		if ( $include_styles ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This method emits standalone static HTML, not a WordPress-rendered page.
			$head .= '<link rel="stylesheet" href="' . htmlspecialchars( $asset_prefix, ENT_QUOTES, 'UTF-8' ) . 'style.css">';
		}
		if ( $include_global_styles ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This method emits standalone static HTML, not a WordPress-rendered page.
			$head .= '<link rel="stylesheet" href="' . htmlspecialchars( $asset_prefix, ENT_QUOTES, 'UTF-8' ) . 'global-styles.css">';
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
	 * @param string                         $theme_slug      Theme slug.
	 * @param string                         $root            Artifact root.
	 * @param string                         $entrypoint      Entrypoint path.
	 * @param array<int,array<string,mixed>> $files Exported files.
	 * @param array<string,mixed>            $report          Export report.
	 * @param array<string,mixed>            $source_metadata Source metadata.
	 * @return array<string,mixed>
	 */
	private static function export_website_artifact( string $theme_slug, string $root, string $entrypoint, array $files, array $report, array $source_metadata ): array {
		$generated_at = self::export_generated_at();
		$id           = 'website-artifact-' . $theme_slug . '-' . substr( hash( 'sha256', self::json_encode_pretty( array( $entrypoint, $files ) ) ), 0, 12 );

		return array(
			'schema'        => 'blocks-engine/php-transformer/site-artifact/v1',
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
	 * Export the merged theme.json, style variation, and user Global Styles CSS.
	 *
	 * @param string $root Artifact root.
	 * @return array<string,mixed>|null
	 */
	private static function export_global_stylesheet_file( string $root ): ?array {
		if ( ! function_exists( 'wp_get_global_stylesheet' ) ) {
			return null;
		}

		$stylesheet = wp_get_global_stylesheet();
		if ( '' === trim( $stylesheet ) ) {
			return null;
		}

		return self::export_file_entry( $root . '/global-styles.css', $stylesheet, 'asset', 'stylesheet' );
	}

	/**
	 * Export browser assets that can be replayed with the website artifact.
	 *
	 * @param string                         $theme_dir   Theme directory.
	 * @param string                         $root        Artifact root.
	 * @param array<int,array<string,mixed>> $diagnostics Export diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	private static function export_theme_asset_files( string $theme_dir, string $root, array &$diagnostics ): array {
		$files   = array();
		$preview = self::read_file_if_readable( $theme_dir . '/screenshot.png' );
		if ( '' !== $preview ) {
			$files[] = self::export_file_entry( $root . '/site-preview.png', $preview, 'asset', 'preview' );
		}
		$assets_dir = $theme_dir . '/assets';
		if ( ! is_dir( $assets_dir ) ) {
			return $files;
		}

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
		$path = Static_Site_Importer_Site_Identity::normalize_route_path( $path );
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
		$root = Static_Site_Importer_Site_Identity::normalize_route_path( $root );
		if ( '' !== $root && ! str_contains( $root, '/' ) ) {
			return $root;
		}

		$parts = explode( '/', $entrypoint );
		return '' !== $parts[0] ? $parts[0] : 'website';
	}

	/**
	 * Build a page artifact path.
	 *
	 * @param object $page Page object.
	 * @return string
	 */
	private static function export_page_artifact_path( object $page, string $root ): string {
		$slug = isset( $page->post_name ) && '' !== trim( (string) $page->post_name ) ? sanitize_title( (string) $page->post_name ) : 'page-' . ( isset( $page->ID ) ? (int) $page->ID : uniqid() );
		if ( 'page' === ( $page->post_type ?? '' ) && function_exists( 'get_page_uri' ) ) {
			$page_uri = get_page_uri( $page );
			if ( is_string( $page_uri ) && '' !== trim( $page_uri ) ) {
				$slug = $page_uri;
			}
		}
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
	 * @param string                         $path  Artifact path.
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
