<?php
/**
 * Smoke test: source-local assets materialize and rewrite across HTML, Markdown, CSS, and unsafe paths.
 *
 * Run from the repository root:
 * php tests/smoke-local-asset-materialization.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;

		public function __construct( string $code = '' ) {
			$this->code = $code;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		$parts = parse_url( $url );
		if ( -1 === $component ) {
			return $parts;
		}

		$map = array(
			PHP_URL_SCHEME   => 'scheme',
			PHP_URL_HOST     => 'host',
			PHP_URL_PORT     => 'port',
			PHP_URL_USER     => 'user',
			PHP_URL_PASS     => 'pass',
			PHP_URL_PATH     => 'path',
			PHP_URL_QUERY    => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);

		return isset( $map[ $component ], $parts[ $map[ $component ] ] ) ? $parts[ $map[ $component ] ] : null;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$tmp        = sys_get_temp_dir() . '/ssi-local-assets-' . uniqid();
$source_dir = $tmp . '/source';
wp_mkdir_p( $source_dir . '/assets' );
wp_mkdir_p( $source_dir . '/pages' );
wp_mkdir_p( $source_dir . '/styles' );

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' );
file_put_contents( $source_dir . '/assets/hero.png', $png );
file_put_contents( $source_dir . '/assets/bg.png', $png );

$class = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

$set_property = static function ( string $property, mixed $value ) use ( $class ): void {
	$ref = $class->getProperty( $property );
	$ref->setValue( null, $value );
};

$configure = static function ( string $policy, array $asset_map = array() ) use ( $source_dir, $set_property ): string {
	$theme_dir = sys_get_temp_dir() . '/ssi-local-assets-theme-' . uniqid();
	wp_mkdir_p( $theme_dir . '/assets/media' );

	$set_property( 'active_source_dir', $source_dir );
	$set_property( 'active_theme_dir', $theme_dir );
	$set_property( 'active_theme_uri', 'https://example.test/wp-content/themes/imported' );
	$set_property( 'active_asset_materialization_policy', $policy );
	$set_property( 'active_asset_policy', 'theme' );
	$set_property( 'active_asset_map', $asset_map );
	$set_property( 'active_asset_metadata', array() );
	$set_property( 'active_imported_media_assets', array() );
	$set_property( 'recorded_local_asset_keys', array() );
	$set_property(
		'conversion_report',
		array(
			'assets'      => array(
				'policy'       => 'theme',
				'local_policy' => $policy,
				'local'        => array(),
				'svg_icons'    => array(),
				'svg_sprites'  => array(),
			),
			'asset_map'   => array(
				'supplied'         => ! empty( $asset_map ),
				'entry_count'      => count( $asset_map ),
				'resolved_count'   => 0,
				'unresolved_count' => 0,
				'resolved'         => array(),
				'unresolved'       => array(),
			),
			'diagnostics' => array(),
		)
	);

	return $theme_dir;
};

$report_outcomes = static function () use ( $class ): array {
	$report = $class->getProperty( 'conversion_report' )->getValue();
	return array_column( $report['assets']['local'] ?? array(), 'outcome' );
};

$rewrite_html = $class->getMethod( 'rewrite_local_asset_references' );
$rewrite_markdown = $class->getMethod( 'rewrite_markdown_links' );
$rewrite_css = $class->getMethod( 'rewrite_css_asset_references' );
$normalize_policy = $class->getMethod( 'normalize_asset_materialization_policy' );

$assert( 'copy_to_theme' === $normalize_policy->invoke( null, '' ), 'default-materialization-policy-copy-to-theme' );

$theme_dir = $configure( 'copy_to_theme' );
$html         = $rewrite_html->invoke(
	null,
	'<figure><img src="../assets/hero.png" alt="Hero"><img src="../../secret.png" alt="Unsafe"></figure>',
	'pages/about.html',
	'main:pages/about.html'
);

$assert( str_contains( $html, 'src="https://example.test/wp-content/themes/imported/assets/media/assets/hero.png"' ), 'html-image-src-rewritten', $html );
$assert( is_readable( $theme_dir . '/assets/media/assets/hero.png' ), 'html-image-file-materialized' );
$assert( str_contains( $html, 'src="../../secret.png"' ), 'unsafe-html-image-left-unchanged', $html );

$markdown         = $rewrite_markdown->invoke( null, '![Hero](../assets/hero.png)', array(), 'pages/story.md', 'main:pages/story.md' );
$assert( str_contains( $markdown, '](https://example.test/wp-content/themes/imported/assets/media/assets/hero.png)' ), 'markdown-image-rewritten', $markdown );

$css         = $rewrite_css->invoke( null, '.hero{background-image:url("../assets/bg.png")}', 'styles/site.css', 'stylesheet:styles/site.css' );
$assert( str_contains( $css, 'url("https://example.test/wp-content/themes/imported/assets/media/assets/bg.png")' ), 'css-url-rewritten', $css );
$assert( is_readable( $theme_dir . '/assets/media/assets/bg.png' ), 'css-asset-file-materialized' );

$metadata = $class->getProperty( 'active_asset_metadata' )->getValue();
$assert( isset( $metadata['../assets/hero.png'] ), 'metadata-keyed-by-original-reference' );
$assert( isset( $metadata['assets/hero.png'] ), 'metadata-keyed-by-source-path' );
$assert( isset( $metadata['https://example.test/wp-content/themes/imported/assets/media/assets/hero.png'] ), 'metadata-keyed-by-materialized-url' );

$report      = $class->getProperty( 'conversion_report' )->getValue();
$diagnostics = array_column( $report['diagnostics'] ?? array(), 'type' );
$assert( in_array( 'local_asset_unsafe_path', $diagnostics, true ), 'unsafe-path-diagnostic-recorded' );
$assert( count( $report['assets']['local'] ?? array() ) >= 2, 'local-assets-recorded' );
$assert( 'copy_to_theme' === ( $report['assets']['local_policy'] ?? '' ), 'copy-policy-recorded' );
$assert( in_array( 'copied', $report_outcomes(), true ), 'copy-outcome-recorded' );
$assert( 'copy_to_theme' === ( $report['assets']['local'][0]['materialization_policy'] ?? '' ), 'copy-row-materialization-policy-recorded' );

$theme_dir = $configure( 'preserve' );
$html      = $rewrite_html->invoke( null, '<img src="../assets/hero.png" alt="Hero"><img src="../../secret.png" alt="Unsafe">', 'pages/about.html', 'main:pages/about.html' );
$markdown  = $rewrite_markdown->invoke( null, '![Hero](../assets/hero.png)', array(), 'pages/story.md', 'main:pages/story.md' );
$css       = $rewrite_css->invoke( null, '.hero{background-image:url("../assets/bg.png")}', 'styles/site.css', 'stylesheet:styles/site.css' );
$report    = $class->getProperty( 'conversion_report' )->getValue();
$assert( str_contains( $html, 'src="../assets/hero.png"' ), 'preserve-html-image-unchanged', $html );
$assert( str_contains( $markdown, '](../assets/hero.png)' ), 'preserve-markdown-image-unchanged', $markdown );
$assert( str_contains( $css, 'url("../assets/bg.png")' ), 'preserve-css-url-unchanged', $css );
$assert( ! is_readable( $theme_dir . '/assets/media/assets/hero.png' ), 'preserve-does-not-copy-html-image' );
$assert( 'preserve' === ( $report['assets']['local_policy'] ?? '' ), 'preserve-policy-recorded' );
$assert( in_array( 'preserved', $report_outcomes(), true ), 'preserve-outcome-recorded' );
$assert( 'preserve' === ( $report['assets']['local'][0]['materialization_policy'] ?? '' ), 'preserve-row-materialization-policy-recorded' );
$assert( in_array( 'local_asset_unsafe_path', array_column( $report['diagnostics'] ?? array(), 'type' ), true ), 'preserve-unsafe-path-diagnostic-recorded' );

$theme_dir = $configure(
	'use_map',
	array(
		'assets/hero.png' => array( 'url' => 'https://cdn.example.test/hero.png' ),
		'assets/bg.png'   => array( 'url' => 'https://cdn.example.test/bg.png' ),
	)
);
$html      = $rewrite_html->invoke( null, '<img src="../assets/hero.png" alt="Hero"><img src="../../secret.png" alt="Unsafe">', 'pages/about.html', 'main:pages/about.html' );
$markdown  = $rewrite_markdown->invoke( null, '![Hero](../assets/hero.png)', array(), 'pages/story.md', 'main:pages/story.md' );
$css       = $rewrite_css->invoke( null, '.hero{background-image:url("../assets/bg.png")}', 'styles/site.css', 'stylesheet:styles/site.css' );
$report    = $class->getProperty( 'conversion_report' )->getValue();
$assert( str_contains( $html, 'src="https://cdn.example.test/hero.png"' ), 'use-map-html-image-rewritten', $html );
$assert( str_contains( $markdown, '](https://cdn.example.test/hero.png)' ), 'use-map-markdown-image-rewritten', $markdown );
$assert( str_contains( $css, 'url("https://cdn.example.test/bg.png")' ), 'use-map-css-url-rewritten', $css );
$assert( ! is_readable( $theme_dir . '/assets/media/assets/hero.png' ), 'use-map-does-not-copy-html-image' );
$assert( 'use_map' === ( $report['assets']['local_policy'] ?? '' ), 'use-map-policy-recorded' );
$assert( in_array( 'mapped', $report_outcomes(), true ), 'use-map-outcome-recorded' );
$assert( 'use_map' === ( $report['assets']['local'][0]['materialization_policy'] ?? '' ), 'use-map-row-materialization-policy-recorded' );
$assert( in_array( 'local_asset_unsafe_path', array_column( $report['diagnostics'] ?? array(), 'type' ), true ), 'use-map-unsafe-path-diagnostic-recorded' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: local asset materialization smoke passed (' . $assertions . " assertions)\n";
