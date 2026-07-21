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
$GLOBALS['ssi_plan_options']    = array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'blogname' => 'Before' );
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
function switch_theme( string $slug ): void { $GLOBALS['ssi_plan_options']['stylesheet'] = $slug; }
function sanitize_text_field( string $value ): string { return $value; }
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

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'       => '<html><head><link rel="stylesheet" href="/assets/site.css"></head><body><header><p>Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main></body></html>',
		'about.html'       => '<main><h1>About</h1></main>',
		'assets/logo.svg'  => '<svg xmlns="http://www.w3.org/2000/svg"/>',
		'assets/site.css'  => 'main { background: url(assets/logo.svg); }',
	),
);
$result = ( new ArtifactCompiler() )->compile( $artifact )->toArray();
$plan   = $result['source_reports']['wordpress_site_plan'];
$assert( 'blocks-engine/wordpress-site-plan/v2' === $plan['schema'], 'compiler emits the released v2 site plan' );
$assert( isset( $result['source_reports']['wordpress_site_plan']['reporting'] ), 'compiler exposes the plan in source reports' );

$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
$assert( 'completed' === $receipt['status'], 'valid plan completes' );
$assert( 'static-site-importer/materialization-receipt/v1' === $receipt['schema'], 'receipt schema is stable' );
$assert( count( $plan['writes'] ) === count( $receipt['generated_files'] ), 'all canonical writes are materialized' );
$assert( file_exists( $GLOBALS['ssi_plan_root'] . '/site-plan/templates/front-page.html' ), 'templates are materialized' );
$assert( str_contains( file_get_contents( $GLOBALS['ssi_plan_root'] . '/site-plan/assets/assets/site.css' ), 'https://example.test/wp-content/themes/site-plan/assets/assets/logo.svg' ), 'root-relative stylesheet references resolve to declared theme assets' );
$assert( 'posts' === $GLOBALS['ssi_plan_options']['show_on_front'], 'plan-only materialization does not change reading settings by default' );
$assert( $receipt['plan']['pages'][0]['document_metadata']['links'][0]['resolved_url'] === 'https://example.test/wp-content/themes/site-plan/assets/assets/site.css', 'resolved metadata retains the declared stylesheet destination' );

$GLOBALS['ssi_plan_options'] = array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'blogname' => 'Before' );
$preview = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true ) );
$assert( 'completed' === $preview['status'], 'preview materialization completes' );
$assert( 'posts' === $GLOBALS['ssi_plan_options']['show_on_front'] && ! isset( $GLOBALS['ssi_plan_options']['stylesheet'] ), 'activate=false preserves runtime options' );
$activated = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true, 'activate' => true, 'site_title' => 'Activated Plan' ) );
$assert( 'site-plan' === $GLOBALS['ssi_plan_options']['stylesheet'] && 'page' === $GLOBALS['ssi_plan_options']['show_on_front'] && 'Activated Plan' === $GLOBALS['ssi_plan_options']['blogname'], 'activate=true applies theme title and reading policy' );

$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
$assert( 'completed' === $repeat['status'], 'reconciliation repeat completes' );
$assert( count( $GLOBALS['ssi_plan_posts'] ) === count( $plan['pages'] ), 'reconciliation preserves source page identity' );

$before_posts = count( $GLOBALS['ssi_plan_posts'] );
$before_files = count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() );
$invalid = $plan;
$invalid['schema'] = 'blocks-engine/wordpress-site-plan/v1';
$rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $invalid, array( 'slug' => 'reject' ) );
$assert( 'rejected' === $rejected['status'], 'invalid plan is rejected' );
$assert( $before_posts === count( $GLOBALS['ssi_plan_posts'] ), 'invalid plan creates no posts' );
$assert( $before_files === count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() ), 'invalid plan writes no files' );

$unsafe = $GLOBALS['ssi_plan_root'] . '/unsafe';
mkdir( $unsafe, 0777, true );
symlink( sys_get_temp_dir(), $unsafe . '/assets' );
$unsafe_result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'unsafe', 'overwrite' => true ) );
$assert( 'rejected' === $unsafe_result['status'], 'unsafe destination is rejected' );
$assert( 'unsafe_destination_path' === $unsafe_result['diagnostics'][0]['reason_code'], 'unsafe destination is diagnosed' );

$dynamic_artifact = $artifact;
$dynamic_artifact['files']['assets/site.js'] = 'window.sitePlan = true;';
$dynamic_plan = ( new ArtifactCompiler() )->compile( $dynamic_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$dynamic_rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $dynamic_plan, array( 'slug' => 'dynamic-plan' ) );
$assert( 'completed' === $dynamic_rejected['status'], 'v0.4.3 proves static local scripts for materialization' );

$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 1;
$partial = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'partial-plan' ) );
$assert( 'partial' === $partial['status'], 'runtime mutation failure returns partial receipt' );
$assert( 'simulated_post_failure' === $partial['diagnostics'][0]['reason_code'], 'partial receipt keeps mutation failure identity' );

echo "WordPress site plan materializer smoke passed.\n";
