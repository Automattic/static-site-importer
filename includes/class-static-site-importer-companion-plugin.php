<?php
/**
 * Companion-plugin scaffolder.
 *
 * Generates a standalone, theme-independent WordPress plugin that houses a
 * site's generated custom blocks as PHP-only dynamic blocks (WordPress 7.0),
 * plus any preserved island JS scoped to where it is used.
 *
 * Each generated block is registered in PHP via register_block_type( $name,
 * $args ) with an api_version, PHP-declared attributes, and a render_callback
 * that includes the block's render.php. There is NO block.json and NO
 * index.js/view.js build artifact for the block's editor representation:
 * server-rendered (dynamic) blocks have no save()-vs-stored markup to diverge,
 * so they cannot trigger "This block contains unexpected or invalid content"
 * (#227) by construction. Component-local interactivity uses the Interactivity
 * API (server-rendered data-wp-* directives) rather than a bundled editor
 * script. See docs/companion-plugin-php-only-blocks.md.
 *
 * The compiled artifact owns the block spec + render + preserved-JS payload;
 * this class is the deterministic destination that turns that payload into an
 * installable plugin file set.
 *
 * The file-set builder is pure and side-effect free so it is testable without a
 * full WordPress runtime. The install/activate side effects live in
 * Static_Site_Importer_Plugin_Materializer::ensure_generated_plugin().
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scaffolds a one-per-site companion plugin from a generated block payload.
 */
class Static_Site_Importer_Companion_Plugin {

	/**
	 * Payload schema identifier consumed by the scaffolder.
	 */
	public const PAYLOAD_SCHEMA = 'static-site-importer/companion-plugin/v1';

	/**
	 * Build the standalone plugin scaffold from a generated payload.
	 *
	 * The returned descriptor carries the namespaced slug, the plugin basename
	 * used as a satisfied-dependency key, the fully-qualified block names, and
	 * the relative-path => file-content map that the install path materializes.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function scaffold( array $payload ) {
		$site_slug = self::site_slug( $payload );
		if ( '' === $site_slug ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_site_slug_missing',
				'Companion-plugin payload must declare a non-empty site_slug.'
			);
		}

		$blocks = self::payload_blocks( $payload );
		if ( empty( $blocks ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_blocks_missing',
				'Companion-plugin payload must declare at least one block with a name.'
			);
		}

		$plugin_slug     = 'ssi-' . $site_slug;
		$block_namespace = $plugin_slug;
		$mu_plugin       = ! empty( $payload['mu_plugin'] );
		$site_name       = self::site_name( $payload, $site_slug );

		$files       = array();
		$block_names = array();
		$block_specs = array();
		$preserved   = self::preserved_js( $payload, $block_namespace );

		foreach ( $blocks as $block ) {
			$built = self::build_block( $block, $block_namespace );
			if ( is_wp_error( $built ) ) {
				return $built;
			}

			$block_names[] = $built['block_name'];
			$block_specs[] = $built['spec'];
			foreach ( $built['files'] as $relative => $content ) {
				$files[ $plugin_slug . '/blocks/' . $built['dir'] . '/' . $relative ] = $content;
			}
		}

		foreach ( $preserved as $island ) {
			$files[ $plugin_slug . '/' . $island['relative_src'] ] = $island['content'];
		}

		$main_file = $plugin_slug . '/' . $plugin_slug . '.php';
		$files     = array_merge(
			array(
				$main_file => self::main_plugin_file( $plugin_slug, $block_namespace, $site_name, $block_specs, $preserved ),
			),
			$files
		);

		$descriptor = array(
			'schema'         => self::PAYLOAD_SCHEMA,
			'slug'           => $plugin_slug,
			'namespace'      => $block_namespace,
			'site_slug'      => $site_slug,
			'plugin_file'    => $main_file,
			'mu_plugin'      => $mu_plugin,
			'block_names'    => $block_names,
			// Handles of preserved island scripts the plugin carries + enqueues
			// scoped. Exposed so the gate/diagnostics can account for preserved
			// island JS as companion-plugin-carried (theme-independent) rather
			// than theme-coupled.
			'island_handles' => array_map(
				static fn ( array $island ): string => (string) $island['handle'],
				$preserved
			),
			'loader_file'    => '',
			'files'          => $files,
		);

		if ( $mu_plugin ) {
			// mu-plugins only auto-load PHP files at the mu-plugins root, never
			// subdirectory files. Emit a root loader stub that requires the real
			// plugin file so the same directory layout works in both modes.
			$loader                    = $plugin_slug . '.php';
			$descriptor['loader_file'] = $loader;
			$descriptor['files']       = array_merge(
				array( $loader => self::mu_loader_file( $plugin_slug, $main_file, $site_name ) ),
				$descriptor['files']
			);
		}

		return $descriptor;
	}

	/**
	 * Namespaced plugin slug, e.g. ssi-acme, for a payload.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	public static function plugin_slug( array $payload ): string {
		$site_slug = self::site_slug( $payload );
		return '' === $site_slug ? '' : 'ssi-' . $site_slug;
	}

	/**
	 * Plugin basename used as the satisfied-dependency key.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	public static function plugin_file( array $payload ): string {
		$slug = self::plugin_slug( $payload );
		return '' === $slug ? '' : $slug . '/' . $slug . '.php';
	}

	/**
	 * Sanitized site slug from the payload.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return string
	 */
	private static function site_slug( array $payload ): string {
		$raw = isset( $payload['site_slug'] ) && is_scalar( $payload['site_slug'] ) ? (string) $payload['site_slug'] : '';
		return self::sanitize_slug( $raw );
	}

