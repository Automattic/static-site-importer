<?php
/** Real WordPress term, route, and inherited archive-query acceptance. */
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The disposable runtime swaps query globals to render the empty native archive template.
if ( ! defined( 'SSI_TAXONOMY_DISPOSABLE_TEST' ) || true !== SSI_TAXONOMY_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Run taxonomy archive acceptance only in the declared disposable runtime.' );
}
wp_set_current_user( 1 );

$ssi_autoloader = require_once '/wordpress/wp-content/plugins/static-site-importer/vendor/autoload.php';

$release_package_mode = defined( 'SSI_TAXONOMY_RELEASE_PACKAGE' ) && true === SSI_TAXONOMY_RELEASE_PACKAGE;
if ( ! $release_package_mode ) {
	$engine_root = '/wordpress/wp-content/plugins/blocks-engine-candidate';
	$ssi_autoloader->setPsr4( 'Automattic\\BlocksEngine\\PhpTransformer\\', $engine_root . '/src/', true );
	if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
		require_once $engine_root . '/php-transformer.php';
	}
}
require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$taxonomy_assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion text is not rendered as HTML.
	}
};

if ( $release_package_mode ) {
	require_once '/wordpress/wp-content/plugins/static-site-importer/tests/acceptance/taxonomy-release-package-proof.php';
	$release_package_proof = ssi_taxonomy_release_package_proof();
	echo 'SSI-TAXONOMY-RELEASE-PACKAGE:' . wp_json_encode( $release_package_proof ) . "\n";
}

$slug                 = 'personal';
$archive_route        = '/writing/category/' . $slug;
$editor_reload_marker = 'SSI Gutenberg category template reload persisted';
$files                = array(
	array(
		'path'    => 'index.html',
		'content' => '<html><body><header><p>Shared source header</p></header><main><h1>Home</h1></main><footer><p>Shared source footer</p></footer></body></html>',
	),
	array(
		'path'     => 'archives/personal.html',
		'content'  => '<html><body><header><p>Shared source header</p></header><main><h1>Personal</h1>'
			. implode( '', array_map( static fn( int $index ): string => '<article class="entry"><h2><a href="/writing/story-' . $index . '">Story ' . $index . '</a></h2><p>Summary ' . $index . '</p></article>', range( 12, 1 ) ) )
			. '</main><footer><p>Shared source footer</p></footer></body></html>',
		'metadata' => array( 'route_path' => $archive_route ),
	),
);
foreach ( range( 1, 12 ) as $index ) {
	$files[] = array(
		'path'    => 'writing/story-' . $index . '.html',
		'content' => '<html><head><meta property="article:published_time" content="2025-01-' . sprintf( '%02d', $index ) . 'T12:00:00Z"></head><body><header><p>Shared source header</p></header><article><h1>Story ' . $index . '</h1><p>Full story body ' . $index . '.</p><a href="' . $archive_route . '">Personal</a></article><footer><p>Shared source footer</p></footer></body></html>',
	);
}
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	array(
		'entrypoint' => 'index.html',
		'files'      => $files,
	),
	array(
		'slug'     => 'taxonomy-archive-acceptance',
		'activate' => true,
	)
);
$taxonomy_assert( ! is_wp_error( $compiled ), 'Captured source must compile: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$plan = $compiled['plan'];
$taxonomy_assert( 1 === count( $plan['taxonomy_entities'] ?? array() ), 'Compiler must derive one corroborated Personal category entity.' );
$entity            = $plan['taxonomy_entities'][0];
$category_template = array_values( array_filter( $plan['templates'], static fn( array $template ): bool => 'category-personal' === ( $template['slug'] ?? '' ) ) )[0] ?? array();
$taxonomy_assert( 12 === count( $entity['membership_source_paths'] ?? array() ) && ( $entity['archive']['source_route'] ?? null ) === $archive_route, 'Entity must retain the 12 source members and canonical archive route.' );
$taxonomy_assert( 0 === ( $plan['quality']['metrics']['fallback_count'] ?? -1 ), 'The accepted fixture must contain no fallback blocks.' );
$shared_chrome   = implode( "\n", array_map( static fn( array $part ): string => (string) ( $part['canonical_block_markup'] ?? '' ), $plan['template_parts'] ?? array() ) );
$captured_chrome = (string) ( $category_template['canonical_block_markup'] ?? '' ) . $shared_chrome;
$taxonomy_assert( str_contains( $captured_chrome, 'Shared source header' ) && str_contains( $captured_chrome, 'Shared source footer' ), 'The category presentation must retain its captured shared chrome.' );
$taxonomy_assert( str_contains( (string) ( $category_template['canonical_block_markup'] ?? '' ), '"slug":"footer"' ) && str_contains( $shared_chrome, 'Shared source footer' ), 'The category template must retain the shared footer wrapper and its captured source-owned content.' );

$rollback_args                                   = $compiled['args'];
$rollback_args['slug']                           = 'taxonomy-archive-rollback';
$rollback_args['inject_materialization_failure'] = 'theme_write_short';
$rollback_receipt                                = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $rollback_args );
$rollback_rules                                  = get_option( 'rewrite_rules', array() );
$rollback_files                                  = 0;
$rollback_theme_dir                              = get_theme_root() . '/taxonomy-archive-rollback';
if ( is_dir( $rollback_theme_dir ) ) {
	$rollback_file_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $rollback_theme_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $rollback_file_iterator as $rollback_file ) {
		if ( $rollback_file->isFile() ) {
			++$rollback_files;
		}
	}
}
$rollback_probe   = array(
	'term_exists' => term_exists( $slug, 'category' ),
	'source_post' => get_page_by_path( 'story-1', OBJECT, 'post' ) instanceof WP_Post,
	'route_rule'  => $rollback_rules['^writing/category/personal/?$'] ?? null,
	'theme_files' => $rollback_files,
	'rollback'    => $rollback_receipt['rollback'] ?? null,
);
$rollback_summary = array(
	'status'   => $rollback_receipt['status'] ?? null,
	'errors'   => $rollback_receipt['errors'] ?? array(),
	'rollback' => $rollback_receipt['rollback'] ?? null,
);
$taxonomy_assert(
	'partial' === ( $rollback_receipt['status'] ?? '' ) && 'theme_write_failed' === ( $rollback_receipt['errors'][0]['code'] ?? '' ),
	'An injected late write failure must return a rolled-back partial receipt: ' . wp_json_encode( $rollback_summary )
);
$taxonomy_assert( ! $rollback_probe['term_exists'] && ! $rollback_probe['source_post'] && null === $rollback_probe['route_rule'] && 0 === $rollback_probe['theme_files'], 'Rollback must remove transaction-owned terms, source posts, exact routes and theme files: ' . wp_json_encode( $rollback_probe ) );

