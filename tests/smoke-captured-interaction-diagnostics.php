<?php
/**
 * Smoke coverage for captured interaction-state reporting.
 *
 * The Data Liberation sidecar records interaction states. The importer must
 * count omitted members — including partial selectable-set loss — instead of
 * reporting interaction_candidate_count: 0 when conversion dropped members
 * (Automattic/blocks-engine#2007, #2031).
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
$assert( 3 === ( $inventory['unrepresented_member_count'] ?? -1 ), 'unrepresented-count-matches-captured-when-nothing-materialized' );
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
$assert( 3 === count( $mixed_inventory['diagnostics'] ?? array() ), 'mixed-paths-emit-capture-gap-and-unmaterialized' );
$assert(
	array( 'website/alpha.html', 'website/beta.html', 'website/beta.html' ) === array_column( $mixed_inventory['diagnostics'], 'source_path' ),
	'diagnostics-are-ordered-by-source-path'
);
$assert(
	array(
		'captured_interaction_unmaterialized',
		'captured_interaction_capture_gap',
		'captured_interaction_unmaterialized',
	) === array_column( $mixed_inventory['diagnostics'], 'reason_code' ),
	'capture-gap-is-classified-separately-from-unmaterialized'
);
$assert( 2 === ( $mixed_inventory['diagnostics'][0]['captured_state_count'] ?? -1 ), 'alpha-diagnostic-count' );
$assert( 1 === ( $mixed_inventory['diagnostics'][1]['recorded_state_count'] ?? -1 ), 'beta-capture-gap-counts-click-failed-not-disproved-no-dialog' );
$assert( 1 === ( $mixed_inventory['diagnostics'][1]['context']['status_counts']['no-dialog'] ?? -1 ), 'capture-gap-status-counts-include-recorded-no-dialog' );
$assert( 1 === ( $mixed_inventory['diagnostics'][1]['context']['status_counts']['click-failed'] ?? -1 ), 'capture-gap-status-counts-include-recorded-click-failed' );
$assert( 'capture_side' === ( $mixed_inventory['diagnostics'][1]['context']['omission_class'] ?? '' ), 'beta-capture-gap-is-capture-side' );
$assert( 1 === ( $mixed_inventory['diagnostics'][2]['captured_state_count'] ?? -1 ), 'beta-unmaterialized-counts-only-captured-members' );
$assert( 'importer' === ( $mixed_inventory['diagnostics'][2]['context']['omission_class'] ?? '' ), 'beta-unmaterialized-is-importer-side' );
$assert( 4 === ( $mixed_inventory['unrepresented_member_count'] ?? -1 ), 'unrepresented-count-sums-capture-gap-and-unmaterialized' );

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
$assert( 1 === count( $no_dialog_inventory['diagnostics'] ?? array() ), 'capture-side-only-states-emit-a-capture-gap-diagnostic' );
$assert( 'captured_interaction_capture_gap' === ( $no_dialog_inventory['diagnostics'][0]['reason_code'] ?? '' ), 'capture-side-only-reason' );
$assert( 1 === ( $no_dialog_inventory['diagnostics'][0]['recorded_state_count'] ?? -1 ), 'capture-side-only-count-is-click-failed' );
$assert( 'capture_side' === ( $no_dialog_inventory['diagnostics'][0]['context']['omission_class'] ?? '' ), 'capture-side-only-class' );
$assert( 1 === ( $no_dialog_inventory['diagnostics'][0]['context']['status_counts']['no-dialog'] ?? -1 ), 'click-failed-gap-still-counts-recorded-no-dialog' );

$disproved_only = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page(
			'https://example.test/beta',
			array(
				$state( 'no-dialog' ),
				$state( 'no-dialog' ),
			)
		),
	),
);
$disproved_inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $disproved_only ), $plan );
$assert( 2 === ( $disproved_inventory['recorded_state_count'] ?? -1 ), 'disproved-selectable-set-states-are-still-counted' );
$assert( 2 === ( $disproved_inventory['status_counts']['no-dialog'] ?? -1 ), 'disproved-selectable-set-status-counts-are-recorded' );
$assert( array() === ( $disproved_inventory['diagnostics'] ?? null ), 'disproved-selectable-set-is-not-an-unsupported-capture-gap' );
$assert( 0 === ( $disproved_inventory['unrepresented_member_count'] ?? -1 ), 'disproved-selectable-set-has-no-unrepresented-members' );

$dialog_no_dialog = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	$artifact(
		array(
			'schema' => 'data-liberation/captured-interactions/v1',
			'pages'  => array(
				$page( 'https://example.test/beta', array( $state( 'no-dialog', 'dialog' ) ) ),
			),
		)
	),
	$plan
);
$assert( 'captured_interaction_capture_gap' === ( $dialog_no_dialog['diagnostics'][0]['reason_code'] ?? '' ), 'non-selectable-set-no-dialog-stays-a-capture-gap' );

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
$assert( 4 === ( $quality['interaction_candidate_count'] ?? -1 ), 'quality-count-sums-omitted-members' );
$assert( true === ( $quality['pass'] ?? false ), 'unmaterialized-interactions-do-not-fail-quality-pass' );
$assert( array() === ( $quality['failure_reasons'] ?? null ), 'unmaterialized-interactions-are-not-a-quality-failure-reason' );
$assert( ! empty( $quality['diagnostic_refs']['interaction_candidate_count'] ?? array() ), 'quality-refs-point-at-interaction-candidate-diagnostics' );

$clean_report  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$clean_quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $clean_report, array() );
$assert( 0 === ( $clean_quality['interaction_candidate_count'] ?? -1 ), 'missing-sidecar-leaves-quality-count-at-zero' );
$assert( true === ( $clean_quality['pass'] ?? false ), 'missing-sidecar-keeps-quality-pass' );

$classified = Static_Site_Importer_Diagnostic_Loss_Classes::classify( $row );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS === $classified, 'explicit-loss-class-survives-classifier' );

$tab_markup = static function ( int $panels ): string {
	$markup = '<!-- wp:tabs --><div class="wp-block-tabs">';
	for ( $index = 0; $index < $panels; $index++ ) {
		$markup .= '<!-- wp:tab-panel {"label":"Item ' . $index . '"} --><div class="wp-block-tab-panel"></div><!-- /wp:tab-panel -->';
	}

	return $markup . '</div><!-- /wp:tabs -->';
};

$selectable_states = static function ( int $captured, int $gaps ) use ( $state ): array {
	$states = array();
	for ( $index = 0; $index < $captured; $index++ ) {
		$states[] = $state( 'captured', 'selectable-set' );
	}
	for ( $index = 0; $index < $gaps; $index++ ) {
		$states[] = $state( 'click-failed', 'selectable-set' );
	}

	return $states;
};

$partial_envelope = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page( 'https://example.test/alpha', $selectable_states( 19, 0 ) ),
	),
);
$partial_plan = $plan;
$partial_plan['pages'][0]['resolved_block_markup'] = $tab_markup( 10 );
$partial = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $partial_envelope ), $partial_plan );
$assert( 9 === ( $partial['unrepresented_member_count'] ?? -1 ), 'partial-set-reports-dropped-member-count' );
$assert( 1 === count( $partial['diagnostics'] ?? array() ), 'partial-set-emits-one-unmaterialized-diagnostic' );
$assert( 'captured_interaction_unmaterialized' === ( $partial['diagnostics'][0]['reason_code'] ?? '' ), 'partial-set-is-importer-side' );
$assert( 9 === ( $partial['diagnostics'][0]['recorded_state_count'] ?? -1 ), 'partial-set-diagnostic-count' );

$full_plan = $plan;
$full_plan['pages'][0]['resolved_block_markup'] = $tab_markup( 19 );
$full = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $partial_envelope ), $full_plan );
$assert( 0 === ( $full['unrepresented_member_count'] ?? -1 ), 'fully-materialized-set-reports-zero' );
$assert( array() === ( $full['diagnostics'] ?? null ), 'fully-materialized-set-emits-no-diagnostic' );

$gap_envelope = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page( 'https://example.test/alpha', $selectable_states( 19, 9 ) ),
	),
);
$gap_plan = $plan;
$gap_plan['pages'][0]['resolved_block_markup'] = $tab_markup( 19 );
$gapped = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $gap_envelope ), $gap_plan );
$assert( 9 === ( $gapped['unrepresented_member_count'] ?? -1 ), 'capture-side-gaps-on-a-materialized-set-are-visible' );
$assert( 1 === count( $gapped['diagnostics'] ?? array() ), 'capture-side-gaps-do-not-emit-unmaterialized' );
$assert( 'captured_interaction_capture_gap' === ( $gapped['diagnostics'][0]['reason_code'] ?? '' ), 'materialized-set-gaps-are-capture-side' );

$be_failed = array();
for ( $index = 0; $index < 9; $index++ ) {
	$be_failed[] = array(
		'code'        => 'captured_selectable_set_member_failed',
		'message'     => 'A selectable-set member was omitted because capture did not produce region content.',
		'source_path' => 'website/alpha.html',
	);
}
$be_only = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	array( 'files' => array() ),
	array_merge( $plan, array( 'diagnostics' => $be_failed ) )
);
$assert( 9 === ( $be_only['unrepresented_member_count'] ?? -1 ), 'producer-member-failed-without-sidecar-is-counted' );
$assert( 'captured_interaction_capture_gap' === ( $be_only['diagnostics'][0]['reason_code'] ?? '' ), 'producer-member-failed-is-capture-side' );
$assert( 9 === ( $be_only['diagnostics'][0]['recorded_state_count'] ?? -1 ), 'producer-member-failed-aggregates-to-one-diagnostic' );
$disproved_producer = array(
	array(
		'code'    => 'captured_selectable_set_member_failed',
		'source'  => 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\CapturedSelectableSetProjector',
		'context' => array(
			'source_url' => 'https://example.test/beta',
			'status'     => 'no-dialog',
		),
	),
	array(
		'code'    => 'captured_selectable_set_member_failed',
		'source'  => 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\CapturedSelectableSetProjector',
		'context' => array(
			'source_url' => 'https://example.test/beta',
			'status'     => 'no-dialog',
		),
	),
);
$disproved_producer_inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	$artifact( $disproved_only ),
	array_merge( $plan, array( 'compiler_diagnostics' => $disproved_producer ) )
);
$assert( array() === ( $disproved_producer_inventory['diagnostics'] ?? null ), 'producer-no-dialog-is-not-an-unsupported-capture-gap' );
$assert( 2 === ( $disproved_producer_inventory['status_counts']['no-dialog'] ?? -1 ), 'producer-no-dialog-does-not-erase-recorded-status-counts' );
$click_failed_producer = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	array( 'files' => array() ),
	array_merge(
		$plan,
		array(
			'compiler_diagnostics' => array(
				array(
					'code'    => 'captured_selectable_set_member_failed',
					'source'  => 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\CapturedSelectableSetProjector',
					'context' => array(
						'status' => 'click-failed',
					),
				),
			),
		)
	)
);
$assert( 1 === count( $click_failed_producer['diagnostics'] ?? array() ), 'click-failed-producer-still-reports-one-gap' );
$assert( 1 === ( $click_failed_producer['diagnostics'][0]['context']['status_counts']['click-failed'] ?? -1 ), 'producer-only-gap-counts-the-recorded-status' );
$compiler_only = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	array( 'files' => array() ),
	array_merge( $plan, array( 'compiler_diagnostics' => $be_failed ) )
);
$assert( 9 === ( $compiler_only['unrepresented_member_count'] ?? -1 ), 'compiler-diagnostics-without-sidecar-are-counted' );
$duplicated_producer = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory(
	array( 'files' => array() ),
	array_merge(
		$plan,
		array(
			'diagnostics'          => $be_failed,
			'compiler_diagnostics' => $be_failed,
		)
	)
);
$assert( 9 === ( $duplicated_producer['unrepresented_member_count'] ?? -1 ), 'plan-and-compiler-member-failed-are-not-double-counted' );

$reconciled_plan = $gap_plan;
$reconciled_plan['diagnostics'] = $be_failed;
$reconciled = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $gap_envelope ), $reconciled_plan );
$assert( 9 === ( $reconciled['unrepresented_member_count'] ?? -1 ), 'sidecar-and-producer-gaps-are-not-double-counted' );
$assert( 1 === count( $reconciled['diagnostics'] ?? array() ), 'reconciled-gaps-emit-one-capture-side-diagnostic' );

$truncated = array(
	array(
		'code'        => 'captured_selectable_set_member_truncated',
		'source_path' => 'website/alpha.html',
	),
	array(
		'code'        => 'captured_selectable_set_member_truncated',
		'source_path' => 'website/alpha.html',
	),
);
$truncated_plan = $partial_plan;
$truncated_plan['diagnostics'] = $truncated;
$truncated_inventory = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $partial_envelope ), $truncated_plan );
$assert( 9 === ( $truncated_inventory['unrepresented_member_count'] ?? -1 ), 'truncated-members-are-not-added-on-top-of-tab-panel-residual' );

$disclosure_envelope = array(
	'schema' => 'data-liberation/captured-interactions/v1',
	'pages'  => array(
		$page(
			'https://example.test/alpha',
			array_merge( $selectable_states( 3, 0 ), array( $state( 'captured', 'disclosure' ), $state( 'captured', 'disclosure' ) ) )
		),
	),
);
$disclosure_plan = $plan;
$disclosure_plan['pages'][0]['resolved_block_markup'] = $tab_markup( 3 );
$disclosures = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $disclosure_envelope ), $disclosure_plan );
$assert( 2 === ( $disclosures['unrepresented_member_count'] ?? -1 ), 'materialized-selectable-set-still-reports-unmaterialized-disclosures' );

$partial_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
foreach ( $partial['diagnostics'] as $diagnostic ) {
	$partial_report->append_diagnostic( $diagnostic );
}
$partial_quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $partial_report, array() );
$assert( 9 === ( $partial_quality['interaction_candidate_count'] ?? -1 ), 'quality-count-reports-partial-loss' );
$assert( true === ( $partial_quality['pass'] ?? false ), 'partial-loss-does-not-fail-quality-pass' );
$assert( array() === ( $partial_quality['failure_reasons'] ?? null ), 'partial-loss-is-not-a-quality-failure-reason' );

$repeat_partial = Static_Site_Importer_Report_Diagnostics::captured_interaction_inventory( $artifact( $partial_envelope ), $partial_plan );
$assert( wp_json_encode( $partial ) === wp_json_encode( $repeat_partial ), 'partial-inventory-is-byte-stable' );
$assert( 0 === ( $empty['unrepresented_member_count'] ?? -1 ), 'empty-states-leave-unrepresented-at-zero' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: captured interaction diagnostics smoke passed (' . $assertions . " assertions)\n";
