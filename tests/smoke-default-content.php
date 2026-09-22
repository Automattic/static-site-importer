<?php
/**
 * Smoke test: untouched WordPress seed content is removed conservatively.
 *
 * Run: php tests/smoke-default-content.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_default_options']  = array( 'fresh_site' => '1', 'wp_page_for_privacy_policy' => 3 );
$GLOBALS['ssi_default_posts']    = array(
	1 => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'hello-world', 'post_title' => 'Hello world!', 'post_content' => 'Welcome', 'guid' => 'https://example.test/?p=1' ),
	2 => array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'sample-page', 'post_title' => 'Sample Page', 'post_content' => 'Example', 'guid' => 'https://example.test/?page_id=2' ),
	3 => array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'privacy-policy', 'post_title' => 'Privacy Policy', 'post_content' => 'Core privacy policy', 'guid' => 'https://example.test/?page_id=3' ),
);
$GLOBALS['ssi_default_comments'] = array(
	1 => array( 'comment_post_ID' => 1, 'comment_author' => 'A WordPress Commenter', 'comment_author_email' => 'wapuu@wordpress.example', 'comment_content' => 'Hi, this is a comment.' ),
);
$GLOBALS['ssi_default_meta']     = array( 3 => array( '_wp_page_template' => 'default' ) );

class WP_Post {
	public string $post_type;
	public string $post_status;
	public string $post_name;
	public string $post_title;
	public string $post_content;
	public string $guid;
	public function __construct( public int $ID, array $data ) {
		foreach ( $data as $key => $value ) { $this->{$key} = $value; }
	}
}
class WP_Comment {
	public int $comment_post_ID;
	public string $comment_author;
	public string $comment_author_email;
	public string $comment_content;
	public function __construct( public int $comment_ID, array $data ) {
		foreach ( $data as $key => $value ) { $this->{$key} = $value; }
	}
}
function get_option( string $key, $default = false ) { return $GLOBALS['ssi_default_options'][ $key ] ?? $default; }
function __( string $text ): string { return $text; }
function get_post( int $id ) { return isset( $GLOBALS['ssi_default_posts'][ $id ] ) ? new WP_Post( $id, $GLOBALS['ssi_default_posts'][ $id ] ) : null; }
function get_comment( int $id ) { return isset( $GLOBALS['ssi_default_comments'][ $id ] ) ? new WP_Comment( $id, $GLOBALS['ssi_default_comments'][ $id ] ) : null; }
function get_post_meta( int $id, string $key, bool $single ) { unset( $single ); return $GLOBALS['ssi_default_meta'][ $id ][ $key ] ?? ''; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_delete_comment( int $id, bool $force ) { unset( $force ); if ( ! isset( $GLOBALS['ssi_default_comments'][ $id ] ) ) { return false; } unset( $GLOBALS['ssi_default_comments'][ $id ] ); return true; }
function wp_delete_post( int $id, bool $force ) { unset( $force ); if ( ! isset( $GLOBALS['ssi_default_posts'][ $id ] ) ) { return false; } $post = $GLOBALS['ssi_default_posts'][ $id ]; unset( $GLOBALS['ssi_default_posts'][ $id ] ); return $post; }
class WP_Privacy_Policy_Content { public static function get_default_content(): string { return 'Core privacy policy'; } }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-default-content.php';

$discovery = Static_Site_Importer_Default_Content::discover();
$privacy = get_post( 3 );
if ( array( 1, 2, 3 ) !== array_column( $discovery['posts'], 'id' ) || ! Static_Site_Importer_Default_Content::is_untouched_seed( $discovery, $privacy ) ) {
	fwrite( STDERR, "FAIL: core privacy seed was not discovered as reusable\n" );
	exit( 1 );
}
$GLOBALS['ssi_default_posts'][3]['post_content'] = 'Edited policy';
if ( Static_Site_Importer_Default_Content::is_untouched_seed( $discovery, get_post( 3 ) ) ) {
	fwrite( STDERR, "FAIL: edited privacy seed was admitted\n" );
	exit( 1 );
}
$GLOBALS['ssi_default_posts'][3]['post_content'] = 'Core privacy policy';
$GLOBALS['ssi_default_posts'][3]['post_title'] = 'Edited Privacy Policy';
if ( in_array( 3, array_column( Static_Site_Importer_Default_Content::discover()['posts'], 'id' ), true ) ) {
	fwrite( STDERR, "FAIL: edited privacy title was discovered as core content\n" );
	exit( 1 );
}
$GLOBALS['ssi_default_posts'][3]['post_title'] = 'Privacy Policy';
$GLOBALS['ssi_default_posts'][3]['post_content'] = 'Destination-authored policy';
if ( in_array( 3, array_column( Static_Site_Importer_Default_Content::discover()['posts'], 'id' ), true ) ) {
	fwrite( STDERR, "FAIL: destination-authored privacy policy was discovered as core content\n" );
	exit( 1 );
}
$GLOBALS['ssi_default_posts'][3]['post_content'] = 'Core privacy policy';
$GLOBALS['ssi_default_posts'][2]['post_title'] = 'My Real Page';
$GLOBALS['ssi_default_posts'][3]['post_content'] = 'Imported privacy policy';
$report = Static_Site_Importer_Default_Content::remove( $discovery );
if ( array( 1 ) !== $report['removed']['posts'] || array( 1 ) !== $report['removed']['comments'] || ! isset( $GLOBALS['ssi_default_posts'][2], $GLOBALS['ssi_default_posts'][3] ) || 3 !== $GLOBALS['ssi_default_options']['wp_page_for_privacy_policy'] || 'record_changed' !== ( $report['skipped'][0]['reason'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: default content cleanup was not conservative\n" );
	exit( 1 );
}

$GLOBALS['ssi_default_options']['fresh_site'] = '0';
if ( ! empty( Static_Site_Importer_Default_Content::discover()['eligible'] ) ) {
	fwrite( STDERR, "FAIL: established sites must not be eligible\n" );
	exit( 1 );
}

echo "OK: default content smoke passed\n";
