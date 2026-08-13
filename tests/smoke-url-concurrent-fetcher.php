<?php
/** Run: php tests/smoke-url-concurrent-fetcher.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
class WP_Error { public function __construct( private string $code, private string $message = '', private mixed $data = null ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } public function get_error_data(): mixed { return $this->data; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';

$active = $origin_active = $starts = $cancels = array(); $max_active = $max_origin = 0;
$driver = array(
	'start' => static function( array $target, array $options ) use ( &$active, &$origin_active, &$starts, &$max_active, &$max_origin ) {
		$origin = $target['scheme'] . '://' . $target['host'] . ':' . $target['port']; $active[] = $target['path']; $origin_active[ $origin ] = ( $origin_active[ $origin ] ?? 0 ) + 1; $starts[] = $target['path']; $max_active = max( $max_active, count( $active ) ); $max_origin = max( $max_origin, $origin_active[ $origin ] );
		return (object) array( 'target' => $target, 'options' => $options, 'origin' => $origin, 'ticks' => '/slow' === $target['path'] ? 3 : 1 );
	},
	'poll' => static function( object $handle ) use ( &$active, &$origin_active ) { if ( 0 < --$handle->ticks ) { return null; } $active = array_values( array_filter( $active, static fn( string $path ): bool => $path !== $handle->target['path'] ) ); --$origin_active[ $handle->origin ]; if ( '/redirect' === $handle->target['path'] ) { return array( 'status_code' => 302, 'headers' => array( 'location' => array( 'http://127.0.0.1/private' ) ), 'body' => '' ); } return array( 'status_code' => 200, 'headers' => array( 'content-type' => array( 'text/plain' ) ), 'body' => ltrim( $handle->target['path'], '/' ) ); },
	'cancel' => static function( object $handle, string $reason ) use ( &$cancels ) { $cancels[] = array( $handle->target['path'], $reason ); },
);
$requests = array( 'slow' => array( 'url' => 'http://1.1.1.1/slow', 'args' => array( 'content_types' => array() ) ), 'fast' => array( 'url' => 'http://8.8.8.8/fast', 'args' => array( 'content_types' => array() ) ), 'same-origin' => array( 'url' => 'http://1.1.1.1/same', 'args' => array( 'content_types' => array() ) ) );
$results = Static_Site_Importer_URL_Fetcher::fetch_many( $requests, array( 'concurrency' => 2, 'per_origin_concurrency' => 1, 'transport' => $driver ) );
if ( 2 !== $max_active || 1 !== $max_origin || array_keys( $requests ) !== array_keys( $results ) || 'slow' !== $results['slow']['body'] || 'fast' !== $results['fast']['body'] || 'same' !== $results['same-origin']['body'] || array( '/slow', '/fast', '/same' ) !== $starts ) { throw new RuntimeException( 'concurrent transport must bound global/per-origin in-flight work while retaining caller-keyed order' ); }

$private = Static_Site_Importer_URL_Fetcher::fetch_many( array( 'redirect' => array( 'url' => 'http://1.1.1.1/redirect', 'args' => array( 'content_types' => array() ) ) ), array( 'transport' => $driver ) );
if ( ! is_wp_error( $private['redirect'] ) || 'static_site_importer_url_private_ip' !== $private['redirect']->get_error_code() ) { throw new RuntimeException( 'redirect targets must be revalidated before a new connection starts' ); }

$now = 0; $deadline_cancels = array(); $never = array( 'start' => static fn( array $target, array $options ) => (object) array( 'target' => $target ), 'poll' => static function( object $handle ) use ( &$now ) { $now++; return null; }, 'cancel' => static function( object $handle, string $reason ) use ( &$deadline_cancels ) { $deadline_cancels[] = $reason; } );
$timed_out = Static_Site_Importer_URL_Fetcher::fetch_many( array( 'one' => array( 'url' => 'http://1.1.1.1/one', 'args' => array( 'content_types' => array() ) ), 'two' => array( 'url' => 'http://8.8.8.8/two', 'args' => array( 'content_types' => array() ) ) ), array( 'concurrency' => 2, 'deadline' => 1, 'clock' => static function() use ( &$now ) { return $now; }, 'transport' => $never ) );
if ( ! is_wp_error( $timed_out['one'] ) || 'static_site_importer_url_deadline_exhausted' !== $timed_out['one']->get_error_code() || array( 'deadline_exhausted', 'deadline_exhausted' ) !== $deadline_cancels ) { throw new RuntimeException( 'deadline exhaustion must cancel every in-flight request with a reason-coded outcome' ); }
$deadline_cancels = array(); $now = 0;
$single_timed_out = Static_Site_Importer_URL_Fetcher::fetch( 'http://1.1.1.1/one', array( 'content_types' => array(), 'deadline' => 1, 'clock' => static function() use ( &$now ) { return $now; }, 'transport' => $never ) );
if ( ! is_wp_error( $single_timed_out ) || 'static_site_importer_url_deadline_exhausted' !== $single_timed_out->get_error_code() || array( 'deadline_exhausted' ) !== $deadline_cancels ) { throw new RuntimeException( 'deadline-aware single fetches must use cancellable transport rather than blocking socket I/O' ); }

$serial = Static_Site_Importer_URL_Fetcher::fetch_many( array( 'a' => array( 'url' => 'http://1.1.1.1/a', 'args' => array( 'content_types' => array() ) ), 'b' => array( 'url' => 'http://8.8.8.8/b', 'args' => array( 'content_types' => array() ) ) ), array( 'concurrency' => 1, 'per_origin_concurrency' => 1, 'transport' => $driver ) );
if ( 'a' !== $serial['a']['body'] || 'b' !== $serial['b']['body'] ) { throw new RuntimeException( 'serial scheduling must produce the same validated responses' ); }
fwrite( STDOUT, "OK: bounded concurrent URL transport\n" );
