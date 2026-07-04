<?php
/**
 * Site-identity primitive.
 *
 * Single source of truth for deriving a site's { name, slug, title } from an
 * import source. Every consumer (REST playground fallback, theme generator,
 * companion plugin, page materializer) resolves identity through this class so
 * the human-facing name, the theme/plugin slug, and the extracted document
 * title stay consistent instead of drifting toward a generic constant.
 *
 * Deterministic priority:
 * - name/title: explicit site_title/name arg -> source document <title>
 *   (extracted + suffix-stripped) -> source URL host -> generic constant.
 * - slug: explicit slug arg -> sanitize_title(name) -> host -> generic constant.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a canonical { name, slug, title } identity from an import source.
 */
class Static_Site_Importer_Site_Identity {

	/**
	 * Last-resort slug when no usable name, document title, or host is available.
	 */
	public const DEFAULT_SLUG = 'generated-wordpress-website';

	/**
	 * Last-resort human-readable name (untranslated) when nothing else is usable.
	 */
	public const DEFAULT_NAME = 'Generated WordPress Website';

	/**
	 * Resolve a canonical site identity from an import context.
	 *
	 * Recognized context keys (all optional):
	 * - site_title / name / title: explicit, highest-priority human name.
	 * - slug: explicit slug override.
	 * - document_title: a pre-extracted raw document title (suffix-stripped here).
	 * - html: raw HTML to extract a <title> from.
	 * - artifact: a website artifact bundle whose entrypoint <title> is used.
	 * - url / source_url: the source URL, used for the host fallback.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @return array{name:string,slug:string,title:string}
	 */
	public static function resolve( array $context ): array {
		$name = self::resolve_name( $context );
		$slug = self::resolve_slug( $context, $name );

		return array(
			'name'  => $name,
			'slug'  => $slug,
			'title' => $name,
		);
	}

	/**
	 * Resolve the human-readable site name following the canonical priority.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @return string
	 */
	private static function resolve_name( array $context ): string {
		foreach ( array( 'site_title', 'name', 'title' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$explicit = self::sanitize_name( (string) $context[ $key ] );
				if ( '' !== $explicit ) {
					return $explicit;
				}
			}
		}

		$document_title = self::document_title_from_context( $context );
		if ( '' !== $document_title ) {
			return $document_title;
		}

		$host = self::host_name_from_context( $context );
		if ( '' !== $host ) {
			return $host;
		}

		return self::default_name();
	}

	/**
	 * Resolve the slug following the canonical priority.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @param string              $name    Resolved site name.
	 * @return string
	 */
	private static function resolve_slug( array $context, string $name ): string {
		if ( isset( $context['slug'] ) && is_scalar( $context['slug'] ) ) {
			$explicit = self::sanitize_slug( (string) $context['slug'] );
			if ( '' !== $explicit ) {
				return $explicit;
			}
		}

		$from_name = self::sanitize_slug( $name );
		if ( '' !== $from_name ) {
			return $from_name;
		}

		$from_host = self::sanitize_slug( self::host_name_from_context( $context ) );
		if ( '' !== $from_host ) {
			return $from_host;
		}

		return self::DEFAULT_SLUG;
	}

	/**
	 * Produce a collision-free slug by appending -2, -3, ... when taken.
	 *
	 * @param string                $desired  Desired slug.
	 * @param callable(string):bool $is_taken Returns true when a slug is already in use.
	 * @return string
	 */
	public static function unique_slug( string $desired, callable $is_taken ): string {
		$desired = self::sanitize_slug( $desired );
		if ( '' === $desired ) {
			$desired = self::DEFAULT_SLUG;
		}

		if ( ! $is_taken( $desired ) ) {
			return $desired;
		}

		$suffix = 2;
		do {
			$candidate = $desired . '-' . $suffix;
			++$suffix;
		} while ( $is_taken( $candidate ) );

		return $candidate;
	}

	/**
	 * Strip a trailing " — suffix" / " | suffix" / " - suffix" from a title.
	 *
	 * Shared so page titles, theme names, and the REST fallback all collapse
	 * "Maya & Devon — Home" to "Maya & Devon" identically.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public static function strip_title_suffix( string $title ): string {
		$title = trim( $title );
		if ( '' === $title ) {
			return '';
		}

		$parts = preg_split( '/\s+(?:\||\x{2014}|\x{2013}|-)\s+/u', $title );
		$first = is_array( $parts ) ? trim( (string) $parts[0] ) : $title;

		return '' !== $first ? $first : $title;
	}

	/**
	 * Extract a cleaned, suffix-stripped title from a raw HTML document.
	 *
	 * @param string $html HTML document.
	 * @return string
	 */
	public static function title_from_html( string $html ): string {
		if ( '' === trim( $html ) || ! preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $matches ) ) {
			return '';
		}

