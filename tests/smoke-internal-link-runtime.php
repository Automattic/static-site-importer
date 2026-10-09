<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function get_permalink( int $id ): string {
	return $GLOBALS['ssi_link_permalinks'][ $id ] ?? '';
}
function home_url( string $path = '' ): string {
	return rtrim( $GLOBALS['ssi_link_home'], '/' ) . '/' . ltrim( $path, '/' );
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function get_page_by_path( string $path ) {
	$id = $GLOBALS['ssi_link_pages'][ $path ] ?? 0;
	return $id ? (object) array( 'ID' => $id ) : null;
}
function wp_login_url( string $redirect = '' ): string {
	return 'https://playground.test/scope:abc/wp-login.php' . ( '' !== $redirect ? '?redirect_to=' . urlencode( $redirect ) : '' );
}
function esc_url( string $url ): string {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function is_ssl(): bool {
	return true;
}
$GLOBALS['ssi_scripts'] = array();
function wp_register_script( string $handle, $src, array $deps = array(), $ver = false, $in_footer = false ): bool {
	$GLOBALS['ssi_scripts'][ $handle ] = array( 'enqueued' => false, 'inline' => '' );
	return true;
}
function wp_enqueue_script( string $handle ): void {
	$GLOBALS['ssi_scripts'][ $handle ]['enqueued'] = true;
}
function wp_add_inline_script( string $handle, string $data ): bool {
	$GLOBALS['ssi_scripts'][ $handle ]['inline'] .= $data;
	return true;
}
$GLOBALS['ssi_link_home']  = 'https://playground.test/scope:abc/';
$GLOBALS['ssi_link_pages'] = array( 'how-it-works' => 5 );
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

// Template parts keep root-relative source routes; rendered blocks resolve them
// for the site's actual home, which may be a Playground scope or subdirectory.
$nav = '<a href="/">Home</a><a href="/how-it-works">How</a><a href="/how-it-works#steps">Steps</a><a href="/files/guide.pdf">Guide</a><a href="//cdn.test/x">CDN</a><a href="https://other.test/">Other</a>';
$assert(
	'<a href="https://playground.test/scope:abc/">Home</a><a href="https://destination.test/blog/">How</a><a href="https://destination.test/blog/#steps">Steps</a><a href="https://playground.test/scope:abc/files/guide.pdf">Guide</a><a href="//cdn.test/x">CDN</a><a href="https://other.test/">Other</a>' === Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $nav ),
	'Rendered root-relative routes resolve to page permalinks, other root-relative links rebase onto the home path, and external links stay.'
);
$assert( '<p>no links</p>' === Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( '<p>no links</p>' ), 'Blocks without root-relative links are untouched.' );

// Data Liberation marks member sign-in controls (`data-dla-member-login`)
// without choosing a target; WordPress points them at its own login and
// brings the reader back to the page they signed in from.
$_SERVER['HTTP_HOST']   = 'playground.test';
$_SERVER['REQUEST_URI'] = '/scope:abc/bylaws/';
$login                  = 'https://playground.test/scope:abc/wp-login.php?redirect_to=https%3A%2F%2Fplayground.test%2Fscope%3Aabc%2Fbylaws%2F';
$header                 = '<div class="wixui-login-social-bar"><a class="O4eQsz" data-testid="handle-button" data-dla-member-login="wix"><span>Sign In</span></a></div>';
$assert(
	'<div class="wixui-login-social-bar"><a href="' . esc_url( $login ) . '" class="O4eQsz" data-testid="handle-button" data-dla-member-login="wix"><span>Sign In</span></a></div>' === Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $header ),
	'A marked sign-in control without a target links to the WordPress login.'
);
$hero = '<a data-testid="linkElement" href="https://www.source.test/account/my-account" class="wixui-button" data-dla-member-login="wix">Sign In</a> <a href="https://www.source.test/account/other">Unmarked</a>';
$assert(
	'<a href="' . esc_url( $login ) . '" data-testid="linkElement" class="wixui-button" data-dla-member-login="wix">Sign In</a> <a href="https://www.source.test/account/other">Unmarked</a>' === Static_Site_Importer_Internal_Link_Runtime::filter_content( $hero ),
	'A marked link into the source members area is replaced by the WordPress login in post content; unmarked links stay.'
);
$assert( str_contains( Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $hero ), 'href="' . esc_url( $login ) . '" data-testid="linkElement"' ), 'Rendered blocks resolve marked links too.' );

// Data Liberation's durable marker is a class: Blocks Engine keeps it on the
// core/button wrapper (dropping data attributes), so the Wix login-bar button
// arrives as a styled core/button. Its inner button becomes a login link with
// the same classes, icon and label.
$core_button = '<div class="wp-block-button O4eQsz dla-member-login-wix blocks-engine-control-x-48"><button type="button" class="wp-block-button__link wp-element-button"><img src="avatar.svg" alt="" class="be-inline-geometry-1"><mark class="HuH6Ex">Sign In</mark></button></div>';
$assert(
	'<div class="wp-block-button O4eQsz dla-member-login-wix blocks-engine-control-x-48"><a href="' . esc_url( $login ) . '" class="wp-block-button__link wp-element-button"><img src="avatar.svg" alt="" class="be-inline-geometry-1"><mark class="HuH6Ex">Sign In</mark></a></div>' === Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $core_button ),
	'The button inside a marked core/button becomes a link to the WordPress login, keeping its classes and children.'
);
$bare_button = '<button type="button" class="O4eQsz dla-member-login-wix"><span>Sign In</span></button><button type="button">Menu</button>';
$assert(
	'<a href="' . esc_url( $login ) . '" class="O4eQsz dla-member-login-wix"><span>Sign In</span></a><button type="button">Menu</button>' === Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $bare_button ),
	'A marked button becomes a login link; other buttons stay buttons.'
);
$class_link = '<a href="https://www.source.test/account/my-account" class="twJknM wixui-button dla-member-login-wix">Sign In</a>';
$assert(
	'<a href="' . esc_url( $login ) . '" class="twJknM wixui-button dla-member-login-wix">Sign In</a>' === Static_Site_Importer_Internal_Link_Runtime::filter_content( $class_link ),
	'A link marked only by class is pointed at the WordPress login.'
);
$plain = '<div class="wp-block-button"><button type="button" class="wp-block-button__link">Go</button></div>';
$assert( $plain === Static_Site_Importer_Internal_Link_Runtime::filter_rendered_block( $plain ), 'Unmarked buttons are untouched.' );

