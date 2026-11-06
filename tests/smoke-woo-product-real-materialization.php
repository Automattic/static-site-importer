<?php
/** Run in isolation with the full site-plan WordPress harness. */
define( 'SSI_PLAN_HARNESS_ONLY', true );
require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/product-gallery-real/fixture.json' ), true );
$markup = file_get_contents( __DIR__ . '/fixtures/product-gallery-real/carousel.html' );
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
function attachment_url_to_postid( $url ) { return $GLOBALS['real_attachment_urls'][ $url ] ?? 0; }
foreach ( $fixture['attachment_sources'] as $url => $source_url ) {
	$attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_name' => 'photo', 'post_status' => 'inherit', 'post_content' => '' ), true );
	$GLOBALS['real_attachment_urls'][ $url ] = $attachment;
	update_post_meta( $attachment, '_static_site_importer_source_image', $source_url );
}
$product = $fixture['product'] + array( 'id' => 'real-product', 'name' => 'Real product', 'slug' => 'real-product', 'regular_price' => '24' );
$source = 'products/real.html';
$product['source_path'] = $source;
$product['source_route'] = '/products/real';
$product['whole_page_candidate'] = 'blocks-engine/whole-page-candidate/v1';
$files = array( 'index.html' => '<main><h1>Home</h1></main>', $source => '<main><h1>Real product</h1></main>', 'products/other.html' => '<main><h1>Other product</h1></main>' );
foreach ( $fixture['plan_assets'] as $asset ) {
	$files[ preg_replace( '~^website/~', '', $asset['source_path'] ) ] = 'captured photo bytes';
}
$plan = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => $files,
	'runtime_declarations' => array( array( 'kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'index.html', 'payload' => array( 'schema' => 'generic/products/v1', 'entities' => array( $product, array_replace( $product, array( 'id' => 'other-product', 'slug' => 'other', 'source_path' => 'products/other.html', 'source_route' => '/products/other' ) ) ) ) ) ),
) )->toArray()['source_reports']['wordpress_site_plan'];
// Only token IDs change to those issued by this plan; uploads and CDN URLs
// remain exactly as captured, including duplicate attachment suffixes.
foreach ( $fixture['plan_assets'] as $asset ) {
	$asset_index = array_search( preg_replace( '~^website/~', '', $asset['source_path'] ), array_column( $plan['assets'], 'source_path' ), true );
	$assert( false !== $asset_index, 'Captured photo is declared in the plan.' );
	$markup = str_replace( '{{wordpress-site-plan:asset:' . $asset['token'] . '}}', '{{wordpress-site-plan:asset:' . $plan['assets'][ $asset_index ]['token'] . '}}', $markup );
}
$index = array_search( $source, array_column( $plan['pages'], 'source_path' ), true );
$plan['pages'][ $index ]['canonical_block_markup'] = $markup;
$plan['pages'][ $index ]['content_hash'] = hash( 'sha256', $markup );
// A producer-generated single template is also a pending canonical write.
$target = 'templates/single-product-' . $product['slug'] . '.html';
$plan['writes'][] = array( 'kind' => 'theme_template', 'source_path' => $source, 'target_path' => $target, 'reconciliation_identity' => WordPressSitePlan::identity( 'write', $source, $target ), 'payload' => array( 'encoding' => 'utf8', 'data' => $markup ), 'payload_hash' => hash( 'sha256', $markup ) );
$other_index = array_search( 'products/other.html', array_column( $plan['pages'], 'source_path' ), true );
$other_candidate = $plan['pages'][ $other_index ]['whole_page_candidates'][0];
$plan['plan_identity'] = WordPressSitePlan::planIdentity( $plan );
$id = wp_insert_post( array( 'post_type' => 'product', 'post_name' => $product['slug'], 'post_status' => 'publish', 'post_content' => 'Product description' ), true );
$claim = array_diff_key( $plan['pages'][ $index ]['whole_page_candidates'][0], array( 'schema' => true ) ) + array( 'schema' => Static_Site_Importer_Whole_Page_Handoff::SCHEMA, 'status' => 'committed', 'destination_post_id' => $id, 'post_type' => 'product', 'document_destination' => 'template', 'product_data' => $product );
$register = static function ( array $blocks ) use ( &$register ): void {
	foreach ( $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) && ! WP_Block_Type_Registry::get_instance()->is_registered( $block['blockName'] ) ) {
			WP_Block_Type_Registry::get_instance()->register( $block['blockName'], array() );
		}
		$register( $block['innerBlocks'] ?? array() );
	}
};
$runtime = new Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime();
foreach ( array_merge( $plan['pages'], $plan['templates'], $plan['template_parts'] ) as $document ) {
	$register( $runtime->parseBlocks( $document['canonical_block_markup'] ?? '' ) );
}
$register( $runtime->parseBlocks( $markup . '<!-- wp:woocommerce/product-image-gallery /-->' ) );
$other_id = wp_insert_post( array( 'post_type' => 'product', 'post_name' => 'other', 'post_status' => 'publish', 'post_content' => 'Other description' ), true );
$other_claim = array_replace( $claim, $other_candidate, array( 'schema' => Static_Site_Importer_Whole_Page_Handoff::SCHEMA, 'destination_post_id' => $other_id, 'product_data' => array( 'name' => 'Other' ) ) );
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'real-gallery', 'whole_page_provider_results' => array( $claim, $other_claim ) ) );
$assert( 'completed' === $receipt['status'], 'Real materialization completes: ' . json_encode( $receipt['errors'] ?? $receipt ) );
$written = file_get_contents( $GLOBALS['ssi_plan_root'] . '/real-gallery/' . $target );
$assert( 1 === substr_count( $written, 'wp:woocommerce/product-image-gallery' ) && ! str_contains( $written, 'authored-carousel' ) && ! str_contains( $written, '<img ' ), 'Written real product template replaces the authored carousel exactly once.' );
$assert( count( $fixture['plan_assets'] ) === count( $plan['assets'] ), 'Real photo assets participate in materialization.' );
foreach ( $plan['assets'] as $asset ) {
	$assert( ! is_file( $GLOBALS['ssi_plan_root'] . '/real-gallery/' . $asset['target_path'] ), 'Replaced photo is not packaged: ' . $asset['target_path'] );
}
