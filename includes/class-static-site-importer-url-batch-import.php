<?php

/** Resumable bounded URL site import. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'Static_Site_Importer_Artifact_Run_Workspace' ) ) {
	require_once __DIR__ . '/class-static-site-importer-artifact-run.php';
}
if ( ! class_exists( 'Static_Site_Importer_Shared_Resource_Plan' ) ) {
	require_once __DIR__ . '/class-static-site-importer-shared-resource-plan.php';
}
final class Static_Site_Importer_URL_Batch_Import {

	private const VERSION         = 2;
	private const MAX_BATCH_PAGES = 100;
	public static function import( array $request, array $input, ?callable $fetcher = null, ?callable $importer = null ) {
		$args        = is_array( $request['provider_args'] ?? null ) ? $request['provider_args'] : array();
		$batch_pages = (int) ( $args['batch_pages'] ?? 0 );
		if ( $batch_pages < 1 ) {
			return new WP_Error( 'static_site_importer_invalid_batch_pages', 'batch_pages must be a positive integer.' );
		}$max_effective_batches = null;
		if ( array_key_exists( 'max_effective_batches_per_invocation', $args ) ) {
			$max_effective_batches = (int) $args['max_effective_batches_per_invocation'];
			if ( $max_effective_batches < 1 ) {
				return new WP_Error( 'static_site_importer_invalid_max_effective_batches_per_invocation', 'max_effective_batches_per_invocation must be a positive integer.' );
			}
		}$clock                 = is_callable( $args['_static_site_importer_clock'] ?? null ) ? $args['_static_site_importer_clock'] : static fn (): float => microtime( true );
		$deadline               = null;
		$max_invocation_seconds = null;
		if ( array_key_exists( 'max_invocation_seconds', $args ) ) {
			$max_invocation_seconds = (float) $args['max_invocation_seconds'];
			if ( $max_invocation_seconds <= 0 ) {
				return new WP_Error( 'static_site_importer_invalid_max_invocation_seconds', 'max_invocation_seconds must be a positive number.' );
			}
			$deadline = (float) call_user_func( $clock ) + $max_invocation_seconds;
		}if ( ! array_key_exists( 'max_assets', $args ) ) {
			$args['max_assets'] = 2000;
		}if ( ! array_key_exists( 'max_total_bytes', $args ) ) {
			$args['max_total_bytes'] = 268435456;
		}$work_dir = (string) ( $request['work_dir'] ?? '' );
		if ( '' === $work_dir || ! wp_mkdir_p( $work_dir ) ) {
			return new WP_Error( 'static_site_importer_batch_work_dir_unavailable', 'The batch import work directory is unavailable.' );
		}
		$url           = (string) $request['url'];
		$manifest_path = trailingslashit( $work_dir ) . 'url-site-batch-manifest-' . hash( 'sha256', self::VERSION . "\n" . $url ) . '.json';
		$requested     = self::contract( $url, $input, $args, $batch_pages );
		$existing      = self::existing_manifest( $manifest_path );
		$contract      = $existing && self::canonical( $existing['contract'] ) === self::canonical( $requested ) ? $existing['contract'] : $requested;
		$contract_json = wp_json_encode( $contract );
		if ( false === $contract_json ) {
			return new WP_Error( 'static_site_importer_batch_contract_encode_failed', 'The URL batch import contract could not be encoded.' );
		}
		$identity = $existing && self::canonical( $existing['contract'] ) === self::canonical( $requested ) ? $existing['identity'] : hash( 'sha256', $contract_json );
		try {
			$workspace = new Static_Site_Importer_Artifact_Run_Workspace(
				$work_dir,
				'url-' . $identity,
				array(
					'on_success' => 'purge_on_success',
					'on_failure' => 'retain',
					'expires_at' => gmdate( 'c', time() + 604800 ),
				)
			);
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'static_site_importer_batch_work_dir_unavailable', $error->getMessage() );
		}
		if ( $workspace->is_expired() ) {
			$cleanup = $workspace->purge();
			$expired = $manifest_path;
			$archive = $expired . '.expired-' . gmdate( 'YmdHis' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Archives an importer-owned manifest after workspace expiry.
			$archived = is_file( $expired ) && ! is_link( $expired ) ? rename( $expired, $archive ) : false;
			return new WP_Error(
				'static_site_importer_batch_run_expired',
				'The retained URL batch run expired and must be restarted.',
				array(
					'cleanup'           => $cleanup,
					'expired_manifest'  => $expired,
					'archived_manifest' => $archived ? $archive : null,
					'restart_required'  => true,
				)
			);
		}$cache = new Static_Site_Importer_Artifact_Byte_Cache( $workspace, 'http-response' );
		$cache->reject_when(
			static function ( string $bytes, array $metadata ): bool {
				$type = strtolower( (string) ( $metadata['content_type'] ?? '' ) );
				return ( str_starts_with( $type, 'text/html' ) || str_starts_with( $type, 'application/xhtml+xml' ) ) && 'error' === ( Static_Site_Importer_URL_Fetcher::html_source_diagnostic( $bytes )['severity'] ?? '' );
			}
		);
		$cache->adopt_legacy( trailingslashit( $work_dir ) . 'url-response-cache-' . $identity );
		$cache->adopt_legacy( $workspace->directory() . '/responses' );
		$source_fetcher = $fetcher ?? static fn ( string $resource_url, array $fetch_args ) => Static_Site_Importer_URL_Fetcher::fetch( $resource_url, $fetch_args );
		if ( null !== $deadline ) {
			$source         = $source_fetcher;
			$source_fetcher = static function ( string $resource_url, array $fetch_args ) use ( $source, $clock, $deadline ) {
				if ( self::deadline_reached( $deadline, $clock ) ) {
					return new WP_Error( 'static_site_importer_invocation_deadline_exceeded', 'The URL batch invocation deadline was reached before starting a network fetch.' );
				}
				$fetch_args['deadline'] = $deadline;
				$fetch_args['clock']    = $clock;
				$result                 = $source( $resource_url, $fetch_args );
				if ( is_wp_error( $result ) && 'static_site_importer_url_deadline_exhausted' === $result->get_error_code() ) {
					return new WP_Error( 'static_site_importer_invocation_deadline_exceeded', 'The URL batch invocation deadline was exhausted during a network fetch.' );
				}
				return $result;
			};
		}
		$fetcher      = self::cached_fetcher( $cache, $source_fetcher );
		$run_manifest = new Static_Site_Importer_Artifact_Run_Manifest( $manifest_path, $identity, 'static-site-importer/url-site-batch-run/v1', $contract );
		$manifest     = $run_manifest->load();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}if ( ! empty( $manifest ) ) {
			$manifest['fetch_cache'] = self::cache_counters( $manifest['fetch_cache'] ?? array() );
		}
		if ( empty( $manifest ) ) {
			$manifest = array(
				'schema'                  => 'static-site-importer/url-site-batch-run/v1',
				'version'                 => self::VERSION,
				'source'                  => array(
					'url'      => $url,
					'identity' => $identity,
				),
				'contract'                => $contract,
				'discovery_limits'        => Static_Site_Importer_URL_Site_Collector::discovery_limits(),
				'per_batch_limits'        => array(
					'max_pages'          => min( self::MAX_BATCH_PAGES, $batch_pages ),
					'max_assets'         => min( 2000, max( 0, (int) $args['max_assets'] ) ),
					'max_total_bytes'    => min( 268435456, max( 1, (int) $args['max_total_bytes'] ) ),
					'max_response_bytes' => 10485760,
				),
				'total_routes'            => 0,
				'routes'                  => array(),
				'batch_pages'             => min( self::MAX_BATCH_PAGES, $batch_pages ),
				'batches'                 => array(),
				'failures'                => array(),
				'diagnostics'             => array(),
				'external_asset_retained' => array(
					'count'   => 0,
					'samples' => array(),
				),
				'fetch_cache'             => self::cache_counters( array() ),
				'state'                   => 'running',
				'phase'                   => 'discovering_routes',
				'progress'                => array(
					'phase'      => 'discovering_routes',
					'updated_at' => gmdate( 'c' ),
				),
			);
			$write    = $run_manifest->save( $manifest );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
		}
		if ( 'discovering_routes' === ( $manifest['phase'] ?? '' ) ) {
			$routes = Static_Site_Importer_URL_Site_Collector::discover_routes( $url, $args, $fetcher );
			if ( is_wp_error( $routes ) ) {
				self::checkpoint_cache( $manifest, $cache );
				if ( self::deadline_error( $routes ) ) {
					$manifest['progress']['updated_at'] = gmdate( 'c' );
					$write                              = $run_manifest->save( $manifest );
					if ( is_wp_error( $write ) ) {
						return $write;
					}
					return self::continuation_result( $manifest, $manifest_path, null, 0, $max_effective_batches, $max_invocation_seconds, 'deadline_exhausted' );
				}
				$manifest['failures'][] = array(
					'phase'   => 'discovering_routes',
					'code'    => $routes->get_error_code(),
					'message' => $routes->get_error_message(),
					'at'      => gmdate( 'c' ),
				);
				$run_manifest->save( $manifest );
				return $routes;
			}$routes = self::ordered_routes( $url, $routes );
			if ( empty( $routes ) ) {
				$routes = array( $url );
			}$cursor                  = Static_Site_Importer_Artifact_Batch_Cursor::create( array_keys( $routes ), min( self::MAX_BATCH_PAGES, $batch_pages ) );
			$manifest['total_routes'] = count( $routes );
			$manifest['routes']       = $routes;
			$manifest['batches']      = self::legacy_batches( $cursor );
			$manifest['phase']        = 'importing_batches';
			$manifest['progress']     = array(
				'phase'      => 'importing_batches',
				'updated_at' => gmdate( 'c' ),
			);
			self::checkpoint_cache( $manifest, $cache );
			if ( is_wp_error( $run_manifest->save( $manifest ) ) ) {
				return $run_manifest->save( $manifest );
			}
		}
		if ( 'completed' === ( $manifest['state'] ?? '' ) && is_array( $manifest['final_result'] ?? null ) ) {
			$manifest['final_result']['url_batch_run']['fetch_cache'] = $manifest['fetch_cache'];
			$cache->cleanup_adopted();
			return $manifest['final_result'];
		}$importer         = $importer ?? static fn ( array $artifact, array $import_args ) => Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
		$shared_plan       = new Static_Site_Importer_Shared_Resource_Plan( $workspace );
		$payload_reader    = self::payload_reader( $workspace );
		$cursor            = Static_Site_Importer_Artifact_Batch_Cursor::hydrate( $manifest['batches'] );
		$effective_batches = 0;
		while ( true ) {
			$index = Static_Site_Importer_Artifact_Batch_Cursor::next( $cursor );
			if ( null === $index ) {
				break;
			}
			if ( null !== $max_effective_batches && $effective_batches >= $max_effective_batches ) {
				$manifest['batches'] = self::legacy_batches( $cursor );
				self::checkpoint_cache( $manifest, $cache );
				if ( is_wp_error( $run_manifest->save( $manifest ) ) ) {
					return $run_manifest->save( $manifest );
				}return self::continuation_result( $manifest, $manifest_path, $index, $effective_batches, $max_effective_batches );
			}
			$batch                = $cursor[ $index ];
			$routes               = array_values( array_intersect_key( $manifest['routes'], array_flip( $batch['units'] ) ) );
			$batch_entry          = in_array( $url, $routes, true ) ? $url : ( $routes[0] ?? $url );
			$cache_name           = 'batches/' . $batch['batch_id'] . '.json';
			$ready_cache_name     = 'batches/' . $batch['batch_id'] . '.page-ready.json';
			$old_cache            = trailingslashit( $work_dir ) . 'url-site-batch-cache-' . $identity . '-' . $index . '.json';
			$manifest['progress'] = array(
				'phase'       => 'collecting_batch',
				'batch_id'    => $batch['batch_id'],
				'batch_index' => $index,
				'route_count' => count( $routes ),
				'updated_at'  => gmdate( 'c' ),
			);
			$write                = $run_manifest->save( $manifest );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
			if ( null !== $deadline ) {
				$known_asset_paths = $shared_plan->source_paths();
				$ready_raw         = self::retained_runtime( $workspace, $ready_cache_name, $ready_cache_name, $ready_cache_name, $routes );
				$ready_runtime     = array();
				if ( is_string( $ready_raw ) && is_array( json_decode( $ready_raw, true ) ) ) {
					$ready_runtime = json_decode( $ready_raw, true );
				}
				if ( empty( $ready_runtime ) ) {
					$ready_args                                = $args;
					$ready_args['_route_set']                  = array_values( array_unique( $routes ) );
					$ready_args['_known_route_set']            = $manifest['routes'];
					$ready_args['max_pages']                   = min( self::MAX_BATCH_PAGES + 1, count( $ready_args['_route_set'] ) + 1 );
					$ready_args['require_complete_collection'] = true;
					$ready_args['asset_failure_policy']        = count( $routes ) > 1 ? 'preserve_failed_external_assets' : 'preserve_external';
					$ready_args['hydration_mode']              = 'page_ready';
					$ready_args['_static_site_importer_known_asset_paths'] = $known_asset_paths;
					$ready_runtime = Static_Site_Importer_URL_Site_Collector::collect( $batch_entry, $ready_args, $fetcher );
					if ( is_wp_error( $ready_runtime ) ) {
						if ( self::deadline_error( $ready_runtime ) ) {
							$manifest['batches'] = self::legacy_batches( $cursor );
							self::checkpoint_cache( $manifest, $cache );
							$write = $run_manifest->save( $manifest );
							if ( is_wp_error( $write ) ) {
								return $write;
							}
							return self::continuation_result( $manifest, $manifest_path, $index, $effective_batches, $max_effective_batches, $max_invocation_seconds, 'deadline_exhausted' );
						}
						return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $ready_runtime, $cache );
					}
					$write = $workspace->publish_json( $ready_cache_name, $ready_runtime );
					if ( is_wp_error( $write ) ) {
						return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $write, $cache );
					}
				}
				if ( 'page_ready' === $batch['state'] && ( $batch['result']['snapshot_sha256'] ?? '' ) !== ( $ready_runtime['source_metadata']['snapshot']['sha256'] ?? '' ) ) {
					return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, new WP_Error( 'static_site_importer_page_ready_checkpoint_mismatch', 'The immutable page-ready checkpoint no longer matches its persisted receipt.' ), $cache );
				}
				if ( 'page_ready' !== $batch['state'] && empty( $batch['page_ready_deferred'] ) && 'pending' === ( $ready_runtime['source_metadata']['collection']['readiness']['optional_assets'] ?? '' ) ) {
					$ready_import_args                                      = Static_Site_Importer_URL_Import_Runtime::batch_import_args( $input, $ready_runtime );
					$ready_import_args['activate']                          = false;
					$ready_import_args['batch_import']                      = true;
					$ready_import_args['preserve_existing_theme_bootstrap'] = $index > 0;
					$ready_import_args['import_run_id']                     = $identity;
					$ready_import_args['page_ready_checkpoint']             = true;
					$ready_result = $importer( $ready_runtime['artifact'], $ready_import_args );
					if ( is_wp_error( $ready_result ) ) {
						if ( 'static_site_importer_page_ready_runtime_bindings_deferred' === $ready_result->get_error_code() ) {
							$manifest['diagnostics'][]               = array(
								'code'     => 'page_ready_materialization_deferred',
								'batch_id' => $batch['batch_id'],
							);
							$cursor[ $index ]['page_ready_deferred'] = true;
							$batch                                   = $cursor[ $index ];
							$manifest['batches']                     = self::legacy_batches( $cursor );
							self::checkpoint_cache( $manifest, $cache );
							$write = $run_manifest->save( $manifest );
							if ( is_wp_error( $write ) ) {
								return $write;
							}
						} else {
							return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $ready_result, $cache );
						}
					} else {
						$cursor[ $index ]['state']  = 'page_ready';
						$cursor[ $index ]['result'] = self::result_evidence( $ready_result, $ready_runtime );
						$batch                      = $cursor[ $index ];
						$manifest['batches']        = self::legacy_batches( $cursor );
						self::checkpoint_cache( $manifest, $cache );
						$write = $run_manifest->save( $manifest );
						if ( is_wp_error( $write ) ) {
							return $write;
						}
					}
				}
			}
			$raw     = self::retained_runtime( $workspace, $cache_name, 'batches/' . $index . '.json', $old_cache, $routes );
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
			$runtime = is_array( $decoded ) ? $decoded : array();
			if ( empty( $runtime ) ) {
				$known_asset_paths                           = $shared_plan->source_paths();
				$collect_args                                = $args;
				$collect_args['_route_set']                  = array_values( array_unique( $routes ) );
				$collect_args['_known_route_set']            = $manifest['routes'];
				$collect_args['max_pages']                   = min( self::MAX_BATCH_PAGES + 1, count( $collect_args['_route_set'] ) + 1 );
				$collect_args['require_complete_collection'] = true;
				$collect_args['asset_failure_policy']        = count( $routes ) > 1 ? 'preserve_failed_external_assets' : 'preserve_external';
				$collect_args['_static_site_importer_collection_return_payload_references'] = null !== $payload_reader;
				$collect_args['_static_site_importer_known_asset_paths']                    = $known_asset_paths;
				$collection_cursor_name = 'batches/' . $batch['batch_id'] . '.collection-cursor.json';
				$collection_contract    = hash(
					'sha256',
					(string) wp_json_encode(
						array(
							'version'       => 2,
							'routes'        => $routes,
							'mode'          => 'complete_snapshot',
							'shared_digest' => hash( 'sha256', (string) wp_json_encode( $known_asset_paths, JSON_UNESCAPED_SLASHES ) ),
						)
					)
				);
				$collect_args           = array_merge(
					$collect_args,
					array(
						'_static_site_importer_collection_contract'      => $collection_contract,
						'_static_site_importer_collection_cursor_load'   => static function () use ( $workspace, $collection_cursor_name ) {
							$raw    = $workspace->read_raw( $collection_cursor_name );
							$cursor = is_string( $raw ) ? json_decode( $raw, true ) : null;
							if ( ! is_array( $cursor ) ) {
								return null;
							}
							return $cursor;
						},
						'_static_site_importer_collection_resource_load' => static function ( array $retained ) use ( $workspace ) {
							$body = isset( $retained['body_ref'] ) && is_string( $retained['body_ref'] ) ? $workspace->read_raw( $retained['body_ref'] ) : null;
							return is_string( $body ) && hash_equals( (string) ( $retained['sha256'] ?? '' ), hash( 'sha256', $body ) ) ? $body : null;
						},
						'_static_site_importer_collection_resource_store' => static fn ( string $body ) => self::store_collection_payload( $workspace, $body ),
						'_static_site_importer_collection_should_yield'  => null !== $deadline ? static fn (): bool => self::deadline_reached( $deadline, $clock ) : null,
						'_static_site_importer_collection_cursor_save'   => static function ( array $cursor ) use ( $workspace, $collection_cursor_name ) {
							return $workspace->publish_json( $collection_cursor_name, $cursor );
						},
					)
				);
				$runtime                = Static_Site_Importer_URL_Site_Collector::collect( $batch_entry, $collect_args, $fetcher );
				if ( is_wp_error( $runtime ) ) {
					if ( self::deadline_error( $runtime ) ) {
						$manifest['batches'] = self::legacy_batches( $cursor );
						self::checkpoint_cache( $manifest, $cache );
						$write = $run_manifest->save( $manifest );
						if ( is_wp_error( $write ) ) {
							return $write;
						}return self::continuation_result( $manifest, $manifest_path, $index, $effective_batches, $max_effective_batches, $max_invocation_seconds, 'deadline_exhausted' );
					}
					if ( count( $routes ) > 1 && self::splittable_collection_error( $runtime ) ) {
						$cursor              = Static_Site_Importer_Artifact_Batch_Cursor::split( $cursor, $index );
						$manifest['batches'] = self::legacy_batches( $cursor );
						self::checkpoint_cache( $manifest, $cache );
						$manifest['diagnostics'][] = array(
							'code'         => 'batch_subdivided',
							'parent_batch' => $batch['batch_id'],
							'children'     => array_column( array_slice( $cursor, $index, 2 ), 'batch_id' ),
						);
						$write                     = $run_manifest->save( $manifest );
						if ( is_wp_error( $write ) ) {
							return $write;
						}
						if ( null !== $max_effective_batches ) {
							return self::continuation_result( $manifest, $manifest_path, $index, $effective_batches, $max_effective_batches, $max_invocation_seconds, 'batch_subdivided' );
						}
						continue;
					}return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $runtime, $cache );
				}$write = $workspace->publish_json( $cache_name, $runtime );
				if ( is_wp_error( $write ) ) {
					return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $write, $cache );
				}
				$workspace->delete( $collection_cursor_name );
			}
			$staged = self::retained_staged_plans( $workspace, $runtime );
			if ( null === $staged ) {
				$shared_started = microtime( true );
				$shared         = $shared_plan->reconcile( $runtime['artifact'] );
				if ( is_wp_error( $shared['plan'] ) ) {
					return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $shared['plan'], $cache );
				}
				$runtime['shared_plan_digest'] = $shared['digest'];
				if ( ! empty( $shared['changed'] ) ) {
					self::invalidate_prepared_batches( $workspace, $cursor, $index );
					$workspace->delete( 'staged-compiler-shared.json' );
					$manifest['diagnostics'][] = array(
						'code'               => 'shared_resource_plan_changed',
						'shared_plan_digest' => $shared['digest'],
					);
				}
				$staged_artifact = $runtime['artifact'];
				$staged_paths    = array_fill_keys( array_column( $staged_artifact['files'] ?? array(), 'path' ), true );
				foreach ( $shared_plan->retained_resources() as $resource ) {
					if ( ! isset( $staged_paths[ $resource['path'] ?? '' ] ) ) {
						$staged_artifact['files'][] = $resource;
					}
				}
				$staged = self::prepare_staged_plans(
					$workspace,
					$staged_artifact,
					$shared['digest'],
					$payload_reader,
					'batches/' . $batch['batch_id'] . '.staged-pages.json',
					null !== $deadline ? static fn (): bool => self::deadline_reached( $deadline, $clock ) : null
				);
				if ( is_wp_error( $staged ) ) {
					if ( self::deadline_error( $staged ) ) {
						self::checkpoint_cache( $manifest, $cache );
						$write = $run_manifest->save( $manifest );
						if ( is_wp_error( $write ) ) {
							return $write;
						}
						return self::continuation_result( $manifest, $manifest_path, $index, $effective_batches, $max_effective_batches, $max_invocation_seconds, 'deadline_exhausted' );
					}
					return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $staged, $cache );
				}
				$runtime['shared_plan_digest'] = $shared['digest'];
				$runtime['staged_page_plans']  = $staged['page_plans'];
				$prepared_write                = $workspace->publish_json( $cache_name, $runtime );
				if ( is_wp_error( $prepared_write ) ) {
					return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $prepared_write, $cache );
				}
				$manifest['shared_resource_plan']                          = array(
					'schema'   => 'static-site-importer/shared-resource-plan/v1',
					'digest'   => $shared['digest'],
					'verified' => true,
				);
				$manifest['stage_timing']['shared_plan_seconds']           = (float) ( $manifest['stage_timing']['shared_plan_seconds'] ?? 0 ) + microtime( true ) - $shared_started;
				$manifest['stage_counters']['shared_plan_reconciliations'] = (int) ( $manifest['stage_counters']['shared_plan_reconciliations'] ?? 0 ) + 1;
				$manifest['stage_counters']['shared_plan_invalidations']   = (int) ( $manifest['stage_counters']['shared_plan_invalidations'] ?? 0 ) + ( ! empty( $shared['changed'] ) ? 1 : 0 );
				$manifest['stage_counters']['compiler_shared_prepares']    = (int) ( $manifest['stage_counters']['compiler_shared_prepares'] ?? 0 ) + ( $staged['shared_prepared'] ? 1 : 0 );
				$manifest['stage_counters']['compiler_page_prepares']      = (int) ( $manifest['stage_counters']['compiler_page_prepares'] ?? 0 ) + $staged['page_prepared'];
				$manifest['external_asset_retained']                       = self::merge_external_assets( $manifest['external_asset_retained'] ?? array(), $runtime['source_metadata']['collection']['external_asset_retained'] ?? array(), $index );
				self::checkpoint_cache( $manifest, $cache );
				$manifest['batches'] = self::legacy_batches( $cursor );
				if ( is_wp_error( $run_manifest->save( $manifest ) ) ) {
					return $run_manifest->save( $manifest );
				}
			}
			if ( null !== $deadline && self::deadline_reached( $deadline, $clock ) ) {
				self::checkpoint_cache( $manifest, $cache );
				$write = $run_manifest->save( $manifest );
				if ( is_wp_error( $write ) ) {
					return $write;
				}return self::continuation_result( $manifest, $manifest_path, $index, $effective_batches, $max_effective_batches, $max_invocation_seconds, 'deadline_exhausted' );
			}
			if ( 'plan' === (string) ( $input['operation'] ?? 'apply' ) ) {
				$result = array(
					'quality'     => array(),
					'diagnostics' => array(),
				);
			} else {
				$manifest['progress']['phase']      = 'materializing_batch';
				$manifest['progress']['updated_at'] = gmdate( 'c' );
				$write                              = $run_manifest->save( $manifest );
				if ( is_wp_error( $write ) ) {
					return $write;
				}
				$terminal         = array_key_last( $cursor ) === $index;
				if ( $terminal ) {
					// The terminal batch finalizes the whole run, so it composes every frozen page plan with the retained shared plan.
					$complete = self::compose_complete_plan( $workspace, $cursor, $payload_reader );
					if ( is_wp_error( $complete ) ) {
						return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $complete, $cache );
					}
					$compiled_staged = $complete['compiled_artifact_result'];
				} else {
					$compiled_staged = self::compose_staged_plans( $staged, $payload_reader );
					if ( is_wp_error( $compiled_staged ) ) {
						return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $compiled_staged, $cache );
					}
				}
				$manifest['stage_counters']['compiler_compositions'] = (int) ( $manifest['stage_counters']['compiler_compositions'] ?? 0 ) + 1;
				$import_args                                      = Static_Site_Importer_URL_Import_Runtime::batch_import_args( $input, $runtime );
				$import_args['activate']                          = $terminal && ! empty( $input['activate'] );
				$import_args['batch_import']                      = true;
				$import_args['preserve_existing_theme_bootstrap'] = $index > 0;
				$import_args['import_run_id']                     = $identity;
				$import_args['compiled_artifact_result']          = $compiled_staged;
				$import_args['_static_site_importer_precompiled_source'] = null !== $payload_reader;
				// This reader is invocation-local workspace access, never plan state.
				$import_args['_static_site_importer_payload_reader'] = $payload_reader;
				$result = $importer( $runtime['artifact'], $import_args );
				if ( is_wp_error( $result ) ) {
					return self::failed( $run_manifest, $workspace, $manifest, $cursor, $index, $result, $cache );
				}
			}
			$cursor                     = Static_Site_Importer_Artifact_Batch_Cursor::complete( $cursor, $index );
			$cursor[ $index ]['result'] = self::result_evidence( $result, $runtime );
			$manifest['batches']        = self::legacy_batches( $cursor );
			$manifest['diagnostics']    = array_slice( array_merge( $manifest['diagnostics'], $result['import_validation_result']['diagnostics'] ?? array() ), -100 );
			$manifest['progress']       = array(
				'phase'      => 'importing_batches',
				'updated_at' => gmdate( 'c' ),
			);
			if ( is_wp_error( $run_manifest->save( $manifest ) ) ) {
				return $run_manifest->save( $manifest );
			}
			// Keep verified prepared input until terminal cleanup for interruption recovery.
			if ( is_file( $old_cache ) ) {
				self::delete_legacy_file( $old_cache );
			}
			++$effective_batches;
			$final = $result;
			unset( $result, $runtime, $raw );
		}
		if ( 'plan' === (string) ( $input['operation'] ?? 'apply' ) ) {
			$final = self::compose_complete_plan( $workspace, $cursor, $payload_reader );
			if ( is_wp_error( $final ) ) {
				return $final;
			}
			$manifest['stage_counters']['compiler_compositions'] = (int) ( $manifest['stage_counters']['compiler_compositions'] ?? 0 ) + 1;
		}
		$manifest['batches']      = self::legacy_batches( $cursor );
		$aggregate                = self::aggregate_result( $manifest, $manifest_path, $final ?? array() );
		$manifest['state']        = 'completed';
		$manifest['phase']        = 'completed';
		$manifest['progress']     = array(
			'phase'      => 'completed',
			'updated_at' => gmdate( 'c' ),
		);
		$manifest['completed_at'] = gmdate( 'c' );
		self::checkpoint_cache( $manifest, $cache );
		$legacy_cleanup                                     = $cache->cleanup_adopted();
		$aggregate['url_batch_run']['cleanup']              = $workspace->cleanup( 'success' );
		$aggregate['url_batch_run']['legacy_cache_cleanup'] = $legacy_cleanup;
		$manifest['final_result']                           = $aggregate;
		if ( is_wp_error( $run_manifest->save( $manifest ) ) ) {
			return $run_manifest->save( $manifest );
		}
		return $aggregate;
	}
	/**
	 * Persist the Blocks Engine staged envelopes. The shared envelope is created
	 * once per retained resource digest; page envelopes remain batch-local.
	 */
	private static function prepare_staged_plans( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $artifact, string $resource_digest, ?object $payload_reader = null, string $page_checkpoint = '', ?callable $should_yield = null ): array|WP_Error {
		$compiler = self::staged_compiler();
		if ( is_wp_error( $compiler ) ) {
			return $compiler;
		}
		$stored          = $workspace->read_raw( 'staged-compiler-shared.json' );
		$stored          = is_string( $stored ) ? json_decode( $stored, true ) : null;
		$shared_prepared = ! is_array( $stored ) || ( $stored['resource_digest'] ?? null ) !== $resource_digest || ! is_array( $stored['plan'] ?? null );
		try {
			$prepare_shared = array( $compiler, 'prepareShared' );
			$prepare_page   = array( $compiler, 'preparePage' );
			if ( ! is_callable( $prepare_shared ) || ! is_callable( $prepare_page ) ) {
				return new WP_Error( 'static_site_importer_missing_transformer_capability', 'The Blocks Engine php-transformer does not support staged URL batch plans.' );
			}
			$shared = $shared_prepared ? call_user_func( $prepare_shared, $artifact, $payload_reader ) : $stored['plan'];
			if ( $shared_prepared ) {
				$write = $workspace->publish_json(
					'staged-compiler-shared.json',
					array(
						'resource_digest' => $resource_digest,
						'plan'            => $shared,
					)
				);
				if ( is_wp_error( $write ) ) {
					return $write;
				}
			}
			$page_contract = hash( 'sha256', $resource_digest . "\n" . (string) wp_json_encode( $artifact, JSON_UNESCAPED_SLASHES ) );
			$page_state    = '' !== $page_checkpoint ? $workspace->read_raw( $page_checkpoint ) : null;
			$page_state    = is_string( $page_state ) ? json_decode( $page_state, true ) : null;
			$page_plans    = is_array( $page_state ) && hash_equals( $page_contract, (string) ( $page_state['contract'] ?? '' ) ) && is_array( $page_state['plans'] ?? null ) ? $page_state['plans'] : array();
			$page_prepared = 0;
			foreach ( $artifact['files'] ?? array() as $file ) {
				if ( ! is_array( $file ) || 'text/html' !== strtolower( (string) ( $file['mime_type'] ?? '' ) ) || '' === (string) ( $file['path'] ?? '' ) ) {
					continue;
				}
				$page_id = (string) $file['path'];
				if ( isset( $page_plans[ $page_id ] ) && is_array( $page_plans[ $page_id ] ) ) {
					continue;
				}
				if ( null !== $should_yield && call_user_func( $should_yield ) ) {
					return new WP_Error( 'static_site_importer_invocation_deadline_exceeded', 'The URL batch invocation deadline was reached during staged page preparation.' );
				}
				$page_plans[ $page_id ] = call_user_func( $prepare_page, $artifact, $shared, $page_id, $payload_reader );
				++$page_prepared;
				if ( '' !== $page_checkpoint ) {
					$write = $workspace->publish_json(
						$page_checkpoint,
						array(
							'contract' => $page_contract,
							'plans'    => $page_plans,
						)
					);
					if ( is_wp_error( $write ) ) {
						return $write;
					}
				}
			}
			ksort( $page_plans, SORT_STRING );
			return array(
				'shared_plan'     => $shared,
				'page_plans'      => array_values( $page_plans ),
				'page_prepared'   => $page_prepared,
				'shared_prepared' => $shared_prepared,
			);
		} catch ( Throwable $error ) {
			return new WP_Error( 'static_site_importer_staged_compile_failed', $error->getMessage() );
		}
	}
	private static function retained_staged_plans( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $runtime ): ?array {
		$digest     = $runtime['shared_plan_digest'] ?? null;
		$page_plans = $runtime['staged_page_plans'] ?? null;
		if ( ! is_string( $digest ) || '' === $digest || ! is_array( $page_plans ) ) {
			return null;
		}
		$shared_raw   = $workspace->read_raw( 'staged-compiler-shared.json' );
		$shared_state = is_string( $shared_raw ) ? json_decode( $shared_raw, true ) : null;
		if ( ! is_array( $shared_state ) || ( $shared_state['resource_digest'] ?? null ) !== $digest || ! is_array( $shared_state['plan'] ?? null ) ) {
			return null;
		}
		return array(
			'shared_plan'     => $shared_state['plan'],
			'page_plans'      => $page_plans,
			'shared_prepared' => false,
		);
	}
	private static function compose_staged_plans( array $staged, ?object $payload_reader = null ): array|WP_Error {
		$compiler = self::staged_compiler();
		if ( is_wp_error( $compiler ) ) {
			return $compiler;
		}
		try {
			$compose = array( $compiler, 'compose' );
			if ( ! is_callable( $compose ) ) {
				return new WP_Error( 'static_site_importer_missing_transformer_capability', 'The Blocks Engine php-transformer does not support staged URL batch plans.' );
			}
			$compiled = call_user_func( $compose, $staged['shared_plan'], $staged['page_plans'], $payload_reader );
			if ( ! is_object( $compiled ) || ! is_callable( array( $compiled, 'toArray' ) ) ) {
				return new WP_Error( 'static_site_importer_invalid_staged_compile', 'The Blocks Engine php-transformer returned an invalid staged URL batch plan.' );
			}
			return call_user_func( array( $compiled, 'toArray' ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'static_site_importer_staged_compose_failed', $error->getMessage() );
		}
	}

	private static function payload_reader( Static_Site_Importer_Artifact_Run_Workspace $workspace ): ?object {
		$interface = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\PayloadReader';
		if ( ! interface_exists( $interface ) ) {
			return null;
		}
		return new class( $workspace ) implements \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader {
			public function __construct( private Static_Site_Importer_Artifact_Run_Workspace $workspace ) {}
			public function read( array $reference ): string {
				$bytes = $this->workspace->read_raw( (string) ( $reference['id'] ?? '' ) );
				if ( ! is_string( $bytes ) ) {
					throw new RuntimeException( 'A retained artifact payload is unavailable.' );
				}
				return $bytes;
			}
		};
	}

	/** @return array{body_ref:string,sha256:string,bytes:int}|WP_Error */
	private static function store_collection_payload( Static_Site_Importer_Artifact_Run_Workspace $workspace, string $body ) {
		$hash = hash( 'sha256', $body );
		$ref  = 'collection-payloads/' . $hash . '.bin';
		$path = $workspace->path( $ref );
		if ( is_string( $path ) && is_file( $path ) ) {
			$existing = $workspace->read_raw( $ref );
			if ( ! is_string( $existing ) || ! hash_equals( $hash, hash( 'sha256', $existing ) ) ) {
				return new WP_Error( 'static_site_importer_collection_payload_corrupt', 'A retained URL collection payload does not match its content-addressed path.', array( 'ref' => $ref ) );
			}
		} else {
			$write = $workspace->publish_raw( $ref, $body );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
		}

		return array(
			'body_ref' => $ref,
			'sha256'   => $hash,
			'bytes'    => strlen( $body ),
		);
	}

	/**
	 * Compose every frozen batch page plan once after terminal URL acquisition.
	 *
	 * @param Static_Site_Importer_Artifact_Run_Workspace $workspace Frozen batch workspace.
	 * @param array<int,array<string,mixed>>              $cursor    Completed batch cursor.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function compose_complete_plan( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $cursor, ?object $payload_reader = null ): array|WP_Error {
		$shared_raw   = $workspace->read_raw( 'staged-compiler-shared.json' );
		$shared_state = is_string( $shared_raw ) ? json_decode( $shared_raw, true ) : null;
		$shared_plan  = is_array( $shared_state ) && is_array( $shared_state['plan'] ?? null ) ? $shared_state['plan'] : null;
		if ( ! is_array( $shared_plan ) ) {
			return new WP_Error( 'static_site_importer_url_plan_shared_missing', 'The frozen URL run has no shared compiler plan.' );
		}

		$current_digest = (string) ( $shared_plan['digest'] ?? '' );
		$compiler       = self::staged_compiler();
		if ( is_wp_error( $compiler ) ) {
			return $compiler;
		}
		$prepare_page_callable = array( $compiler, 'preparePage' );
		if ( ! is_callable( $prepare_page_callable ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer_capability', 'The Blocks Engine php-transformer does not support staged URL batch plans.' );
		}

		$page_plans = array();
		$snapshots  = array();
		$files      = array();
		$entrypoint = '';
		foreach ( $cursor as $batch ) {
			$batch_id = (string) ( $batch['batch_id'] ?? '' );
			$raw      = '' !== $batch_id ? $workspace->read_raw( 'batches/' . $batch_id . '.json' ) : null;
			$runtime  = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( ! is_array( $runtime ) || ! is_array( $runtime['staged_page_plans'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_url_plan_batch_missing', 'The frozen URL run has an incomplete staged batch.' );
			}
			$snapshot = $runtime['source_metadata']['snapshot']['sha256'] ?? null;
			if ( is_string( $snapshot ) && '' !== $snapshot ) {
				$snapshots[] = $snapshot;
			}
			if ( is_array( $runtime['artifact'] ?? null ) ) {
				$entrypoint = '' === $entrypoint ? (string) ( $runtime['artifact']['entrypoint'] ?? '' ) : $entrypoint;
				foreach ( $runtime['artifact']['files'] ?? array() as $file ) {
					if ( is_array( $file ) && '' !== (string) ( $file['path'] ?? '' ) ) {
						$files[ (string) $file['path'] ] = $file;
					}
				}
			}

			// The binding check lives in the compiler plan domain: each staged page plan carries the
			// shared compiler plan digest it was prepared against, and the vendor composer rejects
			// page plans that bind to a different shared plan. A mismatch (or a missing binding)
			// means the shared plan was rebuilt since the batch was staged, so the frozen page
			// plans must be reprepared against the plan that is actually being composed.
			$batch_binding = (string) ( $runtime['staged_page_plans'][0]['shared_digest'] ?? '' );

			if ( '' === $batch_binding || ! hash_equals( $current_digest, $batch_binding ) ) {
				if ( ! is_array( $runtime['artifact']['files'] ?? null ) ) {
					return new WP_Error( 'static_site_importer_url_plan_batch_artifact_missing', 'The frozen URL run cannot reprepare a stale staged batch without its retained artifact.' );
				}
				$fresh_plans = array();
				foreach ( $runtime['artifact']['files'] as $file ) {
					if ( ! is_array( $file ) || 'text/html' !== strtolower( (string) ( $file['mime_type'] ?? '' ) ) || '' === (string) ( $file['path'] ?? '' ) ) {
						continue;
					}
					try {
						$fresh_plans[] = call_user_func( $prepare_page_callable, $runtime['artifact'], $shared_plan, (string) $file['path'], $payload_reader );
					} catch ( Throwable $error ) {
						return new WP_Error( 'static_site_importer_staged_reprepare_failed', $error->getMessage() );
					}
				}
				$page_plans = array_merge( $page_plans, $fresh_plans );
			} else {
				$page_plans = array_merge( $page_plans, $runtime['staged_page_plans'] );
			}
		}

		$compiled = self::compose_staged_plans(
			array(
				'shared_plan' => $shared_plan,
				'page_plans'  => $page_plans,
			),
			$payload_reader
		);
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		$plan = $compiled['source_reports']['wordpress_site_plan'] ?? null;
		if ( ! is_array( $plan ) ) {
			return new WP_Error( 'static_site_importer_url_plan_missing', 'The frozen URL run did not compose a canonical WordPress site plan.' );
		}

		return array(
			'compiled_artifact_result' => $compiled,
			'plan'                     => $plan,
			'quality'                  => is_array( $plan['quality'] ?? null ) ? $plan['quality'] : array(),
			'diagnostics'              => is_array( $plan['diagnostics'] ?? null ) ? $plan['diagnostics'] : array(),
			'provenance'               => array(
				'snapshot_identity' => hash( 'sha256', (string) wp_json_encode( $snapshots ) ),
				'snapshots'         => $snapshots,
			),
			'artifact'                 => array(
				'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
				'entrypoint' => $entrypoint,
				'files'      => array_values( $files ),
			),
		);
	}

	private static function staged_compiler(): mixed {
		$compiler_class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		if ( ! class_exists( $compiler_class ) ) {
			return new WP_Error( 'static_site_importer_missing_transformer', 'Blocks Engine php-transformer is required to prepare staged URL batch plans.' );
		}
		return new $compiler_class();
	}
	private static function cached_fetcher( Static_Site_Importer_Artifact_Byte_Cache $cache, ?callable $fetcher ): callable {
		$fetcher = $fetcher ?? static fn ( string $url, array $args ) => Static_Site_Importer_URL_Fetcher::fetch( $url, $args );
		return static function ( string $url, array $args ) use ( $cache, $fetcher ) {
			$types = isset( $args['content_types'] ) && is_array( $args['content_types'] ) ? array_values( $args['content_types'] ) : null;
			if ( is_array( $types ) ) {
				sort( $types );
			}$key = hash(
				'sha256',
				$url . "\n" . wp_json_encode(
					array(
						'max_bytes'     => $args['max_bytes'] ?? null,
						'content_types' => $types,
						'timeout'       => $args['timeout'] ?? null,
					)
				)
			);
			$now  = isset( $args['_static_site_importer_negative_cache_now'] ) && is_callable( $args['_static_site_importer_negative_cache_now'] ) ? (int) call_user_func( $args['_static_site_importer_negative_cache_now'] ) : time();
			if ( isset( $args['_static_site_importer_cache_failure'] ) && $args['_static_site_importer_cache_failure'] instanceof WP_Error ) {
				$error = $args['_static_site_importer_cache_failure'];
				if ( self::cacheable_failure( $error ) ) {
					$data      = $error->get_error_data();
					$transient = self::transient_failure( $error );
					$cache->put_failure(
						$key,
						array(
							'code'    => $error->get_error_code(),
							'message' => $error->get_error_message(),
							'data'    => $data,
						),
						$transient ? $now + 30 : null
					);
				}return $error;
			}$failure = $cache->get_failure( $key, $now );
			if ( is_array( $failure ) ) {
				return new WP_Error( (string) $failure['code'], (string) $failure['message'], $failure['data'] ?? null );
			}$cached = $cache->get( $key );
			if ( is_array( $cached ) ) {
				$cache->hit();
				$cache->network_avoided();
				return array(
					'body'     => $cached['bytes'],
					'metadata' => $cached['value'] + array( '_static_site_importer_cache_hit' => true ),
				);
			}$cache->miss();
			$response = $fetcher( $url, $args );
			if ( is_wp_error( $response ) ) {
				$data                                      = is_array( $response->get_error_data() ) ? $response->get_error_data() : array();
				$data['_static_site_importer_cache_aware'] = true;
				return new WP_Error( $response->get_error_code(), $response->get_error_message(), $data );
			}if ( is_array( $response ) && is_string( $response['body'] ?? null ) && is_array( $response['metadata'] ?? null ) ) {
				$cache->put( $key, $response['body'], $response['metadata'] );
			}return $response;
		};
	}
	private static function cacheable_failure( WP_Error $error ): bool {
		$code = (string) $error->get_error_code();
		if ( str_contains( $code, 'invalid' ) || str_contains( $code, 'private' ) || str_contains( $code, 'credential' ) || str_contains( $code, 'scheme' ) ) {
			return false;
		}$status = is_array( $error->get_error_data() ) ? (int) ( $error->get_error_data()['status'] ?? 0 ) : 0;
		return self::transient_failure( $error ) || in_array( $code, array( 'static_site_importer_url_unexpected_content_type', 'static_site_importer_url_empty_body', 'static_site_importer_url_too_large' ), true ) || ( 'static_site_importer_url_http_status' === $code && in_array( $status, array( 404, 410 ), true ) );
	}
	private static function transient_failure( WP_Error $error ): bool {
		$code = strtolower( (string) $error->get_error_code() );
		return str_contains( $code, 'timeout' ) || str_contains( $code, 'connect' ) || str_contains( $code, 'tls' ) || str_contains( $code, 'dns' );
	}
	private static function legacy_batches( array $cursor ): array {
		return array_map(
			static fn ( array $row ): array => array_filter(
				array(
					'index'                => $row['index'],
					'batch_id'             => $row['batch_id'],
					'route_indexes'        => $row['units'],
					'state'                => $row['state'],
					'completed_routes'     => $row['completed_units'],
					'result'               => $row['result'] ?? null,
					'split_from'           => $row['split_from'] ?? null,
					'effective_batch_size' => $row['effective_batch_size'] ?? null,
					'page_ready_deferred'  => ! empty( $row['page_ready_deferred'] ) ? true : null,
				),
				static fn ( $value ): bool => null !== $value
			),
			$cursor
		);
	}
	private static function failed( Static_Site_Importer_Artifact_Run_Manifest $run_manifest, Static_Site_Importer_Artifact_Run_Workspace $workspace, array $manifest, array $cursor, int $index, WP_Error $error, Static_Site_Importer_Artifact_Byte_Cache $cache ): WP_Error {
		$cursor              = Static_Site_Importer_Artifact_Batch_Cursor::fail( $cursor, $index );
		$manifest['state']   = 'failed';
		$manifest['batches'] = self::legacy_batches( $cursor );
		self::checkpoint_cache( $manifest, $cache );
		$manifest['failures'][] = array(
			'batch'   => $index,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'at'      => gmdate( 'c' ),
		);
		$write                  = $run_manifest->save( $manifest );
		$data                   = array_merge(
			is_array( $error->get_error_data() ) ? $error->get_error_data() : array(),
			array(
				'run_manifest' => $run_manifest->path(),
				'run'          => $manifest,
				'cleanup'      => $workspace->cleanup( 'failure' ),
			)
		);
		if ( is_wp_error( $write ) ) {
			$data['checkpoint_error'] = array(
				'code'    => $write->get_error_code(),
				'message' => $write->get_error_message(),
			);
		}return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
	private static function checkpoint_cache( array &$manifest, Static_Site_Importer_Artifact_Byte_Cache $cache ): void {
		foreach ( $cache->consume() as $key => $delta ) {
			$manifest['fetch_cache'][ $key ] = (int) ( $manifest['fetch_cache'][ $key ] ?? 0 ) + (int) $delta;
		}
	}
	private static function cache_counters( array $counters ): array {
		foreach ( array( 'hits', 'misses', 'bytes_read', 'bytes_written', 'corrupt_entries', 'bypassed', 'negative_hits', 'negative_writes', 'negative_expired', 'network_requests_avoided' ) as $key ) {
			$counters[ $key ] = (int) ( $counters[ $key ] ?? 0 );
		}return $counters;
	}
	private static function retained_runtime( Static_Site_Importer_Artifact_Run_Workspace $workspace, string $stable, string $indexed, string $legacy, array $routes ): ?string {
		$raw = $workspace->read_raw( $stable );
		if ( is_string( $raw ) && self::owns_runtime( $raw, $routes ) ) {
			return $raw;
		}if ( is_string( $raw ) ) {
			$workspace->delete( $stable );
		}foreach ( array( $indexed, $legacy ) as $source ) {
			if ( 'batches/' === substr( $source, 0, 8 ) ) {
				$candidate = $workspace->read_raw( $source );
			} elseif ( is_file( $source ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned legacy batch artifact.
				$candidate = file_get_contents( $source );
			} else {
				$candidate = null;
			}
			if ( ! is_string( $candidate ) || ! self::owns_runtime( $candidate, $routes ) ) {
				continue;
			}$published = $workspace->publish_raw( $stable, $candidate );
			if ( is_wp_error( $published ) || $workspace->read_raw( $stable ) !== $candidate ) {
				continue;
			}if ( $source === $indexed ) {
				$workspace->delete( $indexed );
			} elseif ( is_file( $source ) ) {
				self::delete_legacy_file( $source );
			}return $candidate;
		}return null;
	}
	private static function invalidate_prepared_batches( Static_Site_Importer_Artifact_Run_Workspace $workspace, array $cursor, int $from ): void {
		foreach ( $cursor as $index => $batch ) {
			if ( $index >= $from && 'completed' !== ( $batch['state'] ?? '' ) ) {
				$workspace->delete( 'batches/' . $batch['batch_id'] . '.json' );
			}
		}
	}
	private static function owns_runtime( string $raw, array $routes ): bool {
		$runtime = json_decode( $raw, true );
		$files   = $runtime['source_metadata']['snapshot']['files'] ?? null;
		if ( ! is_array( $files ) ) {
			return false;
		}$actual = array();
		foreach ( $files as $file ) {
			if ( 'text/html' === strtolower( (string) ( $file['mime_type'] ?? '' ) ) && is_string( $file['source_url'] ?? null ) ) {
				$actual[] = self::page_key( $file['source_url'] );
			}
		}$explicit = array();
		foreach ( $runtime['artifact']['files'] ?? array() as $file ) {
			if ( 'text/html' !== strtolower( (string) ( $file['mime_type'] ?? '' ) ) ) {
				continue;
			}$route = (string) ( $file['metadata']['route_path'] ?? '' );
			if ( '' !== $route && isset( $explicit[ $route ] ) ) {
				return false;
			}$explicit[ $route ] = true;
		}$page_aliases = array();
		foreach ( is_array( $runtime['source_metadata']['collection']['page_aliases'] ?? null ) ? $runtime['source_metadata']['collection']['page_aliases'] : array() as $requested => $final ) {
			if ( is_string( $requested ) && is_string( $final ) ) {
				$page_aliases[ self::page_key( $requested ) ] = self::page_key( $final );
			}
		}
		$expected = array_map(
			static function ( string $route ) use ( $page_aliases ): string {
				$key = self::page_key( $route );
				return $page_aliases[ $key ] ?? $key;
			},
			$routes
		);
		sort( $actual );
		sort( $expected );
		return array_values( array_unique( $expected ) ) === $actual;
	}
	private static function page_key( string $url ): string {
		$parts = self::url_parts( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}$path = rtrim( (string) ( $parts['path'] ?? '/' ), '/' );
		if ( '' === $path || '/index.html' === $path || '/index.htm' === $path ) {
			$path = '/';
		}return strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) $parts['host'] ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
	private static function existing_manifest( string $path ): ?array {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an importer-owned URL batch manifest.
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) && is_array( $data['contract'] ?? null ) && is_string( $data['source']['identity'] ?? null ) ? array(
			'contract' => $data['contract'],
			'identity' => $data['source']['identity'],
		) : null;
	}
	private static function ordered_routes( string $entry, array $routes ): array {
		$routes[] = $entry;
		$routes   = array_values( array_unique( array_filter( $routes, 'is_string' ) ) );
		usort(
			$routes,
			static function ( string $a, string $b ): int {
				$depth_comparison = substr_count( trim( (string) self::url_parts( $a, PHP_URL_PATH ), '/' ), '/' ) <=> substr_count( trim( (string) self::url_parts( $b, PHP_URL_PATH ), '/' ), '/' );
				return 0 !== $depth_comparison ? $depth_comparison : strcmp( $a, $b );
			}
		);
		return $routes;
	}
	private static function delete_legacy_file( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes this verified importer-owned legacy cache path exactly; wp_delete_file filters could redirect it.
		return unlink( $path );
	}
	private static function url_parts( string $url, int $component = -1 ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return -1 === $component ? wp_parse_url( $url ) : wp_parse_url( $url, $component );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone smoke tests run without WordPress URL helpers.
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
	private static function splittable_collection_error( WP_Error $error ): bool {
		$data = $error->get_error_data();
		if ( 'static_site_importer_site_collection_incomplete' !== $error->get_error_code() || ! is_array( $data ) ) {
			return false;
		}if ( array_intersect( $data['collection']['truncated'] ?? array(), array( 'assets', 'bytes' ) ) ) {
			return true;
		}foreach ( $data['collection']['failures'] ?? array() as $failure ) {
			if ( 'asset' === ( $failure['kind'] ?? '' ) ) {
				return true;
			}
		}return false;
	}
	private static function deadline_error( WP_Error $error ): bool {
		if ( 'static_site_importer_invocation_deadline_exceeded' === $error->get_error_code() ) {
			return true;
		}$data = $error->get_error_data();
		foreach ( is_array( $data ) ? ( $data['collection']['failures'] ?? array() ) : array() as $failure ) {
			if ( 'static_site_importer_invocation_deadline_exceeded' === ( $failure['code'] ?? '' ) ) {
				return true;
			}
		}return false;
	}
	private static function deadline_reached( float $deadline, callable $clock ): bool {
		return (float) call_user_func( $clock ) >= $deadline;
	}
	private static function result_evidence( array $result, array $runtime ): array {
		return array(
			'theme_slug'                 => $result['theme_slug'] ?? '',
			'snapshot_sha256'            => $runtime['source_metadata']['snapshot']['sha256'] ?? '',
			'plan_hash'                  => $result['materialization_receipt']['plan_hash'] ?? '',
			'terminal_batch_report_path' => $result['report_path'] ?? '',
			'quality'                    => self::quality_evidence( $result['quality'] ?? ( $result['import_report_summary']['quality_pass'] ?? null ) ),
		);
	}
	private static function quality_evidence( mixed $quality ): mixed {
		if ( ! is_array( $quality ) ) {
			return is_bool( $quality ) ? array( 'pass' => $quality ) : null;
		}return array_filter(
			array(
				'pass'           => isset( $quality['pass'] ) ? (bool) $quality['pass'] : null,
				'status'         => isset( $quality['status'] ) ? (string) $quality['status'] : null,
				'metrics'        => is_array( $quality['metrics'] ?? null ) ? $quality['metrics'] : array(),
				'fallback_count' => is_array( $quality['fallbacks'] ?? null ) ? count( $quality['fallbacks'] ) : (int) ( $quality['fallback_count'] ?? 0 ),
			),
			static fn ( $value ): bool => null !== $value
		);
	}
	private static function merge_external_assets( array $aggregate, array $current, int $batch ): array {
		$samples = $aggregate['samples'] ?? array();
		$seen    = array_column( $samples, 'url' );
		foreach ( $current['samples'] ?? array() as $sample ) {
			$url = (string) ( $sample['url'] ?? '' );
			if ( '' === $url || count( $samples ) >= 50 || in_array( $url, $seen, true ) ) {
				continue;
			}$sample['batch'] = $batch;
			$samples[]        = $sample;
			$seen[]           = $url;
		}return array(
			'count'   => (int) ( $aggregate['count'] ?? 0 ) + (int) ( $current['count'] ?? 0 ),
			'samples' => $samples,
		);
	}
	private static function aggregate_result( array $manifest, string $path, array $terminal ): array {
		$batch_quality = array_values( array_filter( array_map( static fn ( array $batch ): mixed => self::quality_evidence( $batch['result']['quality'] ?? null ), $manifest['batches'] ), static fn ( $quality ): bool => null !== $quality ) );
		$evidence      = array(
			'status'                     => 'completed',
			'run_manifest'               => $path,
			'fetch_cache'                => $manifest['fetch_cache'] ?? array(),
			'per_batch_limits'           => $manifest['per_batch_limits'] ?? array(),
			'total_routes'               => $manifest['total_routes'],
			'completed_routes'           => array_sum( array_column( $manifest['batches'], 'completed_routes' ) ),
			'total_batches'              => count( $manifest['batches'] ),
			'completed_batches'          => count( array_filter( $manifest['batches'], static fn ( array $batch ): bool => 'completed' === $batch['state'] ) ),
			'failures'                   => $manifest['failures'],
			'diagnostics'                => $manifest['diagnostics'],
			'external_asset_retained'    => $manifest['external_asset_retained'] ?? array(),
			'shared_resource_plan'       => $manifest['shared_resource_plan'] ?? array(),
			'stage_timing'               => $manifest['stage_timing'] ?? array(),
			'stage_counters'             => $manifest['stage_counters'] ?? array(),
			'batch_quality'              => $batch_quality,
			'terminal_batch_report_path' => $terminal['report_path'] ?? '',
		);
		return array(
			'success'               => true,
			'theme_slug'            => $terminal['theme_slug'] ?? '',
			'theme_name'            => $terminal['theme_name'] ?? '',
			'import_report_summary' => array(
				'status'            => 'completed',
				'scope'             => 'url_site_batch_run',
				'total_routes'      => $evidence['total_routes'],
				'completed_routes'  => $evidence['completed_routes'],
				'total_batches'     => $evidence['total_batches'],
				'completed_batches' => $evidence['completed_batches'],
			),
			'url_batch_run'         => $evidence,
			'batch_materialization' => $manifest['batches'],
			'terminal_batch_result' => $terminal,
		);
	}
	private static function continuation_result( array $manifest, string $path, ?int $index, int $effective_batches, ?int $max_effective_batches = null, ?float $max_invocation_seconds = null, string $reason = 'effective_batch_limit' ): array {
		$next              = null !== $index ? ( $manifest['batches'][ $index ] ?? array() ) : array();
		$next_work         = array_filter(
			array(
				'index'                => $next['index'] ?? $index,
				'batch_id'             => $next['batch_id'] ?? '',
				'route_indexes'        => $next['route_indexes'] ?? array(),
				'effective_batch_size' => $next['effective_batch_size'] ?? null,
			),
			static fn ( $value ): bool => null !== $value
		);
		$completed_batches = count( array_filter( $manifest['batches'], static fn ( array $batch ): bool => 'completed' === $batch['state'] ) );
		$completed_routes  = self::materialized_routes( $manifest['batches'] );
		$page_ready_routes = array_sum( array_map( static fn( array $batch ): int => 'page_ready' === ( $batch['state'] ?? '' ) ? count( $batch['route_indexes'] ?? array() ) : 0, $manifest['batches'] ) );
		return array(
			'success'               => true,
			'continuation'          => true,
			'continuation_reason'   => $reason,
			'import_report_summary' => array(
				'status'            => 'continuing',
				'scope'             => 'url_site_batch_run',
				'total_routes'      => $manifest['total_routes'],
				'completed_routes'  => $completed_routes,
				'total_batches'     => count( $manifest['batches'] ),
				'completed_batches' => $completed_batches,
			),
			'url_batch_run'         => array(
				'status'                               => 'continuing',
				'phase'                                => (string) ( $manifest['progress']['phase'] ?? $manifest['phase'] ?? 'importing_batches' ),
				'progress'                             => is_array( $manifest['progress'] ?? null ) ? $manifest['progress'] : array(),
				'run_manifest'                         => $path,
				'fetch_cache'                          => $manifest['fetch_cache'] ?? array(),
				'per_batch_limits'                     => $manifest['per_batch_limits'] ?? array(),
				'total_routes'                         => $manifest['total_routes'],
				'completed_routes'                     => $completed_routes,
				'page_ready_routes'                    => $page_ready_routes,
				'total_batches'                        => count( $manifest['batches'] ),
				'completed_batches'                    => $completed_batches,
				'effective_batches_processed'          => $effective_batches,
				'max_effective_batches_per_invocation' => $max_effective_batches,
				'max_invocation_seconds'               => $max_invocation_seconds,
				'continuation_reason'                  => $reason,
				'next_work'                            => $next_work,
			),
			'batch_materialization' => $manifest['batches'],
		);
	}
	private static function materialized_routes( array $batches ): int {
		return array_sum(
			array_map(
				static function ( array $batch ): int {
					$completed_routes = (int) ( $batch['completed_routes'] ?? 0 );
					return 0 !== $completed_routes ? $completed_routes : ( 'page_ready' === ( $batch['state'] ?? '' ) ? count( $batch['route_indexes'] ?? array() ) : 0 );
				},
				$batches
			)
		);
	}
	private static function contract( string $url, array $input, array $args, int $batch_pages ): array {
		foreach ( array_keys( $args ) as $key ) {
			if ( str_starts_with( (string) $key, '_static_site_importer_' ) ) {
				unset( $args[ $key ] );
			}
		}return self::canonical(
			array(
				'version'              => self::VERSION,
				'url'                  => $url,
				'slug'                 => (string) ( $input['slug'] ?? '' ),
				'name'                 => (string) ( $input['name'] ?? '' ),
				'site_title'           => (string) ( $input['site_title'] ?? '' ),
				'activate'             => ! empty( $input['activate'] ),
				'overwrite'            => ! empty( $input['overwrite'] ),
				'report'               => (string) ( $input['report'] ?? '' ),
				'asset_failure_policy' => 'preserve_external_for_single_route_batch',
				'batch_pages'          => min( self::MAX_BATCH_PAGES, $batch_pages ),
				'provider_args'        => $args,
				'compiler_options'     => $input['compiler_options'] ?? array(),
			)
		);
	}
	private static function canonical( array $value ): array {
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$item = self::canonical( $item );
			}
		}unset( $item );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}return $value;
	}
}
