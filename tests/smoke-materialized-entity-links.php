<?php
/**
 * Reduced, source-neutral post-materialization route coverage.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting -- Minimal in-memory WordPress API stubs.
// phpcs:disable WordPress.Files.FileName -- Standalone smoke contains API stubs.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Stubs match WordPress signatures.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated CLI test.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI fixture and assertions.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test harness.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- WordPress stub signature.
// phpcs:disable Generic.Classes.DuplicateClassName -- Separate CLI harnesses use the same WooCommerce API stubs.

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php';

function wp_parse_url( $url ) {
	return parse_url( $url ); }
function sanitize_key( $key ) {
	return $key; }
function get_post_field( $field, $id ) {
	return $GLOBALS['entity_link_content']; }
function get_post_meta( $id, $key, $single = false ) {
	return ''; }
function wp_slash( $value ) {
	return $value; }
function wp_update_post( $post, $error = false ) {
	$GLOBALS['entity_link_content'] = $post['post_content'];
	return $post['ID']; }
function is_wp_error( $value ) {
	return false; }
function get_permalink( $id ) {
	return 'https://local.example/entity/' . $id . '/'; }
function home_url( $path = '' ) {
	return 'https://local.example' . $path; }
function get_page_by_path( $path, $output = null, $type = null ) {
	return null; }
function get_option( $key, $default = false ) {
	return $default; }
function sanitize_title( $title ) {
	return $title; }
function post_type_exists( $type ) {
	return true; }
function taxonomy_exists( $taxonomy ) {
	return true; }
function wp_kses_post( $content ) {
	return $content; }
class WC_Product_Simple {
	public function __call( $method, $arguments ) {}
	public function update_meta_data( $key, $value ) {
		$GLOBALS['seeded_product_meta'][ $key ] = $value; }
	public function save() {
		return 42; }
}
function wc_get_page_id( $page ) {
	return $GLOBALS['cart_page_id']; }
$GLOBALS['cart_page_id'] = 88;

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
};
require dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
$manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_woo_products_manifest(
	array(
		'schema_version' => 1,
		'products'       => array(
			array(
				'name'          => 'Field Kit',
				'slug'          => 'field-kit',
				'regular_price' => '12',
				'url'           => 'https://source.example/products/field-kit',
			),
		),
	)
);
$assert( '/products/field-kit' === ( $manifest['products'][0]['source_route'] ?? '' ), 'Canonical source route survives manifest validation.' );
define( 'OBJECT', 'OBJECT' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
$seeded = Static_Site_Importer_Woo_Product_Seeder::seed( $manifest );
$assert( 42 === ( $seeded['products'][0]['id'] ?? 0 ), 'Product materializes.' );
$assert( '/products/field-kit' === ( $GLOBALS['seeded_product_meta']['_static_site_importer_source_route'] ?? '' ), 'Seeded product stores source route metadata.' );
$GLOBALS['entity_link_content'] = file_get_contents( __DIR__ . '/fixtures/materialized-entity-links.html' );
$state                          = array(
	'ordered_pages' => array(
		array(
			'source_path' => 'collections/tools/index.html',
			'route'       => array( 'path' => '/collections/tools' ),
		),
	),
	'source_ids'    => array( 'collections/tools/index.html' => 10 ),
	'args'          => array(
		'source_metadata'             => array( 'source_path' => 'https://source.example/' ),
		'materialized_entity_reports' => array(
			'catalog' => $seeded,
		),
	),
	'applied'       => array( 'runtime_declarations' => array( 'entity_bindings' => array() ) ),
	'resolved'      => array( 'pages' => array() ),
);
$assert( true === Static_Site_Importer_Site_Plan_Persistence::rewrite_materialized_route_links( $state ), 'Rewrite completes.' );
$stored = $GLOBALS['entity_link_content'];
$assert( 2 === substr_count( $stored, 'href="/?p=42"' ), 'Scoped card and bare nav resolve to the seeded product.' );
$assert( str_contains( $stored, 'href="/?page_id=10"' ), 'Category listing retains its imported page identity.' );
$assert( str_contains( $stored, 'https://source.example/journal/missing' ), 'Unimported route stays unchanged.' );
$assert( array( 'journal' => 1 ) === $state['applied']['unresolved_same_origin_links'], 'Receipt counts unresolved links by first segment.' );
$assert( str_contains( $stored, 'https://elsewhere.example/products/field-kit' ), 'Cross-origin URL stays unchanged.' );
$rendered = Static_Site_Importer_Internal_Link_Runtime::resolve_urls( $stored );
$assert( 2 === substr_count( $rendered, 'https://local.example/entity/42/' ), 'Both references resolve to the product permalink.' );
$GLOBALS['cart_page_id'] = 99;
$rendered                = Static_Site_Importer_Internal_Link_Runtime::resolve_urls( $stored );
$assert( str_contains( $rendered, 'https://local.example/entity/99/' ), 'Cart uses the current Woo page id.' );
$assert( str_contains( $rendered, '?s=field%20kit&post_type=product' ), 'Search is a local product search.' );
$assert( null === Static_Site_Importer_Site_Plan_Persistence::scoped_product_reference( '/products/not-field-kit', array( '/products/field-kit' => '/?p=42' ) ), 'Other handles do not match.' );
$unresolved = array();
$relative   = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="../../products/field-kit?ref=nav#details">Relative</a>', array( '/products/field-kit' => '/?p=42' ), $unresolved, 'https://source.example/', array(), '/collections/tools/index.html' );
$assert( str_contains( $relative, '/?p=42&ref=nav#details' ), 'Document-relative routes preserve query and fragment.' );
$assert( '/?p=42' === Static_Site_Importer_Site_Plan_Persistence::scoped_product_reference( '/groups/tools/items/field-kit', array( '/items/field-kit' => '/?p=42' ) ), 'Scoped matching keys on the product route resource segment, not a platform.' );
$assert( ! Static_Site_Importer_Site_Plan_Persistence::same_source_origin( 'https://source.example:444/products/field-kit', 'https://source.example/' ), 'Other ports are cross-origin.' );
$assert( ! Static_Site_Importer_Site_Plan_Persistence::same_source_origin( 'http://source.example/products/field-kit', 'https://source.example/' ), 'Other schemes are cross-origin.' );
echo "Materialized entity links smoke passed.\n";
