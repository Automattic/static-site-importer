<?php
/**
 * Product images an import also places in page content share one attachment.
 *
 * A product whose images are an SVG and a WebP, both shown on a page, seeds
 * through the real lifecycle before pages materialize. Page media resolution
 * then binds the same attachments, and no image loss is reported.
 *
 * Run: php tests/smoke-woo-product-shared-media-attachments.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone smoke stubs WordPress.

require __DIR__ . '/smoke-woo-variable-product.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-index.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-prepared-plan-application.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-media-library-materializer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

function get_temp_dir(): string {
	return sys_get_temp_dir() . '/'; }
function wp_generate_uuid4(): string {
	return bin2hex( random_bytes( 8 ) ); }
function wp_mkdir_p( string $dir ): bool {
	return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_delete_file( string $file ): void {
	if ( is_file( $file ) ) {
		unlink( $file );
	} }
function wp_check_filetype( string $file ): array {
	$types = array(
		'webp' => 'image/webp',
		'png'  => 'image/png',
	);
	return array( 'type' => $types[ strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ] ?? false ); }
function _wp_relative_upload_path( string $file ): string {
	return ltrim( $file, '/' ); }
function add_filter( string $hook, callable $callback ): void {
	$GLOBALS['filters'][ $hook ][] = $callback; }
function remove_filter( string $hook ): void {
	array_pop( $GLOBALS['filters'][ $hook ] ); }
function wp_get_attachment_url( int $id ): string {
	return 'https://example.test/uploads/' . $id; }

$reset();
$GLOBALS['saved_products'] = array();
$svg     = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10" viewBox="0 0 20 10"><rect width="20" height="10" fill="#c00"/></svg>';
$webp    = base64_decode( 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=' );
$catalog = array(
	array(
		'name'          => 'Tier program',
		'slug'          => 'tier-program',
		'regular_price' => '30.00',
		'image'         => 'media/tier.svg',
		'images'        => array(
			array(
				'src' => 'media/tier.svg',
				'alt' => 'Tier system',
			),
			array(
				'src' => 'media/hip-2.webp',
				'alt' => 'Hip mobility',
			),
		),
	),
);
$artifact  = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		array(
			'path'    => 'index.html',
			'content' => '<main><h1>Store</h1><img src="media/tier.svg" alt="Tier system"><img src="media/hip-2.webp" alt="Hip mobility"></main>',
		),
		array(
			'path'      => 'media/tier.svg',
			'content'   => $svg,
			'mime_type' => 'image/svg+xml',
		),
		array(
			'path'           => 'media/hip-2.webp',
			'content_base64' => base64_encode( $webp ),
			'mime_type'      => 'image/webp',
		),
		array(
			'path'    => 'content-index.json',
			'content' => json_encode(
				array(
					'schema'   => 'capture/content-index/v1',
					'products' => $catalog,
				)
			),
		),
	),
);
$extracted = Static_Site_Importer_Content_Index::extract( $artifact );
$plan      = ( new ArtifactCompiler() )->compile( $extracted['artifact'] )->toArray()['source_reports']['wordpress_site_plan'];
$theme_uri = 'https://example.test/theme';
$resolved  = ( new WordPressSitePlanResolver() )->resolve(
	$plan,
	array(
		'theme_uri'            => $theme_uri,
		'runtime_capabilities' => array( 'products' ),
	)
);
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array( 'runtime_lifecycle_phase' => 'prepare' ) );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $lifecycle, $resolved );
$prepared  = array(
	'plan'        => $plan,
	'resolved'    => $resolved,
	'theme_dir'   => sys_get_temp_dir() . '/ssi-shared-media-theme',
	'theme'       => array( 'uri' => $theme_uri ),
	'destination' => array( 'mode' => 'new_theme' ),
	'args'        => array(),
);

// Products seed before pages, through the production image resolver.
$resolver = new ReflectionMethod( Static_Site_Importer_Prepared_Plan_Application::class, 'resolve_product_image_references' );
$images   = $resolver->invoke( null, $lifecycle, $prepared );
$result   = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $lifecycle, array( 'resolved_product_images' => $images ) );
$assert( null === $result['error'], 'Product materializes.', $result['error'] );
$product = null;
foreach ( $GLOBALS['saved_products'] as $saved ) {
	if ( 'tier-program' === ( $saved->data['set_slug'] ?? '' ) ) {
		$product = $saved;
	}
}
$assert( null !== $product, 'Product persists.' );
$assert( ! str_contains( json_encode( $result['reports'] ), 'unsupported_control_attribute' ), 'No product image loss is reported.', $result['reports'] );

// Pages then resolve their media through the import's Media Library path.
$state       = $prepared;
$resolutions = Static_Site_Importer_Media_Library_Materializer::resolve_plan_assets( $state );
$assert( is_array( $resolutions ), 'Page media resolves.', $resolutions );
$page_ids = array();
foreach ( $plan['assets'] as $asset ) {
	if ( isset( $resolutions[ $asset['token'] ] ) ) {
		$page_ids[ basename( $asset['source_path'] ) ] = $resolutions[ $asset['token'] ]['id'];
	}
}
$assert( isset( $page_ids['tier.svg'], $page_ids['hip-2.webp'] ), 'Page content binds both images as attachments.', $page_ids );
$assert( $page_ids['tier.svg'] === $product->data['set_image_id'], 'The SVG product image is the attachment page content uses.', array( $page_ids, $product->data ) );
$assert( array( $page_ids['hip-2.webp'] ) === $product->data['set_gallery_image_ids'], 'The WebP gallery image is the attachment page content uses.', array( $page_ids, $product->data ) );
echo "Woo product shared media attachments smoke passed.\n";
