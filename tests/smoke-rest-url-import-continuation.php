<?php
/**
 * Smoke test: continuation envelope is bound to a single import_id, and a
 * mismatched URL on a continuation POST fails the identity contract.
 *
 * The unified `static-site-importer/import` ability dispatches URL sources
 * to `import_url_operation()`. The REST router threads the source URL and
 * the optional import_id through the unified ability, which is responsible
 * for the identity-binding rejection.
 *
 * Run inside a WordPress site:
 * wp eval-file tests/smoke-rest-url-import-continuation.php
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
	public array $last_input  = array();
	public array $next_result = array();

	public function execute( array $input ) {
		$this->last_input = $input;
		return $this->next_result;
	}
};

add_filter(
	'wp_get_ability',
	static function ( $ability, $name ) use ( $ability_stub ) {
		if ( 'static-site-importer/import' === $name ) {
			return $ability_stub;
		}
		return $ability;
	},
	10,
	2
);

do_action( 'rest_api_init' );

$ability_stub->next_result = array(
	'success'               => true,
	'continuation'          => true,
	'continuation_reason'   => 'effective_batch_limit',
	'import_id'             => 'bound-1',
	'url_batch_run'         => array( 'status' => 'continuing' ),
	'import_report_summary' => array( 'status' => 'continuing' ),
);

$first = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$first->set_header( 'content-type', 'application/json' );
$first->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://example.test/site' ),
		)
	)
);
$response = rest_get_server()->dispatch( $first );
$body     = $response->get_data();

$assert( 200 === $response->get_status(), 'continuation-first-status' );
$assert( true === ( $body['continuation'] ?? false ), 'continuation-first-flag' );
$assert( 'bound-1' === ( $body['import_id'] ?? '' ), 'continuation-first-import-id' );
$assert( 'https://example.test/site' === ( $ability_stub->last_input['source']['url'] ?? '' ), 'continuation-first-ability-url' );
$assert( 'url' === ( $ability_stub->last_input['source']['type'] ?? '' ), 'continuation-first-ability-type' );

$ability_stub->next_result = array(
	'success'               => true,
	'continuation'          => false,
	'import_id'             => 'bound-1',
	'result'                => array( 'theme_slug' => 'bound-site' ),
	'import_report_summary' => array( 'status' => 'completed' ),
	'url_batch_run'         => array(
		'status'                => 'completed',
		'terminal_batch_result' => array( 'theme_slug' => 'bound-site' ),
	),
);

$second = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$second->set_header( 'content-type', 'application/json' );
$second->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://example.test/site', 'import_id' => 'bound-1' ),
		)
	)
);
$response = rest_get_server()->dispatch( $second );
$body     = $response->get_data();

$assert( 200 === $response->get_status(), 'continuation-second-status' );
$assert( false === ( $body['continuation'] ?? true ), 'continuation-second-terminal' );
$assert( 'bound-site' === ( $body['terminal_batch_result']['theme_slug'] ?? '' ), 'continuation-second-terminal-batch' );
$assert( 'bound-1' === ( $ability_stub->last_input['source']['import_id'] ?? '' ), 'continuation-second-input-import-id' );
$assert( 'https://example.test/site' === ( $ability_stub->last_input['source']['url'] ?? '' ), 'continuation-second-input-url' );

// Identity binding: a continuation POST that carries a mismatched URL must
// not silently rebind the ability's import_id. The ability is responsible
// for the rejection; we exercise it by returning a WP_Error from the stub
// the way `import_url_operation` does for a mismatched URL, and assert the
// REST surface propagates the error envelope.
$ability_stub->next_result = new WP_Error(
	'static_site_importer_invalid_url_import_id',
	'The import_id is invalid for the supplied URL.',
	array( 'status' => 400 )
);

$third = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$third->set_header( 'content-type', 'application/json' );
$third->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://example.test/other', 'import_id' => 'bound-1' ),
		)
	)
);
$response   = rest_get_server()->dispatch( $third );
$body       = $response->get_data();
$status     = $response->get_status();
$error_code = is_array( $body ) && isset( $body['error']['code'] ) ? $body['error']['code'] : '';

$assert( $status >= 400 && $status < 600, 'mismatched-url-rest-error-status' );
$assert( 'static_site_importer_invalid_url_import_id' === $error_code, 'mismatched-url-rest-error-code' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo sprintf( "REST URL import continuation smoke passed (%d assertions).\n", $assertions );
