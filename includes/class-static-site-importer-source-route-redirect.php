<?php
/**
 * Resolves imported source routes and redirects source-file aliases.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns exact imported directory routes and static-file aliases.
 *
 * WordPress permalinks drop `.html` / `index.html`, so inbound links to the
 * source site 404 after import. The public source route is stored as post
 * meta at materialization. Directory documents must resolve before a native
 * taxonomy or pagination query can serve another document at the same URL.
 */
final class Static_Site_Importer_Source_Route_Redirect {
	public const META_KEY = '_static_site_importer_source_route';

	public static function register(): void {
		if ( ! empty( $GLOBALS['static_site_importer_source_route_redirect_registered'] ) || ! function_exists( 'add_action' ) ) {
			return;
		}
		$GLOBALS['static_site_importer_source_route_redirect_registered'] = true;
		// Run before redirect_canonical so extension-bearing source paths resolve
		// to the imported permalink instead of being normalized by WordPress first.
		add_action( 'template_redirect', array( self::class, 'redirect' ), 9 );
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'request', array( self::class, 'resolve_request' ) );
			add_filter( 'page_link', array( self::class, 'filter_permalink' ), 20, 2 );
			add_filter( 'post_link', array( self::class, 'filter_permalink' ), 20, 2 );
			add_filter( 'post_type_link', array( self::class, 'filter_permalink' ), 20, 2 );
		}
	}

	/** Resolve only a published, unambiguous imported index document. */
	public static function resolve_request( array $query_vars ): array {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || isset( $query_vars['rest_route'] ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ) ) {
			return $query_vars;
		}
		$request = isset( $GLOBALS['wp']->request ) && is_string( $GLOBALS['wp']->request ) ? $GLOBALS['wp']->request : '';
		$path    = self::request_path( '' !== $request ? $request : (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( '' === $path ) {
			return $query_vars;
		}
		$id = self::find_post_id( $path . '/index.html' );
		if ( $id <= 0 || ! function_exists( 'get_post_type' ) ) {
			return $query_vars;
		}
		$type = get_post_type( $id );
		if ( ! is_string( $type ) || '' === $type ) {
			return $query_vars;
		}
		// An owned document is singular. Keep its endpoint state; archive
		// selectors from any native or custom taxonomy cannot constrain it.
		// The request's original query string remains available to the document.
		$query_vars = array_intersect_key( $query_vars, array_flip( array( 'feed', 'embed', 'cpage', 'preview', 'preview_id', 'preview_nonce', 'withcomments', 'withoutcomments' ) ) );
		$query_vars[ 'page' === $type ? 'page_id' : 'p' ] = $id;
		if ( 'page' !== $type ) {
			$query_vars['post_type'] = $type;
		}
		return $query_vars;
	}

	/** Canonical links use the same exact source route as request ownership. */
	public static function filter_permalink( string $permalink, $post ): string {
		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		if ( ! function_exists( 'get_option' ) || ! get_option( 'permalink_structure' ) ) {
			return $permalink;
		}
		$route = self::directory_route( $id );
		return null !== $route ? home_url( user_trailingslashit( $route ) ) : $permalink;
	}

	/** Shared route identity for canonical links and portable document export. */
	public static function directory_route( int $id ): ?string {
		if ( $id <= 0 || ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_post_status' ) || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		$routes = array();
		foreach ( (array) get_post_meta( $id, self::META_KEY, false ) as $source ) {
			$source = self::public_source_route( (string) $source );
			if ( str_ends_with( $source, '/index.html' ) ) {
				$routes[] = substr( $source, 0, -strlen( '/index.html' ) );
			}
		}
		$routes = array_values( array_unique( $routes ) );
		if ( 1 !== count( $routes ) || self::find_post_id( $routes[0] . '/index.html' ) !== $id ) {
			return null;
		}
		return $routes[0];
	}

	public static function redirect(): void {
		if ( ! empty( $GLOBALS['static_site_importer_source_route_redirected'] ) ) {
			return;
		}
		$GLOBALS['static_site_importer_source_route_redirected'] = true;
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$request = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( '' === $request && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->request ) && is_string( $GLOBALS['wp']->request ) ) {
			$request = $GLOBALS['wp']->request;
		}
		$path = self::request_path( $request );
		if ( ( ! function_exists( 'is_404' ) || ! is_404() ) && 1 !== preg_match( '~(?:^|/)[^/]+\.html?$~i', $path ) ) {
			return;
		}
		$target = self::target_url( '' !== $request ? $request : null );
		if ( ! is_string( $target ) || '' === $target || ! function_exists( 'wp_safe_redirect' ) ) {
			return;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Public source path a request for the original static file would use.
	 */
	public static function public_source_route( string $source_path ): string {
		$path = self::normalize_path( $source_path );
		if ( str_starts_with( $path, 'website/' ) ) {
			$path = substr( $path, strlen( 'website/' ) );
		}

		return $path;
	}

	public static function target_url( ?string $request_uri = null, ?string $query_string = null ): ?string {
		$path = self::request_path( $request_uri ?? (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( '' === $path || ! function_exists( 'get_permalink' ) ) {
			return null;
		}
		$id = self::find_post_id( $path );
		if ( $id <= 0 ) {
			return null;
		}
		$permalink = get_permalink( $id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}
		$permalink_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;
		$destination    = self::request_path( is_string( $permalink_path ) ? $permalink_path : '' );
		if ( $destination === $path ) {
			return null;
		}
		$query = $query_string;
		if ( null === $query ) {
			$query = (string) ( $_SERVER['QUERY_STRING'] ?? '' );
		}

		return self::with_query( $permalink, $query );
	}

	public static function request_path( string $uri ): string {
		if ( '' === $uri ) {
			return '';
		}
		$path = $uri;
		if ( preg_match( '/^([^?#]*)(.*)$/s', $uri, $parts ) ) {
			$path = $parts[1];
		}
		if ( str_contains( $path, '://' ) || str_starts_with( $path, '//' ) ) {
			return '';
		}
		$path = rawurldecode( $path );
		$home = '';
		if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
			$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$home      = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
		}
		if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return self::normalize_path( $path );
	}

	private static function find_post_id( string $path ): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}
		$candidates = array( $path );
		if ( ! str_starts_with( $path, 'website/' ) ) {
			$candidates[] = 'website/' . $path;
		}
		foreach ( $candidates as $value ) {
			$found = get_posts(
				array(
					'post_type'              => 'any',
					'post_status'            => 'publish',
					'meta_key'               => self::META_KEY,
					'meta_value'             => $value,
					'posts_per_page'         => 2,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'fields'                 => 'ids',
				)
			);
			if ( array() === $found ) {
				continue;
			}
			if ( 1 !== count( $found ) ) {
				return 0;
			}

			return (int) $found[0];
		}

		return 0;
	}

	private static function normalize_path( string $path ): string {
		$path     = str_replace( '\\', '/', $path );
		$path     = ltrim( $path, '/' );
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( array() === $segments ) {
					return '';
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	private static function with_query( string $url, string $query ): string {
		$query = ltrim( $query, '?&' );
		if ( '' === $query ) {
			return $url;
		}

		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
	}
}
