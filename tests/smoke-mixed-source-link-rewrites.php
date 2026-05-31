<?php
/**
 * Smoke test: mixed HTML/Markdown source links rewrite through one route map.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( ! class_exists( 'Static_Site_Importer_Document', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-document.php';
}
if ( ! class_exists( 'Static_Site_Importer_Source_Page', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-source-page.php';
}
if ( ! class_exists( 'Static_Site_Importer_Woo_Product_Seeder', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-woo-product-seeder.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$fixture = $plugin_root . '/tests/fixtures/mixed-source-links/index.html';
$result  = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'        => 'Mixed Source Links',
		'slug'        => 'mixed-source-links-smoke',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$pages = $result['pages'];
	foreach ( array( 'index.html', 'about.markdown', 'docs/intro.md', 'docs/install.markdown' ) as $source_path ) {
		$assert( isset( $pages[ $source_path ] ), 'page-created-' . $source_path );
	}

	$home_permalink    = get_permalink( $pages['index.html'] ?? 0 );
	$about_permalink   = get_permalink( $pages['about.markdown'] ?? 0 );
	$intro_permalink   = get_permalink( $pages['docs/intro.md'] ?? 0 );
	$install_permalink = get_permalink( $pages['docs/install.markdown'] ?? 0 );

	$header_nav      = get_page_by_path( 'mixed-source-links-smoke-header-navigation', OBJECT, 'wp_navigation' );
	$home_content    = get_post_field( 'post_content', $pages['index.html'] ?? 0 );
	$about_content   = get_post_field( 'post_content', $pages['about.markdown'] ?? 0 );
	$intro_content   = get_post_field( 'post_content', $pages['docs/intro.md'] ?? 0 );
	$install_content = get_post_field( 'post_content', $pages['docs/install.markdown'] ?? 0 );

	$assert( $header_nav instanceof WP_Post, 'header-navigation-created' );
	$header_content = $header_nav instanceof WP_Post ? $header_nav->post_content : '';
	$assert( str_contains( $header_content, (string) $intro_permalink ), 'html-chrome-to-markdown' );
	$assert( str_contains( $header_content, (string) $about_permalink ), 'html-chrome-to-markdown-extension' );
	$assert( str_contains( $header_content, (string) $install_permalink ), 'html-chrome-clean-route' );
	$assert( str_contains( $home_content, (string) $intro_permalink ), 'html-body-to-markdown' );
	$assert( str_contains( $about_content, (string) $home_permalink ), 'markdown-to-html' );
	$assert( str_contains( $about_content, (string) $install_permalink ), 'markdown-clean-route' );
	$assert( str_contains( $intro_content, (string) $about_permalink ), 'markdown-to-markdown-relative' );
	$assert( str_contains( $intro_content, (string) $install_permalink ), 'markdown-to-markdown-extension-swapped' );
	$assert( str_contains( $install_content, (string) $home_permalink ), 'markdown-root-clean-route' );

	$report      = json_decode( $read( $result['report_path'] ), true );
	$diagnostics = is_array( $report ) ? wp_list_pluck( $report['diagnostics'] ?? array(), 'type' ) : array();
	$assert( in_array( 'unresolved_internal_link', $diagnostics, true ), 'unresolved-link-diagnostic' );
	$assert( ! in_array( 'local_asset_not_materialized', $diagnostics, true ), 'local-asset-no-longer-unmaterialized' );

	$unresolved_diagnostics = is_array( $report ) ? array_values(
		array_filter(
			$report['diagnostics'] ?? array(),
			static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'unresolved_internal_link' === ( $diagnostic['type'] ?? '' )
		)
	) : array();
	$local_assets           = is_array( $report ) ? ( $report['assets']['local'] ?? array() ) : array();

	$assert( ! empty( $unresolved_diagnostics ), 'unresolved-link-diagnostic-record-present' );
	if ( ! empty( $unresolved_diagnostics ) ) {
		$link = $unresolved_diagnostics[0];
		$assert( str_starts_with( (string) ( $link['id'] ?? '' ), 'diag-' ), 'unresolved-link-diagnostic-has-id' );
		$assert( 'warning' === ( $link['severity'] ?? '' ), 'unresolved-link-diagnostic-has-severity' );
		$assert( 'broken_internal_link' === ( $link['category'] ?? '' ), 'unresolved-link-diagnostic-has-category' );
		$assert( 'unresolved_internal_link' === ( $link['reason_code'] ?? '' ), 'unresolved-link-diagnostic-has-reason-code' );
		$assert( 'rewrite_or_create_internal_target' === ( $link['suggested_repair_class'] ?? '' ), 'unresolved-link-diagnostic-has-repair-class' );
		$assert( '' !== (string) ( $link['source_path'] ?? '' ), 'unresolved-link-diagnostic-has-source-path' );
		$assert( '' !== (string) ( $link['context']['href'] ?? '' ), 'unresolved-link-diagnostic-has-href-context' );
	}

	$assert( ! empty( $local_assets ), 'local-asset-record-present' );
	$assert( in_array( 'docs/images/flow.svg', wp_list_pluck( $local_assets, 'key' ), true ), 'markdown-local-asset-materialized' );

	$source_refs = $report['source_documents']['diagnostic_refs']['unresolved_link_count'] ?? array();
	$assert( is_array( $source_refs ) && ! empty( $source_refs ), 'source-document-count-links-to-diagnostics' );
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

WP_CLI::line( sprintf( 'OK: mixed source link rewrite smoke passed (%d assertions)', $assertions ) );
