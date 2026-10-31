<?php
/**
 * WordPress-runtime regression: a route must not adopt an image attachment.
 *
 * Run: wp eval-file tests/smoke-route-attachment-collision.php
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
require_once dirname( __DIR__ ) . '/static-site-importer.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$slug   = 'ssi-route-image-' . strtolower( wp_generate_password( 8, false, false ) );
$title  = $slug;
$bytes  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC' );
$upload = wp_upload_bits( $slug . '.png', null, $bytes );
$assert( empty( $upload['error'] ), 'Fixture upload must succeed.' );
$attachment_id = wp_insert_attachment(
	array(
		'post_title'     => $title,
		'post_name'      => $slug,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	$upload['file']
);
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
update_post_meta( $attachment_id, '_static_site_importer_source_asset', $slug . '#' . hash( 'sha256', $bytes ) );
$file_meta = get_post_meta( $attachment_id, '_wp_attached_file', true );
$metadata  = wp_get_attachment_metadata( $attachment_id );
$post_ids  = array();
try {
	$plan = ( new ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'index.html',
			'files'      => array(
				array( 'path' => 'index.html', 'content' => '<main><h1>Home</h1></main>' ),
				array( 'path' => $slug . '.html', 'content' => '<html><head><meta property="article:published_time" content="2024-03-12T10:00:00Z"></head><body><main><h1>' . $title . '</h1><img src="photo.png" alt="' . $title . '"></main></body></html>' ),
				array( 'path' => 'photo.png', 'content_base64' => base64_encode( $bytes ) ),
			),
		)
	)->toArray()['source_reports']['wordpress_site_plan'];
	$assert( $attachment_id === get_page_by_path( $slug, OBJECT, 'post' )->ID, 'Fixture must reproduce core attachment fallback before materialization.' );
	$result   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => $slug ) );
	$post_ids = $result['completed']['pages'] ?? $result['pages'] ?? array();
	$assert( 'completed' === ( $result['status'] ?? '' ), 'Materialization must complete: ' . wp_json_encode( $result['diagnostics'] ?? array() ) );
	$post_id = (int) ( $post_ids[ $slug . '.html' ] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	$assert( $post instanceof WP_Post && 'post' === $post->post_type && $post_id !== $attachment_id, 'Post must be created as a separate row.' );
	$assert( 'attachment' === get_post_type( $attachment_id ), 'Image must remain an attachment.' );
	$assert( $file_meta === get_post_meta( $attachment_id, '_wp_attached_file', true ) && is_file( get_attached_file( $attachment_id ) ), 'Attachment file must survive.' );
	$assert( $metadata === wp_get_attachment_metadata( $attachment_id ), 'Attachment metadata must survive.' );
	$assert( $title === get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 'Image alt text must survive.' );
	$assert( str_contains( $post->post_content, '"id":' . $attachment_id ), 'Image block must retain the attachment ID.' );
	$assert( str_contains( do_blocks( $post->post_content ), wp_get_attachment_url( $attachment_id ) ), 'Rendered blocks must still display the image.' );
} finally {
	foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $id ) {
		if ( $attachment_id !== $id ) {
			wp_delete_post( $id, true );
		}
	}
	wp_delete_attachment( $attachment_id, true );
}
echo "OK: route attachment collision smoke passed\n";
