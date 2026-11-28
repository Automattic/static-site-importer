<?php
/** Run with WP-CLI in an explicitly disposable, HTTP-serving WordPress site. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_NESTED_PAGE_TEST' ) ) {
	throw new RuntimeException( 'Set SSI_NESTED_PAGE_TEST=1 in a disposable WordPress site.' );
}
wp_set_current_user( 1 );
// Start with no native Pages in this explicitly disposable site.
foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1 ) ) as $seed_page ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact disposable query.
	wp_delete_post( $seed_page->ID, true );
}
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );
require_once dirname( __DIR__, 2 ) . '/static-site-importer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-compilation-preparation.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$assert   = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion, not browser output.
	}
};
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			array(
				'path'    => 'index.html',
				'content' => '<html><head><title>Home</title></head><body><header><p>Shared header</p></header><main><h1>Home</h1><a href="/pages/111/about/">About</a></main><footer><p>Shared footer</p></footer></body></html>',
			),
			array(
				'path'    => 'pages/111/about/index.html',
				'content' => '<html><head><title>About</title></head><body><header><p>Shared header</p></header><main><h1>About</h1><p>Nested page source route acceptance body.</p><a href="/pages/111/about/">About link</a></main><footer><p>Shared footer</p></footer></body></html>',
			),
		),
	),
	array(
		'slug'     => 'nested-page-acceptance',
		'activate' => true,
	)
);
$assert( ! is_wp_error( $compiled ), 'The reduced artifact must compile.' );
$plan      = $compiled['plan'];
$lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plan, $compiled['args'] );
$assert( ! is_wp_error( $lifecycle ), 'The runtime lifecycle must resolve.' );
$prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare_for_materialization( $plan, $compiled['args'] );
$assert( 'prepared' === ( $prepared['status'] ?? '' ), 'The plan must prepare: ' . wp_json_encode( $prepared['receipt']['errors'] ?? array() ) );
$applied = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared_lifecycle( $prepared, $lifecycle, $compiled['companion_payload'], $compiled['gutenberg_gaps'], $compiled['theme_materialization'] );
$assert( ! is_wp_error( $applied ), 'The import must apply: ' . ( is_wp_error( $applied ) ? $applied->get_error_message() : '' ) );
$receipt = $applied['receipt'];
Static_Site_Importer_WordPress_Site_Plan_Materializer::commit_receipt( $receipt );
$assert( 'completed' === ( $receipt['status'] ?? '' ), 'The import must complete: ' . wp_json_encode( $receipt['errors'] ?? array() ) );

$imported_pages = get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'post__not_in'   => array( (int) get_option( 'page_on_front' ) ),
	)
);
$assert( 1 === count( $imported_pages ) && 'About' === $imported_pages[0]->post_title, 'The import must leave exactly one Page, About, without synthetic placeholders: ' . wp_json_encode( wp_list_pluck( $imported_pages, 'post_title' ) ) );
$assert( 0 === (int) wp_count_posts( 'page' )->draft, 'The import must leave zero draft Pages.' );
$assert( 0 === (int) $imported_pages[0]->post_parent, 'About must be a top-level Page.' );
$assert( home_url( '/pages/111/about/' ) === get_permalink( $imported_pages[0] ), 'About must keep its full source permalink: ' . get_permalink( $imported_pages[0] ) . ' routes=' . wp_json_encode( get_option( 'static_site_importer_post_routes' ) ) );
foreach ( $receipt['completed']['unresolved_link_targets'] ?? array() as $target ) {
	$assert( '/pages/111/about' !== rtrim( (string) ( $target['route'] ?? '' ), '/' ), 'The internal About link must resolve.' );
}
$response = wp_remote_get(
	home_url( '/pages/111/about/' ),
	array(
		'timeout'     => 15,
		'redirection' => 0,
	)
);
$assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'A real GET of the nested source route must return 200 without a redirect.' );
$assert( str_contains( wp_remote_retrieve_body( $response ), 'Nested page source route acceptance body.' ), 'The source route must serve About.' );
$assert( str_contains( (string) get_post_field( 'post_content', $imported_pages[0]->ID ), 'href="/pages/111/about/"' ), 'The rewritten internal link must keep the full source route.' );
echo "Nested page source routes passed.\n";
