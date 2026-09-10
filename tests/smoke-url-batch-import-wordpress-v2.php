<?php
/** Candidate-only SSI-1354 proof. Run in WordPress with a BE compact-view candidate. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$root = dirname( __DIR__ );
require_once $root . '/static-site-importer.php';
wp_set_current_user( 1 );
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;

if ( ! method_exists( WordPressSitePlanView::class, 'compact' ) || ! method_exists( WordPressSitePlanView::class, 'materialize' ) ) {
	echo "SKIP: compact WordPress site plan views require a Blocks Engine candidate.\n";
	return;
}
$token = sanitize_key( wp_generate_password( 10, false, false ) );
$origin = 'https://ssi1354-' . $token . '.test';
$routes = array_map( static fn( int $index ): string => $origin . '/route-' . $index . '/', range( 1, 8 ) );
$binary = str_repeat( 'ssi1354-binary-', 4096 );
$css = '.ssi1354{background:url("shared.txt");color:#1354;}' . str_repeat( '.shared{display:block}', 128 );
$fetcher = static function ( string $url, array $args ) use ( $origin, $routes, $binary, $css ) {
	if ( $origin . '/sitemap.xml' === $url ) return array( 'body' => '<urlset>' . implode( '', array_map( static fn( string $route ): string => '<url><loc>' . $route . '</loc></url>', $routes ) ) . '</urlset>', 'metadata' => array( 'content_type' => 'application/xml', 'final_url' => $url ) );
	if ( $origin . '/shared.css' === $url ) return array( 'body' => $css, 'metadata' => array( 'content_type' => 'text/css', 'final_url' => $url ) );
	if ( $origin . '/shared.txt' === $url ) return array( 'body' => $binary, 'metadata' => array( 'content_type' => 'text/plain', 'final_url' => $url ) );
	if ( in_array( $url, $routes, true ) ) return array( 'body' => '<html><head><link rel="stylesheet" href="/shared.css"></head><body><main class="ssi1354"><a href="/shared.txt">shared</a>' . esc_html( $url ) . '</main></body></html>', 'metadata' => array( 'content_type' => 'text/html', 'final_url' => $url ) );
	return new WP_Error( 'fixture_missing', $url );
};
$run = static function ( string $schema ) use ( $token, $origin, $fetcher ): array {
	$slug = 'ssi1354-' . $token;
	$work_dir = wp_upload_dir()['basedir'] . '/ssi1354-' . $schema . '-' . wp_generate_uuid4();
	$checkpoint = $work_dir . '/checkpoint-' . $schema . '.json'; $calls = 0; $started = hrtime( true );
	$importer = static function ( array $artifact, array $args ) use ( $schema, $checkpoint, &$calls ) {
		$view = $args['compiled_artifact_result'];
		if ( 'v1' === $schema ) $view = WordPressSitePlanView::materialize( $view );
		wp_mkdir_p( dirname( $checkpoint ) ); file_put_contents( $checkpoint, wp_json_encode( $view, JSON_THROW_ON_ERROR ) );
		$view = json_decode( (string) file_get_contents( $checkpoint ), true, 512, JSON_THROW_ON_ERROR );
		if ( 4 === $calls++ ) return new WP_Error( 'ssi1354_interrupted', 'Controlled checkpoint interruption.' );
		$args['compiled_artifact_result'] = $view;
		return Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
	};
	$adapter = new class( $importer ) implements Static_Site_Importer_Final_Hydration_Adapter {
		public function __construct( private $importer ) {}
		public function id(): string { return 'ssi1354/checkpoint-proof'; }
		public function contract_version(): int { return 1; }
		public function implementation_version(): string { return '1'; }
		public function capabilities(): array { return array( 'verify_result', 'reconcile_verified_result' ); }
		public function apply( array $artifact, array $args ) { return call_user_func( $this->importer, $artifact, $args ); }
		public function reconcile( array $receipt, array $artifact, array $args ) { $result = $receipt['effect']['result'] ?? null; return is_array( $result ) && $this->verify( $result, $artifact, $args ) ? $result : new WP_Error( 'static_site_importer_final_effect_reconciliation_unavailable', 'Checkpoint proof result is unavailable.' ); }
		public function verify( array $result, array $artifact, array $args ): bool { return isset( $result['materialization_receipt'] ) && is_array( $result['materialization_receipt'] ); }
	};
	$request = array( 'url' => $origin . '/route-1/', 'work_dir' => $work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 1, 'request_delay_ms' => 0, 'max_assets' => 4 ) );
	$first = Static_Site_Importer_URL_Batch_Import::import( $request, array( 'slug' => $slug, 'name' => 'SSI-1354', 'activate' => false, 'overwrite' => true ), $fetcher, null, $adapter );
	if ( ! is_wp_error( $first ) || 'ssi1354_interrupted' !== $first->get_error_code() || ! is_file( $checkpoint ) ) {
		throw new RuntimeException( 'SSI-1354 did not reach the persisted ' . $schema . ' checkpoint: ' . ( is_wp_error( $first ) ? $first->get_error_code() . ' ' . $first->get_error_message() : wp_json_encode( $first ) ) );
	}
	$run_id = (string) ( $first->get_error_data()['run']['source']['identity'] ?? '' );
	if ( '' === $run_id ) throw new RuntimeException( 'SSI-1354 checkpoint interruption did not retain its run identity.' );
	$resume_started = hrtime( true );
	$resumed = Static_Site_Importer_URL_Batch_Import::import( $request, array( 'slug' => $slug, 'name' => 'SSI-1354', 'activate' => false, 'overwrite' => true ), $fetcher, null, $adapter );
	$view = json_decode( (string) file_get_contents( $checkpoint ), true, 512, JSON_THROW_ON_ERROR );
	$canonical = WordPressSitePlanView::materialize( $view );
	$pages = array_filter( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_static_site_importer_provenance' ) ), static fn( WP_Post $post ): bool => $run_id === (string) ( json_decode( (string) get_post_meta( $post->ID, '_static_site_importer_provenance', true ), true )['import_run_id'] ?? '' ) );
	$state = array_map( static fn( WP_Post $post ): array => array( 'content_sha256' => hash( 'sha256', $post->post_content ) ), $pages );
	sort( $state );
	if ( is_wp_error( $resumed ) || 'completed' !== ( $resumed['url_batch_run']['status'] ?? '' ) ) throw new RuntimeException( 'SSI-1354 checkpoint must reload and resume actual WordPress materialization.' );
	return array( 'schema' => $view['schema'] ?? '', 'persisted_bytes' => filesize( $checkpoint ), 'resume_seconds' => round( ( hrtime( true ) - $resume_started ) / 1e9, 4 ), 'duration_seconds' => round( ( hrtime( true ) - $started ) / 1e9, 4 ), 'canonical_hash' => WordPressSitePlan::planIdentity( $canonical['wordpress_site_plan'] ), 'wordpress_state_hash' => hash( 'sha256', wp_json_encode( $state ) ) );
};
$v1 = $run( 'v1' ); $v2 = $run( 'v2' );
if ( 'blocks-engine/wordpress-site-plan-view/v1' !== $v1['schema'] || 'blocks-engine/wordpress-site-plan-view/v2' !== $v2['schema'] || $v1['canonical_hash'] !== $v2['canonical_hash'] || $v1['wordpress_state_hash'] !== $v2['wordpress_state_hash'] || $v2['persisted_bytes'] >= $v1['persisted_bytes'] ) throw new RuntimeException( 'SSI-1354 compact checkpoint proof did not preserve the v1 materialization result.' );
echo 'SSI-1354 checkpoint proof: ' . wp_json_encode( array( 'v1_before' => $v1, 'v2_after' => $v2 ) ) . "\n";
