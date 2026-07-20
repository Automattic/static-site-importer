<?php
/**
 * Contract coverage for the isolated WordPress site-plan materializer.
 *
 * Run from the repository root:
 * php tests/smoke-wordpress-site-plan-materializer.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$GLOBALS['ssi_plan_writes'] = 0;
$GLOBALS['ssi_plan_meta_writes'] = 0;
$GLOBALS['ssi_plan_directory_writes'] = 0;
$GLOBALS['ssi_plan_root'] = sys_get_temp_dir() . '/ssi-plan-materializer-' . uniqid();
mkdir( $GLOBALS['ssi_plan_root'], 0777, true );

class WP_Error {
	private string $code;
	public function __construct( string $code ) { $this->code = $code; }
	public function get_error_code(): string { return $this->code; }
}
class WP_Post { public int $ID; public function __construct( int $id ) { $this->ID = $id; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function get_theme_root(): string { return $GLOBALS['ssi_plan_root']; }
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_slash( string $value ): string { return addslashes( $value ); }
function get_page_by_path( string $slug, $output, string $type ) { return $GLOBALS['ssi_plan_posts'][ $type . ':' . $slug ] ?? null; }
function get_post_meta( int $id, string $key, bool $single ) { return $GLOBALS['ssi_plan_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( int $id, string $key, string $value ): void { $GLOBALS['ssi_plan_meta'][ $id ][ $key ] = $value; $GLOBALS['ssi_plan_meta_writes']++; }
function wp_insert_post( array $postarr, bool $wp_error ) {
	$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : count( $GLOBALS['ssi_plan_posts'] ) + 1;
	$GLOBALS['ssi_plan_posts'][ $postarr['post_type'] . ':' . $postarr['post_name'] ] = new WP_Post( $id );
	$GLOBALS['ssi_plan_writes']++;
	return $id;
}
function wp_mkdir_p( string $path ): bool { $GLOBALS['ssi_plan_directory_writes']++; return is_dir( $path ) || mkdir( $path, 0777, true ); }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';

$hash = static fn ( string $value ): string => hash( 'sha256', $value );
$plan = array(
	'schema' => 'blocks-engine/wordpress-site-plan/v1',
	'source' => array( 'schema' => 'blocks-engine/php-transformer/compiled-site/v1', 'source_hash' => $hash( 'source' ), 'entry_path' => 'index.html', 'provenance' => array( 'origin' => 'fixture' ) ),
	'pages' => array( array( 'source_path' => 'index.html', 'slug' => 'home', 'title' => 'Home', 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => true, 'final_block_markup' => '<!-- wp:paragraph --><p>Exact plan markup</p><!-- /wp:paragraph -->', 'metadata' => array(), 'provenance' => array( 'origin' => 'fixture' ), 'reconciliation_identity' => $hash( 'page' ) ) ),
	'templates' => array( array( 'source_path' => 'templates/landing.html', 'slug' => 'landing', 'title' => 'Landing', 'post_type' => 'wp_template', 'parent_source_path' => '', 'entrypoint' => false, 'final_block_markup' => '<!-- wp:post-content /-->', 'metadata' => array(), 'provenance' => array(), 'reconciliation_identity' => $hash( 'template' ) ) ),
	'template_parts' => array( array( 'source_path' => 'parts/header.html', 'slug' => 'header', 'title' => 'Header', 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => 'header', 'final_block_markup' => '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->', 'metadata' => array(), 'provenance' => array(), 'reconciliation_identity' => $hash( 'part' ) ) ),
	'assets' => array( array( 'source_path' => 'assets/site.css', 'target_path' => 'assets/site.css', 'mime_type' => 'text/css', 'hash' => $hash( 'body{color:#123}' ), 'load' => array( 'placement' => '', 'type' => '', 'defer' => false, 'async' => false ) ) ),
	'writes' => array( array( 'kind' => 'theme_asset', 'source_path' => 'assets/site.css', 'target_path' => 'assets/site.css', 'payload' => array( 'encoding' => 'utf8', 'data' => 'body{color:#123}' ), 'mime_type' => 'text/css', 'media' => '', 'hash' => $hash( 'body{color:#123}' ), 'load' => array( 'placement' => '', 'type' => '', 'defer' => false, 'async' => false ) ) ),
	'routes' => array(), 'navigation_links' => array(), 'menus' => array(), 'asset_rewrite_candidates' => array(), 'theme' => array(), 'visual_repair' => array(), 'diagnostics' => array(), 'quality' => array( 'status' => 'completed', 'metrics' => array(), 'fallbacks' => array() ),
);

$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'plan-theme' ) );
assert( 'applied' === $receipt['status'] );
assert( 1 === $receipt['wordpress']['posts'][0]['id'] );
assert( 'static-site-importer/materialization-receipt/v1' === $receipt['schema'] );
assert( '<!-- wp:paragraph --><p>Header</p><!-- /wp:paragraph -->' === file_get_contents( $GLOBALS['ssi_plan_root'] . '/plan-theme/parts/header.html' ) );
assert( 'body{color:#123}' === file_get_contents( $GLOBALS['ssi_plan_root'] . '/plan-theme/assets/site.css' ) );

foreach ( array(
	'unsupported_schema' => static function ( array $candidate ): array { $candidate['schema'] = 'blocks-engine/wordpress-site-plan/v0'; return $candidate; },
	'missing_markup' => static function ( array $candidate ): array { $candidate['pages'][0]['final_block_markup'] = ''; return $candidate; },
	'unsafe_path' => static function ( array $candidate ): array { $candidate['writes'][0]['target_path'] = '../escape.css'; return $candidate; },
	'bad_payload' => static function ( array $candidate ): array { $candidate['writes'][0]['payload']['encoding'] = 'rot13'; return $candidate; },
	'bad_hash' => static function ( array $candidate ): array { $candidate['writes'][0]['hash'] = str_repeat( '0', 64 ); return $candidate; },
	'bad_mime' => static function ( array $candidate ): array { $candidate['writes'][0]['mime_type'] = 'text/plain'; return $candidate; },
) as $case => $mutate ) {
	$before_posts = $GLOBALS['ssi_plan_writes'];
	$before_meta = $GLOBALS['ssi_plan_meta_writes'];
	$before_directories = $GLOBALS['ssi_plan_directory_writes'];
	$before_files = count( glob( $GLOBALS['ssi_plan_root'] . '/invalid-' . $case . '/**/*' ) ?: array() );
	$result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $mutate( $plan ), array( 'slug' => 'invalid-' . $case ) );
	assert( 'rejected' === $result['status'] );
	assert( $before_posts === $GLOBALS['ssi_plan_writes'] );
	assert( $before_meta === $GLOBALS['ssi_plan_meta_writes'] );
	assert( $before_directories === $GLOBALS['ssi_plan_directory_writes'] );
	assert( $before_files === count( glob( $GLOBALS['ssi_plan_root'] . '/invalid-' . $case . '/**/*' ) ?: array() ) );
}

echo "WordPress site plan materializer smoke passed.\n";
