<?php
/** Measured, non-gating evidence from the materialized block theme. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Static_Site_Importer_Block_Theme_Scorecard {
	/**
	 * Pure filesystem measurement: no writes, database queries or quality decisions.
	 * Routes carry route, post_content, page_type and optional editability_report.
	 * Context carries source_css_bytes (null means unknown), distinct_menus and design_system.
	 */
	public static function measure( string $theme_dir, array $routes, array $source_css_bytes_context ): array {
		$context = $source_css_bytes_context;
		$templates = self::files( $theme_dir . '/templates', 'html' );
		$parts = self::files( $theme_dir . '/parts', 'html' );
		$json = json_decode( self::contents( $theme_dir . '/theme.json' ), true ) ?: array();
		$checks = array();
		$missing = array();
		foreach ( $routes as $route ) {
			$type = $route['page_type'] ?? 'page';
			$candidates = array( $type, 'singular', 'index' );
			if ( 'front-page' === $type ) { $candidates = array( 'front-page', 'home', 'page', 'singular', 'index' ); }
			if ( ! array_intersect( $candidates, array_keys( $templates ) ) ) { $missing[] = $type; }
		}
		$checks['template_hierarchy'] = self::check( array( 'index_exists' => isset( $templates['index'] ), 'template_count' => count( $templates ), 'missing_page_types' => array_values( array_unique( $missing ) ) ), array( 'index_exists' => true, 'missing_page_types' => 0 ), isset( $templates['index'] ) && ! $missing );
		$references = array(); $missing_shell = array(); $variants = array( 'header' => array(), 'footer' => array() );
		$nav = 0; $refs = array(); $buttons = 0; $fingerprints = array(); $custom_fingerprints = array(); $chunks = array();
		$standard = array( 'index', 'front-page', 'home', 'page', 'single', 'singular', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date', 'search', '404', 'attachment' );
		$custom = 0;
		foreach ( $templates as $slug => $markup ) {
			$blocks = parse_blocks( $markup );
			$fingerprint = json_encode( self::structure( $blocks ) );
			$fingerprints[ $fingerprint ] = true;
			if ( ! in_array( $slug, $standard, true ) ) { ++$custom; $custom_fingerprints[ $fingerprint ] = true; }
			$areas = array();
			foreach ( self::flatten( $blocks ) as $block ) {
				if ( 'core/template-part' === $block['blockName'] ) {
					$part = $block['attrs']['slug'] ?? '';
					$references[ $part ] = true;
					$area = $block['attrs']['area'] ?? '';
					foreach ( $json['templateParts'] ?? array() as $definition ) { if ( ( $definition['name'] ?? '' ) === $part ) { $area = $area ?: ( $definition['area'] ?? '' ); } }
					if ( ! $area && preg_match( '/^(header|footer)(?:-|$)/', $part, $match ) ) { $area = $match[1]; }
					if ( isset( $variants[ $area ] ) && isset( $parts[ $part ] ) ) { $areas[ $area ] = true; $variants[ $area ][ $part ] = true; }
				}
			}
			if ( ! isset( $areas['header'], $areas['footer'] ) ) { $missing_shell[] = $slug; }
			self::navigation( $blocks, $nav, $refs, $buttons );
			// Collect subtrees; the reduction below excludes nested double counting and part references.
			foreach ( self::flatten( $blocks ) as $block ) {
				if ( in_array( $block['blockName'], array( 'core/template-part', 'core/post-content' ), true ) ) { continue; }
				$chunk = serialize_block( $block );
				$chunks[ $chunk ][ $slug ] = true;
			}
		}
		foreach ( $parts as $slug => $markup ) {
			self::navigation( parse_blocks( $markup ), $nav, $refs, $buttons );
			$area = '';
			foreach ( $json['templateParts'] ?? array() as $definition ) { if ( ( $definition['name'] ?? '' ) === $slug ) { $area = $definition['area'] ?? ''; } }
			if ( ! $area && preg_match( '/^(header|footer)(?:-|$)/', $slug, $match ) ) { $area = $match[1]; }
			if ( isset( $variants[ $area ] ) ) { $variants[ $area ][ $slug ] = true; }
		}
		$duplicates = array(); $totals = array(); $worst = array(); $per_route = array();
		foreach ( $routes as $key => $route ) {
			$path = (string) ( $route['route'] ?? $key ); $content = (string) ( $route['post_content'] ?? '' );
			$blocks = parse_blocks( $content );
			self::navigation( $blocks, $nav, $refs, $buttons );
			foreach ( $parts as $slug => $markup ) {
				$needle = preg_replace( '/\s+/', '', trim( $markup ) );
				if ( '' !== $needle && str_contains( preg_replace( '/\s+/', '', $content ), $needle ) ) { $duplicates[ $path ][] = $slug; }
			}
			$metrics = array( 'block_count' => 0, 'core_html_count' => 0, 'custom_block_count' => 0, 'inline_style_attributes' => preg_match_all( '/\sstyle\s*=\s*[\'\"]/i', $content ), 'max_nesting_depth' => 0 );
			self::content_metrics( $blocks, 1, $metrics );
			$existing = $route['editability_report']['metrics'] ?? array();
			foreach ( array( 'block_count' => 'block_count', 'core_html_count' => 'raw_html_block_count', 'max_nesting_depth' => 'max_nesting_depth' ) as $target => $source ) { if ( isset( $existing[ $source ] ) ) { $metrics[ $target ] = $existing[ $source ]; } }
			$per_route[ $path ] = $metrics;
			foreach ( $metrics as $name => $value ) {
				$totals[ $name ] = ( $totals[ $name ] ?? 0 ) + $value;
				if ( ! isset( $worst[ $name ] ) || $value > $worst[ $name ]['value'] ) { $worst[ $name ] = array( 'route' => $path, 'value' => $value ); }
			}
		}
		$unreferenced = array_values( array_diff( array_keys( $parts ), array_keys( $references ) ) );
		$checks['shared_shell'] = self::check( array( 'templates_missing_shell' => $missing_shell, 'unreferenced_parts' => $unreferenced, 'duplicated_parts_by_route' => $duplicates, 'header_variants' => count( $variants['header'] ), 'footer_variants' => count( $variants['footer'] ) ), array( 'templates_missing_shell' => 0, 'unreferenced_parts' => 0, 'duplicated_parts_by_route' => 0 ), ! $missing_shell && ! $unreferenced && ! $duplicates );
		$menus = $context['distinct_menus'] ?? null;
		$checks['navigation'] = self::check( array( 'navigation_blocks_with_ref' => $nav, 'distinct_navigation_refs' => count( $refs ), 'distinct_menus' => $menus, 'navigation_shaped_button_rows' => $buttons ), array( 'distinct_navigation_refs' => $menus, 'navigation_shaped_button_rows' => 0 ), null === $menus ? null : count( $refs ) >= $menus && 0 === $buttons );
		$presets = array();
		foreach ( array( 'palette' => array( 'color', 'palette' ), 'font_families' => array( 'typography', 'fontFamilies' ), 'font_sizes' => array( 'typography', 'fontSizes' ), 'spacing' => array( 'spacing', 'spacingSizes' ) ) as $name => $keys ) { $presets[ $name ] = count( $json['settings'][ $keys[0] ][ $keys[1] ] ?? array() ); }
		$checks['design_tokens'] = self::check( array( 'preset_counts' => $presets, 'design_system_metrics' => $context['design_system']['metrics'] ?? null ), array( 'each_preset_count_min' => 1 ), min( $presets ) > 0 );
		$elements = array(); foreach ( array( 'link', 'button', 'heading' ) as $name ) { $elements[ $name ] = ! empty( $json['styles']['elements'][ $name ] ); }
		$checks['theme_json'] = self::check( array( 'elements' => $elements, 'block_style_count' => count( $json['styles']['blocks'] ?? array() ) ), array( 'all_elements_populated' => true, 'block_style_count_min' => 1 ), ! in_array( false, $elements, true ) && ! empty( $json['styles']['blocks'] ) );
		$css = self::files( $theme_dir, 'css' ); $bytes = array_sum( array_map( 'strlen', $css ) ); $source = $context['source_css_bytes'] ?? null;
		$ratio = $source > 0 ? $bytes / $source : null; $functions = strlen( self::contents( $theme_dir . '/functions.php' ) );
		$checks['proportionate_assets'] = self::check( array( 'theme_css_bytes' => $bytes, 'source_css_bytes' => $source, 'css_ratio' => $ratio, 'css_file_count' => count( $css ), 'functions_php_bytes' => $functions ), array( 'css_ratio_max' => 3, 'css_file_count_max' => 20, 'functions_php_bytes_max' => 65536 ), null === $ratio ? null : $ratio <= 3 && count( $css ) <= 20 && $functions <= 65536 );
		$checks['content_quality'] = self::check( array( 'totals' => $totals, 'worst_routes' => $worst, 'routes' => $per_route ), array( 'core_html_count_max' => 0, 'custom_block_count_max' => 0, 'inline_style_attributes_per_route_max' => 50, 'block_count_per_route_max' => 500, 'max_nesting_depth' => 12 ), ( $totals['core_html_count'] ?? 0 ) === 0 && ( $totals['custom_block_count'] ?? 0 ) === 0 && ( $worst['inline_style_attributes']['value'] ?? 0 ) <= 50 && ( $worst['block_count']['value'] ?? 0 ) <= 500 && ( $worst['max_nesting_depth']['value'] ?? 0 ) <= 12 );
		$headers = array(); $style = self::contents( $theme_dir . '/style.css' );
		foreach ( array( 'Theme Name', 'Description', 'Requires at least', 'Tested up to', 'Requires PHP', 'License' ) as $header ) { preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $style, $match ); $headers[ $header ] = trim( $match[1] ?? '' ); }
		$screenshot = is_file( $theme_dir . '/screenshot.png' );
		$checks['theme_metadata'] = self::check( array( 'headers' => $headers, 'screenshot_exists' => $screenshot ), array( 'all_headers_populated' => true, 'screenshot_exists' => true ), ! in_array( '', $headers, true ) && $screenshot );
		$repeated = 0; $covered = array();
		// Largest repeated subtrees first; their descendants are already accounted for.
		uksort( $chunks, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) ?: strcmp( $a, $b ) );
		foreach ( $chunks as $chunk => $owners ) {
			if ( count( $owners ) < 2 ) { continue; }
			$remaining = array_diff_key( $owners, $covered[ $chunk ] ?? array() );
			if ( count( $remaining ) > 1 ) {
				$repeated += strlen( $chunk ) * ( count( $remaining ) - 1 );
				foreach ( self::flatten( parse_blocks( $chunk ) ) as $descendant ) {
					$key = serialize_block( $descendant );
					$covered[ $key ] = ( $covered[ $key ] ?? array() ) + $remaining;
				}
			}
		}
		$checks['template_economy'] = self::check( array( 'custom_template_count' => $custom, 'distinct_template_structures' => count( $fingerprints ), 'distinct_custom_template_structures' => count( $custom_fingerprints ), 'repeated_block_markup_bytes' => $repeated ), array( 'custom_templates_max' => count( $custom_fingerprints ), 'repeated_block_markup_bytes_max' => 0 ), $custom <= count( $custom_fingerprints ) && 0 === $repeated );
		return array( 'schema' => 'static-site-importer/block-theme-scorecard/v1', 'gating' => false, 'checks' => $checks );
	}

	private static function check( array $value, array $threshold, ?bool $passed ): array { return array( 'measured' => $value, 'pass_threshold' => $threshold, 'status' => null === $passed ? 'not_measured' : ( $passed ? 'pass' : 'fail' ) ); }
	private static function contents( string $path ): string { return is_file( $path ) && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; }
	private static function files( string $dir, string $extension ): array {
		$result = array(); if ( ! is_dir( $dir ) ) { return $result; }
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && ! $file->isLink() && $extension === $file->getExtension() ) { $key = substr( $file->getPathname(), strlen( $dir ) + 1, -strlen( $extension ) - 1 ); $result[ $key ] = self::contents( $file->getPathname() ); }
		}
		ksort( $result ); return $result;
	}
	private static function flatten( array $blocks ): array { $result = array(); foreach ( $blocks as $block ) { if ( empty( $block['blockName'] ) ) { continue; } $result[] = $block; $result = array_merge( $result, self::flatten( $block['innerBlocks'] ?? array() ) ); } return $result; }
	private static function structure( array $blocks ): array {
		$result = array(); foreach ( $blocks as $block ) { if ( empty( $block['blockName'] ) ) { continue; } $result[] = array( $block['blockName'], $block['attrs']['layout']['type'] ?? '', $block['attrs']['tagName'] ?? '', self::structure( $block['innerBlocks'] ?? array() ) ); } return $result;
	}
	private static function navigation( array $blocks, int &$nav, array &$refs, int &$buttons ): void {
		foreach ( self::flatten( $blocks ) as $block ) {
			if ( 'core/navigation' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) { ++$nav; $refs[ $block['attrs']['ref'] ] = true; }
			if ( 'core/buttons' === $block['blockName'] && count( $block['innerBlocks'] ?? array() ) >= 3 ) { ++$buttons; }
		}
	}
	private static function content_metrics( array $blocks, int $depth, array &$metrics ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? ''; if ( ! $name ) { continue; }
			++$metrics['block_count']; $metrics['core_html_count'] += (int) ( 'core/html' === $name ); $metrics['custom_block_count'] += (int) ! str_starts_with( $name, 'core/' ); $metrics['max_nesting_depth'] = max( $depth, $metrics['max_nesting_depth'] );
			self::content_metrics( $block['innerBlocks'] ?? array(), $depth + 1, $metrics );
		}
	}
}
