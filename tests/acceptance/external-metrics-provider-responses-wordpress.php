<?php
/** Reproduce provider payload coercions against the generated plugin runtime. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric response reproduction requires disposable WordPress.' ); }

$metric_facts = get_option( 'ssi_external_metric_acceptance_facts', array() );
$metric_facts = is_array( $metric_facts ) ? array_column( $metric_facts, null, 'id' ) : array();
$plugin_file  = (string) get_option( 'static_site_importer_active_companion_plugin', '' );
$runtime_file = WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/includes/external-metric-runtime.php';
$source       = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the generated companion provider class.
preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $class_match );
$runtime_class = $class_match[1] ?? '';
if ( '' === $runtime_class || ! class_exists( $runtime_class ) ) {
	throw new RuntimeException( 'Generated companion provider runtime is unavailable.' ); }

$run_response       = static function ( array $fact, array $responses ) use ( $runtime_class ): array {
	$requests = array();
	$filter   = static function ( mixed $preempt, array $args, string $url ) use ( &$responses, &$requests ): mixed {
		$requests[] = $url;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$slug     = (string) ( $query['slug'] ?? '' );
		$response = array_shift( $responses[ $slug ] );
		if ( $response instanceof WP_Error ) {
			return $response; }
		$status = is_array( $response ) && isset( $response['_http_status'] ) ? (int) $response['_http_status'] : 200;
		if ( is_array( $response ) ) {
			unset( $response['_http_status'] ); }
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $response ) ? $response : wp_json_encode( $response ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Injected outage',
			),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	try {
		$value = call_user_func( array( $runtime_class, 'value' ), $fact['id'], array( $fact['id'] => $fact ), null, null, true );
	} finally {
		remove_filter( 'pre_http_request', $filter, 10 );
	}
	$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
	return array(
		'value'    => $value,
		'receipt'  => $receipts[ $fact['id'] ] ?? array(),
		'requests' => $requests,
	);
};
$run_route_response = static function ( array $fact, array $responses ) use ( $runtime_class ): array {
	$requests   = array();
	$filter     = static function ( mixed $preempt, array $args, string $url ) use ( &$responses, &$requests ): mixed {
		$requests[] = $url;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$slug     = (string) ( $query['slug'] ?? '' );
		$response = array_shift( $responses[ $slug ] );
		if ( $response instanceof WP_Error ) {
			return $response; }
		$status = is_array( $response ) && isset( $response['_http_status'] ) ? (int) $response['_http_status'] : 200;
		if ( is_array( $response ) ) {
			unset( $response['_http_status'] ); }
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $response ) ? $response : wp_json_encode( $response ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Injected outage',
			),
			'cookies'  => array(),
		);
	};
	$admin_user = get_user_by( 'login', 'admin' );
	if ( $admin_user instanceof WP_User ) {
		wp_set_current_user( (int) $admin_user->ID ); }
	add_filter( 'pre_http_request', $filter, 10, 3 );
	try {
		$request  = new WP_REST_Request( 'POST', '/ssi/v1/external-metrics/' . rawurlencode( $fact['id'] ) . '/refresh' );
		$response = rest_do_request( $request );
	} finally {
		remove_filter( 'pre_http_request', $filter, 10 );
	}
	return array(
		'http_status' => $response->get_status(),
		'data'        => $response->get_data(),
		'requests'    => $requests,
	);
};

$version_fact  = $metric_facts['project-version'] ?? array();
$version_cases = array(
	'version_array'   => array( 'version' => array( '1.2.3' ) ),
	'version_object'  => array( 'version' => array( 'number' => '1.2.3' ) ),
	'version_boolean' => array( 'version' => true ),
);
$results       = array();
foreach ( $version_cases as $label => $payload ) {
	$fact                        = $version_fact;
	$fact['id']                  = 'review-' . $label;
	$fact['source']['resources'] = array( array( 'slug' => 'review-' . str_replace( '_', '-', $label ) ) );
	$fact['fallback']['text']    = 'v-captured';
	$fact['fallback']['hash']    = hash( 'sha256', $fact['fallback']['text'] );
	$results[ $label ]           = $run_response( $fact, array( $fact['source']['resources'][0]['slug'] => array( $payload ) ) );
}
$numeric_version_cases = array(
	'version_decimal_string'      => array( 'version' => '1.20' ),
	'version_leading_zero_string' => array( 'version' => '0012' ),
	'version_literal_angle_text'  => array( 'version' => '<img src=x onerror=alert(1)>' ),
);
foreach ( $numeric_version_cases as $label => $payload ) {
	$fact                        = $version_fact;
	$fact['id']                  = 'review-' . $label;
	$fact['source']['resources'] = array( array( 'slug' => 'review-' . str_replace( '_', '-', $label ) ) );
	$fact['fallback']['text']    = 'v-captured';
	$fact['fallback']['hash']    = hash( 'sha256', $fact['fallback']['text'] );
	$results[ $label ]           = $run_response( $fact, array( $fact['source']['resources'][0]['slug'] => array( $payload ) ) );
}

$installs_fact = $metric_facts['active-installs'] ?? array();
$numeric_cases = array(
	'numeric_fraction'   => array( 'active_installs' => 12.5 ),
	'numeric_overflow'   => '{"active_installs":1e309}',
	'ratings_fraction'   => array( 'num_ratings' => 3.5 ),
	'downloads_overflow' => array( 'all_time' => '9223372036854775808' ),
);
foreach ( $numeric_cases as $label => $payload ) {
	$fact                        = str_starts_with( $label, 'ratings' ) ? ( $metric_facts['project-ratings'] ?? array() ) : ( str_starts_with( $label, 'downloads' ) ? ( $metric_facts['all-time-downloads'] ?? array() ) : $installs_fact );
	$fact['id']                  = 'review-' . $label;
	$fact['source']['resources'] = array( array( 'slug' => 'review-' . str_replace( '_', '-', $label ) ) );
	$fact['fallback']['text']    = 'captured-count';
	$fact['fallback']['hash']    = hash( 'sha256', $fact['fallback']['text'] );
	$results[ $label ]           = $run_response( $fact, array( $fact['source']['resources'][0]['slug'] => array( $payload ) ) );
}

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message; }
};
foreach ( array_merge( $version_cases, $numeric_cases ) as $label => $payload ) {
	$result            = $results[ $label ] ?? array();
	$fact              = str_starts_with( $label, 'version' ) ? $version_fact : ( str_starts_with( $label, 'ratings' ) ? ( $metric_facts['project-ratings'] ?? array() ) : ( str_starts_with( $label, 'downloads' ) ? ( $metric_facts['all-time-downloads'] ?? array() ) : $installs_fact ) );
	$expected_fallback = str_starts_with( $label, 'version' ) ? 'v-captured' : 'captured-count';
	$assert( ( $result['value'] ?? null ) === $expected_fallback, 'Malformed provider response returns exact captured fallback: ' . $label . '; observed ' . wp_json_encode( $result ) );
	$assert( 'captured_fallback' === ( $result['receipt']['status'] ?? '' ), 'Malformed provider response is not labeled fresh: ' . $label );
}
$assert( 'v1.20' === ( $results['version_decimal_string']['value'] ?? null ) && 'fresh' === ( $results['version_decimal_string']['receipt']['status'] ?? '' ), 'WordPress.org string version 1.20 keeps its exact lexical value at decimals zero.' );
$assert( 'v0012' === ( $results['version_leading_zero_string']['value'] ?? null ) && 'fresh' === ( $results['version_leading_zero_string']['receipt']['status'] ?? '' ), 'WordPress.org string version 0012 preserves leading zeros through the generic formatter.' );
$assert( 'v<img src=x onerror=alert(1)>' === ( $results['version_literal_angle_text']['value'] ?? null ) && 'fresh' === ( $results['version_literal_angle_text']['receipt']['status'] ?? '' ), 'WordPress.org version extraction retains literal angle text for escaping at the native rich-text boundary.' );

$http200_error_fact                        = $metric_facts['project-count'] ?? array();
$http200_error_fact['id']                  = 'review-http200-error-json-count';
$http200_error_fact['source']['resources'] = array( array( 'slug' => 'review-http200-error-json-count' ) );
$http200_error_fact['fallback']['text']    = 'captured-success-count';
$http200_error_fact['fallback']['hash']    = hash( 'sha256', 'captured-success-count' );
$http200_error_count                       = $run_response( $http200_error_fact, array( 'review-http200-error-json-count' => array( array( 'error' => 'temporarily unavailable' ) ) ) );
$assert( 'captured-success-count' === ( $http200_error_count['value'] ?? null ) && 'captured_fallback' === ( $http200_error_count['receipt']['status'] ?? '' ), 'Generic typed extraction prerequisite prevents HTTP-200 JSON error objects from incrementing success_count.' );

$route_fact                                   = $installs_fact;
$route_fact['id']                             = 'review-route-last-good';
$route_fact['source']['resources']            = array( array( 'slug' => 'review-route-last-good' ) );
$route_fact['fallback']['text']               = 'route-captured';
$route_fact['fallback']['hash']               = hash( 'sha256', $route_fact['fallback']['text'] );
$route_unresolved_fact                        = $installs_fact;
$route_unresolved_fact['id']                  = 'review-route-unresolved';
$route_unresolved_fact['source']['resources'] = array( array( 'slug' => 'review-route-unresolved' ) );
$route_unresolved_fact['fallback']['text']    = '';
$route_unresolved_fact['fallback']['hash']    = hash( 'sha256', '' );
$assert( 10 === count( $metric_facts ) && isset( $metric_facts['project-count'], $metric_facts['neutral-title-paragraph'], $metric_facts['neutral-title-heading'] ), 'Generated companion contains every producer-compiled metric declaration, including typed success_count and neutral plain-text leaves.' );
call_user_func( array( $runtime_class, 'configure' ), array_merge( array_values( $metric_facts ), array( $route_fact, $route_unresolved_fact ) ) );
$route_validation = call_user_func(
	array( $runtime_class, 'validate_manifest' ),
	array(
		'external_metrics' => array( $route_fact ),
		'source_path'      => $route_fact['bindings'][0]['source_path'] ?? '',
	)
);
$route_direct     = $run_response( $route_fact, array( 'review-route-last-good' => array( array( 'active_installs' => 321 ) ) ) );
$assert( empty( $route_validation['errors'] ), 'Injected REST metric fixture validates: ' . wp_json_encode( $route_validation ) );
$assert( '321+' === ( $route_direct['value'] ?? null ), 'Generated runtime directly computes the controlled route fixture: ' . wp_json_encode( $route_direct ) );
$route_fresh      = $run_route_response( $route_fact, array( 'review-route-last-good' => array( array( 'active_installs' => 321 ) ) ) );
$route_stale      = $run_route_response( $route_fact, array( 'review-route-last-good' => array( array( '_http_status' => 503 ) ) ) );
$route_unresolved = $run_route_response( $route_unresolved_fact, array( 'review-route-unresolved' => array( array( '_http_status' => 503 ) ) ) );
$assert( 200 === ( $route_fresh['http_status'] ?? null ) && 'fresh' === ( $route_fresh['data']['status'] ?? '' ) && 'fresh' === ( $route_fresh['data']['receipt']['status'] ?? '' ) && '321+' === ( $route_fresh['data']['value'] ?? null ), 'Refresh route reports the real fresh receipt and numeric value: ' . wp_json_encode( $route_fresh ) );
$assert( 200 === ( $route_stale['http_status'] ?? null ) && 'stale' === ( $route_stale['data']['status'] ?? '' ) && 'stale' === ( $route_stale['data']['receipt']['status'] ?? '' ) && '321+' === ( $route_stale['data']['value'] ?? null ), 'Injected upstream HTTP 503 reaches the route as stale LKG, never refreshed: ' . wp_json_encode( $route_stale ) );
$assert( 503 === ( $route_unresolved['http_status'] ?? null ) && 'unresolved' === ( $route_unresolved['data']['data']['freshness'] ?? '' ), 'Refresh route returns a meaningful 503 freshness error when no captured or last-good value exists: ' . wp_json_encode( $route_unresolved ) );

$invalid_route_fact                        = $installs_fact;
$invalid_route_fact['id']                  = 'review-route-invalid';
$invalid_route_fact['source']['resources'] = array( array( 'slug' => 'review-route-invalid' ) );
$invalid_route_fact['fallback']['text']    = 'route-invalid-fallback';
$invalid_route_fact['fallback']['hash']    = hash( 'sha256', $invalid_route_fact['fallback']['text'] );
call_user_func( array( $runtime_class, 'configure' ), array_merge( array_values( $metric_facts ), array( $route_fact, $invalid_route_fact ) ) );
$invalid_route = $run_route_response( $invalid_route_fact, array( 'review-route-invalid' => array( array( 'active_installs' => 12.5 ) ) ) );
$assert( 'captured_fallback' === ( $invalid_route['data']['status'] ?? '' ) && 'route-invalid-fallback' === ( $invalid_route['data']['value'] ?? null ), 'Refresh route returns an invalid fractional-count response as the captured fallback: ' . wp_json_encode( $invalid_route ) );

$partial_route_fact                        = $installs_fact;
$partial_route_fact['id']                  = 'review-route-partial';
$partial_route_fact['source']['resources'] = array( array( 'slug' => 'review-route-partial-a' ), array( 'slug' => 'review-route-partial-b' ) );
$partial_route_fact['fallback']['text']    = 'route-partial-fallback';
$partial_route_fact['fallback']['hash']    = hash( 'sha256', $partial_route_fact['fallback']['text'] );
call_user_func( array( $runtime_class, 'configure' ), array_merge( array_values( $metric_facts ), array( $route_fact, $invalid_route_fact, $partial_route_fact ) ) );
$partial_route   = $run_route_response(
	$partial_route_fact,
	array(
		'review-route-partial-a' => array( array( 'active_installs' => 10 ) ),
		'review-route-partial-b' => array( array( 'error' => 'temporarily unavailable' ) ),
	)
);
$recovered_route = $run_route_response(
	$partial_route_fact,
	array(
		'review-route-partial-a' => array( array( 'active_installs' => 20 ) ),
		'review-route-partial-b' => array( array( 'active_installs' => 30 ) ),
	)
);
$assert( 'captured_fallback' === ( $partial_route['data']['status'] ?? '' ) && 'route-partial-fallback' === ( $partial_route['data']['value'] ?? null ), 'Refresh route never reports a partial aggregate as refreshed: ' . wp_json_encode( $partial_route ) );
$assert( 'fresh' === ( $recovered_route['data']['status'] ?? '' ) && '50+' === ( $recovered_route['data']['value'] ?? null ), 'Refresh route reports a full fresh aggregate after recovery: ' . wp_json_encode( $recovered_route ) );

$all_facts = array_merge( array_values( $metric_facts ), array( $route_fact, $invalid_route_fact, $partial_route_fact ) );
call_user_func( array( $runtime_class, 'configure' ), $all_facts );
$native_markup = static function ( array $fact, string $tag ): string {
	$block_name = 'h2' === $tag ? 'heading' : 'paragraph';
	$attributes = array(
		'metadata' => array(
			'name'     => $fact['id'],
			'bindings' => array(
				'content' => array(
					'source' => 'ssi/external-metric',
					'args'   => array( 'metric_id' => $fact['id'] ),
				),
			),
		),
	);
	if ( 'heading' === $block_name ) {
		$attributes['level'] = 2;
	}
	return '<!-- wp:' . $block_name . ' ' . wp_json_encode( $attributes ) . ' --><' . $tag . '>' . esc_html( $fact['fallback']['text'] ) . '</' . $tag . '><!-- /wp:' . $block_name . ' -->';
};
$literal_fact  = $metric_facts['project-version'];
$version_key   = 'ssi_external_metric_' . (string) ( get_option( 'static_site_importer_external_metric_receipts', array() )['project-version']['recipe_hash'] ?? '' );
delete_transient( $version_key );
$last_good = get_option( 'static_site_importer_external_metric_last_good', array() );
$last_good = is_array( $last_good ) ? $last_good : array();
unset( $last_good[ $version_key ] );
update_option( 'static_site_importer_external_metric_last_good', $last_good, false );
$retry_after = get_option( 'static_site_importer_external_metric_retry_after', array() );
$retry_after = is_array( $retry_after ) ? $retry_after : array();
unset( $retry_after[ $version_key ] );
update_option( 'static_site_importer_external_metric_retry_after', $retry_after, false );
$canonicalize     = null;
$canonicalize     = static function ( mixed $value ) use ( &$canonicalize ): mixed {
	if ( ! is_array( $value ) ) {
		return $value; }
	if ( array_is_list( $value ) ) {
		return array_map( $canonicalize, $value ); }
	ksort( $value, SORT_STRING );
	foreach ( $value as $key => $entry ) {
		$value[ $key ] = $canonicalize( $entry ); }
	return $value;
};
$canonical_source = wp_json_encode( $canonicalize( $literal_fact['source'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
delete_transient( 'ssi_external_metric_source_' . hash( 'sha256', (string) $canonical_source ) );
$literal_http = static function ( mixed $preempt, array $args, string $url ): mixed {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	if ( 'https://api.wordpress.org/plugins/info/1.2/' === strtok( $url, '?' ) && 'plugin_information' === ( $query['action'] ?? '' ) && 'block-visibility' === ( $query['slug'] ?? '' ) ) {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 503,
				'message' => 'Injected unavailable source.',
			),
			'cookies'  => array(),
		); }
	return $preempt;
};
add_filter( 'pre_http_request', $literal_http, 10, 3 );
try {
	$literal_content   = do_blocks(
		$native_markup( $literal_fact, 'p' ) . "\n" . $native_markup( $literal_fact, 'h2' ) . "\n" . $native_markup( $route_fact, 'p' ) . "\n" . $native_markup( $partial_route_fact, 'p' )
	);
	$literal_text      = $literal_fact['fallback']['text'];
	$paragraph_literal = do_blocks( $native_markup( $literal_fact, 'p' ) );
	$heading_literal   = do_blocks( $native_markup( $literal_fact, 'h2' ) );
} finally {
	remove_filter( 'pre_http_request', $literal_http, 10 );
}
$literal_recovery = $run_response( $literal_fact, array( 'block-visibility' => array( array( 'version' => '3.8.1' ) ) ) );
$assert( 'v3.8.1' === ( $literal_recovery['value'] ?? null ) && 'fresh' === ( $literal_recovery['receipt']['status'] ?? '' ), 'Literal-fallback outage test restores the producer-declared version source and last-good state.' );
$assert( str_contains( $paragraph_literal, esc_html( $literal_text ) ) && ! str_contains( $paragraph_literal, '<em>pending</em>' ), 'Generated companion outage preserves literal Paragraph fallback tags, quotes and entities as text: ' . $paragraph_literal );
$assert( str_contains( $heading_literal, esc_html( $literal_text ) ) && ! str_contains( $heading_literal, '<em>pending</em>' ), 'Generated companion outage preserves literal Heading fallback tags, quotes and entities as text: ' . $heading_literal );
$assert( str_contains( $literal_content, '321+' ) && str_contains( $literal_content, '50+' ), 'Generated companion renders stale and fresh numeric values alongside literal fallbacks: ' . $literal_content );

$github_rate_fact                     = $metric_facts['github-stars'] ?? array();
$github_rate_fact['id']               = 'github-rate-limit-recovery';
$github_rate_fact['source']['id']     = 'github.rate-limit-recovery';
$github_rate_fact['fallback']['text'] = 'captured-github-stars';
$github_rate_fact['fallback']['hash'] = hash( 'sha256', 'captured-github-stars' );
$github_seed                          = $run_response( $github_rate_fact, array( '' => array( array( 'stargazers_count' => 777 ) ) ) );
$github_rate_limited                  = $run_response( $github_rate_fact, array( '' => array( array( '_http_status' => 429 ) ) ) );
$github_recovered                     = $run_response( $github_rate_fact, array( '' => array( array( 'stargazers_count' => 888 ) ) ) );
$assert( '777' === ( $github_seed['value'] ?? null ) && 'fresh' === ( $github_seed['receipt']['status'] ?? '' ), 'Generic GitHub recipe records a fresh exact count before rate limiting.' );
$assert( '777' === ( $github_rate_limited['value'] ?? null ) && 'stale' === ( $github_rate_limited['receipt']['status'] ?? '' ) && ( $github_rate_limited['receipt']['fetched_at'] ?? null ) === ( $github_seed['receipt']['fetched_at'] ?? null ), 'GitHub 429 preserves the exact last-known-good count and original source timestamp.' );
$assert( '888' === ( $github_recovered['value'] ?? null ) && 'fresh' === ( $github_recovered['receipt']['status'] ?? '' ), 'GitHub recipe recovers after rate-limit backoff on the same generic lifecycle.' );

if ( ! empty( $failures ) ) {
	throw new RuntimeException( esc_html( "External metric generated-companion acceptance failed:\n- " . implode( "\n- ", $failures ) ) ); }

$lkg_fact                        = $installs_fact;
$lkg_fact['id']                  = 'review-lkg-outage';
$lkg_fact['source']['resources'] = array( array( 'slug' => 'review-lkg-outage' ) );
$lkg_fact['fallback']['text']    = 'captured-lkg';
$lkg_fact['fallback']['hash']    = hash( 'sha256', $lkg_fact['fallback']['text'] );
$lkg_seed                        = $run_response( $lkg_fact, array( 'review-lkg-outage' => array( array( 'active_installs' => 321 ) ) ) );
$lkg_outage                      = $run_response( $lkg_fact, array( 'review-lkg-outage' => array( array( '_http_status' => 503 ) ) ) );
$assert( '321+' === ( $lkg_seed['value'] ?? null ) && 'fresh' === ( $lkg_seed['receipt']['status'] ?? '' ), 'Valid provider response seeds a generated-companion last-known-good value.' );
$assert( '321+' === ( $lkg_outage['value'] ?? null ) && 'stale' === ( $lkg_outage['receipt']['status'] ?? '' ) && ( $lkg_outage['receipt']['fetched_at'] ?? null ) === $lkg_seed['receipt']['fetched_at'], 'HTTP outage retains the exact generated-companion last-known-good value and timestamp.' );

$partial_fact                        = $installs_fact;
$partial_fact['id']                  = 'review-partial-recovery';
$partial_fact['source']['resources'] = array( array( 'slug' => 'review-partial-a' ), array( 'slug' => 'review-partial-b' ) );
$partial_fact['fallback']['text']    = 'captured-total';
$partial_fact['fallback']['hash']    = hash( 'sha256', $partial_fact['fallback']['text'] );
$partial_result                      = $run_response(
	$partial_fact,
	array(
		'review-partial-a' => array( array( 'active_installs' => 10 ) ),
		'review-partial-b' => array( array( 'error' => 'temporarily unavailable' ) ),
	)
);
$recovered_result                    = $run_response(
	$partial_fact,
	array(
		'review-partial-a' => array( array( 'active_installs' => 20 ) ),
		'review-partial-b' => array( array( 'active_installs' => 30 ) ),
	)
);
$assert( 'captured-total' === ( $partial_result['value'] ?? null ) && 'captured_fallback' === ( $partial_result['receipt']['status'] ?? '' ), 'Partial multi-plugin response never exposes a partial aggregate.' );
$assert( '50+' === ( $recovered_result['value'] ?? null ) && 'fresh' === ( $recovered_result['receipt']['status'] ?? '' ), 'Complete provider recovery replaces the fallback with the full aggregate.' );

echo wp_json_encode(
	array(
		'status'            => 'provider_response_contract_passed',
		'core'              => get_bloginfo( 'version' ),
		'results'           => $results,
		'lkg'               => $lkg_outage,
		'github_rate_limit' => array(
			'seed'         => $github_seed,
			'rate_limited' => $github_rate_limited,
			'recovered'    => $github_recovered,
		),
		'partial'           => $partial_result,
		'recovered'         => $recovered_result,
		'refresh_routes'    => array(
			'fresh'      => $route_fresh,
			'stale'      => $route_stale,
			'unresolved' => $route_unresolved,
			'invalid'    => $invalid_route,
			'partial'    => $partial_route,
			'recovered'  => $recovered_route,
		),
		'literal_render'    => $literal_content,
	)
) . "\n";
