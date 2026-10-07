<?php
/** Actual Redirection install, native rule reconciliation, retirement and compensation. */
if ( '1' !== getenv( 'SSI_REDIRECTION_DISPOSABLE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Use an explicitly disposable WordPress CLI runtime.' );
}
$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only assertion evidence.
};
$import  = static function ( array $request ): array {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file = wp_tempnam( 'ssi-redirection-request.json' );
	file_put_contents( $file, wp_json_encode( $request ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Operator-owned temporary request fixture.
	try {
		$output = WP_CLI::runcommand(
			'static-site-importer import --request=' . escapeshellarg( $file ) . ' --user=admin --keep-source',
			array(
				'return'     => true,
				'launch'     => true,
				'exit_error' => false,
			)
		);
		foreach ( array_reverse( explode( "\n", trim( $output ) ) ) as $line ) {
			$receipt = json_decode( $line, true );
			if ( is_array( $receipt ) && 'static-site-importer/import-cli-receipt/v1' === ( $receipt['schema'] ?? '' ) ) {
				wp_cache_flush();
				return $receipt['response'];
			}
		}
		throw new RuntimeException( 'Canonical CLI returned no receipt: ' . substr( $output, 0, 2000 ) );
	} finally {
		wp_delete_file( $file ); }
};
$request = array(
	'operation' => 'apply',
	'slug'      => 'redirection-acceptance',
	'activate'  => true,
	'overwrite' => true,
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<html><head><title>Routes</title></head><body><main><h1>Source route migration</h1><a href="/destination">Destination</a></main></body></html>',
			),
			array(
				'path'    => 'website/destination.html',
				'content' => '<html><head><title>Destination</title></head><body><main><h1>Native destination</h1><p>Preserved native document.</p></main></body></html>',
			),
		),
	),
);
$plain   = $import( $request );
$assert( true === ( $plain['success'] ?? false ), 'Ordinary import completes.' );
$assert( ! is_dir( WP_PLUGIN_DIR . '/redirection' ), 'Exact source routes without redirect intent install no redirect plugin.' );
$request['source']['files'][] = array(
	'path'    => 'website/_redirects',
	'content' => "/old /destination.html 301\n",
);
$result                       = $import( $request );
$assert( true === ( $result['success'] ?? false ), 'Typed captured aliases install and configure native Redirection: ' . wp_json_encode( $result ) );
// The parent proof request began before installation. Use the provider's own bootstrap.
if ( ! defined( 'REDIRECTION_VERSION' ) ) {
	require_once WP_PLUGIN_DIR . '/redirection/redirection.php'; }
if ( function_exists( 'red_start_rest' ) ) {
	red_start_rest(); }
