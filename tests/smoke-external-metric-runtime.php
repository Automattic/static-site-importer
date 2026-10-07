<?php
/** Regression and bounded-provider tests for portable external metrics. */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_metric_options']    = array();
$GLOBALS['ssi_metric_transients'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['ssi_metric_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['ssi_metric_options'][ $name ] = $value;
	return true; }
function get_transient( $name ) {
	return $GLOBALS['ssi_metric_transients'][ $name ] ?? false; }
function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['ssi_metric_transients'][ $name ] = $value;
	$GLOBALS['ssi_metric_ttl'][ $name ]        = $expiration;
	return true; }
function delete_transient( $name ) {
	unset( $GLOBALS['ssi_metric_transients'][ $name ] );
	return true; }
function add_action( $hook, $callback ) {
	return true; }
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args ); }
function wp_parse_url( $url ) {
	return parse_url( $url ); }
function wp_http_validate_url( $url ) {
	return true; }
function is_wp_error( $value ) {
	return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? ''; }
function wp_remote_retrieve_header( $response, $header ) {
	return $response['headers'][ strtolower( $header ) ] ?? 'application/json'; }
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags ); }
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( $number, $decimals ); }
function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
class WP_Error {
	public function __construct( public string $code = 'failure' ) {}
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-external-metric-runtime.php';

$assertions = 0;
$assert     = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	} };
$fact       = static function ( string $id, string $source, string $metric, string $aggregation, array $slugs, string $fallback, array $format ): array {
	$markup = '<!-- wp:paragraph --><p>' . esc_html( $fallback ) . '</p><!-- /wp:paragraph -->';
	$download = 'plugin_download_history' === $source;
	$fields   = array(
		'active_installs'       => array( '/active_installs', 'nonnegative_integer' ),
		'downloads_all_time'    => array( '/all_time', 'nonnegative_integer' ),
		'num_ratings'           => array( '/num_ratings', 'nonnegative_integer' ),
		'plugin_response_count' => array( '/slug', 'string' ),
		'version'               => array( '/version', 'string' ),
	);
	return array(
		'id'          => $id,
		'source'      => array(
			'schema'     => 'generic/external-metric-source/v1',
			'id'         => $download ? 'wordpress.org.plugin-download-history' : 'wordpress.org.plugin-information',
			'intent'     => 'external_public_json',
			'request'    => array(
				'method'               => 'GET',
				'url_template'         => $download ? 'https://api.wordpress.org/stats/plugin/1.0/downloads.php' : 'https://api.wordpress.org/plugins/info/1.2/',
				'query'                => $download ? array( 'historical_summary' => 1 ) : array( 'action' => 'plugin_information' ),
				'query_variables'      => array( 'slug' ),
				'headers'              => array( 'Accept' => 'application/json' ),
				'response_media_type'  => 'application/json',
				'max_response_bytes'   => 1048576,
				'timeout_seconds'      => 5,
			),
			'resource_variables' => array(
				'slug' => array( 'location' => 'query', 'min_length' => 1, 'max_length' => 100, 'allowed_characters' => 'abcdefghijklmnopqrstuvwxyz0123456789-', 'first_characters' => 'abcdefghijklmnopqrstuvwxyz0123456789', 'prohibited_values' => array() ),
			),
			'resources'  => array_map( static fn( string $slug ): array => array( 'slug' => $slug ), $slugs ),
			'freshness'  => array( 'max_age_seconds' => 3600 ),
		),
		'metric'      => $metric,
		'extraction'  => array_merge( array( 'kind' => 'json_pointer', 'pointer' => ( $fields[ $metric ] ?? array( '/score', 'nonnegative_integer' ) )[0], 'value_type' => ( $fields[ $metric ] ?? array( '/score', 'nonnegative_integer' ) )[1] ), 'string' === ( $fields[ $metric ][1] ?? null ) ? array( 'max_length' => 255 ) : array() ),
		'aggregation' => $aggregation,
		'format'      => $format,
		'provenance'  => array(
			'kind'        => 'source_corroboration',
			'repository'  => 'ndiego/nickdiego.com',
			'revision'    => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc',
			'source_path' => 'src/components/wp-plugin-stat.tsx',
		),
		'fallback'    => array(
			'text' => $fallback,
			'hash' => hash( 'sha256', $fallback ),
		),
		'bindings'    => array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'role'                => 'paragraph',
				'source_path'         => 'projects.html',
				'search_block_markup' => $markup,
				'occurrence'          => 1,
				'leaf'                => array(
					'block'     => 'core/paragraph',
					'attribute' => 'content',
				),
			),
		),
	);
};
$fmt        = array(
	'locale'   => 'en-US',
	'grouping' => true,
	'prefix'   => '',
	'suffix'   => '+',
	'decimals' => 0,
);
$metrics    = array(
	$fact( 'installs-a', 'plugin_information', 'active_installs', 'sum', array( 'block-visibility', 'icon-block' ), '10,000+', $fmt ),
	$fact( 'downloads-a', 'plugin_download_history', 'downloads_all_time', 'sum', array( 'block-visibility' ), '20,000+', $fmt ),
	$fact(
		'ratings-a',
		'plugin_information',
		'num_ratings',
		'identity',
		array( 'block-visibility' ),
		'42',
		array(
			'locale'   => 'en-US',
			'grouping' => true,
			'prefix'   => '',
			'suffix'   => '',
			'decimals' => 0,
		)
	),
	$fact(
		'version-a',
		'plugin_information',
		'version',
		'identity',
		array( 'block-visibility' ),
		'v1.2.3',
		array(
			'locale'   => 'en-US',
			'grouping' => false,
			'prefix'   => 'v',
			'suffix'   => '',
			'decimals' => 0,
		)
	),
	$fact(
		'count-a',
		'plugin_information',
		'plugin_response_count',
		'success_count',
		array( 'block-visibility', 'icon-block' ),
		'2',
		array(
			'locale'   => 'en-US',
			'grouping' => true,
			'prefix'   => '',
			'suffix'   => '',
			'decimals' => 0,
		)
	),
);
$validated  = Static_Site_Importer_External_Metric_Runtime::validate_manifest(
	array(
		'external_metrics' => $metrics,
		'source_path'      => 'projects.html',
	)
);
$assert( 5 === count( $validated['external_metrics'] ) && empty( $validated['errors'] ), 'accepts source-proven native text bindings and supported WordPress.org facts: ' . wp_json_encode( $validated['errors'] ) );
$bad                                            = $metrics;
$bad[0]['source']['request']['url_template'] = 'http://127.0.0.1/private';
$assert(
	! empty(
		Static_Site_Importer_External_Metric_Runtime::validate_manifest(
			array(
				'external_metrics' => $bad,
				'source_path'      => 'projects.html',
			)
		)['errors']
	),
	'rejects unsafe non-HTTPS endpoint declarations'
);
$bad                   = $metrics;
$bad[0]['aggregation'] = 'average';
$assert(
	! empty(
		Static_Site_Importer_External_Metric_Runtime::validate_manifest(
			array(
				'external_metrics' => $bad,
				'source_path'      => 'projects.html',
			)
		)['errors']
	),
	'rejects unsupported aggregation'
);
$bad                        = $metrics;
$bad[1]['fallback']['hash'] = str_repeat( '0', 64 );
$assert(
	! empty(
		Static_Site_Importer_External_Metric_Runtime::validate_manifest(
			array(
				'external_metrics' => $bad,
				'source_path'      => 'projects.html',
			)
		)['errors']
	),
	'rejects stale captured fallback hashes'
);
$routes         = array(
	'block-visibility' => array(
		'active_installs' => 1200,
		'num_ratings'     => 42,
		'version'         => '1.2.3',
		'slug'            => 'block-visibility',
	),
	'icon-block'       => array(
		'active_installs' => 2300,
		'num_ratings'     => 80,
		'version'         => '2.0',
		'slug'            => 'icon-block',
	),
);
$requests       = array();
$fetch          = static function ( string $url ) use ( &$requests, $routes ) {
	$requests[] = $url;
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	if ( str_contains( $url, '/stats/plugin/' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'all_time' => '3456',
					'today'    => '9',
				)
			),
		); }
	$slug = $query['slug'] ?? '';
	return isset( $routes[ $slug ] ) ? array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $routes[ $slug ] ),
	) : array(
		'response' => array( 'code' => 404 ),
		'body'     => '{}',
	);
};
$install_result = Static_Site_Importer_External_Metric_Runtime::value( 'installs-a', array_column( $metrics, null, 'id' ), $fetch );
$assert( '3,500+' === $install_result, 'sums active_installs and applies source grouping/suffix: ' . var_export( $install_result, true ) . ' receipt=' . wp_json_encode( get_option( 'static_site_importer_external_metric_receipts', array() )['installs-a'] ?? array() ) );
$assert( '3,456+' === Static_Site_Importer_External_Metric_Runtime::value( 'downloads-a', array_column( $metrics, null, 'id' ), $fetch ), 'sums integer historical all_time, not plugin-info downloaded' );
$assert( '42' === Static_Site_Importer_External_Metric_Runtime::value( 'ratings-a', array_column( $metrics, null, 'id' ), $fetch ), 'uses individual num_ratings' );
$assert( 'v1.2.3' === Static_Site_Importer_External_Metric_Runtime::value( 'version-a', array_column( $metrics, null, 'id' ), $fetch ), 'preserves version prefix' );
$before = count( $requests );
Static_Site_Importer_External_Metric_Runtime::value( 'count-a', array_column( $metrics, null, 'id' ), $fetch );
$count_calls = count( $requests ) - $before;
$assert( 0 === $count_calls, 'reuses successful provider responses across metrics within one request' );
$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
$assert( 'fresh' === ( $receipts['ratings-a']['status'] ?? '' ) && is_int( $receipts['ratings-a']['fetched_at'] ?? null ), 'records freshness status and a fetch timestamp' );
$outage       = static function ( string $url ) {
	return new WP_Error();
};
$active_fact  = $fact( 'lkg-a', 'plugin_information', 'active_installs', 'sum', array( 'lkg-plugin' ), 'Baseline+', $fmt );
$active_fetch = static fn( string $url ): array => array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'active_installs' => 321,
			'slug'            => 'lkg-plugin',
		)
	),
);
$assert( '321+' === Static_Site_Importer_External_Metric_Runtime::value( 'lkg-a', array( 'lkg-a' => $active_fact ), $active_fetch ), 'stores a complete last-known-good value' );
$assert( '321+' === Static_Site_Importer_External_Metric_Runtime::value( 'lkg-a', array( 'lkg-a' => $active_fact ), $outage, time(), true ), 'returns the last-known-good value after an outage' );
$assert( 'stale' === ( get_option( 'static_site_importer_external_metric_receipts', array() )['lkg-a']['status'] ?? '' ), 'labels last-known-good fallback stale with its prior fetch timestamp' );
$empty_fact = $fact( 'empty-a', 'plugin_information', 'active_installs', 'sum', array( 'empty-plugin' ), '', $fmt );
$assert( '' === Static_Site_Importer_External_Metric_Runtime::value( 'empty-a', array( 'empty-a' => $empty_fact ), $outage ), 'keeps an empty captured fallback empty during outage' );
$assert( 'unresolved' === ( get_option( 'static_site_importer_external_metric_receipts', array() )['empty-a']['status'] ?? '' ), 'distinguishes an empty fallback as unresolved evidence' );
$german_format = array(
	'locale'   => 'de-DE',
	'grouping' => true,
	'prefix'   => '',
	'suffix'   => '+',
	'decimals' => 0,
);
$german_fact   = $fact( 'german-a', 'plugin_information', 'active_installs', 'sum', array( 'de-plugin' ), '10.000+', $german_format );
$german_fetch  = static fn( string $url ): array => array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'active_installs' => 12345,
			'slug'            => 'de-plugin',
		)
	),
);
$assert( '12.345+' === Static_Site_Importer_External_Metric_Runtime::value( 'german-a', array( 'german-a' => $german_fact ), $german_fetch ), 'respects the declared locale grouping separators' );

