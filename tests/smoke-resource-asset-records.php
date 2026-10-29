<?php
/**
 * Capture resource record intake, destination ownership and native editor output contract.
 *
 * Documents and video become attachment-bound plan assets with every
 * reference rewritten; captured fonts stay theme-owned behind theme.json
 * fontFace and verified theme writes.
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

// The committed export was produced with real Chromium by captureWebsite.
$captured  = json_decode( file_get_contents( __DIR__ . '/fixtures/resource-asset-capture/artifact.json' ), true );
$extracted = Static_Site_Importer_Image_Assets::extract( $captured );
$assert( is_array( $extracted ) && 8 === count( $extracted['records'] ), 'The capture receipt must identify eight resources.' );
$kinds = array();
foreach ( $extracted['records'] as $record ) {
	$kinds[ Static_Site_Importer_Image_Assets::kind( $record ) ] = ( $kinds[ Static_Site_Importer_Image_Assets::kind( $record ) ] ?? 0 ) + 1;
}
$assert( 4 === ( $kinds['font'] ?? 0 ) && 2 === ( $kinds['document'] ?? 0 ) && 1 === ( $kinds['video'] ?? 0 ) && 1 === ( $kinds['image'] ?? 0 ), 'Capture kinds must survive intake: ' . json_encode( $kinds ) );
$attachment_bound = array_filter( $extracted['records'], static fn( array $record ): bool => 'media-library' === Static_Site_Importer_Image_Assets::destination_owner( $record ) );
$assert( 4 === count( $attachment_bound ), 'Documents, video and images are attachment-bound.' );
$fonts = array_filter( $extracted['records'], static fn( array $record ): bool => 'font' === Static_Site_Importer_Image_Assets::kind( $record ) );

$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( $extracted['artifact'] )->toArray();
$plan      = $compiled['source_reports']['wordpress_site_plan'];
$by_source = array_column( $plan['assets'], null, 'source_path' );
foreach ( $extracted['records'] as $source => $record ) {
	$asset = $by_source[ $source ] ?? null;
	$assert( is_array( $asset ), 'The plan must declare every recorded resource: ' . $source );
	$assert( Static_Site_Importer_Image_Assets::plan_destination( $record ) === ( $asset['destination'] ?? '' ), 'The compiler must honor the capture-owned destination: ' . $source );
}
$theme_json_write = null;
foreach ( $plan['writes'] as $write ) {
	if ( 'theme.json' === $write['target_path'] ) {
		$theme_json_write = $write;
	}
}
$assert( is_array( $theme_json_write ), 'Captured fonts must produce theme.json.' );
$faces = json_decode( (string) ( $theme_json_write['canonical_payload'] ?? $theme_json_write['payload']['data'] ), true )['settings']['typography']['fontFamilies'][0]['fontFace'] ?? array();
$assert( 2 === count( $faces ) && 'Garden Sans' === $faces[0]['family'], 'theme.json fontFace must own the captured family.' );
foreach ( $faces as $face ) {
	$assert( str_starts_with( (string) $face['src'], 'file:./assets/website/media/' ), 'Each fontFace src must be a theme-relative file.' );
}

// Attachment-bound documents and video resolve every content, menu and head
// reference to the one attachment URL; fonts resolve to their theme files.
$resolutions = array();
foreach ( $plan['assets'] as $asset ) {
	if ( 'media_library' === ( $asset['destination'] ?? '' ) ) {
		$resolutions[ $asset['token'] ] = array( 'id' => 73 + count( $resolutions ), 'url' => 'https://destination.example/uploads/' . basename( $asset['source_path'] ) );
	}
}
$assert( 4 === count( $resolutions ), 'Exactly the attachment-bound assets may carry resolutions.' );
$resolved = ( new Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://destination.example/themes/fixture', 'attachment_resolutions' => $resolutions ) );
$markup   = $resolved['pages'][0]['resolved_block_markup'];
foreach ( $resolutions as $attachment ) {
	$assert( substr_count( $markup, $attachment['url'] ) >= 1, 'Materialized markup must reference the attachment URL for ' . $attachment['url'] );
}
$assert( ! str_contains( $markup, 'wordpress-site-plan:asset' ) && ! str_contains( $markup, '/media/terms.pdf' ) && ! str_contains( $markup, '/media/download.zip' ), 'No token or source path may survive in materialized markup.' );
$assert( str_contains( $markup, '<!-- wp:file' ) && str_contains( $markup, '<!-- wp:video' ), 'Documents and video must remain native blocks.' );
$nav = $resolved['menus'][0]['resolved_block_markup'] ?? '';
$assert( '' !== $nav && str_contains( $nav, $resolutions[ $by_source['website/media/terms.pdf']['token'] ]['url'] ), 'The navigation link must resolve to the document attachment.' );
$fonts_css = null;
$written_targets = array();
foreach ( $resolved['writes'] as $write ) {
	$written_targets[] = $write['target_path'];
	if ( str_ends_with( $write['target_path'], 'fonts.css' ) ) {
		$fonts_css = $write['payload']['data'];
	}
}
$assert( is_string( $fonts_css ) && ! str_contains( $fonts_css, 'wordpress-site-plan:asset' ) && 2 === substr_count( $fonts_css, '@font-face' ), 'Imported @font-face rules must resolve their sources.' );
foreach ( $fonts as $source => $font ) {
	$assert( in_array( 'assets/' . $source, $written_targets, true ), 'Theme-owned fonts must keep their theme write: ' . $source );
	$assert( str_contains( $fonts_css, "media/$source" ) || str_contains( $fonts_css, basename( $source ) ), 'The @font-face rules must reference the theme-owned file: ' . basename( $source ) );
}
foreach ( $attachment_bound as $source => $record ) {
	$assert( ! in_array( 'assets/' . $source, $written_targets, true ), 'Attachment-bound resources must not keep a theme copy: ' . $source );
}

// Conflicting destination ownership fails explicitly, never placeholder:
// a font claiming the Media Library and a document claiming the theme.
foreach ( array( 4 => 'media-library', 0 => 'theme' ) as $conflicting_asset => $conflicting_owner ) {
	$lying = $captured;
	foreach ( $lying['files'] as $index => $file ) {
		if ( 'capture-receipt.json' !== basename( (string) ( $file['path'] ?? '' ) ) ) {
			continue;
		}
		$data                                   = json_decode( (string) $file['content'], true );
		$data['assets'][ $conflicting_asset ]['destination_owner'] = $conflicting_owner;
		$lying['files'][ $index ]['content']     = json_encode( $data );
	}
	$assert( Static_Site_Importer_Image_Assets::extract( $lying ) instanceof WP_Error, 'An asset claiming ' . $conflicting_owner . ' ownership against its kind must fail intake.' );
}

// Verification is digest- and signature-bound for every non-image kind.
$bytes_by_source = array();
foreach ( $captured['files'] as $file ) {
	if ( isset( $file['content_base64'] ) ) {
		$bytes_by_source[ $file['path'] ] = base64_decode( $file['content_base64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixture transport.
	}
}
foreach ( $extracted['records'] as $source => $record ) {
	if ( 'image' === Static_Site_Importer_Image_Assets::kind( $record ) ) {
		continue;
	}
	$assert( Static_Site_Importer_Image_Assets::verify_bytes( $bytes_by_source[ $source ], $record ), 'Captured bytes must verify against the record: ' . $source );
	$wrong_digest           = $record;
	$wrong_digest['original'] = array_merge( $record['original'], array( 'sha256' => str_repeat( '0', 64 ) ) );
	$assert( ! Static_Site_Importer_Image_Assets::verify_bytes( $bytes_by_source[ $source ], $wrong_digest ), 'A wrong digest must fail: ' . $source );
	$served_mime           = $record;
	$served_mime['original'] = array_merge( $record['original'], array( 'content_type' => 'application/octet-stream' ) );
	$assert( ! Static_Site_Importer_Image_Assets::verify_bytes( $bytes_by_source[ $source ], $served_mime ), 'The served MIME must never substitute the byte signature: ' . $source );
}

echo "Capture resource asset record contract passed.\n";
