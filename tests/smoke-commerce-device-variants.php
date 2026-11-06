<?php
/**
 * Reduced device-selection commerce capture through compiler and import bindings.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, Squiz.Commenting.FunctionComment, Squiz.Commenting.ClassComment, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Standalone WordPress/WooCommerce persistence doubles.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
function apply_filters( $hook, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_key( $value ) { return strtolower( $value ); }
function sanitize_title( $value ) { return strtolower( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Post {
	public function __construct( public int $ID ) {}
}
class WC_Product {}
function wc_get_product( $id ) { return new WC_Product(); }
function get_page_by_path( $slug, $output = OBJECT, $type = 'page' ) {
	return new WP_Post( 'cup' === $slug ? 41 : 42 );
}
require_once ABSPATH . 'vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
foreach ( array( 'entity-materializer-registry', 'woo-product-seeder', 'woo-product-grid-materializer', 'woo-product-listing-materializer', 'import-report', 'report-diagnostics', 'quality-gates', 'site-plan-preparation' ) as $include ) {
	require_once ABSPATH . 'includes/class-static-site-importer-' . $include . '.php';
}
function commerce_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$files = array(
	'shop.html'        => file_get_contents( __DIR__ . '/fixtures/commerce-device-variants/shop.html' ),
	'product/cup.html' => file_get_contents( __DIR__ . '/fixtures/commerce-device-variants/cup.html' ),
);
$view = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'shop.html', 'files' => $files ) )->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$resolved = ( new Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://example.test/theme' ) );
// Model the real producer handoff: unknown subscription forms in two device
// documents, but only the first placement has a purchase declaration/control
// finding. This is deliberately not inferred from the button labels.
$purchase_forms = array_values( array_filter( $plan['quality']['fallbacks'], static fn( array $finding ): bool => 'html_form_fallback' === $finding['diagnostic_code'] ) );
commerce_check( 2 === count( $purchase_forms ), 'Two subscription purchase boxes' );
foreach ( $purchase_forms as $finding ) {
	commerce_check( 'unknown' === Static_Site_Importer_Form_Fallback_Contract::intent( $finding ), 'Subscription form remains runtime intent' );
}
$purchase_anchor = $purchase_forms[0]['binding'];
$purchase_anchor['role'] = 'commerce_add_to_cart_form';
$purchase_anchor['source_path'] = $purchase_forms[0]['source_path'] ?? $purchase_forms[0]['source'];
$controls_finding = array( 'diagnostic_code' => 'html_commerce_controls_fallback', 'code' => 'html_commerce_controls_fallback', 'source_path' => ( $purchase_forms[0]['source_path'] ?? $purchase_forms[0]['source'] ), 'selector' => $purchase_forms[0]['selector'], 'binding' => $purchase_anchor );
$plan['quality']['fallbacks'][] = $controls_finding;
$plan['diagnostics'][] = $controls_finding;
$plan['quality']['fallback_count'] = 7;
$plan['quality']['source_fallback_count'] = 7;
$lifecycle = array( 'entities' => array() );
$reports = array();
foreach ( $plan['runtime_declarations'] as $declaration ) {
	$type = $declaration['type'] ?? '';
	if ( ! in_array( $type, array( 'products', 'product_grids' ), true ) ) {
		continue;
	}
	$id = $declaration['reconciliation_identity'];
	$adapter = 'products' === $type ? Static_Site_Importer_Woo_Product_Seeder::adapter() : Static_Site_Importer_Woo_Product_Grid_Materializer::adapter();
	$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'schema_version' => 1, $type => $declaration['payload']['entities'] ) );
	commerce_check( empty( $validation['errors'] ), 'Valid provider manifest' );
	if ( 'products' === $type ) {
		foreach ( $validation[$type] as &$product ) {
			if ( 'cup' === $product['slug'] ) {
				$product['bindings'][] = $purchase_anchor;
			}
		}
		unset( $product );
	}
	$lifecycle['entities'][ $id ] = array( 'adapter' => $adapter, 'manifest' => array( $type => $validation[ $type ] ) );
	$reports[ $id ] = 'product_grids' === $type ? Static_Site_Importer_Woo_Product_Grid_Materializer::materialize( $validation ) : array( 'products' => array( array( 'slug' => 'cup', 'id' => 41, 'status' => 'created' ), array( 'slug' => 'bowl', 'id' => 42, 'status' => 'created' ) ) );
}
// Older captures declare one shared collection anchor on each catalog product.
// Exercise that handoff too: it has no grid entity from which to take a selector.
if ( in_array( '--legacy', $argv, true ) ) {
	$grid_bindings = array();
	foreach ( $lifecycle['entities'] as $id => $prepared ) {
		if ( isset( $prepared['manifest']['product_grids'] ) ) {
			foreach ( $prepared['manifest']['product_grids'] as $grid ) {
				$anchor = $grid['bindings'][0];
				$anchor['role'] = 'commerce_collection';
				$grid_bindings[] = $anchor;
			}
			unset( $lifecycle['entities'][ $id ], $reports[ $id ] );
		}
	}
	foreach ( $lifecycle['entities'] as &$prepared ) {
		foreach ( $prepared['manifest']['products'] as &$product ) {
			$product['bindings'] = array_merge( array_values( array_filter( $product['bindings'], static fn( array $binding ): bool => 'commerce_controls' !== $binding['role'] ) ), $grid_bindings );
		}
		unset( $product );
	}
	unset( $prepared );
} else {
	$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_adopted_product_regions( $lifecycle, $resolved, $plan['quality']['fallbacks'] );
}
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings( $lifecycle, $reports, $plan['quality']['fallbacks'], $resolved );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $reports );
$binding_reports = array();
$diagnostics = array();
Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $resolved, $bindings, $binding_reports, $diagnostics );
$pages = array();
foreach ( $resolved['pages'] as $page ) {
	$pages[ $page['source_path'] ] = array( 'content_hash' => hash( 'sha256', $page['materialized_block_markup'] ?? $page['resolved_block_markup'] ) );
}
foreach ( $binding_reports as &$binding ) {
	$binding['status'] = 'completed';
	$binding['persisted_fragment_hash'] = $binding['materialized_block_hash'];
	$binding['materialized_content_hash'] = $pages[ $binding['source_path'] ]['content_hash'];
}
unset( $binding );
$report = Static_Site_Importer_Import_Report::from_array( array( 'diagnostics' => $plan['diagnostics'], 'quality' => $plan['quality'], 'materialization_receipt' => array( 'completed' => array( 'runtime_declarations' => array( 'entities' => $reports, 'entity_bindings' => $binding_reports ), 'materialized_pages' => $pages ) ) ) );
Static_Site_Importer_Quality_Gates::finalize_quality_report( $report, array() );
$quality = $report['quality'];
echo 'Unsupported commerce fallbacks: ' . $quality['unsupported_fallback_count'] . "\n";
commerce_check( 0 === $quality['unsupported_fallback_count'], wp_json_encode( $report['fallback_reconciliation'] ) );
commerce_check( 7 === $report['fallback_reconciliation']['source_fallback_count'], 'Both copies of grids, controls and forms were tested' );
commerce_check( 2 === count( array_filter( $binding_reports, static fn( array $binding ): bool => 'commerce_add_to_cart_form' === $binding['role'] ) ), 'Each device purchase placement has its own receipt' );
foreach ( $binding_reports as $binding ) {
	if ( 'commerce_add_to_cart_form' === $binding['role'] ) {
		commerce_check( hash( 'sha256', '' ) !== $binding['fallback_hash'], 'Purchase receipt must hash its real fallback' );
		$matches = array_values( array_filter( $plan['quality']['fallbacks'], static fn( array $fallback ): bool => 'html_form_fallback' === $fallback['diagnostic_code'] && $fallback['selector'] === $binding['selector'] ) );
		commerce_check( 1 === count( $matches ) && Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $matches[0] ) === $binding['fallback_hash'], 'Receipt hashes exactly the source device fallback' );
	}
}
// A stale persisted page must never resolve either device copy.
$receipt = $report['materialization_receipt'];
foreach ( $receipt['completed']['materialized_pages'] as &$page ) {
	$page['content_hash'] = hash( 'sha256', 'stale page' );
}
unset( $page );
$stale = Static_Site_Importer_Import_Report::from_array( array( 'diagnostics' => $plan['diagnostics'], 'quality' => $plan['quality'], 'materialization_receipt' => $receipt ) );
Static_Site_Importer_Quality_Gates::finalize_quality_report( $stale, array() );
commerce_check( 7 === $stale['quality']['unsupported_fallback_count'], 'Stale persistence leaves every copy unresolved' );
echo "Commerce device variants passed.\n";
if ( ! in_array( '--legacy', $argv, true ) ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --legacy', $status );
	commerce_check( 0 === $status, 'Legacy collection handoff' );
}
