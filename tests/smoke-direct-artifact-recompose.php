<?php
/** Run: php tests/smoke-direct-artifact-recompose.php */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
define( 'WP_CLI', true );
$test_root = sys_get_temp_dir() . '/ssi-recompose-' . bin2hex( random_bytes( 4 ) );
class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_CLI {
	public static array $commands = array();
	public static function add_command( string $name, $callback ): void { self::$commands[ $name ] = $callback; }
	public static function line( string $line ): void { echo $line . "\n"; }
	public static function error( string $message ): void { throw new RuntimeException( $message ); }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ); }
function wp_upload_dir(): array { return array( 'basedir' => $GLOBALS['test_root'] ); }
function get_current_blog_id(): int { return 17; }
function get_current_user_id(): int { return 827; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000827'; }
function apply_filters( string $hook, $value, ...$args ) { return $value; }
function add_filter( ...$args ): void {}
function add_action( ...$args ): void {}
function do_action( ...$args ): void {}
function __( string $message ): string { return $message; }
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once dirname( __DIR__ ) . '/includes/rest.php';
require_once dirname( __DIR__ ) . '/includes/cli.php';
foreach ( array( 'artifact-run', 'content-policy', 'client-script-policy', 'website-artifact-import-input', 'direct-artifact-import', 'diagnostic-contract', 'canonical-import-service' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $class . '.php';
}
class Static_Site_Importer_Theme_Generator {
	public static function import_website_artifact( array $artifact, array $args = array() ) {
		++$GLOBALS['materializations'];
		$GLOBALS['original'] = $args['compiled_artifact_result'];
		return new WP_Error( 'static_site_importer_quality_gate_failed', 'Injected post-compose quality failure.' );
	}
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$GLOBALS['materializations'] = 0;
$payload = 'retained binary asset';
$reference = array( 'schema' => 'blocks-engine/payload-reference/v1', 'id' => 'image', 'bytes' => strlen( $payload ), 'sha256' => hash( 'sha256', $payload ) );
$reader = new class( $payload ) {
	public function __construct( private string $payload ) {}
	public function read( array $reference ): string { return $this->payload; }
};
$artifact = array(
	'schema' => 'website-artifact/v1',
	'entry_path' => 'index.html',
	'files' => array(
		array( 'path' => 'index.html', 'content' => file_get_contents( __DIR__ . '/fixtures/direct-artifact-multi-page/index.html' ) ),
		array( 'path' => 'about.html', 'content' => file_get_contents( __DIR__ . '/fixtures/direct-artifact-multi-page/about.html' ) ),
		array( 'path' => 'styles.css', 'content' => file_get_contents( __DIR__ . '/fixtures/direct-artifact-multi-page/styles.css' ) ),
	),
);
try {
	$runtime = static_site_importer_source_runtime( array( 'files' => $artifact['files'], 'entrypoint' => 'index.html' ) );
	$normalized = $runtime['artifact'];
	$normalized['files'][] = array( 'path' => 'image.png', 'type' => 'asset', 'mime_type' => 'image/png', 'payload_reference' => $reference );
	$failed = Static_Site_Importer_Direct_Artifact_Import::start( $normalized, array( 'runtime_lifecycle_phase' => 'prepare' ), 'files', 'apply', array(), $reader );
	while ( is_array( $failed ) && ! empty( $failed['continuation'] ) ) {
		$failed = Static_Site_Importer_Direct_Artifact_Import::resume( $failed['import_id'], array(), 'files', 'apply', array() );
	}
	$assert( is_wp_error( $failed ) && 1 === $GLOBALS['materializations'], 'apply must fail after compose: ' . ( is_wp_error( $failed ) ? $failed->get_error_code() . ': ' . $failed->get_error_message() : wp_json_encode( $failed ) ) );
	$id = $failed->get_error_data()['import_id'] ?? '';
	$workspace = new Static_Site_Importer_Artifact_Run_Workspace( Static_Site_Importer_Direct_Artifact_Import::root(), 'direct-' . $id );
	foreach ( array( 'shared-plan.json', 'artifact.json', 'page-plans', 'receipts', 'payloads' ) as $path ) {
		$assert( file_exists( $workspace->path( $path ) ), 'failed run must retain ' . $path );
	}
	$before = $workspace->read_raw( 'run.json' );
	ob_start();
	WP_CLI::$commands['static-site-importer recompose']( array(), array( 'import-id' => $id ) );
	$summary = json_decode( ob_get_clean(), true );
	$expected = Static_Site_Importer_Direct_Artifact_Import::composition_diagnostics_summary( $GLOBALS['original'] );
	$assert( $expected === $summary, 'CLI diagnostics must match original composition' );
	$assert( 1 === $GLOBALS['materializations'] && $before === $workspace->read_raw( 'run.json' ), 'recompose must not mutate the site or run' );
	$output = json_decode( $workspace->read_raw( 'recomposed-result.json' ), true );
	$assert( 'blocks-engine/wordpress-site-plan-view/v2' === $output['schema'], 'default output must be a compact view' );
	$custom = $test_root . '/custom.json';
	$assert( $expected === Static_Site_Importer_Direct_Artifact_Import::recompose( $id, $custom ) && is_file( $custom ), 'custom output must work' );
	$success = Static_Site_Importer_Direct_Artifact_Import::start( $normalized, array(), 'files', 'plan', array(), $reader );
	while ( ! empty( $success['continuation'] ) ) {
		$success = Static_Site_Importer_Direct_Artifact_Import::resume( $success['import_id'], array(), 'files', 'plan', array() );
	}
	$assert( ! empty( $success['success'] ), 'plan run must succeed' );
	$clean = new Static_Site_Importer_Artifact_Run_Workspace( Static_Site_Importer_Direct_Artifact_Import::root(), 'direct-' . $success['import_id'] );
	foreach ( array( 'shared-plan.json', 'artifact.json', 'page-plans', 'receipts' ) as $path ) {
		$assert( ! file_exists( $clean->path( $path ) ), 'successful run must prune ' . $path );
	}
	$missing = Static_Site_Importer_Direct_Artifact_Import::recompose( $success['import_id'] );
	$assert( is_wp_error( $missing ) && 'static_site_importer_recompose_checkpoints_unavailable' === $missing->get_error_code(), 'pruned inputs need a clear error' );
	$projection = Static_Site_Importer_Direct_Artifact_Import::composition_diagnostics_summary( array( 'wordpress_site_plan' => array( 'diagnostics' => array( array( 'code' => 'wordpress_site_plan_content_lost', 'source_path' => 'about.html' ), array( 'code' => 'warning' ), array( 'code' => 'warning' ) ) ) ) );
	$assert( 2 === $projection['counts_by_code']['warning'] && 'about.html' === $projection['content_loss'][0]['source_path'], 'summary counts codes and carries content-loss paths' );
	echo "Direct artifact recompose smoke passed.\n";
} finally {
	if ( is_dir( $test_root ) ) {
		$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $test_root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $walk as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $test_root );
	}
}