$offline_result = Static_Site_Importer_External_Metric_Runtime::value( 'offline-a', array( 'offline-a' => $fact( 'offline-a', 'plugin_information', 'active_installs', 'sum', array( 'offline-plugin' ), '10,000+', $fmt ) ), $outage );
$offline_status = get_option( 'static_site_importer_external_metric_receipts', array() )['offline-a']['status'] ?? '';
$assert( '10,000+' === $offline_result && 'captured_fallback' === $offline_status, 'uses the exact captured fallback for provider outage' );
$partial_fact = $fact( 'partial-a', 'plugin_information', 'active_installs', 'sum', array( 'block-visibility', 'missing-plugin' ), '10,000+', $fmt );
$assert( '10,000+' === Static_Site_Importer_External_Metric_Runtime::value( 'partial-a', array( 'partial-a' => $partial_fact ), $fetch ), 'does not expose a partial aggregate when one source row fails' );
$invalid      = static fn( string $url ): array => array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"error":"invalid"}',
);
$invalid_fact = $fact(
	'invalid-a',
	'plugin_information',
	'active_installs',
	'sum',
	array( 'missing-plugin' ),
	'captured',
	array(
		'locale'   => 'en-US',
		'grouping' => true,
		'prefix'   => '',
		'suffix'   => '+',
		'decimals' => 0,
	)
);
$assert( 'captured' === Static_Site_Importer_External_Metric_Runtime::value( 'invalid-a', array( 'invalid-a' => $invalid_fact ), $invalid ), 'treats successful HTTP with invalid provider payload as failure, never zero' );

