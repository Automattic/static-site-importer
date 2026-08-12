<?php
/** Run: php tests/smoke-url-resolver-provider.php */
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

$GLOBALS['ssi_resolver_provider'] = static fn( $value, string $host ) => 'public.test' === $host ? array( '1.1.1.1', '2606:4700:4700::1111' ) : $value;
$public = Static_Site_Importer_URL_Fetcher::validate_url( 'https://public.test/path' );
if ( is_wp_error( $public ) || array( '1.1.1.1', '2606:4700:4700::1111' ) !== $public['ips'] ) { throw new RuntimeException( 'provider public addresses must be retained for IP-pinned transport' ); }

$blocked_addresses = array(
	'0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1',
	'192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1',
	'203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255', '::', '::1', '::ffff:127.0.0.1',
	'64:ff9b:1::1', '100::1', '100:0:0:1::1', '2001::1', '2001:db8::1', '2002::1', '3fff::1',
	'5f00::1', 'fc00::1', 'fe80::1', 'ff02::1',
);
foreach ( $blocked_addresses as $blocked_address ) {
	$GLOBALS['ssi_resolver_provider'] = static fn() => array( $blocked_address );
	$private = Static_Site_Importer_URL_Fetcher::validate_url( 'https://private.test/' );
	if ( ! is_wp_error( $private ) || 'static_site_importer_url_private_ip' !== $private->get_error_code() ) { throw new RuntimeException( 'provider address must be rejected: ' . $blocked_address ); }
}

foreach ( array( '1.1.1.1', '8.8.8.8', '23.227.38.74', '2606:4700:4700::1111', '2620:127:f00f:e::' ) as $public_address ) {
	$GLOBALS['ssi_resolver_provider'] = static fn() => array( $public_address );
	$public = Static_Site_Importer_URL_Fetcher::validate_url( 'https://public.test/' );
	if ( is_wp_error( $public ) || array( $public_address ) !== $public['ips'] ) { throw new RuntimeException( 'provider public address must be accepted: ' . $public_address ); }
}

$GLOBALS['ssi_resolver_provider'] = static fn() => '1.1.1.1';
$malformed = Static_Site_Importer_URL_Fetcher::validate_url( 'https://malformed.test/' );
if ( ! is_wp_error( $malformed ) || 'static_site_importer_url_dns_provider_invalid' !== $malformed->get_error_code() ) { throw new RuntimeException( 'malformed provider responses must fail closed' ); }

$GLOBALS['ssi_resolver_provider'] = static fn() => array();
$empty = Static_Site_Importer_URL_Fetcher::validate_url( 'https://empty.test/' );
if ( ! is_wp_error( $empty ) || 'static_site_importer_url_dns_failed' !== $empty->get_error_code() ) { throw new RuntimeException( 'empty provider responses must fail closed without native fallback' ); }

$provider_error = new WP_Error( 'runtime_resolver_unavailable', 'resolver unavailable' );
$GLOBALS['ssi_resolver_provider'] = static fn() => $provider_error;
$unavailable = Static_Site_Importer_URL_Fetcher::validate_url( 'https://unavailable.test/' );
if ( $provider_error !== $unavailable ) { throw new RuntimeException( 'provider errors must retain their identity' ); }

fwrite( STDOUT, "OK: URL resolver provider remains fail closed\n" );
