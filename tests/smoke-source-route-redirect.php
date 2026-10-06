<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url( string $path = '' ): string {
	return rtrim( $GLOBALS['ssi_redirect_home'], '/' ) . '/' . ltrim( $path, '/' );
}
function get_permalink( int $id ): string {
	return $GLOBALS['ssi_redirect_permalinks'][ $id ] ?? '';
}
function get_posts( array $args ): array {
	$value = (string) ( $args['meta_value'] ?? '' );
	$key   = (string) ( $args['meta_key'] ?? '' );
	$ids   = array();
	foreach ( $GLOBALS['ssi_redirect_meta'] as $id => $meta ) {
		if ( 'publish' !== ( $GLOBALS['ssi_redirect_statuses'][ $id ] ?? 'publish' ) ) {
			continue;
		}
		$values = $meta['values'] ?? array( $meta['value'] ?? '' );
		if ( $key === ( $meta['key'] ?? '' ) && in_array( $value, $values, true ) ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? 1 ) );
}
function add_action( string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['ssi_redirect_actions'][] = array( $hook, $callback, $priority );
}
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['ssi_redirect_filters'][] = array( $hook, $callback, $priority );
}
function get_post_type( int $id ): string {
	return $GLOBALS['ssi_redirect_types'][ $id ] ?? 'page';
}
function get_post_status( int $id ): string {
	return $GLOBALS['ssi_redirect_statuses'][ $id ] ?? 'publish';
}
function get_post_meta( int $id, string $key, bool $single = false ) {
	$meta   = $GLOBALS['ssi_redirect_meta'][ $id ] ?? array();
	$values = $key === ( $meta['key'] ?? '' ) ? ( $meta['values'] ?? array( $meta['value'] ?? '' ) ) : array();
	return $single ? ( $values[0] ?? '' ) : $values;
}
function get_option( string $name ) {
	return 'permalink_structure' === $name ? '/%postname%/' : null;
}
function user_trailingslashit( string $path ): string {
	return rtrim( $path, '/' ) . '/';
}

$GLOBALS['ssi_redirect_home']       = 'https://imported.test/';
$GLOBALS['ssi_redirect_permalinks'] = array();
$GLOBALS['ssi_redirect_meta']       = array();
$GLOBALS['ssi_redirect_actions']    = array();
$GLOBALS['ssi_redirect_filters']    = array();

require dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route-redirect.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-redirects-manifest.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( 'about-me.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/about-me.html' ), 'Artifact website/ prefix is not part of the public source route.' );
$assert( 'about-me.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'about-me.html' ), 'Root HTML files keep their source filename.' );
$assert( 'contact.htm' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'contact.htm' ), 'htm files keep their source filename.' );
$assert( 'foo/index.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/foo/index.html' ), 'Nested index documents keep their directory and index filename.' );
$assert( 'blog/merhaba-explorers/index.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/blog/merhaba-explorers/index.html' ), 'Nested blog documents keep the public source path.' );
$assert( 'index.html' === Static_Site_Importer_Source_Route_Redirect::public_source_route( 'website/index.html' ), 'The entry document public route is index.html.' );
$assert( '' === Static_Site_Importer_Source_Route_Redirect::public_source_route( '../escape.html' ), 'Source routes cannot escape the site root.' );

$parsed = Static_Site_Importer_Redirects_Manifest::parse(
	"# aliases\n/blog.html  /blog/index.html  301\n\n/about-me.html /about-me.html 301\n/gone  https://evil.test/ 301\n/news/*  /blog/:splat  301\n/old  /new  200\n/forced  /blog/index.html  301!\n/contact  /contact.html\n"
);
$assert(
	array(
		array(
			'from' => 'blog.html',
			'to'   => 'blog/index.html',
		),
		array(
			'from' => 'contact',
			'to'   => 'contact.html',
		),
	) === $parsed,
	'Redirects parser keeps same-site 301/302 aliases and ignores comments, duplicates, splats, rewrites, force, and external targets.'
);
$extracted = Static_Site_Importer_Redirects_Manifest::extract(
	array(
		'files' => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<main>Home</main>',
			),
			array(
				'path'    => 'website/_redirects',
				'content' => "/blog.html  /blog/index.html  301\n",
			),
			array(
				'path'    => 'website/blog/index.html',
				'content' => '<main>Blog</main>',
			),
		),
	)
);
$assert( is_array( $extracted ) && array( 'website/index.html', 'website/blog/index.html' ) === array_column( $extracted['artifact']['files'], 'path' ), '_redirects is excluded from page and asset materialization.' );
$assert( array(
array(
'from' => 'blog.html',
'to' => 'blog/index.html'
) ) === ( $extracted['aliases'] ?? null ), 'Extracted aliases keep the public from and target source path.' );
$aliases = Static_Site_Importer_Redirects_Manifest::aliases_for_source_paths(
	$extracted['aliases'],
	array( 'website/index.html', 'website/blog/index.html', 'website/about-me.html' )
);
$assert( array( 'website/blog/index.html' => array( 'blog.html' ) ) === $aliases, 'A _redirects alias attaches to the materialized page whose source path matches to.' );

