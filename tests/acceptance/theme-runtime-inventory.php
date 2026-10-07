<?php
/** Theme ownership proof, used both with and without the importer on disposable sites. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run inside the disposable WP-CLI acceptance site.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$root = get_stylesheet_directory() . '/ssi-runtime';
$config = json_decode( (string) file_get_contents( $root . '/companion.json' ), true );
$readme = (string) file_get_contents( $root . '/README.md' );
$source = (string) file_get_contents( $root . '/includes/provider-form-runtime-v1.php' );
preg_match( '/final class ([A-Za-z_][A-Za-z0-9_]*)/', $source, $match );
$runtime = $match[1] ?? '';
$blocks = array();
$assets_match = true;
foreach ( $config['block_directories'] ?? array() as $directory ) {
	$path = 'blocks/' . $directory . '/block.json';
	$raw = (string) file_get_contents( $root . '/' . $path );
	$metadata = json_decode( $raw, true );
	$name = (string) $metadata['name'];
	if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		$blocks[] = $name;
	}
	preg_match_all( '/file:\.\/([^\"]+)/', $raw, $references );
	foreach ( array_merge( array( $path ), array_map( static fn( string $asset ): string => 'blocks/' . $directory . '/' . $asset, $references[1] ) ) as $asset ) {
		$assets_match = $assets_match && is_file( $root . '/' . $asset ) && str_contains( $readme, $asset );
	}
}
$plugins = array_filter( array_keys( get_plugins() ), static fn( string $file ): bool => str_starts_with( $file, 'ssi-' ) );
$mu = array_filter( array_keys( get_mu_plugins() ), static fn( string $file ): bool => str_starts_with( $file, 'ssi-' ) );
$provider_hook = has_filter( 'grunion_contact_form_field_html', array( $runtime, 'project_wrapper_classes' ) );
$projected_field = apply_filters( 'grunion_contact_form_field_html', '<div class="grunion-field-text-wrap ssi-source-wrapper-2--source-box-wrap"><input type="text"></div>' );
$provider_projection = false !== $provider_hook && str_contains( $projected_field, '<div class="ssi-field-row source-box"><input type="text"></div>' ) && ! str_contains( $projected_field, 'ssi-source-wrapper-' );
$result = array(
	'importer_active' => is_plugin_active( 'static-site-importer/static-site-importer.php' ),
	'no_generated_plugin' => empty( $plugins ) && empty( $mu ) && '' === (string) get_option( 'static_site_importer_active_companion_plugin', '' ),
	'theme_runtime_loaded' => ! empty( $blocks ) && 'theme' === ( $config['owner'] ?? '' ) && get_stylesheet() === ( $config['owner_slug'] ?? '' ),
	'provider_runtime_class' => 'Static_Site_Importer_Provider_Form_Runtime_V1',
	'provider_runtime_loaded' => class_exists( 'Static_Site_Importer_Provider_Form_Runtime_V1', false ),
	'theme_provider_runtime_class' => $runtime,
	'theme_provider_runtime_loaded' => '' !== $runtime && class_exists( $runtime, false ),
	'theme_provider_projection_verified' => $provider_projection,
	'readme_inventory_truth' => $assets_match && count( $blocks ) > 0 && str_contains( $readme, $blocks[0] ) && str_contains( $readme, 'No generated companion plugin or MU-loader is required' ),
	'registered_blocks' => $blocks,
);
echo wp_json_encode( $result ) . "\n";
if ( ! $result['no_generated_plugin'] || ! $result['theme_runtime_loaded'] || ! $result['theme_provider_runtime_loaded'] || ! $result['theme_provider_projection_verified'] || ! $result['readme_inventory_truth'] ) {
	WP_CLI::error( 'Theme-owned runtime inventory failed.' );
}
