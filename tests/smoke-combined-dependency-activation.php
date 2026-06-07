<?php
/**
 * Smoke coverage for SSI loading alongside standalone and bundled BFB/H2BC copies.
 *
 * Runs each load-order scenario in a fresh PHP subprocess so global classes,
 * functions, constants, and static registries cannot leak between cases.
 *
 * @package StaticSiteImporter
 */

declare(strict_types=1);

$scenario_matrix = array(
	'ssi-only'                    => array( 'ssi' ),
	'h2bc-then-ssi'               => array( 'standalone-h2bc', 'ssi' ),
	'bfb-then-ssi'                => array( 'standalone-bfb', 'ssi' ),
	'bfb-with-h2bc-then-ssi'      => array( 'standalone-bfb-with-h2bc', 'ssi' ),
	'h2bc-bfb-ssi'                => array( 'standalone-h2bc', 'standalone-bfb-with-h2bc', 'ssi' ),
	'ssi-then-h2bc-bfb'           => array( 'ssi', 'standalone-h2bc', 'standalone-bfb-with-h2bc' ),
	'two-bfb-consumers-then-ssi'  => array( 'consumer-bfb-a', 'consumer-bfb-b', 'ssi' ),
	'two-h2bc-consumers-then-ssi' => array( 'consumer-h2bc-a', 'consumer-h2bc-b', 'ssi' ),
	'all-consumers-before-ssi'    => array( 'standalone-h2bc', 'standalone-bfb-with-h2bc', 'consumer-bfb-a', 'consumer-h2bc-a', 'consumer-bfb-b', 'ssi' ),
	'ssi-between-consumers'       => array( 'consumer-bfb-a', 'consumer-h2bc-a', 'ssi', 'standalone-bfb-with-h2bc', 'consumer-bfb-b' ),
	'all-consumers-after-ssi'     => array( 'ssi', 'standalone-bfb-with-h2bc', 'consumer-bfb-a', 'consumer-h2bc-a', 'consumer-bfb-b' ),
);

if ( ! getenv( 'SSI_COMBINED_DEPENDENCY_SMOKE_CHILD' ) ) {
	$failures = array();

	foreach ( array_keys( $scenario_matrix ) as $scenario_name ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ );
		$env     = array_merge(
			$_ENV,
			array(
				'SSI_COMBINED_DEPENDENCY_SMOKE_CHILD'    => '1',
				'SSI_COMBINED_DEPENDENCY_SMOKE_SCENARIO' => $scenario_name,
			)
		);
		$spec    = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $command, $spec, $pipes, dirname( __DIR__ ), $env );

		if ( ! is_resource( $process ) ) {
			$failures[] = "{$scenario_name}: failed to start child process";
			continue;
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$status = proc_close( $process );
		if ( 0 !== $status ) {
			$failures[] = trim( "{$scenario_name}: exit {$status}\n{$stdout}\n{$stderr}" );
			continue;
		}

		echo trim( (string) $stdout ) . "\n";
	}

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, "FAIL: combined dependency activation matrix failed\n" . implode( "\n---\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: combined dependency activation matrix passed (' . count( $scenario_matrix ) . " scenarios)\n";
	exit( 0 );
}

$scenario_name = (string) getenv( 'SSI_COMBINED_DEPENDENCY_SMOKE_SCENARIO' );
if ( ! isset( $scenario_matrix[ $scenario_name ] ) ) {
	fwrite( STDERR, "FAIL: unknown scenario {$scenario_name}\n" );
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['ssi_smoke_filters']        = array();
$GLOBALS['ssi_smoke_actions_done']   = array();
$GLOBALS['ssi_smoke_actions_active'] = array();
$GLOBALS['ssi_smoke_bfb_loaded']     = array();
$GLOBALS['ssi_smoke_h2bc_loaded']    = array();

function ssi_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ssi_smoke_filters'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function has_filter( string $hook_name, $callback = false ) {
	if ( ! isset( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ] as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return $priority;
			}
		}
	}

	return false;
}

function has_action( string $hook_name, $callback = false ) {
	return has_filter( $hook_name, $callback );
}

