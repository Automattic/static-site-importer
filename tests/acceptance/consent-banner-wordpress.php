<?php
/** Run with wp eval-file in a disposable WordPress + Jetpack installation. */
// phpcs:ignoreFile -- Disposable integration assertions exercise raw native markup and filesystem receipts.
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-theme-generator.php';
$assert = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$root = WP_PLUGIN_DIR . '/static-site-importer/tests/fixtures/consent-banner/';
$artifact = array( 'entrypoint' => 'website/index.html', 'files' => array(
	array( 'path' => 'website/index.html', 'content' => '<!doctype html><html><head><title>Example</title></head><body><main><h1>Example home</h1><p>Welcome to the example.</p></main></body></html>' ),
	array( 'path' => 'website/about.html', 'content' => '<!doctype html><html><head><title>About</title></head><body><main><h1>About example</h1><p>Another route.</p></main></body></html>' ),
	array( 'path' => 'website/consent-banner.json', 'content' => file_get_contents( $root . 'consent-banner.json' ) ),
) );
$args = array( 'slug' => 'consent-example', 'name' => 'Consent Example', 'activate' => true, 'overwrite' => true, 'materialize_dependencies' => false );
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
$assert( ! is_wp_error( $compiled ), 'Fixture compiles.' );
$assert( isset( $compiled['args']['consent_banner']['accept_label'] ), 'Consent fact survives compilation as intent, not content.' );
$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
$assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Import succeeds.' );
$receipt = $result['materialization_receipt'] ?? $result['receipt'] ?? array();
$assert( 'completed' === ( $receipt['status'] ?? '' ), 'Import completes.' );
foreach ( $receipt['completed']['pages'] ?? array() as $id ) {
	$assert( ! str_contains( get_post_field( 'post_content', $id ), 'cookie-consent' ), 'No consent markup in page content.' );
}
$available = WP_Block_Type_Registry::get_instance()->is_registered( 'jetpack/cookie-consent' );
if ( $available ) {
	$markup = get_option( 'cookie_consent_template' );
	$assert( is_string( $markup ) && str_contains( $markup, 'Accept cookies' ), 'Site-wide option written.' );
	file_put_contents( '/tmp/consent-markup.html', $markup );
	ob_start();
	Automattic\Jetpack\Extensions\CookieConsent\render_cookie_consent_template();
	$banner = ob_get_clean();
	$assert( str_contains( $banner, 'Accept cookies' ), 'Actual Jetpack footer renders option.' );
	$_COOKIE['eucookielaw'] = 'accepted';
	ob_start();
	Automattic\Jetpack\Extensions\CookieConsent\render_cookie_consent_template();
	$assert( '' === ob_get_clean(), 'Actual Jetpack suppresses consented visitor.' );
	unset( $_COOKIE['eucookielaw'] );
	add_filter( 'jetpack_disable_cookie_consent_block', '__return_true' );
	ob_start();
	Automattic\Jetpack\Extensions\CookieConsent\render_cookie_consent_template();
	$assert( '' === ob_get_clean(), 'Actual Jetpack respects CMP.' );
	remove_filter( 'jetpack_disable_cookie_consent_block', '__return_true' );
} else {
	$assert( false === get_option( 'cookie_consent_template', false ), 'No option without runtime.' );
	$assert( 'consent_banner_not_materialized' === ( $receipt['completed']['consent_banner']['findings'][0]['code'] ?? '' ), 'One missing-runtime finding.' );
}
// Verify the real persistence journal restores absence, independently of earlier imports.
delete_option( 'cookie_consent_template' );
$prepared = $compiled['args'];
$prepared['activate'] = true;
$prepared['overwrite'] = true;
$prepared['defer_materialization_commit'] = true;
$deferred = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $prepared );
$assert( ! is_wp_error( $deferred ), 'Deferred materialization succeeds.' );
Static_Site_Importer_WordPress_Site_Plan_Materializer::rollback_receipt( $deferred, 'consent_acceptance' );
$assert( false === get_option( 'cookie_consent_template', false ), 'Rollback removes newly created option.' );
// Leave the site imported for the browser acceptance.
Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
echo "Consent import passed.\n";
