<?php
/**
 * Smoke coverage for ability errors preserving import report summaries.
 *
 * Run from the repository root:
 * php tests/smoke-ability-error-report-summary.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook_name ): bool {
		unset( $hook_name );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		unset( $hook_name );
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, string $callback ): void {
		unset( $hook_name, $callback );
	}
}

require_once dirname( __DIR__ ) . '/includes/abilities.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$summary = array(
	'status'                => 'failed',
	'quality_pass'          => false,
	'fail_import'           => true,
	'failure_reasons'       => array( 'core_html_block' ),
	'core_html_block_count' => 1,
);

$result = static_site_importer_ability_error(
	'static_site_importer_quality_gate_failed',
	'Import failed quality gates; materialization was not completed.',
	array(
		'import_report_summary' => $summary,
		'quality'               => array( 'fail_import' => true ),
	)
);

$assert( false === ( $result['success'] ?? true ), 'ability-error-fails' );
$assert( $summary === ( $result['import_report_summary'] ?? array() ), 'preserves-import-report-summary' );
$assert( 'core_html_block' === ( $result['import_report_summary']['failure_reasons'][0] ?? '' ), 'preserves-failure-reason' );
$assert( true === ( $result['error']['data']['quality']['fail_import'] ?? false ), 'preserves-error-data' );

$hostile = static_site_importer_ability_error(
	'static_site_importer_entity_materialization_failed',
	'Provider failed at https://user:password@example.test/private?token=secret.',
	array(
		'diagnostics' => array(
			array(
				'code'        => 'provider_entity_materialization_failed',
				'source_path' => 'pages/é.html',
				'selector'    => 'form.contact',
				'provider'    => 'generic-provider',
				'reason_code' => 'provider_unavailable',
			),
			array(
				'code'        => 'provider_entity_materialization_failed',
				'source_path' => '/private/var/import.html',
				'selector'    => 'form[data-token="secret"]',
				'provider'    => 'https://user:password@example.test/?token=secret',
				'reason_code' => 'authorization: Bearer secret',
				'message'     => "\xB1broken",
			),
		),
	)
);
$hostile_diagnostics = $hostile['diagnostics'] ?? array();
$hostile_json        = json_encode( $hostile );
$assert( 'pages/é.html' === ( $hostile_diagnostics[0]['source_path'] ?? '' ) && 'form.contact' === ( $hostile_diagnostics[0]['selector'] ?? '' ), 'canonical-ability-error-retains-safe-relative-unicode-location' );
$assert( ! isset( $hostile_diagnostics[1]['source_path'], $hostile_diagnostics[1]['selector'], $hostile_diagnostics[1]['provider'], $hostile_diagnostics[1]['reason_code'] ) && ! str_contains( (string) ( $hostile['error']['message'] ?? '' ), 'secret' ) && false !== $hostile_json && ! str_contains( $hostile_json, 'password' ), 'canonical-ability-error-redacts-unsafe-fields-and-emits-valid-json' );

$public_data = Static_Site_Importer_Entity_Materializer_Registry::project_public_error_data(
	array(
		'status'         => 'failed',
		'import_id'      => 'import-42',
		'private_path'   => '/private/var/import.html',
		'callback_url'   => 'https://example.test/?token=secret',
		'nested'         => array( 'authorization' => 'Bearer secret', 'long' => str_repeat( 'x', 10000 ) ),
		'diagnostics'    => array_merge(
			array_fill( 0, 10, 'malformed diagnostic' ),
			array( array( 'code' => 'must_not_be_scanned', 'source_path' => 'hidden.html' ) )
		),
	)
);
$public_json = json_encode( $public_data );
$assert( 'failed' === ( $public_data['status'] ?? '' ) && 'import-42' === ( $public_data['import_id'] ?? '' ) && array() === ( $public_data['diagnostics'] ?? null ) && ! isset( $public_data['private_path'], $public_data['callback_url'], $public_data['nested'] ) && false !== $public_json && ! str_contains( $public_json, 'secret' ) && ! str_contains( $public_json, 'must_not_be_scanned' ), 'public-error-data-is-shallow-allowlisted-and-diagnostic-scanning-is-bounded' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: ability error report summary smoke passed (' . $assertions . " assertions)\n";
