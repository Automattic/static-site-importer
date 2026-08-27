<?php
/** Run: php tests/smoke-url-mapped-ipv6-guard.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
class WP_Error { public function __construct( private string $code, private string $message = '' ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url ) { return parse_url( $url ); }
$GLOBALS['ssi_resolver_provider'] = null;
function apply_filters( string $hook, $value, ...$args ) {
	if ( 'static_site_importer_url_resolved_ips' !== $hook || ! is_callable( $GLOBALS['ssi_resolver_provider'] ) ) { return $value; }
	return $GLOBALS['ssi_resolver_provider']( $value, ...$args );
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';

$failures = array();
$rejects  = static function ( string $url ) use ( &$failures ): void {
	$result = Static_Site_Importer_URL_Fetcher::validate_url( $url );
	if ( ! is_wp_error( $result ) || 'static_site_importer_url_private_ip' !== $result->get_error_code() ) {
		$failures[] = 'must be rejected as a private target: ' . $url;
	}
};

/*
 * Literal host bypass reported as an authenticated SSRF: PHP's range flags accept
 * IPv4-mapped IPv6 literals as public on 8.2.x and on 8.3 before 8.3.16, so the
 * transport connected to IPv4 loopback and returned internal content in the plan.
 */
$rejects( 'http://[::ffff:127.0.0.1]:9090/' );
$rejects( 'http://[::ffff:7f00:1]/' );
$rejects( 'http://[0:0:0:0:0:ffff:127.0.0.1]/' );
$rejects( 'https://[::ffff:10.0.0.1]/' );
$rejects( 'https://[::ffff:169.254.169.254]/latest/meta-data/' );
$rejects( 'https://[::ffff:100.64.0.1]/' );
$rejects( 'https://[::127.0.0.1]/' );
$rejects( 'https://[64:ff9b::127.0.0.1]/' );
$rejects( 'https://[2002:7f00:1::]/' );
$rejects( 'https://[::1]/' );
$rejects( 'http://127.0.0.1:9090/' );
$rejects( 'http://100.64.0.1/' );

// The same guard applies to addresses supplied by a trusted resolver provider.
$GLOBALS['ssi_resolver_provider'] = static fn() => array( '::ffff:127.0.0.1' );
$rejects( 'https://mapped-provider.test/' );

$GLOBALS['ssi_resolver_provider'] = static fn() => array( '1.1.1.1', '::ffff:169.254.169.254' );
$rejects( 'https://mixed-provider.test/' );

// A routable mapped address stays usable and is pinned in its unmapped form.
$GLOBALS['ssi_resolver_provider'] = static fn() => array( '::ffff:8.8.8.8' );
$mapped_public = Static_Site_Importer_URL_Fetcher::validate_url( 'https://mapped-public.test/path' );
if ( is_wp_error( $mapped_public ) ) {
	$failures[] = 'routable mapped address must remain fetchable: ' . $mapped_public->get_error_code();
} elseif ( array( '8.8.8.8' ) !== $mapped_public['ips'] ) {
	$failures[] = 'validated targets must be pinned in unmapped form: ' . implode( ',', $mapped_public['ips'] );
}

// Equivalent encodings of one address collapse to a single pinned target.
$GLOBALS['ssi_resolver_provider'] = static fn() => array( '::ffff:8.8.8.8', '8.8.8.8' );
$deduped = Static_Site_Importer_URL_Fetcher::validate_url( 'https://deduped.test/' );
if ( is_wp_error( $deduped ) || array( '8.8.8.8' ) !== $deduped['ips'] ) {
	$failures[] = 'equivalent encodings must collapse to one pinned target';
}

if ( $failures ) {
	throw new RuntimeException( "URL guard failures:\n - " . implode( "\n - ", $failures ) );
}

fwrite( STDOUT, "OK: validate_url denies IPv4-in-IPv6 literals and pins targets in unmapped form\n" );
