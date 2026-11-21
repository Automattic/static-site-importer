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
	$block = '<!-- wp:example/panel ' . \Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode( array( 'sourceAttributes' => array( array( 'name' => $name, 'value' => $state ) ) ) ) . ' -->'
		. '<button ' . $name . '="' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '">Menu</button><!-- /wp:example/panel -->';
	$unresolved = array();
	$block      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $block, $routes + array( '/' => '/', '/second' => '/second-page/', '/about--us' => '/about-us/' ), $unresolved, 'https://source.example/', array(), '/index.html' );
	foreach ( $panel_copies( $block )[ $name ] ?? array( 'missing' => null ) as $copy => $decoded ) {
		$assert( $expected === $decoded, $label . ': the ' . $copy . ' decodes to the rewritten panel.' );
	}
	$assert( array() === $unresolved, $label . ': nothing is left unresolved.' );
	$expected_comment = '<!-- wp:example/panel ' . \Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode( array( 'sourceAttributes' => array( array( 'name' => $name, 'value' => (string) json_encode( $expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) ) ) . ' -->';
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
	$block      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' );
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
$markup       = '<button data-blocks-engine-capture-dialog-panel-state="' . htmlspecialchars( $panel_state, ENT_QUOTES, 'UTF-8' ) . '">Menu</button><!-- /wp:example/panel -->';
$panel_key    = 'data-blocks-engine-capture-dialog-panel-state';
$shape_case( 'Two spaces after the comment opener', '<!--  wp:example/panel ' . $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ) . ' -->' . $markup, array( $panel_key => $panel_after ) );
$shape_case( 'Value before name', '<!-- wp:example/panel ' . $encode_block( array( 'sourceAttributes' => array( array( 'value' => $panel_state, 'name' => $panel_key ) ) ) ) . ' -->' . $markup, array( $panel_key => $panel_after ) );
// Core's block parser does not read this delimiter, but the block editor does, so
// neither copy of its snapshot may change.
$raw_close_block = '<!-- wp:example/panel {"label":"a-->b",' . substr( $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ), 1 ) . ' -->' . $markup;
$unresolved      = array();
$assert( $raw_close_block === Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $raw_close_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' ), 'A raw comment closer inside attribute JSON leaves both snapshot copies as written.' );
$anchor = $shape_case(
	'An anchor trigger with its own href',
	'<!-- wp:example/panel ' . $encode_block( array( 'url' => '/second/index.html', 'sourceAttributes' => array( array( 'name' => 'href', 'value' => '/second/index.html' ), array( 'name' => 'hreflang', 'value' => '/en/index.html' ), array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ) . ' -->'
		. '<a href="/second/index.html" hreflang="/en/index.html" xlink:href="/second/index.html" data-href="/second/index.html" data-config=\'{"url":"/second/index.html"}\' ' . $panel_key . '="' . htmlspecialchars( $panel_state, ENT_QUOTES, 'UTF-8' ) . '">Menu</a><!-- /wp:example/panel -->',
	array(
		'href'     => '/second-page/',
		'hreflang' => '/en/index.html',
		$panel_key => $panel_after,
	)
);
$anchor_tag = new WP_HTML_Tag_Processor( $anchor );
$anchor_tag->next_tag();
$assert( '/second-page/' === $anchor_tag->get_attribute( 'xlink:href' ) && '/second-page/' === $anchor_tag->get_attribute( 'data-href' ), 'Other link attributes on a snapshot-bearing tag are rewritten.' );
$assert( '{"url":"/second/index.html"}' === $anchor_tag->get_attribute( 'data-config' ), 'Attributes that are not links stay as written on a snapshot-bearing tag.' );
$assert( str_contains( $anchor, '"url":"/second-page/"' ), 'A sibling url block attribute keeps its route rewrite.' );
// A wrapper block keeps the existing-panel snapshot under its attribute name.
$existing       = (string) json_encode( array( 'resting' => array( 'html' => '<a href="/second/index.html">Second</a>' ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$existing_key   = 'data-blocks-engine-capture-existing-panel';
$wrapper_block  = '<!-- wp:example/shell ' . $encode_block( array( 'wrappers' => array( array( 'tagName' => 'div', 'attributes' => array( 'href' => '/second/index.html', 'data-href' => '/second/index.html', 'data-url' => '/second/index.html', $existing_key => $existing ) ) ) ) ) . ' -->'
	. '<div href="/second/index.html" data-href="/second/index.html" data-url="/second/index.html" ' . $existing_key . '="' . htmlspecialchars( $existing, ENT_QUOTES, 'UTF-8' ) . '"></div><!-- /wp:example/shell -->';
$unresolved     = array();
$wrapper_block  = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $wrapper_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' );
$wrapper_parsed = new WP_Block_Processor( $wrapper_block );
$wrapper_parsed->next_delimiter();
$wrapper_attrs = $wrapper_parsed->allocate_and_return_parsed_attributes()['wrappers'][0]['attributes'] ?? array();
$wrapper_tag   = new WP_HTML_Tag_Processor( $wrapper_block );
$wrapper_tag->next_tag();
$wrapper_after = array( 'resting' => array( 'html' => '<a href="/second-page/">Second</a>' ) );
$assert( $wrapper_after === json_decode( (string) ( $wrapper_attrs[ $existing_key ] ?? '' ), true ) && $wrapper_after === json_decode( (string) $wrapper_tag->get_attribute( $existing_key ), true ), 'A keyed existing-panel snapshot is rewritten in both copies.' );
$assert( '/second-page/' === ( $wrapper_attrs['href'] ?? null ) && '/second-page/' === $wrapper_tag->get_attribute( 'href' ), 'A keyed wrapper href keeps its route rewrite in both copies.' );
foreach ( array( 'data-href', 'data-url' ) as $wrapper_link ) {
	$assert( '/second-page/' === ( $wrapper_attrs[ $wrapper_link ] ?? null ) && '/second-page/' === $wrapper_tag->get_attribute( $wrapper_link ), 'A keyed wrapper ' . $wrapper_link . ' is rewritten in both copies.' );
}
$panel_block = static fn( string $name, array $attributes, string $tag ): string => '<!-- wp:example/' . $name . ' ' . $encode_block( $attributes ) . ' -->' . $tag . '<!-- /wp:example/' . $name . ' -->';
$panel_attr  = static fn( string $state ): string => $panel_key . '="' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '"';
// A block without a snapshot keeps the route patterns, so both href copies agree.
$decoy  = $panel_block( 'button', array( 'sourceAttributes' => array( array( 'name' => 'title', 'value' => 'Example: href=' ), array( 'name' => 'href', 'value' => '/second/index.html' ) ) ), '<a title="Example: href=" href="/second/index.html">Second</a>' );
$copies = $panel_copies( Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $decoy, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' ) );
$assert( $copies['href']['block comment'] === $copies['href']['saved markup'] && 'Example: href=' === $copies['title']['block comment'], 'A block without a snapshot keeps matching href copies.' );
// Title and text that read like links survive verbatim on a snapshot-bearing block.
$labelled = $shape_case(
	'A title that reads like a search link',
	$panel_block( 'button', array( 'sourceAttributes' => array( array( 'name' => 'title', 'value' => 'href="/search?other=' ), array( 'name' => $panel_key, 'value' => $panel_state ) ), 'text' => 'Menu "label"' ), '<button title="href=&quot;/search?other=" ' . $panel_attr( $panel_state ) . '>Menu "label"</button>' ),
	array(
		'title'    => 'href="/search?other=',
		$panel_key => $panel_after,
	)
);
$labelled_blocks = new WP_Block_Processor( $labelled );
$labelled_blocks->next_delimiter();
$assert( 'Menu "label"' === ( $labelled_blocks->allocate_and_return_parsed_attributes()['text'] ?? null ) && str_contains( $labelled, '>Menu "label"</button>' ), 'Block text survives verbatim.' );
// A resolved query with a quote stays inside its attribute.
$quoted = new WP_HTML_Tag_Processor( Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="/second/index.html?label=&quot;quote&quot;">Q</a>', $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' ) );
$quoted->next_tag();
$assert( '/second-page/?label="quote"' === $quoted->get_attribute( 'href' ) && array( 'href' ) === $quoted->get_attribute_names_with_prefix( '' ), 'A quote in a resolved query is escaped in the attribute.' );
// A comment that only looks like a delimiter changes nothing; a delimiter only the
// block editor reads holds its own block, not the rest of the document.
$menu_block = $panel_block( 'menu', array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ), '<button ' . $panel_attr( $panel_state ) . '>Menu</button>' );
$mixed      = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<!-- wp: this is a note -->' . $raw_close_block . $menu_block, $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' );
$assert( str_starts_with( $mixed, '<!-- wp: this is a note -->' . $raw_close_block ), 'The block Core cannot parse stays as written.' );
$assert( $panel_after === ( $panel_copies( substr( $mixed, strlen( '<!-- wp: this is a note -->' . $raw_close_block ) ) )[ $panel_key ]['saved markup'] ?? null ), 'Other panels in the document are still rewritten.' );
$shape_case( 'A panel after a note comment', '<!-- wp: this is a note -->' . $menu_block, array( $panel_key => $panel_after ) );
// Rewriting a panel leaves unrelated JSON as written: empty objects, numeric keys, floats.
$exact_state = '{"html":"<a href=\\"/second/index.html\\">Second</a>","meta":{},"keys":{"0":"a"},"ratio":1.0}';
$exact       = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<!-- wp:example/panel {"meta":{},"keys":{"0":"a"},"ratio":1.0,' . substr( $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $exact_state ) ) ) ), 1 ) . ' --><button ' . $panel_attr( $exact_state ) . '>Menu</button><!-- /wp:example/panel -->', $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' );
$exact_after = str_replace( '/second/index.html', '/second-page/', $exact_state );
$exact_tag   = new WP_HTML_Tag_Processor( $exact );
$exact_tag->next_tag( 'button' );
$assert( str_starts_with( $exact, '<!-- wp:example/panel {"meta":{},"keys":{"0":"a"},"ratio":1.0,' ), 'Block attribute JSON keeps empty objects, numeric keys and floats.' );
$exact_blocks = new WP_Block_Processor( $exact );
$exact_blocks->next_delimiter();
$assert( $exact_after === $exact_tag->get_attribute( $panel_key ) && $exact_after === ( $exact_blocks->allocate_and_return_parsed_attributes()['sourceAttributes'][0]['value'] ?? null ), 'Snapshot JSON keeps empty objects, numeric keys and floats in both copies.' );
// Destinations a browser would turn into another origin are never written.
foreach ( array( "/\t/other.example/new", "/\\other.example/new", ' /new', "/new\n" ) as $destination ) {
	$offsite = $panel_copies( Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $menu_block, array( '/second' => $destination ), $unresolved, 'https://source.example/', array(), '/index.html' ) );
	$assert( json_decode( $panel_state, true ) === $offsite[ $panel_key ]['block comment'] && json_decode( $panel_state, true ) === $offsite[ $panel_key ]['saved markup'], 'Destination ' . json_encode( $destination ) . ' is not written into a panel.' );
}
// Many snapshots on one block: each is decoded and encoded once.
$many_entries = array_fill( 0, 5000, array( 'name' => $panel_key, 'value' => $panel_state ) );
$started      = microtime( true );
$many         = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( $panel_block( 'menu', array( 'sourceAttributes' => $many_entries ), '<button>Menu</button>' ), $panel_routes, $unresolved, 'https://source.example/', array(), '/index.html' );
$assert( microtime( true ) - $started < 0.5, 'Five thousand snapshots on one block rewrite in under half a second.' );
$assert( 5000 === substr_count( $many, '/second-page/' ), 'Every snapshot on the block is rewritten.' );
// Order-of-magnitude guard: one pass over the document, however many blocks precede the panel.
$paragraphs = str_repeat( '<!-- wp:paragraph --><p>Body text with <a href="/second/index.html">a link</a>.</p><!-- /wp:paragraph -->', 30000 );
$started    = microtime( true );
$timed      = $shape_case( 'A panel after a few MB of paragraphs', $paragraphs . '<!-- wp:example/panel ' . $encode_block( array( 'sourceAttributes' => array( array( 'name' => $panel_key, 'value' => $panel_state ) ) ) ) . ' -->' . $markup, array() );
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
