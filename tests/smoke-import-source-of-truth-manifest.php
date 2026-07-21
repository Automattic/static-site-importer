<?php
/**
 * Smoke test: canonical v2 imports preserve protected pages and source-of-truth state.
 *
 * Run inside a WordPress site:
 * wp eval-file tests/smoke-import-source-of-truth-manifest.php --skip-plugins
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
if ( is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}

$failures = array();
$assertions = 0;
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

$protected_page = get_page_by_path( 'protected', OBJECT, 'page' );
$protected_id = wp_insert_post(
	array_filter(
		array(
			'ID' => $protected_page instanceof WP_Post ? $protected_page->ID : 0,
			'post_title' => 'Protected',
			'post_name' => 'protected',
			'post_status' => 'publish',
			'post_type' => 'page',
			'post_content' => '<!-- wp:paragraph --><p>Protected original content.</p><!-- /wp:paragraph -->',
		),
		static fn( $value ): bool => 0 !== $value
	),
	true
);
$assert( ! is_wp_error( $protected_id ), 'protected-page-created', is_wp_error( $protected_id ) ? $protected_id->get_error_message() : '' );
update_option( 'static_site_importer_protected_pages', array( 'protected', (string) $protected_id ) );
delete_post_meta( (int) $protected_id, '_static_site_importer_provenance' );
delete_post_meta( (int) $protected_id, '_static_site_importer_reconciliation_identity' );

$user_page = get_page_by_path( 'ssi-user-source-of-truth', OBJECT, 'page' );
$user_id = wp_insert_post(
	array_filter(
		array(
			'ID' => $user_page instanceof WP_Post ? $user_page->ID : 0,
			'post_title' => 'User Source Of Truth',
			'post_name' => 'ssi-user-source-of-truth',
			'post_status' => 'publish',
			'post_type' => 'page',
			'post_content' => '<!-- wp:paragraph --><p>User original content.</p><!-- /wp:paragraph -->',
		),
		static fn( $value ): bool => 0 !== $value
	),
	true
);
$assert( ! is_wp_error( $user_id ), 'user-page-created', is_wp_error( $user_id ) ? $user_id->get_error_message() : '' );

$import = static function ( array $files, string $hash, string $run ) {
	return Static_Site_Importer_Theme_Generator::import_website_artifact(
		array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1', 'id' => 'artifact-source-of-truth-smoke', 'hash' => $hash, 'hash_algo' => 'sha256', 'entrypoint' => 'index.html', 'files' => $files ),
		array( 'name' => 'Source Of Truth Smoke', 'slug' => 'source-of-truth-smoke-theme', 'overwrite' => true, 'activate' => false, 'import_run_id' => $run, 'write_theme_report_artifacts' => true )
	);
};

$result = $import(
	array(
		array( 'path' => 'index.html', 'content' => '<main><h1>Source Of Truth Home</h1></main>' ),
		array( 'path' => 'protected.html', 'content' => '<main><h1>Protected Replacement</h1></main>' ),
		array( 'path' => 'old.html', 'content' => '<main><h1>Stale Source Of Truth</h1></main>' ),
		array( 'path' => 'assets/site.css', 'content' => 'body{color:#111}' ),
		array( 'path' => 'assets/old-image.txt', 'content' => 'old generated asset' ),
	),
	'sha256:source-of-truth-smoke',
	'ssi-source-of-truth-smoke-run'
);
$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$report = json_decode( $read( $result['report_path'] ), true );
	$manifest = json_decode( $read( $result['manifest_path'] ), true );
	$home_id = (int) ( $result['pages']['index.html'] ?? 0 );
	$stale_id = (int) ( $result['pages']['old.html'] ?? 0 );
	$theme_dir = (string) $result['theme_dir'];
	$stale_asset_path = $theme_dir . '/assets/assets/old-image.txt';
	$unknown_file_path = $theme_dir . '/templates/user-added.html';
	$home_meta = json_decode( (string) get_post_meta( $home_id, '_static_site_importer_provenance', true ), true );

	$assert( is_file( $theme_dir . '/templates/front-page.html' ), 'canonical-front-page-template-emitted' );
	$assert( is_file( $stale_asset_path ), 'canonical-asset-path-emitted' );
	$assert( ! is_file( $theme_dir . '/templates/page-protected.html' ) && ! is_file( $theme_dir . '/templates/page-old.html' ), 'legacy-page-templates-not-emitted' );
	$assert( $stale_id > 0, 'first-import-created-stale-page' );
	$assert( false !== file_put_contents( $unknown_file_path, '<!-- user file -->' ), 'unknown-file-created' );
	$assert( $manifest === ( $report['source_of_truth'] ?? array() ), 'report-embeds-written-manifest' );
	$assert( 'ssi-source-of-truth-smoke-run' === ( $home_meta['import_run_id'] ?? '' ), 'owned-page-has-provenance-meta' );
	$assert( '' === (string) get_post_meta( (int) $protected_id, '_static_site_importer_provenance', true ), 'protected-page-has-no-provenance-meta' );
	$assert( '' === (string) get_post_meta( (int) $protected_id, '_static_site_importer_reconciliation_identity', true ), 'protected-page-has-no-reconciliation-identity-meta' );
	$assert( str_contains( (string) get_post_field( 'post_content', $protected_id ), 'Protected original content.' ), 'protected-page-content-unchanged' );
	$matches = $manifest['existing_matches']['pages'] ?? array();
	$protected_match = array_values( array_filter( $matches, static fn( array $row ): bool => (int) ( $row['post_id'] ?? 0 ) === (int) $protected_id ) );
	$assert( 1 === count( $protected_match ) && true === ( $protected_match[0]['protected'] ?? false ) && '/protected' === ( $protected_match[0]['route'] ?? '' ), 'manifest-records-protected-canonical-route-match' );
	$assert( ! isset( $report['generated_theme']['block_documents'][0]['core_html_block_count'] ), 'projection-omits-unreported-core-html-metric' );

	$reimport = $import(
		array(
			array( 'path' => 'index.html', 'content' => '<main><h1>Source Of Truth Home Updated</h1></main>' ),
			array( 'path' => 'assets/site.css', 'content' => 'body{color:#222}' ),
		),
		'sha256:source-of-truth-smoke-reimport',
		'ssi-source-of-truth-smoke-reimport-run'
	);
	$assert( ! is_wp_error( $reimport ), 'reimport-succeeds', is_wp_error( $reimport ) ? $reimport->get_error_message() : '' );
	if ( ! is_wp_error( $reimport ) ) {
		$reimport_report = json_decode( $read( $reimport['report_path'] ), true );
		$reimport_manifest = json_decode( $read( $reimport['manifest_path'] ), true );
		$reimport_home_id = (int) ( $reimport['pages']['index.html'] ?? 0 );
		$reimport_home_meta = json_decode( (string) get_post_meta( $reimport_home_id, '_static_site_importer_provenance', true ), true );
		$deleted_paths = array_column( $reimport_manifest['cleanup']['deleted'] ?? array(), 'path' );
		$stale_page_ids = array_map( 'intval', array_column( $reimport_manifest['cleanup']['pages']['stale_pages'] ?? array(), 'post_id' ) );

		$assert( $home_id === $reimport_home_id && str_contains( (string) get_post_field( 'post_content', $home_id ), 'Source Of Truth Home Updated' ), 'reimport-updates-same-home-page' );
		$assert( 'ssi-source-of-truth-smoke-reimport-run' === ( $reimport_home_meta['import_run_id'] ?? '' ) && ( $home_meta['content_hash'] ?? '' ) !== ( $reimport_home_meta['content_hash'] ?? '' ), 'reimport-updates-provenance-and-content-hash' );
		$assert( ! is_file( $stale_asset_path ) && in_array( 'assets/assets/old-image.txt', $deleted_paths, true ), 'reimport-removes-and-records-stale-canonical-asset' );
		$assert( is_file( $unknown_file_path ), 'reimport-preserves-unknown-user-file' );
		$assert( str_contains( (string) get_post_field( 'post_content', $protected_id ), 'Protected original content.' ) && str_contains( (string) get_post_field( 'post_content', $user_id ), 'User original content.' ), 'reimport-preserves-protected-and-user-pages' );
		$assert( in_array( $stale_id, $stale_page_ids, true ) && 'publish' === get_post_status( $stale_id ) && 'report_only' === ( $reimport_manifest['cleanup']['pages']['action'] ?? '' ), 'reimport-reports-but-preserves-stale-page' );
		$assert( $reimport_manifest === ( $reimport_report['source_of_truth'] ?? array() ), 'reimport-report-embeds-cleanup-manifest' );
	}
}

update_option( 'static_site_importer_protected_pages', array() );
if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}
echo 'OK: canonical source-of-truth manifest smoke passed (' . $assertions . " assertions)\n";
