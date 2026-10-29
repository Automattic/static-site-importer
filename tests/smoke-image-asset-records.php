<?php
/**
 * Capture record intake, verification and native editor output contract.
 *
 * @package StaticSiteImporter
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
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
