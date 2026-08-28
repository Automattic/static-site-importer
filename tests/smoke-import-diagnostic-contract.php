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

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';
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
				array(
					'type'          => 'dom',
					'source_path'   => 'templates/front-page.html',
					'selector'      => '.site-header',
					'reason'        => 'Runtime-dependent source markup was preserved as a bounded runtime island.',
					'repair_bucket' => 'static_site_import_quality',
				),
				array(
					'id'          => 'ssi-canvas-fallback',
					'type'        => 'unsupported_html_fallback',
					'source_path' => 'templates/front-page.html',
					'selector'    => 'canvas#hero',
					'code'        => 'unsupported_html_fallback',
				),
				array(
					'id'          => 'blocks-engine-canvas-fallback',
					'type'        => 'unsupported_html_fallback',
					'source_path' => 'templates/front-page.html',
					'selector'    => 'canvas#hero',
					'code'        => 'unsupported_html_fallback',
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
$assert( 6 === ( $diagnostics['diagnostic_summary']['total'] ?? 0 ), 'total-count' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['dropped_images'] ?? 0 ), 'dropped-images-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['static_site_import_quality'] ?? 0 ), 'static-dom-preservation-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['invalid_block_content'] ?? 0 ), 'invalid-block-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['runtime_target_gap'] ?? 0 ), 'runtime-target-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['semantic_parity'] ?? 0 ), 'semantic-parity-bucket' );
$assert( ! isset( $diagnostics['diagnostic_summary']['repair_bucket']['preserved_runtime_island'] ), 'static-dom-preservation-not-runtime-bucket' );
$assert( 1 === ( $diagnostics['diagnostic_summary']['repair_bucket']['fallback_block'] ?? 0 ), 'deduped-fallback-bucket' );
$assert( ! isset( $diagnostics['diagnostic_summary']['type']['website_artifact_materialization_contract_note'] ), 'report-only-contract-note-excluded' );
$assert( ! isset( $diagnostics['diagnostic_summary']['type']['document_metadata_routed'] ), 'report-only-metadata-note-excluded' );
$assert( 'static-site-importer' === ( $diagnostics['by_repair_bucket']['dropped_images'][0]['parser_owner'] ?? '' ), 'dropped-images-owner' );
$assert( 'blocks-engine' === ( $diagnostics['by_repair_bucket']['runtime_target_gap'][0]['parser_owner'] ?? '' ), 'runtime-target-owner' );
$assert( 'unsupported_loss' === ( $diagnostics['by_repair_bucket']['dropped_images'][0]['loss_class'] ?? '' ), 'dropped-images-loss-class' );
$assert( 'importer_materialization_bug' === ( $diagnostics['by_repair_bucket']['invalid_block_content'][0]['loss_class'] ?? '' ), 'invalid-block-loss-class' );
$assert( 'editable_approximation' === ( $diagnostics['by_repair_bucket']['semantic_parity'][0]['loss_class'] ?? '' ), 'semantic-parity-loss-class' );
$assert( 'editable_approximation' === ( $diagnostics['by_repair_bucket']['static_site_import_quality'][0]['loss_class'] ?? '' ), 'static-dom-preservation-loss-class' );
$assert( 'import-validation' === ( $diagnostics['by_repair_bucket']['static_site_import_quality'][0]['repair_mode'] ?? '' ), 'static-dom-preservation-repair-mode' );
$assert( 'preserved_runtime_island' === ( $diagnostics['by_repair_bucket']['fallback_block'][0]['loss_class'] ?? '' ), 'canvas-fallback-loss-class' );
$assert( 'acceptable_preservation' === ( $diagnostics['by_repair_bucket']['fallback_block'][0]['acceptability'] ?? '' ), 'canvas-fallback-acceptable-preservation' );
$assert( 'fallback-block-replacement' === ( $diagnostics['by_repair_bucket']['fallback_block'][0]['repair_class'] ?? '' ), 'canvas-fallback-repair-class' );
$assert( 'unsupported_html_fallback' === ( $diagnostics['by_repair_bucket']['fallback_block'][0]['source_diagnostic']['type'] ?? '' ), 'canvas-fallback-source-diagnostic-type' );
$assert( 1 === ( $diagnostics['loss_class_summary']['unsupported_loss'] ?? 0 ), 'loss-class-summary-unsupported' );
$assert( 2 === ( $diagnostics['loss_class_summary']['importer_materialization_bug'] ?? 0 ), 'loss-class-summary-importer' );
$assert( 1 === ( $diagnostics['loss_class_summary']['preserved_runtime_island'] ?? 0 ), 'loss-class-summary-preserved-runtime' );
$assert( '#canvas' === ( $diagnostics['runtime_dependency_target_gaps'][0]['selector'] ?? '' ), 'runtime-target-selector' );
$assert( 'header nav' === ( $diagnostics['by_repair_bucket']['semantic_parity'][0]['selector'] ?? '' ), 'semantic-selector' );
$assert( array() === ( $diagnostics['artifact_refs'] ?? null ), 'no-runtime-artifact-requirement' );

