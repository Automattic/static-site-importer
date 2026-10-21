<?php
/**
 * Smoke coverage for binding a producer order-lookup form to WooCommerce's order tracking.
 *
 * A `generic/order-lookups/v1` declaration resolves to the WooCommerce
 * order-lookup adapter under the shop capability, beside (not instead of) the
 * products and cart adapters. Each order lookup's `order_lookup` anchor is
 * replaced with the `[woocommerce_order_tracking]` shortcode block — WooCommerce
 * ships no order-tracking block, and its tracking shortcode takes exactly the
 * two captured inputs — and the completed receipt's form fallback reconciliation
 * identity lets quality reconciliation count the source `html_form_fallback`
 * finding as resolved by the provider. Without WooCommerce the anchor keeps its
 * source markup and the fallback stays unresolved, and a shop provider with no
 * order-lookup adapter leaves the optional declaration as a warning.
 *
 * Run: php tests/smoke-woo-order-lookup-binding.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
	public function get_error_data(): mixed {
		return $this->data; }
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
function apply_filters( string $hook, mixed $value ): mixed {
	foreach ( $GLOBALS['ssi_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-fallback-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-quality-gates.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-import-report.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$anchor        = '<!-- wp:html --><form class="wq-form" novalidate><label><span>Order #</span><input type="text" inputmode="numeric" placeholder="#1234" required name="orderNumber"></label><label><span>Email used at checkout</span><input type="email" autocomplete="email" required name="email"></label><button class="btn btn--primary wq-submit" type="submit">Check my order</button></form><!-- /wp:html -->';
$binding       = array(
	'schema'                       => 'generic/block-binding/v1',
	'source_path'                  => 'support.html',
	'search_block_markup'          => $anchor,
	'occurrence'                   => 1,
	'role'                         => 'order_lookup',
	'superseded_runtime_selectors' => array( 'form.wq-form' ),
);
$form_fallback = array(
	'type'        => 'unsupported_html_fallback',
	'code'        => 'html_form_fallback',
	'reason_code' => 'html_form_fallback',
	'source_path' => 'support.html',
	'selector'    => 'form.wq-form',
	'form'        => array( 'class' => 'wq-form' ),
	'controls'    => array(
		array( 'tag' => 'input', 'type' => 'text', 'name' => 'orderNumber', 'label' => 'Order #', 'placeholder' => '#1234', 'required' => true ),
		array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'label' => 'Email used at checkout', 'placeholder' => 'you@example.com', 'required' => true ),
		array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Check my order' ),
	),
);
$identity      = Static_Site_Importer_Form_Fallback_Contract::reconciliation_identity( $form_fallback );
$fallback_hash = Static_Site_Importer_Form_Fallback_Contract::reconciliation_hash( $form_fallback );
// The producer stamps the form fallback finding's identity and metadata onto the
// entity so the consumer's completed receipt proves which source form fallback
// it replaced.
$order_lookup  = array(
	'source_path'       => 'support.html',
	'selector'          => 'main > form.wq-form',
	'fields'            => array(
		'order' => array( 'label' => 'Order #', 'placeholder' => '#1234' ),
		'email' => array( 'label' => 'Email used at checkout', 'placeholder' => 'you@example.com' ),
	),
	'submit_label'      => 'Check my order',
	'fallback_identity' => $identity,
	'form'              => $form_fallback['form'],
	'controls'          => $form_fallback['controls'],
	'bindings'          => array( $binding ),
);
$lookup_id     = str_repeat( 'd', 64 );
$plan          = array(
	'runtime_declarations' => array(
		array(
			'kind'                    => 'dependency',
			'capability'              => 'shop',
			'reconciliation_identity' => str_repeat( 'a', 64 ),
			'required_for'            => array( 'entity_collection:products' ),
		),
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'products',
			'reconciliation_identity' => str_repeat( 'b', 64 ),
			'payload'                 => array(
				'schema'   => 'generic/products/v1',
				'entities' => array(
					array(
						'name'          => 'Alpha Item',
						'slug'          => 'alpha-item',
						'regular_price' => '10.00',
					),
				),
			),
		),
		array(
			'kind'                    => 'entity_collection',
			'type'                    => 'order_lookups',
			'reconciliation_identity' => $lookup_id,
			'payload'                 => array(
				'schema'   => 'generic/order-lookups/v1',
				'entities' => array( $order_lookup ),
			),
		),
	),
);

// --- 1. Adapter resolution: order lookups are a shop collection, not the shop default.
$default       = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop' );
$order_lookups = Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'order_lookups' );
$assert( 'woocommerce_simple_product' === ( $default['id'] ?? '' ), 'shop-default-adapter-stays-the-product-adapter', (string) ( $default['id'] ?? '' ) );
$assert( 'woocommerce_order_lookup' === ( $order_lookups['id'] ?? '' ), 'order-lookups-collection-resolves-to-the-woo-order-lookup-adapter', (string) ( $order_lookups['id'] ?? '' ) );
$assert( 'woocommerce_order_lookup' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'order_lookup' )['id'] ?? '' ), 'order-lookup-alias-resolves-to-the-woo-order-lookup-adapter' );
$assert( 'woocommerce_cart' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'carts' )['id'] ?? '' ), 'carts-collection-still-resolves-to-the-cart-adapter' );
$assert( 'woocommerce_simple_product' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop', 'products' )['id'] ?? '' ), 'products-collection-still-resolves-to-the-product-adapter' );

$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ), 'store-with-an-order-lookup-plans-cleanly', is_wp_error( $lifecycle ) ? $lifecycle->get_error_message() . ' ' . wp_json_encode( $lifecycle->get_error_data() ) : '' );
$prepared = is_wp_error( $lifecycle ) ? array() : ( $lifecycle['entities'][ $lookup_id ] ?? array() );
$assert( 'woocommerce_order_lookup' === ( $prepared['adapter']['id'] ?? '' ), 'order-lookup-declaration-plans-through-the-woo-order-lookup-adapter' );
$manifest_row = $prepared['manifest']['order_lookups'][0] ?? array();
$assert( 'order_lookup' === ( $manifest_row['bindings'][0]['role'] ?? '' ) && 'Order #' === ( $manifest_row['fields']['order']['label'] ?? '' ) && 'you@example.com' === ( $manifest_row['fields']['email']['placeholder'] ?? '' ) && 'Check my order' === ( $manifest_row['submit_label'] ?? '' ) && $identity === ( $manifest_row['fallback_identity'] ?? '' ), 'order-lookup-manifest-keeps-its-validated-anchor-fields-and-fallback-identity' );

// --- 2. Without WooCommerce the order lookup declines and the source markup stays.
$declined = Static_Site_Importer_Entity_Materializer_Registry::materialize( $order_lookups, $prepared['manifest'] ?? array() );
$assert( 'skipped' === ( $declined['status'] ?? '' ) && 1 === ( $declined['counts']['skipped'] ?? 0 ) && 'woocommerce_inactive' === ( $declined['reason'] ?? '' ), 'order-lookup-declines-when-woocommerce-is-absent', (string) wp_json_encode( $declined ) );
$declined_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $lookup_id => $prepared ) ), array( $lookup_id => $declined ) );
$assert( array() === $declined_bindings, 'declined-order-lookup-leaves-the-source-anchor-unreplaced', (string) wp_json_encode( $declined_bindings ) );
$unresolved_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'support.html' );
$unresolved_report->append_diagnostic( $form_fallback );
$unresolved_report->merge_quality( array( 'fallback_count' => 1 ) );
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $unresolved_report );
$unresolved_resolutions = $unresolved_report['quality_resolutions'];
$assert( 0 === ( $unresolved_resolutions['resolved_by_provider'] ?? -1 ) && 1 === ( $unresolved_resolutions['unresolved_fallback_count'] ?? 0 ) && 'unresolved' === ( $unresolved_resolutions['resolutions'][0]['state'] ?? '' ), 'unmaterialized-order-lookup-leaves-the-form-fallback-unresolved', (string) wp_json_encode( $unresolved_resolutions ) );
$waived_entities = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $lifecycle, array( 'seed_entities' => false, 'allow_missing_woocommerce' => true ) );
$assert( null === ( $waived_entities['error'] ?? null ), 'waived-order-lookup-materialization-fails-on-nothing', (string) wp_json_encode( $waived_entities['error'] ?? null ) );
$assert( 'waived' === ( $waived_entities['reports'][ $lookup_id ]['status'] ?? '' ), 'shop-waiver-waives-the-order-lookup-declaration', (string) wp_json_encode( $waived_entities['reports'][ $lookup_id ] ?? null ) );
$assert( array() === Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $waived_entities['reports'] ), 'waived-order-lookup-leaves-the-source-anchor-unreplaced', (string) wp_json_encode( Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $lifecycle, $waived_entities['reports'] ) ) );

// --- 3. With WooCommerce the anchor becomes the order tracking shortcode block.
if ( ! class_exists( 'WC_Shortcode_Order_Tracking' ) ) {
	class WC_Shortcode_Order_Tracking {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}
function wc_get_order( mixed $order ): bool {
	unset( $order );
	return false; }
$mapped = Static_Site_Importer_Entity_Materializer_Registry::materialize( $order_lookups, $prepared['manifest'] ?? array() );
$assert( 'completed' === ( $mapped['status'] ?? '' ) && 1 === ( $mapped['counts']['mapped'] ?? 0 ), 'order-lookup-maps-when-woocommerce-is-active', (string) wp_json_encode( $mapped ) );
$bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( array( 'entities' => array( $lookup_id => $prepared ) ), array( $lookup_id => $mapped ) );
$assert( is_array( $bindings ) && 1 === count( $bindings ), 'mapped-order-lookup-produces-one-binding', (string) wp_json_encode( $bindings ) );
$record = is_array( $bindings ) ? ( $bindings[0] ?? array() ) : array();
$assert( '<!-- wp:shortcode -->[woocommerce_order_tracking]<!-- /wp:shortcode -->' === ( $record['replacement_block_markup'] ?? '' ), 'order-lookup-anchor-is-replaced-by-the-order-tracking-shortcode-block', (string) ( $record['replacement_block_markup'] ?? '' ) );
$assert( $anchor === ( $record['search_block_markup'] ?? '' ) && 'order_lookup' === ( $record['role'] ?? '' ) && 'woocommerce' === ( $record['provider'] ?? '' ), 'order-lookup-binding-targets-the-whole-script-form-region' );
$assert( array( 'form.wq-form' ) === ( $record['superseded_runtime_selectors'] ?? array() ), 'order-lookup-binding-retires-the-source-form-selector' );
$assert( $identity === ( $record['fallback_reconciliation_identity'] ?? '' ) && $fallback_hash === ( $record['fallback_hash'] ?? '' ) && hash( 'sha256', (string) ( $record['replacement_block_markup'] ?? '' ) ) === ( $record['materialized_block_hash'] ?? '' ), 'order-lookup-receipt-carries-the-form-fallback-reconciliation-identity-hash-and-block-hash' );
$assert(
	array(
		'kind'    => 'shortcode',
		'content' => '[woocommerce_order_tracking]',
	) === Static_Site_Importer_Woo_Order_Lookup_Materializer::binding_classic_render( $order_lookup, $mapped['order_lookups'][0] ?? array() ),
	'classic-themes-render-the-woocommerce-order-tracking-shortcode'
);
$assert( 'rolled_back' === ( Static_Site_Importer_Woo_Order_Lookup_Materializer::rollback( $mapped )['status'] ?? '' ), 'order-lookup-rollback-has-nothing-to-restore' );

// --- 4. Quality reconciliation counts the form's html_form_fallback finding as provider-resolved.
$reconciled_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'support.html' );
$reconciled_report->append_diagnostic( $form_fallback );
$reconciled_report->merge_quality( array( 'fallback_count' => 1 ) );
$page_hash                             = hash( 'sha256', '<!-- wp:group -->support page with the tracking shortcode<!-- /wp:group -->' );
$reconciled_report['materialization_receipt'] = array(
	'completed' => array(
		'runtime_declarations' => array(
			'entity_bindings' => array(
				array_merge(
					$record,
					array(
						'status'                     => 'completed',
						'persisted_fragment_hash'    => hash( 'sha256', (string) $record['replacement_block_markup'] ),
						'materialized_content_hash'  => $page_hash,
					)
				),
			),
		),
		'materialized_pages'   => array(
			'support.html' => array( 'content_hash' => $page_hash ),
		),
	),
);
Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $reconciled_report );
$resolutions = $reconciled_report['quality_resolutions'];
$assert( 1 === ( $resolutions['source_fallback_count'] ?? 0 ) && 1 === ( $resolutions['resolved_by_provider'] ?? 0 ) && 0 === ( $resolutions['unresolved_fallback_count'] ?? -1 ), 'quality-reconciliation-resolves-the-order-lookup-form-fallback-with-a-completed-receipt', (string) wp_json_encode( $resolutions ) );
$assert( 'resolved_by_provider' === ( $resolutions['resolutions'][0]['state'] ?? '' ) && 'order_lookup' === ( $resolutions['resolutions'][0]['receipt']['role'] ?? '' ), 'order-lookup-reconciliation-receipt-keeps-the-order-lookup-role', (string) wp_json_encode( $resolutions['resolutions'][0] ?? array() ) );
$assert( 0 === ( $reconciled_report->quality()['fallback_count'] ?? -1 ), 'quality-reconciliation-decrements-fallback-count-to-zero' );

// --- 5. The validator accepts only canonical order_lookup anchors with both fields.
$wrong_role = $order_lookup;
$wrong_role['bindings'][0]['role'] = 'form';
$rejected = Static_Site_Importer_Entity_Materializer_Registry::validate_order_lookups_manifest( array( 'order_lookups' => array( $wrong_role, $order_lookup ) ) );
$assert( 1 === count( $rejected['order_lookups'] ) && '$.order_lookups[0]' === ( $rejected['errors'][0]['path'] ?? '' ), 'validator-rejects-a-non-order-lookup-anchor-row', (string) wp_json_encode( $rejected ) );
$missing_fields = $order_lookup;
unset( $missing_fields['fields']['email'], $missing_fields['submit_label'] );
$assert( array() === Static_Site_Importer_Entity_Materializer_Registry::validate_order_lookups_manifest( array( 'order_lookups' => array( $missing_fields ) ) )['order_lookups'], 'validator-rejects-an-order-lookup-without-both-fields-and-a-submit-label' );
$unanchored = $order_lookup;
unset( $unanchored['bindings'] );
$assert( array() === Static_Site_Importer_Entity_Materializer_Registry::validate_order_lookups_manifest( array( 'order_lookups' => array( $unanchored ) ) )['order_lookups'], 'validator-rejects-an-order-lookup-without-an-anchor' );
$accepted = Static_Site_Importer_Entity_Materializer_Registry::validate_order_lookups_manifest( array( 'order_lookups' => array( $order_lookup ) ) );
$assert( array() === ( $accepted['errors'] ?? array() ) && 1 === count( $accepted['order_lookups'] ?? array() ), 'validator-accepts-the-canonical-order-lookup-declaration' );

// --- 6. A shop provider with no order-lookup adapter leaves the optional lookup as a warning.
$GLOBALS['ssi_test_filters']['static_site_importer_entity_materializers'][] = static function ( array $adapters ): array {
	$alternate                         = $adapters['woocommerce_simple_product'];
	$alternate['id']                   = 'alternate_shop_products';
	$alternate['provider']             = 'alternate-shop';
	$alternate['rollback_contract_id'] = 'static-site-importer/alternate-shop-rollback/v1';
	$adapters[ $alternate['id'] ]      = $alternate;
	return $adapters;
};
$GLOBALS['ssi_test_filters']['ssi_shop_plugin'][] = static fn(): string => 'alternate-shop';
$alternate_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( 'alternate_shop_products' === ( Static_Site_Importer_Entity_Materializer_Registry::adapter_for_capability( 'shop' )['id'] ?? '' ), 'alternate-provider-serves-the-shop-capability' );
$assert( ! is_wp_error( $alternate_lifecycle ) && ! isset( $alternate_lifecycle['entities'][ $lookup_id ] ), 'alternate-provider-does-not-plan-the-order-lookup', is_wp_error( $alternate_lifecycle ) ? $alternate_lifecycle->get_error_message() : '' );
$codes = is_wp_error( $alternate_lifecycle ) ? array() : array_column( $alternate_lifecycle['diagnostics'] ?? array(), 'code', 'reconciliation_identity' );
$assert( 'unsupported_optional_runtime_declaration' === ( $codes[ $lookup_id ] ?? '' ), 'alternate-provider-reports-the-order-lookup-as-an-unsupported-optional-declaration', (string) wp_json_encode( $codes ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'Woo order lookup binding smoke passed (' . $assertions . " assertions).\n";
