<?php
/**
 * Site-identity primitive.
 *
 * Single source of truth for deriving a site's { name, slug, title } from an
 * import source. Every consumer (REST adapter, theme generator,
 * companion plugin, page materializer) resolves identity through this class so
 * the human-facing name, the theme/plugin slug, and the extracted document
 * title stay consistent instead of drifting toward a generic constant.
 *
 * Deterministic priority:
 * - name/title: explicit theme name -> explicit site_title -> source document <title>
 *   (extracted + suffix-stripped) -> source URL host -> generic constant.
 * - slug: explicit slug arg -> sanitize_title(name) -> host -> generic constant.
 * - block namespace: ssi-<slug>, filtered, sanitized, and never `core`.
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
	 * - name: explicit theme name override.
	 * - site_title / title: site identity used when no theme name is supplied.
	 * - slug: explicit slug override.
	 * - document_title: a pre-extracted raw document title (suffix-stripped here).
	 * - html: raw HTML to extract a <title> from.
	 * - artifact: a website artifact bundle whose entrypoint <title> is used.
	 * - plan: a canonical site plan whose entrypoint document title is used.
	 * - payload_reader: a transient reader for a reference-backed entry document.
	 * - url / source_url: the source URL, used for the host fallback.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @return array{name:string,slug:string,title:string,block_namespace:string}
	 */
	public static function resolve( array $context ): array {
		$name = self::resolve_name( $context );
		/**
		 * Filters the human-readable generated theme name.
		 *
		 * @param string              $name    Resolved theme name.
		 * @param array<string,mixed> $context Import identity context.
		 */
		$name = self::sanitize_name( (string) ( function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_theme_name', $name, $context ) : $name ) );
		if ( '' === $name ) {
			$name = self::default_name();
		}
		$slug = self::resolve_slug( $context, $name );
		/**
		 * Filters the generated theme's filesystem slug.
		 *
		 * @param string              $slug    Resolved theme slug.
		 * @param string              $name    Filtered theme name.
		 * @param array<string,mixed> $context Import identity context.
		 */
		$slug = self::sanitize_slug( (string) ( function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_theme_slug', $slug, $name, $context ) : $slug ) );
		if ( '' === $slug ) {
			$slug = self::DEFAULT_SLUG;
		}

		$block_namespace = 'ssi-' . $slug;
		/**
		 * Filters the consumer-owned block namespace for generated blocks.
		 *
		 * The resolved namespace is supplied to the compiler as the artifact's
		 * block_namespace input, so generated blocks and the companion scaffold
		 * agree on one namespace by construction. The value is sanitized to
		 * ^[a-z][a-z0-9-]*$ and must not be `core`; an empty or invalid value
		 * falls back to the default `ssi-<slug>` namespace.
		 *
		 * @param string              $block_namespace Default block namespace (ssi-<slug>).
		 * @param string              $slug    Filtered theme slug.
		 * @param array<string,mixed> $context Import identity context.
		 */
		$block_namespace = self::sanitize_block_namespace( (string) ( function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_block_namespace', $block_namespace, $slug, $context ) : $block_namespace ) );
		if ( '' === $block_namespace ) {
			$block_namespace = 'ssi-' . $slug;
		}

		return array(
			'name'            => $name,
			'slug'            => $slug,
			'title'           => $name,
			'block_namespace' => $block_namespace,
		);
	}

	/**
	 * Resolve the human-readable site name following the canonical priority.
	 *
	 * @param array<string,mixed> $context Identity resolution context.
	 * @return string
	 */
	private static function resolve_name( array $context ): string {
		foreach ( array( 'name', 'site_title', 'title' ) as $key ) {
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
	public static function title_from_website_artifact( array $artifact, ?object $payload_reader = null ): string {
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
			if ( '' === $content && is_string( $file['content_base64'] ?? null ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes static HTML transport solely to read its document title.
				$decoded = base64_decode( $file['content_base64'], true );
				$content = is_string( $decoded ) ? $decoded : '';
			}
			if ( '' === $content && is_array( $file['payload_reference'] ?? null ) && is_object( $payload_reader ) && is_callable( array( $payload_reader, 'read' ) ) ) {
				$reference = $file['payload_reference'];
				try {
					$bytes = $payload_reader->read( $reference );
				} catch ( Throwable $error ) {
					$bytes = null;
				}
				if ( is_string( $bytes ) && strlen( $bytes ) === ( $reference['bytes'] ?? null ) && hash_equals( (string) ( $reference['sha256'] ?? '' ), hash( 'sha256', $bytes ) ) ) {
					$content = $bytes;
				}
			}
			$title = self::title_from_html( $content );
			if ( '' !== $title ) {
				return $title;
			}
		}

		return '';
	}

	/** Extract explicit native branding evidence from the ordinary entrypoint document. */
	public static function evidence_from_website_artifact( array $artifact, ?object $payload_reader = null, string $site_tagline = '' ): array {
		$entrypoint = isset( $artifact['entrypoint'] ) ? self::normalize_route_path( (string) $artifact['entrypoint'] ) : '';
		$html       = '';
		foreach ( $artifact['files'] ?? array() as $file ) {
			if ( ! is_array( $file ) || self::normalize_route_path( (string) ( $file['path'] ?? '' ) ) !== $entrypoint ) {
				continue;
			}
			$html = self::artifact_file_content( $file, $payload_reader );
			break;
		}
		$evidence = array(
			'tagline'  => trim( $site_tagline ),
			'logo'     => null,
			'icon'     => null,
			'manifest' => null,
			'status'   => 'entrypoint_missing',
		);
		if ( '' === $html ) {
			return $evidence;
		}
		$evidence['status'] = 'explicit_evidence';
		$json_bytes         = 0;
		$json_count         = 0;
		if ( preg_match_all( '~<script\b([^>]*)>(.*?)</script\s*>~is', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $script ) {
				$attributes = self::evidence_attributes( $script[1] );
				if ( 'application/ld+json' !== strtolower( trim( $attributes['type'] ?? '' ) ) ) {
					continue;
				}
				$json_bytes += strlen( $script[2] );
				if ( ++$json_count > 64 || $json_bytes > 256 * 1024 ) {
					$evidence['truncated'] = true;
					break;
				}
				$data = json_decode( $script[2], true );
				if ( ! is_array( $data ) ) {
					$evidence['invalid_jsonld'] = true;
					continue;
				}
				foreach ( self::jsonld_nodes( $data ) as $node ) {
					$type = $node['@type'] ?? array();
					$type = array_filter( is_array( $type ) ? $type : array( $type ), 'is_string' );
					if ( array_intersect( array( 'Organization', 'WebSite' ), $type ) ) {
						if ( empty( $evidence['logo'] ) && isset( $node['logo'] ) ) {
							$evidence['logo'] = self::logo_reference( $node['logo'] );
							if ( null === $evidence['logo'] ) {
								$evidence['logo_status'] = 'unsupported_evidence';
							}
						}
						if ( '' === $evidence['tagline'] && is_string( $node['slogan'] ?? null ) ) {
							$evidence['tagline'] = trim( $node['slogan'] );
						}
					}
				}
			}
		}
		if ( preg_match_all( '~<link\\b([^>]+)>~i', $html, $links ) ) {
			foreach ( $links[1] as $attributes ) {
				$attrs = self::evidence_attributes( $attributes );
				$rels  = preg_split( '/\\s+/', strtolower( trim( $attrs['rel'] ?? '' ) ) );
				$rels  = false === $rels ? array() : $rels;
				if ( in_array( 'manifest', $rels, true ) ) {
					$evidence['manifest'] = $attrs['href'] ?? null;
				}
				if ( in_array( 'icon', $rels, true ) || in_array( 'apple-touch-icon', $rels, true ) ) {
					$evidence['icon'] = $evidence['icon'] ?? ( $attrs['href'] ?? null );
				}
			}
		}
		$evidence['logo_source_path'] = self::evidence_asset_path( (string) ( $evidence['logo'] ?? '' ), $entrypoint, $artifact );
		$evidence['icon_source_path'] = self::evidence_asset_path( (string) ( $evidence['icon'] ?? '' ), $entrypoint, $artifact );
		$manifest_path                = self::evidence_asset_path( (string) ( $evidence['manifest'] ?? '' ), $entrypoint, $artifact );
		if ( null !== $manifest_path ) {
			foreach ( $artifact['files'] ?? array() as $file ) {
				if ( ! is_array( $file ) || ( $file['path'] ?? '' ) !== $manifest_path ) {
					continue;
				}
				$content  = self::artifact_file_content( $file, $payload_reader );
				$manifest = strlen( $content ) <= 256 * 1024 ? json_decode( $content, true ) : null;
				$icons    = is_array( $manifest['icons'] ?? null ) ? array_slice( $manifest['icons'], 0, 64 ) : array();
				usort( $icons, static fn( $left, $right ): int => self::icon_size( $right ) <=> self::icon_size( $left ) );
				foreach ( $icons as $icon ) {
					if ( ! is_array( $icon ) || ! is_string( $icon['src'] ?? null ) || null !== $evidence['icon'] ) {
						continue;
					}
					$evidence['icon']             = $icon['src'];
					$evidence['icon_source_path'] = self::evidence_asset_path( $icon['src'], $manifest_path, $artifact );
					$evidence['icon_source']      = 'web_app_manifest';
				}
				$evidence['manifest_status'] = is_array( $manifest ) ? 'parsed' : 'invalid';
				break;
			}
		}
		if ( ! empty( $evidence['manifest'] ) && ! isset( $evidence['manifest_status'] ) ) {
			$evidence['manifest_status'] = 'unresolved';
		}
		return $evidence;
	}

	/** Parse source declarations, including quoted and unquoted HTML attributes. */
	private static function evidence_attributes( string $source ): array {
		$attributes = array();
		preg_match_all( '~([a-zA-Z_:][\w:.-]*)\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s"\x27=<>`]+))~', $source, $pairs, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
		foreach ( $pairs as $pair ) {
			$attributes[ strtolower( $pair[1] ) ] = html_entity_decode( (string) ( $pair[2] ?? $pair[3] ?? $pair[4] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $attributes;
	}

	private static function icon_size( mixed $icon ): int {
		if ( ! is_array( $icon ) || ! is_string( $icon['sizes'] ?? null ) ) {
			return 0;
		}
		preg_match_all( '/\b(\d+)x(\d+)\b/', $icon['sizes'], $sizes, PREG_SET_ORDER );
		return array_reduce( $sizes, static fn( int $largest, array $size ): int => max( $largest, min( 4096, (int) $size[1] ) * min( 4096, (int) $size[2] ) ), 0 );
	}

	/** Prefer the ImageObject's media bytes over its descriptive page URL. */
	private static function logo_reference( mixed $value, int $depth = 0 ): ?string {
		if ( $depth > 4 ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value ) ? trim( $value ) : null;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		foreach ( array( 'contentUrl', 'url' ) as $key ) {
			if ( is_string( $value[ $key ] ?? null ) ) {
				return self::logo_reference( $value[ $key ], $depth + 1 );
			}
		}
		if ( array_is_list( $value ) ) {
			foreach ( array_slice( $value, 0, 64 ) as $image ) {
				$reference = self::logo_reference( $image, $depth + 1 );
				if ( null !== $reference ) {
					return $reference;
				}
			}
		}
		return null;
	}

	/** Resolve a portable URI inside its declared artifact root, never onto the network. */
	public static function evidence_asset_path( string $reference, string $document, array $artifact ): ?string {
		if ( '' === trim( $reference ) || str_starts_with( $reference, '//' ) || preg_match( '~^[a-z][a-z0-9+.-]*:~i', $reference ) ) {
			return null;
		}
		$root  = trim( (string) ( $artifact['root'] ?? dirname( (string) ( $artifact['entrypoint'] ?? $document ) ) ), '/' );
		$root  = '.' === $root ? '' : $root;
		$path  = rawurldecode( (string) wp_parse_url( $reference, PHP_URL_PATH ) );
		$path  = str_starts_with( $path, '/' ) ? $root . $path : dirname( $document ) . '/' . $path;
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( array() === $parts ) {
					return null;
				}
				array_pop( $parts );
			} else {
				$parts[] = $part;
			}
		}
		$path = implode( '/', $parts );
		return '' !== $root && ! str_starts_with( $path, $root . '/' ) ? null : $path;
	}

	/** @return array<int,array<string,mixed>> */
	private static function jsonld_nodes( array $data ): array {
		$nodes   = array();
		$pending = array( array( $data, 0 ) );
		$visited = 0;
		while ( $pending && ++$visited <= 512 ) {
			list( $node, $depth ) = array_pop( $pending );
			if ( isset( $node['@type'] ) ) {
				$nodes[] = $node;
			}
			if ( $depth >= 8 ) {
				continue;
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$pending[] = array( $child, $depth + 1 );
				}
			}
		}
		return $nodes;
	}

	/** Read inline, base64, or verified reference-backed artifact content. */
	private static function artifact_file_content( array $file, ?object $payload_reader ): string {
		$content = is_scalar( $file['content'] ?? null ) ? (string) $file['content'] : '';
		if ( 'base64' === ( $file['encoding'] ?? '' ) ) {
			$decoded = base64_decode( $content, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Declared artifact transport.
			$content = is_string( $decoded ) ? $decoded : '';
		}
		if ( '' === $content && is_string( $file['content_base64'] ?? null ) ) {
			$decoded = base64_decode( $file['content_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Declared artifact transport.
			$content = is_string( $decoded ) ? $decoded : '';
		}
		$reference = $file['payload_reference'] ?? null;
		if ( '' === $content && is_array( $reference ) && is_object( $payload_reader ) && is_callable( array( $payload_reader, 'read' ) ) ) {
			try {
				$bytes = $payload_reader->read( $reference );
			} catch ( Throwable $error ) {
				$bytes = null;
			}
			if ( is_string( $bytes ) && strlen( $bytes ) === ( $reference['bytes'] ?? null ) && hash_equals( (string) ( $reference['sha256'] ?? '' ), hash( 'sha256', $bytes ) ) ) {
				$content = $bytes;
			}
		}
		return $content;
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
			$title = self::title_from_website_artifact( $context['artifact'], is_object( $context['payload_reader'] ?? null ) ? $context['payload_reader'] : null );
			if ( '' !== $title ) {
				return $title;
			}
		}

		foreach ( $context['plan']['pages'] ?? array() as $page ) {
			if ( ! empty( $page['entrypoint'] ) ) {
				$title = self::clean_title( (string) ( $page['document_metadata']['title'] ?? $page['title'] ?? '' ) );
				if ( '' !== $title ) {
					return $title;
				}
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
	 * Sanitize a block namespace, with a runtime-independent fallback.
	 *
	 * Returns '' when the value cannot become a valid WordPress block namespace
	 * (`^[a-z][a-z0-9-]*$`) or claims the reserved `core` namespace, so callers
	 * can fall back to their default.
	 *
	 * @param string $value Raw namespace.
	 * @return string Sanitized namespace, or '' when unusable.
	 */
	private static function sanitize_block_namespace( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = (string) preg_replace( '/[^a-z0-9-]+/', '-', $value );
		$value = trim( $value, '-' );

		if ( '' === $value || 'core' === $value || 1 !== preg_match( '/^[a-z][a-z0-9-]*$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Normalize a route-like artifact path without resolving outside its root.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public static function normalize_route_path( string $path ): string {
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
