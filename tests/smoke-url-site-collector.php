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

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
		return strip_tags( $string );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-site-collector.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

$responses = array(
	'https://example.test/sitemap.xml' => array(
		'content_type' => 'application/xml',
		'body'         => '<?xml version="1.0"?><urlset><url><loc>https://example.test/index.html</loc></url><url><loc>https://example.test/services.html</loc></url><url><loc>https://example.test/team.html</loc></url><url><loc>https://example.test/contact.html</loc></url></urlset>',
	),
	'https://example.test/' => array(
		'content_type' => 'text/html; charset=utf-8',
		'body'         => '<!doctype html><html><head><link rel="canonical" href="/"><link rel="stylesheet" href="/files/main.css?v=1"><script src="/platform-runtime.js"></script></head><body style="background-image:url(/uploads/hero.jpg)"><nav><a href="/services.html">Services</a><a href="/cdn-cgi/l/email-protection#127352703c717d">Email</a></nav><img src="/uploads/logo.png" srcset="/uploads/logo.png 1x, /uploads/logo-2x.png 2x"><main><h1>Home</h1></main><script>URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)]));</script><div id="weebly-footer-signup-container-v3"><a href="https://www.weebly.com/signup"><div><img src="https://cdn.example.test/platform-badge.png">Powered by Weebly</div></a></div></body></html>',
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
$assert( array( 'max_files' => 70, 'max_file_bytes' => 10485760, 'max_total_bytes' => 104857600 ) === ( $result['artifact']['compiler_limits'] ?? null ), 'collector-declares-bounded-compiler-limits' );
$assert( 4 === ( $result['source_metadata']['collection']['pages'] ?? 0 ), 'sitemap-index-alias-deduplicated' );
$assert( 8 === ( $result['source_metadata']['collection']['assets'] ?? 0 ), 'static-policy-collects-frozen-rendering-assets' );
$assert( array() === ( $result['source_metadata']['collection']['failures'] ?? null ), 'no-collection-failures' );
$snapshot = $result['source_metadata']['snapshot'] ?? array();
$assert( 'static-site-importer/url-snapshot/v1' === ( $snapshot['schema'] ?? '' ) && 64 === strlen( (string) ( $snapshot['sha256'] ?? '' ) ), 'snapshot-hash-recorded' );
$assert( count( $result['artifact']['files'] ?? array() ) === count( $snapshot['files'] ?? array() ) && array() === array_filter( $snapshot['files'] ?? array(), static fn ( array $file ): bool => 64 !== strlen( (string) ( $file['sha256'] ?? '' ) ) ), 'snapshot-records-every-file-hash' );

$files = array();
foreach ( $result['artifact']['files'] ?? array() as $file ) {
	$files[ $file['path'] ?? '' ] = $file;
}
$assert( isset( $files['website/services.html'], $files['website/team.html'], $files['website/contact.html'] ), 'all-pages-packaged' );
$assert( '/' === ( $files['website/index.html']['metadata']['route_path'] ?? null ) && '/services' === ( $files['website/services.html']['metadata']['route_path'] ?? null ), 'html-files-declare-canonical-source-routes' );
$assert( isset( $files['website/files/main-a798de8e.css'] ), 'query-addressed-stylesheet-packaged' );
$assert( isset( $files['website/_external/cdn.example.test/font.woff2'] ), 'external-font-packaged' );
$assert( isset( $files['website/_external/cdn.example.test/team.webp'] ), 'external-image-packaged' );
$assert( isset( $files['website/files/components.css'] ), 'quoted-css-import-packaged' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'href="/services.html"' ), 'page-link-preserved-for-route-rewriting' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'src="uploads/logo.png"' ), 'image-link-rewritten' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'url(uploads/hero.jpg)' ), 'inline-background-rewritten' );
$assert( isset( $files['website/uploads/logo.png']['content_base64'] ), 'binary-assets-base64-encoded' );
$assert( ! in_array( 'https://example.test/index.html', array_column( $requests, 'url' ), true ), 'root-index-not-fetched-twice' );
$assert( array() === array_filter( array_column( $requests, 'url' ), static fn ( string $url ): bool => str_contains( $url, 'new Blob' ) ), 'inline-javascript-url-functions-are-not-css-assets' );
$assert( ! in_array( 'https://example.test/platform-runtime.js', array_column( $requests, 'url' ), true ), 'runtime-scripts-are-not-fetched-by-default' );
$assert( ! isset( $files['website/platform-runtime.js'] ) && ! str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'platform-runtime.js' ), 'runtime-script-markup-and-payload-are-omitted' );
$assert( str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'href="mailto:a@b.co"' ), 'cloudflare-email-link-decoded' );
$assert( ! in_array( 'https://example.test/cdn-cgi/l/email-protection', array_column( $requests, 'url' ), true ), 'cloudflare-email-action-not-crawled-as-page' );
$assert( ! str_contains( (string) ( $files['website/index.html']['content'] ?? '' ), 'weebly-footer-signup-container-v3' ), 'platform-attribution-removed-before-packaging' );
$assert( ! in_array( 'https://cdn.example.test/platform-badge.png', array_column( $requests, 'url' ), true ), 'excluded-platform-assets-not-collected' );
$source_exclusions = $result['source_metadata']['collection']['source_exclusions'] ?? array();
$assert( 1 === count( $source_exclusions ) && 'platform_attribution_removed' === ( $source_exclusions[0]['reason_code'] ?? '' ) && 64 === strlen( (string) ( $source_exclusions[0]['removed_sha256'] ?? '' ) ), 'platform-attribution-removal-retains-receipt' );
$assert( 10485760 >= max( array_map( static fn ( array $request ): int => (int) ( $request['args']['max_bytes'] ?? 0 ), $requests ) ), 'configured-response-limit-hard-clamped' );
$artifact_paths = array_column( $result['artifact']['files'] ?? array(), 'path' );
$sorted_paths   = $artifact_paths;
sort( $sorted_paths, SORT_STRING );
$assert( $sorted_paths === $artifact_paths, 'artifact-file-order-is-canonical' );

