<?php
/**
 * Smoke coverage for the product-grid fallback materialization path.
 *
 * Consumes the Blocks Engine `html_product_grid_fallback` finding, normalizes it
 * into a products-manifest/v1, validates + seeds it through the WooCommerce shop
 * adapter, and confirms the gate-closure signal is stamped onto seeded findings.
 *
 * Run from the repository root:
 * php tests/smoke-product-materializer.php
 *
 * @package StaticSiteImporter
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $title ) {
			$title = strtolower( trim( (string) $title ) );
			$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
			return trim( (string) $title, '-' );
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $value ) {
			return (string) $value;
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

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data );
		}
	}

	// --- WooCommerce runtime mock ------------------------------------------------
	// Captures the seeded simple products so the smoke test can assert the prices
	// the seeder wrote without a live WooCommerce install.
	$GLOBALS['ssi_seeded_products'] = array();
	$GLOBALS['ssi_next_product_id'] = 1000;

	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( $type ) {
			return 'product' === $type;
		}
	}
	if ( ! function_exists( 'taxonomy_exists' ) ) {
		function taxonomy_exists( $taxonomy ) {
			return 'product_cat' === $taxonomy;
		}
	}
	if ( ! function_exists( 'get_page_by_path' ) ) {
		function get_page_by_path( $path, $output = OBJECT, $post_type = 'post' ) {
			unset( $path, $output, $post_type );
			return null;
		}
	}

	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		class WC_Product_Simple {
			/** @var array<string,mixed> */
			public $data = array();
			public function set_name( $value ) {
				$this->data['name'] = $value; }
			public function set_slug( $value ) {
				$this->data['slug'] = $value; }
			public function set_status( $value ) {
				$this->data['status'] = $value; }
			public function set_description( $value ) {
				$this->data['description'] = $value; }
			public function set_short_description( $value ) {
				$this->data['short_description'] = $value; }
			public function set_regular_price( $value ) {
				$this->data['regular_price'] = $value; }
			public function set_sale_price( $value ) {
				$this->data['sale_price'] = $value; }
			public function set_stock_status( $value ) {
				$this->data['stock_status'] = $value; }
			public function set_manage_stock( $value ) {
				$this->data['manage_stock'] = $value; }
			public function set_stock_quantity( $value ) {
				$this->data['stock_quantity'] = $value; }
			public function save() {
				$id                                          = $GLOBALS['ssi_next_product_id']++;
				$this->data['id']                            = $id;
				$GLOBALS['ssi_seeded_products'][ (string) ( $this->data['slug'] ?? '' ) ] = $this->data;
				return $id;
			}
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

	// --- Price normalization is generic and locale-tolerant -----------------
	$price_cases = array(
		'$24'         => '24',
		'$1,299.00'   => '1299.00',
		'€18'         => '18',
		'18.00'       => '18.00',
		'18'          => '18',
		'€18,50'      => '18.50',
		'1,299'       => '1299',
		'1.299,00 €'  => '1299.00',
		'$1,234,567'  => '1234567',
		'  $0.99 '    => '0.99',
		'12.5'        => '12.50',
		'1,234.567'   => '1234.57',
		'49.999'      => '49999',
		''            => '',
		'free'        => '',
	);
	foreach ( $price_cases as $input => $expected ) {
		$actual = Static_Site_Importer_Report_Diagnostics::normalize_product_price( (string) $input );
		$assert( $expected === $actual, 'price-normalize-' . sanitize_key( (string) $input ), 'input "' . $input . '" => "' . $actual . '" expected "' . $expected . '"' );
	}

	// --- Native html_product_grid_fallback row enriches into a product finding
	$enrich   = new ReflectionMethod( 'Static_Site_Importer_Report_Diagnostics', 'diagnostic_from_conversion_report_fallback' );
	$enriched = $enrich->invoke(
		null,
		array(
			'kind'               => 'html_product_grid_fallback',
			'reason'             => 'commerce_requires_runtime',
			'source_path'        => 'website/shop.html',
			'container_selector' => 'ul.products',
			'products'           => array(
				array(
					'name'             => 'Aero Mug',
					'price'            => '$24',
					'sale_price'       => null,
					'description'      => 'Double-walled travel mug.',
					'image'            => array( 'src' => 'https://cdn.example.com/mug.jpg', 'alt' => 'Aero Mug' ),
					'has_cart_control' => true,
					'source_selector'  => 'ul.products li:nth-child(1)',
				),
			),
		)
	);
	$assert( 'html_product_grid_fallback' === ( $enriched['diagnostic_code'] ?? '' ), 'enrich-carries-diagnostic-code' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === ( $enriched['loss_class'] ?? '' ), 'enrich-loss-class-preserved-runtime-island' );
	$assert( 'ul.products' === ( $enriched['container_selector'] ?? '' ), 'enrich-carries-container-selector' );
	$assert( isset( $enriched['products'][0]['name'] ) && 'Aero Mug' === $enriched['products'][0]['name'], 'enrich-carries-products' );
	$assert( 1 === ( $enriched['product_count'] ?? 0 ), 'enrich-product-count' );

	// --- materialize_product_findings: manifest + seeding + gate-closure -----
	$report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/shop.html' );
	$report['diagnostics'][] = array(
		'type'               => 'unsupported_html_fallback',
		'diagnostic_code'    => 'html_product_grid_fallback',
		'loss_class'         => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'        => 'website/shop.html',
		'container_selector' => 'ul.products',
		'selector'           => 'ul.products',
		'products'           => array(
			array(
				'name'             => 'Aero Mug',
				'price'            => '$24',
				'sale_price'       => null,
				'description'      => 'Double-walled travel mug.',
				'image'            => array( 'src' => 'https://cdn.example.com/mug.jpg', 'alt' => 'Aero Mug' ),
				'has_cart_control' => true,
				'source_selector'  => 'ul.products li:nth-child(1)',
			),
			array(
				'name'             => 'Trail Pack',
				'price'            => '$1,299.00',
				'sale_price'       => '$999.00',
				'description'      => null,
				'image'            => null,
				'has_cart_control' => true,
				'source_selector'  => 'ul.products li:nth-child(2)',
			),
		),
	);

	$seeding = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $report, array() );
	$assert( 'woocommerce' === ( $seeding['provider'] ?? '' ), 'materialize-provider-woocommerce' );
	$assert( 1 === ( $seeding['finding_count'] ?? 0 ), 'materialize-one-finding' );
	$assert( 2 === ( $seeding['product_count'] ?? 0 ), 'materialize-two-products' );
	$assert( 'completed' === ( $seeding['status'] ?? '' ), 'materialize-status-completed' );
	$assert( empty( $seeding['validation_errors'] ), 'materialize-manifest-valid', (string) wp_json_encode( $seeding['validation_errors'] ?? array() ) );

	// Manifest shape is products-manifest/v1.
	$manifest = $seeding['manifest'] ?? array();
	$assert( 1 === ( $manifest['schema_version'] ?? 0 ), 'manifest-schema-version-1' );
	$rows = $manifest['products'] ?? array();
	$assert( 2 === count( $rows ), 'manifest-two-rows' );
	$assert( 'Aero Mug' === ( $rows[0]['name'] ?? '' ), 'manifest-row0-name' );
	$assert( 'aero-mug' === ( $rows[0]['slug'] ?? '' ), 'manifest-row0-slug' );
	$assert( '24' === ( $rows[0]['regular_price'] ?? '' ), 'manifest-row0-regular-price' );
	$assert( ! isset( $rows[0]['sale_price'] ), 'manifest-row0-no-sale-price' );
	$assert( 'https://cdn.example.com/mug.jpg' === ( $rows[0]['image'] ?? '' ), 'manifest-row0-image-src' );
	$assert( in_array( 'ul.products li:nth-child(1)', $rows[0]['source_selectors'] ?? array(), true ), 'manifest-row0-source-selectors' );
	$assert( '1299.00' === ( $rows[1]['regular_price'] ?? '' ), 'manifest-row1-regular-price' );
	$assert( '999.00' === ( $rows[1]['sale_price'] ?? '' ), 'manifest-row1-sale-price' );

	// Seeder created real (mocked) products with the normalized prices.
	$assert( 2 === ( $seeding['counts']['created'] ?? 0 ), 'seeder-created-two' );
	$assert( isset( $GLOBALS['ssi_seeded_products']['aero-mug'] ), 'seeder-product-aero-mug' );
	$assert( '24' === ( $GLOBALS['ssi_seeded_products']['aero-mug']['regular_price'] ?? '' ), 'seeder-aero-mug-price' );
	$assert( isset( $GLOBALS['ssi_seeded_products']['trail-pack'] ), 'seeder-product-trail-pack' );
	$assert( '1299.00' === ( $GLOBALS['ssi_seeded_products']['trail-pack']['regular_price'] ?? '' ), 'seeder-trail-pack-price' );
	$assert( '999.00' === ( $GLOBALS['ssi_seeded_products']['trail-pack']['sale_price'] ?? '' ), 'seeder-trail-pack-sale-price' );

	// Gate-closure: the seeded finding receives the runtime-mapped signal.
	$assert( 1 === ( $seeding['mapped_count'] ?? 0 ), 'materialize-finding-mapped-count' );
	$finding = $report['diagnostics'][0];
	$assert( true === ( $finding['runtime_mapped'] ?? false ), 'finding-runtime-mapped' );
	$assert( 'woocommerce' === ( $finding['mapped_provider'] ?? '' ), 'finding-mapped-provider' );
	$assert( 'acceptable_preservation' === ( $finding['acceptability'] ?? '' ), 'finding-acceptable-preservation' );
	$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === Static_Site_Importer_Diagnostic_Loss_Classes::classify( $finding ), 'finding-stays-preserved-runtime-island' );
	$assert( is_array( $report['product_finding_seeding'] ?? null ), 'report-records-product-finding-seeding' );

	// --- No product findings => skipped report ------------------------------
	$empty_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/about.html' );
	$empty_seed   = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $empty_report, array() );
	$assert( 'skipped' === ( $empty_seed['status'] ?? '' ), 'no-findings-skipped' );
	$assert( 'no_product_findings' === ( $empty_seed['reason'] ?? '' ), 'no-findings-reason' );

	// --- product_grid_finding_indexes detects both `kind` and `diagnostic_code`
	$indexes = Static_Site_Importer_Report_Diagnostics::product_grid_finding_indexes(
		array(
			array( 'diagnostic_code' => 'html_form_fallback' ),
			array( 'kind' => 'html_product_grid_fallback' ),
			array( 'diagnostic_code' => 'html_product_grid_fallback' ),
		)
	);
	$assert( array( 1, 2 ) === $indexes, 'finding-indexes-detect-kind-and-code' );

	if ( empty( $failures ) ) {
		echo 'PASS smoke-product-materializer.php (' . $assertions . " assertions)\n";
		exit( 0 );
	}

	echo 'FAILURES (' . count( $failures ) . ' of ' . $assertions . " assertions):\n";
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}
