<?php
/**
 * End-to-end fixture tests for static site import.
 *
 * @package StaticSiteImporter
 */

/**
 * Tests the generated block-theme output for the bundled fixture.
 */
class StaticSiteImporterFixtureTest extends WP_UnitTestCase {

	/**
	 * Imports the full fixture as a block theme and verifies the generated site shape.
	 */
	public function test_wordpress_is_dead_fixture_imports_as_block_theme(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture_dir = $plugin_root . '/tests/fixtures/wordpress-is-dead';
		$fixture     = $fixture_dir . '/index.html';

		foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html', 'styles.css' ) as $file ) {
			$this->assertFileExists( $fixture_dir . '/' . $file, 'Fixture file missing: ' . $file );
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'WordPress Is Dead Fixture',
				'slug'        => 'wordpress-is-dead-fixture',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
				'report'      => trailingslashit( get_temp_dir() ) . 'static-site-importer-fixture-report.json',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir  = $result['theme_dir'];
		$front_page = $this->read_file( $theme_dir . '/templates/front-page.html' );
		$page       = $this->read_file( $theme_dir . '/templates/page.html' );
		$header     = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer     = $this->read_file( $theme_dir . '/parts/footer.html' );
		$style        = $this->read_file( $theme_dir . '/style.css' );
		$editor_style = $this->read_file( $theme_dir . '/assets/css/editor-style.css' );
		$functions    = $this->read_file( $theme_dir . '/functions.php' );
		$theme_json   = json_decode( $this->read_file( $theme_dir . '/theme.json' ), true );
		$home_tmpl    = $this->read_file( $theme_dir . '/templates/page-home.html' );
		$proof_tmpl   = $this->read_file( $theme_dir . '/templates/page-proof.html' );
		$home_pat     = $this->read_file( $theme_dir . '/patterns/page-home.php' );
		$proof_pat    = $this->read_file( $theme_dir . '/patterns/page-proof.php' );

		$this->assertStringContainsString( '<!-- wp:post-content', $front_page );
		$this->assertStringContainsString( '<!-- wp:post-content {"tagName":"main"} /-->', $front_page );
		$this->assertStringNotContainsString( '<!-- wp:pattern', $front_page );
		$this->assertStringContainsString( '<!-- wp:post-content', $page );
		$this->assertStringContainsString( '<!-- wp:post-content {"tagName":"main"} /-->', $page );
		$this->assertStringContainsString( '<!-- wp:post-content', $home_tmpl );
		$this->assertStringContainsString( '<!-- wp:post-content {"tagName":"main"} /-->', $home_tmpl );
		$this->assertStringContainsString( '<!-- wp:post-content', $proof_tmpl );
		$this->assertStringContainsString( '<!-- wp:post-content {"tagName":"main"} /-->', $proof_tmpl );
		$this->assertStringNotContainsString( '<!-- wp:pattern', $home_tmpl );
		$this->assertStringNotContainsString( '<!-- wp:pattern', $proof_tmpl );
		$this->assertStringContainsString( 'Slug: wordpress-is-dead-fixture/page-home', $home_pat );
		$this->assertStringContainsString( 'Slug: wordpress-is-dead-fixture/page-proof', $proof_pat );
		$this->assertStringNotContainsString( '<!-- wp:html /-->', $front_page );
		$this->assertSame( '0', $theme_json['styles']['spacing']['blockGap'] ?? null );

		$this->assertStringContainsString( 'WordPress', $header );
		$this->assertStringNotContainsString( 'href="index.html"', $header );
		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringContainsString( '"ref":', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation-link ', $header );

		$header_nav = get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' );
		$footer_nav = get_page_by_path( 'wordpress-is-dead-fixture-footer-navigation', OBJECT, 'wp_navigation' );
		$this->assertInstanceOf( WP_Post::class, $header_nav );
		$this->assertInstanceOf( WP_Post::class, $footer_nav );
		$this->assertStringContainsString( '"ref":' . $header_nav->ID, $header );
		$this->assertStringContainsString( '<!-- wp:navigation-link ', $header_nav->post_content );
		$this->assertSame( 1, substr_count( $header_nav->post_content, '"label":"Manifesto"' ) );
		$this->assertStringContainsString( '"ref":' . $footer_nav->ID, $footer );
		$this->assertStringContainsString( '<!-- wp:navigation-link ', $footer_nav->post_content );

		$this->assertStringContainsString( '--accent', $style );
		$this->assertStringContainsString( '.wp-block-button.btn > .wp-block-button__link', $style );
		$this->assertStringContainsString( '.wp-block-post-content.is-layout-flow > *', $style );
		$this->assertStringContainsString( '.wp-block-group.is-layout-flow > *', $style );
		$this->assertStringContainsString( '.wp-block-group.is-vertical > * { margin-block-start: 0; margin-block-end: 0; }', $style );
		$this->assertStringContainsString( '.wp-block-group.is-layout-flex,', $style );
		$this->assertStringContainsString( '.wp-block-group.is-vertical { gap: 0; }', $style );
		$this->assertStringContainsString( '.wp-block-post-content.is-layout-flow > *', $editor_style );
		$this->assertStringContainsString( '.wp-block-group.is-layout-flow > *', $editor_style );
		$this->assertStringContainsString( '.wp-block-group.is-vertical > * { margin-block-start: 0; margin-block-end: 0; }', $editor_style );
		$this->assertStringContainsString( '.wp-block-group.is-layout-flex,', $editor_style );
		$this->assertStringContainsString( '.wp-block-group.is-vertical { gap: 0; }', $editor_style );
		$this->assertStringContainsString( "add_theme_support( 'editor-styles' )", $functions );
		$this->assertStringContainsString( "add_editor_style( 'assets/css/editor-style.css' )", $functions );
		$this->assertStringContainsString( "add_action( 'enqueue_block_editor_assets'", $functions );
		$this->assertStringContainsString( "get_template_directory_uri() . '/assets/css/editor-style.css'", $functions );
		$this->assertIsArray( $theme_json );

		$palette = array();
		foreach ( $theme_json['settings']['color']['palette'] ?? array() as $color ) {
			if ( isset( $color['slug'] ) ) {
				$palette[ $color['slug'] ] = $color;
			}
		}
		$this->assertSame( '#0a0a0a', $palette['bg']['color'] ?? '' );
		$this->assertSame( '#f4f4f0', $palette['fg']['color'] ?? '' );
		$this->assertSame( '#ff3b1f', $palette['accent']['color'] ?? '' );
		$this->assertArrayNotHasKey( 'max', $palette );

		$this->assertArrayHasKey( 'index.html', $result['pages'] );
		$this->assertArrayHasKey( 'manifesto.html', $result['pages'] );
		$this->assertArrayHasKey( 'comparison.html', $result['pages'] );
		$this->assertArrayHasKey( 'eulogy.html', $result['pages'] );
		$this->assertArrayHasKey( 'proof.html', $result['pages'] );
		$this->assertNotEmpty( $result['report_path'] );
		$this->assertFileExists( $result['report_path'] );
		$this->assertSame( trailingslashit( get_temp_dir() ) . 'static-site-importer-fixture-report.json', $result['external_report_path'] );
		$this->assertFileExists( $result['external_report_path'] );

		$report          = json_decode( $this->read_file( $result['report_path'] ), true );
		$external_report = json_decode( $this->read_file( $result['external_report_path'] ), true );
		$this->assertIsArray( $report );
		$this->assertSame( $report, $external_report );
		$this->assertSame( 1, $report['version'] ?? 0 );
		$this->assertArrayHasKey( 'quality', $report );
		$this->assertArrayHasKey( 'conversion_fragments', $report );
		$this->assertArrayHasKey( 'generated_theme', $report );
		$this->assertArrayHasKey( 'semantic_fidelity', $report );
		$this->assertArrayHasKey( 'product_seeding', $report );
		$this->assertArrayHasKey( 'diagnostics', $report );
		$this->assertArrayNotHasKey( 'commerce', $report );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_document_count'] ?? null );
		$this->assertSame( 'skipped', $report['product_seeding']['status'] ?? '' );
		$this->assertSame( 'no_validated_manifest', $report['product_seeding']['reason'] ?? '' );
		$this->assertSame( array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0 ), $report['product_seeding']['counts'] ?? array() );
		$this->assertNotEmpty( $report['generated_theme']['block_documents'] ?? array() );
		$this->assertSame( 'requires_external_render_check', $report['semantic_fidelity']['status'] ?? '' );
		$this->assertSame( 'benchmark_harness', $report['semantic_fidelity']['gate_owner'] ?? '' );
		$semantic_targets = $report['semantic_fidelity']['comparison_targets'] ?? array();
		$this->assertIsArray( $semantic_targets );
		$this->assertNotEmpty( $semantic_targets );
		$home_semantic_target = array_values(
			array_filter(
				$semantic_targets,
				static fn ( $target ): bool => is_array( $target ) && 'index.html' === ( $target['source_filename'] ?? '' )
			)
		)[0] ?? array();
		$this->assertNotEmpty( $home_semantic_target );
		$this->assertStringEndsWith( '/index.html', (string) ( $home_semantic_target['source_file'] ?? '' ) );
		$this->assertSame( 'templates/page-home.html', $home_semantic_target['generated_template'] ?? '' );
		$this->assertSame( 'patterns/page-home.php', $home_semantic_target['generated_pattern'] ?? '' );
		$this->assertContains( 'parts/header.html', $home_semantic_target['generated_theme_parts'] ?? array() );
		$this->assertContains( 'parts/footer.html', $home_semantic_target['generated_theme_parts'] ?? array() );
		$this->assertContains( 'header', $home_semantic_target['regions'] ?? array() );
		$this->assertContains( 'nav', $home_semantic_target['regions'] ?? array() );
		$this->assertContains( 'main', $home_semantic_target['regions'] ?? array() );
		$this->assertContains( 'footer', $home_semantic_target['regions'] ?? array() );
		$this->assertContains( '[class*=brand]', $home_semantic_target['semantic_selectors'] ?? array() );
		$this->assertContains( '[class*=logo]', $home_semantic_target['semantic_selectors'] ?? array() );
		$this->assertContains( '[class*=wordmark]', $home_semantic_target['semantic_selectors'] ?? array() );
		$this->assertContains( '[class*=nav]', $home_semantic_target['semantic_selectors'] ?? array() );
		$this->assertContains( '[class*=cta]', $home_semantic_target['semantic_selectors'] ?? array() );
		$this->assertContains( '[class*=card]', $home_semantic_target['semantic_selectors'] ?? array() );
		$this->assertContains( '[class*=status]', $home_semantic_target['semantic_selectors'] ?? array() );

		$pages = array();
		foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html' ) as $filename ) {
			$post = get_post( $result['pages'][ $filename ] ?? 0 );
			$this->assertInstanceOf( WP_Post::class, $post, 'Missing imported page post for ' . $filename );
			$slug         = 'index.html' === $filename ? 'home' : preg_replace( '/\.html?$/i', '', $filename );
			$pattern_body = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-' . $slug . '.php' ) );
			$this->assertStringNotContainsString( 'Imported page layout lives in this page', $post->post_content );
			$this->assertTrue( $this->contains_selector( $post->post_content, '.hero' ) );
			$this->assertNotEmpty( parse_blocks( $post->post_content ), 'Page block parse failed for ' . $filename );
			$this->assertNotEmpty( parse_blocks( $pattern_body ), 'Pattern snapshot block parse failed for ' . $filename );
			$this->assertSame( trim( $pattern_body ), trim( $post->post_content ), 'Pattern snapshot should match page content for ' . $filename );
			$pages[ $filename ] = array(
				'stored'   => $post->post_content,
				'rendered' => do_blocks( $post->post_content ),
			);
		}

		$previous_theme = get_stylesheet();
		switch_theme( $result['theme_slug'] );
		$home_post         = get_post( $result['pages']['index.html'] );
		$frontend_rendered = $home_post instanceof WP_Post ? $this->render_template_for_post( $front_page, $home_post ) : '';
		switch_theme( $previous_theme );
		$this->assertStringContainsString( 'WordPress', $frontend_rendered );
		$this->assertTrue( $this->contains_selector( $frontend_rendered, '.hero' ) );
		$this->assertStringContainsString( 'Prompt Liberation Front', $frontend_rendered );

		$this->assertStringNotContainsString( 'href="index.html"', $pages['proof.html']['stored'] );
		$this->assert_selector_matrix( $pages );
		$this->assertSame( 1, $this->selector_count( $pages['comparison.html']['rendered'], '.compare' ) );
		$this->assertSame( 1, $this->selector_count( $pages['comparison.html']['rendered'], '.col-wp' ) );
		$this->assertSame( 1, $this->selector_count( $pages['comparison.html']['rendered'], '.col-claude' ) );
		$this->assertSame( 1, $this->selector_count( $pages['eulogy.html']['rendered'], '.eulogy-frame' ) );
		$this->assertSame( 1, $this->selector_count( $pages['eulogy.html']['rendered'], '.dates' ) );