$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $compiled['args'] );
$taxonomy_assert( 'completed' === ( $receipt['status'] ?? '' ), 'SSI taxonomy import must complete: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
$taxonomy_assert( 1 === count( $receipt['completed']['taxonomy_entities'] ?? array() ) && 12 === count( $receipt['completed']['taxonomy_entities'][0]['post_ids'] ?? array() ), 'The receipt must report the term ID and all source-to-WordPress post memberships.' );
require_once get_stylesheet_directory() . '/functions.php';
$personal_term = get_term_by( 'slug', $slug, 'category' );
$taxonomy_assert( $personal_term instanceof WP_Term && 'Personal' === $personal_term->name, 'Imported category term must preserve its source name.' );
$taxonomy_assert( '' === trim( (string) get_option( 'category_base', '' ), '/' ), 'The exact source archive route must not mutate the destination global category base.' );
$source_archive_url = untrailingslashit( home_url( $archive_route ) );
$native_term_url    = untrailingslashit( (string) get_term_link( $personal_term ) );
$taxonomy_assert( $source_archive_url === $native_term_url, 'Native term permalink must own the exact canonical source archive route.' );
$rewrite_rules = get_option( 'rewrite_rules', array() );
$taxonomy_assert( 'index.php?category_name=personal' === ( $rewrite_rules['^writing/category/personal/?$'] ?? null ) && 'index.php?category_name=personal&paged=$matches[1]' === ( $rewrite_rules['^writing/category/personal/page/([0-9]+)/?$'] ?? null ), 'The source base and /page/N routes have exact taxonomy rewrites without moving the global category base.' );
$archive_template = get_block_template( get_stylesheet() . '//category-' . $slug );
$taxonomy_assert( $archive_template instanceof WP_Block_Template && str_contains( $archive_template->content, '"inherit":true' ) && str_contains( $archive_template->content, 'query-pagination' ), 'WordPress must resolve the contextual inherited category template with pagination.' );

$source_page_ids  = $receipt['completed']['pages'] ?? array();
$source_member_id = (int) ( $source_page_ids['writing/story-1.html'] ?? 0 );
$taxonomy_assert( $source_member_id > 0 && has_term( $personal_term->term_id, 'category', $source_member_id ), 'The materialized article must carry its source-proven category membership.' );
$owned_meta_before  = get_post_meta( $source_member_id, '_static_site_importer_taxonomy_memberships', true );
$terms_before_fault = wp_get_object_terms( $source_member_id, 'category', array( 'fields' => 'ids' ) );
$stale_owner_term   = wp_insert_term( 'Stale SSI owner marker', 'category', array( 'slug' => 'stale-ssi-owner-marker' ) );
$taxonomy_assert( ! is_wp_error( $stale_owner_term ), 'A stale importer ownership marker term must be available for update/readback fault injection.' );
$owned_meta_before = array( 'category' => array( $personal_term->term_id, (int) $stale_owner_term['term_id'] ) );
update_post_meta( $source_member_id, '_static_site_importer_taxonomy_memberships', $owned_meta_before );
$ownership_writes = 0;
$metadata_fault   = static function ( $check, int $object_id, string $meta_key ) use ( &$ownership_writes, $source_member_id ) {
	if ( $source_member_id === $object_id && '_static_site_importer_taxonomy_memberships' === $meta_key ) {
		++$ownership_writes;
		return 2 === $ownership_writes ? false : $check;
	}
	return $check;
};
add_filter( 'update_post_metadata', $metadata_fault, 10, 3 );
$ownership_fault_args                                   = $compiled['args'];
$ownership_fault_args['slug']                           = 'taxonomy-archive-ownership-fault';
$ownership_fault_args['inject_materialization_failure'] = 'theme_write_short';
$ownership_fault_receipt                                = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $ownership_fault_args );
remove_filter( 'update_post_metadata', $metadata_fault, 10 );
$owned_meta_after_fault    = get_post_meta( $source_member_id, '_static_site_importer_taxonomy_memberships', true );
$terms_after_fault         = wp_get_object_terms( $source_member_id, 'category', array( 'fields' => 'ids' ) );
$rollback_failures         = $ownership_fault_receipt['rollback']['failures'] ?? array();
$ownership_failure_summary = array(
	'status'          => $ownership_fault_receipt['status'] ?? null,
	'errors'          => $ownership_fault_receipt['errors'] ?? array(),
	'receipt'         => $ownership_fault_receipt['rollback'] ?? null,
	'terms_before'    => $terms_before_fault,
	'terms_after'     => $terms_after_fault,
	'metadata_before' => $owned_meta_before,
	'metadata_after'  => $owned_meta_after_fault,
);
$taxonomy_assert(
	'partial' === ( $ownership_fault_receipt['status'] ?? '' ) && ! empty( $rollback_failures ) && in_array( 'taxonomy_post_meta', array_column( $rollback_failures, 'kind', 'target' ), true ) && $terms_before_fault === $terms_after_fault && $owned_meta_before === $owned_meta_after_fault,
	'Fault-injected ownership metadata restoration failure must retain exact relationship and ownership values while explicitly reporting rollback failure: ' . wp_json_encode( $ownership_failure_summary )
);
$owner_term = wp_insert_term( 'Owner selected', 'category', array( 'slug' => 'owner-selected' ) );
$taxonomy_assert( ! is_wp_error( $owner_term ), 'A destination-owned category must be available for preservation checks.' );
$owner_term_id = (int) $owner_term['term_id'];
wp_set_object_terms( $source_member_id, array( $owner_term_id ), 'category', true );
$reimport_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, $compiled['args'] );
$taxonomy_assert( 'completed' === ( $reimport_receipt['status'] ?? '' ) && has_term( $personal_term->term_id, 'category', $source_member_id ) && has_term( $owner_term_id, 'category', $source_member_id ), 'Reimport must retain producer-owned membership and preserve a destination-selected category.' );
$elsewhere = wp_insert_term( 'Elsewhere', 'category', array( 'slug' => 'elsewhere' ) );
$taxonomy_assert( ! is_wp_error( $elsewhere ), 'Second category fixture must be created.' );
$elsewhere_id = (int) $elsewhere['term_id'];
$new_post_id  = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Added after import',
		'post_content' => '<p>Live archive member.</p>',
	),
	true
);
$taxonomy_assert( ! is_wp_error( $new_post_id ), 'A post added after import must be insertable.' );
wp_set_object_terms( (int) $new_post_id, array( $personal_term->term_id ), 'category', false );
$other_post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Outside the archive',
		'post_content' => '<p>Unrelated content.</p>',
	),
	true
);
$taxonomy_assert( ! is_wp_error( $other_post_id ), 'An unrelated post must be insertable.' );
wp_set_object_terms( (int) $other_post_id, array( $elsewhere_id ), 'category', false );
$first_page  = new WP_Query(
	array(
		'cat'            => $personal_term->term_id,
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'paged'          => 1,
	)
);
$second_page = new WP_Query(
	array(
		'cat'            => $personal_term->term_id,
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'paged'          => 2,
	)
);
$first_ids   = array_map( 'intval', wp_list_pluck( $first_page->posts, 'ID' ) );
$second_ids  = array_map( 'intval', wp_list_pluck( $second_page->posts, 'ID' ) );
$taxonomy_assert( 13 === (int) $first_page->found_posts && 3 === (int) $first_page->max_num_pages, 'Adding a post after import must increase native archive count and pagination.' );
$taxonomy_assert( ! in_array( (int) $other_post_id, $first_ids, true ) && ! in_array( (int) $other_post_id, $second_ids, true ) && array() === array_intersect( $first_ids, $second_ids ), 'Archive pagination must remain scoped and return disjoint pages.' );

