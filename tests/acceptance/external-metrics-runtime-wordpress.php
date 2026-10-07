<?php
/** Prove public fetch, native binding render and no post rewrite. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric verification must run in its disposable WordPress site.' ); }
$assert         = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} };
$metric_post_id = (int) get_option( 'ssi_external_metric_acceptance_post_id', 0 );
$facts          = get_option( 'ssi_external_metric_acceptance_facts', array() );
$assert( $metric_post_id > 0 && is_array( $facts ) && 8 === count( $facts ), 'A completed mixed-source eight-fact imported page is persisted.' );
$plugin_file  = (string) get_option( 'static_site_importer_active_companion_plugin', '' );
$runtime_file = WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/includes/external-metric-runtime.php';
$source       = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated local companion runtime source for the acceptance assertion.
preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $class_match );
$runtime_class = $class_match[1] ?? '';
$assert( '' !== $runtime_class && class_exists( $runtime_class ), 'Generated companion owns its independent generic runtime.' );
$fact_map            = array_column( $facts, null, 'id' );
$runtime_metrics     = array_column( $facts, null, 'id' );
$success_count_value = call_user_func( array( $runtime_class, 'value' ), 'project-count', $runtime_metrics, null, null, true );
$assert( '5' === $success_count_value && 'fresh' === ( get_option( 'static_site_importer_external_metric_receipts', array() )['project-count']['status'] ?? '' ), 'Producer-compiled WordPress.org success_count performs a typed /slug prerequisite over its five public resources.' );
$post_content = (string) get_post_field( 'post_content', $metric_post_id );
$before_hash  = hash( 'sha256', $post_content );
$rendered     = do_blocks( $post_content );
$assert( is_string( $rendered ) && '' !== $rendered, 'WordPress renders persisted native text blocks.' );
foreach ( $facts as $fact ) {
	$receipt_value = get_option( 'static_site_importer_external_metric_receipts', array() )[ $fact['id'] ]['value'] ?? null;
	$assert( is_string( $receipt_value ) && str_contains( $rendered, $receipt_value ), 'Fresh declarative-source value renders for ' . $fact['id'] );
	if ( $receipt_value !== (string) $fact['fallback']['text'] ) {
		$assert( ! str_contains( $rendered, (string) $fact['fallback']['text'] ), 'Fresh value replaces captured fallback for ' . $fact['id'] ); }
}
$receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
foreach ( $facts as $fact ) {
	$row = $receipts[ $fact['id'] ] ?? array();
	$assert( 'fresh' === ( $row['status'] ?? '' ) && is_int( $row['fetched_at'] ?? null ) && '' !== (string) ( $row['value'] ?? '' ) && ( $row['source_id'] ?? '' ) === ( $fact['source']['id'] ?? '' ), 'Timestamped fresh source receipt exists for ' . $fact['id'] ); }
$github_api  = wp_safe_remote_get(
	'https://api.github.com/repos/Automattic/.github',
	array(
		'timeout'     => 5,
		'redirection' => 0,
		'headers'     => array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		),
	)
);
$github_data = ! is_wp_error( $github_api ) && 200 === (int) wp_remote_retrieve_response_code( $github_api ) ? json_decode( wp_remote_retrieve_body( $github_api ), true ) : array();
$assert( is_array( $github_data ) && is_int( $github_data['stargazers_count'] ?? null ) && is_int( $github_data['forks_count'] ?? null ), 'Independent live GitHub API observation contains exact integer stargazers_count and forks_count fields: status=' . ( is_wp_error( $github_api ) ? $github_api->get_error_message() : wp_remote_retrieve_response_code( $github_api ) ) . ' body=' . wp_remote_retrieve_body( $github_api ) );
$assert( (string) ( $github_data['stargazers_count'] ?? '' ) === ( $receipts['github-stars']['value'] ?? null ) && (string) ( $github_data['forks_count'] ?? '' ) === ( $receipts['github-forks']['value'] ?? null ), 'Generated runtime receipts equal the independently queried GitHub API fields exactly.' );
$assert( ( $receipts['github-stars']['fetched_at'] ?? null ) === ( $receipts['github-forks']['fetched_at'] ?? null ), 'GitHub stars and forks reuse the same source response timestamp.' );
$neutral_api  = wp_safe_remote_get(
	'https://jsonplaceholder.typicode.com/todos/1',
	array(
		'timeout'     => 5,
		'redirection' => 0,
		'headers'     => array( 'Accept' => 'application/json' ),
	)
);
$neutral_data = ! is_wp_error( $neutral_api ) && 200 === (int) wp_remote_retrieve_response_code( $neutral_api ) ? json_decode( wp_remote_retrieve_body( $neutral_api ), true ) : array();
$assert( is_array( $neutral_data ) && is_int( $neutral_data['userId'] ?? null ) && is_string( $receipts['neutral-score']['value'] ?? null ) && hash_equals( (string) $neutral_data['userId'], $receipts['neutral-score']['value'] ), 'Third neutral public JSON source renders its independently observed userId field through the same companion interpreter.' );
$assert( hash( 'sha256', (string) get_post_field( 'post_content', $metric_post_id ) ) === $before_hash, 'Fetch and cache expiry refresh leave saved post content unchanged.' );

// A separate editor page proves that refresh returns a new value for the
// existing native binding controls, and that detach freezes that exact value.
$metric_map     = array_column( $facts, null, 'id' );
$editor_block   = static function ( string $name, string $metric_id, string $tag, string $text, array $metadata = array() ): string {
	$metadata['name'] = $metadata['name'] ?? $name;
	$attributes       = array(
		'metadata' => array_merge(
			$metadata,
			array(
				'bindings' => array(
					'content' => array(
						'source' => 'ssi/external-metric',
						'args'   => array( 'metric_id' => $metric_id ),
					),
				),
			)
		),
	);
	$block_name       = 'h2' === $tag ? 'heading' : 'paragraph';
	if ( 'heading' === $block_name ) {
		$attributes['level'] = 2;
	}
	return '<!-- wp:' . $block_name . ' ' . wp_json_encode( $attributes ) . ' --><' . $tag . '>' . esc_html( $text ) . '</' . $tag . '><!-- /wp:' . $block_name . ' -->';
};
$editor_content = implode(
	"\n\n",
	array(
		$editor_block(
			'editor-detach-target',
			'github-stars',
			'p',
			'111+',
			array(
				'name'   => 'Preserve target metadata',
				'custom' => 'preserve-me',
			)
		),
		$editor_block( 'editor-sibling-binding', 'github-forks', 'p', 'captured-editor-forks', array( 'name' => 'Preserve sibling binding' ) ),
		$editor_block( 'literal-fallback-paragraph', 'project-version', 'p', $metric_map['project-version']['fallback']['text'] ),
		$editor_block( 'literal-fallback-heading', 'project-version', 'h2', $metric_map['project-version']['fallback']['text'] ),
	)
);

// Exercise one shared source response across GitHub star and fork extraction.
call_user_func( array( $runtime_class, 'configure' ), array_values( $metric_map ) );
$alias_cache_key = 'ssi_external_metric_' . hash( 'sha256', (string) wp_json_encode( array( $metric_map['github-stars']['source'], $metric_map['github-stars']['metric'], $metric_map['github-stars']['extraction'], $metric_map['github-stars']['aggregation'], $metric_map['github-stars']['format'] ) ) );
delete_transient( $alias_cache_key );
$fork_cache_key = 'ssi_external_metric_' . hash( 'sha256', (string) wp_json_encode( array( $metric_map['github-forks']['source'], $metric_map['github-forks']['metric'], $metric_map['github-forks']['extraction'], $metric_map['github-forks']['aggregation'], $metric_map['github-forks']['format'] ) ) );
delete_transient( $fork_cache_key );
$source_cache_key = 'ssi_external_metric_source_' . hash( 'sha256', (string) wp_json_encode( $metric_map['github-stars']['source'] ) );
delete_transient( $source_cache_key );
$alias_receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
unset( $alias_receipts['github-stars'], $alias_receipts['github-forks'] );
update_option( 'static_site_importer_external_metric_receipts', $alias_receipts, false );
$alias_fetches = 0;
$alias_http    = static function ( mixed $preempt, array $args, string $url ) use ( &$alias_fetches ): mixed {
	if ( 'https://api.github.com/repos/Automattic/.github' !== $url ) {
		return $preempt; }
	++$alias_fetches;
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode(
			array(
				'stargazers_count' => 222,
				'forks_count'      => 333,
			)
		),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $alias_http, 10, 3 );
try {
	$alias_metrics = $metric_map;
	$alias_seed    = call_user_func( array( $runtime_class, 'value' ), 'github-stars', $alias_metrics, null, null, true );
	$alias_render  = do_blocks(
		$editor_block( 'alias-cache-first', 'github-stars', 'p', 'captured-editor-stars' ) . "\n" . $editor_block( 'alias-cache-second', 'github-forks', 'p', 'captured-editor-forks' )
	);
} finally {
	remove_filter( 'pre_http_request', $alias_http, 10 );
}
$assert( '222' === $alias_seed, 'Visible source refresh reads a fresh injected GitHub stars response before checking source cache reuse.' );
$alias_receipts = get_option( 'static_site_importer_external_metric_receipts', array() );
$first_receipt  = $alias_receipts['github-stars'] ?? array();
$second_receipt = $alias_receipts['github-forks'] ?? array();
$assert( 1 === $alias_fetches && str_contains( $alias_render, '>222</p>' ) && str_contains( $alias_render, '>333</p>' ), 'Generated companion shares one GitHub source request across distinct stars/forks JSON pointers: fetches=' . $alias_fetches . ' html=' . $alias_render );
$assert( 'fresh' === ( $first_receipt['status'] ?? '' ) && '222' === ( $first_receipt['value'] ?? '' ) && is_int( $first_receipt['fetched_at'] ?? null ), 'GitHub stars receives the canonical fresh source receipt.' );
$assert( 'fresh' === ( $second_receipt['status'] ?? '' ) && '333' === ( $second_receipt['value'] ?? '' ) && ( $second_receipt['fetched_at'] ?? null ) === ( $first_receipt['fetched_at'] ?? null ) && 'github.repository-information' === ( $second_receipt['source_id'] ?? '' ), 'GitHub forks reuses the same source receipt timestamp after extracting its own JSON pointer.' );
$admin_user     = get_user_by( 'login', 'admin' );
$editor_post_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'External Metric Editor Acceptance',
		'post_content' => $editor_content,
		'post_author'  => $admin_user instanceof WP_User ? (int) $admin_user->ID : 1,
	),
	true
);
$assert( ! is_wp_error( $editor_post_id ) && (int) $editor_post_id > 0, 'Controlled native binding editor page is persisted.' );

$mu_plugin_path = WPMU_PLUGIN_DIR . '/ssi-external-metric-editor-http-fixture.php';
wp_mkdir_p( WPMU_PLUGIN_DIR );
$mu_plugin_source = <<<'PHP'
<?php
/** Controlled external metric responses for disposable editor acceptance only. */
add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'api.wordpress.org' === $host ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			if ( 'plugin_information' === ( $query['action'] ?? '' ) && 'block-visibility' === ( $query['slug'] ?? '' ) ) {
				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array( 'code' => 503, 'message' => 'Injected unavailable source.' ),
					'cookies'  => array(),
				);
			}
		}
		if ( 'https://api.github.com/repos/Automattic/.github' !== $url ) {
			return $preempt;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'stargazers_count' => 222, 'forks_count' => 333 ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);
PHP;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Installs an HTTP fixture only in the disposable site's must-use plugin directory.
file_put_contents( $mu_plugin_path, $mu_plugin_source );
$version_fact = $metric_map['project-version'];
$version_key  = 'ssi_external_metric_' . (string) ( $receipts['project-version']['recipe_hash'] ?? '' );
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
$canonical_source = wp_json_encode( $canonicalize( $version_fact['source'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
delete_transient( 'ssi_external_metric_source_' . hash( 'sha256', (string) $canonical_source ) );
echo wp_json_encode(
	array(
		'status'         => 'verified',
		'core'           => get_bloginfo( 'version' ),
		'post_id'        => $metric_post_id,
		'editor_post_id' => (int) $editor_post_id,
		'companion'      => $plugin_file,
		'content_sha256' => $before_hash,
		'receipts'       => $receipts,
		'alias_cache'    => array(
			'provider_fetches'   => $alias_fetches,
			'github_value'       => $first_receipt['value'] ?? null,
			'github_forks_value' => $second_receipt['value'] ?? null,
			'rendered'           => $alias_render,
			'receipts'           => array(
				'github-stars' => $first_receipt,
				'github-forks' => $second_receipt,
			),
		),
	)
) . "\n";
