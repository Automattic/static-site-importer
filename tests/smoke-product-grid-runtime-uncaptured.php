<?php
/**
 * Uncaptured catalog products reuse source card layout, not source product data.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Files.FileName, Universal.Files.SeparateFunctionsFromOO, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, Squiz.Commenting.FunctionComment.MissingParamTag, Generic.CodeAnalysis.UnusedFunctionParameter -- Standalone CLI smoke with WordPress/WooCommerce API stubs.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$wp_root     = require __DIR__ . '/fixtures/wordpress-core-root.php';
$wp_includes = $wp_root . '/wp-includes/';
if ( ! is_readable( $wp_includes . 'html-api/class-wp-html-tag-processor.php' ) ) {
	fwrite( STDERR, "FAIL: WordPress Core is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 1 );
}
foreach ( array( 'plugin.php', 'compat.php', 'compat-utf8.php', 'utf8.php', 'l10n.php', 'formatting.php', 'kses.php', 'class-wp-token-map.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php', 'blocks.php' ) as $wp_file ) {
	// These UTF-8 helpers were split out after the pinned Core fixture.
	if ( in_array( $wp_file, array( 'utf8.php', 'compat-utf8.php' ), true ) && ! is_readable( $wp_includes . $wp_file ) ) {
		continue;
	}
	require_once $wp_includes . $wp_file;
}
require_once __DIR__ . '/fixtures/core-charset.php';
/** Core diagnostic stub. */
function _doing_it_wrong(): void {}
/** Core charset stub. */
function is_utf8_charset(): bool {
	return true;
}
/** Minimal URL protocol allowlist. */
function wp_allowed_protocols(): array {
	return array( 'http', 'https' );
}
/** Core JSON wrapper stub. */
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Core encoding wrapper fixture.
}
/** Minimal catalog product. */
class WC_Product {
	/** Product fixture id. */
	public function __construct( private int $id ) {}
	/** Catalog id. */
	public function get_id(): int {
		return $this->id;
	}
	/** Catalog name includes characters that must stay text. */
	public function get_name(): string {
		return 11 === $this->id ? 'Alpha & Item' : 'Beta <Item> & "New"';
	}
	/** Catalog price. */
	public function get_price(): string {
		return 11 === $this->id ? '1234.50' : '2345.75';
	}
	/** Product link. */
	public function get_permalink(): string {
		return 11 === $this->id ? '/products/alpha/' : '/products/beta/';
	}
	/** Featured image, or no image. */
	public function get_image_id(): int {
		return in_array( $this->id, array( 13, 14 ), true ) ? 0 : $this->id * 10;
	}
	/** Gallery ordered after the featured image. */
	public function get_gallery_image_ids(): array {
		return 13 === $this->id ? array() : array( $this->id * 10 + 1 );
	}
}
/** Current loop id. */
function get_the_ID(): int {
	return $GLOBALS['ssi_current_product'];
}
/** Catalog lookup. */
function wc_get_product( int $id ): WC_Product {
	return new WC_Product( $id );
}
/** Matching-size attachment fixture. */
function wp_get_attachment_image_src( int $id, mixed $size ): array {
	return array( '/images/beta-' . $id . '.jpg', 320, 240, true );
}
/** Responsive attachment fixture. */
function wp_get_attachment_image_srcset( int $id, mixed $size ): string|false {
	return 141 === $id ? false : '/images/beta-' . $id . '.jpg 320w';
}
/** Responsive sizes fixture. */
function wp_get_attachment_image_sizes( int $id, mixed $size ): string {
	return '(max-width: 320px) 100vw, 320px';
}
/** Attachment alt fixture. */
function get_post_meta( int $id, string $key, bool $single ): string {
	return 'Beta image ' . $id;
}
/** Woo placeholder fixture. */
function wc_placeholder_img_src(): string {
	return '/images/placeholder.png';
}
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Minimal Core post fixture alongside the catalog stub.
/** Seeded post fixture. */
class WP_Post {
	/** Post identity. */
	public function __construct( public int $ID ) {} // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Core property name.
}
/** Seeded product resolution. */
function get_page_by_path( string $slug, mixed $output, string $type ): ?WP_Post {
	return new WP_Post(
		array(
			'alpha' => 11,
			'beta'  => 12,
		)[ $slug ] ?? 13
	);
}
/** Render globally registered dynamic card fixtures and structural wrappers. */
function ssi_render_fixture( array $block ): string {
	if ( isset( $GLOBALS['ssi_dynamic_cards'][ $block['blockName'] ] ) ) {
		return $GLOBALS['ssi_dynamic_cards'][ $block['blockName'] ];
	}
	$output = '';
	$index  = 0;
	foreach ( $block['innerContent'] as $chunk ) {
		$output .= null === $chunk ? ssi_render_fixture( $block['innerBlocks'][ $index++ ] ) : $chunk;
	}
	return $output;
}
add_filter( 'pre_render_block', static fn( mixed $content, array $block ): string => ssi_render_fixture( $block ), 10, 2 );
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-grid-materializer.php';

