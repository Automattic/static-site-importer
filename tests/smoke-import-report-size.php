<?php
/**
 * Report-size regression: response artifacts persist evidence once and reference
 * the plan and shared evidence by identity.
 *
 * Reduced fixture: a multi-page import whose pages share one small stylesheet.
 *
 * Run from the repository root:
 * php tests/smoke-import-report-size.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

/**
 * Reduced fixture: $page_count pages sharing one stylesheet of $css bytes.
 *
 * @param int    $page_count Number of pages in the plan.
 * @param string $css        Shared stylesheet content.
 * @return array<string,mixed> Materialization receipt for compose().
 */
$fixture_receipt = static function ( int $page_count, string $css ): array {
	$css_hash           = hash( 'sha256', $css );
	$pages              = array();
	$completed_pages    = array();
	$materialized_pages = array();
	for ( $index = 1; $index <= $page_count; $index++ ) {
		$source_path          = 'website/page-' . $index . '.html';
		$markup               = '<!-- wp:paragraph --><p>page-' . $index . '-markup-payload-sentinel</p><!-- /wp:paragraph -->';
		$pages[]              = array(
			'source_path'               => $source_path,
			'slug'                      => 'page-' . $index,
			'post_type'                 => 'page',
			'entrypoint'                => 1 === $index,
			'route'                     => array( 'path' => 1 === $index ? '/' : '/page-' . $index . '/' ),
			'reconciliation_identity'   => 'page:' . $source_path,
			'content_hash'              => hash( 'sha256', $markup ),
			'canonical_block_markup'    => $markup,
			'resolved_block_markup'     => $markup,
			'materialized_block_markup' => $markup,
		);
		$completed_pages[ $source_path ]    = 100 + $index;
		$materialized_pages[ $source_path ] = array(
			'block_markup' => $markup,
			'content_hash' => hash( 'sha256', $markup ),
		);
	}

	$plan = array(
		'schema'         => 'blocks-engine/wordpress-site-plan/v2',
		'plan_identity'  => array( 'schema' => 'blocks-engine/wordpress-site-plan-identity/v1', 'hash' => str_repeat( 'a', 64 ) ),
		'diagnostics'    => array(
			array(
				'code'     => 'report_size_smoke_notice',
				'type'     => 'static-site-importer',
				'severity' => 'notice',
				'message'  => 'single-diagnostics-copy-sentinel',
			),
		),
		'quality'        => array(),
		'source'         => array(
			'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
			'source_hash' => 'report-size-smoke',
			'entry_path'  => 'website/page-1.html',
			'provenance'  => array(),
		),
		'template_parts' => array(),
		'pages'          => $pages,
		'writes'         => array(
			array(
				'target_path'             => 'assets/css/site.css',
				'kind'                    => 'theme_asset',
				'source_path'             => 'website/site.css',
				'reconciliation_identity' => 'write:assets/css/site.css',
				'payload'                 => array(
					'encoding' => 'utf8',
					'data'     => $css,
				),
				'canonical_payload'       => $css,
				'payload_hash'            => $css_hash,
				'canonical_payload_hash'  => $css_hash,
			),
		),
		'assets'         => array(
			array(
				'token'          => 'asset:site-css',
				'source_path'    => 'website/site.css',
				'target_path'    => 'assets/css/site.css',
				'kind'           => 'css',
				'bytes'          => strlen( $css ),
				'hash'           => $css_hash,
				'content'        => $css,
				'content_base64' => base64_encode( $css ),
			),
		),
		'visual_repair'  => array(
			'stylesheets' => array(
				array(
					'path'  => 'website/site.css',
					'bytes' => strlen( $css ),
				),
			),
			'css'         => $css,
			'compat_css'  => $css,
		),
	);

	return array(
		'status'           => 'completed',
		'plan'             => $plan,
		'plan_identity'    => $plan['plan_identity'],
		'theme'            => array( 'slug' => 'report-size', 'dir' => sys_get_temp_dir() . '/report-size' ),
		'completed'        => array(
			'pages'                => $completed_pages,
			'materialized_pages'   => $materialized_pages,
			'files'                => array(),
			'font_materialization' => array( 'files' => array() ),
		),
		'existing_matches' => array( 'pages' => array() ),
		'extensions'       => array(),
	);
};

