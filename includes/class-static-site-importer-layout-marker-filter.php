<?php
/**
 * Capture-time block path measurement markers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks rendered blocks with their block tree path during layout capture.
 *
 * The filter is inactive unless a capture was requested through the
 * `ssi_layout_capture` query arg by a user who can manage options (or from a
 * WP-CLI render). While active, every block rendered from the main content
 * pass carries `data-ssi-block-path`, which the Playwright capture helper uses
 * to derive the host and item boxes of a placement model.
 */
final class Static_Site_Importer_Layout_Marker_Filter {

	public const QUERY_ARG      = 'ssi_layout_capture';
	public const PATH_ATTRIBUTE = 'data-ssi-block-path';
	private const CAPABILITY    = 'manage_options';

	/**
	 * @var array<int,array{path:string,children:int}>
	 */
	private static array $frames = array();

	private static int $root_children = 0;

	private static bool $capturing = false;

	/**
	 * Register the marker filters.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_action( 'init', array( self::class, 'maybe_register_capture' ) );
	}

	/**
	 * Hook the content render only when a capture was requested and allowed.
	 *
	 * @return void
	 */
	public static function maybe_register_capture(): void {
		if ( ! self::capture_requested() ) {
			return;
		}
		// Core renders blocks on `the_content` at priority 9 (do_blocks), so
		// capture must switch on before it.
		add_filter( 'the_content', array( self::class, 'begin_content_capture' ), 8 );
		add_filter( 'the_content', array( self::class, 'end_content_capture' ), PHP_INT_MAX );
		add_filter( 'render_block_data', array( self::class, 'open_frame' ) );
		add_filter( 'render_block', array( self::class, 'close_frame' ), 10, 2 );
	}

	/**
	 * Report whether the current request asked for a capture render.
	 *
	 * @return bool
	 */
	public static function capture_requested(): bool {
		if ( defined( 'WP_CLI' ) ) {
			return true;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only measurement markers carry no state to verify.
		$requested = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) ) : '';
		return '' !== $requested;
	}

	/**
	 * Reset the path state and enable marking for the main content pass.
	 *
	 * @param string $content Content about to render.
	 * @return string
	 */
	public static function begin_content_capture( string $content ): string {
		self::$frames        = array();
		self::$root_children = 0;
		self::$capturing     = true;
		return $content;
	}

	/**
	 * Disable marking once the main content pass has rendered.
	 *
	 * @param string $content Rendered content.
	 * @return string
	 */
	public static function end_content_capture( string $content ): string {
		self::$capturing = false;
		self::$frames    = array();
		return $content;
	}

	/**
	 * Assign the next block tree path before a block renders.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return array<string,mixed>
	 */
	public static function open_frame( array $block ): array {
		if ( ! self::$capturing ) {
			return $block;
		}
		if ( empty( self::$frames ) ) {
			$path = (string) self::$root_children;
			++self::$root_children;
			self::$frames[] = array(
				'path'     => $path,
				'children' => 0,
			);
			return $block;
		}
		$top_index = count( self::$frames ) - 1;
		$path      = self::$frames[ $top_index ]['path'] . '.' . (string) self::$frames[ $top_index ]['children'];
		++self::$frames[ $top_index ]['children'];
		self::$frames[] = array(
			'path'     => $path,
			'children' => 0,
		);
		return $block;
	}

	/**
	 * Mark one rendered block with its assigned path.
	 *
	 * @param string              $html  Rendered block HTML.
	 * @param array<string,mixed> $block Parsed block.
	 * @return string
	 */
	public static function close_frame( string $html, array $block ): string {
		unset( $block );
		if ( ! self::$capturing || empty( self::$frames ) ) {
			return $html;
		}
		$frame = array_pop( self::$frames );
		return self::mark_html( $html, (string) $frame['path'] );
	}

	/**
	 * Add the path attribute to the first opening tag of a render.
	 *
	 * @param string $html Rendered block HTML.
	 * @param string $path Assigned block tree path.
	 * @return string
	 */
	private static function mark_html( string $html, string $path ): string {
		$tag_start = strpos( $html, '<' );
		if ( false === $tag_start || '/' === substr( $html, $tag_start + 1, 1 ) || '!--' === substr( $html, $tag_start + 1, 3 ) ) {
			return $html;
		}
		$tag_end = strpos( $html, '>', $tag_start );
		if ( false === $tag_end ) {
			return $html;
		}
		$attribute = ' ' . self::PATH_ATTRIBUTE . '="' . ( function_exists( 'esc_attr' ) ? esc_attr( $path ) : $path ) . '"';
		if ( '/' === substr( rtrim( substr( $html, $tag_start + 1, $tag_end - $tag_start - 1 ) ), -1 ) ) {
			$slash = strrpos( substr( $html, 0, $tag_end ), '/' );
			if ( false !== $slash ) {
				return substr( $html, 0, $slash ) . $attribute . substr( $html, $slash );
			}
		}
		return substr( $html, 0, $tag_end ) . $attribute . substr( $html, $tag_end );
	}
}
