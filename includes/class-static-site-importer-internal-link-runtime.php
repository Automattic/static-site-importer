<?php
/**
 * Resolves portable internal post-id references against the current site.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Themes generated before theme-scoped runtimes shipped a guarded copy under
// this class name. When one of those themes loads first (for example while
// the plugin is being activated), reuse its copy instead of redeclaring it.
if ( class_exists( 'Static_Site_Importer_Internal_Link_Runtime', false ) ) {
	return;
}

/**
 * Turns importer-owned `/?p=` / `/?page_id=` references into destination permalinks.
 *
 * SSI never sees the site that will serve the content, so import-time
 * `get_permalink()` is the wrong resolver. Stored references stay valid when
 * this runtime is absent (WordPress canonicalizes them); the filter makes the
 * delivered markup match the destination structure, including after a later
 * permalink change.
 */
final class Static_Site_Importer_Internal_Link_Runtime {
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$registered = true;
		add_filter( 'the_content', array( self::class, 'filter_content' ), 8 );
		add_filter( 'render_block', array( self::class, 'filter_rendered_block' ), 10, 1 );
	}

	/**
	 * Resolve root-relative links in rendered blocks outside post content.
	 *
	 * Template parts and templates (header navigation, footer links) keep the
	 * source site's root-relative routes, such as `/about`. Those only work when
	 * WordPress serves the site from a domain root with matching permalinks. A
	 * Playground scope, a subdirectory install, or plain permalinks all break
	 * them. A route that names a page resolves to that page's permalink; any
	 * other root-relative link is rebased onto the site's home path.
	 *
	 * @param mixed $content Rendered block markup.
	 * @return mixed
	 */
	public static function filter_rendered_block( $content ) {
		if ( ! is_string( $content ) || ! str_contains( $content, 'href="/' ) || ! function_exists( 'home_url' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'~(\bhref=")(/(?!/)[^"]*)(")~i',
			static fn( array $matches ): string => $matches[1] . self::resolve_root_relative( $matches[2] ) . $matches[3],
			$content
		) ?? $content;
	}

	public static function resolve_root_relative( string $url ): string {
		static $resolved = array();
		if ( isset( $resolved[ $url ] ) ) {
			return $resolved[ $url ];
		}
		$path   = $url;
		$suffix = '';
		if ( preg_match( '/^([^?#]*)(.*)$/s', $url, $parts ) ) {
			$path   = $parts[1];
			$suffix = $parts[2];
		}
		if ( '' !== $suffix && '?' === $suffix[0] && '' === trim( $path, '/' ) ) {
			// `/?page_id=N` and `/?p=N` are portable post references.
			$portable = substr( self::resolve_urls( 'href="' . $url . '"' ), 6, -1 );

			$resolved[ $url ] = ( $portable !== $url ) ? $portable : home_url( $url );

			return $resolved[ $url ];
		}
		$slug = trim( $path, '/' );
		if ( '' === $slug ) {
			$resolved[ $url ] = self::join_reference_suffix( home_url( '/' ), $suffix );

			return $resolved[ $url ];
		}
		if ( function_exists( 'get_page_by_path' ) && function_exists( 'get_permalink' ) ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				$permalink = get_permalink( (int) $page->ID );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$resolved[ $url ] = self::join_reference_suffix( $permalink, $suffix );

					return $resolved[ $url ];
				}
			}
		}
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		$resolved[ $url ] = ( '' === $home_path || '/' === $home_path ) ? $url : home_url( $url );

		return $resolved[ $url ];
	}

	/** @param mixed $content */
	public static function filter_content( $content ) {
		return is_string( $content ) ? self::resolve_urls( $content ) : $content;
	}

	public static function resolve_urls( string $content ): string {
		if ( ! function_exists( 'get_permalink' ) ) {
			return $content;
		}
		$replace = static function ( array $matches ): string {
			$value = (string) $matches[2];
			if ( ! preg_match( '~^(?:[a-z][a-z0-9+.-]*:)?(?://[^/?#]+)?/\?(?:page_id|p)=([0-9]+)(.*)$~i', $value, $parts ) ) {
				return $matches[0];
			}
			$permalink = get_permalink( (int) $parts[1] );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				return $matches[0];
			}
			return $matches[1] . self::join_reference_suffix( $permalink, (string) $parts[2] ) . $matches[3];
		};
		foreach ( array(
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*["\'])([^"\']+)(["\'])/i',
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\")([^"\\\\]*)(\\\\")/i',
			'/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)/i',
			'/(["\'](?:url|href|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i',
		) as $pattern ) {
			$content = preg_replace_callback( $pattern, $replace, $content ) ?? $content;
		}

		return $content;
	}

	public static function join_reference_suffix( string $url, string $suffix ): string {
		if ( '' === $suffix ) {
			return $url;
		}
		$fragment = '';
		$query    = $suffix;
		$hash_at  = strpos( $suffix, '#' );
		if ( false !== $hash_at ) {
			$query    = substr( $suffix, 0, $hash_at );
			$fragment = substr( $suffix, $hash_at );
		}
		$query = ltrim( $query, '?&' );
		if ( '' !== $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
		}

		return $url . $fragment;
	}

	/**
	 * Materialize the resolver into the generated theme so imported sites keep
	 * destination permalinks after the importer plugin is gone.
	 *
	 * @param array<string,mixed> $resolved_plan
	 * @param array<string,mixed> $bootstrap_overlay
	 * @return array<string,mixed>
	 */
	public static function prepare_overlay( array $resolved_plan, array $bootstrap_overlay = array(), string $theme_slug = '' ): array {
		$bootstrap = self::bootstrap_content( $resolved_plan, $bootstrap_overlay );
		// The theme copy gets a theme-scoped class name, so it can never
		// collide with this plugin class or with another generated theme.
		$class  = self::theme_runtime_class( $theme_slug );
		$marker = '/* Static Site Importer portable internal links. */';
		$source = file_get_contents( __FILE__ ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the runtime source the generated theme owns independently.
		if ( ! is_string( $source ) || '' === $source ) {
			return isset( $bootstrap_overlay['writes'] ) ? $bootstrap_overlay : array(
				'status' => 'skipped',
				'writes' => array(),
			);
		}
		if ( ! str_contains( $bootstrap, $marker ) ) {
			$bootstrap .= "\n{$marker}\nif ( ! class_exists( '{$class}' ) ) {\n\trequire_once get_stylesheet_directory() . '/portable-internal-links.php';\n}\n{$class}::register();\n";
		}

		return array(
			'status' => 'materialized',
			'writes' => array(
				array(
					'target_path' => 'functions.php',
					'content'     => $bootstrap,
					'encoding'    => 'utf8',
					'source_path' => 'static-site-importer/portable-internal-links',
				),
				array(
					'target_path' => 'portable-internal-links.php',
					'content'     => str_replace( 'Static_Site_Importer_Internal_Link_Runtime', $class, $source ),
					'encoding'    => 'utf8',
					'source_path' => 'static-site-importer/portable-internal-links',
				),
			),
		);
	}

	/**
	 * Theme-scoped class name for the portable runtime copy.
	 */
	public static function theme_runtime_class( string $theme_slug ): string {
		$scope = strtoupper( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $theme_slug ), '_' ) );
		return 'SSI_Theme_' . ( '' !== $scope ? $scope : 'Default' ) . '_Internal_Link_Runtime';
	}

	/** @param array<string,mixed> $resolved_plan @param array<string,mixed> $overlay */
	private static function bootstrap_content( array $resolved_plan, array $overlay ): string {
		foreach ( array_reverse( $overlay['writes'] ?? array() ) as $write ) {
			if ( is_array( $write ) && 'functions.php' === ( $write['target_path'] ?? null ) && is_string( $write['content'] ?? null ) ) {
				return $write['content'];
			}
		}
		foreach ( $resolved_plan['writes'] ?? array() as $write ) {
			if ( ! is_array( $write ) || 'functions.php' !== ( $write['target_path'] ?? null ) || ! is_array( $write['payload'] ?? null ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a declared plan payload encoding.
			$content = 'base64' === ( $write['payload']['encoding'] ?? 'utf8' ) ? base64_decode( (string) ( $write['payload']['data'] ?? '' ), true ) : $write['payload']['data'] ?? null;
			if ( is_string( $content ) && str_starts_with( ltrim( $content ), '<?php' ) ) {
				return $content;
			}
		}
		return "<?php\n";
	}
}
