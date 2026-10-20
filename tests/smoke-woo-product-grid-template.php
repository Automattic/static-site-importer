<?php
/**
 * Smoke coverage for a product grid whose cards become a WooCommerce loop.
 *
 * A `generic/product-grids/v1` grid carries one source card as an item
 * template and the per-product slot values. The WooCommerce product-grid
 * adapter replaces the whole grid region with the source grid element around
 * a `woocommerce/product-collection` whose `product-template` is that card:
 * the name and price slots become live product blocks, the other slots are
 * filled per product at render time, and the add control is wired to
 * WooCommerce's add-to-cart. The grid supersedes every per-card binding inside
 * it, and retires the script hook that used to redraw the cards. A grid whose
 * hook is not an attribute or id selector keeps its per-card bindings.
 *
 * Run: STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-woo-product-grid-template.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// Block parsing/serialization and the HTML API come from WordPress Core.
$wp_includes = rtrim( (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ), '/\\' ) . '/wp-includes/';
if ( ! is_readable( $wp_includes . 'blocks.php' ) ) {
	fwrite( STDERR, "FAIL: WordPress Core is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 1 );
}
foreach ( array( 'plugin.php', 'utf8.php', 'compat-utf8.php', 'l10n.php', 'formatting.php', 'kses.php', 'class-wp-token-map.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php', 'blocks.php' ) as $wp_file ) {
	require_once $wp_includes . $wp_file;
}

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
	public function get_error_data(): mixed {
		return $this->data; }
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function _doing_it_wrong(): void {}
function is_utf8_charset(): bool {
	return true; }
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

// Seeded products resolve by slug.
class WP_Post {
	public function __construct( public int $ID ) {}
}
$GLOBALS['ssi_test_products'] = array(
	'alpha-item' => 11,
	'beta-item'  => 12,
);
function get_page_by_path( string $slug ): ?WP_Post {
	return isset( $GLOBALS['ssi_test_products'][ $slug ] ) ? new WP_Post( $GLOBALS['ssi_test_products'][ $slug ] ) : null;
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-preparation.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// Two source cards: a chip, a name, a blurb, a price and an add control.
$card = static fn( string $name, string $chip, string $blurb, string $price, string $id ): string => '<!-- wp:group {"className":"card","tagName":"article","metadata":{"name":"' . $name . '"}} --><article class="wp-block-group card">'
	. '<!-- wp:paragraph {"className":"chip"} --><p class="chip">' . $chip . '</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading {"level":3,"className":"card-title"} --><h3 class="wp-block-heading card-title">' . $name . '</h3><!-- /wp:heading -->'
	. '<!-- wp:paragraph {"className":"blurb"} --><p class="blurb">' . $blurb . '</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph {"className":"price"} --><p class="price">' . $price . '</p><!-- /wp:paragraph -->'
	. '<!-- wp:html --><button class="btn-add" data-add="' . $id . '">Add to cart</button><!-- /wp:html -->'
	. '</article><!-- /wp:group -->';
$alpha    = $card( 'Alpha Item', 'Sunny', 'Alpha blurb', '$10.00', 'a' );
$beta     = $card( 'Beta Item', 'Shady', 'Beta blurb', '$12.00', 'b' );
$grid_raw = '<!-- wp:group {"className":"grid","metadata":{"attributes":{"data-shop-grid":""}}} --><div class="wp-block-group grid" data-shop-grid>' . $alpha . $beta . '</div><!-- /wp:group -->';
$page     = '<!-- wp:heading --><h2 class="wp-block-heading">Shop</h2><!-- /wp:heading -->' . $grid_raw . '<!-- wp:html --><button class="btn-add" data-add="a">Add to cart</button><!-- /wp:html -->';
$controls = static fn( string $id, int $occurrence ): array => array(
	'schema'              => 'generic/block-binding/v1',
	'source_path'         => 'shop.html',
	'search_block_markup' => '<!-- wp:html --><button class="btn-add" data-add="' . $id . '">Add to cart</button><!-- /wp:html -->',
	'occurrence'          => $occurrence,
	'role'                => 'commerce_controls',
);
$grid     = array(
	'source_path'   => 'shop.html',
	'selector'      => 'main > div:nth-of-type(1)',
	'item_template' => array(
		'schema'       => 'generic/collection-item-template/v1',
		'block_markup' => $alpha,
		'slots'        => array(
			array(
				'id'   => 'slot-0',
				'role' => 'field',
				'path' => array( 0 ),
			),
			array(
				'id'   => 'slot-1',
				'role' => 'name',
				'path' => array( 1 ),
			),
			array(
				'id'   => 'slot-2',
				'role' => 'description',
				'path' => array( 2 ),
			),
			array(
				'id'   => 'slot-3',
				'role' => 'price',
				'path' => array( 3 ),
			),
			array(
				'id'   => 'slot-4',
				'role' => 'add_to_cart',
				'path' => array( 4 ),
			),
		),
	),
	'items'         => array(
		array(
			'product' => 'alpha-item',
			'slots'   => array( 'slot-0' => array( 'markup' => '<!-- wp:paragraph {"className":"chip"} --><p class="chip">Sunny</p><!-- /wp:paragraph -->' ) ),
		),
		array(
			'product' => 'beta-item',
			'slots'   => array( 'slot-0' => array( 'markup' => '<!-- wp:paragraph {"className":"chip"} --><p class="chip">Shady</p><!-- /wp:paragraph -->' ) ),
		),
	),
	'bindings'      => array(
		array(
			'schema'                       => 'generic/block-binding/v1',
			'source_path'                  => 'shop.html',
			'search_block_markup'          => $grid_raw,
			'occurrence'                   => 1,
			'role'                         => 'commerce_product_grid',
			'superseded_runtime_selectors' => array( '[data-shop-grid]' ),
		),
	),
);
$grid_id  = str_repeat( 'g', 64 );
$prod_id  = str_repeat( 'b', 64 );
$plan     = array(
	'pages'                => array(
		array(
			'source_path'           => 'shop.html',
			'resolved_block_markup' => $page,
		),
	),
	'runtime_declarations' => array(
		array(
			'kind'                    => 'dependency',
			'capability'              => 'shop',
			'reconciliation_identity' => str_repeat( 'a', 64 ),
			'required_for'            => array( 'entity_collection:products' ),
		),
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'products',
			'reconciliation_identity' => $prod_id,
			'payload'                 => array(
				'schema'   => 'generic/products/v1',
				'entities' => array(
					array(
						'name'          => 'Alpha Item',
						'slug'          => 'alpha-item',
						'regular_price' => '10.00',
						// One control inside the grid, one outside it.
						'bindings'      => array( $controls( 'a', 1 ), $controls( 'a', 2 ) ),
					),
					array(
						'name'          => 'Beta Item',
						'slug'          => 'beta-item',
						'regular_price' => '12.00',
						'bindings'      => array( $controls( 'b', 1 ) ),
					),
				),
			),
		),
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'product_grids',
			'reconciliation_identity' => $grid_id,
			'payload'                 => array(
				'schema'   => 'generic/product-grids/v1',
				'entities' => array( $grid ),
			),
		),
	),
);

// --- 1. The grid collection resolves to the product-grid adapter.
$adapter = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'product_grids' );
$assert( 'woocommerce_product_grid' === ( $adapter['id'] ?? '' ), 'product-grids-resolve-to-the-woo-product-grid-adapter', (string) ( $adapter['id'] ?? '' ) );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ) && 1 === count( $lifecycle['entities'][ $grid_id ]['manifest']['product_grids'] ?? array() ), 'grid-declaration-plans-and-validates', is_wp_error( $lifecycle ) ? $lifecycle->get_error_message() . ' ' . wp_json_encode( $lifecycle->get_error_data() ) : '' );

// --- 2. The grid supersedes the card controls inside it, not the one outside.
$adopted  = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_grids( $lifecycle, $plan );
$products = $adopted['entities'][ $prod_id ]['manifest']['products'] ?? array();
$assert( array( 2 ) === array_column( $products[0]['bindings'] ?? array(), 'occurrence' ), 'control-inside-the-grid-is-superseded-and-the-outside-one-kept', (string) wp_json_encode( $products[0]['bindings'] ?? null ) );
$assert( array() === ( $products[1]['bindings'] ?? null ), 'every-card-control-inside-the-grid-is-superseded' );

// --- 3. The grid region becomes the source grid around a product loop of the card.
$mapped = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $adopted['entities'][ $grid_id ]['manifest'] );
$assert( 'mapped' === ( $mapped['product_grids'][0]['status'] ?? '' ), 'retirable-grid-maps', (string) wp_json_encode( $mapped ) );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $grid_id => $adopted['entities'][ $grid_id ] ) ), array( $grid_id => $mapped ) );
$record   = is_array( $bindings ) ? ( $bindings[0] ?? array() ) : array();
$markup   = (string) ( $record['replacement_block_markup'] ?? '' );
$assert( 1 === count( (array) $bindings ) && $grid_raw === ( $record['search_block_markup'] ?? '' ) && 'commerce_product_grid' === ( $record['role'] ?? '' ), 'grid-binding-replaces-the-whole-grid-region', (string) wp_json_encode( $bindings ) );
$assert( array( '[data-shop-grid]' ) === ( $record['superseded_runtime_selectors'] ?? null ) && '' !== ( $record['fallback_reconciliation_identity'] ?? '' ), 'grid-binding-retires-the-script-hook-and-resolves-the-grid-fallback' );
$outer = parse_blocks( $markup )[0] ?? array();
$assert( 'core/group' === ( $outer['blockName'] ?? '' ) && 'grid' === ( $outer['attrs']['className'] ?? '' ) && ! str_contains( (string) $outer['innerContent'][0], 'data-shop-grid' ) && ! isset( $outer['attrs']['metadata']['attributes']['data-shop-grid'] ), 'grid-element-keeps-its-design-without-the-script-hook', (string) wp_json_encode( $outer['attrs'] ?? null ) . ' ' . ( $outer['innerContent'][0] ?? '' ) );
$loop = $outer['innerBlocks'][0] ?? array();
$assert( 'woocommerce/product-collection' === ( $loop['blockName'] ?? '' ) && array( 11, 12 ) === ( $loop['attrs']['query']['woocommerceHandPickedProducts'] ?? null ) && 'ssi-item-template-loop' === ( $loop['attrs']['className'] ?? '' ) && ! str_contains( $markup, 'ssi-commerce-control' ), 'loop-hand-picks-the-grid-products-in-source-order', (string) wp_json_encode( $loop['attrs'] ?? null ) );
$template = $loop['innerBlocks'][0]['innerBlocks'][0] ?? array();
$names    = array_column( $template['innerBlocks'] ?? array(), 'blockName' );
$assert( 'woocommerce/product-template' === ( $loop['innerBlocks'][0]['blockName'] ?? '' ) && 'card' === ( $template['attrs']['className'] ?? '' ) && ! isset( $template['attrs']['metadata']['name'] ), 'product-template-is-the-source-card', (string) wp_json_encode( $template['attrs'] ?? null ) );
$assert( array( 'core/paragraph', 'core/post-title', 'core/paragraph', 'woocommerce/product-price', 'core/html' ) === $names, 'name-and-price-slots-become-live-product-blocks', (string) wp_json_encode( $names ) );
$title = $template['innerBlocks'][1]['attrs'] ?? array();
$price = $template['innerBlocks'][3]['attrs'] ?? array();
$assert( 3 === ( $title['level'] ?? 0 ) && 'card-title' === ( $title['className'] ?? '' ) && 'price' === ( $price['className'] ?? '' ), 'live-product-blocks-keep-the-source-classes', (string) wp_json_encode( array( $title, $price ) ) );
$field = $template['innerBlocks'][0]['attrs']['metadata'][ Static_Site_Importer_Woo_Product_Grid_Materializer::SLOT_KEY ] ?? array();
$assert( 'field' === ( $field['role'] ?? '' ) && array( '11', '12' ) === array_map( 'strval', array_keys( $field['values'] ?? array() ) ) && str_contains( (string) ( $field['values'][12] ?? '' ), 'Shady' ), 'field-slot-carries-each-products-own-source-block', (string) wp_json_encode( $field ) );

// --- 4. Render time: the add control joins WooCommerce's add-to-cart.
class WC_Product {
	public function __construct( private int $id ) {}
	public function get_id(): int {
		return $this->id; }
	public function is_purchasable(): bool {
		return true; }
	public function is_in_stock(): bool {
		return true; }
	public function supports( string $feature ): bool {
		return 'ajax_add_to_cart' === $feature; }
	public function get_short_description(): string {
		return 'Live <b>blurb</b>'; }
	public function get_description(): string {
		return ''; }
	public function add_to_cart_url(): string {
		return '?add-to-cart=' . $this->id; }
}
function wc_get_product( int $id ): WC_Product {
	return new WC_Product( $id ); }
function get_the_ID(): int {
	return 12; }
$slot_block = static fn( string $role, array $extra = array() ): array => array( 'attrs' => array( 'metadata' => array( Static_Site_Importer_Woo_Product_Grid_Materializer::SLOT_KEY => array_merge( array( 'role' => $role ), $extra ) ) ) );
$button     = Static_Site_Importer_Woo_Product_Grid_Materializer::render_slot( '<button class="btn-add" data-add="a">Add to cart</button>', $slot_block( 'add_to_cart' ) );
$tags       = new WP_HTML_Tag_Processor( $button );
$tags->next_tag();
$assert( null === $tags->get_attribute( 'data-add' ) && '12' === $tags->get_attribute( 'data-product_id' ) && true === $tags->has_class( 'add_to_cart_button' ) && true === $tags->has_class( 'ajax_add_to_cart' ) && true === $tags->has_class( 'btn-add' ), 'add-control-becomes-a-woocommerce-ajax-add-to-cart-for-the-current-product', $button );
$blurb = Static_Site_Importer_Woo_Product_Grid_Materializer::render_slot( '<p class="blurb">Alpha blurb</p>', $slot_block( 'description' ) );
$assert( '<p class="blurb">Live blurb</p>' === $blurb, 'description-slot-shows-the-live-product-description', $blurb );
$assert( '' === Static_Site_Importer_Woo_Product_Grid_Materializer::render_slot( '<p class="chip">Sunny</p>', $slot_block( 'field', array( 'optional' => true, 'values' => array() ) ) ), 'optional-field-without-a-value-stays-empty' );
$assert( '<p>x</p>' === Static_Site_Importer_Woo_Product_Grid_Materializer::render_slot( '<p>x</p>', array( 'attrs' => array() ) ), 'blocks-outside-a-template-are-untouched' );

// --- 5. A grid whose hook is not an attribute or id selector keeps its cards.
$class_hook = $plan;
$class_hook['runtime_declarations'][2]['payload']['entities'][0]['bindings'][0]['superseded_runtime_selectors'] = array( '.grid' );
$kept = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $class_hook, array() );
$kept = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_grids( $kept, $class_hook );
$assert( array() === $kept['entities'][ $grid_id ]['manifest']['product_grids'] && 2 === count( $kept['entities'][ $prod_id ]['manifest']['products'][0]['bindings'] ), 'class-hooked-grid-adopts-nothing-and-keeps-card-controls' );

// --- 6. A server-rendered grid has no script hook to retire, so it validates
// and is adopted like a hooked grid: the loop supersedes its in-grid controls.
$neutral = $plan;
// The producer omits superseded selectors when no source script owns the grid.
unset( $neutral['runtime_declarations'][2]['payload']['entities'][0]['bindings'][0]['superseded_runtime_selectors'] );
$neutral_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $neutral, array() );
$assert( ! is_wp_error( $neutral_lifecycle ) && 1 === count( $neutral_lifecycle['entities'][ $grid_id ]['manifest']['product_grids'] ?? array() ), 'scriptless-grid-declaration-validates', is_wp_error( $neutral_lifecycle ) ? $neutral_lifecycle->get_error_message() : '' );
$neutral_kept = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_grids( $neutral_lifecycle, $neutral );
$assert( 1 === count( $neutral_kept['entities'][ $grid_id ]['manifest']['product_grids'] ) && 1 === count( $neutral_kept['entities'][ $prod_id ]['manifest']['products'][0]['bindings'] ) && 0 === count( $neutral_kept['entities'][ $prod_id ]['manifest']['products'][1]['bindings'] ), 'scriptless-grid-is-adopted-and-supersedes-in-grid-controls' );

// --- 7. The validator requires an item template and one grid anchor.
$untemplated = $grid;
unset( $untemplated['item_template'] );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_product_grids_manifest( array( 'product_grids' => array( $untemplated, $grid ) ) );
$assert( 1 === count( $validated['product_grids'] ) && '$.product_grids[0]' === ( $validated['errors'][0]['path'] ?? '' ), 'validator-rejects-a-grid-without-an-item-template', (string) wp_json_encode( $validated['errors'] ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Woo product grid template smoke passed (' . $assertions . " assertions).\n";
