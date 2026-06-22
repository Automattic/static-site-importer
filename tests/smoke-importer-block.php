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

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
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

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'test-nonce';
	}
}

require_once dirname( __DIR__ ) . '/includes/block.php';
require_once dirname( __DIR__ ) . '/includes/rest.php';

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

static_site_importer_register_block();

$registered = $GLOBALS['ssi_registered_block'];
$assert( is_array( $registered ), 'block-registers' );
$assert( STATIC_SITE_IMPORTER_PATH . 'blocks/importer' === ( $registered['path'] ?? '' ), 'block-registers-metadata-directory' );
$assert( 'static_site_importer_render_block' === ( $registered['args']['render_callback'] ?? '' ), 'block-registers-render-callback' );

$html = static_site_importer_render_block(
	array(
		'title'      => 'Import your site',
		'intro'      => 'Upload files, paste HTML, or start from a URL.',
		'provider'   => 'Private Provider!',
		'defaultUrl' => 'https://example.com/source',
	)
);

$assert( str_contains( $html, 'data-static-site-importer' ), 'render-has-root-hook' );
$assert( str_contains( $html, 'data-static-site-importer-rest-url="https://example.test/wp-json/static-site-importer/v1/imports"' ), 'render-exposes-import-rest-route' );
$assert( str_contains( $html, 'data-static-site-importer-provider="privateprovider"' ), 'render-sanitizes-provider' );
$assert( str_contains( $html, 'data-static-site-importer-source-url' ), 'render-has-url-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-source-files' ), 'render-has-file-input-hook' );
$assert( str_contains( $html, 'webkitdirectory' ), 'render-has-directory-upload-affordance' );
$assert( str_contains( $html, 'data-static-site-importer-source-archive' ), 'render-has-zip-upload-hook' );
$assert( str_contains( $html, 'accept=&quot;.zip,application/zip&quot;' ) || str_contains( $html, 'accept=".zip,application/zip"' ), 'render-limits-archive-input-to-zip' );
$assert( str_contains( $html, 'data-static-site-importer-source-html' ), 'render-has-html-input-hook' );
$assert( str_contains( $html, 'data-static-site-importer-submit' ), 'render-has-submit-hook' );
$assert( str_contains( $html, 'data-static-site-importer-preview-link' ), 'render-has-preview-link-hook' );
$assert( str_contains( $html, 'data-static-site-importer-report' ), 'render-has-report-hook' );
$assert( str_contains( $html, 'Import your site' ), 'render-uses-custom-title' );
$assert( str_contains( $html, 'https://example.com/source' ), 'render-uses-default-url' );

$view_js = file_get_contents( dirname( __DIR__ ) . '/blocks/importer/view.js' );
$assert( is_string( $view_js ), 'view-js-readable' );
$assert( str_contains( $view_js, 'webkitRelativePath' ), 'view-preserves-directory-relative-paths' );
$assert( str_contains( $view_js, 'archive: await buildArchive' ), 'view-sends-zip-as-archive-payload' );
$assert( ! str_contains( $view_js, 'activate: true' ), 'view-does-not-activate-current-site' );
$assert( ! str_contains( $view_js, 'overwrite: true' ), 'view-does-not-overwrite-current-site' );
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
$assert( isset( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact'] ), 'rest-preview-codebox-request-includes-artifact' );
$assert( 'website/uploaded/site/index.html' === ( WP_Codebox_Abilities::$last_input['browser_runner']['invocation']['input']['artifact']['files'][0]['path'] ?? '' ), 'rest-directory-path-is-normalized' );
$assert( 'uploaded/site/index.html' === ( WP_Codebox_Abilities::$last_input['artifact_files'][0]['path'] ?? '' ), 'rest-preview-codebox-strips-website-prefix-for-browser-artifacts' );

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
