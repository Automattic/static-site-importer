<?php
/**
 * Smoke coverage for transformer product reports exposed to SSI imports.
 *
 * Run from the repository root:
 * php tests/smoke-website-artifact-products-manifest.php
 *
 * @package StaticSiteImporter
 */

namespace {
	function blocks_engine_php_transformer_compile_artifact( array $artifact, array $options = array() ): array {
		return array(
			'schema'         => 'blocks-engine/php-transformer/result/v1',
			'status'         => 'success',
			'source_reports' => array(
				'artifact'      => array(
					'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
					'entry_path'  => 'website/index.html',
					'source_hash' => 'products-smoke',
				),
				'materialization_plan' => array(
					'schema'   => 'blocks-engine/php-transformer/materialization-plan/v1',
					'products' => array(
						array(
							'post_type'      => 'product',
							'slug'           => 'rye-loaf',
							'title'          => 'Rye Loaf',
							'price'          => '12',
							'categories'     => array( 'Bread', 12 ),
							'stock_quantity' => '7',
						),
					),
					'commerce' => array(
						'products' => array(
							array(
								'kind'          => 'product',
								'slug'          => 'seeded-coffee',
								'name'          => 'Seeded Coffee',
								'regular_price' => '5.5',
								'sale_price'    => '4',
							),
						),
					),
					'pages'    => array(
						array(
							'post_type'     => 'product',
							'slug'          => 'rye-loaf',
							'title'         => 'Duplicate Rye Loaf',
							'regular_price' => '99',
						),
					),
				),
			),
			'documents'      => array(
				array(
					'metadata' => array(
						'post_type'     => 'product',
						'slug'          => 'olive-rolls',
						'title'         => 'Olive Rolls',
						'regular_price' => '8.25',
					),
				),
			),
			'provenance'     => array(
				array( 'source_hash' => 'products-smoke' ),
			),
		);
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code, string $message ) {
				$this->code    = $code;
				$this->message = $message;
			}

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

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$compiled = ( new Static_Site_Importer_Transformer_Adapter() )->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
	$products = is_array( $compiled ) ? ( $compiled['products_manifest'] ?? array() ) : array();

	$assert( ! is_wp_error( $compiled ), 'compile-succeeds', is_wp_error( $compiled ) ? $compiled->get_error_message() : '' );
	$assert( 'website/index.html' === ( $compiled['input']['entry_path'] ?? '' ), 'preserves-native-artifact-input' );
	$assert( 3 === count( $products ), 'maps-unique-product-reports' );
	$assert( 'rye-loaf' === ( $products[0]['slug'] ?? '' ), 'maps-materialization-plan-product-slug' );
	$assert( 'Rye Loaf' === ( $products[0]['name'] ?? '' ), 'maps-title-to-name' );
	$assert( '12.00' === ( $products[0]['regular_price'] ?? '' ), 'normalizes-price' );
	$assert( array( 'Bread' ) === ( $products[0]['categories'] ?? array() ), 'filters-categories-to-strings' );
	$assert( 7 === ( $products[0]['stock_quantity'] ?? null ), 'normalizes-stock-quantity' );
	$assert( 'seeded-coffee' === ( $products[1]['slug'] ?? '' ), 'maps-commerce-product' );
	$assert( '5.50' === ( $products[1]['regular_price'] ?? '' ), 'normalizes-commerce-regular-price' );
	$assert( '4.00' === ( $products[1]['sale_price'] ?? '' ), 'normalizes-commerce-sale-price' );
	$assert( 'olive-rolls' === ( $products[2]['slug'] ?? '' ), 'maps-document-metadata-product' );

	$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest(
		Static_Site_Importer_Entity_Materializer_Registry::product_adapter(),
		array(
			'schema_version' => 1,
			'products'       => $products,
		)
	);
	$assert( array() === ( $validation['errors'] ?? array() ), 'woo-adapter-validator-accepts-compiled-products' );
	$assert( 3 === count( $validation['products'] ?? array() ), 'woo-adapter-validator-preserves-product-count' );
	$assert( 'Rye Loaf' === ( $validation['products'][0]['name'] ?? '' ), 'woo-adapter-validator-preserves-product-name' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: website artifact products manifest smoke passed (' . $assertions . " assertions)\n";
}
