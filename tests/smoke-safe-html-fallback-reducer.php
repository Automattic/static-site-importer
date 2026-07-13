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
		'<!-- wp:html {"content":"<section class=\"hero\"><h2 class=\"title\">Care <em>that works</em></h2><p class=\"lede\">Book today</p><img class=\"photo\" src=\"assets/hero.jpg\" alt=\"Clinic room\"><nav class=\"site-nav\"><ul><li><a href=\"#treatments\">Treatments</a></li><li><a href=\"#pricing\">Pricing</a></li><li>Contact</li></ul></nav><ul class=\"ticks\"><li>Assessment</li><li>Treatment</li></ul><a class=\"cta\" href=\"/book/\">Reserve</a></section>"} --><section class="hero"><h2 class="title">Care <em>that works</em></h2><p class="lede">Book today</p><img class="photo" src="assets/hero.jpg" alt="Clinic room"><nav class="site-nav"><ul><li><a href="#treatments">Treatments</a></li><li><a href="#pricing">Pricing</a></li><li>Contact</li></ul></nav><ul class="ticks"><li>Assessment</li><li>Treatment</li></ul><a class="cta" href="/book/">Reserve</a></section><!-- /wp:html -->',
		'<!-- wp:html {"content":"<section class=\"nav-bar\"><ul><li>Services</li><li>Prices</li></ul></section>"} --><section class="nav-bar"><ul><li>Services</li><li>Prices</li></ul></section><!-- /wp:html -->',
		'<!-- wp:html {"content":"<figure class=\"featured\"><img src=\"assets/post.jpg\" alt=\"Post image\"><figcaption>Read the <em>story</em></figcaption></figure>"} --><figure class="featured"><img src="assets/post.jpg" alt="Post image"><figcaption>Read the <em>story</em></figcaption></figure><!-- /wp:html -->',
		'<!-- wp:html {"content":"<nav class=\"primary-nav\"><ul><li><a href=\"/\">Home</a></li><li><a href=\"/archive/\">Archive</a></li></ul></nav>"} --><nav class="primary-nav"><ul><li><a href="/">Home</a></li><li><a href="/archive/">Archive</a></li></ul></nav><!-- /wp:html -->',
		'<!-- wp:html {"content":"<section class=\"posts-grid\"><article class=\"post-card\"><img src=\"one.jpg\" alt=\"One\"><h2>One</h2><p>Excerpt one</p></article><article class=\"post-card\"><img src=\"two.jpg\" alt=\"Two\"><h2>Two</h2><p>Excerpt two</p></article></section>"} --><section class="posts-grid"><article class="post-card"><img src="one.jpg" alt="One"><h2>One</h2><p>Excerpt one</p></article><article class="post-card"><img src="two.jpg" alt="Two"><h2>Two</h2><p>Excerpt two</p></article></section><!-- /wp:html -->',
		'<!-- wp:html {"content":"<form class=\"search-form\" role=\"search\" method=\"get\"><input type=\"search\" name=\"s\" placeholder=\"Search posts\"><button type=\"submit\">Find</button></form>"} --><form class="search-form" role="search" method="get"><input type="search" name="s" placeholder="Search posts"><button type="submit">Find</button></form><!-- /wp:html -->',
		'<!-- wp:html {"content":"<figure class=\"case-study\"><picture><source srcset=\"assets/team.webp\" type=\"image/webp\"><img src=\"assets/team.jpg\" alt=\"Care team\"></picture><figcaption>Care team caption</figcaption></figure>"} --><figure class="case-study"><picture><source srcset="assets/team.webp" type="image/webp"><img src="assets/team.jpg" alt="Care team"></picture><figcaption>Care team caption</figcaption></figure><!-- /wp:html -->',
		'<!-- wp:html {"content":"<form class=\"site-search\" role=\"search\" action=\"/search/\"><label>Find care</label><input type=\"search\" name=\"s\" placeholder=\"Search services\" aria-label=\"Search services\"><button type=\"submit\">Go</button></form>"} --><form class="site-search" role="search" action="/search/"><label>Find care</label><input type="search" name="s" placeholder="Search services" aria-label="Search services"><button type="submit">Go</button></form><!-- /wp:html -->',
		'<!-- wp:html {"content":"<input class=\"generated-search\" type=\"search\" name=\"s\" placeholder=\"Email Search for...\" aria-label=\"Email Search for...\">"} --><input class="generated-search" type="search" name="s" placeholder="Email Search for..." aria-label="Email Search for..."><!-- /wp:html -->',
		'<!-- wp:html {"content":"<blockquote class=\"pull\"><p>Movement changed everything.</p><cite>Patient story</cite></blockquote><hr class=\"rule\">"} --><blockquote class="pull"><p>Movement changed everything.</p><cite>Patient story</cite></blockquote><hr class="rule"><!-- /wp:html -->',
		'<!-- wp:html {"content":"<form class=\"lead-form\"><input name=\"email\"></form>"} --><form class="lead-form"><input name="email"></form><!-- /wp:html -->',
		'</div><!-- /wp:group -->',
	)
);

