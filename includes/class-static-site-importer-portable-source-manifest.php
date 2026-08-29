<?php
/**
 * Projects an explicitly inventoried portable website source before compilation.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Static_Site_Importer_Portable_Source_Manifest {
	public const FILENAME = '.static-site-importer-source.json';
	public const SCHEMA   = 'static-site-importer/portable-source/v1';

	/**
	 * @param array<string,mixed> $artifact Normalized website artifact.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function project( array $artifact ) {
		$files   = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$by_path = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = self::path( $file['path'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			if ( isset( $by_path[ $path ] ) ) {
				return self::error( 'static_site_importer_portable_source_duplicate_transport_path', 'Portable source transport paths must be unique.', array( 'path' => $path ) );
			}
			$by_path[ $path ] = $file;
		}

		$manifest_paths = array_values(
			array_filter(
				array_keys( $by_path ),
				static fn( string $path ): bool => self::FILENAME === $path || str_ends_with( $path, '/' . self::FILENAME )
			)
		);
		if ( array() === $manifest_paths ) {
			return $artifact;
		}
		if ( 1 !== count( $manifest_paths ) ) {
			return self::error( 'static_site_importer_portable_source_manifest_ambiguous', 'A portable source transport may contain only one manifest.' );
		}
		$manifest_path = $manifest_paths[0];
		$manifest_base = str_contains( $manifest_path, '/' ) ? dirname( $manifest_path ) : '';

		$manifest_bytes = self::bytes( $by_path[ $manifest_path ] );
		if ( is_wp_error( $manifest_bytes ) ) {
			return $manifest_bytes;
		}
		$manifest = json_decode( $manifest_bytes, true );
		if ( ! is_array( $manifest ) || array_is_list( $manifest ) ) {
			return self::error( 'static_site_importer_portable_source_manifest_invalid', 'The portable source manifest must be a JSON object.' );
		}
		if ( self::SCHEMA !== (string) ( $manifest['schema'] ?? '' ) ) {
			return self::error( 'static_site_importer_portable_source_schema_invalid', 'The portable source manifest schema is unsupported.' );
		}

		$root = self::root( $manifest['root'] ?? '.' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$declared = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();
		if ( array() === $declared || ! array_is_list( $declared ) ) {
			return self::error( 'static_site_importer_portable_source_files_invalid', 'The portable source manifest must declare a non-empty files list.' );
		}

		$projected      = array();
		$declared_paths = array();
		foreach ( $declared as $declaration ) {
			if ( ! is_array( $declaration ) || array_is_list( $declaration ) ) {
				return self::error( 'static_site_importer_portable_source_file_invalid', 'Each portable source file declaration must be an object.' );
			}
			$relative = self::path( $declaration['path'] ?? '' );
			$sha256   = strtolower( trim( (string) ( $declaration['sha256'] ?? '' ) ) );
			if ( '' === $relative || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
				return self::error( 'static_site_importer_portable_source_file_invalid', 'Each portable source file must declare a safe path and SHA-256 hash.' );
			}
			if ( isset( $declared_paths[ $relative ] ) ) {
				return self::error( 'static_site_importer_portable_source_duplicate_file', 'Portable source file declarations must be unique.', array( 'path' => $relative ) );
			}
			$declared_paths[ $relative ] = true;

			$transport_root = implode( '/', array_filter( array( $manifest_base, $root ), static fn( string $part ): bool => '' !== $part ) );
			$transport_path = '' === $transport_root ? $relative : $transport_root . '/' . $relative;
			if ( $manifest_path === $transport_path || ! isset( $by_path[ $transport_path ] ) ) {
				return self::error( 'static_site_importer_portable_source_file_missing', 'A declared portable source file is missing from the transported payload.', array( 'path' => $relative ) );
			}
			$file_hash = self::sha256( $by_path[ $transport_path ] );
			if ( is_wp_error( $file_hash ) ) {
				return $file_hash;
			}
			if ( ! hash_equals( $sha256, $file_hash ) ) {
				return self::error( 'static_site_importer_portable_source_hash_mismatch', 'A portable source file does not match its declared SHA-256 hash.', array( 'path' => $relative ) );
			}
			$file         = $by_path[ $transport_path ];
			$file['path'] = $relative;
			$projected[]  = $file;
		}

		$entrypoint = self::path( $manifest['entrypoint'] ?? '' );
		if ( '' === $entrypoint || ! isset( $declared_paths[ $entrypoint ] ) ) {
			return self::error( 'static_site_importer_portable_source_entrypoint_invalid', 'The portable source entrypoint must name a declared file.' );
		}

		$artifact['entrypoint']               = $entrypoint;
		$artifact['files']                    = $projected;
		$artifact['portable_source_manifest'] = array(
			'schema' => self::SCHEMA,
			'root'   => '' === $root ? '.' : $root,
			'sha256' => hash( 'sha256', $manifest_bytes ),
		);
		return $artifact;
	}

	/** @return string|WP_Error */
	private static function bytes( array $file ) {
		if ( isset( $file['content'] ) && is_scalar( $file['content'] ) ) {
			return (string) $file['content'];
		}
		if ( isset( $file['content_base64'] ) && is_scalar( $file['content_base64'] ) ) {
			$decoded = base64_decode( (string) $file['content_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared transport bytes.
			if ( false !== $decoded ) {
				return $decoded;
			}
		}
		return self::error( 'static_site_importer_portable_source_payload_unreadable', 'A portable source payload could not be read.', array( 'path' => (string) ( $file['path'] ?? '' ) ) );
	}

	/** @return string|WP_Error */
	private static function sha256( array $file ) {
		$bytes = self::bytes( $file );
		if ( ! is_wp_error( $bytes ) ) {
			return hash( 'sha256', $bytes );
		}
		$declared = strtolower( trim( (string) ( $file['raw_sha256'] ?? '' ) ) );
		return preg_match( '/^[a-f0-9]{64}$/', $declared ) ? $declared : $bytes;
	}

	/** @return string|WP_Error */
	private static function root( mixed $value ) {
		$value = trim( str_replace( '\\', '/', (string) $value ) );
		if ( '' === $value || '.' === $value ) {
			return '';
		}
		$path = self::path( $value );
		return '' === $path ? self::error( 'static_site_importer_portable_source_root_invalid', 'The portable source root must be a safe relative directory.' ) : $path;
	}

	private static function path( mixed $value ): string {
		$value = trim( str_replace( '\\', '/', (string) $value ) );
		if ( '' === $value || str_starts_with( $value, '/' ) || preg_match( '/^[a-zA-Z]:\//', $value ) ) {
			return '';
		}
		$parts = explode( '/', $value );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || str_contains( $part, "\0" ) ) {
				return '';
			}
		}
		return implode( '/', $parts );
	}

	private static function error( string $code, string $message, array $data = array() ): WP_Error {
		return new WP_Error( $code, $message, array_merge( array( 'status' => 400 ), $data ) );
	}
}
