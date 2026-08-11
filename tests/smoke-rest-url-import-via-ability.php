<?php
/**
 * Smoke test: POST /imports with a source.url routes through the unified import ability.
 *
 * The unified `static-site-importer/import` ability dispatches on
 * `source.type`; URL sources go through `import_url_operation()`. This test
 * drives the full REST stack and verifies the router threads `type=url` and
 * the source URL into the unified ability, then unwraps the result envelope
 * into the REST response shape.
 *
 * Run inside a WordPress site:
 * wp eval-file tests/smoke-rest-url-import-via-ability.php
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

$captured = array();

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
	'import_id'             => 'rest-id-1',
	'url_batch_run'         => array( 'status' => 'continuing', 'completed_batches' => 0, 'total_batches' => 3 ),
	'import_report_summary' => array( 'status' => 'continuing' ),
);

$request = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://example.test/start' ),
			'apply_to_current_site' => true,
			'activate'              => true,
			'overwrite'             => true,
		)
	)
);

$response = rest_get_server()->dispatch( $request );

$assert( 200 === $response->get_status(), 'rest-route-url-import-status', (string) $response->get_status() );
$body = $response->get_data();
$assert( true === ( $body['success'] ?? false ), 'rest-response-success' );
$assert( true === ( $body['continuation'] ?? false ), 'rest-response-continuation' );
$assert( 'rest-id-1' === ( $body['import_id'] ?? '' ), 'rest-response-import-id' );
$assert( 'continuing' === ( $body['url_batch_run']['status'] ?? '' ), 'rest-response-url-batch-run-status' );
$assert( 3 === ( $body['url_batch_run']['total_batches'] ?? 0 ), 'rest-response-url-batch-run-total' );
$assert( 'https://example.test/start' === ( $ability_stub->last_input['source']['url'] ?? '' ), 'ability-receives-source-url' );
$assert( 'url' === ( $ability_stub->last_input['source']['type'] ?? '' ), 'ability-source-type-is-url' );
$assert( ! isset( $ability_stub->last_input['provider'] ), 'no-provider-arg-forwarded' );
$assert( ! isset( $ability_stub->last_input['provider_args'] ), 'no-provider-args-arg-forwarded' );
$assert( ! isset( $ability_stub->last_input['work_dir'] ), 'no-work-dir-arg-forwarded' );

$ability_stub->next_result = array(
	'success'               => true,
	'continuation'          => false,
	'import_id'             => 'rest-id-2',
	'result'                => array( 'theme_slug' => 'rest-site' ),
	'import_report_summary' => array( 'status' => 'completed' ),
	'url_batch_run'         => array(
		'status'                => 'completed',
		'terminal_batch_result' => array( 'theme_slug' => 'rest-site', 'report_path' => '/tmp/rest.json' ),
	),
);

$request = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body(
	wp_json_encode(
		array(
			'source'                => array( 'url' => 'https://example.test/done' ),
			'apply_to_current_site' => true,
		)
	)
);

$response = rest_get_server()->dispatch( $request );
$body     = $response->get_data();

$assert( 200 === $response->get_status(), 'rest-route-terminal-status' );
$assert( 'rest-site' === ( $body['terminal_batch_result']['theme_slug'] ?? '' ), 'rest-response-terminal-batch' );
$assert( 'rest-site' === ( $body['result']['theme_slug'] ?? '' ), 'rest-response-result-theme-slug' );
$assert( ! isset( $body['preview'] ), 'rest-response-terminal-current-site-no-preview' );

$ability_stub->next_result = array();
$ability_stub->last_input  = array( 'sentinel' => 'should-not-be-overwritten' );

$request = new WP_REST_Request( 'POST', '/static-site-importer/v1/imports' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body(
	wp_json_encode(
		array(
			'source' => array( 'url' => 'https://example.test/preview' ),
		)
	)
);

$response = rest_get_server()->dispatch( $request );
$body     = $response->get_data();

$assert( 200 === $response->get_status(), 'rest-playground-status' );
$assert( 'static-site-importer/import' === ( $body['requires_ability_capable_target']['ability'] ?? '' ), 'rest-playground-requirement-ability' );
$assert( 'ability_capable_target_required' === ( $body['continuation_reason'] ?? '' ), 'rest-playground-continuation-reason' );
$assert( isset( $ability_stub->last_input['sentinel'] ), 'rest-playground-does-not-invoke-ability' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo sprintf( "REST URL import via ability smoke passed (%d assertions).\n", $assertions );
