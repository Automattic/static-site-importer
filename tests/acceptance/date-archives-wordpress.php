<?php
/** Run with WP-CLI in an explicitly disposable, HTTP-serving WordPress site. */
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The disposable runtime replays one front-end request in-process.
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_DATE_ARCHIVE_TEST' ) ) {
	throw new RuntimeException( 'Set SSI_DATE_ARCHIVE_TEST=1 in a disposable WordPress site.' );
}
wp_set_current_user( 1 );
require_once dirname( __DIR__, 2 ) . '/static-site-importer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-compilation-preparation.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion text is not rendered as HTML.
	}
};
update_option( 'permalink_structure', '/%year%/%monthnum%/%day%/%postname%/' );
flush_rewrite_rules( false );

$archive_route = '/posts/archives/2026/10';
$article       = static fn( string $title, string $date ): string => '<html><head><title>' . $title . '</title><meta property="article:published_time" content="' . $date . '"></head><body><header><p>Shared header</p></header><article><h1>' . $title . '</h1><p>Body of ' . $title . '.</p><a href="' . $archive_route . '">October 2026</a></article><footer><p>Shared footer</p></footer></body></html>';
$compiled      = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			array(
				'path'    => 'index.html',
				'content' => '<html><body><header><p>Shared header</p></header><main><h1>Home</h1><p><a href="' . $archive_route . '">October 2026</a></p></main><footer><p>Shared footer</p></footer></body></html>',
			),
			array(
				'path'    => 'posts/october-first.html',
				'content' => $article( 'October first story', '2026-10-05T12:00:00Z' ),
			),
			array(
				'path'    => 'posts/october-second.html',
				'content' => $article( 'October second story', '2026-10-16T12:00:00Z' ),
			),
			array(
				'path'    => 'posts/november-story.html',
				'content' => $article( 'November story', '2026-11-02T12:00:00Z' ),
			),
		),
	),
	array(
		'slug'     => 'date-archive-acceptance',
		'activate' => true,
	)
);
$assert( ! is_wp_error( $compiled ), 'Captured source must compile: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$plan                  = $compiled['plan'];
$plan['date_archives'] = array(
	array(
		'archive' => array(
			'source_path'  => 'posts/archives/2026/10/index.html',
			'source_route' => $archive_route,
			'query'        => array(
				'year'     => 2026,
				'monthnum' => 10,
			),
		),
	),
);
$plan['plan_identity'] = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::planIdentity( $plan );

// Apply the plan as the canonical import does, so the generated runtime ships the route owner.
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, $compiled['args'] );
$assert( ! is_wp_error( $lifecycle ), 'The plan runtime lifecycle must resolve.' );
$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare_for_materialization( $plan, $compiled['args'] );
$assert( 'prepared' === ( $prepared['status'] ?? '' ), 'The date archive plan must prepare: ' . wp_json_encode( $prepared['receipt']['errors'] ?? array() ) );
$applied = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared_lifecycle( $prepared, $lifecycle, $compiled['companion_payload'], $compiled['gutenberg_gaps'], $compiled['theme_materialization'] );
$assert( ! is_wp_error( $applied ), 'Date archive import must complete: ' . ( is_wp_error( $applied ) ? $applied->get_error_message() : '' ) );
$receipt = $applied['receipt'];
Static_Site_Importer_WordPress_Site_Plan_Materializer::commit_receipt( $receipt );
$assert( 'completed' === ( $receipt['status'] ?? '' ), 'Date archive import must complete: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
$ids = $receipt['completed']['pages'] ?? array();
foreach ( array( 'posts/october-first.html', 'posts/october-second.html' ) as $source ) {
	$assert( 'post' === get_post_type( (int) ( $ids[ $source ] ?? 0 ) ) && '2026-10' === get_the_date( 'Y-m', (int) $ids[ $source ] ), 'Imported story must be an October 2026 post: ' . $source );
}

$response = wp_remote_get(
	home_url( $archive_route . '/' ),
	array(
		'timeout'     => 15,
		'redirection' => 0,
	)
);
$assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'GET of the source date archive must return 200, not 404.' );
$body = wp_remote_retrieve_body( $response );
$assert( (bool) preg_match( '/<body[^>]*class="[^"]*\bdate\b/', $body ) && ! str_contains( $body, 'error404' ), 'GET of the source route must render the native date archive.' );
$assert( str_contains( $body, 'October first story' ) && str_contains( $body, 'October second story' ) && ! str_contains( $body, 'November story' ), 'The native month archive must list exactly the October posts.' );

// Replay the source route through WordPress's own request parser and query.
$_SERVER['REQUEST_URI'] = $archive_route . '/';
$GLOBALS['wp']->main();
$titles = wp_list_pluck( $GLOBALS['wp_query']->posts, 'post_title' );
sort( $titles );
$assert( is_month() && ! is_404() && array( 'October first story', 'October second story' ) === $titles, 'The source route must resolve to the native October 2026 archive: ' . wp_json_encode( $titles ) );

$assert( ( get_option( 'static_site_importer_date_archive_routes' )[ $archive_route ] ?? null ) === array( 'year' => 2026, 'monthnum' => 10 ), 'The import must own the declared date archive route.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expected query.
foreach ( $receipt['completed']['unresolved_link_targets'] ?? array() as $target ) {
	$assert( trim( (string) ( $target['route'] ?? '' ), '/' ) !== trim( $archive_route, '/' ), 'Links to the source date archive must resolve.' );
}
$home_content = (string) get_post_field( 'post_content', (int) ( $ids['index.html'] ?? 0 ) );
$assert( str_contains( $home_content, 'href="' . $archive_route . '/"' ), 'Imported links keep the served source date archive route.' );

echo wp_json_encode(
	array(
		'status' => 'passed',
		'route'  => $archive_route,
		'titles' => $titles,
	)
) . "\n";
