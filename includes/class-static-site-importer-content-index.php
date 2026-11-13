<?php
/**
 * Producer content index (`content-index.json`): declared slugs, dates, terms and redirects.
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
if ( ! class_exists( 'Static_Site_Importer_Redirects_Manifest' ) ) {
	require_once __DIR__ . '/class-static-site-importer-redirects-manifest.php';
}

/**
 * Reads a capture producer's content index and declares its facts on the
 * artifact files it names, so the compiler decides post type, slug, dates and
 * terms from the source's own statement instead of HTML clues.
 */
final class Static_Site_Importer_Content_Index {
	/**
	 * Content index contract: `<producer>/content-index/v1`. The producer
	 * namespace is not interpreted, so any capture producer can declare one.
	 */
	public const SCHEMA_PATTERN = '~^[a-z0-9][a-z0-9._-]*/content-index/v1$~';

	/** The index's site path, relative to the website root. */
	public const PATH = 'content-index.json';

	/** Producer bound on terms per post, matching the compiler's declared-content bound. */
	private const MAX_TERMS = 256;

	/**
	 * Remove the content index from the artifact and stamp its declarations on the files it names.
	 *
	 * A root `content-index.json` that does not declare a {@see SCHEMA_PATTERN} schema is an
	 * ordinary site asset and stays in the artifact.
	 *
	 * @param array<string,mixed> $artifact       Website artifact.
	 * @param object|null         $payload_reader Opaque payload reference reader.
	 * @return array{artifact:array<string,mixed>,aliases:array<int,array{from:string,to:string}>,findings:array<int,array<string,mixed>>}|WP_Error
	 */
	public static function extract( array $artifact, ?object $payload_reader = null ) {
		$files = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$index = null;
		$kept  = array();
		foreach ( $files as $file ) {
			if ( null !== $index || ! is_array( $file ) || ! is_scalar( $file['path'] ?? null ) || self::PATH !== Static_Site_Importer_Source_Route::public_source_route( (string) $file['path'] ) ) {
				$kept[] = $file;
				continue;
			}
			$content = Static_Site_Importer_Content_Policy::file_bytes( $file, $payload_reader );
			if ( $content instanceof WP_Error ) {
				return $content;
			}
			$decoded = is_string( $content ) ? json_decode( $content, true ) : null;
			if ( ! is_array( $decoded ) || ! is_string( $decoded['schema'] ?? null ) || 1 !== preg_match( self::SCHEMA_PATTERN, $decoded['schema'] ) ) {
				$kept[] = $file;
				continue;
			}
			foreach ( array( 'posts', 'terms', 'redirects' ) as $key ) {
				if ( array_key_exists( $key, $decoded ) && ! is_array( $decoded[ $key ] ) ) {
					// ssi-error-class: integrity
					return new WP_Error( 'static_site_importer_content_index_invalid', sprintf( 'The content index %s must be a list.', $key ), array( 'path' => (string) $file['path'] ) );
				}
			}
			$index = $decoded;
		}
		if ( null === $index ) {
			return array(
				'artifact' => $artifact,
				'aliases'  => array(),
				'findings' => array(),
			);
		}

		$by_route = array();
		foreach ( $kept as $position => $file ) {
			if ( is_array( $file ) && is_scalar( $file['path'] ?? null ) ) {
				$by_route[ Static_Site_Importer_Source_Route::public_source_route( (string) $file['path'] ) ] = $position;
			}
		}
		$rules    = array();
		$claimed  = array();
		$findings = array();
		foreach ( $index['posts'] ?? array() as $post ) {
			$position = is_array( $post ) ? self::file_position( $post['path'] ?? null, $by_route ) : null;
			if ( null === $position || ! in_array( $post['kind'] ?? null, array( 'post', 'page' ), true ) ) {
				continue;
			}
			$source = self::url_path( $post['permalink'] ?? null );
			$route  = self::canonical_route( $source );
			if ( ! self::claim_route( $route, (string) $kept[ $position ]['path'], $claimed, $findings ) ) {
				continue;
			}
			$declared = array();
			foreach ( array( 'slug', 'published', 'updated', 'author' ) as $key ) {
				if ( is_string( $post[ $key ] ?? null ) && '' !== trim( $post[ $key ] ) ) {
					$declared[ $key ] = trim( $post[ $key ] );
				}
			}
			if ( is_array( $post['terms'] ?? null ) ) {
				$terms = array_values( array_filter( $post['terms'], static fn( mixed $term ): bool => is_string( $term ) && '' !== trim( $term ) ) );
				if ( array() !== $terms ) {
					$declared['terms'] = array_slice( $terms, 0, self::MAX_TERMS );
				}
			}
			$metadata = array( 'post_type' => $post['kind'] );
			if ( '' !== $route ) {
				$metadata['route_path'] = $route;
				if ( self::differs( $source, $route, (string) $post['path'] ) ) {
					$rules[] = $source . ' /' . Static_Site_Importer_Source_Route::public_source_route( (string) $post['path'] );
				}
			}
			if ( array() !== $declared ) {
				$metadata['declared_content'] = $declared;
			}
			$kept[ $position ]['metadata'] = array_merge( is_array( $kept[ $position ]['metadata'] ?? null ) ? $kept[ $position ]['metadata'] : array(), $metadata );
		}
		foreach ( $index['terms'] ?? array() as $term ) {
			$position = is_array( $term ) && 'term-archive' === ( $term['kind'] ?? null ) && is_string( $term['name'] ?? null ) && '' !== trim( $term['name'] ) ? self::file_position( $term['path'] ?? null, $by_route ) : null;
			if ( null === $position ) {
				continue;
			}
			$source = self::url_path( $term['sourceUrl'] ?? null );
			$route  = self::canonical_route( $source );
			if ( ! self::claim_route( $route, (string) $kept[ $position ]['path'], $claimed, $findings ) ) {
				continue;
			}
			$metadata = array( 'declared_content' => array( 'term_archive' => array( 'name' => trim( $term['name'] ) ) ) );
			if ( '' !== $route ) {
				$metadata['route_path'] = $route;
				if ( self::differs( $source, $route, (string) $term['path'] ) ) {
					$rules[] = $source . ' /' . Static_Site_Importer_Source_Route::public_source_route( (string) $term['path'] );
				}
			}
			$kept[ $position ]['metadata'] = array_merge( is_array( $kept[ $position ]['metadata'] ?? null ) ? $kept[ $position ]['metadata'] : array(), $metadata );
		}
		foreach ( $index['redirects'] ?? array() as $redirect ) {
			$from = is_array( $redirect ) ? self::url_path( $redirect['from'] ?? null ) : '';
			$to   = is_array( $redirect ) ? self::url_path( $redirect['to'] ?? null ) : '';
			if ( '' !== $from && '' !== $to ) {
				$rules[] = $from . ' ' . $to;
			}
		}
		$artifact['files'] = array_values( $kept );

		return array(
			'artifact' => $artifact,
			// Same-site, de-duplicated and self-redirect-free, like `_redirects`.
			'aliases'  => Static_Site_Importer_Redirects_Manifest::parse( implode( "\n", $rules ) ),
			'findings' => $findings,
		);
	}

