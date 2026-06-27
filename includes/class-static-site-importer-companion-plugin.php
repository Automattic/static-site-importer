<?php
/**
 * Companion-plugin scaffolder.
 *
 * Generates a standalone, theme-independent WordPress plugin that houses a
 * site's generated custom blocks (registered from their own block.json) and any
 * preserved island JS, scoped to where it is used. The compiled artifact owns
 * the block.json + render + assets payload; this class is the deterministic
 * destination that turns that payload into an installable plugin file set.
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
				'Companion-plugin payload must declare at least one block with a name and block.json.'
			);
		}

		$plugin_slug     = 'ssi-' . $site_slug;
		$block_namespace = $plugin_slug;
		$mu_plugin       = ! empty( $payload['mu_plugin'] );
		$site_name       = self::site_name( $payload, $site_slug );

		$files       = array();
		$block_names = array();
		$preserved   = self::preserved_js( $payload, $block_namespace );

		foreach ( $blocks as $block ) {
			$built = self::build_block( $block, $block_namespace );
			if ( is_wp_error( $built ) ) {
				return $built;
			}

			$block_names[] = $built['block_name'];
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
				$main_file => self::main_plugin_file( $plugin_slug, $block_namespace, $site_name, $block_names, $preserved ),
			),
			$files
		);

		$descriptor = array(
			'schema'      => self::PAYLOAD_SCHEMA,
			'slug'        => $plugin_slug,
			'namespace'   => $block_namespace,
			'site_slug'   => $site_slug,
			'plugin_file' => $main_file,
			'mu_plugin'   => $mu_plugin,
			'block_names' => $block_names,
			'loader_file' => '',
			'files'       => $files,
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
	 * Build one block's file set and fully-qualified name.
	 *
	 * @param array<string,mixed> $block     Block payload entry.
	 * @param string              $block_namespace Plugin block namespace.
	 * @return array{block_name:string,dir:string,files:array<string,string>}|WP_Error
	 */
	private static function build_block( array $block, string $block_namespace ) {
		$name = isset( $block['name'] ) && is_scalar( $block['name'] ) ? self::sanitize_slug( (string) $block['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_block_name_missing',
				'Each companion-plugin block must declare a sanitizable name.'
			);
		}

		$block_json = self::block_json( $block, $block_namespace . '/' . $name );
		if ( is_wp_error( $block_json ) ) {
			return $block_json;
		}

		$files = array( 'block.json' => $block_json );

		$render = isset( $block['render'] ) && is_scalar( $block['render'] ) ? (string) $block['render'] : '';
		if ( '' !== $render ) {
			$files['render.php'] = self::normalize_render( $render );
		}

		$view_js = isset( $block['view_js'] ) && is_scalar( $block['view_js'] ) ? (string) $block['view_js'] : '';
		if ( '' !== $view_js ) {
			$files['view.js'] = $view_js;
		}

		$assets = isset( $block['assets'] ) && is_array( $block['assets'] ) ? $block['assets'] : array();
		foreach ( $assets as $relative => $content ) {
			$relative = self::sanitize_relative_path( (string) $relative );
			if ( '' === $relative || ! is_scalar( $content ) ) {
				continue;
			}
			$files[ $relative ] = (string) $content;
		}

		return array(
			'block_name' => $block_namespace . '/' . $name,
			'dir'        => $name,
			'files'      => $files,
		);
	}

	/**
	 * Resolve a block.json string, forcing the namespaced block name.
	 *
	 * @param array<string,mixed> $block      Block payload entry.
	 * @param string              $block_name Fully-qualified block name.
	 * @return string|WP_Error
	 */
	private static function block_json( array $block, string $block_name ) {
		$raw = $block['block_json'] ?? null;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = null;
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_block_json_invalid',
				sprintf( 'Block %s must declare block_json as a JSON object or string.', $block_name )
			);
		}

		// The companion plugin owns the namespace, so the generated block.json is
		// authoritative for the block name regardless of what the payload carried.
		$decoded['name'] = $block_name;
		if ( ! isset( $decoded['$schema'] ) ) {
			$decoded = array( '$schema' => 'https://schemas.wp.org/trunk/block.json' ) + $decoded;
		}
		if ( ! isset( $decoded['apiVersion'] ) ) {
			$decoded['apiVersion'] = 3;
		}

		return self::encode_json( $decoded );
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
	 * @param string                          $plugin_slug Plugin slug.
	 * @param string                          $block_namespace   Block namespace.
	 * @param string                          $site_name   Human-readable site name.
	 * @param array<int,string>               $block_names Fully-qualified block names.
	 * @param array<int,array<string,string>> $preserved   Preserved island descriptors.
	 * @return string
	 */
	private static function main_plugin_file(
		string $plugin_slug,
		string $block_namespace,
		string $site_name,
		array $block_names,
		array $preserved
	): string {
		$header_name = sprintf( 'SSI Companion: %s', $site_name );
		$fn_prefix   = str_replace( '-', '_', $plugin_slug );
		$islands_php = self::export_islands_php( $preserved );

		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Plugin Name: ' . $header_name;
		$lines[] = ' * Description: Generated companion plugin housing custom blocks and preserved island JS for ' . $site_name . '. Generated by Static Site Importer.';
		$lines[] = ' * Version: 1.0.0';
		$lines[] = ' * Requires at least: 6.9';
		$lines[] = ' * Requires PHP: 8.1';
		$lines[] = ' * Text Domain: ' . $plugin_slug;
		$lines[] = ' *';
		$lines[] = ' * @package StaticSiteImporterCompanion';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = sprintf( "define( '%s_DIR', plugin_dir_path( __FILE__ ) );", strtoupper( $fn_prefix ) );
		$lines[] = sprintf( "define( '%s_URL', plugin_dir_url( __FILE__ ) );", strtoupper( $fn_prefix ) );
		$lines[] = '';
		$lines[] = '/**';
		$lines[] = ' * Register the generated custom blocks from their own block.json.';
		$lines[] = ' */';
		$lines[] = sprintf( 'function %s_register_blocks() {', $fn_prefix );
		$lines[] = sprintf( "\t\$blocks_dir = %s_DIR . 'blocks';", strtoupper( $fn_prefix ) );
		$lines[] = "\tif ( ! is_dir( \$blocks_dir ) || ! function_exists( 'register_block_type' ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = '';
		$lines[] = sprintf( "\tforeach ( %s as \$block_dir ) {", self::export_block_dirs_php( $block_names ) );
		$lines[] = "\t\t\$path = \$blocks_dir . '/' . \$block_dir;";
		$lines[] = "\t\tif ( is_dir( \$path ) ) {";
		$lines[] = "\t\t\tregister_block_type( \$path );";
		$lines[] = "\t\t}";
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
	 * Export the block directory list as a PHP array literal.
	 *
	 * @param array<int,string> $block_names Fully-qualified block names.
	 * @return string
	 */
	private static function export_block_dirs_php( array $block_names ): string {
		$dirs = array();
		foreach ( $block_names as $block_name ) {
			$parts  = explode( '/', $block_name );
			$dirs[] = "'" . self::php_single_quote( (string) end( $parts ) ) . "'";
		}

		return 'array( ' . implode( ', ', $dirs ) . ' )';
	}

	/**
	 * Ensure the render markup opens with a PHP tag so register_block_type can use it.
	 *
	 * @param string $render Render markup or PHP.
	 * @return string
	 */
	private static function normalize_render( string $render ): string {
		$trimmed = ltrim( $render );
		if ( str_starts_with( $trimmed, '<?php' ) || str_starts_with( $trimmed, '<?=' ) ) {
			return $render;
		}

		return "<?php\n/**\n * Generated companion block render.\n *\n * @package StaticSiteImporterCompanion\n */\n?>\n" . $render;
	}

	/**
	 * JSON-encode a value with stable formatting, guarded for non-WP runtimes.
	 *
	 * @param array<string,mixed> $value Value to encode.
	 * @return string
	 */
	private static function encode_json( array $value ): string {
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $value, $flags );
		} else {
			$encoded = json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Generated outside a WP runtime in tests; wp_json_encode used when available.
		}

		return false === $encoded ? '{}' : $encoded . "\n";
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
