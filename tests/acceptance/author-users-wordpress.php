<?php
/**
 * Hand-built consumer contract fixture. No Blocks Engine compilation: the
 * author_entities and parent producer changes are not yet released.
 *
 * @package StaticSiteImporter
 */
if ( ! defined( 'SSI_AUTHOR_DISPOSABLE_TEST' ) || ! SSI_AUTHOR_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Requires the disposable author workload.' );
}
require_once '/wordpress/wp-content/plugins/static-site-importer/vendor/autoload.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
wp_set_current_user( 1 );
update_option( 'default_role', 'contributor' );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );
$evidence        = array(
	'membership' => true,
	'name'       => true,
	'archive'    => true,
);
$author          = array(
	'kind'                    => 'author',
	'slug'                    => 'proven-writer',
	'name'                    => 'Proven Writer',
	'membership_source_paths' => array( 'story.html' ),
	'archive'                 => array(
		'source_path'         => 'author/proven-writer/index.html',
		'source_route'        => '/author/proven-writer',
		'presentation_markup' => '',
		'query'               => array(
			'post_type' => 'post',
			'author'    => 'proven-writer',
		),
	),
	'evidence'                => $evidence,
);
$make_term       = static function ( string $slug, string $route ) use ( $evidence ): array {
	return array(
		'kind'                    => 'taxonomy_term',
		'taxonomy'                => 'category',
		'slug'                    => $slug,
		'name'                    => ucfirst( $slug ),
		'membership_source_paths' => array( 'story.html' ),
		'archive'                 => array(
			'source_path'         => $route . '/index.html',
			'source_route'        => $route,
			'presentation_markup' => '',
			'query'               => array(
				'post_type' => 'post',
				'taxonomy'  => 'category',
				'term'      => $slug,
			),
		),
		'evidence'                => $evidence,
	);
};
$child           = $make_term( 'ssi-child', '/category/ssi-parent/ssi-child' );
$child['parent'] = 'ssi-parent';
$markup          = '<!-- wp:paragraph --><p>Hand-built story</p><!-- /wp:paragraph -->';
$story_page      = array(
	'source_path'             => 'story.html',
	'slug'                    => 'ssi-story',
	'title'                   => 'Hand-built story',
	'post_type'               => 'post',
	'parent_source_path'      => '',
	'entrypoint'              => false,
	'canonical_block_markup'  => $markup,
	'resolved_block_markup'   => $markup,
	'reconciliation_identity' => hash( 'sha256', 'ssi-author-story' ),
	'content_hash'            => hash( 'sha256', $markup ),
	'route'                   => array(
		'path'        => '/ssi-story',
		'parent_path' => '/',
		'slug'        => 'ssi-story',
	),
);
$plan            = array(
	'schema'               => 'blocks-engine/wordpress-site-plan/v3',
	'plan_identity'        => array(
		'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
		'hash'   => hash( 'sha256', 'hand-built-author-contract' ),
	),
	'pages'                => array( $story_page ),
	'templates'            => array(),
	'template_parts'       => array(),
	'synced_patterns'      => array(),
	'menus'                => array(),
	'assets'               => array(),
	'writes'               => array(),
	'operations'           => array(),
	'routes'               => array(),
	'navigation_links'     => array(),
	'runtime_declarations' => array(),
	'author_entities'      => array( $author ),
	'taxonomy_entities'    => array( $child, $make_term( 'ssi-parent', '/category/ssi-parent' ) ),
);
// Drive the persistence boundary directly with an immutable resolved fixture,
// rather than asking the released producer to validate unreleased contracts.
$persist = static function ( array $fixture, array $args = array() ): array {
	$slug     = 'author-users-acceptance';
	$resolved = $fixture;
	return Static_Site_Importer_Site_Plan_Persistence::materialize_prepared( array(
		'status'                            => 'prepared',
		'plan'                              => $fixture,
		'plan_identity'                     => $fixture['plan_identity'],
		'receipt_instance_id'               => Static_Site_Importer_Site_Plan_Receipt::receipt_instance_id(),
		'base_resolved'                     => $resolved,
		'prepared_resolved_projection_hash' => Static_Site_Importer_Site_Plan_Preparation::prepared_resolved_projection_hash( $resolved ),
		'theme'                             => array(
			'dir' => get_theme_root() . '/' . $slug,
			'uri' => trailingslashit( get_theme_root_uri() ) . $slug,
		),
		'args'                              => array_merge( array(
			'slug'                         => $slug,
			'overwrite'                    => true,
			'defer_materialization_commit' => true,
			'artifact_provenance'          => array( 'source_url' => 'https://source.example/' ),
		), $args ),
	) );
};
$starts  = 0;
$ends    = 0;
add_action( 'import_start', static function () use ( &$starts, $assert ): void {
	++$starts;
	$assert( defined( 'WP_IMPORTING' ) && WP_IMPORTING && wp_defer_term_counting() && wp_defer_comment_counting(), 'Core import context must be active.' );
} );
add_action( 'import_end', static function () use ( &$ends, $assert ): void {
	++$ends;
	$assert( ! wp_defer_term_counting() && ! wp_defer_comment_counting(), 'Counts must be restored before import_end.' );
} );
$generated_password = '';
$password_probe     = static function ( array $data, bool $update, ?int $id, array $userdata ) use ( &$generated_password ): array {
	if ( ! $update && 'proven-writer' === $data['user_login'] ) {
		$generated_password = $userdata['user_pass'];
	}
	return $data;
};
add_filter( 'wp_pre_insert_user_data', $password_probe, 10, 4 );
$receipt = $persist( $plan );
remove_filter( 'wp_pre_insert_user_data', $password_probe );
$assert( 'completed' === $receipt['status'], 'Persistence failed: ' . wp_json_encode( $receipt ) );
$assert( 1 === $starts && 1 === $ends, 'Core hooks fire once.' );
$user = get_user_by( 'login', 'proven-writer' );
$assert( $user instanceof WP_User && array( 'contributor' ) === $user->roles && '' === $user->user_email && '' !== $user->user_pass && 'Proven Writer' === $user->display_name && 'proven-writer' === $user->user_nicename, 'Core user fields and default_role.' );
$assert( strlen( $generated_password ) >= 12 && wp_check_password( $generated_password, $user->user_pass ) && ! str_contains( wp_json_encode( $receipt ), $generated_password ), 'Generated password is hashed by core and never included in receipts.' );
$story_id = (int) $receipt['completed']['pages']['story.html'];
$assert( (int) get_post_field( 'post_author', $story_id ) === (int) $user->ID, 'Proven post authorship.' );
$parent     = get_term_by( 'slug', 'ssi-parent', 'category' );
$child_term = get_term_by( 'slug', 'ssi-child', 'category' );
$assert( (int) $parent->term_id === (int) $child_term->parent && str_contains( get_term_link( $child_term ), '/category/ssi-parent/ssi-child/' ), 'Parent-first insertion and native nested permalink.' );
wp( array( 'category_name' => 'ssi-parent/ssi-child' ) );
$assert( is_category( $child_term->term_id ), 'Native nested category query.' );
$native_rules   = get_option( 'rewrite_rules' );
$matches_native = static function ( string $path, string $query_var ) use ( $native_rules ): bool {
	foreach ( $native_rules as $regex => $query ) {
		if ( preg_match( '~' . $regex . '~', $path ) && str_contains( $query, $query_var . '=' ) ) {
			return true;
		}
	}
	return false;
};
$assert( $matches_native( 'category/ssi-parent/ssi-child/', 'category_name' ) && $matches_native( 'category/ssi-parent/ssi-child/page/2/', 'category_name' ), 'Core rewrite serves nested category and pagination.' );
$assert( $matches_native( 'author/proven-writer/', 'author_name' ) && $matches_native( 'author/proven-writer/page/2/', 'author_name' ), 'Core rewrite serves author and pagination without a custom author route.' );
$native_author = new WP_Query( array( 'author_name' => 'proven-writer' ) );
$assert( $native_author->is_author() && in_array( $story_id, wp_list_pluck( $native_author->posts, 'ID' ), true ), 'Core author archive resolves the imported membership.' );
$repeat = $persist( $plan );
$assert( 'completed' === $repeat['status'] && (int) get_post_field( 'post_author', $story_id ) === (int) $user->ID && 'reused' === $repeat['completed']['author_entities'][0]['status'], 'Reimport reuses marked user.' );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $repeat, 'test_repeat' );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $receipt, 'test_initial' );
$assert( false === get_userdata( $user->ID ) && ! get_post( $story_id ), 'Rollback removes created posts then users.' );

