<?php
/** Linked paragraph listing declines through the public import path. */
define( 'SSI_PLAN_HARNESS_ONLY', true );
require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';

require_once __DIR__ . '/fixtures/core-html-api.php';
$core = require __DIR__ . '/fixtures/wordpress-core-root.php';
foreach ( array( 'class-wp-block-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php' ) as $file ) {
	if ( is_file( $core . '/wp-includes/' . $file ) ) {
		require_once $core . '/wp-includes/' . $file;
	}
}
function parse_blocks( $markup ) { return ( new WP_Block_Parser() )->parse( $markup ); }
function serialize_block_attributes( $attrs ) { return json_encode( $attrs ); }
function serialize_block( $block ) {
	$content = '';
	$index = 0;
	foreach ( $block['innerContent'] as $piece ) {
		$content .= is_string( $piece ) ? $piece : serialize_block( $block['innerBlocks'][ $index++ ] );
	}
	if ( ! $block['blockName'] ) { return $content; }
	$name = preg_replace( '~^core/~', '', $block['blockName'] );
	$attrs = empty( $block['attrs'] ) ? '' : ' ' . serialize_block_attributes( $block['attrs'] );
	return '' === $content ? '<!-- wp:' . $name . $attrs . ' /-->' : '<!-- wp:' . $name . $attrs . ' -->' . $content . '<!-- /wp:' . $name . ' -->';
}
function serialize_blocks( $blocks ) { return implode( '', array_map( 'serialize_block', $blocks ) ); }
function register_block_type( $path ) {
	$metadata = json_decode( file_get_contents( $path . '/block.json' ), true );
	return WP_Block_Type_Registry::get_instance()->register( $metadata['name'], $metadata );
}

function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
class WC_Product {}
function wc_attribute_taxonomy_id_by_name( $name ) { return 0; }
function wc_get_product( $id ) { return null; }


function render_block( $block ) { return serialize_block( $block ); }
function get_term_by( $field, $value, $taxonomy ) { return false; }
class WP_Term {}
function has_term( $term, $taxonomy, $id ) { return true; }

