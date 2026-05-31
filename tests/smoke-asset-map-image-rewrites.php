<?php
/**
 * Smoke test: asset_map rewrites local HTML image references.
 *
 * Run from the repository root:
 * php tests/smoke-asset-map-image-rewrites.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
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

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$class = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

$asset_policy = $class->getProperty( 'active_asset_materialization_policy' );
$asset_policy->setValue( null, 'use_map' );

$active_asset_map = $class->getProperty( 'active_asset_map' );
$active_asset_map->setValue(
	null,
	array(
		'assets/hero.jpg' => array(
			'url'           => 'https://example.test/uploads/hero.jpg',
			'attachment_id' => 321,
			'width'         => 1200,
			'height'        => 800,
			'alt'           => 'Mapped hero',
		),
	)
);

$conversion_report = $class->getProperty( 'conversion_report' );
$conversion_report->setValue(
	null,
	array(
		'asset_map'   => array(
			'supplied'         => true,
			'entry_count'      => 1,
			'resolved_count'   => 0,
			'unresolved_count' => 0,
			'resolved'         => array(),
			'unresolved'       => array(),
		),
		'diagnostics' => array(),
	)
);

$rewrite = $class->getMethod( 'rewrite_asset_map_image_references' );
$html = $rewrite->invoke(
	null,
	'<figure><img src="../assets/hero.jpg" alt=""/><img src="../assets/missing.jpg" alt="Missing"/></figure>',
	'pages/about.html',
	'main:pages/about.html'
);

$assert( str_contains( $html, 'src="https://example.test/uploads/hero.jpg"' ), 'mapped-src-rewritten', $html );
$assert( str_contains( $html, 'data-id="321"' ), 'attachment-id-added', $html );
$assert( str_contains( $html, 'class="wp-image-321"' ), 'wp-image-class-added', $html );
$assert( str_contains( $html, 'width="1200"' ), 'width-added', $html );
$assert( str_contains( $html, 'height="800"' ), 'height-added', $html );
$assert( str_contains( $html, 'alt="Mapped hero"' ), 'alt-added', $html );
$assert( str_contains( $html, 'src="../assets/missing.jpg"' ), 'missing-entry-left-unchanged', $html );

$report = $conversion_report->getValue();
$assert( 1 === ( $report['asset_map']['resolved_count'] ?? null ), 'resolved-count-recorded' );
$assert( 1 === ( $report['asset_map']['unresolved_count'] ?? null ), 'unresolved-count-recorded' );
$assert( 'assets/hero.jpg' === ( $report['asset_map']['resolved'][0]['key'] ?? '' ), 'resolved-key-recorded' );
$assert( 'assets/missing.jpg' === ( $report['asset_map']['unresolved'][0]['key'] ?? '' ), 'unresolved-key-recorded' );
$assert( 'asset_map_unresolved' === ( $report['diagnostics'][0]['type'] ?? '' ), 'unresolved-diagnostic-recorded' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: asset map image rewrite smoke passed (' . $assertions . " assertions)\n";
