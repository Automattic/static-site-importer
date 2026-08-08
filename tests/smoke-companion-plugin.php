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
$GLOBALS['ssi_companion_init_actions'] = 1;
$GLOBALS['ssi_companion_init_running'] = false;
$GLOBALS['ssi_companion_registration_attempts'] = 0;

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

		public function get_registered( string $name ): ?WP_Block_Type {
			return $this->is_registered( $name ) ? new WP_Block_Type( $name ) : null;
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

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 'init' === $hook ? (int) $GLOBALS['ssi_companion_init_actions'] : 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return 'init' === $hook && (bool) $GLOBALS['ssi_companion_init_running'];
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( string $block, array $args = array() ): WP_Block_Type|false {
		++$GLOBALS['ssi_companion_registration_attempts'];
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

// Synthetic minimal payload: one PHP-only dynamic block (attributes + render)
// plus a preserved island scoped to that block. Generic; no fixture-specific
// strings.
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
						'type'    => 'content',
						'default' => '',
					),
					'text'    => array(
						'type'    => 'text',
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
				'editorScript' => 'file:./editor.js',
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
				'editor.js'  => 'window.SSIEditor = true;',
				'script.js'  => 'window.SSIScript = true;',
				'style.css'  => '.ssi-hero { color: inherit; }',
				'editor.css' => '.editor-styles-wrapper .ssi-hero { color: inherit; }',
				'view.js'    => 'window.SSIView = true;',
				'view-module.js' => 'export const SSIView = true;',
				'view.css' => '.ssi-hero { display: block; }',
				'variations.json' => '[]',
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

// 1. Scaffolder emits a valid plugin file set.
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$assert( is_array( $descriptor ), 'scaffold-returns-descriptor', is_array( $descriptor ) ? '' : 'WP_Error returned' );

if ( is_array( $descriptor ) ) {
	$assert( 'ssi-example-site' === $descriptor['slug'], 'scaffold-namespaces-slug', (string) $descriptor['slug'] );
	$assert( 'ssi-example-site/ssi-example-site.php' === $descriptor['plugin_file'], 'scaffold-plugin-file-path', (string) $descriptor['plugin_file'] );
	$assert( array( 'example/custom-hero' ) === $descriptor['block_names'], 'scaffold-preserves-declared-canonical-name' );
	$assert( false === $descriptor['mu_plugin'], 'scaffold-regular-plugin-by-default' );

	$files = $descriptor['files'];
	$main  = $files['ssi-example-site/ssi-example-site.php'] ?? '';
	$assert( str_contains( $main, 'Plugin Name:' ), 'main-file-has-plugin-header' );
	$assert( str_contains( $main, "add_filter( 'render_block'" ), 'main-file-scopes-island-enqueue' );
	$assert( str_contains( $main, 'wp_enqueue_script' ), 'main-file-enqueues-island-js' );

	$assert( str_contains( $main, "register_block_type( SSI_EXAMPLE_SITE_DIR . 'blocks/' . (string) \$spec['dir'] )" ), 'main-file-registers-metadata-block-directory' );
	$assert( str_contains( $main, "\$registered instanceof WP_Block_Type" ) && str_contains( $main, "static_site_importer_companion_block_owners" ) && str_contains( $main, "'plugin_file' => 'ssi-example-site/ssi-example-site.php'" ) && str_contains( $main, "'revision' =>" ), 'main-file-records-revisioned-owner-only-after-matching-registration', $main );
	$assert( str_contains( $main, "'fresh_runtime_required'" ) && str_contains( $main, "'foreign_collision'" ), 'main-file-fails-closed-for-stale-or-foreign-registrations' );
	$assert( str_contains( $main, "register_block_type( (string) \$spec['name'], \$args )" ), 'main-file-retains-php-only-fallback-registration' );
	$assert( str_contains( $main, "'api_version' => 3" ), 'main-file-declares-api-version' );
	$assert( str_contains( $main, "'name' => 'example/custom-hero'" ), 'main-file-carries-declared-block-name' );
	$assert( str_contains( $main, "'attributes' =>" ) && str_contains( $main, "'heading' =>" ), 'main-file-declares-php-attributes' );
	$assert( str_contains( $main, "'content' =>" ) && str_contains( $main, "'text' =>" ), 'main-file-preserves-semantic-attribute-names' );
	$assert( ! str_contains( $main, "'type' => 'content'" ) && ! str_contains( $main, "'type' => 'text'" ), 'main-file-normalizes-invalid-rest-schema-types' );
	$builtin_schema_types = array( 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' );
	preg_match_all( "/'type' => '([^']+)'/", $main, $type_matches );
	$invalid_schema_types = array_values( array_diff( $type_matches[1] ?? array(), $builtin_schema_types ) );
	$assert( array() === $invalid_schema_types, 'main-file-emits-only-builtin-rest-schema-types', implode( ',', $invalid_schema_types ) );
	$block_json = $files['ssi-example-site/blocks/custom-hero/block.json'] ?? '';
	$assert( '' !== $block_json, 'metadata-block-json-emitted' );
	$assert( str_contains( $block_json, '"editorScript": "file:./editor.js"' ), 'metadata-block-json-declares-editor-script' );
	$assert( str_contains( $block_json, '"viewScript"' ) && str_contains( $block_json, '"file:./view.js"' ), 'metadata-block-json-declares-view-script' );
	$assert( str_contains( $block_json, '"viewScriptModule"' ) && str_contains( $block_json, '"viewStyle"' ) && str_contains( $block_json, '"script"' ), 'metadata-block-json-retains-all-core-metadata-fields' );
	$assert( isset( $files['ssi-example-site/blocks/custom-hero/editor.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/script.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/style.css'] ) && isset( $files['ssi-example-site/blocks/custom-hero/editor.css'] ) && isset( $files['ssi-example-site/blocks/custom-hero/view.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/view-module.js'] ) && isset( $files['ssi-example-site/blocks/custom-hero/view.css'] ) && isset( $files['ssi-example-site/blocks/custom-hero/variations.json'] ), 'metadata-block-assets-emitted' );

	// The render.php is the server-rendered template the render_callback runs.
	$render = $files['ssi-example-site/blocks/custom-hero/render.php'] ?? '';
	$assert( '' !== $render, 'render-php-emitted' );
	$assert( str_starts_with( ltrim( $render ), '<?php' ), 'render-php-opens-with-php-tag' );
	$assert( str_contains( $render, "echo '<div class=\"ssi-hero\">Example hero</div>';" ) && ! str_contains( $render, 'system(' ), 'render-php-emits-static-source-markup-only' );

	// Preserved island JS (#496) is separate carried JS and still rides along.
	$island_files = array_filter( array_keys( $files ), static fn ( string $path ): bool => str_contains( $path, '/islands/' ) && str_ends_with( $path, '.js' ) );
	$assert( 1 === count( $island_files ), 'preserved-island-js-file-emitted' );
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
	$assert( str_contains( $variant_main, "'metadata' => true" ), 'static-block-registered-from-metadata' );
	$static_block_json = $variant_files['ssi-render-variants/blocks/static-card/block.json'] ?? '';
	$assert( str_contains( $static_block_json, '"name": "blocks-engine/description-list"' ), 'static-block-preserves-canonical-name' );
	$assert( ! str_contains( $static_block_json, '"render"' ), 'static-block-preserves-static-rendering' );

	// A block with payload markup emits that markup as render.php.
	$declared_render = $variant_files['ssi-render-variants/blocks/declared-render/render.php'] ?? '';
	$assert( str_contains( $declared_render, 'ssi-declared' ), 'declared-render-block-emits-payload-markup' );

	// Metadata is emitted and the generated render.php remains its render target.
	$variant_block_json = array_filter( array_keys( $variant_files ), static fn ( string $path ): bool => str_ends_with( $path, '/block.json' ) );
	$assert( 2 === count( $variant_block_json ), 'render-variants-emit-block-json', implode( ',', $variant_block_json ) );
	$assert( ! str_contains( $variant_main, 'file:./custom-render.php' ), 'php-args-drop-upstream-render-key' );
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
$assert( file_exists( WP_PLUGIN_DIR . '/ssi-example-site/blocks/custom-hero/editor.js' ), 'install-emits-declared-editor-asset' );
$assert( in_array( 'example/custom-hero', WP_Block_Type_Registry::$registered, true ), 'install-registers-generated-block-before-editor-use' );
$owner = $GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] ?? array();
$assert( is_array( $owner ) && 'ssi-example-site/ssi-example-site.php' === ( $owner['plugin_file'] ?? '' ) && ( $descriptor['revision'] ?? '' ) === ( $owner['revision'] ?? '' ), 'install-records-exact-generated-owner-and-revision-before-editor-use' );
$written_main = file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) ? (string) file_get_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) : '';
$assert( str_contains( $written_main, 'register_block_type' ), 'written-main-file-registers-blocks' );
$assert( 'registered' === ( ssi_example_site_register_blocks()['status'] ?? '' ), 'same-companion-revision-callback-is-idempotent' );

$changed_revision = $payload;
$changed_revision['blocks'][0]['render'] = '<div class="ssi-hero">Changed revision</div>';
$changed_revision_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $changed_revision, static fn (): bool => true );
$assert( 'failed' === ( $changed_revision_report['status'] ?? '' ) && 'static_site_importer_companion_plugin_fresh_runtime_required' === ( $changed_revision_report['error']['code'] ?? '' ), 'changed-revision-in-loaded-runtime-requires-fresh-process' );

