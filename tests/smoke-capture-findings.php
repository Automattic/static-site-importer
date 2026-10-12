<?php
/**
 * Smoke coverage for Data Liberation Agent capture findings in an import.
 *
 * A DLA website artifact carries `capture-findings.json`
 * (`data-liberation-agent/capture-findings/v1`) at its root. The import reads
 * it at intake, leaves it out of the compiled site, and carries its rows into
 * the stored report and compact summary as `data-liberation-agent` findings.
 * An artifact without the file imports as before; a malformed file records a
 * single `capture_findings_unreadable` warning and never fails the import.
 *
 * Run from the repository root:
 * php tests/smoke-capture-findings.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.Files.FileName.InvalidClassFileName, Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.PHP.YodaConditions.NotYoda, WordPress.WP.AlternativeFunctions -- Standalone smoke with WordPress stubs.

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );

$test_root                      = sys_get_temp_dir() . '/ssi-capture-findings-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_capture_filters'] = array();
$GLOBALS['ssi_capture_imports'] = array();

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
	foreach ( $GLOBALS['ssi_capture_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function add_filter( string $hook, callable $callback ): bool {
	$GLOBALS['ssi_capture_filters'][ $hook ][] = $callback;
	return true;
}
function do_action( string $hook, ...$args ): void {}
function __( string $message ): string {
	return $message; }

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once dirname( __DIR__ ) . '/includes/rest.php';
require_once dirname( __DIR__ ) . '/includes/cli.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-client-script-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-website-artifact-import-input.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-direct-artifact-import.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-asset-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document-metadata-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-block-document-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-receipt-projection.php';

/**
 * WordPress persistence is out of scope here; the stub persists exactly the
 * plan pages it receives and projects the real import report from that plan.
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
				'slug' => 'capture-findings-fixture',
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
		$stored     = $GLOBALS['test_root'] . '/import-report-' . count( $GLOBALS['ssi_capture_imports'] ) . '.json';
		file_put_contents( $stored, wp_json_encode( $report->to_array() ) );
		$GLOBALS['ssi_capture_imports'][] = array(
			'plan'        => $plan,
			'report_path' => $stored,
		);
		return array(
			'theme_slug'               => 'capture-findings-fixture',
			'theme_name'               => 'Capture Findings Fixture',
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$document = static fn ( string $title, string $body ): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="styles.css"></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';
$findings = array(
	'schema'           => 'data-liberation-agent/capture-findings/v1',
	'entrypoint'       => 'index.html',
	'complete'         => false,
	'usable'           => true,
	'summary'          => array(
		'routes_discovered'  => 3,
		'routes_captured'    => 2,
		'routes_failed'      => 1,
		'routes_omitted'     => 0,
		'unresolved_anchors' => 0,
	),
	'findings'         => array(
		array(
			'code'     => 'route_capture_degraded',
			'severity' => 'warning',
			'unit'     => 'route',
			'url'      => 'https://example.test/about/',
			'path'     => 'about.html',
			'reason'   => 'mobile/screenshot-scrolled: screenshot timeout',
			'metrics'  => array( 'failedStages' => 1 ),
		),
		array(
			'code'     => 'route_export_failed',
			'severity' => 'error',
			'unit'     => 'route',
			'url'      => 'https://example.test/pricing/',
			'reason'   => 'cannot export Pricing',
		),
	),
	'omitted_findings' => 0,
	'missing_routes'   => array(
		array(
			'url'    => 'https://example.test/pricing/',
			'code'   => 'route_export_failed',
			'reason' => 'cannot export Pricing',
		),
		array(
			'url'    => 'https://example.test/slow/',
			'code'   => 'route_capture_failed',
			'reason' => 'desktop/goto: Navigation timeout',
		),
	),
);
$input    = static function ( ?string $capture_findings ) use ( $document ): array {
	$files = array(
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
	if ( null !== $capture_findings ) {
		// Base64, as a staged ZIP archive carries its textual entries.
		$files[] = array(
			'path'           => 'website/capture-findings.json',
			'content_base64' => base64_encode( $capture_findings ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixture payload.
		);
	}
	return array(
		'operation' => 'apply',
		'slug'      => 'capture-findings-fixture',
		'source'    => array(
			'type'       => 'files',
			'entrypoint' => 'website/index.html',
			'files'      => $files,
		),
	);
};
$drive    = static function ( array $request ): array {
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
	throw new RuntimeException( 'The capture-findings fixture exceeded its continuation bound.' );
};
$import   = static function ( ?string $capture_findings, string $label ) use ( $drive, $input, $assert ): array {
	$result = $drive( $input( $capture_findings ) );
	$last   = end( $GLOBALS['ssi_capture_imports'] );
	$plan   = is_array( $last ) ? $last['plan'] : array();
	$stored = is_array( $last ) ? json_decode( (string) file_get_contents( $last['report_path'] ), true ) : array();
	$assert( ! empty( $result['success'] ) && empty( $result['continuation'] ), "$label: the import succeeds" );
	$summary = $result['result']['import_report_summary'] ?? array();
	$assert( 'completed' === ( $summary['status'] ?? '' ) && false === ( $summary['fail_import'] ?? true ), "$label: the compact summary reports a completed import" );
	$persisted = array_column( $plan['pages'] ?? array(), 'source_path' );
	sort( $persisted );
	$assert( array( 'website/about.html', 'website/index.html' ) === $persisted, "$label: both pages import" );
	$assert( ! str_contains( (string) wp_json_encode( $plan ), 'capture-findings.json' ), "$label: the findings file is not site content" );
	return array(
		'stored'  => $stored,
		'summary' => $summary,
	);
};
$rows     = static fn ( array $diagnostics, string $field, string $value ): array => array_values( array_filter( $diagnostics, static fn ( $row ): bool => is_array( $row ) && $value === ( $row[ $field ] ?? null ) ) );

// Findings travel into the stored report and the compact summary.
$with    = $import( (string) wp_json_encode( $findings ), 'with-findings' );
$stored  = $with['stored'];
$summary = $with['summary'];
$capture = $rows( $stored['diagnostics'] ?? array(), 'owning_layer', 'data-liberation-agent' );
$assert( 3 === count( $capture ), 'with-findings: the stored report carries the two findings and the uncovered missing route' );
$degraded = $rows( $capture, 'code', 'route_capture_degraded' )[0] ?? array();
$assert( 'route' === ( $degraded['unit'] ?? '' ) && 'warning' === ( $degraded['severity'] ?? '' ) && 'website/about.html' === ( $degraded['source_path'] ?? '' ), 'with-findings: the degraded row is a route-unit warning located at its exported page' );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS === ( $degraded['loss_class'] ?? '' ) && 'route_capture_degraded' === ( $degraded['reason_code'] ?? '' ), 'with-findings: the degraded row is a degraded-family loss' );
$assert( 1 === ( $degraded['context']['metrics']['failedStages'] ?? null ) && 'https://example.test/about/' === ( $degraded['context']['url'] ?? '' ), 'with-findings: the degraded row keeps its url and metrics' );
$assert( 'mobile/screenshot-scrolled: screenshot timeout' === ( $degraded['message'] ?? '' ), 'with-findings: the degraded row keeps its reason as the message' );
$dropped = $rows( $capture, 'code', 'route_export_failed' )[0] ?? array();
$assert( 'route' === ( $dropped['unit'] ?? '' ) && 'error' === ( $dropped['severity'] ?? '' ) && 'https://example.test/pricing/' === ( $dropped['source_path'] ?? '' ), 'with-findings: the dropped route is a route-unit error located at its URL' );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED === ( $dropped['loss_class'] ?? '' ), 'with-findings: the dropped route is an omitted-family loss' );
$slow = $rows( $capture, 'code', 'route_capture_failed' )[0] ?? array();
$assert( 'https://example.test/slow/' === ( $slow['source_path'] ?? '' ) && Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED === ( $slow['loss_class'] ?? '' ), 'with-findings: a missing route without a finding row is still counted once' );
$assert( 2 === ( $summary['loss_class_summary'][ Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED ] ?? 0 ), 'with-findings: the compact summary counts both missing routes' );
$assert( 1 <= ( $summary['loss_class_summary'][ Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS ] ?? 0 ), 'with-findings: the compact summary counts the degraded route' );
// The inline response bounds its item count; the stored report keeps the full compact summary.
$compact = $rows( $stored['compact_summary']['diagnostics'] ?? array(), 'owning_layer', 'data-liberation-agent' );
$assert( 3 === count( $compact ), 'with-findings: the stored compact summary carries the capture rows' );
$assert( 2 === ( $stored['compact_summary']['loss_class_summary'][ Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED ] ?? 0 ), 'with-findings: the stored compact summary counts both missing routes' );
$compact_codes = array_column( $compact, 'reason_code' );
sort( $compact_codes );
$assert( array( 'route_capture_degraded', 'route_capture_failed', 'route_export_failed' ) === $compact_codes, 'with-findings: compact rows keep the capture codes' );
$compact_dropped = $rows( $compact, 'reason_code', 'route_export_failed' )[0] ?? array();
$assert( 'route' === ( $compact_dropped['unit'] ?? '' ) && 'https://example.test/pricing/' === ( $compact_dropped['source_path'] ?? '' ) && 'error' === ( $compact_dropped['severity'] ?? '' ), 'with-findings: the compact row names unit, location and severity' );
$compact_degraded = $rows( $compact, 'reason_code', 'route_capture_degraded' )[0] ?? array();
$assert( 'website/about.html' === ( $compact_degraded['source_path'] ?? '' ) && 1 === ( $compact_degraded['context']['metrics']['failedStages'] ?? null ) && '' !== ( $compact_degraded['message'] ?? '' ), 'with-findings: the compact degraded row keeps location, metrics and message' );
$assert( array() === $rows( $stored['diagnostics'] ?? array(), 'code', Static_Site_Importer_Capture_Findings::UNREADABLE ), 'with-findings: a valid file records no unreadable warning' );

// Older captures without the file import exactly as before.
$without = $import( null, 'without-findings' );
$assert( array() === $rows( $without['stored']['diagnostics'] ?? array(), 'owning_layer', 'data-liberation-agent' ), 'without-findings: no capture rows' );
$assert( array() === $rows( $without['stored']['diagnostics'] ?? array(), 'code', Static_Site_Importer_Capture_Findings::UNREADABLE ), 'without-findings: no unreadable warning' );

// A malformed file never fails the import; it records one warning.
foreach (
	array(
		'not-json'       => '{"schema":',
		'wrong-schema'   => (string) wp_json_encode( array_merge( $findings, array( 'schema' => 'data-liberation-agent/capture-findings/v0' ) ) ),
		'malformed-row'  => (string) wp_json_encode( array_merge( $findings, array( 'findings' => array( array( 'code' => 'route_export_failed' ) ) ) ) ),
		'missing-routes' => (string) wp_json_encode( array_diff_key( $findings, array( 'missing_routes' => true ) ) ),
	) as $case => $bytes
) {
	$bad        = $import( $bytes, "malformed-$case" );
	$unreadable = $rows( $bad['stored']['diagnostics'] ?? array(), 'code', Static_Site_Importer_Capture_Findings::UNREADABLE );
	$assert( 1 === count( $unreadable ) && 'warning' === ( $unreadable[0]['severity'] ?? '' ) && 'website/capture-findings.json' === ( $unreadable[0]['source_path'] ?? '' ), "malformed-$case: one capture_findings_unreadable warning" );
	$assert( array() === $rows( $bad['stored']['diagnostics'] ?? array(), 'owning_layer', 'data-liberation-agent' ), "malformed-$case: no partial capture rows" );
}

// Request-bundle sources reference the file through the payload reader.
$reader    = new class( (string) wp_json_encode( $findings ) ) {
	public function __construct( private string $bytes ) {}
	public function read( array $reference ): string {
		return $this->bytes;
	}
};
$extracted = Static_Site_Importer_Capture_Findings::extract(
	array(
		array(
			'path'    => 'website/index.html',
			'content' => '<h1>Home</h1>',
		),
		array(
			'path'              => 'website/capture-findings.json',
			'payload_reference' => array( 'id' => 'request-bundle-file:capture-findings.json' ),
		),
	),
	$reader
);
$assert( array( 'website/index.html' ) === array_column( $extracted['files'], 'path' ) && 3 === count( $extracted['findings'] ), 'payload-reference: the file is read through the payload reader and removed' );

// Codes outside the registry are degraded losses; off-origin URLs are not losses.
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::UNSUPPORTED_LOSS === Static_Site_Importer_Capture_Findings::loss_class( 'a_future_capture_code' ), 'registry: an unknown capture code is a degraded loss' );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION === Static_Site_Importer_Capture_Findings::loss_class( 'route_off_origin' ), 'registry: an off-origin URL is not a loss' );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-capture-findings: ok\n";
