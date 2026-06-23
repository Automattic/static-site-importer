<?php
/**
 * Generated theme stylesheet materialization helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds generated theme stylesheet write payloads.
 */
class Static_Site_Importer_Stylesheet_Materializer {

	/**
	 * Build stylesheet writes for a generated block theme.
	 *
	 * @param string                  $theme_dir            Theme directory.
	 * @param string                  $theme_name           Theme name.
	 * @param string                  $css                  Source CSS.
	 * @param array<string,array<string,mixed>> $assets Materialized asset map.
	 * @param array<string,array<int,string>> $visual_repair_styles Visual repair CSS content by target.
	 * @return array<string,string> Absolute stylesheet write paths mapped to file contents.
	 */
	public static function stylesheet_writes(
		string $theme_dir,
		string $theme_name,
		string $css,
		array $assets,
		array $visual_repair_styles
	): array {
		$css = self::rewrite_css_asset_urls( $css, $assets );

		return array(
			$theme_dir . '/style.css'                   => self::style_css( $theme_name, $css, $visual_repair_styles ),
			$theme_dir . '/assets/css/editor-style.css' => self::editor_style_css( $css, $visual_repair_styles ),
		);
	}

	/**
	 * Rewrite CSS url(...) references to materialized theme asset URLs.
	 *
	 * @param string                            $css    Source CSS.
	 * @param array<string,array<string,mixed>> $assets Materialized asset map.
	 * @return string
	 */
	private static function rewrite_css_asset_urls( string $css, array $assets ): string {
		if ( '' === trim( $css ) || empty( $assets ) || ! str_contains( $css, 'url(' ) ) {
			return $css;
		}

		$replacements = array();
		foreach ( $assets as $source => $asset ) {
			$url = isset( $asset['final_url'] ) && is_scalar( $asset['final_url'] ) ? (string) $asset['final_url'] : ( isset( $asset['url'] ) && is_scalar( $asset['url'] ) ? (string) $asset['url'] : '' );
			if ( '' === $url ) {
				continue;
			}

			foreach ( self::css_asset_replacement_keys( (string) $source ) as $key ) {
				$replacements[ $key ] = $url;
			}
			if ( isset( $asset['path'] ) && is_scalar( $asset['path'] ) ) {
				foreach ( self::css_asset_replacement_keys( (string) $asset['path'] ) as $key ) {
					$replacements[ $key ] = $url;
				}
			}
		}

		if ( empty( $replacements ) ) {
			return $css;
		}

		return (string) preg_replace_callback(
			'#url\(\s*(["\']?)([^)"\']+)\1\s*\)#i',
			static function ( array $matches ) use ( $replacements ): string {
				$raw = trim( (string) $matches[2] );
				if ( '' === $raw || preg_match( '#^(?:data:|https?://|//)#i', $raw ) ) {
					return $matches[0];
				}

				$key = self::normalize_css_asset_ref( $raw );
				if ( '' === $key || ! isset( $replacements[ $key ] ) ) {
					return $matches[0];
				}

				return 'url("' . esc_url_raw( $replacements[ $key ] ) . '")';
			},
			$css
		);
	}

	/**
	 * Build lookup keys for a materialized asset path.
	 *
	 * @param string $path Asset path.
	 * @return array<int,string>
	 */
	private static function css_asset_replacement_keys( string $path ): array {
		$path = self::normalize_css_asset_ref( $path );
		if ( '' === $path ) {
			return array();
		}

		$keys = array( $path );
		if ( str_starts_with( $path, 'website/' ) ) {
			$keys[] = substr( $path, strlen( 'website/' ) );
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Normalize a CSS asset reference for replacement lookup.
	 *
	 * @param string $ref Asset reference.
	 * @return string
	 */
	private static function normalize_css_asset_ref( string $ref ): string {
		$ref = html_entity_decode( trim( $ref ), ENT_QUOTES | ENT_HTML5 );
		$ref = strtok( $ref, '?#' );
		$ref = str_replace( '\\', '/', false === $ref ? '' : $ref );
		$ref = preg_replace( '#(^|/)\.(?=/|$)#', '', $ref );
		$ref = preg_replace( '#/+#', '/', (string) $ref );
		$ref = trim( (string) $ref, '/' );

		return preg_match( '#^[A-Za-z0-9_./-]+$#', $ref ) ? $ref : '';
	}

	/**
	 * Build style.css.
	 *
	 * @param string                          $theme_name           Theme name.
	 * @param string                          $css                  Source CSS.
	 * @param array<string,array<int,string>> $visual_repair_styles Visual repair CSS content by target.
	 * @return string
	 */
	private static function style_css( string $theme_name, string $css, array $visual_repair_styles = array() ): string {
		$admin_bar_bridge = self::admin_bar_top_chrome_css( $css );
		$repair_css       = self::visual_repair_css_for_target( $visual_repair_styles, 'frontend' );

		return "/*\nTheme Name: " . $theme_name . "\nAuthor: Static Site Importer\nDescription: Materialized from a compiled website artifact.\nVersion: 0.1.0\nRequires at least: 6.6\n*/\n\n" . $css . "\n" . $admin_bar_bridge . $repair_css;
	}

	/**
	 * Build editor-style.css.
	 *
	 * @param string                          $css                  Source CSS.
	 * @param array<string,array<int,string>> $visual_repair_styles Visual repair CSS content by target.
	 * @return string
	 */
	private static function editor_style_css( string $css, array $visual_repair_styles = array() ): string {
		$repair_css = self::visual_repair_css_for_target( $visual_repair_styles, 'editor' );

		return "/*\nStatic Site Importer editor styles.\nGenerated separately from frontend style.css so editor wrapper repairs do not leak to public rendering.\n*/\n\n" . $css . "\n" . $repair_css;
	}

	/**
	 * Return compiled visual repair CSS for one stylesheet target.
	 *
	 * @param array<string,array<int,string>> $visual_repair_styles Repair CSS content by target.
	 * @param string                          $target               Stylesheet target.
	 * @return string CSS content.
	 */
	private static function visual_repair_css_for_target( array $visual_repair_styles, string $target ): string {
		$styles = $visual_repair_styles[ $target ] ?? array();
		$styles = array_values( array_filter( array_map( 'strval', $styles ), static fn ( string $style ): bool => '' !== trim( $style ) ) );

		return empty( $styles ) ? '' : "\n" . implode( "\n", $styles ) . "\n";
	}

	/**
	 * Build frontend admin-bar offsets for imported fixed/sticky top chrome.
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
					$selectors[] = 'body.admin-bar ' . $selector;
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
}
