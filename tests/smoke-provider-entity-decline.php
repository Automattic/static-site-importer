<?php
/**
 * Smoke coverage for provider entity declines degrading instead of failing an import.
 *
 * A provider declines one entity when it cannot represent it faithfully. The
 * compiled source markup stays at that binding anchor, so the import completes,
 * the binding is dropped, and the loss is named in the report diagnostics.
 *
 * Run from the repository root:
 * php tests/smoke-provider-entity-decline.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook_name = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( string $post_type ): bool {
		unset( $post_type );
		return false;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		unset( $taxonomy );
		return false;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}
		public function get_error_code(): string {
			return $this->code; }
		public function get_error_message(): string {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-dependency-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

/**
 * Build a bound two-form lifecycle whose provider returns the supplied result rows.
 *
 * @param array<int,array<string,mixed>> $rows   Provider result rows, in manifest order.
 * @param array<string,int>              $counts Provider count rollup.
 * @return array{lifecycle:array<string,mixed>,manifest:array<string,mixed>}
 */
$lifecycle_for = static function ( array $rows, array $counts ): array {
	$manifest = array(
		'forms' => array(
			array(
				'source_path' => 'about.html',
				'selector'    => 'form.enquiry',
				'bindings'    => array(
					array(
						'source_path'         => 'about.html',
						'search_block_markup' => '<!-- wp:html --><form class="enquiry"></form><!-- /wp:html -->',
						'occurrence'          => 1,
						'role'                => 'form',
					),
				),
			),
			array(
				'source_path' => 'contact.html',
				'selector'    => 'form.contact',
				'bindings'    => array(
					array(
						'source_path'         => 'contact.html',
						'search_block_markup' => '<!-- wp:html --><form class="contact"></form><!-- /wp:html -->',
						'occurrence'          => 1,
						'role'                => 'form',
					),
				),
			),
		),
	);
	$adapter  = array(
		'provider'         => 'jetpack',
		'waiver_arg'       => 'allow_missing_jetpack',
		'binding_callback' => array( 'Static_Site_Importer_Form_Seeder', 'binding_block_markup' ),
		'materializer'     => static function ( array $seeded ) use ( $rows, $counts ): array {
			unset( $seeded );
			return array(
				'status'   => 'completed',
				'provider' => 'jetpack',
				'counts'   => $counts,
				'forms'    => $rows,
			);
		},
	);

	return array(
		'manifest'  => $manifest,
		'lifecycle' => array(
			'entities' => array(
				'contact-forms' => array(
					'adapter'  => $adapter,
					'manifest' => $manifest,
					'required' => true,
				),
			),
		),
	);
};

$mapped_row = array(
	'source_path'     => 'about.html',
	'selector'        => 'form.enquiry',
	'provider'        => 'jetpack',
	'status'          => 'mapped',
	'runtime_mapped'  => true,
	'block_markup'    => '<!-- wp:jetpack/contact-form --><div></div><!-- /wp:jetpack/contact-form -->',
);

$declined_row = array(
	'source_path'                    => 'contact.html',
	'selector'                       => 'form.contact',
	'provider'                       => 'jetpack',
	'block_name'                     => 'jetpack/contact-form',
	'status'                         => 'skipped',
	'reason'                         => 'form_receipt_loss_unaccepted',
	'provider_mapped'                => true,
	'runtime_mapped'                 => false,
	'runtime_carried'                => true,
	'form_receipt_unaccepted_losses' => array( array( 'dimension' => 'layout', 'reason_code' => 'unsupported_semantic_wrapper' ) ),
	'unaccepted_receipt_loss_count'  => 8,
);

// A provider decline is a considered decision, never a materialization failure.
$assert( true === Static_Site_Importer_Entity_Materializer_Registry::entity_result_declined( $declined_row ), 'skipped-provider-row-reads-as-declined' );
$assert( false === Static_Site_Importer_Entity_Materializer_Registry::entity_result_declined( $mapped_row ), 'mapped-provider-row-is-not-declined' );