$legacy_fact             = $metrics[2];
$legacy_fact['provider'] = array( 'schema' => 'generic/external-metric-provider/v1', 'id' => 'wordpress.org' );
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => array( $legacy_fact ) ) )['errors'] ), 'rejects the retired provider-reader contract rather than preserving a compatibility path' );

$http200_error_fact = $fact( 'http200-error-json', 'plugin_information', 'plugin_response_count', 'success_count', array( 'block-visibility', 'missing-plugin' ), '2', array( 'locale' => 'en-US', 'grouping' => false, 'prefix' => '', 'suffix' => '', 'decimals' => 0 ) );
$http200_error_fetch = static function ( string $url ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	return array( 'response' => array( 'code' => 200 ), 'body' => 'missing-plugin' === ( $query['slug'] ?? '' ) ? '{"error":"not found"}' : '{"slug":"block-visibility"}' );
};
$http200_error_fact['fallback']['text'] = 'captured-count';
$http200_error_fact['fallback']['hash'] = hash( 'sha256', 'captured-count' );
$assert( 'captured-count' === Static_Site_Importer_External_Metric_Runtime::value( 'http200-error-json', array( 'http200-error-json' => $http200_error_fact ), $http200_error_fetch, null, true ), 'success_count requires its declarative typed response prerequisite and fails atomically on HTTP 200 error JSON' );
$partial_success_fact = $http200_error_fact;
$partial_success_fact['id'] = 'partial-success-count';
$partial_success_fact['fallback']['text'] = 'captured-successes';
$partial_success_fact['fallback']['hash'] = hash( 'sha256', 'captured-successes' );
$assert( 'captured-successes' === Static_Site_Importer_External_Metric_Runtime::value( 'partial-success-count', array( 'partial-success-count' => $partial_success_fact ), static fn( string $url ): array => array( 'response' => array( 'code' => 200 ), 'body' => '{"error":"provider error"}' ), null, true ), 'typed extraction failure prevents HTTP 200 error JSON from counting as a successful resource' );

