<?php
/**
 * Base class for provider-block frontend presentation.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared frontend-presentation pipeline for materialized provider blocks.
 *
 * SSI materializes source entities as provider blocks (WooCommerce products,
 * Jetpack forms, and so on). Those blocks render with the provider's own default
 * chrome, which rarely matches the imported design. A provider presentation
 * subclass supplies scoped CSS that reconciles that chrome with the source, and
 * this base owns the generic delivery: it hooks `wp_enqueue_scripts`, guards on
 * the admin context and the provider being active, resolves a stylesheet handle
 * to attach the CSS to (preferring the provider's own handles so the reset wins
 * document order, otherwise a lightweight own handle), and emits the inline CSS.
 *
 * Subclasses implement only the three things that vary per provider:
 * `is_active()`, `preferred_style_handles()`, and `css()`.
 */
abstract class Static_Site_Importer_Provider_Presentation {

	/**
	 * Register the presentation's frontend hook.
	 *
	 * @return void
	 */
	final public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( static::class, 'enqueue' ), 20 );
	}

	/**
	 * Emit the presentation CSS, attached to a resolved stylesheet handle.
	 *
	 * @return void
	 */
	final public static function enqueue(): void {
		if ( is_admin() || ! static::is_active() ) {
			return;
		}

		$css = static::css();
		if ( '' === trim( $css ) ) {
			return;
		}

		$handle = static::resolve_style_handle();
		if ( '' === $handle ) {
			return;
		}

		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Resolve a registered/enqueued stylesheet handle to attach the CSS to. Prefers
	 * the provider's own handles so the reset follows the provider styles in the
	 * cascade, then falls back to a lightweight own handle that always ships.
	 *
	 * @return string
	 */
	final protected static function resolve_style_handle(): string {
		foreach ( static::preferred_style_handles() as $handle ) {
			if ( '' !== $handle && ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) ) {
				return $handle;
			}
		}

		$own = static::own_style_handle();
		if ( '' === $own ) {
			return '';
		}
		if ( ! wp_style_is( $own, 'registered' ) ) {
			wp_register_style( $own, false, static::own_style_dependencies(), '0.1.0' );
		}
		wp_enqueue_style( $own );

		return $own;
	}

	/**
	 * The handle for a self-registered fallback stylesheet, used when none of the
	 * provider's own handles are present.
	 *
	 * @return string
	 */
	protected static function own_style_handle(): string {
		return 'static-site-importer-' . static::provider_slug();
	}

	/**
	 * Dependencies for the self-registered fallback stylesheet so it loads after
	 * the listed handles in the cascade. Only present handles are kept.
	 *
	 * @return array<int, string>
	 */
	protected static function own_style_dependencies(): array {
		$deps = array();
		foreach ( static::preferred_style_handles() as $handle ) {
			if ( '' !== $handle && ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) ) {
				$deps[] = $handle;
			}
		}

		return $deps;
	}

	/**
	 * A short slug identifying the provider, used to name the own handle.
	 *
	 * @return string
	 */
	abstract protected static function provider_slug(): string;

	/**
	 * Whether the provider can render its blocks on this request; only then is the
	 * presentation CSS meaningful.
	 *
	 * @return bool
	 */
	abstract protected static function is_active(): bool;

	/**
	 * Stylesheet handles, in cascade-preference order, that the presentation CSS
	 * should attach to so it follows the provider's own styles.
	 *
	 * @return array<int, string>
	 */
	abstract protected static function preferred_style_handles(): array;

	/**
	 * The scoped presentation CSS for the provider's materialized blocks.
	 *
	 * @return string
	 */
	abstract protected static function css(): string;
}