$quality_fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/tests/fixtures/diagnostic-contract/quality-reconciliation.json' ), true );
$verified_resolutions = array();
for ( $index = 1; $index <= 8; ++$index ) {
	$fallback_identity = hash( 'sha256', 'fallback-' . $index );
	$fallback_hash     = hash( 'sha256', 'source-' . $index );
	$verified_resolutions[] = array(
		'fallback_reconciliation_identity' => $fallback_identity,
		'fallback_hash'                    => $fallback_hash,
		'state'                            => 'resolved_by_provider',
		'receipt'                          => array(
			'schema'                           => 'static-site-importer/quality-resolution-receipt/v1',
			'status'                           => 'completed',
			'fallback_reconciliation_identity' => $fallback_identity,
			'fallback_hash'                    => $fallback_hash,
			'binding_reconciliation_identity'  => hash( 'sha256', 'binding-' . $index ),
			'materialized_block_hash'          => hash( 'sha256', 'block-' . $index ),
			'materialized_content_hash'        => hash( 'sha256', 'content-' . $index ),
		),
	);
}
$quality_fixture['quality_resolutions_evidence']['resolutions'] = $verified_resolutions;
$quality_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'schema'        => 'static-site-importer/import-report/v1',
			'quality'       => $quality_fixture['importer_report_evidence']['quality'],
			'blocks_engine' => array( 'wordpress_site_plan' => $quality_fixture['source_evidence'] ),
			'quality_resolutions' => $quality_fixture['quality_resolutions_evidence'],
		),
		'materialization_receipt' => $quality_fixture['materialization_receipt_evidence'],
	)
);
$expected_unresolved = $quality_fixture['expected_unresolved_quality'];
$assert( $expected_unresolved['block_count'] === ( $quality_contract['quality_counts']['block_count'] ?? null ), 'quality-reconciliation-retains-compiler-block-count' );
$assert( $expected_unresolved['fallback_count'] === ( $quality_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-subtracts-explicit-resolution' );
$assert( $expected_unresolved['diagnostic_count'] === ( $quality_contract['quality_counts']['diagnostic_count'] ?? null ), 'quality-reconciliation-retains-compiler-diagnostics' );
$assert( 458 === ( $quality_contract['quality_counts']['source_detected']['fallback_count'] ?? null ), 'quality-reconciliation-preserves-source-evidence' );
$assert( 8 === ( $quality_contract['quality_counts']['materialized']['fallback_count'] ?? null ), 'quality-reconciliation-preserves-provider-resolution-evidence' );
$assert( $expected_unresolved['fallback_count'] === ( $quality_contract['quality_counts']['unresolved']['fallback_count'] ?? null ), 'quality-reconciliation-exposes-unresolved-evidence' );
$assert( 'blocks_engine.wordpress_site_plan.quality' === ( $quality_contract['quality_counts']['provenance']['source_detected']['path'] ?? '' ), 'quality-reconciliation-identifies-compiler-provenance' );
$assert( false === ( $quality_contract['quality_counts']['consistent'] ?? true ), 'quality-reconciliation-detects-contradictory-layers' );
$assert( 'quality_count_consistency_failure' === ( $quality_contract['diagnostics'][0]['type'] ?? '' ), 'quality-reconciliation-emits-gating-diagnostic' );

$absent_quality_contract = Static_Site_Importer_Diagnostic_Contract::build( array( 'import_report' => array( 'blocks_engine' => array( 'wordpress_site_plan' => array( 'schema' => 'blocks-engine/wordpress-site-plan/v2' ) ) ) ) );
$assert( 0 === ( $absent_quality_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-keeps-absent-fields-zero' );
$assert( array() === ( $absent_quality_contract['quality_counts']['provenance'] ?? null ), 'quality-reconciliation-does-not-invent-absent-provenance' );
$assert( true === ( $absent_quality_contract['quality_counts']['consistent'] ?? false ), 'quality-reconciliation-keeps-absent-layers-consistent' );

$clean_quality_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'quality'       => array( 'block_count' => 2, 'fallback_count' => 0, 'diagnostic_count' => 0 ),
			'blocks_engine' => array( 'wordpress_site_plan' => array( 'schema' => 'blocks-engine/wordpress-site-plan/v2', 'quality' => array( 'metrics' => array( 'block_count' => 2, 'fallback_count' => 0, 'diagnostic_count' => 0 ) ) ) ),
		),
	)
);
$assert( 0 === ( $clean_quality_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-keeps-clean-fallbacks-zero' );
$assert( true === ( $clean_quality_contract['quality_counts']['consistent'] ?? false ), 'quality-reconciliation-keeps-clean-layers-consistent' );
$assert( 0 === ( $clean_quality_contract['diagnostic_summary']['total'] ?? null ), 'quality-reconciliation-does-not-diagnose-clean-layers' );

$partial_quality_report = Static_Site_Importer_Import_Report::from_array(
	array(
	'blocks_engine' => array( 'wordpress_site_plan' => array( 'quality' => array( 'metrics' => array( 'fallback_count' => 2 ) ) ) ),
	'quality'       => array( 'metrics' => array( 'block_count' => 1 ) ),
	'diagnostics'   => array( array( 'type' => 'unsupported_html_fallback', 'severity' => 'warning' ) ),
	)
);
$partial_quality_warning_handler = set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): never {
		throw new RuntimeException( sprintf( 'PHP warning/notice [%d] %s at %s:%d', $severity, $message, $file, $line ) );
	}
);
try {
	$partial_quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $partial_quality_report, array( 'fail_on_quality' => true ) );
} finally {
	restore_error_handler();
}
$partial_quality_counters = array(
	'fallback_count'                        => 2,
	'content_loss_count'                    => 0,
	'empty_conversion_count'                => 0,
	'core_html_block_count'                 => 0,
	'freeform_block_count'                  => 0,
	'invalid_block_count'                   => 0,
	'invalid_block_document_count'          => 0,
	'unsafe_svg_count'                      => 0,
	'svg_materialization_failure_count'     => 0,
	'svg_sprite_reference_failure_count'    => 0,
	'commerce_dependency_failures'          => 0,
	'companion_plugin_dependency_failures'  => 0,
	'interaction_candidate_count'           => 0,
	'runtime_dependency_parity_issue_count' => 0,
	'semantic_parity_failure_count'         => 0,
	'source_fallback_count'                 => 2,
);
$assert( 2 === ( $partial_quality['fallback_count'] ?? 0 ), 'quality-finalization-normalizes-partial-compiler-reports' );
$assert( $partial_quality_counters === array_intersect_key( $partial_quality, $partial_quality_counters ) && 1 === ( $partial_quality['block_count'] ?? 0 ), 'quality-finalization-provides-the-complete-normalized-counter-schema' );
$assert( false === ( $partial_quality['pass'] ?? true ) && true === ( $partial_quality['fail_import'] ?? false ) && in_array( 'unsupported_html_fallback', $partial_quality['failure_reasons'] ?? array(), true ), 'quality-finalization-turns-compiler-fallback-warning-into-strict-failure' );

