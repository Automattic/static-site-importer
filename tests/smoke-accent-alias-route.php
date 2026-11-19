<?php
/** An alias source keeps its address when an accented page sanitizes onto it. */
require_once __DIR__ . '/fixtures/direct-import-harness.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-redirection-materializer.php';
class WP {
	public function parse_request() {}
	public function query_posts() {}
	public function handle_404() {}
}
class WP_Query {
	public function is_singular() { return false; }
	public function is_404() { return true; }
}
// Each planned page is published at its planned route, as WordPress stores it.
function get_permalink( $id ) { return 'https://native.test/' . ( '' === $GLOBALS['published'][ $id ] ? '' : $GLOBALS['published'][ $id ] . '/' ); }
function home_url( $path = '' ) { return 'https://native.test' . $path; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function sanitize_title_for_query( $value ) {
	$value = preg_replace_callback( '/[\x80-\xff]+/', static fn( $m ) => strtolower( rawurlencode( $m[0] ) ), mb_strtolower( $value ) );
	return trim( preg_replace( '/[\s-]+/', '-', preg_replace( '/[^%a-z0-9 _-]/', '', $value ) ), '-' );
}
function get_posts( $args ) {
	$posts = array();
	foreach ( $GLOBALS['published'] as $id => $path ) {
		if ( basename( $path ) === $args['name'] ) {
			$posts[] = (object) array( 'ID' => $id, 'post_status' => 'publish', 'path' => $path );
		}
	}
	return $posts;
}
function get_page_uri( $post ) { return $post->path; }
function url_to_postid( $url ) { return 0; }
function redirect_canonical( $url, $redirect = true ) { return false; }
function redirect_guess_404_permalink() { return false; }
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
try {
	$files = array();
	foreach ( array( 'index.html', 'caf%C3%A9/index.html', 'men%C3%BA/index.html', '_redirects' ) as $path ) {
		$files[] = array( 'path' => 'website/' . $path, 'content' => file_get_contents( __DIR__ . '/fixtures/accent-alias-route/' . $path ) );
	}
	$result = ssi_harness_drive( array( 'operation' => 'apply', 'slug' => 'accent-alias-route', 'source' => array( 'type' => 'files', 'entrypoint' => 'website/index.html', 'files' => $files ) ) );
	$assert( ! empty( $result['success'] ), 'Import must complete: ' . json_encode( $result ) );
	$import = end( $GLOBALS['ssi_harness_imports'] );
	$GLOBALS['published'] = array();
	$ids                  = array();
	foreach ( $import['plan']['pages'] as $offset => $page ) {
		$GLOBALS['published'][ 100 + $offset ] = trim( $page['route']['path'], '/' );
		$ids[ $page['source_path'] ]           = 100 + $offset;
	}
	$redirects = Static_Site_Importer_Redirection_Materializer::routes( array( 'plan' => $import['plan'], 'completed' => array( 'pages' => $ids ) ), $import['args'] );
	$assert( is_array( $redirects ), 'Redirect planning must not find the accented page on the alias source: ' . ( is_wp_error( $redirects ) ? $redirects->get_error_code() : '' ) );
	$assert( 'cafe-2' === $GLOBALS['published'][ $ids['website/caf%C3%A9/index.html'] ] && 'menu' === $GLOBALS['published'][ $ids['website/men%C3%BA/index.html'] ], 'The accented page takes the next free route; the alias destination keeps its own: ' . json_encode( $GLOBALS['published'] ) );
	$assert(
		array(
			'/cafe' => 'https://native.test/menu/',
			'/café' => 'https://native.test/cafe-2/',
			'/menú' => 'https://native.test/menu/',
		) === $redirects,
		'The alias reaches its destination and the accented source address reaches its own page: ' . json_encode( $redirects, JSON_UNESCAPED_UNICODE )
	);
	// Only a derived route that merely sanitizes onto another page's alias moves.
	$alias    = array( array( 'from' => 'cafe', 'to' => 'menu/index.html' ) );
	$artifact = array(
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array( 'path' => 'website/index.html' ),
			array( 'path' => 'website/cafe/index.html' ),
			array( 'path' => 'website/menu/index.html' ),
		),
	);
	$assert( $artifact === Static_Site_Importer_Redirects_Manifest::reserve_alias_routes( $artifact, $alias ), 'A page served at the alias address itself keeps its route.' );
	$artifact['files'][1] = array( 'path' => 'website/caf%C3%A9/index.html', 'metadata' => array( 'route_path' => '/cafe' ) );
	$assert( $artifact === Static_Site_Importer_Redirects_Manifest::reserve_alias_routes( $artifact, $alias ), 'A producer-declared route is not renamed.' );
	unset( $artifact['files'][1]['metadata'] );
	$assert( $artifact === Static_Site_Importer_Redirects_Manifest::reserve_alias_routes( $artifact, array() ), 'Without aliases nothing moves.' );
	$assert( array( 'route_path' => '/cafe-2' ) === Static_Site_Importer_Redirects_Manifest::reserve_alias_routes( $artifact, $alias )['files'][1]['metadata'], 'The accented page takes the next free route.' );
} finally {
	ssi_harness_cleanup();
}
echo "Accent alias route passed.\n";
