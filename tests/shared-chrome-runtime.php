<?php
/** Disposable real WordPress proof; invoked by tools/run-shared-chrome-acceptance.sh. */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local evidence files in the disposable CLI runtime require direct filesystem access.

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_SHARED_CHROME_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Run only in the explicitly disposable shared-chrome test runtime.' );
}
$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$report  = json_decode( file_get_contents( '/evidence/import-report.json' ), true, 512, JSON_THROW_ON_ERROR );
$receipt = $report['materialization_receipt'];
$assert( 'completed' === $receipt['status'], 'Import must have completed: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
$phase = $args[0] ?? 'initial';
if ( 'rollback' === $phase ) {
	$inventory = json_decode( file_get_contents( '/evidence/runtime-inventory.json' ), true, 512, JSON_THROW_ON_ERROR );
	$before    = array();
	foreach ( $inventory['menus'] as $navigation_id ) {
		$before[ $navigation_id ] = get_post_field( 'post_content', $navigation_id );
	}
	$request = json_decode( file_get_contents( '/evidence/request.json' ), true, 512, JSON_THROW_ON_ERROR );
	$files   = array();
	foreach ( $request['source']['files'] as $file ) {
		$files[ 'website/' . $file['path'] ] = $file['content'];
	}
	$result = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'website/index.html',
			'files'      => $files,
		)
	)->toArray();
	$plan   = $result['source_reports']['wordpress_site_plan'];
	$failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
		$plan,
		array(
			'slug'                           => $inventory['theme'],
			'overwrite'                      => true,
			'activate'                       => true,
			'inject_materialization_failure' => 'after_activation',
		)
	);
	$assert( 'partial' === $failed['status'] && 'rolled_back' === $failed['rollback']['status'], 'The late failure must roll back: ' . wp_json_encode( $failed['errors'] ?? array() ) );
	$attempted_ids = array_column( $failed['completed']['navigation_entities'], 'id' );
	sort( $attempted_ids );
	$original_ids = array_keys( $before );
	sort( $original_ids );
	$assert( $attempted_ids === $original_ids, 'The failing attempt updated the actual existing entities rather than creating disposable duplicates.' );
	foreach ( $before as $navigation_id => $content ) {
		$assert( get_post_field( 'post_content', $navigation_id ) === $content, 'A late failure restores saved navigation content exactly.' );
	}
	file_put_contents(
		'/evidence/rollback.json',
		wp_json_encode(
			array(
				'pass'    => true,
				'menus'   => array_keys( $before ),
				'failure' => $failed['errors'],
			),
			JSON_PRETTY_PRINT
		)
	);
	echo "shared-chrome rollback: ok\n";
	return;
}
if ( 'reimport' === $phase ) {
	$inventory = json_decode( file_get_contents( '/evidence/runtime-inventory.json' ), true, 512, JSON_THROW_ON_ERROR );
	$second    = json_decode( file_get_contents( '/evidence/reimport/import-report.json' ), true, 512, JSON_THROW_ON_ERROR )['materialization_receipt'];
	$ids       = array_column( $second['completed']['navigation_entities'], 'id' );
	sort( $ids );
	$first_ids = $inventory['menus'];
	sort( $first_ids );
	$assert( 'completed' === $second['status'] && $ids === $first_ids, 'Reimport updates the same entities without duplicating menus.' );
	file_put_contents(
		'/evidence/reimport.json',
		wp_json_encode(
			array(
				'pass'  => true,
				'menus' => $ids,
			),
			JSON_PRETTY_PRINT
		)
	);
	echo "shared-chrome reimport: ok\n";
	return;
}
$entities = $receipt['completed']['navigation_entities'];
$assert( count( $entities ) >= 3, 'Shared navigation and the distinct fragment menus have persisted owners.' );
$ids      = array_column( $entities, 'id' );
$contents = array_map( static fn( int $id ): string => get_post_field( 'post_content', $id ), $ids );
$assert( str_contains( implode( '', $contents ), '#one' ) && str_contains( implode( '', $contents ), '#two' ), 'Different section destinations survive persistence.' );
$assert( count( $ids ) === count( array_unique( $ids ) ), 'Every declared entity has a distinct persisted ID.' );
$assert( is_file( get_stylesheet_directory() . '/parts/header.html' ) && is_file( get_stylesheet_directory() . '/parts/footer.html' ), 'Header and footer each have one theme-owned editable part.' );
foreach ( $receipt['completed']['pages'] as $source => $page_id ) {
	$content = get_post_field( 'post_content', $page_id );
	$assert( ! str_contains( $content, '{{wordpress-site-plan:navigation:' ), 'Persisted page has no pending navigation IDs: ' . $source );
	$assert( str_contains( $content, '"slug":"header"' ) && str_contains( $content, '"slug":"footer"' ), 'Page keeps shared regions at their authored positions.' );
}
$primary = null;
foreach ( $ids as $navigation_id ) {
	if ( str_contains( get_post_field( 'post_content', $navigation_id ), '"label":"Home"' ) ) {
		$primary = $navigation_id;
		break;
	}
}
$assert( is_int( $primary ), 'A real navigation entity owns the primary labels.' );
$fallback_count = $report['blocks_engine']['wordpress_site_plan']['quality']['metrics']['fallback_count'];
$assert( 0 === $fallback_count, 'The neutral imported fixture has zero fallback blocks.' );
$page_urls    = array_map( 'get_permalink', $receipt['completed']['pages'] );
$entry_source = array_values( array_filter( $report['blocks_engine']['wordpress_site_plan']['pages'], static fn( array $page ): bool => $page['entrypoint'] ) )[0]['source_path'];
file_put_contents(
	'/evidence/runtime-inventory.json',
	wp_json_encode(
		array(
			'theme'          => get_stylesheet(),
			'primary'        => $primary,
			'menus'          => $ids,
			'pages'          => $receipt['completed']['pages'],
			'urls'           => $page_urls,
			'entry'          => $receipt['completed']['pages'][ $entry_source ],
			'fallback_count' => $fallback_count,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);
echo "shared-chrome-runtime: ok\n";
