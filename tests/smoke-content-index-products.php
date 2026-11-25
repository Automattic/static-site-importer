<?php
/** Content-index catalog through real compilation, image resolution and Woo seeding. */
require __DIR__ . '/smoke-woo-variable-product.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-index.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-prepared-plan-application.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

function get_woocommerce_currencies(): array { return array( 'USD' => 'US dollar', 'PEN' => 'Sol' ); }
function get_woocommerce_currency(): string { return get_option( 'woocommerce_currency', 'USD' ); }
function update_option( string $name, mixed $value ): bool { $GLOBALS['woo_test_options'][ $name ] = $value; return true; }

$reset();
$GLOBALS['saved_products'] = array();
$catalog = array(
	array( 'name' => 'Captured', 'slug' => 'captured', 'regular_price' => '12.00' ),
	array( 'name' => 'Shirt', 'slug' => 'shirt', 'regular_price' => '20.00', 'variants' => array(
		array( 'sku' => 'S', 'regular_price' => '20.00', 'stock_status' => 'instock', 'attributes' => array( $size( 's' ) ) ),
		array( 'sku' => 'L', 'regular_price' => '25.00', 'stock_status' => 'instock', 'attributes' => array( $size( 'l' ) ) ),
	) ),
	array( 'name' => 'Photo', 'slug' => 'photo', 'regular_price' => '30.00', 'image' => 'media/photo.png', 'images' => array( array( 'src' => 'media/photo.png', 'alt' => 'Catalog photo' ) ) ),
);
foreach ( $catalog as &$row ) { $row['currency'] = 'PEN'; }
unset( $row );
$artifact = array( 'entrypoint' => 'index.html', 'files' => array(
	array( 'path' => 'index.html', 'content' => '<main><h1>Store</h1></main>' ),
	array( 'path' => 'products/captured/index.html', 'content' => '<main><h1>Captured</h1><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Captured","offers":{"@type":"Offer","price":"12.00","priceCurrency":"USD"}}</script></main>' ),
	array( 'path' => 'media/photo.png', 'content' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=' ), 'mime_type' => 'image/png' ),
	array( 'path' => 'content-index.json', 'content' => json_encode( array( 'schema' => 'capture/content-index/v1', 'products' => $catalog ) ) ),
) );
$without_products = $artifact;
$without_products['files'][3]['content'] = json_encode( array( 'schema' => 'capture/content-index/v1' ) );
$unchanged = Static_Site_Importer_Content_Index::extract( $without_products );
$assert( ! isset( $unchanged['artifact']['metadata'] ) && empty( $unchanged['findings'] ), 'Index without products adds no runtime metadata or findings.' );
$extracted = Static_Site_Importer_Content_Index::extract( $artifact );
$declarations = $extracted['artifact']['metadata']['runtime_declarations'];
$assert( 1 === count( $declarations ) && 3 === count( $declarations[0]['payload']['entities'] ), 'One canonical declaration carries all catalog rows.' );
$plan = ( new ArtifactCompiler() )->compile( $extracted['artifact'] )->toArray()['source_reports']['wordpress_site_plan'];
$resolved = ( new WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://example.test/theme', 'runtime_capabilities' => array( 'products' ) ) );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, array( 'runtime_lifecycle_phase' => 'prepare' ) );
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::with_resolved_binding_manifests( $lifecycle, $resolved );
$assert( is_array( $lifecycle ), 'Catalog lifecycle validates.' );
// Exercise the production artifact-relative image resolver, not a hand-made image map.
$resolver = new ReflectionMethod( Static_Site_Importer_Prepared_Plan_Application::class, 'resolve_product_image_references' );
$images = $resolver->invoke( null, $lifecycle, array( 'plan' => $plan, 'resolved' => $resolved ) );
$result = Static_Site_Importer_Entity_Materializer_Registry::materialize_lifecycle_entities( $lifecycle, array( 'resolved_product_images' => $images ) );
$assert( null === $result['error'], 'Catalog materializes.' );
$assert( 'PEN' === get_woocommerce_currency(), 'Catalog currency persists as PEN.' );
$parents = array_filter( $GLOBALS['saved_products'], static fn( $product ) => ! ( $product instanceof WC_Product_Variation ) );
$by_slug = array();
foreach ( $parents as $product ) { $by_slug[$product->data['set_slug']] = $product; }
$assert( 3 === count( $by_slug ), 'All three products seed, including the two without HTML.' );
foreach ( $catalog as $row ) { $assert( $row['regular_price'] === $by_slug[$row['slug']]->data['set_regular_price'], 'Catalog price persists.' ); }
$assert( $by_slug['shirt'] instanceof WC_Product_Variable, 'Variable parent persists.' );
$variations = array_values( array_filter( $GLOBALS['saved_products'], static fn( $product ) => $product instanceof WC_Product_Variation ) );
$assert( 2 === count( $variations ) && '25.00' === $variations[1]->data['set_regular_price'], 'Two priced variants persist.' );
$assert( $by_slug['photo']->data['set_image_id'] > 0, 'Artifact image seeds a featured attachment.' );

// Invalid collections and rows are quality findings, never extraction failures.
foreach ( array( 'bad', array( array( 'name' => 'Invalid' ) ), array_merge( $catalog, array( array( 'name' => 'Invalid' ) ) ) ) as $products ) {
	$bad = $artifact;
	$bad['files'][3]['content'] = json_encode( array( 'schema' => 'capture/content-index/v1', 'products' => $products ) );
	$check = Static_Site_Importer_Content_Index::extract( $bad );
	$assert( is_array( $check ) && ! empty( $check['findings'] ), 'Invalid products degrade to findings.' );
	$assert( ( is_array( $products ) && count( $products ) > 1 ? 3 : 0 ) === count( $check['artifact']['metadata']['runtime_declarations'][0]['payload']['entities'] ?? array() ), 'Only accepted rows declared.' );
}
$with_existing = $artifact;
$with_existing['metadata']['runtime_declarations'] = array( array( 'kind' => 'dependency', 'capability' => 'redirects', 'source_path' => 'index.html' ) );
$check = Static_Site_Importer_Content_Index::extract( $with_existing );
$assert( 2 === count( $check['artifact']['metadata']['runtime_declarations'] ) && $with_existing['metadata']['runtime_declarations'][0] === $check['artifact']['metadata']['runtime_declarations'][0], 'Catalog appends without replacing existing declarations.' );
echo "Content-index products smoke passed.\n";
