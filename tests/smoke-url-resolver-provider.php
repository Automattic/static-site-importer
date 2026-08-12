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

$GLOBALS['ssi_resolver_provider'] = static fn() => array( '127.0.0.1' );
$private = Static_Site_Importer_URL_Fetcher::validate_url( 'https://private.test/' );
if ( ! is_wp_error( $private ) || 'static_site_importer_url_private_ip' !== $private->get_error_code() ) { throw new RuntimeException( 'provider addresses must pass the existing public-IP policy' ); }

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
