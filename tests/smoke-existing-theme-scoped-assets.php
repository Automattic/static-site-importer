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
$config    = Static_Site_Importer_Companion_Asset_Publication::scoped_asset_config(
	array(
		array( 'src' => 'assets/assets/page.css', 'version' => str_repeat( 'a', 64 ) ),
		array( 'src' => 'assets/assets/deep/font.css', 'version' => str_repeat( 'b', 64 ) ),
	),
	array( 42, 42, 0, 7 ),
	(string) $publication['uri']
);
$json      = Static_Site_Importer_Companion_Asset_Publication::scoped_assets_json( $config );
$decoded   = json_decode( $json, true );
$assert(
	is_array( $decoded ) && 2 === count( $decoded['stylesheets'] ) && array( 42, 7 ) === $decoded['post_ids'],
	'scoped configuration resolves unique positive page identities'
);
$assert(
	$decoded['stylesheets'][0]['handle'] === ( Static_Site_Importer_Companion_Asset_Publication::scoped_asset_config( array( array( 'src' => 'assets/assets/page.css', 'version' => str_repeat( 'a', 64 ) ) ), array( 42 ), (string) $publication['uri'] )['stylesheets'][0]['handle'] ?? '' ) && 'assets/assets/page.css' === $decoded['stylesheets'][0]['src'],
	'style handles stay deterministic per stylesheet src'
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
function wp_enqueue_style( string $handle, string $src, array $deps = array(), string $version = '' ): void {
	$GLOBALS['ssi_test_style_calls'][] = array( 'handle' => $handle, 'src' => $src, 'version' => $version, 'hook' => (string) ( $GLOBALS['ssi_test_style_hook'] ?? '' ) ); }
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
	2 === count( $frontend_imported ) && WP_PLUGIN_URL . '/ssi-imported-site/assets/assets/assets/page.css' === $frontend_imported[0]['src'] && str_repeat( 'a', 64 ) === $frontend_imported[0]['version'],
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
	2 === count( $editor_imported ) && $editor_imported === $GLOBALS['ssi_test_style_calls'],
	'imported pages keep their scoped styles inside the editor and unrelated posts keep none'
);

echo $failures > 0 ? "\n{$failures} failing assertion(s)\n" : "\nall assertions passed\n";
exit( $failures > 0 ? 1 : 0 );