$reflection = new ReflectionClass( Static_Site_Importer_Page_Materializer::class );
$method     = $reflection->getMethod( 'reduce_safe_html_fallback_blocks' );
$output     = $method->invoke( null, $input );

$before = $count_blocks( $input );
$after  = $count_blocks( $output );

$assert( 12 === ( $before['core/html'] ?? 0 ), 'before-has-twelve-html-fallbacks' );
$assert( 1 === ( $after['core/html'] ?? 0 ), 'after-keeps-only-unsupported-form-fallback', print_r( $after, true ) );
$assert( 4 === ( $after['core/group'] ?? 0 ), 'existing-section-and-query-card-groups-preserved', print_r( $after, true ) );
$assert( 1 === ( $after['core/heading'] ?? 0 ), 'heading-converted' );
$assert( 1 === ( $after['core/paragraph'] ?? 0 ), 'paragraph-converted' );
$assert( 3 === ( $after['core/image'] ?? 0 ), 'images-and-captioned-figures-converted' );
$assert( 1 === ( $after['core/list'] ?? 0 ), 'list-converted' );
$assert( 2 === ( $after['core/list-item'] ?? 0 ), 'list-items-converted' );
$assert( 3 === ( $after['core/navigation'] ?? 0 ), 'navigation-converted' );
$assert( 7 === ( $after['core/navigation-link'] ?? 0 ), 'navigation-links-converted' );
$assert( 1 === ( $after['core/buttons'] ?? 0 ), 'button-wrapper-converted' );
$assert( 1 === ( $after['core/button'] ?? 0 ), 'button-converted' );
$assert( 1 === ( $after['core/query'] ?? 0 ), 'query-grid-converted' );
$assert( 1 === ( $after['core/post-template'] ?? 0 ), 'post-template-converted' );
$assert( 1 === ( $after['core/post-featured-image'] ?? 0 ), 'post-featured-image-converted' );
$assert( 1 === ( $after['core/post-title'] ?? 0 ), 'post-title-converted' );
$assert( 1 === ( $after['core/post-excerpt'] ?? 0 ), 'post-excerpt-converted' );
$assert( 3 === ( $after['core/search'] ?? 0 ), 'search-patterns-converted' );
$assert( 1 === ( $after['core/quote'] ?? 0 ), 'blockquote-converted' );
$assert( 1 === ( $after['core/separator'] ?? 0 ), 'separator-converted' );
$assert( str_contains( $output, 'className":"hero' ), 'section-class-preserved' );
$assert( str_contains( $output, 'className":"title' ), 'heading-class-preserved' );
$assert( str_contains( $output, 'assets/hero.jpg' ), 'image-src-preserved' );
$assert( str_contains( $output, 'Clinic room' ), 'image-alt-preserved' );
$assert( str_contains( $output, '#treatments' ), 'navigation-url-preserved' );
$assert( str_contains( $output, 'Contact' ), 'navigation-label-preserved' );
$assert( str_contains( $output, 'assets/team.jpg' ), 'picture-img-src-preserved' );
$assert( str_contains( $output, 'Care team caption' ), 'figure-caption-preserved' );
$assert( str_contains( $output, '/search/' ), 'search-action-preserved' );
$assert( str_contains( $output, 'Search services' ), 'search-placeholder-preserved' );
$assert( str_contains( $output, 'Email Search for...' ), 'standalone-search-placeholder-preserved' );
$assert( str_contains( $output, 'className":"generated-search' ), 'standalone-search-class-preserved' );
$assert( str_contains( $output, 'Patient story' ), 'quote-citation-preserved' );
$assert( str_contains( $output, '/book/' ), 'button-url-preserved' );
$assert( str_contains( $output, 'Read the <em>story</em>' ), 'figcaption-preserved' );
$assert( str_contains( $output, 'primary-nav' ), 'nav-class-preserved' );
$assert( str_contains( $output, 'Search posts' ), 'search-placeholder-preserved' );
$assert( str_contains( $output, '<form class="lead-form"><input name="email"></form>' ), 'unsupported-form-fallback-preserved' );

