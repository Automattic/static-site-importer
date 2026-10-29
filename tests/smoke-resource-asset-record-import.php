<?php
/**
 * Capture resource record import through public compilation and persistence, in WordPress.
 *
 * Documents and video become Media Library attachments bound to every
 * recorded reference; captured fonts stay theme-owned behind theme.json
 * fontFace, and every font byte verifies before the theme write.
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
$slug     = 'ssi-resource-' . strtolower( wp_generate_password( 8, false, false ) );
$artifact = json_decode( file_get_contents( __DIR__ . '/fixtures/resource-asset-capture/artifact.json' ), true );
$receipts = array();
$args     = array( 'slug' => $slug, 'name' => 'Resource record fixture', 'activate' => false, 'defer_materialization_commit' => true );
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
$assert( ! is_wp_error( $compiled ), 'Compilation failed: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$plan    = $compiled['plan'];
$records = $compiled['args']['image_asset_records'];
$assert( 8 === count( $records ), 'The fixture must carry eight resource records.' );
$fonts = array_filter( $records, static fn( array $record ): bool => 'font' === Static_Site_Importer_Image_Assets::kind( $record ) );
$assert( 4 === count( $fonts ), 'Captured fonts must stay theme-owned records.' );

$receipt = null;
try {
	$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $compiled['args'] );
	$assert( 'completed' === $receipt['status'], 'Import failed: ' . wp_json_encode( $receipt['failure_reason'] ?? $receipt['status'] ) );
	$receipts[] = $receipt;
	$state      = $receipt['transaction']->state;

	// One attachment per recorded document/video/image original.
	$by_record_url = array();
	foreach ( $records as $record ) {
		$by_record_url[ $record['original']['source_url'] ] = $record;
	}
	$assets = array();
	foreach ( $receipt['completed']['image_assets'] ?? array() as $entry ) {
		$assets[ $entry['original']['source_url'] ] = $entry;
	}
	$assert( 4 === count( $assets ), 'Public receipt must map the four attachment-backed resources: ' . count( $assets ) );
	$urls = array();
	foreach ( $assets as $source_url => $entry ) {
		$record = $by_record_url[ $source_url ] ?? null;
		$assert( is_array( $record ), "Receipt must project a recorded resource: $source_url." );
		$mime = $record['original']['content_type'];
		$assert( 'font' !== Static_Site_Importer_Image_Assets::kind( $record ), 'Theme-owned fonts must not claim attachment destinations.' );
		$id  = (int) $entry['destination']['attachment_id'];
		$url = (string) $entry['destination']['url'];
		$assert( $id > 0 && '' !== $url, 'Receipt destination must carry attachment id and URL.' );
		$file = get_attached_file( $id );
		$assert( is_string( $file ) && is_file( $file ), 'Attachment must own an uploaded file.' );
		$assert( hash_file( 'sha256', $file ) === $record['original']['sha256'], 'Original bytes must survive unchanged.' );
		$assert( get_post_mime_type( $id ) === $mime, "Attachment must carry the verified $mime." );
		$assert( $record === array_intersect_key( (array) get_post_meta( $id, Static_Site_Importer_Media_Library_Materializer::SOURCE_RECORD_META_KEY, true ), $record ), 'The capture record must remain on the attachment.' );
		$metadata = wp_get_attachment_metadata( $id );
		$assert( is_array( $metadata ) && isset( $metadata['filesize'] ) && ( 'image/png' === $mime ? $metadata['width'] > 0 : ! isset( $metadata['width'] ) ), 'Attachment metadata must fit its kind.' );
		$urls[ strtok( $source_url, '?' ) ] = $url;
	}

	// Page content: every content reference resolves to the attachment URL.
	$content = get_post_field( 'post_content', reset( $state['source_ids'] ) );
	foreach ( $urls as $source_url => $url ) {
		$assert( str_contains( $content, $url ), "Page content must reference the attachment for $source_url." );
	}
	$assert( ! str_contains( $content, 'wordpress-site-plan:asset' ), 'No plan token may survive in materialized page content.' );

	// Navigation: the menu link to the recorded document must resolve too.
	$nav_post = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
	$assert( ! empty( $nav_post ), 'The captured navigation must become a wp_navigation entity.' );
	$nav_content = get_post_field( 'post_content', $nav_post[0] );
	$assert( str_contains( $nav_content, $urls['https://example.org/terms.pdf'] ), 'Navigation must link the recorded document attachment.' );
	// Captured fonts stay theme-owned: bytes on disk match the records.
	$font_files = array();
	foreach ( $state['resolved']['writes'] as $write ) {
		if ( 'theme_asset' !== ( $write['kind'] ?? '' ) ) {
			continue;
		}
		$path = $state['theme_dir'] . '/' . $write['target_path'];
		if ( ! is_file( $path ) ) {
			continue;
		}
		foreach ( $fonts as $font ) {
			if ( hash_file( 'sha256', $path ) === $font['original']['sha256'] ) {
				$font_files[] = $path;
			}
		}
	}
	$assert( 4 === count( $font_files ), 'All four captured font files must be written into the theme: ' . count( $font_files ) );

	$theme_json_path = $state['theme_dir'] . '/theme.json';
	$assert( is_file( $theme_json_path ), 'The theme must ship theme.json.' );
	$theme_json = json_decode( (string) file_get_contents( $theme_json_path ), true );
	$faces      = $theme_json['settings']['typography']['fontFamilies'][0]['fontFace'] ?? array();
	$assert( 2 === count( $faces ), 'theme.json must declare the captured font faces.' );
	foreach ( $faces as $face ) {
		$assert( str_starts_with( (string) ( $face['src'] ?? '' ), 'file:./' ) && is_file( $state['theme_dir'] . '/' . substr( (string) $face['src'], 7 ) ), 'Each fontFace src must own its theme file.' );
		$assert( 'Garden Sans' === ( $face['family'] ?? '' ), 'fontFace family must come from the captured descriptor.' );
	}

	$font_css = '';
	foreach ( $state['resolved']['writes'] as $write ) {
		if ( str_ends_with( $write['target_path'], 'fonts.css' ) ) {
			$font_css = (string) file_get_contents( $state['theme_dir'] . '/' . $write['target_path'] );
		}
	}
	$assert( '' !== $font_css && ! str_contains( $font_css, 'wordpress-site-plan:asset' ), 'Imported @font-face CSS must resolve its sources.' );
	foreach ( $fonts as $font ) {
		$basename = basename( (string) wp_parse_url( $font['original']['source_url'], PHP_URL_PATH ) );
		// CSS reference resolution emits stylesheet-relative URLs; the bytes above prove the theme owns the file.
		$assert( str_contains( $font_css, "url('media/$basename')" ), "The @font-face src must reference the theme-owned $basename." );
	}

	// A record that lies about its bytes must fail before anything is written.
	$lying = $compiled['args'];
	$lying_fonts = array_keys( array_filter( $lying['image_asset_records'], static fn( array $record ): bool => 'font' === Static_Site_Importer_Image_Assets::kind( $record ) ) );
	$lying['image_asset_records'][ $lying_fonts[0] ]['original']['sha256'] = str_repeat( 'be', 32 );
	$lying['slug'] = $slug . '-lying';
	$failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $lying );
	$failed_codes = array_column( $failed['errors'] ?? array(), 'code' );
	$assert( 'completed' !== ( $failed['status'] ?? '' ) && in_array( 'image_resource_unresolved', $failed_codes, true ), 'A mismatched font digest must fail with the capture-record integrity error.' );
	$assert( ! Static_Site_Importer_Image_Assets::verify_bytes( (string) file_get_contents( $font_files[0] ), $lying['image_asset_records'][ $lying_fonts[0] ] ), 'The same bytes must fail the lying record; verification is digest-bound.' );

	// Reimport reuses every attachment without journaling new ones.
	$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array_merge( $compiled['args'], array( 'overwrite' => true ) ) );
	$assert( 'completed' === $repeat['status'], 'Reimport failed.' );
	$receipts[] = $repeat;
	$repeat_ids = array_column( array_column( $repeat['completed']['image_assets'] ?? array(), 'destination' ), 'attachment_id' );
	$planned    = array_column( array_column( $receipt['completed']['image_assets'] ?? array(), 'destination' ), 'attachment_id' );
	sort( $repeat_ids );
	sort( $planned );
	$assert( $repeat_ids === $planned, 'Reimport must reuse the same attachment destinations.' );
	$assert( 0 === count( array_intersect( $repeat['transaction']->state['applied']['attachments'] ?? array(), $planned ) ), 'Reimport must not journal reused attachments as new.' );
} finally {
	foreach ( $receipts as $receipt ) {
		Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $receipt, 'fixture_cleanup' );
	}
}
echo "Capture resource record import passed.\n";
