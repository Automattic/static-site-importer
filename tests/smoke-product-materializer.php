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
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ) {
			return $value instanceof WP_Error; }
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $text ) {
			return strip_tags( (string) $text );
		}
	}

	// Product grafts depend on WordPress Core for canonical anchor serialization.
	$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: '/Users/chubes/Studio/intelligence-chubes4';
	$parser  = rtrim( $wp_root, '/\\' ) . '/wp-includes/class-wp-block-parser.php';
	$blocks  = rtrim( $wp_root, '/\\' ) . '/wp-includes/blocks.php';
	if ( is_readable( $parser ) && is_readable( $blocks ) ) {
		require_once $parser;
		require_once $blocks;
	}
	if ( ! function_exists( 'serialize_blocks' ) ) {
		fwrite( STDERR, "SKIP: WordPress block serialization is unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
		exit( 0 );
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
	if ( ! function_exists( 'term_exists' ) ) {
		function term_exists( $term, $taxonomy = '' ) {
			unset( $term, $taxonomy );
			return null; }
	}
	if ( ! function_exists( 'wp_insert_term' ) ) {
		function wp_insert_term( $term, $taxonomy = '' ) {
			unset( $term, $taxonomy );
			return array( 'term_id' => 1 ); }
	}
	if ( ! function_exists( 'wp_set_object_terms' ) ) {
		function wp_set_object_terms( $object_id, $terms, $taxonomy = '' ) {
			unset( $object_id, $taxonomy );
			return $terms; }
	}
	if ( ! function_exists( 'wp_delete_post' ) ) {
		function wp_delete_post( $post_id, $force_delete = false ) {
			unset( $post_id, $force_delete );
			return true; }
	}
	if ( ! function_exists( 'get_term' ) ) {
		function get_term( $term, $taxonomy = '' ) {
			unset( $term, $taxonomy );
			return null; }
	}
	if ( ! function_exists( 'wp_delete_term' ) ) {
		function wp_delete_term( $term, $taxonomy = '' ) {
			unset( $term, $taxonomy );
			return true; }
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
				$id               = $GLOBALS['ssi_next_product_id']++;
				$this->data['id'] = $id;
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
		'$24'        => '24',
		'$1,299.00'  => '1299.00',
		'€18'        => '18',
		'18.00'      => '18.00',
		'18'         => '18',
		'€18,50'     => '18.50',
		'1,299'      => '1299',
		'1.299,00 €' => '1299.00',
		'$1,234,567' => '1234567',
		'  $0.99 '   => '0.99',
		'12.5'       => '12.50',
		'1,234.567'  => '1234.57',
		'49.999'     => '49999',
		''           => '',
		'free'       => '',
	);
	foreach ( $price_cases as $input => $expected ) {
		$actual = Static_Site_Importer_Report_Diagnostics::normalize_product_price( (string) $input );
		$assert( $expected === $actual, 'price-normalize-' . sanitize_key( (string) $input ), 'input "' . $input . '" => "' . $actual . '" expected "' . $expected . '"' );
	}

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
				'image'            => array(
					'src' => 'https://cdn.example.com/mug.jpg',
					'alt' => 'Aero Mug',
				),
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

	// --- Plain add-to-cart controls are grafted to Woo-owned shortcodes -------
	$button_region                 = '<!-- wp:group --><div class="wp-block-group">'
		. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Add to cart</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
		. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Buy now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
		. '</div><!-- /wp:group -->';
	$graft_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/shop.html' );
	$graft_report['diagnostics'][] = array(
		'type'               => 'unsupported_html_fallback',
		'diagnostic_code'    => 'html_product_grid_fallback',
		'loss_class'         => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
		'source_path'        => 'website/shop.html',
		'container_selector' => 'ul.products',
		'selector'           => 'ul.products',
		'readable_blocks'    => array(
			array(
				'blockName'    => 'core/group',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerContent' => array( '<div class="wp-block-group"><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Add to cart</a></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Buy now</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div>' ),
			),
		),
		'products'           => array(
			array(
				'name'             => 'Aero Mug',
				'price'            => '$24',
				'has_cart_control' => true,
			),
			array(
				'name'             => 'Trail Pack',
				'price'            => '$1,299.00',
				'has_cart_control' => true,
			),
		),
	);
	$graft_report['diagnostics'][0]['readable_blocks'][0]['attrs'] = array(
		'backslash' => 'C:\\products\\shop',
		'delimiter' => 'before -- after',
		'angle'     => '<cart>',
		'ampersand' => 'cart & checkout',
		'quote'     => 'Click "buy"',
	);
	$button_region                 = serialize_blocks( $graft_report['diagnostics'][0]['readable_blocks'] );
	$page_contents                 = array( 'website/shop.html' => $button_region );
	$graft_seeding                 = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $graft_report, array(), $page_contents );
	$assert( 1 === ( $graft_seeding['shortcode_grafted_count'] ?? 0 ), 'shortcode-grafted-count' );
	$assert( true === ( $graft_report['diagnostics'][0]['product_shortcode_grafted'] ?? false ), 'finding-product-shortcode-grafted' );
	$assert( str_contains( $page_contents['website/shop.html'], '<!-- wp:shortcode -->[add_to_cart id="' ), 'page-content-has-add-to-cart-shortcode' );
	$assert( ! str_contains( $page_contents['website/shop.html'], '>Add to cart<' ), 'page-content-removes-static-add-to-cart' );
	$assert( ! str_contains( $page_contents['website/shop.html'], '>Buy now<' ), 'page-content-removes-static-buy-now' );
	$assert( str_contains( $button_region, '\\u005c' ) && str_contains( $button_region, '\\u002d\\u002d' ) && str_contains( $button_region, '\\u003c' ) && str_contains( $button_region, '\\u003e' ) && str_contains( $button_region, '\\u0026' ) && str_contains( $button_region, '\\u0022' ), 'product-graft-anchor-uses-core-sensitive-attribute-serialization' );

	// A resolvable product finding cannot consume an identical region on another page.
	$owned_graft_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/owned-shop.html' );
	$owned_graft_report['diagnostics'][] = $graft_report['diagnostics'][0];
	$owned_graft_report['diagnostics'][0]['source_path'] = 'website/owned-shop.html';
	$owned_graft_contents = array(
		'website/owned-shop.html' => '<!-- wp:paragraph --><p>owner anchor is absent</p><!-- /wp:paragraph -->',
		'website/other-shop.html' => $button_region,
	);
	$owned_graft_seeding = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $owned_graft_report, array(), $owned_graft_contents );
	$owned_graft_diag    = array_values( array_filter( $owned_graft_report['diagnostics'], static fn ( array $diagnostic ): bool => 'product_add_to_cart_graft_unanchorable' === ( $diagnostic['type'] ?? '' ) ) );
	$assert( 0 === ( $owned_graft_seeding['shortcode_grafted_count'] ?? -1 ) && false === ( $owned_graft_report['diagnostics'][0]['product_shortcode_grafted'] ?? true ), 'product-graft-resolved-source-missing-anchor-is-not-grafted' );
	$assert( 1 === count( $owned_graft_diag ) && 'fallback_region_not_found_in_post_content' === ( $owned_graft_diag[0]['reason'] ?? '' ) && 'website/owned-shop.html' === ( $owned_graft_diag[0]['source_path'] ?? '' ), 'product-graft-resolved-source-emits-bounded-owner-diagnostic' );
	$assert( str_contains( $owned_graft_contents['website/other-shop.html'], '>Add to cart<' ) && ! str_contains( $owned_graft_contents['website/other-shop.html'], '[add_to_cart id=' ), 'product-graft-resolved-source-never-mutates-identical-other-page-region' );

	// Grafting requires a seeded Woo product ID; source-only product metadata is not enough.
	$graft_method   = new ReflectionMethod( 'Static_Site_Importer_Report_Diagnostics', 'graft_product_add_to_cart_shortcodes_into_page_contents' );
	$no_id_contents = array( 'website/shop.html' => $button_region );
	$no_id_finding  = $graft_report['diagnostics'][0];
	$no_id_result   = $graft_method->invokeArgs(
		null,
		array(
			$no_id_finding,
			array(
				'aero-mug'   => array( 'slug' => 'aero-mug' ),
				'trail-pack' => array(
					'slug' => 'trail-pack',
					'id'   => 1201,
				),
			),
			&$no_id_contents,
		)
	);
	$assert( false === ( $no_id_result['grafted'] ?? true ), 'no-product-id-not-grafted' );
	$assert( 'no_safe_plain_add_to_cart_products' === ( $no_id_result['diagnostic']['reason'] ?? '' ), 'no-product-id-reason' );
	$assert( ! str_contains( $no_id_contents['website/shop.html'], '[add_to_cart id=' ), 'no-product-id-no-shortcode' );

	// Quantity/options/custom state stay as honest preserved runtime HTML.
	$unsafe_report                  = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/shop.html' );
	$unsafe_report['diagnostics'][] = $graft_report['diagnostics'][0];
	$unsafe_report['diagnostics'][0]['products'][0]['has_quantity_control'] = true;
	$unsafe_contents = array( 'website/shop.html' => str_replace( '<!-- wp:buttons -->', '<button class="qty-btn">+</button><span class="qty-display">1</span><!-- wp:buttons -->', $button_region ) );
	$unsafe_seeding  = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $unsafe_report, array(), $unsafe_contents );
	$assert( 0 === ( $unsafe_seeding['shortcode_grafted_count'] ?? -1 ), 'unsafe-shortcode-not-grafted' );
	$assert( str_contains( $unsafe_contents['website/shop.html'], '>Add to cart<' ), 'unsafe-static-control-preserved' );
	$assert( ! str_contains( $unsafe_contents['website/shop.html'], '[add_to_cart id=' ), 'unsafe-no-fake-woo-shortcode' );
	$assert( str_contains( $unsafe_contents['website/shop.html'], 'class="qty-display">1</span>' ), 'unsafe-quantity-state-preserved' );
	$assert( false === ( $unsafe_report['diagnostics'][0]['product_shortcode_grafted'] ?? true ), 'unsafe-finding-not-marked-grafted' );

	// --- No product findings => skipped report ------------------------------
	$empty_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/about.html' );
	$empty_seed   = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $empty_report, array() );
	$assert( 'skipped' === ( $empty_seed['status'] ?? '' ), 'no-findings-skipped' );
	$assert( 'no_product_findings' === ( $empty_seed['reason'] ?? '' ), 'no-findings-reason' );

	// --- product_grid_finding_indexes detects plan and fallback discriminator fields
	$indexes = Static_Site_Importer_Report_Diagnostics::product_grid_finding_indexes(
		array(
			array( 'diagnostic_code' => 'html_form_fallback' ),
			array( 'kind' => 'html_product_grid_fallback' ),
			array( 'diagnostic_code' => 'html_product_grid_fallback' ),
			array( 'code' => 'html_product_grid_fallback' ),
		)
	);
	$assert( array( 1, 2, 3 ) === $indexes, 'finding-indexes-detect-plan-and-fallback-codes' );

	if ( empty( $failures ) ) {
		echo 'PASS smoke-product-materializer.php (' . $assertions . " assertions)\n";
		exit( 0 );
	}

	echo 'FAILURES (' . count( $failures ) . ' of ' . $assertions . " assertions):\n";
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}
