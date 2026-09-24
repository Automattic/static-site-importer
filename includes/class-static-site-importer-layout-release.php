<?php
/**
 * Host layout release runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Canvas_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-canvas-layout-adapter.php';
}

/**
 * Releases the minimal host layout stylesheet on block asset enqueues.
 *
 * The Canvas adapter adds a generated class to each projected host. This
 * runtime styles that class on the frontend and in the editor so the source's
 * own layout rules cannot fight the Canvas placement.
 */
final class Static_Site_Importer_Layout_Release {

	public const STYLE_HANDLE = 'static-site-importer-layout-release';

	/**
	 * Register the block asset enqueue hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'enqueue_block_assets', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the minimal host layout stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! function_exists( 'wp_add_inline_style' ) || ! function_exists( 'wp_register_style' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, array(), defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? STATIC_SITE_IMPORTER_VERSION : null );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, self::stylesheet() );
	}

	/**
	 * Return the minimal host layout stylesheet.
	 *
	 * @return string
	 */
	public static function stylesheet(): string {
		$canvas = ( new Static_Site_Importer_Canvas_Layout_Adapter() )->host_class();
		// The adapter owns the host's layout, so its release must beat the
		// source's own layout rules (author and editor-scoped stylesheets).
		$css  = '.' . $canvas . '{display:block!important}';
		$css .= '.' . $canvas . '>.wp-block-tabor-canvas{width:100%;max-width:none;margin-left:0;margin-right:0}';
		// A Canvas frame is each placed block's whole measured box: source item
		// widths, grid areas, flex sizing and margins must not add to or fight it.
		$css .= '.' . $canvas . ' .canvas__grid>*>*{width:auto!important;max-width:none!important;min-width:0!important;flex:none!important;grid-area:auto;margin:0!important}';
		return $css;
	}
}
