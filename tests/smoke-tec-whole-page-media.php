<?php
/** Event detail document is finalized after the Media Library resolver runs. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Post {
	public function __construct( public int $ID, public string $post_status, public string $post_content, public string $post_type = 'tribe_events' ) {}
}
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}
function get_post( $id, $output = OBJECT ) { $post = $GLOBALS['posts'][ $id ] ?? null; return ARRAY_A === $output && $post ? (array) $post : $post; }
function get_post_field( $field, $id ) { return get_post( $id )->$field; }
function get_post_type( $id ) { return get_post( $id )->post_type; }
function get_page_by_path( ...$args ) { return null; }
function wp_update_post( $fields, $error = false ) {
	if ( isset( $GLOBALS['update_error'] ) ) { return $GLOBALS['update_error']; }
	foreach ( $fields as $key => $value ) { $GLOBALS['posts'][ $fields['ID'] ]->$key = $value; }
	return $fields['ID'];
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post_meta( $id, $key = '', $single = false ) { $values = $GLOBALS['meta'][ $id ][ $key ] ?? array(); return $single ? ( $values[0] ?? '' ) : $values; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function add_post_meta( $id, $key, $value, $unique = false ) { $GLOBALS['meta'][ $id ][ $key ][] = $value; return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = array( $value ); return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_slash( $value ) { return $value; }
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
foreach ( array( 'whole-page-handoff', 'source-route', 'site-plan-persistence' ) as $file ) {
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-' . $file . '.php';
}
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
// Minimal neutral site: one detail page with one body image, no featured image.
$markup = '<!-- wp:image --><figure class="wp-block-image"><img src="{{wordpress-site-plan:asset:event-image}}" alt="Sample event"/></figure><!-- /wp:image -->';
$row = array(
	'id' => 'sample-event', 'source_path' => 'website/events/sample-event/index.html',
	'source_route' => '/events/sample-event', 'whole_page_candidate' => 'blocks-engine/whole-page-candidate/v1',
);
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WholePageCandidates;
$page = array(
	'source_path' => $row['source_path'], 'route' => array( 'path' => $row['source_route'] ),
	'reconciliation_identity' => 'page-sample-event', 'post_type' => 'page',
);
$pages = WholePageCandidates::attach( array( $page ), array( array(
	'kind' => 'entity_collection', 'reconciliation_identity' => 'declaration-events',
	'payload' => array( 'entities' => array( $row ) ),
) ), array() );
$resolver = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver::class;
$source_url = 'https://example.test/wp-content/themes/sample/assets/sample-event.png';
$attachment_url = 'https://example.test/wp-content/uploads/2027/04/sample-event.png';
$token = '{{wordpress-site-plan:asset:event-image}}';
$pages[0]['resolved_block_markup'] = $resolver::resolveMarkup( $markup, array( $token => $source_url ) );
$document = Static_Site_Importer_Whole_Page_Handoff::documents( $pages )['declaration-events']['sample-event'];
// This is the document persisted by TEC before plan materialization.
$GLOBALS['posts'] = array( 101 => new WP_Post( 101, 'publish', $document['post_content'] ) );
$GLOBALS['meta'] = array();
$result = array_diff_key( $pages[0]['whole_page_candidates'][0], array( 'schema' => true ) ) + array(
	'schema' => Static_Site_Importer_Whole_Page_Handoff::SCHEMA, 'status' => 'committed',
	'destination_post_id' => 101, 'post_type' => 'tribe_events',
);
$pages[0]['materialized_block_markup'] = $resolver::resolveMarkup( $markup, array( $token => $attachment_url ) );
$before = Static_Site_Importer_Whole_Page_Handoff::validate( $pages, array( $result ) );
$assert( 'whole_page_claim_unproven' === $before['diagnostics'][0]['reason_code'], 'Pre-media provider snapshot cannot prove the final document.' );
$state = array(
	'args' => array( 'whole_page_provider_results' => array( $result ) ),
	'ordered_pages' => $pages, 'diagnostics' => array(), 'source_route_aliases' => array(),
	'applied' => array( 'runtime_declarations' => array( 'entity_bindings' => array() ) ),
);
$apply = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'apply_whole_page_handoffs' );
$invalid = $state;
$invalid['args']['whole_page_provider_results'][0]['entity_id'] = 'unrelated-event';
$arguments = array( &$invalid );
$rejected = $apply->invokeArgs( null, $arguments );
$assert( is_wp_error( $rejected ) && 'whole_page_claim_unproven' === $rejected->code, 'An unrelated claim cannot authorize a content write.' );
$assert( $document['post_content'] === get_post_field( 'post_content', 101 ), 'Rejected identities leave provider content untouched.' );
$arguments = array( &$state );
$outcome = $apply->invokeArgs( null, $arguments );
$assert( true === $outcome, 'Event handoff failed: ' . ( is_wp_error( $outcome ) ? $outcome->code : 'unknown' ) );
$content = get_post_field( 'post_content', 101 );
$assert( str_contains( $content, $attachment_url ) && ! str_contains( $content, $source_url ), 'Event content must reference the Media Library URL, not the source asset.' );
$assert( $content === $pages[0]['materialized_block_markup'], 'One final document is persisted and proven.' );
$assert( empty( Static_Site_Importer_Whole_Page_Handoff::validate( $pages, array( $result ) )['diagnostics'] ), 'Persisted final content proves the handoff.' );
$assert( $document['post_content'] === $state['rollback']['posts'][101]['post']['post_content'], 'The existing journal retains provider content for rollback.' );
$failed_write = $state;
$GLOBALS['update_error'] = new WP_Error( 'fixture_update_failed' );
$arguments = array( &$failed_write );
$failed = $apply->invokeArgs( null, $arguments );
$assert( $GLOBALS['update_error'] === $failed, 'A failed final-document write must propagate, not prove a handoff.' );
$assert( $content === get_post_field( 'post_content', 101 ), 'Failed writes preserve the provider content.' );
unset( $GLOBALS['update_error'] );
echo "PASS TEC whole-page Media Library content proof\n";