function do_action( string $hook_name, ...$args ): void {
	$GLOBALS['ssi_smoke_actions_done'][ $hook_name ]   = ( $GLOBALS['ssi_smoke_actions_done'][ $hook_name ] ?? 0 ) + 1;
	$GLOBALS['ssi_smoke_actions_active'][ $hook_name ] = ( $GLOBALS['ssi_smoke_actions_active'][ $hook_name ] ?? 0 ) + 1;

	try {
		$priorities = array_keys( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ?? array() );
		sort( $priorities, SORT_NUMERIC );

		foreach ( $priorities as $priority ) {
			foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ][ $priority ] as $entry ) {
				if ( is_callable( $entry['callback'] ) ) {
					call_user_func_array( $entry['callback'], array_slice( $args, 0, $entry['accepted_args'] ) );
				}
			}
		}
	} finally {
		--$GLOBALS['ssi_smoke_actions_active'][ $hook_name ];
	}
}

function did_action( string $hook_name ): int {
	return (int) ( $GLOBALS['ssi_smoke_actions_done'][ $hook_name ] ?? 0 );
}

function doing_action( ?string $hook_name = null ): bool {
	if ( null === $hook_name ) {
		return ! empty( array_filter( $GLOBALS['ssi_smoke_actions_active'] ) );
	}

	return ! empty( $GLOBALS['ssi_smoke_actions_active'][ $hook_name ] );
}

function apply_filters( string $hook_name, $value, ...$args ) {
	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ?? array() as $entries ) {
		foreach ( $entries as $entry ) {
			if ( is_callable( $entry['callback'] ) ) {
				$value = call_user_func_array( $entry['callback'], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry['accepted_args'] - 1 ) ) ) );
			}
		}
	}

	return $value;
}

function plugin_dir_path( string $file ): string {
	return trailingslashit( dirname( $file ) );
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function ssi_smoke_remove_path( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}

	$entries = scandir( $path );
	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		ssi_smoke_remove_path( $path . '/' . $entry );
	}

	rmdir( $path );
}

function ssi_smoke_copy_path( string $source, string $destination ): void {
	if ( is_dir( $source ) ) {
		if ( ! is_dir( $destination ) ) {
			mkdir( $destination, 0777, true );
		}

		$entries = scandir( $source );
		ssi_smoke_assert( is_array( $entries ), "Should read {$source}." );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.git' === $entry ) {
				continue;
			}

			ssi_smoke_copy_path( $source . '/' . $entry, $destination . '/' . $entry );
		}

		return;
	}

	ssi_smoke_assert( copy( $source, $destination ), "Should copy {$source}." );
}

function ssi_smoke_hook_count( string $hook_name, $callback ): int {
	$count = 0;

	foreach ( $GLOBALS['ssi_smoke_filters'][ $hook_name ] ?? array() as $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				++$count;
			}
		}
	}

	return $count;
}

function ssi_smoke_component_contains_bfb( string $component ): bool {
	return 'ssi' === $component || str_contains( $component, 'bfb' );
}

function ssi_smoke_component_contains_h2bc( string $component ): bool {
	return 'ssi' === $component || str_contains( $component, 'h2bc' ) || str_contains( $component, 'bfb' );
}

function ssi_smoke_last_matching_component( array $components, callable $predicate ): ?string {
	$winner = null;

	foreach ( $components as $component ) {
		if ( $predicate( $component ) ) {
			$winner = $component;
		}
	}

	return $winner;
}

add_action(
	'bfb_loaded',
	static function ( string $version ): void {
		$GLOBALS['ssi_smoke_bfb_loaded'][] = $version;
	},
	10,
	1
);
add_action(
	'html_to_blocks_loaded',
	static function ( string $version ): void {
		$GLOBALS['ssi_smoke_h2bc_loaded'][] = $version;
	},
	10,
	1
);

