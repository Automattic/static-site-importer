<?php
/**
 * Reduced regression: an authored shop document must not become an undesigned archive.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['shop_filters'] = array();
$GLOBALS['shop_source']  = 'website/shop/index.html';
$GLOBALS['shop_design']  = false;
function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['shop_filters'][ $hook ] = $callback;
}
function get_post_meta( int $id, string $key, bool $single = false ): mixed {
	return 6 === $id && '_static_site_importer_source_route' === $key ? $GLOBALS['shop_source'] : '';
}
function get_theme_file_path( string $path ): string {
	return $GLOBALS['shop_design'] ? __FILE__ : __DIR__ . '/nonexistent/' . $path;
}
require dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route-redirect.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-listing-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
Static_Site_Importer_Woo_Product_Listing_Materializer::register();
$filter = $GLOBALS['shop_filters']['option_woocommerce_shop_page_id'] ?? static fn( $id ) => $id;

// WooCommerce's wc_get_page_id() reads this option before reclassifying the page query.
foreach ( array( 'website/shop/index.html', 'shop.html', 'catalog/index.html' ) as $source ) {
	$GLOBALS['shop_source'] = $source;
	$assert( -1 === $filter( 6 ), 'An imported authored page without an archive design remains singular: ' . $source );
}
$assert( 7 === $filter( 7 ), 'An unrelated native shop keeps its configured page.' );
$assert( -1 === $filter( -1 ), 'An unset shop stays unset.' );
$GLOBALS['shop_design'] = true;
$assert( 6 === $filter( 6 ), 'A designed product-listing archive keeps its shop page and inherited product query.' );
$GLOBALS['shop_design'] = false;
$GLOBALS['shop_source'] = '';
$assert( 6 === $filter( 6 ), 'A native shop without imported source content keeps WooCommerce defaults.' );
echo "Authored shop page smoke passed (7 assertions).\n";