$failed = $persist( $plan, array( 'inject_materialization_failure' => 'font_verification' ) );
$assert( 'partial' === $failed['status'] && ! get_user_by( 'login', 'proven-writer' ) && ! get_post( $story_id ) && ! wp_defer_term_counting() && ! wp_defer_comment_counting(), 'Late persistence failure rolls back created users and restores counts.' );

add_filter( 'import_allow_create_users', '__return_false' );
$disabled = $persist( $plan );
$assert( 'completed' === $disabled['status'] && ! get_user_by( 'login', 'proven-writer' ) && 1 === (int) get_post_field( 'post_author', $disabled['completed']['pages']['story.html'] ) && 'creation_disabled' === $disabled['completed']['author_entities'][0]['status'], 'Creation gate falls back and records the outcome.' );
remove_filter( 'import_allow_create_users', '__return_false' );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $disabled, 'test_disabled' );
$existing_id = wp_insert_user( array(
	'user_login'   => 'proven-writer',
	'user_pass'    => 'existing-password',
	'display_name' => 'Owner',
	'user_email'   => 'owner@example.test',
	'role'         => 'editor',
) );
$before      = get_userdata( $existing_id )->to_array();
$collision   = $persist( $plan );
$assert( 'completed' === $collision['status'] && 1 === (int) get_post_field( 'post_author', $collision['completed']['pages']['story.html'] ) && 'existing_user_login' === $collision['completed']['author_entities'][0]['status'] && get_userdata( $existing_id )->to_array() === $before, 'Unmarked user collision falls back without changing owner.' );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $collision, 'test_collision' );
$assert( get_userdata( $existing_id )->to_array() === $before, 'Rollback preserves unmarked owner.' );
$other_parent   = wp_insert_term( 'Destination parent', 'category', array( 'slug' => 'destination-parent' ) );
$existing_child = wp_insert_term( 'Ssi-child', 'category', array(
	'slug'   => 'ssi-child',
	'parent' => $other_parent['term_id'],
) );
$mismatch       = $persist( $plan );
$assert( 'completed' === $mismatch['status'] && (int) get_term( $existing_child['term_id'], 'category' )->parent === (int) $other_parent['term_id'] && in_array( 'taxonomy_existing_parent_mismatch', array_column( $mismatch['diagnostics'], 'reason_code' ), true ), 'Existing term is never reparented; mismatch is diagnosed.' );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $mismatch, 'test_mismatch' );
wp_delete_term( $existing_child['term_id'], 'category' );
wp_delete_term( $other_parent['term_id'], 'category' );
unset( $plan['author_entities'], $plan['taxonomy_entities'][0]['parent'] );
$legacy = $persist( $plan );
$assert( 'completed' === $legacy['status'] && 1 === (int) get_post_field( 'post_author', $legacy['completed']['pages']['story.html'] ), 'Legacy plan keeps importing user.' );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $legacy, 'test_legacy' );
echo "Author users WordPress acceptance passed.\n";
