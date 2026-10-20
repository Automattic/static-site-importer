<?php
/**
 * Smoke coverage for a store listing served as WooCommerce's product archive.
 *
 * A `generic/product-listings/v1` listing wraps a product grid and the
 * filter, sort and count controls a source script drove. The listing adopts
 * that grid, and its region becomes one archive `woocommerce/product-collection`:
 * the grid lists the archive's products with the imported card and the sort
 * and count become WooCommerce blocks. Facets (buttons, a select, radios) and
 * a price range keep their source block, wired to the archive's query
 * parameter, so they keep the source design, order and "all" option.
 * The page holding the listing is written as the theme's `archive-product`
 * template, the grid's source order becomes the catalog's menu order (restored
 * on rollback), and the archive lists every product on one page with the shop
 * page's document styles.
 *
 * Run: STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-woo-product-listing-archive.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- A standalone smoke test stubs the WordPress and WooCommerce APIs it exercises.

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );

// Block parsing/serialization and the HTML API come from WordPress Core.
$wp_includes = rtrim( (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ), '/\\' ) . '/wp-includes/';
if ( ! is_readable( $wp_includes . 'blocks.php' ) ) {
	fwrite( STDERR, "FAIL: WordPress Core is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI failure report.
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
// As WordPress's default filters slug a title.
add_filter( 'sanitize_title', 'sanitize_title_with_dashes', 10, 3 );
function mbstring_binary_safe_encoding(): void {}
function reset_mbstring_encoding(): void {}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function _doing_it_wrong(): void {}
function is_utf8_charset(): bool {
	return true; }
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

// Seeded products resolve by slug and keep a menu order.
class WP_Post {
	public function __construct( public int $ID, public int $menu_order = 0 ) {}
}
class WC_Product {}
$GLOBALS['ssi_listing_products'] = array(
	'zeta-item'  => array( 21, 0 ),
	'alpha-item' => array( 22, 7 ),
);
function get_page_by_path( string $slug ): ?WP_Post {
	$row = $GLOBALS['ssi_listing_products'][ $slug ] ?? null;
	return null === $row ? null : new WP_Post( $row[0], $row[1] );
}
function get_post( int $id ): ?WP_Post {
	foreach ( $GLOBALS['ssi_listing_products'] as $row ) {
		if ( $id === $row[0] ) {
			return new WP_Post( $row[0], $row[1] );
		}
	}
	return null;
}
function wp_update_post( array $post ): int {
	foreach ( $GLOBALS['ssi_listing_products'] as $slug => $row ) {
		if ( $post['ID'] === $row[0] ) {
			$GLOBALS['ssi_listing_products'][ $slug ][1] = (int) $post['menu_order'];
		}
	}
	return (int) $post['ID'];
}
function wc_sanitize_taxonomy_name( string $name ): string {
	return strtolower( $name ); }
function wc_attribute_taxonomy_id_by_name( string $name ): int {
	return array(
		'shade' => 6,
		'tone'  => 7,
	)[ $name ] ?? 0; }
$GLOBALS['ssi_listing_scripts'] = array();
function wp_script_is( string $handle ): bool {
	return isset( $GLOBALS['ssi_listing_scripts'][ $handle ] ); }
function wp_register_script(): bool {
	return true; }
function wp_add_inline_script( string $handle, string $data ): bool {
	$GLOBALS['ssi_listing_scripts'][ $handle ] = $data;
	return true;
}
function wp_enqueue_script(): void {}
define( 'STATIC_SITE_IMPORTER_VERSION', 'test' );
// The shop archive request.
$GLOBALS['ssi_listing_theme_file'] = '';
function get_theme_file_path( string $file ): string {
	return '' === $GLOBALS['ssi_listing_theme_file'] ? '/nonexistent/' . $file : $GLOBALS['ssi_listing_theme_file']; }
function is_shop(): bool {
	return true; }
function is_product_taxonomy(): bool {
	return false; }
function wc_get_page_id( string $name ): int {
	return 'shop' === $name ? 6 : 0; }

/** A block's saved markup, as rendered without dynamic callbacks. */
function render_block_stub( array $block ): string {
	return (string) $block['innerHTML'];
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

// A shop listing: a filter sidebar, then a toolbar and the product grid.
$card         = static fn( string $name ): string => '<!-- wp:group {"className":"card","tagName":"article"} --><article class="wp-block-group card"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $name . '</h3><!-- /wp:heading --></article><!-- /wp:group -->';
$html         = static fn( string $markup ): string => '<!-- wp:html -->' . $markup . '<!-- /wp:html -->';
$category     = $html( '<div data-cat-filters><button class="chip on" data-cat="all">All</button><button class="chip" data-cat="Succulents &amp; Cacti">Succulents</button><button class="chip" data-cat="Tools">Tools</button></div>' );
$price        = '<!-- wp:custom/authored-input {"type":"range","min":"10","max":"100"} --><input type="range" min="10" max="100" value="100" data-price-max><!-- /wp:custom/authored-input -->';
$shade        = '<!-- wp:custom/authored-select {"id":"shade"} --><select id="shade" data-shade><option value="any" selected>Any</option><option value="Deep">Deep</option><option value="pale">Pale</option></select><!-- /wp:custom/authored-select -->';
$tone         = $html( '<div data-tone><label><input type="radio" name="tone" value="warm">Warm</label><label><input type="radio" name="tone" value="cool" checked>Cool</label></div>' );
$swatch       = $html( '<div data-swatch></div>' );
$count        = '<!-- wp:paragraph {"className":"count"} --><p class="count" data-count>2 items</p><!-- /wp:paragraph -->';
$sort         = $html( '<select data-sort><option>Featured</option></select>' );
$grid_raw     = '<!-- wp:group {"className":"grid","metadata":{"attributes":{"data-grid":""}}} --><div class="wp-block-group grid" data-grid>' . $card( 'Zeta Item' ) . $card( 'Alpha Item' ) . '</div><!-- /wp:group -->';
$sidebar      = '<!-- wp:group {"tagName":"aside","className":"filters"} --><aside class="wp-block-group filters"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Filter</h3><!-- /wp:heading -->' . $category . $price . $shade . $tone . $swatch . '</aside><!-- /wp:group -->';
$toolbar      = '<!-- wp:group {"className":"toolbar"} --><div class="wp-block-group toolbar">' . $count . $sort . '</div><!-- /wp:group -->';
$region       = '<!-- wp:group {"className":"shop-layout"} --><div class="wp-block-group shop-layout">' . $sidebar . '<!-- wp:group {"className":"results"} --><div class="wp-block-group results">' . $toolbar . $grid_raw . '</div><!-- /wp:group --></div><!-- /wp:group -->';
$listing_page = '<!-- wp:heading --><h1 class="wp-block-heading">Shop</h1><!-- /wp:heading -->' . $region;
$anchor       = static fn( string $search, string $role, string $control = '' ): array => array_filter(
	array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'shop.html',
		'search_block_markup' => $search,
		'occurrence'          => 1,
		'role'                => $role,
		'control'             => $control,
	)
);
$grid         = array(
	'source_path'   => 'shop.html',
	'selector'      => 'main > div > div:nth-of-type(2)',
	'item_template' => array(
		'schema'       => 'generic/collection-item-template/v1',
		'block_markup' => $card( 'Zeta Item' ),
		'slots'        => array(
			array(
				'id'   => 'slot-0',
				'role' => 'name',
				'path' => array( 0 ),
			),
		),
	),
	'items'         => array(
		array( 'product' => 'zeta-item' ),
		array( 'product' => 'alpha-item' ),
	),
	'bindings'      => array( $anchor( $grid_raw, 'commerce_product_grid' ) + array( 'superseded_runtime_selectors' => array( '[data-grid]' ) ) ),
);
$listing      = array(
	'source_path'   => 'shop.html',
	'selector'      => 'main > div',
	'grid_selector' => 'main > div > div:nth-of-type(2)',
	'controls'      => array(
		array(
			'id'      => 'facet:category',
			'kind'    => 'facet',
			'role'    => 'category',
			'shape'   => 'buttons',
			'options' => array(
				array(
					'value' => 'all',
					'reset' => true,
				),
				array( 'value' => 'Succulents & Cacti' ),
				array( 'value' => 'Tools' ),
			),
		),
		array(
			'id'      => 'facet:shade',
			'kind'    => 'facet',
			'role'    => 'attribute',
			'key'     => 'shade',
			'shape'   => 'select',
			'options' => array(
				array(
					'value' => 'any',
					'reset' => true,
				),
				array( 'value' => 'Deep' ),
				array( 'value' => 'pale' ),
			),
		),
		array(
			'id'      => 'facet:tone',
			'kind'    => 'facet',
			'role'    => 'attribute',
			'key'     => 'tone',
			'shape'   => 'radio',
			'options' => array(
				array( 'value' => 'warm' ),
				array( 'value' => 'cool' ),
			),
		),
		array(
			'id'   => 'facet:swatch',
			'kind' => 'facet',
			'role' => 'attribute',
			'key'  => 'swatch',
		),
		array(
			'id'    => 'range:price',
			'kind'  => 'range',
			'field' => 'price',
			'bound' => 'max',
			'min'   => 10,
			'max'   => 100,
		),
		array(
			'id'   => 'sort',
			'kind' => 'sort',
		),
		array(
			'id'   => 'result_count',
			'kind' => 'result_count',
		),
	),
	'bindings'      => array(
		$anchor( $region, 'commerce_listing' ),
		$anchor( $category, 'commerce_listing_control', 'facet:category' ),
		$anchor( $price, 'commerce_listing_control', 'range:price' ),
		$anchor( $shade, 'commerce_listing_control', 'facet:shade' ),
		$anchor( $tone, 'commerce_listing_control', 'facet:tone' ),
		$anchor( $swatch, 'commerce_listing_control', 'facet:swatch' ),
		$anchor( $count, 'commerce_listing_control', 'result_count' ),
		$anchor( $sort, 'commerce_listing_control', 'sort' ),
	),
);
$grid_id      = str_repeat( 'g', 64 );
$list_id      = str_repeat( 'l', 64 );
$page_tpl     = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:post-content {"layout":{"type":"constrained"}} /--><!-- wp:template-part {"slug":"footer"} /-->';
$plan         = array(
	'pages'                => array(
		array(
			'source_path'            => 'shop.html',
			'resolved_block_markup'  => $listing_page,
			'canonical_block_markup' => $listing_page,
		),
	),
	'writes'               => array(
		array(
			'kind'        => 'theme_template',
			'target_path' => 'templates/page.html',
			'payload'     => array(
				'encoding' => 'utf8',
				'data'     => $page_tpl,
			),
		),
	),
	'runtime_declarations' => array(
		array(
			'kind'                    => 'dependency',
			'capability'              => 'shop',
			'reconciliation_identity' => str_repeat( 'a', 64 ),
			'required_for'            => array( 'entity_collection:product_grids', 'entity_collection:product_listings' ),
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
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'product_listings',
			'reconciliation_identity' => $list_id,
			'payload'                 => array(
				'schema'   => 'generic/product-listings/v1',
				'entities' => array( $listing ),
			),
		),
	),
);

