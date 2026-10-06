<?php
/** Disposable WordPress integration for the producer-owned external-metric contract. */

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_EXTERNAL_METRICS_DISPOSABLE' ) ) {
	throw new RuntimeException( 'External metric acceptance must run inside its disposable WordPress site.' );
}

require_once WP_CONTENT_DIR . '/plugins/static-site-importer/vendor/autoload.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/static-site-importer.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/includes/class-static-site-importer-theme-generator.php';

$assert    = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( esc_html( $message ) ); }
};
$fallbacks = array(
	'project-count'      => 'Captured successful project count',
	'active-installs'    => 'Captured install total',
	'all-time-downloads' => 'Captured download total',
	'project-version'    => 'v0.0.0',
	'project-ratings'    => 'Captured rating count',
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

$slugs             = array( 'block-visibility', 'icon-block', 'social-sharing-block', 'genesis-featured-page-advanced', 'genesis-columns-advanced' );
$source_provenance = static function ( string $file ): array {
	return array(
		'kind'        => 'source_corroboration',
		'repository'  => 'ndiego/nickdiego.com',
		'revision'    => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc',
		'source_path' => $file,
	);
};
$make_fact         = static function ( string $id, string $source, string $metric, string $aggregation, array $plugin_slugs, string $text, array $format, string $source_file ) use ( $metric_page ): array {
	return array(
		'id'          => $id,
		'provider'    => array(
			'schema' => 'generic/external-metric-provider/v1',
			'id'     => 'wordpress.org',
			'source' => $source,
			'slugs'  => $plugin_slugs,
		),
		'metric'      => $metric,
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
$numeric           = array(
	'locale'   => 'en-US',
	'grouping' => true,
	'prefix'   => '',
	'suffix'   => '',
	'decimals' => 0,
);
$facts             = array(
	$make_fact( 'project-count', 'plugin_information', 'plugin_response_count', 'success_count', $slugs, $fallbacks['project-count'], $numeric, 'src/components/wp-plugin-stat.tsx' ),
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
		'status'    => 'materialized',
		'core'      => get_bloginfo( 'version' ),
		'post_id'   => $post_ids[0],
		'metrics'   => array_column( $facts, 'id' ),
		'companion' => get_option( 'static_site_importer_active_companion_plugin', '' ),
	)
) . "\n";