$importer_only_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'schema'              => 'static-site-importer/import-report/v1',
			'quality'             => array( 'fallback_count' => 450 ),
			'quality_resolutions' => array(
				'schema'                    => 'static-site-importer/quality-resolutions/v1',
				'source_fallback_count'     => 458,
				'resolved_by_provider'      => 8,
				'unresolved_fallback_count' => 450,
				'resolutions'               => $verified_resolutions,
			),
		),
	)
);
$assert( 450 === ( $importer_only_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-does-not-double-subtract-importer-unresolved-count' );
$assert( 458 === ( $importer_only_contract['quality_counts']['source_detected']['fallback_count'] ?? null ), 'quality-reconciliation-uses-importer-source-fallback-baseline' );
$assert( 8 === ( $importer_only_contract['quality_counts']['materialized']['fallback_count'] ?? null ), 'quality-reconciliation-preserves-importer-provider-resolution' );
$assert( 'quality_resolutions.source_fallback_count' === ( $importer_only_contract['quality_counts']['provenance']['source_detected']['path'] ?? '' ), 'quality-reconciliation-identifies-importer-source-baseline' );

$legacy_importer_only_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'schema'                  => 'static-site-importer/import-report/v1',
			'quality'                 => array( 'fallback_count' => 450 ),
			'fallback_reconciliation' => array( 'schema' => 'static-site-importer/quality-resolutions/v1', 'resolved_by_provider' => 8 ),
		),
	)
);
$assert( 450 === ( $legacy_importer_only_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-keeps-legacy-importer-quality-as-unresolved' );
$assert( 0 === ( $legacy_importer_only_contract['quality_counts']['materialized']['fallback_count'] ?? null ), 'quality-reconciliation-does-not-apply-resolution-without-source-baseline' );
$assert( 'quality' === ( $legacy_importer_only_contract['quality_counts']['provenance']['source_detected']['path'] ?? '' ), 'quality-reconciliation-preserves-legacy-importer-provenance' );

$forged_resolution_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'quality'             => array( 'fallback_count' => 0 ),
			'blocks_engine'       => array( 'wordpress_site_plan' => array( 'quality' => array( 'metrics' => array( 'fallback_count' => 458 ) ) ) ),
			'quality_resolutions' => array_merge( $quality_fixture['quality_resolutions_evidence'], array( 'resolutions' => array() ) ),
		),
	)
);
$assert( 458 === ( $forged_resolution_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-rejects-forged-aggregate-without-receipts' );
$assert( 0 === ( $forged_resolution_contract['quality_counts']['materialized']['fallback_count'] ?? null ), 'quality-reconciliation-does-not-credit-forged-aggregate' );

$stale_resolution_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'quality'             => array( 'fallback_count' => 0 ),
			'blocks_engine'       => array( 'wordpress_site_plan' => array( 'quality' => array( 'metrics' => array( 'fallback_count' => 458 ) ) ) ),
			'quality_resolutions' => array_merge( $quality_fixture['quality_resolutions_evidence'], array( 'resolutions' => array_slice( $verified_resolutions, 0, 7 ) ) ),
		),
	)
);
$assert( 458 === ( $stale_resolution_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-rejects-stale-aggregate-count-mismatch' );

$mismatched_resolution_entries = $verified_resolutions;
$mismatched_resolution_entries[0]['receipt']['fallback_hash'] = hash( 'sha256', 'other-source' );
$mismatched_resolution_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'import_report' => array(
			'quality'             => array( 'fallback_count' => 0 ),
			'blocks_engine'       => array( 'wordpress_site_plan' => array( 'quality' => array( 'metrics' => array( 'fallback_count' => 458 ) ) ) ),
			'quality_resolutions' => array_merge( $quality_fixture['quality_resolutions_evidence'], array( 'resolutions' => $mismatched_resolution_entries ) ),
		),
	)
);
$assert( 458 === ( $mismatched_resolution_contract['quality_counts']['fallback_count'] ?? null ), 'quality-reconciliation-rejects-mismatched-receipt-hash' );

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
$assert( 'acceptable_conversion' === ( $quality_gate_error['fixture_diagnostics']['diagnostics'][0]['acceptability'] ?? '' ), 'ability-error-fixture-acceptability-classified' );

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
$assert( 0 === ( $numeric_contract['diagnostic_summary']['total'] ?? -1 ), 'contract-drops-count-only-diagnostic' );
$assert( ! $numeric_only( $numeric_diagnostic['type'] ?? '' ), 'contract-type-not-numeric-only' );
$assert( ! $numeric_only( $numeric_diagnostic['kind'] ?? '' ), 'contract-kind-not-numeric-only' );
$assert( ! $numeric_only( $numeric_diagnostic['reason_code'] ?? '' ), 'contract-reason-code-not-numeric-only' );

