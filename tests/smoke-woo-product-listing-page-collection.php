<?php
/**
 * Regression coverage for listing page and archive collection queries.
 *
 * @package StaticSiteImporter
 */

// Reuse the listing lifecycle, bindings and prepared plan from the archive harness.
require __DIR__ . '/smoke-woo-product-listing-archive.php';

$page_markup = (string) ( $prepared['pages'][0]['materialized_block_markup'] ?? '' );
$assert( str_contains( $page_markup, '"inherit":false' ), 'page-does-not-inherit-the-page-query' );
$assert( str_contains( $page_markup, '"woocommerceHandPickedProducts":[21,22]' ) && str_contains( $page_markup, '"orderBy":"post__in"' ), 'page-products-follow-source-order' );
$page_blocks = parse_blocks( $page_markup );
$collection  = $page_blocks[1]['attrs'] ?? array();
$assert( 'woocommerce/product-collection/hand-picked' === ( $collection['collection'] ?? null ) && 2 === ( $collection['query']['perPage'] ?? null ) && 1 === ( $collection['query']['pages'] ?? null ), 'page-uses-one-hand-picked-collection' );
$assert( str_contains( $page_markup, $category ) && str_contains( $page_markup, $sort ) && str_contains( $page_markup, $count ), 'page-keeps-source-controls' );
$assert( str_contains( $data, '"inherit":true' ) && ! str_contains( $data, 'woocommerceHandPickedProducts' ), 'archive-keeps-inheriting-query' );

// Empty seeded ids preserve the previous archive-style page fallback.
$missing_grid                  = $owned;
$missing_grid['grid']          = $grid;
$missing_grid['grid']['items'] = array( array( 'product' => 'not-seeded' ) );
$fallback                      = Static_Site_Importer_Woo_Product_Listing_Materializer::binding_block_markup( $missing_grid, array( 'status' => 'mapped' ) );
$assert( Static_Site_Importer_Woo_Product_Listing_Materializer::archive_block_markup( $missing_grid ) === $fallback, 'empty-ids-keep-archive-behavior' );

// Both replacement fragments must be valid block documents.
foreach ( array( null, '', '<p>not a block</p>' ) as $invalid ) {
	$bad_bindings                                        = $bindings;
	$bad_bindings[0]['archive_replacement_block_markup'] = $invalid;
	$bad_plan        = $plan;
	$bad_reports     = array();
	$bad_diagnostics = array();
	$rejected        = false;
	try {
		Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $bad_plan, $bad_bindings, $bad_reports, $bad_diagnostics );
	} catch ( InvalidArgumentException $error ) {
		$rejected = in_array( $error->getMessage(), array( 'runtime_entity_binding_invalid', 'runtime_entity_binding_replacement_invalid' ), true );
	}
	$assert( $rejected, 'invalid-archive-fragment-is-rejected' );
}

if ( array() !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI failure report.
	exit( 1 );
}
echo "Woo product listing page collection smoke passed.\n";
