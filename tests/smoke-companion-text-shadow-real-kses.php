<?php
/**
 * Companion text shadows survive real core KSES with the WordPress 7.1 allow-list.
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

// Core 7.2 added text-shadow; reproduce 7.1 before the companion's priority-10 extension.
add_filter(
	'safe_style_css',
	static fn( array $properties ): array => array_values( array_diff( $properties, array( 'text-shadow' ) ) ),
	9
);

$method = new ReflectionMethod( Static_Site_Importer_Companion_Plugin::class, 'editable_content_renderer' );
$method->setAccessible( true );
$renderer   = $method->invoke( null );
$attributes = array( 'content' => '<div style="text-shadow: rgb(0, 0, 0) 2px 2px 1px; transform: translate(0px, 0px)">x</div>' );
$block      = null;
ob_start();
eval( '?>' . $renderer ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Exercise the generated companion render template.
$output = (string) ob_get_clean();
if ( ! preg_match( '/style="[^"]*text-shadow: #000000 2px 2px 1px[^"]*"/', $output ) ) {
	fwrite( STDERR, 'FAIL [text-shadow-survives-7.1-kses]: ' . $output . "\n" );
	exit( 1 );
}
echo 'PASS [text-shadow-survives-7.1-kses]: ' . $output . "\n";