$shuffled_responses = $responses;
$shuffled_responses['https://example.test/sitemap.xml']['body'] = '<?xml version="1.0"?><urlset><url><loc>https://example.test/team.html</loc></url><url><loc>https://example.test/contact.html</loc></url><url><loc>https://example.test/services.html</loc></url><url><loc>https://example.test/index.html</loc></url></urlset>';
$shuffled = Static_Site_Importer_URL_Site_Collector::collect(
	'https://example.test/',
	array( 'max_pages' => 10, 'max_assets' => 20, 'max_bytes' => PHP_INT_MAX, 'request_delay_ms' => 0 ),
	static function ( string $url, array $args ) use ( $shuffled_responses ) {
		unset( $args );
		if ( ! isset( $shuffled_responses[ $url ] ) ) {
			return new WP_Error( 'missing_fixture', $url );
		}
		$response = $shuffled_responses[ $url ];
		return array( 'body' => $response['body'], 'metadata' => array( 'content_type' => $response['content_type'], 'final_url' => $url ) );
	}
);
$assert( ! is_wp_error( $shuffled ) && ( $snapshot['sha256'] ?? '' ) === ( $shuffled['source_metadata']['snapshot']['sha256'] ?? null ), 'snapshot-hash-independent-of-discovery-order' );