		$second_result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'WordPress Is Dead Fixture',
				'slug'        => 'wordpress-is-dead-fixture',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);
		$this->assertNotWPError( $second_result );
		$this->assertSame( $header_nav->ID, get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' )->ID );
		$this->assertSame( $footer_nav->ID, get_page_by_path( 'wordpress-is-dead-fixture-footer-navigation', OBJECT, 'wp_navigation' )->ID );
	}

	/**
	 * Recursive HTML discovery includes nested pages while skipping dependency and hidden directories.
	 */
	public function test_collect_pages_discovers_nested_html_sources(): void {
		$fixture_dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-nested-html-' . wp_generate_uuid4();
		$write_html  = function ( string $relative_path, string $title ) use ( $fixture_dir ): void {
			$path = trailingslashit( $fixture_dir ) . $relative_path;
			$this->assertTrue( wp_mkdir_p( dirname( $path ) ) );
			$this->assertNotFalse(
				file_put_contents(
					$path,
					'<!doctype html><html><head><title>' . esc_html( $title ) . '</title></head><body><main><h1>' . esc_html( $title ) . '</h1></main></body></html>'
				)
			);
		};

		$write_html( 'index.html', 'Home' );
		$write_html( 'about/index.html', 'About' );
		$write_html( 'docs/guide.html', 'Guide' );
		$write_html( '.hidden/secret.html', 'Hidden' );
		$write_html( '.git/config.html', 'Git' );
		$write_html( 'node_modules/pkg/index.html', 'Package' );
		$write_html( 'vendor/pkg/index.html', 'Vendor' );

		$method = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'collect_pages' );
		$method->setAccessible( true );
		$pages = $method->invoke( null, $fixture_dir );

		$this->assertIsArray( $pages );
		$this->assertSame( array( 'index.html', 'about/index.html', 'docs/guide.html' ), array_keys( $pages ) );
		$this->assertArrayNotHasKey( '.hidden/secret.html', $pages );
		$this->assertArrayNotHasKey( '.git/config.html', $pages );
		$this->assertArrayNotHasKey( 'node_modules/pkg/index.html', $pages );
		$this->assertArrayNotHasKey( 'vendor/pkg/index.html', $pages );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			trailingslashit( $fixture_dir ) . 'index.html',
			array(
				'name'        => 'Nested HTML Discovery',
				'slug'        => 'nested-html-discovery',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'index.html', 'about/index.html', 'docs/guide.html' ), array_keys( $result['pages'] ) );
		$this->assertSame( 'home', get_post_field( 'post_name', $result['pages']['index.html'] ?? 0 ) );
		$this->assertSame( 'about', get_post_field( 'post_name', $result['pages']['about/index.html'] ?? 0 ) );
		$this->assertSame( 'docs-guide', get_post_field( 'post_name', $result['pages']['docs/guide.html'] ?? 0 ) );

		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertSame( 3, (int) ( $report['source_documents']['counts_by_format']['html'] ?? 0 ) );
	}

	/**
	 * Minimal semantic hero sections import as editable blocks, not freeform fallback.
	 *
	 * Covers validation duplicates #173, #174, #176, #177, #178, and #182.
	 */
	public function test_simple_main_hero_section_imports_without_freeform_fallback(): void {
		$fixture_dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-simple-hero-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $fixture_dir ) );

		$fixture = trailingslashit( $fixture_dir ) . 'index.html';
		$wrote   = file_put_contents(
			$fixture,
			'<!doctype html><html><head><title>Simple Hero</title></head><body><main><section class="hero"><h1>Smoke Test Storefront</h1><p>Synthetic fixture for iterator smoke runs.</p></section></main></body></html>'
		);
		$this->assertNotFalse( $wrote );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Simple Hero Section',
				'slug'        => 'simple-hero-section',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$pattern = $this->read_file( $result['theme_dir'] . '/patterns/page-home.php' );
		$report  = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:group', $pattern );
		$this->assertStringContainsString( '"tagName":"section"', $pattern );
		$this->assertStringContainsString( '"className":"hero"', $pattern );
		$this->assertStringContainsString( 'Smoke Test Storefront', $pattern );
		$this->assertStringContainsString( 'Synthetic fixture for iterator smoke runs.', $pattern );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $pattern );
		$this->assertStringNotContainsString( '<!-- wp:html', $pattern );
		$this->assertSame( 0, $report['quality']['freeform_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $result['import_report_summary']['freeform_block_count'] ?? null );
		$this->assertSame( false, $result['import_report_summary']['fail_import'] ?? null );
	}

	/**
	 * Brand anchors in theme parts use the shared converter instead of freeform islands.
	 */
	public function test_theme_part_brand_anchors_import_without_freeform_blocks(): void {
		$fixture_dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-brand-anchor-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $fixture_dir ) );

		$fixture = trailingslashit( $fixture_dir ) . 'index.html';
		$wrote   = file_put_contents(
			$fixture,
			'<!doctype html><html><head><title>Brand Anchor</title></head><body>' .
			'<header class="site-header"><div class="header-inner"><a class="brand" href="#top" aria-label="Harbor &amp; Hawthorn Tattoo home"><span>Harbor &amp; Hawthorn</span><small>Tattoo Studio</small></a><nav><a href="#booking">Booking</a></nav></div></header>' .
			'<main id="top"><section id="booking" class="hero"><h1>Booking</h1><p>Converter-owned brand anchor fixture.</p></section></main>' .
			'<footer class="site-footer"><div class="footer-inner"><div><a class="brand footer-brand" href="#top"><span class="brand-mark" aria-hidden="true">W</span><span><strong>Wickstead</strong><em>Refill Works</em></span></a></div><ul><li><a href="#booking">Booking</a></li></ul></div></footer>' .
			'</body></html>'
		);
		$this->assertNotFalse( $wrote );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Brand Anchor Theme Parts',
				'slug'        => 'brand-anchor-theme-parts',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$header = $this->read_file( $result['theme_dir'] . '/parts/header.html' );
		$footer = $this->read_file( $result['theme_dir'] . '/parts/footer.html' );
		$report = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:paragraph', $header );
		$this->assertStringContainsString( 'Harbor &amp; Hawthorn', $header );
		$this->assertStringContainsString( 'aria-label="Harbor &amp; Hawthorn Tattoo home"', $header );
		$this->assertStringContainsString( '<!-- wp:paragraph', $footer );
		$this->assertStringContainsString( '<strong>Wickstead</strong>', $footer );
		$this->assertStringContainsString( '<em>Refill Works</em>', $footer );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $header );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $footer );
		$this->assertSame( 0, $report['quality']['freeform_block_count'] ?? null );
		$this->assertSame( 0, $result['import_report_summary']['freeform_block_count'] ?? null );
	}

	/**
	 * Local images referenced from theme parts remain renderable after extraction.
	 */
	public function test_theme_part_local_svg_image_is_materialized(): void {
		$fixture_dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-theme-part-image-' . wp_generate_uuid4();
		$assets_dir  = trailingslashit( $fixture_dir ) . 'assets';
		$this->assertTrue( wp_mkdir_p( $assets_dir ) );

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" role="img"><title>Studio mark</title><circle cx="60" cy="60" r="48" fill="#f4efe6" stroke="#0d0c0b" stroke-width="4"/></svg>';
		$this->assertNotFalse( file_put_contents( trailingslashit( $assets_dir ) . 'studio-mark.svg', $svg ) );

		$fixture = trailingslashit( $fixture_dir ) . 'index.html';
		$wrote   = file_put_contents(
			$fixture,
			'<!doctype html><html><head><title>Theme Part Image</title></head><body>' .
			'<header class="site-header"><div class="brand-lockup"><img src="assets/studio-mark.svg" alt="" width="52" height="52"><a class="brand" href="#top">Harbor &amp; Hawthorn</a></div></header>' .
			'<main id="top"><h1>Theme part image</h1><p>Image asset fixture.</p></main>' .
			'</body></html>'
		);
		$this->assertNotFalse( $wrote );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Theme Part Image',
				'slug'        => 'theme-part-image',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$header = $this->read_file( $result['theme_dir'] . '/parts/header.html' );
		$report = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:image ', $header );
		$this->assertStringContainsString( '/assets/media/studio-mark-', $header );
		$this->assertStringNotContainsString( 'src="assets/studio-mark.svg"', $header );
		$this->assertNotEmpty( glob( $result['theme_dir'] . '/assets/media/studio-mark-*.svg' ) );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['freeform_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['unsafe_svg_count'] ?? null );
	}

	/**
	 * A generated store fixture records valid products.json metadata in the import report only.
	 *
	 * When WooCommerce is active, the import succeeds and seeds products. When WooCommerce is
	 * missing, the import is hard-failed by the dependency gate unless the caller waives it.
	 */
	public function test_products_manifest_fixture_records_valid_contract_metadata(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/generated-store-valid/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'                      => 'Generated Store Valid',
				'slug'                      => 'generated-store-valid',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'                => true,
				// Waive WC so this manifest-shape test stays focused on contract metadata.
				// Hard-fail behavior is covered by test_commerce_intent_with_missing_woocommerce_fails_import.
				'allow_missing_woocommerce' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report   = json_decode( $this->read_file( $result['report_path'] ), true );
		$manifest = $report['commerce']['products_manifest'] ?? array();

		$this->assertIsArray( $report );
		$this->assertSame( true, $manifest['present'] ?? null );
		$this->assertSame( true, $manifest['valid'] ?? null );
		$this->assertSame( 'static-site-importer/products.json', $manifest['contract']['schema'] ?? '' );
		$this->assertSame( 1, $manifest['contract']['schema_version'] ?? null );
		$this->assertSame( array( 'name', 'slug', 'regular_price' ), $manifest['contract']['required_fields'] ?? array() );
		$this->assertSame( 2, $manifest['product_count'] ?? null );
		$this->assertSame( array(), $manifest['errors'] ?? null );
		$this->assertSame( 'Signal Hoodie', $manifest['products'][0]['name'] ?? '' );
		$this->assertSame( 'signal-hoodie', $manifest['products'][0]['slug'] ?? '' );
		$this->assertSame( '64.00', $manifest['products'][0]['regular_price'] ?? '' );
		$this->assertSame( '54.00', $manifest['products'][0]['sale_price'] ?? '' );
		$this->assertSame( array( 'Apparel' ), $manifest['products'][0]['categories'] ?? array() );
		$this->assertSame( 'assets/signal-hoodie.jpg', $manifest['products'][0]['image'] ?? '' );

		// Dependency gate is recorded for commerce-bearing imports regardless of WC status.
		$dependencies = $report['commerce']['dependencies']['woocommerce'] ?? array();
		$this->assertSame( true, $dependencies['required'] ?? null );
		$this->assertSame( class_exists( 'WC_Product_Simple' ), $dependencies['active'] ?? null );
		$this->assertContains( 'products_manifest', $dependencies['sources'] ?? array() );
		$this->assertSame( 2, $dependencies['product_count'] ?? null );

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			// Waived path: import succeeds, products are not seeded, waiver diagnostic is recorded.
			$this->assertNotContains( 'woocommerce_missing', $report['quality']['failure_reasons'] ?? array() );
			$this->assertEmpty( $report['quality']['fail_import'] ?? null );
			$this->assertSame( true, $dependencies['waived'] ?? null );
			$this->assertSame( 'skipped', $report['product_seeding']['status'] ?? '' );
			$this->assertSame( 'woocommerce_inactive', $report['product_seeding']['reason'] ?? '' );
			$this->assertSame( array( 'created' => 0, 'updated' => 0, 'skipped' => 2, 'error' => 0 ), $report['product_seeding']['counts'] ?? array() );
			$this->assertSame( 'signal-hoodie', $report['product_seeding']['products'][0]['slug'] ?? '' );

			$waiver_diagnostics = array_values(
				array_filter(
					$report['diagnostics'] ?? array(),
					static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'woocommerce_waived' === ( $diagnostic['code'] ?? '' )
				)
			);
			$this->assertNotEmpty( $waiver_diagnostics );
			$this->assertSame( 'warning', $waiver_diagnostics[0]['severity'] ?? '' );
		}
	}

	/**
	 * Commerce-bearing imports without WooCommerce hard-fail by default.
	 *
	 * Theme files are still written so callers can inspect the import report; the gate
	 * trips fail_import even without --fail-on-quality so silent half-commerce never ships.
	 */
	public function test_commerce_intent_with_missing_woocommerce_fails_import(): void {
		if ( class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'Requires a runtime without WooCommerce active.' );
		}

		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/generated-store-valid/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Generated Store Hard Fail',
				'slug'        => 'generated-store-hard-fail',
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
		$this->assertSame( true, $result['quality']['fail_import'] ?? null );
		$this->assertContains( 'woocommerce_missing', $result['quality']['failure_reasons'] ?? array() );
		$this->assertSame( 1, $report['quality']['commerce_dependency_failures'] ?? null );
		$this->assertSame( false, $result['quality']['pass'] ?? null );

		$dependencies = $report['commerce']['dependencies']['woocommerce'] ?? array();
		$this->assertSame( true, $dependencies['required'] ?? null );
		$this->assertSame( false, $dependencies['active'] ?? null );
		$this->assertSame( false, $dependencies['waived'] ?? null );
		$this->assertContains( 'products_manifest', $dependencies['sources'] ?? array() );
		$this->assertSame( 2, $dependencies['product_count'] ?? null );

		$diagnostics = array_values(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'woocommerce_missing' === ( $diagnostic['code'] ?? '' )
			)
		);
		$this->assertNotEmpty( $diagnostics );
		$this->assertSame( 'error', $diagnostics[0]['severity'] ?? '' );
		$this->assertSame( 2, $diagnostics[0]['product_count'] ?? null );

		$this->assertSame( 'woocommerce_required_but_missing', $report['product_seeding']['reason'] ?? '' );
	}

	/**
	 * The CLI command must exit non-zero on quality.fail_import for both human and JSON output.
	 *
	 * Regression guard: the JSON branch must not return early before checking fail_import,
	 * otherwise machine-readable consumers (wc-site-generator, CI harnesses) silently see
	 * exit 0 on commerce-bearing imports without WooCommerce.
	 *
	 * Covers report-summary validation duplicates #180 and #181.
	 */
	public function test_cli_command_halts_non_zero_when_fail_import_is_true(): void {
		$cli_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-cli-command.php' );
		$this->assertIsString( $cli_source );

		// JSON branch must call WP_CLI::halt( 1 ) when fail_import is true so the structured
		// payload is still emitted but the process exit code is non-zero.
		$this->assertMatchesRegularExpression(
			'/\$is_json[\s\S]+?WP_CLI::halt\(\s*1\s*\)/m',
			$cli_source,
			'CLI JSON branch must halt(1) on fail_import; otherwise --format=json exits 0 on hard failures.'
		);

		// Human branch must error out before the success block to avoid printing success
		// followed by an error line.
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*\$fail_import\s*\)\s*\{[\s\S]+?WP_CLI::error\([\s\S]+?WP_CLI::success\(/m',
			$cli_source,
			'CLI human branch must report fail_import via WP_CLI::error before printing the success line.'
		);
		$this->assertStringContainsString( 'import_report_summary', $cli_source, 'CLI JSON failure payloads must include import_report_summary metadata.' );
	}

	/**
	 * Non-commerce imports are unaffected by the WooCommerce dependency gate.
	 *
	 * Imports without a valid products.json and without inferred commerce context must
	 * not record a commerce.dependencies block, must not emit dependency diagnostics,
	 * and must not contribute to commerce_dependency_failures.
	 */
	public function test_non_commerce_import_is_not_affected_by_woocommerce_dependency_gate(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/wordpress-is-dead/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Non-Commerce Import Gate Check',
				'slug'        => 'non-commerce-import-gate-check',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertIsArray( $report );
		$this->assertArrayNotHasKey( 'dependencies', $report['commerce'] ?? array() );
		$this->assertSame( 0, $report['quality']['commerce_dependency_failures'] ?? null );
		$this->assertNotContains( 'woocommerce_missing', $report['quality']['failure_reasons'] ?? array() );

		$dependency_codes = array( 'woocommerce_missing', 'woocommerce_present', 'woocommerce_waived' );
		foreach ( $report['diagnostics'] ?? array() as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			$this->assertNotContains( $diagnostic['code'] ?? '', $dependency_codes );
		}

		$this->assertSame( 'no_validated_manifest', $report['product_seeding']['reason'] ?? '' );
	}

	/**
	 * Invalid generated store manifests report actionable diagnostics without aborting import.
	 */
	public function test_invalid_products_manifest_records_errors_without_blocking_import(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/generated-store-invalid/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'                      => 'Generated Store Invalid',
				'slug'                      => 'generated-store-invalid',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'               => true,
				// Invalid manifest never yields commerce intent, but be explicit so the focus stays on manifest validation.
				'allow_missing_woocommerce' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report      = json_decode( $this->read_file( $result['report_path'] ), true );
		$manifest    = $report['commerce']['products_manifest'] ?? array();
		$diagnostics = array_values(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'products_manifest_invalid' === ( $diagnostic['code'] ?? '' )
			)
		);

		$this->assertIsArray( $report );
		$this->assertSame( true, $manifest['present'] ?? null );
		$this->assertSame( false, $manifest['valid'] ?? null );
		$this->assertSame( 0, $manifest['product_count'] ?? null );
		$this->assertNotEmpty( $manifest['errors'] ?? array() );
		$this->assertSame( '$.schema_version', $manifest['errors'][0]['path'] ?? '' );
		$this->assertSame( '$.products[0].name', $manifest['errors'][1]['path'] ?? '' );
		$this->assertSame( '$.products[0].slug', $manifest['errors'][2]['path'] ?? '' );
		$this->assertSame( '$.products[0].regular_price', $manifest['errors'][3]['path'] ?? '' );
		$this->assertNotEmpty( $diagnostics );
		$this->assertSame( 'warning', $diagnostics[0]['severity'] ?? '' );
	}

	/**
	 * Generated stores without valid manifests infer WooCommerce products from visible product cards.
	 */
	public function test_static_store_product_cards_infer_commerce_context(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/generated-store-inferred/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'                      => 'Generated Store Inferred',
				'slug'                      => 'generated-store-inferred',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'               => true,
				// Inferred commerce context counts as intent; waive WC dependency for this inference test.
				'allow_missing_woocommerce' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report    = json_decode( $this->read_file( $result['report_path'] ), true );
		$inference = $report['commerce_product_inference'] ?? array();

		$this->assertIsArray( $report );
		$this->assertSame( 'html_cards', $inference['strategy'] ?? '' );
		$this->assertSame( 2, $inference['product_count'] ?? null );
		$this->assertSame( 'html_cards', $report['commerce_context']['source'] ?? '' );
		$this->assertSame( 2, $report['commerce_context']['product_count'] ?? null );
		$this->assertSame( 'Signal Hoodie', $inference['products'][0]['name'] ?? '' );
		$this->assertSame( 'signal-hoodie', $inference['products'][0]['slug'] ?? '' );
		$this->assertSame( '64.00', $inference['products'][0]['regular_price'] ?? '' );
		$this->assertSame( array( 'Field Gear' ), $inference['products'][0]['categories'] ?? array() );
		$this->assertContains( '[data-product-slug="signal-hoodie"]', array_column( $report['commerce_context']['selector_hints'] ?? array(), 'card_selector' ) );

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			$this->assertSame( 'skipped', $report['product_seeding']['status'] ?? '' );
			$this->assertSame( 'woocommerce_inactive', $report['product_seeding']['reason'] ?? '' );
			$this->assertSame( array( 'created' => 0, 'updated' => 0, 'skipped' => 2, 'error' => 0 ), $report['product_seeding']['counts'] ?? array() );
			$this->assertSame( 'field-notes', $report['product_seeding']['products'][1]['slug'] ?? '' );
		}
	}

	/**
	 * JSON-LD Product data wins over visible-card inference when no valid manifest exists.
	 */
	public function test_json_ld_product_data_infers_before_product_cards(): void {
		$fixture = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Structured Beans","url":"https://example.test/products/structured-beans","image":"assets/beans.jpg","description":"Single origin beans.","offers":{"@type":"Offer","price":"18","priceCurrency":"USD"}}</script></head><body><main><article class="product-card"><h2>Visible Beans</h2><p class="price">$22.00</p></article></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'                      => 'JSON LD Store',
				'slug'                      => 'json-ld-store',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'               => true,
				// JSON-LD inference yields commerce intent; waive WC dependency for this inference test.
				'allow_missing_woocommerce' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report    = json_decode( $this->read_file( $result['report_path'] ), true );
		$inference = $report['commerce_product_inference'] ?? array();

		$this->assertIsArray( $report );
		$this->assertSame( 'json_ld', $inference['strategy'] ?? '' );
		$this->assertSame( 1, $inference['product_count'] ?? null );
		$this->assertSame( 'json_ld', $report['commerce_context']['source'] ?? '' );
		$this->assertSame( 'Structured Beans', $inference['products'][0]['name'] ?? '' );
		$this->assertSame( 'structured-beans', $inference['products'][0]['slug'] ?? '' );
		$this->assertSame( '18.00', $inference['products'][0]['regular_price'] ?? '' );
	}

	/**
	 * Product/catalog decorative placeholders import without HTML fallbacks.
	 */
	public function test_product_catalog_decorative_placeholders_import_as_native_blocks(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture_dir = $plugin_root . '/tests/fixtures/product-catalog-decorative-placeholders';
		$fixture     = $fixture_dir . '/index.html';

		foreach ( array( 'index.html', 'styles.css' ) as $file ) {
			$this->assertFileExists( $fixture_dir . '/' . $file, 'Fixture file missing: ' . $file );
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Product Catalog Decorative Placeholders',
				'slug'        => 'product-catalog-decorative-placeholders',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-home.php' ) );
		$style     = $this->read_file( $theme_dir . '/style.css' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertIsArray( $report );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
		$this->assertSame( true, $report['quality']['pass'] ?? false );
		$this->assertEmpty( $report['quality']['failure_reasons'] ?? array() );

		$this->assertStringContainsString( 'category-image placeholder placeholder-ceramics', $pattern );
		$this->assertStringContainsString( 'gallery-gradient gradient-sunrise', $pattern );
		$this->assertStringContainsString( 'Warm serviceware stacked for brunch.', $pattern );
		$this->assertStringNotContainsString( '<!-- wp:html', $pattern );
		$this->assertStringContainsString( '.placeholder-ceramics', $style );
		$this->assertStringContainsString( '.gallery-gradient', $style );

		$home_document = null;
		foreach ( $report['generated_theme']['block_documents'] ?? array() as $document ) {
			if ( 'patterns/page-home.php' === ( $document['path'] ?? '' ) ) {
				$home_document = $document;
				break;
			}
		}

		$this->assertIsArray( $home_document );
		$this->assertSame( 0, $home_document['core_html_block_count'] ?? null );
	}

	/**
	 * Relay Atlas-style logo anchors convert without core/html islands.
	 */
	public function test_relay_atlas_logo_anchor_fixture_imports_without_html_islands(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/relay-atlas-logo-anchors/index.html';

		$this->assertFileExists( $fixture );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Relay Atlas Logo Anchors',
				'slug'        => 'relay-atlas-logo-anchors',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer    = $this->read_file( $theme_dir . '/parts/footer.html' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertIsArray( $report );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $header );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $footer );
		$this->assertStringNotContainsString( '<p><a href="#" class="logo"><div', $header );
		$this->assertStringNotContainsString( '<p><a href="#" class="footer-logo"><div', $footer );
		$this->assertStringContainsString( '<a href="#" class="logo">', $header );
		$this->assertStringNotContainsString( '<div class="wp-block-group logo">', $header );
		$this->assertStringContainsString( '<span class="logo-mark"><img', $header );
		$this->assertStringContainsString( 'class="logo-name"', $header );
		$this->assertStringContainsString( 'Relay Atlas', $header );
		$this->assertStringContainsString( '/assets/icons/', $header );
		$this->assertStringContainsString( '<a href="#" class="footer-logo">', $footer );
		$this->assertStringNotContainsString( '<div class="wp-block-group footer-logo">', $footer );
		$this->assertStringContainsString( '<span class="footer-logo-mark"><img', $footer );
		$this->assertStringContainsString( 'Relay Atlas', $footer );
		$this->assertStringContainsString( '/assets/icons/', $footer );
		$this->assertNotEmpty( $report['assets']['svg_icons'] ?? array() );
		foreach ( $report['assets']['svg_icons'] as $asset ) {
			$this->assertFileExists( $theme_dir . '/' . ( $asset['path'] ?? '' ) );
		}
		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringContainsString( '<!-- wp:navigation ', $footer );
	}

	/**
	 * Leading page navigation belongs in the shared header part; the hero stays in page content.
	 */
	public function test_leading_nav_before_header_is_preserved_in_header_part(): void {
		$html_path = $this->write_temp_fixture(
			'leading-nav-header.html',
			'<!doctype html><html><head><title>Leading Nav Header</title></head><body>' .
			'<nav><div class="nav-logo">Studio Code</div><div class="nav-badge">Early Access</div><a href="#get-started" class="nav-cta">Get Started</a></nav>' .
			'<header class="hero"><h1>Launch with Studio</h1><p>Hero copy.</p></header>' .
			'<main><section id="get-started"><h2>Get started</h2><p>Body copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Leading Nav Header',
				'slug'      => 'leading-nav-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-leading-nav-header.php' ) );

		$this->assertStringContainsString( 'Studio Code', $header );
		$this->assertStringContainsString( 'Early Access', $header );
		$this->assertStringContainsString( 'Get Started', $header );
		$this->assertStringNotContainsString( 'Launch with Studio', $header );
		$this->assertStringContainsString( 'Launch with Studio', $pattern );
		$this->assertStringContainsString( 'Hero copy.', $pattern );
	}

	/**
	 * Pre-main nav and hero header wrapped in a generic page div are preserved.
	 *
	 * Regression for issue #157: a `<body><div class="page">…nav, header…</div><main>…</main></body>`
	 * shape used to drop nav + hero entirely, leaving an empty header part and a
	 * page pattern that started at the first inner-main section. The wrapper is
	 * a generic, non-semantic container that holds pre-main chrome only and
	 * must be transparent to fragment decomposition.
	 */
	public function test_pre_main_page_wrapper_preserves_nav_and_hero_header(): void {
		$html_path = $this->write_temp_fixture(
			'studio-code-page-wrapper.html',
			'<!doctype html><html><head><title>Studio Code</title></head><body>' .
			'<div class="page">' .
			'<nav class="nav" aria-label="Primary navigation">' .
			'<a class="brand" href="#top"><span class="brand-mark"></span><span>Studio Code</span></a>' .
			'<div class="nav-links"><a href="#benefits">Benefits</a><a href="#workflow">Workflow</a></div>' .
			'</nav>' .
			'<header class="hero" id="top">' .
			'<div><span class="eyebrow">Product launch page</span><h1>Build with HTML. Ship as WordPress.</h1>' .
			'<p class="lede">Studio turns static markup into a working theme.</p>' .
			'<div class="actions"><a class="button" href="#workflow">See the workflow</a></div></div>' .
			'<aside class="console-card">Conversion console preview.</aside>' .
			'</header>' .
			'</div>' .
			'<main class="page"><section id="benefits"><h2>Benefits</h2><p>Benefit copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Studio Code Page Wrapper',
				'slug'      => 'studio-code-page-wrapper',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-studio-code-page-wrapper.php' ) );

		// Nav lands in the shared header part.
		$this->assertNotSame( '', trim( $header ), 'Header part must not be empty when pre-main nav exists.' );
		$this->assertStringContainsString( 'Studio Code', $header );
		$this->assertStringContainsString( 'Benefits', $header );
		$this->assertStringContainsString( 'Workflow', $header );

		// Hero stays with page content (not in header part).
		$this->assertStringNotContainsString( 'Build with HTML. Ship as WordPress.', $header );
		$this->assertStringNotContainsString( 'Conversion console preview.', $header );
		$this->assertStringContainsString( 'Build with HTML. Ship as WordPress.', $pattern );
		$this->assertStringContainsString( 'Studio turns static markup', $pattern );
		$this->assertStringContainsString( 'Conversion console preview.', $pattern );

		// Page body still includes the inner-main sections.
		$this->assertStringContainsString( 'Benefit copy.', $pattern );
	}

	/**
	 * Main-contained classed headers stay with page content instead of becoming site chrome.
	 */
	public function test_main_contained_site_header_stays_in_page_content(): void {
		$html_path = $this->write_temp_fixture(
			'main-contained-site-header.html',
			'<!doctype html><html><head><title>Main Contained Site Header</title></head><body>' .
			'<main id="main"><section class="hero"><header id="top" class="site-header"><nav><a href="#hours">Hours</a></nav><h1>Neighborhood Used Bookstore</h1><p>Hero copy stays in the page.</p></header></section>' .
			'<section id="hours"><h2>Open Today</h2><p>Tuesday through Friday.</p></section></main>' .
			'<footer class="site-footer"><p>Footer copy.</p></footer>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Main Contained Site Header',
				'slug'      => 'main-contained-site-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-main-contained-site-header.php' ) );

		$this->assertSame( '', trim( $header ), 'A site-header class inside main must not be extracted as reusable site chrome.' );
		$this->assertStringContainsString( 'Neighborhood Used Bookstore', $pattern );
		$this->assertStringContainsString( 'Hero copy stays in the page.', $pattern );
		$this->assertStringContainsString( 'Tuesday through Friday.', $pattern );
	}

	/**
	 * Classed theme chrome keeps source element ownership without core/html islands.
	 */
	public function test_classed_header_and_footer_chrome_preserve_source_elements(): void {
		$html_path = $this->write_temp_fixture(
			'classed-chrome.html',
			'<!doctype html><html><head><title>Classed Chrome</title></head><body>' .
			'<header><nav class="topbar"><a href="#" class="nav-logo">HOME<span>BOY</span></a><a href="#docs">Docs</a></nav></header>' .
			'<main><section><h1>Classed chrome</h1><p>Body copy.</p></section></main>' .
			'<footer><div class="footer-logo">HOMEBOY</div><p>Developer operations for engineers who ship with evidence.</p></footer>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Classed Chrome',
				'slug'      => 'classed-chrome',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer    = $this->read_file( $theme_dir . '/parts/footer.html' );

		$this->assertStringNotContainsString( '<!-- wp:html', $header );
		$this->assertStringNotContainsString( '<!-- wp:html', $footer );
		$this->assertStringContainsString( '<p><a href="#" class="nav-logo">HOME<span>BOY</span></a></p>', $header );
		$this->assertStringContainsString( '<p class="footer-logo">HOMEBOY</p>', $footer );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $header );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $footer );
	}

	/**
	 * Top-level classed inline footer text becomes an editable native block.
	 */
	public function test_classed_footer_span_logo_uses_native_paragraph(): void {
		$html_path = $this->write_temp_fixture(
			'classed-footer-span-logo.html',
			'<!doctype html><html><head><title>Classed Footer Span Logo</title></head><body>' .
			'<main><section><h1>Classed footer span logo</h1><p>Body copy.</p></section></main>' .
			'<footer class="site-footer"><span class="footer-logo">Brand</span><p class="footer-meta">Tagline</p></footer>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Classed Footer Span Logo',
				'slug'      => 'classed-footer-span-logo',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$footer    = $this->read_file( $theme_dir . '/parts/footer.html' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:paragraph {"className":"footer-logo"} --><p class="footer-logo">Brand</p><!-- /wp:paragraph -->', $footer );
		$this->assertStringContainsString( '<!-- wp:paragraph {"className":"footer-meta"} --><p class="footer-meta">Tagline</p><!-- /wp:paragraph -->', $footer );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $footer );
		$this->assertSame( 0, $report['quality']['freeform_block_count'] ?? null );
	}

	/**
	 * A top-level nav can contain brand chrome plus a classed menu list.
	 */
	public function test_branded_top_level_nav_preserves_menu_list_class_owner(): void {
		$html_path = $this->write_temp_fixture(
			'branded-nav-header.html',
			'<!doctype html><html><head><title>Branded Nav Header</title></head><body>' .
			'<nav class="nav-shell">' .
			'<a href="#" class="nav-brand"><div class="nav-logo">SC</div><span class="nav-name">Studio Code</span><span class="nav-badge">New</span></a>' .
			'<ul class="nav-links"><li><a href="#benefits">Benefits</a></li><li><a href="#workflow">How it works</a></li><li><a href="#use-cases">Use cases</a></li><li><a href="#cta" class="nav-cta">Get started</a></li></ul>' .
			'</nav>' .
			'<main><section id="benefits"><h1>Benefits</h1><p>Body copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Branded Nav Header',
				'slug'      => 'branded-nav-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );

		$this->assertStringContainsString( 'nav-brand', $header );
		$this->assertStringContainsString( 'nav-logo', $header );
		$this->assertStringContainsString( 'Studio Code', $header );
		$this->assertStringContainsString( 'New', $header );
		$this->assertStringContainsString( '<!-- wp:list {"className":"nav-links"} -->', $header );
		$this->assertStringContainsString( '<ul class="wp-block-list nav-links">', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation {"ref":', $header );
		$this->assertStringNotContainsString( '"className":"nav-links"} /-->', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation-link ', $header );
		$this->assertStringContainsString( '<a href="#benefits">Benefits</a>', $header );
		$this->assertStringContainsString( '<a href="#workflow">How it works</a>', $header );
		$this->assertStringContainsString( '<a href="#use-cases">Use cases</a>', $header );
		$this->assertStringContainsString( '<a href="#cta" class="nav-cta">Get started</a>', $header );
	}

	/**
	 * Source nav wrapper selectors keep matching when a branded nav preserves a classed list.
	 */
	public function test_branded_nav_wrapper_gets_safe_selector_parity(): void {
		$html_path = $this->write_temp_fixture(
			'rsm-nav-wrapper.html',
			'<!doctype html><html><head><title>RSM Nav Wrapper</title><style>' .
			'nav { position: fixed; top: 0; left: 0; right: 0; display: flex; justify-content: space-between; }' .
			'nav .nav-logo { font-weight: 800; }' .
			'@media (max-width: 700px) { nav { position: sticky; } }' .
			'</style></head><body>' .
			'<nav><div class="nav-logo"><span>RSM</span> / Static Site Importer</div><ul class="nav-links"><li><a href="#context">Context</a></li><li><a href="#impact">Studio Impact</a></li></ul></nav>' .
			'<main><section id="context"><h1>RSM import</h1><p>Body copy.</p></section><section id="impact"><h2>Impact</h2><p>More copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'RSM Nav Wrapper',
				'slug'      => 'rsm-nav-wrapper',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$style     = $this->read_file( $theme_dir . '/style.css' );

		$this->assertStringContainsString( 'static-site-importer-source-nav', $header );
		$this->assertStringContainsString( '<!-- wp:list {"className":"nav-links"} -->', $header );
		$this->assertStringContainsString( '<ul class="wp-block-list nav-links">', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation {"ref":', $header );
		$this->assertStringNotContainsString( '"className":"nav-links"} /-->', $header );
		$this->assertStringContainsString( '.static-site-importer-source-nav { position: fixed; top: 0; left: 0; right: 0; display: flex; justify-content: space-between; }', $style );
		$this->assertStringContainsString( '.static-site-importer-source-nav .nav-logo { font-weight: 800; }', $style );
		$this->assertStringContainsString( '@media (max-width: 700px) { .static-site-importer-source-nav { position: sticky; } }', $style );
		$this->assertStringContainsString( 'body.admin-bar .static-site-importer-source-nav { top: 32px; }', $style );
		$this->assertStringContainsString( '@media screen and (max-width: 782px) { body.admin-bar .static-site-importer-source-nav { top: 46px; } }', $style );
		$this->assertStringNotContainsString( 'body.admin-bar nav { top:', $style );
	}

	/**
	 * Pure top-level nav fragments still become a reusable navigation entity.
	 */
	public function test_pure_top_level_nav_still_converts_to_navigation_entity(): void {
		$html_path = $this->write_temp_fixture(
			'pure-nav-header.html',
			'<!doctype html><html><head><title>Pure Nav Header</title></head><body>' .
			'<nav class="top-nav"><a href="#intro">Intro</a><a href="#pricing"><span>Pricing</span></a></nav>' .
			'<main><section id="intro"><h1>Intro</h1><p>Body copy.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Pure Nav Header',
				'slug'      => 'pure-nav-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$nav_post  = get_page_by_path( 'pure-nav-header-header-navigation', OBJECT, 'wp_navigation' );

		$this->assertStringContainsString( '<!-- wp:navigation ', $header );
		$this->assertStringContainsString( '"className":"top-nav"', $header );
		$this->assertStringNotContainsString( '<!-- wp:navigation-link ', $header );
		$this->assertStringNotContainsString( '"tagName":"nav"', $header );
		$this->assertInstanceOf( WP_Post::class, $nav_post );
		$this->assertStringContainsString( '"label":"Intro"', $nav_post->post_content );
		$this->assertStringContainsString( '"label":"Pricing"', $nav_post->post_content );
	}

	/**
	 * Classed header/footer identity chrome does not silently move classes onto paragraphs.
	 */
	public function test_classed_header_footer_identity_chrome_reports_or_preserves_ownership(): void {
		$html_path = $this->write_temp_fixture(
			'homeboy-chrome-identity.html',
			'<!doctype html><html><head><title>Homeboy Chrome Identity</title></head><body>' .
			'<header><nav class="site-nav"><a href="#" class="nav-logo">HOME<span>BOY</span></a><ul><li><a href="#evidence">Evidence</a></li></ul></nav></header>' .
			'<main id="evidence"><h1>Evidence</h1><p>Body copy.</p></main>' .
			'<footer><div class="footer-logo">HOMEBOY</div><p>Developer operations for engineers who ship with evidence.</p></footer>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Homeboy Chrome Identity',
				'slug'      => 'homeboy-chrome-identity',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer    = $this->read_file( $theme_dir . '/parts/footer.html' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<p><a href="#" class="nav-logo">HOME<span>BOY</span></a></p>', $header );
		$this->assertStringContainsString( '<!-- wp:paragraph {"className":"footer-logo"} --><p class="footer-logo">HOMEBOY</p>', $footer );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $header );
		$this->assertStringNotContainsString( '<!-- wp:freeform', $footer );
		$this->assertStringNotContainsString( 'paragraph_wrapper_required_for_identity_anchor', wp_json_encode( $report['diagnostics'] ?? array() ) ?: '' );
		$this->assertStringNotContainsString( 'native_group_wrapper_preserved_identity_class', wp_json_encode( $report['diagnostics'] ?? array() ) ?: '' );
	}

	/**
	 * Nested section headers are page content, not shared site chrome.
	 */
	public function test_nested_section_header_stays_in_page_content(): void {
		$html_path = $this->write_temp_fixture(
			'nested-section-header.html',
			'<!doctype html><html><head><title>Nested Section Header</title></head><body>' .
			'<main><section class="proof"><div class="proof-inner"><header class="proof-header">' .
			'<p class="section-label">Why It Actually Works</p>' .
			'<h2 class="proof-heading reveal">Six reasons the assumptions are wrong.</h2>' .
			'</header><p class="proof-copy">The section body stays with the section.</p></div></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Nested Section Header',
				'slug'      => 'nested-section-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-nested-section-header.php' ) );

		$this->assertStringNotContainsString( 'proof-header', $header );
		$this->assertStringNotContainsString( 'Six reasons the assumptions are wrong.', $header );
		$this->assertStringContainsString( 'proof-header', $pattern );
		$this->assertStringContainsString( 'Why It Actually Works', $pattern );
		$this->assertStringContainsString( 'Six reasons the assumptions are wrong.', $pattern );
	}

	/**
	 * Footer-like content inside a page section is content, not an empty shared footer part.
	 */
	public function test_nested_footer_bar_does_not_emit_empty_footer_part(): void {
		$html_path = $this->write_temp_fixture(
			'nested-footer-bar.html',
			'<!doctype html><html><head><title>Nested Footer Bar</title></head><body>' .
			'<nav class="top-nav"><a href="/">Home</a></nav>' .
			'<main><section class="cta"><h1>Ship the site</h1><p>CTA body.</p><div class="footer-bar"><span>Fine print stays with the CTA.</span></div></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Nested Footer Bar',
				'slug'      => 'nested-footer-bar',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-nested-footer-bar.php' ) );
		$page      = $this->read_file( $theme_dir . '/templates/page.html' );
		$template  = $this->read_file( $theme_dir . '/templates/page-nested-footer-bar.html' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertFileDoesNotExist( $theme_dir . '/parts/footer.html' );
		$this->assertStringNotContainsString( '"slug":"footer"', $page );
		$this->assertStringNotContainsString( '"slug":"footer"', $template );
		$this->assertStringContainsString( 'footer-bar', $pattern );
		$this->assertStringContainsString( 'Fine print stays with the CTA.', $pattern );
		$this->assertNotContains( 'parts/footer.html', $report['visual_fidelity']['comparison_targets'][0]['comparison_hooks']['generated_files'] ?? array() );
	}

	/**
	 * Semantic main content is the page body when document-level chrome surrounds it.
	 */
	public function test_semantic_main_content_becomes_page_body_without_stray_chrome(): void {
		$html_path = $this->write_temp_fixture(
			'semantic-main-page-body.html',
			'<!doctype html><html><head><title>Semantic Main Page Body</title></head><body>' .
			'<svg aria-hidden="true" class="hidden"><symbol id="chrome-icon"><path d="M0 0h1v1H0z"></path></symbol></svg>' .
			'<noscript><iframe src="https://example.com/tracker" title="Body Noscript Tracker"></iframe></noscript>' .
			'<header class="site-header"><nav><a href="#story">Story</a></nav><button>Header Search</button></header>' .
			'<div class="utility-chrome">Utility Sidebar</div>' .
			'<main><section id="story" class="story-grid"><h1>Main Story</h1><p>Main copy only.</p></section></main>' .
			'<footer><p>Footer Chrome</p></footer>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Semantic Main Page Body',
				'slug'      => 'semantic-main-page-body',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-semantic-main-page-body.php' ) );
		$template  = $this->read_file( $theme_dir . '/templates/page-semantic-main-page-body.html' );
		$header    = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer    = $this->read_file( $theme_dir . '/parts/footer.html' );
		$post      = get_post( $result['pages']['semantic-main-page-body.html'] ?? 0 );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertStringContainsString( 'Main Story', $pattern );
		$this->assertStringContainsString( 'Main copy only.', $pattern );
		$this->assertStringContainsString( 'story-grid', $pattern );
		$this->assertStringNotContainsString( 'Body Noscript Tracker', $pattern );
		$this->assertStringNotContainsString( 'Header Search', $pattern );
		$this->assertStringNotContainsString( 'Utility Sidebar', $pattern );
		$this->assertStringNotContainsString( 'chrome-icon', $pattern );
		$this->assertStringContainsString( 'Header Search', $header );
		$this->assertStringContainsString( 'Footer Chrome', $footer );
		$this->assertStringContainsString( '<!-- wp:post-content {"tagName":"main"} /-->', $template );
		$this->assertSame( trim( $pattern ), trim( $post->post_content ) );

		$previous_theme = get_stylesheet();
		switch_theme( $result['theme_slug'] );
		$frontend_rendered = $this->render_template_for_post( $template, $post );
		switch_theme( $previous_theme );

		$this->assertSame( 1, substr_count( $frontend_rendered, '<main' ) );
		$this->assertStringContainsString( 'Main Story', $frontend_rendered );
		$this->assertMatchesRegularExpression( '/<main\b[^>]*>.*Main Story.*<\/main>/s', $frontend_rendered );
		$this->assertDoesNotMatchRegularExpression( '/<main\b[^>]*>.*Header Search.*<\/main>/s', $frontend_rendered );
	}

	/**
	 * Source button styles are moved to the inner link without restyling the core/button wrapper.
	 */
	public function test_source_button_class_styles_do_not_double_apply_to_core_button_wrapper(): void {
		$html_path = $this->write_temp_fixture(
			'button-wrapper-style.html',
			'<!doctype html><html><head><title>Button Wrapper Style</title><style>' .
			'.nav-cta { background: var(--accent); color: #fff; padding: 10px 24px; border-radius: 100px; transition: transform 0.08s ease; }' .
			'.nav-cta:hover { transform: translateY(-1px); }' .
			'a.nav-cta:focus-visible { outline: 2px solid var(--accent); }' .
			'.btn-primary { background: var(--acid); color: var(--ink); padding: 0.9rem 2.25rem; border-radius: 8px; display: inline-flex; }' .
			'.btn-ghost { color: var(--paper-dim); border-bottom: 1px solid var(--slate-border); padding-bottom: 2px; }' .
			'.btn-outline { border: 1px solid var(--slate-border); color: var(--paper-dim); padding: 0.85rem 1.75rem; border-radius: 8px; }' .
			'</style></head><body>' .
			'<main><h1>Button Wrapper Style</h1><p><a href="#try" class="btn nav-cta">Request Access</a></p>' .
			'<p><a href="#primary" class="btn-primary">Start Building</a> <a href="#ghost" class="btn-ghost">Watch Demo</a> <a href="#outline" class="btn-outline">Read Docs</a></p></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Button Wrapper Style',
				'slug'      => 'button-wrapper-style',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-button-wrapper-style.php' ) );
		$style     = $this->read_file( $theme_dir . '/style.css' );

		$this->assertStringContainsString( '<div class="wp-block-button btn nav-cta">', $pattern );
		$this->assertStringContainsString( '<div class="wp-block-button btn-primary">', $pattern );
		$this->assertStringContainsString( '<div class="wp-block-button btn-ghost">', $pattern );
		$this->assertStringContainsString( '<div class="wp-block-button btn-outline">', $pattern );
		$this->assertStringContainsString( '.nav-cta:not(.wp-block-button)', $style );
		$this->assertStringContainsString( '.btn-primary:not(.wp-block-button)', $style );
		$this->assertStringContainsString( '.btn-ghost:not(.wp-block-button)', $style );
		$this->assertStringContainsString( '.btn-outline:not(.wp-block-button)', $style );
		$this->assertStringNotContainsString( '.nav-cta { background: var(--accent); color: #fff; padding: 10px 24px;', $style );
		$this->assertStringContainsString( '.wp-block-button.nav-cta > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-primary > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-ghost > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-outline > .wp-block-button__link:where(.wp-element-button)', $style );
		$this->assertStringContainsString( 'background: transparent; border: 0; border-radius: 0; box-shadow: none; color: inherit; display: inline;', $style );
		$this->assertStringContainsString( 'max-width: none; min-width: 0; padding: 0; text-decoration: inherit; width: auto;', $style );
		$this->assertStringContainsString( '.wp-block-button.nav-cta > .wp-block-button__link { background: var(--accent); color: #fff; padding: 10px 24px;', $style );
		$this->assertStringContainsString( '.wp-block-button.nav-cta > .wp-block-button__link:hover { transform: translateY(-1px); }', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-primary > .wp-block-button__link { background: var(--acid); color: var(--ink); padding: 0.9rem 2.25rem;', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-ghost > .wp-block-button__link { color: var(--paper-dim); border-bottom: 1px solid var(--slate-border); padding-bottom: 2px;', $style );
		$this->assertStringContainsString( '.wp-block-button.btn-outline > .wp-block-button__link { border: 1px solid var(--slate-border); color: var(--paper-dim); padding: 0.85rem 1.75rem;', $style );
		$this->assertStringContainsString( 'a.nav-cta:focus-visible', $style );
	}

	/**
	 * Absolute decorative children inside imported sections keep their source stack in the Site Editor.
	 */
	public function test_absolute_decorative_overlay_stacks_in_site_editor(): void {
		$html_path = $this->write_temp_fixture(
			'absolute-hero-overlay.html',
			'<!doctype html><html><head><title>Absolute Hero Overlay</title><style>' .
			'.hero { min-height: 100vh; display: flex; flex-direction: column; justify-content: flex-end; position: relative; overflow: hidden; }' .
			'.hero-rip { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: clamp(220px, 42vw, 580px); color: rgba(255, 61, 0, 0.035); pointer-events: none; user-select: none; }' .
			'.hero .container { position: relative; z-index: 1; }' .
			'</style></head><body><main><section class="hero"><div class="hero-rip" aria-hidden="true">RIP</div><div class="container"><h1>WordPress is dead.</h1><p>Hero copy stays above the decorative overlay.</p></div></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Absolute Hero Overlay',
				'slug'      => 'absolute-hero-overlay',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir    = $result['theme_dir'];
		$pattern      = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-absolute-hero-overlay.php' ) );
		$style        = $this->read_file( $theme_dir . '/style.css' );
		$editor_style = $this->read_file( $theme_dir . '/assets/css/editor-style.css' );
		$report       = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '"className":"hero"', $pattern );
		$this->assertStringContainsString( '"tagName":"section"', $pattern );
		$this->assertStringContainsString( 'hero-rip', $pattern );
		$this->assertStringContainsString( '<!-- wp:group {"className":"container"}', $pattern );
		$this->assertStringContainsString( '.hero-rip { position: absolute;', $style );
		$this->assertStringContainsString( '.hero .container { position: relative; z-index: 1;', $style );
		$this->assertStringNotContainsString( '.editor-styles-wrapper', $style );
		$this->assertStringContainsString( '.hero-rip { position: absolute;', $editor_style );
		$this->assertStringContainsString( 'Static Site Importer: let Site Editor wrappers preserve imported absolute overlay stacking.', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .hero-rip)', $editor_style );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
	}

	/**
	 * Empty CSS-only visual layers stay on the frontend without noisy Site Editor group placeholders.
	 */
	public function test_empty_css_only_background_layers_hide_site_editor_placeholders(): void {
		$html_path = $this->write_temp_fixture(
			'empty-css-background-layers.html',
			'<!doctype html><html><head><title>Empty CSS Background Layers</title><style>' .
			'#hero { position: relative; overflow: hidden; min-height: 80vh; }' .
			'.hero-bg { position: absolute; inset: 0; pointer-events: none; }' .
			'.hero-bg-grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px); background-size: 32px 32px; }' .
			'.hero-bg-glow { position: absolute; inset: 10% auto auto 20%; width: 40vw; height: 40vw; background: radial-gradient(circle, rgba(255,61,0,.4), transparent 70%); }' .
			'.hero-bg-glow2 { position: absolute; right: 5%; bottom: 10%; width: 30vw; height: 30vw; background: radial-gradient(circle, rgba(0,200,255,.35), transparent 70%); }' .
			'.hero-inner { position: relative; z-index: 1; }' .
			'</style></head><body><main><section id="hero"><div class="hero-bg"><div class="hero-bg-grid"></div><div class="hero-bg-glow"></div><div class="hero-bg-glow2"></div></div><div class="hero-inner"><h1>Launch cleanly</h1><p>Content remains editable above decorative layers.</p></div></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Empty CSS Background Layers',
				'slug'      => 'empty-css-background-layers',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir    = $result['theme_dir'];
		$pattern      = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-empty-css-background-layers.php' ) );
		$style        = $this->read_file( $theme_dir . '/style.css' );
		$editor_style = $this->read_file( $theme_dir . '/assets/css/editor-style.css' );
		$report       = json_decode( $this->read_file( $result['report_path'] ), true );

		foreach ( array( 'hero-bg-grid', 'hero-bg-glow', 'hero-bg-glow2' ) as $class_name ) {
			$this->assertStringContainsString( $class_name, $pattern );
			$this->assertStringContainsString( 'wp-block-group ' . $class_name . ' static-site-importer-decorative-layer', $pattern );
			$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .' . $class_name . ')', $editor_style );
		}
		$this->assertStringNotContainsString( '.editor-styles-wrapper', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .wp-block-group.static-site-importer-decorative-layer)', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block.wp-block-group.static-site-importer-decorative-layer', $editor_style );
		$this->assertStringNotContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block.wp-block-group { display: contents; }', $editor_style );

		$this->assertStringContainsString( 'Static Site Importer: hide empty decorative layer group controls in the Site Editor.', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .block-editor-block-variation-picker', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .components-placeholder', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .block-list-appender', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .wp-block-group.static-site-importer-decorative-layer .block-editor-button-block-appender', $editor_style );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
	}

	/**
	 * Empty decorative hero background chrome in theme parts serializes as native groups.
	 */
	public function test_empty_hero_background_chrome_in_header_part_uses_native_blocks(): void {
		$html_path = $this->write_temp_fixture(
			'relay-atlas-header-hero-bg.html',
			'<!doctype html><html><head><title>Relay Atlas Header Hero Background</title><style>' .
			'.site-header { position: relative; overflow: hidden; min-height: 72vh; }' .
			'.hero-bg { position: absolute; inset: 0; pointer-events: none; background: radial-gradient(circle at top right, rgba(88,166,255,.28), transparent 42%); }' .
			'.hero-copy { position: relative; z-index: 1; }' .
			'</style></head><body>' .
			'<header class="site-header"><div class="hero-bg"></div><nav><a href="#features">Features</a></nav><div class="hero-copy"><h1>Relay Atlas</h1><p>Map every launch handoff.</p></div></header>' .
			'<main id="features"><section><h2>Features</h2><p>Benchmark evidence for issue 92.</p></section></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Relay Atlas Header Hero Background',
				'slug'      => 'relay-atlas-header-hero-bg',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir    = $result['theme_dir'];
		$header       = $this->read_file( $theme_dir . '/parts/header.html' );
		$style        = $this->read_file( $theme_dir . '/style.css' );
		$editor_style = $this->read_file( $theme_dir . '/assets/css/editor-style.css' );
		$report       = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:group {"className":"hero-bg static-site-importer-decorative-layer"}', $header );
		$this->assertStringContainsString( '<div class="wp-block-group hero-bg static-site-importer-decorative-layer"></div>', $header );
		$this->assertStringNotContainsString( '<!-- wp:html --><div class="hero-bg"></div><!-- /wp:html -->', $header );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $header );
		$this->assertStringNotContainsString( '.editor-styles-wrapper', $style );
		$this->assertStringContainsString( '.editor-styles-wrapper .block-editor-block-list__layout > .wp-block:has(> .hero-bg)', $editor_style );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );

		$header_document = null;
		foreach ( $report['generated_theme']['block_documents'] ?? array() as $document ) {
			if ( 'parts/header.html' === ( $document['path'] ?? '' ) ) {
				$header_document = $document;
				break;
			}
		}
		$this->assertIsArray( $header_document );
		$this->assertSame( 0, $header_document['core_html_block_count'] ?? null );
	}

	/**
	 * Normal empty groups without decorative CSS are not marked for hidden editor controls.
	 */
	public function test_normal_empty_groups_remain_unmarked_for_editor_controls(): void {
		$reflection = new ReflectionClass( Static_Site_Importer_Theme_Generator::class );

		$classes_property = $reflection->getProperty( 'decorative_empty_group_classes' );
		$classes_property->setAccessible( true );
		$classes_property->setValue( null, array( 'hero-bg-grid' => true ) );

		$mark = $reflection->getMethod( 'mark_empty_decorative_group_blocks' );
		$mark->setAccessible( true );

		$markup = '<!-- wp:group {"className":"normal-empty"} --><div class="wp-block-group normal-empty"></div><!-- /wp:group -->';
		$result = $mark->invoke( null, $markup );

		$this->assertSame( $markup, $result );
		$this->assertStringNotContainsString( 'static-site-importer-decorative-layer', $result );
	}

	/**
	 * Imported fixed/sticky top chrome is offset only when the WordPress admin bar is present.
	 */
	public function test_fixed_top_chrome_gets_admin_bar_offsets(): void {
		$html_path = $this->write_temp_fixture(
			'fixed-top-chrome.html',
			'<!doctype html><html><head><title>Fixed Top Chrome</title><style>' .
			'header.site-header { position: fixed; top: 0; left: 0; right: 0; z-index: 10; }' .
			'.top-nav { position: sticky; top: 12px; z-index: 9; }' .
			'.modal-header { position: fixed; top: 0; }' .
			'.site-footer { position: fixed; top: 0; }' .
			'</style></head><body>' .
			'<header class="site-header"><p>Site title</p><nav class="top-nav"><a href="#content">Content</a></nav></header>' .
			'<main id="content"><h1>Fixed Top Chrome</h1><p>Body copy.</p></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Fixed Top Chrome',
				'slug'      => 'fixed-top-chrome',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$style        = $this->read_file( $result['theme_dir'] . '/style.css' );
		$editor_style = $this->read_file( $result['theme_dir'] . '/assets/css/editor-style.css' );

		$this->assertStringContainsString( 'Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar.', $style );
		$this->assertStringContainsString( 'body.admin-bar header.site-header { top: 32px; }', $style );
		$this->assertStringContainsString( '@media screen and (max-width: 782px) { body.admin-bar header.site-header { top: 46px; } }', $style );
		$this->assertStringContainsString( 'body.admin-bar .top-nav { top: calc(12px + 32px); }', $style );
		$this->assertStringContainsString( '@media screen and (max-width: 782px) { body.admin-bar .top-nav { top: calc(12px + 46px); } }', $style );
		$this->assertStringNotContainsString( 'body.admin-bar .modal-header', $style );
		$this->assertStringNotContainsString( 'body.admin-bar .site-footer', $style );
		$this->assertStringContainsString( 'header.site-header { position: fixed; top: 0;', $editor_style );
		$this->assertStringNotContainsString( 'Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar.', $editor_style );
		$this->assertStringNotContainsString( 'body.admin-bar header.site-header', $editor_style );
	}

	/**
	 * Static headers are not offset just because they look like top chrome.
	 */
	public function test_static_header_does_not_get_admin_bar_offsets(): void {
		$html_path = $this->write_temp_fixture(
			'static-header.html',
			'<!doctype html><html><head><title>Static Header</title><style>' .
			'header.site-header { position: relative; top: 0; }' .
			'.top-nav { display: flex; gap: 1rem; }' .
			'</style></head><body>' .
			'<header class="site-header"><nav class="top-nav"><a href="#content">Content</a></nav></header>' .
			'<main id="content"><h1>Static Header</h1><p>Body copy.</p></main>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Static Header',
				'slug'      => 'static-header',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$style = $this->read_file( $result['theme_dir'] . '/style.css' );

		$this->assertStringContainsString( 'header.site-header { position: relative; top: 0;', $style );
		$this->assertStringNotContainsString( 'Static Site Importer: offset imported fixed/sticky top chrome below the WordPress admin bar.', $style );
		$this->assertStringNotContainsString( 'body.admin-bar header.site-header', $style );
		$this->assertStringNotContainsString( 'body.admin-bar .top-nav', $style );
	}

	/**
	 * JS-revealed opacity/transform animation starts are visible in editor canvases.
	 */
	public function test_js_reveal_animation_starts_are_neutralized_in_editor_only(): void {
		$html_path = $this->write_temp_fixture(
			'js-reveal-animation.html',
			'<!doctype html><html><head><title>JS Reveal Animation</title><style>' .
			'.hero { min-height: 80vh; display: grid; place-items: center; }' .
			'.hero-eyebrow { opacity: 0; transform: translateY(12px); transition: opacity .4s ease, transform .4s ease; }' .
			'.hero-title, .hero-tagline { opacity: 0 !important; transform: translateY(20px); }' .
			'@media (min-width: 800px) { .hero-meta { opacity: 0; transform: translateX(-16px); } }' .
			'.visually-hidden { opacity: 0; }' .
			'.spinner { opacity: 0; transform: none; }' .
			'</style></head><body><main><section class="hero">' .
			'<p class="hero-eyebrow">Field Notes Live</p><h1 class="hero-title">Conference content should be editable</h1>' .
			'<p class="hero-tagline">Frontend JavaScript reveals this copy.</p><p class="hero-meta">May 2026</p>' .
			'<span class="visually-hidden">Screen reader label</span><span class="spinner">Loading</span>' .
			'</section></main><script>document.querySelectorAll(\'.hero-eyebrow,.hero-title,.hero-tagline,.hero-meta\').forEach(function(el){el.style.opacity=\'1\';el.style.transform=\'translateY(0)\';});</script></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'JS Reveal Animation',
				'slug'      => 'js-reveal-animation',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir    = $result['theme_dir'];
		$pattern      = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-js-reveal-animation.php' ) );
		$style        = $this->read_file( $theme_dir . '/style.css' );
		$editor_style = $this->read_file( $theme_dir . '/assets/css/editor-style.css' );
		$report       = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( 'Conference content should be editable', $pattern );
		$this->assertStringContainsString( '.hero-eyebrow { opacity: 0; transform: translateY(12px);', $style );
		$this->assertStringContainsString( '.hero-title, .hero-tagline { opacity: 0 !important; transform: translateY(20px);', $style );
		$this->assertStringNotContainsString( 'Static Site Importer: show JS-revealed animation content in editor canvases.', $style );
		$this->assertStringContainsString( 'Static Site Importer: show JS-revealed animation content in editor canvases.', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .hero-eyebrow', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .hero-title', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .hero-tagline', $editor_style );
		$this->assertStringContainsString( '.editor-styles-wrapper .hero-meta', $editor_style );
		$this->assertStringContainsString( 'opacity: 1 !important; transform: none !important;', $editor_style );
		$this->assertStringNotContainsString( '.editor-styles-wrapper .visually-hidden', $editor_style );
		$this->assertStringNotContainsString( '.editor-styles-wrapper .spinner', $editor_style );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
	}

	/**
	 * Safe inline SVG icons are materialized as theme assets and native image blocks.
	 */
	public function test_safe_inline_svg_icons_materialize_as_theme_assets(): void {
		$html_path = $this->write_temp_fixture(
			'safe-svg-icons.html',
			'<!doctype html><html><head><title>Safe SVG Icons</title></head><body><main><section class="icons"><h1>Icons</h1><svg class="icon icon-bolt" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Bolt"><title>Bolt</title><path d="M13 2 3 14h8l-1 8 11-13h-8z" fill="currentColor"/></svg></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Safe SVG Icons',
				'slug'      => 'safe-svg-icons',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-safe-svg-icons.php' ) );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:image ', $pattern );
		$this->assertStringNotContainsString( '<!-- wp:html', $pattern );
		$this->assertStringContainsString( '/assets/icons/', $pattern );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertNotEmpty( $report['assets']['svg_icons'] ?? array() );

		$asset = $report['assets']['svg_icons'][0] ?? array();
		$this->assertSame( 'core/image', $asset['block'] ?? '' );
		$this->assertFileExists( $theme_dir . '/' . ( $asset['path'] ?? '' ) );
	}

	/**
	 * Safe decorative SVG waves are materialized instead of falling back to core/html.
	 */
	public function test_decorative_svg_waves_materialize_as_theme_assets(): void {
		$html_path = $this->write_temp_fixture(
			'decorative-svg-wave.html',
			'<!doctype html><html><head><title>Decorative SVG Wave</title></head><body><main><section class="hero"><h1>Harbor Steps</h1><svg viewBox="0 0 1440 120" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M0,60 C240,100 480,20 720,60 C960,100 1200,20 1440,60 L1440,120 L0,120 Z" fill="#F6EFE1" opacity="0.06"></path></svg><p>Body copy.</p></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Decorative SVG Wave',
				'slug'      => 'decorative-svg-wave',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$pattern   = $this->pattern_blocks( $this->read_file( $theme_dir . '/patterns/page-decorative-svg-wave.php' ) );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:image ', $pattern );
		$this->assertStringNotContainsString( '<!-- wp:html', $pattern );
		$this->assertStringContainsString( '/assets/icons/', $pattern );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertNotEmpty( $report['assets']['svg_icons'] ?? array() );

		$asset = $report['assets']['svg_icons'][0] ?? array();
		$this->assertSame( 'core/image', $asset['block'] ?? '' );
		$this->assertFileExists( $theme_dir . '/' . ( $asset['path'] ?? '' ) );
		$this->assertStringContainsString( 'preserveAspectRatio="none"', $this->read_file( $theme_dir . '/' . ( $asset['path'] ?? '' ) ) );
	}

	/**
	 * Header and footer chrome keep source layout wrappers without broad HTML fallbacks.
	 */
	public function test_header_footer_chrome_structure_survives_theme_part_conversion(): void {
		$html_path = $this->write_temp_fixture(
			'relay-atlas-chrome.html',
			'<!doctype html><html><head><title>Relay Atlas Chrome</title></head><body>' .
			'<nav><div class="nav-inner"><a href="/" class="nav-logo"><div class="nav-logo-mark"><svg class="logo-mark" viewBox="0 0 18 18" width="18" height="18" aria-hidden="true"><path d="M3 5h5v5H3zM10 5h5v2h-5zM10 9h5v2h-5zM3 12h12v1H3z" fill="currentColor"/><circle cx="4.5" cy="14.5" r="1.5" fill="currentColor" fill-opacity="0.6"/></svg></div><span class="nav-wordmark">Relay Atlas</span></a><ul class="nav-links"><li><a href="#features">Features</a></li><li><a href="#pricing">Pricing</a></li></ul><div class="nav-cta"><a href="#signin" class="login-link">Sign in</a><a href="#start" class="btn-primary">Start free</a></div></div></nav>' .
			'<main><section id="features"><h1>Map every handoff</h1><p>Relay Atlas keeps launch teams aligned.</p></section><section id="pricing"><h2>Pricing</h2><p>Simple plans.</p></section></main>' .
			'<footer><div class="footer-inner"><div class="footer-left"><svg class="footer-logo-mark" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg><span>Relay Atlas</span></div><ul class="footer-links"><li><a href="#features">Features</a></li><li><a href="#pricing">Pricing</a></li></ul></div></footer>' .
			'</body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Relay Atlas Chrome',
				'slug'      => 'relay-atlas-chrome',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir  = $result['theme_dir'];
		$header     = $this->read_file( $theme_dir . '/parts/header.html' );
		$footer     = $this->read_file( $theme_dir . '/parts/footer.html' );
		$report     = json_decode( $this->read_file( $result['report_path'] ), true );

		foreach ( array( 'static-site-importer-source-nav', 'nav-inner', 'nav-logo', 'nav-links', 'nav-cta' ) as $class_name ) {
			$this->assertStringContainsString( $class_name, $header );
		}
		foreach ( array( 'footer-inner', 'footer-left', 'footer-links' ) as $class_name ) {
			$this->assertStringContainsString( $class_name, $footer );
		}

		$this->assertStringNotContainsString( '<!-- wp:html --><nav', $header );
		$this->assertStringNotContainsString( '<!-- wp:html --><div class="nav-inner"', $header );
		$this->assertStringNotContainsString( '<!-- wp:html --><a href="/" class="nav-logo"', $header );
		$this->assertStringContainsString( '/assets/icons/', $header );
		$this->assertStringContainsString( 'nav-wordmark', $header );
		$this->assertStringNotContainsString( '<!-- wp:paragraph {"className":"nav-cta"}', $header );
		$this->assertStringContainsString( '<!-- wp:group {"className":"nav-cta"}', $header );
		$this->assertSame( 1, substr_count( $header, '"className":"nav-inner"' ) );
		$this->assertStringContainsString( '<!-- wp:list {"className":"nav-links"} -->', $header );
		$this->assertStringContainsString( '<ul class="wp-block-list nav-links">', $header );
		$this->assertStringNotContainsString( '"className":"nav-links"} /-->', $header );

		$this->assertStringNotContainsString( '<!-- wp:html --><footer', $footer );
		$this->assertStringNotContainsString( '<!-- wp:html --><div class="footer-inner"', $footer );
		$this->assertStringNotContainsString( '<!-- wp:html --><div class="footer-left"', $footer );
		$this->assertSame( 1, substr_count( $footer, '"className":"footer-inner"' ) );
		$this->assertSame( 0, $report['quality']['core_html_block_count'] ?? null );
		$this->assertSame( 0, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertNotEmpty( $report['assets']['svg_icons'] ?? array() );
	}

	/**
	 * Safe inline SVG icons in extracted footer chrome are materialized as native image blocks.
	 */
	public function test_footer_template_part_svg_icons_materialize_as_theme_assets(): void {
		$html_path = $this->write_temp_fixture(
			'footer-svg-icon.html',
			'<!doctype html><html><head><title>Footer SVG Icon</title></head><body><main><h1>Footer SVG Icon</h1><p>Body copy.</p></main><footer class="site-footer"><div class="footer-inner"><div class="footer-row"><div class="footer-brand"><span>Relay Atlas</span><svg viewbox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" width="14" height="14"><path d="M8 2L14 5.5V10.5L8 14L2 10.5V5.5L8 2Z"></path></svg></div><ul class="footer-nav"><li><a href="/privacy.html">Privacy</a></li></ul></div></div></footer></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Footer SVG Icon',
				'slug'      => 'footer-svg-icon',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$theme_dir = $result['theme_dir'];
		$footer    = $this->read_file( $theme_dir . '/parts/footer.html' );
		$report    = json_decode( $this->read_file( $result['report_path'] ), true );

		$this->assertStringContainsString( '<!-- wp:image ', $footer );
		$this->assertStringNotContainsString( '<!-- wp:html', $footer );
		$this->assertStringContainsString( '/assets/icons/', $footer );
		$this->assertStringContainsString( '<figure class="wp-block-image size-large"><img src="', $footer );
		$this->assertStringContainsString( ' alt=""/></figure>', $footer );
		$this->assertStringNotContainsString( ' width="14"', $footer );
		$this->assertStringNotContainsString( ' height="14"', $footer );
		$this->assertStringNotContainsString( ' decoding="async"', $footer );
		$this->assertSame( 0, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertSame( 0, $report['quality']['fallback_count'] ?? null );
		$this->assertSame( 0, $report['quality']['invalid_block_count'] ?? null );
		$this->assertNotEmpty( $report['assets']['svg_icons'] ?? array() );

		$asset = $report['assets']['svg_icons'][0] ?? array();
		$this->assertSame( 'theme-part:footer', $asset['source'] ?? '' );
		$this->assertSame( 'core/image', $asset['block'] ?? '' );
		$this->assertFileExists( $theme_dir . '/' . ( $asset['path'] ?? '' ) );
	}

	/**
	 * Unsafe inline SVG remains visible in the import report instead of being accepted silently.
	 */
	public function test_unsafe_inline_svg_is_reported(): void {
		$html_path = $this->write_temp_fixture(
			'unsafe-svg-icons.html',
			'<!doctype html><html><head><title>Unsafe SVG Icons</title></head><body><main><section><h1>Unsafe</h1><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Unsafe SVG Icons',
				'slug'      => 'unsafe-svg-icons',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertSame( 1, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertContains( 'unsafe_inline_svg', $report['quality']['failure_reasons'] ?? array() );
		$this->assertNotEmpty(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( array $diagnostic ): bool => 'unsafe_inline_svg' === ( $diagnostic['type'] ?? '' )
			)
		);
	}

	/**
	 * Unsafe inline SVG in extracted footer chrome remains visible in diagnostics.
	 */
	public function test_unsafe_footer_template_part_svg_is_reported(): void {
		$html_path = $this->write_temp_fixture(
			'unsafe-footer-svg-icon.html',
			'<!doctype html><html><head><title>Unsafe Footer SVG Icon</title></head><body><main><h1>Unsafe Footer SVG Icon</h1><p>Body copy.</p></main><footer><div><div><div><span>Brand</span><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"></path></svg></div><ul><li><a href="/privacy.html">Privacy</a></li></ul></div></div></footer></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => 'Unsafe Footer SVG Icon',
				'slug'      => 'unsafe-footer-svg-icon',
				'overwrite' => true,
				'activate'  => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report      = json_decode( $this->read_file( $result['report_path'] ), true );
		$diagnostics = array_filter(
			$report['diagnostics'] ?? array(),
			static fn ( array $diagnostic ): bool => 'unsafe_inline_svg' === ( $diagnostic['type'] ?? '' ) && 'theme-part:footer' === ( $diagnostic['source'] ?? '' )
		);

		$this->assertSame( 1, $report['quality']['unsafe_svg_count'] ?? null );
		$this->assertContains( 'unsafe_inline_svg', $report['quality']['failure_reasons'] ?? array() );
		$this->assertNotEmpty( $diagnostics );
	}

	/**
	 * Source cleanup is the default after a clean import.
	 */
	public function test_source_directory_is_deleted_after_clean_import(): void {
		$html_path  = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Clean Import</title></head><body><main><h1>Clean Import</h1><p>Body copy.</p></main></body></html>'
		);
		$source_dir = dirname( $html_path );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'          => 'Clean Import Cleanup',
				'slug'          => 'clean-import-cleanup',
				'overwrite'     => true,
				'activate'      => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['quality']['pass'] ?? false );
		$this->assertTrue( $result['source_deleted'] ?? false );
		$this->assertSame( '', $result['source_cleanup_error'] ?? null );
		$this->assertFalse( file_exists( $source_dir ), 'Clean import source directory should be deleted.' );
	}

	/**
	 * Developers can keep source artifacts for debugging and iteration.
	 */
	public function test_keep_source_preserves_source_directory_after_clean_import(): void {
		$html_path  = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Kept Import</title></head><body><main><h1>Kept Import</h1><p>Body copy.</p></main></body></html>'
		);
		$source_dir = dirname( $html_path );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'        => 'Kept Import Source',
				'slug'        => 'kept-import-source',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['quality']['pass'] ?? false );
		$this->assertFalse( $result['source_deleted'] ?? true );
		$this->assertSame( '', $result['source_cleanup_error'] ?? null );
		$this->assertTrue( file_exists( $source_dir ), 'Clean import source directory should be preserved when requested.' );
	}

	/**
	 * Broken imports always keep source artifacts.
	 */
	public function test_source_directory_is_preserved_when_quality_fails(): void {
		$html_path  = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Broken Import</title></head><body><main><h1>Broken Import</h1><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg></main></body></html>'
		);
		$source_dir = dirname( $html_path );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'          => 'Broken Import Cleanup',
				'slug'          => 'broken-import-cleanup',
				'overwrite'     => true,
				'activate'      => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['quality']['pass'] ?? true );
		$this->assertFalse( $result['source_deleted'] ?? true );
		$this->assertSame( 'import quality checks reported issues', $result['source_cleanup_error'] ?? '' );
		$this->assertTrue( file_exists( $source_dir ), 'Failed-quality import source directory should be preserved.' );
	}

	/**
	 * Validated commerce context is forwarded to BFB and summarized in the import report.
	 */
	public function test_commerce_context_is_forwarded_to_page_conversion_and_reported(): void {
		$html_path = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Commerce Context</title></head><body><main><section class="product-grid"><article class="product-card"><h2>Roasted Beans</h2><p>$18.00</p></article></section></main></body></html>'
		);

		$captured_contexts = array();
		$args_filter       = static function ( array $handler_args ) use ( &$captured_contexts ): array {
			if ( isset( $handler_args['context']['commerce'] ) && is_array( $handler_args['context']['commerce'] ) ) {
				$captured_contexts[] = $handler_args['context']['commerce'];
				do_action(
					'bfb_conversion_metadata',
					array(
						'type'          => 'commerce_context_received',
						'product_count' => count( $handler_args['context']['commerce']['products'] ?? array() ),
					)
				);
			}

			return $handler_args;
		};
		add_filter( 'bfb_html_to_blocks_args', $args_filter, 10, 1 );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'                      => 'Commerce Context',
				'slug'                      => 'commerce-context',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'               => true,
				// Caller-supplied commerce_context yields commerce intent; waive WC dependency for this forwarding test.
				'allow_missing_woocommerce' => true,
				'commerce_context'          => array(
					'source'   => 'products.json',
					'products' => array(
						array(
							'name'            => 'Roasted Beans',
							'slug'            => 'roasted-beans',
							'source_filename' => 'index.html',
							'card_selector'   => '.product-card',
						),
					),
					'selector_hints' => array(
						array(
							'source_filename' => 'index.html',
							'grid_selector'   => '.product-grid',
						),
					),
				),
			)
		);
		remove_filter( 'bfb_html_to_blocks_args', $args_filter, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $captured_contexts );
		$this->assertSame( 'products.json', $captured_contexts[0]['source'] ?? '' );
		$this->assertContains( 'main:index.html', array_column( $captured_contexts, 'source_fragment' ) );

		$report = json_decode( $this->read_file( $result['report_path'] ), true );
		$this->assertIsArray( $report );
		$this->assertTrue( $report['commerce_context']['supplied'] ?? false );
		$this->assertSame( 'products.json', $report['commerce_context']['source'] ?? '' );
		$this->assertSame( 1, $report['commerce_context']['product_count'] ?? null );
		$this->assertContains( '.product-grid', array_column( $report['commerce_context']['selector_hints'] ?? array(), 'grid_selector' ) );
		$this->assertContains( '.product-card', array_column( $report['commerce_context']['selector_hints'] ?? array(), 'card_selector' ) );
		$this->assertSame( 'commerce_context_received', $report['commerce_context']['diagnostics'][0]['metadata']['type'] ?? '' );
	}

	/**
	 * Raw product-card HTML supplies commerce context without requiring products.json.
	 */
	public function test_raw_html_product_cards_supply_commerce_context(): void {
		$html_path = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Raw HTML Store</title></head><body><main><section class="product-grid"><article class="product-card"><h2>Roasted Beans</h2><p class="price">$18.00</p></article><article class="product-card"><h2>Pour Over Kit</h2><p class="price">USD 42</p></article></section></main></body></html>'
		);
		$this->assertNotFalse( file_put_contents( trailingslashit( dirname( $html_path ) ) . 'products.json', '{"products":{"broken":true}}' ) );

		$captured_contexts = array();
		$args_filter       = static function ( array $handler_args ) use ( &$captured_contexts ): array {
			if ( isset( $handler_args['context']['commerce'] ) && is_array( $handler_args['context']['commerce'] ) ) {
				$captured_contexts[] = $handler_args['context']['commerce'];
			}

			return $handler_args;
		};
		add_filter( 'bfb_html_to_blocks_args', $args_filter, 10, 1 );

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'                      => 'Raw HTML Store',
				'slug'                      => 'raw-html-store',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'               => true,
				// HTML-card inference yields commerce intent; waive WC dependency for this inference test.
				'allow_missing_woocommerce' => true,
			)
		);
		remove_filter( 'bfb_html_to_blocks_args', $args_filter, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $captured_contexts );
		$this->assertSame( 'html_cards', $captured_contexts[0]['source'] ?? '' );
		$this->assertSame( 'Roasted Beans', $captured_contexts[0]['products'][0]['name'] ?? '' );
		$this->assertSame( 'roasted-beans', $captured_contexts[0]['products'][0]['slug'] ?? '' );
		$this->assertSame( '18.00', $captured_contexts[0]['products'][0]['regular_price'] ?? '' );
		$this->assertSame( '42.00', $captured_contexts[0]['products'][1]['regular_price'] ?? '' );

		$report      = json_decode( $this->read_file( $result['report_path'] ), true );
		$diagnostics = array_values(
			array_filter(
				$report['diagnostics'] ?? array(),
				static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'products_manifest_invalid' === ( $diagnostic['code'] ?? '' )
			)
		);

		$this->assertIsArray( $report );
		$this->assertTrue( $report['commerce_context']['supplied'] ?? false );
		$this->assertSame( 'html_cards', $report['commerce_context']['source'] ?? '' );
		$this->assertSame( 2, $report['commerce_context']['product_count'] ?? null );
		$this->assertSame( array(), $diagnostics );
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			$this->assertSame( 'woocommerce_inactive', $report['product_seeding']['reason'] ?? '' );
			$this->assertSame( 2, $report['product_seeding']['counts']['skipped'] ?? null );
		}
	}

	/**
	 * Product-card inference accepts generated storefront cards with nested footer/meta prices.
	 */
	public function test_raw_html_product_cards_with_nested_footer_prices_supply_commerce_context(): void {
		$html_path = $this->write_temp_fixture(
			'index.html',
			'<!doctype html><html><head><title>Nested Product Prices</title></head><body><main><section class="product-grid"><article class="product-card"><p class="product-kicker">Dining chair</p><h3>Pressed-Cane Dining Chair Kit</h3><p>Measured cane webbing, spline, groove cleaner...</p><div class="card-footer"><span class="price">$64</span><a class="cta" href="#planning">Measure first</a></div></article><article class="product-card"><div class="product-visual shio"></div><p class="product-kicker">Marinade staple</p><h4>Shio Koji Everyday Jar</h4><p>Salted rice koji for tender grilled chicken...</p><div class="product-meta"><span class="price">$14</span><a class="cta" href="#cart">Add to counter</a></div></article></section></main></body></html>'
		);

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'                      => 'Nested Product Prices',
				'slug'                      => 'nested-product-prices',
				'overwrite'                 => true,
				'activate'                  => false,
				'keep_source'               => true,
				// HTML-card inference yields commerce intent; waive WC dependency for this inference test.
				'allow_missing_woocommerce' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		$report    = json_decode( $this->read_file( $result['report_path'] ), true );
		$inference = $report['commerce_product_inference'] ?? array();

		$this->assertIsArray( $report );
		$this->assertSame( 'html_cards', $inference['strategy'] ?? '' );
		$this->assertSame( 2, $inference['product_count'] ?? null );
		$this->assertSame( 0, $inference['skipped_candidate_count'] ?? null );
		$this->assertSame( 'Pressed-Cane Dining Chair Kit', $inference['products'][0]['name'] ?? '' );
		$this->assertSame( '64.00', $inference['products'][0]['regular_price'] ?? '' );
		$this->assertSame( 'Shio Koji Everyday Jar', $inference['products'][1]['name'] ?? '' );
		$this->assertSame( '14.00', $inference['products'][1]['regular_price'] ?? '' );
		$this->assertSame( 'html_cards', $report['commerce_context']['source'] ?? '' );
		$this->assertSame( 2, $report['commerce_context']['product_count'] ?? null );
	}

	/**
	 * Serialized freeform blocks are counted in generated-theme quality.
	 */
	public function test_generated_theme_quality_reports_serialized_freeform_blocks(): void {
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
				'/tmp/generated/parts/header.html' => '<!-- wp:paragraph --><p>Native block.</p><!-- /wp:paragraph -->' .
					'<!-- wp:freeform --><a href="#" class="nav-logo">Field Notes Live</a><!-- /wp:freeform -->',
			),
			'/tmp/generated'
		);

		$finalize = $reflection->getMethod( 'finalize_quality_report' );
		$finalize->setAccessible( true );
		$quality = $finalize->invoke( null, array() );
		$report  = $report_property->getValue();

		$document = $report['generated_theme']['block_documents'][0] ?? array();
		$freeform_diagnostic = $report['generated_theme']['freeform_blocks'][0] ?? array();

		$this->assertSame( 2, $document['block_count'] ?? null );
		$this->assertSame( 1, $document['freeform_block_count'] ?? null );
		$this->assertSame( 'freeform_block', $freeform_diagnostic['type'] ?? '' );
		$this->assertSame( 'parts/header.html', $freeform_diagnostic['source'] ?? '' );
		$this->assertSame( '1', $freeform_diagnostic['block_path'] ?? '' );
		$this->assertSame( 'a.nav-logo', $freeform_diagnostic['selector'] ?? '' );
		$this->assertSame( 'html-to-blocks-converter', $freeform_diagnostic['converter'] ?? '' );
		$this->assertSame( 'generated_theme_block_analysis', $freeform_diagnostic['stage'] ?? '' );
		$this->assertSame( 'generated_document_contains_core_freeform', $freeform_diagnostic['reason'] ?? '' );
		$this->assertStringContainsString( 'class="nav-logo"', $freeform_diagnostic['source_html_preview'] ?? '' );
		$this->assertStringContainsString( 'wp:freeform', $freeform_diagnostic['emitted_block_preview'] ?? '' );
		$this->assertSame( 1, $quality['freeform_block_count'] ?? null );
		$this->assertFalse( $quality['pass'] ?? true );
		$this->assertContains( 'freeform_block', $quality['failure_reasons'] ?? array() );
	}

	/**
	 * Server-visible malformed block documents are counted in generated-theme quality.
	 */
	public function test_generated_theme_quality_reports_malformed_block_documents(): void {
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
				'/tmp/generated/templates/front-page.html' => '<!-- wp:paragraph --><p>Missing closer</p>',
			),
			'/tmp/generated'
		);

		$finalize = $reflection->getMethod( 'finalize_quality_report' );
		$finalize->setAccessible( true );
		$quality = $finalize->invoke( null, array() );
		$report  = $report_property->getValue();

		$this->assertSame( 1, $quality['invalid_block_count'] ?? null );
		$this->assertSame( 1, $quality['invalid_block_document_count'] ?? null );
		$this->assertContains( 'invalid_block', $quality['failure_reasons'] ?? array() );
		$this->assertSame( 'invalid_block_document', $report['diagnostics'][0]['type'] ?? '' );
	}

	/**
	 * Mixed HTML and Markdown sources share one route map for internal link rewrites.
	 */
	public function test_mixed_html_markdown_source_links_rewrite_through_shared_route_map(): void {
		$plugin_root = dirname( __DIR__ );
		$fixture     = $plugin_root . '/tests/fixtures/mixed-source-links/index.html';

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$fixture,
			array(
				'name'        => 'Mixed Source Links',
				'slug'        => 'mixed-source-links',
				'overwrite'   => true,
				'activate'    => false,
				'keep_source' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'index.html', $result['pages'] );
		$this->assertArrayHasKey( 'about.markdown', $result['pages'] );
		$this->assertArrayHasKey( 'docs/intro.md', $result['pages'] );
		$this->assertArrayHasKey( 'docs/install.markdown', $result['pages'] );

		$home_permalink    = get_permalink( $result['pages']['index.html'] );
		$about_permalink   = get_permalink( $result['pages']['about.markdown'] );
		$intro_permalink   = get_permalink( $result['pages']['docs/intro.md'] );
		$install_permalink = get_permalink( $result['pages']['docs/install.markdown'] );

		$header_nav    = get_page_by_path( 'mixed-source-links-header-navigation', OBJECT, 'wp_navigation' );
		$home_content  = get_post( $result['pages']['index.html'] )->post_content;
		$about_content = get_post( $result['pages']['about.markdown'] )->post_content;
		$intro_content = get_post( $result['pages']['docs/intro.md'] )->post_content;
		$install       = get_post( $result['pages']['docs/install.markdown'] );

		$this->assertInstanceOf( WP_Post::class, $header_nav );
		$this->assertStringContainsString( (string) $intro_permalink, $header_nav->post_content, 'HTML chrome should rewrite HTML-to-Markdown links.' );
		$this->assertStringContainsString( (string) $about_permalink, $header_nav->post_content, 'HTML chrome should rewrite .markdown links.' );
		$this->assertStringContainsString( (string) $install_permalink, $header_nav->post_content, 'HTML chrome should rewrite clean root-relative Markdown routes.' );
		$this->assertStringContainsString( (string) $intro_permalink, $home_content, 'HTML body should rewrite HTML-to-Markdown links.' );
		$this->assertStringContainsString( (string) $home_permalink, $about_content, 'Markdown should rewrite Markdown-to-HTML links.' );
		$this->assertStringContainsString( (string) $install_permalink, $about_content, 'Markdown should rewrite clean Markdown routes.' );
		$this->assertStringContainsString( (string) $about_permalink, $intro_content, 'Markdown should rewrite relative Markdown-to-Markdown links.' );
		$this->assertStringContainsString( (string) $install_permalink, $intro_content, 'Markdown should rewrite extension-swapped Markdown routes.' );
		$this->assertInstanceOf( WP_Post::class, $install );
		$this->assertStringContainsString( (string) $home_permalink, $install->post_content );

		$report      = json_decode( $this->read_file( $result['report_path'] ), true );
		$diagnostics = $report['diagnostics'] ?? array();
		$this->assertContains( 'unresolved_internal_link', wp_list_pluck( $diagnostics, 'type' ) );
		$this->assertContains( 'local_asset_not_materialized', wp_list_pluck( $diagnostics, 'type' ) );
	}

	/**
	 * Reads a generated file.
	 */
	private function read_file( string $path ): string {
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents, 'Unable to read ' . $path );

		return (string) $contents;
	}

	/**
	 * Writes a temporary single-file static site fixture.
	 */
	private function write_temp_fixture( string $filename, string $html ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'static-site-importer-fixtures-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $dir ) );
		$path = trailingslashit( $dir ) . $filename;
		$this->assertNotFalse( file_put_contents( $path, $html ) );

		return $path;
	}

	/**
	 * Extracts block content from a pattern file.
	 */
	private function pattern_blocks( string $pattern_file ): string {
		$parts = explode( '?>', $pattern_file, 2 );

		return trim( 2 === count( $parts ) ? $parts[1] : $pattern_file );
	}

	/**
	 * Renders a block template with page context, matching the frontend post-content path.
	 */
	private function render_template_for_post( string $template, WP_Post $post ): string {
		$rendered = '';
		$context  = array(
			'postId'   => $post->ID,
			'postType' => $post->post_type,
		);

		foreach ( parse_blocks( $template ) as $block ) {
			if ( 'core/post-content' === ( $block['blockName'] ?? '' ) ) {
				$content  = do_blocks( $post->post_content );
				$tag_name = $block['attrs']['tagName'] ?? 'div';
				$rendered .= sprintf( '<%1$s class="entry-content wp-block-post-content">%2$s</%1$s>', tag_escape( $tag_name ), $content );
				continue;
			}

			$rendered .= ( new WP_Block( $block, $context ) )->render();
		}

		return $rendered;
	}

	/**
	 * Counts blocks/elements containing every selector class token.
	 */
	private function selector_count( string $content, string $selector ): int {
		$classes = array_filter( explode( '.', ltrim( $selector, '.' ) ) );
		preg_match_all( '/class="([^"]*)"|"className":"([^"]*)"/', $content, $matches, PREG_SET_ORDER );

		$count = 0;
		foreach ( $matches as $match ) {
			$attribute = $match[1] ?: $match[2];
			$tokens    = preg_split( '/\s+/', trim( $attribute ) ) ?: array();
			if ( empty( array_diff( $classes, $tokens ) ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Whether content contains every selector class token.
	 */
	private function contains_selector( string $content, string $selector ): bool {
		return $this->selector_count( $content, $selector ) > 0;
	}

	/**
	 * Verifies source classes survive storage and render paths.
	 *
	 * @param array<string,array{stored:string,rendered:string}> $pages Page content by fixture filename.
	 */
	private function assert_selector_matrix( array $pages ): void {
		$selector_expectations = array(
			'index.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.pull' ),
			'manifesto.html'  => array( '.hero', '.block', '.container', '.lede', '.manifesto-list', '.pull' ),
			'comparison.html' => array( '.hero', '.block', '.container', '.lede', '.compare', '.col-wp', '.col-claude' ),
			'eulogy.html'     => array( '.hero', '.block', '.container', '.lede', '.eulogy-frame', '.dates', '.pull' ),
			'proof.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.prompt', '.pull' ),
		);

		foreach ( $selector_expectations as $filename => $selectors ) {
			foreach ( array_unique( $selectors ) as $selector ) {
				$this->assertTrue( $this->contains_selector( $pages[ $filename ]['stored'] ?? '', $selector ), 'Stored content lost selector ' . $filename . ' ' . $selector );
				$this->assertTrue( $this->contains_selector( $pages[ $filename ]['rendered'] ?? '', $selector ), 'Rendered content lost selector ' . $filename . ' ' . $selector );
			}
		}
	}
}
