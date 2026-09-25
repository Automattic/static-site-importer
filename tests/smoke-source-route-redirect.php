<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url( string $path = '' ): string {
	return rtrim( $GLOBALS['ssi_redirect_home'], '/' ) . '/' . ltrim( $path, '/' );
}
function get_permalink( int $id ): string {
	return $GLOBALS['ssi_redirect_permalinks'][ $id ] ?? '';
}
function get_posts( array $args ): array {
	$value = (string) ( $args['meta_value'] ?? '' );
	$key   = (string) ( $args['meta_key'] ?? '' );
	$ids   = array();
	foreach ( $GLOBALS['ssi_redirect_meta'] as $id => $meta ) {
		if ( $key === ( $meta['key'] ?? '' ) && $value === ( $meta['value'] ?? '' ) ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? 1 ) );
}
function add_action( string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['ssi_redirect_actions'][] = array( $hook, $callback, $priority );
}

$GLOBALS['ssi_redirect_home']       = 'https://imported.test/';
$GLOBALS['ssi_redirect_permalinks'] = array();
$GLOBALS['ssi_redirect_meta']       = array();
$GLOBALS['ssi_redirect_actions']    = array();

require dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route-redirect.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( 'about-me.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/about-me.html' ), 'Artifact website/ prefix is not part of the public source route.' );
$assert( 'about-me.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'about-me.html' ), 'Root HTML files keep their source filename.' );
$assert( 'contact.htm' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'contact.htm' ), 'htm files keep their source filename.' );
$assert( 'foo/index.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/foo/index.html' ), 'Nested index documents keep their directory and index filename.' );
$assert( 'blog/merhaba-explorers/index.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/blog/merhaba-explorers/index.html' ), 'Nested blog documents keep the public source path.' );
$assert( 'index.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/index.html' ), 'The entry document public route is index.html.' );
$assert( '' === Static_Site_Importer_Source_Route_Redirect::public_source_route( '../escape.html' ), 'Source routes cannot escape the site root.' );

$GLOBALS['ssi_redirect_permalinks'] = array(
	11 => 'https://imported.test/about-me/',
	12 => 'https://imported.test/contact/',
	13 => 'https://imported.test/blog/merhaba-explorers/',
	14 => 'https://imported.test/',
);
$GLOBALS['ssi_redirect_meta']       = array(
	11 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'about-me.html',
	),
	12 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'contact.html',
	),
	13 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'blog/merhaba-explorers/index.html',
	),
	14 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'index.html',
	),
);

$assert( 'https://imported.test/about-me/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me.html' ), 'A source .html file redirects to the WordPress permalink.' );
$assert( 'https://imported.test/about-me/?utm=nav' === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me.html?utm=nav', 'utm=nav' ), 'Source-route redirects preserve the query string.' );
$assert( 'https://imported.test/contact/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/contact.html' ), 'Sibling HTML files redirect to their permalinks.' );
$assert( 'https://imported.test/blog/merhaba-explorers/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/blog/merhaba-explorers/index.html' ), 'Nested index.html source paths redirect to the page permalink.' );
$assert( 'https://imported.test/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/index.html' ), 'The source index document redirects to the front page permalink.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/missing.html' ), 'Unknown source paths do not redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '//evil.test/about-me.html' ), 'Protocol-relative URLs never redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( 'https://evil.test/about-me.html' ), 'Absolute URLs never redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me/' ), 'The WordPress permalink itself is not rewritten.' );

$GLOBALS['ssi_redirect_home'] = 'https://playground.test/scope:abc/';
$assert( 'https://imported.test/about-me/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/scope:abc/about-me.html' ), 'Subdirectory homes still match the source path after the home prefix.' );

$assert( ! method_exists( Static_Site_Importer_Source_Route_Redirect::class, 'prepare_overlay' ), 'Source-route redirects must not materialize a theme overlay.' );
$assert( ! method_exists( Static_Site_Importer_Source_Route_Redirect::class, 'theme_runtime_class' ), 'Source-route redirects must not own a theme-scoped class name.' );

$companion_source    = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php' );
$preparation_source  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-preparation.php' );
$assert( str_contains( $companion_source, "class-static-site-importer-source-route-redirect.php" ) && str_contains( $companion_source, "'/includes/source-route-redirect.php'" ) && str_contains( $companion_source, '_Source_Route_Redirect' ), 'The companion plugin must copy and register the source-route redirect runtime.' );
$assert( ! str_contains( $preparation_source, 'Static_Site_Importer_Source_Route_Redirect::prepare_overlay' ), 'Theme preparation must not overlay source-route redirects into functions.php.' );

$runtime_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route-redirect.php' );
$copy_dir       = sys_get_temp_dir() . '/ssi-source-route-copy-' . getmypid();
if ( ! is_dir( $copy_dir ) && ! mkdir( $copy_dir, 0777, true ) && ! is_dir( $copy_dir ) ) {
	throw new RuntimeException( 'Could not create companion runtime copy directory.' );
}
$copy_file = $copy_dir . '/source-route-redirect.php';
file_put_contents( $copy_file, str_replace( 'Static_Site_Importer_Source_Route_Redirect', 'SSI_TEST_SITE_Source_Route_Redirect', $runtime_source ) );
require $copy_file;

Static_Site_Importer_Source_Route_Redirect::register();
SSI_TEST_SITE_Source_Route_Redirect::register();
$assert( 1 === count( $GLOBALS['ssi_redirect_actions'] ), 'SSI and the companion copy must not both register template_redirect.' );
$assert( 'template_redirect' === ( $GLOBALS['ssi_redirect_actions'][0][0] ?? '' ) && 11 === ( $GLOBALS['ssi_redirect_actions'][0][2] ?? 0 ), 'The single source-route runtime registers template_redirect at priority 11.' );
$assert( array( Static_Site_Importer_Source_Route_Redirect::class, 'redirect' ) === ( $GLOBALS['ssi_redirect_actions'][0][1] ?? null ) || array( SSI_TEST_SITE_Source_Route_Redirect::class, 'redirect' ) === ( $GLOBALS['ssi_redirect_actions'][0][1] ?? null ), 'The registered callback belongs to exactly one runtime class.' );

foreach ( array( $copy_file, $copy_dir ) as $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	} elseif ( is_dir( $path ) ) {
		rmdir( $path );
	}
}

echo "source route redirect smoke passed\n";
