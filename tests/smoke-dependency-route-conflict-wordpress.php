<?php
/**
 * WordPress-runtime regression for issue #2581.
 *
 * A required runtime dependency's install or activation may create native
 * content at one of the imported source's own routes (the WooCommerce
 * activation hook seeds shop/cart/checkout/my-account pages). With
 * overwrite disabled the import used to abort with post_conflict even
 * though the run itself had just created the colliding page. The run-owned
 * page record now crosses the continuation boundary inside the lifecycle
 * checkpoint, and preflight adopts exactly those pages.
 *
 * Drives the public CLI import path through every fresh-runtime
 * continuation (`static_site_importer_cli_run_import_host`), the same way
 * `wp static-site-importer import --request=...` runs:
 *
 * - adopt:    a two-page source (index.html + checkout/index.html) whose
 *             checkout route collides with a page the fixture dependency
 *             seeds on activation. The import must complete, keep the
 *             dependency-created page (same post ID), materialize the
 *             source content onto it, and record import provenance.
 * - conflict: a source whose /basket/ route collides with a page that
 *             existed before the run. The import must still fail with
 *             post_conflict and leave the pre-existing page untouched.
 *
 * Environment: STATIC_SITE_IMPORTER_WP_CLI (WordPress runtime), registered
 * in test-manifest.json. The fixture plugin and adapter mu-plugin are
 * written into the disposable site by this test itself.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

/**
 * A test-only provider that models the dependency behavior behind the
 * issue: activation seeds a native page at a route the source owns.
 */
function ssi_e2e_checkout_fixture_adapter(): array {
	return array(
		'id'                    => 'ssi_e2e_checkout_fixture',
		'capability'            => 'checkout_fixture_dependency',
		'rollback_contract_id'  => 'e2e-checkout-fixture/dependency',
		'materialization_stage' => 'before_pages',
		'dependencies'          => array(
			array(
				'type'                  => 'wp_org_plugin',
				'slug'                  => 'checkout-fixture',
				'plugin_file'           => 'checkout-fixture/checkout-fixture.php',
				'availability_callback' => static fn (): bool => class_exists( 'SSI_E2E_Checkout_Fixture_Active' ),
			),
		),
		'intent_callback'       => static function ( array $plan, array $args ): bool {
			unset( $args );
			foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
				if ( 1 === preg_match( '~(^|/)(checkout|basket)/index\.html$~', (string) ( $page['source_path'] ?? '' ) ) ) {
					return true;
				}
			}
			return false;
		},
	);
}

// The adapter joins the registry through the same filter real providers use,
// so every fresh runtime continuation sees it.
add_filter(
	'static_site_importer_entity_materializers',
	static function ( array $adapters ): array {
		$adapters['ssi_e2e_checkout_fixture'] = ssi_e2e_checkout_fixture_adapter();
		return $adapters;
	}
);

// The fixture dependency plugin: present but inactive until SSI installs
// and activates it as a required runtime dependency. Activation seeds the
// checkout route, exactly like WooCommerce seeding its own pages.
$plugin_dir = WP_PLUGIN_DIR . '/checkout-fixture';
if ( ! is_dir( $plugin_dir ) ) {
	wp_mkdir_p( $plugin_dir );
}
file_put_contents(
	$plugin_dir . '/checkout-fixture.php',
	'<?php' . "\n" .
	'/**' . "\n" .
	' * Plugin Name: Checkout Fixture Dependency' . "\n" .
	' * Description: Seeds a native checkout page on activation, modeling the WooCommerce behavior behind issue #2581.' . "\n" .
	' */' . "\n" .
	'class SSI_E2E_Checkout_Fixture_Active {}' . "\n" .
	'register_activation_hook( __FILE__, static function (): void {' . "\n" .
	"\tif ( null === get_page_by_path( 'checkout' ) ) {" . "\n" .
	"\t\t\$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'checkout', 'post_title' => 'Checkout', 'post_content' => 'Seeded by the checkout fixture dependency during activation.' ) );" . "\n" .
	"\t\tupdate_option( 'ssi_e2e_fixture_created_page', (int) \$page_id );" . "\n" .
	"\t}" . "\n" .
	'} );' . "\n"
);

