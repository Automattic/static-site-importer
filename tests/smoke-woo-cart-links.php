<?php
/**
 * Real artifact import of the producer cart-opener contract, with Core HTML API.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone shop smoke harness with in-memory WordPress/Woo APIs.
define( 'SSI_PLAN_HARNESS_ONLY', true );
require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
$core = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once $core . '/wp-includes/utf8.php';
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
function wp_kses_uri_attributes() { return array( 'href', 'src' ); }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( $text, $protocols = null, $context = 'display' ) { return esc_attr( $text ); }
class WC_Product {}
function wc_attribute_taxonomy_id_by_name( $name ) { return 0; }
function wc_get_product( $id ) { return null; }
function wc_get_cart_url() { return 'https://example.test/cart/'; }
function WC() {
	static $woo;
	return $woo ??= (object) array( 'cart' => new class {
		public int $count = 0;
		public function get_cart_contents_count() { return $this->count; }
		public function add_to_cart( $id ) { ++$this->count; }
	} );
}

$html = '<!doctype html><html lang="es"><head><title>Tienda</title></head><body>
<header><nav><a href="/">Tienda</a></nav><button class="cart-btn" data-carrito-abrir aria-label="Abrir carrito"><svg viewBox="0 0 24 24"><path d="M5 8h14"/></svg><span class="cart-count" data-carrito-count hidden>0</span></button></header>
<main><h1>Productos</h1><div class="grid" style="display:grid;grid-template-columns:repeat(3,1fr)">
<article class="card"><h2>Mate</h2><p>€10.00</p><button class="btn btn-add" data-add="0" aria-label="Agregar Mate al carrito">Agregar</button></article>
<article class="card"><h2>Taza</h2><p>€12.00</p><button class="btn btn-add" data-add="1" aria-label="Agregar Taza al carrito">Agregar</button></article>
<article class="card"><h2>Termo</h2><p>€20.00</p><button class="btn btn-add" data-add="2" aria-label="Agregar Termo al carrito">Agregar</button></article>
</div></main><div class="aviso"><button type="button" data-carrito-abrir>Ver carrito</button></div></body></html>';
$other = str_replace( array( '<title>Tienda</title>', '<h1>Productos</h1>' ), array( '<title>Catalogo</title>', '<h1>Catalogo</h1>' ), $html );
$plan = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html, 'catalogo.html' => $other ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$assert( ! empty( $plan['template_parts'] ), 'Fixture produces shared template parts.' );
$cart_declarations = array_values( array_filter( $plan['runtime_declarations'], static fn( $row ) => 'cart_links' === ( $row['type'] ?? '' ) ) );
$assert( ! empty( $cart_declarations ) && empty( $cart_declarations[0]['required_for'] ), 'Producer cart links stay optional.' );
$assert( str_contains( json_encode( $cart_declarations, JSON_UNESCAPED_SLASHES ), 'wordpress-site-plan/shared/header#header' ), 'Header binding targets the shared header artifact.' );
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
	array( 'entrypoint' => 'index.html', 'files' => array( array( 'path' => 'index.html', 'content' => $html ), array( 'path' => 'catalogo.html', 'content' => $other ) ) ),
	array( 'slug' => 'cart-links-smoke' )
);
$assert( ! is_wp_error( $result ), 'Import succeeds: ' . ( is_wp_error( $result ) ? $result->get_error_message() . json_encode( $result->get_error_data() ) : '' ) );
$root = get_theme_root() . '/cart-links-smoke';
$markup = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->getExtension() === 'html' ) { $markup .= file_get_contents( $file->getPathname() ); }
}
foreach ( $GLOBALS['ssi_plan_posts'] as $post ) { $markup .= stripslashes( $post['post_content'] ?? '' ); }
$assert( str_contains( $markup, 'staticSiteImporterCartLink' ), 'Real import saves cart-link metadata.' );
$assert( str_contains( json_encode( $result ), 'cart_links' ), 'Receipt records cart links.' );
$assert( str_contains( $markup, 'woocommerce/product-collection' ), 'Three-card grid imports as a product collection.' );
$render = static function ( string $markup ) use ( &$render ): string {
	$out = '';
	foreach ( parse_blocks( $markup ) as $block ) {
		$content = '';
		$i = 0;
		foreach ( $block['innerContent'] as $piece ) {
			$content .= is_string( $piece ) ? $piece : $render( serialize_block( $block['innerBlocks'][ $i++ ] ) );
		}
		$out .= Static_Site_Importer_Product_Grid_Runtime::render_cart_link( $content, $block );
	}
	return $out;
};
$front = $render( $markup );
$assert( preg_match( '~<a[^>]*href="https://example.test/cart/"[^>]*title="Abrir carrito"[^>]*>~', $front ) === 1, 'Header opener links to the cart.' );
$assert( preg_match( '~<a[^>]*href="https://example.test/cart/"[^>]*>Ver carrito</a>~', $front ) === 1, 'Page toast links to the cart.' );
$tags = new WP_HTML_Tag_Processor( $front );
$openers = 0;
while ( $tags->next_tag() ) {
	if ( 'A' === $tags->get_tag() && wc_get_cart_url() === $tags->get_attribute( 'href' ) ) {
		$assert( 'A' === $tags->get_tag() && wc_get_cart_url() === $tags->get_attribute( 'href' ), 'Header and toast navigate to cart.' );
		++$openers;
	}
}
$assert( $openers >= 2, 'Header template part and page toast are both bound.' );
$assert( preg_match( '~<span[^>]*hidden[^>]*>0</span>~', $front ) === 1, 'Badge is hidden at zero.' );
WC()->cart->add_to_cart( 1 );
$front = $render( $markup );
$assert( preg_match( '~<span([^>]*data-ssi-cart-badge[^>]*)>1</span>~', $front, $badge ) === 1 && ! str_contains( $badge[1], 'hidden' ), 'Live badge shows one without hidden.' );
Static_Site_Importer_Product_Grid_Runtime::register();
$fragments = array();
foreach ( $GLOBALS['ssi_plan_filters']['woocommerce_add_to_cart_fragments'][10] ?? array() as $registered ) { $fragments = $registered['callback']( $fragments ); }
$assert( ! empty( $fragments ) && str_contains( implode( '', $fragments ), '>1</span>' ) && ! str_contains( implode( '', $fragments ), 'hidden' ), 'Registered AJAX fragments return the live badge without a render request.' );
$assert( str_contains( $front, 'cart-btn' ) && str_contains( $front, 'be-inline-geometry-' ) && str_contains( $front, 'cart-count' ), 'Source classes and icon survive.' );
$marker_block = array( 'attrs' => array( 'metadata' => array( Static_Site_Importer_Product_Grid_Runtime::CART_LINK_KEY => array( 'id' => str_repeat( 'a', 24 ) ) ) ) );
$anchor = Static_Site_Importer_Product_Grid_Runtime::render_cart_link( '<a href="/old" class="cart-btn">Cart</a>', $marker_block );
$assert( str_contains( $anchor, 'href="https://example.test/cart/"' ) && str_contains( $anchor, 'class="cart-btn"' ), 'Existing anchors keep their classes and get the cart URL.' );
$without_woo = 'define("ABSPATH", ' . var_export( dirname( __DIR__ ) . '/', true ) . '); require ' . var_export( dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php', true ) . '; $result = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle(' . var_export( array( 'runtime_declarations' => $cart_declarations ), true ) . ', array()); echo json_encode($result);';
exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $without_woo ), $output, $exit );
$optional = json_decode( implode( '', $output ), true );
$assert( 0 === $exit && empty( $optional['entities'] ) && 'unsupported_optional_runtime_declaration' === ( $optional['diagnostics'][0]['code'] ?? '' ), 'Without WooCommerce the optional declaration is skipped exactly as before.' );
echo "PASS: cart links real artifact import and live fragments\n";
