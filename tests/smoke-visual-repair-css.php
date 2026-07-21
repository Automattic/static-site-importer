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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assertions = 0;
$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$artifacts = array(
	'visual_repair' => array(
		'css' => '.compiled-site-repair { display: block; }',
		'styles' => array(
			array( 'target' => 'frontend', 'content' => '.hero-shell { gap: 0; }' ),
			array( 'target' => 'editor', 'content' => '.editor-styles-wrapper .glow-orb { opacity: 1; }' ),
		),
	),
);
$collector = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'visual_repair_styles_from_artifacts' );
$styles = $collector->invoke( null, $artifacts );
$writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/visual-repair-smoke', 'Visual Repair Smoke', '.hero{display:grid}', array(), $styles );
$style = (string) ( $writes['/tmp/visual-repair-smoke/style.css'] ?? '' );
$editor = (string) ( $writes['/tmp/visual-repair-smoke/assets/css/editor-style.css'] ?? '' );
$assert( str_contains( $style, '.hero-shell { gap: 0; }' ) && str_contains( $style, '.compiled-site-repair { display: block; }' ), 'Frontend visual repair CSS is materialized.' );
$assert( ! str_contains( $style, '.glow-orb' ), 'Editor repair CSS is excluded from the frontend stylesheet.' );
$assert( str_contains( $editor, '.glow-orb { opacity: 1; }' ) && str_contains( $editor, '.compiled-site-repair { display: block; }' ), 'Editor visual repair CSS is materialized.' );

echo 'PASS smoke-visual-repair-css.php (' . $assertions . " assertions)\n";
