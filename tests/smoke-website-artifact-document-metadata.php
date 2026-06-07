<?php
/**
 * Smoke test: full-document website artifacts route head metadata out of blocks.
 *
 * Run inside a WordPress site with BAC/BFB available:
 * wp eval-file tests/smoke-website-artifact-document-metadata.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema' => 'block-artifact-compiler/website-artifact/v1',
		'files'  => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ember & Rye</title><meta name="description" content="Wood-fired bakery"><link rel="stylesheet" href="/assets/site.css"></head><body><header class="site-header"><a href="/">Ember & Rye</a></header><main><section class="hero"><h1>Fire, flour, patience.</h1><p>Small-batch loaves.</p></section></main><script src="assets/js/main.js" defer></script></body></html>',
			),
			array(
				'path'    => 'assets/js/main.js',
				'content' => 'document.documentElement.dataset.ready = "true";',
			),
		),
	),
	array(
		'name'        => 'Ember Rye Document Metadata',
		'slug'        => 'ember-rye-document-metadata-smoke',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir = $result['theme_dir'];
	$report    = json_decode( $read( $result['report_path'] ), true );
	$page_ids  = array_values( $result['pages'] ?? array() );
	$page_id   = (int) ( $page_ids[0] ?? 0 );
	$page      = $page_id > 0 ? get_post( $page_id ) : null;
	$content   = $page instanceof WP_Post ? $page->post_content : '';
	$documents = array();
	$pattern_documents = array();
	$scripts = $report['assets']['scripts'] ?? array();
	foreach ( $report['generated_theme']['block_documents'] ?? array() as $document ) {
		if ( is_array( $document ) && isset( $document['path'] ) ) {
			$documents[ $document['path'] ] = $document;
			if ( str_starts_with( (string) $document['path'], 'patterns/page-' ) ) {
				$pattern_documents[] = $document;
			}
		}
	}
	$metadata = $report['generated_theme']['document_metadata'] ?? array();

	$assert( array() === $pattern_documents, 'single-document-import-does-not-generate-page-pattern-copy' );
	$assert( str_contains( $content, 'Fire, flour, patience.' ), 'body-content-is-preserved' );
	$assert( ! str_contains( $content, '<meta' ), 'page-content-has-no-meta-fragments' );
	$assert( ! str_contains( $content, '<title' ), 'page-content-has-no-title-fragments' );
	$assert( ! str_contains( $content, '<link' ), 'page-content-has-no-link-fragments' );
	$assert( ! str_contains( $content, '<script' ), 'page-content-has-no-script-fragments' );
	$assert( 0 === ( $documents['posts/page-home.post_content']['core_html_block_count'] ?? null ), 'report-page-content-has-zero-core-html' );
	$assert( 0 === ( $report['quality']['core_html_block_count'] ?? null ), 'quality-core-html-count-is-zero' );
	$assert( 'static-site-importer/document-metadata/v1' === ( $metadata['schema'] ?? '' ), 'metadata-contract-is-recorded' );
	$assert( 'Ember & Rye' === ( $metadata['title'] ?? '' ), 'title-is-preserved-in-metadata' );
	$assert( 'utf-8' === ( $metadata['meta'][0]['charset'] ?? '' ), 'charset-meta-is-preserved-in-metadata' );
	$assert( 'viewport' === ( $metadata['meta'][1]['name'] ?? '' ), 'viewport-meta-is-preserved-in-metadata' );
	$assert( '/assets/site.css' === ( $metadata['links'][0]['href'] ?? '' ), 'stylesheet-link-is-preserved-in-metadata' );
	$assert( str_ends_with( (string) ( $scripts[0]['src'] ?? '' ), 'assets/js/main.js' ), 'script-src-is-preserved-in-asset-metadata' );
	$assert( true === ( $scripts[0]['attributes']['defer'] ?? false ), 'script-defer-is-preserved-in-asset-metadata' );
}

$multi_page_result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema'     => 'block-artifact-compiler/website-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<!doctype html><html><head><title>Home Page</title></head><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>',
			),
			array(
				'path'    => 'website/menu.html',
				'content' => '<!doctype html><html><head><title>Menu Page</title></head><body><main><h1>Menu</h1><p>Pizza and small plates.</p></main></body></html>',
			),
			array(
				'path'    => 'website/contact.html',
				'content' => '<main><h1>Contact</h1><p>Email us.</p></main>',
			),
		)
	),
	array(
		'name'        => 'Ember Rye Multi Page Artifact',
		'slug'        => 'ember-rye-multi-page-artifact-smoke',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $multi_page_result ), 'multi-page-import-succeeds', is_wp_error( $multi_page_result ) ? $multi_page_result->get_error_message() : '' );

if ( ! is_wp_error( $multi_page_result ) ) {
	$multi_report    = json_decode( $read( $multi_page_result['report_path'] ), true );
	$source_docs     = $multi_report['source_documents'] ?? array();
	$bac_documents   = $source_docs['bac_documents'] ?? array();
	$compiled_site   = $multi_report['block_artifact_compiler']['compiled_site'] ?? array();
	$block_documents = $multi_report['generated_theme']['block_documents'] ?? array();
	$documents_by_source = array();
	$pattern_documents = array();
	foreach ( $bac_documents as $document ) {
		if ( is_array( $document ) && isset( $document['source_path'] ) ) {
			$documents_by_source[ $document['source_path'] ] = $document;
		}
	}
	foreach ( $block_documents as $document ) {
		if ( is_array( $document ) && str_starts_with( (string) ( $document['path'] ?? '' ), 'patterns/page-' ) ) {
			$pattern_documents[] = $document;
		}
	}

	$assert( 3 === ( $source_docs['bac_document_count'] ?? null ), 'multi-page-bac-document-count' );
	$assert( 'block_artifact_compiler' === ( $source_docs['source'] ?? '' ), 'multi-page-source-is-bac' );
	$assert( 'home' === ( $documents_by_source['website/index.html']['slug'] ?? '' ), 'entry-index-materializes-as-home' );
	$assert( str_ends_with( (string) ( $documents_by_source['website/index.html']['permalink'] ?? '' ), '/' ), 'entry-index-has-front-page-permalink' );
	$assert( 'menu' === ( $documents_by_source['website/menu.html']['slug'] ?? '' ), 'menu-page-materializes' );
	$assert( 'contact' === ( $documents_by_source['website/contact.html']['slug'] ?? '' ), 'contact-page-materializes' );
	$assert( 'block-artifact-compiler/compiled-site/v1' === ( $compiled_site['schema'] ?? '' ), 'compiled-site-contract-is-recorded' );
	$assert( 3 === ( $compiled_site['page_count'] ?? null ), 'compiled-site-page-count-is-recorded' );
	$assert( 'menu' === ( $compiled_site['pages'][1]['route_key'] ?? '' ), 'compiled-site-route-key-is-recorded' );
	$assert( array() === $pattern_documents, 'bac-document-import-does-not-generate-page-pattern-copies' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: website artifact document metadata smoke passed (' . $assertions . " assertions)\n";
