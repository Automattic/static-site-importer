<?php
/**
 * Theme-independent asset publication for companion-plugin destinations.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Companion_Plugin' ) ) {
	require_once __DIR__ . '/class-static-site-importer-companion-plugin.php';
}

/**
 * Resolves the companion-plugin home for an import's published assets.
 *
 * An existing-theme import must not write into the theme the destination site
 * already runs. The companion plugin already exists as the theme-independent
 * home for generated blocks and preserved scripts (umbrella #491); published
 * assets land beside it under the same per-site plugin slug, in an `assets`
 * directory. A generated-theme import owns its theme directory and keeps
 * publishing there.
 */
final class Static_Site_Importer_Companion_Asset_Publication {

	public const SCHEMA           = 'static-site-importer/companion-asset-publication/v1';
	public const SCOPING_SCHEMA   = 'static-site-importer/companion-asset-scoping/v1';
	public const GENERATED_THEME  = 'generated_theme';
	public const COMPANION_PLUGIN = 'companion_plugin';

	/**
	 * Resolve the asset publication surface for an import.
	 *
	 * @param array<string,mixed> $args Canonical import arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function resolve( array $args = array() ) {
		$root = self::plugin_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$slug = strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '', strtr( (string) ( $args['slug'] ?? '' ), ' ', '-' ) ) );
		if ( '' === $slug ) {
			return new WP_Error(
				'static_site_importer_companion_asset_publication_slug_missing',
				'An existing-theme destination requires a site slug to host its companion assets.'
			);
		}
		$plugin_slug = Static_Site_Importer_Companion_Plugin::plugin_slug( array( 'site_slug' => $slug ) );
		$dir         = trailingslashit( (string) $root ) . $plugin_slug . '/assets';
		$uri         = trailingslashit( self::plugin_uri_base() ) . $plugin_slug . '/assets';
		return array(
			'schema'      => self::SCHEMA,
			'mode'        => self::COMPANION_PLUGIN,
			'plugin_slug' => $plugin_slug,
			'dir'         => $dir,
			'uri'         => $uri,
		);
	}

	/**
	 * Scope configuration for published stylesheets.
	 *
	 * @param array<int,array<string,int|string>> $stylesheet_targets Relative published stylesheet targets with versions.
	 * @param array<int,int>                      $post_ids           Materialized page IDs the styles scope to.
	 * @param string                              $publication_uri    Publication base URI.
	 * @return array<string,mixed>
	 */
	public static function scoped_asset_config( array $stylesheet_targets, array $post_ids, string $publication_uri ): array {
		$stylesheets = array();
		foreach ( $stylesheet_targets as $index => $target ) {
			$src           = (string) ( $target['src'] ?? '' );
			$version       = (string) ( $target['version'] ?? '' );
			$stylesheets[] = array(
				'handle'  => 'ssi-page-style-' . ( $index + 1 ) . '-' . substr( hash( 'sha256', $src ), 0, 12 ),
				'src'     => $src,
				'version' => $version,
			);
		}
		return array(
			'schema'          => self::SCOPING_SCHEMA,
			'publication_uri' => $publication_uri,
			'post_ids'        => array_values( array_map( 'intval', array_unique( array_filter( $post_ids, static fn( $id ): bool => (int) $id > 0 ) ) ) ),
			'stylesheets'     => $stylesheets,
		);
	}

	/** JSON body for scoped-assets.json. */
	public static function scoped_assets_json( array $config ): string {
		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return ( false === $json ? '' : $json ) . "\n";
	}

