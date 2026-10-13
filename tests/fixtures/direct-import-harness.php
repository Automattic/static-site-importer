<?php
/**
 * Standalone harness that drives the canonical import service end to end.
 *
 * Real source normalization, static-content admission, Direct artifact
 * freezing, Blocks Engine compilation and Receipt_Projection::compose() run;
 * only WordPress persistence is stubbed. The stub persists exactly the plan
 * pages it receives and writes the real projected import report, so a smoke
 * can assert which findings reach the stored report.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, Universal.Files.SeparateFunctionsFromOO -- Standalone harness without a WordPress bootstrap.

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['test_root']          = sys_get_temp_dir() . '/ssi-direct-harness-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_harness_filters'] = array();
$GLOBALS['ssi_harness_imports'] = array();

class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_delete_file( string $path ): void { @unlink( $path ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup.
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_key( $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ); }
function sanitize_text_field( $text ): string { return trim( (string) $text ); }
function wp_upload_dir(): array { return array( 'basedir' => $GLOBALS['test_root'] ); }
function get_current_blog_id(): int { return 17; }
function get_current_user_id(): int { return 827; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000827'; }
function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ssi_harness_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function add_filter( string $hook, callable $callback ): bool {
	$GLOBALS['ssi_harness_filters'][ $hook ][] = $callback;
	return true;
}
function do_action( string $hook, ...$args ): void {}
function __( string $message ): string { return $message; }

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/core-html-api.php';
require_once dirname( __DIR__, 2 ) . '/includes/rest.php';
require_once dirname( __DIR__, 2 ) . '/includes/cli.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-client-script-policy.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-website-artifact-import-input.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-direct-artifact-import.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-asset-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-document-metadata-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-block-document-reporter.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-receipt-projection.php';

/**
 * WordPress persistence is out of scope; the stub persists the plan pages it
 * receives and projects the real import report from that plan and the args.
 */
class Static_Site_Importer_Theme_Generator {
	public static function import_website_artifact( array $artifact, array $args = array() ) {
		if ( 'prepare' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
			return array(
				'status'                       => 'dependencies_prepared',
				'runtime_lifecycle_checkpoint' => '0123456789abcdef0123456789abcdef',
				'fresh_runtime'                => array( 'request_id' => '00000000-0000-4000-8000-000000000827' ),
			);
		}
		$compiled = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView::materialize( $args['compiled_artifact_result'] );
		$plan     = $compiled['wordpress_site_plan'];
		$pages    = array();
		foreach ( $plan['pages'] as $index => $page ) {
			$pages[ $page['source_path'] ] = 100 + $index;
		}
		$receipt    = array(
			'status'           => 'completed',
			'plan'             => $plan,
			'theme'            => array(
				'slug' => 'harness-fixture',
				'dir'  => $GLOBALS['test_root'] . '/theme',
			),
			'completed'        => array(
				'pages'                => $pages,
				'files'                => array(),
				'font_materialization' => array( 'files' => array() ),
			),
			'existing_matches' => array( 'pages' => array() ),
			'extensions'       => array(),
		);
		$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, $args, array(), array(), array(), (string) $args['import_run_id'], array(), array() );
		$report     = $projection['report'];
		$quality    = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
		$stored     = $GLOBALS['test_root'] . '/import-report-' . count( $GLOBALS['ssi_harness_imports'] ) . '.json';
		file_put_contents( $stored, wp_json_encode( $report->to_array() ) );
		$GLOBALS['ssi_harness_imports'][] = array(
			'plan'        => $plan,
			'report_path' => $stored,
		);
		return array(
			'theme_slug'               => 'harness-fixture',
			'theme_name'               => 'Harness Fixture',
			'report_path'              => $stored,
			'pages'                    => $pages,
			'quality'                  => $quality,
			'import_report'            => $report->to_array(),
			'import_report_summary'    => $report['compact_summary'],
			'import_validation_result' => $report['import_validation_result'],
			'materialization_receipt'  => array(
				'status'     => 'completed',
				'page_count' => count( $plan['pages'] ),
			),
		);
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-canonical-import-service.php';

wp_mkdir_p( $GLOBALS['test_root'] );

/**
 * Drive an import request through every continuation to its terminal result.
 *
 * @param array<string,mixed> $request Canonical import request.
 * @return array<string,mixed>
 */
function ssi_harness_drive( array $request ): array {
	for ( $attempt = 0; $attempt < 20; ++$attempt ) {
		$result = Static_Site_Importer_Canonical_Import_Service::import( $request );
		if ( empty( $result['continuation'] ) ) {
			return $result;
		}
		$request = static_site_importer_cli_apply_import_id( $request, (string) ( $result['import_id'] ?? '' ) );
		if ( 'dependencies_prepared' === ( $result['continuation_reason'] ?? '' ) ) {
			$request['runtime_lifecycle_phase']      = 'resume';
			$request['runtime_lifecycle_request_id'] = (string) ( $result['result']['fresh_runtime']['request_id'] ?? '' );
			$request['runtime_lifecycle_checkpoint'] = (string) ( $result['result']['runtime_lifecycle_checkpoint'] ?? '' );
		}
	}
	throw new RuntimeException( 'The harness import exceeded its continuation bound.' );
}

/**
 * The most recently persisted import: its plan and its stored report.
 *
 * @return array{plan:array<string,mixed>,report:array<string,mixed>}
 */
function ssi_harness_last_import(): array {
	$import = end( $GLOBALS['ssi_harness_imports'] );
	return array(
		'plan'   => is_array( $import ) ? $import['plan'] : array(),
		'report' => is_array( $import ) ? json_decode( (string) file_get_contents( $import['report_path'] ), true ) : array(),
	);
}

/**
 * Stored report rows with a code.
 *
 * @param array<string,mixed> $report Stored import report.
 * @return array<int,array<string,mixed>>
 */
function ssi_harness_findings( array $report, string $code ): array {
	return array_values( array_filter( $report['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && $code === ( $row['code'] ?? '' ) ) );
}

/** Remove the harness workspace. */
function ssi_harness_cleanup(): void {
	if ( ! is_dir( $GLOBALS['test_root'] ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['test_root'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $GLOBALS['test_root'] );
}