// The adapter mu-plugin lets every fresh runtime continuation (and every
// step's own mu-plugins load) register the same test provider.
$mu_plugin = WPMU_PLUGIN_DIR . '/ssi-e2e-checkout-fixture-adapter.php';
if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
	wp_mkdir_p( WPMU_PLUGIN_DIR );
}
if ( ! is_file( $mu_plugin ) ) {
	file_put_contents(
		$mu_plugin,
		'<?php' . "\n" .
		'add_filter( \'static_site_importer_entity_materializers\', static function ( array $adapters ): array {' . "\n" .
		"\t\$adapters['ssi_e2e_checkout_fixture'] = array(" . "\n" .
		"\t\t'id' => 'ssi_e2e_checkout_fixture'," . "\n" .
		"\t\t'capability' => 'checkout_fixture_dependency'," . "\n" .
		"\t\t'rollback_contract_id' => 'e2e-checkout-fixture/dependency'," . "\n" .
		"\t\t'materialization_stage' => 'before_pages'," . "\n" .
		"\t\t'dependencies' => array( array(" . "\n" .
		"\t\t\t'type' => 'wp_org_plugin'," . "\n" .
		"\t\t\t'slug' => 'checkout-fixture'," . "\n" .
		"\t\t\t'plugin_file' => 'checkout-fixture/checkout-fixture.php'," . "\n" .
		"\t\t\t'availability_callback' => static fn (): bool => class_exists( 'SSI_E2E_Checkout_Fixture_Active' )," . "\n" .
		"\t\t) )," . "\n" .
		"\t\t'intent_callback' => static function ( array \$plan, array \$args ): bool {" . "\n" .
		"\t\t\tunset( \$args );" . "\n" .
		"\t\t\tforeach ( is_array( \$plan['pages'] ?? null ) ? \$plan['pages'] : array() as \$page ) {" . "\n" .
		"\t\t\t\tif ( 1 === preg_match( '~(^|/)(checkout|basket)/index\.html$~', (string) ( \$page['source_path'] ?? '' ) ) ) {" . "\n" .
		"\t\t\t\t\treturn true;" . "\n" .
		"\t\t\t\t}" . "\n" .
		"\t\t\t}" . "\n" .
		"\t\t\treturn false;" . "\n" .
		"\t\t}," . "\n" .
		"\t);" . "\n" .
		"\treturn \$adapters;" . "\n" .
		'} );' . "\n"
	);
}

/** Stage a CLI request bundle for the given second page route. */
function ssi_e2e_stage_bundle( string $second_route, string $second_title, string $second_content ): string {
	$bundle_dir = sys_get_temp_dir() . '/ssi-e2e-route-conflict-' . bin2hex( random_bytes( 4 ) );
	wp_mkdir_p( $bundle_dir . '/source/checkout' );
	wp_mkdir_p( $bundle_dir . '/source/' . $second_route );
	file_put_contents(
		$bundle_dir . '/source/index.html',
		'<!doctype html><html><head><title>Fixture Site</title></head><body><main><h1>Welcome</h1><p>Hello from the fixture site.</p></main></body></html>'
	);
	file_put_contents(
		$bundle_dir . '/source/checkout/index.html',
		'<!doctype html><html><head><title>Checkout</title></head><body><main><h1>Checkout</h1><p>Complete your order.</p></main></body></html>'
	);
	file_put_contents(
		$bundle_dir . '/source/' . $second_route . '/index.html',
		'<!doctype html><html><head><title>' . $second_title . '</title></head><body><main><h1>' . $second_title . '</h1><p>' . $second_content . '</p></main></body></html>'
	);
	file_put_contents(
		$bundle_dir . '/request.json',
		wp_json_encode(
			array(
				'operation' => 'apply',
				'source'    => array(
					'type' => 'files',
					'ref'  => 'request-bundle:source',
				),
			)
		)
	);
	return $bundle_dir . '/request.json';
}

/** Run the canonical CLI import host through every continuation. */
function ssi_e2e_run_import( string $request_path ): array {
	$input = static_site_importer_cli_import_input( array(), array( 'request' => $request_path ) );
	if ( ! is_array( $input ) ) {
		return array(
			'schema'   => 'static-site-importer/import-cli-receipt/v1',
			'status'   => 'failed',
			'steps'    => 0,
			'response' => array( 'error' => array( 'code' => 'ssi_e2e_input_invalid' ) ),
		);
	}
	$receipt = static_site_importer_cli_run_import_host( $input );
	// Continuations wrote through fresh processes; drop this process's cached
	// rows and options before asserting on them.
	wp_cache_flush();
	return $receipt;
}

