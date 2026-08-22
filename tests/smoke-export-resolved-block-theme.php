<?php
/**
 * Integration coverage for WordPress block template and Global Styles export.
 *
 * Run against WordPress with SSI skipped so this test loads the worktree class:
 * studio wp --skip-plugins=static-site-importer eval-file tests/smoke-export-resolved-block-theme.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This test requires a WordPress runtime.\n" );
	exit( 1 );
}

if ( ! function_exists( 'blocks_engine_php_transformer_convert_format' ) ) {
	function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
		return array( 'documents' => array( array( 'content' => $content ) ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-exporter.php';

$slug      = 'ssi-export-runtime-' . wp_generate_password( 8, false, false );
$theme_dir = WP_CONTENT_DIR . '/themes/' . $slug;
$old_theme = get_stylesheet();
$options   = array( 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => get_option( 'page_on_front' ) );
$post_ids  = array();
$failures  = array();
$assert    = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

try {
	wp_mkdir_p( $theme_dir . '/templates' );
	wp_mkdir_p( $theme_dir . '/parts' );
	wp_mkdir_p( $theme_dir . '/styles' );
	file_put_contents( $theme_dir . '/style.css', "/* Theme Name: SSI Export Runtime */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/theme.json', wp_json_encode( array( 'version' => 3, 'styles' => array( 'color' => array( 'background' => '#010203' ) ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/styles/active.json', wp_json_encode( array( 'version' => 3, 'title' => 'Active', 'styles' => array( 'color' => array( 'text' => '#aabbcc' ) ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/templates/custom.html', '<!-- wp:template-part {"slug":"outer"} /--><!-- wp:post-content /-->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $theme_dir . '/parts/outer.html', '<!-- wp:paragraph --><p>Theme outer</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	switch_theme( $slug );
	wp_clean_themes_cache();
	WP_Theme_JSON_Resolver::clean_cached_data();

	$page_id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Runtime export', 'post_name' => 'runtime-export', 'post_content' => '<!-- wp:paragraph --><p>Page body</p><!-- /wp:paragraph -->' ) );
	$post_ids[] = $page_id;
	update_post_meta( $page_id, '_wp_page_template', 'custom' );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $page_id );

	$template_id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_title' => 'custom', 'post_name' => 'custom', 'post_content' => '<!-- wp:template-part {"slug":"outer"} /--><!-- wp:post-content /-->' ) );
	$post_ids[]  = $template_id;
	wp_set_post_terms( $template_id, array( $slug ), 'wp_theme' );
	$outer_id   = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'outer', 'post_name' => 'outer', 'post_content' => '<!-- wp:paragraph --><p>Database outer</p><!-- /wp:paragraph --><!-- wp:template-part {"slug":"inner"} /-->' ) );
	$post_ids[] = $outer_id;
	wp_set_post_terms( $outer_id, array( $slug ), 'wp_theme' );
	$inner_id   = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'inner', 'post_name' => 'inner', 'post_content' => '<!-- wp:paragraph --><p>Nested database part</p><!-- /wp:paragraph -->' ) );
	$post_ids[] = $inner_id;
	wp_set_post_terms( $inner_id, array( $slug ), 'wp_theme' );
	$styles_id  = wp_insert_post( array( 'post_type' => 'wp_global_styles', 'post_status' => 'publish', 'post_title' => 'Active variation', 'post_name' => 'wp-global-styles-' . $slug, 'post_content' => wp_json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => array( 'color' => array( 'background' => '#f0e1d2' ) ) ) ) ) );
	$post_ids[] = $styles_id;
	wp_set_post_terms( $styles_id, array( $slug ), 'wp_theme' );
	WP_Theme_JSON_Resolver::clean_cached_data();

	$result   = Static_Site_Importer_Theme_Exporter::export_theme( array( 'theme_slug' => $slug, 'include_pages' => array( $page_id ) ) );
	$artifact = $result['website_artifact'] ?? array();
	$files    = array_column( $artifact['files'] ?? array(), 'content', 'path' );
	$html     = $files['website/index.html'] ?? '';
	$css      = $files['website/global-styles.css'] ?? '';
	$assert( str_contains( $html, 'Database outer' ), 'Database template-part override did not win over the theme file.' );
	$assert( str_contains( $html, 'Nested database part' ), 'Nested template part was not resolved by WordPress.' );
	$assert( str_contains( $html, 'Page body' ), 'The custom database template did not render post content.' );
	$assert( ! str_contains( $html, 'Theme outer' ), 'Theme template-part file won over its database override.' );
	$assert( str_contains( $html, 'global-styles.css' ), 'Exported document does not link merged Global Styles.' );
	$assert( str_contains( $css, '#f0e1d2' ), 'User Global Styles/style-variation presentation is absent from exported CSS.' );
} finally {
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	update_option( 'show_on_front', $options['show_on_front'] );
	update_option( 'page_on_front', $options['page_on_front'] );
	switch_theme( $old_theme );
	WP_Theme_JSON_Resolver::clean_cached_data();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $path ) {
		$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
	}
	rmdir( $theme_dir );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "OK: resolved block theme export integration passed\n";
