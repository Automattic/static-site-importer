<?php
/** Real WordPress 7.1 proof for generated theme identity, rollback, and export. */
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable local theme/artifact readback.
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SSI_THEME_METADATA_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Run only in the declared disposable theme-metadata runtime.' );
}

require_once WP_CONTENT_DIR . '/plugins/static-site-importer/vendor/autoload.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/static-site-importer.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require_once WP_CONTENT_DIR . '/plugins/static-site-importer/includes/class-static-site-importer-theme-exporter.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$artifact = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		array(
			'path'    => 'website/index.html',
			'content' => '<!doctype html><html><head><title>Nick Diego &mdash; Home</title><link rel="stylesheet" href="assets/site.css"></head><body><main><h1>Nick Diego</h1><p>Theme metadata acceptance fixture.</p></main></body></html>',
		),
		array(
			'path'    => 'website/about.html',
			'content' => '<!doctype html><html><head><title>About</title></head><body><main><h1>About Nick Diego</h1></main></body></html>',
		),
		array(
			'path'    => 'website/assets/site.css',
			'content' => 'main { color: #123456; }',
		),
	),
);
$provenance = array(
	'schema'         => 'blocks-engine/generated-artifact-provenance/v1',
	'generator'      => 'blocks-engine/php-transformer',
	'engine_version' => '0.32.3',
	'artifact_hash'  => hash( 'sha256', wp_json_encode( $artifact ) ),
);
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$artifact,
	array(
		'slug'                => 'nick-diego-metadata',
		'activate'            => true,
		'artifact_provenance' => $provenance,
	)
);
$assert( ! is_wp_error( $compiled ), 'Source artifact compiles: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
$assert( 'completed' === ( $receipt['status'] ?? '' ), 'First real WordPress materialization completes: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
$style_path = get_theme_root() . '/nick-diego-metadata/style.css';
$style      = (string) file_get_contents( $style_path );
foreach (
	array(
		'Theme Name: Nick Diego',
		'Text Domain: nick-diego-metadata',
		'Author: Static Site Importer',
		'Description: Materialized from a compiled website artifact.',
	)
	as $header
) {
	$assert( str_contains( $style, $header ), 'Persisted WordPress style.css contains ' . $header );
}
$assert( ! str_contains( $style, 'Blocks Engine Site' ) && ! str_contains( $style, 'blocks-engine-site' ), 'Generic producer placeholder is absent from persisted style.css' );
$assert( 1 === preg_match( '/^Version: [0-9][0-9A-Za-z.\-]*\+[a-f0-9]{8}$/m', $style ), 'Persisted style.css contains artifact-bound build version' );
$assert( str_contains( $style, 'Update URI: https://static-site-importer.invalid/nick-diego-metadata' ), 'Persisted style.css contains neutral provenance Update URI' );
$assert( is_file( get_theme_root() . '/nick-diego-metadata/templates/index.html' ), 'Canonical template payload remains present on disk' );
$source_css_persisted = false;
$theme_files          = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( get_theme_root() . '/nick-diego-metadata', FilesystemIterator::SKIP_DOTS ) );
foreach ( $theme_files as $theme_file ) {
	if ( $theme_file->isFile() && str_contains( (string) file_get_contents( $theme_file->getPathname() ), 'main { color: #123456; }' ) ) {
		$source_css_persisted = true;
		break;
	}
}
$assert( $source_css_persisted, 'Source stylesheet payload remains present in persisted theme files' );

$reimport = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
$assert( 'completed' === ( $reimport['status'] ?? '' ) && file_get_contents( $style_path ) === $style, 'Real same-plan reimport completes and preserves exact style.css bytes' );

$theme_snapshot = static function (): array {
	$files = array();
	$root  = get_theme_root();
	$walk  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $walk as $file ) {
		if ( $file->isFile() ) {
			$path          = substr( $file->getPathname(), strlen( $root ) + 1 );
			$files[ $path ] = hash_file( 'sha256', $file->getPathname() );
		}
	}
	ksort( $files );
	return $files;
};
$before_files   = $theme_snapshot();
$before_options = array( get_option( 'stylesheet' ), get_option( 'template' ), get_option( 'blogname' ), get_option( 'show_on_front' ), get_option( 'page_on_front' ) );
$rollback       = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$artifact,
	array(
		'slug'                           => 'nick-diego-rollback',
		'activate'                       => true,
		'inject_materialization_failure' => 'after_blogname',
	)
);
$assert( ! is_wp_error( $rollback ), 'Rollback artifact compiles' );
$failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $rollback['plan'], $rollback['args'] );
$assert( 'partial' === ( $failed['status'] ?? '' ) && 'rolled_back' === ( $failed['rollback']['status'] ?? '' ), 'Injected late materialization failure is rolled back' );
$assert( $before_files === $theme_snapshot(), 'Rollback restores all persisted theme files byte-for-byte' );
$assert( $before_options === array( get_option( 'stylesheet' ), get_option( 'template' ), get_option( 'blogname' ), get_option( 'show_on_front' ), get_option( 'page_on_front' ) ), 'Rollback restores active theme and site options' );

