<?php
/** Run: php tests/smoke-url-batch-import.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
class WP_Error { public function __construct( private string $code, private string $message = '', private mixed $data = null ) {} public function get_error_code(): string { return $this->code; } public function get_error_message(): string { return $this->message; } public function get_error_data(): mixed { return $this->data; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_file_name( string $name ): string { return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ), '-' ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_delete_file( string $path ): bool { return false; }
function wp_generate_uuid4(): string { return bin2hex( random_bytes( 16 ) ); }
function get_current_user_id(): int { return (int) ( $GLOBALS['ssi_test_user_id'] ?? 1 ); }
function wp_upload_dir(): array { return array( 'basedir' => sys_get_temp_dir() . '/ssi-url-runtime' ); }
$GLOBALS['ssi_test_filters'] = array();
function add_filter( string $hook, callable $callback ): void { $GLOBALS['ssi_test_filters'][ $hook ][] = $callback; }
function add_action( string $hook, callable $callback ): void { $GLOBALS['ssi_test_filters'][ $hook ][] = $callback; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { foreach ( $GLOBALS['ssi_test_filters'][ $hook ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
function did_action( string $hook ): int { return 0; }
function doing_action( string $hook ): bool { return false; }
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-fetcher.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-site-collector.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-url-import-runtime.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-final-hydration-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-default-final-hydration-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-callable-final-hydration-adapter.php';
class Static_Site_Importer_Theme_Generator {
	public static function import_website_artifact( array $artifact, array $args ): array {
		return array( 'theme_slug' => 'default', 'import_report_summary' => array( 'status' => 'completed' ) );
	}
}
require_once dirname( __DIR__ ) . '/includes/abilities.php';
$candidate_transformer = getenv( 'SSI_BLOCKS_ENGINE_PHP_TRANSFORMER' );
if ( is_string( $candidate_transformer ) && is_readable( rtrim( $candidate_transformer, '/\\' ) . '/vendor/autoload.php' ) ) { $candidate_transformer = rtrim( $candidate_transformer, '/\\' ); spl_autoload_register( static function ( string $class ) use ( $candidate_transformer ): void { $prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\'; if ( str_starts_with( $class, $prefix ) ) { $path = $candidate_transformer . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php'; if ( is_readable( $path ) ) { require_once $path; } } }, true, true ); require_once $candidate_transformer . '/vendor/autoload.php'; }

function ssi_test_import( array $request, array $input, ?callable $fetcher = null, ?callable $importer = null, ?Static_Site_Importer_Final_Hydration_Adapter $adapter = null ) {
	if ( null === $adapter && null !== $importer ) {
		$adapter = new SSI_Test_Final_Hydration_Adapter( $importer );
	}
	return Static_Site_Importer_URL_Batch_Import::import( $request, $input, $fetcher, $importer, $adapter );
}

final class SSI_Test_Final_Hydration_Adapter implements Static_Site_Importer_Final_Hydration_Adapter {
	private $callback;
	public function __construct( callable $callback ) { $this->callback = $callback; }
	public function id(): string { return 'tests/url-batch'; }
	public function contract_version(): int { return 1; }
	public function implementation_version(): string { return '1'; }
	public function capabilities(): array { return array( 'verify_result', 'reconcile_verified_result' ); }
	public function apply( array $artifact, array $args ) { return call_user_func( $this->callback, $artifact, $args ); }
	public function reconcile( array $receipt, array $artifact, array $args ) { return $receipt['effect']['result'] ?? new WP_Error( 'reconcile_unavailable', 'No stored result.' ); }
	public function verify( array $result, array $artifact, array $args ): bool { return true; }
}

$legacy_path = tempnam( sys_get_temp_dir(), 'ssi-legacy-' ); $delete_legacy_file = new ReflectionMethod( Static_Site_Importer_URL_Batch_Import::class, 'delete_legacy_file' ); if ( ! $delete_legacy_file->invoke( null, $legacy_path ) || is_file( $legacy_path ) ) { throw new RuntimeException( 'legacy cache cleanup must unlink its verified exact path without wp_delete_file filters' ); }
$staged_workspace_root = sys_get_temp_dir() . '/ssi-staged-pages-' . bin2hex( random_bytes( 4 ) ); wp_mkdir_p( $staged_workspace_root );
$staged_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $staged_workspace_root, 'page-checkpoint' );
$staged_artifact = array( 'entrypoint' => 'one.html', 'files' => array( array( 'path' => 'one.html', 'mime_type' => 'text/html', 'content' => '<main>One</main>' ), array( 'path' => 'two.html', 'mime_type' => 'text/html', 'content' => '<main>Two</main>' ), array( 'path' => 'three.html', 'mime_type' => 'text/html', 'content' => '<main>Three</main>' ) ) );
$prepare_staged = new ReflectionMethod( Static_Site_Importer_URL_Batch_Import::class, 'prepare_staged_plans' ); $yield_checks = 0;
$staged_interrupted = $prepare_staged->invoke( null, $staged_workspace, $staged_artifact, hash( 'sha256', 'page-checkpoint' ), null, 'staged-pages.json', static function () use ( &$yield_checks ): bool { return 1 < ++$yield_checks; } );
$staged_checkpoint = json_decode( (string) $staged_workspace->read_raw( 'staged-pages.json' ), true );
$staged_resumed = $prepare_staged->invoke( null, $staged_workspace, $staged_artifact, hash( 'sha256', 'page-checkpoint' ), null, 'staged-pages.json', static fn(): bool => false );
if ( ! is_wp_error( $staged_interrupted ) || 'static_site_importer_invocation_deadline_exceeded' !== $staged_interrupted->get_error_code() || 1 !== count( $staged_checkpoint['plans'] ?? array() ) || is_wp_error( $staged_resumed ) || 2 !== ( $staged_resumed['page_prepared'] ?? -1 ) || 3 !== count( $staged_resumed['page_plans'] ?? array() ) ) { throw new RuntimeException( 'staged page preparation must checkpoint each completed page and resume only unfinished pages' ); }
$staged_workspace->purge();

$payload_workspace_root = sys_get_temp_dir() . '/ssi-collection-payload-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $payload_workspace_root );
$payload_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $payload_workspace_root, 'corruption' );
$store_payload      = new ReflectionMethod( Static_Site_Importer_URL_Batch_Import::class, 'store_collection_payload' );
$stored_payload     = $store_payload->invoke( null, $payload_workspace, 'verified-payload' );
if ( is_wp_error( $stored_payload ) || false === file_put_contents( $payload_workspace->path( $stored_payload['body_ref'] ), 'corrupt-payload' ) ) { throw new RuntimeException( 'collection payload corruption fixture setup failed' ); }
$corrupt_payload = $store_payload->invoke( null, $payload_workspace, 'verified-payload' );
if ( ! is_wp_error( $corrupt_payload ) || 'static_site_importer_collection_payload_corrupt' !== $corrupt_payload->get_error_code() ) { throw new RuntimeException( 'content-addressed collection payload reuse must verify existing bytes' ); }
$payload_workspace->purge();

$responses = array(
	'https://batch.test/sitemap.xml' => array( 'application/xml', '<sitemapindex><sitemap><loc>https://batch.test/one.xml</loc></sitemap><sitemap><loc>https://batch.test/two.xml</loc></sitemap></sitemapindex>' ),
	'https://batch.test/one.xml' => array( 'application/xml', '<urlset><url><loc>https://batch.test/</loc></url><url><loc>https://batch.test/about/</loc></url></urlset>' ),
	'https://batch.test/two.xml' => array( 'application/xml', '<urlset><url><loc>https://batch.test/about/team/</loc></url></urlset>' ),
	'https://batch.test/' => array( 'text/html', '<main><a href="/about/">About</a><link rel="stylesheet" href="/empty.css"></main>' ),
	'https://batch.test/about/' => array( 'text/html', '<main><a href="https://batch.test/about/team/">Team</a></main>' ),
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
$artifact_file_content = static function ( array $file ) use ( &$work_dir ): string {
	if ( isset( $file['content'] ) ) { return (string) $file['content']; }
	$reference = $file['payload_reference']['id'] ?? null;
	$matches = is_string( $reference ) ? glob( $work_dir . '/.ssi-artifact-run-url-*/' . $reference ) : array();
	return ! empty( $matches ) ? (string) file_get_contents( $matches[0] ) : '';
};
$request = array( 'url' => 'https://batch.test/', 'work_dir' => $work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 2, 'request_delay_ms' => 0, 'max_assets' => 10 ) );
$input = array( 'activate' => true );
$artifacts = array();
$attempt = 0;
$importer = static function ( array $artifact, array $args ) use ( &$artifacts, &$attempt, $artifact_file_content ) {
	$artifacts[] = array( 'artifact' => $artifact, 'args' => $args, 'content' => array_map( $artifact_file_content, array_column( $artifact['files'], null, 'path' ) ) );
	if ( 1 === $attempt++ ) { return new WP_Error( 'injected_batch_failure', 'stop after the first completed batch' ); }
	return array( 'theme_slug' => 'batch-site', 'quality' => array( 'pass' => true, 'status' => 'success_with_warnings', 'metrics' => array( 'fallback_count' => 1 ), 'fallbacks' => array( array( 'html' => str_repeat( 'x', 1024 ) ) ) ), 'import_report_summary' => array( 'status' => 'completed' ) );
};
$effect_adapter = new SSI_Test_Final_Hydration_Adapter( $importer );
$first = ssi_test_import( $request, $input, $fetcher, null, $effect_adapter );
if ( ! is_wp_error( $first ) || 'injected_batch_failure' !== $first->get_error_code() ) { throw new RuntimeException( 'injected batch failure must retain a resumable run' ); }
$manifest_path = $first->get_error_data()['run_manifest'] ?? '';
$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( 'failed' !== ( $manifest['state'] ?? '' ) || 'completed' !== ( $manifest['batches'][0]['state'] ?? '' ) || 3 !== ( $manifest['total_routes'] ?? 0 ) ) { throw new RuntimeException( 'manifest must checkpoint discovery and completed batches' ); }
if ( ! preg_match( '/^batch-[a-f0-9]{16}$/', (string) ( $manifest['batches'][0]['batch_id'] ?? '' ) ) || 64 !== strlen( (string) ( $manifest['batches'][0]['result']['snapshot_sha256'] ?? '' ) ) ) { throw new RuntimeException( 'checkpointed batches must retain stable identities and source snapshot evidence' ); }
$legacy_cache = $work_dir . '/url-response-cache-' . $manifest['source']['identity']; wp_mkdir_p( $legacy_cache ); foreach ( glob( $work_dir . '/.ssi-artifact-run-url-' . $manifest['source']['identity'] . '/cache/http-response/*.entry' ) ?: array() as $entry ) { copy( $entry, $legacy_cache . '/' . basename( $entry ) ); }
// Completed batches must carry their frozen staged plans and content-addressed payloads through legacy cache migration so the terminal batch can finalize from the whole site plan.
$run_dir = $work_dir . '/.ssi-artifact-run-url-' . $manifest['source']['identity'];
$completed_batch_ids = array_fill_keys( array_column( array_filter( $manifest['batches'], static fn ( array $batch ): bool => 'completed' === ( $batch['state'] ?? '' ) ), 'batch_id' ), true );
$kept_run_files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $run_dir, FilesystemIterator::SKIP_DOTS ) ) as $file_info ) {
	if ( ! $file_info->isFile() || false !== strpos( $file_info->getPath(), '/cache/' ) ) { continue; }
	$relative = ltrim( str_replace( $run_dir, '', (string) $file_info->getPathname() ), '/' );
	// The interrupted batch restarts from scratch and the shared plan is rebuilt, so only completed-batch state survives the migration.
	if ( 'staged-compiler-shared.json' === $relative || str_starts_with( $relative, 'collection-cursors/' ) ) { continue; }
	if ( str_starts_with( $relative, 'batches/' ) ) {
		$stem = basename( $relative );
		foreach ( array( '.staged-pages.json', '.json' ) as $runtime_suffix ) {
			if ( str_ends_with( $stem, $runtime_suffix ) ) { $stem = substr( $stem, 0, -strlen( $runtime_suffix ) ); break; }
		}
		if ( ! isset( $completed_batch_ids[ $stem ] ) ) { continue; }
	}
	$kept_run_files[ $relative ] = (string) file_get_contents( (string) $file_info->getPathname() );
}
$legacy_cache = $work_dir . '/url-response-cache-' . $manifest['source']['identity'];
wp_mkdir_p( $legacy_cache );
foreach ( glob( $run_dir . '/cache/http-response/*.entry' ) ?: array() as $entry ) { copy( $entry, $legacy_cache . '/' . basename( $entry ) ); }
$legacy_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $work_dir, 'url-' . $manifest['source']['identity'] ); $legacy_workspace->purge();
foreach ( $kept_run_files as $relative => $kept_bytes ) {
	wp_mkdir_p( dirname( $run_dir . '/' . $relative ) );
	file_put_contents( $run_dir . '/' . $relative, $kept_bytes );
}
$resumed = ssi_test_import( $request, $input, $fetcher, static fn( array $artifact, array $args ) => array( 'theme_slug' => 'batch-site', 'artifact' => $artifact, 'args' => $args, 'quality' => array( 'pass' => true, 'status' => 'success', 'metrics' => array( 'fallback_count' => 0 ), 'fallbacks' => array() ), 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $resumed ) || true !== ( $resumed['terminal_batch_result']['args']['activate'] ?? false ) || isset( $resumed['pages'] ) || 2 !== ( $resumed['url_batch_run']['completed_batches'] ?? 0 ) || 3 !== ( $resumed['import_report_summary']['completed_routes'] ?? 0 ) ) { throw new RuntimeException( 'aggregate results must retain terminal output explicitly without misrepresenting it as whole-site output' . ( is_wp_error( $resumed ) ? ': ' . $resumed->get_error_code() . ' ' . $resumed->get_error_message() : ': ' . wp_json_encode( $resumed ) ) ); }
$batch_quality = $resumed['url_batch_run']['batch_quality'] ?? array();
if ( 2 !== count( $batch_quality ) || 1 !== ( $batch_quality[0]['fallback_count'] ?? -1 ) || isset( $batch_quality[0]['fallbacks'] ) || true !== ( $batch_quality[0]['pass'] ?? null ) || true !== ( $batch_quality[1]['pass'] ?? null ) ) { throw new RuntimeException( 'resumed aggregates must derive bounded quality evidence for every completed batch without retaining fallback payloads' ); }
$first_files = array_column( $artifacts[0]['artifact']['files'], null, 'path' );
$second_files = array_column( $artifacts[1]['artifact']['files'], null, 'path' );
$canonical_absolute_route = false;
foreach ( $artifacts as $captured ) {
	if ( ! str_starts_with( (string) ( $captured['artifact']['provenance']['source_url'] ?? '' ), 'https://batch.test/' ) ) { throw new RuntimeException( 'URL artifacts must retain their final source URL as compiler provenance' ); }
	foreach ( $captured['args']['compiled_artifact_result']['wordpress_site_plan']['pages'] ?? array() as $page ) {
		if ( 'website/about/index.html' === ( $page['source_path'] ?? '' ) && str_contains( (string) ( $page['canonical_block_markup'] ?? '' ), 'href="/about/team/"' ) ) { $canonical_absolute_route = true; }
	}
}
if ( ! $canonical_absolute_route ) { throw new RuntimeException( 'staged URL compilation must canonicalize proven same-origin absolute links to materialized routes' ); }
$reference_backed = interface_exists( Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader::class );
$probe = static function () use ( $artifacts, $first_files, $second_files, $reference_backed, &$requests, $resumed, $legacy_cache ): string {
	if ( ! str_contains( (string) ( $artifacts[0]['content']['website/about/index.html'] ?? '' ), 'href="/about/team/"' ) ) { return 'link rewrite'; }
	if ( $reference_backed && ( isset( $first_files['website/about/index.html']['content'] ) || 'blocks-engine/payload-reference/v1' !== ( $first_files['website/about/index.html']['payload_reference']['schema'] ?? '' ) ) ) { return 'opaque payload'; }
	if ( ! isset( $first_files['website/empty.css'] ) ) { return 'empty css'; }
	if ( 2 < count( array_filter( $artifacts[0]['artifact']['files'], static fn( array $file ) => str_ends_with( $file['path'], 'index.html' ) ) ) ) { return 'first index count'; }
	if ( isset( $second_files['website/index.html'] ) ) { return 'second has root'; }
	if ( ! isset( $second_files['website/about/team/index.html'] ) ) { return 'second missing team'; }
	if ( empty( $artifacts[1]['args']['preserve_existing_theme_bootstrap'] ) ) { return 'bootstrap flag'; }
	if ( 2 !== count( array_keys( $requests, 'https://batch.test/about/team/', true ) ) ) { return 'team fetch count: ' . count( array_keys( $requests, 'https://batch.test/about/team/', true ) ); }
	if ( 1 > ( $resumed['url_batch_run']['fetch_cache']['hits'] ?? 0 ) ) { return 'cache hits: ' . wp_json_encode( $resumed['url_batch_run']['fetch_cache'] ?? array() ); }
	if ( is_dir( $legacy_cache ) ) { return 'legacy cache dir remains'; }
	return '';
};
$probe_reason = $probe();
if ( '' !== $probe_reason ) { throw new RuntimeException( 'later batches must retain opaque verified payloads, exclude the unrelated root page, and preserve response reuse [' . $probe_reason . ']' ); }
$again = ssi_test_import( $request, $input, $fetcher, static fn() => new WP_Error( 'should_not_run' ) );
if ( is_wp_error( $again ) || 'batch-site' !== ( $again['theme_slug'] ?? '' ) ) { throw new RuntimeException( 'terminal runs must return their saved SSI result without reimporting' ); }
$mismatch = ssi_test_import( $request, array( 'activate' => true, 'slug' => 'other-target' ), $fetcher, static fn() => new WP_Error( 'should_not_run' ) );
if ( ! is_wp_error( $mismatch ) || 'static_site_importer_batch_contract_mismatch' !== $mismatch->get_error_code() ) { throw new RuntimeException( 'a reused work directory must reject a mismatched import target contract' ); }
$activation_mismatch = ssi_test_import( $request, array( 'activate' => false ), $fetcher, static fn() => new WP_Error( 'should_not_run' ) );
if ( ! is_wp_error( $activation_mismatch ) || 'static_site_importer_batch_contract_mismatch' !== $activation_mismatch->get_error_code() ) { throw new RuntimeException( 'activation must be part of the resumable import contract' ); }
if ( glob( $work_dir . '/url-site-batch-cache-*.json' ) ) { throw new RuntimeException( 'completed batches must remove their fetched artifact cache' ); }
if ( 'completed' !== ( $resumed['url_batch_run']['status'] ?? '' ) || empty( $resumed['url_batch_run']['terminal_batch_report_path'] ) && ! array_key_exists( 'terminal_batch_report_path', $resumed['url_batch_run'] ) ) { throw new RuntimeException( 'aggregate evidence must label terminal-batch report fields explicitly' ); }
$discovery_url = 'https://discovery-progress.test/';
$discovery_work_dir = sys_get_temp_dir() . '/ssi-url-discovery-progress-' . bin2hex( random_bytes( 4 ) );
$discovery_manifest_path = $discovery_work_dir . '/url-site-batch-manifest-' . hash( 'sha256', "2\n" . $discovery_url ) . '.json';
$discovery_checkpoint = array();
$discovery_result = ssi_test_import( array( 'url' => $discovery_url, 'work_dir' => $discovery_work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) ), array(), static function ( string $url, array $args ) use ( $discovery_manifest_path, &$discovery_checkpoint ) { if ( str_ends_with( $url, '/sitemap.xml' ) ) { $discovery_checkpoint = json_decode( (string) file_get_contents( $discovery_manifest_path ), true ); return array( 'body' => '<urlset><url><loc>https://discovery-progress.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } return array( 'body' => '<main>discovered</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); }, static fn() => array( 'theme_slug' => 'discovery-progress', 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $discovery_result ) || 'discovering_routes' !== ( $discovery_checkpoint['phase'] ?? '' ) || 'discovering_routes' !== ( $discovery_checkpoint['progress']['phase'] ?? '' ) || 0 !== ( $discovery_checkpoint['total_routes'] ?? -1 ) || 'completed' !== ( $discovery_result['url_batch_run']['status'] ?? '' ) ) { throw new RuntimeException( 'URL imports must persist durable progress before synchronous route discovery begins' ); }
$discovery_retry_calls = 0; $discovery_retry_failing = true;
$discovery_retry_url = 'https://discovery-retry.test/';
$discovery_retry_request = array( 'url' => $discovery_retry_url, 'work_dir' => sys_get_temp_dir() . '/ssi-url-discovery-retry-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) );
$discovery_retry_fetcher = static function ( string $url, array $args ) use ( &$discovery_retry_calls, &$discovery_retry_failing ) { $discovery_retry_calls++; if ( $discovery_retry_failing ) { return new WP_Error( 'invalid_discovery_fixture', 'retry discovery' ); } if ( str_ends_with( $url, '/sitemap.xml' ) ) { return array( 'body' => '<urlset><url><loc>https://discovery-retry.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } return array( 'body' => '<main>retried</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); };
$discovery_failed = ssi_test_import( $discovery_retry_request, array(), $discovery_retry_fetcher, static fn() => array( 'theme_slug' => 'discovery-retry', 'import_report_summary' => array( 'status' => 'completed' ) ) );
$discovery_retry_failing = false;
$discovery_retried = ssi_test_import( $discovery_retry_request, array(), $discovery_retry_fetcher, static fn() => array( 'theme_slug' => 'discovery-retry', 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( ! is_wp_error( $discovery_failed ) || is_wp_error( $discovery_retried ) || 'completed' !== ( $discovery_retried['url_batch_run']['status'] ?? '' ) || 2 > $discovery_retry_calls ) { throw new RuntimeException( 'a checkpointed discovery failure must remain retryable through the same run' ); }
$continuation_routes = array( 'https://continuation.test/', 'https://continuation.test/one/', 'https://continuation.test/two/' );
$continuation_work_dir = sys_get_temp_dir() . '/ssi-url-continuation-' . bin2hex( random_bytes( 4 ) );
$continuation_request = array( 'url' => 'https://continuation.test/', 'work_dir' => $continuation_work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'max_effective_batches_per_invocation' => 1, 'request_delay_ms' => 0 ) );
$continuation_imports = 0;
$continuation_fetcher = static function ( string $url, array $args ) use ( $continuation_routes ) { if ( 'https://continuation.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset>' . implode( '', array_map( static fn( string $route ): string => '<url><loc>' . $route . '</loc></url>', $continuation_routes ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } return array( 'body' => '<main>' . $url . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); };
$continuation_calls = array();
$continuation_importer = static function ( array $artifact, array $args ) use ( &$continuation_imports, &$continuation_calls ) { $continuation_imports++; $continuation_calls[] = $args; return array( 'theme_slug' => 'continuation', 'import_report_summary' => array( 'status' => 'completed' ) ); };
$continuation_first = ssi_test_import( $continuation_request, array(), $continuation_fetcher, $continuation_importer );
$continuation_manifest = json_decode( (string) file_get_contents( $continuation_first['url_batch_run']['run_manifest'] ?? '' ), true );
if ( is_wp_error( $continuation_first ) || true !== ( $continuation_first['continuation'] ?? false ) || 'continuing' !== ( $continuation_first['url_batch_run']['status'] ?? '' ) || 'importing_batches' !== ( $continuation_first['url_batch_run']['phase'] ?? '' ) || 1 !== ( $continuation_first['url_batch_run']['effective_batches_processed'] ?? 0 ) || 1 !== ( $continuation_first['url_batch_run']['completed_routes'] ?? 0 ) || empty( $continuation_first['url_batch_run']['next_work']['batch_id'] ) || 'running' !== ( $continuation_manifest['state'] ?? '' ) || 1 !== $continuation_imports ) { throw new RuntimeException( 'cooperative imports must checkpoint and return after their configured effective batch budget' ); }
$continuation_second = ssi_test_import( $continuation_request, array(), $continuation_fetcher, $continuation_importer );
$continuation_final = ssi_test_import( $continuation_request, array(), $continuation_fetcher, $continuation_importer );
if ( is_wp_error( $continuation_second ) || true !== ( $continuation_second['continuation'] ?? false ) || is_wp_error( $continuation_final ) || 'completed' !== ( $continuation_final['url_batch_run']['status'] ?? '' ) || 3 !== ( $continuation_final['url_batch_run']['completed_routes'] ?? 0 ) || 3 !== $continuation_imports || 1 !== ( $continuation_final['url_batch_run']['stage_counters']['compiler_shared_prepares'] ?? 0 ) || 3 !== ( $continuation_final['url_batch_run']['stage_counters']['compiler_page_prepares'] ?? 0 ) || 3 !== ( $continuation_final['url_batch_run']['stage_counters']['compiler_compositions'] ?? 0 ) || ! isset( $continuation_final['url_batch_run']['stage_timing']['shared_plan_seconds'] ) ) { throw new RuntimeException( 'cooperative continuation calls must retain one shared compiler plan and page-only plans across three restarted URL batches' ); }
// finalize.test — the terminal apply batch must compose every frozen page plan into one whole-site artifact (issue #991).
$plan_pages_of = static function ( array $args ): array { return array_map( static fn ( array $page ): string => (string) ( $page['path'] ?? $page['slug'] ?? '' ), $args['compiled_artifact_result']['wordpress_site_plan']['pages'] ?? array() ); };
if ( 3 !== count( $continuation_calls ) || 1 !== count( $plan_pages_of( $continuation_calls[0] ?? array() ) ) || 1 !== count( $plan_pages_of( $continuation_calls[1] ?? array() ) ) ) { throw new RuntimeException( 'intermediate apply batches must stay page-local in their composed site plans' ); }
$finalized_pages = $plan_pages_of( $continuation_calls[2] ?? array() );
if ( 3 !== count( $finalized_pages ) || array_diff( array( 'index', 'one', 'two' ), $finalized_pages ) ) { throw new RuntimeException( 'terminal apply batch must compose every persisted page plan with the retained shared plan: ' . wp_json_encode( $finalized_pages ) ); }
$deadline_now = 0; $deadline_fetches = array(); $deadline_imports = 0;
$deadline_request = array( 'url' => 'https://deadline.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-url-deadline-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 2, 'max_invocation_seconds' => 1, '_static_site_importer_clock' => static function () use ( &$deadline_now ) { return $deadline_now; }, 'request_delay_ms' => 0 ) );
$deadline_fetcher = static function ( string $url, array $args ) use ( &$deadline_now, &$deadline_fetches ) { $deadline_fetches[ $url ] = ( $deadline_fetches[ $url ] ?? 0 ) + 1; if ( 'https://deadline.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://deadline.test/</loc></url><url><loc>https://deadline.test/p/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://deadline.test/' === $url ) { $deadline_now = 2; } return array( 'body' => '<main>' . $url . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); };
$deadline_first = ssi_test_import( $deadline_request, array(), $deadline_fetcher, static function () use ( &$deadline_imports ) { $deadline_imports++; return array( 'theme_slug' => 'deadline', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( is_wp_error( $deadline_first ) || 'deadline_exhausted' !== ( $deadline_first['continuation_reason'] ?? '' ) || 0 !== ( $deadline_first['url_batch_run']['completed_routes'] ?? -1 ) || 0 !== $deadline_imports || 1 !== ( $deadline_fetches['https://deadline.test/'] ?? 0 ) || ! empty( $deadline_fetches['https://deadline.test/p/'] ) ) { throw new RuntimeException( 'deadline exhaustion during collection must yield before a new network fetch and retain the pending batch' ); }
$deadline_now = 0;
$deadline_final = ssi_test_import( $deadline_request, array(), $deadline_fetcher, static function () use ( &$deadline_imports ) { $deadline_imports++; return array( 'theme_slug' => 'deadline', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( is_wp_error( $deadline_final ) || 'completed' !== ( $deadline_final['url_batch_run']['status'] ?? '' ) || 2 !== ( $deadline_final['url_batch_run']['completed_routes'] ?? 0 ) || 1 !== ( $deadline_fetches['https://deadline.test/'] ?? 0 ) || 1 !== $deadline_imports ) { throw new RuntimeException( 'deadline continuations must resume from cached collection responses' ); }
$pre_import_now = 0; $pre_import_fetches = 0; $pre_import_calls = 0;
$pre_import_request = array( 'url' => 'https://pre-import-deadline.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-url-pre-import-deadline-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'max_invocation_seconds' => 1, '_static_site_importer_clock' => static function () use ( &$pre_import_now ) { return $pre_import_now; }, 'request_delay_ms' => 0 ) );
$pre_import_fetcher = static function ( string $url, array $args ) use ( &$pre_import_now, &$pre_import_fetches ) { if ( 'https://pre-import-deadline.test/' === $url ) { $pre_import_fetches++; $pre_import_now = 2; return array( 'body' => '<main>ready</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return array( 'body' => '<urlset><url><loc>https://pre-import-deadline.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); };
$pre_import_first = ssi_test_import( $pre_import_request, array(), $pre_import_fetcher, static function () use ( &$pre_import_calls ) { $pre_import_calls++; return array( 'theme_slug' => 'pre-import-deadline', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( is_wp_error( $pre_import_first ) || 'deadline_exhausted' !== ( $pre_import_first['continuation_reason'] ?? '' ) || 0 !== $pre_import_calls || 1 !== $pre_import_fetches ) { throw new RuntimeException( 'a completed collection must yield before artifact import when its deadline is exhausted' ); }
$pre_import_now = 0;
$pre_import_final = ssi_test_import( $pre_import_request, array(), $pre_import_fetcher, static function () use ( &$pre_import_calls ) { $pre_import_calls++; return array( 'theme_slug' => 'pre-import-deadline', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( is_wp_error( $pre_import_final ) || 'completed' !== ( $pre_import_final['url_batch_run']['status'] ?? '' ) || 1 !== $pre_import_calls || 1 !== $pre_import_fetches || 1 !== ( $pre_import_final['url_batch_run']['stage_counters']['shared_plan_reconciliations'] ?? 0 ) || 1 !== ( $pre_import_final['url_batch_run']['stage_counters']['compiler_page_prepares'] ?? 0 ) ) { throw new RuntimeException( 'pre-import deadline continuations must resume persisted staged plans without repeating preparation' ); }
$page_first_now = 0; $page_first_imports = array(); $page_first_fetches = array(); $page_first_asset_fetches = array();
$page_first_request = array( 'url' => 'https://page-first.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-page-first-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'max_invocation_seconds' => 1, '_static_site_importer_clock' => static function () use ( &$page_first_now ) { return $page_first_now; }, 'request_delay_ms' => 0 ) );
$page_first_fetcher = static function ( string $url, array $args ) use ( &$page_first_now, &$page_first_fetches, &$page_first_asset_fetches ) { $page_first_fetches[] = $url; if ( 'https://page-first.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://page-first.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://page-first.test/' === $url ) { $page_first_now = 2; return array( 'body' => '<main><img src="/optional-1.png"><img src="/optional-2.png"><img src="/optional-3.png"></main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } $page_first_asset_fetches[ $url ] = ( $page_first_asset_fetches[ $url ] ?? 0 ) + 1; $page_first_now = 2; return array( 'body' => 'optional-' . basename( $url ), 'metadata' => array( 'content_type' => 'image/png', 'final_url' => $url ) ); };
$page_first_importer = static function ( array $artifact, array $args ) use ( &$page_first_imports ) { $page_first_imports[] = array( 'artifact' => $artifact, 'args' => $args ); return array( 'theme_slug' => 'page-first', 'import_report_summary' => array( 'status' => 'completed' ) ); };
$page_first_interrupted = ssi_test_import( $page_first_request, array(), $page_first_fetcher, $page_first_importer );
$page_first_manifest = json_decode( (string) file_get_contents( $page_first_interrupted['url_batch_run']['run_manifest'] ?? '' ), true );
if ( is_wp_error( $page_first_interrupted ) || 'deadline_exhausted' !== ( $page_first_interrupted['continuation_reason'] ?? '' ) || 1 !== ( $page_first_interrupted['url_batch_run']['completed_routes'] ?? 0 ) || 1 !== ( $page_first_interrupted['url_batch_run']['page_ready_routes'] ?? 0 ) || 1 !== count( $page_first_imports ) || empty( $page_first_imports[0]['args']['page_ready_checkpoint'] ) || ! str_contains( (string) ($page_first_imports[0]['artifact']['files'][0]['content'] ?? ''), 'https://page-first.test/optional-1.png' ) || 'page_ready' !== ($page_first_manifest['batches'][0]['state'] ?? '') || 64 !== strlen( (string) ($page_first_manifest['batches'][0]['result']['snapshot_sha256'] ?? '') ) ) { throw new RuntimeException( 'page readiness must checkpoint and materialize immutable HTML before optional hydration reaches a deadline' ); }
$page_first_resumed = $page_first_interrupted; $cursor_queue_sizes = array();
for ( $i = 0; $i < 5 && ! empty( $page_first_resumed['continuation'] ); $i++ ) { $page_first_now = 0; $page_first_resumed = ssi_test_import( $page_first_request, array(), $page_first_fetcher, $page_first_importer ); $cursor_files = glob( $page_first_request['work_dir'] . '/.ssi-artifact-run-url-*/batches/*.collection-cursor.json' ) ?: array(); if ( ! empty( $cursor_files ) ) { $cursor_data = json_decode( (string) file_get_contents( $cursor_files[0] ), true ); $cursor_queue_sizes[] = count( $cursor_data['asset_queue'] ?? array() ); } }
$resumed_asset_fetches = $page_first_asset_fetches;
$clean_imports = array();
$clean_request = $page_first_request; $clean_request['work_dir'] .= '-clean'; unset( $clean_request['provider_args']['max_invocation_seconds'], $clean_request['provider_args']['_static_site_importer_clock'] );
$clean = ssi_test_import( $clean_request, array(), $page_first_fetcher, static function ( array $artifact, array $args ) use ( &$clean_imports ) { $clean_imports[] = array( 'artifact' => $artifact, 'args' => $args ); return array( 'theme_slug' => 'page-first', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( is_wp_error( $page_first_resumed ) || is_wp_error( $clean ) || 'completed' !== ( $page_first_resumed['url_batch_run']['status'] ?? '' ) || 2 !== count( $page_first_imports ) || 1 !== count( $clean_imports ) || wp_json_encode( $page_first_imports[1]['artifact'] ) !== wp_json_encode( $clean_imports[0]['artifact'] ) || 3 !== count( $resumed_asset_fetches ) || array( 1, 1, 1 ) !== array_values( $resumed_asset_fetches ) || count( array_unique( $cursor_queue_sizes ) ) < 2 ) { throw new RuntimeException( 'resumed optional hydration must checkpoint each resource once and produce the same deterministic artifact as a clean run' ); }
$deferred_now = 0; $deferred_imports = array();
$deferred_request = array( 'url' => 'https://page-ready-deferred.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-page-ready-deferred-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'max_invocation_seconds' => 1, '_static_site_importer_clock' => static function () use ( &$deferred_now ) { return $deferred_now; }, 'request_delay_ms' => 0 ) );
$deferred_fetcher = static function ( string $url, array $args ) use ( &$deferred_now ) { if ( 'https://page-ready-deferred.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://page-ready-deferred.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://page-ready-deferred.test/' === $url ) { $deferred_now = 2; return array( 'body' => '<main><img src="/optional.png"></main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return array( 'body' => 'optional', 'metadata' => array( 'content_type' => 'image/png', 'final_url' => $url ) ); };
$deferred_importer = static function ( array $artifact, array $args ) use ( &$deferred_imports ) { $deferred_imports[] = $args; if ( ! empty( $args['page_ready_checkpoint'] ) ) { return new WP_Error( 'static_site_importer_page_ready_runtime_bindings_deferred', 'defer' ); } return array( 'theme_slug' => 'page-ready-deferred', 'import_report_summary' => array( 'status' => 'completed' ) ); };
$deferred_first = ssi_test_import( $deferred_request, array(), $deferred_fetcher, $deferred_importer );
$deferred_manifest = json_decode( (string) file_get_contents( $deferred_first['url_batch_run']['run_manifest'] ?? '' ), true );
if ( is_wp_error( $deferred_first ) || 'deadline_exhausted' !== ( $deferred_first['continuation_reason'] ?? '' ) || 'pending' !== ( $deferred_manifest['batches'][0]['state'] ?? '' ) || 0 !== ( $deferred_first['url_batch_run']['page_ready_routes'] ?? -1 ) || 'page_ready_materialization_deferred' !== ( $deferred_manifest['diagnostics'][0]['code'] ?? '' ) || 1 !== count( $deferred_imports ) ) { throw new RuntimeException( 'typed page-ready binding deferrals must not checkpoint or report false ready progress' ); }
$deferred_now = 0;
$deferred_final = ssi_test_import( $deferred_request, array(), $deferred_fetcher, $deferred_importer );
if ( is_wp_error( $deferred_final ) || 'completed' !== ( $deferred_final['url_batch_run']['status'] ?? '' ) || 2 !== count( $deferred_imports ) || ! empty( $deferred_imports[1]['page_ready_checkpoint'] ) ) { throw new RuntimeException( 'typed page-ready binding deferrals must resume through complete-snapshot hydration exactly once' ); }
$scale_routes = array();
for ( $i = 0; $i < 1144; $i++ ) { $scale_routes[] = '<url><loc>https://scale.test/page-' . $i . '/</loc></url>'; }
$scale = Static_Site_Importer_URL_Site_Collector::discover_routes( 'https://scale.test/', array( 'request_delay_ms' => 0 ), static fn( string $url, array $args ) => array( 'body' => 'https://scale.test/sitemap.xml' === $url ? '<urlset>' . implode( '', $scale_routes ) . '</urlset>' : '', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ) );
if ( 1144 !== count( $scale ) || 5000 !== ( Static_Site_Importer_URL_Site_Collector::discovery_limits()['max_discovered_routes'] ?? 0 ) ) { throw new RuntimeException( 'discovery must support the acceptance sitemap scale within explicit limits' ); }
$overflow = Static_Site_Importer_URL_Site_Collector::discover_routes( 'https://overflow.test/', array(), static fn( string $url, array $args ) => array( 'body' => '<urlset>' . implode( '', array_map( static fn( int $i ): string => '<url><loc>https://overflow.test/p-' . $i . '/</loc></url>', range( 1, 5001 ) ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ) );
if ( ! is_wp_error( $overflow ) || 'static_site_importer_discovery_incomplete' !== $overflow->get_error_code() || 'routes' !== ( $overflow->get_error_data()['truncated_dimension'] ?? '' ) ) { throw new RuntimeException( 'route discovery must reject queue/route overflow with structured evidence' ); }
$asset_urls = array();
for ( $i = 0; $i < 201; $i++ ) { $asset_urls[] = 'https://assets.test/a-' . $i . '.png'; }
$asset_request = array( 'url' => 'https://assets.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-url-batch-assets-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) );
$asset_result = ssi_test_import( $asset_request, array(), static function ( string $url, array $args ) use ( $asset_urls ) { if ( 'https://assets.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://assets.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://assets.test/' === $url ) { return array( 'body' => '<main>' . implode( '', array_map( static fn( string $asset ): string => '<img src="' . $asset . '">', $asset_urls ) ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return array( 'body' => 'x', 'metadata' => array( 'content_type' => 'image/png', 'final_url' => $url ) ); }, static fn( array $artifact, array $args ) => array( 'theme_slug' => 'assets', 'asset_count' => count( $artifact['files'] ?? array() ) - 1, 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $asset_result ) || 2000 !== ( $asset_result['url_batch_run']['per_batch_limits']['max_assets'] ?? 0 ) || 268435456 !== ( $asset_result['url_batch_run']['per_batch_limits']['max_total_bytes'] ?? 0 ) || 201 > ( $asset_result['terminal_batch_result']['asset_count'] ?? 0 ) ) { throw new RuntimeException( 'batch defaults must support more than legacy 200 assets with bounded per-batch limits' ); }
$lower_request = $asset_request; $lower_request['work_dir'] .= '-lower'; $lower_request['provider_args']['max_assets'] = 1;
$lower_result = ssi_test_import( $lower_request, array(), static fn( string $url, array $args ) => 'https://assets.test/sitemap.xml' === $url ? array( 'body' => '<urlset><url><loc>https://assets.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ) : new WP_Error( 'fixture_stop', 'lower override reached collection' ), static fn() => array() );
if ( ! is_wp_error( $lower_result ) || 1 !== ( $lower_result->get_error_data()['run']['per_batch_limits']['max_assets'] ?? 0 ) ) { throw new RuntimeException( 'caller lower per-batch asset overrides must remain honored' ); }
$split_work_dir = sys_get_temp_dir() . '/ssi-url-split-' . bin2hex( random_bytes( 4 ));
$split_routes = array( 'https://split.test/', 'https://split.test/p1/', 'https://split.test/p2/', 'https://split.test/p3/', 'https://split.test/p4/' );
$split_fetch_counts = array(); $split_fetcher = static function ( string $url, array $args ) use ( $split_routes, &$split_fetch_counts ) { $split_fetch_counts[ $url ] = ( $split_fetch_counts[ $url ] ?? 0 ) + 1; if ( 'https://split.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset>' . implode( '', array_map( static fn( string $route ): string => '<url><loc>' . $route . '</loc></url>', $split_routes ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( in_array( $url, $split_routes, true ) ) { return array( 'body' => '<main>' . str_repeat( 'x', 60 ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return new WP_Error( 'unexpected_asset', $url ); };
$split_request = array( 'url' => 'https://split.test/', 'work_dir' => $split_work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 4, 'max_assets' => 1, 'max_total_bytes' => 160, 'request_delay_ms' => 0 ) );
$split_calls = array(); $split_attempt = 0;
$split_first = ssi_test_import( $split_request, array(), $split_fetcher, static function ( array $artifact, array $args ) use ( &$split_calls, &$split_attempt ) { $split_calls[] = array_column( $artifact['files'], 'path'); if ( 1 === $split_attempt++ ) { return new WP_Error( 'split_resume_failure', 'after a completed split child' ); } return array( 'theme_slug' => 'split', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( ! is_wp_error( $split_first ) || 'split_resume_failure' !== $split_first->get_error_code() ) { throw new RuntimeException( 'split test must fail after a completed child' ); }
$split_manifest_path = $split_first->get_error_data()['run_manifest']; $split_manifest = json_decode( (string) file_get_contents( $split_manifest_path ), true );
if ( empty( array_filter( $split_manifest['diagnostics'] ?? array(), static fn( array $row ): bool => 'batch_subdivided' === ( $row['code'] ?? '' ) ) ) || 'completed' !== ( $split_manifest['batches'][0]['state'] ?? '' ) ) { throw new RuntimeException( 'oversized batch must checkpoint deterministic split lineage before resume' ); }
$completed_before_resume = count( $split_calls );
$split_final = ssi_test_import( $split_request, array(), $split_fetcher, static function ( array $artifact, array $args ) use ( &$split_calls ) { $split_calls[] = array_column( $artifact['files'], 'path'); return array( 'theme_slug' => 'split', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
$split_cache = $split_final['url_batch_run']['fetch_cache'] ?? array(); $split_underlying = array_sum( $split_fetch_counts );
if ( is_wp_error( $split_final ) || 5 !== ( $split_final['url_batch_run']['completed_routes'] ?? 0 ) || count( $split_calls ) <= $completed_before_resume || 1 !== ( $split_fetch_counts['https://split.test/'] ?? 0 ) || $split_underlying !== ( $split_cache['misses'] ?? -1 ) || ( $split_cache['hits'] ?? 0 ) < 1 ) { throw new RuntimeException( 'split resume must complete routes once and reuse cached root/shared fetches with truthful counters' ); }
$split_continuation_request = $split_request; $split_continuation_request['work_dir'] .= '-continuation'; $split_continuation_request['provider_args']['max_effective_batches_per_invocation'] = 1;
$split_continuation_imports = 0;
$split_continuation = ssi_test_import( $split_continuation_request, array(), $split_fetcher, static function () use ( &$split_continuation_imports ) { $split_continuation_imports++; return array( 'theme_slug' => 'split-continuation', 'import_report_summary' => array( 'status' => 'completed' ) ); } );
$split_next_work = $split_continuation['url_batch_run']['next_work'] ?? array(); $split_first_child = $split_continuation['batch_materialization'][0] ?? array();
if ( is_wp_error( $split_continuation ) || true !== ( $split_continuation['continuation'] ?? false ) || 0 !== ( $split_continuation['url_batch_run']['effective_batches_processed'] ?? -1 ) || 0 !== ( $split_continuation['url_batch_run']['completed_routes'] ?? -1 ) || 0 !== $split_continuation_imports || empty( $split_first_child['split_from'] ) || ( $split_first_child['batch_id'] ?? '' ) !== ( $split_next_work['batch_id'] ?? '' ) ) { throw new RuntimeException( 'cooperative split batches must checkpoint and yield before the first child is collected' ); }
$split_resumed = $split_continuation;
for ( $i = 0; $i < 20 && ! empty( $split_resumed['continuation'] ); $i++ ) { $split_resumed = ssi_test_import( $split_continuation_request, array(), $split_fetcher, static function () use ( &$split_continuation_imports ) { $split_continuation_imports++; return array( 'theme_slug' => 'split-continuation', 'import_report_summary' => array( 'status' => 'completed' ) ); } ); }
if ( is_wp_error( $split_resumed ) || ! empty( $split_resumed['continuation'] ) || 'completed' !== ( $split_resumed['url_batch_run']['status'] ?? '' ) || 5 !== ( $split_resumed['url_batch_run']['completed_routes'] ?? 0 ) || 1 > $split_continuation_imports ) { throw new RuntimeException( 'cooperative split continuations must resume through terminal completion' ); }
$asset_count_split_routes = array( 'https://asset-count-split.test/', 'https://asset-count-split.test/one/', 'https://asset-count-split.test/two/' );
$asset_count_split = ssi_test_import( array( 'url' => 'https://asset-count-split.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-url-asset-count-split-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 3, 'max_assets' => 1, 'request_delay_ms' => 0 ) ), array(), static function ( string $url, array $args ) use ( $asset_count_split_routes ) { if ( 'https://asset-count-split.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset>' . implode( '', array_map( static fn( string $route ): string => '<url><loc>' . $route . '</loc></url>', $asset_count_split_routes ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( in_array( $url, $asset_count_split_routes, true ) ) { return array( 'body' => '<img src="/' . basename( rtrim( $url, '/' ) ) . '.png">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return array( 'body' => 'asset', 'metadata' => array( 'content_type' => 'image/png', 'final_url' => $url ) ); }, static fn() => array( 'theme_slug' => 'asset-count-split', 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $asset_count_split ) || 3 !== ( $asset_count_split['url_batch_run']['completed_routes'] ?? 0 ) || empty( array_filter( $asset_count_split['url_batch_run']['diagnostics'] ?? array(), static fn( array $row ): bool => 'batch_subdivided' === ( $row['code'] ?? '' ) ) ) ) { throw new RuntimeException( 'multi-route asset-count pressure must subdivide until singleton batches fit' ); }
$asset_failure_request = array( 'url' => 'https://failure-split.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-url-failure-split-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 2, 'request_delay_ms' => 0 ) );
$asset_failure_imports = 0;
$asset_failure_result = ssi_test_import( $asset_failure_request, array( 'activate' => true ), static function ( string $url, array $args ) { if ( 'https://failure-split.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://failure-split.test/</loc></url><url><loc>https://failure-split.test/p/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://failure-split.test/' === $url ) { return array( 'body' => '<main>' . implode( '', array_map( static fn( int $i ): string => '<img src="/failed-' . $i . '.png?variant=' . $i . '">', range( 1, 4 ) ) ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } if ( 'https://failure-split.test/p/' === $url ) { return array( 'body' => '<main>' . implode( '', array_map( static fn( int $i ): string => '<img src="/failed-' . $i . '.png?variant=' . $i . '">', range( 5, 8 ) ) ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return new WP_Error( 'optional_asset_404', 'failed' ); }, static function ( array $artifact, array $args ) use ( &$asset_failure_imports ) { $asset_failure_imports++; return array( 'theme_slug' => 'failure-split', 'activated' => ! empty( $args['activate'] ), 'import_report_summary' => array( 'status' => 'completed' ) ); } );
if ( is_wp_error( $asset_failure_result ) || 1 !== $asset_failure_imports || 1 !== ( $asset_failure_result['url_batch_run']['completed_batches'] ?? 0 ) || true !== ( $asset_failure_result['terminal_batch_result']['activated'] ?? false ) || 8 !== ( $asset_failure_result['url_batch_run']['external_asset_retained']['count'] ?? 0 ) || 8 !== count( array_filter( $asset_failure_result['url_batch_run']['external_asset_retained']['samples'] ?? array(), static fn( array $sample ): bool => 'optional_asset_404' === ( $sample['reason'] ?? '' ) ) ) || ! empty( array_filter( $asset_failure_result['url_batch_run']['diagnostics'] ?? array(), static fn( array $row ): bool => 'batch_subdivided' === ( $row['code'] ?? '' ) ) ) ) { throw new RuntimeException( 'two-route optional asset 404 variants must import once, retain exact evidence, and activate without subdivision' ); }
$rewrite_context = Static_Site_Importer_URL_Site_Collector::collect( 'https://contexts.test/', array( 'asset_failure_policy' => 'preserve_external', 'require_complete_collection' => true, 'request_delay_ms' => 0 ), static function ( string $url, array $args ) { if ( 'https://contexts.test/sitemap.xml' === $url ) { return new WP_Error( 'no_sitemap', '' ); } if ( 'https://contexts.test/' === $url ) { return array( 'body' => '<style>.x{background:url(/inline.png)}</style><link rel="stylesheet" href="/style.css"><img srcset="/one.png 1x, /two.png?x=1#f 2x" style="background:url(/style.png)">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } if ( 'https://contexts.test/style.css' === $url ) { return array( 'body' => '@import "/import.css?x=1#f";.x{background:url(/nested.png)}', 'metadata' => array( 'content_type' => 'text/css', 'final_url' => $url ) ); } return new WP_Error( 'optional_asset_timeout', 'failed' ); } );
$context_files = is_wp_error( $rewrite_context ) ? array() : array_column( $rewrite_context['artifact']['files'], null, 'path'); $context_html = $context_files['website/index.html']['content'] ?? ''; $context_css = $context_files['website/style.css']['content'] ?? '';
if ( is_wp_error( $rewrite_context ) || ! str_contains( $context_html, 'https://contexts.test/one.png' ) || ! str_contains( $context_html, 'https://contexts.test/two.png?x=1#f' ) || ! str_contains( $context_html, 'https://contexts.test/inline.png' ) || ! str_contains( $context_html, 'https://contexts.test/style.png' ) || ! str_contains( $context_css, 'https://contexts.test/import.css?x=1#f' ) || ! str_contains( $context_css, 'https://contexts.test/nested.png' ) || 6 !== ( $rewrite_context['source_metadata']['collection']['external_asset_retained']['count'] ?? 0 ) ) { throw new RuntimeException( 'external asset preservation must cover srcset, inline CSS, fetched CSS urls and imports' ); }
$nested_limit = Static_Site_Importer_URL_Site_Collector::collect( 'https://nested.test/', array( 'max_assets' => 1, 'asset_failure_policy' => 'preserve_external', 'require_complete_collection' => true, 'request_delay_ms' => 0 ), static function ( string $url, array $args ) { if ( 'https://nested.test/sitemap.xml' === $url ) { return new WP_Error( 'none', '' ); } if ( 'https://nested.test/' === $url ) { return array( 'body' => '<link rel="stylesheet" href="/style.css">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return array( 'body' => '.x{background:url(/nested.png)}', 'metadata' => array( 'content_type' => 'text/css', 'final_url' => $url ) ); } );
if ( is_wp_error( $nested_limit ) || 1 !== ( $nested_limit['source_metadata']['collection']['external_asset_retained']['count'] ?? 0 ) || 'asset_limit' !== ( $nested_limit['source_metadata']['collection']['external_asset_retained']['samples'][0]['reason'] ?? '' ) ) { throw new RuntimeException( 'nested CSS asset admission must preserve external URLs at the asset limit' ); }
$retained_failure = ssi_test_import( array( 'url' => 'https://persist.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-retained-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) ), array(), static function ( string $url, array $args ) { if ( 'https://persist.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://persist.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://persist.test/' === $url ) { return array( 'body' => '<img src="/remote.png">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return new WP_Error( 'optional_timeout', 'failed' ); }, static fn() => array( 'theme_slug' => 'retained', 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $retained_failure ) || 1 !== ( $retained_failure['url_batch_run']['external_asset_retained']['count'] ?? 0 ) || 'optional_timeout' !== ( $retained_failure['url_batch_run']['external_asset_retained']['samples'][0]['reason'] ?? '' ) ) { throw new RuntimeException( 'singleton batch asset failures must retain the external URL and complete' ); }
$preserved_asset = Static_Site_Importer_URL_Site_Collector::collect( 'https://preserve.test/', array( 'max_assets' => 10, 'require_complete_collection' => true, 'asset_failure_policy' => 'preserve_external', 'request_delay_ms' => 0 ), static function ( string $url, array $args ) { if ( 'https://preserve.test/sitemap.xml' === $url ) { return new WP_Error( 'no_sitemap', '' ); } if ( 'https://preserve.test/' === $url ) { return array( 'body' => '<main><img src="https://preserve.test/too-big.jpg"></main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return new WP_Error( 'static_site_importer_url_too_large', 'too large' ); } );
$preserved_files = is_wp_error( $preserved_asset ) ? array() : array_column( $preserved_asset['artifact']['files'], null, 'path' );
if ( is_wp_error( $preserved_asset ) || isset( $preserved_files['website/too-big.jpg'] ) || ! str_contains( (string) $preserved_files['website/index.html']['content'], 'https://preserve.test/too-big.jpg' ) || 1 !== ( $preserved_asset['source_metadata']['collection']['external_asset_retained']['count'] ?? 0 ) || 'static_site_importer_url_too_large' !== ( $preserved_asset['source_metadata']['collection']['external_asset_retained']['samples'][0]['reason'] ?? '' ) ) { throw new RuntimeException( 'single-route optional oversized assets must remain absolute with retained-asset evidence' ); }
$strict_asset = Static_Site_Importer_URL_Site_Collector::collect( 'https://strict.test/', array( 'require_complete_collection' => true, 'request_delay_ms' => 0 ), static function ( string $url, array $args ) { if ( 'https://strict.test/sitemap.xml' === $url ) { return new WP_Error( 'no_sitemap', '' ); } if ( 'https://strict.test/' === $url ) { return array( 'body' => '<img src="/missing.png">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } return new WP_Error( 'optional_asset_failed', 'failed' ); } );
if ( ! is_wp_error( $strict_asset ) || 'static_site_importer_site_collection_incomplete' !== $strict_asset->get_error_code() ) { throw new RuntimeException( 'strict non-batch complete collection must retain optional asset failures' ); }
$html_failed = Static_Site_Importer_URL_Site_Collector::collect( 'https://html-failed.test/', array( 'asset_failure_policy' => 'preserve_external' ), static fn( string $url, array $args ) => new WP_Error( 'html_fetch_failed', 'failed' ) );
if ( ! is_wp_error( $html_failed ) || 'html_fetch_failed' !== $html_failed->get_error_code() ) { throw new RuntimeException( 'asset preservation must never downgrade HTML page failures' ); }
$ownership_dir = sys_get_temp_dir() . '/ssi-retained-ownership-' . bin2hex( random_bytes( 4 ) ); wp_mkdir_p( $ownership_dir ); $ownership_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $ownership_dir, 'ownership' );
$ownership_workspace->publish_json( 'batches/batch-stable.json', array( 'source_metadata' => array( 'snapshot' => array( 'files' => array( array( 'mime_type' => 'text/html', 'source_url' => 'https://ownership.test/stale/' ) ) ) ) ) );
$retained_runtime_method = new ReflectionMethod( Static_Site_Importer_URL_Batch_Import::class, 'retained_runtime' );
$owned_runtime = $retained_runtime_method->invoke( null, $ownership_workspace, 'batches/batch-stable.json', 'batches/0.json', $ownership_dir . '/legacy.json', array( 'https://ownership.test/current/' ) );
if ( null !== $owned_runtime || null !== $ownership_workspace->read_raw( 'batches/batch-stable.json' ) ) { throw new RuntimeException( 'stable retained batch payloads must prove exact route ownership before reuse' ); }
$redirected_runtime = array( 'source_metadata' => array( 'snapshot' => array( 'files' => array( array( 'mime_type' => 'text/html', 'source_url' => 'https://ownership.test/final' ) ) ), 'collection' => array( 'page_aliases' => array( 'https://ownership.test/requested.html' => 'https://ownership.test/final' ) ) ), 'artifact' => array( 'files' => array( array( 'mime_type' => 'text/html', 'metadata' => array( 'route_path' => '/final' ) ) ) ) );
$ownership_workspace->publish_json( 'batches/batch-redirect.json', $redirected_runtime );
$redirect_owned = $retained_runtime_method->invoke( null, $ownership_workspace, 'batches/batch-redirect.json', 'batches/2.json', $ownership_dir . '/legacy-redirect.json', array( 'https://ownership.test/requested.html' ) );
if ( ! is_string( $redirect_owned ) || null === $ownership_workspace->read_raw( 'batches/batch-redirect.json' ) ) { throw new RuntimeException( 'retained batch payloads must accept exact collector-proven page redirects' ); }
$duplicate_runtime = array( 'source_metadata' => array( 'snapshot' => array( 'files' => array( array( 'mime_type' => 'text/html', 'source_url' => 'https://ownership.test/a/' ), array( 'mime_type' => 'text/html', 'source_url' => 'https://ownership.test/b/' ) ) ) ), 'artifact' => array( 'files' => array( array( 'mime_type' => 'text/html', 'metadata' => array( 'route_path' => '/same' ) ), array( 'mime_type' => 'text/html', 'metadata' => array( 'route_path' => '/same' ) ) ) ) );
$ownership_workspace->publish_json( 'batches/batch-duplicate.json', $duplicate_runtime );
$duplicate_owned = $retained_runtime_method->invoke( null, $ownership_workspace, 'batches/batch-duplicate.json', 'batches/1.json', $ownership_dir . '/legacy-duplicate.json', array( 'https://ownership.test/a/', 'https://ownership.test/b/' ) );
if ( null !== $duplicate_owned || null !== $ownership_workspace->read_raw( 'batches/batch-duplicate.json' ) ) { throw new RuntimeException( 'retained batch payloads with colliding explicit routes must be recollected after route canonicalization upgrades' ); }
$ownership_workspace->purge();
$cache_dir = sys_get_temp_dir() . '/ssi-response-cache-' . bin2hex( random_bytes( 4 ) ); wp_mkdir_p( $cache_dir ); $network_calls = 0; $cache_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $cache_dir, 'cache' ); $cache = new Static_Site_Importer_Artifact_Byte_Cache( $cache_workspace, 'http-response' ); $cached_fetch = static function ( string $url, array $args ) use ( &$network_calls, $cache ) { $types = isset( $args['content_types'] ) ? $args['content_types'] : null; if ( is_array( $types ) ) { sort( $types ); } $key = hash( 'sha256', $url . "\n" . wp_json_encode( array( 'max_bytes' => $args['max_bytes'] ?? null, 'content_types' => $types, 'timeout' => $args['timeout'] ?? null ) ) ); $cached = $cache->get( $key ); if ( is_array( $cached ) ) { $cache->hit(); return array( 'body' => $cached['bytes'], 'metadata' => $cached['value'] ); } $cache->miss(); $network_calls++; $response = array( 'body' => 'body-' . $network_calls, 'metadata' => array( 'content_type' => 'text/plain', 'final_url' => $url ) ); $cache->put( $key, $response['body'], $response['metadata'] ); return $response; };
$cached_fetch( 'https://cache.test/a', array( 'max_bytes' => 10, 'content_types' => array() ) ); $cached_fetch( 'https://cache.test/a', array( 'max_bytes' => 10, 'content_types' => array() ) ); $cached_fetch( 'https://cache.test/a', array( 'max_bytes' => 11, 'content_types' => array() ) );
if ( 2 !== $network_calls || 1 !== ( $cache->evidence()['hits'] ?? 0 ) ) { throw new RuntimeException( 'response cache must hit compatible constraints and miss incompatible ones' ); }
$corrupt = glob( $cache_workspace->directory() . '/cache/http-response/*.entry' ) ?: array(); foreach ( $corrupt as $path ) { file_put_contents( $path, 'corrupt' ); } $cached_fetch( 'https://cache.test/a', array( 'max_bytes' => 10, 'content_types' => array() ) );
if ( 3 !== $network_calls || 1 > ( $cache->evidence()['corrupt_entries'] ?? 0 ) ) { throw new RuntimeException( 'corrupt response cache entries must recover as misses' ); }
$cache_workspace->purge(); if ( is_dir( $cache_workspace->directory() ) ) { throw new RuntimeException( 'successful response cache cleanup must remove payloads' ); }
$poison_calls = 0; $poison_dir = sys_get_temp_dir() . '/ssi-poison-cache-' . bin2hex( random_bytes( 4 ) );
$poison_request = array( 'url' => 'https://poison.test/', 'work_dir' => $poison_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) );
$poison_fetcher = static function ( string $url, array $args ) use ( &$poison_calls ) { if ( 'https://poison.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://poison.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } $poison_calls++; return array( 'body' => 1 === $poison_calls ? '<div id="app"></div>' . str_repeat( '<script></script>', 20 ) : '<main>' . str_repeat( 'server-rendered ', 100 ) . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); };
$poisoned = ssi_test_import( $poison_request, array(), $poison_fetcher, static fn() => array() );
$recovered = ssi_test_import( $poison_request, array(), $poison_fetcher, static fn() => array( 'theme_slug' => 'recovered', 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( ! is_wp_error( $poisoned ) || 'static_site_importer_url_client_rendered_app' !== $poisoned->get_error_code() || is_wp_error( $recovered ) || 2 !== $poison_calls ) { throw new RuntimeException( 'transient client-rendered HTML must fail truthfully without poisoning a resumable response cache' ); }
$tiny_calls = 0; $tiny_dir = sys_get_temp_dir() . '/ssi-tiny-cache-' . bin2hex( random_bytes( 4 ) ); wp_mkdir_p( $tiny_dir ); $tiny_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $tiny_dir, 'cache' ); $tiny = new Static_Site_Importer_Artifact_Byte_Cache( $tiny_workspace, 'payload', 1, 1 ); $tiny_fetch = static function () use ( &$tiny_calls, $tiny ) { $tiny_calls++; $tiny->put( 'a', 'body', array() ); }; $tiny_fetch(); $tiny_fetch();
if ( 2 !== $tiny_calls || 2 !== ( $tiny->evidence()['bypassed'] ?? 0 ) || glob( $tiny_workspace->directory() . '/cache/payload/*.entry' ) ) { throw new RuntimeException( 'cache guards must bypass writes without creating entries' ); }
$tiny_workspace->purge();
$negative_dir = sys_get_temp_dir() . '/ssi-negative-cache-' . bin2hex( random_bytes( 4 ) ); wp_mkdir_p( $negative_dir ); $negative_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $negative_dir, 'cache' ); $negative = new Static_Site_Importer_Artifact_Byte_Cache( $negative_workspace, 'http-response' );
$negative->put_failure( 'transient', array( 'code' => 'transient_timeout', 'message' => 'retry', 'data' => array() ), 130 ); $negative_hit = $negative->get_failure( 'transient', 100 ); $negative_expired = $negative->get_failure( 'transient', 130 ); $negative->put( 'transient', 'recovered', array( 'content_type' => 'text/plain' ) ); $negative_recovered = $negative->get( 'transient' );
if ( 'transient_timeout' !== ( $negative_hit['code'] ?? '' ) || null !== $negative_expired || 'recovered' !== ( $negative_recovered['bytes'] ?? '' ) || 1 !== ( $negative->evidence()['negative_writes'] ?? 0 ) || 1 !== ( $negative->evidence()['negative_hits'] ?? 0 ) || 1 !== ( $negative->evidence()['negative_expired'] ?? 0 ) || 1 !== ( $negative->evidence()['network_requests_avoided'] ?? 0 ) ) { throw new RuntimeException( 'negative cache entries must avoid repeated requests only until expiry, then permit successful replacement' ); }
$negative_workspace->purge();
$delay_calls = 0; $shared_asset_calls = 0;
$delay_result = ssi_test_import( array( 'url' => 'https://delay.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-delay-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 1, '_static_site_importer_delay_callback' => static function () use ( &$delay_calls ) { $delay_calls++; } ) ), array(), static function ( string $url, array $args ) use ( &$shared_asset_calls ) { if ( 'https://delay.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://delay.test/</loc></url><url><loc>https://delay.test/p/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://delay.test/shared.png' === $url ) { $shared_asset_calls++; return array( 'body' => 'asset', 'metadata' => array( 'content_type' => 'image/png', 'final_url' => $url ) ); } return array( 'body' => '<img src="/shared.png">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); }, static fn() => array( 'theme_slug' => 'delay', 'import_report_summary' => array( 'status' => 'completed' ) ) );
if ( is_wp_error( $delay_result ) || 1 !== $shared_asset_calls || 0 !== $delay_calls ) { throw new RuntimeException( 'verified shared assets must bypass repeated fetches without incurring retry pacing' ); }
$negative_asset_calls = 0; $negative_delays = 0;
$negative_request = array( 'url' => 'https://negative.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-negative-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'fetch_attempts' => 2, 'request_delay_ms' => 1, '_static_site_importer_delay_callback' => static function () use ( &$negative_delays ) { $negative_delays++; } ) );
$negative_fetcher = static function ( string $url, array $args ) use ( &$negative_asset_calls ) { if ( 'https://negative.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://negative.test/</loc></url><url><loc>https://negative.test/p/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://negative.test/shared.png' === $url ) { $negative_asset_calls++; return new WP_Error( 'asset_timeout', 'temporary failure' ); } return array( 'body' => '<img src="/shared.png">', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); };
$negative_result = ssi_test_import( $negative_request, array(), $negative_fetcher, static fn() => array( 'theme_slug' => 'negative', 'import_report_summary' => array( 'status' => 'completed' ) ) );
$negative_resume = ssi_test_import( $negative_request, array(), $negative_fetcher, static fn() => array() );
if ( is_wp_error( $negative_result ) || is_wp_error( $negative_resume ) || 2 !== $negative_asset_calls || 1 > ( $negative_result['url_batch_run']['fetch_cache']['negative_writes'] ?? 0 ) || 2 !== ( $negative_result['url_batch_run']['external_asset_retained']['count'] ?? 0 ) ) { throw new RuntimeException( 'negative cache failures must retain optional singleton assets without exposing internal cache hooks' ); }

$runtime_imports = array();
add_filter( 'static_site_importer_url_batch_import_args', static fn(): array => array( 'collect_site' => true, 'batch_pages' => 1, 'max_effective_batches_per_invocation' => 1, 'request_delay_ms' => 0, 'max_assets' => 10, 'max_total_bytes' => 1024 * 1024, 'include_scripts' => false ) );
add_filter( 'static_site_importer_url_batch_import_fetcher', static fn() => static function ( string $url ): array { if ( 'https://runtime.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://runtime.test/</loc></url><url><loc>https://runtime.test/about/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } if ( 'https://runtime-one.test/sitemap.xml' === $url ) { return array( 'body' => '<urlset><url><loc>https://runtime-one.test/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } return array( 'body' => '<main>' . $url . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); } );
add_filter( 'static_site_importer_url_batch_importer', static function () use ( &$runtime_imports ) { return new SSI_Test_Final_Hydration_Adapter( static function ( array $artifact, array $args ) use ( &$runtime_imports ): array { $runtime_imports[] = $args; return array( 'theme_slug' => 'runtime', 'materialization_receipt' => array( 'status' => 'completed' ), 'import_report_summary' => array( 'status' => 'completed' ) ); } ); } );
$runtime_input = array( 'url' => 'https://runtime.test/', 'slug' => 'runtime', 'activate' => true );
$runtime_first = Static_Site_Importer_URL_Import_Runtime::import_url( $runtime_input );
if ( is_wp_error( $runtime_first ) || empty( $runtime_first['continuation'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $runtime_first['import_id'] ?? '' ) ) || isset( $runtime_first['url_batch_run']['run_manifest'] ) || 1 !== ( $runtime_first['url_batch_run']['completed_routes'] ?? 0 ) ) { throw new RuntimeException( 'public URL imports must use opaque, path-free resumable batch runs without caller mode flags' ); }
$runtime_input['import_id'] = $runtime_first['import_id'];
$runtime_second = Static_Site_Importer_URL_Import_Runtime::import_url( $runtime_input );
if ( is_wp_error( $runtime_second ) || 'completed' !== ( $runtime_second['url_batch_run']['status'] ?? '' ) || $runtime_first['import_id'] !== ( $runtime_second['import_id'] ?? '' ) || 2 !== count( $runtime_imports ) || true !== ( $runtime_imports[1]['activate'] ?? false ) ) { throw new RuntimeException( 'opaque continuation must resume the server-owned workspace and preserve terminal activation' ); }
$changed_url = $runtime_input; $changed_url['url'] = 'https://other.test/';
$changed_options = $runtime_input; $changed_options['activate'] = false;
$GLOBALS['ssi_test_user_id'] = 2; $changed_user = Static_Site_Importer_URL_Import_Runtime::import_url( $runtime_input ); $GLOBALS['ssi_test_user_id'] = 1;
if ( ! is_wp_error( Static_Site_Importer_URL_Import_Runtime::import_url( $changed_url ) ) || ! is_wp_error( Static_Site_Importer_URL_Import_Runtime::import_url( $changed_options ) ) || ! is_wp_error( $changed_user ) ) { throw new RuntimeException( 'opaque identities must reject URL, import-option, and user changes' ); }
$runtime_one = Static_Site_Importer_URL_Import_Runtime::import_url( array( 'url' => 'https://runtime-one.test/', 'slug' => 'runtime-one' ) );
$runtime_replay = Static_Site_Importer_URL_Import_Runtime::import_url( array( 'url' => 'https://runtime.test/', 'slug' => 'runtime', 'activate' => true, 'import_id' => $runtime_first['import_id'] ) );
$ability_replay = static_site_importer_ability_import( array( 'source' => array( 'type' => 'url', 'url' => 'https://runtime.test/', 'import_id' => $runtime_first['import_id'] ), 'slug' => 'runtime', 'activate' => true ) );
if ( is_wp_error( $runtime_one ) || 'completed' !== ( $runtime_one['url_batch_run']['status'] ?? '' ) || empty( $runtime_one['import_id'] ) || is_wp_error( $runtime_replay ) || 'completed' !== ( $runtime_replay['url_batch_run']['status'] ?? '' ) || 'static_site_importer_url_apply_requires_plan' !== ( $ability_replay['error']['code'] ?? '' ) ) { throw new RuntimeException( 'legacy URL runs retain their opaque batch contract while the canonical ability requires an approved plan for apply' ); }
add_filter( 'static_site_importer_url_batch_import_fetcher', static function ( $fetcher, array $request ) {
	if ( 'https://runtime-plan.test/' !== ( $request['url'] ?? '' ) ) {
		return $fetcher;
	}

	return static function ( string $url ): array {
		if ( 'https://runtime-plan.test/sitemap.xml' === $url ) {
			return array( 'body' => '<urlset><url><loc>https://runtime-plan.test/</loc></url><url><loc>https://runtime-plan.test/about/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) );
		}

		return array( 'body' => '<main>' . $url . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
	};
} );
$writes_before_url_plan = count( $runtime_imports );
$url_plan_first = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'url', 'url' => 'https://runtime-plan.test/' ), 'slug' => 'runtime-plan' ) );
$url_plan_final = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'url', 'url' => 'https://runtime-plan.test/', 'import_id' => $url_plan_first['import_id'] ?? '' ), 'slug' => 'runtime-plan' ) );
$url_plan_pages = $url_plan_final['plan']['pages'] ?? array();
$url_plan_paths = array_map( static fn( array $page ): string => (string) ( $page['path'] ?? $page['slug'] ?? '' ), $url_plan_pages );
$operation_mismatch = Static_Site_Importer_URL_Import_Runtime::run_operation( array( 'operation' => 'apply', 'source' => array( 'type' => 'url' ), 'url' => 'https://runtime-plan.test/', 'import_id' => $url_plan_first['import_id'] ?? '', 'slug' => 'runtime-plan' ) );
if ( empty( $url_plan_first['continuation'] ) || empty( $url_plan_first['import_id'] ) || ! empty( $url_plan_first['plan'] ) || empty( $url_plan_final['plan'] ) || 'completed' !== ( $url_plan_final['url_batch_run']['status'] ?? '' ) || 2 !== count( $url_plan_pages ) || empty( $url_plan_paths[0] ) || empty( $url_plan_paths[1] ) || $writes_before_url_plan !== count( $runtime_imports ) || ! is_wp_error( $operation_mismatch ) || 'static_site_importer_url_import_run_mismatch' !== $operation_mismatch->get_error_code() ) { throw new RuntimeException( 'URL planning must compose every frozen batch into one plan without importer writes and bind continuation to operation intent' ); }

// shared-change.test — plan-mode cross-batch shared digest change (issue #901)
add_filter( 'static_site_importer_url_batch_import_fetcher', static function ( $fetcher, array $request ) {
	if ( 'https://shared-change.test/' !== ( $request['url'] ?? '' ) ) {
		return $fetcher;
	}
	return static function ( string $url ): array {
		if ( 'https://shared-change.test/sitemap.xml' === $url ) {
			return array( 'body' => '<urlset><url><loc>https://shared-change.test/</loc></url><url><loc>https://shared-change.test/page2/</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) );
		}
		if ( 'https://shared-change.test/' === $url ) {
			return array( 'body' => '<main>page1<link rel="stylesheet" href="/theme-a.css"></main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
		}
		if ( 'https://shared-change.test/page2/' === $url ) {
			return array( 'body' => '<main>page2<link rel="stylesheet" href="/theme-a.css"><link rel="stylesheet" href="/theme-b.css"></main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
		}
		if ( 'https://shared-change.test/theme-a.css' === $url ) {
			return array( 'body' => 'body{color:red}', 'metadata' => array( 'content_type' => 'text/css', 'final_url' => $url ) );
		}
		if ( 'https://shared-change.test/theme-b.css' === $url ) {
			return array( 'body' => 'h1{font-size:2em}', 'metadata' => array( 'content_type' => 'text/css', 'final_url' => $url ) );
		}
		return new WP_Error( 'not_found', 'not found' );
	};
} );
$writes_before_shared = count( $runtime_imports );
$shared_first         = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'url', 'url' => 'https://shared-change.test/' ), 'slug' => 'shared-change' ) );
$shared_final         = static_site_importer_ability_import( array( 'operation' => 'plan', 'source' => array( 'type' => 'url', 'url' => 'https://shared-change.test/', 'import_id' => $shared_first['import_id'] ?? '' ), 'slug' => 'shared-change' ) );
$shared_pages         = $shared_final['plan']['pages'] ?? array();
$shared_paths         = array_map( static fn( array $p ): string => (string) ( $p['path'] ?? $p['slug'] ?? '' ), $shared_pages );
if ( empty( $shared_first['continuation'] ) || empty( $shared_first['import_id'] ) || ! empty( $shared_first['plan'] ) || empty( $shared_final['plan'] ) || 'completed' !== ( $shared_final['url_batch_run']['status'] ?? '' ) || 2 !== count( $shared_pages ) || empty( $shared_paths[0] ) || empty( $shared_paths[1] ) || $writes_before_shared !== count( $runtime_imports ) ) { throw new RuntimeException( 'shared plan change across batches must re-prepare stale page plans in compose_complete_plan and succeed without importer writes' ); }

if ( interface_exists( Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader::class ) ) {
	$shopify_font_fetches = 0;
	$shopify_compositions = array();
	$shopify_result = ssi_test_import(
		array( 'url' => 'https://shopify-font.test/', 'work_dir' => sys_get_temp_dir() . '/ssi-shopify-font-' . bin2hex( random_bytes( 4 ) ), 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0 ) ),
		array(),
		static function ( string $url, array $args ) use ( &$shopify_font_fetches ) {
			if ( 'https://shopify-font.test/sitemap.xml' === $url ) {
				return array( 'body' => '<urlset><url><loc>https://shopify-font.test/</loc></url><url><loc>https://shopify-font.test/blogs/news/index.html</loc></url></urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) );
			}
			if ( 'https://shopify-font.test/cdn/fonts/host_grotesk/font.woff2' === $url ) {
				$shopify_font_fetches++;
				return array( 'body' => 'shopify-font', 'metadata' => array( 'content_type' => 'font/woff2', 'final_url' => $url ) );
			}
			$preload = '<link rel="preload" as="font" href="' . ( 'https://shopify-font.test/' === $url ? '/cdn/fonts/host_grotesk/font.woff2' : '../../cdn/fonts/host_grotesk/font.woff2' ) . '">';
			return array( 'body' => '<html><head>' . $preload . '</head><body><main>Shopify</main></body></html>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
		},
		static function ( array $artifact, array $args ) use ( &$shopify_compositions ): array {
			$shopify_compositions[] = $args['compiled_artifact_result'] ?? array();
			return array( 'theme_slug' => 'shopify-font', 'import_report_summary' => array( 'status' => 'completed' ) );
		}
	);
	$shopify_terminal = $shopify_compositions[1] ?? array();
	$shopify_json     = wp_json_encode( $shopify_terminal );
	$shopify_plan_pages = $shopify_terminal['wordpress_site_plan']['pages'] ?? array();
	$shopify_probe = '';
	if ( is_wp_error( $shopify_result ) ) { $shopify_probe = 'error: ' . $shopify_result->get_error_code() . ' ' . $shopify_result->get_error_message(); }
	elseif ( 1 !== $shopify_font_fetches ) { $shopify_probe = 'font fetches: ' . $shopify_font_fetches; }
	elseif ( 2 !== count( $shopify_compositions ) ) { $shopify_probe = 'compositions: ' . count( $shopify_compositions ); }
	elseif ( ! str_contains( (string) $shopify_json, 'asset_reference' ) ) { $shopify_probe = 'no asset_reference'; }
	elseif ( str_contains( (string) $shopify_json, 'unresolved_local_url' ) ) { $shopify_probe = 'unresolved_local_url'; }
	$shopify_document_pages = array_map( static fn ( array $page ): string => (string) ( $page['source_path'] ?? '' ), array_filter( $shopify_plan_pages, static fn ( array $page ): bool => str_starts_with( (string) ( $page['source_path'] ?? '' ), 'website/' ) ) );
	if ( 2 !== count( $shopify_document_pages ) || ! in_array( 'website/index.html', $shopify_document_pages, true ) || ! in_array( 'website/blogs/news/index.html', $shopify_document_pages, true ) ) { $shopify_probe = 'document plan pages: ' . wp_json_encode( array_values( $shopify_document_pages ) ); }
	if ( '' !== $shopify_probe ) { throw new RuntimeException( 'retained Shopify font preloads must refetch once, inject their payload reference, compose without unresolved local URLs, and finalize the terminal batch from the whole-site plan (issue #991) [' . $shopify_probe . ']' ); }
}
// finalize-failclosed.test — a completed batch losing its frozen staged plans must fail closed on terminal composition (issue #991).
$failclosed_routes = array( 'https://finalize-failclosed.test/', 'https://finalize-failclosed.test/about/' );
$failclosed_work_dir = sys_get_temp_dir() . '/ssi-url-finalize-failclosed-' . bin2hex( random_bytes( 4 ) );
$failclosed_request = array( 'url' => 'https://finalize-failclosed.test/', 'work_dir' => $failclosed_work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'max_effective_batches_per_invocation' => 1, 'request_delay_ms' => 0 ) );
$failclosed_fetcher = static function ( string $url, array $args ) use ( $failclosed_routes ) { if ( str_ends_with( $url, '/sitemap.xml' ) ) { return array( 'body' => '<urlset>' . implode( '', array_map( static fn ( string $route ): string => '<url><loc>' . $route . '</loc></url>', $failclosed_routes ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) ); } return array( 'body' => '<main>' . $url . '</main>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) ); };
$failclosed_calls    = array();
$failclosed_importer = static function ( array $artifact, array $args ) use ( &$failclosed_calls ): array {
	$failclosed_calls[] = $args;
	return array( 'theme_slug' => 'finalize-failclosed', 'import_report_summary' => array( 'status' => 'completed' ) );
};
$failclosed_first = ssi_test_import( $failclosed_request, array(), $failclosed_fetcher, $failclosed_importer );
if ( ! is_array( $failclosed_first ) || empty( $failclosed_first['continuation'] ) || empty( $failclosed_first['url_batch_run']['run_manifest'] ) ) { throw new RuntimeException( 'fail-closed scenario needs one checkpointed continuation batch first' ); }
$failclosed_manifest = json_decode( (string) file_get_contents( $failclosed_first['url_batch_run']['run_manifest'] ), true );
$failclosed_identity = (string) ( $failclosed_manifest['source']['identity'] ?? '' );
$failclosed_frozen   = glob( $failclosed_work_dir . '/.ssi-artifact-run-url-' . $failclosed_identity . '/batches/*.json' ) ?: array();
usort( $failclosed_frozen, static fn ( string $a, string $b ): int => strcmp( basename( $a ), basename( $b ) ) );
$failclosed_original = (string) file_get_contents( $failclosed_frozen[0] );
$failclosed_runtime  = json_decode( $failclosed_original, true );
unset( $failclosed_runtime['staged_page_plans'], $failclosed_runtime['shared_plan_digest'] );
file_put_contents( $failclosed_frozen[0], wp_json_encode( $failclosed_runtime ) );
$failclosed_broken = ssi_test_import( $failclosed_request, array(), $failclosed_fetcher, $failclosed_importer );
if ( ! is_wp_error( $failclosed_broken ) || 'static_site_importer_url_plan_batch_missing' !== $failclosed_broken->get_error_code() ) { throw new RuntimeException( 'terminal composition must fail closed when a completed batch lost its frozen staged plans' . ( is_wp_error( $failclosed_broken ) ? ': ' . $failclosed_broken->get_error_code() : '' ) ); }
$failclosed_state = json_decode( (string) file_get_contents( $failclosed_first['url_batch_run']['run_manifest'] ), true );
if ( 'failed' !== ( $failclosed_state['state'] ?? '' ) || ! is_dir( dirname( $failclosed_frozen[0] ) ) ) { throw new RuntimeException( 'a failed terminal composition must mark the run failed and retain the workspace for retry' ); }
file_put_contents( $failclosed_frozen[0], $failclosed_original );
$failclosed_healed = ssi_test_import( $failclosed_request, array(), $failclosed_fetcher, $failclosed_importer );
$healed_pages = array_map( static fn ( array $page ): string => (string) ( $page['path'] ?? $page['slug'] ?? '' ), $failclosed_calls[1]['compiled_artifact_result']['wordpress_site_plan']['pages'] ?? array() );
if ( is_wp_error( $failclosed_healed ) || 'completed' !== ( $failclosed_healed['url_batch_run']['status'] ?? '' ) || 2 !== count( $healed_pages ) ) { throw new RuntimeException( 'restoring frozen staged plans must let the resumed run complete with the whole-site plan: ' . ( is_wp_error( $failclosed_healed ) ? $failclosed_healed->get_error_code() : wp_json_encode( array_keys( $healed_pages ) ) ) ); }
echo "URL batch import smoke passed.\n";
