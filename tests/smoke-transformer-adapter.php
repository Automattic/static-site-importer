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
						),
						'blocks'            => array(
							array( 'blockName' => 'core/paragraph', 'innerBlocks' => array() ),
						),
						'serialized_blocks' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->',
						'documents'         => array(
							array(
								'source_path'  => 'content/about.md',
								'slug'         => 'about',
								'title'        => 'About',
								'block_markup' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
							),
						),
						'assets'            => array(
							array( 'path' => 'assets/site.css', 'role' => 'stylesheet' ),
						),
						'diagnostics'       => array(),
						'fallbacks'         => array(),
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
	$assert( true === ( $GLOBALS['ssi_transformer_adapter_artifact_compiler_calls'][0][1]['include_bfb_report'] ?? false ), 'compile-options-forwarded' );
	$assert( 'block-artifact-compiler/result/v1' === ( $compiled['schema'] ?? '' ), 'native-result-mapped-to-bac-envelope' );
	$assert( 'blocks-engine/php-transformer/compiled-site/v1' === ( $site['schema'] ?? '' ), 'native-compiled-site-contract-is-preserved' );
	$assert( 4 === count( $pages ), 'native-keeps-compiled-site-pages-without-adapter-filtering' );
	$assert( 'website/index.html' === ( $pages[0]['source_path'] ?? '' ), 'native-entry-source-path' );
	$assert( 'index' === ( $pages[0]['slug'] ?? '' ), 'native-entry-slug' );
	$assert( true === ( $pages[0]['entrypoint'] ?? false ), 'native-entrypoint' );
	$assert( 'about' === ( $pages[2]['slug'] ?? '' ), 'native-route-slug-from-source-document-compiled-site' );
	$assert( 1 === count( $documents ), 'native-documents-preserve-transformer-documents-without-compiled-site-synthesis' );
	$assert( 'content/about.md' === ( $documents[0]['source_path'] ?? '' ), 'native-document-from-transformer-documents' );
	$assert( 'assets/site.css' === ( $artifacts['files'][0]['path'] ?? '' ), 'native-assets-report-preserved' );
	$assert( 'rye-loaf' === ( $products[0]['slug'] ?? '' ), 'native-product-slug-mapped-from-generic-report' );
	$assert( '12.00' === ( $products[0]['regular_price'] ?? '' ), 'native-product-price-normalized-from-generic-report' );
	$assert( array( 'Bread' ) === ( $products[0]['categories'] ?? array() ), 'native-product-categories-mapped-from-generic-report' );

	$adapter_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php' );
	foreach ( array( 'transformer_result_to_bac_result', 'documents_from_transformer_result', 'site_from_compiled_site_report', 'template_parts_from_compiled_site_report', 'visual_repair_from_compiled_site_report', 'document_metadata_from_compiled_site_report', 'block_markup_from_compiled_site_page', 'ArtifactCompiler\\ArtifactCompiler', 'FormatBridge\\FormatBridge' ) as $removed_helper ) {
		$assert( false !== $adapter_source && ! str_contains( $adapter_source, $removed_helper ), 'legacy-adapter-helper-removed-' . $removed_helper );
	}

	if ( $failures ) {
		fwrite( STDERR, implode( "\n", $failures ) . "\n" );
		exit( 1 );
	}

	echo 'OK: transformer adapter smoke passed (' . $assertions . " assertions)\n";
}
