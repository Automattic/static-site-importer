<?php
/**
 * Smoke coverage for bundle-aware structured-data product extraction.
 *
 * Proves a site bundle that renders its catalog client-side from a static
 * `js/products.js` (or `.json`) data file materializes products through the
 * existing products-manifest/v1 path and WooCommerce seeder adapter, keying off
 * data shape rather than variable names or site-specific keys.
 *
 * Run from the repository root:
 * php tests/smoke-website-artifact-bundle-data-products.php
 *
 * @package StaticSiteImporter
 */

namespace {
	function blocks_engine_php_transformer_compile_artifact( array $artifact, array $options = array() ): array {
		return array(
			'schema'         => 'blocks-engine/php-transformer/result/v1',
			'status'         => 'success',
			'source_reports' => array(
				'artifact'              => array(
					'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
					'entry_path' => 'website/index.html',
				),
				'materialization_plan' => array(
					'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
					'pages'  => array(
						array(
							'source_path'  => 'website/index.html',
							'post_type'    => 'page',
							'slug'         => 'shop',
							'title'        => 'Shop',
							'block_markup' => '<!-- wp:paragraph --><p>Shop</p><!-- /wp:paragraph -->',
						),
					),
				),
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
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-bundle-data-source.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	// A client-rendered catalog data file shaped like the shop fixtures:
	// unquoted keys, single quotes, comments, integer prices, a trailing comma,
	// a nested object, and an unresolvable variable reference value.
	$products_js = <<<'JS'
/* Studio — product catalog data. Rendered client-side into #featuredGrid. */

// Glaze swatch tokens -> hex. A config map, not a product list.
const GLAZES = {
  Oatmeal: '#e3d8c5',
  Sage:    '#a7b09a',
  Charcoal:'#3f3f3c'
};

const PRODUCTS = [
  {
    id: 'tf-01',
    name: 'Oatmeal Dinner Plate',
    category: 'Tableware',
    price: 48,
    featured: true,
    glaze: GLAZES.Oatmeal,
    description: "A hand-thrown dinner plate finished in a soft matte oatmeal glaze. It's lead-free.",
    variants: { label: 'Glaze', options: ['Oatmeal', 'Sage', 'Charcoal'] }
  },
  {
    id: 'tf-02',
    name: 'Sage Breakfast Bowl',
    category: 'Bowls',
    price: 36.5,
    featured: true,
    description: 'A deep everyday bowl glazed in dusty sage.',
    variants: { label: 'Glaze', options: ['Sage', 'Oatmeal'] }
  },
  {
    id: 'tf-03',
    name: 'Stoneware Tumbler',
    category: 'Mugs & Cups',
    price: 28,
    featured: false,
    description: 'A handleless tumbler with a gently tapered waist.',
  },
];

if (typeof window !== 'undefined') { window.PRODUCTS = PRODUCTS; }
JS;

	// Non-product arrays/objects that must not be misdetected: navigation links
	// (label + href, no price) and a settings object.
	$config_js = <<<'JS'
const NAV = [
  { label: 'Home', href: '/' },
  { label: 'Shop', href: '/shop' },
  { label: 'Contact', href: '/contact' }
];

const SETTINGS = { currency: 'USD', perPage: 12, theme: 'light' };
JS;

	// A JSON data file using alternate field synonyms (title/amount/desc/type).
	$catalog_json = <<<'JSON'
{
  "items": [
    {
      "sku": "cat-01",
      "title": "Pour-Over Dripper",
      "type": "Gear",
      "amount": "24.00",
      "desc": "A ceramic pour-over cone for single cups."
    }
  ]
}
JSON;

	// Rendered HTML carries a product card for a product that also exists in the
	// bundle data, with a different price, to prove bundle-data wins on dedupe.
	$index_html = '<!doctype html><html><body>'
		. '<article class="product-card"><h2>Oatmeal Dinner Plate</h2><p class="price">$99.00</p></article>'
		. '<div id="featuredGrid" class="grid"></div>'
		. '</body></html>';

	$artifact = array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'      => 'website/index.html',
				'kind'      => 'html',
				'mime_type' => 'text/html',
				'content'   => $index_html,
			),
			array(
				'path'    => 'website/js/products.js',
				'content' => $products_js,
			),
			array(
				'path'    => 'website/js/config.js',
				'content' => $config_js,
			),
			array(
				'path'    => 'website/data/catalog.json',
				'content' => $catalog_json,
			),
		),
	);

