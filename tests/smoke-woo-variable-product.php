<?php
/** Run: php tests/smoke-woo-variable-product.php */
require __DIR__ . '/smoke-woo-product-attributes.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

class WC_Data_Exception extends Exception {
	public function getErrorCode(): string { return 'product_invalid_sku'; }
}
class WC_Product_Variable extends WC_Product_Simple {
	public static function sync( int $id ): void { $GLOBALS['synced'][] = $id; }
}
class WC_Product_Variation extends WC_Product_Simple {
	public function save(): int {
		// Variation attributes are slugs, not WC_Product_Attribute objects.
		$attributes = $this->data['set_attributes'];
		unset( $this->data['set_attributes'] );
		$id = parent::save();
		$this->data['set_attributes'] = $attributes;
		return $id;
	}
	public function set_sku( string $sku ): void {
		if ( 'duplicate' === $sku ) { throw new WC_Data_Exception( 'SKU already exists' ); }
		$this->data['set_sku'] = $sku;
	}
}
function get_posts( array $args ): array {
	return array_keys( array_filter( $GLOBALS['meta'], static fn( array $meta ): bool => ( $meta[$args['meta_key']][0] ?? null ) === $args['meta_value'] ) );
}
function sanitize_file_name( string $name ): string { return $name; }
function wp_upload_bits( string $name, mixed $unused, string $bytes ): array { return array( 'file' => '/uploads/' . $name ); }
function wp_insert_attachment( array $post, string $file ): int {
	$id = ++$GLOBALS['next_product_id'];
	$GLOBALS['products'][$id] = $post;
	return $id;
}
function wp_generate_attachment_metadata(): array { return array(); }
function wp_update_attachment_metadata(): void {}
function update_post_meta( int $id, string $key, mixed $value ): void { $GLOBALS['meta'][$id][$key] = array( $value ); }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
function wp_delete_attachment( int $id ): bool { return wp_delete_post( $id ); }

$reset();
$size = static fn( string $value ): array => array( 'key' => 'size', 'label' => 'Size', 'value' => $value, 'value_label' => strtoupper( $value ) );
$row = array(
	'name' => 'Generic shirt', 'slug' => 'generic-shirt', 'regular_price' => '20.00', 'sku' => 'SHIRT',
	'image' => '/media/front.jpg',
	'images' => array(
		array( 'src' => '/media/front.jpg', 'alt' => 'Front' ),
		array( 'src' => '/media/back.jpg', 'alt' => 'Back' ),
		array( 'src' => '/media/side.jpg', 'alt' => 'Side' ),
	),
	'tags' => array( 'Everyday', 'Cotton' ),
	'variants' => array(
		array( 'sku' => 'SHIRT-S', 'regular_price' => '20.00', 'sale_price' => '15.00', 'stock_status' => 'instock', 'image' => '/media/front.jpg', 'attributes' => array( $size( 's' ) ) ),
		array( 'sku' => 'SHIRT-L', 'regular_price' => '25.00', 'stock_status' => 'outofstock', 'image' => '/media/back.jpg', 'attributes' => array( $size( 'l' ) ) ),
	),
);
$validate = static fn( array $product ): array => Static_Site_Importer_Entity_Materializer_Registry::validate_woo_products_manifest( array( 'schema_version' => 1, 'products' => array( $product ) ) );
$validated = $validate( $row );
$assert( empty( $validated['errors'] ), 'new fields validate', $validated );
$resolved = array();
foreach ( $row['images'] as $image ) { $resolved[$image['src']] = array( 'bytes' => 'image bytes', 'mime_type' => 'image/jpeg' ); }
$report = Static_Site_Importer_Woo_Product_Seeder::seed( $validated, array( 'resolved_product_images' => $resolved ) );
$assert( 1 === $report['counts']['created'], 'variable product created', $report );
$id = $report['products'][0]['id'];
$parent = $GLOBALS['saved_products'][$id];
$assert( $parent instanceof WC_Product_Variable, 'variable type' );
$assert( array( $id ) === $GLOBALS['synced'], 'parent synchronized' );
$attribute = $parent->data['set_attributes']['pa_size'];
$assert( true === $attribute->get_variation() && 2 === count( $attribute->get_options() ), 'all sizes on variation-enabled global attribute' );
$assert( 'SHIRT' === $parent->data['set_sku'], 'parent SKU' );
$assert( 2 === count( $parent->data['set_gallery_image_ids'] ) && 3 === count( $report['rollback']['created_attachments'] ), 'featured plus gallery attachments' );
$assert( $report['rollback']['created_attachments'][0] === $parent->data['set_image_id'] && array_slice( $report['rollback']['created_attachments'], 1 ) === $parent->data['set_gallery_image_ids'], 'gallery order' );
$ids = $report['rollback']['created_variations'];
$assert( 2 === count( $ids ), 'two variations in ledger' );
foreach ( $ids as $index => $variation_id ) {
	$variation = $GLOBALS['saved_products'][$variation_id];
	$expected = $row['variants'][$index];
	$assert( $variation instanceof WC_Product_Variation && $id === $variation->data['set_parent_id'], 'variation parent and type' );
	foreach ( array( 'sku', 'regular_price', 'stock_status' ) as $field ) {
		$assert( $expected[$field] === $variation->data['set_' . $field], 'variation ' . $field );
	}
	$assert( ( $expected['sale_price'] ?? '' ) === $variation->data['set_sale_price'], 'sale price' );
	$assert( array( 'pa_size' => $expected['attributes'][0]['value'] ) === $variation->data['set_attributes'], 'variation attribute slugs' );
}
$assert( ! isset( $GLOBALS['saved_products'][$ids[0]]->data['set_image_id'] ) && $parent->data['set_gallery_image_ids'][0] === $GLOBALS['saved_products'][$ids[1]]->data['set_image_id'], 'only differing variation image set, reusing gallery' );
$tag_ids = wp_get_object_terms( $id, 'product_tag' );
$assert( array( 'Everyday', 'Cotton' ) === array_map( static fn( int $term ): string => $GLOBALS['terms'][$term]['name'], $tag_ids ), 'product tags' );
$rollback = Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( 'rolled_back' === $rollback['status'] && empty( $GLOBALS['products'] ) && empty( $GLOBALS['terms'] ) && empty( $GLOBALS['attributes'] ), 'rollback removes parent, variations, images, tags and attributes', $rollback );

