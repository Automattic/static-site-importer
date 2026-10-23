<?php
/**
 * Source-only counterpart to the live WooCommerce authored-shop routing smoke.
 * Checks the portable archive identity boundary, not WooCommerce's URL router.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion failures, not browser output.
// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar -- Machine-readable error classifications.

require __DIR__ . '/smoke-woo-product-archive-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-generated-runtime-package.php';

// Core's untranslated domain bypasses the database-backed translation registry.
require_once ABSPATH . WPINC . '/pomo/translations.php';
$GLOBALS['l10n']['static-site-importer'] = new NOOP_Translations(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Source-only translation fixture.

$package = Static_Site_Importer_Generated_Runtime_Package::theme(
	array(
		'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
		'site_slug' => 'authored-shop-portable',
		'site_name' => 'Authored shop',
		'blocks'    => array(),
	)
);
if ( is_wp_error( $package ) ) {
	// ssi-error-class: integrity
	throw new RuntimeException( $package->get_error_message() );
}
$grid_file = $package['files']['ssi-runtime/includes/product-grid-runtime.php'];
$assert( str_contains( $grid_file, "add_filter( 'blocks_engine_document_id'" ), 'theme-ships-archive-document-filter' );
$assert( str_contains( $package['files']['ssi-runtime/runtime.php'], "'/includes/product-grid-runtime.php'" ), 'theme-loads-product-grid-runtime' );

// An authored shop without a designed archive must not acquire an archive
// identity, even when WooCommerce identifies the request as its shop page.
$GLOBALS['ssi_listing_theme_file'] = '';
$GLOBALS['ssi_archive_shop']       = true;
$GLOBALS['ssi_archive_taxonomy']   = false;
$assert( 73 === apply_filters( 'blocks_engine_document_id', 73 ), 'authored-shop-keeps-singular-document' );
$assert( 0 === apply_filters( 'blocks_engine_document_id', 0 ), 'missing-archive-keeps-default-document' );
if ( $failures ) {
	// ssi-error-class: integrity
	throw new RuntimeException( implode( "\n", $failures ) );
}
echo "PASS: portable authored shop document boundary (live routing requires wp eval-file)\n";
