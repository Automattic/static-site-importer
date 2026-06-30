<?php
/**
 * Smoke test: importer block metadata, registration, and rendered shell.
 *
 * Run from the repository root:
 * php tests/smoke-importer-block.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) ) {
	define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$GLOBALS['ssi_registered_block'] = null;
$GLOBALS['ssi_options']          = array();
$GLOBALS['ssi_test_options']     = array();
$GLOBALS['ssi_filters']          = array();
$GLOBALS['ssi_home_url']         = 'https://example.test/';
$GLOBALS['ssi_uuid_count']       = 0;
$GLOBALS['ssi_transients']       = array();
$GLOBALS['ssi_upload_dir']       = sys_get_temp_dir() . '/ssi-smoke-uploads-' . getmypid();

defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * 24 * 60 * 60 );

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( string $path, array $args = array() ): bool {
		$GLOBALS['ssi_registered_block'] = array(
			'path' => $path,
			'args' => $args,
		);

		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = '' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( $title ) ), '-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response;
	}
}

if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
	function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
		unset( $content, $options );
		if ( 'html' !== $from || 'blocks' !== $to ) {
			return array(
				'schema'            => 'blocks-engine/php-transformer/result/v1',
				'status'            => 'failed',
				'serialized_blocks' => '',
			);
		}

		return array(
			'schema'            => 'blocks-engine/php-transformer/result/v1',
			'status'            => 'success',
			'serialized_blocks' => '<!-- wp:heading {"level":1} --><h1>Figma HTML</h1><!-- /wp:heading --><!-- wp:image {"url":"assets/hero.png","alt":"Hero"} --><figure class="wp-block-image"><img src="assets/hero.png" alt="Hero" /></figure><!-- /wp:image -->',
			'diagnostics'       => array(),
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ssi_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );
		$GLOBALS['ssi_filters'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook ) {
		return empty( $GLOBALS['ssi_filters'][ $hook ] ) ? false : true;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( ?string $hook = null ): bool {
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		if ( array_key_exists( $name, $GLOBALS['ssi_options'] ) ) {
			return $GLOBALS['ssi_options'][ $name ];
		}

		return array_key_exists( $name, $GLOBALS['ssi_test_options'] ) ? $GLOBALS['ssi_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['ssi_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $name ) {
		return $GLOBALS['ssi_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$GLOBALS['ssi_transients'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, bool $create_dir = true ): array {
		unset( $time );
		if ( $create_dir && ! is_dir( $GLOBALS['ssi_upload_dir'] ) ) {
			wp_mkdir_p( $GLOBALS['ssi_upload_dir'] );
		}

		return array(
			'basedir' => $GLOBALS['ssi_upload_dir'],
			'baseurl' => 'https://example.test/uploads',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		++$GLOBALS['ssi_uuid_count'];

		return '00000000-0000-4000-8000-' . str_pad( (string) $GLOBALS['ssi_uuid_count'], 12, '0', STR_PAD_LEFT );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		return file_exists( $file ) ? unlink( $file ) : true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
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
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params;
		private array $files;

		public function __construct( array $params, array $files = array() ) {
			$this->params = $params;
			$this->files  = $files;
		}

		public function get_json_params(): array {
			return $this->params;
		}

		public function get_params(): array {
			return $this->params;
		}

		public function get_file_params(): array {
			return $this->files;
		}

		public function get_param( string $name ) {
			return $this->params[ $name ] ?? null;
		}

		public function get_header( string $name ): string {
			unset( $name );

			return 'null';
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_name;

		public function __construct( int $id, string $post_name ) {
			$this->ID        = $id;
			$this->post_name = $post_name;
		}
	}
}

if ( ! class_exists( 'Static_Site_Importer_Transformer_Adapter' ) ) {
	class Static_Site_Importer_Transformer_Adapter {
		public const WEBSITE_ARTIFACT_SCHEMA = 'blocks-engine/website-artifact/v1';
	}
}

if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
	class Static_Site_Importer_Theme_Generator {
		public static array $last_artifact = array();
		public static array $last_args     = array();

		public static function import_website_artifact( array $artifact, array $args ): array {
			self::$last_artifact = $artifact;
			self::$last_args     = $args;

			return array( 'import_report_summary' => array( 'status' => 'passed' ) );
		}
	}
}

if ( ! class_exists( 'WP_Codebox_Abilities' ) ) {
	class WP_Codebox_Abilities {
		public static array $last_input = array();
		public static array $next_session = array();

		public static function create_browser_playground_session( array $input ): array {
			self::$last_input = $input;

			return self::$next_session;
		}
	}
}

if ( ! class_exists( 'WP_Codebox_Browser_Task_Builder' ) ) {
	class WP_Codebox_Browser_Task_Builder {
		public static function executable_blueprint_ref( array $session ): array {
			if ( isset( $session['blueprint_ref'] ) && is_array( $session['blueprint_ref'] ) ) {
				return $session['blueprint_ref'];
			}

			$playground = isset( $session['playground'] ) && is_array( $session['playground'] ) ? $session['playground'] : array();
			$prepared   = isset( $playground['prepared_runtime'] ) && is_array( $playground['prepared_runtime'] ) ? $playground['prepared_runtime'] : array();
			if ( empty( $prepared['cache_key'] ) || empty( $prepared['input_hash'] ) ) {
				return array();
			}

			return array(
				'schema'             => 'wp-codebox/browser-blueprint-ref/v1',
				'ref'                => 'prepared:' . $prepared['cache_key'] . ':' . $prepared['input_hash'],
				'hydration_endpoint' => '/wp-codebox/v1/browser-blueprint-ref?ref=prepared%3A' . rawurlencode( $prepared['cache_key'] ) . '%3A' . rawurlencode( $prepared['input_hash'] ),
			);
		}
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return rtrim( $GLOBALS['ssi_home_url'], '/' ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'test-nonce';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['ssi_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_page_uri' ) ) {
	function get_page_uri( WP_Post $post ): string {
		return 'tools/' . $post->post_name;
	}
}

require_once dirname( __DIR__ ) . '/includes/block.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
$figma_transformer_bootstrap = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-figma-transformer/figma-transformer/figma-transformer.php';
if ( is_readable( $figma_transformer_bootstrap ) ) {
	require_once $figma_transformer_bootstrap;
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';
Static_Site_Importer_Figma_Import::register_default_zstd_decoder();
require_once dirname( __DIR__ ) . '/includes/abilities.php';
require_once dirname( __DIR__ ) . '/includes/rest.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-page-materializer.php';

$plugin_source = file_get_contents( dirname( __DIR__ ) . '/static-site-importer.php' );
$assert( is_string( $plugin_source ), 'plugin-source-readable' );
$assert( ! str_contains( $plugin_source, 'Requires Plugins: blocks-engine-php-transformer' ), 'transformer-is-not-a-required-wordpress-plugin' );
$assert( str_contains( $plugin_source, "vendor/autoload.php" ), 'loads-composer-autoloader' );
$assert( str_contains( $plugin_source, "vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php" ), 'loads-composer-transformer-bootstrap' );
$assert( str_contains( $plugin_source, "vendor/automattic/blocks-engine-php-transformer/php-transformer.php" ), 'loads-composer-path-transformer-bootstrap' );
$assert( str_contains( $plugin_source, 'Static_Site_Importer_Figma_Import::register_default_zstd_decoder();' ), 'plugin-registers-figma-zstd-decoder' );

$known_zstd_command = false;
foreach ( array( '/opt/homebrew/bin/zstd', '/usr/local/bin/zstd', '/usr/bin/zstd' ) as $known_zstd_path ) {
	$known_zstd_command = $known_zstd_command || is_executable( $known_zstd_path );
}
$figma_zstd_decoder = apply_filters( 'blocks_engine_figma_transformer_zstd_decoder', null );
$assert( ! $known_zstd_command || is_callable( $figma_zstd_decoder ), 'figma-zstd-decoder-registers-when-command-exists' );

$rest_source = file_get_contents( dirname( __DIR__ ) . '/includes/rest.php' );
$assert( is_string( $rest_source ), 'rest-source-readable' );
$assert( ! str_contains( $rest_source, 'figma-preview-blueprint' ), 'rest-does-not-register-stored-figma-blueprint-route' );
$assert( ! str_contains( $rest_source, 'static_site_importer_rest_store_figma_blueprint' ), 'rest-does-not-store-playground-blueprints' );
$assert( ! str_contains( $rest_source, 'https://playground.wordpress.net/?url=%2F' ), 'rest-does-not-return-empty-playground-url' );
$assert( ! str_contains( $rest_source, 'generate_in_current_runtime' ), 'rest-does-not-accept-current-runtime-mode' );

$metadata = json_decode( file_get_contents( dirname( __DIR__ ) . '/blocks/importer/block.json' ), true );
$assert( is_array( $metadata ), 'block-json-decodes' );
$assert( 'static-site-importer/importer' === ( $metadata['name'] ?? '' ), 'block-name-is-product-importer' );
$assert( 'Static Site Importer' === ( $metadata['title'] ?? '' ), 'block-title-is-product-name' );
$assert( isset( $metadata['viewScript'] ), 'block-has-frontend-script' );
$assert( false === ( $metadata['attributes']['applyToCurrentSite']['default'] ?? null ), 'block-defaults-current-site-import-off' );
$assert( true === ( $metadata['attributes']['openInPlayground']['default'] ?? null ), 'block-defaults-to-open-in-playground' );
$assert( ! isset( $metadata['attributes']['generateInCurrentRuntime'] ), 'block-has-no-current-runtime-generation-mode' );

static_site_importer_register_block();

$registered = $GLOBALS['ssi_registered_block'];
$assert( is_array( $registered ), 'block-registers' );
$assert( STATIC_SITE_IMPORTER_PATH . 'blocks/importer' === ( $registered['path'] ?? '' ), 'block-registers-metadata-directory' );
$assert( 'static_site_importer_render_block' === ( $registered['args']['render_callback'] ?? '' ), 'block-registers-render-callback' );

$html = static_site_importer_render_block(
	array(
		'title'              => 'Import your site',
		'intro'              => 'Upload files, paste HTML, or start from a URL.',
		'provider'           => 'Private Provider!',
		'defaultUrl'         => 'https://example.com/source',
		'applyToCurrentSite' => true,
	)
);

$assert( str_contains( $html, 'data-static-site-importer' ), 'render-has-root-hook' );
$assert( str_contains( $html, 'data-static-site-importer-rest-url="https://example.test/wp-json/static-site-importer/v1/imports"' ), 'render-exposes-import-rest-route' );
$assert( str_contains( $html, 'data-static-site-importer-figma-rest-url="https://example.test/wp-json/static-site-importer/v1/import-figma-file"' ), 'render-exposes-figma-file-rest-route' );
$assert( str_contains( $html, 'data-static-site-importer-provider="privateprovider"' ), 'render-sanitizes-provider' );
$assert( str_contains( $html, 'data-static-site-importer-apply-to-current-site="1"' ), 'render-exposes-current-site-apply-flag' );
$assert( str_contains( $html, 'data-static-site-importer-open-in-playground="0"' ), 'render-exposes-open-in-playground-flag' );
$assert( ! str_contains( $html, 'data-static-site-importer-source-url' ), 'render-omits-url-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-default-url="https://example.com/source"' ), 'render-preserves-default-url-for-programmatic-use' );
$assert( str_contains( $html, 'data-static-site-importer-source-files' ), 'render-has-file-input-hook' );
$assert( str_contains( $html, 'Drop website source' ), 'render-has-decoupled-dropzone-label' );
$assert( str_contains( $html, 'Drag a folder, ZIP, or static site files here.' ), 'render-has-upload-dropzone-copy' );
$assert( str_contains( $html, 'data-static-site-importer-dropzone' ), 'render-has-upload-dropzone-hook' );
$assert( str_contains( $html, 'Choose website source' ), 'render-has-decoupled-source-picker-label' );
$assert( ! str_contains( $html, 'data-static-site-importer-source-type' ), 'render-omits-source-type-dropdown-hook' );
$assert( ! str_contains( $html, '<select' ), 'render-omits-source-type-dropdown' );
$assert( str_contains( $html, 'data-static-site-importer-upload-files' ), 'render-has-files-upload-button-hook' );
$assert( str_contains( $html, 'data-static-site-importer-upload-folder' ), 'render-has-folder-upload-button-hook' );
$assert( str_contains( $html, 'data-static-site-importer-upload-figma' ), 'render-has-figma-upload-button-hook' );
$assert( str_contains( $html, 'File(s)' ), 'render-has-files-visible-upload-affordance' );
$assert( str_contains( $html, 'Figma' ), 'render-has-figma-upload-choice' );
$assert( str_contains( $html, 'Folder' ), 'render-preserves-folder-upload-choice' );
$assert( str_contains( $html, 'data-static-site-importer-source-directory' ), 'render-preserves-directory-upload-hook' );
$assert( str_contains( $html, 'data-static-site-importer-source-figma-file' ), 'render-has-separate-figma-upload-hook' );
$assert( str_contains( $html, 'webkitdirectory' ), 'render-preserves-directory-picker' );
$assert( str_contains( $html, 'hidden data-static-site-importer-source-files' ) || str_contains( $html, 'data-static-site-importer-source-files hidden' ), 'render-hides-file-input-behind-trigger' );
$assert( str_contains( $html, 'hidden data-static-site-importer-source-directory' ) || str_contains( $html, 'data-static-site-importer-source-directory hidden' ), 'render-hides-directory-input-behind-trigger' );
$assert( str_contains( $html, 'hidden data-static-site-importer-source-figma-file' ) || str_contains( $html, 'data-static-site-importer-source-figma-file hidden' ), 'render-hides-figma-input-behind-trigger' );
$assert( ! str_contains( $html, '<details class="ssi-importer__upload-picker"' ), 'render-omits-upload-expander' );
$assert( ! str_contains( $html, '<summary class="ssi-importer__upload-button"' ), 'render-omits-upload-summary-button' );
$assert( ! str_contains( $html, 'Upload Figma file' ), 'render-omits-separate-figma-upload-label' );
$assert( str_contains( $html, 'accept=".fig" hidden data-static-site-importer-source-figma-file' ) || str_contains( $html, 'accept=".fig" data-static-site-importer-source-figma-file hidden' ), 'render-accepts-fig-only-on-dedicated-input' );
$assert( ! str_contains( $html, 'data-static-site-importer-source-archive' ), 'render-omits-separate-zip-upload-hook' );
$assert( str_contains( $html, '.zip,application/zip' ), 'render-accepts-zip-in-combined-upload' );
$assert( str_contains( $html, 'data-static-site-importer-source-html' ), 'render-has-html-input-hook' );
$assert( str_contains( $html, '<summary class="ssi-importer__label">Paste HTML</summary>' ), 'render-collapses-paste-html-by-default' );
$assert( str_contains( $html, 'data-static-site-importer-submit' ), 'render-has-submit-hook' );
$assert( str_contains( $html, 'data-static-site-importer-progress' ), 'render-has-progress-hook' );
$assert( ! str_contains( $html, 'data-static-site-importer-preview-link' ), 'render-omits-redundant-preview-link-hook' );
$assert( str_contains( $html, 'data-static-site-importer-report' ), 'render-has-report-hook' );
$assert( ! str_contains( $html, 'Import status' ), 'render-omits-import-status-section-copy' );
$assert( str_contains( $html, 'Import your site' ), 'render-uses-custom-title' );
$assert( str_contains( $html, 'https://example.com/source' ), 'render-uses-default-url' );
$assert( str_contains( static_site_importer_render_block(), 'Generate WordPress Website' ), 'render-preview-mode-button-generates-wordpress-website' );
$playground_html = static_site_importer_render_block( array( 'openInPlayground' => true ) );
$assert( str_contains( $playground_html, 'data-static-site-importer-apply-to-current-site="0"' ), 'render-playground-does-not-enable-current-site-apply' );
$assert( str_contains( $playground_html, 'data-static-site-importer-open-in-playground="1"' ), 'render-can-target-playground-with-generate-label' );
$assert( str_contains( $playground_html, 'Generate WordPress Website' ), 'render-playground-button-generates-wordpress-website' );
$assert( ! str_contains( $playground_html, 'Import to this site' ), 'render-playground-button-does-not-say-import-to-this-site' );

$view_js = file_get_contents( dirname( __DIR__ ) . '/blocks/importer/view.js' );
$assert( is_string( $view_js ), 'view-js-readable' );
$assert( str_contains( $view_js, 'webkitRelativePath' ), 'view-preserves-directory-relative-paths' );
$assert( str_contains( $view_js, 'data-static-site-importer-source-directory' ), 'view-reads-directory-upload-input' );
$assert( ! str_contains( $view_js, 'data-static-site-importer-source-type' ), 'view-omits-source-type-dropdown' );
$assert( str_contains( $view_js, 'data-static-site-importer-upload-files' ), 'view-binds-files-upload-button' );
$assert( str_contains( $view_js, 'data-static-site-importer-upload-folder' ), 'view-binds-folder-upload-button' );
$assert( str_contains( $view_js, 'data-static-site-importer-upload-figma' ), 'view-binds-figma-upload-button' );
$assert( str_contains( $view_js, 'input.click()' ), 'view-opens-selected-hidden-input' );
$assert( str_contains( $view_js, 'webkitGetAsEntry' ), 'view-supports-dropped-directory-entries' );
$assert( str_contains( $view_js, 'archive: await buildArchive( uploadInputs, root )' ), 'view-sends-zip-from-combined-upload-as-archive-payload' );
$assert( str_contains( $view_js, "formData.append( 'figma_file', file )" ), 'view-sends-figma-file-as-multipart-upload' );
$assert( ! str_contains( $view_js, 'buildFigmaFile' ), 'view-does-not-build-figma-file-payload' );
$assert( str_contains( $view_js, '/\\.zip$/i' ), 'view-excludes-zip-files-from-generic-static-upload' );
$assert( str_contains( $view_js, 'data-static-site-importer-source-figma-file' ), 'view-reads-separate-figma-file-input' );
$assert( str_contains( $view_js, 'shouldIncludeSiteFile' ), 'view-skips-known-non-site-upload-files-before-reading' );
$assert( ! str_contains( $view_js, 'CurrentRuntime' ), 'view-does-not-reference-current-runtime-mode' );
$assert( ! str_contains( $view_js, 'generate_in_current_runtime' ), 'view-does-not-send-current-runtime-flag' );
$assert( str_contains( $view_js, 'isCurrentSiteImport' ), 'view-names-current-site-import-mode-explicitly' );
$assert( str_contains( $view_js, 'apply_to_current_site: isCurrentSiteImport' ), 'view-sends-current-site-apply-flag-only-from-apply-mode' );
$assert( str_contains( $view_js, 'activate: isCurrentSiteImport' ), 'view-activates-only-current-site-imports' );
$assert( str_contains( $view_js, 'overwrite: isCurrentSiteImport' ), 'view-overwrites-only-current-site-imports' );
$assert( ! str_contains( $view_js, 'about:blank' ), 'view-does-not-open-window-before-preview-url-is-ready' );
$assert( ! str_contains( $view_js, 'openPendingPreviewWindow' ), 'view-has-no-pending-preview-window-helper' );
$assert( str_contains( $view_js, 'openPreview( report )' ), 'view-opens-preview-only-after-report-is-ready' );
$assert( str_contains( $view_js, 'playground.blueprint_url || preview.url' ), 'view-opens-playground-blueprint-for-generated-site' );
$generic_preview_message = implode( ' ', array( 'no', 'preview', 'provider', 'is', 'configured' ) );
$assert( ! str_contains( $view_js, $generic_preview_message ), 'view-does-not-reference-generic-preview-message' );
$assert( ! str_contains( $view_js, 'Open WordPress preview' ) && ! str_contains( $html, 'Open WordPress preview' ), 'view-and-render-omit-redundant-preview-link-label' );

WP_Codebox_Abilities::$next_session = array(
	'success'         => true,
	'schema'          => 'wp-codebox/browser-playground-session/v1',
	'execution'       => 'browser-playground',
	'execution_scope' => 'disposable-playground',
	'session'         => array(
		'id'     => 'ssi-preview-session',
		'status' => 'ready',
	),
	'playground'      => array(
		'preview_public_url' => 'https://preview.example.test/ssi',
		'preview_url'        => '/?preview=1',
		'scope'              => 'ssi-preview-session',
		'prepared_runtime'   => array(
			'cache_key'  => 'ssi-preview-cache',
			'input_hash' => str_repeat( 'a', 64 ),
		),
	),
	'artifacts'       => array(
		'preview_url' => '/?preview=1',
	),
);

$preview_source   = array(
	'files' => array(
		array(
			'path'    => 'uploaded/site/index.html',
			'content' => '<main>Hello</main>',
		),
	),
);
$preview_response = static_site_importer_rest_create_preview(
	$preview_source,
	static_site_importer_rest_import_args( array() ),
	array( 'source' => $preview_source )
);
$assert( true === ( $preview_response['success'] ?? null ), 'rest-preview-codebox-result-succeeds' );
$assert( 'https://preview.example.test/ssi' === ( $preview_response['preview']['url'] ?? '' ), 'rest-preview-contract-exposes-codebox-preview-url' );
$assert( isset( $preview_response['preview']['playground']['blueprint_url'] ), 'rest-preview-contract-exposes-playground-blueprint-url' );
$preview_blueprint_url_parts = wp_parse_url( (string) $preview_response['preview']['playground']['blueprint_url'] );
parse_str( (string) ( $preview_blueprint_url_parts['query'] ?? '' ), $preview_blueprint_url_query );
$assert( '/?preview=1' === ( $preview_blueprint_url_query['url'] ?? '' ), 'rest-preview-playground-url-lands-on-relative-preview-path' );
$assert( 'function' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['type'] ?? '' ), 'rest-preview-codebox-invokes-ssi-import-function-type' );
$assert( 'static_site_importer_ability_import_website_artifact' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['name'] ?? '' ), 'rest-preview-codebox-invokes-ssi-import-function' );
$assert( true === ( WP_Codebox_Abilities::$last_input['include_raw_browser_session'] ?? null ), 'rest-preview-codebox-requests-raw-session-for-blueprint-extraction' );
$assert( true === ( WP_Codebox_Abilities::$last_input['runtime']['prepared_runtime']['enabled'] ?? null ), 'rest-preview-codebox-enables-prepared-runtime-for-blueprint-ref' );
$assert( 'static-site-importer-preview' === ( WP_Codebox_Abilities::$last_input['runtime']['prepared_runtime']['cache_key'] ?? '' ), 'rest-preview-codebox-uses-stable-prepared-runtime-cache-key' );
$assert( 'static-site-importer' === ( WP_Codebox_Abilities::$last_input['runtime']['plugins'][0]['slug'] ?? '' ), 'rest-preview-codebox-installs-ssi-runtime-plugin' );
$assert( true === ( WP_Codebox_Abilities::$last_input['runtime']['plugins'][0]['activate'] ?? null ), 'rest-preview-codebox-activates-ssi-runtime-plugin' );
$assert( isset( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact'] ), 'rest-preview-codebox-request-includes-artifact' );
$assert( 'website/uploaded/site/index.html' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact']['files'][0]['path'] ?? '' ), 'rest-directory-path-is-normalized' );
$assert( 'uploaded/site/index.html' === ( WP_Codebox_Abilities::$last_input['artifact_files'][0]['path'] ?? '' ), 'rest-preview-codebox-strips-website-prefix-for-browser-artifacts' );
$assert( isset( $preview_response['preview_attempt']['request_id'] ), 'rest-preview-response-exposes-attempt-id' );
$attempts = get_option( 'static_site_importer_preview_attempts', array() );
$assert( 1 === count( $attempts ), 'rest-preview-persists-attempt' );
$ready_attempt = $attempts[0] ?? array();
$assert( 'static-site-importer/preview-attempt/v1' === ( $ready_attempt['schema'] ?? '' ), 'rest-preview-attempt-schema' );
$assert( 'files' === ( $ready_attempt['source']['type'] ?? '' ), 'rest-preview-attempt-source-type' );
$assert( 1 === ( $ready_attempt['source']['file_count'] ?? 0 ), 'rest-preview-attempt-file-count' );
$assert( 'website/uploaded/site/index.html' === ( $ready_attempt['artifact']['entrypoint'] ?? '' ), 'rest-preview-attempt-entrypoint' );
$assert( 'absolute_preview_url_found' === ( $ready_attempt['preview_url_extraction']['status'] ?? '' ), 'rest-preview-attempt-records-preview-extraction-success' );
$assert( 'https://preview.example.test/ssi' === ( $ready_attempt['preview_url_extraction']['selected_url'] ?? '' ), 'rest-preview-attempt-records-selected-url' );
$assert( false === str_contains( wp_json_encode( $ready_attempt ), '<main>Hello</main>' ), 'rest-preview-attempt-omits-raw-file-content' );

WP_Codebox_Abilities::$next_session = array(
	'success'       => true,
	'schema'        => 'wp-codebox/browser-session-product-dto/v1',
	'session_id'    => 'ssi-product-session',
	'execution'     => 'browser-playground',
	'blueprint_ref' => array(
		'schema'             => 'wp-codebox/browser-blueprint-ref/v1',
		'ref'                => 'prepared:ssi-product:' . str_repeat( 'b', 64 ),
		'hydration_endpoint' => '/wp-json/wp-codebox/v1/browser-blueprint-ref?ref=prepared%3Assi-product%3A' . str_repeat( 'b', 64 ),
	),
);
$product_source   = array(
	'files' => array(
		array(
			'path'    => 'uploaded/product/index.html',
			'content' => '<main>Product DTO</main>',
		),
	),
);
$product_response = static_site_importer_rest_create_preview(
	$product_source,
	static_site_importer_rest_import_args( array() ),
	array( 'source' => $product_source )
);
$assert( true === ( $product_response['success'] ?? null ), 'rest-preview-product-dto-succeeds-with-blueprint-ref' );
$assert( isset( $product_response['preview']['playground']['blueprint_url'] ), 'rest-preview-product-dto-exposes-playground-blueprint-url' );

Static_Site_Importer_Theme_Generator::$last_artifact = array();
WP_Codebox_Abilities::$next_session = array(
	'success'    => true,
	'schema'     => 'wp-codebox/browser-playground-session/v1',
	'session'    => array( 'id' => 'ssi-preview-session-no-url' ),
	'playground' => array(),
	'artifacts'  => array(),
);
$unavailable_source   = array( 'html' => '<main>No provider</main>' );
$unavailable_response = static_site_importer_rest_create_preview(
	$unavailable_source,
	static_site_importer_rest_import_args( array() ),
	array( 'source' => $unavailable_source )
);
$assert( false === ( $unavailable_response['success'] ?? null ), 'rest-preview-default-does-not-pretend-success' );
$assert( 'unavailable' === ( $unavailable_response['preview']['status'] ?? '' ), 'rest-preview-default-reports-unavailable' );
$assert( str_contains( $unavailable_response['preview']['message'] ?? '', 'WP Codebox did not return a preview URL or Playground blueprint URL' ), 'rest-preview-default-codebox-no-url-diagnostic' );
$assert( array() === Static_Site_Importer_Theme_Generator::$last_artifact, 'rest-preview-default-does-not-apply-to-current-site' );
$attempts = get_option( 'static_site_importer_preview_attempts', array() );
$assert( 3 === count( $attempts ), 'rest-preview-persists-unavailable-attempt' );
$failed_attempt = $attempts[2] ?? array();
$assert( 'html' === ( $failed_attempt['source']['type'] ?? '' ), 'rest-preview-unavailable-attempt-source-type' );
$assert( 1 === ( $failed_attempt['source']['file_count'] ?? 0 ), 'rest-preview-unavailable-attempt-file-count' );
$assert( 'ssi-preview-session-no-url' === ( $failed_attempt['codebox']['session']['session_id'] ?? '' ), 'rest-preview-unavailable-attempt-session-id' );
$assert( 'missing_absolute_preview_url' === ( $failed_attempt['preview_url_extraction']['status'] ?? '' ), 'rest-preview-unavailable-attempt-extraction-status' );
$assert( 'unavailable' === ( $failed_attempt['final']['status'] ?? '' ), 'rest-preview-unavailable-attempt-final-status' );
$assert( false === str_contains( wp_json_encode( $failed_attempt ), '<main>No provider</main>' ), 'rest-preview-unavailable-attempt-omits-raw-html' );

$GLOBALS['ssi_test_options']['static_site_importer_figma_allow_local_runner'] = true;
$GLOBALS['ssi_home_url'] = 'http://localhost:8882/';
$local_figma_request = new WP_REST_Request( array() );
$assert( static_site_importer_rest_import_figma_allows_local_runner( $local_figma_request ), 'figma-runner-allows-localhost-site-by-default' );
$GLOBALS['ssi_home_url'] = 'https://remote.example.test/';
$assert( ! static_site_importer_rest_import_figma_allows_local_runner( $local_figma_request ), 'figma-runner-blocks-remote-site-without-allowed-host-setting' );
$GLOBALS['ssi_test_options']['static_site_importer_figma_allowed_site_hosts'] = array( 'remote.example.test' );
$assert( static_site_importer_rest_import_figma_allows_local_runner( $local_figma_request ), 'figma-runner-allows-configured-remote-site-host' );
$GLOBALS['ssi_home_url'] = 'https://example.test/';
unset( $GLOBALS['ssi_test_options']['static_site_importer_figma_allow_local_runner'], $GLOBALS['ssi_test_options']['static_site_importer_figma_allowed_site_hosts'] );

$codebox_missing = static_site_importer_rest_preview_unavailable_result( array( 'schema' => 'static-site-importer/preview-request/v1' ) );
$assert( false === ( $codebox_missing['success'] ?? null ), 'rest-codebox-unavailable-does-not-pretend-success' );
$assert( 'wp-codebox/create-browser-playground-session' === ( $codebox_missing['provider'] ?? '' ), 'rest-codebox-unavailable-identifies-required-api' );
$assert( str_contains( $codebox_missing['preview']['message'] ?? '', 'WP Codebox is unavailable, not installed, or does not provide the required browser Playground session API' ), 'rest-codebox-unavailable-diagnostic-wording' );

$assert( 'playground' === static_site_importer_rest_import_mode( array() ), 'rest-mode-defaults-to-open-in-playground' );
$assert( 'playground' === static_site_importer_rest_import_mode( array( 'open_in_playground' => true ) ), 'rest-mode-supports-open-in-playground' );
$assert( 'current_site' === static_site_importer_rest_import_mode( array( 'apply_to_current_site' => true, 'open_in_playground' => true ) ), 'rest-mode-current-site-import-wins-when-explicit' );

Static_Site_Importer_Theme_Generator::$last_artifact = array();
$apply_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => true,
			'activate'              => true,
			'overwrite'             => true,
			'source'                => array(
				'html' => '<main>Apply</main>',
			),
		)
	)
);
$assert( true === ( $apply_response['success'] ?? null ), 'rest-current-site-apply-is-explicitly-available' );
$assert( true === ( Static_Site_Importer_Theme_Generator::$last_args['activate'] ?? null ), 'rest-current-site-apply-preserves-activate' );
$assert( isset( $apply_response['result'] ), 'rest-current-site-apply-returns-ability-envelope' );
$assert( 'https://example.test/' === ( $apply_response['preview']['url'] ?? '' ), 'rest-current-site-apply-returns-site-preview-url' );

Static_Site_Importer_Theme_Generator::$last_artifact = array();
Static_Site_Importer_Theme_Generator::$last_args     = array();
WP_Codebox_Abilities::$last_input = array();
$playground_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => false,
			'open_in_playground'    => true,
			'source'                => array(
				'html' => '<main>Generate</main>',
			),
		)
	)
);
$assert( true === ( $playground_response['success'] ?? null ), 'rest-playground-open-succeeds-without-codebox' );
$assert( 'playground' === ( $playground_response['mode'] ?? '' ), 'rest-playground-open-reports-mode' );
$assert( array() === Static_Site_Importer_Theme_Generator::$last_args, 'rest-playground-open-does-not-import-into-current-site' );
$assert( array() === WP_Codebox_Abilities::$last_input, 'rest-playground-open-does-not-use-codebox-preview' );
$assert( str_starts_with( $playground_response['preview']['url'] ?? '', 'https://playground.wordpress.net/#' ), 'rest-playground-open-returns-direct-playground-blueprint-url' );
$assert( ! str_starts_with( $playground_response['preview']['url'] ?? '', 'https://playground.wordpress.net/?url=' ), 'rest-playground-open-does-not-return-empty-playground-url' );
$assert( '/' === ( $playground_response['preview']['playground']['preview_url'] ?? '' ), 'rest-playground-open-records-playground-preview-path' );
$playground_blueprint_json = rawurldecode( substr( (string) ( $playground_response['preview']['playground']['blueprint_url'] ?? '' ), strlen( 'https://playground.wordpress.net/#' ) ) );
$playground_blueprint      = json_decode( $playground_blueprint_json, true );
$assert( is_array( $playground_blueprint ), 'rest-playground-open-blueprint-decodes' );
$playground_blueprint_code = wp_json_encode( $playground_blueprint );
$playground_plugin_step    = $playground_blueprint['steps'][1] ?? array();
$assert( 'https://github.com/Automattic/static-site-importer/releases/latest/download/static-site-importer.zip' === ( $playground_plugin_step['pluginData']['url'] ?? '' ), 'rest-playground-open-blueprint-installs-release-zip' );
$assert( str_contains( (string) $playground_blueprint_code, 'static_site_importer_ability_import_website_artifact' ), 'rest-playground-open-blueprint-runs-import-ability' );
$assert( str_contains( (string) $playground_blueprint_code, "'activate' => true" ), 'rest-playground-open-blueprint-activates-generated-site' );
$assert( str_contains( (string) $playground_blueprint_code, "'overwrite' => true" ), 'rest-playground-open-blueprint-overwrites-generated-site' );
// Sourceless HTML (no <title>, no URL) still falls back to the generic identity constant.
$assert( str_contains( (string) $playground_blueprint_code, "'slug' => 'generated-wordpress-website'" ), 'rest-playground-open-blueprint-uses-non-legacy-generated-theme-slug' );
$assert( str_contains( (string) $playground_blueprint_code, "'name' => 'Generated WordPress Website'" ), 'rest-playground-open-blueprint-uses-generated-theme-name' );
$assert( ! str_contains( (string) $playground_blueprint_code, 'imported-website-artifact' ), 'rest-playground-open-blueprint-omits-legacy-theme-slug' );
$assert( ! str_contains( (string) $playground_blueprint_code, 'Codebox' ), 'rest-playground-open-blueprint-does-not-introduce-codebox' );

$GLOBALS['ssi_last_url_provider_request'] = array();
add_filter(
	'static_site_importer_url_import_provider',
	static function ( mixed $output, array $request ): mixed {
		if ( 'https://facebook.com' !== ( $request['url'] ?? '' ) ) {
			return $output;
		}

		$GLOBALS['ssi_last_url_provider_request'] = $request;

		return array(
			'provider'        => 'test-url-playground-provider',
			'artifact'        => array(
				'schema' => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
				'files'  => array(
					array(
						'path'    => 'website/index.html',
						'content' => '<!doctype html><html><head><title>Facebook Fixture</title></head><body><main>Facebook Fixture</main></body></html>',
					),
				),
			),
			'source_metadata' => array(
				'source_url'     => $request['url'],
				'provider_token' => $request['provider_args']['token'] ?? '',
			),
		);
	},
	10,
	2
);

$url_playground_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => false,
			'open_in_playground'    => true,
			'source'                => array(
				'url' => 'facebook.com',
			),
			'provider'              => 'test-provider',
			'provider_args'         => array(
				'token' => 'playground-provider-arg',
			),
		)
	)
);
$assert( ! is_wp_error( $url_playground_response ), 'rest-playground-url-only-does-not-error', is_wp_error( $url_playground_response ) ? $url_playground_response->get_error_code() . ': ' . $url_playground_response->get_error_message() : '' );
if ( is_wp_error( $url_playground_response ) ) {
	$url_playground_response = array();
}
$assert( true === ( $url_playground_response['success'] ?? null ), 'rest-playground-url-only-succeeds' );
$assert( 'https://facebook.com' === ( $GLOBALS['ssi_last_url_provider_request']['url'] ?? '' ), 'rest-playground-url-only-normalizes-bare-host' );
$assert( 'test-provider' === ( $GLOBALS['ssi_last_url_provider_request']['provider'] ?? '' ), 'rest-playground-url-only-preserves-provider-request' );
$assert( 'playground-provider-arg' === ( $GLOBALS['ssi_last_url_provider_request']['provider_args']['token'] ?? '' ), 'rest-playground-url-only-preserves-provider-args' );
$url_playground_blueprint_json = rawurldecode( substr( (string) ( $url_playground_response['preview']['playground']['blueprint_url'] ?? '' ), strlen( 'https://playground.wordpress.net/#' ) ) );
$url_playground_blueprint      = json_decode( $url_playground_blueprint_json, true );
$url_playground_import_code    = is_array( $url_playground_blueprint ) ? (string) ( $url_playground_blueprint['steps'][2]['code'] ?? '' ) : '';
$assert( str_contains( $url_playground_blueprint_json, 'Facebook Fixture' ), 'rest-playground-url-only-blueprint-contains-fetched-artifact' );
$assert( str_contains( $url_playground_import_code, "'source_url' => 'https://facebook.com'" ), 'rest-playground-url-only-blueprint-preserves-source-url' );
$assert( str_contains( $url_playground_import_code, "'provider_token' => 'playground-provider-arg'" ), 'rest-playground-url-only-blueprint-preserves-provider-arg-metadata' );
$assert( str_contains( $url_playground_import_code, "'url_import_provider' => 'test-url-playground-provider'" ), 'rest-playground-url-only-blueprint-preserves-provider' );
$assert( array() === Static_Site_Importer_Theme_Generator::$last_args, 'rest-playground-url-only-does-not-import-current-site' );

Static_Site_Importer_Theme_Generator::$last_args = array();
$current_site_url_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => true,
			'source'                => array(
				'url' => 'facebook.com',
			),
		)
	)
);
$assert( ! is_wp_error( $current_site_url_response ), 'rest-current-site-url-only-does-not-error', is_wp_error( $current_site_url_response ) ? $current_site_url_response->get_error_code() . ': ' . $current_site_url_response->get_error_message() : '' );
$assert( 'https://facebook.com' === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata']['source_url'] ?? '' ), 'rest-current-site-url-only-normalizes-bare-host' );

$client_shell_html = '<!doctype html><html><head><title>Client App</title>' . str_repeat( '<script src="/bundle.js"></script>', 25 ) . '</head><body><div id="root"></div></body></html>' . str_repeat( ' ', 120000 );
$pasted_shell_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => false,
			'source'                => array(
				'html' => $client_shell_html,
			),
		)
	)
);
$assert( is_wp_error( $pasted_shell_response ), 'rest-pasted-client-shell-errors' );
$assert( 'static_site_importer_client_rendered_app_shell' === ( is_wp_error( $pasted_shell_response ) ? $pasted_shell_response->get_error_code() : '' ), 'rest-pasted-client-shell-error-code' );
$pasted_shell_error_data = is_wp_error( $pasted_shell_response ) ? $pasted_shell_response->get_error_data() : array();
$assert( 'website/index.html' === ( $pasted_shell_error_data['diagnostic']['source_path'] ?? '' ), 'rest-pasted-client-shell-diagnostic-source-path' );

$uploaded_shell_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => false,
			'source'                => array(
				'files' => array(
					array(
						'path'           => 'index.html',
						'content_base64' => base64_encode( $client_shell_html ),
					),
				),
			),
		)
	)
);
$assert( is_wp_error( $uploaded_shell_response ), 'rest-uploaded-client-shell-errors' );
$assert( 'static_site_importer_client_rendered_app_shell' === ( is_wp_error( $uploaded_shell_response ) ? $uploaded_shell_response->get_error_code() : '' ), 'rest-uploaded-client-shell-error-code' );
$uploaded_shell_error_data = is_wp_error( $uploaded_shell_response ) ? $uploaded_shell_response->get_error_data() : array();
$assert( 'website/index.html' === ( $uploaded_shell_error_data['diagnostic']['source_path'] ?? '' ), 'rest-uploaded-client-shell-diagnostic-source-path' );

// A real source document title now names the generated theme/site via the
// site-identity primitive instead of collapsing to the generic constant.
Static_Site_Importer_Theme_Generator::$last_args = array();
$titled_playground_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'apply_to_current_site' => false,
			'open_in_playground'    => true,
			'source'                => array(
				'html' => '<!doctype html><html><head><title>Maya &amp; Devon &#8212; Home</title></head><body><main>Generate</main></body></html>',
			),
		)
	)
);
$titled_blueprint_json = rawurldecode( substr( (string) ( $titled_playground_response['preview']['playground']['blueprint_url'] ?? '' ), strlen( 'https://playground.wordpress.net/#' ) ) );
$titled_blueprint      = json_decode( $titled_blueprint_json, true );
$titled_blueprint_code = wp_json_encode( is_array( $titled_blueprint ) ? $titled_blueprint : array() );
$assert( str_contains( (string) $titled_blueprint_code, "'name' => 'Maya & Devon'" ), 'rest-playground-open-derives-name-from-source-document-title' );
$assert( str_contains( (string) $titled_blueprint_code, "'slug' => 'maya-devon'" ), 'rest-playground-open-derives-slug-from-source-document-title' );
$assert( ! str_contains( (string) $titled_blueprint_code, 'generated-wordpress-website' ), 'rest-playground-open-titled-source-omits-generic-constant' );
$assert( ! str_contains( (string) $titled_blueprint_code, 'url_import_provider' ), 'rest-playground-open-non-url-source-omits-url-provider-metadata' );

$figma_upload_artifact = Static_Site_Importer_Figma_Import::website_artifact_from_input(
	array(
		'source' => array(
			'figma_file' => array(
				'name'           => 'design.fig',
				'type'           => 'application/octet-stream',
				'content_base64' => base64_encode( 'not-a-zip' ),
			),
		),
	)
);
$assert( is_wp_error( $figma_upload_artifact ) || is_array( $figma_upload_artifact ), 'figma-file-source-routes-through-transformer' );
if ( is_wp_error( $figma_upload_artifact ) && 'static_site_importer_figma_transform_empty' === $figma_upload_artifact->get_error_code() ) {
	$figma_upload_error_data = $figma_upload_artifact->get_error_data();
	$assert( isset( $figma_upload_error_data['diagnostic'] ) && is_array( $figma_upload_error_data['diagnostic'] ), 'figma-empty-transform-error-exposes-diagnostic' );
	$assert( 'Blocks Engine Figma transformer did not produce importable files.' !== $figma_upload_artifact->get_error_message(), 'figma-empty-transform-error-message-includes-diagnostic' );
}
$generic_fig_artifact = static_site_importer_rest_source_artifact(
	array(
		'files' => array(
			array(
				'path'           => 'design.fig',
				'content_base64' => base64_encode( 'not-a-site' ),
			),
		),
	)
);
$assert( is_wp_error( $generic_fig_artifact ), 'rest-generic-static-upload-ignores-fig-file' );

if ( class_exists( 'ZipArchive' ) ) {
	$fig_payload = array(
		'name'         => 'Public Import Fixture',
		'NODE_CHANGES' => array(
			'4:1' => array(
				'node' => array(
					'id'       => '4:1',
					'type'     => 'FRAME',
					'name'     => 'Landing',
					'children' => array(
						array(
							'id'         => '4:2',
							'type'       => 'TEXT',
							'name'       => 'Heading',
							'characters' => 'Synthetic FIG Upload',
						),
					),
				),
			),
		),
	);
	$fig_json    = wp_json_encode( $fig_payload );
	$fig_chunk   = gzdeflate( (string) $fig_json );
	$fig_canvas  = 'fig-kiwi' . pack( 'V', 106 ) . pack( 'V', strlen( $fig_chunk ) ) . $fig_chunk;
	$fig_path    = tempnam( sys_get_temp_dir(), 'ssi-fig-smoke-' );
	$fig_archive = new ZipArchive();
	$fig_archive->open( $fig_path, ZipArchive::OVERWRITE );
	$fig_archive->addFromString( 'canvas.fig', $fig_canvas );
	$fig_archive->addFromString( 'meta.json', '{"name":"Public Import Fixture"}' );
	$fig_archive->close();

	Static_Site_Importer_Theme_Generator::$last_artifact = array();
	Static_Site_Importer_Theme_Generator::$last_args     = array();
	WP_Codebox_Abilities::$last_input = array();
	$fig_upload_response = static_site_importer_rest_create_import(
		new WP_REST_Request(
			array(
				'apply_to_current_site' => false,
				'source'                => array(
					'figma_file' => array(
						'name'           => 'design.fig',
						'type'           => 'application/octet-stream',
						'content_base64' => base64_encode( file_get_contents( $fig_path ) ),
					),
				),
			)
		)
	);
	$assert( ! is_wp_error( $fig_upload_response ), 'rest-fig-upload-playground-does-not-error', is_wp_error( $fig_upload_response ) ? $fig_upload_response->get_error_code() . ': ' . $fig_upload_response->get_error_message() : '' );
	if ( is_wp_error( $fig_upload_response ) ) {
		$fig_upload_response = array();
	}
	$assert( true === ( $fig_upload_response['success'] ?? null ), 'rest-fig-upload-playground-succeeds' );
	$assert( 'playground' === ( $fig_upload_response['mode'] ?? '' ), 'rest-fig-upload-uses-playground-mode' );
	$assert( str_starts_with( $fig_upload_response['preview']['url'] ?? '', 'https://playground.wordpress.net/#' ), 'rest-fig-upload-returns-direct-playground-blueprint-url' );
	$assert( 'figma_file' === ( $fig_upload_response['request']['source'] ?? '' ), 'rest-fig-upload-records-distinct-source' );
	$assert( array() === Static_Site_Importer_Theme_Generator::$last_args, 'rest-fig-upload-playground-does-not-import-current-site' );
	$assert( array() === WP_Codebox_Abilities::$last_input, 'rest-fig-upload-playground-does-not-use-codebox' );
	$fig_upload_blueprint_json = rawurldecode( substr( (string) ( $fig_upload_response['preview']['playground']['blueprint_url'] ?? '' ), strlen( 'https://playground.wordpress.net/#' ) ) );
	$assert( str_contains( $fig_upload_blueprint_json, 'Synthetic FIG Upload' ), 'rest-fig-upload-blueprint-contains-transformed-artifact' );
	$assert( ! str_contains( $fig_upload_blueprint_json, 'content_base64' ), 'rest-fig-upload-blueprint-does-not-carry-raw-fig-source' );

	$fig_multipart_response = static_site_importer_rest_import_figma_file(
		new WP_REST_Request(
			array(
				'apply_to_current_site' => false,
			),
			array(
				'figma_file' => array(
					'name'     => 'design.fig',
					'type'     => 'application/octet-stream',
					'tmp_name' => $fig_path,
					'error'    => 0,
					'size'     => filesize( $fig_path ),
				),
			)
		)
	);
	@unlink( $fig_path );
	$assert( ! is_wp_error( $fig_multipart_response ), 'rest-fig-multipart-playground-does-not-error', is_wp_error( $fig_multipart_response ) ? $fig_multipart_response->get_error_code() . ': ' . $fig_multipart_response->get_error_message() : '' );
	if ( is_wp_error( $fig_multipart_response ) ) {
		$fig_multipart_response = array();
	}
	$assert( true === ( $fig_multipart_response['success'] ?? null ), 'rest-fig-multipart-playground-succeeds' );
	$assert( 'playground' === ( $fig_multipart_response['mode'] ?? '' ), 'rest-fig-multipart-uses-playground-mode' );
	$assert( str_starts_with( $fig_multipart_response['preview']['url'] ?? '', 'https://playground.wordpress.net/#' ), 'rest-fig-multipart-returns-direct-playground-blueprint-url' );
	$fig_multipart_blueprint_json = rawurldecode( substr( (string) ( $fig_multipart_response['preview']['playground']['blueprint_url'] ?? '' ), strlen( 'https://playground.wordpress.net/#' ) ) );
	$assert( str_contains( $fig_multipart_blueprint_json, 'Synthetic FIG Upload' ), 'rest-fig-multipart-blueprint-contains-transformed-artifact' );
	$assert( ! str_contains( $fig_multipart_blueprint_json, 'content_base64' ), 'rest-fig-multipart-blueprint-does-not-carry-raw-fig-source' );
}

$blueprint = json_decode( file_get_contents( dirname( __DIR__ ) . '/docs/playground/blueprint.json' ), true );
$assert( is_array( $blueprint ), 'playground-blueprint-decodes' );
$assert( '/import/' === ( $blueprint['landingPage'] ?? '' ), 'playground-blueprint-lands-on-import-page' );
$blueprint_code = implode( "\n", array_map( static fn( array $step ): string => isset( $step['code'] ) ? (string) $step['code'] : '', $blueprint['steps'] ?? array() ) );
$assert( str_contains( $blueprint_code, 'static-site-importer/importer' ), 'playground-blueprint-creates-importer-block-page' );
$assert( str_contains( $blueprint_code, '"title":"Import HTML to WordPress"' ), 'playground-blueprint-uses-import-html-title' );
$assert( str_contains( $blueprint_code, '"intro":"Upload a website or paste HTML. Static Site Importer will generate your new WordPress website."' ), 'playground-blueprint-uses-generate-website-copy' );
$assert( str_contains( $blueprint_code, '"applyToCurrentSite":false' ), 'playground-blueprint-generates-wordpress-website' );
$assert( str_contains( $blueprint_code, '"openInPlayground":true' ), 'playground-blueprint-targets-open-in-playground' );
$assert( ! str_contains( $blueprint_code, 'generateInCurrentRuntime' ), 'playground-blueprint-does-not-reference-current-runtime-generation' );
$assert( str_contains( $blueprint_code, 'static_site_importer_protected_pages' ), 'playground-blueprint-protects-import-page' );
$plugin_step = $blueprint['steps'][1] ?? array();
$assert( 'https://github.com/Automattic/static-site-importer/releases/latest/download/static-site-importer.zip' === ( $plugin_step['pluginData']['url'] ?? '' ), 'playground-blueprint-installs-packaged-release' );

$GLOBALS['ssi_test_options']['static_site_importer_protected_pages'] = array( 'import', 'tools/settings', '42' );
$assert( Static_Site_Importer_Page_Materializer::is_protected_page( new WP_Post( 7, 'import' ) ), 'protected-page-matches-slug' );
$assert( Static_Site_Importer_Page_Materializer::is_protected_page( new WP_Post( 8, 'settings' ) ), 'protected-page-matches-path' );
$assert( Static_Site_Importer_Page_Materializer::is_protected_page( new WP_Post( 42, 'other' ) ), 'protected-page-matches-id' );
$assert( ! Static_Site_Importer_Page_Materializer::is_protected_page( new WP_Post( 9, 'ordinary' ) ), 'ordinary-page-is-not-protected' );

$html_source_page = Static_Site_Importer_Source_Page::from_materialization_plan_page(
	array(
		'source_path'  => 'website/index.html',
		'title'        => 'Figma HTML',
		'slug'         => 'figma-html',
		'body_format' => 'blocks',
		'block_markup' => '<!-- wp:heading {"level":1} --><h1>Figma HTML</h1><!-- /wp:heading --><!-- wp:image {"url":"assets/hero.png","alt":"Hero"} --><figure class="wp-block-image"><img src="assets/hero.png" alt="Hero" /></figure><!-- /wp:image -->',
	)
);
$assert( ! is_wp_error( $html_source_page ), 'html-materialization-source-page-builds' );
if ( ! is_wp_error( $html_source_page ) ) {
	$html_page_artifacts = Static_Site_Importer_Page_Materializer::page_artifacts(
		array( 'website/index.html' => $html_source_page ),
		'figma-import',
		array(
			'website/assets/hero.png' => array(
				'final_url' => 'https://example.test/wp-content/themes/figma-import/assets/materialized/website/assets/hero.png',
			),
		)
	);
	$assert( ! str_contains( $html_page_artifacts['contents']['website/index.html'] ?? '', '<!-- wp:html -->' ), 'html-materialization-avoids-core-html-block' );
	$assert( str_contains( $html_page_artifacts['contents']['website/index.html'] ?? '', '<!-- wp:heading {"level":1} -->' ), 'html-materialization-converts-html-to-blocks' );
	$assert( str_contains( $html_page_artifacts['contents']['website/index.html'] ?? '', 'https://example.test/wp-content/themes/figma-import/assets/materialized/website/assets/hero.png' ), 'html-materialization-rewrites-root-relative-asset-reference' );
	$assert( ! str_contains( $html_page_artifacts['contents']['website/index.html'] ?? '', '"url":"assets/hero.png"' ), 'html-materialization-rewrites-block-json-asset-reference' );
	$assert( array() === $html_page_artifacts['diagnostics'], 'html-materialization-does-not-emit-unsupported-format-diagnostic' );
}

Static_Site_Importer_Theme_Generator::$last_artifact = array();
Static_Site_Importer_Theme_Generator::$last_args     = array();
WP_Codebox_Abilities::$last_input = array();
WP_Codebox_Abilities::$next_session = array(
	'success'         => true,
	'schema'          => 'wp-codebox/browser-playground-session/v1',
	'execution'       => 'browser-playground',
	'execution_scope' => 'disposable-playground',
	'session'         => array(
		'id'     => 'ssi-figma-preview-session',
		'status' => 'ready',
	),
	'playground'      => array(
		'preview_url'      => '/?preview=1',
		'scope'            => 'ssi-figma-preview-session',
		'prepared_runtime' => array(
			'cache_key'  => 'ssi-figma-preview-cache',
			'input_hash' => str_repeat( 'c', 64 ),
		),
	),
);
$figma_response = static_site_importer_rest_import_figma(
	new WP_REST_Request(
		array(
			'schema'          => 'figma-to-wordpress/runner-request/v1',
			'source'          => array(
				'tool'       => 'figma',
				'nodeIds'    => array( '1:2' ),
				'exportedAt' => '2026-06-23T00:00:00.000Z',
			),
			'goal'            => 'Import Figma into WordPress.',
			'artifact_bundle' => array(
				'schema'        => 'figma-to-wordpress/website-artifact-bundle/v1',
				'root'          => 'website/',
				'entrypoint'    => 'website/index.html',
				'import_source' => 'figma-to-wordpress',
				'files'         => array(
					array(
						'path'      => 'website/index.html',
						'content'   => '<main><h1>Figma</h1></main>',
						'role'      => 'html',
						'mime_type' => 'text/html',
					),
					array(
						'path'      => 'website/assets/styles.css',
						'content'   => 'body{color:#111}',
						'role'      => 'css',
						'mime_type' => 'text/css',
					),
					array(
						'path'      => 'website/metadata.json',
						'content'   => '{"title":"Fisiostetic"}',
						'role'      => 'metadata',
						'mime_type' => 'application/json',
					),
				),
			),
		)
	)
);
$assert( true === ( $figma_response['success'] ?? null ), 'figma-rest-response-succeeds' );
$assert( 'figma-to-wordpress/runner-response/v1' === ( $figma_response['schema'] ?? '' ), 'figma-rest-response-uses-runner-schema' );
$assert( 'created' === ( $figma_response['status'] ?? '' ), 'figma-rest-response-created-status' );
$assert( str_starts_with( $figma_response['open_url'] ?? '', 'https://playground.wordpress.net/#' ), 'figma-rest-response-open-url-is-direct-playground-blueprint' );
$assert( isset( $figma_response['preview_session']['playground']['blueprint_url'] ), 'figma-rest-response-exposes-playground-blueprint-url' );
$assert( array() === Static_Site_Importer_Theme_Generator::$last_artifact, 'figma-rest-preview-does-not-apply-to-current-site' );
$assert( array() === WP_Codebox_Abilities::$last_input, 'figma-rest-preview-does-not-use-codebox-runner' );
$figma_open_url_parts = wp_parse_url( (string) $figma_response['open_url'] );
$assert( isset( $figma_open_url_parts['fragment'] ), 'figma-playground-uses-self-contained-blueprint-fragment' );
$figma_blueprint_ref = (string) ( $figma_response['preview_session']['playground']['ref'] ?? '' );
$assert( preg_match( '/^[a-f0-9]{64}$/', $figma_blueprint_ref ) === 1, 'figma-preview-exposes-blueprint-ref' );
$figma_blueprint_response = json_decode( rawurldecode( (string) ( $figma_open_url_parts['fragment'] ?? '' ) ), true );
$assert( is_array( $figma_blueprint_response ), 'figma-blueprint-hydrates' );
$figma_blueprint_code = wp_json_encode( $figma_blueprint_response );
$assert( str_contains( (string) $figma_blueprint_code, 'static_site_importer_ability_import_website_artifact' ), 'figma-blueprint-invokes-ssi-import-function' );
$assert( str_contains( (string) $figma_blueprint_code, 'website/index.html' ) || str_contains( (string) $figma_blueprint_code, 'website\/index.html' ), 'figma-artifact-entrypoint-normalized' );
$assert( str_contains( (string) $figma_blueprint_code, 'website/assets/styles.css' ) || str_contains( (string) $figma_blueprint_code, 'website\/assets\/styles.css' ), 'figma-artifact-file-path-normalized' );
$assert( str_contains( (string) $figma_blueprint_code, "'activate' => true" ), 'figma-preview-defaults-to-activate-in-playground' );
$assert( str_contains( (string) $figma_blueprint_code, "'overwrite' => true" ), 'figma-preview-defaults-to-overwrite-in-playground' );
$assert( str_contains( (string) $figma_blueprint_code, "'name' => 'Fisiostetic'" ), 'figma-import-name-derived-from-metadata' );
$assert( str_contains( (string) $figma_blueprint_code, "'site_title' => 'Fisiostetic'" ), 'figma-import-site-title-derived-from-metadata' );
$assert( str_contains( (string) $figma_blueprint_code, "'source' => 'figma-to-wordpress'" ), 'figma-artifact-provenance-source' );

$figma_diagnostics_input = array(
	'schema'     => 'figma-to-wordpress/runner-request/v1',
	'source'     => array(
		'fileKey' => 'fixture-file-key',
		'nodeIds' => array( '1:1' ),
	),
	'slug'       => 'figma-diagnostics',
	'name'       => 'Figma Diagnostics',
	'scenegraph' => array(
		'name'  => 'Diagnostics Fixture',
		'nodes' => array(
			array(
				'id'       => '1:1',
				'type'     => 'FRAME',
				'name'     => 'Landing Page',
				'width'    => 640,
				'height'   => 360,
				'children' => array(
					array(
						'id'       => '1:2',
						'type'     => 'TEXT',
						'name'     => 'Heading',
						'text'     => 'Hello diagnostics',
						'fontSize' => 32,
					),
				),
			),
		),
	),
);
$figma_diagnostics       = Static_Site_Importer_Figma_Import::diagnostics_report(
	$figma_diagnostics_input
);
$assert( is_array( $figma_diagnostics ), 'figma-diagnostics-builds-report' );
$assert( true === ( $figma_diagnostics['success'] ?? null ), 'figma-diagnostics-succeeds' );
$assert( 'static-site-importer/figma-diagnostics/v1' === ( $figma_diagnostics['schema'] ?? '' ), 'figma-diagnostics-uses-schema' );
$assert( true === ( $figma_diagnostics['request']['has_scenegraph'] ?? null ), 'figma-diagnostics-summarizes-scenegraph-request' );
$assert( 'website/index.html' === ( $figma_diagnostics['artifact']['entrypoint'] ?? '' ), 'figma-diagnostics-summarizes-artifact-entrypoint' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( $figma_diagnostics['figma_transform_report']['schema'] ?? '' ), 'figma-diagnostics-exposes-durable-transform-report' );
$assert( isset( $figma_diagnostics['transform_diagnostics']['diagnostic_codes'] ), 'figma-diagnostics-exposes-transform-diagnostics' );
$assert( 'figma-diagnostics' === ( $figma_diagnostics['production_import_input']['slug'] ?? '' ), 'figma-diagnostics-summarizes-production-import-input' );

Static_Site_Importer_Theme_Generator::$last_artifact = array();
Static_Site_Importer_Theme_Generator::$last_args     = array();
$figma_import_result = Static_Site_Importer_Figma_Import::import( $figma_diagnostics_input );
$assert( true === ( $figma_import_result['success'] ?? null ), 'figma-import-scenegraph-succeeds' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( $figma_import_result['figma_transform_report']['schema'] ?? '' ), 'figma-import-result-exposes-durable-transform-report' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( Static_Site_Importer_Theme_Generator::$last_artifact['provenance']['figma_transform_report']['schema'] ?? '' ), 'figma-artifact-provenance-preserves-transform-report' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata']['figma_transform_report']['schema'] ?? '' ), 'figma-import-source-metadata-preserves-transform-report' );

if ( class_exists( 'ZipArchive' ) ) {
	$zip_path = tempnam( sys_get_temp_dir(), 'ssi-test-' );
	$zip      = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::OVERWRITE );
	$zip->addFromString( 'site/index.html', '<main>ZIP</main>' );
	$zip->addFromString( '../escape.html', '<main>Escape</main>' );
	$zip->close();

	$artifact = static_site_importer_rest_source_artifact(
		array(
			'archive' => array(
				'name'           => 'site.zip',
				'content_base64' => base64_encode( file_get_contents( $zip_path ) ),
			),
		)
	);
	@unlink( $zip_path );
	$assert( is_array( $artifact ), 'rest-zip-artifact-builds' );
	$paths = array_column( $artifact['files'] ?? array(), 'path' );
	$assert( in_array( 'website/site/index.html', $paths, true ), 'rest-zip-extracts-normalized-entry' );
	$assert( in_array( 'website/escape.html', $paths, true ), 'rest-zip-strips-traversal-entry' );
}

$artifact = static_site_importer_rest_source_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<main>Site</main>',
			),
			array(
				'path'    => 'result.json',
				'content' => '{"figma":"metadata"}',
			),
			array(
				'path'    => 'figma-export/result.json',
				'content' => '{"figma":"metadata"}',
			),
			array(
				'path'    => '.DS_Store',
				'content' => 'macos',
			),
			array(
				'path'    => 'assets/data.json',
				'content' => '{"site":"data"}',
			),
		),
	)
);
$assert( is_array( $artifact ), 'rest-artifact-skips-non-site-metadata-builds' );
$paths = array_column( $artifact['files'] ?? array(), 'path' );
$assert( in_array( 'website/index.html', $paths, true ), 'rest-artifact-keeps-html-file' );
$assert( in_array( 'website/assets/data.json', $paths, true ), 'rest-artifact-keeps-site-json-asset' );
$assert( ! in_array( 'website/result.json', $paths, true ), 'rest-artifact-skips-root-figma-result-json' );
$assert( ! in_array( 'website/figma-export/result.json', $paths, true ), 'rest-artifact-skips-nested-figma-result-json' );
$assert( ! in_array( 'website/.DS_Store', $paths, true ), 'rest-artifact-skips-macos-metadata-file' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Importer block smoke passed (%d assertions).\n", $assertions );
