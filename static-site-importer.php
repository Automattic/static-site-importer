<?php
/**
 * Plugin Name: Static Site Importer
 * Description: Materialize compiled website artifacts into WordPress block themes.
 * Version: 1.4.0
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
define( 'STATIC_SITE_IMPORTER_VERSION', '1.4.0' );

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

require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-site-identity.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-website-artifact-import-input.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-client-script-policy.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-page.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-fetcher.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-artifact-run.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-source-normalizer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-content-policy.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-site-collector.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-url-import-runtime.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-companion-plugin.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-plugin-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-entity-materializer-registry.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-asset-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document-metadata-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-page-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-stylesheet-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-provider-layout-overlay.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-woo-product-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-form-seeder.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-product-handoff-contract.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-diagnostic-loss-classes.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-diagnostic-contract.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-validation-runtime.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-report-diagnostics.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-font-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-document-type-classifier.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-current-site-capabilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-figma-import.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-exporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-block-document-reporter.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-theme-generator.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-provider-presentation.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/class-static-site-importer-commerce-presentation.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/abilities.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/block.php';
require_once STATIC_SITE_IMPORTER_PATH . 'includes/rest.php';

Static_Site_Importer_Figma_Import::register_default_zstd_decoder();
Static_Site_Importer_Entity_Materializer_Registry::register_presentations();
Static_Site_Importer_Form_Seeder::register_runtime_bootstrap();

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
		'static-site-importer materialize-wordpress-site-plan',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$input = isset( $assoc_args['plan'] ) ? file_get_contents( (string) $assoc_args['plan'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-supplied canonical plan.
			$plan  = is_string( $input ) ? json_decode( $input, true ) : null;
			if ( ! is_array( $plan ) || empty( $assoc_args['slug'] ) ) {
				WP_CLI::error( 'Provide --plan=<canonical-plan.json> and --slug=<theme-slug>.' );
			}
			$receipt = static_site_importer_ability_materialize_wordpress_site_plan(
				array(
					'plan'            => $plan,
					'slug'            => (string) $assoc_args['slug'],
					'overwrite'       => isset( $assoc_args['overwrite'] ),
					'disable_smilies' => ! isset( $assoc_args['no-disable-smilies'] ),
				)
			);
			WP_CLI::line( (string) wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES ) );
			if ( 'completed' !== $receipt['status'] ) {
				WP_CLI::halt( 1 );
			}
		}
	);

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
					'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
					'entrypoint' => $entrypoint,
					'files'      => $files,
				),
				'slug'                         => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                         => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'                     => isset( $assoc_args['activate'] ),
				'overwrite'                    => isset( $assoc_args['overwrite'] ),
				'disable_smilies'              => ! isset( $assoc_args['no-disable-smilies'] ),
				'fail_on_quality'              => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce'    => isset( $assoc_args['allow-missing-woocommerce'] ),
				'materialize_dependencies'     => ! isset( $assoc_args['skip-dependency-materialization'] ),
				'report'                       => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
				'asset_materialization_policy' => isset( $assoc_args['asset-materialization-policy'] ) ? (string) $assoc_args['asset-materialization-policy'] : '',
			);

			$result = static_site_importer_cli_import(
				array_merge(
					$input,
					array( 'source' => static_site_importer_ability_files_source( $input['artifact'] ) )
				)
			);
			if ( empty( $result['success'] ) ) {
				$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
				WP_CLI::error( (string) ( $error['message'] ?? 'Static site import failed.' ) );
			}

			WP_CLI::success( sprintf( 'Imported %s.', (string) ( $result['result']['theme_slug'] ?? $input['slug'] ) ) );
		}
	);

	WP_CLI::add_command(
		'static-site-importer import-url',
		static function ( array $args, array $assoc_args ): void {
			$url = isset( $args[0] ) ? (string) $args[0] : '';
			if ( '' === trim( $url ) ) {
				WP_CLI::error( 'Provide a public source URL.' );
			}

			$input  = array(
				'source'                    => array(
					'type' => 'url',
					'url'  => $url,
				),
				'slug'                      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'site_title'                => isset( $assoc_args['site-title'] ) ? (string) $assoc_args['site-title'] : '',
				'activate'                  => isset( $assoc_args['activate'] ),
				'overwrite'                 => isset( $assoc_args['overwrite'] ),
				'disable_smilies'           => ! isset( $assoc_args['no-disable-smilies'] ),
				'fail_on_quality'           => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce' => isset( $assoc_args['allow-missing-woocommerce'] ),
				'report'                    => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
			);
			$result = static_site_importer_cli_import( $input );
			while ( ! empty( $result['success'] ) && ! empty( $result['continuation'] ) ) {
				$input['source']['import_id'] = (string) ( $result['import_id'] ?? '' );
				$result                       = static_site_importer_cli_import( $input );
			}
			if ( empty( $result['success'] ) ) {
				$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
				WP_CLI::error( (string) ( $error['message'] ?? 'Static site URL import failed.' ) );
			}

			WP_CLI::success( sprintf( 'Imported %s.', (string) ( $result['result']['theme_slug'] ?? $input['slug'] ) ) );
		}
	);

	WP_CLI::add_command(
		'static-site-importer plan-artifact-dependencies',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$input = static_site_importer_cli_artifact_input( $assoc_args );
			if ( is_wp_error( $input ) ) {
				WP_CLI::error( $input->get_error_message() );
			}
			$result = Static_Site_Importer_Validation_Runtime::plan_artifact_dependencies( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode dependency plan.' );
			}
			if ( ! empty( $assoc_args['output'] ) && false === file_put_contents( (string) $assoc_args['output'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes an explicit host handoff artifact.
				WP_CLI::error( 'Failed to write dependency plan output.' );
			}
			WP_CLI::line( (string) $json );
		}
	);

	WP_CLI::add_command(
		'static-site-importer prepare-artifact-dependencies',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$input = static_site_importer_cli_artifact_input( $assoc_args );
			if ( is_wp_error( $input ) ) {
				WP_CLI::error( $input->get_error_message() );
			}
			$result = Static_Site_Importer_Validation_Runtime::prepare_artifact_dependencies( $input );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				WP_CLI::error( 'Failed to encode dependency preparation receipt.' );
			}
			if ( empty( $assoc_args['receipt'] ) || false === file_put_contents( (string) $assoc_args['receipt'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes its explicit lifecycle handoff receipt.
				WP_CLI::error( 'Dependency preparation requires a writable --receipt path.' );
			}
			WP_CLI::line( (string) $json );
		}
	);

	WP_CLI::add_command(
		'static-site-importer validate-artifact',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );
			$halt_on_failure = ! isset( $assoc_args['allow-failure'] ) && false !== ( $assoc_args['error-on-fail'] ?? true ) && ! isset( $assoc_args['no-error-on-fail'] );
			$format          = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'full';
			if ( ! in_array( $format, array( 'full', 'fixture-matrix' ), true ) ) {
				WP_CLI::error( 'The --format value must be full or fixture-matrix.' );
			}

			$input = array(
				'slug'                                 => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
				'name'                                 => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
				'activate'                             => ! isset( $assoc_args['no-activate'] ),
				'overwrite'                            => ! isset( $assoc_args['no-overwrite'] ),
				'fail_on_quality'                      => isset( $assoc_args['fail-on-quality'] ),
				'allow_missing_woocommerce'            => isset( $assoc_args['allow-missing-woocommerce'] ),
				'require_proven_dynamic_client_assets' => ! isset( $assoc_args['allow-unproven-dynamic-client-assets'] ),
			);
			if ( isset( $assoc_args['host-staged-dependencies'] ) ) {
				$input['materialize_dependencies'] = false;
			}
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
			if ( isset( $assoc_args['lifecycle-receipt'] ) ) {
				$receipt_json     = file_get_contents( (string) $assoc_args['lifecycle-receipt'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads its explicit lifecycle handoff receipt.
				$receipt          = json_decode( false === $receipt_json ? '' : $receipt_json, true );
				$encoded_artifact = isset( $input['artifact'] ) ? wp_json_encode( $input['artifact'] ) : false;
				$artifact_hash    = false !== $encoded_artifact ? hash( 'sha256', $encoded_artifact ) : '';
				if ( ! is_array( $receipt ) || 'static-site-importer/runtime-lifecycle-receipt/v1' !== ( $receipt['schema'] ?? '' ) || 'dependencies_prepared' !== ( $receipt['status'] ?? '' ) || ( $receipt['artifact_sha256'] ?? '' ) !== $artifact_hash ) {
					WP_CLI::error( 'The --lifecycle-receipt must be a completed receipt for this exact artifact.' );
				}
				$input['runtime_lifecycle_phase']      = 'resume';
				$input['runtime_lifecycle_request_id'] = (string) ( $receipt['fresh_runtime']['request_id'] ?? '' );
			}

			if ( isset( $assoc_args['generated-theme-ref'] ) ) {
				$input['generated_theme_ref'] = array( 'artifact_ref' => (string) $assoc_args['generated-theme-ref'] );
			}

			if ( isset( $assoc_args['theme-archive-ref'] ) ) {
				$input['theme_archive_ref'] = array( 'artifact_ref' => (string) $assoc_args['theme-archive-ref'] );
			}
			$sidecar_contract = static_site_importer_cli_materialization_sidecar_contract( $assoc_args );
			if ( is_wp_error( $sidecar_contract ) ) {
				WP_CLI::error( $sidecar_contract->get_error_message(), 1 );
			}

			$result = Static_Site_Importer_Validation_Runtime::validate_artifact( $input );
			if ( is_wp_error( $result ) ) {
				$error_result = Static_Site_Importer_Validation_Runtime::error_result_from_wp_error( $result, $input );
				if ( 'fixture-matrix' === $format ) {
					$error_result = Static_Site_Importer_Validation_Runtime::fixture_matrix_result( $error_result );
				}
				if ( true === $sidecar_contract ) {
					$sidecar_result = static_site_importer_cli_write_materialization_sidecar( $error_result, $assoc_args );
					if ( is_wp_error( $sidecar_result ) ) {
						WP_CLI::error( $sidecar_result->get_error_message(), 1 );
					}
				}
				$json = wp_json_encode( $error_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( false === $json ) {
					WP_CLI::error( $result->get_error_message() );
				}

				static_site_importer_cli_write_validation_output( (string) $json, $output );
				if ( $halt_on_failure ) {
					WP_CLI::halt( 1 );
				}

				return;
			}

			if ( true === $sidecar_contract ) {
				$sidecar_result = static_site_importer_cli_write_materialization_sidecar( $result, $assoc_args );
				if ( is_wp_error( $sidecar_result ) ) {
					WP_CLI::error( $sidecar_result->get_error_message(), 1 );
				}
			}
			if ( 'fixture-matrix' === $format ) {
				$result = Static_Site_Importer_Validation_Runtime::fixture_matrix_result( $result );
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

/** Build the common artifact input for lifecycle commands without provider setup. */
function static_site_importer_cli_artifact_input( array $assoc_args ) {
	if ( empty( $assoc_args['artifact'] ) ) {
		return new WP_Error( 'static_site_importer_cli_artifact_missing', 'Provide an artifact JSON file with --artifact.' );
	}
	$artifact_json = file_get_contents( (string) $assoc_args['artifact'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-provided artifact file.
	$artifact      = json_decode( false === $artifact_json ? '' : $artifact_json, true );
	if ( ! is_array( $artifact ) ) {
		return new WP_Error( 'static_site_importer_cli_artifact_invalid', 'The --artifact file must contain a JSON object.' );
	}
	return array(
		'artifact'  => $artifact,
		'slug'      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
		'name'      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
		'activate'  => ! isset( $assoc_args['no-activate'] ),
		'overwrite' => ! isset( $assoc_args['no-overwrite'] ),
	);
}

/**
 * A sidecar is an opt-in CLI contract. Legacy validate-artifact callers retain
 * their prior result and exit behavior; partial receipt identities fail early.
 *
 * @param array<string,mixed> $args CLI arguments.
 * @return bool|WP_Error True when required, false when absent.
 */
function static_site_importer_cli_materialization_sidecar_contract( array $args ) {
	$keys    = array( 'receipt-sidecar', 'receipt-run-id', 'receipt-step-id', 'receipt-attempt-id' );
	$present = array_filter( $keys, static fn( string $key ): bool => array_key_exists( $key, $args ) );
	if ( empty( $present ) ) {
		return false;
	}
	if ( count( $present ) !== count( $keys ) ) {
		return new WP_Error( 'static_site_importer_sidecar_contract_partial', 'Materialization sidecar requires --receipt-sidecar, --receipt-run-id, --receipt-step-id, and --receipt-attempt-id together.' );
	}
	return true;
}

/**
 * Persist compact matrix evidence before verbose WP-CLI output can be truncated.
 *
 * @param array<string,mixed> $result Validation result.
 * @param array<string,mixed> $args CLI arguments.
 */
function static_site_importer_cli_write_materialization_sidecar( array $result, array $args ) {
	$path       = isset( $args['receipt-sidecar'] ) ? (string) $args['receipt-sidecar'] : '';
	$fixture_id = isset( $result['fixture_id'] ) ? (string) $result['fixture_id'] : ( isset( $args['slug'] ) ? (string) $args['slug'] : '' );
	$run_id     = isset( $args['receipt-run-id'] ) ? (string) $args['receipt-run-id'] : '';
	$step_id    = isset( $args['receipt-step-id'] ) ? (string) $args['receipt-step-id'] : '';
	$attempt_id = isset( $args['receipt-attempt-id'] ) ? (string) $args['receipt-attempt-id'] : '';
	if ( '' === $path || ! static_site_importer_cli_sidecar_token( $fixture_id, 80 ) || ! static_site_importer_cli_sidecar_token( $run_id, 160 ) || 'import' !== $step_id || ! static_site_importer_cli_sidecar_token( $attempt_id, 80 ) ) {
		return new WP_Error( 'static_site_importer_sidecar_identity_invalid', 'Required materialization sidecar identity is missing or invalid.' );
	}
	$artifact_path = isset( $args['artifact'] ) ? (string) $args['artifact'] : '';
	$artifact_hash = is_readable( $artifact_path ) ? hash_file( 'sha256', $artifact_path ) : '';
	if ( ! is_string( $artifact_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $artifact_hash ) ) {
		return new WP_Error( 'static_site_importer_sidecar_artifact_hash_missing', 'Required materialization sidecar artifact hash could not be calculated.' );
	}
	$receipt                   = isset( $result['materialization_receipt'] ) && is_array( $result['materialization_receipt'] ) ? $result['materialization_receipt'] : array();
	$completed                 = isset( $receipt['completed'] ) && is_array( $receipt['completed'] ) ? $receipt['completed'] : array();
	$is_completed              = 'static-site-importer/materialization-receipt/v1' === ( $receipt['schema'] ?? '' ) && 'completed' === ( $receipt['status'] ?? '' ) && isset( $receipt['plan_hash'] ) && is_string( $receipt['plan_hash'] ) && preg_match( '/^(?:sha256:)?[a-f0-9]{64}$/', $receipt['plan_hash'] ) && isset( $completed['pages'], $completed['files'] ) && is_array( $completed['pages'] ) && is_array( $completed['files'] );
	$summary                   = $is_completed ? static_site_importer_cli_materialization_summary( $receipt, $result ) : static_site_importer_cli_failed_materialization_summary( $result );
	$sidecar                   = array(
		'schema'             => 'static-site-importer/materialization-runtime-sidecar/v1',
		'fixture_id'         => $fixture_id,
		'run_id'             => $run_id,
		'step_id'            => $step_id,
		'attempt_id'         => $attempt_id,
		'artifact_sha256'    => $artifact_hash,
		'provenance'         => array(
			'provider'        => (string) ( $result['runtime']['provider'] ?? 'static-site-importer/current-runtime' ),
			'provider_status' => $is_completed ? 'completed' : 'failed',
		),
		'durability'         => array(
			'file_fsync'      => function_exists( 'fsync' ) ? 'available' : 'unavailable',
			'directory_fsync' => function_exists( 'fsync' ) ? 'attempted' : 'unavailable',
		),
		'receipt'            => $summary,
		'command_result'     => array(
			'status'     => $is_completed ? 'completed' : 'failed',
			'success'    => $is_completed,
			'error_code' => $is_completed ? '' : static_site_importer_cli_sidecar_token_value( $result['error']['code'] ?? $result['code'] ?? 'import_failed', 80 ),
			'error_hash' => hash( 'sha256', (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		),
		'front_page_options' => array(
			'show_on_front' => static_site_importer_cli_sidecar_token_value( get_option( 'show_on_front' ), 20 ),
			'page_on_front' => min( 10000000, max( 0, (int) get_option( 'page_on_front' ) ) ),
		),
	);
	$sidecar['content_sha256'] = hash( 'sha256', (string) wp_json_encode( $sidecar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	$json                      = wp_json_encode( $sidecar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json || strlen( $json ) > 32768 ) {
		return new WP_Error( 'static_site_importer_sidecar_too_large', 'Required materialization sidecar exceeds its 32 KiB bound.' );
	}
	$directory = dirname( $path );
	if ( ! wp_mkdir_p( $directory ) ) {
		return new WP_Error( 'static_site_importer_sidecar_directory_failed', 'Required materialization sidecar directory could not be created.' );
	}
	$temp = tempnam( $directory, '.ssi-sidecar-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- same-directory temporary file is required for atomic rename.
	if ( false === $temp ) {
		return new WP_Error( 'static_site_importer_sidecar_temp_failed', 'Required materialization sidecar temporary file could not be created.' );
	}
	$handle = fopen( $temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- explicit CLI artifact publication.
	try {
		$bytes = strlen( $json ) + 1;
		if ( false === $handle || fwrite( $handle, $json . "\n" ) !== $bytes || ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) || ! fclose( $handle ) || ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory publication.
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup after failed atomic publish.
			}
			return new WP_Error( 'static_site_importer_sidecar_persist_failed', 'Required materialization sidecar could not be atomically persisted.' );
		}
		static_site_importer_cli_fsync_directory( $directory );
	} finally {
		if ( file_exists( $temp ) ) {
			unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only this bounded temporary sidecar.
		}
	}
	return true;
}

/** @return array<string,mixed> */
function static_site_importer_cli_failed_materialization_summary( array $result ): array {
	$error_code = static_site_importer_cli_sidecar_token_value( $result['error']['code'] ?? $result['code'] ?? 'import_failed', 80 );
	return array(
		'schema'          => 'static-site-importer/materialization-receipt/v1',
		'status'          => 'failed',
		'page_count'      => 0,
		'file_count'      => 0,
		'operation_count' => 0,
		'loss_count'      => 1,
		'failure_code'    => $error_code ? $error_code : 'import_failed',
	);
}

function static_site_importer_cli_fsync_directory( string $directory ): void {
	if ( ! function_exists( 'fsync' ) ) {
		return;
	}
	$handle = @fopen( $directory, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- best-effort directory durability on supported platforms.
	if ( false !== $handle ) {
		@fsync( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unsupported directory fsync remains non-fatal.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the best-effort directory handle.
	}
}

/** @return array<string,mixed> */
function static_site_importer_cli_materialization_summary( array $receipt, array $result ): array {
	$completed      = isset( $receipt['completed'] ) && is_array( $receipt['completed'] ) ? $receipt['completed'] : array();
	$operations     = isset( $completed['operations'] ) && is_array( $completed['operations'] ) ? $completed['operations'] : array();
	$diagnostics    = isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array();
	$operation_rows = array();
	$loss_rows      = array();
	foreach ( array_slice( $operations, 0, 25 ) as $operation ) {
		if ( is_array( $operation ) ) {
			$row = array_filter(
				array(
					'kind'        => static_site_importer_cli_sidecar_token_value( $operation['kind'] ?? $operation['type'] ?? $operation['operation'] ?? '', 80 ),
					'status'      => static_site_importer_cli_sidecar_token_value( $operation['status'] ?? '', 40 ),
					'reason_code' => static_site_importer_cli_sidecar_token_value( $operation['reason_code'] ?? '', 80 ),
					'hash'        => hash( 'sha256', (string) wp_json_encode( $operation ) ),
				)
			);
			if ( ! empty( $row['kind'] ) ) {
				$operation_rows[] = $row;
			}
		}
	}
	foreach ( array_slice( $diagnostics, 0, 25 ) as $diagnostic ) {
		if ( is_array( $diagnostic ) ) {
			$row = array_filter(
				array(
					'kind'        => static_site_importer_cli_sidecar_token_value( $diagnostic['kind'] ?? $diagnostic['code'] ?? $diagnostic['type'] ?? '', 80 ),
					'reason_code' => static_site_importer_cli_sidecar_token_value( $diagnostic['reason_code'] ?? '', 80 ),
					'hash'        => hash( 'sha256', (string) wp_json_encode( $diagnostic ) ),
				)
			);
			if ( ! empty( $row['kind'] ) ) {
				$loss_rows[] = $row;
			}
		}
	}
	$layout    = isset( $receipt['computed_layout'] ) && is_array( $receipt['computed_layout'] ) ? $receipt['computed_layout'] : array();
	$plan_hash = isset( $receipt['plan_hash'] ) && is_string( $receipt['plan_hash'] ) && preg_match( '/^(?:sha256:)?[a-f0-9]{64}$/', $receipt['plan_hash'] ) ? $receipt['plan_hash'] : '';
	return array(
		'schema'                 => 'static-site-importer/materialization-receipt/v1',
		'status'                 => 'completed',
		'plan_hash'              => $plan_hash,
		'page_count'             => min( 10000000, count( $completed['pages'] ?? array() ) ),
		'file_count'             => min( 10000000, count( $completed['files'] ?? array() ) ),
		'operation_count'        => min( 10000000, count( $operations ) ),
		'loss_count'             => min( 10000000, count( $diagnostics ) ),
		'provider_totals'        => array( 'completed' => ! empty( $result['runtime']['provider'] ) ? 1 : 0 ),
		'computed_layout_totals' => array_filter(
			array(
				'applied'    => isset( $layout['applied'] ) ? (int) $layout['applied'] : null,
				'losses'     => isset( $layout['losses'] ) ? (int) $layout['losses'] : null,
				'operations' => count( array_filter( $operations, static fn( $operation ): bool => is_array( $operation ) && false !== strpos( (string) wp_json_encode( $operation ), 'computed_layout' ) ) ),
			),
			static fn( $value ): bool => null !== $value
		),
		'operation_rows'         => $operation_rows,
		'loss_rows'              => $loss_rows,
		'truncated'              => array(
			'operation_rows' => count( $operations ) > 25,
			'loss_rows'      => count( $diagnostics ) > 25,
		),
	);
}

function static_site_importer_cli_sidecar_token( $value, int $maximum ): bool {
	return is_string( $value ) && 0 < strlen( $value ) && $maximum >= strlen( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value );
}

function static_site_importer_cli_sidecar_token_value( $value, int $maximum ): string {
	$value = is_scalar( $value ) ? (string) $value : '';
	return static_site_importer_cli_sidecar_token( $value, $maximum ) ? $value : '';
}
