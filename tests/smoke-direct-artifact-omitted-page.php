<?php
/**
 * Smoke coverage for an omitted non-entry page in a direct artifact import.
 *
 * Blocks Engine omits a non-entrypoint page whose conversion hits a
 * representability limit and records a `page_compile_omitted` warning in the
 * WordPress site plan. The import must keep the remaining pages and carry the
 * omission as a finding in the stored report and compact summary, on both the
 * in-process and the per-page worker compile paths. An omitted entrypoint
 * stays fail-closed.
 *
 * Run from the repository root:
 * php tests/smoke-direct-artifact-omitted-page.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );

$test_root                     = sys_get_temp_dir() . '/ssi-direct-omitted-page-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_omitted_filters'] = array();
$GLOBALS['ssi_omitted_imports'] = array();

class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_key( $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ); }
function sanitize_text_field( $text ): string { return trim( (string) $text ); }
function wp_upload_dir(): array { return array( 'basedir' => $GLOBALS['test_root'] ); }
function get_current_blog_id(): int { return 17; }
function get_current_user_id(): int { return 827; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000827'; }
function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ssi_omitted_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function add_filter( string $hook, callable $callback ): bool {
	$GLOBALS['ssi_omitted_filters'][ $hook ][] = $callback;
	return true;
}
function do_action( string $hook, ...$args ): void {}
function __( string $message ): string { return $message; }

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
				'slug' => 'omitted-page-fixture',
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
		$stored     = $GLOBALS['test_root'] . '/import-report-' . count( $GLOBALS['ssi_omitted_imports'] ) . '.json';
		file_put_contents( $stored, wp_json_encode( $report->to_array() ) );
		$GLOBALS['ssi_omitted_imports'][] = array(
			'plan'        => $plan,
			'report_path' => $stored,
		);
		return array(
			'theme_slug'               => 'omitted-page-fixture',
			'theme_name'               => 'Omitted Page Fixture',
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
$input    = static fn (): array => array(
	'operation' => 'apply',
	'slug'      => 'omitted-page-fixture',
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array( 'path' => 'website/index.html', 'content' => $document( 'Home', '<p>Welcome.</p><a href="about.html">About</a> <a href="pricing.html">Pricing</a>' ) ),
			array( 'path' => 'website/about.html', 'content' => $document( 'About', '<p>About us.</p><a href="index.html">Home</a>' ) ),
			array( 'path' => 'website/pricing.html', 'content' => $document( 'Pricing', '<p>Plans.</p><a href="index.html">Home</a>' ) ),
			array( 'path' => 'website/styles.css', 'content' => 'body{font-family:serif}' ),
		),
	),
);
$drive = static function ( array $request ): array {
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
	throw new RuntimeException( 'The omitted-page fixture exceeded its continuation bound.' );
};
// Blocks Engine's documented consumer seam: a throw before one page converts
// is handled exactly like a representability throw from its converter.
$throw_on = static function ( string $source_path ): void {
	add_filter(
		'static_site_importer_direct_artifact_compiler',
		static fn (): object => new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler(
			beforeDocumentTransform: static function ( string $path ) use ( $source_path ): void {
				if ( $source_path === $path ) {
					throw new InvalidArgumentException( 'Form layout graph exceeds its node limit.' );
				}
			}
		)
	);
};
$use_policy = static function ( array $overrides ): void {
	$GLOBALS['ssi_omitted_filters']['static_site_importer_direct_artifact_run_policy'] = array(
		static fn ( array $policy ): array => array_merge( $policy, $overrides ),
	);
};
$verify_omission = static function ( array $result, string $label ) use ( $assert ): void {
	$import  = end( $GLOBALS['ssi_omitted_imports'] );
	$plan    = is_array( $import ) ? $import['plan'] : array();
	$stored  = is_array( $import ) ? json_decode( (string) file_get_contents( $import['report_path'] ), true ) : array();
	$summary = $result['result']['import_report_summary'] ?? array();
	$assert( ! empty( $result['success'] ) && empty( $result['continuation'] ), "$label: the import succeeds" );
	$persisted = array_column( $plan['pages'] ?? array(), 'source_path' );
	sort( $persisted );
	$assert( array( 'website/about.html', 'website/index.html' ) === $persisted, "$label: only the two compiled pages are persisted" );
	$assert( 2 === count( $result['result']['pages'] ?? array() ) && ! isset( $result['result']['pages']['website/pricing.html'] ), "$label: the result names two persisted pages" );

	$finding = array_values( array_filter( $stored['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && 'page_compile_omitted' === ( $row['code'] ?? '' ) ) );
	$assert( 1 === count( $finding ), "$label: the stored report carries one omission finding" );
	$finding = $finding[0] ?? array();
	$assert( 'website/pricing.html' === ( $finding['source_path'] ?? '' ) && 'page_compile_representability_limit' === ( $finding['reason_code'] ?? '' ) && 'Form layout graph exceeds its node limit.' === ( $finding['message'] ?? '' ), "$label: the stored finding names the page, reason and message" );
	$assert( 'page' === ( $finding['unit'] ?? '' ) && 'blocks-engine' === ( $finding['owning_layer'] ?? '' ) && 'warning' === ( $finding['severity'] ?? '' ), "$label: the stored finding is a blocks-engine page-unit warning" );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED === ( $finding['loss_class'] ?? '' ), "$label: the stored finding carries the page_compile_omitted loss class" );
	$assert( 2 === ( $stored['source_documents']['total_count'] ?? 0 ), "$label: the stored report counts two source documents" );

	$assert( 'completed' === ( $summary['status'] ?? '' ) && true === ( $summary['quality_pass'] ?? false ) && false === ( $summary['fail_import'] ?? true ), "$label: the compact summary reports a passing completed import" );
	$assert( 1 === ( $summary['loss_class_summary'][ Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED ] ?? 0 ), "$label: the compact summary counts the omission by loss class" );
	$compact = array_values( array_filter( $summary['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && Static_Site_Importer_Diagnostic_Loss_Classes::PAGE_COMPILE_OMITTED === ( $row['loss_class'] ?? '' ) ) );
	$compact = $compact[0] ?? array();
	$assert( 'website/pricing.html' === ( $compact['source_path'] ?? '' ) && 'page_compile_representability_limit' === ( $compact['reason_code'] ?? '' ) && 'page' === ( $compact['unit'] ?? '' ) && 'blocks-engine' === ( $compact['owning_layer'] ?? '' ) && '' !== ( $compact['message'] ?? '' ), "$label: the compact summary carries the omission finding" );
	$assert( ! empty( $summary['warning_summaries'] ) && empty( $summary['error_summaries'] ), "$label: the omission is a warning, not an error" );
};

// In-process compilation: one page per batch, so the omitting receipt is its own checkpoint.
$throw_on( 'website/pricing.html' );
$use_policy( array( 'compile_in_process_pages' => 1 ) );
$verify_omission( $drive( $input() ), 'in-process' );

// Per-page compile workers publish the omitting receipt; the coordinator composes it.
$worker_calls = 0;
$use_policy(
	array(
		'compile_fanout_pages' => 4,
		'compile_workers'      => 3,
		'compile_shard_pages'  => 1,
		'compile_fanout'       => static function ( string $import_id, array $shards ) use ( &$worker_calls ) {
			foreach ( $shards as $page_ids ) {
				++$worker_calls;
				$published = Static_Site_Importer_Direct_Artifact_Import::compile_worker( $import_id, $page_ids );
				if ( is_wp_error( $published ) ) {
					return $published;
				}
			}
			return true;
		},
	)
);
$verify_omission( $drive( $input() ), 'worker' );
$assert( 3 === $worker_calls, 'worker: every page, including the omitted one, is compiled by its own worker' );

// An entrypoint that cannot be represented fails the import before any persistence.
$imports_before = count( $GLOBALS['ssi_omitted_imports'] );
$throw_on( 'website/index.html' );
$use_policy( array( 'compile_in_process_pages' => 1 ) );
$entry = $drive( $input() );
$assert( empty( $entry['success'] ) && $imports_before === count( $GLOBALS['ssi_omitted_imports'] ), 'entrypoint: an unrepresentable entrypoint stays fail-closed' );

// Should an omission row ever name the entrypoint, SSI refuses it itself.
$GLOBALS['ssi_omitted_filters']['static_site_importer_direct_artifact_compiler'] = array(
	static fn ( object $compiler ): object => new class( $compiler ) {
		public function __construct( private object $compiler ) {}

		public function compose( ...$arguments ) {
			return new class( $this->compiler->compose( ...$arguments ) ) {
				public function __construct( private object $result ) {}

				public function __get( string $name ) {
					return $this->result->{$name};
				}

				public function toCompactWordPressSitePlanView(): array {
					$view = $this->result->toCompactWordPressSitePlanView();
					$view['wordpress_site_plan']['diagnostics'][] = array(
						'code'        => 'page_compile_omitted',
						'severity'    => 'warning',
						'unit'        => 'page',
						'source_path' => 'website/index.html',
						'reason_code' => 'page_compile_representability_limit',
						'message'     => 'Injected entrypoint omission.',
					);
					return $view;
				}
			};
		}

		public function __call( string $method, array $arguments ) {
			return $this->compiler->{$method}( ...$arguments );
		}
	},
);
$forged = $drive( $input() );
$assert( 'static_site_importer_omitted_entrypoint' === ( $forged['error']['code'] ?? '' ) && $imports_before === count( $GLOBALS['ssi_omitted_imports'] ), 'entrypoint: an omission row naming the entrypoint is refused before persistence' );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-direct-artifact-omitted-page: ok\n";
