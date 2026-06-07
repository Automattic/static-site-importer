<?php
/**
 * Smoke coverage for two Composer consumers bundling BFB in one request.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

$GLOBALS['bfb_smoke_filters']        = array();
$GLOBALS['bfb_smoke_actions_done']   = array();
$GLOBALS['bfb_smoke_current_action'] = null;

function bfb_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function bfb_smoke_callback_id( $callback ): string {
	if ( is_string( $callback ) ) {
		return $callback;
	}

	if ( is_array( $callback ) && 2 === count( $callback ) ) {
		$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		return $class . '::' . (string) $callback[1];
	}

	if ( $callback instanceof Closure ) {
		return 'closure:' . spl_object_id( $callback );
	}

	if ( is_object( $callback ) ) {
		return 'object:' . spl_object_id( $callback );
	}

	return md5( serialize( $callback ) );
}

function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['bfb_smoke_filters'][ $hook_name ][ $priority ][ bfb_smoke_callback_id( $callback ) ] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

function has_filter( string $hook_name, $callback = false ) {
	if ( empty( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	$id = bfb_smoke_callback_id( $callback );
	foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] as $priority => $entries ) {
		if ( isset( $entries[ $id ] ) ) {
			return $priority;
		}
	}

	return false;
}

function has_action( string $hook_name, $callback = false ) {
	return has_filter( $hook_name, $callback );
}

function apply_filters( string $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['bfb_smoke_filters'][ $hook_name ] );
	foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] as $entries ) {
		foreach ( $entries as $entry ) {
			$accepted = max( 1, (int) $entry['accepted_args'] );
			$params   = array_slice( array_merge( array( $value ), $args ), 0, $accepted );
			$value    = call_user_func_array( $entry['callback'], $params );
		}
	}

	return $value;
}

function do_action( string $hook_name, ...$args ): void {
	bfb_smoke_do_action_range( $hook_name, null, null, $args );
}

function wp_delete_file( string $file ): bool {
	return unlink( $file );
}

function bfb_smoke_do_action_range( string $hook_name, ?int $min_priority, ?int $max_priority, array $args = array() ): void {
	$GLOBALS['bfb_smoke_current_action'] = $hook_name;

	if ( ! empty( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ) ) {
		ksort( $GLOBALS['bfb_smoke_filters'][ $hook_name ] );
		foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] as $priority => $entries ) {
			if ( null !== $min_priority && $priority < $min_priority ) {
				continue;
			}
			if ( null !== $max_priority && $priority > $max_priority ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				$accepted = (int) $entry['accepted_args'];
				$params   = array_slice( $args, 0, $accepted );
				call_user_func_array( $entry['callback'], $params );
			}
		}
	}

	$GLOBALS['bfb_smoke_actions_done'][ $hook_name ] = ( $GLOBALS['bfb_smoke_actions_done'][ $hook_name ] ?? 0 ) + 1;
	$GLOBALS['bfb_smoke_current_action']             = null;
}

function did_action( string $hook_name ): int {
	return (int) ( $GLOBALS['bfb_smoke_actions_done'][ $hook_name ] ?? 0 );
}

function doing_action( ?string $hook_name = null ): bool {
	if ( null === $hook_name ) {
		return null !== $GLOBALS['bfb_smoke_current_action'];
	}

	return $GLOBALS['bfb_smoke_current_action'] === $hook_name;
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

function wp_unslash( $value ) {
	return $value;
}

function wp_slash( $value ) {
	return $value;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function get_post_types( array $args = array() ): array {
	unset( $args );
	return array( 'post' => 'post', 'page' => 'page' );
}

function parse_blocks( string $content ): array {
	return array(
		array(
			'blockName'    => 'core/freeform',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $content,
			'innerContent' => array( $content ),
		),
	);
}

function serialize_blocks( array $blocks ): string {
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

function serialize_block( array $block ): string {
	return (string) ( $block['innerHTML'] ?? '' );
}

function render_block( array $block ): string {
	return (string) ( $block['innerHTML'] ?? '' );
}

function bfb_smoke_copy_path( string $source, string $target ): void {
	if ( is_dir( $source ) ) {
		mkdir( $target, 0777, true );
		$iterator = new FilesystemIterator( $source, FilesystemIterator::SKIP_DOTS );
		foreach ( $iterator as $item ) {
			if ( ! $item instanceof SplFileInfo ) {
				continue;
			}
			bfb_smoke_copy_path( $item->getPathname(), $target . '/' . $item->getBasename() );
		}
		return;
	}

	copy( $source, $target );
}

function bfb_smoke_copy_package( string $source_root, string $target_root, string $version, bool $with_normalization = true ): void {
	mkdir( $target_root, 0777, true );
	copy( $source_root . '/library.php', $target_root . '/library.php' );
	bfb_smoke_copy_path( $source_root . '/includes', $target_root . '/includes' );
	bfb_smoke_copy_path( $source_root . '/vendor_prefixed', $target_root . '/vendor_prefixed' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static smoke patches a temporary local copy.
	$library_raw = file_get_contents( $target_root . '/library.php' );
	if ( ! is_string( $library_raw ) ) {
		bfb_smoke_assert( false, 'Temp library.php should be readable.' );
	}
	$library = is_string( $library_raw ) ? $library_raw : '';
	$library = preg_replace( '/\$bfb_library_version = \'[^\']+\';/', "\$bfb_library_version = '{$version}';", $library, 1, $count );
	bfb_smoke_assert( 1 === $count && is_string( $library ), "Temp library.php version should patch to {$version}." );
	if ( ! $with_normalization ) {
		$library = str_replace( "\trequire_once \$bfb_library_path . '/includes/normalization.php';\n", '', $library, $removed );
		bfb_smoke_assert( 1 === $removed, 'Stale temp library.php should remove the bfb_normalize() include.' );
		wp_delete_file( $target_root . '/includes/normalization.php' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Static smoke patches a temporary local copy.
	file_put_contents( $target_root . '/library.php', $library );
}

function bfb_smoke_remove_path( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		wp_delete_file( $path );
		return;
	}

	$iterator = new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS );
	foreach ( $iterator as $item ) {
		if ( ! $item instanceof SplFileInfo ) {
			continue;
		}
		bfb_smoke_remove_path( $item->getPathname() );
	}
	rmdir( $path );
}

function bfb_smoke_hook_count( string $hook_name, $callback ): int {
	$count = 0;
	$id    = bfb_smoke_callback_id( $callback );

	foreach ( $GLOBALS['bfb_smoke_filters'][ $hook_name ] ?? array() as $entries ) {
		$count += isset( $entries[ $id ] ) ? 1 : 0;
	}

	return $count;
}

$source_root = dirname( __DIR__ );
$temp_root   = sys_get_temp_dir() . '/bfb-multi-consumer-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$consumer_a  = $temp_root . '/data-machine/vendor/chubes4/block-format-bridge';
$consumer_b  = $temp_root . '/markdown-database-integration/vendor/chubes4/block-format-bridge';

try {
	bfb_smoke_copy_package( $source_root, $consumer_a, '0.3.0', false );
	bfb_smoke_copy_package( $source_root, $consumer_b, '9.9.9' );

	$bfb_loaded_versions = array();
	add_action(
		'bfb_loaded',
		static function ( string $version ) use ( &$bfb_loaded_versions ): void {
			$bfb_loaded_versions[] = $version;
		},
		10,
		1
	);

	require $consumer_a . '/library.php';
	require $consumer_b . '/library.php';

	bfb_smoke_assert(
		1 === bfb_smoke_hook_count( 'plugins_loaded', array( 'BFB_Versions', 'initialize_latest_version' ) ),
		'BFB version initializer should register on plugins_loaded once.'
	);

	bfb_smoke_do_action_range( 'plugins_loaded', 0, 0 );

	$registry = BFB_Versions::instance();
	$versions = new ReflectionProperty( $registry, 'versions' );
	$registered_versions = $versions->getValue( $registry );

	bfb_smoke_assert( is_array( $registered_versions ), 'Registered versions should be inspectable.' );
	bfb_smoke_assert(
		array( '0.3.0', '9.9.9' ) === array_column( $registered_versions, 'version' ),
		'Both consumer copies should register their BFB versions.'
	);
	bfb_smoke_assert(
		array( realpath( $consumer_a ), realpath( $consumer_b ) ) === array_column( $registered_versions, 'source' ),
		'Both consumer copies should retain source paths for duplicate-version diagnostics.'
	);

	bfb_smoke_do_action_range( 'plugins_loaded', 1, 1 );

	bfb_smoke_assert( function_exists( 'bfb_convert' ), 'bfb_convert() should exist after the winning copy boots.' );
	bfb_smoke_assert( function_exists( 'bfb_normalize' ), 'bfb_normalize() should exist after the winning copy boots, even though the stale bundled copy lacked it.' );
	bfb_smoke_assert( defined( 'BFB_VERSION' ) && '9.9.9' === BFB_VERSION, 'The highest registered BFB version should initialize.' );
	$winning_path_raw = realpath( $consumer_b );
	$losing_path_raw  = realpath( $consumer_a );
	if ( ! is_string( $winning_path_raw ) ) {
		bfb_smoke_assert( false, 'Winning consumer path should resolve.' );
	}
	if ( ! is_string( $losing_path_raw ) ) {
		bfb_smoke_assert( false, 'Losing consumer path should resolve.' );
	}
	$winning_path = is_string( $winning_path_raw ) ? $winning_path_raw : '';
	$losing_path  = is_string( $losing_path_raw ) ? $losing_path_raw : '';
	bfb_smoke_assert(
		defined( 'BFB_PATH' ) && trailingslashit( $winning_path ) === BFB_PATH,
		'BFB_PATH should point at the winning consumer copy. Expected ' . trailingslashit( $winning_path ) . ', got ' . ( defined( 'BFB_PATH' ) ? BFB_PATH : 'undefined' ) . '. Losing copy was ' . trailingslashit( $losing_path ) . '.'
	);
	$convert_ref   = new ReflectionFunction( 'bfb_convert' );
	$normalize_ref = new ReflectionFunction( 'bfb_normalize' );
	bfb_smoke_assert(
		$winning_path . '/includes/api.php' === $convert_ref->getFileName(),
		'bfb_convert() should resolve from the winning copy. Expected ' . $winning_path . '/includes/api.php, got ' . ( $convert_ref->getFileName() ? $convert_ref->getFileName() : 'unknown' ) . '.'
	);
	bfb_smoke_assert(
		$winning_path . '/includes/normalization.php' === $normalize_ref->getFileName(),
		'bfb_normalize() should resolve from the winning copy. Expected ' . $winning_path . '/includes/normalization.php, got ' . ( $normalize_ref->getFileName() ? $normalize_ref->getFileName() : 'unknown' ) . '. Losing copy has normalization.php=' . ( file_exists( $losing_path . '/includes/normalization.php' ) ? 'yes' : 'no' ) . '.'
	);
	bfb_smoke_assert( array( '9.9.9' ) === $bfb_loaded_versions, 'bfb_loaded should fire once for the winning version.' );
	bfb_smoke_assert( 1 === bfb_smoke_hook_count( 'wp_insert_post_data', 'bfb_convert_on_insert' ), 'BFB insert conversion hook should register once.' );

	$scoped_classes = array(
		'HTML_To_Blocks_Versions',
		'HTML_To_Blocks_HTML_Element',
		'HTML_To_Blocks_Block_Factory',
		'HTML_To_Blocks_Attribute_Parser',
		'HTML_To_Blocks_Transform_Registry',
	);

	foreach ( $scoped_classes as $class_name ) {
		bfb_smoke_assert(
			class_exists( 'BlockFormatBridge\\Vendor\\' . $class_name, false ),
			"Scoped h2bc {$class_name} should exist after bundled boot."
		);
		bfb_smoke_assert(
			! class_exists( $class_name, false ),
			"Global h2bc {$class_name} should not exist after bundled boot."
		);
	}

	bfb_smoke_assert(
		function_exists( 'BlockFormatBridge\\Vendor\\html_to_blocks_raw_handler' ),
		'Scoped h2bc raw handler should exist after bundled boot.'
	);
	bfb_smoke_assert(
		! function_exists( 'html_to_blocks_raw_handler' ),
		'Global h2bc raw handler should not exist after bundled boot.'
	);
} finally {
	bfb_smoke_remove_path( $temp_root );
}

echo "PASS: multi-consumer bundled BFB load\n";