$finalization_cursor = null;
$retained_bodies     = array();
$retained_loads      = array();
$finalization_steps  = array();
$finalization_fetcher = static function ( string $url, array $args ): array {
	unset( $args );
	$fixtures = array(
		'https://finalize.test/'          => array( 'text/html', '<link rel="stylesheet" href="/style.css"><img src="/image.png">' ),
		'https://finalize.test/style.css' => array( 'text/css', 'body{background:url(/image.png)}' ),
		'https://finalize.test/image.png' => array( 'image/png', 'image-bytes' ),
	);
	return array( 'body' => $fixtures[ $url ][1], 'metadata' => array( 'content_type' => $fixtures[ $url ][0], 'final_url' => $url ) );
};
$finalization_args = array(
	'max_pages'                                    => 1,
	'max_assets'                                   => 2,
	'_route_set'                                   => array( 'https://finalize.test/' ),
	'_static_site_importer_collection_contract'    => 'finalization-test',
	'_static_site_importer_collection_cursor_load' => static function () use ( &$finalization_cursor ) {
		return $finalization_cursor;
	},
	'_static_site_importer_collection_resource_load' => static function ( array $retained ) use ( &$retained_bodies, &$retained_loads ) {
		$ref                    = (string) ( $retained['body_ref'] ?? '' );
		$retained_loads[ $ref ] = ( $retained_loads[ $ref ] ?? 0 ) + 1;
		$body                   = $retained_bodies[ $ref ] ?? null;
		return is_string( $body ) && hash_equals( (string) ( $retained['sha256'] ?? '' ), hash( 'sha256', $body ) ) ? $body : null;
	},
	'_static_site_importer_collection_cursor_save' => static function ( array $cursor ) use ( &$finalization_cursor, &$retained_bodies ) {
		foreach ( $cursor['resources'] ?? array() as $url => $resource ) {
			if ( isset( $resource['body'] ) && is_string( $resource['body'] ) ) {
				$hash                     = hash( 'sha256', $resource['body'] );
				$ref                      = 'source/' . $hash;
				$retained_bodies[ $ref ]  = $resource['body'];
				unset( $resource['body'] );
				$resource['body_ref']      = $ref;
				$resource['sha256']        = $hash;
				$cursor['resources'][ $url ] = $resource;
			}
		}
		foreach ( $cursor['finalization']['files'] ?? array() as $index => $file ) {
			if ( isset( $file['body'] ) && is_string( $file['body'] ) ) {
				$hash                                    = hash( 'sha256', $file['body'] );
				$ref                                     = 'finalized/' . $hash;
				$retained_bodies[ $ref ]                 = $file['body'];
				unset( $file['body'] );
				$file['body_ref']                         = $ref;
				$file['sha256']                           = $hash;
				$cursor['finalization']['files'][ $index ] = $file;
			}
		}
		$finalization_cursor = $cursor;
		return true;
	},
);
$resumed_finalization = null;
for ( $attempt = 0; $attempt < 5; ++$attempt ) {
	$yield_checks = 0;
	$attempt_args = $finalization_args;
	$attempt_args['_static_site_importer_collection_should_yield'] = static function () use ( &$yield_checks ): bool {
		return 1 < ++$yield_checks;
	};
	$resumed_finalization = Static_Site_Importer_URL_Site_Collector::collect( 'https://finalize.test/', $attempt_args, $finalization_fetcher );
	$finalization_steps[] = (int) ( $finalization_cursor['finalization']['next_resource'] ?? 0 );
	if ( ! is_wp_error( $resumed_finalization ) ) {
		break;
	}
}
$clean_finalization = Static_Site_Importer_URL_Site_Collector::collect( 'https://finalize.test/', array( 'max_pages' => 1, 'max_assets' => 2, '_route_set' => array( 'https://finalize.test/' ) ), $finalization_fetcher );
$source_loads = array_filter( $retained_loads, static fn ( int $count, string $ref ): bool => str_starts_with( $ref, 'source/' ), ARRAY_FILTER_USE_BOTH );
$finalized_refs_verify = array_filter(
	$finalization_cursor['finalization']['files'] ?? array(),
	static fn ( array $file ): bool => ! isset( $retained_bodies[ $file['body_ref'] ?? '' ] ) || ! hash_equals( (string) ( $file['sha256'] ?? '' ), hash( 'sha256', $retained_bodies[ $file['body_ref'] ] ) )
);
$assert( array( 1, 2, 3, 3 ) === $finalization_steps, 'finalization-cursor-advances-one-resource-per-invocation' );
$assert( 'static-site-importer/url-collection-cursor/v2' === ( $finalization_cursor['schema'] ?? '' ) && array() === $finalized_refs_verify, 'finalization-cursor-retains-hash-verified-payloads' );
$assert( 2 === count( $source_loads ) && array() === array_filter( $source_loads, static fn ( int $count ): bool => 1 !== $count ), 'resumed-finalization-does-not-reload-completed-source-resources' );
$assert( ! is_wp_error( $resumed_finalization ) && ! is_wp_error( $clean_finalization ) && wp_json_encode( $clean_finalization['artifact'] ) === wp_json_encode( $resumed_finalization['artifact'] ), 'resumed-finalization-preserves-clean-artifact-bytes' );

