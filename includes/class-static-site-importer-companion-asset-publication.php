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
	public const SCOPING_SCHEMA   = 'static-site-importer/companion-asset-scoping/v2';
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
	 * Each materialized page replays the stylesheet sequence its canonical
	 * document publishes (WordPressSitePlan::documentStylesheets()): every
	 * ordered occurrence gets its own handle and media while repeated
	 * occurrences share one published file, so linked A, B, A still ends at A.
	 * Published stylesheets the plan does not sequence (importer overlays)
	 * follow each page's plan sequence in publication order.
	 *
	 * @param array<string,mixed>              $plan                  Canonical WordPress site plan.
	 * @param array<int,array<string,mixed>>   $posts                 Materialized posts with `id` and `source_path`.
	 * @param array<int,array<string,string>>  $published_stylesheets Published stylesheet `src` targets with versions, in publication order.
	 * @param string                           $publication_uri       Publication base URI.
	 * @return array<string,mixed>
	 */
	public static function scoped_asset_config( array $plan, array $posts, array $published_stylesheets, string $publication_uri ): array {
		$versions = array();
		foreach ( $published_stylesheets as $published ) {
			$versions[ (string) ( $published['src'] ?? '' ) ] = (string) ( $published['version'] ?? '' );
		}
		unset( $versions[''] );
		$planned = array();
		$sources = array();
		foreach ( is_array( $plan['assets'] ?? null ) ? $plan['assets'] : array() as $asset ) {
			if ( 'css' === ( $asset['kind'] ?? null ) ) {
				$planned[ (string) ( $asset['target_path'] ?? '' ) ] = true;
			}
		}
		foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
			$sources[ (string) ( $page['source_path'] ?? '' ) ] = true;
		}
		$stylesheets = array();
		$overlays    = array();
		foreach ( array_diff_key( $versions, $planned ) as $src => $version ) {
			$handle                 = 'ssi-page-style-' . substr( hash( 'sha256', $src ), 0, 12 );
			$stylesheets[ $handle ] = array(
				'src'     => $src,
				'version' => $version,
				'media'   => 'all',
				'context' => 'both',
			);
			$overlays[]             = $handle;
		}
		$sequences    = array();
		$posts_config = array();
		foreach ( $posts as $post ) {
			$id     = (int) ( $post['id'] ?? 0 );
			$source = (string) ( $post['source_path'] ?? '' );
			if ( $id <= 0 || ! isset( $sources[ $source ] ) ) {
				continue;
			}
			if ( ! isset( $sequences[ $source ] ) ) {
				$sequences[ $source ] = array();
				foreach ( \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::documentStylesheets( $plan, $source ) as $row ) {
					if ( ! isset( $versions[ $row['target_path'] ] ) ) {
						continue;
					}
					$handle                 = 'ssi-' . $row['handle'];
					$stylesheets[ $handle ] = array(
						'src'     => $row['target_path'],
						'version' => $versions[ $row['target_path'] ],
						'media'   => '' === $row['media'] ? 'all' : $row['media'],
						'context' => $row['stylesheet_target'],
					);
					$sequences[ $source ][] = $handle;
				}
				array_push( $sequences[ $source ], ...$overlays );
			}
			$posts_config[ (string) $id ] = $sequences[ $source ];
		}
		return array(
			'schema'          => self::SCOPING_SCHEMA,
			'publication_uri' => $publication_uri,
			'stylesheets'     => $stylesheets,
			'posts'           => $posts_config,
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
		$lines[] = "\t\$handles = \$post_id > 0 ? ( \$static_site_importer_scoped_assets['posts'][ (string) \$post_id ] ?? null ) : null;";
		$lines[] = "\t\$base    = (string) ( \$static_site_importer_scoped_assets['publication_uri'] ?? '' );";
		$lines[] = "\tif ( ! is_array( \$handles ) || '' === \$base ) {";
		$lines[] = "\t\treturn;";
		$lines[] = "\t}";
		$lines[] = "\t// One handle per ordered stylesheet occurrence; repeated occurrences share a file.";
		$lines[] = "\tforeach ( \$handles as \$handle ) {";
		$lines[] = "\t\t\$stylesheet = \$static_site_importer_scoped_assets['stylesheets'][ \$handle ] ?? null;";
		$lines[] = "\t\tif ( ! is_array( \$stylesheet ) || '' === (string) ( \$stylesheet['src'] ?? '' ) || ( 'editor' === \$context ? 'frontend' : 'editor' ) === ( \$stylesheet['context'] ?? 'both' ) ) {";
		$lines[] = "\t\t\tcontinue;";
		$lines[] = "\t\t}";
		$lines[] = "\t\twp_enqueue_style( (string) \$handle, \$base . '/' . ltrim( (string) \$stylesheet['src'], '/' ), array(), (string) ( \$stylesheet['version'] ?? '' ), (string) ( \$stylesheet['media'] ?? 'all' ) );";
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
