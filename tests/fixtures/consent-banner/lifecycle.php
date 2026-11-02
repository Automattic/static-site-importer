<?php
/** Consent intent must enter the ordinary dependency plan without Jetpack. */
// phpcs:ignoreFile -- Standalone contract assertion.
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
function get_option( $name, $default = false ) { return $default; }
require ABSPATH . 'includes/class-static-site-importer-consent-banner.php';
require ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require ABSPATH . 'includes/class-static-site-importer-dependency-manager.php';
$args = array( 'consent_banner' => array( 'text' => 'Cookies', 'accept_label' => 'Accept' ), 'runtime_lifecycle_phase' => 'plan' );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( array(), $args );
$plan = Static_Site_Importer_Dependency_Manager::dependency_plan( $lifecycle, 'fixture' );
if ( 'jetpack' !== ( $plan['entries'][0]['slug'] ?? '' ) || array( 'jetpack/cookie-consent' ) !== ( $plan['entries'][0]['provider_readiness']['required_block_types'] ?? array() ) ) {
	throw new RuntimeException( 'Consent fact must plan the native Jetpack block dependency.' );
}
echo "Consent dependency planned.\n";
