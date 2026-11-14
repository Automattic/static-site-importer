<?php
/**
 * Regression coverage for bounded global attribute slugs and nonfatal failures.
 *
 * Run: php tests/smoke-woo-long-product-attributes.php
 *
 * @package StaticSiteImporter
 */

require __DIR__ . '/smoke-woo-variable-product.php';

$reset();
$label = 'Sélection de finition pour un accessoire de bureau';
$assert( strlen( wc_sanitize_taxonomy_name( $label ) ) > 28, 'fixture exceeds Woo slug limit' );
$option = static fn( string $value ): array => array(
	'key'         => $label,
	'label'       => $label,
	'value'       => $value,
	'value_label' => ucfirst( $value ),
);
$product = array(
	'slug'     => 'desk-accessory',
	'name'     => 'Desk accessory',
	'variants' => array(
		array( 'regular_price' => '10', 'stock_status' => 'instock', 'attributes' => array( $option( 'matte' ) ) ),
		array( 'regular_price' => '12', 'stock_status' => 'instock', 'attributes' => array( $option( 'gloss' ) ) ),
	),
);
$second = $product;
$second['slug'] = 'another-accessory';
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $product, $second ) ) );
$assert( 2 === $report['counts']['created'], 'both variable products created', $report );
$assert( 1 === count( $GLOBALS['attributes'] ), 'long option reused across products' );
$global = reset( $GLOBALS['attributes'] );
$assert( $label === $global['name'] && strlen( $global['slug'] ) <= 28, 'original label and bounded slug' );
$taxonomy = wc_attribute_taxonomy_name( $global['slug'] );
foreach ( $report['products'] as $row ) {
	$parent = $GLOBALS['saved_products'][ $row['id'] ];
	$assert( $parent instanceof WC_Product_Variable && empty( $row['losses'] ), 'variable product without losses', $row );
	$attribute = $parent->data['set_attributes'][ $taxonomy ] ?? null;
	$assert( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() && 2 === count( $attribute->get_options() ), 'parent uses shared variation taxonomy' );
}
$assert( 4 === count( $report['rollback']['created_variations'] ), 'all variations created' );
foreach ( $report['rollback']['created_variations'] as $index => $id ) {
	$assert( array( $taxonomy => 0 === $index % 2 ? 'matte' : 'gloss' ) === $GLOBALS['saved_products'][ $id ]->data['set_attributes'], 'variation lookup agrees with creation' );
}

// Repeated seeding produces the same slug even when creation order changes.
$reset();
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $second ) ) );
$assert( $global === reset( $GLOBALS['attributes'] ), 'deterministic global identity' );

$reset();
$reserved = $option( 'matte' );
$reserved['key'] = 'name';
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $plant( 'reserved-option', array( $reserved ) ) ) ) );
$global = reset( $GLOBALS['attributes'] );
$assert( 1 === $report['counts']['created'] && $global && ! wc_check_if_attribute_name_is_reserved( $global['slug'] ) && strlen( $global['slug'] ) <= 28, 'reserved key receives usable slug' );

// A backend rejection must not escalate the existing attribute loss to an error.
$reset();
$GLOBALS['reject_attributes'] = true;
$report = Static_Site_Importer_Woo_Product_Seeder::seed( array( 'products' => array( $product, $plant( 'following-product', array() ) ) ) );
unset( $GLOBALS['reject_attributes'] );
$assert( 2 === $report['counts']['created'] && ! empty( $report['products'][0]['losses'] ), 'attribute rejection is a loss and import continues', $report );
$assert( empty( $report['rollback']['created_variations'] ), 'no wildcard variations for missing attributes' );

print "Woo long product attributes smoke passed\n";
