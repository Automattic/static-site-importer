<?php
/** Run: php tests/smoke-url-batch-import.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
class WP_Error { public function __construct( private string $code, private string $message = '', private mixed $data = null ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } public function get_error_data(): mixed { return $this->data; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_file_name( string $name ): string { return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ), '-' ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-site-collector.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-import-runtime.php';

$responses = array(
	'https://batch.test/sitemap.xml' => array( 'application/xml', '<sitemapindex><sitemap><loc>https://batch.test/one.xml</loc></sitemap><sitemap><loc>https://batch.test/two.xml</loc></sitemap></sitemapindex>' ),
	'https://batch.test/one.xml' => array( 'application/xml', '<urlset><url><loc>https://batch.test/</loc></url><url><loc>https://batch.test/about/</loc></url></urlset>' ),
	'https://batch.test/two.xml' => array( 'application/xml', '<urlset><url><loc>https://batch.test/about/team/</loc></url></urlset>' ),
	'https://batch.test/' => array( 'text/html', '<main><a href="/about/">About</a><link rel="stylesheet" href="/empty.css"></main>' ),
	'https://batch.test/about/' => array( 'text/html', '<main><a href="/about/team/">Team</a></main>' ),
	'https://batch.test/about/team/' => array( 'text/html', '<main>Team</main>' ),
	'https://batch.test/empty.css' => array( 'text/css', '' ),
);
$requests = array();
$transient_failures = array();
$fetcher = static function ( string $url, array $args ) use ( &$requests, &$transient_failures, $responses ) {
	$requests[] = $url;
	if ( 'https://batch.test/about/team/' === $url && empty( $transient_failures[ $url ] ) ) { $transient_failures[ $url ] = true; return new WP_Error( 'transient_timeout', 'retry me' ); }
	if ( ! isset( $responses[ $url ] ) ) { return new WP_Error( 'missing_fixture', $url ); }
	return array( 'body' => $responses[ $url ][1], 'metadata' => array( 'content_type' => $responses[ $url ][0], 'final_url' => $url ) );
};
$work_dir = sys_get_temp_dir() . '/ssi-url-batch-' . bin2hex( random_bytes( 4 ) );
$request = array( 'url' => 'https://batch.test/', 'work_dir' => $work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 2, 'request_delay_ms' => 0, 'max_assets' => 10 ) );
$input = array( 'activate' => true );
$artifacts = array();
$attempt = 0;
$importer = static function ( array $artifact, array $args ) use ( &$artifacts, &$attempt ) {
	$artifacts[] = array( 'artifact' => $artifact, 'args' => $args );
	if ( 1 === $attempt++ ) { return new WP_Error( 'injected_batch_failure', 'stop after the first completed batch' ); }
	return array( 'theme_slug' => 'batch-site', 'import_report_summary' => array( 'status' => 'completed' ) );
};
$first = Static_Site_Importer_URL_Batch_Import::import( $request, $input, $fetcher, $importer );
if ( ! is_wp_error( $first ) || 'injected_batch_failure' !== $first->get_error_code() ) { throw new RuntimeException( 'injected batch failure must retain a resumable run' ); }
$manifest_path = $first->get_error_data()['run_manifest'] ?? '';
$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( 'failed' !== ( $manifest['state'] ?? '' ) || 'completed' !== ( $manifest['batches'][0]['state'] ?? '' ) || 3 !== ( $manifest['total_routes'] ?? 0 ) ) { throw new RuntimeException( 'manifest must checkpoint discovery and completed batches' ); }
$resumed = Static_Site_Importer_URL_Batch_Import::import( $request, $input, $fetcher, static fn( array $artifact, array $args ) => array( 'theme_slug' => 'batch-site', 'artifact' => $artifact, 'args' => $args, 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $resumed ) || true !== ( $resumed['terminal_batch_result']['args']['activate'] ?? false ) || isset( $resumed['pages'] ) || 2 !== ( $resumed['url_batch_run']['completed_batches'] ?? 0 ) || 3 !== ( $resumed['import_report_summary']['completed_routes'] ?? 0 ) ) { throw new RuntimeException( 'aggregate results must retain terminal output explicitly without misrepresenting it as whole-site output' ); }
$first_files = array_column( $artifacts[0]['artifact']['files'], null, 'path' );
if ( ! str_contains( (string) $first_files['website/about/index.html']['content'], 'href="/about/team/"' ) || ! isset( $first_files['website/empty.css'] ) || 2 < count( array_filter( $artifacts[0]['artifact']['files'], static fn( array $file ) => str_ends_with( $file['path'], 'index.html' ) ) ) || empty( $artifacts[1]['args']['preserve_existing_theme_bootstrap'] ) || 2 !== count( array_keys( $requests, 'https://batch.test/about/team/', true ) ) ) { throw new RuntimeException( 'batch must preserve cross-batch routes, empty optional assets, bounded retries, bounds, and prior bootstrap behavior' ); }
$again = Static_Site_Importer_URL_Batch_Import::import( $request, $input, $fetcher, static fn() => new WP_Error( 'should_not_run' ) );
if ( is_wp_error( $again ) || 'batch-site' !== ( $again['theme_slug'] ?? '' ) ) { throw new RuntimeException( 'terminal runs must return their saved SSI result without reimporting' ); }
$mismatch = Static_Site_Importer_URL_Batch_Import::import( $request, array( 'activate' => true, 'slug' => 'other-target' ), $fetcher, static fn() => new WP_Error( 'should_not_run' ) );
if ( ! is_wp_error( $mismatch ) || 'static_site_importer_batch_contract_mismatch' !== $mismatch->get_error_code() ) { throw new RuntimeException( 'a reused work directory must reject a mismatched import target contract' ); }
$activation_mismatch = Static_Site_Importer_URL_Batch_Import::import( $request, array( 'activate' => false ), $fetcher, static fn() => new WP_Error( 'should_not_run' ) );
if ( ! is_wp_error( $activation_mismatch ) || 'static_site_importer_batch_contract_mismatch' !== $activation_mismatch->get_error_code() ) { throw new RuntimeException( 'activation must be part of the resumable import contract' ); }
if ( glob( $work_dir . '/url-site-batch-cache-*.json' ) ) { throw new RuntimeException( 'completed batches must remove their fetched artifact cache' ); }
if ( 'completed' !== ( $resumed['url_batch_run']['status'] ?? '' ) || empty( $resumed['url_batch_run']['terminal_batch_report_path'] ) && ! array_key_exists( 'terminal_batch_report_path', $resumed['url_batch_run'] ) ) { throw new RuntimeException( 'aggregate evidence must label terminal-batch report fields explicitly' ); }
$scale_routes = array();
for ( $i = 0; $i < 1144; $i++ ) { $scale_routes[] = '<url><loc>https://scale.test/page-' . $i . '/</loc></url>'; }
$scale = Static_Site_Importer_URL_Site_Collector::discover_routes( 'https://scale.test/', array( 'request_delay_ms' => 0 ), static fn( string $url, array $args ) => array( 'body' => 'https://scale.test/sitemap.xml' === $url ? '<urlset>' . implode( '', $scale_routes ) . '</urlset>' : '', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ) );
if ( 1144 !== count( $scale ) || 5000 !== ( Static_Site_Importer_URL_Site_Collector::discovery_limits()['max_discovered_routes'] ?? 0 ) ) { throw new RuntimeException( 'discovery must support the acceptance sitemap scale within explicit limits' ); }
$overflow = Static_Site_Importer_URL_Site_Collector::discover_routes( 'https://overflow.test/', array(), static fn( string $url, array $args ) => array( 'body' => '<urlset>' . implode( '', array_map( static fn( int $i ): string => '<url><loc>https://overflow.test/p-' . $i . '/</loc></url>', range( 1, 5001 ) ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ) );
if ( ! is_wp_error( $overflow ) || 'static_site_importer_discovery_incomplete' !== $overflow->get_error_code() || 'routes' !== ( $overflow->get_error_data()['truncated_dimension'] ?? '' ) ) { throw new RuntimeException( 'route discovery must reject queue/route overflow with structured evidence' ); }
$asset_urls = array();
for ( $i = 0; $i < 201; $i++ ) { $asset_urls[] = 'https://assets.test/a-' . $i . '.png'; }
$asset_request = array( 'url' => 'https://assets.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-url-batch-assets-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) );
$asset_result = Static_Site_Importer_URL_Batch_Import::import( $asset_request, array(), static function ( string $url, array $args ) use ( $asset_urls ) { if ( 'https://assets.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://assets.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://assets.test/' === $url ) { return array( 'body' => '<main>' . implode( '', array_map( static fn( string $asset ): string => '<img src="' . $asset . '">', $asset_urls ) ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return array( 'body' => 'x', 'metadata' => array( 'content_type' => 'image/png', 'final_url' => $url ) ); }, static fn( array $artifact, array $args ) => array( 'theme_slug' => 'assets', 'asset_count' => count( $artifact['files'] ?? array() ) - 1, 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $asset_result ) || 2000 !== ( $asset_result['url_batch_run']['per_batch_limits']['max_assets'] ?? 0 ) || 268435456 !== ( $asset_result['url_batch_run']['per_batch_limits']['max_total_bytes'] ?? 0 ) || 201 > ( $asset_result['terminal_batch_result']['asset_count'] ?? 0 ) ) { throw new RuntimeException( 'batch defaults must support more than legacy 200 assets with bounded per-batch limits' ); }
$lower_request = $asset_request; $lower_request['work_dir'] .= '-lower'; $lower_request['provider_args']['max_assets'] = 1;
$lower_result = Static_Site_Importer_URL_Batch_Import::import( $lower_request, array(), static fn( string $url, array $args ) => 'https://assets.test/sitemap.xml' === $url ? array( 'body' => '<urlset><url><loc>https://assets.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ) : new WP_Error( 'fixture_stop', 'lower override reached collection' ), static fn() => array() );
if ( ! is_wp_error( $lower_result ) || 1 !== ( $lower_result->get_error_data()['run']['per_batch_limits']['max_assets'] ?? 0 ) ) { throw new RuntimeException( 'caller lower per-batch asset overrides must remain honored' ); }
echo "URL batch import smoke passed.\n";
