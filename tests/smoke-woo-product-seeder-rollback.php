<?php
/** Run: php tests/smoke-woo-product-seeder-rollback.php */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {} public function get_error_code(): string {
		return $this->code;
	} public function get_error_message(): string {
		return $this->message; }
}
class WP_Post {
	public function __construct( public int $ID ) {}
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function sanitize_title( string $value ): string {
	return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) ); }
function wp_kses_post( string $value ): string {
	return $value; }
function post_type_exists( string $type ): bool {
	return 'product' === $type; }
function taxonomy_exists( string $type ): bool {
	return 'product_cat' === $type; }
function get_page_by_path( string $slug ): ?WP_Post {
	return isset( $GLOBALS['products_by_slug'][ $slug ] ) ? new WP_Post( $GLOBALS['products_by_slug'][ $slug ] ) : null; }
function get_post( int $id, string $output = OBJECT ): array|WP_Post|null {
	return $GLOBALS['products'][ $id ] ?? null; }
function get_post_meta( int $id ): array {
	return $GLOBALS['meta'][ $id ] ?? array(); }
function delete_post_meta( int $id, string $key ): void {
	unset( $GLOBALS['meta'][ $id ][ $key ] ); }
function add_post_meta( int $id, string $key, mixed $value ): void {
	$GLOBALS['meta'][ $id ][ $key ][] = $value; }
function wp_get_object_terms( int $id ): array {
	return $GLOBALS['product_terms'][ $id ] ?? array(); }
function wp_update_post( array $post, bool $error = false ): int|WP_Error {
	if ( ! empty( $GLOBALS['update_fails'] ) ) {
		return new WP_Error( 'update_failed', 'Update failed.' );
	} $GLOBALS['products'][ $post['ID'] ] = $post;
	return $post['ID']; }
function wp_delete_post( int $id ): bool {
	if ( ! empty( $GLOBALS['delete_fails'] ) ) {
		return false;
	} $GLOBALS['delete_order'][] = $id;
	foreach ( $GLOBALS['product_terms'][ $id ] ?? array() as $term ) {
		--$GLOBALS['terms'][ $term ]->count;
	} unset( $GLOBALS['products'][ $id ], $GLOBALS['meta'][ $id ], $GLOBALS['product_terms'][ $id ] );
	foreach ( $GLOBALS['products_by_slug'] as $slug => $product_id ) {
		if ( $product_id === $id ) {
			unset( $GLOBALS['products_by_slug'][ $slug ] );
		}
	} return true; }
function term_exists( string $name ): int|array|null {
	return $GLOBALS['terms_by_name'][ $name ] ?? null; }
function wp_insert_term( string $name ): array|WP_Error|false {
	if ( ! empty( $GLOBALS['insert_term_fails'] ) ) {
		return new WP_Error( 'term_failed', 'Term creation failed.' );
	} $id                              = ++$GLOBALS['next_term_id'];
	$GLOBALS['terms_by_name'][ $name ] = array( 'term_id' => $id );
	$GLOBALS['terms'][ $id ]           = (object) array( 'count' => 0 );
	return array( 'term_id' => $id ); }
function wp_set_object_terms( int $id, array $terms ): array|WP_Error|false {
	$failure = $GLOBALS['set_terms_failure'];
	if ( null !== $failure ) {
		$GLOBALS['set_terms_failure'] = null;
		return 'error' === $failure ? new WP_Error( 'term_assignment_failed', 'Term assignment failed.' ) : false;
	} $GLOBALS['product_terms'][ $id ] = $terms;
	foreach ( $terms as $term ) {
		$GLOBALS['terms'][ $term ]->count = ( $GLOBALS['terms'][ $term ]->count ?? 0 ) + 1;
	} return $terms; }
function get_term( int $id ): object|null {
	return $GLOBALS['terms'][ $id ] ?? null; }
function wp_delete_term( int $id ): bool|WP_Error {
	if ( ! empty( $GLOBALS['delete_term_fails'] ) ) {
		return false;
	} unset( $GLOBALS['terms'][ $id ] );
	return true; }