/**
 * Compose and finalize one persisted report for a fixture receipt.
 *
 * @param array<string,mixed> $receipt Materialization receipt.
 * @return array{report:array<string,mixed>,json:string}
 */
$persisted_report = static function ( array $receipt ): array {
	$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array( 'artifact_hash' => hash( 'sha256', 'report-size-smoke' ) ), array(), array(), array(), 'report-size-run', array(), array() );
	$report     = $projection['report'];
	Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
	$persisted = $report->to_array();

	return array(
		'report' => $persisted,
		'json'   => (string) json_encode( $persisted ),
	);
};

// The reduced fixture from the report-size issue: 3 pages sharing one ~49-byte stylesheet.
$small_css = '.report-size-css-payload-sentinel{margin:0 auto}';
$baseline  = $persisted_report( $fixture_receipt( 3, $small_css ) );
$persisted = $baseline['report'];
$json      = $baseline['json'];
$plan = $persisted['blocks_engine']['plan_summary'] ?? array();
$assert( ! isset( $persisted['blocks_engine']['wordpress_site_plan'] ), 'site-plan-is-not-embedded' );
$assert( 3 === ( $plan['page_count'] ?? 0 ), 'summary-keeps-page-count' );
$assert( ! isset( $plan['pages'], $plan['writes'] ), 'summary-is-not-executable-plan' );

$assert( ! str_contains( $json, $small_css ), 'persisted-report-carries-no-stylesheet-payload-bytes' );
$assert( ! str_contains( $json, base64_encode( $small_css ) ), 'persisted-report-carries-no-base64-asset-payload' );
$assert( ! str_contains( $json, 'page-1-markup-payload-sentinel' ), 'persisted-report-carries-no-page-block-markup' );

$artifact_diagnostics = $persisted['artifact_diagnostics'] ?? array();
$assert( ! array_key_exists( 'diagnostics', $artifact_diagnostics ), 'artifact-diagnostics-does-not-duplicate-the-top-level-list' );
$assert( 'diagnostics' === ( $artifact_diagnostics['diagnostics_ref'] ?? '' ), 'artifact-diagnostics-references-the-canonical-list' );
$assert( count( $persisted['diagnostics'] ?? array() ) === ( $artifact_diagnostics['summary']['total'] ?? -1 ), 'artifact-diagnostics-summary-counts-the-canonical-list' );
$assert( 1 <= count( $persisted['diagnostics'] ?? array() ), 'top-level-diagnostics-list-is-kept' );

// Size tracks page count, not asset bytes: a 200 KB stylesheet may not grow the
// report by more than bookkeeping noise, while doubling pages must grow it.
$large_css        = '.report-size-css-payload-sentinel{content:"' . str_repeat( 'x', 200000 ) . '"}';
$large_asset_json = $persisted_report( $fixture_receipt( 3, $large_css ) )['json'];
$more_pages_json  = $persisted_report( $fixture_receipt( 6, $small_css ) )['json'];
$assert( abs( strlen( $large_asset_json ) - strlen( $json ) ) < 2048, 'report-size-is-independent-of-asset-bytes', sprintf( 'baseline %d bytes, 200KB-asset %d bytes', strlen( $json ), strlen( $large_asset_json ) ) );
$assert( strlen( $more_pages_json ) > strlen( $json ), 'report-size-grows-with-page-count', sprintf( '3 pages %d bytes, 6 pages %d bytes', strlen( $json ), strlen( $more_pages_json ) ) );

