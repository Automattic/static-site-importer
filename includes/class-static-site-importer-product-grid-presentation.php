<?php
/**
 * Front-end layout for product loops that repeat an imported card.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce wraps a product loop in its own collection, list and item
 * elements. A loop that repeats an imported card sits inside the source grid
 * element, so those wrappers are flattened and each card is laid out by the
 * source grid CSS exactly as its static cards were.
 */
class Static_Site_Importer_Product_Grid_Presentation extends Static_Site_Importer_Provider_Presentation {

	protected static function provider_slug(): string {
		return 'product-grid';
	}

	protected static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	protected static function preferred_style_handles(): array {
		return array( 'wc-blocks-style', 'woocommerce-general' );
	}

	protected static function css(): string {
		// The loop class is doubled so the flattening outranks WooCommerce's own
		// list layout (`.wc-block-product-template.is-flex-container`).
		$loop = '.' . Static_Site_Importer_Woo_Product_Grid_Materializer::LOOP_CLASS;
		$loop = $loop . $loop;
		return $loop . ',' . $loop . ' > .wc-block-product-template,' . $loop . ' > .wc-block-product-template > .wc-block-product,' . $loop . ' > .wc-block-components-notices{display:contents;}'
			// A store notice spans the whole grid row instead of taking a card's cell.
			. $loop . ' > .wc-block-components-notices > *{grid-column:1/-1;}';
	}
}