$mixed_contact_html = '<div class="contact-content"><aside class="contact-sidebar"><div class="contact-block"><div class="label">Booking</div><h3>Book a Show</h3><p>Email <a href="mailto:booking@example.com" class="contact-email"><svg aria-hidden="true" viewBox="0 0 16 16"><path d="M1 1h14v14H1z"/></svg> booking@example.com</a></p></div></aside><div class="contact-form-wrap"><h2>Send a Message</h2><form class="contact-form"><label>Name<input name="name"></label><select name="topic"><option>Booking</option></select><textarea name="message"></textarea><button type="submit">Send</button></form></div></div>';
$mixed_contact_input = '<!-- wp:html ' . wp_json_encode( array( 'content' => $mixed_contact_html ) ) . ' -->' . $mixed_contact_html . '<!-- /wp:html -->';
$mixed_contact_output = $method->invoke( null, $mixed_contact_input );
$mixed_contact_after  = $count_blocks( $mixed_contact_output );

$assert( 1 === ( $count_blocks( $mixed_contact_input )['core/html'] ?? 0 ), 'mixed-contact-before-single-large-html' );
$assert( 1 === ( $mixed_contact_after['core/html'] ?? 0 ), 'mixed-contact-after-single-bounded-form-html', print_r( $mixed_contact_after, true ) );
$assert( 3 <= ( $mixed_contact_after['core/group'] ?? 0 ), 'mixed-contact-static-layout-groups-converted', print_r( $mixed_contact_after, true ) );
$assert( in_array( 'core/heading', array_keys( $mixed_contact_after ), true ), 'mixed-contact-heading-converted' );
$assert( str_contains( $mixed_contact_output, 'booking@example.com' ), 'mixed-contact-link-text-preserved' );
$assert( str_contains( $mixed_contact_output, '<form class="contact-form">' ), 'mixed-contact-runtime-form-preserved' );

$cart_control_html = '<article class="merch-card"><h3>EP Tee</h3><p>Washed black tee.</p><button class="qty-btn" data-dir="down" aria-label="Decrease quantity">-</button><span class="qty-display" aria-live="polite">1</span><button class="add-to-cart">Add</button></article>';
$cart_control_input = '<!-- wp:html ' . wp_json_encode( array( 'content' => $cart_control_html ) ) . ' -->' . $cart_control_html . '<!-- /wp:html -->';
$cart_control_output = $method->invoke( null, $cart_control_input );
$cart_control_after  = $count_blocks( $cart_control_output );

$assert( 3 === ( $cart_control_after['core/html'] ?? 0 ), 'cart-controls-remain-bounded-html-islands', print_r( $cart_control_after, true ) );
$assert( 0 === ( $cart_control_after['core/button'] ?? 0 ), 'cart-controls-not-faked-as-core-buttons', print_r( $cart_control_after, true ) );
$assert( str_contains( $cart_control_output, 'class="add-to-cart"' ), 'cart-add-control-preserved' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: safe HTML fallback reducer smoke passed (' . $assertions . " assertions)\n";
