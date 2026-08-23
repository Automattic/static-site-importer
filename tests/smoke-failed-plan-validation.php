<?php
/** Run: php tests/smoke-failed-plan-validation.php */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-failed-plan-validation.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$diagnostics = array();
for ( $index = 0; $index < 75; ++$index ) {
	$diagnostics[] = array(
		'type'        => 'document_nesting',
		'severity'    => 'error',
		'reason_code' => 'document_nesting',
		'message'     => 'Nested document ' . $index,
	);
}
$plan = array(
	'schema'      => 'blocks-engine/wordpress-site-plan/v2',
	'quality'     => array( 'pass' => false, 'metrics' => array( 'fallback_count' => 0 ), 'failure_reasons' => array( 'document_nesting', 'empty_wrapper' ) ),
	'diagnostics' => $diagnostics,
);
$original_plan = $plan;
$artifacts = Static_Site_Importer_Failed_Plan_Validation::build( $plan, array( 'slug' => 'zero-fallback-failure', 'fail_on_quality' => true ) );

$assert( false === ( $artifacts['import_report']['quality']['pass'] ?? true ), 'zero-fallback canonical quality failure remains failed' );
$assert( true === ( $artifacts['import_report']['quality']['fail_import'] ?? false ), 'failed plan is marked terminal in the standard quality shape' );
$assert( 0 === ( $artifacts['import_report']['quality']['fallback_count'] ?? -1 ), 'zero fallback evidence is retained' );
$assert( 50 === count( $artifacts['import_report']['diagnostics'] ?? array() ), 'failed-plan report bounds diagnostics' );
$assert( true === ( $artifacts['import_report']['diagnostics_truncated'] ?? false ), 'failed-plan report records truncation' );
$assert( 75 === ( $artifacts['import_report']['diagnostic_count'] ?? 0 ), 'failed-plan report retains original diagnostic count' );
$assert( 'blocks-engine/import-validation-result/v1' === ( $artifacts['import_validation_result']['schema'] ?? '' ), 'standard validation artifact schema is preserved' );
$assert( 'blocks-engine/finding-packets/v1' === ( $artifacts['finding_packets']['schema'] ?? '' ), 'standard finding packet schema is preserved' );
$assert( $plan === $original_plan, 'failed-plan evidence generation does not mutate the canonical plan' );

$root = sys_get_temp_dir() . '/ssi-failed-plan-validation-' . uniqid( '', true );
mkdir( $root );
$paths = Static_Site_Importer_Failed_Plan_Validation::persist( $artifacts, $root . '/import-report.json' );
$assert( 3 === count( $paths ), 'explicit report destination writes all standard artifacts' );
$assert( is_file( $paths['import_report'] ) && is_file( $paths['import_validation_result'] ) && is_file( $paths['finding_packets'] ), 'explicit report artifacts exist' );
$persisted = json_decode( (string) file_get_contents( $paths['import_report'] ), true );
$assert( 'pre_materialization_quality_admission' === ( $persisted['failure_context']['stage'] ?? '' ), 'persisted report identifies its non-mutating admission stage' );

$paths_again = Static_Site_Importer_Failed_Plan_Validation::persist( $artifacts, $root . '/import-report.json' );
$assert( $paths === $paths_again, 'retrying a failed plan refreshes its owned report artifacts' );
foreach ( $paths as $path ) {
	unlink( $path );
}
rmdir( $root );

echo "Failed-plan validation smoke passed.\n";