$deduped_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'status'        => 'failed',
		'success'       => false,
		'import_report' => array(
			'diagnostics'   => array(
				array(
					'type'           => 'core_html_block',
					'source_path'    => 'posts/page-home.post_content',
					'selector'       => 'iframe#map',
					'reason_code'    => 'generated_document_contains_core_html',
					'source_snippet' => '<iframe id="map"></iframe>',
				),
			),
			'blocks_engine' => array(
				'conversion_report' => array(
					'diagnostics' => array(
						array(
							'type'                  => 'unsupported_html_fallback',
							'source_path'           => 'posts/page-home.post_content',
							'selector'              => 'iframe#map',
							'reason_code'           => 'generated_document_contains_core_html',
							'emitted_block_preview' => '<!-- wp:html --><iframe id="map"></iframe><!-- /wp:html -->',
						),
					),
				),
			),
		),
	)
);
$assert( 1 === ( $deduped_contract['diagnostic_summary']['total'] ?? 0 ), 'contract-dedupes-context-equivalent-diagnostics' );
$assert( '<iframe id="map"></iframe>' === ( $deduped_contract['diagnostics'][0]['source_snippet'] ?? '' ), 'contract-preserves-source-snippet' );
$assert( '<!-- wp:html --><iframe id="map"></iframe><!-- /wp:html -->' === ( $deduped_contract['diagnostics'][0]['emitted_block_preview'] ?? '' ), 'contract-merges-duplicate-output-preview' );

