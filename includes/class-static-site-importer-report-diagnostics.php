<?php
/**
 * Import report and diagnostics helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Product_Handoff_Contract' ) ) {
	require_once __DIR__ . '/class-static-site-importer-product-handoff-contract.php';
}
if ( ! class_exists( 'Static_Site_Importer_Import_Report' ) ) {
	require_once __DIR__ . '/class-static-site-importer-import-report.php';
}
if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Loss_Classes' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-loss-classes.php';
}
if ( ! class_exists( 'Static_Site_Importer_Entity_Materializer_Registry' ) ) {
	require_once __DIR__ . '/class-static-site-importer-entity-materializer-registry.php';
}
if ( ! class_exists( 'Static_Site_Importer_Owner_Handoff_Evidence' ) ) {
	require_once __DIR__ . '/class-static-site-importer-owner-handoff-evidence.php';
}

if ( ! class_exists( 'Static_Site_Importer_Diagnostic_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-diagnostic-projection.php';
}
if ( ! class_exists( 'Static_Site_Importer_Quality_Gates' ) ) {
	require_once __DIR__ . '/class-static-site-importer-quality-gates.php';
}
if ( ! class_exists( 'Static_Site_Importer_Visual_Parity_Oracle' ) ) {
	require_once __DIR__ . '/class-static-site-importer-visual-parity-oracle.php';
}
if ( ! class_exists( 'Static_Site_Importer_Product_Finding_Materializer' ) ) {
	require_once __DIR__ . '/class-static-site-importer-product-finding-materializer.php';
}

/**
 * Builds SSI import reports and normalizes diagnostics for repair loops.
 */
class Static_Site_Importer_Report_Diagnostics {
	/** Diagnostic type for a page route materialized without author stylesheet coverage. */
	public const PAGE_WITHOUT_AUTHOR_STYLES_TYPE = 'page_materialized_without_author_styles';

	/** Diagnostic type for interaction members the imported representation omitted. */
	public const INTERACTION_CANDIDATE_TYPE = 'interaction_candidate';

	/** Reason code for captured interaction states with no imported representation. */
	public const CAPTURED_INTERACTION_UNMATERIALIZED_REASON = 'captured_interaction_unmaterialized';

	/** Reason code for capture-side outcomes that never produced region content. */
	public const CAPTURED_INTERACTION_CAPTURE_GAP_REASON = 'captured_interaction_capture_gap';

	/** Diagnostic type for fixed geometry added to a topology-changed CSS-owned container. */
	public const UNSAFE_LAYOUT_CONSTRAINT_TYPE = 'unsafe_layout_constraint';

	/** Retain source diagnostics unless a persisted provider replacement covers them. */
	public static function after_completed_entity_bindings( array $diagnostics, array $receipt ): array {
		return Static_Site_Importer_Diagnostic_Projection::after_completed_entity_bindings( $diagnostics, $receipt );
	}