$root       = dirname( __DIR__ );
$temp_root  = sys_get_temp_dir() . '/ssi-combined-deps-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$source_bfb = $root . '/vendor/chubes4/block-format-bridge';
$source_h2b = $root . '/vendor/chubes4/html-to-blocks-converter';
$paths      = array(
	'ssi'                       => $root,
	'standalone-h2bc'           => $temp_root . '/standalone-h2bc',
	'standalone-bfb'            => $temp_root . '/standalone-bfb',
	'standalone-bfb-with-h2bc'  => $temp_root . '/standalone-bfb-with-h2bc',
	'consumer-bfb-a'            => $temp_root . '/consumer-a/vendor/chubes4/block-format-bridge',
	'consumer-bfb-b'            => $temp_root . '/consumer-b/vendor/chubes4/block-format-bridge',
	'consumer-h2bc-a'           => $temp_root . '/consumer-h2bc-a/vendor/chubes4/html-to-blocks-converter',
	'consumer-h2bc-b'           => $temp_root . '/consumer-h2bc-b/vendor/chubes4/html-to-blocks-converter',
	'consumer-bfb-a-h2bc'       => $temp_root . '/consumer-a/vendor/chubes4/html-to-blocks-converter',
	'consumer-bfb-b-h2bc'       => $temp_root . '/consumer-b/vendor/chubes4/html-to-blocks-converter',
	'standalone-bfb-h2bc'       => $temp_root . '/standalone-bfb-h2bc',
	'standalone-bfb-with-h2bc-h2bc' => $temp_root . '/standalone-bfb-with-h2bc-h2bc',
);
$duplicate_warnings = array();
$loaded_components  = $scenario_matrix[ $scenario_name ];

