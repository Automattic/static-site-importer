<?php
/**
 * Tests the import-report source-region-selection diagnostics.
 *
 * @package StaticSiteImporter
 */

/**
 * Verifies that import-report.json records page-body extraction decisions
 * and flags meaningful source regions that were not assigned to a generated
 * theme part or page pattern.
 */
class StaticSiteImporterSourceRegionReportTest extends WP_UnitTestCase {

	/**
	 * Reports dropped pre-main chrome wrappers with selectors, line ranges, and excerpts.
	 */
	public function test_pre_main_wrapper_chrome_is_reported_as_unassigned(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/dropped-pre-main-chrome/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Dropped Pre-Main Chrome',
				'slug'        => 'dropped-pre-main-chrome',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertFileExists( $result['report_path'] );

		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertIsArray( $report );

		$this->assertArrayHasKey( 'source_region_selection', $report, 'report should expose a source_region_selection section' );
		$selection = $report['source_region_selection'];
		$this->assertIsArray( $selection );

		// Page body decision.
		$this->assertSame( $fixture, $selection['entry_file'] ?? '', 'entry_file should match the imported HTML path' );
		$this->assertArrayHasKey( 'page_body', $selection );
		$this->assertSame( 'semantic_main', $selection['page_body']['mode'] ?? '' );
		$this->assertSame( 'main', $selection['page_body']['tag'] ?? '' );
		$this->assertStringContainsString( 'main.page', (string) ( $selection['page_body']['selector'] ?? '' ) );
		$this->assertIsArray( $selection['page_body']['line_range'] ?? null );
		$this->assertCount( 2, $selection['page_body']['line_range'] );
		$this->assertGreaterThan( 0, $selection['page_body']['line_range'][0] );
		$this->assertGreaterThanOrEqual(
			$selection['page_body']['line_range'][0],
			$selection['page_body']['line_range'][1]
		);

		// Footer was extracted as theme chrome.
		$this->assertIsArray( $selection['extracted_footer'] ?? null );
		$this->assertSame( 'footer', $selection['extracted_footer']['tag'] ?? '' );

		// No global header/nav was selected — both live inside the dropped
		// pre-main wrapper, so the report flags them as unassigned.
		$unassigned = $selection['unassigned_regions'] ?? array();
		$this->assertIsArray( $unassigned );
		$this->assertNotEmpty( $unassigned, 'pre-main wrapper containing nav and hero should be reported as unassigned' );

		// Locate the wrapper entry.
		$wrapper = null;
		$nav     = null;
		$hero    = null;
		foreach ( $unassigned as $region ) {
			$selector = (string) ( $region['selector'] ?? '' );
			if ( 'unassigned_body_child' === ( $region['role'] ?? '' ) && str_contains( $selector, 'div.page' ) ) {
				$wrapper = $region;
			}
			if ( ( $region['tag'] ?? '' ) === 'nav' ) {
				$nav = $region;
			}
			if ( ( $region['tag'] ?? '' ) === 'header' ) {
				$hero = $region;
			}
		}

		$this->assertNotNull( $wrapper, 'wrapper div.page should be reported as unassigned_body_child' );
		$this->assertSame( 'before_main', $wrapper['position'] ?? '' );
		$this->assertSame( 'pre_main_or_post_main_sibling_not_assigned', $wrapper['reason'] ?? '' );
		$this->assertIsArray( $wrapper['line_range'] ?? null );
		$this->assertNotEmpty( $wrapper['excerpt'] ?? '' );

		$this->assertNotNull( $nav, 'nested nav inside the wrapper should be reported' );
		$this->assertSame( 'unassigned_nested_landmark', $nav['role'] ?? '' );
		$this->assertStringContainsString( 'nav.nav', (string) ( $nav['selector'] ?? '' ) );

		$this->assertNotNull( $hero, 'nested hero header inside the wrapper should be reported' );
		$this->assertSame( 'unassigned_nested_landmark', $hero['role'] ?? '' );
		$this->assertStringContainsString( 'header', (string) ( $hero['selector'] ?? '' ) );
		$this->assertStringContainsString( '#top', (string) ( $hero['selector'] ?? '' ) );

		// Counts surface source landmark presence at a glance.
		$counts = $selection['counts'] ?? array();
		$this->assertSame( 1, $counts['source_landmarks']['main'] ?? 0 );
		$this->assertGreaterThanOrEqual( 1, $counts['source_landmarks']['nav'] ?? 0 );
		$this->assertGreaterThanOrEqual( 1, $counts['source_landmarks']['header'] ?? 0 );
		$this->assertGreaterThanOrEqual( 1, $counts['source_landmarks']['footer'] ?? 0 );
		$this->assertSame( count( $unassigned ), $counts['unassigned_regions'] ?? -1 );

		// A diagnostics entry mirrors each unassigned region for tooling that
		// scans the diagnostics list.
		$diagnostics = $report['diagnostics'] ?? array();
		$this->assertIsArray( $diagnostics );
		$source_region_diagnostics = array_values(
			array_filter(
				$diagnostics,
				static fn ( $entry ): bool => is_array( $entry ) && 'source_region_unassigned' === ( $entry['type'] ?? '' )
			)
		);
		$this->assertNotEmpty( $source_region_diagnostics );
		$this->assertSame( count( $unassigned ), count( $source_region_diagnostics ) );
		$this->assertNotEmpty( $source_region_diagnostics[0]['selector'] ?? '' );
	}

	/**
	 * Reports a sane page_body decision when the entry document already
	 * uses a clean header/main/footer landmark layout (no unassigned regions).
	 */
	public function test_clean_landmark_layout_has_no_unassigned_regions(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/hero-id-chrome/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Source Region Clean Layout',
				'slug'        => 'source-region-clean-layout',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertIsArray( $report );

		$selection = $report['source_region_selection'] ?? array();
		$this->assertSame( 'semantic_main', $selection['page_body']['mode'] ?? '' );
		$this->assertSame( array(), $selection['unassigned_regions'] ?? array( 'not-set' ) );
		$this->assertSame( 0, $selection['counts']['unassigned_regions'] ?? -1 );
		$this->assertSame( 1, $selection['counts']['intentionally_ignored_regions'] ?? -1 );
		$this->assertSame( 'accessibility_skip_link', $selection['intentionally_ignored_regions'][0]['role'] ?? '' );
		$this->assertStringContainsString( 'a.skip-link', (string) ( $selection['intentionally_ignored_regions'][0]['selector'] ?? '' ) );
		$this->assertSame( 'Skip to content', $selection['intentionally_ignored_regions'][0]['excerpt'] ?? '' );
		$this->assertIsArray( $selection['extracted_header'] ?? null );
		$this->assertNotEmpty( $selection['extracted_header']['parts'] ?? array() );
	}

	/**
	 * Reads a generated file.
	 */
	private function read_file( string $path ): string {
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents, 'Unable to read ' . $path );

		return (string) $contents;
	}
}
