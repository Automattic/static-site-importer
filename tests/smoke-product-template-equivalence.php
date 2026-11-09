<?php
/**
 * Realistic same-layout product documents must share a served template.
 *
 * @package StaticSiteImporter
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_mkdir_p( $path ) { return mkdir( $path, 0777, true ); }
function wp_normalize_path( $path ) { return $path; }
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
require_once ABSPATH . 'includes/class-static-site-importer-product-field-blocks.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-plan-persistence.php';
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$paragraph = static fn( string $text ): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$group = static fn( string $class, string $body ): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="' . $class . '">' . $body . '</div><!-- /wp:group -->';
$documents = array();
foreach ( array(
	array( 'Sticker', 'Paper goods', '3.50', 'from $3.50', 'aaaaaaaaaaaa' ),
	array( 'Print', 'Wall art', '5.00', 'from $5.00', 'bbbbbbbbbbbb' ),
) as $index => [ $name, $category, $price, $wording, $hash ] ) {
	$images = '';
	$product = array( 'name' => $name, 'price' => $price, 'categories' => array( $category ), 'images' => array() );
	foreach ( array( 'front', 'back' ) as $view ) {
		$url = '/media/' . strtolower( $name ) . '-' . $view . '.jpg';
		$product['images'][] = array( 'src' => $url );
		$images .= '<!-- wp:image --><figure><img src="' . $url . '" /></figure><!-- /wp:image -->';
	}
	$breadcrumb = '<!-- wp:group {"tagName":"nav","className":"product-nav","style":{"spacing":{"margin":{"bottom":"20px"}}}} --><nav class="product-nav">'
		. $paragraph( '<a href="/shop/' . $category . '">' . $category . '</a>' ) . $paragraph( '›' ) . $paragraph( '<a href="/products/' . $name . '">' . $name . '</a>' ) . '</nav><!-- /wp:group -->';
	$gallery = '<!-- wp:blocks-engine/authored-carousel {"className":"product-gallery","sourceIdentityAttributes":{"data-be-source-scope":"scope-' . $hash . '"}} --><div>' . $images . '</div><!-- /wp:blocks-engine/authored-carousel -->';
	$markup = $group( 'product-detail ssi-product-' . $hash,
		$breadcrumb . '<!-- wp:heading --><h2>' . $name . '</h2><!-- /wp:heading -->' . $paragraph( $wording ) . $gallery
		. $group( 'gallery-lightbox-wrapper', $group( 'gallery-lightbox-list', $images ) )
		. '<!-- wp:group {"anchor":"yui_3_17_2_1_' . $index . '_42","className":"ssi-form-' . $hash . ' ssi-node-' . $hash . '"} --><div id="yui_3_17_2_1_' . $index . '_42" class="ssi-form-' . $hash . ' ssi-node-' . $hash . '"></div><!-- /wp:group -->' );
	$captures = array();
	$tokens = array();
	$bound = Static_Site_Importer_Product_Field_Blocks::bind_document( $markup, $product, $captures, array(), array(), $tokens );
	$assert( $bound === Static_Site_Importer_Product_Field_Blocks::bind_document( $bound, $product ), 'Binding is idempotent' );
	$css = '.ssi-form-' . $hash . ' .ssi-node-' . $hash . '{color:red}';
	$css_state = array( 'resolved' => array( 'writes' => array( array( 'target_path' => 'style.css', 'payload' => array( 'encoding' => 'utf8', 'data' => $css ) ) ) ) );
	$alias = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'alias_product_presentation_tokens' );
	$alias_args = array( &$css_state, $tokens );
	$alias->invokeArgs( null, $alias_args );
	$aliased_css = $css_state['resolved']['writes'][0]['payload']['data'];
	$assert( str_contains( $aliased_css, strtr( $css, $tokens ) ) && str_contains( $aliased_css, '.ssi-form-' . $hash ), 'CSS keeps source selectors and serves structural selectors' );
	$assert( str_contains( $bound, 'wp:woocommerce/product-price' ), 'Prefixed price is dynamic' );
	$assert( str_contains( $bound, 'wp:woocommerce/breadcrumbs {"className":"product-nav","style"' ), 'Breadcrumb retains container presentation' );
	$assert( ! str_contains( $bound, '/media/' ) && ! str_contains( $bound, 'lightbox' ), 'Gallery owns images and lightbox' );
	$assert( ! str_contains( $bound, 'yui_' ), 'Unreferenced runtime anchors removed' );
	$assert( ! str_contains( $bound, $hash ), 'Page identity tokens replaced' );
	$documents[] = $bound;
}
$assert( $documents[0] === $documents[1], 'Same layouts bind byte-identically' );
foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
	$assert( str_contains( Static_Site_Importer_Product_Field_Blocks::bind_document( $paragraph( '$27.99 USD' ), array( $key => '27.99' ) ), 'woocommerce/product-price' ), $key . ': suffixed price' );
}
$unmatched = $paragraph( 'Save 2 on $27.99' );
$assert( $unmatched === Static_Site_Importer_Product_Field_Blocks::bind_document( $unmatched, array( 'price' => '27.99' ) ), 'Multiple numeric values are not a price' );
$runtime = '<!-- wp:paragraph {"anchor":"yui_3_17_2_1_99"} --><p id="yui_3_17_2_1_99"><a href="#yui_3_17_2_1_99">Details</a></p><!-- /wp:paragraph -->';
$assert( $runtime === Static_Site_Importer_Product_Field_Blocks::bind_document( $runtime, array() ), 'Referenced runtime anchors survive' );
$unrelated = $group( 'gallery-lightbox-wrapper', '<!-- wp:image --><figure><img src="/other.jpg" /></figure><!-- /wp:image -->' );
$existing = '<!-- wp:woocommerce/product-image-gallery /-->' . $unrelated;
$assert( $existing === Static_Site_Importer_Product_Field_Blocks::bind_document( $existing, array( 'images' => array( array( 'src' => '/product.jpg' ) ) ) ), 'Unrelated lightbox photos survive' );
$state = array( 'theme_dir' => sys_get_temp_dir() . '/ssi-equivalence-' . uniqid(), 'ordered_pages' => array(), 'applied' => array( 'files' => array() ) );
mkdir( $state['theme_dir'] . '/templates', 0777, true );
try {
	foreach ( $documents as $index => $document ) {
		$target = 'templates/single-product-' . $index . '.html';
		file_put_contents( $state['theme_dir'] . '/' . $target, $document );
		$state['ordered_pages'][] = array( 'post_type' => 'product', 'product_data' => array( 'name' => 'Product' ), 'source_path' => 'product-' . $index . '.html', 'served_template' => $target );
	}
	$share = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'share_product_templates' );
	$args = array( &$state );
	$assert( true === $share->invokeArgs( null, $args ), 'Template consolidation succeeds' );
	$assert( array( $state['theme_dir'] . '/templates/single-product.html' ) === glob( $state['theme_dir'] . '/templates/*.html' ), 'Only the shared single-product template remains' );
} finally {
	foreach ( glob( $state['theme_dir'] . '/templates/*.html' ) as $file ) { unlink( $file ); }
	rmdir( $state['theme_dir'] . '/templates' );
	rmdir( $state['theme_dir'] );
}
echo "PASS product template equivalence\n";
