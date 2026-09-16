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
	 * Schema of the producer-owned artifact provenance record a companion
	 * payload may carry (blocks-engine#1874). Optional: a payload from an
	 * older producer has none.
	 */
	public const ARTIFACT_PROVENANCE_SCHEMA = 'blocks-engine/generated-artifact-provenance/v1';

	/**
	 * Site option carrying the composed artifact identity of the most recent
	 * import as a schema-validated JSON string, so a fleet can be queried
	 * without parsing generated file headers.
	 */
	public const ARTIFACT_IDENTITY_OPTION = 'static_site_importer_artifact_identity';

	/**
	 * Neutral default host for generated `Update URI` headers. The .invalid
	 * TLD is reserved (RFC 2606) and routable by no one, so core fires its
	 * update_plugins_/update_themes_{$hostname} hooks without pointing any
	 * generated fleet at a real operator. Consumers override it with the
	 * static_site_importer_update_uri_host filter.
	 */
	public const DEFAULT_UPDATE_URI_HOST = 'static-site-importer.invalid';

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
		$receipt    = self::development_package_receipt();
		if ( array() !== $receipt ) {
			$provenance['development_package'] = $receipt;
		}
		return $provenance;
	}

	/**
	 * Describe the build that produced an import, composed with the artifact
	 * provenance record the companion payload carried.
	 *
	 * This is the identity stamped into artifacts that survive the import and
	 * recorded in the site option. A payload without artifact provenance (a
	 * consumer pinned to an older producer) composes the build identity alone.
	 *
	 * @param array<string,mixed> $artifact_provenance Validated producer artifact provenance record.
	 * @param string              $imported_at         Optional ISO-8601 timestamp. Defaults to now (UTC).
	 * @return array<string,mixed> Composed artifact identity.
	 */
	public static function describe_artifact( array $artifact_provenance = array(), string $imported_at = '' ): array {
		$identity = self::describe( $imported_at );
		if ( array() !== $artifact_provenance && self::valid_artifact_provenance( $artifact_provenance ) ) {
			$identity['artifact'] = $artifact_provenance;
		}
		return $identity;
	}

	/**
	 * Validate a producer-carried artifact provenance record.
	 *
	 * A record is valid when it declares the blocks-engine generated-artifact
	 * schema with non-empty single-line `generator` and `engine_version`
	 * strings and a hex `artifact_hash`. Anything else is malformed and must
	 * be rejected by the consumer rather than silently dropped.
	 *
	 * @param mixed $record Raw provenance value from a companion payload.
	 * @return bool
	 */
	public static function valid_artifact_provenance( mixed $record ): bool {
		if ( ! is_array( $record ) || array_is_list( $record ) ) {
			return false;
		}
		if ( self::ARTIFACT_PROVENANCE_SCHEMA !== (string) ( $record['schema'] ?? '' ) ) {
			return false;
		}
		foreach ( array( 'generator', 'engine_version' ) as $field ) {
			$value = $record[ $field ] ?? null;
			if ( ! is_string( $value ) || '' === trim( $value ) || 1 === preg_match( '/[\r\n\0]/', $value ) ) {
				return false;
			}
		}
		$hash = $record['artifact_hash'] ?? null;

		return is_string( $hash ) && 1 === preg_match( '/^[a-fA-F0-9]{16,128}$/', $hash );
	}

	/**
	 * Compose the real WordPress header lines stamped into a durable artifact.
	 *
	 * `Version` carries the producing build (the importer version plus the
	 * producing artifact hash prefix) instead of a frozen placeholder, and
	 * `Update URI` identifies the artifact for core's
	 * update_plugins_/update_themes_{$hostname} mechanisms. The update host is
	 * consumer policy (static_site_importer_update_uri_host filter); an empty
	 * filtered host emits no Update URI line at all rather than a malformed
	 * header. An artifact without provenance gets no header lines, preserving
	 * the historical output for older producers.
	 *
	 * @param array<string,mixed> $artifact_provenance Producer artifact provenance record.
	 * @param string              $artifact_slug       Generated artifact slug (theme or plugin slug).
	 * @return array<int,string> Header lines like `Version: 1.11.0+a1b2c3d4`.
	 */
	public static function artifact_header_lines( array $artifact_provenance, string $artifact_slug ): array {
		if ( array() === $artifact_provenance ) {
			return array();
		}

		$lines   = array();
		$lines[] = 'Version: ' . self::artifact_version( $artifact_provenance );

		$slug = strtolower( trim( $artifact_slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		$slug = trim( $slug, '-' );
		$host = self::update_uri_host();
		if ( '' !== $host && '' !== $slug ) {
			$lines[] = 'Update URI: https://' . $host . '/' . $slug;
		}

		return $lines;
	}

	/**
	 * The producing-build version string for a durable artifact header.
	 *
	 * @param array<string,mixed> $artifact_provenance Producer artifact provenance record.
	 * @return string
	 */
	private static function artifact_version( array $artifact_provenance ): string {
		$version = defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? (string) STATIC_SITE_IMPORTER_VERSION : '';
		if ( 1 !== preg_match( '/^[0-9][0-9A-Za-z.\-+]*$/', $version ) ) {
			$version = '0.0.0';
		}
		$hash = strtolower( (string) ( $artifact_provenance['artifact_hash'] ?? '' ) );

		return '' !== $hash ? $version . '+' . substr( $hash, 0, 8 ) : $version;
	}

	/**
	 * Resolve the update host for generated Update URI headers.
	 *
	 * @return string Sanitized hostname, or '' when updates are not addressed.
	 */
	private static function update_uri_host(): string {
		$host = self::DEFAULT_UPDATE_URI_HOST;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the hostname used in generated Update URI headers.
			 *
			 * The host is consumer policy: core fires
			 * update_plugins_{$hostname} / update_themes_{$hostname} for any
			 * non-wordpress.org value. Returning an empty value emits no
			 * Update URI header.
			 *
			 * @param string $host Default neutral, non-routable host.
			 */
			$host = (string) apply_filters( 'static_site_importer_update_uri_host', $host );
		}

		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '~^[a-z][a-z0-9+.-]*://~', '', $host );
		$host = (string) preg_replace( '~[/?#].*$~', '', $host );

		return 1 === preg_match( '/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $host ) ? $host : '';
	}

	/**
	 * Record the composed artifact identity in the site option.
	 *
	 * @param array<string,mixed> $identity Composed artifact identity (describe_artifact()).
	 * @return bool
	 */
	public static function record_artifact_identity( array $identity ): bool {
		if ( self::SCHEMA !== (string) ( $identity['schema'] ?? '' ) ) {
			return false;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $identity ) : json_encode( $identity );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		return function_exists( 'update_option' ) ? update_option( self::ARTIFACT_IDENTITY_OPTION, $encoded, false ) : false;
	}

	/**
	 * Read the composed artifact identity of the most recent import.
	 *
	 * Validated on read the way page provenance is: a record that is absent,
	 * unreadable, or not the current build-provenance schema reads as none.
	 *
	 * @return array<string,mixed> Identity record, or array() when absent or invalid.
	 */
	public static function artifact_identity(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::ARTIFACT_IDENTITY_OPTION, '' ) : '';
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$identity = json_decode( $raw, true );

		return is_array( $identity ) && self::SCHEMA === (string) ( $identity['schema'] ?? '' ) ? $identity : array();
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
