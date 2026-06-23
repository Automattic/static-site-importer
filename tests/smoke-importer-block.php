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
$GLOBALS['ssi_home_url']         = 'https://example.test/';
$GLOBALS['ssi_uuid_count']       = 0;

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
		return $value;
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

		public function __construct( array $params ) {
			$this->params = $params;
		}

		public function get_json_params(): array {
			return $this->params;
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
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';
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

$metadata = json_decode( file_get_contents( dirname( __DIR__ ) . '/blocks/importer/block.json' ), true );
$assert( is_array( $metadata ), 'block-json-decodes' );
$assert( 'static-site-importer/importer' === ( $metadata['name'] ?? '' ), 'block-name-is-product-importer' );
$assert( 'Static Site Importer' === ( $metadata['title'] ?? '' ), 'block-title-is-product-name' );
$assert( isset( $metadata['viewScript'] ), 'block-has-frontend-script' );
$assert( false === ( $metadata['attributes']['applyToCurrentSite']['default'] ?? null ), 'block-defaults-to-preview-mode' );

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
$assert( str_contains( $html, 'data-static-site-importer-provider="privateprovider"' ), 'render-sanitizes-provider' );
$assert( str_contains( $html, 'data-static-site-importer-apply-to-current-site="1"' ), 'render-exposes-current-site-apply-flag' );
$assert( ! str_contains( $html, 'data-static-site-importer-source-url' ), 'render-omits-url-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-default-url="https://example.com/source"' ), 'render-preserves-default-url-for-programmatic-use' );
$assert( str_contains( $html, 'data-static-site-importer-source-files' ), 'render-has-file-input-hook' );
$assert( str_contains( $html, 'Upload file(s)' ), 'render-combines-file-upload-label' );
$assert( str_contains( $html, 'Drag a folder, ZIP, or static site files here.' ), 'render-has-upload-dropzone-copy' );
$assert( str_contains( $html, 'data-static-site-importer-dropzone' ), 'render-has-upload-dropzone-hook' );
$assert( str_contains( $html, 'Choose files or ZIP' ), 'render-has-file-or-zip-upload-affordance' );
$assert( str_contains( $html, 'Choose folder' ), 'render-has-folder-upload-affordance' );
$assert( str_contains( $html, 'data-static-site-importer-source-directory' ), 'render-has-directory-upload-hook' );
$assert( str_contains( $html, 'webkitdirectory' ), 'render-preserves-directory-upload-affordance' );
$assert( ! str_contains( $html, 'data-static-site-importer-source-archive' ), 'render-omits-separate-zip-upload-hook' );
$assert( str_contains( $html, 'accept=&quot;.zip,application/zip' ) || str_contains( $html, 'accept=".zip,application/zip' ), 'render-accepts-zip-in-combined-upload' );
$assert( str_contains( $html, 'data-static-site-importer-source-html' ), 'render-has-html-input-hook' );
$assert( str_contains( $html, '<summary class="ssi-importer__label">Paste HTML</summary>' ), 'render-collapses-paste-html-by-default' );
$assert( str_contains( $html, 'data-static-site-importer-submit' ), 'render-has-submit-hook' );
$assert( str_contains( $html, 'data-static-site-importer-preview-link' ), 'render-has-preview-link-hook' );
$assert( str_contains( $html, 'data-static-site-importer-report' ), 'render-has-report-hook' );
$assert( ! str_contains( $html, 'Import status' ), 'render-omits-import-status-section-copy' );
$assert( str_contains( $html, 'Import your site' ), 'render-uses-custom-title' );
$assert( str_contains( $html, 'https://example.com/source' ), 'render-uses-default-url' );
$assert( str_contains( static_site_importer_render_block(), 'Generate WordPress website' ), 'render-preview-mode-button-generates-wordpress-website' );

$view_js = file_get_contents( dirname( __DIR__ ) . '/blocks/importer/view.js' );
$assert( is_string( $view_js ), 'view-js-readable' );
$assert( str_contains( $view_js, 'webkitRelativePath' ), 'view-preserves-directory-relative-paths' );
$assert( str_contains( $view_js, 'data-static-site-importer-source-directory' ), 'view-reads-directory-upload-input' );
$assert( str_contains( $view_js, 'webkitGetAsEntry' ), 'view-supports-dropped-directory-entries' );
$assert( str_contains( $view_js, 'archive: await buildArchive( uploadInputs, root )' ), 'view-sends-zip-from-combined-upload-as-archive-payload' );
$assert( str_contains( $view_js, 'apply_to_current_site: applyToCurrentSite' ), 'view-sends-current-site-apply-flag' );
$assert( str_contains( $view_js, 'activate: applyToCurrentSite' ), 'view-activates-current-site-imports' );
$assert( str_contains( $view_js, 'overwrite: applyToCurrentSite' ), 'view-overwrites-current-site-imports' );
$generic_preview_message = implode( ' ', array( 'no', 'preview', 'provider', 'is', 'configured' ) );
$assert( ! str_contains( $view_js, $generic_preview_message ), 'view-does-not-reference-generic-preview-message' );
$assert( str_contains( $view_js, 'Open WordPress preview' ) || str_contains( $html, 'Open WordPress preview' ), 'view-or-render-has-preview-link-label' );

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

