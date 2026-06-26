<?php
/**
 * Tests generated block diagnostic routing metadata.
 *
 * @package StaticSiteImporter
 */

/**
 * Verifies generated block diagnostics carry enough detail to route upstream fixes.
 */
class StaticSiteImporterFallbackDiagnosticsTest extends WP_UnitTestCase {

	/**
	 * Import report summaries include compact machine-actionable diagnostics.
	 */
	public function test_import_report_summary_includes_compact_diagnostics(): void {
		$result = Static_Site_Importer_Report_Diagnostics::import_report_summary(
			array(
				'entry_file'              => '/tmp/source/index.html',
				'version'                 => 1,
				'theme_slug'              => 'static-template-import',
				'blocks_engine'           => array(
					'available'        => true,
					'fragment_count'   => 0,
					'website_artifact' => array(
						'summary' => array(
							'schema'           => 'blocks-engine/php-transformer/result/v1',
							'status'           => 'success',
							'source'           => 'artifact.json',
							'diagnostic_count' => 0,
						),
					),
				),
				'source_documents'        => array(
					'total_count'           => 2,
					'unresolved_link_count' => 1,
				),
				'diagnostics'             => array(
					array(
						'id'                     => 'diag-001-core_html_block-generated_document_contains_core_html-indexhtml',
						'type'                   => 'core_html_block',
						'severity'               => 'warning',
						'category'               => 'fallback_block',
						'reason_code'            => 'generated_document_contains_core_html',
						'suggested_repair_class' => 'replace_fallback_block',
						'source_path'            => 'index.html',
						'selector'               => 'iframe#store-widget.embedded.checkout',
						'source_html_preview'    => '<iframe id="store-widget" class="embedded checkout"></iframe>',
						'emitted_block_preview'  => '<!-- wp:html --><iframe id="store-widget" class="embedded checkout"></iframe><!-- /wp:html -->',
						'block_name'             => 'core/html',
						'engine'                 => 'blocks-engine/php-transformer',
						'stage'                  => 'generated_theme_block_analysis',
						'reason'                 => 'generated_document_contains_core_html',
						'html_length'            => 64,
					),
				),
			),
			array(
				'pass'                         => false,
				'fail_import'                  => false,
				'failure_reasons'              => array( 'core_html_block' ),
				'core_html_block_count'        => 1,
				'invalid_block_document_count' => 0,
			)
		);

		$this->assertSame( 'static-site-importer/import-metrics/v1', $result['schema'] ?? '' );
		$this->assertSame( 1, $result['version'] ?? 0 );
		$this->assertSame( 1, $result['report_version'] ?? 0 );
		$this->assertSame( 'static-template-import', $result['theme_slug'] ?? '' );
		$this->assertSame( 'blocks-engine/php-transformer/result/v1', $result['compiler']['schema'] ?? '' );
		$this->assertSame( 'success', $result['compiler']['status'] ?? '' );
		$this->assertSame( 1, $result['diagnostic_count'] ?? 0 );
		$this->assertSame( 2, $result['source_document_count'] ?? 0 );
		$this->assertSame( 1, $result['unresolved_link_count'] ?? 0 );
		$this->assertSame( 1, $result['diagnostic_summary']['warning'] ?? 0 );
		$this->assertCount( 1, $result['warning_summaries'] ?? array() );
		$this->assertSame( 'core_html_block', $result['warning_summaries'][0]['type'] ?? '' );
		$this->assertCount( 1, $result['diagnostics'] ?? array() );
		$this->assertSame( 'diag-001-core_html_block-generated_document_contains_core_html-indexhtml', $result['diagnostics'][0]['id'] ?? '' );
		$this->assertSame( 'index.html', $result['diagnostics'][0]['source_path'] ?? '' );
		$this->assertSame( 'iframe#store-widget.embedded.checkout', $result['diagnostics'][0]['selector'] ?? '' );
		$this->assertStringContainsString( '<iframe', $result['diagnostics'][0]['source_html_preview'] ?? '' );
		$this->assertStringContainsString( '<!-- wp:html -->', $result['diagnostics'][0]['emitted_block_preview'] ?? '' );
		$this->assertArrayNotHasKey( 'html_length', $result['diagnostics'][0] ?? array() );
	}