// --- 1. Listings resolve to the WooCommerce archive adapter and validate.
$adapter = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'product_listings' );
$assert( 'woocommerce_product_listing' === ( $adapter['id'] ?? '' ), 'product-listings-resolve-to-the-woo-archive-adapter', (string) ( $adapter['id'] ?? '' ) );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ) && 1 === count( $lifecycle['entities'][ $list_id ]['manifest']['product_listings'] ?? array() ), 'listing-declaration-plans-and-validates', is_wp_error( $lifecycle ) ? $lifecycle->get_error_message() . ' ' . wp_json_encode( $lifecycle->get_error_data() ) : '' );
$unnamed                           = $listing;
$unnamed['bindings'][1]['control'] = 'facet:missing';
$validated                         = Static_Site_Importer_Entity_Materializer_Registry::validate_product_listings_manifest( array( 'product_listings' => array( $unnamed, $listing ) ) );
$assert( 1 === count( $validated['product_listings'] ) && '$.product_listings[0]' === ( $validated['errors'][0]['path'] ?? '' ), 'validator-rejects-a-control-anchor-naming-no-control', (string) wp_json_encode( $validated['errors'] ) );

// --- 2. The listing adopts its grid; the grid's own anchor is absorbed.
$adopted = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $lifecycle, $plan );
$owned   = $adopted['entities'][ $list_id ]['manifest']['product_listings'][0] ?? array();
$assert( ( $owned['grid']['selector'] ?? null ) === $grid['selector'] && array( 'commerce_listing' ) === array_column( $owned['bindings'] ?? array(), 'role' ) && 7 === count( $owned['control_bindings'] ?? array() ), 'listing-adopts-its-grid-and-keeps-only-its-region-anchor', (string) wp_json_encode( array_column( $owned['bindings'] ?? array(), 'role' ) ) );
$assert( array() === ( $adopted['entities'][ $grid_id ]['manifest']['product_grids'][0]['bindings'] ?? null ), 'grid-anchor-inside-the-listing-is-absorbed' );
$elsewhere = $plan;
$elsewhere['runtime_declarations'][2]['payload']['entities'][0]['grid_selector'] = 'main > ul';
$unowned = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $elsewhere, array() ), $elsewhere );
$assert( null === ( $unowned['entities'][ $list_id ]['manifest']['product_listings'][0]['grid'] ?? null ) && array() === $unowned['entities'][ $list_id ]['manifest']['product_listings'][0]['bindings'] && 1 === count( $unowned['entities'][ $grid_id ]['manifest']['product_grids'][0]['bindings'] ), 'listing-without-its-grid-keeps-the-source-controls-and-grid' );

