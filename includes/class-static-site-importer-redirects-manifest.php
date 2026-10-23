<?php
/**
 * Netlify `_redirects` subset used as import-time source-route aliases.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Content_Policy' ) ) {
	require_once __DIR__ . '/class-static-site-importer-content-policy.php';
}
if ( ! class_exists( 'Static_Site_Importer_Source_Route' ) ) {
	require_once __DIR__ . '/class-static-site-importer-source-route.php';
}

final class Static_Site_Importer_Redirects_Manifest {
	/**
	 * Remove a root `_redirects` file from an artifact and return its aliases.
	 *
	 * @param array<string,mixed> $artifact Website artifact.
	 * @param object|null         $payload_reader Opaque payload reference reader.
	 * @return array{artifact:array<string,mixed>,aliases:array<int,array{from:string,to:string}>}|WP_Error
	 */
	public static function extract( array $artifact, ?object $payload_reader = null ) {
		$files   = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$kept    = array();
		$aliases = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				$kept[] = $file;
				continue;
			}
			$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '';
			if ( ! Static_Site_Importer_Content_Policy::is_redirects_manifest_path( $path ) ) {
				$kept[] = $file;
				continue;
			}
			// Content_Policy::admit_artifact() decided this manifest's size,
			// readability and content before the artifact reached extraction; a
			// reference is re-read against the digest that decision verified.
			$content = Static_Site_Importer_Content_Policy::file_bytes( $file, $payload_reader );
			if ( $content instanceof WP_Error ) {
				return $content;
			}
			$aliases = array_merge( $aliases, self::parse( (string) $content ) );
		}
		$artifact['files'] = $kept;

		return array(
			'artifact' => $artifact,
			'aliases'  => $aliases,
		);
	}

	/**
	 * @return array<int,array{from:string,to:string}>
	 */
	public static function parse( string $content ): array {
		$rules     = array();
		$seen_from = array();
		$lines     = preg_split( '/\R/', $content );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = trim( substr( $line, 0, $hash ) );
				if ( '' === $line ) {
					continue;
				}
			}
			$parts = preg_split( '/\s+/', $line );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}
			$from   = $parts[0];
			$to     = $parts[1];
			$status = $parts[2] ?? '301';
			if ( ! in_array( $status, array( '301', '302' ), true ) || ! self::is_same_site_path( $from ) || ! self::is_same_site_path( $to ) ) {
				continue;
			}
			$from_route = Static_Site_Importer_Source_Route::public_source_route( $from );
			$to_route   = Static_Site_Importer_Source_Route::public_source_route( $to );
			if ( '' === $from_route || '' === $to_route || $from_route === $to_route || isset( $seen_from[ $from_route ] ) ) {
				continue;
			}
			$seen_from[ $from_route ] = true;
			$rules[]                  = array(
				'from' => $from_route,
				'to'   => $to_route,
			);
		}

		return $rules;
	}

	/**
	 * @param array<int,array{from:string,to:string}> $rules
	 * @param array<int,string>                       $source_paths
	 * @return array<string,array<int,string>>
	 */
	public static function aliases_for_source_paths( array $rules, array $source_paths ): array {
		$targets = array();
		$primary = array();
		foreach ( $source_paths as $source_path ) {
			if ( '' === $source_path ) {
				continue;
			}
			$public = Static_Site_Importer_Source_Route::public_source_route( $source_path );
			if ( '' === $public ) {
				continue;
			}
			$primary[ $public ] = true;
			$targets[ $public ] = $source_path;
			$without_index      = (string) preg_replace( '#/index\.html?$#i', '', $public );
			if ( $without_index !== $public && '' !== $without_index ) {
				$targets[ $without_index ] = $source_path;
			}
		}
		$aliases = array();
		foreach ( $rules as $rule ) {
			$from = $rule['from'];
			$to   = $rule['to'];
			if ( '' === $from || '' === $to || isset( $primary[ $from ] ) || ! isset( $targets[ $to ] ) ) {
				continue;
			}
			$source_path = $targets[ $to ];
			if ( Static_Site_Importer_Source_Route::public_source_route( $source_path ) === $from ) {
				continue;
			}
			$aliases[ $source_path ][] = $from;
		}

		return $aliases;
	}

	private static function is_same_site_path( string $path ): bool {
		return str_starts_with( $path, '/' ) && ! str_starts_with( $path, '//' ) && ! str_contains( $path, '://' ) && ! str_contains( $path, '*' ) && ! str_contains( $path, '?' ) && ! str_contains( $path, ':' );
	}
}
