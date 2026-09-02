<?php
/**
 * WordPress-owned provider submission evidence runtime coverage.
 *
 * Run from the repository root:
 * php tests/smoke-provider-submission-evidence.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'STATIC_SITE_IMPORTER_VERSION' ) ) {
	define( 'STATIC_SITE_IMPORTER_VERSION', '1.8.3' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

$GLOBALS['ssi_test_hooks'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $accepted_args );
		$GLOBALS['ssi_test_hooks'][ $hook ][ $priority ][] = $callback;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		$callbacks = $GLOBALS['ssi_test_hooks'][ $hook ][ $priority ] ?? array();
		$GLOBALS['ssi_test_hooks'][ $hook ][ $priority ] = array_values( array_filter( $callbacks, static fn( $candidate ): bool => $candidate !== $callback ) );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ssi_test_hooks'][ $hook ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}
		return $value;
	}
}

$provider_evidence = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-provider-submission-evidence.php' : '';
if ( ! is_file( $provider_evidence ) ) {
	$provider_evidence = dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-submission-evidence.php';
}
require_once $provider_evidence;

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$receipt = array(
	'schema'    => 'static-site-importer/materialization-receipt/v2',
	'status'    => 'completed',
	'plan_hash' => str_repeat( 'b', 64 ),
);
$sidecar = sys_get_temp_dir() . '/ssi-provider-submission-sidecar-' . bin2hex( random_bytes( 4 ) ) . '.json';
$output  = sys_get_temp_dir() . '/ssi-provider-submission-evidence-' . bin2hex( random_bytes( 4 ) ) . '.json';
file_put_contents( $sidecar, wp_json_encode( array( 'schema' => 'static-site-importer/materialization-runtime-sidecar/v2', 'receipt' => $receipt ) ) . "\n" );

$form_identity = str_repeat( 'a', 64 );
$envelopes     = Static_Site_Importer_Provider_Submission_Evidence::verify_runtime(
	array(
		'fixture_id'    => 'nimbus',
		'requirements'  => array(
			array(
				'required'       => true,
				'page_route'     => '/contact',
				'form_identity'  => $form_identity,
				'provider_id'    => 'wordpress/forms',
				'provider_owner' => 'wordpress',
			),
		),
		'sidecar_path'     => $sidecar,
		'output_path'      => $output,
		'output_artifact'  => 'nimbus/provider-submission-evidence.json',
	)
);

$assert( 1 === count( $envelopes ), 'one-form-envelope' );
$row = $envelopes[0] ?? array();
$assert( Static_Site_Importer_Provider_Submission_Evidence::SCHEMA === ( $row['schema'] ?? '' ), 'schema' );
$assert( 'nimbus' === ( $row['fixture_id'] ?? '' ), 'fixture-id' );
$assert( '/contact' === ( $row['page']['route'] ?? '' ) && '' === ( $row['page']['wordpress_entity_id'] ?? '' ), 'page-identity-unresolved-without-provider-runtime' );
$assert( $form_identity === ( $row['form_identity'] ?? '' ), 'form-identity' );
$assert( 'wordpress' === ( $row['provider']['ownership'] ?? '' ) && 'wordpress-local' === ( $row['provider']['submission_endpoint']['scope'] ?? '' ), 'wordpress-owned-endpoint' );
$assert( false === ( $row['provider']['submission_endpoint']['source_endpoint_contacted'] ?? true ), 'no-source-endpoint' );
$assert( array() === ( $row['network']['external_request_origins'] ?? null ), 'no-external-requests' );
$assert( false === ( $row['notification']['attempted'] ?? true ) && 'separate' === ( $row['notification']['capability'] ?? '' ), 'notification-separate-and-unattempted' );
$assert( 'failed' === ( $row['behaviors']['required_field_failure']['status'] ?? '' ), 'required-field-failure-without-provider' );
$assert( 'failed' === ( $row['behaviors']['valid_submission']['status'] ?? '' ) && 'wordpress-local' === ( $row['behaviors']['valid_submission']['local_receipt']['storage'] ?? '' ), 'valid-submission-fails-closed-without-provider' );
$assert( '' === ( $row['behaviors']['valid_submission']['local_receipt']['id'] ?? '' ) && '' === ( $row['behaviors']['valid_submission']['local_receipt']['sha256'] ?? '' ), 'no-synthetic-receipt' );
$assert( 'failed' === ( $row['behaviors']['provider_failure']['status'] ?? '' ), 'provider-failure-without-provider' );
$assert( 'failed' === ( $row['behaviors']['duplicate_submit']['status'] ?? '' ) && 0 === ( $row['behaviors']['duplicate_submit']['local_receipt_count'] ?? -1 ), 'duplicate-fails-closed-without-provider' );
$assert( str_repeat( 'b', 64 ) === ( $row['plan_hash'] ?? '' ), 'plan-hash' );
$assert( Static_Site_Importer_Provider_Submission_Evidence::canonical_sha256( $receipt ) === ( $row['materialization_receipt_sha256'] ?? '' ), 'receipt-hash' );
$assert( is_file( $output ), 'artifact-written' );

$unavailable = Static_Site_Importer_Provider_Submission_Evidence::verify_runtime(
	array(
		'fixture_id'   => 'nimbus',
		'requirements' => array(
			array(
				'required'      => true,
				'page_route'    => '/contact',
				'form_identity' => $form_identity,
				'provider_id'   => 'wordpress/forms',
			),
		),
		'sidecar_path' => $sidecar,
		'output_path'  => $output,
	)
);
$assert( 'failed' === ( $unavailable[0]['behaviors']['valid_submission']['status'] ?? '' ), 'missing-provider-fails-closed' );

@unlink( $sidecar );
@unlink( $output );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: provider submission evidence smoke passed (' . $assertions . " assertions)\n";
