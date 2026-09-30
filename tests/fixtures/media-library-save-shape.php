<?php
/** Attachment lookup boundary for the registered-block save regression. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {}
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); }
function wp_get_attachment_url( int $id ): string { return 'https://example.test/uploads/' . $id . '.png'; }
function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function serialize_block_attributes( array $attrs ): string { return json_encode( $attrs, JSON_UNESCAPED_SLASHES ); }

require getenv( 'SSI_MEDIA_LIBRARY_SOURCE' ) ?: dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-media-library-materializer.php';

$markup      = stream_get_contents( STDIN );
$theme_uri   = 'https://example.test/themes/source';
$state       = array();
$attachments = array( 'photo.png' => 7, 'child.png' => 8 );
$by_hash     = array();
$report      = array( 'replaceable_media_count' => 0 );
$bound       = 0;
$error       = null;
$image_method = new ReflectionMethod( Static_Site_Importer_Media_Library_Materializer::class, 'bind_image_block' );
$markup = preg_replace_callback(
	'/<!--\s+wp:image(\s+\{.*?\})?\s+-->(.*?)<!--\s+\/wp:image\s+-->/s',
	static function ( array $match ) use ( $image_method, $theme_uri, &$state, &$attachments, &$by_hash, &$report, &$bound, &$error ): string {
		return $image_method->invokeArgs( null, array( $match, $theme_uri, __DIR__, &$state, &$attachments, &$by_hash, &$report, &$bound, &$error ) );
	},
	$markup
);
$method      = new ReflectionMethod( Static_Site_Importer_Media_Library_Materializer::class, 'bind_referenced_images' );
echo $method->invokeArgs( null, array( $markup, $theme_uri, __DIR__, &$state, &$attachments, &$by_hash, &$report, &$bound, &$error ) );
