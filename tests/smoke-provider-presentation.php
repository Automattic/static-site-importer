<?php
/**
 * Smoke coverage for the provider presentation seam.
 *
 * Verifies the shared presentation pipeline (admin/active/empty guards, handle
 * resolution, inline enqueue) and the registry-driven registration dispatcher.
 *
 * Run from the repository root:
 * php tests/smoke-provider-presentation.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	// Mutable WordPress runtime state the pipeline reads/writes.
	$GLOBALS['ssi_test_is_admin']      = false;
	$GLOBALS['ssi_test_registered']    = array();
	$GLOBALS['ssi_test_enqueued']      = array();
	$GLOBALS['ssi_test_actions']       = array();
	$GLOBALS['ssi_test_inline_styles'] = array();

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin(): bool { return (bool) ( $GLOBALS['ssi_test_is_admin'] ?? false ); }
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
			$GLOBALS['ssi_test_actions'][] = array( 'hook' => $hook, 'callback' => $callback, 'priority' => $priority );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $tag, $value ) { unset( $tag ); return $value; }
	}
	if ( ! function_exists( 'shortcode_exists' ) ) {
		function shortcode_exists( string $tag ): bool { return 'add_to_cart' === $tag; }
	}
	if ( ! function_exists( 'wp_style_is' ) ) {
		function wp_style_is( string $handle, string $list = 'enqueued' ): bool {
			if ( 'registered' === $list ) {
				return isset( $GLOBALS['ssi_test_registered'][ $handle ] );
			}
			return isset( $GLOBALS['ssi_test_enqueued'][ $handle ] );
		}
	}
	if ( ! function_exists( 'wp_register_style' ) ) {
		function wp_register_style( string $handle, $src, array $deps = array(), $ver = false ): void {
			$GLOBALS['ssi_test_registered'][ $handle ] = array( 'src' => $src, 'deps' => $deps );
		}
	}
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		function wp_enqueue_style( string $handle ): void {
			$GLOBALS['ssi_test_enqueued'][ $handle ] = true;
		}
	}
	if ( ! function_exists( 'wp_add_inline_style' ) ) {
		function wp_add_inline_style( string $handle, string $css ): bool {
			$GLOBALS['ssi_test_inline_styles'][ $handle ] = ( $GLOBALS['ssi_test_inline_styles'][ $handle ] ?? '' ) . $css;
			return true;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-presentation.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-commerce-presentation.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']';
		}
	};

	$reset = static function (): void {
		$GLOBALS['ssi_test_is_admin']      = false;
		$GLOBALS['ssi_test_registered']    = array();
		$GLOBALS['ssi_test_enqueued']      = array();
		$GLOBALS['ssi_test_inline_styles'] = array();
	};

	// A test presentation with controllable active/CSS to exercise the base.
	if ( ! class_exists( 'SSI_Test_Presentation' ) ) {
		class SSI_Test_Presentation extends \Static_Site_Importer_Provider_Presentation {
			public static bool $active = true;
			public static string $out  = '.demo{color:red}';
			protected static function provider_slug(): string { return 'demo'; }
			protected static function is_active(): bool { return static::$active; }
			protected static function preferred_style_handles(): array { return array( 'demo-provider-style' ); }
			protected static function css(): string { return static::$out; }
		}
	}

	// Registry dispatcher registers the declared presentation exactly once on
	// wp_enqueue_scripts, and skips classes that are not presentation subclasses.
	\Static_Site_Importer_Entity_Materializer_Registry::register_presentations();
	$commerce_hooks = array_filter(
		$GLOBALS['ssi_test_actions'],
		static fn ( array $a ): bool => 'wp_enqueue_scripts' === $a['hook']
			&& is_array( $a['callback'] )
			&& 'Static_Site_Importer_Commerce_Presentation' === $a['callback'][0]
	);
	$assert( 1 === count( $commerce_hooks ), 'registry-registers-commerce-presentation-once' );
	$assert( 'enqueue' === ( reset( $commerce_hooks )['callback'][1] ?? '' ), 'registry-hooks-shared-enqueue-entrypoint' );

	// Own-handle fallback: no preferred handle present -> registers and enqueues a
	// slugged own handle, then attaches the CSS to it.
	$reset();
	SSI_Test_Presentation::$active = true;
	SSI_Test_Presentation::$out    = '.demo{color:red}';
	SSI_Test_Presentation::enqueue();
	$assert( isset( $GLOBALS['ssi_test_registered']['static-site-importer-demo'] ), 'own-handle-registered-when-no-provider-handle' );
	$assert( isset( $GLOBALS['ssi_test_enqueued']['static-site-importer-demo'] ), 'own-handle-enqueued' );
	$assert( '.demo{color:red}' === ( $GLOBALS['ssi_test_inline_styles']['static-site-importer-demo'] ?? '' ), 'css-attached-to-own-handle' );

	// Preferred handle present -> CSS attaches to it, no own handle registered.
	$reset();
	$GLOBALS['ssi_test_registered']['demo-provider-style'] = array();
	SSI_Test_Presentation::enqueue();
	$assert( '.demo{color:red}' === ( $GLOBALS['ssi_test_inline_styles']['demo-provider-style'] ?? '' ), 'css-attached-to-preferred-handle' );
	$assert( ! isset( $GLOBALS['ssi_test_registered']['static-site-importer-demo'] ), 'own-handle-not-registered-when-preferred-present' );

	// Admin context is a no-op.
	$reset();
	$GLOBALS['ssi_test_is_admin'] = true;
	SSI_Test_Presentation::enqueue();
	$assert( array() === $GLOBALS['ssi_test_inline_styles'], 'admin-context-emits-nothing' );

	// Inactive provider is a no-op.
	$reset();
	SSI_Test_Presentation::$active = false;
	SSI_Test_Presentation::enqueue();
	$assert( array() === $GLOBALS['ssi_test_inline_styles'], 'inactive-provider-emits-nothing' );
	SSI_Test_Presentation::$active = true;

	// Empty CSS is a no-op (never registers a handle).
	$reset();
	SSI_Test_Presentation::$out = '   ';
	SSI_Test_Presentation::enqueue();
	$assert( array() === $GLOBALS['ssi_test_registered'], 'empty-css-registers-no-handle' );

	// The refactored commerce presentation preserves its own handle + CSS.
	$reset();
	SSI_Test_Presentation::$out = '.demo{color:red}';
	\Static_Site_Importer_Commerce_Presentation::enqueue();
	$assert( isset( $GLOBALS['ssi_test_inline_styles']['static-site-importer-commerce'] ), 'commerce-attaches-to-own-handle-name' );
	$assert( false !== strpos( $GLOBALS['ssi_test_inline_styles']['static-site-importer-commerce'] ?? '', 'add_to_cart_inline' ), 'commerce-css-preserved' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: provider presentation smoke passed (' . $assertions . " assertions)\n";
}
