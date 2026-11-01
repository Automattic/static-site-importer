<?php
/**
 * Supports captured member sign-in controls and core/tabs deep links.
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
 * Interaction support only. Ordinary internal links are resolved at import.
 */
final class Static_Site_Importer_Internal_Link_Runtime {
	private static bool $registered = false;

	private static bool $tab_query_enqueued = false;

	public static function register(): void {
		if ( self::$registered || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$registered = true;
		add_filter( 'the_content', array( self::class, 'filter_member_login' ), 8 );
		add_filter( 'render_block', array( self::class, 'filter_member_login' ), 10, 1 );
		add_filter( 'render_block_core/tabs', array( self::class, 'maybe_enqueue_tab_query_script' ), 10, 1 );
	}

	/**
	 * Select a core Tabs tab named by the source's `?tab=` deep link.
	 *
	 * Enqueued only when a rendered core/tabs block has panels that carry a
	 * source tab key as their id (generated ids start with
	 * `blocks-engine-set-`). The script activates the real core tab by
	 * clicking it, so core's own store selects the panel. It never changes the
	 * URL and ignores values that match no tab.
	 *
	 * @param mixed $content Rendered core/tabs markup.
	 * @return mixed
	 */
	public static function maybe_enqueue_tab_query_script( $content ) {
		if ( self::$tab_query_enqueued || ! is_string( $content ) || ! function_exists( 'wp_register_script' ) || ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'wp_add_inline_script' ) ) {
			return $content;
		}
		if ( ! preg_match_all( '~<section\b[^>]*\brole="tabpanel"[^>]*>~i', $content, $panels ) ) {
			return $content;
		}
		foreach ( $panels[0] as $panel ) {
			if ( preg_match( '~\sid="([^"]+)"~', $panel, $id ) && ! str_starts_with( $id[1], 'blocks-engine-set-' ) ) {
				self::$tab_query_enqueued = true;
				wp_register_script( 'static-site-importer-tab-query', false, array(), '1', true );
				wp_enqueue_script( 'static-site-importer-tab-query' );
				wp_add_inline_script( 'static-site-importer-tab-query', self::tab_query_script() );
				break;
			}
		}

		return $content;
	}

	public static function tab_query_script(): string {
		return '(function(){var key=new URLSearchParams(window.location.search).get("tab");if(!key)return;var tries=0;function select(){var tab=document.getElementById("tab__"+key);if(!tab||tab.getAttribute("role")!=="tab")return;if(tab.getAttribute("aria-selected")==="true")return;tab.click();if(++tries<50)setTimeout(select,100);}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",select);else select();})();';
	}

	/** @param mixed $content */
	public static function filter_member_login( $content ) {
		return is_string( $content ) ? self::resolve_member_login_links( $content ) : $content;
	}

	/**
	 * Point member sign-in controls at this site's login.
	 *
	 * A capture producer marks the source platform's member sign-in entry
	 * points once it has removed the platform's own login, and leaves the
	 * target to the destination. The marker is the capture-evidence member
	 * sign-in class `<prefix><provider>` (block conversion keeps classes),
	 * with the member sign-in attribute where it survived. Both names come
	 * from {@see Static_Site_Importer_Capture_Evidence}; generated theme and
	 * companion copies receive them inlined. Here the target is
	 * `wp_login_url()`, returning the reader to the page they signed in from.
	 *
	 * - A marked link gets that `href`, replacing any source one (the old
	 *   platform's members area).
	 * - A marked button, or the button inside a marked core/button wrapper,
	 *   becomes a link to it with the same classes and children (icon and
	 *   label), since signing in is navigation once the platform's dialog is
	 *   gone.
	 */
	public static function resolve_member_login_links( string $content ): string {
		$attribute = Static_Site_Importer_Capture_Evidence::MEMBER_LOGIN;
		$prefix    = Static_Site_Importer_Capture_Evidence::MEMBER_LOGIN_CLASS_PREFIX;
		// The class prefix extends the attribute name, so one check finds either marker.
		if ( ! str_contains( $content, $attribute ) || ! function_exists( 'wp_login_url' ) ) {
			return $content;
		}
		$login  = esc_url( wp_login_url( self::current_url() ) );
		$marked = '(?=[^>]*(?:\s' . preg_quote( $attribute, '~' ) . '(?=[\s=/>])|\sclass\s*=\s*["\'][^"\']*(?<![\w-])' . preg_quote( $prefix, '~' ) . '[a-z0-9_-]+))';
		$href   = '~\s+href\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i';
		$type   = '~\s+type\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i';
		$link   = static fn( string $attributes ): string => '<a href="' . $login . '"' . (string) preg_replace( array( $href, $type ), '', $attributes ) . '>';

		// The button inside a marked core/button wrapper.
		$content = preg_replace_callback(
			'~(<div\b' . $marked . '[^>]*>\s*)<button\b([^>]*)>(.*?)</button>~is',
			static fn( array $m ): string => $m[1] . $link( $m[2] ) . $m[3] . '</a>',
			$content
		) ?? $content;
		// A marked button itself (buttons cannot nest, so the first close is its own).
		$content = preg_replace_callback(
			'~<button\b' . $marked . '([^>]*)>(.*?)</button>~is',
			static fn( array $m ): string => $link( $m[1] ) . $m[2] . '</a>',
			$content
		) ?? $content;

		return preg_replace_callback(
			'~<a\b' . $marked . '([^>]*)>~i',
			static fn( array $m ): string => $link( $m[1] ),
			$content
		) ?? $content;
	}

	/**
	 * The requested URL, for the post-login redirect. wp-login.php validates
	 * `redirect_to` itself, so a foreign host cannot be smuggled through it.
	 */
	private static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only passed to wp_login_url(), whose redirect wp-login.php validates.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same as above.
		if ( '' === $host || '' === $uri ) {
			return '';
		}

		return ( function_exists( 'is_ssl' ) && is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
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
	 * Materialize only tab deep links and member sign-in support in the theme.
	 *
	 * @param array<string,mixed> $resolved_plan
	 * @param array<string,mixed> $bootstrap_overlay
	 * @return array<string,mixed>
	 */
	public static function prepare_interaction_overlay( array $resolved_plan, array $bootstrap_overlay = array(), string $theme_slug = '' ): array {
		$bootstrap = self::interaction_bootstrap( $resolved_plan, $bootstrap_overlay );
		// The theme copy gets a theme-scoped class name, so it can never
		// collide with this plugin class or with another generated theme, and
		// carries the capture-evidence names it reads as literals.
		$class  = self::theme_runtime_class( $theme_slug );
		$marker = '/* Static Site Importer tab and member interactions. */';
		$source = file_get_contents( __FILE__ ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the runtime source the generated theme owns independently.
		if ( ! is_string( $source ) || '' === $source ) {
			return isset( $bootstrap_overlay['writes'] ) ? $bootstrap_overlay : array(
				'status' => 'skipped',
				'writes' => array(),
			);
		}
		if ( ! str_contains( $bootstrap, $marker ) ) {
			$bootstrap .= "\n{$marker}\nif ( ! class_exists( '{$class}' ) ) {\n\trequire_once get_stylesheet_directory() . '/captured-interactions.php';\n}\n{$class}::register();\n";
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
					'target_path' => 'captured-interactions.php',
					'content'     => Static_Site_Importer_Capture_Evidence::inline( str_replace( 'Static_Site_Importer_Internal_Link_Runtime', $class, $source ) ),
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
	private static function interaction_bootstrap( array $resolved_plan, array $overlay ): string {
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
