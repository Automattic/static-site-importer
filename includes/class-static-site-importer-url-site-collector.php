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

	private const DEFAULT_MAX_PAGES        = 20;
	private const DEFAULT_MAX_ASSETS       = 200;
	private const DEFAULT_MAX_TOTAL_BYTES  = 52428800;
	private const MAX_PAGES                = 250;
	private const MAX_ASSETS               = 2000;
	private const MAX_TOTAL_BYTES          = 268435456;
	private const MAX_RESPONSE_BYTES       = 10485760;
	private const MAX_SITEMAP_DOCUMENTS    = 100;
	private const MAX_DISCOVERED_ROUTES    = 5000;
	private const SAME_ORIGIN_CONCURRENCY  = 2;
	private const CROSS_ORIGIN_CONCURRENCY = 4;

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

		$max_pages               = min( self::MAX_PAGES, max( 1, (int) ( $args['max_pages'] ?? self::DEFAULT_MAX_PAGES ) ) );
		$max_assets              = min( self::MAX_ASSETS, max( 0, (int) ( $args['max_assets'] ?? self::DEFAULT_MAX_ASSETS ) ) );
		$max_total_bytes         = min( self::MAX_TOTAL_BYTES, max( 1, (int) ( $args['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES ) ) );
		$use_many_fetcher        = null === $fetcher;
		$fetcher                 = $fetcher ?? static fn ( string $resource_url, array $fetch_args ) => Static_Site_Importer_URL_Fetcher::fetch( $resource_url, $fetch_args );
		$fetcher                 = self::scheduled_fetcher( $fetcher, $args );
		$fetch_args              = array_intersect_key( $args, array_flip( array( 'timeout' ) ) );
		$fetch_args['max_bytes'] = min( self::MAX_RESPONSE_BYTES, $max_total_bytes, max( 1, (int) ( $args['max_bytes'] ?? 5242880 ) ) );

		$page_queue             = array( $entry_url );
		$asset_queue            = array();
		$queued_pages           = array( self::page_key( $entry_url ) => true );
		$queued_assets          = array();
		$resources              = array();
		$failures               = array();
		$diagnostics            = array();
		$source_exclusions      = array();
		$aliases                = array();
		$total_bytes            = 0;
		$truncated              = array();
		$external_assets        = array();
		$asset_owners           = array();
		$shared_assets          = array();
		$asset_failure_policy   = $args['asset_failure_policy'] ?? '';
		$preserve_failed_assets = in_array( $asset_failure_policy, array( 'preserve_external', 'preserve_failed_external_assets' ), true );
		$preserve_asset_limits  = 'preserve_external' === $asset_failure_policy;
		$page_ready             = 'page_ready' === ( $args['hydration_mode'] ?? '' );
		$critical_assets        = array();
		if ( $page_ready ) {
			// A ready checkpoint may retain optional resources externally, but never a
			// stylesheet or font dependency needed to render the collected page.
			$preserve_failed_assets = true;
			$preserve_asset_limits  = true;
		}
		$script_policy      = self::script_policy( $args );
		$script_exclusions  = array();
		$entry_resource_url = $entry_url;
		$site_url           = $entry_url;

		$sitemap_urls = isset( $args['_route_set'] ) && is_array( $args['_route_set'] ) ? array_values( $args['_route_set'] ) : self::sitemap_urls( $entry_url, $fetcher, $fetch_args );
		if ( is_wp_error( $sitemap_urls ) ) {
			return $sitemap_urls;
		}
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

		$page_fetcher = self::prefetched_fetcher( array_slice( $page_queue, 0, $max_pages ), array_merge( $fetch_args, array( 'content_types' => array( 'text/html', 'application/xhtml+xml' ) ) ), $fetcher, $use_many_fetcher, $args );
		while ( $page_queue && self::resource_count( $resources, 'html' ) < $max_pages ) {
			$page_url = array_shift( $page_queue );
			$response = $page_fetcher( $page_url, array_merge( $fetch_args, array( 'content_types' => array( 'text/html', 'application/xhtml+xml' ) ) ) );
			$response = self::without_cache_marker( $response );
			if ( is_wp_error( $response ) ) {
				if ( $page_url === $entry_url ) {
					return $response;
				}
				$failures[] = self::failure( $page_url, $response, 'html' );
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

			$body       = (string) $response['body'];
			$normalized = Static_Site_Importer_Source_Normalizer::normalize_html( $body, $final_url, $args );
			$body       = $normalized['html'];
			$diagnostic = Static_Site_Importer_URL_Fetcher::html_source_diagnostic( $body );
			if ( ! empty( $diagnostic ) && 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				$error = new WP_Error( 'static_site_importer_url_client_rendered_app', (string) $diagnostic['message'], array( 'diagnostic' => $diagnostic ) );
				if ( $page_url === $entry_url ) {
					return $error;
				}
				$diagnostic['severity']    = 'warning';
				$diagnostic['url']         = $page_url;
				$diagnostic['disposition'] = 'collected_static_html';
				$diagnostics[]             = $diagnostic;
			}
			$document_base_url = self::html_base_url( $body, $final_url );
			$scripts           = self::apply_script_policy( $body, $document_base_url, $script_policy );
			$body              = $scripts['html'];
			$source_exclusions = array_merge( $source_exclusions, $normalized['exclusions'] );
			$script_exclusions = array_merge( $script_exclusions, $scripts['exclusions'] );
			$diagnostics       = array_merge( $diagnostics, $normalized['diagnostics'] );
			$bytes             = strlen( $body );
			if ( $total_bytes + $bytes > $max_total_bytes ) {
				$truncated['bytes'] = true;
				break;
			}

			$total_bytes            += $bytes;
			$resources[ $final_url ] = array(
				'kind'         => 'html',
				'body'         => $body,
				'content_type' => self::content_type( $response, 'text/html' ),
			);

			foreach ( isset( $args['_route_set'] ) ? array() : self::html_page_urls( $body, $document_base_url, $site_url ) as $discovered_url ) {
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

			foreach ( self::critical_html_asset_urls( $body, $document_base_url ) as $asset_url ) {
				$critical_assets[ $asset_url ] = true;
			}
			foreach ( self::html_style_asset_urls( $body, $document_base_url ) as $asset_url ) {
				$shared_assets[ $asset_url ] = true;
			}
			foreach ( self::html_asset_urls( $body, $document_base_url, $scripts['asset_urls'] ) as $asset_url ) {
				$asset_owners[ $asset_url ][ $final_url ] = true;
				if ( isset( $queued_assets[ $asset_url ] ) || isset( $resources[ $asset_url ] ) ) {
					continue;
				}
				if ( $page_ready && ! isset( $critical_assets[ $asset_url ] ) ) {
					$external_assets[ $asset_url ] = 'optional_pending';
					continue;
				}
				if ( count( $asset_queue ) + self::resource_count( $resources, 'asset' ) >= $max_assets ) {
					if ( $preserve_asset_limits ) {
						$external_assets[ $asset_url ] = 'asset_limit';
						continue;
					}
					$truncated['assets'] = true;
					break;
				}
				$queued_assets[ $asset_url ] = true;
				$asset_queue[]               = $asset_url;
			}
		}

		$asset_fetcher = self::prefetched_fetcher( array_slice( $asset_queue, 0, $max_assets ), array_merge( $fetch_args, array( 'content_types' => array() ) ), $fetcher, $use_many_fetcher, $args );
		while ( $asset_queue && self::resource_count( $resources, 'asset' ) < $max_assets ) {
			$asset_url = array_shift( $asset_queue );
			$critical  = $page_ready && isset( $critical_assets[ $asset_url ] );
			$response  = $asset_fetcher( $asset_url, array_merge( $fetch_args, array( 'content_types' => array() ) ) );
			$response  = self::without_cache_marker( $response );
			if ( is_wp_error( $response ) ) {
				if ( 'static_site_importer_invocation_deadline_exceeded' === $response->get_error_code() ) {
					return $response;
				}
				if ( $preserve_failed_assets && ( ! $critical || ! self::same_origin( $asset_url, $site_url ) ) ) {
					$external_assets[ $asset_url ] = $response->get_error_code();
					continue;
				}
				$failures[] = self::failure( $asset_url, $response, 'asset' );
				continue;
			}

			$final_url = self::response_url( $response, $asset_url );
			if ( $final_url !== $asset_url ) {
				$aliases[ $asset_url ] = $final_url;
				if ( isset( $shared_assets[ $asset_url ] ) ) {
					$shared_assets[ $final_url ] = true;
				}
				foreach ( array_keys( $asset_owners[ $asset_url ] ?? array() ) as $owner_url ) {
					$asset_owners[ $final_url ][ $owner_url ] = true;
				}
			}
			if ( isset( $resources[ $final_url ] ) ) {
				continue;
			}

			$body  = (string) $response['body'];
			$bytes = strlen( $body );
			if ( $total_bytes + $bytes > $max_total_bytes ) {
				if ( $preserve_asset_limits && ( ! $critical || ! self::same_origin( $asset_url, $site_url ) ) ) {
					$external_assets[ $asset_url ] = 'byte_limit';
					continue;
				}
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

			if ( 'text/css' === $content_type || str_ends_with( strtolower( (string) self::url_parts( $final_url, PHP_URL_PATH ) ), '.css' ) ) {
				foreach ( self::css_asset_urls( $body, $final_url ) as $nested_url ) {
					if ( isset( $shared_assets[ $final_url ] ) || isset( $shared_assets[ $asset_url ] ) ) {
						$shared_assets[ $nested_url ] = true;
					}
					foreach ( array_keys( $asset_owners[ $final_url ] ?? $asset_owners[ $asset_url ] ?? array() ) as $owner_url ) {
						$asset_owners[ $nested_url ][ $owner_url ] = true;
					}
					if ( isset( $queued_assets[ $nested_url ] ) || isset( $resources[ $nested_url ] ) ) {
						continue;
					}
					$critical_assets[ $nested_url ] = true;
					if ( count( $asset_queue ) + self::resource_count( $resources, 'asset' ) >= $max_assets ) {
						if ( $preserve_asset_limits ) {
							$external_assets[ $nested_url ] = 'asset_limit';
							continue;
						}
						$truncated['assets'] = true;
						break;
					}
					$queued_assets[ $nested_url ] = true;
					$asset_queue[]                = $nested_url;
				}
			}
		}

		if ( ( ! empty( $truncated ) || ! empty( $failures ) ) && ! empty( $args['require_complete_collection'] ) ) {
			return new WP_Error(
				'static_site_importer_site_collection_incomplete',
				'The public site could not be collected completely.',
				array(
					'collection' => array(
						'pages'     => self::resource_count( $resources, 'html' ),
						'assets'    => self::resource_count( $resources, 'asset' ),
						'bytes'     => $total_bytes,
						'failures'  => $failures,
						'truncated' => array_keys( $truncated ),
					),
					'limits'     => array(
						'max_pages'       => $max_pages,
						'max_assets'      => $max_assets,
						'max_total_bytes' => $max_total_bytes,
					),
				)
			);
		}

		ksort( $resources, SORT_STRING );
		ksort( $aliases, SORT_STRING );
		ksort( $external_assets, SORT_STRING );
		usort( $script_exclusions, static fn ( array $left, array $right ): int => strcmp( implode( '|', $left ), implode( '|', $right ) ) );
		$paths           = self::artifact_paths( $resources, $site_url );
		$route_paths     = self::route_paths( $resources );
		$reference_paths = $paths;
		foreach ( $aliases as $requested_url => $final_url ) {
			if ( isset( $paths[ $final_url ] ) ) {
				$reference_paths[ $requested_url ] = $paths[ $final_url ];
			}
		}
		$files          = array();
		$snapshot_files = array();
		foreach ( $resources as $resource_url => $resource ) {
			$path = $paths[ $resource_url ];
			$body = (string) $resource['body'];
			if ( 'html' === $resource['kind'] ) {
				$body = self::rewrite_html( $body, self::html_base_url( $body, $resource_url ), $path, $reference_paths, $aliases, $site_url, $external_assets );
			} elseif ( 'text/css' === $resource['content_type'] || str_ends_with( strtolower( $path ), '.css' ) ) {
				$body = self::rewrite_css( $body, $resource_url, $path, $reference_paths, $external_assets );
			}

			$file = array(
				'path'      => $path,
				'mime_type' => $resource['content_type'],
			);
			if ( 'html' === $resource['kind'] ) {
				$file['metadata'] = array( 'route_path' => $route_paths[ $resource_url ] );
			} else {
				$owners = array_keys( $asset_owners[ $resource_url ] ?? array() );
				if ( isset( $shared_assets[ $resource_url ] ) || count( $owners ) > 1 ) {
					$file['metadata'] = array( 'compilation' => array( 'scope' => 'shared' ) );
				} elseif ( 1 === count( $owners ) && isset( $paths[ $owners[0] ] ) ) {
					$file['metadata'] = array(
						'compilation' => array(
							'scope' => 'page',
							'id'    => $paths[ $owners[0] ],
						),
					);
				}
			}
			if ( self::is_text( $resource['content_type'], $path ) ) {
				$file['content'] = $body;
			} else {
				$file['content_base64'] = base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes binary artifact payload bytes.
			}
			$files[]          = $file;
			$snapshot_files[] = array(
				'path'       => $path,
				'source_url' => $resource_url,
				'mime_type'  => $resource['content_type'],
				'bytes'      => strlen( $body ),
				'sha256'     => hash( 'sha256', $body ),
			);
		}
		usort( $files, static fn ( array $left, array $right ): int => strcmp( (string) $left['path'], (string) $right['path'] ) );
		usort( $snapshot_files, static fn ( array $left, array $right ): int => strcmp( (string) $left['path'], (string) $right['path'] ) );
		$compiler_limits    = array(
			'max_files'       => min( 5000, $max_assets + ( 5 * $max_pages ) ),
			'max_file_bytes'  => $fetch_args['max_bytes'],
			'max_total_bytes' => min( 335544320, $max_total_bytes + min( 67108864, $max_total_bytes ) ),
		);
		$snapshot           = array(
			'schema'     => 'static-site-importer/url-snapshot/v1',
			'entrypoint' => $paths[ $entry_resource_url ],
			'files'      => $snapshot_files,
		);
		$snapshot['sha256'] = hash(
			'sha256',
			self::json_encode(
				array(
					'entrypoint'      => $snapshot['entrypoint'],
					'compiler_limits' => $compiler_limits,
					'files'           => $snapshot_files,
				),
				JSON_UNESCAPED_SLASHES
			)
		);

		return array(
			'provider'        => 'public-static-site-collector',
			'artifact'        => array(
				'schema'          => 'blocks-engine/php-transformer/site-artifact/v1',
				'entrypoint'      => $paths[ $entry_resource_url ],
				'compiler_limits' => $compiler_limits,
				'metadata'        => array( 'snapshot' => $snapshot ),
				'files'           => $files,
			),
			'source_metadata' => array(
				'source_type' => 'url',
				'source_url'  => $entry_url,
				'final_url'   => $site_url,
				'snapshot'    => $snapshot,
				'collection'  => array(
					'pages'                   => self::resource_count( $resources, 'html' ),
					'assets'                  => self::resource_count( $resources, 'asset' ),
					'bytes'                   => $total_bytes,
					'failures'                => $failures,
					'diagnostics'             => $diagnostics,
					'source_exclusions'       => $source_exclusions,
					'script_policy'           => array(
						'name'             => $script_policy,
						'excluded_count'   => count( $script_exclusions ),
						'excluded_scripts' => $script_exclusions,
					),
					'truncated'               => array_keys( $truncated ),
					'sitemap_urls'            => count( $sitemap_urls ),
					'fetch_scheduling'        => self::scheduling_limits( $args ),
					'external_asset_retained' => array(
						'count'   => count( $external_assets ),
						'samples' => array_slice(
							array_map(
								static fn ( string $url, int|string $reason ): array => array(
									'url'    => $url,
									'reason' => (string) $reason,
								),
								array_keys( $external_assets ),
								$external_assets
							),
							0,
							50
						),
					),
					'readiness'               => array(
						'mode'            => $page_ready ? 'page_ready' : 'complete_snapshot',
						'html'            => 'complete',
						'critical_assets' => 'complete',
						'optional_assets' => $page_ready && in_array( 'optional_pending', $external_assets, true ) ? 'pending' : 'complete',
					),
				),
			),
		);
	}

	/**
	 * Discover all same-origin page routes declared by a sitemap index or urlset.
	 *
	 * @return array<int,string>|WP_Error
	 */
	public static function discover_routes( string $url, array $args = array(), ?callable $fetcher = null ) {
		$entry_url = self::canonical_url( Static_Site_Importer_URL_Fetcher::normalize_url( $url ) );
		if ( '' === $entry_url ) {
			return new WP_Error( 'static_site_importer_site_collection_invalid_url', 'Enter a valid public site URL.' );
		}
		$fetcher                 = $fetcher ?? static fn ( string $resource_url, array $fetch_args ) => Static_Site_Importer_URL_Fetcher::fetch( $resource_url, $fetch_args );
		$fetcher                 = self::scheduled_fetcher( $fetcher, $args );
		$fetch_args              = array_intersect_key( $args, array_flip( array( 'timeout' ) ) );
		$fetch_args['max_bytes'] = min( 10485760, max( 1, (int) ( $args['max_bytes'] ?? 5242880 ) ) );
		$routes                  = self::sitemap_urls( $entry_url, $fetcher, $fetch_args );
		if ( is_wp_error( $routes ) ) {
			return $routes;
		}
		if ( ! empty( $routes ) ) {
			return $routes;
		}
		// Public sites frequently omit or block sitemap.xml. Crawl HTML links from
		// the entrypoint so batch mode still has a bounded useful route set.
		$queue = array( $entry_url );
		$seen  = array();
		while ( $queue ) {
			$current = array_shift( $queue );
			$key     = self::page_key( $current );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$response = $fetcher( $current, array_merge( $fetch_args, array( 'content_types' => array( 'text/html', 'application/xhtml+xml' ) ) ) );
			if ( is_wp_error( $response ) || '' === trim( (string) ( $response['body'] ?? '' ) ) ) {
				continue;
			}
			$seen[ $key ] = $current;
			$final        = self::response_url( $response, $current );
			foreach ( self::html_page_urls( (string) $response['body'], self::html_base_url( (string) $response['body'], $final ), $entry_url ) as $next ) {
				if ( ! isset( $seen[ self::page_key( $next ) ] ) && count( $seen ) + count( $queue ) >= self::MAX_DISCOVERED_ROUTES ) {
					return new WP_Error(
						'static_site_importer_discovery_incomplete',
						'HTML link discovery exceeded its route limit.',
						array(
							'truncated_dimension' => 'routes',
							'limit'               => self::MAX_DISCOVERED_ROUTES,
							'discovered'          => count( $seen ),
							'queued'              => count( $queue ),
						)
					);
				}
				if ( ! isset( $seen[ self::page_key( $next ) ] ) ) {
					$queue[] = $next;
				}
			}
		}
		return array_values( $seen );
	}

	/** @return array<string,int> */
	public static function discovery_limits(): array {
		return array(
			'max_sitemap_documents'      => self::MAX_SITEMAP_DOCUMENTS,
			'max_discovered_routes'      => self::MAX_DISCOVERED_ROUTES,
			'max_sitemap_document_bytes' => 1048576,
		);
	}

	/** @return array<int,string>|WP_Error */
	private static function sitemap_urls( string $entry_url, callable $fetcher, array $fetch_args ) {
		$parts = self::url_parts( $entry_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return array();
		}
		$origin      = self::origin( $entry_url );
		$sitemap_url = $origin . '/sitemap.xml';
		$queue       = array( $sitemap_url );
		$seen        = array();
		$urls        = array();
		while ( $queue ) {
			$current = array_shift( $queue );
			if ( isset( $seen[ $current ] ) ) {
				continue;
			}
			$seen[ $current ] = true;
			$response         = $fetcher(
				$current,
				array_merge(
					$fetch_args,
					array(
						'max_bytes'     => min( 1048576, (int) ( $fetch_args['max_bytes'] ?? 1048576 ) ),
						'content_types' => array( 'application/xml', 'text/xml', 'text/plain', 'application/rss+xml' ),
					)
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			preg_match_all( '#<loc\b[^>]*>(.*?)</loc>#is', (string) $response['body'], $matches );
			foreach ( $matches[1] as $location ) {
				$resolved = self::resolve_url( html_entity_decode( self::strip_all_tags( (string) $location ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $current );
				if ( '' === $resolved || ! self::same_origin( $resolved, $entry_url ) ) {
					continue;
				}
				if ( str_ends_with( strtolower( (string) self::url_parts( $resolved, PHP_URL_PATH ) ), '.xml' ) ) {
					if ( ! isset( $seen[ $resolved ] ) && count( $seen ) + count( $queue ) >= self::MAX_SITEMAP_DOCUMENTS ) {
						return new WP_Error(
							'static_site_importer_discovery_incomplete',
							'Sitemap discovery exceeded its document limit.',
							array(
								'truncated_dimension' => 'sitemap_documents',
								'limit'               => self::MAX_SITEMAP_DOCUMENTS,
								'discovered'          => count( $seen ),
								'queued'              => count( $queue ),
							)
						);
					}
					$queue[] = $resolved;
				} elseif ( self::is_page_url( $resolved ) ) {
					if ( count( $urls ) >= self::MAX_DISCOVERED_ROUTES ) {
						return new WP_Error(
							'static_site_importer_discovery_incomplete',
							'Sitemap discovery exceeded its route limit.',
							array(
								'truncated_dimension' => 'routes',
								'limit'               => self::MAX_DISCOVERED_ROUTES,
								'discovered'          => count( $urls ),
							)
						);
					}
					$urls[] = $resolved;
				}
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
	private static function html_asset_urls( string $html, string $base_url, array $script_urls = array() ): array {
		$urls        = array();
		foreach ( self::tag_attribute_values( $html, 'a', 'href' ) as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url && ! self::is_page_url( $url ) ) {
				$urls[] = $url;
			}
		}
		$source_urls = array_merge(
			self::tag_attribute_values( $html, 'img|source|video|audio', 'src' ),
			self::tag_attribute_values( $html, 'video', 'poster' )
		);
		$link_urls   = array();
		preg_match_all( '#<link\b[^>]*>#is', $html, $link_matches );
		foreach ( $link_matches[0] as $link_tag ) {
			$relation = self::tag_attribute_value( $link_tag, 'rel' );
			$href     = self::tag_attribute_value( $link_tag, 'href' );
			if ( null === $relation || null === $href ) {
				continue;
			}
			$relations = preg_split( '/\s+/', strtolower( trim( $relation ) ) );
			if ( array_intersect( $relations ? $relations : array(), array( 'stylesheet', 'icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'apple-touch-startup-image', 'mask-icon', 'manifest', 'preload', 'modulepreload' ) ) ) {
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
		return array_values( array_unique( array_merge( $urls, self::html_css_asset_urls( $html, $base_url ) ) ) );
	}

	/** Resolve the explicit script retention contract for public HTML collection. */
	private static function script_policy( array $args ): string {
		if ( array_key_exists( 'include_scripts', $args ) ) {
			return ! empty( $args['include_scripts'] ) ? 'full' : 'none';
		}
		$policy = isset( $args['script_policy'] ) ? (string) $args['script_policy'] : 'static';
		return in_array( $policy, array( 'static', 'full', 'none' ), true ) ? $policy : 'static';
	}

	/**
	 * Omit scripts from the frozen server-rendered document unless a caller supplies
	 * the full runtime-preservation contract.
	 *
	 * Full retention remains an explicit compatibility mode for callers that supply
	 * their own runtime-preservation contract.
	 *
	 * @return array{html:string,asset_urls:array<int,string>,exclusions:array<int,array<string,string>>}
	 */
	private static function apply_script_policy( string $html, string $base_url, string $policy ): array {
		$asset_urls = array();
		$exclusions = array();
		$html       = (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			static function ( array $matches ) use ( $base_url, $policy, &$asset_urls, &$exclusions ): string {
				$tag     = '<script' . $matches[1] . '>';
				$source  = self::tag_attribute_value( $tag, 'src' );
				$type    = strtolower( trim( (string) self::tag_attribute_value( $tag, 'type' ) ) );
				$kind    = null === $source ? 'inline' : 'external';
				$is_data = in_array( $type, array( 'application/json', 'application/ld+json', 'application/manifest+json' ), true );
				$keep    = 'full' === $policy;
				if ( $keep ) {
					if ( 'external' === $kind ) {
						$url = self::resolve_url( (string) $source, $base_url );
						if ( '' !== $url ) {
							$asset_urls[] = $url;
						}
					}
					return $matches[0];
				}

				$exclusion = array(
					'kind'        => $kind,
					'reason_code' => 'none' === $policy ? 'script_omitted_by_caller_policy' : ( $is_data ? 'data_script_omitted_from_static_artifact' : 'script_omitted_without_runtime_declaration' ),
					'sha256'      => hash( 'sha256', $matches[0] ),
					'type'        => '' !== $type ? $type : 'classic',
				);
				if ( 'external' === $kind ) {
					$exclusion['url'] = self::resolve_url( (string) $source, $base_url );
				}
				$exclusions[] = $exclusion;
				return '';
			},
			$html
		);
		return array(
			'html'       => $html,
			'asset_urls' => array_values( array_unique( $asset_urls ) ),
			'exclusions' => $exclusions,
		);
	}

	/** @return array<int,string> */
	private static function critical_html_asset_urls( string $html, string $base_url ): array {
		$urls = array();
		preg_match_all( '#<link\b[^>]*>#is', $html, $matches );
		foreach ( $matches[0] as $tag ) {
			$relation = strtolower( (string) self::tag_attribute_value( $tag, 'rel' ) );
			$href     = self::tag_attribute_value( $tag, 'href' );
			$as       = strtolower( (string) self::tag_attribute_value( $tag, 'as' ) );
			if ( null === $href || ( ! str_contains( $relation, 'stylesheet' ) && ( ! str_contains( $relation, 'preload' ) || ! in_array( $as, array( 'style', 'font' ), true ) ) ) ) {
				continue;
			}
			$url = self::resolve_url( $href, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** @return array<int,string> */
	private static function html_css_asset_urls( string $html, string $base_url ): array {
		$css = array();
		preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $style_blocks );
		$css = array_merge( $css, $style_blocks[1] );
		preg_match_all( '#<[^>]+\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#is', $html, $style_attributes, PREG_SET_ORDER );
		foreach ( $style_attributes as $attribute ) {
			$css[] = self::matched_attribute_value( $attribute, 1 );
		}
		$urls = array();
		foreach ( $css as $source ) {
			$urls = array_merge( $urls, self::css_asset_urls( (string) $source, $base_url ) );
		}
		return array_values( array_unique( $urls ) );
	}

	/** @return array<int,string> */
	private static function html_style_asset_urls( string $html, string $base_url ): array {
		preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $style_blocks );
		$urls = array();
		foreach ( $style_blocks[1] as $css ) {
			$urls = array_merge( $urls, self::css_asset_urls( (string) $css, $base_url ) );
		}
		return array_values( array_unique( $urls ) );
	}

	/** @return array<int,string> */
	private static function css_asset_urls( string $css, string $base_url ): array {
		preg_match_all( '#url\(\s*(["\']?)(.*?)\1\s*\)#is', $css, $matches );
		$urls = array();
		foreach ( $matches[2] as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		preg_match_all( '#@import\s+(["\'])(.*?)\1#is', $css, $import_matches );
		foreach ( $import_matches[2] as $reference ) {
			$url = self::resolve_url( (string) $reference, $base_url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** @param array<string,array<string,string>> $resources @return array<string,string> */
	private static function artifact_paths( array $resources, string $entry_url ): array {
		ksort( $resources, SORT_STRING );
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

	/** @param array<string,array<string,string>> $resources @return array<string,string> */
	private static function route_paths( array $resources ): array {
		ksort( $resources, SORT_STRING );
		$paths = array();
		$used  = array();
		foreach ( $resources as $resource_url => $resource ) {
			if ( 'html' !== $resource['kind'] ) {
				continue;
			}
			$path = self::canonical_route_path( $resource_url );
			if ( isset( $used[ $path ] ) ) {
				$path = rtrim( $path, '/' ) . '-' . substr( hash( 'sha256', $resource_url ), 0, 10 );
			}
			$used[ $path ]          = true;
			$paths[ $resource_url ] = $path;
		}
		return $paths;
	}

	private static function artifact_path( string $url, bool $html, string $entry_url ): string {
		$parts = self::url_parts( $url );
		$path  = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '/';
		$path  = implode( '/', array_map( static fn ( string $segment ): string => sanitize_file_name( $segment ), array_filter( explode( '/', trim( $path, '/' ) ), static fn ( string $segment ): bool => '' !== $segment ) ) );
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
	private static function rewrite_html( string $html, string $base_url, string $source_path, array $paths, array $aliases, string $site_url, array $external_assets = array() ): string {
		$html = preg_replace_callback(
			'#\b(src|href|poster)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#is',
			static function ( array $matches ) use ( $base_url, $source_path, $paths, $aliases, $site_url, $external_assets ): string {
				$value = self::matched_attribute_value( $matches );
				$url   = self::resolve_url( $value, $base_url );
				if ( isset( $paths[ $url ] ) && preg_match( '/\.html?$/i', $paths[ $url ] ) ) {
					if ( isset( $aliases[ $url ] ) ) {
						return $matches[1] . '="' . self::route_url( $aliases[ $url ], $value ) . '"';
					}
					return $matches[0];
				}
				if ( isset( $paths[ $url ] ) ) {
					return $matches[1] . '="' . self::relative_path( $source_path, $paths[ $url ] ) . '"';
				}
				if ( isset( $external_assets[ $url ] ) ) {
					return $matches[1] . '="' . self::external_asset_url( $url, $value ) . '"';
				}
				return '' !== $url && self::same_origin( $url, $site_url ) && self::is_page_url( $url ) ? $matches[1] . '="' . self::route_url( $url, $value ) . '"' : $matches[0];
			},
			$html
		);
		$html = preg_replace_callback(
			'#\bsrcset\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#is',
			static function ( array $matches ) use ( $base_url, $source_path, $paths, $external_assets ): string {
				$candidates = array();
				foreach ( explode( ',', self::matched_attribute_value( $matches, 1 ) ) as $candidate ) {
					$parts        = preg_split( '/\s+/', trim( $candidate ), 2 );
					$url          = self::resolve_url( $parts[0] ?? '', $base_url );
					$ref          = isset( $paths[ $url ] ) ? self::relative_path( $source_path, $paths[ $url ] ) : ( isset( $external_assets[ $url ] ) ? self::external_asset_url( $url, (string) ( $parts[0] ?? '' ) ) : ( $parts[0] ?? '' ) );
					$candidates[] = trim( $ref . ' ' . ( $parts[1] ?? '' ) );
				}
				return 'srcset="' . implode( ', ', $candidates ) . '"';
			},
			(string) $html
		);
		return self::rewrite_css( (string) $html, $base_url, $source_path, $paths, $external_assets );
	}

	/** @param array<string,string> $paths */
	private static function rewrite_css( string $css, string $base_url, string $source_path, array $paths, array $external_assets = array() ): string {
		$css = (string) preg_replace_callback(
			'#url\(\s*(["\']?)(.*?)\1\s*\)#is',
			static function ( array $matches ) use ( $base_url, $source_path, $paths, $external_assets ): string {
				$url = self::resolve_url( $matches[2], $base_url );
				return isset( $paths[ $url ] ) ? 'url(' . $matches[1] . self::relative_path( $source_path, $paths[ $url ] ) . $matches[1] . ')' : ( isset( $external_assets[ $url ] ) ? 'url(' . $matches[1] . self::external_asset_url( $url, $matches[2] ) . $matches[1] . ')' : $matches[0] );
			},
			$css
		);
		return (string) preg_replace_callback(
			'#@import\s+(["\'])(.*?)\1#is',
			static function ( array $matches ) use ( $base_url, $source_path, $paths, $external_assets ): string {
				$url = self::resolve_url( $matches[2], $base_url );
				return isset( $paths[ $url ] ) ? '@import ' . $matches[1] . self::relative_path( $source_path, $paths[ $url ] ) . $matches[1] : ( isset( $external_assets[ $url ] ) ? '@import ' . $matches[1] . self::external_asset_url( $url, $matches[2] ) . $matches[1] : $matches[0] );
			},
			$css
		);
	}

	private static function external_asset_url( string $url, string $reference ): string {
		$fragment = self::url_parts( html_entity_decode( $reference, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), PHP_URL_FRAGMENT );
		return $url . ( is_string( $fragment ) && '' !== $fragment ? '#' . $fragment : '' );
	}

	private static function response_url( array $response, string $requested_url ): string {
		$final_url = self::canonical_url( (string) ( $response['metadata']['final_url'] ?? '' ) );
		return '' !== $final_url ? $final_url : $requested_url;
	}

	private static function html_base_url( string $html, string $document_url ): string {
		preg_match( '#<base\b[^>]*>#is', $html, $matches );
		$base = isset( $matches[0] ) ? self::tag_attribute_value( $matches[0], 'href' ) : null;
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

	private static function matched_attribute_value( array $matches, int $offset = 2 ): string {
		foreach ( array_slice( $matches, $offset, 3 ) as $value ) {
			if ( '' !== (string) $value ) {
				return (string) $value;
			}
		}
		return '';
	}

	private static function route_url( string $url, string $original_reference ): string {
		$parts    = self::url_parts( $url );
		$route    = (string) ( $parts['path'] ?? '/' );
		$route   .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = self::url_parts( html_entity_decode( $original_reference, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), PHP_URL_FRAGMENT );
		return $route . ( is_string( $fragment ) && '' !== $fragment ? '#' . $fragment : '' );
	}

	private static function relative_path( string $from, string $to ): string {
		$from_segments = explode( '/', trim( dirname( $from ), './' ) );
		$to_segments   = explode( '/', trim( $to, '/' ) );
		while ( $from_segments && $to_segments && $from_segments[0] === $to_segments[0] ) {
			array_shift( $from_segments );
			array_shift( $to_segments );
		}
		return str_repeat( '../', count( array_filter( $from_segments, static fn ( string $segment ): bool => '' !== $segment ) ) ) . implode( '/', $to_segments );
	}

	private static function resolve_url( string $reference, string $base_url ): string {
		$reference = trim( html_entity_decode( $reference, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\"'" );
		if ( '' === $reference || str_starts_with( $reference, '#' ) || preg_match( '#^(?:data|javascript|mailto|tel|blob):#i', $reference ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $reference ) ) {
			return self::canonical_url( $reference );
		}
		$base = self::url_parts( $base_url );
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
		$parts = self::url_parts( trim( $url ) );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}
		$path  = self::normalize_path( (string) ( $parts['path'] ?? '/' ) );
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
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

	private static function canonical_route_path( string $url ): string {
		$path     = (string) ( self::url_parts( $url, PHP_URL_PATH ) ?? '/' );
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), static fn ( string $segment ): bool => '' !== $segment ) );
		$last     = array_key_last( $segments );
		$slugs    = array();
		foreach ( $segments as $index => $segment ) {
			$decoded = urldecode( $segment );
			if ( $index === $last ) {
				$decoded = (string) preg_replace( '/\.html?$/i', '', $decoded );
			}
			$slug = function_exists( 'sanitize_title' )
				? sanitize_title( $decoded )
				: trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $decoded ) ), '-' );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return array() === $slugs ? '/' : '/' . implode( '/', $slugs );
	}

	private static function origin( string $url ): string {
		$parts = self::url_parts( $url );
		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	private static function same_origin( string $left, string $right ): bool {
		return self::origin( $left ) === self::origin( $right );
	}

	private static function is_page_url( string $url ): bool {
		$path      = strtolower( (string) self::url_parts( $url, PHP_URL_PATH ) );
		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		return '' === $extension || in_array( $extension, array( 'html', 'htm', 'php', 'asp', 'aspx' ), true );
	}

	private static function page_key( string $url ): string {
		$parts = self::url_parts( $url );
		$path  = strtolower( rtrim( (string) ( $parts['path'] ?? '/' ), '/' ) );
		if ( '' === $path || '/index.html' === $path || '/index.htm' === $path ) {
			$path = '/';
		}
		return self::origin( $url ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/** @param array<string,array<string,string>> $resources */
	private static function resource_count( array $resources, string $kind ): int {
		return count( array_filter( $resources, static fn ( array $item ): bool => $kind === $item['kind'] ) );
	}

	private static function url_parts( string $url, int $component = -1 ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return -1 === $component ? wp_parse_url( $url ) : wp_parse_url( $url, $component );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone collector smoke tests run without WordPress URL helpers.
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}

	private static function json_encode( array $data, int $flags = 0 ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data, $flags );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone collector smoke tests run without WordPress JSON helpers.
			$encoded = json_encode( $data, $flags );
		}
		return false === $encoded ? '' : $encoded;
	}

	private static function strip_all_tags( string $html ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $html );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone collector smoke tests run without WordPress tag helpers.
		return strip_tags( $html );
	}

	private static function content_type( array $response, string $fallback ): string {
		$content_type = strtolower( trim( explode( ';', (string) ( $response['metadata']['content_type'] ?? '' ), 2 )[0] ) );
		return '' !== $content_type ? $content_type : $fallback;
	}

	private static function is_text( string $content_type, string $path ): bool {
		return str_starts_with( $content_type, 'text/' ) || in_array( $content_type, array( 'application/javascript', 'application/json', 'application/xml', 'image/svg+xml' ), true ) || (bool) preg_match( '/\.(?:css|js|json|xml|svg)$/i', $path );
	}

	/** @return array<string,int|string> */
	private static function failure( string $url, WP_Error $error, string $kind = 'asset' ): array {
		return array(
			'url'     => $url,
			'kind'    => $kind,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}

	private static function without_cache_marker( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}
		$data = is_array( $response->get_error_data() ) ? $response->get_error_data() : array();
		if ( empty( $data['_static_site_importer_negative_cache_hit'] ) ) {
			return $response;
		}
		unset( $data['_static_site_importer_negative_cache_hit'] );
		return new WP_Error( $response->get_error_code(), $response->get_error_message(), ! empty( $data ) ? $data : null );
	}

	/** @return callable */
	private static function prefetched_fetcher( array $urls, array $fetch_args, callable $fetcher, bool $use_many_fetcher, array $args ): callable {
		if ( ! $use_many_fetcher || ! $urls ) {
			return $fetcher;
		}
		$responses = self::fetch_batch( $urls, $fetch_args, $args );
		return static fn ( string $url, array $request_args ) => $responses[ $url ] ?? $fetcher( $url, $request_args );
	}

	/** @return array<string,array|WP_Error> */
	private static function fetch_batch( array $urls, array $fetch_args, array $args ): array {
		$attempts  = min( 3, max( 1, (int) ( $args['fetch_attempts'] ?? 2 ) ) );
		$delay     = min( 2000, max( 0, (int) ( $args['request_delay_ms'] ?? 0 ) ) );
		$pending   = array_values( $urls );
		$responses = array();
		for ( $attempt = 0; $pending && $attempt < $attempts; $attempt++ ) {
			$requests = array();
			foreach ( $pending as $url ) {
				$requests[ $url ] = array(
					'url'  => $url,
					'args' => $fetch_args,
				);
			}
			$many_args = array(
				'concurrency'            => self::CROSS_ORIGIN_CONCURRENCY,
				'per_origin_concurrency' => self::SAME_ORIGIN_CONCURRENCY,
			);
			if ( isset( $args['_static_site_importer_fetch_deadline'] ) ) {
				$many_args['deadline'] = (float) $args['_static_site_importer_fetch_deadline'];
			}
			if ( isset( $args['_static_site_importer_fetch_clock'] ) && is_callable( $args['_static_site_importer_fetch_clock'] ) ) {
				$many_args['clock'] = $args['_static_site_importer_fetch_clock'];
			}
			if ( isset( $args['_static_site_importer_fetch_many_transport'] ) && is_array( $args['_static_site_importer_fetch_many_transport'] ) ) {
				$many_args['transport'] = $args['_static_site_importer_fetch_many_transport'];
			}
			$batch   = Static_Site_Importer_URL_Fetcher::fetch_many( $requests, $many_args );
			$pending = array();
			foreach ( $batch as $url => $response ) {
				$responses[ $url ] = $response;
				if ( is_wp_error( $response ) && $attempt + 1 < $attempts ) {
					self::delay( $delay, $args );
					$pending[] = $url;
				}
			}
		}
		return $responses;
	}

	/** @return callable */
	private static function scheduled_fetcher( callable $fetch_resource, array $args ): callable {
		$fetch_attempts = min( 3, max( 1, (int) ( $args['fetch_attempts'] ?? 2 ) ) );
		$retry_delay    = min( 2000, max( 0, (int) ( $args['request_delay_ms'] ?? 0 ) ) );
		$clock          = isset( $args['_static_site_importer_scheduler_clock'] ) && is_callable( $args['_static_site_importer_scheduler_clock'] ) ? $args['_static_site_importer_scheduler_clock'] : static fn (): float => microtime( true );
		return static function ( string $resource_url, array $fetch_args ) use ( $fetch_resource, $fetch_attempts, $retry_delay, $clock, $args ) {
			$response     = null;
			$next_allowed = array();
			$origin       = self::origin( $resource_url );
			for ( $attempt = 0; $attempt < $fetch_attempts; $attempt++ ) {
				$wait = max( 0, ( $next_allowed[ $origin ] ?? 0 ) - (float) call_user_func( $clock ) );
				if ( $wait > 0 ) {
					self::delay( (int) ceil( $wait * 1000 ), $args );
				}
				$response = $fetch_resource( $resource_url, $fetch_args );
				if ( ! is_wp_error( $response ) ) {
					return $response;
				}
				// Successful and cached responses are immediately eligible; only a retry is paced.
				$next_allowed[ $origin ] = (float) call_user_func( $clock ) + ( $retry_delay / 1000 );
			}
			$data = is_array( $response->get_error_data() ) ? $response->get_error_data() : array();
			if ( ! empty( $data['_static_site_importer_cache_aware'] ) ) {
				unset( $data['_static_site_importer_cache_aware'] );
				$response = new WP_Error( $response->get_error_code(), $response->get_error_message(), ! empty( $data ) ? $data : null );
				$fetch_resource( $resource_url, $fetch_args + array( '_static_site_importer_cache_failure' => $response ) );
			}
			return $response;
		};
	}

	/** @return array<string,int> */
	private static function scheduling_limits( array $args ): array {
		return array(
			'same_origin_concurrency'  => self::SAME_ORIGIN_CONCURRENCY,
			'cross_origin_concurrency' => self::CROSS_ORIGIN_CONCURRENCY,
			'retry_delay_ms'           => min( 2000, max( 0, (int) ( $args['request_delay_ms'] ?? 0 ) ) ),
		);
	}

	private static function delay( int $milliseconds, array $args = array() ): void {
		if ( $milliseconds > 0 ) {
			if ( isset( $args['_static_site_importer_delay_callback'] ) && is_callable( $args['_static_site_importer_delay_callback'] ) ) {
				call_user_func( $args['_static_site_importer_delay_callback'], $milliseconds );
				return;
			}
			usleep( $milliseconds * 1000 );
		}
	}
}
