<?php
/** Disposable WordPress integration for the producer-owned external-metric contract. */

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric acceptance must run inside its disposable WordPress site.' );
}

require_once WP_CONTENT_DIR . '/plugins/static-site-importer/vendor/autoload.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/static-site-importer.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/includes/class-static-site-importer-theme-generator.php';
require_once __DIR__ . '/blocks-engine-source-autoloader.php';
$producer_source = $GLOBALS['ssi_external_metric_producer_source'];

$assert    = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( esc_html( $message ) ); }
};
$producer_runtime = new ReflectionClass( Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations::class );
$assert( false !== $producer_source && str_starts_with( (string) $producer_runtime->getFileName(), $producer_source . '/src/' ), 'Prototype paired compiler comes from the explicitly supplied committed producer checkout.' );
$fallbacks = array(
	'project-count'      => 'Captured successful project count',
	'active-installs'    => 'Captured install total',
	'all-time-downloads' => 'Captured download total',
	'project-version'    => 'v0.0.0',
	'project-ratings'    => 'Captured rating count',
	'github-stars'       => '7',
	'github-forks'       => '9',
	'neutral-score'      => '1',
);
$html      = '<!doctype html><html><head><title>Projects</title></head><body><main><h1>Projects</h1>';
foreach ( $fallbacks as $fallback ) {
	$html .= '<p>' . esc_html( $fallback ) . '</p>'; }
$html    .= '</main></body></html>';
$artifact = array(
	'entrypoint' => 'projects.html',
	'files'      => array(
		array(
			'path'    => 'projects.html',
			'content' => $html,
		),
	),
);
$args     = array(
	'slug'      => 'ssi-external-metrics-acceptance',
	'name'      => 'External Metrics Acceptance',
	'activate'  => true,
	'overwrite' => true,
);
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
$assert( ! is_wp_error( $compiled ), 'Base source fixture compiles before declaration anchors are attached.' );
$metric_page = null;
foreach ( $compiled['plan']['pages'] ?? array() as $candidate ) {
	$markup   = (string) ( $candidate['canonical_block_markup'] ?? '' );
	$blocks   = parse_blocks( $markup );
	$contents = array();
	$walk     = static function ( array $nodes ) use ( &$walk, &$contents ): void {
		foreach ( $nodes as $node ) {
			$text = trim( wp_strip_all_tags( (string) ( $node['innerHTML'] ?? '' ) ) );
			if ( 'core/paragraph' === ( $node['blockName'] ?? '' ) && '' !== $text ) {
				$contents[ $text ] = serialize_block( $node ); }
			if ( ! empty( $node['innerBlocks'] ) ) {
				$walk( $node['innerBlocks'] ); }
		}
	};
	$walk( $blocks );
	$matched = count( array_filter( $fallbacks, static fn( $text ): bool => isset( $contents[ $text ] ) ) );
	if ( count( $fallbacks ) === $matched ) {
		$metric_page = array(
			'source_path' => (string) $candidate['source_path'],
			'contents'    => $contents,
		);
		break; }
}
$assert( is_array( $metric_page ), 'Compiler emits all five captured metric text leaves as native paragraphs. Plan pages: ' . wp_json_encode( $compiled['plan']['pages'] ?? array() ) );

