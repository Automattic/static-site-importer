<?php
/**
 * Smoke coverage for loss classes in import reports, validation results, and finding packets.
 *
 * Run from the repository root:
 * php tests/smoke-report-diagnostic-loss-classes.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
$report['diagnostics'][] = array(
	'type'        => 'core_html_block',
	'source_path' => 'templates/front-page.html',
	'block_name'  => 'core/html',
);
$report['diagnostics'][] = array(
	'type'        => 'interaction_candidate',
	'source_path' => 'website/index.html',
	'selector'    => '.map iframe',
);
$report['diagnostics'][] = array(
	'type'        => 'svg_materialization_failure',
	'source_path' => 'assets/icon.svg',
);
$report['quality']['core_html_block_count']             = 1;
$report['quality']['interaction_candidate_count']       = 1;
$report['quality']['svg_materialization_failure_count'] = 1;

Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );

$assert( 'editable_approximation' === ( $report['diagnostics'][0]['loss_class'] ?? '' ), 'report-diagnostic-editable-loss-class' );
$assert( 'preserved_runtime_island' === ( $report['diagnostics'][1]['loss_class'] ?? '' ), 'report-diagnostic-runtime-loss-class' );
$assert( 'importer_materialization_bug' === ( $report['diagnostics'][2]['loss_class'] ?? '' ), 'report-diagnostic-importer-loss-class' );
$assert( 1 === ( $report['compact_summary']['loss_class_summary']['editable_approximation'] ?? 0 ), 'compact-summary-editable-count' );
$assert( 1 === ( $report['import_validation_result']['diagnostic_summary']['loss_class']['preserved_runtime_island'] ?? 0 ), 'validation-summary-runtime-count' );
$assert( 'editable_approximation' === ( $report['import_validation_result']['diagnostics'][0]['loss_class'] ?? '' ), 'validation-diagnostic-loss-class' );
$assert( 'acceptable_conversion' === ( $report['import_validation_result']['diagnostics'][0]['acceptability'] ?? '' ), 'validation-diagnostic-acceptability' );
$assert( 'replace_fallback_block' === ( $report['import_validation_result']['diagnostics'][0]['repair_class'] ?? '' ), 'validation-diagnostic-repair-class' );
$assert( 'core_html_block' === ( $report['import_validation_result']['diagnostics'][0]['source_diagnostic']['type'] ?? '' ), 'validation-diagnostic-source-diagnostic-type' );
$assert( 'editable_approximation' === ( $report['finding_packets']['packets'][0]['loss_class'] ?? '' ), 'finding-packet-loss-class' );
$assert( 'editable_approximation' === ( $report['finding_packets']['packets'][0]['routing']['loss_class'] ?? '' ), 'finding-packet-routing-loss-class' );
$assert( 'acceptable_conversion' === ( $report['finding_packets']['packets'][0]['acceptability'] ?? '' ), 'finding-packet-acceptability' );
$assert( 'replace_fallback_block' === ( $report['finding_packets']['packets'][0]['routing']['repair_class'] ?? '' ), 'finding-packet-routing-repair-class' );
$assert( 'core_html_block' === ( $report['finding_packets']['packets'][0]['source_diagnostic']['type'] ?? '' ), 'finding-packet-source-diagnostic-type' );

$runtime_only_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
$runtime_only_report['diagnostics'][] = array(
	'type'        => 'interaction_candidate',
	'source_path' => 'website/index.html',
	'selector'    => '.map iframe',
);
$runtime_only_report['quality']['interaction_candidate_count'] = 1;
$runtime_only_quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $runtime_only_report, array() );
$assert( true === ( $runtime_only_quality['pass'] ?? false ), 'preserved-runtime-island-does-not-fail-quality' );
$assert( array() === ( $runtime_only_quality['failure_reasons'] ?? null ), 'preserved-runtime-island-not-failure-reason' );
$assert( 'acceptable_preservation' === ( $runtime_only_report['import_validation_result']['diagnostics'][0]['acceptability'] ?? '' ), 'preserved-runtime-island-acceptability' );

$cleanup_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
$cleanup_report['diagnostics'][] = array(
	'type'        => 'static_site_fixture_diagnostic',
	'reason'      => '2',
	'source_path' => 'website/index.html',
);
$cleanup_report['diagnostics'][] = array(
	'type'                => 'core_html_block',
	'source_path'         => 'posts/page-home.post_content',
	'selector'            => 'iframe#map',
	'reason_code'         => 'generated_document_contains_core_html',
	'source_html_preview' => '<iframe id="map"></iframe>',
);
$cleanup_report['diagnostics'][] = array(
	'type'                  => 'unsupported_html_fallback',
	'source_path'           => 'posts/page-home.post_content',
	'selector'              => 'iframe#map',
	'reason_code'           => 'generated_document_contains_core_html',
	'emitted_block_preview' => '<!-- wp:html --><iframe id="map"></iframe><!-- /wp:html -->',
);
Static_Site_Importer_Report_Diagnostics::finalize_report( $cleanup_report, array() );
$assert( 1 === count( $cleanup_report['diagnostics'] ?? array() ), 'report-drops-count-only-and-dedupes' );
$assert( '<iframe id="map"></iframe>' === ( $cleanup_report['import_validation_result']['diagnostics'][0]['source_snippet'] ?? '' ), 'report-preserves-source-snippet' );
$assert( '<!-- wp:html --><iframe id="map"></iframe><!-- /wp:html -->' === ( $cleanup_report['import_validation_result']['diagnostics'][0]['observed_output'] ?? '' ), 'report-merges-observed-output' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: report diagnostic loss classes smoke passed (' . $assertions . " assertions)\n";