		return self::clean_title( (string) $matches[1] );
	}

	/**
	 * Extract the entrypoint document title from a website artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact bundle.
	 * @return string
	 */
	public static function title_from_website_artifact( array $artifact ): string {
		$entrypoint = isset( $artifact['entrypoint'] ) && is_scalar( $artifact['entrypoint'] ) ? self::normalize_route_path( (string) $artifact['entrypoint'] ) : '';
		$files      = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? self::normalize_route_path( (string) $file['path'] ) : '';
			if ( '' === $path || ( '' !== $entrypoint && $path !== $entrypoint ) ) {
				continue;
			}

			$content = isset( $file['content'] ) && is_scalar( $file['content'] ) ? (string) $file['content'] : '';
			$title   = self::title_from_html( $content );
			if ( '' !== $title ) {
				return $title;
			}
		}

		return '';
	}

	/**
	 * Resolve the bare host (minus a leading www.) from a source URL.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	public static function host_from_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		if ( function_exists( 'wp_parse_url' ) ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
		} else {
			$parsed = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress wp_parse_url is unavailable in this runtime-free path.
			$host   = is_array( $parsed ) && isset( $parsed['host'] ) ? $parsed['host'] : '';
		}
		$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';
		if ( '' === $host ) {
			return '';
		}

		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * The translated last-resort site name.
	 *
	 * @return string
	 */
	public static function default_name(): string {
		return function_exists( '__' ) ? __( 'Generated WordPress Website', 'static-site-importer' ) : self::DEFAULT_NAME;
	}

	/**
	 * Resolve a document title from the context's document/html/artifact inputs.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @return string
	 */
	private static function document_title_from_context( array $context ): string {
		if ( isset( $context['document_title'] ) && is_scalar( $context['document_title'] ) ) {
			$title = self::clean_title( (string) $context['document_title'] );
			if ( '' !== $title ) {
				return $title;
			}
		}

		if ( isset( $context['html'] ) && is_scalar( $context['html'] ) ) {
			$title = self::title_from_html( (string) $context['html'] );
			if ( '' !== $title ) {
				return $title;
			}
		}

		if ( isset( $context['artifact'] ) && is_array( $context['artifact'] ) ) {
			$title = self::title_from_website_artifact( $context['artifact'] );
			if ( '' !== $title ) {
				return $title;
			}
		}

		return '';
	}

	/**
	 * Resolve the host fallback name from the context's URL inputs.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @return string
	 */
	private static function host_name_from_context( array $context ): string {
		foreach ( array( 'url', 'source_url' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$host = self::host_from_url( (string) $context[ $key ] );
				if ( '' !== $host ) {
					return $host;
				}
			}
		}

		return '';
	}

	/**
	 * Decode, tag-strip, suffix-strip, and sanitize a raw title fragment.
	 *
	 * @param string $raw Raw title fragment.
	 * @return string
	 */
	private static function clean_title( string $raw ): string {
		$title = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : preg_replace( '/<[^>]*>/', '', $raw );
		$title = html_entity_decode( trim( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = self::strip_title_suffix( $title );

		return self::sanitize_name( $title );
	}

	/**
	 * Sanitize a human-readable name.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function sanitize_name( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $name ) : trim( (string) preg_replace( '/<[^>]*>/', '', $name ) );
	}

	/**
	 * Sanitize a slug, with a runtime-independent fallback.
	 *
	 * @param string $value Raw slug source.
	 * @return string
	 */
	private static function sanitize_slug( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'sanitize_title' ) ) {
			$sanitized = sanitize_title( $value );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		}

		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

		return trim( (string) $value, '-' );
	}

	/**
	 * Normalize an artifact route path for entrypoint matching.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private static function normalize_route_path( string $path ): string {
		$path_without_query = strtok( $path, '?' );
		$path               = str_replace( '\\', '/', false === $path_without_query ? $path : $path_without_query );
		$path               = ltrim( $path, '/' );
		$segments           = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}
}
