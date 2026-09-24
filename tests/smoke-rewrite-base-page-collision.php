<?php
/**
 * Smoke test: imported pages under a core rewrite base stay reachable.
 *
 * A page at `category/<slug>` or `tag/<slug>` is shadowed by the taxonomy
 * archive rules, which WP_Rewrite places ahead of page rules. The import moves
 * the colliding base, so the source URL resolves to the imported page.
 *
 * Run inside a disposable WordPress site with Static Site Importer available:
 * wp eval-file tests/smoke-rewrite-base-page-collision.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

global $wp_rewrite;
$previous = array(
	'permalink_structure' => get_option( 'permalink_structure' ),
	'category_base'       => get_option( 'category_base' ),
	'tag_base'            => get_option( 'tag_base' ),
);
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->set_category_base( '' );
$wp_rewrite->set_tag_base( '' );
create_initial_taxonomies();
flush_rewrite_rules( false );

$document = static fn( string $title ): string => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title></head><body><main><h1>' . $title . '</h1><p>' . $title . ' body.</p></main></body></html>';

$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
		'files'  => array(
			array(
				'path'    => 'index.html',
				'content' => $document( 'Home' ),
			),
			array(
				'path'    => 'category/shop-all/index.html',
				'content' => $document( 'Shop All' ),
			),
			array(
				'path'    => 'category/shop-all/sub/index.html',
				'content' => $document( 'Shop Sub' ),
			),
			array(
				'path'    => 'tag/news/index.html',
				'content' => $document( 'News' ),
			),
			array(
				'path'    => 'author/jane/index.html',
				'content' => $document( 'Jane' ),
			),
		),
	),
	array(
		'name'      => 'Rewrite Base Collision',
		'slug'      => 'rewrite-base-collision-smoke',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$receipt = $result['materialization_receipt'] ?? array();
	foreach ( array( 'category/shop-all', 'category/shop-all/sub', 'tag/news' ) as $path ) {
		$page     = get_page_by_path( $path );
		$resolved = url_to_postid( home_url( '/' . $path . '/' ) );
		$assert( $page instanceof WP_Post, 'page-is-imported-' . $path );
		$assert( $page instanceof WP_Post && $page->ID === $resolved, 'source-url-resolves-to-page-' . $path, 'url_to_postid=' . $resolved );
	}
	$assert( 'category-archive' === get_option( 'category_base' ), 'category-base-moves-off-imported-path', (string) get_option( 'category_base' ) );
	$assert( 'tag-archive' === get_option( 'tag_base' ), 'tag-base-moves-off-imported-path', (string) get_option( 'tag_base' ) );
	$rules = (array) get_option( 'rewrite_rules' );
	$assert( isset( $rules['category-archive/(.+?)/?$'] ) && isset( $rules['tag-archive/([^/]+)/?$'] ), 'taxonomy-archives-stay-routable-at-moved-bases' );
	$assert( ! isset( $rules['category/(.+?)/?$'] ) && ! isset( $rules['tag/([^/]+)/?$'] ), 'shadowing-taxonomy-rules-are-gone' );
	$moves = array_values( array_filter( $receipt['completed']['operations'] ?? array(), static fn( $operation ): bool => 'move_rewrite_base' === ( $operation['kind'] ?? '' ) ) );
	$assert( 2 === count( $moves ) && 'category' === ( $moves[0]['from'] ?? '' ) && 'category-archive' === ( $moves[0]['to'] ?? '' ), 'receipt-records-rewrite-base-moves', (string) wp_json_encode( $moves ) );
	$shadowed = array_values( array_filter( $receipt['diagnostics'] ?? array(), static fn( $diagnostic ): bool => 'page_route_shadowed_by_core_rewrite' === ( $diagnostic['reason_code'] ?? '' ) ) );
	$assert( 1 === count( $shadowed ) && 'author/jane' === ( $shadowed[0]['target_path'] ?? '' ), 'unmovable-author-base-collision-is-reported', (string) wp_json_encode( $shadowed ) );
}

$wp_rewrite->set_permalink_structure( (string) $previous['permalink_structure'] );
$wp_rewrite->set_category_base( (string) $previous['category_base'] );
$wp_rewrite->set_tag_base( (string) $previous['tag_base'] );
// The next request rebuilds the rules from the restored options.
delete_option( 'rewrite_rules' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: rewrite base page collision smoke passed (' . $assertions . " assertions)\n";
