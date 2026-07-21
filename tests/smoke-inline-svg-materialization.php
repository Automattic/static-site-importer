<?php
/**
 * Smoke coverage for safe inline SVG core/html promotion.
 *
 * Run from the repository root:
 * php tests/smoke-inline-svg-materialization.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

foreach (
	array(
		'trailingslashit'     => static fn( string $value ): string => rtrim( $value, '/\\' ) . '/',
		'get_theme_root_uri'  => static fn( string $stylesheet = '' ): string => 'https://example.test/wp-content/themes',
		'wp_json_encode'      => static fn( mixed $value, int $flags = 0, int $depth = 512 ): string|false => json_encode( $value, $flags, $depth ),
		'esc_url_raw'         => static fn( string $value ): string => $value,
		'esc_url'             => static fn( string $value ): string => $value,
		'esc_attr'            => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
		'esc_html'            => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
		'sanitize_text_field' => static fn( string $value ): string => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) ?? '' ),
		'wp_strip_all_tags'   => static fn( string $value ): string => strip_tags( $value ),
		'sanitize_key'        => static fn( string $value ): string => strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' ),
		'sanitize_title'      => static fn( string $value ): string => trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?? '' ), '-' ),
	) as $function => $implementation
) {
	if ( ! function_exists( $function ) ) {
		eval( 'function ' . $function . '(...$args) { global $ssi_inline_svg_stubs; return $ssi_inline_svg_stubs[__FUNCTION__](...$args); }' );
	}
}
$ssi_inline_svg_stubs = array(
	'trailingslashit'     => static fn( string $value ): string => rtrim( $value, '/\\' ) . '/',
	'get_theme_root_uri'  => static fn( string $stylesheet = '' ): string => 'https://example.test/wp-content/themes',
	'wp_json_encode'      => static fn( mixed $value, int $flags = 0, int $depth = 512 ): string|false => json_encode( $value, $flags, $depth ),
	'esc_url_raw'         => static fn( string $value ): string => $value,
	'esc_url'             => static fn( string $value ): string => $value,
	'esc_attr'            => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
	'esc_html'            => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
	'sanitize_text_field' => static fn( string $value ): string => trim( preg_replace( '/\s+/', ' ', strip_tags( $value ) ) ?? '' ),
	'wp_strip_all_tags'   => static fn( string $value ): string => strip_tags( $value ),
	'sanitize_key'        => static fn( string $value ): string => strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' ),
	'sanitize_title'      => static fn( string $value ): string => trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?? '' ), '-' ),
);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-identity.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$safe_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" role="img" aria-label="Check"><path d="M0 0h10v10H0z" fill="#6cdab0"></path></svg>';
$unsafe   = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0h10v10H0z"></path></svg>';
$content  = '<!-- wp:group --><div class="wp-block-group"><!-- wp:html {"content":' . wp_json_encode( $safe_svg ) . '} -->' . $safe_svg . '<!-- /wp:html --><!-- wp:html {"content":' . wp_json_encode( $unsafe ) . '} -->' . $unsafe . '<!-- /wp:html --></div><!-- /wp:group -->';
$page     = Static_Site_Importer_Source_Page::from_wordpress_document_artifact(
	array(
		'source_path'  => 'index.html',
		'slug'         => 'home',
		'title'        => 'Home',
		'entrypoint'   => '1',
		'block_markup' => $content,
	)
);

$artifacts = Static_Site_Importer_Page_Materializer::page_artifacts( array( 'index.html' => $page ), 'inline-svg-theme' );
$output    = $artifacts['contents']['index.html'] ?? '';

$assert( str_contains( $output, '<!-- wp:image ' ), 'safe-svg-promoted-to-image' );
$assert( str_contains( $output, 'blocks-engine-inline-svg' ), 'promoted-image-class' );
$assert( 1 === count( $artifacts['asset_writes'] ?? array() ), 'one-svg-asset-write' );
$assert( str_contains( array_key_first( $artifacts['asset_writes'] ?? array() ) ?? '', 'assets/materialized/inline-svg/home-' ), 'stable-svg-asset-path' );
$assert( str_contains( $output, 'onload=' ), 'unsafe-svg-remains-html' );
$assert( 1 === substr_count( $output, '<!-- wp:html' ), 'only-unsafe-html-remains' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS smoke-inline-svg-materialization.php (' . $assertions . " assertions)\n";
