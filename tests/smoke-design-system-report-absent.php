<?php
/**
 * An older Blocks Engine plan without a design system leaves the
 * design_system section absent and the import unaffected (#2118).
 *
 * Run from the repository root:
 * php tests/smoke-design-system-report-absent.php
 *
 * @package StaticSiteImporter
 */

require_once __DIR__ . '/fixtures/design-system-report-harness.php';

$assert = ssi_design_system_assert();
$plan   = ssi_design_system_plan( false );

$assert( array() === Static_Site_Importer_Site_Plan_Preparation::design_system( $plan ), 'no design system without the plan section' );
$assert( array() === Static_Site_Importer_Site_Plan_Preparation::design_system( $plan, array( 'schema' => 'blocks-engine/php-transformer/editability-report/v2' ) ), 'editability report without design_system adds none' );
$malformed                           = $plan;
$malformed['theme']['design_system'] = array( 'schema' => 'blocks-engine/design-system/v1' );
$assert( array() === Static_Site_Importer_Site_Plan_Preparation::design_system( $malformed ), 'a design system without metrics is ignored' );

$receipt = ssi_design_system_receipt( $plan );
$assert( 'completed' === $receipt['status'], 'absent design system never fails the import' );
$assert( ! array_key_exists( 'design_system', $receipt ), 'receipt has no design_system section' );
$assert( ! array_key_exists( 'design_system_summary', $receipt ), 'receipt has no design system summary' );
$assert( 'passed' === $receipt['editability_report']['status'], 'editability decision is unchanged' );

$report = ssi_design_system_report( $plan, $receipt )->to_array();
$assert( ! array_key_exists( 'design_system', $report ), 'import-report.json has no design_system section' );
$assert( ! array_key_exists( 'design_system_summary', $report['compact_summary'] ), 'completion summary has no design system line' );
$assert( ! array_key_exists( 'design_system', $report['owner_handoff_evidence'] ), 'owner handoff evidence has no design_system' );

$cli = static_site_importer_cli_materialization_summary( $receipt, array() );
$assert( ! array_key_exists( 'design_system_summary', $cli ), 'CLI completion summary has no design system line' );

echo "design-system report (absent) smoke passed\n";
