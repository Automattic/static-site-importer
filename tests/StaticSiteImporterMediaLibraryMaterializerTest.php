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

	/** Source attachment numbers never establish destination ownership. */
	public function test_foreign_image_identity_is_rebound_by_asset_and_reused(): void {
		$foreign_id = self::factory()->attachment->create( array( 'post_title' => 'Unrelated owner image' ) );
		$this->assert_rebound_image_identity( $foreign_id );
		$this->assertSame( 'Unrelated owner image', get_post( $foreign_id )->post_title );
		$this->assertSame( '', get_post_meta( $foreign_id, Static_Site_Importer_Media_Library_Materializer::SOURCE_ASSET_META_KEY, true ) );
	}

	public function test_absent_foreign_image_identity_is_rebound_by_asset(): void {
		$this->assert_rebound_image_identity( 987654321 );
	}

	private function assert_rebound_image_identity( int $foreign_id ): void {
		$uri       = get_theme_root_uri() . '/' . $this->slug();
		$figure    = 'wp-block-image size-full source-presentation wp-image-' . $foreign_id;
		$paragraph = '<!-- wp:paragraph {"className":"keep\u002d\u002dexact"} --><p class="keep--exact">Unrelated</p><!-- /wp:paragraph -->';
		$attrs     = array(
			'id'        => $foreign_id,
			'sizeSlug'  => 'full',
			'className' => 'source-presentation wp-image-' . $foreign_id,
		);
		$image     = '<!-- wp:image ' . serialize_block_attributes( $attrs ) . ' --><figure class="' . $figure . '"><a href="https://example.org/art"><img src="' . $uri . '/media/photo.png" alt="Studio &amp; photo" class="wp-image-' . $foreign_id . '"/></a><figcaption class="wp-element-caption">Original caption</figcaption></figure><!-- /wp:image -->';
		$state     = $this->state_with_page( $image . $paragraph . $image );
		$page      = $state['source_ids']['website/index.html'];
		$report    = Static_Site_Importer_Media_Library_Materializer::materialize( $state );
		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['attachment_count'] );
		$this->assertSame( 2, $report['bound_block_count'] );
		$content = get_post_field( 'post_content', $page );
		$blocks  = parse_blocks( $content );
		$id      = (int) $blocks[0]['attrs']['id'];
		$this->assertGreaterThan( 0, $id );
		$this->assertNotSame( $foreign_id, $id, 'a foreign number, even an existing attachment, is not asset ownership' );
		$this->assertSame( $id, $blocks[2]['attrs']['id'] );
		$this->assertSame( array_replace( $attrs, array( 'id' => $id ) ), $blocks[0]['attrs'] );
		$tag = new WP_HTML_Tag_Processor( $blocks[0]['innerHTML'] );
		$this->assertTrue( $tag->next_tag( 'IMG' ) );
		$this->assertSame( 'wp-image-' . $id, $tag->get_attribute( 'class' ) );
		$this->assertSame( wp_get_attachment_url( $id ), $tag->get_attribute( 'src' ) );
		$this->assertSame( 'Studio & photo', $tag->get_attribute( 'alt' ) );
		$this->assertStringContainsString( '<figure class="' . $figure . '">', $content, 'source presentation selectors remain on the figure' );
		$this->assertStringContainsString( '<a href="https://example.org/art">', $content );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Original caption</figcaption>', $content );
		$this->assertStringContainsString( $paragraph, $content );
		$this->assertSame( array( $id ), $state['applied']['attachments'] );

		$again = Static_Site_Importer_Media_Library_Materializer::materialize( $state );
		$this->assertSame( 0, $again['bound_block_count'] );
		$this->assertSame( $content, get_post_field( 'post_content', $page ), 'already bound content is byte-idempotent' );
		wp_update_post( array(
			'ID'           => $page,
			'post_content' => wp_slash( $image . $paragraph . $image ),
		) );
		$state['applied']['attachments'] = array();
		$reimport                        = Static_Site_Importer_Media_Library_Materializer::materialize( $state );
		$this->assertSame( 1, $reimport['attachment_count'] );
		$this->assertSame( array(), $state['applied']['attachments'], 'reimport reuses the asset-identity attachment' );
		$this->assertSame( $content, get_post_field( 'post_content', $page ) );
	}

	public function test_image_border_dimensions_and_quoted_markup_survive_binding(): void {
		$uri    = get_theme_root_uri() . '/' . $this->slug();
		$attrs  = array(
			'id'     => 987654321,
			'url'    => $uri . '/media/photo.png',
			'width'  => '120px',
			'height' => '80px',
			'style'  => array( 'border' => array( 'color' => '#123456' ) ),
		);
		$inner  = "<figure class='wp-block-image is-resized has-custom-border'><img src='" . $attrs['url'] . "' alt='Border photo' class='has-border-color wp-image-987654321' style='border-color:#123456;width:120px;height:80px'/></figure>";
		$state  = $this->state_with_page( '<!-- wp:image ' . serialize_block_attributes( $attrs ) . ' -->' . $inner . '<!-- /wp:image -->' );
		$report = Static_Site_Importer_Media_Library_Materializer::materialize( $state );
		$this->assertIsArray( $report );
		$block = parse_blocks( get_post_field( 'post_content', $state['source_ids']['website/index.html'] ) )[0];
		$id    = $block['attrs']['id'];
		$this->assertNotSame( 987654321, $id );
		$this->assertSame( array_replace( $attrs, array(
			'id'  => $id,
			'url' => wp_get_attachment_url( $id ),
		) ), $block['attrs'] );
		$tag = new WP_HTML_Tag_Processor( $block['innerHTML'] );
		$this->assertTrue( $tag->next_tag( 'IMG' ) );
		$this->assertSame( 'has-border-color wp-image-' . $id, $tag->get_attribute( 'class' ) );
		$this->assertSame( 'border-color:#123456;width:120px;height:80px', $tag->get_attribute( 'style' ) );
		$this->assertSame( wp_get_attachment_url( $id ), $tag->get_attribute( 'src' ) );
		$figure = new WP_HTML_Tag_Processor( $block['innerHTML'] );
		$this->assertTrue( $figure->next_tag( 'FIGURE' ) );
		$this->assertSame( 'wp-block-image is-resized has-custom-border', $figure->get_attribute( 'class' ) );
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
		$state                                       = $this->state_with_page( $this->page_markup() );
		$state['resolved']['pages'][0]['entrypoint'] = true;
		$state['resolved']['pages'][0]['document_metadata'] = array(
			'links' => array(
				array(
					'rel'          => 'icon',
					'resolved_url' => get_theme_root_uri() . '/' . $this->slug() . '/media/photo.png',
				),
			),
		);

		$report = Static_Site_Importer_Media_Library_Materializer::materialize( $state );

		$this->assertGreaterThan( 0, $report['site_icon'] );
		$this->assertSame( 0, (int) get_option( 'site_icon', 0 ), 'attachment creation does not apply global branding before activation' );
		$state['args']['activate'] = true;
		$identity                  = Static_Site_Importer_Media_Library_Materializer::materialize_identity( $state );
		$this->assertSame( 'applied', $identity['site_icon']['status'] );
		$this->assertSame( $report['site_icon'], (int) get_option( 'site_icon' ) );
		$this->assertSame( 1, $report['attachment_count'], 'the icon reuses the page image attachment for the same file' );

		Static_Site_Importer_Site_Plan_Persistence::rollback( $state );
		$this->assertSame( 0, (int) get_option( 'site_icon', 0 ) );
	}

	/**
	 * Images that are not core/image blocks still become attachments.
	 *
	 * Companion markup, cover, and media-text reference theme rasters. Identical
	 * bytes share one attachment. A CSS background stays a theme file.
	 */
	public function test_embedded_images_bind_attachment_ids_and_dedupe_by_content_hash(): void {
		$uri        = get_theme_root_uri() . '/' . $this->slug();
		$photo      = $uri . '/media/photo.png';
		$copy       = $uri . '/media/photo-copy.png';
		$other      = $uri . '/media/other.png';
		$background = $uri . '/media/background.png';
		$paragraph  = '<!-- wp:paragraph {"className":"keep\u002d\u002dexact"} --><p class="keep--exact">Unrelated</p><!-- /wp:paragraph -->';
		$markup     = '<!-- wp:example/media ' . serialize_block_attributes(
			array(
				'content' => '<img src="' . $photo . '" alt="Studio photo"/>',
				'kind'    => 'media',
			)
		) . ' /-->'
			. '<!-- wp:example/media ' . serialize_block_attributes(
				array(
					'content' => '<img src="' . $copy . '" alt="Studio photo"/>',
				)
			) . ' /-->'
			. '<!-- wp:cover ' . serialize_block_attributes( array(
				'url' => $other,
				'id'  => 987654321,
			) ) . ' --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-987654321" alt="" src="' . $other . '"/></div><!-- /wp:cover -->'
			. '<!-- wp:media-text ' . serialize_block_attributes(
				array(
					'mediaUrl'  => $photo,
					'mediaType' => 'image',
					'mediaId'   => 987654321,
				)
			) . ' --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img class="wp-image-987654321 size-large" src="' . $photo . '" alt="Item photo"/></figure><div class="wp-block-media-text__content">' . $paragraph . '</div></div><!-- /wp:media-text -->'
			. '<!-- wp:group --><div class="wp-block-group" style="background-image:url(' . $background . ')"></div><!-- /wp:group -->';
		$state      = $this->state_with_page( $markup );
		copy( $this->theme_dir . '/media/photo.png', $this->theme_dir . '/media/photo-copy.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Test fixture.
		$different = imagecreatetruecolor( 2, 2 );
		imagepng( $different, $this->theme_dir . '/media/other.png' );
		imagedestroy( $different );
		copy( $this->theme_dir . '/media/other.png', $this->theme_dir . '/media/background.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Test fixture.

		$report = Static_Site_Importer_Media_Library_Materializer::materialize( $state );

		$this->assertIsArray( $report );
		$this->assertSame( 2, $report['attachment_count'], 'identical bytes share one attachment; a CSS background is not imported' );
		$content = get_post_field( 'post_content', $state['source_ids']['website/index.html'] );
		$this->assertStringContainsString( $paragraph, $content, 'unrelated blocks are not re-serialized' );
		$this->assertStringContainsString( 'background-image:url(' . $background . ')', $content, 'CSS backgrounds stay theme assets' );
		$this->assertStringNotContainsString( 'wp-image-987654321', $content );
		$blocks = parse_blocks( $content );
		$ids    = array();
		foreach ( $blocks as $block ) {
			if ( 'example/media' === $block['blockName'] ) {
				$this->assertGreaterThan( 0, (int) ( $block['attrs']['id'] ?? 0 ) );
				$this->assertStringContainsString( 'wp-image-' . $block['attrs']['id'], $block['attrs']['content'] );
				$this->assertStringContainsString( wp_get_attachment_url( $block['attrs']['id'] ), $block['attrs']['content'] );
				$ids[] = (int) $block['attrs']['id'];
			}
			if ( 'core/cover' === $block['blockName'] ) {
				$this->assertGreaterThan( 0, (int) ( $block['attrs']['id'] ?? 0 ) );
				$this->assertStringContainsString( 'wp-image-' . $block['attrs']['id'], $block['innerHTML'] );
				$this->assertNotContains( (int) $block['attrs']['id'], $ids );
			}
			if ( 'core/media-text' === $block['blockName'] ) {
				$this->assertGreaterThan( 0, (int) ( $block['attrs']['mediaId'] ?? 0 ) );
				$this->assertStringContainsString( 'wp-image-' . $block['attrs']['mediaId'] . ' size-full', $block['innerHTML'], 'media-text save() emits attachment and size classes together' );
				$this->assertContains( (int) $block['attrs']['mediaId'], $ids );
			}
		}
		$this->assertCount( 2, $ids );
		$this->assertSame( $ids[0], $ids[1] );
		$this->assertSame( 'Studio photo', get_post_meta( $ids[0], '_wp_attachment_image_alt', true ) );
	}

	/** An owner's existing site icon is kept. */
	public function test_existing_site_icon_is_kept(): void {
		update_option( 'site_icon', 999999 );
		$state = $this->state_with_page( $this->page_markup() );
		$state['resolved']['pages'][0]['document_metadata'] = array(
			'links' => array(
				array(
					'rel'          => 'icon',
					'resolved_url' => get_theme_root_uri() . '/' . $this->slug() . '/media/photo.png',
				),
			),
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
