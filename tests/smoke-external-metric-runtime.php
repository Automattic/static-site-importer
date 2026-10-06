<?php
/** Regression and bounded-provider tests for portable external metrics. */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_metric_options'] = array();
$GLOBALS['ssi_metric_transients'] = array();
function get_option( $name, $default = false ) { return $GLOBALS['ssi_metric_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['ssi_metric_options'][ $name ] = $value; return true; }
function get_transient( $name ) { return $GLOBALS['ssi_metric_transients'][ $name ] ?? false; }
function set_transient( $name, $value, $expiration = 0 ) { $GLOBALS['ssi_metric_transients'][ $name ] = $value; $GLOBALS['ssi_metric_ttl'][ $name ] = $expiration; return true; }
function add_action( $hook, $callback ) { return true; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
class WP_Error { public function __construct( public string $code = 'failure' ) {} }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-external-metric-runtime.php';

$assertions = 0;
$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void { ++$assertions; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$fact = static function ( string $id, string $source, string $metric, string $aggregation, array $slugs, string $fallback, array $format ): array {
	$markup = '<!-- wp:paragraph --><p>' . esc_html( $fallback ) . '</p><!-- /wp:paragraph -->';
	return array(
		'id' => $id,
		'provider' => array( 'schema' => 'generic/external-metric-provider/v1', 'id' => 'wordpress.org', 'source' => $source, 'slugs' => $slugs ),
		'metric' => $metric,
		'aggregation' => $aggregation,
		'format' => $format,
		'provenance' => array( 'kind' => 'source_corroboration', 'repository' => 'ndiego/nickdiego.com', 'revision' => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc', 'source_path' => 'src/components/wp-plugin-stat.tsx' ),
		'fallback' => array( 'text' => $fallback, 'hash' => hash( 'sha256', $fallback ) ),
		'bindings' => array( array( 'schema' => 'generic/block-binding/v1', 'role' => 'paragraph', 'source_path' => 'projects.html', 'search_block_markup' => $markup, 'occurrence' => 1, 'leaf' => array( 'block' => 'core/paragraph', 'attribute' => 'content' ) ) ),
	);
};
$fmt = array( 'locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0 );
$metrics = array(
	$fact( 'installs-a', 'plugin_information', 'active_installs', 'sum', array( 'block-visibility', 'icon-block' ), '10,000+', $fmt ),
	$fact( 'downloads-a', 'plugin_download_history', 'downloads_all_time', 'sum', array( 'block-visibility' ), '20,000+', $fmt ),
	$fact( 'ratings-a', 'plugin_information', 'num_ratings', 'identity', array( 'block-visibility' ), '42', array( 'locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0 ) ),
	$fact( 'version-a', 'plugin_information', 'version', 'identity', array( 'block-visibility' ), 'v1.2.3', array( 'locale' => 'en-US', 'grouping' => false, 'prefix' => 'v', 'suffix' => '', 'decimals' => 0 ) ),
	$fact( 'count-a', 'plugin_information', 'plugin_response_count', 'success_count', array( 'block-visibility', 'icon-block' ), '2', array( 'locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0 ) ),
);
$validated = Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => $metrics, 'source_path' => 'projects.html' ) );
$assert( 5 === count( $validated['external_metrics'] ) && empty( $validated['errors'] ), 'accepts source-proven native text bindings and supported WordPress.org facts' );
$bad = $metrics; $bad[0]['provider']['source'] = 'https://attacker.invalid/';
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => $bad, 'source_path' => 'projects.html' ) )['errors'] ), 'rejects arbitrary endpoint declarations' );
$bad = $metrics; $bad[0]['aggregation'] = 'average';
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => $bad, 'source_path' => 'projects.html' ) )['errors'] ), 'rejects unsupported aggregation' );
$bad = $metrics; $bad[1]['fallback']['hash'] = str_repeat( '0', 64 );
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => $bad, 'source_path' => 'projects.html' ) )['errors'] ), 'rejects stale captured fallback hashes' );
$bad = $metrics; $bad[2]['metric'] = 'average_rating';
$assert( ! empty( Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => $bad, 'source_path' => 'projects.html' ) )['errors'] ), 'does not substitute average rating for num_ratings' );