foreach ( array( 'bad', array( array( 'regular_price' => '20.00' ) ), array( $row['variants'][0], $row['variants'][0] ) ) as $malformed ) {
	$bad = $row;
	$bad['variants'] = $malformed;
	$result = $validate( $bad );
	$assert( empty( $result['errors'] ) && ! isset( $result['products'][0]['variants'] ) && 'variants' === $result['products'][0]['losses'][0]['attribute'], 'malformed variants dropped with loss' );
	$reset();
	$seeded = Static_Site_Importer_Woo_Product_Seeder::seed( $result );
	$simple = $GLOBALS['saved_products'][$seeded['products'][0]['id']];
	$assert( WC_Product_Simple::class === get_class( $simple ) && ! empty( $seeded['products'][0]['losses'] ), 'fallback seeds simple with loss' );
}
foreach ( array( 'sku' => 42, 'images' => array( array( 'src' => 'x', 'alt' => 42 ) ), 'tags' => array( 42 ), 'stock_status' => 'unknown', 'sale_price' => 42 ) as $field => $bad_value ) {
	$bad = $row;
	$bad[$field] = $bad_value;
	$assert( ! empty( $validate( $bad )['errors'] ), 'malformed ' . $field . ' rejected' );
}
$reset();
$conflict = $row;
$conflict['variants'][0]['sku'] = 'duplicate';
$report = Static_Site_Importer_Woo_Product_Seeder::seed( $validate( $conflict ), array( 'resolved_product_images' => $resolved ) );
$assert( 1 === $report['counts']['created'] && 'sku' === $report['products'][0]['losses'][0]['attribute'], 'duplicate SKU records loss without losing product', $report );
$assert( '' === $GLOBALS['saved_products'][$report['rollback']['created_variations'][0]]->data['set_sku'], 'conflicting SKU cleared' );
$reset();
$simple_row = array( 'name' => 'Generic item', 'slug' => 'generic-item', 'regular_price' => '20.00', 'sale_price' => '12.00', 'sku' => 'ITEM', 'stock_status' => 'outofstock' );
$report = Static_Site_Importer_Woo_Product_Seeder::seed( $validate( $simple_row ) );
$simple = $GLOBALS['saved_products'][$report['products'][0]['id']];
$assert( 'ITEM' === $simple->data['set_sku'] && '12.00' === $simple->data['set_sale_price'] && 'outofstock' === $simple->data['set_stock_status'], 'simple fields preserved' );
// Updating an existing product restores its previous tags and leaves shared terms.
$reset();
register_taxonomy( 'product_tag' );
$GLOBALS['products'][7] = array( 'ID' => 7, 'post_name' => 'generic-item' );
$GLOBALS['products_by_slug']['generic-item'] = 7;
$original_tag = wp_insert_term( 'Original tag', 'product_tag' )['term_id'];
wp_set_object_terms( 7, array( $original_tag ), 'product_tag' );
$simple_row['tags'] = array( 'New tag' );
$report = Static_Site_Importer_Woo_Product_Seeder::seed( $validate( $simple_row ) );
$assert( array( $original_tag ) === $report['rollback'][7]['tags'], 'prior tags captured' );
$rollback = Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( 'rolled_back' === $rollback['status'] && array( $original_tag ) === wp_get_object_terms( 7, 'product_tag' ) && 1 === count( $GLOBALS['terms'] ), 'rollback restores original tags and deletes only created tags' );

