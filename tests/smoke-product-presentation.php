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
// The real-import fixture has input paint, not quantity-wrapper or row geometry.
// Assert the supported layout without treating the 42px input as a 144px stepper.
$layout_cases = array(
	'table.variations'                     => 'display:block',
	'table.variations th'                  => 'display:block',
	'table.variations td'                  => 'display:block',
	'table.variations select'              => 'box-sizing:border-box',
	'.woocommerce-variation-add-to-cart'   => 'grid-template-columns:minmax(0,1fr) minmax(0,479px)',
	'form.cart:not(.variations_form)'      => 'grid-template-columns:minmax(0,1fr) minmax(0,479px)',
	'form.cart .quantity'                  => 'margin:0',
	'form.cart .single_add_to_cart_button' => 'margin:0',
);
foreach ( $writes as $path => $css ) {
	foreach ( $layout_cases as $selector => $declaration ) {
		$selector = '.' . $capture['className'] . '.' . $capture['className'] . ' ' . $selector;
		if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '\\{([^}]+)\\}/', $css, $match ) || ! str_contains( $match[1], 'width:100% !important;' ) || ! str_contains( $match[1], $declaration . ' !important;' ) ) {
			throw new RuntimeException( $path . ': missing scoped full-width layout for ' . $selector );
		}
	}
}
if ( $capture !== Static_Site_Importer_Product_Presentation::validate_overlay( $capture ) ) {
	throw new RuntimeException( 'Purchase layout receipt failed validation.' );
}
$changed = $fixture;
$changed['presentation_graph']['controls'][1]['control']['styles']['width'] = '321px';
$changed_capture = Static_Site_Importer_Product_Presentation::capture( $changed );
if ( ! str_contains( $changed_capture['css'], 'minmax(0,321px)' ) || str_contains( $changed_capture['css'], 'minmax(0,479px)' ) ) {
	throw new RuntimeException( 'Purchase track did not follow captured button geometry.' );
}
$simple = $fixture;
unset( $simple['controls'][0] );
$simple_capture = Static_Site_Importer_Product_Presentation::capture( $simple );
if ( str_contains( $simple_capture['css'], 'table.variations' ) || ! str_contains( $simple_capture['css'], 'form.cart:not(.variations_form){width:100%' ) ) {
	throw new RuntimeException( 'Simple product must fill its row without inventing variation fields.' );
}
$unknown_width = $fixture;
unset( $unknown_width['presentation_graph']['controls'][1]['control']['styles']['width'] );
if ( ! str_contains( Static_Site_Importer_Product_Presentation::capture( $unknown_width )['css'], 'grid-template-columns:auto minmax(0,1fr)' ) ) {
	throw new RuntimeException( 'Missing button geometry must use intrinsic quantity and a flexible button.' );
}
$bad_layout = $capture;
$bad_layout['rules']['layout:purchase-row'] .= 'position:fixed !important;';
if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad_layout ) ) {
	throw new RuntimeException( 'Purchase layout accepted an unsupported declaration.' );
}
$bare = Static_Site_Importer_Product_Presentation::capture( array() );
if ( $bare || str_contains( $capture['css'], 'gap:10px' ) || str_contains( $capture['css'], 'minmax(0,42px)' ) ) {
	throw new RuntimeException( 'Purchase layout invented missing geometry.' );
}
$legacy          = $capture;
$legacy['rules'] = array_filter( $legacy['rules'], static fn( $slot ) => ! str_starts_with( $slot, 'layout:' ), ARRAY_FILTER_USE_KEY );
// Legacy receipts are still accepted by rebuilding their original artifact.
$hash                = substr( hash( 'sha256', json_encode( $legacy['rules'] ) ), 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic receipt contract.
$legacy['className'] = 'ssi-product-' . $hash;
$legacy['css']       = '';
foreach ( explode( '}', $capture['css'] ) as $rule ) {
	if ( str_contains( $rule, '{' ) && ! preg_match( '/ (?:form\\.cart|table\\.variations|\\.single_variation_wrap|\\.woocommerce-variation-add-to-cart)/', $rule ) ) {
		$legacy['css'] .= str_replace( $capture['className'], $legacy['className'], $rule ) . '}';
	}
}
if ( $legacy !== Static_Site_Importer_Product_Presentation::validate_overlay( $legacy ) ) {
	throw new RuntimeException( 'Legacy paint-only receipt rejected.' );
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
// Cascade-resolved carousel geometry must survive percentage-sized images.
$carousel_cases = array(
	'left cascade strip' => array( 'left', 'left', 'row', 'column', '-1', '3/4' ),
	'right cascade strip' => array( 'right', 'right', 'row', 'column', '0', '3 / 4' ),
	'top cascade strip' => array( 'top', 'above', 'column', 'row', '-1', '3/4' ),
	'bottom cascade strip' => array( 'bottom', 'below', 'column', 'row', '0', '3/4' ),
);
foreach ( $carousel_cases as $label => list( $placement, $position, $direction, $strip_direction, $order, $ratio ) ) {
	$node = array(
		'blockName' => 'ssi-example-site/authored-carousel',
		'attrs'     => array(
			'thumbnails'        => array( array( 'src' => 'one.jpg' ), array( 'src' => 'two.jpg' ), array( 'src' => 'three.jpg' ) ),
			'thumbnailPosition' => $placement,
			'thumbnailWidth'    => '50px',
			'thumbnailGap'      => '10px',
			'stageGap'          => '10px',
			'stageAspectRatio'  => $ratio,
		),
		'innerHTML' => '<img src="one.jpg" style="width:100%;height:100%" />',
	);
	$facts = Static_Site_Importer_Product_Presentation::gallery_attrs( $node );
	if ( $position !== ( $facts['position'] ?? '' ) || '50px' !== ( $facts['thumbnail']['width'] ?? '' ) || '10px' !== ( $facts['thumbnailGap'] ?? '' ) || '10px' !== ( $facts['gap'] ?? '' ) || $ratio !== ( $facts['main']['aspectRatio'] ?? '' ) ) {
		throw new RuntimeException( $label . ': carousel attributes lost.' );
	}
	$overlay = Static_Site_Importer_Product_Presentation::capture( array( 'gallery' => $facts ) );
	$expected_rules = array(
		'gallery'  => array( 'display:flex', 'flex-direction:' . $direction, 'gap:10px' ),
		'viewport' => array( 'aspect-ratio:' . $ratio, 'min-width:0', 'flex:' . ( 'row' === $direction ? '1 1 0' : '0 0 auto' ) ),
		'image'    => array( 'width:100%', 'height:100%', 'object-fit:cover' ),
		'thumbs'   => array( 'flex-direction:' . $strip_direction, 'order:' . $order, 'gap:10px' ),
		'thumb'    => array( 'width:50px', 'aspect-ratio:' . $ratio ),
	);
	if ( 'row' === $direction ) {
		$expected_rules['thumbs'][] = 'width:50px';
	}
	foreach ( $expected_rules as $slot => $declarations ) {
		foreach ( $declarations as $expected ) {
			if ( ! str_contains( $overlay['rules'][ $slot ] ?? '', $expected . ' !important;' ) ) {
				throw new RuntimeException( $label . ': missing ' . $slot . ' ' . $expected );
			}
		}
	}
	if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) ) {
		throw new RuntimeException( $label . ': carousel receipt failed validation.' );
	}
	foreach ( array( 'aspect-ratio' => array( '3/0', '0/4', 'auto', '3/4;display:none', '3/4 junk' ), 'flex' => array( '1', '1 1 auto', '1 1 0;display:none' ) ) as $property => $values ) {
		foreach ( $values as $value ) {
			$bad = $overlay;
			$bad['rules']['viewport'] .= $property . ':' . $value . ' !important;';
			if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
				throw new RuntimeException( $label . ': unsafe carousel value accepted.' );
			}
		}
	}
}
// Source width belongs to the outer block; conflicting source layout does not.
$replacement_cases = array(
	'source carousel width cascade' => array( 'product-gallery be-inline-geometry-x', 'scope-example' ),
);
foreach ( $replacement_cases as $label => list( $classes, $scope ) ) {
	$node = array(
		'blockName' => 'example/authored-carousel',
		'attrs' => array(
			'className' => 'existing',
			'sourcePresentationClasses' => $classes,
			'sourceIdentityAttributes' => array( 'data-be-source-scope' => $scope, 'onclick' => 'unsafe()' ),
			'stageAspectRatio' => '3/4',
			'thumbnailPosition' => 'left',
			'thumbnailWidth' => '50px',
			'thumbnails' => array( array( 'src' => 'one.jpg' ) ),
		),
	);
	$node['innerBlocks'] = array();
	$node['innerHTML'] = '<div><img src="one.jpg" style="width:100%;height:100%" /></div>';
	$node['innerContent'] = array( $node['innerHTML'] );
	$overlays = array();
	$bound = Static_Site_Importer_Product_Field_Blocks::bind_document( serialize_block( $node ), array( 'image' => 'one.jpg' ), $overlays );
	$block = parse_blocks( $bound )[0];
	if ( 'woocommerce/product-image-gallery' !== $block['blockName'] || str_contains( $bound, '<img' ) || 1 !== count( $overlays ) ) {
		throw new RuntimeException( $label . ': public replacement path lost the native gallery.' );
	}
	$overlay = Static_Site_Importer_Product_Presentation::capture( array( 'gallery' => Static_Site_Importer_Product_Presentation::gallery_attrs( $node ) ) );
	foreach ( array_merge( array( 'existing', $overlay['className'] ), explode( ' ', $classes ) ) as $class ) {
		if ( ! in_array( $class, explode( ' ', $block['attrs']['className'] ), true ) ) {
			throw new RuntimeException( $label . ': missing replacement class ' . $class );
		}
	}
	if ( array( 'data-be-source-scope' => $scope ) !== ( $block['attrs']['metadata']['staticSiteImporterGalleryIdentity'] ?? null ) ) {
		throw new RuntimeException( $label . ': lost inert source identity.' );
	}
	$rules = array(
		'layout:gallery-block' => array( 'display:block', 'overflow:visible', 'min-width:0' ),
		'gallery' => array( 'width:100%', 'display:flex' ),
	);
	foreach ( $rules as $slot => $values ) {
		foreach ( $values as $value ) {
			if ( ! str_contains( $overlay['rules'][ $slot ] ?? '', $value . ' !important;' ) ) {
				throw new RuntimeException( $label . ': missing wrapper isolation or inner fill ' . $value );
			}
		}
	}
	if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) || str_contains( $overlay['rules']['layout:gallery-block'], 'width:' . '100%' ) ) {
		throw new RuntimeException( $label . ': receipt invalid or source width overridden.' );
	}
	$inner_selector = '.' . $overlay['className'] . '.' . $overlay['className'] . ' .woocommerce-product-gallery{width:100% !important;';
	if ( ! str_contains( $overlay['css'], $inner_selector ) || $overlays[0] !== $overlay ) {
		throw new RuntimeException( $label . ': fill rule must target the inner gallery, not the source-width wrapper.' );
	}
	$bad = $overlay;
	$bad['rules']['layout:gallery-block'] .= 'overflow:hidden !important;';
	if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
		throw new RuntimeException( $label . ': unsupported wrapper receipt accepted.' );
	}
}
echo "Product presentation passed.\n";