$content = '<article class="card"><a href="/products/alpha/" title="Alpha &amp; Item"><span>Alpha &amp; Item</span></a><img src="/images/alpha.jpg" srcset="/images/alpha.jpg 320w" sizes="320px" alt="Alpha image" width="320" height="240"><img src="/images/alpha-gallery.jpg" alt="Alpha gallery"></article>';
$markup  = '<!-- wp:html -->' . $content . '<!-- /wp:html -->';
$node    = parse_blocks( $markup )[0];
$method  = new ReflectionMethod( Static_Site_Importer_Woo_Product_Grid_Materializer::class, 'fill_slot' );
$args    = array(
	&$node,
	array(),
	array(
		'id'   => 'card',
		'role' => 'field',
	),
	array( 11 => array( 'card' => array( 'markup' => $markup ) ) ),
);
$method->invokeArgs( null, $args );
$slot     = $node['attrs']['metadata'][ Static_Site_Importer_Product_Grid_Runtime::SLOT_KEY ];
$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = 'FAIL: ' . $label;
	}
};
$assert( 11 === $slot['template']['product_id'], 'materializer records actual template product' );
$assert( '/images/alpha.jpg' === $slot['template']['images'][0]['src'] && 'Alpha & Item' === $slot['template']['name'], 'marker stores source values' );
// The template need not be the first captured product in the catalog.
$later_markup = str_replace( array( 'Alpha', 'alpha' ), array( 'Later', 'later' ), $markup );
$card_method  = new ReflectionMethod( Static_Site_Importer_Woo_Product_Grid_Materializer::class, 'card_markup' );
$later_card   = $card_method->invoke(
	null,
	array(
		'item_template' => array(
			'block_markup' => $later_markup,
			'slots'        => array(
				array(
					'id'   => 'card',
					'role' => 'field',
					'path' => array(),
				),
			),
		),
	),
	array(
		11 => array( 'card' => array( 'markup' => $markup ) ),
		12 => array( 'card' => array( 'markup' => $later_markup ) ),
	)
);
$later_node   = parse_blocks( $later_card )[0];
$assert( 12 === $later_node['attrs']['metadata'][ Static_Site_Importer_Product_Grid_Runtime::SLOT_KEY ]['template']['product_id'], 'template identity is not the first captured product' );
$GLOBALS['ssi_current_product'] = 12;
$output                         = Static_Site_Importer_Product_Grid_Runtime::render_slot( $content, $node );
$assert( ! str_contains( strtolower( $output ), 'alpha' ), 'no template product leakage' );
$assert( str_contains( $output, '/products/beta/' ) && str_contains( $output, '/images/beta-120.jpg' ) && str_contains( $output, '/images/beta-121.jpg' ), 'current permalink, featured image and ordered gallery' );
$assert( str_contains( $output, 'Beta &lt;Item&gt; &amp;' ) && ! str_contains( $output, '<Item>' ), 'current name stays text, not markup' );
$assert( str_contains( $output, 'class="card"' ) && str_contains( $output, 'Beta image 120' ), 'source layout retained and alt replaced' );
$GLOBALS['ssi_current_product'] = 13;
$output                         = Static_Site_Importer_Product_Grid_Runtime::render_slot( $content, $node );
$assert( 2 === substr_count( $output, '/images/placeholder.png' ) && ! str_contains( $output, 'srcset=' ) && ! str_contains( $output, 'sizes=' ), 'imageless product gets placeholder without stale responsive attributes' );
$assert( ! str_contains( strtolower( $output ), 'alpha' ), 'placeholder card has no template values' );
$GLOBALS['ssi_current_product'] = 14;
$output                         = Static_Site_Importer_Product_Grid_Runtime::render_slot( $content, $node );
$assert( str_contains( $output, '/images/beta-141.jpg' ) && str_contains( $output, '/images/placeholder.png' ) && ! str_contains( $output, 'srcset=' ), 'gallery-only product uses gallery first, single URL clears source srcset' );
$node['attrs']['metadata'][ Static_Site_Importer_Product_Grid_Runtime::SLOT_KEY ]['optional'] = true;
$assert( '' === Static_Site_Importer_Product_Grid_Runtime::render_slot( $content, $node ), 'uncaptured optional slot remains empty' );
// Two independently registered dynamic cards share layout, not data or names.
$dynamic_alpha                  = '<article class="blocks-engine-source-card"><a href="https://store.example/products/alpha/"><img src="/images/alpha.jpg"><span>Alpha &amp; Item</span><b>$1,234.50</b><small>12 payments of $102.88</small></a></article>';
$dynamic_beta                   = str_replace( array( 'alpha', 'Alpha &amp; Item', '$1,234.50', '$102.88' ), array( 'beta', 'Captured Beta', '$2,345.75', '$195.48' ), $dynamic_alpha );
$GLOBALS['ssi_dynamic_cards']   = array(
	'neutral/list-one' => $dynamic_alpha,
	'neutral/list-two' => $dynamic_beta,
);
$make_grid                      = static function ( string $name, string $slug, string $slot_id ): array {
	$card = '<!-- wp:neutral/' . $name . ' /-->';
	return array(
		'item_template' => array(
			'block_markup' => $card,
			'slots'        => array(
				array(
					'id'   => $slot_id,
					'role' => 'field',
					'path' => array(),
				),
			),
		),
		'items'         => array(
			array(
				'product' => $slug,
				'slots'   => array( $slot_id => array( 'markup' => $card ) ),
			),
		),
		'bindings'      => array( array( 'search_block_markup' => '<!-- wp:group --><div><!-- wp:neutral/' . $name . ' /--></div><!-- /wp:group -->' ) ),
	);
};
$own_grid                       = $make_grid( 'list-one', 'alpha', 'card' );
$other_grid                     = $make_grid( 'list-two', 'beta', 'other-card' );
$own_grid['captured_grids']     = array( $other_grid, $make_grid( 'list-two', 'alpha', 'other-card' ) );
$archive                        = Static_Site_Importer_Woo_Product_Grid_Materializer::inherited_grid_block_markup( $own_grid );
$archive_card                   = parse_blocks( $archive )[0]['innerBlocks'][0]['innerBlocks'][0];
$GLOBALS['ssi_current_product'] = 12;
$assert( Static_Site_Importer_Product_Grid_Runtime::render_slot( $dynamic_alpha, $archive_card ) === $dynamic_beta, 'other listing captured card renders exact source markup' );
$GLOBALS['ssi_current_product'] = 11;
$assert( Static_Site_Importer_Product_Grid_Runtime::render_slot( $dynamic_alpha, $archive_card ) === $dynamic_alpha, 'own captured card unchanged' );
$GLOBALS['ssi_current_product'] = 14;
$output                         = Static_Site_Importer_Product_Grid_Runtime::render_slot( $dynamic_alpha, $archive_card );
$assert( str_contains( $output, 'href="/products/beta/"' ) && ! str_contains( $output, 'store.example' ), 'dynamic uncaptured permalink comes from rendered template' );
$assert( str_contains( $output, '$2,345.75' ) && ! str_contains( $output, '$1,234.50' ), 'dynamic uncaptured price preserves source format' );
$assert( str_contains( $output, '12 payments of $102.88' ), 'derived instalment text remains untouched' );
// A card whose markup carries a class the archive's own cards never render
// would be unstyled on the archive, so it is not pooled.
$GLOBALS['ssi_dynamic_cards']['neutral/list-three'] = str_replace( 'blocks-engine-source-card', 'blocks-engine-source-card blocks-engine-source-badge', $dynamic_beta );
$unstyled                       = $own_grid;
$unstyled['captured_grids']     = array( $make_grid( 'list-three', 'beta', 'other-card' ) );
$unstyled_card                  = parse_blocks( Static_Site_Importer_Woo_Product_Grid_Materializer::inherited_grid_block_markup( $unstyled ) )[0]['innerBlocks'][0]['innerBlocks'][0];
$GLOBALS['ssi_current_product'] = 12;
$assert( ! str_contains( Static_Site_Importer_Product_Grid_Runtime::render_slot( $dynamic_alpha, $unstyled_card ), 'blocks-engine-source-badge' ), 'card with classes absent from the archive cards is not pooled' );
// The other listing's template card is on sale (an extra badge), but this
// product's own captured card renders only classes the archive already styles.
$sale_listing            = $make_grid( 'list-three', 'gamma', 'other-card' );
$sale_listing['items'][] = array(
	'product' => 'beta',
	'slots'   => array( 'other-card' => array( 'markup' => '<!-- wp:neutral/list-two /-->' ) ),
);
$mixed                   = $own_grid;
$mixed['captured_grids'] = array( $sale_listing );
$mixed_card              = parse_blocks( Static_Site_Importer_Woo_Product_Grid_Materializer::inherited_grid_block_markup( $mixed ) )[0]['innerBlocks'][0]['innerBlocks'][0];
$assert( Static_Site_Importer_Product_Grid_Runtime::render_slot( $dynamic_alpha, $mixed_card ) === $dynamic_beta, 'styled card pooled from a listing whose template card differs' );
$price_method = method_exists( Static_Site_Importer_Product_Grid_Runtime::class, 'replace_price_figure' ) ? new ReflectionMethod( Static_Site_Importer_Product_Grid_Runtime::class, 'replace_price_figure' ) : null;
$assert( null !== $price_method && '2.345,75 €' === $price_method->invoke( null, '1.234,50 €', '1234.50', '2345.75' ), 'decimal comma and trailing currency preserved' );
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: product grid runtime uncaptured smoke passed.\n";
