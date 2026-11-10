<?php
/** Run in isolation with the full site-plan WordPress harness. */
define( 'SSI_PLAN_HARNESS_ONLY', true );
require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/product-gallery-real/fixture.json' ), true );
$written_fixture = file_get_contents( __DIR__ . '/fixtures/product-gallery-real/missed-template.html' );
$entities = json_decode( file_get_contents( __DIR__ . '/fixtures/product-gallery-real/products.json' ), true )['entities'];
$assert( 39 === substr_count( $written_fixture, '<img ' ), 'Untrimmed missed template retains its 39 images.' );
$markup = $written_fixture;
// Non-gallery tokens have no asset records in the gist. Declare isolated
// resources for them; never guess that a recommendation is a product photo.
preg_match_all( '/\{\{wordpress-site-plan:asset:(asset-[a-f0-9]+)\}\}/', $markup, $tokens );
$unrelated_tokens = array_diff( array_unique( $tokens[1] ), array_column( $fixture['plan_assets'], 'token' ) );
$assert( $entities['beach-bubble-free-stickers']['images'] === $fixture['product']['images'], 'Written product entity agrees with captured image contract.' );
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
function attachment_url_to_postid( $url ) { return $GLOBALS['real_attachment_urls'][ $url ] ?? 0; }
foreach ( array_combine( str_replace( 'localhost:9078', 'localhost:9169', array_keys( $fixture['attachment_sources'] ) ), array_values( $fixture['attachment_sources'] ) ) as $url => $source_url ) {
	$attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_name' => 'photo', 'post_status' => 'inherit', 'post_content' => '' ), true );
	$GLOBALS['real_attachment_urls'][ $url ] = $attachment;
	update_post_meta( $attachment, '_static_site_importer_source_image', $source_url );
}
$swapped_fixture = file_get_contents( __DIR__ . '/fixtures/product-gallery-real/swapped-template.html' );
$swapped = Static_Site_Importer_Product_Field_Blocks::bind_document( $swapped_fixture, $entities['beach-b-bubble-free-stickers'] );
$assert( 1 === substr_count( $swapped, 'wp:woocommerce/product-image-gallery' ), 'Already-swapped real product remains a single native gallery.' );
$product = $fixture['product'] + array( 'id' => 'real-product', 'name' => 'Real product', 'slug' => 'real-product', 'regular_price' => '24' );
$source = 'item/real/abc123/index.html';
$product['source_path'] = $source;
$product['source_route'] = '/item/real/abc123';
$product['whole_page_candidate'] = 'blocks-engine/whole-page-candidate/v1';
$other_source = 'item/other/def456/index.html';
$files = array( 'index.html' => '<main><h1>Home</h1></main>', $source => '<main><h1>Real product</h1></main>', $other_source => '<main><h1>Other product</h1></main>' );
foreach ( $fixture['plan_assets'] as $asset ) {
	$files[ preg_replace( '~^website/~', '', $asset['source_path'] ) ] = 'captured photo bytes';
}
foreach ( $unrelated_tokens as $token ) {
	$files[ 'media/' . $token . '.webp' ] = 'unrelated photo ' . $token;
}
// Real missed-import shape: entity uses 750w, slides and thumbnails use
// 100w, and each rendition has independent bytes, token and attachment.
$responsive_photo = 'media/kiss-cut-stickers-white-3x3-lifestyle-2-6a17554267879';
$responsive_paths = array();
foreach ( array( 'bc73450fb5f2' => 100, 'd5187b50c927' => 300, '9e4e87d17282' => 750 ) as $suffix => $width ) {
	$path = $responsive_photo . '-' . $suffix . '.webp';
	$responsive_paths[ $width ] = $path;
	$files[ $path ] = 'independent rendition bytes ' . $width;
}
$responsive_paths[1000] = $responsive_photo . '.webp';
$srcset = implode( ', ', array_map( static fn ( $width, $path ) => '/' . $path . ' ' . $width . 'w', array_keys( $responsive_paths ), $responsive_paths ) );
$files[ $source ] .= '<img src="/' . $responsive_paths[100] . '" srcset="' . $srcset . '">';
foreach ( $product['images'] as &$image ) {
	if ( '/' . $responsive_paths[1000] === $image['src'] ) {
		$image['src'] = '/' . $responsive_paths[750];
	}
}
unset( $image );
$files['media/shared-lifestyle.webp'] = 'captured photo bytes';
foreach ( array_keys( $files ) as $path ) {
	if ( str_ends_with( $path, '.webp' ) ) {
		$files[ $source ] .= '<img src="/' . $path . '">';
	}
}
// The JSON-LD lead is a separate capture, referenced only by a link.
$lead_path = 'media/lead-full-size.webp';
$files[ $lead_path ] = 'independent lead image bytes';
$files[ $source ] .= '<link rel="preload" as="image" href="/' . $lead_path . '">';
array_unshift( $product['images'], array( 'src' => '/' . $lead_path ) );
$product['image'] = '/' . $lead_path;
$entity_images = $product['images'];
$plan = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files' => $files,
	'runtime_declarations' => array( array( 'kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'index.html', 'payload' => array( 'schema' => 'generic/products/v1', 'entities' => array( $product, array_replace( $product, array( 'id' => 'other-product', 'slug' => 'other', 'source_path' => $other_source, 'source_route' => '/item/other/def456' ) ) ) ) ) ),
) )->toArray()['source_reports']['wordpress_site_plan'];
// Restore the capture evidence carried by the real import's asset records.
foreach ( $plan['assets'] as &$asset ) {
	if ( isset( $files[ $asset['source_path'] ] ) && str_ends_with( $asset['source_path'], '.webp' ) && $lead_path !== $asset['source_path'] ) {
		$asset['references'][] = array( 'source_path' => $source, 'selector' => 'img[src="/' . $asset['source_path'] . '"]', 'element' => 'img', 'attribute' => 'src', 'value' => '/' . $asset['source_path'] );
	}
	if ( $lead_path === $asset['source_path'] ) {
		$asset['references'] = array( array( 'source_path' => $source, 'selector' => 'link.preload', 'element' => 'link', 'attribute' => 'href', 'value' => '/' . $lead_path ) );
	}
	if ( in_array( $asset['source_path'], $responsive_paths, true ) ) {
		$asset['references'][] = array( 'source_path' => $source, 'selector' => 'img.product-photo', 'element' => 'img', 'attribute' => 'srcset', 'value' => $srcset );
		if ( $asset['source_path'] === $responsive_paths[100] ) {
			$asset['references'][] = array( 'source_path' => $source, 'selector' => 'img.product-photo', 'element' => 'img', 'attribute' => 'src', 'value' => '/' . $responsive_paths[100] );
		}
	}
}
unset( $asset );
// Only token IDs change to those issued by this plan; uploads and CDN URLs
// remain exactly as captured, including duplicate attachment suffixes.
foreach ( $fixture['plan_assets'] as $asset ) {
	$asset_index = array_search( preg_replace( '~^website/~', '', $asset['source_path'] ), array_column( $plan['assets'], 'source_path' ), true );
	$assert( false !== $asset_index, 'Captured photo is declared in the plan.' );
	$markup = str_replace( '{{wordpress-site-plan:asset:' . $asset['token'] . '}}', '{{wordpress-site-plan:asset:' . $plan['assets'][ $asset_index ]['token'] . '}}', $markup );
}
foreach ( $unrelated_tokens as $token ) {
	$asset_index = array_search( 'media/' . $token . '.webp', array_column( $plan['assets'], 'source_path' ), true );
	$markup = str_replace( '{{wordpress-site-plan:asset:' . $token . '}}', '{{wordpress-site-plan:asset:' . $plan['assets'][ $asset_index ]['token'] . '}}', $markup );
}
$responsive_original = $plan['assets'][ array_search( $responsive_paths[1000], array_column( $plan['assets'], 'source_path' ), true ) ];
$responsive_small = $plan['assets'][ array_search( $responsive_paths[100], array_column( $plan['assets'], 'source_path' ), true ) ];
$markup = str_replace( '{{wordpress-site-plan:asset:' . $responsive_original['token'] . '}}', '{{wordpress-site-plan:asset:' . $responsive_small['token'] . '}}', $markup );
$assert( str_contains( $markup, '{{wordpress-site-plan:asset:' . $responsive_small['token'] . '}}' ), 'Carousel slides and thumbnails use the 100w rendition.' );
$index = array_search( $source, array_column( $plan['pages'], 'source_path' ), true );
$plan['pages'][ $index ]['canonical_block_markup'] = $markup;
$plan['pages'][ $index ]['content_hash'] = hash( 'sha256', $markup );
// A producer-generated single template is also a pending canonical write.
$target = 'templates/single-product-' . $product['slug'] . '.html';
$plan['writes'][] = array( 'kind' => 'theme_template', 'source_path' => $source, 'target_path' => $target, 'reconciliation_identity' => WordPressSitePlan::identity( 'write', $source, $target ), 'payload' => array( 'encoding' => 'utf8', 'data' => $markup ), 'payload_hash' => hash( 'sha256', $markup ) );
$other_index = array_search( $other_source, array_column( $plan['pages'], 'source_path' ), true );
$other_candidate = $plan['pages'][ $other_index ]['whole_page_candidates'][0];
$other_markup = $markup . '<!-- wp:paragraph --><p>Other product details</p><!-- /wp:paragraph -->';
$plan['pages'][ $other_index ]['canonical_block_markup'] = $other_markup;
$plan['pages'][ $other_index ]['content_hash'] = hash( 'sha256', $other_markup );
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
$other_product = array_replace( $product, array( 'id' => 'other-product', 'slug' => 'other', 'name' => 'Other product sharing lifestyle photos' ) );
$other_claim = array_replace( $claim, $other_candidate, array( 'schema' => Static_Site_Importer_Whole_Page_Handoff::SCHEMA, 'destination_post_id' => $other_id, 'product_data' => $other_product ) );
// Persistence can reuse one attachment for multiple captured asset paths.
// The later product's path must not steal that attachment's earlier aliases.
$resolutions = array();
foreach ( $plan['assets'] as $asset ) {
	$photo = '/' . $asset['source_path'];
	foreach ( $GLOBALS['real_attachment_urls'] as $url => $attachment ) {
		if ( get_post_meta( $attachment, '_static_site_importer_source_image', true ) === $photo ) {
			$resolutions[ $asset['token'] ] = array( 'id' => $attachment, 'url' => $url );
			break;
		}
	}
}
foreach ( $plan['assets'] as $asset ) {
	if ( in_array( $asset['source_path'], $responsive_paths, true ) && ! isset( $resolutions[ $asset['token'] ] ) ) {
		$attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_name' => 'rendition', 'post_status' => 'inherit', 'post_content' => '' ), true );
		$url = 'https://example.test/uploads/' . $asset['token'] . '.webp';
		$resolutions[ $asset['token'] ] = array( 'id' => $attachment, 'url' => $url );
	}
}
$shared_index = array_search( 'media/shared-lifestyle.webp', array_column( $plan['assets'], 'source_path' ), true );
$duplicate = $plan['assets'][ $shared_index ];
$resolutions[ $duplicate['token'] ] = reset( $resolutions );
// Process the shared path last, as happens when a later product reuses a photo.
unset( $plan['assets'][ $shared_index ] );
$plan['assets'] = array_values( $plan['assets'] );
$plan['assets'][] = $duplicate;
$plan['plan_identity'] = WordPressSitePlan::planIdentity( $plan );
$prepared = Static_Site_Importer_Site_Plan_Preparation::prepare( $plan, array( 'slug' => 'real-gallery', 'whole_page_provider_results' => array( $claim, $other_claim ) ), $resolutions );
$assert( 'prepared' === $prepared['status'], 'Preparation: ' . json_encode( $prepared ) );
$identities = Static_Site_Importer_Product_Field_Blocks::image_identities( $prepared );
$assert( $identities[ '/' . $responsive_paths[100] ] === $identities[ '/' . $responsive_paths[750] ], 'Plan srcset co-membership joins independently captured responsive renditions.' );
// Browser-root references map through the entry directory, not basenames.
$rooted_state = $prepared;
$rooted_state['plan']['pages'] = array( array( 'entrypoint' => true, 'source_path' => 'website/index.html' ) );
foreach ( $rooted_state['plan']['assets'] as &$asset ) {
	$asset['source_path'] = 'website/' . $asset['source_path'];
	if ( in_array( substr( $asset['source_path'], 8 ), $responsive_paths, true ) ) {
		foreach ( $asset['references'] as &$reference ) {
			if ( 'srcset' === $reference['attribute'] ) {
				// The element's src need not appear in its candidate list.
				$reference['value'] = '/' . $responsive_paths[300] . ' 300w, /' . $responsive_paths[750] . ' 750w, /' . $responsive_paths[1000] . ' 1000w';
			}
		}
		unset( $reference );
	}
}
unset( $asset );
$rooted_aliases = Static_Site_Importer_Product_Field_Blocks::image_identities( $rooted_state );
foreach ( $responsive_paths as $path ) {
	$assert( $rooted_aliases[ '/' . $responsive_paths[750] ] === $rooted_aliases[ 'website/' . $path ], 'Entry-root srcset mapping joins every rendition, including the separate img src.' );
}
$unrelated_token = reset( $unrelated_tokens );
$assert( $rooted_aliases[ 'website/media/' . $unrelated_token . '.webp' ] !== $rooted_aliases[ '/' . $responsive_paths[750] ], 'Unrelated photos are not joined by a filename or width convention.' );
// Identity is stable for transitive capture aliases and distinct responsive
// URLs of the same attachment; URL query strings are never stripped/guessed.
$identity_state = $prepared;
$shared_source = array_search( reset( $resolutions ), $resolutions, true );
$shared_asset = $plan['assets'][ array_search( $shared_source, array_column( $plan['assets'], 'token' ), true ) ];
$cdn = 'https://cdn.example.test/photo.webp?format=750w';
$identity_state['args']['image_asset_records'][ $shared_asset['source_path'] ]['source_urls'] = array( $cdn );
$identity_state['resolved']['resolution']['attachment_resolutions'][ $duplicate['token'] ]['url'] .= '?size=thumbnail';
$aliases = Static_Site_Importer_Product_Field_Blocks::image_identities( $identity_state );
$assert( $aliases[ $cdn ] === $aliases[ $duplicate['source_path'] ], 'Attachment IDs join distinct rendition URLs with exact capture aliases.' );
$assert( ! isset( $aliases['https://unrelated.example.test/photo.webp?format=750w'] ), 'Matching basenames or query shapes never establish identity.' );
$identity_state['plan']['assets'] = array_reverse( $identity_state['plan']['assets'] );
$reverse = Static_Site_Importer_Product_Field_Blocks::image_identities( $identity_state );
$assert( $reverse[ $cdn ] === $reverse[ $duplicate['source_path'] ], 'Equivalence survives reversed product/asset order.' );
// A displayed lead outside the carousel must still block replacement.
$displayed_state = $prepared;
$lead_index = array_search( $lead_path, array_column( $displayed_state['plan']['assets'], 'source_path' ), true );
$lead_asset = $displayed_state['plan']['assets'][ $lead_index ];
$assert( array( 'link' ) === array_unique( array_column( $lead_asset['references'], 'element' ) ), 'Lead capture has only link references, not img/srcset evidence.' );
$displayed_state['plan']['assets'][ $lead_index ]['references'][] = array( 'source_path' => $source, 'selector' => 'img.lead', 'element' => 'img', 'attribute' => 'src', 'value' => '/' . $lead_path );
$displayed_markup = $markup . '<!-- wp:image --><figure class="wp-block-image"><img src="{{wordpress-site-plan:asset:' . $lead_asset['token'] . '}}"></figure><!-- /wp:image -->';
$displayed_presentations = array();
$displayed_aliases = Static_Site_Importer_Product_Field_Blocks::image_identities( $displayed_state );
$displayed_bound = Static_Site_Importer_Product_Field_Blocks::bind_document( $displayed_markup, $product, $displayed_presentations, $displayed_aliases, Static_Site_Importer_Product_Field_Blocks::undisplayed_image_identities( $displayed_state, $displayed_aliases ) );
$assert( 0 === substr_count( $displayed_bound, 'wp:woocommerce/product-image-gallery' ) && preg_match( '~<!-- wp:[^\s]+/authored-carousel\b~', $displayed_bound ), 'An img-displayed lead outside the carousel keeps the authored carousel.' );
$assert( $entity_images === $claim['product_data']['images'], 'The handoff preserves every entity image, including the link-only lead.' );
$prepared = Static_Site_Importer_Site_Plan_Preparation::admit_prepared( $prepared );
$receipt = Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( $prepared );
$assert( 'completed' === $receipt['status'], 'Real materialization completes: ' . json_encode( $receipt['errors'] ?? $receipt ) );
$written = file_get_contents( $GLOBALS['ssi_plan_root'] . '/real-gallery/' . $target );
$assert( 1 === substr_count( $written, 'wp:woocommerce/product-image-gallery' ) && ! preg_match( '~<!-- wp:[^\s]+/authored-carousel\b~', $written ), 'Written real product template replaces the authored carousel exactly once.' );
foreach ( $resolutions as $token => $resolution ) {
	$assert( $identities[ '{{wordpress-site-plan:asset:' . $token . '}}' ] === $identities[ $resolution['url'] ], 'Every token agrees with its resolved attachment, including shared paths.' );
}
$assert( 3 === substr_count( $written, '<img ' ), 'The 27 gallery images and nine duplicate lightbox images disappear; unrelated document images survive.' );
$assert( count( $fixture['plan_assets'] ) + 1 <= count( $plan['assets'] ), 'Real photo assets participate in materialization.' );
$draft_routes = array_filter( $GLOBALS['ssi_plan_posts'], static fn( array $post ): bool => 'page' === $post['post_type'] && 'draft' === $post['post_status'] );
$assert( array() === $draft_routes, 'Nested whole-page handoffs leave no empty synthetic draft parent pages.' );
$other_written = file_get_contents( $GLOBALS['ssi_plan_root'] . '/real-gallery/templates/single-product-other.html' );
$assert( 1 === substr_count( $other_written, 'wp:woocommerce/product-image-gallery' ) && ! preg_match( '~<!-- wp:[^\s]+/authored-carousel\b~', $other_written ), 'Shared-photo product also persists exactly one gallery.' );
foreach ( $plan['assets'] as $asset ) {
	if ( str_starts_with( $asset['source_path'], 'media/asset-' ) ) {
		$assert( is_file( $GLOBALS['ssi_plan_root'] . '/real-gallery/' . $asset['target_path'] ), 'Unrelated photos remain packaged.' );
		continue;
	}
	if ( $lead_path === $asset['source_path'] ) {
		continue; // Link-only lead was never an authored carousel photo.
	}
	if ( ! str_ends_with( $asset['source_path'], '.webp' ) ) {
		continue;
	}
	$assert( ! is_file( $GLOBALS['ssi_plan_root'] . '/real-gallery/' . $asset['target_path'] ), 'Replaced photo is not packaged: ' . $asset['target_path'] );
}