$schedule_calls = array();
$schedule_delays = array();
$scheduled = Static_Site_Importer_URL_Site_Collector::collect(
	'https://schedule.test/',
	array( 'max_pages' => 3, 'max_assets' => 0, '_route_set' => array( 'https://schedule.test/', 'https://schedule.test/a/', 'https://schedule.test/b/' ), '_static_site_importer_delay_callback' => static function ( int $milliseconds ) use ( &$schedule_delays ): void { $schedule_delays[] = $milliseconds; } ),
	static function ( string $url, array $args ) use ( &$schedule_calls ): array {
		unset( $args );
		$schedule_calls[] = $url;
		return array( 'body' => '<main>' . $url . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
	}
);
$assert( ! is_wp_error( $scheduled ) && 3 === count( $schedule_calls ) && array() === $schedule_delays, 'successful-uncached-fetches-have-no-default-pacing' );
$assert( array( 'same_origin_concurrency' => 2, 'cross_origin_concurrency' => 4, 'retry_delay_ms' => 0 ) === ( $scheduled['source_metadata']['collection']['fetch_scheduling'] ?? null ), 'concurrent-transport-scheduling-limits-are-explicit' );

$concurrent_routes = array( 'http://1.1.1.1/', 'http://1.1.1.1/slow/', 'http://8.8.8.8/fast/', 'http://8.8.8.8/middle/' );
$concurrent_active = array();
$concurrent_origins = array();
$concurrent_max_active = 0;
$concurrent_max_origin = 0;
$concurrent_starts = array();
$concurrent_transport = array(
	'start' => static function ( array $target, array $options ) use ( &$concurrent_active, &$concurrent_origins, &$concurrent_max_active, &$concurrent_max_origin, &$concurrent_starts ) {
		unset( $options );
		$origin = $target['scheme'] . '://' . $target['host'] . ':' . $target['port'];
		$delay = array( '/' => 80000, '/slow/' => 60000, '/fast/' => 20000, '/middle/' => 40000 )[ $target['path'] ];
		$concurrent_active[] = $target['path'];
		$concurrent_origins[ $origin ] = ( $concurrent_origins[ $origin ] ?? 0 ) + 1;
		$concurrent_max_active = max( $concurrent_max_active, count( $concurrent_active ) );
		$concurrent_max_origin = max( $concurrent_max_origin, $concurrent_origins[ $origin ] );
		$concurrent_starts[] = $target['path'];
		return (object) array( 'target' => $target, 'origin' => $origin, 'due' => microtime( true ) + ( $delay / 1000000 ) );
	},
	'poll' => static function ( object $handle ) use ( &$concurrent_active, &$concurrent_origins ) {
		if ( microtime( true ) < $handle->due ) {
			return null;
		}
		$concurrent_active = array_values( array_filter( $concurrent_active, static fn ( string $path ): bool => $path !== $handle->target['path'] ) );
		--$concurrent_origins[ $handle->origin ];
		return array( 'status_code' => 200, 'headers' => array( 'content-type' => array( 'text/html' ) ), 'body' => '<main>' . $handle->target['path'] . '</main>' );
	},
);
$concurrent_started = microtime( true );
$concurrent_result = Static_Site_Importer_URL_Site_Collector::collect(
	$concurrent_routes[0],
	array( 'max_pages' => 4, 'max_assets' => 0, 'fetch_attempts' => 1, '_route_set' => $concurrent_routes, '_static_site_importer_fetch_many_transport' => $concurrent_transport )
);
$concurrent_elapsed = microtime( true ) - $concurrent_started;
$serial_started = microtime( true );
$serial_result = Static_Site_Importer_URL_Site_Collector::collect(
	$concurrent_routes[0],
	array( 'max_pages' => 4, 'max_assets' => 0, 'fetch_attempts' => 1, '_route_set' => $concurrent_routes ),
	static function ( string $url, array $args ): array {
		unset( $args );
		usleep( array( '/' => 80000, '/slow/' => 60000, '/fast/' => 20000, '/middle/' => 40000 )[ (string) wp_parse_url( $url, PHP_URL_PATH ) ] );
		return array( 'body' => '<main>' . wp_parse_url( $url, PHP_URL_PATH ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
	}
);
$serial_elapsed = microtime( true ) - $serial_started;
$assert( ! is_wp_error( $concurrent_result ) && 4 === $concurrent_max_active && 2 === $concurrent_max_origin && array( '/', '/slow/', '/fast/', '/middle/' ) === $concurrent_starts, 'collector-fetch-many-bounds-global-and-per-origin-admission' );
$assert( ! is_wp_error( $concurrent_result ) && ! is_wp_error( $serial_result ) && ( $concurrent_result['source_metadata']['snapshot']['sha256'] ?? '' ) === ( $serial_result['source_metadata']['snapshot']['sha256'] ?? '' ) && ( $concurrent_result['source_metadata']['collection']['diagnostics'] ?? null ) === ( $serial_result['source_metadata']['collection']['diagnostics'] ?? null ), 'out-of-order-completions-preserve-serial-artifact-hash-and-diagnostics' );
$assert( $concurrent_elapsed < $serial_elapsed * 0.7, 'bounded-concurrent-collection-materially-reduces-wall-clock-delay', sprintf( 'concurrent=%.3fs serial=%.3fs', $concurrent_elapsed, $serial_elapsed ) );

$retry_now = 0.0;
$retry_calls = 0;
$retry_delays = array();
$retried = Static_Site_Importer_URL_Site_Collector::collect(
	'https://retry.test/',
	array( 'max_pages' => 1, 'max_assets' => 0, '_route_set' => array( 'https://retry.test/' ), 'fetch_attempts' => 2, 'request_delay_ms' => 125, '_static_site_importer_scheduler_clock' => static function () use ( &$retry_now ): float { return $retry_now; }, '_static_site_importer_delay_callback' => static function ( int $milliseconds ) use ( &$retry_now, &$retry_delays ): void { $retry_delays[] = $milliseconds; $retry_now += $milliseconds / 1000; } ),
	static function ( string $url, array $args ) use ( &$retry_calls ) {
		unset( $url, $args );
		$retry_calls++;
		return 1 === $retry_calls ? new WP_Error( 'temporary_failure', 'Retry me.' ) : array( 'body' => '<main>Recovered</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => 'https://retry.test/' ) );
	}
);
$assert( ! is_wp_error( $retried ) && 2 === $retry_calls && array( 125 ) === $retry_delays, 'retry-pacing-is-per-origin-and-deterministic' );

$cache_calls = 0;
$cache_delays = array();
$cached = Static_Site_Importer_URL_Site_Collector::collect(
	'https://cache-schedule.test/',
	array( 'max_pages' => 1, 'max_assets' => 0, '_route_set' => array( 'https://cache-schedule.test/' ), 'request_delay_ms' => 125, '_static_site_importer_delay_callback' => static function ( int $milliseconds ) use ( &$cache_delays ): void { $cache_delays[] = $milliseconds; } ),
	static function ( string $url, array $args ) use ( &$cache_calls ): array {
		unset( $args );
		$cache_calls++;
		return array( 'body' => '<main>Cached</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url, '_static_site_importer_cache_hit' => true ) );
	}
);
$assert( ! is_wp_error( $cached ) && 1 === $cache_calls && array() === $cache_delays, 'cache-hits-never-consume-pacing-budget' );

$compiled         = blocks_engine_php_transformer_compile_artifact( $result['artifact'] );
$site_plan        = $compiled['source_reports']['wordpress_site_plan'] ?? array();
$site_diagnostics = $compiled['source_reports']['wordpress_site_plan_diagnostics'] ?? array();
$routes           = array_column( $site_plan['routes'] ?? array(), 'target_path', 'source_path' );
$assert( array() === $site_diagnostics, 'collected-artifact-is-self-contained', json_encode( $site_diagnostics ) ?: '' );
$assert( 4 === count( $routes ), 'collected-artifact-produces-four-routes', json_encode( $routes ) ?: '' );
$assert( '/' === ( $routes['website/index.html'] ?? null ) && '/services' === ( $routes['website/services.html'] ?? null ), 'collected-routes-preserve-source-paths' );

$policy_responses = array(
	'https://policy.test/sitemap.xml' => array( 'content_type' => 'application/xml', 'body' => '<urlset><url><loc>https://policy.test/</loc></url></urlset>' ),
	'https://policy.test/' => array( 'content_type' => 'text/html', 'body' => '<!doctype html><html><head><link rel="stylesheet" href="/site.css"><script src="/bootstrap.js"></script><script type="module" src="/chunks/app.js"></script><script>window.hydrate=true;</script><script type="application/ld+json">{"@type":"Organization","name":"Frozen"}</script></head><body><main><h1>Frozen page</h1><p>Server-rendered content.</p><img src="/hero.png" alt="Hero"></main></body></html>' ),
	'https://policy.test/site.css' => array( 'content_type' => 'text/css', 'body' => '.hero{color:#123}' ),
	'https://policy.test/hero.png' => array( 'content_type' => 'image/png', 'body' => str_repeat( 'i', 80 ) ),
	'https://policy.test/bootstrap.js' => array( 'content_type' => 'application/javascript', 'body' => str_repeat( 'b', 4000 ) ),
	'https://policy.test/chunks/app.js' => array( 'content_type' => 'application/javascript', 'body' => str_repeat( 'c', 6000 ) ),
);
$policy_requests = array();
$policy_fetcher = static function ( string $url, array $args ) use ( $policy_responses, &$policy_requests ) {
	unset( $args );
	$policy_requests[] = $url;
	if ( ! isset( $policy_responses[ $url ] ) ) {
		return new WP_Error( 'missing_policy_fixture', $url );
	}
	$response = $policy_responses[ $url ];
	return array( 'body' => $response['body'], 'metadata' => array( 'content_type' => $response['content_type'], 'final_url' => $url ) );
};
$static_policy = Static_Site_Importer_URL_Site_Collector::collect( 'https://policy.test/', array( 'request_delay_ms' => 0, 'require_complete_collection' => true ), $policy_fetcher );
$full_policy = Static_Site_Importer_URL_Site_Collector::collect( 'https://policy.test/', array( 'request_delay_ms' => 0, 'require_complete_collection' => true, 'script_policy' => 'isolated_preview', 'client_script_isolated' => true, 'client_script_provenance' => array( 'ref' => 'fixture:isolated-preview' ) ), $policy_fetcher );
$static_files = is_wp_error( $static_policy ) ? array() : array_column( $static_policy['artifact']['files'], null, 'path' );
$static_html = (string) ( $static_files['website/index.html']['content'] ?? '' );
$static_compiled = is_wp_error( $static_policy ) ? array() : blocks_engine_php_transformer_compile_artifact( $static_policy['artifact'] );
$static_plan = $static_compiled['source_reports']['wordpress_site_plan'] ?? array();
$exclusions = $static_policy['source_metadata']['collection']['script_policy']['excluded_scripts'] ?? array();
$reason_codes = array_column( $exclusions, 'reason_code' );
sort( $reason_codes, SORT_STRING );
$assert( ! is_wp_error( $static_policy ) && ! is_wp_error( $full_policy ), 'script-policy-fixture-collects-completely' );
$assert( 2 === ( $static_policy['source_metadata']['collection']['assets'] ?? 0 ) && 4 === ( $full_policy['source_metadata']['collection']['assets'] ?? 0 ) && 2 === count( array_filter( $policy_requests, static fn ( string $url ): bool => in_array( $url, array( 'https://policy.test/bootstrap.js', 'https://policy.test/chunks/app.js' ), true ) ) ) && 10000 < ( $full_policy['source_metadata']['collection']['bytes'] ?? 0 ) - ( $static_policy['source_metadata']['collection']['bytes'] ?? 0 ), 'static-policy-reduces-script-requests-and-bytes' );
$assert( str_contains( $static_html, '<h1>Frozen page</h1>' ) && str_contains( $static_html, 'site.css' ) && str_contains( $static_html, 'hero.png' ) && ! str_contains( $static_html, 'bootstrap.js' ) && ! str_contains( $static_html, 'window.hydrate' ) && ! str_contains( $static_html, 'application/ld+json' ), 'static-policy-preserves-frozen-content-and-visual-contract' );
$assert( 'proven' === ( $static_plan['reference_semantics']['dynamic_client_assets']['status'] ?? null ) && array() === ( $static_compiled['source_reports']['wordpress_site_plan_diagnostics'] ?? array() ), 'static-policy-artifact-is-accepted-without-runtime-regression', json_encode( array( $static_plan['reference_semantics'] ?? array(), $static_compiled['source_reports']['wordpress_site_plan_diagnostics'] ?? array() ) ) ?: '' );
$assert( 'inert' === ( $static_policy['source_metadata']['collection']['script_policy']['name'] ?? null ) && 4 === count( $exclusions ) && array( 'data_script_quarantined_by_inert_policy', 'script_dropped_by_inert_policy', 'script_dropped_by_inert_policy', 'script_dropped_by_inert_policy' ) === $reason_codes && 64 === strlen( (string) ( $exclusions[0]['sha256'] ?? '' ) ), 'inert-policy-records-deterministic-reason-coded-provenance' );
$assert( in_array( 'https://policy.test/bootstrap.js', $policy_requests, true ) && in_array( 'https://policy.test/chunks/app.js', $policy_requests, true ), 'explicit-isolated-preview-policy-preserves-script-collection-behavior' );

$encoded_route = Static_Site_Importer_URL_Site_Collector::collect(
	'https://example.test/news/category/Americana%2FCountry+Artist',
	array( 'max_pages' => 1, 'max_assets' => 0, 'max_bytes' => PHP_INT_MAX, 'request_delay_ms' => 0, '_route_set' => array( 'https://example.test/news/category/Americana%2FCountry+Artist' ) ),
	static fn ( string $url, array $args ): array => array( 'body' => '<main>Category</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) )
);
$encoded_file = $encoded_route['artifact']['files'][0] ?? array();
$assert( '/news/category/americana-country-artist' === ( $encoded_file['metadata']['route_path'] ?? null ), 'encoded-source-route-is-canonicalized' );

$colliding_urls = array( 'https://example.test/news/tag/inc-+richlyn+marketing', 'https://example.test/news/tag/inc-richlyn+marketing' );
$colliding_routes = Static_Site_Importer_URL_Site_Collector::collect(
	$colliding_urls[0],
	array( 'max_pages' => 2, 'max_assets' => 0, 'max_bytes' => PHP_INT_MAX, 'request_delay_ms' => 0, '_route_set' => $colliding_urls ),
	static fn ( string $url, array $args ): array => array( 'body' => '<main><h1>Tag</h1><p>Server-rendered tag archive.</p></main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) )
);
$colliding_files = is_wp_error( $colliding_routes ) ? array() : array_filter( $colliding_routes['artifact']['files'], static fn ( array $file ): bool => 'text/html' === ( $file['mime_type'] ?? '' ) );
$colliding_paths = array_column( $colliding_files, 'metadata' );
$colliding_paths = array_column( $colliding_paths, 'route_path' );
$colliding_compiled = is_wp_error( $colliding_routes ) ? array() : blocks_engine_php_transformer_compile_artifact( $colliding_routes['artifact'] );
$colliding_diagnostics = $colliding_compiled['source_reports']['wordpress_site_plan_diagnostics'] ?? array();
$route_collision_diagnostics = array_filter( $colliding_diagnostics, static fn ( array $diagnostic ): bool => str_contains( (string) ( $diagnostic['message'] ?? '' ), 'colliding page routes' ) );
$assert( 2 === count( array_unique( $colliding_paths ) ) && array() === $route_collision_diagnostics, 'canonical-route-collisions-receive-stable-distinct-routes', json_encode( array( 'routes' => $colliding_paths, 'diagnostics' => $colliding_diagnostics ) ) ?: '' );

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

$source_broken_fetcher = static function ( string $url, array $args ) {
	unset( $args );
	if ( 'https://source-broken.test/' === $url ) {
		return array( 'body' => '<link rel="stylesheet" href="/style.css"><main>Ready</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
	}
	if ( 'https://source-broken.test/style.css' === $url ) {
		return array( 'body' => '@font-face{src:url(/missing.woff2)}', 'metadata' => array( 'content_type' => 'text/css', 'final_url' => $url ) );
	}
	return new WP_Error( 'static_site_importer_url_http_status', 'The URL returned HTTP status 404.', array( 'status' => 404 ) );
};
$source_broken_result = Static_Site_Importer_URL_Site_Collector::collect(
	'https://source-broken.test/',
	array( '_route_set' => array( 'https://source-broken.test/' ), 'asset_failure_policy' => 'preserve_failed_external_assets', 'hydration_mode' => 'page_ready', 'max_pages' => 2, 'max_assets' => 10, 'request_delay_ms' => 0, 'require_complete_collection' => true ),
	$source_broken_fetcher
);
$source_broken_collection = is_wp_error( $source_broken_result ) ? array() : $source_broken_result['source_metadata']['collection'];
$source_broken_diagnostics = array_values( array_filter( $source_broken_collection['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'source_broken_asset_reference' === ( $diagnostic['code'] ?? '' ) ) );
$source_broken_detail = wp_json_encode( is_wp_error( $source_broken_result ) ? array( 'code' => $source_broken_result->get_error_code(), 'data' => $source_broken_result->get_error_data() ) : $source_broken_collection );
$assert( ! is_wp_error( $source_broken_result ) && 1 === ( $source_broken_collection['external_asset_retained']['count'] ?? 0 ), 'source-broken-critical-assets-are-retained', $source_broken_detail );
$assert( 'source_http_404' === ( $source_broken_collection['external_asset_retained']['samples'][0]['reason'] ?? '' ) && true === ( $source_broken_diagnostics[0]['critical'] ?? false ), 'source-broken-critical-assets-record-provenance', $source_broken_detail );

$critical_timeout_result = Static_Site_Importer_URL_Site_Collector::collect(
	'https://source-broken.test/',
	array( '_route_set' => array( 'https://source-broken.test/' ), 'asset_failure_policy' => 'preserve_failed_external_assets', 'hydration_mode' => 'page_ready', 'max_pages' => 2, 'max_assets' => 10, 'request_delay_ms' => 0, 'require_complete_collection' => true ),
	static function ( string $url, array $args ) use ( $source_broken_fetcher ) {
		return 'https://source-broken.test/missing.woff2' === $url ? new WP_Error( 'asset_timeout', 'Timed out.' ) : $source_broken_fetcher( $url, $args );
	}
);
$assert( is_wp_error( $critical_timeout_result ) && 'asset_timeout' === ( $critical_timeout_result->get_error_data()['collection']['failures'][0]['code'] ?? '' ), 'critical-asset-timeouts-remain-terminal' );

$uncollected_page_result = Static_Site_Importer_URL_Site_Collector::collect(
	'https://uncollected.test/',
	array( '_route_set' => array( 'https://uncollected.test/', 'https://uncollected.test/about.html' ), 'max_pages' => 3, 'max_assets' => 0, 'request_delay_ms' => 0 ),
	static function ( string $url, array $args ) {
		unset( $args );
		$body = 'https://uncollected.test/' === $url ? '<a href="about.html">About</a><a href="missing.html">Missing</a>' : '<main>About</main>';
		return array( 'body' => $body, 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
	}
);
$uncollected_files = is_wp_error( $uncollected_page_result ) ? array() : array_column( $uncollected_page_result['artifact']['files'], null, 'path' );
$uncollected_html = (string) ( $uncollected_files['website/index.html']['content'] ?? '' );
$uncollected_metadata = is_wp_error( $uncollected_page_result ) ? array() : $uncollected_page_result['source_metadata']['collection']['external_page_retained'];
$assert( str_contains( $uncollected_html, 'href="about.html"' ), 'collected-page-links-remain-route-rewritable' );
$assert( str_contains( $uncollected_html, 'href="https://uncollected.test/missing.html"' ), 'uncollected-page-links-remain-external' );
$assert( 1 === ( $uncollected_metadata['count'] ?? 0 ) && 'uncollected_page' === ( $uncollected_metadata['samples'][0]['reason'] ?? '' ), 'uncollected-page-links-record-provenance' );

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
