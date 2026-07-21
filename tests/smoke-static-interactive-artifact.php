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

$artifact = array(
	'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Static Interactive Kitchen</title><link rel="stylesheet" href="assets/site.css"><script type="module" src="assets/app.js" defer></script><script src="assets/analytics.js" async></script></head><body><header><a href="/">Kitchen Home</a><nav><a href="/menu/">Menu</a></nav></header><main><section class="accordion"><button aria-expanded="false">Open pantry notes</button><div hidden><p>Fermentation schedule.</p></div></section><section class="tabs" role="tablist"><button role="tab" aria-selected="true">Bake</button><button role="tab">Serve</button></section><dialog><p>Reservation dialog fallback.</p></dialog><section class="carousel"><button class="prev">Prev</button><img src="assets/images/photo.svg" alt="Loaf carousel"><button class="next">Next</button></section><form action="/newsletter" method="post"><label>Email<input name="email" type="email"></label><button>Send</button></form></main><footer><p>Kitchen Footer</p></footer></body></html>',
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
	$metadata  = $report['generated_theme']['document_metadata'] ?? array();
	$scripts   = isset( $metadata['scripts'] ) && is_array( $metadata['scripts'] ) ? $metadata['scripts'] : array();
	$page_ids  = array_values( $result['pages'] ?? array() );
	$page      = ! empty( $page_ids[0] ) ? get_post( (int) $page_ids[0] ) : null;
	$content   = $page instanceof WP_Post ? $page->post_content : '';
	$plan      = $report['blocks_engine']['wordpress_site_plan'] ?? array();
	$provenance = $report['blocks_engine']['transformer'] ?? array();
	$receipt   = $result['materialization_receipt'] ?? array();
	$validation = $result['import_validation_result'] ?? array();
	$assets_by_source = array();
	foreach ( $plan['assets'] ?? array() as $asset ) {
		if ( is_array( $asset ) && isset( $asset['source_path'], $asset['target_path'] ) ) {
			$assets_by_source[ (string) $asset['source_path'] ] = $asset;
		}
	}

	$assert( 'blocks-engine/wordpress-site-plan/v2' === ( $plan['schema'] ?? '' ), 'canonical-plan-recorded' );
	$assert( '' !== (string) ( $provenance['package'] ?? '' ) && '' !== (string) ( $provenance['version'] ?? '' ) && '' !== (string) ( $provenance['reference'] ?? '' ), 'transformer-provenance-is-complete' );
	$assert( ! isset( $report['blocks_engine']['compiled_site'] ) && ! isset( $report['blocks_engine']['materialization_plan'] ), 'report-has-no-legacy-projections' );
	$assert( isset( $report['quality']['pass'] ) && is_array( $validation['diagnostics'] ?? null ) && isset( $validation['quality'] ), 'quality-and-validation-are-public-siblings' );
	$assert( is_array( $plan['diagnostics'] ?? null ), 'canonical-plan-diagnostics-are-recorded' );
	$assert( 2 <= count( $plan['template_parts'] ?? array() ), 'canonical-plan-extracts-shared-chrome' );
	$assert( ! str_contains( $content, 'Kitchen Home' ) && ! str_contains( $content, 'Kitchen Footer' ), 'page-markup-does-not-duplicate-shared-chrome' );
	$assert( 'static-site-importer/document-metadata/v1' === ( $metadata['schema'] ?? '' ), 'document-metadata-recorded' );
	$assert( 'module' === ( $scripts[0]['type'] ?? '' ), 'module-script-type-preserved' );
	$assert( true === ( $scripts[0]['defer'] ?? false ), 'defer-script-metadata-preserved' );
	$assert( true === ( $scripts[1]['async'] ?? false ), 'async-script-metadata-preserved' );
	foreach ( array( 'website/assets/site.css', 'website/assets/theme.css', 'website/assets/app.js', 'website/assets/analytics.js', 'website/assets/fonts/kitchen.woff2', 'website/assets/images/photo.svg' ) as $source_path ) {
		$target_path = (string) ( $assets_by_source[ $source_path ]['target_path'] ?? '' );
		$assert( '' !== $target_path && is_file( $theme_dir . '/' . $target_path ), 'canonical-asset-write-' . $source_path, $target_path );
	}
	$assert( is_file( $theme_dir . '/parts/header.html' ) && is_file( $theme_dir . '/parts/footer.html' ), 'canonical-plan-materializes-shared-chrome' );
	$assert( str_contains( $read( $theme_dir . '/parts/header.html' ), 'Kitchen Home' ) && str_contains( $read( $theme_dir . '/parts/footer.html' ), 'Kitchen Footer' ), 'shared-chrome-is-preserved-in-template-parts' );
	$receipt_svg_assets = array_filter( $receipt['plan']['assets'] ?? array(), static fn( array $asset ): bool => str_ends_with( (string) ( $asset['target_path'] ?? '' ), '.svg' ) );
	$assert( ! empty( $receipt_svg_assets ), 'receipt-preserves-declared-svg-asset' );
	$svg_target = (string) ( $assets_by_source['website/assets/images/photo.svg']['target_path'] ?? '' );
	$assert( '' !== $svg_target && str_contains( $content, $svg_target ) && ! str_contains( $content, 'src="assets/images/photo.svg"' ), 'page-content-uses-resolved-canonical-svg-url' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: static interactive artifact smoke passed (' . $assertions . " assertions)\n";
