<?php
/**
 * Standalone smoke: source routes work through the generated companion alone.
 *
 * @package StaticSiteImporter
 */

// Reuse the companion scaffolder's standalone WordPress doubles.
define( 'SSI_COMPANION_DOUBLES_ONLY', true );
require __DIR__ . '/smoke-companion-plugin.php';

class WP_Post {
	public function __construct( public int $ID, public string $post_type = 'post' ) {}
}
function home_url( string $path = '' ): string {
	return 'https://example.org' . $path;
}
function user_trailingslashit( string $path, string $type = '' ): string {
	return rtrim( $path, '/' ) . '/';
}
function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}
function is_singular( string $type ): bool {
	return 'post' === $type && ! empty( $GLOBALS['route_queried_id'] );
}
function get_queried_object_id(): int {
	return $GLOBALS['route_queried_id'] ?? 0;
}
function route_filter( string $hook, mixed $value, mixed ...$args ): mixed {
	foreach ( $GLOBALS['ssi_companion_registered_filters'][ $hook ] ?? array() as $entry ) {
		$value = ( $entry[0] )( $value, ...array_slice( $args, 0, $entry[2] - 1 ) );
	}
	return $value;
}
function get_permalink( int $id ): string {
	return route_filter( 'post_link', home_url( '/2024/10/23/post-' . $id . '/' ), new WP_Post( $id ) );
}
$check = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
foreach ( array( 'rewrite_rules_array', 'post_link', 'redirect_canonical' ) as $hook ) {
	$check( empty( $GLOBALS['ssi_companion_registered_filters'][ $hook ] ), 'SSI must not register route hooks: ' . $hook );
}
$check( ! class_exists( 'Static_Site_Importer_Site_Plan_Persistence', false ), 'SSI persistence must not be loaded' );
$payload = array(
	'schema'             => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug'          => 'post-routes',
	'site_name'          => 'Post routes',
	'blocks'             => array(),
	'post_source_routes' => true,
);
$check( Static_Site_Importer_Companion_Plugin::has_materializable_content( $payload ), 'Routes alone must require a companion' );
$package = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$check( ! is_wp_error( $package ), 'Route-only companion must scaffold' );
$dir = sys_get_temp_dir() . '/ssi-post-routes-' . getmypid();
try {
	foreach ( $package['files'] as $path => $content ) {
		wp_mkdir_p( dirname( $dir . '/' . $path ) );
		file_put_contents( $dir . '/' . $path, $content );
	}
	$GLOBALS['ssi_companion_registered_filters'] = array();
	require $dir . '/ssi-post-routes/ssi-post-routes.php';
	// Re-inclusion of the embedded class is safe, and registration is idempotent.
	include $dir . '/ssi-post-routes/includes/post-source-route-runtime.php';
	SSI_POST_ROUTES_Post_Source_Route_Runtime::register();
	foreach ( array( 'rewrite_rules_array', 'post_link', 'redirect_canonical' ) as $hook ) {
		$callbacks = $GLOBALS['ssi_companion_registered_filters'][ $hook ] ?? array();
		$check( 1 === count( $callbacks ) && SSI_POST_ROUTES_Post_Source_Route_Runtime::class === $callbacks[0][0][0], 'Generated companion must own ' . $hook );
	}
	$routes = array( 101 => '/communities/a', 102 => '/communities/a-duplicate', 103 => '/news/c.html' );
	$GLOBALS['ssi_companion_options']['static_site_importer_post_routes'] = $routes;
	$rules = route_filter( 'rewrite_rules_array', array( '^unrelated/?$' => 'index.php?p=999' ) );
	foreach ( $routes as $id => $route ) {
		$url = home_url( str_ends_with( $route, '.html' ) ? $route : $route . '/' );
		$check( $url === get_permalink( $id ), 'Permalink must use source route' );
		$target = null;
		foreach ( $rules as $regex => $query ) {
			if ( preg_match( '#' . $regex . '#', ltrim( $route, '/' ) ) ) {
				$target = $query;
				break;
			}
		}
		$check( 'index.php?p=' . $id === $target, 'Source route must resolve exactly by post ID' );
		$GLOBALS['route_queried_id'] = $id;
		$check( false === route_filter( 'redirect_canonical', $url . '/', $url . '?ref=source' ), 'Source route must not redirect' );
		$check( 'core-redirect' === route_filter( 'redirect_canonical', 'core-redirect', home_url( '/wrong/' ) ), 'Other canonical redirects must remain intact' );
	}
	$check( home_url( '/2024/10/23/post-999/' ) === get_permalink( 999 ), 'Unimported post permalink must not change' );
	$check( 'page-url' === route_filter( 'post_link', 'page-url', new WP_Post( 101, 'page' ) ), 'Page permalink must not change' );
	$check( isset( $rules['^unrelated/?$'] ), 'Unrelated rewrite rules must remain intact' );
	$GLOBALS['ssi_companion_options']['static_site_importer_post_routes'] = array();
	$check( home_url( '/2024/10/23/post-101/' ) === get_permalink( 101 ), 'Runtime must reread options after rollback' );
	$check( array() === route_filter( 'rewrite_rules_array', array() ), 'Removed ownership must remove exact rules' );
	$theme = Static_Site_Importer_Companion_Plugin::scaffold( $payload, 'theme', 'route-theme' );
	$check( ! is_wp_error( $theme ) && str_contains( $theme['files']['ssi-post-routes/includes/post-source-route-runtime.php'], 'SSI_THEME_ROUTE_THEME_Post_Source_Route_Runtime' ), 'Theme runtime must have its own class prefix' );
	echo "Post source routes smoke passed.\n";
} finally {
	$remove = static function ( string $path ) use ( &$remove ): void {
		foreach ( glob( $path . '/*' ) ?: array() as $child ) {
			is_dir( $child ) ? $remove( $child ) : unlink( $child );
		}
		rmdir( $path );
	};
	if ( is_dir( $dir ) ) {
		$remove( $dir );
	}
}
