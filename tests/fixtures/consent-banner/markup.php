<?php
/** Native serialized consent markup, using real WordPress formatting. */
// phpcs:ignoreFile -- Validation input must be emitted as raw serialized blocks.
$wp_root = require dirname( __DIR__ ) . '/wordpress-core-root.php';
define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/plugin.php';
add_filter( 'pre_option_blog_charset', static fn() => 'UTF-8' );
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/kses.php';
require dirname( __DIR__, 3 ) . '/includes/class-static-site-importer-consent-banner.php';
$bytes = file_get_contents( __DIR__ . '/consent-banner.json' );
$artifact = array( 'files' => array( array( 'path' => 'website/consent-banner.json', 'content_base64' => base64_encode( $bytes ) ), array( 'path' => 'website/index.html', 'content' => '<h1>Example</h1>' ) ) );
$args = array();
Static_Site_Importer_Consent_Banner::extract( $artifact, $args );
if ( count( $artifact['files'] ) !== 1 || ! isset( $args['consent_banner'] ) ) {
	fwrite( STDERR, 'Consent evidence must be extracted before compile.' );
	exit( 1 );
}
echo Static_Site_Importer_Consent_Banner::markup( $args['consent_banner'] );