$routes = array(
	'block-visibility' => array( 'active_installs' => 1200, 'num_ratings' => 42, 'version' => '1.2.3', 'slug' => 'block-visibility' ),
	'icon-block' => array( 'active_installs' => 2300, 'num_ratings' => 80, 'version' => '2.0', 'slug' => 'icon-block' ),
);
$requests = array();
$fetch = static function ( string $url ) use ( &$requests, $routes ) {
	$requests[] = $url;
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	if ( str_contains( $url, '/stats/plugin/' ) ) { return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'all_time' => '3456', 'today' => '9' ) ) ); }
	$slug = $query['slug'] ?? '';
	return isset( $routes[ $slug ] ) ? array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $routes[ $slug ] ) ) : array( 'response' => array( 'code' => 404 ), 'body' => '{}' );
};
$install_result = Static_Site_Importer_External_Metric_Runtime::value( 'installs-a', array_column( $metrics, null, 'id' ), $fetch );
$assert( '3,500+' === $install_result, 'sums active_installs and applies source grouping/suffix: ' . var_export( $install_result, true ) );
$assert( '3,456+' === Static_Site_Importer_External_Metric_Runtime::value( 'downloads-a', array_column( $metrics, null, 'id' ), $fetch ), 'sums integer historical all_time, not plugin-info downloaded' );
$assert( '42' === Static_Site_Importer_External_Metric_Runtime::value( 'ratings-a', array_column( $metrics, null, 'id' ), $fetch ), 'uses individual num_ratings' );
$assert( 'v1.2.3' === Static_Site_Importer_External_Metric_Runtime::value( 'version-a', array_column( $metrics, null, 'id' ), $fetch ), 'preserves version prefix' );
$before = count( $requests );
Static_Site_Importer_External_Metric_Runtime::value( 'count-a', array_column( $metrics, null, 'id' ), $fetch );
$count_calls = count( $requests ) - $before;
$assert( 0 === $count_calls, 'reuses successful provider responses across metrics within one request' );
$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
$assert( 'fresh' === ( $receipts['ratings-a']['status'] ?? '' ) && is_int( $receipts['ratings-a']['fetched_at'] ?? null ), 'records freshness status and a fetch timestamp' );
$outage = static function ( string $url ) { return new WP_Error(); };
$active_fact = $fact( 'lkg-a', 'plugin_information', 'active_installs', 'sum', array( 'lkg-plugin' ), 'Baseline+', $fmt );
$active_fetch = static fn( string $url ): array => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'active_installs' => 321, 'slug' => 'lkg-plugin' ) ) );
$assert( '321+' === Static_Site_Importer_External_Metric_Runtime::value( 'lkg-a', array( 'lkg-a' => $active_fact ), $active_fetch ), 'stores a complete last-known-good value' );
$assert( '321+' === Static_Site_Importer_External_Metric_Runtime::value( 'lkg-a', array( 'lkg-a' => $active_fact ), $outage, time(), true ), 'returns the last-known-good value after an outage' );
$assert( 'stale' === ( get_option( 'static_site_importer_external_metric_receipts', array() )['lkg-a']['status'] ?? '' ), 'labels last-known-good fallback stale with its prior fetch timestamp' );
$empty_fact = $fact( 'empty-a', 'plugin_information', 'active_installs', 'sum', array( 'empty-plugin' ), '', $fmt );
$assert( '' === Static_Site_Importer_External_Metric_Runtime::value( 'empty-a', array( 'empty-a' => $empty_fact ), $outage ), 'keeps an empty captured fallback empty during outage' );
$assert( 'unresolved' === ( get_option( 'static_site_importer_external_metric_receipts', array() )['empty-a']['status'] ?? '' ), 'distinguishes an empty fallback as unresolved evidence' );
$german_format = array( 'locale' => 'de-DE', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0 );
$german_fact = $fact( 'german-a', 'plugin_information', 'active_installs', 'sum', array( 'de-plugin' ), '10.000+', $german_format );
$german_fetch = static fn( string $url ): array => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'active_installs' => 12345, 'slug' => 'de-plugin' ) ) );
$assert( '12.345+' === Static_Site_Importer_External_Metric_Runtime::value( 'german-a', array( 'german-a' => $german_fact ), $german_fetch ), 'respects the declared locale grouping separators' );

$offline_result = Static_Site_Importer_External_Metric_Runtime::value( 'offline-a', array( 'offline-a' => $fact( 'offline-a', 'plugin_information', 'active_installs', 'sum', array( 'offline-plugin' ), '10,000+', $fmt ) ), $outage );
$offline_status = get_option( 'static_site_importer_external_metric_receipts', array() )['offline-a']['status'] ?? '';
$assert( '10,000+' === $offline_result && 'captured_fallback' === $offline_status, 'uses the exact captured fallback for provider outage' );
$partial_fact = $fact( 'partial-a', 'plugin_information', 'active_installs', 'sum', array( 'block-visibility', 'missing-plugin' ), '10,000+', $fmt );
$assert( '10,000+' === Static_Site_Importer_External_Metric_Runtime::value( 'partial-a', array( 'partial-a' => $partial_fact ), $fetch ), 'does not expose a partial aggregate when one source row fails' );
$invalid = static fn( string $url ): array => array( 'response' => array( 'code' => 200 ), 'body' => '{"error":"invalid"}' );
$invalid_fact = $fact( 'invalid-a', 'plugin_information', 'active_installs', 'sum', array( 'missing-plugin' ), 'captured', array( 'locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0 ) );
$assert( 'captured' === Static_Site_Importer_External_Metric_Runtime::value( 'invalid-a', array( 'invalid-a' => $invalid_fact ), $invalid ), 'treats successful HTTP with invalid provider payload as failure, never zero' );

echo 'External metric runtime regression passed: ' . $assertions . " assertions\n";