$github_fact = $fact( 'github-stars', 'plugin_information', 'active_installs', 'identity', array(), '7', array( 'locale' => 'en-US', 'grouping' => false, 'prefix' => '', 'suffix' => '', 'decimals' => 0 ) );
$github_fact['source'] = array( 'schema' => 'generic/external-metric-source/v1', 'id' => 'github.repository-information', 'intent' => 'external_public_json', 'request' => array( 'method' => 'GET', 'url_template' => 'https://api.github.com/repos/{owner}/{repository}', 'query' => array(), 'query_variables' => array(), 'headers' => array( 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28' ), 'response_media_type' => 'application/json', 'max_response_bytes' => 1048576, 'timeout_seconds' => 5 ), 'resource_variables' => array( 'owner' => array( 'location' => 'path', 'min_length' => 1, 'max_length' => 39, 'allowed_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-', 'first_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 'last_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 'prohibited_values' => array() ), 'repository' => array( 'location' => 'path', 'min_length' => 1, 'max_length' => 100, 'allowed_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-', 'prohibited_values' => array( '.', '..' ) ) ), 'resources' => array( array( 'owner' => 'Automattic', 'repository' => '.github' ) ), 'freshness' => array( 'max_age_seconds' => 86400 ) );
$github_fact['metric'] = 'stargazers_count';
$github_fact['extraction'] = array( 'kind' => 'json_pointer', 'pointer' => '/stargazers_count', 'value_type' => 'nonnegative_integer' );
$github_requests = array();
$github_fetch = static function ( string $url ) use ( &$github_requests ): array {
	$github_requests[] = $url;
	return array( 'response' => array( 'code' => 200 ), 'body' => '{"stargazers_count":7,"forks_count":9}' );
};
$assert( empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => array( $github_fact ) ) )['errors'] ) && '7' === Static_Site_Importer_External_Metric_Runtime::value( 'github-stars', array( 'github-stars' => $github_fact ), $github_fetch, 100000, true ) && array( 'https://api.github.com/repos/Automattic/.github' ) === $github_requests, 'generic interpreter accepts and fetches GitHub source data, preserving exact count extraction' );
$forks_fact = $github_fact;
$forks_fact['id'] = 'github-forks';
$forks_fact['metric'] = 'forks_count';
$forks_fact['extraction']['pointer'] = '/forks_count';
$assert( '9' === Static_Site_Importer_External_Metric_Runtime::value( 'github-forks', array( 'github-forks' => $forks_fact ), $github_fetch, 100001 ) && 1 === count( $github_requests ), 'GitHub stars and forks share one canonical HTTP/JSON source-cache entry' );
$github_refetches = 0;
$github_refetch = static function ( string $url ) use ( &$github_refetches ): array {
	++$github_refetches;
	return array( 'response' => array( 'code' => 200 ), 'body' => '{"stargazers_count":17,"forks_count":18}' );
};
$github_facts = array( 'github-stars' => $github_fact, 'github-forks' => $forks_fact );
$assert( '17' === Static_Site_Importer_External_Metric_Runtime::value( 'github-stars', $github_facts, $github_refetch, 100002, true ) && '18' === Static_Site_Importer_External_Metric_Runtime::value( 'github-forks', $github_facts, $github_refetch, 100003 ) && 1 === $github_refetches, 'Visible source refresh invalidates per-metric aliases so sibling extraction follows the refreshed shared response.' );
$github_receipt  = get_option( 'static_site_importer_external_metric_receipts', array() )['github-stars'] ?? array();
$assert( 'github.repository-information' === ( $github_receipt['source_id'] ?? '' ) && in_array( 86400, $GLOBALS['ssi_metric_ttl'] ?? array(), true ), 'source receipt/cache identity and one-day freshness come from the recipe' );
$unsafe_resource = $github_fact;
$unsafe_resource['id'] = 'github-unsafe-path';
$unsafe_resource['source']['resources'][0]['repository'] = '..';
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => array( $unsafe_resource ) ) )['errors'] ), 'resource variable schema rejects dot-segment path selectors' );
$unsafe_header = $github_fact;
$unsafe_header['id'] = 'github-unsafe-header';
$unsafe_header['source']['request']['headers']['X-Api-Key'] = 'credential-value';
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => array( $unsafe_header ) ) )['errors'] ), 'declarative source transport refuses credential-bearing header data' );
$fractional_fact = $github_fact;
$fractional_fact['id'] = 'github-fractional';
$fractional_fact['source']['id'] = 'github.fractional-test';
$fractional_fact['fallback']['text'] = 'captured-stars';
$fractional_fact['fallback']['hash'] = hash( 'sha256', 'captured-stars' );
$fractional_fetch = static fn( string $url ): array => array( 'response' => array( 'code' => 200 ), 'body' => '{"stargazers_count":1.5}' );
$assert( 'captured-stars' === Static_Site_Importer_External_Metric_Runtime::value( 'github-fractional', array( 'github-fractional' => $fractional_fact ), $fractional_fetch, null, true ), 'generic integer extraction rejects fractional JSON values and preserves the literal fallback' );

