<?php
/**
 * Smoke test: full-document website artifacts route head metadata out of blocks.
 *
 * Run inside a WordPress site with Blocks Engine php-transformer available:
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
	if ( '' === $path ) {
		return '';
	}

	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
		'files'  => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ember & Rye</title><meta name="description" content="Wood-fired bakery"><link rel="stylesheet" href="/assets/site.css"></head><body><header class="site-header"><a href="/">Ember & Rye</a></header><main><section class="hero"><h1>Fire, flour, patience.</h1><p>Small-batch loaves.</p><div class="contact-actions"><a class="btn btn-ghost" href="/contact">Visit us</a></div><div class="hours-table"><div><span>Tue</span><strong>4–10pm</strong></div></div><figure><img class="rounded-photo reveal" src="assets/logo.svg" alt="Bakery mark"></figure><div class="glow-orb"></div></section></main><script src="assets/js/main.js" defer></script></body></html>',
			),
			array(
				'path'    => 'assets/site.css',
				'content' => '.photo-collage{display:grid;grid-template-columns:1fr 1fr;gap:24px}.photo-collage img:first-child{grid-row:span 2;height:100%}.form-card label{display:grid;gap:7px}.form-card input,.form-card select,.form-card textarea{width:100%;border:1px solid #ccc}.btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 20px}.contact-actions .btn-ghost{background:white;color:black}.hours-table div{display:flex;justify-content:space-between;gap:18px;padding:16px}.glow-orb{position:absolute}.reveal{opacity:0;transform:translateY(1rem)}@media (max-width:560px){.contact-actions .btn{width:100%}}',
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
		'import_run_id' => 'ssi-smoke-run-001',
		'artifact_hash' => 'sha256:ember-rye-smoke',
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir = $result['theme_dir'];
	$report    = isset( $result['import_report'] ) && is_array( $result['import_report'] ) ? $result['import_report'] : array();
	$manifest  = json_decode( $read( $result['manifest_path'] ?? '' ), true );
	$validation_result = isset( $result['import_validation_result'] ) && is_array( $result['import_validation_result'] ) ? $result['import_validation_result'] : array();
	$finding_packets   = isset( $result['finding_packets'] ) && is_array( $result['finding_packets'] ) ? $result['finding_packets'] : array();
	$page_ids  = array_values( $result['pages'] ?? array() );
	$page_id   = (int) ( $page_ids[0] ?? 0 );
	$progress_events = isset( $result['progress_events'] ) && is_array( $result['progress_events'] ) ? $result['progress_events'] : array();
	$page      = $page_id > 0 ? get_post( $page_id ) : null;
	$content   = $page instanceof WP_Post ? $page->post_content : '';
	$documents = array();
	$pattern_documents = array();
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
	$scripts  = $metadata['scripts'] ?? array();

	$assert( array() === $pattern_documents, 'single-document-import-does-not-generate-page-pattern-copy' );
	$assert( str_contains( $content, 'Fire, flour, patience.' ), 'body-content-is-preserved' );
	$single_template_parts_by_path = array();
	foreach ( $template_parts as $template_part ) {
		if ( is_array( $template_part ) && isset( $template_part['path'] ) ) {
			$single_template_parts_by_path[ $template_part['path'] ] = $template_part;
		}
	}
	$assert( isset( $single_template_parts_by_path['parts/header.html'] ), 'single-document-import-generates-source-header-template-part' );
	$assert( ! isset( $single_template_parts_by_path['parts/footer.html'] ), 'single-document-import-without-footer-does-not-generate-footer-template-part' );
	$assert( is_file( $theme_dir . '/parts/header.html' ), 'single-document-import-writes-header-template-part-file' );
	$assert( str_contains( $read( $theme_dir . '/templates/front-page.html' ), 'wp:template-part {"slug":"header"' ), 'single-document-template-references-header-part' );
	$assert( ! str_contains( $read( $theme_dir . '/templates/front-page.html' ), 'wp:template-part {"slug":"footer"' ), 'single-document-template-does-not-reference-missing-footer-part' );
	$assert( str_contains( $content, 'logo.svg' ) && ! str_contains( $content, 'src="assets/logo.svg"' ), 'block-markup-local-asset-is-rewritten' );
	$assert( ! str_contains( $content, 'src="assets/logo.svg"' ), 'block-markup-local-asset-source-url-is-removed' );
	$assert( ! str_contains( $content, '<meta' ), 'page-content-has-no-meta-fragments' );
	$assert( ! str_contains( $content, '<title' ), 'page-content-has-no-title-fragments' );
	$assert( ! str_contains( $content, '<link' ), 'page-content-has-no-link-fragments' );
	$assert( ! str_contains( $content, '<script' ), 'page-content-has-no-script-fragments' );
	$assert( ! empty( $report['quality']['pass'] ), 'canonical-plan-quality-passes' );
	$assert( isset( $report['quality']['metrics'] ) || isset( $report['quality']['score'] ), 'canonical-plan-quality-is-reported-without-fabricated-core-html-count' );
	$assert( '' === ( $result['report_path'] ?? '' ), 'theme-report-artifact-is-not-written-by-default' );
	$assert( '' === ( $result['validation_result_path'] ?? '' ), 'theme-validation-artifact-is-not-written-by-default' );
	$assert( '' === ( $result['finding_packets_path'] ?? '' ), 'theme-finding-packets-artifact-is-not-written-by-default' );
	$assert( ! is_file( $theme_dir . '/import-report.json' ), 'theme-report-file-is-absent-by-default' );
	$assert( ! is_file( $theme_dir . '/import-validation-result.json' ), 'theme-validation-file-is-absent-by-default' );
	$assert( ! is_file( $theme_dir . '/finding-packets.json' ), 'theme-finding-packets-file-is-absent-by-default' );
	$assert( is_file( $result['manifest_path'] ?? '' ), 'source-of-truth-manifest-is-written' );
	$assert( 'ssi-smoke-run-001' === ( $report['import_run_id'] ?? '' ), 'report-includes-import-run-id' );
	$assert( 'sha256:ember-rye-smoke' === ( $report['source_artifact']['hash'] ?? '' ), 'report-includes-source-artifact-hash' );
	$assert( 'static-site-importer/source-of-truth-manifest/v1' === ( $report['source_of_truth']['schema'] ?? '' ), 'report-includes-source-of-truth-schema' );
	$assert( 'wp-codebox/live-progress-event/v1' === ( $progress_events[0]['schema'] ?? '' ), 'progress-event-uses-canonical-schema' );
	$assert( 'ssi.materialization.completed' === ( $progress_events[0]['phase'] ?? '' ), 'progress-event-materialization-phase' );
	$assert( 100 === (int) ( $progress_events[0]['progress']['percent'] ?? 0 ), 'progress-event-materialization-complete-percent' );
	$assert( 'ssi.saved.completed' === ( $progress_events[2]['phase'] ?? '' ), 'progress-event-saved-state' );
	$assert( 'ssi-smoke-run-001' === ( $manifest['import_run_id'] ?? '' ), 'manifest-includes-import-run-id' );
	$assert( 'sha256:ember-rye-smoke' === ( $manifest['artifact']['hash'] ?? '' ), 'manifest-includes-source-artifact-hash' );
	$assert( 'index.html' === ( $manifest['desired']['pages'][0]['source_path'] ?? '' ), 'manifest-includes-desired-page-source' );
	$assert( $page_id === (int) ( $manifest['desired']['pages'][0]['materialized_post_id'] ?? 0 ), 'manifest-includes-materialized-page-id' );
	$assert( 'static-site-importer-manifest.json' === ( $manifest['manifest_path'] ?? '' ), 'manifest-reports-relative-manifest-path' );
	$assert( ! in_array( 'import-report.json', array_column( $manifest['desired']['files'] ?? array(), 'path' ), true ), 'manifest-omits-report-file-target-by-default' );
	$assert( '_static_site_importer_provenance' === ( $manifest['desired']['pages'][0]['provenance_meta_key'] ?? '' ), 'manifest-identifies-page-provenance-meta-key' );
	$provenance = json_decode( (string) get_post_meta( $page_id, '_static_site_importer_provenance', true ), true );
	$assert( 'ssi-smoke-run-001' === ( $provenance['import_run_id'] ?? '' ), 'page-provenance-meta-includes-import-run-id' );
	$assert( 'index.html' === ( $provenance['source_path'] ?? '' ), 'page-provenance-meta-includes-source-path' );
	$assert( 'blocks-engine/import-validation-result/v1' === ( $validation_result['schema'] ?? '' ), 'validation-result-schema' );
	$assert( 'ImportValidationResult' === ( $validation_result['artifact_type'] ?? '' ), 'validation-result-artifact-type' );
	$assert( 'passed' === ( $validation_result['status'] ?? '' ), 'validation-result-status-passed' );
	$visual_parity = $report['visual_parity_artifacts'] ?? array();
	$visual_parity_validation = $validation_result['visual_parity_artifacts'] ?? array();
	$assert( 'static-site-importer/visual-parity-artifacts/v1' === ( $visual_parity['schema'] ?? '' ), 'visual-parity-artifact-schema' );
	$assert( 'pending' === ( $visual_parity['status'] ?? '' ), 'visual-parity-artifacts-pending-until-runtime-capture' );
	$assert( 'codebox_runtime' === ( $visual_parity['owner'] ?? '' ), 'visual-parity-artifacts-owned-by-codebox-runtime' );
	$assert( 'captured' === ( $visual_parity['artifacts']['import_report']['status'] ?? '' ), 'visual-parity-import-report-ref-captured' );
	$assert( 'import-report.json' === ( $visual_parity['artifacts']['import_report']['ref']['artifact_name'] ?? '' ), 'visual-parity-import-report-ref-name' );
	$assert( 'pending' === ( $visual_parity['artifacts']['source_screenshot']['status'] ?? '' ), 'visual-parity-source-screenshot-pending' );
	$assert( 'not_captured' === ( $visual_parity['artifacts']['visual_diff']['capture_state'] ?? '' ), 'visual-parity-diff-not-captured' );
	$assert( $visual_parity === $visual_parity_validation, 'validation-result-embeds-visual-parity-artifacts' );
	$assert( ! static_site_importer_smoke_contains_local_path( $visual_parity ), 'visual-parity-artifacts-contain-no-local-paths' );
	$assert( 'blocks-engine/finding-packets/v1' === ( $finding_packets['schema'] ?? '' ), 'finding-packets-schema' );
	$assert( 'FindingPacketSet' === ( $finding_packets['artifact_type'] ?? '' ), 'finding-packets-artifact-type' );
	$assert( 'static-site-importer/document-metadata/v1' === ( $metadata['schema'] ?? '' ), 'metadata-contract-is-recorded' );
	$assert( 'Ember & Rye' === ( $metadata['title'] ?? '' ), 'title-is-preserved-in-metadata' );
	$assert( 'utf-8' === ( $metadata['meta'][0]['charset'] ?? '' ), 'charset-meta-is-preserved-in-metadata' );
	$assert( 'viewport' === ( $metadata['meta'][1]['name'] ?? '' ), 'viewport-meta-is-preserved-in-metadata' );
	$assert( str_ends_with( (string) ( $metadata['links'][0]['href'] ?? '' ), 'assets/assets/site.css' ), 'stylesheet-link-is-resolved-to-the-declared-theme-asset' );
	$assert( str_ends_with( (string) ( $scripts[0]['src'] ?? '' ), 'assets/assets/js/main.js' ), 'script-src-is-resolved-to-the-declared-theme-asset' );
	$assert( 'body' === ( $scripts[0]['placement'] ?? '' ), 'script-placement-is-preserved-in-document-metadata' );
	$assert( true === ( $scripts[0]['defer'] ?? false ), 'script-defer-is-preserved-in-document-metadata' );
	$bootstrap = $read( $theme_dir . '/functions.php' );
	$assert( str_contains( $bootstrap, "get_theme_file_uri( 'assets/assets/site.css' )" ), 'theme-bootstrap-enqueues-the-canonical-stylesheet', $bootstrap );
}

$missing_template_parts_result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
		'files'  => array(
			array(
				'path'    => 'no-header.html',
				'content' => '<main><h1>No Header</h1><p>This artifact has no compiler template parts.</p></main>',
			),
		),
	),
	array(
		'name'                         => 'No Header Artifact',
		'slug'                         => 'no-header-artifact-smoke',
		'overwrite'                    => true,
		'activate'                     => false,
		'write_theme_report_artifacts' => true,
	)
);

$assert( ! is_wp_error( $missing_template_parts_result ), 'missing-template-parts-import-succeeds', is_wp_error( $missing_template_parts_result ) ? $missing_template_parts_result->get_error_message() : '' );
if ( ! is_wp_error( $missing_template_parts_result ) ) {
	$missing_report = json_decode( $read( $missing_template_parts_result['report_path'] ), true );
	$missing_template_parts = $missing_report['generated_theme']['template_parts'] ?? array();
	$missing_template       = $read( $missing_template_parts_result['theme_dir'] . '/templates/front-page.html' );
	$assert( array() === $missing_template_parts, 'missing-template-parts-does-not-generate-header' );
	$assert( ! is_file( $missing_template_parts_result['theme_dir'] . '/parts/header.html' ), 'missing-template-parts-does-not-write-header-file' );
	$assert( ! str_contains( $missing_template, 'wp:template-part {"slug":"header"' ), 'missing-template-parts-template-does-not-reference-header' );
	$assert( ! str_contains( $missing_template, 'wp:navigation' ), 'missing-template-parts-template-does-not-include-navigation' );
}

$multi_page_result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
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
		'name'                         => 'Ember Rye Multi Page Artifact',
		'slug'                         => 'ember-rye-multi-page-artifact-smoke',
		'overwrite'                    => true,
		'activate'                     => false,
		'write_theme_report_artifacts' => true,
	)
);

$assert( ! is_wp_error( $multi_page_result ), 'multi-page-import-succeeds', is_wp_error( $multi_page_result ) ? $multi_page_result->get_error_message() : '' );

if ( ! is_wp_error( $multi_page_result ) ) {
	$multi_report    = json_decode( $read( $multi_page_result['report_path'] ), true );
	$source_docs     = $multi_report['source_documents'] ?? array();
	$blocks_engine_documents = $source_docs['blocks_engine_documents'] ?? array();
	$wordpress_site_plan = $multi_report['blocks_engine']['wordpress_site_plan'] ?? array();
	$block_documents = $multi_report['generated_theme']['block_documents'] ?? array();
	$template_parts  = $multi_report['generated_theme']['template_parts'] ?? array();
	$documents_by_source = array();
	$pattern_documents = array();
	$template_parts_by_path = array();
	foreach ( $blocks_engine_documents as $document ) {
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

	$assert( 3 === ( $source_docs['blocks_engine_document_count'] ?? null ), 'multi-page-blocks-engine-document-count' );
	$assert( 3 === ( $source_docs['counts_by_format']['html'] ?? null ), 'multi-page-html-source-document-count' );
	$assert( 0 === ( $source_docs['counts_by_format']['markdown'] ?? null ), 'multi-page-markdown-source-document-count' );
	$assert( 0 === ( $source_docs['counts_by_format']['mdx'] ?? null ), 'multi-page-mdx-source-document-count' );
	$assert( 'blocks_engine' === ( $source_docs['source'] ?? '' ), 'multi-page-source-is-blocks-engine' );
	$assert( 'home' === ( $documents_by_source['website/index.html']['slug'] ?? '' ), 'entry-index-materializes-as-home' );
	$assert( str_ends_with( (string) ( $documents_by_source['website/index.html']['permalink'] ?? '' ), '/' ), 'entry-index-has-front-page-permalink' );
	$assert( 'menu' === ( $documents_by_source['website/menu.html']['slug'] ?? '' ), 'menu-page-materializes' );
	$assert( 'contact' === ( $documents_by_source['website/contact.html']['slug'] ?? '' ), 'contact-page-materializes' );
	$assert( 'blocks-engine/wordpress-site-plan/v2' === ( $wordpress_site_plan['schema'] ?? '' ), 'wordpress-site-plan-contract-is-recorded' );
	$assert( 3 === count( $wordpress_site_plan['pages'] ?? array() ), 'wordpress-site-plan-page-count-is-recorded' );
	$assert( isset( $template_parts_by_path['parts/header.html'] ), 'multi-page-synthesizes-header-template-part' );
	$assert( isset( $template_parts_by_path['parts/footer.html'] ), 'multi-page-synthesizes-footer-template-part' );
	$assert( str_contains( $read( $multi_page_result['theme_dir'] . '/templates/front-page.html' ), '"slug":"header"' ), 'multi-page-template-references-synthesized-header-part' );
	$assert( str_contains( $read( $multi_page_result['theme_dir'] . '/templates/front-page.html' ), '"slug":"footer"' ), 'multi-page-template-references-synthesized-footer-part' );
	$assert( array() === $pattern_documents, 'blocks-engine-document-import-does-not-generate-page-pattern-copies' );
}

function static_site_importer_smoke_contains_local_path( $value ): bool {
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( static_site_importer_smoke_contains_local_path( $item ) ) {
				return true;
			}
		}

		return false;
	}

	if ( ! is_string( $value ) ) {
		return false;
	}

	return (bool) preg_match( '#^(?:/|[A-Za-z]:\\\\|file://|~[/\\\\]|(?:\.\.?[/\\\\]))#', $value );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: website artifact document metadata smoke passed (' . $assertions . " assertions)\n";
