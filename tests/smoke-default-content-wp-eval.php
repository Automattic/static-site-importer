<?php
/**
 * Disposable WordPress regression for the core privacy-policy seed.
 *
 * Run in a freshly installed WordPress site with SSI loaded:
 * wp eval-file /path/to/static-site-importer/tests/smoke-default-content-wp-eval.php
 *
 * @package StaticSiteImporter
 */

if ( ! class_exists( 'Static_Site_Importer_Default_Content' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-default-content.php';
}

$policy_id = (int) get_option( 'wp_page_for_privacy_policy' );
$policy    = get_post( $policy_id );
$discovery = Static_Site_Importer_Default_Content::discover();

if ( 3 !== $policy_id || ! $policy instanceof WP_Post || ! Static_Site_Importer_Default_Content::is_untouched_seed( $discovery, $policy ) ) {
	throw new RuntimeException( 'Fresh WordPress privacy-policy seed was not recognized as an untouched core seed.' );
}

echo "Default privacy-policy seed discovery passed.\n";
