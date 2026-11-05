<?php
/**
 * Pages the site plan reports as having lost compiled text fail import quality.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone smoke-test bootstrap and assertions.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';

foreach ( array( 0 => true, 3 => false ) as $lost => $pass ) {
	$report = Static_Site_Importer_Import_Report::from_array( array() );
	$report['blocks_engine'] = array( 'plan_summary' => array( 'quality' => array( 'metrics' => array( 'content_loss_count' => $lost ) ) ) );
	$quality = Static_Site_Importer_Quality_Gates::finalize_quality_report( $report, array() );
	if ( $lost !== $quality['content_loss_count'] || $pass !== $quality['pass'] || ( ! $pass && ! in_array( 'content_loss_abort', $quality['failure_reasons'], true ) ) ) {
		throw new RuntimeException( "Plan content_loss_count {$lost} must reach the gate: " . json_encode( $quality ) );
	}
}

echo "Plan content loss quality smoke passed\n";
