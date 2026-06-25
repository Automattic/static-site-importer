<?php
/**
 * Smoke test: website artifact imports write non-destructive source-of-truth provenance.
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

if ( ! function_exists( 'blocks_engine_php_transformer_compile_artifact' ) ) {
	function blocks_engine_php_transformer_compile_artifact( array $artifact, array $options = array() ): array {
		unset( $options );
		$files      = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$entry_path = isset( $artifact['entrypoint'] ) && is_scalar( $artifact['entrypoint'] ) ? (string) $artifact['entrypoint'] : '';
		$pages      = array();
		$assets     = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) || ! is_scalar( $file['path'] ) ) {
				continue;
			}

			$path = (string) $file['path'];
			if ( str_ends_with( $path, '.css' ) ) {
				$assets[] = array(
					'path'    => $path,
					'role'    => 'stylesheet',
					'kind'    => 'css',
					'content' => isset( $file['content'] ) && is_scalar( $file['content'] ) ? (string) $file['content'] : '',
				);
				continue;
			}
			if ( ! str_ends_with( $path, '.html' ) ) {
				$assets[] = array(
					'path'    => $path,
					'content' => isset( $file['content'] ) && is_scalar( $file['content'] ) ? (string) $file['content'] : '',
				);
				continue;
			}

			$is_entry = '' === $entry_path || $path === $entry_path;
			$basename = basename( $path );
			if ( 'protected.html' === $basename ) {
				$slug  = 'ssi-protected-source-of-truth';
				$title = 'Protected Source Of Truth';
			} elseif ( 'old.html' === $basename ) {
				$slug  = 'ssi-stale-source-of-truth';
				$title = 'Stale Source Of Truth';
			} else {
				$slug  = 'ssi-source-of-truth-home';
				$title = 'Source Of Truth Home';
			}
			$pages[]  = array(
				'source_path'  => $path,
				'entrypoint'   => $is_entry,
				'post_type'    => 'page',
				'slug'         => $slug,
				'title'        => $title,
				'block_markup' => '<!-- wp:paragraph --><p>Imported ' . esc_html( $title ) . '</p><!-- /wp:paragraph -->',
			);
		}

		return array(
			'schema'         => 'blocks-engine/php-transformer/result/v1',
			'status'         => 'success',
			'provenance'     => array(
				'source' => '' !== $entry_path ? $entry_path : 'artifact.json',
				'hash'   => isset( $artifact['hash'] ) && is_scalar( $artifact['hash'] ) ? (string) $artifact['hash'] : '',
			),
			'input'          => array( 'entry_path' => $entry_path ),
			'source_reports' => array(
				'materialization_plan' => array(
					'schema'     => 'blocks-engine/php-transformer/materialization-plan/v1',
					'entry_path' => $entry_path,
					'pages'      => $pages,
					'assets'     => $assets,
				),
			),
			'diagnostics'    => array(),
		);
	}
}

if ( is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};
$read       = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$protected_page = get_page_by_path( 'ssi-protected-source-of-truth', OBJECT, 'page' );
$protected_id   = wp_insert_post(
	array_filter(
		array(
			'ID'           => $protected_page instanceof WP_Post ? $protected_page->ID : 0,
			'post_title'   => 'Protected Source Of Truth',
			'post_name'    => 'ssi-protected-source-of-truth',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:paragraph --><p>Protected original content.</p><!-- /wp:paragraph -->',
		),
		static fn ( $value ): bool => 0 !== $value
	),
	true
);
$assert( ! is_wp_error( $protected_id ), 'protected-page-created', is_wp_error( $protected_id ) ? $protected_id->get_error_message() : '' );
update_option( 'static_site_importer_protected_pages', array( 'ssi-protected-source-of-truth', (string) $protected_id ) );

$user_page = get_page_by_path( 'ssi-user-source-of-truth', OBJECT, 'page' );
$user_id   = wp_insert_post(
	array_filter(
		array(
			'ID'           => $user_page instanceof WP_Post ? $user_page->ID : 0,
			'post_title'   => 'User Source Of Truth',
			'post_name'    => 'ssi-user-source-of-truth',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:paragraph --><p>User original content.</p><!-- /wp:paragraph -->',
		),
		static fn ( $value ): bool => 0 !== $value
	),
	true
);
$assert( ! is_wp_error( $user_id ), 'user-page-created', is_wp_error( $user_id ) ? $user_id->get_error_message() : '' );

$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
	array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
		'id'         => 'artifact-source-of-truth-smoke',
		'hash'       => 'sha256:source-of-truth-smoke',
		'hash_algo'  => 'sha256',
		'entrypoint' => 'index.html',
		'files'      => array(
			array( 'path' => 'index.html', 'content' => '<main><h1>Source Of Truth Home</h1></main>' ),
			array( 'path' => 'protected.html', 'content' => '<main><h1>Protected Replacement</h1></main>' ),
			array( 'path' => 'old.html', 'content' => '<main><h1>Stale Source Of Truth</h1></main>' ),
			array( 'path' => 'assets/site.css', 'content' => 'body{color:#111}' ),
			array( 'path' => 'assets/old-image.txt', 'content' => 'old generated asset' ),
		),
	),
	array(
		'name'          => 'Source Of Truth Smoke',
		'slug'          => 'source-of-truth-smoke-theme',
		'overwrite'     => true,
		'activate'      => false,
		'import_run_id' => 'ssi-source-of-truth-smoke-run',
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$report    = json_decode( $read( $result['report_path'] ), true );
	$manifest  = json_decode( $read( $result['manifest_path'] ), true );
	$page_ids  = $result['pages'] ?? array();
	$home_id   = (int) ( $page_ids['index.html'] ?? 0 );
	$protected = get_post( (int) $protected_id );
	$home_meta = json_decode( (string) get_post_meta( $home_id, '_static_site_importer_provenance', true ), true );
	$stale_id  = (int) ( $page_ids['old.html'] ?? 0 );
	$theme_dir = (string) $result['theme_dir'];
	$stale_template_path = $theme_dir . '/templates/page-ssi-protected-source-of-truth.html';
	$stale_page_template_path = $theme_dir . '/templates/page-ssi-stale-source-of-truth.html';
	$stale_asset_path    = $theme_dir . '/assets/materialized/assets/old-image.txt';
	$unknown_file_path   = $theme_dir . '/templates/user-added.html';
	$unknown_file_result = file_put_contents( $unknown_file_path, '<!-- user file -->' );

	$assert( is_file( $result['manifest_path'] ), 'manifest-file-written' );
	$assert( is_file( $stale_template_path ), 'first-import-generated-protected-template' );
	$assert( $stale_id > 0, 'first-import-created-stale-candidate-page' );
	$assert( is_file( $stale_page_template_path ), 'first-import-generated-stale-page-template' );
	$assert( is_file( $stale_asset_path ), 'first-import-generated-old-asset' );
	$assert( false !== $unknown_file_result, 'unknown-file-created' );
	$assert( 'ssi-source-of-truth-smoke-run' === ( $report['import_run_id'] ?? '' ), 'report-has-import-run-id' );
	$assert( 'sha256:source-of-truth-smoke' === ( $report['source_artifact']['hash'] ?? '' ), 'report-has-artifact-hash' );
	$assert( 'static-site-importer/source-of-truth-manifest/v1' === ( $report['source_of_truth']['schema'] ?? '' ), 'report-has-source-of-truth-schema' );
	$assert( $manifest === ( $report['source_of_truth'] ?? array() ), 'report-embeds-written-manifest' );
	$assert( 'ssi-source-of-truth-smoke-run' === ( $home_meta['import_run_id'] ?? '' ), 'owned-page-has-provenance-meta' );
	$assert( '' === (string) get_post_meta( (int) $protected_id, '_static_site_importer_provenance', true ), 'protected-page-has-no-provenance-meta' );
	$assert( $protected instanceof WP_Post && str_contains( $protected->post_content, 'Protected original content.' ), 'protected-page-content-unchanged' );
	$assert( ! empty( $manifest['desired']['pages'] ), 'manifest-has-desired-pages' );
	$assert( in_array( 'static-site-importer-manifest.json', array_column( $manifest['desired']['files'] ?? array(), 'path' ), true ), 'manifest-has-generated-manifest-file-target' );
	$assert( ! empty( $manifest['desired']['assets'] ), 'manifest-has-desired-assets' );
	$assert( in_array( true, array_column( $manifest['existing_matches']['pages'] ?? array(), 'protected' ), true ), 'manifest-reports-protected-existing-match' );
	$assert( true === ( $manifest['cleanup']['enabled'] ?? false ), 'manifest-cleanup-enabled' );

	$reimport = Static_Site_Importer_Theme_Generator::import_website_artifact(
		array(
			'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
			'id'         => 'artifact-source-of-truth-smoke',
			'hash'       => 'sha256:source-of-truth-smoke-reimport',
			'hash_algo'  => 'sha256',
			'entrypoint' => 'index.html',
			'files'      => array(
				array( 'path' => 'index.html', 'content' => '<main><h1>Source Of Truth Home Updated</h1></main>' ),
				array( 'path' => 'assets/site.css', 'content' => 'body{color:#222}' ),
			),
		),
		array(
			'name'          => 'Source Of Truth Smoke',
			'slug'          => 'source-of-truth-smoke-theme',
			'overwrite'     => true,
			'activate'      => false,
			'import_run_id' => 'ssi-source-of-truth-smoke-reimport-run',
		)
	);

	$assert( ! is_wp_error( $reimport ), 'reimport-succeeds', is_wp_error( $reimport ) ? $reimport->get_error_message() : '' );
	if ( ! is_wp_error( $reimport ) ) {
		$reimport_report   = json_decode( $read( $reimport['report_path'] ), true );
		$reimport_manifest = json_decode( $read( $reimport['manifest_path'] ), true );
		$deleted_paths     = array_column( $reimport_manifest['cleanup']['deleted'] ?? array(), 'path' );
		$protected_after   = get_post( (int) $protected_id );
		$user_after        = get_post( (int) $user_id );
		$stale_after       = get_post( $stale_id );
		$stale_pages       = $reimport_manifest['cleanup']['pages']['stale_pages'] ?? array();
		$stale_page_ids    = array_map( 'intval', array_column( is_array( $stale_pages ) ? $stale_pages : array(), 'post_id' ) );

		$assert( ! is_file( $stale_template_path ), 'reimport-removes-stale-generated-template' );
		$assert( ! is_file( $stale_page_template_path ), 'reimport-removes-stale-generated-page-template' );
		$assert( ! is_file( $stale_asset_path ), 'reimport-removes-stale-generated-asset' );
		$assert( is_file( $unknown_file_path ), 'reimport-preserves-unknown-user-file' );
		$assert( in_array( 'templates/page-ssi-protected-source-of-truth.html', $deleted_paths, true ), 'cleanup-records-stale-template-delete' );
		$assert( in_array( 'templates/page-ssi-stale-source-of-truth.html', $deleted_paths, true ), 'cleanup-records-stale-page-template-delete' );
		$assert( in_array( 'assets/materialized/assets/old-image.txt', $deleted_paths, true ), 'cleanup-records-stale-asset-delete' );
		$assert( 'report_only' === ( $reimport_manifest['cleanup']['pages']['action'] ?? '' ), 'stale-page-default-action-report-only' );
		$assert( in_array( $stale_id, $stale_page_ids, true ), 'stale-page-detected' );
		$assert( 1 === (int) ( $reimport_manifest['cleanup']['pages']['counts']['stale_pages'] ?? 0 ), 'stale-page-count-recorded' );
		$assert( 0 === (int) ( $reimport_manifest['cleanup']['pages']['counts']['pages_drafted'] ?? -1 ), 'stale-page-default-does-not-draft' );
		$assert( 0 === (int) ( $reimport_manifest['cleanup']['pages']['counts']['pages_deleted'] ?? -1 ), 'stale-page-default-does-not-delete' );
		$assert( 0 === (int) ( $reimport_manifest['cleanup']['counts']['pages_drafted'] ?? -1 ), 'cleanup-counts-record-no-page-drafting' );
		$assert( 0 === (int) ( $reimport_manifest['cleanup']['counts']['pages_deleted'] ?? -1 ), 'cleanup-counts-record-no-page-deletion' );
		$assert( 0 === (int) ( $reimport_manifest['cleanup']['protected']['pages_deleted'] ?? -1 ), 'cleanup-records-no-page-deletion' );
		$assert( $reimport_manifest === ( $reimport_report['source_of_truth'] ?? array() ), 'reimport-report-embeds-cleanup-manifest' );
		$assert( $protected_after instanceof WP_Post && str_contains( $protected_after->post_content, 'Protected original content.' ), 'reimport-protected-page-content-unchanged' );
		$assert( $user_after instanceof WP_Post && 'publish' === $user_after->post_status && str_contains( $user_after->post_content, 'User original content.' ), 'reimport-user-page-unchanged' );
		$assert( $stale_after instanceof WP_Post && 'publish' === $stale_after->post_status, 'reimport-stale-page-remains-published-in-report-only-mode' );
	}
}

update_option( 'static_site_importer_protected_pages', array() );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: import source-of-truth manifest smoke passed (' . $assertions . " assertions)\n";
