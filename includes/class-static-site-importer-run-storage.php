<?php
/**
 * Private filesystem root for importer-owned run state.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the parent directory that holds importer-owned working state.
 *
 * Retained run workspaces, lifecycle checkpoints, URL collection work
 * directories, response artifacts and validation artifacts are the importer's
 * own state, not site content. Writing them under `wp_upload_dir()['basedir']`
 * files them inside the media library, so every path that packages a built site
 * — archives, backups, media offloaders, media scanners — carries the
 * importer's run state to the destination alongside genuine media. The importer
 * reads all of it from disk and never serves any of it over HTTP, so none of it
 * needs to be in the uploads directory.
 */
final class Static_Site_Importer_Run_Storage {

	private const DIRECTORY = 'static-site-importer';

	/**
	 * Absolute path of one importer-owned working directory.
	 *
	 * @param string $purpose Directory name below the importer-owned root.
	 * @return string
	 */
	public static function path( string $purpose ): string {
		$purpose = trim( $purpose, '/' );
		$root    = self::root();
		return '' === $purpose ? $root : rtrim( $root, '/\\' ) . '/' . $purpose;
	}

	/**
	 * Absolute path of the parent directory for importer-owned working state.
	 *
	 * @return string
	 */
	public static function root(): string {
		$root = self::default_root();
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'static_site_importer_run_storage_root', $root ) : $root;
	}

	/**
	 * The uploads-based root earlier releases wrote to.
	 *
	 * Retained so scheduled expiry sweeps still reach state an upgraded site
	 * left behind, and as the fallback when `wp-content` cannot be written.
	 *
	 * @return string
	 */
	public static function legacy_uploads_root(): string {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$basedir = trim( $uploads['basedir'] ?? '' );
		return rtrim( '' !== $basedir ? $basedir : sys_get_temp_dir(), '/\\' ) . '/' . self::DIRECTORY;
	}

	/**
	 * Prefer a private directory beside the media library, not inside it.
	 *
	 * @return string
	 */
	private static function default_root(): string {
		$content = defined( 'WP_CONTENT_DIR' ) ? rtrim( WP_CONTENT_DIR, '/\\' ) : '';
		if ( '' === $content ) {
			return self::legacy_uploads_root();
		}
		$private = $content . '/' . self::DIRECTORY;
		if ( is_dir( $private ) ) {
			return $private;
		}
		return is_dir( $content ) && is_writable( $content ) ? $private : self::legacy_uploads_root(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Chooses the importer-owned working root without initializing a global filesystem transport.
	}
}
