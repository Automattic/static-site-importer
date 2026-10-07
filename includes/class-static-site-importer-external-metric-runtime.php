<?php
/**
 * Bounded declarative external metric source and runtime.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_IP_Classifier' ) ) {
	$ip_classifier_file = is_readable( __DIR__ . '/ip-classifier.php' ) ? __DIR__ . '/ip-classifier.php' : __DIR__ . '/class-static-site-importer-ip-classifier.php';
	if ( is_readable( $ip_classifier_file ) ) {
		require_once $ip_classifier_file; }
}

final class Static_Site_Importer_External_Metric_Runtime {
	public const COLLECTION       = 'external_metrics';
	public const SOURCE           = 'ssi/external-metric';
	private const MAX_BODY        = 1048576;
	private const MAX_COUNT       = 9007199254740991;
	private static array $metrics = array();

	public static function configure( array $metrics ): void {
		self::$metrics = array();
		foreach ( $metrics as $metric ) {
			if ( is_array( $metric ) && is_string( $metric['id'] ?? null ) ) {
				self::$metrics[ $metric['id'] ] = $metric; }
		}
	}

	public static function register(): void {
		if ( empty( self::$metrics ) ) {
			return; }
		add_action(
			'init',
			static function (): void {
				if ( ! class_exists( 'WP_Block_Bindings_Registry' ) ) {
					return; }
				$registry = WP_Block_Bindings_Registry::get_instance();
				if ( ! $registry->is_registered( self::SOURCE ) ) {
					$registry->register(
						self::SOURCE,
						array(
							'label'              => 'External metric',
							'get_value_callback' => array( self::class, 'binding_value' ),
							'uses_context'       => array(),
						)
					);
				}
			}
		);
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'ssi/v1',
					'/external-metrics/(?P<id>[A-Za-z0-9._-]+)/refresh',
					array(
						'methods'             => 'POST',
						'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
						'callback'            => static function ( WP_REST_Request $request ) {
							$id       = (string) $request['id'];
							$value    = self::value( $id, self::$metrics, null, null, true );
							$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
							$receipt  = is_array( $receipts ) && is_array( $receipts[ $id ] ?? null ) ? $receipts[ $id ] : array();
							$status   = (string) ( $receipt['status'] ?? 'unresolved' );
							if ( null === $value || 'unresolved' === $status ) {
								return new WP_Error(
									'static_site_importer_external_metric_refresh_unresolved',
									'No current or captured value is available for this metric.',
									array(
										'status'    => 503,
										'freshness' => 'unresolved',
										'value'     => $value,
										'receipt'   => $receipt,
									)
								);
							}
							$messages = array(
								'fresh'             => 'The configured source returned a fresh value.',
								'stale'             => 'The configured source is unavailable; showing the last-known value.',
								'captured_fallback' => 'The configured source returned an invalid value; showing the captured fallback.',
							);
							return rest_ensure_response(
								array(
									'status'  => $status,
									'value'   => $value,
									'receipt' => $receipt,
									'message' => $messages[ $status ] ?? 'External metric status: ' . $status,
								)
							);
						},
					)
				);
			}
		);
	}

	public static function binding_value( array $source_args ): ?string {
		if ( ! empty( $GLOBALS['static_site_importer_external_metric_export_fallback'] ) ) {
			return null; }
		$id = is_string( $source_args['metric_id'] ?? null ) ? $source_args['metric_id'] : '';
		if ( '' === $id ) {
			return null; }
		$value = self::value( $id, self::$metrics );
		if ( null === $value ) {
			return null; }
		$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
		$status   = is_array( $receipts ) && is_array( $receipts[ $id ] ?? null ) ? ( $receipts[ $id ]['status'] ?? '' ) : '';
		// The saved native text is authoritative fallback markup. Returning its
		// decoded text to WP_Block::replace_html() would promote literal tags into
		// rich-text markup, so let WordPress keep the original HTML when no trusted
		// provider value or last-known-good value is available.
		return in_array( $status, array( 'fresh', 'stale' ), true ) ? $value : null;
	}

	/** External metrics have no persistent WordPress entities to seed. */
	public static function adapter(): array {
		return array(
			'id'                       => 'generic_external_metrics',
			'entity_type'              => 'external_metric',
			'entity_collection'        => self::COLLECTION,
			'capability'               => self::COLLECTION,
			'provider'                 => 'external_source',
			'label'                    => 'External metrics',
			'report_key'               => 'external_metrics',
			'validator'                => array( self::class, 'validate_manifest' ),
			'materializer'             => array( self::class, 'materialize' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/external-metric-rollback/v1',
			'binding_callback'         => array( self::class, 'binding_markup' ),
			'classic_binding_callback' => array( self::class, 'classic_binding' ),
		);
	}

	public static function classic_binding( array $entity, array $result ): array {
		$markup = self::binding_markup( $entity, $result );
		return '' === $markup ? array() : array(
			'kind'    => 'blocks',
			'content' => $markup,
		);
	}

	/** Validate the exact producer declaration shape, fail-closed as a whole. */
	public static function validate_manifest( mixed $data ): array {
		$errors = array();
		$rows   = is_array( $data ) && is_array( $data[ self::COLLECTION ] ?? null ) ? $data[ self::COLLECTION ] : null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || empty( $rows ) || count( $rows ) > 100 ) {
			return array(
				self::COLLECTION => array(),
				'errors'         => array(
					array(
						'path'    => '$.external_metrics',
						'message' => 'external_metrics must be a non-empty bounded list.',
					),
				),
			);
		}
		$accepted = array();
		$seen     = array();
		foreach ( $rows as $index => $fact ) {
			$path = '$.external_metrics[' . $index . ']';
			if ( ! is_array( $fact ) || ! is_string( $fact['id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $fact['id'] ) || isset( $seen[ $fact['id'] ] ) ) {
				$errors[] = array(
					'path'    => $path . '.id',
					'message' => 'Metric identity is invalid or duplicated.',
				);
				continue;
			}
			if ( array_key_exists( 'provider', $fact ) ) {
				$errors[] = array(
					'path'    => $path . '.provider',
					'message' => 'The retired provider reader shape is unsupported; declare a generic source recipe.',
				);
				continue;
			}
			$seen[ $fact['id'] ] = true;
			$source_errors       = self::validate_source( $fact['source'] ?? null );
			if ( ! empty( $source_errors ) ) {
				$errors[] = array(
					'path'    => $path . '.source',
					'message' => 'External metric source recipe is invalid.',
					'checks'  => $source_errors,
				);
				continue;
			}
			$metric           = $fact['metric'] ?? null;
			$aggregation      = $fact['aggregation'] ?? null;
			$extraction       = $fact['extraction'] ?? null;
			$resources        = $fact['source']['resources'];
			$type             = is_array( $extraction ) ? ( $extraction['value_type'] ?? null ) : null;
			$extract_keys     = is_array( $extraction ) ? array_keys( $extraction ) : array();
			$extract_required = array( 'kind', 'pointer', 'value_type' );
			if ( is_array( $extraction ) && 'string' === $type ) {
				$extract_required[] = 'max_length'; }
			sort( $extract_keys );
			sort( $extract_required );
			$extract_ok     = is_array( $extraction ) && $extract_keys === $extract_required && 'json_pointer' === ( $extraction['kind'] ?? null ) && self::valid_json_pointer( $extraction['pointer'] ?? null ) && in_array( $type, array( 'nonnegative_integer', 'string' ), true ) && ( 'string' !== $type || ( is_int( $extraction['max_length'] ?? null ) && $extraction['max_length'] >= 1 && $extraction['max_length'] <= 255 ) );
			$extract_ok     = 'success_count' === $aggregation && null === $extraction ? true : $extract_ok;
			$aggregation_ok = in_array( $aggregation, array( 'identity', 'sum', 'success_count' ), true )
				&& ( 'identity' !== $aggregation || 1 === count( $resources ) )
				&& ( 'success_count' === $aggregation || $extract_ok )
				&& ( 'sum' !== $aggregation || 'nonnegative_integer' === $type );
			if ( ! is_string( $metric ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9._-]{0,127}$/', $metric ) || ! $aggregation_ok ) {
				$errors[] = array(
					'path'    => $path . '.metric',
					'message' => 'Metric extraction or aggregation is invalid.',
				);
				continue;
			}
			$format        = $fact['format'] ?? null;
			$provenance    = $fact['provenance'] ?? null;
			$fallback      = $fact['fallback'] ?? null;
			$fact_bindings = is_array( $fact['bindings'] ?? null ) ? $fact['bindings'] : array();
			$binding       = $fact_bindings[0] ?? null;
			$format_ok     = is_array( $format ) && is_string( $format['locale'] ?? null ) && preg_match( '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $format['locale'] ) && is_bool( $format['grouping'] ?? null ) && is_string( $format['prefix'] ?? null ) && strlen( $format['prefix'] ) <= 16 && ! preg_match( '/[\\x00-\\x1f\\x7f]/', $format['prefix'] ) && is_string( $format['suffix'] ?? null ) && strlen( $format['suffix'] ) <= 16 && ! preg_match( '/[\\x00-\\x1f\\x7f]/', $format['suffix'] ) && is_int( $format['decimals'] ?? null ) && $format['decimals'] >= 0 && $format['decimals'] <= 4;
			if ( $format_ok && ( preg_match( '/[<>]/', (string) $format['prefix'] ) || preg_match( '/[<>]/', (string) $format['suffix'] ) || ( 'string' === $type && 'success_count' !== $aggregation && ( $format['grouping'] || 0 !== $format['decimals'] || '' !== $format['suffix'] ) ) ) ) {
				$format_ok = false; }
			$provenance_ok  = is_array( $provenance ) && ( ( 'source_corroboration' === ( $provenance['kind'] ?? '' ) && is_string( $provenance['repository'] ?? null ) && is_string( $provenance['revision'] ?? null ) && preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $provenance['revision'] ) && is_string( $provenance['source_path'] ?? null ) ) || ( 'operator_mapping' === ( $provenance['kind'] ?? '' ) && ! empty( $provenance['author'] ) && ! empty( $provenance['source_relationship'] ) ) );
			$fallback_ok    = is_array( $fallback ) && is_string( $fallback['text'] ?? null ) && strlen( $fallback['text'] ) <= 4096 && is_string( $fallback['hash'] ?? null ) && hash_equals( hash( 'sha256', $fallback['text'] ), $fallback['hash'] );
			$binding_source = is_array( $binding ) ? ( $data['source_path'] ?? $binding['source_path'] ?? '' ) : '';
			$binding_ok     = is_array( $binding ) && 'generic/block-binding/v1' === ( $binding['schema'] ?? '' ) && array_key_exists( 'role', $binding ) && in_array( $binding['role'], array( 'paragraph', 'heading' ), true ) && ( $binding['source_path'] ?? null ) === $binding_source && is_string( $binding_source ) && '' !== $binding_source && ! str_contains( $binding_source, '..' ) && is_string( $binding['search_block_markup'] ?? null ) && is_int( $binding['occurrence'] ?? null ) && $binding['occurrence'] > 0 && is_array( $binding['leaf'] ?? null ) && array_key_exists( 'block', $binding['leaf'] ) && in_array( $binding['leaf']['block'], array( 'core/paragraph', 'core/heading' ), true ) && ( ( 'paragraph' === $binding['role'] && 'core/paragraph' === $binding['leaf']['block'] ) || ( 'heading' === $binding['role'] && 'core/heading' === $binding['leaf']['block'] ) ) && 'content' === ( $binding['leaf']['attribute'] ?? '' );
			if ( $binding_ok && ! empty( $data['validate_anchor_content'] ) && function_exists( 'parse_blocks' ) ) {
				$anchor_block = parse_blocks( $binding['search_block_markup'] )[0] ?? null;
				$anchor_text  = is_array( $anchor_block ) ? ( $anchor_block['attrs']['content'] ?? trim( html_entity_decode( wp_strip_all_tags( $anchor_block['innerHTML'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : null;
				$binding_ok   = is_array( $anchor_block ) && ( $binding['leaf']['block'] ?? '' ) === ( $anchor_block['blockName'] ?? '' ) && ( $fallback['text'] ?? null ) === $anchor_text;
			}
			if ( ! $format_ok || ! $provenance_ok || ! $fallback_ok || ! $binding_ok || ! array_is_list( $fact_bindings ) || 1 !== count( $fact_bindings ) ) {
				$errors[] = array(
					'path'    => $path,
					'message' => 'Metric formatting, provenance, fallback, or native text-leaf binding is invalid.',
					'checks'  => array(
						'format'         => (bool) $format_ok,
						'provenance'     => (bool) $provenance_ok,
						'fallback'       => (bool) $fallback_ok,
						'binding'        => (bool) $binding_ok,
						'single_binding' => array_is_list( $fact_bindings ) && 1 === count( $fact_bindings ),
					),
				);
				continue;
			}
			$accepted[] = $fact;
		}
		return array(
			self::COLLECTION => empty( $errors ) ? $accepted : array(),
			'errors'         => $errors,
		);
	}

	/** Validate the bounded, data-only HTTP/JSON source recipe. */
	private static function validate_source( mixed $source ): array {
		$errors = array();
		if ( ! is_array( $source ) || 'generic/external-metric-source/v1' !== ( $source['schema'] ?? null ) || ! is_string( $source['id'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9._-]{0,127}$/', $source['id'] ) || 'external_public_json' !== ( $source['intent'] ?? null ) || ! is_array( $source['request'] ?? null ) || ! is_array( $source['resource_variables'] ?? null ) || ! is_array( $source['resources'] ?? null ) || ! array_is_list( $source['resources'] ) || empty( $source['resources'] ) || count( $source['resources'] ) > 100 || ! is_array( $source['freshness'] ?? null ) || ! is_int( $source['freshness']['max_age_seconds'] ?? null ) || $source['freshness']['max_age_seconds'] < 60 || $source['freshness']['max_age_seconds'] > 2592000 ) {
			return array( 'source' => false );
		}
		$source_keys = array_keys( $source );
		sort( $source_keys );
		if ( array( 'freshness', 'id', 'intent', 'request', 'resource_variables', 'resources', 'schema' ) !== $source_keys ) {
			$errors['source_keys'] = false; }
		$request      = $source['request'];
		$request_keys = array_keys( $request );
		sort( $request_keys );
		if ( array( 'headers', 'max_response_bytes', 'method', 'query', 'query_variables', 'response_media_type', 'timeout_seconds', 'url_template' ) !== $request_keys ) {
			$errors['request_keys'] = false; }
		$url = $request['url_template'] ?? null;
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || strpbrk( $url, "\r\n\\" ) ) {
			$errors['url_template'] = false;
			$url                    = '';
		}
		preg_match_all( '/\\{([a-z][a-z0-9_]*)\\}/', $url, $matches );
		if ( str_contains( str_replace( $matches[0], '', $url ), '{' ) || str_contains( str_replace( $matches[0], '', $url ), '}' ) ) {
			$errors['url_template_variables'] = false; }
		$parsed_template = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone companion validation runs without WordPress URL helpers.
		$parsed_url      = function_exists( 'wp_parse_url' ) ? wp_parse_url( str_replace( $matches[0], 'resource', $url ) ) : parse_url( str_replace( $matches[0], 'resource', $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone companion validation runs without WordPress URL helpers.
		$host            = is_array( $parsed_url ) ? strtolower( (string) ( $parsed_url['host'] ?? '' ) ) : '';
		if ( ! is_array( $parsed_template ) || ! is_array( $parsed_url ) || str_contains( (string) ( $parsed_template['host'] ?? '' ), '{' ) || 'https' !== strtolower( (string) ( $parsed_url['scheme'] ?? '' ) ) || '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/', $host ) || isset( $parsed_url['user'] ) || isset( $parsed_url['pass'] ) || ( isset( $parsed_url['port'] ) && 443 !== $parsed_url['port'] ) || isset( $parsed_url['query'] ) || isset( $parsed_url['fragment'] ) ) {
			$errors['url_template'] = false; }
		$path = (string) ( $parsed_template['path'] ?? '' );
		preg_match_all( '/\\{([a-z][a-z0-9_]*)\\}/', $path, $path_matches );
		if ( count( $matches[1] ) !== count( $path_matches[1] ) || count( $path_matches[1] ) > 16 ) {
			$errors['url_path_variables'] = false; }
		foreach ( explode( '/', rawurldecode( str_replace( $path_matches[0], 'resource', $path ) ) ) as $segment ) {
			if ( in_array( $segment, array( '.', '..' ), true ) ) {
				$errors['url_path_segments'] = false; }
		}
		if ( 'GET' !== ( $request['method'] ?? null ) || 'application/json' !== ( $request['response_media_type'] ?? null ) || ! is_int( $request['max_response_bytes'] ?? null ) || $request['max_response_bytes'] < 1 || $request['max_response_bytes'] > self::MAX_BODY || ! is_int( $request['timeout_seconds'] ?? null ) || $request['timeout_seconds'] < 1 || $request['timeout_seconds'] > 5 ) {
			$errors['request_limits'] = false; }
		$query = $request['query'] ?? null;
		if ( ! is_array( $query ) || count( $query ) > 32 ) {
			$errors['query'] = false;
			$query           = array(); }
		foreach ( $query as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $name ) || preg_match( '/(?:token|secret|password|credential|auth|api[-_]?key)/i', $name ) || ! ( is_string( $value ) || is_int( $value ) || is_bool( $value ) ) || strlen( (string) $value ) > 255 || preg_match( '/[\\x00-\\x1f\\x7f]/', (string) $value ) ) {
				$errors['query_values'] = false; }
		}
		$query_variables = $request['query_variables'] ?? null;
		if ( ! is_array( $query_variables ) || ! array_is_list( $query_variables ) || count( $query_variables ) > 16 || count( $query_variables ) !== count( array_unique( $query_variables, SORT_REGULAR ) ) ) {
			$errors['query_variables'] = false;
			$query_variables           = array(); }
		foreach ( $query_variables as $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) || preg_match( '/(?:token|secret|password|credential|auth|api[-_]?key)/i', $name ) || in_array( $name, $path_matches[1], true ) || array_key_exists( $name, $query ) ) {
				$errors['query_variables'] = false; }
		}
		$variables = array_values( array_unique( array_merge( $path_matches[1], $query_variables ) ) );
		sort( $variables, SORT_STRING );
		$variable_schemas = $source['resource_variables'];
		$variable_names   = array_keys( $variable_schemas );
		sort( $variable_names, SORT_STRING );
		if ( count( $variables ) > 32 || $variables !== $variable_names ) {
			$errors['resource_variable_schema'] = false; }
		foreach ( $variable_schemas as $name => $schema ) {
			$location     = in_array( $name, $path_matches[1], true ) ? 'path' : 'query';
			$schema_keys  = is_array( $schema ) ? array_keys( $schema ) : array();
			$allowed_keys = array( 'location', 'min_length', 'max_length', 'allowed_characters', 'first_characters', 'last_characters', 'prohibited_values' );
			if ( ! is_array( $schema ) || preg_match( '/(?:token|secret|password|credential|auth|api[-_]?key)/i', (string) $name ) || array_diff( $schema_keys, $allowed_keys ) || ! in_array( count( $schema ), array( 5, 6, 7 ), true ) || ! is_string( $schema['location'] ?? null ) || ! hash_equals( $location, (string) $schema['location'] ) || ! is_int( $schema['min_length'] ?? null ) || $schema['min_length'] < 1 || ! is_int( $schema['max_length'] ?? null ) || $schema['max_length'] < $schema['min_length'] || $schema['max_length'] > 255 || ! is_string( $schema['allowed_characters'] ?? null ) || '' === $schema['allowed_characters'] || strlen( $schema['allowed_characters'] ) > 128 || 1 !== preg_match( '/\\A[\\x21-\\x7e]+\\z/D', $schema['allowed_characters'] ) || strlen( count_chars( $schema['allowed_characters'], 3 ) ) !== strlen( $schema['allowed_characters'] ) || ! is_array( $schema['prohibited_values'] ?? null ) || ! array_is_list( $schema['prohibited_values'] ) || count( $schema['prohibited_values'] ) > 16 ) {
				$errors['resource_variable_constraints'] = false;
				continue; }
			foreach ( array( 'first_characters', 'last_characters' ) as $edge ) {
				if ( array_key_exists( $edge, $schema ) && ( ! is_string( $schema[ $edge ] ) || '' === $schema[ $edge ] || strlen( $schema[ $edge ] ) > 128 || strlen( count_chars( $schema[ $edge ], 3 ) ) !== strlen( $schema[ $edge ] ) || strspn( $schema[ $edge ], $schema['allowed_characters'] ) !== strlen( $schema[ $edge ] ) ) ) {
					$errors['resource_variable_constraints'] = false; }
			}
			foreach ( $schema['prohibited_values'] as $prohibited ) {
				if ( ! is_string( $prohibited ) || '' === $prohibited || strlen( $prohibited ) > $schema['max_length'] ) {
					$errors['resource_variable_constraints'] = false; }
			}
		}
		$headers = $request['headers'] ?? null;
		if ( ! is_array( $headers ) || count( $headers ) > 16 ) {
			$errors['headers'] = false;
			$headers           = array(); }
		foreach ( $headers as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $name ) || preg_match( '/(?:token|secret|password|credential|auth|api[-_]?key)/i', $lower ) || in_array( $lower, array( 'host', 'authorization', 'cookie', 'content-length', 'connection', 'transfer-encoding', 'proxy-authorization' ), true ) || str_starts_with( $lower, 'proxy-' ) || ! is_string( $value ) || strlen( $value ) > 255 || preg_match( '/[\\x00-\\x1f\\x7f]/', $value ) ) {
				$errors['headers'] = false; }
		}
		$accept_json = false;
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && 'accept' === strtolower( $name ) && is_string( $value ) && str_contains( strtolower( $value ), 'json' ) ) {
				$accept_json = true; }
		}
		if ( ! $accept_json ) {
			$errors['headers_accept'] = false; }
		$resource_identities = array();
		foreach ( $source['resources'] as $resource ) {
			$resource_keys = is_array( $resource ) ? array_keys( $resource ) : array();
			sort( $resource_keys, SORT_STRING );
			if ( ! is_array( $resource ) || ( array() !== $resource && array_is_list( $resource ) ) || $resource_keys !== $variables ) {
				$errors['resources'] = false;
				continue; }
			$identity = hash( 'sha256', self::canonical_json( $resource ) );
			if ( isset( $resource_identities[ $identity ] ) ) {
				$errors['resources'] = false; }
			$resource_identities[ $identity ] = true;
			foreach ( $resource as $name => $value ) {
				$variable_schema = $variable_schemas[ $name ] ?? null;
				if ( ! is_array( $variable_schema ) || ! is_string( $value ) || strlen( $value ) < (int) ( $variable_schema['min_length'] ?? 0 ) || strlen( $value ) > (int) ( $variable_schema['max_length'] ?? 0 ) || strspn( $value, (string) ( $variable_schema['allowed_characters'] ?? '' ) ) !== strlen( $value ) || ( isset( $variable_schema['first_characters'] ) && ! str_contains( $variable_schema['first_characters'], $value[0] ) ) || ( isset( $variable_schema['last_characters'] ) && ! str_contains( $variable_schema['last_characters'], $value[ strlen( $value ) - 1 ] ) ) || in_array( $value, $variable_schema['prohibited_values'] ?? array(), true ) || preg_match( '/[\\x00-\\x1f\\x7f]/', $value ) || ( 'path' === ( $variable_schema['location'] ?? '' ) && ( str_contains( $value, '/' ) || str_contains( $value, '\\' ) || in_array( $value, array( '.', '..' ), true ) ) ) ) {
					$errors['resources'] = false; }
			}
		}
		$freshness_keys = array_keys( $source['freshness'] );
		sort( $freshness_keys );
		if ( array( 'max_age_seconds' ) !== $freshness_keys ) {
			$errors['freshness'] = false; }
		return $errors;
	}

	private static function canonical_json( mixed $value ): string {
		$encoded = wp_json_encode( self::canonical_value( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}

	private static function canonical_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value; }
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'canonical_value' ), $value ); }
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $entry ) {
			$value[ $key ] = self::canonical_value( $entry ); }
		return $value;
	}

	private static function valid_json_pointer( mixed $pointer ): bool {
		if ( ! is_string( $pointer ) || strlen( $pointer ) > 1024 || ( '' !== $pointer && ! str_starts_with( $pointer, '/' ) ) ) {
			return false; }
		if ( '' === $pointer ) {
			return true; }
		$segments = explode( '/', substr( $pointer, 1 ) );
		if ( count( $segments ) > 32 ) {
			return false; }
		foreach ( $segments as $segment ) {
			if ( preg_match( '/~(?![01])/', $segment ) || preg_match( '/[\\x00-\\x1f\\x7f]/', $segment ) ) {
				return false; }
		}
		return true;
	}

	/** Build no-write provider receipt; fetches happen only on frontend requests. */
	public static function materialize( array $manifest, array $args = array() ): array {
		unset( $args );
		$rows = $manifest[ self::COLLECTION ] ?? array();
		return array(
			'status'         => 'completed',
			'provider'       => 'external_source',
			'counts'         => array(
				'mapped'  => count( $rows ),
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			self::COLLECTION => array_map(
				static fn( array $row ): array => array(
					'id'        => $row['id'],
					'status'    => 'mapped',
					'metric_id' => $row['id'],
				),
				$rows
			),
		);
	}

	public static function rollback( array $report ): array {
		return array(
			'status' => 'rolled_back',
			'reason' => 'no_persistent_entity',
			'report' => $report,
		); }

	/** Return a canonical native paragraph/heading with its captured leaf fallback. */
	public static function binding_markup( array $entity, array $result ): string {
		if ( ( $result['status'] ?? '' ) !== 'mapped' || ! is_array( $entity['fallback'] ?? null ) ) {
			return ''; }
		$block = (string) ( $entity['bindings'][0]['leaf']['block'] ?? '' );
		if ( ! in_array( $block, array( 'core/paragraph', 'core/heading' ), true ) ) {
			return ''; }
		$id      = (string) $entity['id'];
		$content = (string) $entity['fallback']['text'];
		$parsed  = function_exists( 'parse_blocks' ) ? parse_blocks( (string) ( $entity['bindings'][0]['search_block_markup'] ?? '' ) ) : array();
		if ( ! is_array( $parsed[0] ?? null ) || ( $parsed[0]['blockName'] ?? '' ) !== $block ) {
			return ''; }
		$attributes             = $parsed[0]['attrs'];
		$metadata               = is_array( $attributes['metadata'] ?? null ) ? $attributes['metadata'] : array();
		$bindings               = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : array();
		$bindings['content']    = array(
			'source' => self::SOURCE,
			'args'   => array( 'metric_id' => $id ),
		);
		$metadata['bindings']   = $bindings;
		$attributes['metadata'] = $metadata;
		$parsed[0]['attrs']     = $attributes;
		return serialize_block( $parsed[0] );
	}

	/** Interpret one declarative source recipe through the shared fetch/cache lifecycle. */
	public static function value( string $metric_id, array $metrics, ?callable $request = null, ?int $now = null, bool $force = false ): ?string {
		static $request_values        = array();
		static $request_responses     = array();
		static $request_source_hashes = array();
		if ( $force ) {
			unset( $request_values[ $metric_id ] ); }
		if ( isset( $request_values[ $metric_id ] ) ) {
			return $request_values[ $metric_id ]; }
		$fact = $metrics[ $metric_id ] ?? null;
		if ( ! is_array( $fact ) || ! empty(
			self::validate_manifest(
				array(
					self::COLLECTION => array( $fact ),
					'source_path'    => $fact['bindings'][0]['source_path'] ?? '',
				)
			)['errors']
		) ) {
			return null; }
		$cache_material = self::canonical_json( array( $fact['source'], $fact['metric'], $fact['extraction'] ?? null, $fact['aggregation'], $fact['format'] ) );
		$recipe_hash    = hash( 'sha256', $cache_material );
		$key            = 'ssi_external_metric_' . $recipe_hash;
		$now            = $now ?? time();
		$max_age        = (int) $fact['source']['freshness']['max_age_seconds'];
		$source_id      = (string) $fact['source']['id'];
		$source_hash    = hash( 'sha256', self::canonical_json( $fact['source'] ) );
		$source_key     = 'ssi_external_metric_source_' . $source_hash;
		foreach ( $metrics as $candidate_id => $candidate ) {
			if ( is_string( $candidate_id ) && is_array( $candidate['source'] ?? null ) ) {
				$request_source_hashes[ $candidate_id ] = hash( 'sha256', self::canonical_json( $candidate['source'] ) ); }
		}
		if ( $force ) {
			foreach ( $request_source_hashes as $candidate_id => $candidate_hash ) {
				if ( hash_equals( $source_hash, $candidate_hash ) ) {
					unset( $request_values[ $candidate_id ] ); }
			}
		}
		$source_cached     = function_exists( 'get_transient' ) ? get_transient( $source_key ) : false;
		$use_source_cache  = ! $force && is_array( $source_cached ) && 'fresh' === ( $source_cached['status'] ?? null ) && is_string( $source_cached['source_hash'] ?? null ) && hash_equals( $source_hash, $source_cached['source_hash'] ) && is_int( $source_cached['fetched_at'] ?? null ) && $now >= $source_cached['fetched_at'] && $max_age > ( $now - $source_cached['fetched_at'] ) && is_array( $source_cached['documents'] ?? null ) && array_is_list( $source_cached['documents'] ) && count( $source_cached['documents'] ) === count( $fact['source']['resources'] );
		$source_fetched_at = $use_source_cache ? (int) $source_cached['fetched_at'] : $now;
		$cached            = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( ! $force && is_array( $cached ) && 'fresh' === ( $cached['status'] ?? null ) && is_string( $cached['value'] ?? null ) && is_int( $cached['fetched_at'] ?? null ) && $now >= $cached['fetched_at'] && $max_age > ( $now - $cached['fetched_at'] ) && is_string( $cached['source_id'] ?? null ) && hash_equals( $source_id, $cached['source_id'] ) && is_string( $cached['recipe_hash'] ?? null ) && hash_equals( $recipe_hash, $cached['recipe_hash'] ) && ( ! $use_source_cache || ( $cached['source_fetched_at'] ?? null ) === $source_fetched_at ) ) {
			// The transient is a canonical source receipt shared by facts with the
			// same provider semantics. Hand that exact receipt to this fact so its
			// binding can render the cached value without inventing freshness data.
			self::store_receipt( $metric_id, $cached );
			$request_values[ $metric_id ] = $cached['value'];
			return $cached['value']; }
		$retry_at = get_option( 'static_site_importer_external_metric_retry_after', array() );
		if ( ! $force && is_array( $retry_at ) && (int) ( $retry_at[ $key ] ?? 0 ) > $now ) {
			return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
		$documents  = $use_source_cache ? $source_cached['documents'] : array();
		$fetched_at = $source_fetched_at;
		$deadline   = microtime( true ) + 15.0;
		if ( ! $use_source_cache ) {
			foreach ( $fact['source']['resources'] as $resource ) {
				if ( microtime( true ) >= $deadline ) {
					return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				$parts = self::request_parts( $fact['source'], $resource );
				if ( ! is_array( $parts ) || ! self::public_request_url( $parts['url'] ) ) {
					return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				$url          = $parts['url'];
				$response_key = hash( 'sha256', $url . self::canonical_json( $parts['headers'] ) );
				if ( $force ) {
					unset( $request_responses[ $response_key ] ); }
				if ( array_key_exists( $response_key, $request_responses ) ) {
					$response = $request_responses[ $response_key ];
				} elseif ( null !== $request ) {
					$response = $request( $url );
				} else {
					$response = wp_safe_remote_get(
						$url,
						array(
							'timeout'             => (int) $fact['source']['request']['timeout_seconds'],
							'redirection'         => 0,
							'reject_unsafe_urls'  => true,
							'limit_response_size' => (int) $fact['source']['request']['max_response_bytes'],
							'headers'             => $parts['headers'],
						)
					);
				}
				$request_responses[ $response_key ] = $response;
				if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! self::json_response( $response, (int) $fact['source']['request']['max_response_bytes'] ) ) {
					return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) || ! str_starts_with( ltrim( $body ), '{' ) ) {
					return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				$documents[] = $data;
			}
		}
		$values = array();
		foreach ( $documents as $data ) {
			if ( is_array( $fact['extraction'] ?? null ) ) {
				$extracted = self::json_pointer_value( $data, (string) $fact['extraction']['pointer'] );
				$typed     = self::typed_value( $extracted, $fact['extraction'] );
				if ( null === $typed ) {
					if ( $use_source_cache && function_exists( 'delete_transient' ) ) {
						delete_transient( $source_key ); }
					return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				if ( 'success_count' !== $fact['aggregation'] ) {
					$values[] = $typed; }
			} elseif ( 'success_count' !== $fact['aggregation'] ) {
				return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
			if ( 'success_count' === $fact['aggregation'] ) {
				$values[] = 1; }
		}
		if ( empty( $values ) ) {
			return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
		$value = $values[0];
		if ( 'sum' === $fact['aggregation'] || 'success_count' === $fact['aggregation'] ) {
			$value = 0;
			foreach ( $values as $count ) {
				if ( ! is_int( $count ) || $count < 0 || $count > self::MAX_COUNT - $value ) {
					return self::stale_or_fallback( $metric_id, $fact, $key, $cached, $request_values ); }
				$value += $count;
			}
		}
		$formatted = self::format_value( $value, $fact['format'] );
		if ( ! $use_source_cache && function_exists( 'set_transient' ) ) {
			$source_documents = wp_json_encode( $documents );
			if ( is_string( $source_documents ) && strlen( $source_documents ) <= self::MAX_BODY ) {
				set_transient(
					$source_key,
					array(
						'status'      => 'fresh',
						'source_hash' => $source_hash,
						'fetched_at'  => $fetched_at,
						'documents'   => $documents,
					),
					$max_age
				);
			}
		}
		$receipt = array(
			'status'            => 'fresh',
			'value'             => $formatted,
			'fetched_at'        => $fetched_at,
			'source_fetched_at' => $fetched_at,
			'source_id'         => $source_id,
			'recipe_hash'       => $recipe_hash,
		);
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $receipt, $max_age ); }
		$lkg         = get_option( 'static_site_importer_external_metric_last_good', array() );
		$lkg         = is_array( $lkg ) ? $lkg : array();
		$lkg[ $key ] = $receipt;
		update_option( 'static_site_importer_external_metric_last_good', $lkg, false );
		self::store_receipt( $metric_id, $receipt );
		$request_values[ $metric_id ] = $formatted;
		return $formatted;
	}

	private static function stale_or_fallback( string $id, array $fact, string $key, mixed $cached, array &$request_values ): string {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key ); }
		$retry         = get_option( 'static_site_importer_external_metric_retry_after', array() );
		$retry         = is_array( $retry ) ? $retry : array();
		$retry[ $key ] = time() + 60;
		update_option( 'static_site_importer_external_metric_retry_after', $retry, false );
		$lkg         = get_option( 'static_site_importer_external_metric_last_good', array() );
		$last_good   = is_array( $lkg ) && is_array( $lkg[ $key ] ?? null ) ? $lkg[ $key ] : null;
		$value       = is_array( $last_good ) && is_string( $last_good['value'] ?? null ) ? $last_good['value'] : (string) $fact['fallback']['text'];
		$status      = is_array( $last_good ) ? 'stale' : ( '' !== $value ? 'captured_fallback' : 'unresolved' );
		$recipe_hash = hash( 'sha256', self::canonical_json( array( $fact['source'], $fact['metric'], $fact['extraction'] ?? null, $fact['aggregation'], $fact['format'] ) ) );
		$receipt     = array(
			'status'            => $status,
			'value'             => $value,
			'fetched_at'        => (int) ( $last_good['fetched_at'] ?? 0 ),
			'source_fetched_at' => (int) ( $last_good['source_fetched_at'] ?? $last_good['fetched_at'] ?? 0 ),
			'source_id'         => (string) ( $fact['source']['id'] ?? '' ),
			'recipe_hash'       => $recipe_hash,
		);
		self::store_receipt( $id, $receipt );
		$request_values[ $id ] = $value;
		return $value;
	}

	private static function format_value( mixed $value, array $format ): string {
		if ( is_int( $value ) && 0 === (int) $format['decimals'] ) {
			$locale   = strtolower( str_replace( '_', '-', (string) $format['locale'] ) );
			$decimal  = preg_match( '/^(de|es|it|pt|nl|ru|tr|pl|fr|da|sv|no|fi|cs|sk|hu)(-|$)/', $locale ) ? ',' : '.';
			$thousand = ',' === $decimal ? ( str_starts_with( $locale, 'fr' ) ? "\u{202f}" : '.' ) : ',';
			$digits   = (string) $value;
			if ( ! empty( $format['grouping'] ) ) {
				$digits = preg_replace( '/\\B(?=(?:[0-9]{3})+(?![0-9]))/', $thousand, $digits ); }
			return (string) $format['prefix'] . $digits . (string) $format['suffix'];
		}
		if ( is_numeric( $value ) ) {
			$formatted = false;
			if ( class_exists( 'NumberFormatter' ) ) {
				$formatter = new NumberFormatter( (string) $format['locale'], NumberFormatter::DECIMAL );
				$formatter->setAttribute( NumberFormatter::GROUPING_USED, ! empty( $format['grouping'] ) ? 1 : 0 );
				$formatter->setAttribute( NumberFormatter::MIN_FRACTION_DIGITS, (int) $format['decimals'] );
				$formatter->setAttribute( NumberFormatter::MAX_FRACTION_DIGITS, (int) $format['decimals'] );
				$formatted = $formatter->format( (float) $value );
			}
			if ( ! is_string( $formatted ) ) {
				$locale    = strtolower( str_replace( '_', '-', (string) $format['locale'] ) );
				$decimal   = preg_match( '/^(de|es|it|pt|nl|ru|tr|pl|fr|da|sv|no|fi|cs|sk|hu)(-|$)/', $locale ) ? ',' : '.';
				$thousand  = ',' === $decimal ? ( str_starts_with( $locale, 'fr' ) ? "\u{202f}" : '.' ) : ',';
				$formatted = number_format( (float) $value, (int) $format['decimals'], $decimal, ! empty( $format['grouping'] ) ? $thousand : '' );
			}
			$value = $formatted;
		}
		return (string) $format['prefix'] . (string) $value . (string) $format['suffix'];
	}

	private static function request_parts( array $source, array $resource_values ): ?array {
		$request = $source['request'];
		$url     = preg_replace_callback(
			'/\\{([A-Za-z][A-Za-z0-9_]*)\\}/',
			static function ( array $placeholder_parts ) use ( $resource_values ): string {
				$value = (string) ( $resource_values[ $placeholder_parts[1] ] ?? '' );
				return in_array( $value, array( '.', '..' ), true ) ? '' : rawurlencode( $value );
			},
			(string) $request['url_template']
		);
		if ( ! is_string( $url ) || str_contains( $url, '{' ) || str_contains( $url, '}' ) ) {
			return null; }
		$query = $request['query'];
		foreach ( $request['query_variables'] as $name ) {
			if ( ! array_key_exists( $name, $resource_values ) ) {
				return null; }
			$query[ $name ] = (string) $resource_values[ $name ];
		}
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url ); }
		return array(
			'url'     => $url,
			'headers' => $request['headers'],
		);
	}

	private static function public_request_url( string $url ): bool {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone companion runtime tests run without WordPress URL helpers.
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false; }
		$host = (string) $parts['host'];
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$public = class_exists( 'Static_Site_Importer_IP_Classifier' ) && Static_Site_Importer_IP_Classifier::is_public( $host );
		} else {
			$records = function_exists( 'dns_get_record' ) ? dns_get_record( $host, DNS_A | DNS_AAAA ) : array();
			$ips     = array();
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				$ip = $record['ip'] ?? $record['ipv6'] ?? null;
				if ( is_string( $ip ) ) {
					$ips[] = $ip; }
			}
			$public = ! empty( $ips ) && class_exists( 'Static_Site_Importer_IP_Classifier' );
			foreach ( $ips as $ip ) {
				$public = $public && Static_Site_Importer_IP_Classifier::is_public( $ip ); }
		}
		return $public && ( ! function_exists( 'wp_http_validate_url' ) || false !== wp_http_validate_url( $url ) );
	}

	private static function json_response( mixed $response, int $max_bytes ): bool {
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > $max_bytes ) {
			return false; }
		$content_type = function_exists( 'wp_remote_retrieve_header' ) ? wp_remote_retrieve_header( $response, 'content-type' ) : '';
		if ( ! is_string( $content_type ) ) {
			return false; }
		return (bool) preg_match( '~^application/(?:[a-z0-9.+-]*\\+)?json(?:\\s*;|$)~i', trim( $content_type ) );
	}

	private static function json_pointer_value( array $data, string $pointer ): mixed {
		$value = $data;
		if ( '' === $pointer ) {
			return $value; }
		foreach ( explode( '/', substr( $pointer, 1 ) ) as $segment ) {
			$segment = str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );
			if ( ! is_array( $value ) ) {
				return null; }
			if ( array_is_list( $value ) ) {
				if ( ! preg_match( '/^(?:0|[1-9][0-9]*)$/', $segment ) || ! array_key_exists( (int) $segment, $value ) ) {
					return null; }
				$value = $value[ (int) $segment ];
			} elseif ( array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return null;
			}
		}
		return $value;
	}

	private static function typed_value( mixed $value, array $extraction ): int|string|null {
		$type = (string) ( $extraction['value_type'] ?? '' );
		if ( 'nonnegative_integer' === $type ) {
			return self::count_value( $value ); }
		if ( 'string' === $type && is_string( $value ) && '' !== $value && strlen( $value ) <= (int) ( $extraction['max_length'] ?? 0 ) && 1 === preg_match( '//u', $value ) && ! preg_match( '/[<>\\x00-\\x1f\\x7f]/', $value ) ) {
			return $value; }
		return null;
	}

	/** Parse a safe nonnegative integer without coercing floating point values. */
	private static function count_value( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			$count = $value;
		} elseif ( is_string( $value ) && preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) ) {
			$maximum = (string) self::MAX_COUNT;
			if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
				return null; }
			$count = (int) $value;
		} else {
			return null;
		}
		return $count >= 0 && $count <= self::MAX_COUNT ? $count : null;
	}

	private static function store_receipt( string $id, array $receipt ): void {
		$all        = get_option( 'static_site_importer_external_metric_receipts', array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = $receipt;
		update_option( 'static_site_importer_external_metric_receipts', $all, false );
	}
}