$source = 'shop/all/index.html';
$products = array();
$items = array();
$cards = '';
foreach ( array( 'Blue Heron', 'Red Fox', 'Green Moss', 'Gold Leaf' ) as $index => $name ) {
	$slug = sanitize_title( $name );
	$card = '<!-- wp:paragraph {"className":"blocks-engine-synthetic-paragraph blocks-engine-synthetic-anchor-undecorated"} --><p class="blocks-engine-synthetic-paragraph blocks-engine-synthetic-anchor-undecorated"><a class="card" href="/shop/' . $slug . '/"><mark class="tile" role="none"><img src="https://example.test/' . $slug . '.jpg" alt="' . $name . '"></mark> <strong>' . $name . '</strong> $20.00</a></p><!-- /wp:paragraph -->';
	$template_card = $template_card ?? $card;
	$cards .= $card;
	$products[] = array( 'name' => $name, 'slug' => $slug, 'regular_price' => '20.00' );
	$items[] = array( 'product' => $slug, 'slots' => array( 'card' => array( 'markup' => $card ) ) );
}
$grid_markup = '<!-- wp:group {"className":"grid"} --><div class="wp-block-group grid">' . $cards . '</div><!-- /wp:group -->';
$sort = '<!-- wp:html --><label>Sort <select id="sort" name="sort"><option value="featured">Featured</option><option value="price-asc">Price low to high</option></select></label><!-- /wp:html -->';
$region = '<!-- wp:group {"className":"shop"} --><div class="wp-block-group shop">' . $sort . $grid_markup . '</div><!-- /wp:group -->';
$anchor = static fn( $markup, $role ) => array( 'schema' => 'generic/block-binding/v1', 'source_path' => $source, 'search_block_markup' => $markup, 'occurrence' => 1, 'role' => $role );
$grid = array(
	'source_path' => $source,
	'selector' => '.grid',
	'items' => $items,
	'item_template' => array(
		'schema' => 'generic/collection-item-template/v1',
		'block_markup' => $template_card,
		// The producer's slot addresses a child, but this captured card lowered
		// to one opaque paragraph; WooCommerce cannot resolve that slot.
		'slots' => array( array( 'id' => 'card', 'role' => 'field', 'path' => array( 0 ) ) ),
	),
	'bindings' => array( $anchor( $grid_markup, 'commerce_product_grid' ) ),
);
$listing = array( 'source_path' => $source, 'selector' => '.shop', 'grid_selector' => '.grid', 'controls' => array( array( 'id' => 'sort', 'kind' => 'sort', 'options' => array( array( 'value' => 'featured', 'order' => 'default' ), array( 'value' => 'price-asc', 'order' => 'price_asc' ) ) ) ), 'bindings' => array( $anchor( $region, 'commerce_listing' ), $anchor( $sort, 'commerce_listing_control' ) + array( 'control' => 'sort' ) ) );
$declarations = array( array( 'kind' => 'dependency', 'capability' => 'shop', 'source_path' => $source, 'required_for' => array( 'entity_collection:products', 'entity_collection:product_grids', 'entity_collection:product_listings' ) ) );
foreach ( array( 'products' => $products, 'product_grids' => array( $grid ), 'product_listings' => array( $listing ) ) as $type => $rows ) {
	$declarations[] = array( 'kind' => 'entity_collection', 'type' => $type, 'source_path' => $source, 'payload' => array( 'schema' => 'generic/' . str_replace( '_', '-', $type ) . '/v1', 'entities' => $rows ) );
}
// A retained producer block document pins the linked-paragraph lowering independently
// of newer compiler heuristics that may choose a different card representation.
$artifact = array( 'entrypoint' => $source, 'files' => array( array( 'path' => $source, 'content' => '<!DOCTYPE html><html><head><title>Shop all</title></head><body>' . $region . '</body></html>' ) ), 'runtime_declarations' => $declarations );
$compiler = new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
$compiled = $compiler->compile( $artifact )->toArray();
$plan = &$compiled['source_reports']['wordpress_site_plan'];
$plan['runtime_declarations'] = Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations::normalizeList( $declarations );
$plan['runtime_entity_records'] = array();
$plan['runtime_records'] = array();
$plan['pages'][0]['canonical_block_markup'] = $region;
$plan['pages'][0]['content_hash'] = hash( 'sha256', $region );
$bootstrap = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap::functionsPhp( $plan['assets'], array(), $plan['template_parts'], $plan['reference_tokens'], $plan['templates'], $plan['pages'], $plan['menus'], false, $plan['theme']['document_roots'] ?? array() );
foreach ( $plan['writes'] as &$write ) {
	if ( 'functions.php' === $write['target_path'] ) {
		$write['payload']['data'] = $bootstrap;
		$write['payload_hash'] = hash( 'sha256', $bootstrap );
		$write['canonical_payload'] = $bootstrap;
	}
}
unset( $write );
$plan['plan_identity'] = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::planIdentity( $plan );
$view_owner = new Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView();
$view = $view_owner->compact( $view_owner->fromResult( $compiled ) );
foreach ( array( 'core/group', 'core/paragraph', 'core/html' ) as $name ) { WP_Block_Type_Registry::get_instance()->register( $name, array() ); }
$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, array( 'slug' => 'listing-card-decline', 'compiled_artifact_result' => $view, '_static_site_importer_precompiled_source' => true ) );
if ( is_wp_error( $result ) ) { fwrite( STDERR, $result->get_error_message() . ' ' . json_encode( $result->get_error_data() ) . "\n" ); exit( 1 ); }
$receipt = $result['materialization_receipt'];
$assert( 'completed' === $receipt['status'], 'Import completes despite an unexpressible listing card.' );
$rows = array();
$seeded = array();
foreach ( $receipt['completed']['runtime_declarations']['entities'] as $report ) {
	$rows = array_merge( $rows, $report['product_listings'] ?? array() );
	$seeded = array_merge( $seeded, $report['products'] ?? array() );
}
$assert( 1 === count( $rows ) && 'skipped' === $rows[0]['status'] && 'woocommerce_listing_card_unexpressible' === $rows[0]['reason'], 'Listing is deliberately declined, not unresolved.' );
$assert( count( $products ) === count( $seeded ) && array( 'created' ) === array_values( array_unique( array_column( $seeded, 'status' ) ) ), 'Products still seed independently.' );
$page_id = $receipt['completed']['pages'][ $source ];
$assert( $region === get_post_field( 'post_content', $page_id ), 'The entire listing region, including cards and sort select, is preserved byte-for-byte.' );
$assert( array() === $receipt['completed']['runtime_declarations']['entity_bindings'], 'Declined listing emits no binding or archive replacement.' );
$declines = array_values( array_filter( $result['import_report']['diagnostics'], static fn( $row ) => 'provider_entity_declined' === ( $row['code'] ?? '' ) && 'woocommerce_listing_card_unexpressible' === ( $row['reason_code'] ?? '' ) ) );
$assert( 1 === count( $declines ) && $source === $declines[0]['source_path'] && '.shop' === $declines[0]['selector'] && 'product_listing' === $declines[0]['entity_type'], 'Decline diagnostic identifies the listing and reason.' );
echo "PASS: linked paragraph listing decline import\n";
