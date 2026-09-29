<?php
/** Neutral provider-owned source-page handoff contract smoke. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Post { public function __construct( public int $ID, public string $post_status, public string $post_content, public string $post_type = 'note' ) {} }
$posts = array( 101 => new WP_Post( 101, 'publish', '<!-- wp:paragraph --><p>one</p><!-- /wp:paragraph -->' ) );
function get_post( $id ) { global $posts; return $posts[ (int) $id ] ?? null; }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-whole-page-handoff.php';
$page = array( 'source_path' => 'website/notes/one.html', 'route' => array( 'path' => '/notes/one' ), 'reconciliation_identity' => 'page-one', 'resolved_block_markup' => '<!-- wp:paragraph --><p>one</p><!-- /wp:paragraph -->', 'whole_page_candidates' => array( array( 'source_path' => 'website/notes/one.html', 'route_path' => '/notes/one', 'page_reconciliation_identity' => 'page-one', 'declaration_id' => 'tec', 'row_identity' => 'one' ) ) );
$result = array( 'status' => 'committed', 'source_path' => 'website/notes/one.html', 'route_path' => '/notes/one', 'page_reconciliation_identity' => 'page-one', 'declaration_id' => 'tec', 'row_identity' => 'one', 'destination_post_id' => 101, 'post_type' => 'note', 'images_preserved' => true, 'content_sha256' => hash( 'sha256', $posts[101]->post_content ) );
$page_two = array( 'source_path' => 'website/notes/two.html', 'route' => array( 'path' => '/notes/two' ), 'reconciliation_identity' => 'page-two', 'resolved_block_markup' => '<!-- wp:paragraph --><p>two</p><!-- /wp:paragraph -->' );
$valid = Static_Site_Importer_Whole_Page_Handoff::validate( array( $page, $page_two ), array( $result ) );
if ( 1 !== count( $valid['claims'] ) ) { fwrite( STDERR, "valid claim was not accepted\n" ); exit( 1 ); }
$stale = $result; $stale['route_path'] = '/notes/old-one';
$invalid = Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array( $stale ) );
if ( 0 !== count( $invalid['claims'] ) ) { fwrite( STDERR, "stale claim was accepted\n" ); exit( 1 ); }
$ambiguous = Static_Site_Importer_Whole_Page_Handoff::validate( array( $page, $page ), array( $result ) );
if ( 0 !== count( $ambiguous['claims'] ) ) { fwrite( STDERR, "ambiguous candidate was accepted\n" ); exit( 1 ); }
$conflicting = Static_Site_Importer_Whole_Page_Handoff::validate( array( $page ), array( $result, $result ) );
if ( 0 !== count( $conflicting['claims'] ) ) { fwrite( STDERR, "conflicting provider claims were accepted\n" ); exit( 1 ); }
echo "whole-page handoff smoke passed\n";
