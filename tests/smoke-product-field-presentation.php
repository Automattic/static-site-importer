<?php
/** Bound field presentation uses the portable runtime, not frozen catalog text. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['test_filters'][ $hook ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); }
	return $value;
}
function add_filter( $hook, $callback, ...$args ) { $GLOBALS['test_filters'][ $hook ][] = $callback; }
function add_action( ...$args ) {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_url( $value ) { return $value; }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function get_the_ID() { return 7; }
function wc_get_product( $id ) { return $GLOBALS['live_product']; }
function wc_price( $price ) { return '<span class="amount">$' . number_format( $price, 2 ) . '</span>'; }
class WC_Product {
	public function get_price_html() { return wc_price( 27.99 ); }
}
class WC_Product_Variable extends WC_Product {
	public $minimum = 4.25;
	public function get_variation_price( $which ) {
		if ( 'min' !== $which ) { throw new RuntimeException( 'Must request minimum' ); }
		return $this->minimum;
	}
}
class WC_Breadcrumb {
	private $crumbs = array();
	public function add_crumb( $name, $url ) { $this->crumbs[] = array( $name, $url ); }
	public function generate() { return array_merge( $this->crumbs, array( array( 'Cat', '/cat' ), array( 'Name', '' ) ) ); }
}
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once $wp_root . '/wp-includes/compat-utf8.php';
require_once $wp_root . '/wp-includes/utf8.php';
require_once $wp_root . '/wp-includes/kses.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once ABSPATH . 'includes/class-static-site-importer-product-field-blocks.php';
require_once ABSPATH . 'includes/class-static-site-importer-product-grid-runtime.php';
$cases = array(
	array( 'from $3.50', array( 'price' => '3.50' ), '<div class="price">old range</div>', 'from $4.25', new WC_Product_Variable() ),
	array( '$27.99', array( 'price' => '27.99' ), '<div class="price">$27.99</div>', '$27.99', new WC_Product() ),
	array( '<a style="font-size:16px" href="/cat">Cat</a> › Name', array( 'name' => 'Name', 'categories' => array( 'Cat' ) ), '<div><nav class="woocommerce-breadcrumb">Home / Cat / Name</nav></div>', 'Cat › Name', new WC_Product() ),
);
$cases[] = array( '<a href="/cat">Cat</a> › Name', array( 'name' => 'Name', 'categories' => array( 'Cat' ) ), '<div><nav class="woocommerce-breadcrumb" style="color:red;margin-bottom:1em">Home / Cat / Name</nav></div>', 'Cat › Name', new WC_Product() );
foreach ( $cases as [ $text, $row, $rendered, $expected, $product ] ) {
	$GLOBALS['live_product'] = $product;
	$bound = Static_Site_Importer_Product_Field_Blocks::bind_document( '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->', $row );
	$block = parse_blocks( $bound )[0];
	$result = Static_Site_Importer_Product_Grid_Runtime::render_field_presentation( $rendered, $block );
	if ( trim( strip_tags( $result ) ) !== $expected ) { throw new RuntimeException( 'Live presentation: ' . $result ); }
	$source = $block['attrs']['metadata']['staticSiteImporterFieldPresentation'] ?? array();
	if ( $product instanceof WC_Product_Variable ) {
		if ( ( $source['prefix'] ?? null ) !== 'from ' ) { throw new RuntimeException( 'Missing wording' ); }
		$product->minimum = 6.50;
		$edited = Static_Site_Importer_Product_Grid_Runtime::render_field_presentation( $rendered, $block );
		if ( ! str_contains( $edited, '$6.50' ) ) { throw new RuntimeException( 'Price edit was frozen' ); }
	} elseif ( isset( $row['categories'] ) ) {
		$size = str_contains( $text, 'font-size:16px' ) ? '16px' : '';
		if ( ( $source['separator'] ?? '' ) !== '›' || ( $source['home'] ?? true ) || ( $source['fontSize'] ?? '' ) !== $size || ( '' !== $size && ! str_contains( $result, 'font-size:' . $size ) ) || str_contains( $result, 'Home' ) ) { throw new RuntimeException( 'Breadcrumb source presentation missing' ); }
		if ( ( $block['attrs']['fontSize'] ?? null ) !== '' ) { throw new RuntimeException( 'Woo default font size was not cleared' ); }
		$tags = new WP_HTML_Tag_Processor( $result );
		if ( ! $tags->next_tag( array( 'tag_name' => 'NAV', 'class_name' => 'woocommerce-breadcrumb' ) ) || ! str_contains( (string) $tags->get_attribute( 'style' ), 'margin:0' ) ) { throw new RuntimeException( 'Woo breadcrumb margin was not cleared' ); }
	} elseif ( $result !== $rendered || $source ) { throw new RuntimeException( 'Plain price changed' ); }
	$unbound = $block;
	unset( $unbound['attrs']['metadata'] );
	if ( Static_Site_Importer_Product_Grid_Runtime::render_field_presentation( $rendered, $unbound ) !== $rendered ) { throw new RuntimeException( 'Unbound default changed' ); }
}
foreach ( array( '', 'large' ) as $preset ) {
	$source = '<!-- wp:paragraph ' . json_encode( array( 'fontSize' => $preset ) ) . ' --><p>Cat › Name</p><!-- /wp:paragraph -->';
	$bound = parse_blocks( Static_Site_Importer_Product_Field_Blocks::bind_document( $source, array( 'name' => 'Name', 'categories' => array( 'Cat' ) ) ) )[0];
	if ( ( $bound['attrs']['fontSize'] ?? null ) !== $preset ) { throw new RuntimeException( 'Explicit source font size changed' ); }
}
// One table exercises both binding producers and the block-specific runtime.
Static_Site_Importer_Product_Grid_Runtime::register();
require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
$gallery_node = array(
	'blockName' => 'source/authored-carousel',
	'attrs' => array( 'sourcePresentationClasses' => 'product-gallery', 'thumbnails' => array( array( 'url' => 'one.jpg' ) ), 'stageAspectRatio' => '3/4', 'thumbnailWidth' => '50px', 'thumbnailPosition' => 'left' ),
	'innerHTML' => '<div class="product-gallery"><img src="one.jpg"></div>',
	'innerContent' => array( '<div class="product-gallery"><img src="one.jpg"></div>' ),
	'innerBlocks' => array(),
);
foreach ( array( '', '@media (max-width: 768px){.product-gallery-thumbnails{display:none}}', '@media (max-width: 768px){.product-gallery > .product-gallery-thumbnails{display:none!important}}', '@media (max-width: 600px){.unrelated-thumbnails{display:none}}', '@media (max-width: 600px){.other-gallery .product-gallery-thumbnails{display:none}}' ) as $css ) {
	$overlays = array();
	$markup = Static_Site_Importer_Product_Field_Blocks::bind_document( serialize_block( $gallery_node ), array( 'images' => array( array( 'src' => 'one.jpg' ) ) ), $overlays, array(), array(), $css );
	$gallery = parse_blocks( $markup )[0];
	$cart_markup = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'entity_kind' => 'add_to_cart_form' ), array( 'id' => 7 ) );
	$cart = parse_blocks( $cart_markup )[0]['innerBlocks'][0];
	foreach ( array(
		array( $gallery, '<div class="wp-block-woocommerce-product-image-gallery"><div class="woocommerce-product-gallery" style="opacity:1;margin-bottom:2em"><div class="flex-viewport"></div><ol class="flex-control-thumbs"></ol></div></div>', array( 'DIV' ) ),
		array( $cart, '<div><form class="variations_form cart" style="margin-bottom:2em"><table class="variations" style="margin-bottom:1em"></table></form></div>', array( 'FORM', 'TABLE' ) ),
	) as [ $block, $html, $targets ] ) {
		$result = apply_filters( 'render_block_' . $block['blockName'], $html, $block );
		$tags = new WP_HTML_Tag_Processor( $result );
		$zeroed = 0;
		while ( $tags->next_tag() ) {
			if ( in_array( $tags->get_tag(), $targets, true ) && str_contains( (string) $tags->get_attribute( 'style' ), ';margin:0' ) ) { ++$zeroed; }
		}
		if ( count( $targets ) !== $zeroed ) { throw new RuntimeException( 'Bound classic margins not cleared: ' . $result ); }
		$hide = 'woocommerce/product-image-gallery' === $block['blockName'] && str_contains( $css, '768px' );
		if ( $hide !== str_contains( $result, '@media (max-width: 768px)' ) || ( $hide && ( ! str_contains( $result, '.flex-control-thumbs{display:none !important;}' ) || ! str_contains( $result, 'width:100% !important;flex:1 1 100%' ) || ! str_contains( $result, 'ssi-bound-gallery-' ) || ! str_contains( $result, 'aspect-ratio:3/4 !important' ) ) ) ) { throw new RuntimeException( 'Source thumbnail visibility missing or invented' ); }
		unset( $block['attrs']['metadata'] );
		if ( $html !== apply_filters( 'render_block_' . $block['blockName'], $html, $block ) ) { throw new RuntimeException( 'Unbound gallery/cart changed' ); }
	}
}
echo "PASS bound product field presentation\n";
