<?php
/**
 * Block Artifact Compiler library bootstrap.
 *
 * @package BlockArtifactCompiler
 */

if ( ! function_exists( 'bac_sanitize_key' ) ) {
	/**
	 * Sanitize keys in and out of WordPress.
	 */
	function bac_sanitize_key( string $key ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $key );
		}

		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'bac_json_encode' ) ) {
	/**
	 * Encode JSON in and out of WordPress.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false Encoded JSON or false.
	 */
	function bac_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $value, $flags, $depth );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the non-WordPress fallback for JSON encoding.
		return json_encode( $value, $flags, max( 1, $depth ) );
	}
}

require_once __DIR__ . '/includes/class-block-artifact-compiler.php';
require_once __DIR__ . '/includes/functions.php';
