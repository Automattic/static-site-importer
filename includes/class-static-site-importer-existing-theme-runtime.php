<?php
/** Importer-owned host runtime fold-in. @package StaticSiteImporter */
final class Static_Site_Importer_Existing_Theme_Runtime {
	/** Discover ownership from durable artifacts, never the theme's display name. */
	public static function host(): ?array {
		if ( ! function_exists( 'get_stylesheet_directory' ) ) {
			return null;
		}
		$dir = get_stylesheet_directory();
		if ( is_link( $dir ) || is_link( $dir . '/ssi-runtime' ) || is_link( $dir . '/ssi-runtime/companion.json' ) ) {
			return null;
		}
		$manifest = self::json( $dir . '/static-site-importer-manifest.json' );
		$config   = self::json( $dir . '/ssi-runtime/companion.json' );
		if ( 'static-site-importer/source-of-truth-manifest/v1' !== ( $manifest['schema'] ?? '' ) || empty( $manifest['import_run_id'] ) || 'theme' !== ( $config['owner'] ?? '' ) || get_stylesheet() !== ( $config['owner_slug'] ?? '' ) || ! is_file( $dir . '/ssi-runtime/runtime.php' ) ) {
			return null;
		}
		$namespace = '';
		foreach ( $config['block_directories'] ?? array() as $block ) {
			if ( ! is_string( $block ) || ! preg_match( '/^[a-z0-9-]+$/', $block ) ) {
				return null;
			}
			$metadata = self::json( $dir . '/ssi-runtime/blocks/' . $block . '/block.json' );
			$name     = (string) ( $metadata['name'] ?? '' );
			if ( ! preg_match( '/^(ssi-[a-z0-9-]+)\/[a-z0-9-]+$/', $name, $match ) || ( '' !== $namespace && $namespace !== $match[1] ) ) {
				return null;
			}
			$namespace = $match[1];
		}
		// New empty libraries stamp their namespace explicitly. Older packages
		// carry it in their runtime Text Domain, independent of the theme name.
		$namespace = '' !== $namespace ? $namespace : (string) ( $config['block_namespace'] ?? '' );
		if ( '' === $namespace ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect generated runtime provenance, not the host theme name.
			$runtime = (string) file_get_contents( $dir . '/ssi-runtime/runtime.php' );
			if ( preg_match( '/^ \* Text Domain: (ssi-[a-z0-9-]+)$/m', $runtime, $match ) ) {
				$namespace = $match[1];
			}
		}
		return preg_match( '/^ssi-[a-z0-9-]+$/', $namespace ) ? compact( 'dir', 'config', 'namespace' ) : null;
	}

	private static function json( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read importer-owned runtime metadata.
		$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/** Restrict compiler namespace override to existing-theme requests. */
	public static function namespace_for( array $args, string $fallback ): string {
		$host = 'existing_theme' === ( $args['destination'] ?? '' ) ? self::host() : null;
		return $host['namespace'] ?? $fallback;
	}

	/** Pure write projection; no existing block directory is ever replaced. */
	public static function project( array $payload ) {
		$host = self::host();
		if ( ! $host ) {
			return null;
		}
		require_once __DIR__ . '/class-static-site-importer-generated-runtime-package.php';
		$package = Static_Site_Importer_Generated_Runtime_Package::theme( $payload, get_stylesheet() );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( $package['namespace'] !== $host['namespace'] ) {
			// ssi-error-class: integrity
			return new WP_Error( 'existing_theme_runtime_block_conflict', 'Generated blocks do not use the host namespace.' );
		}
		$collision = Static_Site_Importer_Generated_Runtime_Package::preflight_blocks( $package, $host['dir'] );
		if ( is_wp_error( $collision ) ) {
			return $collision;
		}
		$config = $host['config'];
		$writes = array();
		$added  = array();
		foreach ( $package['files'] as $path => $bytes ) {
			if ( ! preg_match( '~^ssi-runtime/blocks/([a-z0-9-]+)/~', $path, $match ) ) {
				continue;
			}
			$segments = explode( '/', $path );
			$parent   = $host['dir'];
			foreach ( $segments as $segment ) {
				$parent .= '/' . $segment;
				if ( is_link( $parent ) || ( file_exists( $parent ) && $parent !== $host['dir'] . '/' . $path && ! is_dir( $parent ) ) ) {
					// ssi-error-class: integrity
					return new WP_Error( 'existing_theme_runtime_block_conflict', 'Unsafe host runtime path.', array( 'target_path' => $path ) );
				}
			}
			if ( is_dir( $host['dir'] . '/ssi-runtime/blocks/' . $match[1] ) ) {
				if ( ! in_array( $match[1], $config['block_directories'], true ) ) {
					// ssi-error-class: integrity
					return new WP_Error( 'existing_theme_runtime_block_conflict', 'Existing block directory is not registered by the host runtime.', array( 'target_path' => $path ) );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare immutable host block bytes before admitting reuse.
				if ( ! is_file( $host['dir'] . '/' . $path ) || file_get_contents( $host['dir'] . '/' . $path ) !== $bytes ) {
					// ssi-error-class: integrity
					return new WP_Error( 'existing_theme_runtime_block_conflict', 'Host block directory differs from the generated library.', array( 'target_path' => $path ) );
				}
				continue;
			}
			$added[ $match[1] ] = true;
			$writes[]           = self::write( $path, $bytes );
		}
		foreach ( $host['config']['block_directories'] as $block ) {
			$prefix = 'ssi-runtime/blocks/' . $block . '/';
			if ( ! isset( $package['files'][ $prefix . 'block.json' ] ) ) {
				continue;
			}
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $host['dir'] . '/' . $prefix, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$relative = $prefix . substr( $file->getPathname(), strlen( $host['dir'] . '/' . $prefix ) );
				if ( $file->isLink() || ! isset( $package['files'][ $relative ] ) ) {
					// ssi-error-class: integrity
					return new WP_Error( 'existing_theme_runtime_block_conflict', 'Host block directory contains additional files.', array( 'target_path' => $relative ) );
				}
			}
		}
		$generated_config = json_decode( $package['files']['ssi-runtime/companion.json'], true );
		foreach ( $generated_config['editor_scripts'] ?? array() as $script ) {
			if ( in_array( $script, $config['editor_scripts'] ?? array(), true ) ) {
				continue;
			}
			$path = 'ssi-runtime/' . $script['src'];
			if ( is_file( $host['dir'] . '/' . $path ) ) {
				// ssi-error-class: integrity
				return new WP_Error( 'existing_theme_runtime_block_conflict', 'Host editor script differs from the generated library.', array( 'target_path' => $path ) );
			}
			$config['editor_scripts'][] = $script;
			$writes[]                   = self::write( $path, $package['files'][ $path ] );
		}
		foreach ( array_keys( $added ) as $block ) {
			$config['block_directories'][] = $block;
		}
		if ( $config !== $host['config'] ) {
			$writes[] = self::write( 'ssi-runtime/companion.json', wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		}
		return array(
			'writes'  => $writes,
			'added'   => array_keys( $added ),
			'host'    => $host,
			'config'  => $config,
			'package' => $package,
		);
	}

	private static function write( string $path, string $bytes ): array {
		return array(
			'kind'                    => 'theme_bootstrap',
			'target_path'             => $path,
			'source_path'             => '',
			'payload'                 => array(
				'encoding' => 'utf8',
				'data'     => $bytes,
			),
			'payload_hash'            => hash( 'sha256', $bytes ),
			'reconciliation_identity' => hash( 'sha256', $path ),
		);
	}
}
