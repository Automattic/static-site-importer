<?php
/**
 * Blocks Engine design-system metrics reach the receipt, import report,
 * owner-handoff evidence and CLI completion summary unchanged (#2118).
 *
 * Run from the repository root:
 * php tests/smoke-design-system-report-present.php
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/design-system-report-harness.php';

$assert        = ssi_design_system_assert();
$design_system = ssi_design_system_fixture();
$plan          = ssi_design_system_plan( true );

// The plan's theme.design_system is carried as-is.
$assert( $design_system === Static_Site_Importer_Site_Plan_Preparation::design_system( $plan ), 'plan theme.design_system is read unchanged' );

$receipt = ssi_design_system_receipt( $plan );
$assert( 'completed' === $receipt['status'], 'design system never fails the import' );
$assert( $design_system === ( $receipt['design_system'] ?? null ), 'receipt carries design_system unchanged' );
$summary = (string) ( $receipt['design_system_summary'] ?? '' );
$assert( 'Design system: palette base #ffffff, contrast #111827, accent #2563eb; heading Playfair Display, body Inter; preset_share 0.9632; restyle_coverage 0.875; css ratio 0.42' === $summary, 'receipt summary is one line: ' . $summary );
$assert( ! str_contains( $summary, "\n" ), 'summary is a single line' );

$report = ssi_design_system_report( $plan, $receipt )->to_array();
$assert( $design_system === ( $report['design_system'] ?? null ), 'import-report.json carries design_system unchanged' );
$assert( 0.9632 === $report['design_system']['metrics']['preset_share'], 'import report keeps preset_share' );
$assert( 0.875 === $report['design_system']['metrics']['restyle_coverage'], 'import report keeps restyle_coverage' );
$assert( $summary === ( $report['compact_summary']['design_system_summary'] ?? null ), 'completion summary carries the one-line summary' );
$assert(
	array(
		'schema'  => 'blocks-engine/design-system/v1',
		'metrics' => $design_system['metrics'],
	) === ( $report['owner_handoff_evidence']['design_system'] ?? null ),
	'owner handoff evidence records the metrics'
);
$assert( 12 === count( $report['owner_handoff_evidence']['dimensions'] ), 'design system is not an owner-handoff dimension' );

$cli = static_site_importer_cli_materialization_summary( $receipt, array() );
$assert( $summary === ( $cli['design_system_summary'] ?? null ), 'CLI completion summary prints the one-line summary' );

// When only the editability report carries the design system, it is used.
$plan_without = ssi_design_system_plan( false );
$editability  = array(
	'schema'        => 'blocks-engine/php-transformer/editability-report/v2',
	'design_system' => array(
		'schema'  => 'blocks-engine/design-system/v1',
		'metrics' => $design_system['metrics'],
	),
);
$fallback = ssi_design_system_receipt( $plan_without, $editability );
$assert( $editability['design_system'] === ( $fallback['design_system'] ?? null ), 'editability report design_system is accepted' );
$assert( 'Design system: preset_share 0.9632; restyle_coverage 0.875; css ratio 0.42' === ( $fallback['design_system_summary'] ?? null ), 'metrics-only summary omits palette and fonts' );

// A single family serving both roles is emitted by Blocks Engine as `body` only.
$single                  = $design_system;
$single['font_families'] = array( $design_system['font_families'][1] );
$single_summary          = Static_Site_Importer_Site_Plan_Receipt::design_system_summary( $single );
$assert( str_contains( $single_summary, 'heading Inter, body Inter' ), 'one family serves heading and body' );

echo "design-system report (present) smoke passed\n";
