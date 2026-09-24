<?php
/**
 * Verifies imported page images become Media Library attachments.
 *
 * @package StaticSiteImporter
 */

class StaticSiteImporterMediaLibraryMaterializerTest extends WP_UnitTestCase {

	private string $theme_dir = '';

	private string $slug = '';

	public function tear_down(): void {
		if ( '' !== $this->theme_dir && is_dir( $this->theme_dir ) ) {
			foreach ( glob( $this->theme_dir . '/media/*' ) ?: array() as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
			rmdir( $this->theme_dir . '/media' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $this->theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		}
		parent::tear_down();
	}

	/** Page images bind to one attachment per file; unrelated blocks keep their exact bytes. */
	public function test_page_images_become_attachments_without_reencoding_other_blocks(): void {
		$state = $this->state_with_page( $this->page_markup() );
		$page  = $state['source_ids']['website/index.html'];

		$report = Static_Site_Importer_Media_Library_Materializer::materialize( $state );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['attachment_count'], 'one attachment per distinct source file' );
		$this->assertSame( 2, $report['bound_block_count'] );
		$content = get_post_field( 'post_content', $page );
		$ids     = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( 'core/image' === $block['blockName'] && ! str_contains( $block['innerHTML'], 'icon.svg' ) ) {
				$ids[] = (int) ( $block['attrs']['id'] ?? 0 );
				$this->assertStringContainsString( 'wp-image-' . $block['attrs']['id'], $block['innerHTML'] );
				$this->assertStringContainsString( wp_get_attachment_url( $block['attrs']['id'] ), $block['innerHTML'] );
			}
		}
		$this->assertCount( 2, $ids );
		$this->assertSame( $ids[0], $ids[1] );
		$this->assertSame( 'Studio photo', get_post_meta( $ids[0], '_wp_attachment_image_alt', true ) );
		$this->assertStringContainsString( '<!-- wp:paragraph {"className":"keep\u002d\u002dexact"} --><p class="keep--exact">Unrelated</p><!-- /wp:paragraph -->', $content, 'unrelated blocks are not re-serialized' );
		$this->assertStringContainsString( 'icon.svg', $content, 'SVG icons stay theme assets' );
		$this->assertSame( array( $ids[0] ), $state['applied']['attachments'] );
	}

	/** Rollback removes attachments the import created. */
	public function test_rollback_deletes_created_attachments(): void {
		$state = $this->state_with_page( $this->page_markup() );
		Static_Site_Importer_Media_Library_Materializer::materialize( $state );
		$this->assertCount( 1, $state['applied']['attachments'] ?? array() );
		$attachment = $state['applied']['attachments'][0];
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment ) );

		Static_Site_Importer_Site_Plan_Persistence::rollback( $state );

		$this->assertNull( get_post( $attachment ) );
	}

	/** The source favicon becomes the site icon; rollback restores the prior value. */
	public function test_source_favicon_becomes_site_icon_and_rolls_back(): void {
		delete_option( 'site_icon' );
		$state = $this->state_with_page( $this->page_markup() );
		$state['resolved']['pages'][0]['entrypoint']        = true;
		$state['resolved']['pages'][0]['document_metadata'] = array(
			'links' => array( array( 'rel' => 'icon', 'resolved_url' => get_theme_root_uri() . '/' . $this->slug() . '/media/photo.png' ) ),
		);

		$report = Static_Site_Importer_Media_Library_Materializer::materialize( $state );

		$this->assertGreaterThan( 0, $report['site_icon'] );
		$this->assertSame( $report['site_icon'], (int) get_option( 'site_icon' ) );
		$this->assertSame( 1, $report['attachment_count'], 'the icon reuses the page image attachment for the same file' );

		Static_Site_Importer_Site_Plan_Persistence::rollback( $state );
		$this->assertSame( 0, (int) get_option( 'site_icon', 0 ) );
	}

	/** An owner's existing site icon is kept. */
	public function test_existing_site_icon_is_kept(): void {
		update_option( 'site_icon', 999999 );
		$state = $this->state_with_page( $this->page_markup() );
		$state['resolved']['pages'][0]['document_metadata'] = array(
			'links' => array( array( 'rel' => 'icon', 'resolved_url' => get_theme_root_uri() . '/' . $this->slug() . '/media/photo.png' ) ),
		);

		$report = Static_Site_Importer_Media_Library_Materializer::materialize( $state );

		$this->assertSame( 0, $report['site_icon'] );
		$this->assertSame( 999999, (int) get_option( 'site_icon' ) );
		delete_option( 'site_icon' );
	}

	private function page_markup(): string {
		$uri = get_theme_root_uri() . '/' . $this->slug();
		return '<!-- wp:image {"className":"hero"} --><figure class="wp-block-image hero"><img src="' . $uri . '/media/photo.png" alt="Studio photo"/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph {"className":"keep\u002d\u002dexact"} --><p class="keep--exact">Unrelated</p><!-- /wp:paragraph -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="' . $uri . '/media/photo.png" alt="Studio photo"/></figure><!-- /wp:image -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="' . $uri . '/media/icon.svg" alt=""/></figure><!-- /wp:image -->';
	}

	/** A per-test theme directory, so attachments never cross tests. */
	private function slug(): string {
		if ( '' === $this->slug ) {
			$this->slug = 'ssi-media-test-' . wp_generate_password( 8, false, false );
		}
		return $this->slug;
	}

	/** @return array<string,mixed> */
	private function state_with_page( string $markup ): array {
		$this->theme_dir = get_theme_root() . '/' . $this->slug();
		wp_mkdir_p( $this->theme_dir . '/media' );
		$png = imagecreatetruecolor( 4, 4 );
		imagepng( $png, $this->theme_dir . '/media/photo.png' );
		file_put_contents( $this->theme_dir . '/media/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => wp_slash( $markup ),
			)
		);
		return array(
			'theme'         => array( 'uri' => get_theme_root_uri() . '/' . $this->slug() ),
			'theme_dir'     => $this->theme_dir,
			'ordered_pages' => array( array( 'source_path' => 'website/index.html' ) ),
			'source_ids'    => array( 'website/index.html' => $page ),
			'resolved'      => array( 'pages' => array( array( 'source_path' => 'website/index.html' ) ) ),
			'applied'       => array(),
			'rollback'      => array(),
		);
	}
}
