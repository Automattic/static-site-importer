<?php
/**
 * Run in a disposable WordPress + WooCommerce runtime with SSI inactive.
 * STATIC_SITE_IMPORTER_WP_CLI="studio wp --path /path/to/site" node tools/run-test-manifest.mjs --all
 *
 * @package StaticSiteImporter
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
	throw new RuntimeException( 'Requires WordPress and active WooCommerce.' );
}
if ( class_exists( 'Static_Site_Importer' ) || class_exists( 'Static_Site_Importer_Source_Route' ) ) {
	throw new RuntimeException( 'Run with SSI inactive (or --skip-plugins=static-site-importer).' );
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-generated-runtime-package.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$slug = 'ssi-shop-runtime-' . strtolower( wp_generate_password( 8, false, false ) );
$dir = get_theme_root() . '/' . $slug;
$old_theme = get_stylesheet();
$options = array();
foreach ( array( 'woocommerce_shop_page_id', 'permalink_structure', 'show_on_front', 'page_on_front' ) as $key ) {
	$options[ $key ] = get_option( $key );
}
$page_id = 0;
$front_id = 0;
$existing_shop = get_page_by_path( 'shop' );
try {
	$package = Static_Site_Importer_Generated_Runtime_Package::theme( array( 'schema' => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA, 'site_slug' => $slug, 'site_name' => 'Authored shop', 'blocks' => array() ) );
	$assert( ! is_wp_error( $package ), 'Could not generate theme runtime.' );
	foreach ( $package['files'] as $path => $bytes ) {
		wp_mkdir_p( dirname( $dir . '/' . $path ) );
		file_put_contents( $dir . '/' . $path, $bytes );
	}
	wp_mkdir_p( $dir . '/templates' );
	file_put_contents( $dir . '/style.css', "/* Theme Name: Authored Shop Regression */\n" );
	file_put_contents( $dir . '/theme.json', '{"version":3}' );
	file_put_contents( $dir . '/templates/index.html', '<!-- wp:post-content /-->' );
	file_put_contents( $dir . '/templates/page.html', '<!-- wp:post-content /-->' );
	wp_clean_themes_cache();
	switch_theme( $slug );
	require $dir . '/ssi-runtime/runtime.php';
	$assert( ! class_exists( 'Static_Site_Importer_Source_Route' ) && ! class_exists( 'Static_Site_Importer_Woo_Product_Listing_Materializer' ), 'Importer runtime must not be loaded.' );
	add_theme_support( 'woocommerce' );
	// WP-CLI does not initialize the frontend template loader.
	WC_Template_Loader::init();
	// Imported sites use a static front page; WooCommerce then reads any
	// `page_id` query for its shop page as a front-page shop archive.
	$front_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_name' => $slug . '-home' ) );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $front_id );
	update_option( 'permalink_structure', '/%postname%/' );
	if ( $existing_shop ) {
		wp_update_post( array( 'ID' => $existing_shop->ID, 'post_name' => $slug . '-original' ) );
	}
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Authored shop', 'post_name' => 'shop', 'post_content' => '<!-- wp:paragraph --><p>Authored shop hero and category picker</p><!-- /wp:paragraph -->' ) );
	$assert( $page_id > 0 && 'shop' === get_post_field( 'post_name', $page_id ), 'Requires an unused shop slug.' );
	// Reproduce wc_create_page adopting an existing imported document.
	delete_option( 'woocommerce_shop_page_id' );
	require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
	$adopted = wc_create_page( 'shop', 'woocommerce_shop_page_id', 'Shop' );
	$assert( $page_id === (int) $adopted, 'WooCommerce did not adopt the imported page.' );
	flush_rewrite_rules( false );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = wp_parse_url( home_url( '/shop/' ), PHP_URL_PATH );
	$_SERVER['PHP_SELF'] = '/index.php';
	foreach ( array( 'website/shop/index.html', 'shop.html' ) as $source ) {
		update_post_meta( $page_id, '_static_site_importer_source_route', $source );
		$GLOBALS['wp_the_query'] = new WP_Query();
		$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
		$GLOBALS['wp'] = new WP();
		$GLOBALS['wp']->main();
		$assert( is_page( $page_id ) && is_singular() && ! is_post_type_archive( 'product' ), 'Shop must resolve singularly: ' . $source );
		$assert( wc_get_page_id( 'shop' ) === $page_id && wc_get_page_permalink( 'shop' ) === get_permalink( $page_id ), 'Shop links must still target the imported page.' );
		// Resolve the block template the way template-loader.php does.
		$template = apply_filters( 'template_include', locate_block_template( get_page_template(), 'page', array( 'page' ) ) );
		$assert( ! str_contains( (string) $GLOBALS['_wp_current_template_id'], 'archive-product' ), 'WooCommerce selected its archive template instead of the authored page.' );
		$html = get_the_block_template_html();
		$assert( str_contains( $html, 'Authored shop hero and category picker' ), 'Authored page content was not rendered.' );
	}
	file_put_contents( $dir . '/templates/archive-product.html', '<!-- wp:paragraph --><p>Designed archive</p><!-- /wp:paragraph -->' );
	$GLOBALS['wp_the_query'] = new WP_Query();
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
	$GLOBALS['wp'] = new WP();
	$GLOBALS['wp']->main();
	$assert( is_post_type_archive( 'product' ) && ! is_singular(), 'Designed product archives must retain WooCommerce routing.' );
	$assert( wc_get_page_permalink( 'shop' ) === get_permalink( $page_id ), 'Designed archive shop links changed.' );
} finally {
	if ( ! empty( $front_id ) ) {
		wp_delete_post( $front_id, true );
	}
	if ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
	if ( $existing_shop ) {
		wp_update_post( array( 'ID' => $existing_shop->ID, 'post_name' => 'shop' ) );
	}
	foreach ( $options as $key => $value ) {
		false === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	switch_theme( $old_theme );
	flush_rewrite_rules( false );
	if ( is_dir( $dir ) ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
}
echo "OK: authored shop page runs without SSI, with shop links and designed archives preserved.\n";
