<?php
/** Intent routing regression; run with plain PHP. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	if ( 'ssi_entity_materializer_provider' === $hook ) {
		return 'fixture-provider';
	}
	if ( 'static_site_importer_entity_materializers' === $hook ) {
		$value['fixture-shop'] = array(
			'id' => 'fixture-shop',
			'provider' => 'fixture-provider',
			'capability' => 'shop',
			'entity_collection' => 'products',
			'rollback_contract_id' => 'fixture/v1',
			'materializer' => static fn( array $manifest ): array => array( 'products' => $manifest['products'], 'status' => 'completed' ),
		);
	}
	return $value;
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-quality-gates.php';

$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
$fixture = array(
	array(
		'form_intent' => 'commerce_add_to_cart',
		'selector' => 'form.cart',
		'form' => array( 'method' => 'post', 'action' => '/cart/add' ),
		'html' => '<form method="post" action="/cart/add"><input type="hidden" name="id" value="12"><input type="number" name="quantity" value="1"><button>Add to cart</button></form>',
		'controls' => array( array( 'tag' => 'input', 'type' => 'hidden', 'name' => 'id' ), array( 'tag' => 'input', 'type' => 'number', 'name' => 'quantity' ) ),
		'materialization_target' => array( 'capability' => 'shop', 'entity' => 'commerce_controls' ),
		'materialization_hint' => 'commerce_cart_runtime',
	),
	array(
		'form_intent' => 'filter_sort',
		'selector' => 'form.sort',
		'form' => array( 'method' => 'get', 'action' => '/items' ),
		'html' => '<form method="get" action="/items"><select name="sort"><option>Price</option></select><button>Sort</button></form>',
		'controls' => array( array( 'tag' => 'select', 'name' => 'sort', 'options' => array( array( 'value' => 'price', 'label' => 'Price' ) ) ) ),
	),
	array(
		'form_intent' => 'contact',
		'selector' => 'form.contact',
		'form' => array( 'method' => 'post', 'action' => '/contact' ),
		'html' => '<form method="post" action="/contact"><input type="email" name="email"><textarea name="message"></textarea><button>Send</button></form>',
		'controls' => array( array( 'tag' => 'input', 'type' => 'email', 'name' => 'email' ), array( 'tag' => 'textarea', 'name' => 'message' ) ),
	),
);
foreach ( $fixture as &$finding ) {
	$finding['source_path'] = 'index.html';
	$finding['diagnostic_code'] = 'html_form_fallback';
}
unset( $finding );
// Declarations need not carry intent: recover it from matching findings even
// when the producer did not include HTML on the finding.
$entities = $fixture;
foreach ( $entities as &$entity ) {
	unset( $entity['form_intent'], $entity['html'] );
}
unset( $entity );
$plan = array( 'quality' => array( 'fallbacks' => $fixture ) );
$candidates = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $entities, $plan );
$assert( array( 'form.contact' ) === array_column( $candidates, 'selector' ), 'Only contact reaches the form provider' );
$without_html = $plan;
foreach ( $without_html['quality']['fallbacks'] as &$finding ) {
	unset( $finding['html'] );
}
unset( $finding );
$assert( array( 'form.contact' ) === array_column( Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $entities, $without_html ), 'selector' ), 'Intent recovery does not depend on HTML' );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $fixture ) );
$assert( array( 'form.contact' ) === array_column( $validated['forms'], 'selector' ), 'Direct manifests also exclude cart and sort' );
// The released commerce target selects the shop, not the form provider.
$shop = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( $fixture[0]['materialization_target']['capability'] );
$assert( 'fixture-shop' === ( $shop['id'] ?? '' ), 'Cart selects the configured shop adapter' );
$cart_result = Static_Site_Importer_Entity_Materializer_Registry::materialize( $shop, array( 'products' => array( $fixture[0] ) ) );
$assert( 'completed' === ( $cart_result['status'] ?? '' ), 'Commerce provider handles the cart' );
$assert( Static_Site_Importer_Quality_Gates::is_commerce_controls_fallback_diagnostic( $fixture[0] ), 'Cart reconciles as commerce controls' );
$assert( Static_Site_Importer_Quality_Gates::is_form_fallback_diagnostic( $fixture[0] ), 'Cart remains a tracked fallback until commerce binding proves resolution' );
$assert( ! Static_Site_Importer_Quality_Gates::is_form_fallback_diagnostic( $fixture[1] ), 'Sort never receives a form loss decision' );
$legacy = $fixture[2];
unset( $legacy['form_intent'] );
$assert( 1 === count( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $legacy ) ) )['forms'] ), 'Absent intent retains provider behavior' );
$assert( Static_Site_Importer_Quality_Gates::is_form_fallback_diagnostic( $legacy ), 'Absent intent retains gate behavior' );
foreach ( array( 'search', 'login', 'filter_sort' ) as $intent ) {
	$row = array_merge( $fixture[2], array( 'form_intent' => $intent ) );
	$assert( array() === Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $row ) ) )['forms'], $intent . ' is preserved, not converted to a contact form' );
}
$row = array_merge( $fixture[2], array( 'form_intent' => 'newsletter' ) );
$assert( 1 === count( Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $row ) ) )['forms'] ), 'Newsletter retains provider behavior' );
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo "Form intent routing smoke passed.\n";
