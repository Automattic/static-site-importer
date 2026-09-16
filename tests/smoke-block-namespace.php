<?php
/**
 * Smoke coverage for the consumer-owned generated-block namespace and the
 * durable artifact identity it travels with (issue #1685).
 *
 * Proves the whole consumer chain for one resolved namespace: the
 * static_site_importer_block_namespace filter feeds Site_Identity::resolve(),
 * the resolved namespace is the artifact's block_namespace compiler input, a
 * producer that consumes that input emits payload blocks under it, the
 * companion scaffold registers those exact names from disk, and a
 * provenance-carrying payload stamps a real Version and a parseable Update
 * URI into the artifacts that survive the import.
 *
 * Run from the repository root:
 * php tests/smoke-block-namespace.php
 *
 * @package StaticSiteImporter
 */

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'STATIC_SITE_IMPORTER_VERSION', '9.9.9' );

	$GLOBALS['ssi_namespace_filters'] = array();
	$GLOBALS['ssi_namespace_options'] = array();
	$GLOBALS['ssi_namespace_registered_blocks'] = array();
	$GLOBALS['ssi_namespace_registered_scripts'] = array();
	$GLOBALS['ssi_namespace_actions'] = array();

	class WP_Error {
		public function __construct( private string $code, private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$filter = $GLOBALS['ssi_namespace_filters'][ $hook ] ?? null;
		return is_callable( $filter ) ? $filter( $value, ...$args ) : $value;
	}

	function add_action( string $hook, callable|string $callback ): void {
		$GLOBALS['ssi_namespace_actions'][ $hook ][] = $callback;
	}

	function add_filter( string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1 ): void {}

	function remove_filter( string $hook, callable|string $callback ): void {}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}

	function sanitize_title( string $title ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
	}

	function sanitize_key( string $key ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.keyFound
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}

	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) );
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}

	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}

	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}

	function plugin_dir_url( string $file ): string {
		return 'https://example.test/plugins/' . basename( dirname( $file ) ) . '/';
	}

	function register_block_type( string $block, array $args = array() ): object|false {
		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( '' === $name && is_file( $block . '/block.json' ) ) {
			$metadata = json_decode( (string) file_get_contents( $block . '/block.json' ), true );
			$name     = is_array( $metadata ) ? (string) ( $metadata['name'] ?? '' ) : '';
		}
		if ( '' === $name || in_array( $name, $GLOBALS['ssi_namespace_registered_blocks'], true ) ) {
			return false;
		}
		$GLOBALS['ssi_namespace_registered_blocks'][] = $name;
		return new class( $name ) {
			public function __construct( public string $name ) {}
		};
	}

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['ssi_namespace_options'][ $name ] ?? $default;
	}

	function update_option( string $name, mixed $value, bool $autoload = false ): bool {
		$GLOBALS['ssi_namespace_options'][ $name ] = $value;
		return true;
	}

	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), mixed $ver = false, mixed $in_footer = false ): void {}

	function wp_register_script( string $handle, string $src = '', array $deps = array(), mixed $ver = false, mixed $in_footer = false ): bool {
		return true;
	}

	// A producer stub shaped like the upstream that consumes the artifact's
	// block_namespace input (blocks-engine#1874): it records the artifact it
	// was handed and emits generated blocks under the namespace it carries.
	eval( 'namespace Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler { class ArtifactCompiler { public static array $artifacts = array(); public function compile( array $artifact ): object { self::$artifacts[] = $artifact; $namespace = is_string( $artifact["block_namespace"] ?? null ) ? $artifact["block_namespace"] : ""; return new class( $namespace ) { public function __construct( private string $namespace ) {} public function toWordPressSitePlanView(): array { return array( "schema" => "blocks-engine/wordpress-site-plan-view/v1", "wordpress_site_plan" => array( "schema" => "blocks-engine/wordpress-site-plan/v2", "plan_identity" => array( "schema" => "blocks-engine/wordpress-site-plan-identity/v1", "hash" => str_repeat( "c", 64 ) ), "assets" => array(), "writes" => array(), "quality" => array( "pass" => true ) ), "gutenberg_gaps" => array(), "companion_plugin_payload" => array( "schema" => "blocks-engine/wordpress-companion-plugin/v1", "site_slug" => "acme", "site_name" => "Acme", "blocks" => array( array( "name" => "hero", "block_json" => array( "name" => $this->namespace . "/hero", "title" => "Hero", "category" => "design" ), "render" => "<div class=\"acme-hero\">Hero</div>" ) ), "provenance" => array( "schema" => "blocks-engine/generated-artifact-provenance/v1", "generator" => "blocks-engine", "engine_version" => "1.0.0", "artifact_hash" => str_repeat( "d4", 32 ) ) ), "font_materialization" => array(), "diagnostics" => array() ); } }; } } }' );

	// Composer autoload after the producer stub: the vendored transformer
	// never loads because the stub above already owns the compiler class.
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-compilation-preparation.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-stylesheet-materializer.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-materialization-strategy.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-identity.php';

	$assertions = 0;
	$failures   = array();
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$source_artifact = array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<html><head><title>Acme Group</title></head><body><main><h1>Acme</h1></main></body></html>',
			),
		),
	);

	// 1. Unfiltered: the resolved namespace is the default ssi-<slug>, the
	// compiler artifact input carries it, and the producer emits it.
	$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $source_artifact, array( 'name' => 'Acme Group', 'slug' => 'acme' ) );
	$assert( ! is_wp_error( $compiled ), 'unfiltered-compile-prepares', is_wp_error( $compiled ) ? $compiled->get_error_message() : '' );
	$assert( 'ssi-acme' === ( \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler::$artifacts[0]['block_namespace'] ?? null ), 'unfiltered-namespace-reaches-compiler-artifact-input' );
	$assert( 'ssi-acme/hero' === ( $compiled['companion_payload']['blocks'][0]['block_json']['name'] ?? null ), 'unfiltered-payload-blocks-use-default-namespace' );
	$assert( in_array( 'ssi-acme/hero', array_column( $compiled['companion_payload']['blocks'] ?? array(), 'name' ), true ) === false, 'payload-name-slots-stay-namespace-free' );
	$assert( 'blocks-engine/generated-artifact-provenance/v1' === ( $compiled['args']['artifact_provenance']['schema'] ?? '' ), 'payload-provenance-travels-with-import-args' );

	// 2. Filtered: one consumer-owned namespace resolved once reaches the
	// producer's generated block names by construction.
	$GLOBALS['ssi_namespace_filters']['static_site_importer_block_namespace'] = static fn ( string $namespace, string $slug ): string => 'acme-blocks-' . $slug;
	\Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler::$artifacts = array();
	$compiled_filtered = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $source_artifact, array( 'name' => 'Acme Group', 'slug' => 'acme' ) );
	$assert( ! is_wp_error( $compiled_filtered ), 'filtered-compile-prepares', is_wp_error( $compiled_filtered ) ? $compiled_filtered->get_error_message() : '' );
	$assert( 'acme-blocks-acme' === ( \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler::$artifacts[0]['block_namespace'] ?? null ), 'filtered-namespace-reaches-compiler-artifact-input' );
	$assert( 'acme-blocks-acme/hero' === ( $compiled_filtered['companion_payload']['blocks'][0]['block_json']['name'] ?? '' ), 'producer-emits-blocks-under-filtered-namespace' );

	// 3. The scaffold registers the payload-resolved names from disk; the
	// plugin directory identity stays derived from site_slug.
	$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $compiled_filtered['companion_payload'] );
	$assert( is_array( $descriptor ), 'filtered-payload-scaffolds' );
	$plugin_root = null;
	if ( is_array( $descriptor ) ) {
		$assert( array( 'acme-blocks-acme/hero' ) === $descriptor['block_names'], 'scaffold-keeps-producer-resolved-names', print_r( $descriptor['block_names'], true ) );
		$assert( 'acme-blocks-acme' === $descriptor['namespace'], 'scaffold-namespace-matches-producer' );
		$assert( 'ssi-acme' === $descriptor['slug'] && 'ssi-acme/ssi-acme.php' === $descriptor['plugin_file'], 'plugin-directory-identity-stays-slug-derived' );

		$plugin_root = sys_get_temp_dir() . '/ssi-block-namespace-' . bin2hex( random_bytes( 4 ) );
		foreach ( $descriptor['files'] as $relative => $content ) {
			$path = $plugin_root . '/' . $relative;
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, $content );
		}
		require_once $plugin_root . '/' . $descriptor['plugin_file'];
		$callback = $descriptor['registration_callback'];
		$callback();
		$assert( in_array( 'acme-blocks-acme/hero', $GLOBALS['ssi_namespace_registered_blocks'], true ), 'filtered-namespace-reaches-registered-block-names', print_r( $GLOBALS['ssi_namespace_registered_blocks'], true ) );

		// 4. The durable plugin header identifies its producing build.
		$main_file = (string) file_get_contents( $plugin_root . '/' . $descriptor['plugin_file'] );
		$assert( 1 === preg_match( '/^\s*\* Version: 9\.9\.9\+d4d4d4d4$/m', $main_file ), 'plugin-header-version-carries-producing-build', $main_file );
		$update_uri = array();
		$assert( 1 === preg_match( '/^\s*\* Update URI: (\S+)$/m', $main_file, $update_uri ) && 'static-site-importer.invalid' === ( parse_url( (string) $update_uri[1], PHP_URL_HOST ) ?? '' ) && 'ssi-acme' === trim( (string) parse_url( (string) $update_uri[1], PHP_URL_PATH ), '/' ), 'plugin-header-carries-parseable-artifact-update-uri', $main_file );
	}

	// 5. The generated block theme's style.css carries the same identity,
	// and a provenance-free stylesheet keeps the historical header.
	$provenance = $compiled_filtered['args']['artifact_provenance'];
	$theme_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/ssi-ns-theme', 'acme', '.hero{color:inherit}', array(), array(), array(), null, $provenance );
	$theme_style  = (string) ( $theme_writes['/tmp/ssi-ns-theme/style.css'] ?? '' );
	$assert( 1 === preg_match( '/^Version: 9\.9\.9\+d4d4d4d4$/m', $theme_style ), 'block-theme-style-css-carries-producing-build-version', $theme_style );
	$theme_uri = array();
	$assert( 1 === preg_match( '/^Update URI: (\S+)$/m', $theme_style, $theme_uri ) && 'static-site-importer.invalid' === ( parse_url( (string) $theme_uri[1], PHP_URL_HOST ) ?? '' ), 'block-theme-style-css-carries-parseable-update-uri', $theme_style );
	$plain_theme_style = (string) ( Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/ssi-ns-theme', 'acme', '.hero{color:inherit}', array(), array() )['/tmp/ssi-ns-theme/style.css'] ?? '' );
	$assert( str_contains( $plain_theme_style, "Version: 0.1.0\n" ) && ! str_contains( $plain_theme_style, 'Update URI' ), 'provenance-free-block-theme-keeps-frozen-header' );

	// 6. The classic theme projection's style.css carries the same identity.
	$classic_scaffold = Static_Site_Importer_Theme_Materialization_Strategy::fixed_classic_scaffold( 'Acme Group', $provenance );
	$classic_style    = (string) ( $classic_scaffold['style.css'] ?? '' );
	$assert( str_starts_with( $classic_style, "/*\nTheme Name: Acme Group\nText Domain: static-site-importer\nVersion: 9.9.9+d4d4d4d4\n" ), 'classic-style-css-carries-producing-build-version', $classic_style );
	$classic_uri = array();
	$assert( 1 === preg_match( '/^Update URI: (\S+)$/m', $classic_style, $classic_uri ) && 'static-site-importer.invalid' === ( parse_url( (string) $classic_uri[1], PHP_URL_HOST ) ?? '' ), 'classic-style-css-carries-parseable-update-uri', $classic_style );
	$plain_classic = Static_Site_Importer_Theme_Materialization_Strategy::fixed_classic_scaffold( 'Acme Group' );
	$assert( "/*\nTheme Name: Acme Group\nText Domain: static-site-importer\n*/\n" === ( $plain_classic['style.css'] ?? '' ), 'provenance-free-classic-scaffold-keeps-historical-header' );

	// 7. The same identity is queryable from the site option without parsing
	// any file headers, and survives with no plugin runtime at all.
	$recorded = Static_Site_Importer_Build_Provenance::record_artifact_identity( Static_Site_Importer_Build_Provenance::describe_artifact( $provenance, '2026-09-16T09:00:00Z' ) );
	$identity = Static_Site_Importer_Build_Provenance::artifact_identity();
	$assert( true === $recorded && 'static-site-importer/build-provenance/v1' === ( $identity['schema'] ?? '' ) && '9.9.9' === ( $identity['static_site_importer']['version'] ?? '' ) && str_repeat( 'd4', 32 ) === ( $identity['artifact']['artifact_hash'] ?? '' ), 'site-option-records-composed-artifact-identity' );

	// Cleanup generated fixtures.
	$cleanup = static function ( string $dir ) use ( &$cleanup ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $cleanup( $path ) : unlink( $path );
		}
		rmdir( $dir );
	};
	if ( is_string( $plugin_root ) ) {
		$cleanup( $plugin_root );
	}

	if ( $failures ) {
		echo implode( "\n", $failures ) . "\n";
		echo 'FAILED: block-namespace smoke (' . count( $failures ) . ' of ' . $assertions . " assertions)\n";
		exit( 1 );
	}

	echo 'OK: block-namespace smoke passed (' . $assertions . " assertions)\n";
}