$absolute = 'data-pin-url=\\u0022https://sandbox.test/?p=6\\u0022';
$assert( 'data-pin-url=\\u0022https://destination.test/2026/01/news/\\u0022' === Static_Site_Importer_Internal_Link_Runtime::resolve_urls( $absolute ), 'Absolute query permalinks from a build host still resolve on the destination.' );

$result        = Static_Site_Importer_Internal_Link_Runtime::prepare_overlay(
	array( 'writes' => array() ),
	array(
'writes' => array(
array(
'target_path' => 'functions.php',
'content' => "<?php\n// Existing bootstrap.\n"
) ) ),
	'89-hearth-bistro'
);
$bootstrap     = (string) ( $result['writes'][0]['content'] ?? '' );
$runtime       = (string) ( $result['writes'][1]['content'] ?? '' );
$route_runtime = (string) ( $result['writes'][2]['content'] ?? '' );
$assert( 'materialized' === ( $result['status'] ?? '' ), 'Portable internal links should always materialize into the generated theme.' );
$assert( str_contains( $bootstrap, '// Existing bootstrap.' ) && str_contains( $bootstrap, 'Static Site Importer portable internal links' ), 'The overlay should keep prior bootstrap code and add the resolver marker.' );
$assert( str_contains( $bootstrap, "require_once get_stylesheet_directory() . '/portable-internal-links.php'" ) && str_contains( $bootstrap, 'SSI_Theme_89_HEARTH_BISTRO_Internal_Link_Runtime::register()' ), 'The portable theme bootstrap must load and register the resolver after SSI is gone.' );
$assert( str_contains( $runtime, 'final class SSI_Theme_89_HEARTH_BISTRO_Internal_Link_Runtime' ) && ! str_contains( $runtime, 'Static_Site_Importer_Internal_Link_Runtime' ), 'The generated theme owns a theme-scoped copy of the resolver.' );
$assert( str_contains( $route_runtime, 'final class SSI_Theme_89_HEARTH_BISTRO_Source_Route_Redirect' ) && str_contains( $bootstrap, "'/portable-source-routes.php'" ), 'Core-only themes deliver the canonical source route resolver without an importer or companion plugin.' );

// The theme copy and the plugin class coexist in either load order (#1820).
foreach ( array( 'theme-first', 'plugin-first' ) as $order ) {
	$dir = sys_get_temp_dir() . '/ssi-1820-' . $order . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir );
	file_put_contents( $dir . '/portable-internal-links.php', $runtime );
	file_put_contents( $dir . '/portable-source-routes.php', (string) ( $result['writes'][2]['content'] ?? '' ) );
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
	array(
'writes' => array(
array(
'target_path' => 'functions.php',
'content' => $bootstrap
) ) )
);
$assert( 1 === substr_count( (string) ( $repeat['writes'][0]['content'] ?? '' ), 'Static Site Importer portable internal links' ), 'The overlay should be idempotent.' );

// A core/tabs block whose panels carry source tab keys gets the `?tab=` selection script; generated ids do not.
$keyed = '<div class="wp-block-tabs"><section id="oral-habits" role="tabpanel"></section><section id="braces" role="tabpanel"></section></div>';
$assert( $keyed === Static_Site_Importer_Internal_Link_Runtime::maybe_enqueue_tab_query_script( $keyed ), 'Tab markup is returned unchanged.' );
$assert( true === ( $GLOBALS['ssi_scripts']['static-site-importer-tab-query']['enqueued'] ?? false ), 'A tabs block with source tab keys enqueues the tab query script.' );
$assert( str_contains( $GLOBALS['ssi_scripts']['static-site-importer-tab-query']['inline'], 'tab__' ) && ! str_contains( $GLOBALS['ssi_scripts']['static-site-importer-tab-query']['inline'], 'pushState' ) && ! str_contains( $GLOBALS['ssi_scripts']['static-site-importer-tab-query']['inline'], 'replaceState' ), 'The script clicks the real core tab and never rewrites the URL.' );
$GLOBALS['ssi_scripts'] = array();
Static_Site_Importer_Internal_Link_Runtime::maybe_enqueue_tab_query_script( '<div class="wp-block-tabs"><section id="blocks-engine-set-abc-1-panel-0" role="tabpanel"></section></div>' );
$assert( array() === $GLOBALS['ssi_scripts'], 'A tabs block with only generated panel ids does not enqueue the script.' );

echo "internal link runtime smoke passed\n";
