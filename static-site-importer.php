<?php
/**
 * Plugin Name: Static Site Importer
 * Description: Materialize compiled website artifacts into WordPress block themes.
 * Version: 1.1.4
 * Author: Chris Huber
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Text Domain: static-site-importer
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STATIC_SITE_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'STATIC_SITE_IMPORTER_VERSION', '1.1.5' );

$static_site_importer_autoload = STATIC_SITE_IMPORTER_PATH . 'vendor/autoload.php';
if ( is_readable( $static_site_importer_autoload ) ) {
	require_once $static_site_importer_autoload;
}

$static_site_importer_transformer = STATIC_SITE_IMPORTER_PATH . 'vendor/automattic/blocks-engine-php-transformer/php-transformer/php-transformer.php';
if ( ! function_exists( 'blocks_engine_php_transformer_compile_artifact' ) && is_readable( $static_site_importer_transformer ) ) {
	require_once $static_site_importer_transformer;
}

require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-page.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-fetcher.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-import-runtime.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-plugin-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-asset-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document-metadata-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-page-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-stylesheet-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-product-handoff-contract.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-codebox-validation.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-transformer-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-exporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-generator.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/block.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/rest.php';

Static_Site_Importer_Codebox_Validation::register_default_provider();

add_action( 'init', 'static_site_importer_register_block' );
add_action( 'rest_api_init', 'static_site_importer_register_rest_routes' );

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'static-site-importer validate-in-codebox',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );

			$input = array(
				'slug'                      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'                  => ! isset( $assoc_args['no-activate'] ),
				'overwrite'                 => ! isset( $assoc_args['no-overwrite'] ),
				'fail_on_quality'           => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce' => isset( $assoc_args['allow-missing-woocommerce'] ),
			);

			if ( isset( $assoc_args['artifact'] ) ) {
				$artifact_json = file_get_contents( (string) $assoc_args['artifact'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-provided artifact file.
				$artifact      = json_decode( false === $artifact_json ? '' : $artifact_json, true );
				if ( ! is_array( $artifact ) ) {
					WP_CLI::error( 'The --artifact file must contain a JSON object.' );
				}

				$input['artifact'] = $artifact;
			}

			if ( isset( $assoc_args['generated-theme-ref'] ) ) {
				$input['generated_theme_ref'] = array( 'artifact_ref' => (string) $assoc_args['generated-theme-ref'] );
			}

			if ( isset( $assoc_args['theme-archive-ref'] ) ) {
				$input['theme_archive_ref'] = array( 'artifact_ref' => (string) $assoc_args['theme-archive-ref'] );
			}

			$result = Static_Site_Importer_Codebox_Validation::validate( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode Codebox validation result.' );
			}

			WP_CLI::line( $json );
			if ( empty( $result['success'] ) ) {
				WP_CLI::halt( 1 );
			}
		}
	);
}
