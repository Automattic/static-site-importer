<?php
/** Canonical visual-repair stylesheet smoke coverage. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string { return trim( strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) ), '-' ); }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-layout-overlay.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assertions = 0;
$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$styles = array(
	'frontend' => array( '.compiled-site-repair { display: block; }', '.hero-shell { gap: 0; }' ),
	'editor'   => array( '.compiled-site-repair { display: block; }', '.editor-styles-wrapper .glow-orb { opacity: 1; }' ),
);
$css = "/* Static Site Importer provider layout overlay: abcdef123456 */\n.ssi-form-123456789abc > form.jetpack-contact-form__form{display:flex}\n";
$overlay = array( 'schema' => Static_Site_Importer_Provider_Layout_Overlay::OVERLAY_SCHEMA, 'css' => $css, 'sha256' => hash( 'sha256', $css ), 'bytes' => strlen( $css ) );
$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/visual-repair-smoke', 'Visual Repair Smoke', '.hero{display:grid}', array(), $styles, array( $overlay, $overlay ) );
$style = (string) ( $writes['/tmp/visual-repair-smoke/style.css'] ?? '' );
$editor = (string) ( $writes['/tmp/visual-repair-smoke/assets/css/editor-style.css'] ?? '' );
$assert( str_contains( $style, '.hero-shell { gap: 0; }' ) && str_contains( $style, '.compiled-site-repair { display: block; }' ), 'Frontend visual repair CSS is materialized.' );
$assert( ! str_contains( $style, '.glow-orb' ), 'Editor repair CSS is excluded from the frontend stylesheet.' );
$assert( str_contains( $editor, '.glow-orb { opacity: 1; }' ) && str_contains( $editor, '.compiled-site-repair { display: block; }' ), 'Editor visual repair CSS is materialized.' );
$assert( 1 === substr_count( $style, 'provider layout overlay: abcdef123456' ) && 1 === substr_count( $editor, 'provider layout overlay: abcdef123456' ), 'Provider layout overlays are content-deduplicated in both theme stylesheets.' );
$forged = $overlay;
$forged['css'] = "/* Static Site Importer provider layout overlay: abcdef123456 */\nbody{background:url(https://example.test/x)}\n";
$forged_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/visual-repair-smoke', 'Visual Repair Smoke', '.hero{}', array(), array(), array( $forged ) );
$assert( ! str_contains( $forged_writes['/tmp/visual-repair-smoke/style.css'], 'example.test' ), 'Forged provider overlay CSS is rejected at stylesheet admission.' );

echo 'PASS smoke-visual-repair-css.php (' . $assertions . " assertions)\n";