	$compiled = ( new Static_Site_Importer_Transformer_Adapter() )->compile_website_artifact( $artifact );
	$products = is_array( $compiled ) ? ( $compiled['products_manifest'] ?? array() ) : array();
	$by_slug  = array();
	foreach ( $products as $product ) {
		$by_slug[ $product['slug'] ?? '' ] = $product;
	}

	$assert( ! is_wp_error( $compiled ), 'compile-succeeds', is_wp_error( $compiled ) ? $compiled->get_error_message() : '' );
	$assert( 4 === count( $products ), 'extracts-three-js-and-one-json-product', 'count=' . count( $products ) );

	// js/products.js products materialize with name/price/category/description.
	$plate = $by_slug['oatmeal-dinner-plate'] ?? array();
	$assert( 'Oatmeal Dinner Plate' === ( $plate['name'] ?? '' ), 'js-product-name' );
	$assert( '48.00' === ( $plate['regular_price'] ?? '' ), 'js-integer-price-normalized', 'price=' . ( $plate['regular_price'] ?? '' ) );
	$assert( array( 'Tableware' ) === ( $plate['categories'] ?? array() ), 'js-single-category-to-list' );
	$assert( str_contains( (string) ( $plate['description'] ?? '' ), 'oatmeal glaze' ), 'js-description-with-escaped-quote' );
	$assert( str_starts_with( (string) ( ( $plate['source_selectors'][0] ?? '' ) ), 'bundle-data:products.js' ), 'js-product-source-provenance' );

	$bowl = $by_slug['sage-breakfast-bowl'] ?? array();
	$assert( '36.50' === ( $bowl['regular_price'] ?? '' ), 'js-decimal-price-normalized' );

	$assert( isset( $by_slug['stoneware-tumbler'] ), 'js-product-after-trailing-comma' );

	// JSON data file with title/amount/desc/type synonyms.
	$dripper = $by_slug['pour-over-dripper'] ?? array();
	$assert( 'Pour-Over Dripper' === ( $dripper['name'] ?? '' ), 'json-title-synonym-to-name' );
	$assert( '24.00' === ( $dripper['regular_price'] ?? '' ), 'json-amount-synonym-to-price' );
	$assert( array( 'Gear' ) === ( $dripper['categories'] ?? array() ), 'json-type-synonym-to-category' );
	$assert( str_contains( (string) ( $dripper['description'] ?? '' ), 'pour-over cone' ), 'json-desc-synonym-to-description' );

	// Non-product arrays are not misdetected.
	$assert( ! isset( $by_slug['home'] ) && ! isset( $by_slug['shop'] ) && ! isset( $by_slug['contact'] ), 'navigation-array-not-detected' );

	// Bundle data is preferred over DOM detection on dedupe (price stays 48, not 99).
	$assert( '48.00' === ( $plate['regular_price'] ?? '' ), 'bundle-data-preferred-over-dom-card' );

	// The full set is seedable through the existing WooCommerce product adapter.
	$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest(
		Static_Site_Importer_Entity_Materializer_Registry::product_adapter(),
		array(
			'schema_version' => 1,
			'products'       => $products,
		)
	);
	$assert( array() === ( $validation['errors'] ?? array() ), 'woo-adapter-validator-accepts-bundle-products', implode( '; ', array_map( static fn ( $e ) => ( $e['path'] ?? '' ) . ' ' . ( $e['message'] ?? '' ), $validation['errors'] ?? array() ) ) );
	$assert( 4 === count( $validation['products'] ?? array() ), 'woo-adapter-validator-preserves-product-count' );
	$assert( 'Oatmeal Dinner Plate' === ( $validation['products'][0]['name'] ?? ( $by_slug['oatmeal-dinner-plate']['name'] ?? '' ) ), 'woo-adapter-validator-preserves-name' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: website artifact bundle data products smoke passed (' . $assertions . " assertions)\n";
}
