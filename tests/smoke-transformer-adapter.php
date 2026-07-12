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
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {
			public static function get_instance(): self {
				return new self();
			}

			public function is_registered( string $name ): bool {
				return in_array( $name, array( 'jetpack/contact-form', 'jetpack/field-text' ), true );
			}
		}
	}

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
										'attribute_path'        => 'attrs.content',
										'source_html_preview'   => '<iframe class="booking-widget"></iframe>',
										'emitted_block_preview' => '<!-- wp:html --><iframe class="booking-widget"></iframe><!-- /wp:html -->',
									),
								),
								'asset_reference_count'   => 2,
								'presentation_gap_count'  => 1,
								'block_type_counts'       => array(
									'core/paragraph' => 2,
									'core/html'      => 1,
								),
								'source_selector_summaries' => array(
									array(
										'source_path' => 'website/index.html',
										'selector'    => 'main',
										'block_count' => 3,
									),
								),
								'page_metrics'           => array(
									array(
										'source_path' => 'website/index.html',
										'block_count' => 3,
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
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}

	$GLOBALS['ssi_transformer_adapter_format_conversion_calls'] = array();
	$GLOBALS['ssi_transformer_adapter_compile_calls'] = array();
	$GLOBALS['ssi_transformer_adapter_seeded_products'] = array();

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
	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $title ) {
			$title = strtolower( trim( (string) $title ) );
			$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
			return trim( (string) $title, '-' );
		}
	}
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $value ) {
			return (string) $value;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			unset( $name );
			return $default;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			unset( $hook, $args );
			return $value;
		}
	}
	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( $type ) {
			return 'product' === $type;
		}
	}
	if ( ! function_exists( 'taxonomy_exists' ) ) {
		function taxonomy_exists( $taxonomy ) {
			return 'product_cat' === $taxonomy;
		}
	}
	if ( ! function_exists( 'get_page_by_path' ) ) {
		function get_page_by_path( $path, $output = OBJECT, $post_type = 'post' ) {
			unset( $path, $output, $post_type );
			return null;
		}
	}
	if ( ! function_exists( 'term_exists' ) ) {
		function term_exists( $term, $taxonomy ) {
			unset( $term, $taxonomy );
			return null;
		}
	}
	if ( ! function_exists( 'wp_insert_term' ) ) {
		function wp_insert_term( $term, $taxonomy ) {
			unset( $taxonomy );
			return array( 'term_id' => abs( crc32( (string) $term ) ) );
		}
	}
	if ( ! function_exists( 'wp_set_object_terms' ) ) {
		function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
			unset( $object_id, $taxonomy );
			return $terms;
		}
	}
	if ( ! function_exists( 'wc_format_decimal' ) ) {
		function wc_format_decimal( $value ) {
			return (string) preg_replace( '/[^0-9.]/', '', (string) $value );
		}
	}
	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		class WC_Product_Simple {
			/** @var array<string,mixed> */
			public array $data = array();
			public function set_name( $value ): void { $this->data['name'] = $value; }
			public function set_slug( $value ): void { $this->data['slug'] = $value; }
			public function set_status( $value ): void { $this->data['status'] = $value; }
			public function set_description( $value ): void { $this->data['description'] = $value; }
			public function set_short_description( $value ): void { $this->data['short_description'] = $value; }
			public function set_regular_price( $value ): void { $this->data['regular_price'] = $value; }
			public function set_sale_price( $value ): void { $this->data['sale_price'] = $value; }
			public function set_stock_status( $value ): void { $this->data['stock_status'] = $value; }
			public function set_manage_stock( $value ): void { $this->data['manage_stock'] = $value; }
			public function set_stock_quantity( $value ): void { $this->data['stock_quantity'] = $value; }
			public function save(): int {
				$id = count( $GLOBALS['ssi_transformer_adapter_seeded_products'] ?? array() ) + 1000;
				$this->data['id'] = $id;
				$GLOBALS['ssi_transformer_adapter_seeded_products'][ (string) ( $this->data['slug'] ?? '' ) ] = $this->data;
				return $id;
			}
		}
	}

	if ( is_readable( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
		require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	}
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-transformer-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
	require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
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
	$assert( 2 === ( $report['blocks_engine']['conversion_report']['asset_reference_count'] ?? 0 ), 'import-report-records-native-asset-reference-count' );
	$assert( 1 === ( $report['blocks_engine']['conversion_report']['presentation_gap_count'] ?? 0 ), 'import-report-records-native-presentation-gap-count' );
	$assert( 1 === ( $report['blocks_engine']['conversion_report']['block_type_counts']['core/html'] ?? 0 ), 'import-report-records-native-block-type-counts' );
	$assert( 'main' === ( $report['blocks_engine']['conversion_report']['source_selector_summaries'][0]['selector'] ?? '' ), 'import-report-records-native-selector-summaries' );
	$assert( 3 === ( $report['blocks_engine']['conversion_report']['page_metrics'][0]['block_count'] ?? 0 ), 'import-report-records-native-page-metrics' );
	$assert( 1 === ( $report['quality']['interaction_candidate_count'] ?? 0 ), 'quality-records-interaction-candidate-count' );
	$assert( 'reported' === ( $report['import_validation_result']['quality_gates']['interaction_candidates']['status'] ?? '' ), 'validation-gate-reports-interaction-candidates' );
	$assert( 'unsupported_html_fallback' === ( $report['diagnostics'][0]['type'] ?? '' ), 'native-fallback-becomes-normalized-diagnostic' );
	$assert( 'unsupported_interactive_embed' === ( $report['diagnostics'][0]['reason_code'] ?? '' ), 'native-fallback-preserves-reason-code' );
	$assert( 'replace_unsupported_html' === ( $report['diagnostics'][0]['suggested_repair_class'] ?? '' ), 'native-fallback-gets-repair-class' );
	$assert( 'interaction_candidate' === ( $report['diagnostics'][1]['type'] ?? '' ), 'interaction-candidate-becomes-report-diagnostic' );
	$assert( 2 === ( $report['finding_packets']['count'] ?? 0 ), 'native-report-diagnostics-create-finding-packets' );

	$GLOBALS['ssi_transformer_adapter_result_override'] = array(
		'schema'         => 'blocks-engine/php-transformer/result/v1',
		'status'         => 'success',
		'source_reports' => array(
			'artifact'              => array( 'entry_path' => 'website/index.html' ),
			'materialization_plan' => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(),
			),
			'conversion_report'    => array(
				'schema'               => 'blocks-engine/php-transformer/conversion-report/v1',
				'fallback_diagnostics' => array(
					array(
						'diagnostic_code'        => 'html_form_fallback',
						'source_path'            => 'website/index.html',
						'selector'               => 'form.contact',
						'reason'                 => 'form_requires_runtime',
						'runtime_requirement'    => 'server_or_client_form_handler',
						'materialization_target' => array(
							'capability'    => 'form',
							'entity'        => 'form',
							'provider_role' => 'form_provider',
						),
						'form'                   => array( 'action' => '/contact', 'method' => 'post' ),
						'controls'               => array(
							array( 'tag' => 'input', 'type' => 'email', 'label' => 'Email', 'required' => true ),
							array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
						),
						'control_count'          => 2,
					),
					array(
						'diagnostic_code'        => 'html_product_grid_fallback',
						'kind'                   => 'html_product_grid_fallback',
						'source_path'            => 'website/shop.html',
						'selector'               => 'ul.products',
						'container_selector'     => 'ul.products',
						'reason'                 => 'commerce_products_detected',
						'materialization_target' => array(
							'capability'    => 'shop',
							'entity'        => 'product',
							'provider_role' => 'commerce_product_provider',
						),
						'products'               => array(
							array(
								'name'            => 'Adapter Tee',
								'price'           => '$29',
								'description'     => 'Provider materialized product.',
								'source_selector' => 'ul.products li:nth-child(1)',
							),
						),
						'product_count'          => 1,
					),
				),
			),
		),
		'blocks'         => array(),
		'documents'      => array(),
		'assets'         => array(),
		'diagnostics'    => array(),
		'fallbacks'      => array(),
		'provenance'     => array(),
	);
	$provider_compiled = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
	unset( $GLOBALS['ssi_transformer_adapter_result_override'] );
	$assert( ! is_wp_error( $provider_compiled ), 'provider-fallback-diagnostics-compile-succeeds' );
	$provider_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	Static_Site_Importer_Report_Diagnostics::record_blocks_engine_result( $provider_report, $provider_compiled );
	$form_seed    = Static_Site_Importer_Report_Diagnostics::materialize_form_findings( $provider_report, array( 'allow_missing_jetpack' => true ) );
	$product_seed = Static_Site_Importer_Report_Diagnostics::materialize_product_findings( $provider_report, array( 'allow_missing_woocommerce' => true ) );
	$provider_fallbacks = $provider_report['blocks_engine']['conversion_report']['fallback_diagnostics'] ?? array();
	$assert( 'form' === ( $provider_fallbacks[0]['materialization_target']['capability'] ?? '' ), 'provider-report-preserves-form-materialization-target' );
	$assert( 'shop' === ( $provider_fallbacks[1]['materialization_target']['capability'] ?? '' ), 'provider-report-preserves-shop-materialization-target' );
	$assert( 'jetpack' === ( $form_seed['provider'] ?? '' ), 'provider-e2e-form-provider-jetpack' );
	$assert( 1 === ( $form_seed['mapped_count'] ?? 0 ), 'provider-e2e-form-mapped' );
	$assert( ! empty( $form_seed['waived'] ), 'provider-e2e-form-waiver-recorded' );
	$assert( 'jetpack/contact-form' === ( $provider_report['diagnostics'][0]['block_name'] ?? '' ), 'provider-e2e-form-jetpack-block' );
	$assert( 'woocommerce' === ( $product_seed['provider'] ?? '' ), 'provider-e2e-shop-provider-woocommerce' );
	$assert( 1 === ( $product_seed['mapped_count'] ?? 0 ), 'provider-e2e-product-mapped' );
	$assert( ! empty( $product_seed['waived'] ), 'provider-e2e-product-waiver-recorded' );
	$assert( isset( $GLOBALS['ssi_transformer_adapter_seeded_products']['adapter-tee'] ), 'provider-e2e-woo-product-seeded' );
	$assert( 'woocommerce/product-collection' === ( $provider_report['diagnostics'][1]['block_name'] ?? '' ), 'provider-e2e-product-block-name' );

	$runtime_dependency_parity = array(
		'schema'                   => 'blocks-engine/runtime-dependency-parity/v1',
		'status'                   => 'reported',
		'scripts'                  => array(
			array(
				'path'         => 'assets/script.js',
				'role'         => 'script',
				'discovered'   => true,
				'materialized' => true,
				'enqueued'     => true,
			),
			array(
				'path'         => 'assets/rum.js',
				'role'         => 'script',
				'discovered'   => true,
				'materialized' => true,
				'enqueued'     => true,
				'telemetry'    => true,
				'vendor'       => 'rum',
			),
		),
		'missing_dom_targets'      => array(
			array(
				'source_path' => 'website/index.html',
				'script_path' => 'assets/script.js',
				'selector'    => '#canvas',
				'message'     => 'script.js references #canvas, but the imported page does not contain that DOM target.',
			),
		),
		'unsupported_elements'     => array(
			array(
				'source_path' => 'website/index.html',
				'script_path' => 'assets/script.js',
				'element'     => 'canvas',
				'selector'    => 'canvas',
			),
		),
		'vendor_telemetry_scripts' => array(
			array(
				'source_path' => 'website/index.html',
				'script_path' => 'assets/rum.js',
				'vendor'      => 'rum',
				'telemetry'   => true,
			),
		),
	);

	$GLOBALS['ssi_transformer_adapter_result_override'] = array(
		'schema'         => 'blocks-engine/php-transformer/result/v1',
		'status'         => 'success',
		'source_reports' => array(
			'artifact'                  => array( 'entry_path' => 'website/index.html' ),
			'materialization_plan'      => array(
				'schema' => 'blocks-engine/php-transformer/materialization-plan/v1',
				'pages'  => array(),
			),
			'runtime_dependency_parity' => $runtime_dependency_parity,
		),
	);
	$runtime_parity_compiled = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
	unset( $GLOBALS['ssi_transformer_adapter_result_override'] );
	$assert( ! is_wp_error( $runtime_parity_compiled ), 'runtime-parity-compile-succeeds' );
	$assert( 'blocks-engine/runtime-dependency-parity/v1' === ( $runtime_parity_compiled['runtime_dependency_parity']['schema'] ?? '' ), 'source-report-runtime-parity-preserved' );

	$runtime_report = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'website/index.html' );
	Static_Site_Importer_Report_Diagnostics::record_blocks_engine_result( $runtime_report, $runtime_parity_compiled );
	Static_Site_Importer_Report_Diagnostics::finalize_report( $runtime_report, array() );
	$runtime_payload = $runtime_report['blocks_engine']['runtime_dependency_parity'] ?? array();
	$runtime_gate    = $runtime_report['import_validation_result']['quality_gates']['runtime_dependency_parity'] ?? array();
	$assert( 2 === ( $runtime_payload['script_count'] ?? 0 ), 'runtime-parity-records-script-count' );
	$assert( 'assets/script.js' === ( $runtime_payload['scripts'][0]['path'] ?? '' ), 'runtime-parity-preserves-script-path' );
	$assert( true === ( $runtime_payload['scripts'][0]['materialized'] ?? false ), 'runtime-parity-preserves-script-materialized-state' );
	$assert( true === ( $runtime_payload['scripts'][0]['enqueued'] ?? false ), 'runtime-parity-preserves-script-enqueued-state' );
	$assert( 1 === ( $runtime_payload['missing_dom_target_count'] ?? 0 ), 'runtime-parity-counts-missing-dom-targets' );
	$assert( 1 === ( $runtime_payload['unsupported_element_reference_count'] ?? 0 ), 'runtime-parity-counts-unsupported-elements' );
	$assert( 1 === ( $runtime_payload['vendor_telemetry_script_count'] ?? 0 ), 'runtime-parity-counts-vendor-telemetry-scripts' );
	$assert( 2 === ( $runtime_report['quality']['runtime_dependency_parity_issue_count'] ?? 0 ), 'runtime-parity-quality-count-excludes-telemetry-notice' );
	$assert( 'reported' === ( $runtime_gate['status'] ?? '' ), 'runtime-parity-gate-reports-issues' );
	$assert( 2 === ( $runtime_gate['count'] ?? 0 ), 'runtime-parity-gate-counts-actionable-issues' );
	$assert( 2 === count( $runtime_gate['diagnostic_refs'] ?? array() ), 'runtime-parity-gate-has-diagnostic-refs' );
	$assert( 3 === count( $runtime_report['import_validation_result']['diagnostics'] ?? array() ), 'runtime-parity-validation-artifact-exposes-diagnostics' );
	$assert( '#canvas' === ( $runtime_report['import_validation_result']['diagnostics'][0]['selector'] ?? '' ), 'runtime-parity-validation-artifact-preserves-selector' );
	$assert( 'runtime_dependency_missing_dom_target' === ( $runtime_report['diagnostics'][0]['type'] ?? '' ), 'runtime-parity-missing-target-becomes-diagnostic' );
	$assert( '#canvas' === ( $runtime_report['diagnostics'][0]['selector'] ?? '' ), 'runtime-parity-diagnostic-preserves-selector' );
	$assert( 'assets/script.js' === ( $runtime_report['diagnostics'][0]['script_path'] ?? '' ), 'runtime-parity-diagnostic-preserves-script-path' );
	$assert( 'runtime_dependency_vendor_telemetry_script' === ( $runtime_report['diagnostics'][2]['type'] ?? '' ), 'runtime-parity-telemetry-becomes-low-severity-diagnostic' );
	$assert( 'notice' === ( $runtime_report['diagnostics'][2]['severity'] ?? '' ), 'runtime-parity-telemetry-is-notice' );
	$assert( 2 === ( $runtime_report['finding_packets']['count'] ?? 0 ), 'runtime-parity-actionable-diagnostics-create-finding-packets' );
	$assert( 'failed' === ( Static_Site_Importer_Report_Diagnostics::import_validation_result( $runtime_report, Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $runtime_report, array( 'fail_on_quality' => true ) ) )['status'] ?? '' ), 'runtime-parity-fail-on-quality-can-fail' );

	$runtime_parity_locations = array(
		'source_reports.conversion_report.runtime_dependency_parity' => array(
			'source_reports' => array(
				'artifact'             => array( 'entry_path' => 'website/index.html' ),
				'materialization_plan' => array( 'schema' => 'blocks-engine/php-transformer/materialization-plan/v1' ),
				'conversion_report'    => array( 'runtime_dependency_parity' => $runtime_dependency_parity ),
			),
		),
		'conversion_report.runtime_dependency_parity' => array(
			'source_reports'    => array(
				'artifact'             => array( 'entry_path' => 'website/index.html' ),
				'materialization_plan' => array( 'schema' => 'blocks-engine/php-transformer/materialization-plan/v1' ),
			),
			'conversion_report' => array( 'runtime_dependency_parity' => $runtime_dependency_parity ),
		),
		'reports.runtime_dependency_parity' => array(
			'source_reports' => array(
				'artifact'             => array( 'entry_path' => 'website/index.html' ),
				'materialization_plan' => array( 'schema' => 'blocks-engine/php-transformer/materialization-plan/v1' ),
			),
			'reports'        => array( 'runtime_dependency_parity' => $runtime_dependency_parity ),
		),
	);
	foreach ( $runtime_parity_locations as $label => $payload ) {
		$GLOBALS['ssi_transformer_adapter_result_override'] = array_merge(
			array(
				'schema' => 'blocks-engine/php-transformer/result/v1',
				'status' => 'success',
			),
			$payload
		);
		$location_compiled = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
		unset( $GLOBALS['ssi_transformer_adapter_result_override'] );
		$assert( ! is_wp_error( $location_compiled ), 'runtime-parity-location-compile-' . $label );
		$assert( 'blocks-engine/runtime-dependency-parity/v1' === ( $location_compiled['runtime_dependency_parity']['schema'] ?? '' ), 'runtime-parity-location-consumed-' . $label );
	}

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
		'schema'            => 'blocks-engine/php-transformer/result/v1',
		'status'            => 'success',
		'components'        => array( array( 'name' => 'accordion' ) ),
		'block_types'       => array( array( 'name' => 'core/details' ) ),
		'source_reports'    => array(
			'artifact'              => array(
				'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
				'entry_path' => 'website/index.html',
			),
			'compiled_site'        => array(
				'schema'     => 'blocks-engine/php-transformer/compiled-site/v1',
				'entry_path' => 'website/index.html',
			),
			'materialization_plan' => array(
				'schema'                   => 'blocks-engine/php-transformer/materialization-plan/v1',
				'entry_path'               => 'website/index.html',
				'pages'                    => array(
					array(
						'source_path'  => 'website/index.html',
						'slug'         => 'home-view',
						'title'        => 'Home View',
						'post_type'    => 'page',
						'entrypoint'   => true,
						'block_markup' => '<!-- wp:details --><details class="wp-block-details"><summary>Question</summary><p>Answer</p></details><!-- /wp:details -->',
					),
				),
				'routes'                   => array(),
				'navigation_links'         => array(),
				'menus'                    => array(),
				'template_parts'           => array(),
				'template_part_writes'     => array(),
				'assets'                   => array(
					array(
						'path'    => 'assets/view.css',
						'role'    => 'stylesheet',
						'kind'    => 'css',
						'content' => '.view{display:block}',
					),
				),
				'theme'                    => array(),
				'asset_rewrite_candidates' => array(),
				'rewrite_candidates'       => array(),
				'totals'                   => array(),
			),
			'conversion_report'    => array(
				'schema'                 => 'blocks-engine/php-transformer/conversion-report/v1',
				'source_format'          => 'artifact',
				'source_summary'         => array(),
				'selector_summary'       => array(),
				'fallback_diagnostics'   => array(),
				'asset_refs'             => array(),
				'navigation_candidates'  => array(),
				'interaction_candidates' => array(
					array(
						'source_path' => 'website/index.html',
						'selector'    => '.accordion button',
						'kind'        => 'accordion',
					),
				),
				'presentation_gaps'      => array(),
				'metrics'                => array(),
			),
		),
		'blocks'            => array( array( 'blockName' => 'core/details', 'innerBlocks' => array() ) ),
		'serialized_blocks' => '<!-- wp:details --><details class="wp-block-details"><summary>Question</summary><p>Answer</p></details><!-- /wp:details -->',
		'documents'         => array( array( 'source_path' => 'website/index.html', 'block_markup' => '<!-- wp:details /-->' ) ),
		'assets'            => array( array( 'path' => 'assets/top-level-view.css', 'role' => 'stylesheet', 'kind' => 'css', 'content' => '.top{}' ) ),
		'diagnostics'       => array( array( 'code' => 'view_diagnostic' ) ),
		'fallbacks'         => array(),
		'provenance'        => array( array( 'source' => 'canonical-view' ) ),
		'coverage'          => array(),
		'context'           => array(),
		'metrics'           => array(),
	);
	$view_compiled = $adapter->compile_website_artifact( array( 'schema' => 'blocks-engine/php-transformer/site-artifact/v1' ) );
	unset( $GLOBALS['ssi_transformer_adapter_result_override'] );
	$assert( ! is_wp_error( $view_compiled ), 'materialization-view-compile-succeeds', is_wp_error( $view_compiled ) ? $view_compiled->get_error_message() : '' );
	if ( class_exists( 'Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationView' ) ) {
		$assert( 'website/index.html' === ( $view_compiled['artifact_summary']['entry_path'] ?? '' ), 'materialization-view-exposes-artifact-summary' );
		$assert( 'blocks-engine/php-transformer/compiled-site/v1' === ( $view_compiled['artifacts']['compiled_site']['schema'] ?? '' ), 'materialization-view-exposes-compiled-site' );
	} else {
		$assert( ! isset( $view_compiled['artifact_summary'] ), 'native-contract-used-when-materialization-view-unavailable' );
	}
	$assert( 'website/index.html' === ( $view_compiled['input']['entry_path'] ?? '' ), 'materialization-view-artifact-summary-drives-input' );
	$assert( 'home-view' === ( $view_compiled['artifacts']['site']['pages'][0]['slug'] ?? '' ), 'materialization-view-materialization-plan-drives-site' );
	$assert( 'assets/view.css' === ( $view_compiled['artifacts']['files'][0]['path'] ?? '' ), 'materialization-view-materialization-plan-assets-drive-files' );
	$assert( 'view_diagnostic' === ( $view_compiled['diagnostics'][0]['code'] ?? '' ), 'materialization-view-exposes-diagnostics' );
	$assert( '.accordion button' === ( $view_compiled['conversion_report']['interaction_candidates'][0]['selector'] ?? '' ), 'materialization-view-exposes-conversion-report' );

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
