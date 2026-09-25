<?php
/**
 * Redirects source-file routes to materialized WordPress permalinks.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a materialized page's original static path onto a 301.
 *
 * WordPress permalinks drop `.html` / `index.html`, so inbound links to the
 * source site 404 after import. The public source route is stored as post
 * meta at materialization; this runtime looks it up only on 404s.
 */
final class Static_Site_Importer_Source_Route_Redirect {
	public const META_KEY = '_static_site_importer_source_route';

	public static function register(): void {
		if ( ! empty( $GLOBALS['static_site_importer_source_route_redirect_registered'] ) || ! function_exists( 'add_action' ) ) {
			return;
		}
		$GLOBALS['static_site_importer_source_route_redirect_registered'] = true;
		add_action( 'template_redirect', array( self::class, 'redirect' ), 11 );
	}

	public static function redirect(): void {
		if ( ! empty( $GLOBALS['static_site_importer_source_route_redirected'] ) ) {
			return;
		}
		$GLOBALS['static_site_importer_source_route_redirected'] = true;
		if ( ! function_exists( 'is_404' ) || ! is_404() ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$request = '';
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->request ) && is_string( $GLOBALS['wp']->request ) ) {
			$request = $GLOBALS['wp']->request;
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
					'post_type'              => array( 'page', 'post' ),
					'post_status'            => 'publish',
					'meta_key'               => self::META_KEY,
					'meta_value'             => $value,
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'fields'                 => 'ids',
				)
			);
			if ( array() === $found ) {
				continue;
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
