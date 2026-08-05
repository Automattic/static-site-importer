<?php
/**
 * Smoke test: REST URL import helpers route through the import-url ability.
 *
 * Run from the repository root:
 * php tests/smoke-rest-url-import-helpers.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;
		public function __construct( string $code, string $message, mixed $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000000'; }
}

$GLOBALS['ssi_ability_results'] = array();

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) {
		if ( 'static-site-importer/import-url' !== $name ) {
			return null;
		}
		return new class {
			public function execute( array $input ) {
				$GLOBALS['ssi_ability_last_input'] = $input;
				return array_shift( $GLOBALS['ssi_ability_results'] );
			}
		};
	}
}

require_once dirname( __DIR__ ) . '/includes/rest.php';

$continuation_envelope = array(
	'success'               => true,
	'continuation'          => true,
	'continuation_reason'   => 'effective_batch_limit',
	'import_id'             => 'abc123',
	'url_batch_run'         => array(
		'status'           => 'continuing',
		'completed_batches' => 0,
		'total_batches'     => 3,
	),
	'import_report_summary' => array( 'status' => 'continuing' ),
);

$terminal_envelope = array(
	'success'               => true,
	'continuation'          => false,
	'import_id'             => 'def456',
	'result'                => array( 'theme_slug' => 'remote-site' ),
	'import_report_summary' => array( 'status' => 'completed' ),
	'url_batch_run'         => array(
		'status'                => 'completed',
		'terminal_batch_result' => array( 'theme_slug' => 'remote-site', 'report_path' => '/tmp/x.json' ),
	),
);

$GLOBALS['ssi_ability_results'] = array( $continuation_envelope );

$result = static_site_importer_rest_route_url_import(
	array( 'url' => 'https://example.test/start' ),
	array( 'slug' => 'rest-continuation' ),
	'current_site'
);

$assert( is_array( $result ), 'route-url-import-returns-array' );
$assert( true === ( $result['success'] ?? false ), 'continuation-success-true' );
$assert( true === ( $result['continuation'] ?? false ), 'continuation-flag-propagates' );
$assert( 'effective_batch_limit' === ( $result['continuation_reason'] ?? '' ), 'continuation-reason-propagates' );
$assert( 'abc123' === ( $result['import_id'] ?? '' ), 'import-id-propagates' );
$assert( 'continuing' === ( $result['url_batch_run']['status'] ?? '' ), 'url-batch-run-status-propagates' );
$assert( 'https://example.test/start' === ( $GLOBALS['ssi_ability_last_input']['url'] ?? '' ), 'ability-receives-source-url' );

$GLOBALS['ssi_ability_results'] = array( $terminal_envelope );
$GLOBALS['ssi_ability_last_input'] = null;

$result = static_site_importer_rest_route_url_import(
	array( 'url' => 'https://example.test/done' ),
	array( 'slug' => 'rest-terminal' ),
	'current_site'
);

$assert( true === ( $result['success'] ?? false ), 'terminal-success-true' );
$assert( false === ( $result['continuation'] ?? false ), 'terminal-continuation-false' );
$assert( 'def456' === ( $result['import_id'] ?? '' ), 'terminal-import-id-propagates' );
$assert( 'remote-site' === ( $result['terminal_batch_result']['theme_slug'] ?? '' ), 'terminal-batch-result-propagates' );
$assert( ! isset( $result['preview'] ), 'terminal-current-site-does-not-advertise-preview' );

// Playground mode short-circuits the ability call: it must not consume a
// stubbed result, and the structured requirement is built directly.
$GLOBALS['ssi_ability_results'] = array();
$GLOBALS['ssi_ability_last_input'] = null;

$result = static_site_importer_rest_route_url_import(
	array( 'url' => 'https://example.test/preview' ),
	array( 'slug' => 'rest-playground' ),
	'playground'
);

$assert( is_array( $result ), 'playground-returns-array' );
$assert( true === ( $result['success'] ?? false ), 'playground-success-true' );
$assert( true === ( $result['continuation'] ?? false ), 'playground-continuation-true' );
$assert( 'ability_capable_target_required' === ( $result['continuation_reason'] ?? '' ), 'playground-continuation-reason' );
$assert( null === $GLOBALS['ssi_ability_last_input'], 'playground-does-not-invoke-ability' );
$assert( 'unavailable' === ( $result['preview']['status'] ?? '' ), 'playground-preview-unavailable' );
$assert( 'https://example.test/preview' === ( $result['preview']['url'] ?? '' ), 'playground-preview-url-echoes-source' );
$assert( 'static-site-importer/url-playground-requirement/v1' === ( $result['preview']['requires_ability_capable_target']['schema'] ?? '' ), 'playground-requirement-schema' );
$assert( 'static-site-importer/import-url' === ( $result['preview']['requires_ability_capable_target']['ability'] ?? '' ), 'playground-requirement-ability' );
$assert( 'https://example.test/preview' === ( $result['preview']['requires_ability_capable_target']['url'] ?? '' ), 'playground-requirement-url' );
$assert( '' !== ( $result['preview']['requires_ability_capable_target']['import_id'] ?? '' ), 'playground-requirement-import-id-synthesized' );
$assert( '' !== ( $result['preview']['requires_ability_capable_target']['instructions'] ?? '' ), 'playground-requirement-instructions-present' );
$assert( 'pending_disposable_target' === ( $result['url_batch_run']['status'] ?? '' ), 'playground-url-batch-run-pending' );
$assert( isset( $result['input']['slug'] ) && 'rest-playground' === $result['input']['slug'], 'playground-input-echoed' );

$import_id_envelope = array(
	'success'               => true,
	'continuation'          => true,
	'import_id'             => 'bound-id',
	'continuation_reason'   => 'deadline_exhausted',
);

$GLOBALS['ssi_ability_results'] = array( $import_id_envelope );

$result = static_site_importer_rest_route_url_import(
	array( 'url' => 'https://example.test/continue', 'import_id' => 'bound-id' ),
	array( 'slug' => 'rest-import-id' ),
	'current_site'
);

$assert( 'bound-id' === ( $GLOBALS['ssi_ability_last_input']['import_id'] ?? '' ), 'ability-receives-rest-import-id' );
$assert( 'bound-id' === ( $result['import_id'] ?? '' ), 'import-id-echoes-through-result' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo sprintf( "REST URL import helper smoke passed (%d assertions).\n", $assertions );
