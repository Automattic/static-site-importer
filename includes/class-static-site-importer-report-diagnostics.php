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
if ( ! class_exists( 'Static_Site_Importer_Product_Handoff_Contract' ) ) {
	require_once __DIR__ . '/class-static-site-importer-product-handoff-contract.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Seeder' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-seeder.php';
}
if ( ! class_exists( 'Static_Site_Importer_Entity_Materializer_Registry' ) ) {
	require_once __DIR__ . '/class-static-site-importer-entity-materializer-registry.php';
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
			'schema'                  => Static_Site_Importer_Product_Handoff_Contract::SSI_IMPORT_REPORT_SCHEMA,
			'version'                 => 1,
			'entry_file'              => $html_path,
			'source'                  => array_merge(
				array(
					'type' => empty( $source_metadata ) ? 'file' : (string) ( $source_metadata['source_type'] ?? 'file' ),
				),
				$source_metadata
			),
			'quality'                 => array(
				'pass'                                  => true,
				'fallback_count'                        => 0,
				'content_loss_count'                    => 0,
				'empty_conversion_count'                => 0,
				'core_html_block_count'                 => 0,
				'freeform_block_count'                  => 0,
				'invalid_block_count'                   => 0,
				'invalid_block_document_count'          => 0,
				'unsafe_svg_count'                      => 0,
				'svg_materialization_failure_count'     => 0,
				'svg_sprite_reference_failure_count'    => 0,
				'commerce_dependency_failures'          => 0,
				'companion_plugin_dependency_failures'  => 0,
				'interaction_candidate_count'           => 0,
				'runtime_dependency_parity_issue_count' => 0,
				'semantic_parity_failure_count'         => 0,
				'failure_reasons'                       => array(),
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
			'source_of_truth'         => array(
				'schema'           => 'static-site-importer/source-of-truth-manifest/v1',
				'import_run_id'    => '',
				'artifact'         => array(),
				'desired'          => array(
					'pages'  => array(),
					'files'  => array(),
					'assets' => array(),
				),
				'existing_matches' => array(
					'pages' => array(),
				),
				'manifest_path'    => '',
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
				'templates'         => array(),
				'template_parts'    => array(),
				'block_documents'   => array(),
				'freeform_blocks'   => array(),
			),
			'materialized_content'    => array(
				'block_documents' => array(),
			),
			'visual_fidelity'         => array(
				'status'             => 'requires_runtime_visual_parity_check',
				'gate_owner'         => 'codebox_runtime',
				'comparison_targets' => array(),
				'notes'              => array(
					'Static Site Importer records stable artifact slots for Codebox/runtime visual parity validation; browser rendering, screenshots, and diffs are captured by the runtime when available.',
				),
			),
			'visual_parity_artifacts' => self::visual_parity_artifact_contract(),
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
				'Visual fidelity requires browser rendering; use visual_parity_artifacts for durable Codebox/runtime evidence and explicit pending/not-captured slots.',
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
		$artifacts                                   = isset( $compiled['artifacts'] ) && is_array( $compiled['artifacts'] ) ? $compiled['artifacts'] : array();
		$site                                        = isset( $artifacts['site'] ) && is_array( $artifacts['site'] ) ? $artifacts['site'] : array();
		$report['blocks_engine']['available']        = true;
		$report['blocks_engine']['website_artifact'] = array(
			'summary'     => ( new Static_Site_Importer_Transformer_Adapter() )->summarize_result( $compiled ),
			'provenance'  => isset( $compiled['provenance'] ) && is_array( $compiled['provenance'] ) ? $compiled['provenance'] : array(),
			'input'       => isset( $compiled['input'] ) && is_array( $compiled['input'] ) ? $compiled['input'] : array(),
			'diagnostics' => isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array(),
		);
		$report['blocks_engine']['transformer']      = self::transformer_package_provenance();

		if ( 'blocks-engine/php-transformer/compiled-site/v1' === (string) ( $site['schema'] ?? '' ) ) {
			$report['blocks_engine']['compiled_site'] = self::compiled_site_report_payload( $site );
		}
		if ( 'blocks-engine/php-transformer/materialization-plan/v1' === (string) ( $site['schema'] ?? '' ) ) {
			$report['blocks_engine']['materialization_plan'] = self::compiled_site_report_payload( $site );
		}

		$conversion_report = isset( $compiled['conversion_report'] ) && is_array( $compiled['conversion_report'] ) ? $compiled['conversion_report'] : array();
		if ( ! empty( $conversion_report ) ) {
			$report['blocks_engine']['conversion_report'] = self::conversion_report_payload( $conversion_report );
			self::record_conversion_report_quality_metadata( $report, $conversion_report );
		}

		self::mark_materialized_script_fallbacks_carried( $report, $site );

		$runtime_dependency_parity = isset( $compiled['runtime_dependency_parity'] ) && is_array( $compiled['runtime_dependency_parity'] ) ? $compiled['runtime_dependency_parity'] : array();
		if ( ! empty( $runtime_dependency_parity ) ) {
			$report['blocks_engine']['runtime_dependency_parity'] = self::runtime_dependency_parity_payload( $runtime_dependency_parity );
			self::record_runtime_dependency_parity_quality_metadata( $report, $runtime_dependency_parity );
		}

		$semantic_parity = self::blocks_engine_semantic_parity_report( $compiled );
		if ( ! empty( $semantic_parity ) ) {
			$report['blocks_engine']['semantic_parity'] = self::semantic_parity_report_payload( $semantic_parity );
			self::record_semantic_parity_quality_metadata( $report, $semantic_parity );
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
		$artifacts = isset( $compiled['artifacts'] ) && is_array( $compiled['artifacts'] ) ? $compiled['artifacts'] : array();
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
			'message'     => 'Direct materialization consumed block_markup, documents, files, and materialization-plan artifacts. Static Site Importer owns WordPress writes and product seeding while Blocks Engine owns materializer-neutral site/theme compilation.',
			'contract'    => isset( $compiled['schema'] ) && is_scalar( $compiled['schema'] ) ? (string) $compiled['schema'] : 'blocks-engine/php-transformer/result/v1',
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
		if ( function_exists( 'serialize_blocks' ) && self::is_serializable_parsed_block( $block ) ) {
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
			'stage'                 => isset( $context['stage'] ) ? (string) $context['stage'] : 'block_conversion',
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
	 * Check whether a diagnostic block has the parsed-block fields WordPress serialization requires.
	 *
	 * @param array<string,mixed> $block Generated or parsed block.
	 * @return bool
	 */
	private static function is_serializable_parsed_block( array $block ): bool {
		if ( ! array_key_exists( 'blockName', $block ) || ! isset( $block['attrs'], $block['innerBlocks'], $block['innerContent'] ) ) {
			return false;
		}

		if ( null !== $block['blockName'] && ! is_string( $block['blockName'] ) ) {
			return false;
		}

		if ( ! is_array( $block['attrs'] ) || ! is_array( $block['innerBlocks'] ) || ! is_array( $block['innerContent'] ) ) {
			return false;
		}

		foreach ( $block['innerBlocks'] as $inner_block ) {
			if ( ! is_array( $inner_block ) || ! self::is_serializable_parsed_block( $inner_block ) ) {
				return false;
			}
		}

		return true;
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
		$report['visual_parity_artifacts']  = self::visual_parity_artifact_contract( isset( $args['validation_artifacts'] ) && is_array( $args['validation_artifacts'] ) ? $args['validation_artifacts'] : array() );
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
			'schema'                  => 'blocks-engine/import-validation-result/v1',
			'artifact_type'           => 'ImportValidationResult',
			'version'                 => 1,
			'status'                  => ! empty( $quality['fail_import'] ) ? 'failed' : ( ! empty( $quality['pass'] ) ? 'passed' : 'reported' ),
			'quality_pass'            => ! empty( $quality['pass'] ),
			'fail_import'             => ! empty( $quality['fail_import'] ),
			'failure_reasons'         => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'counts'                  => array(
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
				'interaction_candidates'        => (int) ( $quality['interaction_candidate_count'] ?? 0 ),
				'runtime_dependency_parity'     => (int) ( $quality['runtime_dependency_parity_issue_count'] ?? 0 ),
				'semantic_parity_failures'      => (int) ( $quality['semantic_parity_failure_count'] ?? 0 ),
			),
			'quality_gates'           => array(
				'fallback_blocks'           => self::validation_gate( 'fallback_blocks', (int) ( $quality['fallback_count'] ?? 0 ), $quality ),
				'conversion_failures'       => self::validation_gate( 'conversion_failures', (int) ( $quality['content_loss_count'] ?? 0 ) + (int) ( $quality['empty_conversion_count'] ?? 0 ) + (int) ( $quality['invalid_block_count'] ?? 0 ), $quality ),
				'generated_fallback_blocks' => self::validation_gate( 'generated_fallback_blocks', (int) ( $quality['core_html_block_count'] ?? 0 ) + (int) ( $quality['freeform_block_count'] ?? 0 ), $quality ),
				'asset_materialization'     => self::validation_gate( 'asset_materialization', (int) ( $quality['svg_materialization_failure_count'] ?? 0 ) + (int) ( $quality['svg_sprite_reference_failure_count'] ?? 0 ), $quality ),
				'commerce_dependencies'     => self::validation_gate( 'commerce_dependencies', (int) ( $quality['commerce_dependency_failures'] ?? 0 ), $quality ),
				'interaction_candidates'    => self::validation_gate( 'interaction_candidates', (int) ( $quality['interaction_candidate_count'] ?? 0 ), $quality ),
				'runtime_dependency_parity' => self::validation_gate( 'runtime_dependency_parity', (int) ( $quality['runtime_dependency_parity_issue_count'] ?? 0 ), $quality ),
				'semantic_parity'           => self::validation_gate( 'semantic_parity', (int) ( $quality['semantic_parity_failure_count'] ?? 0 ), $quality ),
				'visual_fidelity'           => array(
					'status' => (string) ( $report['visual_fidelity']['status'] ?? 'requires_external_render_check' ),
					'owner'  => (string) ( $report['visual_fidelity']['gate_owner'] ?? 'benchmark_harness' ),
				),
				'semantic_fidelity'         => array(
					'status' => (string) ( $report['semantic_fidelity']['status'] ?? 'requires_external_render_check' ),
					'owner'  => (string) ( $report['semantic_fidelity']['gate_owner'] ?? 'benchmark_harness' ),
				),
			),
			'diagnostic_summary'      => $summary['diagnostic_summary'] ?? array(),
			'diagnostics'             => self::compact_import_report_diagnostics( $diagnostics ),
			'diagnostic_refs'         => isset( $quality['diagnostic_refs'] ) && is_array( $quality['diagnostic_refs'] ) ? $quality['diagnostic_refs'] : array(),
			'artifacts'               => self::validation_artifact_refs(),
			'visual_parity_artifacts' => isset( $report['visual_parity_artifacts'] ) && is_array( $report['visual_parity_artifacts'] ) ? $report['visual_parity_artifacts'] : self::visual_parity_artifact_contract(),
			'provenance'              => self::validation_provenance( $report ),
			'reproduction_context'    => self::validation_reproduction_context( $report ),
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
		$materialization_plan = isset( $report['blocks_engine']['materialization_plan'] ) && is_array( $report['blocks_engine']['materialization_plan'] ) ? $report['blocks_engine']['materialization_plan'] : array();
		self::mark_active_companion_script_fallbacks_materialized( $report );
		self::mark_materialized_script_fallbacks_carried( $report, $materialization_plan );
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
		if ( ( $quality['companion_plugin_dependency_failures'] ?? 0 ) > 0 ) {
			$reasons[] = 'companion_plugin_missing';
		}
		if ( ( $quality['runtime_dependency_parity_issue_count'] ?? 0 ) > 0 ) {
			$reasons[] = 'runtime_dependency_parity';
		}
		if ( ( $quality['semantic_parity_failure_count'] ?? 0 ) > 0 ) {
			$reasons[] = 'semantic_parity_failure';
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
		if ( in_array( 'companion_plugin_missing', $reasons, true ) ) {
			$quality['fail_import'] = true;
		}

		$quality['diagnostic_refs'] = self::quality_diagnostic_refs( $report['diagnostics'] ?? array() );
		$report['quality']          = $quality;
		self::normalize_source_document_diagnostic_refs( $report );
		$report['artifact_diagnostics'] = Static_Site_Importer_Artifact_Diagnostics_Adapter::build_for_import_report( $report );

		return $quality;
	}

	/**
	 * Record a generated companion-plugin dependency into a conversion report.
	 *
	 * Mirrors the WooCommerce/Jetpack directory-dependency surface so the gate and
	 * diagnostics treat a generated companion as a first-class declared dependency:
	 * a present companion emits an info diagnostic, a waived-but-missing one emits a
	 * warning, and a required-but-missing one increments the dependency-failure
	 * quality counter (which fails the import) and emits an error diagnostic. The
	 * declared dependency row is stored under `companion_plugins.dependencies` keyed
	 * by the namespaced companion slug, distinct from `commerce.dependencies`.
	 *
	 * @param array<string, mixed> $report     Conversion report (mutated in place).
	 * @param array<string, mixed> $dependency Companion dependency definition.
	 * @param bool                 $waived     Whether enforcement is waived.
	 * @return void
	 */
	public static function record_companion_plugin_dependency( array &$report, array $dependency, bool $waived ): void {
		$row  = Static_Site_Importer_Entity_Materializer_Registry::companion_dependency_row( $dependency, $waived );
		$slug = (string) ( $row['slug'] ?? '' );
		if ( '' === $slug ) {
			return;
		}

		if ( ! isset( $report['companion_plugins'] ) || ! is_array( $report['companion_plugins'] ) ) {
			$report['companion_plugins'] = array( 'dependencies' => array() );
		}
		if ( ! isset( $report['companion_plugins']['dependencies'] ) || ! is_array( $report['companion_plugins']['dependencies'] ) ) {
			$report['companion_plugins']['dependencies'] = array();
		}
		$report['companion_plugins']['dependencies'][ $slug ] = $row;

		if ( ! isset( $report['diagnostics'] ) || ! is_array( $report['diagnostics'] ) ) {
			$report['diagnostics'] = array();
		}

		$source = 'companion_plugins.dependencies.' . $slug;

		if ( ! empty( $row['active'] ) ) {
			$island_handles = isset( $row['island_handles'] ) && is_array( $row['island_handles'] ) ? $row['island_handles'] : array();
			$runtime_scripts = isset( $row['runtime_scripts'] ) && is_array( $row['runtime_scripts'] ) ? $row['runtime_scripts'] : array();
			self::mark_companion_script_fallbacks_materialized( $report, $runtime_scripts, $slug );
			$present        = array(
				'code'           => 'companion_plugin_present',
				'severity'       => 'info',
				'source'         => $source,
				'message'        => sprintf( 'Companion plugin %s is active; generated blocks are available theme-independently.', $slug ),
				'slug'           => $slug,
				'block_names'    => $row['block_names'] ?? array(),
				'island_handles' => $island_handles,
			);
			// Preserved island JS that rides the active companion plugin is
			// carried theme-independently; flag the runtime-carried signal the
			// honest gate looks for so this JS is not counted as lost.
			if ( ! empty( $island_handles ) ) {
				$present['runtime_carried'] = true;
				$present['message']         = sprintf( 'Companion plugin %s is active; generated blocks and preserved island JS are carried theme-independently.', $slug );
			}
			$report['diagnostics'][] = $present;
			return;
		}

		if ( $waived ) {
			$report['diagnostics'][] = array(
				'code'        => 'companion_plugin_waived',
				'severity'    => 'warning',
				'source'      => $source,
				'message'     => sprintf( 'Companion plugin %s requirement was waived; generated blocks were not installed.', $slug ),
				'slug'        => $slug,
				'block_names' => $row['block_names'] ?? array(),
			);
			return;
		}

		if ( ! isset( $report['quality'] ) || ! is_array( $report['quality'] ) ) {
			$report['quality'] = array();
		}
		$report['quality']['companion_plugin_dependency_failures'] = (int) ( $report['quality']['companion_plugin_dependency_failures'] ?? 0 ) + 1;

		$report['diagnostics'][] = array(
			'code'        => 'companion_plugin_missing',
			'severity'    => 'error',
			'source'      => $source,
			'message'     => sprintf( 'Companion plugin %s is required to house generated blocks but is not installed/active.', $slug ),
			'slug'        => $slug,
			'block_names' => $row['block_names'] ?? array(),
		);
	}

	/**
	 * Build the compact report summary consumed by validation harnesses.
	 *
	 * @param array<string, mixed> $report  Full conversion report.
	 * @param array<string, mixed> $quality Finalized quality summary.
	 * @return array<string, mixed>
	 */
	public static function import_report_summary( array $report, array $quality ): array {
		$diagnostics            = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$source_documents       = isset( $report['source_documents'] ) && is_array( $report['source_documents'] ) ? $report['source_documents'] : array();
		$commerce               = isset( $report['commerce'] ) && is_array( $report['commerce'] ) ? $report['commerce'] : array();
		$commerce_context       = isset( $report['commerce_context'] ) && is_array( $report['commerce_context'] ) ? $report['commerce_context'] : array();
		$plugin_materialization = isset( $report['plugin_materialization'] ) && is_array( $report['plugin_materialization'] ) ? $report['plugin_materialization'] : array();
		$product_seeding        = isset( $report['product_seeding'] ) && is_array( $report['product_seeding'] ) ? $report['product_seeding'] : array();

		return array(
			'schema'                                => 'static-site-importer/import-metrics/v1',
			'version'                               => 1,
			'report_version'                        => (int) ( $report['version'] ?? 0 ),
			'status'                                => ! empty( $quality['fail_import'] ) ? 'failed' : 'completed',
			'theme_slug'                            => isset( $report['theme_slug'] ) ? (string) $report['theme_slug'] : '',
			'entry_file'                            => isset( $report['entry_file'] ) ? (string) $report['entry_file'] : '',
			'compiler'                              => self::compact_import_report_compiler_summary( $report ),
			'quality_pass'                          => ! empty( $quality['pass'] ),
			'fail_import'                           => ! empty( $quality['fail_import'] ),
			'failure_reasons'                       => isset( $quality['failure_reasons'] ) && is_array( $quality['failure_reasons'] ) ? array_values( $quality['failure_reasons'] ) : array(),
			'fallback_count'                        => (int) ( $quality['fallback_count'] ?? 0 ),
			'content_loss_count'                    => (int) ( $quality['content_loss_count'] ?? 0 ),
			'empty_conversion_count'                => (int) ( $quality['empty_conversion_count'] ?? 0 ),
			'core_html_block_count'                 => (int) ( $quality['core_html_block_count'] ?? 0 ),
			'freeform_block_count'                  => (int) ( $quality['freeform_block_count'] ?? 0 ),
			'invalid_block_count'                   => (int) ( $quality['invalid_block_count'] ?? 0 ),
			'invalid_block_document_count'          => (int) ( $quality['invalid_block_document_count'] ?? 0 ),
			'interaction_candidate_count'           => (int) ( $quality['interaction_candidate_count'] ?? 0 ),
			'runtime_dependency_parity_issue_count' => (int) ( $quality['runtime_dependency_parity_issue_count'] ?? 0 ),
			'semantic_parity_failure_count'         => (int) ( $quality['semantic_parity_failure_count'] ?? 0 ),
			'source_document_count'                 => (int) ( $source_documents['total_count'] ?? 0 ),
			'unresolved_link_count'                 => (int) ( $source_documents['unresolved_link_count'] ?? 0 ),
			'commerce'                              => $commerce,
			'commerce_context'                      => $commerce_context,
			'plugin_materialization'                => $plugin_materialization,
			'product_seeding'                       => $product_seeding,
			'visual_parity_artifacts'               => isset( $report['visual_parity_artifacts'] ) && is_array( $report['visual_parity_artifacts'] ) ? $report['visual_parity_artifacts'] : self::visual_parity_artifact_contract(),
			'semantic_parity'                       => self::compact_semantic_parity_summary( $report ),
			'diagnostic_count'                      => count( $diagnostics ),
			'diagnostic_summary'                    => self::compact_import_report_diagnostic_summary( $diagnostics ),
			'loss_class_summary'                    => Static_Site_Importer_Diagnostic_Loss_Classes::counts( $diagnostics ),
			'warning_summaries'                     => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'warning' ),
			'error_summaries'                       => self::compact_import_report_diagnostic_summaries_by_severity( $diagnostics, 'error' ),
			'diagnostics'                           => self::compact_import_report_diagnostics( $diagnostics ),
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
			'interaction_candidates'    => 'interaction_candidate_count',
			'runtime_dependency_parity' => 'runtime_dependency_parity_issue_count',
			'semantic_parity'           => 'semantic_parity_failure_count',
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
	 * Build the stable Codebox/runtime visual parity artifact contract.
	 *
	 * @param array<string,mixed> $provided Runtime-provided durable artifact refs.
	 * @return array<string,mixed>
	 */
	private static function visual_parity_artifact_contract( array $provided = array() ): array {
		$slots = array(
			'browser_render'           => array(
				'kind'    => 'browser_render_evidence',
				'aliases' => array( 'browser-html', 'browser-artifact', 'artifact-bundle' ),
				'reason'  => 'Codebox/runtime browser render evidence was not provided.',
			),
			'source_screenshot'        => array(
				'kind'   => 'source_screenshot',
				'reason' => 'Source screenshot was not captured by the runtime.',
			),
			'imported_screenshot'      => array(
				'kind'   => 'imported_screenshot',
				'reason' => 'Imported WordPress screenshot was not captured by the runtime.',
			),
			'visual_diff'              => array(
				'kind'    => 'visual_diff',
				'aliases' => array( 'visual_parity_artifact' ),
				'reason'  => 'Visual diff output was not captured by the runtime.',
			),
			'import_report'            => array(
				'kind'          => 'static-site-importer/import-report',
				'artifact_name' => 'import-report.json',
			),
			'import_validation_result' => array(
				'kind'          => 'static-site-importer/import-validation-result',
				'artifact_name' => 'import-validation-result.json',
			),
			'finding_packets'          => array(
				'kind'          => 'static-site-importer/finding-packets',
				'artifact_name' => 'finding-packets.json',
			),
			'block_validation'         => array(
				'kind'   => 'gutenberg_block_validation',
				'reason' => 'Block validation artifact was not provided by the runtime.',
			),
		);

		$artifacts = array();
		foreach ( $slots as $name => $slot ) {
			$aliases = isset( $slot['aliases'] ) ? $slot['aliases'] : array();
			$ref     = self::durable_artifact_ref( self::provided_visual_parity_artifact_ref( $provided, $name, (string) $slot['kind'], $aliases ) );
			if ( empty( $ref ) && isset( $slot['artifact_name'] ) ) {
				$ref = array(
					'kind'          => (string) $slot['kind'],
					'artifact_name' => (string) $slot['artifact_name'],
				);
			}

			$artifacts[ $name ] = array_merge(
				array(
					'status' => empty( $ref ) ? 'pending' : 'captured',
					'kind'   => (string) $slot['kind'],
				),
					empty( $ref )
					? array(
						'capture_state' => 'not_captured',
						'reason'        => (string) $slot['reason'],
					)
					: array( 'ref' => $ref )
			);
		}

		$missing = array_keys(
			array_filter(
				$artifacts,
				static fn ( array $artifact ): bool => 'captured' !== $artifact['status']
			)
		);

		$metrics = self::visual_parity_metrics( $provided );

		return array_filter(
			array(
				'schema'      => 'static-site-importer/visual-parity-artifacts/v1',
				'status'      => empty( $missing ) ? 'complete' : 'pending',
				'owner'       => 'codebox_runtime',
				'contract'    => 'durable_artifact_refs_only',
				'missing'     => $missing,
				'metrics'     => $metrics,
				'artifacts'   => $artifacts,
				'local_paths' => 'omitted',
				'notes'       => array(
					'Screenshot and diff slots stay pending until Codebox/runtime validation supplies durable artifact refs.',
					'Reviewer-facing refs use artifact IDs, URLs, or artifact names; local filesystem paths are intentionally omitted.',
				),
			),
			static fn ( mixed $value ): bool => array() !== $value
		);
	}

	/**
	 * Extract visual parity metrics from runtime validation output.
	 *
	 * @param array<string,mixed> $provided Runtime-provided validation artifacts.
	 * @return array<string,mixed>
	 */
	private static function visual_parity_metrics( array $provided ): array {
		$summaries = array();
		if ( isset( $provided['summary'] ) && is_array( $provided['summary'] ) ) {
			$summaries[] = $provided['summary'];
		}
		if ( isset( $provided['codebox_validation']['summary'] ) && is_array( $provided['codebox_validation']['summary'] ) ) {
			$summaries[] = $provided['codebox_validation']['summary'];
		}

		$metrics = array();
		foreach ( $summaries as $summary ) {
			foreach ( array( 'pixel_delta_percent', 'average_delta', 'compared_width', 'compared_height', 'screenshot_artifacts', 'visual_diff_artifacts' ) as $key ) {
				if ( isset( $summary[ $key ] ) && is_numeric( $summary[ $key ] ) ) {
					$metrics[ $key ] = 0 + $summary[ $key ];
				}
			}
		}

		return $metrics;
	}

	/**
	 * Read a named runtime artifact ref from direct keys or artifact_refs arrays.
	 *
	 * @param array<string,mixed> $provided Runtime-provided artifacts.
	 * @param string              $name     Artifact slot name.
	 * @param string              $kind     Expected artifact kind.
	 * @param array<int,string>   $aliases  Accepted artifact kind aliases.
	 * @return mixed
	 */
	private static function provided_visual_parity_artifact_ref( array $provided, string $name, string $kind, array $aliases = array() ): mixed {
		if ( isset( $provided[ $name ] ) ) {
			return $provided[ $name ];
		}

		$refs = array();
		foreach ( array( 'artifact_refs', 'artifacts' ) as $key ) {
			if ( isset( $provided[ $key ] ) && is_array( $provided[ $key ] ) ) {
				$refs = array_merge( $refs, array_values( $provided[ $key ] ) );
			}
		}

		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) ) {
				continue;
			}

			$ref_kind = isset( $ref['kind'] ) && is_scalar( $ref['kind'] ) ? (string) $ref['kind'] : '';
			$ref_role = isset( $ref['role'] ) && is_scalar( $ref['role'] ) ? (string) $ref['role'] : '';
			if ( $name === $ref_role || $kind === $ref_kind || in_array( $ref_kind, $aliases, true ) ) {
				return $ref;
			}
		}

		return null;
	}

	/**
	 * Normalize a runtime artifact reference to durable fields only.
	 *
	 * @param mixed $ref Runtime-provided artifact reference.
	 * @return array<string,string>
	 */
	private static function durable_artifact_ref( mixed $ref ): array {
		if ( is_string( $ref ) && '' !== trim( $ref ) ) {
			$ref = array( filter_var( $ref, FILTER_VALIDATE_URL ) ? 'url' : 'artifact_id' => $ref );
		}
		if ( ! is_array( $ref ) ) {
			return array();
		}

		$durable = array();
		foreach ( array( 'artifact_id', 'id', 'url', 'artifact_name', 'name', 'sha256' ) as $key ) {
			if ( ! isset( $ref[ $key ] ) || ! is_scalar( $ref[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $ref[ $key ] );
			if ( '' === $value || self::is_local_path_ref( $value ) ) {
				continue;
			}

			$normalized_key             = 'id' === $key ? 'artifact_id' : ( 'name' === $key ? 'artifact_name' : $key );
			$durable[ $normalized_key ] = $value;
		}

		if ( ! isset( $durable['artifact_name'] ) && isset( $ref['path'] ) && is_scalar( $ref['path'] ) ) {
			$path = trim( (string) $ref['path'] );
			if ( '' !== $path && ! self::is_local_path_ref( $path ) ) {
				$durable['artifact_name'] = basename( $path );
			}
		}

		if ( ! empty( $durable ) ) {
			foreach ( array( 'kind', 'role' ) as $key ) {
				if ( ! isset( $ref[ $key ] ) || ! is_scalar( $ref[ $key ] ) ) {
					continue;
				}

				$value = trim( (string) $ref[ $key ] );
				if ( '' !== $value ) {
					$durable[ $key ] = $value;
				}
			}
		}

		return $durable;
	}

	/**
	 * Determine whether a value is a local filesystem path that should not be shared.
	 *
	 * @param string $value Candidate artifact field value.
	 * @return bool
	 */
	private static function is_local_path_ref( string $value ): bool {
		return (bool) preg_match( '#^(?:/|[A-Za-z]:\\\\|file://|~[/\\\\]|(?:\.\.?[/\\\\]))#', $value );
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
				'interaction_candidate',
				'runtime_dependency_missing_dom_target',
				'runtime_dependency_unsupported_element_reference',
				'runtime_dependency_parity_issue',
				'semantic_parity_navigation_missing',
				'semantic_parity_navigation_mismatch',
				'semantic_parity_landmark_missing',
				'semantic_parity_failure',
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
			'loss_class'           => isset( $diagnostic['loss_class'] ) && is_scalar( $diagnostic['loss_class'] ) ? (string) $diagnostic['loss_class'] : Static_Site_Importer_Diagnostic_Loss_Classes::classify( $diagnostic ),
			'diagnostic_class'     => isset( $diagnostic['diagnostic_class'] ) && is_scalar( $diagnostic['diagnostic_class'] ) ? (string) $diagnostic['diagnostic_class'] : Static_Site_Importer_Diagnostic_Loss_Classes::classify( $diagnostic ),
			'repair_class'         => isset( $diagnostic['repair_class'] ) && is_scalar( $diagnostic['repair_class'] ) ? (string) $diagnostic['repair_class'] : self::diagnostic_repair_class( $type ),
			'acceptability'        => isset( $diagnostic['acceptability'] ) && is_scalar( $diagnostic['acceptability'] ) ? (string) $diagnostic['acceptability'] : self::diagnostic_acceptability( Static_Site_Importer_Diagnostic_Loss_Classes::classify( $diagnostic ) ),
			'source_diagnostic'    => isset( $diagnostic['source_diagnostic'] ) && is_array( $diagnostic['source_diagnostic'] ) ? $diagnostic['source_diagnostic'] : self::source_diagnostic_identity( $diagnostic, $diagnostic ),
			'owner'                => self::finding_owner( $diagnostic ),
			'routing'              => array(
				'component'              => self::finding_owner( $diagnostic ),
				'stage'                  => isset( $diagnostic['stage'] ) && is_scalar( $diagnostic['stage'] ) ? (string) $diagnostic['stage'] : 'import',
				'suggested_repair_class' => isset( $diagnostic['suggested_repair_class'] ) && is_scalar( $diagnostic['suggested_repair_class'] ) ? (string) $diagnostic['suggested_repair_class'] : self::diagnostic_repair_class( $type ),
				'repair_class'           => isset( $diagnostic['repair_class'] ) && is_scalar( $diagnostic['repair_class'] ) ? (string) $diagnostic['repair_class'] : self::diagnostic_repair_class( $type ),
				'repair_bucket'          => isset( $diagnostic['repair_bucket'] ) && is_scalar( $diagnostic['repair_bucket'] ) ? (string) $diagnostic['repair_bucket'] : '',
				'loss_class'             => isset( $diagnostic['loss_class'] ) && is_scalar( $diagnostic['loss_class'] ) ? (string) $diagnostic['loss_class'] : Static_Site_Importer_Diagnostic_Loss_Classes::classify( $diagnostic ),
				'acceptability'          => isset( $diagnostic['acceptability'] ) && is_scalar( $diagnostic['acceptability'] ) ? (string) $diagnostic['acceptability'] : self::diagnostic_acceptability( Static_Site_Importer_Diagnostic_Loss_Classes::classify( $diagnostic ) ),
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
			'source'               => array(
				'path'     => isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : '',
				'selector' => isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '',
				'snippet'  => isset( $diagnostic['source_html_preview'] ) && is_scalar( $diagnostic['source_html_preview'] ) ? (string) $diagnostic['source_html_preview'] : ( isset( $diagnostic['html_excerpt'] ) && is_scalar( $diagnostic['html_excerpt'] ) ? (string) $diagnostic['html_excerpt'] : '' ),
			),
			'observed'             => array(
				'output'      => isset( $diagnostic['emitted_block_preview'] ) && is_scalar( $diagnostic['emitted_block_preview'] ) ? (string) $diagnostic['emitted_block_preview'] : '',
				'block_name'  => isset( $diagnostic['block_name'] ) && is_scalar( $diagnostic['block_name'] ) ? (string) $diagnostic['block_name'] : '',
				'reason_code' => isset( $diagnostic['reason_code'] ) && is_scalar( $diagnostic['reason_code'] ) ? (string) $diagnostic['reason_code'] : self::diagnostic_reason_code( $type, $diagnostic ),
			),
			'expected'             => array(
				'outcome' => self::finding_expected_outcome( $diagnostic ),
			),
			'refs'                 => array_values( self::validation_artifact_refs() ),
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
		if ( in_array( $type, array( 'runtime_dependency_missing_dom_target', 'runtime_dependency_unsupported_element_reference', 'runtime_dependency_parity_issue' ), true ) ) {
			return 'Runtime scripts should be preserved only with DOM targets and browser elements that exist in the imported WordPress page.';
		}
		if ( str_starts_with( $type, 'semantic_parity_' ) ) {
			return 'Generate core WordPress blocks whose navigation, landmark, label, and URL semantics match the source structure.';
		}

		return 'Import should complete without this diagnostic being reported.';
	}

	/**
	 * Materialize preserved <form> runtime islands through the configured provider.
	 *
	 * Collects preserved form findings, runs them through the form-capability
	 * adapter, and stamps the runtime-mapped signal plus mapped-block evidence onto
	 * each finding that a real provider could map. The form_seeding report records
	 * provider, dependency availability, and per-form mapping outcomes. Forms with
	 * no mappable controls keep no signal and stay an unacceptable parity loss.
	 *
	 * When page contents are supplied, the seeded contact-form markup is grafted
	 * into the matching page's post_content in place of the finding's readable
	 * fallback region. The graft anchors on the finding's own readable_blocks (the
	 * structural identity the finding already carries), scoped to the finding's
	 * source page, and never matches visible copy. Findings that cannot be anchored
	 * leave the fallback in place and record a structured graft diagnostic.
	 *
	 * @param array<string,mixed> $report         Import report (mutated in place).
	 * @param array<string,mixed> $args           Import args.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename, mutated in place when a finding is grafted.
	 * @return array<string,mixed> The recorded form_seeding report.
	 */
	public static function materialize_form_findings( array &$report, array $args = array(), array &$page_contents = array() ): array {
		$adapter = Static_Site_Importer_Entity_Materializer_Registry::form_adapter();

		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$indexes     = array();
		foreach ( $diagnostics as $index => $diagnostic ) {
			if ( is_array( $diagnostic ) && self::is_materializable_form_diagnostic( $diagnostic ) ) {
				$indexes[] = (int) $index;
			}
		}

		if ( empty( $indexes ) ) {
			$seeding                = Static_Site_Importer_Entity_Materializer_Registry::new_entity_report( $adapter );
			$seeding['status']      = 'skipped';
			$seeding['reason']      = 'no_form_findings';
			$report['form_seeding'] = $seeding;
			return $seeding;
		}

		$manifest_forms = array();
		foreach ( $indexes as $index ) {
			$diagnostic       = $report['diagnostics'][ $index ];
			$controls         = isset( $diagnostic['controls'] ) && is_array( $diagnostic['controls'] ) ? $diagnostic['controls'] : array();
			$form             = isset( $diagnostic['form'] ) && is_array( $diagnostic['form'] ) ? $diagnostic['form'] : array();
			if ( empty( $controls ) && self::is_generated_core_html_form_diagnostic( $diagnostic ) ) {
				$extracted = self::extract_form_manifest_from_diagnostic( $diagnostic );
				$controls  = isset( $extracted['controls'] ) && is_array( $extracted['controls'] ) ? $extracted['controls'] : array();
				$form      = isset( $extracted['form'] ) && is_array( $extracted['form'] ) ? $extracted['form'] : $form;
			}
			$manifest_forms[] = array(
				'selector'    => isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '',
				'source_path' => isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : ( isset( $diagnostic['source'] ) && is_scalar( $diagnostic['source'] ) ? (string) $diagnostic['source'] : '' ),
				'form'        => $form,
				'controls'    => $controls,
			);
		}

		$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest_generic( $adapter, array( 'forms' => $manifest_forms ) );
		$seeding    = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, array( 'forms' => isset( $validation['forms'] ) && is_array( $validation['forms'] ) ? $validation['forms'] : array() ) );

		$seeding['provider']      = Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'form' );
		$seeding['form_count']    = count( $manifest_forms );
		$seeding['mapped_count']  = 0;
		$seeding['grafted_count'] = 0;
		$seeding['waived']        = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? 'allow_missing_jetpack' ) ] );
		if ( ! empty( $validation['errors'] ) ) {
			$seeding['validation_errors'] = $validation['errors'];
		}

		$pending = $indexes;
		$rows    = isset( $seeding['forms'] ) && is_array( $seeding['forms'] ) ? $seeding['forms'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['runtime_mapped'] ) ) {
				continue;
			}

			++$seeding['mapped_count'];
			$selector = isset( $row['selector'] ) && is_scalar( $row['selector'] ) ? (string) $row['selector'] : '';
			$source_path = isset( $row['source_path'] ) && is_scalar( $row['source_path'] ) ? (string) $row['source_path'] : '';
			$index    = self::form_finding_index_for_selector( $report['diagnostics'], $pending, $selector, $source_path );
			if ( null === $index ) {
				continue;
			}

			$report['diagnostics'][ $index ] = self::mark_form_finding_mapped( $report['diagnostics'][ $index ], $row, $seeding['provider'] );
			$report['diagnostics'][ $index ] = self::add_form_graft_source_path( $report['diagnostics'][ $index ], $report );

			if ( ! empty( $page_contents ) ) {
				$markup = isset( $row['block_markup'] ) && is_scalar( $row['block_markup'] ) ? (string) $row['block_markup'] : '';
				$graft  = self::graft_form_block_into_page_contents( $report['diagnostics'][ $index ], $markup, $page_contents );
				if ( $graft['grafted'] ) {
					++$seeding['grafted_count'];
				}
				$report['diagnostics'][ $index ] = $graft['finding'];
				if ( ! empty( $graft['diagnostic'] ) ) {
					$report['diagnostics'][] = $graft['diagnostic'];
				}
			}

			$pending = array_values( array_diff( $pending, array( $index ) ) );
		}

		$report['form_seeding'] = $seeding;
		return $seeding;
	}

	/**
	 * Determine whether import diagnostics require a form provider.
	 *
	 * This is intentionally shared with dependency provisioning so the provider is
	 * active before the same finding is converted and grafted into page content.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return bool
	 */
	public static function has_materializable_form_findings( array $report ): bool {
		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( is_array( $diagnostic ) && self::is_materializable_form_diagnostic( $diagnostic ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a diagnostic can be passed to the form provider.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return bool
	 */
	private static function is_materializable_form_diagnostic( array $diagnostic ): bool {
		if ( 'html_form_fallback' === (string) ( $diagnostic['diagnostic_code'] ?? '' ) ) {
			return true;
		}

		return self::is_generated_core_html_form_diagnostic( $diagnostic );
	}

	/**
	 * Identify generated post_content core/html diagnostics that preserve a form.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return bool
	 */
	private static function is_generated_core_html_form_diagnostic( array $diagnostic ): bool {
		if ( 'core/html' !== (string) ( $diagnostic['block_name'] ?? '' ) ) {
			return false;
		}

		$tag_name = strtolower( (string) ( $diagnostic['tag_name'] ?? $diagnostic['tag'] ?? $diagnostic['element'] ?? '' ) );
		if ( 'form' === $tag_name ) {
			return true;
		}

		$html = self::first_scalar( $diagnostic, array( 'source_html_preview', 'html_excerpt', 'excerpt' ) );
		return '' !== $html && str_contains( strtolower( $html ), '<form' );
	}

	/**
	 * Extract the provider manifest shape from a generated core/html form diagnostic.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return array{form:array<string,string>,controls:array<int,array<string,mixed>>}
	 */
	private static function extract_form_manifest_from_diagnostic( array $diagnostic ): array {
		$html = self::first_scalar( $diagnostic, array( 'source_html_preview', 'html_excerpt', 'excerpt' ) );
		if ( '' === $html || ! str_contains( strtolower( $html ), '<form' ) ) {
			return array(
				'form'     => array(),
				'controls' => array(),
			);
		}

		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$form_node = $doc->getElementsByTagName( 'form' )->item( 0 );
		if ( ! $form_node instanceof DOMElement ) {
			return array(
				'form'     => array(),
				'controls' => array(),
			);
		}

		$form = array();
		foreach ( array( 'class', 'action', 'method' ) as $attribute ) {
			$value = trim( $form_node->getAttribute( $attribute ) );
			if ( '' !== $value ) {
				$form[ $attribute ] = $value;
			}
		}

		$controls = array();
		foreach ( array( 'input', 'textarea', 'select', 'button' ) as $tag_name ) {
			foreach ( $form_node->getElementsByTagName( $tag_name ) as $control_node ) {
				if ( ! $control_node instanceof DOMElement ) {
					continue;
				}

				$control = array(
					'tag'  => strtolower( $control_node->tagName ),
					'type' => strtolower( trim( $control_node->getAttribute( 'type' ) ) ),
				);
				if ( 'button' === $control['tag'] && '' === $control['type'] ) {
					$control['type'] = 'submit';
				}
				if ( 'input' === $control['tag'] && '' === $control['type'] ) {
					$control['type'] = 'text';
				}

				foreach ( array( 'id', 'name', 'placeholder' ) as $attribute ) {
					$value = trim( $control_node->getAttribute( $attribute ) );
					if ( '' !== $value ) {
						$control[ $attribute ] = $value;
					}
				}

				$label = trim( $control_node->getAttribute( 'aria-label' ) );
				if ( '' === $label ) {
					$label = trim( $control_node->textContent );
				}
				if ( '' !== $label ) {
					$control['label'] = $label;
				}

				if ( $control_node->hasAttribute( 'required' ) ) {
					$control['required'] = true;
				}

				$controls[] = $control;
			}
		}

		return array(
			'form'     => $form,
			'controls' => $controls,
		);
	}

	/**
	 * Resolve which pending form finding a materialized row maps onto.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Report diagnostics.
	 * @param array<int,int>                 $pending     Pending diagnostic indexes.
	 * @param string                         $selector    Materialized form selector.
	 * @return int|null
	 */
	private static function form_finding_index_for_selector( array $diagnostics, array $pending, string $selector, string $source_path = '' ): ?int {
		if ( '' !== $selector && '' !== $source_path ) {
			foreach ( $pending as $index ) {
				$diagnostic = $diagnostics[ $index ] ?? array();
				$diagnostic_source = self::first_scalar( is_array( $diagnostic ) ? $diagnostic : array(), array( 'source_path', 'source' ) );
				if ( (string) ( $diagnostic['selector'] ?? '' ) === $selector && self::form_source_paths_match( $source_path, $diagnostic_source ) ) {
					return $index;
				}
			}
		}

		if ( '' !== $selector ) {
			foreach ( $pending as $index ) {
				$diagnostic = $diagnostics[ $index ] ?? array();
				if ( (string) ( $diagnostic['selector'] ?? '' ) === $selector ) {
					return $index;
				}
			}
		}

		return $pending[0] ?? null;
	}

	/**
	 * Stamp the runtime-mapped signal and mapped-block evidence onto a finding.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic to mark.
	 * @param array<string,mixed> $row        Materialized form row.
	 * @param string              $provider   Resolved form provider id.
	 * @return array<string,mixed>
	 */
	private static function mark_form_finding_mapped( array $diagnostic, array $row, string $provider ): array {
		$diagnostic['runtime_mapped']  = true;
		$diagnostic['runtime_carried'] = ! empty( $row['runtime_carried'] );
		$diagnostic['mapped_provider'] = isset( $row['provider'] ) && is_scalar( $row['provider'] ) && '' !== (string) $row['provider'] ? (string) $row['provider'] : $provider;
		$diagnostic['acceptability']   = 'acceptable_preservation';
		$diagnostic['block_name']      = isset( $row['block_name'] ) && is_scalar( $row['block_name'] ) ? (string) $row['block_name'] : 'jetpack/contact-form';

		if ( isset( $row['block_markup'] ) && is_scalar( $row['block_markup'] ) ) {
			$diagnostic['emitted_block_preview'] = self::diagnostic_excerpt( (string) $row['block_markup'] );
		}

		return $diagnostic;
	}

	/**
	 * Add the generated post_content key that corresponds to a source HTML form.
	 *
	 * Blocks Engine form findings are keyed to source documents such as
	 * `website/index.html`; generated theme content is keyed to post fields such as
	 * `posts/page-home.post_content`. The source document manifest is the contract
	 * that bridges those namespaces.
	 *
	 * @param array<string,mixed> $diagnostic Form finding.
	 * @param array<string,mixed> $report     Import report.
	 * @return array<string,mixed>
	 */
	private static function add_form_graft_source_path( array $diagnostic, array $report ): array {
		$source_path = self::first_scalar( $diagnostic, array( 'source_path', 'source' ) );
		if ( '' === $source_path ) {
			return $diagnostic;
		}

		$documents = $report['source_documents']['blocks_engine_documents'] ?? array();
		if ( ! is_array( $documents ) ) {
			return $diagnostic;
		}

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || (string) ( $document['source_path'] ?? '' ) !== $source_path ) {
				continue;
			}

			$slug = isset( $document['slug'] ) && is_scalar( $document['slug'] ) ? trim( (string) $document['slug'] ) : '';
			if ( '' !== $slug ) {
				$diagnostic['graft_source_path'] = 'posts/page-' . $slug . '.post_content';
			}
			return $diagnostic;
		}

		return $diagnostic;
	}

	/**
	 * Graft seeded contact-form markup into a page's post_content in place of the
	 * finding's readable fallback region.
	 *
	 * Anchors on the finding's own readable_blocks (serialized to the same
	 * comment-delimited block markup the transformer emitted into post_content),
	 * scoped to the finding's source page. Never matches visible copy. On a
	 * successful graft the finding is stamped `content_grafted`; when no anchor can
	 * be resolved the fallback is left untouched and a structured diagnostic is
	 * returned so the loss surfaces instead of being guessed away.
	 *
	 * @param array<string,mixed>  $finding       Mapped form-fallback finding.
	 * @param string               $block_markup  Seeded contact-form block markup.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename, mutated in place.
	 * @return array{grafted:bool, finding:array<string,mixed>, diagnostic:?array<string,mixed>}
	 */
	private static function graft_form_block_into_page_contents( array $finding, string $block_markup, array &$page_contents ): array {
		$source_path = self::first_scalar( $finding, array( 'graft_source_path', 'source_path', 'source' ) );
		$selector    = isset( $finding['selector'] ) && is_scalar( $finding['selector'] ) ? (string) $finding['selector'] : '';

		$unanchorable = static function ( string $reason ) use ( &$finding, $source_path, $selector ): array {
			$finding['content_grafted'] = false;
			return array(
				'grafted'    => false,
				'finding'    => $finding,
				'diagnostic' => array(
					'type'            => 'form_block_graft_unanchorable',
					'reason'          => $reason,
					'source_path'     => $source_path,
					'selector'        => $selector,
					'diagnostic_code' => 'html_form_fallback_graft_unanchorable',
					'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
					'message'         => 'Seeded contact-form markup could not be anchored to the readable fallback in post_content; the fallback was left in place.',
				),
			);
		};

		if ( '' === $block_markup ) {
			return $unanchorable( 'no_seeded_block_markup' );
		}

		$readable = isset( $finding['readable_blocks'] ) && is_array( $finding['readable_blocks'] ) ? $finding['readable_blocks'] : array();
		$region   = self::serialize_readable_form_blocks( $readable );
		if ( '' === $region ) {
			$core_html_graft = self::graft_form_block_into_core_html_fallback( $finding, $block_markup, $source_path, $page_contents );
			return $core_html_graft['grafted'] ? $core_html_graft : $unanchorable( 'no_readable_fallback_blocks' );
		}

		$key = self::form_fallback_page_content_key( $source_path, $region, $page_contents );
		if ( null === $key ) {
			$core_html_graft = self::graft_form_block_into_core_html_fallback( $finding, $block_markup, $source_path, $page_contents );
			return $core_html_graft['grafted'] ? $core_html_graft : $unanchorable( 'fallback_region_not_found_in_post_content' );
		}

		$content  = (string) ( $page_contents[ $key ] ?? '' );
		$position = strpos( $content, $region );
		if ( false === $position ) {
			return $unanchorable( 'fallback_region_not_found_in_post_content' );
		}

		// Replace only the first remaining occurrence of this finding's readable
		// region, so sibling forms with an identical fallback structure on the same
		// page each consume one occurrence as findings are processed in turn.
		$page_contents[ $key ]               = substr( $content, 0, $position ) . $block_markup . substr( $content, $position + strlen( $region ) );
		$finding['content_grafted']          = true;
		$finding['grafted_post_content_key'] = $key;

		return array(
			'grafted'    => true,
			'finding'    => $finding,
			'diagnostic' => null,
		);
	}

	/**
	 * Replace a serialized core/html form preservation block when readable blocks
	 * are absent or no longer match generated post_content.
	 *
	 * @param array<string,mixed>  $finding       Mapped form-fallback finding.
	 * @param string               $block_markup  Seeded contact-form block markup.
	 * @param string               $source_path   Resolved post_content path.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename.
	 * @return array{grafted:bool, finding:array<string,mixed>, diagnostic:?array<string,mixed>}
	 */
	private static function graft_form_block_into_core_html_fallback( array $finding, string $block_markup, string $source_path, array &$page_contents ): array {
		foreach ( self::form_fallback_page_content_keys_for_source( $source_path, $page_contents ) as $key ) {
			$content = (string) ( $page_contents[ $key ] ?? '' );
			foreach ( self::serialized_core_html_blocks( $content ) as $block ) {
				if ( ! self::core_html_form_matches_finding( $block['html'], $finding ) ) {
					continue;
				}

				$position = strpos( $content, $block['serialized'] );
				if ( false === $position ) {
					continue;
				}

				$page_contents[ $key ]               = substr( $content, 0, $position ) . $block_markup . substr( $content, $position + strlen( $block['serialized'] ) );
				$finding['content_grafted']          = true;
				$finding['grafted_post_content_key'] = $key;
				$finding['graft_anchor']             = 'core_html_form_block';

				return array(
					'grafted'    => true,
					'finding'    => $finding,
					'diagnostic' => null,
				);
			}
		}

		$finding['content_grafted'] = false;
		return array(
			'grafted'    => false,
			'finding'    => $finding,
			'diagnostic' => null,
		);
	}

	/**
	 * Return candidate page content keys for a resolved source path, preferring the
	 * matching page and falling back to the supplied order.
	 *
	 * @param string               $source_path   Resolved post_content path.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename.
	 * @return array<int,string>
	 */
	private static function form_fallback_page_content_keys_for_source( string $source_path, array $page_contents ): array {
		$keys = array();
		if ( '' !== $source_path ) {
			foreach ( $page_contents as $key => $content ) {
				if ( self::form_source_paths_match( $source_path, (string) $key ) ) {
					$keys[] = (string) $key;
				}
			}
		}

		foreach ( array_keys( $page_contents ) as $key ) {
			if ( ! in_array( (string) $key, $keys, true ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Extract serialized core/html blocks and their decoded HTML payloads.
	 *
	 * @param string $content Serialized block document.
	 * @return array<int,array{serialized:string,html:string}>
	 */
	private static function serialized_core_html_blocks( string $content ): array {
		$blocks  = array();
		$matches = array();
		preg_match_all( '/<!--\s+wp:html\s+(\{.*?\})\s+\/-->/s', $content, $matches, PREG_SET_ORDER );
		preg_match_all( '/<!--\s+wp:html\s+(\{.*?\})\s+-->(.*?)<!--\s+\/wp:html\s+-->/s', $content, $wrapped_matches, PREG_SET_ORDER );
		$matches = array_merge( $matches, $wrapped_matches );

		foreach ( $matches as $match ) {
			$attrs = json_decode( (string) $match[1], true );
			$html  = is_array( $attrs ) && isset( $attrs['content'] ) && is_scalar( $attrs['content'] ) ? (string) $attrs['content'] : ( isset( $match[2] ) ? (string) $match[2] : '' );
			if ( '' !== $html ) {
				$blocks[] = array(
					'serialized' => (string) $match[0],
					'html'       => $html,
				);
			}
		}

		return $blocks;
	}

	/**
	 * Determine whether a decoded core/html form payload matches a form finding.
	 *
	 * @param string              $html    Decoded core/html payload.
	 * @param array<string,mixed> $finding Form finding.
	 * @return bool
	 */
	private static function core_html_form_matches_finding( string $html, array $finding ): bool {
		if ( ! str_contains( strtolower( $html ), '<form' ) ) {
			return false;
		}

		$form = isset( $finding['form'] ) && is_array( $finding['form'] ) ? $finding['form'] : array();
		foreach ( array( 'class', 'action', 'method' ) as $field ) {
			$value = isset( $form[ $field ] ) && is_scalar( $form[ $field ] ) ? trim( (string) $form[ $field ] ) : '';
			if ( '' !== $value && ! str_contains( $html, $value ) ) {
				return false;
			}
		}

		$controls = isset( $finding['controls'] ) && is_array( $finding['controls'] ) ? $finding['controls'] : array();
		foreach ( $controls as $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			foreach ( array( 'id', 'name' ) as $field ) {
				$value = isset( $control[ $field ] ) && is_scalar( $control[ $field ] ) ? trim( (string) $control[ $field ] ) : '';
				if ( '' !== $value && ! str_contains( $html, $value ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Resolve the page content key that owns a form finding's fallback region.
	 *
	 * Prefers the finding's own source page; only falls back to scanning other
	 * provided pages when the finding carries no resolvable source path.
	 *
	 * @param string               $source_path   Finding source path.
	 * @param string               $region        Serialized readable fallback region.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename.
	 * @return string|null
	 */
	private static function form_fallback_page_content_key( string $source_path, string $region, array $page_contents ): ?string {
		if ( '' !== $source_path ) {
			foreach ( $page_contents as $key => $content ) {
				if ( self::form_source_paths_match( $source_path, (string) $key ) && str_contains( (string) $content, $region ) ) {
					return (string) $key;
				}
			}
		}

		foreach ( $page_contents as $key => $content ) {
			if ( str_contains( (string) $content, $region ) ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * Compare a finding source path against a page content key.
	 *
	 * @param string $source_path Finding source path.
	 * @param string $key         Page content key.
	 * @return bool
	 */
	private static function form_source_paths_match( string $source_path, string $key ): bool {
		if ( $source_path === $key ) {
			return true;
		}

		$normalized_source = self::normalize_form_source_path( $source_path );
		$normalized_key    = self::normalize_form_source_path( $key );
		if ( '' !== $normalized_source && $normalized_source === $normalized_key ) {
			return true;
		}

		$source_base = basename( $source_path );
		return '' !== $source_base && basename( $key ) === $source_base;
	}

	/**
	 * Normalize a source path for form-fallback page matching.
	 *
	 * @param string $path Source path.
	 * @return string
	 */
	private static function normalize_form_source_path( string $path ): string {
		$path = trim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path ) {
			return '';
		}
		if ( str_starts_with( $path, 'website/' ) ) {
			$path = substr( $path, strlen( 'website/' ) );
		}
		return trim( $path, '/' );
	}

	/**
	 * Serialize a transformer-style readable_blocks tree to comment-delimited
	 * block markup.
	 *
	 * Mirrors Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime block
	 * serialization so the result matches the exact fallback region the
	 * transformer emitted into serialized_blocks (and thus into post_content).
	 *
	 * @param array<int,array<string,mixed>> $readable_blocks Readable fallback block tree.
	 * @return string
	 */
	private static function serialize_readable_form_blocks( array $readable_blocks ): string {
		$serialized = '';
		foreach ( array_values( $readable_blocks ) as $block ) {
			$serialized .= self::serialize_readable_form_block( $block );
		}
		return $serialized;
	}

	/**
	 * Serialize one transformer-style block node to comment-delimited markup.
	 *
	 * @param array<string,mixed> $block Block node (blockName/attrs/innerBlocks/innerHTML/innerContent).
	 * @return string
	 */
	private static function serialize_readable_form_block( array $block ): string {
		$content = self::serialize_readable_form_inner_content( $block );
		$name    = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		if ( '' === $name ) {
			return $content;
		}

		$name  = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$attrs = self::serialize_readable_form_block_attributes( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array() );
		if ( '' === $content ) {
			return '<!-- wp:' . $name . $attrs . ' /-->';
		}

		return '<!-- wp:' . $name . $attrs . ' -->' . $content . '<!-- /wp:' . $name . ' -->';
	}

	/**
	 * Serialize a transformer-style block's inner content.
	 *
	 * @param array<string,mixed> $block Block node.
	 * @return string
	 */
	private static function serialize_readable_form_inner_content( array $block ): string {
		$inner_blocks  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( array_filter( $block['innerBlocks'], 'is_array' ) ) : array();
		$inner_content = $block['innerContent'] ?? null;

		if ( ! is_array( $inner_content ) ) {
			$serialized = '';
			foreach ( $inner_blocks as $inner_block ) {
				$serialized .= self::serialize_readable_form_block( $inner_block );
			}
			return $serialized . ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '' );
		}

		$serialized = '';
		$index      = 0;
		foreach ( $inner_content as $part ) {
			if ( null === $part ) {
				$inner_block = $inner_blocks[ $index ] ?? null;
				$serialized .= is_array( $inner_block ) ? self::serialize_readable_form_block( $inner_block ) : '';
				++$index;
				continue;
			}
			$serialized .= (string) $part;
		}

		return $serialized;
	}

	/**
	 * Serialize block attributes for a comment delimiter, matching WordPress core.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return string
	 */
	private static function serialize_readable_form_block_attributes( array $attrs ): string {
		if ( array() === $attrs ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback only for non-WordPress smoke tests; WordPress runtimes use wp_json_encode().
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			return '';
		}

		$encoded = preg_replace( '/--/', '\\u002d\\u002d', $encoded ) ?? $encoded;
		$encoded = preg_replace( '/</', '\\u003c', $encoded ) ?? $encoded;
		$encoded = preg_replace( '/>/', '\\u003e', $encoded ) ?? $encoded;
		$encoded = preg_replace( '/&/', '\\u0026', $encoded ) ?? $encoded;
		$encoded = preg_replace( '/\\\\"/', '\\u0022', $encoded ) ?? $encoded;

		return ' ' . $encoded;
	}

	/**
	 * Materialize detected product-grid fallbacks through the configured shop provider.
	 *
	 * Collects every `html_product_grid_fallback` finding, normalizes each detected
	 * product into a `products-manifest/v1` row (deriving a slug, normalizing the
	 * currency price text into a decimal string, and forwarding description, image,
	 * and source selectors), runs the rows through the shop adapter's manifest
	 * validator + seeder, and stamps the runtime-mapped / acceptable-preservation
	 * signal onto each finding whose products were actually seeded. Findings whose
	 * products could not be seeded (for example because WooCommerce is unavailable)
	 * keep no signal and stay an unacceptable parity loss, which lets the existing
	 * commerce dependency gate report the missing runtime.
	 *
	 * @param array<string,mixed>  $report        Import report (mutated in place).
	 * @param array<string,mixed>  $args          Import args.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename, mutated in place.
	 * @return array<string,mixed> The recorded product_finding_seeding report.
	 */
	public static function materialize_product_findings( array &$report, array $args = array(), array &$page_contents = array() ): array {
		$adapter = Static_Site_Importer_Entity_Materializer_Registry::product_adapter();

		$diagnostics = isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array();
		$indexes     = self::product_grid_finding_indexes( $diagnostics );

		if ( empty( $indexes ) ) {
			$seeding                           = Static_Site_Importer_Entity_Materializer_Registry::new_entity_report( $adapter );
			$seeding['status']                 = 'skipped';
			$seeding['reason']                 = 'no_product_findings';
			$report['product_finding_seeding'] = $seeding;
			return $seeding;
		}

		$manifest_products = array();
		$finding_slugs     = array();
		foreach ( $indexes as $index ) {
			$diagnostic = $report['diagnostics'][ $index ];
			$products   = isset( $diagnostic['products'] ) && is_array( $diagnostic['products'] ) ? $diagnostic['products'] : array();
			$container  = isset( $diagnostic['container_selector'] ) && is_scalar( $diagnostic['container_selector'] )
				? (string) $diagnostic['container_selector']
				: ( isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '' );

			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}

				$row = self::product_finding_manifest_row( $product, $container );
				if ( null === $row ) {
					continue;
				}

				$manifest_products[]       = $row;
				$finding_slugs[ $index ][] = $row['slug'];
			}
		}

		$manifest   = array(
			'schema_version' => 1,
			'products'       => $manifest_products,
		);
		$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_manifest( $adapter, $manifest );
		$validated  = $validation['products'];

		$seeding = Static_Site_Importer_Entity_Materializer_Registry::materialize( $adapter, array( 'products' => $validated ) );

		$seeding['provider']      = Static_Site_Importer_Entity_Materializer_Registry::provider_for( 'shop' );
		$seeding['finding_count'] = count( $indexes );
		$seeding['product_count'] = count( $manifest_products );
		$seeding['mapped_count']  = 0;
		$seeding['waived']        = ! empty( $args[ (string) ( $adapter['waiver_arg'] ?? 'allow_missing_woocommerce' ) ] );
		$seeding['manifest']      = $manifest;
		if ( ! empty( $validation['errors'] ) ) {
			$seeding['validation_errors'] = $validation['errors'];
		}

		$seeded_products_by_slug = array();
		foreach ( ( isset( $seeding['products'] ) && is_array( $seeding['products'] ) ? $seeding['products'] : array() ) as $product_row ) {
			if ( ! is_array( $product_row ) ) {
				continue;
			}
			if ( in_array( (string) ( $product_row['status'] ?? '' ), array( 'created', 'updated' ), true ) ) {
				$slug = (string) ( $product_row['slug'] ?? '' );
				if ( '' !== $slug ) {
					$seeded_products_by_slug[ $slug ] = $product_row;
				}
			}
		}

		$seeding['shortcode_grafted_count'] = 0;

		foreach ( $indexes as $index ) {
			$slugs = $finding_slugs[ $index ] ?? array();
			foreach ( $slugs as $slug ) {
				if ( isset( $seeded_products_by_slug[ $slug ] ) ) {
					++$seeding['mapped_count'];
					$report['diagnostics'][ $index ] = self::mark_product_finding_mapped( $report['diagnostics'][ $index ], $seeding['provider'] );
					break;
				}
			}

			if ( ! empty( $page_contents ) ) {
				$graft = self::graft_product_add_to_cart_shortcodes_into_page_contents( $report['diagnostics'][ $index ], $seeded_products_by_slug, $page_contents );
				$report['diagnostics'][ $index ] = $graft['finding'];
				if ( $graft['grafted'] ) {
					++$seeding['shortcode_grafted_count'];
				}
				if ( is_array( $graft['diagnostic'] ) ) {
					$report['diagnostics'][] = $graft['diagnostic'];
				}
			}
		}

		$report['product_finding_seeding'] = $seeding;
		return $seeding;
	}

	/**
	 * Replace plain static product-card add-to-cart buttons with Woo shortcode blocks.
	 *
	 * This prototype only rewrites serialized Gutenberg button fallbacks. Raw HTML
	 * runtime controls are left in place so SSI never embeds block markup inside an
	 * arbitrary HTML island or fakes cart state.
	 *
	 * @param array<string,mixed>        $finding                 Product-grid finding.
	 * @param array<string,array<mixed>> $seeded_products_by_slug Seeded Woo rows keyed by slug.
	 * @param array<string,string>       $page_contents           Materialized page post_content keyed by source filename.
	 * @return array{grafted:bool,finding:array<string,mixed>,diagnostic:?array<string,mixed>}
	 */
	private static function graft_product_add_to_cart_shortcodes_into_page_contents( array $finding, array $seeded_products_by_slug, array &$page_contents ): array {
		$source_path = self::first_scalar( $finding, array( 'graft_source_path', 'source_path', 'source' ) );
		$selector    = isset( $finding['selector'] ) && is_scalar( $finding['selector'] ) ? (string) $finding['selector'] : '';

		$unanchorable = static function ( string $reason ) use ( &$finding, $source_path, $selector ): array {
			$finding['product_shortcode_grafted'] = false;
			return array(
				'grafted'    => false,
				'finding'    => $finding,
				'diagnostic' => array(
					'type'            => 'product_add_to_cart_graft_unanchorable',
					'reason'          => $reason,
					'source_path'     => $source_path,
					'selector'        => $selector,
					'diagnostic_code' => 'html_product_add_to_cart_graft_unanchorable',
					'loss_class'      => Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND,
					'message'         => 'Woo add-to-cart shortcode markup could not be safely anchored to plain serialized button controls; the static controls were left in place.',
				),
			);
		};

		$products = self::shortcode_graft_products( $finding, $seeded_products_by_slug );
		if ( empty( $products ) ) {
			return $unanchorable( 'no_safe_plain_add_to_cart_products' );
		}

		$readable = isset( $finding['readable_blocks'] ) && is_array( $finding['readable_blocks'] ) ? $finding['readable_blocks'] : array();
		$region   = self::serialize_readable_form_blocks( $readable );
		if ( '' === $region ) {
			return $unanchorable( 'no_readable_fallback_blocks' );
		}

		$key = self::form_fallback_page_content_key( $source_path, $region, $page_contents );
		if ( null === $key ) {
			return $unanchorable( 'fallback_region_not_found_in_post_content' );
		}

		$shortcoded_region = self::replace_plain_cart_button_blocks_with_shortcodes( $region, $products );
		if ( null === $shortcoded_region ) {
			return $unanchorable( 'plain_serialized_button_controls_not_matched' );
		}

		$content  = (string) ( $page_contents[ $key ] ?? '' );
		$position = strpos( $content, $region );
		if ( false === $position ) {
			return $unanchorable( 'fallback_region_not_found_in_post_content' );
		}

		$page_contents[ $key ]                    = substr( $content, 0, $position ) . $shortcoded_region . substr( $content, $position + strlen( $region ) );
		$finding['product_shortcode_grafted']     = true;
		$finding['grafted_post_content_key']      = $key;
		$finding['grafted_add_to_cart_shortcode'] = true;

		return array(
			'grafted'    => true,
			'finding'    => $finding,
			'diagnostic' => null,
		);
	}

	/**
	 * Resolve products eligible for plain add-to-cart shortcode grafting.
	 *
	 * @param array<string,mixed>        $finding                 Product-grid finding.
	 * @param array<string,array<mixed>> $seeded_products_by_slug Seeded Woo rows keyed by slug.
	 * @return array<int,array{id:int,slug:string}>
	 */
	private static function shortcode_graft_products( array $finding, array $seeded_products_by_slug ): array {
		$products = isset( $finding['products'] ) && is_array( $finding['products'] ) ? $finding['products'] : array();
		$eligible = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) || ! self::is_plain_add_to_cart_product( $product ) ) {
				return array();
			}

			$row  = self::product_finding_manifest_row( $product, '' );
			$slug = is_array( $row ) ? (string) ( $row['slug'] ?? '' ) : '';
			$id   = isset( $seeded_products_by_slug[ $slug ]['id'] ) ? (int) $seeded_products_by_slug[ $slug ]['id'] : 0;
			if ( '' === $slug || $id <= 0 ) {
				return array();
			}

			$eligible[] = array(
				'id'   => $id,
				'slug' => $slug,
			);
		}

		return $eligible;
	}

	/**
	 * Determine whether a detected product card has only a plain add-to-cart action.
	 *
	 * @param array<string,mixed> $product Detected product data.
	 * @return bool
	 */
	private static function is_plain_add_to_cart_product( array $product ): bool {
		if ( true !== ( $product['has_cart_control'] ?? false ) ) {
			return false;
		}

		foreach ( array( 'has_quantity_control', 'has_option_control', 'has_variant_control', 'has_custom_state', 'requires_options' ) as $flag ) {
			if ( true === ( $product[ $flag ] ?? false ) ) {
				return false;
			}
		}

		foreach ( array( 'quantity', 'options', 'variants', 'attributes', 'custom_state', 'form_controls' ) as $field ) {
			if ( ! empty( $product[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Replace one serialized core/buttons add-to-cart control per product.
	 *
	 * @param string                    $region   Serialized fallback block region.
	 * @param array<int,array{id:int}>  $products Eligible seeded products in card order.
	 * @return string|null
	 */
	private static function replace_plain_cart_button_blocks_with_shortcodes( string $region, array $products ): ?string {
		$pattern = '/<!--\s+wp:buttons\b.*?-->(?:(?!<!--\s+\/wp:buttons\s+-->).)*<!--\s+\/wp:buttons\s+-->/is';
		$matches = array();
		preg_match_all( $pattern, $region, $matches, PREG_OFFSET_CAPTURE );

		$cart_controls = array_values(
			array_filter(
				$matches[0] ?? array(),
				static fn( array $control_match ): bool => 1 === preg_match( '/\b(?:add\s+to\s+cart|buy\s+now|purchase|order\s+now)\b/i', wp_strip_all_tags( (string) $control_match[0] ) )
			)
		);

		if ( count( $cart_controls ) !== count( $products ) ) {
			return null;
		}

		$rewritten = '';
		$cursor    = 0;
		foreach ( $cart_controls as $index => $control_match ) {
			$block    = (string) $control_match[0];
			$position = (int) $control_match[1];
			$rewritten .= substr( $region, $cursor, $position - $cursor );
			$rewritten .= self::add_to_cart_shortcode_block( (int) $products[ $index ]['id'] );
			$cursor     = $position + strlen( $block );
		}

		return $rewritten . substr( $region, $cursor );
	}

	/**
	 * Build a Woo-owned add-to-cart shortcode block for a seeded product.
	 *
	 * @param int $product_id WooCommerce product post ID.
	 * @return string
	 */
	private static function add_to_cart_shortcode_block( int $product_id ): string {
		return '<!-- wp:shortcode -->[add_to_cart id="' . $product_id . '"]<!-- /wp:shortcode -->';
	}

	/**
	 * Return diagnostic indexes for every detected product-grid fallback finding.
	 *
	 * @param array<int,mixed> $diagnostics Report diagnostics.
	 * @return array<int,int>
	 */
	public static function product_grid_finding_indexes( array $diagnostics ): array {
		$indexes = array();
		foreach ( $diagnostics as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$code = (string) ( $diagnostic['diagnostic_code'] ?? '' );
			if ( '' === $code ) {
				$code = (string) ( $diagnostic['kind'] ?? '' );
			}

			if ( 'html_product_grid_fallback' === $code ) {
				$indexes[] = (int) $index;
			}
		}

		return $indexes;
	}

	/**
	 * Normalize one detected product into a products-manifest/v1 row.
	 *
	 * @param array<string,mixed> $product           Detected product entry.
	 * @param string              $container_selector Owning grid container selector.
	 * @return array<string,mixed>|null
	 */
	private static function product_finding_manifest_row( array $product, string $container_selector ): ?array {
		$name = isset( $product['name'] ) && is_scalar( $product['name'] ) ? trim( (string) $product['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		$slug_source = isset( $product['slug'] ) && is_scalar( $product['slug'] ) && '' !== trim( (string) $product['slug'] )
			? (string) $product['slug']
			: $name;
		$slug        = self::product_slug( $slug_source );
		if ( '' === $slug ) {
			return null;
		}

		$row = array(
			'name'          => $name,
			'slug'          => $slug,
			'regular_price' => self::normalize_product_price( isset( $product['price'] ) && is_scalar( $product['price'] ) ? (string) $product['price'] : '' ),
		);

		$sale_price = self::normalize_product_price( isset( $product['sale_price'] ) && is_scalar( $product['sale_price'] ) ? (string) $product['sale_price'] : '' );
		if ( '' !== $sale_price ) {
			$row['sale_price'] = $sale_price;
		}

		if ( isset( $product['description'] ) && is_scalar( $product['description'] ) && '' !== trim( (string) $product['description'] ) ) {
			$row['description'] = (string) $product['description'];
		}

		$image = self::product_image_src( $product['image'] ?? null );
		if ( '' !== $image ) {
			$row['image'] = $image;
		}

		$selectors = array();
		if ( isset( $product['source_selector'] ) && is_scalar( $product['source_selector'] ) && '' !== trim( (string) $product['source_selector'] ) ) {
			$selectors[] = trim( (string) $product['source_selector'] );
		}
		if ( '' !== $container_selector ) {
			$selectors[] = $container_selector;
		}
		$selectors = array_values( array_unique( $selectors ) );
		if ( ! empty( $selectors ) ) {
			$row['source_selectors'] = $selectors;
		}

		return $row;
	}

	/**
	 * Derive a lowercase URL slug for a product.
	 *
	 * @param string $text Slug source text.
	 * @return string
	 */
	private static function product_slug( string $text ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $text );
		}

		$slug = strtolower( trim( $text ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( is_string( $slug ) ? $slug : '', '-' );
	}

	/**
	 * Resolve the product image source from a string or {src, alt} object.
	 *
	 * @param mixed $image Detected product image.
	 * @return string
	 */
	private static function product_image_src( mixed $image ): string {
		if ( is_string( $image ) ) {
			return trim( $image );
		}

		if ( is_array( $image ) ) {
			$src = $image['src'] ?? '';
			return is_scalar( $src ) ? trim( (string) $src ) : '';
		}

		return '';
	}

	/**
	 * Normalize a human-readable currency price into a decimal manifest string.
	 *
	 * Generic and locale-tolerant: strips currency symbols, whitespace, and other
	 * non-numeric characters, then resolves the decimal separator from the digit
	 * grouping itself rather than any site or locale setting. Handles US grouping
	 * ("$1,299.00" => "1299.00"), European grouping ("1.299,00 €" => "1299.00"),
	 * symbol-only integers ("$24" => "24", "€18" => "18"), and bare decimals
	 * ("18.00" => "18.00"). The fractional part is normalized to exactly two
	 * decimals; integers stay integers so the manifest validator accepts both.
	 *
	 * @param string $price Raw price text.
	 * @return string Decimal price string, or '' when no digits are present.
	 */
	public static function normalize_product_price( string $price ): string {
		$clean = preg_replace( '/[^0-9.,]/', '', trim( $price ) );
		$clean = is_string( $clean ) ? $clean : '';
		if ( '' === $clean ) {
			return '';
		}

		$comma_count = substr_count( $clean, ',' );
		$dot_count   = substr_count( $clean, '.' );

		$decimal_sep = '';
		if ( 0 < $comma_count && 0 < $dot_count ) {
			// When both separators appear, the rightmost one is the decimal point.
			$comma_position = strrpos( $clean, ',' );
			$dot_position   = strrpos( $clean, '.' );
			$decimal_sep    = false !== $comma_position && false !== $dot_position && $comma_position > $dot_position ? ',' : '.';
		} elseif ( 1 === $comma_count ) {
			$decimal_sep = self::is_decimal_tail( $clean, ',' ) ? ',' : '';
		} elseif ( 1 === $dot_count ) {
			$decimal_sep = self::is_decimal_tail( $clean, '.' ) ? '.' : '';
		}
		// Repeated single separators (e.g. "1,234,567") are digit grouping only.

		if ( '' !== $decimal_sep ) {
			$parts    = explode( $decimal_sep, $clean );
			$fraction = (string) array_pop( $parts );
			$integer  = (string) preg_replace( '/[^0-9]/', '', implode( '', $parts ) );
			$fraction = (string) preg_replace( '/[^0-9]/', '', $fraction );
		} else {
			$integer  = (string) preg_replace( '/[^0-9]/', '', $clean );
			$fraction = '';
		}

		$integer = ltrim( $integer, '0' );
		if ( '' === $integer ) {
			$integer = '0';
		}

		if ( '' === $fraction ) {
			return $integer;
		}

		if ( strlen( $fraction ) > 2 ) {
			return number_format( (float) ( $integer . '.' . $fraction ), 2, '.', '' );
		}

		return $integer . '.' . str_pad( $fraction, 2, '0' );
	}

	/**
	 * Decide whether a single separator's trailing digits read as a decimal part.
	 *
	 * @param string $clean Digit-and-separator string.
	 * @param string $sep   Candidate decimal separator.
	 * @return bool
	 */
	private static function is_decimal_tail( string $clean, string $sep ): bool {
		$pos = strrpos( $clean, $sep );
		if ( false === $pos ) {
			return false;
		}

		$length = strlen( substr( $clean, $pos + 1 ) );
		return $length >= 1 && $length <= 2;
	}

	/**
	 * Stamp the runtime-mapped / acceptable-preservation signal onto a product finding.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic to mark.
	 * @param string              $provider   Resolved shop provider id.
	 * @return array<string,mixed>
	 */
	private static function mark_product_finding_mapped( array $diagnostic, string $provider ): array {
		$diagnostic['runtime_mapped']  = true;
		$diagnostic['mapped_provider'] = '' !== $provider ? $provider : 'woocommerce';
		$diagnostic['acceptability']   = 'acceptable_preservation';
		$diagnostic['block_name']      = isset( $diagnostic['block_name'] ) && is_scalar( $diagnostic['block_name'] ) && '' !== (string) $diagnostic['block_name']
			? (string) $diagnostic['block_name']
			: 'woocommerce/product-collection';

		return $diagnostic;
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
		$assets         = isset( $site['assets'] ) && is_array( $site['assets'] ) ? $site['assets'] : array();

		$payload = array(
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

		if ( ! empty( $assets ) ) {
			$payload['assets'] = self::materialization_plan_assets_report_payload( $assets );
		}

		return $payload;
	}

	/**
	 * Preserve compact materialization-plan asset metadata for downstream diagnostics.
	 *
	 * @param array<int,mixed> $assets Materialization-plan assets.
	 * @return array<int,array<string,mixed>>
	 */
	private static function materialization_plan_assets_report_payload( array $assets ): array {
		$rows = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$row = array();
			foreach ( array( 'source', 'path', 'target_path', 'kind', 'role', 'intent', 'media_type', 'mime_type', 'placement', 'source_path', 'selector', 'hash' ) as $field ) {
				if ( isset( $asset[ $field ] ) && is_scalar( $asset[ $field ] ) && '' !== trim( (string) $asset[ $field ] ) ) {
					$row[ $field ] = (string) $asset[ $field ];
				}
			}
			$payload = self::materialization_plan_asset_payload( $asset );
			if ( null !== $payload ) {
				$row['payload_present'] = true;
				$row['payload_sha256']  = hash( 'sha256', $payload );
				$row['payload_bytes']   = strlen( $payload );
			} else {
				$row['payload_present'] = false;
			}
			foreach ( array( 'defer', 'async' ) as $field ) {
				if ( array_key_exists( $field, $asset ) ) {
					$row[ $field ] = (bool) $asset[ $field ];
				}
			}
			if ( isset( $asset['bytes'] ) && is_numeric( $asset['bytes'] ) ) {
				$row['bytes'] = (int) $asset['bytes'];
			}

			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Return a materialization asset payload without retaining it in diagnostics.
	 *
	 * @param array<string,mixed> $asset Materialization-plan asset.
	 * @return string|null
	 */
	private static function materialization_plan_asset_payload( array $asset ): ?string {
		if ( isset( $asset['content_base64'] ) && is_scalar( $asset['content_base64'] ) ) {
			$payload = base64_decode( (string) $asset['content_base64'], true );
			if ( false !== $payload ) {
				return $payload;
			}
		}
		if ( isset( $asset['content'] ) && is_scalar( $asset['content'] ) ) {
			return (string) $asset['content'];
		}
		return null;
	}

	/**
	 * Read the effective transformer package identity from Composer's installed metadata.
	 *
	 * @return array<string,string>
	 */
	private static function transformer_package_provenance(): array {
		$package = 'automattic/blocks-engine-php-transformer';
		if ( ! class_exists( '\Composer\InstalledVersions' ) || ! \Composer\InstalledVersions::isInstalled( $package ) ) {
			return array( 'package' => $package );
		}

		$provenance = array(
			'package' => $package,
			'version' => (string) \Composer\InstalledVersions::getPrettyVersion( $package ),
		);
		if ( method_exists( '\\Composer\\InstalledVersions', 'getReference' ) ) {
			$reference = \Composer\InstalledVersions::getReference( $package );
			if ( is_string( $reference ) && '' !== $reference ) {
				$provenance['reference'] = $reference;
			}
		}
		return $provenance;
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
	 * Preserve optional Blocks Engine conversion-report fields for import-report consumers.
	 *
	 * @param array<string,mixed> $conversion_report Native conversion report.
	 * @return array<string,mixed>
	 */
	private static function conversion_report_payload( array $conversion_report ): array {
		$diagnostics            = self::array_values_if_list( $conversion_report['diagnostics'] ?? array() );
		$fallbacks              = self::conversion_report_fallback_rows( $conversion_report );
		$interaction_candidates = self::array_values_if_list( $conversion_report['interaction_candidates'] ?? array() );

		$payload = array(
			'schema'                      => isset( $conversion_report['schema'] ) && is_scalar( $conversion_report['schema'] ) ? (string) $conversion_report['schema'] : 'blocks-engine/php-transformer/conversion-report/v1',
			'status'                      => isset( $conversion_report['status'] ) && is_scalar( $conversion_report['status'] ) ? (string) $conversion_report['status'] : '',
			'diagnostic_count'            => count( $diagnostics ),
			'fallback_count'              => count( $fallbacks ),
			'interaction_candidate_count' => count( $interaction_candidates ),
		);

		foreach ( array( 'asset_reference_count', 'presentation_gap_count' ) as $count_field ) {
			if ( isset( $conversion_report[ $count_field ] ) && is_numeric( $conversion_report[ $count_field ] ) ) {
				$payload[ $count_field ] = (int) $conversion_report[ $count_field ];
			}
		}

		foreach ( array( 'source_selector_summaries', 'block_type_counts', 'asset_references', 'presentation_gaps', 'page_metrics' ) as $field ) {
			if ( isset( $conversion_report[ $field ] ) && is_array( $conversion_report[ $field ] ) ) {
				$payload[ $field ] = self::compact_native_report_value( $conversion_report[ $field ] );
			}
		}

		if ( ! empty( $diagnostics ) ) {
			$payload['diagnostics'] = self::compact_native_report_rows( $diagnostics );
		}
		if ( ! empty( $fallbacks ) ) {
			$payload['fallbacks']            = self::compact_native_report_rows( $fallbacks );
			$payload['fallback_diagnostics'] = self::compact_native_report_rows( $fallbacks );
		}
		if ( ! empty( $interaction_candidates ) ) {
			$payload['interaction_candidates'] = self::compact_native_report_rows( $interaction_candidates );
		}

		return $payload;
	}

	/**
	 * Locate an optional Blocks Engine semantic parity report in known result slots.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed>
	 */
	private static function blocks_engine_semantic_parity_report( array $compiled ): array {
		$candidates = array(
			$compiled['semantic_parity'] ?? null,
			$compiled['semantic_parity_report'] ?? null,
			$compiled['reports']['semantic_parity'] ?? null,
			$compiled['artifacts']['semantic_parity'] ?? null,
			$compiled['artifacts']['semantic_parity_report'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate ) ) {
				return $candidate;
			}
		}

		return array();
	}

	/**
	 * Preserve the semantic parity report in a compact, stable SSI report key.
	 *
	 * @param array<string,mixed> $semantic_parity Native Blocks Engine semantic parity report.
	 * @return array<string,mixed>
	 */
	private static function semantic_parity_report_payload( array $semantic_parity ): array {
		$findings = self::semantic_parity_findings( $semantic_parity );
		$summary  = isset( $semantic_parity['summary'] ) && is_array( $semantic_parity['summary'] ) ? $semantic_parity['summary'] : array();

		return array_filter(
			array(
				'schema'        => isset( $semantic_parity['schema'] ) && is_scalar( $semantic_parity['schema'] ) ? (string) $semantic_parity['schema'] : Static_Site_Importer_Product_Handoff_Contract::BLOCKS_ENGINE_SEMANTIC_PARITY_SCHEMA,
				'status'        => isset( $semantic_parity['status'] ) && is_scalar( $semantic_parity['status'] ) ? (string) $semantic_parity['status'] : ( empty( $findings ) ? 'passed' : 'reported' ),
				'finding_count' => count( $findings ),
				'summary'       => self::compact_native_report_value( $summary ),
				'findings'      => self::compact_native_report_rows( $findings ),
				'counts'        => isset( $semantic_parity['counts'] ) && is_array( $semantic_parity['counts'] ) ? self::compact_native_report_value( $semantic_parity['counts'] ) : array(),
				'coverage'      => isset( $semantic_parity['coverage'] ) && is_array( $semantic_parity['coverage'] ) ? self::compact_native_report_value( $semantic_parity['coverage'] ) : array(),
			),
			static fn ( mixed $value ): bool => array() !== $value
		);
	}

	/**
	 * Reflect semantic parity findings in SSI diagnostics and quality gates.
	 *
	 * @param array<string,mixed> $report          Import report.
	 * @param array<string,mixed> $semantic_parity Native Blocks Engine semantic parity report.
	 * @return void
	 */
	private static function record_semantic_parity_quality_metadata( array &$report, array $semantic_parity ): void {
		$findings = self::semantic_parity_findings( $semantic_parity );
		$report['quality']['semantic_parity_failure_count'] = (int) ( $report['quality']['semantic_parity_failure_count'] ?? 0 ) + count( $findings );

		$report['semantic_fidelity'] = array_merge(
			isset( $report['semantic_fidelity'] ) && is_array( $report['semantic_fidelity'] ) ? $report['semantic_fidelity'] : array(),
			array(
				'status'        => empty( $findings ) ? 'passed' : 'reported',
				'gate_owner'    => 'blocks-engine/php-transformer',
				'finding_count' => count( $findings ),
				'summary'       => self::semantic_parity_summary_counts( $findings ),
			)
		);

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}

			$diagnostic = self::diagnostic_from_semantic_parity_finding( $finding );
			if ( ! empty( $diagnostic ) ) {
				$report['diagnostics'][] = $diagnostic;
			}
		}
	}

	/**
	 * Return canonical semantic parity findings from current and expected report keys.
	 *
	 * @param array<string,mixed> $semantic_parity Native Blocks Engine semantic parity report.
	 * @return array<int,mixed>
	 */
	private static function semantic_parity_findings( array $semantic_parity ): array {
		foreach ( array( 'findings', 'failures', 'mismatches', 'diagnostics' ) as $key ) {
			$rows = self::array_values_if_list( $semantic_parity[ $key ] ?? array() );
			if ( ! empty( $rows ) ) {
				return $rows;
			}
		}

		return array();
	}

	/**
	 * Build summary counts for semantic parity findings.
	 *
	 * @param array<int,mixed> $findings Semantic parity findings.
	 * @return array<string,int>
	 */
	private static function semantic_parity_summary_counts( array $findings ): array {
		$summary = array(
			'total'      => 0,
			'navigation' => 0,
			'landmark'   => 0,
		);

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}

			++$summary['total'];
			$type = self::semantic_parity_diagnostic_type( $finding );
			if ( str_contains( $type, 'navigation' ) ) {
				++$summary['navigation'];
			}
			if ( str_contains( $type, 'landmark' ) ) {
				++$summary['landmark'];
			}
		}

		return $summary;
	}

	/**
	 * Normalize a semantic parity finding into SSI's diagnostic shape.
	 *
	 * @param array<string,mixed> $finding Native semantic parity finding.
	 * @return array<string,mixed>
	 */
	private static function diagnostic_from_semantic_parity_finding( array $finding ): array {
		$type   = self::semantic_parity_diagnostic_type( $finding );
		$source = self::first_scalar( $finding, array( 'source_path', 'source', 'path', 'route' ) );
		$reason = self::first_scalar( $finding, array( 'reason_code', 'reason', 'code', 'kind', 'type' ) );

		$diagnostic = array(
			'type'      => $type,
			'source'    => $source,
			'reason'    => '' !== $reason ? $reason : $type,
			'engine'    => 'blocks-engine/php-transformer',
			'stage'     => 'semantic_parity',
			'converter' => 'blocks-engine/php-transformer',
		);

		foreach ( array( 'source_path', 'selector', 'message', 'excerpt', 'source_html_preview', 'html_excerpt', 'expected', 'observed', 'label', 'source_label', 'generated_label', 'url', 'source_url', 'generated_url', 'landmark', 'role', 'block_name', 'block_path' ) as $field ) {
			if ( isset( $finding[ $field ] ) && is_scalar( $finding[ $field ] ) && '' !== trim( (string) $finding[ $field ] ) ) {
				$diagnostic[ $field ] = (string) $finding[ $field ];
			}
		}

		return $diagnostic;
	}

	/**
	 * Map Blocks Engine semantic finding kinds to SSI diagnostic types.
	 *
	 * @param array<string,mixed> $finding Native semantic parity finding.
	 * @return string
	 */
	private static function semantic_parity_diagnostic_type( array $finding ): string {
		$value = sanitize_key( self::first_scalar( $finding, array( 'type', 'kind', 'reason_code', 'reason', 'code' ) ) );
		if ( str_contains( $value, 'nav' ) && str_contains( $value, 'missing' ) ) {
			return 'semantic_parity_navigation_missing';
		}
		if ( str_contains( $value, 'nav' ) && ( str_contains( $value, 'mismatch' ) || str_contains( $value, 'label' ) || str_contains( $value, 'url' ) ) ) {
			return 'semantic_parity_navigation_mismatch';
		}
		if ( str_contains( $value, 'header' ) || str_contains( $value, 'footer' ) || str_contains( $value, 'main' ) || str_contains( $value, 'landmark' ) ) {
			return 'semantic_parity_landmark_missing';
		}

		return 'semantic_parity_failure';
	}

	/**
	 * Compact semantic parity metrics for import-report summaries.
	 *
	 * @param array<string,mixed> $report Full import report.
	 * @return array<string,mixed>
	 */
	private static function compact_semantic_parity_summary( array $report ): array {
		$semantic_parity = isset( $report['blocks_engine']['semantic_parity'] ) && is_array( $report['blocks_engine']['semantic_parity'] ) ? $report['blocks_engine']['semantic_parity'] : array();
		if ( empty( $semantic_parity ) ) {
			return array(
				'status' => (string) ( $report['semantic_fidelity']['status'] ?? 'requires_external_render_check' ),
				'owner'  => (string) ( $report['semantic_fidelity']['gate_owner'] ?? 'benchmark_harness' ),
			);
		}

		return array(
			'status'        => (string) ( $semantic_parity['status'] ?? 'reported' ),
			'owner'         => 'blocks-engine/php-transformer',
			'finding_count' => (int) ( $semantic_parity['finding_count'] ?? 0 ),
			'summary'       => isset( $report['semantic_fidelity']['summary'] ) && is_array( $report['semantic_fidelity']['summary'] ) ? $report['semantic_fidelity']['summary'] : array(),
		);
	}

	/**
	 * Reflect optional native conversion-report quality metadata without changing import behavior.
	 *
	 * @param array<string,mixed> $report            Import report.
	 * @param array<string,mixed> $conversion_report Native conversion report.
	 * @return void
	 */
	private static function record_conversion_report_quality_metadata( array &$report, array $conversion_report ): void {
		$fallbacks              = self::conversion_report_fallback_rows( $conversion_report );
		$interaction_candidates = self::array_values_if_list( $conversion_report['interaction_candidates'] ?? array() );

		$report['quality']['interaction_candidate_count'] = (int) ( $report['quality']['interaction_candidate_count'] ?? 0 ) + count( $interaction_candidates );
		$report['quality']['fallback_count']              = (int) ( $report['quality']['fallback_count'] ?? 0 ) + count( $fallbacks );

		foreach ( $fallbacks as $fallback ) {
			if ( ! is_array( $fallback ) ) {
				continue;
			}

			$diagnostic = self::diagnostic_from_conversion_report_fallback( $fallback );
			if ( ! empty( $diagnostic ) ) {
				$report['diagnostics'][] = $diagnostic;
			}
		}

		foreach ( $interaction_candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$diagnostic = self::diagnostic_from_interaction_candidate( $candidate );
			if ( ! empty( $diagnostic ) ) {
				$report['diagnostics'][] = $diagnostic;
			}
		}
	}

	/**
	 * Mark script fallback diagnostics as carried when the materialization plan emits the matching script asset.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @param array<string,mixed> $site   Blocks Engine materialization plan.
	 * @return void
	 */
	private static function mark_materialized_script_fallbacks_carried( array &$report, array $site ): void {
		$script_assets = self::materialized_runtime_script_assets_by_selector( $site );
		if ( empty( $script_assets ) || empty( $report['diagnostics'] ) || ! is_array( $report['diagnostics'] ) ) {
			return;
		}

		foreach ( $report['diagnostics'] as &$diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! self::is_script_runtime_fallback_diagnostic( $diagnostic ) ) {
				continue;
			}

			$source_path = self::first_scalar( $diagnostic, array( 'source_path', 'source', 'path' ) );
			$selector    = self::first_scalar( $diagnostic, array( 'selector' ) );
			$key         = self::materialized_runtime_script_asset_key( $source_path, $selector );
			if ( '' === $key || ! isset( $script_assets[ $key ] ) ) {
				continue;
			}

			$asset                                    = $script_assets[ $key ];
			$diagnostic['runtime_carried']            = true;
			$diagnostic['loss_class']                 = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
			$diagnostic['diagnostic_class']           = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
			$diagnostic['materialized_runtime_asset'] = $asset;
			if ( empty( $diagnostic['message'] ) ) {
				$diagnostic['message'] = sprintf( 'Script fallback is carried by materialized theme asset %s.', (string) ( $asset['path'] ?? '' ) );
			}
		}
		unset( $diagnostic );
	}

	/**
	 * Resolve script fallback diagnostics after the generated companion plugin is active.
	 *
	 * @param array<string,mixed>            $report          Import report.
	 * @param array<int,array<string,mixed>> $runtime_scripts Materialized companion scripts.
	 * @param string                         $slug             Companion plugin slug.
	 * @return void
	 */
	private static function mark_companion_script_fallbacks_materialized( array &$report, array $runtime_scripts, string $slug ): void {
		$selectors = array();
		foreach ( $runtime_scripts as $script ) {
			if ( ! is_array( $script ) ) {
				continue;
			}
			$selector = self::first_scalar( $script, array( 'selector' ) );
			if ( '' !== $selector ) {
				$selectors[ $selector ] = true;
			}
		}
		if ( empty( $selectors ) || empty( $report['diagnostics'] ) || ! is_array( $report['diagnostics'] ) ) {
			return;
		}

		$resolved = array();
		foreach ( $report['diagnostics'] as &$diagnostic ) {
			if ( ! is_array( $diagnostic ) || ! self::is_script_runtime_fallback_diagnostic( $diagnostic ) ) {
				continue;
			}
			$selector = self::first_scalar( $diagnostic, array( 'selector' ) );
			if ( '' === $selector || ! isset( $selectors[ $selector ] ) ) {
				continue;
			}

			$diagnostic['original_code']                 = self::first_scalar( $diagnostic, array( 'code', 'diagnostic_code', 'kind', 'type' ) );
			$diagnostic['code']                          = 'runtime_script_materialized';
			$diagnostic['diagnostic_code']               = 'runtime_script_materialized';
			$diagnostic['kind']                          = 'runtime_script_materialized';
			$diagnostic['type']                          = 'runtime_script_materialized';
			$diagnostic['severity']                      = 'info';
			$diagnostic['loss_class']                    = Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION;
			$diagnostic['diagnostic_class']              = Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION;
			$diagnostic['repair_bucket']                 = Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION;
			$diagnostic['runtime_carried']               = true;
			$diagnostic['materialized_runtime_provider'] = 'companion_plugin';
			$diagnostic['companion_plugin']              = $slug;
			$diagnostic['message']                       = sprintf( 'Runtime script is materialized by active companion plugin %s.', $slug );
			$resolved[ $selector ]                       = true;
		}
		unset( $diagnostic );

		if ( ! empty( $resolved ) ) {
			$report['quality']['fallback_count'] = max( 0, (int) ( $report['quality']['fallback_count'] ?? 0 ) - count( $resolved ) );
		}
	}

	/**
	 * Reconcile late-added fallback rows against active companion dependencies.
	 *
	 * @param array<string,mixed> $report Import report.
	 * @return void
	 */
	private static function mark_active_companion_script_fallbacks_materialized( array &$report ): void {
		$dependencies = $report['companion_plugins']['dependencies'] ?? array();
		if ( ! is_array( $dependencies ) ) {
			return;
		}

		foreach ( $dependencies as $slug => $dependency ) {
			if ( ! is_array( $dependency ) || empty( $dependency['active'] ) ) {
				continue;
			}
			$runtime_scripts = isset( $dependency['runtime_scripts'] ) && is_array( $dependency['runtime_scripts'] ) ? $dependency['runtime_scripts'] : array();
			self::mark_companion_script_fallbacks_materialized( $report, $runtime_scripts, (string) $slug );
		}
	}

	/**
	 * Index materialized executable script assets by source document and selector.
	 *
	 * @param array<string,mixed> $site Blocks Engine materialization plan.
	 * @return array<string,array<string,string>>
	 */
	private static function materialized_runtime_script_assets_by_selector( array $site ): array {
		if ( 'blocks-engine/php-transformer/materialization-plan/v1' !== (string) ( $site['schema'] ?? '' ) || empty( $site['assets'] ) || ! is_array( $site['assets'] ) ) {
			return array();
		}

		$indexed = array();
		foreach ( $site['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$role   = self::first_scalar( $asset, array( 'role' ) );
			$kind   = self::first_scalar( $asset, array( 'kind' ) );
			$source = self::first_scalar( $asset, array( 'source' ) );
			if ( 'script' !== $role || 'js' !== $kind || 'inline-script' !== $source ) {
				continue;
			}

			$source_path = self::first_scalar( $asset, array( 'source_path' ) );
			$selector    = self::first_scalar( $asset, array( 'selector' ) );
			$key         = self::materialized_runtime_script_asset_key( $source_path, $selector );
			if ( '' === $key ) {
				continue;
			}

			$indexed[ $key ] = array_filter(
				array(
					'path'        => self::first_scalar( $asset, array( 'path', 'target_path' ) ),
					'source_path' => $source_path,
					'selector'    => $selector,
					'role'        => $role,
					'kind'        => $kind,
					'source'      => $source,
				),
				static fn ( string $value ): bool => '' !== $value
			);
		}

		return $indexed;
	}

	/**
	 * Build a stable materialized script lookup key.
	 *
	 * @param string $source_path Source document path.
	 * @param string $selector    Source element selector.
	 * @return string
	 */
	private static function materialized_runtime_script_asset_key( string $source_path, string $selector ): string {
		$source_path = trim( $source_path );
		$selector    = trim( $selector );
		if ( '' === $source_path || '' === $selector ) {
			return '';
		}

		return $source_path . "\0" . $selector;
	}

	/**
	 * Check whether a diagnostic describes a script fallback that needs carried runtime evidence.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return bool
	 */
	private static function is_script_runtime_fallback_diagnostic( array $diagnostic ): bool {
		$parts = array();
		foreach ( array( 'diagnostic_code', 'kind', 'type', 'reason_code', 'reason', 'code', 'tag_name', 'tag', 'element', 'message' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && '' !== trim( (string) $diagnostic[ $field ] ) ) {
				$parts[] = (string) $diagnostic[ $field ];
			}
		}

		$haystack = implode( ' ', $parts );

		return (bool) preg_match( '/html[_\s-]+script[_\s-]+fallback|script[_\s-]+requires[_\s-]+runtime|\bscript\b/i', $haystack );
	}

	/**
	 * Preserve optional Blocks Engine runtime dependency parity evidence compactly.
	 *
	 * @param array<string,mixed> $runtime_dependency_parity Native runtime dependency parity report.
	 * @return array<string,mixed>
	 */
	private static function runtime_dependency_parity_payload( array $runtime_dependency_parity ): array {
		$scripts  = self::runtime_dependency_parity_scripts( $runtime_dependency_parity );
		$findings = self::runtime_dependency_parity_findings( $runtime_dependency_parity );

		$payload = array(
			'schema'                              => isset( $runtime_dependency_parity['schema'] ) && is_scalar( $runtime_dependency_parity['schema'] ) ? (string) $runtime_dependency_parity['schema'] : 'blocks-engine/runtime-dependency-parity/v1',
			'status'                              => isset( $runtime_dependency_parity['status'] ) && is_scalar( $runtime_dependency_parity['status'] ) ? (string) $runtime_dependency_parity['status'] : '',
			'script_count'                        => count( $scripts ),
			'finding_count'                       => count( $findings ),
			'missing_dom_target_count'            => count( array_filter( $findings, static fn ( array $finding ): bool => 'runtime_dependency_missing_dom_target' === ( $finding['type'] ?? '' ) ) ),
			'unsupported_element_reference_count' => count( array_filter( $findings, static fn ( array $finding ): bool => 'runtime_dependency_unsupported_element_reference' === ( $finding['type'] ?? '' ) ) ),
			'vendor_telemetry_script_count'       => count( array_filter( $findings, static fn ( array $finding ): bool => 'runtime_dependency_vendor_telemetry_script' === ( $finding['type'] ?? '' ) ) ),
		);

		if ( ! empty( $scripts ) ) {
			$payload['scripts'] = self::compact_native_report_rows( $scripts );
		}
		if ( ! empty( $findings ) ) {
			$payload['findings'] = self::compact_native_report_rows( $findings );
		}

		return $payload;
	}

	/**
	 * Reflect runtime dependency parity findings into report quality metadata.
	 *
	 * @param array<string,mixed> $report                    Import report.
	 * @param array<string,mixed> $runtime_dependency_parity Native runtime dependency parity report.
	 * @return void
	 */
	private static function record_runtime_dependency_parity_quality_metadata( array &$report, array $runtime_dependency_parity ): void {
		$issue_count = 0;
		foreach ( self::runtime_dependency_parity_findings( $runtime_dependency_parity ) as $finding ) {
			$diagnostic = self::diagnostic_from_runtime_dependency_parity_finding( $finding );
			if ( empty( $diagnostic ) ) {
				continue;
			}

			if ( 'runtime_dependency_vendor_telemetry_script' !== ( $diagnostic['type'] ?? '' ) ) {
				++$issue_count;
			}
			$report['diagnostics'][] = $diagnostic;
		}

		$report['quality']['runtime_dependency_parity_issue_count'] = (int) ( $report['quality']['runtime_dependency_parity_issue_count'] ?? 0 ) + $issue_count;
	}

	/**
	 * Return script rows from plausible runtime dependency parity fields.
	 *
	 * @param array<string,mixed> $runtime_dependency_parity Native runtime dependency parity report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function runtime_dependency_parity_scripts( array $runtime_dependency_parity ): array {
		foreach ( array( 'scripts', 'script_assets', 'assets' ) as $field ) {
			$rows = self::array_values_if_list( $runtime_dependency_parity[ $field ] ?? array() );
			if ( ! empty( $rows ) ) {
				return array_values( array_filter( $rows, 'is_array' ) );
			}
		}

		return array();
	}

	/**
	 * Return normalized finding rows from plausible runtime dependency parity fields.
	 *
	 * @param array<string,mixed> $runtime_dependency_parity Native runtime dependency parity report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function runtime_dependency_parity_findings( array $runtime_dependency_parity ): array {
		$findings = array();
		foreach ( self::array_values_if_list( $runtime_dependency_parity['findings'] ?? array() ) as $finding ) {
			if ( is_array( $finding ) ) {
				$findings[] = self::normalize_runtime_dependency_parity_finding( $finding );
			}
		}

		foreach ( self::array_values_if_list( $runtime_dependency_parity['missing_dom_targets'] ?? array() ) as $target ) {
			if ( is_array( $target ) ) {
				$findings[] = self::normalize_runtime_dependency_parity_finding( array_merge( array( 'type' => 'missing_dom_target' ), $target ) );
			}
		}

		foreach ( self::array_values_if_list( $runtime_dependency_parity['unsupported_elements'] ?? array() ) as $element ) {
			if ( is_array( $element ) ) {
				$findings[] = self::normalize_runtime_dependency_parity_finding( array_merge( array( 'type' => 'unsupported_element_reference' ), $element ) );
			}
		}

		foreach ( self::array_values_if_list( $runtime_dependency_parity['vendor_telemetry_scripts'] ?? array() ) as $script ) {
			if ( is_array( $script ) ) {
				$findings[] = self::normalize_runtime_dependency_parity_finding( array_merge( array( 'type' => 'vendor_telemetry_script' ), $script ) );
			}
		}

		return $findings;
	}

	/**
	 * Normalize one runtime dependency parity row into SSI diagnostic-compatible fields.
	 *
	 * @param array<string,mixed> $finding Runtime dependency parity finding row.
	 * @return array<string,mixed>
	 */
	private static function normalize_runtime_dependency_parity_finding( array $finding ): array {
		$type = self::first_scalar( $finding, array( 'type', 'kind', 'code' ) );
		$type = sanitize_key( $type );
		if ( str_contains( $type, 'missing' ) && ( str_contains( $type, 'target' ) || str_contains( $type, 'dom' ) || str_contains( $type, 'selector' ) ) ) {
			$type = 'runtime_dependency_missing_dom_target';
		} elseif ( str_contains( $type, 'unsupported' ) && ( str_contains( $type, 'element' ) || str_contains( $type, 'dom' ) ) ) {
			$type = 'runtime_dependency_unsupported_element_reference';
		} elseif ( str_contains( $type, 'telemetry' ) || ! empty( $finding['telemetry'] ) ) {
			$type = 'runtime_dependency_vendor_telemetry_script';
		} elseif ( ! str_starts_with( $type, 'runtime_dependency_' ) ) {
			$type = 'runtime_dependency_parity_issue';
		}

		$finding['type'] = $type;
		return $finding;
	}

	/**
	 * Normalize runtime dependency parity rows into SSI diagnostics.
	 *
	 * @param array<string,mixed> $finding Normalized runtime dependency parity finding row.
	 * @return array<string,mixed>
	 */
	private static function diagnostic_from_runtime_dependency_parity_finding( array $finding ): array {
		$type        = isset( $finding['type'] ) && is_scalar( $finding['type'] ) ? (string) $finding['type'] : 'runtime_dependency_parity_issue';
		$source      = self::first_scalar( $finding, array( 'source_path', 'source', 'document_path', 'path' ) );
		$script_path = self::first_scalar( $finding, array( 'script_path', 'script', 'asset_path', 'asset' ) );
		$selector    = self::first_scalar( $finding, array( 'selector', 'target_selector', 'target', 'dom_target' ) );

		if ( '' === $source && '' === $script_path && '' === $selector ) {
			return array();
		}

		$diagnostic = array(
			'type'        => $type,
			'source'      => '' !== $source ? $source : $script_path,
			'reason'      => self::first_scalar( $finding, array( 'reason_code', 'reason', 'message' ) ),
			'engine'      => 'blocks-engine/php-transformer',
			'stage'       => 'runtime_dependency_parity',
			'converter'   => 'blocks-engine/php-transformer',
			'severity'    => 'runtime_dependency_vendor_telemetry_script' === $type ? 'notice' : 'warning',
			'script_path' => $script_path,
		);

		if ( '' !== $selector ) {
			$diagnostic['selector'] = $selector;
		}
		if ( '' === $diagnostic['reason'] ) {
			$diagnostic['reason'] = $type;
		}

		foreach ( array( 'source_path', 'message', 'excerpt', 'source_html_preview', 'html_excerpt', 'tag_name', 'element', 'handle', 'src' ) as $field ) {
			if ( isset( $finding[ $field ] ) && is_scalar( $finding[ $field ] ) && '' !== trim( (string) $finding[ $field ] ) ) {
				$diagnostic[ $field ] = (string) $finding[ $field ];
			}
		}

		return $diagnostic;
	}

	/**
	 * Return fallback rows from old and canonical Blocks Engine conversion-report fields.
	 *
	 * @param array<string,mixed> $conversion_report Native conversion report.
	 * @return array<int,mixed>
	 */
	private static function conversion_report_fallback_rows( array $conversion_report ): array {
		$fallbacks = self::array_values_if_list( $conversion_report['fallbacks'] ?? array() );
		if ( ! empty( $fallbacks ) ) {
			return $fallbacks;
		}

		return self::array_values_if_list( $conversion_report['fallback_diagnostics'] ?? array() );
	}

	/**
	 * Normalize a native fallback row into SSI's diagnostic shape when useful fields exist.
	 *
	 * @param array<string,mixed> $fallback Native fallback row.
	 * @return array<string,mixed>
	 */
	private static function diagnostic_from_conversion_report_fallback( array $fallback ): array {
		$source = self::first_scalar( $fallback, array( 'source_path', 'source', 'path' ) );
		$reason = self::first_scalar( $fallback, array( 'reason_code', 'reason', 'code' ) );
		if ( '' === $source && '' === $reason ) {
			return array();
		}

		$diagnostic = array(
			'type'      => 'unsupported_html_fallback',
			'source'    => $source,
			'reason'    => '' !== $reason ? $reason : 'native_conversion_report_fallback',
			'engine'    => 'blocks-engine/php-transformer',
			'stage'     => 'block_conversion',
			'converter' => 'blocks-engine/php-transformer',
		);

		foreach ( array( 'source_path', 'selector', 'tag_name', 'block_name', 'block_path', 'message', 'excerpt', 'source_html_preview', 'emitted_block_preview', 'html_excerpt' ) as $field ) {
			if ( isset( $fallback[ $field ] ) && is_scalar( $fallback[ $field ] ) && '' !== trim( (string) $fallback[ $field ] ) ) {
				$diagnostic[ $field ] = (string) $fallback[ $field ];
			}
		}

		$diagnostic_code = self::first_scalar( $fallback, array( 'diagnostic_code' ) );
		if ( 'html_form_fallback' === $diagnostic_code ) {
			$diagnostic = self::enrich_form_fallback_diagnostic( $diagnostic, $fallback, $diagnostic_code );
		}

		// The product-grid fallback may carry its code under `kind` (Blocks Engine
		// product-grid contract) or `diagnostic_code` (normalized fallbacks).
		$kind = self::first_scalar( $fallback, array( 'kind' ) );
		if ( 'html_product_grid_fallback' === $diagnostic_code || 'html_product_grid_fallback' === $kind ) {
			$diagnostic = self::enrich_product_grid_fallback_diagnostic( $diagnostic, $fallback );
		}

		return $diagnostic;
	}

	/**
	 * Carry preserved <form> runtime island metadata onto its SSI diagnostic.
	 *
	 * Keeps the native diagnostic_code, classifies the finding as a preserved
	 * runtime island, and forwards the form attributes and source control list so
	 * the configured form provider can materialize it and close the gate loop.
	 *
	 * @param array<string,mixed> $diagnostic      Base diagnostic.
	 * @param array<string,mixed> $fallback        Native fallback row.
	 * @param string              $diagnostic_code Native diagnostic code.
	 * @return array<string,mixed>
	 */
	private static function enrich_form_fallback_diagnostic( array $diagnostic, array $fallback, string $diagnostic_code ): array {
		$diagnostic['diagnostic_code']     = $diagnostic_code;
		$diagnostic['loss_class']          = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
		$diagnostic['diagnostic_class']    = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
		$diagnostic['tag']                 = 'form';
		$diagnostic['tag_name']            = isset( $diagnostic['tag_name'] ) && '' !== $diagnostic['tag_name'] ? $diagnostic['tag_name'] : 'form';
		$diagnostic['element']             = 'form';
		$diagnostic['suggested_primitive'] = 'form';
		$diagnostic['runtime_requirement'] = self::first_scalar( $fallback, array( 'runtime_requirement' ) );

		if ( isset( $fallback['form'] ) && is_array( $fallback['form'] ) ) {
			$diagnostic['form'] = $fallback['form'];
		}
		if ( isset( $fallback['controls'] ) && is_array( $fallback['controls'] ) ) {
			$diagnostic['controls'] = array_values( array_filter( $fallback['controls'], 'is_array' ) );
		}
		if ( isset( $fallback['control_count'] ) && is_numeric( $fallback['control_count'] ) ) {
			$diagnostic['control_count'] = (int) $fallback['control_count'];
		}
		self::carry_materialization_metadata( $diagnostic, $fallback );

		// Carry the transformer's readable fallback block tree so the form graft
		// can anchor the seeded contact-form markup to the exact fallback region
		// the finding represents inside the page's post_content (the finding's own
		// structural identity), instead of guessing by matching visible copy.
		if ( isset( $fallback['readable_blocks'] ) && is_array( $fallback['readable_blocks'] ) ) {
			$diagnostic['readable_blocks'] = array_values( array_filter( $fallback['readable_blocks'], 'is_array' ) );
		}

		return $diagnostic;
	}

	/**
	 * Carry preserved product-grid metadata onto its SSI diagnostic.
	 *
	 * Mirrors the form-fallback enrichment: keeps a stable diagnostic_code,
	 * classifies the finding as a preserved runtime island, and forwards the
	 * container selector plus the detected product list so the configured shop
	 * provider can materialize the products and close the gate loop.
	 *
	 * @param array<string,mixed> $diagnostic Base diagnostic.
	 * @param array<string,mixed> $fallback   Native fallback row.
	 * @return array<string,mixed>
	 */
	private static function enrich_product_grid_fallback_diagnostic( array $diagnostic, array $fallback ): array {
		$diagnostic['diagnostic_code']     = 'html_product_grid_fallback';
		$diagnostic['loss_class']          = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
		$diagnostic['diagnostic_class']    = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
		$diagnostic['tag']                 = 'product-grid';
		$diagnostic['element']             = 'product-grid';
		$diagnostic['suggested_primitive'] = 'product';

		$container = self::first_scalar( $fallback, array( 'container_selector', 'selector' ) );
		if ( '' !== $container ) {
			$diagnostic['container_selector'] = $container;
			if ( empty( $diagnostic['selector'] ) ) {
				$diagnostic['selector'] = $container;
			}
		}

		if ( isset( $fallback['products'] ) && is_array( $fallback['products'] ) ) {
			$products                    = array_values( array_filter( $fallback['products'], 'is_array' ) );
			$diagnostic['products']      = $products;
			$diagnostic['product_count'] = count( $products );
		}
		self::carry_materialization_metadata( $diagnostic, $fallback );

		return $diagnostic;
	}

	/**
	 * Preserve provider materialization metadata on normalized SSI findings.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic, mutated in place.
	 * @param array<string,mixed> $fallback   Native Blocks Engine fallback row.
	 * @return void
	 */
	private static function carry_materialization_metadata( array &$diagnostic, array $fallback ): void {
		foreach ( array( 'materialization_target', 'form', 'controls', 'products' ) as $field ) {
			if ( isset( $fallback[ $field ] ) && is_array( $fallback[ $field ] ) ) {
				$diagnostic[ $field ] = $fallback[ $field ];
			}
		}

		foreach ( array( 'materialization_hint', 'recoverability', 'actionability', 'suggested_repair_class', 'runtime_requirement' ) as $field ) {
			if ( isset( $fallback[ $field ] ) && is_scalar( $fallback[ $field ] ) && '' !== trim( (string) $fallback[ $field ] ) ) {
				$diagnostic[ $field ] = (string) $fallback[ $field ];
			}
		}
	}

	/**
	 * Normalize a native interaction candidate into a report-only diagnostic.
	 *
	 * @param array<string,mixed> $candidate Native interaction candidate row.
	 * @return array<string,mixed>
	 */
	private static function diagnostic_from_interaction_candidate( array $candidate ): array {
		$source = self::first_scalar( $candidate, array( 'source_path', 'source', 'path' ) );
		if ( '' === $source && '' === self::first_scalar( $candidate, array( 'selector', 'kind', 'type' ) ) ) {
			return array();
		}

		$diagnostic = array(
			'type'      => 'interaction_candidate',
			'source'    => $source,
			'reason'    => 'native_conversion_report_interaction_candidate',
			'engine'    => 'blocks-engine/php-transformer',
			'stage'     => 'interaction_detection',
			'converter' => 'blocks-engine/php-transformer',
		);

		foreach ( array( 'source_path', 'selector', 'tag_name', 'message', 'excerpt', 'source_html_preview', 'html_excerpt', 'kind' ) as $field ) {
			if ( isset( $candidate[ $field ] ) && is_scalar( $candidate[ $field ] ) && '' !== trim( (string) $candidate[ $field ] ) ) {
				$diagnostic[ $field ] = (string) $candidate[ $field ];
			}
		}

		return $diagnostic;
	}

	/**
	 * Compact native report rows while preserving scalar diagnostic metadata.
	 *
	 * @param array<int,mixed> $rows Native report rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function compact_native_report_rows( array $rows ): array {
		$fields  = array( 'type', 'kind', 'code', 'diagnostic_code', 'severity', 'source', 'source_path', 'path', 'script_path', 'selector', 'container_selector', 'target_selector', 'target', 'dom_target', 'tag', 'tag_name', 'element', 'block_name', 'block_path', 'attribute_path', 'reason', 'reason_code', 'message', 'excerpt', 'source_html_preview', 'emitted_block_preview', 'html_excerpt', 'handle', 'src', 'role', 'discovered', 'materialized', 'enqueued', 'telemetry', 'vendor', 'expected', 'observed', 'label', 'source_label', 'generated_label', 'url', 'source_url', 'generated_url', 'landmark', 'runtime_requirement', 'recoverability', 'actionability', 'suggested_repair_class', 'suggested_primitive', 'materialization_hint', 'control_count', 'product_count' );
		$array_fields = array( 'materialization_target', 'form', 'controls', 'products', 'readable_blocks' );
		$compact = array();
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$entry = array();
			foreach ( $fields as $field ) {
				if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
					$entry[ $field ] = is_bool( $row[ $field ] ) || is_numeric( $row[ $field ] ) ? $row[ $field ] : (string) $row[ $field ];
				}
			}
			foreach ( $array_fields as $field ) {
				if ( isset( $row[ $field ] ) && is_array( $row[ $field ] ) && ! empty( $row[ $field ] ) ) {
					$entry[ $field ] = self::compact_native_report_value( $row[ $field ] );
				}
			}

			if ( ! empty( $entry ) ) {
				$compact[] = $entry;
			}
		}

		return $compact;
	}

	/**
	 * Compact nested native report values while preserving scalar metrics.
	 *
	 * @param mixed $value Native report value.
	 * @return mixed
	 */
	private static function compact_native_report_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : null;
		}

		$compact = array();
		foreach ( array_slice( $value, 0, 50, true ) as $key => $item ) {
			$compacted = self::compact_native_report_value( $item );
			if ( null !== $compacted ) {
				$compact[ $key ] = $compacted;
			}
		}

		return $compact;
	}

	/**
	 * Return array values only for list-like report rows.
	 *
	 * @param mixed $value Candidate list.
	 * @return array<int,mixed>
	 */
	private static function array_values_if_list( mixed $value ): array {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Return the first non-empty scalar from a row.
	 *
	 * @param array<string,mixed> $row  Source row.
	 * @param array<int,string>   $keys Candidate keys.
	 * @return string
	 */
	private static function first_scalar( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
		}

		return '';
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
		$seen       = array();
		foreach ( array_values( $report['diagnostics'] ) as $index => $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}
			if ( self::is_count_only_diagnostic( $diagnostic ) ) {
				continue;
			}

			$type        = isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'import_diagnostic';
			$source      = isset( $diagnostic['source'] ) && is_scalar( $diagnostic['source'] ) ? (string) $diagnostic['source'] : '';
			$source_path = isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : self::diagnostic_source_path( $source );
			$reason_code = self::diagnostic_reason_code( $type, $diagnostic );

			$machine                     = array(
				'id'                     => self::diagnostic_id( $index, $type, $source_path, $reason_code ),
				'severity'               => self::diagnostic_severity( $type ),
				'category'               => self::diagnostic_category( $type ),
				'reason_code'            => $reason_code,
				'suggested_repair_class' => self::diagnostic_repair_class( $type ),
				'repair_class'           => self::diagnostic_repair_class( $type ),
				'source_path'            => $source_path,
			);
			$machine['loss_class']       = Static_Site_Importer_Diagnostic_Loss_Classes::classify( array_merge( $diagnostic, $machine ) );
			$machine['diagnostic_class'] = $machine['loss_class'];
			$machine['acceptability']    = self::diagnostic_acceptability( $machine['loss_class'] );
			$source_diagnostic           = self::source_diagnostic_identity( $diagnostic, $machine );
			if ( ! empty( $source_diagnostic ) ) {
				$machine['source_diagnostic'] = $source_diagnostic;
			}
			if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === $machine['loss_class'] ) {
				$machine['repair_bucket'] = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
				$machine['group_key']     = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
			}

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

			$row = array_merge( $machine, $diagnostic );
			if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === $machine['loss_class'] ) {
				$repair_bucket = isset( $row['repair_bucket'] ) && is_scalar( $row['repair_bucket'] ) ? (string) $row['repair_bucket'] : '';
				if ( '' === $repair_bucket || in_array( $repair_bucket, array( 'static_site_import_quality', 'import_quality' ), true ) ) {
					$row['repair_bucket'] = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
					$row['group_key']     = Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND;
				}
			}
			$key = self::diagnostic_dedupe_key( $row );
			if ( isset( $seen[ $key ] ) ) {
				$normalized[ $seen[ $key ] ] = self::merge_diagnostic_context( $normalized[ $seen[ $key ] ], $row );
				continue;
			}

			$seen[ $key ] = count( $normalized );
			$normalized[] = $row;
		}

		$report['diagnostics'] = $normalized;
	}

	/**
	 * Check whether a diagnostic is only a count/index placeholder.
	 *
	 * @param array<string,mixed> $diagnostic Candidate diagnostic.
	 * @return bool
	 */
	private static function is_count_only_diagnostic( array $diagnostic ): bool {
		$type   = sanitize_key( self::first_scalar( $diagnostic, array( 'type', 'kind', 'code' ) ) );
		$reason = self::first_scalar( $diagnostic, array( 'reason_code', 'reason', 'error_code', 'message' ) );

		if ( ! self::is_placeholder_scalar( $type ) && ! in_array( $type, array( 'diagnostic', 'import_diagnostic', 'static_site_fixture_diagnostic', 'static_site_importer_diagnostic' ), true ) ) {
			return false;
		}
		if ( '' !== $reason && ! self::is_placeholder_scalar( $reason ) ) {
			return false;
		}

		foreach ( array( 'selector', 'source_snippet', 'source_html_preview', 'emitted_block_preview', 'observed_output', 'html_excerpt', 'excerpt', 'script_path', 'src', 'href', 'expected', 'observed' ) as $field ) {
			if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && ! self::is_placeholder_scalar( (string) $diagnostic[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a scalar is a placeholder rather than source evidence.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_placeholder_scalar( string $value ): bool {
		$value = trim( strtolower( $value ) );

		return '' === $value || '(none)' === $value || 'none' === $value || 1 === preg_match( '/^\d+$/', $value );
	}

	/**
	 * Build a dedupe key that can collapse SSI and Blocks Engine echoes of the same finding.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return string
	 */
	private static function diagnostic_dedupe_key( array $diagnostic ): string {
		$source_path = isset( $diagnostic['source_path'] ) && is_scalar( $diagnostic['source_path'] ) ? (string) $diagnostic['source_path'] : '';
		$selector    = isset( $diagnostic['selector'] ) && is_scalar( $diagnostic['selector'] ) ? (string) $diagnostic['selector'] : '';
		$reason      = self::first_scalar( $diagnostic, array( 'reason_code', 'reason', 'error_code' ) );
		$loss_class  = isset( $diagnostic['loss_class'] ) && is_scalar( $diagnostic['loss_class'] ) ? (string) $diagnostic['loss_class'] : '';

		if ( '' !== $source_path && '' !== $selector && '' !== $reason ) {
			return implode( '|', array( 'context', $source_path, $selector, sanitize_key( $reason ), $loss_class ) );
		}

		return implode( '|', array_map( 'strval', array( 'identity', $diagnostic['id'] ?? '', $diagnostic['type'] ?? '', $source_path, $selector, $diagnostic['reason_code'] ?? '' ) ) );
	}

	/**
	 * Merge duplicate diagnostics without discarding source evidence from either producer.
	 *
	 * @param array<string,mixed> $primary   First diagnostic row.
	 * @param array<string,mixed> $duplicate Duplicate diagnostic row.
	 * @return array<string,mixed>
	 */
	private static function merge_diagnostic_context( array $primary, array $duplicate ): array {
		foreach ( $duplicate as $field => $value ) {
			if ( ! array_key_exists( $field, $primary ) || '' === $primary[ $field ] || null === $primary[ $field ] || array() === $primary[ $field ] ) {
				$primary[ $field ] = $value;
			}
		}

		return $primary;
	}

	/**
	 * Build quality counter references into normalized diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Normalized diagnostics.
	 * @return array<string,array<int,string>> Diagnostic IDs keyed by quality count.
	 */
	private static function quality_diagnostic_refs( array $diagnostics ): array {
		$types_by_count = array(
			'fallback_count'                        => array( 'unsupported_html_fallback' ),
			'content_loss_count'                    => array( 'content_loss_abort' ),
			'empty_conversion_count'                => array( 'empty_conversion' ),
			'core_html_block_count'                 => array( 'core_html_block' ),
			'freeform_block_count'                  => array( 'freeform_block' ),
			'invalid_block_count'                   => array( 'invalid_block_document' ),
			'unsafe_svg_count'                      => array( 'unsafe_inline_svg' ),
			'svg_materialization_failure_count'     => array( 'svg_materialization_failure' ),
			'svg_sprite_reference_failure_count'    => array( 'svg_sprite_reference_failure' ),
			'commerce_dependency_failures'          => array( 'commerce_dependency_failure' ),
			'interaction_candidate_count'           => array( 'interaction_candidate' ),
			'runtime_dependency_parity_issue_count' => array( 'runtime_dependency_missing_dom_target', 'runtime_dependency_unsupported_element_reference', 'runtime_dependency_parity_issue' ),
			'semantic_parity_failure_count'         => array( 'semantic_parity_navigation_missing', 'semantic_parity_navigation_mismatch', 'semantic_parity_landmark_missing', 'semantic_parity_failure' ),
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
			if ( ! isset( $diagnostic[ $key ] ) || ! is_scalar( $diagnostic[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $diagnostic[ $key ] );
			if ( '' !== $value && ! preg_match( '/^\d+$/', $value ) ) {
				return sanitize_key( $value );
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
			'local_asset_not_materialized'               => 'unresolved_asset',
			'unresolved_internal_link'                   => 'broken_internal_link',
			'unsafe_inline_svg'                          => 'unsafe_svg',
			'svg_materialization_failure'                => 'unresolved_asset',
			'svg_sprite_reference_failure'               => 'unresolved_asset',
			'unsupported_source_document'                => 'unsupported_source',
			'unsupported_html_fallback'                  => 'unsupported_element',
			'core_html_block'                            => 'fallback_block',
			'freeform_block'                             => 'fallback_block',
			'invalid_block_document'                     => 'conversion_quality',
			'content_loss_abort'                         => 'conversion_quality',
			'empty_conversion'                           => 'conversion_quality',
			'source_region_unassigned'                   => 'source_region',
			'commerce_dependency_failure'                => 'conversion_quality',
			'commerce_product_inference_unmatched'       => 'conversion_quality',
			'interaction_candidate'                      => 'source_interaction',
			'runtime_dependency_missing_dom_target'      => 'runtime_dependency_parity',
			'runtime_dependency_unsupported_element_reference' => 'runtime_dependency_parity',
			'runtime_dependency_vendor_telemetry_script' => 'runtime_dependency_parity',
			'runtime_dependency_parity_issue'            => 'runtime_dependency_parity',
			'semantic_parity_navigation_missing'         => 'semantic_parity',
			'semantic_parity_navigation_mismatch'        => 'semantic_parity',
			'semantic_parity_landmark_missing'           => 'semantic_parity',
			'semantic_parity_failure'                    => 'semantic_parity',
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
			'local_asset_not_materialized'               => 'materialize_or_rewrite_asset',
			'unresolved_internal_link'                   => 'rewrite_or_create_internal_target',
			'unsafe_inline_svg'                          => 'sanitize_or_externalize_svg',
			'svg_materialization_failure'                => 'materialize_or_rewrite_asset',
			'svg_sprite_reference_failure'               => 'materialize_or_rewrite_asset',
			'unsupported_source_document'                => 'convert_source_document',
			'unsupported_html_fallback'                  => 'replace_unsupported_html',
			'core_html_block'                            => 'replace_fallback_block',
			'freeform_block'                             => 'replace_fallback_block',
			'invalid_block_document'                     => 'repair_generated_block_markup',
			'content_loss_abort'                         => 'repair_source_conversion',
			'empty_conversion'                           => 'repair_source_conversion',
			'source_region_unassigned'                   => 'assign_or_ignore_source_region',
			'commerce_dependency_failure'                => 'install_or_configure_dependency',
			'commerce_product_inference_unmatched'       => 'provide_structured_product_data',
			'interaction_candidate'                      => 'inspect_interactive_behavior',
			'runtime_dependency_missing_dom_target'      => 'restore_or_remove_runtime_dom_dependency',
			'runtime_dependency_unsupported_element_reference' => 'replace_unsupported_runtime_element',
			'runtime_dependency_vendor_telemetry_script' => 'review_vendor_telemetry_script',
			'runtime_dependency_parity_issue'            => 'inspect_runtime_dependency_parity',
			'semantic_parity_navigation_missing'         => 'generate_core_navigation_parity',
			'semantic_parity_navigation_mismatch'        => 'repair_core_navigation_items',
			'semantic_parity_landmark_missing'           => 'generate_semantic_landmark_parity',
			'semantic_parity_failure'                    => 'repair_semantic_structure',
		);

		return $classes[ $type ] ?? 'inspect_import_diagnostic';
	}

	/**
	 * Classify whether a diagnostic is acceptable preservation/conversion or an imported-output defect.
	 *
	 * @param string $loss_class Normalized loss class.
	 * @return string
	 */
	private static function diagnostic_acceptability( string $loss_class ): string {
		if ( Static_Site_Importer_Diagnostic_Loss_Classes::PRESERVED_RUNTIME_ISLAND === $loss_class ) {
			return 'acceptable_preservation';
		}
		if ( in_array( $loss_class, array( Static_Site_Importer_Diagnostic_Loss_Classes::NATIVE_CONVERSION, Static_Site_Importer_Diagnostic_Loss_Classes::EDITABLE_APPROXIMATION ), true ) ) {
			return 'acceptable_conversion';
		}

		return 'unacceptable_imported_output_defect';
	}

	/**
	 * Preserve source diagnostic identity fields alongside the normalized row.
	 *
	 * @param array<string,mixed> $diagnostic Raw diagnostic row.
	 * @param array<string,mixed> $machine    Normalized diagnostic row.
	 * @return array<string,string>
	 */
	private static function source_diagnostic_identity( array $diagnostic, array $machine ): array {
		$identity = array();
		foreach ( array( 'id', 'type', 'kind', 'code', 'reason_code', 'reason', 'message', 'source_path', 'selector', 'stage', 'engine' ) as $field ) {
			$value = self::first_scalar( $diagnostic, array( $field ) );
			if ( '' === $value ) {
				$value = self::first_scalar( $machine, array( $field ) );
			}
			if ( '' !== $value ) {
				$identity[ $field ] = $value;
			}
		}

		return $identity;
	}

	/**
	 * Extract concise diagnostic context for repair prompts.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic record.
	 * @return array<string,mixed> Context fields.
	 */
	private static function diagnostic_context( array $diagnostic ): array {
		$context = array();
		foreach ( array( 'href', 'tag', 'tag_name', 'block_name', 'block_path', 'excerpt', 'html_excerpt', 'source_html_preview', 'error_message', 'kind', 'script_path', 'element', 'handle', 'src', 'expected', 'observed', 'label', 'source_label', 'generated_label', 'url', 'source_url', 'generated_url', 'landmark', 'role' ) as $key ) {
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
			'total'      => 0,
			'error'      => 0,
			'warning'    => 0,
			'notice'     => 0,
			'info'       => 0,
			'loss_class' => Static_Site_Importer_Diagnostic_Loss_Classes::counts( $diagnostics ),
		);

		foreach ( $diagnostics as $diagnostic ) {
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
				'id'         => isset( $diagnostic['id'] ) && is_scalar( $diagnostic['id'] ) ? (string) $diagnostic['id'] : '',
				'type'       => isset( $diagnostic['type'] ) && is_scalar( $diagnostic['type'] ) ? (string) $diagnostic['type'] : 'static_site_importer_diagnostic',
				'loss_class' => isset( $diagnostic['loss_class'] ) && is_scalar( $diagnostic['loss_class'] ) ? (string) $diagnostic['loss_class'] : Static_Site_Importer_Diagnostic_Loss_Classes::classify( $diagnostic ),
				'source'     => self::diagnostic_summary_source( $diagnostic ),
				'message'    => self::diagnostic_summary_message( $diagnostic ),
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
			'loss_class',
			'diagnostic_class',
			'acceptability',
			'reason_code',
			'suggested_repair_class',
			'repair_class',
			'repair_bucket',
			'group_key',
			'source_diagnostic',
			'source_path',
			'source',
			'selector',
			'script_path',
			'excerpt',
			'source_snippet',
			'source_html_preview',
			'observed_output',
			'emitted_block_preview',
			'observed_block_name',
			'block_name',
			'block_path',
			'engine',
			'stage',
			'reason',
			'message',
			'removed_header_blocks',
			'removed_footer_blocks',
			'matched_header_template_part',
			'matched_footer_template_part',
			'preserved_local_header_count',
			'preserved_local_footer_count',
			'tag_name',
			'element',
			'html_excerpt',
			'context',
			'diagnostic_code',
			'runtime_mapped',
			'runtime_carried',
			'mapped_provider',
		);

		$compact = array();
		foreach ( array_slice( $diagnostics, 0, 50 ) as $diagnostic ) {
			$diagnostic = self::with_matrix_diagnostic_aliases( $diagnostic );
			$row        = array();
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
	 * Add generic aliases consumed by fixture-matrix grouping without changing source ownership.
	 *
	 * @param array<string,mixed> $diagnostic Normalized diagnostic.
	 * @return array<string,mixed>
	 */
	private static function with_matrix_diagnostic_aliases( array $diagnostic ): array {
		if ( empty( $diagnostic['source_snippet'] ) ) {
			foreach ( array( 'source_html_preview', 'html_excerpt', 'excerpt' ) as $field ) {
				if ( isset( $diagnostic[ $field ] ) && is_scalar( $diagnostic[ $field ] ) && '' !== trim( (string) $diagnostic[ $field ] ) ) {
					$diagnostic['source_snippet'] = (string) $diagnostic[ $field ];
					break;
				}
			}
		}
		if ( empty( $diagnostic['observed_output'] ) && isset( $diagnostic['emitted_block_preview'] ) && is_scalar( $diagnostic['emitted_block_preview'] ) ) {
			$diagnostic['observed_output'] = (string) $diagnostic['emitted_block_preview'];
		}
		if ( empty( $diagnostic['observed_block_name'] ) && isset( $diagnostic['block_name'] ) && is_scalar( $diagnostic['block_name'] ) ) {
			$diagnostic['observed_block_name'] = (string) $diagnostic['block_name'];
		}

		return $diagnostic;
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
