<?php
/**
 * Smoke coverage for the configurable form provider layer and Jetpack form adapter.
 *
 * Run from the repository root:
 * php tests/smoke-form-materializer.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	$GLOBALS['ssi_test_hooks'] = array();

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback ): void {
			$GLOBALS['ssi_test_hooks'][ $hook ][] = $callback;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			foreach ( $GLOBALS['ssi_test_hooks'][ $hook ] ?? array() as $callback ) {
				$value = $callback( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			unset( $name );
			return $default;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-loss-classes.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	// --- Default provider selection -----------------------------------------
	$assert( 'jetpack' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-default-provider-jetpack' );
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-default-provider-woocommerce' );

	$form_adapter = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'jetpack_contact_form' === ( $form_adapter['id'] ?? '' ), 'form-adapter-resolves-jetpack' );
	$assert( 'form' === ( $form_adapter['capability'] ?? '' ), 'form-adapter-capability' );
	$assert( 'allow_missing_jetpack' === ( $form_adapter['waiver_arg'] ?? '' ), 'form-adapter-waiver' );

	// --- Woo path unaffected -------------------------------------------------
	$product_adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
	$assert( 'woocommerce_simple_product' === ( $product_adapter['id'] ?? '' ), 'product-adapter-unchanged' );
	$assert( 'shop' === ( $product_adapter['capability'] ?? '' ), 'product-adapter-capability-shop' );
	$assert( 'allow_missing_woocommerce' === ( $product_adapter['waiver_arg'] ?? '' ), 'product-adapter-waiver-unchanged' );

	// --- Forms manifest validation rejects submit-only forms ----------------
	$submit_only = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest(
		array( 'forms' => array( array( 'selector' => 'form#x', 'controls' => array( array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ) ) ) )
	);
	$assert( array() === $submit_only['forms'], 'submit-only-form-rejected' );
	$assert( ! empty( $submit_only['errors'] ), 'submit-only-form-error-recorded' );

	// --- Jetpack form seeder maps controls to contact-form blocks -----------
	$forms_manifest = array(
		'forms' => array(
			array(
				'selector' => 'form.contact',
				'form'     => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
				'controls' => array(
					array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Your name', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true ),
					array( 'tag' => 'input', 'type' => 'tel', 'name' => 'phone', 'label' => 'Phone' ),
					array( 'tag' => 'select', 'type' => 'select', 'name' => 'topic', 'label' => 'Topic', 'options' => array( array( 'label' => 'Sales' ), array( 'label' => 'Support' ) ) ),
					array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message', 'label' => 'Message' ),
					array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send message' ),
				),
			),
		),
	);
	$seed = Static_Site_Importer_Form_Seeder::seed( $forms_manifest );
	$assert( 'completed' === ( $seed['status'] ?? '' ), 'seed-status-completed' );
	$assert( 1 === ( $seed['counts']['mapped'] ?? 0 ), 'seed-one-form-mapped' );
	$row    = $seed['forms'][0] ?? array();
	$markup = (string) ( $row['block_markup'] ?? '' );
	$assert( true === ( $row['runtime_mapped'] ?? false ), 'seed-form-runtime-mapped' );
	$assert( 5 === ( $row['field_count'] ?? 0 ), 'seed-five-fields-mapped' );
	$assert( str_contains( $markup, 'wp:jetpack/contact-form' ), 'markup-contact-form' );
	$assert( str_contains( $markup, 'wp:jetpack/field-text' ), 'markup-field-text' );
	$assert( str_contains( $markup, 'wp:jetpack/field-email' ), 'markup-field-email' );
	$assert( str_contains( $markup, 'wp:jetpack/field-telephone' ), 'markup-field-telephone' );
	$assert( str_contains( $markup, 'wp:jetpack/field-select' ), 'markup-field-select' );
	$assert( str_contains( $markup, 'wp:jetpack/field-textarea' ), 'markup-field-textarea' );
	$assert( str_contains( $markup, 'wp:jetpack/button' ), 'markup-submit-button' );
	$assert( str_contains( $markup, 'hello@example.com' ), 'markup-mailto-recipient' );
	$assert( str_contains( $markup, '"options":["Sales","Support"]' ), 'markup-select-options' );

	// --- Native html_form_fallback row is enriched into a form finding -------
	$enrich   = new ReflectionMethod( 'Static_Site_Importer_Report_Diagnostics', 'diagnostic_from_conversion_report_fallback' );
	$enriched = $enrich->invoke(
		null,
		array(
			'diagnostic_code' => 'html_form_fallback',
			'reason'          => 'form_requires_runtime',
			'source_path'     => 'website/index.html',
			'selector'        => 'form.contact',
			'tag'             => 'form',
			'form'            => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
			'controls'        => array(
				array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ),
			),
			'control_count'   => 1,
		)
	);
	$assert( 'html_form_fallback' === ( $enriched['diagnostic_code'] ?? '' ), 'enrich-carries-diagnostic-code' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === ( $enriched['loss_class'] ?? '' ), 'enrich-loss-class-preserved-runtime-island' );
	$assert( isset( $enriched['form']['action'] ) && 'mailto:hello@example.com' === $enriched['form']['action'], 'enrich-carries-form-metadata' );
	$assert( isset( $enriched['controls'][0]['type'] ) && 'email' === $enriched['controls'][0]['type'], 'enrich-carries-controls' );
	$assert( 'form' === ( $enriched['tag'] ?? '' ), 'enrich-tag-form' );

	// --- Gate loop: a mapped form finding receives the runtime-mapped signal --
	$report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	$report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.contact',
		'tag'             => 'form',
		'form'            => array( 'action' => 'mailto:hello@example.com', 'method' => 'post' ),
		'controls'        => array(
			array( 'tag' => 'input', 'type' => 'text', 'label' => 'Your name', 'required' => true ),
			array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email' ),
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
		),
	);
	// A second form with no mappable controls must stay unmapped (unacceptable loss).
	$report['diagnostics'][] = array(
		'type'            => 'unsupported_html_fallback',
		'diagnostic_code' => 'html_form_fallback',
		'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'     => 'website/index.html',
		'selector'        => 'form.search-only',
		'tag'             => 'form',
		'controls'        => array(
			array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Go' ),
		),
	);

	$seeding = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $report, array() );
	$assert( 'jetpack' === ( $seeding['provider'] ?? '' ), 'materialize-provider-jetpack' );
	$assert( 2 === ( $seeding['form_count'] ?? 0 ), 'materialize-counts-two-form-findings' );
	$assert( 1 === ( $seeding['mapped_count'] ?? 0 ), 'materialize-one-form-mapped' );

	$mapped   = $report['diagnostics'][0];
	$unmapped = $report['diagnostics'][1];
	$assert( true === ( $mapped['runtime_mapped'] ?? false ), 'finding-runtime-mapped-set' );
	$assert( 'jetpack' === ( $mapped['mapped_provider'] ?? '' ), 'finding-mapped-provider' );
	$assert( 'jetpack/contact-form' === ( $mapped['block_name'] ?? '' ), 'finding-block-name' );
	$assert( 'acceptable_preservation' === ( $mapped['acceptability'] ?? '' ), 'finding-acceptable-preservation' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $mapped ), 'finding-stays-preserved-runtime-island' );
	$assert( empty( $unmapped['runtime_mapped'] ), 'unmappable-form-stays-unsignaled' );

	// --- Provider override routes to a different registered adapter ----------
	add_filter(
		'static_site_importer_entity_materializers',
		static function ( array $adapters ): array {
			$adapters['gravity_forms_adapter'] = array(
				'id'         => 'gravity_forms_adapter',
				'capability' => 'form',
				'provider'   => 'gravity_forms',
				'waiver_arg' => 'allow_missing_gravity_forms',
			);
			return $adapters;
		}
	);
	add_filter( 'ssi_form_plugin', static fn ( string $provider ): string => 'gravity_forms' );

	$assert( 'gravity_forms' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' ), 'form-provider-override' );
	$overridden = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$assert( 'gravity_forms_adapter' === ( $overridden['id'] ?? '' ), 'form-adapter-routes-to-override' );
	// Shop capability stays on the default provider despite the form override.
	$assert( 'woocommerce' === Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' ), 'shop-provider-unaffected-by-form-override' );

	if ( empty( $failures ) ) {
		echo 'PASS smoke-form-materializer.php (' . $assertions . " assertions)\n";
		exit( 0 );
	}

	echo 'FAILURES (' . count( $failures ) . ' of ' . $assertions . " assertions):\n";
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}
