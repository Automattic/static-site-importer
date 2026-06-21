<?php
/**
 * Import report and diagnostics helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Transformer_Adapter' ) ) {
	require_once __DIR__ . '/class-static-site-importer-transformer-adapter.php';
}

/**
 * Builds SSI import reports and normalizes diagnostics for repair loops.
 */
class Static_Site_Importer_Report_Diagnostics {

	/**
	 * Initialize a conversion report.
	 *
	 * @param string              $html_path       Imported entry file.
	 * @param array<string,mixed> $source_metadata Source metadata.
	 * @return array<string, mixed>
	 */
	public static function new_conversion_report( string $html_path, array $source_metadata = array() ): array {
		return array(
			'version'                 => 1,
			'entry_file'              => $html_path,
			'source'                  => array_merge(
				array(
					'type' => empty( $source_metadata ) ? 'file' : (string) ( $source_metadata['source_type'] ?? 'file' ),
				),
				$source_metadata
			),
			'quality'                 => array(
				'pass'                               => true,
				'fallback_count'                     => 0,
				'content_loss_count'                 => 0,
				'empty_conversion_count'             => 0,
				'core_html_block_count'              => 0,
				'freeform_block_count'               => 0,
				'invalid_block_count'                => 0,
				'invalid_block_document_count'       => 0,
				'unsafe_svg_count'                   => 0,
				'svg_materialization_failure_count'  => 0,
				'svg_sprite_reference_failure_count' => 0,
				'commerce_dependency_failures'       => 0,
				'failure_reasons'                    => array(),
			),
			'source_documents'        => array(
				'total_count'                => 0,
				'counts_by_format'           => array(
					'html'     => 0,
					'markdown' => 0,
					'mdx'      => 0,
				),
				'skipped_mdx_count'          => 0,
				'unresolved_links'           => array(),
				'unresolved_link_count'      => 0,
				'markdown_parse_error_count' => 0,
			),
			'conversion_fragments'    => array(),
			'source_region_selection' => array(
				'entry_file'                    => '',
				'page_body'                     => null,
				'extracted_header'              => null,
				'extracted_footer'              => null,
				'unassigned_regions'            => array(),
				'intentionally_ignored_regions' => array(),
				'counts'                        => array(
					'source_landmarks'              => array(
						'main'   => 0,
						'header' => 0,
						'nav'    => 0,
						'footer' => 0,
					),
					'unassigned_regions'            => 0,
					'intentionally_ignored_regions' => 0,
				),
				'notes'                         => array(
					'Reports which source region became the page body, the extracted header/footer parts, and any meaningful direct body children that were not assigned to a generated region. Reporting only — does not change conversion behavior.',
				),
			),
			'commerce_context'        => array(
				'supplied'       => false,
				'source'         => 'none',
				'product_count'  => 0,
				'selector_hints' => array(),
				'diagnostics'    => array(),
			),
			'assets'                  => array(
				'policy'       => 'theme',
				'local_policy' => 'copy_to_theme',
				'svg_icons'    => array(),
				'svg_sprites'  => array(),
				'local'        => array(),
			),
			'asset_map'               => array(
				'supplied'         => false,
				'entry_count'      => 0,
				'resolved_count'   => 0,
				'unresolved_count' => 0,
				'resolved'         => array(),
				'unresolved'       => array(),
			),
			'blocks_engine'           => array(
				'available'      => true,
				'fragment_count' => 0,
				'fragments'      => array(),
			),
			'generated_theme'         => array(
				'document_metadata' => array(),
				'template_parts'    => array(),
				'block_documents'   => array(),
				'freeform_blocks'   => array(),
			),
			'visual_fidelity'         => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source and generated DOM probes, render URLs, and theme artifacts for visual comparison; screenshot capture and computed-style/layout thresholds belong to the benchmark harness.',
				),
			),
			'semantic_fidelity'       => array(
				'status'             => 'requires_external_render_check',
				'gate_owner'         => 'benchmark_harness',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records source/generated semantic comparison targets; browser DOM extraction and semantic fingerprint comparison belong to the benchmark harness.',
				),
			),
			'diagnostics'             => array(),
			'notes'                   => array(
				'Blocks Engine owns the website-artifact to WordPress-artifact envelope and transform diagnostics; Static Site Importer materializes the result into WordPress.',
				'Static Site Importer still owns WordPress writes, dependency materialization, and WooCommerce product seeding, which keeps this report helper from moving wholesale into Blocks Engine.',
				'Generated-theme block validation uses WordPress server-side block parsing and serialization checks; editor-runtime validation remains the exact Gutenberg authority.',
				'Visual fidelity requires browser rendering; use visual_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
				'Semantic fidelity requires browser DOM extraction; use semantic_fidelity.comparison_targets to compare source static HTML against the generated WordPress URL.',
			),
		);
	}

	/**
	 * Record the bundle-level Blocks Engine result used by import_website_artifact().
	 *
	 * @param array<string,mixed> $report   Import report.
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	public static function record_blocks_engine_result( array &$report, array $compiled ): void {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$site      = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		$report['blocks_engine']['available']        = true;
		$report['blocks_engine']['website_artifact'] = array(
			'summary'     => ( new Static_Site_Importer_Transformer_Adapter() )->summarize_result( $compiled ),
			'provenance'  => isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : array(),
			'input'       => isset( $compiled['input'] ) && is_array( $compiled['input'] ) ? $compiled['input'] : array(),
			'diagnostics' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array(),
		);

		if ( in_array( (string) ( $site['schema'] ?? '' ), array( 'block-artifact-compiler/compiled-site/v1', 'blocks-engine/php-transformer/compiled-site/v1' ), true ) ) {
			$report['blocks_engine']['compiled_site'] = self::compiled_site_report_payload( $site );
		}
		if ( 'blocks-engine/php-transformer/materialization-plan/v1' === (string) ( $site['schema'] ?? '' ) ) {
			$report['block_artifact_compiler']['materialization_plan'] = self::compiled_site_report_payload( $site );
		}
	}

	/**
	 * Record source and contract notes for direct website-artifact materialization.
	 *
	 * @param array<string,mixed> $report   Import report.
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	public static function record_direct_website_artifact_source_summary( array &$report, array $compiled ): void {
		$artifacts = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$files     = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$source    = (string) ( $compiled['provenance']['source'] ?? ( $compiled['input']['entry_path'] ?? 'website_artifact' ) );

		$source_documents = array_merge(
			$report['source_documents'],
			array(
				'total_count'      => 1,
				'counts_by_format' => array(
					'html'     => 1,
					'markdown' => 0,
					'mdx'      => 0,
				),
			)
		);

		$report['source_documents']                            = $source_documents;
		$report['source_documents']['direct_website_artifact'] = array(
			'source'     => '' !== $source ? $source : 'website_artifact',
			'file_count' => count( $files ),
		);

		$report['diagnostics'][] = array(
			'type'        => 'website_artifact_materialization_contract_note',
			'source'      => '' !== $source ? $source : 'website_artifact',
			'message'     => 'Direct materialization consumed block_markup, documents, files, and compiled-site artifacts. Static Site Importer owns WordPress writes and product seeding while Blocks Engine owns materializer-neutral site/theme compilation.',
			'contract'    => 'block-artifact-compiler/result/v1',
			'constraints' => 'report_only',
		);
	}

	/**
	 * Build a normalized fallback/core-html diagnostic entry.
	 *
	 * @param string              $type         Diagnostic type.
	 * @param string              $source       Source fragment or generated document path.
	 * @param string              $element_html Source HTML fragment.
	 * @param array<string,mixed> $context      Diagnostic context.
	 * @param array<string,mixed> $block        Generated or parsed block.
	 * @return array<string,mixed>
	 */
	public static function fallback_diagnostic_entry( string $type, string $source, string $element_html, array $context, array $block ): array {
		$selector = isset( $context['selector'] ) && is_scalar( $context['selector'] ) ? trim( (string) $context['selector'] ) : '';
		if ( '' === $selector ) {
			$selector = self::diagnostic_selector_from_html( $element_html );
		}

		$emitted = '';
		if ( function_exists( 'serialize_blocks' ) ) {
			// @phpstan-ignore-next-line argument.type -- Parsed block shape comes from WordPress parse_blocks() or transformer diagnostics.
			$emitted = serialize_blocks( array( $block ) );
		}
		if ( '' === trim( $emitted ) || preg_match( '/^<!--\s+wp:[^>]+\/-->$/', trim( $emitted ) ) ) {
			$emitted = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : $element_html;
		}

		$entry = array(
			'type'                  => $type,
			'source'                => $source,
			'selector'              => '' !== $selector ? $selector : null,
			'excerpt'               => self::diagnostic_excerpt( wp_strip_all_tags( $element_html ) ),
			'source_html_preview'   => self::diagnostic_excerpt( $element_html ),
			'emitted_block_preview' => self::diagnostic_excerpt( $emitted ),
			'reason'                => isset( $context['reason'] ) ? (string) $context['reason'] : 'unknown',
			'tag_name'              => isset( $context['tag_name'] ) ? (string) $context['tag_name'] : self::diagnostic_tag_name_from_html( $element_html ),
			'block_name'            => isset( $block['blockName'] ) ? (string) $block['blockName'] : null,
			'engine'                => 'blocks-engine/php-transformer',
			'stage'                 => isset( $context['stage'] ) ? (string) $context['stage'] : 'html_to_blocks',
			'html_length'           => strlen( $element_html ),
			'html_excerpt'          => self::diagnostic_excerpt( $element_html ),
		);

		if ( isset( $context['occurrence'] ) ) {
			$entry['occurrence'] = (int) $context['occurrence'];
		}

		if ( isset( $context['path'] ) && is_scalar( $context['path'] ) ) {
			$entry['block_path'] = (string) $context['path'];
		}

		return $entry;
	}

	/**
	 * Finalize quality summary, compact summary, and artifact diagnostics.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @param array<string,mixed> $args   Import args.
	 * @return array<string, mixed>
	 */
	public static function finalize_report( array &$report, array $args ): array {
		$quality                            = self::finalize_quality_report( $report, $args );
		$report['compact_summary']          = self::import_report_summary( $report, $quality );
		$report['finding_packets']          = self::finding_packets( $report );
		$report['import_validation_result'] = self::import_validation_result( $report, $quality );

		return $quality;
	}

	/**
	 * Build the first-class import validation artifact for automation consumers.
	 *
	 * @param array<string,mixed> $report  Full import report.
	 * @param array<string,mixed> $quality Finalized quality gate state.
	 * @return array<string,mixed>
	 */
	public static function import_validation_result( array $report, array $quality ): array {
		$summary          = self::import_report_summary( $report, $quality );
		$diagnostics      = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$source_documents = isset( $report['source_documents'] ) && is_array( $report['source_documents'] ) ? $report['source_documents'] : array();

		return array(
			'schema'               => 'blocks-engine/import-validation-result/v1',
			'artifact_type'        => 'ImportValidationResult',
			'version'              => 1,
			'status'               => ! empty( $quality['fail_import'] ) ? 'failed' : ( ! empty( $quality['pass'] ) ? 'passed' : 'reported' ),
			'quality_pass'         => ! empty( $quality['pass'] ),
			'fail_import'          => ! empty( $quality['fail_import'] ),
			'failure_reasons'      => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'counts'               => array(
				'source_documents'              => (int) ( $source_documents['total_count'] ?? 0 ),
				'diagnostics'                   => count( $diagnostics ),
				'fallback_blocks'               => (int) ( $quality['fallback_count'] ?? 0 ),
				'content_loss'                  => (int) ( $quality['content_loss_count'] ?? 0 ),
				'empty_conversions'             => (int) ( $quality['empty_conversion_count'] ?? 0 ),
				'core_html_blocks'              => (int) ( $quality['core_html_block_count'] ?? 0 ),
				'freeform_blocks'               => (int) ( $quality['freeform_block_count'] ?? 0 ),
				'invalid_blocks'                => (int) ( $quality['invalid_block_count'] ?? 0 ),
				'invalid_block_documents'       => (int) ( $quality['invalid_block_document_count'] ?? 0 ),
				'unsafe_svgs'                   => (int) ( $quality['unsafe_svg_count'] ?? 0 ),
				'svg_materialization_failures'  => (int) ( $quality['svg_materialization_failure_count'] ?? 0 ),
				'svg_sprite_reference_failures' => (int) ( $quality['svg_sprite_reference_failure_count'] ?? 0 ),
				'commerce_dependency_failures'  => (int) ( $quality['commerce_dependency_failures'] ?? 0 ),
			),
			'quality_gates'        => array(
				'fallback_blocks'              => self::validation_gate( 'fallback_blocks', (int) ( $quality['fallback_count'] ?? 0 ), $quality ),
				'conversion_failures'          => self::validation_gate( 'conversion_failures', (int) ( $quality['content_loss_count'] ?? 0 ) + (int) ( $quality['empty_conversion_count'] ?? 0 ) + (int) ( $quality['invalid_block_count'] ?? 0 ), $quality ),
				'generated_fallback_blocks'    => self::validation_gate( 'generated_fallback_blocks', (int) ( $quality['core_html_block_count'] ?? 0 ) + (int) ( $quality['freeform_block_count'] ?? 0 ), $quality ),
				'asset_materialization'        => self::validation_gate( 'asset_materialization', (int) ( $quality['svg_materialization_failure_count'] ?? 0 ) + (int) ( $quality['svg_sprite_reference_failure_count'] ?? 0 ), $quality ),
				'commerce_dependencies'        => self::validation_gate( 'commerce_dependencies', (int) ( $quality['commerce_dependency_failures'] ?? 0 ), $quality ),
				'visual_fidelity'             => array(
					'status' => (string) ( $report['visual_fidelity']['status'] ?? 'requires_external_render_check' ),
					'owner'  => (string) ( $report['visual_fidelity']['gate_owner'] ?? 'benchmark_harness' ),
				),
				'semantic_fidelity'           => array(
					'status' => (string) ( $report['semantic_fidelity']['status'] ?? 'requires_external_render_check' ),
					'owner'  => (string) ( $report['semantic_fidelity']['gate_owner'] ?? 'benchmark_harness' ),
				),
			),
			'diagnostic_summary'  => $summary['diagnostic_summary'] ?? array(),
			'diagnostic_refs'     => isset( $quality['diagnostic_refs'] ) && is_array( $quality['diagnostic_refs'] ) ? $quality['diagnostic_refs'] : array(),
			'artifacts'           => self::validation_artifact_refs(),
			'provenance'          => self::validation_provenance( $report ),
			'reproduction_context' => self::validation_reproduction_context( $report ),
		);
	}

	/**
	 * Build the finding packet artifact set for repair-loop routing.
	 *
	 * @param array<string,mixed> $report Full import report.
	 * @return array<string,mixed>
	 */
	public static function finding_packets( array $report ): array {
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$packets     = array();

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! self::diagnostic_is_finding_packet_candidate( $diagnostic ) ) {
				continue;
			}

			$packets[] = self::finding_packet_from_diagnostic( $diagnostic, $report );
		}

		return array(
			'schema'        => 'blocks-engine/finding-packets/v1',
			'artifact_type' => 'FindingPacketSet',
			'version'       => 1,
			'status'        => empty( $packets ) ? 'clean' : 'reported',
			'count'         => count( $packets ),
			'packets'       => $packets,
		);
	}

	/**
	 * Finalize quality summary and gate status.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @param array<string,mixed> $args   Import args.
	 * @return array<string, mixed>
	 */
	public static function finalize_quality_report( array &$report, array $args ): array {
		self::normalize_import_diagnostics( $report );

		$quality = $report['quality'];
		$reasons = array();
		if ( $quality['fallback_count'] > 0 ) {
			$reasons[] = 'unsupported_html_fallback';
		}
		if ( $quality['content_loss_count'] > 0 ) {
			$reasons[] = 'content_loss_abort';
		}
		if ( $quality['empty_conversion_count'] > 0 ) {
			$reasons[] = 'empty_conversion';
		}
		if ( $quality['core_html_block_count'] > 0 ) {
			$reasons[] = 'core_html_block';
		}
		if ( $quality['freeform_block_count'] > 0 ) {
			$reasons[] = 'freeform_block';
		}
		if ( $quality['invalid_block_count'] > 0 ) {
			$reasons[] = 'invalid_block';
		}
		if ( $quality['unsafe_svg_count'] > 0 ) {
			$reasons[] = 'unsafe_inline_svg';
		}
		if ( $quality['svg_materialization_failure_count'] > 0 ) {
			$reasons[] = 'svg_materialization_failure';
		}
		if ( $quality['svg_sprite_reference_failure_count'] > 0 ) {
			$reasons[] = 'svg_sprite_reference_failure';
		}
		if ( ( $quality['commerce_dependency_failures'] ?? 0 ) > 0 ) {
			$reasons[] = 'woocommerce_missing';
		}

		$quality['pass']            = empty( $reasons );
		$quality['failure_reasons'] = $reasons;
		$quality['fail_import']     = false;
		if ( ! empty( $args['fail_on_quality'] ) && ! $quality['pass'] ) {
			$quality['fail_import'] = true;
		}
		if ( array_key_exists( 'max_fallbacks', $args ) && null !== $args['max_fallbacks'] && $quality['fallback_count'] > (int) $args['max_fallbacks'] ) {
			$quality['fail_import'] = true;
		}
		if ( in_array( 'woocommerce_missing', $reasons, true ) ) {
			$quality['fail_import'] = true;
		}

		$quality['diagnostic_refs'] = self::quality_diagnostic_refs( $report['diagnostics'] ?? array() );
		$report['quality']         = $quality;
		self::normalize_source_document_diagnostic_refs( $report );
		$report['artifact_diagnostics'] = Static_Site_Importer_WP_Codebox_Artifact_Diagnostics_Normalizer::build_for_import_report( $report );

		return $quality;
	}

	/**
	 * Build the compact report summary consumed by validation harnesses.
	 *
	 * @param array<string, mixed> $report  Full conversion report.
	 * @param array<string, mixed> $quality Finalized quality summary.
	 * @return array<string, mixed>
	 */
	public static function import_report_summary( array $report, array $quality ): array {
		$diagnostics      = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$source_documents = isset( $report['source_documents'] ) && is_array( $report['source_documents'] ) ? $report['source_documents'] : array();
		$commerce         = isset( $report['commerce'] ) && is_array( $report['commerce'] ) ? $report['commerce'] : array();
		$commerce_context = isset( $report['commerce_context'] ) && is_array( $report['commerce_context'] ) ? $report['commerce_context'] : array();
		$plugin_materialization = isset( $report['plugin_materialization'] ) && is_array( $report['plugin_materialization'] ) ? $report['plugin_materialization'] : array();
		$product_seeding        = isset( $report['product_seeding'] ) && is_array( $report['product_seeding'] ) ? $report['product_seeding'] : array();

		return array(
			'schema'                       => 'static-site-importer/import-metrics/v1',
			'version'                      => 1,
			'report_version'               => (int) ( $report['version'] ?? 0 ),
			'status'                       => ! empty( $quality['fail_import'] ) ? 'failed' : 'completed',
			'theme_slug'                   => isset( $report['theme_slug'] ) ? (string) $report['theme_slug'] : '',
			'entry_file'                   => isset( $report['entry_file'] ) ? (string) $report['entry_file'] : '',
			'compiler'                     => self::compact_import_report_compiler_summary( $report ),
			'quality_pass'                 => ! empty( $quality['pass'] ),
			'fail_import'                  => ! empty( $quality['fail_import'] ),
			'failure_reasons'              => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'fallback_count'               => (int) ( $quality['fallback_count'] ?? 0 ),
			'content_loss_count'           => (int) ( $quality['content_loss_count'] ?? 0 ),
			'empty_conversion_count'       => (int) ( $quality['empty_conversion_count'] ?? 0 ),
			'core_html_block_count'        => (int) ( $quality['core_html_block_count'] ?? 0 ),
			'freeform_block_count'         => (int) ( $quality['freeform_block_count'] ?? 0 ),
			'invalid_block_count'          => (int) ( $quality['invalid_block_count'] ?? 0 ),
			'invalid_block_document_count' => (int) ( $quality['invalid_block_document_count'] ?? 0 ),
			'source_document_count'        => (int) ( $source_documents['total_count'] ?? 0 ),
			'unresolved_link_count'        => (int) ( $source_documents['unresolved_link_count'] ?? 0 ),
			'commerce'                     => $commerce,
			'commerce_context'             => $commerce_context,
			'plugin_materialization'       => $plugin_materialization,
			'product_seeding'              => $product_seeding,
			'diagnostic_count'             => count( $diagnostics ),
			'diagnostic_summary'           => self::compact_import_report_diagnostic_summary( $diagnostics ),
			'warning_summaries'            => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'warning' ),
			'error_summaries'              => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'error' ),
			'diagnostics'                  => self::compact_import_report_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Build a machine quality gate row.
	 *
	 * @param string              $name    Gate name.
	 * @param int                 $count   Finding count.
	 * @param array<string,mixed> $quality Finalized quality report.
	 * @return array<string,mixed>
	 */
	private static function validation_gate( string $name, int $count, array $quality ): array {
		$ref_keys = array(
			'fallback_blocks'           => 'fallback_count',
			'conversion_failures'       => 'content_loss_count',
			'generated_fallback_blocks' => 'core_html_block_count',
			'asset_materialization'     => 'svg_materialization_failure_count',
			'commerce_dependencies'     => 'commerce_dependency_failures',
		);
		$ref_key  = $ref_keys[ $name ] ?? $name;

		return array(
			'name'            => $name,
			'status'          => 0 === $count ? 'passed' : 'reported',
			'count'           => $count,
			'diagnostic_refs' => isset( $quality['diagnostic_refs'][ $ref_key ] ) && is_array( $quality['diagnostic_refs'][ $ref_key ] ) ? $quality['diagnostic_refs'][ $ref_key ] : array(),
		);
	}

	/**
	 * Stable artifact paths emitted by SSI import.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function validation_artifact_refs(): array {
		return array(
			'import_report'            => array(
				'path' => 'import-report.json',
				'kind' => 'blocks-engine/import-report',
			),
			'import_validation_result' => array(
				'path' => 'import-validation-result.json',
				'kind' => 'blocks-engine/import-validation-result',
			),
			'finding_packets'          => array(
				'path' => 'finding-packets.json',
				'kind' => 'blocks-engine/finding-packets',
			),
		);
	}

	/**
	 * Build provenance for machine artifacts.
	 *
	 * @param array<string,mixed> $report Full import report.
	 * @return array<string,mixed>
	 */
	private static function validation_provenance( array $report ): array {
		$source   = isset( $report['source'] ) && is_array( $report['source'] ) ? $report['source'] : array();
		$compiler = isset( $report['blocks_engine']['website_artifact'] ) && is_array( $report['blocks_engine']['website_artifact'] ) ? $report['blocks_engine']['website_artifact'] : array();

		return array(
			'producer'            => 'static-site-importer',
			'producer_version'    => defined( 'STATIC_SITE_IMPORTER_VERSION' ) ? STATIC_SITE_IMPORTER_VERSION : 'unknown',
			'source'              => $source,
			'compiler_summary'    => isset( $compiler['summary'] ) && is_array( $compiler['summary'] ) ? $compiler['summary'] : array(),
			'compiler_input'      => isset( $compiler['input'] ) && is_array( $compiler['input'] ) ? $compiler['input'] : array(),
			'compiler_provenance' => isset( $compiler['provenance'] ) && is_array( $compiler['provenance'] ) ? $compiler['provenance'] : array(),
		);
	}

	/**
	 * Build import-level reproduction context for machine artifacts.
	 *
	 * @param array<string,mixed> $report Full import report.
	 * @return array<string,mixed>
	 */
	private static function validation_reproduction_context( array $report ): array {
		return array(
			'entry_file'       => isset( $report['entry_file'] ) ? (string) $report['entry_file'] : '',
			'theme_slug'       => isset( $report['theme_slug'] ) ? (string) $report['theme_slug'] : '',
			'source_documents' => isset( $report['source_documents'] ) && is_array( $report['source_documents'] ) ? $report['source_documents'] : array(),
			'artifacts'        => self::validation_artifact_refs(),
		);
	}

	/**
	 * Determine whether a diagnostic should route as a repair finding.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return bool
	 */
	private static function diagnostic_is_finding_packet_candidate( array $diagnostic ): bool {
		$type = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : '';

		return in_array(
			$type,
			array(
				'unsupported_html_fallback',
				'core_html_block',
				'freeform_block',
				'invalid_block_document',
				'content_loss_abort',
				'empty_conversion',
				'local_asset_not_materialized',
				'svg_materialization_failure',
				'svg_sprite_reference_failure',
				'commerce_dependency_failure',
				'commerce_product_inference_unmatched',
			),
			true
		);
	}

	/**
	 * Convert one normalized diagnostic into a FindingPacket.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @param array<string,mixed> $report     Full import report.
	 * @return array<string,mixed>
	 */
	private static function finding_packet_from_diagnostic( array $diagnostic, array $report ): array {
		$id       = isset( $diagnostic['id'] ) && is_scalar( $diagnostic['id'] ) ? (string) $diagnostic['id'] : 'finding';
		$type     = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'import_diagnostic';
		$severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : self::diagnostic_severity( $type );

		return array(
			'schema'               => 'blocks-engine/finding-packet/v1',
			'artifact_type'        => 'FindingPacket',
			'version'              => 1,
			'id'                   => 'finding-' . preg_replace( '/^diag-/', '', $id ),
			'diagnostic_id'        => $id,
			'type'                 => $type,
			'severity'             => $severity,
			'category'             => isset( $diagnostic['category'] ) && is_scalar( $diagnostic['category'] ) ? (string) $diagnostic['category'] : self::diagnostic_category( $type ),
			'owner'                => self::finding_owner( $diagnostic ),
			'routing'              => array(
				'component'              => self::finding_owner( $diagnostic ),
				'stage'                  => isset( $diagnostic['stage'] ) && is_scalar( $diagnostic['stage'] ) ? (string) $diagnostic['stage'] : 'import',
				'suggested_repair_class' => isset( $diagnostic['suggested_repair_class'] ) && is_scalar( $diagnostic['suggested_repair_class'] ) ? (string) $diagnostic['suggested_repair_class'] : self::diagnostic_repair_class( $type ),
			),
			'provenance'           => self::validation_provenance( $report ),
			'reproduction_context' => array_merge(
				self::validation_reproduction_context( $report ),
				array(
					'source_path' => isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : '',
					'selector'    => isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '',
					'block_path'  => isset( $diagnostic['block_path'] ) && is_scalar( $diagnostic['block_path'] ) ? (string) $diagnostic['block_path'] : '',
				)
			),
			'source'              => array(
				'path'         => isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : '',
				'selector'     => isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '',
				'snippet'      => isset( $diagnostic['source_html_preview'] ) && is_scalar( $diagnostic['source_html_preview'] ) ? (string) $diagnostic['source_html_preview'] : ( isset( $diagnostic['html_excerpt'] ) && is_scalar( $diagnostic['html_excerpt'] ) ? (string) $diagnostic['html_excerpt'] : '' ),
			),
			'observed'            => array(
				'output'       => isset( $diagnostic['emitted_block_preview'] ) && is_scalar( $diagnostic['emitted_block_preview'] ) ? (string) $diagnostic['emitted_block_preview'] : '',
				'block_name'   => isset( $diagnostic['block_name'] ) && is_scalar( $diagnostic['block_name'] ) ? (string) $diagnostic['block_name'] : '',
				'reason_code'  => isset( $diagnostic['reason_code'] ) && is_scalar( $diagnostic['reason_code'] ) ? (string) $diagnostic['reason_code'] : self::diagnostic_reason_code( $type, $diagnostic ),
			),
			'expected'            => array(
				'outcome' => self::finding_expected_outcome( $diagnostic ),
			),
			'refs'                => array_values( self::validation_artifact_refs() ),
		);
	}

	/**
	 * Route a finding to the most likely upstream owner.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function finding_owner( array $diagnostic ): string {
		$engine = isset( $diagnostic['engine'] ) && is_scalar( $diagnostic['engine'] ) ? (string) $diagnostic['engine'] : '';
		if ( '' === $engine ) {
			$engine = isset( $diagnostic['converter'] ) && is_scalar( $diagnostic['converter'] ) ? (string) $diagnostic['converter'] : '';
		}
		if ( '' !== $engine ) {
			return $engine;
		}

		$type = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : '';
		if ( str_contains( $type, 'svg' ) || str_contains( $type, 'asset' ) ) {
			return 'static-site-importer';
		}

		return 'blocks-engine/php-transformer';
	}

	/**
	 * Explain the target repair state for a finding.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function finding_expected_outcome( array $diagnostic ): string {
		$type = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : '';
		if ( in_array( $type, array( 'unsupported_html_fallback', 'core_html_block', 'freeform_block' ), true ) ) {
			return 'Generate first-class WordPress block markup without fallback core/html or core/freeform output.';
		}
		if ( in_array( $type, array( 'content_loss_abort', 'empty_conversion', 'invalid_block_document' ), true ) ) {
			return 'Convert the source content into valid non-empty WordPress block markup without losing source content.';
		}

		return 'Import should complete without this diagnostic being reported.';
	}

	/**
	 * Build a compact diagnostic excerpt.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	public static function diagnostic_excerpt( string $html ): string {
		$excerpt = preg_replace( '/\s+/', ' ', trim( $html ) );
		$excerpt = is_string( $excerpt ) ? $excerpt : trim( $html );
		return substr( $excerpt, 0, 300 );
	}

	/**
	 * Preserve the Blocks Engine compiled-site contract in the import report.
	 *
	 * @param array<string,mixed> $site Blocks Engine compiled-site artifact.
	 * @return array<string,mixed>
	 */
	private static function compiled_site_report_payload( array $site ): array {
		$pages          = isset( $site['pages'] ) && is_array( $site['pages'] ) ? $site['pages'] : array();
		$shared_regions = isset( $site['shared_regions'] ) && is_array( $site['shared_regions'] ) ? $site['shared_regions'] : array();
		$theme_assets   = isset( $site['theme_assets'] ) && is_array( $site['theme_assets'] ) ? $site['theme_assets'] : array();

		return array(
			'schema'              => (string) ( $site['schema'] ?? '' ),
			'page_count'          => count( $pages ),
			'pages'               => self::compiled_site_pages_report_payload( $pages ),
			'shared_region_count' => count( $shared_regions ),
			'shared_regions'      => $shared_regions,
			'theme_assets'        => array(
				'styles'  => isset( $theme_assets['styles'] ) && is_array( $theme_assets['styles'] ) ? $theme_assets['styles'] : array(),
				'scripts' => isset( $theme_assets['scripts'] ) && is_array( $theme_assets['scripts'] ) ? $theme_assets['scripts'] : array(),
			),
			'provenance'          => isset( $site['provenance'] ) && is_array( $site['provenance'] ) ? $site['provenance'] : array(),
		);
	}

	/**
	 * Normalize compiled-site page rows for reports.
	 *
	 * @param array<int,mixed> $pages Compiled-site page rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compiled_site_pages_report_payload( array $pages ): array {
		$normalized = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$normalized[] = array(
				'source_path' => isset( $page['source_path'] ) && is_scalar( $page['source_path'] ) ? (string) $page['source_path'] : '',
				'route_key'   => isset( $page['route_key'] ) && is_scalar( $page['route_key'] ) ? (string) $page['route_key'] : '',
				'slug'        => isset( $page['slug'] ) && is_scalar( $page['slug'] ) ? (string) $page['slug'] : '',
				'post_type'   => isset( $page['post_type'] ) && is_scalar( $page['post_type'] ) ? (string) $page['post_type'] : '',
				'title'       => isset( $page['title'] ) && is_scalar( $page['title'] ) ? (string) $page['title'] : '',
				'entrypoint'  => ! empty( $page['entrypoint'] ),
				'artifact'    => isset( $page['artifact'] ) && is_scalar( $page['artifact'] ) ? (string) $page['artifact'] : '',
			);
		}

		return $normalized;
	}

	/**
	 * Normalize import diagnostics into a stable, machine-actionable shape.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return void
	 */
	private static function normalize_import_diagnostics( array &$report ): void {
		if ( empty( $report['diagnostics'] ) || ! is_array( $report['diagnostics'] ) ) {
			return;
		}

		$normalized = array();
		foreach ( array_values( $report['diagnostics'] ) as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$type        = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'import_diagnostic';
			$source      = isset( $diagnostic['source'] ) && is_scalar( $diagnostic['source'] ) ? (string) $diagnostic['source'] : '';
			$source_path = isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : self::diagnostic_source_path( $source );
			$reason_code = self::diagnostic_reason_code( $type, $diagnostic );

			$machine = array(
				'id'                     => self::diagnostic_id( $index, $type, $source_path, $reason_code ),
				'severity'               => self::diagnostic_severity( $type ),
				'category'               => self::diagnostic_category( $type ),
				'reason_code'            => $reason_code,
				'suggested_repair_class' => self::diagnostic_repair_class( $type ),
				'source_path'            => $source_path,
			);

			$selector = isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? trim( (string) $diagnostic['selector'] ) : '';
			if ( '' !== $selector ) {
				$machine['selector'] = $selector;
			}

			if ( isset( $diagnostic['line'] ) && is_numeric( $diagnostic['line'] ) ) {
				$machine['line'] = (int) $diagnostic['line'];
			}

			if ( isset( $diagnostic['line_range'] ) ) {
				$machine['line_range'] = $diagnostic['line_range'];
			}

			$context = self::diagnostic_context( $diagnostic );
			if ( ! empty( $context ) ) {
				$machine['context'] = $context;
			}

			$normalized[] = array_merge( $machine, $diagnostic );
		}

		$report['diagnostics'] = $normalized;
	}

	/**
	 * Build quality counter references into normalized diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,array<int,string>> Diagnostic IDs keyed by quality count.
	 */
	private static function quality_diagnostic_refs( array $diagnostics ): array {
		$types_by_count = array(
			'fallback_count'                     => array( 'unsupported_html_fallback' ),
			'content_loss_count'                 => array( 'content_loss_abort' ),
			'empty_conversion_count'             => array( 'empty_conversion' ),
			'core_html_block_count'              => array( 'core_html_block' ),
			'freeform_block_count'               => array( 'freeform_block' ),
			'invalid_block_count'                => array( 'invalid_block_document' ),
			'unsafe_svg_count'                   => array( 'unsafe_inline_svg' ),
			'svg_materialization_failure_count'  => array( 'svg_materialization_failure' ),
			'svg_sprite_reference_failure_count' => array( 'svg_sprite_reference_failure' ),
			'commerce_dependency_failures'       => array( 'commerce_dependency_failure' ),
		);

		$refs = array();
		foreach ( $types_by_count as $count_key => $types ) {
			$refs[ $count_key ] = array_values(
				array_filter(
					array_map(
						static function ( array $diagnostic ) use ( $types ): string {
							return in_array( $diagnostic['type'] ?? '', $types, true ) && isset( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '';
						},
						$diagnostics
					)
				)
			);
		}

		return $refs;
	}

	/**
	 * Link source-document counts back to concrete diagnostics.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return void
	 */
	private static function normalize_source_document_diagnostic_refs( array &$report ): void {
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$refs        = array(
			'unresolved_link_count'      => array(),
			'skipped_mdx_count'          => array(),
			'markdown_parse_error_count' => array(),
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || empty( $diagnostic['id'] ) ) {
				continue;
			}

			$type = (string) ( $diagnostic['type'] ?? '' );
			if ( 'unresolved_internal_link' === $type ) {
				$refs['unresolved_link_count'][] = (string) $diagnostic['id'];
			} elseif ( 'unsupported_source_document' === $type ) {
				$refs['skipped_mdx_count'][] = (string) $diagnostic['id'];
			} elseif ( 'markdown_parse_error' === $type ) {
				$refs['markdown_parse_error_count'][] = (string) $diagnostic['id'];
			}
		}

		$report['source_documents']['diagnostic_refs'] = $refs;
	}

	/**
	 * Build a stable diagnostic ID.
	 *
	 * @param int    $index       Zero-based diagnostic position.
	 * @param string $type        Diagnostic type.
	 * @param string $source_path Source-relative path.
	 * @param string $reason_code Reason code.
	 * @return string Diagnostic ID.
	 */
	private static function diagnostic_id( int $index, string $type, string $source_path, string $reason_code ): string {
		$slug = sanitize_key( $type . '-' . $reason_code . '-' . $source_path );
		if ( '' === $slug ) {
			$slug = 'import-diagnostic';
		}

		return sprintf( 'diag-%03d-%s', $index + 1, substr( $slug, 0, 80 ) );
	}

	/**
	 * Infer a source-relative path from a diagnostic source label.
	 *
	 * @param string $source Diagnostic source label.
	 * @return string Source-relative path or generated artifact path.
	 */
	private static function diagnostic_source_path( string $source ): string {
		if ( str_contains( $source, ':' ) ) {
			return (string) substr( $source, strpos( $source, ':' ) + 1 );
		}

		return $source;
	}

	/**
	 * Normalize a diagnostic reason code.
	 *
	 * @param string              $type       Diagnostic type.
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return string Reason code.
	 */
	private static function diagnostic_reason_code( string $type, array $diagnostic ): string {
		foreach ( array( 'reason_code', 'reason', 'error_code' ) as $key ) {
			if ( isset( $diagnostic[ $key ] ) && is_scalar( $diagnostic[ $key ] ) && '' !== trim( (string) $diagnostic[ $key ] ) ) {
				return sanitize_key( (string) $diagnostic[ $key ] );
			}
		}

		return sanitize_key( $type );
	}

	/**
	 * Classify diagnostic severity for repair prioritization.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Severity.
	 */
	private static function diagnostic_severity( string $type ): string {
		if ( in_array( $type, array( 'content_loss_abort', 'empty_conversion', 'invalid_block_document', 'commerce_dependency_failure' ), true ) ) {
			return 'error';
		}

		return 'warning';
	}

	/**
	 * Classify diagnostics by generic repair category.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Category.
	 */
	private static function diagnostic_category( string $type ): string {
		$categories = array(
			'local_asset_not_materialized'         => 'unresolved_asset',
			'unresolved_internal_link'             => 'broken_internal_link',
			'unsafe_inline_svg'                    => 'unsafe_svg',
			'svg_materialization_failure'          => 'unresolved_asset',
			'svg_sprite_reference_failure'         => 'unresolved_asset',
			'unsupported_source_document'          => 'unsupported_source',
			'unsupported_html_fallback'            => 'unsupported_element',
			'core_html_block'                      => 'fallback_block',
			'freeform_block'                       => 'fallback_block',
			'invalid_block_document'               => 'conversion_quality',
			'content_loss_abort'                   => 'conversion_quality',
			'empty_conversion'                     => 'conversion_quality',
			'source_region_unassigned'             => 'source_region',
			'commerce_dependency_failure'          => 'conversion_quality',
			'commerce_product_inference_unmatched' => 'conversion_quality',
		);

		return $categories[ $type ] ?? 'import_quality';
	}

	/**
	 * Suggest a generic repair class for a diagnostic type.
	 *
	 * @param string $type Diagnostic type.
	 * @return string Suggested repair class.
	 */
	private static function diagnostic_repair_class( string $type ): string {
		$classes = array(
			'local_asset_not_materialized'         => 'materialize_or_rewrite_asset',
			'unresolved_internal_link'             => 'rewrite_or_create_internal_target',
			'unsafe_inline_svg'                    => 'sanitize_or_externalize_svg',
			'svg_materialization_failure'          => 'materialize_or_rewrite_asset',
			'svg_sprite_reference_failure'         => 'materialize_or_rewrite_asset',
			'unsupported_source_document'          => 'convert_source_document',
			'unsupported_html_fallback'            => 'replace_unsupported_html',
			'core_html_block'                      => 'replace_fallback_block',
			'freeform_block'                       => 'replace_fallback_block',
			'invalid_block_document'               => 'repair_generated_block_markup',
			'content_loss_abort'                   => 'repair_source_conversion',
			'empty_conversion'                     => 'repair_source_conversion',
			'source_region_unassigned'             => 'assign_or_ignore_source_region',
			'commerce_dependency_failure'          => 'install_or_configure_dependency',
			'commerce_product_inference_unmatched' => 'provide_structured_product_data',
		);

		return $classes[ $type ] ?? 'inspect_import_diagnostic';
	}

	/**
	 * Extract concise diagnostic context for repair prompts.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return array<string,mixed> Context fields.
	 */
	private static function diagnostic_context( array $diagnostic ): array {
		$context = array();
		foreach ( array( 'href', 'tag', 'tag_name', 'block_name', 'block_path', 'excerpt', 'html_excerpt', 'source_html_preview', 'error_message' ) as $key ) {
			if ( array_key_exists( $key, $diagnostic ) && null !== $diagnostic[ $key ] && '' !== $diagnostic[ $key ] ) {
				$context[ $key ] = $diagnostic[ $key ];
			}
		}

		return $context;
	}

	/**
	 * Summarize compiler evidence for compact import metrics.
	 *
	 * @param array<string,mixed> $report Full conversion report.
	 * @return array<string,mixed>
	 */
	private static function compact_import_report_compiler_summary( array $report ): array {
		$compiler = isset( $report['blocks_engine'] ) && is_array( $report['blocks_engine'] ) ? $report['blocks_engine'] : array();
		$summary  = array(
			'available'      => ! empty( $compiler['available'] ),
			'fragment_count' => (int) ( $compiler['fragment_count'] ?? 0 ),
		);

		$website_artifact = isset( $compiler['website_artifact'] ) && is_array( $compiler['website_artifact'] ) ? $compiler['website_artifact'] : array();
		$compiler_summary = isset( $website_artifact['summary'] ) && is_array( $website_artifact['summary'] ) ? $website_artifact['summary'] : array();
		if ( empty( $compiler_summary ) && ! empty( $compiler['fragments'][0]['summary'] ) && is_array( $compiler['fragments'][0]['summary'] ) ) {
			$compiler_summary = $compiler['fragments'][0]['summary'];
		}

		foreach ( array( 'schema', 'status', 'source' ) as $field ) {
			if ( isset( $compiler_summary[ $field ] ) && is_scalar( $compiler_summary[ $field ] ) ) {
				$summary[ $field ] = (string) $compiler_summary[ $field ];
			}
		}

		if ( isset( $compiler_summary['diagnostic_count'] ) ) {
			$summary['diagnostic_count'] = (int) $compiler_summary['diagnostic_count'];
		}

		return $summary;
	}

	/**
	 * Summarize compact diagnostics by severity.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,int>
	 */
	private static function compact_import_report_diagnostic_summary( array $diagnostics ): array {
		$summary = array(
			'total'   => 0,
			'error'   => 0,
			'warning' => 0,
			'notice'  => 0,
			'info'    => 0,
		);

		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			++$summary['total'];
			$severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'warning';
			if ( ! array_key_exists( $severity, $summary ) ) {
				$severity = 'warning';
			}

			++$summary[ $severity ];
		}

		return $summary;
	}

	/**
	 * Build concise diagnostic summaries for a severity bucket.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @param string                         $severity    Severity to include.
	 * @return array<int,array<string,string>>
	 */
	private static function compact_import_report_diagnostic_summaries_by_severity( array $diagnostics, string $severity ): array {
		$summaries = array();
		foreach ( $diagnostics as $diagnostic ) {
			$diagnostic_severity = isset( $diagnostic['severity'] ) && is_scalar( $diagnostic['severity'] ) ? (string) $diagnostic['severity'] : 'warning';
			if ( $severity !== $diagnostic_severity ) {
				continue;
			}

			$summaries[] = array(
				'id'      => isset( $diagnostic['id'] ) && is_scalar( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '',
				'type'    => isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic',
				'source'  => self::diagnostic_summary_source( $diagnostic ),
				'message' => self::diagnostic_summary_message( $diagnostic ),
			);

			if ( count( $summaries ) >= 10 ) {
				break;
			}
		}

		return $summaries;
	}

	/**
	 * Resolve a concise diagnostic source label.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function diagnostic_summary_source( array $diagnostic ): string {
		foreach ( array( 'source_path', 'source' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) ) {
				return (string) $diagnostic[ $field ];
			}
		}

		return '';
	}

	/**
	 * Resolve a concise diagnostic message.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return string
	 */
	private static function diagnostic_summary_message( array $diagnostic ): string {
		foreach ( array( 'message', 'reason', 'excerpt', 'error_message', 'html_excerpt' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && '' !== trim( (string) $diagnostic[ $field ] ) ) {
				return self::diagnostic_excerpt( (string) $diagnostic[ $field ] );
			}
		}

		return isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic';
	}

	/**
	 * Keep summary diagnostics compact while preserving repair-agent evidence.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<int,array<string,mixed>> Compact diagnostics for validation harnesses.
	 */
	private static function compact_import_report_diagnostics( array $diagnostics ): array {
		$fields = array(
			'id',
			'type',
			'severity',
			'category',
			'reason_code',
			'suggested_repair_class',
			'source_path',
			'source',
			'selector',
			'excerpt',
			'source_html_preview',
			'emitted_block_preview',
			'block_name',
			'block_path',
			'engine',
			'stage',
			'reason',
			'message',
			'tag_name',
			'html_excerpt',
			'context',
		);

		$compact = array();
		foreach ( array_slice( $diagnostics, 0, 50 ) as $diagnostic ) {
			$row = array();
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $diagnostic ) || null === $diagnostic[ $field ] || '' === $diagnostic[ $field ] || array() === $diagnostic[ $field ] ) {
					continue;
				}

				$row[ $field ] = $diagnostic[ $field ];
			}

			if ( ! empty( $row ) ) {
				$compact[] = $row;
			}
		}

		return $compact;
	}

	/**
	 * Infer a compact CSS-like selector from an HTML preview.
	 *
	 * @param string $html Source HTML.
	 * @return string
	 */
	private static function diagnostic_selector_from_html( string $html ): string {
		if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b([^>]*)>/i', $html, $match ) ) {
			return '';
		}

		$selector = strtolower( (string) $match[1] );
		$attrs    = (string) $match[2];
		if ( preg_match( '/\sid\s*=\s*(["\'])(.*?)\1/i', $attrs, $id_match ) ) {
			$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id_match[2] );
			if ( is_string( $id ) && '' !== $id ) {
				$selector .= '#' . $id;
			}
		}

		if ( preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $class_match ) ) {
			$classes = preg_split( '/\s+/', trim( (string) $class_match[2] ) );
			if ( is_array( $classes ) ) {
				foreach ( array_slice( $classes, 0, 3 ) as $class ) {
					$class = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
					if ( is_string( $class ) && '' !== $class ) {
						$selector .= '.' . $class;
					}
				}
			}
		}

		return $selector;
	}

	/**
	 * Infer the first element tag name from an HTML preview.
	 *
	 * @param string $html Source HTML.
	 * @return string|null
	 */
	private static function diagnostic_tag_name_from_html( string $html ): ?string {
		if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b/i', $html, $match ) ) {
			return null;
		}

		return strtoupper( (string) $match[1] );
	}
}
