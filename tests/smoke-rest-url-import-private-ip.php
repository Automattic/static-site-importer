<?php
/**
 * Smoke test: private/loopback URLs are rejected through the import-url ability
 * boundary with the same SSRF error codes the URL fetcher uses directly.
 *
 * Run inside a WordPress site:
 * wp eval-file tests/smoke-rest-url-import-private-ip.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

add_filter( 'static_site_importer_can_manage_imports', '__return_true' );

$ability_stub = new class {
	public function execute( array $input ) {
		if ( 'https://127.0.0.1/' === ( $input['url'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_url_private_ip', 'The URL resolves to a private, loopback, link-local, or otherwise reserved IP address.' );
		}
		if ( 'https://localhost/' === ( $input['url'] ?? '' ) ) {
			return new WP_Error( 'static_site_importer_url_host', 'The URL host is not allowed.' );
		}
		return array( 'success' => true, 'continuation' => false );
	}
};

add_filter(
	'wp_get_ability',
	static function ( $ability, $name ) use ( $ability_stub ) {
		if ( 'static-site-importer/import-url' === $name ) {
			return $ability_stub;
		}
		return $ability;
	},
	10,
	2
);

do_action( 'rest_api_init' );

$loopback = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$loopback->set_header( 'content-type', 'application/json' );
$loopback->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://127.0.0.1/' ),
			'apply_to_current_site' => true,
		)
	)
);
$response = rest_get_server()->dispatch( $loopback );

$assert( 400 === $response->get_status() || 500 === $response->get_status(), 'loopback-ip-status', (string) $response->get_status() );
$body = $response->get_data();
$assert( 'static_site_importer_url_private_ip' === ( $body['code'] ?? '' ), 'loopback-ip-error-code', wp_json_encode( $body ) );

$host = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$host->set_header( 'content-type', 'application/json' );
$host->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://localhost/' ),
			'apply_to_current_site' => true,
		)
	)
);
$response = rest_get_server()->dispatch( $host );
$body     = $response->get_data();
$assert( 'static_site_importer_url_host' === ( $body['code'] ?? '' ), 'localhost-host-error-code', wp_json_encode( $body ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo sprintf( "REST URL import private-IP smoke passed (%d assertions).\n", $assertions );
