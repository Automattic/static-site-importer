<?php
/**
 * Minimal charset shim for source-only Core formatting before WordPress 6.9.
 *
 * @package StaticSiteImporter
 */

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Supply the charset without a database.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	function get_option( $option ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Core test stub.
		return 'blog_charset' === $option ? 'UTF-8' : '';
	}
}

if ( ! function_exists( '_canonical_charset' ) ) {
	/**
	 * These standalone fixtures only use UTF-8.
	 *
	 * @param string $charset Requested charset.
	 * @return string
	 */
	function _canonical_charset( $charset ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Core compatibility shim.
		return $charset;
	}
}