$gaps_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'status'        => 'completed',
		'success'       => true,
		'import_report' => array(
			'blocks_engine' => array(
				'gutenberg_gaps' => array(
					array(
						'id'                     => 'gap-42',
						'block_name'             => 'blocks-engine/example',
						'references'             => array( 'file:./view.js' ),
						'source_path'            => 'pages/index.html',
						'materialization_status' => 'installed_activated',
					),
				),
			),
		),
	)
);
$gaps_diagnostics = array_values( array_filter( $gaps_contract['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'gap-42' === ( $diagnostic['id'] ?? '' ) ) );
$gaps_diagnostic = $gaps_diagnostics[0] ?? array();
$assert( 'gap-42' === ( $gaps_diagnostic['id'] ?? '' ) && 'blocks-engine/example' === ( $gaps_diagnostic['block_name'] ?? '' ) && 'pages/index.html' === ( $gaps_diagnostic['source_path'] ?? '' ) && 'installed_activated' === ( $gaps_diagnostic['materialization_status'] ?? '' ) && array( 'file:./view.js' ) === ( $gaps_diagnostic['references'] ?? array() ), 'contract-projects-gutenberg-gap-provenance-and-materialization-status' );

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

$runtime_preservation_contract = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'status'      => 'completed',
		'success'     => true,
		'diagnostics' => array(
			array(
				'code'                => 'preserved_runtime_island',
				'loss_class'          => 'runtime_island_preserved',
				'selector'            => '.site-nav',
				'runtime_requirement' => 'client_script_execution',
				'preservation_status' => 'accepted_runtime_preservation',
				'disposition'         => 'preserve',
				'js_handling'         => 'preserve_verbatim',
			)
		),
	)
);
$runtime_preservation_diagnostic = $runtime_preservation_contract['diagnostics'][0] ?? array();
$assert( 'accepted_runtime_preservation' === ( $runtime_preservation_diagnostic['preservation_status'] ?? '' ), 'contract-preserves-runtime-acceptance-status' );
$assert( 'client_script_execution' === ( $runtime_preservation_diagnostic['runtime_requirement'] ?? '' ), 'contract-preserves-runtime-requirement' );
$assert( 'preserve' === ( $runtime_preservation_diagnostic['disposition'] ?? '' ), 'contract-preserves-runtime-disposition' );
$assert( 'preserve_verbatim' === ( $runtime_preservation_diagnostic['js_handling'] ?? '' ), 'contract-preserves-runtime-js-handling' );

$finalized_report = Static_Site_Importer_Import_Report::from_array(
	array(
	'schema'      => 'static-site-importer/import-report/v1',
	'version'     => 1,
	'theme_slug'  => 'finalized-diagnostic-source',
	'quality'     => array( 'fallback_count' => 0 ),
	'diagnostics' => array(
		array(
			'type'        => 'invalid_block_content',
			'source_path' => 'templates/front-page.html',
		),
	),
	'blocks_engine' => array(
		'conversion_report' => array(
			'diagnostics' => array(
				array(
					'type'        => 'stale_nested_diagnostic',
					'source_path' => 'legacy-report.html',
				),
			),
		),
	),
	)
);
$finalized_quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $finalized_report, array() );
$finalized_report['materialization_receipt'] = array(
	'schema'    => 'static-site-importer/materialization-receipt/v2',
	'status'    => 'completed',
	'completed' => array( 'pages' => array( 1 ) ),
);
$cached_contract = Static_Site_Importer_Report_Diagnostics::refresh_projections( $finalized_report, $finalized_quality );
$assert( 1 === ( $cached_contract['diagnostic_summary']['total'] ?? 0 ), 'finalized-report-is-sole-diagnostic-source' );
$assert( 'invalid_block_content' === ( $cached_contract['diagnostics'][0]['type'] ?? '' ), 'finalized-report-builds-fixture-projection' );
$assert( $cached_contract === Static_Site_Importer_Canonical_Import_Service::success_diagnostics_contract( array( 'fixture_diagnostics' => $cached_contract, 'import_report' => $finalized_report->to_array() ) ), 'canonical-service-reuses-finalized-fixture-projection' );
$assert( 'completed' === ( $cached_contract['materialization_receipt']['status'] ?? '' ), 'projection-refresh-includes-final-receipt' );
$assert( ( $finalized_report['compact_summary']['status'] ?? '' ) === ( $cached_contract['status'] ?? '' ), 'projection-refresh-keeps-statuses-aligned' );

