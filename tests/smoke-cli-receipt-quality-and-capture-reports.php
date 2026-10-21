<?php
/**
 * CLI receipt quality verdict and capture-receipt report resolution.
 *
 * Run from the repository root:
 * php tests/smoke-cli-receipt-quality-and-capture-reports.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $options = 0 ) {
		return json_encode( $value, $options );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['ssi_cli_filters'] = array();
	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['ssi_cli_filters'][ $hook ][] = $callback;
	}
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ssi_cli_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require dirname( __DIR__ ) . '/includes/cli.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-portable-source-manifest.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require dirname( __DIR__ ) . '/includes/rest.php';

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

// --- Part 1: the receipt is the single quality verdict. ---

// The ability envelope for a materialized import whose quality gate failed
// (fail_on_quality with failure reasons): success stays true at that layer.
$quality_failed_response = array(
	'success'     => true,
	'result'      => array(
		'theme_slug'               => 'quality-site',
		'import_report_summary'    => array(
			'status'                     => 'failed',
			'quality_pass'               => false,
			'fail_import'                => true,
			'failure_reasons'            => array( 'unsupported_html_fallback', 'core_html_block' ),
			'fallback_count'             => 3,
			'unsupported_fallback_count' => 3,
			'core_html_block_count'      => 1,
		),
		'import_validation_result' => array(
			'status'          => 'failed',
			'quality_pass'    => false,
			'fail_import'     => true,
			'failure_reasons' => array( 'unsupported_html_fallback', 'core_html_block' ),
			'counts'          => array(
				'fallback_blocks'       => 3,
				'unsupported_fallbacks' => 3,
			),
		),
	),
	'diagnostics' => array(),
);
$failed_receipt = static_site_importer_cli_import_receipt( $quality_failed_response, 1 );
$message        = (string) ( $failed_receipt['response']['error']['message'] ?? '' );
$assert( 'static-site-importer/import-cli-receipt/v1' === ( $failed_receipt['schema'] ?? '' ), 'quality-failed-receipt-keeps-schema' );
$assert( 'failed' === ( $failed_receipt['status'] ?? '' ), 'quality-failed-receipt-is-failed' );
$assert( false === ( $failed_receipt['response']['success'] ?? null ), 'quality-failed-receipt-response-is-not-successful' );
$assert( array( 'code', 'message' ) === array_keys( $failed_receipt['response']['error'] ?? array() ), 'quality-failed-receipt-error-is-code-and-message' );
$assert( 'static_site_importer_quality_failed' === ( $failed_receipt['response']['error']['code'] ?? '' ), 'quality-failed-receipt-error-code' );
$assert( str_contains( $message, '3 unsupported_html_fallback failures' ), 'quality-failed-message-names-reason-with-count: ' . $message );
$assert( str_contains( $message, '1 core_html_block failure' ) && ! str_contains( $message, '1 core_html_block failures' ), 'quality-failed-message-names-every-reason-with-singular-count: ' . $message );
$assert( 'quality-site' === ( $failed_receipt['response']['result']['theme_slug'] ?? '' ), 'quality-failed-receipt-keeps-import-evidence' );

// No failure reasons: the message falls back to the fallback-block count.
$fallback_only = static_site_importer_cli_import_receipt(
	array(
		'success' => true,
		'result'  => array(
			'import_validation_result' => array(
				'fail_import' => true,
				'counts'      => array( 'fallback_blocks' => 3 ),
			),
		),
	),
	2
);
$assert( 'failed' === ( $fallback_only['status'] ?? '' ) && 'static_site_importer_quality_failed' === ( $fallback_only['response']['error']['code'] ?? '' ), 'quality-failed-without-reasons-is-failed' );
$assert( str_contains( (string) ( $fallback_only['response']['error']['message'] ?? '' ), '3 fallback_blocks failures' ), 'quality-failed-without-reasons-names-fallback-block-count' );

// A top-level summary (direct artifact responses) is read the same way.
$top_level = static_site_importer_cli_import_receipt(
	array(
		'success'               => true,
		'import_report_summary' => array(
			'fail_import'     => true,
			'failure_reasons' => array( 'dropped_artifact_files' ),
			'omitted_file_count' => 2,
		),
	),
	1
);
$assert( 'failed' === ( $top_level['status'] ?? '' ) && str_contains( (string) ( $top_level['response']['error']['message'] ?? '' ), '2 dropped_artifact_files failures' ), 'quality-failed-top-level-summary-is-failed' );

// A passing import completes and never carries fail_import: true.
$completed = static_site_importer_cli_import_receipt(
	array(
		'success' => true,
		'result'  => array(
			'import_report_summary'    => array( 'status' => 'completed', 'quality_pass' => false, 'fail_import' => false, 'failure_reasons' => array( 'unsupported_html_fallback' ) ),
			'import_validation_result' => array( 'status' => 'reported', 'fail_import' => false ),
			'quality'                  => array( 'pass' => false, 'fail_import' => false ),
		),
	),
	1
);
$assert( 'completed' === ( $completed['status'] ?? '' ) && ! isset( $completed['response']['error'] ), 'reported-quality-without-fail-import-completes' );
$assert( ! str_contains( (string) wp_json_encode( $completed ), '"fail_import":true' ), 'completed-receipt-never-carries-fail-import' );

// An import that already failed keeps its own error.
$already_failed = static_site_importer_cli_import_receipt(
	array(
		'success'               => false,
		'error'                 => array( 'code' => 'static_site_importer_quality_gate_failed', 'message' => 'Policy rejected.' ),
		'import_report_summary' => array( 'fail_import' => true ),
	),
	1
);
$assert( 'failed' === ( $already_failed['status'] ?? '' ) && 'static_site_importer_quality_gate_failed' === ( $already_failed['response']['error']['code'] ?? '' ), 'failed-import-keeps-its-own-error' );

// --- Part 2: capture receipts name their reports. ---

$remove_tree = static function ( string $directory ): void {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $directory );
};
$bundle_dir = sys_get_temp_dir() . '/ssi-capture-reports-' . bin2hex( random_bytes( 6 ) );
$stage      = static function ( string $name, array $receipt, ?array $metadata = null ) use ( $bundle_dir ): string {
	$root = $bundle_dir . '/' . $name;
	mkdir( $root . '/website', 0777, true );
	mkdir( $root . '/reports', 0777, true );
	file_put_contents( $root . '/capture-receipt.json', wp_json_encode( array( 'schema' => 'blocks-engine-capture/capture-receipt/v1', 'websiteRoot' => 'website' ) + $receipt ) );
	file_put_contents( $root . '/website/index.html', '<h1>Captured</h1>' );
	file_put_contents( $root . '/website/other.json', '{"page":true}' );
	file_put_contents( $root . '/scroll-states.json', '{"pages":[]}' );
	file_put_contents( $root . '/reports/asset-evidence.json', '{"assets":[]}' );
	$source = array(
		'type' => 'files',
		'ref'  => 'request-bundle:' . $name,
	);
	if ( null !== $metadata ) {
		$source['metadata'] = $metadata;
	}
	$request = $bundle_dir . '/' . $name . '-request.json';
	file_put_contents( $request, wp_json_encode( array( 'operation' => 'plan', 'source' => $source ) ) );
	return $request;
};
$resolve = static function ( string $name, string $request ) {
	$input = static_site_importer_cli_import_input( array(), array( 'request' => $request ) );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	return apply_filters( 'static_site_importer_resolve_source_reference', null, 'request-bundle:' . $name, 'files' );
};
$paths_of = static function ( $resolved ): array {
	$paths = array_column( is_array( $resolved ) ? $resolved['source']['files'] : array(), 'path' );
	sort( $paths );
	return $paths;
};
$runtime_paths = static function ( $resolved ): array {
	$runtime = is_array( $resolved ) ? static_site_importer_source_runtime( $resolved['source'], $resolved['payload_reader'] ) : null;
	$paths   = is_array( $runtime ) ? array_column( $runtime['artifact']['files'] ?? array(), 'path' ) : array();
	sort( $paths );
	return $paths;
};

// Receipt `reports` are forwarded from the capture root without the caller naming them.
$forwarded = $resolve( 'forwarded', $stage( 'forwarded', array( 'reports' => array( 'scroll-states.json', './reports/asset-evidence.json', '../escape.json', '/abs.json', 'missing.json' ) ) ) );
$assert( is_array( $forwarded ), 'capture-receipt-reports-resolve' );
$assert( array( 'scroll-states.json', 'reports/asset-evidence.json' ) === ( $forwarded['source']['metadata']['reports'] ?? null ), 'capture-receipt-reports-become-safe-declared-reports' );
$assert( array( 'index.html', 'other.json', 'reports/asset-evidence.json', 'scroll-states.json' ) === $paths_of( $forwarded ), 'capture-receipt-reports-are-forwarded-beside-the-website-tree' );
$assert( array( 'reports/asset-evidence.json', 'scroll-states.json', 'website/index.html', 'website/other.json' ) === $runtime_paths( $forwarded ), 'capture-receipt-reports-stay-at-the-artifact-root' );
$reader = $forwarded['payload_reader'] ?? null;
$file   = array_values( array_filter( $forwarded['source']['files'] ?? array(), static fn( array $entry ): bool => 'scroll-states.json' === $entry['path'] ) )[0] ?? array();
$assert( is_object( $reader ) && '{"pages":[]}' === $reader->read( $file['payload_reference'] ?? array() ), 'capture-receipt-report-payload-reads-the-capture-root-file' );

// A caller-declared `metadata.reports` wins over the receipt.
$explicit = $resolve( 'explicit', $stage( 'explicit', array( 'reports' => array( 'scroll-states.json' ) ), array( 'reports' => array( 'other.json' ) ) ) );
$assert( array( 'other.json' ) === ( $explicit['source']['metadata']['reports'] ?? null ), 'explicit-metadata-reports-win' );
$assert( array( 'index.html', 'other.json' ) === $paths_of( $explicit ), 'explicit-metadata-reports-do-not-forward-receipt-reports' );
$assert( array( 'other.json', 'website/index.html' ) === $runtime_paths( $explicit ), 'explicit-metadata-reports-keep-existing-artifact-layout' );

// A receipt without `reports` behaves as before.
$plain = $resolve( 'plain', $stage( 'plain', array() ) );
$assert( is_array( $plain ) && ! isset( $plain['source']['metadata']['reports'] ), 'receipt-without-reports-declares-none' );
$assert( array( 'index.html', 'other.json' ) === $paths_of( $plain ), 'receipt-without-reports-imports-only-the-website-tree' );
$assert( array( 'website/index.html', 'website/other.json' ) === $runtime_paths( $plain ), 'receipt-without-reports-keeps-existing-artifact-layout' );

// A declared report that is a symlink is refused.
$linked_request = $stage( 'linked', array( 'reports' => array( 'linked.json' ) ) );
symlink( $bundle_dir . '/linked/scroll-states.json', $bundle_dir . '/linked/linked.json' );
$linked = static_site_importer_cli_import_input( array(), array( 'request' => $linked_request ) );
$assert( is_wp_error( $linked ) && 'static_site_importer_cli_request_bundle_invalid' === $linked->get_error_code(), 'capture-receipt-symlinked-report-is-refused' );

$remove_tree( $bundle_dir );

if ( $failures ) {
	fwrite( STDERR, "CLI receipt quality / capture reports smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "CLI receipt quality / capture reports smoke passed ({$assertions} assertions).\n";
