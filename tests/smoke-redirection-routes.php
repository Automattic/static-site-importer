<?php
/** Pure route binding contract; the real provider is exercised in acceptance. */
define( 'ABSPATH', '/' );
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}
function get_option( $key, $default = false ) { return $default; }
function get_post_status( $id ) { return 'publish'; }
function get_permalink( $id ) { return $GLOBALS['permalinks'][ $id ] ?? 'https://native.test/' . ( 1 === $id ? '' : 'native-' . $id . '/' ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function sanitize_title_for_query( $value ) { return $value; }
function get_posts( $args ) { return $GLOBALS['route_owners'] ?? array(); }
function get_page_uri( $post ) { return $post->path; }
require dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-redirects-manifest.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-redirection-materializer.php';
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$pages = array(
	array( 'source_path' => 'website/index.html', 'route' => array( 'path' => '/' ) ),
	array( 'source_path' => 'website/about.html', 'route' => array( 'path' => '/about' ) ),
	array( 'source_path' => 'website/notes/deep/index.html', 'route' => array( 'path' => '/tag/topic/page/1' ) ),
);
$receipt = array( 'plan' => array( 'pages' => $pages ), 'completed' => array( 'pages' => array( 'website/index.html' => 1, 'website/about.html' => 2, 'website/notes/deep/index.html' => 3 ) ) );
$assert( Static_Site_Importer_Redirection_Materializer::has_intent( $receipt['plan'], array() ), 'Differing source routes require Redirection.' );
$assert( ! Static_Site_Importer_Redirection_Materializer::has_intent( array( 'pages' => array() ), array() ), 'Empty imports have no route intent.' );
$routes = Static_Site_Importer_Redirection_Materializer::routes( $receipt, array() );
$assert( is_array( $routes ) && array_keys( $routes ) === array( '/about', '/about.html', '/notes/deep', '/tag/topic/page/1' ), 'Served file, directory and declared routes bind without capture index aliases.' );
$assert( 'https://native.test/native-3/' === $routes['/tag/topic/page/1'], 'Native taxonomy/pagination-shaped source routes redirect rather than override permalinks.' );
$receipt['completed']['pages']['website/about.html'] = 0;
$routes = Static_Site_Importer_Redirection_Materializer::routes( $receipt, array() );
$assert( ! isset( $routes['/about.html'] ), 'Uncommitted documents cannot claim routes.' );
$receipt['completed']['pages']['website/about.html'] = 2;
$GLOBALS['route_owners'] = array( (object) array( 'ID' => 99, 'post_status' => 'publish', 'path' => 'about' ) );
$error = Static_Site_Importer_Redirection_Materializer::routes( $receipt, array() );
$assert( $error instanceof WP_Error && 'static_site_importer_redirect_route_occupied' === $error->code, 'Published content cannot be shadowed.' );
$GLOBALS['route_owners'] = array();
$receipt['plan']['pages'][2]['route']['path'] = '/about';
$error = Static_Site_Importer_Redirection_Materializer::routes( $receipt, array() );
$assert( $error instanceof WP_Error && 'static_site_importer_redirect_ambiguous' === $error->code, 'Ambiguous source claims fail loudly.' );
$assert( '' === Static_Site_Importer_Source_Route::public_source_route( '../escape.html' ) && 'notes/index.html' === Static_Site_Importer_Source_Route::public_source_route( 'website/notes/index.html' ), 'Metadata helper preserves public identity without root escapes.' );
// Wix/Squarespace captures contain index documents even though the site serves clean URLs.
$clean_pages = array(
	array( 'source_path' => 'website/index.html', 'route' => array( 'path' => '/' ) ),
	array( 'source_path' => 'website/about/index.html', 'route' => array( 'path' => '/about/' ) ),
	array( 'source_path' => 'website/notes/deep/index.html', 'route' => array( 'path' => '/notes/deep/' ) ),
);
$GLOBALS['permalinks'] = array( 1 => 'https://native.test/', 2 => 'https://native.test/about/', 3 => 'https://native.test/notes/deep/' );
$clean_receipt = array( 'plan' => array( 'pages' => $clean_pages ), 'completed' => array( 'pages' => array_combine( array_column( $clean_pages, 'source_path' ), array( 1, 2, 3 ) ) ) );
$assert( ! Static_Site_Importer_Redirection_Materializer::has_intent( $clean_receipt['plan'], array() ), 'Clean directory routes need no Redirection dependency.' );
$assert( array() === Static_Site_Importer_Redirection_Materializer::routes( $clean_receipt, array() ), 'Clean directory routes produce no rules.' );
$flat_pages = array(
	array( 'source_path' => 'website/about.html', 'route' => array( 'path' => '/about/' ) ),
	array( 'source_path' => 'website/notes/post.html', 'route' => array( 'path' => '/notes/post/' ) ),
);
$GLOBALS['permalinks'][3] = 'https://native.test/notes/post/';
$flat_receipt = array( 'plan' => array( 'pages' => $flat_pages ), 'completed' => array( 'pages' => array( 'website/about.html' => 2, 'website/notes/post.html' => 3 ) ) );
$assert( Static_Site_Importer_Redirection_Materializer::has_intent( $flat_receipt['plan'], array() ), 'Flat HTML addresses require Redirection.' );
$assert( array( '/about.html' => 'https://native.test/about/', '/notes/post.html' => 'https://native.test/notes/post/' ) === Static_Site_Importer_Redirection_Materializer::routes( $flat_receipt, array() ), 'Flat documents produce exactly their served HTML rules.' );
$clean_receipt['plan']['pages'][2]['route']['path'] = '/native-3/';
$GLOBALS['permalinks'][3] = 'https://native.test/native-3/';
$assert( Static_Site_Importer_Redirection_Materializer::has_intent( $clean_receipt['plan'], array() ), 'A differing nested directory route requires Redirection.' );
$assert( array( '/notes/deep' => 'https://native.test/native-3/' ) === Static_Site_Importer_Redirection_Materializer::routes( $clean_receipt, array() ), 'Nested documents redirect only their directory route, not their capture path.' );
$clean_receipt['plan']['pages'][2]['route']['path'] = '/notes/deep/';
$clean_receipt['plan']['pages'][2]['whole_page_candidates'] = array( array( 'provider' => 'test' ) );
$assert( Static_Site_Importer_Redirection_Materializer::has_intent( $clean_receipt['plan'], array() ), 'Whole-page candidates retain provider route intent.' );
$assert( Static_Site_Importer_Redirection_Materializer::has_intent( array( 'pages' => $clean_pages ), array( 'source_route_aliases' => array( '/old' => 'website/about/index.html' ) ) ), 'Explicit aliases retain route intent.' );
echo "Redirection source route contract passed.\n";
