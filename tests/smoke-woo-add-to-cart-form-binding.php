<?php
/**
 * Product-detail form binding regression without WordPress.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
/**
 * Return the unchanged filter value in this standalone fixture.
 *
 * @param string $hook Filter name.
 * @param mixed  $value Filter value.
 * @param mixed  ...$args Additional filter arguments.
 */
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Match the WordPress filter API.
	return $value; }
/**
 * Encode fixture metadata without loading WordPress.
 *
 * @param mixed $value Fixture metadata.
 * @param int   $flags Encoding flags.
 * @param int   $depth Maximum depth.
 * @phpstan-param int<1, max> $depth
 */
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-local WordPress stub.
}
/** Serialize the fixture's leaf HTML blocks.
 *
 * @param array<int,array<string,mixed>> $blocks Fixture blocks.
 */
function serialize_blocks( array $blocks ): string {
	return implode( '', array_column( $blocks, 'innerHTML' ) );
}
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once ABSPATH . 'includes/class-static-site-importer-product-finding-materializer.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';
require_once ABSPATH . 'includes/class-static-site-importer-site-plan-preparation.php';
/**
 * Fail this standalone regression with a useful message.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Failure message.
 */
function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test failure.
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
	$bound    = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings(
		$lifecycle,
		$reports,
		array( $fallback, $contact ),
		array(
			'pages' => array(
				array(
					'source_path'           => 'products/shirt.html',
					'resolved_block_markup' => $form,
				),
			),
		)
	);
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
		check( $original === $content, 'Unmatched source unchanged' );
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
	check( ( $seeded ? 'resolved_by_provider' : 'unresolved' ) === $resolution['state'], 'Receipt state' );
	check( 'unresolved' === $report['fallback_reconciliation']['resolutions'][1]['state'], 'Contact fallback not reconciled by commerce' );
	if ( ! $seeded ) {
		check( 'no_seeded_product_for_page' === $resolution['reason'], 'Explicit unmatched reason' );
	}
}
// Real producer topology: related cards share the page source, but are not its product.
$page_product                         = $product;
$page_product['source_path']          = 'products/shirt.html';
$readable_form                        = '<!-- wp:html --><form action="/cart/add"><input name="quantity" type="number"><button>Add</button></form><!-- /wp:html -->';
$readable_fallback                    = $fallback;
$readable_fallback['controls']        = array(
	array(
		'type' => 'number',
		'name' => 'quantity',
	),
);
$readable_fallback['bindings']        = 0;
$readable_fallback['readable_blocks'] = array(
	array(
		'blockName'    => 'core/html',
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerHTML'    => $readable_form,
		'innerContent' => array( $readable_form ),
	),
);
foreach ( array( false, true ) as $ambiguous ) {
	$products = array( $page_product );
	$rows     = array(
		array(
			'slug'   => 'shirt',
			'id'     => 42,
			'status' => 'created',
		),
	);
	foreach ( array( 'hat', 'scarf' ) as $index => $slug ) {
		$products[] = array(
			'slug'        => $slug,
			'source_path' => 'products/shirt.html',
			'selector'    => '.related .card-' . $slug,
			'bindings'    => array(
				array(
					'source_path'         => 'products/shirt.html',
					'search_block_markup' => '<p>' . $slug . '</p>',
					'occurrence'          => 1,
				),
			),
		);
		$rows[]     = array(
			'slug'   => $slug,
			'id'     => 43 + $index,
			'status' => 'created',
		);
	}
	if ( $ambiguous ) {
		$products[] = array(
			'slug'        => 'other-shirt',
			'source_path' => 'products/shirt.html',
		);
		$rows[]     = array(
			'slug'   => 'other-shirt',
			'id'     => 45,
			'status' => 'created',
		);
	}
	$card_lifecycle = $lifecycle;
	$card_lifecycle['entities']['catalog']['manifest']['products'] = $products;
	$card_reports = array( 'catalog' => array( 'products' => $rows ) );
	$bound        = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings(
		$card_lifecycle,
		$card_reports,
		array( $readable_fallback ),
		array(
			'pages' => array(
				array(
					'source_path'           => 'products/shirt.html',
					'resolved_block_markup' => $readable_form,
				),
			),
		)
	);
	$identity     = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $readable_fallback );
	check( isset( $bound['entities'][ $identity ] ) === ! $ambiguous, 'Only a unique page-level product binds despite related cards' );
	$form_bindings = array();
	$content       = $readable_form;
	if ( ! $ambiguous ) {
		$form_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $identity => $bound['entities'][ $identity ] ) ), $card_reports );
		check( 1 === count( $form_bindings ) && $readable_form === $form_bindings[0]['search_block_markup'] && str_contains( $form_bindings[0]['replacement_block_markup'], '"productId":42' ), 'Readable-only form targets page product' );
		$content                                       = $form_bindings[0]['replacement_block_markup'];
		$form_bindings[0]['status']                    = 'completed';
		$form_bindings[0]['persisted_fragment_hash']   = $form_bindings[0]['materialized_block_hash'];
		$form_bindings[0]['materialized_content_hash'] = hash( 'sha256', $content );
	}
	$report                            = Static_Site_Importer_Import_Report::from_array( array() );
	$report['diagnostics']             = array( $readable_fallback );
	$report['materialization_receipt'] = array(
		'completed' => array(
			'runtime_declarations' => array(
				'entities'        => $card_reports,
				'entity_bindings' => $form_bindings,
			),
			'materialized_pages'   => array( 'products/shirt.html' => array( 'content_hash' => hash( 'sha256', $content ) ) ),
		),
	);
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
	$resolution = $report['fallback_reconciliation']['resolutions'][0];
	check( ( $ambiguous ? 'unresolved' : 'resolved_by_provider' ) === $resolution['state'], 'Readable-only form receipt state' );
	if ( $ambiguous ) {
		check( $readable_form === $content && 'ambiguous_page_product' === $resolution['reason'], 'Ambiguous form preserved with explicit reason' );
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
$bound   = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings(
	$lifecycle,
	$reports,
	array( $fallback ),
	array(
		'pages' => array(
			array(
				'source_path'           => 'products/shirt.html',
				'resolved_block_markup' => $form,
			),
		),
	)
);
check( 2 === count( $bound['entities'] ), 'Variant evidence fallback' );

// Canonical producer bindings take precedence over stale quality anchors.
$fallback['source_path']                        = 'products/shirt.html';
$raw_anchor                                     = '<!-- wp:group {"style":{"spacing":{"padding":{"right":"30px"}}}} --><div>Form</div><!-- /wp:group -->';
$canonical_anchor                               = str_replace( '30px', 'var:preset|spacing|100', $raw_anchor );
$fallback['bindings'][0]['search_block_markup'] = $raw_anchor;
$resolved                                       = array(
	'pages' => array(
		array(
			'source_path'           => 'products/shirt.html',
			'resolved_block_markup' => $canonical_anchor,
		),
	),
);
$seeded_reports                                 = $reports;
$identity                                       = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback );
foreach ( array( false, true ) as $verbatim ) {
	$test_fallback = $fallback;
	if ( $verbatim ) {
		$test_fallback['bindings'][0]['search_block_markup'] = $canonical_anchor;
	}
	$test_reports = $seeded_reports;
	$bound        = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings( $lifecycle, $test_reports, array( $test_fallback ), $resolved );
	$bindings     = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $bound, $test_reports );
	check( count( $bindings ) === ( $verbatim ? 1 : 0 ), 'Only verbatim resolved anchors bind' );
	if ( ! $verbatim ) {
		check( ! isset( $bound['entities'][ $identity ] ) && 'anchor_not_in_page' === $test_reports[ $identity ]['product_form_binding_reason'], 'Stale anchor is reported without adding an entity' );
		$applied     = $resolved;
		$receipts    = array();
		$diagnostics = array();
		Static_Site_Importer_Site_Plan_Preparation::apply_runtime_entity_bindings( $applied, $bindings, $receipts, $diagnostics );
		check( $resolved === $applied, 'Stale anchor leaves converted markup unchanged without throwing' );
		$report                            = Static_Site_Importer_Import_Report::from_array( array() );
		$report['diagnostics']             = array( $test_fallback );
		$report['materialization_receipt'] = array(
			'completed' => array(
				'runtime_declarations' => array(
					'entities'        => $test_reports,
					'entity_bindings' => $bindings,
				),
			),
		);
		Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
		$resolution = $report['fallback_reconciliation']['resolutions'][0];
		check( 'unresolved' === $resolution['state'] && 'anchor_not_in_page' === $resolution['reason'], 'Stale fallback stays unresolved with its reason' );
	}
}

$declared_lifecycle = $lifecycle;
$declared_lifecycle['entities']['catalog']['manifest']['products'][0]['bindings'] = array(
	array(
		'source_path'         => 'products/shirt.html',
		'search_block_markup' => $canonical_anchor,
		'occurrence'          => 1,
		'role'                => 'commerce_add_to_cart_form',
	),
);
$test_reports = $seeded_reports;
$bound        = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings( $declared_lifecycle, $test_reports, array( $fallback ), $resolved );
$bindings     = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $bound, $test_reports );
check( 1 === count( $bindings ) && 1 === count( $bound['entities'] ), 'Declared page binding suppresses fallback synthesis' );
check( str_contains( $bindings[0]['replacement_block_markup'], 'woocommerce/add-to-cart-form' ) && str_contains( $bindings[0]['replacement_block_markup'], 'woocommerce/single-product' ), 'Declared product form renders native Woo blocks' );
check( $identity === $bindings[0]['fallback_reconciliation_identity'] && Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $fallback ) === $bindings[0]['fallback_hash'], 'Declared binding carries matching form fallback identity and hash' );
echo "Woo add-to-cart form binding passed.\n";