// --- 3. Materializing orders the catalog by the grid's source order.
$report = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $adopted['entities'][ $list_id ]['manifest'] );
$assert( 'mapped' === ( $report['product_listings'][0]['status'] ?? '' ), 'adopted-listing-maps', (string) wp_json_encode( $report ) );
$assert( array( 1, 2 ) === array( $GLOBALS['ssi_listing_products']['zeta-item'][1], $GLOBALS['ssi_listing_products']['alpha-item'][1] ), 'products-take-the-grids-source-order-as-menu-order', (string) wp_json_encode( $GLOBALS['ssi_listing_products'] ) );
$unmapped = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, $unowned['entities'][ $list_id ]['manifest'] );
$assert( 'listing_grid_not_adopted' === ( $unmapped['product_listings'][0]['reason'] ?? '' ), 'listing-without-its-grid-is-skipped' );

// --- 4. The region becomes an archive collection of WooCommerce blocks.
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $list_id => $adopted['entities'][ $list_id ] ) ), array( $list_id => $report ) );
$record   = is_array( $bindings ) ? ( $bindings[0] ?? array() ) : array();
$markup   = (string) ( $record['replacement_block_markup'] ?? '' );
$assert( 1 === count( (array) $bindings ) && ( $record['search_block_markup'] ?? '' ) === $region && 'commerce_listing' === ( $record['role'] ?? '' ), 'listing-binding-replaces-the-whole-region', is_wp_error( $bindings ) ? $bindings->get_error_message() : (string) wp_json_encode( $bindings ) );
$outer = parse_blocks( $markup )[0] ?? array();
$assert( 'woocommerce/product-collection' === ( $outer['blockName'] ?? '' ) && true === ( $outer['attrs']['query']['inherit'] ?? null ) && 'shop-layout' === ( $outer['innerBlocks'][0]['attrs']['className'] ?? '' ), 'region-becomes-an-archive-collection-around-the-source-layout', (string) wp_json_encode( $outer['attrs'] ?? null ) );
$aside        = $outer['innerBlocks'][0]['innerBlocks'][0] ?? array();
$filters      = $aside['innerBlocks'] ?? array();
$filter_names = array_column( $filters, 'blockName' );
$wire         = static fn( mixed $block ): mixed => ( is_array( $block ) ? $block : array() )['attrs']['metadata'][ Static_Site_Importer_Woo_Product_Listing_Materializer::CONTROL_KEY ] ?? null;
$assert( 'filters' === ( $aside['attrs']['className'] ?? '' ) && array( 'core/heading', 'core/html', 'custom/authored-input', 'custom/authored-select', 'core/html', 'core/html' ) === $filter_names && ! str_contains( $markup, 'product-filter' ), 'filters-keep-their-source-blocks-in-place', (string) wp_json_encode( $filter_names ) );
$assert(
	array(
		'param'       => 'categories',
		'shape'       => 'buttons',
		'values'      => array( '', 'succulents-cacti', 'tools' ),
		'state_class' => 'on',
	) === $wire( $filters[1] ),
	'category-buttons-keep-their-source-order-and-all-option-wired-to-the-category-filter',
	(string) wp_json_encode( $wire( $filters[1] ) )
);
$assert(
	array(
		'param' => 'max_price',
		'shape' => 'range',
		'clear' => '100',
	) === $wire( $filters[2] ) && str_contains( (string) ( $filters[2]['innerHTML'] ?? '' ), 'data-price-max' ),
	'price-range-keeps-its-source-input-wired-to-max-price',
	(string) wp_json_encode( $wire( $filters[2] ) )
);
$assert(
	array(
		'param'  => 'filter_shade',
		'shape'  => 'select',
		'values' => array( '', 'Deep', 'pale' ),
	) === $wire( $filters[3] ),
	'select-facet-keeps-its-source-select-wired-to-the-attribute-filter',
	(string) wp_json_encode( $wire( $filters[3] ) )
);
$assert(
	array(
		'param'  => 'filter_tone',
		'shape'  => 'radio',
		'values' => array( 'warm', 'cool' ),
	) === $wire( $filters[4] ),
	'radio-facet-keeps-its-source-radios-wired-to-the-attribute-filter',
	(string) wp_json_encode( $wire( $filters[4] ) )
);
$assert( null === $wire( $filters[5] ) && str_contains( (string) ( $filters[5]['innerHTML'] ?? '' ), 'data-swatch' ), 'unseeded-attribute-facet-keeps-its-source-block-unwired' );
$results = $outer['innerBlocks'][0]['innerBlocks'][1] ?? array();
$assert( array( 'woocommerce/product-results-count', 'woocommerce/catalog-sorting' ) === array_column( $results['innerBlocks'][0]['innerBlocks'] ?? array(), 'blockName' ), 'count-and-sort-become-woocommerce-blocks-in-the-toolbar' );
$assert( 'grid' === ( $results['innerBlocks'][1]['attrs']['className'] ?? '' ) && str_contains( $markup, '<!-- wp:woocommerce/product-template' ) && ! str_contains( $markup, 'woocommerceHandPickedProducts' ) && ! str_contains( $markup, 'data-grid' ), 'grid-lists-the-archives-products-with-the-card' );
$assert( 1 === substr_count( $markup, '<!-- wp:woocommerce/product-collection' ), 'grid-shares-the-archive-collection' );

