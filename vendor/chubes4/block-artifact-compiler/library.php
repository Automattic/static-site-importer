<?php
/**
 * Block Artifact Compiler library bootstrap.
 *
 * @package BlockArtifactCompiler
 */

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Minimal sanitize_key fallback for non-WordPress contract tests.
	 */
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal wp_json_encode fallback for non-WordPress contract tests.
	 *
	 * @param mixed $value Value to encode.
	 * @return string|false Encoded JSON or false.
	 */
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

require_once __DIR__ . '/includes/class-block-artifact-compiler.php';
require_once __DIR__ . '/includes/functions.php';
