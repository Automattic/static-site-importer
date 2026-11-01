<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/fixtures/link-runtime-stubs.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-capture-evidence.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-internal-link-runtime.php';
function wp_register_script( $handle, ...$args ) { $GLOBALS['scripts'][ $handle ] = ''; }
function wp_enqueue_script( $handle ) {}
function wp_add_inline_script( $handle, $script ) { $GLOBALS['scripts'][ $handle ] .= $script; }
$assert = static function ( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$_SERVER['HTTP_HOST'] = 'playground.test';
$_SERVER['REQUEST_URI'] = '/scope:abc/bylaws/';
$link = '<a href="https://source.test/login" class="data-blocks-engine-capture-member-login-wix">Sign in</a>';
$resolved = Static_Site_Importer_Internal_Link_Runtime::filter_member_login( $link );
$assert( str_contains( $resolved, 'wp-login.php?redirect_to=' ) && ! str_contains( $resolved, 'source.test' ), 'Member links resolve to the local login.' );
$button = '<div class="wp-block-button data-blocks-engine-capture-member-login-wix"><button type="button" class="wp-block-button__link"><span>Sign in</span></button></div>';
$resolved_button = Static_Site_Importer_Internal_Link_Runtime::filter_member_login( $button );
$assert( str_contains( $resolved_button, '<a href=' ) && str_contains( $resolved_button, '<span>Sign in</span></a>' ) && ! str_contains( $resolved_button, '<button' ), 'Marked core buttons become login links.' );
$plain = '<a href="/about">About</a><button>Menu</button>';
$assert( $plain === Static_Site_Importer_Internal_Link_Runtime::filter_member_login( $plain ), 'Ordinary links and buttons are never rewritten.' );
$overlay = Static_Site_Importer_Internal_Link_Runtime::prepare_interaction_overlay( array(), array(), 'example' );
$runtime = $overlay['writes'][1]['content'];
$assert( ! str_contains( $runtime, 'get_permalink' ) && ! str_contains( $runtime, 'resolve_urls' ), 'Generated interaction support contains no link resolver.' );
$assert( ! str_contains( $runtime, 'Static_Site_Importer_Capture_Evidence::MEMBER_LOGIN' ), 'Capture markers are inlined for standalone themes.' );
$dir = sys_get_temp_dir() . '/ssi-member-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir );
file_put_contents( $dir . '/runtime.php', $runtime );
file_put_contents( $dir . '/run.php', '<?php define("ABSPATH", "/"); require ' . var_export( __DIR__ . '/fixtures/link-runtime-stubs.php', true ) . '; $_SERVER["HTTP_HOST"]="playground.test"; $_SERVER["REQUEST_URI"]="/scope:abc/bylaws/"; require ' . var_export( $dir . '/runtime.php', true ) . '; echo SSI_Theme_EXAMPLE_Internal_Link_Runtime::filter_member_login(' . var_export( $link, true ) . ');' );
$assert( $resolved === shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/run.php' ) ), 'Member sign-in works without the importer.' );
unlink( $dir . '/run.php' ); unlink( $dir . '/runtime.php' ); rmdir( $dir );
$GLOBALS['scripts'] = array();
Static_Site_Importer_Internal_Link_Runtime::maybe_enqueue_tab_query_script( '<section id="blocks-engine-set-abc" role="tabpanel"></section>' );
$assert( array() === $GLOBALS['scripts'], 'Generated panel ids do not enqueue.' );
$keyed = '<section id="braces" role="tabpanel"></section>';
$assert( $keyed === Static_Site_Importer_Internal_Link_Runtime::maybe_enqueue_tab_query_script( $keyed ), 'Tab markup is unchanged.' );
Static_Site_Importer_Internal_Link_Runtime::maybe_enqueue_tab_query_script( $keyed );
$assert( Static_Site_Importer_Internal_Link_Runtime::tab_query_script() === $GLOBALS['scripts']['static-site-importer-tab-query'], 'Tab deep links enqueue exactly once.' );
echo "captured interactions smoke passed\n";
