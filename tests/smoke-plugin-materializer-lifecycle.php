<?php
/**
 * Smoke coverage for dependency activation after WordPress lifecycle hooks fired.
 *
 * @package StaticSiteImporter
 */

$tmp = sys_get_temp_dir() . '/ssi-plugin-lifecycle-' . getmypid();
define( 'ABSPATH', $tmp . '/wordpress/' );
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
define( 'WP_CLI', true );
mkdir( WP_PLUGIN_DIR . '/late-plugin', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/late-plugin/late-plugin.php', "<?php\n" );

class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
class Plugin_Upgrader {
	public function __construct( mixed $skin = null ) {}
	public function install( string $download_link ): bool|WP_Error {
		++$GLOBALS['ssi_install_attempts'];
		if ( 'install_failure' === $GLOBALS['ssi_install_outcome'] ) {
			return new WP_Error( 'install_failed', 'Plugin installation failed.' );
		}
		mkdir( WP_PLUGIN_DIR . '/absent-plugin', 0777, true );
		file_put_contents( WP_PLUGIN_DIR . '/absent-plugin/absent-plugin.php', "<?php\n" );
		return true;
	}
}
class Automatic_Upgrader_Skin {}
class WP_CLI {
	public static array $commands = array();
	public static function runcommand( string $command, array $options ): mixed {
		self::$commands[] = array( 'command' => $command, 'options' => $options );
		if ( empty( $options['launch'] ) ) {
			return 1;
		}
		++$GLOBALS['ssi_install_attempts'];
		if ( 'install_failure' === $GLOBALS['ssi_install_outcome'] ) {
			return 1;
		}
		mkdir( WP_PLUGIN_DIR . '/absent-plugin', 0777, true );
		file_put_contents( WP_PLUGIN_DIR . '/absent-plugin/absent-plugin.php', "<?php\n" );
		// A launched WP-CLI install cannot poison this process's plugin scan.
		$GLOBALS['ssi_plugin_entrypoint_discoverable'] = true;
		return 0;
	}
}
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
$GLOBALS['ssi_activation_outcome'] = 'success';
$GLOBALS['ssi_activation_attempts'] = 0;
$GLOBALS['ssi_install_attempts'] = 0;
$GLOBALS['ssi_install_outcome'] = 'success';
$GLOBALS['ssi_plugin_cache_cleans'] = 0;
$GLOBALS['ssi_plugin_entrypoint_discoverable'] = true;
$GLOBALS['ssi_preparation_calls'] = 0;

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
function plugins_api( string $action, array $args ): object {
	unset( $action, $args );
	return (object) array( 'download_link' => 'https://example.test/absent-plugin.zip' );
}
function wp_clean_plugins_cache( bool $clear_update_cache = true ): void {
	unset( $clear_update_cache );
	++$GLOBALS['ssi_plugin_cache_cleans'];
}
function activate_plugin( string $plugin_file ) {
	unset( $plugin_file );
	++$GLOBALS['ssi_activation_attempts'];
	if ( ! $GLOBALS['ssi_plugin_entrypoint_discoverable'] ) {
		return new WP_Error( 'no_plugin_header', 'The plugin does not have a valid header.' );
	}
	if ( 'throw_without_state_change' !== $GLOBALS['ssi_activation_outcome'] ) {
		$GLOBALS['ssi_plugin_active'] = true;
	}
	if ( 'throw_after_state_change' === $GLOBALS['ssi_activation_outcome'] || 'throw_without_state_change' === $GLOBALS['ssi_activation_outcome'] ) {
		throw new RuntimeException( 'Activation callback threw.' );
	}
	if ( 'error_after_state_change' === $GLOBALS['ssi_activation_outcome'] ) {
		return new WP_Error( 'activation_callback_failed', 'Activation callback failed after persistent activation.' );
	}
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
		++$GLOBALS['ssi_preparation_calls'];
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
$assert( 0 === $GLOBALS['ssi_install_attempts'], 'installed-inactive-does-not-install' );

$GLOBALS['ssi_activation_attempts'] = 0;
$GLOBALS['ssi_plugin_active'] = true;
$active_report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'late-plugin',
	'late-plugin/late-plugin.php',
	static fn (): bool => true
);
$assert( 'already_available' === ( $active_report['status'] ?? '' ) && 0 === $GLOBALS['ssi_activation_attempts'], 'active-provider-is-not-reactivated' );

$GLOBALS['ssi_plugin_active'] = false;
$GLOBALS['ssi_activation_outcome'] = 'error_after_state_change';
$activation_error_report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'late-plugin',
	'late-plugin/late-plugin.php',
	static fn (): bool => false,
	static fn (): bool => true
);
$assert( 'activated_pending_fresh_runtime' === ( $activation_error_report['status'] ?? '' ), 'activation-side-effect-reconciled' );
$assert( true === ( $activation_error_report['active'] ?? false ) && in_array( 'reconciled_activated', $activation_error_report['actions'] ?? array(), true ), 'activation-reconciliation-evidence' );

