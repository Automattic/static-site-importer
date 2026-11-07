<?php
/**
 * Measured, non-gating evidence from the materialized block theme.
 *
 * @package Static_Site_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only measurements of theme structure and content. */
final class Static_Site_Importer_Block_Theme_Scorecard {
	/** Build scorecard evidence from a finalized import report. */
	public static function from_import( array $report ): array {
		$receipt                     = $report['materialization_receipt'] ?? array();
		$context                     = $receipt['scorecard_context'] ?? array();
		$context['design_system']    = $report['design_system'] ?? $receipt['design_system'] ?? array();
		$context['source_css_bytes'] = $context['design_system']['metrics']['unique_source_css_bytes'] ?? $context['source_css_bytes'] ?? null;
		$routes                      = array();
		$documents                   = array_column( $context['editability_report']['documents'] ?? array(), null, 'source_path' );
		foreach ( $receipt['wordpress'] ?? array() as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( ! $id || ! function_exists( 'get_post' ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
				continue;
			}
			$routes[] = array(
				'route'              => $row['source_path'] ?? (string) $id,
				'page_type'          => 'post' === $post->post_type ? 'single' : 'page',
				'post_content'       => (string) $post->post_content,
				'editability_report' => $documents[ $row['source_path'] ?? '' ] ?? array(),
			);
		}
		$context['distinct_menus'] = $context['distinct_menus'] ?? null;
		$dir                       = (string) ( $receipt['theme']['dir'] ?? '' );
		return function_exists( 'parse_blocks' ) && is_dir( $dir )
			? self::measure( $dir, $routes, $context )
			: array(
				'schema' => 'static-site-importer/block-theme-scorecard/v1',
				'gating' => false,
				'status' => 'not_measured',
				'reason' => 'materialized_theme_or_block_parser_unavailable',
				'checks' => array(),
			);
	}

	/** Render the signals-only report-card table without changing its encoding. */
	public static function report_card_rows( array $scorecard ): string {
		$rows = "\n\n## Block theme scorecard (signals only)\n\n| Check | Status | Measured | Pass threshold |\n| --- | --- | --- | --- |\n";
		foreach ( $scorecard['checks'] as $name => $check ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve the report card's existing JSON encoding.
			$rows .= '| ' . $name . ' | ' . $check['status'] . ' | ' . str_replace( '|', '\\|', (string) json_encode( $check['measured'] ) ) . ' | ' . str_replace( '|', '\\|', (string) json_encode( $check['pass_threshold'] ) ) . " |\n";
		}
		return $rows;
	}

	/** Measure theme files and materialized routes without changing them. */
	public static function measure( string $theme_dir, array $routes, array $source_css_bytes_context ): array {
		$context   = $source_css_bytes_context;
		$templates = self::files( $theme_dir . '/templates', 'html' );
		$parts     = self::files( $theme_dir . '/parts', 'html' );
		$json      = json_decode( self::contents( $theme_dir . '/theme.json' ), true );
		if ( ! $json ) {
			$json = array();
		}
		$template_blocks = array_map( 'parse_blocks', $templates );
		$part_blocks     = array_map( 'parse_blocks', $parts );
		$route_blocks    = array();
		foreach ( $routes as $key => $route ) {
			$route_blocks[ $key ] = parse_blocks( (string) ( $route['post_content'] ?? '' ) );
		}
		return array(
			'schema' => 'static-site-importer/block-theme-scorecard/v1',
			'gating' => false,
			'checks' => array(
				'template_hierarchy'   => self::template_hierarchy( $templates, $routes ),
				'shared_shell'         => self::shared_shell( $template_blocks, $parts, $json, $routes ),
				'navigation'           => self::navigation( $template_blocks, $part_blocks, $route_blocks, $context ),
				'design_tokens'        => self::design_tokens( $json, $context ),
				'theme_json'           => self::theme_json( $json ),
				'proportionate_assets' => self::proportionate_assets( $theme_dir, $context ),
				'content_quality'      => self::content_quality( $routes, $route_blocks ),
				'theme_metadata'       => self::theme_metadata( $theme_dir ),
				'template_economy'     => self::template_economy( $template_blocks ),
			),
		);
	}

	/** Measure template hierarchy. */
	private static function template_hierarchy( array $templates, array $routes ): array {
		$missing = array();
		foreach ( $routes as $route ) {
			$type       = $route['page_type'] ?? 'page';
			$candidates = array( $type, 'singular', 'index' );
			if ( 'front-page' === $type ) {
				$candidates = array( 'front-page', 'home', 'page', 'singular', 'index' );
			}
			if ( ! array_intersect( $candidates, array_keys( $templates ) ) ) {
				$missing[] = $type;
			}
		}
		return self::check(
			array(
				'index_exists'       => isset( $templates['index'] ),
				'template_count'     => count( $templates ),
				'missing_page_types' => array_values( array_unique( $missing ) ),
			),
			array(
				'index_exists'       => true,
				'missing_page_types' => 0,
			),
			isset( $templates['index'] ) && ! $missing
		);
	}

	/** Measure shared shell. */
	private static function shared_shell( array $template_blocks, array $parts, array $json, array $routes ): array {
		$references    = array();
		$missing_shell = array();
		$variants      = array(
			'header' => array(),
			'footer' => array(),
		);
		foreach ( $template_blocks as $slug => $blocks ) {
			$areas = array();
			foreach ( self::flatten( $blocks ) as $block ) {
				if ( 'core/template-part' === $block['blockName'] ) {
					$part                = $block['attrs']['slug'] ?? '';
					$references[ $part ] = true;
					$area                = self::part_area( $part, $json, $block['attrs']['area'] ?? '', true );
					if ( isset( $variants[ $area ] ) && isset( $parts[ $part ] ) ) {
						$areas[ $area ]             = true;
						$variants[ $area ][ $part ] = true;
					}
				}
			}
			if ( ! isset( $areas['header'], $areas['footer'] ) ) {
				$missing_shell[] = $slug;
			}
		}
		foreach ( $parts as $slug => $markup ) {
			$area = self::part_area( $slug, $json );
			if ( isset( $variants[ $area ] ) ) {
				$variants[ $area ][ $slug ] = true;
			}
		}
		$duplicates = array();
		foreach ( $routes as $key => $route ) {
			$path    = (string) ( $route['route'] ?? $key );
			$content = (string) ( $route['post_content'] ?? '' );
			foreach ( $parts as $slug => $markup ) {
				$needle = preg_replace( '/\s+/', '', trim( $markup ) );
				if ( '' !== $needle && str_contains( preg_replace( '/\s+/', '', $content ), $needle ) ) {
					$duplicates[ $path ][] = $slug;
				}
			}
		}
		$unreferenced = array_values( array_diff( array_keys( $parts ), array_keys( $references ) ) );
		return self::check(
			array(
				'templates_missing_shell'   => $missing_shell,
				'unreferenced_parts'        => $unreferenced,
				'duplicated_parts_by_route' => $duplicates,
				'header_variants'           => count( $variants['header'] ),
				'footer_variants'           => count( $variants['footer'] ),
			),
			array(
				'templates_missing_shell'   => 0,
				'unreferenced_parts'        => 0,
				'duplicated_parts_by_route' => 0,
			),
			! $missing_shell && ! $unreferenced && ! $duplicates
		);
	}

	/** Resolve explicit, declared, then conventional part areas. */
	private static function part_area( string $slug, array $json, string $area = '', bool $template_reference = false ): string {
		foreach ( $json['templateParts'] ?? array() as $definition ) {
			if ( ( $definition['name'] ?? '' ) === $slug ) {
				if ( ! $template_reference || ! $area ) {
					$area = $definition['area'] ?? '';
				}
				if ( $template_reference && $area ) {
					break;
				}
			}
		}
		if ( ! $area && preg_match( '/^(header|footer)(?:-|$)/', $slug, $match ) ) {
			$area = $match[1];
		}
		return $area;
	}

	/** Measure navigation. */
	private static function navigation( array $template_blocks, array $part_blocks, array $route_blocks, array $context ): array {
		$nav     = 0;
		$refs    = array();
		$buttons = 0;
		foreach ( array( $template_blocks, $part_blocks, $route_blocks ) as $collection ) {
			foreach ( $collection as $blocks ) {
				self::count_navigation( $blocks, $nav, $refs, $buttons );
			}
		}
		$menus = $context['distinct_menus'] ?? null;
		return self::check(
			array(
				'navigation_blocks_with_ref'    => $nav,
				'distinct_navigation_refs'      => count( $refs ),
				'distinct_menus'                => $menus,
				'navigation_shaped_button_rows' => $buttons,
			),
			array(
				'distinct_navigation_refs'      => $menus,
				'navigation_shaped_button_rows' => 0,
			),
			null === $menus ? null : count( $refs ) >= $menus && 0 === $buttons
		);
	}

	/** Measure design tokens. */
	private static function design_tokens( array $json, array $context ): array {
		$presets = array();
		foreach ( array(
			'palette'       => array( 'color', 'palette' ),
			'font_families' => array( 'typography', 'fontFamilies' ),
			'font_sizes'    => array( 'typography', 'fontSizes' ),
			'spacing'       => array( 'spacing', 'spacingSizes' ),
		) as $name => $keys ) {
			$presets[ $name ] = count( $json['settings'][ $keys[0] ][ $keys[1] ] ?? array() );
		}
		return self::check(
			array(
				'preset_counts'         => $presets,
				'design_system_metrics' => $context['design_system']['metrics'] ?? null,
			),
			array(
				'each_preset_count_min' => 1,
			),
			min( $presets ) > 0
		);
	}

	/** Measure theme json. */
	private static function theme_json( array $json ): array {
		$elements = array();
		foreach ( array( 'link', 'button', 'heading' ) as $name ) {
			$elements[ $name ] = ! empty( $json['styles']['elements'][ $name ] );
		}
		return self::check(
			array(
				'elements'          => $elements,
				'block_style_count' => count( $json['styles']['blocks'] ?? array() ),
			),
			array(
				'all_elements_populated' => true,
				'block_style_count_min'  => 1,
			),
			! in_array( false, $elements, true ) && ! empty( $json['styles']['blocks'] )
		);
	}

	/** Measure proportionate assets. */
	private static function proportionate_assets( string $theme_dir, array $context ): array {
		$css       = self::files( $theme_dir, 'css' );
		$bytes     = array_sum( array_map( 'strlen', $css ) );
		$source    = $context['source_css_bytes'] ?? null;
		$ratio     = $source > 0 ? $bytes / $source : null;
		$functions = strlen( self::contents( $theme_dir . '/functions.php' ) );
		return self::check(
			array(
				'theme_css_bytes'     => $bytes,
				'source_css_bytes'    => $source,
				'css_ratio'           => $ratio,
				'css_file_count'      => count( $css ),
				'functions_php_bytes' => $functions,
			),
			array(
				'css_ratio_max'           => 3,
				'css_file_count_max'      => 20,
				'functions_php_bytes_max' => 65536,
			),
			null === $ratio ? null : $ratio <= 3 && count( $css ) <= 20 && $functions <= 65536
		);
	}

	/** Measure content quality. */
	private static function content_quality( array $routes, array $route_blocks ): array {
		$totals    = array();
		$worst     = array();
		$per_route = array();
		foreach ( $routes as $key => $route ) {
			$path    = (string) ( $route['route'] ?? $key );
			$content = (string) ( $route['post_content'] ?? '' );
			$blocks  = $route_blocks[ $key ];
			$metrics = array(
				'block_count'             => 0,
				'core_html_count'         => 0,
				'custom_block_count'      => 0,
				'inline_style_attributes' => preg_match_all( '/\sstyle\s*=\s*[\'\"]/i', $content ),
				'max_nesting_depth'       => 0,
			);
			self::content_metrics( $blocks, 1, $metrics );
			$existing = $route['editability_report']['metrics'] ?? array();
			foreach ( array(
				'block_count'       => 'block_count',
				'core_html_count'   => 'raw_html_block_count',
				'max_nesting_depth' => 'max_nesting_depth',
			) as $target => $source ) {
				if ( isset( $existing[ $source ] ) ) {
					$metrics[ $target ] = $existing[ $source ];
				}
			}
			$per_route[ $path ] = $metrics;
			foreach ( $metrics as $name => $value ) {
				$totals[ $name ] = 'max_nesting_depth' === $name
					? max( $totals[ $name ] ?? 0, $value )
					: ( $totals[ $name ] ?? 0 ) + $value;
				if ( ! isset( $worst[ $name ] ) || $value > $worst[ $name ]['value'] ) {
					$worst[ $name ] = array(
						'route' => $path,
						'value' => $value,
					);
				}
			}
		}
		return self::check(
			array(
				'totals'       => $totals,
				'worst_routes' => $worst,
				'routes'       => $per_route,
			),
			array(
				'core_html_count_max'                   => 0,
				'custom_block_count_max'                => 0,
				'inline_style_attributes_per_route_max' => 50,
				'block_count_per_route_max'             => 500,
				'max_nesting_depth'                     => 12,
			),
			( $totals['core_html_count'] ?? 0 ) === 0 && ( $totals['custom_block_count'] ?? 0 ) === 0 && ( $worst['inline_style_attributes']['value'] ?? 0 ) <= 50 && ( $worst['block_count']['value'] ?? 0 ) <= 500 && ( $worst['max_nesting_depth']['value'] ?? 0 ) <= 12
		);
	}

	/** Measure theme metadata. */
	private static function theme_metadata( string $theme_dir ): array {
		$headers = array();
		$style   = self::contents( $theme_dir . '/style.css' );
		foreach ( array( 'Theme Name', 'Description', 'Requires at least', 'Tested up to', 'Requires PHP', 'License' ) as $header ) {
			preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $style, $match );
			$headers[ $header ] = trim( $match[1] ?? '' );
		}
		$screenshot = is_file( $theme_dir . '/screenshot.png' );
		return self::check(
			array(
				'headers'           => $headers,
				'screenshot_exists' => $screenshot,
			),
			array(
				'all_headers_populated' => true,
				'screenshot_exists'     => true,
			),
			! in_array( '', $headers, true ) && $screenshot
		);
	}

