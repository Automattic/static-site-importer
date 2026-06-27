<?php
/**
 * Smoke coverage for the SSI-owned import diagnostic contract.
 *
 * Run from the repository root:
 * php tests/smoke-import-diagnostic-contract.php
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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/abilities.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$diagnostics = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'status'        => 'failed',
		'success'       => false,
		'import_report' => array(
			'quality'       => array(
				'invalid_block_count'                   => 1,
				'runtime_dependency_parity_issue_count' => 1,
				'semantic_parity_failure_count'         => 1,
			),
			'diagnostics'   => array(
				array(
					'type'        => 'website_artifact_materialization_contract_note',
					'source_path' => 'website/index.html',
					'constraints' => 'report_only',
					'message'     => 'Direct materialization contract note.',
				),
				array(
					'type'        => 'document_metadata_routed',
					'source_path' => 'website/index.html',
					'constraints' => 'report_only',
					'message'     => 'Document metadata routing note.',
				),
				array(
					'type'        => 'dropped_image_asset',
					'source_path' => 'assets/hero.jpg',
					'message'     => 'Dropped image asset.',
				),
				array(
					'type'        => 'invalid_block_content',
					'source_path' => 'templates/front-page.html',
				),
			),
			'blocks_engine' => array(
				'runtime_dependency_parity' => array(
					'missing_dom_targets' => array(
						array(
							'type'     => 'runtime_dependency_target_missing',
							'selector' => '#canvas',
						),
					),
				),
				'semantic_parity'           => array(
					'findings' => array(
						array(
							'type'        => 'navigation_missing',
							'source_path' => 'index.html',
							'selector'    => 'header nav',
						),
					),
				),
			),
		),
	)
);

$assert( 'static-site-importer/import-diagnostics/v1' === ( $diagnostics['schema'] ?? '' ), 'schema' );
$assert( 4 === ( $diagnostics['diagnostic_summary']['total'] ?? 0 ), 'total-count' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['dropped_images'] ?? 0 ), 'dropped-images-bucket' );
$assert( ! isset( $diagnostics['diagnostic_summary']['repair_bucket']['static_site_import_quality'] ), 'report-only-metadata-bucket-excluded' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['invalid_block_content'] ?? 0 ), 'invalid-block-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['runtime_target_gap'] ?? 0 ), 'runtime-target-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['semantic_parity'] ?? 0 ), 'semantic-parity-bucket' );
$assert( ! isset( $diagnostics['diagnostic_summary']['type']['website_artifact_materialization_contract_note'] ), 'report-only-contract-note-excluded' );
$assert( ! isset( $diagnostics['diagnostic_summary']['type']['document_metadata_routed'] ), 'report-only-metadata-note-excluded' );
$assert( 'static-site-importer' === ( $diagnostics['by_repair_bucket']['dropped_images'][0]['parser_owner'] ?? '' ), 'dropped-images-owner' );
$assert( 'blocks-engine' === ( $diagnostics['by_repair_bucket']['runtime_target_gap'][0]['parser_owner'] ?? '' ), 'runtime-target-owner' );
$assert( 'unsupported_loss' === ( $diagnostics['by_repair_bucket']['dropped_images'][0]['loss_class'] ?? '' ), 'dropped-images-loss-class' );
$assert( 'importer_materialization_bug' === ( $diagnostics['by_repair_bucket']['invalid_block_content'][0]['loss_class'] ?? '' ), 'invalid-block-loss-class' );
$assert( 'editable_approximation' === ( $diagnostics['by_repair_bucket']['semantic_parity'][0]['loss_class'] ?? '' ), 'semantic-parity-loss-class' );
$assert( 1 === ( $diagnostics['loss_class_summary']['unsupported_loss'] ?? 0 ), 'loss-class-summary-unsupported' );
$assert( 2 === ( $diagnostics['loss_class_summary']['importer_materialization_bug'] ?? 0 ), 'loss-class-summary-importer' );
$assert( '#canvas' === ( $diagnostics['runtime_dependency_target_gaps'][0]['selector'] ?? '' ), 'runtime-target-selector' );
$assert( 'header nav' === ( $diagnostics['by_repair_bucket']['semantic_parity'][0]['selector'] ?? '' ), 'semantic-selector' );
$assert( array() === ( $diagnostics['artifact_refs'] ?? null ), 'no-runtime-artifact-requirement' );

$quality_gate_error = static_site_importer_ability_error(
	'static_site_importer_quality_gate_failed',
	'Import failed quality gates; materialization was not completed.',
	array(
		'import_validation_result' => array(
			'diagnostics' => array(
				array(
					'id'                  => 'diag-001-core-html',
					'type'                => 'core_html_block',
					'kind'                => 'core_html_block',
					'severity'            => 'warning',
					'reason_code'         => 'generated_document_contains_core_html',
					'reason'              => 'generated_document_contains_core_html',
					'source_path'         => 'posts/page-home.post_content',
					'selector'            => 'iframe#map',
					'source_html_preview' => '<iframe id="map"></iframe>',
					'observed_output'     => '<!-- wp:html --><iframe id="map"></iframe><!-- /wp:html -->',
					'observed_block_name' => 'core/html',
				)
			),
		),
		'quality'                  => array(
			'core_html_block_count' => 1,
			'failure_reasons'      => array( 'core_html_block' ),
		),
	)
);

$assert( 'core_html_block' === ( $quality_gate_error['diagnostics'][0]['type'] ?? '' ), 'ability-error-promotes-validation-diagnostic-type' );
$assert( 'iframe#map' === ( $quality_gate_error['diagnostics'][0]['selector'] ?? '' ), 'ability-error-promotes-validation-selector' );
$assert( is_array( $quality_gate_error['errors'][0] ?? null ), 'ability-error-errors-are-structured' );
$assert( 'core_html_block' === ( $quality_gate_error['errors'][0]['kind'] ?? '' ), 'ability-error-prevents-numeric-generic-errors' );
$assert( 'fallback_block' === ( $quality_gate_error['fixture_diagnostics']['diagnostics'][0]['repair_bucket'] ?? '' ), 'ability-error-fixture-diagnostics-classified' );
$assert( 'editable_approximation' === ( $quality_gate_error['fixture_diagnostics']['diagnostics'][0]['loss_class'] ?? '' ), 'ability-error-fixture-loss-classified' );

$numeric_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'status'      => 'failed',
		'success'     => false,
		'diagnostics' => array(
			array(
				'type'        => '2',
				'kind'        => '8',
				'reason'      => '3',
				'source_path' => 'website/index.html',
			),
		),
	)
);
$numeric_diagnostic = $numeric_contract['diagnostics'][0] ?? array();
$numeric_only       = static fn ( $value ): bool => is_scalar( $value ) && 1 === preg_match( '/^\d+$/', (string) $value );
$assert( ! $numeric_only( $numeric_diagnostic['type'] ?? '' ), 'contract-type-not-numeric-only' );
$assert( ! $numeric_only( $numeric_diagnostic['kind'] ?? '' ), 'contract-kind-not-numeric-only' );
$assert( ! $numeric_only( $numeric_diagnostic['reason_code'] ?? '' ), 'contract-reason-code-not-numeric-only' );

$numeric_quality_gate_error = static_site_importer_ability_error(
	'static_site_importer_quality_gate_failed',
	'Import failed quality gates; materialization was not completed.',
	array(
		'import_validation_result' => array(
			'diagnostics' => array(
				array(
					'message' => '2',
				),
			),
		),
	)
);
$assert( 'validation_error' === ( $numeric_quality_gate_error['errors'][0]['kind'] ?? '' ), 'ability-error-rejects-numeric-message-diagnostic' );
$assert( ! $numeric_only( $numeric_quality_gate_error['errors'][0]['reason'] ?? '' ), 'ability-error-reason-not-numeric-only' );
$assert( ! $numeric_only( $numeric_quality_gate_error['fixture_diagnostics']['diagnostics'][0]['kind'] ?? '' ), 'fixture-diagnostic-kind-not-numeric-only' );
$assert( ! $numeric_only( $numeric_quality_gate_error['fixture_diagnostics']['diagnostics'][0]['reason_code'] ?? '' ), 'fixture-diagnostic-reason-code-not-numeric-only' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import diagnostic contract smoke passed (' . $assertions . " assertions)\n";
