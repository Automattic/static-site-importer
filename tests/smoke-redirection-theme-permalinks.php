<?php
/** WordPress-runtime regression: redirects use the newly activated theme's post_link. */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$root = dirname( __DIR__ );
require_once $root . '/static-site-importer.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require_once $root . '/includes/class-static-site-importer-redirection-materializer.php';
require_once $root . '/includes/class-static-site-importer-redirects-manifest.php';

$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$compiler = new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
$plan = $compiler->compile(
	array(
		'entrypoint' => 'index.html',
		'files' => array(
			'index.html' => '<main><h1>Home</h1></main>',
			'en/infosec/index.html' => '<html><head><meta property="article:published_time" content="2024-11-04T10:00:00Z"></head><body><main><h1>Infosec</h1></main></body></html>',
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$slug = 'ssi-redirect-theme-' . wp_generate_password( 8, false, false );
$stylesheet = get_stylesheet();
$structure = get_option( 'permalink_structure' );
$hooks = $GLOBALS['wp_filter'];
// Clone hook objects so theme callbacks can be removed even if the smoke fails.
foreach ( $hooks as $name => $hook ) {
	$hooks[ $name ] = clone $hook;
}
$receipt = array();
try {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
	$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
		$plan,
		array( 'slug' => $slug, 'activate' => true )
	);
	$assert( 'completed' === ( $receipt['status'] ?? '' ), 'Plan must materialize successfully: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
	$id = (int) ( $receipt['completed']['pages']['en/infosec/index.html'] ?? 0 );
	$assert( $id > 0 && 'post' === get_post_type( $id ), 'Dated prefixed document must be a real post.' );
	$assert( str_contains( get_permalink( $id ), '/2024/11/04/' ), 'Reproduce the materializing request before theme functions load.' );
	$routes = Static_Site_Importer_Redirection_Materializer::routes(
		$receipt,
		array( 'source_route_aliases' => array( array( 'from' => 'old-infosec', 'to' => 'en/infosec/index.html' ) ) )
	);
	$assert( ! is_wp_error( $routes ), 'Redirect routes must resolve.' );
	$canonical = home_url( user_trailingslashit( '/en/infosec' ) );
	$assert( $canonical === get_permalink( $id ), 'Activated theme post_link must preserve the source permalink.' );
	$assert( ! isset( $routes['/en/infosec'] ) && ! isset( $routes['/en/infosec/'] ), 'No rule may redirect away from the canonical source route.' );
	$assert( array( '/old-infosec' => $canonical ) === $routes, 'Only the moved alias redirects, to the theme-owned canonical URL.' );
	$assert( $routes === Static_Site_Importer_Redirection_Materializer::routes( $receipt, array( 'source_route_aliases' => array( array( 'from' => 'old-infosec', 'to' => 'en/infosec/index.html' ) ) ) ), 'Loading theme functions is idempotent.' );
} finally {
	switch_theme( $stylesheet );
	$GLOBALS['wp_filter'] = $hooks;
	$wp_rewrite->set_permalink_structure( $structure );
	foreach ( $receipt['completed']['pages'] ?? array() as $id ) {
		wp_delete_post( (int) $id, true );
	}
	if ( is_dir( get_theme_root() . '/' . $slug ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		delete_theme( $slug );
	}
}
echo "Theme-owned redirect permalinks passed.\n";