$GLOBALS['ssi_redirect_permalinks'] = array(
	11 => 'https://imported.test/about-me/',
	12 => 'https://imported.test/contact/',
	13 => 'https://imported.test/blog/merhaba-explorers/',
	14 => 'https://imported.test/',
);
$GLOBALS['ssi_redirect_meta']       = array(
	11 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'about-me.html',
	),
	12 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'contact.html',
	),
	13 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'blog/merhaba-explorers/index.html',
	),
	14 => array(
		'key'   => Static_Site_Importer_Source_Route_Redirect::META_KEY,
		'value' => 'index.html',
	),
);

$assert( 'https://imported.test/about-me/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me.html' ), 'A source .html file redirects to the WordPress permalink.' );
$assert( 'https://imported.test/about-me/?utm=nav' === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me.html?utm=nav', 'utm=nav' ), 'Source-route redirects preserve the query string.' );
$assert( 'https://imported.test/contact/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/contact.html' ), 'Sibling HTML files redirect to their permalinks.' );
$assert( 'https://imported.test/blog/merhaba-explorers/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/blog/merhaba-explorers/index.html' ), 'Nested index.html source paths redirect to the page permalink.' );
$assert( 'https://imported.test/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/index.html' ), 'The source index document redirects to the front page permalink.' );
$GLOBALS['ssi_redirect_permalinks'][15] = 'https://imported.test/blog/';
$GLOBALS['ssi_redirect_meta'][15]       = array(
	'key'    => Static_Site_Importer_Source_Route_Redirect::META_KEY,
	'values' => array( 'blog/index.html', 'blog.html' ),
);
$assert( 'https://imported.test/blog/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/blog.html' ), 'A _redirects alias stored as additional source-route meta resolves to the page permalink.' );
$assert( 'https://imported.test/blog/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/blog/index.html' ), 'The materialized blog source path still resolves after an alias is attached.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/missing.html' ), 'Unknown source paths do not redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '//evil.test/about-me.html' ), 'Protocol-relative URLs never redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( 'https://evil.test/about-me.html' ), 'Absolute URLs never redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me/' ), 'The WordPress permalink itself is not rewritten.' );

$GLOBALS['ssi_redirect_home'] = 'https://playground.test/scope:abc/';
$assert( 'https://imported.test/about-me/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/scope:abc/about-me.html' ), 'Subdirectory homes still match the source path after the home prefix.' );

