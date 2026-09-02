<?php
/**
 * Smoke test: ability registration is idempotent across repeated includes.
 *
 * Run from the repository root:
 * php tests/smoke-ability-registration-idempotent.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) ) {
	define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['ssi_ability_categories'] = array();
$GLOBALS['ssi_abilities']          = array();

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function apply_filters( string $hook, $value ) {
	return $value;
}

function doing_action( string $hook ): bool {
	return false;
}

function did_action( string $hook ): int {
	return in_array( $hook, array( 'wp_abilities_api_categories_init', 'wp_abilities_api_init' ), true ) ? 1 : 0;
}

function wp_get_ability_category( string $slug ): ?array {
	return $GLOBALS['ssi_ability_categories'][ $slug ] ?? null;
}

function wp_register_ability_category( string $slug, array $args ): ?array {
	$GLOBALS['ssi_ability_categories'][ $slug ] = $args;
	return $args;
}

function wp_get_ability( string $name ): ?array {
	return $GLOBALS['ssi_abilities'][ $name ] ?? null;
}

function wp_register_ability( string $name, array $args ): ?array {
	$GLOBALS['ssi_abilities'][ $name ] = $args;
	return $args;
}

require dirname( __DIR__ ) . '/includes/abilities.php';
require dirname( __DIR__ ) . '/includes/abilities.php';

$expected_abilities = array(
	'static-site-importer/get-runtime-package-manifest',
	'static-site-importer/export-theme',
	'static-site-importer/materialize-wordpress-site-plan',
	'static-site-importer/import',
	'static-site-importer/validate-artifact',
);

assert( array( STATIC_SITE_IMPORTER_ABILITY_CATEGORY ) === array_keys( $GLOBALS['ssi_ability_categories'] ) );
assert( $expected_abilities === array_keys( $GLOBALS['ssi_abilities'] ) );
foreach ( array( 'static-site-importer/import' ) as $ability ) {
	$properties = $GLOBALS['ssi_abilities'][ $ability ]['input_schema']['properties'];
	assert( 'string' === $properties['site_title']['type'] );
	assert( array( 'report_only', 'draft' ) === $properties['stale_page_action']['enum'] );
}
$import_properties = $GLOBALS['ssi_abilities']['static-site-importer/import']['input_schema']['properties'];
assert( array( 'plan', 'apply' ) === $import_properties['operation']['enum'] );
assert( array( 'html', 'files', 'zip', 'url', 'figma' ) === $import_properties['source']['properties']['type']['enum'] );
assert( isset( $import_properties['source']['properties']['figma_file'], $import_properties['artifact_bundle'], $import_properties['scenegraph'] ) );
assert( ! isset( $GLOBALS['ssi_abilities']['static-site-importer/import-url'] ) && ! isset( $GLOBALS['ssi_abilities']['static-site-importer/import-website-artifact'] ) && ! isset( $GLOBALS['ssi_abilities']['static-site-importer/import-figma'] ) );
$manifest_result = static_site_importer_ability_get_runtime_package_manifest();
assert( true === $manifest_result['success'] );
assert( 'static-site-importer/runtime-package-manifest/v1' === $manifest_result['manifest']['schema'] );
assert( isset( $manifest_result['manifest']['profiles']['website-artifact-import'] ) );
assert( isset( $manifest_result['manifest']['profiles']['html-site-import'] ) );
assert( true === static_site_importer_ability_read_runtime_manifest_permission_callback() );

echo "Ability registration idempotency smoke passed.\n";