$normalized_form_fallback = array(
	'type'        => 'unsupported_html_fallback',
	'code'        => 'html_form_fallback',
	'reason_code' => 'html_form_fallback',
	'source_path' => 'index.html',
	'selector'    => 'form.newsletter',
	'form'        => array( 'class' => 'newsletter' ),
	'controls'    => array(
		array(
			'tag'  => 'input',
			'type' => 'email',
			'name' => 'email',
		),
	),
);
$hidden_response_iframe = array(
	'type'                => 'unsupported_html_fallback',
	'code'                => 'html_form_fallback',
	'reason_code'         => 'html_form_fallback',
	'source_path'         => 'index.html',
	'selector'            => 'iframe.form-response',
	'source_html_preview' => '<iframe class="form-response" hidden></iframe>',
);
$form_identity    = Static_Site_Importer_Report_Diagnostics::fallback_reconciliation_identity( $normalized_form_fallback );
$form_hash        = Static_Site_Importer_Report_Diagnostics::fallback_reconciliation_hash( $normalized_form_fallback );
$block_hash       = hash( 'sha256', '<!-- wp:jetpack/contact-form -->newsletter<!-- /wp:jetpack/contact-form -->' );
$page_hash        = hash( 'sha256', '<!-- wp:group -->materialized page<!-- /wp:group -->' );
$provider_receipt = array(
	'schema'                           => 'static-site-importer/quality-resolution-receipt/v1',
	'status'                           => 'completed',
	'fallback_reconciliation_identity' => $form_identity,
	'fallback_hash'                    => $form_hash,
	'binding_reconciliation_identity'  => hash( 'sha256', 'form-fallback-binding' ),
	'materialized_block_hash'          => $block_hash,
	'persisted_fragment_hash'          => $block_hash,
	'materialized_content_hash'        => $page_hash,
	'provider'                         => 'jetpack',
);
$normalized_form_report = Static_Site_Importer_Import_Report::from_array(
	array(
	'quality'                 => array( 'fallback_count' => 2 ),
	'diagnostics'             => array( $normalized_form_fallback, $hidden_response_iframe ),
	'materialization_receipt' => array(
		'completed' => array(
			'materialized_pages' => array(
				'index.html' => array( 'content_hash' => $page_hash ),
			),
		),
	),
	)
);
Static_Site_Importer_Report_Diagnostics::reconcile_provider_materialized_fallbacks( $normalized_form_report, array( $provider_receipt ) );
$assert( 1 === ( $normalized_form_report['quality']['fallback_count'] ?? 0 ) && 2 === ( $normalized_form_report['quality']['source_fallback_count'] ?? 0 ) && 1 === ( $normalized_form_report['quality_resolutions']['resolved_by_provider'] ?? 0 ), 'normalized-form-diagnostic-reconciles-exact-provider-receipt' );
$assert( 'resolved_by_provider' === ( $normalized_form_report['quality_resolutions']['resolutions'][0]['state'] ?? '' ) && 'unresolved' === ( $normalized_form_report['quality_resolutions']['resolutions'][1]['state'] ?? '' ), 'unreceipted-hidden-response-iframe-remains-unresolved' );