$GLOBALS['ssi_redirect_meta'][25] = array(
'key' => Static_Site_Importer_Source_Route_Redirect::META_KEY,
'value' => 'tag/topic/page/1/index.html'
);
$GLOBALS['wp']                    = (object) array( 'request' => 'tag/topic/page/1' );
$vars                             = array(
'tag' => 'topic',
'paged' => 1,
'feed' => 'rss2'
);
$assert( array(
'feed' => 'rss2',
'page_id' => 25
) === Static_Site_Importer_Source_Route_Redirect::resolve_request( $vars ), 'An exact imported route replaces native archive identities and retains feed state.' );
$assert( array( 'page_id' => 25 ) === Static_Site_Importer_Source_Route_Redirect::resolve_request( array(
'custom_topic' => 'topic',
'paged' => 1
) ), 'Custom-taxonomy query variables cannot constrain an owned singular document.' );
$rest = array(
'rest_route' => '/wp/v2/pages',
'tag' => 'topic'
);
$assert( $rest === Static_Site_Importer_Source_Route_Redirect::resolve_request( $rest ), 'REST query routing is preserved before WordPress defines REST_REQUEST.' );
$assert( 'https://playground.test/scope:abc/tag/topic/page/1/' === Static_Site_Importer_Source_Route_Redirect::filter_permalink( 'https://playground.test/scope:abc/tag/topic/page/1-2/', 25 ), 'Canonical links use the owned source URL even when WordPress renamed the numeric slug.' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$assert( $vars === Static_Site_Importer_Source_Route_Redirect::resolve_request( $vars ), 'POST requests keep their native query.' );
$_SERVER['REQUEST_METHOD'] = 'HEAD';
$assert( 25 === Static_Site_Importer_Source_Route_Redirect::resolve_request( $vars )['page_id'], 'HEAD resolves the same published imported document.' );
$GLOBALS['ssi_redirect_statuses'][25] = 'draft';
$assert( $vars === Static_Site_Importer_Source_Route_Redirect::resolve_request( $vars ), 'Draft route claims do not shadow native public routes.' );
unset( $GLOBALS['ssi_redirect_statuses'][25] );
$GLOBALS['ssi_redirect_meta'][26] = $GLOBALS['ssi_redirect_meta'][25];
$assert( $vars === Static_Site_Importer_Source_Route_Redirect::resolve_request( $vars ), 'Ambiguous source claims stay unowned.' );
unset( $GLOBALS['ssi_redirect_meta'][26] );
$GLOBALS['wp']->request = 'tag/native/page/1';
$assert( $vars === Static_Site_Importer_Source_Route_Redirect::resolve_request( $vars ), 'Unrelated native archive and pagination paths remain native.' );
unset( $GLOBALS['wp'], $_SERVER['REQUEST_METHOD'] );

$assert( ! method_exists( Static_Site_Importer_Source_Route_Redirect::class, 'prepare_overlay' ), 'Source-route redirects must not materialize a theme overlay.' );
$assert( ! method_exists( Static_Site_Importer_Source_Route_Redirect::class, 'theme_runtime_class' ), 'Source-route redirects must not own a theme-scoped class name.' );

$companion_source   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php' );
$preparation_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-site-plan-preparation.php' );
$assert( str_contains( $companion_source, "class-static-site-importer-source-route-redirect.php" ) && str_contains( $companion_source, "'/includes/source-route-redirect.php'" ) && str_contains( $companion_source, '_Source_Route_Redirect' ), 'The companion plugin must copy and register the source-route redirect runtime.' );
$assert( ! str_contains( $preparation_source, 'Static_Site_Importer_Source_Route_Redirect::prepare_overlay' ), 'Theme preparation must not overlay source-route redirects into functions.php.' );

$runtime_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route-redirect.php' );
$copy_dir       = sys_get_temp_dir() . '/ssi-source-route-copy-' . getmypid();
if ( ! is_dir( $copy_dir ) && ! mkdir( $copy_dir, 0777, true ) && ! is_dir( $copy_dir ) ) {
	throw new RuntimeException( 'Could not create companion runtime copy directory.' );
}
$copy_file = $copy_dir . '/source-route-redirect.php';
file_put_contents( $copy_file, str_replace( 'Static_Site_Importer_Source_Route_Redirect', 'SSI_TEST_SITE_Source_Route_Redirect', $runtime_source ) );
require $copy_file;

Static_Site_Importer_Source_Route_Redirect::register();
SSI_TEST_SITE_Source_Route_Redirect::register();
$assert( 1 === count( $GLOBALS['ssi_redirect_actions'] ), 'SSI and the companion copy must not both register template_redirect.' );
$assert( array( 'request', 'page_link', 'post_link', 'post_type_link' ) === array_column( $GLOBALS['ssi_redirect_filters'], 0 ), 'Companion and importer copies register request ownership and canonical links only once.' );
$assert( 'template_redirect' === ( $GLOBALS['ssi_redirect_actions'][0][0] ?? '' ) && 9 === ( $GLOBALS['ssi_redirect_actions'][0][2] ?? 0 ), 'The single source-route runtime runs before WordPress canonical redirects.' );
$assert( array( Static_Site_Importer_Source_Route_Redirect::class, 'redirect' ) === ( $GLOBALS['ssi_redirect_actions'][0][1] ?? null ) || array( SSI_TEST_SITE_Source_Route_Redirect::class, 'redirect' ) === ( $GLOBALS['ssi_redirect_actions'][0][1] ?? null ), 'The registered callback belongs to exactly one runtime class.' );

foreach ( array( $copy_file, $copy_dir ) as $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	} elseif ( is_dir( $path ) ) {
		rmdir( $path );
	}
}

echo "source route redirect smoke passed\n";