// A foreign registration that wins before generated plugin init must never be
// marked as companion-owned, so a later materialization still fails closed.
WP_Block_Type_Registry::$registered[] = 'example/custom-hero';
$GLOBALS['static_site_importer_companion_block_owners'] = array();

// Before init, the callback remains queued and editor readiness is explicitly
// pending instead of registering into an incomplete WordPress lifecycle.
$GLOBALS['static_site_importer_companion_block_owners'] = array();
WP_Block_Type_Registry::$registered = array();
$GLOBALS['ssi_companion_init_actions'] = 0;
$before_init_payload = array_merge( $payload, array( 'site_slug' => 'before-init' ) );
$before_init_report  = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $before_init_payload );
$assert( 'installed_pending_init' === ( $before_init_report['status'] ?? '' ) && 'pending_init' === ( $before_init_report['registration']['status'] ?? '' ) && 'init_not_started' === ( $before_init_report['registration']['reason_code'] ?? '' ), 'before-init-surfaces-top-level-pending-runtime-readiness' );
$assert( ! in_array( 'example/custom-hero', WP_Block_Type_Registry::$registered, true ) && ! empty( $GLOBALS['ssi_companion_actions']['init'] ), 'before-init-does-not-directly-register-or-unhook-init' );
$before_init_refresh = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $before_init_payload, static fn (): bool => true );
$assert( 'refreshed_pending_init' === ( $before_init_refresh['status'] ?? '' ) && 'pending_init' === ( $before_init_refresh['registration']['status'] ?? '' ), 'pre-init-refresh-surfaces-top-level-pending-runtime-readiness' );
$before_init_callback = $GLOBALS['ssi_companion_actions']['init'][ count( $GLOBALS['ssi_companion_actions']['init'] ) - 1 ] ?? null;
$assert( is_callable( $before_init_callback ), 'before-init-captures-generated-init-callback' );
$GLOBALS['ssi_companion_registration_attempts'] = 0;
if ( is_callable( $before_init_callback ) ) {
	call_user_func( $before_init_callback );
	call_user_func( $before_init_callback );
}
$assert( 1 === $GLOBALS['ssi_companion_registration_attempts'] && in_array( 'example/custom-hero', WP_Block_Type_Registry::$registered, true ), 'queued-init-callback-registers-exactly-once' );
$GLOBALS['ssi_companion_init_actions'] = 1;
$after_init_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $before_init_payload, static fn (): bool => true );
$assert( 'refreshed' === ( $after_init_report['status'] ?? '' ) && 'registered' === ( $after_init_report['registration']['status'] ?? '' ), 'post-init-refresh-transitions-to-verifiable-readiness' );

