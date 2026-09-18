<?php
/**
 * Smoke coverage for captured interaction-state reporting.
 *
 * The Data Liberation sidecar records interaction states. The importer must
 * count them and diagnose captured states that conversion did not materialize,
 * instead of reporting interaction_candidate_count: 0 on a clean quality_pass
 * (Automattic/blocks-engine#2007).
 *
 * Run from the repository root:
 * php tests/smoke-captured-interaction-diagnostics.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$state = static function ( string $status, string $kind = 'selectable-set' ): array {
	return array(
		'status' => $status,
		'kind'   => $kind,
	);
};

$page = static function ( string $source_url, array $states ): array {
	return array(
		'schema'    => 'data-liberation/interaction-states/v2',
		'sourceUrl' => $source_url,
		'states'    => $states,
	);
};

$artifact = static function ( array $envelope, string $path = 'interaction-states.json' ): array {
	$encoded = wp_json_encode( $envelope );

	return array(
		'files' => array(
			array(
				'path'    => $path,
				'content' => false === $encoded ? '{}' : $encoded,
			),
		),
	);
};

$envelope = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page(
			'https://example.test/alpha',
			array(
				$state( 'captured' ),
				$state( 'captured' ),
				$state( 'captured' ),
			)
		),
	),
);

$plan = array(
	'pages' => array(
		array(
			'source_path' => 'website/alpha.html',
			'route'       => array( 'path' => '/alpha' ),
		),
		array(
			'source_path' => 'website/beta.html',
			'route'       => array( 'path' => '/beta' ),
		),
	),
);

$inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $envelope ), $plan );
$assert( 3 === ( $inventory['recorded_state_count'] ?? -1 ), 'captured-states-are-counted' );
$assert( 3 === ( $inventory['captured_state_count'] ?? -1 ), 'captured-status-is-distinguished' );
$assert( 1 === count( $inventory['diagnostics'] ?? array() ), 'unmaterialized-captured-states-emit-one-diagnostic' );
$row = $inventory['diagnostics'][0] ?? array();
$assert( 'website/alpha.html' === ( $row['source_path'] ?? '' ), 'diagnostic-uses-plan-source-path' );
$assert( 3 === ( $row['captured_state_count'] ?? -1 ), 'diagnostic-carries-captured-count' );
$assert( 'captured_interaction_unmaterialized' === ( $row['reason_code'] ?? '' ), 'diagnostic-reason' );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS === ( $row['loss_class'] ?? '' ), 'loss-class-is-unsupported-loss' );
$assert( 'interaction_candidate' === ( $row['type'] ?? '' ), 'diagnostic-type-is-existing-interaction-candidate' );

$repeat = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $envelope ), $plan );
$assert( wp_json_encode( $inventory ) === wp_json_encode( $repeat ), 'inventory-is-byte-stable' );

$missing = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( array( 'files' => array() ), $plan );
$assert( 0 === ( $missing['recorded_state_count'] ?? -1 ) && array() === ( $missing['diagnostics'] ?? null ), 'missing-artifact-is-zero-and-silent' );

$empty_states = $envelope;
$empty_states['pages'][0]['states'] = array();
$empty = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $empty_states ), $plan );
$assert( 0 === ( $empty['recorded_state_count'] ?? -1 ) && array() === ( $empty['diagnostics'] ?? null ), 'empty-states-are-zero-and-silent' );

$malformed = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	array(
		'files' => array(
			array(
				'path'    => 'interaction-states.json',
				'content' => '{not-json',
			),
		),
	),
	$plan
);
$assert( 0 === ( $malformed['recorded_state_count'] ?? -1 ) && array() === ( $malformed['diagnostics'] ?? null ), 'malformed-json-degrades-without-throwing' );

$legacy = array(
	'schema'    => 'data-liberation/interaction-states/v1',
	'sourceUrl' => 'https://example.test/alpha',
	'states'    => array(
		$state( 'captured', 'disclosure' ),
		$state( 'captured', 'disclosure' ),
	),
);
$legacy_inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $legacy ), $plan );
$assert( 2 === ( $legacy_inventory['recorded_state_count'] ?? -1 ), 'legacy-page-envelope-still-counts' );
$assert( 1 === count( $legacy_inventory['diagnostics'] ?? array() ), 'legacy-page-envelope-still-diagnoses' );

$website_rooted = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	$artifact( $envelope, 'website/interaction-states.json' ),
	$plan
);
$assert( 3 === ( $website_rooted['recorded_state_count'] ?? -1 ), 'website-rooted-sidecar-is-found' );

$mixed = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page(
			'https://example.test/beta',
			array(
				$state( 'captured' ),
				$state( 'no-dialog' ),
				$state( 'click-failed' ),
			)
		),
		$page(
			'https://example.test/alpha',
			array(
				$state( 'captured' ),
				$state( 'captured' ),
			)
		),
	),
);
$mixed_inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $mixed ), $plan );
$assert( 5 === ( $mixed_inventory['recorded_state_count'] ?? -1 ), 'recorded-count-includes-non-captured-statuses' );
$assert( 3 === ( $mixed_inventory['captured_state_count'] ?? -1 ), 'captured-count-excludes-non-captured-statuses' );
$assert( 1 === ( $mixed_inventory['status_counts']['no-dialog'] ?? -1 ), 'no-dialog-status-is-counted' );
$assert( 1 === ( $mixed_inventory['status_counts']['click-failed'] ?? -1 ), 'click-failed-status-is-counted' );
$assert( 2 === count( $mixed_inventory['diagnostics'] ?? array() ), 'each-path-with-captured-states-gets-a-diagnostic' );
$assert(
	array( 'website/alpha.html', 'website/beta.html' ) === array_column( $mixed_inventory['diagnostics'], 'source_path' ),
	'diagnostics-are-ordered-by-source-path'
);
$assert( 2 === ( $mixed_inventory['diagnostics'][0]['captured_state_count'] ?? -1 ), 'alpha-diagnostic-count' );
$assert( 1 === ( $mixed_inventory['diagnostics'][1]['captured_state_count'] ?? -1 ), 'beta-diagnostic-count' );
$assert( 3 === ( $mixed_inventory['diagnostics'][1]['recorded_state_count'] ?? -1 ), 'beta-diagnostic-records-non-captured-siblings' );

$no_dialog_only = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page(
			'https://example.test/beta',
			array(
				$state( 'no-dialog' ),
				$state( 'click-failed' ),
			)
		),
	),
);
$no_dialog_inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $no_dialog_only ), $plan );
$assert( 2 === ( $no_dialog_inventory['recorded_state_count'] ?? -1 ), 'non-captured-only-states-are-still-counted' );
$assert( array() === ( $no_dialog_inventory['diagnostics'] ?? null ), 'non-captured-only-states-do-not-diagnose-a-materialization-gap' );

$already_reported = $plan;
$already_reported['diagnostics'] = array(
	array(
		'type'        => 'interaction_candidate',
		'source_path' => 'website/alpha.html',
	),
);
$deduped = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $envelope ), $already_reported );
$assert( 3 === ( $deduped['recorded_state_count'] ?? -1 ), 'already-reported-path-still-counts' );
$assert( array() === ( $deduped['diagnostics'] ?? null ), 'already-reported-path-is-not-diagnosed-twice' );

$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
foreach ( $mixed_inventory['diagnostics'] as $diagnostic ) {
	$report->append_diagnostic( $diagnostic );
}
$quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $report, array() );
$assert( 5 === ( $quality['interaction_candidate_count'] ?? -1 ), 'quality-count-sums-recorded-states' );
$assert( true === ( $quality['pass'] ?? false ), 'unmaterialized-interactions-do-not-fail-quality-pass' );
$assert( array() === ( $quality['failure_reasons'] ?? null ), 'unmaterialized-interactions-are-not-a-quality-failure-reason' );
$assert( ! empty( $quality['diagnostic_refs']['interaction_candidate_count'] ?? array() ), 'quality-refs-point-at-interaction-candidate-diagnostics' );

$clean_report  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$clean_quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $clean_report, array() );
$assert( 0 === ( $clean_quality['interaction_candidate_count'] ?? -1 ), 'missing-sidecar-leaves-quality-count-at-zero' );
$assert( true === ( $clean_quality['pass'] ?? false ), 'missing-sidecar-keeps-quality-pass' );

$classified = Static_Site_Importer_Diagnostic_Loss_Classes::classify( $row );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS === $classified, 'explicit-loss-class-survives-classifier' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: captured interaction diagnostics smoke passed (' . $assertions . " assertions)\n";
