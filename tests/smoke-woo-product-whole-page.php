<?php
/** Product producer row -> Woo document -> transactional source-route handoff. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Post {
	public function __construct( public int $ID, public string $post_status, public string $post_content, public string $post_type, public string $post_name ) {}
}
class WC_Product_Simple {
	private array $data = array();
	public function __call( $name, $args ) {
		$this->data[ $name ] = $args[0];
	}
	public function save() {
		$id = count( $GLOBALS['posts'] ) + 100;
		$GLOBALS['posts'][ $id ] = new WP_Post( $id, 'publish', $this->data['set_description'], 'product', $this->data['set_slug'] );
		return $id;
	}
}
function post_type_exists( $type ) { return 'product' === $type; }
function taxonomy_exists( $type ) { return 'product_cat' === $type; }
function sanitize_title( $text ) { return strtolower( $text ); }
function wp_kses_post( $text ) { return $text; }
function get_page_by_path( ...$args ) { return null; }
function get_option( $key, $default = false ) { return $default; }
function get_post( $id, $output = OBJECT ) { $post = $GLOBALS['posts'][ $id ] ?? null; return ARRAY_A === $output && $post ? (array) $post : $post; }
function get_post_field( $field, $id ) { return get_post( $id )->$field; }
function get_post_type( $id ) { return get_post( $id )->post_type; }
function wp_update_post( $fields, $error = false ) { foreach ( $fields as $key => $value ) { $GLOBALS['posts'][ $fields['ID'] ]->$key = $value; } return $fields['ID']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post_meta( $id, $key = '', $single = false ) { $values = $GLOBALS['meta'][ $id ][ $key ] ?? array(); return $single ? ( $values[0] ?? '' ) : $values; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function add_post_meta( $id, $key, $value, $unique = false ) { $GLOBALS['meta'][ $id ][ $key ][] = $value; return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = array( $value ); return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_slash( $value ) { return $value; }
function wp_mkdir_p( $path ) { return mkdir( $path, 0777, true ); }
function wp_normalize_path( $path ) { return $path; }

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
foreach ( array( 'woo-product-seeder', 'entity-materializer-registry', 'whole-page-handoff', 'source-route', 'site-plan-persistence' ) as $file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $file . '.php';
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$row = array(
	'id' => 'product-mug', 'name' => 'Mug', 'slug' => 'mug', 'regular_price' => '24',
	'source_path' => 'website/products/mug.html', 'source_route' => '/products/mug',
	'whole_page_candidate' => 'blocks-engine/whole-page-candidate/v1',
);
// Exercise the real producer attachment code, not a fabricated page candidate.
$attach = new ReflectionMethod( Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::class, 'attachWholePageCandidates' );
$markup = '<!-- wp:group --><div class="wp-block-group original-layout"><!-- wp:paragraph --><p>Original mug layout</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$pages = $attach->invoke( null, array( array(
	'source_path' => $row['source_path'], 'route' => array( 'path' => $row['source_route'] ),
	'reconciliation_identity' => 'page-mug', 'post_type' => 'page', 'resolved_block_markup' => $markup,
	'planned_existing_id' => 10,
) ), array( array( 'kind' => 'entity_collection', 'reconciliation_identity' => 'declaration-products', 'payload' => array( 'entities' => array( $row ) ) ) ), array() );
$manifest = Static_Site_Importer_Entity_Materializer_Registry::validate_woo_products_manifest( array( 'schema_version' => 1, 'products' => array( $row ) ) );
$assert( empty( $manifest['errors'] ), 'Producer product validates.' );
$GLOBALS['posts'] = array( 10 => new WP_Post( 10, 'publish', $markup, 'page', 'mug' ) );
$GLOBALS['meta'] = array( 10 => array( '_static_site_importer_reconciliation_identity' => array( 'page-mug' ) ) );
$report = Static_Site_Importer_Woo_Product_Seeder::seed( $manifest, array(
	'declaration_reconciliation_identity' => 'declaration-products',
	'whole_page_documents' => Static_Site_Importer_Whole_Page_Handoff::documents( $pages ),
) );
$assert( 1 === count( $report['whole_page_results'] ?? array() ), 'Product returns a whole-page claim.' );
$id = $report['products'][0]['id'];
$assert( $markup === get_post_field( 'post_content', $id ), 'Woo saves the complete editable layout.' );
$binding = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'entity_kind' => 'add_to_cart_form' ), array( 'id' => $id ) );
$bound_markup = $markup . $binding;
$pages[0]['materialized_block_markup'] = $bound_markup;
$state = array(
	'args' => array( 'whole_page_provider_results' => $report['whole_page_results'] ),
	'ordered_pages' => $pages, 'diagnostics' => array(), 'source_route_aliases' => array(),
	'theme_dir' => sys_get_temp_dir() . '/ssi-product-handoff-' . uniqid(),
);
$apply = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'apply_whole_page_handoffs' );
$arguments = array( &$state );
$assert( true === $apply->invokeArgs( null, $arguments ), 'Product handoff commits.' );
$assert( $bound_markup === get_post_field( 'post_content', $id ), 'Final product document includes the resolved add-to-cart binding.' );
$assert( 'draft' === get_post( 10 )->post_status, 'Captured page is no longer published.' );
$assert( $id === $state['source_ids'][ $row['source_path'] ], 'Product is the page owner.' );
$assert( in_array( 'products/mug', get_post_meta( $id, Static_Site_Importer_Source_Route::META_KEY ), true ), 'Source route becomes a redirect alias.' );
$template = $state['theme_dir'] . '/templates/single-product-mug.html';
$assert( '<!-- wp:post-content /-->' === file_get_contents( $template ), 'Specific single-product template outputs only the product document.' );
$assert( false === $state['rollback']['files'][ $template ]['exists'], 'Template participates in transactional rollback.' );
$assert( 'publish' === $state['rollback']['posts'][10]['post']['post_status'], 'Rollback retains the captured page publication state.' );
$assert( $markup === $state['rollback']['posts'][ $id ]['post']['post_content'], 'Rollback retains the pre-binding product document.' );
$binding = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'entity_kind' => 'add_to_cart_form' ), array( 'id' => $id ) );
$assert( str_contains( $binding, '"productId":' . $id ) && str_contains( $binding, 'woocommerce/add-to-cart-form' ), 'Bound form explicitly retains Woo product context.' );
unset( $row['whole_page_candidate'] );
$row['slug'] = 'translated-mug';
$row['description'] = 'Normal description';
$normal = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $row ) ) );
$assert( empty( $normal['whole_page_results'] ), 'No candidate: no handoff.' );
$assert( 'Normal description' === get_post_field( 'post_content', $normal['products'][0]['id'] ), 'No candidate: existing description behavior unchanged.' );
unlink( $template );
rmdir( dirname( $template ) );
rmdir( $state['theme_dir'] );
echo "PASS Woo product producer whole-page handoff\n";
