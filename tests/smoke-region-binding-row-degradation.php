<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Standalone smoke with WordPress stubs.
/**
 * Region adapters reject individual rows without losing the source fallback.
 *
 * Run: php tests/smoke-region-binding-row-degradation.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Standalone WordPress stubs.

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone encoder.
}
function apply_filters( string $hook, mixed $value ): mixed {
	return $value;
}

require_once ABSPATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once ABSPATH . 'includes/class-static-site-importer-form-seeder.php';
require_once ABSPATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once ABSPATH . 'includes/class-static-site-importer-import-report.php';
require_once ABSPATH . 'includes/class-static-site-importer-diagnostic-projection.php';
require_once ABSPATH . 'includes/class-static-site-importer-quality-gates.php';

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

// Use the public preparation and resolver hydration boundaries, not a synthetic validator.
$resolve = static function ( string $collection, array $rows ): array|WP_Error {
	$id          = str_repeat( 'a', 64 );
	$declaration = array(
		'kind'                    => 'entity_collection',
		'type'                    => $collection,
		'reconciliation_identity' => $id,
		'payload'                 => array(
			'schema'        => 'blocks-engine/runtime-entity-manifest/v1',
			'entity_schema' => 'generic/' . str_replace( '_', '-', $collection ) . '/v1',
			'entities'      => array( array( 'content_hash' => str_repeat( 'b', 64 ) ) ),
		),
	);
	$plan        = array( 'runtime_declarations' => array( $declaration ) );
	$lifecycle   = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
	if ( is_wp_error( $lifecycle ) ) {
		return $lifecycle;
	}
	return Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests(
		$lifecycle,
		array(
			'runtime_declarations'      => array( $declaration ),
			'runtime_entity_resolution' => array(
				array(
					'reconciliation_identity' => $id,
					'kind'                    => 'entity_collection',
					'type'                    => $collection,
					'entity_schema'           => 'generic/' . str_replace( '_', '-', $collection ) . '/v1',
					'entities'                => $rows,
				),
			),
		)
	);
};

$valid                 = array(
	'source_path'   => 'shop.html',
	'selector'      => '.valid-listing',
	'grid_selector' => '.valid-grid',
	'controls'      => array(
		array(
			'id'   => 'sort',
			'kind' => 'sort',
		),
	),
	'bindings'      => array(
		array(
			'schema'              => 'generic/block-binding/v1',
			'source_path'         => 'shop.html',
			'search_block_markup' => '<!-- wp:paragraph --><p>Source listing</p><!-- /wp:paragraph -->',
			'occurrence'          => 1,
			'role'                => 'commerce_listing',
		),
	),
);
$invalid               = $valid;
$invalid['selector']   = '.rejected-listing';
$invalid['controls'][] = $valid['controls'][0];
$declaration_id        = str_repeat( 'a', 64 );
$partial               = $resolve( 'product_listings', array( $valid, $invalid ) );
$assert( ! is_wp_error( $partial ), 'Mixed listing rows must degrade rather than fail' );
if ( ! is_wp_error( $partial ) ) {
	$rows       = $partial['entities'][ $declaration_id ]['manifest']['product_listings'] ?? array();
	$diagnostic = $partial['diagnostics'][0] ?? array();
	$assert( array( '.valid-listing' ) === array_column( $rows, 'selector' ), 'Only the valid listing reaches materialization' );
	$assert( 'runtime_entity_rows_rejected' === ( $diagnostic['code'] ?? '' ) && 1 === ( $diagnostic['accepted_count'] ?? 0 ) && 1 === ( $diagnostic['rejected_count'] ?? 0 ) && '$.product_listings[1]' === ( $diagnostic['path'] ?? '' ), 'Rejected listing diagnostic carries row counts and path' );
}
$dropped = $resolve( 'product_listings', array( $invalid ) );
$assert( ! is_wp_error( $dropped ), 'All rejected listing rows must not fail the import' );
if ( ! is_wp_error( $dropped ) ) {
	$assert( ! isset( $dropped['entities'][ $declaration_id ] ) && ! isset( $dropped['dependencies'][ $declaration_id ] ), 'Empty declaration and dependency are dropped' );
	$assert( 'runtime_declaration_rejected' === ( $dropped['diagnostics'][0]['code'] ?? '' ), 'Dropped declaration is diagnosed' );
}
$assert( is_wp_error( $resolve( 'product_listings', array( 'not-a-list' => $valid ) ) ), 'Non-list manifest fails closed' );
$assert( is_wp_error( $resolve( 'products', array( array() ) ) ), 'Invalid product rows still fail closed' );
foreach ( array( 'product_grids', 'carts' ) as $collection ) {
	$rejected = $resolve( $collection, array( array() ) );
	$assert( ! is_wp_error( $rejected ), $collection . ' opts in to row degradation' );
	if ( ! is_wp_error( $rejected ) ) {
		$assert( empty( $rejected['entities'] ) && 'runtime_declaration_rejected' === ( $rejected['diagnostics'][0]['code'] ?? '' ), $collection . ' drops invalid rows with a diagnostic' );
	}
}

// Inline declarations follow the same policy, including dropping an empty declaration.
foreach ( array( array( $valid, $invalid ), array( $invalid ) ) as $rows ) {
	$inline = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle(
		array(
			'runtime_declarations' => array(
				array(
					'kind'                    => 'entity_collection',
					'type'                    => 'product_listings',
					'reconciliation_identity' => $declaration_id,
					'payload'                 => array(
						'schema'   => 'generic/product-listings/v1',
						'entities' => $rows,
					),
				),
			),
		),
		array()
	);
	$assert( ! is_wp_error( $inline ), 'Inline listing rows degrade' );
	if ( ! is_wp_error( $inline ) ) {
		$assert( count( $rows ) - 1 === count( $inline['entities'][ $declaration_id ]['manifest']['product_listings'] ?? array() ), 'Inline rejected rows never reach the provider' );
	}
}

// No rejected region can produce a completed binding receipt. Quality must not
// infer resolution from the surviving declaration or its rejection diagnostic.
foreach ( array( 'html_product_grid_fallback', 'html_cart_view_fallback', 'html_commerce_controls_fallback' ) as $code ) {
	$report = Static_Site_Importer_Import_Report::from_array(
		array(
			'quality'                 => array( 'fallback_count' => 1 ),
			'diagnostics'             => array_merge(
				array(
					array(
						'type'        => 'unsupported_html_fallback',
						'code'        => $code,
						'source_path' => 'shop.html',
						'selector'    => '.rejected-listing',
					),
				),
				is_wp_error( $partial ) ? array() : $partial['diagnostics']
			),
			'materialization_receipt' => array(
				'completed' => array(
					'runtime_declarations' => array( 'entity_bindings' => array() ),
				),
			),
		)
	);
	Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report );
	$assert( 0 === ( $report['quality_resolutions']['resolved_by_provider'] ?? -1 ) && 1 === ( $report['quality_resolutions']['unresolved_fallback_count'] ?? -1 ), $code . ' stays unresolved without a completed binding' );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI failure output.
	exit( 1 );
}
echo "Region binding row degradation smoke passed.\n";
