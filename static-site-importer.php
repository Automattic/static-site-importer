<?php
/**
 * Plugin Name: Static Site Importer
 * Description: Materialize compiled website artifacts into WordPress block themes.
 * Version: 1.1.45
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
define( 'STATIC_SITE_IMPORTER_VERSION', '1.1.45' );

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

require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-page.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-fetcher.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-import-runtime.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-plugin-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-asset-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document-metadata-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-page-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-stylesheet-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-form-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-product-handoff-contract.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-diagnostic-contract.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-validation-runtime.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-transformer-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-figma-import.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-exporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-generator.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/block.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/rest.php';

Static_Site_Importer_Figma_Import::register_default_zstd_decoder();

add_action( 'init', 'static_site_importer_register_block' );
add_action( 'rest_api_init', 'static_site_importer_register_rest_routes' );

if ( ! function_exists( 'static_site_importer_cli_write_validation_output' ) ) {
	/**
	 * Write validation output to a file when requested, otherwise stdout.
	 *
	 * @param string $json   Validation JSON.
	 * @param string $output Output path.
	 * @return void
	 */
	function static_site_importer_cli_write_validation_output( string $json, string $output ): void {
		if ( '' === $output ) {
			WP_CLI::line( $json );
			return;
		}

		$directory = dirname( $output );
		if ( ! is_dir( $directory ) ) {
			$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : false;
			if ( ! $created ) {
				WP_CLI::error( 'Failed to create validation output directory.' );
			}
		}

		if ( false === file_put_contents( $output, $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes operator-requested validation artifact.
			WP_CLI::error( 'Failed to write validation output file.' );
		}

		WP_CLI::line(
			(string) wp_json_encode(
				array(
					'schema' => 'static-site-importer/validation-cli-output/v1',
					'output' => $output,
				),
				JSON_UNESCAPED_SLASHES
			)
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command(
		'static-site-importer import-theme',
		static function ( array $args, array $assoc_args ): void {
			$entry = isset( $args[0] ) ? (string) $args[0] : '';
			if ( '' === $entry || ! is_readable( $entry ) || ! is_file( $entry ) ) {
				WP_CLI::error( 'Provide a readable source HTML file.' );
			}

			$root = realpath( dirname( $entry ) );
			if ( false === $root ) {
				WP_CLI::error( 'Could not resolve the source directory.' );
				return;
			}
			$root = (string) $root;

			$files    = array();
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo || ! $file->isFile() || ! $file->isReadable() ) {
					continue;
				}

				$path     = $file->getPathname();
				$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
				if ( '' === $relative ) {
					continue;
				}

				$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads operator-provided source files.
				if ( false === $content ) {
					WP_CLI::error( sprintf( 'Could not read source file: %s', $path ) );
					return;
				}

				$files[] = array(
					'path'           => $relative,
					'content_base64' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes declared artifact payload bytes, including binary assets.
				);
			}

			$entry_realpath = realpath( $entry );
			$entrypoint     = false !== $entry_realpath ? ltrim( str_replace( '\\', '/', substr( $entry_realpath, strlen( $root ) ) ), '/' ) : basename( $entry );
			$input          = array(
				'artifact'                     => array(
					'schema'     => Static_Site_Importer_Transformer_Adapter::WEBSITE_ARTIFACT_SCHEMA,
					'entrypoint' => $entrypoint,
					'files'      => $files,
				),
				'slug'                         => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                         => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'                     => isset( $assoc_args['activate'] ),
				'overwrite'                    => isset( $assoc_args['overwrite'] ),
				'fail_on_quality'              => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce'    => isset( $assoc_args['allow-missing-woocommerce'] ),
				'materialize_dependencies'     => ! isset( $assoc_args['skip-dependency-materialization'] ),
				'report'                       => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
				'asset_materialization_policy' => isset( $assoc_args['asset-materialization-policy'] ) ? (string) $assoc_args['asset-materialization-policy'] : '',
			);

			$result = static_site_importer_ability_import_website_artifact( $input );
			if ( empty( $result['success'] ) ) {
				$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
				WP_CLI::error( (string) ( $error['message'] ?? 'Static site import failed.' ) );
			}

			WP_CLI::success( sprintf( 'Imported %s.', (string) ( $result['result']['theme_slug'] ?? $input['slug'] ) ) );
		}
	);

	WP_CLI::add_command(
		'static-site-importer validate-artifact',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$halt_on_failure = ! isset( $assoc_args['allow-failure'] ) && false !== ( $assoc_args['error-on-fail'] ?? true ) && ! isset( $assoc_args['no-error-on-fail'] );

			$input  = array(
				'slug'                      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'                  => ! isset( $assoc_args['no-activate'] ),
				'overwrite'                 => ! isset( $assoc_args['no-overwrite'] ),
				'fail_on_quality'           => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce' => isset( $assoc_args['allow-missing-woocommerce'] ),
			);
			$output = isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : '';
			if ( isset( $assoc_args['artifact-dir'] ) ) {
				$input['artifact_dir'] = (string) $assoc_args['artifact-dir'];
			}

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

			$result = Static_Site_Importer_Validation_Runtime::validate_artifact( $input );
			if ( is_wp_error( $result ) ) {
				$error_result = Static_Site_Importer_Validation_Runtime::error_result_from_wp_error( $result, $input );
				$json         = wp_json_encode( $error_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( false === $json ) {
					WP_CLI::error( $result->get_error_message() );
				}

				static_site_importer_cli_write_validation_output( (string) $json, $output );
				if ( $halt_on_failure ) {
					WP_CLI::halt( 1 );
				}

				return;
			}

			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode validation result.' );
				return;
			}

			static_site_importer_cli_write_validation_output( $json, $output );
			if ( $halt_on_failure && empty( $result['success'] ) ) {
				WP_CLI::halt( 1 );
			}
		}
	);

	WP_CLI::add_command(
		'static-site-importer figma-diagnostics',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );

			if ( empty( $assoc_args['input'] ) ) {
				WP_CLI::error( 'Provide a Figma request JSON file with --input=<path>.' );
				return;
			}

			$input_json = file_get_contents( (string) $assoc_args['input'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-provided request file.
			$input      = json_decode( false === $input_json ? '' : $input_json, true );
			if ( ! is_array( $input ) ) {
				WP_CLI::error( 'The --input file must contain a JSON object.' );
				return;
			}

			$result = Static_Site_Importer_Figma_Import::diagnostics_report( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
				return;
			}

			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode Figma diagnostics result.' );
				return;
			}

			WP_CLI::line( $json );
		}
	);
}
