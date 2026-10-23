<?php
/**
 * Purchase-form placement fallback attachment regression without WordPress.
 *
 * One product page carries two placements of the purchase form (desktop and
 * mobile document copies) with distinct source anchors, and each placement's
 * producer binding names its own pre-projection anchor through
 * `projected_anchor.source_block_hash`. Every placement must carry the
 * fallback whose own anchor hashes to it — never the page's last fallback —
 * so each receipt reconciles its own fallback.
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
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
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
$desktop_form                     = '<!-- wp:html --><form method="post" action="/cart/add" class="purchase desktop"><input type="hidden" name="id" value="v-hoodie"><button>Add to cart</button></form><!-- /wp:html -->';
$mobile_form                      = '<!-- wp:html --><form method="post" action="/cart/add" class="purchase mobile"><input type="hidden" name="id" value="v-hoodie"><button>Add to cart</button></form><!-- /wp:html -->';
$base_fallback                    = array(
	'diagnostic_code' => 'html_form_fallback',
	'form_intent'     => 'commerce_add_to_cart',
	'source_path'     => 'products/hoodie.html',
);
$desktop_fallback                 = $base_fallback;
$desktop_fallback['selector']     = 'form.purchase.desktop';
$desktop_fallback['html_excerpt'] = $desktop_form;
$desktop_fallback['bindings']     = array(
	array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'products/hoodie.html',
		'search_block_markup' => $desktop_form,
		'occurrence'          => 1,
		'role'                => 'form',
	),
);
$mobile_fallback                  = $base_fallback;
$mobile_fallback['selector']      = 'form.purchase.mobile';
$mobile_fallback['html_excerpt']  = $mobile_form;
$mobile_fallback['bindings']      = array(
	array(
		'schema'              => 'generic/block-binding/v1',
		'source_path'         => 'products/hoodie.html',
		'search_block_markup' => $mobile_form,
		'occurrence'          => 1,
		'role'                => 'form',
	),
);
$desktop_identity                 = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $desktop_fallback );
$mobile_identity                  = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $mobile_fallback );
check( '' !== $desktop_identity && $desktop_identity !== $mobile_identity, 'Distinct anchors produce distinct fallback identities' );
// Producer topology: the bindings list their anchors in reverse fallback
// order, each naming its own pre-projection anchor, so page-scoped attachment
// would give both placements the page's last fallback.
$projected  = static function ( string $markup ): array {
	return array(
		'schema'            => 'blocks-engine/projected-binding-anchor/v1',
		'source_block_hash' => hash( 'sha256', $markup ),
	);
};
$adapter    = Static_Site_Importer_Woo_Product_Seeder::adapter();
$lifecycle  = array(
	'entities' => array(
		'catalog' => array(
			'adapter'  => $adapter,
			'manifest' => array(
				'products' => array(
					array(
						'slug'     => 'hoodie',
						'url'      => 'https://example.test/products/hoodie',
						'variants' => array(
							array(
								'source_id' => 'v-hoodie',
								'sku'       => 'HOOD',
							),
						),
						'bindings' => array(
							array(
								'source_path'         => 'products/hoodie.html',
								'search_block_markup' => $mobile_form,
								'occurrence'          => 1,
								'role'                => 'commerce_add_to_cart_form',
								'projected_anchor'    => $projected( $mobile_form ),
							),
							array(
								'source_path'         => 'products/hoodie.html',
								'search_block_markup' => $desktop_form,
								'occurrence'          => 1,
								'role'                => 'commerce_add_to_cart_form',
								'projected_anchor'    => $projected( $desktop_form ),
							),
						),
					),
				),
			),
		),
	),
);
// Producer bindings reach this join only through manifest normalization,
// which must keep each placement's pre-projection anchor hash.
$normalize = new ReflectionMethod( Static_Site_Importer_Entity_Materializer_Registry::class, 'normalize_block_binding' );
foreach ( $lifecycle['entities']['catalog']['manifest']['products'][0]['bindings'] as $binding_index => $binding ) {
	$normalized = $normalize->invoke( null, array( 'schema' => 'generic/block-binding/v1' ) + $binding );
	check( is_array( $normalized ) && ( $normalized['projected_anchor']['source_block_hash'] ?? null ) === $binding['projected_anchor']['source_block_hash'], 'Normalization keeps placement ' . $binding_index . ' anchor hash' );
	$lifecycle['entities']['catalog']['manifest']['products'][0]['bindings'][ $binding_index ] = $normalized;
}
$reports    = array(
	'catalog' => array(
		'products' => array(
			array(
				'slug'   => 'hoodie',
				'id'     => 42,
				'status' => 'created',
			),
		),
	),
);
$resolved   = array(
	'pages' => array(
		array(
			'source_path'           => 'products/hoodie.html',
			'resolved_block_markup' => '<!-- wp:paragraph --><p>Hoodie</p><!-- /wp:paragraph -->' . $desktop_form . $mobile_form,
		),
	),
);
$bound      = Static_Site_Importer_Entity_Materializer_Registry::with_product_form_bindings(
	$lifecycle,
	$reports,
	array( $desktop_fallback, $mobile_fallback ),
	$resolved
);
$attached   = static function ( mixed $placement ): string {
	$fallback = is_array( $placement ) ? ( $placement['product_form_fallback'] ?? null ) : null;
	return is_array( $fallback ) ? Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $fallback ) : '';
};
$placements = $bound['entities']['catalog']['manifest']['products'][0]['bindings'];
check( $mobile_identity === $attached( $placements[0] ?? null ), 'Mobile placement carries its own fallback' );
check( $desktop_identity === $attached( $placements[1] ?? null ), 'Desktop placement carries its own fallback' );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $bound, $reports );
check( 2 === count( $bindings ), 'Both declared placements bind' );
$content = (string) $resolved['pages'][0]['resolved_block_markup'];
foreach ( $bindings as &$binding ) {
	if ( $mobile_form === $binding['search_block_markup'] ) {
		check( $mobile_identity === $binding['fallback_reconciliation_identity'], 'Mobile receipt joins the mobile fallback' );
	} else {
		check( $desktop_form === $binding['search_block_markup'] && $desktop_identity === $binding['fallback_reconciliation_identity'], 'Desktop receipt joins the desktop fallback' );
	}
	$content = str_replace( $binding['search_block_markup'], $binding['replacement_block_markup'], $content );
}
unset( $binding );
// Both placements persist into the same page copy, so every completed
// receipt proves the one final persisted content.
foreach ( $bindings as &$binding ) {
	$binding['status']                    = 'completed';
	$binding['persisted_fragment_hash']   = $binding['materialized_block_hash'];
	$binding['materialized_content_hash'] = hash( 'sha256', $content );
}
unset( $binding );
$report                = Static_Site_Importer_Import_Report::from_array( array() );
$report['diagnostics'] = array( $desktop_fallback, $mobile_fallback );
$report->set_quality( array( 'fallback_count' => 2 ) );
$report['materialization_receipt'] = array(
	'completed' => array(
		'runtime_declarations' => array( 'entity_bindings' => $bindings ),
		'materialized_pages'   => array( 'products/hoodie.html' => array( 'content_hash' => hash( 'sha256', $content ) ) ),
	),
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
$resolutions        = $report['fallback_reconciliation']['resolutions'];
$states_by_identity = array();
foreach ( $resolutions as $resolution ) {
	$states_by_identity[ (string) ( $resolution['fallback_reconciliation_identity'] ?? '' ) ] = (string) ( $resolution['state'] ?? '' );
}
check( 2 === count( $resolutions ), 'Both purchase-form fallbacks report a resolution' );
check( 'resolved_by_provider' === ( $states_by_identity[ $desktop_identity ] ?? '' ), 'Desktop fallback resolves through its own placement receipt' );
check( 'resolved_by_provider' === ( $states_by_identity[ $mobile_identity ] ?? '' ), 'Mobile fallback resolves through its own placement receipt' );
echo "Product form binding placement passed.\n";
