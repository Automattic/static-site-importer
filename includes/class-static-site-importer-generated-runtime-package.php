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
