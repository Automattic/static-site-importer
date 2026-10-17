<?php
/**
 * Report-size regression: the persisted import report embeds a reduced site
 * plan (no inline payload content) and a single diagnostics list, so its size
 * tracks page count rather than asset bytes.
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
$plan      = $persisted['blocks_engine']['wordpress_site_plan'] ?? array();

$write = $plan['writes'][0] ?? array();
$assert( ! array_key_exists( 'canonical_payload', $write ), 'report-plan-write-drops-canonical-payload' );
$assert( is_array( $write['payload'] ?? null ) && ! array_key_exists( 'data', $write['payload'] ), 'report-plan-write-drops-resolved-payload-data' );
$assert( 'assets/css/site.css' === ( $write['target_path'] ?? '' ) && hash( 'sha256', $small_css ) === ( $write['payload_hash'] ?? '' ) && hash( 'sha256', $small_css ) === ( $write['canonical_payload_hash'] ?? '' ), 'report-plan-write-keeps-target-path-and-hashes' );

$asset = $plan['assets'][0] ?? array();
$assert( ! array_key_exists( 'content', $asset ) && ! array_key_exists( 'content_base64', $asset ), 'report-plan-asset-drops-inline-content' );
$assert( 'assets/css/site.css' === ( $asset['target_path'] ?? '' ) && strlen( $small_css ) === ( $asset['bytes'] ?? 0 ) && hash( 'sha256', $small_css ) === ( $asset['hash'] ?? '' ), 'report-plan-asset-keeps-target-path-bytes-and-hash' );

$visual_repair = $plan['visual_repair'] ?? array();
$assert( ! array_key_exists( 'css', $visual_repair ) && ! array_key_exists( 'compat_css', $visual_repair ), 'report-plan-visual-repair-drops-css-copies' );
$assert( 'website/site.css' === ( $visual_repair['stylesheets'][0]['path'] ?? '' ) && strlen( $small_css ) === ( $visual_repair['stylesheets'][0]['bytes'] ?? 0 ), 'report-plan-visual-repair-keeps-stylesheet-paths-and-bytes' );

$markup_free = true;
$hashes_kept = true;
foreach ( $plan['pages'] ?? array() as $page ) {
	foreach ( array( 'canonical_block_markup', 'resolved_block_markup', 'materialized_block_markup' ) as $field ) {
		$markup_free = $markup_free && ! array_key_exists( $field, $page );
	}
	$hashes_kept = $hashes_kept && '' !== (string) ( $page['content_hash'] ?? '' ) && '' !== (string) ( $page['source_path'] ?? '' );
}
$assert( 3 === count( $plan['pages'] ?? array() ) && $markup_free, 'report-plan-pages-drop-block-markup' );
$assert( $hashes_kept, 'report-plan-pages-keep-source-paths-and-content-hashes' );

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

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report size smoke passed (' . $assertions . " assertions)\n";
