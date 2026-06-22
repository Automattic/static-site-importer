<?php
/**
 * Smoke test: importer block metadata, registration, and rendered shell.
 *
 * Run from the repository root:
 * php tests/smoke-importer-block.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) ) {
	define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$GLOBALS['ssi_registered_block'] = null;

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( string $path, array $args = array() ): bool {
		$GLOBALS['ssi_registered_block'] = array(
			'path' => $path,
			'args' => $args,
		);

		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'test-nonce';
	}
}

require_once dirname( __DIR__ ) . '/includes/block.php';

$plugin_source = file_get_contents( dirname( __DIR__ ) . '/static-site-importer.php' );
$assert( is_string( $plugin_source ), 'plugin-source-readable' );
$assert( ! str_contains( $plugin_source, 'Requires Plugins: blocks-engine-php-transformer' ), 'transformer-is-not-a-required-wordpress-plugin' );
$assert( str_contains( $plugin_source, "vendor/autoload.php" ), 'loads-composer-autoloader' );
$assert( str_contains( $plugin_source, "vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php" ), 'loads-composer-transformer-bootstrap' );

$metadata = json_decode( file_get_contents( dirname( __DIR__ ) . '/blocks/importer/block.json' ), true );
$assert( is_array( $metadata ), 'block-json-decodes' );
$assert( 'static-site-importer/importer' === ( $metadata['name'] ?? '' ), 'block-name-is-product-importer' );
$assert( 'Static Site Importer' === ( $metadata['title'] ?? '' ), 'block-title-is-product-name' );
$assert( isset( $metadata['viewScript'] ), 'block-has-frontend-script' );

static_site_importer_register_block();

$registered = $GLOBALS['ssi_registered_block'];
$assert( is_array( $registered ), 'block-registers' );
$assert( STATIC_SITE_IMPORTER_PATH . 'blocks/importer' === ( $registered['path'] ?? '' ), 'block-registers-metadata-directory' );
$assert( 'static_site_importer_render_block' === ( $registered['args']['render_callback'] ?? '' ), 'block-registers-render-callback' );

$html = static_site_importer_render_block(
	array(
		'title'      => 'Import your site',
		'intro'      => 'Upload files, paste HTML, or start from a URL.',
		'provider'   => 'Private Provider!',
		'defaultUrl' => 'https://example.com/source',
	)
);

$assert( str_contains( $html, 'data-static-site-importer' ), 'render-has-root-hook' );
$assert( str_contains( $html, 'data-static-site-importer-rest-url="https://example.test/wp-json/static-site-importer/v1/imports"' ), 'render-exposes-import-rest-route' );
$assert( str_contains( $html, 'data-static-site-importer-provider="privateprovider"' ), 'render-sanitizes-provider' );
$assert( str_contains( $html, 'data-static-site-importer-source-url' ), 'render-has-url-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-source-files' ), 'render-has-file-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-source-html' ), 'render-has-html-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-submit' ), 'render-has-submit-hook' );
$assert( str_contains( $html, 'data-static-site-importer-report' ), 'render-has-report-hook' );
$assert( str_contains( $html, 'Import your site' ), 'render-uses-custom-title' );
$assert( str_contains( $html, 'https://example.com/source' ), 'render-uses-default-url' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Importer block smoke passed (%d assertions).\n", $assertions );
