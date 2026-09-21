<?php
/** Regression coverage for the PHP.wasm demo network adapter. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_get( string $url, array $args ) {
	$GLOBALS['requests'][] = array( $url, $args );
	return array_shift( $GLOBALS['responses'] );
}
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function wp_remote_retrieve_response_code( array $response ): int { return $response['status']; }
function wp_remote_retrieve_headers( array $response ): array { return $response['headers'] ?? array(); }
require_once ABSPATH . 'demos/playground-importer/includes/networking.php';
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$GLOBALS['requests'] = array();
$GLOBALS['responses'] = array(
	array( 'status' => 200, 'body' => json_encode( array( 'Status' => 0, 'Answer' => array( array( 'type' => 5, 'data' => 'alias.test' ), array( 'type' => 1, 'data' => '93.184.216.34' ) ) ) ) ),
	array( 'status' => 200, 'body' => json_encode( array( 'Status' => 0, 'Answer' => array( array( 'type' => 28, 'data' => '2606:4700::1111' ) ) ) ) ),
);
$ips = static_site_importer_playground_resolve_ips( null, 'public.test' );
$assert( array( '93.184.216.34', '2606:4700::1111' ) === $ips, 'Resolve A and AAAA, not CNAMEs or synthetic socket addresses.' );
$assert( $ips === static_site_importer_playground_resolve_ips( null, 'public.test' ) && 2 === count( $GLOBALS['requests'] ), 'Reuse bounded DNS results within one PHP invocation.' );
$provided = new WP_Error( 'host_policy', 'Host rejected this request.' );
$assert( $provided === static_site_importer_playground_resolve_ips( $provided, 'blocked.test' ), 'Preserve an existing host policy result.' );
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => '{"Status":0,"TC":true}' ) );
$assert( is_wp_error( static_site_importer_playground_resolve_ips( null, 'truncated.test' ) ), 'Truncated DNS answers fail closed.' );
$GLOBALS['responses'] = array( array( 'status' => 200, 'body' => '{"Status":2}' ) );
$assert( is_wp_error( static_site_importer_playground_resolve_ips( null, 'failed.test' ) ), 'DNS failures stay failures.' );
$GLOBALS['responses'] = array( array( 'status' => 302, 'body' => '', 'headers' => array( 'Location' => 'http://127.0.0.1/' ) ) );
$result = static_site_importer_playground_request( array( 'url' => 'https://public.test/' ), array( 'timeout' => 2.5, 'max_bytes' => 100 ) );
$request = end( $GLOBALS['requests'] );
$assert( 302 === $result['status_code'] && array( 'http://127.0.0.1/' ) === $result['headers']['location'], 'Return redirects to the SSI validator rather than following them.' );
$assert( 0 === $request[1]['redirection'] && 101 === $request[1]['limit_response_size'] && 2.5 === $request[1]['timeout'] && array() === $request[1]['cookies'], 'Honor byte/deadline bounds and carry no cookies.' );
$assert( ! isset( $request[1]['sslverify'] ), 'Retain runtime TLS verification.' );
echo "Playground networking smoke passed.\n";