	/**
	 * Append index aliases whose source route no other rule already claims.
	 *
	 * @param array<int,mixed>                        $aliases Existing alias rules, kept as given.
	 * @param array<int,array{from:string,to:string}> $index   Content index alias rules.
	 * @return array<int,mixed>
	 */
	public static function append_aliases( array $aliases, array $index ): array {
		$claimed = array();
		foreach ( $aliases as $alias ) {
			if ( is_array( $alias ) && is_string( $alias['from'] ?? null ) ) {
				$claimed[ $alias['from'] ] = true;
			}
		}
		foreach ( $index as $alias ) {
			if ( ! isset( $claimed[ $alias['from'] ] ) ) {
				$claimed[ $alias['from'] ] = true;
				$aliases[]                 = $alias;
			}
		}
		return $aliases;
	}

	/**
	 * Posts precede terms; the first declaration of a canonical route wins.
	 * A skipped entry must not stamp content metadata or add an alias either.
	 *
	 * @param string                          $route    Canonical route, or '' when absent.
	 * @param string                          $path     Artifact file path.
	 * @param array<string,string>            $claimed  Route owners.
	 * @param array<int,array<string,mixed>>   $findings Intake warnings.
	 */
	private static function claim_route( string $route, string $path, array &$claimed, array &$findings ): bool {
		if ( '' === $route ) {
			return true;
		}
		if ( isset( $claimed[ $route ] ) ) {
			$findings[] = array(
				'code'        => 'content_index_route_skipped',
				'type'        => 'static-site-importer',
				'severity'    => 'warning',
				'stage'       => 'content_index',
				'source_path' => $path,
				'path'        => $path,
				'reason_code' => 'duplicate_canonical_route',
				'message'     => sprintf( 'Skipped content-index declaration for %1$s: route %2$s is already claimed by %3$s; the file keeps its file-derived route.', $path, $route, $claimed[ $route ] ),
			);
			return false;
		}
		$claimed[ $route ] = $path;
		return true;
	}

	/** @param array<string,int> $by_route */
	private static function file_position( mixed $path, array $by_route ): ?int {
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		$route = Static_Site_Importer_Source_Route::public_source_route( $path );
		return '' !== $route && isset( $by_route[ $route ] ) ? $by_route[ $route ] : null;
	}

	/** The path of an absolute URL or a site path, or '' when it has none. */
	private static function url_path( mixed $url ): string {
		if ( ! is_string( $url ) || '' === trim( $url ) || preg_match( '/\s/', trim( $url ) ) ) {
			return '';
		}
		$path = wp_parse_url( trim( $url ), PHP_URL_PATH );
		return is_string( $path ) && str_starts_with( $path, '/' ) && ! str_starts_with( $path, '//' ) ? $path : '';
	}

	/**
	 * A source URL path as a canonical plan route: lowercase `[a-z0-9-]`
	 * segments without a document extension, as WordPress slugs are spelled.
	 */
	private static function canonical_route( string $path ): string {
		$path     = (string) preg_replace( '/\.html?$/i', '', $path );
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$segment = rawurldecode( $segment );
			if ( function_exists( 'remove_accents' ) ) {
				$segment = remove_accents( $segment );
			}
			$segment = trim( substr( trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $segment ) ), '-' ), 0, 200 ), '-' );
			if ( '' !== $segment ) {
				$segments[] = $segment;
			}
		}
		return array() === $segments ? '' : '/' . implode( '/', $segments );
	}

	/**
	 * Whether the source address is served by neither the canonical route
	 * nor the artifact file (`/search/label/Road%20Trips`), so it stays an alias.
	 */
	private static function differs( string $source, string $route, string $file ): bool {
		$public = Static_Site_Importer_Source_Route::public_source_route( $source );
		return ltrim( $route, '/' ) !== $public && Static_Site_Importer_Source_Route::public_source_route( $file ) !== $public;
	}
}
