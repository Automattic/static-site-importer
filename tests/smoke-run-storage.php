<?php
/**
 * Smoke coverage for importer-owned run state staying outside the media library.
 *
 * Run from the repository root:
 * php tests/smoke-run-storage.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_run_storage_site'] = sys_get_temp_dir() . '/ssi-run-storage-smoke-' . getmypid();
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $GLOBALS['ssi_run_storage_site'] . '/wp-content' );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0700, true ); }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$basedir = WP_CONTENT_DIR . '/uploads';
		wp_mkdir_p( $basedir );
		return array( 'basedir' => $basedir );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand(): int { return random_int( 1, PHP_INT_MAX ); }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string { return strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '-', $title ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
}

$GLOBALS['ssi_run_storage_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ssi_run_storage_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['ssi_run_storage_filters'][ $hook ][] = $callback;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback ): void {
		unset( $callback );
		$GLOBALS['ssi_run_storage_filters'][ $hook ] = array();
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-run-storage.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-direct-artifact-import.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-lifecycle-compile-checkpoint.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php';
require_once dirname( __DIR__ ) . '/includes/cli.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

wp_mkdir_p( WP_CONTENT_DIR );
$uploads_basedir = wp_upload_dir()['basedir'];
// Compare against the resolved path too: workspaces report realpath(), and the
// system temporary directory is a symlink on some platforms.
$uploads_prefixes = array_unique( array_filter( array( $uploads_basedir, realpath( $uploads_basedir ) ) ) );
$inside_uploads   = static function ( string $path ) use ( $uploads_prefixes ): bool {
	foreach ( $uploads_prefixes as $prefix ) {
		if ( str_starts_with( $path, rtrim( (string) $prefix, '/\\' ) . '/' ) ) {
			return true;
		}
	}
	return false;
};

// Every importer-owned working directory lives beside the media library, not inside it.
$roots = array(
	'run_storage_root'    => Static_Site_Importer_Run_Storage::root(),
	'direct_artifacts'    => Static_Site_Importer_Direct_Artifact_Import::root(),
	'lifecycle_checkpts'  => Static_Site_Importer_Lifecycle_Compile_Checkpoint::root(),
);
foreach ( $roots as $label => $root ) {
	$assert( ! $inside_uploads( $root ), $label . '-outside-uploads', $root );
	$assert( str_starts_with( $root, WP_CONTENT_DIR . '/static-site-importer' ), $label . '-under-private-root', $root );
}

// The legacy root stays resolvable so scheduled sweeps can still drain it.
$assert(
	$inside_uploads( Static_Site_Importer_Run_Storage::legacy_uploads_root() . '/direct-artifact-imports' ),
	'legacy-root-still-resolvable',
	Static_Site_Importer_Run_Storage::legacy_uploads_root()
);

// Hosts can relocate every working directory with one filter.
$relocated = sys_get_temp_dir() . '/ssi-run-storage-relocated-' . getmypid();
add_filter( 'static_site_importer_run_storage_root', static fn ( string $root ): string => $relocated );
$assert( $relocated . '/direct-artifact-imports' === Static_Site_Importer_Direct_Artifact_Import::root(), 'run-storage-filter-relocates-direct-artifacts' );
$assert( $relocated . '/lifecycle-checkpoints' === Static_Site_Importer_Lifecycle_Compile_Checkpoint::root(), 'run-storage-filter-relocates-lifecycle-checkpoints' );
$GLOBALS['ssi_run_storage_filters'] = array();

// The existing per-subsystem filter still wins for retained direct artifact runs.
$direct_override = sys_get_temp_dir() . '/ssi-direct-artifact-override-' . getmypid();
add_filter( 'static_site_importer_direct_artifact_root', static fn ( string $root ): string => $direct_override );
$assert( $direct_override === Static_Site_Importer_Direct_Artifact_Import::root(), 'direct-artifact-root-filter-preserved' );
$GLOBALS['ssi_run_storage_filters'] = array();

// A completed import persists its response artifacts outside the media library.
$result = Static_Site_Importer_Canonical_Import_Service::bound_success_result(
	array(
		'theme_slug'              => 'run-storage-smoke',
		'status'                  => 'completed',
		'import_report'           => array(
			'schema'        => 'static-site-importer/import-report/v1',
			'import_run_id' => 'ssi-run-storage-smoke',
			'diagnostics'   => array(),
		),
		'materialization_receipt' => array(
			'schema'              => 'static-site-importer/materialization-receipt/v2',
			'status'              => 'completed',
			'receipt_instance_id' => 'receipt-run-storage-smoke',
			'plan_identity'       => array( 'hash' => hash( 'sha256', 'plan' ) ),
		),
	)
);

$artifacts = $result['response_artifacts']['artifacts'] ?? array();
$assert( 'completed' === ( $result['response_artifacts']['status'] ?? '' ), 'response-artifacts-persisted', (string) wp_json_encode( $result['response_artifacts']['errors'] ?? array() ) );
$assert( isset( $artifacts['import_report']['path'] ), 'response-artifacts-include-import-report' );
foreach ( $artifacts as $name => $artifact ) {
	$path = (string) ( $artifact['path'] ?? '' );
	$assert( is_file( $path ), $name . '-artifact-exists', $path );
	$assert( ! $inside_uploads( $path ), $name . '-artifact-outside-uploads', $path );
}

$leaked = glob( $uploads_basedir . '/static-site-importer/*' ) ?: array();
$assert( array() === $leaked, 'import-leaves-no-working-files-in-uploads', implode( ', ', $leaked ) );

// Studio retains only the final MiB of a CLI line, so Figma reports must stay
// in response artifacts while the receipt keeps a useful, parseable summary.
$figma_report = array(
	'schema'         => 'static-site-importer/figma-transform-report/v1',
	'source'         => 'blocks-engine/figma-transformer',
	'status'         => 'completed',
	'summary'        => array( 'page_coverage' => array( 'candidate_count' => 1, 'selected_count' => 1, 'page_count' => 1 ) ),
	'source_reports' => array( 'figma' => array( 'raw_transform_diagnostics' => str_repeat( 'figma-diagnostic-', 80000 ) ) ),
);
$figma_response = Static_Site_Importer_Canonical_Import_Service::success(
	array(
		'theme_slug'              => 'figma-bounded-response-smoke',
		'status'                  => 'completed',
		'import_report'           => array( 'schema' => 'static-site-importer/import-report/v1', 'import_run_id' => 'figma-bounded-response-smoke', 'diagnostics' => array() ),
		'materialization_receipt' => array( 'schema' => 'static-site-importer/materialization-receipt/v2', 'status' => 'completed', 'receipt_instance_id' => 'figma-bounded-response-receipt', 'plan_identity' => array( 'hash' => hash( 'sha256', 'figma-plan' ) ) ),
	),
	array( 'source_metadata' => array( 'figma_transform_report' => $figma_report ) )
);
$cli_receipt = static_site_importer_cli_import_receipt( $figma_response, 1 );
$serialized   = wp_json_encode( $cli_receipt, JSON_UNESCAPED_SLASHES );
$studio_tail  = is_string( $serialized ) ? substr( $serialized, -1048576 ) : '';
$parsed_tail  = json_decode( $studio_tail, true );
$reference    = $parsed_tail['response']['figma_transform_report']['artifact'] ?? array();
$stored       = is_array( $reference ) && is_file( (string) ( $reference['path'] ?? '' ) ) ? json_decode( (string) file_get_contents( $reference['path'] ), true ) : null;
$assert( is_string( $serialized ) && 1048576 > strlen( $serialized ), 'figma-cli-receipt-within-studio-tail-bound', (string) strlen( $serialized ) );
$assert( is_array( $parsed_tail ), 'figma-cli-receipt-studio-tail-parses' );
$assert( ! str_contains( $serialized ?: '', 'raw_transform_diagnostics' ), 'figma-cli-receipt-omits-full-transform-report' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( $parsed_tail['response']['figma_transform_report']['schema'] ?? '' ) && 1 === ( $parsed_tail['response']['figma_transform_report']['summary']['page_coverage']['selected_count'] ?? 0 ), 'figma-cli-receipt-retains-compact-summary' );
$assert( is_file( (string) ( $reference['path'] ?? '' ) ) && 1048576 < (int) ( $reference['bytes'] ?? 0 ) && hash_file( 'sha256', (string) $reference['path'] ) === ( $reference['sha256'] ?? '' ), 'figma-transform-report-reference-is-verified' );
$assert( $figma_report === $stored, 'figma-transform-report-reference-retrieves-complete-report' );

// Clean up the smoke's throwaway site tree.
$remove = static function ( string $directory ) use ( &$remove ): void {
	foreach ( glob( $directory . '/{,.}[!.,..]*', GLOB_BRACE ) ?: array() as $entry ) {
		is_dir( $entry ) && ! is_link( $entry ) ? $remove( $entry ) : unlink( $entry );
	}
	if ( is_dir( $directory ) ) {
		rmdir( $directory );
	}
};
$remove( $GLOBALS['ssi_run_storage_site'] );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	fwrite( STDERR, 'smoke-run-storage: ' . count( $failures ) . ' of ' . $assertions . ' assertions failed' . PHP_EOL );
	exit( 1 );
}

echo 'smoke-run-storage: ' . $assertions . ' assertions passed' . PHP_EOL;
