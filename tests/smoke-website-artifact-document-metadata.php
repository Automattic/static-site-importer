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
				'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ember & Rye</title><meta name="description" content="Wood-fired bakery"><link rel="stylesheet" href="/assets/site.css"></head><body><header class="site-header"><a href="/">Ember & Rye</a></header><main><section class="hero"><h1>Fire, flour, patience.</h1><p>Small-batch loaves.</p></section></main></body></html>',
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
	$pattern   = $read( $theme_dir . '/patterns/page-home.php' );
	$report    = json_decode( $read( $result['report_path'] ), true );
	$documents = array();
	foreach ( $report['generated_theme']['block_documents'] ?? array() as $document ) {
		if ( is_array( $document ) && isset( $document['path'] ) ) {
			$documents[ $document['path'] ] = $document;
		}
	}
	$metadata = $report['generated_theme']['document_metadata'] ?? array();

	$assert( str_contains( $pattern, 'Fire, flour, patience.' ), 'body-content-is-preserved' );
	$assert( ! str_contains( $pattern, '<meta' ), 'pattern-has-no-meta-fragments' );
	$assert( ! str_contains( $pattern, '<title' ), 'pattern-has-no-title-fragments' );
	$assert( ! str_contains( $pattern, '<link' ), 'pattern-has-no-link-fragments' );
	$assert( 0 === ( $documents['patterns/page-home.php']['core_html_block_count'] ?? null ), 'report-pattern-has-zero-core-html' );
	$assert( 0 === ( $report['quality']['core_html_block_count'] ?? null ), 'quality-core-html-count-is-zero' );
	$assert( 'static-site-importer/document-metadata/v1' === ( $metadata['schema'] ?? '' ), 'metadata-contract-is-recorded' );
	$assert( 'Ember & Rye' === ( $metadata['title'] ?? '' ), 'title-is-preserved-in-metadata' );
	$assert( 'utf-8' === ( $metadata['meta'][0]['charset'] ?? '' ), 'charset-meta-is-preserved-in-metadata' );
	$assert( 'viewport' === ( $metadata['meta'][1]['name'] ?? '' ), 'viewport-meta-is-preserved-in-metadata' );
	$assert( '/assets/site.css' === ( $metadata['links'][0]['href'] ?? '' ), 'stylesheet-link-is-preserved-in-metadata' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: website artifact document metadata smoke passed (' . $assertions . " assertions)\n";
