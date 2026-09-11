<?php
/**
 * Real-process regression coverage for WP-CLI v2.12.0 child process handling.
 *
 * Provision the pinned public PHAR once, outside this test, then run:
 * STATIC_SITE_IMPORTER_WP_CLI_2_12_0_PHAR=/path/to/wp-cli-2.12.0.phar php tests/smoke-plugin-materializer-wp-cli-process.php
 *
 * Public fixture URL: https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
 * The test never downloads a PHAR. It creates and removes an isolated temporary
 * HOME, config, cache, packages directory, and disposable WP-CLI bootstrap.
 *
 * @package StaticSiteImporter
 */

$phar = getenv( 'STATIC_SITE_IMPORTER_WP_CLI_2_12_0_PHAR' );
if ( ! is_string( $phar ) || ! is_file( $phar ) ) {
	fwrite( STDERR, "SKIP: Set STATIC_SITE_IMPORTER_WP_CLI_2_12_0_PHAR to the locally provisioned v2.12.0 PHAR from https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar.\n" );
	exit( 0 );
}

$version = array();
$version_status = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $phar ) . ' --version 2>&1', $version, $version_status );
if ( 0 !== $version_status || ! str_contains( implode( "\n", $version ), 'WP-CLI 2.12.0' ) ) {
	fwrite( STDERR, "FAIL: STATIC_SITE_IMPORTER_WP_CLI_2_12_0_PHAR must be a WP-CLI v2.12.0 PHAR.\n" );
	exit( 1 );
}

$tmp = sys_get_temp_dir() . '/ssi-wp-cli-process-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $tmp, 0700, true ) && ! is_dir( $tmp ) ) {
	throw new RuntimeException( 'Unable to create temporary WP-CLI process fixture directory.' );
}

$remove_tree = static function ( string $path ) use ( &$remove_tree ): void {
	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		return;
	}
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( scandir( $path ) ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$remove_tree( $path . DIRECTORY_SEPARATOR . $entry );
			}
		}
		rmdir( $path );
		return;
	}
	unlink( $path );
};

try {
	$bootstrap = $tmp . '/bootstrap.php';
	$marker    = $tmp . '/child-output.log';
	$sentinel  = $tmp . '/after-invalid-option.log';
	$source    = dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';
	$bootstrap_source = <<<'PHP'
<?php

class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
class Plugin_Upgrader {}
class Automatic_Upgrader_Skin {}

$GLOBALS['ssi_process_plugin_active'] = false;
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_clean_plugins_cache( bool $clear_update_cache = true ): void { unset( $clear_update_cache ); }
function is_plugin_active( string $plugin_file ): bool { unset( $plugin_file ); return $GLOBALS['ssi_process_plugin_active']; }
function activate_plugin( string $plugin_file ): null { unset( $plugin_file ); $GLOBALS['ssi_process_plugin_active'] = true; return null; }

$arguments = array_values( array_slice( $GLOBALS['argv'], 1 ) );
foreach ( $arguments as $offset => $argument ) {
	if ( 'plugin' === $argument && 'install' === ( $arguments[ $offset + 1 ] ?? '' ) ) {
		WP_CLI::line( 'controlled child output' );
		file_put_contents( %MARKER%, (string) getenv( 'SSI_WP_CLI_PROCESS_OUTCOME' ) . "\n", FILE_APPEND | LOCK_EX );
		WP_CLI::halt( 'failure' === getenv( 'SSI_WP_CLI_PROCESS_OUTCOME' ) ? 23 : 0 );
	}
}

WP_CLI::add_command(
	'ssi-process materialize',
	static function (): void {
		define( 'WP_PLUGIN_DIR', %PLUGIN_DIR% );
		require_once %SOURCE%;
		$report = Static_Site_Importer_Plugin_Materializer::ensure_wp_org_plugin(
			'controlled-plugin',
			'controlled-plugin/controlled-plugin.php',
			static fn (): bool => $GLOBALS['ssi_process_plugin_active']
		);
		WP_CLI::line( json_encode( $report, JSON_THROW_ON_ERROR ) );
	},
	array( 'when' => 'before_wp_load' )
);

WP_CLI::add_command(
	'ssi-process invalid-option',
	static function (): void {
		WP_CLI::runcommand( 'plugin install controlled-plugin', array( 'launch' => true, 'return' => 'return_code', 'exit_on_error' => false ) );
		file_put_contents( %SENTINEL%, "after-call\n" );
	},
	array( 'when' => 'before_wp_load' )
);
PHP;
	$bootstrap_source = strtr(
		$bootstrap_source,
		array(
			'%PLUGIN_DIR%' => var_export( $tmp . '/plugins', true ),
			'%SOURCE%'     => var_export( $source, true ),
			'%MARKER%'     => var_export( $marker, true ),
			'%SENTINEL%'   => var_export( $sentinel, true ),
		)
	);
	file_put_contents( $bootstrap, $bootstrap_source );
	file_put_contents( $tmp . '/config.yml', 'require: ' . $bootstrap . "\n" );

	$environment = array(
		'HOME'                => $tmp . '/home',
		'PATH'                => getenv( 'PATH' ) ?: '',
		'WP_CLI_CACHE_DIR'    => $tmp . '/cache',
		'WP_CLI_CONFIG_PATH'  => $tmp . '/config.yml',
		'WP_CLI_PACKAGES_DIR' => $tmp . '/packages',
	);
	$run = static function ( string $command, string $outcome ) use ( $phar, $tmp, $environment ): array {
		$command_line = escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg( $phar ) . ' --quiet ' . $command;
		$pipes        = array();
		$process      = proc_open( $command_line, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $tmp, array_merge( $environment, array( 'SSI_WP_CLI_PROCESS_OUTCOME' => $outcome ) ) );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Unable to start disposable WP-CLI parent process.' );
		}
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		return array( 'status' => proc_close( $process ), 'stdout' => $stdout, 'stderr' => $stderr );
	};

	$failure = $run( 'ssi-process materialize', 'failure' );
	$failure_report = json_decode( trim( $failure['stdout'] ), true );
	$marker_output = is_file( $marker ) ? file_get_contents( $marker ) : '';
	if ( 0 !== $failure['status'] || 'failed' !== ( $failure_report['status'] ?? '' ) || 'static_site_importer_plugin_install_failed' !== ( $failure_report['error']['code'] ?? '' ) || ! str_contains( (string) ( $failure_report['error']['message'] ?? '' ), 'controlled-plugin' ) || "failure\n" !== $marker_output ) {
		throw new RuntimeException( 'A nonzero WP-CLI child must return to the materializer and produce structured install diagnostics.' );
	}

	$success = $run( 'ssi-process materialize', 'success' );
	$success_report = json_decode( trim( $success['stdout'] ), true );
	if ( 0 !== $success['status'] || 'installed_activated' !== ( $success_report['status'] ?? '' ) || "failure\nsuccess\n" !== file_get_contents( $marker ) ) {
		throw new RuntimeException( 'A successful WP-CLI child with output must preserve plugin installation success.' );
	}

	$invalid = $run( 'ssi-process invalid-option', 'failure' );
	if ( 23 !== $invalid['status'] || file_exists( $sentinel ) ) {
		throw new RuntimeException( 'The invalid exit_on_error option must terminate the sacrificial parent before its after-call sentinel.' );
	}
} finally {
	$remove_tree( $tmp );
}

echo "Plugin materializer WP-CLI process smoke test passed.\n";
