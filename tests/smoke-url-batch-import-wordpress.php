<?php
/**
 * WordPress-path batch import integration smoke.
 *
 * Run: wp eval-file tests/smoke-url-batch-import-wordpress.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$root = dirname( __DIR__ );
require_once $root . '/static-site-importer.php';
$token = sanitize_key( wp_generate_password( 10, false, false ) );
$slug = 'ssi-batch-wp-' . $token;
$host = 'batch-' . $token . '.test';
$prefix = 'ssi-batch-' . $token;
$origin = 'https://' . $host;
$work_dir = wp_upload_dir()['basedir'] . '/ssi-batch-wp-' . wp_generate_uuid4();
$responses = array(
	$origin . '/sitemap.xml' => array( 'application/xml', '<urlset><url><loc>' . $origin . '/' . $prefix . '/</loc></url><url><loc>' . $origin . '/' . $prefix . '/about/</loc></url><url><loc>' . $origin . '/' . $prefix . '/about/team/</loc></url></urlset>' ),
	$origin . '/' . $prefix . '/' => array( 'text/html', '<html><head><link rel="stylesheet" href="/' . $prefix . '/first.css"></head><body><main>Home</main></body></html>' ),
	$origin . '/' . $prefix . '/about/' => array( 'text/html', '<html><body><main>About</main></body></html>' ),
	$origin . '/' . $prefix . '/about/team/' => array( 'text/html', '<html><head><link rel="stylesheet" href="/' . $prefix . '/second.css"></head><body><main>Team</main></body></html>' ),
	$origin . '/' . $prefix . '/first.css' => array( 'text/css', '.first{color:red}' ), $origin . '/' . $prefix . '/second.css' => array( 'text/css', '.second{color:blue}' ),
);
$fetcher = static function ( string $url, array $args ) use ( $responses ) { return isset( $responses[ $url ] ) ? array( 'body' => $responses[ $url ][1], 'metadata' => array( 'content_type' => $responses[ $url ][0], 'final_url' => $url ) ) : new WP_Error( 'fixture_missing', $url ); };
$request = array( 'url' => $origin . '/' . $prefix . '/', 'work_dir' => $work_dir, 'provider_args' => array( 'collect_site' => true, 'batch_pages' => 2, 'request_delay_ms' => 0, 'max_assets' => 10 ) );
$input = array( 'slug' => $slug, 'name' => 'SSI Batch WP', 'activate' => true, 'overwrite' => false );
$before = get_stylesheet(); $calls = 0;
$first = Static_Site_Importer_URL_Batch_Import::import( $request, $input, $fetcher, static function ( array $artifact, array $args ) use ( &$calls ) { if ( 1 === $calls++ ) { return new WP_Error( 'injected_batch_failure', 'resume test' ); } return Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args ); } );
$run_id = is_wp_error( $first ) ? (string) ( $first->get_error_data()['run']['source']['identity'] ?? '' ) : '';
$first_about = get_page_by_path( $prefix . '/about', OBJECT, 'page' );
if ( ! is_wp_error( $first ) || '' === $run_id || $before !== get_stylesheet() || ! $first_about || $run_id !== (string) ( json_decode( (string) get_post_meta( $first_about->ID, '_static_site_importer_provenance', true ), true )['import_run_id'] ?? '' ) ) { throw new RuntimeException( 'first batch must materialize this invocation routes and provenance without activating after injected failure' ); }
$resumed = Static_Site_Importer_URL_Batch_Import::import( $request, $input, $fetcher, static fn( array $artifact, array $args ) => Static_Site_Importer_Theme_Generator::import_website_artifact( $artifact, $args ) );
$active_slug = sanitize_key( $slug );
$theme_dir = get_theme_root() . '/' . $active_slug;
$about = get_page_by_path( $prefix . '/about', OBJECT, 'page' ); $team = get_page_by_path( $prefix . '/about/team', OBJECT, 'page' );
$bootstrap = (string) file_get_contents( $theme_dir . '/functions.php' ) . implode( '', array_map( static fn( string $path ): string => (string) file_get_contents( $path ), glob( $theme_dir . '/static-site-importer-batch-bootstrap/*.php' ) ?: array() ) );
if ( is_wp_error( $resumed ) || get_stylesheet() !== $active_slug || ! $about || ! $team || $run_id !== (string) ( json_decode( (string) get_post_meta( $about->ID, '_static_site_importer_provenance', true ), true )['import_run_id'] ?? '' ) || $run_id !== (string) ( json_decode( (string) get_post_meta( $team->ID, '_static_site_importer_provenance', true ), true )['import_run_id'] ?? '' ) || (int) $team->post_parent !== (int) $about->ID || ! is_file( $theme_dir . '/functions.php' ) || ! str_contains( (string) file_get_contents( $theme_dir . '/assets/website/' . $prefix . '/first.css' ), '.first' ) || ! str_contains( (string) file_get_contents( $theme_dir . '/assets/website/' . $prefix . '/second.css' ), '.second' ) || ! str_contains( $bootstrap, 'assets/website/' . $prefix . '/first.css' ) || ! str_contains( $bootstrap, 'assets/website/' . $prefix . '/second.css' ) || 'completed' !== ( $resumed['url_batch_run']['status'] ?? '' ) ) { throw new RuntimeException( 'resumed batches must retain this invocation active bootstrap CSS behavior, assets, nested parents, terminal activation, and aggregate evidence' ); }
echo "WordPress URL batch import smoke passed.\n";
