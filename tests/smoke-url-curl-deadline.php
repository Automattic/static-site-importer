<?php
/** Run: php tests/smoke-url-curl-deadline.php */
if ( ! function_exists( 'curl_multi_init' ) ) { fwrite( STDOUT, "SKIP: curl extension unavailable\n" ); exit( 0 ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }
class WP_Error { public function __construct( private string $code, private string $message = '' ) {} public function get_error_code(): string { return $this->code; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';

$peer_code = '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if ( false === $server ) { exit( 1 ); } fwrite(STDOUT, stream_socket_get_name($server, false) . "\\n"); $client = stream_socket_accept($server, 5); if ( $client ) { sleep( 4 ); fclose($client); } fclose($server);';
$process   = proc_open( PHP_BINARY . ' -r ' . escapeshellarg( $peer_code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $process ) || false === ( $address = fgets( $pipes[1] ) ) ) { throw new RuntimeException( 'Could not start the withheld-TLS test peer.' ); }

try {
	$proxies = array( 'ALL_PROXY' => getenv( 'ALL_PROXY' ), 'HTTPS_PROXY' => getenv( 'HTTPS_PROXY' ) );
	putenv( 'ALL_PROXY=http://127.0.0.1:1' );
	putenv( 'HTTPS_PROXY=http://127.0.0.1:1' );
	try {
	$port    = (int) substr( trim( $address ), strrpos( trim( $address ), ':' ) + 1 );
	$target  = array( 'url' => 'https://deadline.test:' . $port . '/', 'scheme' => 'https', 'host' => 'deadline.test', 'port' => $port, 'path' => '/', 'ips' => array( '127.0.0.1' ) );
	$clock   = static fn(): float => microtime( true );
	$options_method = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'request_options' );
	$options = $options_method->invoke( null, array( 'timeout' => 5, 'max_bytes' => 1024 ), $clock() + 0.9, $clock );
	$start   = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'native_start' );
	$poll    = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'native_poll' );
	$handle  = $start->invoke( null, $target, $options );
	$then    = microtime( true );
	do {
		$response = $poll->invoke( null, $handle );
		if ( null === $response ) { usleep( 1000 ); }
	} while ( null === $response && microtime( true ) - $then < 1.5 );
	$elapsed = microtime( true ) - $then;
	if ( ! is_wp_error( $response ) || 'static_site_importer_url_deadline_exhausted' !== $response->get_error_code() || $elapsed > 1.3 || null !== $handle->multi || null !== $handle->curl ) {
		throw new RuntimeException( 'curl_multi must return deadline exhaustion promptly and close the withheld-TLS request.' );
	}
	} finally {
		foreach ( $proxies as $name => $value ) { false === $value ? putenv( $name ) : putenv( $name . '=' . $value ); }
	}
} finally {
	fclose( $pipes[0] ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_terminate( $process ); proc_close( $process );
}

$peer_code = '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if ( false === $server ) { exit( 1 ); } fwrite(STDOUT, stream_socket_get_name($server, false) . "\\n"); $client = stream_socket_accept($server, 5); if ( $client ) { fread($client, 8192); fwrite($client, "HTTP/1.1 200 OK\\r\\nContent-Type: text/plain\\r\\nTransfer-Encoding: chunked\\r\\nConnection: close\\r\\n\\r\\n5\\r\\nhello\\r\\n0\\r\\n\\r\\n"); fclose($client); } fclose($server);';
$process   = proc_open( PHP_BINARY . ' -r ' . escapeshellarg( $peer_code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $process ) || false === ( $address = fgets( $pipes[1] ) ) ) { throw new RuntimeException( 'Could not start the chunked-response test peer.' ); }

try {
	$port    = (int) substr( trim( $address ), strrpos( trim( $address ), ':' ) + 1 );
	$target  = array( 'url' => 'http://chunked.test:' . $port . '/', 'scheme' => 'http', 'host' => 'chunked.test', 'port' => $port, 'path' => '/', 'ips' => array( '127.0.0.1' ) );
	$options = array( 'timeout' => 1, 'max_bytes' => 1024, 'deadline' => null, 'clock' => null );
	$start   = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'native_start' );
	$poll    = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'native_poll' );
	$handle  = $start->invoke( null, $target, $options );
	do {
		$response = $poll->invoke( null, $handle );
		if ( null === $response ) { usleep( 1000 ); }
	} while ( null === $response );
	if ( is_wp_error( $response ) || 'hello' !== $response['body'] || 200 !== $response['status_code'] || null !== $handle->multi || null !== $handle->curl ) {
		throw new RuntimeException( 'curl_multi must decode chunked bodies, retain the HTTP status, and close completed handles.' );
	}
} finally {
	fclose( $pipes[0] ); fclose( $pipes[1] ); fclose( $pipes[2] ); proc_terminate( $process ); proc_close( $process );
}

fwrite( STDOUT, "OK: curl_multi TLS deadline is bounded and cancelled\n" );
