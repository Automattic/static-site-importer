<?php
/**
 * Smoke coverage for entity materializer registry Woo dependency behavior.
 *
 * Run from the repository root:
 * php tests/smoke-entity-materializer-registry.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( string $post_type ): bool {
			unset( $post_type );
			return false;
		}
	}

	if ( ! function_exists( 'taxonomy_exists' ) ) {
		function taxonomy_exists( string $taxonomy ): bool {
			unset( $taxonomy );
			return false;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message, private $data = null ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-dependency-manager.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();
	$intent  = array(
		'present'       => true,
		'sources'       => array( 'products_manifest' ),
		'product_count' => 1,
	);

	$dependencies = Static_Site_Importer_Dependency_Manager::dependency_rows( $adapter, $intent, false );
	$assert( isset( $dependencies['woocommerce'] ), 'woocommerce-dependency-row-key-preserved' );
	$assert( false === ( $dependencies['woocommerce']['active'] ?? true ), 'missing-woocommerce-reports-inactive' );
	$assert( false === ( $dependencies['woocommerce']['waived'] ?? true ), 'missing-woocommerce-not-waived-by-default' );
	$assert( array( 'WC_Product_Simple', 'product_post_type', 'product_cat_taxonomy' ) === ( $dependencies['woocommerce']['missing_apis'] ?? array() ), 'missing-woocommerce-api-list-preserved' );
	$assert( array( 'products_manifest' ) === ( $dependencies['woocommerce']['sources'] ?? array() ), 'dependency-sources-preserved' );
	$assert( 1 === ( $dependencies['woocommerce']['product_count'] ?? 0 ), 'dependency-product-count-preserved' );
	$assert( false === Static_Site_Importer_Dependency_Manager::dependencies_available( $adapter ), 'adapter-dependencies-not-available-without-woo' );

	$waived_dependencies = Static_Site_Importer_Dependency_Manager::dependency_rows( $adapter, $intent, true );
	$assert( true === ( $waived_dependencies['woocommerce']['waived'] ?? false ), 'waived-row-records-waiver' );
	$dependency_plan = Static_Site_Importer_Dependency_Manager::dependency_plan(
		array( 'dependencies' => array( 'products' => array( 'adapter' => $adapter, 'required' => true ) ) ),
		str_repeat( 'a', 64 )
	);
	$assert( 'woocommerce' === ( $dependency_plan['entries'][0]['slug'] ?? '' ) && array( 'products' ) === ( $dependency_plan['entries'][0]['provenance']['declaration_ids'] ?? array() ), 'dependency-manager-projects-adapter-declarations-to-runtime-plan' );

	$seeding = Static_Site_Importer_Entity_Materializer_Registry::materialize(
		$adapter,
		array(
			'products' => array(
				array(
					'name'          => 'Rye Loaf',
					'slug'          => 'rye-loaf',
					'regular_price' => '12.00',
				),
			),
		)
	);
	$assert( 'skipped' === ( $seeding['status'] ?? '' ), 'missing-woocommerce-skips-seeding' );
	$assert( 'woocommerce_inactive' === ( $seeding['reason'] ?? '' ), 'missing-woocommerce-skip-reason-preserved' );
	$assert( 1 === ( $seeding['counts']['skipped'] ?? 0 ), 'missing-woocommerce-skipped-count-preserved' );
	$assert( 'woocommerce_inactive' === ( $seeding['products'][0]['reason'] ?? '' ), 'missing-woocommerce-product-row-reason-preserved' );

	$failing = Static_Site_Importer_Entity_Materializer_Registry::materialize(
		array( 'materializer' => static fn( array $manifest ) => new WP_Error( 'adapter_failed', 'Adapter failed.' ) ),
		array()
	);
	$assert( is_wp_error( $failing ), 'failing-adapter-wp-error-is-preserved' );
	$assert( 'adapter_failed' === $failing->get_error_code(), 'failing-adapter-error-code-is-preserved' );

	$duplicate_products = Static_Site_Importer_Entity_Materializer_Registry::validate_woo_products_manifest( array( 'schema_version' => 1, 'products' => array( array( 'name' => 'One', 'slug' => 'same', 'regular_price' => '10' ), array( 'name' => 'Two', 'slug' => 'same', 'regular_price' => '12' ) ) ) );
	$assert( ! empty( $duplicate_products['errors'] ), 'duplicate-product-slugs-reject-provider-result-ambiguity' );
	$duplicate_forms = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( array( 'source_path' => 'index.html', 'selector' => 'form', 'controls' => array( array( 'tag' => 'input', 'type' => 'email' ) ) ), array( 'source_path' => 'index.html', 'selector' => 'form', 'controls' => array( array( 'tag' => 'input', 'type' => 'email' ) ) ) ) ) );
	$assert( ! empty( $duplicate_forms['errors'] ), 'duplicate-form-identities-reject-provider-result-ambiguity' );

	$materializer_calls = 0;
	$bound_adapter      = array(
		'provider'     => 'test-provider',
		'waiver_arg'   => 'allow_missing_test_provider',
		'materializer' => static function ( array $manifest ) use ( &$materializer_calls ): array {
			++$materializer_calls;
			return array(
				'status' => 'completed',
				'counts' => array( 'mapped' => count( $manifest['forms'] ?? array() ) ),
				'forms'  => $manifest['forms'] ?? array(),
			);
		},
	);
	$bound_forms = array(
		'forms' => array(
			array( 'source_path' => 'about.html', 'selector' => 'form.desktop', 'bindings' => array( array( 'role' => 'form' ) ) ),
			array( 'source_path' => 'contact.html', 'selector' => 'form.mobile', 'bindings' => array( array( 'role' => 'form' ) ) ),
		),
	);
	$bound_lifecycle = array(
		'entities' => array(
			'forms' => array( 'adapter' => $bound_adapter, 'manifest' => $bound_forms, 'required' => false ),
		),
	);
	$bound_result = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $bound_lifecycle, array( 'seed_entities' => false ) );
	$assert( 1 === $materializer_calls && 2 === ( $bound_result['reports']['forms']['counts']['mapped'] ?? 0 ), 'bound-entities-materialize-without-opt-in-seeding' );

	$form_adapter = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();
	$missing_provider_lifecycle = array(
		'dependencies' => array(
			'forms' => array( 'adapter' => $form_adapter, 'required' => false ),
		),
		'entities' => array(
			'forms' => array( 'adapter' => $form_adapter, 'manifest' => $bound_forms, 'required' => false ),
		),
	);
	$missing_provider = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $missing_provider_lifecycle, array( 'materialize_dependencies' => false ) );
	$assert( is_wp_error( $missing_provider ) && 'static_site_importer_required_runtime_dependency_missing' === $missing_provider->get_error_code(), 'bound-entity-missing-provider-rejects-admission' );

	$manifest_id     = str_repeat( 'a', 64 );
	$manifest_entity = array(
		'source_path'        => 'contact.html',
		'selector'           => 'form.contact',
		'controls'           => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ) ),
		'bindings'           => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'contact.html', 'search_block_markup' => '<!-- wp:paragraph --><p>Contact</p><!-- /wp:paragraph -->', 'occurrence' => 1, 'role' => 'form' ) ),
		'layout_graph'       => array( 'hash' => 'layout-graph-sha256' ),
		'presentation_graph' => array( 'hash' => 'presentation-graph-sha256' ),
	);
	$manifest_lifecycle = array(
		'entities' => array(
			$manifest_id => array(
				'adapter'     => array(
					'capability' => 'test',
					'validator'  => static function ( array $manifest ): array {
						$forms = $manifest['forms'] ?? array();
						$valid = is_array( $forms ) && 2 === count( $forms );
						foreach ( $forms as $form ) {
							$valid = $valid && is_array( $form ) && 'contact.html' === ( $form['source_path'] ?? '' ) && ! empty( $form['selector'] ) && ! empty( $form['bindings'] ) && ! empty( $form['controls'] ) && isset( $form['layout_graph']['hash'], $form['presentation_graph']['hash'] );
						}
						return array( 'forms' => $forms, 'errors' => $valid ? array() : array( array( 'message' => 'expanded forms are incomplete' ) ) );
					},
				),
				'manifest'    => array( 'forms' => array() ),
				'declaration' => array(),
			),
		),
	);
	$manifest_declaration = array(
		'reconciliation_identity' => $manifest_id,
		'kind'                    => 'entity_collection',
		'type'                    => 'forms',
		'payload'                 => array( 'schema' => 'blocks-engine/runtime-entity-manifest/v1', 'entity_schema' => 'generic/forms/v1', 'entities' => array( array( 'content_hash' => str_repeat( 'b', 64 ) ) ) ),
	);
	$manifest_lifecycle['entities'][ $manifest_id ]['declaration'] = $manifest_declaration;
	$manifest_resolved = array(
		'runtime_declarations'      => array( $manifest_declaration ),
		'runtime_entity_resolution' => array(
			array( 'reconciliation_identity' => $manifest_id, 'kind' => 'entity_collection', 'type' => 'forms', 'entity_schema' => 'generic/forms/v1', 'entities' => array( $manifest_entity, array_merge( $manifest_entity, array( 'selector' => 'form.newsletter' ) ) ) ),
		),
	);
	$expanded_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $manifest_lifecycle, $manifest_resolved );
	$expanded_forms     = $expanded_lifecycle['entities'][ $manifest_id ]['manifest']['forms'] ?? array();
	$assert( ! is_wp_error( $expanded_lifecycle ) && 2 === count( $expanded_forms ) && 'contact.html' === ( $expanded_forms[0]['source_path'] ?? '' ) && 1 === count( $expanded_forms[0]['bindings'] ?? array() ) && 'email' === ( $expanded_forms[0]['controls'][0]['name'] ?? '' ) && 'layout-graph-sha256' === ( $expanded_forms[0]['layout_graph']['hash'] ?? '' ) && 'presentation-graph-sha256' === ( $expanded_forms[0]['presentation_graph']['hash'] ?? '' ), 'resolver-expanded-manifest-retains-all-entity-source-binding-control-and-graph-data' );
	$missing_resolution = $manifest_resolved;
	$missing_resolution['runtime_entity_resolution'] = array();
	$assert( is_wp_error( Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $manifest_lifecycle, $missing_resolution ) ), 'resolver-expanded-manifest-rejects-missing-entity-resolution' );
	$duplicate_resolution = $manifest_resolved;
	$duplicate_resolution['runtime_entity_resolution'][] = $duplicate_resolution['runtime_entity_resolution'][0];
	$assert( is_wp_error( Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $manifest_lifecycle, $duplicate_resolution ) ), 'resolver-expanded-manifest-rejects-duplicate-entity-resolution' );
	$mismatched_resolution = $manifest_resolved;
	$mismatched_resolution['runtime_entity_resolution'][0]['entity_schema'] = 'generic/products/v1';
	$assert( is_wp_error( Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $manifest_lifecycle, $mismatched_resolution ) ), 'resolver-expanded-manifest-rejects-mismatched-entity-schema' );
	$incomplete_entity_resolution = $manifest_resolved;
	$incomplete_entity_resolution['runtime_entity_resolution'][0]['entities'][1]['controls'] = array();
	$assert( is_wp_error( Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $manifest_lifecycle, $incomplete_entity_resolution ) ), 'resolver-expanded-manifest-rejects-incomplete-expanded-entity-data' );
	$legacy_lifecycle = array( 'entities' => array( 'legacy' => array( 'manifest' => array( 'forms' => array( $manifest_entity ) ) ) ) );
	$assert( $legacy_lifecycle === Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $legacy_lifecycle, array( 'runtime_declarations' => array() ) ), 'direct-payload-entity-lifecycle-remains-unchanged-without-manifests' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: entity materializer registry smoke passed (' . $assertions . " assertions)\n";
}
