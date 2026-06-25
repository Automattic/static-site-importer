<?php
/**
 * Smoke coverage for the SSI Codebox validation product contract.
 *
 * Run from the repository root:
 * php tests/smoke-codebox-validation-contract.php
 *
 * @package StaticSiteImporter
 */

namespace {
	$GLOBALS['static_site_importer_test_filters'] = array();

	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $priority, $accepted_args );
		$GLOBALS['static_site_importer_test_filters'][ $hook_name ][] = $callback;
	}

	function has_filter( string $hook_name ): bool {
		return ! empty( $GLOBALS['static_site_importer_test_filters'][ $hook_name ] );
	}

	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['static_site_importer_test_filters'][ $hook_name ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function sanitize_title( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );

		return trim( (string) $value, '-' );
	}

	function sanitize_text_field( string $value ): string {
		return trim( wp_strip_all_tags( $value ) );
	}

	function sanitize_key( string $value ): string {
		$value = strtolower( $value );

		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?: '';
	}

	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}

	function wp_upload_dir(): array {
		$dir = sys_get_temp_dir() . '/ssi-codebox-validation-smoke-uploads';

		return array( 'basedir' => $dir );
	}

	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}

	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000000';
	}

	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code, string $message ) {
				$this->code    = $code;
				$this->message = $message;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-codebox-validation.php';
	Static_Site_Importer_Codebox_Validation::register_default_provider();

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$blocked = Static_Site_Importer_Codebox_Validation::validate(
		array(
			'artifact' => array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ),
			'slug'     => 'Fixture Import',
		)
	);

	$assert( ! is_wp_error( $blocked ), 'blocked-result-is-structured' );
	$assert( false === ( $blocked['success'] ?? true ), 'blocked-result-is-unsuccessful' );
	$assert( 'blocked' === ( $blocked['status'] ?? '' ), 'blocked-status' );
	$assert( 'static-site-importer/codebox-validation-result/v1' === ( $blocked['schema'] ?? '' ), 'result-schema' );
	$assert( 'static-site-importer/codebox-validation-artifacts/v1' === ( $blocked['artifacts']['schema'] ?? '' ), 'artifact-schema' );
	$assert( 'static-site-importer/import-diagnostics/v1' === ( $blocked['fixture_diagnostics']['schema'] ?? '' ), 'blocked-fixture-diagnostics-schema' );
	$assert( 'fixture-import' === ( $blocked['fixture_diagnostics']['fixture']['slug'] ?? '' ), 'blocked-fixture-slug' );
	$assert( 'runtime_provider_unavailable' === ( $blocked['fixture_diagnostics']['diagnostics'][0]['type'] ?? '' ), 'blocked-fixture-diagnostic-type' );
	$assert( ! empty( $blocked['upstream_gaps'][0]['needed_shape'] ), 'upstream-gap-is-concrete' );

	add_filter(
		'wp_codebox_host_delegation_request',
		static function ( $result, array $request ): array {
			unset( $result );

			return array(
				'success'    => true,
				'schema'     => 'wp-codebox/host-delegation-result/v1',
				'status'     => 'completed',
				'request_id' => (string) ( $request['request_id'] ?? '' ),
				'provider'   => 'test-host-provider',
				'result'     => array(
					'summary'   => array( 'quality_pass' => true ),
					'artifacts' => array(
						'import_report'           => array( 'artifact_ref' => 'artifact://delegated/import-report.json' ),
						'block_validation_result' => array( 'artifact_ref' => 'artifact://delegated/block-validation.json' ),
						'browser_render_evidence' => array( 'artifact_ref' => 'artifact://delegated/browser-render.json' ),
					),
				),
			);
		},
		10,
		2
	);

	$delegated = Static_Site_Importer_Codebox_Validation::validate(
		array(
			'artifact' => array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ),
			'name'     => 'Delegated Fixture Import',
		)
	);

	$assert( true === ( $delegated['success'] ?? false ), 'delegated-provider-success' );
	$assert( 'succeeded' === ( $delegated['status'] ?? '' ), 'delegated-provider-status' );
	$assert( 'test-host-provider' === ( $delegated['runtime']['provider'] ?? '' ), 'delegated-runtime-provider' );
	$assert( 'completed' === ( $delegated['runtime']['host_delegation_status'] ?? '' ), 'delegated-host-status' );
	$assert( 'artifact://delegated/import-report.json' === ( $delegated['artifacts']['import_report']['artifact_ref'] ?? '' ), 'delegated-import-report-ref' );

	add_filter(
		'static_site_importer_codebox_validation_result',
		static function ( $result, array $request ): array {
			unset( $result, $request );

			return array(
				'success'   => true,
				'summary'   => array( 'quality_pass' => true ),
				'runtime'   => array( 'provider' => 'test-codebox' ),
				'import_report' => array(
					'quality'       => array(
						'core_html_block_count'                 => 1,
						'svg_materialization_failure_count'     => 1,
						'runtime_dependency_parity_issue_count' => 1,
					),
					'diagnostics'   => array(
						array(
							'id'          => 'diag-core-html',
							'type'        => 'core_html_block',
							'reason_code' => 'generated_document_contains_core_html',
							'source_path' => 'templates/front-page.html',
							'selector'    => 'a.wp-block-button__link',
						),
						array(
							'id'          => 'diag-svg',
							'type'        => 'svg_materialization_failure',
							'code'        => 'svg_missing_payload',
							'source_path' => 'assets/icon.svg',
						),
						array(
							'id'          => 'diag-image',
							'type'        => 'dropped_image_asset',
							'code'        => 'image_missing',
							'source_path' => 'assets/hero.jpg',
						),
					),
					'blocks_engine' => array(
						'conversion_report'         => array(
							'diagnostics' => array(
								array(
									'id'          => 'be-button-style',
									'type'        => 'button_style_loss',
									'code'        => 'button_radius_dropped',
									'source_path' => 'index.html',
									'selector'    => 'button.cta',
								),
							),
						),
						'runtime_dependency_parity' => array(
							'missing_dom_targets' => array(
								array(
									'id'          => 'runtime-missing-target',
									'type'        => 'runtime_dependency_missing_dom_target',
									'code'        => 'missing_dom_target',
									'source_path' => 'scripts/app.js',
									'selector'    => '#cart-drawer',
								),
							),
						),
					),
				),
				'artifacts' => array(
					'generated_theme'         => array( 'artifact_ref' => 'artifact://validation/theme', 'path' => '/Users/local/theme' ),
					'theme_archive'           => array( 'url' => 'https://artifacts.example/theme.zip', 'sha256' => 'abc' ),
					'import_report'           => array( 'artifact_ref' => 'artifact://validation/import-report.json' ),
					'block_validation_result' => array( 'artifact_ref' => 'artifact://validation/block-validation.json' ),
					'browser_render_evidence' => array( 'artifact_ref' => 'artifact://validation/browser-render.json' ),
					'screenshots'             => array( array( 'artifact_ref' => 'artifact://validation/home.png' ) ),
					'diffs'                   => array( array( 'artifact_ref' => 'artifact://validation/home.diff.png' ) ),
				),
			);
		}
	);

	$provided = Static_Site_Importer_Codebox_Validation::validate(
		array(
			'artifact' => array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ),
			'name'     => 'Fixture Import',
		)
	);

	$assert( true === ( $provided['success'] ?? false ), 'provider-success' );
	$assert( 'succeeded' === ( $provided['status'] ?? '' ), 'provider-status' );
	$assert( 'test-codebox' === ( $provided['runtime']['provider'] ?? '' ), 'runtime-provider' );
	$assert( 'artifact://validation/theme' === ( $provided['artifacts']['generated_theme']['artifact_ref'] ?? '' ), 'generated-theme-ref' );
	$assert( ! isset( $provided['artifacts']['generated_theme']['path'] ), 'local-path-removed-from-reviewer-artifact' );
	$assert( 'artifact://validation/import-report.json' === ( $provided['artifacts']['import_report']['artifact_ref'] ?? '' ), 'import-report-ref' );
	$assert( 'artifact://validation/block-validation.json' === ( $provided['artifacts']['block_validation_result']['artifact_ref'] ?? '' ), 'block-validation-ref' );
	$assert( 'artifact://validation/browser-render.json' === ( $provided['artifacts']['browser_render_evidence']['artifact_ref'] ?? '' ), 'browser-render-ref' );
	$assert( 1 === count( $provided['artifacts']['screenshots'] ?? array() ), 'screenshot-ref-count' );
	$assert( 1 === count( $provided['artifacts']['diffs'] ?? array() ), 'diff-ref-count' );
	$assert( 'static-site-importer/import-diagnostics/v1' === ( $provided['fixture_diagnostics']['schema'] ?? '' ), 'provided-fixture-diagnostics-schema' );
	$assert( 1 === ( $provided['fixture_diagnostics']['quality_counts']['core_html_block_count'] ?? 0 ), 'provided-quality-core-html-count' );
	$assert( 1 === ( $provided['fixture_diagnostics']['diagnostic_summary']['type']['core_html_block'] ?? 0 ), 'provided-diagnostic-summary-core-html' );
	$assert( 1 === ( $provided['fixture_diagnostics']['diagnostic_summary']['repair_bucket']['fallback_block'] ?? 0 ), 'provided-diagnostic-summary-repair-bucket' );
	$assert( 1 <= ( $provided['fixture_diagnostics']['diagnostic_summary']['parser_owner']['blocks-engine'] ?? 0 ), 'provided-diagnostic-summary-parser-owner' );
	$assert( 'templates/front-page.html' === ( $provided['fixture_diagnostics']['diagnostics'][0]['source_path'] ?? '' ), 'provided-diagnostic-source-path' );
	$assert( 'a.wp-block-button__link' === ( $provided['fixture_diagnostics']['diagnostics'][0]['selector'] ?? '' ), 'provided-diagnostic-selector' );
	$assert( 'fallback_block' === ( $provided['fixture_diagnostics']['diagnostics'][0]['repair_bucket'] ?? '' ), 'provided-diagnostic-repair-bucket' );
	$assert( 'blocks-engine' === ( $provided['fixture_diagnostics']['diagnostics'][0]['parser_owner'] ?? '' ), 'provided-diagnostic-parser-owner' );
	$assert( '#cart-drawer' === ( $provided['fixture_diagnostics']['runtime_dependency_target_gaps'][0]['selector'] ?? '' ), 'provided-runtime-target-gap-selector' );
	$assert( 'assets/hero.jpg' === ( $provided['fixture_diagnostics']['asset_diagnostics'][0]['source_path'] ?? '' ), 'provided-asset-diagnostic-source' );
	$assert( 'assets/icon.svg' === ( $provided['fixture_diagnostics']['svg_diagnostics'][0]['source_path'] ?? '' ), 'provided-svg-diagnostic-source' );
	$assert( 'button.cta' === ( $provided['fixture_diagnostics']['button_style_loss_hints'][0]['selector'] ?? '' ), 'provided-button-style-loss-selector' );

	$GLOBALS['static_site_importer_test_filters']['static_site_importer_codebox_validation_result'] = array();
	Static_Site_Importer_Codebox_Validation::register_default_provider();

	if ( ! class_exists( 'Static_Site_Importer_Theme_Generator' ) ) {
		class Static_Site_Importer_Theme_Generator {
			public static function import_website_artifact( array $artifact, array $args = array() ): array {
				unset( $artifact );

				$report_path = (string) ( $args['report'] ?? '' );
				if ( '' !== $report_path ) {
					$dir = dirname( $report_path );
					if ( ! is_dir( $dir ) ) {
						mkdir( $dir, 0777, true );
					}

					file_put_contents(
						$report_path,
						json_encode(
							array(
								'quality'     => array(
									'pass'                => false,
									'invalid_block_count' => 2,
								),
								'diagnostics' => array(
									array(
										'id'          => 'local-invalid-blocks',
										'type'        => 'invalid_block_content',
										'reason_code' => 'block_validation_failed',
									),
								),
							),
							JSON_PRETTY_PRINT
						)
					);
					file_put_contents( trailingslashit( $dir ) . 'import-validation-result.json', '{"success":false}' );
					file_put_contents( trailingslashit( $dir ) . 'finding-packets.json', '[]' );
				}

				return array(
					'theme_slug'                      => (string) ( $args['slug'] ?? 'local-validation-fixture' ),
					'external_report_path'            => $report_path,
					'external_validation_result_path' => trailingslashit( dirname( $report_path ) ) . 'import-validation-result.json',
					'external_finding_packets_path'   => trailingslashit( dirname( $report_path ) ) . 'finding-packets.json',
					'quality'                         => array(
						'pass'                => false,
						'invalid_block_count' => 2,
					),
				);
			}
		}
	}

	$local = Static_Site_Importer_Codebox_Validation::validate(
		array(
			'artifact' => array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ),
			'slug'     => 'Local Fixture',
			'name'     => 'Local Fixture',
		)
	);

	$assert( false === ( $local['success'] ?? true ), 'local-provider-quality-fails' );
	$assert( 'failed' === ( $local['status'] ?? '' ), 'local-provider-status' );
	$assert( 'static-site-importer/current-codebox-runtime' === ( $local['runtime']['provider'] ?? '' ), 'local-provider-runtime' );
	$assert( 'local-fixture' === ( $local['artifacts']['generated_theme']['artifact_ref'] ?? '' ), 'local-provider-theme-ref' );
	$assert( 'import-report.json' === ( $local['artifacts']['import_report']['artifact_ref'] ?? '' ), 'local-provider-import-report-ref' );
	$assert( 'import-validation-result.json' === ( $local['artifacts']['block_validation_result']['artifact_ref'] ?? '' ), 'local-provider-block-validation-ref' );
	$assert( 2 === ( $local['fixture_diagnostics']['quality_counts']['invalid_block_count'] ?? 0 ), 'local-provider-invalid-block-count' );
	$assert( 'invalid_block_content' === ( $local['fixture_diagnostics']['diagnostics'][0]['type'] ?? '' ), 'local-provider-diagnostic-type' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: Codebox validation contract smoke passed (' . $assertions . " assertions)\n";
}
