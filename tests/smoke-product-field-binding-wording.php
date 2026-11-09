<?php
/**
 * Product fields render from the product: prefixed price, multi-node breadcrumb, gallery-owned lightbox.
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
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$paragraph = static fn( string $text ): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$group = static fn( string $class, string $body ): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="' . $class . '">' . $body . '</div><!-- /wp:group -->';
$images = '';
$product = array( 'name' => 'Sticker', 'price' => '3.50', 'categories' => array( 'Paper goods' ), 'images' => array() );
foreach ( array( 'front', 'back' ) as $view ) {
	$product['images'][] = array( 'src' => '/media/sticker-' . $view . '.jpg' );
	$images .= '<!-- wp:image --><figure><img src="/media/sticker-' . $view . '.jpg" /></figure><!-- /wp:image -->';
}
$breadcrumb = '<!-- wp:group {"tagName":"nav","className":"product-nav","style":{"spacing":{"margin":{"bottom":"20px"}}}} --><nav class="product-nav">'
	. $paragraph( '<a href="/shop/paper-goods/">Paper goods</a>' ) . $paragraph( '›' ) . $paragraph( '<a href="/products/sticker/">Sticker</a>' ) . '</nav><!-- /wp:group -->';
$gallery = '<!-- wp:blocks-engine/authored-carousel {"className":"product-gallery"} --><div>' . $images . '</div><!-- /wp:blocks-engine/authored-carousel -->';
$markup = $group( 'product-detail', $breadcrumb . '<!-- wp:heading --><h2>Sticker</h2><!-- /wp:heading -->' . $paragraph( 'from $3.50' ) . $gallery . $group( 'gallery-lightbox-wrapper', $group( 'gallery-lightbox-list', $images ) ) );
$bound = Static_Site_Importer_Product_Field_Blocks::bind_document( $markup, $product );
$assert( $bound === Static_Site_Importer_Product_Field_Blocks::bind_document( $bound, $product ), 'Binding is idempotent' );
$assert( str_contains( $bound, 'wp:woocommerce/product-price' ) && ! str_contains( $bound, 'from $3.50' ), 'Prefixed price is dynamic' );
$assert( str_contains( $bound, 'wp:woocommerce/breadcrumbs {"className":"product-nav","style"' ) && ! str_contains( $bound, 'Paper goods' ), 'Multi-node breadcrumb binds with its container presentation' );
$assert( ! str_contains( $bound, '/media/' ) && ! str_contains( $bound, 'lightbox' ), 'Gallery owns images and lightbox' );
foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
	$assert( str_contains( Static_Site_Importer_Product_Field_Blocks::bind_document( $paragraph( '$27.99 USD' ), array( $key => '27.99' ) ), 'woocommerce/product-price' ), $key . ': suffixed price' );
}
$unmatched = $paragraph( 'Save 2 on $27.99' );
$assert( $unmatched === Static_Site_Importer_Product_Field_Blocks::bind_document( $unmatched, array( 'price' => '27.99' ) ), 'Multiple numeric values are not a price' );
$existing = '<!-- wp:woocommerce/product-image-gallery /-->' . $group( 'gallery-lightbox-wrapper', '<!-- wp:image --><figure><img src="/other.jpg" /></figure><!-- /wp:image -->' );
$assert( $existing === Static_Site_Importer_Product_Field_Blocks::bind_document( $existing, array( 'images' => array( array( 'src' => '/product.jpg' ) ) ) ), 'Unrelated lightbox photos survive' );
echo "PASS product field binding\n";
