<?php
/**
 * Smoke coverage for the preserved-island-JS consumption seam (issue #488 SSI side).
 *
 * Proves that preserved custom JS rides the generated companion plugin
 * (scoped, enqueued from the plugin, theme-independent) and NOT the generated
 * theme. The blocks-engine producer that populates companion_plugin_payload's
 * preserved_js slot is a separate later task, so the seam is exercised
 * synthetically: a payload whose preserved_js carries one island.
 *
 * Run from the repository root:
 * php tests/smoke-companion-plugin-js.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// Unique island marker so we can prove the same JS is NOT duplicated into the
// theme. Generic; no fixture-specific strings.
$island_body = 'window.__ssiIslandMarker=function(){return 42;};';
$payload     = array(
	'schema'       => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug'    => 'Example Site',
	'site_name'    => 'Example Site',
	'blocks'       => array(
		array(
			'name'       => 'Custom Hero',
			'block_json' => array(
				'title'    => 'Custom Hero',
				'category' => 'design',
			),
			'render'     => '<div class="ssi-hero"><?php echo esc_html( $attributes["heading"] ?? "" ); ?></div>',
		),
	),
	'preserved_js' => array(
		array(
			'handle'  => 'hero-island',
			'content' => $island_body,
			'block'   => 'ssi-example-site/custom-hero',
			'selector' => 'script:nth-of-type(1)',
			'source_path' => 'index.html',
		),
	),
);

// 1. Companion plugin consumes preserved_js: scoped enqueue + island file +
//    descriptor carriage. This is the payload -> scaffold() pass-through.
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$assert( is_array( $descriptor ), 'scaffold-returns-descriptor', is_array( $descriptor ) ? '' : 'WP_Error returned' );

if ( is_array( $descriptor ) ) {
	$assert( array( 'hero-island' ) === ( $descriptor['island_handles'] ?? null ), 'descriptor-exposes-island-handles' );
	$assert( 'script:nth-of-type(1)' === ( $descriptor['runtime_scripts'][0]['selector'] ?? '' ), 'descriptor-exposes-runtime-selector' );

	$files = $descriptor['files'];
	$main  = $files['ssi-example-site/ssi-example-site.php'] ?? '';
	$assert( str_contains( $main, "add_filter( 'render_block'" ), 'companion-scopes-island-to-owning-block' );
	$assert( str_contains( $main, 'wp_enqueue_script' ), 'companion-enqueues-island-js' );
	$assert( str_contains( $main, "'block' => 'ssi-example-site/custom-hero'" ), 'companion-island-bound-to-block' );

	$island_files = array_filter(
		$files,
		static fn ( string $content, string $path ): bool => str_contains( $path, '/islands/' ) && str_ends_with( $path, '.js' ),
		ARRAY_FILTER_USE_BOTH
	);
	$assert( 1 === count( $island_files ), 'companion-emits-one-island-js-file' );
	$assert( in_array( $island_body, array_values( $island_files ), true ), 'companion-island-file-carries-js-body' );
}

$script_only = $payload;
$script_only['blocks'] = array();
$script_only['preserved_js'][0]['block'] = '';
$script_only_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $script_only );
$assert( is_array( $script_only_descriptor ), 'script-only-companion-is-supported' );
if ( is_array( $script_only_descriptor ) ) {
	$script_only_main = $script_only_descriptor['files']['ssi-example-site/ssi-example-site.php'] ?? '';
	$assert( str_contains( $script_only_main, "add_action( 'wp_enqueue_scripts'" ), 'script-only-companion-enqueues-on-frontend' );
	$assert( str_contains( $script_only_main, "'' !== ( \$island['block'] ?? '' )" ), 'global-enqueue-is-limited-to-unscoped-scripts' );
}

// 2. Theme decoupling: the generated theme functions.php no longer enqueues a
//    theme-coupled site.js, and the preserved island JS body never lands in the
//    theme. A legitimate per-asset script still rides the theme (path intact).
$theme_dir   = '/tmp/ssi-theme';
$legit_asset = 'assets/materialized/app.js';
$writes      = Static_Site_Importer_Theme_Materializer::base_theme_writes(
	$theme_dir,
	'example-site',
	'Example Site',
	'body { color: #000; }',
	false,
	false,
	array(
		array(
			'theme_path' => $legit_asset,
			'placement'  => 'body',
		),
	),
	array()
);

$functions_php = $writes[ $theme_dir . '/functions.php' ] ?? '';
$assert( '' !== $functions_php, 'theme-functions-php-generated' );
$assert( ! str_contains( $functions_php, 'site.js' ), 'theme-no-longer-enqueues-site-js' );
$assert( ! str_contains( $functions_php, $island_body ), 'theme-does-not-carry-preserved-island-js' );
$assert( ! isset( $writes[ $theme_dir . '/assets/site.js' ] ), 'theme-writes-omit-site-js-asset' );
$write_blob = implode( "\n", array_values( $writes ) );
$assert( ! str_contains( $write_blob, $island_body ), 'no-theme-write-carries-preserved-island-js' );
// Theme path otherwise intact: legitimate per-asset scripts still enqueue.
$assert( str_contains( $functions_php, $legit_asset ), 'theme-still-enqueues-legitimate-asset-scripts' );

// 3. Gate/diagnostics account for the JS as companion-plugin-carried.
$GLOBALS['ssi_companion_js_active'] = false;
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return ! empty( $GLOBALS['ssi_companion_js_active'] );
	}
}

$dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $payload );
$row        = Static_Site_Importer_Entity_Materializer_Registry::companion_dependency_row( $dependency, false );
$assert( array( 'hero-island' ) === ( $row['island_handles'] ?? null ), 'dependency-row-carries-island-handles' );

// Active companion: present diagnostic flags JS as runtime-carried theme-independently.
$GLOBALS['ssi_companion_js_active'] = true;
$report                            = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$report['quality']['fallback_count'] = 1;
$report['diagnostics'][] = array(
	'code'       => 'html_script_fallback',
	'kind'       => 'unsupported_html_fallback',
	'type'       => 'unsupported_html_fallback',
	'tag'        => 'script',
	'selector'   => 'script:nth-of-type(1)',
	'source_path' => 'index.html',
);
Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( $report, $dependency, false );
$present = array_values( array_filter( $report['diagnostics'] ?? array(), static fn ( array $d ): bool => 'companion_plugin_present' === ( $d['code'] ?? '' ) ) );
$assert( 1 === count( $present ), 'present-diagnostic-emitted-when-active' );
$assert( array( 'hero-island' ) === ( $present[0]['island_handles'] ?? null ), 'present-diagnostic-carries-island-handles' );
$assert( true === ( $present[0]['runtime_carried'] ?? false ), 'present-diagnostic-flags-runtime-carried' );
$materialized = array_values( array_filter( $report['diagnostics'] ?? array(), static fn ( array $d ): bool => 'runtime_script_materialized' === ( $d['code'] ?? '' ) ) );
$assert( 1 === count( $materialized ), 'companion-materialization-resolves-script-fallback' );
$assert( 'native_conversion' === ( $materialized[0]['loss_class'] ?? '' ), 'materialized-script-is-native-conversion-outcome' );
$assert( 0 === ( $report['quality']['fallback_count'] ?? -1 ), 'materialized-script-no-longer-counts-as-fallback' );
$report['diagnostics'][] = array(
	'code'        => 'html_script_fallback',
	'kind'        => 'html',
	'tag'         => 'script',
	'selector'    => 'script:nth-of-type(1)',
	'source_path' => 'index.html',
	'reason'      => 'script_requires_runtime',
);
Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $report, array() );
$unresolved = array_filter( $report['diagnostics'], static fn ( array $d ): bool => 'html_script_fallback' === ( $d['code'] ?? '' ) );
$assert( empty( $unresolved ), 'finalization-reconciles-late-script-fallback-rows' );
$stored = $report['companion_plugins']['dependencies']['ssi-example-site']['island_handles'] ?? null;
$assert( array( 'hero-island' ) === $stored, 'report-stores-companion-island-handles' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: companion plugin JS consumption seam smoke passed (' . $assertions . " assertions)\n";
