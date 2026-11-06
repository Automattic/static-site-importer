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
if ( 'policy-link' === ( $argv[1] ?? '' ) ) {
	require dirname( __DIR__, 3 ) . '/includes/class-static-site-importer-site-plan-persistence.php';
	require ABSPATH . WPINC . '/http.php';
	function get_permalink( $id ) {
		return 'https://local.example/imported-privacy/';
	}
	$fact = json_decode( $argv[2], true );
	$state = array(
		'args' => array( 'consent_banner' => $fact, 'artifact_provenance' => array( 'source_url' => 'https://www.jccollaborative.com/' ) ),
		'ordered_pages' => array( array( 'source_path' => 'privacy.html', 'route' => array( 'path' => '/privacy' ), 'skip_materialization' => true ) ),
		'source_ids' => array( 'privacy.html' => 149 ),
	);
	Static_Site_Importer_Site_Plan_Persistence::rewrite_materialized_route_links( $state );
	echo $state['resolved']['consent_banner_markup'];
} elseif ( 'legacy-markup' === ( $argv[1] ?? '' ) ) {
	$fact = $args['consent_banner'];
	foreach ( array( 'border_width', 'border_style', 'border_color' ) as $key ) {
		unset( $fact['presentation']['button'][ $key ] );
	}
	foreach ( $fact['presentation']['layout'] as &$entry ) {
		unset( $entry['text_margin'] );
	}
	echo Static_Site_Importer_Consent_Banner::markup( $fact );
} elseif ( 'gap' === ( $argv[1] ?? '' ) ) {
	$fact = $args['consent_banner'];
	$fact['presentation'] = array( 'gap' => json_decode( $argv[2], true ) );
	echo Static_Site_Importer_Consent_Banner::markup( $fact ) . Static_Site_Importer_Consent_Banner::stylesheet( $fact );
} elseif ( 'css' === ( $argv[1] ?? '' ) ) {
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
		array( 'direction' => 'row', 'text_margin' => '0px;}body{display:none' ),
		array( 'direction' => 'row', 'text_margin' => '1px 2px 3px 4px 5px' ),
		array( 'direction' => 'row', 'text_margin' => 15 ),
	);
	foreach ( $fact['presentation']['button'] as &$value ) {
		$value = '0px;}body{display:none';
	}
	echo Static_Site_Importer_Consent_Banner::markup( $fact ) . Static_Site_Importer_Consent_Banner::stylesheet( $fact );
} else {
	echo Static_Site_Importer_Consent_Banner::markup( $args['consent_banner'] );
}
