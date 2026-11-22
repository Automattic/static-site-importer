<?php
/**
 * Verifies a provider-created attachment never takes a captured page route.
 *
 * @package StaticSiteImporter
 */

class StaticSiteImporterRouteSlugReservationTest extends WP_UnitTestCase {

	/** @var array<int,int> */
	private array $created_attachments = array();

	private string $slug = '';

	public function tear_down(): void {
		remove_all_filters( 'ssi_shop_plugin' );
		remove_all_filters( 'static_site_importer_entity_materializers' );
		foreach ( $this->created_attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		if ( '' !== $this->slug ) {
			$theme_dir = get_theme_root() . '/' . $this->slug;
			if ( is_dir( $theme_dir ) ) {
				$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $items as $item ) {
					$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test fixture cleanup.
				}
				rmdir( $theme_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			}
		}
		parent::tear_down();
	}

	/**
	 * Entity providers run before pages materialize. A product photo titled
	 * after its product must not claim the slug of a page with that title
	 * (issue #3261: /build-your-drawer/ became /build-your-drawer-2/).
	 */
	public function test_entity_attachment_titled_like_a_page_keeps_the_page_route(): void {
		$attachments = &$this->created_attachments;
		// Plan resolution needs an absolute theme URI; the test runtime's may be relative.
		add_filter( 'theme_root_uri', static fn(): string => 'http://example.org/wp-content/themes' );
		add_filter( 'ssi_shop_plugin', static fn(): string => 'route-fixture-shop' );
		add_filter(
			'static_site_importer_entity_materializers',
			static function ( array $adapters ) use ( &$attachments ): array {
				$adapters['route_fixture_shop'] = array(
					'id'                   => 'route_fixture_shop',
					'capability'           => 'shop',
					'provider'             => 'route-fixture-shop',
					'entity_collection'    => 'products',
					'rollback_contract_id' => 'test/route-fixture-shop/v1',
					'validator'            => static fn( array $manifest ): array => array(
						'products' => $manifest['products'] ?? array(),
						'errors'   => array(),
					),
					// Models the WooCommerce seeder: the product photo is titled after the product.
					'materializer'         => static function ( array $manifest ) use ( &$attachments ): array {
						$rows = array();
						foreach ( $manifest['products'] ?? array() as $product ) {
							$attachments[] = wp_insert_attachment(
								array(
									'post_mime_type' => 'image/jpeg',
									'post_title'     => $product['name'],
									'post_status'    => 'inherit',
								)
							);
							$rows[]        = array(
								'slug'   => $product['slug'],
								'status' => 'created',
								'id'     => end( $attachments ),
							);
						}
						return array(
							'status'   => 'completed',
							'counts'   => array( 'created' => count( $rows ) ),
							'products' => $rows,
						);
					},
					'rollback_callback'    => static fn(): array => array( 'status' => 'rolled_back' ),
				);
				return $adapters;
			}
		);

		$files    = array(
			'index.html'                   => '<html><head><title>Home</title></head><body><main><h1>Home</h1><p><a href="/build-your-drawer/">Build Your Drawer</a></p></main></body></html>',
			'build-your-drawer/index.html' => '<html><head><title>Build Your Drawer</title></head><body><main><h1>Build Your Drawer</h1><p>Pick every module.</p></main></body></html>',
		);
		$product  = array(
			'id'            => 'rio-cat-027',
			'name'          => 'Build Your Drawer',
			'slug'          => 'rio-cat-027',
			'regular_price' => '40',
			'source_path'   => 'index.html',
			'source_route'  => '/product/rio-cat-027',
		);
		$artifact = array(
			'entrypoint'           => 'index.html',
			'files'                => array(),
			'runtime_declarations' => array(
				array(
					'kind'        => 'entity_collection',
					'type'        => 'products',
					'source_path' => 'index.html',
					'required'    => true,
					'payload'     => array(
						'schema'   => 'generic/products/v1',
						'entities' => array( $product ),
					),
				),
			),
		);
		foreach ( $files as $path => $content ) {
			$artifact['files'][] = array(
				'path'    => $path,
				'content' => $content,
			);
		}
		$this->slug = 'ssi-route-slug-' . strtolower( wp_generate_password( 8, false, false ) );
		$result     = Static_Site_Importer_Theme_Generator::import_website_artifact(
			$artifact,
			array(
				'slug'          => $this->slug,
				'name'          => 'Route slug fixture',
				'activate'      => false,
				'seed_entities' => true,
			)
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$this->assertCount( 1, $this->created_attachments, 'The provider must run and create its product photo.' );
		$page = get_page_by_path( 'build-your-drawer' );
		$this->assertInstanceOf( WP_Post::class, $page, 'The captured route must resolve to a page.' );
		$this->assertSame( 'page', $page->post_type );
		$this->assertSame( 'Build Your Drawer', $page->post_title );
		$this->assertSame( 'build-your-drawer', $page->post_name, 'The page keeps its source-route slug.' );
		$this->assertSame( 'build-your-drawer-2', get_post_field( 'post_name', $this->created_attachments[0] ), 'The attachment yields the slug as it would beside an existing page.' );
	}
}
