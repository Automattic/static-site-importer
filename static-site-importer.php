<?php
/**
 * Plugin Name: Static Site Importer
 * Description: Materialize compiled website artifacts into WordPress block and classic themes.
 * Version: 1.8.5
 * Author: Chris Huber
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Text Domain: static-site-importer
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATIC_SITE_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_VERSION', '1.8.5' );

$static_site_importer_autoload = STATIC_SITE_IMPORTER_PATH . 'vendor/autoload.php';
if ( is_readable( $static_site_importer_autoload ) ) {
	require_once $static_site_importer_autoload;
}

$static_site_importer_transformers = array(
	STATIC_SITE_IMPORTER_PATH . 'vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php',
	STATIC_SITE_IMPORTER_PATH . 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php',
);
foreach ( $static_site_importer_transformers as $static_site_importer_transformer ) {
	if ( function_exists( 'blocks_engine_php_transformer_compile_artifact' ) || ! is_readable( $static_site_importer_transformer ) ) {
		continue;
	}
	require_once $static_site_importer_transformer;
}

$static_site_importer_figma_transformers = array(
	STATIC_SITE_IMPORTER_PATH . 'vendor/automattic/blocks-engine-figma-transformer/figma-transformer/figma-transformer.php',
	STATIC_SITE_IMPORTER_PATH . 'vendor/automattic/blocks-engine-figma-transformer/figma-transformer.php',
);
foreach ( $static_site_importer_figma_transformers as $static_site_importer_figma_transformer ) {
	if ( ( function_exists( 'blocks_engine_figma_transformer_transform_scenegraph' ) && function_exists( 'blocks_engine_figma_transformer_transform_file' ) ) || ! is_readable( $static_site_importer_figma_transformer ) ) {
		continue;
	}
	require_once $static_site_importer_figma_transformer;
}

$static_site_importer_lifecycle_checkpoint = STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-lifecycle-compile-checkpoint.php';
require_once $static_site_importer_lifecycle_checkpoint;
Static_Site_Importer_Lifecycle_Compile_Checkpoint::runtime_generation();
Static_Site_Importer_Lifecycle_Compile_Checkpoint::register_cleanup();
register_deactivation_hook( __FILE__, array( Static_Site_Importer_Lifecycle_Compile_Checkpoint::class, 'unschedule_cleanup' ) );

$static_site_importer_includes = array(
	'class-static-site-importer-build-provenance.php',
	'class-static-site-importer-site-identity.php',
	'class-static-site-importer-website-artifact-import-input.php',
	'class-static-site-importer-theme-materialization-strategy.php',
	'class-static-site-importer-classic-theme-projection.php',
	'class-static-site-importer-client-script-policy-report.php',
	'class-static-site-importer-client-script-policy.php',
	'class-static-site-importer-document.php',
	'class-static-site-importer-source-page.php',
	'class-static-site-importer-ip-classifier.php',
	'class-static-site-importer-url-fetcher.php',
	'class-static-site-importer-artifact-run.php',
	'class-static-site-importer-source-normalizer.php',
	'class-static-site-importer-portable-source-manifest.php',
	'class-static-site-importer-content-policy.php',
	'class-static-site-importer-url-site-collector.php',
	'class-static-site-importer-url-import-runtime.php',
	'class-static-site-importer-companion-plugin.php',
	'class-static-site-importer-plugin-materializer.php',
	'class-static-site-importer-dependency-manager.php',
	'class-static-site-importer-entity-materializer-registry.php',
	'class-static-site-importer-asset-reporter.php',
	'class-static-site-importer-document-metadata-reporter.php',
	'class-static-site-importer-route-document-metadata.php',
	'class-static-site-importer-protected-page-policy.php',
	'class-static-site-importer-stylesheet-materializer.php',
	'class-static-site-importer-provider-layout-overlay.php',
	'class-static-site-importer-woo-product-seeder.php',
	'class-static-site-importer-form-seeder.php',
	'class-static-site-importer-product-handoff-contract.php',
	'class-static-site-importer-diagnostic-loss-classes.php',
	'class-static-site-importer-import-report.php',
	'class-static-site-importer-diagnostic-contract.php',
	'class-static-site-importer-artifact-diagnostics-adapter.php',
	'class-static-site-importer-validation-runtime.php',
	'class-static-site-importer-report-diagnostics.php',
	'class-static-site-importer-failed-plan-validation.php',
	'class-static-site-importer-font-materializer.php',
	'class-static-site-importer-document-type-classifier.php',
	'class-static-site-importer-current-site-capabilities.php',
	'class-static-site-importer-quality-budget-admission.php',
	'class-static-site-importer-owner-handoff-evidence.php',
	'class-static-site-importer-wordpress-site-plan-materializer.php',
	'class-static-site-importer-figma-import.php',
	'class-static-site-importer-theme-exporter.php',
	'class-static-site-importer-block-document-reporter.php',
	'class-static-site-importer-theme-generator.php',
	'class-static-site-importer-provider-presentation.php',
	'class-static-site-importer-commerce-presentation.php',
	'class-static-site-importer-direct-artifact-import.php',
	'class-static-site-importer-canonical-import-service.php',
);
foreach ( $static_site_importer_includes as $static_site_importer_include ) {
	require_once STATIC_SITE_IMPORTER_PATH . 'includes/' . $static_site_importer_include;
}

Static_Site_Importer_Direct_Artifact_Import::register_cleanup();
register_deactivation_hook( __FILE__, array( Static_Site_Importer_Direct_Artifact_Import::class, 'unschedule_cleanup' ) );

Static_Site_Importer_Figma_Import::register_default_zstd_decoder();
Static_Site_Importer_Entity_Materializer_Registry::register_presentations();
Static_Site_Importer_Form_Seeder::register_runtime_bootstrap();
Static_Site_Importer_Route_Document_Metadata::register();

require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/block.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/rest.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/cli.php';

add_action( 'init', 'static_site_importer_register_block' );
add_action( 'rest_api_init', 'static_site_importer_register_rest_routes' );
