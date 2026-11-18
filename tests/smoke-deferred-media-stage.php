<?php
/**
 * Capture intake and resumable media stage end-to-end smoke.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require_once dirname( __DIR__ ) . '/static-site-importer.php';
require_once dirname( __DIR__ ) . '/includes/cli.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$assert = static function ( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$root = realpath( sys_get_temp_dir() ) . '/ssi-deferred-' . wp_generate_password( 8, false, false );
$slug = strtolower( basename( $root ) );
wp_mkdir_p( $root . '/website' );
$entries = array();
$html = '<html><body><main><h1>Deferred capture</h1>';
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC' );
foreach ( array( 'a', 'b', 'c', 'missing' ) as $name ) {
	$path = 'deferred-media/' . $name . '/image.png';
	$entries[] = array( 'path' => $path, 'status' => 'missing' === $name ? 'failed' : 'done', 'url' => 'https://source.example/' . $name . '.png', 'originalUrl' => 'https://source.example/' . $name . '.png' );
	$html .= '<img src="' . $path . '" alt="' . $name . '">';
	if ( 'missing' !== $name ) {
		wp_mkdir_p( dirname( $root . '/website/' . $path ) );
		file_put_contents( $root . '/website/' . $path, $png . str_repeat( "\0", 4096 ) );
	}
}
$html .= '</main></body></html>';
file_put_contents( $root . '/media-queue.json', wp_json_encode( array( 'schema' => 'data-liberation-agent/media-queue/v1', 'entries' => $entries ) ) );
file_put_contents( $root . '/website/index.html', $html );
$receipt = null;
try {
	$limit_filter = static function ( array $limits ): array {
		$limits['max_media_total_bytes'] = 1024;
		return $limits;
	};
	add_filter( 'static_site_importer_cli_request_bundle_limits', $limit_filter );
	$bundle = static_site_importer_cli_request_bundle_files( $root . '/website' );
	remove_filter( 'static_site_importer_cli_request_bundle_limits', $limit_filter );
	$assert( ! is_wp_error( $bundle ), 'Deferred files must not exhaust intake limits.' );
	$assert( array( 'index.html' ) === array_column( $bundle['files'], 'path' ), 'Deferred media must never enter compilation or theme copying.' );
	$plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( array( 'path' => 'index.html', 'content' => $bundle['files'][0]['content'] ) ) ) )->toArray()['source_reports']['wordpress_site_plan'];
	$args = array( 'slug' => $slug, 'name' => 'Deferred capture', 'activate' => false, 'source_metadata' => array( 'deferred_media' => array( 'root' => $root ) ) );
	$prepared = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $args );
	$assert( 'prepared' === $prepared['status'], 'Content preparation succeeds.' );
	$receipt = Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( $prepared );
	$assert( 'completed' === $receipt['status'], 'Content import succeeds.' );
	$assert( 4 === $receipt['media']['pending'], 'Import receipt counts deferred entries.' );
	$assert( str_contains( (string) get_post_field( 'post_content', $receipt['wordpress'][0]['id'] ), 'https://source.example/a.png' ), 'Before the media stage, content shows the source image.' );
	$ref = $receipt['media']['ref'];
	$before = (int) wp_count_posts( 'attachment' )->inherit;
	$first = Static_Site_Importer_Deferred_Media_Stage::run( array( 'ref' => $ref, 'batch' => 1 ) );
	$assert( 3 === $first['pending'], 'A bounded first batch can stop safely.' );
	// Simulate interruption after attachment and post writes but before journal publication.
	$journal = get_option( $ref );
	$journal['entries'] = array();
	update_option( $ref, $journal, false );
	$second = Static_Site_Importer_Deferred_Media_Stage::run( array( 'ref' => $ref, 'batch' => 20 ) );
	$assert( 0 === $second['pending'], 'Resume drains the queue.' );
	$again = Static_Site_Importer_Deferred_Media_Stage::run( array( 'ref' => $ref ) );
	wp_cache_delete( _count_posts_cache_key( 'attachment', '' ), 'counts' );
	$assert( $again === $second && $before + 3 === (int) wp_count_posts( 'attachment' )->inherit, 'Resume and replay create no duplicate attachments.' );
	$assert( 3 === $second['imported'] && array( 'deferred-media/missing/image.png' ) === array_keys( $second['failed'] ), 'The receipt counts imports and names each failure once.' );
	$assert( 'source_failed' === $second['failed']['deferred-media/missing/image.png']['reason'], 'A source failure keeps its reason.' );
	$content = (string) get_post_field( 'post_content', $receipt['wordpress'][0]['id'] );
	$assert( ! str_contains( $content, 'deferred-media/' ), 'No deferred path reaches imported content.' );
	foreach ( get_option( $ref )['entries'] as $path => $entry ) {
		$assert( str_contains( $content, $entry['url'] ), 'Content uses the attachment URL, or the original URL for a failure: ' . $path );
		if ( $entry['attachment_id'] ) {
			$assert( str_contains( $content, '"id":' . $entry['attachment_id'] ) && str_contains( $content, 'wp-image-' . $entry['attachment_id'] ), 'Image block binds the attachment ID in attributes and markup.' );
		}
	}
	$assert( str_contains( $content, 'https://source.example/missing.png' ), 'A failed entry keeps its source URL.' );
	echo "Deferred media stage smoke passed.\n";
} finally {
	if ( isset( $ref ) ) {
		$journal = get_option( $ref, array() );
		foreach ( $journal['entries'] ?? array() as $entry ) {
			if ( $entry['attachment_id'] ) {
				wp_delete_attachment( $entry['attachment_id'], true );
			}
		}
		delete_option( $ref );
	}
	foreach ( $receipt['wordpress'] ?? array() as $post ) {
		wp_delete_post( $post['id'], true );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	$wp_filesystem->delete( $root, true );
	$wp_filesystem->delete( get_theme_root() . '/' . $slug, true );
}
