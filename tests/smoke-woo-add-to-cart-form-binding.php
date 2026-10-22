<?php
/** Product-detail form binding regression without WordPress. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function apply_filters( string $hook, mixed $value ): mixed {
	return $value; }
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once ABSPATH . 'includes/class-static-site-importer-product-finding-materializer.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';
function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
$form                   = '<form method="post" action="/cart/add"><input type="hidden" name="id" value="v-small"><select name="size"><option>Small</option></select><select name="color"><option>Blue</option></select><input name="quantity" type="number" value="1"><button>Add</button></form>';
$fallback               = array(
	'diagnostic_code' => 'html_form_fallback',
	'form_intent'     => 'commerce_add_to_cart',
	'source_path'     => 'products/shirt.html',
	'selector'        => 'form.purchase',
	'form'            => array(
		'method' => 'post',
		'action' => '/cart/add',
	),
	'controls'        => array(
		array(
			'type'  => 'hidden',
			'name'  => 'id',
			'value' => 'v-small',
		),
		array(
			'type' => 'select',
			'name' => 'size',
		),
		array(
			'type' => 'select',
			'name' => 'color',
		),
		array(
			'type' => 'number',
			'name' => 'quantity',
		),
	),
	'bindings'        => array(
		array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => 'products/shirt.html',
			'search_block_markup' => $form,
			'occurrence'          => 1,
			'role'                => 'form',
		),
	),
);
$contact                = $fallback;
$contact['form_intent'] = 'contact';
$contact['selector']    = 'form.contact';
$adapter                = Static_Site_Importer_Woo_Product_Seeder::adapter();
$product                = array(
	'slug'       => 'shirt',
	'url'        => 'https://example.test/products/shirt',
	'attributes' => array(
		array(
			'key'         => 'size',
			'label'       => 'Size',
			'value'       => 'small',
			'value_label' => 'Small',
		),
		array(
			'key'         => 'color',
			'label'       => 'Color',
			'value'       => 'blue',
			'value_label' => 'Blue',
		),
	),
	'variants'   => array(
		array(
			'source_id' => 'different-variant',
			'sku'       => 'SMALL',
		),
	),
);
$lifecycle              = array(
	'entities' => array(
		'catalog' => array(
			'adapter'  => $adapter,
			'manifest' => array( 'products' => array( $product ) ),
		),
	),
);
foreach ( array( true, false ) as $seeded ) {
	$reports  = array(
		'catalog' => array(
			'products' => $seeded ? array(
				array(
					'slug'   => 'shirt',
					'id'     => 42,
					'status' => 'created',
				),
			) : array(),
		),
	);
	$bound    = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings( $lifecycle, $reports, array( $fallback, $contact ) );
	$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $bound, $reports );
	check( count( $bindings ) === ( $seeded ? 1 : 0 ), 'Only matching commerce form binds' );
	$original = '<h1>Shirt</h1><p>20</p><img src="shirt.jpg">' . $form . '<form class="contact">Contact</form>';
	$content  = $original;
	foreach ( $bindings as &$binding ) {
		$content                              = str_replace( $binding['search_block_markup'], $binding['replacement_block_markup'], $content );
		$binding['status']                    = 'completed';
		$binding['persisted_fragment_hash']   = $binding['materialized_block_hash'];
		$binding['materialized_content_hash'] = hash( 'sha256', $content );
	}
	unset( $binding );
	if ( $seeded ) {
		check( str_contains( $content, 'woocommerce/add-to-cart-form' ) && str_contains( $content, '"productId":42' ), 'Native product context' );
		check( str_starts_with( $content, '<h1>Shirt</h1><p>20</p><img src="shirt.jpg">' ) && str_ends_with( $content, '<form class="contact">Contact</form>' ), 'Surrounding markup preserved' );
		$classic = Static_Site_Importer_Woo_Product_Seeder::binding_classic_render( array( 'entity_kind' => 'add_to_cart_form' ), array( 'id' => 42 ) );
		check( str_contains( $classic['content'], 'show_price="false"' ), 'Classic equivalent' );
	} else {
		check( $content === $original, 'Unmatched source unchanged' );
	}
	$report                = Static_Site_Importer_Import_Report::from_array( array() );
	$report['diagnostics'] = array( $fallback, $contact );
	$report->set_quality( array( 'fallback_count' => 2 ) );
	$report['materialization_receipt'] = array(
		'completed' => array(
			'runtime_declarations' => array( 'entity_bindings' => $bindings ),
			'materialized_pages'   => array( 'products/shirt.html' => array( 'content_hash' => hash( 'sha256', $content ) ) ),
		),
	);
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
	$resolution = $report['fallback_reconciliation']['resolutions'][0];
	check( $resolution['state'] === ( $seeded ? 'resolved_by_provider' : 'unresolved' ), 'Receipt state' );
	check( 'unresolved' === $report['fallback_reconciliation']['resolutions'][1]['state'], 'Contact fallback not reconciled by commerce' );
	if ( ! $seeded ) {
		check( 'no_seeded_product_for_page' === $resolution['reason'], 'Explicit unmatched reason' );
	}
}
// A different source page can still match by producer variant identity, never by text.
$fallback['source_path'] = 'other.html';
$lifecycle['entities']['catalog']['manifest']['products'][0]['variants'][0]['source_id'] = 'v-small';
$reports = array(
	'catalog' => array(
		'products' => array(
			array(
				'slug'   => 'shirt',
				'id'     => 42,
				'status' => 'created',
			),
		),
	),
);
$bound   = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings( $lifecycle, $reports, array( $fallback ) );
check( 2 === count( $bound['entities'] ), 'Variant evidence fallback' );
echo "Woo add-to-cart form binding passed.\n";
