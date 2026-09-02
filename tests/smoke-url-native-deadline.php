<?php
/** Run: php tests/smoke-url-native-deadline.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }
class WP_Error { public function __construct( private string $code, private string $message = '' ) {} public function get_error_code(): string { return $this->code; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';

// No-curl path is forced by calling native_stream_start directly, bypassing the
// curl_multi preference in native_start. If curl_multi is present it only proves
// the deadline exhaustion still happens on the native stream path when forced.
$peer_code = '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if ( false === $server ) { exit( 1 ); } fwrite(STDOUT, stream_socket_get_name($server, false) . "\n"); $client = stream_socket_accept($server, 5); if ( $client ) { sleep( 4 ); fclose($client); } fclose($server);';
$process   = proc_open( PHP_BINARY . ' -r ' . escapeshellarg( $peer_code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $process ) || false === ( $address = fgets( $pipes[1] ) ) ) { throw new RuntimeException( 'Could not start the withheld-TLS test peer.' ); }

try {
	$port    = (int) substr( trim( $address ), strrpos( trim( $address ), ':' ) + 1 );
	$target  = array( 'url' => 'https://deadline.test:' . $port . '/', 'scheme' => 'https', 'host' => 'deadline.test', 'port' => $port, 'path' => '/', 'ips' => array( '127.0.0.1' ) );
	$clock   = static fn(): float => microtime( true );
	$options_method = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'request_options' );
	$options = $options_method->invoke( null, array( 'timeout' => 5, 'max_bytes' => 1024 ), $clock() + 0.9, $clock );
	$start   = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'native_stream_start' );
	$poll    = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'native_poll' );
	$handle  = $start->invoke( null, $target, $options );
	$then    = microtime( true );
	do {
		$response = $poll->invoke( null, $handle );
		if ( null === $response ) { usleep( 1000 ); }
	} while ( null === $response && microtime( true ) - $then < 1.5 );
	$elapsed = microtime( true ) - $then;
	if ( ! is_wp_error( $response ) || 'static_site_importer_url_deadline_exhausted' !== $response->get_error_code() || $elapsed > 1.3 || is_resource( $handle->socket ) || null !== $handle->multi || null !== $handle->curl ) {
		throw new RuntimeException( 'native stream must return deadline exhaustion promptly and must not enter the uncancellable TLS handshake.' );
	}
} finally {
	fclose( $pipes[0] ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_terminate( $process ); proc_close( $process );
}

fwrite( STDOUT, "OK: native stream TLS deadline is bounded and cancelled\n" );
