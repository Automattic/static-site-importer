<?php
/**
 * Smoke coverage for generated SVG Media Library materialization.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$root = sys_get_temp_dir() . '/ssi-media-materializer-' . uniqid( '', true );
$attachments = array();

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
function sanitize_title( string $value ): string {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ?? '', '-' );
}
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}
function wp_mkdir_p( string $directory ): bool {
	return is_dir( $directory ) || mkdir( $directory, 0777, true );
}
function wp_upload_dir(): array {
	global $root;
	return array(
		'basedir' => $root,
		'baseurl' => 'https://example.test/wp-content/uploads',
		'error'   => false,
	);
}
function attachment_url_to_postid( string $url ): int {
	global $attachments;
	foreach ( $attachments as $id => $attachment ) {
		if ( $url === $attachment['url'] ) {
			return $id;
		}
	}
	return 0;
}
function wp_insert_attachment( array $postarr, string $file, int $parent, bool $wp_error ) {
	global $attachments;
	unset( $parent, $wp_error );
	$id = count( $attachments ) + 1;
	$attachments[ $id ] = array(
		'file' => $file,
		'mime' => $postarr['post_mime_type'],
		'url'  => 'https://example.test/wp-content/uploads/static-site-importer/' . rawurlencode( basename( $file ) ),
	);
	return $id;
}
function wp_get_attachment_url( int $id ): string {
	global $attachments;
	return (string) ( $attachments[ $id ]['url'] ?? '' );
}
function update_attached_file( int $id, string $file ): void {
	unset( $id, $file );
}
function update_post_meta( int $id, string $key, string $value ): void {
	unset( $id, $key, $value );
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}
class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-media-materializer.php';

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/></svg>';
$path = 'website/assets/materialized-svg/status.svg';
$old_url = 'https://example.test/wp-content/themes/generated/assets/materialized/' . $path;
$artifacts = array(
	'site' => array(
		'assets' => array(
			array(
				'path'               => $path,
				'mime_type'          => 'image/svg+xml',
				'content'            => $svg,
				'source_role'        => 'importer_owned',
				'pipeline_sanitized' => true,
			),
			array(
				'path'               => $path,
				'mime_type'          => 'image/svg+xml',
				'content'            => $svg,
				'source_role'        => 'importer_owned',
				'pipeline_sanitized' => true,
			),
			array(
				'path'        => 'website/unsafe.svg',
				'mime_type'   => 'image/svg+xml',
				'content'     => '<svg><script>alert(1)</script></svg>',
				'source_role' => 'canonical',
			),
		),
	),
);
$result = Static_Site_Importer_Media_Materializer::materialize_sanitized_svgs(
	$artifacts,
	array( $path => array( 'final_url' => $old_url ) )
);

$failures = array();
$assert = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$assert( is_array( $result ), 'materialization-succeeds' );
$assert( 1 === count( $result['attachments'] ?? array() ), 'only-unique-sanitized-importer-owned-svg-attached' );
$assert( 'image/svg+xml' === ( $result['attachments'][0]['mime_type'] ?? '' ), 'attachment-mime-recorded' );
$assert( file_exists( (string) ( $attachments[1]['file'] ?? '' ) ), 'sanitized-svg-written-to-uploads' );
$rewritten = Static_Site_Importer_Media_Materializer::rewrite_block_media( '<!-- wp:paragraph --><p><img src="' . $old_url . '" /></p><!-- /wp:paragraph -->', $result['replacements'] ?? array() );
$assert( str_contains( $rewritten, '/wp-content/uploads/static-site-importer/' ), 'block-url-rewritten-to-attachment' );
$assert( ! str_contains( $rewritten, '/wp-content/themes/generated/' ), 'theme-asset-url-removed-from-block' );

if ( $failures ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failures ) . "\n" );
	exit( 1 );
}

echo "PASS smoke-media-materializer.php (6 assertions)\n";
