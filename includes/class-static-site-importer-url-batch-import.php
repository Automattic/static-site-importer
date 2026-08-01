<?php
/** Resumable bounded URL site import. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Static_Site_Importer_URL_Batch_Import {
	private const VERSION = 2;
	private const MAX_BATCH_PAGES = 100;

	/** @return array<string,mixed>|WP_Error */
	public static function import( array $request, array $input, ?callable $fetcher = null, ?callable $importer = null ) {
		$args = is_array( $request['provider_args'] ?? null ) ? $request['provider_args'] : array();
		$batch_pages = (int) ( $args['batch_pages'] ?? 0 );
		if ( $batch_pages < 1 ) { return new WP_Error( 'static_site_importer_invalid_batch_pages', 'batch_pages must be a positive integer.' ); }
		// These are bounded per-batch collection guards, never whole-site limits.
		if ( ! array_key_exists( 'max_assets', $args ) ) { $args['max_assets'] = 2000; }
		if ( ! array_key_exists( 'max_total_bytes', $args ) ) { $args['max_total_bytes'] = 268435456; }
		$work_dir = (string) ( $request['work_dir'] ?? '' );
		if ( '' === $work_dir || ! wp_mkdir_p( $work_dir ) ) { return new WP_Error( 'static_site_importer_batch_work_dir_unavailable', 'The batch import work directory is unavailable.' ); }
		$url = (string) $request['url'];
		$contract = self::contract( $url, $input, $args, $batch_pages );
		$identity = hash( 'sha256', wp_json_encode( $contract ) );
		$manifest_path = trailingslashit( $work_dir ) . 'url-site-batch-manifest-' . hash( 'sha256', self::VERSION . "\n" . $url ) . '.json';
		$manifest = self::read_manifest( $manifest_path, $identity );
		if ( is_wp_error( $manifest ) ) { return $manifest; }
		if ( ! empty( $manifest ) && $contract !== ( $manifest['contract'] ?? null ) ) { return new WP_Error( 'static_site_importer_batch_contract_mismatch', 'The existing batch run targets a different import contract.', array( 'run_manifest' => $manifest_path ) ); }
		if ( empty( $manifest ) ) {
			$routes = Static_Site_Importer_URL_Site_Collector::discover_routes( $url, $args, $fetcher );
			if ( is_wp_error( $routes ) ) { return $routes; }
			$routes = self::ordered_routes( $url, $routes );
			// A sitemap is optional. The entrypoint is always a valid single-page batch.
			if ( empty( $routes ) ) { $routes = array( $url ); }
			$manifest = array(
				'schema' => 'static-site-importer/url-site-batch-run/v1', 'version' => self::VERSION,
				'source' => array( 'url' => $url, 'identity' => $identity ), 'contract' => $contract, 'discovery_limits' => Static_Site_Importer_URL_Site_Collector::discovery_limits(), 'per_batch_limits' => array( 'max_pages' => min( self::MAX_BATCH_PAGES, $batch_pages ), 'max_assets' => min( 2000, max( 0, (int) $args['max_assets'] ), ), 'max_total_bytes' => min( 268435456, max( 1, (int) $args['max_total_bytes'] ), ), 'max_response_bytes' => 10485760 ),
				'total_routes' => count( $routes ), 'routes' => $routes, 'batch_pages' => min( self::MAX_BATCH_PAGES, $batch_pages ),
				'batches' => array(), 'failures' => array(), 'diagnostics' => array(), 'external_asset_retained' => array( 'count' => 0, 'samples' => array() ), 'state' => 'running',
			);
			foreach ( array_chunk( array_keys( $routes ), $manifest['batch_pages'] ) as $index => $route_indexes ) {
				$manifest['batches'][] = array( 'index' => $index, 'route_indexes' => $route_indexes, 'state' => 'pending', 'completed_routes' => 0 );
			}
			$write = self::write_json( $manifest_path, $manifest );
			if ( is_wp_error( $write ) ) { return $write; }
		}
		if ( 'completed' === ( $manifest['state'] ?? '' ) && is_array( $manifest['final_result'] ?? null ) ) { return $manifest['final_result']; }
		$importer = $importer ?? static fn ( array $artifact, array $import_args ) => Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $import_args );
		$results = array();
		foreach ( $manifest['batches'] as $index => $batch ) {
			if ( 'completed' === ( $batch['state'] ?? '' ) ) { $results[] = $batch['result'] ?? array(); continue; }
			$routes = array_values( array_intersect_key( $manifest['routes'], array_flip( $batch['route_indexes'] ) ) );
			$cache_path = trailingslashit( $work_dir ) . 'url-site-batch-cache-' . $identity . '-' . $index . '.json';
			$runtime = self::read_cache( $cache_path );
			if ( empty( $runtime ) ) {
				$collect_args = $args;
				$collect_args['_route_set'] = array_values( array_unique( array_merge( array( $url ), $routes ) ) );
				$collect_args['max_pages'] = min( self::MAX_BATCH_PAGES + 1, count( $collect_args['_route_set'] ) + 1 );
				$collect_args['require_complete_collection'] = true;
				if ( 1 === count( $routes ) ) { $collect_args['asset_failure_policy'] = 'preserve_external'; }
				$runtime = Static_Site_Importer_URL_Site_Collector::collect( $url, $collect_args, $fetcher );
				if ( is_wp_error( $runtime ) ) {
					if ( count( $routes ) > 1 && self::splittable_collection_error( $runtime ) ) {
						$manifest = self::split_batch( $manifest, $index );
						$write = self::write_json( $manifest_path, $manifest );
						if ( is_wp_error( $write ) ) { return $write; }
						return self::import( $request, $input, $fetcher, $importer );
					}
					return self::failed( $manifest_path, $manifest, $index, $runtime );
				}
				$write = self::write_json( $cache_path, $runtime );
				if ( is_wp_error( $write ) ) { return self::failed( $manifest_path, $manifest, $index, $write ); }
			}
			$manifest['external_asset_retained'] = self::merge_external_assets( $manifest['external_asset_retained'] ?? array(), $runtime['source_metadata']['collection']['external_asset_retained'] ?? array(), $index );
			$write = self::write_json( $manifest_path, $manifest );
			if ( is_wp_error( $write ) ) { return $write; }
			$import_args = Static_Site_Importer_URL_Import_Runtime::batch_import_args( $input, $runtime );
			$import_args['activate'] = $index === array_key_last( $manifest['batches'] ) && ! empty( $input['activate'] );
			$import_args['batch_import'] = true;
			$import_args['preserve_existing_theme_bootstrap'] = $index > 0;
			$import_args['import_run_id'] = $identity;
			$result = $importer( $runtime['artifact'], $import_args );
			if ( is_wp_error( $result ) ) { return self::failed( $manifest_path, $manifest, $index, $result ); }
			$manifest['batches'][ $index ]['state'] = 'completed';
			$manifest['batches'][ $index ]['completed_routes'] = count( $routes );
			$manifest['batches'][ $index ]['result'] = self::result_evidence( $result );
			$manifest['diagnostics'] = array_merge( $manifest['diagnostics'], $result['import_validation_result']['diagnostics'] ?? array() );
			$write = self::write_json( $manifest_path, $manifest );
			if ( is_wp_error( $write ) ) { return $write; }
			if ( is_file( $cache_path ) && ! unlink( $cache_path ) ) { $manifest['diagnostics'][] = array( 'code' => 'static_site_importer_batch_cache_delete_failed', 'path' => $cache_path ); }
			$results[] = $result;
			$final = $result;
		}
		$aggregate = self::aggregate_result( $manifest, $manifest_path, $results, $final ?? array() );
		$manifest['state'] = 'completed'; $manifest['completed_at'] = gmdate( 'c' ); $manifest['final_result'] = $aggregate;
		$write = self::write_json( $manifest_path, $manifest );
		return is_wp_error( $write ) ? $write : $aggregate;
	}

	private static function ordered_routes( string $entry, array $routes ): array {
		$routes[] = $entry;
		$routes = array_values( array_unique( array_filter( $routes, 'is_string' ) ) );
		usort( $routes, static fn( string $a, string $b ): int => substr_count( trim( (string) parse_url( $a, PHP_URL_PATH ), '/' ), '/' ) <=> substr_count( trim( (string) parse_url( $b, PHP_URL_PATH ), '/' ), '/' ) ?: strcmp( $a, $b ) );
		return $routes;
	}
	private static function splittable_collection_error( WP_Error $error ): bool { $data = $error->get_error_data(); if ( 'static_site_importer_site_collection_incomplete' !== $error->get_error_code() || ! is_array( $data ) ) { return false; } if ( array_intersect( $data['collection']['truncated'] ?? array(), array( 'assets', 'bytes' ) ) ) { return true; } foreach ( $data['collection']['failures'] ?? array() as $failure ) { if ( 'asset' === ( $failure['kind'] ?? '' ) ) { return true; } } return false; }
	private static function split_batch( array $manifest, int $index ): array {
		$batch = $manifest['batches'][ $index ]; $routes = $batch['route_indexes']; $middle = (int) ceil( count( $routes ) / 2 );
		$children = array( array( 'route_indexes' => array_slice( $routes, 0, $middle ) ), array( 'route_indexes' => array_slice( $routes, $middle ) ) );
		foreach ( $children as &$child ) { $child += array( 'state' => 'pending', 'completed_routes' => 0, 'split_from' => $batch['index'], 'effective_batch_size' => count( $child['route_indexes'] ) ); }
		unset( $child ); array_splice( $manifest['batches'], $index, 1, $children );
		foreach ( $manifest['batches'] as $position => &$row ) { $row['index'] = $position; }
		unset( $row ); $manifest['diagnostics'][] = array( 'code' => 'batch_subdivided', 'parent_batch' => $batch['index'], 'children' => array_map( static fn( array $child ): int => $child['effective_batch_size'], $children ) ); return $manifest;
	}
	private static function result_evidence( array $result ): array { return array( 'theme_slug' => $result['theme_slug'] ?? '', 'terminal_batch_report_path' => $result['report_path'] ?? '', 'quality' => $result['quality'] ?? ( $result['import_report_summary']['quality_pass'] ?? null ) ); }
	private static function evidence( array $manifest, string $path, array $results ): array {
		return array( 'status' => 'completed', 'run_manifest' => $path, 'per_batch_limits' => $manifest['per_batch_limits'] ?? array(), 'total_routes' => $manifest['total_routes'], 'completed_routes' => array_sum( array_column( $manifest['batches'], 'completed_routes' ) ), 'total_batches' => count( $manifest['batches'] ), 'completed_batches' => count( array_filter( $manifest['batches'], static fn( array $batch ): bool => 'completed' === $batch['state'] ) ), 'failures' => $manifest['failures'], 'diagnostics' => $manifest['diagnostics'], 'external_asset_retained' => $manifest['external_asset_retained'] ?? array(), 'batch_quality' => array_column( $results, 'quality' ), 'terminal_batch_report_path' => $results ? ( $results[ array_key_last( $results ) ]['report_path'] ?? '' ) : '' );
	}
	private static function aggregate_result( array $manifest, string $path, array $results, array $terminal ): array {
		$evidence = self::evidence( $manifest, $path, $results );
		return array( 'success' => true, 'theme_slug' => $terminal['theme_slug'] ?? '', 'theme_name' => $terminal['theme_name'] ?? '', 'import_report_summary' => array( 'status' => 'completed', 'scope' => 'url_site_batch_run', 'total_routes' => $evidence['total_routes'], 'completed_routes' => $evidence['completed_routes'], 'total_batches' => $evidence['total_batches'], 'completed_batches' => $evidence['completed_batches'] ), 'url_batch_run' => $evidence, 'batch_materialization' => $manifest['batches'], 'terminal_batch_result' => $terminal );
	}
	private static function merge_external_assets( array $aggregate, array $current, int $batch ): array { $samples = $aggregate['samples'] ?? array(); $seen = array_column( $samples, 'url' ); foreach ( $current['samples'] ?? array() as $sample ) { $url = (string) ( $sample['url'] ?? '' ); if ( '' === $url || count( $samples ) >= 50 || in_array( $url, $seen, true ) ) { continue; } $sample['batch'] = $batch; $samples[] = $sample; $seen[] = $url; } return array( 'count' => (int) ( $aggregate['count'] ?? 0 ) + (int) ( $current['count'] ?? 0 ), 'samples' => $samples ); }
	private static function failed( string $path, array $manifest, int $index, WP_Error $error ): WP_Error {
		$manifest['state'] = 'failed'; $manifest['batches'][ $index ]['state'] = 'failed';
		$manifest['failures'][] = array( 'batch' => $index, 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'at' => gmdate( 'c' ) );
		$write = self::write_json( $path, $manifest );
		$data = array_merge( is_array( $error->get_error_data() ) ? $error->get_error_data() : array(), array( 'run_manifest' => $path, 'run' => $manifest ) );
		if ( is_wp_error( $write ) ) { $data['checkpoint_error'] = array( 'code' => $write->get_error_code(), 'message' => $write->get_error_message() ); }
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
	private static function read_manifest( string $path, string $identity ) { if ( ! is_file( $path ) ) { return array(); } $manifest = json_decode( (string) file_get_contents( $path ), true ); if ( ! is_array( $manifest ) || empty( $manifest['source']['identity'] ) ) { return new WP_Error( 'static_site_importer_batch_manifest_invalid', 'The batch run manifest is invalid.' ); } return $manifest; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer checkpoint.
	private static function read_cache( string $path ): array { return is_file( $path ) ? ( json_decode( (string) file_get_contents( $path ), true ) ?: array() ) : array(); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer resource cache.
	private static function contract( string $url, array $input, array $args, int $batch_pages ): array { return array( 'version' => self::VERSION, 'url' => $url, 'slug' => (string) ( $input['slug'] ?? '' ), 'name' => (string) ( $input['name'] ?? '' ), 'site_title' => (string) ( $input['site_title'] ?? '' ), 'activate' => ! empty( $input['activate'] ), 'overwrite' => ! empty( $input['overwrite'] ), 'report' => (string) ( $input['report'] ?? '' ), 'asset_failure_policy' => 'preserve_external_for_single_route_batch', 'batch_pages' => min( self::MAX_BATCH_PAGES, $batch_pages ), 'provider_args' => $args, 'compiler_options' => $input['compiler_options'] ?? array() ); }
	private static function write_json( string $path, array $data ) { $temp = tempnam( dirname( $path ), '.ssi-batch-' ); $json = wp_json_encode( $data, JSON_PRETTY_PRINT ); $written = is_string( $json ) && false !== $temp ? file_put_contents( $temp, $json ) : false; if ( false === $temp || false === $json || strlen( $json ) !== $written || ! rename( $temp, $path ) ) { if ( is_string( $temp ) && file_exists( $temp ) ) { unlink( $temp ); } return new WP_Error( 'static_site_importer_batch_checkpoint_write_failed', 'Unable to atomically write URL batch run state.', array( 'path' => $path ) ); } return true; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.unlink_unlink -- Atomic importer checkpoint write.
}
