<?php
/**
 * Frontend presentation normalization for materialized WooCommerce controls.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes the WooCommerce `[add_to_cart]` inline control so a materialized
 * store inherits the imported theme's minimal design instead of WooCommerce's
 * opinionated default chrome.
 *
 * SSI seeds product cart controls as the WooCommerce `[add_to_cart]` shortcode,
 * which renders `p.product.add_to_cart_inline` with a duplicated price and a
 * filled default button. Source designs typically pair a compact quantity
 * stepper with a slim, theme-styled action button. This resets WooCommerce's
 * default footprint so the materialized control blends with the surrounding
 * theme: the redundant inline price is hidden, and the button drops its filled
 * background/heavy padding to inherit adjacent control styling. All rules are
 * scoped to the inline add-to-cart control so no other WooCommerce surface is
 * affected, and the CSS is only emitted when WooCommerce actually renders on
 * the page.
 *
 * The generic delivery pipeline — hooking, admin/active guards, stylesheet
 * handle resolution, and inline enqueue — lives in the provider presentation
 * base; this subclass supplies only the WooCommerce-specific active check,
 * preferred handles, and CSS.
 */
class Static_Site_Importer_Commerce_Presentation extends Static_Site_Importer_Provider_Presentation {

	/**
	 * @inheritDoc
	 */
	protected static function provider_slug(): string {
		return 'commerce';
	}

	/**
	 * The WooCommerce add-to-cart inline control can render when its shortcode is
	 * registered or WooCommerce is loaded. Only then is the reset meaningful.
	 *
	 * @return bool
	 */
	protected static function is_active(): bool {
		return shortcode_exists( 'add_to_cart' ) || class_exists( 'WooCommerce' );
	}

	/**
	 * WooCommerce stylesheet handles, in cascade-preference order, so the reset
	 * follows Woo's own styles.
	 *
	 * @return array<int, string>
	 */
	protected static function preferred_style_handles(): array {
		return array( 'wc-blocks-style', 'woocommerce-general', 'woocommerce-layout', 'woocommerce-inline' );
	}

	/**
	 * The scoped normalization CSS for the inline add-to-cart control.
	 *
	 * @return string
	 */
	protected static function css(): string {
		return implode(
			'',
			array(
				// Compact the inline control container so it collapses to the
				// height of the adjacent quantity stepper instead of reserving a
				// full paragraph block. WooCommerce renders the control inside a
				// `<p>` whose default block margins expand the surrounding flex row;
				// these are neutralized and the control is pinned to the stepper
				// height so the action row does not inflate the card.
				'.woocommerce p.product.add_to_cart_inline,p.product.add_to_cart_inline,.add_to_cart_inline{display:inline-flex;align-items:center;align-self:center;gap:.5rem;margin:0;padding:0;min-height:0;height:2rem;font-size:1rem;line-height:1;border:0;background:none;}',
				// Hide the price that the inline control repeats; the card already
				// shows the canonical price above the action row.
				'.add_to_cart_inline .woocommerce-Price-amount,.add_to_cart_inline > .amount{display:none;}',
				// Reset the default filled button to inherit the surrounding theme
				// styling instead of WooCommerce's opinionated background/padding.
				// The button is sized to the quantity stepper height so the whole
				// action row stays compact; the label is uppercased and shrunk and
				// the border dimmed so it reads as a slim, theme-consistent control.
				'.add_to_cart_inline a.button.add_to_cart_button,.add_to_cart_inline a.add_to_cart_button.wp-element-button{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:2rem;background:transparent;background-image:none;color:inherit;font-family:inherit;font-size:.62em;font-weight:inherit;text-transform:uppercase;letter-spacing:.08em;padding:0 .9rem;margin:0;min-height:0;min-width:0;line-height:1;border:1px solid;border-color:currentColor;border-color:color-mix(in srgb,currentColor 22%,transparent);border-radius:0;box-shadow:none;white-space:nowrap;}',
			)
		);
	}
}