$mismatched_receipt                  = $provider_receipt;
$mismatched_receipt['fallback_hash'] = hash( 'sha256', 'mismatched fallback' );
$mismatched_form_report                = Static_Site_Importer_Import_Report::from_array( $normalized_form_report->to_array() );
$mismatched_form_report['quality']     = array( 'fallback_count' => 1 );
$mismatched_form_report['diagnostics'] = array( $normalized_form_fallback );
Static_Site_Importer_Report_Diagnostics::reconcile_provider_materialized_fallbacks( $mismatched_form_report, array( $mismatched_receipt ) );
$assert( 1 === ( $mismatched_form_report['quality']['fallback_count'] ?? 0 ) && 'unresolved' === ( $mismatched_form_report['quality_resolutions']['resolutions'][0]['state'] ?? '' ), 'normalized-form-diagnostic-rejects-mismatched-provider-receipt' );

$safe_runtime_report = Static_Site_Importer_Import_Report::from_array(
	array(
	'quality' => array( 'fallback_count' => 1 ),
	'diagnostics' => array(
		array(
			'type'                  => 'unsupported_html_fallback',
			'loss_class'            => 'runtime_island_preserved',
			'acceptability'         => 'acceptable_preservation',
			'source_path'           => 'contact.html',
			'selector'              => 'iframe.contact-form',
			'reason_code'           => 'preserved_runtime_embed',
			'source_html_preview'   => '<iframe class="contact-form" src="https://forms.hsforms.com/embed/contact" title="Contact form"></iframe>',
			'preservation_strategy' => 'sanitized_embed_markup',
			'runtime_requirement'   => 'third_party_embed_runtime',
			'materialization_path'  => 'runtime_island_registry',
		),
	),
	)
);
$safe_runtime_quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $safe_runtime_report, array( 'fail_on_quality' => true ) );
$assert( true === ( $safe_runtime_quality['pass'] ?? false ) && false === ( $safe_runtime_quality['fail_import'] ?? true ), 'bounded-safe-runtime-iframe-passes-quality-admission' );
$assert( 1 === ( $safe_runtime_quality['accepted_preserved_runtime_island_count'] ?? 0 ) && 0 === ( $safe_runtime_quality['unsupported_fallback_count'] ?? -1 ), 'runtime-island-counts-are-separated-from-unsupported-fallbacks' );
$assert( 1 === ( $safe_runtime_report['import_validation_result']['counts']['accepted_preserved_runtime_islands'] ?? 0 ) && 'passed' === ( $safe_runtime_report['import_validation_result']['quality_gates']['fallback_blocks']['status'] ?? '' ), 'validation-result-reports-accepted-runtime-island-without-fallback-failure' );
$assert( 'sanitized_embed_markup' === ( $safe_runtime_report['finding_packets']['packets'][0]['preservation']['strategy'] ?? '' ) && 'runtime_island_registry' === ( $safe_runtime_report['finding_packets']['packets'][0]['preservation']['materialization_path'] ?? '' ), 'finding-packet-preserves-runtime-island-contract-evidence' );