wp_set_object_terms( $source_member_id, array( $elsewhere_id ), 'category', false );
$recategorized = new WP_Query(
	array(
		'cat'            => $personal_term->term_id,
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
	)
);
$taxonomy_assert( ! in_array( $source_member_id, wp_list_pluck( $recategorized->posts, 'ID' ), true ) && 12 === (int) $recategorized->found_posts, 'Recategorizing a post must update the archive query without changing a page.' );
$taxonomy_assert( untrailingslashit( (string) get_term_link( $personal_term ) ) === $native_term_url, 'Source route identity remains stable after membership changes.' );


$empty_term          = wp_insert_term( 'Empty category', 'category', array( 'slug' => 'empty-category' ) );
$empty_query         = new WP_Query(
	array(
		'category_name' => 'empty-category',
		'post_type'     => 'post',
		'post_status'   => 'publish',
	)
);
$previous_query      = $GLOBALS['wp_query'] ?? null;
$previous_post       = $GLOBALS['post'] ?? null;
$GLOBALS['wp_query'] = $empty_query;
$GLOBALS['post']     = null;
$empty_html          = do_blocks( $archive_template->content );
$GLOBALS['wp_query'] = $previous_query;
$GLOBALS['post']     = $previous_post;
$taxonomy_assert( ! is_wp_error( $empty_term ) && 0 === (int) $empty_query->found_posts && str_contains( $empty_html, 'No posts found.' ), 'An empty native term archive remains a valid empty query with its captured native empty state.' );
$ssi_plugin_active = in_array( 'static-site-importer/static-site-importer.php', (array) get_option( 'active_plugins', array() ), true );
$taxonomy_assert( ! $ssi_plugin_active, 'Browser route proof must exercise the generated theme bootstrap with SSI absent from the active plugin list.' );

echo 'WordPress ' . esc_html( get_bloginfo( 'version' ) ) . "\n";
echo "Taxonomy archive WordPress store acceptance passed.\n";
echo "SSI is inactive for the subsequent real base/page-2 HTTP requests.\n";
echo 'SSI-TAXONOMY-ADOPTION-PLAN:' . wp_json_encode(
	array(
		'plan' => $plan,
		'args' => $compiled['args'],
	)
) . "\n";
