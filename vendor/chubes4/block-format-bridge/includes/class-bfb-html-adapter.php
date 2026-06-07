<?php
/**
 * HTML format adapter.
 *
 * `to_blocks()` delegates to `html_to_blocks_raw_handler()` from
 * `chubes4/html-to-blocks-converter`, which BFB bundles as a Composer
 * dependency. Built distributions call the vendor-prefixed function;
 * dev-mode/plugin installs can still call the unprefixed global.
 *
 * `from_blocks()` renders blocks through `do_blocks()` so dynamic
 * blocks resolve to their server-side HTML output.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML ↔ Blocks adapter.
 */
class BFB_HTML_Adapter implements BFB_Format_Adapter {

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return 'html';
	}

	/**
	 * @inheritDoc
	 */
	public function to_blocks( string $content, array $options = array() ): array {
		if ( '' === $content ) {
			return array();
		}

		// Already block markup — parse and return.
		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			return parse_blocks( $content );
		}

		$args = array_merge( $options, array( 'HTML' => $content ) );

		/**
		 * Filters the argument array passed to html-to-blocks-converter.
		 *
		 * BFB reserves the `HTML` key for source content. Per-call conversion
		 * options, such as `context` and `mode`, are forwarded alongside it for
		 * h2bc to consume when supported.
		 *
		 * @since 0.5.0
		 *
		 * @param array<string, mixed> $args    Raw handler arguments.
		 * @param string               $content Source HTML.
		 * @param array<string, mixed> $options Per-call conversion options.
		 */
		$args         = (array) apply_filters( 'bfb_html_to_blocks_args', $args, $content, $options );
		$args['HTML'] = $content;

		$pre_result = apply_filters( 'bfb_html_to_blocks_pre_result', null, $content, $options, $args );
		if ( is_array( $pre_result ) ) {
			return bfb_filter_html_to_blocks_result( $pre_result, $content, $options, $args );
		}

		if ( function_exists( '\BlockFormatBridge\Vendor\html_to_blocks_raw_handler' ) ) {
			$blocks = \BlockFormatBridge\Vendor\html_to_blocks_raw_handler( $args );
			return bfb_filter_html_to_blocks_result( is_array( $blocks ) ? $blocks : array(), $content, $options, $args );
		}

		if ( function_exists( 'html_to_blocks_raw_handler' ) ) {
			$blocks = html_to_blocks_raw_handler( $args );
			return bfb_filter_html_to_blocks_result( is_array( $blocks ) ? $blocks : array(), $content, $options, $args );
		}

		// Should only happen in a broken build: BFB requires
		// chubes4/html-to-blocks-converter and built distributions ship
		// the prefixed function above.
		do_action(
			'bfb_diagnostic',
			'html_to_blocks_unavailable',
			'html-to-blocks-converter is unavailable; falling back to a freeform block.',
			array( 'adapter' => 'html' )
		);
		return array(
			array(
				'blockName'    => 'core/freeform',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $content,
				'innerContent' => array( $content ),
			),
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Renders each block through `render_block()` so dynamic blocks
	 * resolve to their server-side HTML output. Static blocks pass
	 * through their inner HTML untouched.
	 */
	public function from_blocks( array $blocks, array $options = array() ): string {
		unset( $options );

		if ( empty( $blocks ) ) {
			return '';
		}

		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}
		return $html;
	}
}
