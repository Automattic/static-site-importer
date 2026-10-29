<?php
/**
 * Formless purchase regions reconcile only through persisted product-form proof.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
/**
 * Return the unchanged standalone filter value.
 *
 * @param string $hook Filter name.
 * @param mixed  $value Filter value.
 * @param mixed  ...$args Additional arguments.
 */
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress stub.
	return $value;
}
/**
 * Encode fixture metadata.
 *
 * @param mixed $value Metadata.
 */
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress stub.
}
/**
 * Sanitize a fixture diagnostic key.
 *
 * @param string $key Diagnostic key.
 */
function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-plan-preparation.php';
/**
 * Assert a standalone regression contract.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Failure message.
 */
function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI assertion.
		exit( 1 );
	}
}
$schema   = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"ProductGroup","name":"Shirt","url":"https://example.test/products/shirt","hasVariant":[{"@type":"Product","sku":"SMALL"}]}</script>';
$region   = '<!-- wp:html --><div class="purchase"><select name="size"><option>Small</option></select><input name="quantity" type="number" value="1"><button>Add to cart</button></div><!-- /wp:html -->';
$fallback = array(
	'type'            => 'unsupported_html_fallback',
	'diagnostic_code' => 'html_commerce_controls_fallback',
	'source_path'     => 'products/shirt.html',
	'selector'        => '.purchase',
	'html_excerpt'    => $region,
	'binding'         => array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'products/shirt.html',
		'search_block_markup' => $region,
		'occurrence'          => 1,
		'role'                => 'commerce_add_to_cart_form',
	),
);
// The provider lifecycle consumes the normalized ProductGroup page product.
$product = array(
	'slug'        => 'shirt',
	'source_path' => 'products/shirt.html',
	'url'         => 'https://example.test/products/shirt',
	'variants'    => array( array( 'sku' => 'SMALL' ) ),
);
foreach ( array( 'unique', 'ambiguous', 'unseeded', 'missing_anchor', 'not_persisted', 'other_controls' ) as $scenario ) {
	$finding  = $fallback;
	$products = array( $product );
	$rows     = array( array( 'slug' => 'shirt', 'id' => 42, 'status' => 'created' ) );
	if ( 'ambiguous' === $scenario ) {
		$products[] = array( 'slug' => 'other-shirt', 'source_path' => 'products/shirt.html' );
		$rows[]     = array( 'slug' => 'other-shirt', 'id' => 43, 'status' => 'created' );
	}
	if ( 'unseeded' === $scenario ) {
		$rows = array();
	}
	if ( 'other_controls' === $scenario ) {
		$finding['binding']['role'] = 'commerce_controls';
	}
	$lifecycle = array(
		'entities' => array(
			'catalog' => array(
				'adapter'  => Static_Site_Importer_Woo_Product_Seeder::adapter(),
				'manifest' => array( 'products' => $products ),
			),
		),
	);
	$reports   = array( 'catalog' => array( 'products' => $rows ) );
	$content   = 'missing_anchor' === $scenario ? '<p>No purchase region</p>' : $schema . ( 'ambiguous' === $scenario ? '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Other shirt"}</script>' : '' ) . $region;
	$bound     = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings(
		$lifecycle,
		$reports,
		array( $finding ),
		array( 'pages' => array( array( 'source_path' => 'products/shirt.html', 'resolved_block_markup' => $content ) ) )
	);
	$bindings  = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $bound, $reports );
	foreach ( $bindings as &$binding ) {
		$content                              = str_replace( $binding['search_block_markup'], $binding['replacement_block_markup'], $content );
		$binding['status']                    = 'completed';
		$binding['persisted_fragment_hash']   = $binding['materialized_block_hash'];
		$binding['materialized_content_hash'] = hash( 'sha256', $content );
	}
	unset( $binding );
	$report                = Static_Site_Importer_Import_Report::from_array( array() );
	$report['diagnostics'] = array( $finding );
	$report->set_quality( array( 'fallback_count' => 1 ) );
	$report['materialization_receipt'] = array(
		'completed' => array(
			'runtime_declarations' => array( 'entities' => $reports, 'entity_bindings' => $bindings ),
			'materialized_pages'   => array( 'products/shirt.html' => array( 'content_hash' => hash( 'sha256', 'not_persisted' === $scenario ? 'stale' : $content ) ) ),
		),
	);
	Static_Site_Importer_Quality_Gates::finalize_quality_report( $report, array() );
	$resolutions = $report['fallback_reconciliation']['resolutions'];
	check( 1 === count( $resolutions ), $scenario . ': exactly one resolution' );
	$resolution = $resolutions[0];
	$resolved   = 'unique' === $scenario;
	check( ( $resolved ? 'resolved_by_provider' : 'unresolved' ) === $resolution['state'], $scenario . ': receipt state' );
	check( ( $resolved ? 0 : 1 ) === $report['quality']['unsupported_fallback_count'], $scenario . ': unsupported_html_fallback count' );
	check( $resolved === $report['quality']['pass'], $scenario . ': quality gate result' );
	check( ! $resolved === in_array( 'unsupported_html_fallback', $report['quality']['failure_reasons'], true ), $scenario . ': quality failure reason' );
	if ( $resolved ) {
		check( 'woocommerce' === $resolution['receipt']['provider'], 'Product provider owns resolution' );
		check( str_contains( $content, 'woocommerce/single-product' ) && str_contains( $content, 'woocommerce/add-to-cart-form' ), 'Native purchase replacement persisted' );
	}
	$reason = match ( $scenario ) {
		'ambiguous' => 'ambiguous_page_product',
		'unseeded' => 'no_seeded_product_for_page',
		'missing_anchor' => 'anchor_not_in_page',
		'not_persisted' => 'provider_replacement_not_persisted',
		default => '',
	};
	check( $reason === ( $resolution['reason'] ?? '' ), $scenario . ': existing reason contract' );
}
echo "Formless purchase reconciliation passed.\n";
