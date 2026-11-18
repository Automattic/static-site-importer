<?php
/**
 * Site file modes for files the importer publishes into theme or plugin directories.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies core's normal file mode to published theme/plugin output.
 *
 * Private workspace and artifact-run files deliberately stay 0600/0700 and must
 * not use this helper.
 */
final class Static_Site_Importer_Published_File_Mode {

	/** The site's normal file mode: FS_CHMOD_FILE, else 0644 & ~umask(), as core. */
	public static function file_mode(): int {
		return defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : ( 0644 & ~umask() );
	}

	/** Give a published file the site's normal file mode. */
	public static function apply_file_mode( string $path ): bool {
		return is_file( $path ) && ! is_link( $path ) && chmod( $path, self::file_mode() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Published theme/plugin files take the site's normal mode instead of tempnam()'s 0600.
	}
}
