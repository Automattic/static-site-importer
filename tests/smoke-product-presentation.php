<?php
/** Capture-driven native product replacement regression. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require_once ABSPATH . 'vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
ssi_test_require_core_block_parser( $wp_root );
foreach ( array( 'compat.php', 'compat-utf8.php', 'utf8.php', 'formatting.php', 'kses.php', 'class-wp-token-map.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php' ) as $file ) {
	require_once $wp_root . '/wp-includes/' . $file;
}
require_once ABSPATH . 'includes/class-static-site-importer-product-field-blocks.php';
$source = file_get_contents( __DIR__ . '/fixtures/product-handoff-contract/captured-product.html' );
$product = array( 'images' => array( array( 'src' => 'https://example.test/front.jpg' ), array( 'src' => 'https://example.test/back.jpg' ) ) );
foreach ( array( 'left' => 'row', 'right' => 'row-reverse', 'below' => 'column' ) as $position => $direction ) {
	$markup = str_replace( 'flex-direction:row;', 'flex-direction:' . $direction . ';', $source );
	$bound = Static_Site_Importer_Product_Field_Blocks::bind_document( $markup, $product );
	$blocks = parse_blocks( $bound );
	$block = $blocks[0];
	$gallery = $block['attrs']['metadata']['ssiPresentation']['gallery'] ?? array();
	$rendered = Static_Site_Importer_Product_Presentation::render( '<div>Native gallery</div>', $block );
	foreach ( array(
		1 === substr_count( $bound, 'wp:woocommerce/product-image-gallery' ),
		$position === ( $gallery['position'] ?? '' ),
		'600px' === ( $gallery['main']['width'] ?? '' ),
		'800px' === ( $gallery['main']['height'] ?? '' ),
		'50px' === ( $gallery['thumbnail']['width'] ?? '' ),
		'6px' === ( $gallery['thumbnailGap'] ?? '' ),
		str_contains( $rendered, 'height:800px!important' ),
		str_contains( $rendered, 'gap:6px' ),
		! str_contains( $bound, 'wp:html' ),
		$bound === Static_Site_Importer_Product_Field_Blocks::bind_document( $bound, $product ),
	) as $index => $ok ) {
		if ( ! $ok ) {
			throw new RuntimeException( "$position assertion $index failed" );
		}
	}
}
echo "Product presentation passed.\n";
