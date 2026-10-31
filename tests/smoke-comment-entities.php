<?php
/**
 * Comment entity import, reimport, nesting, and captured avatar smoke.
 *
 * @package StaticSiteImporter
 */

// phpcs:disable Squiz.Commenting, Generic.Commenting.DocComment, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile -- Standalone WordPress stubs.

require __DIR__ . '/smoke-wordpress-site-plan-materializer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-comment-avatar.php';

class WP_Comment {
	public function __construct( public int $comment_ID ) {}
}
function esc_url_raw( string $url ): string {
	return $url; }
function get_date_from_gmt( string $date ): string {
	return $date; }
function get_comments( array $args ): array {
	$result = array();
	foreach ( $GLOBALS['ssi_comments'] ?? array() as $id => $data ) {
		if ( (int) $data['comment_post_ID'] === $args['post_id'] && get_comment_meta( $id, $args['meta_key'], true ) === $args['meta_value'] ) {
			$result[] = new WP_Comment( $id );
		}
	}
	return $result;
}
function wp_insert_comment( array $data ): int {
	$id = count( $GLOBALS['ssi_comments'] ?? array() ) + 1;
	$GLOBALS['ssi_comments'][ $id ] = $data;
	return $id;
}
function wp_update_comment( array $data, bool $error = false ): int {
	$id = $data['comment_ID'];
	unset( $data['comment_ID'] );
	$GLOBALS['ssi_comments'][ $id ] = wp_unslash( $data );
	return 1;
}
function get_comment( int $id, $output = null ) {
	return $GLOBALS['ssi_comments'][ $id ] + array( 'comment_ID' => $id ); }
function wp_delete_comment( int $id, bool $force = false ): bool {
	unset( $GLOBALS['ssi_comments'][ $id ], $GLOBALS['ssi_comment_meta'][ $id ] );
	return true;
}
function update_comment_meta( int $id, string $key, $value ): void {
	$GLOBALS['ssi_comment_meta'][ $id ][ $key ] = $value; }
function get_comment_meta( int $id, string $key, bool $single = false ) {
	return $GLOBALS['ssi_comment_meta'][ $id ][ $key ] ?? ''; }
function get_avatar_url( $comment ): string {
	$data = array();
	foreach ( $GLOBALS['ssi_plan_filters']['pre_get_avatar_data'][10] ?? array() as $filter ) {
		$data = ( $filter['callback'] )( $data, $comment );
	}
	return $data['url'] ?? 'default-avatar'; }

Static_Site_Importer_Comment_Avatar::register();
$comment_plan = ( new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( array( 'path' => 'index.html', 'content' => '<article><h1>Comment test</h1><p>Post body.</p></article>', 'metadata' => array( 'post_type' => 'post' ) ) ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$comment_path = $comment_plan['pages'][0]['source_path'];
$top = array(
	'source_path' => $comment_path,
	'source_id' => 'comment-249',
	'parent_source_id' => '',
	'author_name' => 'Captured Author',
	'author_url' => 'https://example.com/author',
	'date' => '2024-01-02T10:00:00+00:00',
	'content' => '<p>A captured comment.</p>',
	'avatar_url' => 'https://example.com/avatar.png',
);
$reply = array_merge( $top, array( 'source_id' => 'comment-250', 'parent_source_id' => 'comment-249', 'author_name' => 'Reply Author', 'content' => '<p>A reply.</p>', 'date' => '2024-01-03T11:00:00+00:00', 'avatar_url' => 'https://example.com/reply.png' ) );
// Deliberately put the reply first: input order must not lose threading.
$comment_plan['comment_entities'] = array( $reply, $top );
$comment_plan['plan_identity'] = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::planIdentity( $comment_plan );
$comment_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $comment_plan, array( 'slug' => 'comment-entities' ) );
$assert( 'completed' === $comment_receipt['status'], 'comment plan completes' );
$assert( 2 === count( $comment_receipt['applied']['comment_entities'] ?? array() ), 'receipt lists both comments' );
$assert( 2 === count( $GLOBALS['ssi_comments'] ?? array() ), 'two comments inserted' );
$comment_ids = array_column( $comment_receipt['applied']['comment_entities'], 'comment_id', 'source_id' );
foreach ( array( $top, $reply ) as $entity ) {
	$id = $comment_ids[ $entity['source_id'] ];
	$stored = $GLOBALS['ssi_comments'][ $id ];
	$assert( 1 === $stored['comment_approved'] && '' === $stored['comment_author_email'], 'approved without email' );
	$assert( $stored['comment_author'] === $entity['author_name'] && $stored['comment_author_url'] === $entity['author_url'], 'author preserved' );
	$assert( $stored['comment_date_gmt'] === gmdate( 'Y-m-d H:i:s', strtotime( $entity['date'] ) ) && $stored['comment_content'] === $entity['content'], 'date and content preserved' );
	$assert( $stored['comment_parent'] === ( $entity['parent_source_id'] ? $comment_ids[ $entity['parent_source_id'] ] : 0 ), 'thread parent mapped' );
	$assert( get_avatar_url( new WP_Comment( $id ) ) === $entity['avatar_url'], 'captured avatar returned' );
}
$comment_plan['comment_entities'][0]['content'] = '<p>Updated reply.</p>';
$comment_plan['plan_identity'] = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::planIdentity( $comment_plan );
$again = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $comment_plan, array( 'slug' => 'comment-entities', 'overwrite' => true ) );
$assert( 'completed' === $again['status'] && 2 === count( $GLOBALS['ssi_comments'] ), 'reimport does not duplicate comments' );
$assert( $again['applied']['comment_entities'] === $comment_receipt['applied']['comment_entities'], 'stable receipt IDs' );
$assert( '<p>Updated reply.</p>' === $GLOBALS['ssi_comments'][ $comment_ids['comment-250'] ]['comment_content'], 'reimport updates content' );
$assert( 'default-avatar' === get_avatar_url( new WP_Comment( 999 ) ) && 'default-avatar' === get_avatar_url( 'someone@example.com' ), 'unrelated avatars untouched' );
$comment_plan['comment_entities'][0]['content'] = '<p>Rolled back reply.</p>';
$comment_plan['comment_entities'][0]['avatar_url'] = 'https://example.com/changed.png';
$comment_plan['plan_identity'] = \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::planIdentity( $comment_plan );
$staged = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $comment_plan, array( 'slug' => 'comment-entities', 'overwrite' => true, 'defer_materialization_commit' => true ) );
Static_Site_Importer_Site_Plan_Persistence::rollback_receipt( $staged, 'test_rollback' );
$assert( '<p>Updated reply.</p>' === $GLOBALS['ssi_comments'][ $comment_ids['comment-250'] ]['comment_content'], 'rollback restores existing content' );
$assert( $reply['avatar_url'] === get_avatar_url( new WP_Comment( $comment_ids['comment-250'] ) ), 'rollback restores existing avatar' );
echo "Comment entities smoke passed.\n";
