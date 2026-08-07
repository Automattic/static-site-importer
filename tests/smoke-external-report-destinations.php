<?php
/**
 * Regression coverage for the explicit CLI report destination preflight.
 *
 * Run from the repository root:
 * php tests/smoke-external-report-destinations.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$root = sys_get_temp_dir() . '/ssi-report-destination-' . uniqid( '', true );
mkdir( $root, 0700, true );
$root = (string) realpath( $root );
$safe = $root . '/import-report.json';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $safe ), 'new report destination should be accepted' );
file_put_contents( $safe, 'existing' );
$assert( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $safe ), 'existing report target must be rejected' );
unlink( $safe );
file_put_contents( $root . '/finding-packets.json', 'existing sidecar' );
$assert( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $root . '/finding-packets.json' ), 'existing report sidecar must be rejected' );
unlink( $root . '/finding-packets.json' );
$assert( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $root . '/missing/import-report.json' ), 'missing report parent must be rejected' );
$assert( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $root . '/../escape.json' ), 'traversal report destination must be rejected' );

if ( function_exists( 'symlink' ) && symlink( sys_get_temp_dir(), $root . '/linked' ) ) {
	$assert( ! Static_Site_Importer_WordPress_Site_Plan_Materializer::safe_external_report_destination( $root . '/linked/import-report.json' ), 'symlink report parent must be rejected' );
	unlink( $root . '/linked' );
}

rmdir( $root );
echo "External report destination smoke passed.\n";