$GLOBALS['ssi_plugin_active'] = false;
$GLOBALS['ssi_activation_outcome'] = 'throw_after_state_change';
$activation_throw_report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'late-plugin',
	'late-plugin/late-plugin.php',
	static fn (): bool => false,
	static fn (): bool => true
);
$assert( 'activated_pending_fresh_runtime' === ( $activation_throw_report['status'] ?? '' ) && true === ( $activation_throw_report['active'] ?? false ) && in_array( 'reconciled_activated', $activation_throw_report['actions'] ?? array(), true ), 'thrown-activation-side-effect-reconciled' );

$GLOBALS['ssi_plugin_active'] = false;
$GLOBALS['ssi_activation_outcome'] = 'success';
$preparation_pending_report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'late-plugin',
	'late-plugin/late-plugin.php',
	static fn (): bool => false,
	static fn () => new WP_Error( 'late_plugin_init_pending', 'Initialization requires a fresh request.' )
);
$assert( 'activated_pending_fresh_runtime' === ( $preparation_pending_report['status'] ?? '' ) && true === ( $preparation_pending_report['active'] ?? false ) && in_array( 'activated', $preparation_pending_report['actions'] ?? array(), true ), 'activation-preparation-pending-fresh-runtime' );

$GLOBALS['ssi_plugin_active'] = false;
$GLOBALS['ssi_activation_outcome'] = 'throw_without_state_change';
$activation_throw_failure = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'late-plugin',
	'late-plugin/late-plugin.php',
	static fn (): bool => false,
	static fn (): bool => true
);
$assert( 'failed' === ( $activation_throw_failure['status'] ?? '' ) && 'static_site_importer_plugin_activation_failed' === ( $activation_throw_failure['error']['code'] ?? '' ), 'genuine-thrown-activation-failure-rejected' );
$assert( 'late-plugin' === ( $activation_throw_failure['slug'] ?? '' ) && in_array( 'activate', $activation_throw_failure['attempted_actions'] ?? array(), true ) && false !== strpos( (string) ( $activation_throw_failure['error']['message'] ?? '' ), 'Activation callback threw.' ), 'activation-failure-evidence' );

unlink( WP_PLUGIN_DIR . '/late-plugin/late-plugin.php' );
rmdir( WP_PLUGIN_DIR . '/late-plugin' );
$GLOBALS['ssi_plugin_active'] = false;
$GLOBALS['ssi_activation_outcome'] = 'success';
$GLOBALS['ssi_plugin_entrypoint_discoverable'] = false;
$GLOBALS['ssi_prepared'] = false;
$GLOBALS['ssi_preparation_calls'] = 0;
$absent_report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'absent-plugin',
	'absent-plugin/absent-plugin.php',
	static fn (): bool => $GLOBALS['ssi_plugin_active'],
	static function (): bool {
		$GLOBALS['ssi_prepared'] = true;
		++$GLOBALS['ssi_preparation_calls'];
		return true;
	}
);
$assert( 'installed_activated' === ( $absent_report['status'] ?? '' ) && 1 === $GLOBALS['ssi_install_attempts'] && in_array( 'install', $absent_report['attempted_actions'] ?? array(), true ) && in_array( 'activate', $absent_report['attempted_actions'] ?? array(), true ), 'absent-provider-installs-then-activates' );
$assert( 1 === $GLOBALS['ssi_plugin_cache_cleans'], 'newly-installed-provider-refreshes-plugin-cache-before-activation' );
$assert( true === ( WP_CLI::$commands[0]['options']['launch'] ?? false ), 'wp-cli-install-launches-in-child-process' );
$assert( true === $GLOBALS['ssi_plugin_entrypoint_discoverable'], 'launched-install-makes-fresh-entrypoint-discoverable' );
$assert( true === ( $absent_report['active'] ?? false ), 'fresh-entrypoint-activates-in-same-execution' );
$assert( 1 === $GLOBALS['ssi_preparation_calls'] && in_array( 'prepare_runtime', $absent_report['attempted_actions'] ?? array(), true ), 'fresh-entrypoint-prepares-in-same-execution' );

unlink( WP_PLUGIN_DIR . '/absent-plugin/absent-plugin.php' );
rmdir( WP_PLUGIN_DIR . '/absent-plugin' );
$GLOBALS['ssi_install_outcome'] = 'install_failure';
$install_failure_report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
	'absent-plugin',
	'absent-plugin/absent-plugin.php',
	static fn (): bool => false
);
$assert( 'failed' === ( $install_failure_report['status'] ?? '' ) && 'absent-plugin' === ( $install_failure_report['slug'] ?? '' ) && in_array( 'install', $install_failure_report['attempted_actions'] ?? array(), true ) && 'static_site_importer_plugin_install_failed' === ( $install_failure_report['error']['code'] ?? '' ), 'installation-failure-evidence' );

rmdir( WP_PLUGIN_DIR );
rmdir( $tmp );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failures ) . "\n" );
	exit( 1 );
}

echo "Plugin materializer lifecycle smoke test passed.\n";
