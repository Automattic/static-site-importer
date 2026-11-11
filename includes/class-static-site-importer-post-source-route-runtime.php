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
			add_filter( 'redirect_canonical', array( self::class, 'post_source_canonical' ), 10, 2 );
		}

		/** Exact ID rules precede archives and slug guessing, including mixed shapes. */
		public static function post_source_rewrite_rules( array $rules ): array {
			$exact = array();
			foreach ( (array) get_option( 'static_site_importer_post_routes', array() ) as $id => $route ) {
				$exact[ '^' . preg_quote( ltrim( $route, '/' ), '#' ) . '/?$' ] = 'index.php?p=' . (int) $id;
			}
			return $exact + $rules;
		}

		/** Use source routes without changing unrelated post permalinks. */
		public static function post_source_permalink( string $url, WP_Post $post ): string {
			$routes = get_option( 'static_site_importer_post_routes', array() );
			if ( 'post' !== $post->post_type || ! isset( $routes[ $post->ID ] ) ) {
				return $url;
			}
			$route = $routes[ $post->ID ];
			return home_url( str_contains( basename( $route ), '.' ) ? $route : user_trailingslashit( $route, 'single' ) );
		}

		/** Do not append core's trailing slash to source .html URLs. */
		public static function post_source_canonical( $redirect, string $requested ) {
			if ( is_singular( 'post' ) ) {
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
