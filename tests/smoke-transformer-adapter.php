<?php
/**
 * Smoke test: SSI transformer adapter maps Blocks Engine php-transformer output.
 *
 * Run from the repository root:
 * php tests/smoke-transformer-adapter.php
 *
 * @package StaticSiteImporter
 */

namespace {
	function blocks_engine_php_transformer_compile_artifact( array $artifact, array $options = array() ): array {
		$GLOBALS['ssi_transformer_adapter_compile_calls'][] = array( $artifact, $options );
		if ( isset( $GLOBALS['ssi_transformer_adapter_result_override'] ) && is_array( $GLOBALS['ssi_transformer_adapter_result_override'] ) ) {
			return $GLOBALS['ssi_transformer_adapter_result_override'];
		}

		return array(
						'schema'            => 'blocks-engine/php-transformer/result/v1',
						'status'            => 'success',
						'components'        => array(),
						'block_types'       => array(
							array( 'name' => 'core/paragraph' ),
						),
						'source_reports'    => array(
							'artifact'      => array(
								'schema'          => 'blocks-engine/php-transformer/site-artifact/v1',
								'original_schema' => 'blocks-engine/php-transformer/site-artifact/v1',
								'entry_path'      => 'website/index.html',
								'entrypoints'     => array( 'website/index.html' ),
								'file_count'      => 3,
								'accepted_count'  => 3,
								'rejected_count'  => 0,
								'bytes'           => 100,
								'files_by_kind'   => array( 'html' => 2, 'asset' => 1 ),
								'files_by_role'   => array( 'document' => 2, 'stylesheet' => 1 ),
								'files_by_mime'   => array( 'text/html' => 2, 'text/css' => 1 ),
								'source_hash'     => 'abc123',
							),
							'materialization_plan' => array(
								'schema'      => 'blocks-engine/php-transformer/materialization-plan/v1',
								'source_schema' => 'blocks-engine/php-transformer/compiled-site/v1',
								'source_hash' => 'abc123',
								'entry_path'  => 'website/index.html',
								'pages'       => array(
									array(
										'source_path'  => 'website/index.html',
										'entrypoint'   => true,
										'post_type'    => 'page',
										'slug'         => 'home-canonical',
										'title'        => 'Home Canonical',
										'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
									),
									array(
										'source_path' => 'website/menu.html',
										'entrypoint'  => false,
										'post_type'   => 'page',
										'slug'        => 'menu',
										'title'       => 'Menu Page',
									),
									array(
										'source_path' => 'content/about.md',
										'entrypoint'  => false,
										'post_type'   => 'page',
										'slug'        => 'about-canonical',
										'title'       => 'About',
									),
									array(
										'source_path'    => 'products/rye-loaf.md',
										'entrypoint'     => false,
										'post_type'      => 'product',
										'slug'           => 'rye-loaf-canonical',
										'title'          => 'Rye Loaf',
										'regular_price'  => '12',
										'categories'     => array( 'Bread' ),
									),
								),
								'assets'      => array(
									array(
										'path'    => 'assets/native-site.css',
										'role'    => 'stylesheet',
										'kind'    => 'css',
										'content' => 'body { color: black; }',
									),
								),
								'theme'       => array(
									'stylesheets' => array( 'assets/site.css' ),
								),
							),
							'conversion_report' => array(
								'schema'                 => 'blocks-engine/php-transformer/conversion-report/v1',
								'status'                 => 'success',
								'serialized_blocks'      => '<!-- wp:paragraph --><p>Source report Home</p><!-- /wp:paragraph -->',
								'diagnostics'            => array(
									array(
										'code'    => 'source_report_diagnostic',
										'message' => 'Source report diagnostic.',
									),
								),
								'fallbacks'              => array(
									array(
										'source_path'           => 'website/index.html',
										'selector'              => 'iframe.booking-widget',
										'reason_code'           => 'unsupported_interactive_embed',
										'block_name'            => 'core/html',
										'source_html_preview'   => '<iframe class="booking-widget"></iframe>',
										'emitted_block_preview' => '<!-- wp:html --><iframe class="booking-widget"></iframe><!-- /wp:html -->',
									),
								),
								'interaction_candidates' => array(
									array(
										'source_path'         => 'website/index.html',
										'selector'            => 'button.reserve',
										'kind'                => 'button',
										'source_html_preview' => '<button class="reserve">Reserve</button>',
									),
								),
							),
						),
						'blocks'            => array(
							array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ),
						),
						'serialized_blocks' => '<!-- wp:paragraph --><p>Top-level Home</p><!-- /wp:paragraph -->',
						'conversion_report' => array(
							'status'            => 'success',
							'serialized_blocks' => '<!-- wp:paragraph --><p>Native report Home</p><!-- /wp:paragraph -->',
							'diagnostics'       => array(
								array(
									'code'    => 'native_report_diagnostic',
									'message' => 'Native conversion report diagnostic.',
								),
							),
							'fallbacks'         => array(
								array(
									'source' => 'native-conversion-report',
									'count'  => 0,
								),
							),
						),
						'documents'         => array(
							array(
								'source_path'  => 'content/about.md',
								'slug'         => 'about',
								'title'        => 'About',
								'block_markup' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
							),
						),
						'assets'            => array(
							array( 'path' => 'assets/top-level-site.css', 'role' => 'stylesheet' ),
						),
						'diagnostics'       => array(
							array(
								'code'    => 'top_level_diagnostic',
								'message' => 'Top-level diagnostic.',
							),
						),
						'fallbacks'         => array(
							array(
								'source' => 'top-level',
								'count'  => 1,
							),
						),
						'provenance'        => array(
							array( 'source_hash' => 'abc123' ),
						),
		);
	}

	function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
		$GLOBALS['ssi_transformer_adapter_format_conversion_calls'][] = array( $content, $from, $to, $options );
		return array(
			'schema'    => 'blocks-engine/php-transformer/result/v1',
			'status'    => 'success',
			'documents' => array(
				array(
					'format'  => 'html',
					'content' => '<p>Blocks Engine rendered</p>',
				),
			),
		);
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	$GLOBALS['ssi_transformer_adapter_format_conversion_calls'] = array();
	$GLOBALS['ssi_transformer_adapter_compile_calls'] = array();

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

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $key ): string {
			$key = strtolower( $key );
			return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-report-diagnostics.php';

	$failures   = array();
	$assertions = 0;
	$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
		}
	};

	$adapter = new Static_Site_Importer_Transformer_Adapter();
	$html    = $adapter->blocks_to_html( '<!-- wp:paragraph --><p>Edited</p><!-- /wp:paragraph -->', array( 'source' => 'smoke' ) );
	$assert( '<p>Blocks Engine rendered</p>' === $html, 'format-conversion-result-is-used' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_format_conversion_calls'] ), 'format-conversion-called' );
	$assert( 'blocks' === ( $GLOBALS['ssi_transformer_adapter_format_conversion_calls'][0][1] ?? '' ), 'format-conversion-from-format' );
	$assert( 'html' === ( $GLOBALS['ssi_transformer_adapter_format_conversion_calls'][0][2] ?? '' ), 'format-conversion-to-format' );
	$assert( 'smoke' === ( $GLOBALS['ssi_transformer_adapter_format_conversion_calls'][0][3]['source'] ?? '' ), 'format-conversion-options-forwarded' );

	$compiled  = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ), array( 'include_conversion_report' => true ) );
	$artifacts = $compiled['artifacts'] ?? array();
	$site      = $artifacts['site'] ?? array();
	$pages     = $site['pages'] ?? array();
	$documents = $artifacts['documents'] ?? array();
	$products  = $compiled['products_manifest'] ?? array();
	$assert( ! is_wp_error( $compiled ), 'native-compile-succeeds' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_compile_calls'] ), 'plugin-compile-helper-called' );
	$assert( true === ( $GLOBALS['ssi_transformer_adapter_compile_calls'][0][1]['include_conversion_report'] ?? false ), 'compile-options-forwarded-as-native-report-request' );
	$assert( 'blocks-engine/php-transformer/result/v1' === ( $compiled['schema'] ?? '' ), 'native-result-schema-is-preserved' );
	$assert( 'success' === ( $compiled['conversion_report']['status'] ?? '' ), 'conversion-report-shape-preserved' );
	$assert( '<!-- wp:paragraph --><p>Source report Home</p><!-- /wp:paragraph -->' === ( $compiled['conversion_report']['serialized_blocks'] ?? '' ), 'conversion-report-prefers-source-report-serialized-blocks' );
	$assert( 'source_report_diagnostic' === ( $compiled['conversion_report']['diagnostics'][0]['code'] ?? '' ), 'conversion-report-prefers-source-report-diagnostics' );
	$assert( 'website/index.html' === ( $compiled['conversion_report']['fallbacks'][0]['source_path'] ?? '' ), 'conversion-report-prefers-source-report-fallbacks' );
	$assert( 'button.reserve' === ( $compiled['conversion_report']['interaction_candidates'][0]['selector'] ?? '' ), 'conversion-report-preserves-interaction-candidates' );
	$assert( 'top_level_diagnostic' !== ( $compiled['conversion_report']['diagnostics'][0]['code'] ?? '' ), 'conversion-report-ignores-top-level-diagnostics' );
	$assert( 'top-level' !== ( $compiled['conversion_report']['fallbacks'][0]['source'] ?? '' ), 'conversion-report-ignores-top-level-fallbacks' );
	$assert( 'website/index.html' === ( $compiled['input']['entry_path'] ?? '' ), 'native-artifact-report-preserved-as-input' );
	$assert( 'blocks-engine/php-transformer/materialization-plan/v1' === ( $site['schema'] ?? '' ), 'native-materialization-plan-contract-is-used' );
	$assert( 4 === count( $pages ), 'native-keeps-materialization-plan-pages-without-adapter-filtering' );
	$assert( 'website/index.html' === ( $pages[0]['source_path'] ?? '' ), 'native-entry-source-path' );
	$assert( 'home-canonical' === ( $pages[0]['slug'] ?? '' ), 'native-entry-slug-from-materialization-plan' );
	$assert( true === ( $pages[0]['entrypoint'] ?? false ), 'native-entrypoint' );
	$assert( 'about-canonical' === ( $pages[2]['slug'] ?? '' ), 'native-route-slug-from-materialization-plan' );
	$assert( 1 === count( $documents ), 'native-documents-preserve-transformer-documents-without-site-report-synthesis' );
	$assert( 'content/about.md' === ( $documents[0]['source_path'] ?? '' ), 'native-document-from-transformer-documents' );
	$assert( 'assets/native-site.css' === ( $artifacts['files'][0]['path'] ?? '' ), 'native-materialization-plan-assets-drive-artifact-files' );
	$assert( 'assets/top-level-site.css' !== ( $artifacts['files'][0]['path'] ?? '' ), 'top-level-assets-do-not-override-native-materialization-plan-assets' );
	$assert( 'rye-loaf-canonical' === ( $products[0]['slug'] ?? '' ), 'native-product-slug-mapped-from-generic-report' );
	$assert( '12.00' === ( $products[0]['regular_price'] ?? '' ), 'native-product-price-normalized-from-generic-report' );
	$assert( array( 'Bread' ) === ( $products[0]['categories'] ?? array() ), 'native-product-categories-mapped-from-generic-report' );

	$native_report_compiled = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ), array( 'include_conversion_report' => true ) );
	$assert( ! is_wp_error( $native_report_compiled ), 'native-report-compile-succeeds' );
	$assert( 2 === count( $GLOBALS['ssi_transformer_adapter_compile_calls'] ), 'plugin-compile-helper-called-for-native-report' );
	$assert( true === ( $GLOBALS['ssi_transformer_adapter_compile_calls'][1][1]['include_conversion_report'] ?? false ), 'native-report-option-forwarded' );
	$assert( isset( $native_report_compiled['conversion_report'] ) && is_array( $native_report_compiled['conversion_report'] ), 'native-report-request-exposes-conversion-report' );

	$report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	Static_Site_Importer_Report_Diagnostics::record_blocks_engine_result( $report, $compiled );
	Static_Site_Importer_Report_Diagnostics::finalize_report( $report, array() );
	$assert( 1 === ( $report['blocks_engine']['conversion_report']['fallback_count'] ?? 0 ), 'import-report-records-native-fallback-count' );
	$assert( 1 === ( $report['blocks_engine']['conversion_report']['interaction_candidate_count'] ?? 0 ), 'import-report-records-native-interaction-candidate-count' );
	$assert( 'button.reserve' === ( $report['blocks_engine']['conversion_report']['interaction_candidates'][0]['selector'] ?? '' ), 'import-report-records-native-interaction-candidates' );
	$assert( 1 === ( $report['quality']['interaction_candidate_count'] ?? 0 ), 'quality-records-interaction-candidate-count' );
	$assert( 'reported' === ( $report['import_validation_result']['quality_gates']['interaction_candidates']['status'] ?? '' ), 'validation-gate-reports-interaction-candidates' );
	$assert( 'unsupported_html_fallback' === ( $report['diagnostics'][0]['type'] ?? '' ), 'native-fallback-becomes-normalized-diagnostic' );
	$assert( 'unsupported_interactive_embed' === ( $report['diagnostics'][0]['reason_code'] ?? '' ), 'native-fallback-preserves-reason-code' );
	$assert( 'replace_unsupported_html' === ( $report['diagnostics'][0]['suggested_repair_class'] ?? '' ), 'native-fallback-gets-repair-class' );
	$assert( 'interaction_candidate' === ( $report['diagnostics'][1]['type'] ?? '' ), 'interaction-candidate-becomes-report-diagnostic' );
	$assert( 2 === ( $report['finding_packets']['count'] ?? 0 ), 'native-report-diagnostics-create-finding-packets' );

	$GLOBALS['ssi_transformer_adapter_result_override'] = array(
		'schema'            => 'blocks-engine/php-transformer/result/v1',
		'status'            => 'success',
		'source_reports'    => array(
			'artifact'              => array( 'entry_path' => 'website/index.html' ),
			'materialization_plan' => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(),
			),
		),
		'serialized_blocks' => '<!-- wp:paragraph --><p>Top level</p><!-- /wp:paragraph -->',
		'conversion_report' => array(
			'schema'            => 'blocks-engine/php-transformer/conversion-report/v1',
			'serialized_blocks' => '<!-- wp:paragraph --><p>Tagged dependency report</p><!-- /wp:paragraph -->',
			'fallbacks'         => array(
				array( 'source' => 'tagged-dependency-top-level' ),
			),
		),
	);
	$tagged_dependency_compiled = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
	unset( $GLOBALS['ssi_transformer_adapter_result_override'] );
	$assert( ! is_wp_error( $tagged_dependency_compiled ), 'tagged-dependency-report-compile-succeeds' );
	$assert( '<!-- wp:paragraph --><p>Tagged dependency report</p><!-- /wp:paragraph -->' === ( $tagged_dependency_compiled['conversion_report']['serialized_blocks'] ?? '' ), 'top-level-conversion-report-remains-compatible' );
	$assert( 'tagged-dependency-top-level' === ( $tagged_dependency_compiled['conversion_report']['fallbacks'][0]['source'] ?? '' ), 'top-level-conversion-report-fallbacks-preserved' );

	$GLOBALS['ssi_transformer_adapter_result_override'] = array(
		'schema' => 'blocks-engine/php-transformer/result/v1',
		'status' => 'success',
		'source_reports' => array(
			'artifact' => array( 'entry_path' => 'website/index.html' ),
		),
	);
	$missing_plan = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
	unset( $GLOBALS['ssi_transformer_adapter_result_override'] );
	$assert( is_wp_error( $missing_plan ), 'missing-materialization-plan-errors' );
	$assert( 'static_site_importer_transformer_missing_materialization_plan' === ( is_wp_error( $missing_plan ) ? $missing_plan->get_error_code() : '' ), 'missing-materialization-plan-error-code' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: transformer adapter smoke passed (' . $assertions . " assertions)\n";
}
