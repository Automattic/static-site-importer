<?php
/**
 * Table-driven captured product presentation ownership regression.
 *
 * @package StaticSiteImporter
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
/** Standalone WordPress filter contract. */
function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core API.
	return $value;
}
/** Standalone block serialization contract. */
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Core stub.
}
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once ABSPATH . 'includes/class-static-site-importer-product-field-blocks.php';
require_once ABSPATH . 'includes/class-static-site-importer-stylesheet-materializer.php';
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/product-presentation/captured.json' ), true );
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
$binding = array(
	'source_path'           => $fixture['source_path'],
	'search_block_markup'   => '<!-- wp:html --><form>Captured purchase controls</form><!-- /wp:html -->',
	'occurrence'            => 1,
	'role'                  => 'commerce_add_to_cart_form',
	'product_form_fallback' => $fixture,
);
$product = array(
	'slug'     => 'stickers',
	'bindings' => array( $binding ),
);
$records = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
	array(
		'entities' => array(
			'catalog' => array(
				'adapter'  => Static_Site_Importer_Woo_Product_Seeder::adapter(),
				'manifest' => array( 'products' => array( $product ) ),
			),
		),
	),
	array(
		'catalog' => array(
			'products' => array(
				array(
					'slug'   => 'stickers',
					'id'     => 42,
					'status' => 'created',
				),
			),
		),
	)
);
$markup  = $records[0]['replacement_block_markup'];
$capture = $records[0]['product_presentation'];
if ( $capture !== Static_Site_Importer_Product_Presentation::capture( $fixture ) ) {
	throw new RuntimeException( 'Binding receipt lost captured presentation.' );
}
$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Captured', '', array(), array(), array( $capture ) );
$cases  = array(
	'variation' => array( '.variations tr:nth-child(1) select', 'width:634px', 'height:55px', 'border-radius:23.84px', 'font-size:16px', 'border-color:rgb(48,54,54)' ),
	'quantity'  => array( 'input.qty', 'width:42px', 'height:27px', 'border:1px solid rgb(48,54,54)', 'border-radius:23.84px', 'font-size:16px' ),
	'submit'    => array( '.single_add_to_cart_button', 'width:479px', 'height:55px', 'border:1px solid rgb(212,150,125)', 'background:transparent', 'text-transform:uppercase' ),
);
foreach ( $cases as $role => $expected ) {
	foreach ( $writes as $path => $css ) {
		foreach ( $expected as $value ) {
			if ( ! str_contains( $css, $value ) ) {
				throw new RuntimeException( $path . ': missing ' . $role . ' ' . $value );
			}
		}
	}
}
if ( ! str_contains( $markup, $capture['className'] ) || str_contains( $markup, 'wp:html' ) ) {
	throw new RuntimeException( 'Native form must carry capture scope with zero fallback blocks.' );
}
$template = Static_Site_Importer_Product_Field_Blocks::bind_document( $markup, array() );
if ( str_contains( $template, 'productId' ) || ! str_contains( $template, $capture['className'] ) ) {
	throw new RuntimeException( 'Single-product template must keep presentation and inherit product context.' );
}
foreach ( array( 'name', 'price', 'description', 'image', 'breadcrumb' ) as $role ) {
	$attrs = array(
		'className' => 'captured-source',
		'style'     => array( 'border' => array( 'radius' => '24px' ) ),
	);
	$block = Static_Site_Importer_Product_Field_Blocks::field(
		array(
			'blockName' => 'core/heading',
			'attrs'     => $attrs,
		),
		$role
	);
	if ( array_diff_key( $attrs, $block['attrs'] ) || $attrs['style'] !== $block['attrs']['style'] || $attrs['className'] !== $block['attrs']['className'] ) {
		throw new RuntimeException( 'Presentation lost on ' . $role );
	}
}
$other = $fixture;
$other['presentation_graph']['controls'][1]['control']['styles']['width'] = '320px';
$other_capture = Static_Site_Importer_Product_Presentation::capture( $other );
if ( $capture['className'] === $other_capture['className'] || str_contains( $capture['css'], $other_capture['className'] ) ) {
	throw new RuntimeException( 'Different source captures must not share presentation scopes.' );
}
$unsafe = $fixture;
$unsafe['presentation_graph']['controls'][1]['control']['styles']['color'] = 'red;}body{display:none';
if ( str_contains( Static_Site_Importer_Product_Presentation::capture( $unsafe )['css'], 'display:none' ) ) {
	throw new RuntimeException( 'Source value escaped its declaration.' );
}
$bad        = $capture;
$bad['css'] = 'body{display:none}';
if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
	throw new RuntimeException( 'Unproved stylesheet accepted.' );
}
// Inline geometry deliberately uses no source-specific class names or selectors.
$main          = '<!-- wp:image --><figure><img src="main.jpg" width="600" height="800" /></figure><!-- /wp:image -->';
$thumbs        = '<!-- wp:group --><div style="display:flex;flex-direction:%s;gap:6px"><!-- wp:image --><figure><img src="main.jpg" width="50" height="67" /></figure><!-- /wp:image --><!-- wp:image --><figure><img src="second.jpg" width="50" height="67" /></figure><!-- /wp:image --></div><!-- /wp:group -->';
$gallery_cases = array(
	'left strip'     => array( 'row', true, true, 'left' ),
	'right strip'    => array( 'row', false, true, 'right' ),
	'reversed strip' => array( 'row-reverse', true, true, 'right' ),
	'below strip'    => array( 'column', false, true, 'below' ),
	'no thumbnails'  => array( 'column', false, false, 'below' ),
);
foreach ( $gallery_cases as $label => list( $direction, $first, $has_thumbs, $position ) ) {
	$strip    = $has_thumbs ? sprintf( $thumbs, 'column' === $direction ? 'row' : 'column' ) : '';
	$source   = '<!-- wp:group --><div style="display:flex;flex-direction:' . $direction . ';gap:12px">' . ( $first ? $strip . $main : $main . $strip ) . '</div><!-- /wp:group -->';
	$node     = parse_blocks( $source )[0];
	$facts    = Static_Site_Importer_Product_Presentation::gallery_attrs( $node );
	$overlays = array();
	$bound    = Static_Site_Importer_Product_Field_Blocks::bind_document( $source, array( 'images' => array( array( 'src' => 'main.jpg' ), array( 'src' => $has_thumbs ? 'second.jpg' : 'main.jpg' ) ) ), $overlays );
	$overlay  = $overlays[0] ?? array();
	if ( $position !== $facts['position'] || array(
		'width'  => '600px',
		'height' => '800px',
	) !== $facts['main'] || 1 !== count( $overlays ) || 1 !== substr_count( $bound, 'wp:woocommerce/product-image-gallery' ) || str_contains( $bound, '<img' ) || str_contains( $bound, 'wp:html' ) || ! str_contains( $bound, $overlay['className'] ) ) {
		throw new RuntimeException( $label . ': native gallery lost captured geometry or scope.' );
	}
	$combined = Static_Site_Importer_Product_Presentation::capture( array_merge( $fixture, array( 'gallery' => $facts ) ) );
	if ( $combined !== Static_Site_Importer_Product_Presentation::validate_overlay( $combined ) || ! str_contains( $combined['css'], '.single_add_to_cart_button' ) || ! str_contains( $combined['css'], '.flex-viewport' ) || $combined['className'] === $capture['className'] ) {
		throw new RuntimeException( $label . ': gallery and purchase controls must share one validated capture artifact.' );
	}
	if ( $bound !== Static_Site_Importer_Product_Field_Blocks::bind_document( $bound, array() ) ) {
		throw new RuntimeException( $label . ': native gallery binding is not idempotent.' );
	}
	if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) ) {
		throw new RuntimeException( $label . ': gallery receipt failed validation.' );
	}
	$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Captured', '', array(), array(), $overlays );
	foreach ( $writes as $css ) {
		foreach ( array( '.woocommerce-product-gallery', '.flex-viewport', 'width:600px', 'height:800px' ) as $expected ) {
			if ( ! str_contains( $css, $expected ) ) {
				throw new RuntimeException( $label . ': stylesheet missing ' . $expected );
			}
		}
		if ( $has_thumbs && ( '6px' !== $facts['thumbnailGap'] || ! str_contains( $css, '.flex-control-thumbs li{width:50px' ) || ! str_contains( $css, 'gap:12px' ) || ! str_contains( $css, 'gap:6px' ) || ! str_contains( $css, 'order:' . ( 'left' === $position ? '-1' : '0' ) ) ) ) {
			throw new RuntimeException( $label . ': thumbnail stylesheet lost arrangement.' );
		}
		if ( ! $has_thumbs && str_contains( $css, '.flex-control-thumbs' ) ) {
			throw new RuntimeException( $label . ': invented thumbnail presentation.' );
		}
	}
	$bad                  = $overlay;
	$bad['rules']['body'] = 'display:none !important;';
	if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
		throw new RuntimeException( 'Gallery receipt accepted an arbitrary selector.' );
	}
}
echo "Product presentation passed.\n";
