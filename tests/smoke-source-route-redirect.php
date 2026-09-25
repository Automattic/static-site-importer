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
		if ( $key === ( $meta['key'] ?? '' ) && $value === ( $meta['value'] ?? '' ) ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? 1 ) );
}

$GLOBALS['ssi_redirect_home']       = 'https://imported.test/';
$GLOBALS['ssi_redirect_permalinks'] = array();
$GLOBALS['ssi_redirect_meta']       = array();

require dirname( __DIR__ ) . '/includes/class-static-site-importer-source-route-redirect.php';

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
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/missing.html' ), 'Unknown source paths do not redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '//evil.test/about-me.html' ), 'Protocol-relative URLs never redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( 'https://evil.test/about-me.html' ), 'Absolute URLs never redirect.' );
$assert( null === Static_Site_Importer_Source_Route_Redirect::target_url( '/about-me/' ), 'The WordPress permalink itself is not rewritten.' );

$GLOBALS['ssi_redirect_home'] = 'https://playground.test/scope:abc/';
$assert( 'https://imported.test/about-me/' === Static_Site_Importer_Source_Route_Redirect::target_url( '/scope:abc/about-me.html' ), 'Subdirectory homes still match the source path after the home prefix.' );

$result    = Static_Site_Importer_Source_Route_Redirect::prepare_overlay(
	array( 'writes' => array() ),
	array( 'writes' => array( array( 'target_path' => 'functions.php', 'content' => "<?php\n// Existing bootstrap.\n" ), array( 'target_path' => 'portable-internal-links.php', 'content' => "<?php\n" ) ) ),
	'adventuring-ankara'
);
$bootstrap = (string) ( $result['writes'][0]['content'] ?? '' );
$runtime   = '';
foreach ( $result['writes'] as $write ) {
	if ( 'source-route-redirect.php' === ( $write['target_path'] ?? '' ) ) {
		$runtime = (string) ( $write['content'] ?? '' );
	}
}
$assert( 'materialized' === ( $result['status'] ?? '' ), 'Source-route redirects should always materialize into the generated theme.' );
$assert( str_contains( $bootstrap, '// Existing bootstrap.' ) && str_contains( $bootstrap, 'Static Site Importer source route redirects' ), 'The overlay should keep prior bootstrap code and add the redirect marker.' );
$assert( str_contains( $bootstrap, "require_once get_stylesheet_directory() . '/source-route-redirect.php'" ) && str_contains( $bootstrap, 'SSI_Theme_ADVENTURING_ANKARA_Source_Route_Redirect::register()' ), 'The portable theme bootstrap must load and register the redirector after SSI is gone.' );
$assert( str_contains( $runtime, 'final class SSI_Theme_ADVENTURING_ANKARA_Source_Route_Redirect' ) && ! str_contains( $runtime, 'Static_Site_Importer_Source_Route_Redirect' ), 'The generated theme owns a theme-scoped copy of the redirector.' );
$passed_links = false;
foreach ( $result['writes'] as $write ) {
	if ( 'portable-internal-links.php' === ( $write['target_path'] ?? '' ) ) {
		$passed_links = true;
	}
}
$assert( $passed_links, 'The overlay should keep previously materialized theme runtime files.' );

$repeat = Static_Site_Importer_Source_Route_Redirect::prepare_overlay(
	array(),
	array( 'writes' => $result['writes'] ),
	'adventuring-ankara'
);
$assert( 1 === substr_count( (string) ( $repeat['writes'][0]['content'] ?? '' ), 'Static Site Importer source route redirects' ), 'The overlay should be idempotent.' );
$runtime_writes = 0;
foreach ( $repeat['writes'] as $write ) {
	if ( 'source-route-redirect.php' === ( $write['target_path'] ?? '' ) ) {
		++$runtime_writes;
	}
}
$assert( 1 === $runtime_writes, 'The overlay should not duplicate the theme runtime file.' );

echo "source route redirect smoke passed\n";