$preview_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'source' => array(
				'files' => array(
					array(
						'path'    => 'uploaded/site/index.html',
						'content' => '<main>Hello</main>',
					),
				),
			),
		)
	)
);
$assert( true === ( $preview_response['success'] ?? null ), 'rest-preview-codebox-result-succeeds' );
$assert( 'https://preview.example.test/ssi' === ( $preview_response['preview']['url'] ?? '' ), 'rest-preview-contract-exposes-codebox-preview-url' );
$assert( isset( $preview_response['preview']['playground']['blueprint_url'] ), 'rest-preview-contract-exposes-playground-blueprint-url' );
$assert( 'static-site-importer/import-website-artifact' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['name'] ?? '' ), 'rest-preview-codebox-invokes-ssi-import-ability' );
$assert( true === ( WP_Codebox_Abilities::$last_input['include_raw_browser_session'] ?? null ), 'rest-preview-codebox-requests-raw-session-for-blueprint-extraction' );
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
$product_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'source' => array(
				'files' => array(
					array(
						'path'    => 'uploaded/product/index.html',
						'content' => '<main>Product DTO</main>',
					),
				),
			),
		)
	)
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
$unavailable_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'source' => array(
				'html' => '<main>No provider</main>',
			),
		)
	)
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

$blueprint = json_decode( file_get_contents( dirname( __DIR__ ) . '/docs/playground/blueprint.json' ), true );
$assert( is_array( $blueprint ), 'playground-blueprint-decodes' );
$assert( '/import/' === ( $blueprint['landingPage'] ?? '' ), 'playground-blueprint-lands-on-import-page' );
$blueprint_code = implode( "\n", array_map( static fn( array $step ): string => isset( $step['code'] ) ? (string) $step['code'] : '', $blueprint['steps'] ?? array() ) );
$assert( str_contains( $blueprint_code, 'static-site-importer/importer' ), 'playground-blueprint-creates-importer-block-page' );
$assert( str_contains( $blueprint_code, '"title":"Import HTML to WordPress"' ), 'playground-blueprint-uses-import-html-title' );
$assert( str_contains( $blueprint_code, '"intro":"Upload a website or paste HTML. Static Site Importer will generate your new WordPress website."' ), 'playground-blueprint-uses-generate-website-copy' );
$assert( str_contains( $blueprint_code, '"applyToCurrentSite":false' ), 'playground-blueprint-generates-wordpress-website' );
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
$assert( str_starts_with( $figma_response['open_url'] ?? '', 'https://playground.wordpress.net/?blueprint-url=' ), 'figma-rest-response-open-url-is-playground-blueprint' );
$assert( isset( $figma_response['preview_session']['playground']['blueprint_url'] ), 'figma-rest-response-exposes-playground-blueprint-url' );
$assert( array() === Static_Site_Importer_Theme_Generator::$last_artifact, 'figma-rest-preview-does-not-apply-to-current-site' );
$assert( 'static-site-importer/import-website-artifact' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['name'] ?? '' ), 'figma-rest-preview-invokes-ssi-import-ability' );
$assert( 'website/index.html' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact']['entrypoint'] ?? '' ), 'figma-artifact-entrypoint-normalized' );
$assert( 'website/assets/styles.css' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact']['files'][1]['path'] ?? '' ), 'figma-artifact-file-path-normalized' );
$assert( true === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['activate'] ?? null ), 'figma-preview-defaults-to-activate-in-playground' );
$assert( true === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['overwrite'] ?? null ), 'figma-preview-defaults-to-overwrite-in-playground' );
$assert( 'Fisiostetic' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['name'] ?? null ), 'figma-import-name-derived-from-metadata' );
$assert( 'Fisiostetic' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['site_title'] ?? null ), 'figma-import-site-title-derived-from-metadata' );
$assert( 'figma-to-wordpress' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact']['provenance']['source'] ?? '' ), 'figma-artifact-provenance-source' );

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

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "Importer block smoke passed (%d assertions).\n", $assertions );
