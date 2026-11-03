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
$bytes = file_get_contents( __DIR__ . ( isset( $argv[1] ) ? '/presentation.json' : '/consent-banner.json' ) );
$artifact = array( 'files' => array( array( 'path' => 'website/consent-banner.json', 'content_base64' => base64_encode( $bytes ) ), array( 'path' => 'website/index.html', 'content' => '<h1>Example</h1>' ) ) );
$args = array();
Static_Site_Importer_Consent_Banner::extract( $artifact, $args );
if ( count( $artifact['files'] ) !== 1 || ! isset( $args['consent_banner'] ) ) {
	fwrite( STDERR, 'Consent evidence must be extracted before compile.' );
	exit( 1 );
}
if ( 'css' === ( $argv[1] ?? '' ) ) {
	echo Static_Site_Importer_Consent_Banner::stylesheet( $args['consent_banner'] );
} elseif ( 'top' === ( $argv[1] ?? '' ) ) {
	$fact = $args['consent_banner'];
	$fact['position'] = 'top';
	echo Static_Site_Importer_Consent_Banner::stylesheet( $fact );
} elseif ( 'legacy' === ( $argv[1] ?? '' ) ) {
	$fact = $args['consent_banner'];
	unset( $fact['presentation']['text_margin'], $fact['presentation']['layout'], $fact['presentation']['button']['line_height'] );
	echo Static_Site_Importer_Consent_Banner::stylesheet( $fact );
} elseif ( 'hostile' === ( $argv[1] ?? '' ) ) {
	$fact = $args['consent_banner'];
	foreach ( array( 'edge_offset', 'padding', 'font_family', 'font_size', 'line_height', 'text_margin' ) as $key ) {
		$fact['presentation'][ $key ] = '0px;}body{display:none';
	}
	$fact['presentation']['layout'] = array(
		array( 'direction' => 'row;}body{display:none' ),
		array( 'direction' => 'row', 'min_width' => '768' ),
		array( 'direction' => 'row', 'max_width' => -1 ),
		array( 'direction' => 'row', 'align_items' => 'center;display:none' ),
		array( 'direction' => 'column', 'button_align' => 'end;display:none' ),
	);
	foreach ( $fact['presentation']['button'] as &$value ) {
		$value = '0px;}body{display:none';
	}
	echo Static_Site_Importer_Consent_Banner::markup( $fact ) . Static_Site_Importer_Consent_Banner::stylesheet( $fact );
} else {
	echo Static_Site_Importer_Consent_Banner::markup( $args['consent_banner'] );
}
