<?php
/**
 * Capture record import through public compilation and persistence, in WordPress.
 *
 * @package StaticSiteImporter
 */

require_once dirname( __DIR__ ) . '/static-site-importer.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$slug = 'ssi-record-' . strtolower( wp_generate_password( 8, false, false ) );
$artifact = json_decode( file_get_contents( __DIR__ . '/fixtures/image-asset-capture/artifact.json' ), true );
$capture = json_decode( $artifact['files'][3]['content'], true );
$record = $capture['assets'][0];
$args = array( 'slug' => $slug, 'name' => 'Image record fixture', 'activate' => false, 'defer_materialization_commit' => true );
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
$assert( ! is_wp_error( $compiled ), 'Compilation failed: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$plan = $compiled['plan'];
$receipt = null;
$previous_icon = get_option( 'site_icon', 0 );
$opaque_conversion = static fn( array $formats ): array => array_merge( $formats, array( 'image/webp' => 'image/jpeg' ) );
add_filter( 'image_editor_output_format', $opaque_conversion );
add_image_size( 'ssi-record-proof', 80, 40 );
try {
	$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $compiled['args'] );
	$assert( 'completed' === $receipt['status'], 'Import failed: ' . wp_json_encode( $receipt ) );
	$state = $receipt['transaction']->state;
	$ids = $state['applied']['attachments'] ?? array();
	$assert( 1 === count( $ids ), 'One capture resource must create exactly one attachment.' );
	$assert( 1 === count( $receipt['completed']['image_assets'] ?? array() ) && $record['source_urls'] === $receipt['completed']['image_assets'][0]['source_urls'], 'Public receipt must map every source alias to its destination.' );
	$assert( $ids[0] === $receipt['completed']['image_assets'][0]['destination']['attachment_id'], 'Public receipt must expose the attachment ID.' );
	$id = $ids[0];
	$url = wp_get_attachment_url( $id );
	$assert( 'Transparent garden logo' === get_the_title( $id ), 'Attachment needs an authored title.' );
	$assert( 'Transparent garden logo' === get_post_meta( $id, '_wp_attachment_image_alt', true ), 'Attachment needs authored alt text.' );
	$assert( str_starts_with( basename( get_attached_file( $id ) ), 'transparent-garden-logo' ), 'Upload filename must be meaningful.' );
	$assert( hash_file( 'sha256', get_attached_file( $id ) ) === $record['original']['sha256'], 'Transparent original bytes must survive unchanged.' );
	$metadata = wp_get_attachment_metadata( $id );
	$assert( 203 === $metadata['width'] && 90 === $metadata['height'], 'WordPress must generate dimensions from the original.' );
	$size = $metadata['sizes']['ssi-record-proof'] ?? null;
	$assert( is_array( $size ) && 'image/webp' === $size['mime-type'], 'An opaque output-format filter must not convert alpha sizes to JPEG.' );
	$intermediate = imagecreatefromwebp( dirname( get_attached_file( $id ) ) . '/' . $size['file'] );
	$assert( imagecolorsforindex( $intermediate, imagecolorat( $intermediate, imagesx( $intermediate ) - 1, 0 ) )['alpha'] > 0, 'Generated WordPress size must preserve transparent pixels.' );
	imagedestroy( $intermediate );
	$assert( $record === array_intersect_key( get_post_meta( $id, '_static_site_importer_asset_record', true ), $record ), 'Source aliases and categorized references must remain on the attachment.' );
	$content = get_post_field( 'post_content', reset( $state['source_ids'] ) );
	$assert( str_contains( $content, '"id":' . $id ) && str_contains( $content, 'wp-image-' . $id ), 'Editable core image must bind its attachment.' );
	$assert( str_contains( $content, '"lightbox":{"enabled":true}' ) && str_contains( $content, 'src="' . $url . '"' ), 'Native lightbox must use the same attachment.' );
	$assert( ! str_contains( $content, 'responsive-media' ) && ! str_contains( $content, 'srcset=' ), 'WordPress, not captured srcset descriptors, owns image sizes.' );
	$icon = $state['resolved']['pages'][0]['document_metadata']['links'] ?? array();
	$assert( in_array( $url, array_column( $icon, 'resolved_url' ), true ), 'Head icon must resolve to the attachment.' );
	delete_option( 'site_icon' );
	$state['args']['activate'] = true;
	$identity = Static_Site_Importer_Media_Library_Materializer::materialize_identity( $state );
	$assert( $id === ( $identity['site_icon']['attachment_id'] ?? 0 ), 'Site identity must reuse the image attachment.' );
	update_option( 'site_icon', $previous_icon );
	$css_bound = false;
	foreach ( $state['resolved']['writes'] as $write ) {
		if ( str_ends_with( $write['target_path'], '.css' ) ) {
			preg_match_all( '/url\(\s*[\'\"]?([^\'\"\s)]+)/', $write['payload']['data'], $urls );
			foreach ( $urls[1] as $css_url ) {
				$path = realpath( dirname( $state['theme_dir'] . '/' . $write['target_path'] ) . '/' . rawurldecode( $css_url ) );
				$css_bound = $css_bound || $css_url === $url || ( false !== $path && $path === realpath( get_attached_file( $id ) ) );
			}
		}
	}
	$assert( $css_bound, 'CSS background must use the same attachment.' );
	if ( getenv( 'SSI_IMAGE_RECORD_MARKUP' ) ) {
		file_put_contents( getenv( 'SSI_IMAGE_RECORD_MARKUP' ), $content );
	}
	$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array_merge( $compiled['args'], array( 'overwrite' => true ) ) );
	$assert( 'completed' === $repeat['status'] && empty( $repeat['transaction']->state['applied']['attachments'] ), 'Reimport must reuse the record attachment without journaling it as new.' );
	$assert( $id === $repeat['completed']['image_assets'][0]['destination']['attachment_id'], 'Reimport receipt must retain source-to-destination identity.' );
	Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $repeat, 'fixture_reimport_cleanup' );
	$assert( 'attachment' === get_post_type( $id ), 'Reimport rollback must not remove reused media.' );
} finally {
	update_option( 'site_icon', $previous_icon );
	remove_filter( 'image_editor_output_format', $opaque_conversion );
	remove_image_size( 'ssi-record-proof' );
	if ( is_array( $receipt ) ) {
		Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $receipt, 'fixture_cleanup' );
	}
}
$assert( null === get_post( $id ), 'Rollback must delete newly owned attachment and its files.' );
// A record that lies about decoded geometry must fail at the upload boundary,
// not create a plausible attachment from unverified bytes.
$invalid = $compiled['args'];
$source_path = array_key_first( $invalid['image_asset_records'] );
$invalid['image_asset_records'][ $source_path ]['original']['width'] = 999;
$invalid['slug'] = $slug . '-invalid';
$failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $invalid );
$assert( 'completed' !== $failed['status'] && empty( $failed['transaction']->state['applied']['attachments'] ), 'Mismatched original dimensions must fail before upload.' );
echo "Capture image record import passed.\n";
