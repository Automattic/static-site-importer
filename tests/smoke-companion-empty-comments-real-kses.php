<?php
/**
 * Issue #2864: framework placeholders must not reparent rendered companion HTML.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone regression uses real Core sources and generated PHP.
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/plugin.php';
add_filter( 'pre_option_blog_charset', static fn() => 'UTF-8' );
foreach ( array( 'compat.php', 'utf8.php', 'formatting.php', 'functions.php', 'kses.php', 'class-wp-error.php', 'load.php' ) as $file ) {
	require ABSPATH . WPINC . '/' . $file;
}
require __DIR__ . '/fixtures/core-html-api.php';
require __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
// This default Core hook is essential: KSES alone does not reproduce the bug.
add_filter( 'pre_kses', 'wp_pre_kses_block_attributes', 10, 3 );
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$source = file_get_contents( __DIR__ . '/fixtures/companion-empty-comments/index.html' );
// The issue's sizes-bearing logo is stored in a responsive-media content attribute.
preg_match( '#<a href="/" class="logo-link">.*?</a>#s', $source, $match );
$content = $match[0];
$payload = array(
	'schema' => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug' => 'comment-regression',
	'blocks' => array( array(
		'name' => 'responsive-media',
		'renderer' => 'blocks-engine/responsive-media/v1',
		'block_json' => array(
			'apiVersion' => 3,
			'name' => 'ssi-comment-regression/responsive-media',
			'title' => 'Responsive media',
			'attributes' => array( 'content' => array( 'type' => 'string' ) ),
		),
	) ),
);
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$assert( ! is_wp_error( $descriptor ), 'Public companion scaffold must succeed.' );
$renderer = $descriptor['files']['ssi-comment-regression/blocks/responsive-media/render.php'];
// Round-trip the stored attribute through Core's real block serializer/parser.
$markup = serialize_block( array(
	'blockName' => 'ssi-comment-regression/responsive-media',
	'attrs' => array( 'content' => $content ),
	'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
) );
$parsed = parse_blocks( $markup );
$attributes = $parsed[0]['attrs'];
$block = null;
ob_start();
eval( '?>' . $renderer );
$output = (string) ob_get_clean();
$rendered = str_replace( $content, $output, $source );
$document = new DOMDocument();
$previous = libxml_use_internal_errors( true );
$document->loadHTML( $rendered, LIBXML_NONET );
libxml_clear_errors();
libxml_use_internal_errors( $previous );
$xpath = new DOMXPath( $document );
$assert( 1 === $xpath->query( '//header/div[@class="nav-row"]' )->length, 'Navigation must remain a header child, outside the logo row.' );
$assert( 1 === $xpath->query( '//div[@class="top"]/div[@class="side icons"]' )->length, 'Icons must keep their original parent, not move inside the logo.' );
$assert( 0 === $xpath->query( '//div[@class="logo"]//div[@class="side icons"]' )->length, 'Logo must not adopt later siblings.' );
$assert( ! str_contains( $output, '<!--&gt;' ), 'Rendered markup must contain no corrupted comment opener.' );
echo "PASS: companion empty comments preserve reduced-page DOM structure\n";
