<?php
/**
 * Smoke coverage for dependency activation after WordPress lifecycle hooks fired.
 *
 * @package StaticSiteImporter
 */

$tmp = sys_get_temp_dir() . '/ssi-plugin-lifecycle-' . getmypid();
define( 'ABSPATH', $tmp . '/wordpress/' );
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
mkdir( WP_PLUGIN_DIR . '/late-plugin', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/late-plugin/late-plugin.php', "<?php\n" );

class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
class Plugin_Upgrader {}
class Automatic_Upgrader_Skin {}
class SSI_Test_Hook {
	public array $callbacks = array();
}

$GLOBALS['wp_filter']         = array();
$GLOBALS['wp_actions']        = array( 'plugins_loaded' => 1, 'init' => 1, 'wp_loaded' => 1 );
$GLOBALS['wp_current_filter'] = array();
$GLOBALS['ssi_plugin_active'] = false;
$GLOBALS['ssi_runtime_ready'] = false;
$GLOBALS['ssi_replay_order']  = array();
$GLOBALS['ssi_existing_runs'] = 0;
$GLOBALS['ssi_prepared']      = false;

function ssi_test_callback_id( callable $callback ): string {
	if ( is_string( $callback ) ) return $callback;
	if ( is_array( $callback ) ) return ( is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0] ) . '::' . $callback[1];
	return spl_object_hash( $callback );
}
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['wp_filter'][ $hook ] ??= new SSI_Test_Hook();
	$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ ssi_test_callback_id( $callback ) ] = array( 'function' => $callback, 'accepted_args' => $accepted_args );
	return true;
}
function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
	$id = ssi_test_callback_id( $callback );
	if ( ! isset( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ $id ] ) ) return false;
	unset( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ $id ] );
	return true;
}
function did_action( string $hook ): int { return (int) ( $GLOBALS['wp_actions'][ $hook ] ?? 0 ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function is_plugin_active( string $plugin_file ): bool { return $GLOBALS['ssi_plugin_active']; }
function activate_plugin( string $plugin_file ) {
	unset( $plugin_file );
	$GLOBALS['ssi_plugin_active'] = true;
	add_action(
		'plugins_loaded',
		static function (): void {
			$GLOBALS['ssi_replay_order'][] = 'plugins_loaded';
			add_action(
				'init',
				static function (): void {
					$GLOBALS['ssi_replay_order'][]  = 'init';
					$GLOBALS['ssi_runtime_ready'] = true;
				}
			);
		}
	);
	return null;
}

add_action( 'plugins_loaded', static function (): void { ++$GLOBALS['ssi_existing_runs']; } );
add_action( 'init', static function (): void { ++$GLOBALS['ssi_existing_runs']; } );

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';

$report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'late-plugin',
	'late-plugin/late-plugin.php',
	static fn (): bool => $GLOBALS['ssi_runtime_ready'],
	static function (): bool {
		$GLOBALS['ssi_prepared'] = true;
		return true;
	}
);

$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) $failures[] = $label;
};
$assert( 'activated' === ( $report['status'] ?? '' ), 'dependency-activated' );
$assert( true === $GLOBALS['ssi_runtime_ready'], 'late-runtime-became-available' );
$assert( true === $GLOBALS['ssi_prepared'], 'runtime-preparation-ran' );
$assert( array( 'plugins_loaded', 'init' ) === $GLOBALS['ssi_replay_order'], 'lifecycle-replayed-in-order' );
$assert( 0 === $GLOBALS['ssi_existing_runs'], 'existing-callbacks-not-replayed' );
$assert( 1 === did_action( 'plugins_loaded' ) && 1 === did_action( 'init' ) && 1 === did_action( 'wp_loaded' ), 'action-counts-restored' );
$assert( 1 === ( $report['lifecycle_replay']['plugins_loaded'] ?? 0 ), 'plugins-loaded-replay-reported' );
$assert( 1 === ( $report['lifecycle_replay']['init'] ?? 0 ), 'init-replay-reported' );

unlink( WP_PLUGIN_DIR . '/late-plugin/late-plugin.php' );
rmdir( WP_PLUGIN_DIR . '/late-plugin' );
rmdir( WP_PLUGIN_DIR );
rmdir( $tmp );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failures ) . "\n" );
	exit( 1 );
}

echo "Plugin materializer lifecycle smoke test passed.\n";
