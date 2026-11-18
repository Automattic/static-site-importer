<?php
/**
 * Destination-owned runtime package. Producer payloads remain untrusted data;
 * only the validated scaffold emits executable PHP from audited SSI primitives.
 *
 * @package StaticSiteImporter
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-static-site-importer-companion-plugin.php';

final class Static_Site_Importer_Generated_Runtime_Package {
	public const DIRECTORY = 'ssi-runtime';

	/** Materialized-content markers that prove a site uses an optional runtime feature. */
	private const FEATURE_MARKERS = array(
		'forms'        => array( 'wp:jetpack/', 'grunion', 'ssi-source-submit--', 'ssi-source-semantic-wrapper-' ),
		'interactions' => array( Static_Site_Importer_Capture_Evidence::MEMBER_LOGIN, 'wp:tabs' ),
		'commerce'     => array( 'woocommerce', 'ssi-item-template-', 'staticSiteImporterArchiveDocument', 'staticSiteImporterCartLink', 'staticSiteImporterItemSlot', 'staticSiteImporterListingControl', 'staticSiteImporterListingQuery', 'staticSiteImporterFieldPresentation', 'staticSiteImporterGalleryIdentity' ),
	);

	/** Runtime lifecycle capabilities whose providers render through an optional runtime. */
	private const FEATURE_CAPABILITIES = array(
		'form' => 'forms',
		'shop' => 'commerce',
	);

	/**
	 * Scope a theme runtime payload to what the site uses: declare the optional
	 * runtime features its content and lifecycle need, and keep only generated
	 * blocks that a materialized block document references.
	 *
	 * @param array<string,mixed> $payload   Theme runtime payload.
	 * @param array<mixed>        $content   Resolved plan and other documents the theme renders.
	 * @param array<string,mixed> $lifecycle Runtime lifecycle with selected provider adapters.
	 * @return array<string,mixed>
	 */
	public static function scoped_payload( array $payload, array $content, array $lifecycle = array() ): array {
		$strings = array();
		array_walk_recursive(
			$content,
			static function ( $value ) use ( &$strings ): void {
				if ( is_string( $value ) && '' !== $value ) {
					$strings[] = $value;
				}
			}
		);
		$used_blocks = array();
		foreach ( $strings as $value ) {
			if ( preg_match_all( '~wp:([a-z][a-z0-9-]*)\\\\?/([a-z0-9-]+)~', $value, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$used_blocks[ $match[1] . '/' . $match[2] ] = true;
				}
			}
		}
		$namespace = Static_Site_Importer_Companion_Plugin::block_namespace( $payload );
		$blocks    = array();
		foreach ( is_array( $payload['blocks'] ?? null ) ? $payload['blocks'] : array() as $block ) {
			if ( ! is_array( $block ) ) {
				$blocks[] = $block; // Left for payload validation to reject.
				continue;
			}
			$declared = is_string( $block['block_json']['name'] ?? null ) ? $block['block_json']['name'] : '';
			$name     = '' !== $declared ? $declared : $namespace . '/' . ( is_string( $block['name'] ?? null ) ? $block['name'] : '' );
			if ( isset( $used_blocks[ $name ] ) ) {
				$blocks[] = $block;
				if ( is_string( $block['render'] ?? null ) ) {
					$strings[] = $block['render'];
				}
			}
		}
		if ( array_key_exists( 'blocks', $payload ) ) {
			$payload['blocks'] = $blocks;
		}
		$features = array();
		foreach ( array_merge( $lifecycle['dependencies'] ?? array(), $lifecycle['entities'] ?? array() ) as $entry ) {
			$feature = self::FEATURE_CAPABILITIES[ (string) ( $entry['adapter']['capability'] ?? ( $entry['declaration']['capability'] ?? '' ) ) ] ?? '';
			if ( '' !== $feature ) {
				$features[ $feature ] = true;
			}
		}
		foreach ( self::FEATURE_MARKERS as $feature => $markers ) {
			foreach ( $strings as $value ) {
				foreach ( isset( $features[ $feature ] ) ? array() : $markers as $marker ) {
					if ( false !== stripos( $value, $marker ) ) {
						$features[ $feature ] = true;
						break;
					}
				}
			}
		}
		$payload['runtime_features'] = array_values( array_intersect( Static_Site_Importer_Companion_Plugin::RUNTIME_FEATURES, array_keys( $features ) ) );
		return $payload;
	}

	/**
	 * Generated runtime files the package ships, for the import report.
	 *
	 * @param array<string,mixed> $package Theme runtime package.
	 * @return array<int,string>
	 */
	public static function shipped_files( array $package ): array {
		$files = array_map( 'strval', array_keys( $package['files'] ?? array() ) );
		sort( $files );
		return $files;
	}

	/** Build the same validated runtime for the theme destination, without a plugin or loader. */
	public static function theme( array $payload, string $owner_slug = '' ) {
		$package = Static_Site_Importer_Companion_Plugin::scaffold( $payload, 'theme', $owner_slug );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		$files = array();
		foreach ( $package['files'] as $path => $bytes ) {
			$files[ self::DIRECTORY . '/' . substr( $path, strlen( $package['slug'] ) + 1 ) ] = $bytes;
		}
		$package['files']      = $files;
		$package['entrypoint'] = self::DIRECTORY . '/runtime.php';
		unset( $package['plugin_file'], $package['mu_plugin'], $package['loader_file'] );
		return $package;
	}

	/** Compose trusted file writes into the resolved projection, keeping normal conflict/journal protections. */
	public static function with_writes( array $resolved, array $package ): array {
		$bootstrap = "\nrequire_once get_theme_file_path( 'ssi-runtime/runtime.php' );\n";
		$found     = false;
		$targets   = array();
		foreach ( $resolved['writes'] as &$write ) {
			$target             = (string) ( $write['target_path'] ?? '' );
			$targets[ $target ] = true;
			if ( 'functions.php' !== $target ) {
				continue;
			}
			$content = Static_Site_Importer_Site_Plan_Persistence::payload_data( $write );
			if ( ! str_contains( $content, $bootstrap ) ) {
				$content .= $bootstrap;
			}
			$write['payload']      = array(
				'encoding' => 'utf8',
				'data'     => $content,
			);
			$write['payload_hash'] = hash( 'sha256', $content );
			$found                 = true;
		}
		unset( $write );
		if ( ! $found ) {
			$resolved['writes'][] = self::write( 'functions.php', "<?php\n" . $bootstrap, 'theme_bootstrap' );
		}
		foreach ( $package['files'] as $path => $bytes ) {
			if ( isset( $targets[ $path ] ) ) {
				// ssi-error-class: integrity
				throw new InvalidArgumentException( 'generated_runtime_file_collision' );
			}
			$resolved['writes'][] = self::write( $path, $bytes, 'theme_bootstrap' );
		}
		return $resolved;
	}

	private static function write( string $path, string $bytes, string $kind ): array {
		$binary = 1 !== preg_match( '//u', $bytes );
		return array(
			'kind'                    => $kind,
			'source_path'             => '',
			'target_path'             => $path,
			'payload'                 => array(
				'encoding' => $binary ? 'base64' : 'utf8',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary artifact payloads use explicit base64 transport encoding.
				'data'     => $binary ? base64_encode( $bytes ) : $bytes,
			),
			'payload_hash'            => hash( 'sha256', $bytes ),
			'reconciliation_identity' => hash( 'sha256', 'theme-runtime:' . $path ),
		);
	}

	/** Require exact destination ownership for reuse; namespaces are never ownership proof. */
	public static function preflight_blocks( array $package, string $directory ) {
		$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;
		foreach ( $package['block_names'] as $name ) {
			if ( ! $registry || ! $registry->is_registered( $name ) ) {
				continue;
			}
			$owner = $GLOBALS['static_site_importer_runtime_block_owners'][ $name ] ?? array();
			if ( 'theme' !== ( $owner['owner'] ?? '' ) || ( $owner['path'] ?? '' ) !== $directory . '/' . $package['entrypoint'] ) {
				return new WP_Error( 'static_site_importer_runtime_block_name_collision', 'Generated block name is already owned by another destination.', array( 'block_name' => $name ) );
			}
		}
		return true;
	}

	/** Snapshot new and previously owned registrations for this destination. */
	public static function registration_snapshot( array $package, string $directory ): array {
		$names = $package['block_names'];
		foreach ( $GLOBALS['static_site_importer_runtime_block_owners'] ?? array() as $name => $owner ) {
			if ( 'theme' === ( $owner['owner'] ?? '' ) && ( $owner['path'] ?? '' ) === $directory . '/' . $package['entrypoint'] ) {
				$names[] = $name;
			}
		}
		$registry = WP_Block_Type_Registry::get_instance();
		$snapshot = array();
		foreach ( array_unique( $names ) as $name ) {
			$snapshot[ $name ] = array(
				'block' => $registry->get_registered( $name ),
				'owner' => $GLOBALS['static_site_importer_runtime_block_owners'][ $name ] ?? null,
			);
		}
		return $snapshot;
	}

	/** Restore only the registrations touched by this destination transaction. */
	public static function restore_registration( array $snapshot ): void {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( $snapshot as $name => $before ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
			unset( $GLOBALS['static_site_importer_runtime_block_owners'][ $name ] );
			if ( $before['block'] ) {
				$registry->register( $before['block'] );
			}
			if ( null !== $before['owner'] ) {
				$GLOBALS['static_site_importer_runtime_block_owners'][ $name ] = $before['owner'];
			}
		}
	}

	/** Load the installed theme package immediately for page-ready/editor admission. */
	public static function register( array $package, string $directory ) {
		$callback  = $package['registration_callback'];
		$collision = self::preflight_blocks( $package, $directory );
		if ( is_wp_error( $collision ) ) {
			return $collision;
		}
		if ( ! is_callable( $callback ) ) {
			require $directory . '/' . $package['entrypoint'];
		}
		if ( ! is_string( $callback ) || ! is_callable( $callback ) ) {
			return new WP_Error( 'static_site_importer_theme_runtime_registration_missing', 'Theme runtime registration callback is unavailable.' );
		}
		$callback_file = ( new ReflectionFunction( $callback ) )->getFileName();
		if ( false === $callback_file || realpath( $callback_file ) !== realpath( $directory . '/' . $package['entrypoint'] ) ) {
			return new WP_Error( 'static_site_importer_theme_runtime_callback_collision', 'Theme runtime callback belongs to another destination.' );
		}
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( self::registration_snapshot( $package, $directory ) as $name => $before ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
			unset( $GLOBALS['static_site_importer_runtime_block_owners'][ $name ] );
		}
		call_user_func( $callback );
		foreach ( $package['block_names'] as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				return new WP_Error( 'static_site_importer_theme_runtime_registration_incomplete', 'Theme runtime did not register every generated block.' );
			}
		}
		return true;
	}
}