// ---------------------------------------------------------------------------
// Scenario adopt: the issue's reproduction. The dependency activation seeds
// /checkout/ mid-run; the import must adopt it, not abort.
// ---------------------------------------------------------------------------
update_option( 'ssi_e2e_fixture_created_page', 0 );
if ( is_callable( 'deactivate_plugins' ) && is_plugin_active( 'checkout-fixture/checkout-fixture.php' ) ) {
	deactivate_plugins( 'checkout-fixture/checkout-fixture.php' );
}
$adopt_request  = ssi_e2e_stage_bundle( 'unused', 'unused', 'unused' );
$adopt_receipt  = ssi_e2e_run_import( $adopt_request );
$adopt_response = is_array( $adopt_receipt['response'] ?? null ) ? $adopt_receipt['response'] : array();

$assert( 'completed' === ( $adopt_receipt['status'] ?? '' ), 'adopt-import-completes: ' . wp_json_encode( array( 'status' => $adopt_receipt['status'] ?? null, 'error' => $adopt_response['error'] ?? null, 'steps' => $adopt_receipt['steps'] ?? null ) ) );

$dependency_page_id = (int) get_option( 'ssi_e2e_fixture_created_page' );
$assert( $dependency_page_id > 0, 'dependency-activation-seeded-a-checkout-page' );

$checkout_page = get_page_by_path( 'checkout' );
$assert( $checkout_page instanceof WP_Post && $dependency_page_id === (int) $checkout_page->ID, 'source-checkout-route-adopts-the-dependency-created-page: ' . wp_json_encode( array( 'dependency_created' => $dependency_page_id, 'found' => $checkout_page instanceof WP_Post ? (int) $checkout_page->ID : null ) ) );

if ( $checkout_page instanceof WP_Post ) {
	$assert( str_contains( (string) $checkout_page->post_content, 'Complete your order' ), 'adopted-page-materializes-the-source-checkout-content: ' . substr( (string) $checkout_page->post_content, 0, 200 ) );
	$provenance = json_decode( (string) get_post_meta( (int) $checkout_page->ID, '_static_site_importer_provenance', true ), true );
	$assert( is_array( $provenance ) && 'checkout/index.html' === ( $provenance['source_path'] ?? '' ) && '' !== (string) ( $provenance['import_run_id'] ?? '' ), 'adopted-page-records-import-provenance: ' . wp_json_encode( $provenance ) );
}
$index_pages = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 's' => 'Hello from the fixture site.' ) );
$assert( ! empty( $index_pages ), 'index-content-materialized' );

// ---------------------------------------------------------------------------
// Scenario conflict: a page that existed before the run must still reject
// the import, dependency or not.
// ---------------------------------------------------------------------------
$pre_existing_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => 'basket',
		'post_title'   => 'Basket',
		'post_content' => 'Pre-existing basket page.',
	)
);
$basket_request   = ssi_e2e_stage_bundle( 'basket', 'Basket', 'Source basket route.' );
$basket_receipt   = ssi_e2e_run_import( $basket_request );
$basket_response  = is_array( $basket_receipt['response'] ?? null ) ? $basket_receipt['response'] : array();
$basket_error     = is_array( $basket_response['error'] ?? null ) ? $basket_response['error'] : array();
$basket_page      = get_page_by_path( 'basket' );
$basket_codes     = array( (string) ( $basket_error['code'] ?? '' ) );
foreach ( is_array( $basket_response['errors'] ?? null ) ? $basket_response['errors'] : array() as $basket_entry ) {
	$basket_codes[] = (string) ( $basket_entry['reason_code'] ?? $basket_entry['code'] ?? '' );
}
foreach ( is_array( $basket_response['import_report_summary']['failure_reasons'] ?? null ) ? $basket_response['import_report_summary']['failure_reasons'] : array() as $basket_reason ) {
	$basket_codes[] = (string) $basket_reason;
}
$basket_codes = array_values( array_filter( array_unique( $basket_codes ) ) );

$assert( 'failed' === ( $basket_receipt['status'] ?? '' ), 'conflict-import-fails: ' . wp_json_encode( array( 'status' => $basket_receipt['status'] ?? null ) ) );
$assert( in_array( 'post_conflict', $basket_codes, true ), 'conflict-failure-is-post_conflict: ' . wp_json_encode( array( 'codes' => $basket_codes, 'error' => $basket_error ) ) );
$assert( $basket_page instanceof WP_Post && (int) $pre_existing_id === (int) $basket_page->ID && str_contains( (string) $basket_page->post_content, 'Pre-existing basket page.' ), 'pre-existing-page-left-untouched' );
$assert( '' === (string) get_post_meta( (int) $pre_existing_id, '_static_site_importer_provenance', true ), 'pre-existing-page-has-no-import-provenance' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL: \n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dependency route conflict WordPress regression passed.\n";
exit( 0 );
