<?php
/**
 * Bounded WordPress.org external metric declarations and runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_External_Metric_Runtime {
	public const COLLECTION = 'external_metrics';
	public const PROVIDER   = 'wordpress.org';
	public const SOURCE     = 'ssi/external-metric';
	private const CACHE_TTL = 3600;
	private const MAX_BODY  = 1048576;
	private static array $metrics = array();

	public static function configure( array $metrics ): void {
		self::$metrics = array();
		foreach ( $metrics as $metric ) { if ( is_array( $metric ) && is_string( $metric['id'] ?? null ) ) { self::$metrics[ $metric['id'] ] = $metric; } }
	}

	public static function register(): void {
		add_action( 'init', static function (): void {
			if ( ! class_exists( 'WP_Block_Bindings_Registry' ) ) { return; }
			$registry = WP_Block_Bindings_Registry::get_instance();
			if ( ! $registry->is_registered( self::SOURCE ) ) {
				$registry->register( self::SOURCE, array( 'label' => 'WordPress.org metric', 'get_value_callback' => array( self::class, 'binding_value' ), 'uses_context' => array() ) );
			}
		} );
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( 'ssi/v1', '/external-metrics/(?P<id>[A-Za-z0-9._-]+)/refresh', array(
				'methods' => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'callback' => static function ( WP_REST_Request $request ) {
					$id = (string) $request['id'];
					$value = self::value( $id, self::$metrics, null, null, true );
					return rest_ensure_response( array( 'status' => null === $value ? 'unresolved' : 'refreshed', 'value' => $value ) );
				},
			) );
		} );
	}

	public static function binding_value( array $source_args ): ?string {
		if ( ! empty( $GLOBALS['static_site_importer_external_metric_export_fallback'] ) ) { return null; }
		$id = is_string( $source_args['metric_id'] ?? null ) ? $source_args['metric_id'] : '';
		return '' === $id ? null : self::value( $id, self::$metrics );
	}

	/** External metrics have no persistent WordPress entities to seed. */
	public static function adapter(): array {
		return array(
			'id'                   => 'wordpress_org_external_metrics',
			'entity_type'          => 'external_metric',
			'entity_collection'    => self::COLLECTION,
			'capability'           => self::COLLECTION,
			'provider'             => self::PROVIDER,
			'label'                => 'WordPress.org external metrics',
			'report_key'           => 'external_metrics',
			'validator'            => array( self::class, 'validate_manifest' ),
			'materializer'         => array( self::class, 'materialize' ),
			'rollback_callback'    => array( self::class, 'rollback' ),
			'rollback_contract_id' => 'static-site-importer/external-metric-rollback/v1',
			'binding_callback'     => array( self::class, 'binding_markup' ),
			'classic_binding_callback' => array( self::class, 'classic_binding' ),
		);
	}

	public static function classic_binding( array $entity, array $result ): array {
		$markup = self::binding_markup( $entity, $result );
		return '' === $markup ? array() : array( 'kind' => 'blocks', 'content' => $markup );
	}

	/** Validate the exact producer declaration shape, fail-closed as a whole. */
	public static function validate_manifest( mixed $data ): array {
		$errors = array();
		$rows   = is_array( $data ) && is_array( $data[ self::COLLECTION ] ?? null ) ? $data[ self::COLLECTION ] : null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || empty( $rows ) || count( $rows ) > 100 ) {
			return array( self::COLLECTION => array(), 'errors' => array( array( 'path' => '$.external_metrics', 'message' => 'external_metrics must be a non-empty bounded list.' ) ) );
		}
		$accepted = array();
		$seen     = array();
		foreach ( $rows as $index => $fact ) {
			$path = '$.external_metrics[' . $index . ']';
			if ( ! is_array( $fact ) || ! is_string( $fact['id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $fact['id'] ) || isset( $seen[ $fact['id'] ] ) ) {
				$errors[] = array( 'path' => $path . '.id', 'message' => 'Metric identity is invalid or duplicated.' );
				continue;
			}
			$seen[ $fact['id'] ] = true;
			$provider = $fact['provider'] ?? null;
			$source   = is_array( $provider ) ? ( $provider['source'] ?? '' ) : '';
			$slugs    = is_array( $provider ) ? ( $provider['slugs'] ?? null ) : null;
			$allowed  = 'plugin_information' === $source
				? array( 'plugin_response_count' => 'success_count', 'active_installs' => 'sum', 'version' => 'identity', 'num_ratings' => 'identity' )
				: ( 'plugin_download_history' === $source ? array( 'downloads_all_time' => 'sum' ) : array() );
			if ( ! is_array( $provider ) || 'generic/external-metric-provider/v1' !== ( $provider['schema'] ?? '' ) || self::PROVIDER !== ( $provider['id'] ?? '' ) || ! is_array( $slugs ) || ! array_is_list( $slugs ) || empty( $slugs ) || count( $slugs ) > 100 ) {
				$errors[] = array( 'path' => $path . '.provider', 'message' => 'Unsupported external metric provider declaration.' );
				continue;
			}
			$slug_error = false;
			foreach ( $slugs as $slug ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/', $slug ) ) { $slug_error = true; break; }
			}
			if ( $slug_error || count( $slugs ) !== count( array_unique( $slugs ) ) ) {
				$errors[] = array( 'path' => $path . '.provider.slugs', 'message' => 'Plugin slugs must be unique validated identifiers.' );
				continue;
			}
			$metric = $fact['metric'] ?? null;
			if ( ! is_string( $metric ) || ! isset( $allowed[ $metric ] ) || $allowed[ $metric ] !== ( $fact['aggregation'] ?? null ) || ( in_array( $metric, array( 'version', 'num_ratings' ), true ) && 1 !== count( $slugs ) ) ) {
				$errors[] = array( 'path' => $path . '.metric', 'message' => 'Metric and aggregation are not supported for this source.' );
				continue;
			}
			$format     = $fact['format'] ?? null;
			$provenance = $fact['provenance'] ?? null;
			$fallback  = $fact['fallback'] ?? null;
			$fact_bindings = is_array( $fact['bindings'] ?? null ) ? $fact['bindings'] : array();
			$binding   = $fact_bindings[0] ?? null;
			$format_ok = is_array( $format ) && is_string( $format['locale'] ?? null ) && preg_match( '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $format['locale'] ) && is_bool( $format['grouping'] ?? null ) && in_array( $format['prefix'] ?? null, array( '', 'v' ), true ) && in_array( $format['suffix'] ?? null, array( '', '+' ), true ) && is_int( $format['decimals'] ?? null ) && $format['decimals'] >= 0 && $format['decimals'] <= 4;
			if ( $format_ok && ( ( 'version' === $metric ) !== ( 'v' === $format['prefix'] ) || ( in_array( $metric, array( 'active_installs', 'downloads_all_time' ), true ) !== ( '+' === $format['suffix'] ) ) || ( 'version' === $metric && ( $format['grouping'] || 0 !== $format['decimals'] ) ) || ( 'num_ratings' === $metric && '+' === $format['suffix'] ) ) ) { $format_ok = false; }
			$provenance_ok = is_array( $provenance ) && ( ( 'source_corroboration' === ( $provenance['kind'] ?? '' ) && is_string( $provenance['repository'] ?? null ) && is_string( $provenance['revision'] ?? null ) && preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $provenance['revision'] ) && is_string( $provenance['source_path'] ?? null ) ) || ( 'operator_mapping' === ( $provenance['kind'] ?? '' ) && ! empty( $provenance['author'] ) && ! empty( $provenance['source_relationship'] ) ) );
			$fallback_ok = is_array( $fallback ) && is_string( $fallback['text'] ?? null ) && strlen( $fallback['text'] ) <= 4096 && is_string( $fallback['hash'] ?? null ) && hash_equals( hash( 'sha256', $fallback['text'] ), $fallback['hash'] );
			$binding_source = is_array( $binding ) ? ( $data['source_path'] ?? $binding['source_path'] ?? '' ) : '';
			$binding_ok  = is_array( $binding ) && 'generic/block-binding/v1' === ( $binding['schema'] ?? '' ) && in_array( $binding['role'] ?? '', array( 'paragraph', 'heading' ), true ) && ( $binding['source_path'] ?? null ) === $binding_source && is_string( $binding_source ) && '' !== $binding_source && ! str_contains( $binding_source, '..' ) && is_string( $binding['search_block_markup'] ?? null ) && is_int( $binding['occurrence'] ?? null ) && $binding['occurrence'] > 0 && is_array( $binding['leaf'] ?? null ) && in_array( $binding['leaf']['block'] ?? '', array( 'core/paragraph', 'core/heading' ), true ) && ( ( 'paragraph' === ( $binding['role'] ?? '' ) && 'core/paragraph' === ( $binding['leaf']['block'] ?? '' ) ) || ( 'heading' === ( $binding['role'] ?? '' ) && 'core/heading' === ( $binding['leaf']['block'] ?? '' ) ) ) && 'content' === ( $binding['leaf']['attribute'] ?? '' );
			if ( $binding_ok && ! empty( $data['validate_anchor_content'] ) && function_exists( 'parse_blocks' ) ) {
				$anchor_block = parse_blocks( $binding['search_block_markup'] )[0] ?? null;
				$anchor_text = is_array( $anchor_block ) ? ( $anchor_block['attrs']['content'] ?? trim( html_entity_decode( wp_strip_all_tags( (string) ( $anchor_block['innerHTML'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : null;
				$binding_ok = is_array( $anchor_block ) && ( $binding['leaf']['block'] ?? '' ) === ( $anchor_block['blockName'] ?? '' ) && ( $fallback['text'] ?? null ) === $anchor_text;
			}
			if ( ! $format_ok || ! $provenance_ok || ! $fallback_ok || ! $binding_ok || ! array_is_list( $fact_bindings ) || 1 !== count( $fact_bindings ) ) {
				$errors[] = array( 'path' => $path, 'message' => 'Metric formatting, provenance, fallback, or native text-leaf binding is invalid.', 'checks' => array( 'format' => (bool) $format_ok, 'provenance' => (bool) $provenance_ok, 'fallback' => (bool) $fallback_ok, 'binding' => (bool) $binding_ok, 'single_binding' => array_is_list( $fact_bindings ) && 1 === count( $fact_bindings ) ) );
				continue;
			}
			$accepted[] = $fact;
		}
		return array( self::COLLECTION => empty( $errors ) ? $accepted : array(), 'errors' => $errors );
	}

	/** Build no-write provider receipt; fetches happen only on frontend requests. */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$rows = $manifest[ self::COLLECTION ] ?? array();
		return array( 'status' => 'completed', 'provider' => self::PROVIDER, 'counts' => array( 'mapped' => count( $rows ), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0 ), self::COLLECTION => array_map( static fn( array $row ): array => array( 'id' => $row['id'], 'status' => 'mapped', 'metric_id' => $row['id'] ), $rows ) );
	}

	public static function rollback( array $report ): array { return array( 'status' => 'rolled_back', 'reason' => 'no_persistent_entity', 'report' => $report ); }

	/** Return a canonical native paragraph/heading with its captured leaf fallback. */
	public static function binding_markup( array $entity, array $result ): string {
		if ( ( $result['status'] ?? '' ) !== 'mapped' || ! is_array( $entity['fallback'] ?? null ) ) { return ''; }
		$block = (string) ( $entity['bindings'][0]['leaf']['block'] ?? '' );
		if ( ! in_array( $block, array( 'core/paragraph', 'core/heading' ), true ) ) { return ''; }
		$id      = (string) $entity['id'];
		$content = (string) $entity['fallback']['text'];
		$parsed = function_exists( 'parse_blocks' ) ? parse_blocks( (string) ( $entity['bindings'][0]['search_block_markup'] ?? '' ) ) : array();
		if ( ! is_array( $parsed[0] ?? null ) || $block !== ( $parsed[0]['blockName'] ?? '' ) ) { return ''; }
		$attributes = is_array( $parsed[0]['attrs'] ?? null ) ? $parsed[0]['attrs'] : array();
		$metadata = is_array( $attributes['metadata'] ?? null ) ? $attributes['metadata'] : array();
		$bindings = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : array();
		$bindings['content'] = array( 'source' => self::SOURCE, 'args' => array( 'metric_id' => $id ) );
		$metadata['bindings'] = $bindings;
		$attributes['metadata'] = $metadata;
		$parsed[0]['attrs'] = $attributes;
		return serialize_block( $parsed[0] );
	}

	/** Fetch a source value with per-request dedupe, hourly transient and LKG fallback. */
	public static function value( string $metric_id, array $metrics, ?callable $request = null, ?int $now = null, bool $force = false ): ?string {
		static $request_values = array();
		static $request_responses = array();
		if ( $force ) { unset( $request_values[ $metric_id ] ); }
		if ( isset( $request_values[ $metric_id ] ) ) { return $request_values[ $metric_id ]; }
		$fact = $metrics[ $metric_id ] ?? null;
		if ( ! is_array( $fact ) || ! empty( self::validate_manifest( array( self::COLLECTION => array( $fact ), 'source_path' => $fact['bindings'][0]['source_path'] ?? '' ) )['errors'] ) ) { return null; }
		$key = 'ssi_external_metric_' . hash( 'sha256', wp_json_encode( array( $fact['provider'], $fact['metric'], $fact['aggregation'], $fact['format'] ) ) );
		$now = $now ?? time();
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( ! $force && is_array( $cached ) && isset( $cached['value'], $cached['fetched_at'] ) && $now - (int) $cached['fetched_at'] < self::CACHE_TTL ) { return $request_values[ $metric_id ] = (string) $cached['value']; }
		$retry_at = get_option( 'static_site_importer_external_metric_retry_after', array() );
		if ( ! $force && is_array( $retry_at ) && (int) ( $retry_at[ $key ] ?? 0 ) > $now ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
		$request = $request ?? static fn( string $url ): array|WP_Error => wp_remote_get( $url, array( 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => self::MAX_BODY, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$values = array();
		$deadline = microtime( true ) + 15.0;
		foreach ( $fact['provider']['slugs'] as $slug ) {
			if ( microtime( true ) >= $deadline ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
			$url = 'plugin_information' === $fact['provider']['source'] ? add_query_arg( array( 'action' => 'plugin_information', 'slug' => $slug ), 'https://api.wordpress.org/plugins/info/1.2/' ) : add_query_arg( array( 'slug' => $slug, 'historical_summary' => 1 ), 'https://api.wordpress.org/stats/plugin/1.0/downloads.php' );
			if ( $force ) { unset( $request_responses[ $url ] ); }
			$response = array_key_exists( $url, $request_responses ) ? $request_responses[ $url ] : $request( $url );
			if ( is_wp_error( $response ) ) {
				$retry = get_option( 'static_site_importer_external_metric_retry_after', array() );
				$retry = is_array( $retry ) ? $retry : array();
				if ( time() >= (int) ( $retry[ $key ] ?? 0 ) ) {
					usleep( 100000 );
					$response = $request( $url );
					$retry[ $key ] = time() + 60;
					update_option( 'static_site_importer_external_metric_retry_after', $retry, false );
				}
			}
			$request_responses[ $url ] = $response;
			if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
			if ( 'plugin_download_history' === $fact['provider']['source'] ) {
				if ( ! isset( $data['all_time'] ) || ! is_scalar( $data['all_time'] ) || ! preg_match( '/^\d+$/', (string) $data['all_time'] ) ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				$values[] = (int) $data['all_time'];
			} else {
				if ( isset( $data['error'] ) || empty( $data ) ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				if ( 'plugin_response_count' !== $fact['metric'] && ! array_key_exists( $fact['metric'], $data ) ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				if ( 'plugin_response_count' === $fact['metric'] ) { $values[] = 1; }
				elseif ( 'active_installs' === $fact['metric'] ) { if ( ! is_numeric( $data['active_installs'] ) || (float) $data['active_installs'] < 0 ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); } $values[] = (float) $data['active_installs']; }
				elseif ( 'num_ratings' === $fact['metric'] ) { if ( ! is_numeric( $data['num_ratings'] ) || (float) $data['num_ratings'] < 0 ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); } $values[] = (int) $data['num_ratings']; }
				else { if ( ! is_string( $data['version'] ) || '' === $data['version'] ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); } $values[] = $data['version']; }
			}
		}
		if ( empty( $values ) ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
		$value = 'identity' === $fact['aggregation'] ? $values[0] : array_sum( $values );
		if ( ! is_scalar( $value ) || ( 'version' !== $fact['metric'] && ! is_numeric( $value ) ) ) { return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
		$formatted = self::format_value( $value, $fact['format'], (string) $fact['metric'] );
		$receipt = array( 'status' => 'fresh', 'value' => $formatted, 'fetched_at' => $now, 'provider' => self::PROVIDER, 'source' => $fact['provider']['source'] );
		if ( function_exists( 'set_transient' ) ) { set_transient( $key, $receipt, self::CACHE_TTL ); }
		$lkg = get_option( 'static_site_importer_external_metric_last_good', array() );
		$lkg = is_array( $lkg ) ? $lkg : array();
		$lkg[ $key ] = $receipt;
		update_option( 'static_site_importer_external_metric_last_good', $lkg, false );
		self::store_receipt( $metric_id, $receipt );
		return $request_values[ $metric_id ] = $formatted;
	}

	private static function stale_or_fallback( string $id, array $fact, string $key, mixed $cached, array &$request_values ): string {
		$retry = get_option( 'static_site_importer_external_metric_retry_after', array() );
		$retry = is_array( $retry ) ? $retry : array();
		$retry[ $key ] = time() + 60;
		update_option( 'static_site_importer_external_metric_retry_after', $retry, false );
		$lkg = get_option( 'static_site_importer_external_metric_last_good', array() );
		$last_good = is_array( $lkg ) && is_array( $lkg[ $key ] ?? null ) ? $lkg[ $key ] : null;
		$value = is_array( $last_good ) && is_string( $last_good['value'] ?? null ) ? $last_good['value'] : (string) $fact['fallback']['text'];
		$status = is_array( $last_good ) ? 'stale' : ( '' !== $value ? 'captured_fallback' : 'unresolved' );
		$receipt = array( 'status' => $status, 'value' => $value, 'fetched_at' => (int) ( $last_good['fetched_at'] ?? 0 ), 'provider' => self::PROVIDER, 'source' => $fact['provider']['source'] );
		self::store_receipt( $id, $receipt );
		return $request_values[ $id ] = $value;
	}

	private static function format_value( mixed $value, array $format, string $metric ): string {
		if ( 'version' !== $metric && is_numeric( $value ) ) {
			$formatted = false;
			if ( class_exists( 'NumberFormatter' ) ) {
				$formatter = new NumberFormatter( (string) $format['locale'], NumberFormatter::DECIMAL );
				$formatter->setAttribute( NumberFormatter::GROUPING_USED, ! empty( $format['grouping'] ) ? 1 : 0 );
				$formatter->setAttribute( NumberFormatter::MIN_FRACTION_DIGITS, (int) $format['decimals'] );
				$formatter->setAttribute( NumberFormatter::MAX_FRACTION_DIGITS, (int) $format['decimals'] );
				$formatted = $formatter->format( $value );
			}
			if ( ! is_string( $formatted ) ) {
				$locale   = strtolower( str_replace( '_', '-', (string) $format['locale'] ) );
				$decimal  = preg_match( '/^(de|es|it|pt|nl|ru|tr|pl|fr|da|sv|no|fi|cs|sk|hu)(-|$)/', $locale ) ? ',' : '.';
				$thousand = ',' === $decimal ? ( str_starts_with( $locale, 'fr' ) ? "\u{202f}" : '.' ) : ',';
				$formatted = number_format( (float) $value, (int) $format['decimals'], $decimal, ! empty( $format['grouping'] ) ? $thousand : '' );
			}
			$value = $formatted;
		}
		return (string) $format['prefix'] . (string) $value . (string) $format['suffix'];
	}

	private static function store_receipt( string $id, array $receipt ): void {
		$all = get_option( 'static_site_importer_external_metric_receipts', array() );
		$all = is_array( $all ) ? $all : array();
		$all[ $id ] = $receipt;
		update_option( 'static_site_importer_external_metric_receipts', $all, false );
	}
}