	/**
	 * Name every entity a provider declined to materialize.
	 *
	 * A provider declines one entity when it cannot represent it faithfully -- an
	 * unsupported control topology, or a layout it cannot carry without losing
	 * fidelity. The imported page keeps the converted source markup at that
	 * anchor, so the outcome is a bounded, reportable preservation rather than a
	 * failure. Without these rows the decline is only reachable by reading the
	 * entity lifecycle, which names the declaration by hash and nothing else.
	 *
	 * @param array<string,mixed> $entities Provider entity reports keyed by declaration id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function provider_entity_decline_diagnostics( array $entities ): array {
		return Static_Site_Importer_Diagnostic_Projection::provider_entity_decline_diagnostics( $entities );
	}

	/**
	 * Initialize a conversion report.
	 *
	 * @param string              $html_path       Imported entry file.
	 * @param array<string,mixed> $source_metadata Source metadata.
	 * @return Static_Site_Importer_Import_Report
	 */
	public static function new_conversion_report( string $html_path, array $source_metadata = array() ): Static_Site_Importer_Import_Report {
		$envelope = array(
			'schema'                  => Static_Site_Importer_Import_Report::SCHEMA,
			'version'                 => 1,
			'entry_file'              => $html_path,
			'source'                  => array_merge(
				array(
					'type' => empty( $source_metadata ) ? 'file' : (string) ( $source_metadata['source_type'] ?? 'file' ),
				),
				$source_metadata
			),
			'quality'                 => Static_Site_Importer_Quality_Gates::quality_defaults(),
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
				'build'            => array(),
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
			'visual_parity_artifacts' => Static_Site_Importer_Diagnostic_Projection::visual_parity_artifact_contract(),
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

		return Static_Site_Importer_Import_Report::from_array( $envelope );
	}

	/**
	 * Record source and contract notes for direct website-artifact materialization.
	 *
	 * @param Static_Site_Importer_Import_Report $report   Import report.
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return void
	 */
	public static function record_direct_website_artifact_source_summary( Static_Site_Importer_Import_Report $report, array $compiled ): void {
		$artifacts = isset( $compiled['artifacts'] ) && is_array( $compiled['artifacts'] ) ? $compiled['artifacts'] : array();
		$files     = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$source    = (string) ( $compiled['provenance']['source'] ?? ( $compiled['input']['entry_path'] ?? 'website_artifact' ) );

		$source_documents                            = array_merge(
			$report->section( 'source_documents' ),
			array(
				'total_count'      => 1,
				'counts_by_format' => array(
					'html'     => 1,
					'markdown' => 0,
					'mdx'      => 0,
				),
			)
		);
		$source_documents['direct_website_artifact'] = array(
			'source'     => '' !== $source ? $source : 'website_artifact',
			'file_count' => count( $files ),
		);
		$report->set_section( 'source_documents', $source_documents );
		$report->append_diagnostic(
			array(
				'type'        => 'website_artifact_materialization_contract_note',
				'source'      => '' !== $source ? $source : 'website_artifact',
				'message'     => 'Direct materialization consumed block_markup, documents, files, and materialization-plan artifacts. Static Site Importer owns WordPress writes and product seeding while Blocks Engine owns materializer-neutral site/theme compilation.',
				'contract'    => isset( $compiled['schema'] ) && is_scalar( $compiled['schema'] ) ? (string) $compiled['schema'] : 'blocks-engine/php-transformer/result/v1',
				'constraints' => 'report_only',
			)
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
			'excerpt'               => self::diagnostic_excerpt( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $element_html ) : strip_tags( $element_html ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for runtime-free smoke tests.
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
		if ( 'form' === strtolower( (string) $entry['tag_name'] ) ) {
			$metadata          = array(
				'form'              => is_array( $context['form'] ?? null ) ? $context['form'] : array(),
				'controls'          => is_array( $context['controls'] ?? null ) ? $context['controls'] : array(),
				'form_presentation' => is_array( $context['form_presentation'] ?? null ) ? $context['form_presentation'] : array(),
			);
			$manifest          = Static_Site_Importer_Form_Fallback_Contract::manifest_from_metadata( $metadata );
			$entry['form']     = $manifest['form'];
			$entry['controls'] = $manifest['controls'];
			$presentation      = Static_Site_Importer_Form_Fallback_Contract::presentation_from_metadata( $metadata, $selector, (int) ( $context['occurrence'] ?? 0 ) );
			if ( ! empty( $presentation ) ) {
				$entry['form_presentation'] = $presentation;
			}
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
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @param array<string,mixed> $args   Import args.
	 * @return array<string, mixed>
	 */
	public static function finalize_report( Static_Site_Importer_Import_Report $report, array $args ): array {
		$provided = isset( $args['validation_artifacts'] ) && is_array( $args['validation_artifacts'] ) ? $args['validation_artifacts'] : array();
		if ( isset( $args['source_reports'] ) && is_array( $args['source_reports'] ) ) {
			$provided['source_reports'] = $args['source_reports'];
		}
		$oracle = Static_Site_Importer_Visual_Parity_Oracle::evaluate( $provided );
		foreach ( $oracle['diagnostics'] as $diagnostic ) {
			if ( is_array( $diagnostic ) ) {
				$report->append_diagnostic( $diagnostic );
			}
		}
		$visual_fidelity                          = $report->section( 'visual_fidelity' );
		$visual_fidelity['gate_owner']            = 'codebox_runtime';
		$visual_fidelity['compiler_report_path']  = Static_Site_Importer_Visual_Parity_Oracle::COMPILER_REPORT_PATH;
		$visual_fidelity['expected_schema']       = Static_Site_Importer_Visual_Parity_Oracle::SCHEMA;
		$visual_fidelity['missing_data_contract'] = isset( $oracle['missing_data_contract'] ) && is_array( $oracle['missing_data_contract'] ) ? $oracle['missing_data_contract'] : array();
		if ( in_array( $oracle['status'], array( 'passed', 'failed' ), true ) ) {
			$visual_fidelity['status']             = $oracle['status'];
			$visual_fidelity['verification']       = Static_Site_Importer_Visual_Parity_Oracle::VERIFICATION;
			$visual_fidelity['stage']              = Static_Site_Importer_Visual_Parity_Oracle::STAGE;
			$visual_fidelity['tolerances']         = $oracle['tolerances'];
			$visual_fidelity['disagreement_count'] = count( $oracle['disagreements'] );
			$provided                              = array_merge( $provided, $oracle['artifact_refs'] );
			if ( ! empty( $oracle['summary'] ) ) {
				$provided['summary'] = array_merge( isset( $provided['summary'] ) && is_array( $provided['summary'] ) ? $provided['summary'] : array(), $oracle['summary'] );
			}
		} else {
			$visual_fidelity['status'] = 'not_verified';
			$visual_fidelity['reason'] = (string) ( $oracle['reason'] ?? '' );
		}
		$report->set_section( 'visual_fidelity', $visual_fidelity );
		$quality                           = Static_Site_Importer_Quality_Gates::finalize_quality_report( $report, $args );
		$report['visual_parity_artifacts'] = Static_Site_Importer_Diagnostic_Projection::visual_parity_artifact_contract( $provided );
		Static_Site_Importer_Diagnostic_Projection::refresh_projections( $report, $quality, false );

		return $quality;
	}

	/**
	 * Refresh every public projection from one finalized report state.
	 *
	 * @param Static_Site_Importer_Import_Report $report  Finalized import report.
	 * @param array<string,mixed> $quality Finalized quality gate state.
	 * @param bool                $build_fixture Whether to build the fixture projection.
	 * @return array<string,mixed>
	 */
	public static function refresh_projections( Static_Site_Importer_Import_Report $report, array $quality, bool $build_fixture = true ): array {
		return Static_Site_Importer_Diagnostic_Projection::refresh_projections( $report, $quality, $build_fixture );
	}

	/**
	 * Build the first-class import validation artifact for automation consumers.
	 *
	 * @param array<string,mixed>|Static_Site_Importer_Import_Report $report  Full import report.
	 * @param array<string,mixed> $quality Finalized quality gate state.
	 * @return array<string,mixed>
	 */
	public static function import_validation_result( array|Static_Site_Importer_Import_Report $report, array $quality ): array {
		return Static_Site_Importer_Diagnostic_Projection::import_validation_result( $report, $quality );
	}

	/**
	 * Build the finding packet artifact set for repair-loop routing.
	 *
	 * @param array<string,mixed>|Static_Site_Importer_Import_Report $report Full import report.
	 * @return array<string,mixed>
	 */
	public static function finding_packets( array|Static_Site_Importer_Import_Report $report ): array {
		return Static_Site_Importer_Diagnostic_Projection::finding_packets( $report );
	}

	/**
	 * Detect page routes that materialize without any author stylesheet asset
	 * while their source document demonstrably ships author styles.
	 *
	 * The importer is the only layer that sees both the canonical plan's
	 * asset-to-route scopes and the source artifact, so it owns reporting the
	 * "page ships without its styles while the import reports clean" failure
	 * class (Automattic/blocks-engine#1241) regardless of which upstream stage
	 * regresses next. A page counts as covered when any non-engine stylesheet
	 * asset (coalesced `stylesheet-bundle-*`, uncoalesced `source-author-*`, or
	 * a copied author CSS file) is scoped to its route or declared global;
	 * `engine-support` and `editor-static-state` assets are engine-generated
	 * and never satisfy author coverage. Source evidence requires a non-empty
	 * inline `<style>` payload or a linked stylesheet that resolves to a
	 * non-empty artifact-local CSS file, so a warning always means author CSS
	 * the pipeline had in hand was dropped.
	 *
	 * @param array<string,mixed> $plan     Canonical WordPress site plan.
	 * @param array<string,mixed> $artifact Source website artifact.
	 * @return array<int,array<string,mixed>> Warning diagnostics, one per uncovered route.
	 */
	public static function missing_author_stylesheet_diagnostics( array $plan, array $artifact ): array {
		return Static_Site_Importer_Diagnostic_Projection::missing_author_stylesheet_diagnostics( $plan, $artifact );
	}

	/**
	 * Read captured interaction states from the source artifact and report the
	 * members conversion omitted, including partial set loss.
	 *
	 * @param array<string,mixed> $artifact Source website artifact.
	 * @param array<string,mixed> $plan     Canonical WordPress site plan.
	 * @return array{recorded_state_count:int,captured_state_count:int,unrepresented_member_count:int,status_counts:array<string,int>,diagnostics:array<int,array<string,mixed>>}
	 */
	public static function captured_interaction_inventory( array $artifact, array $plan = array() ): array {
		return Static_Site_Importer_Diagnostic_Projection::captured_interaction_inventory( $artifact, $plan );
	}

	/**
	 * Detect a concrete layout hazard in the materialized plan without claiming a
	 * browser comparison has occurred. A topology warning alone is reportable but
	 * acceptable; generated fixed height on that CSS-owned container is unsafe.
	 *
	 * @param array<string,mixed> $plan Canonical WordPress site plan.
	 * @return array<int,array<string,mixed>> Unsafe layout diagnostics.
	 */
	public static function unsafe_layout_constraint_diagnostics( array $plan ): array {
		return Static_Site_Importer_Diagnostic_Projection::unsafe_layout_constraint_diagnostics( $plan );
	}

	/**
	 * Finalize quality summary and gate status.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @param array<string,mixed> $args   Import args.
	 * @return array<string, mixed>
	 */
	public static function finalize_quality_report( Static_Site_Importer_Import_Report $report, array $args ): array {
		return Static_Site_Importer_Quality_Gates::finalize_quality_report( $report, $args );
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
	 * @param Static_Site_Importer_Import_Report $report     Conversion report (mutated in place).
	 * @param array<string, mixed> $dependency Companion dependency definition.
	 * @param bool                 $waived     Whether enforcement is waived.
	 * @return void
	 */
	public static function record_companion_plugin_dependency( Static_Site_Importer_Import_Report $report, array $dependency, bool $waived ): void {
		$row  = Static_Site_Importer_Dependency_Manager::companion_dependency_row( $dependency, $waived );
		$slug = (string) ( $row['slug'] ?? '' );
		if ( '' === $slug ) {
			return;
		}

		$companion = $report->section( 'companion_plugins' );
		if ( ! isset( $companion['dependencies'] ) || ! is_array( $companion['dependencies'] ) ) {
			$companion['dependencies'] = array();
		}
		$companion['dependencies'][ $slug ] = $row;
		$report->set_section( 'companion_plugins', $companion );

		$source = 'companion_plugins.dependencies.' . $slug;

		if ( ! empty( $row['active'] ) ) {
			$island_handles  = isset( $row['island_handles'] ) && is_array( $row['island_handles'] ) ? $row['island_handles'] : array();
			$runtime_scripts = isset( $row['runtime_scripts'] ) && is_array( $row['runtime_scripts'] ) ? $row['runtime_scripts'] : array();
			Static_Site_Importer_Quality_Gates::mark_companion_script_fallbacks_materialized( $report, $runtime_scripts, $slug );
			$present = array(
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
			$report->append_diagnostic( $present );
			return;
		}

		if ( $waived ) {
			$report->append_diagnostic(
				array(
					'code'        => 'companion_plugin_waived',
					'severity'    => 'warning',
					'source'      => $source,
					'message'     => sprintf( 'Companion plugin %s requirement was waived; generated blocks were not installed.', $slug ),
					'slug'        => $slug,
					'block_names' => $row['block_names'] ?? array(),
				)
			);
			return;
		}

		$report->increment_quality( 'companion_plugin_dependency_failures' );
		$report->append_diagnostic(
			array(
				'code'        => 'companion_plugin_missing',
				'severity'    => 'error',
				'source'      => $source,
				'message'     => sprintf( 'Companion plugin %s is required to house generated blocks but is not installed/active.', $slug ),
				'slug'        => $slug,
				'block_names' => $row['block_names'] ?? array(),
			)
		);
	}

	/**
	 * Build the compact report summary consumed by validation harnesses.
	 *
	 * @param array<string,mixed>|Static_Site_Importer_Import_Report $report  Full conversion report.
	 * @param array<string, mixed> $quality Finalized quality summary.
	 * @return array<string, mixed>
	 */
	public static function import_report_summary( array|Static_Site_Importer_Import_Report $report, array $quality ): array {
		return Static_Site_Importer_Diagnostic_Projection::import_report_summary( $report, $quality );
	}

	/**
	 * Materialize detected product-grid fallbacks through the configured shop provider.
	 *
	 * Collects every `html_product_grid_fallback` finding and materializes only
	 * producer-declared product rows (slug + regular_price already present) through
	 * the shop adapter's manifest validator + seeder. Stamps the runtime-mapped /
	 * acceptable-preservation signal onto each finding whose products were actually
	 * seeded. Findings whose products could not be seeded (for example because
	 * WooCommerce is unavailable) keep no signal and stay an unacceptable parity
	 * loss, which lets the existing commerce dependency gate report the missing
	 * runtime.
	 *
	 * @param Static_Site_Importer_Import_Report  $report        Import report (mutated in place).
	 * @param array<string,mixed>  $args          Import args.
	 * @param array<string,string> $page_contents Materialized page post_content keyed by source filename, mutated in place.
	 * @return array<string,mixed> The recorded product_finding_seeding report.
	 */
	public static function materialize_product_findings( Static_Site_Importer_Import_Report $report, array $args = array(), array &$page_contents = array() ): array {
		return Static_Site_Importer_Product_Finding_Materializer::materialize_product_findings( $report, $args, $page_contents );
	}

	/**
	 * Return diagnostic indexes for every detected product-grid fallback finding.
	 *
	 * @param array<int,mixed> $diagnostics Report diagnostics.
	 * @return array<int,int>
	 */
	public static function product_grid_finding_indexes( array $diagnostics ): array {
		return Static_Site_Importer_Product_Finding_Materializer::product_grid_finding_indexes( $diagnostics );
	}

	/**
	 * Normalize active product-grid findings into the Woo manifest row contract.
	 *
	 * This is intentionally limited to the Blocks Engine product-grid discriminator.
	 * Callers own how the rows are declared or materialized; this helper owns only
	 * the source-finding to validated-product data bridge.
	 *
	 * @param array<int,mixed> $diagnostics Plan or report diagnostics.
	 * @return array<int,array<string,mixed>>
	 */
	public static function product_grid_manifest_products( array $diagnostics ): array {
		return Static_Site_Importer_Product_Finding_Materializer::product_grid_manifest_products( $diagnostics );
	}

	/**
	 * Derive a shared block-binding anchor per product for every detected
	 * product-grid finding, keyed by the finding's own seeded manifest slug.
	 *
	 * @param array<int,mixed> $diagnostics Plan or report diagnostics.
	 * @return array<string,array{source_path:string,search_block_markup:string}>
	 */
	public static function product_grid_binding_anchors( array $diagnostics ): array {
		return Static_Site_Importer_Product_Finding_Materializer::product_grid_binding_anchors( $diagnostics );
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
		return Static_Site_Importer_Product_Finding_Materializer::normalize_product_price( $price );
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
	 * Reconcile source form fallbacks against hash-bound provider receipts.
	 *
	 * The source finding remains in diagnostics for auditability. Only the final
	 * quality count excludes a form after a completed receipt proves that exact
	 * source fallback was replaced by the provider's persisted block markup.
	 *
	 * @param Static_Site_Importer_Import_Report $report Import report.
	 * @return void
	 */
	public static function reconcile_provider_materialized_fallbacks( Static_Site_Importer_Import_Report $report, array $receipts = array() ): void {
		Static_Site_Importer_Quality_Gates::reconcile_provider_materialized_fallbacks( $report, $receipts );
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
