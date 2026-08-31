<?php
/**
 * WordPress-runtime coverage for generated editor stylesheet registration.
 *
 * Run: wp --skip-plugins=static-site-importer eval-file tests/smoke-editor-theme-styles.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This test requires a WordPress runtime.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/includes/class-static-site-importer-font-materializer.php';

$slug       = 'ssi-editor-styles-' . wp_generate_password( 8, false, false );
$theme_dir  = WP_CONTENT_DIR . '/themes/' . $slug;
$old_theme  = get_stylesheet();
$marker     = '.ssi-editor-style-runtime-proof{color:#123456}';
$failures   = array();
$old_styles = $GLOBALS['editor_styles'] ?? null;

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

try {
	$overlay = Static_Site_Importer_Font_Materializer::prepare_overlay(
		array(),
		array(
			'writes' => array(
				array(
					'target_path' => 'functions.php',
					'payload'     => array(
						'encoding' => 'utf8',
						'data'     => "<?php\n",
					),
				),
			),
		)
	);
	$writes  = array();
	foreach ( is_array( $overlay ) ? $overlay['writes'] ?? array() : array() as $write ) {
		if ( 'utf8' === ( $write['encoding'] ?? null ) ) {
			$writes[ $write['target_path'] ] = $write['content'];
		}
	}

	$assert( isset( $writes['functions.php'] ), 'The font materializer did not emit the generated theme bootstrap.' );
	if ( ! isset( $writes['functions.php'] ) ) {
		throw new RuntimeException( 'Generated theme bootstrap is unavailable.' );
	}

	wp_mkdir_p( $theme_dir . '/assets/css' );
	wp_mkdir_p( $theme_dir . '/templates' );
	file_put_contents( $theme_dir . '/style.css', "/* Theme Name: SSI Editor Styles Runtime */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/theme.json', wp_json_encode( array( 'version' => 3 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/templates/index.html', '<!-- wp:post-content /-->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/assets/css/editor-style.css', $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/functions.php', $writes['functions.php'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	switch_theme( $slug );
	wp_clean_themes_cache();

	global $wp_filter;
	$before = isset( $wp_filter['after_setup_theme']->callbacks[10] ) ? array_keys( $wp_filter['after_setup_theme']->callbacks[10] ) : array();
	require $theme_dir . '/functions.php';
	$after = $wp_filter['after_setup_theme']->callbacks[10] ?? array();
	$new   = array_diff_key( $after, array_fill_keys( $before, true ) );

	$assert( 1 === count( $new ), 'The generated theme did not register exactly one setup callback.' );
	foreach ( $new as $callback ) {
		$callback['function']();
	}

	$styles = get_block_editor_theme_styles();
	$css    = implode( "\n", array_column( $styles, 'css' ) );
	$assert( str_contains( $css, $marker ), 'WordPress Core did not load assets/css/editor-style.css into block editor theme styles.' );
} finally {
	$GLOBALS['editor_styles'] = $old_styles;
	switch_theme( $old_theme );
	wp_clean_themes_cache();
	if ( is_dir( $theme_dir ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $path ) {
			$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
		}
		rmdir( $theme_dir );
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS: generated editor stylesheet resolves through WordPress Core\n" );
