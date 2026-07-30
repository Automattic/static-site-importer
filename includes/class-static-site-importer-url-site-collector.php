<?php
/**
 * Bounded public static-site collection.
 *
 * @package StaticSiteImporter
 */

if ( ! class_exists( 'Static_Site_Importer_Source_Normalizer' ) ) {
	require_once __DIR__ . '/class-static-site-importer-source-normalizer.php';
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects server-rendered pages and their referenced assets into one artifact.
 */
class Static_Site_Importer_URL_Site_Collector {

	private const DEFAULT_MAX_PAGES       = 20;
	private const DEFAULT_MAX_ASSETS      = 200;
	private const DEFAULT_MAX_TOTAL_BYTES = 52428800;
	private const MAX_PAGES               = 100;
	private const MAX_ASSETS              = 500;
	private const MAX_TOTAL_BYTES         = 104857600;
	private const MAX_RESPONSE_BYTES      = 10485760;

	/**
	 * Collect a public static site.
	 *
	 * @param string        $url     Entry URL.
	 * @param array         $args    Collection and fetch limits.
	 * @param callable|null $fetcher Optional test/provider fetch callback.
	 * @return array{provider:string,artifact:array<string,mixed>,source_metadata:array<string,mixed>}|WP_Error
	 */
	public static function collect( string $url, array $args = array(), ?callable $fetcher = null ) {
		$entry_url = self::canonical_url( Static_Site_Importer_URL_Fetcher::normalize_url( $url ) );
		if ( '' === $entry_url ) {
			return new WP_Error( 'static_site_importer_site_collection_invalid_url', 'Enter a valid public site URL.' );
		}

		$max_pages       = min( self::MAX_PAGES, max( 1, (int) ( $args['max_pages'] ?? self::DEFAULT_MAX_PAGES ) ) );
		$max_assets      = min( self::MAX_ASSETS, max( 0, (int) ( $args['max_assets'] ?? self::DEFAULT_MAX_ASSETS ) ) );
		$max_total_bytes = min( self::MAX_TOTAL_BYTES, max( 1, (int) ( $args['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES ) ) );
		$request_delay   = min( 2000, max( 0, (int) ( $args['request_delay_ms'] ?? 100 ) ) );
		$fetcher    = $fetcher ?? static fn ( string $resource_url, array $fetch_args ) => Static_Site_Importer_URL_Fetcher::fetch( $resource_url, $fetch_args );
		$fetch_args = array_intersect_key( $args, array_flip( array( 'timeout' ) ) );
		$fetch_args['max_bytes'] = min( self::MAX_RESPONSE_BYTES, $max_total_bytes, max( 1, (int) ( $args['max_bytes'] ?? 5242880 ) ) );

		$page_queue       = array( $entry_url );
		$asset_queue      = array();
		$queued_pages     = array( self::page_key( $entry_url ) => true );
		$queued_assets    = array();
		$resources        = array();
		$failures         = array();
		$diagnostics      = array();
		$source_exclusions = array();
		$aliases           = array();
		$total_bytes       = 0;
		$truncated         = array();
		$entry_resource_url = $entry_url;
		$site_url           = $entry_url;

		$sitemap_urls = self::sitemap_urls( $entry_url, $fetcher, $fetch_args );
		foreach ( $sitemap_urls as $page_url ) {
			if ( count( $page_queue ) >= $max_pages ) {
				$truncated['pages'] = true;
				break;
			}
			$page_key = self::page_key( $page_url );
			if ( ! isset( $queued_pages[ $page_key ] ) ) {
				$queued_pages[ $page_key ] = true;
				$page_queue[]              = $page_url;
			}
		}

		while ( $page_queue && count( array_filter( $resources, static fn ( array $resource ): bool => 'html' === $resource['kind'] ) ) < $max_pages ) {
			$page_url = array_shift( $page_queue );
			$response = $fetcher( $page_url, array_merge( $fetch_args, array( 'content_types' => array( 'text/html', 'application/xhtml+xml' ) ) ) );
			if ( is_wp_error( $response ) ) {
				if ( $page_url === $entry_url ) {
					return $response;
				}
				$failures[] = self::failure( $page_url, $response );
				continue;
			}

			$final_url = self::response_url( $response, $page_url );
			if ( $page_url === $entry_url ) {
				$entry_resource_url = $final_url;
				$site_url           = $final_url;
			}
			if ( $final_url !== $page_url ) {
				$aliases[ $page_url ] = $final_url;
			}
			if ( isset( $resources[ $final_url ] ) ) {
				continue;
			}

			$body              = (string) $response['body'];
			$normalized        = Static_Site_Importer_Source_Normalizer::normalize_html( $body, $final_url, $args );
			$body              = $normalized['html'];
			$source_exclusions = array_merge( $source_exclusions, $normalized['exclusions'] );
			$diagnostics       = array_merge( $diagnostics, $normalized['diagnostics'] );
			$bytes             = strlen( $body );
			if ( $total_bytes + $bytes > $max_total_bytes ) {
				$truncated['bytes'] = true;
				break;
			}

			$diagnostic = Static_Site_Importer_URL_Fetcher::html_source_diagnostic( $body );
			if ( ! empty( $diagnostic ) && 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				$error = new WP_Error( 'static_site_importer_url_client_rendered_app', (string) $diagnostic['message'], array( 'diagnostic' => $diagnostic ) );
				if ( $page_url === $entry_url ) {
					return $error;
				}
				$diagnostic['severity']    = 'warning';
				$diagnostic['url']         = $page_url;
				$diagnostic['disposition'] = 'collected_static_html';
				$diagnostics[]              = $diagnostic;
			}

			$total_bytes           += $bytes;
			$resources[ $final_url ] = array(
				'kind'         => 'html',
				'body'         => $body,
				'content_type' => self::content_type( $response, 'text/html' ),
			);

			$document_base_url = self::html_base_url( $body, $final_url );
			foreach ( self::html_page_urls( $body, $document_base_url, $site_url ) as $discovered_url ) {
				$page_key = self::page_key( $discovered_url );
				if ( isset( $queued_pages[ $page_key ] ) ) {
					continue;
				}
				if ( count( $page_queue ) + self::resource_count( $resources, 'html' ) >= $max_pages ) {
					$truncated['pages'] = true;
					break;
				}
				$queued_pages[ $page_key ] = true;
				$page_queue[]              = $discovered_url;
			}

			$include_scripts = ! array_key_exists( 'include_scripts', $args ) || (bool) $args['include_scripts'];
			foreach ( self::html_asset_urls( $body, $document_base_url, $include_scripts ) as $asset_url ) {
				if ( isset( $queued_assets[ $asset_url ] ) || isset( $resources[ $asset_url ] ) ) {
					continue;
				}
				if ( count( $asset_queue ) + self::resource_count( $resources, 'asset' ) >= $max_assets ) {
					$truncated['assets'] = true;
					break;
				}
				$queued_assets[ $asset_url ] = true;
				$asset_queue[]                 = $asset_url;
			}

			self::delay( $request_delay );
		}

		while ( $asset_queue && self::resource_count( $resources, 'asset' ) < $max_assets ) {
			$asset_url = array_shift( $asset_queue );
			$response  = $fetcher( $asset_url, array_merge( $fetch_args, array( 'content_types' => array() ) ) );
			if ( is_wp_error( $response ) ) {
				$failures[] = self::failure( $asset_url, $response );
				continue;
			}

			$final_url = self::response_url( $response, $asset_url );
			if ( $final_url !== $asset_url ) {
				$aliases[ $asset_url ] = $final_url;
			}
			if ( isset( $resources[ $final_url ] ) ) {
				continue;
			}

			$body  = (string) $response['body'];
			$bytes = strlen( $body );
			if ( $total_bytes + $bytes > $max_total_bytes ) {
				$truncated['bytes'] = true;
				break;
			}

			$content_type            = self::content_type( $response, 'application/octet-stream' );
			$total_bytes            += $bytes;
			$resources[ $final_url ] = array(
				'kind'         => 'asset',
				'body'         => $body,
				'content_type' => $content_type,
			);

			if ( 'text/css' === $content_type || str_ends_with( strtolower( (string) parse_url( $final_url, PHP_URL_PATH ) ), '.css' ) ) {
				foreach ( self::css_asset_urls( $body, $final_url ) as $nested_url ) {
					if ( isset( $queued_assets[ $nested_url ] ) || isset( $resources[ $nested_url ] ) ) {
						continue;
					}
					if ( count( $asset_queue ) + self::resource_count( $resources, 'asset' ) >= $max_assets ) {
						$truncated['assets'] = true;
						break;
					}
					$queued_assets[ $nested_url ] = true;
					$asset_queue[]                  = $nested_url;
				}
			}

			self::delay( $request_delay );
		}

		$paths           = self::artifact_paths( $resources, $site_url );
		$reference_paths = $paths;
		foreach ( $aliases as $requested_url => $final_url ) {
			if ( isset( $paths[ $final_url ] ) ) {
				$reference_paths[ $requested_url ] = $paths[ $final_url ];
			}
		}
		$files = array();
		foreach ( $resources as $resource_url => $resource ) {
			$path = $paths[ $resource_url ];
			$body = (string) $resource['body'];
			if ( 'html' === $resource['kind'] ) {
				$body = self::rewrite_html( $body, self::html_base_url( $body, $resource_url ), $path, $reference_paths, $aliases );
			} elseif ( 'text/css' === $resource['content_type'] || str_ends_with( strtolower( $path ), '.css' ) ) {
				$body = self::rewrite_css( $body, $resource_url, $path, $reference_paths );
			}

			$file = array(
				'path'      => $path,
				'mime_type' => $resource['content_type'],
			);
			if ( self::is_text( $resource['content_type'], $path ) ) {
				$file['content'] = $body;
			} else {
				$file['content_base64'] = base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes binary artifact payload bytes.
			}
			$files[] = $file;
		}

		return array(
			'provider'        => 'public-static-site-collector',
			'artifact'        => array(
				'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
				'entrypoint' => $paths[ $entry_resource_url ],
				'files'      => $files,
			),
			'source_metadata' => array(
				'source_type' => 'url',
				'source_url'  => $entry_url,
				'final_url'   => $site_url,
				'collection'  => array(
					'pages'             => self::resource_count( $resources, 'html' ),
					'assets'            => self::resource_count( $resources, 'asset' ),
					'bytes'             => $total_bytes,
					'failures'          => $failures,
					'diagnostics'       => $diagnostics,
					'source_exclusions' => $source_exclusions,
					'truncated'         => array_keys( $truncated ),
					'sitemap_urls'      => count( $sitemap_urls ),
				),
			),
		);
	}

	/** @return array<int,string> */
	private static function sitemap_urls( string $entry_url, callable $fetcher, array $fetch_args ): array {
		$parts = parse_url( $entry_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return array();
		}
		$origin      = self::origin( $entry_url );
		$sitemap_url = $origin . '/sitemap.xml';
		$response    = $fetcher( $sitemap_url, array_merge( $fetch_args, array( 'max_bytes' => min( 1048576, (int) ( $fetch_args['max_bytes'] ?? 1048576 ) ), 'content_types' => array( 'application/xml', 'text/xml', 'text/plain', 'application/rss+xml' ) ) ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		preg_match_all( '#<loc\b[^>]*>(.*?)</loc>#is', (string) $response['body'], $matches );
		$urls = array();
		foreach ( $matches[1] ?? array() as $location ) {
			$resolved = self::resolve_url( html_entity_decode( strip_tags( (string) $location ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $sitemap_url );
			if ( '' !== $resolved && self::same_origin( $resolved, $entry_url ) && self::is_page_url( $resolved ) ) {
				$urls[] = $resolved;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/** @return array<int,string> */
	private static function html_page_urls( string $html, string $base_url, string $entry_url ): array {
		$urls = array();
		foreach ( self::tag_attribute_values( $html, 'a', 'href' ) as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url && self::same_origin( $url, $entry_url ) && self::is_page_url( $url ) ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** @return array<int,string> */
	private static function html_asset_urls( string $html, string $base_url, bool $include_scripts ): array {
		$urls = array();
		$source_urls = array_merge(
			self::tag_attribute_values( $html, 'img|source|video|audio', 'src' ),
			self::tag_attribute_values( $html, 'video', 'poster' )
		);
		$script_urls = $include_scripts ? self::tag_attribute_values( $html, 'script', 'src' ) : array();
		$link_urls = array();
		preg_match_all( '#<link\b[^>]*>#is', $html, $link_matches );
		foreach ( $link_matches[0] ?? array() as $link_tag ) {
			$relation = self::tag_attribute_value( $link_tag, 'rel' );
			$href     = self::tag_attribute_value( $link_tag, 'href' );
			if ( null === $relation || null === $href ) {
				continue;
			}
			$relations = preg_split( '/\s+/', strtolower( trim( $relation ) ) );
			if ( array_intersect( $relations ?: array(), array( 'stylesheet', 'icon', 'preload', 'modulepreload' ) ) ) {
				$link_urls[] = $href;
			}
		}
		foreach ( array_merge( $link_urls, $source_urls, $script_urls ) as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		foreach ( self::tag_attribute_values( $html, 'img|source', 'srcset' ) as $srcset ) {
			foreach ( explode( ',', (string) $srcset ) as $candidate ) {
				$reference = preg_split( '/\s+/', trim( $candidate ) )[0] ?? '';
				$url       = self::resolve_url( $reference, $base_url );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( array_merge( $urls, self::css_asset_urls( $html, $base_url ) ) ) );
	}

	/** @return array<int,string> */
	private static function css_asset_urls( string $css, string $base_url ): array {
		preg_match_all( '#url\(\s*(["\']?)(.*?)\1\s*\)#is', $css, $matches );
		$urls = array();
		foreach ( $matches[2] ?? array() as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		preg_match_all( '#@import\s+(["\'])(.*?)\1#is', $css, $import_matches );
		foreach ( $import_matches[2] ?? array() as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** @param array<string,array<string,string>> $resources @return array<string,string> */
	private static function artifact_paths( array $resources, string $entry_url ): array {
		$paths = array();
		$used  = array();
		foreach ( $resources as $resource_url => $resource ) {
			$path = self::artifact_path( $resource_url, 'html' === $resource['kind'], $entry_url );
			if ( isset( $used[ $path ] ) ) {
				$extension = pathinfo( $path, PATHINFO_EXTENSION );
				$suffix    = '-' . substr( hash( 'sha256', $resource_url ), 0, 10 );
				$path      = '' !== $extension ? substr( $path, 0, -1 - strlen( $extension ) ) . $suffix . '.' . $extension : $path . $suffix;
			}
			$used[ $path ]          = true;
			$paths[ $resource_url ] = $path;
		}
		return $paths;
	}

	private static function artifact_path( string $url, bool $html, string $entry_url ): string {
		$parts = parse_url( $url );
		$path  = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '/';
		$path  = implode( '/', array_map( static fn ( string $segment ): string => sanitize_file_name( $segment ), array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) ) );
		if ( ! self::same_origin( $url, $entry_url ) ) {
			$host = sanitize_file_name( strtolower( (string) ( $parts['host'] ?? 'external' ) ) );
			$path = '_external/' . $host . '/' . $path;
		}
		if ( $html ) {
			if ( '' === $path || 'index.html' === strtolower( $path ) || 'index.htm' === strtolower( $path ) ) {
				$path = 'index.html';
			} elseif ( ! preg_match( '/\.html?$/i', $path ) ) {
				$path = rtrim( $path, '/' ) . '/index.html';
			}
		} elseif ( '' === $path ) {
			$path = 'asset-' . substr( hash( 'sha256', $url ), 0, 12 );
		}
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$extension = pathinfo( $path, PATHINFO_EXTENSION );
			$suffix    = '-' . substr( hash( 'sha256', (string) $parts['query'] ), 0, 8 );
			$path      = '' !== $extension ? substr( $path, 0, -1 - strlen( $extension ) ) . $suffix . '.' . $extension : $path . $suffix;
		}
		return 'website/' . ltrim( $path, '/' );
	}

	/** @param array<string,string> $paths */
	private static function rewrite_html( string $html, string $base_url, string $source_path, array $paths, array $aliases ): string {
		$html = preg_replace_callback(
			'#\b(src|href|poster)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#is',
			static function ( array $match ) use ( $base_url, $source_path, $paths, $aliases ): string {
				$value = self::matched_attribute_value( $match );
				$url   = self::resolve_url( $value, $base_url );
				if ( isset( $paths[ $url ] ) && preg_match( '/\.html?$/i', $paths[ $url ] ) ) {
					if ( isset( $aliases[ $url ] ) ) {
						return $match[1] . '="' . self::route_url( $aliases[ $url ], $value ) . '"';
					}
					return $match[0];
				}
				return isset( $paths[ $url ] ) ? $match[1] . '="' . self::relative_path( $source_path, $paths[ $url ] ) . '"' : $match[0];
			},
			$html
		);
		$html = preg_replace_callback(
			'#\bsrcset\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#is',
			static function ( array $match ) use ( $base_url, $source_path, $paths ): string {
				$candidates = array();
				foreach ( explode( ',', self::matched_attribute_value( $match, 1 ) ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
					$url   = self::resolve_url( $parts[0] ?? '', $base_url );
					$ref   = isset( $paths[ $url ] ) ? self::relative_path( $source_path, $paths[ $url ] ) : ( $parts[0] ?? '' );
					$candidates[] = trim( $ref . ' ' . ( $parts[1] ?? '' ) );
				}
				return 'srcset="' . implode( ', ', $candidates ) . '"';
			},
			(string) $html
		);
		return self::rewrite_css( (string) $html, $base_url, $source_path, $paths );
	}

	/** @param array<string,string> $paths */
	private static function rewrite_css( string $css, string $base_url, string $source_path, array $paths ): string {
		$css = (string) preg_replace_callback(
			'#url\(\s*(["\']?)(.*?)\1\s*\)#is',
			static function ( array $match ) use ( $base_url, $source_path, $paths ): string {
				$url = self::resolve_url( $match[2], $base_url );
				return isset( $paths[ $url ] ) ? 'url(' . $match[1] . self::relative_path( $source_path, $paths[ $url ] ) . $match[1] . ')' : $match[0];
			},
			$css
		);
		return (string) preg_replace_callback(
			'#@import\s+(["\'])(.*?)\1#is',
			static function ( array $match ) use ( $base_url, $source_path, $paths ): string {
				$url = self::resolve_url( $match[2], $base_url );
				return isset( $paths[ $url ] ) ? '@import ' . $match[1] . self::relative_path( $source_path, $paths[ $url ] ) . $match[1] : $match[0];
			},
			$css
		);
	}

	private static function response_url( array $response, string $requested_url ): string {
		$final_url = self::canonical_url( (string) ( $response['metadata']['final_url'] ?? '' ) );
		return '' !== $final_url ? $final_url : $requested_url;
	}

	private static function html_base_url( string $html, string $document_url ): string {
		preg_match( '#<base\b[^>]*>#is', $html, $match );
		$base = isset( $match[0] ) ? self::tag_attribute_value( $match[0], 'href' ) : null;
		if ( null === $base ) {
			return $document_url;
		}
		$resolved = self::resolve_url( $base, $document_url );
		return '' !== $resolved ? $resolved : $document_url;
	}

	/** @return array<int,string> */
	private static function tag_attribute_values( string $html, string $tags, string $attribute ): array {
		preg_match_all( '#<(?:' . $tags . ')\b[^>]*>#is', $html, $matches );
		$values = array();
		foreach ( $matches[0] ?? array() as $tag ) {
			$value = self::tag_attribute_value( $tag, $attribute );
			if ( null !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	private static function tag_attribute_value( string $tag, string $attribute ): ?string {
		$pattern = '#\b' . preg_quote( $attribute, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#is';
		if ( ! preg_match( $pattern, $tag, $match ) ) {
			return null;
		}
		return self::matched_attribute_value( $match, 1 );
	}

	private static function matched_attribute_value( array $match, int $offset = 2 ): string {
		foreach ( array_slice( $match, $offset, 3 ) as $value ) {
			if ( '' !== (string) $value ) {
				return (string) $value;
			}
		}
		return '';
	}

	private static function route_url( string $url, string $original_reference ): string {
		$parts    = parse_url( $url );
		$route    = (string) ( $parts['path'] ?? '/' );
		$route   .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = parse_url( html_entity_decode( $original_reference, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), PHP_URL_FRAGMENT );
		return $route . ( is_string( $fragment ) && '' !== $fragment ? '#' . $fragment : '' );
	}

	private static function relative_path( string $from, string $to ): string {
		$from_segments = explode( '/', trim( dirname( $from ), './' ) );
		$to_segments   = explode( '/', trim( $to, '/' ) );
		while ( $from_segments && $to_segments && $from_segments[0] === $to_segments[0] ) {
			array_shift( $from_segments );
			array_shift( $to_segments );
		}
		return str_repeat( '../', count( array_filter( $from_segments, 'strlen' ) ) ) . implode( '/', $to_segments );
	}

	private static function resolve_url( string $reference, string $base_url ): string {
		$reference = trim( html_entity_decode( $reference, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\"'" );
		if ( '' === $reference || str_starts_with( $reference, '#' ) || preg_match( '#^(?:data|javascript|mailto|tel|blob):#i', $reference ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $reference ) ) {
			return self::canonical_url( $reference );
		}
		$base = parse_url( $base_url );
		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}
		if ( str_starts_with( $reference, '//' ) ) {
			return self::canonical_url( $base['scheme'] . ':' . $reference );
		}
		$origin = self::origin( $base_url );
		if ( str_starts_with( $reference, '/' ) ) {
			return self::canonical_url( $origin . $reference );
		}
		$base_path = (string) ( $base['path'] ?? '/' );
		return self::canonical_url( $origin . preg_replace( '#/[^/]*$#', '/', $base_path ) . $reference );
	}

	private static function canonical_url( string $url ): string {
		$parts = parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}
		$path     = self::normalize_path( (string) ( $parts['path'] ?? '/' ) );
		$port     = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$query    = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . $port . $path . $query;
	}

	private static function normalize_path( string $path ): string {
		$segments = array();
		foreach ( explode( '/', '/' . ltrim( $path, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$normalized = '/' . implode( '/', $segments );
		return str_ends_with( $path, '/' ) && '/' !== $normalized ? $normalized . '/' : $normalized;
	}

	private static function origin( string $url ): string {
		$parts = parse_url( $url );
		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	private static function same_origin( string $left, string $right ): bool {
		return self::origin( $left ) === self::origin( $right );
	}

	private static function is_page_url( string $url ): bool {
		$path      = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );
		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		return '' === $extension || in_array( $extension, array( 'html', 'htm', 'php', 'asp', 'aspx' ), true );
	}

	private static function page_key( string $url ): string {
		$parts = parse_url( $url );
		$path  = strtolower( rtrim( (string) ( $parts['path'] ?? '/' ), '/' ) );
		if ( '' === $path || '/index.html' === $path || '/index.htm' === $path ) {
			$path = '/';
		}
		return self::origin( $url ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/** @param array<string,array<string,string>> $resources */
	private static function resource_count( array $resources, string $kind ): int {
		return count( array_filter( $resources, static fn ( array $resource ): bool => $kind === $resource['kind'] ) );
	}

	private static function content_type( array $response, string $fallback ): string {
		$content_type = strtolower( trim( explode( ';', (string) ( $response['metadata']['content_type'] ?? '' ), 2 )[0] ) );
		return '' !== $content_type ? $content_type : $fallback;
	}

	private static function is_text( string $content_type, string $path ): bool {
		return str_starts_with( $content_type, 'text/' ) || in_array( $content_type, array( 'application/javascript', 'application/json', 'application/xml', 'image/svg+xml' ), true ) || (bool) preg_match( '/\.(?:css|js|json|xml|svg)$/i', $path );
	}

	/** @return array<string,string> */
	private static function failure( string $url, WP_Error $error ): array {
		return array(
			'url'     => $url,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}

	private static function delay( int $milliseconds ): void {
		if ( $milliseconds > 0 ) {
			usleep( $milliseconds * 1000 );
		}
	}
}
