<?php
/**
 * Smoke coverage for dependency-manager diagnostics across the three distinct
 * ways a required `wp_org_plugin` dependency can end a materialization
 * attempt: install failure, activation failure, and installed+activated but
 * not yet ready (the WooCommerce false-negative this covers). Each must
 * report a message that names what actually happened instead of one generic
 * "could not install or activate" string regardless of cause.
 *
 * Run: php tests/smoke-dependency-manager-readiness.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }

/**
 * A test double standing in for the real plugin materializer. Dependency
 * manager only ever calls it by class name, so this substitution isolates
 * the diagnostic-building logic under test from real plugin install/activate
 * mechanics (already covered by smoke-plugin-materializer-lifecycle.php).
 */
class Static_Site_Importer_Plugin_Materializer {
	public static function ensure_wp_org_plugin( string $slug, string $plugin_file, ?callable $availability_check = null, ?callable $preparation_callback = null ): array {
		return $GLOBALS['ssi_next_report'];
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-dependency-manager.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$adapter = array(
	'provider'     => 'woocommerce',
	'waiver_arg'   => 'allow_missing_woocommerce',
	'dependencies' => array(
		array(
			'type'                  => 'wp_org_plugin',
			'slug'                  => 'woocommerce',
			'plugin_file'           => 'woocommerce/woocommerce.php',
			'availability_callback' => static fn (): bool => false,
		),
	),
);

$lifecycle = static fn ( string $phase = '' ): array => array(
	'dependencies' => array(
		'products' => array( 'adapter' => $adapter, 'required' => true ),
	),
);

// 1. Genuine install failure: the report never reached an installed state.
$GLOBALS['ssi_next_report'] = array(
	'slug'        => 'woocommerce',
	'plugin_file' => 'woocommerce/woocommerce.php',
	'status'      => 'failed',
	'installed'   => false,
	'active'      => false,
	'error'       => array( 'code' => 'static_site_importer_plugin_install_failed', 'message' => 'WordPress could not install plugin woocommerce.' ),
);
$install_failure = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle(), array( 'materialize_dependencies' => true ) );
$assert( is_wp_error( $install_failure ), 'install-failure-returns-wp-error' );
$assert( 'static_site_importer_required_runtime_dependency_failed' === ( $install_failure->get_error_code() ?? '' ), 'install-failure-error-code' );
$assert( str_contains( $install_failure->get_error_message(), 'could not install' ), 'install-failure-message-names-install-stage' );
$assert( ! str_contains( $install_failure->get_error_message(), 'activate' ), 'install-failure-message-does-not-claim-activation-was-attempted' );
$assert( 'install' === ( $install_failure->get_error_data()['failure_stage'] ?? '' ), 'install-failure-stage-is-recorded' );

// 2. Genuine activation failure: installed cleanly, activation never took.
$GLOBALS['ssi_next_report'] = array(
	'slug'        => 'woocommerce',
	'plugin_file' => 'woocommerce/woocommerce.php',
	'status'      => 'failed',
	'installed'   => true,
	'active'      => false,
	'error'       => array( 'code' => 'static_site_importer_plugin_activation_failed', 'message' => 'Plugin woocommerce activation failed: fatal error.' ),
);
$activation_failure = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle(), array( 'materialize_dependencies' => true ) );
$assert( is_wp_error( $activation_failure ), 'activation-failure-returns-wp-error' );
$assert( 'static_site_importer_required_runtime_dependency_failed' === ( $activation_failure->get_error_code() ?? '' ), 'activation-failure-error-code' );
$assert( str_contains( $activation_failure->get_error_message(), 'could not activate' ), 'activation-failure-message-names-activation-stage' );
$assert( ! str_contains( $activation_failure->get_error_message(), 'could not install' ), 'activation-failure-message-does-not-claim-install-failed' );
$assert( 'activation' === ( $activation_failure->get_error_data()['failure_stage'] ?? '' ), 'activation-failure-stage-is-recorded' );

// 3. The false-negative this branch exists for: install and activation both
// succeeded (WordPress's own state proves it), but the availability probe
// is still false because its `init`-time registrations have not run yet in
// this request. This must never be reported as an install/activation
// failure, and it must not fire at all during the `prepare` phase — the
// continuation checkpoint is expected to carry it to a fresh request.
$GLOBALS['ssi_next_report'] = array(
	'slug'        => 'woocommerce',
	'plugin_file' => 'woocommerce/woocommerce.php',
	'status'      => 'activated_pending_fresh_runtime',
	'installed'   => true,
	'active'      => true,
);
$prepare_phase_result = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle(), array( 'materialize_dependencies' => true, 'runtime_lifecycle_phase' => 'prepare' ) );
$assert( ! is_wp_error( $prepare_phase_result ), 'pending-readiness-during-prepare-phase-does-not-fail' );
$assert( 'activated_pending_fresh_runtime' === ( $prepare_phase_result['products']['woocommerce']['status'] ?? '' ), 'prepare-phase-report-carries-pending-status-for-the-resume-checkpoint' );

$not_ready = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle(), array( 'materialize_dependencies' => true ) );
$assert( is_wp_error( $not_ready ), 'pending-readiness-outside-prepare-phase-returns-wp-error' );
$assert( 'static_site_importer_required_runtime_dependency_missing' === ( $not_ready->get_error_code() ?? '' ), 'pending-readiness-uses-the-missing-code-not-the-failed-code' );
$assert( str_contains( $not_ready->get_error_message(), 'installed and activated' ), 'pending-readiness-message-affirms-successful-install-and-activation' );
$assert( str_contains( $not_ready->get_error_message(), 'fresh' ), 'pending-readiness-message-explains-a-fresh-request-is-needed' );
$assert( ! str_contains( $not_ready->get_error_message(), 'could not install' ) && ! str_contains( $not_ready->get_error_message(), 'could not activate' ), 'pending-readiness-message-never-claims-install-or-activation-failed' );
$assert( 'pending_fresh_runtime' === ( $not_ready->get_error_data()['readiness_stage'] ?? '' ), 'pending-readiness-stage-is-recorded' );

// 4. Once available, no error at all — the normal resumed-request outcome.
$GLOBALS['ssi_next_report'] = array(
	'slug'        => 'woocommerce',
	'plugin_file' => 'woocommerce/woocommerce.php',
	'status'      => 'already_available',
	'installed'   => true,
	'active'      => true,
);
$available_adapter = $adapter;
$available_adapter['dependencies'][0]['availability_callback'] = static fn (): bool => true;
$available_lifecycle = array( 'dependencies' => array( 'products' => array( 'adapter' => $available_adapter, 'required' => true ) ) );
$ready = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $available_lifecycle, array( 'materialize_dependencies' => true ) );
$assert( ! is_wp_error( $ready ), 'available-dependency-materializes-without-error' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL: \n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dependency manager readiness diagnostics smoke test passed.\n";
