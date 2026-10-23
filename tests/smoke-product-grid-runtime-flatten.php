<?php
/**
 * Imported cards must be real source-grid children, not display:contents boxes.
 *
 * Run: STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-product-grid-runtime-flatten.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Standalone CLI smoke using WooCommerce's block filter.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$wp_includes = rtrim( (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ), '/\\' ) . '/wp-includes/';
if ( ! is_readable( $wp_includes . 'html-api/class-wp-html-tag-processor.php' ) ) {
	fwrite( STDERR, "FAIL: WordPress Core is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
	exit( 1 );
}
foreach ( array( 'plugin.php', 'compat-utf8.php', 'compat.php', 'utf8.php', 'l10n.php', 'formatting.php', 'kses.php', 'class-wp-token-map.php', 'html-api/html5-named-character-references.php', 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php' ) as $wp_file ) {
	require_once $wp_includes . $wp_file;
}
/** Core diagnostic stub. */
function _doing_it_wrong(): void {}
/** Core charset stub. */
function is_utf8_charset(): bool {
	return true;
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-grid-runtime.php';
Static_Site_Importer_Product_Grid_Runtime::register();

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = 'FAIL: ' . $label;
	}
};

// Literal ProductTemplate.php strtr/sprintf wrappers, including newlines and
// context escaping. Inner lists catch transforms that stop at the first </li>
// or </ul>; quoted > must not confuse token boundaries.
foreach ( array( 'li', 'div' ) as $card_tag ) {
	$items = '';
	foreach ( array( 101, 202 ) as $product_id ) {
		$li_directives = '
				data-wp-interactive="woocommerce/product-collection"
				data-wp-context=\'{"woocommerce/products":{"productId":' . $product_id . ',"variationId":null}}\'
				data-wp-key="product-item-' . $product_id . '"
			';
		$items        .= strtr(
			'<li class="{classes}"
					{li_directives}
				>
					{content}
				</li>',
			array(
				'{classes}'       => 'wc-block-product post-' . $product_id . ' product type-product instock',
				'{li_directives}' => $li_directives,
				'{content}'       => '<' . $card_tag . ' class="cell" data-source="a > b"><ul class="features"><li>Feature</li></ul><button>Add</button></' . $card_tag . '>',
			)
		);
	}
	$template = sprintf( '<ul %1$s>%2$s</ul>', 'class="wc-block-product-template is-flex-container columns-4" data-wp-on--scroll="actions.watchScroll" data-wp-init="callbacks.initResizeObserver"', $items );
	foreach ( array( false, true ) as $inherit ) {
		$block  = array(
			'attrs' => array(
				'className' => 'extra ' . Static_Site_Importer_Product_Grid_Runtime::LOOP_CLASS,
				'query'     => array( 'inherit' => $inherit ),
			),
		);
		$input  = $inherit
			? '<div class="wp-block-woocommerce-product-collection ssi-item-template-loop"><section><h2>Shop</h2><ul class="grid">' . $template . '</ul><nav>Pages</nav></section></div>'
			: '<div class="wp-block-woocommerce-product-collection ssi-item-template-loop" data-wp-interactive="woocommerce/product-collection">' . $template . '</div>';
		$output = apply_filters( 'render_block_woocommerce/product-collection', $input, $block );
		if ( ! $inherit ) {
			$output = '<ul class="grid">' . $output . '</ul>';
		}
		$label = $card_tag . ( $inherit ? ' archive' : ' hand-picked' );
		$dom   = new DOMDocument();
		@$dom->loadHTML( $output ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Inspect HTML fragment with HTML5 tags.
		$xpath = new DOMXPath( $dom );
		$cards = $xpath->query( '//ul[@class="grid"]/' . $card_tag . '[contains(concat(" ", @class, " "), " cell ")]' );
		$assert( false !== $cards && 2 === $cards->length, $label . ': direct source children' );
		$assert( ! str_contains( $output, 'wc-block-product-template' ) && ! str_contains( $output, 'wc-block-product' ), $label . ': wrappers removed' );
		foreach ( array( 101, 202 ) as $index => $product_id ) {
			$card = false !== $cards ? $cards->item( $index ) : null;
			$assert( $card instanceof DOMElement && $card->getAttribute( 'data-wp-key' ) === 'product-item-' . $product_id, $label . ': product key' );
			$context = $card instanceof DOMElement ? json_decode( $card->getAttribute( 'data-wp-context' ), true ) : null;
			$assert( ( $context['woocommerce/products']['productId'] ?? null ) === $product_id, $label . ': product context' );
			$assert( $card instanceof DOMElement && str_contains( $card->getAttribute( 'class' ), 'post-' . $product_id ) && 'woocommerce/product-collection' === $card->getAttribute( 'data-wp-interactive' ), $label . ': classes and namespace' );
		}
		$features = $xpath->query( '//ul[@class="features"]/li' );
		$assert( false !== $features && 2 === $features->length, $label . ': nested lists preserved' );
		$assert( apply_filters( 'render_block_woocommerce/product-collection', $input, array( 'attrs' => array( 'className' => 'ssi-item-template-loop-other' ) ) ) === $input, $label . ': unrelated collection unchanged' );
	}
}
$assert( ! str_contains( Static_Site_Importer_Product_Grid_Runtime::layout_css(), 'wc-block-product' ), 'no retired wrapper CSS' );
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: product grid runtime flatten smoke passed.\n";