$slugs                  = array( 'block-visibility', 'icon-block', 'social-sharing-block', 'genesis-featured-page-advanced', 'genesis-columns-advanced' );
$source_provenance      = static function ( string $file ): array {
	return array(
		'kind'        => 'source_corroboration',
		'repository'  => 'ndiego/nickdiego.com',
		'revision'    => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc',
		'source_path' => $file,
	);
};
$make_fact              = static function ( string $id, string $source, string $metric, string $aggregation, array $plugin_slugs, string $text, array $format, string $source_file ) use ( $metric_page ): array {
	$download   = 'plugin_download_history' === $source;
	$extractions = array(
		'active_installs'       => array( '/active_installs', 'nonnegative_integer' ),
		'downloads_all_time'    => array( '/all_time', 'nonnegative_integer' ),
		'num_ratings'           => array( '/num_ratings', 'nonnegative_integer' ),
		'plugin_response_count' => array( '/slug', 'string' ),
		'version'               => array( '/version', 'string' ),
	);
	return array(
		'id'          => $id,
		'source'      => array(
			'schema'    => 'generic/external-metric-source/v1',
			'id'        => $download ? 'wordpress.org.plugin-download-history' : 'wordpress.org.plugin-information',
			'intent'    => 'external_public_json',
			'request'   => array(
				'method'              => 'GET',
				'url_template'        => $download ? 'https://api.wordpress.org/stats/plugin/1.0/downloads.php' : 'https://api.wordpress.org/plugins/info/1.2/',
				'query'               => $download ? array( 'historical_summary' => 1 ) : array( 'action' => 'plugin_information' ),
				'query_variables'     => array( 'slug' ),
				'headers'             => array( 'Accept' => 'application/json' ),
				'response_media_type' => 'application/json',
				'max_response_bytes'  => 1048576,
				'timeout_seconds'     => 5,
			),
			'resource_variables' => array(
				'slug' => array(
					'location'           => 'query',
					'min_length'         => 1,
					'max_length'         => 100,
					'allowed_characters' => 'abcdefghijklmnopqrstuvwxyz0123456789-',
					'first_characters'   => 'abcdefghijklmnopqrstuvwxyz0123456789',
					'prohibited_values'  => array(),
				),
			),
			'resources' => array_map( static fn( string $slug ): array => array( 'slug' => $slug ), $plugin_slugs ),
			'freshness' => array( 'max_age_seconds' => 3600 ),
		),
		'metric'      => $metric,
		'extraction'  => array_merge(
			array(
				'kind'       => 'json_pointer',
				'pointer'    => $extractions[ $metric ][0],
				'value_type' => $extractions[ $metric ][1],
			),
			'string' === $extractions[ $metric ][1] ? array( 'max_length' => 255 ) : array()
		),
		'aggregation' => $aggregation,
		'format'      => $format,
		'provenance'  => array(
			'kind'        => 'source_corroboration',
			'repository'  => 'ndiego/nickdiego.com',
			'revision'    => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc',
			'source_path' => $source_file,
		),
		'fallback'    => array(
			'text' => $text,
			'hash' => hash( 'sha256', $text ),
		),
		'bindings'    => array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'role'                => 'paragraph',
				'source_path'         => $metric_page['source_path'],
				'search_block_markup' => $metric_page['contents'][ $text ],
				'occurrence'          => 1,
				'leaf'                => array(
					'block'     => 'core/paragraph',
					'attribute' => 'content',
				),
			),
		),
	);
};
$numeric                = array(
	'locale'   => 'en-US',
	'grouping' => true,
	'prefix'   => '',
	'suffix'   => '',
	'decimals' => 0,
);
$facts                  = array(
	$make_fact( 'active-installs', 'plugin_information', 'active_installs', 'sum', $slugs, $fallbacks['active-installs'], array_merge( $numeric, array( 'suffix' => '+' ) ), 'src/components/wp-plugin-stat.tsx' ),
	$make_fact( 'all-time-downloads', 'plugin_download_history', 'downloads_all_time', 'sum', $slugs, $fallbacks['all-time-downloads'], array_merge( $numeric, array( 'suffix' => '+' ) ), 'src/components/wp-plugin-stat.tsx' ),
	$make_fact(
		'project-version',
		'plugin_information',
		'version',
		'identity',
		array( $slugs[0] ),
		$fallbacks['project-version'],
		array_merge(
			$numeric,
			array(
				'grouping' => false,
				'prefix'   => 'v',
			)
		),
		'src/components/wp-plugin-card.tsx'
	),
	$make_fact( 'project-ratings', 'plugin_information', 'num_ratings', 'identity', array( $slugs[0] ), $fallbacks['project-ratings'], $numeric, 'src/components/wp-plugin-card.tsx' ),
);
$expected_live_contract = array(
	'active-installs'    => array(
		'source_id'   => 'wordpress.org.plugin-information',
		'pointer'     => '/active_installs',
		'value_type'  => 'nonnegative_integer',
		'metric'      => 'active_installs',
		'aggregation' => 'sum',
		'resources'   => array_map( static fn( string $slug ): array => array( 'slug' => $slug ), $slugs ),
		'freshness'   => 3600,
		'format'      => array_merge( $numeric, array( 'suffix' => '+' ) ),
	),
	'all-time-downloads' => array(
		'source_id'   => 'wordpress.org.plugin-download-history',
		'pointer'     => '/all_time',
		'value_type'  => 'nonnegative_integer',
		'metric'      => 'downloads_all_time',
		'aggregation' => 'sum',
		'resources'   => array_map( static fn( string $slug ): array => array( 'slug' => $slug ), $slugs ),
		'freshness'   => 3600,
		'format'      => array_merge( $numeric, array( 'suffix' => '+' ) ),
	),
	'project-version'    => array(
		'source_id'   => 'wordpress.org.plugin-information',
		'pointer'     => '/version',
		'value_type'  => 'string',
		'metric'      => 'version',
		'aggregation' => 'identity',
		'resources'   => array( array( 'slug' => $slugs[0] ) ),
		'freshness'   => 3600,
		'format'      => array_merge(
			$numeric,
			array(
				'grouping' => false,
				'prefix'   => 'v',
			)
		),
	),
	'project-ratings'    => array(
		'source_id'   => 'wordpress.org.plugin-information',
		'pointer'     => '/num_ratings',
		'value_type'  => 'nonnegative_integer',
		'metric'      => 'num_ratings',
		'aggregation' => 'identity',
		'resources'   => array( array( 'slug' => $slugs[0] ) ),
		'freshness'   => 3600,
		'format'      => $numeric,
	),
);
$actual_live_contract   = array();
foreach ( $facts as $fact ) {
	$actual_live_contract[ $fact['id'] ] = array(
		'source_id'   => $fact['source']['id'],
		'pointer'     => $fact['extraction']['pointer'],
		'value_type'  => $fact['extraction']['value_type'],
		'metric'      => $fact['metric'],
		'aggregation' => $fact['aggregation'],
		'resources'   => $fact['source']['resources'],
		'freshness'   => $fact['source']['freshness']['max_age_seconds'],
		'format'      => $fact['format'],
	);
}
$assert( $expected_live_contract === $actual_live_contract, 'Bounded live-API evidence exactly matches independently expected source fields, aggregation and formatting: ' . wp_json_encode( $actual_live_contract ) );
$make_generic_fact = static function ( string $id, array $source, string $metric, string $pointer, string $type, string $fallback, array $provenance ) use ( $metric_page, $numeric ): array {
	return array(
		'id'          => $id,
		'source'      => $source,
		'metric'      => $metric,
		'extraction'  => array(
			'kind'       => 'json_pointer',
			'pointer'    => $pointer,
			'value_type' => $type,
		) + ( 'string' === $type ? array( 'max_length' => 255 ) : array() ),
		'aggregation' => 'identity',
		'format'      => $numeric,
		'provenance'  => $provenance,
		'fallback'    => array(
			'text' => $fallback,
			'hash' => hash( 'sha256', $fallback ),
		),
		'bindings'    => array(
			array(
				'schema'              => 'generic/block-binding/v1',
				'role'                => 'paragraph',
				'source_path'         => $metric_page['source_path'],
				'search_block_markup' => $metric_page['contents'][ $fallback ],
				'occurrence'          => 1,
				'leaf'                => array(
					'block'     => 'core/paragraph',
					'attribute' => 'content',
				),
			),
		),
	);
};
$github_source = array(
	'schema'             => 'generic/external-metric-source/v1',
	'id'                 => 'github.repository-information',
	'intent'             => 'external_public_json',
	'request'            => array(
		'method'              => 'GET',
		'url_template'        => 'https://api.github.com/repos/{owner}/{repository}',
		'query'               => array(),
		'query_variables'     => array(),
		'headers'             => array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		),
		'response_media_type' => 'application/json',
		'max_response_bytes'  => 1048576,
		'timeout_seconds'     => 5,
	),
	'resource_variables' => array(
		'owner' => array(
			'location'           => 'path',
			'min_length'         => 1,
			'max_length'         => 39,
			'allowed_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-',
			'first_characters'   => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
			'last_characters'    => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
			'prohibited_values'  => array(),
		),
		'repository' => array(
			'location'           => 'path',
			'min_length'         => 1,
			'max_length'         => 100,
			'allowed_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-',
			'prohibited_values'  => array( '.', '..' ),
		),
	),
	'resources'          => array(
		array(
			'owner'      => 'Automattic',
			'repository' => '.github',
		),
	),
	'freshness'          => array( 'max_age_seconds' => 86400 ),
);
$neutral_source = array(
	'schema'             => 'generic/external-metric-source/v1',
	'id'                 => 'neutral.example-records',
	'intent'             => 'external_public_json',
	'request'            => array(
		'method'              => 'GET',
		'url_template'        => 'https://jsonplaceholder.typicode.com/todos/{record}',
		'query'               => array(),
		'query_variables'     => array(),
		'headers'             => array( 'Accept' => 'application/json' ),
		'response_media_type' => 'application/json',
		'max_response_bytes'  => 1048576,
		'timeout_seconds'     => 5,
	),
	'resource_variables' => array(
		'record' => array(
			'location'           => 'path',
			'min_length'         => 1,
			'max_length'         => 3,
			'allowed_characters' => '0123456789',
			'prohibited_values'  => array(),
		),
	),
	'resources'          => array(
		array( 'record' => '1' ),
	),
	'freshness'          => array( 'max_age_seconds' => 600 ),
);
$facts[] = $make_generic_fact( 'github-stars', $github_source, 'stargazers_count', '/stargazers_count', 'nonnegative_integer', '7', $source_provenance( 'src/components/gh-repo-card.tsx' ) );
$facts[] = $make_generic_fact( 'github-forks', $github_source, 'forks_count', '/forks_count', 'nonnegative_integer', '9', $source_provenance( 'src/components/gh-repo-card.tsx' ) );
$facts[] = $make_generic_fact(
	'neutral-score',
	$neutral_source,
	'user_id',
	'/userId',
	'nonnegative_integer',
	'1',
	array(
		'kind'                => 'operator_mapping',
		'author'              => 'Chris Huber',
		'source_relationship' => 'Neutral public JSON record userId is rendered as the configured source statistic.',
	)
);
$direct_validation = Static_Site_Importer_External_Metric_Runtime::validate_manifest( array( 'external_metrics' => $facts ) );
$assert( empty( $direct_validation['errors'] ), 'Consumer validates producer facts: ' . wp_json_encode( $direct_validation['errors'] ?? array() ) );
$declaration                      = array(
	'kind'        => 'entity_collection',
	'type'        => 'external_metrics',
	'source_path' => $metric_page['source_path'],
	'payload'     => array(
		'schema'   => 'generic/external-metric/v1',
		'entities' => $facts,
	),
);
$artifact['runtime_declarations'] = Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations::normalizeList( array( $declaration ) );
$second_compile                   = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
$second_facts                     = $second_compile['plan']['runtime_declarations'][0]['payload']['entities'] ?? array();
$assert( ( $second_facts[0]['bindings'][0]['search_block_markup'] ?? '' ) === $facts[0]['bindings'][0]['search_block_markup'], 'Compiler-to-consumer handoff retains distinct projected metric anchors.' );
$compiled_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $second_compile['plan'], $second_compile['args'] );
$compiled_rows      = $compiled_lifecycle['entities'][ $second_compile['plan']['runtime_declarations'][0]['reconciliation_identity'] ]['manifest']['external_metrics'] ?? array();
$assert( ( $compiled_rows[0]['bindings'][0]['search_block_markup'] ?? '' ) === $facts[0]['bindings'][0]['search_block_markup'], 'Compiled lifecycle manifest preserves the owning projected text-leaf anchor.' );
$result = Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args );
if ( is_wp_error( $result ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Retains disposable acceptance diagnostics in a mounted evidence directory.
	file_put_contents(
		'/evidence/materialization-error.json',
		wp_json_encode(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			),
			JSON_PRETTY_PRINT
		)
	); }
