<?php
/**
 * Tests fallback/core-html diagnostic routing metadata.
 *
 * @package StaticSiteImporter
 */

/**
 * Verifies fallback diagnostics carry enough detail to route upstream fixes.
 */
class StaticSiteImporterFallbackDiagnosticsTest extends WP_UnitTestCase {

	/**
	 * SSI should consume BAC/BFB diagnostics instead of lower-level h2bc hooks.
	 */
	public function test_theme_generator_does_not_register_h2bc_conversion_hooks(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php' );

		$this->assertIsString( $source );
		$this->assertStringNotContainsString( "add_action( 'html_to_blocks_", $source );
		$this->assertStringNotContainsString( "remove_action( 'html_to_blocks_", $source );
	}

	/**
	 * Unsupported fallback diagnostics include routing metadata for upstream triage.
	 */
	public function test_fallback_diagnostic_includes_actionable_routing_metadata(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );
		$method     = $reflection->getMethod( 'fallback_diagnostic_entry' );
		$method->setAccessible( true );

		$diagnostic = $method->invoke(
			null,
			'unsupported_html_fallback',
			'main:index.html',
			'<iframe id="store-widget" class="embedded checkout" src="https://example.com/widget"></iframe>',
			array(
				'reason'     => 'no_transform',
				'tag_name'   => 'IFRAME',
				'occurrence' => 0,
			),
			array( 'blockName' => 'core/html' )
		);