$owned   = get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() );
$scope   = $owned['redirection-acceptance'] ?? array();
$rule_id = (int) ( $scope['rules']['/old']['id'] ?? 0 );
$assert( $rule_id > 0 && Static_Site_Importer_Redirection_Materializer::available(), 'A real native rule and ready provider database exist.' );
$again = $import( $request );
$assert( true === ( $again['success'] ?? false ) && get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() ) === $owned, 'Reimport retains exact native IDs and configuration.' );
$destination = get_page_by_path( 'destination', OBJECT, 'page' );
$assert( $destination instanceof WP_Post, 'Native destination page exists.' );
$receipt   = array(
	'theme'     => array( 'slug' => 'redirection-acceptance' ),
	'plan'      => array(
		'pages' => array(
			array(
				'source_path' => 'website/destination.html',
				'route'       => array( 'path' => '/destination' ),
			),
		),
	),
	'completed' => array( 'pages' => array( 'website/destination.html' => (int) $destination->ID ) ),
);
$temporary = Static_Site_Importer_Redirection_Materializer::materialize(
	array( 'redirects' => array() ),
	array(
		'materialized_receipt' => $receipt,
		'source_route_aliases' => array(
			array(
				'from' => 'old',
				'to'   => 'destination.html',
			),
			array(
				'from' => 'temporary',
				'to'   => 'destination.html',
			),
		),
	)
);
$assert( ! is_wp_error( $temporary ) && 'completed' === $temporary['status'], 'Native rule updates return mutation journals.' );
$noop_delete = static function ( mixed $response, WP_REST_Server $server, WP_REST_Request $native_request ): mixed {
	return '/redirection/v1/bulk/redirect/delete' === $native_request->get_route() ? new WP_REST_Response( array() ) : $response;
};
add_filter( 'rest_pre_dispatch', $noop_delete, 10, 3 );
try {
	$unproven_restore = Static_Site_Importer_Redirection_Materializer::rollback( $temporary );
} finally {
	remove_filter( 'rest_pre_dispatch', $noop_delete, 10 );
}
$assert( 'failed' === $unproven_restore['status'] && 'native_redirect_restore_unproven' === $unproven_restore['reason'], 'A successful API response without a native deletion never claims rollback success.' );
$rolled_back = Static_Site_Importer_Redirection_Materializer::rollback( $temporary );
$assert( 'rolled_back' === $rolled_back['status'] && get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() ) === $owned, 'Compensation removes temporary rules and restores ownership.' );
$retired = Static_Site_Importer_Redirection_Materializer::materialize(
	array( 'redirects' => array() ),
	array(
		'materialized_receipt' => $receipt,
		'source_route_aliases' => array(),
	)
);
$assert( ! is_wp_error( $retired ) && 'completed' === $retired['status'], 'Removed source aliases retire their owned native rules: ' . ( is_wp_error( $retired ) ? $retired->get_error_message() : wp_json_encode( $retired ) ) );
$retirement_rollback = Static_Site_Importer_Redirection_Materializer::rollback( $retired );
$assert( 'rolled_back' === $retirement_rollback['status'] && get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() ) === $owned, 'Retirement compensation preserves original native rule IDs.' );
$fail_readback = static function ( mixed $response, WP_REST_Server $server, WP_REST_Request $native_request ): mixed {
	$filter = $native_request->get_param( 'filterBy' );
	return 'GET' === $native_request->get_method() && '/redirection/v1/redirect' === $native_request->get_route() && '/readback-failure' === ( $filter['url'] ?? '' ) ? new WP_Error( 'acceptance_readback_failure', 'Injected native readback failure.', array( 'status' => 503 ) ) : $response;
};
add_filter( 'rest_pre_dispatch', $fail_readback, 10, 3 );
try {
	$failed_readback = Static_Site_Importer_Redirection_Materializer::materialize(
		array( 'redirects' => array() ),
		array(
			'materialized_receipt' => $receipt,
			'source_route_aliases' => array(
				array(
					'from' => 'old',
					'to'   => 'destination.html',
				),
				array(
					'from' => 'readback-failure',
					'to'   => 'destination.html',
				),
			),
		)
	);
} finally {
	remove_filter( 'rest_pre_dispatch', $fail_readback, 10 );
}
$assert( ! is_wp_error( $failed_readback ) && 'failed' === $failed_readback['status'] && ! empty( $failed_readback['rollback']['rules'] ), 'A readback failure retains the already committed native rule journal.' );
$readback_rollback = Static_Site_Importer_Redirection_Materializer::rollback( $failed_readback );
$assert( 'rolled_back' === $readback_rollback['status'] && get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() ) === $owned, 'Readback failure compensation restores native state and ownership.' );
$deny_ownership_write = static fn( mixed $next, mixed $old ): mixed => $old;
add_filter( 'pre_update_option_' . Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, $deny_ownership_write, 10, 2 );
try {
	$failed_write = Static_Site_Importer_Redirection_Materializer::materialize(
		array( 'redirects' => array() ),
		array(
			'materialized_receipt' => $receipt,
			'source_route_aliases' => array(
				array(
					'from' => 'old',
					'to'   => 'destination.html',
				),
				array(
					'from' => 'write-failure',
					'to'   => 'destination.html',
				),
			),
		)
	);
} finally {
	remove_filter( 'pre_update_option_' . Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, $deny_ownership_write, 10 );
}
$assert( ! is_wp_error( $failed_write ) && 'failed' === $failed_write['status'] && ! empty( $failed_write['mutations'] ), 'A denied ownership write retains its actual native mutation journal.' );
$failure_receipt = array( 'status' => 'partial' );
Static_Site_Importer_Entity_Compensation::append(
	$failure_receipt,
	array(
		'entities' => array(
			'native-rule-fault' => array(
				'adapter'  => Static_Site_Importer_Redirection_Materializer::adapter(),
				'manifest' => array( 'redirects' => array() ),
			),
		),
	),
	array( 'native-rule-fault' => $failed_write ),
	'after_pages',
	'ownership_write_failed'
);
$assert( 'rolled_back' === ( $failure_receipt['entity_compensation']['status'] ?? '' ) && get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() ) === $owned, 'Generic failed-provider compensation restores native rules and exact ownership.' );
$owner_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Owner destination',
		'post_name'    => 'owner-destination',
		'post_content' => '<!-- wp:paragraph --><p>Owner-managed route.</p><!-- /wp:paragraph -->',
	),
	true
);
$assert( ! is_wp_error( $owner_page ), 'Owner destination exists.' );
$edited = Static_Site_Importer_Redirection_Materializer::api( 'POST', 'redirect/' . $rule_id, array_replace( $scope['rules']['/old']['config'], array( 'action_data' => array( 'url' => get_permalink( $owner_page ) ) ) ) );
$assert( ! is_wp_error( $edited ), 'The owner can edit the native rule using the same provider API as its admin UI.' );
$before_content                                       = get_post_field( 'post_content', $destination->ID );
$conflicting_request                                  = $request;
$conflicting_request['source']['files'][1]['content'] = '<html><body><main><h1>Unaccepted late-stage content</h1></main></body></html>';
$conflict = $import( $conflicting_request );
$assert( false === ( $conflict['success'] ?? true ), 'Reimport protects an owner-edited native rule.' );
$assert( get_post_field( 'post_content', $destination->ID ) === $before_content, 'A late native provider failure restores preexisting page content.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/old' ), 'SSI cannot override native provider ownership with a stale fallback redirect.' );
if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';
}
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-theme-exporter.php';
$unsupported_config                                       = $edited['item'];
$unsupported_config['match_data']['source']['flag_query'] = 'ignore';
$unsupported_edit = Static_Site_Importer_Redirection_Materializer::api( 'POST', 'redirect/' . $rule_id, $unsupported_config );
$assert( ! is_wp_error( $unsupported_edit ), 'Native owner query behavior can change.' );
$unsupported_export = Static_Site_Importer_Theme_Exporter::export_theme( array( 'theme_slug' => 'redirection-acceptance' ) );
$assert( is_wp_error( $unsupported_export ) && 'static_site_importer_redirect_export_unproven' === $unsupported_export->get_error_code(), 'Export explicitly rejects native matcher changes that exact-path aliases cannot preserve.' );
$restored_edit = Static_Site_Importer_Redirection_Materializer::api( 'POST', 'redirect/' . $rule_id, $edited['item'] );
$assert( ! is_wp_error( $restored_edit ), 'Owner query behavior is restored before portable export.' );
$exported = Static_Site_Importer_Theme_Exporter::export_theme( array( 'theme_slug' => 'redirection-acceptance' ) );
$assert( ! is_wp_error( $exported ), 'The full current site exports native rules and their actual target documents: ' . ( is_wp_error( $exported ) ? $exported->get_error_message() : '' ) );
$artifact = $exported['website_artifact'];
$assert( 1 === ( $artifact['report']['redirect_count'] ?? 0 ) && 'redirects' === ( $artifact['metadata']['runtime_declarations'][0]['capability'] ?? '' ), 'Export retains native rule inventory and provider dependency intent.' );
$evidence = dirname( __DIR__, 2 ) . '/artifacts/redirection';
file_put_contents( $evidence . '/export.json', wp_json_encode( $artifact ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable proof artifact.
$transport_files = array_map(
	static function ( array $file ): array {
		if ( 'base64' === ( $file['encoding'] ?? '' ) ) {
			$file['content_base64'] = $file['content'];
			unset( $file['content'] );
		}
		return $file;
	},
	$artifact['files']
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable second-site request artifact.
file_put_contents(
	$evidence . '/reimport-request.json',
	wp_json_encode(
		array(
			'operation' => 'apply',
			'slug'      => 'redirection-roundtrip',
			'activate'  => true,
			'source'    => array(
				'type'       => 'files',
				'entrypoint' => $artifact['entrypoint'],
				'files'      => $transport_files,
				'metadata'   => $artifact['metadata'],
			),
		)
	)
);
$event_request = array(
	'operation' => 'apply',
	'slug'      => 'native-event-redirect',
	'activate'  => false,
	'overwrite' => true,
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			$request['source']['files'][0],
			array(
				'path'     => 'website/event-detail.html',
				'content'  => '<html><head><title>Native route gathering</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"Event","name":"Native route gathering","startDate":"2027-04-03T18:00:00Z","endDate":"2027-04-03T20:00:00Z"}</script></head><body><main><h1>Native route gathering</h1><p>Committed provider-owned event content.</p></main></body></html>',
				'metadata' => array( 'route_path' => '/event-detail' ),
			),
		),
	),
);
$native_event  = $import( $event_request );
$assert( true === ( $native_event['success'] ?? false ), 'A genuine producer-declared event materializes and transfers its native route: ' . wp_json_encode( $native_event ) );
$event_owned = get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION, array() );
$assert( ! empty( $event_owned['native-event-redirect']['rules']['/event-detail']['id'] ), 'A provider-owned CPT source route has a real native redirect rule.' );
echo wp_json_encode(
	array(
		'status'           => 'passed',
		'wordpress'        => get_bloginfo( 'version' ),
		'provider_version' => REDIRECTION_VERSION,
		'rule_id'          => $rule_id,
		'reimport'         => 'idempotent',
		'rollback'         => 'restored',
		'retirement'       => 'reversible',
		'owner_route'      => get_permalink( $owner_page ),
	)
) . "\n";
