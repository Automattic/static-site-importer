<?php
/** Native alias ownership, editable draft hierarchy, protected collision and rollback. */
if ( '1' !== getenv( 'SSI_ALIAS_DISPOSABLE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Use an explicitly disposable WordPress CLI runtime.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Disposable CLI assertion evidence.
	}
};
$import = static function ( array $request ): array {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file = wp_tempnam( 'alias-hierarchy-request.json' );
	file_put_contents( $file, wp_json_encode( $request ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable request artifact.
	try {
		$output = WP_CLI::runcommand( 'static-site-importer import --user=' . get_current_user_id() . ' --request=' . escapeshellarg( $file ), array( 'return' => true, 'launch' => true, 'exit_error' => false ) );
		foreach ( array_reverse( explode( "\n", trim( $output ) ) ) as $line ) {
			$row = json_decode( $line, true );
			if ( 'static-site-importer/import-cli-receipt/v1' === ( $row['schema'] ?? '' ) ) {
				wp_cache_flush();
				return $row['response'];
			}
		}
		throw new RuntimeException( 'CLI returned no receipt: ' . substr( $output, 0, 2000 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Disposable CLI evidence.
	} finally {
		wp_delete_file( $file );
	}
};
$files = array(
	array( 'path' => 'website/index.html', 'content' => '<html><head><title>Root</title></head><body><p>Root content</p></body></html>' ),
	array( 'path' => 'website/target/index.html', 'content' => '<html><head><title>Target</title></head><body><p>Alias destination</p></body></html>' ),
	array( 'path' => 'website/projects/item/index.html', 'content' => '<html><head><title>Item</title></head><body><p>Editable descendant</p></body></html>' ),
	array( 'path' => 'website/_redirects', 'content' => "/projects /target/index.html 301\n" ),
);
$request = array( 'operation' => 'apply', 'slug' => 'alias-hierarchy-neutral', 'activate' => true, 'overwrite' => true, 'source' => array( 'type' => 'files', 'entrypoint' => 'website/index.html', 'files' => $files ) );
$result = $import( $request );
$assert( ! empty( $result['success'] ), 'Synthetic alias import must complete: ' . wp_json_encode( $result['error'] ?? array() ) );
$parent = get_page_by_path( 'projects', OBJECT, 'page' );
$child  = get_page_by_path( 'projects/item', OBJECT, 'page' );
$target = get_page_by_path( 'target', OBJECT, 'page' );
$assert( $parent instanceof WP_Post && 'draft' === $parent->post_status, 'Only the generated alias hierarchy parent remains a draft.' );
$assert( $child instanceof WP_Post && 'publish' === $child->post_status && (int) $child->post_parent === (int) $parent->ID, 'Published child retains native parent identity.' );
$assert( 'projects/item' === get_page_uri( $child ) && home_url( '/projects/item/' ) === get_permalink( $child ), 'Draft parent retains the exact descendant permalink.' );
$query = new WP_Query( array( 'pagename' => 'projects/item', 'post_status' => 'publish' ) );
$assert( in_array( (int) $child->ID, wp_list_pluck( $query->posts, 'ID' ), true ) && current_user_can( 'edit_post', $child->ID ) && current_user_can( 'edit_post', $parent->ID ), 'Descendant is publicly queryable and both native pages are editable.' );
$owned = get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION );
$rule  = $owned['alias-hierarchy-neutral']['rules']['/projects'];
$assert( get_permalink( $target ) === $rule['config']['action_data']['url'], 'Native exact alias binds to the committed destination post.' );
$receipt_ref = $result['result']['response_artifacts']['artifacts']['materialization_receipt'];
$assert( is_file( $receipt_ref['path'] ) && hash_file( 'sha256', $receipt_ref['path'] ) === $receipt_ref['sha256'], 'Full durable receipt matches its response artifact identity.' );
$receipt = json_decode( file_get_contents( $receipt_ref['path'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verified disposable receipt artifact.
$parents = array_values( array_filter( $receipt['wordpress'], static fn( $page ) => 'alias_hierarchy_parent' === ( $page['route_ownership'] ?? '' ) ) );
$assert( 1 === count( $parents ) && 'draft' === $parents[0]['post_status'] && (int) $parent->ID === (int) $receipt['completed']['pages'][ $parents[0]['source_path'] ] && 'website/target/index.html' === $parents[0]['alias_target_source_path'], 'Receipt states the hierarchy-only publication and its exact alias target.' );
$again = $import( $request );
$assert( ! empty( $again['success'] ) && (int) get_page_by_path( 'projects', OBJECT, 'page' )->ID === (int) $parent->ID, 'Reimport keeps the native hierarchy and rule ownership.' );

$protected = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'protected', 'post_title' => 'Owner page', 'post_content' => 'Owner content' ), true );
$shadow    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'protected', 'post_title' => 'Draft same slug' ), true );
$assert( (int) Static_Site_Importer_Redirection_Materializer::published_route_owner( '/protected' )->ID === (int) $protected, 'Draft same-slug content never conceals a genuine published route owner.' );
$blocked = $request;
$blocked['slug'] = 'alias-protected-neutral';
$blocked['overwrite'] = true;
$blocked['source']['files'][2]['path'] = 'website/protected/item/index.html';
$blocked['source']['files'][3]['content'] = "/protected /target/index.html 301\n";
$failure = $import( $blocked );
$assert( empty( $failure['success'] ) && 'static_site_importer_redirect_route_occupied' === ( $failure['error']['code'] ?? '' ), 'A genuine published alias occupant remains a failure even with overwrite requested.' );
$assert( 'publish' === get_post_status( $protected ) && 'Owner content' === get_post_field( 'post_content', $protected ), 'Protected real content is never drafted, replaced or deleted.' );

$real = $request;
$real['slug'] = 'alias-real-page-neutral';
$real['source']['files'][] = array( 'path' => 'website/projects/index.html', 'content' => '<html><head><title>Real projects</title></head><body><p>Real captured parent</p></body></html>' );
$real_failure = $import( $real );
$assert( empty( $real_failure['success'] ) && in_array( $real_failure['error']['code'] ?? '', array( 'static_site_importer_redirect_route_occupied', 'static_site_importer_redirect_ambiguous' ), true ), 'A captured real page competing with an explicit alias is rejected, rather than treated as a hierarchy-only row: ' . wp_json_encode( $real_failure['error'] ?? array() ) );
$assert( 'draft' === get_post_status( $parent->ID ) && (int) get_post_field( 'post_parent', $child->ID ) === (int) $parent->ID && $owned === get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION ), 'Competing real-page rollback restores the prior native hierarchy and alias.' );

$compiler = new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
$plan = $compiler->compile( array( 'entrypoint' => 'website/index.html', 'files' => array_column( array_slice( $files, 0, 3 ), 'content', 'path' ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$before = array( 'parent_status' => get_post_status( $parent->ID ), 'child_content' => get_post_field( 'post_content', $child->ID ), 'theme' => get_stylesheet(), 'front' => get_option( 'page_on_front' ) );
$failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'alias-hierarchy-neutral', 'overwrite' => true, 'activate' => true, 'source_route_aliases' => array( array( 'from' => 'projects', 'to' => 'target/index.html' ) ), 'inject_materialization_failure' => 'after_activation' ) );
$assert( 'partial' === $failed['status'] && 'injected_after_activation_failure' === $failed['errors'][0]['code'], 'Injected mutation failure stays a transaction failure in its receipt.' );
$after = array( 'parent_status' => get_post_status( $parent->ID ), 'child_content' => get_post_field( 'post_content', $child->ID ), 'theme' => get_stylesheet(), 'front' => get_option( 'page_on_front' ) );
$assert( $before === $after && $owned === get_option( Static_Site_Importer_Redirection_Materializer::OWNERSHIP_OPTION ), 'Rollback restores hierarchy status, editable content, runtime options and native redirect ownership.' );
echo wp_json_encode( array( 'status' => 'passed', 'parent_id' => $parent->ID, 'child_id' => $child->ID, 'target_id' => $target->ID, 'child_url' => get_permalink( $child ), 'alias_url' => home_url( '/projects' ), 'target_url' => get_permalink( $target ), 'receipt_parent' => $parents[0], 'protected_post_id' => $protected, 'draft_shadow_id' => $shadow ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
