<?php
/**
 * Build-provenance primitive.
 *
 * Single source of truth for the identity of the build that produced an import:
 * the Static Site Importer version, the Blocks Engine transformer versions that
 * performed the conversion, and — for development packages built from source —
 * the packaged provenance receipt written by tools/build-dev-package.mjs.
 *
 * The importer plugin is frequently removed after an import completes, so the
 * build identity has to be recorded into durable output (the source-of-truth
 * manifest, and through it the import report) at import time. Without it a
 * finished site cannot be attributed to a build, and two runs of one source
 * cannot be compared.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the build that produced an import.
 */
class Static_Site_Importer_Build_Provenance {

	/**
	 * Schema of the provenance record embedded in durable import output.
	 */
	public const SCHEMA = 'static-site-importer/build-provenance/v1';

	/**
	 * Plugin-root file carrying the development-package provenance receipt.
	 */
	public const DEVELOPMENT_PACKAGE_RECEIPT = 'build-provenance.json';

	/**
	 * Schema of the development-package receipt written by the package builder.
	 */
	public const DEVELOPMENT_PACKAGE_SCHEMA = 'static-site-importer/development-package-provenance/v1';

	/**
	 * Describe the running build.
	 *
	 * Released builds are identified by version alone. Development packages
	 * additionally carry the source commits they were built from, which is the
	 * only identity that distinguishes two dev builds sharing a release version.
	 *
	 * @param string $imported_at Optional ISO-8601 timestamp. Defaults to now (UTC).
	 * @return array<string,mixed> Provenance record.
	 */
	public static function describe( string $imported_at = '' ): array {
		$provenance = array(
			'schema'               => self::SCHEMA,
			'imported_at'          => '' !== $imported_at ? $imported_at : gmdate( 'Y-m-d\TH:i:s\Z' ),
			'static_site_importer' => array(
				'version' => defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? (string) STATIC_SITE_IMPORTER_VERSION : '',
			),
			'blocks_engine'        => array(
				'php_transformer'   => function_exists( 'blocks_engine_php_transformer_version' ) ? (string) blocks_engine_php_transformer_version() : '',
				'figma_transformer' => function_exists( 'blocks_engine_figma_transformer_version' ) ? (string) blocks_engine_figma_transformer_version() : '',
			),
		);
		$receipt = self::development_package_receipt();
		if ( array() !== $receipt ) {
			$provenance['development_package'] = $receipt;
		}
		return $provenance;
	}

	/**
	 * Read the development-package receipt shipped inside the plugin, when present.
	 *
	 * Released packages do not carry a receipt; the absence of one is itself
	 * meaningful and is reported by omission rather than by an empty structure.
	 *
	 * @param string $plugin_root Optional plugin root override for testing.
	 * @return array<string,mixed> Receipt, or an empty array when this is not a development package.
	 */
	public static function development_package_receipt( string $plugin_root = '' ): array {
		$root = '' !== $plugin_root ? $plugin_root : ( defined( 'STATIC_SITE_IMPORTER_PATH' ) ? (string) STATIC_SITE_IMPORTER_PATH : '' );
		if ( '' === $root ) {
			return array();
		}
		$path = rtrim( $root, '/' ) . '/' . self::DEVELOPMENT_PACKAGE_RECEIPT;
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read; WP_Filesystem is not initialized at import time.
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return array();
		}
		$receipt = json_decode( $contents, true );
		if ( ! is_array( $receipt ) || self::DEVELOPMENT_PACKAGE_SCHEMA !== ( $receipt['schema'] ?? '' ) ) {
			return array();
		}
		return $receipt;
	}
}
