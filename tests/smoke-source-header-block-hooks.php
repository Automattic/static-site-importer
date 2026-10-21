<?php
/**
 * Run in a disposable WordPress runtime with WooCommerce active and SSI inactive.
 *
 * @package StaticSiteImporter
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wc_set_hooked_blocks_version_on_theme_switch' ) ) {
	throw new RuntimeException( 'Requires WordPress and active WooCommerce.' );
}
if ( class_exists( 'Static_Site_Importer' ) ) {
	throw new RuntimeException( 'Run with SSI inactive.' );
}
// WooCommerce caches this option per request; theme switches here simulate fresh requests.
if ( ! defined( 'WP_RUN_CORE_TESTS' ) ) {
	define( 'WP_RUN_CORE_TESTS', true );
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-generated-runtime-package.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$slug = 'ssi-header-' . strtolower( wp_generate_password( 8, false, false ) );
$dir = get_theme_root() . '/' . $slug;
$old_theme = wp_get_theme();
$option = 'woocommerce_hooked_blocks_version';
$previous = get_option( $option );
try {
	$package = Static_Site_Importer_Generated_Runtime_Package::theme( array( 'schema' => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA, 'site_slug' => $slug, 'site_name' => 'Source header', 'blocks' => array() ) );
	$assert( ! is_wp_error( $package ), 'Could not generate theme runtime.' );
	foreach ( $package['files'] as $path => $bytes ) {
		wp_mkdir_p( dirname( $dir . '/' . $path ) );
		file_put_contents( $dir . '/' . $path, $bytes );
	}
	wp_mkdir_p( $dir . '/templates' );
	wp_mkdir_p( $dir . '/parts' );
	file_put_contents( $dir . '/style.css', "/* Theme Name: Source Header Regression */\n" );
	file_put_contents( $dir . '/theme.json', '{"version":3,"templateParts":[{"name":"header","area":"header","title":"Header"}]}' );
	file_put_contents( $dir . '/templates/index.html', '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
	// Reduced source-authored header: navigation, but no commerce controls.
	file_put_contents( $dir . '/parts/header.html', '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation {"overlayMenu":"never"} --><!-- wp:navigation-link {"label":"Source home","url":"/"} /--><!-- /wp:navigation --></div><!-- /wp:group -->' );
	wp_clean_themes_cache();
	switch_theme( $slug );
	require $dir . '/ssi-runtime/runtime.php';
	// Exercise both activation initialization and an already-enabled store.
	foreach ( array( WC()->stable_version(), false ) as $version ) {
		delete_option( $option );
		if ( false !== $version ) {
			update_option( $option, $version );
		}
		wc_set_hooked_blocks_version();
		// An absent theme has no block templates, exercising WC's classic-to-block branch.
		$classic_theme = new WP_Theme( 'source-classic-regression', get_theme_root() );
		wc_set_hooked_blocks_version_on_theme_switch( 'Classic source', $classic_theme );
		$html = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
		$assert( str_contains( $html, 'Source home' ), 'Source navigation must remain rendered.' );
		$assert( ! str_contains( $html, 'wp-block-woocommerce-customer-account' ), 'WooCommerce injected a customer account into the source header.' );
		$assert( ! str_contains( $html, 'wp-block-woocommerce-mini-cart' ), 'WooCommerce injected a mini-cart into the source header.' );
		$assert( 'no' === get_option( $option ), 'Opt-out must survive WooCommerce activation handling.' );
	}
	switch_theme( $old_theme->get_stylesheet() );
	$assert( false === get_option( $option ), 'Other themes must retain the underlying WooCommerce setting.' );
} finally {
	switch_theme( $old_theme->get_stylesheet() );
	delete_option( $option );
	if ( false !== $previous ) {
		update_option( $option, $previous );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	$GLOBALS['wp_filesystem']->delete( $dir, true );
	wp_clean_themes_cache();
}
echo "Source header block hooks runtime smoke passed.\n";