// Exercise the same immutable response publication path used by abilities/CLI.
$response_fixture = static function ( int $bytes ) use ( $fixture_receipt, $persisted_report, $small_css ): array {
	$receipt = $fixture_receipt( 3, $small_css );
	$evidence = array( 'nodes' => array( 'fallback-evidence-sentinel' . str_repeat( 'f', $bytes ) ) );
	$receipt['plan']['quality']['fallbacks'] = array( array( 'type' => 'html_form_fallback', 'layout_graph' => $evidence, 'report_only' => true ) );
	$entity = array( 'id' => 'form.contact', 'layout_graph' => $evidence, 'evidence' => 'runtime-evidence-sentinel' . str_repeat( 'r', 1024 ), 'bindings' => array( array( 'source_path' => 'contact.html' ) ) );
	$receipt['plan']['runtime_entity_records'] = array( array( 'content_hash' => hash( 'sha256', json_encode( $entity ) ), 'entity' => $entity ) );
	$resolved_entity = $entity;
	$resolved_entity['bindings'][0]['resolved_target'] = 'page:42';
	$receipt['plan']['runtime_entity_resolution'] = array( array( 'entities' => array( $resolved_entity ) ) );
	$receipt['runtime_lifecycle']['entities']['form.contact']['manifest']['entities'] = $receipt['plan']['runtime_entity_records'];
	$report = $persisted_report( $receipt )['report'];
	$report['import_run_id'] = 'size-' . $bytes . '-' . bin2hex( random_bytes( 5 ) );
	$result = Static_Site_Importer_Canonical_Import_Service::bound_success_result( array(
		'import_report' => $report,
		'materialization_receipt' => $receipt,
		'quality' => $report['quality'],
	) );
	$artifacts = $result['response_artifacts']['artifacts'] ?? array();
	$total = 0;
	$copies = 0;
	$runtime_copies = 0;
	foreach ( $artifacts as $artifact ) {
		$content = (string) file_get_contents( $artifact['path'] );
		$total += strlen( $content );
		$copies += substr_count( $content, 'fallback-evidence-sentinel' );
		$runtime_copies += substr_count( $content, 'runtime-evidence-sentinel' );
	}
	$loaded = array();
	foreach ( $artifacts as $name => $artifact ) {
		$loaded[ $name ] = json_decode( (string) file_get_contents( $artifact['path'] ), true );
	}
	$resolved = method_exists( Static_Site_Importer_Canonical_Import_Service::class, 'resolve_response_evidence' )
		? Static_Site_Importer_Canonical_Import_Service::resolve_response_evidence( $loaded['result_details']['quality']['fallbacks_ref'] ?? array(), $loaded )
		: array();
	$expanded = Static_Site_Importer_Canonical_Import_Service::resolve_response_evidence( $loaded['wordpress_site_plan'], $loaded );
	return array( 'bytes' => $total, 'copies' => $copies, 'artifacts' => $artifacts, 'resolved' => $resolved, 'runtime_copies' => $runtime_copies, 'expanded' => $expanded, 'entity' => $entity, 'resolved_entity' => $resolved_entity );
};
$response_small = $response_fixture( 1024 );
$response_large = $response_fixture( 101024 );
$assert( ! empty( $response_small['artifacts'] ), 'response-artifacts-published' );
$assert( 'html_form_fallback' === ( $response_small['resolved'][0]['type'] ?? '' ), 'fallback-reference-resolves-to-canonical-evidence' );
$assert( 1 === $response_small['runtime_copies'], 'runtime-manifest-persisted-once' );
$assert( $response_small['entity'] == $response_small['expanded']['runtime_entity_records'][0]['entity'], 'source-entity-round-trips' );
$assert( $response_small['resolved_entity'] == $response_small['expanded']['runtime_entity_resolution'][0]['entities'][0], 'resolved-entity-round-trips-with-distinct-bindings' );
$assert( 1 === $response_small['copies'] && 1 === $response_large['copies'], 'fallback-list-persisted-once' );
$growth = $response_large['bytes'] - $response_small['bytes'];
$assert( $growth >= 100000 && $growth < 102048, 'artifact-growth-is-distinct-evidence-only', (string) $growth );
echo sprintf( "Reduced response fixture: %d bytes; +100KB distinct evidence: %d bytes\n", $response_small['bytes'], $response_large['bytes'] );