$unsafe_runtime_report = Static_Site_Importer_Import_Report::from_array( $safe_runtime_report->to_array() );
$unsafe_diagnostics    = $unsafe_runtime_report->diagnostics();
$unsafe_diagnostics[0]['source_html_preview'] = '<iframe srcdoc="<script>alert(1)</script>"></iframe>';
$unsafe_runtime_report->set_diagnostics( $unsafe_diagnostics );
$unsafe_runtime_quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $unsafe_runtime_report, array( 'fail_on_quality' => true ) );
$assert( false === ( $unsafe_runtime_quality['pass'] ?? true ) && true === ( $unsafe_runtime_quality['fail_import'] ?? false ), 'unsafe-runtime-iframe-remains-fail-closed' );
$assert( 0 === ( $unsafe_runtime_quality['accepted_preserved_runtime_island_count'] ?? -1 ) && 1 === ( $unsafe_runtime_quality['unsupported_fallback_count'] ?? 0 ), 'unsafe-runtime-iframe-is-counted-as-unsupported-fallback' );

$incomplete_runtime_report = Static_Site_Importer_Import_Report::from_array( $safe_runtime_report->to_array() );
$incomplete_diagnostics    = $incomplete_runtime_report->diagnostics();
unset( $incomplete_diagnostics[0]['materialization_path'] );
$incomplete_runtime_report->set_diagnostics( $incomplete_diagnostics );
$incomplete_runtime_quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $incomplete_runtime_report, array( 'fail_on_quality' => true ) );
$assert( false === ( $incomplete_runtime_quality['pass'] ?? true ) && true === ( $incomplete_runtime_quality['fail_import'] ?? false ), 'missing-runtime-materialization-contract-remains-fail-closed' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import diagnostic contract smoke passed (' . $assertions . " assertions)\n";
