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
		add_filter( 'render_block', array( self::class, 'expose_grid_host' ), 10, 2 );
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
	 * Write a core-grid host's native column count and gaps onto its rendered
	 * wrapper as custom properties the release stylesheet reads.
	 *
	 * @param string              $content Rendered block.
	 * @param array<string,mixed> $block   Parsed block.
	 */
	public static function expose_grid_host( $content, $block ) {
		$host = ( new Static_Site_Importer_Core_Grid_Layout_Adapter() )->host_class();
		// The projector adds the host class to the saved markup, not to the
		// className attribute, so match on the rendered wrapper.
		if ( ! is_string( $content ) || ! is_array( $block ) || ! str_contains( $content, $host ) || 'grid' !== ( $block['attrs']['layout']['type'] ?? '' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}
		$columns = (int) ( $block['attrs']['layout']['columnCount'] ?? 0 );
		$gap     = $block['attrs']['style']['spacing']['blockGap'] ?? null;
		$length  = static fn( $value ): string => is_string( $value ) && 1 === preg_match( '/^[0-9]+(?:\\.[0-9]+)?px$/', $value ) ? $value : '0px';
		$vars    = array();
		if ( $columns > 0 ) {
			$vars[] = '--ssi-layout-columns:' . $columns;
		}
		if ( is_array( $gap ) ) {
			$vars[] = '--ssi-layout-column-gap:' . $length( $gap['left'] ?? '' );
			$vars[] = '--ssi-layout-row-gap:' . $length( $gap['top'] ?? '' );
		}
		if ( array() === $vars ) {
			return $content;
		}
		$processor = new WP_HTML_Tag_Processor( $content );
		if ( ! $processor->next_tag( array( 'class_name' => $host ) ) ) {
			return $content;
		}
		$style = trim( (string) $processor->get_attribute( 'style' ) );
		$processor->set_attribute( 'style', ( '' !== $style ? rtrim( $style, ';' ) . ';' : '' ) . implode( ';', $vars ) );
		return $processor->get_updated_html();
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
		// Core renders the host's own columnCount and blockGap; the release only
		// has to outrank the source's rules, so it reads them back from the
		// custom properties `expose_grid_host()` writes on the rendered host.
		$css     .= '.' . $grid . '{display:grid!important;grid-template-columns:repeat(var(--ssi-layout-columns,' . $columns . '),minmax(0,1fr))!important;grid-template-rows:none!important;grid-template-areas:none!important;column-gap:var(--ssi-layout-column-gap,0px)!important;row-gap:var(--ssi-layout-row-gap,0px)!important}';
		// Placement is now the only thing positioning or sizing each placed
		// item: source item widths, grid areas and flex sizing would fight it.
		$items    = '.' . $canvas . ' .canvas__grid>*>*,.' . $grid . '>*';
		$css     .= $items . '{width:auto!important;max-width:none!important;min-width:0!important;flex:none!important;grid-area:auto;margin-left:0!important;margin-right:0!important}';
		return $css;
	}
}
