<?php
/**
 * Real WordPress store acceptance for a data-liberation-agent content index:
 * declared slugs, post_date/post_modified, label terms, no label-listing
 * posts, and redirects from old permalinks and label URLs.
 *
 * Runs inside a disposable WordPress with this plugin mounted at
 * wp-content/plugins/static-site-importer and, for paired candidate mode, a
 * Blocks Engine php-transformer checkout at wp-content/plugins/blocks-engine-candidate.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'SSI_CONTENT_INDEX_DISPOSABLE_TEST' ) || true !== SSI_CONTENT_INDEX_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Run content index acceptance only in the declared disposable runtime.' );
}
wp_set_current_user( 1 );

$ssi_plugin     = WP_CONTENT_DIR . '/plugins/static-site-importer';
$ssi_autoloader = require_once $ssi_plugin . '/vendor/autoload.php';
$ssi_candidate  = WP_CONTENT_DIR . '/plugins/blocks-engine-candidate';
if ( is_dir( $ssi_candidate . '/src' ) ) {
	$ssi_autoloader->setPsr4( 'Automattic\\BlocksEngine\\PhpTransformer\\', $ssi_candidate . '/src/', true );
}
require_once $ssi_plugin . '/static-site-importer.php';
require_once $ssi_plugin . '/includes/class-static-site-importer-compilation-preparation.php';
require_once $ssi_plugin . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require_once $ssi_plugin . '/includes/class-static-site-importer-redirection-materializer.php';

$ssi_assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion text.
	}
};

update_option( 'timezone_string', 'UTC' );
update_option( 'permalink_structure', '/%year%/%monthnum%/%postname%/' );
flush_rewrite_rules( false );

$fixture  = require $ssi_plugin . '/tests/fixtures/content-index-artifact.php';
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	array(
		'entrypoint' => 'website/index.html',
		'files'      => $fixture['files'],
	),
	array(
		'slug'     => 'content-index-acceptance',
		'activate' => true,
	)
);
$ssi_assert( ! is_wp_error( $compiled ), 'The DLA artifact must compile: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
$ssi_assert( 'completed' === ( $receipt['status'] ?? '' ), 'The import must complete: ' . wp_json_encode( $receipt['errors'] ?? $receipt['status'] ?? null ) );
$ids = $receipt['completed']['pages'] ?? array();

$expected = array(
	'website/2025/01/post-0.html' => array( 'post-0', '2025-01-10 17:00:00', '2025-02-01 12:00:00', array( 'food', 'travel' ) ),
	'website/2025/02/post-1.html' => array( 'post-1', '2025-02-11 10:30:00', '2025-03-01 08:00:00', array( 'travel' ) ),
	'website/2025/03/post-2.html' => array( 'post-2', '2025-03-12 00:00:00', '2025-03-13 00:00:00', array( 'food' ) ),
);
foreach ( $expected as $source => [ $slug, $published, $modified, $tags ] ) {
	$ssi_post = get_post( (int) ( $ids[ $source ] ?? 0 ) );
	$ssi_assert( $ssi_post instanceof WP_Post && 'post' === $ssi_post->post_type && 'publish' === $ssi_post->post_status, 'Declared posts are published posts: ' . $source );
	$ssi_assert( $slug === $ssi_post->post_name, 'Declared slug: ' . $source . ' => ' . $ssi_post->post_name );
	$ssi_assert( $published === $ssi_post->post_date_gmt, 'Declared publication date: ' . $source . ' => ' . $ssi_post->post_date_gmt );
	$ssi_assert( $modified === $ssi_post->post_modified_gmt && $modified === $ssi_post->post_modified, 'Declared modification date: ' . $source . ' => ' . $ssi_post->post_modified_gmt . ' / ' . $ssi_post->post_modified );
	$assigned = wp_get_post_terms( $ssi_post->ID, 'post_tag', array( 'fields' => 'slugs' ) );
	sort( $assigned );
	$ssi_assert( $tags === $assigned, 'Label terms: ' . $source . ' => ' . wp_json_encode( $assigned ) );
}
foreach ( array(
	'travel' => 'Travel',
	'food'   => 'Food',
) as $slug => $name ) {
	$ssi_term = get_term_by( 'slug', $slug, 'post_tag' );
	$ssi_assert( $ssi_term instanceof WP_Term && $name === $ssi_term->name, 'Label term exists with its source name: ' . $slug );
}
$listing_sources = array( 'website/search/label/Travel.html', 'website/' . $fixture['food_path'] );
foreach ( $listing_sources as $source ) {
	$ssi_assert( ! isset( $ids[ $source ] ), 'A label listing is not a post or page: ' . $source );
}
$listing_posts = get_posts(
	array(
		'post_type'   => array( 'post', 'page' ),
		'post_status' => 'any',
		'title'       => 'Travel',
		'numberposts' => -1,
	)
);
$ssi_assert( array() === $listing_posts, 'No post or page carries a label listing.' );

$redirects = Static_Site_Importer_Redirection_Materializer::routes( $receipt, $compiled['args'] );
$ssi_assert( is_array( $redirects ), 'Redirect routes must bind: ' . ( is_wp_error( $redirects ) ? $redirects->get_error_code() : '' ) );
foreach ( array_keys( $expected ) as $source ) {
	$from = '/' . substr( $source, strlen( 'website/' ) );
	$ssi_assert( get_permalink( (int) $ids[ $source ] ) === ( $redirects[ $from ] ?? null ), 'Old permalink redirects to its post: ' . $from . ' ' . wp_json_encode( $redirects ) );
}
foreach ( array(
	'Travel' => 'travel',
	'Food'   => 'food',
) as $label => $slug ) {
	$ssi_link = get_term_link( $slug, 'post_tag' );
	$ssi_assert( ( $redirects[ '/search/label/' . $label ] ?? null ) === $ssi_link, 'Label URL redirects to its term: ' . $label . ' ' . wp_json_encode( $redirects ) );
}
echo 'Content index WordPress store acceptance passed.' . "\n";
