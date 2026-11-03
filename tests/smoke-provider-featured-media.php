<?php
/** Provider featured media reuses a plan attachment without a theme copy. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function parse_blocks( $content ) { return array(); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_insert_attachment( ...$args ) { throw new RuntimeException( 'Must reuse the resolved attachment.' ); }
function get_post_field( $field, $id ) { return '<!-- wp:paragraph --><p>Sample detail.</p><!-- /wp:paragraph -->'; }
function set_post_thumbnail( $post_id, $attachment_id ) { $GLOBALS['thumbnails'][ $post_id ] = $attachment_id; }
function get_post_thumbnail_id( $post_id ) { return $GLOBALS['thumbnails'][ $post_id ] ?? 0; }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-media-library-materializer.php';
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$source = 'website/details/sample/index.html';
$target = 'assets/website/media/sample.avif';
$token = '{{wordpress-site-plan:asset:sample}}';
$state = array(
	'theme' => array( 'uri' => 'https://example.test/wp-content/themes/sample' ),
	'theme_dir' => __DIR__ . '/missing-provider-featured-theme',
	'args' => array( 'native_site_identity_evidence' => array( 'icon' => array( 'id' => 99 ) ) ),
	'ordered_pages' => array( array(
		'source_path' => $source, 'provider_owned' => true, 'skip_materialization' => true,
		'provider_featured_image_target_path' => $target,
		// A conflicting metadata token must not override the provider claim.
		'metadata' => array( 'featured_image' => array( 'asset_token' => 'other', 'target_path' => 'assets/other.png' ) ),
	) ),
	'source_ids' => array( $source => 101 ),
	'plan' => array( 'assets' => array( array( 'target_path' => $target, 'token' => $token, 'delivery' => 'media-library' ) ) ),
	'resolved' => array( 'resolution' => array( 'attachment_resolutions' => array( $token => array( 'id' => 707 ), 'other' => array( 'id' => 808 ) ) ) ),
	'diagnostics' => array(),
);
$assert( ! file_exists( $state['theme_dir'] . '/' . $target ), 'Fixture must have no theme copy.' );
$result = Static_Site_Importer_Media_Library_Materializer::materialize( $state );
$assert( ! is_wp_error( $result ), 'Provider featured media failed: ' . ( is_wp_error( $result ) ? $result->code : '' ) );
$assert( 707 === get_post_thumbnail_id( 101 ), 'Provider target must resolve to its plan attachment.' );
$assert( 0 === $result['attachment_count'], 'Reusing media must not create attachments.' );
// The metadata handoff uses the same attachment-resolution path.
$metadata = $state;
unset( $metadata['ordered_pages'][0]['provider_featured_image_target_path'] );
$metadata['ordered_pages'][0]['metadata']['featured_image'] = array( 'asset_token' => $token, 'target_path' => $target );
$GLOBALS['thumbnails'] = array();
$result = Static_Site_Importer_Media_Library_Materializer::materialize( $metadata );
$assert( ! is_wp_error( $result ) && 707 === get_post_thumbnail_id( 101 ), 'Metadata handoff must still reuse the resolved attachment.' );
// An unresolved provider claim reports its own page, not a previous page.
$failed = $state;
$failed['resolved']['resolution']['attachment_resolutions'] = array();
$result = Static_Site_Importer_Media_Library_Materializer::materialize( $failed );
$assert( is_wp_error( $result ) && 'provider_featured_image_unresolved' === $result->code, 'Missing provider media must still fail.' );
$assert( $source === ( $failed['diagnostics'][0]['source_path'] ?? null ), 'Failure diagnostic must identify the provider page.' );
echo "PASS provider featured Media Library attachment resolution\n";
