<?php
/**
 * Smoke coverage for the companion-plugin scaffolder, install/activate path, and
 * declared-dependency wiring (issue #491 slice 1).
 *
 * Run from the repository root:
 * php tests/smoke-companion-plugin.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$ssi_companion_tmp = sys_get_temp_dir() . '/ssi-companion-smoke-' . getmypid();
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', $ssi_companion_tmp . '/plugins' );
}
if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', $ssi_companion_tmp . '/mu-plugins' );
}

// Controllable plugin-activation stubs so the install path is exercised without
// a WordPress runtime. is_plugin_active reports inactive until activate_plugin
// records the activation intent.
$GLOBALS['ssi_companion_active']      = array();
$GLOBALS['ssi_companion_activated']   = array();
$GLOBALS['ssi_companion_deactivated'] = array();
$GLOBALS['ssi_companion_options']     = array();
$GLOBALS['static_site_importer_companion_block_owners'] = array();
$GLOBALS['ssi_companion_actions']     = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {
		public static array $registered = array();

		public static function get_instance(): self {
			static $instance;
			return $instance ??= new self();
		}

		public function is_registered( string $name ): bool {
			return in_array( $name, self::$registered, true );
		}
	}
}

if ( ! class_exists( 'WP_Block_Type' ) ) {
	class WP_Block_Type {
		public function __construct( public string $name ) {}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( string $content, array $allowed ): string {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $document->documentElement;
		foreach ( iterator_to_array( $root->getElementsByTagName( '*' ) ) as $element ) {
			$tag = strtolower( $element->tagName );
			if ( ! isset( $allowed[ $tag ] ) ) {
				$element->parentNode?->removeChild( $element );
				continue;
			}
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$name      = strtolower( $attribute->name );
				$permitted = isset( $allowed[ $tag ][ $name ] ) || ( str_starts_with( $name, 'aria-' ) && isset( $allowed[ $tag ]['aria-*'] ) ) || ( str_starts_with( $name, 'data-' ) && isset( $allowed[ $tag ]['data-*'] ) );
				$value     = strtolower( rawurldecode( rawurldecode( preg_replace( '/\s+/', '', html_entity_decode( $attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' ) ) );
				$unsafe    = in_array( $name, array( 'href', 'src', 'longdesc' ), true ) && preg_match( '/^(?:javascript|vbscript|file|blob|data):/', $value );
				if ( ! $permitted || $unsafe ) {
					$element->removeAttribute( $attribute->name );
				}
			}
		}
		$output = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$output .= $document->saveHTML( $child );
		}
		return $output;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $content ): string {
		$global = array(
			'aria-*' => true,
			'class'  => true,
			'data-*' => true,
			'id'     => true,
			'style'  => true,
		);
		return wp_kses(
			$content,
			array(
				'a'    => array_merge( $global, array( 'href' => true, 'rel' => true, 'target' => true ) ),
				'div'  => $global,
				'img'  => array_merge( $global, array( 'alt' => true, 'height' => true, 'src' => true, 'width' => true ) ),
				'p'    => $global,
				'span' => $global,
			)
		);
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable|string $callback ): void {
		$GLOBALS['ssi_companion_actions'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable|string $callback ): void {}
}

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( string $block, array $args = array() ): WP_Block_Type|false {
		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( '' === $name && is_file( $block . '/block.json' ) ) {
			$metadata = json_decode( (string) file_get_contents( $block . '/block.json' ), true );
			$name     = is_array( $metadata ) ? (string) ( $metadata['name'] ?? '' ) : '';
		}
		if ( '' === $name || in_array( $name, WP_Block_Type_Registry::$registered, true ) ) {
			return false;
		}
		WP_Block_Type_Registry::$registered[] = $name;
		return new WP_Block_Type( $name );
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( string $plugin_file ): bool {
		return in_array( $plugin_file, $GLOBALS['ssi_companion_active'], true );
	}
}

if ( ! function_exists( 'activate_plugin' ) ) {
	function activate_plugin( string $plugin_file ) {
		$GLOBALS['ssi_companion_active'][]    = $plugin_file;
		$GLOBALS['ssi_companion_activated'][] = $plugin_file;
		require_once WP_PLUGIN_DIR . '/' . $plugin_file;
		return null;
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( string $plugin_file ): void {
		$GLOBALS['ssi_companion_active']        = array_values( array_diff( $GLOBALS['ssi_companion_active'], array( $plugin_file ) ) );
		$GLOBALS['ssi_companion_deactivated'][] = $plugin_file;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['ssi_companion_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, bool $autoload = false ): bool {
		$GLOBALS['ssi_companion_options'][ $name ] = $value;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-plugin-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// Synthetic metadata block with a render file plus a preserved island scoped to
// that block. Generic; no fixture-specific strings.
$payload = array(
	'schema'       => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug'    => 'Example Site',
	'site_name'    => 'Example Site',
	'blocks'       => array(
		array(
			'name'       => 'custom-hero',
			'block_json' => array(
				'name'       => 'example/custom-hero',
				'title'      => 'Custom Hero',
				'category'   => 'design',
				'attributes' => array(
					'heading' => array(
						'type'    => 'string',
						'default' => '',
					),
					'content' => array(
						'type'    => 'string',
						'default' => '',
					),
					'text'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'nested'  => array(
						'type'       => 'object',
						'properties' => array(
							'caption' => array(
								'type' => 'text',
							),
						),
					),
				),
				'supports'   => array(
					'interactivity' => true,
				),
				'editorScript' => 'file:./index.js',
				'script'       => array( 'file:./script.js', 'shared-script-handle' ),
				'style'        => 'file:./style.css',
				'editorStyle'  => 'file:./editor.css',
				'viewScript'   => array( 'file:./view.js' ),
				'viewScriptModule' => array( 'file:./view-module.js' ),
				'viewStyle'       => array( 'file:./view.css' ),
				'variations'      => 'file:./variations.json',
			),
			'render'     => '<div class="ssi-hero">Example hero</div>',
			'assets'     => array(
				'index.js'   => 'window.SSIEditor = true;',
				'script.js'  => 'window.SSIScript = true;',
				'style.css'  => '.ssi-hero { color: inherit; }',
				'editor.css' => '.editor-styles-wrapper .ssi-hero { color: inherit; }',
				'view.js'    => 'window.SSIView = true;',
				'view-module.js' => 'export const SSIView = true;',
				'view.css' => '.ssi-hero { display: block; }',
				'variations.json' => '[]',
			),
			'script_dependencies' => array(
				'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ),
			),
		),
	),
	'preserved_js' => array(
		array(
			'handle'  => 'hero-island',
			'content' => 'document.addEventListener("DOMContentLoaded",function(){});',
			'block'   => 'example/custom-hero',
		),
	),
);

$assert( true === Static_Site_Importer_Companion_Plugin::validate_payload( $payload ), 'canonical-payload-validates-all-core-metadata-fields' );
$dialog_payload = array(
	'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug' => 'captured-dialog-site',
	'site_name' => 'Captured Dialog Site',
	'blocks'    => array(
		array(
			'name'       => 'captured-dialog',
			'block_json' => array(
				'apiVersion'   => 3,
				'name'         => 'ssi-captured-dialog-site/captured-dialog',
				'title'        => 'Dialog',
				'category'     => 'widgets',
				'editorScript' => 'file:./index.js',
				'viewScript'   => 'file:./view.js',
				'attributes'   => array(
					'dialogId'       => array( 'type' => 'string', 'default' => '' ),
					'triggerIds'     => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string' ) ),
					'addCloseButton' => array( 'type' => 'boolean', 'default' => false ),
				),
				'supports'     => array( 'html' => false, 'customClassName' => false ),
			),
			'view_js'   => '(function(){document.querySelectorAll("dialog[data-blocks-engine-triggers]").forEach(function(dialog){dialog.showModal();});})();',
			'assets'    => array(
				'index.js' => '(function(blocks,blockEditor,element){blocks.registerBlockType("ssi-captured-dialog-site/captured-dialog",{edit:function(){return element.createElement(blockEditor.InnerBlocks);},save:function(){return element.createElement("dialog",null,element.createElement(blockEditor.InnerBlocks.Content));}});})(window.wp.blocks,window.wp.blockEditor,window.wp.element);',
			),
			'script_dependencies' => array(
				'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ),
			),
		),
	),
	'preserved_js' => array(),
);
$dialog_validation = Static_Site_Importer_Companion_Plugin::validate_payload( $dialog_payload );
$assert( true === $dialog_validation, 'captured-dialog-payload-validates', is_wp_error( $dialog_validation ) ? $dialog_validation->get_error_message() : '' );
$dialog_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $dialog_payload );
$assert( is_array( $dialog_descriptor ), 'captured-dialog-payload-scaffolds' );
if ( is_array( $dialog_descriptor ) ) {
	$dialog_files = $dialog_descriptor['files'] ?? array();
	$dialog_block_json = (string) ( $dialog_files['ssi-captured-dialog-site/blocks/captured-dialog/block.json'] ?? '' );
	$assert( str_contains( $dialog_block_json, '"viewScript": "file:./view.js"' ), 'captured-dialog-metadata-retains-scoped-view-script' );
	$assert( str_contains( (string) ( $dialog_files['ssi-captured-dialog-site/blocks/captured-dialog/view.js'] ?? '' ), 'showModal' ), 'captured-dialog-scaffold-writes-native-dialog-behavior' );
	$assert( str_contains( (string) ( $dialog_files['ssi-captured-dialog-site/blocks/captured-dialog/index.js'] ?? '' ), 'InnerBlocks' ), 'captured-dialog-scaffold-writes-editable-inner-block-editor' );
}
$conflicting_dialog_payload = $dialog_payload;
$conflicting_dialog_payload['blocks'][0]['assets']['view.js'] = 'window.conflict = true;';
$conflicting_dialog_validation = Static_Site_Importer_Companion_Plugin::validate_payload( $conflicting_dialog_payload );
$assert( is_wp_error( $conflicting_dialog_validation ) && 'static_site_importer_companion_plugin_view_script_conflict' === $conflicting_dialog_validation->get_error_code(), 'captured-dialog-conflicting-view-script-rejected' );
$unsafe_dialog_payload = $dialog_payload;
$unsafe_dialog_payload['blocks'][0]['view_js'] = '<?php system( "id" );';
$unsafe_dialog_validation = Static_Site_Importer_Companion_Plugin::validate_payload( $unsafe_dialog_payload );
$assert( is_wp_error( $unsafe_dialog_validation ) && 'static_site_importer_companion_plugin_view_script_invalid' === $unsafe_dialog_validation->get_error_code(), 'captured-dialog-server-code-view-script-rejected' );
$missing_metadata_asset = $payload;
unset( $missing_metadata_asset['blocks'][0]['assets']['view-module.js'] );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $missing_metadata_asset ) ), 'array-metadata-file-reference-requires-declared-asset' );
$missing_render = $payload;
$missing_render['blocks'][0]['render'] = null;
$missing_render['blocks'][0]['block_json']['render'] = 'file:./render.php';
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $missing_render ) ), 'supplied-render-file-requires-declared-asset' );
$missing_variations = $payload;
unset( $missing_variations['blocks'][0]['assets']['variations.json'] );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $missing_variations ) ), 'variations-file-reference-requires-declared-asset' );
$generated_render = $payload;
$generated_render['blocks'][0]['block_json']['render'] = 'file:./missing-upstream-render.php';
$assert( true === Static_Site_Importer_Companion_Plugin::validate_payload( $generated_render ), 'scalar-render-source-generates-render-file' );
$unowned_name = $payload;
$unowned_name['blocks'][0]['block_json']['name'] = 'other-producer/unowned';
$assert( true === Static_Site_Importer_Companion_Plugin::validate_payload( $unowned_name ), 'syntactically-valid-canonical-name-is-preserved' );
$reserved_name = $payload;
$reserved_name['blocks'][0]['block_json']['name'] = 'core/paragraph';
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $reserved_name ) ), 'reserved-core-name-rejected' );
$invalid_payload = $payload;
$invalid_payload['blocks'][0]['assets']['../escape.js'] = 'unsafe';
$invalid_result = Static_Site_Importer_Companion_Plugin::validate_payload( $invalid_payload );
$assert( is_wp_error( $invalid_result ), 'invalid-payload-rejected-before-materialization' );
$invalid_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $invalid_payload );
$assert( 'failed' === ( $invalid_report['status'] ?? '' ), 'invalid-payload-prevents-file-mutations' );
$assert( ! file_exists( WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/escape.js' ), 'invalid-payload-writes-no-unsafe-file' );
$php_asset = $payload;
$php_asset['blocks'][0]['assets']['exploit.php'] = '<?php touch( "/tmp/owned" );';
$php_asset_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $php_asset );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $php_asset ) ), 'php-companion-asset-rejected' );
$assert( 'failed' === ( $php_asset_report['status'] ?? '' ) && empty( $GLOBALS['ssi_companion_activated'] ), 'php-companion-asset-cannot-reach-activation-sink' );
$php_render = $payload;
$php_render['blocks'][0]['render'] = '<?php system( "id" );';
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $php_render ) ), 'php-render-template-rejected' );
$typed_renderer = $payload;
unset( $typed_renderer['blocks'][0]['render'] );
$typed_renderer['blocks'][0]['renderer'] = 'static-site-importer/responsive-media/v1';
$typed_renderer['blocks'][0]['block_json']['attributes']['content']['type'] = 'string';
$typed_renderer['blocks'][0]['block_json']['attributes']['kind'] = array( 'type' => 'string', 'default' => 'media' );
$assert( true === Static_Site_Importer_Companion_Plugin::validate_payload( $typed_renderer ), 'known-typed-renderer-validates' );
$unknown_renderer = $typed_renderer;
$unknown_renderer['blocks'][0]['renderer'] = 'producer/arbitrary/v1';
$assert( 'static_site_importer_companion_plugin_renderer_invalid' === Static_Site_Importer_Companion_Plugin::validate_payload( $unknown_renderer )->get_error_code(), 'unknown-typed-renderer-rejected' );
$renderer_conflict = $typed_renderer;
$renderer_conflict['blocks'][0]['render'] = '<div>conflict</div>';
$assert( 'static_site_importer_companion_plugin_renderer_conflict' === Static_Site_Importer_Companion_Plugin::validate_payload( $renderer_conflict )->get_error_code(), 'typed-renderer-and-markup-conflict-rejected' );
$invalid_renderer_attributes = $typed_renderer;
$invalid_renderer_attributes['blocks'][0]['block_json']['attributes']['content']['type'] = 'object';
$assert( 'static_site_importer_companion_plugin_renderer_attributes_invalid' === Static_Site_Importer_Companion_Plugin::validate_payload( $invalid_renderer_attributes )->get_error_code(), 'typed-renderer-requires-declared-string-content' );
$layout_renderer = $typed_renderer;
$layout_renderer['blocks'][0]['block_json']['name'] = 'example/responsive-layout';
$assert( true === Static_Site_Importer_Companion_Plugin::validate_payload( $layout_renderer ), 'known-layout-renderer-validates' );
$malformed_dependencies = $payload;
$malformed_dependencies['blocks'][0]['script_dependencies'] = array( array( 'wp-blocks' ) );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $malformed_dependencies ) ), 'script-dependency-map-must-be-an-object' );
$unsafe_dependency_path = $payload;
$unsafe_dependency_path['blocks'][0]['script_dependencies'] = array( '../index.js' => array( 'wp-blocks' ) );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $unsafe_dependency_path ) ), 'script-dependency-path-must-be-safe' );
$missing_dependency_asset = $payload;
unset( $missing_dependency_asset['blocks'][0]['assets']['index.js'] );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $missing_dependency_asset ) ), 'script-dependency-asset-must-exist-and-be-referenced' );
$invalid_dependency_handle = $payload;
$invalid_dependency_handle['blocks'][0]['script_dependencies']['index.js'] = array( 'wp-blocks', 'wp blocks' );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $invalid_dependency_handle ) ), 'script-dependency-handle-must-be-safe' );

// 1. Scaffolder emits a valid plugin file set.
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$assert( is_array( $descriptor ), 'scaffold-returns-descriptor', is_array( $descriptor ) ? '' : 'WP_Error returned' );

if ( is_array( $descriptor ) ) {
	$assert( 'ssi-example-site' === $descriptor['slug'], 'scaffold-namespaces-slug', (string) $descriptor['slug'] );
	$assert( 'ssi-example-site/ssi-example-site.php' === $descriptor['plugin_file'], 'scaffold-plugin-file-path', (string) $descriptor['plugin_file'] );
	$assert( 1 === preg_match( '/^ssi_example_site_[a-f0-9]{16}_register_blocks$/', (string) ( $descriptor['registration_callback'] ?? '' ) ), 'scaffold-exposes-deterministic-inventory-callback' );
	$assert( array( 'example/custom-hero' ) === $descriptor['block_names'], 'scaffold-preserves-declared-canonical-name' );
	$assert( false === $descriptor['mu_plugin'], 'scaffold-regular-plugin-by-default' );

	$files = $descriptor['files'];
	$main  = $files['ssi-example-site/ssi-example-site.php'] ?? '';
	$assert( str_contains( $main, 'Plugin Name:' ), 'main-file-has-plugin-header' );
	$assert( str_contains( $main, "add_filter( 'render_block'" ), 'main-file-scopes-island-enqueue' );
	$assert( str_contains( $main, 'wp_enqueue_script' ), 'main-file-enqueues-island-js' );

	$assert( str_contains( $main, "register_block_type( SSI_EXAMPLE_SITE_" ) && str_contains( $main, "_DIR . 'blocks/' . \$block_dir )" ), 'main-file-registers-metadata-block-directory' );
	$assert( str_contains( $main, "\$registered instanceof WP_Block_Type" ) && str_contains( $main, "static_site_importer_companion_block_owners" ) && str_contains( $main, "'plugin_file' => 'ssi-example-site/ssi-example-site.php'" ), 'main-file-records-owner-after-metadata-registration' );
	$assert( ! str_contains( $main, 'Requires Plugins:' ) && ! str_contains( $main, 'Static_Site_Importer_' ) && ! str_contains( $main, 'Automattic\\BlocksEngine' ), 'generated-plugin-declares-no-importer-or-compiler-runtime-dependency' );
	$assert( ! str_contains( $main, 'block_specs' ) && ! str_contains( $main, 'render_callback' ) && ! str_contains( $main, "register_block_type( (string)" ), 'main-file-has-no-php-only-registration-fallback' );
	$block_json = $files['ssi-example-site/blocks/custom-hero/block.json'] ?? '';
	$assert( '' !== $block_json, 'metadata-block-json-emitted' );
	$assert( str_contains( $block_json, '"editorScript": "file:./index.js"' ), 'metadata-block-json-declares-editor-script' );
	$assert( str_contains( $block_json, '"viewScript"' ) && str_contains( $block_json, '"file:./view.js"' ), 'metadata-block-json-declares-view-script' );
	$assert( str_contains( $block_json, '"viewScriptModule"' ) && str_contains( $block_json, '"viewStyle"' ) && str_contains( $block_json, '"script"' ), 'metadata-block-json-retains-all-core-metadata-fields' );
	$assert( isset( $files['ssi-example-site/blocks/custom-hero/index.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/script.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/style.css'] ) && isset( $files['ssi-example-site/blocks/custom-hero/editor.css'] ) && isset( $files['ssi-example-site/blocks/custom-hero/view.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/view-module.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/view.css'] ) && isset( $files['ssi-example-site/blocks/custom-hero/variations.json'] ), 'metadata-block-assets-emitted' );
	$asset_manifest = $files['ssi-example-site/blocks/custom-hero/index.asset.php'] ?? '';
	$assert( str_contains( $asset_manifest, "'dependencies' => array(\n\t\t'wp-blocks',\n\t\t'wp-block-editor',\n\t\t'wp-element'," ) && str_contains( $asset_manifest, "'version' => '" . hash( 'sha256', 'window.SSIEditor = true;' ) . "'" ), 'script-dependency-asset-manifest-is-deterministic' );

	// The metadata render target remains a server-rendered template.
	$render = $files['ssi-example-site/blocks/custom-hero/render.php'] ?? '';
	$assert( '' !== $render, 'render-php-emitted' );
	$assert( str_starts_with( ltrim( $render ), '<?php' ), 'render-php-opens-with-php-tag' );
	$assert( str_contains( $render, 'Generated editable-content companion block render' ) && str_contains( $render, 'wp_kses_post' ) && ! str_contains( $render, 'Example hero' ), 'editable-render-uses-ssi-owned-safe-boundary' );

	$render_frontend = static function ( string $template, array $attributes ): string {
		$content = '';
		$block   = null;
		ob_start();
		eval( '?>' . $template );
		return (string) ob_get_clean();
	};
	$canonical_url  = 'https://example.test/wp-content/themes/generated-example/assets/media/hero.jpg';
	$imported_output = $render_frontend(
		$render,
		array( 'content' => '<div class="ssi-hero"><img src="' . $canonical_url . '" alt=""><p>Imported hero</p></div>' )
	);
	$assert( str_contains( $imported_output, $canonical_url ) && ! str_contains( $imported_output, 'Example hero' ), 'editable-render-outputs-imported-canonicalized-url', $imported_output );

	$edited_url    = 'https://example.test/wp-content/themes/generated-example/assets/media/edited-hero.jpg';
	$edited_output = $render_frontend(
		$render,
		array( 'content' => '<div class="ssi-hero" onclick="alert(1)"><img src="' . $edited_url . '" alt=""><p>Edited hero</p><script>alert(1)</script></div>' )
	);
	$assert( str_contains( $edited_output, $edited_url ) && str_contains( $edited_output, 'Edited hero' ) && ! str_contains( $edited_output, $canonical_url ), 'editable-render-reflects-saved-content-edit', $edited_output );
	$assert( ! str_contains( $edited_output, '<script' ) && ! str_contains( $edited_output, 'onclick' ), 'editable-render-sanitizes-current-content-at-server-boundary', $edited_output );

	// Preserved island JS (#496) is separate carried JS and still rides along.
	$island_files = array_filter( array_keys( $files ), static fn ( string $path ): bool => str_contains( $path, '/islands/' ) && str_ends_with( $path, '.js' ) );
	$assert( 1 === count( $island_files ), 'preserved-island-js-file-emitted' );
}

// The layout renderer preserves safe semantic content while its media sibling
// remains restricted to media-only markup.
$layout_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $layout_renderer );
$assert( is_array( $layout_descriptor ), 'layout-renderer-scaffold-returns-descriptor' );
if ( is_array( $layout_descriptor ) ) {
	$layout_render = $layout_descriptor['files']['ssi-example-site/blocks/custom-hero/render.php'] ?? '';
	$assert( str_contains( $layout_render, 'Generated responsive-media layout mode companion block render' ) && ! str_contains( $layout_render, 'responsive-layout/v1' ), 'layout-mode-uses-released-renderer-template' );
	$attributes = array( 'kind' => 'layout', 'content' => '<main class="story"><header><nav><a href="/about">About</a></nav></header><section><h1>Story</h1><p>Safe copy <strong>with emphasis</strong>.</p><button type="button">Read more</button><wow-image data-hook="hero"><img src="data:image/png;base64,aGVybw==" alt="Hero" decoding="async"></wow-image><svg viewBox="0 0 10 10" preserveAspectRatio="xMidYMid slice" focusable="false" role="img" aria-label="Mark"><path d="M0 0L10 10" stroke="#000"></path></svg></section></main>' );
	ob_start();
	eval( '?>' . $layout_render );
	$layout_output = (string) ob_get_clean();
	foreach ( array( '<main class="story">', '<nav>', '<h1>Story</h1>', '<button type="button">Read more</button>', '<wow-image data-hook="hero">', '<img src="data:image/png;base64,aGVybw==" alt="Hero" decoding="async">', '<svg viewBox="0 0 10 10" preserveAspectRatio="xMidYMid slice" focusable="false" role="img" aria-label="Mark">', '<path d="M0 0L10 10" stroke="#000"></path>' ) as $fragment ) {
		$assert( str_contains( $layout_output, $fragment ), 'layout-renderer-preserves-' . $fragment );
	}

	// The producer admits these globals on every SVG element. Verify the rendered
	// DOM, including local IDs and arbitrary aria-* names, rather than PHP text.
	$svg_globals = array( 'class' => 'ssi-%s', 'id' => 'node-%s', 'role' => 'img', 'title' => 'title-%s', 'aria-label' => 'label-%s', 'aria-roledescription' => 'graphic-%s' );
	$svg_shapes  = array(
		'svg' => array( 'viewbox' => '0 0 10 10' ),
		'g' => array( 'fill' => 'red', 'stroke' => 'blue', 'stroke-width' => '2', 'transform' => 'translate(1 2)' ),
		'path' => array( 'd' => 'M0 0', 'fill' => 'url(#node-lineargradient)', 'stroke' => 'blue', 'stroke-width' => '2', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'bevel' ),
		'circle' => array( 'cx' => '1', 'cy' => '2', 'r' => '3', 'fill' => 'red', 'stroke' => 'blue', 'stroke-width' => '2' ),
		'ellipse' => array( 'cx' => '1', 'cy' => '2', 'rx' => '3', 'ry' => '4', 'fill' => 'red', 'stroke' => 'blue', 'stroke-width' => '2' ),
		'line' => array( 'x1' => '1', 'x2' => '2', 'y1' => '3', 'y2' => '4', 'stroke' => 'blue', 'stroke-width' => '2', 'stroke-linecap' => 'round' ),
		'polyline' => array( 'points' => '0,0 1,1', 'fill' => 'red', 'stroke' => 'blue', 'stroke-width' => '2', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'bevel' ),
		'polygon' => array( 'points' => '0,0 1,1 2,0', 'fill' => 'red', 'stroke' => 'blue', 'stroke-width' => '2', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'bevel' ),
		'rect' => array( 'x' => '1', 'y' => '2', 'width' => '3', 'height' => '4', 'rx' => '1', 'ry' => '2', 'fill' => 'red', 'stroke' => 'blue', 'stroke-width' => '2' ),
		'defs' => array(),
		'lineargradient' => array( 'gradientunits' => 'userSpaceOnUse', 'x1' => '0', 'x2' => '1', 'y1' => '0', 'y2' => '1' ),
		'radialgradient' => array( 'cx' => '1', 'cy' => '2', 'r' => '3' ),
		'stop' => array( 'offset' => '0', 'stop-color' => '#fff', 'stop-opacity' => '0.5' ),
	);
	$svg_attributes = static function ( string $tag, array $attributes ) use ( $svg_globals ): string {
		$rendered = array();
		foreach ( array_merge( $svg_globals, $attributes ) as $name => $value ) {
			$rendered[] = $name . '="' . sprintf( $value, $tag ) . '"';
		}
		return implode( ' ', $rendered );
	};
	$svg_content = '<svg ' . $svg_attributes( 'svg', $svg_shapes['svg'] ) . '>';
	$svg_content .= '<defs ' . $svg_attributes( 'defs', $svg_shapes['defs'] ) . '><linearGradient ' . $svg_attributes( 'lineargradient', $svg_shapes['lineargradient'] ) . '><stop ' . $svg_attributes( 'stop', $svg_shapes['stop'] ) . '></stop></linearGradient><radialGradient ' . $svg_attributes( 'radialgradient', $svg_shapes['radialgradient'] ) . '></radialGradient></defs>';
	foreach ( array( 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect' ) as $tag ) {
		$svg_content .= '<' . $tag . ' ' . $svg_attributes( $tag, $svg_shapes[ $tag ] ) . '></' . $tag . '>';
	}
	$svg_content .= '</svg>';
	$attributes = array( 'kind' => 'layout', 'content' => $svg_content );
	ob_start();
	eval( '?>' . $layout_render );
	$svg_output = (string) ob_get_clean();
	$svg_document = new DOMDocument();
	$previous_libxml_errors = libxml_use_internal_errors( true );
	$svg_document->loadHTML( '<div>' . $svg_output . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous_libxml_errors );
	foreach ( $svg_shapes as $tag => $shape_attributes ) {
		$element = ( new DOMXPath( $svg_document ) )->query( '//*[@id="node-' . $tag . '"]' )->item( 0 );
		$expected = array_merge( $svg_globals, $shape_attributes );
		$assert( $element instanceof DOMElement && count( $element->attributes ) === count( $expected ), 'layout-renderer-retains-exact-svg-attributes-' . $tag );
		if ( $element instanceof DOMElement ) {
			foreach ( $expected as $name => $value ) {
				$assert( sprintf( $value, $tag ) === $element->getAttribute( $name ), 'layout-renderer-retains-svg-' . $tag . '-' . $name );
			}
		}
	}
	$attributes = array( 'kind' => 'layout', 'content' => '<main onclick="alert(1)" data-wp-interactive="bad" style="background:url(javascript:alert(0))"><script>alert(2)</script><a href="%256a%2561vascript:alert(3)">bad</a><img src="javascript:alert(4)" srcset="safe.jpg 1x, javascript:alert(6) 2x"><audio><source src="song.mp3" type="audio/mpeg"></audio><svg onload="alert(5)"><foreignObject>bad</foreignObject><animate attributeName="x"></animate><defs><linearGradient id="paint"><stop offset="0" stop-color="#fff"></stop></linearGradient></defs><path fill="url(https://evil.test/x.svg#paint)" stroke="url(#paint)" d="M0 0"></path><use href="https://evil.test/icons.svg#icon"></use></svg></main>' );
	ob_start();
	eval( '?>' . $layout_render );
	$unsafe_layout_output = strtolower( (string) ob_get_clean() );
	foreach ( array( 'onclick', 'data-wp-', '<script', 'javascript:', '%256a', 'onload', 'foreignobject', '<animate', '<use', 'https://evil.test' ) as $fragment ) {
		$assert( ! str_contains( $unsafe_layout_output, $fragment ), 'layout-renderer-removes-' . $fragment );
	}
	$assert( str_contains( $unsafe_layout_output, '<path' ) && str_contains( $unsafe_layout_output, 'd="m0 0"' ), 'layout-renderer-keeps-safe-svg-shapes' );
	$assert( str_contains( $unsafe_layout_output, '<source src="song.mp3" type="audio/mpeg">' ) && str_contains( $unsafe_layout_output, 'stroke="url(#paint)"' ), 'layout-renderer-keeps-safe-local-media-and-svg-references' );
}

WP_Block_Type_Registry::$registered[] = 'example/custom-hero';
$collision_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload );
$assert( 'failed' === ( $collision_report['status'] ?? '' ) && 'static_site_importer_companion_plugin_block_name_collision' === ( $collision_report['error']['code'] ?? '' ) && 'runtime_block_name_collision' === ( $collision_report['diagnostics'][0]['reason_code'] ?? '' ), 'registered-block-name-collision-fails-with-structured-receipt' );
WP_Block_Type_Registry::$registered = array();

// Metadata blocks remain dynamic through their generated render.php.
$render_variants = Static_Site_Importer_Companion_Plugin::scaffold(
	array(
		'schema'    => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
		'site_slug' => 'render-variants',
		'site_name' => 'Render Variants',
		'blocks'    => array(
			array(
				'name'       => 'static-card',
				'block_json' => array(
					'name'     => 'blocks-engine/description-list',
					'title'    => 'Static Card',
					'category' => 'design',
				),
			),
			array(
				'name'       => 'declared-render',
				'block_json' => array(
					'title'    => 'Declared Render',
					'category' => 'design',
					'render'   => 'file:./custom-render.php',
				),
				'render'     => '<div class="ssi-declared"></div>',
			),
		),
	)
);
$assert( is_array( $render_variants ), 'render-variants-scaffold-returns-descriptor', is_array( $render_variants ) ? '' : $render_variants->get_error_code() );

if ( is_array( $render_variants ) ) {
	$variant_files = $render_variants['files'];
	$variant_main  = $variant_files['ssi-render-variants/ssi-render-variants.php'] ?? '';

	// A block with no render payload remains static and uses its saved post markup.
	$assert( ! isset( $variant_files['ssi-render-variants/blocks/static-card/render.php'] ), 'static-block-omits-render-php' );
	$assert( str_contains( $variant_main, "'static-card'" ) && str_contains( $variant_main, "register_block_type" ), 'static-block-registered-from-metadata' );
	$static_block_json = $variant_files['ssi-render-variants/blocks/static-card/block.json'] ?? '';
	$assert( str_contains( $static_block_json, '"name": "blocks-engine/description-list"' ), 'static-block-preserves-canonical-name' );
	$assert( ! str_contains( $static_block_json, '"render"' ), 'static-block-preserves-static-rendering' );

	// A block with payload markup emits that markup as render.php.
	$declared_render = $variant_files['ssi-render-variants/blocks/declared-render/render.php'] ?? '';
	$assert( str_contains( $declared_render, 'ssi-declared' ), 'declared-render-block-emits-payload-markup' );

	// Metadata is emitted and the generated render.php remains its render target.
	$variant_block_json = array_filter( array_keys( $variant_files ), static fn ( string $path ): bool => str_ends_with( $path, '/block.json' ) );
	$assert( 2 === count( $variant_block_json ), 'render-variants-emit-block-json', implode( ',', $variant_block_json ) );
	$assert( ! str_contains( $variant_main, 'file:./custom-render.php' ), 'generated-main-file-uses-no-render-arguments' );
}

// Typed renderers emit only SSI-owned PHP and sanitize editable attributes at runtime.
$typed_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $typed_renderer );
$assert( is_array( $typed_descriptor ), 'typed-renderer-scaffold-returns-descriptor' );
if ( is_array( $typed_descriptor ) ) {
	$typed_render = $typed_descriptor['files']['ssi-example-site/blocks/custom-hero/render.php'] ?? '';
	$assert( str_contains( $typed_render, 'Generated responsive-media companion block render' ) && ! str_contains( $typed_render, 'producer/arbitrary' ), 'typed-renderer-emits-ssi-owned-template' );
	$assert( ! str_contains( $typed_render, 'Static_Site_Importer_' ) && ! str_contains( $typed_render, 'Automattic\\BlocksEngine' ), 'typed-renderer-is-self-contained-after-import' );
	$attributes = array(
		'kind'    => 'media',
		'content' => '<a data-track="profile" aria-label="Profile" href="/profile" target="_blank" rel="noopener"><picture><source media="(min-width:800px)" srcset="safe.webp 1x, javascript:alert(1) 2x, hero,wide.webp 3x"><img src="data:image/png;base64,aGVsbG8=" srcset="safe.png 1x, %6a%61vascript:alert(1) 2x, data:image/svg+xml;base64,PHN2Zz4= 3x" alt="Profile"></picture></a>',
	);
	ob_start();
	eval( '?>' . $typed_render );
	$typed_output = (string) ob_get_clean();
	foreach ( array( 'data-track="profile"', 'aria-label="Profile"', 'href="/profile"', 'safe.webp 1x', 'hero,wide.webp 3x', 'safe.png 1x', 'data:image/png;base64,aGVsbG8=' ) as $fragment ) {
		$assert( str_contains( $typed_output, $fragment ), 'typed-renderer-preserves-' . $fragment );
	}
	foreach ( array( 'javascript:', '%6a%61vascript:', 'data:image/svg+xml' ) as $fragment ) {
		$assert( ! str_contains( $typed_output, $fragment ), 'typed-renderer-removes-' . $fragment );
	}
	foreach ( array( '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', '<img srcset=javascript:alert(1)>' ) as $unsafe_content ) {
		$attributes = array( 'kind' => 'media', 'content' => $unsafe_content );
		ob_start();
		eval( '?>' . $typed_render );
		$unsafe_output = strtolower( (string) ob_get_clean() );
		$assert( ! str_contains( $unsafe_output, '<script' ) && ! str_contains( $unsafe_output, 'onerror' ) && ! str_contains( $unsafe_output, 'data:text' ) && ! str_contains( $unsafe_output, 'javascript:' ), 'typed-renderer-rejects-executable-content' );
	}
}

// mu-plugin variant materializes a root loader stub.
$mu_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( array_merge( $payload, array( 'mu_plugin' => true ) ) );
$assert( is_array( $mu_descriptor ) && true === $mu_descriptor['mu_plugin'], 'scaffold-honors-mu-plugin-option' );
$assert( is_array( $mu_descriptor ) && 'ssi-example-site.php' === $mu_descriptor['loader_file'], 'mu-plugin-emits-root-loader' );
$assert( is_array( $mu_descriptor ) && isset( $mu_descriptor['files']['ssi-example-site.php'] ), 'mu-plugin-loader-file-present' );

// Invalid payloads are rejected.
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::scaffold( array( 'site_slug' => '' ) ) ), 'scaffold-rejects-missing-site-slug' );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::scaffold( array( 'site_slug' => 'x', 'blocks' => array() ) ) ), 'scaffold-rejects-missing-blocks' );

// 2. Install plan resolves the file set + activation intent (pure / no writes).
if ( is_array( $descriptor ) ) {
	$plan = Static_Site_Importer_Plugin_Materializer::generated_install_plan( $descriptor, '/var/plugins' );
	$assert( is_array( $plan ), 'install-plan-built' );
	if ( is_array( $plan ) ) {
		$assert( 'plugin' === $plan['destination'], 'install-plan-regular-destination' );
		$assert( true === $plan['activate'], 'install-plan-regular-requires-activation' );
		$assert( isset( $plan['absolute_files']['/var/plugins/ssi-example-site/ssi-example-site.php'] ), 'install-plan-absolute-paths-prefixed' );
	}

	$mu_plan = Static_Site_Importer_Plugin_Materializer::generated_install_plan( $mu_descriptor, '/var/mu-plugins' );
	$assert( is_array( $mu_plan ) && 'mu_plugin' === $mu_plan['destination'], 'install-plan-mu-destination' );
	$assert( is_array( $mu_plan ) && false === $mu_plan['activate'], 'install-plan-mu-no-activation' );
}

// 3. Full install/activate path writes the file set and activates it.
$report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload );
$assert( 'installed_activated' === ( $report['status'] ?? '' ), 'install-status-installed-activated', (string) ( $report['status'] ?? '' ) );
$assert( true === ( $report['installed'] ?? false ), 'install-reports-installed' );
$assert( true === ( $report['active'] ?? false ), 'install-reports-active' );
$assert( in_array( 'installed', $report['actions'] ?? array(), true ), 'install-records-installed-action' );
$assert( in_array( 'activated', $report['actions'] ?? array(), true ), 'install-records-activated-action' );
$assert( in_array( 'ssi-example-site/ssi-example-site.php', $GLOBALS['ssi_companion_activated'], true ), 'install-activates-companion-plugin' );
$assert( 'ssi-example-site/ssi-example-site.php' === get_option( Static_Site_Importer_Plugin_Materializer::ACTIVE_COMPANION_OPTION ), 'install-records-current-companion-plugin' );
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ), 'install-writes-main-file-to-disk' );
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/render.php' ), 'install-writes-render-php-to-disk' );
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/block.json' ), 'install-emits-block-json' );
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/index.js' ), 'install-emits-declared-editor-asset' );
$standalone_bootstrap = <<<'PHP'
define( 'ABSPATH', __DIR__ . '/' );
class WP_Block_Type {
	public function __construct( public string $name ) {}
}
function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function plugin_dir_url( string $file ): string { return 'https://example.test/plugins/' . basename( dirname( $file ) ) . '/'; }
function add_action( string $hook, callable|string $callback ): void { if ( 'init' === $hook ) { call_user_func( $callback ); } }
function add_filter( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void {}
function register_block_type( string $path, array $args = array() ): WP_Block_Type|false {
	$metadata = is_file( $path . '/block.json' ) ? json_decode( (string) file_get_contents( $path . '/block.json' ), true ) : array();
	$name = is_array( $metadata ) ? (string) ( $metadata['name'] ?? '' ) : '';
	return '' !== $name ? new WP_Block_Type( $name ) : false;
}
function get_option( string $name, mixed $default = false ): mixed { return $default; }
require $argv[1];
exit( isset( $GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] ) ? 0 : 1 );
PHP;
$standalone_process = proc_open(
	array( PHP_BINARY, '-r', $standalone_bootstrap, WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$standalone_pipes
);
$standalone_output = '';
$standalone_status = 1;
if ( is_resource( $standalone_process ) ) {
	$standalone_output = stream_get_contents( $standalone_pipes[1] ) . stream_get_contents( $standalone_pipes[2] );
	fclose( $standalone_pipes[1] );
	fclose( $standalone_pipes[2] );
	$standalone_status = proc_close( $standalone_process );
}
$assert( 0 === $standalone_status, 'generated-plugin-loads-without-importer-or-compiler', $standalone_output );
$written_asset_manifest = WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/index.asset.php';
$asset_manifest_value  = file_exists( $written_asset_manifest ) ? include $written_asset_manifest : null;
$assert( array( 'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ), 'version' => hash( 'sha256', 'window.SSIEditor = true;' ) ) === $asset_manifest_value, 'installed-asset-manifest-executes-with-dependencies-and-content-version' );
$assert( in_array( 'example/custom-hero', WP_Block_Type_Registry::$registered, true ), 'install-registers-declared-block-before-editor-use' );
$assert( isset( $GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] ), 'install-records-declared-block-owner-before-editor-use' );
$written_main = file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) ? (string) file_get_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) : '';
$assert( str_contains( $written_main, 'register_block_type' ), 'written-main-file-registers-blocks' );

// Runtime paths may use filesystem aliases (for example /var and /private/var
// on macOS) while still identifying the same generated companion entrypoint.
$GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] = array(
	'plugin_file' => 'ssi-example-site/ssi-example-site.php',
	'plugin_path' => dirname( WP_PLUGIN_DIR ) . '/plugins/../plugins/ssi-example-site/ssi-example-site.php',
);
$aliased_owner_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$assert( 'refreshed' === ( $aliased_owner_report['status'] ?? '' ), 'same-companion-filesystem-alias-reuses-registered-block' );

// A second overwrite import can also start without its request-local owner
// record when the active entrypoint is byte-identical to the pending scaffold.
$GLOBALS['static_site_importer_companion_block_owners'] = array();
$overwrite_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true, true );
$assert( 'refreshed' === ( $overwrite_report['status'] ?? '' ), 'same-companion-overwrite-reuses-prior-registered-block' );
$assert( in_array( 'refreshed', $overwrite_report['actions'] ?? array(), true ), 'same-companion-overwrite-records-refresh-action' );

// A foreign registration that wins before generated plugin init must never be
// marked as companion-owned, so a later materialization still fails closed.
WP_Block_Type_Registry::$registered[] = 'example/custom-hero';
$GLOBALS['static_site_importer_companion_block_owners'] = array();
call_user_func( $descriptor['registration_callback'] );
$assert( ! isset( $GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] ), 'foreign-registration-before-generated-init-records-no-owner' );
$foreign_init_collision = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$assert( 'failed' === ( $foreign_init_collision['status'] ?? '' ) && 'runtime_block_name_collision' === ( $foreign_init_collision['diagnostics'][0]['reason_code'] ?? '' ), 'foreign-registration-before-generated-init-blocks-refresh' );
WP_Block_Type_Registry::$registered = array();
$GLOBALS['ssi_companion_actions'] = array();

// Existing active generated companions are refreshed from the current payload;
// stale files from an older SSI build must not bypass scaffold normalization.
file_put_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php', "<?php\nreturn array( 'type' => 'content' );\n" );
$GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] = array(
	'plugin_file' => 'ssi-example-site/ssi-example-site.php',
	'plugin_path' => WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php',
);
WP_Block_Type_Registry::$registered[] = 'example/custom-hero';
$refresh_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$refreshed_main = file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) ? (string) file_get_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) : '';
$assert( 'refreshed' === ( $refresh_report['status'] ?? '' ), 'active-generated-plugin-refresh-status', (string) ( $refresh_report['status'] ?? '' ) );
$assert( in_array( 'refreshed', $refresh_report['actions'] ?? array(), true ), 'active-generated-plugin-records-refresh-action' );
$assert( ! str_contains( $refreshed_main, "'type' => 'content'" ), 'active-generated-plugin-overwrites-stale-invalid-schema' );
$assert( 'refreshed' === ( $refresh_report['status'] ?? '' ), 'active-companion-owned-registration-refreshes-successfully' );

// Later batches may add blocks while the prior generated callback remains loaded.
$expanded_payload = $payload;
$expanded_payload['blocks'][] = array(
	'name'       => 'custom-gallery',
	'block_json' => array(
		'name'     => 'example/custom-gallery',
		'title'    => 'Custom Gallery',
		'category' => 'design',
	),
	'render'     => '<div class="ssi-gallery">Gallery</div>',
);
$expanded_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $expanded_payload );
$expanded_report     = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $expanded_payload, static fn (): bool => true );
$assert( is_array( $expanded_descriptor ) && $descriptor['registration_callback'] !== $expanded_descriptor['registration_callback'], 'changed-inventory-uses-new-registration-callback' );
$assert( 'refreshed' === ( $expanded_report['status'] ?? '' ) && in_array( 'example/custom-gallery', WP_Block_Type_Registry::$registered, true ), 'same-request-refresh-registers-new-block-inventory' );

$GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] = array(
	'plugin_file' => 'foreign/foreign.php',
	'plugin_path' => WP_PLUGIN_DIR . '/foreign/foreign.php',
);
$foreign_collision = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$assert( 'failed' === ( $foreign_collision['status'] ?? '' ) && 'runtime_block_name_collision' === ( $foreign_collision['diagnostics'][0]['reason_code'] ?? '' ), 'foreign-registered-block-fails-before-refresh-write' );
WP_Block_Type_Registry::$registered = array();
$GLOBALS['static_site_importer_companion_block_owners'] = array();

// mu-plugin install writes the root loader and needs no activation call.
$mu_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( array_merge( $payload, array( 'mu_plugin' => true ) ) );
$assert( 'installed_activated' === ( $mu_report['status'] ?? '' ), 'mu-install-status', (string) ( $mu_report['status'] ?? '' ) );
$assert( true === ( $mu_report['mu_plugin'] ?? false ), 'mu-install-reports-mu-plugin' );
$assert( ! in_array( 'activated', $mu_report['actions'] ?? array(), true ), 'mu-install-skips-activation' );
$assert( file_exists( WPMU_PLUGIN_DIR . '/ssi-example-site.php' ), 'mu-install-writes-root-loader' );

// 4. Declared-dependency wiring: distinct generated/companion dependency entry.
$dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $payload );
$assert( 'companion_plugin' === ( $dependency['type'] ?? '' ), 'dependency-type-is-companion-plugin' );
$assert( 'ssi-example-site' === ( $dependency['slug'] ?? '' ), 'dependency-slug-namespaced' );
$assert( is_callable( $dependency['availability_callback'] ?? null ), 'dependency-has-availability-callback' );

// The earlier install marked the regular companion active via the stub, so the
// dependency row reflects a satisfied dependency.
$active_row = Static_Site_Importer_Entity_Materializer_Registry::companion_dependency_row( $dependency, false );
$assert( 'generated' === ( $active_row['source'] ?? '' ), 'dependency-row-source-generated' );
$assert( true === ( $active_row['active'] ?? false ), 'dependency-row-active-when-installed' );
$assert( array( 'example/custom-hero' ) === ( $active_row['block_names'] ?? array() ), 'dependency-row-carries-block-names' );

// A not-yet-installed companion surfaces as a gate-visible failure.
$missing_payload    = array_merge( $payload, array( 'site_slug' => 'second-site' ) );
$missing_dependency = Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_dependency( $missing_payload );
$assert( false === Static_Site_Importer_Entity_Materializer_Registry::companion_plugin_available( $missing_dependency ), 'missing-companion-not-available' );

$gate_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( $gate_report, $missing_dependency, false );
$assert( 1 === (int) ( $gate_report['quality']['companion_plugin_dependency_failures'] ?? 0 ), 'missing-companion-increments-quality-counter' );
$assert( isset( $gate_report['companion_plugins']['dependencies']['ssi-second-site'] ), 'companion-dependency-declared-in-report' );
$missing_diag = array_values( array_filter( $gate_report['diagnostics'] ?? array(), static fn ( array $d ): bool => 'companion_plugin_missing' === ( $d['code'] ?? '' ) ) );
$assert( 1 === count( $missing_diag ), 'missing-companion-emits-diagnostic' );

$quality = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $gate_report, array( 'fail_on_quality' => true ) );
$assert( in_array( 'companion_plugin_missing', $quality['failure_reasons'] ?? array(), true ), 'gate-sees-companion-plugin-missing' );
$assert( true === ( $quality['fail_import'] ?? false ), 'gate-fails-import-on-missing-companion' );

// Waived missing companion warns but does not fail the gate.
$waived_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
Static_Site_Importer_Report_Diagnostics::record_companion_plugin_dependency( $waived_report, $missing_dependency, true );
$assert( 0 === (int) ( $waived_report['quality']['companion_plugin_dependency_failures'] ?? 0 ), 'waived-companion-no-quality-failure' );
$waived_diag = array_values( array_filter( $waived_report['diagnostics'] ?? array(), static fn ( array $d ): bool => 'companion_plugin_waived' === ( $d['code'] ?? '' ) ) );
$assert( 1 === count( $waived_diag ), 'waived-companion-emits-warning' );

// A new site replaces the previous regular companion so document-global
// scripts from separate imports cannot execute together.
$GLOBALS['static_site_importer_companion_block_owners'] = array();
WP_Block_Type_Registry::$registered                   = array();
$replacement_payload = array_merge( $payload, array( 'site_slug' => 'replacement-site' ) );
$replacement_report  = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $replacement_payload );
$assert( in_array( 'ssi-example-site/ssi-example-site.php', $GLOBALS['ssi_companion_deactivated'], true ), 'replacement-deactivates-previous-companion' );
$assert( in_array( 'replaced:ssi-example-site/ssi-example-site.php', $replacement_report['actions'] ?? array(), true ), 'replacement-reports-previous-companion' );
$assert( 'ssi-replacement-site/ssi-replacement-site.php' === get_option( Static_Site_Importer_Plugin_Materializer::ACTIVE_COMPANION_OPTION ), 'replacement-records-current-companion-plugin' );

// Cleanup generated fixtures.
$cleanup = static function ( string $dir ) use ( &$cleanup ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( is_array( $items ) ? $items : array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? $cleanup( $path ) : unlink( $path );
	}
	rmdir( $dir );
};
$cleanup( $ssi_companion_tmp );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: companion plugin smoke passed (' . $assertions . " assertions)\n";
