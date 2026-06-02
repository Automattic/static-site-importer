<?php
/**
 * Library entry point for block-format-bridge.
 *
 * Composer consumers autoload this file (`autoload.files`) to make the
 * bridge APIs and hooks available without installing the standalone
 * plugin. The standalone plugin also loads this file.
 *
 * Multiple bundled copies can coexist: each registers its version and
 * initializer, then `BFB_Versions` initializes the highest registered
 * version on `plugins_loaded:1`.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$bfb_library_path    = __DIR__;
$bfb_library_version = '0.8.0';

// Load Composer/php-scoper dependencies as soon as the bridge package is
// included, not when the winning BFB version initializes on
// `plugins_loaded:1`. Some dependencies (notably html-to-blocks-converter)
// register their own Action-Scheduler-style version callbacks at
// `plugins_loaded:0`; loading them from BFB's initializer would be too late.
if ( file_exists( $bfb_library_path . '/vendor_prefixed/autoload.php' ) ) {
	require_once $bfb_library_path . '/vendor_prefixed/autoload.php';
} elseif ( file_exists( $bfb_library_path . '/vendor/autoload.php' ) ) {
	require_once $bfb_library_path . '/vendor/autoload.php';
}

if ( ! class_exists( 'BFB_Versions', false ) ) {
	require_once $bfb_library_path . '/includes/class-bfb-versions.php';
}

$bfb_initializer = static function () use ( $bfb_library_path, $bfb_library_version ): void {
	// BFB bundles html-to-blocks-converter as a package. Its library
	// registers with its own version registry when Composer autoload runs;
	// initialize that registry now so BFB's HTML adapter can call the
	// raw-handler during this same request even when the standalone h2bc
	// plugin is inactive.
	if ( class_exists( '\BlockFormatBridge\Vendor\HTML_To_Blocks_Versions' ) ) {
		\BlockFormatBridge\Vendor\HTML_To_Blocks_Versions::initialize_latest_version();
	} elseif ( class_exists( 'HTML_To_Blocks_Versions' ) ) {
		HTML_To_Blocks_Versions::initialize_latest_version();
	}

	if ( ! defined( 'BFB_VERSION' ) ) {
		define( 'BFB_VERSION', $bfb_library_version );
	}
	if ( ! defined( 'BFB_PATH' ) ) {
		define( 'BFB_PATH', trailingslashit( $bfb_library_path ) );
	}
	if ( ! defined( 'BFB_FILE' ) ) {
		$plugin_file = $bfb_library_path . '/block-format-bridge.php';
		define( 'BFB_FILE', file_exists( $plugin_file ) ? $plugin_file : __FILE__ );
	}
	if ( ! defined( 'BFB_MIN_WP' ) ) {
		define( 'BFB_MIN_WP', '6.4' );
	}
	if ( ! defined( 'BFB_MIN_PHP' ) ) {
		define( 'BFB_MIN_PHP', '8.1' );
	}

	require_once $bfb_library_path . '/includes/interface-bfb-format-adapter.php';
	require_once $bfb_library_path . '/includes/class-bfb-adapter-registry.php';
	require_once $bfb_library_path . '/includes/class-bfb-html-adapter.php';
	require_once $bfb_library_path . '/includes/class-bfb-markdown-adapter.php';
	require_once $bfb_library_path . '/includes/api.php';
	require_once $bfb_library_path . '/includes/normalization.php';
	require_once $bfb_library_path . '/includes/abilities.php';
	require_once $bfb_library_path . '/includes/hooks.php';
	require_once $bfb_library_path . '/includes/rest.php';
	require_once $bfb_library_path . '/includes/cli.php';
	require_once $bfb_library_path . '/includes/bootstrap.php';
};

$bfb_register = static function () use ( $bfb_library_path, $bfb_library_version, $bfb_initializer ): void {
	BFB_Versions::instance()->register( $bfb_library_version, $bfb_initializer, $bfb_library_path );
};

BFB_Versions::register_hooks();

if ( did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
	$bfb_register();
	BFB_Versions::initialize_latest_version();
} else {
	add_action( 'plugins_loaded', $bfb_register, 0 );
}
