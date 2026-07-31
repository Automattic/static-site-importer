<?php
/**
 * Smoke test: bounded URL collection produces a complete website artifact.
 *
 * Run from the repository root:
 * php tests/smoke-url-site-collector.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ), '-' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-site-collector.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php';

$responses = array(
	'https://example.test/sitemap.xml' => array(
		'content_type' => 'application/xml',
		'body'         => '<?xml version="1.0"?><urlset><url><loc>https://example.test/index.html</loc></url><url><loc>https://example.test/services.html</loc></url><url><loc>https://example.test/team.html</loc></url><url><loc>https://example.test/contact.html</loc></url></urlset>',
	),
	'https://example.test/' => array(
		'content_type' => 'text/html; charset=utf-8',
		'body'         => '<!doctype html><html><head><link rel="canonical" href="/"><link rel="stylesheet" href="/files/main.css?v=1"><script src="/platform-runtime.js"></script></head><body style="background-image:url(/uploads/hero.jpg)"><nav><a href="/services.html">Services</a><a href="/cdn-cgi/l/email-protection#127352703c717d">Email</a></nav><img src="/uploads/logo.png" srcset="/uploads/logo.png 1x, /uploads/logo-2x.png 2x"><main><h1>Home</h1></main><div id="weebly-footer-signup-container-v3"><a href="https://www.weebly.com/signup"><div><img src="https://cdn.example.test/platform-badge.png">Powered by Weebly</div></a></div></body></html>',
	),
	'https://example.test/services.html' => array(
		'content_type' => 'text/html',
		'body'         => '<!doctype html><html><body><a href="team.html">Team</a><main><h1>Services</h1></main></body></html>',
	),
	'https://example.test/team.html' => array(
		'content_type' => 'text/html',
		'body'         => '<!doctype html><html><body><main><h1>Team</h1><img src="https://cdn.example.test/team.webp"></main></body></html>',
	),
	'https://example.test/contact.html' => array(
		'content_type' => 'text/html',
		'body'         => '<!doctype html><html><body><main><h1>Contact</h1></main></body></html>',
	),
	'https://example.test/files/main.css?v=1' => array(
		'content_type' => 'text/css',
		'body'         => '@import "components.css";@font-face{src:url("https://cdn.example.test/font.woff2")}body{background:url(../uploads/pattern.svg)}',
	),
	'https://example.test/files/components.css' => array( 'content_type' => 'text/css', 'body' => '.component{display:block}' ),
	'https://example.test/platform-runtime.js' => array( 'content_type' => 'application/javascript', 'body' => 'window.platformRuntime = true;' ),
	'https://example.test/uploads/hero.jpg' => array( 'content_type' => 'image/jpeg', 'body' => "\xff\xd8hero" ),
	'https://example.test/uploads/logo.png' => array( 'content_type' => 'image/png', 'body' => "\x89PNGlogo" ),
	'https://example.test/uploads/logo-2x.png' => array( 'content_type' => 'image/png', 'body' => "\x89PNGlogo2" ),
	'https://example.test/uploads/pattern.svg' => array( 'content_type' => 'image/svg+xml', 'body' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ),
	'https://cdn.example.test/team.webp' => array( 'content_type' => 'image/webp', 'body' => 'webp-team' ),
	'https://cdn.example.test/font.woff2' => array( 'content_type' => 'font/woff2', 'body' => 'woff2-font' ),
);

$requests = array();
$fetcher  = static function ( string $url, array $args ) use ( &$requests, $responses ) {
	$requests[] = array( 'url' => $url, 'args' => $args );
	if ( ! isset( $responses[ $url ] ) ) {
		return new WP_Error( 'missing_fixture', 'No response fixture for ' . $url );
	}
	$response = $responses[ $url ];
	return array(
		'body'     => $response['body'],
		'metadata' => array( 'content_type' => $response['content_type'], 'source_url' => $url, 'final_url' => $url ),
	);
};

$result = Static_Site_Importer_URL_Site_Collector::collect(
	'https://example.test/',
	array(
		'max_pages'       => 10,
		'max_assets'      => 20,
		'max_bytes'       => PHP_INT_MAX,
		'request_delay_ms' => 0,
	),
	$fetcher
);

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert( ! is_wp_error( $result ), 'collection-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );
$assert( 'public-static-site-collector' === ( $result['provider'] ?? '' ), 'provider-recorded' );
$assert( 'website/index.html' === ( $result['artifact']['entrypoint'] ?? '' ), 'root-entrypoint' );
$assert( 4 === ( $result['source_metadata']['collection']['pages'] ?? 0 ), 'sitemap-index-alias-deduplicated' );
$assert( 9 === ( $result['source_metadata']['collection']['assets'] ?? 0 ), 'html-css-and-script-assets-collected' );
$assert( array() === ( $result['source_metadata']['collection']['failures'] ?? null ), 'no-collection-failures' );

$files = array();
foreach ( $result['artifact']['files'] ?? array() as $file ) {
	$files[ $file['path'] ?? '' ] = $file;
}
$assert( isset( $files['website/services.html'], $files['website/team.html'], $files['website/contact.html'] ), 'all-pages-packaged' );
$assert( isset( $files['website/files/main-a798de8e.css'] ), 'query-addressed-stylesheet-packaged' );
$assert( isset( $files['website/_external/cdn.example.test/font.woff2'] ), 'external-font-packaged' );
$assert( isset( $files['website/_external/cdn.example.test/team.webp'] ), 'external-image-packaged' );
$assert( isset( $files['website/files/components.css'] ), 'quoted-css-import-packaged' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'href="/services.html"' ), 'page-link-preserved-for-route-rewriting' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'src="uploads/logo.png"' ), 'image-link-rewritten' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'url(uploads/hero.jpg)' ), 'inline-background-rewritten' );
$assert( isset( $files['website/uploads/logo.png']['content_base64'] ), 'binary-assets-base64-encoded' );
$assert( ! in_array( 'https://example.test/index.html', array_column( $requests, 'url' ), true ), 'root-index-not-fetched-twice' );
$assert( in_array( 'https://example.test/platform-runtime.js', array_column( $requests, 'url' ), true ), 'remote-scripts-collected-by-default' );
$assert( isset( $files['website/platform-runtime.js']['content'] ), 'script-payload-packaged' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'href="mailto:a@b.co"' ), 'cloudflare-email-link-decoded' );
$assert( ! in_array( 'https://example.test/cdn-cgi/l/email-protection', array_column( $requests, 'url' ), true ), 'cloudflare-email-action-not-crawled-as-page' );
$assert( ! str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'weebly-footer-signup-container-v3' ), 'platform-attribution-removed-before-packaging' );
$assert( ! in_array( 'https://cdn.example.test/platform-badge.png', array_column( $requests, 'url' ), true ), 'excluded-platform-assets-not-collected' );
$source_exclusions = $result['source_metadata']['collection']['source_exclusions'] ?? array();
$assert( 1 === count( $source_exclusions ) && 'platform_attribution_removed' === ( $source_exclusions[0]['reason_code'] ?? '' ) && 64 === strlen( (string) ( $source_exclusions[0]['removed_sha256'] ?? '' ) ), 'platform-attribution-removal-retains-receipt' );
$assert( 10485760 >= max( array_map( static fn ( array $request ): int => (int) ( $request['args']['max_bytes'] ?? 0 ), $requests ) ), 'configured-response-limit-hard-clamped' );

$compiled         = blocks_engine_php_transformer_compile_artifact( $result['artifact'] );
$site_plan        = $compiled['source_reports']['wordpress_site_plan'] ?? array();
$site_diagnostics = $compiled['source_reports']['wordpress_site_plan_diagnostics'] ?? array();
$routes           = array_column( $site_plan['routes'] ?? array(), 'target_path', 'source_path' );
$assert( array() === $site_diagnostics, 'collected-artifact-is-self-contained', json_encode( $site_diagnostics ) ?: '' );
$assert( 4 === count( $routes ), 'collected-artifact-produces-four-routes', json_encode( $routes ) ?: '' );
$assert( '/' === ( $routes['website/index.html'] ?? null ) && '/services' === ( $routes['website/services.html'] ?? null ), 'collected-routes-preserve-source-paths' );

$complete_result = Static_Site_Importer_URL_Site_Collector::collect(
	'https://example.test/',
	array(
		'max_pages'                  => 1,
		'max_assets'                 => 20,
		'max_bytes'                  => PHP_INT_MAX,
		'request_delay_ms'           => 0,
		'require_complete_collection' => true,
	),
	$fetcher
);
$assert( is_wp_error( $complete_result ) && 'static_site_importer_site_collection_incomplete' === $complete_result->get_error_code(), 'complete-collection-rejects-truncation' );
$complete_error_data = is_wp_error( $complete_result ) ? $complete_result->get_error_data() : array();
$assert( array( 'pages' ) === ( $complete_error_data['collection']['truncated'] ?? null ) && 1 === ( $complete_error_data['limits']['max_pages'] ?? null ), 'complete-collection-reports-reached-limits' );

$incomplete_responses = $responses;
unset( $incomplete_responses['https://example.test/uploads/logo.png'] );
$incomplete_fetcher = static function ( string $url, array $args ) use ( $incomplete_responses ) {
	unset( $args );
	if ( ! isset( $incomplete_responses[ $url ] ) ) {
		return new WP_Error( 'missing_fixture', 'No response fixture for ' . $url );
	}
	$response = $incomplete_responses[ $url ];
	return array(
		'body'     => $response['body'],
		'metadata' => array( 'content_type' => $response['content_type'], 'source_url' => $url, 'final_url' => $url ),
	);
};
$incomplete_result = Static_Site_Importer_URL_Site_Collector::collect( 'https://example.test/', array( 'max_pages' => 10, 'max_assets' => 20, 'request_delay_ms' => 0, 'require_complete_collection' => true ), $incomplete_fetcher );
$assert( is_wp_error( $incomplete_result ) && 'missing_fixture' === ( $incomplete_result->get_error_data()['collection']['failures'][0]['code'] ?? null ), 'complete-collection-rejects-fetch-failures' );

$redirect_responses = array(
	'https://redirect.test/sitemap.xml' => array( 'content_type' => 'application/xml', 'body' => '<urlset><url><loc>https://redirect.test/</loc></url><url><loc>https://redirect.test/go</loc></url></urlset>' ),
	'https://redirect.test/' => array( 'content_type' => 'text/html', 'body' => '<base href=/static/><link rel=stylesheet href=style.css><main><h1>Home</h1><a href=/go>Docs</a><img src=/hero.png></main>' ),
	'https://redirect.test/go' => array( 'content_type' => 'text/html', 'final_url' => 'https://redirect.test/docs/', 'body' => '<main><h1>Docs</h1><a href="child.html">Child</a></main>' ),
	'https://redirect.test/docs/child.html' => array( 'content_type' => 'text/html', 'body' => '<main><h1>Child</h1></main>' ),
	'https://redirect.test/static/style.css' => array( 'content_type' => 'text/css', 'final_url' => 'https://redirect.test/assets/css/style.css', 'body' => '@import "theme.css";body{background:url(../background.png)}' ),
	'https://redirect.test/assets/css/theme.css' => array( 'content_type' => 'text/css', 'body' => 'body{color:#000}' ),
	'https://redirect.test/assets/background.png' => array( 'content_type' => 'image/png', 'body' => "\x89PNGredirect" ),
	'https://redirect.test/hero.png' => array( 'content_type' => 'image/png', 'body' => "\x89PNGhero" ),
);
$redirect_requests  = array();
$redirect_fetcher   = static function ( string $url, array $args ) use ( &$redirect_requests, $redirect_responses ) {
	$redirect_requests[] = $url;
	if ( ! isset( $redirect_responses[ $url ] ) ) {
		return new WP_Error( 'missing_redirect_fixture', 'No response fixture for ' . $url );
	}
	$response = $redirect_responses[ $url ];
	return array(
		'body'     => $response['body'],
		'metadata' => array( 'content_type' => $response['content_type'], 'source_url' => $url, 'final_url' => $response['final_url'] ?? $url ),
	);
};
$redirect_result = Static_Site_Importer_URL_Site_Collector::collect( 'https://redirect.test/', array( 'request_delay_ms' => 0 ), $redirect_fetcher );
$redirect_paths  = array_column( $redirect_result['artifact']['files'] ?? array(), 'path' );
$redirect_files  = array_column( $redirect_result['artifact']['files'] ?? array(), null, 'path' );
$assert( in_array( 'https://redirect.test/docs/child.html', $redirect_requests, true ), 'redirected-html-relative-link-uses-final-url' );
$assert( in_array( 'https://redirect.test/static/style.css', $redirect_requests, true ), 'html-base-url-applied' );
$assert( in_array( 'https://redirect.test/hero.png', $redirect_requests, true ), 'unquoted-html-asset-collected' );
$assert( in_array( 'https://redirect.test/assets/css/theme.css', $redirect_requests, true ), 'redirected-css-import-uses-final-url' );
$assert( in_array( 'https://redirect.test/assets/background.png', $redirect_requests, true ), 'redirected-css-url-uses-final-url' );
$assert( in_array( 'website/docs/index.html', $redirect_paths, true ) && in_array( 'website/assets/css/style.css', $redirect_paths, true ), 'redirected-resources-use-final-identities' );
$assert( str_contains( (string) ( $redirect_files['website/index.html']['content'] ?? '' ), 'href="/docs/"' ), 'redirected-page-link-rewritten-to-final-route' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "URL site collector smoke passed (%d assertions).\n", $assertions );
