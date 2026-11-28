<?php
/**
 * Exact imported post routes, shipped independently in generated runtimes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Post_Source_Route_Runtime', false ) ) {
	final class Static_Site_Importer_Post_Source_Route_Runtime {
		/** Register the option-backed route owner once across generated packages. */
		public static function register(): void {
			if ( defined( 'STATIC_SITE_IMPORTER_POST_SOURCE_ROUTE_RUNTIME_REGISTERED' ) ) {
				return;
			}
			define( 'STATIC_SITE_IMPORTER_POST_SOURCE_ROUTE_RUNTIME_REGISTERED', true );
			add_filter( 'rewrite_rules_array', array( self::class, 'post_source_rewrite_rules' ) );
			add_filter( 'post_link', array( self::class, 'post_source_permalink' ), 10, 2 );
			add_filter( 'page_link', array( self::class, 'page_source_permalink' ), 10, 2 );
			add_filter( 'redirect_canonical', array( self::class, 'post_source_canonical' ), 10, 2 );
		}

		/** Exact ID rules precede archives and slug guessing, including mixed shapes. */
		public static function post_source_rewrite_rules( array $rules ): array {
			$exact = array();
			foreach ( (array) get_option( 'static_site_importer_post_routes', array() ) as $id => $route ) {
				$query = 'page' === get_post_type( $id ) ? 'page_id=' : 'p=';
				$exact[ '^' . preg_quote( ltrim( rawurldecode( $route ), '/' ), '#' ) . '/?$' ] = 'index.php?' . $query . (int) $id;
			}
			// Declared native date archives answer their source route and its pages.
			foreach ( (array) get_option( 'static_site_importer_date_archive_routes', array() ) as $route => $query ) {
				$path  = preg_quote( trim( rawurldecode( (string) $route ), '/' ), '#' );
				$query = 'index.php?' . http_build_query( array_map( 'intval', (array) $query ) );
				$exact[ '^' . $path . '/page/?([0-9]{1,})/?$' ] = $query . '&paged=$matches[1]';
				$exact[ '^' . $path . '/?$' ]                   = $query;
			}
			return $exact + $rules;
		}

		/** Use source routes without changing unrelated post permalinks. */
		public static function post_source_permalink( string $url, WP_Post $post ): string {
			$routes = get_option( 'static_site_importer_post_routes', array() );
			if ( ! isset( $routes[ $post->ID ] ) ) {
				return $url;
			}
			$route = $routes[ $post->ID ];
			return home_url( str_contains( basename( $route ), '.' ) ? $route : user_trailingslashit( $route, 'single' ) );
		}

		/** Apply the same ID-backed permalink contract to flattened pages. */
		public static function page_source_permalink( string $url, int $post_id ): string {
			$post = get_post( $post_id );
			return $post instanceof WP_Post ? self::post_source_permalink( $url, $post ) : $url;
		}

		/** Do not append core's trailing slash to source .html URLs. */
		public static function post_source_canonical( $redirect, string $requested ) {
			if ( is_singular( array( 'post', 'page' ) ) ) {
				$id     = get_queried_object_id();
				$routes = get_option( 'static_site_importer_post_routes', array() );
				if ( isset( $routes[ $id ] ) && wp_parse_url( $requested, PHP_URL_PATH ) === wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) ) {
					return false;
				}
			}
			return $redirect;
		}
	}
}
