<?php
/**
 * Smoke test: website artifact import preserves static interactive diagnostics and assets.
 *
 * Run inside a WordPress site with Static Site Importer available:
 * wp eval-file tests/smoke-static-interactive-artifact.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Smoke test reads generated artifacts.
	return false === $contents ? '' : $contents;
};

$assert( class_exists( 'Automattic\\BlocksEngine\\PhpTransformer\\StaticSite\\MaterializationView' ), 'materialization-view-class-available' );

$artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Static Interactive Kitchen</title><link rel="stylesheet" href="assets/site.css"><script type="module" src="assets/app.js" defer></script><script src="assets/analytics.js" async></script></head><body><main><section class="accordion"><button aria-expanded="false">Open pantry notes</button><div hidden><p>Fermentation schedule.</p></div></section><section class="tabs" role="tablist"><button role="tab" aria-selected="true">Bake</button><button role="tab">Serve</button></section><dialog><p>Reservation dialog fallback.</p></dialog><section class="carousel"><button class="prev">Prev</button><img src="assets/images/photo.svg" alt="Loaf carousel"><button class="next">Next</button></section><form action="/newsletter" method="post"><label>Email<input name="email" type="email"></label><button>Send</button></form></main></body></html>',
		),
		array(
			'path'    => 'website/assets/site.css',
			'content' => '@import url("theme.css");@font-face{font-family:"Kitchen Local";src:url("fonts/kitchen.woff2") format("woff2");font-weight:400}.accordion{border:1px solid #332}.carousel img{width:100%;height:auto}',
		),
		array(
			'path'    => 'website/assets/theme.css',
			'content' => '.tabs{display:flex;gap:0.5rem}.prev,.next{border-radius:999px}',
		),
		array(
			'path'    => 'website/assets/app.js',
			'content' => 'export function bootStaticKitchen(){document.documentElement.dataset.staticKitchen="ready";}',
		),
		array(
			'path'    => 'website/assets/analytics.js',
			'content' => 'window.staticKitchenAnalytics=true;',
		),
		array(
			'path'     => 'website/assets/fonts/kitchen.woff2',
			'encoding' => 'base64',
			'content'  => base64_encode( 'fake-font-fixture' ),
		),
		array(
			'path'    => 'website/assets/images/photo.svg',
			'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" fill="#d2691e"/><circle cx="8" cy="8" r="4" fill="#fff4d6"/></svg>',
		),
	),
);

$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	$artifact,
	array(
		'name'                         => 'Static Interactive Fixture',
		'slug'                         => 'static-interactive-fixture-smoke',
		'overwrite'                    => true,
		'activate'                     => false,
		'write_theme_report_artifacts' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir = $result['theme_dir'];
	$report    = json_decode( $read( $result['report_path'] ), true );
	$style     = $read( $theme_dir . '/style.css' );
	$metadata  = $report['generated_theme']['document_metadata'] ?? array();
	$scripts   = isset( $metadata['scripts'] ) && is_array( $metadata['scripts'] ) ? $metadata['scripts'] : array();
	$page_ids  = array_values( $result['pages'] ?? array() );
	$page      = ! empty( $page_ids[0] ) ? get_post( (int) $page_ids[0] ) : null;
	$content   = $page instanceof WP_Post ? $page->post_content : '';
	$be_report = $report['blocks_engine']['conversion_report'] ?? array();
	$gate      = $report['import_validation_result']['quality_gates']['interaction_candidates'] ?? array();

	$assert( 'blocks-engine/php-transformer/conversion-report/v1' === ( $be_report['schema'] ?? '' ), 'conversion-report-schema-recorded' );
	$assert( 2 <= (int) ( $be_report['fallback_count'] ?? 0 ), 'fallback-diagnostics-counted' );
	$assert( 2 <= count( $be_report['fallback_diagnostics'] ?? array() ), 'fallback-diagnostics-exposed' );
	$assert( array_key_exists( 'interaction_candidate_count', $be_report ), 'interaction-candidate-count-exposed' );
	$assert( array_key_exists( 'interaction_candidate_count', $report['quality'] ?? array() ), 'interaction-candidate-quality-count-exposed' );
	$assert( isset( $gate['status'] ), 'interaction-candidate-gate-recorded' );
	$assert( 'static-site-importer/document-metadata/v1' === ( $metadata['schema'] ?? '' ), 'document-metadata-recorded' );
	$assert( 'module' === ( $scripts[0]['type'] ?? '' ), 'module-script-type-preserved' );
	$assert( true === ( $scripts[0]['defer'] ?? false ), 'defer-script-metadata-preserved' );
	$assert( true === ( $scripts[1]['async'] ?? false ), 'async-script-metadata-preserved' );
	$assert( str_contains( $style, '@font-face' ), 'style-preserves-font-face' );
	$assert( str_contains( $style, '.tabs' ), 'style-includes-imported-css' );
	$assert( is_file( $theme_dir . '/assets/materialized/website/assets/site.css' ), 'site-css-materialized' );
	$assert( is_file( $theme_dir . '/assets/materialized/website/assets/theme.css' ), 'imported-css-materialized' );
	$assert( is_file( $theme_dir . '/assets/materialized/website/assets/app.js' ), 'module-js-materialized' );
	$assert( is_file( $theme_dir . '/assets/materialized/website/assets/analytics.js' ), 'async-js-materialized' );
	$assert( is_file( $theme_dir . '/assets/materialized/website/assets/fonts/kitchen.woff2' ), 'font-asset-materialized' );
	$assert( is_file( $theme_dir . '/assets/materialized/website/assets/images/photo.svg' ), 'image-asset-materialized' );
	$assert( str_contains( $content, 'assets/materialized/website/assets/images/photo.svg' ), 'page-content-rewrites-local-image' );
	$assert( ! str_contains( $content, 'src="assets/images/photo.svg"' ), 'page-content-removes-source-image-path' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: static interactive artifact smoke passed (' . $assertions . " assertions)\n";
