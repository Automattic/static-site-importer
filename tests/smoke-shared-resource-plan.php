<?php
/** Run: php tests/smoke-shared-resource-plan.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
class WP_Error { public function __construct( private string $code, private string $message = '' ) {} public function get_error_code(): string { return $this->code; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function sanitize_file_name( string $name ): string { return preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ) ?? ''; }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-shared-resource-plan.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-site-collector.php';
$root = sys_get_temp_dir() . '/ssi-shared-plan-' . bin2hex( random_bytes( 4 ) ); mkdir( $root, 0700, true );
$workspace = new Static_Site_Importer_Artifact_Run_Workspace( $root, 'shared-plan' );
$plan = new Static_Site_Importer_Shared_Resource_Plan( $workspace );
$artifact = static fn( string $css, string $page ): array => array( 'files' => array( array( 'path' => 'website/index.html', 'source_url' => 'https://shared-plan.test/', 'mime_type' => 'text/html', 'content' => $page ), array( 'path' => 'website/theme.css', 'source_url' => 'https://shared-plan.test/theme.css', 'mime_type' => 'text/css', 'content' => $css ), array( 'path' => 'website/app.js', 'source_url' => 'https://shared-plan.test/app.js', 'mime_type' => 'application/javascript', 'content' => 'shared-script' ) ) );
$first = $plan->reconcile( $artifact( 'body{color:red}', 'first' ) );
$second = ( new Static_Site_Importer_Shared_Resource_Plan( $workspace ) )->reconcile( $artifact( 'body{color:red}', 'second' ) );
$changed = $plan->reconcile( $artifact( 'body{color:blue}', 'third' ) );
if ( is_wp_error( $first['plan'] ) || $first['changed'] || is_wp_error( $second['plan'] ) || $second['changed'] || $first['digest'] !== $second['digest'] || is_wp_error( $changed['plan'] ) || ! $changed['changed'] || $changed['digest'] === $first['digest'] || ! is_file( $workspace->directory() . '/shared-resource-plan.json' ) ) { throw new RuntimeException( 'shared plans must survive restart, ignore page-only changes, and deterministically invalidate changed shared content' ); }
$expanded = $plan->reconcile( array( 'files' => array( array( 'path' => 'website/extra.css', 'source_url' => 'https://shared-plan.test/extra.css', 'mime_type' => 'text/css', 'content' => 'h1{color:blue}' ) ) ) );
$preserved = $plan->reconcile( $artifact( 'body{color:blue}', 'fourth' ) );
if ( is_wp_error( $expanded['plan'] ) || ! $expanded['changed'] || is_wp_error( $preserved['plan'] ) || $preserved['changed'] || 3 !== count( $preserved['plan']['resources'] ?? array() ) ) { throw new RuntimeException( 'shared plans must retain resources discovered only in an earlier batch' ); }
$referenced = array( 'path' => 'website/fonts/host.woff2', 'source_url' => 'HTTPS://CDN.TEST/fonts/../fonts/host.woff2#ignored', 'mime_type' => 'font/woff2', 'payload_reference' => array( 'schema' => 'blocks-engine/payload-reference/v1', 'id' => 'payloads/host.woff2', 'bytes' => 4, 'sha256' => hash( 'sha256', 'font' ) ) );
$workspace->publish_raw( 'payloads/host.woff2', 'font' );
$with_reference = $plan->reconcile( array( 'files' => array( $referenced ) ) );
$retained = $plan->retained_resources(); $paths = $plan->source_paths();
if ( is_wp_error( $with_reference['plan'] ) || 'website/fonts/host.woff2' !== ( $paths['https://cdn.test/fonts/host.woff2'] ?? '' ) || 1 !== count( array_filter( $retained, static fn( array $resource ): bool => 'website/fonts/host.woff2' === $resource['path'] ) ) ) { throw new RuntimeException( 'shared plans must advertise only canonical, workspace-materializable references' ); }
$workspace->publish_raw( 'payloads/host.woff2', 'corrupt' );
if ( isset( $plan->source_paths()['https://cdn.test/fonts/host.woff2'] ) || ! empty( array_filter( $plan->retained_resources(), static fn( array $resource ): bool => 'website/fonts/host.woff2' === $resource['path'] ) ) ) { throw new RuntimeException( 'corrupt retained payload references must be withheld for collector refetch' ); }
$refetches = 0;
$refetched = Static_Site_Importer_URL_Site_Collector::collect( 'https://refetch.test/', array( 'require_complete_collection' => true, 'request_delay_ms' => 0, '_static_site_importer_known_asset_paths' => $plan->source_paths() ), static function ( string $url ) use ( &$refetches ) {
	if ( 'https://refetch.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); }
	if ( 'https://refetch.test/' === $url ) { return array( 'body' => '<link rel="preload" as="font" href="https://cdn.test/fonts/host.woff2">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); }
	$refetches++;
	return array( 'body' => 'refetched-font', 'metadata' => array( 'content_type' => 'font/woff2', 'final_url' => $url ) );
} );
if ( is_wp_error( $refetched ) || 1 !== $refetches ) { throw new RuntimeException( 'a malformed retained resource must be refetched rather than advertised as known' ); }
$workspace->purge(); @rmdir( $root );
echo "Shared resource plan smoke passed.\n";
