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
if ( ! class_exists( 'Static_Site_Importer_Core_Grid_Layout_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-core-grid-layout-adapter.php';
}

/**
 * Releases the minimal host layout stylesheet on block asset enqueues.
 *
 * Projection adapters add a generated class to the host block; this runtime
 * styles those classes on the frontend and in the editor: canvas hosts render
 * as block containers, core-grid hosts render the 12-column grid.
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
		$canvas   = ( new Static_Site_Importer_Canvas_Layout_Adapter() )->host_class();
		$grid     = ( new Static_Site_Importer_Core_Grid_Layout_Adapter() )->host_class();
		$columns  = (string) Static_Site_Importer_Core_Grid_Layout_Adapter::COLUMNS;
		// The adapter owns the host's layout, so its release must beat the
		// source's own layout rules (author and editor-scoped stylesheets).
		$css      = '.' . $canvas . '{display:block!important}';
		$css     .= '.' . $canvas . '>.wp-block-tabor-canvas{width:100%;max-width:none;margin-left:0;margin-right:0}';
		$css     .= '.' . $grid . '{display:grid!important;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr))!important;grid-template-rows:none!important;grid-template-areas:none!important}';
		// Placement is now the only thing positioning or sizing each placed
		// item: source item widths, grid areas and flex sizing would fight it.
		$items    = '.' . $canvas . ' .canvas__grid>*>*,.' . $grid . '>*';
		$css     .= $items . '{width:auto!important;max-width:none!important;min-width:0!important;flex:none!important;grid-area:auto;margin-left:0!important;margin-right:0!important}';
		return $css;
	}
}
