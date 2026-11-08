<?php
/**
 * Table-driven captured product presentation ownership regression.
 *
 * @package StaticSiteImporter
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
/** Standalone WordPress filter contract. */
function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core API.
	foreach ( $GLOBALS['ssi_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core signature.
	$GLOBALS['ssi_filters'][ $hook ][] = $callback;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core signature.
}
function get_post_type( $id ) {
	return $GLOBALS['ssi_post_types'][ $id ] ?? false;
}
/** Standalone URL sanitization contract. */
function esc_url_raw( $url ) {
	return $url;
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
$purchase_cases = array(
	'percentage submit' => array( '100%', '144px', '144px minmax(0,1fr)' ),
	'control row'       => array( '100%', '86px', '86px minmax(0,1fr)' ),
);
foreach ( $purchase_cases as $label => list( $submit_width, $quantity_width, $tracks ) ) {
	$entity = array(
		'controls' => array(
			array( 'tag' => 'input', 'type' => 'number', 'styles' => array( 'width' => $quantity_width ) ),
			array( 'tag' => 'button', 'type' => 'submit', 'styles' => array( 'width' => $submit_width, 'gap' => '10px' ) ),
		),
	);
	$gap = '10px';
	if ( 'control row' === $label ) {
		// Real Squarespace shape: an outer column stack also holds both controls;
		// the nearest purchase row splits 3:10 only on desktop and wraps below it.
		$gap      = '11px';
		$member   = static fn( int $index, array $styles ): array => array( 'index' => $index, 'source_selector' => '.m' . $index, 'provenance' => array(), 'styles' => $styles );
		$variant  = static fn( int $row, string $role, ?int $index, string $query, array $patch ): array => array_filter( array( 'index' => $row, 'role' => $role, 'member_index' => $index, 'condition' => array( 'kind' => 'media', 'query' => $query ), 'style_patch' => $patch, 'precedence' => array(), 'provenance' => array() ), static fn( $value ): bool => null !== $value );
		$desktop  = 'screen and (min-width:768px)';
		$narrow   = 'screen and (max-width:767px)';
		$entity['presentation_graph'] = array(
			'schema'       => 'generic/computed-form-presentation/v3',
			'control_rows' => array(
				array( 'index' => 0, 'source_selector' => '.stack', 'provenance' => array(), 'styles' => array( 'display' => 'flex', 'flex_direction' => 'column', 'gap' => $gap ), 'members' => array( $member( 0, array( 'width' => '100%' ) ), $member( 1, array( 'width' => '100%' ) ) ) ),
				array( 'index' => 1, 'source_selector' => '.purchase', 'provenance' => array(), 'styles' => array( 'display' => 'flex', 'gap' => $gap ), 'members' => array( $member( 0, array( 'flex_grow' => '3', 'flex_basis' => '0' ) ), $member( 1, array( 'min_width' => '0', 'width' => 'auto' ) ) ) ),
			),
			'variants'     => array(
				$variant( 1, 'control_row_item', 1, $desktop, array( 'flex_grow' => '10', 'flex_basis' => '0' ) ),
				$variant( 1, 'control_row', null, $narrow, array( 'flex_wrap' => 'wrap' ) ),
			),
		);
	}
	$overlay = Static_Site_Importer_Product_Presentation::capture( $entity );
	if ( 'control row' === $label ) {
		$desktop_rule = $overlay['rules']['layout:purchase-row@screen and (min-width:768px)'] ?? '';
		$narrow_rule  = $overlay['rules']['layout:purchase-row@screen and (max-width:767px)'] ?? '';
		if ( ! str_contains( $overlay['css'], '@media screen and (min-width:768px){' ) || ! str_contains( $desktop_rule, 'grid-template-columns:minmax(0,3fr) minmax(0,10fr) !important;' ) || ! str_contains( $desktop_rule, 'column-gap:11px !important;' ) || ! str_contains( $narrow_rule, 'grid-template-columns:86px minmax(0,1fr) !important;' ) ) {
			throw new RuntimeException( 'Control row must use the nearest row and its desktop media distribution.' );
		}
	}
	foreach ( array( 'layout:purchase-row', 'layout:simple-row' ) as $slot ) {
		if ( ! str_contains( $overlay['rules'][ $slot ], 'grid-template-columns:' . $tracks . ' !important;' ) || ! str_contains( $overlay['rules'][ $slot ], 'column-gap:' . $gap . ' !important;' ) ) {
			throw new RuntimeException( $label . ': purchase proportions or gap lost.' );
		}
	}
	if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) ) {
		throw new RuntimeException( $label . ': purchase receipt rejected.' );
	}
	foreach ( array( '144% minmax(0,1fr)', '144px minmax(0,100%)', '144px minmax(0,1fr) junk', '144px minmax(0,1fr);position:fixed' ) as $invalid ) {
		$bad = $overlay;
		$bad['rules']['layout:purchase-row'] = str_replace( $tracks, $invalid, $bad['rules']['layout:purchase-row'] );
		if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
			throw new RuntimeException( $label . ': invalid purchase tracks accepted.' );
		}
	}
}
// Acceptance: native stepper and portrait gallery frame, independent of source classes.
$frame_cases = array(
	'quantity stepper' => array(
		'entity_kind' => 'add_to_cart_form',
		'controls' => array(
			array( 'tag' => 'select' ),
			array( 'tag' => 'button', 'type' => 'button', 'aria-label' => 'Decrease quantity by 1' ),
			array( 'tag' => 'input', 'type' => 'number', 'styles' => array( 'width' => '42px', 'border' => '0' ) ),
			array( 'tag' => 'button', 'type' => 'button', 'aria-label' => 'Increase quantity by 1' ),
			array( 'tag' => 'button', 'type' => 'submit' ),
		),
		'presentation_graph' => array(
			'schema' => 'generic/computed-form-presentation/v3',
			'control_rows' => array( array(
				'index' => 0,
				'styles' => array( 'width' => '144px', 'border' => '1px solid rgb(48,54,54)', 'border_radius' => '24px' ),
				'members' => array( array( 'index' => 1 ), array( 'index' => 2 ), array( 'index' => 3 ) ),
			) ),
		),
	),
	'gallery frame' => array( 'gallery' => Static_Site_Importer_Product_Presentation::gallery_attrs( array(
		'blockName' => 'core/image',
		'innerHTML' => '<figure><img width="1000" height="1000" style="aspect-ratio:3/4;object-fit:cover" src="square.jpg"></figure>',
	) ) ),
);
foreach ( $frame_cases as $label => $entity ) {
	$overlay = Static_Site_Importer_Product_Presentation::capture( $entity );
	$expected = 'quantity stepper' === $label ? array( 'width:144px', 'border:1px solid rgb(48,54,54)', 'border-radius:24px' ) : array( 'aspect-ratio:3/4', 'object-fit:cover' );
	$rule = $overlay['rules'][ 'quantity stepper' === $label ? 'quantity-frame' : 'image' ] ?? '';
	foreach ( $expected as $declaration ) {
		if ( ! str_contains( $rule, $declaration . ' !important;' ) ) {
			throw new RuntimeException( $label . ': missing ' . $declaration );
		}
	}
	if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) ) {
		throw new RuntimeException( $label . ': frame receipt rejected.' );
	}
	if ( 'quantity stepper' === $label ) {
		$stepper_markup = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( $entity, array( 'id' => 42 ) );
		if ( ! str_contains( $stepper_markup, '"quantitySelectorStyle":"stepper"' ) ) {
			throw new RuntimeException( 'Native Woo stepper attribute missing.' );
		}
		$without_frame = $entity;
		$without_frame['presentation_graph']['control_rows'][0]['styles'] = array();
		if ( ! Static_Site_Importer_Product_Presentation::quantity_stepper( $without_frame ) || str_contains( Static_Site_Importer_Product_Presentation::capture( $without_frame )['rules']['quantity-frame'], 'border' ) ) {
			throw new RuntimeException( 'Missing container paint must not be inferred from input paint.' );
		}
		unset( $entity['presentation_graph']['control_rows'] );
		if ( Static_Site_Importer_Product_Presentation::quantity_stepper( $entity ) ) {
			throw new RuntimeException( 'Buttons without a dedicated row are not stepper evidence.' );
		}
	}
	$bad = $overlay;
	$bad['rules'][ 'quantity stepper' === $label ? 'quantity-frame' : 'image' ] .= 'position:fixed !important;';
	if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
		throw new RuntimeException( $label . ': open frame grammar.' );
	}
}
$unknown_width = $fixture;
unset( $unknown_width['presentation_graph']['controls'][1]['control']['styles']['width'], $unknown_width['presentation_graph']['controls'][0]['control']['styles']['width'] );
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
		'layout:gallery-block' => array( 'display:block', 'overflow:visible', 'min-width:0', 'max-width:none' ),
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
	foreach ( array( 'overflow' => array( 'hidden' ), 'max-width' => array( '512px', '100%', '0', 'auto', 'initial', 'unset', 'none;display:none' ) ) as $property => $values ) {
		foreach ( $values as $value ) {
			$bad = $overlay;
			$original = 'max-width' === $property ? 'none' : 'visible';
			$bad['rules']['layout:gallery-block'] = str_replace( $property . ':' . $original . ' !important;', $property . ':' . $value . ' !important;', $bad['rules']['layout:gallery-block'] );
			// Rebuild the receipt so rejection tests the vocabulary, not a stale hash or CSS.
			$class = 'ssi-product-' . substr( hash( 'sha256', (string) json_encode( $bad['rules'] ) ), 0, 12 );
			$bad['css'] = str_replace( $overlay['className'], $class, $bad['css'] );
			$bad['css'] = str_replace( $overlay['rules']['layout:gallery-block'], $bad['rules']['layout:gallery-block'], $bad['css'] );
			$bad['className'] = $class;
			if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
				throw new RuntimeException( $label . ': unsupported wrapper receipt accepted: ' . $property . ':' . $value );
			}
		}
	}
}
/** Minimal product meta persistence for the native Woo filter contract. */
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['ssi_wording_meta'][ $id ][ $key ] = $value;
}
function get_post_meta( $id, $key, $single = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core signature.
	return $GLOBALS['ssi_wording_meta'][ $id ][ $key ] ?? '';
}
function sanitize_title( $text ) {
	return strtolower( str_replace( ' ', '-', $text ) );
}
require_once ABSPATH . 'includes/class-static-site-importer-product-grid-runtime.php';
Static_Site_Importer_Product_Grid_Runtime::register();
// One table-driven served-route regression through the registered runtime hook.
foreach ( array(
	array( 'product', 'accessories/p/x', null, '/accessories/p/x' ),
	array( 'page', 'accessories/p/x', '/about', '/about' ),
	array( 'product', 'https://example.com/../x', null, null ),
) as list( $post_type, $source_route, $incoming, $expected_route ) ) {
	$GLOBALS['ssi_post_types'][42] = $post_type;
	update_post_meta( 42, '_static_site_importer_source_route', $source_route );
	if ( $expected_route !== apply_filters( 'blocks_engine_served_route', $incoming, 42 ) ) {
		throw new RuntimeException( 'Imported product served-route contract failed: ' . $post_type . ' / ' . $source_route );
	}
}
/** Exercise the same action scope as Woo's native variable form. */
function render_variations_form( $rows ) {
	$result = array();
	Static_Site_Importer_Product_Grid_Runtime::open_variation_form();
	foreach ( $rows as $attribute => $expected ) {
		$label = Static_Site_Importer_Product_Grid_Runtime::variation_label( $attribute, $attribute, false );
		$args = Static_Site_Importer_Product_Grid_Runtime::variation_placeholder( array( 'product' => $GLOBALS['product'], 'attribute' => $attribute, 'show_option_none' => 'Choose an option' ) );
		$result[ $attribute ] = array( $label, $args['show_option_none'] );
	}
	Static_Site_Importer_Product_Grid_Runtime::close_variation_form();
	return $result;
}
// Captured wording must survive both direct siblings and text-free wrappers.
$select = '<!-- wp:ssi/authored-select {"name":"variant-option-Size-select","options":[{"label":"\\n Select Size \\n","value":"","valueDeclared":true}]} /-->';
$wording_cases = array(
	'direct sibling' => $select,
	'wrapped select' => '<!-- wp:group {"className":"variant-select-wrapper"} --><div class="variant-select-wrapper">' . $select . '</div><!-- /wp:group -->',
);
foreach ( $wording_cases as $case => $control ) {
	$binding['search_block_markup'] = '<!-- wp:group --><div><!-- wp:paragraph --><p class="variant-option-title">Size:</p><!-- /wp:paragraph -->' . $control . '</div><!-- /wp:group -->';
	$product_row = array( 'slug' => 'stickers', 'bindings' => array( $binding ) );
	$records = Static_Site_Importer_Entity_Materializer_Registry::block_bindings(
		array( 'entities' => array( 'catalog' => array( 'adapter' => Static_Site_Importer_Woo_Product_Seeder::adapter(), 'manifest' => array( 'products' => array( $product_row ) ) ) ) ),
		array( 'catalog' => array( 'products' => array( array( 'slug' => 'stickers', 'id' => 42, 'status' => 'created' ) ) ) )
	);
	$entity = array_merge( $fixture, array( 'variation_wording' => $records[0]['product_presentation']['variation_wording'] ?? array() ) );
	if ( array( 'size' => array( 'label' => 'Size:', 'placeholder' => 'Select Size' ) ) !== $entity['variation_wording'] ) {
		throw new RuntimeException( $case . ': source wording was not captured exactly.' );
	}
	$GLOBALS['product'] = new class {
		public function get_id() { return 42; }
	};
	$rows = array( 'Size' => array( 'Size:', 'Select Size' ), 'Color' => array( 'Color', 'Choose an option' ) );
	if ( render_variations_form( $rows ) !== $rows || 'Size' !== Static_Site_Importer_Product_Grid_Runtime::variation_label( 'Size', 'Size', false ) ) {
		throw new RuntimeException( $case . ': native variation form lost source wording or leaked labels outside the form.' );
	}
	$overlay = Static_Site_Importer_Product_Presentation::capture( $entity );
	if ( $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) ) {
		throw new RuntimeException( 'Wording receipt did not round trip.' );
	}
	foreach ( array( '<b>Size:</b>', 'Size:&#10;', "Size:\n" ) as $unsafe_text ) {
		$bad = $overlay;
		$bad['variation_wording']['size']['label'] = $unsafe_text;
		if ( null !== Static_Site_Importer_Product_Presentation::validate_overlay( $bad ) ) {
			throw new RuntimeException( 'Non-plain wording accepted.' );
		}
	}
}
// A sliding-door link button paints its cap on the control and its body on the
// caption carrier. One native Woo button draws both: the carrier as a ::before
// layer inset by the control's padding, sprite paths resolved against the
// declaring stylesheet and published as theme asset URLs.
$sheet   = array( 'source_path' => 'website/files/main.css', 'source_sha256' => str_repeat( 'a', 64 ), 'selector' => '.x', 'condition' => null );
$sliding = array(
	'controls'           => array( array( 'tag' => 'a', 'type' => 'a', 'role' => 'button' ) ),
	'presentation_graph' => array(
		'controls' => array(
			array(
				'index'   => 0,
				'control' => array(
					'styles'     => array( 'background' => 'url(../media/large.png) no-repeat 100% -126px', 'background_image' => 'url(/media/hl.png)', 'color' => '#fff', 'height' => '41px', 'padding' => '0 10px 0 0' ),
					'provenance' => array( $sheet + array( 'properties' => array( 'background', 'background-image', 'color', 'height', 'padding' ) ) ),
				),
				'caption' => array(
					'styles'     => array( 'background' => 'url(../media/large.png) no-repeat 0 0', 'background_image' => 'url(javascript:alert(1))', 'height' => '41px', 'padding' => '0 10px 0 20px' ),
					'provenance' => array( $sheet + array( 'properties' => array( 'background', 'background-image', 'height', 'padding' ) ) ),
				),
			),
		),
	),
);
$overlay = Static_Site_Importer_Product_Presentation::capture( $sliding );
if ( ! $overlay || $overlay !== Static_Site_Importer_Product_Presentation::validate_overlay( $overlay ) ) {
	throw new RuntimeException( 'Sliding-door receipt did not round trip.' );
}
$assets = array(
	'website/media/large.png' => array( 'url' => 'https://example.test/theme/assets/website/media/large.png' ),
	'website/media/hl.png'    => array( 'url' => 'https://example.test/theme/assets/website/media/hl.png' ),
);
$css    = implode( '', Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/theme', 'Captured', '', $assets, array(), array( $overlay ) ) );
$scope  = '.' . $overlay['className'] . '.' . $overlay['className'] . ' .single_add_to_cart_button';
foreach ( array(
	$scope . '{'         => array( 'background:url(https://example.test/theme/assets/website/media/large.png) no-repeat 100% -126px', 'background-image:url(https://example.test/theme/assets/website/media/hl.png)', 'color:#fff', 'padding:0px 20px 0px 20px' ),
	$scope . '::before{' => array( 'content:""', 'inset:0px 10px 0px 0px', 'z-index:-1', 'background:url(https://example.test/theme/assets/website/media/large.png) no-repeat 0 0' ),
) as $selector => $expected ) {
	$rule = preg_match( '/' . preg_quote( $selector, '/' ) . '([^}]+)\}/', $css, $match ) ? $match[1] : '';
	foreach ( $expected as $declaration ) {
		if ( ! str_contains( $rule, $declaration . ' !important;' ) ) {
			throw new RuntimeException( 'Sliding-door button missing ' . $selector . ' ' . $declaration . ': ' . $rule );
		}
	}
}
if ( str_contains( $css, 'javascript' ) ) {
	throw new RuntimeException( 'Unsafe caption image reached the stylesheet.' );
}
echo "Product presentation passed.\n";
