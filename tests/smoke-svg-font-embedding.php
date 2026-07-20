<?php
/**
 * Smoke coverage for self-contained Google Fonts in generated SVG assets.
 *
 * Run from the repository root:
 * php tests/smoke-svg-font-embedding.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_svg_font_filters'] = array();
$GLOBALS['ssi_svg_font_requests'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['ssi_svg_font_filters'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['ssi_svg_font_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}
foreach ( array( 'trailingslashit', 'get_theme_root_uri', 'wp_parse_url', 'wp_remote_retrieve_body', 'wp_remote_retrieve_response_code', 'wp_json_encode', 'esc_html', 'esc_url_raw', 'esc_url', 'esc_attr', 'sanitize_key', 'sanitize_title' ) as $function ) {
	if ( ! function_exists( $function ) ) {
		eval( 'function ' . $function . '(...$args) { return $GLOBALS["ssi_svg_font_stubs"][__FUNCTION__](...$args); }' );
	}
}
$GLOBALS['ssi_svg_font_stubs'] = array(
	'trailingslashit' => static fn( string $value ): string => rtrim( $value, '/\\' ) . '/',
	'get_theme_root_uri' => static fn( string $stylesheet = '' ): string => 'https://example.test/themes',
	'wp_parse_url' => static fn( string $url ): array|false => parse_url( $url ),
	'wp_remote_retrieve_body' => static fn( array $response ): string => (string) ( $response['body'] ?? '' ),
	'wp_remote_retrieve_response_code' => static fn( array $response ): int => (int) ( $response['response']['code'] ?? 0 ),
	'wp_json_encode' => static fn( mixed $value, int $flags = 0, int $depth = 512 ): string|false => json_encode( $value, $flags, $depth ),
	'esc_html' => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
	'esc_url_raw' => static fn( string $value ): string => $value,
	'esc_url' => static fn( string $value ): string => $value,
	'esc_attr' => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
	'sanitize_key' => static fn( string $value ): string => strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' ),
	'sanitize_title' => static fn( string $value ): string => trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?? '' ), '-' ),
);
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = array() ): mixed {
		$GLOBALS['ssi_svg_font_requests'][] = array( 'url' => $url, 'args' => $args );
		return apply_filters( 'pre_http_request', false, $args, $url );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';

$failures = array();
$assertions = 0;
$assert = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']';
	}
};

$css_url = 'https://fonts.googleapis.com/css2?family=Example+Family:wght@400';
$font_url = 'https://fonts.gstatic.com/s/example-family/v1/example.woff2';
add_filter(
	'pre_http_request',
	static function ( mixed $preempt, array $args, string $url ) use ( $css_url, $font_url ): mixed {
		if ( $css_url === $url ) {
			return array( 'body' => "@font-face{font-family:'Example Family';font-style:normal;src:url($font_url) format('woff2')}", 'response' => array( 'code' => 200 ) );
		}
		if ( $font_url === $url ) {
			return array( 'body' => 'woff2-payload', 'response' => array( 'code' => 200 ) );
		}
		return new WP_Error( 'unexpected_request' );
	},
	10,
	3
);

$plan = static function ( array $imports, string $svg ): array {
	$stylesheet_content = implode( '', array_map( static fn( string $import ): string => '@import url("' . $import . '");', $imports ) );
	return array(
		'site' => array(
			'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
			'theme' => array(
				'font_materialization' => array(
					'schema' => 'blocks-engine/php-transformer/font-materialization-plan/v1',
					'provider' => 'google_fonts',
					'fonts' => array( array( 'family' => 'Example Family' ) ),
					'stylesheets' => array( array( 'path' => 'assets/css/fonts.css', 'content' => $stylesheet_content ) ),
				),
			),
			'assets' => array( array( 'path' => 'images/label.svg', 'kind' => 'svg', 'mime_type' => 'image/svg+xml', 'content' => $svg, 'source_role' => 'importer_owned', 'keep_source' => false ) ),
		),
	);
};
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><text font-family="Example Family">Label</text></svg>';
$theme_dir = sys_get_temp_dir() . '/ssi-svg-font-' . bin2hex( random_bytes( 6 ) );
$deprecations = array();
set_error_handler(
	static function ( int $severity, string $message ) use ( &$deprecations ): bool {
		if ( E_DEPRECATED === $severity ) {
			$deprecations[] = $message;
			return true;
		}
		return false;
	}
);
$result = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), $svg ), true );
restore_error_handler();
$result_error = is_wp_error( $result ) ? $result->get_error_code() : '';
$written = $theme_dir . '/assets/materialized/images/label.svg';
$payload = file_exists( $written ) ? (string) file_get_contents( $written ) : '';
$assert( ! is_wp_error( $result ), 'embedding-materializes-' . $result_error );
$result = is_array( $result ) ? $result : array( 'assets' => array(), 'diagnostics' => array(), 'svg_font_faces' => '' );
$assert( str_contains( $payload, '<style type="text/css">@font-face' ), 'font-face-is-injected-before-write' );
$assert( str_contains( $payload, 'data:font/woff2;base64,' . base64_encode( 'woff2-payload' ) ), 'font-payload-is-self-contained' );
$embedded_stylesheet = $theme_dir . '/assets/css/embedded-fonts.css';
$embedded_stylesheet_payload = file_exists( $embedded_stylesheet ) ? (string) file_get_contents( $embedded_stylesheet ) : '';
$assert( str_contains( $embedded_stylesheet_payload, 'data:font/woff2;base64,' . base64_encode( 'woff2-payload' ) ), 'self-contained-font-stylesheet-is-written' );
$assert( 'text/css' === ( $result['assets']['assets/css/embedded-fonts.css']['mime_type'] ?? '' ), 'self-contained-font-stylesheet-is-reported' );
$assert( ! str_contains( $payload, $font_url ), 'attachment-safe-svg-has-no-external-font-url' );
$assert( 'image/svg+xml' === ( $result['assets']['images/label.svg']['mime_type'] ?? '' ), 'asset-metadata-is-preserved' );
$assert( 2 === count( $GLOBALS['ssi_svg_font_requests'] ), 'stylesheet-and-font-are-fetched-once-per-materialization' );
$assert( $css_url === ( $GLOBALS['ssi_svg_font_requests'][0]['url'] ?? '' ), 'stylesheet-request-url-is-preserved' );
$assert( 262144 === ( $GLOBALS['ssi_svg_font_requests'][0]['args']['limit_response_size'] ?? 0 ), 'stylesheet-request-is-bounded' );
$assert( 2097152 === ( $GLOBALS['ssi_svg_font_requests'][1]['args']['limit_response_size'] ?? 0 ), 'font-request-is-bounded' );
$assert( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' === ( $GLOBALS['ssi_svg_font_requests'][0]['args']['headers']['User-Agent'] ?? '' ), 'stylesheet-request-uses-browser-user-agent' );
$assert( $GLOBALS['ssi_svg_font_requests'][0]['args']['headers']['User-Agent'] === ( $GLOBALS['ssi_svg_font_requests'][1]['args']['headers']['User-Agent'] ?? '' ), 'font-request-uses-browser-user-agent' );
$assert( empty( $deprecations ), 'embedding-emits-no-deprecations' );

$inline_svg = '<svg xmlns="http://www.w3.org/2000/svg"><text font-family="Example Family">Page label</text></svg>';
$page_markup = '<!-- wp:html {"content":' . wp_json_encode( $inline_svg ) . '} -->' . $inline_svg . '<!-- /wp:html -->';
$page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact(
	array(
		'source_path' => 'index.html',
		'slug' => 'home',
		'block_markup' => $page_markup,
	)
);
$usage_markup = $page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::svg_font_usage_markup( array( 'index.html' => $page ) ) : '';
$before = count( $GLOBALS['ssi_svg_font_requests'] );
$page_result = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' ), false, $usage_markup );
$page_result = is_array( $page_result ) ? $page_result : array( 'assets' => array(), 'svg_font_faces' => '' );
$page_artifacts = $page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::page_artifacts( array( 'index.html' => $page ), 'svg-font-theme', $page_result['assets'], array(), array(), array( 'svg_font_faces' => $page_result['svg_font_faces'] ) ) : array( 'asset_writes' => array() );
$page_svg_path = array_key_first( $page_artifacts['asset_writes'] ?? array() ) ?? '';
$page_svg = (string) ( $page_artifacts['asset_writes'][ $page_svg_path ] ?? '' );
$assert( 2 === count( $GLOBALS['ssi_svg_font_requests'] ) - $before, 'page-only-inline-svg-resolves-stylesheet-and-font-once' );
$assert( str_contains( $page_svg, 'data:font/woff2;base64,' . base64_encode( 'woff2-payload' ) ), 'page-only-inline-svg-receives-embedded-font-before-write' );
$assert( str_contains( $page_svg_path, substr( sha1( rtrim( $page_svg ) ), 0, 12 ) ), 'page-only-inline-svg-hashes-embedded-font-payload' );

$plain_inline_svg = '<svg xmlns="http://www.w3.org/2000/svg"><text font-family="Other Family">Page label</text></svg>';
$plain_page = Static_Site_Importer_Source_Page::from_wordpress_document_artifact(
	array(
		'source_path' => 'plain.html',
		'slug' => 'plain',
		'block_markup' => '<!-- wp:html {"content":' . wp_json_encode( $plain_inline_svg ) . '} -->' . $plain_inline_svg . '<!-- /wp:html -->',
	)
);
$plain_usage = $plain_page instanceof Static_Site_Importer_Source_Page ? Static_Site_Importer_Page_Materializer::svg_font_usage_markup( array( 'plain.html' => $plain_page ) ) : '';
$before = count( $GLOBALS['ssi_svg_font_requests'] );
Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' ), false, $plain_usage );
$assert( $before === count( $GLOBALS['ssi_svg_font_requests'] ), 'page-without-matching-inline-svg-text-performs-no-requests' );

$before = count( $GLOBALS['ssi_svg_font_requests'] );
$untrusted = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( 'https://example.test/fonts.css' ), $svg ), false );
$assert( $before === count( $GLOBALS['ssi_svg_font_requests'] ), 'untrusted-stylesheet-is-never-fetched' );
$untrusted = is_array( $untrusted ) ? $untrusted : array( 'diagnostics' => array() );
$assert( 'svg_font_embedding_failed' === ( $untrusted['diagnostics'][0]['type'] ?? '' ), 'untrusted-stylesheet-has-specific-diagnostic' );
$assert( 'untrusted_stylesheet_url' === ( $untrusted['diagnostics'][0]['reason'] ?? '' ), 'untrusted-stylesheet-has-deterministic-diagnostic' );

$mixed = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url, 'https://example.test/fonts.css' ), $svg ), false );
$assert( $before === count( $GLOBALS['ssi_svg_font_requests'] ), 'mixed-trusted-and-untrusted-stylesheets-are-never-fetched' );
$mixed = is_array( $mixed ) ? $mixed : array( 'diagnostics' => array() );
$assert( 'untrusted_stylesheet_url' === ( $mixed['diagnostics'][0]['reason'] ?? '' ), 'mixed-stylesheets-fail-closed' );

$invalid_path = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( 'https://fonts.googleapis.com/css3?family=Example+Family' ), $svg ), false );
$assert( $before === count( $GLOBALS['ssi_svg_font_requests'] ), 'non-exact-google-css-path-is-never-fetched' );
$invalid_path = is_array( $invalid_path ) ? $invalid_path : array( 'diagnostics' => array() );
$assert( 'untrusted_stylesheet_url' === ( $invalid_path['diagnostics'][0]['reason'] ?? '' ), 'non-exact-google-css-path-fails-closed' );

$unsafe_font_url = 'https://fonts.gstatic.com/s/example-family/v1/example.woff2';
$GLOBALS['ssi_svg_font_filters']['pre_http_request'] = array(
	static function ( mixed $preempt, array $args, string $url ) use ( $css_url, $unsafe_font_url ): mixed {
		return $css_url === $url ? array( 'body' => "@font-face{font-family:'Example Family';src:url($unsafe_font_url) format('woff2'),url(https://example.test/font.woff2) format('woff2')}", 'response' => array( 'code' => 200 ) ) : new WP_Error( 'unexpected_request' );
	}
);
$before = count( $GLOBALS['ssi_svg_font_requests'] );
$unsafe_font = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), $svg ), false );
$assert( 1 === count( $GLOBALS['ssi_svg_font_requests'] ) - $before, 'untrusted-font-source-is-never-fetched' );
$unsafe_font = is_array( $unsafe_font ) ? $unsafe_font : array( 'diagnostics' => array(), 'svg_font_faces' => '' );
$assert( 'untrusted_font_url' === ( $unsafe_font['diagnostics'][0]['reason'] ?? '' ), 'untrusted-font-source-has-deterministic-diagnostic' );
$assert( '' === ( $unsafe_font['svg_font_faces'] ?? '' ), 'untrusted-font-source-is-not-embedded' );

$GLOBALS['ssi_svg_font_filters']['pre_http_request'] = array(
	static function ( mixed $preempt, array $args, string $url ) use ( $css_url ): mixed {
		return $css_url === $url ? array( 'body' => str_repeat( 'x', 262145 ), 'response' => array( 'code' => 200 ) ) : new WP_Error( 'unexpected_request' );
	}
);
$bounded = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), $svg ), false );
$bounded = is_array( $bounded ) ? $bounded : array( 'diagnostics' => array(), 'svg_font_faces' => '' );
$assert( '' === ( $bounded['svg_font_faces'] ?? '' ), 'oversized-stylesheet-is-not-embedded' );
$assert( 'stylesheet_response_too_large' === ( $bounded['diagnostics'][0]['reason'] ?? '' ), 'oversized-stylesheet-has-bounded-diagnostic' );

$before = count( $GLOBALS['ssi_svg_font_requests'] );
$plain = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' ), false );
$plain = is_array( $plain ) ? $plain : array( 'svg_font_faces' => '' );
$assert( $before === count( $GLOBALS['ssi_svg_font_requests'] ), 'svg-without-text-font-match-is-unchanged-without-fetch' );
$assert( '' === ( $plain['svg_font_faces'] ?? '' ), 'svg-without-text-font-match-has-no-font-css' );

$duplicate_css_url = 'https://fonts.googleapis.com/css?family=Example+Family';
$GLOBALS['ssi_svg_font_filters']['pre_http_request'] = array(
	static function ( mixed $preempt, array $args, string $url ) use ( $css_url, $duplicate_css_url, $font_url ): mixed {
		if ( $css_url === $url || $duplicate_css_url === $url ) {
			return array( 'body' => "@font-face{font-family:'Example Family';src:url($font_url) format('woff2')}", 'response' => array( 'code' => 200 ) );
		}
		return $font_url === $url ? array( 'body' => 'woff2-payload', 'response' => array( 'code' => 200 ) ) : new WP_Error( 'unexpected_request' );
	}
);
$before = count( $GLOBALS['ssi_svg_font_requests'] );
$cached = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $theme_dir, 'https://example.test/themes/generated', $plan( array( $css_url, $duplicate_css_url ), $svg ), false );
$cached = is_array( $cached ) ? $cached : array( 'svg_font_faces' => '' );
$assert( 3 === count( $GLOBALS['ssi_svg_font_requests'] ) - $before, 'duplicate-font-payload-is-fetched-once-per-materialization' );
$assert( str_contains( (string) ( $cached['svg_font_faces'] ?? '' ), 'data:font/woff2;base64,' ), 'cached-font-payload-is-embedded' );

$large_font_urls = array(
	'https://fonts.gstatic.com/s/example-family/v1/example-a.woff2',
	'https://fonts.gstatic.com/s/example-family/v1/example-b.woff2',
	'https://fonts.gstatic.com/s/example-family/v1/example-c.woff2',
);
$GLOBALS['ssi_svg_font_filters']['pre_http_request'] = array(
	static function ( mixed $preempt, array $args, string $url ) use ( $css_url, $large_font_urls ): mixed {
		if ( $css_url === $url ) {
			$faces = array_map( static fn( string $font_url ): string => "@font-face{font-family:'Example Family';src:url($font_url) format('woff2')}", $large_font_urls );
			return array( 'body' => implode( '', $faces ), 'response' => array( 'code' => 200 ) );
		}
		return in_array( $url, $large_font_urls, true ) ? array( 'body' => str_repeat( 'x', 1572864 ), 'response' => array( 'code' => 200 ) ) : new WP_Error( 'unexpected_request' );
	}
);
$overflow_dir = sys_get_temp_dir() . '/ssi-svg-font-overflow-' . bin2hex( random_bytes( 6 ) );
$overflow = Static_Site_Importer_Theme_Materializer::materialize_website_artifact_files( $overflow_dir, 'https://example.test/themes/generated', $plan( array( $css_url ), $svg ), true );
$overflow = is_array( $overflow ) ? $overflow : array( 'diagnostics' => array(), 'svg_font_faces' => '' );
$overflow_svg = (string) file_get_contents( $overflow_dir . '/assets/materialized/images/label.svg' );
$assert( '' === ( $overflow['svg_font_faces'] ?? '' ), 'aggregate-font-overflow-is-not-embedded' );
$assert( 'font_payload_total_too_large' === ( $overflow['diagnostics'][0]['reason'] ?? '' ), 'aggregate-font-overflow-has-deterministic-diagnostic' );
$assert( $svg === $overflow_svg, 'aggregate-font-overflow-leaves-svg-unchanged' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'PASS smoke-svg-font-embedding.php (' . $assertions . " assertions)\n";
