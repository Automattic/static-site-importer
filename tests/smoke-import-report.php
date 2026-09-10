<?php
/**
 * Typed import-report envelope coverage.
 *
 * Run from the repository root:
 * php tests/smoke-import-report.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '' ) {}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-asset-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document-metadata-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-block-document-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-receipt-projection.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$assert( $report instanceof Static_Site_Importer_Import_Report, 'factory-returns-typed-report' );
$assert( Static_Site_Importer_Import_Report::SCHEMA === $report['schema'], 'schema-constant' );
$assert( 'index.html' === $report['entry_file'], 'arrayaccess-read' );

$report['theme_slug'] = 'typed-report';
$assert( 'typed-report' === $report->get( 'theme_slug' ), 'top-level-set' );

$report->append_diagnostic( array( 'type' => 'document_metadata_routed' ) );
$assert( 1 === count( $report->diagnostics() ), 'append-diagnostic' );

$nested_write_notice = false;
set_error_handler(
	static function ( int $severity, string $message ) use ( &$nested_write_notice ): bool {
		if ( str_contains( $message, 'Indirect modification of overloaded element' ) ) {
			$nested_write_notice = true;
			return true;
		}
		return false;
	}
);
$report['quality']['fallback_count'] = 99;
restore_error_handler();
$assert( $nested_write_notice, 'nested-arrayaccess-write-warns' );
$assert( 99 !== ( $report->quality()['fallback_count'] ?? null ), 'nested-arrayaccess-write-does-not-persist' );

$report->merge_quality( array( 'fallback_count' => 2 ) );
$assert( 2 === $report->quality()['fallback_count'], 'merge-quality' );

$roundtrip = $report->to_array();
$assert( is_array( $roundtrip ), 'to-array-is-array' );
$assert( 'typed-report' === $roundtrip['theme_slug'], 'to-array-preserves-writes' );
$assert( $roundtrip === Static_Site_Importer_Import_Report::from_array( $roundtrip )->to_array(), 'from-array-roundtrip' );

$unknown_threw = false;
try {
	$report->set( 'not_a_real_top_level_key', 1 );
} catch ( InvalidArgumentException $e ) {
	$unknown_threw = str_contains( $e->getMessage(), 'not_a_real_top_level_key' );
}
$assert( $unknown_threw, 'unknown-key-throws' );

// A stale envelope round-trips its unknown keys but never launders them into the schema.
$stale = Static_Site_Importer_Import_Report::from_array(
	array(
		'schema'        => Static_Site_Importer_Import_Report::SCHEMA,
		'retired_field' => 'carried',
	)
);
$assert( 'carried' === $stale->get( 'retired_field' ), 'stale-key-readable' );
$assert( 'carried' === ( $stale->to_array()['retired_field'] ?? null ), 'stale-key-round-trips' );
$stale_threw = false;
try {
	$stale->set( 'retired_field', 'rewritten' );
} catch ( InvalidArgumentException $e ) {
	$stale_threw = true;
}
$assert( $stale_threw, 'stale-key-is-not-writable' );

$isolated = Static_Site_Importer_Import_Report::from_array( array() );
$policy   = Static_Site_Importer_Asset_Reporter::initialize_report( $isolated, array() );
$assert( 'copy_to_theme' === $policy, 'asset-reporter-default-policy' );
$assert( 'theme' === $isolated->section( 'assets' )['policy'], 'asset-reporter-sets-policy' );
$assert( 'copy_to_theme' === $isolated->section( 'assets' )['local_policy'], 'asset-reporter-sets-local-policy' );
$assert( false === $isolated->section( 'asset_map' )['supplied'], 'asset-reporter-empty-map' );

$isolated = Static_Site_Importer_Import_Report::from_array( array() );
Static_Site_Importer_Document_Metadata_Reporter::record( $isolated, array() );
$assert( array() === $isolated->diagnostics(), 'metadata-reporter-ignores-missing-contract' );
Static_Site_Importer_Document_Metadata_Reporter::record(
	$isolated,
	array(
		'document_metadata' => array(
			'schema'      => 'blocks-engine/php-transformer/document-metadata/v1',
			'source_path' => 'website/index.html',
			'title'       => 'Home',
			'meta'        => array(),
			'links'       => array(),
			'styles'      => array(),
			'scripts'     => array(),
		),
	)
);
$assert( 'document_metadata_routed' === ( $isolated->diagnostics()[0]['type'] ?? '' ), 'metadata-reporter-appends-diagnostic' );
$assert( 'website/index.html' === ( $isolated->section( 'generated_theme' )['document_metadata']['source_path'] ?? '' ), 'metadata-reporter-stores-document-metadata' );

$compiler_warning = array(
	'code'     => 'normalizer_limit_warning',
	'severity' => 'warning',
	'message'  => 'A normalizer limit was reached after successful compilation.',
	'context'  => array(
		'rejected_count'   => 1,
		'rejected_by_code' => array( 'artifact_file_too_large' => 1 ),
		'samples'          => array( array( 'code' => 'artifact_file_too_large', 'bytes' => 16230577 ) ),
		'samples_omitted'  => 0,
	),
);
$compiler_plan = array(
	'schema'         => 'blocks-engine/wordpress-site-plan/v2',
	'diagnostics'    => array(),
	'quality'        => array(),
	'source'         => array(
		'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
		'source_hash' => 'compiler-warning-smoke',
		'entry_path'  => 'website/index.html',
		'provenance'  => array(),
	),
	'template_parts' => array(),
	'pages'          => array(),
	'writes'         => array(),
	'assets'         => array(),
);
$receipt = array(
	'plan'             => $compiler_plan,
	'theme'            => array( 'slug' => 'compiler-warning', 'dir' => sys_get_temp_dir() . '/compiler-warning' ),
	'completed'        => array( 'files' => array(), 'font_materialization' => array( 'files' => array() ) ),
	'existing_matches' => array( 'pages' => array() ),
	'extensions'       => array(),
);
$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array( 'compiler_diagnostics' => array( $compiler_warning ) ), array(), array(), array(), 'compiler-warning-run', array(), array() );
$persisted  = $projection['report']->to_array();
$assert( array( $compiler_warning ) === ( $persisted['diagnostics'] ?? null ), 'persisted-import-report-retains-compiler-warning' );
$summary = Static_Site_Importer_Report_Diagnostics::import_report_summary( $persisted, array() );
$assert( $compiler_warning['context'] === ( $summary['diagnostics'][0]['context'] ?? null ), 'compact-summary-retains-bounded-compiler-warning-context' );

$nested_context = array();
for ( $index = 0; $index < 20; ++$index ) {
	$nested_context[ 'outer-' . $index . '-' . str_repeat( 'key-', 100 ) ] = array(
		'inner-' . $index . '-' . str_repeat( 'key-', 100 ) => array(
			'empty-' . $index . '-' . str_repeat( 'key-', 100 ) => array(),
			'value-' . $index . '-' . str_repeat( 'key-', 100 ) => str_repeat( 'payload-', 100 ),
		),
	);
}
$single_compiler_diagnostic = Static_Site_Importer_Compiler_Diagnostic_Normalizer::normalize(
	array(
		array(
			'code'     => 'nested-context-boundary',
			'severity' => 'notice',
			'message'  => 'Nested compiler context must remain serializable and bounded.',
			'context'  => $nested_context,
		)
	)
);
$single_context      = $single_compiler_diagnostic[0]['context'] ?? null;
$single_context_json = is_array( $single_context ) ? json_encode( $single_context ) : false;
$assert( 'nested-context-boundary' === ( $single_compiler_diagnostic[0]['code'] ?? '' ) && 'notice' === ( $single_compiler_diagnostic[0]['severity'] ?? '' ), 'single-compiler-diagnostic-preserves-expected-fields' );
$assert( is_string( $single_context_json ) && strlen( $single_context_json ) <= 4096, 'single-compiler-diagnostic-bounds-json-keys-delimiters-and-empty-arrays' );

$normal_provenance_diagnostic = Static_Site_Importer_Compiler_Diagnostic_Normalizer::normalize(
	array( array( 'source' => 'artifact', 'stage' => 'compile' ) )
);
$assert( 'artifact' === ( $normal_provenance_diagnostic[0]['source'] ?? '' ) && 'compile' === ( $normal_provenance_diagnostic[0]['stage'] ?? '' ), 'single-compiler-diagnostic-retains-scalar-source-and-stage' );
$provenance_diagnostic = Static_Site_Importer_Compiler_Diagnostic_Normalizer::normalize(
	array(
		array(
			'code'     => 'provenance-boundary',
			'severity' => 'notice',
			'message'  => 'Compiler provenance is a bounded projection.',
			'context'  => array( 'preserved' => true ),
			'source'   => 'compiler/' . str_repeat( 'source-', 30 ),
			'stage'    => 'materialize/' . str_repeat( 'stage-', 30 ),
			'unknown'  => 'omitted',
		)
	)
);
$provenance_row  = $provenance_diagnostic[0] ?? array();
$provenance_json = json_encode( $provenance_row, JSON_INVALID_UTF8_SUBSTITUTE );
$assert( 'provenance-boundary' === ( $provenance_row['code'] ?? '' ) && 'notice' === ( $provenance_row['severity'] ?? '' ) && array( 'preserved' => true ) === ( $provenance_row['context'] ?? null ), 'single-compiler-diagnostic-preserves-code-severity-and-context' );
$assert( 'compiler/' === substr( (string) ( $provenance_row['source'] ?? '' ), 0, 9 ) && 128 === strlen( (string) ( $provenance_row['source'] ?? '' ) ) && 'materialize/' === substr( (string) ( $provenance_row['stage'] ?? '' ), 0, 12 ) && 128 === strlen( (string) ( $provenance_row['stage'] ?? '' ) ), 'single-compiler-diagnostic-caps-scalar-source-and-stage' );
$assert( ! isset( $provenance_row['unknown'] ) && is_string( $provenance_json ) && strlen( $provenance_json ) <= 4096, 'single-compiler-diagnostic-omits-unknown-fields-and-remains-valid-json' );
$array_provenance_diagnostic = Static_Site_Importer_Compiler_Diagnostic_Normalizer::normalize(
	array( array( 'source' => array( 'not' => 'scalar' ), 'stage' => array( 'not' => 'scalar' ) ) )
);
$assert( ! isset( $array_provenance_diagnostic[0]['source'], $array_provenance_diagnostic[0]['stage'] ), 'compiler-diagnostic-omits-non-scalar-provenance' );

$adversarial_compiler_diagnostics = array();
for ( $index = 0; $index < 80; ++$index ) {
	$adversarial_compiler_diagnostics[] = array(
		'code'     => 'compiler-' . $index,
		'severity' => 0 === $index % 7 ? 'error' : 'warning',
		'message'  => str_repeat( 'message-', 200 ),
		'context'  => array( 'nested' => array( 'again' => array( 'payload' => str_repeat( 'context-', 1000 ), 'extra' => array( 'discard' => true ) ) ) ),
	);
}
$adversarial_compiler_diagnostics[] = $adversarial_compiler_diagnostics[ 79 ];
$adversarial_projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array( 'compiler_diagnostics' => $adversarial_compiler_diagnostics ), array(), array(), array(), 'compiler-adversarial-run', array(), array() );
$adversarial_report     = $adversarial_projection['report'];
$adversarial_quality    = Static_Site_Importer_Report_Diagnostics::finalize_report( $adversarial_report, array() );
$adversarial_persisted  = $adversarial_report->to_array();
$adversarial_summary    = Static_Site_Importer_Report_Diagnostics::import_report_summary( $adversarial_persisted, $adversarial_quality );
$aggregate              = $adversarial_persisted['diagnostics'][0] ?? array();
$aggregate_context_json = json_encode( $aggregate['context'] ?? array() );
$summary_context_json   = json_encode( $adversarial_summary['diagnostics'][0]['context'] ?? array() );
$assert( 1 === count( $adversarial_persisted['diagnostics'] ?? array() ), 'final-receipt-has-one-compiler-aggregate' );
$assert( 'compiler_diagnostics_aggregated' === ( $aggregate['code'] ?? '' ), 'final-receipt-retains-compiler-aggregate-code' );
$assert( 81 === ( $aggregate['context']['diagnostic_count'] ?? 0 ) && 12 === ( $aggregate['context']['diagnostic_by_severity']['error'] ?? 0 ) && 69 === ( $aggregate['context']['diagnostic_by_severity']['warning'] ?? 0 ) && 61 === ( $aggregate['context']['code_occurrences_omitted'] ?? 0 ) && ! isset( $aggregate['context']['codes_omitted'] ), 'final-receipt-retains-truthful-compiler-counts' );
$assert( 5 === count( $aggregate['context']['samples'] ?? array() ) && 76 === ( $aggregate['context']['samples_omitted'] ?? -1 ), 'final-receipt-bounds-compiler-samples' );
$assert( 20 === count( $aggregate['context']['diagnostic_by_code'] ?? array() ), 'final-receipt-bounds-compiler-code-categories' );
$assert( is_string( $aggregate_context_json ) && strlen( $aggregate_context_json ) <= 4096, 'final-receipt-bounds-compiler-context' );
$assert( 1 === count( $adversarial_summary['diagnostics'] ?? array() ), 'compact-cli-summary-does-not-duplicate-staged-compiler-warning' );
$assert( is_string( $summary_context_json ) && strlen( $summary_context_json ) <= 4096, 'final-apply-summary-keeps-compiler-aggregate-bounded' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report smoke passed (' . $assertions . " assertions)\n";
