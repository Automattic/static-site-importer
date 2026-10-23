<?php
/**
 * Smoke coverage for seeding a product's attribute values as global
 * WooCommerce attributes, so WooCommerce's attribute filters can list them.
 *
 * Each attribute key becomes one `pa_<key>` attribute named by its label, and
 * each value a term named by its shopper-facing label. Attributes and terms
 * the run created are removed on rollback; ones that already existed are
 * reused and kept. A key WooCommerce rejects is reported as a product loss.
 *
 * Run: php tests/smoke-woo-product-attributes.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Standalone smoke stubs WordPress and WooCommerce.

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
}
class WP_Post {
	public function __construct( public int $ID, public string $post_type = 'product' ) {}
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function sanitize_title( string $value ): string {
	return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) ); }
function wp_kses_post( string $value ): string {
	return $value; }
function post_type_exists( string $type ): bool {
	return 'product' === $type; }
function taxonomy_exists( string $taxonomy ): bool {
	return 'product_cat' === $taxonomy || isset( $GLOBALS['taxonomies'][ $taxonomy ] ); }
function register_taxonomy( string $taxonomy ): void {
	$GLOBALS['taxonomies'][ $taxonomy ] = true; }
function get_page_by_path( string $slug, mixed $output = OBJECT, string $post_type = 'post' ): ?WP_Post {
	if ( isset( $GLOBALS['attachments_by_slug'][ $slug ] ) ) {
		return new WP_Post( $GLOBALS['attachments_by_slug'][ $slug ], 'attachment' );
	}
	return isset( $GLOBALS['products_by_slug'][ $slug ] ) ? new WP_Post( $GLOBALS['products_by_slug'][ $slug ], 'product' ) : null; }
function get_post( int $id ): ?array {
	return $GLOBALS['products'][ $id ] ?? null; }
function get_post_meta( int $id ): array {
	return $GLOBALS['meta'][ $id ] ?? array(); }
function delete_post_meta( int $id, string $key ): void {
	unset( $GLOBALS['meta'][ $id ][ $key ] ); }
function maybe_unserialize( mixed $value ): mixed {
	return $value; }
function add_post_meta( int $id, string $key, mixed $value ): void {
	$GLOBALS['meta'][ $id ][ $key ][] = $value; }
function wp_update_post( array $post ): int {
	$GLOBALS['products'][ $post['ID'] ] = $post;
	return $post['ID']; }
function wp_delete_post( int $id ): bool {
	foreach ( $GLOBALS['relations'][ $id ] ?? array() as $ids ) {
		foreach ( $ids as $term_id ) {
			--$GLOBALS['terms'][ $term_id ]['count'];
		}
	}
	unset( $GLOBALS['products'][ $id ], $GLOBALS['meta'][ $id ], $GLOBALS['relations'][ $id ] );
	$GLOBALS['products_by_slug'] = array_diff( $GLOBALS['products_by_slug'], array( $id ) );
	return true; }
function term_exists( string $term, string $taxonomy = 'product_cat' ): ?array {
	foreach ( $GLOBALS['terms'] as $id => $row ) {
		if ( $row['taxonomy'] === $taxonomy && ( $row['slug'] === $term || $row['name'] === $term ) ) {
			return array( 'term_id' => $id );
		}
	}
	return null; }
function wp_insert_term( string $name, string $taxonomy = 'product_cat', array $args = array() ): array {
	$id                      = ++$GLOBALS['next_term_id'];
	$GLOBALS['terms'][ $id ] = array(
		'taxonomy' => $taxonomy,
		'name'     => $name,
		'slug'     => $args['slug'] ?? sanitize_title( $name ),
		'count'    => 0,
	);
	return array( 'term_id' => $id ); }
function get_term( int $id, string $taxonomy = 'product_cat' ): ?object {
	return isset( $GLOBALS['terms'][ $id ] ) && $GLOBALS['terms'][ $id ]['taxonomy'] === $taxonomy ? (object) $GLOBALS['terms'][ $id ] : null; }
function get_terms( array $args ): array {
	return array_keys( array_filter( $GLOBALS['terms'], static fn( array $row ): bool => $row['taxonomy'] === $args['taxonomy'] ) ); }
function wp_delete_term( int $id ): bool {
	unset( $GLOBALS['terms'][ $id ] );
	return true; }
// Term meta lives inside the stub term row, so wp_delete_term() removes it
// with the term, the way WordPress deletes term meta.
function update_term_meta( int $term_id, string $meta_key, mixed $meta_value ): bool {
	$GLOBALS['terms'][ $term_id ]['meta'][ $meta_key ] = $meta_value;
	return true; }
function get_term_meta( int $term_id, string $meta_key, bool $single = true ): mixed {
	return $GLOBALS['terms'][ $term_id ]['meta'][ $meta_key ] ?? ''; }
function wp_get_object_terms( int $id, string $taxonomy = 'product_cat' ): array {
	return $GLOBALS['relations'][ $id ][ $taxonomy ] ?? array(); }
function wp_set_object_terms( int $id, array $terms, string $taxonomy = 'product_cat' ): array {
	foreach ( $GLOBALS['relations'][ $id ][ $taxonomy ] ?? array() as $term_id ) {
		--$GLOBALS['terms'][ $term_id ]['count'];
	}
	$GLOBALS['relations'][ $id ][ $taxonomy ] = array_map( 'intval', $terms );
	foreach ( $terms as $term_id ) {
		++$GLOBALS['terms'][ $term_id ]['count'];
	}
	return $terms; }
function get_option( string $name, mixed $fallback = false ): mixed {
	return $fallback; }
function add_option(): bool {
	return true; }
function delete_option(): bool {
	return true; }

// WooCommerce global attributes.
function wc_sanitize_taxonomy_name( string $name ): string {
	return strtolower( trim( preg_replace( '/[^a-z0-9_]+/i', '-', $name ), '-' ) ); }
function wc_attribute_taxonomy_name( string $slug ): string {
	return 'pa_' . $slug; }
function wc_attribute_taxonomy_id_by_name( string $slug ): int {
	return (int) array_search( $slug, array_column( $GLOBALS['attributes'], 'slug', 'id' ), true ); }
function wc_create_attribute( array $args ): int|WP_Error {
	if ( strlen( $args['slug'] ) > 28 ) {
		return new WP_Error( 'invalid_product_attribute_slug_too_long', 'Slug too long.' );
	}
	$id                           = ++$GLOBALS['next_attribute_id'];
	$GLOBALS['attributes'][ $id ] = array(
		'id'   => $id,
		'name' => $args['name'],
		'slug' => $args['slug'],
	);
	return $id; }
function wc_get_attribute( int $id ): ?object {
	return isset( $GLOBALS['attributes'][ $id ] ) ? (object) array(
		'id'   => $id,
		'slug' => 'pa_' . $GLOBALS['attributes'][ $id ]['slug'],
	) : null; }
function wc_delete_attribute( int $id ): bool {
	unset( $GLOBALS['attributes'][ $id ], $GLOBALS['taxonomies'][ 'pa_' . ( $GLOBALS['attributes'][ $id ]['slug'] ?? '' ) ] );
	return true; }
function wc_get_attribute_taxonomy_names(): array {
	return array_map( static fn( array $row ): string => 'pa_' . $row['slug'], array_values( $GLOBALS['attributes'] ) ); }

class WC_Product_Attribute {
	private array $data = array( 'options' => array() );
	public function __call( string $method, array $args ): mixed {
		if ( str_starts_with( $method, 'set_' ) ) {
			$this->data[ substr( $method, 4 ) ] = $args[0];
			return null;
		}
		return $this->data[ substr( $method, 4 ) ] ?? null; }
}
class WC_Product_Simple {
	public array $data = array();
	public function __construct( public int $id = 0 ) {
		if ( $id > 0 && isset( $GLOBALS['products'][ $id ] ) && 'attachment' === ( $GLOBALS['products'][ $id ]['post_type'] ?? '' ) ) {
			throw new Exception( 'Invalid product.' );
		}
	}
	public function __call( string $method, array $args ): void {
		$this->data[ $method ] = $args[0] ?? null; }
	public function save(): int {
		$id                                   = 0 !== $this->id ? $this->id : ++$GLOBALS['next_product_id'];
		$slug                                 = (string) ( $this->data['set_slug'] ?? 'product-' . $id );
		$GLOBALS['products'][ $id ]           = array(
			'ID'        => $id,
			'post_type' => 'product',
			'post_name' => $slug,
		);
		$GLOBALS['products_by_slug'][ $slug ] = $id;
		// WooCommerce's data store assigns each global attribute's terms on save.
		foreach ( $this->data['set_attributes'] ?? array() as $taxonomy => $attribute ) {
			wp_set_object_terms( $id, $attribute->get_options(), $taxonomy );
		}
		$GLOBALS['saved_products'][ $id ] = $this;
		$GLOBALS['saved_attributes'][ $id ] = $this->data['set_attributes'] ?? null;
		return $id; }
}
function wc_get_product( int $id ): WC_Product_Simple {
	return new WC_Product_Simple( $id ); }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';

$assert = static function ( bool $condition, string $label, mixed $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $label . ( null === $detail ? '' : ': ' . json_encode( $detail ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone CLI assertion message.
	} };
$reset  = static function (): void {
	$GLOBALS['products']          = array();
	$GLOBALS['attachments_by_slug'] = array();
	$GLOBALS['products_by_slug']  = array();
	$GLOBALS['meta']              = array();
	$GLOBALS['relations']         = array();
	$GLOBALS['terms']             = array();
	$GLOBALS['attributes']        = array();
	$GLOBALS['taxonomies']        = array();
	$GLOBALS['saved_attributes']  = array();
	$GLOBALS['next_product_id']   = 100;
	$GLOBALS['next_term_id']      = 200;
	$GLOBALS['next_attribute_id'] = 0;
};
$light  = static fn( string $value, string $label ): array => array(
	'key'         => 'light_level',
	'label'       => 'Light level',
	'value'       => $value,
	'value_label' => $label,
);
$plant  = static fn( string $slug, array $attributes ): array => array(
	'slug'       => $slug,
	'name'       => ucfirst( $slug ),
	'attributes' => $attributes,
);

// Two products share one new attribute with different values.
$reset();
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $plant( 'fern', array( $light( 'low', 'Shade' ) ) ), $plant( 'aloe', array( $light( 'high', 'Full sun' ) ) ) ) ) );
$assert( 2 === $report['counts']['created'], 'both products are seeded', $report );
$assert( array( 1 => 'Light level' ) === array_column( $GLOBALS['attributes'], 'name', 'id' ) && array( 1 => 'light_level' ) === array_column( $GLOBALS['attributes'], 'slug', 'id' ), 'one global attribute named by its label', $GLOBALS['attributes'] );
$value_terms    = array_column( array_filter( $GLOBALS['terms'], static fn( array $row ): bool => 'pa_light_level' === $row['taxonomy'] ), 'name', 'slug' );
$expected_terms = array(
	'low'  => 'Shade',
	'high' => 'Full sun',
);
$assert( $expected_terms === $value_terms, 'each value is a term named by its shopper-facing label', $value_terms );
$fern = $GLOBALS['saved_attributes'][101]['pa_light_level'] ?? null;
$assert( $fern instanceof WC_Product_Attribute && 1 === $fern->get_id() && 'pa_light_level' === $fern->get_name() && array( 201 ) === $fern->get_options() && true === $fern->get_visible() && false === $fern->get_variation(), 'the product carries the global attribute with its own value', $fern );
$assert( array( 201 ) === wp_get_object_terms( 101, 'pa_light_level' ) && array( 202 ) === wp_get_object_terms( 102, 'pa_light_level' ), 'each product is assigned its value term' );
$assert( array( 1 ) === $report['rollback']['created_product_attributes']['attributes'] && array( 201, 202 ) === array_column( $report['rollback']['created_product_attributes']['terms'], 'term_id' ), 'the receipt records the created attribute and terms', $report['rollback'] );
$rollback = Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( 'rolled_back' === $rollback['status'] && empty( $GLOBALS['products'] ) && empty( $GLOBALS['terms'] ) && empty( $GLOBALS['attributes'] ), 'rollback removes the products, their terms, and the attribute', array( $rollback, $GLOBALS['terms'], $GLOBALS['attributes'] ) );

// An attribute and value that already exist are reused and survive rollback.
$reset();
$GLOBALS['attributes'][1]                = array(
	'id'   => 1,
	'name' => 'Light',
	'slug' => 'light_level',
);
$GLOBALS['taxonomies']['pa_light_level'] = true;
$GLOBALS['next_attribute_id']            = 1;
wp_insert_term( 'Shade', 'pa_light_level', array( 'slug' => 'low' ) );
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $plant( 'fern', array( $light( 'low', 'Shade' ) ) ) ) ) );
$assert( array( 201 ) === wp_get_object_terms( 101, 'pa_light_level' ) && array() === $report['rollback']['created_product_attributes']['attributes'] && array() === $report['rollback']['created_product_attributes']['terms'], 'existing attribute and term are reused, not recorded as created', $report['rollback'] );
Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( isset( $GLOBALS['attributes'][1], $GLOBALS['terms'][201] ), 'rollback keeps the preexisting attribute and term' );

// A key WooCommerce cannot use as an attribute slug is a reported loss.
$reset();
$report = Static_Site_Importer_Woo_Product_Seeder::seed(
	array(
		'products' => array(
			$plant(
				'fern',
				array(
					array(
						'key'         => 'an_attribute_key_longer_than_woocommerce_allows',
						'label'       => 'Long',
						'value'       => 'x',
						'value_label' => 'X',
					),
				)
			),
		),
	)
);
$assert( 'created' === $report['products'][0]['status'] && array( 'attributes.an_attribute_key_longer_than_woocommerce_allows' ) === array_column( $report['products'][0]['losses'] ?? array(), 'attribute' ), 'an attribute WooCommerce rejects is reported as a loss', $report['products'][0] );

// An existing product's attribute terms are restored on rollback.
$reset();
$GLOBALS['attributes'][1]            = array(
	'id'   => 1,
	'name' => 'Season',
	'slug' => 'season',
);
$GLOBALS['taxonomies']['pa_season']  = true;
$GLOBALS['next_attribute_id']        = 1;
$GLOBALS['products'][7]              = array(
	'ID'        => 7,
	'post_name' => 'fern',
);
$GLOBALS['products_by_slug']['fern'] = 7;
$summer                              = wp_insert_term( 'Summer', 'pa_season' )['term_id'];
wp_set_object_terms( 7, array( $summer ), 'pa_season' );
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $plant( 'fern', array( $light( 'low', 'Shade' ) ) ) ) ) );
$assert( 'updated' === $report['products'][0]['status'] && array( $summer ) === $report['rollback'][7]['attribute_terms']['pa_season'], 'the receipt captures the existing attribute terms', $report['rollback'][7] ?? null );
Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( array( $summer ) === wp_get_object_terms( 7, 'pa_season' ) && array() === wp_get_object_terms( 7, 'pa_light_level' ) && ! isset( $GLOBALS['attributes'][2] ), 'rollback restores the existing product attribute terms and removes the new attribute', array( $GLOBALS['relations'][7] ?? null, $GLOBALS['attributes'] ) );

print "Woo product attributes smoke passed\n";
