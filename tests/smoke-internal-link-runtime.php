<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function get_permalink( int $id ): string {
	return $GLOBALS['ssi_link_permalinks'][ $id ] ?? '';
}
require dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$GLOBALS['ssi_link_permalinks'] = array(
	5 => 'https://destination.test/blog/',
	6 => 'https://destination.test/2026/01/news/',
);

$stored = '<a href="/?page_id=5">Blog</a><a href="/?p=6&ref=nav#top">News</a>';
$assert( '<a href="https://destination.test/blog/">Blog</a><a href="https://destination.test/2026/01/news/?ref=nav#top">News</a>' === Static_Site_Importer_Internal_Link_Runtime::resolve_urls( $stored ), 'Portable post-id references resolve to the current site permalink structure.' );

$absolute = 'data-pin-url=\\u0022https://sandbox.test/?p=6\\u0022';
$assert( 'data-pin-url=\\u0022https://destination.test/2026/01/news/\\u0022' === Static_Site_Importer_Internal_Link_Runtime::resolve_urls( $absolute ), 'Absolute query permalinks from a build host still resolve on the destination.' );

$result = Static_Site_Importer_Internal_Link_Runtime::prepare_overlay(
	array( 'writes' => array() ),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => "<?php\n// Existing bootstrap.\n" ) ) ),
	'89-hearth-bistro'
);
$bootstrap = (string) ( $result['writes'][0]['content'] ?? '' );
$runtime   = (string) ( $result['writes'][1]['content'] ?? '' );
$assert( 'materialized' === ( $result['status'] ?? '' ), 'Portable internal links should always materialize into the generated theme.' );
$assert( str_contains( $bootstrap, '// Existing bootstrap.' ) && str_contains( $bootstrap, 'Static Site Importer portable internal links' ), 'The overlay should keep prior bootstrap code and add the resolver marker.' );
$assert( str_contains( $bootstrap, "require_once get_stylesheet_directory() . '/portable-internal-links.php'" ) && str_contains( $bootstrap, 'SSI_Theme_89_HEARTH_BISTRO_Internal_Link_Runtime::register()' ), 'The portable theme bootstrap must load and register the resolver after SSI is gone.' );
$assert( str_contains( $runtime, 'final class SSI_Theme_89_HEARTH_BISTRO_Internal_Link_Runtime' ) && ! str_contains( $runtime, 'Static_Site_Importer_Internal_Link_Runtime' ), 'The generated theme owns a theme-scoped copy of the resolver.' );

// The theme copy and the plugin class coexist in either load order (#1820).
foreach ( array( 'theme-first', 'plugin-first' ) as $order ) {
	$dir = sys_get_temp_dir() . '/ssi-1820-' . $order . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir );
	file_put_contents( $dir . '/portable-internal-links.php', $runtime );
	file_put_contents( $dir . '/functions.php', $bootstrap );
	$plugin = dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php';
	$stub   = '<?php define( "ABSPATH", "/" ); function add_filter() {} function get_stylesheet_directory() { return ' . var_export( $dir, true ) . '; } ';
	$load   = 'theme-first' === $order
		? 'require ' . var_export( $dir . '/functions.php', true ) . '; require ' . var_export( $plugin, true ) . ';'
		: 'require ' . var_export( $plugin, true ) . '; require ' . var_export( $dir . '/functions.php', true ) . ';';
	file_put_contents( $dir . '/run.php', $stub . $load . ' echo class_exists( "Static_Site_Importer_Internal_Link_Runtime", false ) && class_exists( "SSI_Theme_89_HEARTH_BISTRO_Internal_Link_Runtime", false ) ? "ok" : "missing";' );
	$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/run.php' ) . ' 2>&1' );
	$assert( 'ok' === trim( $output ), 'Generated theme runtime and plugin runtime coexist when loaded ' . $order . '.', $output );
}

// A theme generated before scoping ships the plugin's class name; loading the
// plugin afterwards reuses that copy instead of fataling on redeclaration.
$legacy_dir = sys_get_temp_dir() . '/ssi-1820-legacy-' . bin2hex( random_bytes( 4 ) );
mkdir( $legacy_dir );
file_put_contents( $legacy_dir . '/legacy.php', (string) preg_replace( '/^.*?final class/s', "<?php\nfinal class", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php' ) ) );
file_put_contents( $legacy_dir . '/run.php', '<?php define( "ABSPATH", "/" ); require ' . var_export( $legacy_dir . '/legacy.php', true ) . '; require ' . var_export( dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php', true ) . '; echo "ok";' );
$legacy_output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $legacy_dir . '/run.php' ) . ' 2>&1' );
$assert( 'ok' === trim( $legacy_output ), 'Loading the plugin after a legacy generated theme copy does not redeclare the class.', $legacy_output );

$repeat = Static_Site_Importer_Internal_Link_Runtime::prepare_overlay(
	array(),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => $bootstrap ) ) )
);
$assert( 1 === substr_count( (string) ( $repeat['writes'][0]['content'] ?? '' ), 'Static Site Importer portable internal links' ), 'The overlay should be idempotent.' );

echo "internal link runtime smoke passed\n";
