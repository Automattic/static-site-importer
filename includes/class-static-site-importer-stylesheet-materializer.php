<?php
/**
 * Generated theme stylesheet materialization helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Generated_File' ) ) {
	require_once __DIR__ . '/class-static-site-importer-generated-file.php';
}
if ( ! class_exists( 'Static_Site_Importer_Build_Provenance' ) ) {
	require_once __DIR__ . '/class-static-site-importer-build-provenance.php';
}
require_once __DIR__ . '/class-static-site-importer-form-presentation-overlay.php';
if ( ! class_exists( '\\Automattic\\BlocksEngine\\PhpTransformer\\AssetAnalysis\\CssUrlRewriter' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/src/AssetAnalysis/CssUrlRewriter.php';
}

/**
 * Builds generated theme stylesheet write payloads.
 */
class Static_Site_Importer_Stylesheet_Materializer {

	/**
	 * Build stylesheet writes for a generated block theme.
	 *
	 * @param string                            $theme_dir            Theme directory.
	 * @param string                            $theme_name           Theme name.
	 * @param string                            $css                  Source CSS.
	 * @param array<string,array<string,mixed>> $assets Materialized asset map.
	 * @param array<string,array<int,string>>   $visual_repair_styles Visual repair CSS content by target.
	 * @param array<int,array<string,mixed>>    $provider_layout_overlays Validated provider layout overlays.
	 * @param array<string,string>|null         $existing_stylesheets Existing stylesheet contents by path.
	 * @param array<string,mixed>               $artifact_provenance Producer artifact provenance, when carried.
	 * @return array<string,string> Absolute stylesheet write paths mapped to file contents.
	 */
	public static function stylesheet_writes(
		string $theme_dir,
		string $theme_name,
		string $css,
		array $assets,
		array $visual_repair_styles,
		array $provider_layout_overlays = array(),
		?array $existing_stylesheets = null,
		array $artifact_provenance = array()
	): array {
		// Overlays carry site-root asset paths (a captured button sprite, say);
		// publish them as asset URLs relative to the stylesheet that serves them.
		$provider = static fn( string $field, string $theme_base ): string => self::rewrite_css_asset_urls( self::provider_layout_overlay_css( $provider_layout_overlays, $field ), $assets, $theme_base );
		if ( null !== $existing_stylesheets ) {
			$writes = array();
			foreach ( $existing_stylesheets as $path => $content ) {
				$is_editor       = str_ends_with( $path, '/assets/css/editor-style.css' );
				$base            = $is_editor ? '../../' : '';
				$writes[ $path ] = $provider( $is_editor ? 'editor_context_css' : 'context_css', $base ) . self::preserve_form_placeholders( $content, $provider_layout_overlays ) . $provider( 'css', $base ) . ( $is_editor ? $provider( 'editor_css', $base ) : '' );
			}
			return $writes;
		}
		// A source led by a theme header is the plan's style.css scaffold. Its
		// header is written once: kept when it names this theme, otherwise
		// replaced by the generated one; the editor stylesheet needs none.
		$header = '';
		if ( preg_match( '/\A\s*\/\*((?:(?!\*\/).)*)\*\/\s*/s', $css, $match ) && preg_match( '/^[ \t*]*Theme Name:[ \t]*(.+?)[ \t]*$/m', $match[1], $name ) ) {
			$css = substr( $css, strlen( $match[0] ) );
			if ( Static_Site_Importer_Generated_File::comment_header_value( $theme_name ) === $name[1] ) {
				$header = trim( $match[0] ) . "\n";
			}
		}
		$css                 = self::preserve_form_placeholders( self::rewrite_css_asset_urls( $css, $assets ), $provider_layout_overlays );
		$provider_layout_css = $provider( 'css', '' );

		return array(
			$theme_dir . '/style.css'                   => self::style_css( $theme_name, $provider( 'context_css', '' ) . $css . $provider_layout_css, $visual_repair_styles, $artifact_provenance, $header ),
			$theme_dir . '/assets/css/editor-style.css' => self::editor_style_css( $provider( 'editor_context_css', '../../' ) . $css . $provider( 'css', '../../' ) . $provider( 'editor_css', '../../' ), $visual_repair_styles, '' !== $provider_layout_css ),
		);
	}

