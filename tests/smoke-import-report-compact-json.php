<?php
/**
 * Regression: the journaled report writer publishes compact JSON.
 *
 * The theme-shipped artifacts (import-report.json, static-site-importer-manifest.json,
 * import-validation-result.json, finding-packets.json) are produced by
 * Journaled_Report_Writer with JSON_UNESCAPED_SLASHES and no JSON_PRETTY_PRINT
 * indentation (#2314). Pretty-printed indentation inflated a 50-page import
 * report from 34.7 MB compact to 80 MB, and the report ships inside the theme,
 * so it counts toward the exported-site size bound.
 *
 * Reduced fixture: a three-page plan whose pages share one small stylesheet,
 * projected through Receipt_Projection::compose()/finalize() and published
 * through the real journaled writer under the production theme paths.
 *
 * Run from the repository root:
 * php tests/smoke-import-report-compact-json.php
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $options = 0 ) {
		return json_encode( $value, $options );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

$static_site_importer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $static_site_importer_autoload ) ) {
	require_once $static_site_importer_autoload;
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
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-quality-budget-admission.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-projection.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-receipt-projection.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-journaled-report-writer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

/**
 * Reduced fixture: three pages sharing one small stylesheet.
 *
 * @return array<string,mixed> Materialization receipt for compose().
 */
$fixture_receipt = static function (): array {
	$css  = '.compact-json-css-payload-sentinel{margin:0 auto}';
	$hash = hash( 'sha256', $css );
	$pages              = array();
	$completed_pages    = array();
	$materialized_pages = array();
	for ( $index = 1; $index <= 3; $index++ ) {
		$source_path = 'website/page-' . $index . '.html';
		$markup      = '<!-- wp:paragraph --><p>page-' . $index . '-markup-payload-sentinel</p><!-- /wp:paragraph -->';
		$pages[]     = array(
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
				'code'     => 'compact_json_smoke_notice',
				'type'     => 'static-site-importer',
				'severity' => 'notice',
				'message'  => 'single-diagnostics-copy-sentinel',
			),
		),
		'quality'        => array(),
		'source'         => array(
			'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
			'source_hash' => 'compact-json-smoke',
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
				'payload_hash'            => $hash,
				'canonical_payload_hash'  => $hash,
			),
		),
		'assets'         => array(
			array(
				'source_path'    => 'website/site.css',
				'target_path'    => 'assets/css/site.css',
				'kind'           => 'css',
				'bytes'          => strlen( $css ),
				'hash'           => $hash,
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
		'theme'            => array( 'slug' => 'compact-json-smoke', 'dir' => sys_get_temp_dir() . '/compact-json-smoke' ),
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

// Project, finalize, and publish the artifacts exactly as the theme generator does.
$receipt           = $fixture_receipt();
$args              = array( 'artifact_hash' => hash( 'sha256', 'compact-json-smoke' ) );
$projection        = Static_Site_Importer_Receipt_Projection::compose( $receipt, $args, array(), array(), array(), 'compact-json-smoke-run', array(), array() );
$report            = $projection['report'];
$manifest          = $projection['manifest'];
$quality           = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, $args );
$manifest['existing_matches'] = $receipt['existing_matches'] ?? array( 'pages' => array() );
$final             = Static_Site_Importer_Receipt_Projection::finalize( $report, $manifest, $receipt['plan'], $receipt, $args, $quality );

$theme_dir  = sys_get_temp_dir() . '/ssi-compact-json-' . bin2hex( random_bytes( 4 ) ) . '/wp-content/themes/compact-json-smoke';
$artifacts  = array(
	$theme_dir . '/import-report.json'                 => $report->to_array(),
	$theme_dir . '/static-site-importer-manifest.json' => $manifest,
	$theme_dir . '/import-validation-result.json'      => $final['validation'],
	$theme_dir . '/finding-packets.json'               => $final['findings'],
);
$publish_receipt = array();
wp_mkdir_p( $theme_dir );
foreach ( $artifacts as $path => $payload ) {
	Static_Site_Importer_Journaled_Report_Writer::write( $path, $payload, $publish_receipt );
}

$report_bytes = (string) file_get_contents( $theme_dir . '/import-report.json' );

foreach ( $artifacts as $path => $payload ) {
	$bytes    = (string) file_get_contents( $path );
	$basename = basename( $path );

	// The regression: no pretty-print indentation in the published bytes.
	$assert( ! str_contains( $bytes, "\n    \"" ), $basename . '-has-no-pretty-indentation' );

	// Compact JSON_UNESCAPED_SLASHES byte contract, with a trailing newline.
	$assert( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n" === $bytes, $basename . '-matches-compact-wordpress-encoding' );

	// Same path and schema: the published file decodes back to the payload.
	$assert( is_array( json_decode( $bytes, true ) ) && json_decode( $bytes, true ) === $payload, $basename . '-decodes-to-the-published-structure' );
}

// The compact report is smaller than its pretty-printed encoding.
$pretty_report_bytes = strlen( (string) wp_json_encode( $report->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
$assert( strlen( $report_bytes ) < $pretty_report_bytes, 'import-report-is-smaller-than-pretty-encoding', sprintf( 'compact %d bytes, pretty %d bytes', strlen( $report_bytes ), $pretty_report_bytes ) );
fwrite( STDERR, sprintf( "reduced-fixture import-report.json: compact %d bytes vs pretty %d bytes\n", strlen( $report_bytes ), $pretty_report_bytes ) );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import report compact JSON smoke passed (' . $assertions . " assertions)\n";
