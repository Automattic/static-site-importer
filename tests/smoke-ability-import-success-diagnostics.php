<?php
/**
 * Smoke coverage for the import-website-artifact success envelope carrying the
 * static-site-importer/import-diagnostics/v1 contract and firing the completion hook.
 *
 * Run from the repository root:
 * php tests/smoke-ability-import-success-diagnostics.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook_name ): bool {
		unset( $hook_name );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		unset( $hook_name );
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, string $callback ): void {
		unset( $hook_name, $callback );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0700, true ); }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$root = sys_get_temp_dir() . '/ssi-bounded-response-smoke-' . getmypid();
		wp_mkdir_p( $root );
		return array( 'basedir' => $root );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand(): int { return random_int( 1, PHP_INT_MAX ); }
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

$GLOBALS['ssi_smoke_fired_hooks'] = array();
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['ssi_smoke_fired_hooks'][] = array(
			'hook' => $hook_name,
			'args' => $args,
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// A representative success result shaped like
// Static_Site_Importer_Theme_Generator::import_website_artifact() returns.
$result = array(
	'theme_slug'               => 'acme-co',
	'theme_name'               => 'Acme Co',
	'quality'                  => array(
		'fallback_count'                        => 2,
		'core_html_block_count'                 => 1,
		'runtime_dependency_parity_issue_count' => 1,
	),
	'import_report_summary'    => array(
		'status' => 'completed',
	),
	'import_validation_result' => array(
		'schema'      => 'blocks-engine/import-validation-result/v1',
		'status'      => 'reported',
		'diagnostics' => array(
			array(
				'type'        => 'core_html_block',
				'reason_code' => 'unconverted_markup',
				'severity'    => 'warning',
				'source_path' => 'website/index.html',
				'selector'    => 'div.hero',
				'message'     => 'Fell back to a core/html block.',
			),
		),
	),
);

$input    = array( 'slug' => 'acme-co' );
$envelope = static_site_importer_ability_import_success( $result, $input );

$assert( true === ( $envelope['success'] ?? false ), 'envelope-success-true' );
$assert( $result === ( $envelope['result'] ?? null ), 'envelope-preserves-raw-result' );
$assert( is_array( $envelope['fixture_diagnostics'] ?? null ), 'envelope-has-fixture-diagnostics' );

$contract = $envelope['fixture_diagnostics'];
$assert(
	Static_Site_Importer_Diagnostic_Contract::IMPORT_DIAGNOSTICS_SCHEMA === ( $contract['schema'] ?? '' ),
	'contract-schema-v1',
	(string) ( $contract['schema'] ?? '' )
);
$assert( true === ( $contract['success'] ?? false ), 'contract-success-true' );
$assert( 'acme-co' === ( $contract['fixture']['slug'] ?? '' ), 'contract-carries-slug' );
$assert( 2 === ( $contract['quality_counts']['fallback_count'] ?? -1 ), 'contract-quality-counts-from-result' );
$assert( 1 === ( $contract['quality_counts']['runtime_dependency_parity_issue_count'] ?? -1 ), 'contract-runtime-quality-count' );
$assert( ! empty( $contract['diagnostics'] ), 'contract-has-diagnostic-rows' );
$assert(
	is_array( $envelope['diagnostics'] ?? null ) && count( $envelope['diagnostics'] ) === count( $contract['diagnostics'] ),
	'envelope-diagnostics-match-contract'
);

$fired = array_values(
	array_filter(
		$GLOBALS['ssi_smoke_fired_hooks'],
		static fn ( array $entry ): bool => 'static_site_importer_import_completed' === $entry['hook']
	)
);
$assert( 1 === count( $fired ), 'completion-hook-fired-once', (string) count( $fired ) );
$assert( ( $fired[0]['args'][0] ?? null ) === $contract, 'hook-arg-contract' );
$assert( ( $fired[0]['args'][1] ?? null ) === $result, 'hook-arg-result' );
$assert( ( $fired[0]['args'][2] ?? null ) === $input, 'hook-arg-input' );

$provider_resolutions = array();
for ( $index = 1; $index <= 8; ++$index ) {
	$fallback_identity = hash( 'sha256', 'ability-fallback-' . $index );
	$fallback_hash     = hash( 'sha256', 'ability-source-' . $index );
	$provider_resolutions[] = array(
		'fallback_reconciliation_identity' => $fallback_identity,
		'fallback_hash'                    => $fallback_hash,
		'state'                            => 'resolved_by_provider',
		'receipt'                          => array(
			'schema'                           => 'static-site-importer/quality-resolution-receipt/v1',
			'status'                           => 'completed',
			'fallback_reconciliation_identity' => $fallback_identity,
			'fallback_hash'                    => $fallback_hash,
			'binding_reconciliation_identity'  => hash( 'sha256', 'ability-binding-' . $index ),
			'materialized_block_hash'          => hash( 'sha256', 'ability-block-' . $index ),
			'materialized_content_hash'        => hash( 'sha256', 'ability-content-' . $index ),
		),
	);
}
$large_diagnostics = array();
for ( $index = 0; $index < 500; ++$index ) {
	$large_diagnostics[] = array(
		'type'        => 'bounded-response-diagnostic',
		'source_path' => 'website/page-' . $index . '.html',
		'message'     => str_repeat( 'd', 10000 ),
	);
}
$reconciled_result = array(
	'theme_slug'      => 'compiler-quality-site',
	'theme_name'      => 'Compiler Quality Site',
	'pages'           => range( 1, 5000 ),
	'finding_packets' => array_fill( 0, 500, str_repeat( 'f', 10000 ) ),
	'source_of_truth' => array( 'large_manifest_projection' => str_repeat( 's', 2 * 1024 * 1024 ) ),
	'quality'         => array( 'block_count' => 0, 'fallback_count' => 0, 'diagnostic_count' => 0 ),
	'import_report' => array(
		'schema'                  => 'static-site-importer/import-report/v1',
		'import_run_id'           => 'bounded-response-smoke',
		'large_report_projection' => str_repeat( 'r', 2 * 1024 * 1024 ),
		'quality'                 => array( 'block_count' => 0, 'fallback_count' => 0, 'diagnostic_count' => 0 ),
		'blocks_engine'           => array(
			'wordpress_site_plan' => array(
				'schema'  => 'blocks-engine/wordpress-site-plan/v2',
				'quality' => array( 'metrics' => array( 'block_count' => 331, 'fallback_count' => 458, 'diagnostic_count' => 537 ) ),
			),
		),
		'quality_resolutions' => array(
			'schema'                    => 'static-site-importer/quality-resolutions/v1',
			'source_fallback_count'     => 458,
			'resolved_by_provider'      => 8,
			'unresolved_fallback_count' => 450,
			'resolutions'               => $provider_resolutions,
		),
		'import_validation_result' => array(
			'schema'      => 'blocks-engine/import-validation-result/v1',
			'status'      => 'reported',
			'diagnostics' => $large_diagnostics,
		),
	),
	'materialization_receipt' => array(
		'schema'              => 'static-site-importer/materialization-receipt/v2',
		'status'              => 'completed',
		'receipt_instance_id' => 'receipt-bounded-response-smoke',
		'plan_identity'       => array( 'schema' => 'blocks-engine/wordpress-site-plan-identity/v1', 'hash' => hash( 'sha256', 'plan' ) ),
		'plan'                => array( 'large_resolved_plan' => str_repeat( 'p', 2 * 1024 * 1024 ) ),
	),
);
$reconciled_envelope = static_site_importer_ability_import_success( $reconciled_result, array( 'slug' => 'compiler-quality-site' ) );
$reconciled_contract = $reconciled_envelope['fixture_diagnostics'] ?? array();
$assert( 331 === ( $reconciled_contract['quality_counts']['block_count'] ?? null ), 'success-envelope-retains-compiler-block-count' );
$assert( 450 === ( $reconciled_contract['quality_counts']['fallback_count'] ?? null ), 'success-envelope-emits-unresolved-fallback-count' );
$assert( 8 === ( $reconciled_contract['quality_counts']['materialized']['fallback_count'] ?? null ), 'success-envelope-uses-provider-reconciliation' );
$assert( 'blocks_engine.wordpress_site_plan.quality' === ( $reconciled_contract['quality_counts']['provenance']['source_detected']['path'] ?? '' ), 'success-envelope-identifies-compiler-provenance' );
$assert( 'static-site-importer/materialization-receipt/v2' === ( $reconciled_contract['quality_counts']['provenance']['materialized']['receipt'] ?? '' ), 'success-envelope-identifies-materialization-receipt' );
$assert( false === ( $reconciled_contract['quality_counts']['consistent'] ?? true ), 'success-envelope-flags-contradictory-quality-layers' );
$consistency_diagnostics = array_values( array_filter( $reconciled_contract['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'quality_count_consistency_failure' === ( $diagnostic['type'] ?? '' ) ) );
$assert( 1 === count( $consistency_diagnostics ), 'success-envelope-emits-consistency-diagnostic' );
$bounded_result = $reconciled_envelope['result'] ?? array();
$artifacts      = $bounded_result['response_artifacts']['artifacts'] ?? array();
$assert( ! isset( $bounded_result['import_report'], $bounded_result['materialization_receipt'] ), 'success-envelope-omits-unbounded-payloads' );
$assert( ! isset( $bounded_result['finding_packets'], $bounded_result['source_of_truth'] ), 'success-envelope-externalizes-site-sized-details' );
$assert( 0 < count( $bounded_result['pages'] ?? array() ) && 100 > count( $bounded_result['pages'] ?? array() ), 'success-envelope-bounds-inline-page-identities' );
$assert( 5000 === ( $bounded_result['page_count'] ?? 0 ) && true === ( $bounded_result['pages_truncated'] ?? false ), 'success-envelope-reports-page-identity-truncation' );
$assert( 'completed' === ( $bounded_result['response_artifacts']['status'] ?? '' ), 'response-artifacts-persisted' );
$assert( 'completed' === ( $bounded_result['materialization_receipt_summary']['status'] ?? '' ), 'receipt-identity-remains-inline' );
$assert( 200000 > strlen( (string) wp_json_encode( $reconciled_envelope ) ), 'success-envelope-remains-bounded' );
foreach ( array( 'import_report', 'materialization_receipt', 'result_details' ) as $artifact_name ) {
	$artifact = $artifacts[ $artifact_name ] ?? array();
	$assert( is_file( $artifact['path'] ?? '' ), $artifact_name . '-artifact-exists' );
	$assert( hash_file( 'sha256', $artifact['path'] ) === ( $artifact['sha256'] ?? '' ), $artifact_name . '-artifact-digest' );
}
$persisted_report  = json_decode( (string) file_get_contents( $artifacts['import_report']['path'] ?? '' ), true );
$persisted_receipt = json_decode( (string) file_get_contents( $artifacts['materialization_receipt']['path'] ?? '' ), true );
$persisted_details = json_decode( (string) file_get_contents( $artifacts['result_details']['path'] ?? '' ), true );
$assert( ! isset( $persisted_receipt['plan'] ), 'persisted-receipt-references-plan-identity' );
$assert( ! isset( $persisted_report['materialization_receipt']['plan'] ), 'persisted-report-does-not-duplicate-receipt-plan' );
$assert( 500 === count( $persisted_details['finding_packets'] ?? array() ), 'persisted-details-retain-full-finding-packets' );
$assert( 5000 === count( $persisted_details['pages'] ?? array() ), 'persisted-details-retain-all-page-identities' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: ability import success diagnostics smoke passed (' . $assertions . " assertions)\n";
