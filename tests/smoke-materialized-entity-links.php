<?php
/**
 * Reduced, source-neutral post-materialization route coverage.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting -- Minimal in-memory WordPress API stubs.
// phpcs:disable WordPress.Files.FileName -- Standalone smoke contains API stubs.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Stubs match WordPress signatures.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated CLI test.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI fixture and assertions.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test harness.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- WordPress stub signature.
// phpcs:disable Generic.Classes.DuplicateClassName -- Separate CLI harnesses use the same WooCommerce API stubs.

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once $wp_root . '/wp-includes/compat-utf8.php';
require_once $wp_root . '/wp-includes/utf8.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once $wp_root . '/wp-includes/class-wp-block-processor.php';

function wp_parse_url( $url ) {
	return parse_url( $url ); }
function sanitize_key( $key ) {
	return $key; }
function get_post_field( $field, $id ) {
	return $GLOBALS['entity_link_content']; }
function get_post_meta( $id, $key, $single = false ) {
	return ''; }
function wp_slash( $value ) {
	return $value; }
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags ); }
function wp_update_post( $post, $error = false ) {
	$GLOBALS['entity_link_content'] = $post['post_content'];
	return $post['ID']; }
function is_wp_error( $value ) {
	return false; }
function wp_make_link_relative( $link ) {
	return preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', (string) $link );
}

function get_permalink( $id ) {
	return 'https://local.example/entity/' . $id . '/'; }
function home_url( $path = '' ) {
	return 'https://local.example' . $path; }
function get_page_by_path( $path, $output = null, $type = null ) {
	return null; }
function get_option( $key, $default = false ) {
	return $default; }
function sanitize_title( $title ) {
	return $title; }
function post_type_exists( $type ) {
	return true; }
function taxonomy_exists( $taxonomy ) {
	return true; }
function wp_kses_post( $content ) {
	return $content; }
function wp_kses_uri_attributes() {
	return array( 'action', 'href', 'src' ); }
function esc_url( $url ) {
	return $url; }
class WC_Product_Simple {
	public function __call( $method, $arguments ) {}
	public function update_meta_data( $key, $value ) {
		$GLOBALS['seeded_product_meta'][ $key ] = $value; }
	public function save() {
		return 42; }
}
function wc_get_page_id( $page ) {
	return $GLOBALS['cart_page_id']; }
$GLOBALS['cart_page_id'] = 88;

// A child run for the memory limit check below: one menu after 3 MB of linked
// paragraphs, rewritten with or without panel blocks once only the given headroom
// is left under the memory limit.
$memory_probe = getenv( 'SSI_PANEL_MEMORY_PROBE' );
if ( is_string( $memory_probe ) && '' !== $memory_probe ) {
	list( $probe_mode, $probe_headroom ) = explode( ':', $memory_probe );
	$probe_state  = '{"html":"<a href=\\"/second/index.html\\">Menu</a>"}';
	$probe_doc    = str_repeat( '<!-- wp:paragraph --><p>Ordinary copy without links. <a href="/second/index.html">Link</a></p><!-- /wp:paragraph -->', 27000 )
		. '<!-- wp:example/authored-button ' . \Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode( array( 'sourceAttributes' => array( array( 'name' => 'data-blocks-engine-capture-dialog-panel-state', 'value' => $probe_state ) ) ) ) . ' -->'
		. '<button data-blocks-engine-capture-dialog-panel-state="' . htmlspecialchars( $probe_state, ENT_QUOTES, 'UTF-8' ) . '">Menu</button><!-- /wp:example/authored-button -->';
	$probe_names  = 'head' === $probe_mode ? array( 'example/authored-button' ) : array();
	$probe_retain = str_repeat( 'x', max( 0, ini_parse_quantity( (string) ini_get( 'memory_limit' ) ) - memory_get_usage( true ) - (int) $probe_headroom ) );
	$probe_out    = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $probe_doc, array( '/second' => '/second-page/' ), $probe_unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: $probe_names );
	echo str_contains( $probe_out, '/second/index.html' ) === ( 'head' !== $probe_mode ) ? 'ok' : 'wrong', PHP_EOL;
	exit( 0 );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
};
require dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
$manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_woo_products_manifest(
	array(
		'schema_version' => 1,
		'products'       => array(
			array(
				'name'          => 'Field Kit',
				'slug'          => 'field-kit',
				'regular_price' => '12',
				'url'           => 'https://source.example/products/field-kit',
			),
		),
	)
);
$assert( '/products/field-kit' === ( $manifest['products'][0]['source_route'] ?? '' ), 'Canonical source route survives manifest validation.' );
define( 'OBJECT', 'OBJECT' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
$seeded = Static_Site_Importer_Woo_Product_Seeder::seed( $manifest );
$assert( 42 === ( $seeded['products'][0]['id'] ?? 0 ), 'Product materializes.' );
$assert( '/products/field-kit' === ( $GLOBALS['seeded_product_meta']['_static_site_importer_source_route'] ?? '' ), 'Seeded product stores source route metadata.' );
$GLOBALS['entity_link_content'] = file_get_contents( __DIR__ . '/fixtures/materialized-entity-links.html' );
$state                          = array(
	'ordered_pages'         => array(
		array(
			'source_path' => 'collections/tools/index.html',
			'route'       => array( 'path' => '/collections/tools' ),
		),
	),
	'source_ids'            => array( 'collections/tools/index.html' => 10 ),
	'args'                  => array(
		'source_metadata'             => array( 'source_path' => 'https://source.example/' ),
		'materialized_entity_reports' => array(
			'catalog' => $seeded,
		),
	),
	'applied'               => array( 'runtime_declarations' => array( 'entity_bindings' => array() ) ),
	'resolved'              => array(
		'pages'  => array(),
		'writes' => array(
			array(
				'kind'         => 'theme_template',
				'target_path'  => 'templates/archive.html',
				'payload'      => array(
					'encoding' => 'utf8',
					'data'     => '<a href="https://source.example/collections/tools">Tools</a>',
				),
				'payload_hash' => 'declared',
			),
		),
	),
	'theme_dir'             => '/theme',
	'composed_theme_writes' => array( '/theme/templates/archive.html' => '<title>Overlay</title><a href="https://source.example/collections/tools">Tools</a>' ),
);
$assert( true === Static_Site_Importer_Site_Plan_Persistence::rewrite_materialized_route_links( $state ), 'Rewrite completes.' );
$template = $state['resolved']['writes'][0];
$assert( '<title>Overlay</title><a href="/entity/10/">Tools</a>' === $template['payload']['data'] && ! isset( $template['payload_hash'] ), 'A composed template is rewritten from its overlaid text and its stale hash dropped.' );
$assert( $template['payload']['data'] === $state['composed_theme_writes']['/theme/templates/archive.html'], 'The composed copy matches the rewritten write, so the write is not skipped.' );
$stored = $GLOBALS['entity_link_content'];
$assert( 2 === substr_count( $stored, 'href="/entity/42/"' ), 'Scoped card and bare nav resolve to the seeded product.' );
$assert( str_contains( $stored, 'href="/entity/10/"' ), 'Category listing retains its imported page identity.' );
$assert( str_contains( $stored, 'https://source.example/journal/missing' ), 'Unimported route stays unchanged.' );
$assert( array( '/journal/missing' ) === array_keys( $state['applied']['unresolved_link_targets'] ), 'Receipt records distinct unresolved targets, including navigation.' );
$assert( str_contains( $stored, 'https://elsewhere.example/products/field-kit' ), 'Cross-origin URL stays unchanged.' );
$assert( 2 === substr_count( $stored, '/entity/42/' ), 'Both references store the product permalink.' );
$assert( str_contains( $stored, '/entity/88/' ), 'Cart stores the Woo page permalink at import.' );
$assert( str_contains( $stored, '?s=field%20kit&post_type=product' ), 'Search is a local product search.' );
$assert( null === Static_Site_Importer_Site_Plan_Persistence::scoped_product_reference( '/products/not-field-kit', array( '/products/field-kit' => '/?p=42' ) ), 'Other handles do not match.' );
// Data URL attributes can also contain flags; only URL-shaped values are links.
foreach ( array( '"', "'", '\\"', '\\u0022', '\\u005cu0022' ) as $quote ) {
	$fixture = '<product-info data-update-url="true" data-url="/products/x"><a href="/products/x">x</a></product-info>';
	$fixture = str_replace( '"', $quote, $fixture );
	$unresolved = array();
	$rewritten = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $fixture, array( '/products/x' => '/local/x' ), $unresolved, '', array(), '/website/products/x/index.html' );
	$assert( str_replace( '/products/x', '/local/x', $fixture ) === $rewritten && array() === $unresolved, 'Neutral product fixture rewrites real links without reporting the boolean flag.' );
	foreach ( array( 'true', 'false', '', '42', '1.5', 'variant' ) as $value ) {
		$attribute = 'data-update-url=' . $quote . $value . $quote;
		$unresolved = array();
		$rewritten = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $attribute, array( '/website/products/x/' . $value => '/must-not-rewrite' ), $unresolved, '', array(), '/website/products/x/index.html' );
		$assert( $attribute === $rewritten && array() === $unresolved, 'Non-URL data values are neither rewritten nor diagnosed.' );
	}
	foreach ( array( '/products/x', 'products/x', './products/x', '../products/x', 'x.html', 'https://source.example/products/x', '//source.example/products/x', '?view=all', '#details' ) as $value ) {
		$unresolved = array();
		Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( 'data-url=' . $quote . $value . $quote, array(), $unresolved, 'https://source.example/', array(), '/current.html' );
		$assert( array() !== $unresolved, 'URL-shaped data values remain link candidates.' );
	}
}
$unresolved = array();
$relative   = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="../../products/field-kit?ref=nav#details">Relative</a>', array( '/products/field-kit' => '/?p=42' ), $unresolved, 'https://source.example/', array(), '/collections/tools/index.html' );
$nested     = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '{"html":"\\u005cu003ca href=\\u005cu0022https://source.example/products/field-kit\\u005cu0022\\u005cu003e"}', array(), $unresolved, 'https://source.example/', array( '/products/field-kit' => '/?p=42' ) );
$assert( str_contains( $nested, 'href=\\u005cu0022/?p=42\\u005cu0022' ), 'HTML escaped twice inside block attributes is rewritten.' );
$assert( str_contains( $relative, '/?p=42&ref=nav#details' ), 'Document-relative routes preserve query and fragment.' );
const EXAMPLE_PANEL_BLOCKS = array( 'example/authored-button', 'example/layout-shell', 'example/accessible-link' );
// A captured dialog keeps HTML snapshots as JSON in data attributes, both in the
// block comment and in the saved markup. Both copies must decode to the same panel.
$panel_copies = static function ( string $block ): array {
	$blocks = new WP_Block_Processor( $block );
	$blocks->next_delimiter();
	$attributes = $blocks->allocate_and_return_parsed_attributes()['sourceAttributes'] ?? array();
	$tags       = new WP_HTML_Tag_Processor( $block );
	$tags->next_tag();
	$copies = array();
	foreach ( $attributes as $attribute ) {
		$decode                       = str_starts_with( $attribute['name'], 'data-blocks-engine-capture-' ) ? static fn( $value ) => json_decode( (string) $value, true ) : static fn( $value ) => $value;
		$copies[ $attribute['name'] ] = array(
			'block comment' => $decode( $attribute['value'] ),
			'saved markup'  => $decode( $tags->get_attribute( $attribute['name'] ) ),
		);
	}
	return $copies;
};
$panel_case = static function ( string $label, string $name, array $snapshot, array $expected, array $routes = array() ) use ( $assert, $panel_copies ): void {
	$state = (string) json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$block = '<!-- wp:example/authored-button ' . \Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode( array( 'sourceAttributes' => array( array( 'name' => $name, 'value' => $state ) ) ) ) . ' -->'
		. '<button ' . $name . '="' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '">Menu</button><!-- /wp:example/authored-button -->';
	$unresolved = array();
	$block      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $block, $routes + array( '/' => '/', '/second' => '/second-page/', '/about--us' => '/about-us/' ), $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
	foreach ( $panel_copies( $block )[ $name ] ?? array( 'missing' => null ) as $copy => $decoded ) {
		$assert( $expected === $decoded, $label . ': the ' . $copy . ' decodes to the rewritten panel.' );
	}
	$assert( array() === $unresolved, $label . ': nothing is left unresolved.' );
	$expected_comment = '<!-- wp:example/authored-button ' . \Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode( array( 'sourceAttributes' => array( array( 'name' => $name, 'value' => (string) json_encode( $expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) ) ) . ' -->';
	$assert( str_starts_with( $block, $expected_comment ), $label . ': the block comment is encoded as Blocks Engine encodes it.' );
};
$long_query = substr( str_repeat( '&amp;utm_term=floor', 500 ), 5 );
$panel_case(
	'Combined panel links',
	'data-blocks-engine-capture-dialog-panel-state',
	array( 'html' => '<div role="dialog"><span>Example: href="</span><a href="/index.html">Home</a><a href="/second/index.html?ref=menu&amp;copy=1#top">Second</a><a href="/about--us/index.html">About</a><a href="/search?q=field&amp;sort=popular">Find</a><a href="/second/index.html?ref=summer sale">Sale</a><a href="/second/index.html?' . $long_query . '">Long</a><a href="/index.html">Again</a></div>' ),
	array( 'html' => '<div role="dialog"><span>Example: href="</span><a href="/">Home</a><a href="/second-page/?ref=menu&amp;copy=1#top">Second</a><a href="/about-us/">About</a><a href="/?s=field&amp;post_type=product">Find</a><a href="/second-page/?ref=summer sale">Sale</a><a href="/second-page/?' . $long_query . '">Long</a><a href="/">Again</a></div>' )
);
$panel_case(
	'An attribute value that reads like an href',
	'data-blocks-engine-capture-dialog-panel-state',
	array( 'html' => '<nav><a title="href=" href="/second/index.html">Second</a></nav>' ),
	array( 'html' => '<nav><a title="href=" href="/second-page/">Second</a></nav>' )
);
$panel_case(
	'Visible text that reads like an href',
	'data-blocks-engine-capture-dialog-panel-state',
	array( 'html' => '<p><code>href="/second/index.html?ref=summer sale"</code> <a href="/index.html">Home</a></p>' ),
	array( 'html' => '<p><code>href="/second/index.html?ref=summer sale"</code> <a href="/">Home</a></p>' )
);
$panel_case(
	'Raw angle brackets in a quoted href',
	'data-blocks-engine-capture-dialog-panel-state',
	array( 'html' => '<a href="/second/index.html?ref=<sale>">Sale</a>' ),
	array( 'html' => '<a href="/second-page/?ref=&lt;sale&gt;">Sale</a>' )
);
$backslashes = str_repeat( 'C:\\\\Floors\\\\"quoted"\\\\ ', 60000 );
$panel_case(
	'A large backslash-heavy panel',
	'data-blocks-engine-capture-dialog-panel-state',
	array( 'html' => '<a href="/index.html">Home</a><pre>' . $backslashes . '</pre><a href="/second/index.html">Second</a>' ),
	array( 'html' => '<a href="/">Home</a><pre>' . $backslashes . '</pre><a href="/second-page/">Second</a>' )
);
$panel_case(
	'Resting and opened existing-panel snapshots',
	'data-blocks-engine-capture-existing-panel',
	array(
		'resting' => array( 'html' => '<a href="/second/index.html">Second</a>', 'hidden' => true ),
		'opened'  => array( 'html' => '<a href="/index.html">Home</a>', 'hidden' => false ),
	),
	array(
		'resting' => array( 'html' => '<a href="/second-page/">Second</a>', 'hidden' => true ),
		'opened'  => array( 'html' => '<a href="/">Home</a>', 'hidden' => false ),
	)
);
// Block shapes Gutenberg accepts: delimiter spacing, entry order, a raw `-->` in
// attribute JSON, and an anchor trigger whose own href is also a source attribute.
$panel_routes = array( '/' => '/', '/second' => '/second-page/', '/en' => '/english/' );
$panel_state  = (string) json_encode( array( 'html' => '<a hreflang="en" href="/second/index.html">Second</a>' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$panel_after  = array( 'html' => '<a hreflang="en" href="/second-page/">Second</a>' );
$shape_case   = static function ( string $label, string $block, array $expected ) use ( $assert, $panel_copies, $panel_routes ): string {
	$unresolved = array();
	$block      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
	$copies     = $panel_copies( $block );
	foreach ( $expected as $name => $value ) {
		foreach ( $copies[ $name ] ?? array( 'missing' => null ) as $copy => $decoded ) {
			$assert( $value === $decoded, $label . ': ' . $name . ' in the ' . $copy . ' matches the expected value.' );
		}
	}
	$assert( array() === $unresolved, $label . ': nothing is left unresolved.' );
	return $block;
};
$encode_block = static fn( array $attributes ): string => \Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode( $attributes );
$markup       = '<button data-blocks-engine-capture-dialog-panel-state="' . htmlspecialchars( $panel_state, ENT_QUOTES, 'UTF-8' ) . '">Menu</button><!-- /wp:example/authored-button -->';
$panel_key    = 'data-blocks-engine-capture-dialog-panel-state';
$shape_case( 'Two spaces after the comment opener', '<!--  wp:example/authored-button ' . $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ) . ' -->' . $markup, array( $panel_key => $panel_after ) );
$shape_case( 'Value before name', '<!-- wp:example/authored-button ' . $encode_block( array( 'sourceAttributes' => array( array( 'value' => $panel_state, 'name' => $panel_key ) ) ) ) . ' -->' . $markup, array( $panel_key => $panel_after ) );
// The route patterns alone, exactly as on main: no panel block names are given.
$route_patterns = static function ( string $content, array $routes ) use ( &$unresolved ): string {
	return Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $content, $routes, $unresolved, 'https://source.example/', array(), '/index.html' );
};
// Markup with each snapshot value masked, in the saved markup and the block comment.
$outside_snapshots = static fn( string $content ): string => (string) preg_replace( array( '/(data-blocks-engine-capture-(?:dialog-panel-state|existing-panel)=)"[^"]*"/', '/"\\{\\\\u0022(?:[^"\\\\]++|\\\\.)*+"/' ), array( '$1"snapshot"', '"snapshot"' ), $content );
// Core's block parser does not read this delimiter, but the block editor does, so it
// keeps the route patterns, as on main.
$raw_close_block = '<!-- wp:example/authored-button {"label":"a-->b",' . substr( $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ), 1 ) . ' -->' . $markup;
$unresolved      = array();
$assert( $route_patterns( $raw_close_block, $panel_routes ) === Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $raw_close_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ), 'A raw comment closer inside attribute JSON keeps the route patterns.' );
$anchor_input = '<!-- wp:example/authored-button ' . $encode_block( array( 'url' => '/second/index.html', 'sourceAttributes' => array( array( 'name' => 'href', 'value' => '/second/index.html' ), array( 'name' => 'hreflang', 'value' => '/en/index.html' ), array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ) . ' -->'
	. '<a href="/second/index.html" hreflang="/en/index.html" xlink:href="/second/index.html" data-href="/second/index.html" data-config=\'{"url":"/second/index.html"}\' ' . $panel_key . '="' . htmlspecialchars( $panel_state, ENT_QUOTES, 'UTF-8' ) . '">Menu</a><!-- /wp:example/authored-button -->';
$unresolved   = array();
$anchor       = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $anchor_input, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
// The route patterns rewrite the trigger's own links, so the whole block keeps their output.
$assert( $route_patterns( $anchor_input, $panel_routes ) === $anchor, 'An anchor trigger whose own links the route patterns rewrite keeps their output.' );
$assert( $outside_snapshots( $route_patterns( $anchor_input, $panel_routes ) ) === $outside_snapshots( $anchor ), 'Everything outside the snapshots of an anchor trigger matches the route patterns.' );
$assert( str_contains( $anchor, '"url":"/second-page/"' ), 'A sibling url block attribute keeps its route rewrite.' );
// A wrapper block keeps the existing-panel snapshot under its attribute name.
$existing       = (string) json_encode( array( 'resting' => array( 'html' => '<a href="/second/index.html">Second</a>' ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$existing_key   = 'data-blocks-engine-capture-existing-panel';
$wrapper_block  = '<!-- wp:example/layout-shell ' . $encode_block( array( 'wrappers' => array( array( 'tagName' => 'div', 'attributes' => array( 'href' => '/second/index.html', 'data-href' => '/second/index.html', 'data-url' => '/second/index.html', $existing_key => $existing ) ) ) ) ) . ' -->'
	. '<div href="/second/index.html" data-href="/second/index.html" data-url="/second/index.html" ' . $existing_key . '="' . htmlspecialchars( $existing, ENT_QUOTES, 'UTF-8' ) . '"></div><!-- /wp:example/layout-shell -->';
$wrapper_input  = $wrapper_block;
$unresolved     = array();
$wrapper_block  = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $wrapper_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$wrapper_parsed = new WP_Block_Processor( $wrapper_block );
$wrapper_parsed->next_delimiter();
$wrapper_attrs = $wrapper_parsed->allocate_and_return_parsed_attributes()['wrappers'][0]['attributes'] ?? array();
$wrapper_tag   = new WP_HTML_Tag_Processor( $wrapper_block );
$wrapper_tag->next_tag();
$wrapper_after = array( 'resting' => array( 'html' => '<a href="/second-page/">Second</a>' ) );
$assert( $route_patterns( $wrapper_input, $panel_routes ) === $wrapper_block, 'A keyed existing-panel snapshot whose wrapper links the route patterns rewrite keeps their output.' );
$plain_wrapper = '<!-- wp:example/layout-shell ' . $encode_block( array( 'wrappers' => array( array( 'tagName' => 'div', 'attributes' => array( 'class' => 'menu', $existing_key => $existing ) ) ) ) ) . ' -->'
	. '<div class="menu" ' . $existing_key . '="' . htmlspecialchars( $existing, ENT_QUOTES, 'UTF-8' ) . '"></div><!-- /wp:example/layout-shell -->';
$plain_after   = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $plain_wrapper, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$plain_parsed  = new WP_Block_Processor( $plain_after );
$plain_parsed->next_delimiter();
$plain_tag = new WP_HTML_Tag_Processor( $plain_after );
$plain_tag->next_tag();
$assert( $wrapper_after === json_decode( (string) ( $plain_parsed->allocate_and_return_parsed_attributes()['wrappers'][0]['attributes'][ $existing_key ] ?? '' ), true ) && $wrapper_after === json_decode( (string) $plain_tag->get_attribute( $existing_key ), true ), 'A keyed existing-panel snapshot is rewritten in both copies.' );
$assert( $outside_snapshots( $route_patterns( $wrapper_input, $panel_routes ) ) === $outside_snapshots( $wrapper_block ), 'Everything outside a wrapper snapshot matches the route patterns.' );
// Wrapper attributes around a snapshot keep the route patterns in both copies, whether
// the snapshot needs rewriting, is already rewritten, or is not a snapshot at all.
$shell_block  = static function ( string $state, array $attributes ) use ( $encode_block, $existing_key ): string {
	$attributes = array( $existing_key => $state ) + $attributes;
	$tag        = '<div';
	foreach ( $attributes as $name => $value ) {
		$tag .= ' ' . $name . '="' . htmlspecialchars( $value, ENT_COMPAT ) . '"';
	}
	return '<!-- wp:example/layout-shell ' . $encode_block( array( 'wrappers' => array( array( 'tagName' => 'div', 'attributes' => $attributes ) ) ) ) . ' -->' . $tag . '></div><!-- /wp:example/layout-shell -->';
};
$shell_copies = static function ( string $block ): array {
	$parsed = new WP_Block_Processor( $block );
	$parsed->next_delimiter();
	$tag = new WP_HTML_Tag_Processor( $block );
	$tag->next_tag( 'div' );
	$copies = array();
	foreach ( $parsed->allocate_and_return_parsed_attributes()['wrappers'][0]['attributes'] ?? array() as $name => $value ) {
		$copies[ $name ] = array( $value, $tag->get_attribute( $name ) );
	}
	return $copies;
};
$config_links = "{'url':'/second/index.html','href':'/second/index.html','action':'/second/index.html'}";
$config_after = "{'url':'/second-page/','href':'/second-page/','action':'/second-page/'}";
foreach ( array(
	'A snapshot already rewritten'      => array( (string) json_encode( $wrapper_after, JSON_UNESCAPED_SLASHES ), (string) json_encode( $wrapper_after, JSON_UNESCAPED_SLASHES ) ),
	'A snapshot value that is not JSON' => array( 'not-json', 'not-json' ),
	'A scalar snapshot value'           => array( '"scalar"', '"scalar"' ),
) as $label => list( $shell_state, $shell_expected ) ) {
	$shell_input = $shell_block( $shell_state, array( 'data-config' => $config_links ) );
	$shell_after = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $shell_input, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
	$copies      = $shell_copies( $shell_after );
	$assert( array( $config_after, $config_after ) === ( $copies['data-config'] ?? null ), $label . ': every data-config link is rewritten in both copies.' );
	$assert( json_decode( $shell_expected ) == json_decode( (string) $copies[ $existing_key ][0] ) && $copies[ $existing_key ][0] === $copies[ $existing_key ][1], $label . ': both snapshot copies decode to the expected panel.' );
	$assert( $outside_snapshots( $route_patterns( $shell_input, $panel_routes ) ) === $outside_snapshots( $shell_after ), $label . ': everything outside the snapshot matches the route patterns.' );
}
$panel_block = static fn( string $name, array $attributes, string $tag ): string => '<!-- wp:example/' . $name . ' ' . $encode_block( $attributes ) . ' -->' . $tag . '<!-- /wp:example/' . $name . ' -->';
$panel_attr  = static fn( string $state ): string => $panel_key . '="' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '"';
// A block without a snapshot keeps the route patterns, so both href copies agree.
$decoy  = $panel_block( 'button', array( 'sourceAttributes' => array( array( 'name' => 'title', 'value' => 'Example: href=' ), array( 'name' => 'href', 'value' => '/second/index.html' ) ) ), '<a title="Example: href=" href="/second/index.html">Second</a>' );
$copies = $panel_copies( Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $decoy, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ) );
$assert( $copies['href']['block comment'] === $copies['href']['saved markup'] && 'Example: href=' === $copies['title']['block comment'], 'A block without a snapshot keeps matching href copies.' );
// When a route pattern match runs from outside a snapshot into it, the document
// keeps the route patterns' output whole.
$labelled_input = $panel_block( 'authored-button', array( 'sourceAttributes' => array( array( 'name' => 'title', 'value' => 'href="/search?other=' ), array( 'name' => $panel_key, 'value' => $panel_state ) ), 'text' => 'Menu "label"' ), '<button title="href=&quot;/search?other=" ' . $panel_attr( $panel_state ) . '>Menu "label"</button>' );
$labelled       = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $labelled_input, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( $route_patterns( $labelled_input, $panel_routes ) === $labelled, 'A pattern match that crosses into a snapshot keeps the route patterns.' );
$labelled_input = $panel_block( 'authored-button', array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ), array( 'name' => 'title', 'value' => 'Find "more"' ) ), 'text' => 'Menu "label"' ), '<button ' . $panel_attr( $panel_state ) . ' title="Find &quot;more&quot;">Menu "label"</button>' );
$labelled       = $shape_case( 'A title with quotes', $labelled_input, array( $panel_key => $panel_after, 'title' => 'Find "more"' ) );
$labelled_blocks = new WP_Block_Processor( $labelled );
$labelled_blocks->next_delimiter();
$assert( 'Menu "label"' === ( $labelled_blocks->allocate_and_return_parsed_attributes()['text'] ?? null ) && str_contains( $labelled, '>Menu "label"</button>' ), 'Block text survives verbatim.' );
// A resolved query with a quote is written as main writes it.
$assert( '<a href="/second-page/?label="quote"">Q</a>' === Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="/second/index.html?label=&quot;quote&quot;">Q</a>', $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ), 'A quote in a resolved query is written as main writes it.' );
// A comment that only looks like a delimiter changes nothing; a delimiter only the
// block editor reads keeps the route patterns without affecting the panels after it.
$menu_block = $panel_block( 'authored-button', array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ), '<button ' . $panel_attr( $panel_state ) . '>Menu</button>' );
$mixed      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<!-- wp: this is a note -->' . $raw_close_block . $menu_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( str_starts_with( $mixed, '<!-- wp: this is a note -->' . $route_patterns( $raw_close_block, $panel_routes ) ), 'The block Core cannot parse keeps the route patterns.' );
$assert( $panel_after === ( $panel_copies( substr( $mixed, strlen( '<!-- wp: this is a note -->' . $route_patterns( $raw_close_block, $panel_routes ) ) ) )[ $panel_key ]['saved markup'] ?? null ), 'Other panels in the document are still rewritten.' );
$shape_case( 'A panel after a note comment', '<!-- wp: this is a note -->' . $menu_block, array( $panel_key => $panel_after ) );
// Rewriting a panel leaves unrelated JSON as written: empty objects, numeric keys, floats.
$exact_state = '{"html":"<a href=\\"/second/index.html\\">Second</a>","meta":{},"keys":{"0":"a"},"ratio":1.0}';
$exact       = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<!-- wp:example/authored-button {"meta":{},"keys":{"0":"a"},"ratio":1.0,' . substr( $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $exact_state ) ) ) ), 1 ) . ' --><button ' . $panel_attr( $exact_state ) . '>Menu</button><!-- /wp:example/authored-button -->', $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$exact_after = str_replace( '/second/index.html', '/second-page/', $exact_state );
$exact_tag   = new WP_HTML_Tag_Processor( $exact );
$exact_tag->next_tag( 'button' );
$assert( str_starts_with( $exact, '<!-- wp:example/authored-button {"meta":{},"keys":{"0":"a"},"ratio":1.0,' ), 'Block attribute JSON keeps empty objects, numeric keys and floats.' );
$exact_blocks = new WP_Block_Processor( $exact );
$exact_blocks->next_delimiter();
$assert( $exact_after === $exact_tag->get_attribute( $panel_key ) && $exact_after === ( $exact_blocks->allocate_and_return_parsed_attributes()['sourceAttributes'][0]['value'] ?? null ), 'Snapshot JSON keeps empty objects, numeric keys and floats in both copies.' );
// Destinations are written into a panel exactly as resolved, unless a browser would
// read them, after dropping tabs and newlines and trimming, as another site.
foreach ( array(
	"/\t/other.example/new"    => null,
	"//other.example/new"     => null,
	"/\\other.example/new"    => null,
	"/new\n"                   => "/new\n",
	'/new/?q=summer sale'     => '/new/?q=summer sale',
	' /new'                   => ' /new',
	"/new/#m\tenu"             => "/new/#m\tenu",
) as $destination => $written ) {
	$offsite = $panel_copies( Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $menu_block, array( '/second' => $destination ), $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ) );
	$expect  = null === $written ? json_decode( $panel_state, true ) : array( 'html' => '<a hreflang="en" href="' . $written . '">Second</a>' );
	$assert( $expect === $offsite[ $panel_key ]['block comment'] && $expect === $offsite[ $panel_key ]['saved markup'], 'Destination ' . json_encode( $destination ) . ' is written safely into both panel copies.' );
}
// A trigger link whose saved markup comes from its `content` HTML: both copies agree,
// whether the block carries a keyed snapshot or only mentions the attribute name.
$link_generator = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AccessibleLinkBlockGenerator();
foreach ( array(
	'with a snapshot'        => array( 'sourceAttributes' => array( $existing_key => $existing ) ),
	'naming a snapshot only' => array( 'accessibleLabel' => 'Example attribute ' . $panel_key ),
) as $link_label => $link_extra ) {
	$link_attributes = $link_extra + array(
		'href'             => '/second/index.html',
		'accessibleLabel'  => 'Menu',
		'contentMode'      => 'raw-source',
		'content'          => '<span data-url="/second/index.html">Open</span>',
		'sourceAttributes' => array(),
	);
	$link_block      = '<!-- wp:example/accessible-link ' . $encode_block( $link_attributes ) . ' -->' . $link_generator->markup( $link_attributes ) . '<!-- /wp:example/accessible-link -->';
	$link_after      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $link_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
	$link_blocks     = new WP_Block_Processor( $link_after );
	$link_blocks->next_delimiter();
	$link_attrs = $link_blocks->allocate_and_return_parsed_attributes();
	$link_tag   = new WP_HTML_Tag_Processor( $link_after );
	$link_tag->next_tag( 'span' );
	$assert( '<span data-url="/second-page/">Open</span>' === ( $link_attrs['content'] ?? null ) && '/second-page/' === $link_tag->get_attribute( 'data-url' ), 'An accessible link ' . $link_label . ' rewrites its content and saved span alike.' );
}
// A block type whose saved fields are not covered keeps the route patterns, so a
// visible link in it is rewritten as on main.
$label_block = $panel_block( 'authored-label', array( 'sourceAttributes' => array( $panel_key => $panel_state ) ), '<label ' . $panel_attr( $panel_state ) . '><a href="/second/index.html">Terms</a></label>' );
$label_after = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $label_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( $route_patterns( $label_block, $panel_routes ) === $label_after && str_contains( $label_after, '<a href="/second-page/">Terms</a>' ), 'An uncovered block type keeps the route patterns and its visible link is rewritten.' );
// Nested blocks are independent: an uncovered child does not stop its covered parent
// or sibling, and the parent does not stop the child.
$nested_shell = '<!-- wp:example/layout-shell ' . $encode_block( array( 'wrappers' => array( array( 'tagName' => 'div', 'attributes' => array( 'data-href' => '/second/index.html', $existing_key => $existing ) ) ) ) ) . ' -->'
	. '<div data-href="/second/index.html" ' . $existing_key . '="' . htmlspecialchars( $existing, ENT_QUOTES, 'UTF-8' ) . '">' . $label_block . $menu_block . '</div><!-- /wp:example/layout-shell -->';
$nested_after = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $nested_shell, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$nested_tag   = new WP_HTML_Tag_Processor( $nested_after );
$nested_tag->next_tag();
$nested_main  = new WP_HTML_Tag_Processor( $route_patterns( $nested_shell, $panel_routes ) );
$nested_main->next_tag();
$assert( '/second-page/' === $nested_tag->get_attribute( 'data-href' ) && $nested_main->get_attribute( $existing_key ) === $nested_tag->get_attribute( $existing_key ), 'A covered parent whose bytes the route patterns change keeps their output.' );
$assert( str_contains( $nested_after, $label_after ), 'The uncovered child keeps the route patterns inside a covered parent.' );
$assert( $panel_after === ( $panel_copies( substr( $nested_after, strpos( $nested_after, '<!-- wp:example/authored-button ' ) ) )[ $panel_key ]['saved markup'] ?? null ), 'A covered sibling is rewritten next to an uncovered child.' );
// A delimiter only the block editor reads, naming a snapshot in a label, keeps the
// route patterns and leaves everything after it to be rewritten.
$editor_link = '<!-- wp:example/accessible-link {"accessibleLabel":"' . $panel_key . ' x-->y","href":"/second/index.html"} --><a href="/second/index.html">Open</a><!-- /wp:example/accessible-link -->';
$editor_after = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $editor_link . $menu_block . '<a href="/second/index.html">After</a>', $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( str_starts_with( $editor_after, $route_patterns( $editor_link, $panel_routes ) ) && str_ends_with( $editor_after, '<a href="/second-page/">After</a>' ), 'An editor-only delimiter keeps the route patterns and nothing after it is held.' );
$assert( str_contains( $editor_after, '"href":"/second-page/"} --><a href="/second-page/">Open</a>' ), 'An editor-only delimiter naming a snapshot has its links rewritten, as on main.' );
// Many editor-only delimiters before a few MB of paragraphs stay linear.
$editor_only = str_repeat( $raw_close_block, 30000 ) . str_repeat( '<!-- wp:paragraph --><p><a href="/second/index.html">a link</a></p><!-- /wp:paragraph -->', 30000 ) . $menu_block;
$started     = microtime( true );
$editor_many = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $editor_only, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( microtime( true ) - $started < 2.0, 'Thirty thousand editor-only blocks before a few MB rewrite in under two seconds.' );
$assert( 30000 === substr_count( $editor_many, '<a href="/second-page/">a link</a>' ), 'Paragraph links after editor-only blocks are rewritten.' );
unset( $editor_only, $editor_many );
// A comment that is not a block opener changes nothing after it.
$note_after = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<!-- wp:note this is a note -->' . $menu_block . '<a href="/second/index.html">After</a>', $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( str_ends_with( $note_after, '<a href="/second-page/">After</a>' ) && $panel_after === ( $panel_copies( substr( $note_after, strlen( '<!-- wp:note this is a note -->' ) ) )[ $panel_key ]['saved markup'] ?? null ), 'Links after a note comment are still rewritten.' );
$notes = str_repeat( '<!-- wp:note this is a note -->', 100 ) . str_repeat( '<!-- wp:paragraph --><p><a href="/second/index.html">a link</a></p><!-- /wp:paragraph -->', 30000 ) . $menu_block;
memory_reset_peak_usage();
$memory_before = memory_get_usage();
$notes_after   = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $notes, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( memory_get_peak_usage() - $memory_before < 64 * 1048576, 'A hundred note comments before a few MB stay within 64 MB of working memory.' );
$assert( 30000 === substr_count( $notes_after, '<a href="/second-page/">a link</a>' ), 'Every paragraph link after the notes is rewritten.' );
unset( $notes, $notes_after );
// Attribute JSON that cannot be encoded again stays as written around the snapshot.
$overflow       = str_replace( '<!-- wp:example/authored-button {', '<!-- wp:example/authored-button {"quantity":1e309,', $menu_block );
$overflow_after = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $overflow, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( str_starts_with( $overflow_after, '<!-- wp:example/authored-button {"quantity":1e309,' ) && $outside_snapshots( $route_patterns( $overflow, $panel_routes ) ) === $outside_snapshots( $overflow_after ), 'Attribute JSON that cannot be re-encoded stays as written outside the snapshot.' );
// Outside snapshots, destinations are written exactly as the route patterns write them.
$assert( '<a href="/new/?q=summer sale">S</a>' === Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="/second/index.html">S</a>', array( '/second' => '/new/?q=summer sale' ), $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ), 'A space in a destination outside a snapshot is written as resolved.' );
// Without a covered snapshot, output is exactly what main writes, control characters included.
$tab_routes = array( '/second' => "/second-page/\t" );
$other      = '<!-- wp:example/other ' . $encode_block( array( 'href' => '/second', 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => '{"html":"<a href=\\"/second\\">A</a>"}' ) ) ) ) . ' --><a href="/second" ' . $panel_attr( '{"html":"<a href=\\"/second\\">A</a>"}' ) . '>Other</a><!-- /wp:example/other -->';
foreach ( array(
	array( '<!-- wp:paragraph --><p><a href="/second/#m&#9;enu">Open</a></p><!-- /wp:paragraph -->', $panel_routes, "<!-- wp:paragraph --><p><a href=\"/second-page/#m\tenu\">Open</a></p><!-- /wp:paragraph -->" ),
	array( "<!-- wp:paragraph --><p><a href=\"/second/?q=x\ty\">Open</a></p><!-- /wp:paragraph -->", $panel_routes, "<!-- wp:paragraph --><p><a href=\"/second-page/?q=x\ty\">Open</a></p><!-- /wp:paragraph -->" ),
	array( '<!-- wp:paragraph --><p><a href="/second">Open</a></p><!-- /wp:paragraph -->', $tab_routes, "<!-- wp:paragraph --><p><a href=\"/second-page/\t\">Open</a></p><!-- /wp:paragraph -->" ),
	array( $other, $tab_routes, str_replace( array( '"href":"/second"', 'href="/second"' ), array( '"href":"/second-page/\\t"', "href=\"/second-page/\t\"" ), $other ) ),
) as list( $input, $routes, $expected ) ) {
	$assert( $expected === Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $input, $routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ), 'Output without a covered snapshot is written exactly as main writes it.' );
}
// Inside a snapshot, a destination is written exactly as it is around it.
$tab_mixed = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $menu_block . '<a href="/second">After</a>', $tab_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( array( 'html' => "<a hreflang=\"en\" href=\"/second-page/\t\">Second</a>" ) === ( $panel_copies( $tab_mixed )[ $panel_key ]['saved markup'] ?? null ) && str_ends_with( $tab_mixed, "<a href=\"/second-page/\t\">After</a>" ), 'Snapshot destinations are not percent-encoded.' );
// A snapshot that is also written inside an HTML field has a third copy, so the block keeps the route patterns.
$field_span  = '<span ' . $panel_attr( $panel_state ) . '>Panel</span>';
$field_block = $panel_block( 'accessible-link', array( 'href' => '/second/index.html', 'content' => $field_span, 'contentAttributes' => array( $panel_key => $panel_state ) ), '<a href="/second/index.html">' . $field_span . '</a>' );
$assert( $route_patterns( $field_block, $panel_routes ) === Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $field_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ), 'A snapshot inside an HTML field keeps the route patterns.' );
// Outside snapshot values, output is exactly main's, quotes in raw text and resolved queries included.
$rewrite_panels = static fn( string $content, array $routes ): string => Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $content, $routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( '<xmp>href="/second-page/?x="y""</xmp>' === $rewrite_panels( '<xmp>href="/second/index.html?x=&quot;y&quot;"</xmp>', $panel_routes ), 'Raw text that reads like a link is written as main writes it.' );
$oreilly_input = $shell_block( 'not-json', array( 'data-config' => "{'url':'/second/index.html'}" ) );
$oreilly       = $rewrite_panels( $oreilly_input, array( '/second' => "/second-page/?name=O'Reilly" ) );
$assert( $route_patterns( $oreilly_input, array( '/second' => "/second-page/?name=O'Reilly" ) ) === $oreilly && array( "{'url':'/second-page/?name=O'Reilly'}", "{'url':'/second-page/?name=O'Reilly'}" ) === ( $shell_copies( $oreilly )['data-config'] ?? null ), 'A resolved query with an apostrophe is written as main writes it, in both copies.' );
// A tab inside a snapshot link is written as main writes it, so the browser still reads #menu.
$tab_state = (string) json_encode( array( 'html' => "<a href=\"/second/#m\tenu\">Menu</a>" ), JSON_UNESCAPED_SLASHES );
$tab_shell = $shell_copies( $rewrite_panels( $shell_block( $tab_state, array() ), $panel_routes ) );
$assert( array( 'html' => "<a href=\"/second-page/#m\tenu\">Menu</a>" ) == json_decode( (string) ( $tab_shell[ $existing_key ][0] ?? '' ), true ) && $tab_shell[ $existing_key ][0] === $tab_shell[ $existing_key ][1], 'A tab inside a snapshot fragment is written as resolved in both copies.' );
// Only the exact panel block names given are rewritten; another namespace's block of the same local name keeps main's output.
$foreign_state = '{"html":"<a href=\\"/second\\">Menu</a>"}';
$foreign       = '<!-- wp:third-party/layout-shell ' . $encode_block( array( $panel_key => $foreign_state ) ) . ' --><div class="wp-block-third-party-layout-shell" ' . $panel_attr( $foreign_state ) . ' data-address="second">Menu</div><!-- /wp:third-party/layout-shell -->';
$assert( $foreign !== Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( str_replace( 'third-party/', 'example/', $foreign ), $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS ), 'The same block under a listed name has its snapshot rewritten.' );
$assert( $foreign === $rewrite_panels( $foreign, $panel_routes ), 'A block from another namespace is left as main leaves it.' );
// Only the value bytes of a snapshot attribute change; its spacing stays as written.
$spaced       = str_replace( $existing_key . '="', $existing_key . ' = "', $shell_block( $existing, array() ) );
$spaced_after = $rewrite_panels( $spaced, $panel_routes );
$assert( str_contains( $spaced_after, $existing_key . ' = "' ) && $wrapper_after === json_decode( (string) ( $shell_copies( $spaced_after )[ $existing_key ][1] ?? '' ), true ), 'A spaced snapshot attribute keeps its spacing and is rewritten.' );
// The attribute JSON scan uses no regular expression, so a large snapshot is read
// whatever the PCRE backtrack limit, as with PCRE JIT off.
$scan        = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'json_snapshot_values' );
$large_state = (string) json_encode( array( 'html' => '<a href="/second/index.html">Second</a><pre>' . str_repeat( 'C:\\Floors\\"quoted"\\ ', 60000 ) . '</pre>' ), JSON_UNESCAPED_SLASHES );
$limit       = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '1000' );
$large_found = $scan->invoke( null, $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $large_state ) ) ) ), 0 );
ini_set( 'pcre.backtrack_limit', (string) $limit );
$assert( is_array( $large_found ) && 1 === count( $large_found ) && $large_state === $large_found[0][2], 'A large snapshot is read without depending on the PCRE backtrack limit.' );
unset( $large_state, $large_found );
// The route patterns' output is kept whole, so an earlier rewrite that leaves text
// looking like an escaped attribute opener matches as it does on main.
$menu_for   = static fn( string $state ): string => $panel_block( 'authored-button', array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $state ) ) ), '<button ' . $panel_attr( $state ) . '>Menu</button>' );
$opener_doc = '<a href="/first?ref=href=&#92;u0022/second?carry=">Before</a>' . $menu_for( (string) json_encode( array( 'html' => '<a href="/second/index.html">Menu</a>' ), JSON_UNESCAPED_SLASHES ) );
$opener_rt  = $panel_routes + array( '/first' => '/one/' );
$opener_out = $rewrite_panels( $opener_doc, $opener_rt );
$assert( explode( '<!-- wp:', $route_patterns( $opener_doc, $opener_rt ) )[0] === explode( '<!-- wp:', $opener_out )[0], 'Text before a panel that an earlier pattern turns into an escaped opener is written as main writes it.' );
$assert( ! str_contains( $opener_out, '/second/index.html' ), 'The panel after that text is still rewritten.' );
// Inside a snapshot only links change: an accessible label that reads like a link stays.
$label_html = '<a href="/second/index.html" aria-label="Example href=&quot;/second/index.html&quot;&gt;">Menu</a>';
$label_out  = $rewrite_panels( $menu_for( (string) json_encode( array( 'html' => $label_html ), JSON_UNESCAPED_SLASHES ) ), $panel_routes );
foreach ( $panel_copies( $label_out )[ $panel_key ] ?? array( 'missing' => null ) as $copy => $decoded ) {
	$label_tag = new WP_HTML_Tag_Processor( (string) ( $decoded['html'] ?? '' ) );
	$label_tag->next_tag( 'a' );
	$assert( '/second-page/' === $label_tag->get_attribute( 'href' ) && 'Example href="/second/index.html">' === $label_tag->get_attribute( 'aria-label' ), 'A label that reads like a link stays as written in the ' . $copy . '.' );
}
// A prefixed data-*url keeps main's exemption for identifiers.
$record_out = $rewrite_panels( $menu_for( (string) json_encode( array( 'html' => '<menu vendor:data-url="123"><a href="/second/index.html">Menu</a></menu>' ), JSON_UNESCAPED_SLASHES ) ), $panel_routes + array( '/123' => '/record/' ) );
foreach ( $panel_copies( $record_out )[ $panel_key ] ?? array( 'missing' => null ) as $copy => $decoded ) {
	$assert( '<menu vendor:data-url="123"><a href="/second-page/">Menu</a></menu>' === ( $decoded['html'] ?? null ), 'A prefixed data-url identifier stays as written in the ' . $copy . '.' );
}
// A snapshot that does not encode back to its own bytes, such as one with an integer
// beyond 64 bits, keeps the route patterns' output.
$bigint_doc = $menu_for( '{"html":"<a href=\"/second/index.html\">Menu</a>","id":9223372036854775809}' );
$assert( $route_patterns( $bigint_doc, $panel_routes ) === $rewrite_panels( $bigint_doc, $panel_routes ), 'A snapshot with an integer beyond 64 bits keeps the route patterns\' output.' );
// Panel block names from the companion payload, as build_block() registers them.
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';
$names_for = static fn( $payload ): array => Static_Site_Importer_Companion_Plugin::generated_block_names( $payload, Static_Site_Importer_Site_Plan_Persistence::PANEL_BLOCK_TYPES );
$name_base = array( 'schema' => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA, 'site_slug' => 'my-theme' );
$assert( array( 'my-theme/authored-button' ) === $names_for( $name_base + array( 'blocks' => array( array( 'name' => 'authored-button', 'block_json' => (object) array( 'name' => 'my-theme/authored-button' ) ) ) ) ), 'Block metadata given as an object is read.' );
$assert( array() === $names_for( array( 'blocks' => array( array( 'name' => 'authored-button', 'block_json' => array() ) ) ) ), 'A block with no namespace is skipped.' );
$assert( array() === $names_for( $name_base + array( 'blocks' => array( array( 'name' => 'authored-button', 'block_json' => array( 'name' => 'core/button' ) ) ) ) ), 'A core block name is skipped.' );
$assert( array( 'ssi-my-theme/layout-shell' ) === $names_for( $name_base + array( 'blocks' => array( array( 'name' => 'layout-shell' ), array( 'name' => 'authored-label' ) ) ) ), 'A block without declared metadata takes the site namespace.' );
// A panel whose snapshot the route patterns already changed, such as a single-quoted
// link inside an HTML template attribute, keeps their output, so no rewrite is undone.
$template_state = (string) json_encode( array( 'html' => '<a href="/second/index.html">Menu</a><div data-menu-template="&lt;a href=\'/en/index.html\'&gt;English&lt;/a&gt;"></div>' ), JSON_UNESCAPED_SLASHES );
$template_doc   = $panel_block( 'authored-button', array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $template_state ) ) ), '<button ' . $panel_key . '="' . htmlspecialchars( $template_state, ENT_COMPAT, 'UTF-8' ) . '">Menu</button>' );
$template_main  = $route_patterns( $template_doc, $panel_routes );
$assert( $template_doc !== $template_main && $template_main === $rewrite_panels( $template_doc, $panel_routes ), 'A snapshot the route patterns already changed keeps their output.' );
// Blocks pair only with identical blocks, so a delimiter the patterns add before a
// panel and remove after it never moves one menu into another button.
$void_shell   = '<!-- wp:example/layout-shell /-->';
$drift_routes = $panel_routes + array( '/insert' => '/inserted/', '/remove-' . $void_shell . '-end' => '/removed/' );
$drift_doc    = '<a href="/insert?x=&lt;!-- wp:example/layout-shell /--&gt;">Prefix</a>' . $menu_for( (string) json_encode( array( 'html' => '<a href="/keep/">Keep</a>' ), JSON_UNESCAPED_SLASHES ) ) . $menu_for( (string) json_encode( array( 'html' => '<a href="/second/index.html">Second</a>' ), JSON_UNESCAPED_SLASHES ) ) . '<a href="/remove-' . $void_shell . '-end">Suffix</a>';
$drift_out    = $rewrite_panels( $drift_doc, $drift_routes );
$assert( $route_patterns( $drift_doc, $drift_routes ) !== $drift_doc && $route_patterns( $drift_doc, $drift_routes ) === $drift_out, 'A delimiter the patterns add and remove does not move one menu into another button.' );
// Only URL-bearing attribute names are links: data-turbo-action is not, and it adds
// no unresolved target.
$unresolved   = array();
$action_out   = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $menu_for( (string) json_encode( array( 'html' => '<a href="/second/index.html" data-turbo-action="advance">Menu</a><a href="/second/index.html" data-turbo-action="replace">Again</a>' ), JSON_UNESCAPED_SLASHES ) ), $panel_routes + array( '/advance' => '/advance/' ), $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
foreach ( $panel_copies( $action_out )[ $panel_key ] ?? array( 'missing' => null ) as $copy => $decoded ) {
	$assert( '<a href="/second-page/" data-turbo-action="advance">Menu</a><a href="/second-page/" data-turbo-action="replace">Again</a>' === ( $decoded['html'] ?? null ), 'A data-turbo-action value stays as written in the ' . $copy . '.' );
}
$assert( array() === $unresolved, 'A data-turbo-action value adds no unresolved target.' );
// A panel that is only text, inside a textarea, keeps the route patterns' output.
$textarea_doc = '<textarea>' . $menu_for( (string) json_encode( array( 'html' => '<a href="/second/index.html">Menu</a>' ), JSON_UNESCAPED_SLASHES ) ) . '</textarea>';
$assert( $route_patterns( $textarea_doc, $panel_routes ) === $rewrite_panels( $textarea_doc, $panel_routes ), 'Block markup inside a textarea keeps the route patterns\' output.' );
// Diagnostics come only from snapshots that are written: a block that keeps the route
// patterns' output adds none of its panel's unresolved targets.
$unresolved    = array();
$rejected_doc  = $panel_block( 'authored-button', array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => '{"html":"<a href=\"/second/index.html\">Menu</a><a href=\"/missing\">Missing</a>"}' ), array( 'name' => $existing_key, 'value' => '{"html":"<p>Panel</p>","id":9223372036854775808}' ) ) ), '<button ' . $panel_attr( '{"html":"<a href=\"/second/index.html\">Menu</a><a href=\"/missing\">Missing</a>"}' ) . ' ' . $existing_key . '="' . htmlspecialchars( '{"html":"<p>Panel</p>","id":9223372036854775808}', ENT_QUOTES, 'UTF-8' ) . '">Menu</button>' );
$rejected_out  = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $rejected_doc, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( $route_patterns( $rejected_doc, $panel_routes ) === $rejected_out && ! in_array( '/missing', $unresolved, true ), 'A block that keeps the route patterns\' output adds no unresolved target from its panel.' );
// Block markup that is literal text, not live markup, keeps the route patterns' output.
$literal_menu = $menu_for( (string) json_encode( array( 'html' => '<a href="/second/index.html">Menu</a>' ), JSON_UNESCAPED_SLASHES ) );
foreach (
	array(
		'inside a noscript'         => '<noscript>' . $literal_menu . '</noscript>',
		'after a plaintext'         => '<plaintext>' . $literal_menu,
		'inside a quoted attribute' => "<div data-demo='" . $literal_menu . "'></div>",
		'inside a template'         => '<template>' . $literal_menu . '</template>',
	) as $context => $literal_doc
) {
	$assert( $route_patterns( $literal_doc, $panel_routes ) === $rewrite_panels( $literal_doc, $panel_routes ), 'Block markup ' . $context . ' keeps the route patterns\' output.' );
}
// A panel whose HTML holds a noscript keeps the route patterns' output.
$noscript_doc = $menu_for( (string) json_encode( array( 'html' => '<noscript><a href="/second/index.html">Menu</a></noscript>' ), JSON_UNESCAPED_SLASHES ) );
$assert( $route_patterns( $noscript_doc, $panel_routes ) === $rewrite_panels( $noscript_doc, $panel_routes ), 'A panel holding a noscript keeps the route patterns\' output.' );
// Inside panel HTML only href on links and areas changes: action, data-href and
// data-*url values, numeric ones included, stay as written.
$unresolved = array();
$narrow_out = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $menu_for( (string) json_encode( array( 'html' => '<a href="/second/index.html">Menu</a><button action="advance">Go</button><button data-href="123">Pick</button><form action="/second/index.html"></form><div data-url="/second/index.html"></div>' ), JSON_UNESCAPED_SLASHES ) ), $panel_routes + array( '/advance' => '/advance/', '/123' => '/record/' ), $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
foreach ( $panel_copies( $narrow_out )[ $panel_key ] ?? array( 'missing' => null ) as $copy => $decoded ) {
	$assert( '<a href="/second-page/">Menu</a><button action="advance">Go</button><button data-href="123">Pick</button><form action="/second/index.html"></form><div data-url="/second/index.html"></div>' === ( $decoded['html'] ?? null ), 'Only the link href changes in the ' . $copy . '.' );
}
$assert( array() === $unresolved, 'Attributes that stay as written add no unresolved target.' );
// Memory: two hundred panels in a few MB stay close to the route patterns' own peak.
$paragraph_fill = str_repeat( '<!-- wp:paragraph --><p>Ordinary copy without links. <a href="/second/index.html">Link</a></p><!-- /wp:paragraph -->', 150 );
$many_panels    = str_repeat( $menu_block . $paragraph_fill, 200 );
$route_patterns( $many_panels, $panel_routes );
$rewrite_panels( $many_panels, $panel_routes );
memory_reset_peak_usage();
$memory_before = memory_get_usage();
$route_patterns( $many_panels, $panel_routes );
$main_peak = memory_get_peak_usage() - $memory_before;
memory_reset_peak_usage();
$memory_before = memory_get_usage();
$panels_after  = $rewrite_panels( $many_panels, $panel_routes );
$head_peak     = memory_get_peak_usage() - $memory_before;
$assert( ! str_contains( $panels_after, '/second/index.html' ), 'Every link in two hundred panels and the paragraphs around them is rewritten.' );
$assert( $head_peak < $main_peak + strlen( $many_panels ), 'Two hundred panels in ' . round( strlen( $many_panels ) / 1048576, 1 ) . ' MB stay within one document size of the route patterns\' peak (' . round( $main_peak / 1048576, 1 ) . ' MB against ' . round( $head_peak / 1048576, 1 ) . ' MB).' );
unset( $many_panels, $panels_after );
// Memory: one menu after 3 MB of linked paragraphs is spliced into the route patterns'
// output, with no slices of the whole document.
$linked_page = str_repeat( '<!-- wp:paragraph --><p>Ordinary copy without links. <a href="/second/index.html">Link</a></p><!-- /wp:paragraph -->', 27000 ) . $menu_block;
$route_patterns( $linked_page, $panel_routes );
$rewrite_panels( $linked_page, $panel_routes );
memory_reset_peak_usage();
$memory_before = memory_get_usage();
$route_patterns( $linked_page, $panel_routes );
$main_peak = memory_get_peak_usage() - $memory_before;
memory_reset_peak_usage();
$memory_before = memory_get_usage();
$linked_after  = $rewrite_panels( $linked_page, $panel_routes );
$head_peak     = memory_get_peak_usage() - $memory_before;
$assert( ! str_contains( $linked_after, '/second/index.html' ), 'One menu after 3 MB of linked paragraphs is rewritten with them.' );
$assert( $head_peak < $main_peak + strlen( $linked_page ) * 0.8, 'One menu after ' . round( strlen( $linked_page ) / 1048576, 1 ) . ' MB stays within 80% of the document size of the route patterns\' peak (' . round( $main_peak / 1048576, 1 ) . ' MB against ' . round( $head_peak / 1048576, 1 ) . ' MB).' );
unset( $linked_page, $linked_after );
// Memory: under a memory limit, rewriting the menu as well as the links of that page
// needs at most 1 MB more headroom than the route patterns alone, since it keeps only
// their output and one spliced copy.
$memory_run = static function ( string $mode, int $headroom ): bool {
	$output = array();
	exec( 'SSI_PANEL_MEMORY_PROBE=' . escapeshellarg( $mode . ':' . $headroom ) . ' ' . escapeshellarg( PHP_BINARY ) . ' -d memory_limit=64M ' . escapeshellarg( __FILE__ ) . ' 2>/dev/null', $output, $status );
	return 0 === $status && array( 'ok' ) === $output;
};
$low  = 0;
$high = 16 << 20;
$assert( $memory_run( 'main', $high ), 'The route patterns rewrite 3 MB of linked paragraphs with 16 MB of headroom.' );
while ( $high - $low > 128 << 10 ) {
	$middle = intdiv( $low + $high, 2 );
	if ( $memory_run( 'main', $middle ) ) {
		$high = $middle;
	} else {
		$low = $middle;
	}
}
$assert( $memory_run( 'head', $high + ( 1 << 20 ) ), 'One menu after 3 MB of linked paragraphs is rewritten within 1 MB of the ' . round( $high / 1048576, 2 ) . ' MB of headroom the route patterns need.' );
// Memory: when the route patterns change nothing, one menu after 3 MB of plain
// paragraphs costs one copy of the document, built without copied slices.
$plain_page = str_repeat( '<!-- wp:paragraph --><p>Ordinary copy without links, repeated for size.</p><!-- /wp:paragraph -->', 33000 ) . $menu_block;
$rewrite_panels( $plain_page, $panel_routes );
memory_reset_peak_usage();
$memory_before = memory_get_usage();
$plain_after   = $rewrite_panels( $plain_page, $panel_routes );
$head_peak     = memory_get_peak_usage() - $memory_before;
$assert( ! str_contains( $plain_after, '/second/index.html' ), 'One menu after 3 MB of plain paragraphs is rewritten.' );
$assert( $head_peak < strlen( $plain_page ) * 1.5, 'One menu after ' . round( strlen( $plain_page ) / 1048576, 1 ) . ' MB of plain paragraphs costs under one and a half document sizes (' . round( $head_peak / 1048576, 1 ) . ' MB).' );
unset( $plain_page, $plain_after );
// Snapshot copies that disagree between the comment and the saved markup keep the route patterns.
$many_entries = array_fill( 0, 5000, array( 'name' => $panel_key, 'value' => $panel_state ) );
$many_input   = $panel_block( 'authored-button', array( 'sourceAttributes' => $many_entries ), '<button ' . $panel_attr( $panel_state ) . '>Menu</button>' );
$started      = microtime( true );
$many         = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $many_input, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( microtime( true ) - $started < 0.5, 'Five thousand snapshots on one block are read in under half a second.' );
$assert( $route_patterns( $many_input, $panel_routes ) === $many, 'Snapshot copies that disagree keep the route patterns.' );
// Many covered blocks: each snapshot is decoded and encoded once.
$started = microtime( true );
$many    = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( str_repeat( $menu_block, 1000 ), $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html', panel_blocks: EXAMPLE_PANEL_BLOCKS );
$assert( microtime( true ) - $started < 0.5, 'A thousand panel blocks rewrite in under half a second.' );
$assert( 2000 === substr_count( $many, '/second-page/' ) && ! str_contains( $many, '/second/index.html' ), 'Both snapshot copies of every panel block are rewritten.' );
// Order-of-magnitude guard: one pass over the document, however many blocks precede the panel.
$paragraphs = str_repeat( '<!-- wp:paragraph --><p>Body text with <a href="/second/index.html">a link</a>.</p><!-- /wp:paragraph -->', 30000 );
$started    = microtime( true );
$timed      = $shape_case( 'A panel after a few MB of paragraphs', $paragraphs . '<!-- wp:example/authored-button ' . $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ) . ' -->' . $markup, array() );
$assert( microtime( true ) - $started < 1.0, 'A panel after ' . round( strlen( $paragraphs ) / 1048576, 1 ) . ' MB of paragraphs rewrites in under a second.' );
$assert( 30000 === substr_count( $timed, '<a href="/second-page/">' ), 'Paragraph links still resolve.' );
$unresolved = array();
foreach ( array(
	'/oman\\u002d\\u002d-unterwegs/index.html' => '/oman---unterwegs',
	'\\/caf\\u00e9\\u0026\\u003c\\u003e\\u0022/index.html' => '/café&<>"',
) as $escaped_route => $route ) {
	foreach ( array( '?page_id=7', '/local--page?label=<a>&quote="yes"' ) as $target ) {
		$block = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references(
			'<!-- wp:navigation-link {"label":"Oman","url":"' . $escaped_route . '"} /-->',
			array( $route => $target ),
			$unresolved,
			'https://source.example'
		);
		if ( function_exists( 'parse_blocks' ) ) {
			$parsed = parse_blocks( $block );
			$assert( 'core/navigation-link' === $parsed[0]['blockName'] && $target === $parsed[0]['attrs']['url'], 'Rewritten navigation block parses with its resolved URL.' );
		} else {
			$assert( 1 === preg_match( '/^<!-- wp:navigation-link (\{.*\}) \/-->$/s', $block, $attributes ), 'Navigation block wrapper survives rewriting.' );
			$decoded = json_decode( $attributes[1], true );
			$assert( is_array( $decoded ) && array( 'label' => 'Oman', 'url' => $target ) === $decoded, 'Rewritten block attribute JSON decodes to the resolved URL.' );
		}
		$assert( ! str_contains( substr( $block, 4, -3 ), '--' ), 'Block attributes never contain a raw comment delimiter.' );
	}
}
$plain = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="/oman---unterwegs/index.html">Oman</a>', array( '/oman---unterwegs' => '?page_id=7' ), $unresolved );
$assert( '<a href="?page_id=7">Oman</a>' === $plain, 'Plain HTML links containing double hyphens still resolve.' );
$assert( array() === $unresolved, 'Escaped block routes and plain HTML routes leave nothing unresolved.' );
$assert( '/?p=42' === Static_Site_Importer_Site_Plan_Persistence::scoped_product_reference( '/groups/tools/items/field-kit', array( '/items/field-kit' => '/?p=42' ) ), 'Scoped matching keys on the product route resource segment, not a platform.' );
$assert( ! Static_Site_Importer_Site_Plan_Persistence::same_source_origin( 'https://source.example:444/products/field-kit', 'https://source.example/' ), 'Other ports are cross-origin.' );
$assert( ! Static_Site_Importer_Site_Plan_Persistence::same_source_origin( 'http://source.example/products/field-kit', 'https://source.example/' ), 'Other schemes are cross-origin.' );
$GLOBALS['entity_link_content'] = '<!-- wp:navigation-link {"url":"/nav-missing"} /-->';
$navigation_state = $state;
$navigation_state['ordered_pages'] = array();
$navigation_state['resolved']['writes'] = array();
$navigation_state['applied']['navigation_entities'] = array( array( 'id' => 99, 'source_path' => 'website/index.html' ) );
$assert( true === Static_Site_Importer_Site_Plan_Persistence::rewrite_materialized_route_links( $navigation_state ), 'Navigation-only rewrite completes.' );
$assert( 'website/index.html' === $navigation_state['applied']['unresolved_link_targets']['/nav-missing']['source_path'], 'A link owned only by wp_navigation contributes a distinct target with its linking source.' );
foreach ( array(
	'slug normalized from source folder' => array( 'website/a---b/index.html', '/a-b', '/a\\u002d\\u002d-b/index.html' ),
	'slug lowercased from source folder' => array( 'website/Tools/index.html', '/tools', '/Tools/index.html' ),
) as $case => list( $source, $route, $href ) ) {
	$GLOBALS['entity_link_content']     = '<!-- wp:navigation-link {"label":"X","url":"' . $href . '"} /-->';
	$navigation_state['ordered_pages']  = array(
		array(
			'source_path'          => $source,
			'route'                => array( 'path' => $route ),
			'skip_materialization' => true,
		),
	);
	$navigation_state['source_ids']     = array( $source => 7 );
	$assert( true === Static_Site_Importer_Site_Plan_Persistence::rewrite_materialized_route_links( $navigation_state ), $case . ': navigation-only rewrite completes.' );
	$assert( '<!-- wp:navigation-link {"label":"X","url":"/entity/7/"} /-->' === $GLOBALS['entity_link_content'], $case . ': navigation link resolves to the page permalink.' );
	$assert( array() === $navigation_state['applied']['unresolved_link_targets'], $case . ': nothing remains unresolved.' );
}
$fragment_targets = array();
$missing_routes = array();
Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references(
	'<a href="#contact">Contact</a><a href="#top">Top</a><a href="#">Empty</a><a href="about.html#team">Team</a><a href="about.html#team">Again</a>',
	array( '/' => '/?page_id=1', '/about.html' => '/?page_id=2' ),
	$missing_routes,
	'',
	array(),
	'/index.html',
	array( '/?page_id=1' => array( 'route' => '/', 'ids' => array( 'contact' => true ) ), '/?page_id=2' => array( 'route' => '/about', 'ids' => array() ) ),
	$fragment_targets
);
$assert( array() === $missing_routes && 2 === count( $fragment_targets ) && 'team' === $fragment_targets[0]['fragment'], 'Fragment validation checks the target page, skips resolved and special anchors, and retains observations for distinct-target aggregation.' );
echo "Materialized entity links smoke passed.\n";
