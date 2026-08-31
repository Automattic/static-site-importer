<?php
/**
 * Smoke test: REST import adapters and source normalization.
 *
 * Run from the repository root:
 * php tests/smoke-rest-import.php
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

$GLOBALS['ssi_test_options']     = array();
$GLOBALS['ssi_filters']          = array();
$GLOBALS['ssi_home_url']         = 'https://example.test/';
$GLOBALS['ssi_transients']       = array();
$GLOBALS['ssi_upload_dir']       = sys_get_temp_dir() . '/ssi-smoke-uploads-' . getmypid();

defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * 24 * 60 * 60 );

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000000';
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
		return array_key_exists( $name, $GLOBALS['ssi_test_options'] ) ? $GLOBALS['ssi_test_options'][ $name ] : $default;
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

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
$figma_transformer_bootstrap = dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-figma-transformer/figma-transformer/figma-transformer.php';
if ( is_readable( $figma_transformer_bootstrap ) ) {
	require_once $figma_transformer_bootstrap;
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';
Static_Site_Importer_Figma_Import::register_default_zstd_decoder();
$GLOBALS['ssi_figma_zstd_available'] = true;
add_filter(
	'static_site_importer_figma_zstd_available',
	static function (): bool {
		return (bool) $GLOBALS['ssi_figma_zstd_available'];
	}
);
require_once dirname( __DIR__ ) . '/includes/abilities.php';
require_once dirname( __DIR__ ) . '/includes/rest.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-document.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-source-page.php';

$plugin_source = file_get_contents( dirname( __DIR__ ) . '/static-site-importer.php' );
$assert( is_string( $plugin_source ), 'plugin-source-readable' );
$assert( ! str_contains( $plugin_source, 'Requires Plugins: blocks-engine-php-transformer' ), 'transformer-is-not-a-required-wordpress-plugin' );
$assert( str_contains( $plugin_source, "vendor/autoload.php" ), 'loads-composer-autoloader' );
$assert( str_contains( $plugin_source, "vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php" ), 'loads-composer-transformer-bootstrap' );
$assert( str_contains( $plugin_source, "vendor/automattic/blocks-engine-php-transformer/php-transformer.php" ), 'loads-composer-path-transformer-bootstrap' );
$assert( str_contains( $plugin_source, 'Static_Site_Importer_Figma_Import::register_default_zstd_decoder();' ), 'plugin-registers-figma-zstd-decoder' );

$zstd_decoder_test = escapeshellarg( dirname( __DIR__ ) . '/tests/figma-zstd-decoder.php' );
$zstd_native_output = array();
$zstd_native_status = 0;
exec( escapeshellarg( PHP_BINARY ) . ' -n ' . $zstd_decoder_test . ' native 2>&1', $zstd_native_output, $zstd_native_status );
$assert( 0 === $zstd_native_status, 'figma-zstd-decoder-prefers-native-extension', implode( "\n", $zstd_native_output ) );
$zstd_command_output = array();
$zstd_command_status = 0;
exec( escapeshellarg( PHP_BINARY ) . ' -n ' . $zstd_decoder_test . ' command 2>&1', $zstd_command_output, $zstd_command_status );
$assert( 0 === $zstd_command_status, 'figma-zstd-decoder-falls-back-to-command', implode( "\n", $zstd_command_output ) );
$zstd_unavailable_output = array();
$zstd_unavailable_status = 0;
exec( escapeshellarg( PHP_BINARY ) . ' -n ' . $zstd_decoder_test . ' unavailable 2>&1', $zstd_unavailable_output, $zstd_unavailable_status );
$assert( 0 === $zstd_unavailable_status, 'figma-zstd-decoder-rejects-invalid-command', implode( "\n", $zstd_unavailable_output ) );
$zstd_disabled_output = array();
$zstd_disabled_status = 0;
exec( escapeshellarg( PHP_BINARY ) . ' -n -d disable_functions=proc_open ' . $zstd_decoder_test . ' disabled 2>&1', $zstd_disabled_output, $zstd_disabled_status );
$assert( 0 === $zstd_disabled_status, 'figma-zstd-decoder-fails-closed-without-proc-open', implode( "\n", $zstd_disabled_output ) );

$rest_source = file_get_contents( dirname( __DIR__ ) . '/includes/rest.php' );
$assert( is_string( $rest_source ), 'rest-source-readable' );
$assert( ! str_contains( strtolower( $rest_source ), 'playground' ), 'rest-contains-no-playground-generation' );
$assert( ! str_contains( strtolower( $rest_source ), 'blueprint' ), 'rest-contains-no-blueprint-generation' );

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

Static_Site_Importer_Theme_Generator::$last_artifact = array();
$apply_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'activate'  => true,
			'overwrite' => true,
			'source'    => array(
				'html' => '<main>Apply</main>',
			),
		)
	)
);
$assert( true === ( $apply_response['success'] ?? null ), 'rest-import-applies-to-current-site' );
$assert( true === ( Static_Site_Importer_Theme_Generator::$last_args['activate'] ?? null ), 'rest-import-preserves-activate' );
$assert( isset( $apply_response['result'] ), 'rest-import-returns-ability-envelope' );
$assert( 'https://example.test/' === ( $apply_response['preview']['url'] ?? '' ), 'rest-import-returns-site-preview-url' );

$GLOBALS['ssi_last_url_provider_request'] = array();

$GLOBALS['ssi_url_ability_inputs'] = array();
$GLOBALS['ssi_url_ability_id']     = 'stub-id';

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?object {
		if ( 'static-site-importer/import' !== $name ) {
			return null;
		}
		return new class {
			public function execute( array $input ) {
				$GLOBALS['ssi_url_ability_inputs'][] = $input;
				return array(
					'success'               => true,
					'continuation'          => true,
					'continuation_reason'   => 'effective_batch_limit',
					'import_id'             => $GLOBALS['ssi_url_ability_id'],
					'url_batch_run'         => array( 'status' => 'continuing' ),
					'import_report_summary' => array( 'status' => 'continuing' ),
				);
			}
		};
	}
}

Static_Site_Importer_Theme_Generator::$last_args = array();
$GLOBALS['ssi_url_ability_id']     = 'rest-stub-id';
$GLOBALS['ssi_url_ability_inputs'] = array();

$current_site_url_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'source' => array(
				'url' => 'facebook.com',
			),
		)
	)
);
$assert( ! is_wp_error( $current_site_url_response ), 'rest-current-site-url-only-does-not-error', is_wp_error( $current_site_url_response ) ? $current_site_url_response->get_error_code() . ': ' . $current_site_url_response->get_error_message() : '' );
if ( is_wp_error( $current_site_url_response ) ) {
	$current_site_url_response = array();
}
$assert( true === ( $current_site_url_response['success'] ?? null ), 'rest-current-site-url-only-succeeds' );
$assert( true === ( $current_site_url_response['continuation'] ?? null ), 'rest-current-site-url-only-returns-continuation' );
$assert( 'rest-stub-id' === ( $current_site_url_response['import_id'] ?? '' ), 'rest-current-site-url-only-returns-import-id' );
$assert( 1 === count( $GLOBALS['ssi_url_ability_inputs'] ), 'rest-current-site-url-only-ability-invoked-once' );
$assert( 'facebook.com' === ( $GLOBALS['ssi_url_ability_inputs'][0]['source']['url'] ?? '' ), 'rest-current-site-url-only-ability-receives-source-url' );
$assert( 'url' === ( $GLOBALS['ssi_url_ability_inputs'][0]['source']['type'] ?? '' ), 'rest-current-site-url-only-ability-receives-source-type' );
$assert( array() === Static_Site_Importer_Theme_Generator::$last_args, 'rest-current-site-url-only-does-not-materialize-locally' );

$client_shell_html = '<!doctype html><html><head><title>Client App</title>' . str_repeat( '<script src="/bundle.js"></script>', 25 ) . '</head><body><div id="root"></div></body></html>' . str_repeat( ' ', 120000 );
$pasted_shell_response = static_site_importer_rest_create_import(
	new WP_REST_Request(
		array(
			'source' => array(
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
			'source' => array(
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
$staged_figma_root = ABSPATH . '.studio-import';
if ( ! is_dir( $staged_figma_root ) ) {
	mkdir( $staged_figma_root );
}
$staged_figma_path = $staged_figma_root . '/design.fig';
file_put_contents( $staged_figma_path, 'not-a-zip' );
$GLOBALS['ssi_figma_zstd_available'] = false;
$unavailable_figma_artifact          = Static_Site_Importer_Figma_Import::website_artifact_from_figma_upload( $staged_figma_path, 'design.fig', array() );
$assert( 'static_site_importer_figma_zstd_unavailable' === ( is_wp_error( $unavailable_figma_artifact ) ? $unavailable_figma_artifact->get_error_code() : '' ), 'figma-upload-fails-explicitly-without-zstd' );
$assert( 501 === ( is_wp_error( $unavailable_figma_artifact ) ? ( $unavailable_figma_artifact->get_error_data()['status'] ?? 0 ) : 0 ), 'figma-upload-unavailable-status-is-actionable' );
$GLOBALS['ssi_figma_zstd_available'] = true;
$staged_figma_artifact = Static_Site_Importer_Figma_Import::website_artifact_from_input(
	array(
		'source' => array(
			'figma_file' => array(
				'name'        => 'design.fig',
				'staged_path' => $staged_figma_path,
			),
		),
	)
);
$assert( 'static_site_importer_figma_staged_file_invalid' !== ( is_wp_error( $staged_figma_artifact ) ? $staged_figma_artifact->get_error_code() : '' ), 'figma-staged-file-routes-through-transformer' );
$outside_staged_figma = Static_Site_Importer_Figma_Import::website_artifact_from_input(
	array(
		'source' => array(
			'figma_file' => array(
				'name'        => 'design.fig',
				'staged_path' => __FILE__,
			),
		),
	)
);
$assert( 'static_site_importer_figma_staged_file_invalid' === ( is_wp_error( $outside_staged_figma ) ? $outside_staged_figma->get_error_code() : '' ), 'figma-staged-file-rejects-outside-path' );
unlink( $staged_figma_path );
rmdir( $staged_figma_root );
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

$figma_missing_payload = Static_Site_Importer_Figma_Import::website_artifact_from_input(
	array(
		'artifact_bundle' => array(
			'schema' => 'figma-to-wordpress/website-artifact-bundle/v1',
			'root'   => 'website/',
			'files'  => array(
				array( 'path' => 'website/index.html' ),
			),
		),
	)
);
$assert( is_wp_error( $figma_missing_payload ), 'figma-bundle-missing-file-payload-errors' );
$assert( 'static_site_importer_figma_file_payload_missing' === ( is_wp_error( $figma_missing_payload ) ? $figma_missing_payload->get_error_code() : '' ), 'figma-bundle-missing-file-payload-error-code' );

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
	$fig_archive_base64 = base64_encode( file_get_contents( $fig_path ) );

	Static_Site_Importer_Theme_Generator::$last_artifact = array();
	Static_Site_Importer_Theme_Generator::$last_args     = array();
	$fig_upload_response = static_site_importer_rest_create_import(
		new WP_REST_Request(
			array(
				'source' => array(
					'figma_file' => array(
						'name'           => 'design.fig',
						'type'           => 'application/octet-stream',
						'content_base64' => $fig_archive_base64,
					),
				),
			)
		)
	);
	$assert( ! is_wp_error( $fig_upload_response ), 'rest-fig-upload-does-not-error', is_wp_error( $fig_upload_response ) ? $fig_upload_response->get_error_code() . ': ' . $fig_upload_response->get_error_message() : '' );
	if ( is_wp_error( $fig_upload_response ) ) {
		$fig_upload_response = array();
	}
	$assert( true === ( $fig_upload_response['success'] ?? null ), 'rest-fig-upload-succeeds' );
	$fig_upload_input = end( $GLOBALS['ssi_url_ability_inputs'] );
	$fig_upload_json  = wp_json_encode( $fig_upload_input );
	$assert( str_contains( (string) $fig_upload_json, 'Synthetic FIG Upload' ), 'rest-fig-upload-sends-transformed-artifact-to-ability' );
	$assert( ! str_contains( (string) $fig_upload_json, $fig_archive_base64 ), 'rest-fig-upload-does-not-forward-raw-fig-source' );

	$fig_multipart_response = static_site_importer_rest_import_figma_file(
		new WP_REST_Request(
			array(),
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
	$assert( ! is_wp_error( $fig_multipart_response ), 'rest-fig-multipart-does-not-error', is_wp_error( $fig_multipart_response ) ? $fig_multipart_response->get_error_code() . ': ' . $fig_multipart_response->get_error_message() : '' );
	if ( is_wp_error( $fig_multipart_response ) ) {
		$fig_multipart_response = array();
	}
	$assert( true === ( $fig_multipart_response['success'] ?? null ), 'rest-fig-multipart-succeeds' );
	$fig_multipart_input = end( $GLOBALS['ssi_url_ability_inputs'] );
	$fig_multipart_json  = wp_json_encode( $fig_multipart_input );
	$assert( str_contains( (string) $fig_multipart_json, 'Synthetic FIG Upload' ), 'rest-fig-multipart-sends-transformed-artifact-to-ability' );
	$assert( ! str_contains( (string) $fig_multipart_json, $fig_archive_base64 ), 'rest-fig-multipart-does-not-forward-raw-fig-source' );
}

Static_Site_Importer_Theme_Generator::$last_artifact = array();
Static_Site_Importer_Theme_Generator::$last_args     = array();
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
$assert( 'https://example.test/' === ( $figma_response['open_url'] ?? '' ), 'figma-rest-response-opens-current-site' );
$figma_rest_input = end( $GLOBALS['ssi_url_ability_inputs'] );
$assert( 'Fisiostetic' === ( $figma_rest_input['name'] ?? '' ), 'figma-import-name-derived-from-metadata' );
$assert( 'Fisiostetic' === ( $figma_rest_input['site_title'] ?? '' ), 'figma-import-site-title-derived-from-metadata' );
$assert( 'figma-to-wordpress' === ( $figma_rest_input['source_metadata']['source'] ?? '' ), 'figma-artifact-provenance-source' );

$figma_diagnostics_input = array(
	'schema'     => 'figma-to-wordpress/runner-request/v1',
	'source'     => array(
		'fileKey' => 'fixture-file-key',
		'nodeIds' => array( '1:1' ),
	),
	'slug'              => 'figma-diagnostics',
	'name'              => 'Figma Diagnostics',
	'site_title'        => 'Figma Controls',
	'stale_page_action' => 'draft',
	'scenegraph'        => array(
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
$assert( isset( $figma_diagnostics['figma_transform_report']['summary']['page_coverage']['selected_count'] ), 'figma-diagnostics-exposes-page-coverage-summary' );
$assert( isset( $figma_diagnostics['figma_transform_report']['summary']['selected_pages'] ), 'figma-diagnostics-exposes-selected-pages-summary' );
$assert( isset( $figma_diagnostics['figma_transform_report']['summary']['artifact_quality'] ), 'figma-diagnostics-exposes-artifact-quality-summary' );
$assert( isset( $figma_diagnostics['transform_diagnostics']['diagnostic_codes'] ), 'figma-diagnostics-exposes-transform-diagnostics' );
$assert( 'figma-diagnostics' === ( $figma_diagnostics['production_import_input']['slug'] ?? '' ), 'figma-diagnostics-summarizes-production-import-input' );

Static_Site_Importer_Theme_Generator::$last_artifact = array();
Static_Site_Importer_Theme_Generator::$last_args     = array();
$figma_import_result = Static_Site_Importer_Figma_Import::import( $figma_diagnostics_input );
$assert( true === ( $figma_import_result['success'] ?? null ), 'figma-import-scenegraph-succeeds' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( $figma_import_result['figma_transform_report']['schema'] ?? '' ), 'figma-import-result-exposes-durable-transform-report' );
$assert( isset( $figma_import_result['figma_transform_report']['summary']['page_coverage'] ), 'figma-import-result-exposes-transform-summary' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( Static_Site_Importer_Theme_Generator::$last_artifact['provenance']['figma_transform_report']['schema'] ?? '' ), 'figma-artifact-provenance-preserves-transform-report' );
$assert( 'static-site-importer/figma-transform-report/v1' === ( Static_Site_Importer_Theme_Generator::$last_args['source_metadata']['figma_transform_report']['schema'] ?? '' ), 'figma-import-source-metadata-preserves-transform-report' );
$assert( 'Figma Controls' === ( Static_Site_Importer_Theme_Generator::$last_args['site_title'] ?? '' ), 'figma-import-forwards-site-title' );
$assert( 'draft' === ( Static_Site_Importer_Theme_Generator::$last_args['stale_page_action'] ?? '' ), 'figma-import-forwards-stale-page-action' );

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

	$assert_archive_limit = static function ( string $label, array $limits, array $entries, string $expected_code ) use ( $assert ): void {
		$zip_path = tempnam( sys_get_temp_dir(), 'ssi-limit-' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::OVERWRITE );
		foreach ( $entries as $path => $content ) {
			$zip->addFromString( $path, $content );
		}
		$zip->close();

		$GLOBALS['ssi_filters']['static_site_importer_archive_limits'] = array(
			static function ( array $configured_limits ) use ( $limits ): array {
				return array_merge( $configured_limits, $limits );
			},
		);
		$result = static_site_importer_rest_archive_files(
			array(
				'name'           => 'adversarial.zip',
				'content_base64' => base64_encode( file_get_contents( $zip_path ) ),
			)
		);
		unset( $GLOBALS['ssi_filters']['static_site_importer_archive_limits'] );
		@unlink( $zip_path );

		$assert( is_wp_error( $result ) && $expected_code === $result->get_error_code(), $label . '-rejects-before-materialization', is_wp_error( $result ) ? $result->get_error_code() : 'archive was materialized' );
		$assert( is_wp_error( $result ) && $expected_code === ( $result->get_error_data()['diagnostic']['code'] ?? '' ), $label . '-returns-stable-diagnostic' );
	};

	$assert_archive_limit( 'rest-zip-encoded-size', array( 'max_encoded_bytes' => 1 ), array( 'index.html' => '<main>bounded</main>' ), 'static_site_importer_archive_encoded_bytes_exceeded' );
	$assert_archive_limit( 'rest-zip-decoded-size', array( 'max_encoded_bytes' => 1024 * 1024, 'max_decoded_bytes' => 1 ), array( 'index.html' => '<main>bounded</main>' ), 'static_site_importer_archive_decoded_bytes_exceeded' );
	$assert_archive_limit( 'rest-zip-entry-count', array( 'max_entries' => 1 ), array( 'index.html' => '<main>one</main>', 'about.html' => '<main>two</main>' ), 'static_site_importer_archive_entry_count_exceeded' );
	$assert_archive_limit( 'rest-zip-entry-size', array( 'max_entry_uncompressed_bytes' => 16 ), array( 'index.html' => str_repeat( 'a', 32 ) ), 'static_site_importer_archive_entry_uncompressed_bytes_exceeded' );
	$assert_archive_limit( 'rest-zip-aggregate-size', array( 'max_entry_uncompressed_bytes' => 1024, 'max_total_uncompressed_bytes' => 32 ), array( 'index.html' => str_repeat( 'a', 20 ), 'about.html' => str_repeat( 'b', 20 ) ), 'static_site_importer_archive_total_uncompressed_bytes_exceeded' );
	$assert_archive_limit( 'rest-zip-compression-ratio', array( 'max_entry_uncompressed_bytes' => 4096, 'max_compression_ratio' => 2 ), array( 'index.html' => str_repeat( 'a', 2048 ) ), 'static_site_importer_archive_compression_ratio_exceeded' );
	$GLOBALS['ssi_filters']['static_site_importer_archive_limits'] = array(
		static function ( array $limits ): array {
			$limits['max_encoded_bytes'] = PHP_INT_MAX;
			return $limits;
		},
	);
	$hard_limited = static_site_importer_rest_archive_limits();
	unset( $GLOBALS['ssi_filters']['static_site_importer_archive_limits'] );
	$assert( 52428800 >= $hard_limited['max_encoded_bytes'], 'rest-zip-filter-cannot-exceed-encoded-hard-ceiling' );

	$staged_path = tempnam( sys_get_temp_dir(), 'ssi-staged-' );
	$staged_zip  = new ZipArchive();
	$staged_zip->open( $staged_path, ZipArchive::OVERWRITE );
	$staged_zip->addFromString( 'index.html', '<main>Staged</main>' );
	$staged_zip->close();
	$staged = static_site_importer_staged_archive_files(
		array(
			'name'        => 'website.zip',
			'staged_path' => $staged_path,
		)
	);
	$assert( is_array( $staged ) && 'website/index.html' === ( $staged[0]['path'] ?? '' ), 'staged-zip-extracts-provider-owned-archive' );
	$assert( file_exists( $staged_path ), 'staged-zip-preserves-provider-owned-archive' );
	$staged_zip = new ZipArchive();
	$staged_zip->open( $staged_path, ZipArchive::OVERWRITE );
	$staged_zip->addFromString( 'index.html', '<main>Staged</main>' );
	$staged_binary = str_repeat( "\x00\xffPNG", 65536 );
	$staged_zip->addFromString( 'assets/photo.png', $staged_binary );
	$staged_zip->setCompressionName( 'assets/photo.png', ZipArchive::CM_STORE );
	$staged_zip->addFromString( 'assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' );
	$staged_zip->close();
	$referenced_staged = static_site_importer_staged_archive_files(
		array(
			'name'        => 'website.zip',
			'staged_path' => $staged_path,
		),
		true
	);
	$referenced_by_path = is_array( $referenced_staged ) ? array_column( $referenced_staged, null, 'path' ) : array();
	$assert( isset( $referenced_by_path['website/assets/photo.png']['payload_reference'] ) && ! isset( $referenced_by_path['website/assets/photo.png']['content_base64'] ) && isset( $referenced_by_path['website/assets/logo.svg']['content_base64'] ), 'staged-zip-references-only-non-svg-binary-entries' );
	$inline_binary = base64_encode( $staged_binary );
	$inline_plan   = wp_json_encode(
		array(
			'assets' => array( array( 'content_base64' => $inline_binary ) ),
			'writes' => array( array( 'payload' => array( 'data' => $inline_binary ) ) ),
		)
	);
	$reference_plan = wp_json_encode(
		array(
			'assets' => array( $referenced_by_path['website/assets/photo.png'] ),
			'writes' => array( array( 'payload_reference' => $referenced_by_path['website/assets/photo.png']['payload_reference'] ) ),
		)
	);
	$assert( is_string( $inline_plan ) && is_string( $reference_plan ) && strlen( $reference_plan ) < strlen( $inline_plan ) / 100, 'staged-zip-reference-plan-eliminates-duplicated-binary-payload-size', strlen( $reference_plan ) . '/' . strlen( $inline_plan ) );
	$staged_reader = static_site_importer_staged_archive_payload_reader( array( 'name' => 'website.zip', 'staged_path' => $staged_path ) );
	$staged_bytes  = is_object( $staged_reader ) ? $staged_reader->read( $referenced_by_path['website/assets/photo.png']['payload_reference'] ) : '';
	$assert( hash( 'sha256', $staged_bytes ) === ( $referenced_by_path['website/assets/photo.png']['payload_reference']['sha256'] ?? '' ), 'staged-zip-reader-reopens-and-reads-the-declared-reference' );
	$inline_staged   = static_site_importer_staged_archive_files( array( 'name' => 'website.zip', 'staged_path' => $staged_path ) );
	$artifact_compiler = new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
	$inline_compiled   = $artifact_compiler->compile( array( 'entrypoint' => 'website/index.html', 'files' => $inline_staged ) )->toArray();
	$reference_compiled = $artifact_compiler->compile( array( 'entrypoint' => 'website/index.html', 'files' => $referenced_staged ) )->toArray();
	$inline_site_plan   = $inline_compiled['source_reports']['wordpress_site_plan'] ?? array();
	$reference_site_plan = $reference_compiled['source_reports']['wordpress_site_plan'] ?? array();
	$inline_plan_json   = wp_json_encode( $inline_site_plan );
	$reference_plan_json = wp_json_encode( $reference_site_plan );
	$assert( is_string( $inline_plan_json ) && is_string( $reference_plan_json ) && str_contains( $reference_plan_json, 'payload_reference' ) && ! str_contains( $reference_plan_json, $inline_binary ) && strlen( $reference_plan_json ) + ( 2 * strlen( $inline_binary ) ) < strlen( $inline_plan_json ), 'staged-zip-compiler-plan-removes-duplicated-binary-assets-and-writes', strlen( $reference_plan_json ) . '/' . strlen( $inline_plan_json ) );
	$staged_zip = new ZipArchive();
	$staged_zip->open( $staged_path, ZipArchive::OVERWRITE );
	$staged_zip->addFromString( 'index.html', '<main>Staged</main>' );
	$staged_zip->addFromString( 'assets/photo.png', str_repeat( 'x', strlen( $staged_binary ) ) );
	$staged_zip->setCompressionName( 'assets/photo.png', ZipArchive::CM_STORE );
	$staged_zip->addFromString( 'assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' );
	$staged_zip->close();
	$tampered_bytes = $staged_reader->read( $referenced_by_path['website/assets/photo.png']['payload_reference'] );
	$assert( ! hash_equals( $referenced_by_path['website/assets/photo.png']['payload_reference']['sha256'], hash( 'sha256', $tampered_bytes ) ), 'staged-zip-reader-does-not-cache-tampered-payloads-before-materializer-verification' );

	$symlink_path = $staged_path . '.link';
	if ( function_exists( 'symlink' ) && @symlink( $staged_path, $symlink_path ) ) {
		$symlinked = static_site_importer_staged_archive_files(
			array(
				'name'        => 'website.zip',
				'staged_path' => $symlink_path,
			)
		);
		$assert( is_wp_error( $symlinked ) && 'static_site_importer_staged_archive_invalid' === $symlinked->get_error_code(), 'staged-zip-rejects-symlink' );
		@unlink( $symlink_path );
	}

	$GLOBALS['ssi_filters']['static_site_importer_staged_archive_limits'] = array(
		static function ( array $limits ): array {
			$limits['max_archive_bytes'] = 1;
			return $limits;
		},
	);
	$oversized_staged = static_site_importer_staged_archive_files(
		array(
			'name'        => 'website.zip',
			'staged_path' => $staged_path,
		)
	);
	unset( $GLOBALS['ssi_filters']['static_site_importer_staged_archive_limits'] );
	$assert( is_wp_error( $oversized_staged ) && 'static_site_importer_archive_staged_bytes_exceeded' === $oversized_staged->get_error_code(), 'staged-zip-enforces-archive-byte-limit' );
	$assert( file_exists( $staged_path ), 'staged-zip-preserves-provider-archive-on-policy-failure' );
	@unlink( $staged_path );

	$GLOBALS['ssi_filters']['static_site_importer_staged_archive_limits'] = array(
		static function ( array $limits ): array {
			$limits['max_archive_bytes'] = PHP_INT_MAX;
			return $limits;
		},
	);
	$hard_staged_limits = static_site_importer_staged_archive_limits();
	unset( $GLOBALS['ssi_filters']['static_site_importer_staged_archive_limits'] );
	$assert( 262144000 === $hard_staged_limits['max_archive_bytes'], 'staged-zip-filter-cannot-exceed-hard-ceiling' );
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

echo sprintf( "REST import smoke passed (%d assertions).\n", $assertions );