$degraded = $lifecycle_for(
	array( $mapped_row, $declined_row ),
	array( 'mapped' => 1, 'skipped' => 1, 'error' => 0 )
);
$degraded_result = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $degraded['lifecycle'], array( 'seed_entities' => false ) );
$assert( null === $degraded_result['error'], 'declined-bound-entity-does-not-fail-the-import', wp_json_encode( $degraded_result['error'] ) );
$assert( 1 === ( $degraded_result['reports']['contact-forms']['counts']['mapped'] ?? 0 ), 'the-other-form-still-materializes' );

$degraded_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $degraded['lifecycle'], $degraded_result['reports'] );
$assert( ! is_wp_error( $degraded_bindings ), 'declined-entity-produces-no-binding-error' );
$assert( 1 === count( $degraded_bindings ), 'only-the-materialized-form-is-bound' );
$assert( 'about.html' === ( $degraded_bindings[0]['source_path'] ?? '' ), 'the-declined-page-keeps-its-compiled-source-markup' );

// A provider row that errored, or one that never came back at all, still fails.
$errored = $lifecycle_for(
	array( $mapped_row, array( 'source_path' => 'contact.html', 'selector' => 'form.contact', 'status' => 'error', 'reason' => 'provider_exploded' ) ),
	array( 'mapped' => 1, 'error' => 1 )
);
$errored_result = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $errored['lifecycle'], array( 'seed_entities' => false ) );
$assert( 'static_site_importer_entity_materialization_failed' === ( $errored_result['error']['code'] ?? '' ), 'a-provider-error-row-still-fails-materialization' );

$absent = $lifecycle_for( array( $mapped_row ), array( 'mapped' => 2 ) );
$absent_result   = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $absent['lifecycle'], array( 'seed_entities' => false ) );
$absent_bindings = Static_Site_Importer_Entity_Materializer_Registry::block_bindings( $absent['lifecycle'], $absent_result['reports'] );
$assert( is_wp_error( $absent_bindings ) && 'static_site_importer_runtime_binding_unresolved' === $absent_bindings->get_error_code(), 'an-absent-provider-result-remains-unresolved' );

// The decline is named for a reader: which page, which provider, which reason.
$diagnostics = Static_Site_Importer_Report_Diagnostics::provider_entity_decline_diagnostics(
	array(
		'contact-forms' => array(
			'status'   => 'completed',
			'provider' => 'jetpack',
			'forms'    => array( $mapped_row, $declined_row ),
		),
	)
);
$assert( 1 === count( $diagnostics ), 'one-diagnostic-per-declined-entity' );
$diagnostic = $diagnostics[0] ?? array();
$assert( 'provider_entity_declined' === ( $diagnostic['code'] ?? '' ), 'decline-diagnostic-code-is-stable' );
$assert( 'contact.html' === ( $diagnostic['source_path'] ?? '' ) && 'form.contact' === ( $diagnostic['selector'] ?? '' ), 'decline-diagnostic-names-the-page-and-the-form' );
$assert( 'jetpack' === ( $diagnostic['provider'] ?? '' ) && 'form' === ( $diagnostic['entity_type'] ?? '' ), 'decline-diagnostic-names-the-provider-and-entity-type' );
$assert( 'form_receipt_loss_unaccepted' === ( $diagnostic['reason_code'] ?? '' ) && 8 === ( $diagnostic['unaccepted_receipt_loss_count'] ?? 0 ), 'decline-diagnostic-names-the-reason-and-its-loss-count' );
$assert( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === ( $diagnostic['loss_class'] ?? '' ), 'a-declined-entity-is-a-preserved-runtime-island' );
$assert( str_contains( (string) ( $diagnostic['message'] ?? '' ), 'contact.html' ) && str_contains( (string) ( $diagnostic['message'] ?? '' ), 'form_receipt_loss_unaccepted' ), 'decline-diagnostic-message-is-actionable-without-a-hash-lookup' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: provider entity decline smoke passed (' . $assertions . " assertions)\n";