$export = Static_Site_Importer_Theme_Exporter::export_theme( array( 'theme_slug' => 'nick-diego-metadata' ) );
$assert( ! is_wp_error( $export ) && is_array( $export['website_artifact'] ?? null ), 'SSI exports the persisted generated theme as a website artifact' );
$exported = $export['website_artifact'];
$exported['entrypoint'] = preg_replace( '~^website/~', '', (string) ( $exported['entrypoint'] ?? 'website/index.html' ) );
$exported['files']      = array_map(
	static function ( array $file ): array {
		$file['path'] = preg_replace( '~^website/~', '', (string) ( $file['path'] ?? '' ) );
		return $file;
	},
	$exported['files'] ?? array()
);
$round_trip = Static_Site_Importer_Compilation_Preparation::compile_website_artifact(
	$exported,
	array(
		'slug'                => 'nick-diego-export-roundtrip',
		'overwrite'           => true,
		'artifact_provenance' => $provenance,
	)
);
$assert( ! is_wp_error( $round_trip ), 'Exported artifact recompiles: ' . ( is_wp_error( $round_trip ) ? $round_trip->get_error_message() : '' ) );
$round_trip_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $round_trip['plan'], $round_trip['args'] );
$round_trip_style   = (string) file_get_contents( get_theme_root() . '/nick-diego-export-roundtrip/style.css' );
$assert( 'completed' === ( $round_trip_receipt['status'] ?? '' ), 'Exported artifact reimports into a second real WordPress theme: ' . wp_json_encode( $round_trip_receipt['errors'] ?? array() ) );
$assert( str_contains( $round_trip_style, 'Theme Name: Nick Diego' ) && str_contains( $round_trip_style, 'Author: Static Site Importer' ) && ! str_contains( $round_trip_style, 'Blocks Engine Site' ), 'Export/reimport keeps neutral source identity and SSI attribution' );

$evidence = array(
	'core_version'           => get_bloginfo( 'version' ),
	'first_theme'            => 'nick-diego-metadata',
	'first_theme_headers'    => array_values( array_filter( explode( "\n", $style ), static fn( string $line ): bool => preg_match( '/^(Theme Name|Text Domain|Author|Description|Version|Update URI):/', $line ) ) ),
	'reimport_status'        => $reimport['status'],
	'rollback_status'        => $failed['status'],
	'rollback_files_restored' => true,
	'export_file_count'      => count( $exported['files'] ?? array() ),
	'round_trip_theme'       => 'nick-diego-export-roundtrip',
	'round_trip_identity'    => 'Nick Diego',
);
file_put_contents( '/evidence/theme-metadata-result.json', wp_json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES ) . "\n";
