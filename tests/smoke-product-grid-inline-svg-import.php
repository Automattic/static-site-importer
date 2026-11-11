<?php
/** Import the unmodified reproduction from issue #2868 through the real build. */
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

$html = file_get_contents( __DIR__ . '/fixtures/product-grid-inline-svg/index.html' );
$plan = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$register = static function ( array $blocks ) use ( &$register ): void {
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? '';
		if ( $name && ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			WP_Block_Type_Registry::get_instance()->register( $name, array() );
		}
		$register( $block['innerBlocks'] ?? array() );
	}
};
foreach ( array( 'add-to-cart-form', 'product-button', 'product-collection', 'product-image', 'product-price', 'product-template' ) as $name ) {
	WP_Block_Type_Registry::get_instance()->register( 'woocommerce/' . $name, array() );
}
$runtime = new Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime();
foreach ( array_merge( $plan['pages'], $plan['templates'], $plan['template_parts'] ) as $document ) {
	$register( $runtime->parseBlocks( $document['canonical_block_markup'] ) );
}
$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( array( 'path' => 'index.html', 'content' => $html ) ),
	),
	array( 'slug' => 'product-grid-inline-svg' )
);
$assert( ! is_wp_error( $result ), 'Import succeeds: ' . ( is_wp_error( $result ) ? $result->get_error_message() . ' ' . json_encode( $result->get_error_data()['errors'] ?? array() ) : '' ) );
$root = get_theme_root() . '/product-grid-inline-svg';
$archive = $root . '/templates/archive-product.html';
$assert( is_file( $archive ), 'The real build creates the product archive.' );
$markup = file_get_contents( $archive );
$assert( str_contains( $markup, 'woocommerce/product-collection' ), 'Archive contains the adopted product grid.' );
$assert( 1 === preg_match( '~src="\{\{wordpress-site-plan:asset:[^}]+\}\}"~', $markup ), 'Archive keeps the review star as a theme asset token.' );
// The theme resolves template asset tokens at render time from functions.php,
// so every token left in a template must map to a file the build wrote.
$functions = (string) file_get_contents( $root . '/functions.php' );
preg_match_all( '~\{\{wordpress-site-plan:asset:[^}]+\}\}~', $markup, $tokens );
foreach ( array_unique( $tokens[0] ) as $token ) {
	$assert( 1 === preg_match( '~' . preg_quote( "'" . $token . "' => '", '~' ) . "([^']+)'~", $functions, $path ), 'functions.php resolves ' . $token );
	$assert( is_file( $root . '/' . $path[1] ), 'The asset for ' . $token . ' is written: ' . $path[1] );
}
echo "PASS: product grid and inline SVG import\n";
