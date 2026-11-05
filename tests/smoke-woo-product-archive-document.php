<?php
/**
 * Portable archive document identity, asset resolution and source selection smoke.
 *
 * @package StaticSiteImporter
 */

// Reuse the source-only Core bootstrap and real adopted-listing fixture.
require __DIR__ . '/smoke-woo-product-listing-archive.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-grid-runtime.php';

/**
 * Imported page records, not the WooCommerce shop page setting.
 *
 * @param array<string,mixed> $args Query arguments.
 * @return array<int,int> Matching imported page IDs.
 */
function get_posts( array $args ): array {
	return str_repeat( 'b', 64 ) === ( $args['meta_value'] ?? '' ) ? array( 73 ) : array();
}

$failures   = array();
$assertions = 0;
$assert( 2 === ( $record['archive_product_count'] ?? null ), 'binding-record-counts-adopted-grid-products' );
$token                              = '{{wordpress-site-plan:asset:logo}}';
$url                                = 'https://example.test/theme/assets/logo-2.png';
$fixture                            = $plan;
$fixture['reference_tokens']        = array(
	array(
		'token'       => 'logo',
		'target_path' => 'assets/logo.png',
	),
);
$fixture['resolution']              = array( 'theme_uri' => 'https://example.test/theme' );
$fixture['pages'][0]['source_path'] = 'large.html';
$fixture['pages'][0]['reconciliation_identity'] = str_repeat( 'b', 64 );
$fixture['pages'][0]['canonical_block_markup'] .= '<!-- wp:html --><img src="' . $token . '"><!-- /wp:html -->';
$fixture['pages'][0]['resolved_block_markup']  .= '<!-- wp:html --><img src="' . $url . '"><!-- /wp:html -->';
$fixture['pages'][1]                            = $fixture['pages'][0];
$fixture['pages'][1]['source_path']             = 'small.html';
$fixture['pages'][1]['reconciliation_identity'] = str_repeat( 'c', 64 );
$large                            = $record;
$large['source_path']             = 'large.html';
$large['archive_product_count']   = 8;
$small                            = $large;
$small['source_path']             = 'small.html';
$small['reconciliation_identity'] = str_repeat( 'd', 64 );
$small['archive_product_count']   = 2;

foreach ( array( array( $large, $small ), array( $small, $large ) ) as $ordered ) {
	$prepared    = $fixture;
	$reports     = array();
	$diagnostics = array();
	Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $prepared, $ordered, $reports, $diagnostics );
	$archives = array_values( array_filter( $prepared['writes'], static fn( array $write ): bool => 'templates/archive-product.html' === $write['target_path'] ) );
	$data     = $archives[0]['payload']['data'];
	$assert( 'large.html' === $archives[0]['source_path'], 'largest-listing-independent-of-order' );
	$assert( ! str_contains( $data, '{{wordpress-site-plan:asset:' ) && str_contains( $data, $url ), 'archive-assets-resolved-like-source-page' );
	$first = parse_blocks( $data )[0] ?? array();
	$assert( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $first['attrs']['metadata']['staticSiteImporterArchiveDocument'] ?? '' ) ) && ! str_contains( $data, '<!-- ssi-' ), 'archive-identity-is-first-block-metadata-not-freeform-markup' );
	$assert( $fixture['pages'][0]['resolved_block_markup'] === $prepared['pages'][0]['resolved_block_markup'] && $fixture['writes'][0] === $prepared['writes'][0], 'source-content-and-other-template-unchanged' );
}

// Equal grids choose the lexicographically first path in either order.
$small['archive_product_count'] = 8;
foreach ( array( array( $small, $large ), array( $large, $small ) ) as $ordered ) {
	$prepared    = $fixture;
	$reports     = array();
	$diagnostics = array();
	Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $prepared, $ordered, $reports, $diagnostics );
	$archives = array_values( array_filter( $prepared['writes'], static fn( array $write ): bool => 'templates/archive-product.html' === $write['target_path'] ) );
	$assert( 'large.html' === $archives[0]['source_path'], 'equal-grids-use-first-source-path' );
}

