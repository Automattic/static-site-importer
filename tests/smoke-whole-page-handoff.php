<?php
/** Canonical whole-page identity and persisted-content regression. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Post {
	public function __construct( public int $ID, public string $post_status, public string $post_content, public string $post_type = 'note' ) {}
}
function get_post( $id ) {
	return $GLOBALS['handoff_posts'][ $id ] ?? null;
}
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-whole-page-handoff.php';
$markup                   = '<!-- wp:image --><figure class="wp-block-image"><img src="/media/one.png" alt="One"/></figure><!-- /wp:image -->';
$GLOBALS['handoff_posts'] = array( 101 => new WP_Post( 101, 'publish', $markup ) );
$candidate                = array(
	'schema'                              => 'blocks-engine/whole-page-candidate/v1',
	'source_path'                         => 'website/notes/one.html',
	'source_route'                        => '/notes/one',
	'page_reconciliation_identity'        => 'page-one',
	'declaration_reconciliation_identity' => 'declaration-one',
	'entity_id'                           => 'entity-one',
);
$page                     = array(
	'source_path'             => $candidate['source_path'],
	'route'                   => array( 'path' => '/notes/one' ),
	'reconciliation_identity' => 'page-one',
	'resolved_block_markup'   => $markup,
	'whole_page_candidates'   => array( $candidate ),
);
$result                   = array_diff_key( $candidate, array( 'schema' => true ) ) + array(
	'schema'              => Static_Site_Importer_Whole_Page_Handoff::SCHEMA,
	'status'              => 'committed',
	'destination_post_id' => 101,
	'post_type'           => 'note',
);
$assert                   = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$valid                    = Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array( $result ) );
$assert( 1 === count( $valid['claims'] ), 'Exact producer identity and saved image markup authorize the handoff.' );
$documents = Static_Site_Importer_Whole_Page_Handoff::documents( array( $page ) );
$assert( $markup === $documents['declaration-one']['entity-one']['post_content'], 'The provider receives the exact compiled document.' );
foreach ( array( 'source_route', 'entity_id', 'declaration_reconciliation_identity', 'page_reconciliation_identity' ) as $field ) {
	$stale           = $result;
	$stale[ $field ] = 'stale';
	$assert( empty( Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array( $stale ) )['claims'] ), 'Stale canonical ' . $field . ' cannot claim ownership.' );
}
$assert( empty( Static_Site_Importer_Whole_Page_Handoff::validate( array( $page, $page ), array( $result ) )['claims'] ), 'Ambiguous source documents are rejected.' );
$assert( empty( Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array( $result, $result ) )['claims'] ), 'Duplicate destinations are rejected.' );
$GLOBALS['handoff_posts'][101]->post_content = '<!-- wp:paragraph --><p>Missing image</p><!-- /wp:paragraph -->';
$result['images_preserved']                  = true;
$result['content_sha256']                    = hash( 'sha256', $GLOBALS['handoff_posts'][101]->post_content );
$assert( empty( Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array( $result ) )['claims'] ), 'Self-reported hashes and image booleans cannot prove source preservation.' );
$assert( empty( Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array() )['claims'] ), 'An absent provider keeps the source page.' );
$child = array(
	'source_path'        => 'website/notes/child.html',
	'parent_source_path' => $page['source_path'],
);
$assert( empty( Static_Site_Importer_Whole_Page_Handoff::documents( array( $page, $child ) ) ), 'A source parent is not transferred to a non-hierarchical provider.' );
echo "OK: canonical whole-page ownership and content proof\n";
