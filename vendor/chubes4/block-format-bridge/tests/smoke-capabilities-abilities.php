<?php
/**
 * Smoke coverage for capability report and Abilities API registration.
 *
 * @package BlockFormatBridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'BFB_VERSION' ) ) {
	define( 'BFB_VERSION', 'test-version' );
}
if ( ! defined( 'BFB_PATH' ) ) {
	define( 'BFB_PATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['bfb_smoke_abilities'] = array();
$GLOBALS['bfb_smoke_ability_categories'] = array();

function bfb_capabilities_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function __( string $text, string $domain = '' ): string {
	unset( $domain );
	return $text;
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

function doing_action( string $hook_name ): bool {
	return 'wp_abilities_api_init' === $hook_name;
}

function did_action( string $hook_name ): int {
	unset( $hook_name );
	return 0;
}

function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );

	if ( 'wp_abilities_api_categories_init' === $hook_name && is_callable( $callback ) ) {
		$callback();
	}
}

function do_action( string $hook_name, ...$args ): void {
	unset( $hook_name, $args );
}

function apply_filters( string $hook_name, $value ) {
	unset( $hook_name );
	return $value;
}

function current_user_can( string $capability ): bool {
	return 'read' === $capability;
}

function wp_register_ability( string $name, array $config ): void {
	$GLOBALS['bfb_smoke_abilities'][ $name ] = $config;
}

function wp_register_ability_category( string $slug, array $config ): void {
	$GLOBALS['bfb_smoke_ability_categories'][ $slug ] = $config;
}

function parse_blocks( string $content ): array {
	return array(
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $content,
			'innerContent' => array( $content ),
		),
	);
}

function serialize_blocks( array $blocks ): string {
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

function serialize_block( array $block ): string {
	return '<!-- wp:' . $block['blockName'] . ' -->' . $block['innerHTML'] . '<!-- /wp:' . $block['blockName'] . ' -->';
}

function render_block( array $block ): string {
	return (string) $block['innerHTML'];
}

function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

class WP_Error {
	private string $code;
	private string $message;
	private $data;

	public function __construct( string $code, string $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

require_once __DIR__ . '/../includes/interface-bfb-format-adapter.php';
require_once __DIR__ . '/../includes/class-bfb-adapter-registry.php';
require_once __DIR__ . '/../includes/class-bfb-html-adapter.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/normalization.php';
require_once __DIR__ . '/../includes/abilities.php';

BFB_Adapter_Registry::reset();
BFB_Adapter_Registry::register( new BFB_HTML_Adapter() );

$report = bfb_capabilities();
bfb_capabilities_smoke_assert( 'test-version' === $report['bridge']['version'], 'Capability report should include BFB version.' );
bfb_capabilities_smoke_assert( isset( $report['formats']['blocks'] ), 'Capability report should expose the block pivot format.' );
bfb_capabilities_smoke_assert( isset( $report['formats']['html'] ), 'Capability report should expose registered adapters.' );
bfb_capabilities_smoke_assert( false === $report['formats']['html']['pivot'], 'Adapter formats should not be marked as pivot formats.' );
bfb_capabilities_smoke_assert( isset( $report['conversions']['html_to_blocks'] ), 'Capability report should expose HTML to blocks availability.' );
bfb_capabilities_smoke_assert( 'h2bc_capability_api_missing' === $report['block_coverage']['source'], 'Capability report should identify the missing h2bc capability API.' );
bfb_capabilities_smoke_assert( 'https://github.com/chubes4/html-to-blocks-converter/issues/418' === $report['block_coverage']['requires'], 'Capability report should point at the h2bc capability API dependency.' );
bfb_capabilities_smoke_assert( in_array( 'bfb_html_to_blocks_args', $report['hooks']['filters'], true ), 'Capability report should list HTML raw-handler args filter.' );
bfb_capabilities_smoke_assert( in_array( 'bfb_diagnostic', $report['hooks']['actions'], true ), 'Capability report should list observability hooks.' );
bfb_capabilities_smoke_assert( in_array( 'block-format-bridge/get-capabilities', $report['abilities'], true ), 'Capability report should list the capabilities ability.' );
bfb_capabilities_smoke_assert( in_array( 'block-format-bridge/convert', $report['abilities'], true ), 'Capability report should list the convert ability.' );
bfb_capabilities_smoke_assert( in_array( 'block-format-bridge/normalize', $report['abilities'], true ), 'Capability report should list the normalize ability.' );

$abilities = $GLOBALS['bfb_smoke_abilities'];
$categories = $GLOBALS['bfb_smoke_ability_categories'];
bfb_capabilities_smoke_assert( isset( $categories['block-format-bridge'] ), 'BFB ability category should be registered.' );
bfb_capabilities_smoke_assert( isset( $categories['block-format-bridge']['label'] ), 'BFB ability category should include a label.' );
bfb_capabilities_smoke_assert( isset( $abilities['block-format-bridge/get-capabilities'] ), 'Capabilities ability should be registered.' );
bfb_capabilities_smoke_assert( isset( $abilities['block-format-bridge/convert'] ), 'Convert ability should be registered.' );
bfb_capabilities_smoke_assert( isset( $abilities['block-format-bridge/normalize'] ), 'Normalize ability should be registered.' );
bfb_capabilities_smoke_assert( 'block-format-bridge' === $abilities['block-format-bridge/get-capabilities']['category'], 'Capabilities ability should declare the BFB category.' );
bfb_capabilities_smoke_assert( 'block-format-bridge' === $abilities['block-format-bridge/convert']['category'], 'Convert ability should declare the BFB category.' );
bfb_capabilities_smoke_assert( 'block-format-bridge' === $abilities['block-format-bridge/normalize']['category'], 'Normalize ability should declare the BFB category.' );
bfb_capabilities_smoke_assert( true === $abilities['block-format-bridge/get-capabilities']['meta']['show_in_rest'], 'Capabilities ability should opt into REST exposure.' );
bfb_capabilities_smoke_assert( isset( $abilities['block-format-bridge/convert']['input_schema']['properties']['options'] ), 'Convert ability should accept conversion options.' );

$capability_callback = $abilities['block-format-bridge/get-capabilities']['execute_callback'];
$ability_report      = $capability_callback( array() );
bfb_capabilities_smoke_assert( isset( $ability_report['formats']['html'] ), 'Capabilities ability should call the PHP report helper.' );

$convert_callback = $abilities['block-format-bridge/convert']['execute_callback'];
$converted        = $convert_callback(
	array(
		'content' => 'same',
		'from'    => 'markdown',
		'to'      => 'markdown',
	)
);
bfb_capabilities_smoke_assert( true === $converted['success'], 'Convert ability should return a success envelope.' );
bfb_capabilities_smoke_assert( 'same' === $converted['content'], 'Convert ability should call bfb_convert().' );

$normalize_callback = $abilities['block-format-bridge/normalize']['execute_callback'];
$normalized         = $normalize_callback(
	array(
		'content' => "Line\r\nTwo",
		'format'  => 'markdown',
	)
);
bfb_capabilities_smoke_assert( true === $normalized['success'], 'Normalize ability should return a success envelope.' );
bfb_capabilities_smoke_assert( "Line\nTwo" === $normalized['content'], 'Normalize ability should call bfb_normalize().' );

$normalize_error = $normalize_callback(
	array(
		'content' => 'content',
		'format'  => 'missing-format',
	)
);
bfb_capabilities_smoke_assert( false === $normalize_error['success'], 'Normalize ability should surface WP_Error failures.' );
bfb_capabilities_smoke_assert( 'bfb_unknown_format' === $normalize_error['error']['code'], 'Normalize ability should preserve WP_Error codes.' );

echo "PASS: capability report and abilities contract\n";