// A composed checkpoint already owns the executable plan. Publication must
// advertise that artifact (and its pointer), not persist another plan copy.
$checkpoint_root = Static_Site_Importer_Direct_Artifact_Import::root() . '/plan-size-' . bin2hex( random_bytes( 5 ) );
wp_mkdir_p( $checkpoint_root );
$checkpoint_plan = $response_small['expanded'];
$checkpoint_plan['writes'][0]['size_test'] = 'canonical-plan-payload-sentinel';
$checkpoint_path = $checkpoint_root . '/composed-result.json';
file_put_contents( $checkpoint_path, wp_json_encode( array( 'payload' => array( 'result' => array( 'wordpress_site_plan' => $checkpoint_plan ) ) ) ) );
$descriptor = array( 'path' => $checkpoint_path, 'sha256' => hash_file( 'sha256', $checkpoint_path ), 'bytes' => filesize( $checkpoint_path ), 'json_pointer' => '/payload/result/wordpress_site_plan', 'plan_identity' => $checkpoint_plan['plan_identity'] ?? array() );
$checkpoint_receipt = $fixture_receipt( 3, $small_css );
$checkpoint_receipt['plan'] = $checkpoint_plan;
$checkpoint_report = $persisted_report( $checkpoint_receipt )['report'];
$checkpoint_report['import_run_id'] = 'checkpoint-size-' . bin2hex( random_bytes( 5 ) );
$checkpoint_response = Static_Site_Importer_Canonical_Import_Service::bound_success_result( array( 'import_report' => $checkpoint_report, 'materialization_receipt' => $checkpoint_receipt ), array(), $descriptor );
$checkpoint_artifacts = $checkpoint_response['response_artifacts']['artifacts'];
$assert( $descriptor === ( $checkpoint_artifacts['wordpress_site_plan'] ?? null ), 'response-reuses-checkpoint-identity-and-pointer' );
$assert( $checkpoint_plan === Static_Site_Importer_Canonical_Import_Service::load_response_artifact( $checkpoint_artifacts['wordpress_site_plan'] ), 'checkpoint-plan-consumer-follows-pointer' );
$invalid_descriptor = $descriptor;
$invalid_descriptor['sha256'] = str_repeat( '0', 64 );
$assert( array() === Static_Site_Importer_Canonical_Import_Service::load_response_artifact( $invalid_descriptor ), 'checkpoint-consumer-rejects-invalid-digest' );
$plan_copies = substr_count( (string) file_get_contents( $checkpoint_path ), 'canonical-plan-payload-sentinel' );
$checkpoint_fallback_copies = 0;
$checkpoint_entity_copies = 0;
foreach ( $checkpoint_artifacts as $name => $artifact ) {
	if ( 'wordpress_site_plan' !== $name ) {
		$content = (string) file_get_contents( $artifact['path'] );
		$checkpoint_fallback_copies += substr_count( $content, 'fallback-evidence-sentinel' );
		$checkpoint_entity_copies += substr_count( $content, 'runtime-evidence-sentinel' );
	}
	if ( 'wordpress_site_plan' !== $name ) {
		$plan_copies += substr_count( (string) file_get_contents( $artifact['path'] ), 'canonical-plan-payload-sentinel' );
		$assert( ! file_exists( dirname( $artifact['path'] ) . '/wordpress-site-plan.json' ), 'no-response-plan-copy-' . $name );
	}
}
$assert( 1 === $plan_copies, 'plan-persisted-once-across-workspace' );
$assert( 0 === $checkpoint_fallback_copies && 0 === $checkpoint_entity_copies, 'response-does-not-copy-checkpoint-fallback-or-entity-evidence' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report size smoke passed (' . $assertions . " assertions)\n";
