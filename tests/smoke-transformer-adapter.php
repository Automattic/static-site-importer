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
		$GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][] = array( $artifact, $options );

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
								'original_schema' => 'block-artifact-compiler/website-artifact/v1',
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
							'compiled_site' => array(
								'schema'      => 'blocks-engine/php-transformer/compiled-site/v1',
								'source_hash' => 'abc123',
								'entry_path'  => 'website/index.html',
								'pages'       => array(
									array(
										'source_path'  => 'website/index.html',
										'entrypoint'   => true,
										'slug'         => 'index',
										'title'        => 'Home Page',
										'block_markup' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
									),
									array(
										'source_path' => 'website/menu.html',
										'entrypoint'  => false,
										'slug'        => 'menu',
										'title'       => 'Menu Page',
									),
									array(
										'source_path' => 'content/about.md',
										'entrypoint'  => false,
										'slug'        => 'about',
										'title'       => 'About',
									),
									array(
										'source_path'    => 'products/rye-loaf.md',
										'entrypoint'     => false,
										'post_type'      => 'product',
										'slug'           => 'rye-loaf',
										'title'          => 'Rye Loaf',
										'regular_price'  => '12',
										'categories'     => array( 'Bread' ),
									),
								),
								'assets'      => array(
									array( 'path' => 'assets/site.css', 'role' => 'stylesheet' ),
								),
								'theme'       => array(
									'stylesheets' => array( 'assets/site.css' ),
								),
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
						),
						'blocks'            => array(
							array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ),
						),
						'serialized_blocks' => '<!-- wp:paragraph --><p>Legacy top-level Home</p><!-- /wp:paragraph -->',
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
							array( 'path' => 'assets/legacy-site.css', 'role' => 'stylesheet' ),
						),
						'diagnostics'       => array(
							array(
								'code'    => 'legacy_top_level_diagnostic',
								'message' => 'Legacy top-level diagnostic.',
							),
						),
						'fallbacks'         => array(
							array(
								'source' => 'legacy-top-level',
								'count'  => 1,
							),
						),
						'legacy_mapping'    => array(
							'block-artifact-compiler/result/v1' => array(
								'wordpress_artifacts.site.pages.0.slug' => 'legacy.slug',
								'wordpress_artifacts.documents.0.source_path' => 'legacy.documents.0.source_path',
							),
						),
						'legacy'            => array(
							'slug'      => 'legacy-home',
							'documents' => array(
								array( 'source_path' => 'legacy/about.html' ),
							),
						),
						'provenance'        => array(
							array( 'source_hash' => 'abc123' ),
						),
		);
	}

	function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $options = array() ): array {
		$GLOBALS['ssi_transformer_adapter_format_bridge_calls'][] = array( $content, $from, $to, $options );
		return array(
			'schema'    => 'blocks-engine/php-transformer/result/v1',
			'status'    => 'success',
			'documents' => array(
				array(
					'format'  => 'html',
					'content' => '<p>FormatBridge rendered</p>',
				),
			),
		);
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	$GLOBALS['ssi_transformer_adapter_format_bridge_calls'] = array();
	$GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'] = array();

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

	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';

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
	$assert( '<p>FormatBridge rendered</p>' === $html, 'format-bridge-result-is-used' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'] ), 'format-bridge-called' );
	$assert( 'blocks' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][1] ?? '' ), 'format-bridge-from-format' );
	$assert( 'html' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][2] ?? '' ), 'format-bridge-to-format' );
	$assert( 'smoke' === ( $GLOBALS['ssi_transformer_adapter_format_bridge_calls'][0][3]['source'] ?? '' ), 'format-bridge-options-forwarded' );

	$compiled  = $adapter->compile_website_artifact( array( 'schema' => 'block-artifact-compiler/website-artifact/v1' ), array( 'include_bfb_report' => true ) );
	$artifacts = $compiled['wordpress_artifacts'] ?? array();
	$site      = $artifacts['site'] ?? array();
	$pages     = $site['pages'] ?? array();
	$documents = $artifacts['documents'] ?? array();
	$products  = $compiled['products_manifest'] ?? array();
	$assert( ! is_wp_error( $compiled ), 'native-compile-succeeds' );
	$assert( 1 === count( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'] ), 'plugin-artifact-helper-called' );
	$assert( true === ( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][0][1]['include_conversion_report'] ?? false ), 'compile-options-forwarded-as-native-report-request' );
	$assert( ! array_key_exists( 'include_bfb_report', $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][0][1] ?? array() ), 'legacy-bfb-option-is-isolated' );
	$assert( 'block-artifact-compiler/result/v1' === ( $compiled['schema'] ?? '' ), 'native-result-mapped-to-bac-envelope' );
	$assert( 'success' === ( $compiled['bfb_report']['status'] ?? '' ), 'legacy-bfb-report-shape-preserved' );
	$assert( '<!-- wp:paragraph --><p>Native report Home</p><!-- /wp:paragraph -->' === ( $compiled['bfb_report']['serialized_blocks'] ?? '' ), 'legacy-bfb-report-uses-native-report-serialized-blocks' );
	$assert( 'native_report_diagnostic' === ( $compiled['bfb_report']['diagnostics'][0]['code'] ?? '' ), 'legacy-bfb-report-uses-native-report-diagnostics' );
	$assert( 'native-conversion-report' === ( $compiled['bfb_report']['fallbacks'][0]['source'] ?? '' ), 'legacy-bfb-report-uses-native-report-fallbacks' );
	$assert( 'legacy_top_level_diagnostic' !== ( $compiled['bfb_report']['diagnostics'][0]['code'] ?? '' ), 'legacy-bfb-report-ignores-top-level-diagnostics' );
	$assert( 'legacy-top-level' !== ( $compiled['bfb_report']['fallbacks'][0]['source'] ?? '' ), 'legacy-bfb-report-ignores-top-level-fallbacks' );
	$assert( 'website/index.html' === ( $compiled['input']['entry_path'] ?? '' ), 'native-artifact-report-preserved-as-input' );
	$assert( 'blocks-engine/php-transformer/materialization-plan/v1' === ( $site['schema'] ?? '' ), 'native-materialization-plan-contract-is-used' );
	$assert( 4 === count( $pages ), 'native-keeps-compiled-site-pages-without-adapter-filtering' );
	$assert( 'website/index.html' === ( $pages[0]['source_path'] ?? '' ), 'native-entry-source-path' );
	$assert( 'home-canonical' === ( $pages[0]['slug'] ?? '' ), 'native-entry-slug-from-materialization-plan' );
	$assert( 'legacy-home' !== ( $pages[0]['slug'] ?? '' ), 'legacy-mapping-does-not-override-native-materialization-plan' );
	$assert( true === ( $pages[0]['entrypoint'] ?? false ), 'native-entrypoint' );
	$assert( 'about-canonical' === ( $pages[2]['slug'] ?? '' ), 'native-route-slug-from-materialization-plan' );
	$assert( 1 === count( $documents ), 'native-documents-preserve-transformer-documents-without-compiled-site-synthesis' );
	$assert( 'content/about.md' === ( $documents[0]['source_path'] ?? '' ), 'native-document-from-transformer-documents' );
	$assert( 'legacy/about.html' !== ( $documents[0]['source_path'] ?? '' ), 'legacy-mapping-does-not-override-native-documents' );
	$assert( 'assets/native-site.css' === ( $artifacts['files'][0]['path'] ?? '' ), 'native-materialization-plan-assets-drive-artifact-files' );
	$assert( 'assets/legacy-site.css' !== ( $artifacts['files'][0]['path'] ?? '' ), 'legacy-assets-do-not-override-native-materialization-plan-assets' );
	$assert( 'rye-loaf-canonical' === ( $products[0]['slug'] ?? '' ), 'native-product-slug-mapped-from-generic-report' );
	$assert( '12.00' === ( $products[0]['regular_price'] ?? '' ), 'native-product-price-normalized-from-generic-report' );
	$assert( array( 'Bread' ) === ( $products[0]['categories'] ?? array() ), 'native-product-categories-mapped-from-generic-report' );

	$native_report_compiled = $adapter->compile_website_artifact( array( 'schema' => 'block-artifact-compiler/website-artifact/v1' ), array( 'include_conversion_report' => true ) );
	$assert( ! is_wp_error( $native_report_compiled ), 'native-report-compile-succeeds' );
	$assert( 2 === count( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'] ), 'plugin-artifact-helper-called-for-native-report' );
	$assert( true === ( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][1][1]['include_conversion_report'] ?? false ), 'native-report-option-forwarded' );
	$assert( ! array_key_exists( 'include_bfb_report', $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][1][1] ?? array() ), 'native-report-options-do-not-forward-legacy-bfb-option' );
	$assert( ! array_key_exists( 'bfb_report', is_array( $native_report_compiled ) ? $native_report_compiled : array() ), 'native-report-request-does-not-expose-legacy-bfb-report' );

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: transformer adapter smoke passed (' . $assertions . " assertions)\n";
}
