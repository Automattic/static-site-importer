<?php
/**
 * Virtual current templates must not block materialization's content re-saves.
 *
 * Run with STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-current-page-template-materialization.php
 * or: wp --skip-plugins=static-site-importer eval-file tests/smoke-current-page-template-materialization.php
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
$ssi_invalid = static fn ( $result ): bool => is_wp_error( $result ) && 'invalid_page_template' === $result->get_error_code();
$ssi_slug    = 'virtual-catalog-' . substr( md5( (string) microtime( true ) ), 0, 8 );
$ssi_id      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'SSI virtual template fixture' ), true );
if ( is_wp_error( $ssi_id ) ) {
	fwrite( STDERR, 'FAIL: Cannot insert fixture: ' . $ssi_id->get_error_message() . PHP_EOL );
	exit( 1 );
}

$ssi_stored = get_post_meta( $ssi_id, '_wp_page_template', true );

// Like a plugin-owned virtual template, override only reads for this page.
$ssi_metadata = static function ( $value, $object_id, $meta_key, $single ) use ( $ssi_id, $ssi_slug ) {
	if ( $ssi_id === (int) $object_id && '_wp_page_template' === $meta_key ) {
		return $single ? $ssi_slug : array( $ssi_slug );
	}
	return $value;
};
$ssi_resave = static fn () => wp_update_post( array( 'ID' => $ssi_id, 'post_content' => '<!-- wp:paragraph --><p>Rewritten</p><!-- /wp:paragraph -->' ), true );
add_filter( 'get_post_metadata', $ssi_metadata, 10, 4 );
try {
	$ssi_assert( $ssi_slug === get_post_meta( $ssi_id, '_wp_page_template', true ), 'Metadata reports the virtual template.' );
	$ssi_assert( $ssi_slug === get_post( $ssi_id, ARRAY_A )['page_template'], 'get_post carries the virtual template into wp_update_post.' );
	$ssi_assert( ! isset( wp_get_theme()->get_page_templates()[ $ssi_slug ] ), 'The active theme does not register the virtual template.' );
	$ssi_assert( $ssi_invalid( $ssi_resave() ), 'Core rejects the content-only re-save outside materialization.' );

	// No plan-shipped templates: current assignments alone must enable the window.
	Static_Site_Importer_Planned_Page_Templates::during(
		array(),
		static function () use ( $ssi_assert, $ssi_invalid, $ssi_resave, $ssi_id, $ssi_slug ): void {
			$ssi_assert( $ssi_id === $ssi_resave(), 'Materialization can re-save content with a virtual current template.' );
			$ssi_assert( $ssi_slug === get_post_meta( $ssi_id, '_wp_page_template', true ), 'The reported assignment is preserved.' );
			$ssi_assert( false !== strpos( get_post_field( 'post_content', $ssi_id ), 'Rewritten' ), 'Rewritten content is saved.' );
			$ssi_assert( ! isset( wp_get_theme()->get_page_templates()[ $ssi_slug ] ), 'The current template is not registered without post context.' );
			$ssi_assert(
				$ssi_invalid( wp_update_post( array( 'ID' => $ssi_id, 'page_template' => $ssi_slug . '-other' ), true ) ),
				'A newly proposed unregistered assignment is still rejected.'
			);
		}
	);
	$ssi_assert( $ssi_invalid( $ssi_resave() ), 'Core rejects re-saves again after the window closes.' );
	try {
		Static_Site_Importer_Planned_Page_Templates::during(
			array(),
			static function (): void {
				throw new RuntimeException( 'materialization failed' );
			}
		);
	} catch ( RuntimeException $exception ) {
		$ssi_assert( $ssi_invalid( $ssi_resave() ), 'The window also closes after an exception.' );
	}
} finally {
	remove_filter( 'get_post_metadata', $ssi_metadata, 10 );
	$ssi_assert( $ssi_stored === get_post_meta( $ssi_id, '_wp_page_template', true ), 'The underlying stored assignment is unchanged.' );
	wp_delete_post( $ssi_id, true );
}

if ( array() !== $ssi_failures ) {
	foreach ( $ssi_failures as $ssi_failure ) {
		fwrite( STDERR, 'FAIL: ' . $ssi_failure . PHP_EOL );
	}
	exit( 1 );
}
echo "Current page template materialization smoke passed.\n";