	/**
	 * Human-readable site name for plugin headers.
	 *
	 * @param array<string,mixed> $payload   Generated companion-plugin payload.
	 * @param string              $site_slug Sanitized site slug.
	 * @return string
	 */
	private static function site_name( array $payload, string $site_slug ): string {
		$raw = isset( $payload['site_name'] ) && is_scalar( $payload['site_name'] ) ? trim( (string) $payload['site_name'] ) : '';
		return '' !== $raw ? $raw : $site_slug;
	}

	/**
	 * Normalize the block list from the payload.
	 *
	 * @param array<string,mixed> $payload Generated companion-plugin payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function payload_blocks( array $payload ): array {
		$blocks = isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ? $payload['blocks'] : array();
		return array_values( array_filter( $blocks, 'is_array' ) );
	}

	/**
	 * Block.json keys (camelCase) mapped to register_block_type() argument keys
	 * (the snake_case WP_Block_Type properties) that the PHP-only registration
	 * carries into the generated plugin. Anything outside this list belongs to
	 * the JS-build editor representation we deliberately no longer emit.
	 */
	private const BLOCK_SPEC_FIELDS = array(
		'apiVersion'      => 'api_version',
		'title'           => 'title',
		'category'        => 'category',
		'parent'          => 'parent',
		'ancestor'        => 'ancestor',
		'description'     => 'description',
		'keywords'        => 'keywords',
		'textdomain'      => 'textdomain',
		'icon'            => 'icon',
		'attributes'      => 'attributes',
		'providesContext' => 'provides_context',
		'usesContext'     => 'uses_context',
		'supports'        => 'supports',
		'styles'          => 'styles',
		'example'         => 'example',
	);