// During init the materializer can invoke the generated callback directly.
$GLOBALS['static_site_importer_companion_block_owners'] = array();
WP_Block_Type_Registry::$registered = array();
$GLOBALS['ssi_companion_init_actions'] = 0;
$GLOBALS['ssi_companion_init_running'] = true;
$during_init_payload = array_merge( $payload, array( 'site_slug' => 'during-init' ) );
$during_init_report  = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $during_init_payload );
$assert( 'registered' === ( $during_init_report['registration']['status'] ?? '' ), 'during-init-direct-registration-is-ready' );
$GLOBALS['ssi_companion_init_actions'] = 1;
$GLOBALS['ssi_companion_init_running'] = false;
$GLOBALS['static_site_importer_companion_block_owners'] = array();
ssi_example_site_register_blocks();
$assert( ! isset( $GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] ), 'foreign-registration-before-generated-init-records-no-owner' );
$foreign_init_collision = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$assert( 'failed' === ( $foreign_init_collision['status'] ?? '' ) && 'runtime_block_name_collision' === ( $foreign_init_collision['diagnostics'][0]['reason_code'] ?? '' ), 'foreign-registration-before-generated-init-blocks-refresh' );
WP_Block_Type_Registry::$registered = array();
$GLOBALS['ssi_companion_actions'] = array();

