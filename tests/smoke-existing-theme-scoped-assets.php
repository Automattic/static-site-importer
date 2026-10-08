<?php
/**
 * Companion asset publication surface for existing-theme destinations.
 *
 * Proves the published-home primitives in isolation: the publication resolves
 * under the companion plugin directory and never inside the active theme, the
 * scoped loader is deterministic, and it enqueues only for imported pages on
 * the frontend and in the editor.
 *
 * Run: php tests/smoke-existing-theme-scoped-assets.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_active_stylesheet']     = 'host-theme';
$GLOBALS['ssi_plugin_root']           = sys_get_temp_dir() . '/ssi-scoped-assets-plugin-root';
define( 'WP_PLUGIN_DIR', $GLOBALS['ssi_plugin_root'] );
define( 'WP_PLUGIN_URL', 'https://example.test/wp-content/plugins' );
mkdir( $GLOBALS['ssi_plugin_root'], 0777, true );
$GLOBALS['ssi_stylesheet_root'] = sys_get_temp_dir() . '/ssi-scoped-assets-theme-root';
mkdir( $GLOBALS['ssi_stylesheet_root'] . '/host-theme', 0777, true );

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); }
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/'; }
function wp_json_encode( $value, int $options = 0 ) {
	return ( false === ( $encoded = json_encode( $value, $options ) ) ? '' : $encoded ); }
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error; }
class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
}
function get_stylesheet(): string {
	return (string) $GLOBALS['ssi_active_stylesheet']; }
function get_stylesheet_directory(): string {
	return $GLOBALS['ssi_stylesheet_root'] . '/' . get_stylesheet(); }

$autoload        = require dirname( __DIR__ ) . '/vendor/autoload.php';
$producer_source = getenv( 'BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT' );
if ( is_string( $producer_source ) && is_file( $producer_source . '/src/WordPressSitePlan/WordPressSitePlan.php' ) ) {
	$autoload->setPsr4( 'Automattic\\BlocksEngine\\PhpTransformer\\', $producer_source . '/src/', true );
}
require dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-asset-publication.php';

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "ok   - {$message}\n";
		return;
	}
	++$failures;
	echo "FAIL - {$message}\n";
};

// The publication home resolves under the companion plugin directory.
$publication = Static_Site_Importer_Companion_Asset_Publication::resolve( array( 'slug' => 'imported-site' ) );
$assert(
	! is_wp_error( $publication ) && WP_PLUGIN_DIR . '/ssi-imported-site/assets' === $publication['dir'],
	'publication resolves under the companion plugin directory'
);
$assert(
	WP_PLUGIN_URL . '/ssi-imported-site/assets' === $publication['uri'],
	'publication resolves a companion plugin URI'
);
$assert(
	! str_starts_with( (string) $publication['dir'], trailingslashit( get_stylesheet_directory() ) ),
	'published assets never live inside the active theme directory'
);
$assert(
	'companion_plugin' === $publication['mode'] && 'ssi-imported-site' === $publication['plugin_slug'],
	'publication names the companion plugin mode and slug'
);
$repeat = Static_Site_Importer_Companion_Asset_Publication::resolve( array( 'slug' => 'imported-site' ) );
$assert( $repeat === $publication, 'publication resolution is deterministic' );
$unset_slug = Static_Site_Importer_Companion_Asset_Publication::resolve( array() );
$assert(
	is_wp_error( $unset_slug ) && 'static_site_importer_companion_asset_publication_slug_missing' === $unset_slug->get_error_code(),
	'publication without a site slug is refused rather than guessed'
);

// The scoped loader is deterministic and enqueues the configuration only.
// Each imported page replays its canonical document's stylesheet sequence.
$plan      = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html'      => '<!doctype html><html><head><link rel="stylesheet" href="page.css"></head><body><main><p class="home">Home</p></main></body></html>',
			'about.html'      => '<!doctype html><html><head><link rel="stylesheet" href="page.css" media="print"></head><body><main><p class="about">About</p></main></body></html>',
			'page.css'        => '.home,.about{color:orchid}',
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$page_target = array_column( $plan['assets'], 'target_path', 'source_path' )['page.css'];
$published   = array();
foreach ( $plan['assets'] as $asset ) {
	if ( 'css' === $asset['kind'] ) {
		$published[] = array( 'src' => $asset['target_path'], 'version' => hash( 'sha256', $asset['target_path'] ) );
	}
}
$published[] = array( 'src' => 'assets/css/overlay.css', 'version' => str_repeat( 'b', 64 ) );
$posts       = array( array( 'id' => 42, 'source_path' => 'index.html' ), array( 'id' => 42, 'source_path' => 'index.html' ), array( 'id' => 0, 'source_path' => 'about.html' ), array( 'id' => 7, 'source_path' => 'about.html' ), array( 'id' => 9, 'source_path' => 'unplanned.html' ) );
$config      = Static_Site_Importer_Companion_Asset_Publication::scoped_asset_config( $plan, $posts, $published, (string) $publication['uri'] );
$json        = Static_Site_Importer_Companion_Asset_Publication::scoped_assets_json( $config );
$decoded     = json_decode( $json, true );
$assert(
	is_array( $decoded ) && array( 42, 7 ) === array_keys( $decoded['posts'] ),
	'scoped configuration maps unique positive planned page identities'
);
$sources_for = static fn( int $id ): array => array_map( static fn( string $handle ): string => $decoded['stylesheets'][ $handle ]['src'], $decoded['posts'][ $id ] );
$assert(
	in_array( $page_target, $sources_for( 42 ), true ) && 'assets/css/overlay.css' === array_slice( $sources_for( 42 ), -1 )[0],
	'a page replays its planned stylesheets, then importer overlay stylesheets'
);
$about_rows = array_values( array_filter( $decoded['posts'][7], static fn( string $handle ): bool => $page_target === $decoded['stylesheets'][ $handle ]['src'] ) );
$home_rows  = array_values( array_filter( $decoded['posts'][42], static fn( string $handle ): bool => $page_target === $decoded['stylesheets'][ $handle ]['src'] ) );
$assert(
	1 === count( $about_rows ) && 1 === count( $home_rows ) && $about_rows !== $home_rows && 'print' === $decoded['stylesheets'][ $about_rows[0] ]['media'] && 'all' === $decoded['stylesheets'][ $home_rows[0] ]['media'],
	'each page occurrence of one stylesheet file keeps its own handle and media'
);
$assert(
	$config === Static_Site_Importer_Companion_Asset_Publication::scoped_asset_config( $plan, $posts, $published, (string) $publication['uri'] ),
	'style handles stay deterministic per page occurrence'
);
$loader_one = Static_Site_Importer_Companion_Asset_Publication::scoped_loader_source();
$loader_two = Static_Site_Importer_Companion_Asset_Publication::scoped_loader_source();
$assert( $loader_one === $loader_two && str_starts_with( $loader_one, '<?php' ) && (bool) str_contains( $loader_one, 'scoped-assets.json' ), 'loader source is deterministic and reads its published configuration' );

// The loader enqueues published styles only while an imported page renders —
// on the frontend and in the editor.
$loader_dir = WP_PLUGIN_DIR . '/ssi-imported-site/assets';
mkdir( $loader_dir, 0777, true );
file_put_contents( $loader_dir . '/scoped-assets.json', $json );
file_put_contents( $loader_dir . '/asset-loader.php', $loader_one );
$style_calls = array();
$hooks       = array();
$GLOBALS['ssi_test_current_post_id'] = 0;
$GLOBALS['ssi_test_style_hook']      = 'frontend';
function add_action( string $hook, $callback ): void {
	$GLOBALS['ssi_test_hooks'][ $hook ] = $callback; }
function wp_enqueue_style( string $handle, string $src, array $deps = array(), string $version = '', string $media = 'all' ): void {
	$GLOBALS['ssi_test_style_calls'][] = array( 'handle' => $handle, 'src' => $src, 'version' => $version, 'media' => $media, 'hook' => (string) ( $GLOBALS['ssi_test_style_hook'] ?? '' ) ); }
function get_the_ID(): int {
	return (int) ( $GLOBALS['ssi_test_current_post_id'] ?? 0 ); }
function get_current_screen(): ?object {
	return $GLOBALS['ssi_test_screen']; }
$GLOBALS['ssi_test_hooks']       = array();
$GLOBALS['ssi_test_style_calls'] = array();
$GLOBALS['ssi_test_screen']      = null;
include $loader_dir . '/asset-loader.php';
$frontend = $GLOBALS['ssi_test_hooks']['wp_enqueue_scripts'] ?? null;
$editor   = $GLOBALS['ssi_test_hooks']['enqueue_block_editor_assets'] ?? null;
$assert( is_callable( $frontend ) && is_callable( $editor ), 'loader registers both frontend and editor enqueue callbacks' );
$GLOBALS['ssi_test_current_post_id'] = 42;
$frontend();
$frontend_imported = $GLOBALS['ssi_test_style_calls'];
$assert(
	count( $decoded['posts'][42] ) === count( $frontend_imported ) && in_array( WP_PLUGIN_URL . '/ssi-imported-site/assets/' . $page_target, array_column( $frontend_imported, 'src' ), true ) && hash( 'sha256', $page_target ) === array_column( $frontend_imported, 'version', 'src' )[ WP_PLUGIN_URL . '/ssi-imported-site/assets/' . $page_target ],
	'imported page loads its published styles from the companion publication URI'
);
$GLOBALS['ssi_test_current_post_id'] = 999;
$frontend();
$assert(
	$frontend_imported === $GLOBALS['ssi_test_style_calls'],
	'unrelated pages load nothing from the companion publication surface'
);
$GLOBALS['ssi_test_style_calls'] = array();
$GLOBALS['ssi_test_style_hook']  = 'editor';
$GLOBALS['ssi_test_screen']      = (object) array( 'post' => (object) array( 'ID' => 42 ) );
$editor();
$editor_imported = $GLOBALS['ssi_test_style_calls'];
$GLOBALS['ssi_test_screen'] = (object) array( 'post' => (object) array( 'ID' => 999 ) );
$editor();
$assert(
	count( $decoded['posts'][42] ) === count( $editor_imported ) && $editor_imported === $GLOBALS['ssi_test_style_calls'],
	'imported pages keep their scoped styles inside the editor and unrelated posts keep none'
);

echo $failures > 0 ? "\n{$failures} failing assertion(s)\n" : "\nall assertions passed\n";
exit( $failures > 0 ? 1 : 0 );
