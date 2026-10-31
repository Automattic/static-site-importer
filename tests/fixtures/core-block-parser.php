<?php
/**
 * Loads WordPress Core's block parser and block API from a source-only core
 * tree. Core 7.2 builds WP_Block_Parser on WP_Block_Processor, which uses
 * WP_HTML_Span; older cores have no processor file.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI test helper.
if ( ! function_exists( 'ssi_test_require_core_block_parser' ) ) {
	function ssi_test_require_core_block_parser( string $wp_root ): bool {
		$includes = rtrim( $wp_root, '/\\' ) . '/wp-includes/';
		if ( ! is_readable( $includes . 'class-wp-block-parser.php' ) || ! is_readable( $includes . 'blocks.php' ) ) {
			return false;
		}
		// WP_Block_Processor reports spans with the HTML API's WP_HTML_Span.
		if ( is_readable( $includes . 'class-wp-block-processor.php' ) && ! class_exists( 'WP_HTML_Span', false ) ) {
			require_once $includes . 'html-api/class-wp-html-span.php';
		}
		foreach ( array( 'class-wp-block-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php', 'blocks.php' ) as $file ) {
			if ( is_readable( $includes . $file ) ) {
				require_once $includes . $file;
			}
		}
		return true;
	}
}