	/** asset-loader.php source: enqueues the scoped styles for the imported pages only. */
	public static function scoped_loader_source(): string {
		$lines   = array();
		$lines[] = '<?php';
		$lines[] = '/**';
		$lines[] = ' * Generated companion asset loader.';
		$lines[] = ' *';
		$lines[] = ' * Enqueues published stylesheets only while an imported page renders, so the';
		$lines[] = " * destination theme's global styles never restyle unrelated pages.";
		$lines[] = ' *';
		$lines[] = ' * @package StaticSiteImporterCompanion';
		$lines[] = ' */';
		$lines[] = '';
		$lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
		$lines[] = "\texit;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '$static_site_importer_scoped_assets = json_decode( (string) file_get_contents( __DIR__ . \'/scoped-assets.json\' ), true );';
		$lines[] = 'if ( ! is_array( $static_site_importer_scoped_assets ) ) {';
		$lines[] = "\treturn; // phpcs:ignore Squiz.PHP.NonExecutableCode.ReturnNotFound -- Top-level guard in a generated loader file.";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '$static_site_importer_scoped_enqueue = static function ( string $context ) use ( $static_site_importer_scoped_assets ) {';
		$lines[] = "\tif ( empty( \$static_site_importer_scoped_assets['stylesheets'] ) || ! function_exists( 'wp_enqueue_style' ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = "\tif ( 'editor' === \$context ) {";
		$lines[] = "\t\t\$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;";
		$lines[] = "\t\t\$post   = is_object( \$screen ) && isset( \$screen->post ) ? \$screen->post : null;";
		$lines[] = "\t\t\$post_id = is_object( \$post ) && isset( \$post->ID ) ? (int) \$post->ID : 0;";
		$lines[] = "\t} else {";
		$lines[] = "\t\t\$post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;";
		$lines[] = "\t}";
		$lines[] = "\tif ( \$post_id <= 0 || ! in_array( \$post_id, array_map( 'intval', (array) ( \$static_site_importer_scoped_assets['post_ids'] ?? array() ) ), true ) ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = "\t\$base = (string) ( \$static_site_importer_scoped_assets['publication_uri'] ?? '' );";
		$lines[] = "\tif ( '' === \$base ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = "\tforeach ( (array) \$static_site_importer_scoped_assets['stylesheets'] as \$stylesheet ) {";
		$lines[] = "\t\tif ( ! is_array( \$stylesheet ) || '' === (string) ( \$stylesheet['handle'] ?? '' ) || '' === (string) ( \$stylesheet['src'] ?? '' ) ) {";
		$lines[] = "\t\t\tcontinue;";
		$lines[] = "\t\t}";
		$lines[] = "\t\twp_enqueue_style( (string) \$stylesheet['handle'], \$base . '/' . ltrim( (string) \$stylesheet['src'], '/' ), array(), (string) ( \$stylesheet['version'] ?? '' ) );";
		$lines[] = "\t}";
		$lines[] = '};';
		$lines[] = "add_action( 'wp_enqueue_scripts', static function () use ( \$static_site_importer_scoped_enqueue ): void {";
		$lines[] = "\t\$static_site_importer_scoped_enqueue( 'frontend' );";
		$lines[] = '} );';
		$lines[] = "add_action( 'enqueue_block_editor_assets', static function () use ( \$static_site_importer_scoped_enqueue ): void {";
		$lines[] = "\t\$static_site_importer_scoped_enqueue( 'editor' );";
		$lines[] = '} );';
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * Resolve the companion plugin directory that hosts published assets.
	 *
	 * The deepest existing ancestor must be writable; the materializer creates
	 * the remaining segments through the write boundary.
	 *
	 * @return string|WP_Error
	 */
	private static function plugin_root() {
		$root = defined( 'WP_PLUGIN_DIR' )
			? WP_PLUGIN_DIR
			: ( defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) . '/wp-content/plugins' : '' );
		if ( '' === $root ) {
			return new WP_Error(
				'static_site_importer_companion_asset_publication_unavailable',
				'The destination site has no resolvable companion plugin directory.'
			);
		}
		if ( is_link( $root ) || ( file_exists( $root ) && ! is_dir( $root ) ) ) {
			return new WP_Error(
				'static_site_importer_companion_asset_publication_unsafe',
				'The companion plugin directory cannot host published assets.'
			);
		}
		$probe = $root;
		while ( ! is_dir( $probe ) ) {
			$parent = dirname( $probe );
			if ( '' === $parent || DIRECTORY_SEPARATOR === $parent || '.' === $parent || $parent === $probe ) {
				break;
			}
			$probe = $parent;
		}
		if ( ! is_dir( $probe ) || is_link( $probe ) || ! is_writable( $probe ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Preflights the companion asset publication root before atomic local writes.
			return new WP_Error(
				'static_site_importer_companion_asset_publication_not_ready',
				'The companion plugin directory cannot host published assets.'
			);
		}
		return rtrim( $root, '/' );
	}

	private static function plugin_uri_base(): string {
		if ( defined( 'WP_PLUGIN_URL' ) ) {
			// @phpstan-ignore-next-line phpstanWP.wpConstant.fetch -- Standalone shims expose WP_PLUGIN_URL without plugins_url().
			return rtrim( WP_PLUGIN_URL, '/' );
		}
		if ( defined( 'WP_CONTENT_URL' ) ) {
			// @phpstan-ignore-next-line phpstanWP.wpConstant.fetch -- Standalone shims expose WP_CONTENT_URL without content_url().
			return rtrim( WP_CONTENT_URL, '/' ) . '/plugins';
		}
		return 'wp-content/plugins';
	}
}