	/** Measure template economy. */
	private static function template_economy( array $template_blocks ): array {
		$fingerprints        = array();
		$custom_fingerprints = array();
		$chunks              = array();
		$standard            = array( 'index', 'front-page', 'home', 'page', 'single', 'singular', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date', 'search', '404', 'attachment' );
		$custom              = 0;
		foreach ( $template_blocks as $slug => $blocks ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve the existing structure fingerprint encoding.
			$fingerprint                  = json_encode( self::structure( $blocks ) );
			$fingerprints[ $fingerprint ] = true;
			if ( ! in_array( $slug, $standard, true ) ) {
				++$custom;
				$custom_fingerprints[ $fingerprint ] = true;
			}
			// Collect subtrees; the reduction below excludes nested double counting and part references.
			foreach ( self::flatten( $blocks ) as $block ) {
				if ( in_array( $block['blockName'], array( 'core/template-part', 'core/post-content' ), true ) ) {
					continue;
				}
				$chunk                     = serialize_block( $block );
				$chunks[ $chunk ][ $slug ] = true;
			}
		}
		$repeated = 0;
		$covered  = array();
		// Largest repeated subtrees first; their descendants are already accounted for.
		uksort( $chunks, static fn( string $a, string $b ): int => strlen( $b ) !== strlen( $a ) ? strlen( $b ) <=> strlen( $a ) : strcmp( $a, $b ) );
		foreach ( $chunks as $chunk => $owners ) {
			if ( count( $owners ) < 2 ) {
				continue;
			}
			$remaining = array_diff_key( $owners, $covered[ $chunk ] ?? array() );
			if ( count( $remaining ) > 1 ) {
				$repeated += strlen( $chunk ) * ( count( $remaining ) - 1 );
				foreach ( self::flatten( parse_blocks( $chunk ) ) as $descendant ) {
					$key             = serialize_block( $descendant );
					$covered[ $key ] = ( $covered[ $key ] ?? array() ) + $remaining;
				}
			}
		}
		return self::check(
			array(
				'custom_template_count'               => $custom,
				'distinct_template_structures'        => count( $fingerprints ),
				'distinct_custom_template_structures' => count( $custom_fingerprints ),
				'repeated_block_markup_bytes'         => $repeated,
			),
			array(
				'custom_templates_max'            => count( $custom_fingerprints ),
				'repeated_block_markup_bytes_max' => 0,
			),
			$custom <= count( $custom_fingerprints ) && 0 === $repeated
		);
	}

	/** Format measured values, thresholds and status. */
	private static function check( array $value, array $threshold, ?bool $passed ): array {
		return array(
			'measured'       => $value,
			'pass_threshold' => $threshold,
			'status'         => null === $passed ? 'not_measured' : ( $passed ? 'pass' : 'fail' ),
		);
	}

	/** Read a local theme file. */
	private static function contents( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local theme files, not remote URLs.
		return is_file( $path ) && is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/** Read matching files in stable path order. */
	private static function files( string $dir, string $extension ): array {
		$result = array();
		if ( ! is_dir( $dir ) ) {
			return $result;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && ! $file->isLink() && $extension === $file->getExtension() ) {
				$key            = substr( $file->getPathname(), strlen( $dir ) + 1, -strlen( $extension ) - 1 );
				$result[ $key ] = self::contents( $file->getPathname() );
			}
		}
		ksort( $result );
		return $result;
	}

	/** Flatten named blocks in traversal order. */
	private static function flatten( array $blocks ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$result[] = $block;
			$result   = array_merge( $result, self::flatten( $block['innerBlocks'] ?? array() ) );
		}
		return $result;
	}

