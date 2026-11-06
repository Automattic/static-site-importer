<?php
/** Exercise consent option storage through the real plan persistence lifecycle. */
// phpcs:ignoreFile -- Standalone fixture uses the shared in-memory WordPress harness.
define( 'SSI_PLAN_HARNESS_ONLY', true );
require dirname( __DIR__, 2 ) . '/smoke-wordpress-site-plan-materializer.php';
foreach ( array( 'jetpack/cookie-consent', 'core/heading', 'core/group' ) as $block ) {
	WP_Block_Type_Registry::get_instance()->register( $block, array() );
}
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
$GLOBALS['ssi_plan_rollback_events'] = array();
function stored_consent_markup(): string {
	$writes = array_keys( $GLOBALS['ssi_plan_rollback_events'], 'option:cookie_consent_template', true );
	if ( 1 !== count( $writes ) ) {
		throw new RuntimeException( 'Consent template must be written exactly once.' );
	}
	return (string) get_option( 'cookie_consent_template' );
}
$fact = json_decode( $argv[1], true );
$artifact = array(
	'entrypoint' => 'index.html',
	'provenance' => array( 'source_url' => 'https://www.jccollaborative.com/' ),
	'files' => array(
		'index.html' => '<main><h1>Home</h1></main>',
		'privacy/index.html' => '<main><h1>Privacy</h1></main>',
	),
);
$plan = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $artifact )->toArray()['source_reports']['wordpress_site_plan'];
if ( 'before-resolution' === ( $argv[2] ?? '' ) ) {
	$GLOBALS['ssi_plan_posts'][149] = array( 'post_name' => 'privacy', 'post_type' => 'page' );
	$state = array(
		'args' => array( 'consent_banner' => $fact ),
		'plan' => $plan,
		'ordered_pages' => array( array( 'source_path' => 'privacy/index.html', 'route' => array( 'path' => '/privacy/' ) ) ),
		'source_ids' => array( 'privacy/index.html' => 149 ),
	);
	Static_Site_Importer_Consent_Banner::materialize( $state );
	if ( get_option( 'cookie_consent_template' ) !== $state['resolved']['consent_banner_markup'] ) {
		throw new RuntimeException( 'Consent option must use resolved markup.' );
	}
	echo stored_consent_markup();
	return;
}
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'consent-policy', 'activate' => true, 'consent_banner' => $fact ) );
if ( 'completed' !== $receipt['status'] ) {
	throw new RuntimeException( json_encode( array( $receipt['status'], $receipt['reason'] ?? '', $receipt['diagnostics'] ?? array() ) ) );
}
echo stored_consent_markup();
