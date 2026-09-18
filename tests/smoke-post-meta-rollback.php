<?php
/**
 * Producer reconciliation metadata rollback coverage.
 *
 * @package StaticSiteImporter
 */

define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['ssi_rollback_posts'] = array(
	1 => array( 'ID' => 1, 'post_title' => 'Without producer identity' ),
	2 => array( 'ID' => 2, 'post_title' => 'With producer identity' ),
);
$ssi_rollback_provenance_1 = (string) json_encode(
	array(
		'document_title' => 'Services – Southern Multi Product ltd',
		'source_path'    => 'C:\\captures\\services.html',
	)
);
$ssi_rollback_provenance_2 = (string) json_encode(
	array(
		'document_title' => 'Projects – Southern Multi Product ltd',
		'source_path'    => 'C:\\captures\\projects.html',
	)
);
$GLOBALS['ssi_rollback_meta'] = array(
	1 => array(
		'_static_site_importer_provenance'              => $ssi_rollback_provenance_1,
		'_static_site_importer_reconciliation_identity' => 'importer-1',
	),
	2 => array(
		'_static_site_importer_provenance'              => $ssi_rollback_provenance_2,
		'_static_site_importer_reconciliation_identity' => 'importer-2',
		'_blocks_engine_reconciliation_identity'        => 'producer-before',
	),
);

function get_post( int $id, string $output ): ?array {
	unset( $output );
	return $GLOBALS['ssi_rollback_posts'][ $id ] ?? null;
}
function get_post_meta( int $id, string $key, bool $single ): string {
	unset( $single );
	return (string) ( $GLOBALS['ssi_rollback_meta'][ $id ][ $key ] ?? '' );
}
function metadata_exists( string $meta_type, int $id, string $key ): bool {
	return 'post' === $meta_type && array_key_exists( $key, $GLOBALS['ssi_rollback_meta'][ $id ] ?? array() );
}
function wp_update_post( array $post ): int {
	$GLOBALS['ssi_rollback_posts'][ $post['ID'] ] = $post;
	return (int) $post['ID'];
}
function wp_slash( string $value ): string {
	return addslashes( $value );
}
function update_post_meta( int $id, string $key, string $value ): void {
	$GLOBALS['ssi_rollback_meta'][ $id ][ $key ] = stripslashes( $value );
}
function delete_post_meta( int $id, string $key ): void {
	unset( $GLOBALS['ssi_rollback_meta'][ $id ][ $key ] );
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$receipt = array( 'transaction' => (object) array( 'state' => array( 'rollback' => array( 'posts' => array() ) ) ) );
Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_post( $receipt, 1 );
Static_Site_Importer_WordPress_Site_Plan_Materializer::journal_receipt_post( $receipt, 2 );

$state = $receipt['transaction']->state;
if ( true === $state['rollback']['posts'][1]['producer_reconciliation_identity_exists'] || true !== $state['rollback']['posts'][2]['producer_reconciliation_identity_exists'] ) {
	throw new RuntimeException( 'producer metadata existence was not journaled' );
}

$GLOBALS['ssi_rollback_meta'][1]['_blocks_engine_reconciliation_identity'] = 'producer-new-1';
$GLOBALS['ssi_rollback_meta'][2]['_blocks_engine_reconciliation_identity'] = 'producer-new-2';
$state['applied']['posts'] = array( array( 'id' => 1 ), array( 'id' => 2 ) );
$rollback = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'rollback' );
$rollback->invokeArgs( null, array( &$state ) );

if ( array_key_exists( '_blocks_engine_reconciliation_identity', $GLOBALS['ssi_rollback_meta'][1] ) ) {
	throw new RuntimeException( 'rollback created producer metadata that did not previously exist' );
}
if ( 'producer-before' !== $GLOBALS['ssi_rollback_meta'][2]['_blocks_engine_reconciliation_identity'] ) {
	throw new RuntimeException( 'rollback did not restore existing producer metadata' );
}
$restored_provenance_1 = (string) $GLOBALS['ssi_rollback_meta'][1]['_static_site_importer_provenance'];
$restored_provenance_2 = (string) $GLOBALS['ssi_rollback_meta'][2]['_static_site_importer_provenance'];
if ( ! str_contains( $ssi_rollback_provenance_1, '\u2013' ) || ! str_contains( $ssi_rollback_provenance_1, '\\\\captures\\\\services.html' ) ) {
	throw new RuntimeException( 'rollback fixture did not contain escaped Unicode and backslashes' );
}
if ( $ssi_rollback_provenance_1 !== $restored_provenance_1 || $ssi_rollback_provenance_2 !== $restored_provenance_2 ) {
	throw new RuntimeException( 'rollback did not restore provenance JSON exactly through metadata unslashing' );
}
if ( 'Services – Southern Multi Product ltd' !== ( json_decode( $restored_provenance_1, true )['document_title'] ?? '' ) || 'C:\\captures\\services.html' !== ( json_decode( $restored_provenance_1, true )['source_path'] ?? '' ) ) {
	throw new RuntimeException( 'restored provenance JSON did not retain escaped Unicode and backslashes' );
}

echo "Post metadata rollback smoke passed.\n";