		$this->assertSame( 'unsupported_html_fallback', $diagnostic['type'] ?? '' );
		$this->assertSame( 'iframe#store-widget.embedded.checkout', $diagnostic['selector'] ?? '' );
		$this->assertSame( 'core/html', $diagnostic['block_name'] ?? '' );
		$this->assertSame( 'html-to-blocks-converter', $diagnostic['converter'] ?? '' );
		$this->assertSame( 'html_to_blocks', $diagnostic['stage'] ?? '' );
		$this->assertSame( 'no_transform', $diagnostic['reason'] ?? '' );
		$this->assertStringContainsString( '<iframe', $diagnostic['source_html_preview'] ?? '' );
		$this->assertStringContainsString( '<iframe', $diagnostic['emitted_block_preview'] ?? '' );
		$this->assertArrayHasKey( 'excerpt', $diagnostic );
	}

	/**
	 * Import report summaries include compact machine-actionable diagnostics.
	 */
	public function test_import_report_summary_includes_compact_diagnostics(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

		$summary = $reflection->getMethod( 'import_report_summary' );
		$summary->setAccessible( true );

		$result = $summary->invoke(
			null,
			array(
				'entry_file'              => '/tmp/source/index.html',
				'version'                 => 1,
				'theme_slug'              => 'static-template-import',
				'block_artifact_compiler' => array(
					'available'        => true,
					'fragment_count'   => 0,
					'website_artifact' => array(
						'summary' => array(
							'schema'           => 'block-artifact-compiler/result/v1',
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
						'id'                     => 'diag-001-unsupported_html_fallback-no_transform-indexhtml',
						'type'                   => 'unsupported_html_fallback',
						'severity'               => 'warning',
						'category'               => 'unsupported_element',
						'reason_code'            => 'no_transform',
						'suggested_repair_class' => 'replace_unsupported_html',
						'source_path'            => 'index.html',
						'selector'               => 'iframe#store-widget.embedded.checkout',
						'source_html_preview'    => '<iframe id="store-widget" class="embedded checkout"></iframe>',
						'emitted_block_preview'  => '<!-- wp:html --><iframe id="store-widget" class="embedded checkout"></iframe><!-- /wp:html -->',
						'block_name'             => 'core/html',
						'converter'              => 'html-to-blocks-converter',
						'stage'                  => 'html_to_blocks',
						'reason'                 => 'no_transform',
						'html_length'            => 64,
					),
				),
			),
			array(
				'pass'                         => false,
				'fail_import'                  => false,
				'failure_reasons'              => array( 'unsupported_html_fallback' ),
				'fallback_count'               => 1,
				'empty_conversion_count'       => 0,
				'invalid_block_document_count' => 0,
			)
		);

		$this->assertSame( 'static-site-importer/import-metrics/v1', $result['schema'] ?? '' );
		$this->assertSame( 1, $result['version'] ?? 0 );
		$this->assertSame( 1, $result['report_version'] ?? 0 );
		$this->assertSame( 'static-template-import', $result['theme_slug'] ?? '' );
		$this->assertSame( 'block-artifact-compiler/result/v1', $result['compiler']['schema'] ?? '' );
		$this->assertSame( 'success', $result['compiler']['status'] ?? '' );
		$this->assertSame( 1, $result['diagnostic_count'] ?? 0 );
		$this->assertSame( 2, $result['source_document_count'] ?? 0 );
		$this->assertSame( 1, $result['unresolved_link_count'] ?? 0 );
		$this->assertSame( 1, $result['diagnostic_summary']['warning'] ?? 0 );
		$this->assertCount( 1, $result['warning_summaries'] ?? array() );
		$this->assertSame( 'unsupported_html_fallback', $result['warning_summaries'][0]['type'] ?? '' );
		$this->assertCount( 1, $result['diagnostics'] ?? array() );
		$this->assertSame( 'diag-001-unsupported_html_fallback-no_transform-indexhtml', $result['diagnostics'][0]['id'] ?? '' );
		$this->assertSame( 'index.html', $result['diagnostics'][0]['source_path'] ?? '' );
		$this->assertSame( 'iframe#store-widget.embedded.checkout', $result['diagnostics'][0]['selector'] ?? '' );
		$this->assertStringContainsString( '<iframe', $result['diagnostics'][0]['source_html_preview'] ?? '' );
		$this->assertStringContainsString( '<!-- wp:html -->', $result['diagnostics'][0]['emitted_block_preview'] ?? '' );
		$this->assertArrayNotHasKey( 'html_length', $result['diagnostics'][0] ?? array() );
	}

	/**
	 * Artifact diagnostics use the WP Codebox normalization contract.
	 */
	public function test_wp_codebox_artifact_diagnostics_normalizer_accepts_import_report_shape(): void {
		$diagnostics = Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer::build(
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
			),
			array(
				'source'          => 'static-site-importer',
				'stage'           => 'import',
				'observationType' => 'static-site-importer/import-report',
				'refs'            => array(
					array(
						'path' => 'import-report.json',
						'kind' => 'static-site-importer/import-report',
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
		$this->assertSame( 'static-site-importer', $diagnostics['diagnostics'][0]['source'] ?? '' );
		$this->assertSame( 'import', $diagnostics['diagnostics'][0]['stage'] ?? '' );
		$this->assertSame( 'index.html', $diagnostics['diagnostics'][0]['path'] ?? '' );
		$this->assertSame( 'iframe#store-widget', $diagnostics['diagnostics'][0]['selector'] ?? '' );
		$this->assertSame( 'static-site-importer/import-report', $diagnostics['diagnostics'][0]['provenance']['observationType'] ?? '' );
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

		$new_report = $reflection->getMethod( 'new_conversion_report' );
		$new_report->setAccessible( true );

		$report_property = $reflection->getProperty( 'conversion_report' );
		$report_property->setAccessible( true );
		$report_property->setValue( null, $new_report->invoke( null, '/tmp/source/index.html' ) );

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
		$this->assertSame( 'html-to-blocks-converter', $diagnostics[0]['converter'] ?? '' );
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

		$new_report = $reflection->getMethod( 'new_conversion_report' );
		$new_report->setAccessible( true );

		$report_property = $reflection->getProperty( 'conversion_report' );
		$report_property->setAccessible( true );
		$report_property->setValue( null, $new_report->invoke( null, '/tmp/source/index.html' ) );

		$analyze = $reflection->getMethod( 'analyze_generated_theme_block_documents' );
		$analyze->setAccessible( true );
		$analyze->invoke(
			null,
			array(
				'/tmp/generated/templates/page.html' => '<!-- wp:freeform --><div class="legacy-widget"><marquee>Sale</marquee></div><!-- /wp:freeform -->',
			),
			'/tmp/generated'
		);

		$normalize = $reflection->getMethod( 'normalize_import_diagnostics' );
		$normalize->setAccessible( true );
		$normalize->invoke( null );

		$report      = $report_property->getValue();
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
