<?php
/** Attachment lookup boundary for the registered-block save regression. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone lookup-boundary stub.
function wp_get_attachment_url( int $id ): string { return 'https://example.test/uploads/' . $id . '.png'; }
function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES ); }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function serialize_block_attributes( array $attrs ): string { return json_encode( $attrs, JSON_UNESCAPED_SLASHES ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone serialization-boundary stub.

/** HTML API boundary for this standalone save-shape fixture; real WP tests cover binding. */
class WP_HTML_Tag_Processor {
	private DOMDocument $document;
	private ?DOMElement $image = null;
	private string $original = '';
	public function __construct( private string $html ) {
		$this->document = new DOMDocument();
	}
	public function next_tag( string $name ): bool {
		if ( 'IMG' !== $name || ! preg_match( '/<img\b[^>]*>/i', $this->html, $match ) ) {
			return false;
		}
		$this->original = $match[0];
		$this->document->loadHTML( $this->original, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$this->image = $this->document->getElementsByTagName( 'img' )->item( 0 );
		return null !== $this->image;
	}
	public function get_attribute( string $name ): string { return $this->image->getAttribute( $name ); }
	public function set_attribute( string $name, string $value ): void { $this->image->setAttribute( $name, $value ); }
	public function class_list(): array { return array_filter( preg_split( '/\s+/', trim( $this->get_attribute( 'class' ) ) ) ); }
	public function remove_class( string $class ): void { $this->set_attribute( 'class', implode( ' ', array_diff( $this->class_list(), array( $class ) ) ) ); }
	public function add_class( string $class ): void { $this->set_attribute( 'class', implode( ' ', array_unique( array_merge( $this->class_list(), array( $class ) ) ) ) ); }
	public function get_updated_html(): string { return str_replace( $this->original, $this->document->saveHTML( $this->image ), $this->html ); }
}

$source_file = getenv( 'SSI_MEDIA_LIBRARY_SOURCE' );
require false !== $source_file && '' !== $source_file ? $source_file : dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-media-library-materializer.php';

$markup        = stream_get_contents( STDIN );
$theme_uri     = 'https://example.test/themes/source';
$state         = array();
$attachments   = array(
	'photo.png' => 7,
	'child.png' => 8,
);
$by_hash       = array();
$report        = array( 'replaceable_media_count' => 0 );
$bound         = 0;
$binding_error = null;
$image_method  = new ReflectionMethod( Static_Site_Importer_Media_Library_Materializer::class, 'bind_image_block' );
$markup        = preg_replace_callback(
	'/<!--\s+wp:image(\s+\{.*?\})?\s+-->(.*?)<!--\s+\/wp:image\s+-->/s',
	static function ( array $block_match ) use ( $image_method, $theme_uri, &$state, &$attachments, &$by_hash, &$report, &$bound, &$binding_error ): string {
		return $image_method->invokeArgs( null, array( $block_match, $theme_uri, __DIR__, &$state, &$attachments, &$by_hash, &$report, &$bound, &$binding_error ) );
	},
	$markup
);
$method        = new ReflectionMethod( Static_Site_Importer_Media_Library_Materializer::class, 'bind_referenced_images' );
$materialized  = $method->invokeArgs( null, array( $markup, $theme_uri, __DIR__, &$state, &$attachments, &$by_hash, &$report, &$bound, &$binding_error ) );
echo $materialized; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw serialized markup is the test protocol.
