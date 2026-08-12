<?php
/** Run: php tests/smoke-url-tls-context.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/wordpress/' ); }
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';

$method = new ReflectionMethod( Static_Site_Importer_URL_Fetcher::class, 'tls_context' );
$context = $method->invoke( null, 'example.com' );
$options = stream_context_get_options( $context );
$expected = array(
	'SNI_enabled'      => true,
	'peer_name'        => 'example.com',
	'verify_peer'      => true,
	'verify_peer_name' => true,
	'cafile'           => '/wordpress/wp-includes/certificates/ca-bundle.crt',
);

if ( $expected !== ( $options['ssl'] ?? null ) ) {
	throw new RuntimeException( 'IP-pinned HTTPS must use the WordPress CA bundle with peer and hostname verification.' );
}

fwrite( STDOUT, "OK: URL TLS context uses the WordPress CA bundle\n" );