	/**
	 * Build one PHP-only dynamic block: its render.php (+ any carried assets) and
	 * the PHP registration spec the main plugin file feeds to register_block_type.
	 *
	 * No block.json and no index.js/view.js build artifact is emitted for the
	 * block. The block is registered server-side via render_callback, so it is a
	 * dynamic block with no save()-vs-stored markup that could go invalid.
	 *
	 * @param array<string,mixed> $block           Block payload entry.
	 * @param string              $block_namespace Plugin block namespace.
	 * @return array{block_name:string,dir:string,spec:array<string,mixed>,files:array<string,string>}|WP_Error
	 */
	private static function build_block( array $block, string $block_namespace ) {
		$name = isset( $block['name'] ) && is_scalar( $block['name'] ) ? self::sanitize_slug( (string) $block['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_block_name_missing',
				'Each companion-plugin block must declare a sanitizable name.'
			);
		}

		$block_name = $block_namespace . '/' . $name;
		$args       = self::block_args( $block, $block_name );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		// The render callback always resolves to render.php, so it is always
		// emitted even when the payload omits markup (an empty dynamic block).
		$render              = isset( $block['render'] ) && is_scalar( $block['render'] ) ? (string) $block['render'] : '';
		$files               = array();
		$files['render.php'] = self::normalize_render( $render );

		// Carried static assets (e.g. block stylesheets or a hand-written
		// Interactivity API view module) ride alongside render.php. These are
		// pass-through files, not generated JS build output.
		$assets = isset( $block['assets'] ) && is_array( $block['assets'] ) ? $block['assets'] : array();
		foreach ( $assets as $relative => $content ) {
			$relative = self::sanitize_relative_path( (string) $relative );
			if ( '' === $relative || ! is_scalar( $content ) ) {
				continue;
			}
			$files[ $relative ] = (string) $content;
		}

		return array(
			'block_name' => $block_name,
			'dir'        => $name,
			'spec'       => array(
				'name' => $block_name,
				'dir'  => $name,
				'args' => $args,
			),
			'files'      => $files,
		);
	}

	/**
	 * Resolve the register_block_type() argument array for a block.
	 *
	 * Accepts the existing block_json payload slot (object or JSON string) as the
	 * source of the editor-facing metadata, but emits only the server-side
	 * registration arguments WP_Block_Type understands. The fully-qualified name
	 * is owned by the companion plugin namespace and passed separately to
	 * register_block_type(), so it is intentionally not part of the args.
	 *
	 * @param array<string,mixed> $block      Block payload entry.
	 * @param string              $block_name Fully-qualified block name.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function block_args( array $block, string $block_name ) {
		$raw = $block['block_json'] ?? null;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( null === $raw ) {
			$decoded = array();
		} else {
			$decoded = null;
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_block_json_invalid',
				sprintf( 'Block %s must declare block_json as a JSON object or string.', $block_name )
			);
		}

		$args = array();
		foreach ( self::BLOCK_SPEC_FIELDS as $json_key => $arg_key ) {
			if ( array_key_exists( $json_key, $decoded ) ) {
				$args[ $arg_key ] = $decoded[ $json_key ];
			}
		}

		// Server-rendered dynamic block: api_version >= 2 enables the new block
		// wrapper, and we default to the current API version when unspecified.
		if ( ! isset( $args['api_version'] ) ) {
			$args['api_version'] = 3;
		}

		// Attributes must be a map for WP_Block_Type::prepare_attributes_for_render.
		if ( isset( $args['attributes'] ) && ! is_array( $args['attributes'] ) ) {
			unset( $args['attributes'] );
		}

		return $args;
	}

	/**
	 * Normalize preserved island JS entries into a scoped descriptor list.
	 *
	 * @param array<string,mixed> $payload   Generated companion-plugin payload.
	 * @param string              $block_namespace Plugin block namespace.
	 * @return array<int,array<string,string>>
	 */
	private static function preserved_js( array $payload, string $block_namespace ): array {
		$entries = isset( $payload['preserved_js'] ) && is_array( $payload['preserved_js'] ) ? $payload['preserved_js'] : array();
		$islands = array();
		$index   = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$content = isset( $entry['content'] ) && is_scalar( $entry['content'] ) ? (string) $entry['content'] : '';
			if ( '' === $content ) {
				continue;
			}

			++$index;
			$handle_raw   = isset( $entry['handle'] ) && is_scalar( $entry['handle'] ) ? self::sanitize_slug( (string) $entry['handle'] ) : '';
			$handle       = '' !== $handle_raw ? $handle_raw : $block_namespace . '-island-' . $index;
			$relative_raw = isset( $entry['src'] ) && is_scalar( $entry['src'] ) ? self::sanitize_relative_path( (string) $entry['src'] ) : '';
			$relative     = '' !== $relative_raw ? $relative_raw : 'islands/' . $handle . '.js';
			$block        = isset( $entry['block'] ) && is_scalar( $entry['block'] ) ? (string) $entry['block'] : '';

			$islands[] = array(
				'handle'       => $handle,
				'relative_src' => $relative,
				'content'      => $content,
				// Scope: enqueue only when this block renders. Empty block means
				// the island is unscoped, but slice 1 only emits scoped islands.
				'block'        => $block,
			);
		}

