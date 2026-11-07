<?php
/**
 * Companion text styles survive real core KSES with the WordPress 7.1 allow-list.
 *
 * @package StaticSiteImporter
 */

$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/plugin.php';
add_filter( 'pre_option_blog_charset', static fn() => 'UTF-8' );
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/kses.php';
require __DIR__ . '/fixtures/core-html-api.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';

$cases = array(
	'text-shadow'               => array( 'rgb(0, 0, 0) 2px 2px 1px', '#000000 2px 2px 1px' ),
	'text-decoration-line'      => array( 'none', 'none' ),
	'text-decoration-style'     => array( 'initial', 'initial' ),
	'text-decoration-thickness' => array( 'initial', 'initial' ),
	'font-kerning'              => array( 'none', 'none' ),
);

// Reproduce 7.1 before the companion's priority-10 extension (7.2 added text-shadow).
add_filter(
	'safe_style_css',
	static fn( array $properties ): array => array_values( array_diff( $properties, array_keys( $cases ) ) ),
	9
);

$method = new ReflectionMethod( Static_Site_Importer_Companion_Plugin::class, 'editable_content_renderer' );
$method->setAccessible( true );
$renderer = $method->invoke( null );
$block    = null;
$failed   = false;
foreach ( $cases as $property => list( $value, $expected ) ) {
	$attributes = array( 'content' => '<a href="/" style="' . $property . ': ' . $value . '; transform: translate(0px, 0px)">x</a>' );
	ob_start();
	eval( '?>' . $renderer ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Exercise the generated companion render template.
	$output = (string) ob_get_clean();
	if ( ! preg_match( '/style="[^"]*' . preg_quote( $property . ': ' . $expected, '/' ) . '(?=;|")/', $output ) ) {
		fwrite( STDERR, 'FAIL [' . $property . '-survives-7.1-kses]: ' . $output . "\n" );
		$failed = true;
		continue;
	}
	echo 'PASS [' . $property . '-survives-7.1-kses]: ' . $output . "\n";
}
exit( $failed ? 1 : 0 );
