<?php
/**
 * Capture record intake, verification and native editor output contract.
 *
 * @package StaticSiteImporter
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-image-assets.php';
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message = '' ) {}
	}
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC' );
$record = array(
	'source_urls' => array( 'https://example.org/photo.png', 'https://example.org/photo-small.png' ),
	'resolved_original' => 'https://example.org/photo.png',
	'original' => array( 'source_url' => 'https://example.org/photo.png', 'content_type' => 'image/png', 'width' => 20, 'height' => 20, 'alpha' => false, 'sha256' => hash( 'sha256', $bytes ) ),
	'references' => array( array( 'kind' => 'content', 'document_url' => 'https://example.org/', 'location' => 'img:0', 'source_url' => 'https://example.org/photo-small.png' ) ),
);
$artifact = array(
	'entrypoint' => 'website/index.html',
	'files' => array(
		array( 'path' => 'website/index.html', 'content' => '<html><head><title>Record fixture</title></head><body><main><h1>Record fixture</h1><a href="photo.png"><img src="photo.png" srcset="photo.png 10w, photo.png 20w" sizes="100vw" alt="Garden photo"></a></main></body></html>' ),
		array( 'path' => 'website/photo.png', 'content_base64' => base64_encode( $bytes ) ),
		array( 'path' => 'image-assets.json', 'content' => json_encode( array( 'version' => 1, 'images' => array( $record ) ) ) ),
	),
);
$extracted = Static_Site_Importer_Image_Assets::extract( $artifact );
// Ineligible SVG bytes retain capture identity without becoming attachments.
$svg_bytes  = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28"><animateTransform attributeName="transform" type="rotate"/></svg>';
$svg_record = array_merge( $record, array( 'original' => array_merge( $record['original'], array( 'content_type' => 'image/svg+xml', 'width' => 28, 'height' => 28, 'sha256' => hash( 'sha256', $svg_bytes ) ) ) ) );
$svg_file   = tempnam( sys_get_temp_dir(), 'ssi-svg-' );
try {
	file_put_contents( $svg_file, $svg_bytes );
	$assert( Static_Site_Importer_Image_Assets::verify( $svg_file, $svg_record ), 'Intact unsupported SVG must pass staged integrity verification.' );
	$assert( Static_Site_Importer_Image_Assets::verify_bytes( $svg_bytes, $svg_record ), 'Intact unsupported SVG must pass theme byte verification.' );
	$assert( 'unsupported_svg_content' === Static_Site_Importer_Image_Assets::media_library_unavailable_reason( $svg_file, $svg_record ), 'Eligibility must retain the specific SVG refusal reason.' );
	$assert( ! Static_Site_Importer_Image_Assets::verify_bytes( $svg_bytes . ' ', $svg_record ), 'A changed SVG digest must fail theme verification.' );
	file_put_contents( $svg_file, $svg_bytes . ' ' );
	$assert( ! Static_Site_Importer_Image_Assets::verify( $svg_file, $svg_record ), 'A changed SVG digest must fail staged verification.' );
} finally {
	unlink( $svg_file );
}
$document_cases = array(
	'.txt citation'       => array( "A citation from a source document.\n", 'https://example.org/citation.txt', 'text/plain' ),
	'same bytes as .pdf'  => array( "A citation from a source document.\n", 'https://example.org/citation.pdf', null ),
	'HTML at .txt'        => array( '<!doctype html><html>content</html>', 'https://example.org/error.txt', null ),
	'NUL at .txt'         => array( "citation\0text", 'https://example.org/citation.txt', null ),
	'empty .txt'          => array( '', 'https://example.org/empty.txt', null ),
);
$document_type = new ReflectionMethod( Static_Site_Importer_Image_Assets::class, 'document_content_type' );
$document_type->setAccessible( true );
foreach ( $document_cases as $label => $case ) {
	$assert( $case[2] === $document_type->invoke( null, $case[0], $case[1] ), "Document verification must handle {$label}." );
}
$assert( is_array( $extracted ), 'Valid record must be consumed.' );
$assert( 2 === count( $extracted['artifact']['files'] ), 'Evidence must not become a theme asset.' );
$assert( $record === $extracted['records']['website/photo.png'], 'Source aliases and provenance must survive.' );
$assert( ! str_contains( $extracted['artifact']['files'][0]['content'], 'srcset' ), 'Destination WordPress owns image sizes.' );
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $extracted['artifact'] )->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$asset = array_values( array_filter( $plan['assets'], static fn( array $candidate ): bool => 'website/photo.png' === $candidate['source_path'] ) )[0];
$assert( 'media_library' === $asset['destination'], 'Recorded inline images must route to native media.' );
$resolved = ( new Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://destination.example/themes/fixture', 'attachment_resolutions' => array( $asset['token'] => array( 'id' => 73, 'url' => 'https://destination.example/uploads/garden-photo.png' ) ) ) );
$markup = $resolved['pages'][0]['resolved_block_markup'];
$assert( str_contains( $markup, '"id":73' ) && str_contains( $markup, 'wp-image-73' ), 'Compiler output must be an attachment-bound native image.' );
$assert( ! str_contains( $markup, 'srcset' ) && ! str_contains( $markup, 'sizes=' ), 'Generated markup must not carry the captured same-resource srcset.' );
// A box-tree sidecar binds capture evidence to the exact document bytes, so
// image intake must leave a bound document untouched for Blocks Engine.
$box_tree_document = str_replace( array( '<main>', '<h1>', '<a href' ), array( '<main data-blocks-engine-node-id="n1">', '<h1 data-blocks-engine-node-id="n2">', '<a data-blocks-engine-node-id="n3" href' ), $artifact['files'][0]['content'] );
$box_tree_node     = static fn( array $children ): array => array( 'children' => $children, 'viewports' => array( 'desktop' => array( 'border_box' => array( 'x' => 0, 'y' => 0, 'width' => 20, 'height' => 20 ), 'used_styles' => array_fill_keys( Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::PROPERTIES, 'normal' ) ) ) );
$box_tree          = array( 'schema' => Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree::SCHEMA, 'source_path' => 'website/index.html', 'source_hash' => hash( 'sha256', $box_tree_document ), 'viewports' => array( 'desktop' => array( 'width' => 1280, 'height' => 800 ) ), 'nodes' => array( 'n1' => $box_tree_node( array( 'n2', 'n3' ) ), 'n2' => $box_tree_node( array() ), 'n3' => $box_tree_node( array() ) ) );
$box_tree_sidecar  = array( 'path' => 'website/box-tree/index.json', 'content' => json_encode( $box_tree ) );
$bound             = $artifact;
$bound['files'][0]['content'] = $box_tree_document;
$bound['files'][] = $box_tree_sidecar;
$bound_extracted  = Static_Site_Importer_Image_Assets::extract( $bound );
$bound_files      = is_array( $bound_extracted ) ? array_column( $bound_extracted['artifact']['files'], null, 'path' ) : array();
$assert( $box_tree_document === ( $bound_files['website/index.html']['content'] ?? null ), 'A box-tree bound document must keep its captured bytes.' );
$assert( $box_tree['source_hash'] === hash( 'sha256', $bound_files['website/index.html']['content'] ) && $box_tree_sidecar === ( $bound_files['website/box-tree/index.json'] ?? null ), 'The document must still hash to its untouched box-tree source_hash.' );
$assert( $record === ( $bound_extracted['records']['website/photo.png'] ?? null ), 'A bound document must still bind its image record.' );
$bound_compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $bound_extracted['artifact'] )->toArray();
$assert( ! str_contains( json_encode( $bound_compiled['diagnostics'] ?? array() ), 'box_tree_' ), 'Blocks Engine must accept the bound box-tree evidence.' );
$stale = $bound;
$stale['files'][ count( $stale['files'] ) - 1 ]['content'] = json_encode( array_merge( $box_tree, array( 'source_hash' => str_repeat( '0', 64 ) ) ) );
$assert( ! str_contains( Static_Site_Importer_Image_Assets::extract( $stale )['artifact']['files'][0]['content'], 'srcset' ), 'An unbound document still yields responsive sizes to WordPress.' );
foreach ( array( null, array_merge( $record['original'], array( 'sha256' => str_repeat( '0', 64 ) ) ) ) as $invalid ) {
	$bad = $artifact;
	$bad['files'][2]['content'] = json_encode( array( 'version' => 1, 'images' => array( array_merge( $record, array( 'original' => $invalid ) ) ) ) );
	$assert( Static_Site_Importer_Image_Assets::extract( $bad ) instanceof WP_Error, 'An unresolved original must fail instead of substituting a rendition.' );
}
$bad = $artifact;
$bad['files'][2]['content'] = json_encode( array( 'version' => 1, 'images' => array( $record, $record ) ) );
$assert( Static_Site_Importer_Image_Assets::extract( $bad ) instanceof WP_Error, 'Ambiguous duplicate source identities must be rejected.' );
$legacy = $artifact;
array_pop( $legacy['files'] );
$assert( $legacy === Static_Site_Importer_Image_Assets::extract( $legacy )['artifact'], 'Record-free captures retain their exact image selection.' );
// The committed export was produced with real Chromium by captureWebsite.
$captured = json_decode( file_get_contents( __DIR__ . '/fixtures/image-asset-capture/artifact.json' ), true );
$captured = Static_Site_Importer_Image_Assets::extract( $captured );
$assert( is_array( $captured ) && 1 === count( $captured['records'] ), 'Real capture receipt must identify one original.' );
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $captured['artifact'] )->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$asset = array_values( array_filter( $plan['assets'], static fn( array $candidate ): bool => isset( $captured['records'][ $candidate['source_path'] ] ) ) )[0];
$resolved = ( new Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://destination.example/themes/fixture', 'attachment_resolutions' => array( $asset['token'] => array( 'id' => 73, 'url' => 'https://destination.example/uploads/transparent-garden-logo.webp' ) ) ) );
$markup = $resolved['pages'][0]['resolved_block_markup'];
$assert( str_contains( $markup, '"id":73' ) && str_contains( $markup, '"lightbox":{"enabled":true}' ), 'Captured lightbox must be a native attachment-bound image.' );
$assert( ! str_contains( $markup, 'srcset' ) && ! str_contains( $markup, 'responsive-media' ), 'Captured inline srcset must not retain a raw picture owner.' );
$assert( str_contains( json_encode( $resolved['pages'][0]['document_metadata'] ), 'transparent-garden-logo.webp' ), 'Captured head icon must use the attachment resolution.' );
if ( getenv( 'SSI_IMAGE_RECORD_MARKUP' ) ) {
	file_put_contents( getenv( 'SSI_IMAGE_RECORD_MARKUP' ), $markup );
}
echo "Capture image asset record contract passed.\n";