	/**
	 * Keep authored placeholder rules above provider chrome and fallback resets.
	 *
	 * `revert` is still useful for controls without authored placeholder styles,
	 * but it rolls back the whole author origin, not just Jetpack's `inherit`.
	 * Add scoped alternatives to the source rules instead of capturing a colour:
	 * variables, state, conditions and subsequent source edits remain live.
	 * The scope is attached to the subject so source ancestor selectors still work.
	 * Five scope classes outrank both frontend and editor placeholder resets.
	 *
	 * @param string                         $css Source stylesheet.
	 * @param array<int,array<string,mixed>> $overlays Provider overlays.
	 * @return string
	 */
	public static function preserve_form_placeholders( string $css, array $overlays ): string {
		if ( ! str_contains( $css, '::placeholder' ) ) {
			return $css;
		}
		$scopes = array();
		foreach ( $overlays as $overlay ) {
			$validated = Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $overlay );
			if ( null !== $validated && preg_match( '/\.ssi-form-[a-f0-9]{12}/', $validated['css'], $match ) ) {
				$scopes[] = str_repeat( $match[0], 5 ) . ' *';
			}
		}
		if ( empty( $scopes ) ) {
			return $css;
		}
		$scope = ':is(' . implode( ',', array_unique( $scopes ) ) . ')';
		return ( new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer() )->transform(
			$css,
			static function ( string $prelude ) use ( $scope ): string {
				if ( str_contains( $prelude, $scope ) ) {
					return $prelude;
				}
				$selectors = \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevel( $prelude, array( ',' ) );
				$projected = array();
				foreach ( $selectors as $selector ) {
					if ( preg_match( '/::placeholder\s*$/', $selector ) && ! str_contains( $selector, '.ssi-form-' ) ) {
						$projected[] = preg_replace( '/::placeholder\s*$/', $scope . '::placeholder', $selector );
					}
				}
				return empty( $projected ) ? $prelude : $prelude . ',' . implode( ',', $projected );
			}
		);
	}

	/**
	 * Return deterministic, content-addressed provider layout overlays once each.
	 *
	 * @param array<int,array<string,mixed>> $overlays Provider overlays.
	 * @param string                         $field CSS field to collect.
	 * @return string
	 */
	private static function provider_layout_overlay_css( array $overlays, string $field = 'css' ): string {
		$css = array();
		foreach ( $overlays as $overlay ) {
			$validated = Static_Site_Importer_Form_Presentation_Overlay::validate_overlay( $overlay );
			if ( null !== $validated ) {
				$css[] = $validated[ $field ] ?? '';
			}
		}
		$css = array_values( array_unique( $css ) );
		sort( $css, SORT_STRING );
		return empty( $css ) ? '' : "\n" . implode( "\n", $css );
	}

	/**
	 * Rewrite CSS url(...) references to materialized theme asset URLs.
	 *
	 * @param string                            $css    Source CSS.
	 * @param array<string,array<string,mixed>> $assets     Materialized asset map; a `theme_path` entry is a theme-relative file.
	 * @param string                            $theme_base Path from the stylesheet back to the theme root.
	 * @return string
	 */
	private static function rewrite_css_asset_urls( string $css, array $assets, string $theme_base = '' ): string {
		if ( '' === trim( $css ) || empty( $assets ) || ! str_contains( $css, 'url(' ) ) {
			return $css;
		}

		$replacements = array();
		foreach ( $assets as $source => $asset ) {
			$url = isset( $asset['final_url'] ) && is_scalar( $asset['final_url'] ) ? esc_url_raw( (string) $asset['final_url'] ) : ( isset( $asset['url'] ) && is_scalar( $asset['url'] ) ? esc_url_raw( (string) $asset['url'] ) : '' );
			// Theme-relative paths have no scheme for esc_url_raw() to anchor; admit plain path characters only.
			if ( '' === $url && isset( $asset['theme_path'] ) && is_string( $asset['theme_path'] ) && preg_match( '#^[A-Za-z0-9._~%/-]+$#', $asset['theme_path'] ) && ! str_contains( $asset['theme_path'], '..' ) ) {
				$url = $theme_base . $asset['theme_path'];
			}
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

		return \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(
			$css,
			static function ( string $raw ) use ( $replacements ): string {
				$raw = trim( $raw );
				if ( '' === $raw || preg_match( '#^(?:data:|https?://|//)#i', $raw ) ) {
					return $raw;
				}

				$key = self::normalize_css_asset_ref( $raw );
				if ( '' === $key || ! isset( $replacements[ $key ] ) ) {
					return $raw;
				}

				return $replacements[ $key ];
			}
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

		return preg_match( '#^[A-Za-z0-9_./,-]+$#', $ref ) ? $ref : '';
	}

	/**
	 * Build style.css.
	 *
	 * @param string                          $theme_name           Theme name.
	 * @param string                          $css                  Source CSS.
	 * @param array<string,array<int,string>> $visual_repair_styles Visual repair CSS content by target.
	 * @param array<string,mixed>             $artifact_provenance  Producer artifact provenance, when carried.
	 * @param string                          $header               Resolved theme header to keep, or '' to generate one.
	 * @return string
	 */
	private static function style_css( string $theme_name, string $css, array $visual_repair_styles = array(), array $artifact_provenance = array(), string $header = '' ): string {
		$theme_name       = Static_Site_Importer_Generated_File::comment_header_value( $theme_name );
		$body_class_guard = self::wordpress_body_class_collision_guard_css( $css );
		$repair_css       = self::visual_repair_css_for_target( $visual_repair_styles, 'frontend' );

		return ( '' !== $header ? $header : self::theme_header( $theme_name, '', $theme_name, $artifact_provenance ) ) . "\n" . $css . "\n" . $body_class_guard . $repair_css;
	}

	/**
	 * Compose the canonical producer scaffold header from consumer-owned identity.
	 *
	 * The canonical plan's style.css scaffold is a generic producer placeholder;
	 * the materializer replaces its payload with the resolved site identity using
	 * the same header contract as stylesheet_writes() plus the generated theme's
	 * own text domain. Provenance-carrying builds replace the frozen placeholder
	 * version and add an Update URI identifying this theme; an absent provenance
	 * record keeps the frozen header.
	 *
	 * @param string              $theme_name          Resolved theme name.
	 * @param string              $text_domain         Generated theme text domain (theme slug).
	 * @param array<string,mixed> $artifact_provenance Producer artifact provenance, when carried.
	 * @return string Header-only style.css content.
	 */
	public static function scaffold_style_css( string $theme_name, string $text_domain, array $artifact_provenance = array() ): string {
		$theme_name = Static_Site_Importer_Generated_File::comment_header_value( $theme_name );
		$domain     = strtolower( trim( $text_domain ) );
		$domain     = (string) preg_replace( '/[^a-z0-9_-]+/', '', $domain );
		if ( '' === $domain ) {
			$domain = 'static-site-importer';
		}
		return self::theme_header( $theme_name, $domain, $domain, $artifact_provenance );
	}

	/**
	 * The style.css header comment of a generated theme.
	 *
	 * The theme is GPL like WordPress, so its owner may keep developing it.
	 * It bundles this plugin's runtime code, so it needs the plugin's PHP
	 * floor, and it was materialized on, so tested with, the running
	 * WordPress. A provenance-carrying build replaces the frozen placeholder
	 * version with the producing build and adds an Update URI identifying
	 * this theme, so the theme stays attributable and updatable after SSI is
	 * removed.
	 *
	 * @param string              $theme_name          Header-safe theme name.
	 * @param string              $text_domain         Text domain, or '' for none.
	 * @param string              $provenance_slug     Slug the Update URI identifies.
	 * @param array<string,mixed> $artifact_provenance Producer artifact provenance, when carried.
	 */
	public static function theme_header( string $theme_name, string $text_domain, string $provenance_slug, array $artifact_provenance ): string {
		$headers = array( 'Theme Name' => $theme_name );
		if ( '' !== $text_domain ) {
			$headers['Text Domain'] = $text_domain;
		}
		$headers += array(
			'Author'            => 'Static Site Importer',
			'Description'       => 'Materialized from a compiled website artifact.',
			'Version'           => '0.1.0',
			'Requires at least' => '7.1',
			'Tested up to'      => self::wordpress_branch(),
			'Requires PHP'      => self::plugin_requires_php(),
			'License'           => 'GNU General Public License v2 or later',
			'License URI'       => 'https://www.gnu.org/licenses/gpl-2.0.html',
		);
		foreach ( Static_Site_Importer_Build_Provenance::artifact_header_lines( $artifact_provenance, $provenance_slug ) as $header_line ) {
			list( $name, $value ) = array_map( 'trim', explode( ':', $header_line, 2 ) );
			$headers[ $name ]     = $value;
		}
		$lines = array();
		foreach ( array_filter( $headers, static fn( string $value ): bool => '' !== $value ) as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		return "/*\n" . implode( "\n", $lines ) . "\n*/\n";
	}

	/** The running WordPress branch (`7.1` for `7.1.2` or `7.1-beta1`), or '' outside WordPress. */
	private static function wordpress_branch(): string {
		global $wp_version;
		return is_string( $wp_version ) && preg_match( '/^\d+\.\d+/', $wp_version, $match ) ? $match[0] : '';
	}

	/** This plugin's `Requires PHP` header. */
	private static function plugin_requires_php(): string {
		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/static-site-importer.php', false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this plugin's own local header.
		return preg_match( '/^[ \t\/*#@]*Requires PHP:\s*(\S+)/mi', $plugin, $match ) ? $match[1] : '';
	}

	/**
	 * Build editor-style.css.
	 *
	 * @param string                          $css                  Source CSS.
	 * @param array<string,array<int,string>> $visual_repair_styles Visual repair CSS content by target.
	 * @param bool                            $has_provider_layout  Whether a validated provider layout was materialized.
	 * @return string
	 */
	private static function editor_style_css( string $css, array $visual_repair_styles = array(), bool $has_provider_layout = false ): string {
		$repair_css               = self::visual_repair_css_for_target( $visual_repair_styles, 'editor' );
		$provider_placeholder_css = $has_provider_layout ? ".static-site-importer-empty-visual-group.wp-block-group__placeholder>.components-placeholder{display:none!important}\n" : '';
		// The generated theme.json sets styles.spacing.blockGap to false. Core's PHP
		// prints that as an empty root gap, but the editor's global styles fall back
		// to 0.5em on the same selector, which opens gaps between template parts
		// and post content in template-locked page editing. Global styles load after
		// editor styles, so the canvas root container adds the specificity needed to
		// restate the frontend's effective value.
		$root_gap_css = ":root .is-root-container:where(.wp-site-blocks) > *{margin-block-start:0}\n";

		return "/*\nStatic Site Importer editor styles.\nGenerated separately from frontend style.css so editor wrapper repairs do not leak to public rendering.\n*/\n\n" . $css . "\n" . $root_gap_css . $provider_placeholder_css . $repair_css;
	}

	/**
	 * Prevent source utility/page classes from styling WordPress' generated body classes.
	 *
	 * WordPress adds generic classes such as `page` and `home` to <body>. Static
	 * sites commonly use the same tokens for inner page wrappers, and layout rules
	 * on those source classes must not shrink or pad the WordPress shell itself.
	 *
	 * @param string $css Source CSS.
	 * @return string CSS guard rules.
	 */
	private static function wordpress_body_class_collision_guard_css( string $css ): string {
		$body_classes = array(
			'admin-bar',
			'archive',
			'attachment',
			'author',
			'blog',
			'category',
			'customize-support',
			'date',
			'error404',
			'home',
			'logged-in',
			'no-customize-support',
			'page',
			'page-child',
			'page-parent',
			'page-template',
			'page-template-default',
			'paged',
			'post-type-archive',
			'privacy-policy',
			'rtl',
			'search',
			'search-no-results',
			'search-results',
			'single',
			'tag',
			'wp-custom-logo',
			'wp-embed-responsive',
		);
		$collisions   = array();

		foreach ( self::layout_class_selectors_from_css( $css ) as $class_name ) {
			if ( in_array( $class_name, $body_classes, true ) ) {
				$collisions[] = $class_name;
			}
		}

		$collisions = array_values( array_unique( $collisions ) );
		if ( empty( $collisions ) ) {
			return '';
		}

		$rules = array();
		foreach ( $collisions as $class_name ) {
			$rules[] = 'body.' . $class_name;
		}

		return "\n/* Static Site Importer: keep imported wrapper classes from styling WordPress body classes. */\n"
			. implode( ', ', $rules ) . ' { width: auto; max-width: none; margin: 0; padding: 0; }' . "\n";
	}

	/**
	 * Find class selectors carrying layout geometry.
	 *
	 * @param string $css Source CSS.
	 * @return array<int,string> Class selectors whose rule carries layout geometry.
	 */
	private static function layout_class_selectors_from_css( string $css ): array {
		$css     = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		$classes = array();
		if ( '' === trim( $css ) || ! preg_match_all( '/([^{}@][^{}]*)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$body = (string) $match[2];
			if ( ! preg_match( '/(?:^|;)\s*(?:width|max-width|min-width|margin|margin-[a-z-]+|padding|padding-[a-z-]+)\s*:/i', $body ) ) {
				continue;
			}

			foreach ( explode( ',', (string) $match[1] ) as $selector ) {
				if ( preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)/', $selector, $class_matches ) ) {
					$classes = array_merge( $classes, $class_matches[1] );
				}
			}
		}

		return array_values( array_unique( $classes ) );
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
	 * @param string $css Source CSS from all delivered frontend stylesheets.
	 * @param string $markup Imported markup.
	 * @return string Additional frontend CSS rules.
	 */
	public static function admin_bar_top_chrome_css( string $css, string $markup = '' ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css;
		if ( '' === trim( $css ) || ! str_contains( $css, 'position' ) || ! str_contains( $css, 'top' ) ) {
			return '';
		}

		$positions = array();
		$tops      = array();
		$rules     = self::admin_bar_top_chrome_rules_from_css( $css, $positions, $tops );
		preg_match_all( '/\bclass\s*=\s*([\'"])(.*?)\1/is', $markup, $matches );
		foreach ( $matches[2] as $class_list ) {
			$classes = preg_split( '/\s+/', html_entity_decode( $class_list, ENT_QUOTES | ENT_HTML5 ) );
			if ( false === $classes ) {
				continue;
			}
			foreach ( array_intersect( $classes, array_keys( $positions ) ) as $position ) {
				foreach ( array_intersect( $classes, array_keys( $tops ) ) as $top_class ) {
					$rules = array_merge( $rules, self::admin_bar_top_rules( '.' . $position . '.' . $top_class, $tops[ $top_class ] ) );
				}
			}
		}
		if ( empty( $rules ) ) {
			return '';
		}

		return "\n/* Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar. */\n" . implode( "\n", array_unique( $rules ) ) . "\n";
	}

	/**
	 * Build admin-bar offset rules from one CSS scope.
	 *
	 * @param string $css CSS to inspect.
	 * @param array<string,bool> $positions Single fixed/sticky classes.
	 * @param array<string,string> $tops Single classes with top declarations.
	 * @return array<int, string> CSS rules.
	 */
	private static function admin_bar_top_chrome_rules_from_css( string $css, array &$positions, array &$tops ): array {
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
				$rules = array_merge( $rules, self::admin_bar_top_chrome_rules_from_css( $body, $positions, $tops ) );
				continue;
			}

			$is_fixed = (bool) preg_match( '/(?:^|;)\s*position\s*:\s*(?:fixed|sticky)\s*(?:!important\s*)?(?:;|$)/i', $body );
			$top      = self::css_declaration_value( $body, 'top' );

			$selectors = array();
			foreach ( self::top_level_selectors( $prelude ) as $selector ) {
				if ( preg_match( '/^\.([a-zA-Z_][a-zA-Z0-9_-]*)$/', $selector, $class ) ) {
					if ( $is_fixed ) {
						$positions[ $class[1] ] = true;
					}
					if ( null !== $top ) {
						$tops[ $class[1] ] = $top;
					}
				}
				if ( $is_fixed && null !== $top && self::selector_is_plausible_top_chrome( $selector ) ) {
					$selectors[] = $selector;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rules = array_merge( $rules, self::admin_bar_top_rules( implode( ', ', array_unique( $selectors ) ), $top ) );
		}

		return $rules;
	}

	/**
	 * Split a selector list at its top-level commas.
	 *
	 * A comma inside `:where()`, `:is()`, `:not()`, or an attribute value separates
	 * arguments, not selectors: `.slider :where(.a,.b)` is one complex selector,
	 * and `.b` inside it is not a selector of its own.
	 *
	 * @param string $prelude Selector list.
	 * @return array<int,string> Trimmed, non-empty selectors.
	 */
	private static function top_level_selectors( string $prelude ): array {
		$selectors = array();
		$depth     = 0;
		$quote     = '';
		$start     = 0;
		$length    = strlen( $prelude );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $prelude[ $index ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$index;
				} elseif ( $quote === $char ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ( ')' === $char || ']' === $char ) && $depth > 0 ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$selectors[] = substr( $prelude, $start, $index - $start );
				$start       = $index + 1;
			}
		}
		$selectors[] = substr( $prelude, $start );

		return array_values( array_filter( array_map( 'trim', $selectors ), static fn ( string $selector ): bool => '' !== $selector ) );
	}

	/**
	 * Offset top chrome except where core makes the admin bar absolute.
	 *
	 * Each selector gets a `:root` prefix so the offset outranks its source rule, which may live in a
	 * carried stylesheet that loads after style.css.
	 *
	 * @param string $selector Selector list.
	 * @param string $top Source top declaration.
	 * @return array<int,string> Frontend rules.
	 */
	private static function admin_bar_top_rules( string $selector, string $top ): array {
		$offset = self::admin_bar_offset_top_value( $top );
		if ( null === $offset ) {
			return array();
		}
		$selector = implode( ', ', array_map( static fn ( string $part ): string => ':root ' . $part, self::top_level_selectors( $selector ) ) );
		return array(
			$selector . ' { top: ' . $offset . '; }',
			'@media screen and (max-width: 600px) { ' . $selector . ' { top: ' . $top . '; } }',
		);
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
	 * @return string|null Offset top value, or null when unsafe to rewrite.
	 */
	private static function admin_bar_offset_top_value( string $top ): ?string {
		$top       = trim( $top );
		$important = '';
		if ( preg_match( '/\s*!important\s*$/i', $top ) ) {
			$important = ' !important';
			$top       = trim( preg_replace( '/\s*!important\s*$/i', '', $top ) ?? $top );
		}

		if ( '' === $top || preg_match( '/[;{}]/', $top ) || preg_match( '/^(?:auto|inherit|initial|revert|unset)$/i', $top ) || str_starts_with( $top, '-' ) ) {
			return null;
		}

		if ( '0' === $top ) {
			$top = '0px';
		}
		return 'calc(' . $top . ' + var(--wp-admin--admin-bar--height, 0px))' . $important;
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
