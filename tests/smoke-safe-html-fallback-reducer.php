<?php
/**
 * Smoke coverage for reducing safe static HTML fallback blocks.
 *
 * Run from the repository root:
 * php tests/smoke-safe-html-fallback-reducer.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value ) {
		unset( $hook_name );
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
if ( ! is_readable( $parser ) || ! is_readable( $blocks ) ) {
	fwrite( STDERR, "SKIP: WordPress parser/serializer files are unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 0 );
}

require_once $parser;
require_once $blocks;
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$count_blocks = static function ( string $markup ): array {
	$counts = array();
	$walk   = static function ( array $blocks ) use ( &$walk, &$counts ): void {
		foreach ( $blocks as $block ) {
			$name            = is_array( $block ) && isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : 'unparsed_html';
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			if ( is_array( $block ) && isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $markup ) );

	return $counts;
};

$input = implode(
	'',
	array(
		'<!-- wp:group {"className":"outer"} --><div class="wp-block-group outer">',
		'<!-- wp:html {"content":""} --><!-- /wp:html -->',
		'<!-- wp:html {"content":"<section class=\"hero\"><h2 class=\"title\">Care <em>that works</em></h2><p class=\"lede\">Book today</p><img class=\"photo\" src=\"assets/hero.jpg\" alt=\"Clinic room\"><ul class=\"ticks\"><li>Assessment</li><li>Treatment</li></ul><a class=\"cta\" href=\"/book/\">Reserve</a></section>"} --><section class="hero"><h2 class="title">Care <em>that works</em></h2><p class="lede">Book today</p><img class="photo" src="assets/hero.jpg" alt="Clinic room"><ul class="ticks"><li>Assessment</li><li>Treatment</li></ul><a class="cta" href="/book/">Reserve</a></section><!-- /wp:html -->',
		'<!-- wp:html {"content":"<figure class=\"featured\"><img src=\"assets/post.jpg\" alt=\"Post image\"><figcaption>Read the <em>story</em></figcaption></figure>"} --><figure class="featured"><img src="assets/post.jpg" alt="Post image"><figcaption>Read the <em>story</em></figcaption></figure><!-- /wp:html -->',
		'<!-- wp:html {"content":"<nav class=\"primary-nav\"><ul><li><a href=\"/\">Home</a></li><li><a href=\"/archive/\">Archive</a></li></ul></nav>"} --><nav class="primary-nav"><ul><li><a href="/">Home</a></li><li><a href="/archive/">Archive</a></li></ul></nav><!-- /wp:html -->',
		'<!-- wp:html {"content":"<section class=\"posts-grid\"><article class=\"post-card\"><img src=\"one.jpg\" alt=\"One\"><h2>One</h2><p>Excerpt one</p></article><article class=\"post-card\"><img src=\"two.jpg\" alt=\"Two\"><h2>Two</h2><p>Excerpt two</p></article></section>"} --><section class="posts-grid"><article class="post-card"><img src="one.jpg" alt="One"><h2>One</h2><p>Excerpt one</p></article><article class="post-card"><img src="two.jpg" alt="Two"><h2>Two</h2><p>Excerpt two</p></article></section><!-- /wp:html -->',
		'<!-- wp:html {"content":"<form class=\"search-form\" role=\"search\" method=\"get\"><input type=\"search\" name=\"s\" placeholder=\"Search posts\"><button type=\"submit\">Find</button></form>"} --><form class="search-form" role="search" method="get"><input type="search" name="s" placeholder="Search posts"><button type="submit">Find</button></form><!-- /wp:html -->',
		'<!-- wp:html {"content":"<form class=\"lead-form\"><input name=\"email\"></form>"} --><form class="lead-form"><input name="email"></form><!-- /wp:html -->',
		'</div><!-- /wp:group -->',
	)
);

$reflection = new ReflectionClass( Static_Site_Importer_Page_Materializer::class );
$method     = $reflection->getMethod( 'reduce_safe_html_fallback_blocks' );
$output     = $method->invoke( null, $input );

$before = $count_blocks( $input );
$after  = $count_blocks( $output );

$assert( 7 === ( $before['core/html'] ?? 0 ), 'before-has-seven-html-fallbacks' );
$assert( 1 === ( $after['core/html'] ?? 0 ), 'after-keeps-only-unsupported-form-fallback', print_r( $after, true ) );
$assert( 3 === ( $after['core/group'] ?? 0 ), 'existing-section-and-query-card-groups-preserved', print_r( $after, true ) );
$assert( 1 === ( $after['core/heading'] ?? 0 ), 'heading-converted' );
$assert( 1 === ( $after['core/paragraph'] ?? 0 ), 'paragraph-converted' );
$assert( 2 === ( $after['core/image'] ?? 0 ), 'images-and-captioned-figure-converted' );
$assert( 1 === ( $after['core/list'] ?? 0 ), 'list-converted' );
$assert( 2 === ( $after['core/list-item'] ?? 0 ), 'list-items-converted' );
$assert( 1 === ( $after['core/buttons'] ?? 0 ), 'button-wrapper-converted' );
$assert( 1 === ( $after['core/button'] ?? 0 ), 'button-converted' );
$assert( 1 === ( $after['core/navigation'] ?? 0 ), 'navigation-converted' );
$assert( 2 === ( $after['core/navigation-link'] ?? 0 ), 'navigation-links-converted' );
$assert( 1 === ( $after['core/query'] ?? 0 ), 'query-grid-converted' );
$assert( 1 === ( $after['core/post-template'] ?? 0 ), 'post-template-converted' );
$assert( 1 === ( $after['core/post-featured-image'] ?? 0 ), 'post-featured-image-converted' );
$assert( 1 === ( $after['core/post-title'] ?? 0 ), 'post-title-converted' );
$assert( 1 === ( $after['core/post-excerpt'] ?? 0 ), 'post-excerpt-converted' );
$assert( 1 === ( $after['core/search'] ?? 0 ), 'search-form-converted' );
$assert( str_contains( $output, 'className":"hero' ), 'section-class-preserved' );
$assert( str_contains( $output, 'className":"title' ), 'heading-class-preserved' );
$assert( str_contains( $output, 'assets/hero.jpg' ), 'image-src-preserved' );
$assert( str_contains( $output, 'Clinic room' ), 'image-alt-preserved' );
$assert( str_contains( $output, '/book/' ), 'button-url-preserved' );
$assert( str_contains( $output, 'Read the <em>story</em>' ), 'figcaption-preserved' );
$assert( str_contains( $output, 'primary-nav' ), 'nav-class-preserved' );
$assert( str_contains( $output, 'Search posts' ), 'search-placeholder-preserved' );
$assert( str_contains( $output, '<form class="lead-form"><input name="email"></form>' ), 'unsupported-form-fallback-preserved' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: safe HTML fallback reducer smoke passed (' . $assertions . " assertions)\n";
