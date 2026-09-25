<?php
/**
 * Smoke test: imported pages under a core rewrite base stay reachable.
 *
 * A page at `category/<slug>` or `tag/<slug>` is shadowed by the taxonomy
 * archive rules, which WP_Rewrite places ahead of page rules. On a site that
 * does not use the taxonomy yet, the import moves the colliding base so the
 * source URL resolves to the imported page. A taxonomy with published posts
 * keeps its base and archive URLs, and the shadowed pages are reported.
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

// Each case starts as a fresh request would: default bases, rules rebuilt.
$reset_rewrite = static function (): void {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	$wp_rewrite->set_category_base( '' );
	$wp_rewrite->set_tag_base( '' );
	create_initial_taxonomies();
	$wp_rewrite->extra_rules_top = array();
	flush_rewrite_rules( false );
};

// The query of the first rewrite rule WP::parse_request() would match.
$routed_query = static function ( string $path ): string {
	global $wp_rewrite;
	foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $match => $query ) {
		if ( preg_match( '#^' . $match . '#', $path ) ) {
			return (string) $query;
		}
	}
	return '';
};

$document = static fn( string $title ): string => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title></head><body><main><h1>' . $title . '</h1><p>' . $title . ' body.</p></main></body></html>';

$import = static function () use ( $document ) {
	$files = array( 'index.html' => 'Home', 'category/shop-all/index.html' => 'Shop All', 'category/shop-all/sub/index.html' => 'Shop Sub', 'tag/news/index.html' => 'News', 'author/jane/index.html' => 'Jane' );
	return Static_Site_Importer_Theme_Generator::import_website_artifact(
		array(
			'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
			'files'  => array_map(
				static fn( string $path, string $title ): array => array(
					'path'    => $path,
					'content' => $document( $title ),
				),
				array_keys( $files ),
				array_values( $files )
			),
		),
		array(
			'name'      => 'Rewrite Base Collision',
			'slug'      => 'rewrite-base-collision-smoke',
			'overwrite' => true,
			'activate'  => false,
		)
	);
};

$receipt_parts = static function ( array $result ): array {
	$receipt = $result['materialization_receipt'] ?? array();
	$moves   = array_values( array_filter( $receipt['completed']['operations'] ?? array(), static fn( $operation ): bool => 'move_rewrite_base' === ( $operation['kind'] ?? '' ) ) );
	$shadow  = array();
	foreach ( $receipt['diagnostics'] ?? array() as $diagnostic ) {
		if ( 'page_route_shadowed_by_core_rewrite' === ( $diagnostic['reason_code'] ?? '' ) ) {
			$shadow[ $diagnostic['target_path'] ] = $diagnostic;
		}
	}
	return array( array_column( $moves, 'to', 'from' ), $shadow );
};

$resolves_to_page = static function ( string $path ) use ( $assert ): void {
	$page     = get_page_by_path( $path );
	$resolved = url_to_postid( home_url( '/' . $path . '/' ) );
	$assert( $page instanceof WP_Post && $page->ID === $resolved, 'source-url-resolves-to-page-' . $path, 'url_to_postid=' . $resolved );
};

// Case 1: a fresh site (only the default category is used) moves both bases.
$reset_rewrite();
$result = $import();
$assert( ! is_wp_error( $result ), 'fresh-import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );
if ( ! is_wp_error( $result ) ) {
	foreach ( array( 'category/shop-all', 'category/shop-all/sub', 'tag/news' ) as $path ) {
		$resolves_to_page( $path );
	}
	$assert( 'category-archive' === get_option( 'category_base' ), 'fresh-category-base-moves-off-imported-path', (string) get_option( 'category_base' ) );
	$assert( 'tag-archive' === get_option( 'tag_base' ), 'fresh-tag-base-moves-off-imported-path', (string) get_option( 'tag_base' ) );
	$rules = (array) get_option( 'rewrite_rules' );
	$assert( isset( $rules['category-archive/(.+?)/?$'] ) && isset( $rules['tag-archive/([^/]+)/?$'] ), 'fresh-taxonomy-archives-stay-routable-at-moved-bases' );
	$assert( ! isset( $rules['category/(.+?)/?$'] ) && ! isset( $rules['tag/([^/]+)/?$'] ), 'fresh-shadowing-taxonomy-rules-are-gone' );
	list( $moves, $shadow ) = $receipt_parts( $result );
	$assert( array( 'category' => 'category-archive', 'tag' => 'tag-archive' ) === $moves, 'fresh-receipt-records-rewrite-base-moves', (string) wp_json_encode( $moves ) );
	$assert( array( 'author/jane' ) === array_keys( $shadow ) && 'author' === $shadow['author/jane']['rewrite'], 'fresh-unmovable-author-base-collision-is-reported', (string) wp_json_encode( $shadow ) );
}

// Case 2: a published post in a real category keeps the category base; tags still move.
$reset_rewrite();
$term    = wp_insert_term( 'Ceramics', 'category', array( 'slug' => 'ceramics' ) );
$term_id = is_wp_error( $term ) ? (int) ( get_term_by( 'slug', 'ceramics', 'category' )->term_id ?? 0 ) : (int) $term['term_id'];
$post_id = wp_insert_post( array( 'post_title' => 'Ceramics post', 'post_status' => 'publish', 'post_category' => array( $term_id ) ) );
$result  = $import();
$assert( ! is_wp_error( $result ), 'category-in-use-import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );
if ( ! is_wp_error( $result ) ) {
	$assert( '' === get_option( 'category_base' ), 'category-in-use-keeps-category-base', (string) get_option( 'category_base' ) );
	$assert( 'tag-archive' === get_option( 'tag_base' ), 'category-in-use-still-moves-unused-tag-base', (string) get_option( 'tag_base' ) );
	$assert( str_contains( $routed_query( 'category/ceramics' ), 'category_name=' ), 'category-in-use-archive-url-still-resolves', $routed_query( 'category/ceramics' ) );
	$resolves_to_page( 'tag/news' );
	list( $moves, $shadow ) = $receipt_parts( $result );
	$assert( array( 'tag' => 'tag-archive' ) === $moves, 'category-in-use-receipt-records-only-tag-move', (string) wp_json_encode( $moves ) );
	$category_warning = $shadow['category/shop-all'] ?? array();
	$assert( 'category' === ( $category_warning['rewrite'] ?? '' ) && isset( $shadow['category/shop-all/sub'] ) && str_contains( (string) ( $category_warning['detail'] ?? '' ), 'Settings > Permalinks' ), 'category-in-use-collision-is-reported', (string) wp_json_encode( $shadow ) );
}
wp_delete_post( (int) $post_id, true );
wp_delete_term( $term_id, 'category' );

// Case 3: a published post tagged `news` keeps the tag base; the category still moves.
$reset_rewrite();
$post_id = wp_insert_post( array( 'post_title' => 'News post', 'post_status' => 'publish', 'tags_input' => array( 'news' ) ) );
$result  = $import();
$assert( ! is_wp_error( $result ), 'tag-in-use-import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );
if ( ! is_wp_error( $result ) ) {
	$assert( '' === get_option( 'tag_base' ), 'tag-in-use-keeps-tag-base', (string) get_option( 'tag_base' ) );
	$assert( 'category-archive' === get_option( 'category_base' ), 'tag-in-use-still-moves-unused-category-base', (string) get_option( 'category_base' ) );
	$assert( str_contains( $routed_query( 'tag/news' ), 'tag=' ), 'tag-in-use-archive-url-still-resolves', $routed_query( 'tag/news' ) );
	$resolves_to_page( 'category/shop-all' );
	list( $moves, $shadow ) = $receipt_parts( $result );
	$assert( array( 'category' => 'category-archive' ) === $moves, 'tag-in-use-receipt-records-only-category-move', (string) wp_json_encode( $moves ) );
	$assert( 'tag' === ( $shadow['tag/news']['rewrite'] ?? '' ) && ! isset( $shadow['category/shop-all'] ), 'tag-in-use-collision-is-reported', (string) wp_json_encode( $shadow ) );
}
wp_delete_post( (int) $post_id, true );
$news = get_term_by( 'slug', 'news', 'post_tag' );
if ( $news ) {
	wp_delete_term( $news->term_id, 'post_tag' );
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
