<?php
/**
 * Pages assigned a plan-shipped custom template survive re-saves made before
 * the planned theme is active.
 *
 * Materialization inserts pages, then rewrites their content with
 * wp_update_post() (route links, media), and only afterwards installs and
 * activates the planned theme. Core validates `_wp_page_template` against the
 * active theme on every update, so an assigned route template the incoming
 * theme registers must be offered to core for that window, and only then.
 *
 * Run from the repository root against a WordPress install whose active theme
 * does not ship the fixture templates:
 * STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-planned-page-templates-materialization.php
 * or: wp --skip-plugins=static-site-importer eval-file tests/smoke-planned-page-templates-materialization.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	$ssi_wp_root = rtrim( (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ), '/\\' );
	if ( '' === $ssi_wp_root || ! is_readable( $ssi_wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, "FAIL: This test requires a WordPress runtime. Set STATIC_SITE_IMPORTER_WP_ROOT to a WordPress install.\n" );
		exit( 1 );
	}
	$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
	require $ssi_wp_root . '/wp-load.php';
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-planned-page-templates.php';

$ssi_failures = array();
$ssi_assert   = static function ( bool $condition, string $message ) use ( &$ssi_failures ): void {
	if ( ! $condition ) {
		$ssi_failures[] = $message;
	}
};

$ssi_suffix = substr( md5( (string) microtime( true ) ), 0, 8 );
$ssi_page   = 'page-north-about-' . $ssi_suffix;
$ssi_single = 'single-post-north-note-' . $ssi_suffix;
$ssi_plan   = array(
	'templates' => array(
		array( 'slug' => 'page', 'title' => 'Page' ),
		array( 'slug' => $ssi_page, 'title' => 'About (north)' ),
		array( 'slug' => $ssi_single, 'title' => 'Note (north)' ),
	),
	'pages'     => array(
		array( 'post_type' => 'page', 'metadata' => array( 'post_meta' => array( '_wp_page_template' => $ssi_page ) ) ),
		array( 'post_type' => 'post', 'metadata' => array( 'post_meta' => array( '_wp_page_template' => $ssi_single ) ) ),
		// Assigned but not shipped by the plan: never registered.
		array( 'post_type' => 'page', 'metadata' => array( 'post_meta' => array( '_wp_page_template' => 'page-unshipped-' . $ssi_suffix ) ) ),
	),
);

$ssi_assert(
	array(
		'page' => array( $ssi_page => 'About (north)' ),
		'post' => array( $ssi_single => 'Note (north)' ),
	) === Static_Site_Importer_Planned_Page_Templates::assigned( $ssi_plan ),
	'Only templates the plan ships and assigns are registered, per post type.'
);

$ssi_created = array();
$ssi_insert  = static function ( string $post_type, string $template ) use ( &$ssi_created ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => $post_type,
			'post_status' => 'draft',
			'post_title'  => 'SSI planned template ' . $template,
			'meta_input'  => array( '_wp_page_template' => $template ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	$ssi_created[] = $id;
	return (int) $id;
};
$ssi_resave = static fn ( int $id ) => wp_update_post( array( 'ID' => $id, 'post_content' => '<!-- wp:paragraph --><p>Rewritten</p><!-- /wp:paragraph -->' ), true );

$ssi_page_id   = $ssi_insert( 'page', $ssi_page );
$ssi_post_id   = $ssi_insert( 'post', $ssi_single );
$ssi_orphan_id = $ssi_insert( 'page', 'page-unshipped-' . $ssi_suffix );
$ssi_assert( $ssi_page_id > 0 && $ssi_post_id > 0 && $ssi_orphan_id > 0, 'Fixture posts are inserted.' );

// Without registration, core rejects the re-save (the failure this covers).
$ssi_before = $ssi_resave( $ssi_page_id );
$ssi_assert( is_wp_error( $ssi_before ) && 'invalid_page_template' === $ssi_before->get_error_code(), 'Precondition: the active theme does not provide the planned template.' );

$ssi_during = Static_Site_Importer_Planned_Page_Templates::during(
	$ssi_plan,
	static fn (): array => array(
		'page'   => $ssi_resave( $ssi_page_id ),
		'post'   => $ssi_resave( $ssi_post_id ),
		'orphan' => $ssi_resave( $ssi_orphan_id ),
	)
);
$ssi_assert( $ssi_page_id === $ssi_during['page'], 'A page assigned a planned template can be re-saved during materialization.' );
$ssi_assert( $ssi_post_id === $ssi_during['post'], 'A post assigned a planned single template can be re-saved during materialization.' );
$ssi_assert( is_wp_error( $ssi_during['orphan'] ), 'A template the plan does not ship stays invalid.' );
$ssi_assert( $ssi_page === get_post_meta( $ssi_page_id, '_wp_page_template', true ), 'The planned assignment is preserved.' );

$ssi_after = $ssi_resave( $ssi_page_id );
$ssi_assert( is_wp_error( $ssi_after ), 'Registration ends with materialization.' );

$ssi_thrown = false;
try {
	Static_Site_Importer_Planned_Page_Templates::during(
		$ssi_plan,
		static function (): void {
			throw new RuntimeException( 'materialization failed' );
		}
	);
} catch ( RuntimeException $exception ) {
	$ssi_thrown = true;
}
$ssi_assert( $ssi_thrown && is_wp_error( $ssi_resave( $ssi_page_id ) ), 'Registration is removed when materialization throws.' );

foreach ( $ssi_created as $ssi_id ) {
	wp_delete_post( $ssi_id, true );
}

if ( array() !== $ssi_failures ) {
	foreach ( $ssi_failures as $ssi_failure ) {
		fwrite( STDERR, 'FAIL: ' . $ssi_failure . PHP_EOL );
	}
	exit( 1 );
}
echo "Planned page templates materialization smoke passed.\n";
