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
$url                                = 'https://example.test/theme/assets/logo.png';
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
