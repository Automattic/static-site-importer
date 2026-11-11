<?php
/**
 * Real WordPress smoke: dated posts retain mixed source routes and native archives.
 * Run: wp eval-file tests/smoke-post-source-routes.php
 *
 * @package StaticSiteImporter
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-persistence.php';

$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$previous_structure = get_option( 'permalink_structure' );
$ids                = array();
$state              = array( 'ordered_pages' => array(), 'source_ids' => array(), 'applied' => array( 'operations' => array() ) );
global $wp_rewrite;
try {
	$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
	foreach ( array( 'communities/ssi-route-a/index.html', 'communities/ssi-route-b/index.html', 'news/ssi-route-c.html' ) as $path ) {
		$id = wp_insert_post( array(
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => basename( dirname( $path ) ) . basename( $path ),
			'post_date' => '2024-10-23 19:32:40',
			'post_content' => '<p>Imported post</p>',
		) );
		$ids[] = $id;
		$state['ordered_pages'][] = array( 'source_path' => $path, 'post_type' => 'post' );
		$state['source_ids'][ $path ] = $id;
		update_post_meta( $id, '_static_site_importer_source_route', $path );
	}
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SSI unchanged page', 'post_name' => 'ssi-unchanged-page' ) );
	$ids[] = $page;
	$page_url = get_permalink( $page );
	$assert( Static_Site_Importer_Site_Plan_Persistence::serve_post_source_routes( $state ), 'Route persistence failed' );
	$assert( isset( $state['rollback']['options']['static_site_importer_post_routes'] ), 'Route ownership must be journaled' );
	// Read the persisted rules as a later request would, not in-memory add_rewrite_rule state.
	$wp_rewrite->rules = get_option( 'rewrite_rules' );
	foreach ( $state['source_ids'] as $path => $id ) {
		$route = Static_Site_Importer_Site_Plan_Persistence::normalized_route_path( '/' . $path );
		$route = str_ends_with( $route, '.html' ) ? $route : $route . '/';
		$url = home_url( $route );
		$assert( $url === get_permalink( $id ), 'Post canonical must be its source URL: ' . $path );
		$assert( $id === url_to_postid( $url ), 'Source route must resolve directly, without a 301 slug guess: ' . $path );
		$_SERVER['REQUEST_URI'] = wp_make_link_relative( $url );
		$GLOBALS['wp']->main();
		$assert( is_single( $id ) && ! is_404(), 'Core request must serve the source route: ' . $path );
		$assert( ! redirect_canonical( $url, false ), 'Core must not redirect the source route: ' . $path );
		$markup = Static_Site_Importer_Site_Plan_Persistence::rewrite_route_references( '<a href="/' . $path . '">Read</a>', array( rtrim( $route, '/' ) => wp_make_link_relative( get_permalink( $id ) ) ) );
		$assert( str_contains( $markup, 'href="' . $route . '"' ), 'Importer link must use the source URL' );
		$assert( 'post' === get_post_type( $id ) && '2024-10-23 19:32:40' === get_post_field( 'post_date', $id ), 'Post type and date changed' );
	}
	$archive = new WP_Query( array( 'post_type' => 'post', 'year' => 2024, 'monthnum' => 10, 'day' => 23, 'post__in' => array_values( $state['source_ids'] ), 'fields' => 'ids' ) );
	$assert( 3 === $archive->post_count, 'Posts must retain date archive membership' );
	$assert( $page_url === get_permalink( $page ), 'Page permalink changed' );
	$assert( '/%year%/%monthnum%/%day%/%postname%/' === get_option( 'permalink_structure' ), 'Unrelated post structure changed' );
	Static_Site_Importer_Site_Plan_Persistence::rollback( $state );
	$assert( ! str_contains( get_permalink( $ids[0] ), '/communities/' ), 'Rollback must restore original permalink ownership' );
	echo "Post source routes smoke passed.\n";
} finally {
	if ( isset( $state['rollback']['options'] ) ) {
		Static_Site_Importer_Site_Plan_Persistence::rollback( $state );
	}
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	$wp_rewrite->set_permalink_structure( $previous_structure );
	flush_rewrite_rules( false );
}