class WC_Product_Simple {
	public array $data = array();
	public function __construct( public int $id = 0 ) {}
	public function __call( string $method, array $args ): void {
		$this->data[ $method ] = $args[0] ?? null; }
	public function save(): int {
		$id                                   = $this->id ?: ++$GLOBALS['next_product_id'];
		$slug                                 = (string) ( $this->data['set_slug'] ?? 'product-' . $id );
		$GLOBALS['products'][ $id ]           = array(
			'ID'         => $id,
			'post_name'  => $slug,
			'post_title' => (string) ( $this->data['set_name'] ?? '' ),
		);
		$GLOBALS['products_by_slug'][ $slug ] = $id;
		$GLOBALS['meta'][ $id ]               = array( '_changed' => array( 'yes' ) );
		return $id; }
}
function wc_get_product( int $id ): WC_Product_Simple {
	return new WC_Product_Simple( $id ); }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
$assert  = static function ( bool $condition, string $label ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	} };
$reset   = static function (): void {
	$GLOBALS['products']          = array();
	$GLOBALS['products_by_slug']  = array();
	$GLOBALS['meta']              = array();
	$GLOBALS['product_terms']     = array();
	$GLOBALS['terms']             = array();
	$GLOBALS['terms_by_name']     = array();
	$GLOBALS['delete_order']      = array();
	$GLOBALS['next_product_id']   = 100;
	$GLOBALS['next_term_id']      = 200;
	$GLOBALS['set_terms_failure'] = null;
	$GLOBALS['insert_term_fails'] = false;
	$GLOBALS['delete_term_fails'] = false;
	$GLOBALS['delete_fails']      = false;
	$GLOBALS['update_fails']      = false;
};
$product = static fn( string $slug, array $categories ): array => array(
	'slug'       => $slug,
	'name'       => ucfirst( $slug ),
	'categories' => $categories,
);

$reset();
$GLOBALS['set_terms_failure'] = 'false';
$report                       = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $product( 'new-failure', array( 'New category' ) ) ) ) );
$row                          = $report['products'][0];
$assert( 'error' === $row['status'] && ! empty( $row['id'] ) && ! empty( $row['compensated'] ), 'new product failure retains a compensable receipt' );
$assert( empty( $GLOBALS['products'] ) && empty( $GLOBALS['terms'] ), 'new product and created category are compensated after assignment failure' );

$reset();
$GLOBALS['products'][7]                         = array(
	'ID'         => 7,
	'post_name'  => 'updated-failure',
	'post_title' => 'Original',
);
$GLOBALS['products_by_slug']['updated-failure'] = 7;
$GLOBALS['meta'][7]                             = array( '_original' => array( 'yes' ) );
$GLOBALS['product_terms'][7]                    = array( 9 );
$GLOBALS['terms'][9]                            = (object) array( 'count' => 1 );
$GLOBALS['terms_by_name']['Shared category']    = array( 'term_id' => 9 );
$GLOBALS['set_terms_failure']                   = 'error';
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $product( 'updated-failure', array( 'Shared category' ) ) ) ) );
$assert( ! empty( $report['products'][0]['compensated'] ) && 'Original' === $GLOBALS['products'][7]['post_title'] && array( '_original' => array( 'yes' ) ) === $GLOBALS['meta'][7] && array( 9 ) === $GLOBALS['product_terms'][7], 'updated product failure restores post, meta, and preexisting categories' );
$assert( isset( $GLOBALS['terms'][9] ), 'preexisting shared category is never cleaned up' );

$reset();
$GLOBALS['insert_term_fails'] = true;
$report                       = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $product( 'creation-failure', array( 'Broken category' ) ) ) ) );
$assert( 'error' === $report['products'][0]['status'] && ! isset( $GLOBALS['products_by_slug']['creation-failure'] ), 'category creation failure deletes the already-saved product' );

$reset();
$report                       = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $product( 'first', array( 'Reverse category' ) ), $product( 'second', array( 'Reverse category' ) ) ) ) );
$GLOBALS['delete_term_fails'] = true;
$rollback                     = Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( 'partial' === $rollback['status'] && array( 201 ) === $rollback['term_cleanup_failures'] && array( 102, 101 ) === $GLOBALS['delete_order'] && empty( $GLOBALS['products'] ), 'reverse rollback removes products and reports category cleanup diagnostics' );

print "Woo product rollback smoke passed\n";
