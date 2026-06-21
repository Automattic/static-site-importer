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
	 * Import validation and finding packet artifacts expose repair-loop contracts.
	 */
	public function test_import_validation_result_and_finding_packets_are_machine_readable(): void {
		$report = array(
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
	 * Artifact diagnostics use the WP Codebox normalization contract.
	 */
	public function test_wp_codebox_artifact_diagnostics_normalizer_accepts_import_report_shape(): void {
		$diagnostics = Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer::build_for_import_report(
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
						'error_message' => 'Asset file is missing.',
						'source_path'   => 'assets/hero.jpg',
					),
				),
			)
		);

		$this->assertSame( 'wp-codebox/artifact-diagnostics/v1', $diagnostics['schema'] ?? '' );
		$this->assertSame( 'reported', $diagnostics['status'] ?? '' );
		$this->assertSame( array( 'total' => 2, 'error' => 0, 'warning' => 1, 'notice' => 1, 'info' => 0 ), $diagnostics['summary'] ?? array() );
		$this->assertSame( 'diag-001', $diagnostics['diagnostics'][0]['id'] ?? '' );
		$this->assertSame( 'unsupported_html_fallback', $diagnostics['diagnostics'][0]['type'] ?? '' );
		$this->assertSame( 'no_transform', $diagnostics['diagnostics'][0]['code'] ?? '' );
		$this->assertSame( 'blocks-engine', $diagnostics['diagnostics'][0]['source'] ?? '' );
		$this->assertSame( 'import', $diagnostics['diagnostics'][0]['stage'] ?? '' );
		$this->assertSame( 'index.html', $diagnostics['diagnostics'][0]['path'] ?? '' );
		$this->assertSame( 'iframe#store-widget', $diagnostics['diagnostics'][0]['selector'] ?? '' );
		$this->assertSame( 'blocks-engine/import-report', $diagnostics['diagnostics'][0]['provenance']['observationType'] ?? '' );
		$this->assertSame( 'import-report.json', $diagnostics['diagnostics'][0]['refs'][0]['path'] ?? '' );
		$this->assertSame( 'core/html', $diagnostics['diagnostics'][0]['details']['block_name'] ?? '' );
		$this->assertSame( 'Asset file is missing.', $diagnostics['diagnostics'][1]['message'] ?? '' );
		$this->assertSame( 'warning', $diagnostics['diagnostics'][1]['severity'] ?? '' );

		$empty = Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer::build( array( 'diagnostics' => array() ) );
		$this->assertSame( 'clean', $empty['status'] ?? '' );
		$this->assertSame( array( 'total' => 0, 'error' => 0, 'warning' => 0, 'notice' => 0, 'info' => 0 ), $empty['summary'] ?? array() );
		$this->assertSame( array(), $empty['diagnostics'] ?? null );
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
				'/tmp/generated/templates/page.html' => '<!-- wp:freeform --><div class="legacy-widget"><marquee>Sale</marquee></div><!-- /wp:freeform -->',
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
		$this->assertSame( 'div.legacy-widget', $diagnostics[0]['selector'] ?? '' );
		$this->assertSame( '0', $diagnostics[0]['context']['block_path'] ?? '' );
	}
}
