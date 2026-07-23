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
 */
class Static_Site_Importer_Commerce_Presentation {

	/**
	 * Register the frontend presentation hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_add_to_cart_normalization' ), 20 );
	}

	/**
	 * Whether the WooCommerce add-to-cart inline control can render in this
	 * runtime. Only then is the normalization CSS meaningful.
	 *
	 * @return bool
	 */
	private static function woocommerce_add_to_cart_available(): bool {
		return shortcode_exists( 'add_to_cart' ) || class_exists( 'WooCommerce' );
	}

	/**
	 * Attach the normalization CSS to a registered frontend stylesheet handle so
	 * it loads in document order after WooCommerce's own styles.
	 *
	 * @return void
	 */
	public static function enqueue_add_to_cart_normalization(): void {
		if ( is_admin() || ! self::woocommerce_add_to_cart_available() ) {
			return;
		}

		$handle = self::inline_style_handle();
		if ( '' === $handle ) {
			return;
		}

		wp_add_inline_style( $handle, self::add_to_cart_normalization_css() );
	}

	/**
	 * Resolve a registered, enqueued frontend stylesheet handle to attach the
	 * inline CSS to. Prefers a WooCommerce handle so the reset follows Woo's own
	 * styles in the cascade, then falls back to the active theme stylesheet.
	 *
	 * @return string
	 */
	private static function inline_style_handle(): string {
		foreach ( array( 'wc-blocks-style', 'woocommerce-general', 'woocommerce-layout', 'woocommerce-inline' ) as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
				return $handle;
			}
		}

		if ( wp_style_is( 'wc-blocks-style', 'registered' ) ) {
			return 'wc-blocks-style';
		}

		// Register a lightweight own handle so the reset still ships when no
		// WooCommerce stylesheet is enqueued (blocks-only stores).
		$own = 'static-site-importer-commerce';
		if ( ! wp_style_is( $own, 'registered' ) ) {
			wp_register_style( $own, false, array(), '0.1.0' );
		}
		wp_enqueue_style( $own );

		return $own;
	}

	/**
	 * The scoped normalization CSS for the inline add-to-cart control.
	 *
	 * @return string
	 */
	private static function add_to_cart_normalization_css(): string {
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