	/**
	 * Incomplete diagnostic blocks fall back to HTML previews instead of calling serialize_blocks().
	 */
	public function test_fallback_diagnostic_entry_handles_incomplete_block_shape(): void {
		$warnings = array();
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			}
		);

		try {
			$entry = Static_Site_Importer_Report_Diagnostics::fallback_diagnostic_entry(
				'freeform_block',
				'generated:templates/front-page.html',
				'<p>Unparsed HTML</p>',
				array(
					'reason' => 'generated_document_contains_malformed_freeform_html',
					'stage'  => 'generated_theme_block_analysis',
				),
				array(
					'innerHTML' => '<p>Unparsed HTML</p>',
				)
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
		$this->assertStringContainsString( 'Unparsed HTML', $entry['emitted_block_preview'] ?? '' );
		$this->assertNull( $entry['block_name'] ?? null );
	}

	/**
	 * Import validation and finding packet artifacts expose repair-loop contracts.
	 */
	public function test_import_validation_result_and_finding_packets_are_machine_readable(): void {
		$report = array(
			'schema'                  => 'static-site-importer/import-report/v1',
			'entry_file'              => '/tmp/source/index.html',
			'version'                 => 1,
			'theme_slug'              => 'static-template-import',
			'source'                  => array(
				'type'   => 'website_artifact',
				'source' => 'artifact.json',
			),
			'blocks_engine'           => array(
				'website_artifact' => array(
					'summary'    => array(
						'schema' => 'blocks-engine/php-transformer/result/v1',
						'status' => 'success',
						'source' => 'artifact.json',
					),
					'input'      => array( 'entry_path' => 'website/index.html' ),
					'provenance' => array( 'source' => 'artifact.json' ),
				),
			),
			'source_documents'        => array(
				'total_count' => 1,
			),
			'visual_fidelity'         => array(
				'status'     => 'requires_external_render_check',
				'gate_owner' => 'benchmark_harness',
			),
			'semantic_fidelity'       => array(
				'status'     => 'requires_external_render_check',
				'gate_owner' => 'benchmark_harness',
			),
			'quality'                 => array(
				'fallback_count'                     => 0,
				'content_loss_count'                 => 0,
				'empty_conversion_count'             => 0,
				'core_html_block_count'              => 1,
				'freeform_block_count'               => 0,
				'invalid_block_count'                => 0,
				'invalid_block_document_count'       => 0,
				'unsafe_svg_count'                   => 0,
				'svg_materialization_failure_count'  => 0,
				'svg_sprite_reference_failure_count' => 0,
				'commerce_dependency_failures'       => 0,
			),
			'diagnostics'             => array(
				array(
					'type'                   => 'core_html_block',
					'source'                 => 'generated:templates/front-page.html',
					'selector'               => 'iframe#store-widget.embedded.checkout',
					'source_html_preview'    => '<iframe id="store-widget" class="embedded checkout"></iframe>',
					'emitted_block_preview'  => '<!-- wp:html --><iframe id="store-widget" class="embedded checkout"></iframe><!-- /wp:html -->',
					'block_name'             => 'core/html',
					'engine'                 => 'blocks-engine/php-transformer',
					'stage'                  => 'generated_theme_block_analysis',
					'reason'                 => 'generated_document_contains_core_html',
					'block_path'             => '0',
				),
			),
		);

		$quality = Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );

		$this->assertSame( 'blocks-engine/import-validation-result/v1', $report['import_validation_result']['schema'] ?? '' );
		$this->assertSame( 'ImportValidationResult', $report['import_validation_result']['artifact_type'] ?? '' );
		$this->assertSame( 'reported', $report['import_validation_result']['status'] ?? '' );
		$this->assertFalse( $quality['pass'] ?? true );
		$this->assertSame( 1, $report['import_validation_result']['counts']['core_html_blocks'] ?? 0 );
		$this->assertSame( 'import-validation-result.json', $report['import_validation_result']['artifacts']['import_validation_result']['path'] ?? '' );
		$this->assertSame( 'finding-packets.json', $report['import_validation_result']['artifacts']['finding_packets']['path'] ?? '' );
		$this->assertSame( '/tmp/source/index.html', $report['import_validation_result']['reproduction_context']['entry_file'] ?? '' );

		$this->assertSame( 'blocks-engine/finding-packets/v1', $report['finding_packets']['schema'] ?? '' );
		$this->assertSame( 1, $report['finding_packets']['count'] ?? 0 );
		$packet = $report['finding_packets']['packets'][0] ?? array();
		$this->assertSame( 'blocks-engine/finding-packet/v1', $packet['schema'] ?? '' );
		$this->assertSame( 'FindingPacket', $packet['artifact_type'] ?? '' );
		$this->assertSame( 'core_html_block', $packet['type'] ?? '' );
		$this->assertSame( 'warning', $packet['severity'] ?? '' );
		$this->assertSame( 'blocks-engine/php-transformer', $packet['owner'] ?? '' );
		$this->assertSame( 'replace_fallback_block', $packet['routing']['suggested_repair_class'] ?? '' );
		$this->assertSame( 'templates/front-page.html', $packet['source']['path'] ?? '' );
		$this->assertStringContainsString( '<iframe', $packet['source']['snippet'] ?? '' );
		$this->assertStringContainsString( '<!-- wp:html -->', $packet['observed']['output'] ?? '' );
		$this->assertStringContainsString( 'without fallback', $packet['expected']['outcome'] ?? '' );
	}

	/**
	 * Codebox/runtime visual parity artifacts use durable refs and explicit pending slots.
	 */
	public function test_visual_parity_artifacts_are_stable_and_omit_local_paths(): void {
		$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' );
		$report['theme_slug'] = 'visual-parity-fixture';

		Static_Site_Importer_Report_Diagnostics::finalize_report(
			$report,
			array(
				'validation_artifacts' => array(
					'browser_render'      => array(
						'kind'        => 'browser_render_evidence',
						'artifact_id' => 'codebox-run-123/render.json',
						'url'         => 'https://artifacts.example.test/runs/123/render.json',
						'path'        => '/tmp/codebox/render.json',
					),
					'imported_screenshot' => array(
						'kind' => 'imported_screenshot',
						'path' => 'screenshots/imported.png',
					),
					'visual_diff'         => array(
						'kind' => 'visual_diff',
						'path' => '/Users/chubes/Downloads/local-diff.png',
					),
					'block_validation'    => array(
						'kind'          => 'gutenberg_block_validation',
						'artifact_name' => 'block-validation.json',
					),
				),
			)
		);

		$visual = $report['visual_parity_artifacts'] ?? array();
		$this->assertSame( 'static-site-importer/visual-parity-artifacts/v1', $visual['schema'] ?? '' );
		$this->assertSame( 'pending', $visual['status'] ?? '' );
		$this->assertSame( 'codebox_runtime', $visual['owner'] ?? '' );
		$this->assertSame( 'durable_artifact_refs_only', $visual['contract'] ?? '' );

		$artifacts = $visual['artifacts'] ?? array();
		$this->assertSame( 'captured', $artifacts['browser_render']['status'] ?? '' );
		$this->assertSame( 'codebox-run-123/render.json', $artifacts['browser_render']['ref']['artifact_id'] ?? '' );
		$this->assertSame( 'https://artifacts.example.test/runs/123/render.json', $artifacts['browser_render']['ref']['url'] ?? '' );
		$this->assertArrayNotHasKey( 'path', $artifacts['browser_render']['ref'] ?? array() );
		$this->assertSame( 'captured', $artifacts['imported_screenshot']['status'] ?? '' );
		$this->assertSame( 'imported.png', $artifacts['imported_screenshot']['ref']['artifact_name'] ?? '' );
		$this->assertSame( 'pending', $artifacts['source_screenshot']['status'] ?? '' );
		$this->assertSame( 'not_captured', $artifacts['source_screenshot']['capture_state'] ?? '' );
		$this->assertStringContainsString( 'not captured', $artifacts['source_screenshot']['reason'] ?? '' );
		$this->assertSame( 'pending', $artifacts['visual_diff']['status'] ?? '' );
		$this->assertSame( 'not_captured', $artifacts['visual_diff']['capture_state'] ?? '' );
		$this->assertSame( 'captured', $artifacts['import_report']['status'] ?? '' );
		$this->assertSame( 'import-report.json', $artifacts['import_report']['ref']['artifact_name'] ?? '' );
		$this->assertSame( 'block-validation.json', $artifacts['block_validation']['ref']['artifact_name'] ?? '' );
		$this->assertSame( $visual, $report['import_validation_result']['visual_parity_artifacts'] ?? array() );
		$this->assertSame( $visual, $report['compact_summary']['visual_parity_artifacts'] ?? array() );
		$this->assertReportValueHasNoLocalPath( $visual );
	}

	/**
	 * Artifact diagnostics use SSI's schema when no WP Codebox PHP normalizer is available.
	 */
	public function test_artifact_diagnostics_adapter_emits_static_site_importer_fallback_shape(): void {
		if ( function_exists( 'wp_codebox_build_artifact_diagnostics' ) || class_exists( 'WP_Codebox_Artifact_Diagnostics_Normalizer' ) ) {
			$this->markTestSkipped( 'WP Codebox exposes an artifact diagnostics normalizer.' );
		}

		$diagnostics = Static_Site_Importer_Artifact_Diagnostics_Adapter::build_for_import_report(
			array(
				'diagnostics' => array(
					array(
						'id'          => 'diag-001',
						'type'        => 'unsupported_html_fallback',
						'severity'    => 'notice',
						'reason_code' => 'no_transform',
						'source_path' => 'index.html',
						'selector'    => 'iframe#store-widget',
						'message'     => 'Used fallback block.',
						'block_name'  => 'core/html',
					),
					array(
						'type'          => 'missing_asset',
						'severity'      => 'error',
						'error_message' => 'Asset file is missing.',
						'source_path'   => 'assets/hero.jpg',
					),
				),
			)
		);

		$this->assertSame( 'static-site-importer/artifact-diagnostics/v1', $diagnostics['schema'] ?? '' );
		$this->assertSame( 'reported', $diagnostics['status'] ?? '' );
		$this->assertSame( 'static-site-importer', $diagnostics['source'] ?? '' );
		$this->assertSame( array( 'total' => 2, 'error' => 1, 'warning' => 0, 'notice' => 1, 'info' => 0 ), $diagnostics['summary'] ?? array() );
		$this->assertSame( 'diag-001', $diagnostics['diagnostics'][0]['id'] ?? '' );
		$this->assertSame( 'unsupported_html_fallback', $diagnostics['diagnostics'][0]['type'] ?? '' );
		$this->assertSame( 'no_transform', $diagnostics['diagnostics'][0]['reason_code'] ?? '' );
		$this->assertSame( 'index.html', $diagnostics['diagnostics'][0]['source_path'] ?? '' );
		$this->assertSame( 'iframe#store-widget', $diagnostics['diagnostics'][0]['selector'] ?? '' );
		$this->assertSame( 'core/html', $diagnostics['diagnostics'][0]['block_name'] ?? '' );
		$this->assertSame( 'Asset file is missing.', $diagnostics['diagnostics'][1]['error_message'] ?? '' );
		$this->assertSame( 'error', $diagnostics['diagnostics'][1]['severity'] ?? '' );

		$empty = Static_Site_Importer_Artifact_Diagnostics_Adapter::build_for_import_report( array( 'diagnostics' => array() ) );
		$this->assertSame( 'clean', $empty['status'] ?? '' );
		$this->assertSame( array( 'total' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0, 'info' => 0 ), $empty['summary'] ?? array() );
		$this->assertSame( array(), $empty['diagnostics'] ?? null );
	}

	/**
	 * Codebox validation output exposes importer diagnostics for repair grouping.
	 */
	public function test_codebox_validation_result_includes_importer_diagnostics(): void {
		$provider = static function (): array {
			$import_report = array(
				'quality'                  => array(
					'fallback_count'                        => 1,
					'core_html_block_count'                 => 1,
					'freeform_block_count'                  => 1,
					'invalid_block_count'                   => 1,
					'invalid_block_document_count'          => 1,
					'svg_materialization_failure_count'     => 1,
					'runtime_dependency_parity_issue_count' => 1,
				),
				'diagnostics'              => array(
					array(
						'id'          => 'diag-core-html',
						'type'        => 'core_html_block',
						'severity'    => 'warning',
						'reason_code' => 'generated_document_contains_core_html',
						'source_path' => 'templates/front-page.html',
						'selector'    => 'a.wp-block-button__link',
						'block_name'  => 'core/html',
					),
					array(
						'id'          => 'diag-svg',
						'type'        => 'svg_materialization_failure',
						'code'        => 'svg_missing_payload',
						'source_path' => 'assets/icon.svg',
					),
					array(
						'id'          => 'diag-image',
						'type'        => 'dropped_image_asset',
						'code'        => 'image_missing',
						'source_path' => 'assets/hero.jpg',
					),
				),
				'blocks_engine'           => array(
					'conversion_report'         => array(
						'diagnostic_count'  => 1,
						'diagnostics'       => array(
							array(
								'id'          => 'be-button-style',
								'type'        => 'button_style_loss',
								'code'        => 'button_radius_dropped',
								'source_path' => 'index.html',
								'selector'    => 'button.cta',
							),
						),
						'fallbacks'         => array(
							array(
								'id'          => 'be-fallback',
								'type'        => 'unsupported_html_fallback',
								'source_path' => 'index.html',
								'selector'    => 'iframe.map',
							),
						),
					),
					'runtime_dependency_parity' => array(
						'finding_count'       => 1,
						'missing_dom_targets' => array(
							array(
								'id'          => 'runtime-missing-target',
								'type'        => 'runtime_dependency_missing_dom_target',
								'code'        => 'missing_dom_target',
								'source_path' => 'scripts/app.js',
								'selector'    => '#cart-drawer',
							),
						),
					),
				),
				'import_validation_result' => array(
					'artifacts' => array(
						'import_report' => array( 'path' => 'import-report.json' ),
					),
				),
			);

			return array(
				'success'       => false,
				'status'        => 'failed',
				'import_report' => $import_report,
				'artifacts'     => array(
					'import_report' => array(
						'artifact_id' => 'run-123/import-report.json',
						'path'        => '/tmp/local/import-report.json',
					),
				),
			);
		};

		$result = $provider(
			array(),
			array(
				'import_args' => array(
					'slug' => 'fixture-one',
					'name' => 'Fixture One',
				),
			)
		);

		$this->assertIsArray( $result );
		$fixture = $result['fixture_diagnostics'] ?? array();
		$this->assertSame( 'static-site-importer/import-diagnostics/v1', $fixture['schema'] ?? '' );
		$this->assertSame( 'fixture-one', $fixture['fixture']['slug'] ?? '' );
		$this->assertSame( 'Fixture One', $fixture['fixture']['name'] ?? '' );
		$this->assertSame( 1, $fixture['quality_counts']['core_html_block_count'] ?? 0 );
		$this->assertSame( 1, $fixture['import_report_quality_counts']['runtime_dependency_parity_issue_count'] ?? 0 );
		$this->assertSame( 1, $fixture['diagnostic_summary']['type']['core_html_block'] ?? 0 );
		$this->assertSame( 2, $fixture['diagnostic_summary']['repair_bucket']['fallback_block'] ?? 0 );
		$this->assertSame( 5, $fixture['diagnostic_summary']['parser_owner']['blocks-engine'] ?? 0 );
		$this->assertSame( 'templates/front-page.html', $fixture['diagnostics'][0]['source_path'] ?? '' );
		$this->assertSame( 'a.wp-block-button__link', $fixture['diagnostics'][0]['selector'] ?? '' );
		$this->assertSame( 'generated_document_contains_core_html', $fixture['diagnostics'][0]['code'] ?? '' );
		$this->assertSame( 'fallback_block', $fixture['diagnostics'][0]['repair_bucket'] ?? '' );
		$this->assertSame( 'blocks-engine', $fixture['diagnostics'][0]['parser_owner'] ?? '' );
		$this->assertSame( 'unsupported_html_fallback', $fixture['diagnostics'][4]['type'] ?? '' );
		$this->assertSame( 'fallback_block', $fixture['diagnostics'][4]['repair_bucket'] ?? '' );
		$this->assertCount( 1, $fixture['runtime_dependency_target_gaps'] ?? array() );
		$this->assertSame( '#cart-drawer', $fixture['runtime_dependency_target_gaps'][0]['selector'] ?? '' );
		$this->assertCount( 1, $fixture['asset_diagnostics'] ?? array() );
		$this->assertSame( 'assets/hero.jpg', $fixture['asset_diagnostics'][0]['source_path'] ?? '' );
		$this->assertCount( 1, $fixture['svg_diagnostics'] ?? array() );
		$this->assertSame( 'assets/icon.svg', $fixture['svg_diagnostics'][0]['source_path'] ?? '' );
		$this->assertCount( 1, $fixture['button_style_loss_hints'] ?? array() );
		$this->assertSame( 'button.cta', $fixture['button_style_loss_hints'][0]['selector'] ?? '' );
		$this->assertContains(
			array(
				'parser_owner'  => 'blocks-engine',
				'repair_bucket' => 'fallback_block',
				'count'         => 2,
			),
			$fixture['top_parser_buckets'] ?? array()
		);
		$this->assertSame( 'run-123/import-report.json', $fixture['artifact_refs']['import_report']['artifact_id'] ?? '' );
		$this->assertArrayNotHasKey( 'path', $fixture['artifact_refs']['import_report'] ?? array() );
	}

	/**
	 * Import diagnostics are product-owned and do not require Codebox runtime fields.
	 */
	public function test_import_diagnostic_contract_normalizes_parser_repair_buckets_without_codebox(): void {
		$diagnostics = Static_Site_Importer_Diagnostic_Contract::build(
			array(
				'status'        => 'failed',
				'success'       => false,
				'import_report' => array(
					'quality'       => array(
						'invalid_block_count'                => 1,
						'semantic_parity_failure_count'      => 1,
						'runtime_dependency_parity_issue_count' => 1,
					),
					'diagnostics'   => array(
						array(
							'type'        => 'document_metadata_routed',
							'source_path' => 'website/index.html',
							'severity'    => 'info',
							'message'     => 'Full-document metadata/assets were routed through the generated_theme.document_metadata contract instead of generated page block content.',
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

		$this->assertSame( 'static-site-importer/import-diagnostics/v1', $diagnostics['schema'] ?? '' );
		$this->assertSame( 5, $diagnostics['diagnostic_summary']['total'] ?? 0 );
		$this->assertSame( 1, $diagnostics['diagnostic_summary']['repair_bucket']['dropped_images'] ?? 0 );
		$this->assertSame( 1, $diagnostics['diagnostic_summary']['repair_bucket']['static_site_import_quality'] ?? 0 );
		$this->assertSame( 1, $diagnostics['diagnostic_summary']['repair_bucket']['invalid_block_content'] ?? 0 );
		$this->assertSame( 1, $diagnostics['diagnostic_summary']['repair_bucket']['runtime_target_gap'] ?? 0 );
		$this->assertSame( 1, $diagnostics['diagnostic_summary']['repair_bucket']['semantic_parity'] ?? 0 );
		$this->assertSame( 2, $diagnostics['diagnostic_summary']['parser_owner']['static-site-importer'] ?? 0 );
		$this->assertSame( 3, $diagnostics['diagnostic_summary']['parser_owner']['blocks-engine'] ?? 0 );
		$this->assertSame( 'static-site-importer', $diagnostics['by_repair_bucket']['dropped_images'][0]['parser_owner'] ?? '' );
		$this->assertSame( 'document_metadata_routed', $diagnostics['by_repair_bucket']['static_site_import_quality'][0]['type'] ?? '' );
		$this->assertSame( 'blocks-engine', $diagnostics['by_repair_bucket']['runtime_target_gap'][0]['parser_owner'] ?? '' );
		$this->assertSame( '#canvas', $diagnostics['runtime_dependency_target_gaps'][0]['selector'] ?? '' );
		$this->assertSame( 'header nav', $diagnostics['by_repair_bucket']['semantic_parity'][0]['selector'] ?? '' );
	}

	/**
	 * Validation errors can still be consumed as structured JSON.
	 */
	public function test_validation_error_result_is_structured(): void {
		$error = Static_Site_Importer_Validation_Runtime::validate_artifact(
			array(
				'slug' => 'missing-artifact',
				'name' => 'Missing Artifact',
			)
		);

		$this->assertWPError( $error );
		$result = Static_Site_Importer_Validation_Runtime::error_result_from_wp_error(
			$error,
			array(
				'slug' => 'missing-artifact',
				'name' => 'Missing Artifact',
			)
		);

		$this->assertSame( 'static-site-importer/import-validation-result/v1', $result['schema'] ?? '' );
		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'missing-artifact', $result['fixture_diagnostics']['fixture']['slug'] ?? '' );
		$this->assertSame( 'validation_error', $result['fixture_diagnostics']['diagnostics'][0]['type'] ?? '' );
		$this->assertSame( 'static_site_importer_validation_artifact_missing', $result['fixture_diagnostics']['diagnostics'][0]['code'] ?? '' );
	}

	/**
	 * Assert that a report value does not expose local filesystem paths.
	 *
	 * @param mixed $value Report value.
	 */
	private function assertReportValueHasNoLocalPath( mixed $value ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->assertReportValueHasNoLocalPath( $item );
			}

			return;
		}

		if ( ! is_string( $value ) ) {
			return;
		}

		$this->assertDoesNotMatchRegularExpression( '#^(?:/|[A-Za-z]:\\\\|file://|~[/\\\\]|(?:\.\.?[/\\\\]))#', $value );
	}

	/**
	 * Generated core/html findings identify the generated document and block path.
	 */
	public function test_generated_core_html_diagnostic_includes_actionable_routing_metadata(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

		$report_property = $reflection->getProperty( 'conversion_report' );
		$report_property->setAccessible( true );
		$report_property->setValue( null, Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' ) );

		$analyze = $reflection->getMethod( 'analyze_generated_theme_block_documents' );
		$analyze->setAccessible( true );
		$analyze->invoke(
			null,
			array(
				'/tmp/generated/templates/front-page.html' => '<!-- wp:html --><aside class="widget-card"><iframe src="https://example.com/widget"></iframe></aside><!-- /wp:html -->',
			),
			'/tmp/generated'
		);

		$report      = $report_property->getValue();
		$this->assertSame( 'static-site-importer/import-report/v1', $report['schema'] ?? '' );
		$diagnostics = array_values(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'core_html_block' === ( $diagnostic['type'] ?? '' )
			)
		);

		$this->assertNotEmpty( $diagnostics );
		$this->assertSame( 'templates/front-page.html', $diagnostics[0]['source'] ?? '' );
		$this->assertSame( 'aside.widget-card', $diagnostics[0]['selector'] ?? '' );
		$this->assertSame( 'core/html', $diagnostics[0]['block_name'] ?? '' );
		$this->assertSame( 'blocks-engine/php-transformer', $diagnostics[0]['engine'] ?? '' );
		$this->assertSame( 'generated_theme_block_analysis', $diagnostics[0]['stage'] ?? '' );
		$this->assertSame( 'generated_document_contains_core_html', $diagnostics[0]['reason'] ?? '' );
		$this->assertSame( '0', $diagnostics[0]['block_path'] ?? '' );
		$this->assertStringContainsString( '<aside', $diagnostics[0]['source_html_preview'] ?? '' );
	}

	/**
	 * Generated freeform findings normalize to repair-loop diagnostic fields.
	 */
	public function test_generated_freeform_diagnostic_includes_machine_actionable_shape(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

		$report_property = $reflection->getProperty( 'conversion_report' );
		$report_property->setAccessible( true );
		$report_property->setValue( null, Static_Site_Importer_Report_Diagnostics::new_conversion_report( '/tmp/source/index.html' ) );

		$analyze = $reflection->getMethod( 'analyze_generated_theme_block_documents' );
		$analyze->setAccessible( true );
		$analyze->invoke(
			null,
			array(
				'/tmp/generated/templates/page.html' => '<!-- wp:freeform --><div class="unsupported-widget"><marquee>Sale</marquee></div><!-- /wp:freeform -->',
			),
			'/tmp/generated'
		);

		$report      = $report_property->getValue();
		Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
		$diagnostics = array_values(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'freeform_block' === ( $diagnostic['type'] ?? '' )
			)
		);

		$this->assertNotEmpty( $diagnostics );
		$this->assertStringStartsWith( 'diag-', $diagnostics[0]['id'] ?? '' );
		$this->assertSame( 'warning', $diagnostics[0]['severity'] ?? '' );
		$this->assertSame( 'fallback_block', $diagnostics[0]['category'] ?? '' );
		$this->assertSame( 'generated_document_contains_core_freeform', $diagnostics[0]['reason_code'] ?? '' );
		$this->assertSame( 'replace_fallback_block', $diagnostics[0]['suggested_repair_class'] ?? '' );
		$this->assertSame( 'templates/page.html', $diagnostics[0]['source_path'] ?? '' );
		$this->assertSame( 'div.unsupported-widget', $diagnostics[0]['selector'] ?? '' );
		$this->assertSame( '0', $diagnostics[0]['context']['block_path'] ?? '' );
	}
}