// Created attribute terms carry an `order` term meta — the position
// WooCommerce's menu_order term ordering reads — in first-seen source order,
// so variation options present as S, M, L, XL instead of alphabetically.
$reset();
$tee    = array(
	'name'          => 'Tee',
	'slug'          => 'tee',
	'regular_price' => '20.00',
	'sku'           => 'TEE',
	'variants'      => array(
		array( 'sku' => 'TEE-S', 'regular_price' => '20.00', 'stock_status' => 'instock', 'attributes' => array( $size( 's' ) ) ),
		array( 'sku' => 'TEE-M', 'regular_price' => '20.00', 'stock_status' => 'instock', 'attributes' => array( $size( 'm' ) ) ),
		array( 'sku' => 'TEE-L', 'regular_price' => '25.00', 'stock_status' => 'instock', 'attributes' => array( $size( 'l' ) ) ),
		array( 'sku' => 'TEE-XL', 'regular_price' => '25.00', 'stock_status' => 'instock', 'attributes' => array( $size( 'xl' ) ) ),
	),
);
$hoodie = array(
	'name'          => 'Hoodie',
	'slug'          => 'hoodie',
	'regular_price' => '40.00',
	'sku'           => 'HOODIE',
	'variants'      => array(
		array( 'sku' => 'HOODIE-M', 'regular_price' => '40.00', 'stock_status' => 'instock', 'attributes' => array( $size( 'm' ) ) ),
		array( 'sku' => 'HOODIE-XXL', 'regular_price' => '45.00', 'stock_status' => 'instock', 'attributes' => array( $size( 'xxl' ) ) ),
	),
);
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $tee, $hoodie ) ) );
$assert( 2 === $report['counts']['created'], 'both sized products created', $report );
$size_orders = array();
foreach ( $GLOBALS['terms'] as $term_row ) {
	if ( 'pa_size' === $term_row['taxonomy'] ) {
		$size_orders[ $term_row['slug'] ] = $term_row['meta']['order'];
	}
}
$assert( array( 's' => 0, 'm' => 1, 'l' => 2, 'xl' => 3, 'xxl' => 4 ) === $size_orders, 'created terms carry order meta 0..4 in first-seen source order', $size_orders );
$rollback = Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( 'rolled_back' === $rollback['status'] && empty( $GLOBALS['terms'] ), 'rollback removes the created terms with their order meta', $rollback );

// A term that already exists keeps its stored order; a created term follows
// the taxonomy's current maximum, and rollback needs no separate meta cleanup.
$reset();
$GLOBALS['attributes'][1]         = array( 'id' => 1, 'name' => 'Size', 'slug' => 'size' );
$GLOBALS['taxonomies']['pa_size'] = true;
$GLOBALS['next_attribute_id']     = 1;
$existing_m                       = wp_insert_term( 'M', 'pa_size', array( 'slug' => 'm' ) )['term_id'];
update_term_meta( $existing_m, 'order', 3 );
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $tee ) ) );
$assert( 1 === $report['counts']['created'], 'product seeded against the existing size', $report );
$created_s = (int) term_exists( 's', 'pa_size' )['term_id'];
$assert( 3 === get_term_meta( $existing_m, 'order', true ) && 4 === get_term_meta( $created_s, 'order', true ), 'a pre-existing term keeps its order while the created one follows the taxonomy maximum', array( $existing_m => get_term_meta( $existing_m, 'order', true ), $created_s => get_term_meta( $created_s, 'order', true ) ) );
$rollback = Static_Site_Importer_Woo_Product_Seeder::rollback( $report );
$assert( 'rolled_back' === $rollback['status'] && array( $existing_m ) === array_keys( $GLOBALS['terms'] ) && 3 === get_term_meta( $existing_m, 'order', true ), 'rollback removes only the created term, leaving the pre-existing order untouched', $rollback );
// An attachment with the product slug must not be treated as an existing product.
$reset();
$GLOBALS['products'][55] = array( 'ID' => 55, 'post_type' => 'attachment', 'post_name' => 'generic-item' );
$GLOBALS['attachments_by_slug']['generic-item'] = 55;
$report = Static_Site_Importer_Woo_Product_Seeder::seed( $validate( $simple_row ) );
$assert( 1 === $report['counts']['created'] && 55 !== $report['products'][0]['id'], 'attachment slug collision creates a new product', $report );
$assert( 'product' === $GLOBALS['products'][ $report['products'][0]['id'] ]['post_type'], 'created record has product post type' );

print "Woo variable product smoke passed\n";
