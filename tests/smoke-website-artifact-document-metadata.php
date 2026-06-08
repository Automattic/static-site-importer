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
				'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ember & Rye</title><meta name="description" content="Wood-fired bakery"><link rel="stylesheet" href="/assets/site.css"></head><body><header class="site-header"><a href="/">Ember & Rye</a></header><main><section class="hero"><h1>Fire, flour, patience.</h1><p>Small-batch loaves.</p><div class="contact-actions"><a class="btn btn-ghost" href="/contact">Visit us</a></div><div class="hours-table"><div><span>Tue</span><strong>4–10pm</strong></div></div><figure><img class="rounded-photo reveal" src="assets/logo.svg" alt="Bakery mark"></figure></section></main><script src="assets/js/main.js" defer></script></body></html>',
			),
			array(
				'path'    => 'assets/site.css',
				'content' => '.photo-collage{display:grid;grid-template-columns:1fr 1fr;gap:24px}.photo-collage img:first-child{grid-row:span 2;height:100%}.form-card label{display:grid;gap:7px}.form-card input,.form-card select,.form-card textarea{width:100%;border:1px solid #ccc}.btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 20px}.contact-actions .btn-ghost{background:white;color:black}.hours-table div{display:flex;justify-content:space-between;gap:18px;padding:16px}@media (max-width:560px){.contact-actions .btn{width:100%}}',
			),
			array(
				'path'    => 'assets/logo.svg',
				'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5" fill="#c94f2d"/></svg>',
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
	$template_parts = $report['generated_theme']['template_parts'] ?? array();
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
	$assert( str_contains( $read( $theme_dir . '/parts/header.html' ), 'Ember &amp; Rye' ), 'bac-header-template-part-is-materialized' );
	$assert( isset( $template_parts[0]['path'] ) && 'parts/header.html' === $template_parts[0]['path'], 'bac-header-template-part-report-is-recorded' );
	$assert( str_contains( $content, 'logo.svg' ) && ! str_contains( $content, 'src="assets/logo.svg"' ), 'block-markup-local-asset-is-rewritten' );
	$assert( ! str_contains( $content, 'src="assets/logo.svg"' ), 'block-markup-local-asset-source-url-is-removed' );
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
	$style = $read( $theme_dir . '/style.css' );
	$assert( str_contains( $style, '.wp-block-group.photo-collage {display:grid;grid-template-columns:1fr 1fr;gap:24px}' ), 'source-display-rule-bridges-converted-group-wrapper' );
	$assert( str_contains( $style, '.wp-block-group.photo-collage > .wp-block-image:first-child, .wp-block-group.photo-collage > .wp-block-image:first-child img {grid-row:span 2;height:100%}' ), 'source-image-grid-rule-bridges-native-image-block-wrapper' );
	$assert( str_contains( $style, '.form-card .static-form-field {display:grid;gap:7px}' ), 'source-form-label-rule-bridges-static-form-field-wrapper' );
	$assert( str_contains( $style, '.form-card .static-form-control.static-form-input, .form-card .static-form-control.static-form-select, .form-card .static-form-control.static-form-textarea {width:100%;border:1px solid #ccc}' ), 'source-form-control-rule-bridges-static-form-control-wrapper' );
	$assert( str_contains( $style, '.contact-actions .wp-block-button.btn { width:100% }' ), 'source-button-layout-rule-bridges-core-button-wrapper' );
}

$missing_template_parts_result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema' => 'block-artifact-compiler/website-artifact/v1',
		'files'  => array(
			array(
				'path'    => 'no-header.html',
				'content' => '<main><h1>No Header</h1><p>This artifact has no compiler template parts.</p></main>',
			),
		),
	),
	array(
		'name'      => 'No Header Artifact',
		'slug'      => 'no-header-artifact-smoke',
		'overwrite' => true,
		'activate'  => false,
	)
);

$assert( is_wp_error( $missing_template_parts_result ), 'missing-template-parts-import-fails' );
$assert( 'static_site_importer_bac_template_parts_missing' === ( is_wp_error( $missing_template_parts_result ) ? $missing_template_parts_result->get_error_code() : '' ), 'missing-template-parts-error-code' );

$multi_page_result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema'     => 'block-artifact-compiler/website-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<!doctype html><html><head><title>Home Page</title></head><body><header><a href="/">Ember Rye</a><nav><a href="/menu.html">Menu</a></nav></header><main><h1>Home</h1><p>Welcome.</p></main><footer><p>Open daily.</p></footer></body></html>',
			),
			array(
				'path'    => 'website/menu.html',
				'content' => '<!doctype html><html><head><title>Menu Page</title></head><body><header><a href="/">Ember Rye</a><nav><a href="/menu.html">Menu</a></nav></header><main><h1>Menu</h1><p>Pizza and small plates.</p></main><footer><p>Open daily.</p></footer></body></html>',
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
	)
);

$assert( ! is_wp_error( $multi_page_result ), 'multi-page-import-succeeds', is_wp_error( $multi_page_result ) ? $multi_page_result->get_error_message() : '' );

if ( ! is_wp_error( $multi_page_result ) ) {
	$multi_report    = json_decode( $read( $multi_page_result['report_path'] ), true );
	$source_docs     = $multi_report['source_documents'] ?? array();
	$bac_documents   = $source_docs['bac_documents'] ?? array();
	$compiled_site   = $multi_report['block_artifact_compiler']['compiled_site'] ?? array();
	$block_documents = $multi_report['generated_theme']['block_documents'] ?? array();
	$template_parts  = $multi_report['generated_theme']['template_parts'] ?? array();
	$documents_by_source = array();
	$pattern_documents = array();
	$template_parts_by_path = array();
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
	foreach ( $template_parts as $template_part ) {
		if ( is_array( $template_part ) && isset( $template_part['path'] ) ) {
			$template_parts_by_path[ $template_part['path'] ] = $template_part;
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
	$assert( str_contains( $read( $multi_page_result['theme_dir'] . '/parts/header.html' ), 'Ember Rye' ), 'multi-page-bac-header-template-part-is-materialized' );
	$assert( str_contains( $read( $multi_page_result['theme_dir'] . '/parts/footer.html' ), 'Open daily.' ), 'multi-page-bac-footer-template-part-is-materialized' );
	$assert( isset( $template_parts_by_path['parts/header.html'] ), 'multi-page-header-template-part-is-recorded' );
	$assert( isset( $template_parts_by_path['parts/footer.html'] ), 'multi-page-footer-template-part-is-recorded' );
	$assert( str_contains( $read( $multi_page_result['theme_dir'] . '/templates/front-page.html' ), '"slug":"footer"' ), 'multi-page-template-references-bac-footer-part' );
	$assert( array() === $pattern_documents, 'bac-document-import-does-not-generate-page-pattern-copies' );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: website artifact document metadata smoke passed (' . $assertions . " assertions)\n";