$neutral_fact = $fact( 'neutral-score', 'plugin_information', 'score', 'identity', array(), '0', array( 'locale' => 'en-US', 'grouping' => false, 'prefix' => '', 'suffix' => '', 'decimals' => 0 ) );
$neutral_fact['source'] = array( 'schema' => 'generic/external-metric-source/v1', 'id' => 'neutral.example-records', 'intent' => 'external_public_json', 'request' => array( 'method' => 'GET', 'url_template' => 'https://jsonplaceholder.typicode.com/todos/{record}', 'query' => array(), 'query_variables' => array(), 'headers' => array( 'Accept' => 'application/json' ), 'response_media_type' => 'application/json', 'max_response_bytes' => 1048576, 'timeout_seconds' => 5 ), 'resource_variables' => array( 'record' => array( 'location' => 'path', 'min_length' => 1, 'max_length' => 3, 'allowed_characters' => '0123456789', 'prohibited_values' => array() ) ), 'resources' => array( array( 'record' => '1' ) ), 'freshness' => array( 'max_age_seconds' => 600 ) );
$neutral_fact['metric'] = 'user_id';
$neutral_fact['extraction'] = array( 'kind' => 'json_pointer', 'pointer' => '/userId', 'value_type' => 'nonnegative_integer' );
$neutral_fetch = static fn( string $url ): array => array( 'response' => array( 'code' => 200 ), 'body' => '{"userId":31,"id":1,"title":"neutral JSON proof","completed":false}' );
$assert( empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => array( $neutral_fact ) ) )['errors'] ) && '31' === Static_Site_Importer_External_Metric_Runtime::value( 'neutral-score', array( 'neutral-score' => $neutral_fact ), $neutral_fetch, 200000, true ), 'third neutral source recipe runs through the same interpreter without a core source branch' );

echo 'External metric runtime regression passed: ' . $assertions . " assertions\n";
