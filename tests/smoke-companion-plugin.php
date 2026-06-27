<?php
/**
 * Smoke coverage for the companion-plugin scaffolder, install/activate path, and
 * declared-dependency wiring (issue #491 slice 1).
 *
 * Run from the repository root:
 * php tests/smoke-companion-plugin.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$ssi_companion_tmp = sys_get_temp_dir() . '/ssi-companion-smoke-' . getmypid();
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', $ssi_companion_tmp . '/plugins' );
}
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', $ssi_companion_tmp . '/mu-plugins' );
}

// Controllable plugin-activation stubs so the install path is exercised without
// a WordPress runtime. is_plugin_active reports inactive until activate_plugin
// records the activation intent.
$GLOBALS['ssi_companion_active']    = array();
$GLOBALS['ssi_companion_activated'] = array();

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

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return in_array( $plugin_file, $GLOBALS['ssi_companion_active'], true );
	}
}

if ( ! function_exists( 'activate_plugin' ) ) {
	function activate_plugin( string $plugin_file ) {
		$GLOBALS['ssi_companion_active'][]    = $plugin_file;
		$GLOBALS['ssi_companion_activated'][] = $plugin_file;
		return null;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// Synthetic minimal payload: one block (block.json + render) plus a preserved
// island scoped to that block. Generic; no fixture-specific strings.
$payload = array(
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
			'content' => 'document.addEventListener("DOMContentLoaded",function(){});',
			'block'   => 'ssi-example-site/custom-hero',
		),
	),
);

// 1. Scaffolder emits a valid plugin file set.
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$assert( is_array( $descriptor ), 'scaffold-returns-descriptor', is_array( $descriptor ) ? '' : 'WP_Error returned' );

if ( is_array( $descriptor ) ) {
	$assert( 'ssi-example-site' === $descriptor['slug'], 'scaffold-namespaces-slug', (string) $descriptor['slug'] );
	$assert( 'ssi-example-site/ssi-example-site.php' === $descriptor['plugin_file'], 'scaffold-plugin-file-path', (string) $descriptor['plugin_file'] );
	$assert( array( 'ssi-example-site/custom-hero' ) === $descriptor['block_names'], 'scaffold-namespaces-block-name' );
	$assert( false === $descriptor['mu_plugin'], 'scaffold-regular-plugin-by-default' );

	$files = $descriptor['files'];
	$main  = $files['ssi-example-site/ssi-example-site.php'] ?? '';
	$assert( str_contains( $main, 'Plugin Name:' ), 'main-file-has-plugin-header' );
	$assert( str_contains( $main, 'register_block_type' ), 'main-file-registers-blocks' );
	$assert( str_contains( $main, "add_filter( 'render_block'" ), 'main-file-scopes-island-enqueue' );
	$assert( str_contains( $main, 'wp_enqueue_script' ), 'main-file-enqueues-island-js' );

	$block_json_raw = $files['ssi-example-site/blocks/custom-hero/block.json'] ?? '';
	$block_json     = json_decode( $block_json_raw, true );
	$assert( is_array( $block_json ) && 'ssi-example-site/custom-hero' === ( $block_json['name'] ?? '' ), 'block-json-forces-namespaced-name' );
	$assert( is_array( $block_json ) && 3 === ( $block_json['apiVersion'] ?? 0 ), 'block-json-has-api-version' );

	$render = $files['ssi-example-site/blocks/custom-hero/render.php'] ?? '';
	$assert( str_starts_with( ltrim( $render ), '<?php' ), 'render-php-opens-with-php-tag' );

	$island_files = array_filter( array_keys( $files ), static fn ( string $path ): bool => str_contains( $path, '/islands/' ) && str_ends_with( $path, '.js' ) );
	$assert( 1 === count( $island_files ), 'preserved-island-js-file-emitted' );
}

// mu-plugin variant materializes a root loader stub.
$mu_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( array_merge( $payload, array( 'mu_plugin' => true ) ) );
$assert( is_array( $mu_descriptor ) && true === $mu_descriptor['mu_plugin'], 'scaffold-honors-mu-plugin-option' );
$assert( is_array( $mu_descriptor ) && 'ssi-example-site.php' === $mu_descriptor['loader_file'], 'mu-plugin-emits-root-loader' );
$assert( is_array( $mu_descriptor ) && isset( $mu_descriptor['files']['ssi-example-site.php'] ), 'mu-plugin-loader-file-present' );

// Invalid payloads are rejected.
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::scaffold( array( 'site_slug' => '' ) ) ), 'scaffold-rejects-missing-site-slug' );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::scaffold( array( 'site_slug' => 'x', 'blocks' => array() ) ) ), 'scaffold-rejects-missing-blocks' );

// 2. Install plan resolves the file set + activation intent (pure / no writes).
if ( is_array( $descriptor ) ) {
	$plan = Static_Site_Importer_Plugin_Materializer::generated_install_plan( $descriptor, '/var/plugins' );
	$assert( is_array( $plan ), 'install-plan-built' );
	if ( is_array( $plan ) ) {
		$assert( 'plugin' === $plan['destination'], 'install-plan-regular-destination' );
		$assert( true === $plan['activate'], 'install-plan-regular-requires-activation' );
		$assert( isset( $plan['absolute_files']['/var/plugins/ssi-example-site/ssi-example-site.php'] ), 'install-plan-absolute-paths-prefixed' );
	}

	$mu_plan = Static_Site_Importer_Plugin_Materializer::generated_install_plan( $mu_descriptor, '/var/mu-plugins' );
	$assert( is_array( $mu_plan ) && 'mu_plugin' === $mu_plan['destination'], 'install-plan-mu-destination' );
	$assert( is_array( $mu_plan ) && false === $mu_plan['activate'], 'install-plan-mu-no-activation' );
}

// 3. Full install/activate path writes the file set and activates it.
$report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload );
$assert( 'installed_activated' === ( $report['status'] ?? '' ), 'install-status-installed-activated', (string) ( $report['status'] ?? '' ) );
$assert( true === ( $report['installed'] ?? false ), 'install-reports-installed' );
$assert( true === ( $report['active'] ?? false ), 'install-reports-active' );
$assert( in_array( 'installed', $report['actions'] ?? array(), true ), 'install-records-installed-action' );
$assert( in_array( 'activated', $report['actions'] ?? array(), true ), 'install-records-activated-action' );
$assert( in_array( 'ssi-example-site/ssi-example-site.php', $GLOBALS['ssi_companion_activated'], true ), 'install-activates-companion-plugin' );
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ), 'install-writes-main-file-to-disk' );
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/block.json' ), 'install-writes-block-json-to-disk' );
$written_main = file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) ? (string) file_get_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) : '';
$assert( str_contains( $written_main, 'register_block_type' ), 'written-main-file-registers-blocks' );

// mu-plugin install writes the root loader and needs no activation call.
$mu_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( array_merge( $payload, array( 'mu_plugin' => true ) ) );
$assert( 'installed_activated' === ( $mu_report['status'] ?? '' ), 'mu-install-status', (string) ( $mu_report['status'] ?? '' ) );
$assert( true === ( $mu_report['mu_plugin'] ?? false ), 'mu-install-reports-mu-plugin' );
$assert( ! in_array( 'activated', $mu_report['actions'] ?? array(), true ), 'mu-install-skips-activation' );
$assert( file_exists( WPMU_PLUGIN_DIR . '/ssi-example-site.php' ), 'mu-install-writes-root-loader' );

// 4. Declared-dependency wiring: distinct generated/companion dependency entry.
$dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $payload );
$assert( 'companion_plugin' === ( $dependency['type'] ?? '' ), 'dependency-type-is-companion-plugin' );
$assert( 'ssi-example-site' === ( $dependency['slug'] ?? '' ), 'dependency-slug-namespaced' );
$assert( is_callable( $dependency['availability_callback'] ?? null ), 'dependency-has-availability-callback' );

// The earlier install marked the regular companion active via the stub, so the
// dependency row reflects a satisfied dependency.
$active_row = Static_Site_Importer_Entity_Materializer_Registry::companion_dependency_row( $dependency, false );
$assert( 'generated' === ( $active_row['source'] ?? '' ), 'dependency-row-source-generated' );
$assert( true === ( $active_row['active'] ?? false ), 'dependency-row-active-when-installed' );
$assert( array( 'ssi-example-site/custom-hero' ) === ( $active_row['block_names'] ?? array() ), 'dependency-row-carries-block-names' );

// A not-yet-installed companion surfaces as a gate-visible failure.
$missing_payload    = array_merge( $payload, array( 'site_slug' => 'second-site' ) );
$missing_dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $missing_payload );
$assert( false === Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_available( $missing_dependency ), 'missing-companion-not-available' );

$gate_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( $gate_report, $missing_dependency, false );
$assert( 1 === (int) ( $gate_report['quality']['companion_plugin_dependency_failures'] ?? 0 ), 'missing-companion-increments-quality-counter' );
$assert( isset( $gate_report['companion_plugins']['dependencies']['ssi-second-site'] ), 'companion-dependency-declared-in-report' );
$missing_diag = array_values( array_filter( $gate_report['diagnostics'] ?? array(), static fn ( array $d ): bool => 'companion_plugin_missing' === ( $d['code'] ?? '' ) ) );
$assert( 1 === count( $missing_diag ), 'missing-companion-emits-diagnostic' );

$quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $gate_report, array( 'fail_on_quality' => true ) );
$assert( in_array( 'companion_plugin_missing', $quality['failure_reasons'] ?? array(), true ), 'gate-sees-companion-plugin-missing' );
$assert( true === ( $quality['fail_import'] ?? false ), 'gate-fails-import-on-missing-companion' );

// Waived missing companion warns but does not fail the gate.
$waived_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( $waived_report, $missing_dependency, true );
$assert( 0 === (int) ( $waived_report['quality']['companion_plugin_dependency_failures'] ?? 0 ), 'waived-companion-no-quality-failure' );
$waived_diag = array_values( array_filter( $waived_report['diagnostics'] ?? array(), static fn ( array $d ): bool => 'companion_plugin_waived' === ( $d['code'] ?? '' ) ) );
$assert( 1 === count( $waived_diag ), 'waived-companion-emits-warning' );

// Cleanup generated fixtures.
$cleanup = static function ( string $dir ) use ( &$cleanup ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( is_array( $items ) ? $items : array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? $cleanup( $path ) : unlink( $path );
	}
	rmdir( $dir );
};
$cleanup( $ssi_companion_tmp );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: companion plugin smoke passed (' . $assertions . " assertions)\n";