		return $islands;
	}

	/**
	 * Render the main plugin PHP file.
	 *
	 * @param string                          $plugin_slug     Plugin slug.
	 * @param string                          $block_namespace Block namespace.
	 * @param string                          $site_name       Human-readable site name.
	 * @param array<int,array<string,mixed>>  $block_specs     PHP-only block registration specs.
	 * @param array<int,array<string,string>> $preserved       Preserved island descriptors.
	 * @return string
	 */
	private static function main_plugin_file(
		string $plugin_slug,
		string $block_namespace,
		string $site_name,
		array $block_specs,
		array $preserved
	): string {
		$header_name  = sprintf( 'SSI Companion: %s', $site_name );
		$fn_prefix    = str_replace( '-', '_', $plugin_slug );
		$const_prefix = strtoupper( $fn_prefix );
		$islands_php  = self::export_islands_php( $preserved );
		$specs_php    = self::export_block_specs_php( $block_specs );

		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Plugin Name: ' . $header_name;
		$lines[] = ' * Description: Generated companion plugin housing PHP-only dynamic blocks and preserved island JS for ' . $site_name . '. Generated by Static Site Importer.';
		$lines[] = ' * Version: 1.0.0';
		$lines[] = ' * Requires at least: 6.9';
		$lines[] = ' * Requires PHP: 8.1';
		$lines[] = ' * Text Domain: ' . $plugin_slug;
		$lines[] = ' *';
		$lines[] = ' * Blocks are registered in PHP via register_block_type( $name, $args ) with a';
		$lines[] = ' * render_callback (dynamic / server-rendered). There is no block.json and no';
		$lines[] = ' * JS build: a dynamic block has no save()-vs-stored markup to diverge, so it';
		$lines[] = ' * cannot trigger "This block contains unexpected or invalid content".';
		$lines[] = ' *';
		$lines[] = ' * @package StaticSiteImporterCompanion';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = sprintf( "define( '%s_DIR', plugin_dir_path( __FILE__ ) );", $const_prefix );
		$lines[] = sprintf( "define( '%s_URL', plugin_dir_url( __FILE__ ) );", $const_prefix );
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * PHP-only dynamic block registration specs for this site.';
		$lines[] = ' *';
		$lines[] = ' * Each entry carries the fully-qualified block name, its render.php directory,';
		$lines[] = ' * and the register_block_type() argument array (api_version, attributes, and';
		$lines[] = ' * any editor metadata). The render_callback is attached at registration time.';
		$lines[] = ' *';
		$lines[] = ' * @return array<int,array<string,mixed>>';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_block_specs() {', $fn_prefix );
		$lines[] = "\treturn " . $specs_php . ';';
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Build a render_callback that server-renders a block from its render.php.';
		$lines[] = ' *';
		$lines[] = ' * render.php receives $attributes, $content, and $block in scope, mirroring the';
		$lines[] = ' * block.json `render` template contract without needing a block.json file.';
		$lines[] = ' *';
		$lines[] = ' * @param string $block_dir Block directory under blocks/.';
		$lines[] = ' * @return callable';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_render_callback( $block_dir ) {', $fn_prefix );
		$lines[] = "\treturn static function ( \$attributes, \$content, \$block ) use ( \$block_dir ) {";
		$lines[] = sprintf( "\t\t\$render = %s_DIR . 'blocks/' . \$block_dir . '/render.php';", $const_prefix );
		$lines[] = "\t\tif ( ! is_readable( \$render ) ) {";
		$lines[] = "\t\t\treturn '';";
		$lines[] = "\t\t}";
		$lines[] = "\t\tob_start();";
		$lines[] = "\t\tinclude \$render;";
		$lines[] = "\t\treturn (string) ob_get_clean();";
		$lines[] = "\t};";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Register the generated PHP-only dynamic blocks.';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_register_blocks() {', $fn_prefix );
		$lines[] = "\tif ( ! function_exists( 'register_block_type' ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = sprintf( "\tforeach ( %s_block_specs() as \$spec ) {", $fn_prefix );
		$lines[] = "\t\t\$args                    = isset( \$spec['args'] ) && is_array( \$spec['args'] ) ? \$spec['args'] : array();";
		$lines[] = sprintf( "\t\t\$args['render_callback'] = %s_render_callback( (string) \$spec['dir'] );", $fn_prefix );
		$lines[] = "\t\tregister_block_type( (string) \$spec['name'], \$args );";
		$lines[] = "\t}";
		$lines[] = '}';
		$lines[] = sprintf( "add_action( 'init', '%s_register_blocks' );", $fn_prefix );
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Preserved island scripts, scoped to the block they belong to.';
		$lines[] = ' *';
		$lines[] = ' * @return array<int,array<string,string>>';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_islands() {', $fn_prefix );
		$lines[] = "\treturn " . $islands_php . ';';
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Enqueue preserved island JS only when its owning block renders.';
		$lines[] = ' *';
		$lines[] = ' * @param string              $content Rendered block HTML.';
		$lines[] = ' * @param array<string,mixed> $block   Parsed block.';
		$lines[] = ' * @return string';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_enqueue_islands( $content, $block ) {', $fn_prefix );
		$lines[] = "\t\$name = is_array( \$block ) && isset( \$block['blockName'] ) ? (string) \$block['blockName'] : '';";
		$lines[] = "\tif ( '' === \$name || ! function_exists( 'wp_enqueue_script' ) ) {";
		$lines[] = "\t\treturn \$content;";
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = sprintf( "\tforeach ( %s_islands() as \$island ) {", $fn_prefix );
		$lines[] = "\t\tif ( ( \$island['block'] ?? '' ) !== \$name || '' === ( \$island['src'] ?? '' ) ) {";
		$lines[] = "\t\t\tcontinue;";
		$lines[] = "\t\t}";
		$lines[] = sprintf( "\t\twp_enqueue_script( \$island['handle'], %s_URL . \$island['src'], array(), '1.0.0', true );", strtoupper( $fn_prefix ) );
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = "\treturn \$content;";
		$lines[] = '}';
		$lines[] = sprintf( "add_filter( 'render_block', '%s_enqueue_islands', 10, 2 );", $fn_prefix );
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Render the mu-plugin root loader stub.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $main_file   Main plugin file relative to plugins dir.
	 * @param string $site_name   Human-readable site name.
	 * @return string
	 */
	private static function mu_loader_file( string $plugin_slug, string $main_file, string $site_name ): string {
		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Plugin Name: SSI Companion Loader: ' . $site_name;
		$lines[] = ' * Description: Must-use loader that requires the ' . $plugin_slug . ' companion plugin. Generated by Static Site Importer.';
		$lines[] = ' *';
		$lines[] = ' * @package StaticSiteImporterCompanion';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = sprintf( "\$ssi_companion_main = __DIR__ . '/%s';", $main_file );
		$lines[] = 'if ( is_readable( $ssi_companion_main ) ) {';
		$lines[] = "\trequire_once \$ssi_companion_main;";
		$lines[] = '}';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Export island descriptors as a PHP array literal for the generated file.
	 *
	 * @param array<int,array<string,string>> $preserved Preserved island descriptors.
	 * @return string
	 */
	private static function export_islands_php( array $preserved ): string {
		if ( empty( $preserved ) ) {
			return 'array()';
		}

		$rows = array();
		foreach ( $preserved as $island ) {
			$rows[] = sprintf(
				"\t\tarray( 'handle' => '%s', 'src' => '%s', 'block' => '%s' ),",
				self::php_single_quote( $island['handle'] ),
				self::php_single_quote( $island['relative_src'] ),
				self::php_single_quote( $island['block'] )
			);
		}

		return "array(\n" . implode( "\n", $rows ) . "\n\t)";
	}

	/**
	 * Export the PHP-only block registration specs as a PHP array literal.
	 *
	 * @param array<int,array<string,mixed>> $block_specs Block registration specs.
	 * @return string
	 */
	private static function export_block_specs_php( array $block_specs ): string {
		if ( empty( $block_specs ) ) {
			return 'array()';
		}

		return self::export_php_value( array_values( $block_specs ), 1 );
	}

	/**
	 * Export an arbitrary scalar/array value as deterministic, lint-clean PHP.
	 *
	 * Used to embed register_block_type() argument arrays (api_version,
	 * attributes, supports, ...) directly into the generated plugin file so the
	 * companion plugin needs no block.json to describe its blocks.
	 *
	 * @param mixed $value  Value to export.
	 * @param int   $indent Current indentation depth (tabs).
	 * @return string
	 */
	private static function export_php_value( $value, int $indent = 0 ): string {
		if ( is_array( $value ) ) {
			if ( array() === $value ) {
				return 'array()';
			}

			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$pad     = str_repeat( "\t", $indent + 1 );
			$rows    = array();
			foreach ( $value as $key => $item ) {
				$exported = self::export_php_value( $item, $indent + 1 );
				if ( $is_list ) {
					$rows[] = $pad . $exported . ',';
				} else {
					$rows[] = $pad . "'" . self::php_single_quote( (string) $key ) . "' => " . $exported . ',';
				}
			}

			return "array(\n" . implode( "\n", $rows ) . "\n" . str_repeat( "\t", $indent ) . ')';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		if ( is_int( $value ) ) {
			return (string) $value;
		}

		if ( is_float( $value ) ) {
			// var_export keeps a parseable float literal (e.g. trailing .0).
			return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_var_export -- Generating a deterministic PHP literal for the scaffolded plugin file.
		}

		return "'" . self::php_single_quote( (string) $value ) . "'";
	}

	/**
	 * Build a render.php template that the dynamic block's render_callback runs.
	 *
	 * The closure exposes $attributes, $content, and $block, so a render.php that
	 * echoes from those variables works exactly like a block.json `render` file.
	 * An empty payload falls back to passing inner content through unchanged.
	 *
	 * @param string $render Render markup or PHP from the payload.
	 * @return string
	 */
	private static function normalize_render( string $render ): string {
		$trimmed = ltrim( $render );
		if ( '' === $trimmed ) {
			return "<?php\n/**\n * Generated companion block render (server-rendered dynamic block).\n *\n * @package StaticSiteImporterCompanion\n *\n * @var array<string,mixed> \$attributes Block attributes.\n * @var string              \$content    Inner block content.\n * @var WP_Block            \$block      Block instance.\n */\n\necho \$content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner block content is already sanitized by WordPress.\n";
		}

		if ( str_starts_with( $trimmed, '<?php' ) || str_starts_with( $trimmed, '<?=' ) ) {
			return $render;
		}

		return "<?php\n/**\n * Generated companion block render (server-rendered dynamic block).\n *\n * @package StaticSiteImporterCompanion\n *\n * @var array<string,mixed> \$attributes Block attributes.\n * @var string              \$content    Inner block content.\n * @var WP_Block            \$block      Block instance.\n */\n?>\n" . $render;
	}

	/**
	 * Sanitize a slug, falling back to a portable regex when WP is unavailable.
	 *
	 * @param string $value Raw slug.
	 * @return string
	 */
	private static function sanitize_slug( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'sanitize_title' ) ) {
			$sanitized = sanitize_title( $value );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		}

		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}

	/**
	 * Sanitize a relative file path, rejecting traversal and absolute paths.
	 *
	 * @param string $value Raw relative path.
	 * @return string
	 */
	private static function sanitize_relative_path( string $value ): string {
		$value = str_replace( '\\', '/', trim( $value ) );
		$value = ltrim( $value, '/' );
		if ( '' === $value || str_contains( $value, '../' ) || str_contains( $value, './' ) ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $value ) as $segment ) {
			$segment = preg_replace( '/[^A-Za-z0-9._-]/', '', $segment );
			if ( '' === $segment || '..' === $segment ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Escape a value for embedding inside single-quoted generated PHP.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function php_single_quote( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}
}
