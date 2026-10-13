<?php
/**
 * A form the provider cannot admit degrades that form, not the import
 * (Automattic/static-site-importer#2054).
 *
 * A forms runtime declaration whose only form violates the producer control
 * topology contract is skipped with one `runtime_entity_rows_rejected` finding;
 * the page keeps its converted source form, the other declarations still
 * materialize, and the finding reaches Receipt_Projection::compose() with its
 * unit, path and owning layer.
 *
 * Run from the repository root:
 * php tests/smoke-runtime-form-entity-degraded.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- Standalone smoke stubs WordPress.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_parse_url( string $url ) { return parse_url( $url ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub.
function sanitize_key( $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $text ): string { return trim( (string) $text ); }
function post_type_exists( string $post_type ): bool { return false; }
function taxonomy_exists( string $taxonomy ): bool { return false; }

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
foreach ( array( 'woo-product-seeder', 'form-seeder', 'plugin-materializer', 'dependency-manager', 'entity-materializer-registry', 'product-handoff-contract', 'import-report', 'diagnostic-loss-classes', 'source-findings', 'diagnostic-contract', 'artifact-diagnostics-adapter', 'report-diagnostics', 'asset-reporter', 'document-metadata-reporter', 'block-document-reporter', 'receipt-projection' ) as $class_file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $class_file . '.php';
}

$failures = array();
$assert   = static function ( bool $condition, string $label, string $detail = '' ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// One form per declaration: an email field and a submit button, each a control node.
$form = static function ( string $source_path, string $selector ): array {
	$node = static fn ( int $control ): array => array(
		'id'      => 'control-' . $control,
		'kind'    => 'control',
		'parent'  => null,
		'order'   => $control,
		'depth'   => 0,
		'control' => $control,
	);
	return array(
		'source_path'      => $source_path,
		'selector'         => $selector,
		'controls'         => array(
			array(
				'tag'   => 'input',
				'type'  => 'email',
				'name'  => 'email',
				'label' => 'Email',
			),
			array(
				'tag'   => 'button',
				'type'  => 'submit',
				'label' => 'Send',
			),
		),
		'control_topology' => array(
			'schema'    => 'generic/form-control-topology/v1',
			'max_depth' => 8,
			'max_nodes' => 128,
			'truncated' => false,
			'nodes'     => array( $node( 0 ), $node( 1 ) ),
		),
	);
};
$declaration = static fn ( string $id, string $source_path, array $entities ): array => array(
	'kind'                    => 'entity_collection',
	'type'                    => 'forms',
	'source_path'             => $source_path,
	'reconciliation_identity' => $id,
	'payload'                 => array(
		'schema'   => 'generic/forms/v1',
		'entities' => $entities,
	),
);

// The contact form's topology omits its submit control, as when a producer
// skips a control nested inside another control.
$invalid_form = $form( 'website/contact.html', 'form.contact' );
array_pop( $invalid_form['control_topology']['nodes'] );
$invalid_id = str_repeat( 'a', 64 );
$valid_id   = str_repeat( 'b', 64 );
$plan       = array(
	'runtime_declarations' => array(
		$declaration( $invalid_id, 'website/contact.html', array( $invalid_form ) ),
		$declaration( $valid_id, 'website/index.html', array( $form( 'website/index.html', 'form.newsletter' ) ) ),
	),
);

$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array() );
$assert( ! is_wp_error( $lifecycle ), 'lifecycle: a form that violates the producer topology contract no longer fails the import', is_wp_error( $lifecycle ) ? $lifecycle->get_error_message() . ' ' . wp_json_encode( $lifecycle->get_error_data() ) : '' );
$lifecycle = is_wp_error( $lifecycle ) ? array() : $lifecycle;
$assert( ! isset( $lifecycle['entities'][ $invalid_id ] ) && ! isset( $lifecycle['dependencies'][ $invalid_id ] ), 'lifecycle: the rejected form never reaches the provider' );
$assert( array( 'form.newsletter' ) === array_column( $lifecycle['entities'][ $valid_id ]['manifest']['forms'] ?? array(), 'selector' ), 'lifecycle: the other forms declaration still materializes' );
$findings = array_values( array_filter( $lifecycle['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && 'runtime_entity_rows_rejected' === ( $row['code'] ?? '' ) ) );
$finding  = $findings[0] ?? array();
$assert( 1 === count( $findings ) && $invalid_id === ( $finding['declaration_id'] ?? '' ), 'lifecycle: one finding names the rejected declaration', wp_json_encode( $lifecycle['diagnostics'] ?? null ) );
$assert(
	'warning' === ( $finding['severity'] ?? '' ) && 'item' === ( $finding['unit'] ?? '' ) && 'blocks-engine' === ( $finding['owning_layer'] ?? '' ) && '$.forms[0].control_topology' === ( $finding['path'] ?? '' ) && 0 === ( $finding['accepted_count'] ?? null ) && 1 === ( $finding['rejected_count'] ?? null ),
	'lifecycle: the finding carries severity, unit, path, counts and owning layer',
	wp_json_encode( $finding )
);
$assert( str_contains( (string) ( $finding['message'] ?? '' ), 'must cover every flat control exactly once' ), 'lifecycle: the finding states the validator reason', (string) ( $finding['message'] ?? '' ) );

// The finding reaches Receipt_Projection::compose() and does not fail the import.
$receipt    = array(
	'plan'             => array(
		'schema'         => 'blocks-engine/wordpress-site-plan/v2',
		'diagnostics'    => array(),
		'quality'        => array(),
		'source'         => array(
			'schema'      => 'blocks-engine/php-transformer/site-artifact/v1',
			'source_hash' => 'runtime-form-entity-degraded',
			'entry_path'  => 'website/index.html',
			'provenance'  => array(),
		),
		'template_parts' => array(),
		'pages'          => array(),
		'writes'         => array(),
		'assets'         => array(),
	),
	'theme'            => array(
		'slug' => 'runtime-form-entity-degraded',
		'dir'  => sys_get_temp_dir() . '/runtime-form-entity-degraded',
	),
	'completed'        => array( 'files' => array() ),
	'existing_matches' => array( 'pages' => array() ),
	'extensions'       => array(),
);
$projection = Static_Site_Importer_Receipt_Projection::compose( $receipt, array(), $lifecycle, array(), array(), 'runtime-form-entity-degraded-run', array(), array() );
$report     = $projection['report'];
$quality    = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
$stored     = json_decode( (string) json_encode( $report->to_array() ), true );
$rows       = array_values( array_filter( $stored['diagnostics'] ?? array(), static fn ( $row ): bool => is_array( $row ) && 'runtime_entity_rows_rejected' === ( $row['code'] ?? '' ) ) );
$row        = $rows[0] ?? array();
$assert( 1 === count( $rows ), 'compose: the runtime_entity_rows_rejected finding reaches the stored report' );
$assert(
	'item' === ( $row['unit'] ?? '' ) && 'blocks-engine' === ( $row['owning_layer'] ?? '' ) && '$.forms[0].control_topology' === ( $row['location'] ?? '' ) && 'preserved_runtime_island' === ( $row['loss_class'] ?? '' ) && 'acceptable_preservation' === ( $row['acceptability'] ?? '' ),
	'compose: the finding is a preserved form at its declaration path, owned by Blocks Engine',
	wp_json_encode( $row )
);
$assert( false === $quality['fail_import'], 'compose: a rejected form does not fail the import' );

// Collections without a source-form fallback keep rejecting a declaration with no valid row.
$products = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle(
	array(
		'runtime_declarations' => array(
			array(
				'kind'                    => 'entity_collection',
				'type'                    => 'products',
				'reconciliation_identity' => str_repeat( 'c', 64 ),
				'payload'                 => array(
					'schema'   => 'generic/products/v1',
					'entities' => array( array( 'name' => '' ) ),
				),
			),
		),
	),
	array()
);
$assert( is_wp_error( $products ) && 'static_site_importer_runtime_entity_invalid' === $products->get_error_code(), 'scope: a products declaration with no valid row still rejects', is_wp_error( $products ) ? '' : wp_json_encode( $products['diagnostics'] ?? null ) );

if ( array() !== $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n" );
	exit( 1 );
}
echo "smoke-runtime-form-entity-degraded: ok\n";