$assert( ! is_wp_error( $result ), 'SSI materializes source-proven external metric declarations: ' . ( is_wp_error( $result ) ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' ) );
$receipt = is_array( $result ) ? ( $result['materialization_receipt'] ?? $result['receipt'] ?? array() ) : array();
$assert( 'completed' === ( $receipt['status'] ?? '' ), 'SSI transaction receipt completes with external metric bindings.' );
$binding_markup = implode( "\n", array_column( $receipt['completed']['runtime_declarations']['entity_bindings'] ?? array(), 'replacement_block_markup' ) );
$assert( str_contains( $binding_markup, 'ssi/external-metric' ), 'Persisted native paragraph leaves retain their core binding source.' );
$post_ids = array_values( array_filter( $receipt['completed']['pages'] ?? array(), static fn( $id ): bool => is_int( $id ) && $id > 0 ) );
$assert( 1 === count( $post_ids ), 'Materialization receipt identifies one persisted projects page.' );
update_option( 'ssi_external_metric_acceptance_post_id', $post_ids[0], false );
update_option( 'ssi_external_metric_acceptance_facts', $facts, false );
echo wp_json_encode(
	array(
		'status'          => 'materialized',
		'core'            => get_bloginfo( 'version' ),
		'post_id'         => $post_ids[0],
		'metrics'         => array_column( $facts, 'id' ),
		'source_contract' => $actual_live_contract,
		'companion'       => get_option( 'static_site_importer_active_companion_plugin', '' ),
	)
) . "\n";