	/** Fingerprint block structure without content or IDs. */
	private static function structure( array $blocks ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$result[] = array(
				$block['blockName'],
				$block['attrs']['layout']['type'] ?? '',
				$block['attrs']['tagName'] ?? '',
				self::structure( $block['innerBlocks'] ?? array() ),
			);
		}
		return $result;
	}

	/** Count navigation. */
	private static function count_navigation( array $blocks, int &$nav, array &$refs, int &$buttons ): void {
		foreach ( self::flatten( $blocks ) as $block ) {
			if ( 'core/navigation' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
				++$nav;
				$refs[ $block['attrs']['ref'] ] = true;
			}
			if ( 'core/buttons' === $block['blockName'] && count( $block['innerBlocks'] ?? array() ) >= 3 ) {
				++$buttons;
			}
		}
	}

	/** Accumulate recursive content metrics. */
	private static function content_metrics( array $blocks, int $depth, array &$metrics ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( ! $name ) {
				continue;
			}
			++$metrics['block_count'];
			$metrics['core_html_count']    += (int) ( 'core/html' === $name );
			$metrics['custom_block_count'] += (int) ! str_starts_with( $name, 'core/' );
			$metrics['max_nesting_depth']   = max( $depth, $metrics['max_nesting_depth'] );
			self::content_metrics( $block['innerBlocks'] ?? array(), $depth + 1, $metrics );
		}
	}
}
