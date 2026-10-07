<?php
/**
 * Canonical theme export/reimport assertions for issue #1972.
 *
 * This script runs only inside the disposable two-site Docker acceptance.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This acceptance helper must run under WP-CLI.' );
}

$phase = (string) ( $args[0] ?? '' );
$json  = static function ( array $value ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-CLI output is machine-readable JSON acceptance evidence.
	echo (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) . "\n";
};
$fail  = static function ( string $message ): never {
	WP_CLI::error( $message );
};

if ( 'export' === $phase ) {
	$published_pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	);
	$source_page     = null;
	foreach ( $published_pages as $candidate_page ) {
		if ( str_contains( (string) $candidate_page->post_content, 'https://example.test/updated-map' ) ) {
			$source_page = $candidate_page;
			break;
		}
	}
	if ( null === $source_page ) {
		$fail( 'Could not locate the imported editor-acceptance page for export.' );
	}
	$result   = static_site_importer_ability_export_theme(
		array(
			'theme_slug'    => get_stylesheet(),
			'include_pages' => array( (int) $source_page->ID ),
		)
	);
	$artifact = is_array( $result['website_artifact'] ?? null ) ? $result['website_artifact'] : array();
	if ( empty( $result['success'] ) || 'blocks-engine/php-transformer/site-artifact/v1' !== ( $artifact['schema'] ?? '' ) || empty( $artifact['files'] ) ) {
		$fail( 'Canonical theme exporter did not return a website artifact.' );
	}
	$export_envelope_path = '/work/output/export-envelope.json';
	$raw                  = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $raw ) || false === file_put_contents( $export_envelope_path, $raw . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a retained artifact in the disposable WP-CLI acceptance container.
		$fail( 'Could not retain canonical website artifact export.' );
	}
	$files = array();
	foreach ( $artifact['files'] as $file ) {
		$files[ (string) ( $file['path'] ?? '' ) ] = array(
			'sha256'   => (string) ( $file['sha256'] ?? '' ),
			'encoding' => (string) ( $file['encoding'] ?? '' ),
		);
	}
	$json(
		array(
			'status'          => 'exported',
			'artifact_id'     => (string) ( $artifact['id'] ?? '' ),
			'artifact_schema' => (string) $artifact['schema'],
			'entrypoint'      => (string) ( $artifact['entrypoint'] ?? '' ),
			'provenance'      => $artifact['provenance'] ?? array(),
			'files'           => $files,
			'page_count'      => (int) ( $artifact['report']['page_count'] ?? 0 ),
			'source_page_id'  => (int) $source_page->ID,
		)
	);
	exit;
}

if ( 'verify-reimport' !== $phase && 'verify-removed' !== $phase ) {
	$fail( 'Expected export, verify-reimport, or verify-removed phase.' );
}

$expected_artifact_id = (string) ( $args[1] ?? '' );
$runtime_root = get_stylesheet_directory() . '/ssi-runtime';
if ( ! is_file( $runtime_root . '/runtime.php' ) ) {
	$fail( 'The reimported theme runtime is not installed.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$headers                  = get_file_data( $runtime_root . '/runtime.php', array( 'Version' => 'Version', 'UpdateURI' => 'Update URI' ) );
$config                   = json_decode( (string) file_get_contents( $runtime_root . '/companion.json' ), true );
$readme                   = (string) file_get_contents( $runtime_root . '/README.md' );
$generated_plugins        = array_filter( array_keys( get_plugins() ), static fn( string $file ): bool => str_starts_with( $file, 'ssi-' ) );
$generated_mu_loaders     = array_filter( array_keys( get_mu_plugins() ), static fn( string $file ): bool => str_starts_with( $file, 'ssi-' ) );
$registered               = array();
$assets                   = array();
$asset_paths_match_readme = true;

foreach ( is_array( $config['block_directories'] ?? null ) ? $config['block_directories'] : array() as $directory ) {
	$metadata_path = $runtime_root . '/blocks/' . $directory . '/block.json';
	$metadata      = json_decode( (string) file_get_contents( $metadata_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated block metadata in the disposable acceptance runtime.
	$name          = (string) ( $metadata['name'] ?? '' );
	if ( '' !== $name && WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		$registered[] = $name;
	}
	$references = array( 'blocks/' . $directory . '/block.json' );
	preg_match_all( '/file:\.\/([^\"]+)/', (string) file_get_contents( $metadata_path ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated block metadata in the disposable acceptance runtime.
	foreach ( $matches[1] as $relative ) {
		$references[] = 'blocks/' . $directory . '/' . $relative;
	}
	foreach ( array_unique( $references ) as $relative ) {
		$exists                   = is_file( $runtime_root . '/' . $relative );
		$asset_paths_match_readme = $asset_paths_match_readme && $exists && str_contains( $readme, $relative );
		$assets[]                 = array(
			'path'   => $relative,
			'exists' => $exists,
		);
	}
}

$published_pages = get_posts(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => -1,
	)
);
$entry           = null;
foreach ( $published_pages as $candidate_page ) {
	if ( str_contains( (string) $candidate_page->post_content, 'https://example.test/updated-map' ) ) {
		$entry = $candidate_page;
		break;
	}
}
$route_target = null;
if ( null !== $entry ) {
	$redirect_source = '';
	$source_files    = glob( $runtime_root . '/includes/source-route-redirect.php' );
	foreach ( is_array( $source_files ) ? $source_files : array() as $source_file ) {
		$source = (string) file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads generated companion code in the disposable acceptance runtime.
		if ( 1 === preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $match ) ) {
			$redirect_source = $match[1];
			break;
		}
	}
	if ( '' !== $redirect_source && class_exists( $redirect_source ) ) {
		$route_target = $redirect_source::target_url( 'index.html', '' );
	}
}

$export_envelope       = is_file( '/work/output/export-envelope.json' ) ? json_decode( (string) file_get_contents( '/work/output/export-envelope.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the retained canonical export in the disposable acceptance runtime.
$export_artifact       = is_array( $export_envelope['website_artifact'] ?? null ) ? $export_envelope['website_artifact'] : array();
$theme_dir             = get_theme_root( get_stylesheet() ) . '/' . get_stylesheet();
$manifest              = is_file( $theme_dir . '/static-site-importer-manifest.json' ) ? json_decode( (string) file_get_contents( $theme_dir . '/static-site-importer-manifest.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the generated source-of-truth manifest in the disposable acceptance runtime.
$manifest_artifact     = is_array( $manifest['artifact'] ?? null ) ? $manifest['artifact'] : array();
$report_source         = array(
	'id'         => (string) ( $manifest_artifact['id'] ?? '' ),
	'hash'       => (string) ( $manifest_artifact['hash'] ?? '' ),
	'provenance' => is_array( $manifest_artifact['provenance'] ?? null ) ? $manifest_artifact['provenance'] : array(),
);
$readme_truth          = str_contains( $readme, 'Captured snapshot' )
	&& str_contains( $readme, 'do not update' )
	&& str_contains( $readme, 'no live data source or refresh mechanism is installed' )
	&& str_contains( $readme, 'unverified source claims' );
$theme_identity_truth = 'theme' === ( $config['owner'] ?? '' )
	&& get_stylesheet() === ( $config['owner_slug'] ?? '' )
	&& str_contains( $readme, 'generated theme owns these blocks' )
	&& str_contains( $readme, 'Switching away from or deleting the theme' )
	&& ! str_contains( $readme, 'It is theme-independent' );
$provenance_row        = $report_source['provenance'][0] ?? array();
$producer_match        = 1 === preg_match( '/This build was produced by `([^`]+)` using `([^`]+)`\./', $readme, $producer );
$build_hash            = str_contains( (string) ( $headers['Version'] ?? '' ), '+' ) ? substr( (string) $headers['Version'], strpos( (string) $headers['Version'], '+' ) + 1 ) : '';
$build_truth           = '' !== $build_hash
	? $producer_match && 1 === preg_match( '/^[a-f0-9]{8}$/', $build_hash ) && str_contains( $readme, $build_hash ) && str_contains( $readme, (string) ( $headers['Version'] ?? '' ) )
	: '1.0.0' === (string) ( $headers['Version'] ?? '' ) && str_contains( $readme, 'no producer provenance' );
$source_truth          = ! empty( $report_source['id'] )
	&& $expected_artifact_id === (string) $report_source['id']
	&& is_array( $provenance_row )
	&& 'artifact' === ( $provenance_row['source_format'] ?? '' )
	&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $provenance_row['source_hash'] ?? '' ) )
	&& in_array( 'id', $provenance_row['input_keys'] ?? array(), true )
	&& in_array( 'provenance', $provenance_row['input_keys'] ?? array(), true );
$version_truth         = $source_truth && $build_truth;

$result = array(
	'phase'                    => $phase,
	'importer_active'          => function_exists( 'is_plugin_active' ) && is_plugin_active( 'static-site-importer/static-site-importer.php' ),
	'theme_runtime_loaded'     => count( $registered ) > 0,
	'no_generated_plugin'      => empty( $generated_plugins ) && empty( $generated_mu_loaders ) && '' === (string) get_option( 'static_site_importer_active_companion_plugin', '' ),
	'runtime_owner'            => 'theme',
	'runtime_version'          => (string) ( $headers['Version'] ?? '' ),
	'runtime_update_uri'       => (string) ( $headers['UpdateURI'] ?? '' ),
	'generated_readme_present' => is_file( $runtime_root . '/README.md' ),
	'theme_identity_truth'     => $theme_identity_truth,
	'readme_inventory_truth'   => count( $registered ) > 0 && str_contains( $readme, $registered[0] ) && $asset_paths_match_readme,
	'readme_snapshot_truth'    => $readme_truth,
	'provenance_truth'         => $version_truth,
	'source_provenance_truth'  => ( 'static-site-importer' === ( $export_artifact['provenance']['producer'] ?? '' ) && 'editor-acceptance' === ( $export_artifact['provenance']['materialized_from']['theme_slug'] ?? '' ) && 'theme' === ( $export_artifact['provenance']['materialized_from']['runtime_owner']['type'] ?? '' ) ),
	'source_artifact'          => $report_source,
	'registered_blocks'        => $registered,
	'owned_assets'             => $assets,
	'route_source_path'        => null !== $entry ? get_post_meta( $entry->ID, '_static_site_importer_source_route', true ) : '',
	'route_redirect_target'    => $route_target,
	'page_id'                  => null !== $entry ? (int) $entry->ID : 0,
	'permalink'                => null !== $entry ? get_permalink( $entry ) : '',
	'page_content'             => null !== $entry ? (string) $entry->post_content : '',
);

$json( $result );
$importer_state_invalid = ( 'verify-reimport' === $phase && ! $result['importer_active'] ) || ( 'verify-removed' === $phase && $result['importer_active'] );
$verification_failed    = $importer_state_invalid
	|| ! $result['theme_runtime_loaded']
	|| ! $result['no_generated_plugin']
	|| ! $result['generated_readme_present']
	|| ! $result['theme_identity_truth']
	|| ! $result['readme_inventory_truth']
	|| ! $result['readme_snapshot_truth']
	|| ! $result['provenance_truth']
	|| empty( $registered )
	|| empty( $assets )
	|| false === $asset_paths_match_readme
	|| null === $route_target
	|| null === $entry
	|| 'index.html' !== $result['route_source_path']
	|| false === $result['source_provenance_truth'];
if ( $verification_failed ) {
	$fail( 'Reimported companion inventory, provenance, route, or lifecycle proof failed.' );
}
