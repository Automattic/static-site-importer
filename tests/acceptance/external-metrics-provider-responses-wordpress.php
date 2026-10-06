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
	'version_markup'  => array( 'version' => '<img src=x onerror=alert(1)>' ),
);
$results       = array();
foreach ( $version_cases as $label => $payload ) {
	$fact                      = $version_fact;
	$fact['id']                = 'review-' . $label;
	$fact['provider']['slugs'] = array( 'review-' . str_replace( '_', '-', $label ) );
	$fact['fallback']['text']  = 'v-captured';
	$fact['fallback']['hash']  = hash( 'sha256', $fact['fallback']['text'] );
	$results[ $label ]         = $run_response( $fact, array( $fact['provider']['slugs'][0] => array( $payload ) ) );
}

$installs_fact = $metric_facts['active-installs'] ?? array();
$numeric_cases = array(
	'numeric_fraction'   => array( 'active_installs' => 12.5 ),
	'numeric_overflow'   => '{"active_installs":1e309}',
	'ratings_fraction'   => array( 'num_ratings' => 3.5 ),
	'downloads_overflow' => array( 'all_time' => '9223372036854775808' ),
);
foreach ( $numeric_cases as $label => $payload ) {
	$fact                      = str_starts_with( $label, 'ratings' ) ? ( $metric_facts['project-ratings'] ?? array() ) : ( str_starts_with( $label, 'downloads' ) ? ( $metric_facts['all-time-downloads'] ?? array() ) : $installs_fact );
	$fact['id']                = 'review-' . $label;
	$fact['provider']['slugs'] = array( 'review-' . str_replace( '_', '-', $label ) );
	$fact['fallback']['text']  = 'captured-count';
	$fact['fallback']['hash']  = hash( 'sha256', $fact['fallback']['text'] );
	$results[ $label ]         = $run_response( $fact, array( $fact['provider']['slugs'][0] => array( $payload ) ) );
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

$route_fact                                 = $installs_fact;
$route_fact['id']                           = 'review-route-last-good';
$route_fact['provider']['slugs']            = array( 'review-route-last-good' );
$route_fact['fallback']['text']             = 'route-captured';
$route_fact['fallback']['hash']             = hash( 'sha256', $route_fact['fallback']['text'] );
$route_unresolved_fact                      = $installs_fact;
$route_unresolved_fact['id']                = 'review-route-unresolved';
$route_unresolved_fact['provider']['slugs'] = array( 'review-route-unresolved' );
$route_unresolved_fact['fallback']['text']  = '';
$route_unresolved_fact['fallback']['hash']  = hash( 'sha256', '' );
$editor_facts                               = get_option( 'ssi_external_metric_editor_test_facts', array() );
$assert( is_array( $editor_facts ) && 4 === count( $editor_facts ), 'Generated companion carries controlled editor and literal-fallback facts.' );
call_user_func( array( $runtime_class, 'configure' ), array_merge( array_values( $metric_facts ), $editor_facts, array( $route_fact, $route_unresolved_fact ) ) );
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

$invalid_route_fact                      = $installs_fact;
$invalid_route_fact['id']                = 'review-route-invalid';
$invalid_route_fact['provider']['slugs'] = array( 'review-route-invalid' );
$invalid_route_fact['fallback']['text']  = 'route-invalid-fallback';
$invalid_route_fact['fallback']['hash']  = hash( 'sha256', $invalid_route_fact['fallback']['text'] );
call_user_func( array( $runtime_class, 'configure' ), array_merge( array_values( $metric_facts ), $editor_facts, array( $route_fact, $invalid_route_fact ) ) );
$invalid_route = $run_route_response( $invalid_route_fact, array( 'review-route-invalid' => array( array( 'active_installs' => 12.5 ) ) ) );
$assert( 'captured_fallback' === ( $invalid_route['data']['status'] ?? '' ) && 'route-invalid-fallback' === ( $invalid_route['data']['value'] ?? null ), 'Refresh route returns an invalid fractional-count response as the captured fallback: ' . wp_json_encode( $invalid_route ) );

$partial_route_fact                      = $installs_fact;
$partial_route_fact['id']                = 'review-route-partial';
$partial_route_fact['provider']['slugs'] = array( 'review-route-partial-a', 'review-route-partial-b' );
$partial_route_fact['fallback']['text']  = 'route-partial-fallback';
$partial_route_fact['fallback']['hash']  = hash( 'sha256', $partial_route_fact['fallback']['text'] );
call_user_func( array( $runtime_class, 'configure' ), array_merge( array_values( $metric_facts ), $editor_facts, array( $route_fact, $invalid_route_fact, $partial_route_fact ) ) );
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

$all_facts = array_merge( array_values( $metric_facts ), $editor_facts, array( $route_fact, $invalid_route_fact, $partial_route_fact ) );
call_user_func( array( $runtime_class, 'configure' ), $all_facts );
$native_markup     = static function ( array $fact, string $tag ): string {
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
$literal_content   = do_blocks(
	$native_markup( $editor_facts[2], 'p' ) . "\n" . $native_markup( $editor_facts[3], 'h2' ) . "\n" . $native_markup( $route_fact, 'p' ) . "\n" . $native_markup( $partial_route_fact, 'p' )
);
$literal_text      = $editor_facts[2]['fallback']['text'];
$paragraph_literal = do_blocks( $native_markup( $editor_facts[2], 'p' ) );
$heading_literal   = do_blocks( $native_markup( $editor_facts[3], 'h2' ) );
$assert( str_contains( $paragraph_literal, esc_html( $literal_text ) ) && ! str_contains( $paragraph_literal, '<em>pending</em>' ), 'Generated companion outage preserves literal Paragraph fallback tags, quotes and entities as text: ' . $paragraph_literal );
$assert( str_contains( $heading_literal, esc_html( $literal_text ) ) && ! str_contains( $heading_literal, '<em>pending</em>' ), 'Generated companion outage preserves literal Heading fallback tags, quotes and entities as text: ' . $heading_literal );
$assert( str_contains( $literal_content, '321+' ) && str_contains( $literal_content, '50+' ), 'Generated companion renders stale and fresh numeric values alongside literal fallbacks: ' . $literal_content );

if ( ! empty( $failures ) ) {
	throw new RuntimeException( esc_html( "External metric generated-companion acceptance failed:\n- " . implode( "\n- ", $failures ) ) ); }

$lkg_fact                      = $installs_fact;
$lkg_fact['id']                = 'review-lkg-outage';
$lkg_fact['provider']['slugs'] = array( 'review-lkg-outage' );
$lkg_fact['fallback']['text']  = 'captured-lkg';
$lkg_fact['fallback']['hash']  = hash( 'sha256', $lkg_fact['fallback']['text'] );
$lkg_seed                      = $run_response( $lkg_fact, array( 'review-lkg-outage' => array( array( 'active_installs' => 321 ) ) ) );
$lkg_outage                    = $run_response( $lkg_fact, array( 'review-lkg-outage' => array( array( '_http_status' => 503 ) ) ) );
$assert( '321+' === ( $lkg_seed['value'] ?? null ) && 'fresh' === ( $lkg_seed['receipt']['status'] ?? '' ), 'Valid provider response seeds a generated-companion last-known-good value.' );
$assert( '321+' === ( $lkg_outage['value'] ?? null ) && 'stale' === ( $lkg_outage['receipt']['status'] ?? '' ) && ( $lkg_outage['receipt']['fetched_at'] ?? null ) === $lkg_seed['receipt']['fetched_at'], 'HTTP outage retains the exact generated-companion last-known-good value and timestamp.' );

$partial_fact                      = $installs_fact;
$partial_fact['id']                = 'review-partial-recovery';
$partial_fact['provider']['slugs'] = array( 'review-partial-a', 'review-partial-b' );
$partial_fact['fallback']['text']  = 'captured-total';
$partial_fact['fallback']['hash']  = hash( 'sha256', $partial_fact['fallback']['text'] );
$partial_result                    = $run_response(
	$partial_fact,
	array(
		'review-partial-a' => array( array( 'active_installs' => 10 ) ),
		'review-partial-b' => array( array( 'error' => 'temporarily unavailable' ) ),
	)
);
$recovered_result                  = $run_response(
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
		'status'         => 'provider_response_contract_passed',
		'core'           => get_bloginfo( 'version' ),
		'results'        => $results,
		'lkg'            => $lkg_outage,
		'partial'        => $partial_result,
		'recovered'      => $recovered_result,
		'refresh_routes' => array(
			'fresh'      => $route_fresh,
			'stale'      => $route_stale,
			'unresolved' => $route_unresolved,
			'invalid'    => $invalid_route,
			'partial'    => $partial_route,
			'recovered'  => $recovered_route,
		),
		'literal_render' => $literal_content,
	)
) . "\n";
