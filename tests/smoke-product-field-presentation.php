<?php
/** Bound field presentation uses the portable runtime, not frozen catalog text. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function apply_filters( $hook, $value, ...$args ) { return $value; }
function add_filter( ...$args ) {}
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
		if ( ( $source['separator'] ?? '' ) !== '›' || ( $source['home'] ?? true ) || ( $source['fontSize'] ?? '' ) !== '16px' || ! str_contains( $result, 'font-size:16px' ) || str_contains( $result, 'Home' ) ) { throw new RuntimeException( 'Breadcrumb source presentation missing' ); }
	} elseif ( $result !== $rendered || $source ) { throw new RuntimeException( 'Plain price changed' ); }
	$unbound = $block;
	unset( $unbound['attrs']['metadata'] );
	if ( Static_Site_Importer_Product_Grid_Runtime::render_field_presentation( $rendered, $unbound ) !== $rendered ) { throw new RuntimeException( 'Unbound default changed' ); }
}
echo "PASS bound product field presentation\n";