// --- 5. The listing page becomes the theme's product archive template.
$prepared    = $plan;
$reports     = array();
$diagnostics = array();
Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $prepared, $bindings, $reports, $diagnostics );
$archive = array_values( array_filter( $prepared['writes'], static fn( array $write ): bool => 'templates/archive-product.html' === $write['target_path'] ) );
$data    = (string) ( $archive[0]['payload']['data'] ?? '' );
$assert( 1 === count( $archive ) && str_starts_with( $data, '<!-- wp:template-part {"slug":"header"} /--><!-- wp:heading -->' ) && str_contains( $data, '<!-- wp:woocommerce/product-collection' ) && str_ends_with( $data, '<!-- wp:template-part {"slug":"footer"} /-->' ) && ! str_contains( $data, 'wp:post-content' ) && hash( 'sha256', $data ) === $archive[0]['payload_hash'], 'listing-page-is-written-as-the-archive-product-template', $data );

// --- 6. The archive lists every product with the shop page's styles.
$assert( 12 === Static_Site_Importer_Woo_Product_Listing_Materializer::archive_per_page( 12 ) && 0 === Static_Site_Importer_Woo_Product_Listing_Materializer::archive_document_id( 0 ), 'theme-without-an-archive-design-keeps-woocommerce-defaults' );
// Any readable file stands in for the written template.
$GLOBALS['ssi_listing_theme_file'] = __FILE__;
$assert( -1 === Static_Site_Importer_Woo_Product_Listing_Materializer::archive_per_page( 12 ) && 6 === Static_Site_Importer_Woo_Product_Listing_Materializer::archive_document_id( 0 ), 'designed-archive-shows-every-product-with-the-shop-page-styles' );