// A grid-only binding has no archive-specific producer markup or product count.
$grid_binding = $large;
$grid_binding['role'] = 'commerce_product_grid';
$grid_binding['search_block_markup'] = $grid_raw;
$grid_binding['replacement_block_markup'] = Static_Site_Importer_Woo_Product_Grid_Materializer::grid_block_markup( $grid );
unset( $grid_binding['archive_replacement_block_markup'], $grid_binding['archive_product_count'] );
$listing_binding = $small;
$listing_binding['archive_product_count'] = 0;
foreach ( array(
	'grid-only'            => array( array( $grid_binding ), 'large.html', str_repeat( 'b', 64 ) ),
	'listing-before-grid'  => array( array( $listing_binding, $grid_binding ), 'small.html', str_repeat( 'c', 64 ) ),
	'grid-before-listing'  => array( array( $grid_binding, $listing_binding ), 'small.html', str_repeat( 'c', 64 ) ),
) as $case => $expected ) {
	$prepared    = $fixture;
	$reports     = array();
	$diagnostics = array();
	Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $prepared, $expected[0], $reports, $diagnostics );
	$archives = array_values( array_filter( $prepared['writes'], static fn( array $write ): bool => 'templates/archive-product.html' === $write['target_path'] ) );
	$archive_markup = (string) ( $archives[0]['payload']['data'] ?? '' );
	$first = parse_blocks( $archive_markup )[0] ?? array();
	$assert( 1 === count( $archives ) && $expected[1] === $archives[0]['source_path'], $case . '-archive-source' );
	$assert( $expected[2] === ( $first['attrs']['metadata']['staticSiteImporterArchiveDocument'] ?? '' ), $case . '-archive-page-identity' );
	$assert( str_contains( $archive_markup, $url ) && ! str_contains( $archive_markup, $token ), $case . '-archive-assets-resolved' );
	if ( 'grid-only' === $case ) {
		preg_match_all( '/<img src="([^"]+)"/', $archive_markup, $archive_assets );
		preg_match_all( '/<img src="([^"]+)"/', $prepared['pages'][0]['materialized_block_markup'], $page_assets );
		$assert( $page_assets[1] === $archive_assets[1] && in_array( $url, $archive_assets[1], true ), 'grid-archive-uses-source-deduplicated-asset-urls' );
		preg_match( '/<!-- wp:woocommerce\/product-collection (\{.*?\}) -->/s', $archive_markup, $collection );
		$attrs = json_decode( $collection[1] ?? '{}', true );
		$assert( true === ( $attrs['query']['inherit'] ?? false ) && ! isset( $attrs['query']['woocommerceHandPickedProducts'] ) && 'woocommerce/product-collection/product-catalog' === ( $attrs['collection'] ?? '' ), 'grid-archive-inherits-main-query' );
		$assert( str_contains( $prepared['pages'][0]['materialized_block_markup'], $grid_binding['replacement_block_markup'] ), 'grid-source-keeps-hand-picked-page-query' );
	}
}

// A final source projection may differ from the canonical token table. It
// includes materialized SVG targets and Media Library URLs/attachment IDs.
$prepared = $fixture;
$media_url = 'https://example.test/uploads/logo.png';
$svg_url = 'https://example.test/theme/assets/materialized-svg/inline-2.svg';
$prepared['resolution']['attachment_resolutions'] = array( 'logo' => array( 'id' => 91, 'url' => $media_url ) );
$prepared['pages'][0]['resolved_block_markup'] = str_replace( $url, $media_url, $prepared['pages'][0]['resolved_block_markup'] );
$prepared['pages'][0]['resolved_block_markup'] .= '<!-- wp:html --><img src="' . $svg_url . '"><!-- /wp:html -->';
$reports = array();
$diagnostics = array();
Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $prepared, array( $grid_binding ), $reports, $diagnostics );
$archives = array_values( array_filter( $prepared['writes'], static fn( array $write ): bool => 'templates/archive-product.html' === $write['target_path'] ) );
$archive_markup = $archives[0]['payload']['data'];
$assert( str_contains( $archive_markup, $media_url ) && str_contains( $archive_markup, $svg_url ) && ! str_contains( $archive_markup, $url ), 'grid-archive-keeps-final-media-and-materialized-svg-resolution' );

$temp = tempnam( sys_get_temp_dir(), 'ssi-archive-' );
file_put_contents( $temp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary theme fixture.
$GLOBALS['ssi_listing_theme_file'] = $temp;
Static_Site_Importer_Product_Grid_Runtime::register();
foreach ( array( array( true, false ), array( false, true ), array( false, false ) ) as $view ) {
	$GLOBALS['ssi_archive_shop']     = $view[0];
	$GLOBALS['ssi_archive_taxonomy'] = $view[1];
	$assert( ( $view[0] || $view[1] ? 73 : 19 ) === apply_filters( 'blocks_engine_document_id', 19 ), 'archive-identity-only-on-product-archives' );
	$assert( ( $view[0] || $view[1] ? 73 : 0 ) === apply_filters( 'blocks_engine_document_id', 0 ), 'archive-resolves-nonsingular-default' );
}
unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary fixture cleanup.
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic.
	exit( 1 );
}
echo "PASS: product archive document\n";
