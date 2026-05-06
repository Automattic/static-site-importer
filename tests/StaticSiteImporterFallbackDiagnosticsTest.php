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
		$this->assertArrayHasKey( 'excerpt', $diagnostic );
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
}
