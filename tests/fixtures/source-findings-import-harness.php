<?php
/**
 * Standalone import harness for source-findings smokes.
 *
 * Loads the plugin from `$GLOBALS['ssi_harness_root']` (default: this checkout)
 * with WordPress stubs, and returns an import driver. A root other than this
 * checkout lets the equivalence smoke record the same import against another
 * revision of the plugin. WordPress persistence is out of scope: the Theme
 * Generator stub persists exactly the plan pages it receives and projects the
 * real import report from that plan.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.Files.FileName.InvalidClassFileName, Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standalone harness with WordPress stubs.

$ssi_harness_root = rtrim( (string) ( $GLOBALS['ssi_harness_root'] ?? dirname( __DIR__, 2 ) ), '/' );

define( 'ABSPATH', $ssi_harness_root . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', $ssi_harness_root . '/' );

$GLOBALS['test_root']              = sys_get_temp_dir() . '/ssi-source-findings-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_harness_filters']    = array();
$GLOBALS['ssi_harness_imports']    = array();
$GLOBALS['ssi_harness_theme_slug'] = 'source-findings-fixture';

class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) {
	return json_encode( $value, $options ); }
function wp_mkdir_p( string $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0777, true ); }
function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/'; }
function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ); }
function sanitize_text_field( $text ): string {
	return trim( (string) $text ); }
function wp_upload_dir(): array {
	return array( 'basedir' => $GLOBALS['test_root'] ); }
function get_current_blog_id(): int {
	return 17; }
function get_current_user_id(): int {
	return 827; }
function wp_generate_uuid4(): string {
	return '00000000-0000-4000-8000-000000000827'; }
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
function __( string $message ): string {
	return $message; }

require_once $ssi_harness_root . '/vendor/autoload.php';
require_once $ssi_harness_root . '/tests/fixtures/core-html-api.php';
foreach (
	array(
		'rest.php',
		'cli.php',
		'class-static-site-importer-artifact-run.php',
		'class-static-site-importer-content-policy.php',
		'class-static-site-importer-client-script-policy.php',
		'class-static-site-importer-website-artifact-import-input.php',
		'class-static-site-importer-direct-artifact-import.php',
		'class-static-site-importer-diagnostic-contract.php',
		'class-static-site-importer-report-diagnostics.php',
		'class-static-site-importer-asset-reporter.php',
		'class-static-site-importer-document-metadata-reporter.php',
		'class-static-site-importer-block-document-reporter.php',
		'class-static-site-importer-receipt-projection.php',
	) as $ssi_harness_include
) {
	require_once $ssi_harness_root . '/includes/' . $ssi_harness_include;
}

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
				'slug' => $GLOBALS['ssi_harness_theme_slug'],
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
			'theme_slug'               => $GLOBALS['ssi_harness_theme_slug'],
			'theme_name'               => 'Source Findings Fixture',
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

require_once $ssi_harness_root . '/includes/class-static-site-importer-canonical-import-service.php';

/**
 * Import a two-page site plus extra artifact files, driving every continuation.
 *
 * @param array<string,string> $extra_files Artifact path => bytes, carried base64 like a staged ZIP entry.
 * @return array{result:array<string,mixed>,plan:array<string,mixed>,stored:array<string,mixed>,summary:array<string,mixed>}
 */
function ssi_source_findings_import( array $extra_files ): array {
	$document = static fn ( string $title, string $body ): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="styles.css"></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
	$files    = array(
		array(
			'path'    => 'website/index.html',
			'content' => $document( 'Home', '<p>Welcome.</p><a href="about.html">About</a>' ),
		),
		array(
			'path'    => 'website/about.html',
			'content' => $document( 'About', '<p>About us.</p><a href="index.html">Home</a>' ),
		),
		array(
			'path'    => 'website/styles.css',
			'content' => 'body{font-family:serif}',
		),
	);
	foreach ( $extra_files as $path => $bytes ) {
		$files[] = array(
			'path'           => $path,
			'content_base64' => base64_encode( $bytes ),
		);
	}
	$request = array(
		'operation' => 'apply',
		'slug'      => $GLOBALS['ssi_harness_theme_slug'],
		'source'    => array(
			'type'       => 'files',
			'entrypoint' => 'website/index.html',
			'files'      => $files,
		),
	);
	for ( $attempt = 0; $attempt < 20; ++$attempt ) {
		$result = Static_Site_Importer_Canonical_Import_Service::import( $request );
		if ( empty( $result['continuation'] ) ) {
			$last = end( $GLOBALS['ssi_harness_imports'] );
			return array(
				'result'  => $result,
				'plan'    => is_array( $last ) ? $last['plan'] : array(),
				'stored'  => is_array( $last ) ? (array) json_decode( (string) file_get_contents( $last['report_path'] ), true ) : array(),
				'summary' => $result['result']['import_report_summary'] ?? array(),
			);
		}
		$request = static_site_importer_cli_apply_import_id( $request, (string) ( $result['import_id'] ?? '' ) );
		if ( 'dependencies_prepared' === ( $result['continuation_reason'] ?? '' ) ) {
			$request['runtime_lifecycle_phase']      = 'resume';
			$request['runtime_lifecycle_request_id'] = (string) ( $result['result']['fresh_runtime']['request_id'] ?? '' );
			$request['runtime_lifecycle_checkpoint'] = (string) ( $result['result']['runtime_lifecycle_checkpoint'] ?? '' );
		}
	}
	throw new RuntimeException( 'The source-findings fixture exceeded its continuation bound.' );
}