// --- 7. A wired control renders the requested archive state.
$render               = static fn( mixed $block ): string => ! is_array( $block ) ? '' : Static_Site_Importer_Woo_Product_Listing_Materializer::render_control( render_block_stub( $block ), $block );
$untouched_buttons    = $render( $filters[1] );
$_GET['categories']   = 'tools';
$_GET['filter_shade'] = 'pale,Deep';
$_GET['filter_tone']  = 'warm';
$_GET['max_price']    = '40';
$buttons_html         = $render( $filters[1] );
$select_html          = $render( $filters[3] );
$radio_html           = $render( $filters[4] );
$range_html           = $render( $filters[2] );
$assert( str_contains( $untouched_buttons, '<button aria-pressed="true" data-ssi-listing-value="" class="chip on" data-cat="all">' ) && str_contains( $untouched_buttons, '<button aria-pressed="false" data-ssi-listing-value="tools" class="chip" data-cat="Tools">' ), 'unfiltered-archive-selects-the-all-button', $untouched_buttons );
$assert( str_contains( $buttons_html, 'data-ssi-listing-param="categories"' ) && str_contains( $buttons_html, '<button aria-pressed="false" data-ssi-listing-value="" class="chip" data-cat="all">' ) && str_contains( $buttons_html, '<button aria-pressed="true" data-ssi-listing-value="tools" class="chip on" data-cat="Tools">' ), 'requested-category-button-takes-the-source-selected-state', $buttons_html );
$assert( str_contains( $select_html, 'data-ssi-listing-param="filter_shade"' ) && str_contains( $select_html, '<option value="" >Any</option>' ) && str_contains( $select_html, '<option selected value="pale">Pale</option>' ) && ! str_contains( $select_html, 'value="Deep" selected' ), 'select-shows-the-requested-term-and-clears-at-its-reset-option', $select_html );
$assert( str_contains( $radio_html, 'data-ssi-listing-param="filter_tone"' ) && str_contains( $radio_html, '<input checked type="radio" name="tone" value="warm">' ) && str_contains( $radio_html, '<input type="radio" name="tone" value="cool" >' ), 'radio-checks-the-requested-term', $radio_html );
$assert( str_contains( $range_html, 'value="40"' ) && str_contains( $range_html, 'data-ssi-listing-param="max_price"' ) && str_contains( $range_html, 'data-ssi-listing-clear="100"' ), 'range-shows-the-requested-price-bound', $range_html );
$script = (string) ( $GLOBALS['ssi_listing_scripts']['static-site-importer-listing-controls'] ?? '' );
$assert( str_contains( $script, "closest('[data-ssi-listing-param]')" ) && str_contains( $script, "closest('[data-ssi-listing-value]')" ) && str_contains( $script, 'event.preventDefault()' ) && str_contains( $script, 'searchParams.delete(param)' ) && str_contains( $script, "searchParams.delete('paged')" ), 'choosing-an-option-loads-the-archive-url-with-the-parameter' );
$assert( '<p>x</p>' === Static_Site_Importer_Woo_Product_Listing_Materializer::render_control( '<p>x</p>', array( 'attrs' => array() ) ), 'blocks-without-a-wire-are-untouched' );

// --- 8. Rollback restores the previous menu order.
$rolled = Static_Site_Importer_Entity_Materializer_Registry::rollback( $adapter, $report );
$assert( 'rolled_back' === ( $rolled['status'] ?? '' ) && array( 0, 7 ) === array( $GLOBALS['ssi_listing_products']['zeta-item'][1], $GLOBALS['ssi_listing_products']['alpha-item'][1] ), 'rollback-restores-the-previous-menu-order', (string) wp_json_encode( $GLOBALS['ssi_listing_products'] ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI failure report.
	exit( 1 );
}
echo 'Woo product listing archive smoke passed (' . $assertions . " assertions).\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI summary.
