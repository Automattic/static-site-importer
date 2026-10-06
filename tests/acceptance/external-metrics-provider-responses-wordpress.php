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

$run_response = static function ( array $fact, array $responses ) use ( $runtime_class ): array {
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

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) ); }
};
foreach ( array_merge( $version_cases, $numeric_cases ) as $label => $payload ) {
	$result            = $results[ $label ] ?? array();
	$fact              = str_starts_with( $label, 'version' ) ? $version_fact : ( str_starts_with( $label, 'ratings' ) ? ( $metric_facts['project-ratings'] ?? array() ) : ( str_starts_with( $label, 'downloads' ) ? ( $metric_facts['all-time-downloads'] ?? array() ) : $installs_fact ) );
	$expected_fallback = str_starts_with( $label, 'version' ) ? 'v-captured' : 'captured-count';
	$assert( ( $result['value'] ?? null ) === $expected_fallback, 'Malformed provider response returns exact captured fallback: ' . $label . '; observed ' . wp_json_encode( $result ) );
	$assert( 'captured_fallback' === ( $result['receipt']['status'] ?? '' ), 'Malformed provider response is not labeled fresh: ' . $label );
}

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
		'status'    => 'provider_response_contract_passed',
		'core'      => get_bloginfo( 'version' ),
		'results'   => $results,
		'lkg'       => $lkg_outage,
		'partial'   => $partial_result,
		'recovered' => $recovered_result,
	)
) . "\n";