try {
	foreach ( $paths as $component => $path ) {
		if ( 'ssi' === $component ) {
			continue;
		}

		if ( in_array( $component, array( 'standalone-bfb', 'standalone-bfb-with-h2bc', 'consumer-bfb-a', 'consumer-bfb-b' ), true ) ) {
			ssi_smoke_copy_path( $source_bfb, $path );
		} elseif ( str_contains( $component, 'h2bc' ) ) {
			ssi_smoke_copy_path( $source_h2b, $path );
		}
	}

	foreach ( array( 'standalone-bfb-with-h2bc', 'consumer-bfb-a', 'consumer-bfb-b' ) as $bfb_component ) {
		$h2bc_component = $bfb_component . '-h2bc';
		if ( 'standalone-bfb-with-h2bc-h2bc' === $h2bc_component ) {
			$h2bc_component = 'standalone-bfb-with-h2bc-h2bc';
		}

		$h2bc_path = $paths[ $h2bc_component ] ?? $paths[ 'standalone-bfb-h2bc' ];
		mkdir( $paths[ $bfb_component ] . '/vendor', 0777, true );
		file_put_contents(
			$paths[ $bfb_component ] . '/vendor/autoload.php',
			"<?php\nrequire_once " . var_export( $h2bc_path . '/library.php', true ) . ";\n"
		);
	}

	set_error_handler(
		static function ( int $errno, string $errstr ) use ( &$duplicate_warnings ): bool {
			if ( E_USER_WARNING === $errno && str_contains( $errstr, 'Block Format Bridge version' ) && str_contains( $errstr, 'registered by multiple sources' ) ) {
				$duplicate_warnings[] = $errstr;
				return true;
			}

			return false;
		}
	);

	foreach ( $loaded_components as $component ) {
		if ( 'ssi' === $component ) {
			require $root . '/static-site-importer.php';
		} elseif ( isset( $paths[ $component ] ) ) {
			require $paths[ $component ] . '/library.php';
		} else {
			ssi_smoke_assert( false, "Unknown component {$component}." );
		}
	}

	ssi_smoke_assert( class_exists( 'BFB_Versions', false ), 'BFB version registry should load.' );
	ssi_smoke_assert( class_exists( 'HTML_To_Blocks_Versions', false ), 'H2BC version registry should load.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'plugins_loaded', array( 'BFB_Versions', 'initialize_latest_version' ) ), 'BFB initializer hook should register once.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'plugins_loaded', array( 'HTML_To_Blocks_Versions', 'initialize_latest_version' ) ), 'H2BC initializer hook should register once.' );

	do_action( 'plugins_loaded' );
	restore_error_handler();

	$bfb_winner_component  = ssi_smoke_last_matching_component( $loaded_components, 'ssi_smoke_component_contains_bfb' );
	$h2bc_winner_component = ssi_smoke_last_matching_component( $loaded_components, 'ssi_smoke_component_contains_h2bc' );
	ssi_smoke_assert( null !== $bfb_winner_component, 'Scenario should include a BFB provider.' );
	ssi_smoke_assert( null !== $h2bc_winner_component, 'Scenario should include an H2BC provider.' );

	$bfb_expected_path = 'ssi' === $bfb_winner_component ? $source_bfb : $paths[ $bfb_winner_component ];
	if ( 'ssi' === $h2bc_winner_component ) {
		$h2bc_expected_path = $source_h2b;
	} elseif ( 'standalone-bfb-with-h2bc' === $h2bc_winner_component ) {
		$h2bc_expected_path = $paths['standalone-bfb-with-h2bc-h2bc'];
	} elseif ( 'consumer-bfb-a' === $h2bc_winner_component ) {
		$h2bc_expected_path = $paths['consumer-bfb-a-h2bc'];
	} elseif ( 'consumer-bfb-b' === $h2bc_winner_component ) {
		$h2bc_expected_path = $paths['consumer-bfb-b-h2bc'];
	} else {
		$h2bc_expected_path = $paths[ $h2bc_winner_component ];
	}

	$bfb_expected_real  = realpath( $bfb_expected_path );
	$h2bc_expected_real = realpath( $h2bc_expected_path );
	ssi_smoke_assert( is_string( $bfb_expected_real ), 'Expected BFB path should resolve.' );
	ssi_smoke_assert( is_string( $h2bc_expected_real ), 'Expected H2BC path should resolve.' );

	$expected_duplicate_warnings = max( 0, count( array_filter( $loaded_components, 'ssi_smoke_component_contains_bfb' ) ) - 1 );
	ssi_smoke_assert( $expected_duplicate_warnings === count( $duplicate_warnings ), "Duplicate BFB warnings should match provider count. Expected {$expected_duplicate_warnings}, got " . count( $duplicate_warnings ) . '.' );
	ssi_smoke_assert( defined( 'BFB_PATH' ) && trailingslashit( (string) $bfb_expected_real ) === BFB_PATH, 'Last same-version BFB provider should win.' );
	ssi_smoke_assert( function_exists( 'bfb_convert' ), 'BFB API should load after combined activation.' );
	ssi_smoke_assert( function_exists( 'bfb_normalize' ), 'BFB normalize API should load after combined activation.' );
	ssi_smoke_assert( function_exists( 'html_to_blocks_raw_handler' ), 'H2BC raw handler should load after combined activation.' );

	$normalize_ref = new ReflectionFunction( 'bfb_normalize' );
	$h2bc_ref      = new ReflectionFunction( 'html_to_blocks_raw_handler' );
	ssi_smoke_assert( $bfb_expected_real . '/includes/normalization.php' === $normalize_ref->getFileName(), 'bfb_normalize() should come from the winning BFB provider.' );
	ssi_smoke_assert( $h2bc_expected_real . '/raw-handler.php' === $h2bc_ref->getFileName(), 'html_to_blocks_raw_handler() should come from the winning H2BC provider.' );
	ssi_smoke_assert( array( '0.8.1' ) === $GLOBALS['ssi_smoke_bfb_loaded'], 'bfb_loaded should fire once.' );
	ssi_smoke_assert( array( '0.7.2' ) === $GLOBALS['ssi_smoke_h2bc_loaded'], 'html_to_blocks_loaded should fire once.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'wp_insert_post_data', 'bfb_convert_on_insert' ), 'BFB insert hook should register once.' );
	ssi_smoke_assert( 1 === ssi_smoke_hook_count( 'wp_insert_post_data', 'html_to_blocks_convert_on_insert' ), 'H2BC insert hook should register once.' );
} finally {
	restore_error_handler();
	ssi_smoke_remove_path( $temp_root );
}

echo "OK: {$scenario_name}\n";