// Existing active generated companions are refreshed from the current payload;
// stale files from an older SSI build must not bypass scaffold normalization.
$GLOBALS['ssi_companion_options'][ Static_Site_Importer_Plugin_Materializer::ACTIVE_COMPANION_OPTION ] = 'ssi-example-site/ssi-example-site.php';
$GLOBALS['ssi_companion_active'][] = 'ssi-example-site/ssi-example-site.php';
file_put_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php', "<?php\nreturn array( 'type' => 'content' );\n" );
$GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] = array(
	'plugin_file' => 'ssi-example-site/ssi-example-site.php',
	'plugin_path' => (string) realpath( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ),
	'revision'    => $descriptor['revision'],
);
WP_Block_Type_Registry::$registered[] = 'example/custom-hero';
$refresh_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$refreshed_main = file_exists( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) ? (string) file_get_contents( WP_PLUGIN_DIR . '/ssi-example-site/ssi-example-site.php' ) : '';
$assert( 'refreshed' === ( $refresh_report['status'] ?? '' ), 'active-generated-plugin-refresh-status', (string) ( $refresh_report['status'] ?? '' ) );
$assert( in_array( 'refreshed', $refresh_report['actions'] ?? array(), true ), 'active-generated-plugin-records-refresh-action' );
$assert( ! str_contains( $refreshed_main, "'type' => 'content'" ), 'active-generated-plugin-overwrites-stale-invalid-schema' );
$assert( 'refreshed' === ( $refresh_report['status'] ?? '' ), 'active-companion-owned-registration-refreshes-successfully' );

$GLOBALS['static_site_importer_companion_block_owners']['example/custom-hero'] = array(
	'plugin_file' => 'foreign/foreign.php',
	'plugin_path' => WP_PLUGIN_DIR . '/foreign/foreign.php',
);
$foreign_collision = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $payload, static fn (): bool => true );
$assert( 'failed' === ( $foreign_collision['status'] ?? '' ) && 'runtime_block_name_collision' === ( $foreign_collision['diagnostics'][0]['reason_code'] ?? '' ), 'foreign-registered-block-fails-before-refresh-write' );
WP_Block_Type_Registry::$registered = array();
$GLOBALS['static_site_importer_companion_block_owners'] = array();

// mu-plugin install writes the root loader and needs no activation call.
$mu_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( array_merge( $payload, array( 'site_slug' => 'mu-example-site', 'mu_plugin' => true ) ) );
$assert( 'installed_activated' === ( $mu_report['status'] ?? '' ), 'mu-install-status', (string) ( $mu_report['status'] ?? '' ) );
$assert( true === ( $mu_report['mu_plugin'] ?? false ), 'mu-install-reports-mu-plugin' );
$assert( ! in_array( 'activated', $mu_report['actions'] ?? array(), true ), 'mu-install-skips-activation' );
$assert( file_exists( WPMU_PLUGIN_DIR . '/ssi-mu-example-site.php' ), 'mu-install-writes-root-loader' );
$GLOBALS['ssi_companion_options'][ Static_Site_Importer_Plugin_Materializer::ACTIVE_COMPANION_OPTION ] = 'ssi-example-site/ssi-example-site.php';
$GLOBALS['ssi_companion_active'][] = 'ssi-example-site/ssi-example-site.php';

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
WP_Block_Type_Registry::$registered = array();
$replacement_payload = array_merge( $payload, array( 'site_slug' => 'replacement-site' ) );
$replacement_report  = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $replacement_payload );
$assert( in_array( 'ssi-example-site/ssi-example-site.php', $GLOBALS['ssi_companion_deactivated'], true ), 'replacement-deactivates-previous-companion' );
$assert( in_array( 'replaced:ssi-example-site/ssi-example-site.php', $replacement_report['actions'] ?? array(), true ), 'replacement-reports-previous-companion' );
$assert( 'ssi-replacement-site/ssi-replacement-site.php' === get_option( Static_Site_Importer_Plugin_Materializer::ACTIVE_COMPANION_OPTION ), 'replacement-records-current-companion-plugin' );

$control_payload = array_merge(
	$payload,
	array(
		'site_slug' => 'editor-controls',
		'blocks'    => array(
			array(
				'name'       => 'authored-select',
				'block_json' => array( 'name' => 'blocks-engine/authored-select', 'title' => 'Authored Select', 'editorScript' => 'file:./editor.js' ),
				'render'     => '<select><option>One</option></select>',
				'assets'     => array( 'editor.js' => 'window.SSIFormSelect = true;' ),
			),
			array(
				'name'       => 'authored-input',
				'block_json' => array( 'name' => 'blocks-engine/authored-input', 'title' => 'Authored Input', 'editorScript' => 'file:./editor.js' ),
				'render'     => '<input type="text">',
				'assets'     => array( 'editor.js' => 'window.SSIFormInput = true;' ),
			),
		),
	)
);
$control_report = Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin( $control_payload );
$assert( 'installed_activated' === ( $control_report['status'] ?? '' ), 'control-companion-materializes' );
$assert( in_array( 'blocks-engine/authored-select', WP_Block_Type_Registry::$registered, true ), 'authored-select-registers-before-editor-use' );
$assert( in_array( 'blocks-engine/authored-input', WP_Block_Type_Registry::$registered, true ), 'authored-input-registers-before-editor-use' );

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
