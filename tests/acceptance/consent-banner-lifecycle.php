<?php
/** Run in separate wp eval-file requests: plan, prepare, resume. Starts without Jetpack. */
// phpcs:ignoreFile -- Disposable WordPress integration assertions.
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-theme-generator.php';
$phase = $args[0] ?? 'plan';
$artifact = array( 'entrypoint' => 'website/index.html', 'files' => array(
	array( 'path' => 'website/index.html', 'content' => '<!doctype html><html><head><title>Consent</title></head><body><main><h1>Consent example</h1><p>Welcome.</p></main></body></html>' ),
	array( 'path' => 'website/consent-banner.json', 'content' => file_get_contents( WP_PLUGIN_DIR . '/static-site-importer/tests/fixtures/consent-banner/consent-banner.json' ) ),
) );
$options = array( 'slug' => 'consent-lifecycle', 'name' => 'Consent lifecycle', 'activate' => true, 'overwrite' => true, 'materialize_dependencies' => true, 'runtime_lifecycle_phase' => $phase, 'runtime_lifecycle_invocation_id' => $phase );
if ( 'resume' === $phase ) {
	$options['runtime_lifecycle_request_id'] = 'prepare';
	$options['runtime_lifecycle_checkpoint'] = get_option( 'ssi_consent_checkpoint' );
}
$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $options );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) );
}
if ( 'plan' === $phase ) {
	$entries = $result['entries'] ?? array();
	if ( 'jetpack' !== ( $entries[0]['slug'] ?? '' ) ) {
		throw new RuntimeException( 'Consent fact must plan Jetpack before provisioning: ' . wp_json_encode( $result ) );
	}
} elseif ( 'prepare' === $phase ) {
	update_option( 'ssi_consent_checkpoint', $result['runtime_lifecycle_checkpoint'] );
} else {
	if ( ! str_contains( (string) get_option( 'cookie_consent_template' ), 'Accept cookies' ) ) {
		throw new RuntimeException( 'Fresh runtime must materialize native consent option.' );
	}
}
echo "Consent lifecycle $phase passed.\n";
