<?php
/**
 * Navigation entity persistence: deterministic wp_navigation posts and ref rewrites.
 *
 * Run: php tests/smoke-navigation-entity-materializer.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_nav_posts'] = array();
$GLOBALS['ssi_nav_meta']  = array();

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
}
class WP_Post {
	public int $ID;
	public string $post_type = 'wp_navigation';
	public string $post_content = '';
	public function __construct( int $id ) {
		$this->ID           = $id;
		$this->post_type    = (string) ( $GLOBALS['ssi_nav_posts'][ $id ]['post_type'] ?? 'wp_navigation' );
		$this->post_content = (string) ( $GLOBALS['ssi_nav_posts'][ $id ]['post_content'] ?? '' );
	}
}
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error; }
function post_type_exists( string $type ): bool {
	return 'wp_navigation' === $type; }
function sanitize_title( string $value ): string {
	return trim( (string) preg_replace( '/-+/', '-', (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ), '-' ); }
function sanitize_key( string $value ): string {
	return strtolower( $value ); }
function wp_slash( string $value ): string {
	return $value; }
function wp_insert_post( array $post, bool $wp_error = false ) {
	unset( $wp_error );
	$id = ! empty( $post['ID'] ) ? (int) $post['ID'] : count( $GLOBALS['ssi_nav_posts'] ) + 1;
	$GLOBALS['ssi_nav_posts'][ $id ] = $post;
	return $id;
}
function wp_update_post( array $post, bool $wp_error = false ) {
	unset( $wp_error );
	$id = (int) ( $post['ID'] ?? 0 );
	if ( $id <= 0 || ! isset( $GLOBALS['ssi_nav_posts'][ $id ] ) ) {
		return new WP_Error( 'missing_post' );
	}
	$GLOBALS['ssi_nav_posts'][ $id ] = array_merge( $GLOBALS['ssi_nav_posts'][ $id ], $post );
	return $id;
}
function get_post_field( string $field, int $id ): string {
	return (string) ( $GLOBALS['ssi_nav_posts'][ $id ][ $field ] ?? '' ); }
function get_post_meta( int $id, string $key, bool $single = true ): string {
	unset( $single );
	return (string) ( $GLOBALS['ssi_nav_meta'][ $id ][ $key ] ?? '' ); }
function update_post_meta( int $id, string $key, string $value ): void {
	$GLOBALS['ssi_nav_meta'][ $id ][ $key ] = $value; }
function metadata_exists( string $type, int $id, string $key ): bool {
	return 'post' === $type && array_key_exists( $key, $GLOBALS['ssi_nav_meta'][ $id ] ?? array() ); }
function get_posts( array $args ): array {
	$matches = array();
	foreach ( $GLOBALS['ssi_nav_meta'] as $id => $meta ) {
		if ( isset( $meta[ $args['meta_key'] ] ) && ( ! isset( $args['meta_value'] ) || $meta[ $args['meta_key'] ] === $args['meta_value'] ) ) {
			$matches[] = new WP_Post( $id );
		}
	}
	return $matches;
}

class Static_Site_Importer_Site_Plan_Persistence {
	public static function rewrite_route_references( string $content, array $routes, ?array &$unresolved = null ): string {
		unset( $routes, $unresolved );
		return $content;
	}
	public static function reconciled_post( string $identity ) {
		$posts = get_posts(
			array(
				'meta_key'   => Static_Site_Importer_Navigation_Entity_Materializer::META_KEY,
				'meta_value' => $identity,
			)
		);
		return $posts[0] ?? null;
	}
	public static function write_post_meta( int $id, string $key, string $value ): bool {
		update_post_meta( $id, $key, $value );
		return metadata_exists( 'post', $id, $key ) && get_post_meta( $id, $key, true ) === $value;
	}
	public static function is_valid_post_type( string $post_type ): bool {
		return in_array( $post_type, array( 'page', 'post' ), true );
	}
	public static function portable_internal_reference( int $post_id, string $post_type ): string {
		return 'page' === $post_type ? '/?page_id=' . $post_id : '/?p=' . $post_id;
	}
	public static function normalized_route_path( string $path ): string {
		if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
			return '';
		}
		$normalized = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : $normalized;
	}
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-navigation-entity-materializer.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$token   = 'navigation-aaaaaaaaaaaaaaaa';
$prefix  = Static_Site_Importer_Navigation_Entity_Materializer::TOKEN_PREFIX;
$inline  = '<!-- wp:navigation {"className":"primary","overlayMenu":"mobile"} --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"About","url":"/about"} /--><!-- /wp:navigation -->';
$tokened = '<!-- wp:navigation {"className":"primary","overlayMenu":"mobile","ref":"' . $prefix . $token . '}}"} /-->';
$rewritten_token = Static_Site_Importer_Navigation_Entity_Materializer::rewrite_references( $tokened, array( $token => 42 ) );
$assert( str_contains( $rewritten_token, '"ref":42' ) && ! str_contains( $rewritten_token, $prefix ) && str_contains( $rewritten_token, '"overlayMenu":"mobile"' ) && str_contains( $rewritten_token, '"className":"primary"' ), 'Token refs become integer refs while overlay and className stay on the referencing block.' );
$rewritten_match = Static_Site_Importer_Navigation_Entity_Materializer::rewrite_matching( $inline, array( "Home\t/\nAbout\t/about" => 42 ) );
$assert( str_contains( $rewritten_match, '"ref":42' ) && ! str_contains( $rewritten_match, 'wp:navigation-link' ) && str_contains( $rewritten_match, '"overlayMenu":"mobile"' ), 'Matching inline navigation becomes a self-closing integer ref.' );
$assert( '<!-- wp:page-list /-->' === Static_Site_Importer_Navigation_Entity_Materializer::rewrite_references( '<!-- wp:page-list /-->', array( $token => 42 ) ), 'Unrelated markup is left alone.' );

$identity = str_repeat( 'ab', 32 );
$state    = array(
	'resolved'       => array(
		'menus'          => array(
			array(
				'kind'                    => 'menu',
				'token'                   => $token,
				'block_markup'            => '<!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:navigation-link {"label":"About","url":"/about"} /-->',
				'reconciliation_identity' => $identity,
				'title'                   => 'Header',
				'target_slug'             => 'header',
			),
		),
		'writes'         => array(
			array(
				'target_path' => 'parts/header.html',
				'payload'     => array(
					'encoding' => 'utf8',
					'data'     => $inline,
				),
			),
		),
		'pages'          => array(
			array(
				'source_path'            => 'index.html',
				'resolved_block_markup'  => $inline,
				'canonical_block_markup' => $inline,
			),
		),
		'template_parts' => array(),
	),
	'ordered_pages'  => array(),
	'source_ids'     => array(),
	'applied'        => array( 'posts' => array() ),
	'rollback'       => array( 'posts' => array() ),
);

$first = Static_Site_Importer_Navigation_Entity_Materializer::materialize( $state );
$assert( ! is_wp_error( $first ), 'First persist succeeds.' );
$nav_posts = array_values(
	array_filter(
		$GLOBALS['ssi_nav_posts'],
		static fn( array $post ): bool => 'wp_navigation' === ( $post['post_type'] ?? '' )
	)
);
$assert( 1 === count( $nav_posts ), 'Exactly one wp_navigation post is created.' );
$assert( str_contains( (string) $nav_posts[0]['post_content'], '"label":"Home"' ) && str_contains( (string) $nav_posts[0]['post_content'], '"label":"About"' ) && str_contains( (string) $nav_posts[0]['post_content'], '"url":"/"' ) && str_contains( (string) $nav_posts[0]['post_content'], '"url":"/about"' ) && ! str_contains( (string) $nav_posts[0]['post_content'], 'page_id' ) && ! str_contains( (string) $nav_posts[0]['post_content'], 'wp:page-list' ), 'The navigation post stores source links with canonical routes, not the page-list placeholder.' );
$assert( str_contains( (string) $state['resolved']['writes'][0]['payload']['data'], '"ref":1' ) && ! str_contains( (string) $state['resolved']['writes'][0]['payload']['data'], $prefix ), 'Header write references the persisted post by integer ref.' );
$assert( str_contains( (string) $state['resolved']['pages'][0]['resolved_block_markup'], '"ref":1' ), 'Page-owned copies of the same menu also reference the persisted post.' );
$first_id = (int) $state['applied']['navigation_entities'][0]['id'];

$second = Static_Site_Importer_Navigation_Entity_Materializer::materialize( $state );
$assert( ! is_wp_error( $second ), 'Re-persist succeeds.' );
$assert( 1 === count( $GLOBALS['ssi_nav_posts'] ), 'Re-import updates the same wp_navigation post instead of duplicating it.' );
$assert( $first_id === (int) $state['applied']['navigation_entities'][1]['id'], 'Idempotent persist keeps the same post id.' );

$GLOBALS['ssi_nav_posts'][99] = array(
	'post_type'    => 'wp_navigation',
	'post_name'    => 'navigation',
	'post_title'   => 'Navigation',
	'post_content' => '<!-- wp:page-list /-->',
);
$assert( 2 === count( $GLOBALS['ssi_nav_posts'] ) && '<!-- wp:page-list /-->' === $GLOBALS['ssi_nav_posts'][99]['post_content'], 'The default page-list navigation post remains unused.' );

echo "smoke-navigation-entity-materializer: ok\n";
