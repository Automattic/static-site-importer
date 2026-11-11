<?php
/** Served HTML twins share one page through the public import interface. */
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
function get_permalink( $id ) { return 100 === $id ? 'https://native.test/' : 'https://native.test/x/'; }
function home_url( $path = '' ) { return 'https://native.test' . $path; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function get_posts( $args ) { return array(); }
function sanitize_title_for_query( $value ) { return $value; }
function url_to_postid( $url ) { return 0; }
function redirect_canonical( $url, $redirect = true ) { return false; }
function redirect_guess_404_permalink() { return false; }
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
try {
	foreach ( array( false, true ) as $different ) {
		$files = array();
		foreach ( array( 'index.html', 'x.html', 'x/index.html' ) as $path ) {
			$content = file_get_contents( __DIR__ . '/fixtures/source-route-twins/' . $path );
			if ( $different && 'index.html' === $path ) {
				$content = str_replace( '</body>', '<a href="x.html">Catalog file</a><a href="x/">Catalog directory</a></body>', $content );
			}
			if ( $different && 'x/index.html' === $path ) {
				$content = str_replace( 'Catalog', 'Directory catalog', $content );
			}
			$files[] = array( 'path' => 'website/' . $path, 'content' => $content );
		}
		if ( $different ) {
			$files = array_reverse( $files );
		}
		$result = ssi_harness_drive( array( 'operation' => 'apply', 'slug' => 'route-twins', 'source' => array( 'type' => 'files', 'files' => $files ) ) );
		$assert( ! empty( $result['success'] ), 'Import must complete: ' . json_encode( $result ) );
		$import = end( $GLOBALS['ssi_harness_imports'] );
		$pages = $import['plan']['pages'];
		$assert( array( 'website/index.html', 'website/x/index.html' ) === array_column( $pages, 'source_path' ), 'Home and one directory-default page must survive.' );
		$assert( ! $different || 'Directory catalog' === $pages[1]['title'], 'Directory content must win even when different.' );
		$receipt = array( 'plan' => $import['plan'], 'completed' => array( 'pages' => array( 'website/index.html' => 100, 'website/x/index.html' => 101 ) ) );
		$routes = Static_Site_Importer_Redirection_Materializer::routes( $receipt, $import['args'] );
		$assert( array( '/x.html' => 'https://native.test/x/' ) === $routes, 'The flat source address must redirect without a competing /x rule.' );
	}
} finally {
	ssi_harness_cleanup();
}
echo "Source route twins public import passed.\n";
