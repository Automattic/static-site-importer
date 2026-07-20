<?php
/**
 * Isolated v2 plan materialization contract coverage.
 *
 * Run: php tests/smoke-wordpress-site-plan-materializer.php
 *
 * @package StaticSiteImporter
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

define( 'OBJECT', 'OBJECT' );
$GLOBALS['ssi_plan_root']       = sys_get_temp_dir() . '/ssi-plan-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 0;
mkdir( $GLOBALS['ssi_plan_root'], 0777, true );

class WP_Error {
	private string $code;
	public function __construct( string $code ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}
class WP_Post {
	public int $ID;
	public function __construct( int $id ) { $this->ID = $id; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function get_theme_root(): string { return $GLOBALS['ssi_plan_root']; }
function get_theme_root_uri(): string { return 'https://example.test/wp-content/themes'; }
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_slash( string $value ): string { return addslashes( $value ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function update_option( string $key, $value ): void { $GLOBALS['ssi_plan_options'][ $key ] = $value; }
function update_post_meta( int $id, string $key, string $value ): void { $GLOBALS['ssi_plan_meta'][ $id ][ $key ] = $value; }
function get_posts( array $args ): array {
	foreach ( $GLOBALS['ssi_plan_meta'] as $id => $meta ) {
		if ( ( $meta[ $args['meta_key'] ] ?? null ) === $args['meta_value'] ) { return array( new WP_Post( $id ) ); }
	}
	return array();
}
function get_page_by_path( string $slug, $output, string $type ) {
	foreach ( $GLOBALS['ssi_plan_posts'] as $id => $post ) { if ( $post['post_name'] === $slug ) { return new WP_Post( $id ); } }
	return null;
}
function wp_insert_post( array $post, bool $wp_error ) {
	if ( $GLOBALS['ssi_plan_fail_after'] && count( $GLOBALS['ssi_plan_posts'] ) >= $GLOBALS['ssi_plan_fail_after'] ) { return new WP_Error( 'simulated_post_failure' ); }
	$id = ! empty( $post['ID'] ) ? (int) $post['ID'] : count( $GLOBALS['ssi_plan_posts'] ) + 1;
	$GLOBALS['ssi_plan_posts'][ $id ] = $post;
	return $id;
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'       => '<header><p>Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main>',
		'about.html'       => '<main><h1>About</h1></main>',
		'assets/logo.svg'  => '<svg xmlns="http://www.w3.org/2000/svg"/>',
		'assets/site.css'  => 'main { background: url(assets/logo.svg); }',
	),
);
$result = ( new ArtifactCompiler() )->compile( $artifact )->toArray();
$plan   = $result['source_reports']['wordpress_site_plan'];
assert( 'blocks-engine/wordpress-site-plan/v2' === $plan['schema'] );

$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
assert( 'complete' === $receipt['status'] );
assert( 'static-site-importer/materialization-receipt/v1' === $receipt['schema'] );
assert( count( $plan['writes'] ) === count( $receipt['generated_files'] ) );
assert( file_exists( $GLOBALS['ssi_plan_root'] . '/site-plan/templates/front-page.html' ) );
assert( str_contains( file_get_contents( $GLOBALS['ssi_plan_root'] . '/site-plan/assets/assets/site.css' ), 'https://example.test/wp-content/themes/site-plan/assets/assets/logo.svg' ) );
assert( 'page' === $GLOBALS['ssi_plan_options']['show_on_front'] );

$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
assert( 'complete' === $repeat['status'] );
assert( count( $GLOBALS['ssi_plan_posts'] ) === count( $plan['pages'] ) );

$before_posts = count( $GLOBALS['ssi_plan_posts'] );
$before_files = count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() );
$invalid = $plan;
$invalid['schema'] = 'blocks-engine/wordpress-site-plan/v1';
$rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $invalid, array( 'slug' => 'reject' ) );
assert( 'rejected' === $rejected['status'] );
assert( $before_posts === count( $GLOBALS['ssi_plan_posts'] ) );
assert( $before_files === count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() ) );

$unsafe = $GLOBALS['ssi_plan_root'] . '/unsafe';
mkdir( $unsafe, 0777, true );
symlink( sys_get_temp_dir(), $unsafe . '/assets' );
$unsafe_result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'unsafe', 'overwrite' => true ) );
assert( 'rejected' === $unsafe_result['status'] );
assert( 'unsafe_destination_path' === $unsafe_result['diagnostics'][0]['reason_code'] );

$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 1;
$partial = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'partial-plan' ) );
assert( 'partial' === $partial['status'] );
assert( 'simulated_post_failure' === $partial['diagnostics'][0]['reason_code'] );

echo "WordPress site plan materializer smoke passed.\n";
